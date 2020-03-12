<?php
// formulas/f_timefield.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class TimeField_Fexpr extends Fexpr {
    private $field;
    function __construct($field) {
        $this->field = $field;
    }
    function compile(FormulaCompiler $state) {
        return "((int) \$prow->" . $this->field . ")";
    }
}
