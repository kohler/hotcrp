<?php
// pc_lead.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Lead_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_lead(null)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->leadContactId && $pl->user->can_view_lead($row)) {
            return (int) $row->leadContactId;
        } else {
            return 0;
        }
    }
    function analyze_sort(PaperList $pl, PaperInfoSet $rows, ListSorter $sorter) {
        $sorter->ianno = Contact::parse_sortspec($pl->conf, $sorter->anno);
    }
    function sort_name(PaperList $pl, ListSorter $sorter = null) {
        return PaperColumn::decorate_user_sort_name($this->name, $pl, $sorter);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $pl = $sorter->pl;
        return $pl->_compare_pc(self::cid($pl, $a), self::cid($pl, $b), $sorter);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc((int) $row->leadContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc((int) $row->leadContactId);
    }
}
