<?php
// api_upload.php -- HotCRP upload manager
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Upload_API {
    const MIN_MULTIPART_SIZE = 5 << 20;
    // How long to respect an `s3_lock` recorded by the previous code; see
    // transfer(). Remove with that check.
    const OLD_LOCK_TIMEOUT = 300;
    const MAX_SIZE = 1 << 30;
    const MAX_BLOB = 32 << 20;
    // maximum number of disjoint `ranges`; token data has limited length
    const MAX_RANGES = 128;
    // seconds a request waits for the upload lock
    const LOCK_TIMEOUT = 30;
    const SERVER_PROGRESS_FACTOR = 0.5;

    // seconds before upload token expires; see also cleandocstore.php
    const EXPIRES_IN = 7200;

    /** @var Conf */
    public $conf;
    /** @var int */
    public $max_size;
    /** @var int */
    public $max_blob;
    /** @var list<array{int,int}> */
    private $segments;
    /** @var string */
    public $tmpdir;
    /** @var TokenInfo */
    private $_cap;
    /** @var ?object */
    private $_capd;
    /** @var bool */
    public $no_s3 = false;
    /** @var bool */
    public $no_s3_move = false;
    /** @var bool */
    public $synchronous = false;
    /** @var bool */
    public $no_transfer = false;
    /** @var int */
    public $lock_timeout = self::LOCK_TIMEOUT;
    /** @var int */
    private $_lock_depth = 0;
    /** @var ?string */
    private $_lock_salt;
    /** @var bool */
    private $_detached = false;
    /** @var list<MessageItem> */
    private $_ml = [];
    /** @var ?HashContext */
    private $_hashctx;
    /** @var ?HashContext */
    private $_crc32ctx;
    /** @var ?HashContext */
    private $_xxh64ctx;
    /** @var ?int */
    private $_hashpos;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->max_size = $conf->opt("uploadApiMaxSize") ?? self::MAX_SIZE;
        $this->max_blob = $conf->opt("uploadApiMaxBlob") ?? self::MAX_BLOB;
        $this->segments = [[0, 5<<20], [5<<20, 13<<20], [13<<20, 29<<20]];
        $this->tmpdir = $conf->docstore_tempdir();
    }

    /** @param int $offset
     * @return array{int,int,int} */
    private function find_segment($offset) {
        $segi = 0;
        $nseg = count($this->segments);
        while ($segi < $nseg - 1) {
            [$seg0, $seg1] = $this->segments[$segi];
            if ($offset >= $seg0 && $offset < $seg1) {
                return [$segi, $seg0, $seg1];
            }
            ++$segi;
        }
        [$seg0, $seg1] = $this->segments[$segi];
        $segsz = $seg1 - $seg0;
        $segx = (int) (($offset - $seg0) / $segsz);
        return [$segi + $segx, $seg0 + $segx * $segsz, $seg0 + ($segx + 1) * $segsz];
    }

    /** @param int $segi
     * @return array{int,int} */
    private function segment_boundaries($segi) {
        if ($segi < count($this->segments)) {
            return $this->segments[$segi];
        }
        $segl = count($this->segments) - 1;
        [$seg0, $seg1] = $this->segments[$segl];
        $segsz = $seg1 - $seg0;
        $segx = $segi - $segl;
        return [$seg0 + $segx * $segsz, $seg0 + ($segx + 1) * $segsz];
    }

    /** @param ?int $segno
     * @return string */
    private function segment_file($segno) {
        return $this->tmpdir . $this->_cap->salt . ($segno ? "-{$segno}" : "");
    }

    /** @return string */
    private function lock_file() {
        return $this->tmpdir . $this->_cap->salt . "-lock";
    }

    /** @return string */
    private function assembly_file() {
        return $this->tmpdir . $this->_cap->salt . "-asm";
    }

    /** @param DocumentInfo $doc
     * @return string */
    private function final_file($doc) {
        return $this->tmpdir . "upload-" . $this->_cap->salt . Mimetype::extension($doc->mimetype);
    }

    /** @param list<int> $range
     * @param int $lo
     * @param int $hi
     * @return list<int> */
    static function add_range($range, $lo, $hi) {
        if ($lo < $hi) {
            for ($i = 0; $i !== count($range) && $lo > $range[$i + 1]; $i += 2) {
            }
            // invariant: $i === count($range) || $lo <= $range[$i + 1]
            for ($j = $i; $j !== count($range) && $hi >= $range[$j]; $j += 2) {
            }
            // invariant: $j === count($range) || $hi < $range[$j]
            if ($i === $j) { // new range
                array_splice($range, $i, 0, [$lo, $hi]);
            } else {
                if ($i + 2 !== $j) {
                    array_splice($range, $i + 1, $j - ($i + 2));
                }
                $range[$i] = min($range[$i], $lo);
                $range[$i + 1] = max($range[$i + 1], $hi);
            }
        }
        return $range;
    }

    /** @return bool */
    private function assign_token() {
        for ($tries = 1; $tries !== 10; ++$tries) {
            $this->_cap->set_salt("hcup" . base48_encode(random_bytes(12)));
            if (($handle = fopen($this->segment_file(0), "x"))) {
                fclose($handle);
                $this->_cap->insert();
                if ($this->_cap->stored()) {
                    return true;
                }
                unlink($this->segment_file(0));
            }
        }
        return false;
    }

    /** @return JsonResult */
    function exec_start(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (isset($qreq->token)) {
            return JsonResult::make_parameter_error("start", "<0>Start requests must not specify a token");
        }
        if ($prow) {
            $pid = $prow->paperId;
        } else if ((string) $qreq->p === "") {
            $pid = null;  // `dt` can be either paper option or nonpaper option
        } else if (in_array((string) $qreq->p, ["0", "-1", "new"], true)) {
            $pid = 0;     // `dt` must be paper option if set
        } else if ((string) $qreq->p === "-2") {
            $pid = -2;    // `dt` must be nonpaper option if set
        } else {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        }
        $size = stoi($qreq->size);
        if ($size === null || $size < 0) {
            if (isset($qreq->size)) {
                return JsonResult::make_parameter_error("size");
            }
        } else if ($size > $this->max_size) {
            return JsonResult::make_parameter_error("size", "<0>File too large")->set("maxsize", $this->max_size);
        }
        if (isset($qreq->filename)
            && strlen($qreq->filename) <= 255
            && is_valid_utf8($qreq->filename)) {
            $filename = $qreq->filename;
        } else {
            $filename = "_upload_";
        }
        $dtarg = $qreq->dt ?? $qreq->dtype /* XXX backward compat */;
        if ($dtarg === null) {
            $dtype = null;
        } else {
            $dtype = DocumentRequest::parse_doctype($this->conf, $dtarg, $pid);
            if ($dtype === null) {
                return JsonResult::make_parameter_error("dt", "<0>Document type not found");
            }
        }
        if ($pid === null) {
            // resolve `pid` to appropriate value (0 paper, -2 nonpaper)
            $opt = $dtype !== null ? $this->conf->option_by_id($dtype) : null;
            $pid = $opt && $opt->nonpaper ? -2 : 0;
        }
        $this->_cap = (new TokenInfo($this->conf, TokenInfo::UPLOAD))
            ->set_user_from($user, false)
            ->set_pid($pid)
            ->set_expires_in(self::EXPIRES_IN);
        $data = [
            "size" => $size,
            "ranges" => [0, 0],
            "filename" => $filename,
            "req_mimetype" => Mimetype::sanitize($qreq->mimetype) ?? "application/octet-stream",
            "mimetype" => null,
            "pid" => $pid,
            "dtype" => $dtype,
            "temp" => friendly_boolean($qreq->temp) ?? $dtype === null,
            "hash" => null,
            "crc32" => null,
            // 0: not started, 1: hash complete, 2: docstore assembled,
            // 3: docstore emplaced, 4: multipart upload complete, 5: S3 emplaced
            "status" => 0,
            "s3_parts" => [],
            "s3_uploadid" => false,
            "hashctx" => base64_encode(serialize(hash_init($this->conf->content_hash_algorithm()))),
            "crc32ctx" => base64_encode(serialize(hash_init("crc32b"))),
            "xxh64ctx" => base64_encode(serialize(hash_init("xxh64"))),
            "hashpos" => 0
        ];
        $this->_cap->assign_data($data);
        if ($this->assign_token()) {
            $qreq->token = $this->_cap->salt;
            return JsonResult::make_ok();
        }
        return JsonResult::make_message_list(503, MessageItem::error("<0>Cannot initiate upload"));
    }

    private function delete_files() {
        foreach (glob($this->segment_file(null) . "*") as $f) {
            @unlink($f);
        }
    }

    function delete_all() {
        $this->delete_files();
        // `delete_files` globs `<salt>*`, which does not match a temporary
        // final file (`upload-<salt><ext>`). Only temporary files are ours to
        // remove; a non-temporary `content_file` is a shared docstore path.
        if (($this->_capd->temp ?? false)
            && isset($this->_capd->content_file)) {
            @unlink($this->_capd->content_file);
        }
        // before status 4 the multipart upload is incomplete, so abort it;
        // after, delete the assembled temporary object if it remains
        if ($this->_capd->s3_uploadid
            && $this->conf->s3_client()) {
            if ($this->_capd->status < 4) {
                $key = $this->s3_key() . "?uploadId=" . $this->_capd->s3_uploadid;
            } else {
                $key = $this->s3_key();
            }
            // The abort/delete is a network round trip. When the site
            // processes work offline, queue it rather than block teardown
            // (e.g. a cancel, or the token GC) on S3. The upload key is
            // unique to this token, so a deferred delete cannot touch a
            // later upload or a stored document.
            if ($this->conf->opt("s3ProcessWork")
                && ($wi = WorkItem::make($this->conf, "s3cancel", null, ["confid" => $this->conf->confid, "key" => $key]))
                && $wi->enqueue()) {
                // handled by `processwork`, which calls `cancel_s3_upload`
            } else {
                self::cancel_s3_upload($this->conf, $key);
            }
        }
    }

    /** Delete the S3 state of an aborted or completed upload: an incomplete
     * multipart upload (`$key` ends in `?uploadId=…`) or a temporary
     * assembled object. Idempotent. Also called by `processwork` to run a
     * deferred `s3cancel` work item.
     * @param string $key
     * @return int  1: deleted, already gone, or no S3; 0: transient failure */
    static function cancel_s3_upload(Conf $conf, $key) {
        if (!($s3 = $conf->s3_client())) {
            return 1;
        }
        $s3r = $s3->start_delete($key)->run();
        // 2xx deleted, 404 already gone: nothing left to do either way. Any
        // other status (endpoint down, 5xx) is transient; the caller retries,
        // and an incomplete multipart upload is also caught by an S3
        // lifecycle rule.
        return ($s3r->status >= 200 && $s3r->status < 300) || $s3r->status === 404 ? 1 : 0;
    }

    /** @return bool */
    private function canceled() {
        return !$this->_capd || ($this->_capd->canceled ?? false);
    }

    /** Discard a canceled upload’s files and S3 state. The token itself is
     * left invalid and expired for the token GC to sweep.
     * The caller must hold the transfer lock. */
    private function reclaim() {
        if ($this->_capd && !($this->_capd->deleted ?? false)) {
            $this->delete_all();
            $this->modify_capd(function ($d) {
                $d->s3_uploadid = false;   // forget the S3 upload
                $d->deleted = true;        // mark local files as deleted
            });
            $this->_cap->set_invalid()->set_expires_at(Conf::$now - 1)->update();
        }
    }

    /** Reclaim a canceled upload unless another request is transferring
     * it. The caller must hold the upload lock. */
    private function try_reclaim() {
        // An upload already reclaimed has no files; taking the S3 lock would
        // only re-create its lock file.
        if (!$this->_capd || ($this->_capd->deleted ?? false)) {
            return;
        }
        // Try the S3 lock: `s3_transfer_segment` creates the multipart
        // upload under it, so this is what keeps a concurrent transfer from
        // stranding one after `delete_all` aborts it. That transfer polls
        // `canceled` and reclaims the upload itself.
        if (($lockf = $this->lock(false))) {
            $this->reclaim();
            $this->unlock($lockf);
        }
    }

    /** Reclaim a canceled upload, which `lock_token` does as it refuses
     * the lock, and report the cancellation unless `$quiet`. */
    private function reclaim_canceled($quiet = false) {
        if ($this->lock_token($this->_cap->salt, $this->lock_timeout)) {
            $this->unlock_token();
        }
        if (!$quiet) {
            $this->_ml[] = MessageItem::error($this->_capd->cancel_message ?? "<0>Upload canceled");
        }
    }

    /** Mark this upload canceled, and delete it if no other request is
     * transferring it. The caller must hold the upload lock. */
    private function cancel($message = null) {
        assert($this->_lock_depth > 0);
        // An expired token is refused by `exec` and swept by the token GC,
        // which calls `cleanup` to finish any teardown left undone here.
        $this->_cap->set_invalid()->set_expires_at(Conf::$now - 1)->update();
        $this->modify_capd(function ($d) use ($message) {
            $d->canceled = true;
            if ($message !== null) {
                $d->cancel_message = $message;
            }
        });
        $this->try_reclaim();
    }

    /** Fix the upload’s size, which bounds every later chunk, the hash,
     * and the assembled file. The caller must hold the upload lock.
     * @param ?int $size  null means the data received so far, if it is one
     *   range from offset 0
     * @return ?MessageItem */
    private function set_size($size) {
        assert($this->_lock_depth > 0);
        $ranges = $this->_capd->ranges;
        if ($size === null) {
            if (count($ranges) !== 2 || $ranges[0] !== 0) {
                return null;
            }
            $size = $ranges[1];
        }
        if (isset($this->_capd->size)) {
            if ($size !== $this->_capd->size) {
                return MessageItem::error_at("size", "<0>Wrong size");
            }
            return null;
        }
        if ($size > $this->max_size) {
            return MessageItem::error_at("size", "<0>File too large");
        } else if ($size < $ranges[count($ranges) - 1]) {
            return MessageItem::error_at("size", "<0>Size is smaller than the data already uploaded");
        }
        $this->modify_capd(function ($d) use ($size) {
            $d->size = $size;
        });
        return null;
    }

    /** @return ?HashContext */
    private function _parse_hashctx($x) {
        try {
            if (is_string($x)
                && ($y = base64_decode($x, true)) !== false
                && ($h = unserialize($y))
                && $h instanceof HashContext) {
                return $h;
            }
        } catch (Throwable $t) {
        }
        return null;
    }

    private function _make_hashctx() {
        if (isset($this->_capd->hashctx)
            && isset($this->_capd->crc32ctx)
            && isset($this->_capd->hashpos)
            && is_int($this->_capd->hashpos)
            && (!$this->_hashctx || $this->_hashpos < $this->_capd->hashpos)
            && ($hashctx = $this->_parse_hashctx($this->_capd->hashctx))
            && ($crc32ctx = $this->_parse_hashctx($this->_capd->crc32ctx))) {
            $this->_hashctx = $hashctx;
            $this->_crc32ctx = $crc32ctx;
            // uploads begun before `xxh64ctx` was recorded have none
            $this->_xxh64ctx = isset($this->_capd->xxh64ctx) ? $this->_parse_hashctx($this->_capd->xxh64ctx) : null;
            $this->_hashpos = $this->_capd->hashpos;
        }
        if (!$this->_hashctx) {
            $this->_hashctx = hash_init($this->conf->content_hash_algorithm());
            $this->_crc32ctx = hash_init("crc32b");
            $this->_xxh64ctx = hash_init("xxh64");
            $this->_hashpos = 0;
        }
    }

    /** Feed segment file `$segi` to the hash contexts. Return true iff the
     * hash reached the end of `$segi` (or `$bound`, whichever is lower).
     * @param int $segi
     * @param int $bound
     * @return bool */
    private function _update_hashctx_seg($segi, $bound) {
        [$seg0, $seg1] = $this->segment_boundaries($segi);
        $end = min($seg1, $bound);
        if ($this->_hashpos < $seg0) {
            return false;
        } else if ($end <= $this->_hashpos) {
            return true;
        }
        $f = @fopen($this->segment_file($segi), "rb");
        if ($f && fseek($f, $this->_hashpos - $seg0, SEEK_SET) === 0) {
            while ($this->_hashpos < $end) {
                $data = fread($f, min($end - $this->_hashpos, 1 << 20));
                if ($data === false || $data === "") {
                    break;
                }
                hash_update($this->_hashctx, $data);
                hash_update($this->_crc32ctx, $data);
                if ($this->_xxh64ctx) {
                    hash_update($this->_xxh64ctx, $data);
                }
                $this->_hashpos += strlen($data);
            }
        }
        $f && fclose($f);
        return $this->_hashpos === $end;
    }

    /** Return the end of the data the hash can cover: the data recorded
     * contiguously from offset 0, up to `size`.
     * @return int */
    private function _hash_target() {
        $ranges = $this->_capd->ranges;
        if ($ranges[0] !== 0) {
            return 0;
        }
        return min($ranges[1], $this->_capd->size ?? $ranges[1]);
    }

    /** Advance the hash over recorded data it has not covered, reading
     * it back from the segment files, and save the hash state.
     * The caller must hold the upload lock.
     * @return bool */
    private function _advance_hash() {
        assert($this->_lock_depth > 0);
        $this->_make_hashctx();
        $target = $this->_hash_target();
        $hashpos0 = $this->_hashpos;
        $segi = 0;
        while ($this->_hashpos < $target
               && $this->_update_hashctx_seg($segi, $target)) {
            ++$segi;
        }
        if ($this->_hashpos > $hashpos0) {
            $this->modify_capd(function ($d) {
                $d->hashctx = base64_encode(serialize($this->_hashctx));
                $d->crc32ctx = base64_encode(serialize($this->_crc32ctx));
                if ($this->_xxh64ctx) {
                    $d->xxh64ctx = base64_encode(serialize($this->_xxh64ctx));
                }
                $d->hashpos = $this->_hashpos;
            });
        }
        return $this->_hashpos === $target;
    }

    /** Write `$data` at `$offset`, checking it against data already there.
     * The caller must hold the upload lock.
     * @param int $offset
     * @param string $data
     * @return bool */
    private function exec_upload(Contact $user, $offset, $data) {
        assert($this->_lock_depth > 0);
        $pos = 0;
        $last_offset = $offset + strlen($data);
        while ($offset !== $last_offset) {
            // write data into relevant segment
            $offset0 = $offset;
            [$segi, $seg0, $seg1] = $this->find_segment($offset);
            $seg1 = min($seg1, $last_offset);
            $fname = $this->segment_file($segi);
            $handle = fopen($fname, "c+");
            if (!$handle
                || fseek($handle, $offset - $seg0, SEEK_SET) !== 0) {
                return false;
            }
            // check integrity of overlapping data, write missing data
            $ranges = $this->_capd->ranges;
            $ranges[] = PHP_INT_MAX; // sentinel
            $ri = 0;
            while ($offset !== $seg1) {
                $have = null;
                if ($offset < $ranges[$ri]) {
                    $n = min($seg1, $ranges[$ri]) - $offset;
                } else if ($offset < $ranges[$ri + 1]) {
                    $n = min($seg1, $ranges[$ri + 1]) - $offset;
                    $have = fread($handle, $n);
                } else if ($offset < $ranges[$ri + 2]) {
                    $n = min($seg1, $ranges[$ri + 2]) - $offset;
                } else {
                    $ri += 2;
                    continue;
                }
                $want = substr($data, $pos, $n);
                if ($have !== null) {
                    if ($have === false || strlen($have) !== $n) {
                        return false;
                    } else if ($want !== $have) {
                        $m = "<0>Upload aborted by data mismatch";
                        $this->cancel($m);
                        $this->_ml[] = MessageItem::error($m);
                        return false;
                    }
                } else if (fwrite($handle, $want) !== $n) {
                    return false;
                }
                $offset += $n;
                $pos += $n;
            }
            if (!fflush($handle)
                || !fclose($handle)) {
                return false;
            }
            $this->modify_capd(function ($d) use ($segi, $offset0, $seg1) {
                $d->ranges = Upload_API::add_range($d->ranges, $offset0, $seg1);
                while (count($d->s3_parts) <= $segi) {
                    $d->s3_parts[] = null;
                }
            });
        }
        return true;
    }

    /** @return string */
    static private function s3_key_for(TokenInfo $cap) {
        $confid = $cap->conf->confid;
        return "upload/" . $cap->salt . ($confid ? "-{$confid}" : "");
    }

    /** @return string */
    private function s3_key() {
        return self::s3_key_for($this->_cap);
    }

    /** @return string */
    private function dest_s3_key() {
        assert($this->_capd->status >= 1);
        return DocumentInfo::s3_key_for($this->_capd->hash, $this->_capd->mimetype);
    }

    private function dest_user_data() {
        return ["hotcrp" => json_encode_db(["conf" => $this->conf->dbname, "pid" => $this->_capd->pid, "dtype" => $this->_capd->dtype])];
    }

    /** Update this capability’s data by compare-and-swap.
     * If `$callable === null`, just reload the capability data.
     * @param callable(object) $callable */
    private function modify_capd($callable) {
        Dbl::compare_exchange(
            $this->conf->dblink,
            "select `data` from Capability where salt=?", [$this->_cap->salt],
            function ($oldd) use ($callable) {
                $this->_cap->assign_data($oldd);
                $this->_capd = $oldd ? json_decode($oldd) : null;
                if (!$this->_capd || !$callable) {
                    return $oldd;
                }
                call_user_func($callable, $this->_capd);
                $this->_cap->assign_data($this->_capd);
                return $this->_cap->encoded_data();
            },
            "update Capability set `data`=?{desired} where salt=? and `data`=?{expected}", [$this->_cap->salt]
        );
    }

    /** @param S3Client $s3c
     * @param int $segindex
     * @return string|false */
    private function s3_transfer_segment($s3c, $segindex) {
        if (isset($this->_capd->size)
            && $this->_capd->size <= self::MIN_MULTIPART_SIZE) {
            return "whole";
        }
        if (!$this->_capd->s3_uploadid) {
            $uploadid = $s3c->multipart_create($this->s3_key(), $this->_capd->mimetype, $this->dest_user_data());
            if (!$uploadid) {
                $this->_ml[] = MessageItem::error("<0>S3 multipart upload error");
                return false;
            }
            $this->modify_capd(function ($d) use ($uploadid) {
                $d->s3_uploadid = $uploadid;
            });
        }
        $file = $this->segment_file($segindex);
        if (!is_readable($file)) {
            $this->_ml[] = MessageItem::error("<0>Cannot read content file");
            return false;
        }
        $r = $s3c->start_put_file($this->s3_key() . "?partNumber=" . ($segindex + 1)
                                  . "&uploadId=" . $this->_capd->s3_uploadid,
                                  $file,
                                  "application/octet-stream", [])->run();
        if ($r->status !== 200) {
            $this->_ml[] = MessageItem::error("<0>S3 upload error");
            error_log($r->method() . " " . $r->url() . " -> " . $r->status . " " . json_encode($r->response_headers) . " " . $r->response_body() . "\n\n" . json_encode($this->_capd));
            return false;
        }
        return $r->response_header("etag");
    }

    /** The caller must hold the upload lock. */
    private function assemble_docstore() {
        assert($this->_lock_depth > 0);
        $asmfn = $this->assembly_file();
        if (!($file = fopen($asmfn, "wb"))) {
            return;
        }
        $size = $this->_capd->size;
        $nseg = count($this->_capd->s3_parts);
        for ($segi = 0; $segi !== $nseg; ++$segi)  {
            [$seg0, $seg1] = $this->segment_boundaries($segi);
            $len = min($seg1, $size) - $seg0;
            if ($len <= 0
                || !($infile = fopen($this->segment_file($segi), "rb"))) {
                break;
            }
            $n = stream_copy_to_stream($infile, $file, $len);
            fclose($infile);
            if ($n !== $len) {
                break;
            }
        }
        if ($segi !== $nseg) {
            fclose($file);
            @unlink($this->assembly_file());
            return;
        }
        fflush($file);
        $this->modify_capd(function ($d) {
            $d->status = max($d->status, 2);
        });
        fclose($file);
    }

    /** The caller must hold the upload lock. */
    private function complete_docstore() {
        assert($this->_lock_depth > 0);
        $asmfn = $this->assembly_file();
        clearstatcache(true, $asmfn);
        if (@filesize($asmfn) !== $this->_capd->size) {
            // assemble again on the next attempt
            @unlink($asmfn);
            $this->modify_capd(function ($d) {
                $d->status = min($d->status, 1);
            });
            return;
        }
        $doc = DocumentInfo::make_token($this->conf, $this->_cap, $asmfn);
        if ($this->_capd->temp) {
            $finalfn = $this->final_file($doc);
        } else {
            $finalfn = $this->conf->docstore()->path_for($doc, Docstore::FPATH_MKDIR);
        }
        if (!$finalfn) {
            return;
        }
        // Emplace without clobbering: `link` fails if a file is already
        // there. An intact existing file is kept, a damaged one replaced.
        // A damaged file suggests the upload that stored it was bad, so
        // `replaced` makes the S3 steps replace its S3 object too.
        $replaced = false;
        if (@link($asmfn, $finalfn)
            || (file_exists($finalfn)
                && $this->_stored_file_matches($finalfn, $doc))) {
            @unlink($asmfn);
        } else {
            $replaced = file_exists($finalfn);
            if (!rename($asmfn, $finalfn)) {
                return;
            }
        }
        $this->modify_capd(function ($d) use ($finalfn, $replaced) {
            if ($d->status === 2) {
                $d->content_file = $finalfn;
                $d->status = 3;
                $d->ready = true;
                if ($replaced) {
                    $d->replaced = true;
                }
            }
        });
    }

    /** Return true if the file at `$fn` holds this upload’s content.
     *
     * The content hash names `$fn`, so the token’s digests describe the
     * file’s correct content; the question is only whether the file is
     * damaged, which the xxh64 answers at a fraction of a sha256 pass.
     * @param string $fn
     * @return bool */
    private function _stored_file_matches($fn, DocumentInfo $doc) {
        clearstatcache(true, $fn);
        if (@filesize($fn) !== $this->_capd->size) {
            return false;
        } else if (isset($this->_capd->xxh64)) {
            return hash_file("xxh64", $fn) === $this->_capd->xxh64;
        }
        return $doc->file_binary_hash($fn, $doc->binary_hash()) === $doc->binary_hash();
    }

    /** @param ?S3Client $s3c */
    private function assemble_s3($s3c) {
        if (!$this->_capd->s3_uploadid) {
            // small file, move directly to destination
            $doc = DocumentInfo::make_token($this->conf, $this->_cap);
            if (!$doc) {
                return;
            }
            if ($this->_capd->replaced ?? false) {
                $ok = $s3c->put_file($doc->s3_key(), $this->_capd->content_file, $doc->mimetype, $doc->s3_user_data());
            } else {
                $ok = $doc->store_s3() > 0;
            }
            if ($ok) {
                $this->modify_capd(function ($d) {
                    $d->s3_ready = true;
                    $d->status = max($d->status, 5);
                });
            }
        } else if ($s3c->multipart_complete($this->s3_key(),
                                            $this->_capd->s3_uploadid,
                                            $this->_capd->s3_parts)) {
            $this->modify_capd(function ($d) {
                $d->status = max($d->status, 4);
            });
        }
    }

    /** @param bool $synchronous
     * @return ?resource */
    private function lock($synchronous) {
        // S3 dislikes parallel/out-of-order multi-part uploads. File lock
        // so process exit fixes it.
        $lockf = @fopen($this->lock_file(), "c");
        if (!$lockf) {
            $this->_ml[] = MessageItem::error("<0>Upload lock error");
        } else if (!flock($lockf, $synchronous ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
            // another request is transferring
            fclose($lockf);
            $lockf = null;
        }
        return $lockf ? : null;
    }

    /** @param resource $lockf */
    private function unlock($lockf) {
        flock($lockf, LOCK_UN);
        fclose($lockf);
    }

    /** The token lock serializes requests, including chunk writes, `size`,
     * and completion. S3 uploads use a subordinate lock. Taking the lock
     * reloads a loaded upload; if that shows it canceled, the upload is
     * reclaimed and the lock refused, so a refused caller has nothing left
     * to do but report.
     * @param string $salt
     * @param int $timeout  seconds to wait; 0 tries once
     * @return bool */
    private function lock_token($salt, $timeout) {
        if ($this->_lock_depth > 0) {
            ++$this->_lock_depth;
            return true;
        }
        if ($this->conf->fetch_ivalue("select get_lock(?,?)", $salt, $timeout) !== 1) {
            return false;
        }
        $this->_lock_salt = $salt;
        ++$this->_lock_depth;
        if ($this->_cap) {
            $this->modify_capd(null);
            if ($this->canceled()) {
                $this->try_reclaim();
                $this->unlock_token();
                return false;
            }
        }
        return true;
    }

    private function unlock_token() {
        assert($this->_lock_depth > 0);
        --$this->_lock_depth;
        if ($this->_lock_depth === 0) {
            $this->conf->qe("select release_lock(?)", $this->_lock_salt);
        }
    }

    /** @param bool $synchronous
     * @param string $debugid */
    private function transfer_s3_parts(S3Client $s3c, $synchronous, $debugid) {
        if (!($lockf = $this->lock($synchronous))) {
            return;
        }

        // locking may have blocked, check for state update
        $this->modify_capd(null);
        if (!$this->_capd) {
            $this->_ml[] = MessageItem::error("<0>Upload token changed underneath us");
            $this->unlock($lockf);
            return;
        }

        // XXX Backward compatibility: Exit if a lock from the previous code
        // is still apparently active
        if (($this->_capd->s3_lock ?? 0) > time() - self::OLD_LOCK_TIMEOUT) {
            $this->unlock($lockf);
            return;
        }

        // walk available parts and transfer to S3
        for ($segindex = 0; !$this->canceled(); ++$segindex) {
            [$seg0, $seg1] = $this->segment_boundaries($segindex);
            // exit if part not available
            if ($segindex >= count($this->_capd->s3_parts)
                || $seg0 >= $this->_capd->ranges[1]
                || min($seg1, $this->_capd->size ?? $seg1) > $this->_capd->ranges[1]) {
                break;
            }
            // skip if part already uploaded
            if ($this->_capd->s3_parts[$segindex] !== null) {
                continue;
            }
            // upload part
            set_time_limit(120);
            assert($seg1 - $seg0 >= self::MIN_MULTIPART_SIZE);
            $part = $this->s3_transfer_segment($s3c, $segindex);
            if ($part === false) {
                // may retry (XXX should give up eventually)
                break;
            }
            $this->modify_capd(function ($d) use ($segindex, $part) {
                $d->s3_parts[$segindex] = $part;
            });
        }
        // `cancel` leaves the teardown to whoever holds the lock
        if ($this->canceled()) {
            $this->reclaim();
            $this->_ml[] = MessageItem::error($this->_capd->cancel_message ?? "<0>Upload canceled");
        }
        $this->unlock($lockf);
    }

    /** Compute the hash, assemble the docstore file, and emplace it.
     * The caller must hold the upload lock. */
    private function complete_docstore_transfer() {
        assert($this->_lock_depth > 0);
        // the hash and the file cover exactly `size` bytes, so all of them
        // must be recorded, in segment files that reach `size`
        $size = $this->_capd->size;
        $nseg = count($this->_capd->s3_parts);
        if ($size === null
            || $this->_capd->ranges !== [0, $size]
            || ($this->segment_boundaries($nseg))[0] < $size) {
            return;
        }

        // status 0: hash not yet computed
        if ($this->_capd->status === 0) {
            if (!$this->_advance_hash()) {
                $this->_ml[] = MessageItem::error("<0>Hash computation error");
                return;
            }
            $ha = new HashAnalysis($this->conf->content_hash_algorithm());
            $hash = $ha->prefix() . hash_final($this->_hashctx);
            $crc = hash_final($this->_crc32ctx);
            $xxh64 = $this->_xxh64ctx ? hash_final($this->_xxh64ctx) : null;
            $this->_hashctx = $this->_crc32ctx = $this->_xxh64ctx = null;
            $this->modify_capd(function ($d) use ($hash, $crc, $xxh64) {
                $d->hash = $hash;
                $d->crc32 = $crc;
                if ($xxh64 !== null) {
                    $d->xxh64 = $xxh64;
                }
                $d->status = max($d->status, 1);
            });
        }

        // status 1: hash computed, docstore not assembled
        if (!$this->canceled()
            && $this->_capd->status === 1) {
            $this->assemble_docstore();
        }

        // status 2: docstore assembled, not ready
        if (!$this->canceled()
            && $this->_capd->status === 2) {
            $this->complete_docstore();
        }
    }

    /** @param ?S3Client $s3c */
    private function complete_transfer($s3c) {
        if ($this->_capd->size === 0) {
            $this->_ml[] = MessageItem::error("<0>Empty upload");
            $this->delete_files();
            return;
        }

        // Statuses 0–2 compute the hash and turn the segment files into the
        // stored document, so they must run once: hold the upload lock from
        // checking `status` through updating it. A detached request leaves
        // the work to whoever holds the lock.
        if (!$this->lock_token($this->_cap->salt, $this->_detached ? 0 : $this->lock_timeout)) {
            if ($this->canceled()) {
                $this->reclaim_canceled();
            }
            return;
        }
        try {
            $this->complete_docstore_transfer();
        } finally {
            $this->unlock_token();
        }
        $nseg = count($this->_capd->s3_parts ?? []);

        // status 3: docstore ready, S3 not ready or not configured
        if (!$this->canceled()
            && $this->_capd->status === 3
            && $s3c) {
            // check for upload in progress
            // -- Do not *restart* in-progress upload; that was handled before,
            // in transfer_s3_parts
            for ($segi = 0; $segi !== $nseg; ++$segi) {
                if ($this->_capd->s3_parts[$segi] === null)
                    return;
            }
            $this->assemble_s3($s3c);
        }

        // status 4: docstore ready, S3 multipart complete but not emplaced
        if (!$this->canceled()
            && $this->_capd->status === 4
            && $s3c
            && !$this->no_s3_move) {
            // move to final location
            $doc = DocumentInfo::make_token($this->conf, $this->_cap);
            if ((!($this->_capd->replaced ?? false)
                 && $s3c->head_size($this->dest_s3_key()) === $this->_capd->size)
                || $s3c->copy($this->s3_key(), $this->dest_s3_key(), $doc->s3_user_data() + ["content_type" => $doc->mimetype])) {
                $this->modify_capd(function ($d) {
                    $d->ready = true;
                    $d->status = max($d->status, 5);
                });
                $s3c->delete($this->s3_key());
            }
        }

        if ($this->canceled()
            || $this->_capd->status < 3) {
            $this->modify_capd(null);
        }

        if ($this->canceled()) {
            $this->reclaim_canceled();
        } else if ($this->_capd->status >= ($s3c ? 5 : 3)) {
            $this->delete_files();
        } else if ($this->_capd->status < 3) {
            $this->_ml[] = MessageItem::error("<0>Upload error");
        }
    }

    /** @param bool $synchronous
     * @param string $debugid */
    private function transfer($synchronous, $debugid) {
        if ($this->canceled()) {
            $this->reclaim_canceled();
            return;
        }

        // compute data mimetype (before starting S3 upload)
        if ($this->_capd->mimetype === null
            && ($this->_capd->ranges[1] >= 4096
                || ($this->_capd->size !== null
                    && $this->_capd->ranges[1] >= $this->_capd->size))) {
            $content = file_get_contents($this->segment_file(0), false, null, 0, 4096);
            $mimetype = Mimetype::content_type($content, $this->_capd->req_mimetype);
            $this->modify_capd(function ($d) use ($mimetype) {
                $d->mimetype = $mimetype;
            });
        }

        // Hash newly contiguous data. A `finish` or synchronous request
        // waits for the lock, since its reply carries the hash; a detached
        // request leaves the data to whoever holds the lock, and a later
        // request catches up. Completion below hashes anything left.
        if (!isset($this->_capd->hash)
            && $this->_capd->ranges !== [0, $this->_capd->size]
            && ($this->_capd->hashpos ?? 0) < $this->_hash_target()
            && $this->lock_token($this->_cap->salt, $this->_detached ? 0 : $this->lock_timeout)) {
            try {
                $this->_advance_hash();
            } finally {
                $this->unlock_token();
            }
        }
        // the lock may have been refused because the upload was canceled
        if ($this->canceled()) {
            $this->reclaim_canceled();
            return;
        }

        // transfer S3 parts
        $s3c = null;
        if (!$this->no_s3 && !$this->_capd->temp) {
            $s3c = $this->conf->s3_client();
        }
        // Nothing left to do only once the S3 side is done too. `ready` is set
        // at status 3, which is now before all the S3 work rather than after
        // it, so returning on `ready` alone strands the multipart upload
        // whenever transfer_s3_parts() was skipped -- the lock was held, or an
        // old-style lock deferred us -- and no later request can resume it.
        if (($this->_capd->ready ?? false)
            && (!$s3c || $this->_capd->status >= 5)) {
            return;
        }
        if ($s3c) {
            if ($s3c->has_curl()) {
                $s3c->set_result_class("CurlS3Result");
            }
            $this->transfer_s3_parts($s3c, $synchronous, $debugid);
        }

        // complete
        if (!$this->canceled()
            && $this->_capd->size !== null
            && $this->_capd->ranges === [0, $this->_capd->size]) {
            $this->complete_transfer($s3c);
        }
    }

    /** @param MessageItem ...$ml
     * @return JsonResult */
    private function _make_result(...$ml) {
        foreach ($ml as $mi) {
            $this->_ml[] = $mi;
        }
        $status = MessageSet::list_status($this->_ml);
        if (!$this->_capd) {
            // token vanished underneath us, e.g. swept by the token GC
            return new JsonResult($status < MessageSet::ERROR ? 200 : 400, [
                "ok" => $status < MessageSet::ERROR,
                "token" => $this->_cap->salt,
                "message_list" => $this->_ml
            ]);
        }
        $j = [
            "ok" => $status < MessageSet::ERROR,
            "token" => $this->_cap->salt,
            "dt" => $this->_capd->dtype,
            "filename" => $this->_capd->filename
        ];
        if ($this->_capd->mimetype !== null) {
            $j["mimetype"] = $this->_capd->mimetype;
        }
        $j["ranges"] = $this->_capd->ranges;
        if (isset($this->_capd->size)) {
            $j["size"] = $this->_capd->size;
        }
        if (isset($this->_capd->hash)) {
            $j["hash"] = $this->_capd->hash;
        }
        if (isset($this->_capd->crc32)) {
            $j["crc32"] = $this->_capd->crc32;
        }
        if (isset($this->_capd->size)) {
            [$unused, $seg1] = $this->segment_boundaries(count($this->_capd->s3_parts));
            $spl = min($seg1, $this->_capd->size) + (isset($this->_capd->hash) ? 1 << 20 : 0);
            $j["progress_value"] = (int) ($spl * self::SERVER_PROGRESS_FACTOR);
            $j["progress_max"] = (int) (($this->_capd->size + (1 << 20)) * self::SERVER_PROGRESS_FACTOR);
        }
        if (!empty($this->_ml)) {
            $j["message_list"] = $this->_ml;
        }
        return new JsonResult($status < MessageSet::ERROR ? 200 : 400, $j);
    }

    /** @return JsonResult */
    function exec(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $this->_cap = $this->_capd = null;
        if (!$this->tmpdir) {
            return JsonResult::make_message_list(501, MessageItem::error("<0>Upload API not available on this site"));
        } else {
            $user->ensure_account_here();
        }

        if (friendly_boolean($qreq->start)) {
            $j = $this->exec_start($user, $qreq, $prow);
            if (!$j->ok()) {
                return $j;
            }
        } else if (!$qreq->token) {
            return JsonResult::make_missing_error("token");
        } else if (strlen($qreq->token) > 36
                   || !preg_match('/\Ahcup\w++\z/', $qreq->token)) {
            return JsonResult::make_not_found_error("token", "<0>Upload token not found");
        }

        // requests on an upload are serialized by its lock
        if (!$this->lock_token($qreq->token, $this->lock_timeout)) {
            return JsonResult::make_message_list(409, MessageItem::error("<0>Upload busy"))
                ->set("token", $qreq->token);
        }
        $jr = null;
        try {
            $jr = $this->exec_locked($user, $qreq);
        } finally {
            $this->unlock_token();
        }
        if ($jr) {
            return $jr;
        } else if ($this->no_transfer) {
            return $this->_make_result();
        }

        $finish = friendly_boolean($qreq->finish);
        if (!$finish
            && !$this->synchronous
            && JsonCompletion::$allow_short_circuit) {
            $this->_make_result()->emit($qreq);
            if (PHP_SAPI === "fpm-fcgi") {
                fastcgi_finish_request();
            }
            $this->_detached = true;
            $this->transfer(false, "{$qreq->offset}+{$qreq->length}");
            Navigation::complete();
        }

        $this->transfer($this->synchronous, "finish");
        return $this->_make_result();
    }

    /** Load the upload and apply a request’s `cancel`, `size`, chunk, and
     * `finish` to it. The caller must hold the upload lock.
     * @return ?JsonResult */
    private function exec_locked(Contact $user, Qrequest $qreq) {
        assert($this->_lock_depth > 0);
        $this->_cap = $this->_cap ?? TokenInfo::find($qreq->token, $user->conf);
        if (!$this->_cap || $this->_cap->capabilityType !== TokenInfo::UPLOAD) {
            return JsonResult::make_not_found_error("token", "<0>Upload token not found");
        } else if ($this->_cap->contactId !== $user->contactId) {
            return JsonResult::make_parameter_error("token", "<0>That upload belongs to another user");
        }

        $this->_capd = $this->_cap->data();
        if (friendly_boolean($qreq->cancel)) {
            if ($this->_cap->is_active()) {
                $this->cancel();
            }
            $m = $this->_capd->cancel_message ?? "<0>Upload canceled";
            $this->_ml = [MessageItem::warning_note($m)];
            return $this->_make_result();
        }

        if (!$this->_cap->is_active()) {
            return JsonResult::make_not_found_error("token", "<0>Upload inactive or expired");
        }

        if (isset($qreq->size)) {
            $size = stoi($qreq->size);
            if ($size === null || $size < 0) {
                return $this->_make_result(MessageItem::error_at("size", "<0>Parameter error"));
            }
            if (($mi = $this->set_size($size))) {
                return $this->_make_result($mi);
            }
        }

        $offset = isset($qreq->offset) ? stoi($qreq->offset) : 0;
        if ($offset === null || $offset < 0) {
            return $this->_make_result(MessageItem::error_at("offset", "<0>Parameter error"));
        }
        $length = isset($qreq->length) ? stoi($qreq->length) : null;
        if (isset($qreq->length) && ($length === null || $length < 0)) {
            return $this->_make_result(MessageItem::error_at("length", "<0>Parameter error"));
        }

        $blob = $qreq->file("blob");
        if (!$blob && $qreq->blob !== null) {
            $blob = QrequestFile::make_string($qreq->blob);
        }
        if ($blob && !$this->_capd->hash) {
            if ($blob->size > $this->max_blob) {
                $mi = MessageItem::error_at("blob", "<0>Uploaded segment too large");
                return $this->_make_result($mi)->set("maxblob", $this->max_blob);
            }
            $length = $length ?? $blob->size;
            if ($length > $blob->size) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Uploaded file smaller than claimed `length`"));
            } else if ($offset + $length > $this->max_size) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Uploaded segment extends past maximum upload size"));
            } else if (isset($this->_capd->size) && $offset + $length > $this->_capd->size) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Uploaded segment extends past claimed upload size"));
            } else if (count(self::add_range($this->_capd->ranges, $offset, $offset + $length)) > 2 * self::MAX_RANGES) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Upload too fragmented"));
            }
            $data = $blob->content(0, $length);
            if ($data === false || strlen($data) !== $length) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Problem reading uploaded file"));
            }
            if (!$this->exec_upload($user, $offset, $data)) {
                // `exec_upload` explains itself where it can
                if (empty($this->_ml)) {
                    $this->_ml[] = MessageItem::error("<0>Upload failed");
                }
                return $this->_make_result();
            }
        } else if ($qreq->has_annex("upload_errors")) {
            return $this->_make_result(MessageItem::error_at("blob", "<0>Problem with uploaded file"));
        }

        if (friendly_boolean($qreq->finish)) {
            if (count($this->_capd->ranges) !== 2) {
                return $this->_make_result(MessageItem::error("<0>Upload has holes"));
            }
            if ($this->_capd->size === null
                && ($mi = $this->set_size(null))) {
                return $this->_make_result($mi);
            }
            if ($this->_capd->ranges !== [0, $this->_capd->size]) {
                return $this->_make_result(MessageItem::error("<0>Upload incomplete"));
            }
        }
        return null;
    }

    static function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return (new Upload_API($user->conf))->exec($user, $qreq, $prow);
    }

    static function cleanup(TokenInfo $cap) {
        $up = new Upload_API($cap->conf);
        $up->_cap = $cap;
        $up->_capd = $cap->data();
        // A request completing an expired upload may still hold its lock
        // and need its files. Never wait: if the lock is busy, push back the
        // token’s expiration so the next sweep cleans it.
        if ($up->lock_token($cap->salt, 0)) {
            try {
                $up->delete_all();
            } finally {
                $up->unlock_token();
            }
        } else if (!$up->canceled()) {
            $cap->conf->qe("update Capability set timeExpires=? where salt=? and timeExpires=?",
                Conf::$now + 3600, $cap->salt, $cap->timeExpires);
        }
    }
}
