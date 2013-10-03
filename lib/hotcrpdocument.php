<?php
// hotcrpdocument.inc -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfFilestore;
$ConfFilestore = null;

class HotCRPDocument {

    private $dtype;
    private $option;

    public function __construct($dtype, $option = null) {
        $this->dtype = $dtype;
        if ($option)
            $this->option = $option;
        else if ($this->dtype > 0)
            $this->option = paperOptions($dtype);
        else
            $this->option = null;
    }

    public static function unparse_dtype($dtype) {
        if ($dtype == DTYPE_SUBMISSION)
            return "paper";
        else if ($dtype == DTYPE_FINAL)
            return "final";
        else if (($o = PaperOption::get($dtype)) && $o->isDocument)
            return $o->optionAbbrev;
        else
            return null;
    }

    public static function filename($doc) {
        global $Opt;
        $fn = $Opt["downloadPrefix"];
        if ($doc->documentType == DTYPE_SUBMISSION)
            $fn .= "paper" . $doc->paperId;
        else if ($doc->documentType == DTYPE_FINAL)
            $fn .= "final" . $doc->paperId;
        else {
            $o = paperOptions($doc->documentType);
            if ($o && $o->type == PaperOption::T_ATTACHMENTS && $doc->filename)
                // do not decorate with MIME type suffix
                return $fn . "p" . $doc->paperId . "/" . $o->optionAbbrev . "/" . $doc->filename;
            else if ($o && $o->isDocument)
                $fn .= "paper" . $doc->paperId . "-" . $o->optionAbbrev;
            else
                $fn .= "paper" . $doc->paperId . "-unknown";
        }
        return $fn . Mimetype::extension($doc->mimetype);
    }

    public function mimetypes($doc = null, $docinfo = null) {
        global $Opt;
        if ($this->dtype > 0 && !$this->option)
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

    public function database_storage($doc, $docinfo) {
        global $Conf;
        $doc->paperId = $docinfo->paperId;
        $doc->documentType = $this->dtype;
        $columns = array("paperId" => $docinfo->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "paper" => $doc->content,
                         "sha1" => $doc->sha1,
                         "documentType" => $doc->documentType);
        if ($Conf->sversion >= 45 && $doc->filename)
            $columns["filename"] = $doc->filename;
        return array("PaperStorage", "paperStorageId", $columns, "paper");
    }

    public function filestore_pattern($doc, $docinfo) {
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

    public function load_database_content($doc) {
        global $Conf;
        if (!$doc->paperStorageId) {
            if ($this->dtype == DTYPE_SUBMISSION)
                $doc->error_text = "Paper #" . $doc->paperId . " has not been uploaded.";
            else if ($this->dtype == DTYPE_FINAL)
                $doc->error_text = "Paper #" . $doc->paperId . "’s final copy has not been uploaded.";
            else
                $doc->error_text = "";
            return false;
        }
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
