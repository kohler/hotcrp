<?php
// search/st_paperstatus.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PaperStatus_SearchTerm extends SearchTerm {
    private $match;

    function __construct($match) {
        parent::__construct("pf");
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $fval = PaperSearch::status_field_matcher($srch->conf, $word, $sword->quoted);
        if (is_array($fval[1]) && empty($fval[1])) {
            $srch->warn("“" . htmlspecialchars($word) . "” doesn’t match a decision or status.");
            $fval[1][] = -10000000;
        }
        if ($fval[0] === "outcome")
            return new Decision_SearchTerm($fval[1]);
        else {
            if ($srch->limit_submitted()
                && ($fval[0] !== "timeSubmitted" || $fval[1] !== ">0"))
                $srch->warn("“" . htmlspecialchars("{$sword->keyword}:{$sword->qword}") . "” won’t match because this collection that only contains submitted papers.");
            return new PaperStatus_SearchTerm($fval);
        }
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $q = array();
        for ($i = 0; $i < count($this->match); $i += 2) {
            $sqi->add_column($this->match[$i], "Paper." . $this->match[$i]);
            $q[] = "Paper." . $this->match[$i] . CountMatcher::sqlexpr_using($this->match[$i+1]);
        }
        return self::andjoin_sqlexpr($q);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        for ($i = 0; $i < count($this->match); $i += 2) {
            if (!CountMatcher::compare_using($row->{$this->match[$i]}, $this->match[$i+1]))
                return false;
        }
        return true;
    }
}
