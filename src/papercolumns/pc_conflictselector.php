<?php
// pc_conflictselector.php -- HotCRP conflict selector list column
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ConflictSelector_PaperColumn extends PaperColumn {
    private $contact;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        if (!$pl->user->is_manager())
            return false;
        if (($tid = $pl->table_id()))
            $pl->add_header_script('paperlist_ui.prepare_assrev(' . json_encode_browser("#$tid") . ')');
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Conflict?";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->conflict_type($this->contact) > 0);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $disabled = $row->conflict_type($this->contact) >= CONFLICT_AUTHOR;
        if (!$pl->user->allow_administer($row)) {
            $disabled = true;
            if (!$pl->user->can_view_conflicts($row))
                return "";
        }
        $pl->mark_has("sel");
        $c = "";
        if ($disabled)
            $c .= ' disabled="disabled"';
        if ($this->checked($pl, $row)) {
            $c .= ' checked="checked"';
            unset($row->folded);
        }
        return '<input type="checkbox" class="assrev" '
            . 'name="assrev' . $row->paperId . 'u' . $this->contact->contactId
            . '" value="-1"' . $c . ' />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $this->checked($pl, $row) ? "Y" : "N";
    }
}
