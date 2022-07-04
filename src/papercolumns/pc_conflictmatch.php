<?php
// pc_conflictmatch.php -- HotCRP paper columns for author/collaborator match
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ConflictMatch_PaperColumn extends PaperColumn {
    /** @var Contact */
    private $contact;
    /** @var bool */
    private $show_user;
    private $_potconf;
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
        $this->_potconf = [];
        $pref = $row->preference($this->contact);
        $potconf = $row->potential_conflict_html($this->contact, true);
        if ($pref[0] <= -100) {
            $potconf = $potconf ?? ["", []];
            $potconf[1][] = "<ul class=\"potentialconflict\"><li><em>reviewer preference</em> " . unparse_preference($pref) . "</li></ul>";
        }
        $this->nonempty = !$row->has_author($this->contact) || $potconf;
        if (!$this->nonempty) {
            return "";
        }
        foreach ($potconf[1] as &$m) {
            $m = substr($m, 0, 28) . " break-avoid" . substr($m, 28);
        }
        if (empty($potconf[1])) {
            return "";
        } else if (count($potconf[1]) === 1) {
            return "<div class=\"potentialconflict-one\">{$potconf[1][0]}</div>";
        } else {
            return "<div class=\"potentialconflict-many\">" . join("", $potconf[1]) . "</div>";
        }
    }

    /** @param string $name @unused-param
     * @param object $xfj @unused-param */
    static function expand($name, Contact $user, $xfj, $m) {
        if (!($fj = (array) $user->conf->basic_paper_column("potentialconflict", $user))) {
            return null;
        }
        $rs = [];
        foreach (ContactSearch::make_pc($m[1], $user)->users() as $u) {
            $fj["name"] = "potentialconflict:" . $u->email;
            $fj["user"] = $u->email;
            $rs[] = (object) $fj;
        }
        if (empty($rs)) {
            PaperColumn::column_error($user, "<0>PC member ‘{$m[1]}’ not found");
        }
        return $rs;
    }
}
