<?php
// pc_administrator.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Administrator_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_manager(null);
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->managerContactId && $pl->user->can_view_manager($row))
            return $row->managerContactId;
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
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->managerContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->managerContactId);
    }
}
