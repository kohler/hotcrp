<?php
// formulas/f_topicscore.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class TopicScore_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("topicscore");
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function viewable_by(Contact $user) {
        return $user->isPC;
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["topics"] = true;
        $prow = $state->_prow();
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->define_gvar("mytopicscore", "{$prow}->topic_interest_score(\$contact)");
        } else if ($state->user->can_view_pc()) {
            return "{$prow}->topic_interest_score(" . $state->loop_cid(true) . ")";
        } else {
            return "(" . $state->loop_cid() . " == " . $state->user->contactId
                . " ? {$prow}->topic_interest_score(" . $state->loop_cid() . ")"
                . " : null)";
        }
    }
}
