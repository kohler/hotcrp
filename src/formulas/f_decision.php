<?php
// formulas/f_decision.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class Decision_Fexpr extends Fexpr {
    function __construct() {
        $this->set_format(Fexpr::FDECISION);
    }
    static function make(Contact $user) {
        if (!$user->can_view_some_decision()) {
            return Fexpr::cnever();
        }
        return new Decision_Fexpr;
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function compile(FormulaCompiler $state) {
        return $state->_add_decision();
    }
}
