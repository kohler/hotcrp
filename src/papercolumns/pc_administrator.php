<?php
// pc_administrator.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class Administrator_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_manager(null);
    }
    function header(PaperList $pl, $is_text) {
        return "Administrator";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$row->managerContactId
            || !$pl->user->can_view_manager($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->managerContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->managerContactId);
    }
}
