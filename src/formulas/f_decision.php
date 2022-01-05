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
        if ($state->check_gvar('$decision')) {
            $prow = $state->_prow();
            $state->gstmt[] = "\$decision = \$contact->can_view_decision({$prow}) ? (int) {$prow}->outcome : 0;";
        }
        return '$decision';
    }
}
