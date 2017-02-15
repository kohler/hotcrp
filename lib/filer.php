<?php
// filer.php -- generic document helper class
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ZipDocument_File {
    public $filename;
    public $sha1;
    public $filestore;
    public $content;
    function __construct($doc, $filename, $sha1) {
        $this->filename = $filename;
        $this->sha1 = $sha1;
        $this->filestore = get($doc, "filestore");
        if (!isset($this->filestore))
            $this->content = get($doc, "content");
    }
}

class ZipDocument {
    public $filename;
    public $filestore;
    public $sha1; // NB: might be of _filestore, not of content!

    private $tmpdir_ = null;
    private $files;
    public $warnings;
    private $recurse;
    private $mimetype;
    private $headers;
    private $start_time;
    private $_filestore;
    private $_filestore_length;

    function __construct($filename, $mimetype = "application/zip") {
        $this->filename = $filename;
        $this->mimetype = $mimetype;
        $this->clean();
    }

    function clean() {
        $this->filestore = false;
        $this->sha1 = false;
        $this->tmpdir_ = null;
        $this->files = array();
        $this->warnings = array();
        $this->recurse = false;
        $this->headers = false;
        $this->start_time = time();
        $this->_filestore = array();
        $this->_filestore_length = 0;
    }

    private function tmpdir() {
        if ($this->tmpdir_ === null
            && ($this->tmpdir_ = tempdir()) === false)
            $this->warnings[] = "Could not create temporary directory.";
        return $this->tmpdir_;
    }

    private function _add($doc, $filename, $check_filename) {
        // maybe this is a warning container
        if (is_object($doc) && isset($doc->error) && $doc->error) {
            $this->warnings[] = (isset($doc->filename) ? $doc->filename . ": " : "")
                . (isset($doc->error_html) ? htmlspecialchars_decode($doc->error_html) : "Unknown error.");
            return;
        }

        // check filename
        if (!$filename && is_object($doc) && isset($doc->filename))
            $filename = $doc->filename;
        if (!$filename
            || ($check_filename
                && !preg_match(',\A[^.*/\s\000-\017\\\\\'"][^*/\000-\017\\\\\'"]*\z,', $filename))) {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }

        // load document
        if (is_string($doc))
            $doc = (object) array("content" => $doc);
        if (!isset($doc->filestore) && !isset($doc->content)) {
            if ($doc->docclass && $doc->docclass->load($doc))
                $doc->_content_reset = true;
            else {
                if (!isset($doc->error_text))
                    $this->warnings[] = "$filename: Couldn’t load document.";
                else if ($doc->error_text)
                    $this->warnings[] = $doc->error_text;
                return false;
            }
        }
        if (isset($doc->content) && !isset($doc->sha1))
            $doc->sha1 = sha1($doc->content, true);

        // add document to filestore list
        if (is_array($this->_filestore)
            && ($sha1 = Filer::binary_sha1($doc)) !== null
            && (isset($doc->filestore)
                || (isset($doc->content) && $doc->content !== ""
                    && strlen($doc->content) + $this->_filestore_length <= 4000000))) {
            $this->_filestore[] = new ZipDocument_File($doc, $filename, $sha1);
            if (!isset($doc->filestore))
                $this->_filestore_length += strlen($doc->content);
            return self::_add_done($doc, true);
        }

        // At this point, we will definitely create a new zipfile.

        // construct temporary directory
        if (!($tmpdir = $this->tmpdir()))
            return self::_add_done($doc, false);
        $zip_filename = "$tmpdir/";

        // populate with contents of filestore list, if any
        if (!$this->_add_filestore())
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
        else if (isset($doc->content)) {
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

    private function _add_filestore() {
        if (($filestore = $this->_filestore) !== null) {
            $this->_filestore = null;
            foreach ($filestore as $f)
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
        $this->sha1 = false;
        if (!empty($this->_filestore) && opt("docstore")
            && opt("docstoreAccelRedirect")) {
            // calculate sha1 for zipfile contents
            $sorted_filestore = $this->_filestore;
            usort($sorted_filestore, function ($a, $b) {
                return strcmp($a->filename, $b->filename);
            });
            $sha1_input = count($sorted_filestore) . "\n";
            foreach ($sorted_filestore as $f)
                $sha1_input .= $f->filename . "\n" . $f->sha1 . "\n";
            if (count($this->warnings))
                $sha1_input .= "README-warnings.txt\n" . join("\n", $this->warnings) . "\n";
            $zipfile_sha1 = sha1($sha1_input, false);
            // look for zipfile
            $zfn = opt("docstore") . "/tmp/" . $zipfile_sha1 . ".zip";
            if (Filer::prepare_filestore(opt("docstore"), $zfn)) {
                $this->sha1 = Filer::binary_sha1($zipfile_sha1);
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
        $this->_add_filestore();
        if (count($this->warnings))
            $this->add(join("\n", $this->warnings) . "\n", "README-warnings.txt");
        $opts = ($this->recurse ? "-rq" : "-q");
        set_time_limit(60);
        $out = system("cd $tmpdir; $zipcmd $opts " . escapeshellarg($this->filestore) . " " . join(" ", array_map("escapeshellarg", array_keys($this->files))) . " 2>&1", $status);
        if ($status == 0 && file_exists($this->filestore)) {
            // XXX do we really need the sha1?
            if ($this->sha1 === false) {
                // avoid file_get_contents in case the file doesn't fit in memory
                $hctx = hash_init("sha1");
                hash_update_file($hctx, $this->filestore);
                $this->sha1 = hash_final($hctx, true);
            }
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
        if (is_string(get($doc, "file")) && is_readable($doc->file))
            return $doc->file;
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
    function load(DocumentInfo $doc) {
        // Return true iff `$doc` can be loaded.
        if (!($has_content = self::has_content($doc))
            && ($fsinfo = $this->_filestore($doc, true))) {
            $doc->filestore = $fsinfo[1];
            $has_content = true;
        }
        return ($has_content && $this->validate_content($doc))
            || $this->load_content($doc);
    }
    function load_to_filestore(DocumentInfo $doc) {
        if (!$this->load($doc))
            return false;
        if (!isset($doc->filestore)) {
            if (!self::$tempdir && (self::$tempdir = tempdir()) == false) {
                set_error_html($doc, "Cannot create temporary directory.");
                return false;
            }
            $sha1 = self::text_sha1($doc);
            if ($sha1 === false)
                $sha1 = $doc->sha1 = sha1($doc->content);
            $path = self::$tempdir . "/" . $sha1 . Mimetype::extension($doc->mimetype);
            if (file_put_contents($path, $doc->content) != strlen($doc->content)) {
                set_error_html($doc, "Failed to save document to temporary file.");
                return false;
            }
            $doc->filestore = $path;
        }
        return true;
    }
    function load_to_memory(DocumentInfo $doc) {
        if (!$this->load($doc))
            return false;
        if (isset($doc->filestore) && !isset($doc->content)
            && ($content = @file_get_contents($doc->filestore)) !== false)
            $doc->content = $content;
        return isset($doc->content);
    }
    function store(DocumentInfo $doc) {
        // load content (if unloaded)
        // XXX loading enormous documents into memory...?
        if (!$this->load($doc)
            || ($content = self::content($doc)) === null
            || $content === false
            || get($doc, "error"))
            return false;
        // calculate SHA-1, complain on mismatch
        $sha1 = sha1($content, true);
        if (isset($doc->sha1) && $doc->sha1 !== false && $doc->sha1 !== ""
            && self::binary_sha1($doc) !== $sha1) {
            set_error_html($doc, "Document claims checksum " . self::text_sha1($doc) . ", but has checksum " . bin2hex($sha1) . ".");
            return false;
        }
        $doc->sha1 = $sha1;
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
        return !!$this->_filestore($doc, true);
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
        if (!($fsinfo = $this->_filestore($doc, false)))
            return false;
        list($fsdir, $fspath) = $fsinfo;
        if (!self::prepare_filestore($fsdir, $fspath)) {
            $no_error || set_error_html($doc, "Internal error: docstore cannot be initialized.");
            return false;
        }
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
            && (!get($doc, "docclass") || !$doc->docclass->load($doc))) {
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
        if ($doc->sha1)
            header("ETag: \"" . self::text_sha1($doc) . "\"");
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
        if (!is_object($doc)) {
            error_log(caller_landmark() . ": Filer::upload called with non-object");
            return false;
        }
        if (!$this->load($doc) && !get($doc, "error_html"))
            set_error_html($doc, "Empty document.");
        if (get($doc, "error"))
            return false;

        // Clean up mimetype and timestamp.
        if (!isset($doc->mimetype) && isset($doc->type) && is_string($doc->type))
            $doc->mimetype = $doc->type;
        $doc->mimetype = Mimetype::content_type(get($doc, "content"), get($doc, "mimetype"));
        if (($m = Mimetype::lookup($doc->mimetype)))
            $doc->mimetypeid = $m->mimetypeid;
        if (!get($doc, "timestamp"))
            $doc->timestamp = time();

        if ($this->validate_upload($doc))
            return $this->store($doc);
        else
            return false;
    }

    // SHA-1 helpers
    static function text_sha1($doc) {
        $sha1 = is_object($doc) ? get($doc, "sha1") : $doc;
        if (is_string($sha1) && strlen($sha1) > 40)
            $sha1 = trim($sha1);
        if (is_string($sha1) && strlen($sha1) === 20)
            return bin2hex($sha1);
        else if (is_string($sha1) && strlen($sha1) === 40 && ctype_xdigit($sha1))
            return strtolower($sha1);
        else {
            error_log("Filer::text_sha1: invalid input " . var_export($sha1, true) . ", caller " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            return false;
        }
    }
    static function binary_sha1($doc) {
        $sha1 = is_object($doc) ? get($doc, "sha1") : $doc;
        if (is_string($sha1) && strlen($sha1) > 40)
            $sha1 = trim($sha1);
        if (is_string($sha1) && strlen($sha1) === 20)
            return $sha1;
        else if (is_string($sha1) && strlen($sha1) === 40 && ctype_xdigit($sha1))
            return hex2bin($sha1);
        else {
            error_log("Filer::binary_sha1: invalid input " . var_export($sha1, true) . ", caller " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
            return false;
        }
    }

    // private functions
    private function _expand_filestore($pattern, DocumentInfo $doc, $extension) {
        $x = "";
        $sha1 = false;
        while (preg_match('/\A(.*?)%(\d*)([%hx])(.*)\z/', $pattern, $m)) {
            $x .= $m[1];
            if ($m[3] === "%")
                $x .= "%";
            else if ($m[3] === "x") {
                if ($extension)
                    $x .= Mimetype::extension($doc->mimetype);
            } else {
                if ($sha1 === false)
                    $sha1 = self::text_sha1($doc);
                if ($sha1 === false
                    && ($content = self::content($doc)) !== false)
                    $sha1 = $doc->sha1 = sha1($content);
                if ($sha1 === false)
                    return false;
                if ($m[2] !== "")
                    $x .= substr($sha1, 0, intval($m[2]));
                else
                    $x .= $sha1;
            }
            $pattern = $m[4];
        }
        return $x . $pattern;
    }
    private function _filestore(DocumentInfo $doc, $for_reading) {
        if (!($fsinfo = $this->filestore_pattern($doc)))
            return $fsinfo;
        else if ($doc->error)
            return null;
        list($fdir, $fpath) = $fsinfo;
        if ($fdir && $fdir[strlen($fdir) - 1] === "/")
            $fdir = substr($fdir, 0, strlen($fdir) - 1);
        $pattern = substr($fpath, strlen($fdir));
        if (!($f = $this->_expand_filestore($pattern, $doc, true)))
            return null;
        if ($for_reading && !is_readable($fdir . $f)) {
            // clean up presence of old files saved w/o extension
            $g = $this->_expand_filestore($pattern, $doc, false);
            if ($f && $g !== $f && is_readable($fdir . $g)) {
                if (!@rename($fdir . $g, $fdir . $f))
                    $f = $g;
            } else
                return null;
        }
        return [$fdir, $fdir . $f];
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

    function archive_listing(DocumentInfo $doc) {
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
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($err != "")
            $err = preg_replace('/^tar: Ignoring unknown[^\n]*\n*/m', '', $err);
        if ($status != 0 || $err != "")
            error_log("$cmd problem: status $status, stderr $err");
        return explode("\n", rtrim($out));
    }

    function clean_archive_listing($listing) {
        $etcetera = false;
        $listing = array_filter($listing, function ($x) use (&$etcetera) {
            if (str_ends_with($x, "/"))
                return false;
            else if (preg_match('@(?:\A|/)(?:\A__MACOSX|\._.*|\.DS_Store|\.svn|\.git|.*~\z|\A…\z)(?:/|\z)@', $x)) {
                $etcetera = true;
                return false;
            } else
                return true;
        });
        natcasesort($listing);
        $listing = array_values($listing);
        if ($etcetera)
            $listing[] = "…";
        return $listing;
    }

    function consolidate_archive_listing($listing) {
        $new_listing = [];
        $etcetera = empty($listing) || $listing[count($listing) - 1] !== "…" ? 0 : 1;
        for ($i = 0; $i < count($listing) - $etcetera; ) {
            if (($slash = strpos($listing[$i], "/")) !== false) {
                $prefix = substr($listing[$i], 0, $slash + 1);
                for ($j = $i + 1; $j < count($listing) && str_starts_with($listing[$j], $prefix); ++$j)
                    /* nada */;
                if ($j > $i + 1) {
                    $xlisting = [];
                    for ($k = $i; $k < $j; ++$k)
                        $xlisting[] = substr($listing[$k], $slash + 1);
                    $xlisting = $this->consolidate_archive_listing($xlisting);
                    if (count($xlisting) == 1)
                        $new_listing[] = $prefix . $xlisting[0];
                    else
                        $new_listing[] = $prefix . "{" . join(", ", $xlisting) . "}";
                    $i = $j;
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
