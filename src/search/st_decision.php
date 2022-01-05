<?php
// search/st_decision.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decision_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    private $match;

    function __construct(Contact $user, $match) {
        parent::__construct("dec");
        $this->user = $user;
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dec = PaperSearch::decision_matchexpr($srch->conf, $word, $sword->quoted);
        if (is_array($dec) && empty($dec)) {
            $srch->lwarning($sword, "<0>Decision not found");
            $dec[] = -10000000;
        }
        return new Decision_SearchTerm($srch->user, $dec);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $f = ["Paper.outcome" . CountMatcher::sqlexpr_using($this->match)];
        if (CountMatcher::compare_using(0, $this->match)
            && !$this->user->allow_administer_all()) {
            $f[] = "Paper.outcome=0";
        }
        return "(" . join(" or ", $f) . ")";
    }
    function test(PaperInfo $row, $rrow) {
        $d = $this->user->can_view_decision($row) ? $row->outcome : 0;
        return CountMatcher::compare_using($d, $this->match);
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
