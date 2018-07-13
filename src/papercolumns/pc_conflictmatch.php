<?php
// pc_conflictmatch.php -- HotCRP paper columns for author/collaborator match
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ConflictMatch_PaperColumn extends PaperColumn {
    private $_potconf;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        $this->contact = $pl->reviewer_user();
        $general_pregexes = $this->contact->aucollab_general_pregexes();
        return $pl->user->is_manager() && !empty($general_pregexes);
    }
    function header(PaperList $pl, $is_text) {
        return $is_text ? "Potential conflict" : "<strong>Potential conflict</strong>";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->allow_administer($row);
    }
    function _conflict_match($user, $matcher, $conflict, $aunum, $why) {
        $aumatcher = new AuthorMatcher($conflict);
        if ($aunum) {
            $pfx = "<em>author #$aunum</em> ";
            if ($matcher->nonauthor) {
                $match = $aumatcher->highlight($matcher);
                if (!$matcher->name())
                    $match = "All " . $match;
                $this->_potconf[$aunum][] = [$pfx . $matcher->highlight($conflict), "Author matches PC collaborator " . $match];
            } else if ($why == AuthorMatcher::MATCH_AFFILIATION) {
                $this->_potconf[$aunum][] = [$pfx . htmlspecialchars($conflict->name()) . " (" . $matcher->highlight($conflict->affiliation) . ")", "Author matches PC affiliation " . $aumatcher->highlight($user->affiliation)];
            } else {
                $this->_potconf[$aunum][] = [$pfx . $matcher->highlight($conflict), "Author matches PC " . $aumatcher->highlight($user)];
            }
        } else {
            $num = "x" . count($this->_potconf);
            $pfx = "<em>collaborator</em> ";
            if (!$conflict->name())
                $pfx .= "All ";
            $pfx .= $matcher->highlight($conflict);
            if ($why == AuthorMatcher::MATCH_AFFILIATION) {
                $this->_potconf[$num][] = [$pfx, "Paper collaborator matches PC affiliation " . $aumatcher->highlight($user->affiliation)];
            } else {
                $this->_potconf[$num][] = [$pfx, "Paper collaborator matches PC " . $aumatcher->highlight($user)];
            }
        }
    }
    function content(PaperList $pl, PaperInfo $row) {
        $this->_potconf = [];
        if (!$row->potential_conflict_callback($this->contact, [$this, "_conflict_match"]))
            return "";
        $ch = [];
        $nconf = count($this->_potconf);
        foreach ($this->_potconf as &$cx) {
            if (count($cx) > 1) {
                $n = $len = false;
                foreach ($cx as $c) {
                    $thislen = strlen(preg_replace('{>[^<]*<}', "", $c[0]));
                    if ($n === false || $thislen < $len) {
                        $n = $c[0];
                        $len = $thislen;
                    }
                }
                $cx[0][0] = $n;
            }
            $cn = array_map(function ($c) { return $c[1]; }, $cx);
            $ch[] = '<span class="nb need-tooltip" data-tooltip-class="gray" data-tooltip="'
                . htmlspecialchars('<div class="mmm">' . join('</div><div class="mmm">', $cn) . '</div>')
                . '">' . $cx[0][0]
                . (count($ch) === $nconf - 1 ? '</span>' : ';</span>');
        }
        unset($cx, $row->folded);
        return join(" ", $ch);
    }
}
