<?php
// search/st_time.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Time_SearchTerm extends SearchTerm {
    /** @var int */
    private $t;
    /** @var bool */
    private $before;

    /** @param int $t
     * @param bool $before */
    function __construct($t, $before) {
        parent::__construct($before ? "before" : "after");
        $this->t = $t;
        $this->before = $before;
    }
    /** @return bool */
    function before() {
        return $this->before;
    }
    /** @return int */
    function timestamp() {
        return $this->t;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strlen($word) === 4
            && ctype_digit($word)) {
            $word += $sword->kwdef->before ?? false ? 0 : 1;
            $word .= "-01-01T00:00:00";
        }
        $t = $srch->conf->parse_time($word);
        if ($t === false) {
            $srch->lwarning($sword, "<0>Invalid date");
            return new False_SearchTerm;
        }
        return new Time_SearchTerm($t, $sword->kwdef->before ?? false);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return ($this->before ? Conf::$now <= $this->t : Conf::$now >= $this->t) ? "true" : "false";
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        return $this->before ? Conf::$now <= $this->t : Conf::$now >= $this->t;
    }
    function about() {
        return 0;
    }
}
