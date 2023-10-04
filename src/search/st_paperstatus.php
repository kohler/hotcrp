<?php
// search/st_paperstatus.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperStatus_SearchTerm extends SearchTerm {
    private $match;

    function __construct($match) {
        parent::__construct("pf");
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $fval = PaperSearch::status_field_matcher($srch->conf, $word, $sword->quoted);
        if (is_array($fval[1]) && empty($fval[1])) {
            $srch->lwarning($sword, "<0>Submission status ‘{$word}’ not found");
            $fval[1][] = -10000000;
        }
        if ($fval[0] === "outcome") {
            return new Decision_SearchTerm($srch->user, $fval[1]);
        } else {
            if ($srch->limit_term()->is_submitted()
                && ($fval[0] !== "timeSubmitted" || $fval[1] !== ">0")) {
                $srch->lwarning($sword, "<0>Matches nothing because this search is limited to completed submissions");
            }
            return new PaperStatus_SearchTerm($fval);
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $q = [];
        for ($i = 0; $i < count($this->match); $i += 2) {
            $sqi->add_column($this->match[$i], "Paper." . $this->match[$i]);
            $q[] = "Paper." . $this->match[$i] . CountMatcher::sqlexpr_using($this->match[$i+1]);
        }
        return self::andjoin_sqlexpr($q);
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        for ($i = 0; $i < count($this->match); $i += 2) {
            if (!CountMatcher::compare_using($row->{$this->match[$i]}, $this->match[$i+1]))
                return false;
        }
        return true;
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
