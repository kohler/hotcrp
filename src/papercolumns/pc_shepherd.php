<?php
// pc_shepherd.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Shepherd_PaperColumn extends PaperColumn {
    /** @var int */
    private $nameflags;
    /** @var bool */
    private $was_reset = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function view_option_schema() {
        return self::user_view_option_schema();
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_shepherd(null)
            || (!$pl->conf->has_any_lead_or_shepherd() && !$visible)) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        $this->nameflags = $this->user_view_option_name_flags($pl->conf);
        return true;
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->shepherdContactId > 0 && $pl->user->can_view_shepherd($row)) {
            return $row->shepherdContactId;
        } else {
            return 0;
        }
    }
    function reset(PaperList $pl) {
        if (!$this->was_reset && $pl->conf->setting("extrev_shepherd")) {
            foreach ($pl->rowset() as $row) {
                if ($row->shepherdContactId > 0)
                    $pl->conf->prefetch_user_by_id($row->shepherdContactId);
            }
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
        return $pl->user_content($row->shepherdContactId, $row, $this->nameflags);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->user_text($row->shepherdContactId, $this->nameflags);
    }
}
