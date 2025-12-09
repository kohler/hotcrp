<?php
// formulas/f_reviewround.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class ReviewRound_Fexpr extends Fexpr {
    function __construct() {
        $this->set_format(Fexpr::FROUND);
    }
    static function make(Contact $user) {
        return $user->is_reviewer() ? new ReviewRound_Fexpr : Fexpr::cnever();
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function compile(FormulaCompiler $state) {
        $rrow = $state->_rrow();
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->define_gvar("myrevround", "{$rrow} ? {$rrow}->reviewRound : null");
        }
        $view_score = $state->user->permissive_view_score_bound();
        if (VIEWSCORE_REVIEWER <= $view_score) {
            return "null";
        }
        $state->queryOptions["reviewSignatures"] = true;
        $rrow_vsb = $state->_rrow_view_score_bound(false);
        return "(" . VIEWSCORE_REVIEWER . " > {$rrow_vsb} ? {$rrow}->reviewRound : null)";
    }
}
