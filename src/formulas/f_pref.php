<?php
// formulas/f_pref.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Pref_Fexpr extends Fexpr {
    private $isexpertise;
    private $cids;
    function __construct($ff) {
        $this->isexpertise = is_object($ff) ? $ff->kwdef->is_expertise : $ff;
        $this->format_ = $this->isexpertise ? self::FPREFEXPERTISE : null;
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
            if ($csm->ids !== false) {
                $ff->modifier = $csm->ids;
                return true;
            }
        }
        return false;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        if (!$state->user->is_reviewer()) {
            return "null";
        }
        $state->queryOptions["allReviewerPreference"] = true;
        $state->datatype |= self::APREF;
        $e = "((" . $state->_add_preferences() . "[" . $state->loop_cid(true) . "] ?? [])"
            . "[" . ($this->isexpertise ? 1 : 0) . "] ?? null)";
        if ($this->cids) {
            $e = "(in_array(" . $state->loop_cid() . ", [" . join(",", $this->cids) . "]) ? $e : null)";
        }
        return $e;
    }
}
