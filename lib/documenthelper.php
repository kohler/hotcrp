<?php
// documenthelper.php -- generic document helper class
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ZipDocument {

    private $tmpdir;
    private $files;
    public $warnings;
    private $recurse;
    private $downloadname;
    private $mimetype;
    private $headers;

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
    }

    static private function _add_done($doc, $result) {
        if (@$doc->_content_reset)
            unset($doc->content, $doc->_content_reset);
        return $result;
    }

    private function _add($doc, $filename, $check_filename) {
        if ($this->tmpdir === null)
            if (($this->tmpdir = tempdir()) === false) {
                $this->warnings[] = "Could not create temporary directory.";
                return false;
            }
        if (!$filename && is_object($doc) && isset($doc->filename))
            $filename = $doc->filename;
        if (!$filename
            || ($check_filename
                && !preg_match(',\A[^.*/\s\000-\017\\\\\'"][^*/\000-\017\\\\\'"]*\z,', $filename))) {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }
        if (is_string($doc))
            $doc = (object) array("content" => $doc);
        else if (!isset($doc->filestore) && !isset($doc->content)) {
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
        $fn = $filename;
        $zip_filename = "$this->tmpdir/";
        while (($p = strpos($fn, "/")) !== false) {
            $zip_filename .= substr($fn, 0, $p);
            if (!is_dir($zip_filename)
                && (file_exists($zip_filename) || !@mkdir($zip_filename, 0777))) {
                $this->warnings[] = "$filename: Couldn’t save document to this name.";
                error_log(join(" ", $this->warnings));
                return self::_add_done($doc, false);
            }
            $zip_filename .= "/";
            $fn = substr($fn, $p + 1);
        }
        if ($fn == "") {
            $this->warnings[] = "$filename: Bad filename.";
            return self::_add_done($doc, false);
        }
        $zip_filename .= $fn;
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
        $zip_filename = substr($zip_filename, strlen($this->tmpdir) + 1);
        if (($p = strpos($zip_filename, "/")) !== false) {
            $zip_filename = substr($zip_filename, 0, $p);
            $this->recurse = true;
        }
        $this->files[$zip_filename] = true;
        return self::_add_done($doc, true);
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
        global $Opt;
        if (!($zipcmd = defval($Opt, "zipCommand", "zip")))
            return set_error_html("<code>zip</code> is not supported on this installation.");
        if (count($this->warnings))
            $this->add(join("\n", $this->warnings) . "\n", "README-warnings.txt");
        $opts = ($this->recurse ? "-rq" : "-q");
        $out = system("cd $this->tmpdir; $zipcmd $opts _hotcrp.zip '" . join("' '", array_keys($this->files)) . "' 2>&1", $status);
        if ($status != 0)
            return set_error_html("<code>zip</code> returned an error.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        if (!file_exists("$this->tmpdir/_hotcrp.zip"))
            return set_error_html("<code>zip</code> output unreadable or empty.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        return "$this->tmpdir/_hotcrp.zip";
    }

    public function download() {
        global $Opt, $zlib_output_compression;
        $result = $this->create();
        if (is_string($result)) {
            $this->download_headers();
            if (!$zlib_output_compression)
                header("Content-Length: " . filesize($result));
            // flush all output buffers to avoid holding large files in memory
            flush();
            while (@ob_end_flush())
                /* do nothing */;
            readfile($result);
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

        $a = array();
        foreach ($cols as $k => $v)
            if ($k != $idcol)
                $a[] = "`" . $k . "`='" . sqlq(substr($v, 0, $N)) . "'";

        if (isset($cols[$idcol]))
            $q = "update $table set " . join(",", $a) . " where $idcol='" . sqlq($cols[$idcol]);
        else
            $q = "insert into $table set " . join(",", $a);
        if (!($result = $Conf->q($q))) {
            set_error_html($doc, $Conf->db_error_html(true, $while));
            return;
        }

        if (isset($cols[$idcol]))
            $doc->$idcol = $cols[$idcol];
        else {
            $doc->$idcol = $Conf->lastInsertId(false);
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
            && (!($result = $Conf->qe("select length($check_contents) from $table where $idcol=" . $doc->$idcol, $while))
                || !($row = edb_row($result))
                || $row[0] != strlen($doc->content))) {
            set_error_html($doc, "Failed to store your document. Usually, this is because the file you tried to upload was too big for our system. Please try again.");
            return;
        }
    }

    private static function _mimetype($doc) {
        return (isset($doc->mimetype) ? $doc->mimetype : $doc->mimetypeid);
    }

    private static function _expand_filestore($fsinfo, $doc) {
        list($fdir, $fpath) = $fsinfo;
        $sha1 = null;

        $xfpath = $fdir;
        $fpath = substr($fpath, strlen($fdir));
        while (preg_match("/\\A(.*?)%(\d*)([%hx])(.*)\\z/", $fpath, $m)) {
            $fpath = $m[4];

            $xfpath .= $m[1];
            if ($m[3] == "%")
                $xfpath .= "%";
            else if ($m[3] == "x")
                $xfpath .= Mimetype::extension(self::_mimetype($doc));
            else {
                if (!$sha1) {
                    $sha1 = bin2hex($doc->sha1);
                    if (strlen($sha1) != 40)
                        return array(null, null);
                }
                if ($m[2] != "")
                    $xfpath .= substr($sha1, 0, +$m[2]);
                else
                    $xfpath .= $sha1;
            }
        }

        if ($fdir && $fdir[strlen($fdir) - 1] == "/")
            $fdir = substr($fdir, 0, strlen($fdir) - 1);
        return array($fdir, $xfpath . $fpath);
    }

    private static function _store_filestore($fsinfo, $doc) {
        list($fdir, $fpath) = $fsinfo;

        if (!is_dir($fdir) && !@mkdir($fdir, 0700)) {
            @rmdir($fdir);
            return false;
        }

        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "$fdir/.htaccess";
        if (!is_file($htaccess)
            && file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink("$fdir/.htaccess");
            return false;
        }

        // Create subdirectory
        $pos = strlen($fdir) + 1;
        while ($pos < strlen($fpath)
               && ($pos = strpos($fpath, "/", $pos)) !== false) {
            $superdir = substr($fpath, 0, $pos);
            if (!is_dir($superdir) && !@mkdir($superdir, 0770))
                return false;
            ++$pos;
        }

        // Write contents
        if (file_put_contents($fpath, $doc->content) != strlen($doc->content)) {
            @unlink($fpath);
            return false;
        }
        @chmod($fpath, 0660 & ~umask());
        $doc->filestore = $fpath;
        return true;
    }

    static function store($docclass, $doc, $docinfo) {
        if (!isset($doc->size) && isset($doc->content))
            $doc->size = strlen($doc->content);
        if (!isset($doc->sha1) && isset($doc->content))
            $doc->sha1 = sha1($doc->content, true);
        $docclass->prepare_storage($doc, $docinfo);
        if (($dbinfo = $docclass->database_storage($doc, $docinfo)))
            self::_store_database($dbinfo, $doc);
        if (($fsinfo = $docclass->filestore_pattern($doc, $docinfo))) {
            $fsinfo = self::_expand_filestore($fsinfo, $doc);
            if (!self::_store_filestore($fsinfo, $doc) && !$dbinfo)
                set_error_html($doc, "Internal error: could not store document.");
        }
        return $doc;
    }

    static function file_upload_json($uploadId) {
        global $Now;
        $doc = (object) array();

        if (!$uploadId
            || !fileUploaded($_FILES[$uploadId])
            || !isset($_FILES[$uploadId]["tmp_name"]))
            return set_error_html($doc, "Upload error. Please try again.");

        // prepare document
        $doc->content = file_get_contents($_FILES[$uploadId]["tmp_name"]);
        if ($doc->content === false || strlen($doc->content) == 0)
            return set_error_html($doc, "The uploaded file was empty. Please try again.");

        if (isset($_FILES[$uploadId]["name"])
            && strlen($_FILES[$uploadId]["name"]) <= 255
            && is_valid_utf8($_FILES[$uploadId]["name"]))
            $doc->filename = $_FILES[$uploadId]["name"];
        else
            $doc->filename = null;

        $doc->mimetype = Mimetype::type(defval($_FILES[$uploadId], "type", "application/octet-stream"));

        $doc->timestamp = time();
        return $doc;
    }

    static function upload($docclass, $upload, $docinfo) {
        global $Conf, $Opt, $OK;
        if (is_object($upload)) {
            $doc = $upload;
            if (@$doc->content === null || $doc->content === "")
                set_error_html($doc, "The uploaded file was empty.");
        } else
            $doc = self::file_upload_json($upload);
        if (@$doc->error)
            return $doc;

        // Check if paper one of the allowed mimetypes.
        if (!@$doc->mimetype)
            $doc->mimetype = "application/octet-stream";
        // Sniff content since MacOS browsers supply bad mimetypes.
        if (strncmp("%PDF-", $doc->content, 5) == 0)
            $doc->mimetype = Mimetype::type("pdf");
        else if (strncmp("%!PS-", $doc->content, 5) == 0)
            $doc->mimetype = Mimetype::type("ps");
        else if (substr($doc->content, 512, 4) == "\x00\x6E\x1E\xF0")
            $doc->mimetype = Mimetype::type("ppt");
        if (($m = Mimetype::lookup($doc->mimetype)))
            $doc->mimetypeid = $m->mimetypeid;

        $mimetypes = $docclass->mimetypes($doc, $docinfo);
        for ($i = 0; $i < count($mimetypes); ++$i)
            if ($mimetypes[$i]->mimetype == $doc->mimetype)
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

    static function load($docclass, $doc) {
        $fsinfo = $docclass->filestore_pattern($doc, null);
        if ($fsinfo) {
            $fsinfo = self::_expand_filestore($fsinfo, $doc);
            if (is_readable($fsinfo[1])) {
                $doc->filestore = $fsinfo[1];
                return true;
            }
        }
        if (!isset($doc->content) && !$docclass->load_content($doc))
            return false;
        if ($fsinfo)
            self::_store_filestore($fsinfo, $doc);
        return true;
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
        if (isset($doc->filestore)) {
            if (!$zlib_output_compression)
                header("Content-Length: " . filesize($doc->filestore));
            flush();
            while (@ob_end_flush())
                /* do nothing */;
            readfile($doc->filestore);
        } else {
            if (!$zlib_output_compression)
                header("Content-Length: " . strlen($doc->content));
            echo $doc->content;
        }
        return (object) array("error" => false);
    }

}
