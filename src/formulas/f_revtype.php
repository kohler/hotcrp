<?php
// formulas/f_revtype.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Revtype_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("retype");
        $this->set_format(Fexpr::FREVTYPE);
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function viewable_by(Contact $user) {
        return $user->is_reviewer();
    }
    function compile(FormulaCompiler $state) {
        if ($state->index_type === Fexpr::IDX_MY) {
            $rt = $state->define_gvar("myrevtype", $state->_prow() . "->review_type(\$contact)");
        } else {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score) {
                return "null";
            }
            $state->queryOptions["reviewSignatures"] = true;
            $rrow = $state->_rrow();
            return "({$rrow} ? {$rrow}->reviewType : null)";
        }
        return $rt;
    }
}
