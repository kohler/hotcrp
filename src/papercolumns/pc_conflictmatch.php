<?php
// pc_conflictmatch.php -- HotCRP paper columns for author/collaborator match
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ConflictMatch_PaperColumn extends PaperColumn {
    /** @var Contact */
    private $contact;
    /** @var bool */
    private $show_user;
    public $nonempty;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        if (($this->show_user = isset($cj->user))) {
            $this->contact = $conf->pc_member_by_email($cj->user);
        }
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $this->contact ?? $pl->reviewer_user();
        $general_pregexes = $this->contact->aucollab_general_pregexes();
        return $pl->user->is_manager() && !empty($general_pregexes);
    }
    function header(PaperList $pl, $is_text) {
        $t = "Potential conflict";
        if ($this->show_user) {
            $t .= " with " . $this->contact->name_h(NAME_P);
        }
        if ($this->show_user && $this->contact->affiliation) {
            $t .= " (" . htmlspecialchars($this->contact->affiliation) . ")";
        }
        return $is_text ? $t : "<strong>{$t}</strong>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        $this->nonempty = false;
        return !$pl->user->allow_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $pf = $row->preference($this->contact);
        $potconf = $row->potential_conflict_html($this->contact, true);
        if ($pf->preference <= -100) {
            $potconf = $potconf ?? new PaperInfoPotentialConflictHTML;
            $potconf->messages[] = ["<em>reviewer preference</em> " . $pf->unparse()];
        }
        $this->nonempty = !$row->has_author($this->contact) || $potconf;
        if (!$potconf || empty($potconf->messages)) {
            return "";
        }
        $m = $potconf->render_ul_list("break-avoid");
        if (count($potconf->messages) === 1) {
            return "<div class=\"potentialconflict-one\">{$m[0]}</div>";
        } else {
            return "<div class=\"potentialconflict-many\">" . join("", $m) . "</div>";
        }
    }

    /** @param string $name @unused-param
     * @param object $xfj @unused-param */
    static function expand($name, XtParams $xtp, $xfj, $m) {
        if (!($fj = (array) $xtp->conf->basic_paper_column("potentialconflict", $xtp->user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $xtp->user)->users() as $u) {
            $fj["name"] = "potentialconflict:" . $u->email;
            $fj["user"] = $u->email;
            $rs[] = (object) $fj;
        }
        if (empty($rs)) {
            PaperColumn::column_error($xtp, "<0>PC member ‘{$m[1]}’ not found");
        }
        return $rs;
    }
}
