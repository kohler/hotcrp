<?php
// formulas/f_topicscore.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class TopicScore_Fexpr extends Fexpr {
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= Fexpr::APCCANREV;
        $state->queryOptions["topics"] = true;
        if ($state->looptype === self::LMY) {
            return $state->define_gvar("mytopicscore", "\$prow->topic_interest_score(\$contact)");
        } else if ($state->user->can_view_pc()) {
            return "\$prow->topic_interest_score(" . $state->loop_cid(true) . ")";
        } else {
            return "(" . $state->loop_cid() . " == " . $state->user->contactId
                . " ? \$prow->topic_interest_score(" . $state->loop_cid() . ")"
                . " : null)";
        }
    }
}
