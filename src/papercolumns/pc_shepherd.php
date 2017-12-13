<?php
// pc_shepherd.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class Shepherd_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
        $this->override = PaperColumn::OVERRIDE_FOLD;
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_shepherd(null, true)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    function header(PaperList $pl, $is_text) {
        return "Shepherd";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->shepherdContactId
            || !$pl->user->can_view_shepherd($row);
        // XXX external reviewer can view shepherd even if external reviewer
        // cannot view reviewer identities? WHO GIVES A SHIT
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->shepherdContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->shepherdContactId);
    }
}
