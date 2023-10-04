<?php
// search/st_decision.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decision_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string|list<int> */
    private $match;

    /** @param string|list<int> $match */
    function __construct(Contact $user, $match) {
        parent::__construct("decision");
        $this->user = $user;
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dec = $srch->conf->decision_set()->matchexpr($word);
        if (is_array($dec) && empty($dec)) {
            $srch->lwarning($sword, "<0>Decision not found");
            $dec[] = -10000000;
        }
        return new Decision_SearchTerm($srch->user, $dec);
    }
    /** @return string|list<int> */
    function matchexpr() {
        return $this->match;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $f = ["Paper.outcome" . CountMatcher::sqlexpr_using($this->match)];
        if (CountMatcher::compare_using(0, $this->match)
            && !$this->user->allow_administer_all()) {
            $f[] = "Paper.outcome=0";
        }
        return "(" . join(" or ", $f) . ")";
    }
    function test(PaperInfo $row, $xinfo) {
        $d = $this->user->can_view_decision($row) ? $row->outcome : 0;
        return CountMatcher::compare_using($d, $this->match);
    }
    function about() {
        return self::ABOUT_PAPER;
    }
    function drag_assigners(Contact $user) {
        $ds = $user->conf->decision_set()->filter_using($this->match);
        if (count($ds) !== 1 || !$user->can_set_some_decision()) {
            return null;
        }
        return [
            ["action" => "decision", "decision" => $ds[0]->name, "ondrag" => "enter"],
            ["action" => "decision", "decision" => "none", "ondrag" => "leave"]
        ];
    }
}
