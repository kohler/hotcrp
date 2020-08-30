<?php
// search/st_decision.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Decision_SearchTerm extends SearchTerm {
    private $match;

    function __construct($match) {
        parent::__construct("dec");
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dec = PaperSearch::decision_matchexpr($srch->conf, $word, $sword->quoted);
        if (is_array($dec) && empty($dec)) {
            $srch->warn("“" . htmlspecialchars($word) . "” doesn’t match a decision.");
            $dec[] = -10000000;
        }
        return new Decision_SearchTerm($dec);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "(Paper.outcome" . CountMatcher::sqlexpr_using($this->match) . ")";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $srch->user->can_view_decision($row)
            && CountMatcher::compare_using($row->outcome, $this->match);
    }
}
