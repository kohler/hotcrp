<?php
// hotcrpdocument.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class HotCRPDocument extends Filer {
    private $dtype;
    private $option = null;
    private $no_database = false;
    private $no_filestore = false;
    static private $_s3_document = false;
    static private $_docstore = null;
    static private $map = [];

    public function __construct($dtype, $option = null) {
        global $Conf;
        $this->dtype = $dtype;
        if ($this->dtype > 0 && $option)
            $this->option = $option;
        else if ($this->dtype > 0)
            $this->option = $Conf->paper_opts->find($dtype);
    }

    static public function get($dtype) {
        if (!isset(self::$map[$dtype]))
            self::$map[$dtype] = new HotCRPDocument($dtype);
        return self::$map[$dtype];
    }

    public function set_no_database_storage() {
        $this->no_database = true;
    }

    public function set_no_file_storage() {
        $this->no_filestore = true;
    }

    public static function unparse_dtype($dtype) {
        global $Conf;
        if ($dtype == DTYPE_SUBMISSION)
            return "paper";
        else if ($dtype == DTYPE_FINAL)
            return "final";
        else if (($o = $Conf->paper_opts->find($dtype)) && $o->is_document())
            return $o->abbr;
        else
            return null;
    }

    public static function parse_dtype($dname) {
        global $Conf;
        if (preg_match('/\A-?\d+\z/', $dname))
            return (int) $dname;
        $dname = strtolower($dname);
        if ($dname === "paper" || $dname === "submission")
            return DTYPE_SUBMISSION;
        else if ($dname === "final")
            return DTYPE_FINAL;
        else if (($o = $Conf->paper_opts->match($dname)))
            return $o->id;
        else
            return null;
    }

    public static function filename($doc, $filters = null) {
        global $Conf;
        $fn = $Conf->download_prefix;;
        if ($doc->documentType == DTYPE_SUBMISSION)
            $fn .= "paper" . $doc->paperId;
        else if ($doc->documentType == DTYPE_FINAL)
            $fn .= "final" . $doc->paperId;
        else {
            $o = $Conf->paper_opts->find($doc->documentType);
            if ($o && $o->nonpaper && $doc->paperId < 0) {
                $fn .= $o->abbr;
                $oabbr = "";
            } else {
                $fn .= "paper" . $doc->paperId;
                $oabbr = $o ? "-" . $o->abbr : "-unknown";
            }
            if ($o && $o->has_attachments()
                && ($afn = get($doc, "unique_filename") ? : $doc->filename))
                // do not decorate with MIME type suffix
                return $fn . $oabbr . "/" . $afn;
            $fn .= $oabbr;
        }
        $mimetype = get($doc, "mimetype");
        if ($filters === null && isset($doc->filters_applied))
            $filters = $doc->filters_applied;
        if ($filters)
            foreach (is_array($filters) ? $filters : [$filters] as $filter) {
                if (is_string($filter))
                    $filter = FileFilter::find_by_name($filter);
                if ($filter instanceof FileFilter) {
                    $fn .= "-" . $filter->name;
                    $mimetype = $filter->mimetype($doc, $mimetype);
                }
            }
        if ($mimetype)
            $fn .= Mimetype::extension($mimetype);
        return $fn;
    }

    public function validate_upload($doc, $docinfo) {
        global $Conf;
        if (get($doc, "filterType"))
            return true;
        else {
            $opt = $this->option ? : $Conf->paper_opts->find_document($this->dtype);
            return !$opt || $opt->validate_document($doc, $docinfo);
        }
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
        if (($sha1 = Filer::text_sha1($doc)) !== false)
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
        if (!isset($doc->content) && !$this->load_content($doc))
            return false;
        if (!$trust_sha1 && Filer::binary_sha1($doc) !== sha1($doc->content, true)) {
            error_log("S3 upload cancelled: data claims checksum " . Filer::text_sha1($doc)
                      . ", has checksum " . sha1($doc->content));
            return false;
        }
        $s3 = self::s3_document();
        $dtype = isset($doc->documentType) ? $doc->documentType : $this->dtype;
        $meta = array("conf" => opt("dbName"),
                      "pid" => isset($doc->paperId) ? (int) $doc->paperId : (int) $docinfo->paperId,
                      "dtype" => (int) $dtype);
        if (get($doc, "filter")) {
            $meta["filtertype"] = (int) $doc->filter;
            if (get($doc, "original_sha1"))
                $meta["original_sha1"] = $doc->original_sha1;
        }
        $filename = self::s3_filename($doc);
        $s3->save($filename, $doc->content, $doc->mimetype,
                  array("hotcrp" => json_encode($meta)));
        if ($s3->status != 200)
            error_log("S3 error: POST $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        return $s3->status == 200;
    }

    public function store_other($doc, $docinfo) {
        if (($s3 = self::s3_document()))
            $this->s3_store($doc, $docinfo, true);
    }

    public function dbstore($doc, $docinfo) {
        global $Conf;
        if ($this->no_database)
            return null;
        if (!isset($doc->paperId))
            $doc->paperId = $docinfo->paperId;
        $doc->documentType = $this->dtype;
        $columns = array("paperId" => $docinfo->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "sha1" => $doc->sha1,
                         "documentType" => $doc->documentType);
        $columns["mimetype"] = $doc->mimetype;
        if ($Conf->sversion >= 136 && ($m = Mimetype::lookup($doc->mimetype)))
            $columns["mimetypeid"] = $m->mimetypeid;
        if (!opt("dbNoPapers"))
            $columns["paper"] = $doc->content;
        if (get($doc, "filename"))
            $columns["filename"] = $doc->filename;
        $infoJson = get($doc, "infoJson");
        if (is_string($infoJson))
            $columns["infoJson"] = $infoJson;
        else if (is_object($infoJson) || is_associative_array($infoJson))
            $columns["infoJson"] = json_encode($infoJson);
        else if (is_object(get($doc, "metadata")))
            $columns["infoJson"] = json_encode($doc->metadata);
        if ($Conf->sversion >= 74 && get($doc, "size"))
            $columns["size"] = $doc->size;
        if ($Conf->sversion >= 82) {
            if (get($doc, "filterType"))
                $columns["filterType"] = $doc->filterType;
            else if (get($doc, "filter"))
                $columns["filterType"] = $doc->filter;
            if (get($doc, "originalStorageId"))
                $columns["originalStorageId"] = $doc->originalStorageId;
            else if (get($doc, "original_id"))
                $columns["originalStorageId"] = $doc->original_id;
        }
        return new Filer_Dbstore("PaperStorage", "paperStorageId", $columns,
                                 opt("dbNoPapers") ? null : "paper");
    }

    public function filestore_pattern($doc) {
        global $ConfSitePATH;
        if ($this->no_filestore)
            return false;
        if (self::$_docstore === null) {
            $fdir = opt("docstore");
            if (!$fdir)
                return (self::$_docstore = false);

            $fpath = $fdir;
            $use_subdir = opt("docstoreSubdir", false);
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $fpath .= "/%h%x";

            self::$_docstore = array($fdir, $fpath);
        }
        return self::$_docstore;
    }

    public function load_content($doc) {
        global $Conf;
        $ok = false;

        $result = null;
        if (!opt("dbNoPapers")
            && get_i($doc, "paperStorageId") > 1)
            $result = Dbl::q("select paper, compression from PaperStorage where paperStorageId=" . $doc->paperStorageId);
        if (!$result || !($row = edb_row($result)) || $row[0] === null)
            $doc->content = "";
        else if ($row[1] == 1) {
            $doc->content = gzinflate($row[0]);
            $ok = true;
        } else {
            $doc->content = $row[0];
            $ok = true;
        }

        if (!$ok && ($s3 = self::s3_document())
            && ($filename = self::s3_filename($doc))) {
            $filename = self::s3_filename($doc);
            $content = $s3->load($filename);
            if ($content !== "" && $content !== null) {
                $doc->content = $content;
                $ok = true;
            } else if ($s3->status != 200)
                error_log("S3 error: GET $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        }

        if (!$ok) {
            $num = get($doc, "paperId") ? " #$doc->paperId" : "";
            $doc->error = true;
            if ($this->dtype == DTYPE_SUBMISSION)
                $doc->error_text = "Paper$num has not been uploaded.";
            else if ($this->dtype == DTYPE_FINAL)
                $doc->error_text = "Paper{$num}’s final version has not been uploaded.";
        }

        $doc->size = strlen($doc->content);
        $this->store_filestore($doc, true); // silently does nothing if error || !filestore
        return $ok;
    }

    static function url($doc, $filters = null, $rest = null) {
        assert(property_exists($doc, "mimetype") && isset($doc->documentType));
        if ($doc->mimetype)
            $f = "file=" . rawurlencode(self::filename($doc, $filters));
        else {
            $f = "p=$doc->paperId";
            if ($doc->documentType == DTYPE_FINAL)
                $f .= "&amp;final=1";
            else if ($doc->documentType > 0)
                $f .= "&amp;dt=$doc->documentType";
        }
        if ($rest && is_array($rest)) {
            foreach ($rest as $k => $v)
                $f .= "&amp;" . urlencode($k) . "=" . urlencode($v);
        } else if ($rest)
            $f .= "&amp;" . $rest;
        return hoturl("doc", $f);
    }
}
