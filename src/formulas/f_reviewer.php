<?php
// formulas/f_reviewer.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Reviewer_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("reviewer");
        $this->set_format(Fexpr::FREVIEWER);
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_review_identity();
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["reviewSignatures"] = true;
        return '(' . $state->_prow() . '->can_view_review_identity_of(' . $state->loop_cid() . ', $contact) ? ' . $state->loop_cid() . ' : null)';
    }
}
