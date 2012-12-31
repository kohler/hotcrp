<?php
// documents.inc -- HotCRP document helper class
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfFilestore;
$ConfFilestore = null;

class HotCRPDocument {

    var $type;
    var $option;

    function __construct($document_type, $option = null) {
        $this->type = $document_type;
        if ($option)
            $this->option = $option;
        else if ($this->type > 0)
            $this->option = paperOptions($document_type);
        else
            $this->option = null;
    }

    function mimetypes($doc = null, $docinfo = null) {
        global $Opt;
        require_once("paperoption.inc");
        if ($this->type > 0 && !$this->option)
            return null;
        $optionType = ($this->option ? $this->option->type : null);
        $mimetypes = array();
        if (PaperOption::type_takes_pdf($optionType))
            $mimetypes[] = Mimetype::lookup("pdf");
        if ($optionType === null && !defval($Opt, "disablePS"))
            $mimetypes[] = Mimetype::lookup("ps");
        if ($optionType == PaperOption::T_SLIDES
            || $optionType == PaperOption::T_FINALSLIDES) {
            $mimetypes[] = Mimetype::lookup("ppt");
            $mimetypes[] = Mimetype::lookup("pptx");
        }
        if ($optionType == PaperOption::T_VIDEO
            || $optionType == PaperOption::T_FINALVIDEO) {
            $mimetypes[] = Mimetype::lookup("mp4");
            $mimetypes[] = Mimetype::lookup("avi");
        }
        return $mimetypes;
    }

    function database_storage($doc, $docinfo) {
        global $Conf;
        $columns = array("paperId" => $docinfo->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "paper" => $doc->content);
        if ($Conf->sversion >= 28) {
            $columns["sha1"] = $doc->sha1;
            $columns["documentType"] = $this->type;
        }
        if ($Conf->sversion >= 45 && $doc->filename)
            $columns["filename"] = $doc->filename;
        return array("PaperStorage", "paperStorageId", $columns, "paper");
    }

    function filestore_pattern($doc, $docinfo) {
        global $Opt, $ConfSitePATH, $ConfMulticonf, $ConfFilestore;
        if ($ConfFilestore === null) {
            $fdir = defval($Opt, "filestore");
            if (!$fdir)
                return ($ConfFilestore = false);
            if ($fdir === true)
                $fdir = "$ConfSitePATH/filestore";
            if (isset($Opt["multiconference"]) && $Opt["multiconference"])
                $fdir = str_replace("*", $ConfMulticonf, $fdir);

            $fpath = $fdir;
            $use_subdir = defval($Opt, "filestoreSubdir", false);
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $fpath .= "/%h%x";

            $ConfFilestore = array($fdir, $fpath);
        }
        return $ConfFilestore;
    }

    function load_database_content($doc) {
        global $Conf;
        assert(isset($doc->paperStorageId));
        $result = $Conf->q("select paper, compression from PaperStorage where paperStorageId=" . $doc->paperStorageId);
        $ok = true;
        if (!$result || !($row = edb_row($result))) {
            $doc->content = "";
            $ok = false;
        } else if ($row[1] == 1)
            $doc->content = gzinflate($row[0]);
        else
            $doc->content = $row[0];
        $doc->size = strlen($doc->content);
        return $ok;
    }

}

class DocumentHelper {

    static function _store_database($dbinfo, $doc) {
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
            $doc->error = $Conf->dbErrorText(true, $while);
            return;
        }

        if (isset($cols[$idcol]))
            $doc->$idcol = $cols[$idcol];
        else {
            $doc->$idcol = $Conf->lastInsertId(false);
            if (!$doc->$idcol) {
                $doc->error = $Conf->dbErrorText(true, $while);
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
                $doc->error = $Conf->dbErrorText(true, $while);
                return;
            }
        }

        // check that paper storage succeeded
        if ($check_contents
            && (!($result = $Conf->qe("select length($check_contents) from $table where $idcol=" . $doc->$idcol, $while))
                || !($row = edb_row($result))
                || $row[0] != strlen($doc->content))) {
            $doc->error = "Failed to store your paper. Usually, this is because the file you tried to upload was too big for our system. Please try again.";
            return;
        }
    }

    static function _expand_filestore($fsinfo, $doc) {
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
                $xfpath .= Mimetype::extension($doc->mimetype);
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

    static function _store_filestore($fsinfo, $doc) {
        list($fdir, $fpath) = $fsinfo;

	if (!is_dir($fdir) && !@mkdir($fdir, 0700)) {
	    @rmdir($fdir);
	    return false;
	}

	// Ensure an .htaccess file exists, even if someone else made the
	// filestore directory
	$htaccess = "$fdir/.htaccess";
	if (!is_file($htaccess)
	    && file_put_contents($htaccess, "Order deny,allow\nDeny from all\nphp_flag magic_quotes_gpc off\n") === false) {
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

    static function upload($docclass, $uploadId, $docinfo) {
	global $Conf, $Opt, $OK;
        $doc = (object) array();

	if (!$uploadId
	    || !fileUploaded($_FILES[$uploadId])
	    || !isset($_FILES[$uploadId]["tmp_name"])) {
            $doc->error = "Upload error. Please try again.";
            return $doc;
        }
	$filename = $_FILES[$uploadId]["tmp_name"];

        // prepare document
        $doc->content = file_get_contents($filename);
	if ($doc->content === false || strlen($doc->content) == 0) {
            $doc->error = "The uploaded file was empty. Please try again.";
            return $doc;
        }
        $doc->size = strlen($doc->content);
	$doc->sha1 = sha1($doc->content, true);
	if (isset($_FILES[$uploadId]["name"])
	    && strlen($_FILES[$uploadId]["name"]) <= 255
	    && is_valid_utf8($_FILES[$uploadId]["name"]))
	    $doc->filename = $_FILES[$uploadId]["name"];
        else
            $doc->filename = null;
        $doc->timestamp = time();

	// Check if paper one of the allowed mimetypes.
	// We prefer to look at data since MacOS browsers get this wrong.
	if (strncmp("%PDF-", $doc->content, 5) == 0)
	    $doc->mimetype = Mimetype::type("pdf");
	else if (strncmp("%!PS-", $doc->content, 5) == 0)
	    $doc->mimetype = Mimetype::type("ps");
	else if (substr($doc->content, 512, 4) == "\x00\x6E\x1E\xF0")
	    $doc->mimetype = Mimetype::type("ppt");
	else
	    $doc->mimetype = defval($_FILES[$uploadId], "type", "application/octet-stream");
        $doc->mimetype = Mimetype::type($doc->mimetype);

	$mimetypes = $docclass->mimetypes($doc, $docinfo);
        for ($i = 0; $i < count($mimetypes); ++$i)
            if ($mimetypes[$i]->mimetype == $doc->mimetype)
                break;
	if ($i >= count($mimetypes)) {
	    $doc->error = "I only accept " . htmlspecialchars(Mimetype::description($mimetypes)) . " files.";
	    $doc->error .= " (Your file has MIME type “" . htmlspecialchars($doc->mimetype) . "” and starts with “" . htmlspecialchars(substr($doc->content, 0, 5)) . "”.)<br />Please convert your file to a supported type and try again.";
	    return $doc;
	}

	// store the paper
        if (($dbinfo = $docclass->database_storage($doc, $docinfo)))
            self::_store_database($dbinfo, $doc);
        if (($fsinfo = $docclass->filestore_pattern($doc, $docinfo))) {
            $fsinfo = self::_expand_filestore($fsinfo, $doc);
            if (!self::_store_filestore($fsinfo, $doc) && !$dbinfo)
                $doc->error = "Internal error: could not store document.";
        }

        return $doc;
    }

    static function load($docclass, $doc) {
        $fsinfo = $docclass->filestore_pattern($doc, null);
        if ($fsinfo) {
            $fsinfo = self::_expand_filestore($fsinfo, $doc);
            if (is_readable($fsinfo[1])
                && cvtint(defval($_REQUEST, "nocache", 0), 0) <= 0) {
                $doc->filestore = $fsinfo[1];
                return true;
            }
        }
        if (!isset($doc->content) && !$docclass->load_database_content($doc))
            return false;
        if ($fsinfo)
            self::_store_filestore($fsinfo, $doc);
        return true;
    }

}
