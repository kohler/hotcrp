<?php
// formulas/f_reviewround.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class ReviewRound_Fexpr extends Fexpr {
    function __construct() {
        $this->set_format(Fexpr::FROUND);
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function viewable_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        $rrow = $state->_rrow();
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->define_gvar("myrevround", "{$rrow} ? {$rrow}->reviewRound : null");
        } else {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score) {
                return "null";
            }
            $state->queryOptions["reviewSignatures"] = true;
            $rrow_vsb = $state->_rrow_view_score_bound();
            return "(" . VIEWSCORE_PC . " > $rrow_vsb ? {$rrow}->reviewRound : null)";
        }
    }
}
