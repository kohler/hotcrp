<?php
// formulas/f_pref.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Pref_Fexpr extends Fexpr {
    private $is_expertise;
    private $cids;
    function __construct($ff) {
        $is_expertise = is_object($ff) ? $ff->kwdef->is_expertise : $ff;
        parent::__construct($is_expertise ? "prefexp" : "pref");
        $this->is_expertise = $is_expertise;
        $this->set_format($is_expertise ? Fexpr::FPREFEXPERTISE : Fexpr::FNUMERIC);
        if (is_object($ff) && $ff->modifier) {
            $this->cids = $ff->modifier;
        }
    }
    static function parse_modifier(FormulaCall $ff, $arg, $rest, Formula $formula) {
        if ($ff->modifier === false && !str_starts_with($arg, ".")) {
            if (str_starts_with($arg, ":")) {
                $arg = substr($arg, 1);
            }
            $csm = ContactSearch::make_pc($arg, $formula->user);
            if (!$csm->has_error()) {
                $ff->modifier = $csm->user_ids();
                return true;
            }
        }
        return false;
    }
    function inferred_index() {
        return Fexpr::IDX_PC;
    }
    function viewable_by(Contact $user) {
        return $user->isPC;
    }
    function compile(FormulaCompiler $state) {
        if (!$state->user->is_reviewer()) {
            return "null";
        }
        $state->queryOptions["allReviewerPreference"] = true;
        $e = "((" . $state->_add_preferences() . "[" . $state->loop_cid(true) . "] ?? [])"
            . "[" . ($this->is_expertise ? 1 : 0) . "] ?? null)";
        if ($this->cids) {
            $e = "(in_array(" . $state->loop_cid() . ", [" . join(",", $this->cids) . "]) ? $e : null)";
        }
        return $e;
    }
}
