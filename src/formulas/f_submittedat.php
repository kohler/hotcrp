<?php
// formulas/f_submittedat.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class SubmittedAt_Fexpr extends Fexpr {
    function __construct(FormulaCall $ff) {
        parent::__construct("submittedat");
        $this->set_format($ff->kwdef->is_time ? Fexpr::FTIME : Fexpr::FDATE);
    }
    function compile(FormulaCompiler $state) {
        return '(' . $state->_prow() . '->submitted_at() ? : null)';
    }
}
