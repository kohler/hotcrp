<?php
// formulas/f_reviewwordcount.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2024 Eddie Kohler; see LICENSE.

class ReviewWordCount_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("rewordcount");
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function viewable_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        if ($state->index_type !== Fexpr::IDX_MY
            && VIEWSCORE_REVIEWER <= $state->user->permissive_view_score_bound()) {
            return "null";
        }
        $state->_ensure_review_word_counts();
        $rrow = $state->_rrow();
        $rrow_vsb = $state->_rrow_view_score_bound(true);
        return "(" . VIEWSCORE_AUTHORDEC . " > {$rrow_vsb} ? {$rrow}->reviewWordCount : null)";
    }
}
