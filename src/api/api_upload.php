<?php
// api_upload.php -- HotCRP upload manager
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Upload_API {
    const MIN_MULTIPART_SIZE = 5 << 20;
    const MAX_SIZE = 1 << 30;
    const MAX_BLOB = 32 << 20;
    const SERVER_PROGRESS_FACTOR = 0.5;

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
    /** @var object */
    private $_capd;
    /** @var bool */
    public $no_s3 = false;
    /** @var bool */
    public $no_s3_move = false;
    /** @var bool */
    public $synchronous = false;
    /** @var list<MessageItem> */
    private $_ml = [];
    /** @var ?HashContext */
    private $_hashctx;
    /** @var ?HashContext */
    private $_crc32ctx;
    /** @var ?int */
    private $_hashpos;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->max_size = $conf->opt("uploadApiMaxSize") ?? self::MAX_SIZE;
        $this->max_blob = $conf->opt("uploadApiMaxBlob") ?? self::MAX_BLOB;
        $this->segments = [[0, 5<<20], [5<<20, 13<<20], [13<<20, 29<<20]];
        $this->tmpdir = $conf->docstore_tempdir();
        //S3Client::$verbose = true;
    }

    /** @param int $offset
     * @return array{int,int,int} */
    private function find_segment($offset) {
        $segi = 0;
        $nseg = count($this->segments);
        while ($segi < $nseg - 1) {
            list($seg0, $seg1) = $this->segments[$segi];
            if ($offset >= $seg0 && $offset < $seg1) {
                return [$segi, $seg0, $seg1];
            }
            ++$segi;
        }
        list($seg0, $seg1) = $this->segments[$segi];
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
        list($seg0, $seg1) = $this->segments[$segl];
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
        $size = stoi($qreq->size);
        if ($size === null || $size < 0) {
            if (isset($qreq->size)) {
                return JsonResult::make_parameter_error("size");
            }
        } else if ($size > $this->max_size) {
            return JsonResult::make_parameter_error("size", "<0>File too large")->set("maxsize", $this->max_size);
        }
        $this->_cap = (new TokenInfo($this->conf, TokenInfo::UPLOAD))
            ->set_user_from($user, false)
            ->set_paper($prow)
            ->set_expires_in(7200);
        if (isset($qreq->filename)
            && strlen($qreq->filename) <= 255
            && is_valid_utf8($qreq->filename)) {
            $filename = $qreq->filename;
        } else {
            $filename = "_upload_";
        }
        $dtarg = $qreq->dt ?? $qreq->dtype /* XXX backward compat */;
        if (is_int($dtarg)) {
            $dtype = $dtarg;
        } else if ($dtarg && preg_match('/\A-?\d+\z/', $dtarg)) {
            $dtype = intval($dtarg);
        } else {
            $dtype = null;
        }
        $data = [
            "size" => $size,
            "ranges" => [0, 0],
            "filename" => $filename,
            "mimetype" => Mimetype::sanitize($qreq->mimetype) ?? "application/octet-stream",
            "pid" => $prow ? $prow->paperId : -1,
            "dtype" => $dtype,
            "temp" => friendly_boolean($qreq->temp) ?? $dtype === null,
            "hash" => null,
            "crc32" => null,
            "s3_parts" => [],
            "s3_uploadid" => false,
            "s3_lock" => null,
            "status" => 0,
            "hashctx" => null,
            "crc32ctx" => null,
            "hashpos" => 0
        ];
        if (PHP_VERSION_ID >= 80000) {
            $hashctx = hash_init($this->conf->content_hash_algorithm());
            $crc32ctx = hash_init("crc32b");
            $data["hashctx"] = base64_encode(serialize($hashctx));
            $data["crc32ctx"] = base64_encode(serialize($crc32ctx));
        }
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
        if ($this->_capd->s3_uploadid
            && ($s3d = $this->conf->s3_client())) {
            if ($this->_capd->status < 3) {
                $s3d->delete($this->s3_key() . "?uploadId=" . $this->_capd->s3_uploadid);
            } else if ($this->_capd->status < 4) {
                $s3d->delete($this->s3_key());
            }
        }
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
        if (!$this->_hashctx
            && isset($this->_capd->hashctx)
            && isset($this->_capd->crc32ctx)
            && isset($this->_capd->hashpos)
            && is_int($this->_capd->hashpos)
            && ($hashctx = $this->_parse_hashctx($this->_capd->hashctx))
            && ($crc32ctx = $this->_parse_hashctx($this->_capd->crc32ctx))) {
            $this->_hashctx = $hashctx;
            $this->_crc32ctx = $crc32ctx;
            $this->_hashpos = $this->_capd->hashpos;
        }
        if (!$this->_hashctx) {
            $this->_hashctx = hash_init($this->conf->content_hash_algorithm());
            $this->_crc32ctx = hash_init("crc32b");
            $this->_hashpos = 0;
        }
    }

    /** @param int $pos
     * @param string $data */
    private function _update_hashctx($pos, $data) {
        $this->_make_hashctx();
        if ($pos === $this->_hashpos) {
            hash_update($this->_hashctx, $data);
            hash_update($this->_crc32ctx, $data);
            $this->_hashpos += strlen($data);
        }
    }

    /** @param int $offset
     * @param string $data
     * @return bool */
    function exec_upload(Contact $user, $offset, $data) {
        $pos = 0;
        $last_offset = $offset + strlen($data);
        while ($offset !== $last_offset) {
            list($segi, $seg0, $seg1) = $this->find_segment($offset);
            $nbytes = min($last_offset, $seg1) - $offset;
            $fname = $this->segment_file($segi);
            $handle = fopen($fname, "c");
            if (!$handle
                || fseek($handle, $offset - $seg0, SEEK_SET) !== 0
                || fwrite($handle, substr($data, $pos, $nbytes)) !== $nbytes) {
                return false;
            }
            $this->_update_hashctx($offset, substr($data, $pos, $nbytes));
            $this->modify_capd(function ($d) use ($offset, $nbytes) {
                $d->ranges = Upload_API::add_range($d->ranges, $offset, $offset + $nbytes);
                if ($this->_hashctx
                    && $d->hashpos === $offset
                    && PHP_VERSION_ID >= 80000) {
                    $d->hashctx = base64_encode(serialize($this->_hashctx));
                    $d->crc32ctx = base64_encode(serialize($this->_crc32ctx));
                    $d->hashpos = $this->_hashpos;
                }
            });
            $pos += $nbytes;
            $offset += $nbytes;
        }
        return true;
    }

    /** @return string */
    static private function s3_key_for(TokenInfo $cap) {
        $confid = $cap->conf->opt("confid") ?? false;
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

    private function modify_capd($callable) {
        Dbl::compare_exchange(
            $this->conf->dblink,
            "select `data` from Capability where salt=?", [$this->_cap->salt],
            function ($oldd) use ($callable) {
                $this->_cap->assign_data($oldd);
                if (!$oldd || !($this->_capd = json_decode($oldd))) {
                    return $oldd;
                }
                call_user_func($callable, $this->_capd);
                $this->_cap->assign_data($this->_capd);
                return $this->_cap->encoded_data();
            },
            "update Capability set `data`=?{desired} where salt=? and `data`=?{expected}", [$this->_cap->salt]
        );
    }

    /** @return int */
    private function reload_capd_status() {
        $this->_cap->load_data();
        $this->_capd = $this->_cap->data();
        return $this->_capd->status ?? -1;
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

    private function assemble_docstore() {
        $asmfn = $this->assembly_file();
        if (!($file = fopen($asmfn, "cb"))
            || !flock($file, LOCK_EX | LOCK_NB)) {
            return;
        }
        ftruncate($file, 0);
        $nseg = count($this->_capd->s3_parts);
        for ($segi = 0; $segi !== $nseg; ++$segi)  {
            $infilename = $this->segment_file($segi);
            if (!($infile = fopen($infilename, "r"))) {
                break;
            }
            $n = stream_copy_to_stream($infile, $file);
            fclose($infile);
            if ($n !== filesize($infilename)) {
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
        flock($file, LOCK_UN);
        fclose($file);
    }

    private function complete_docstore() {
        $asmfn = $this->assembly_file();
        $doc = DocumentInfo::make_token($this->conf, $this->_cap, $asmfn);
        if ($this->_capd->temp) {
            $finalfn = $this->final_file($doc);
        } else {
            $finalfn = $this->conf->docstore()->path_for($doc, Docstore::FPATH_MKDIR);
        }
        if (!$finalfn) {
            return;
        }
        if (!rename($asmfn, $finalfn)) {
            usleep(100);
            $this->reload_capd_status();
            return;
        }
        $this->modify_capd(function ($d) use ($finalfn) {
            if ($d->status === 2) {
                $d->content_file = $finalfn;
                $d->status = 3;
                $d->ready = true;
            }
        });
    }

    /** @param ?S3Client $s3c */
    private function assemble_s3($s3c) {
        if (!$this->_capd->s3_uploadid) {
            // small file, move directly to destination
            $doc = DocumentInfo::make_token($this->conf, $this->_cap);
            if ($doc && $doc->store_s3()) {
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

    /** @param ?S3Client $s3c */
    private function complete_transfer($s3c) {
        $nseg = count($this->_capd->s3_parts);
        if ($nseg === 0) {
            $this->_ml[] = MessageItem::error("<0>Empty upload");
            $this->delete_files();
            return;
        }
        assert($this->_capd->size !== null);
        assert(($this->segment_boundaries($nseg))[0] >= $this->_capd->size);
        assert(!array_filter($this->_capd->s3_parts, function ($p) { return $p === null; }));

        // status 0: hash not yet computed
        if ($this->_capd->status === 0) {
            // compute hash
            $this->_make_hashctx();
            for ($segi = 0; $segi !== $nseg; ++$segi) {
                list($seg0, $seg1) = $this->segment_boundaries($segi);
                if ($seg1 <= $this->_hashpos) {
                    continue;
                }
                $f = $this->segment_file($segi);
                if ($seg0 === $this->_hashpos) {
                    $ok = hash_update_file($this->_hashctx, $f)
                        && hash_update_file($this->_crc32ctx, $f);
                } else {
                    $data = @file_get_contents($f, false, null, $this->_hashpos - $seg0);
                    $ok = $data !== false
                        && hash_update($this->_hashctx, $data)
                        && hash_update($this->_crc32ctx, $data);
                }
                if (!$ok) {
                    break;
                }
                $this->_hashpos = min($seg1, $this->_capd->size);
            }
            if ($this->_hashpos !== $this->_capd->size) {
                $this->_ml[] = MessageItem::error("<0>Hash computation error");
                return;
            }
            $ha = new HashAnalysis($this->conf->content_hash_algorithm());
            $hash = $ha->prefix() . hash_final($this->_hashctx);
            $crc = hash_final($this->_crc32ctx);
            $this->modify_capd(function ($d) use ($hash, $crc) {
                $d->hash = $hash;
                $d->crc32 = $crc;
                $d->status = max($d->status, 1);
            });
        }

        // status 1: hash computed, docstore not assembled
        if ($this->_capd->status === 1) {
            $this->assemble_docstore();
        }

        // status 2: docstore assembled, not ready
        if ($this->_capd->status === 2) {
            $this->complete_docstore();
        }

        // status 3: hash computed, docstore ready, S3 not ready
        if ($this->_capd->status === 3
            && $s3c) {
            $this->assemble_s3($s3c);
        }

        // status 4: hash computed, docstore ready,
        // S3 multipart complete but not moved to destination
        if ($this->_capd->status === 4
            && $s3c
            && !$this->no_s3_move) {
            // move to final location
            $doc = DocumentInfo::make_token($this->conf, $this->_cap);
            if ($s3c->head_size($this->dest_s3_key()) === $this->_capd->size
                || $s3c->copy($this->s3_key(), $this->dest_s3_key(), $doc->s3_user_data() + ["content_type" => $doc->mimetype])) {
                $this->modify_capd(function ($d) {
                    $d->s3_ready = true;
                    $d->status = max($d->status, 5);
                });
                $s3c->delete($this->s3_key());
            }
        }

        if ($this->_capd->status >= 3
            || $this->reload_capd_status() >= 3) {
            // success; clean up
            $this->delete_files();
        } else {
            $this->_ml[] = MessageItem::error("<0>Upload error");
        }
    }

    /** @param bool $synchronous
     * @param string $debugid */
    private function transfer($synchronous, $debugid) {
        // obtain lock
        $start = time();
        $have_lock = false;
        $delay = 50000;
        while (true) {
            if (($this->_capd->ready ?? false)
                || time() > $start + 5) {
                return;
            } else if (!$this->_capd->s3_lock) {
                $this->_capd->s3_lock = time();
                $new_data = json_encode_db($this->_capd);
                $result = $this->conf->qe("update Capability set `data`=? where salt=? and `data`=?", $new_data, $this->_cap->salt, $this->_cap->encoded_data());
                if ($result->affected_rows > 0) {
                    $this->_cap->assign_data($new_data);
                    $have_lock = $this->_capd->s3_lock;
                    break;
                }
            } else if (!$synchronous) {
                return;
            }
            usleep($delay);
            $delay = min($delay * 2, 250000);
            $this->_cap->load_data();
            $this->_capd = $this->_cap->data();
            if (!$this->_capd) {
                $this->_ml[] = MessageItem::error("<0>Capability changed underneath us");
                return;
            }
        }

        // walk parts, transfer to S3 if available
        $segindex = count($this->_capd->s3_parts);
        list($seg0, $seg1) = $this->segment_boundaries($segindex);
        $s3c = null;
        if (!$this->no_s3 && !$this->_capd->temp) {
            $s3c = $this->conf->s3_client();
        }
        if ($s3c) {
            //$s3c->result_class = "CurlS3Result";
        }
        while ($seg0 < $this->_capd->ranges[1]
               && min($seg1, $this->_capd->size ?? $seg1) <= $this->_capd->ranges[1]) {
            set_time_limit(120);
            assert($seg1 - $seg0 >= self::MIN_MULTIPART_SIZE);
            if ($segindex === 0) {
                $content = file_get_contents($this->segment_file(0), false, null, 0, 4096);
                $mimetype = Mimetype::content_type($content, $this->_capd->mimetype);
                $this->modify_capd(function ($d) use ($mimetype) {
                    $d->mimetype = $mimetype;
                });
            }
            $part = $s3c ? $this->s3_transfer_segment($s3c, $segindex) : "x";
            if ($part === false) {
                return false;
            }
            $this->modify_capd(function ($d) use ($segindex, $part) {
                while (count($d->s3_parts) <= $segindex) {
                    $d->s3_parts[] = null;
                }
                $d->s3_parts[$segindex] = $part;
            });
            ++$segindex;
            list($seg0, $seg1) = $this->segment_boundaries($segindex);
        }

        // complete
        if (isset($this->_capd->size)
            && $seg0 >= $this->_capd->size) {
            $this->complete_transfer($s3c);
        }

        // release lock
        $this->modify_capd(function ($d) use ($have_lock) {
            if ($d->s3_lock === $have_lock) {
                $d->s3_lock = null;
            }
        });
    }

    /** @param MessageItem ...$ml
     * @return JsonResult */
    private function _make_result(...$ml) {
        foreach ($ml as $mi) {
            $this->_ml[] = $mi;
        }
        $status = MessageSet::list_status($this->_ml);
        $j = [
            "ok" => $status < MessageSet::ERROR,
            "token" => $this->_cap->salt,
            "dt" => $this->_capd->dtype,
            "filename" => $this->_capd->filename,
            "mimetype" => $this->_capd->mimetype,
            "ranges" => $this->_capd->ranges
        ];
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
            list($unused, $seg1) = $this->segment_boundaries(count($this->_capd->s3_parts));
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
        $qreq->qsession()->commit();

        if (friendly_boolean($qreq->start)) {
            $j = $this->exec_start($user, $qreq, $prow);
            if (!$j->ok()) {
                return $j;
            }
        } else if ($qreq->token) {
            $this->_cap = TokenInfo::find($qreq->token, $user->conf);
        } else {
            return JsonResult::make_missing_error("token");
        }

        if (!$this->_cap || $this->_cap->capabilityType !== TokenInfo::UPLOAD) {
            return JsonResult::make_not_found_error("token", "<0>Upload token not found");
        } else if (!$this->_cap->is_active()) {
            error_log("token {$qreq->token} inactive: expires {$this->_cap->timeExpires}, invalid {$this->_cap->timeInvalid}");
            return JsonResult::make_not_found_error("token", "<0>Upload inactive or expired");
        } else if ($this->_cap->contactId !== $user->contactId) {
            return JsonResult::make_parameter_error("token", "<0>That upload belongs to another user");
        }
        $this->_capd = $this->_cap->data();

        if (friendly_boolean($qreq->cancel)) {
            $this->delete_all();
            $this->_cap->delete();
            return JsonResult::make_message_list(MessageItem::marked_note("<0>Upload canceled"))->set("token", $qreq->token);
        }

        if (isset($qreq->size)) {
            $size = stoi($qreq->size);
            if ($size === null || $size < 0) {
                return $this->_make_result(MessageItem::error_at("size", "<0>Parameter error"));
            }
            if (!isset($this->_capd->size)) {
                $this->modify_capd(function ($d) use ($size) {
                    $this->_capd->size = $size;
                });
            } else if ($size !== $this->_capd->size) {
                return $this->_make_result(MessageItem::error_at("size", "<0>Wrong size"));
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
            }
            $data = $blob->content(0, $length);
            if ($data === false || strlen($data) !== $length) {
                return $this->_make_result(MessageItem::error_at("blob", "<0>Problem reading uploaded file"));
            }
            if (!$this->exec_upload($user, $offset, $data)) {
                return $this->_make_result(MessageItem::error("<0>Upload failed"));
            }
        } else if ($qreq->has_annex("upload_errors")) {
            return $this->_make_result(MessageItem::error_at("blob", "<0>Problem with uploaded file"));
        }

        $finish = friendly_boolean($qreq->finish);
        if ($finish) {
            if (count($this->_capd->ranges) !== 2) {
                return $this->_make_result(MessageItem::error("<0>Upload has holes"));
            }
            if ($this->_capd->size === null) {
                $this->modify_capd(function ($d) {
                    if (count($this->_capd->ranges) === 2
                        && $this->_capd->ranges[0] === 0) {
                        $this->_capd->size = $this->_capd->ranges[1];
                    }
                });
            }
            if ($this->_capd->ranges !== [0, $this->_capd->size]) {
                return $this->_make_result(MessageItem::error("<0>Upload incomplete"));
            }
        }

        if (!$finish
            && !$this->synchronous
            && JsonCompletion::$allow_short_circuit) {
            $this->_make_result()->emit($qreq);
            if (PHP_SAPI === "fpm-fcgi") {
                fastcgi_finish_request();
            }
            $this->transfer(false, "{$offset}+{$length}");
            exit(0);
        }

        $this->transfer(true, "finish");
        return $this->_make_result();
    }

    static function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return (new Upload_API($user->conf))->exec($user, $qreq, $prow);
    }

    static function cleanup(TokenInfo $cap) {
        $up = new Upload_API($cap->conf);
        $up->_cap = $cap;
        $up->_capd = $cap->data();
        $up->delete_all();
    }
}
