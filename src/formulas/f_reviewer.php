<?php
// formulas/f_reviewer.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Reviewer_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("reviewer");
        $this->_format = Fexpr::FREVIEWER;
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["reviewSignatures"] = true;
        return '($prow->can_view_review_identity_of(' . $state->loop_cid() . ', $contact) ? ' . $state->loop_cid() . ' : null)';
    }
}
