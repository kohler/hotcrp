<?php
// formulas/f_topicscore.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class TopicScore_Fexpr extends Fexpr {
    /** @var int */
    private $topic_idx = -1;
    function __construct(FormulaCall $ff) {
        parent::__construct("topicscore");
        $opt = $ff->conf->option_by_id(PaperOption::TOPICSID);
        if (!$opt->always_visible()) {
            $this->topic_idx = $ff->formula->register_info($opt);
        }
    }
    static function make(FormulaCall $ff) {
        return $ff->user->isPC ? new TopicScore_Fexpr($ff) : Fexpr::cnever();
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
        $tscores = $state->define_gvar('$topic_scores', '[]');
        $cond = [];
        if ($this->topic_idx >= 0) {
            $cond[] = "\$user->can_view_option(\$prow, \$formula->info[{$this->topic_idx}])";
        }
        if (!$state->user->privChair
            && $state->index_type !== Fexpr::IDX_MY) {
            $vps = $state->prow_view_preference_state();
            $cond[] = $uid;
            $cond[] = "({$vps} >= ({$uid} === {$state->user->contactId} ? " . Contact::VIEWPREF_OWN . " : " . Contact::VIEWPREF_ALLOW_ALL . "))";
        }
        $r = "{$prow}->topic_interest_score({$uid})";
        if (!empty($cond)) {
            $r = "(" . join(" && ", $cond) . " ? {$r} : null)";
        }
        $state->lstmt[] = "if (!array_key_exists({$uid}, {$tscores})) { {$tscores}[{$uid}] = {$r}; }";
        return "{$tscores}[{$uid}]";
    }
}
