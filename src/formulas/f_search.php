<?php
// formulas/f_search.php -- HotCRP helper class for search matching
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class Search_Fexpr extends Fexpr {
    /** @var int */
    private $stindex;
    function __construct(Formula $formula, SearchTerm $st) {
        parent::__construct("search");
        $this->stindex = $formula->register_info($st);
        $this->set_format(Fexpr::FBOOL);
    }
    static function eval($formula, $stindex, $prow) {
        return $formula->info[$stindex]->test($prow, null);
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
