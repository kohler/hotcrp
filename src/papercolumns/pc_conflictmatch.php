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
    /** @param Contact $user
     * @param AuthorMatcher $matcher
     * @param Author $conflict
     * @param int $aunum
     * @param string $why */
    function _conflict_match($user, $matcher, $conflict, $aunum, $why) {
        $aumatcher = new AuthorMatcher($conflict);
        if ($aunum) {
            $pfx = "<em>author #$aunum</em> ";
            if ($matcher->nonauthor) {
                $this->_potconf[$aunum][] = [$pfx . $matcher->highlight($conflict), "matches PC collaborator " . $aumatcher->highlight($matcher)];
            } else if ($why == AuthorMatcher::MATCH_AFFILIATION) {
                $this->_potconf[$aunum][] = [$pfx . htmlspecialchars($conflict->name()) . " (" . $matcher->highlight($conflict->affiliation) . ")", "matches PC affiliation " . $aumatcher->highlight($user->affiliation)];
            } else {
                $this->_potconf[$aunum][] = [$pfx . $matcher->highlight($conflict), "matches PC " . $aumatcher->highlight($user)];
            }
        } else {
            $num = "x" . count($this->_potconf);
            $pfx = "<em>collaborator</em> " . $matcher->highlight($conflict);
            if ($why == AuthorMatcher::MATCH_AFFILIATION) {
                $this->_potconf[$num][] = [$pfx, "matches PC affiliation " . $aumatcher->highlight($user->affiliation)];
            } else {
                $this->_potconf[$num][] = [$pfx, "matches PC " . $aumatcher->highlight($user)];
            }
        }
    }
    function content(PaperList $pl, PaperInfo $row) {
        $this->_potconf = [];
        $pref = $row->preference($this->contact);
        $this->nonempty = !$row->has_author($this->contact)
            && ($row->potential_conflict_callback($this->contact, [$this, "_conflict_match"])
                || $pref[0] <= -100);
        if (!$this->nonempty) {
            return "";
        }
        if ($pref[0] <= -100) {
            $this->_potconf["pref"][] = ["<em>reviewer preference</em>", "PC entered preference " . unparse_preference($pref)];
        }
        $ch = [];
        $nconf = count($this->_potconf);
        foreach ($this->_potconf as &$cx) {
            if (count($cx) > 1) {
                $n = $len = false;
                foreach ($cx as $c) {
                    $thislen = strlen(preg_replace('{<[^>]*>[^<]*</[^>]*>}', "", $c[0]));
                    if ($n === false || $thislen < $len) {
                        $n = $c[0];
                        $len = $thislen;
                    }
                }
                $cx[0][0] = $n;
            }
            $cn = array_map(function ($c) { return $c[1]; }, $cx);
            $ch[] = '<ul class="potentialconflict break-avoid"><li>' . $cx[0][0] . '</li><li>' . join('</li><li>', $cn) . '</li></ul>';
        }
        unset($cx);
        if (empty($ch)) {
            return "";
        } else if (count($ch) === 1) {
            return '<div class="potentialconflict-one">' . $ch[0] . '</div>';
        } else {
            return '<div class="potentialconflict-many">' . join("", $ch) . '</div>';
        }
    }

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
