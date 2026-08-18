<?php
// formulas/f_revtype.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class Revtype_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("retype");
        $this->set_format(Fexpr::FREVTYPE);
    }
    static function make(Contact $user) {
        return $user->is_reviewer() ? new Revtype_Fexpr : Fexpr::cnever();
    }
    function about() {
        return SearchTerm::ABOUT_REVIEWS;
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function compile(FormulaCompiler $state) {
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->define_gvar("myrevtype", $state->_prow() . "->review_type(\$user)");
        }
        $state->queryOptions["reviewSignatures"] = true;
        $rrow = $state->_rrow();
        $rmv = $state->_rrow_meta_viewable();
        return "({$rmv} ? {$rrow}->reviewType : null)";
    }
}
