<?php
// hotcrpdocument.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfFilestore;
$ConfFilestore = null;

class HotCRPDocument {

    private $dtype;
    private $option = null;
    private $no_database = false;
    static private $_s3_document = false;

    public function __construct($dtype, $option = null) {
        $this->dtype = $dtype;
        if ($this->dtype > 0 && $option)
            $this->option = $option;
        else if ($this->dtype > 0)
            $this->option = PaperOption::find($dtype);
    }

    public function set_no_database_storage() {
        $this->no_database = true;
    }

    public static function unparse_dtype($dtype) {
        if ($dtype == DTYPE_SUBMISSION)
            return "paper";
        else if ($dtype == DTYPE_FINAL)
            return "final";
        else if (($o = PaperOption::find($dtype)) && $o->is_document())
            return $o->abbr;
        else
            return null;
    }

    public static function parse_dtype($dname) {
        if (preg_match('/\A-?\d+\z/', $dname))
            return (int) $dname;
        $dname = strtolower($dname);
        if ($dname === "paper" || $dname === "submission")
            return DTYPE_SUBMISSION;
        if ($dname === "final")
            return DTYPE_FINAL;
        $omatches = PaperOption::search($dname);
        if (count($omatches) == 1)
            return current($omatches)->id;
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
            $o = PaperOption::find($doc->documentType);
            if ($o && $o->type == "attachments" && $doc->filename)
                // do not decorate with MIME type suffix
                return $fn . "p" . $doc->paperId . "/" . $o->abbr . "/" . $doc->filename;
            else if ($o && $o->is_document())
                $fn .= "paper" . $doc->paperId . "-" . $o->abbr;
            else
                $fn .= "paper" . $doc->paperId . "-unknown";
        }
        return $fn . Mimetype::extension($doc->mimetype);
    }

    public function mimetypes($doc = null, $docinfo = null) {
        global $Opt;
        if ($this->dtype > 0 && !$this->option)
            return null;
        $otype = ($this->option ? $this->option->type : "pdf");
        $mimetypes = array();
        if (PaperOption::type_takes_pdf($otype))
            $mimetypes[] = Mimetype::lookup("pdf");
        if (!$this->option && !defval($Opt, "disablePS"))
            $mimetypes[] = Mimetype::lookup("ps");
        if ($otype == "slides") {
            $mimetypes[] = Mimetype::lookup("ppt");
            $mimetypes[] = Mimetype::lookup("pptx");
        }
        if ($otype == "video") {
            $mimetypes[] = Mimetype::lookup("mp4");
            $mimetypes[] = Mimetype::lookup("avi");
        }
        return $mimetypes;
    }

    public static function s3_document() {
        global $Conf, $Now;
        if (self::$_s3_document === false) {
            if ($Conf->setting_data("s3_bucket")) {
                $opt = array("bucket" => $Conf->setting_data("s3_bucket"),
                             "key" => $Conf->setting_data("s3_key"),
                             "secret" => $Conf->setting_data("s3_secret"),
                             "scope" => $Conf->setting_data("__s3_scope"),
                             "signing_key" => $Conf->setting_data("__s3_signing_key"));
                self::$_s3_document = new S3Document($opt);
                list($scope, $signing_key) = self::$_s3_document->scope_and_signing_key($Now);
                if ($opt["scope"] !== $scope
                    || $opt["signing_key"] !== $signing_key) {
                    $Conf->save_setting("__s3_scope", 1, $scope);
                    $Conf->save_setting("__s3_signing_key", 1, $signing_key);
                }
            } else
                self::$_s3_document = null;
        }
        return self::$_s3_document;
    }

    public static function s3_filename($doc) {
        if (($sha1 = DocumentHelper::text_sha1($doc)) !== null)
            return "doc/" . substr($sha1, 0, 2) . "/" . $sha1
                . Mimetype::extension($doc->mimetype);
        else
            return null;
    }

    public function s3_check($doc) {
        return ($s3 = self::s3_document())
            && $s3->check(self::s3_filename($doc));
    }

    public function s3_store($doc, $docinfo, $trust_sha1 = false) {
        global $Opt;
        if (!isset($doc->content) && !$this->load_content($doc))
            return false;
        if (!$trust_sha1 && DocumentHelper::binary_sha1($doc) !== sha1($doc->content, true)) {
            error_log("S3 upload cancelled: data claims checksum " . DocumentHelper::text_sha1($doc)
                      . ", has checksum " . sha1($doc->content));
            return false;
        }
        $s3 = self::s3_document();
        $dtype = isset($doc->documentType) ? $doc->documentType : $this->dtype;
        $meta = json_encode(array("conf" => $Opt["dbName"],
                                  "pid" => (int) $docinfo->paperId,
                                  "dtype" => (int) $dtype));
        $filename = self::s3_filename($doc);
        $s3->save($filename, $doc->content, $doc->mimetype,
                  array("hotcrp" => $meta));
        if ($s3->status != 200)
            error_log("S3 error: POST $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        return $s3->status == 200;
    }

    public function prepare_storage($doc, $docinfo) {
        if (($s3 = self::s3_document()))
            $this->s3_store($doc, $docinfo, true);
    }

    public function database_storage($doc, $docinfo) {
        global $Conf, $Opt;
        if ($this->no_database)
            return null;
        $doc->paperId = $docinfo->paperId;
        $doc->documentType = $this->dtype;
        $columns = array("paperId" => $docinfo->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "sha1" => $doc->sha1,
                         "documentType" => $doc->documentType);
        if (!@$Opt["dbNoPapers"])
            $columns["paper"] = $doc->content;
        if (@$doc->filename)
            $columns["filename"] = $doc->filename;
        if (is_string(@$doc->infoJson))
            $columns["infoJson"] = $doc->infoJson;
        else if (is_object(@$doc->infoJson))
            $columns["infoJson"] = json_encode($doc->infoJson);
        else if (is_object(@$doc->metadata))
            $columns["infoJson"] = json_encode($doc->metadata);
        if ($Conf->sversion >= 74 && @$doc->size)
            $columns["size"] = $doc->size;
        return array("PaperStorage", "paperStorageId", $columns,
                     @$Opt["dbNoPapers"] ? null : "paper");
    }

    public function filestore_pattern($doc, $docinfo) {
        global $Opt, $ConfSitePATH, $ConfFilestore;
        if ($ConfFilestore === null) {
            $fdir = defval($Opt, "docstore");
            if (!$fdir)
                return ($ConfFilestore = false);
            if ($fdir === true)
                $fdir = "docs";
            if ($fdir[0] !== "/")
                $fdir = "$ConfSitePATH/$fdir";
            if (@$Opt["multiconference"])
                $fdir = str_replace("*", $Opt["confid"], $fdir);

            $fpath = $fdir;
            $use_subdir = defval($Opt, "docstoreSubdir", false);
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $fpath .= "/%h%x";

            $ConfFilestore = array($fdir, $fpath);
        }
        return $ConfFilestore;
    }

    public function filestore_check($doc) {
        return DocumentHelper::filestore_check($this, $doc);
    }

    public function load_content($doc) {
        global $Conf;
        $ok = false;

        $result = null;
        if (!@$Opt["dbNoPapers"]
            && @($doc->paperStorageId > 1))
            $result = $Conf->q("select paper, compression from PaperStorage where paperStorageId=" . $doc->paperStorageId);
        if (!$result || !($row = edb_row($result)) || $row[0] === null)
            $doc->content = "";
        else if ($row[1] == 1) {
            $doc->content = gzinflate($row[0]);
            $ok = true;
        } else {
            $doc->content = $row[0];
            $ok = true;
        }

        if (!$ok && ($s3 = self::s3_document())) {
            $filename = self::s3_filename($doc);
            $content = $s3->load($filename);
            if ($content !== "" && $content !== null) {
                $doc->content = $content;
                $ok = true;
            } else if ($s3->status != 200)
                error_log("S3 error: GET $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        }

        if (!$ok) {
            $num = @$doc->paperId ? " #$doc->paperId" : "";
            if ($this->dtype == DTYPE_SUBMISSION)
                $doc->error_text = "Paper$num has not been uploaded.";
            else if ($this->dtype == DTYPE_FINAL)
                $doc->error_text = "Paper{$num}’s final copy has not been uploaded.";
            else
                $doc->error_text = "";
        }

        $doc->size = strlen($doc->content);
        return $ok;
    }

    static function url($doc) {
        assert(property_exists($doc, "mimetype") && isset($doc->documentType));
        if ($doc->mimetype)
            $f = "file=" . urlencode(self::filename($doc));
        else {
            $f = "p=$doc->paperId";
            if ($doc->documentType == DTYPE_FINAL)
                $f .= "&amp;final=1";
            else if ($doc->documentType > 0)
                $f .= "&amp;dt=$doc->documentType";
        }
        return hoturl("doc", $f);
    }

}
