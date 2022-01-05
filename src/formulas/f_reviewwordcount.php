<?php
// formulas/f_reviewwordcount.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class ReviewWordCount_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("rewordcount");
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function viewable_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        if ($state->index_type !== Fexpr::IDX_MY) {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score) {
                return "null";
            }
        }
        $state->_ensure_review_word_counts();
        $rrow = $state->_rrow();
        $rrow_vsb = $state->_rrow_view_score_bound();
        return "(" . VIEWSCORE_AUTHORDEC . " > $rrow_vsb ? {$rrow}->reviewWordCount : null)";
    }
}
