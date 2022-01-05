<?php
// formulas/f_now.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Now_Fexpr extends Fexpr {
    function __construct(FormulaCall $ff) {
        $this->set_format($ff->kwdef->is_time ? Fexpr::FTIME : Fexpr::FDATE);
    }
    function compile(FormulaCompiler $state) {
        return $state->_add_now();
    }
}
