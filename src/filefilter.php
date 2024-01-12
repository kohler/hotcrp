<?php
// filefilter.php -- HotCRP helper class for filtering documents
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class FileFilter {
    /** @var int */
    public $id;
    /** @var string */
    public $name;

    /** @return array<string,FileFilter> */
    static function all_by_name(Conf $conf) {
        if ($conf->_file_filters === null) {
            $conf->_file_filters = [];
            if (($flist = $conf->opt("documentFilters"))) {
                $ffa = new FileFilterJsonExpander($conf);
                expand_json_includes_callback($flist, [$ffa, "_add_json"]);
            }
        }
        return $conf->_file_filters;
    }
    /** @param string $name
     * @return ?FileFilter */
    static function find_by_name(Conf $conf, $name) {
        return (self::all_by_name($conf))[$name] ?? null;
    }

    /** @param DocumentInfo $doc
     * @param string $name
     * @return DocumentInfo */
    static function apply_named(DocumentInfo $doc, $name) {
        if (($filter = self::find_by_name($doc->conf, $name))
            && ($xdoc = $filter->exec($doc))) {
            return $xdoc;
        } else {
            return $doc;
        }
    }

    /** @return ?DocumentInfo */
    function exec(DocumentInfo $doc) {
        return null;
    }

    /** @param DocumentInfo $doc
     * @return ?DocumentInfo */
    function find_filtered(DocumentInfo $doc) {
        if ($this->id) {
            $result = $doc->conf->qe("select PaperStorage.* from FilteredDocument join PaperStorage on (PaperStorage.paperStorageId=FilteredDocument.outDocId) where inDocId=? and FilteredDocument.filterType=?", $doc->paperStorageId, $this->id);
            $fdoc = DocumentInfo::fetch($result, $doc->conf);
            Dbl::free($result);
        } else {
            $fdoc = null;
        }
        if ($fdoc) {
            $fdoc->filters_applied = $doc->filters_applied;
            $fdoc->filters_applied[] = $this;
        }
        return $fdoc;
    }

    function mimetype(DocumentInfo $doc, $mimetype) {
        return $mimetype;
    }
}

class FileFilterJsonExpander {
    /** @var Conf */
    private $conf;
    function __construct(Conf $conf) {
        $this->conf = $conf;
    }
    function _add_json($fj) {
        if (is_object($fj)
            && (!isset($fj->id) || is_int($fj->id))
            && isset($fj->name) && is_string($fj->name) && $fj->name !== ""
            && ctype_alnum($fj->name) && !ctype_digit($fj->name)
            && isset($fj->function) && is_string($fj->function)) {
            if ($fj->function[0] === "+") {
                $class = substr($fj->function, 1);
                /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
                $ff = new $class($this->conf, $fj);
            } else {
                $ff = call_user_func($fj->function, $this->conf, $fj);
            }
            if ($ff) {
                assert($ff instanceof FileFilter);
                $ff->id = $fj->id ?? null;
                $ff->name = $fj->name;
                $this->conf->_file_filters[$ff->name] = $ff;
                return true;
            }
        }
        return false;
    }
}
