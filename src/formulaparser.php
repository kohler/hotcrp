<?php
// formulaparser.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class FormulaParser {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Formula
     * @readonly */
    public $formula;
    /** @var string
     * @readonly */
    public $str;
    /** @var ?SearchStringContext
     * @readonly */
    public $string_context;
    /** @var int */
    public $nesting = 0;
    /** @var int */
    private $pos = 0;
    /** @var ?FormulaCall */
    private $_macro;
    /** @var array<string,VarDef_Fexpr> */
    private $_free_bind = [];
    /** @var array<string,VarDef_Fexpr> */
    private $_bind = [];
    /** @var ?int */
    private $_last_lerror_pos;

    const MAXNESTING = 100;
    const MAXDEPTH = 10;
    /** Parse budget, on the same scale as PaperSearch::MAX_COST: a formula
     * parsed within a search shares the search's budget. Each operand and
     * each named-formula or macro expansion costs NODE_COST (formula nodes
     * cost more to compile and evaluate than search atoms); each step of
     * qualified-name resolution costs 1, more for a long name. This bounds
     * the compiled size whatever the expression or the formulas it expands. */
    const MAX_COST = 20000;
    const NODE_COST = 4;

    static private $_oprassoc = [
        "**" => true
    ];

    static private $_oprewrite = [
        "=" => "==", ":" => "==", "≤" => "<=", "≥" => ">=", "≠" => "!=",
        "and" => "&&", "or" => "||", "xor" => "^^"
    ];


    /** @param string $t
     * @param int $pos
     * @return int */
    private static function skip_whitespace($t, $pos) {
        $len = strlen($t);
        while (($sp = SearchParser::space_len($t, $pos, $len)) > 0) {
            $pos += $sp;
        }
        return $pos;
    }

    /** @param string $t
     * @param int $pos
     * @param string $span
     * @return int */
    static function span_parens_until($t, $pos, $span) {
        $len = strlen($t);
        while ($pos !== $len && strpos($span, $t[$pos]) === false) {
            $x = SearchParser::span_balanced_parens($t, $pos, $span, true);
            $pos = max($pos + 1, $x);
        }
        return $pos;
    }


    /** @param string $str */
    private function __construct(Formula $formula, $str, SearchStringContext $ssc) {
        $this->conf = $formula->conf;
        $this->user = $formula->user;
        $this->formula = $formula;
        $this->str = $str;
        $this->string_context = $ssc;
    }

    /** @param string $str
     * @param ?SearchStringContext $ssc
     * @return FormulaParser */
    static function make(Formula $formula, $str, $ssc = null) {
        // A formula parsed within a search nests under the search's string
        // context (`$parent`, where `$str` occupies [$ppos1, $ppos2)): its
        // errors expand from the search word, and its parse is charged to
        // the search's budget.
        $ssc = SearchStringContext::make($str, $ssc);
        if (!$ssc->parent) {
            $ssc->cost = 0;
        }
        return new FormulaParser($formula, $str, $ssc);
    }

    /** @return int */
    function cost() {
        return $this->string_context->cost() ?? 0;
    }

    /** @return bool */
    function too_complex() {
        return $this->cost() > self::MAX_COST;
    }

    /** Charge `$cost` against the formula's parse budget, shared by nested
     * parsers. Returns false once the budget is exceeded; callers then stop
     * producing nodes, and Formula::make reports the formula too complex.
     * @param int $cost
     * @return bool */
    private function charge($cost = 1) {
        return $this->string_context->charge($cost) <= self::MAX_COST;
    }

    /** Report that the formula exceeded its parse budget, and return an
     * error expression spanning the whole formula to stand in for it.
     * @return Fexpr */
    function lerror_too_complex() {
        $this->formula->lcerror(0, strlen($this->str), $this->string_context, "<0>Formula too complex");
        return $this->cerror(0, strlen($this->str));
    }

    /** @param string $str
     * @param ?FormulaCall $macro
     * @param int $ppos1
     * @param int $ppos2
     * @return FormulaParser
     * @suppress PhanAccessReadOnlyProperty */
    function make_nested($str, $macro, $ppos1, $ppos2) {
        $ssc = SearchStringContext::make($str, $this->string_context->child($ppos1, $ppos2));
        $fp = new FormulaParser($this->formula, $str, $ssc);
        if ($macro) {
            $fp->_macro = $macro;
            $fp->_free_bind = &$this->_free_bind;
            $fp->_bind = $this->_bind;
        }
        return $fp;
    }

    /** @param VarDef_Fexpr $vare
     * @return $this */
    function add_param($vare) {
        $this->_free_bind[$vare->name()] = $vare;
        return $this;
    }

    /** @return array<string,VarDef_Fexpr> */
    function params() {
        return $this->_free_bind;
    }


    /** @param int $pos1
     * @param int $pos2
     * @return Constant_Fexpr */
    function cerror($pos1, $pos2) {
        $fexpr = Fexpr::cerror();
        $fexpr->apply_strspan($pos1, $pos2, $this->string_context);
        return $fexpr;
    }

    /** @param int $pos1
     * @param int $pos2
     * @param string $message
     * @param mixed ...$args */
    function lerror($pos1, $pos2, $message, ...$args) {
        // (past the budget, parsing is abandoned; only that is reported)
        if ($this->too_complex()) {
            return;
        }
        $this->_last_lerror_pos = $pos1;
        $this->formula->lcerror($pos1, $pos2, $this->string_context, $message, ...$args);
    }

    /** @param int $pos1
     * @param int $pos2
     * @param string $message
     * @param mixed ...$args */
    function lerror_circular($pos1, $pos2, $message, ...$args) {
        $this->_last_lerror_pos = $pos1;
        $this->formula->lcerror_at("circular_reference", $pos1, $pos2, $this->string_context, $message, ...$args);
    }

    /** @param int $pos
     * @param string $message */
    function weak_lerror($pos, $message) {
        if ($this->_last_lerror_pos !== $pos) {
            $this->lerror($pos, $pos, $message);
        }
    }


    /** @param string &$name
     * @return ?object */
    private function _find_formula_function(&$name) {
        $s = $name;
        while ($s !== "") {
            // each step rescans the name, so a long one is charged for its length
            if (!$this->charge(1 + intdiv(strlen($s), 256))) {
                return null;
            }
            if (($kwdef = $this->conf->formula_function($s, $this->user))) {
                $name = $s;
                return $kwdef;
            }
            $pos1 = strrpos($s, ":");
            $pos2 = strrpos($s, ".");
            $s = substr($s, 0, max((int) $pos1, (int) $pos2));
        }
        return null;
    }

    /** @return bool */
    private function _parse_function_args(FormulaCall $ff) {
        $argtype = $ff->kwdef->args;
        $needargs = $argtype !== "optional"
            && ($argtype === "raw" ? empty($ff->rawargs) : empty($ff->args));

        // skip space
        $t = $this->str;
        $len = strlen($t);
        $pos0 = $this->pos;
        $pos = self::skip_whitespace($t, $pos0);

        // no parenthesis
        if ($pos === $len || $t[$pos] !== "(") {
            if (!$needargs) {
                return true;
            }
            $this->pos = $pos;
            if (($e = $this->_parse_expr(Formula::$opprec["u+"], false))) {
                $ff->args[] = $e;
                return true;
            }
            $this->pos = $pos0;
            return false;
        }

        // raw arguments
        $pos1 = $pos;
        if ($argtype === "raw") {
            $pos = self::span_parens_until($t, $pos, ")");
            $ff->rawargs[] = substr($t, $pos1, $pos - $pos1);
            $this->pos = $ff->pos2 = $pos;
            return true;
        }

        // parsed arguments
        $warned = $comma = false;
        ++$this->nesting;
        $pos = self::skip_whitespace($t, $pos1 + 1);
        while ($pos !== $len && $t[$pos] !== ")") {
            if ($comma && $t[$pos] === ",") {
                $pos = self::skip_whitespace($t, $pos + 1);
            }
            $this->pos = $apos = $pos;
            $e = $this->_parse_ternary(false) ?? $this->cerror($apos, $this->pos);
            $ff->args[] = $e;
            if ($e->format() === Fexpr::FERROR) {
                $warned = true;
            }
            $pos = self::skip_whitespace($t, $this->pos);
            if ($pos !== $len && $t[$pos] !== ")" && $t[$pos] !== ",") {
                if (!$warned) {
                    $this->weak_lerror($pos, "<0>Expected ‘,’ or ‘)’");
                    $warned = true;
                }
                $pos = self::span_parens_until($t, $pos, "),");
            }
            $comma = true;
        }
        if ($pos !== $len) {
            ++$pos;
        } else if (!$warned) {
            $this->weak_lerror($pos, "<0>Expected ‘)’");
        }
        --$this->nesting;
        $this->pos = $ff->pos2 = $pos;
        return true;
    }

    /** @param string $name
     * @return ?Fexpr */
    private function _parse_function($name, $kwdef) {
        $ff = new FormulaCall($this, $kwdef, $name);
        $args = $kwdef->args ?? false;
        $ff->pos1 = $pos1 = $this->pos;
        $ff->pos2 = $this->pos = $this->pos + strlen($name);

        if ($kwdef->parse_modifier_function ?? false) {
            $mpos = $name === "#" ? $pos1 : $this->pos;
            while (preg_match('/\G[.#:](?:"[^"]*(?:"|\z)|[-a-zA-Z0-9_.@!*?~:\/#]+)/s', $this->str, $m, 0, $mpos)
                   && ($args !== false
                       || !preg_match('/\G\s*\(/', $this->str, $mx, 0, $mpos + strlen($m[0])))) {
                $ff->pos2 = $mpos + strlen($m[0]);
                if (!call_user_func($kwdef->parse_modifier_function, $ff, $m[0], $this->formula)) {
                    $ff->pos2 = $this->pos;
                    break;
                }
                $this->pos = $mpos = $mpos + strlen($m[0]);
            }
        }

        if ($args !== false) {
            if (!$this->_parse_function_args($ff)) {
                $this->lerror($ff->pos1, $this->pos, "<0>Function ‘{$name}’ requires arguments");
                return $this->cerror($ff->pos1, $this->pos);
            } else if (!$ff->check_nargs($args)) {
                return $this->cerror($ff->pos1, $this->pos);
            }
        }

        return $this->_create_function($kwdef, $ff)
            ?? $this->cerror($ff->pos1, $ff->pos2);
    }

    /** @return ?Fexpr */
    private function _create_function($kwdef, FormulaCall $ff) {
        $before = count($this->formula->lerrors);

        if (isset($kwdef->function)) {
            if ($kwdef->function[0] === "+") {
                $class = substr($kwdef->function, 1);
                /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
                return new $class($ff);
            }
            if (($e = call_user_func($kwdef->function, $ff))) {
                return $e;
            }
            if (count($this->formula->lerrors) === $before) {
                $this->lerror($ff->pos1, $ff->pos2, "<0>Parse error");
            }
            return null;
        }

        if (isset($kwdef->macro)) {
            if (!$this->charge(self::NODE_COST)) {
                return null;
            }
            if ($kwdef->__recursion ?? false) {
                $this->lerror_circular($ff->pos1, $ff->pos2, "<0>Formula macro ‘{}’ refers to itself", $ff->name);
                return null;
            }
            try {
                $kwdef->__recursion = true;
                $parser = $this->make_nested($kwdef->macro, $ff, $ff->pos1, $ff->pos2);
                return $parser->parse();
            } finally {
                $kwdef->__recursion = false;
            }
        }

        return null;
    }

    /** @return ?Fexpr */
    private function field_search_fexpr($fval) {
        $fn = null;
        for ($i = 0; $i < count($fval); $i += 2) {
            $k = $fval[$i];
            $v = $fval[$i + 1];
            if ($k === "outcome") {
                $fx = Decision_Fexpr::make($this->user);
            } else {
                $fx = new TimeField_Fexpr($k);
            }
            if ($v === "=0" || $v === "!=0") {
                $fx = new Equality_Fexpr($v === "=0" ? "==" : "!=", $fx, Fexpr::czero());
            } else if ($v === ">0" || $v === ">=0" || $v === "<0" || $v === "<=0") {
                $fx = new Inequality_Fexpr(substr($v, 0, -1), $fx, Fexpr::czero());
            } else if (is_string($v)) {
                error_log("field_search_fexpr given {$v}");
                $fx = Fexpr::cnull();
            } else {
                $fx = new In_Fexpr($fx, $v);
            }
            $fn = $fn ? new And_Fexpr($fn, $fx) : $fx;
        }
        return $fn;
    }

    /** @param string $t
     * @return ?Fexpr */
    private function _reviewer_base($t) {
        $t = strtolower($t);
        $len = strlen($t);
        if (str_ends_with($t, "type")) {
            if ($len === 4 || in_array($t, ["rtype", "retype", "revtype", "reviewtype"], true)) {
                return Revtype_Fexpr::make($this->user);
            }
        } else if (str_ends_with($t, "round")) {
            if ($len === 5 || in_array($t, ["rround", "reround", "revround", "reviewround"], true)) {
                return ReviewRound_Fexpr::make($this->user);
            }
        } else if ($t === "reviewer") {
            if (!$this->user->can_view_some_review_identity()) {
                return Fexpr::cnever();
            }
            return new Reviewer_Fexpr;
        } else if (str_ends_with($t, "auwords")) {
            if ($len === 7 || in_array($t, ["rauwords", "reauwords", "revauwords", "reviewauwords"], true)) {
                return ReviewWordCount_Fexpr::make($this->user);
            }
        } else if (str_ends_with($t, "words")) {
            if ($len === 5 || in_array($t, ["rwords", "rewords", "revwords", "reviewwords"], true)) {
                return ReviewWordCount_Fexpr::make($this->user);
            }
        } else if ($t !== "") {
            if ($t[0] === "c") {
                $completeness = ReviewSearchMatcher::COMPLETE;
                $t = substr($t, 1);
            } else if ($t[0] === "i") {
                $completeness = ReviewSearchMatcher::INCOMPLETE;
                $t = substr($t, 1);
            } else if ($t[0] === "p") {
                $completeness = ReviewSearchMatcher::INPROGRESS;
                $t = substr($t, 1);
            } else {
                $completeness = 0;
            }
            if (in_array($t, ["re", "rev", "review"], true)) {
                return new ReviewStatus_Fexpr($completeness);
            }
        }
        return null;
    }

    /** @param ?Fexpr $e0
     * @return ?Fexpr */
    private function _reviewer_decoration($e0) {
        $es = [];
        $rsm = new ReviewSearchMatcher;
        $t = $this->str;
        $len = strlen($t);
        $completeness = 0;
        if (($had_revexists = $e0 instanceof ReviewStatus_Fexpr)) {
            $completeness = $e0->status;
            $e0 = null;
        }
        while ($this->pos !== $len) {
            if (!preg_match('/\G:((?:"[^"]*(?:"|\z)|~*[-A-Za-z0-9_.\#@]+(?!\s*\())+)/si', $t, $m, 0, $this->pos)) {
                break;
            }
            $lcword = strtolower($m[1]);
            if (in_array($lcword, ["null", "false", "true", "pid", "paperid"], true)) {
                break;
            }
            if (in_array($lcword, ["type", "round", "reviewer", "words", "auwords"], true)) {
                if ($e0) {
                    break;
                }
                $e0 = $this->_reviewer_base($m[1]);
            } else if ($rsm->apply_review_type($m[1])
                       || $rsm->apply_round($m[1], $this->conf)
                       || $rsm->apply_completeness($m[1])) {
                // nothing
            } else if ($lcword === "pc") {
                $es[] = new Inequality_Fexpr(">=", Revtype_Fexpr::make($this->user), new Constant_Fexpr(REVIEW_PC, Fexpr::FREVTYPE));
            } else {
                if (strpos($m[1], "\"") !== false) {
                    $m[1] = str_replace(["\"", "*"], ["", "\\*"], $m[1]);
                }
                $es[] = ReviewerMatch_Fexpr::make($this->user, $m[1]);
            }
            $this->pos += strlen($m[0]);
        }

        $rsm->finish();
        if ($rsm->review_type()) {
            $es[] = new Equality_Fexpr("==", Revtype_Fexpr::make($this->user), new Constant_Fexpr($rsm->review_type(), Fexpr::FREVTYPE));
        }
        if ($rsm->round_list !== null) {
            $es[] = new In_Fexpr(ReviewRound_Fexpr::make($this->user), $rsm->round_list);
        }
        if ($completeness || $rsm->completeness_mask() !== 0) {
            $es[] = new ReviewStatus_Fexpr($completeness | $rsm->completeness_mask());
        }

        $e1 = empty($es) ? null : $es[0];
        for ($i = 1; $i < count($es); ++$i) {
            $e1 = new And_Fexpr($e1, $es[$i]);
        }

        if (!$e0 || !$e1) {
            return $e0 ?? $e1;
        } else if ($e0->format() === Fexpr::FBOOL) {
            return new And_Fexpr($e0, $e1);
        }
        return new Ternary_Fexpr($e1, $e0, Fexpr::cnull());
    }

    /** @param string $text
     * @return list<PaperOption|ReviewField|NamedFormula> */
    function find_all_fields($text) {
        $amflag = $this->user->is_root_user() ? Conf::MFLAG_GLOBAL : 0;
        return $this->conf->find_all_fields($text, $amflag);
    }

    /** @param string &$field
     * @return null|PaperOption|ReviewField|NamedFormula */
    private function _find_formula_field(&$field) {
        $s = $field;
        if (($colon = strpos($s, ":")) !== false) {
            $s = substr($s, 0, $colon);
        }
        while (strlen($s) > 1) {
            $fs = $this->find_all_fields($s);
            if (count($fs) === 1) {
                $field = $s;
                return $fs[0];
            }
            if (($dash = strrpos($s, "-")) === false) {
                break;
            }
            $s = substr($s, 0, $dash);
        }
        return null;
    }

    /** @param int $pos1
     * @return Fexpr */
    private function _parse_one_option($pos1, PaperOption $opt) {
        if (!$this->user->can_view_some_option($opt)) {
            $fex = Fexpr::cnever();
            $fex->apply_strspan($pos1, $this->pos, $this->string_context);
            return $fex;
        }
        $ff = new FormulaCall($this, null, $opt->search_keyword());
        $ff->pos1 = $pos1;
        $ff->pos2 = $this->pos;
        $nerrors = count($this->formula->lerrors);
        if (($fex = $opt->parse_fexpr($ff))) {
            return $fex;
        }
        if (count($this->formula->lerrors) === $nerrors) {
            $ff->lerror("<0>Submission field ‘{$opt->name}’ can’t be used in formulas");
        }
        return $this->cerror($ff->pos1, $ff->pos2);
    }

    /** @param int $pos1
     * @return Fexpr */
    private function _parse_option($pos1) {
        if (!preg_match('/\G[A-Za-z0-9_.@]+/', $this->str, $m, 0, $this->pos)) {
            $this->weak_lerror($pos1, "<0>Submission field missing");
            return $this->cerror($pos1, $pos1);
        }

        $oname = $m[0];
        $this->pos += strlen($oname);
        if (($opt = $this->conf->abbrev_matcher()->find1($oname, Conf::MFLAG_OPTION))) {
            return $this->_parse_one_option($pos1, $opt);
        }

        if (($os2 = $this->conf->abbrev_matcher()->find_all($oname, Conf::MFLAG_OPTION))) {
            $ts = array_map(function ($o) { return "‘" . $o->search_keyword() . "’"; }, $os2);
            $this->lerror($pos1, $this->pos, "<0>‘{$oname}’ matches more than one submission field; try " . commajoin($ts, " or "));
        } else {
            $this->lerror($pos1, $this->pos, "<0>Submission field ‘{$oname}’ not found");
        }
        return $this->cerror($pos1, $this->pos);
    }

    /** @param int $pos1
     * @param PaperOption|ReviewField|NamedFormula $f
     * @return Fexpr */
    private function _parse_field($pos1, $f) {
        if ($f instanceof PaperOption) {
            return $this->_parse_one_option($pos1, $f);
        }
        if ($f instanceof ReviewField) {
            if ($f->view_score <= $this->user->permissive_view_score_bound()) {
                return Fexpr::cnever();
            } else if ($f instanceof Score_ReviewField
                       || $f instanceof Checkbox_ReviewField) {
                return $this->_reviewer_decoration(new Score_Fexpr($f));
            }
            $this->lerror($pos1, $this->pos, "<0>Review field ‘{$f->name}’ can’t be used in formulas");
            return $this->cerror($pos1, $this->pos);
        }
        if ($f instanceof NamedFormula) {
            if (!$this->charge(self::NODE_COST)) {
                return $this->cerror($pos1, $this->pos);
            }
            if ($f->recursion) {
                $this->lerror_circular($pos1, $this->pos, "<0>Saved formula ‘{}’ refers to itself", $f->name);
                return $this->cerror($pos1, $this->pos);
            }
            try {
                $f->recursion = true;
                $parser = $this->make_nested($f->expression, null, $pos1, $this->pos);
                return $parser->parse();
            } finally {
                $f->recursion = false;
            }
        }
        $this->lerror($pos1, $this->pos, "<0>Field not found");
        return $this->cerror($pos1, $this->pos);
    }

    /** @param string $var
     * @param int $varpos
     * @param int $exprpos
     * @param bool $in_qc
     * @return Fexpr */
    private function _parse_let($var, $varpos, $exprpos, $in_qc) {
        $saved_bind = $this->_bind;
        $vals = [];
        while (true) {
            if (in_array($var, ["null", "true", "false", "let", "and", "or", "xor", "not", "in"], true)) {
                $this->lerror($varpos, $varpos + strlen($var), "<0>Cannot redefine reserved word ‘{$var}’");
                $varok = false;
            } else if (isset($vals[$var])) {
                $this->lerror($varpos, $varpos + strlen($var), "<0>‘{$var}’ multiply defined");
                $varok = false;
            } else {
                $varok = true;
            }

            $this->pos = $exprpos;
            $vale = $this->_parse_ternary(false);
            if (!$vale) {
                $this->_bind = $saved_bind;
                return null;
            }

            if ($varok) {
                $vare = new VarDef_Fexpr($var, null);
                $vare->apply_strspan($varpos, $varpos + strlen($var), $this->string_context);
                $this->_bind[$var] = $vare;
                $vals[$var] = $vale;
            }

            if (preg_match('/\G\s*in(?=[\s(])/si', $this->str, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
                break;
            } else if (!preg_match('/\G(\s*,\s*)([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/si', $this->str, $m, 0, $this->pos)) {
                $this->weak_lerror($exprpos, "<0>Expected ‘in’");
                $this->_bind = $saved_bind;
                return null;
            }
            $var = $m[2];
            $varpos = $this->pos + strlen($m[1]);
            $exprpos = $this->pos + strlen($m[0]);
        }

        $e = $this->_parse_ternary($in_qc);

        if ($e) {
            foreach (array_reverse($vals, true) as $name => $vale) {
                $e = new Let_Fexpr($this->_bind[$name], $vale, $e);
            }
        }
        $this->_bind = $saved_bind;
        return $e;
    }

    /** @return Constant_Fexpr */
    static function make_error_call(FormulaCall $ff) {
        $ff->parser->lerror($ff->pos1, $ff->pos1 + strlen($ff->name), "<0>Function ‘{$ff->name}’ not found");
        return $ff->parser->cerror($ff->pos1, $ff->pos2);
    }

    /** @param int $level
     * @param bool $in_qc
     * @return ?Fexpr */
    private function _parse_expr($level, $in_qc) {
        $t = $this->str;
        $len = strlen($t);
        $this->pos = self::skip_whitespace($t, $this->pos);
        if ($this->pos === $len) {
            return null;
        } else if (!$this->charge(self::NODE_COST)) {
            $this->pos = $len;
            return null;
        }
        $pos1 = $this->pos;

        $e = null;
        $ch = strtolower($t[$this->pos]);
        if ($ch === "(") {
            ++$this->pos;
            $e = $this->_parse_ternary(false);
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len || $t[$this->pos] !== ")") {
                if ($e) {
                    $this->weak_lerror($this->pos, "<0>Expected ‘)’");
                }
                $this->pos = self::span_parens_until($t, $this->pos, ")");
            }
            if (!$e || $this->pos === $len) {
                return $e;
            }
            ++$this->pos;
        } else if ($ch === "-" || $ch === "+" || $ch === "!") {
            $op = $ch;
            ++$this->pos;
            if (!($e = $this->_parse_expr(Formula::$opprec["u{$op}"], $in_qc))) {
                return null;
            }
            $e = $op === "!" ? new Not_Fexpr($e) : new Unary_Fexpr($op, $e);
        } else if ($ch === "n"
                   && preg_match('/\Gnot(?=[\s(]|\z)/s', $t, $m, 0, $this->pos)) {
            $this->pos += 3;
            if (!($e = $this->_parse_expr(Formula::$opprec["u!"], $in_qc))) {
                return null;
            }
            $e = new Not_Fexpr($e);
        } else if (preg_match('/\G(?:\d++\.?+\d*+|\.\d++)(?![A-Za-z])/s', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Constant_Fexpr((float) $m[0], Fexpr::FNUMERIC);
        } else if (($ch === "f" || $ch === "t")
                   && preg_match('/\G(?:false|true)\b/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Constant_Fexpr($m[0], Fexpr::FBOOL);
        } else if ($ch === "n"
                   && preg_match('/\Gnull\b/s', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = Fexpr::cnull();
        } else if ($ch === "o"
                   && preg_match('/\Gopt(?:ion)?:\s*/s', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_parse_option($pos1);
        } else if ($ch === "d"
                   && preg_match('/\G(?:dec|decision):\s*([-a-zA-Z0-9_.\#@*]+)/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $me = $this->conf->decision_set()->matchexpr($m[1]);
            $e = $this->field_search_fexpr(["outcome", $me]);
        } else if ($ch === "d"
                   && preg_match('/\G(?:dec|decision)\b/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = Decision_Fexpr::make($this->user);
        } else if ($ch === "i"
                   && preg_match('/\Gis:?rev?\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Inequality_Fexpr(">=", Revtype_Fexpr::make($this->user), new Constant_Fexpr(0, Fexpr::FREVTYPE));
        } else if (($ch === "i" || $ch === "p")
                   && preg_match('/\G(?:is:?)?pcrev?\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Inequality_Fexpr(">=", Revtype_Fexpr::make($this->user), new Constant_Fexpr(REVIEW_PC, Fexpr::FREVTYPE));
        } else if (preg_match('/\G(?:is:?)?(meta|pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?|optional)\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Equality_Fexpr("==", Revtype_Fexpr::make($this->user), new Constant_Fexpr($m[1], Fexpr::FREVTYPE));
        } else if (preg_match('/\G(?:is|status):\s*([-a-zA-Z0-9_.\#@*]+)/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->field_search_fexpr(PaperSearch::status_field_matcher($this->conf, $m[1]));
        } else if ($ch === "r"
                   && preg_match('/\G(?:r(?:|e|ev|eview)(?:|type|round|words|auwords)|round|reviewer)(?=[:\#])/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_decoration($this->_reviewer_base($m[0]));
        } else if ($ch === "r"
                   && preg_match('/\G(r(?:|e|ev|eview)(?:|type|round|words|auwords)|round|reviewer)\b\s*(?![\(\s])/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_base($m[1]);
        } else if (($ch === "i" || $ch === "c" || $ch === "p")
                   && preg_match('/\G[icp]re(?:|v|view)(?=[:\#])/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_decoration($this->_reviewer_base($m[0]));
        } else if (($ch === "i" || $ch === "c" || $ch === "p")
                   && preg_match('/\G([icp]re(?:|v|view))\b\s*(?![\(\s])/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_base($m[1]);
        } else if ($ch === "l"
                   && preg_match('/\G(let\s+)([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/si', $t, $m, 0, $this->pos)) {
            $e = $this->_parse_let($m[2], $this->pos + strlen($m[1]), $this->pos + strlen($m[0]), $in_qc);
        } else if ($ch === "\$"
                   && preg_match('/\G\$(\d+)/s', $t, $m, 0, $this->pos)
                   && $this->_macro
                   && intval($m[1]) > 0) {
            $this->pos += strlen($m[0]);
            if (intval($m[1]) <= count($this->_macro->args)) {
                $e = $this->_macro->args[intval($m[1]) - 1];
            } else {
                $e = Fexpr::cnull();
            }
        } else if (($ch === "\"" && preg_match('/\G"(.*?)"/s', $t, $m, 0, $this->pos))
                   || ($ch === "\xE2" && preg_match('/\G(?:“|”)(.*?)(?:"|“|”)/s', $t, $m, 0, $this->pos))) {
            $this->pos += strlen($m[0]);
            $fs = $m[1] === "" ? [] : $this->find_all_fields($m[1]);
            if (count($fs) === 1) {
                $e = $this->_parse_field($pos1, $fs[0]);
            } else {
                $e = new VarUse_Fexpr(new VarDef_Fexpr($m[1], 0));
            }
        } else if ($ch === "#"
                   && ($kwdef = $this->_find_formula_function($ch))) {
            $e = $this->_parse_function($ch, $kwdef);
        } else if (preg_match('/\G((?:[A-Za-z_0-9]|\.[A-Za-z_0-9])(?:[A-Za-z_0-9]|[.@:][A-Za-z_0-9])*+)(-[A-Za-z_0-9](?:[A-Za-z_0-9]|[-.@:][A-Za-z_0-9])*+|)/s', $t, $m, 0, $this->pos)) {
            $isfunc = preg_match('/\G\s*+\(/s', $t, $mm, 0, $this->pos + strlen($m[0]));
            $isfunc1 = $isfunc && $m[2] === "";
            if (!$isfunc1
                && ($vd = $this->_bind[$m[1]] ?? $this->_free_bind[$m[1]] ?? null)) {
                $this->pos += strlen($m[1]);
                $e = new VarUse_Fexpr($vd);
            } else if (($kwdef = $this->_find_formula_function($m[1]))) {
                $e = $this->_parse_function($m[1], $kwdef);
            } else if ($this->too_complex()) {
                // budget exhausted resolving the name: stop here rather
                // than trying it as a field
                $this->pos = $len;
                return null;
            } else if (!$isfunc && ($f = $this->_find_formula_field($m[0]))) {
                $this->pos += strlen($m[0]);
                $e = $this->_parse_field($pos1, $f);
            } else if (!$isfunc) {
                $this->pos += strlen($m[1]);
                $vare = new VarDef_Fexpr($m[1], 0);
                $this->_free_bind[$m[1]] = $vare;
                $e = new VarUse_Fexpr($vare);
            } else {
                $e = $this->_parse_function($m[1], (object) [
                    "name" => $m[1], "args" => true, "optional" => true,
                    "function" => "FormulaParser::make_error_call"
                ]);
            }
        }

        if (!$e) {
            return null;
        }
        $e->apply_strspan($pos1, $this->pos, $this->string_context);

        while (true) {
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len) {
                return $e;
            }

            if (preg_match(Formula::BINARY_OPERATOR_REGEX, $t, $m, 0, $this->pos)) {
                $op = $m[0];
            } else if (!$in_qc && $t[$this->pos] === ":") {
                $op = ":";
            } else {
                return $e;
            }

            $opprec = Formula::$opprec[$op];
            if ($opprec < $level) {
                return $e;
            }

            $posx = $this->pos;
            $this->pos += strlen($op);
            $opx = self::$_oprewrite[$op] ?? $op;
            $opassoc = (self::$_oprassoc[$opx] ?? false) ? $opprec : $opprec + 1;

            $e2 = $this->_parse_expr($opassoc, $in_qc);

            if ($op === ":" && (!$e2 || !($e2 instanceof Constant_Fexpr))) {
                $this->pos = $posx;
                return $e;
            }

            if (!$e2) {
                if ($e->format() !== Fexpr::FERROR) {
                    $this->weak_lerror($this->pos, "<0>Expression expected");
                    $e = $this->cerror($this->pos, $this->pos);
                }
            } else if ($opx === "<" || $opx === ">" || $opx === "<=" || $opx === ">=") {
                $e = new Inequality_Fexpr($opx, $e, $e2);
            } else if ($opx === "==" || $opx === "!=") {
                $e = new Equality_Fexpr($opx, $e, $e2);
            } else if ($opx === "&&") {
                $e = new And_Fexpr($e, $e2);
            } else if ($opx === "||") {
                $e = new Or_Fexpr($e, $e2);
            } else if ($opx === "^^") {
                $e = new Xor_Fexpr($e, $e2);
            } else if ($opx === "??") {
                $e = new Coalesce_Fexpr(FormulaCall::make_args($this, "??", [$e, $e2]));
            } else if ($opx === "+" || $opx === "-") {
                $e = new Additive_Fexpr($opx, $e, $e2);
            } else if ($opx === "*" || $opx === "/" || $opx === "%") {
                $e = new Multiplicative_Fexpr($opx, $e, $e2);
            } else if ($opx === "&" || $opx === "|" || $opx === "^") {
                $e = new Bitwise_Fexpr($opx, $e, $e2);
            } else if ($opx === "<<" || $opx === ">>") {
                $e = new Shift_Fexpr($opx, $e, $e2);
            } else if ($opx === "**") {
                $e = new Pow_Fexpr(FormulaCall::make_args($this, "**", [$e, $e2]));
            } else {
                throw new Exception("Unknown operator {$opx}");
            }
            $e->apply_strspan($pos1, $this->pos, $this->string_context);
        }
    }

    /** @param bool $in_qc
     * @return ?Fexpr */
    private function _parse_ternary($in_qc) {
        if ($this->nesting >= self::MAXNESTING) {
            $this->lerror($this->pos, $this->pos, "<0>Expression too deeply nested");
            $this->pos = strlen($this->str);
            return null;
        }
        ++$this->nesting;

        $t = $this->str;
        $len = strlen($t);
        $pos1 = $this->pos;
        $before = count($this->formula->lerrors);

        if (!($ec = $this->_parse_expr(0, $in_qc))) {
            if (count($this->formula->lerrors) === $before) {
                $this->weak_lerror($pos1, "<0>Expression expected");
            }
            --$this->nesting;
            return null;
        }

        $this->pos = self::skip_whitespace($t, $this->pos);
        if ($this->pos === $len || $t[$this->pos] !== "?") {
            return $ec;
        }
        ++$this->pos;

        $er = null;
        if (($et = $this->_parse_ternary(true))) {
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len || $t[$this->pos] !== ":") {
                $this->weak_lerror($this->pos, "<0>Expected ‘:’");
                $this->pos = self::span_parens_until($t, $this->pos, ":)");
            }
            if ($this->pos !== $len && $t[$this->pos] === ":") {
                ++$this->pos;
            }
            if (($ef = $this->_parse_ternary($in_qc))) {
                $er = new Ternary_Fexpr($ec, $et, $ef);
            }
        }
        $er = $er ?? Fexpr::cerror();
        $er->apply_strspan($pos1, $this->pos, $this->string_context);

        --$this->nesting;
        return $er;
    }

    /** @return ?Fexpr */
    function parse() {
        if ($this->string_context->depth >= self::MAXDEPTH) {
            $fe = Fexpr::cerror();
            $fe->apply_strspan(0, strlen($this->str), $this->string_context);
            $this->formula->fexpr_lerror($fe, "<0>Formula too complex: nested too deeply");
            return $fe;
        }
        $s = (string) $this->str;
        if (trim($s) === "") {
            $fe = Fexpr::cerror();
            $fe->apply_strspan(0, strlen($this->str), $this->string_context);
            $this->formula->fexpr_lerror($fe, "<0>Empty formula");
        } else {
            $fe = $this->_parse_ternary(false)
                ?? $this->cerror(0, strlen($this->str));
            if (self::skip_whitespace($this->str, $this->pos) !== strlen($this->str)) {
                $this->weak_lerror($this->pos, "<0>Expected end of formula");
            }
        }
        return $fe;
    }
}
