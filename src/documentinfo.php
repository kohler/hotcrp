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
    public $sha1; // should be binary hash
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
    public $sourceHash;
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
        if ($p) {
            foreach ($p as $k => $v)
                if ($k === "hash")
                    $this->sha1 = $v;
                else
                    $this->$k = $v;
        }
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->paperId = (int) $this->paperId;
        $this->documentType = (int) $this->documentType;
        $this->timestamp = (int) $this->timestamp;
        assert($this->paperStorageId <= 1 || !!$this->mimetype);
        if ($this->sha1 != "")
            $this->sha1 = Filer::hash_as_binary($this->sha1);
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
        if ($this->sourceHash != "")
            $this->sourceHash = Filer::hash_as_binary($this->sourceHash);
        $this->docclass = $this->conf->docclass($this->documentType);
        if (isset($this->paper) && !isset($this->content))
            $this->content = $this->paper;
        if ($this->error_html)
            $this->error = true;

        // set sha1
        if ($this->sha1 == "" && $this->paperStorageId > 1
            && $this->docclass->load_content($this)) {
            // store sha1 in database if needed (backwards compat)
            $this->conf->q("update PaperStorage set sha1=? where paperId=? and paperStorageId=?", $this->binary_hash(), $this->paperId, $this->paperStorageId);
            // we might also need to update the joindoc
            if ($this->documentType == DTYPE_SUBMISSION)
                $this->conf->q("update Paper set sha1=? where paperId=? and paperStorageId=? and finalPaperStorageId<=0", $this->binary_hash(), $this->paperId, $this->paperStorageId);
            else if ($this->documentType == DTYPE_FINAL)
                $this->conf->q("update Paper set sha1=? where paperId=? and finalPaperStorageId=?", $this->binary_hash(), $this->paperStorageId);
        }
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


    function set_content($content, $mimetype = null) {
        $this->content = $content;
        $this->mimetype = $mimetype;
        $this->sha1 = "";
    }


    function filename($filters = null) {
        return HotCRPDocument::filename($this, $filters);
    }


    function has_hash() {
        return !!$this->sha1;
    }
    function text_hash() {
        return Filer::hash_as_text($this->binary_hash());
    }
    function binary_hash() {
        if ($this->sha1 == "")
            $this->sha1 = $this->content_binary_hash();
        return $this->sha1;
    }
    function binary_hash_data() {
        $hash = $this->binary_hash();
        if ($hash === false || strlen($hash) === 20)
            return $hash;
        else
            return substr($hash, strpos($hash, "-") + 1);
    }
    function check_text_hash($hash) {
        return Filer::check_text_hash($this->text_hash(), $hash);
    }
    function hash_algorithm() {
        if (strlen($this->sha1) === 20)
            return "sha1";
        else if ($this->sha1 && substr($this->sha1, 0, 5) === "sha2-")
            return "sha256";
        else
            return false;
    }
    function hash_algorithm_prefix() {
        if (strlen($this->sha1) === 20)
            return "";
        else if ($this->sha1)
            return substr($this->sha1, 0, strpos($this->sha1, "-") + 1);
        else
            return false;
    }
    function content_binary_hash($like_hash = false) {
        // never cached
        if ($like_hash) {
            list($x1, $x2, $algorithm) = Filer::analyze_hash($like_hash);
        } else
            $algorithm = $this->conf->opt("contentHashMethod");
        if ($algorithm !== "sha1" && $algorithm !== "sha256")
            $algorithm = "sha256";
        $pfx = ($algorithm === "sha1" ? "" : "sha2-");
        if (is_string($this->content))
            return $pfx . hash($algorithm, $this->content, true);
        else if ($this->filestore && is_readable($this->filestore)) {
            $hctx = hash_init($algorithm);
            if (hash_update_file($hctx, $this->filestore))
                return $pfx . hash_final($hctx, true);
        }
        return false;
    }

    function save() {
        // look for an existing document with same sha1; otherwise upload
        $hash = false;
        if ($this->sha1 != "")
            $hash = Filer::hash_as_binary($this->sha1);
        if ($hash === false)
            $hash = $this->content_binary_hash();
        if ($hash !== false) {
            $this->sha1 = $hash;
            $id = Dbl::fetch_ivalue($this->conf->dblink, "select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $this->documentType, $hash);
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
            || ($this->documentType > 0 && ($o = $this->conf->paper_opts->get($this->documentType)) && $o->final))
            $suffix = "f";
        if ($this->documentType == DTYPE_FINAL && ($flags & self::L_FINALTITLE))
            $title = "Final version";

        assert(!($flags & self::L_REQUIREFORMAT) || !!$this->prow);
        if (($this->documentType == DTYPE_SUBMISSION || $this->documentType == DTYPE_FINAL)
            && $this->prow)
            list($info, $suffix) = $this->link_html_format_info($flags, $suffix);

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
    private function link_html_format_info($flags, $suffix) {
        if (($spects = $this->conf->format_spec($this->documentType)->timestamp)) {
            if ($this->prow->is_joindoc($this)) {
                $specstatus = $this->prow->pdfFormatStatus;
                if ($specstatus == -$spects && ($flags & self::L_SMALL))
                    return ["", $suffix . "x"];
                else if ($specstatus == $spects)
                    return ["", $suffix];
            }
            $runflag = CheckFormat::RUN_NO;
            if (($flags & self::L_REQUIREFORMAT)
                || (CheckFormat::$runcount < 3 && mt_rand(0, 7) == 0))
                $runflag = CheckFormat::RUN_PREFER_NO;
            $cf = new CheckFormat($runflag);
            $cf->check_document($this->prow, $this);
            if ($cf->has_error()) {
                if ($flags & self::L_SMALL)
                    return ["", $suffix . "x"];
                else
                    return ['<span class="need-tooltip" style="font-weight:bold" data-tooltip="' . htmlspecialchars(join("<br />", $cf->messages())) . '">ⓘ</span>', $suffix . "x"];
            }
        }
        return ["", $suffix];
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
                $x[$k] = Filer::hash_as_text($v);
            else if ($k !== "conf" && $k !== "docclass" && $k !== "infoJson_str" && $v !== null)
                $x[$k] = $v;
        return $x;
    }
}
