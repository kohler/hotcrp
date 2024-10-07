<?php
// pc_administrator.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Administrator_PaperColumn extends PaperColumn {
    /** @var int */
    private $nameflags;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function view_option_schema() {
        return self::user_view_option_schema();
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_manager(null)) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        $this->nameflags = $this->user_view_option_name_flags($pl->conf);
        return true;
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->managerContactId && $pl->user->can_view_manager($row)) {
            return $row->managerContactId;
        } else {
            return 0;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ianno = $this->nameflags & NAME_L ? Contact::SORTSPEC_LAST : Contact::SORTSPEC_FIRST;
        return $pl->user_compare(self::cid($pl, $a), self::cid($pl, $b), $ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->user_content($row->managerContactId, $row, $this->nameflags);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->user_text($row->managerContactId, $this->nameflags);
    }
}
