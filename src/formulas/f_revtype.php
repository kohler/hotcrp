<?php
// formulas/f_revtype.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Revtype_Fexpr extends Fexpr {
    function __construct() {
        $this->format_ = self::FREVTYPE;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        if ($state->looptype === self::LMY) {
            $rt = $state->define_gvar("myrevtype", "\$prow->review_type(\$contact)");
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
