<?php
// hotcrpdocument.php -- document helper class for HotCRP papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class HotCRPDocument extends Filer {
    private $conf;
    private $dtype;
    private $option = null;

    function __construct(Conf $conf, $dtype, PaperOption $option = null) {
        $this->conf = $conf;
        $this->dtype = $dtype;
        if ($this->dtype > 0 && $option)
            $this->option = $option;
        else
            $this->option = $this->conf->paper_opts->get($dtype);
    }

    function validate_upload(DocumentInfo $doc) {
        if ($this->option && !get($doc, "filterType"))
            return $this->option->validate_document($doc);
        else
            return true;
    }

    static function s3_filename(DocumentInfo $doc) {
        $hash = $doc->text_hash();
        // Format: `doc/%[2/3]H/%h%x`. Why not algorithm in subdirectory?
        // Because S3 works better if filenames are partitionable.
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

    function s3_store(DocumentInfo $doc, $storeinfo, $trust_hash = false) {
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
                  array("hotcrp" => json_encode_db($meta)));
        if ($s3->status != 200)
            error_log("S3 error: POST $filename: $s3->status $s3->status_text " . json_encode_db($s3->response_headers));
        if ($storeinfo) {
            if ($s3->status == 200)
                $storeinfo->content_success = true;
            else
                $storeinfo->error_html[] = "Cannot upload document to S3.";
        }
        return $s3->status == 200;
    }

    function store_other(DocumentInfo $doc, $storeinfo) {
        if (($s3 = $this->conf->s3_docstore()))
            $this->s3_store($doc, $storeinfo, true);
    }

    function dbstore(DocumentInfo $doc) {
        $doc->documentType = $this->dtype;
        $dbstore = new Filer_Dbstore;
        $dbstore->dblink = $this->conf->dblink;
        $dbstore->table = "PaperStorage";
        $dbstore->id_column = "paperStorageId";
        $dbstore->upd = array("paperId" => $doc->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "sha1" => $doc->binary_hash(),
                         "documentType" => $doc->documentType,
                         "mimetype" => $doc->mimetype);
        if (!$this->conf->opt("dbNoPapers")) {
            $dbstore->upd["paper"] = $doc->content;
            $dbstore->content_column = "paper";
        }
        if (get($doc, "filename"))
            $dbstore->upd["filename"] = $doc->filename;
        $infoJson = get($doc, "infoJson");
        if (is_string($infoJson))
            $dbstore->upd["infoJson"] = $infoJson;
        else if (is_object($infoJson) || is_associative_array($infoJson))
            $dbstore->upd["infoJson"] = json_encode_db($infoJson);
        else if (is_object(get($doc, "metadata")))
            $dbstore->upd["infoJson"] = json_encode_db($doc->metadata);
        if ($doc->size)
            $dbstore->upd["size"] = $doc->size;
        if ($doc->filterType)
            $dbstore->upd["filterType"] = $doc->filterType;
        if ($doc->originalStorageId)
            $dbstore->upd["originalStorageId"] = $doc->originalStorageId;
        return $dbstore;
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

        if (!$ok
            && ($s3 = $this->conf->s3_docstore())
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
                error_log("S3 error: GET $filename: $s3->status $s3->status_text " . json_encode_db($s3->response_headers));
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
        // store to filestore; silently does nothing if error || !filestore
        $this->store_filestore($doc, new Filer_StoreStatus);
        return $ok;
    }
}
