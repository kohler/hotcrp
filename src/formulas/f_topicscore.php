<?php
// formulas/f_topicscore.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class TopicScore_Fexpr extends Fexpr {
    function __construct() {
        parent::__construct("topicscore");
    }
    static function make(FormulaCall $ff) {
        return $ff->user->isPC ? new TopicScore_Fexpr : Fexpr::cnever();
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function paper_options(&$oids) {
        $oids[PaperOption::TOPICSID] = true;
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["topics"] = true;
        $prow = $state->_prow();
        $uid = $state->current_uid();
        if ($state->user->privChair
            || $state->index_type === Fexpr::IDX_MY) {
            return "{$prow}->topic_interest_score({$uid})";
        }
        $vps = $state->prow_view_preference_state();
        return "({$uid} && ({$vps} >= ({$uid} === {$state->user->contactId} ? "
            . Contact::VIEWPREF_OWN . " : " . Contact::VIEWPREF_ALL
            . ")) ? {$prow}->topic_interest_score({$uid}) : null)";
    }
}
