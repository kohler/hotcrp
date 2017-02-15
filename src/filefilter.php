<?php
// filefilter.php -- HotCRP helper class for filtering documents
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FileFilter {
    public $id;
    public $name;

    static private $filter_by_name;
    static private $filter_by_id;

    static public function _add_json($fj) {
        if (is_object($fj)
            && (!isset($fj->id) || is_int($fj->id))
            && isset($fj->name) && is_string($fj->name)
            && $fj->name !== "" && ctype_alnum($fj->name)) {
            $ff = null;
            if (($factory_class = get($fj, "factory_class")))
                $ff = new $factory_class($fj);
            else if (($factory = get($fj, "factory")))
                $ff = call_user_func($factory, $fj);
            if ($ff) {
                $ff->id = get($fj, "id");
                $ff->name = $fj->name;
                if ($ff->id !== null)
                    self::$filter_by_id[$ff->id] = $ff;
                self::$filter_by_name[$fj->name] = $ff;
                return true;
            }
        }
        return false;
    }
    static private function load() {
        if (self::$filter_by_name === null) {
            self::$filter_by_name = self::$filter_by_id = [];
            if (($flist = opt("documentFilters")))
                expand_json_includes_callback($flist, "FileFilter::_add_json");
        }
    }

    static public function find_by_name($name) {
        self::load();
        return get(self::$filter_by_name, $name);
    }
    static public function all_by_name() {
        self::load();
        return self::$filter_by_name;
    }
    static public function apply_named($doc, PaperInfo $prow, $name) {
        if (($filter = self::find_by_name($name))
            && ($xdoc = $filter->apply($doc, $prow)))
            return $xdoc;
        return $doc;
    }

    public function find_filtered($doc) {
        if ($this->id) {
            $result = $doc->conf->qe("select PaperStorage.* from FilteredDocument join PaperStorage on (PaperStorage.paperStorageId=FilteredDocument.outDocId) where inDocId=? and FilteredDocument.filterType=?", $doc->paperStorageId, $this->id);
            $fdoc = DocumentInfo::fetch($result, $doc->conf);
            Dbl::free($result);
        } else
            $fdoc = null;
        if ($fdoc) {
            $fdoc->filters_applied = $doc->filters_applied;
            $fdoc->filters_applied[] = $this;
        }
        return $fdoc;
    }

    public function mimetype($doc, $mimetype) {
        return $mimetype;
    }

    public function apply($doc, PaperInfo $prow) {
        return false;
    }
}
