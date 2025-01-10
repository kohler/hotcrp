<?php
// formulas/f_decision.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Decision_Fexpr extends Fexpr {
    function __construct() {
        $this->set_format(Fexpr::FDECISION);
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_decision();
    }
    function compile(FormulaCompiler $state) {
        return $state->_add_decision();
    }
}
