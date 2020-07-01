<?php
// documentinfoset.php -- HotCRP document set
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentInfoSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var list<string> */
    private $ufn = [];
    /** @var list<DocumentInfo> */
    private $docs = [];
    function add(DocumentInfo $doc) {
        $fn = $doc->filename ?? "";
        while ($fn !== "" && in_array($fn, $this->ufn)) {
            if (preg_match('/\A(.*\()(\d+)(\)(?:\.\w+|))\z/', $fn, $m)) {
                $fn = $m[1] . ((int) $m[2] + 1) . $m[3];
            } else if (preg_match('/\A(.*?)(\.\w+|)\z/', $fn, $m) && $m[1] !== "") {
                $fn = $m[1] . " (1)" . $m[2];
            } else {
                $fn .= " (1)";
            }
        }
        $this->ufn[] = $fn;
        $this->docs[] = $doc->with_member_filename($fn);
    }
    /** @return list<DocumentInfo> */
    function as_list() {
        return $this->docs;
    }
    /** @return list<int> */
    function document_ids() {
        return array_map(function ($doc) { return $doc->paperStorageId; }, $this->docs);
    }
    /** @return int */
    function size() {
        return count($this->docs);
    }
    /** @return int */
    function count() {
        return count($this->docs);
    }
    /** @return bool */
    function is_empty() {
        return empty($this->docs);
    }
    /** @param int $i
     * @return ?DocumentInfo */
    function document_by_index($i) {
        return $this->docs[$i] ?? null;
    }
    /** @param int $i
     * @return DocumentInfo */
    function checked_document_by_index($i) {
        $doc = $this->docs[$i] ?? null;
        if (!$doc) {
            throw new Exception("DocumentInfoSet::checked_document_by_index($i) failure");
        }
        return $doc;
    }
    /** @param string $fn
     * @return ?DocumentInfo */
    function document_by_filename($fn) {
        $i = array_search($fn, $this->ufn);
        return $i !== false && $fn !== "" ? $this->docs[$i] : null;
    }
    /** @param int $i
     * @return ?string */
    function filename_by_index($i) {
        return $this->ufn[$i] ?? null;
    }
    /** @return Iterator<DocumentInfo> */
    function getIterator() {
        return new ArrayIterator($this->docs);
    }
    /** @param int|string $offset
     * @return bool */
    function offsetExists($offset) {
        return is_int($offset)
            ? isset($this->docs[$offset])
            : $offset !== "" && in_array($offset, $this->ufn);
    }
    /** @param int|string $offset
     * @return ?DocumentInfo */
    function offsetGet($offset) {
        if (!is_int($offset) && $offset !== "") {
            $offset = array_search($offset, $this->ufn);
        }
        return is_int($offset) ? $this->docs[$offset] ?? null : null;
    }
    function offsetSet($offset, $value) {
        throw new Exception("invalid DocumentInfoSet::offsetSet");
    }
    function offsetUnset($offset) {
        throw new Exception("invalid DocumentInfoSet::offsetUnset");
    }
}
