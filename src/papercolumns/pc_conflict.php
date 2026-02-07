<?php
// pc_conflict.php -- HotCRP conflict list column
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Conflict_PaperColumn extends PaperColumn {
    /** @var ?Contact */
    private $user;
    /** @var bool */
    private $show_user;
    /** @var bool */
    private $not_me;
    /** @var bool */
    private $description;
    /** @var bool */
    private $editable = false;
    /** @var bool */
    private $simple = false;
    /** @var bool */
    private $pin_no = false;
    /** @var bool */
    private $pin_yes = false;
    /** @var bool */
    private $palette = false;
    /** @var string */
    private $usuffix;
    /** @var Conflict */
    private $cset;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
        if (($this->show_user = isset($cj->user))) {
            $this->user = $conf->pc_member_by_email($cj->user);
        }
        $this->description = $cj->show_description ?? false;
        $this->editable = $cj->edit ?? false;
    }
    static function basic_view_option_schema() {
        return ["simple", "edit=yes|no|palette", "pin=all,yes|none,no|nonconflict,unconflicted|conflict,conflicted", "description", "desc/description"];
    }
    function view_option_schema() {
        return self::basic_view_option_schema();
    }
    function prepare(PaperList $pl, $visible) {
        $this->user = $this->user ? : $pl->reviewer_user();
        $this->not_me = $this->user->contactId !== $pl->user->contactId;
        $editing = $this->view_option("edit") ?? $this->editable;
        $this->editable = $editing && $editing !== "no";
        if ($this->editable) {
            $this->simple = $this->view_option("simple") ?? false;
            $pin = $this->view_option("pin") ?? "none";
            $this->pin_no = $pin === "all" || $pin === "nonconflict";
            $this->pin_yes = $pin === "all" || $pin === "conflict";
            $this->override = PaperColumn::OVERRIDE_BOTH;
            $this->palette = $editing === "palette";
        }
        $this->description = $pl->conf->setting("sub_pcconfsel")
            && ($this->view_option("description") ?? $this->description);
        $this->usuffix = $this->simple ? "" : "u{$this->user->contactId}";
        $this->cset = $pl->conf->conflict_set();
        return true;
    }
    private function conflict_type(PaperList $pl, $row) {
        if ($this->not_me && !$pl->user->can_view_conflicts($row)) {
            return 0;
        }
        $ct = $row->conflict_type($this->user);
        if ($this->description
            && Conflict::is_conflicted($ct)
            && !$pl->user->can_view_authors($row)) {
            $ct = Conflict::CT_DEFAULT;
        }
        return $ct;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $act = $this->conflict_type($pl, $a);
        $bct = $this->conflict_type($pl, $b);
        if ($this->description) {
            return $act <=> $bct;
        }
        return ($act ? 1 : 0) <=> ($bct ? 1 : 0);
    }
    function header(PaperList $pl, $is_text) {
        if ((!$this->show_user && !$this->not_me && !$this->editable)
            || $this->simple) {
            return "Conflict";
        } else if ($is_text) {
            return $pl->user->reviewer_text_for($this->user) . " conflict";
        }
        return $pl->user->reviewer_html_for($this->user) . "<br>conflict";
    }
    protected function checked(PaperList $pl, PaperInfo $row) {
        return $pl->is_selected($row->paperId, $row->has_conflict($this->user));
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
        } else if (!$this->description) {
            return review_type_icon(-1);
        }
        return $this->cset->unparse_html(min($ct, CONFLICT_AUTHOR));
    }
    private function edit_content(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->allow_admin($row)) {
            return "";
        }
        $ct = $row->conflict_type($this->user);
        if (Conflict::is_author($ct)) {
            return "Author";
        } else if ($this->palette) {
            return $this->edit_content_palette($pl, $row, $ct);
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
            $n = htmlspecialchars($pl->user->name_text_for($this->user));
            $suffix .= " title=\"{$n} conflict\"";
        } else {
            $suffix .= " title=\"Conflict\"";
        }
        return "<input type=\"checkbox\" class=\"uic uikd uich js-assign-review js-range-click\" data-range-type=\"assrev{$this->usuffix}\" name=\"assrev{$row->paperId}u{$this->user->contactId}\" value=\"{$value}\" data-unconflicted-value=\"{$nonvalue}\" autocomplete=\"off\"{$suffix}>";
    }
    private function edit_content_palette(PaperList $pl, PaperInfo $row, $ct) {
        $t = ['<span class="btnbox">'];
        $t[] = $this->radio_for($row, 3, $ct);
        $t[] = $this->radio_for($row, 1, $ct);
        $t[] = $this->radio_for($row, 0, $ct);
        $t[] = $this->radio_for($row, 2, $ct);
        $t[] = '</span>';
        $pl->need_render = true;
        return join("", $t);
    }
    static private $radio_titles = ["Non-conflict", "Conflict", "Pinned non-conflict", "Pinned conflict"];
    private function radio_for(PaperInfo $row, $cn, $ct) {
        $pinned = ($cn & 2) !== 0;
        $conflicted = ($cn & 1) !== 0;
        $title = self::$radio_titles[$cn];
        $checked = Conflict::is_conflicted($ct) === $conflicted
            && Conflict::is_pinned($ct) === $pinned ? " checked" : "";
        $klass = $conflicted ? "yes" : "no";
        if (Conflict::is_conflicted($ct) && $conflicted) {
            $value = $this->cset->unparse_assignment(Conflict::set_pinned($ct, false));
        } else {
            $value = $conflicted ? "conflict" : "nonconflict";
        }
        if ($pinned) {
            $klass .= "-pinned";
            $value = "pinned {$value}";
        } else if (!$conflicted && $row->potential_conflict($this->user)) {
            $klass .= "-potential";
            $title = "Unconfirmed potential conflict";
        }
        return "<input type=\"radio\" class=\"uic uikd uich js-assign-review js-range-click btn cflt cflt-{$klass} need-tooltip\" data-tooltip-info=\"cflt\" data-range-type=\"assrev{$this->usuffix}cv{$cn}\" name=\"assrev{$row->paperId}u{$this->user->contactId}\" value=\"{$value}\" autocomplete=\"off\" title=\"{$title}\"{$checked}>";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $ct = $this->conflict_type($pl, $row);
        if (!Conflict::is_conflicted($ct)) {
            return "N";
        } else if (!$this->description) {
            return "Y";
        }
        return $this->cset->unparse_csv(min($ct, CONFLICT_AUTHOR));
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
            PaperColumn::column_error_at($xtp, $name, "<0>PC member ‘{$m[2]}’ not found");
        }
        return $rs;
    }

    static function completions(Contact $user, $xfj) {
        if (!$user->can_view_some_conflicts()) {
            return [];
        }
        return ["conflict:{user}"];
    }

    static function examples(Contact $user, $xfj) {
        if (!$user->can_view_some_conflicts()) {
            return [];
        }
        return [new SearchExample("conflict:{user}", "<0>Conflict with user",
                    new FmtArg("view_options", Conflict_PaperColumn::basic_view_option_schema()))];
    }
}
