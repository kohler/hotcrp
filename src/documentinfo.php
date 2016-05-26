<?php
// paperinfo.php -- HotCRP document objects
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class DocumentInfo {
    public $paperStorageId;
    public $paperId;
    public $documentType;
    public $timestamp;
    public $mimetype;
    public $mimetypeid;
    public $sha1;
    public $size;
    public $content;
    public $compression;
    public $filename;
    public $unique_filename;
    public $infoJson;
    public $infoJson_str;
    public $filterType;
    public $originalStorageId;
    public $docclass;
    public $is_partial = false;

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
        $this->mimetypeid = (int) $this->mimetypeid;
        $this->size = (int) $this->size;
        if (is_string($this->infoJson))
            $this->infoJson_str = $this->infoJson;
        $this->infoJson = $this->infoJson ? json_decode($this->infoJson) : null;
        $this->filterType = $this->filterType ? (int) $this->filterType : null;
        $this->originalStorageId = $this->originalStorageId ? (int) $this->originalStorageId : null;
        $this->docclass = HotCRPDocument::get($this->documentType);

        // in modern versions sha1 is set at storage time; before it wasn't
        if ($this->paperStorageId > 1 && $this->sha1 == ""
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
}
