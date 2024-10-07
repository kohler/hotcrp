<?php
// pc_administrator.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Administrator_PaperColumn extends PaperColumn {
    /** @var int */
    private $ianno;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function decoration_spec() {
        return PaperColumn::user_sort_decoration_spec();
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_manager(null)) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        return true;
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->managerContactId && $pl->user->can_view_manager($row)) {
            return $row->managerContactId;
        } else {
            return 0;
        }
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->ianno = Contact::parse_sortspec($pl->conf, $this->decoration_list());
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $pl->user_compare(self::cid($pl, $a), self::cid($pl, $b), $this->ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->user_content($row->managerContactId, $row);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->user_text($row->managerContactId);
    }
}
