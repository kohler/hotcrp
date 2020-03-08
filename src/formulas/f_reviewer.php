<?php
// formulas/f_reviewer.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Reviewer_Fexpr extends Review_Fexpr {
    function __construct() {
        $this->format_ = Fexpr::FREVIEWER;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        $state->queryOptions["reviewSignatures"] = true;
        return '($prow->can_view_review_identity_of(' . $state->loop_cid() . ', $contact) ? ' . $state->loop_cid() . ' : null)';
    }
}
