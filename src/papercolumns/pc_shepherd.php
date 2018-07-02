<?php
// pc_shepherd.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Shepherd_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
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
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $pl = $sorter->list;
        return $pl->_compare_pc(self::cid($pl, $a), self::cid($pl, $b));
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
