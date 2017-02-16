<?php
// paperinfo.php -- HotCRP document objects
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class DocumentInfo implements JsonSerializable {
    public $conf;
    public $prow;
    public $paperStorageId = 0;
    public $paperId = 0;
    public $documentType = 0;
    public $timestamp;
    public $mimetype;
    public $mimetypeid;
    public $sha1;
    public $size;
    public $content;
    public $compression;
    public $filename;
    public $unique_filename;
    public $filestore;
    public $infoJson;
    public $infoJson_str;
    public $filterType = 0;
    public $originalStorageId;
    public $docclass;
    public $is_partial = false;
    public $filters_applied;
    public $error;
    public $error_html;

    function __construct($p = null, Conf $conf = null, PaperInfo $prow = null) {
        $this->merge($p, $conf, $prow);
    }

    private function merge($p, Conf $conf = null, PaperInfo $prow = null) {
        global $Conf;
        $this->conf = $conf ? : $Conf;
        $this->prow = $prow;
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->paperId = (int) $this->paperId;
        $this->documentType = (int) $this->documentType;
        $this->timestamp = (int) $this->timestamp;
        assert($this->paperStorageId <= 1 || !!$this->mimetype);
        $this->mimetypeid = (int) $this->mimetypeid;
        $this->size = (int) $this->size;
        if (is_string($this->infoJson)) {
            $this->infoJson_str = $this->infoJson;
            $this->infoJson = json_decode($this->infoJson);
        } else if (is_associative_array($this->infoJson))
            $this->infoJson = array_to_object_recursive($this->infoJson);
        else if (!is_object($this->infoJson))
            $this->infoJson = null;
        $this->filterType = $this->filterType ? (int) $this->filterType : null;
        $this->originalStorageId = $this->originalStorageId ? (int) $this->originalStorageId : null;
        $this->docclass = $this->conf->docclass($this->documentType);
        if (isset($this->paper) && !isset($this->content))
            $this->content = $this->paper;
        if ($this->error_html)
            $this->error = true;

        // set sha1
        if ($this->sha1 == "" && $this->paperStorageId > 1
            && $this->docclass->load_content($this)) {
            // store sha1 in database if needed (backwards compat)
            $this->sha1 = sha1($this->content, true);
            $this->conf->q("update PaperStorage set sha1=? where paperId=? and paperStorageId=?", $this->sha1, $this->paperId, $this->paperStorageId);
            // we might also need to update the joindoc
            if ($this->documentType == DTYPE_SUBMISSION)
                $this->conf->q("update Paper set sha1=? where paperId=? and paperStorageId=? and finalPaperStorageId<=0", $this->sha1, $this->paperId, $this->paperStorageId);
            else if ($this->documentType == DTYPE_FINAL)
                $this->conf->q("update Paper set sha1=? where paperId=? and finalPaperStorageId=?", $this->sha1, $this->paperStorageId);
        } else if ($this->sha1 == "" && $this->content)
            $this->sha1 = sha1($this->content, true);
    }

    static function fetch($result, Conf $conf = null, PaperInfo $prow = null) {
        $di = $result ? $result->fetch_object("DocumentInfo", [null, $conf, $prow]) : null;
        if ($di && !is_int($di->paperStorageId))
            $di->merge(null, $conf, $prow);
        return $di;
    }

    static function make_file_upload($paperId, $documentType, $upload) {
        if (is_string($upload) && $upload)
            $upload = $_FILES[$upload];
        if (!$upload || !is_array($upload) || !file_uploaded($upload)
            || !isset($upload["tmp_name"]))
            return null;
        $args = ["paperId" => $paperId,
                 "documentType" => $documentType,
                 "timestamp" => time()];
        if (isset($upload["name"]) && strlen($upload["name"]) <= 255
            && is_valid_utf8($upload["name"]))
            $args["filename"] = $upload["name"];
        $fnhtml = isset($args["filename"]) ? " “" . htmlspecialchars($args["filename"]) . "”" : "";
        if (($content = file_get_contents($upload["tmp_name"])) === false)
            $args["error_html"] = "Uploaded file$fnhtml could not be read.";
        else if ($content === "")
            $args["error_html"] = "Uploaded file$fnhtml was empty, not saving.";
        else
            $args["content"] = $content;
        if (get($upload, "type"))
            $args["mimetype"] = Mimetype::type(get($upload, "type"));
        $args["mimetype"] = Mimetype::content_type($content, get($args, "mimetype"));
        return new DocumentInfo($args);
    }


    function filename($filters = null) {
        return HotCRPDocument::filename($this, $filters);
    }

    function compute_sha1() {
        $sha1 = Filer::binary_sha1($this->sha1);
        if ($sha1 === false && is_string($this->content))
            $sha1 = sha1($this->content, true);
        else if ($sha1 === false && $this->filestore && is_readable($this->filestore)) {
            if (is_executable("/usr/bin/sha1sum"))
                $cmd = "/usr/bin/sha1sum ";
            else if (is_executable("/usr/bin/shasum"))
                $cmd = "/usr/bin/shasum -a 1 ";
            if ($cmd
                && ($result = exec($cmd . escapeshellarg($this->filestore), $cmd_out, $cmd_status))
                && $cmd_status == 0
                && ($sha1 = Filer::binary_sha1(trim($result))) !== false)
                /* skip */;
            else if (($this->content = file_get_contents($this->filestore)) !== false)
                $sha1 = sha1($this->content, true);
        }
        return $sha1;
    }

    function save() {
        // look for an existing document with same sha1; otherwise upload
        if (($sha1 = $this->compute_sha1()) !== false) {
            $this->sha1 = $sha1;
            $id = Dbl::fetch_ivalue($this->conf->dblink, "select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $this->documentType, $sha1);
            if ($id) {
                $this->paperStorageId = $id;
                return true;
            }
        }
        if (!$this->docclass)
            $this->docclass = $this->conf->docclass($this->documentType);
        return $this->docclass->upload($this);
    }

    function url($filters = null, $rest = null) {
        if ($filters === null)
            $filters = $this->filters_applied;
        return HotCRPDocument::url($this, $filters, $rest);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    const L_FINALTITLE = 4;
    const L_REQUIREFORMAT = 8;
    function link_html($html = "", $flags = 0, $filters = null) {
        $p = $this->url($filters);
        $suffix = $info = "";
        $title = null;
        $small = ($flags & self::L_SMALL) != 0;

        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0 && ($o = $this->conf->paper_opts->find($this->documentType)) && $o->final))
            $suffix = "f";
        if ($this->documentType == DTYPE_FINAL && ($flags & self::L_FINALTITLE))
            $title = "Final version";

        assert(!($flags & self::L_REQUIREFORMAT) || !!$this->prow);
        if (($this->documentType == DTYPE_SUBMISSION || $this->documentType == DTYPE_FINAL)
            && ($specwhen = $this->conf->setting("sub_banal" . ($this->documentType ? "_m1" : "")))
            && $this->prow) {
            $specstatus = 0;
            if ($this->prow->is_joindoc($this))
                $specstatus = $this->prow->pdfFormatStatus;
            if ($specstatus == -$specwhen && $small)
                $suffix .= "x";
            else if ($specstatus != $specwhen) {
                $cf = new CheckFormat($flags & self::L_REQUIREFORMAT ? CheckFormat::RUN_PREFER_NO : CheckFormat::RUN_NO);
                $cf->check_document($this->prow, $this);
                if ($cf->has_error()) {
                    $suffix .= "x";
                    if (!$small)
                        $info = '<span class="need-tooltip" style="font-weight:bold" data-tooltip="' . htmlspecialchars(join("<br />", $cf->messages())) . '">ⓘ</span>';
                }
            }
        }

        if ($this->mimetype == "application/pdf")
            list($img, $alt) = ["pdf", "[PDF]"];
        else if ($this->mimetype == "application/postscript")
            list($img, $alt) = ["postscript", "[PS]"];
        else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype);
            $alt = "[" . ($m && $m->description ? $m->description : $this->mimetype) . "]";
        }

        $x = '<a href="' . $p . '" class="q">'
            . Ht::img($img . $suffix . ($small ? "" : "24") . ".png", $alt, ["class" => $small ? "sdlimg" : "dlimg", "title" => $title]);
        if ($html)
            $x .= "&nbsp;" . $html;
        if (isset($this->size) && $this->size > 0 && !($flags && self::L_NOSIZE)) {
            $x .= "&nbsp;<span class=\"dlsize\">" . ($html ? "(" : "");
            if ($this->size > 921)
                $x .= round($this->size / 1024);
            else
                $x .= max(round($this->size / 102.4), 1) / 10;
            $x .= "kB" . ($html ? ")" : "") . "</span>";
        }
        return $x . "</a>" . ($info ? "&nbsp;$info" : "");
    }

    function metadata() {
        if ($this->is_partial && !isset($this->infoJson) && !isset($this->infoJson_str)) {
            $this->infoJson_str = Dbl::fetch_value($this->conf->dblink, "select infoJson from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId) ? : "";
            $this->infoJson = json_decode($this->infoJson_str);
        }
        return $this->infoJson;
    }

    function update_metadata($delta, $quiet = false) {
        if ($this->paperStorageId <= 1)
            return false;
        $length_ok = true;
        $this->infoJson_str = Dbl::compare_and_swap($this->conf->dblink,
            "select infoJson from PaperStorage where paperId=? and paperStorageId=?", [$this->paperId, $this->paperStorageId],
            function ($old) use ($delta, &$length_ok) {
                $j = json_object_replace($old ? json_decode($old) : null, $delta, true);
                $new = $j ? json_encode($j) : null;
                $length_ok = $new === null || strlen($new) <= 32768;
                return $length_ok ? $new : $old;
            },
            "update PaperStorage set infoJson=?{desired} where paperId=? and paperStorageId=? and infoJson?{expected}e", [$this->paperId, $this->paperStorageId]);
        $this->infoJson = is_string($this->infoJson_str) ? json_decode($this->infoJson_str) : null;
        if (!$length_ok && !$quiet)
            error_log(caller_landmark() . ": {$this->conf->dbname}: update_metadata(paper $this->paperId, dt $this->documentType): delta too long, delta " . json_encode($delta));
        return $length_ok;
    }

    function load_to_filestore() {
        return $this->docclass->load_to_filestore($this);
    }

    function npages() {
        if ($this->mimetype && $this->mimetype != "application/pdf")
            return null;
        else if (($m = $this->metadata()) && isset($m->npages))
            return $m->npages;
        else if ($this->docclass->load_to_filestore($this)) {
            $cf = new CheckFormat;
            $cf->clear();
            $bj = $cf->run_banal($this->filestore);
            if ($bj && is_object($bj) && isset($bj->pages)) {
                $this->update_metadata(["npages" => count($bj->pages), "banal" => $bj]);
                return count($bj->pages);
            }
        }
        return null;
    }

    function jsonSerialize() {
        $x = [];
        foreach (get_object_vars($this) as $k => $v)
            if ($k === "content" && is_string($v) && strlen($v) > 50)
                $x[$k] = substr($v, 0, 50) . "…";
            else if ($k === "sha1" && is_string($v))
                $x[$k] = Filer::text_sha1($v);
            else if ($k !== "conf" && $k !== "docclass" && $k !== "infoJson_str" && $v !== null)
                $x[$k] = $v;
        return $x;
    }
}
