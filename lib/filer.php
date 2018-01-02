<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ZipDocument {
    public $filename;
    public $filestore;
    public $sha1; // `ZipDocument` can pun as a `DocumentInfo`;
                  // this checksum might not be of the data

    private $tmpdir_ = null;
    private $files;
    public $warnings;
    private $recurse;
    private $mimetype;
    private $headers;
    private $start_time;
    private $_pending;
    private $_pending_memory;

    function __construct($filename, $mimetype = "application/zip") {
        $this->filename = $filename;
        $this->mimetype = $mimetype;
        $this->clean();
    }

    function clean() {
        $this->filestore = false;
        $this->sha1 = "";
        $this->tmpdir_ = null;
        $this->files = array();
        $this->warnings = array();
        $this->recurse = false;
        $this->headers = false;
        $this->start_time = time();
        $this->_pending = [];
        $this->_pending_memory = 0;
    }

    private function tmpdir() {
        if ($this->tmpdir_ === null
            && ($this->tmpdir_ = tempdir()) === false)
            $this->warnings[] = "Could not create temporary directory.";
        return $this->tmpdir_;
    }

    private function _add_load($doc, $filename, $to_filestore) {
        if ($doc->docclass && $doc->docclass->load($doc, $to_filestore)) {
            $doc->_content_reset = true;
            return true;
        } else {
            if (!isset($doc->error_text))
                $this->warnings[] = "$filename: Couldn’t load document.";
            else if ($doc->error_text)
                $this->warnings[] = $doc->error_text;
            return false;
        }
    }

    private function _add($doc, $filename, $check_filename) {
        if (is_string($doc))
            $doc = new DocumentInfo(["content" => $doc]);
        assert($doc instanceof DocumentInfo);

        // maybe this is a warning container
        if ($doc->error) {
            $this->warnings[] = ($doc->filename != "" ? $doc->filename . ": " : "")
                . ($doc->error_html ? htmlspecialchars_decode($doc->error_html) : "Unknown error.");
            return;
        }

        // check filename
        if ($filename == "" && $doc->filename != "")
            $filename = $doc->filename;
        if ($filename == ""
            || ($check_filename
                && !preg_match(',\A[^.*/\s\000-\017\\\\\'"][^*/\000-\017\\\\\'"]*\z,', $filename))) {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }

        // load document
        if (!isset($doc->filestore) && !isset($doc->content)
            && !$this->_add_load($doc, $filename, true))
            return false;

        // add document to filestore list
        if (is_array($this->_pending)
            && (isset($doc->filestore)
                || (isset($doc->content) && $doc->content !== ""
                    && strlen($doc->content) + $this->_pending_memory <= 4000000))
            && $doc->binary_hash() !== false) {
            $this->_pending[] = new DocumentInfo(["filename" => $filename, "filestore" => $doc->filestore, "sha1" => $doc->binary_hash(), "content" => $doc->content]);
            if (!isset($doc->filestore))
                $this->_pending_memory += strlen($doc->content);
            return self::_add_done($doc, true);
        }

        // At this point, we will definitely create a new zipfile.

        // construct temporary directory
        if (!($tmpdir = $this->tmpdir()))
            return self::_add_done($doc, false);
        $zip_filename = "$tmpdir/";

        // populate with pending contents, if any
        if (!$this->_resolve_pending())
            return self::_add_done($doc, false);

        // construct subdirectories
        $fn = $filename;
        while (($p = strpos($fn, "/")) !== false) {
            $zip_filename .= substr($fn, 0, $p);
            if (!is_dir($zip_filename)
                && (file_exists($zip_filename) || !@mkdir($zip_filename, 0777))) {
                $this->warnings[] = "$filename: Couldn’t save document to this name.";
                return self::_add_done($doc, false);
            }
            $zip_filename .= "/";
            $fn = substr($fn, $p + 1);
        }
        if ($fn === "") {
            $this->warnings[] = "$filename: Bad filename.";
            return self::_add_done($doc, false);
        }
        $zip_filename .= $fn;

        // store file in temporary directory
        if (isset($doc->filestore)
            && @symlink($doc->filestore, $zip_filename))
            /* OK */;
        else {
            if (!isset($doc->content) && !$this->_add_load($doc, $filename, false))
                return false;
            $trylen = file_put_contents($zip_filename, $doc->content);
            if ($trylen != strlen($doc->content)) {
                clean_tempdirs();
                $trylen = file_put_contents($zip_filename, $doc->content);
            }
            if ($trylen != strlen($doc->content)) {
                $this->warnings[] = "$filename: Could not save.";
                return self::_add_done($doc, false);
            }
        }

        // track files to pass to `zip`
        $zip_filename = substr($zip_filename, strlen($tmpdir) + 1);
        if (($p = strpos($zip_filename, "/")) !== false) {
            $zip_filename = substr($zip_filename, 0, $p);
            $this->recurse = true;
        }
        $this->files[$zip_filename] = true;

        // complete
        if (time() - $this->start_time >= 0)
            set_time_limit(30);
        return self::_add_done($doc, true);
    }

    static private function _add_done($doc, $result) {
        if (isset($doc->_content_reset) && $doc->_content_reset)
            unset($doc->content, $doc->_content_reset);
        return $result;
    }

    private function _resolve_pending() {
        if (($ps = $this->_pending) !== null) {
            $this->_pending = null;
            foreach ($ps as $f)
                if (!$this->_add($f, $f->filename, false))
                    return false;
        }
        return true;
    }

    function add($doc, $filename = null) {
        return $this->_add($doc, $filename, true);
    }

    function add_as($doc, $filename) {
        return $this->_add($doc, $filename, false);
    }

    function download_headers() {
        if (!$this->headers) {
            header("Content-Disposition: attachment; filename=" . mime_quote_string($this->filename));
            header("Content-Type: " . $this->mimetype);
            $this->headers = true;
        }
    }

    function create() {
        global $Now;
        if (!($tmpdir = $this->tmpdir()))
            return set_error_html("Could not create temporary directory.");

        // maybe cache zipfile in docstore
        $this->filestore = "$tmpdir/_hotcrp.zip";
        if (!empty($this->_pending) && opt("docstore")
            && opt("docstoreAccelRedirect")) {
            // calculate hash for zipfile contents
            $sorted_pending = $this->_pending;
            usort($sorted_pending, function ($a, $b) {
                return strcmp($a->filename, $b->filename);
            });
            $hash_input = count($sorted_pending) . "\n";
            foreach ($sorted_pending as $f)
                $hash_input .= $f->filename . "\n" . $f->text_hash() . "\n";
            if (!empty($this->warnings))
                $hash_input .= "README-warnings.txt\n" . join("\n", $this->warnings) . "\n";
            $this->sha1 = sha1($hash_input, false);
            // look for zipfile
            $dstore_prefix = Filer::docstore_fixed_prefix(opt("docstore"));
            $zfn = $dstore_prefix . "tmp/" . $this->sha1 . ".zip";
            if (Filer::prepare_filestore($dstore_prefix, $zfn)) {
                $this->filestore = $zfn;
                if (file_exists($this->filestore)) {
                    if (($mtime = @filemtime($zfn)) < $Now - 21600)
                        @touch($this->filestore);
                    return $this->filestore;
                }
            }
        }

        // actually run zip
        if (!($zipcmd = opt("zipCommand", "zip")))
            return set_error_html("<code>zip</code> is not supported on this installation.");
        $this->_resolve_pending();
        if (count($this->warnings))
            $this->add(join("\n", $this->warnings) . "\n", "README-warnings.txt");
        $opts = ($this->recurse ? "-rq" : "-q");
        set_time_limit(60);
        $command = "cd $tmpdir; $zipcmd $opts " . escapeshellarg($this->filestore) . " " . join(" ", array_map("escapeshellarg", array_keys($this->files)));
        $out = system("$command 2>&1", $status);
        if ($status == 0 && file_exists($this->filestore)) {
            $this->sha1 = "sha2-" . hash_file("sha256", $this->filestore);
            return $this->filestore;
        }
        $this->filestore = false;
        if ($status != 0)
            return set_error_html("<code>zip</code> returned an error.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        else
            return set_error_html("<code>zip</code> output unreadable or empty.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
    }

    function download() {
        $result = $this->create();
        if (is_string($result)) {
            set_time_limit(180); // large zip files might download slowly
            $this->download_headers();
            Filer::download_file($result);
            $result = (object) array("error" => false);
        }
        $this->clean();
        return $result;
    }
}

class Filer_UploadJson implements JsonSerializable {
    public $docid;
    public $content;
    public $filename;
    public $mimetype;
    public $timestamp;
    function __construct($upload) {
        $this->content = file_get_contents($upload["tmp_name"]);
        if (isset($upload["name"])
            && strlen($upload["name"]) <= 255
            && is_valid_utf8($upload["name"]))
            $this->filename = $upload["name"];
        $this->mimetype = Mimetype::type(get($upload, "type", "application/octet-stream"));
        $this->timestamp = time();
    }
    function jsonSerialize() {
        $x = array();
        foreach (get_object_vars($this) as $k => $v)
            if ($k === "content" && $v !== null) {
                $v = strlen($v) < 50 ? $v : substr($v, 0, 50) . "...";
                $x[$k] = convert_to_utf8($v);
            } else if ($v !== null)
                $x[$k] = $v;
        return $x;
    }
}

class Filer {
    static public $tempdir;

    function validate_content(DocumentInfo $doc) {
        // load() callback. Return `true` if content of $doc is up to date and
        // need not be checked by load_content.
        return true;
    }
    function load_content(DocumentInfo $doc) {
        // load() callback. Return `true` if content was successfully loaded.
        // On return true, at least one of `$doc->content` and
        // `$doc->filestore` must be set.
        return false;
    }
    function filestore_pattern(DocumentInfo $doc) {
        // load()/store() callback. Return the filestore pattern suitable for
        // `$doc`.
        return null;
    }
    function dbstore(DocumentInfo $doc) {
        // store() callback. Return a `Filer_Dbstore` object to tell how to
        // store the document in the database.
        return null;
    }
    function store_other(DocumentInfo $doc) {
        // store() callback. Store `$doc` elsewhere (e.g. S3) if appropriate.
    }
    function validate_upload(DocumentInfo $doc) {
        // upload() callback. Return false if $doc should not be stored.
        return true;
    }

    // main accessors
    static function has_content($doc) {
        return is_string(get($doc, "content"))
            || is_string(get($doc, "content_base64"))
            || self::content_filename($doc);
    }
    static function content_filename($doc) {
        if (is_string(get($doc, "content_file")) && is_readable($doc->content_file))
            return $doc->content_file;
        if (is_string(get($doc, "filestore")) && is_readable($doc->filestore))
            return $doc->filestore;
        return false;
    }
    static function content($doc) {
        if (is_string(get($doc, "content")))
            return $doc->content;
        if (is_string(get($doc, "content_base64")))
            return $doc->content = base64_decode($doc->content_base64);
        if (($filename = self::content_filename($doc)))
            return $doc->content = @file_get_contents($filename);
        return false;
    }
    function load(DocumentInfo $doc, $to_filestore = false) {
        // Return true iff `$doc` can be loaded.
        $has_content = self::has_content($doc);
        if (!$has_content
            && ($fspath = $this->filestore_path($doc, self::FPATH_EXISTS))) {
            $doc->filestore = $fspath;
            $has_content = true;
        }
        if ($has_content && $this->validate_content($doc))
            return true;
        else if ($this->load_content($doc)) {
            if ($to_filestore && $doc->filestore)
                $doc->content = null;
            return true;
        } else
            return false;
    }
    function load_to_filestore(DocumentInfo $doc) {
        if (!$this->load($doc, true))
            return false;
        if (!isset($doc->filestore)) {
            if (!self::$tempdir && (self::$tempdir = tempdir()) == false) {
                set_error_html($doc, "Cannot create temporary directory.");
                return false;
            }
            if (($hash = $doc->text_hash()) === false) {
                set_error_html($doc, "Failed to hash contents.");
                return false;
            }
            $path = self::$tempdir . "/" . $hash . Mimetype::extension($doc->mimetype);
            if (file_put_contents($path, $doc->content) != strlen($doc->content)) {
                set_error_html($doc, "Failed to save document to temporary file.");
                return false;
            }
            $doc->filestore = $path;
        }
        return true;
    }
    function load_to_memory(DocumentInfo $doc) {
        if (!$this->load($doc, false))
            return false;
        if (isset($doc->filestore) && !isset($doc->content)
            && ($content = @file_get_contents($doc->filestore)) !== false)
            $doc->content = $content;
        return isset($doc->content);
    }
    function store(DocumentInfo $doc) {
        // load content (if unloaded)
        // XXX loading enormous documents into memory...?
        if (!$this->load($doc, false)
            || ($content = self::content($doc)) === null
            || $content === false
            || get($doc, "error"))
            return false;
        // calculate hash, complain on mismatch
        if ($doc->has_hash()) {
            $bhash = $doc->content_binary_hash($doc->binary_hash());
            if ($bhash !== $doc->binary_hash()) {
                set_error_html($doc, "Document claims checksum " . $doc->text_hash() . ", but has checksum " . bin2hex($bhash) . ".");
                return false;
            }
        }
        if (isset($doc->size) && $doc->size && $doc->size != strlen($content))
            set_error_html($doc, "Document claims length " . $doc->size . ", but has length " . strlen($content) . ".");
        $doc->size = strlen($content);
        $content = null;
        // actually store
        if (($dbinfo = $this->dbstore($doc)))
            $this->store_database($dbinfo, $doc);
        $this->store_filestore($doc);
        $this->store_other($doc);
        return !get($doc, "error");
    }

    // dbstore functions
    function store_database($dbinfo, DocumentInfo $doc) {
        global $Conf;
        $N = 400000;
        $idcol = $dbinfo->id_column;
        $while = "while storing document in database";

        $qk = $qv = [];
        foreach ($dbinfo->columns as $k => $v)
            if ($k !== $idcol) {
                $qk[] = "`$k`=?";
                $qv[] = substr($v, 0, $N);
            }

        if (isset($dbinfo->columns[$idcol])) {
            $q = "update $dbinfo->table set " . join(", ", $qk) . " where $idcol=?";
            $qv[] = $dbinfo->columns[$idcol];
        } else
            $q = "insert into $dbinfo->table set " . join(", ", $qk);
        if (!($result = Dbl::qe_apply($dbinfo->dblink, $q, $qv))) {
            set_error_html($doc, $Conf->db_error_html(true, $while));
            return;
        }

        if (isset($dbinfo->columns[$idcol]))
            $doc->$idcol = $dbinfo->columns[$idcol];
        else {
            $doc->$idcol = $result->insert_id;
            if (!$doc->$idcol) {
                set_error_html($doc, $Conf->db_error_html(true, $while));
                return;
            }
        }

        for ($pos = $N; true; $pos += $N) {
            $qk = $qv = [];
            foreach ($dbinfo->columns as $k => $v)
                if (strlen($v) > $pos) {
                    $qk[] = "`{$k}`=concat(`{$k}`,?)";
                    $qv[] = substr($v, $pos, $N);
                }
            if (empty($qk))
                break;
            $q = "update $dbinfo->table set " . join(", ", $qk) . " where $idcol=?";
            $qv[] = $doc->$idcol;
            if (!Dbl::qe_apply($dbinfo->dblink, $q, $qv)) {
                set_error_html($doc, $Conf->db_error_html(true, $while));
                return;
            }
        }

        // check that paper storage succeeded
        if ($dbinfo->check_contents) {
            $len = Dbl::fetch_ivalue($dbinfo->dblink, "select length($dbinfo->check_contents) from $dbinfo->table where $idcol=?", $doc->$idcol);
            if ($len != strlen(self::content($doc)))
                set_error_html($doc, "Failed to store your document. Usually this is because the file you tried to upload was too big for our system. Please try again.");
        }
    }

    // filestore functions
    function filestore_check(DocumentInfo $doc) {
        return !!$this->filestore_path($doc, self::FPATH_EXISTS);
    }
    static function docstore_fixed_prefix($pattern) {
        if ($pattern == "")
            return $pattern;
        $prefix = "";
        while (($pos = strpos($pattern, "%")) !== false) {
            if ($pos == strlen($pattern) - 1)
                break;
            else if ($pattern[$pos + 1] === "%") {
                $prefix .= substr($pattern, 0, $pos + 1);
                $pattern = substr($pattern, $pos + 2);
            } else {
                $prefix .= substr($pattern, 0, $pos);
                if (($rslash = strrpos($prefix, "/")) !== false)
                    return substr($prefix, 0, $rslash + 1);
                else
                    return "";
            }
        }
        $prefix .= $pattern;
        if ($prefix[strlen($prefix) - 1] !== "/")
            $prefix .= "/";
        return $prefix;
    }
    static function prepare_filestore($parent, $path) {
        if (!self::_make_fpath_parents($parent, $path))
            return false;
        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "$parent/.htaccess";
        if (!is_file($htaccess)
            && @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink($htaccess);
            return false;
        }
        return true;
    }
    function store_filestore(DocumentInfo $doc, $no_error = false) {
        $flags = self::FPATH_MKDIR | ($no_error ? self::FPATH_QUIET : 0);
        if (!($fspath = $this->filestore_path($doc, $flags)))
            return false;
        $content = self::content($doc);
        if (!is_readable($fspath) || file_get_contents($fspath) !== $content) {
            if (file_put_contents($fspath, $content) != strlen($content)) {
                @unlink($fspath);
                $no_error || set_error_html($doc, "Internal error: docstore failure.");
                return false;
            }
            @chmod($fspath, 0660 & ~umask());
        }
        $doc->filestore = $fspath;
        return true;
    }

    // download
    static function download_file($filename, $no_accel = false) {
        global $zlib_output_compression;
        // if docstoreAccelRedirect, output X-Accel-Redirect header
        if (($dar = opt("docstoreAccelRedirect"))
            && ($ds = opt("docstore"))
            && !$no_accel) {
            if (!str_ends_with($ds, "/"))
                $ds .= "/";
            if (str_starts_with($filename, $ds)
                && ($tail = substr($filename, strlen($ds)))
                && preg_match(',\A[^/]+,', $tail)) {
                if (!str_ends_with($dar, "/"))
                    $dar .= "/";
                header("X-Accel-Redirect: $dar$tail");
                return;
            }
        }
        // write length header, flush output buffers
        if (!$zlib_output_compression)
            header("Content-Length: " . filesize($filename));
        flush();
        while (@ob_end_flush())
            /* do nothing */;
        // read file directly to output
        readfile($filename);
    }
    function download($doc, $downloadname = null, $attachment = null) {
        if (is_object($doc) && !isset($doc->docclass))
            $doc->docclass = $this;
        return self::multidownload($doc, $downloadname, $attachment);
    }
    static function multidownload($doc, $downloadname = null, $opts = null) {
        global $Now, $zlib_output_compression;
        if (is_array($doc) && count($doc) == 1) {
            $doc = $doc[0];
            $downloadname = null;
        }
        if (!$doc || (is_object($doc) && isset($doc->size) && $doc->size == 0))
            return set_error_html("Empty file.");
        if (is_array($doc)) {
            $z = new ZipDocument($downloadname);
            foreach ($doc as $d)
                $z->add($d);
            return $z->download();
        }
        if (!self::has_content($doc)
            && (!get($doc, "docclass") || !$doc->docclass->load($doc, true))) {
            $error_html = "Don’t know how to download.";
            if (get($doc, "error") && isset($doc->error_html))
                $error_html = $doc->error_html;
            else if (get($doc, "error") && isset($doc->error_text))
                $error_html = htmlspecialchars($doc->error_text);
            return set_error_html($error_html);
        }

        // Print paper
        header("Content-Type: " . Mimetype::type($doc->mimetype));
        $attachment = null;
        if (is_bool($opts))
            $attachment = $opts;
        else if (is_array($opts) && isset($opts["attachment"]))
            $attachment = $opts["attachment"];
        if ($attachment === null)
            $attachment = !Mimetype::disposition_inline($doc->mimetype);
        if (!$downloadname) {
            $downloadname = $doc->filename;
            if (($slash = strrpos($downloadname, "/")) !== false)
                $downloadname = substr($downloadname, $slash + 1);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        if (is_array($opts) && get($opts, "cacheable")) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", $Now + 315576000) . " GMT");
        }
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
        if ($doc->has_hash())
            header("ETag: \"" . $doc->text_hash() . "\"");
        if (($filename = self::content_filename($doc)))
            self::download_file($filename, get($doc, "no_cache") || get($doc, "no_accel"));
        else {
            $content = self::content($doc);
            if (!$zlib_output_compression)
                header("Content-Length: " . strlen($content));
            echo $content;
        }
        return (object) array("error" => false);
    }

    // upload
    function upload(DocumentInfo $doc) {
        global $Conf;
        if (!$this->load($doc, false) && !$doc->error_html)
            set_error_html($doc, "Empty document.");
        if ($doc->error)
            return false;

        // Clean up mimetype and timestamp.
        if (!isset($doc->mimetype) && isset($doc->type) && is_string($doc->type))
            $doc->mimetype = $doc->type;
        $doc->mimetype = Mimetype::content_type($doc->content, $doc->mimetype);
        $doc->timestamp = $doc->timestamp ? : time();

        if ($this->validate_upload($doc))
            return $this->store($doc);
        else
            return false;
    }

    // hash helpers
    static function analyze_hash($hash, $refhash = null) {
        $len = strlen($hash);
        if ($len === 20 || $len === 40)
            return [$hash, "", "sha1"];
        else if ($len === 32 || $len === 64) {
            if (!$refhash || substr($refhash, 0, 5) !== "sha3-")
                return [$hash, "sha2-", "sha256"];
        } else if (($len == 25 || $len === 45)
                   && strcasecmp(substr($hash, 0, 5), "sha1-") == 0)
            return [substr($hash, 5), "", "sha1"];
        else if (($len == 37 || $len === 69)
                 && strcasecmp(substr($hash, 0, 5), "sha2-") == 0)
            return [substr($hash, 5), "sha2-", "sha256"];
        return [false, false, false];
    }
    static function hash_as_text($hash, $refhash = null) {
        if (!is_string($hash)) {
            error_log("Filer::hash_as_text: invalid input " . var_export($hash, true) . ", caller " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            return false;
        }
        list($hash, $pfx, $alg) = self::analyze_hash($hash, $refhash);
        if ($hash !== false && strlen($hash) < 40)
            return $pfx . bin2hex($hash);
        else if ($hash !== false && ctype_xdigit($hash))
            return $pfx . strtolower($hash);
        else
            return false;
    }
    static function hash_as_binary($hash) {
        if (!is_string($hash)) {
            error_log("Filer::hash_as_binary: invalid input " . var_export($h, true) . ", caller " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            return false;
        }
        list($hash, $pfx, $alg) = self::analyze_hash($hash);
        if ($hash !== false && strlen($hash) < 40)
            return $pfx . $hash;
        else if ($hash !== false && ctype_xdigit($hash))
            return $pfx . hex2bin($hash);
        else
            return false;
    }
    static function check_text_hash($hash1, $hash2) {
        $hash2 = self::hash_as_text($hash2, $hash1);
        return $hash2 !== false && $hash1 === $hash2;
    }
    static function sha1_hash_as_text($str) {
        if (strlen($str) > 20 && strcasecmp(substr($str, 0, 5), "sha1-") == 0)
            $str = substr($str, 5);
        $len = strlen($str);
        if ($len === 20)
            return bin2hex($str);
        else if ($len === 40 && ctype_xdigit($str))
            return strtolower($str);
        else
            return false;
    }

    // private functions
    private function _expand_filestore($pattern, DocumentInfo $doc, $extension) {
        $x = "";
        $hash = false;
        while (preg_match('/\A(.*?)%(\d*)([%hxHjaA])(.*)\z/', $pattern, $m)) {
            $x .= $m[1];
            list($fwidth, $fn, $pattern) = [$m[2], $m[3], $m[4]];
            if ($fn === "%")
                $x .= "%";
            else if ($fn === "x") {
                if ($extension)
                    $x .= Mimetype::extension($doc->mimetype);
            } else {
                if ($hash === false
                    && ($hash = $doc->text_hash()) == false)
                    return false;
                if ($fn === "h" && $fwidth === "")
                    $x .= $hash;
                else if ($fn === "a")
                    $x .= $doc->hash_algorithm();
                else if ($fn === "A")
                    $x .= $doc->hash_algorithm_prefix();
                else if ($fn === "j")
                    $x .= substr($hash, 0, strlen($hash) === 40 ? 2 : 3);
                else {
                    $h = $hash;
                    if (strlen($h) !== 40) {
                        $pos = strpos($h, "-") + 1;
                        if ($fn === "h")
                            $x .= substr($h, 0, $pos);
                        $h = substr($h, $pos);
                    }
                    if ($fwidth === "")
                        $x .= $h;
                    else
                        $x .= substr($h, 0, intval($fwidth));
                }
            }
        }
        return $x . $pattern;
    }
    const FPATH_EXISTS = 1;
    const FPATH_MKDIR = 2;
    const FPATH_QUIET = 4;
    function filestore_path(DocumentInfo $doc, $flags = 0) {
        if ($doc->error || !($pattern = $this->filestore_pattern($doc)))
            return null;
        if (!($f = $this->_expand_filestore($pattern, $doc, true)))
            return null;
        if (($flags & self::FPATH_EXISTS) && !is_readable($f)) {
            // clean up presence of old files saved w/o extension
            $g = $this->_expand_filestore($pattern, $doc, false);
            if ($f && $g !== $f && is_readable($g)) {
                if (!@rename($g, $f))
                    $f = $g;
            } else
                return null;
        }
        if (($flags & self::FPATH_MKDIR)
            && !self::prepare_filestore(self::docstore_fixed_prefix($pattern), $f)) {
            if (!($flags & self::FPATH_QUIET))
                set_error_html($doc, "Internal error: docstore cannot be initialized.");
            return false;
        }
        return $f;
    }
    private static function _make_fpath_parents($fdir, $fpath) {
        $lastslash = strrpos($fpath, "/");
        $container = substr($fpath, 0, $lastslash);
        while (str_ends_with($container, "/"))
            $container = substr($container, 0, strlen($container) - 1);
        if (!is_dir($container)) {
            if (strlen($container) < strlen($fdir)
                || !($parent = self::_make_fpath_parents($fdir, $container))
                || !@mkdir($container, 0770))
                return false;
            if (!@chmod($container, 02770 & fileperms($parent))) {
                @rmdir($container);
                return false;
            }
        }
        return $container;
    }


    function is_archive(DocumentInfo $doc) {
        return $doc->filename
            && preg_match('/\.(?:zip|tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $doc->filename);
    }

    function archive_listing(DocumentInfo $doc, $max_length = -1) {
        if (!$this->load_to_filestore($doc))
            return false;
        $type = null;
        if (preg_match('/\.zip\z/i', $doc->filename))
            $type = "zip";
        else if (preg_match('/\.(?:tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $doc->filename))
            $type = "tar";
        else if (!$doc->filename) {
            $contents = file_get_contents($doc->filestore, false, null, 0, 1000);
            if (str_starts_with($contents, "\x1F\x9D")
                || str_starts_with($contents, "\x1F\xA0")
                || str_starts_with($contents, "BZh")
                || str_starts_with($contents, "\x1F\x8B")
                || str_starts_with($contents, "\xFD7zXZ\x00"))
                $type = "tar";
            else if (str_starts_with($contents, "ustar\x0000")
                     || str_starts_with($contents, "ustar  \x00"))
                $type = "tar";
            else if (str_starts_with($contents, "PK\x03\x04")
                     || str_starts_with($contents, "PK\x05\x06")
                     || str_starts_with($contents, "PK\x07\x08"))
                $type = "zip";
        }
        if (!$type)
            return false;
        if ($type === "zip")
            $cmd = "zipinfo -1 ";
        else
            $cmd = "tar tf ";
        $cmd .= escapeshellarg($doc->filestore);
        $pipes = null;
        $proc = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        // Some versions of PHP experience timeouts here; work around that.
        $out = $err = "";
        $now = microtime(true);
        $end_time = $now + 5;
        $done = false;
        while (!$done
               && $now < $end_time
               && ($max_length < 0 || $max_length > strlen($out))) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            $delta = $end_time - $now;
            $delta_sec = (int) $delta;
            stream_select($r, $w, $e, $delta_sec, (int) (($delta - $delta_sec) * 1000000));
            foreach ($r as $f) {
                if ($f === $pipes[1]) {
                    $t = fread($pipes[1], $max_length < 0 ? 65536 : min(65536, $max_length - strlen($out)));
                    if ($t === "")
                        $done = true;
                    else
                        $out .= $t;
                } else if ($f === $pipes[2])
                    $err .= fread($pipes[2], 65536);
            }
            $now = microtime(true);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($err !== "")
            $err = preg_replace('/^tar: Ignoring unknown[^\n]*\n*/m', '', $err);
        if (($status !== 0 && $status !== 2) || $err !== "")
            error_log("$cmd problem: status $status, stderr $err");
        if (!$done && ($slash = strrpos($out, "\n")) > 0)
            return explode("\n", substr($out, 0, $slash + 1) . "…");
        else
            return explode("\n", rtrim($out));
    }

    function clean_archive_listing($listing) {
        $bad = preg_grep('@(?:\A|/)(?:\A__MACOSX|\._.*|\.DS_Store|\.svn|\.git|.*~\z|.*/\z|\A…\z)(?:/|\z)@', $listing);
        if (!empty($bad)) {
            $listing = array_values(array_diff_key($listing, $bad));
            if (preg_match('@[^/]\n@', join("\n", $bad) . "\n"))
                $listing[] = "…";
        }
        return $listing;
    }

    function consolidate_archive_listing($listing) {
        $new_listing = [];
        $nlisting = count($listing);
        $etcetera = $nlisting && $listing[$nlisting - 1] === "…";
        if ($etcetera)
            --$nlisting;
        for ($i = 0; $i < $nlisting; ) {
            if ($i + 1 < $nlisting && ($slash = strpos($listing[$i], "/")) !== false) {
                $prefix = substr($listing[$i], 0, $slash + 1);
                for ($j = $i + 1; $j < $nlisting && str_starts_with($listing[$j], $prefix); ++$j) {
                }
                if ($j > $i + 1) {
                    $xlisting = [];
                    for (; $i < $j; ++$i)
                        $xlisting[] = substr($listing[$i], $slash + 1);
                    $xlisting = $this->consolidate_archive_listing($xlisting);
                    if (count($xlisting) == 1)
                        $new_listing[] = $prefix . $xlisting[0];
                    else
                        $new_listing[] = $prefix . "{" . join(", ", $xlisting) . "}";
                    continue;
                }
            }
            $new_listing[] = $listing[$i];
            ++$i;
        }
        if ($etcetera)
            $new_listing[] = "…";
        return $new_listing;
    }
}

class Filer_Dbstore {
    public $dblink;
    public $table;
    public $id_column;
    public $columns;
    public $check_contents;

    function __construct($dblink, $table, $id_column, $columns, $check_contents = null) {
        $this->dblink = $dblink;
        $this->table = $table;
        $this->id_column = $id_column;
        $this->columns = $columns;
        $this->check_contents = $check_contents;
    }
}
