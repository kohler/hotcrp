<?php
// hotcrpdocument.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class HotCRPDocument extends Filer {
    private $conf;
    private $dtype;
    private $option = null;
    private $no_database = false;
    private $no_filestore = false;

    public function __construct(Conf $conf, $dtype, PaperOption $option = null) {
        $this->conf = $conf;
        $this->dtype = $dtype;
        if ($this->dtype > 0 && $option)
            $this->option = $option;
        else
            $this->option = $this->conf->paper_opts->find_document($dtype);
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
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));

        $fn = $Conf->download_prefix;
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
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if ($this->option && !get($doc, "filterType"))
            return $this->option->validate_document($doc, $docinfo);
        else
            return true;
    }

    public static function s3_filename($doc) {
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if (($sha1 = Filer::text_sha1($doc)) !== false)
            return "doc/" . substr($sha1, 0, 2) . "/" . $sha1
                . Mimetype::extension($doc->mimetype);
        else
            return null;
    }

    public function s3_check($doc) {
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return ($s3 = $this->conf->s3_docstore())
            && $s3->check(self::s3_filename($doc));
    }

    public function s3_store($doc, $docinfo, $trust_sha1 = false) {
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if (!isset($doc->content) && !$this->load_content($doc))
            return false;
        if (!$trust_sha1 && Filer::binary_sha1($doc) !== sha1($doc->content, true)) {
            error_log("S3 upload cancelled: data claims checksum " . Filer::text_sha1($doc)
                      . ", has checksum " . sha1($doc->content));
            return false;
        }
        $s3 = $this->conf->s3_docstore();
        $dtype = isset($doc->documentType) ? $doc->documentType : $this->dtype;
        $meta = array("conf" => $this->conf->opt("dbName"),
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
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if (($s3 = $this->conf->s3_docstore()))
            $this->s3_store($doc, $docinfo, true);
    }

    public function dbstore($doc, $docinfo) {
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
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
        if ($this->conf->sversion >= 136 && ($m = Mimetype::lookup($doc->mimetype)))
            $columns["mimetypeid"] = $m->mimetypeid;
        if (!$this->conf->opt("dbNoPapers"))
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
        if (get($doc, "size"))
            $columns["size"] = $doc->size;
        if (get($doc, "filterType"))
            $columns["filterType"] = $doc->filterType;
        else if (get($doc, "filter"))
            $columns["filterType"] = $doc->filter;
        if (get($doc, "originalStorageId"))
            $columns["originalStorageId"] = $doc->originalStorageId;
        else if (get($doc, "original_id"))
            $columns["originalStorageId"] = $doc->original_id;
        return new Filer_Dbstore($this->conf->dblink, "PaperStorage", "paperStorageId", $columns,
                                 $this->conf->opt("dbNoPapers") ? null : "paper");
    }

    public function filestore_pattern($doc) {
        return $this->no_filestore ? false : $this->conf->docstore();
    }

    private function load_content_db($doc) {
        $result = $this->conf->q("select paper, compression from PaperStorage where paperStorageId=" . $doc->paperStorageId);
        $ok = false;
        if ($result && ($row = $result->fetch_row()) && $row[0] !== null) {
            $doc->content = ($row[1] == 1 ? gzinflate($row[0]) : $row[0]);
            $ok = true;
        }
        Dbl::free($result);
        return $ok;
    }

    public function load_content($doc) {
        global $Conf;
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        $ok = false;
        $doc->content = "";

        $dbNoPapers = $this->conf->opt("dbNoPapers");
        if (!$dbNoPapers && $doc->paperStorageId > 1)
            $ok = $this->load_content_db($doc);

        if (!$ok && ($s3 = $this->conf->s3_docstore())
            && ($filename = self::s3_filename($doc))) {
            $filename = self::s3_filename($doc);
            $content = $s3->load($filename);
            if ($content !== "" && $content !== null) {
                $doc->content = $content;
                $ok = true;
            } else if ($s3->status != 200)
                error_log("S3 error: GET $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        }

        if (!$ok && $dbNoPapers && $doc->paperStorageId > 1) // ignore dbNoPapers second time through
            $ok = $this->load_content_db($doc);

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
        if (!($doc instanceof DocumentInfo))
            error_log("HotCRPDocument bad \$doc: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
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
