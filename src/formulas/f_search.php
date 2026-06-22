<?php
// formulas/f_search.php -- HotCRP helper class for search matching
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class Search_Fexpr extends Fexpr {
    /** @var SearchTerm */
    private $term;
    /** @var int */
    private $stindex;
    function __construct(Formula $formula, SearchTerm $term) {
        parent::__construct("search");
        $this->term = $term;
        $this->stindex = $formula->register_info($this->term);
        $this->set_format(Fexpr::FBOOL);
    }
    static function eval($formula, $stindex, $prow) {
        return $formula->info[$stindex]->test($prow, null);
    }
    function about() {
        return $this->term->about();
    }
    function compile(FormulaCompiler $state) {
        $prow = $state->_prow();
        return "Search_Fexpr::eval(\$formula,{$this->stindex},{$prow})";
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return ["op" => "search"];
    }
}
