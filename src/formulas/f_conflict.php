<?php
// formulas/f_conflict.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class Conflict_Fexpr extends Fexpr {
    private $ispc;
    function __construct($ispc) {
        $this->ispc = is_object($ispc) ? $ispc->kwdef->is_pc : $ispc;
        $this->set_format(Fexpr::FBOOL);
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function compile(FormulaCompiler $state) {
        // XXX the actual search is different
        if ($state->index_type === Fexpr::IDX_MY) {
            return $state->_prow() . "->has_conflict(\$user->contactId)";
        }
        $uid = $state->current_uid();
        $ctmap = $state->prow_viewable_conflict_types();
        $x = "({$uid} && ({$ctmap}[{$uid}] ?? 0) > " . CONFLICT_MAXUNCONFLICTED . ")";
        if ($this->ispc) {
            $pcmap = $state->g_viewable_pc();
            $x = "(isset({$pcmap}[{$uid}]) ? {$x} : null)";
        }
        return $x;
    }
}
