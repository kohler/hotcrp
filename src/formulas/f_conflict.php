<?php
// formulas/f_conflict.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Conflict_Fexpr extends Fexpr {
    private $ispc;
    function __construct($ispc) {
        $this->ispc = is_object($ispc) ? $ispc->kwdef->is_pc : $ispc;
        $this->set_format(Fexpr::FBOOL);
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function compile(FormulaCompiler $state) {
        // XXX the actual search is different
        $idx = $state->loop_cid();
        if ($state->index_type === Fexpr::IDX_MY) {
            $rt = $state->_prow() . "->has_conflict($idx)";
        } else {
            $rt = "((" . $state->_add_conflict_types() . "[" . $idx . "] ?? 0) > "
                . CONFLICT_MAXUNCONFLICTED . ")";
            if ($this->ispc) {
                $rt = "(" . $state->_add_pc() . "[" . $idx . "] ?? false ? $rt : null)";
            }
        }
        return $rt;
    }
}
