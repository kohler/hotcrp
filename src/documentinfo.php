<?php
// paperinfo.php -- HotCRP document objects
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class DocumentInfo implements JsonSerializable {
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

    public function __construct($p = null) {
        $this->merge($p);
    }

    private function merge($p) {
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
        $this->docclass = HotCRPDocument::get($this->documentType);
        if (isset($this->paper) && !isset($this->content))
            $this->content = $this->paper;
        if ($this->error_html)
            $this->error = true;

        // set sha1 if content is available
        if ($this->content && $this->sha1 == "")
            $this->sha1 = sha1($this->content, true);
        // set sha1 in database if needed ()
        if ($this->paperStorageId > 1
            && $this->sha1 == ""
            && $this->docclass->load_content($this)) {
            $this->sha1 = sha1($this->content, true);
            Dbl::q("update PaperStorage set sha1=? where paperStorageId=?", $this->sha1, $this->paperStorageId);
        }
    }

    static public function fetch($result) {
        $di = $result ? $result->fetch_object("DocumentInfo") : null;
        if ($di && !is_int($di->paperStorageId))
            $di->merge(null);
        return $di;
    }

    static public function id_by_sha1($paperId, $dtype, $sha1) {
        if (($sha1 = Filer::binary_sha1($sha1)) !== false)
            return Dbl::fetch_ivalue("select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $paperId, $dtype, $sha1) ? : false;
        else
            return false;
    }

    static public function make_file_upload($paperId, $documentType, $upload) {
        if (is_string($upload) && $upload)
            $upload = $_FILES[$upload];
        if (!$upload || !is_array($upload) || !fileUploaded($upload)
            || !isset($upload["tmp_name"]))
            return null;
        $args = ["paperId" => $paperId,
                 "documentType" => $documentType,
                 "timestamp" => time(),
                 "mimetype" => Mimetype::type(get($upload, "type", "application/octet-stream"))];
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
        return new DocumentInfo($args);
    }


    public function filename($filters = null) {
        return HotCRPDocument::filename($this, $filters);
    }

    public function compute_sha1() {
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

    public function save() {
        // look for an existing document with same sha1; otherwise upload
        if (($sha1 = $this->compute_sha1()) !== false) {
            $this->sha1 = $sha1;
            if (($this->paperStorageId = self::id_by_sha1($this->paperId, $this->documentType, $sha1)))
                return true;
        }
        if (!$this->docclass)
            $this->docclass = HotCRPDocument::get($this->documentType);
        return $this->docclass->upload($this, (object) ["paperId" => $this->paperId]);
    }

    public function url($filters = null, $rest = null) {
        if ($filters === null)
            $filters = $this->filters_applied;
        return HotCRPDocument::url($this, $filters, $rest);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    public function link_html($html = "", $flags = 0, $filters = null) {
        global $Conf;
        $p = $this->url($filters);

        $finalsuffix = "";
        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0 && ($o = PaperOption::find($this->documentType)) && $o->final))
            $finalsuffix = "f";

        if ($this->mimetype == "application/pdf")
            list($img, $alt) = ["pdf", "[PDF]"];
        else if ($this->mimetype == "application/postscript")
            list($img, $alt) = ["postscript", "[PS]"];
        else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype, true);
            $alt = "[" . ($m && $m->description ? : $this->mimetype) . "]";
        }

        $small = ($flags & self::L_SMALL) != 0;
        $x = '<a href="' . $p . '" class="q">'
            . Ht::img($img . $finalsuffix . ($small ? "" : "24") . ".png", $alt, $small ? "sdlimg" : "dlimg");
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
        return $x . "</a>";
    }

    public function metadata() {
        if ($this->is_partial && !isset($this->infoJson) && !isset($this->infoJson_str)) {
            $this->infoJson_str = Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $this->paperStorageId) ? : "";
            $this->infoJson = json_decode($this->infoJson_str);
        }
        return $this->infoJson;
    }

    public function update_metadata($delta, $quiet = false) {
        global $Conf;
        if ($this->paperStorageId <= 1)
            return false;
        $length_ok = true;
        $this->infoJson_str = Dbl::compare_and_swap($Conf->dblink,
            "select infoJson from PaperStorage where paperStorageId=?", [$this->paperStorageId],
            function ($old) use ($delta, &$length_ok) {
                $j = json_object_replace($old ? json_decode($old) : null, $delta, true);
                $new = $j ? json_encode($j) : null;
                $length_ok = $new === null || strlen($new) <= 32768;
                return $length_ok ? $new : $old;
            },
            "update PaperStorage set infoJson=?{desired} where paperStorageId=? and infoJson?{expected}e", [$this->paperStorageId]);
        $this->infoJson = is_string($this->infoJson_str) ? json_decode($this->infoJson_str) : null;
        if (!$length_ok && !$quiet)
            error_log(caller_landmark() . ": " . $Conf->opt->dbName . ": update_metadata(paper $this->paperId, dt $this->documentType): delta too long, delta " . json_encode($delta));
        return $length_ok;
    }

    public function load_to_filestore() {
        return $this->docclass->load_to_filestore($this);
    }

    public function npages() {
        if ($this->mimetype && $this->mimetype != "application/pdf")
            return false;
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

    public function jsonSerialize() {
        $x = [];
        foreach (get_object_vars($this) as $k => $v)
            if ($k === "content" && is_string($v) && strlen($v) > 50)
                $x[$k] = substr($v, 0, 50) . "…";
            else if ($k === "sha1" && is_string($v))
                $x[$k] = Filer::text_sha1($v);
            else if ($k !== "docclass" && $k !== "infoJson_str" && $v !== null)
                $x[$k] = $v;
        return $x;
    }
}
