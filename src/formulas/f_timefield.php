<?php
// formulas/f_timefield.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class TimeField_Fexpr extends Fexpr {
    private $field;
    function __construct($field) {
        parent::__construct($field);
        $this->field = $field;
    }
    function compile(FormulaCompiler $state) {
        return "((int) " . $state->_prow() . "->" . $this->field . ")";
    }
}
