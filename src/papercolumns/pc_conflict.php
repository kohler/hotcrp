<?php
// pc_conflict.php -- HotCRP conflict list column
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Conflict_PaperColumn extends PaperColumn {
    /** @var ?Contact */
    private $contact;
    /** @var bool */
    private $show_user;
    /** @var bool */
    private $not_me;
    /** @var bool */
    private $show_description;
    /** @var bool */
    private $editable = false;
    /** @var bool */
    private $simple = false;
    /** @var bool */
    private $pin_no = false;
    /** @var bool */
    private $pin_yes = false;
    /** @var string */
    private $usuffix;
    /** @var Conflict */
    private $cset;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        if (($this->show_user = isset($cj->user))) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
        $this->show_description = ($cj->show_description ?? false)
            && $conf->setting("sub_pcconfsel");
        if ($cj->edit ?? false) {
            $this->mark_editable();
        }
    }
    function add_decoration($decor) {
        if ($decor === "simple") {
            $this->simple = true;
            return $this->__add_decoration($decor);
        } else if ($decor === "edit") {
            $this->mark_editable();
            return $this->__add_decoration($decor);
        } else if (str_starts_with($decor, "pin=")) {
            $this->pin_no = $decor === "pin=all" || $decor === "pin=unconflicted";
            $this->pin_yes = $decor === "pin=all" || $decor === "pin=conflicted";
            return $this->__add_decoration($decor);
        } else {
            return parent::add_decoration($decor);
        }
    }
    function mark_editable() {
        $this->editable = true;
        $this->override = PaperColumn::OVERRIDE_BOTH;
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ? : $pl->reviewer_user();
        $this->not_me = $this->contact->contactId !== $pl->user->contactId;
        $this->usuffix = $this->simple ? "" : "u{$this->contact->contactId}";
        $this->cset = $pl->conf->conflict_set();
        return true;
    }
    private function conflict_type(PaperList $pl, $row) {
        if (!$this->not_me || $pl->user->can_view_conflicts($row)) {
            $ct = $row->conflict_type($this->contact);
            if ($this->show_description
                && Conflict::is_conflicted($ct)
                && !$pl->user->can_view_authors($row)) {
                $ct = Conflict::GENERAL;
            }
            return $ct;
        } else {
            return 0;
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $act = $this->conflict_type($pl, $a);
        $bct = $this->conflict_type($pl, $b);
        if ($this->show_description) {
            return $act <=> $bct;
        } else {
            return ($act ? 1 : 0) <=> ($bct ? 1 : 0);
        }
    }
    function header(PaperList $pl, $is_text) {
        if ((!$this->show_user && !$this->not_me && !$this->editable)
            || $this->simple) {
            return "Conflict";
        } else if ($is_text) {
            return $pl->user->reviewer_text_for($this->contact) . " conflict";
        } else {
            return $pl->user->reviewer_html_for($this->contact) . "<br>conflict";
        }
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->has_conflict($this->contact));
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $this->not_me && !$pl->user->can_view_conflicts($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($this->editable
            && ($t = $this->edit_content($pl, $row))) {
            return $t;
        }
        $ct = $this->conflict_type($pl, $row);
        if (!Conflict::is_conflicted($ct)) {
            return "";
        } else if (!$this->show_description) {
            return review_type_icon(-1);
        } else {
            return $this->cset->unparse_html(min($ct, CONFLICT_AUTHOR));
        }
    }
    function edit_content(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->allow_administer($row)) {
            return false;
        }
        $ct = $row->conflict_type($this->contact);
        if (Conflict::is_author($ct)) {
            return "Author";
        }
        if (Conflict::is_conflicted($ct)) {
            $suffix = " checked";
            $value = $this->cset->unparse_assignment($ct);
            $nonvalue = $this->pin_no ? "pinned unconflicted" : "unconflicted";
        } else {
            $suffix = "";
            $value = $this->pin_yes ? "pinned conflicted" : "conflicted";
            $nonvalue = $this->cset->unparse_assignment($ct);
        }
        if ($this->show_user) {
            $n = htmlspecialchars($pl->user->name_text_for($this->contact));
            $suffix .= " title=\"{$n} conflict\"";
        }
        return "<input type=\"checkbox\" class=\"uic uikd uich js-assign-review js-range-click\" data-range-type=\"assrev{$this->usuffix}\" name=\"assrev{$row->paperId}u{$this->contact->contactId}\" value=\"{$value}\" data-unconflicted-value=\"{$nonvalue}\" autocomplete=\"off\" tabindex=\"0\"{$suffix}>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $ct = $this->conflict_type($pl, $row);
        if (!Conflict::is_conflicted($ct)) {
            return "N";
        } else if (!$this->show_description) {
            return "Y";
        } else {
            return $this->cset->unparse_csv(min($ct, CONFLICT_AUTHOR));
        }
    }

    static function expand($name, XtParams $xtp, $xfj, $m) {
        if (!($fj = (array) $xtp->conf->basic_paper_column($m[1], $xtp->user))) {
            return null;
        }
        $rs = [];
        $cs = new ContactSearch(ContactSearch::F_PC | ContactSearch::F_TAG | ContactSearch::F_USER, $m[2], $xtp->user);
        foreach ($cs->user_ids() as $cid) {
            if (($u = $xtp->conf->pc_member_by_id($cid))) {
                $fj["name"] = $m[1] . ":" . $u->email;
                $fj["user"] = $u->email;
                $rs[] = (object) $fj;
            }
        }
        if (empty($rs)) {
            PaperColumn::column_error($xtp, "<0>PC member ‘{$m[2]}’ not found");
        }
        return $rs;
    }

    static function completions(Contact $user, $fxt) {
        if ($user->can_view_some_conflicts()) {
            return [($fxt->show_description ?? false) ? "pcconfdesc:<user>" : "pcconf:<user>"];
        } else {
            return [];
        }
    }
}
