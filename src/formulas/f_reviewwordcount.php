<?php
// formulas/f_reviewwordcount.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class ReviewWordCount_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("rewordcount");
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function visible_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        if ($state->index_type !== Fexpr::IDX_MY
            && ($state->user->permissive_view_bits() & VIEWBITS_PC) === 0) {
            return "null";
        }
        $state->_ensure_review_word_counts();
        $rrow = $state->_rrow();
        $rrow_vb = $state->_rrow_view_bits();
        return "({$rrow_vb} & " . VIEWBITS_AU_AUD . " ? {$rrow}->reviewWordCount : null)";
    }
}
