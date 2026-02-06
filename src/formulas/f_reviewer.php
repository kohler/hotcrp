<?php
// formulas/f_reviewer.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Reviewer_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("reviewer");
        $this->set_format(Fexpr::FREVIEWER);
    }
    static function make(FormulaCall $ff) {
        if (!$ff->formula->user->can_view_some_review_identity()) {
            return Fexpr::cnever();
        }
        return new Reviewer_Fexpr;
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["reviewSignatures"] = true;
        return $state->review_identity_loop_cid();
    }
}
