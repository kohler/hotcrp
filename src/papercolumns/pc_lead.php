<?php
// pc_lead.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class Lead_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD;
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_lead(null, true)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    function header(PaperList $pl, $is_text) {
        return "Discussion lead";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->leadContactId
            || !$pl->user->can_view_lead($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->leadContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->leadContactId);
    }
}
