<?php
// documenthelper.php -- generic document helper class
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ZipDocumentFile {
    public $filename;
    public $filestore;
    public $sha1;
    function __construct($filename, $filestore, $sha1) {
        $this->filename = $filename;
        $this->filestore = $filestore;
        $this->sha1 = $sha1;
    }
}

class ZipDocument {
    private $tmpdir;
    private $files;
    public $warnings;
    private $recurse;
    private $downloadname;
    private $mimetype;
    private $headers;
    private $start_time;
    private $filestore;

    function __construct($downloadname, $mimetype = "application/zip") {
        $this->tmpdir = null;
        $this->downloadname = $downloadname;
        $this->mimetype = $mimetype;
        $this->clean();
    }

    function clean() {
        if ($this->tmpdir) {
            exec("/bin/rm -rf $this->tmpdir");
            $this->tmpdir = null;
        }
        $this->files = array();
        $this->warnings = array();
        $this->recurse = false;
        $this->headers = false;
        $this->start_time = time();
        $this->filestore = array();
    }

    private function _add($doc, $filename, $check_filename) {
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
            if ($doc->docclass && DocumentHelper::load($doc->docclass, $doc))
                $doc->_content_reset = true;
            else {
                if (!isset($doc->error_text))
                    $this->warnings[] = "$filename: Couldn’t load document.";
                else if ($doc->error_text)
                    $this->warnings[] = $doc->error_text;
                return false;
            }
        }

        // add document to filestore list
        if (is_array($this->filestore) && isset($doc->filestore)
            && ($sha1 = DocumentHelper::binary_sha1($doc)) !== null) {
            $this->filestore[] = new ZipDocumentFile($filename, $doc->filestore, $sha1);
            return self::_add_done($doc, true);
        }

        // At this point, we will definitely create a new zipfile.

        // construct temporary directory
        if ($this->tmpdir === null)
            if (($this->tmpdir = tempdir()) === false) {
                $this->warnings[] = "Could not create temporary directory.";
                return self::_add_done($doc, false);
            }
        $zip_filename = "$this->tmpdir/";

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
        $zip_filename = substr($zip_filename, strlen($this->tmpdir) + 1);
        if (($p = strpos($zip_filename, "/")) !== false) {
            $zip_filename = substr($zip_filename, 0, $p);
            $this->recurse = true;
        }
        $this->files[$zip_filename] = true;

        // complete
        if (time() - $this->start_time >= 0) {
            set_time_limit(30);
            if (!$this->headers) {
                $this->download_headers();
                DocumentHelper::hyperflush();
            }
        }
        return self::_add_done($doc, true);
    }

    static private function _add_done($doc, $result) {
        if (@$doc->_content_reset)
            unset($doc->content, $doc->_content_reset);
        return $result;
    }

    private function _add_filestore() {
        if (($filestore = $this->filestore) !== null) {
            $this->filestore = null;
            foreach ($filestore as $f)
                if (!$this->_add($f, $f->filename, false))
                    return false;
        }
        return true;
    }

    public function add($doc, $filename = null) {
        return $this->_add($doc, $filename, true);
    }

    public function add_as($doc, $filename) {
        return $this->_add($doc, $filename, false);
    }

    public function download_headers() {
        if (!$this->headers) {
            header("Content-Disposition: attachment; filename=" . mime_quote_string($this->downloadname));
            header("Content-Type: " . $this->mimetype);
            $this->headers = true;
        }
    }

    private function create() {
        global $Now, $Opt;

        // maybe cache zipfile in docstore
        $zip_filename = "$this->tmpdir/_hotcrp.zip";
        if (count($this->filestore) > 0 && @$Opt["docstore"]
            && @$Opt["docstoreAccelRedirect"]) {
            // calculate sha1 for zipfile contents
            usort($this->filestore, function ($a, $b) {
                return strcmp($a->filename, $b->filename);
            });
            $sha1_input = count($this->filestore) . "\n";
            foreach ($this->filestore as $f)
                $sha1_input .= $f->filename . "\n" . $f->sha1 . "\n";
            if (count($this->warnings))
                $sha1_input .= "README-warnings.txt\n" . join("\n", $this->warnings) . "\n";
            $zipfile_sha1 = sha1($sha1_input, false);
            // look for zipfile
            $zfn = $Opt["docstore"] . "/tmp/" . $zipfile_sha1 . ".zip";
            if (DocumentHelper::prepare_docstore($Opt["docstore"], $zfn)) {
                if (file_exists($zfn)) {
                    if (($mtime = @filemtime($zfn)) < $Now - 21600)
                        @touch($zfn);
                    return $zfn;
                }
                $zip_filename = $zfn;
            }
        }

        // actually run zip
        if (!($zipcmd = defval($Opt, "zipCommand", "zip")))
            return set_error_html("<code>zip</code> is not supported on this installation.");
        $this->_add_filestore();
        if (count($this->warnings))
            $this->add(join("\n", $this->warnings) . "\n", "README-warnings.txt");
        $opts = ($this->recurse ? "-rq" : "-q");
        set_time_limit(60);
        $out = system("cd $this->tmpdir; $zipcmd $opts '$zip_filename' '" . join("' '", array_keys($this->files)) . "' 2>&1", $status);
        if ($status != 0)
            return set_error_html("<code>zip</code> returned an error.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        if (!file_exists($zip_filename))
            return set_error_html("<code>zip</code> output unreadable or empty.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        return $zip_filename;
    }

    public function download() {
        global $Opt, $zlib_output_compression;
        $result = $this->create();
        if (is_string($result)) {
            set_time_limit(180); // large zip files might download slowly
            $this->download_headers();
            DocumentHelper::download_file($result);
            $result = (object) array("error" => false);
        }
        $this->clean();
        return $result;
    }
}

class DocumentHelper {

    private static function _store_database($dbinfo, $doc) {
        global $Conf, $OK;
        $N = 400000;
        $dbinfo[] = null;
        list($table, $idcol, $cols, $check_contents) = $dbinfo;
        $while = "while storing document in database";

        $a = $ks = $vs = array();
        foreach ($cols as $k => $v)
            if ($k !== $idcol) {
                $ks[] = "`$k`=?";
                $vs[] = substr($v, 0, $N);
            }

        if (isset($cols[$idcol])) {
            $q = "update $table set " . join(",", $ks) . " where $idcol=?";
            $vs[] = $cols[$idcol];
        } else
            $q = "insert into $table set " . join(",", $ks);
        if (!($result = Dbl::query_apply($q, $vs))) {
            set_error_html($doc, $Conf->db_error_html(true, $while));
            return;
        }

        if (isset($cols[$idcol]))
            $doc->$idcol = $cols[$idcol];
        else {
            $doc->$idcol = $result->insert_id;
            if (!$doc->$idcol) {
                set_error_html($doc, $Conf->db_error_html(true, $while));
                $OK = false;
                return;
            }
        }

        for ($pos = $N; true; $pos += $N) {
            $a = array();
            foreach ($cols as $k => $v)
                if (strlen($v) > $pos)
                    $a[] = "`" . $k . "`=concat(`" . $k . "`,'" . sqlq(substr($v, $pos, $N)) . "')";
            if (!count($a))
                break;
            if (!$Conf->q("update $table set " . join(",", $a) . " where $idcol=" . $doc->$idcol)) {
                set_error_html($doc, $Conf->db_error_html(true, $while));
                return;
            }
        }

        // check that paper storage succeeded
        if ($check_contents
            && (!($result = $Conf->qe("select length($check_contents) from $table where $idcol=" . $doc->$idcol))
                || !($row = edb_row($result))
                || $row[0] != strlen($doc->content))) {
            set_error_html($doc, "Failed to store your document. Usually, this is because the file you tried to upload was too big for our system. Please try again.");
            return;
        }
    }

    private static function _mimetype($doc) {
        return (isset($doc->mimetype) ? $doc->mimetype : $doc->mimetypeid);
    }

    public static function binary_sha1($doc) {
        if (is_string(@$doc->sha1) && strlen($doc->sha1) === 20)
            return $doc->sha1;
        else if (is_string(@$doc->sha1) && strlen($doc->sha1) === 40 && ctype_xdigit($doc->sha1))
            return hex2bin($doc->sha1);
        else
            return null;
    }

    public static function text_sha1($doc) {
        if (is_string(@$doc->sha1) && strlen($doc->sha1) === 20)
            return bin2hex($doc->sha1);
        else if (is_string(@$doc->sha1) && strlen($doc->sha1) === 40 && ctype_xdigit($doc->sha1))
            return strtolower($doc->sha1);
        else
            return null;
    }

    private static function _filestore($docclass, $doc, $docinfo) {
        $fsinfo = $docclass->filestore_pattern($doc, $docinfo);
        if (!$fsinfo)
            return $fsinfo;

        list($fdir, $fpath) = $fsinfo;
        $sha1 = null;

        $xfpath = $fdir;
        $fpath = substr($fpath, strlen($fdir));
        while (preg_match("/\\A(.*?)%(\d*)([%hx])(.*)\\z/", $fpath, $m)) {
            $fpath = $m[4];

            $xfpath .= $m[1];
            if ($m[3] === "%")
                $xfpath .= "%";
            else if ($m[3] === "x")
                $xfpath .= Mimetype::extension(self::_mimetype($doc));
            else {
                if ($sha1 === null
                    && ($sha1 = self::text_sha1($doc)) === null)
                    return array(null, null);
                if ($m[2] !== "")
                    $xfpath .= substr($sha1, 0, +$m[2]);
                else
                    $xfpath .= $sha1;
            }
        }

        if ($fdir && $fdir[strlen($fdir) - 1] === "/")
            $fdir = substr($fdir, 0, strlen($fdir) - 1);
        return array($fdir, $xfpath . $fpath);
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

    public static function prepare_docstore($parent, $path) {
        if (!self::_make_fpath_parents($parent, $path))
            return false;
        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "$parent/.htaccess";
        if (!is_file($htaccess)
            && file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink($htaccess);
            return false;
        }
        return true;
    }

    private static function _store_filestore($fsinfo, $doc) {
        list($fdir, $fpath) = $fsinfo;
        if (!self::prepare_docstore($fdir, $fpath))
            return false;
        if (file_put_contents($fpath, $doc->content) != strlen($doc->content)) {
            @unlink($fpath);
            return false;
        }
        @chmod($fpath, 0660 & ~umask());
        $doc->filestore = $fpath;
        return true;
    }

    static function prepare_content($docclass, $doc) {
        $filename = @$doc->content_file ? : (@$doc->file ? : @$doc->filestore);
        if ((@$doc->content === null || @$doc->content === false)
            && @$doc->content_base64)
            $doc->content = base64_decode($doc->content_base64);
        if ((@$doc->content === null || @$doc->content === false)
            && $filename)
            $doc->content = @file_get_contents($filename);
        if (@$doc->content === null || @$doc->content === false)
            $docclass->load_content($doc);
        if ((@$doc->content === null || @$doc->content === false)
            && $filename)
            set_error_html($doc, "File $filename not readable.");
    }

    static function store($docclass, $doc, $docinfo) {
        // load data (if unloaded)
        self::prepare_content($docclass, $doc);
        // calculate SHA-1, complain on mismatch
        if (@$doc->content) {
            $sha1 = sha1($doc->content, true);
            if (isset($doc->sha1) && self::binary_sha1($doc) !== $sha1)
                set_error_html($doc, "Document claims checksum " . self::text_sha1($doc) . ", but has checksum " . bin2hex($sha1) . ".");
            $doc->sha1 = $sha1;
        }
        // exit on error
        if (@$doc->error)
            return $doc;
        if (!isset($doc->size) && isset($doc->content))
            $doc->size = strlen($doc->content);
        $docclass->prepare_storage($doc, $docinfo);
        if (($dbinfo = $docclass->database_storage($doc, $docinfo)))
            self::_store_database($dbinfo, $doc);
        if (($fsinfo = self::_filestore($docclass, $doc, $docinfo))) {
            if (!self::_store_filestore($fsinfo, $doc) && !$dbinfo)
                set_error_html($doc, "Internal error: could not store document.");
        }
        return $doc;
    }

    static function file_upload_json($upload) {
        global $Now;
        $doc = (object) array();

        if (is_string($upload) && $upload)
            $upload = $_FILES[$upload];
        if (!$upload || !is_array($upload) || !fileUploaded($upload)
            || !isset($upload["tmp_name"]))
            return set_error_html($doc, "Upload error. Please try again.");

        // prepare document
        $doc->content = file_get_contents($upload["tmp_name"]);
        if ($doc->content === false || strlen($doc->content) == 0)
            return set_error_html($doc, "The uploaded file was empty. Please try again.");

        if (isset($upload["name"])
            && strlen($upload["name"]) <= 255
            && is_valid_utf8($upload["name"]))
            $doc->filename = $upload["name"];
        else
            $doc->filename = null;

        $doc->mimetype = Mimetype::type(defval($upload, "type", "application/octet-stream"));

        $doc->timestamp = time();
        return $doc;
    }

    static function upload($docclass, $upload, $docinfo) {
        global $Conf, $Opt;
        if (is_object($upload)) {
            $doc = clone $upload;
            self::prepare_content($docclass, $doc);
            if ((@$doc->content === null || $doc->content === false || $doc->content === "")
                && !@$doc->error_html)
                set_error_html($doc, "The uploaded file was empty.");
        } else
            $doc = self::file_upload_json($upload);
        if (@$doc->error)
            return $doc;

        // Check if paper one of the allowed mimetypes.
        if (!@$doc->mimetype)
            $doc->mimetype = "application/octet-stream";
        // Sniff content since MacOS browsers supply bad mimetypes.
        if (($m = Mimetype::sniff($doc->content)))
            $doc->mimetype = $m;
        if (($m = Mimetype::lookup($doc->mimetype)))
            $doc->mimetypeid = $m->mimetypeid;

        $mimetypes = $docclass->mimetypes($doc, $docinfo);
        for ($i = 0; $i < count($mimetypes); ++$i)
            if ($mimetypes[$i]->mimetype === $doc->mimetype)
                break;
        if ($i >= count($mimetypes) && count($mimetypes)) {
            $e = "I only accept " . htmlspecialchars(Mimetype::description($mimetypes)) . " files.";
            $e .= " (Your file has MIME type “" . htmlspecialchars($doc->mimetype) . "” and starts with “" . htmlspecialchars(substr($doc->content, 0, 5)) . "”.)<br />Please convert your file to a supported type and try again.";
            return set_error_html($doc, $e);
        }

        if (!@$doc->timestamp)
            $doc->timestamp = time();
        return self::store($docclass, $doc, $docinfo);
    }

    static function filestore_check($docclass, $doc) {
        $fsinfo = self::_filestore($docclass, $doc, null);
        return $fsinfo && is_readable($fsinfo[1]);
    }

    static function load($docclass, $doc) {
        if (is_string(@$doc->content)
            || is_string(@$doc->content_base64)
            || (is_string(@$doc->content_file) && is_readable($doc->content_file))
            || (is_string(@$doc->file) && is_readable($doc->file))
            || (is_string(@$doc->filestore) && is_readable($doc->filestore)))
            return true;
        $fsinfo = self::_filestore($docclass, $doc, null);
        if ($fsinfo && is_readable($fsinfo[1])) {
            $doc->filestore = $fsinfo[1];
            return true;
        }
        if (!isset($doc->content) && !$docclass->load_content($doc))
            return false;
        if ($fsinfo)
            self::_store_filestore($fsinfo, $doc);
        return true;
    }

    static function hyperflush() {
        flush();
        while (@ob_end_flush())
            /* do nothing */;
    }

    static function download_file($filename) {
        global $Opt, $zlib_output_compression;
        if (($dar = @$Opt["docstoreAccelRedirect"]) && ($ds = @$Opt["docstore"])) {
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
        if (!$zlib_output_compression)
            header("Content-Length: " . filesize($filename));
        self::hyperflush();
        readfile($filename);
    }

    static function download($doc, $downloadname = null, $attachment = null) {
        global $zlib_output_compression;
        if (is_array($doc) && count($doc) == 1) {
            $doc = $doc[0];
            $downloadname = null;
        }
        if (!$doc || (is_object($doc) && isset($doc->size) && $doc->size == 0))
            return set_error_html("Empty file.");
        else if (is_array($doc)) {
            $z = new ZipDocument($downloadname);
            foreach ($doc as $d)
                $z->add($d);
            return $z->download();
        }
        if (!isset($doc->filestore) && !isset($doc->content)
            && (!@$doc->docclass || !DocumentHelper::load($doc->docclass, $doc)))
            return set_error_html("Don’t know how to download.");

        // Print paper
        $doc_mimetype = self::_mimetype($doc);
        header("Content-Type: " . Mimetype::type($doc_mimetype));
        if ($attachment === null)
            $attachment = !Mimetype::disposition_inline($doc_mimetype);
        if (!$downloadname) {
            $downloadname = $doc->filename;
            if (($slash = strrpos($downloadname, "/")) !== false)
                $downloadname = substr($downloadname, $slash + 1);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
        if (isset($doc->filestore))
            self::download_file($doc->filestore);
        else {
            if (!$zlib_output_compression)
                header("Content-Length: " . strlen($doc->content));
            echo $doc->content;
        }
        return (object) array("error" => false);
    }

}
