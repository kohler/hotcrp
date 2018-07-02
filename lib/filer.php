<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ZipDocument {
    private $conf;
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
        global $Conf;
        $this->conf = $Conf;
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

    private function _add($doc, $filename, $check_filename) {
        if (is_string($doc))
            $doc = new DocumentInfo(["content" => $doc]);
        assert($doc instanceof DocumentInfo);

        if ($filename == "" && $doc->filename != "")
            $filename = $doc->filename;

        // maybe this is a warning container
        if ($doc->error || !$doc->ensure_content()) {
            $this->warnings[] = "$filename: " . ($doc->error_html ? htmlspecialchars_decode($doc->error_html) : "Unknown error.");
            return false;
        }

        // check filename
        if ($filename == ""
            || ($check_filename
                && !preg_match(',\A[^.*/\s\000-\017\\\\\'"][^*/\000-\017\\\\\'"]*\z,', $filename))) {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }

        // add document to filestore list
        if (is_array($this->_pending) && $doc->binary_hash() !== false) {
            if (($path = $doc->available_content_file())) {
                $this->_pending[] = new DocumentInfo(["filename" => $filename, "content_file" => $path, "sha1" => $doc->binary_hash()]);
                return true;
            } else if (strlen($doc->content) + $this->_pending_memory <= 4000000) {
                $this->_pending[] = new DocumentInfo(["filename" => $filename, "content" => $doc->content, "sha1" => $doc->binary_hash()]);
                $this->_pending_memory += strlen($doc->content);
                return true;
            }
        }

        // At this point, we will definitely create a new zipfile.

        // construct temporary directory
        if (!($tmpdir = $this->tmpdir()))
            return false;
        $zip_filename = "$tmpdir/";

        // populate with pending contents, if any
        if (!$this->_resolve_pending())
            return false;

        // construct subdirectories
        $fn = $filename;
        while (($p = strpos($fn, "/")) !== false) {
            $zip_filename .= substr($fn, 0, $p);
            if (!is_dir($zip_filename)
                && (file_exists($zip_filename) || !@mkdir($zip_filename, 0777))) {
                $this->warnings[] = "$filename: Couldn’t save document to this name.";
                return false;
            }
            $zip_filename .= "/";
            $fn = substr($fn, $p + 1);
        }
        if ($fn === "") {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }
        $zip_filename .= $fn;

        // store file in temporary directory
        if (($path = $doc->available_content_file())
            && @symlink($path, $zip_filename))
            /* OK */;
        else {
            $trylen = file_put_contents($zip_filename, $doc->content);
            if ($trylen != strlen($doc->content)) {
                clean_tempdirs();
                $trylen = file_put_contents($zip_filename, $doc->content);
            }
            if ($trylen != strlen($doc->content)) {
                $this->warnings[] = "$filename: Could not save.";
                return false;
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
        return true;
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
        if (!empty($this->_pending)
            && $this->conf->opt("docstore")
            && $this->conf->opt("docstoreAccelRedirect")) {
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
            $dstore_prefix = Filer::docstore_fixed_prefix($this->conf->opt("docstore"));
            $zfn = $dstore_prefix . "tmp/" . $this->sha1 . ".zip";
            if (Filer::prepare_docstore($dstore_prefix, $zfn)) {
                $this->filestore = $zfn;
                if (file_exists($this->filestore)) {
                    if (($mtime = @filemtime($zfn)) < $Now - 21600)
                        @touch($this->filestore);
                    return $this->filestore;
                }
            }
        }

        // actually run zip
        if (!($zipcmd = $this->conf->opt("zipCommand", "zip")))
            return set_error_html("<code>zip</code> is not supported on this installation.");
        $this->_resolve_pending();
        if (count($this->warnings))
            $this->add_as(join("\n", $this->warnings) . "\n", "README-warnings.txt");
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

class Filer {
    static public $tempdir;
    static public $tempcounter = 0;

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

        if (!$doc->ensure_content()) {
            $error_html = "Don’t know how to download.";
            if ($doc->error && isset($doc->error_html))
                $error_html = $doc->error_html;
            else if ($doc->error && isset($doc->error_text))
                $error_html = htmlspecialchars($doc->error_text);
            return set_error_html($error_html);
        }

        // Print paper
        header("Content-Type: " . Mimetype::type_with_charset($doc->mimetype));
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
        if (($path = $doc->available_content_file()))
            self::download_file($path, get($doc, "no_cache") || get($doc, "no_accel"));
        else {
            if (!$zlib_output_compression)
                header("Content-Length: " . strlen($doc->content));
            echo $doc->content;
        }
        return (object) array("error" => false);
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

    // filestore path functions
    const FPATH_EXISTS = 1;
    const FPATH_MKDIR = 2;
    static function docstore_path(DocumentInfo $doc, $flags = 0) {
        global $Now;
        if ($doc->error || !($pattern = $doc->conf->docstore()))
            return null;
        if (!($path = self::_expand_docstore($pattern, $doc, true)))
            return null;
        if ($flags & self::FPATH_EXISTS) {
            if (!is_readable($path)) {
                // clean up presence of old files saved w/o extension
                $g = self::_expand_docstore($pattern, $doc, false);
                if ($path && $g !== $path && is_readable($g)) {
                    if (!@rename($g, $path))
                        $path = $g;
                } else
                    return null;
            }
            if (filemtime($path) < $Now - 172800)
                touch($path, $Now);
        }
        if (($flags & self::FPATH_MKDIR)
            && !self::prepare_docstore(self::docstore_fixed_prefix($pattern), $path))
            return $doc->add_error_html("File system storage cannot be initialized.", true);
        return $path;
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

    static function prepare_docstore($parent, $path) {
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

    static private function _expand_docstore($pattern, DocumentInfo $doc, $extension) {
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

    static private function _make_fpath_parents($fdir, $fpath) {
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
}
