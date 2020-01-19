<?php
// pc_shepherd.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class Shepherd_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_shepherd(null)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->shepherdContactId && $pl->user->can_view_shepherd($row))
            return $row->shepherdContactId;
        return 0;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        $sorter->anno = Contact::parse_sortanno($pl->conf, $sorter->anno);
    }
    function sort_name(PaperList $pl, ListSorter $sorter = null) {
        return $this->name . PaperColumn::contact_sort_anno($pl, $sorter);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $pl = $sorter->pl;
        return $pl->_compare_pc(self::cid($pl, $a), self::cid($pl, $b), $sorter);
    }
    function header(PaperList $pl, $is_text) {
        return "Shepherd";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->shepherdContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->shepherdContactId);
    }
}
