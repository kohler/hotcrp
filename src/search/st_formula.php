<?php
// search/st_formula.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Formula_SearchTerm extends SearchTerm {
    private $formula;
    private $function;
    function __construct(Formula $formula) {
        parent::__construct("formula");
        $this->formula = $formula;
        $this->function = $formula->compile_function();
    }
    static private function read_formula($word, $quoted, $is_graph, PaperSearch $srch) {
        $formula = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word))
            $formula = $srch->conf->find_named_formula($word);
        if (!$formula)
            $formula = new Formula($word, $is_graph);
        if (!$formula->check($srch->user)) {
            $srch->warn($formula->error_html());
            $formula = null;
        }
        return $formula;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, false, $srch)))
            return new Formula_SearchTerm($formula);
        return new False_SearchTerm;
    }
    static function parse_graph($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, true, $srch)))
            return SearchTerm::make_float(["view" => ["graph($word)" => true]]);
        return null;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $this->formula->add_query_options($sqi->srch->_query_options);
        return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $formulaf = $this->function;
        return !!$formulaf($row, null, $srch->user);
    }
}
