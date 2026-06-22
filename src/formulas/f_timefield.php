<?php
// formulas/f_timefield.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class TimeField_Fexpr extends Fexpr {
    private $field;
    function __construct($field) {
        parent::__construct($field);
        $this->field = $field;
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function compile(FormulaCompiler $state) {
        return "((int) " . $state->_prow() . "->" . $this->field . ")";
    }
}
