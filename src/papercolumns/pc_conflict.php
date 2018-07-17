<?php
// pc_conflict.php -- HotCRP conflict list column
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Conflict_PaperColumn extends PaperColumn {
    private $contact;
    private $show_user;
    private $not_me;
    private $show_description;
    private $editable = false;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_FOLD_IFEMPTY;
        if (($this->show_user = isset($cj->user)))
            $this->contact = $conf->pc_member_by_email($cj->user);
        $this->show_description = !!get($cj, "show_description");
        if (get($cj, "edit"))
            $this->mark_editable();
        $this->editable = !!get($cj, "edit");
    }
    function mark_editable() {
        $this->editable = true;
        $this->override = PaperColumn::OVERRIDE_FOLD_BOTH;
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        return true;
    }
    private function conflict_type(PaperList $pl, $row) {
        if (!$this->not_me || $pl->user->can_view_conflicts($row)) {
            $ct = $row->conflict_type($this->contact);
            if ($this->show_description
                && $ct > 1
                && !$pl->user->can_view_authors($row))
                $ct = 1;
            return $ct;
        } else
            return 0;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $act = $this->conflict_type($pl, $a);
        $bct = $this->conflict_type($pl, $b);
        if ($this->show_description)
            return $bct - $act;
        else
            return ($bct ? 1 : 0) - ($act ? 1 : 0);
    }
    function header(PaperList $pl, $is_text) {
        if ((!$this->show_user && !$this->not_me && !$this->editable)
            || $pl->report_id() === "conflictassign")
            return "Conflict";
        else if ($is_text)
            return $pl->user->name_text_for($this->contact) . " conflict";
        else
            return $pl->user->name_html_for($this->contact) . "<br>conflict";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->conflict_type($this->contact) > 0);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->not_me
            && !$pl->user->can_administer($row)
            && !$pl->user->can_view_conflicts($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($this->editable
            && ($t = $this->edit_content($pl, $row)))
            return $t;
        $ct = $this->conflict_type($pl, $row);
        if (!$ct)
            return "";
        else if (!$this->show_description || $ct == 1)
            return review_type_icon(-1);
        else if ($ct >= CONFLICT_AUTHOR)
            return "Author";
        else
            return get(Conflict::$type_descriptions, $ct, "Other");
    }
    function edit_content(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->allow_administer($row))
            return false;
        $ct = $row->conflict_type($this->contact);
        if ($ct >= CONFLICT_AUTHOR)
            return "Author";
        return '<input type="checkbox" class="uix uikd js-range-click uich js-assign-review" '
            . 'data-range-type="assrevu' . ($this->show_user ? $this->contact->contactId : "")
            . '" name="assrev' . $row->paperId . 'u' . $this->contact->contactId
            . '" value="-1"' . ($ct ? " checked" : "") . ' />';
    }
    function text(PaperList $pl, PaperInfo $row) {
        $ct = $this->conflict_type($pl, $row);
        if (!$ct)
            return "N";
        else if (!$this->show_description || $ct == 1)
            return "Y";
        else if ($ct >= CONFLICT_AUTHOR)
            return "Author";
        else
            return get(Conflict::$type_descriptions, $ct, "Other");
    }

    static function expand($name, Conf $conf, $xfj, $m) {
        if (!($fj = (array) $conf->basic_paper_column($m[1], $conf->xt_user)))
            return null;
        $rs = [];
        $cs = new ContactSearch(ContactSearch::F_PC | ContactSearch::F_TAG | ContactSearch::F_USER, $m[2], $conf->xt_user);
        foreach ($cs->ids as $cid) {
            $u = $conf->pc_member_by_id($cid);
            $fj["name"] = $m[1] . ":" . $u->email;
            $fj["user"] = $u->email;
            $rs[] = (object) $fj;
        }
        if (empty($rs))
            $conf->xt_factory_error("No PC member matches “" . htmlspecialchars($m[2]) . "”.");
        return $rs;
    }
}
