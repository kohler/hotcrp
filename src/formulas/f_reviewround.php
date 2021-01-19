<?php
// formulas/f_reviewround.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class ReviewRound_Fexpr extends Fexpr {
    function __construct() {
        $this->_format = self::FROUND;
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function visible_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        $rrow = $state->_rrow();
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->define_gvar("myrevround", "{$rrow} ? {$rrow}->reviewRound : null");
        } else {
            $view_bits = $state->user->permissive_view_bits();
            if ($state->user->permissive_view_bits() & VIEWBITS_PC) {
                $state->queryOptions["reviewSignatures"] = true;
                $rrow_vb = $state->_rrow_view_bits();
                return "($rrow_vb & " . VIEWBITS_PC . " ? {$rrow}->reviewRound : null)";
            } else {
                return "null";
            }
        }
    }
}
