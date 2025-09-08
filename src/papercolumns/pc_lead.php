<?php
// pc_lead.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Lead_PaperColumn extends PaperColumn {
    /** @var int */
    private $nameflags;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function view_option_schema() {
        return self::user_view_option_schema();
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_lead(null)
            || (!$pl->conf->has_any_lead_or_shepherd()
                && $visible === FieldRender::CFSUGGEST)) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        $this->nameflags = $this->user_view_option_name_flags($pl->conf);
        return true;
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->leadContactId > 0 && $pl->user->can_view_lead($row)) {
            return $row->leadContactId;
        }
        return 0;
    }
    function sort_name() {
        return $this->sort_name_with_options("format");
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ianno = $this->nameflags & NAME_L ? Contact::SORTSPEC_LAST : Contact::SORTSPEC_FIRST;
        return $pl->user_compare(self::cid($pl, $a), self::cid($pl, $b), $ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->user_content($row->leadContactId, $row, $this->nameflags);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->user_text($row->leadContactId, $this->nameflags);
    }
}
