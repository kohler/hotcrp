<?php
// search/st_phase.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Phase_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var int */
    private $phase;
    /** @param ?Contact $user
     * @param 0|1 $phase */
    function __construct($user, $phase) {
        parent::__construct("phase");
        $this->user = !$user || $user->is_root_user() ? null : $user;
        $this->phase = $phase;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "final") === 0) {
            return new Phase_SearchTerm($srch->user, PaperInfo::PHASE_FINAL);
        } else if (strcasecmp($word, "review") === 0) {
            return new Phase_SearchTerm($srch->user, PaperInfo::PHASE_REVIEW);
        } else {
            $srch->lwarning($sword, "<0>Only “phase:review” and “phase:final” are allowed");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return $this->phase === PaperInfo::PHASE_FINAL ? "(Paper.timeWithdrawn<=0 and Paper.outcome>0)" : "true";
    }
    function test(PaperInfo $row, $xinfo) {
        return $row->visible_phase($this->user) === $this->phase;
    }
    function about() {
        return self::ABOUT_PAPER;
    }

    /** @return ?int */
    static function term_phase(SearchTerm $st) {
        return $st->visit(function ($t, ...$vals) {
            if (empty($vals)) {
                return $t instanceof Phase_SearchTerm ? $t->phase : null;
            } else if ($t instanceof And_SearchTerm) {
                foreach ($vals as $v) {
                    if ($v !== null)
                        return $v;
                }
                return null;
            } else if ($t instanceof Not_SearchTerm) {
                return null;
            } else {
                $x = $vals[0];
                foreach ($vals as $v) {
                    if ($v !== $x)
                        return null;
                }
                return $x;
            }
        });
    }
}
