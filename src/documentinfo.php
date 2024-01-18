<?php
// documentinfo.php -- HotCRP document objects
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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
    /** @var int */
    public $timestamp;
    /** @var string */
    public $mimetype;
    /** @var ?string */
    private $paper;    // translated to `content` on load
    public $compression; // 0: uncompressed, 1: gz, -1: skeleton
    /** @var string|false */
    public $sha1 = ""; // binary hash; empty = unhashed, false = not available
    /** @var ?string|false */
    private $crc32;    // binary hash
    /** @var ?int */
    public $documentType;
    /** @var ?string */
    public $filename;
    /** @var ?string|false */
    private $infoJson;
    /** @var int */
    private $size = -1;
    /** @var ?int */
    public $filterType;
    /** @var ?int */
    public $originalStorageId;
    /** @var int */
    public $inactive = 0;
    /** @var ?int */
    private $npages;
    /** @var ?int */
    private $width;
    /** @var ?int */
    private $height;

    /** @var ?string */
    private $content;
    /** @var ?string */
    private $content_file;
    /** @var ?string */
    private $filestore;
    /** @var bool */
    private $_prefer_s3 = false;
    /** @var ?string */
    private $_content_prefix;

    /** @var ?object */
    private $_metadata;
    /** @var ?CommentInfo */
    private $_owner;
    /** @var ?string */
    public $sourceHash;
    /** @var ?list<FileFilter> */
    public $filters_applied;
    /** @var ?string */
    private $_member_filename;
    /** @var ?MessageSet */
    private $_ms;
    /** @var ?array */
    private $_old_prop;

    const LINKTYPE_COMMENT_BEGIN = 0;
    const LINKTYPE_COMMENT_END = 1024;

    const FLAG_NO_DOCSTORE = 1;

    /** @param array{paperId?:int,paperStorageId?:int,timestamp?:int,mimetype?:string,content?:string,content_base64?:string,content_file?:string,hash?:?string,crc32?:?string,documentType?:int,filename?:?string,metadata?:array|object,infoJson?:?string,size?:int,filterType?:?int,originalStorageId?:?int,sourceHash?:?string,filters_applied?:?list<FileFilter>,sha1?:void,filestore?:void} $p */
    function __construct($p, Conf $conf, PaperInfo $prow = null) {
        $this->conf = $conf;
        $this->prow = $prow;
        if ($p !== null) {
            $this->assign($p);
        }
    }

    private function assign($p) {
        $this->paperId = $p["paperId"] ?? 0;
        $this->paperStorageId = $p["paperStorageId"] ?? 0;
        $this->timestamp = $p["timestamp"] ?? 0;
        $this->mimetype = $p["mimetype"] ?? "";
        if (array_key_exists("content", $p)) {
            $this->content = $p["content"];
        } else if (isset($p["content_base64"])) {
            $this->content = base64_decode($p["content_base64"]);
        } else if (isset($p["content_file"])) {
            $this->content_file = $p["content_file"];
        }
        if (($p["hash"] ?? "") !== "") {
            $this->sha1 = HashAnalysis::hash_as_binary($p["hash"]);
        }
        if (($p["crc32"] ?? "") !== "") {
            $this->crc32 = $p["crc32"];
            if (strlen($this->crc32) === 8 && ctype_xdigit($this->crc32)) {
                $this->crc32 = hex2bin($this->crc32);
            } else if (strlen($this->crc32) === 4) {
                $this->crc32 = null;
            }
        }
        $this->documentType = $p["documentType"] ?? 0;
        $this->filename = $p["filename"] ?? null;
        if (isset($p["metadata"])) {
            if (is_object($p["metadata"])) {
                $this->_metadata = $p["metadata"];
            } else if (is_array($p["metadata"])) {
                $this->_metadata = array_to_object_recursive($p["metadata"]);
            } else {
                assert(false);
            }
        } else if (array_key_exists("infoJson", $p)) {
            $this->infoJson = $p["infoJson"];
            assert(is_string($this->infoJson ?? ""));
        } else if ($this->paperStorageId > 0) {
            $this->infoJson = false;
        }
        $this->size = $p["size"] ?? -1;
        $this->filterType = $p["filterType"] ?? null;
        $this->originalStorageId = $p["originalStorageId"] ?? null;
        if (isset($p["sourceHash"])) {
            $this->sourceHash = HashAnalysis::hash_as_binary($p["sourceHash"]);
        }
        $this->filters_applied = $p["filters_applied"] ?? null;

        assert(!isset($p["sha1"]));
        assert($this->sha1 === false || is_string($this->sha1));
    }

    private function fetch_incorporate() {
        $this->paperId = (int) $this->paperId;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->timestamp = (int) $this->timestamp;
        $this->documentType = (int) $this->documentType;
        $this->size = (int) $this->size;
        $this->filterType = (int) $this->filterType ? : null;
        $this->originalStorageId = (int) $this->originalStorageId ? : null;
        $this->inactive = (int) $this->inactive;
        $this->content = $this->content ?? $this->paper;
        $this->paper = null;
        if (isset($this->npages)) {
            $this->npages = (int) $this->npages;
        }
        if (isset($this->width)) {
            $this->width = (int) $this->width;
        }
        if (isset($this->height)) {
            $this->height = (int) $this->height;
        }
    }

    /** @param mysqli_result|Dbl_Result $result
     * @return ?DocumentInfo */
    static function fetch($result, Conf $conf, PaperInfo $prow = null) {
        if (($doc = $result->fetch_object("DocumentInfo", [null, $conf, $prow]))) {
            $doc->fetch_incorporate();
        }
        return $doc;
    }


    /** @return DocumentInfo */
    static function make(Conf $conf) {
        return new DocumentInfo(null, $conf);
    }

    /** @return DocumentInfo */
    static function make_empty(Conf $conf, PaperInfo $prow = null) {
        // matches paperStorageId 1 in schema
        $doc = new DocumentInfo(null, $conf, $prow);
        $doc->paperStorageId = 1;
        $doc->documentType = 0;
        $doc->mimetype = "text/plain";
        $doc->content = "";
        $doc->sha1 = "\xda\x39\xa3\xee\x5e\x6b\x4b\x0d\x32\x55\xbf\xef\x95\x60\x18\x90\xaf\xd8\x07\x09";
        $doc->size = 0;
        return $doc;
    }

    /** @param string $content
     * @param ?string $mimetype
     * @return DocumentInfo */
    static function make_content(Conf $conf, $content, $mimetype = null) {
        $doc = new DocumentInfo(null, $conf);
        $doc->mimetype = $mimetype;
        $doc->content = $content;
        $doc->size = strlen($content);
        return $doc->analyze_content();
    }

    /** @param string $content_file
     * @param ?string $mimetype
     * @return DocumentInfo */
    static function make_content_file(Conf $conf, $content_file, $mimetype = null) {
        $doc = new DocumentInfo(null, $conf);
        $doc->mimetype = $mimetype;
        $doc->content_file = $content_file;
        return $doc->analyze_content();
    }

    /** @param string $hash
     * @param ?string $mimetype
     * @return DocumentInfo */
    static function make_hash(Conf $conf, $hash, $mimetype = null) {
        $doc = new DocumentInfo(null, $conf);
        $doc->sha1 = HashAnalysis::hash_as_binary($hash);
        $doc->mimetype = $mimetype;
        return $doc;
    }

    /** @param QrequestFile $upload
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_uploaded_file($upload, $paperId, $documentType, Conf $conf) {
        if (!$upload) {
            return null;
        }

        $doc = new DocumentInfo(null, $conf);
        $doc->paperId = $paperId;
        $doc->documentType = $documentType;
        $doc->timestamp = time();
        $doc->mimetype = $upload->type;
        $doc->filename = self::sanitize_filename($upload->name);

        $upload_error = "";
        if ($upload->content !== null) {
            $doc->content = $upload->content;
            $doc->size = strlen($upload->content);
        } else if ($upload->tmp_name !== null && is_readable($upload->tmp_name)) {
            $sz = filesize($upload->tmp_name);
            if ($sz !== false && $sz > 0) {
                $doc->content_file = $upload->tmp_name;
                $doc->size = $sz;
            } else {
                $upload_error = " was empty, not saving";
            }
        } else {
            $upload_error = " could not be read";
        }

        $doc->analyze_content();
        if ($upload_error) {
            $doc->error("<0>Uploaded file" . ($doc->filename === "" ? "" : " ‘{$doc->filename}’") . $upload_error);
        }
        return $doc;
    }

    /** @param string $token
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_capability(Conf $conf, $token, $paperId, $documentType) {
        if (!$token
            || !($toki = TokenInfo::find($token, $conf))
            || !$toki->is_active()
            || $toki->capabilityType !== TokenInfo::UPLOAD) {
            return null;
        }
        return self::make_token($conf, $toki, null, $paperId, $documentType);
    }

    /** @param ?string $content_file
     * @param ?int $paperId
     * @param ?int $documentType
     * @return ?DocumentInfo */
    static function make_token(Conf $conf, TokenInfo $toki, $content_file = null,
                               $paperId = null, $documentType = null) {
        assert($toki->capabilityType === TokenInfo::UPLOAD);
        $tokd = json_decode($toki->data);
        if (!$tokd->hash) {
            return null;
        }
        $doc = new DocumentInfo(null, $conf);
        $doc->paperId = $paperId ?? $toki->paperId;
        $doc->documentType = $documentType ?? $tokd->dtype;
        $doc->timestamp = time();
        $doc->mimetype = $tokd->mimetype ?? null;
        $doc->filename = self::sanitize_filename($tokd->filename);
        $doc->size = $tokd->size;
        $doc->sha1 = HashAnalysis::hash_as_binary($tokd->hash);
        if (ctype_xdigit($tokd->crc32) && strlen($tokd->crc32) === 8) {
            $doc->crc32 = hex2bin($tokd->crc32);
        }
        if ($content_file !== null) {
            $doc->content_file = $content_file;
        }
        if ($doc->content_available() || $doc->load_docstore()) {
            $doc->analyze_content();
        }
        $doc->_prefer_s3 = !!($tokd->s3_status ?? false);
        return $doc;
    }

    /** @param 0|-1 $dtype
     * @param ?int $size
     * @return DocumentInfo */
    static function make_primary_document(PaperInfo $prow, $dtype, $size) {
        $doc = new DocumentInfo(null, $prow->conf, $prow);
        $doc->paperStorageId = $dtype < 0 ? $prow->finalPaperStorageId : $prow->paperStorageId;
        $doc->paperId = $prow->paperId;
        $doc->documentType = $dtype;
        $doc->timestamp = (int) $prow->timestamp;
        $doc->mimetype = $prow->mimetype;
        $doc->sha1 = $prow->sha1;
        $doc->size = $size ?? -1;
        $doc->compression = -1;
        $doc->infoJson = false; // metadata is not loaded
        return $doc;
    }

    /** @param string $name
     * @param int $paperId
     * @param int $documentType
     * @return ?DocumentInfo */
    static function make_request(Qrequest $qreq, $name, $paperId,
                                 $documentType, Conf $conf) {
        if (($fu = $qreq["{$name}:upload"])) {
            return self::make_capability($conf, $fu, $paperId, $documentType);
        } if (($fi = $qreq->file("{$name}:file") ?? $qreq->file($name) /* XXX obsolete */)) {
            return self::make_uploaded_file($fi, $paperId, $documentType, $conf);
        } else {
            return null;
        }
    }

    /** @param FileFilter $ff
     * @return DocumentInfo */
    function make_filtered(FileFilter $ff) {
        $doc = new DocumentInfo(null, $this->conf, $this->prow);
        $doc->paperId = $this->paperId;
        $doc->timestamp = $this->timestamp;
        $doc->mimetype = $this->mimetype;
        $doc->documentType = $this->documentType;
        $doc->filename = $this->filename;
        $doc->filterType = $ff->id;
        $doc->originalStorageId = $this->paperStorageId;
        $doc->sourceHash = $this->binary_hash();
        $doc->filters_applied = $this->filters_applied ?? [];
        $doc->filters_applied[] = $ff;
        return $doc;
    }


    /** @param string $name
     * @return bool */
    static function has_request_for(Qrequest $qreq, $name) {
        return $qreq["{$name}:upload"]
            || $qreq->has_file("{$name}:file")
            || $qreq->has_file($name) /* XXX obsolete */;
    }

    /** @return bool */
    static function check_json_upload($j) {
        return is_object($j)
            && (!isset($j->hash) || is_string($j->hash))
            && (!isset($j->content) || is_string($j->content))
            && (!isset($j->content_base64) || is_string($j->content_base64))
            && (!isset($j->content_file) || is_string($j->content_file));
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


    /** @param int $pid
     * @return $this */
    function set_paper_id($pid) {
        assert($this->paperStorageId <= 0);
        $this->paperId = $pid;
        return $this;
    }

    /** @return $this */
    function set_paper(PaperInfo $prow) {
        assert($this->paperStorageId <= 0 && $this->conf === $prow->conf);
        $this->prow = $prow;
        $this->paperId = $prow->paperId;
        return $this;
    }

    /** @param int $dtype
     * @return $this */
    function set_document_type($dtype) {
        assert($this->paperStorageId <= 0);
        $this->documentType = $dtype;
        return $this;
    }

    /** @param string $content
     * @return $this */
    function set_simple_content($content) {
        assert($this->paperStorageId <= 0);
        $this->content = $content;
        $this->content_file = $this->filestore = $this->_content_prefix = null;
        $this->size = strlen($content);
        $this->sha1 = $this->crc32 = "";
        return $this;
    }

    /** @param string $content_file
     * @return $this */
    function set_simple_content_file($content_file) {
        assert($this->paperStorageId <= 0);
        $this->content_file = $content_file;
        $this->content = $this->filestore = $this->_content_prefix = null;
        $this->size = -1;
        $this->sha1 = $this->crc32 = "";
        return $this;
    }

    /** @param string $content
     * @param ?string $mimetype
     * @return $this */
    function set_content($content, $mimetype = null) {
        $this->set_simple_content($content);
        $this->mimetype = $mimetype ?? $this->mimetype;
        return $this->analyze_content();
    }

    /** @param string $content_file
     * @param ?string $mimetype
     * @return $this */
    function set_content_file($content_file, $mimetype = null) {
        $this->set_simple_content_file($content_file);
        $this->mimetype = $mimetype ?? $this->mimetype;
        return $this->analyze_content();
    }

    /** @param ?string $hash
     * @return $this */
    function set_hash($hash) {
        assert($this->paperStorageId <= 0);
        $this->sha1 = $hash;
        return $this;
    }

    /** @param ?string $crc32
     * @return $this */
    function set_crc32($crc32) {
        assert($this->paperStorageId <= 0);
        $this->crc32 = $crc32;
        return $this;
    }

    /** @param int $size
     * @return $this */
    function set_size($size) {
        assert($this->paperStorageId <= 0);
        $this->size = $size;
        return $this;
    }

    /** @param int $timestamp
     * @return $this */
    function set_timestamp($timestamp) {
        assert($this->paperStorageId <= 0);
        $this->timestamp = $timestamp;
        return $this;
    }

    /** @param ?string $mimetype
     * @return $this */
    function set_mimetype($mimetype) {
        assert($this->paperStorageId <= 0);
        $this->mimetype = $mimetype;
        return $this;
    }

    /** @param string $filename
     * @return $this */
    function set_filename($filename) {
        assert($this->paperStorageId <= 0);
        $this->filename = $filename;
        return $this;
    }

    /** @param string $key
     * @param mixed $value
     * @return $this */
    function set_metadata($key, $value) {
        assert($this->paperStorageId <= 0 && $this->infoJson === null);
        $this->_metadata = $this->_metadata ?? (object) [];
        $this->_metadata->$key = $value;
        return $this;
    }

    /** @return $this */
    function analyze_content() {
        if (($info = Mimetype::content_info(null, $this->mimetype, $this))) {
            $this->mimetype = $info["type"];
            if (isset($info["width"])) {
                $this->set_prop("width", $info["width"]);
            }
            if (isset($info["height"])) {
                $this->set_prop("height", $info["height"]);
            }
            if (str_starts_with($this->mimetype, "video/")
                || str_starts_with($this->mimetype, "audio/")) {
                if (isset($info["duration"])) {
                    $this->set_prop("npages", (int) ($info["duration"] * 10 + 0.5));
                }
            }
        }
        return $this;
    }


    /** @param ?CommentInfo $owner
     * @return DocumentInfo */
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

    /** @return bool */
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

    function release_redundant_content() {
        if ($this->filestore !== null) {
            $this->content = null;
        }
    }


    /** @return bool */
    function content_available() {
        return $this->content !== null
            || $this->content_file !== null
            || $this->filestore !== null;
    }

    /** @return bool */
    function ensure_content() {
        // 1. check docstore
        // 2. check db
        // 3. check S3
        // 4. check db as last resort
        if ($this->content_available()
            || $this->load_docstore()
            || $this->load_database()
            || $this->load_s3()
            || $this->load_database(true)) {
            return true;
        }
        // not found
        $this->error("<0>Cannot load document");
        return false;
    }

    /** @return int */
    function content_size() {
        $sz = -1;
        if ($this->ensure_content()) {
            if ($this->content !== null) {
                $sz = strlen($this->content);
            } else if ($this->content_file !== null) {
                $sz = filesize($this->content_file);
            } else if ($this->filestore !== null) {
                $sz = filesize($this->filestore);
            }
        }
        return $sz !== false ? $sz : -1;
    }

    const SIZE_NO_CONTENT = 1;
    /** @param int $flags
     * @return int */
    function size($flags = 0) {
        if ($this->size < 0) {
            $this->load_size($flags);
        }
        return $this->size;
    }

    private function load_size($flags) {
        if (!$this->content_available()
            && !$this->load_docstore()
            && !$this->load_database()
            && ($s3 = $this->conf->s3_client())
            && $this->has_hash()
            && ($s3k = $this->s3_key())) {
            // NB This function may be called from `load_s3()`!
            // Avoid a recursive call to `load_s3()` via `head_size()`
            $this->size = $s3->head_size($s3k);
        }
        if ($this->size < 0 && ($flags & self::SIZE_NO_CONTENT) === 0) {
            $this->size = $this->content_size();
        }
        if ($this->size >= 0 && $this->paperStorageId > 1) {
            $this->conf->qe("update PaperStorage set size=? where paperId=? and paperStorageId=? and size<=0", $this->size, $this->paperId, $this->paperStorageId);
        }
    }

    /** @param string $fn
     * @param int $expected_size
     * @return int */
    static function filesize_expected($fn, $expected_size) {
        $sz = @filesize($fn);
        if ($sz !== $expected_size && $sz !== false) {
            clearstatcache(true, $fn);
            $sz = @filesize($fn);
        }
        return $sz === false ? -2 : $sz;
    }


    /** @return bool */
    function compressible() {
        $sz = $this->size();
        return $sz > 0 && $sz <= 10000000 && Mimetype::compressible($this->mimetype);
    }

    /** @param bool $ignore_no_papers
     * @return bool */
    private function load_database($ignore_no_papers = false) {
        if ($this->paperStorageId <= 1
            || (!$ignore_no_papers && $this->conf->opt("dbNoPapers"))) {
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
    private function load_docstore() {
        if (!$this->has_hash()
            || !($path = Filer::docstore_path($this, Filer::FPATH_EXISTS))) {
            return false;
        }
        if ($this->size > 0) {
            $sz = self::filesize_expected($path, $this->size);
            if ($sz !== $this->size
                && ($s3 = $this->conf->s3_client())
                && ($s3k = $this->s3_key())
                && $s3->head_size($s3k) === $this->size) {
                unlink($path);
                return false;
            } else if ($sz !== $this->size) {
                error_log("{$this->conf->dbname}: #{$this->paperId}/{$this->documentType}/{$this->paperStorageId}: bad size {$sz}, expected {$this->size}");
            }
        }
        $this->filestore = $path;
        return true;
    }

    /** @return bool */
    function need_prefetch_content() {
        return $this->content === null
            && $this->content_file === null
            && $this->filestore === null
            && !$this->load_docstore()
            && $this->conf->s3_client();
    }

    /** @return bool */
    function store_skeleton() {
        if (!$this->timestamp) {
            $this->timestamp = time();
        }
        $upd = [
            "paperId" => $this->paperId,
            "sha1" => $this->binary_hash(),
            "timestamp" => $this->timestamp,
            "size" => $this->size(),
            "mimetype" => $this->mimetype,
            "documentType" => $this->documentType,
            "inactive" => 0
        ];
        if (($this->crc32 || ($this->size >= 0 && $this->size <= 10000000))
            && ($crc32 = $this->crc32()) !== false) {
            $upd["crc32"] = $crc32;
        }
        foreach (["filename", "filterType", "originalStorageId"] as $k) {
            if ($this->$k)
                $upd[$k] = $this->$k;
        }
        if ($this->conf->sversion >= 276) {
            foreach (["npages", "width", "height"] as $k) {
                if (($this->$k ?? -1) >= 0)
                    $upd[$k] = $this->$k;
            }
        }
        if ($this->_metadata) {
            $upd["infoJson"] = json_encode_db($this->_metadata);
        } else if ($this->infoJson) {
            $upd["infoJson"] = $this->infoJson;
        }
        if (($upd["infoJson"] ?? "") === "{}") {
            unset($upd["infoJson"]);
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
            $this->_old_prop = null;
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
        if ($this->conf->opt("dbNoPapers")) {
            return null;
        }
        $content = $this->content();
        for ($p = 0; $p < strlen($content); $p += 400000) {
            $result = $this->conf->qe("update PaperStorage set paper=concat(coalesce(paper,''),?) where paperId=? and paperStorageId=?", substr($content, $p, 400000), $this->paperId, $this->paperStorageId);
            if (Dbl::is_error($result)) {
                break;
            }
            Dbl::free($result);
        }
        $ssize = $this->conf->fetch_ivalue("select length(paper) from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId);
        $ok = $ssize === strlen($content);
        if (!$ok) {
            $this->message_set()->warning_at(".content", "<0>Internal error while saving document content to database");
        }
        return $ok;
    }

    /** @param int $savef
     * @return ?bool */
    private function store_docstore($savef) {
        $dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR);
        if (!$dspath) {
            return null;
        }
        if (file_exists($dspath)
            && (($savef & self::SAVEF_SKIP_VERIFY) !== 0
                || $this->file_binary_hash($dspath, $this->binary_hash()) === $this->binary_hash())) {
            $this->filestore = $dspath;
            return true;
        }
        if ($this->content_file !== null
            && copy($this->content_file, $dspath)
            && @filesize($dspath) === @filesize($this->content_file)) {
            $ok = true;
        } else {
            $content = $this->content();
            if (file_put_contents("{$dspath}~", $content) === strlen($content)
                && rename("{$dspath}~", $dspath))
                $ok = true;
            else {
                @unlink("{$dspath}~");
                $ok = false;
            }
        }
        if ($ok) {
            $this->filestore = $dspath;
            @chmod($dspath, 0660 & ~umask());
        } else {
            @unlink($dspath);
            $this->message_set()->warning_at(".content", "<0>Internal error while saving document to file system");
        }
        return $ok;
    }

    /** @param string $text_hash
     * @param string|Mimetype $mimetype
     * @return non-empty-string */
    static function s3_key_for($text_hash, $mimetype) {
        // Format: `doc/%[2/3]H/%h%x`. Why not algorithm in subdirectory?
        // Because S3 works better if keys are partitionable.
        if (strlen($text_hash) === 40) {
            $x = substr($text_hash, 0, 2);
        } else {
            $x = substr($text_hash, strpos($text_hash, "-") + 1, 3);
        }
        return "doc/{$x}/{$text_hash}" . Mimetype::extension($mimetype);
    }

    /** @return ?non-empty-string */
    function s3_key() {
        if (($hash = $this->text_hash()) === false) {
            return null;
        }
        return self::s3_key_for($hash, $this->mimetype);
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
            && $this->conf->s3_client()
            && $this->s3_key()
            && ($s3ap = $this->conf->opt("s3AccelRedirect"))) {
            return $s3ap;
        } else {
            return false;
        }
    }

    /** @param string $dspath
     * @return ?resource */
    static private function fopen_docstore($dspath) {
        if (!$dspath) {
            return null;
        }
        $stream = @fopen("{$dspath}~", "x+b");
        if ($stream === false
            && @filemtime("{$dspath}~") < Conf::$now - 3600
            && @unlink("{$dspath}~")) {
            $stream = @fopen("{$dspath}~", "x+b");
        }
        return $stream ? : null;
    }

    /** @return bool */
    private function load_s3() {
        if (!($s3 = $this->conf->s3_client())
            || !$this->has_hash()
            || !($s3k = $this->s3_key())) {
            return false;
        }
        $dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR);
        if (function_exists("curl_init")) {
            $stream = $dspath ? self::fopen_docstore($dspath) : null;
            $s3l = $s3->start_curl_get($s3k)
                ->set_response_body_stream($stream)
                ->set_timeout_size($this->size(self::SIZE_NO_CONTENT));
            $s3l->run();
            return $this->handle_load_s3_curl($s3l, $stream ? $dspath : null);
        } else {
            return $this->load_s3_direct($s3, $s3k, $dspath);
        }
    }

    /** @param CurlS3Result $s3l
     * @param ?string $dspath
     * @return bool */
    private function handle_load_s3_curl($s3l, $dspath) {
        if ($s3l->status === 404
            && $this->s3_upgrade_extension($s3l->s3, $s3l->skey)) {
            $s3l->reset()->run();
        }
        if ($s3l->status !== 200) {
            error_log("S3 error: GET {$s3l->skey}: {$s3l->status} {$s3l->status_text} " . json_encode_db($s3l->response_headers));
            $s3l->close_response_body_stream();
            if ($dspath) {
                @unlink("{$dspath}~");
            }
            return false;
        }
        if (!$dspath) {
            $this->content = $s3l->response_body();
            $s3l->close_response_body_stream();
            return true;
        }
        $s3l->close_response_body_stream();
        $sz = self::filesize_expected("{$dspath}~", $this->size);
        if ($sz !== $this->size) {
            error_log("Disk error: GET {$s3l->skey}: expected size {$this->size}, got " . json_encode($sz));
            $s3l->status = 500;
        } else if (rename("{$dspath}~", $dspath)) {
            $this->filestore = $dspath;
            return true;
        } else {
            $this->content = file_get_contents("{$dspath}~");
        }
        @unlink("{$dspath}~");
        return $s3l->status === 200;
    }

    /** @param S3Client $s3
     * @param string $s3k
     * @param ?string $dspath
     * @return bool */
    private function load_s3_direct($s3, $s3k, $dspath) {
        $r = $s3->start_get($s3k)->run();
        if ($r->status === 404
            && $this->s3_upgrade_extension($s3, $s3k)) {
            $r = $s3->start_get($s3k)->run();
        }
        if ($r->status !== 200) {
            error_log("S3 error: GET {$s3k}: {$r->status} {$r->status_text} " . json_encode_db($r->response_headers));
            return false;
        }
        $b = $r->response_body();
        if (($b ?? "") === "") {
            return false;
        }
        if ($dspath && file_put_contents($dspath, $b) === $this->size) {
            $this->filestore = $dspath;
        } else {
            $this->content = $b;
        }
        return true;
    }

    /** @return bool */
    function check_s3() {
        return ($s3 = $this->conf->s3_client())
            && ($s3k = $this->s3_key())
            && ($s3->head($s3k)
                || ($this->s3_upgrade_extension($s3, $s3k) && $s3->head($s3k)));
    }

    /** @return array<string,string> */
    function s3_user_data() {
        $meta = [
            "conf" => $this->conf->dbname,
            "pid" => $this->paperId,
            "dtype" => $this->documentType
        ];
        if ($this->filterType) {
            $meta["filtertype"] = $this->filterType;
            if ($this->sourceHash != "") {
                $meta["sourcehash"] = HashAnalysis::hash_as_text($this->sourceHash);
            }
        }
        if (($this->npages ?? -1) >= 0) {
            $meta["npages"] = $this->npages;
        }
        if (($this->width ?? -1) >= 0) {
            $meta["width"] = $this->width;
        }
        if (($this->height ?? -1) >= 0) {
            $meta["height"] = $this->height;
        }
        return ["hotcrp" => json_encode_db($meta)];
    }

    /** @return ?bool */
    function store_s3() {
        if (!($s3 = $this->conf->s3_client())
            || !($s3k = $this->s3_key())) {
            return null;
        }

        if ($s3->head_size($s3k) === $this->size()) {
            return true;
        }

        $user_data = $this->s3_user_data();
        if (($path = $this->available_content_file())
            && $s3->put_file($s3k, $path, $this->mimetype, $user_data)) {
            return true;
        }

        $r = $s3->start_put($s3k, $this->content(), $this->mimetype, $user_data)->run();
        if ($r->status === 200) {
            return true;
        } else {
            error_log("S3 error: POST {$s3k}: {$r->status} {$r->status_text} " . json_encode_db($r->response_headers));
            $this->message_set()->warning_at(".content", "<0>Internal error while saving document to S3");
            return false;
        }
    }


    const SAVEF_SKIP_VERIFY = 1;
    const SAVEF_SKIP_CONTENT = 2;

    /** @param int $savef
     * @return bool */
    function save($savef = 0) {
        // look for an existing document with same sha1
        if ($this->binary_hash() !== false && $this->paperId != 0) {
            $row = $this->conf->fetch_first_row("select paperStorageId, timestamp, inactive, filename, mimetype, infoJson from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $this->documentType, $this->binary_hash());
            if ($row
                && (!isset($this->filename) || $row[3] === $this->filename)
                && (!isset($this->mimetype) || $row[4] === $this->mimetype)) {
                $this->paperStorageId = (int) $row[0];
                $this->timestamp = (int) $row[1];
                $qf = $qv = [];
                if ($row[2]) {
                    $qf[] = "inactive=?";
                    $qv[] = 0;
                }
                $m = $this->metadata();
                $this->infoJson = $row[5];
                $this->_metadata = null;
                if (!empty((array) $m)) {
                    $this->infoJson = json_object_replace_recursive($this->infoJson, $m);
                    $qf[] = "infoJson=?";
                    $qv[] = $this->infoJson;
                }
                if (!empty($qf)) {
                    $qv[] = $this->paperId;
                    $qv[] = $this->paperStorageId;
                    $this->conf->qe_apply("update PaperStorage set " . join(",", $qf) . " where paperId=? and paperStorageId=?", $qv);
                }
                return true;
            }
        }

        // ensure content
        $s3 = ($savef & self::SAVEF_SKIP_CONTENT) !== 0
            || ($this->_prefer_s3 && $this->check_s3());
        if ($this->has_error() || (!$s3 && !$this->ensure_content())) {
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
        $s2 = !$s3 && $this->store_docstore($savef);
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
        } else if (($path = $this->available_content_file()) !== null) {
            return @file_get_contents($path);
        } else {
            return false;
        }
    }

    /** @return ?string */
    function available_content_file() {
        if ($this->content_file !== null && is_readable($this->content_file)) {
            return $this->content_file;
        } else if ($this->filestore !== null && is_readable($this->filestore)) {
            return $this->filestore;
        } else {
            return null;
        }
    }

    /** @return ?string */
    function content_file() {
        $this->ensure_content();
        if (($path = $this->available_content_file())) {
            return $path;
        } else if ($this->content === null) {
            return null;
        } else if ($this->store_docstore(0)) {
            return $this->filestore;
        }
        $this->content_file = null;
        $fp = "doc-" . time() . "-%08d" . Mimetype::extension($this->mimetype);
        if (!($x = Filer::tempfile($fp, strlen($this->content) > 10000000 ? $this->conf : null))) {
            return null;
        }
        if (Filer::tempfile_write($x[0], $this->content)) {
            $this->content_file = $x[1];
        }
        fclose($x[0]);
        return $this->content_file;
    }

    /** @return bool */
    function has_memory_content() {
        return $this->content !== null;
    }

    /** @param int $prefix_len
     * @return string|false */
    function content_prefix($prefix_len) {
        if (!$this->ensure_content()) {
            return false;
        } else if ($this->content !== null) {
            return $this->content;
        } else if ($prefix_len <= 4096 && $this->_content_prefix !== null) {
            return $this->_content_prefix;
        } else if (!($path = $this->available_content_file())) {
            return false;
        }
        $prefix_len = max($prefix_len, 4096);
        $s = @file_get_contents($path, false, null, 0, $prefix_len);
        if ($s !== false && strlen($s) <= 4096) {
            $this->_content_prefix = $s;
        }
        return $s;
    }

    /** @return string */
    function content_text_signature() {
        $pfx = $this->content_prefix(16);
        if ($pfx === false) {
            return "cannot be loaded";
        } else if ($pfx === "") {
            return "is empty";
        }
        $t = substr($pfx, 0, 8);
        if (!is_valid_utf8($t)) {
            $t = UnicodeHelper::utf8_prefix(UnicodeHelper::utf8_truncate_invalid($t), 8);
            if (strlen($t) < 6) {
                $t = join("", array_map(function ($ch) {
                    $c = ord($ch);
                    if ($c >= 0x20 && $c <= 0x7E) {
                        return $ch;
                    } else {
                        return sprintf("\\x%02X", $c);
                    }
                }, str_split(substr($pfx, 0, 8))));
            }
        }
        return "starts with “{$t}”";
    }

    /** @return string */
    function content_mimetype() {
        return Mimetype::content_type($this->content_prefix(4096), $this->mimetype);
    }


    /** @param iterable<DocumentInfo> $docs
     * @param int $flags */
    static function prefetch_content($docs, $flags = 0) {
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
        $docstore = ($flags & self::FLAG_NO_DOCSTORE) === 0;

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
                $s3 = $doc->conf->s3_client();
                if (($s3k = $doc->s3_key())) {
                    $dspath = $docstore ? Filer::docstore_path($doc, Filer::FPATH_MKDIR) : null;
                    $stream = $dspath ? self::fopen_docstore($dspath) : null;
                    $s3l = $s3->start_curl_get($s3k)
                        ->set_response_body_stream($stream)
                        ->set_timeout_size($doc->size());
                    $adocs[] = [$doc, $s3l, 0, $stream ? $dspath : null];
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
        return HashAnalysis::hash_as_text($this->binary_hash());
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
        $my_hash = $this->binary_hash();
        return $my_hash !== false && $my_hash === HashAnalysis::hash_as_binary($hash);
    }

    /** @return non-empty-string|false */
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

    /** @param ?string $like_hash
     * @return string|false */
    function content_binary_hash($like_hash = null) {
        // never cached
        $ha = HashAnalysis::make_algorithm($this->conf, $like_hash);
        $this->ensure_content();
        if ($this->content !== null) {
            $ha->set_hash($this->content);
        } else if (($path = $this->available_content_file())) {
            $ha->set_hash_file($path);
        }
        return $ha->ok() ? $ha->binary() : false;
    }

    /** @param string $file
     * @param ?string $like_hash
     * @return string|false */
    function file_binary_hash($file, $like_hash = null) {
        $ha = HashAnalysis::make_algorithm($this->conf, $like_hash);
        $ha->set_hash_file($file);
        return $ha->ok() ? $ha->binary() : false;
    }


    /** @return bool */
    function has_crc32() {
        return is_string($this->crc32) && $this->crc32 !== "";
    }

    /** @return string|false */
    function crc32() {
        if ($this->crc32 === null && $this->paperStorageId > 0) {
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
        $conf = null;
        foreach ($docs as $doc) {
            if ($doc->crc32 === null && $doc->paperStorageId > 0) {
                $conf = $conf ?? $doc->conf;
                if ($doc->conf === $conf) {
                    $need[] = "(paperId={$doc->paperId} and paperStorageId={$doc->paperStorageId})";
                }
            }
        }
        if (!empty($need)) {
            $idmap = [];
            $result = $conf->qe("select paperStorageId, crc32 from PaperStorage where " . join(" or ", $need));
            while (($row = $result->fetch_row())) {
                $idmap[(int) $row[0]] = $row[1] ?? "";
            }
            Dbl::free($result);
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
    function error_filename() {
        if ($this->filename === null || $this->filename === "") {
            return "(uploaded file)";
        }
        return $this->filename;
    }

    /** @return ?string */
    function member_filename($flags = 0) {
        if (($this->_member_filename ?? "") !== "") {
            return $this->_member_filename;
        } else {
            if (($flags & self::ANY_MEMBER_FILENAME) === 0) {
                error_log(debug_string_backtrace());
            }
            assert(($flags & self::ANY_MEMBER_FILENAME) !== 0);
            return $this->filename;
        }
    }

    /** @param ?list<FileFilter> $filters
     * @param int $flags
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
        $filters = $filters ?? $this->filters_applied;
        if ($filters) {
            foreach (is_array($filters) ? $filters : [$filters] as $filter) {
                if (is_string($filter)) {
                    error_log("filter should not be string: " . debug_string_backtrace());
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

    /** @param ?list<FileFilter> $filters
     * @param int $hoturl_flags
     * @return string */
    function url($filters = null, $hoturl_flags = 0) {
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
        if (($hoturl_flags & self::DOCURL_INCLUDE_TIME) !== 0) {
            $f["at"] = $this->timestamp;
        }
        return $this->conf->hoturl("doc", $f, $hoturl_flags);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    const L_FINALTITLE = 4;
    const L_REQUIREFORMAT = 8;

    /** @param string $html
     * @param int $flags
     * @param ?list<FileFilter> $filters
     * @return string */
    function link_html($html = "", $flags = 0, $filters = null) {
        $p = $this->url($filters);
        $suffix = $info = "";
        $title = null;
        $small = ($flags & self::L_SMALL) != 0;

        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0
                && ($o = $this->conf->option_by_id($this->documentType))
                && $o->is_final())) {
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
            $img = "pdf";
            $alt = "[PDF]";
        } else if ($this->mimetype == "application/postscript") {
            $img = "postscript";
            $alt = "[PS]";
        } else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype);
            $alt = "[" . ($m && $m->description ? $m->description : $this->mimetype) . "]";
        }

        $x = "<a href=\"{$p}\" class=\"qo" . ($need_run ? " need-format-check" : "") . '">'
            . Ht::img($img . $suffix . ($small ? "" : "24") . ".png", $alt, ["class" => $small ? "sdlimg" : "dlimg", "title" => $title]);
        if ($html) {
            $x .= " <u class=\"x\">{$html}</u>";
        }
        if (($flags & self::L_NOSIZE) === 0 && $this->size() > 0) {
            $size = unparse_byte_size($this->size);
            if ($html) {
                $size = "({$size})";
            }
            $x .= " <span class=\"dlsize\">{$size}</span>";
        }
        return $x . "</a>" . ($info ? " {$info}" : "");
    }

    /** @param int $flags
     * @param string $suffix
     * @return array{string,string,bool} */
    private function link_html_format_info($flags, $suffix) {
        $message = "";
        $need_run = false;
        if (($spects = $this->conf->format_spec($this->documentType)->timestamp)) {
            if ($this->prow->is_primary_document($this)
                && ($flags & self::L_SMALL) !== 0) {
                if ($this->prow->pdfFormatStatus == -$spects) {
                    $suffix .= "x";
                }
            } else {
                $runflag = CheckFormat::RUN_NEVER;
                if (($flags & self::L_REQUIREFORMAT) !== 0) {
                    $runflag = CheckFormat::RUN_IF_NECESSARY;
                }
                $cf = new CheckFormat($this->conf, $runflag);
                $cf->check_document($this);
                $need_run = $cf->need_recheck();
                if ($cf->has_problem() && $cf->check_ok()) {
                    if ($cf->has_error()) {
                        $suffix .= "x";
                    }
                    if (($flags & self::L_SMALL) === 0) {
                        $ffh = htmlspecialchars($cf->full_feedback_html());
                        $message = "<strong class=\"need-tooltip\" aria-label=\"{$ffh}\">ⓘ</strong>";
                    }
                }
            }
        }
        return [$message, $suffix, $need_run];
    }

    /** @param string $prop
     * @param mixed $v */
    function set_prop($prop, $v) {
        if (($prop === "npages" || $prop === "width" || $prop === "height")
            && $this->conf->sversion >= 276) {
            assert(is_int($v));
            if ($this->$prop === $v) {
                return;
            }
            $this->_old_prop = $this->_old_prop ?? [];
            $this->_old_prop[$prop] = $this->$prop;
            $this->$prop = $v;
            $v = null;
        }
        $m = $this->metadata();
        if (($m->$prop ?? null) !== $v) {
            $this->_old_prop["metadata"] = $this->_old_prop["metadata"] ?? (object) [];
            $this->_old_prop["metadata"]->$prop = $m->$prop ?? null;
            if ($v !== null) {
                $m->$prop = $v;
            } else {
                unset($m->$prop);
            }
        }
    }

    /** @return bool */
    function save_prop($quiet = false) {
        if ($this->paperStorageId <= 1 || empty($this->_old_prop)) {
            return false;
        }
        $qf = $qv = $metadata = [];
        foreach ($this->_old_prop as $prop => $v) {
            if ($prop === "metadata") {
                $m = $this->metadata();
                foreach ((array) $v as $prop1 => $v1) {
                    $metadata[$prop1] = $m->$prop1 ?? null;
                }
            } else {
                $qf[] = "{$prop}=?";
                $qv[] = $this->$prop;
            }
        }
        $qv[] = $this->paperId;
        $qv[] = $this->paperStorageId;
        if (empty($metadata)) {
            $result = $this->conf->qe("update PaperStorage set " . join(", ", $qf) . " where paperId=? and paperStorageId=?", ...$qv);
            $ok = !Dbl::is_error($result);
        } else {
            $ok = true;
            $qf[] = "infoJson=?{desired}";
            $ijstr = Dbl::compare_exchange($this->conf->dblink,
                "select infoJson from PaperStorage where paperId=? and paperStorageId=?",
                [$this->paperId, $this->paperStorageId],
                function ($oldstr) use ($metadata, &$ok) {
                    $newstr = json_object_replace_recursive($oldstr, $metadata);
                    $ok = $newstr === null || strlen($newstr) <= 32768;
                    return $ok ? $newstr : $oldstr;
                },
                "update PaperStorage set " . join(", ", $qf) . " where paperId=? and paperStorageId=? and infoJson?{expected}e",
                $qv);
            $this->infoJson = $ijstr;
            $this->_metadata = null;
            if (!$ok && !$quiet) {
                error_log(caller_landmark() . ": {$this->conf->dbname}: save_prop(paper {$this->paperId}, dt {$this->documentType}): infoJson too long, delta " . json_encode($metadata));
            }
        }
        return $ok;
    }

    function abort_prop() {
        foreach ($this->_old_prop ?? [] as $prop => $v) {
            if ($prop === "metadata") {
                $m = $this->metadata();
                foreach ((array) $v as $prop1 => $v1) {
                    if ($v1 !== null) {
                        $m->$prop1 = $v1;
                    } else {
                        unset($m->$prop1);
                    }
                }
            } else {
                $this->$prop = $v;
            }
        }
        $this->_old_prop = null;
    }

    function prop_update() {
        $j = [];
        foreach ($this->_old_prop ?? [] as $prop => $v) {
            if ($prop === "metadata") {
                $m = $this->metadata();
                foreach ((array) $v as $prop1 => $v1) {
                    $j[$prop1] = $m->$prop1 ?? null;
                }
            } else {
                $j[$prop] = $this->$prop;
            }
        }
        return $j;
    }

    function load_metadata() {
        if ($this->paperStorageId > 0) {
            $row = Dbl::fetch_first_object($this->conf->dblink,
                    "select " . $this->conf->document_metadata_query_fields() . " from PaperStorage where paperId=? and paperStorageId=?",
                    $this->paperId, $this->paperStorageId)
                ?? (object) ["infoJson" => null, "npages" => -1, "width" => -1, "height" => -1];
            foreach ((array) $row as $prop => $v) {
                if ($prop !== "infoJson") {
                    $v = (int) $v;
                }
                $this->$prop = $v;
            }
            $this->_metadata = null;
        }
    }

    /** @return object */
    function metadata() {
        if ($this->_metadata === null) {
            if ($this->infoJson === false && $this->paperStorageId > 0) {
                $this->load_metadata();
            }
            $this->_metadata = ($this->infoJson ? json_decode($this->infoJson) : null) ?? (object) [];
        }
        return $this->_metadata;
    }

    /** @return bool */
    function is_archive() {
        return $this->filename
            && preg_match('/\.(?:zip|tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $this->filename);
    }

    /** @param int $max_length
     * @return ?list<string> */
    function archive_listing($max_length = -1) {
        return ArchiveInfo::archive_listing($this, $max_length);
    }

    /** @param ?CheckFormat $cf
     * @return ?int */
    function npages(CheckFormat $cf = null) {
        if (($this->mimetype && $this->mimetype !== "application/pdf")
            || $this->npages === -1000000) {
            return null;
        }
        if ($this->npages === null) {
            $this->load_metadata();
        }
        if ($this->npages < 0 && $this->infoJson) {
            $m = $this->metadata();
            if (isset($m->npages) && is_int($m->npages)) {
                $this->set_prop("npages", $m->npages);
                $this->save_prop();
            }
        }
        if ($this->npages < 0) {
            // prevent recursive computation of npages
            $this->npages = -1000000;

            $cfx = $cf ?? new CheckFormat($this->conf);
            $cfx->check_document($this);

            // if default format checker fails, it will not succeed later;
            // if non-default format checker fails, default might succeed
            if ($this->npages === -1000000 && $cf) {
                $this->npages = -1;
            }
        }
        return $this->npages >= 0 ? $this->npages : null;
    }

    /** @param ?CheckFormat $cf
     * @return ?int */
    function nwords(CheckFormat $cf = null) {
        if ($this->mimetype && $this->mimetype !== "application/pdf") {
            return null;
        } else {
            $cf = $cf ?? new CheckFormat($this->conf);
            $cf->check_document($this);
            return $cf->nwords;
        }
    }

    /** @return ?int */
    function width() {
        if ($this->width === null) {
            $this->load_metadata();
        }
        return $this->width >= 0 ? $this->width : null;
    }

    /** @return ?int */
    function height() {
        if ($this->height === null) {
            $this->load_metadata();
        }
        return $this->height >= 0 ? $this->height : null;
    }

    /** @param ?Downloader $dopt
     * @return bool */
    function download($dopt = null) {
        if ($this->size() <= 0) {
            $this->message_set()->warning_at(null, "<0>Empty file");
            return false;
        }

        $dopt = $dopt ?? new Downloader;
        if ($this->has_hash()) {
            $dopt->etag = "\"{$this->text_hash()}\"";
            if ($dopt->run_match()) {
                return true;
            }
        }

        $s3_accel = false;
        if (!$dopt->no_accel && $dopt->range === null) {
            // do not forward range requests to S3 -- there are a lot of them
            // and they can get rate limited
            $s3_accel = $this->s3_accel_redirect();
        }
        if (!$s3_accel && !$this->ensure_content()) {
            if (!$this->has_error()) {
                $this->error("Document cannot be prepared for download");
            }
            return false;
        }

        // Print headers
        $dopt->mimetype = Mimetype::type_with_charset($this->mimetype);
        $attachment = $dopt->attachment ?? !Mimetype::disposition_inline($this->mimetype);
        $downloadname = $this->export_filename();
        if (($slash = strrpos($downloadname, "/")) !== false) {
            $downloadname = substr($downloadname, $slash + 1);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        if ($dopt->cacheable) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 315576000) . " GMT");
        }
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");

        // Maybe log
        if ($dopt->log_user && $dopt->range_overlaps(0, 4096)) {
            DocumentInfo::log_download_activity([$this], $dopt->log_user);
        }

        // Download or redirect
        if ($s3_accel) {
            header("Content-Type: {$dopt->mimetype}");
            header("ETag: {$dopt->etag}");
            $this->conf->s3_client()->get_accel_redirect($this->s3_key(), $s3_accel);
        } else if (($path = $this->available_content_file())) {
            $dopt->output_file($path);
        } else {
            $dopt->output_string($this->content);
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
        if ($this->size >= 0) {
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
                $x[$k] = HashAnalysis::hash_as_text($v);
            } else if ($v !== null && $k !== "conf" && $k !== "prow" && $k[0] !== "_") {
                $x[$k] = $v;
            }
        }
        return $x;
    }

    /** @param list<DocumentInfo> $docs */
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
                $user->log_activity_dedup("Download {$name}", array_values(array_unique($pidm)));
            }
        }
    }

    /** @return array<int,true> */
    static function active_document_map(Conf $conf) {
        $q = ["select paperStorageId from Paper where paperStorageId>1",
            "select finalPaperStorageId from Paper where finalPaperStorageId>1",
            "select documentId from DocumentLink where documentId>1"];
        $document_option_ids = [];
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
