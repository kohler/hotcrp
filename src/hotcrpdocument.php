<?php
// hotcrpdocument.php -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class HotCRPDocument extends Filer {
    private $conf;
    private $dtype;
    private $option = null;
    private $no_database = false;
    private $no_filestore = false;

    function __construct(Conf $conf, $dtype, PaperOption $option = null) {
        $this->conf = $conf;
        $this->dtype = $dtype;
        if ($this->dtype > 0 && $option)
            $this->option = $option;
        else
            $this->option = $this->conf->paper_opts->find_document($dtype);
    }

    function set_no_database_storage() {
        $this->no_database = true;
    }

    function set_no_file_storage() {
        $this->no_filestore = true;
    }

    static function unparse_dtype($dtype) {
        global $Conf;
        $o = $Conf->paper_opts->find_document($dtype);
        return $o && $o->is_document() ? $o->abbreviation() : null;
    }

    static function parse_dtype($dname) {
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

    static function filename(DocumentInfo $doc, $filters = null) {
        $fn = $doc->conf->download_prefix;
        if ($doc->documentType == DTYPE_SUBMISSION)
            $fn .= "paper" . $doc->paperId;
        else if ($doc->documentType == DTYPE_FINAL)
            $fn .= "final" . $doc->paperId;
        else {
            $o = $doc->conf->paper_opts->find($doc->documentType);
            if ($o && $o->nonpaper && $doc->paperId < 0) {
                $fn .= $o->abbreviation();
                $oabbr = "";
            } else {
                $fn .= "paper" . $doc->paperId;
                $oabbr = $o ? "-" . $o->abbreviation() : "-unknown";
            }
            if ($o && $o->has_attachments()
                && ($afn = $doc->unique_filename ? : $doc->filename))
                // do not decorate with MIME type suffix
                return $fn . $oabbr . "/" . $afn;
            $fn .= $oabbr;
        }
        $mimetype = $doc->mimetype;
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
        if ($mimetype) {
            if (($ext = Mimetype::extension($mimetype)))
                $fn .= $ext;
            else if ($doc->filename
                     && preg_match('/(\.[A-Za-z0-9]{1,5})\z/', $doc->filename, $m)
                     && (!$filters || $mimetype === $doc->mimetype))
                $fn .= $m[1];
        }
        return $fn;
    }

    function validate_upload(DocumentInfo $doc) {
        if ($this->option && !get($doc, "filterType"))
            return $this->option->validate_document($doc);
        else
            return true;
    }

    static function s3_filename(DocumentInfo $doc) {
        $hash = $doc->text_hash();
        if ($hash === false)
            return null;
        else if (strlen($hash) === 40)
            $x = substr($hash, 0, 2);
        else
            $x = substr($hash, strpos($hash, "-") + 1, 3);
        return "doc/$x/$hash" . Mimetype::extension($doc->mimetype);
    }

    function s3_check(DocumentInfo $doc) {
        $s3 = $this->conf->s3_docstore();
        if (!$s3)
            return false;
        $filename = self::s3_filename($doc);
        return $s3->check($filename)
            || ($this->s3_upgrade_extension($s3, $doc)
                && $s3->check($filename));
    }

    function s3_store(DocumentInfo $doc, $trust_hash = false) {
        if (!isset($doc->content) && !$this->load_to_memory($doc))
            return false;
        if (!$trust_hash) {
            $chash = $doc->content_binary_hash($doc->binary_hash());
            if ($chash !== $doc->binary_hash()) {
                error_log("S3 upload cancelled: data claims checksum " . $doc->text_hash()
                          . ", has checksum " . Filer::hash_as_text($chash));
                return false;
            }
        }
        $s3 = $this->conf->s3_docstore();
        $dtype = isset($doc->documentType) ? $doc->documentType : $this->dtype;
        $meta = array("conf" => $this->conf->opt("dbName"),
                      "pid" => $doc->paperId,
                      "dtype" => (int) $dtype);
        if ($doc->filterType) {
            $meta["filtertype"] = $doc->filterType;
            if ($doc->sourceHash != "")
                $meta["sourcehash"] = Filer::hash_as_text($doc->sourceHash);
        }
        $filename = self::s3_filename($doc);
        $s3->save($filename, $doc->content, $doc->mimetype,
                  array("hotcrp" => json_encode($meta)));
        if ($s3->status != 200)
            error_log("S3 error: POST $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        return $s3->status == 200;
    }

    function store_other(DocumentInfo $doc) {
        if (($s3 = $this->conf->s3_docstore()))
            $this->s3_store($doc, true);
    }

    function dbstore(DocumentInfo $doc) {
        if ($this->no_database)
            return null;
        $doc->documentType = $this->dtype;
        $columns = array("paperId" => $doc->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "sha1" => $doc->binary_hash(),
                         "documentType" => $doc->documentType,
                         "mimetype" => $doc->mimetype);
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
        if ($doc->size)
            $columns["size"] = $doc->size;
        if ($doc->filterType)
            $columns["filterType"] = $doc->filterType;
        if ($doc->originalStorageId)
            $columns["originalStorageId"] = $doc->originalStorageId;
        return new Filer_Dbstore($this->conf->dblink, "PaperStorage", "paperStorageId", $columns,
                                 $this->conf->opt("dbNoPapers") ? null : "paper");
    }

    function filestore_pattern(DocumentInfo $doc) {
        return $this->no_filestore ? null : $this->conf->docstore();
    }

    private function load_content_db($doc) {
        $result = $this->conf->q("select paper, compression from PaperStorage where paperStorageId=?", $doc->paperStorageId);
        $ok = false;
        if ($result && ($row = $result->fetch_row()) && $row[0] !== null) {
            $doc->content = $row[1] == 1 ? gzinflate($row[0]) : $row[0];
            $ok = true;
        }
        Dbl::free($result);
        return $ok;
    }

    private function s3_upgrade_extension(S3Document $s3, DocumentInfo $doc) {
        $extension = Mimetype::extension($doc->mimetype);
        if ($extension === ".pdf" || $extension === "")
            return false;
        $filename = self::s3_filename($doc);
        $src_filename = substr($filename, 0, -strlen($extension));
        return $s3->copy($src_filename, $filename);
    }

    function load_content(DocumentInfo $doc) {
        $ok = false;
        $doc->content = "";

        $dbNoPapers = $this->conf->opt("dbNoPapers");
        if (!$dbNoPapers && $doc->paperStorageId > 1)
            $ok = $this->load_content_db($doc);

        if (!$ok && ($s3 = $this->conf->s3_docstore())
            && ($filename = self::s3_filename($doc))) {
            $content = $s3->load($filename);
            if ($s3->status == 404
                // maybe it’s in S3 under a different extension
                && $this->s3_upgrade_extension($s3, $doc))
                $content = $s3->load($filename);
            if ($content !== "" && $content !== null) {
                $doc->content = $content;
                $ok = true;
            } else if ($s3->status != 200)
                error_log("S3 error: GET $filename: $s3->status $s3->status_text " . json_encode($s3->response_headers));
        }

        // ignore dbNoPapers second time through
        if (!$ok && $dbNoPapers && $doc->paperStorageId > 1)
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

    static function url(DocumentInfo $doc, $filters = null, $rest = null) {
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
