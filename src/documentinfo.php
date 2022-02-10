<?php
// documentinfo.php -- HotCRP document objects
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentInfo implements JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var ?PaperInfo */
    public $prow;

    // database fields
    /** @var int */
    public $paperId = 0;
    /** @var int */
    public $paperStorageId = 0;
    public $timestamp;
    /** @var string */
    public $mimetype;
    /** @var ?string */
    private $paper;    // translated to `content` on load
    public $compression;
    /** @var string|false */
    public $sha1 = ""; // binary hash; empty = unhashed, false = not available
    /** @var ?string|false */
    private $crc32;    // binary hash
    /** @var int */
    public $documentType = 0;
    /** @var ?string */
    public $filename;
    /** @var false|null|object */
    public $infoJson = false;
    /** @var ?int */
    public $size;
    /** @var ?int */
    public $filterType;
    /** @var ?int */
    public $originalStorageId;
    /** @var int */
    public $inactive = 0;
    private $_owner;

    /** @var ?string */
    public $content;
    /** @var ?string */
    public $content_file;
    /** @var ?string */
    public $filestore;
    /** @var bool */
    private $_prefer_s3 = false;

    /** @var ?string */
    private $_member_filename;
    public $sourceHash;
    /** @var bool */
    public $is_partial = false;
    public $filters_applied;
    /** @var ?MessageSet */
    private $_ms;

    const LINKTYPE_COMMENT_BEGIN = 0;
    const LINKTYPE_COMMENT_END = 1024;

    function __construct($p, Conf $conf, PaperInfo $prow = null) {
        $this->merge($p, $conf, $prow);
    }

    private function merge($p, Conf $conf, PaperInfo $prow = null) {
        $this->conf = $conf;
        $this->prow = $prow;
        if ($p) {
            foreach ($p as $k => $v) {
                if ($k === "hash") {
                    $this->sha1 = $v;
                } else if ($k === "content_base64") {
                    $this->content = base64_decode($v);
                } else if ($k === "metadata") {
                    /** @phan-suppress-next-line PhanTypeMismatchProperty */
                    $this->infoJson = $v;
                } else {
                    $this->$k = $v;
                }
            }
        }
        $this->paperId = (int) $this->paperId;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->timestamp = (int) $this->timestamp;
        $this->documentType = (int) $this->documentType;
        assert($this->paperStorageId <= 1 || !!$this->mimetype);
        assert($this->sha1 === false || is_string($this->sha1));
        if ($this->sha1 !== false && $this->sha1 !== "") {
            $this->sha1 = Filer::hash_as_binary($this->sha1);
        }
        if ($this->crc32 !== null) {
            if (strlen($this->crc32) === 8 && ctype_xdigit($this->crc32)) {
                $this->crc32 = hex2bin($this->crc32);
            } else if (strlen($this->crc32) !== 4) {
                $this->crc32 = null;
            }
        }
        $this->size = (int) $this->size;
        if (is_string($this->infoJson)) {
            $this->infoJson = json_decode($this->infoJson);
        } else if (is_associative_array($this->infoJson)) {
            $this->infoJson = array_to_object_recursive($this->infoJson);
        } else if (!is_object($this->infoJson) && $this->infoJson !== false) {
            $this->infoJson = null;
        }
        $this->filterType = (int) $this->filterType ? : null;
        $this->originalStorageId = (int) $this->originalStorageId ? : null;
        $this->inactive = (int) $this->inactive;
        if ($this->sourceHash != "") {
            $this->sourceHash = Filer::hash_as_binary($this->sourceHash);
        }
        if (isset($this->paper) && !isset($this->content)) {
            $this->content = $this->paper;
            $this->paper = null;
        }
    }

    /** @return ?DocumentInfo */
    static function fetch($result, Conf $conf, PaperInfo $prow = null) {
        $di = $result ? $result->fetch_object("DocumentInfo", [null, $conf, $prow]) : null;
        '@phan-var ?DocumentInfo $di';
        if ($di && !is_int($di->paperStorageId)) {
            $di->merge(null, $conf, $prow);
        }
        return $di;
    }

    /** @param array $upload
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_uploaded_file($upload, $paperId, $documentType, Conf $conf) {
        if (!$upload || !is_array($upload)) {
            return null;
        }
        $args = [
            "paperId" => $paperId,
            "documentType" => $documentType,
            "timestamp" => time(),
            "mimetype" => $upload["type"] ?? null,
            "filename" => self::sanitize_filename($upload["name"] ?? null)
        ];
        $fnhtml = isset($args["filename"]) ? " “" . htmlspecialchars($args["filename"]) . "”" : "";

        $content = false;
        $upload_error = "";
        if (isset($upload["content"])) {
            $content = $args["content"] = $upload["content"];
        } else if (isset($upload["content_file"])) {
            $args["content_file"] = $upload["content_file"];
        } else if (isset($upload["tmp_name"]) && is_readable($upload["tmp_name"])) {
            $args["size"] = filesize($upload["tmp_name"]);
            if ($args["size"] > 0) {
                $args["content_file"] = $upload["tmp_name"];
            } else {
                $upload_error = " was empty, not saving";
            }
        } else {
            $upload_error = " could not be read";
        }
        self::fix_mimetype($args);
        $doc = new DocumentInfo($args, $conf);
        if ($upload_error) {
            $filename = $args["filename"] ?? "";
            $doc->error("<0>Uploaded file" . ($filename === "" ? "" : "‘{$filename}’") . $upload_error);
        }
        return new DocumentInfo($args, $conf);
    }

    /** @param string $token
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_capability($token, $paperId, $documentType, Conf $conf) {
        if (!$token
            || !($cap = TokenInfo::find($token, $conf))
            || !$cap->is_active()
            || $cap->capabilityType !== TokenInfo::UPLOAD) {
            return null;
        }
        $capd = json_decode($cap->data);
        if (!$capd->hash) {
            return null;
        }
        $args = [
            "paperId" => $paperId,
            "documentType" => $documentType,
            "timestamp" => time(),
            "mimetype" => $capd->mimetype ?? null,
            "filename" => self::sanitize_filename($capd->filename),
            "size" => $capd->size,
            "hash" => $capd->hash,
            "crc32" => $capd->crc32 ?? null
        ];
        $doc = new DocumentInfo($args, $conf);
        $doc->_prefer_s3 = !!($capd->s3_status ?? false);
        return $doc;
    }

    /** @param string $name
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_request(Qrequest $qreq, $name, $paperId,
                                 $documentType, Conf $conf) {
        if (($f1 = $qreq->file($name))) {
            return self::make_uploaded_file($f1, $paperId, $documentType, $conf);
        } else if (($f2 = $qreq["{$name}:upload"])) {
            return self::make_capability($f2, $paperId, $documentType, $conf);
        } else {
            return null;
        }
    }

    static function check_json_upload($j) {
        return is_object($j)
            && (!isset($j->content) || is_string($j->content))
            && (!isset($j->content_base64) || is_string($j->content_base64))
            && (!isset($j->content_file) || is_string($j->content_file));
    }

    static function fix_mimetype(&$args) {
        $content = $args["content"] ?? null;
        if ($content === null && isset($args["content_file"])) {
            $content = file_get_contents($args["content_file"], false, null, 0, 2048);
        } else if ($content === null && isset($args["content_base64"])) {
            $content = base64_decode(substr($args["content_base64"], 0, 2730));
        }
        $args["mimetype"] = Mimetype::content_type($content, $args["mimetype"] ?? null);
    }

    /** @param ?string $fn
     * @return ?string */
    static function sanitize_filename($fn) {
        $fn = str_replace(["/", "\\"], "_", $fn ?? "");
        if (str_starts_with($fn, ".")) {
            $fn = "_" . substr($fn, 1);
        }
        if (strlen($fn) > 255) {
            if (($dot = strpos($fn, ".", strlen($fn) - 10)) !== false) {
                $extlen = strlen($fn) - $dot;
                $fn = substr($fn, 0, 252 - $extlen) . "..." . substr($fn, $dot);
            } else {
                $fn = substr($fn, 0, 252) . "...";
            }
        }
        if ($fn !== "" && !is_valid_utf8($fn)) {
            $fn = UnicodeHelper::utf8_replace_invalid($fn);
        }
        return $fn !== "" ? $fn : null;
    }


    /** @return DocumentInfo */
    function with_owner($owner) {
        if ($this->_owner === null) {
            $this->_owner = $owner;
            return $this;
        } else if ($this->_owner === $owner) {
            return $this;
        } else {
            $d = clone $this;
            $d->_owner = $owner;
            return $d;
        }
    }

    /** @param string $fn
     * @return DocumentInfo */
    function with_member_filename($fn) {
        if ($this->_member_filename === null) {
            $this->_member_filename = $fn;
            return $this;
        } else if ($this->_member_filename === $fn) {
            return $this;
        } else {
            $d = clone $this;
            $d->_member_filename = $fn;
            return $d;
        }
    }

    private function find_owner() {
        if ($this->documentType == DTYPE_COMMENT) {
            $this->prow = $this->prow ?? $this->conf->paper_by_id($this->paperId);
            if ($this->prow
                && ($cid = $this->prow->link_id_by_document_id($this->paperStorageId, self::LINKTYPE_COMMENT_BEGIN, self::LINKTYPE_COMMENT_END))) {
                $this->_owner = $this->prow->comment_by_id($cid);
            }
        }
        return !!$this->_owner;
    }

    /** @return bool */
    function has_error() {
        return $this->_ms && $this->_ms->has_error();
    }
    /** @return MessageSet */
    function message_set() {
        $this->_ms = $this->_ms ?? (new MessageSet)->set_want_ftext(true, 5);
        return $this->_ms;
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }
    /** @param string $msg
     * @return MessageItem */
    function error($msg) {
        return $this->message_set()->error_at(null, $msg);
    }

    /** @param string $content
     * @param ?string $mimetype */
    function set_content($content, $mimetype = null) {
        assert(is_string($content));
        $this->content = $content;
        $this->size = strlen($content);
        $this->mimetype = $mimetype;
        $this->sha1 = $this->crc32 = "";
    }


    /** @return bool */
    function content_available() {
        return $this->content !== null
            || $this->content_file !== null
            || $this->filestore !== null;
    }

    /** @return bool */
    function ensure_content() {
        if ($this->content_available()) {
            return true;
        }
        // 1. check docstore
        if ($this->load_docstore()) {
            return true;
        }
        // 2. check db
        $dbNoPapers = $this->conf->opt("dbNoPapers");
        if (!$dbNoPapers && $this->load_database()) {
            return true;
        }
        // 3. check S3
        if ($this->load_s3()) {
            return true;
        }
        // 4. check db as last resort
        if ($dbNoPapers && $this->load_database()) {
            return true;
        }
        // not found
        $this->error("<0>Cannot load document");
        return false;
    }

    /** @return int|false */
    function content_size() {
        if (!$this->ensure_content()) {
            return false;
        } else if ($this->content !== null) {
            return strlen($this->content);
        } else if ($this->content_file !== null) {
            return filesize($this->content_file);
        } else if ($this->filestore !== null) {
            return filesize($this->filestore);
        } else {
            return false;
        }
    }

    /** @return int */
    function size() {
        $this->ensure_size();
        return $this->size;
    }

    /** @return bool */
    function ensure_size() {
        if ($this->size == 0 && $this->paperStorageId !== 1) {
            // NB This function may be called from `load_s3()`!
            // Avoid a recursive call to `load_s3()` via `head_size()`
            if ($this->content_available()
                || $this->load_docstore()
                || (!$this->conf->opt("dbNoPapers") && $this->load_database())
                || !$this->check_s3()
                || ($size = $this->conf->s3_docstore()->head_size($this->s3_key())) === false) {
                $size = (int) $this->content_size();
            }
            $this->size = $size;
            if ($this->size != 0 && $this->paperStorageId > 1) {
                $this->conf->qe("update PaperStorage set size=? where paperId=? and paperStorageId=? and size=0", $this->size, $this->paperId, $this->paperStorageId);
            }
        }
        return $this->size != 0 || $this->paperStorageId === 1;
    }

    function reset_size() {
        $this->size = (int) $this->content_size();
    }

    /** @param string $fn
     * @param int $expected_size
     * @return int|false */
    static function filesize_expected($fn, $expected_size) {
        $sz = @filesize($fn);
        if ($sz !== $expected_size && $sz !== false) {
            clearstatcache(true, $fn);
            $sz = @filesize($fn);
        }
        return $sz;
    }


    /** @return bool */
    function compressible() {
        return $this->size() <= 10000000
            && Mimetype::compressible($this->mimetype);
    }

    /** @return bool */
    function load_database() {
        if ($this->paperStorageId <= 1) {
            return false;
        }
        $row = $this->conf->fetch_first_row("select paper, compression from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId);
        if ($row === null) {
            $row = $this->conf->fetch_first_row("select paper, compression from PaperStorage where paperStorageId=?", $this->paperStorageId);
        }
        if ($row !== null && $row[0] !== null) {
            $this->content = $row[1] == 1 ? gzinflate($row[0]) : $row[0];
            return true;
        } else {
            return false;
        }
    }

    /** @return bool */
    function load_docstore() {
        if (($path = Filer::docstore_path($this, Filer::FPATH_EXISTS))) {
            if ($this->size != 0) {
                $sz = self::filesize_expected($path, $this->size);
                if ($sz !== $this->size
                    && ($s3 = $this->conf->s3_docstore())
                    && ($s3k = $this->s3_key())
                    && $s3->head_size($s3k) === $this->size()) {
                    unlink($path);
                    return false;
                } else if ($sz !== $this->size) {
                    error_log("{$this->conf->dbname}: #{$this->paperId}/{$this->documentType}/{$this->paperStorageId}: bad size $sz, expected $this->size");
                }
            }
            $this->filestore = $path;
            return true;
        } else {
            return false;
        }
    }

    /** @return bool */
    function need_prefetch_content() {
        return $this->content === null
            && $this->content_file === null
            && !$this->filestore
            && !$this->load_docstore()
            && $this->conf->s3_docstore();
    }

    /** @return bool */
    function store_skeleton() {
        $this->ensure_size();
        if (!$this->timestamp) {
            $this->timestamp = time();
        }
        $upd = [
            "paperId" => $this->paperId,
            "sha1" => $this->binary_hash(),
            "timestamp" => $this->timestamp,
            "size" => $this->size,
            "mimetype" => $this->mimetype,
            "documentType" => $this->documentType,
            "inactive" => 0
        ];
        if (($this->crc32 || $this->size <= 10000000)
            && ($crc32 = $this->crc32()) !== false) {
            $upd["crc32"] = $crc32;
        }
        foreach (["filename", "filterType", "originalStorageId"] as $k) {
            if ($this->$k)
                $upd[$k] = $this->$k;
        }
        if ($this->infoJson) {
            $upd["infoJson"] = json_encode_db($this->infoJson);
        }

        if ($this->paperStorageId > 1) {
            $qv = array_values($upd);
            $qv[] = $this->paperStorageId;
            $result = $this->conf->qe_apply("update PaperStorage set " . join("=?, ", array_keys($upd)) . "=? where paperStorageId=?", $qv);
        } else {
            $result = $this->conf->qe_apply("insert into PaperStorage set " . join("=?, ", array_keys($upd)) . "=?", array_values($upd));
            if ($result->affected_rows) {
                $this->paperStorageId = (int) $result->insert_id;
            }
        }

        if (!Dbl::is_error($result)) {
            Dbl::free($result);
            return true;
        } else {
            if ($this->conf->dblink->errno) {
                error_log("Error while saving document: " . $this->conf->dblink->error);
            }
            $this->error("<0>Internal error while saving document");
            return false;
        }
    }

    /** @return ?bool */
    private function store_database() {
        if (!$this->conf->opt("dbNoPapers")) {
            $content = $this->content();
            for ($p = 0; $p < strlen($content); $p += 400000) {
                $result = $this->conf->qe("update PaperStorage set paper=concat(coalesce(paper,''),?) where paperId=? and paperStorageId=?", substr($content, $p, 400000), $this->paperId, $this->paperStorageId);
                if (Dbl::is_error($result)) {
                    break;
                }
                Dbl::free($result);
            }
            if ($this->conf->fetch_ivalue("select length(paper) from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId) === strlen($content)) {
                return true;
            }
            $this->message_set()->warning_at(".content", "<0>Internal error while saving document content to database");
            return false;
        }
        return null;
    }

    /** @return ?bool */
    private function store_docstore() {
        if (($dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR))) {
            if (file_exists($dspath)
                && $this->file_binary_hash($dspath, $this->binary_hash()) === $this->binary_hash()) {
                $ok = true;
            } else if ($this->content_file !== null
                       && copy($this->content_file, $dspath)
                       && @filesize($dspath) === @filesize($this->content_file)) {
                $ok = true;
            } else {
                $content = $this->content();
                if (file_put_contents($dspath . "~", $content) === strlen($content)
                    && rename($dspath . "~", $dspath))
                    $ok = true;
                else {
                    @unlink($dspath . "~");
                    $ok = false;
                }
            }
            if ($ok) {
                $this->filestore = $dspath;
                @chmod($dspath, 0660 & ~umask());
                return true;
            } else {
                @unlink($dspath);
                $this->message_set()->warning_at(".content", "<0>Internal error while saving document to file system");
                return false;
            }
        }
        return null;
    }

    /** @param string $text_hash
     * @param string|Mimetype $mimetype
     * @return string */
    static function s3_key_for($text_hash, $mimetype) {
        // Format: `doc/%[2/3]H/%h%x`. Why not algorithm in subdirectory?
        // Because S3 works better if keys are partitionable.
        if (strlen($text_hash) === 40) {
            $x = substr($text_hash, 0, 2);
        } else {
            $x = substr($text_hash, strpos($text_hash, "-") + 1, 3);
        }
        return "doc/$x/$text_hash" . Mimetype::extension($mimetype);
    }

    /** @return ?string */
    function s3_key() {
        if (($hash = $this->text_hash()) !== false) {
            return self::s3_key_for($hash, $this->mimetype);
        } else {
            return null;
        }
    }

    private function s3_upgrade_extension(S3Client $s3, $s3k) {
        $extension = Mimetype::extension($this->mimetype);
        if ($extension === ".pdf" || $extension === "") {
            return false;
        }
        return $s3->copy(substr($s3k, 0, -strlen($extension)), $s3k);
    }

    /** @return string|false */
    function s3_accel_redirect() {
        if (($s3as = $this->conf->opt("s3AccelRedirectThreshold"))
            && $this->size >= $s3as
            && $this->conf->s3_docstore()
            && $this->s3_key()
            && ($s3ap = $this->conf->opt("s3AccelRedirect"))) {
            return $s3ap;
        } else {
            return false;
        }
    }

    /** @return bool */
    function load_s3() {
        if (($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())) {
            if (($dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR))
                && function_exists("curl_init")) {
                $stream = @fopen("{$dspath}~", "x+b");
                if ($stream === false
                    && @filemtime("{$dspath}~") < Conf::$now - 3600
                    && @unlink("{$dspath}~")) {
                    $stream = @fopen("{$dspath}~", "x+b");
                }
                if ($stream) {
                    $s3l = $s3->start_curl_get($s3k)
                        ->set_response_body_stream($stream)
                        ->set_expected_size($this->size());
                    $s3l->run();
                    return $this->handle_load_s3_curl($s3l, $dspath);
                }
            }
            return $this->load_s3_direct($s3, $s3k, $dspath);
        } else {
            return false;
        }
    }

    /** @param CurlS3Result $s3l
     * @param string $dspath
     * @return bool */
    private function handle_load_s3_curl($s3l, $dspath) {
        if ($s3l->status === 404
            && $this->s3_upgrade_extension($s3l->s3, $s3l->skey)) {
            $s3l->reset()->run();
        }
        fclose($s3l->dstream);
        $unlink = true;
        if ($s3l->status === 200) {
            if (($sz = self::filesize_expected("{$dspath}~", $this->size)) !== $this->size) {
                error_log("Disk error: GET $s3l->skey: expected size {$this->size}, got " . json_encode($sz));
                $s3l->status = 500;
            } else if (rename("{$dspath}~", $dspath)) {
                $this->filestore = $dspath;
                $unlink = false;
            } else {
                $this->content = file_get_contents("{$dspath}~");
            }
        } else {
            error_log("S3 error: GET $s3l->skey: $s3l->status $s3l->status_text " . json_encode_db($s3l->response_headers));
        }
        if ($unlink) {
            @unlink("{$dspath}~");
        }
        return $s3l->status === 200;
    }

    /** @param S3Client $s3
     * @param string $s3k
     * @param string $dspath
     * @return bool */
    private function load_s3_direct($s3, $s3k, $dspath) {
        $r = $s3->start_get($s3k)->run();
        if ($r->status === 404
            && $this->s3_upgrade_extension($s3, $s3k)) {
            $r = $s3->start_get($s3k)->run();
        }
        if ($r->status === 200 && ($b = $r->response_body() ?? "") !== "") {
            if ($dspath
                && file_put_contents($dspath, $b) === $this->size) {
                $this->filestore = $dspath;
            } else {
                $this->content = $b;
            }
            return true;
        } else {
            if ($r->status !== 200) {
                error_log("S3 error: GET $s3k: $r->status $r->status_text " . json_encode_db($r->response_headers));
            }
            return false;
        }
    }

    /** @return bool */
    function check_s3() {
        return ($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())
            && ($s3->head($s3k)
                || ($this->s3_upgrade_extension($s3, $s3k) && $s3->head($s3k)));
    }

    /** @return ?bool */
    function store_s3() {
        if (($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())) {
            $meta = ["conf" => $this->conf->dbname, "pid" => $this->paperId, "dtype" => $this->documentType];
            if ($this->filterType) {
                $meta["filtertype"] = $this->filterType;
                if ($this->sourceHash != "") {
                    $meta["sourcehash"] = Filer::hash_as_text($this->sourceHash);
                }
            }
            $user_data = ["hotcrp" => json_encode_db($meta)];
            $s3k = $this->s3_key();

            if ($s3->head_size($s3k) === $this->size()
                || (($path = $this->available_content_file())
                    && $s3->put_file($s3k, $path, $this->mimetype, $user_data))) {
                return true;
            }

            $r = $s3->start_put($s3k, $this->content(), $this->mimetype, $user_data)->run();
            if ($r->status === 200) {
                return true;
            } else {
                error_log("S3 error: POST $s3k: $r->status $r->status_text " . json_encode_db($r->response_headers));
                $this->message_set()->warning_at(".content", "<0>Internal error while saving document to S3");
                return false;
            }
        }
        return null;
    }


    /** @return bool */
    function save() {
        // look for an existing document with same sha1
        if ($this->binary_hash() !== false && $this->paperId != 0) {
            $row = $this->conf->fetch_first_row("select paperStorageId, timestamp, inactive, filename, mimetype from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $this->documentType, $this->binary_hash());
            if ($row
                && (!isset($this->filename) || $row[3] === $this->filename)
                && (!isset($this->mimetype) || $row[4] === $this->mimetype)) {
                $this->paperStorageId = (int) $row[0];
                $this->timestamp = (int) $row[1];
                if ($row[2]) {
                    $this->conf->qe("update PaperStorage set inactive=0 where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId);
                }
                return true;
            }
        }

        // ensure content
        $s3 = false;
        if ($this->_prefer_s3 && $this->check_s3()) {
            $s3 = true;
        } else if (!$this->ensure_content() || $this->has_error()) {
            return false;
        }

        // validate
        if (!$this->filterType) {
            $opt = $this->conf->option_by_id($this->documentType);
            if ($opt && !$opt->validate_document($this)) {
                return false;
            }
        }

        // store
        $s0 = $this->store_skeleton();
        $s1 = $s0 && $this->store_database();
        $s2 = !$s3 && $this->store_docstore();
        $s3 = $s3 || $this->store_s3();
        if ($s0 && ($s1 || $s2 || $s3)) {
            if ($this->_ms && $this->_ms->has_problem_at(".content")) {
                error_log("Recoverable error saving document " . $this->export_filename() . ", hash " . $this->text_hash() . ": " . MessageSet::feedback_text($this->_ms->message_list_at(".content")));
                $ms = $this->_ms;
                $this->_ms = null;
                foreach ($ms->message_list() as $mi) {
                    if ($mi->field !== ".content")
                        $this->message_set()->append_item($mi);
                }
            }
            return true;
        } else {
            $this->message_set()->prepend_msg("<0>Document not saved", MessageSet::ERROR);
            error_log("Error saving document " . $this->export_filename() . ", hash " . $this->text_hash() . ": " . $this->_ms->full_feedback_text());
            return false;
        }
    }


    /** @return string|false */
    function content() {
        $this->ensure_content();
        if ($this->content !== null) {
            return $this->content;
        } else if (($path = $this->content_file) !== null
                   || ($path = $this->filestore) !== null) {
            return @file_get_contents($path);
        } else {
            return false;
        }
    }

    /** @return string|false */
    function available_content_file() {
        if ($this->content_file !== null && is_readable($this->content_file)) {
            return $this->content_file;
        } else if ($this->filestore !== null && is_readable($this->filestore)) {
            return $this->filestore;
        } else {
            return false;
        }
    }

    /** @return string|false */
    function content_file() {
        $this->ensure_content();
        if (($path = $this->available_content_file())) {
            return $path;
        } else if ($this->content !== null) {
            if ($this->store_docstore()) {
                return $this->filestore;
            } else if (($f = $this->_temp_content_filename())) {
                $trylen = file_put_contents($f, $this->content);
                if ($trylen !== strlen($this->content)) {
                    clean_tempdirs();
                    $trylen = file_put_contents($f, $this->content);
                }
                if ($trylen === strlen($this->content)) {
                    return ($this->content_file = $f);
                }
            }
        }
        return false;
    }

    private function _temp_content_filename() {
        if (!Filer::$tempdir && !(Filer::$tempdir = tempdir())) {
            return false;
        }
        if ($this->has_hash()) {
            $base = $this->text_hash();
        } else {
            ++Filer::$tempcounter;
            $base = "__temp" . Filer::$tempcounter . "__";
        }
        return Filer::$tempdir . "/" . $base . Mimetype::extension($this->mimetype);
    }

    /** @return string */
    function content_text_signature() {
        $s = false;
        if ($this->content === null
            && ($path = $this->available_content_file())) {
            $s = file_get_contents($path, false, null, 0, 16);
        }
        if ($s === false) {
            $s = $this->content();
        }
        if ($s === false) {
            return "cannot be loaded";
        } else if ($s === "") {
            return "is empty";
        } else {
            $t = substr($s, 0, 8);
            if (!is_valid_utf8($s)) {
                $t = UnicodeHelper::utf8_prefix(UnicodeHelper::utf8_truncate_invalid($s), 8);
                if (strlen($t) < 7) {
                    $t = join("", array_map(function ($ch) {
                        $c = ord($ch);
                        if ($c >= 0x20 && $c <= 0x7E) {
                            return $ch;
                        } else {
                            return sprintf("\\x%02X", $c);
                        }
                    }, str_split(substr($s, 0, 8))));
                }
            }
            return "starts with “{$t}”";
        }
    }

    /** @return string */
    function content_mimetype() {
        $s = false;
        if ($this->content === null
            && ($path = $this->available_content_file())) {
            $s = file_get_contents($path, false, null, 0, 2048);
        }
        if ($s === false) {
            $s = $this->content();
        }
        return Mimetype::content_type($s, $this->mimetype);
    }


    /** @param iterable<DocumentInfo> $docs */
    static function prefetch_content($docs) {
        $pfdocs = [];
        foreach ($docs as $doc) {
            if ($doc->need_prefetch_content()) {
                $pfdocs[] = $doc;
            }
        }
        if (empty($pfdocs) || !function_exists("curl_multi_init")) {
            return;
        }

        $adocs = [];
        $curlm = curl_multi_init();
        $starttime = $stoptime = null;

        while (true) {
            // check time
            $time = microtime(true);
            if ($stoptime === null) {
                $starttime = $time;
                $stoptime = $time + 20 * max(ceil(count($pfdocs) / 8), 1);
                S3Client::$retry_timeout_allowance += 5 * count($pfdocs) / 4;
            }
            if ($time >= $stoptime) {
                break;
            }
            if ($time >= $starttime + 5) {
                set_time_limit(30);
            }

            // add documents to sliding window
            while (count($adocs) < 8 && !empty($pfdocs)) {
                $doc = array_pop($pfdocs);
                $s3 = $doc->conf->s3_docstore();
                if (($s3k = $doc->s3_key())
                    && ($dspath = Filer::docstore_path($doc, Filer::FPATH_MKDIR))
                    && ($stream = @fopen($dspath . "~", "x+b"))) {
                    $s3l = $s3->start_curl_get($s3k)->set_response_body_stream($stream)->set_expected_size($doc->size());
                    $adocs[] = [$doc, $s3l, 0, $dspath];
                }
            }
            if (empty($adocs)) {
                break;
            }

            // block if needed
            $mintime = $stoptime;
            foreach ($adocs as &$adoc) {
                if ($adoc[2] === 0 || $adoc[2] >= $time) {
                    $adoc[1]->prepare();
                    curl_multi_add_handle($curlm, $adoc[1]->curlh);
                    $adoc[2] = -1;
                }
                $mintime = min($mintime, $adoc[2]);
            }
            unset($adoc);
            if ($mintime > $time) {
                usleep((int) (($mintime - $time) * 1000000));
                S3Client::$retry_timeout_allowance -= $mintime - $time;
                continue;
            }

            // call multi_exec
            while (($mstat = curl_multi_exec($curlm, $mrunning)) === CURLM_CALL_MULTI_PERFORM) {
            }
            if ($mstat !== CURLM_OK) {
                break;
            }

            // handle results
            while (($minfo = curl_multi_info_read($curlm))) {
                $curlh = $minfo["handle"];
                for ($i = 0; $i < count($adocs) && $adocs[$i][1]->curlh !== $curlh; ++$i) {
                }
                $s3l = $adocs[$i][1];
                curl_multi_remove_handle($curlm, $s3l->curlh);
                if ($s3l->parse_result()) {
                    $adocs[$i][0]->handle_load_s3_curl($s3l, $adocs[$i][3]);
                    array_splice($adocs, $i, 1);
                } else {
                    $adocs[$i][2] = microtime(true) + 0.005 * (1 << $s3l->runindex);
                }
            }

            // maybe block
            if ($mrunning) {
                curl_multi_select($curlm, $stoptime - microtime(true));
            }
        }

        // clean up leftovers
        foreach ($adocs as $adoc) {
            $adoc[1]->status = null;
            $adoc[0]->handle_load_s3_curl($adoc[1], $adoc[3]);
        }
        curl_multi_close($curlm);
    }


    /** @return bool */
    function has_hash() {
        assert($this->sha1 !== null);
        return (bool) $this->sha1;
    }
    /** @return string|false */
    function text_hash() {
        return Filer::hash_as_text($this->binary_hash());
    }
    /** @return string|false */
    function binary_hash() {
        if ($this->sha1 === "") {
            $this->sha1 = $this->content_binary_hash();
        }
        return $this->sha1;
    }
    /** @return string|false */
    function binary_hash_data() {
        $hash = $this->binary_hash();
        if ($hash === false || strlen($hash) === 20) {
            return $hash;
        } else {
            return substr($hash, strpos($hash, "-") + 1);
        }
    }
    /** @return bool */
    function check_text_hash($hash) {
        $hash = $this->binary_hash();
        return $hash !== false && $hash === Filer::hash_as_binary($hash);
    }
    /** @return string|false */
    function hash_algorithm() {
        assert($this->has_hash());
        if (strlen($this->sha1) === 20) {
            return "sha1";
        } else if (substr($this->sha1, 0, 5) === "sha2-") {
            return "sha256";
        } else {
            return false;
        }
    }
    /** @return string|false */
    function hash_algorithm_prefix() {
        assert($this->has_hash());
        if (strlen($this->sha1) === 20) {
            return "";
        } else if (($dash = strpos($this->sha1, "-")) !== false) {
            return substr($this->sha1, 0, $dash + 1);
        } else {
            return false;
        }
    }
    /** @return HashAnalysis */
    private function hash_algorithm_for($like_hash) {
        $ha = null;
        if ($like_hash) {
            $ha = new HashAnalysis($like_hash);
        }
        if (!$ha || !$ha->known_algorithm()) {
            $ha = HashAnalysis::make_known_algorithm($this->conf->opt("contentHashMethod"));
        }
        return $ha;
    }
    /** @return string|false */
    function content_binary_hash($like_hash = null) {
        // never cached
        $ha = $this->hash_algorithm_for($like_hash);
        $this->ensure_content();
        if ($this->content !== null) {
            return $ha->prefix() . hash($ha->algorithm(), $this->content, true);
        } else if (($path = $this->available_content_file())
                   && ($h = hash_file($ha->algorithm(), $path, true)) !== false) {
            return $ha->prefix() . $h;
        } else {
            return false;
        }
    }
    /** @return string|false */
    function file_binary_hash($file, $like_hash = null) {
        $ha = $this->hash_algorithm_for($like_hash);
        if (($h = hash_file($ha->algorithm(), $file, true)) !== false) {
            return $ha->prefix() . $h;
        } else {
            return false;
        }
    }

    /** @return bool */
    function has_crc32() {
        return is_string($this->crc32) && $this->crc32 !== "";
    }
    /** @return string|false */
    function crc32() {
        if ($this->crc32 === null && $this->paperStorageId > 0 && $this->is_partial) {
            self::prefetch_crc32([$this]);
        }
        if ($this->crc32 === null || $this->crc32 === "") {
            $this->ensure_content();
            if ($this->content !== null) {
                $c = hash("crc32b", $this->content, true);
            } else if (($path = $this->available_content_file())) {
                $c = hash_file("crc32b", $path, true);
            } else {
                $c = false;
            }
            if ($c === false || strlen($c) === 4) {
                $this->crc32 = $c;
            } else {
                $this->crc32 = false;
                error_log("{$this->conf->dbname}: #{$this->paperId}/{$this->documentType}/{$this->paperStorageId}: funny CRC32 result");
            }
            if ($this->crc32 !== false && $this->paperStorageId > 0) {
                $this->conf->ql("update PaperStorage set crc32=? where paperId=? and paperStorageId=?", $this->crc32, $this->paperId, $this->paperStorageId);
            }
        } else if ($this->crc32 === "\0\0\0\0") {
            error_log("{$this->conf->dbname}: #{$this->paperId}/{$this->paperStorageId}: unlikely CRC32 00000000");
        }
        return $this->crc32;
    }
    /** @return int|false */
    function integer_crc32() {
        if (($s = $this->crc32()) !== false) {
            return (ord($s[0]) << 24) | (ord($s[1]) << 16) | (ord($s[2]) << 8) | ord($s[3]);
        } else {
            return false;
        }
    }

    /** @param iterable<DocumentInfo> $docs */
    static function prefetch_crc32($docs) {
        $need = [];
        foreach ($docs as $doc) {
            if ($doc->crc32 === null
                && $doc->is_partial
                && $doc->paperStorageId > 0
                && $doc->conf === Conf::$main) {
                $need[] = "(paperId=$doc->paperId and paperStorageId=$doc->paperStorageId)";
            }
        }
        if (!empty($need)) {
            $idmap = [];
            $result = Conf::$main->qe("select paperStorageId, crc32 from PaperStorage where " . join(" or ", $need));
            while (($row = $result->fetch_row())) {
                $idmap[(int) $row[0]] = $row[1] ?? "";
            }
            $need = [];
            foreach ($docs as $doc) {
                if (isset($idmap[$doc->paperStorageId])) {
                    $doc->crc32 = $idmap[$doc->paperStorageId];
                }
                if ($doc->crc32 === null || $doc->crc32 === "") {
                    $need[] = $doc;
                }
            }
            self::prefetch_content($need);
        }
    }


    const ANY_MEMBER_FILENAME = 1;

    /** @return ?string */
    function member_filename($flags = 0) {
        if (($this->_member_filename ?? "") !== "") {
            return $this->_member_filename;
        } else {
            assert(($flags & self::ANY_MEMBER_FILENAME) !== 0);
            return $this->filename;
        }
    }

    /** @param int $flags
     * @return string */
    function export_filename($filters = null, $flags = 0) {
        $fn = $this->conf->download_prefix;
        if ($this->documentType == DTYPE_SUBMISSION) {
            $fn .= "paper" . $this->paperId;
        } else if ($this->documentType == DTYPE_FINAL) {
            $fn .= "final" . $this->paperId;
        } else if ($this->documentType == DTYPE_COMMENT) {
            if (!$this->_owner) {
                $this->find_owner();
            } else if (!($this->_owner instanceof CommentInfo)) {
                throw new Exception("bad DocumentInfo::export_filename for comment");
            }
            assert(!$filters);
            if (!$this->_owner) {
                $cid = "commentX";
            } else if ($this->_owner->is_response()) {
                $cid = $this->_owner->unparse_html_id();
            } else {
                $cid = "comment-" . $this->_owner->unparse_html_id();
            }
            return "paper{$this->paperId}/{$cid}/" . $this->member_filename($flags);
        } else if ($this->documentType == DTYPE_EXPORT) {
            assert(!!$this->filename);
            return $this->filename;
        } else {
            $o = $this->conf->option_by_id($this->documentType);
            if ($o && $o->nonpaper && $this->paperId < 0) {
                $fn .= $o->dtype_name();
                $oabbr = "";
            } else {
                $fn .= "paper" . $this->paperId;
                $oabbr = $o ? "-" . $o->dtype_name() : "-unknown";
            }
            if ($o && $o->has_attachments()) {
                assert(!$filters);
                // do not decorate with MIME type suffix
                return "{$fn}{$oabbr}/" . $this->member_filename($flags);
            }
            $fn .= $oabbr;
        }
        $mimetype = $this->mimetype;
        if ($filters === null && isset($this->filters_applied)) {
            $filters = $this->filters_applied;
        }
        if ($filters) {
            foreach (is_array($filters) ? $filters : [$filters] as $filter) {
                if (is_string($filter)) {
                    $filter = FileFilter::find_by_name($this->conf, $filter);
                }
                if ($filter instanceof FileFilter) {
                    $fn .= "-" . $filter->name;
                    $mimetype = $filter->mimetype($this, $mimetype);
                }
            }
        }
        if ($mimetype) {
            if (($ext = Mimetype::extension($mimetype))) {
                $fn .= $ext;
            } else if ($this->filename
                       && preg_match('/(\.[A-Za-z0-9]{1,5})\z/', $this->filename, $m)
                       && (!$filters || $mimetype === $this->mimetype)) {
                $fn .= $m[1];
            }
        }
        return $fn;
    }

    /** @param string $suffix
     * @return string */
    function suffixed_export_filename($suffix, $filters = null) {
        $fn = $this->export_filename($filters);
        if (preg_match('/(\.[A-Za-z0-9]{1,5}(?:\.[A-Za-z0-9]{1,3})?)\z/', $fn, $m)) {
            return substr($fn, 0, -strlen($m[0])) . $suffix . $m[0];
        } else {
            return $fn . $suffix;
        }
    }

    const DOCURL_INCLUDE_TIME = 1024;

    /** @return string */
    function url($filters = null, $flags = 0) {
        if ($this->mimetype) {
            $f = ["file" => $this->export_filename($filters ?? $this->filters_applied)];
        } else {
            $f = ["p" => $this->paperId];
            if ($this->documentType == DTYPE_FINAL) {
                $f["final"] = 1;
            } else if ($this->documentType > 0) {
                $f["dt"] = $this->documentType;
            }
        }
        if ($flags & self::DOCURL_INCLUDE_TIME) {
            $f["at"] = $this->timestamp;
        }
        return $this->conf->hoturl("doc", $f, $flags);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    const L_FINALTITLE = 4;
    const L_REQUIREFORMAT = 8;
    function link_html($html = "", $flags = 0, $filters = null) {
        $p = $this->url($filters);
        $suffix = $info = "";
        $title = null;
        $small = ($flags & self::L_SMALL) != 0;

        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0
                && ($o = $this->conf->option_by_id($this->documentType))
                && $o->final)) {
            $suffix = "f";
        }
        if ($this->documentType == DTYPE_FINAL
            && ($flags & self::L_FINALTITLE)) {
            $title = "Final version";
        }

        assert(!($flags & self::L_REQUIREFORMAT) || !!$this->prow);
        $need_run = false;
        if (($this->documentType == DTYPE_SUBMISSION || $this->documentType == DTYPE_FINAL)
            && $this->prow !== null) {
            list($info, $suffix, $need_run) = $this->link_html_format_info($flags, $suffix);
        }

        if ($this->mimetype == "application/pdf") {
            list($img, $alt) = ["pdf", "[PDF]"];
        } else if ($this->mimetype == "application/postscript") {
            list($img, $alt) = ["postscript", "[PS]"];
        } else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype);
            $alt = "[" . ($m && $m->description ? $m->description : $this->mimetype) . "]";
        }

        $x = "<a href=\"{$p}\" class=\"q" . ($need_run ? " need-format-check" : "") . '">'
            . Ht::img($img . $suffix . ($small ? "" : "24") . ".png", $alt, ["class" => $small ? "sdlimg" : "dlimg", "title" => $title]);
        if ($html) {
            $x .= "&nbsp;" . $html;
        }
        if (!($flags & self::L_NOSIZE) && $this->size() > 0) {
            $x .= " <span class=\"dlsize\">" . ($html ? "(" : "")
                . unparse_byte_size($this->size) . ($html ? ")" : "") . "</span>";
        }
        return $x . "</a>" . ($info ? "&nbsp;$info" : "");
    }
    private function link_html_format_info($flags, $suffix) {
        $need_run = false;
        if (($spects = $this->conf->format_spec($this->documentType)->timestamp)) {
            if ($this->prow->is_primary_document($this)
                && ($flags & self::L_SMALL)) {
                $specstatus = $this->prow->pdfFormatStatus;
                if ($specstatus == -$spects) {
                    return ["", $suffix . "x", false];
                } else if ($specstatus == $spects) {
                    return ["", $suffix, false];
                }
            }
            $runflag = CheckFormat::RUN_NEVER;
            if ($flags & self::L_REQUIREFORMAT) {
                $runflag = CheckFormat::RUN_IF_NECESSARY;
            }
            $cf = new CheckFormat($this->conf, $runflag);
            $cf->check_document($this);
            if ($cf->has_problem()) {
                if ($cf->has_error()) {
                    $suffix .= "x";
                }
                if (($flags & self::L_SMALL) || !$cf->check_ok()) {
                    return ["", $suffix, $cf->need_recheck()];
                } else {
                    return ['<span class="need-tooltip" style="font-weight:bold" data-tooltip="' . htmlspecialchars($cf->full_feedback_html()) . '">ⓘ</span>', $suffix, $cf->need_recheck()];
                }
            } else {
                $need_run = $cf->need_recheck();
            }
        }
        return ["", $suffix, $need_run];
    }

    function metadata() {
        if ($this->is_partial && $this->infoJson === false) {
            $x = Dbl::fetch_value($this->conf->dblink, "select infoJson from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId) ? : "null";
            $this->infoJson = json_decode($x);
        }
        return $this->infoJson;
    }

    function update_metadata($delta, $quiet = false) {
        if ($this->paperStorageId <= 1) {
            return false;
        }
        $length_ok = true;
        $ijstr = Dbl::compare_and_swap($this->conf->dblink,
            "select infoJson from PaperStorage where paperId=? and paperStorageId=?",
            [$this->paperId, $this->paperStorageId],
            function ($old) use ($delta, &$length_ok) {
                $j = json_object_replace($old ? json_decode($old) : null, $delta, true);
                $new = $j ? json_encode($j) : null;
                $length_ok = $new === null || strlen($new) <= 32768;
                return $length_ok ? $new : $old;
            },
            "update PaperStorage set infoJson=?{desired} where paperId=? and paperStorageId=? and infoJson?{expected}e",
            [$this->paperId, $this->paperStorageId]);
        $this->infoJson = is_string($ijstr) ? json_decode($ijstr) : null;
        if (!$length_ok && !$quiet) {
            error_log(caller_landmark() . ": {$this->conf->dbname}: update_metadata(paper $this->paperId, dt $this->documentType): delta too long, delta " . json_encode($delta));
        }
        return $length_ok;
    }

    function is_archive() {
        return $this->filename
            && preg_match('/\.(?:zip|tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $this->filename);
    }
    function archive_listing($max_length = -1) {
        return ArchiveInfo::archive_listing($this, $max_length);
    }

    /** @param ?CheckFormat $cf
     * @return ?int */
    function npages(CheckFormat $cf = null) {
        if ($this->mimetype && $this->mimetype !== "application/pdf") {
            return null;
        } else if (($m = $this->metadata()) && isset($m->npages)) {
            return $m->npages;
        } else if ($this->content_file()) {
            $cf = $cf ?? new CheckFormat($this->conf);
            $cf->check_document($this);
            return $cf->npages;
        } else {
            return null;
        }
    }

    /** @param array{attachment?:bool,no_accel?:bool,cacheable?:bool} $opts
     * @return bool */
    function download($opts = []) {
        if ($this->size == 0 && !$this->ensure_size()) {
            $this->message_set()->warning_at(null, "<0>Empty file");
            return false;
        }

        if (isset($opts["if-none-match"])
            && $this->has_hash()
            && $opts["if-none-match"] === "\"" . $this->text_hash() . "\"") {
            header("HTTP/1.1 304 Not Modified");
            return true;
        }

        $no_accel = $opts["no_accel"] ?? false;
        $s3_accel = $no_accel ? false : $this->s3_accel_redirect();
        if (!$s3_accel && !$this->ensure_content()) {
            if (!$this->has_error()) {
                $this->error("Document cannot be prepared for download");
            }
            return false;
        }

        // Print headers
        $mimetype = Mimetype::type_with_charset($this->mimetype);
        if (isset($opts["attachment"])) {
            $attachment = $opts["attachment"];
        } else {
            $attachment = !Mimetype::disposition_inline($this->mimetype);
        }
        $downloadname = $this->export_filename();
        if (($slash = strrpos($downloadname, "/")) !== false) {
            $downloadname = substr($downloadname, $slash + 1);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        if ($opts["cacheable"] ?? false) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 315576000) . " GMT");
        }
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
        if ($this->has_hash()) {
            $opts["etag"] = "\"" . $this->text_hash() . "\"";
        }

        // Download or redirect
        if ($s3_accel) {
            header("Content-Type: $mimetype");
            header("ETag: " . $opts["etag"]);
            $this->conf->s3_docstore()->get_accel_redirect($this->s3_key(), $s3_accel);
        } else if (($path = $this->available_content_file())) {
            Filer::download_file($path, $mimetype, $opts);
        } else {
            Filer::download_string($this->content, $mimetype, $opts);
        }
        return true;
    }

    function unparse_json() {
        $x = [];
        if ($this->filename) {
            $x["filename"] = $this->filename;
        }
        if ($this->_member_filename !== null
            && $this->_member_filename !== $this->filename) {
            $x["unique_filename"] = $this->_member_filename;
        }
        if ($this->mimetype) {
            $x["mimetype"] = $this->mimetype;
        }
        if ($this->size) {
            $x["size"] = $this->size;
        }
        if ($this->has_hash()) {
            $x["hash"] = $this->text_hash();
        }
        $x["siteurl"] = $this->url(null, Conf::HOTURL_RAW | Conf::HOTURL_SITEREL);
        return (object) $x;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($k === "content" && is_string($v) && strlen($v) > 50) {
                $x[$k] = substr($v, 0, 50) . "…";
            } else if ($k === "sha1" && is_string($v)) {
                $x[$k] = Filer::hash_as_text($v);
            } else if ($v !== null && $k !== "conf" && $k !== "prow" && $k[0] !== "_") {
                $x[$k] = $v;
            }
        }
        return $x;
    }

    static function log_download_activity($docs, Contact $user) {
        if ($user->is_anonymous_user()) {
            return;
        }
        $byn = [];
        $any_nonauthor = false;
        foreach ($docs as $doc) {
            if ($doc->documentType !== DTYPE_COMMENT
                && $doc->conf === $user->conf
                && $doc->paperId > 0
                && $doc->paperStorageId > 1) {
                // XXX ignores documents from other conferences
                $byn[$doc->documentType][] = $doc->paperId;
                $any_nonauthor = $any_nonauthor || !$doc->prow || !$doc->prow->has_author($user);
            }
        }
        if ($any_nonauthor) {
            foreach ($byn as $dtype => $pidm) {
                $opt = $user->conf->option_by_id($dtype);
                $name = $opt ? $opt->json_key() : "opt{$dtype}";
                $user->log_activity_dedup("Download $name", array_values(array_unique($pidm)));
            }
        }
    }

    static function active_document_map(Conf $conf) {
        $q = ["select paperStorageId from Paper where paperStorageId>1",
            "select finalPaperStorageId from Paper where finalPaperStorageId>1",
            "select documentId from DocumentLink where documentId>1"];
        $document_option_ids = array();
        foreach ($conf->options()->universal() as $id => $o) {
            if ($o->has_document())
                $document_option_ids[] = $id;
        }
        if (!empty($document_option_ids)) {
            $q[] = "select value from PaperOption where optionId in ("
                . join(",", $document_option_ids) . ") and value>1";
        }

        $result = $conf->qe_raw(join(" UNION ", $q));
        $ids = [];
        while (($row = $result->fetch_row())) {
            $ids[(int) $row[0]] = true;
        }
        Dbl::free($result);
        return $ids;
    }
}
