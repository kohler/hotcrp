<?php
// formula.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class FormulaCall {
    public $formula;
    public $name;
    public $text;
    public $args = [];
    public $modifier = false;
    public $kwdef;
    public $pos1;
    public $pos2;

    function __construct(Formula $formula, $kwdef, $name) {
        $this->formula = $formula;
        $this->name = $kwdef->name;
        $this->text = $name;
        $this->kwdef = $kwdef;
    }
    function lerror($message_html) {
        return $this->formula->lerror($this->pos1, $this->pos2, $message_html);
    }
}

class Fexpr implements JsonSerializable {
    public $op = "";
    public $args = [];
    public $text;
    public $_format = false;
    public $pos1;
    public $pos2;

    const IDX_NONE = 0;
    const IDX_PC = 1;
    const IDX_REVIEW = 2;
    const IDX_MY = 4;

    const FNULL = -1;
    const FERROR = -2;
    const FBOOL = 1;
    const FROUND = 2;
    const FREVTYPE = 3;
    const FDECISION = 4;
    const FPREFEXPERTISE = 5;
    const FREVIEWER = 6;
    const FTAG = 7; // used in formulagraph.php
    const FDATE = 8;
    const FTIME = 9;
    const FDATEDELTA = 10;
    const FTIMEDELTA = 11;
    const FTIMEBIT = 1;
    const FDELTABIT = 2;
    const FSEARCH = 12; // used in formulagraph.php

    function __construct($op = null, $args = []) {
        if (is_string($op)) {
            $this->op = $op;
            $this->args = $args;
        } else {
            assert($op instanceof FormulaCall);
            $this->op = $op->name;
            $this->args = $op->args;
            $this->pos1 = $op->pos1;
            $this->pos2 = $op->pos1;
        }
    }
    function add($x) {
        $this->args[] = $x;
    }
    function set_landmark($left, $right) {
        $this->pos1 = $left;
        $this->pos2 = $right;
        return $this;
    }

    function format() {
        return $this->_format;
    }
    function math_format() {
        return $this->_format !== self::FREVIEWER;
    }
    function error_format() {
        return $this->_format === self::FERROR;
    }
    function unknown_format() {
        return $this->_format === false;
    }

    static function common_format($args) {
        $commonf = false;
        foreach ($args as $a) {
            $af = $a->format();
            if (is_int($af) && $af < 0) {
                // null or error; ignore
            } else if (!$af || ($commonf && $commonf !== $af)) {
                return null;
            } else {
                $commonf = $af;
            }
        }
        return $commonf;
    }

    function typecheck_resolve_neighbors(Formula $formula) {
        foreach ($this->args as $i => $a) {
            if ($a instanceof Constant_Fexpr
                && $a->unknown_format()
                && ($b = get($this->args, $i ? $i - 1 : $i + 1))) {
                $a->resolve_neighbor($formula, $b);
            }
        }
    }

    function typecheck_arguments(Formula $formula, $ismath = false) {
        $ok = true;
        foreach ($this->args as $a) {
            if ($a->typecheck($formula)) {
                if ($ismath && !$a->math_format()) {
                    $ok = $formula->fexpr_lerror($a, "Unusable in math expressions.");
                }
            } else {
                $ok = false;
            }
        }
        if ($ok && $this->_format === false) {
            $this->_format = $this->typecheck_format();
        }
        return $ok;
    }

    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula);
    }

    function typecheck_format() {
        return null;
    }

    function inferred_index() {
        $lt = 0;
        foreach ($this->args as $a) {
            $lt |= $a->inferred_index();
        }
        return $lt;
    }

    function view_score(Contact $user) {
        $score = VIEWSCORE_AUTHOR;
        foreach ($this->args as $e) {
            $score = min($score, $e->view_score($user));
        }
        return $score;
    }

    static function cast_bool($t) {
        return "($t !== null ? (bool) $t : null)";
    }

    function matches_at_most_once() {
        return false;
    }

    function compile(FormulaCompiler $state) {
        assert("no compile for $this->op");
        return "null";
    }

    function compile_extractor(FormulaCompiler $state) {
        return false;
    }

    function compiled_comparator($cmp, Conf $conf, $other_expr = null) {
        if ($this->_format
            && $this->_format instanceof ReviewField
            && $this->_format->option_letter
            && !$conf->opt("smartScoreCompare")
            && (!$other_expr
                || $other_expr->format() === $this->_format)) {
            if ($cmp[0] == "<") {
                return ">" . substr($cmp, 1);
            } else if ($cmp[0] == ">") {
                return "<" . substr($cmp, 1);
            }
        }
        return $cmp;
    }

    function jsonSerialize() {
        if ($this->op) {
            $x = ["op" => $this->op];
        } else {
            $x = ["type" => get_class($this)];
        }
        foreach ($this->args as $e) {
            $x["args"][] = $e->jsonSerialize();
        }
        return $x;
    }
}

class Constant_Fexpr extends Fexpr {
    private $x;
    function __construct($x, $format = null, $pos1 = null, $pos2 = null) {
        $this->x = $x;
        $this->_format = $format;
        $this->set_landmark($pos1, $pos2);
    }
    private function _check_revtype() {
        $rsm = new ReviewSearchMatcher;
        if ($rsm->apply_review_type($this->x)) {
            $this->x = $rsm->review_type();
            return true;
        } else {
            return false;
        }
    }
    function typecheck(Formula $formula) {
        if ($this->_format === self::FREVTYPE
            && is_string($this->x)
            && !$this->_check_revtype()) {
            return $formula->fexpr_lerror($this, "Unknown review type.");
        } else if ($this->_format === false) {
            return $formula->fexpr_lerror($this, "Undefined.");
        } else {
            return true;
        }
    }
    function resolve_neighbor(Formula $formula, $e) {
        if ($this->_format !== false
            || !$e->typecheck($formula)) {
            return;
        }
        $format = $e->format();
        $letter = "";
        if (strlen($this->x) === 1 && ctype_alpha($this->x)) {
            $letter = strtoupper($this->x);
        }
        if ($format === self::FPREFEXPERTISE && $letter >= "X" && $letter <= "Z") {
            $this->x = 89 - ord($letter);
        } else if ($format instanceof ReviewField
                   && $letter
                   && ($x = $format->parse_option_value($letter))) {
            $this->x = $x;
        } else if ($format === self::FROUND
                   && ($round = $formula->conf->round_number($this->x, false)) !== false) {
            $this->x = $round;
        } else if ($format === self::FREVTYPE && $this->_check_revtype()) {
            /* OK */
        } else {
            return;
        }
        $this->_format = $format;
    }
    function compile(FormulaCompiler $state) {
        return $this->x;
    }
    function jsonSerialize() {
        if ($this->x === "null") {
            return null;
        } else if ($this->x === "true") {
            return true;
        } else if ($this->x === "false") {
            return false;
        } else if (is_numeric($this->x)) {
            return (float) $this->x;
        } else {
            return $this->x;
        }
    }
    static function cerror($pos1 = null, $pos2 = null) {
        return new Constant_Fexpr("null", self::FERROR, $pos1, $pos2);
    }
    static function make_error_call(FormulaCall $ff) {
        $ff->formula->lerror($ff->pos1, $ff->pos1 + strlen($ff->name), "Unknown function.");
        return self::cerror($ff->pos1, $ff->pos2);
    }
    static function cnull() {
        return new Constant_Fexpr("null", self::FNULL);
    }
    static function czero() {
        return new Constant_Fexpr("0");
    }
    static function cfalse() {
        return new Constant_Fexpr("false", self::FBOOL);
    }
    static function ctrue() {
        return new Constant_Fexpr("true", self::FBOOL);
    }
}

class Ternary_Fexpr extends Fexpr {
    function __construct($e0, $e1, $e2) {
        parent::__construct("?!", [$e0, $e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula);
    }
    function typecheck_format() {
        return self::common_format([$this->args[1], $this->args[2]]);
    }
    function view_score(Contact $user) {
        $t = $this->args[0]->view_score($user);
        $tt = $this->args[1]->view_score($user);
        $tf = $this->args[2]->view_score($user);
        return min($t, max($tt, $tf));
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        $tt = $state->_addltemp($this->args[1]->compile($state));
        $tf = $state->_addltemp($this->args[2]->compile($state));
        return "($t ? $tt : $tf)";
    }
    function matches_at_most_once() {
        return $this->args[0]->matches_at_most_once()
            && $this->args[2]->format() === self::FNULL;
    }
}

class Equality_Fexpr extends Fexpr {
    function __construct($op, $e0, $e1) {
        assert($op === "==" || $op === "!=");
        parent::__construct($op, [$e0, $e1]);
        $this->_format = self::FBOOL;
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 !== null && $t2 !== null ? $t1 {$this->op} $t2 : null)";
    }
}

class Inequality_Fexpr extends Fexpr {
    function __construct($op, $e0, $e1) {
        assert(in_array($op, ["<", ">", "<=", ">="]));
        parent::__construct($op, [$e0, $e1]);
        $this->_format = self::FBOOL;
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        $op = $this->args[0]->compiled_comparator($this->op, $state->conf, $this->args[1]);
        return "($t1 !== null && $t2 !== null ? $t1 $op $t2 : null)";
    }
}

class And_Fexpr extends Fexpr {
    function __construct($e0, $e1) {
        parent::__construct("&&", [$e0, $e1]);
    }
    function typecheck_format() {
        return self::common_format($this->args);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 ? $t2 : $t1)";
    }
    function matches_at_most_once() {
        return $this->args[0]->matches_at_most_once() || $this->args[1]->matches_at_most_once();
    }
}

class Or_Fexpr extends Fexpr {
    function __construct($e0, $e1) {
        parent::__construct("||", [$e0, $e1]);
    }
    function typecheck_format() {
        return self::common_format($this->args);
    }
    function view_score(Contact $user) {
        return $this->args[0]->view_score($user);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 ? : $t2)";
    }
}

class Not_Fexpr extends Fexpr {
    function __construct(Fexpr $e) {
        parent::__construct("!", [$e]);
        $this->_format = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "!$t";
    }
}

class Unary_Fexpr extends Fexpr {
    function __construct($op, Fexpr $e) {
        assert($op === "+" || $op === "-");
        parent::__construct($op, [$e]);
        $this->_format = null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "{$this->op}$t";
    }
}

class Additive_Fexpr extends Fexpr {
    function __construct($op, $e1, $e2) {
        assert($op === "+" || $op === "-");
        parent::__construct($op, [$e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function typecheck_format() {
        $a0 = $this->args[0];
        $f0 = $a0->format();
        $d0 = is_int($f0) && $f0 >= self::FDATE && $f0 <= self::FTIMEDELTA;
        $a1 = $this->args[1];
        $f1 = $a1->format();
        $d1 = is_int($f1) && $f1 >= self::FDATE && $f1 <= self::FTIMEDELTA;
        if ((!$d0 && !$d1)
            || (!$d0 && $f0)
            || (!$d1 && $f1)) {
            return null;
        } else if ($this->op === "-"
                   && $d0 && !($f0 & self::FDELTABIT)
                   && $d1 && !($f1 & self::FDELTABIT)) {
            $fx = self::FDATEDELTA;
        } else if ($d0 && (!$d1 || ($f1 & self::FDELTABIT))) {
            $fx = $f0 & ~self::FTIMEBIT;
        } else if ($d1 && (!$d0 || ($f0 & self::FDELTABIT))) {
            $fx = $f1 & ~self::FTIMEBIT;
        } else {
            return null;
        }
        if ((($d0 ? $f0 : 0) | ($d1 ? $f1 : 0)) & self::FTIMEBIT) {
            $fx |= self::FTIMEBIT;
        }
        return $fx;
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 !== null || $t2 !== null ? $t1 {$this->op} $t2 : null)";
    }
}

class Multiplicative_Fexpr extends Fexpr {
    function __construct($op, $e1, $e2) {
        assert($op === "*" || $op === "/" || $op === "%");
        parent::__construct($op, [$e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        $t2check = $this->op === "*" ? "$t2 !== null" : "$t2";
        return "($t1 !== null && $t2check ? $t1 {$this->op} $t2 : null)";
    }
}

class Shift_Fexpr extends Fexpr {
    function __construct($op, $e1, $e2) {
        assert($op === "<<" || $op === ">>");
        parent::__construct($op, [$e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 !== null && $t2 !== null ? $t1 {$this->op} $t2 : null)";
    }
}

class Bitwise_Fexpr extends Fexpr {
    function __construct($op, $e1, $e2) {
        assert($op === "&" || $op === "|" || $op === "^");
        parent::__construct($op, [$e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 !== null && $t2 !== null ? $t1 {$this->op} $t2 : null)";
    }
}

class Pow_Fexpr extends Fexpr {
    function __construct($e1, $e2) {
        parent::__construct("**", [$e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "($t1 !== null && $t2 !== null ? pow($t1, $t2) : null)";
    }
}

class In_Fexpr extends Fexpr {
    private $values;
    function __construct(Fexpr $e, array $values) {
        parent::__construct("in", [$e]);
        $this->values = $values;
        $this->_format = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "(array_search($t, [" . join(", ", $this->values) . "]) !== false)";
    }
}

class Extremum_Fexpr extends Fexpr {
    function __construct(FormulaCall $ff) {
        if ($ff->name === "min") {
            $op = "least";
        } else if ($ff->name === "max") {
            $op = "greatest";
        } else {
            $op = $ff->name;
        }
        parent::__construct($op, $ff->args);
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula, true);
    }
    function typecheck_format() {
        return self::common_format($this->args);
    }
    function compile(FormulaCompiler $state) {
        $cmp = $this->compiled_comparator($this->op === "greatest" ? ">" : "<", $state->conf);
        $t1 = "null";
        for ($i = 0; $i < count($this->args); ++$i) {
            $t2 = $state->_addltemp($this->args[$i]->compile($state));
            if ($i === 0) {
                $t1 = $t2;
            } else if ($this->op === "coalesce") {
                $state->lstmt[] = "$t1 = $t1 ?? $t2;";
            } else {
                $state->lstmt[] = "$t1 = ($t1 === null || ($t2 !== null && $t2 $cmp $t1) ? $t2 : $t1);";
            }
        }
        return $t1;
    }
}

class Math_Fexpr extends Fexpr {
    function __construct($ff) {
        parent::__construct($ff);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        if (count($this->args) === 2) {
            $t2 = $state->_addltemp($this->args[1]->compile($state));
        } else {
            $t2 = null;
        }
        if ($this->op === "log" && $t2) {
            return "($t1 !== null && $t2 !== null ? log($t1, $t2) : null)";
        } else if ($this->op === "log10") {
            return "($t1 !== null ? log10($t1) : null)";
        } else if ($this->op === "log2" || $this->op === "lg") {
            return "($t1 !== null ? log($t1, 2.0) : null)";
        } else if ($this->op === "log" || $this->op === "ln") {
            return "($t1 !== null ? log($t1) : null)";
        } else {
            return "($t1 !== null ? {$this->op}($t1) : null)";
        }
    }
}

class Round_Fexpr extends Fexpr {
    function __construct($ff) {
        parent::__construct($ff);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $op = $this->op === "trunc" ? "(int) " : $this->op;
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        if (count($this->args) === 1) {
            return "($t1 !== null ? $op($t1) : null)";
        } else {
            $t2 = $state->_addltemp($this->args[1]->compile($state));
            return "($t1 !== null && $t2 ? $op($t1 / $t2) * $t2 : null)";
        }
    }
}

class AggregateFexpr extends Fexpr {
    public $index_type;
    function __construct($fn, array $values, int $index_type) {
        parent::__construct($fn, $values);
        $this->index_type = $index_type;
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!$ff->modifier) {
            $ff->modifier = [false, false];
        }
        if ($arg === ".pc"
            && !$ff->modifier[0]) {
            $ff->modifier[0] = Fexpr::IDX_PC;
            return true;
        } else if ($arg === ".re"
                   && !$ff->modifier[0]) {
            $ff->modifier[0] = Fexpr::IDX_REVIEW;
            return true;
        } else if (($ff->name === "variance" || $ff->name === "stddev")
                   && !$ff->modifier[1]
                   && strpos($ff->text, "_") === false
                   && in_array($arg, [".p", ".pop", ".s", ".samp"])) {
            $ff->modifier[1] = true;
            if ($arg === ".p" || $arg === ".pop") {
                $ff->name .= "_pop";
            }
            return true;
        } else if (str_starts_with($arg, ".")) {
            $ff->formula->lerror($ff->pos2 - strlen($arg), $ff->pos2, "Unknown aggregate type.");
            return true;
        } else {
            return false;
        }
    }
    static function make(FormulaCall $ff) {
        $op = $ff->name;
        if (($op === "min" || $op === "max")
            && count($ff->args) > 1) {
            if (!$ff->modifier) {
                return new Extremum_Fexpr($ff);
            } else {
                return null;
            }
        }
        if ($op === "wavg" && count($ff->args) === 1) {
            $op = "avg";
        }
        $arg_count = 1;
        if ($op === "atminof" || $op === "atmaxof"
            || $op === "argmin" || $op === "argmax"
            || $op === "wavg" || $op === "quantile") {
            $arg_count = 2;
        }
        if (count($ff->args) !== $arg_count) {
            $ff->lerror("Wrong number of arguments (expected $arg_count).");
            return null;
        }
        if ($op === "atminof" || $op === "atmaxof") {
            $op = "arg" . substr($op, 2, 3);
            $ff->args = [$ff->args[1], $ff->args[0]];
        }
        if ($op === "my") {
            $index_type = Fexpr::IDX_MY;
        } else if ($ff->modifier) {
            $index_type = $ff->modifier[0];
        } else {
            $index_type = 0;
        }
        return new AggregateFexpr($op, $ff->args, $index_type);
    }

    function typecheck(Formula $formula) {
        $ok = $this->typecheck_arguments($formula);
        if (($this->op !== "argmin" && $this->op !== "argmax")
            && !$this->args[0]->math_format()) {
            $ok = $formula->fexpr_lerror($this->args[0], "Unusable in math expressions.");
        }
        if (count($this->args) > 1 && !$this->args[1]->math_format()) {
            $ok = $formula->fexpr_lerror($this->args[1], "Unusable in math expressions.");
        }
        if ($ok && !$this->index_type) {
            $lt = parent::inferred_index();
            if ($lt === 0 || ($lt & ($lt - 1)) === 0) {
                $this->index_type = $lt;
            } else {
                $formula->fexpr_lerror($this, "Can’t infer index, specify “{$this->op}.pc” or “{$this->op}.re”.");
                $ok = false;
            }
        }
        return $ok;
    }

    function typecheck_format() {
        if ($this->op === "all" || $this->op === "any") {
            return self::FBOOL;
        } else if ($this->op === "min" || $this->op === "max"
                   || $this->op === "argmin" || $this->op === "argmax") {
            return $this->args[0]->format();
        } else if ($this->op === "avg" || $this->op === "wavg"
                   || $this->op === "median" || $this->op === "quantile") {
            $f = $this->args[0]->format();
            return $f === self::FBOOL ? null : $f;
        } else {
            return null;
        }
    }

    function inferred_index() {
        return 0;
    }

    static function quantile($a, $p) {
        // The “R-7” quantile implementation
        if (count($a) === 0 || $p < 0 || $p > 1) {
            return null;
        }
        sort($a, SORT_NUMERIC);
        $ix = (count($a) - 1) * $p + 1;
        $i = (int) $ix;
        $v = $a[$i - 1];
        if (($e = $ix - $i)) {
            $v += $e * ($a[$i] - $v);
        }
        return $v;
    }

    private function loop_info(FormulaCompiler $state) {
        if ($this->op === "all") {
            return ["null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", self::cast_bool("~x~")];
        } else if ($this->op === "any") {
            return ["null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", self::cast_bool("~x~")];
        } else if ($this->op === "some") {
            return ["null", "~r~ = ~l~;
  if (~r~ !== null && ~r~ !== false)
    break;"];
        } else if ($this->op === "min" || $this->op === "max") {
            $cmp = $this->compiled_comparator($this->op === "min" ? "<" : ">", $state->conf);
            return ["null", "(~l~ !== null && (~r~ === null || ~l~ $cmp ~r~) ? ~l~ : ~r~)"];
        } else if ($this->op === "argmin" || $this->op === "argmax") {
            $cmp = $this->args[1]->compiled_comparator($this->op === "argmin" ? "<" : ">", $state->conf);
            return ["[null, [null]]",
"if (~l1~ !== null && (~r~[0] === null || ~l1~ $cmp ~r~[0])) {
  ~r~[0] = ~l1~;
  ~r~[1] = [~l~];
} else if (~l1~ !== null && ~l1~ == ~r~[0])
  ~r~[1][] = ~l~;",
                    "~x~[1][count(~x~[1]) > 1 ? mt_rand(0, count(~x~[1]) - 1) : 0]"];
        } else if ($this->op === "count") {
            return ["0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)"];
        } else if ($this->op === "sum") {
            return ["null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)"];
        } else if ($this->op === "avg") {
            return ["[0, 0]", "(~l~ !== null ? [~r~[0] + ~l~, ~r~[1] + 1] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        } else if ($this->op === "median" || $this->op === "quantile") {
            if ($this->op === "median") {
                $q = "0.5";
            } else {
                $q = $state->_addltemp($this->args[1]->compile($state));
                if ($this->compiled_comparator("<", $state->conf) === ">") {
                    $q = "1 - $q";
                }
            }
            return ["[]", "if (~l~ !== null)\n  array_push(~r~, ~l~);",
                    "AggregateFexpr::quantile(~x~, $q)"];
        } else if ($this->op === "wavg") {
            return ["[0, 0]", "(~l~ !== null && ~l1~ !== null ? [~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        } else if ($this->op === "stddev" || $this->op === "stddev_pop"
                   || $this->op === "variance" || $this->op === "variance_pop") {
            if ($this->op === "variance") {
                $x = "(~x~[2] > 1 ? ~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1)) : (~x~[2] ? 0 : null))";
            } else if ($this->op === "variance_pop") {
                $x = "(~x~[2] ? ~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2]) : null)";
            } else if ($this->op === "stddev") {
                $x = "(~x~[2] > 1 ? sqrt(~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1))) : (~x~[2] ? 0 : null))";
            } else {
                $x = "(~x~[2] ? sqrt(~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2])) : null)";
            }
            return ["[0, 0, 0]", "(~l~ !== null ? [~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1] : ~r~)", $x];
        } else {
            return null;
        }
    }

    function compile(FormulaCompiler $state) {
        if ($this->op === "my") {
            return $state->_compile_my($this->args[0]);
        } else if (($li = $this->loop_info($state))) {
            $t = $state->_compile_loop($li[0], $li[1], $this);
            return $li[2] ?? false ? str_replace("~x~", $t, $li[2]) : $t;
        } else {
            return "null";
        }
    }

    function compile_extractor(FormulaCompiler $state) {
        if ($this->op === "my") {
            return $this->args[0]->compile_extractor($state);
        } else if ($this->op === "quantile") {
            $state->fragments[] = $this->args[0]->compile($state);
            return $this->args[1]->compile_extractor($state);
        } else {
            foreach ($this->args as $e) {
                $state->fragments[] = $e->compile($state);
            }
            return true;
        }
    }
}

class Pid_Fexpr extends Fexpr {
    function compile(FormulaCompiler $state) {
        return '$prow->paperId';
    }
}

class Score_Fexpr extends Fexpr {
    private $field;
    function __construct(ReviewField $field) {
        parent::__construct("rf");
        $this->field = $this->_format = $field;
    }
    function inferred_index() {
        return self::IDX_REVIEW;
    }
    function view_score(Contact $user) {
        return $this->field->view_score;
    }
    function compile(FormulaCompiler $state) {
        if ($this->field->view_score <= $state->user->permissive_view_score_bound()) {
            return "null";
        }
        $fid = $this->field->id;
        $state->_ensure_rrow_score($fid);
        $rrow = $state->_rrow();
        $rrow_vsb = $state->_rrow_view_score_bound();
        if ($this->field->allow_empty) {
            return "({$this->field->view_score} > $rrow_vsb ? (int) {$rrow}->$fid : null)";
        } else {
            return "({$this->field->view_score} > $rrow_vsb && isset({$rrow}->$fid) && {$rrow}->$fid ? (int) {$rrow}->$fid : null)";
        }
    }
}

class Let_Fexpr extends Fexpr {
    private $vardef;
    function __construct(VarDef_Fexpr $vardef, Fexpr $val, Fexpr $body) {
        parent::__construct("let", [$val, $body]);
        $this->vardef = $vardef;
    }
    function typecheck(Formula $formula) {
        if (($ok0 = $this->args[0]->typecheck($formula))) {
            $this->vardef->_format = $this->args[0]->format();
            $this->vardef->_index_type = $this->args[0]->inferred_index();
        } else {
            $this->vardef->_format = self::FERROR;
            $this->vardef->_index_type = 0;
        }
        $ok1 = $this->args[1]->typecheck($formula);
        $this->_format = $ok0 && $ok1 ? $this->args[1]->format() : self::FERROR;
        return $ok0 && $ok1;
    }
    function compile(FormulaCompiler $state) {
        $this->vardef->ltemp = $state->_addltemp($this->args[0]->compile($state));
        return $this->args[1]->compile($state);
    }
    function compile_extractor(FormulaCompiler $state) {
        $this->vardef->ltemp = $state->_addltemp($this->args[0]->compile($state));
        return $this->args[1]->compile_extractor($state);
    }
    function jsonSerialize() {
        return ["op" => "let", "name" => $this->vardef->name(),
                "value" => $this->args[0], "body" => $this->args[1]];
    }
}

class VarDef_Fexpr extends Fexpr {
    private $name;
    public $ltemp;
    public $_index_type;
    function __construct($name) {
        parent::__construct("vardef");
        $this->name = $name;
    }
    function name() {
        return $this->name;
    }
    function inferred_index() {
        assert($this->_index_type !== null);
        return $this->_index_type;
    }
    function jsonSerialize() {
        return ["op" => "vardef", "name" => $this->name];
    }
}

class VarUse_Fexpr extends Fexpr {
    private $vardef;
    function __construct(VarDef_Fexpr $vardef) {
        parent::__construct("varuse");
        $this->vardef = $vardef;
    }
    function typecheck(Formula $formula) {
        $this->_format = $this->vardef->format();
        return true;
    }
    function inferred_index() {
        return $this->vardef->inferred_index();
    }
    function compile(FormulaCompiler $state) {
        return $this->vardef->ltemp;
    }
    function jsonSerialize() {
        return ["op" => "varuse", "name" => $this->vardef->name()];
    }
}

class FormulaCompiler {
    public $conf;
    public $user;
    public $tagger;
    private $gvar;
    private $g0stmt;
    public $gstmt;
    public $lstmt;
    public $fragments = array();
    public $combining = null;
    public $index_type;
    public $indexed;
    private $_lprefix;
    private $_maxlprefix;
    private $_lflags;
    public $indent = 2;
    public $queryOptions = array();
    public $tagrefs = null;
    private $_stack;

    const LFLAG_RROW = 1;
    const LFLAG_RROW_VSB = 2;
    const LFLAG_CID = 4;
    const LFLAG_PREFERENCES = 8;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->tagger = new Tagger($user);
        $this->clear();
    }

    function clear() {
        $this->gvar = $this->g0stmt = $this->gstmt = $this->lstmt = [];
        $this->index_type = Fexpr::IDX_NONE;
        $this->indexed = false;
        $this->_lprefix = 0;
        $this->_maxlprefix = 0;
        $this->_lflags = 0;
        $this->_stack = [];
    }

    function check_gvar($gvar) {
        if ($this->gvar[$gvar] ?? false) {
            return false;
        } else {
            $this->gvar[$gvar] = $gvar;
            return true;
        }
    }
    function define_gvar($name, $expr) {
        if (preg_match(',\A\$?(.*[^A-Ya-z0-9_].*)\z,', $name, $m)) {
            $name = '$' . preg_replace_callback(',[^A-Ya-z0-9_],', function ($m) { return "Z" . dechex(ord($m[0])); }, $m[1]);
        } else {
            $name = $name[0] === "$" ? $name : '$' . $name;
        }
        if (get($this->gvar, $name) === null) {
            $this->gstmt[] = "$name = $expr;";
            $this->gvar[$name] = $name;
        }
        return $name;
    }

    function _add_pc() {
        if ($this->check_gvar('$pc')) {
            $this->gstmt[] = "\$pc = \$contact->conf->pc_members();";
        }
        return '$pc';
    }
    function _add_vsreviews() {
        if ($this->check_gvar('$vsreviews')) {
            $this->queryOptions["reviewSignatures"] = true;
            $this->gstmt[] = "\$vsreviews = \$prow->viewable_submitted_reviews_by_user(\$contact);";
        }
        return '$vsreviews';
    }
    function _add_preferences() {
        $this->queryOptions["allReviewerPreference"] = true;
        if ($this->_lprefix) {
            $this->_lflags |= self::LFLAG_PREFERENCES;
            return "\$preferences_{$this->_lprefix}";
        } else {
            if ($this->check_gvar('$preferences')) {
                $this->gstmt[] = "\$preferences = \$contact->can_view_preference(\$prow) ? \$prow->preferences() : [];";
            }
            return '$preferences';
        }
    }
    function _add_conflicts() {
        if ($this->check_gvar('$conflicts')) {
            $this->queryOptions["allConflictType"] = true;
            $this->gstmt[] = "\$conflicts = \$contact->can_view_conflicts(\$prow) ? \$prow->conflicts() : [];";
        }
        return '$conflicts';
    }
    function _add_tags() {
        if ($this->check_gvar('$tags')) {
            $this->queryOptions["tags"] = true;
            $this->gstmt[] = "\$tags = \$prow->searchable_tags(\$contact);";
        }
        return '$tags';
    }
    function _add_now() {
        if ($this->check_gvar('$now')) {
            $this->gstmt[] = "global \$Now;";
        }
        return '$Now';
    }

    function loop_cid($aggregate = false) {
        $this->indexed = true;
        if ($this->index_type === Fexpr::IDX_NONE) {
            return '$rrow_cid';
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return (string) $this->user->contactId;
        } else {
            if (!$aggregate) {
                $this->_lflags |= self::LFLAG_CID;
            }
            return '~i~';
        }
    }
    function _rrow() {
        $this->indexed = true;
        if ($this->index_type === Fexpr::IDX_NONE) {
            return $this->define_gvar("rrow", "\$prow->review_of_user(\$rrow_cid)");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return $this->define_gvar("myrrow", "\$prow->review_of_user(" . $this->user->contactId . ")");
        } else {
            $this->_add_vsreviews();
            $this->_lflags |= self::LFLAG_RROW | self::LFLAG_CID;
            return "\$rrow_{$this->_lprefix}";
        }
    }
    function _rrow_view_score_bound() {
        $rrow = $this->_rrow();
        if ($this->index_type === Fexpr::IDX_NONE) {
            return $this->define_gvar("rrow_vsb", "({$rrow} ? \$contact->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return $this->define_gvar("myrrow_vsb", "({$rrow} ? \$contact->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
        } else {
            $this->_lflags |= self::LFLAG_RROW_VSB | self::LFLAG_CID;
            return "\$rrow_vsb_{$this->_lprefix}";
        }
    }
    function _ensure_rrow_score($fid) {
        if (!isset($this->queryOptions["scores"])) {
            $this->queryOptions["scores"] = array();
        }
        $this->queryOptions["scores"][$fid] = $fid;
        if ($this->check_gvar('$ensure_score_' . $fid)) {
            $this->g0stmt[] = '$prow->ensure_review_score("' . $fid . '");';
        }
    }
    function _ensure_review_word_counts() {
        $this->queryOptions["reviewWordCounts"] = true;
        if ($this->check_gvar('$ensure_reviewWordCounts')) {
            $this->g0stmt[] = '$prow->ensure_review_word_counts();';
        }
    }

    private function _push() {
        $this->_stack[] = [$this->_lprefix, $this->lstmt, $this->index_type, $this->indexed, $this->_lflags];
        $this->_lprefix = ++$this->_maxlprefix;
        $this->lstmt = array();
        $this->index_type = Fexpr::IDX_NONE;
        $this->indexed = false;
        $this->_lflags = 0;
        $this->indent += 2;
        return $this->_lprefix;
    }
    private function _pop() {
        list($this->_lprefix, $this->lstmt, $this->index_type, $this->indexed, $this->_lflags) = array_pop($this->_stack);
        $this->indent -= 2;
    }
    function _addltemp($expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
            return $expr;
        $tname = "\$t" . $this->_lprefix . "_" . count($this->lstmt);
        $this->lstmt[] = "$tname = $expr;";
        return $tname;
    }
    private function _join_lstmt($isblock) {
        $indent = "\n" . str_pad("", $this->indent);
        $t = $isblock ? "{" . $indent : "";
        $t .= join($indent, $this->lstmt);
        if ($isblock) {
            $t .= substr($indent, 0, $this->indent - 1) . "}";
        }
        return $t;
    }

    function loop_variable($index_types) {
        $g = array();
        assert($index_types === ($index_types & (Fexpr::IDX_REVIEW | Fexpr::IDX_PC)));
        if ($index_types & Fexpr::IDX_PC) {
            $g[] = $this->_add_pc();
        }
        if ($index_types & Fexpr::IDX_REVIEW) {
            $g[] = $this->_add_vsreviews();
        }
        if (count($g) > 1) {
            return join(" + ", $g);
        } else if (!empty($g)) {
            return $g[0];
        } else {
            return $this->define_gvar("trivial_loop", "[0]");
        }
    }
    function _compile_loop($initial_value, $combiner, AggregateFexpr $e) {
        $t_result = $this->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $this->_push();
        $this->index_type = $e->index_type;
        $loopstmt = [];

        preg_match_all('/~l(\d*)~/', $combiner, $m);
        foreach (array_unique($m[1]) as $i) {
            if ($this->combining !== null) {
                $t = "\$v{$p}";
                if (count($this->fragments) !== 1) {
                    $t .= "[{$this->combining}]";
                }
                ++$this->combining;
            } else {
                $t = $this->_addltemp($e->args[(int) $i]->compile($this));
            }
            $combiner = str_replace("~l{$i}~", $t, $combiner);
        }

        if (preg_match('/[;}]\s*\z/', $combiner)) {
            $this->lstmt[] = str_replace("\n", str_pad("\n", $this->indent + 1), $combiner);
        } else {
            $this->lstmt[] = "$t_result = $combiner;";
        }

        if ($this->_lflags) {
            $lstmt_pfx = [];
            if ($this->_lflags & self::LFLAG_RROW) {
                if ($this->index_type === Fexpr::IDX_REVIEW) {
                    $v = "\$v$p";
                } else {
                    $v = "(\$vsreviews[\$i$p] ?? null)";
                }
                $lstmt_pfx[] = "\$rrow_{$p} = $v;";
            }
            if ($this->_lflags & self::LFLAG_RROW_VSB) {
                $lstmt_pfx[] = "\$rrow_vsb_{$p} = \$rrow_{$p} ? \$contact->view_score_bound(\$prow, \$rrow_{$p}) : " . VIEWSCORE_EMPTYBOUND . ";";
            }
            if ($this->_lflags & self::LFLAG_PREFERENCES) {
                $loopstmt[] = "\$preferences_{$p} = \$prow->viewable_preferences(\$contact"
                    . ($this->_lflags & self::LFLAG_CID ? "" : ", true")
                    . ");";
            }
            $this->lstmt = array_merge($lstmt_pfx, $this->lstmt);
        }

        if ($this->combining !== null) {
            $loop = "foreach (\$extractor_results as \$v$p) " . $this->_join_lstmt(true);
        } else {
            $g = $this->loop_variable($this->index_type);
            $loop = "foreach ($g as \$i$p => \$v$p) "
                . str_replace("~i~", "\$i$p", $this->_join_lstmt(true));
            $loop = str_replace("({$g}[\$i$p] ?? null)", "\$v$p", $loop);
        }
        $loopstmt[] = $loop;

        $this->_pop();
        $this->lstmt = array_merge($this->lstmt, $loopstmt);
        return $t_result;
    }

    function _compile_my(Fexpr $e) {
        $p = $this->_push();
        $this->index_type = Fexpr::IDX_MY;
        $t = $this->_addltemp($e->compile($this));
        $loop = $this->_join_lstmt(false);
        $this->_pop();
        $this->lstmt[] = $loop;
        return $t;
    }

    function statement_text() {
        return join("\n  ", $this->g0stmt)
            . (empty($this->g0stmt) || empty($this->gstmt) ? "" : "\n  ")
            . join("\n  ", $this->gstmt)
            . (empty($this->gstmt) || empty($this->lstmt) ? "" : "\n  ")
            . join("\n  ", $this->lstmt) . "\n";
    }
}

class FormulaParse {
    public $fexpr;
    public $indexed;
    public $index_type;
    public $format = Fexpr::FERROR;
    public $tagrefs;
    public $lerrors;
}

class Formula implements Abbreviator, JsonSerializable {
    public $conf;
    public $user;

    public $formulaId;
    public $name;
    public $expression;
    public $createdBy = 0;
    public $timeModified = 0;

    private $_allow_indexed;
    private $_abbreviation;

    private $_depth = 0;
    private $_macro;
    private $_bind;
    private $_lerrors;

    private $_parse;
    private $_format;

    private $_extractorf;
    private $_extractor_nfragments;

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠)/';

    const DEBUG = 0;

    static public $opprec = array(
        "**" => 13,
        "u+" => 12, "u-" => 12, "u!" => 12,
        "*" => 11, "/" => 11, "%" => 11,
        "+" => 10, "-" => 10,
        "<<" => 9, ">>" => 9,
        "<" => 8, ">" => 8, "<=" => 8, ">=" => 8, "≤" => 8, "≥" => 8,
        "=" => 7, "==" => 7, "!=" => 7, "≠" => 7,
        "&" => 6,
        "^" => 5,
        "|" => 4,
        ":" => 3,
        "&&" => 2, "and" => 2,
        "||" => 1, "or" => 1,
        "?:" => 0,
        "in" => -1
    );

    static private $_oprassoc = array(
        "**" => true
    );

    static private $_oprewrite = array(
        "=" => "==", ":" => "==", "≤" => "<=", "≥" => ">=", "≠" => "!=",
        "and" => "&&", "or" => "||"
    );

    const ALLOW_INDEXED = 1;
    function __construct($expr = null, $flags = 0) {
        if ($flags === true) {
            $flags = self::ALLOW_INDEXED; // XXX backward compat
        }
        assert(is_int($flags));
        if ($expr !== null) {
            $this->expression = $expr;
            $this->_allow_indexed = ($flags & self::ALLOW_INDEXED) !== 0;
        }
        $this->merge();
    }

    private function merge() {
        $this->formulaId = (int) $this->formulaId;
    }

    static function fetch(Conf $conf, $result) {
        $formula = $result ? $result->fetch_object("Formula") : null;
        if ($formula) {
            $formula->conf = $conf;
            if (!is_int($formula->formulaId)) {
                $formula->merge();
            }
        }
        return $formula;
    }

    static function span_maximal_formula($t) {
        return self::span_parens_until($t, ",;)]}");
    }


    function abbreviations_for($name, $data) {
        return $this->abbreviation();
    }

    function abbreviation() {
        if ($this->_abbreviation === null) {
            $aclass = new AbbreviationClass;
            $aclass->force = true;
            $this->_abbreviation = $this->conf->abbrev_matcher()->unique_abbreviation($this->name, $this, $aclass);
        }
        return $this->_abbreviation;
    }


    /* parsing */

    private function set_user(Contact $user) {
        assert(!$this->conf || $this->conf === $user->conf);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->_parse = null;
    }

    function lerror($pos1, $pos2, $message_html) {
        $len = strlen($this->expression);
        $this->_lerrors[] = [$len + $pos1, $len + $pos2, $message_html];
        return false;
    }

    function fexpr_lerror(Fexpr $expr, $message_html) {
        return $this->lerror($expr->pos1, $expr->pos2, $message_html);
    }

    private function parse(Contact $user) {
        assert(!$this->conf || $this->conf === $user->conf);
        assert($this->_depth === 0);
        if ($user !== $this->user) {
            $this->conf = $user->conf;
            $this->user = $user;
        }
        $this->_lerrors = [];
        $this->_bind = [];
        $t = $this->expression;
        if ((string) $t === "") {
            $this->lerror(0, 0, "Empty formula.");
            $e = null;
        } else {
            ++$this->_depth;
            $e = $this->_parse_ternary($t, false);
            --$this->_depth;
        }
        $fp = new FormulaParse;
        if (!$e
            || $t !== ""
            || !empty($this->_lerrors)) {
            if (empty($this->_lerrors)) {
                $this->lerror(-strlen($t), 0, "Parse error.");
            }
        } else if (!$e->typecheck($this)) {
            if (empty($this->_lerrors)) {
                $this->fexpr_lerror($e, "Type error.");
            }
        } else {
            $state = new FormulaCompiler($this->user);
            $e->compile($state);
            if ($state->indexed && !$this->_allow_indexed
                && $e->matches_at_most_once()) {
                $e = new AggregateFexpr("some", [$e]);
                $state = new FormulaCompiler($this->user);
                $e->compile($state);
            }
            if ($state->indexed && !$this->_allow_indexed) {
                $this->fexpr_lerror($e, "Need an aggregate function like “sum” or “max”.");
            } else {
                $fp->fexpr = $e;
                $fp->indexed = !!$state->indexed;
                if ($fp->indexed) {
                    $fp->index_type = $e->inferred_index();
                } else if ($e instanceof AggregateFexpr) {
                    $fp->index_type = $e->index_type;
                } else {
                    $fp->index_type = 0;
                }
                $fp->format = $e->format();
                $fp->tagrefs = $state->tagrefs;
            }
        }
        $fp->lerrors = $this->_lerrors;
        $this->_lerrors = null;
        $this->_bind = null;
        return $fp;
    }

    function check(Contact $user = null) {
        if ($this->_parse === null || ($user && $user !== $this->user)) {
            $this->_parse = $this->parse($user ?? $this->user);
            $this->_format = $this->_parse->format;
        }
        return $this->_format !== Fexpr::FERROR;
    }

    function error_html() {
        if ($this->check()) {
            return "";
        } else {
            $x = [];
            foreach ($this->_parse->lerrors as $e) {
                $x[] = Ht::contextual_diagnostic($this->expression, $e[0], $e[1], $e[2]);
            }
            return "<pre>" . join("", $x) . "</pre>";
        }
    }

    private static function span_parens_until($t, $span) {
        $pos = 0;
        $len = strlen($t);
        while ($pos !== $len && strpos($t[$pos], $span) === false) {
            $x = SearchSplitter::span_balanced_parens($t, $pos, function ($ch) use ($span) {
                return strpos($ch, $span) !== false;
            });
            $pos = max($pos + 1, $x);
        }
        return $pos;
    }

    private function _find_formula_function(&$m) {
        while ($m[1] !== "") {
            if (($kwdef = $this->conf->formula_function($m[1], $this->user))) {
                return $kwdef;
            }
            $pos1 = strrpos($m[1], ":");
            $pos2 = strrpos($m[1], ".");
            $m[1] = substr($m[1], 0, max((int) $pos1, (int) $pos2));
        }
        return false;
    }

    private function _parse_function_args(FormulaCall $ff, &$t) {
        $argtype = $ff->kwdef->args;
        $t = ltrim($t);
        // collect arguments
        if ($t === "") {
            return $argtype === "optional" || !empty($ff->args);
        } else if ($t[0] === "(" && $argtype === "raw") {
            $pos = self::span_parens_until($t, ")");
            $ff->args[] = substr($t, 0, $pos);
            $t = substr($t, $pos);
            return true;
        } else if ($t[0] === "(") {
            $warned = $comma = false;
            ++$this->_depth;
            $t = ltrim(substr($t, 1));
            while ($t !== "" && $t[0] !== ")") {
                if ($comma && $t[0] === ",") {
                    $t = ltrim(substr($t, 1));
                }
                $pos1 = -strlen($t);
                $e = $this->_parse_ternary($t, false);
                if ($e) {
                    $ff->args[] = $e;
                } else {
                    $ff->args[] = Constant_Fexpr::cerror($pos1, -strlen($t));
                }
                $t = ltrim($t);
                if ($t !== "" && $t[0] !== ")" && $t[0] !== ",") {
                    if (!$warned) {
                        $this->lerror(-strlen($t), -strlen($t), "Expected “,” or “)”.");
                        $warned = true;
                    }
                    $t = substr($t, self::span_parens_until($t, "),"));
                }
                $comma = true;
            }
            if ($t === "") {
                $this->lerror(0, 0, "Missing “)”.");
            } else {
                $t = substr($t, 1);
            }
            --$this->_depth;
            return true;
        } else if ($argtype === "optional" || !empty($ff->args)) {
            return true;
        } else if (($e = $this->_parse_expr($t, self::$opprec["u+"], false))) {
            $ff->args[] = $e;
            return true;
        } else {
            return false;
        }
    }

    static private function _pop_argument($t) {
        if (preg_match('/\s*((?:"[^"]*(?:"|\z)|[^\s()]*)*)(.*)\z/s', $t, $m) && $m[1] !== "")
            return $m;
        else
            return [$t, "", $t];
    }

    const ARGUMENT_REGEX = '((?:"[^"]*"|[-#a-zA-Z0-9_.@!*?~]+|:(?="|[-#a-zA-Z0-9_.@+!*\/?~]+(?![()])))+)';

    private function _parse_function(&$t, $name, $kwdef) {
        $ff = new FormulaCall($this, $kwdef, $name);
        $args = $kwdef->args ?? false;

        $ff->pos1 = $pos1 = -strlen($t);
        $t = substr($t, strlen($name));

        if ($kwdef->parse_modifier_callback ?? false) {
            $xt = $name === "#" ? "#" . $t : $t;
            while (preg_match('/\A([.#:](?:"[^"]*(?:"|\z)|[-a-zA-Z0-9_.@!*?~:\/#]+))(.*)/s', $xt, $m)
                   && ($args !== false || !preg_match('/\A\s*\(/s', $m[2]))) {
                $ff->pos2 = -strlen($m[2]);
                if (call_user_func($kwdef->parse_modifier_callback, $ff, $m[1], $m[2], $this)) {
                    $t = $xt = $m[2];
                } else {
                    break;
                }
            }
        }

        if ($args !== false) {
            if (!$this->_parse_function_args($ff, $t)) {
                $this->lerror($pos1, -strlen($t), "Function requires arguments.");
                return Constant_Fexpr::cerror($pos1, -strlen($t));
            } else if ((is_int($args) && count($ff->args) !== $args)
                       || (is_array($args) && (count($ff->args) < $args[0] || count($ff->args) > $args[1]))) {
                $m = "Wrong number of arguments";
                if (is_int($args)) {
                    $m .= " ($args expected)";
                }
                $this->lerror($pos1, -strlen($t), "$m.");
                return Constant_Fexpr::cerror($pos1, -strlen($t));
            }
        }
        $ff->pos2 = -strlen($t);

        if (isset($kwdef->callback)) {
            if ($kwdef->callback[0] === "+") {
                $class = substr($kwdef->callback, 1);
                $e = new $class($ff, $this);
            } else {
                $before = count($this->_lerrors);
                $e = call_user_func($kwdef->callback, $ff, $this);
                if (!$e && count($this->_lerrors) === $before) {
                    $this->lerror($ff->pos1, $ff->pos2, "Parse error.");
                }
            }
        } else if (isset($kwdef->macro)) {
            if ($this->_depth > 20) {
                $this->lerror($pos1, -strlen($t), "Circular macro definition.");
                $e = null;
            } else {
                ++$this->_depth;
                $old_macro = $this->_macro;
                $this->_macro = $ff;
                $tt = $kwdef->macro;
                $before = count($this->_lerrors);
                $e = $this->_parse_ternary($tt, false);
                $this->_macro = $old_macro;
                --$this->_depth;
                if (!$e || $tt !== "") {
                    if (count($this->_lerrors) === $before) {
                        $this->lerror($ff->pos1, $ff->pos2, "Parse error in macro.");
                    }
                    $e = null;
                }
            }
        } else {
            $e = null;
        }

        return $e ? : Constant_Fexpr::cerror($ff->pos1, $ff->pos2);
    }

    static private function field_search_fexpr($fval) {
        $fn = null;
        for ($i = 0; $i < count($fval); $i += 2) {
            $k = $fval[$i];
            $v = $fval[$i + 1];
            $fx = ($k === "outcome" ? new Decision_Fexpr : new TimeField_Fexpr($k));
            if ($v === "=0" || $v === "!=0") {
                $fx = new Equality_Fexpr($v === "=0" ? "==" : "!=", $fx, Constant_Fexpr::czero());
            } else if ($v === ">0" || $v === ">=0" || $v === "<0" || $v === "<=0") {
                $fx = new Inequality_Fexpr(substr($v, 0, -1), $fx, Constant_Fexpr::czero());
            } else if (is_string($v)) {
                error_log("field_search_fexpr given $v");
                $fx = Constant_Fexpr::cnull();
            } else {
                $fx = new In_Fexpr($fx, $v);
            }
            $fn = $fn ? new And_Fexpr($fn, $fx) : $fx;
        }
        return $fn;
    }

    private function _reviewer_base($t) {
        $t = strtolower($t);
        if (preg_match('/\A(?:(?:re|rev|review)(?:|type)|rtype)\z/i', $t)) {
            return new Revtype_Fexpr;
        } else if (preg_match('/\A(?:|r|re|rev|review)round\z/i', $t)) {
            return new ReviewRound_Fexpr;
        } else if (preg_match('/\A(?:|r|re|rev)reviewer\z/i', $t)) {
            return new Reviewer_Fexpr;
        } else if (preg_match('/\A(?:|r|re|rev|review)(?:|au)words\z/i', $t)) {
            return new ReviewWordCount_Fexpr;
        } else {
            return null;
        }
    }

    private function _reviewer_decoration(&$t, $e0) {
        $es = [];
        $rsm = new ReviewSearchMatcher;
        while ($t !== "") {
            if (!preg_match('/\A:((?:"[^"]*(?:"|\z)|~*[-A-Za-z0-9_.#@]+(?!\s*\())+)(.*)/si', $t, $m)
                || preg_match('/\A(?:null|false|true|pid|paperid)\z/i', $m[1])) {
                break;
            }
            $ee = null;
            if (preg_match('/\A(?:type|round|reviewer|words|auwords)\z/i', $m[1])) {
                if ($e0) {
                    break;
                }
                $e0 = $this->_reviewer_base($m[1]);
            } else if ($rsm->apply_review_type($m[1])
                       || $rsm->apply_round($m[1], $this->conf)) {
                // nothing
            } else if ($m[1] === "pc") {
                $es[] = new Inequality_Fexpr(">=", new Revtype_Fexpr, new Constant_Fexpr(REVIEW_PC, Fexpr::FREVTYPE));
            } else {
                if (strpos($m[1], "\"") !== false) {
                    $m[1] = str_replace(["\"", "*"], ["", "\\*"], $m[1]);
                }
                $es[] = new ReviewerMatch_Fexpr($this->user, $m[1]);
            }
            $t = $m[2];
        }

        $rsm->finish();
        if ($rsm->review_type()) {
            $es[] = new Equality_Fexpr("==", new RevType_Fexpr, new Constant_Fexpr($rsm->review_type(), Fexpr::FREVTYPE));
        }
        if ($rsm->round) {
            $es[] = new In_Fexpr(new ReviewRound_Fexpr, $rsm->round);
        }

        $e1 = empty($es) ? null : $es[0];
        for ($i = 1; $i < count($es); ++$i) {
            $e1 = new And_Fexpr($e1, $es[$i]);
        }

        if ($e0 && $e1) {
            return new Ternary_Fexpr($e1, $e0, Constant_Fexpr::cnull());
        } else {
            return $e0 ? : $e1;
        }
    }

    private function _parse_option($pos1, $pos2, $text) {
        $os = Option_SearchTerm::analyze($this->conf, $text, true);
        foreach ($os->warnings as $w) {
            $this->lerror($pos1, $pos2, $w);
        }
        $e = null;
        foreach ($os->os as $o) {
            $ex = new Option_Fexpr($o->option);
            if ($o->kind) {
                $this->lerror($pos1, $pos2, "Option can’t be used in formulas.");
            } else if ($o->value === null) {
                $ex = $o->compar === "=" ? $ex : new Not_Fexpr($ex);
            } else if (is_array($o->value) && $o->compar === "!=") {
                $ex = new Not_Fexpr(new In_Fexpr($ex, $o->value));
            } else if (is_array($o->value)) {
                $ex = new In_Fexpr($ex, $o->value);
            } else {
                $op = self::$_oprewrite[$o->compar] ?? $o->compar;
                assert(in_array($op, ["==", "!=", "<", ">", "<=", ">="]));
                $ex2 = new Constant_Fexpr($o->value, $o->option);
                if ($op === "==" || $op === "!=") {
                    $ex = new Equality_Fexpr($op, $ex, $ex2);
                } else {
                    $ex = new Inequality_Fexpr($op, $ex, $ex2);
                }
            }
            $e = $e ? new Or_Fexpr($e, $ex) : $ex;
        }
        if ($e && $os->negated) {
            $e = new Not_Fexpr($e);
        }
        return $e;
    }

    private function _parse_field($pos1, &$t, $f) {
        $pos2 = -strlen($t);
        if ($f instanceof PaperOption) {
            return $this->_parse_option($pos1, $pos2, $f->search_keyword());
        } else if ($f instanceof ReviewField) {
            if ($f->has_options) {
                return $this->_reviewer_decoration($t, new Score_Fexpr($f));
            } else {
                $this->lerror($pos1, $pos2, "This review field can’t be used in formulas.");
            }
        } else if ($f instanceof Formula) {
            if ($f->_depth === 0) {
                $fp = $f->parse($this->user);
                if (!$fp->error_format()) {
                    return $fp->fexpr;
                } else {
                    $this->lerror($pos1, $pos2, "This formula’s definition contains an error.");
                }
            } else {
                $this->lerror($pos1, $pos2, "Self-referential formula.");
            }
        } else {
            $this->lerror($pos1, $pos2, "Unknown field.");
        }
        return Constant_Fexpr::cerror($pos1, $pos2);
    }

    private function _parse_expr(&$t, $level, $in_qc) {
        if (($t = ltrim($t)) === "") {
            return null;
        }
        $pos1 = -strlen($t);

        $e = null;
        if ($t[0] === "(") {
            $t = substr($t, 1);
            ++$this->_depth;
            $e = $this->_parse_ternary($t, false);
            --$this->_depth;
            $t = ltrim($t);
            if ($t === "" || $t[0] !== ")") {
                $this->lerror(-strlen($t), -strlen($t), "Missing “)”.");
                $t = substr($t, self::span_parens_until($t, ")"));
            }
            if (!$e || $t === "") {
                return $e;
            }
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = $this->_parse_expr($t, self::$opprec["u$op"], $in_qc))) {
                return null;
            }
            $e = $op === "!" ? new Not_Fexpr($e) : new Unary_Fexpr($op, $e);
        } else if ($t[0] === "n"
                   && preg_match('/\Anot([\s(].*|)\z/s', $t, $m)) {
            $t = $m[1];
            if (!($e = $this->_parse_expr($t, self::$opprec["u!"], $in_qc))) {
                return null;
            }
            $e = new Not_Fexpr($e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = new Constant_Fexpr($m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/si', $t, $m)) {
            $e = new Constant_Fexpr($m[1], Fexpr::FBOOL);
            $t = $m[2];
        } else if ($t[0] === "n"
                   && preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = Constant_Fexpr::cnull();
            $t = $m[1];
        } else if ($t[0] === "o"
                   && preg_match('/\Aopt(?:ion)?:\s*(.*)\z/s', $t, $m)) {
            $pos1 = -strlen($m[1]);
            $rest = self::_pop_argument($m[1]);
            $t = $rest[2];
            $e = $this->_parse_option($pos1, -strlen($t), $rest[1]);
        } else if ($t[0] === "d"
                   && preg_match('/\A(?:dec|decision):\s*([-a-zA-Z0-9_.#@*]+)(.*)\z/si', $t, $m)) {
            $me = PaperSearch::decision_matchexpr($this->conf, $m[1], false);
            $e = $this->field_search_fexpr(["outcome", $me]);
            $t = $m[2];
        } else if ($t[0] === "d"
                   && preg_match('/\A(?:dec|decision)\b(.*)\z/si', $t, $m)) {
            $e = new Decision_Fexpr;
            $t = $m[1];
        } else if (preg_match('/\Ais:?rev?\b(.*)\z/is', $t, $m)) {
            $e = new Inequality_Fexpr(">=", new Revtype_Fexpr, new Constant_Fexpr(0, Fexpr::FREVTYPE));
            $t = $m[1];
        } else if (preg_match('/\A(?:is:?)?pcrev?\b(.*)\z/is', $t, $m)) {
            $e = new Inequality_Fexpr(">=", new Revtype_Fexpr, new Constant_Fexpr(REVIEW_PC, Fexpr::FREVTYPE));
            $t = $m[1];
        } else if (preg_match('/\A(?:is:?)?(meta|pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?|optional)\b(.*)\z/is', $t, $m)) {
            $e = new Equality_Fexpr("==", new Revtype_Fexpr, new Constant_Fexpr($m[1], Fexpr::FREVTYPE));
            $t = $m[2];
        } else if (preg_match('/\A(?:is|status):\s*([-a-zA-Z0-9_.#@*]+)(.*)\z/si', $t, $m)) {
            $e = $this->field_search_fexpr(PaperSearch::status_field_matcher($this->conf, $m[1]));
            $t = $m[2];
        } else if ($t[0] === "r"
                   && preg_match('/\A((?:r|re|rev|review)(?:type|round|words|auwords|)|round|reviewer)(?::|(?=#))\s*(.*)\z/is', $t, $m)) {
            $t = ":" . $m[2];
            $e = $this->_reviewer_decoration($t, $this->_reviewer_base($m[1]));
        } else if ($t[0] === "r"
                   && preg_match('/\A((?:r|re|rev|review)(?:type|round|words|auwords)|round|reviewer|re|rev|review)\b\s*(?!\()(.*)\z/is', $t, $m)) {
            $e = $this->_reviewer_base($m[1]);
            $t = $m[2];
        } else if ($t[0] === "l"
                   && preg_match('/\Alet\s+([A-Za-z][A-Za-z0-9_]*)(\s*=\s*)(.*)\z/si', $t, $m)) {
            $var = $m[1];
            $varpos = -(strlen($m[1]) + strlen($m[2]) + strlen($m[3]));
            if (preg_match('/\A(?:null|true|false|let|and|or|not|in)\z/', $var)) {
                $this->lerror($varpos, $varpos + strlen($m[1]), "Reserved word.");
            }
            $vare = new VarDef_Fexpr($m[1]);
            $vare->set_landmark($varpos, $varpos + strlen($m[1]));
            $t = $m[3];
            $e = $this->_parse_ternary($t, false);
            if ($e && preg_match('/\A\s*in(?=[\s(])(.*)\z/si', $t, $m)) {
                $t = $m[1];
                $old_bind = $this->_bind[$var] ?? null;
                $this->_bind[$var] = $vare;
                $e2 = $this->_parse_ternary($t, $in_qc);
                $this->_bind[$var] = $old_bind;
            } else {
                $this->lerror(-strlen($t), -strlen($t), "Expected “in”.");
                $e2 = null;
            }
            if ($e && $e2) {
                $e = new Let_Fexpr($vare, $e, $e2);
            }
        } else if ($t[0] === "\$"
                   && preg_match('/\A\$(\d+)(.*)\z/s', $t, $m)
                   && $this->_macro
                   && intval($m[1]) > 0) {
            if (intval($m[1]) <= count($this->_macro->args)) {
                $e = $this->_macro->args[intval($m[1]) - 1];
            } else {
                $e = Constant_Fexpr::cnull();
            }
            $t = $m[2];
        } else if (($t[0] === "\"" && preg_match('/\A"(.*?)"(.*)\z/s', $t, $m))
                   || ($t[0] === "\xE2" && preg_match('/\A[“”](.*?)["“”](.*)\z/su', $t, $m))) {
            $fs = $m[1] === "" ? [] : $this->conf->find_all_fields($m[1]);
            if (count($fs) !== 1) {
                $e = new Constant_Fexpr($m[1], false);
            } else {
                $e = $this->_parse_field($pos1, $m[2], $fs[0]);
            }
            $t = $m[2];
        } else if (!empty($this->_bind)
                   && preg_match('/\A([A-Za-z][A-Za-z0-9_]*)/', $t, $m)
                   && isset($this->_bind[$m[1]])) {
            $e = new VarUse_Fexpr($this->_bind[$m[1]]);
            $t = substr($t, strlen($m[1]));
        } else if (preg_match('/\A(#|[A-Za-z][A-Za-z0-9_.@:]*)/is', $t, $m)
                   && ($kwdef = $this->_find_formula_function($m))) {
            $e = $this->_parse_function($t, $m[1], $kwdef);
        } else if (preg_match('/\A([-A-Za-z0-9_.@]+)(.*)\z/s', $t, $m)
                   && !preg_match('/\A\s*\(/s', $m[2])) {
            $field = $m[1];
            while (true) {
                if (strlen($field) > 1) {
                    $fs = $this->conf->find_all_fields($field);
                    if (count($fs) === 1) {
                        $e = $this->_parse_field($pos1, $m[2], $fs[0]);
                        break;
                    }
                }
                $dash = strrpos($field, "-");
                if ($dash === false) {
                    break;
                }
                $m[2] = substr($field, $dash) . $m[2];
                $field = substr($field, 0, $dash);
            }
            $t = $m[2];
            $e = $e ? : new Constant_Fexpr($field, false);
        } else if ($this->_depth
                   && preg_match('/\A([A-Za-z][A-Za-z0-9_.@:]*)/is', $t, $m)) {
            $e = $this->_parse_function($t, $m[1], (object) [
                "name" => $m[1], "args" => true, "optional" => true,
                "callback" => "Constant_Fexpr::make_error_call"
            ]);
        }

        if (!$e) {
            return null;
        }
        $e->set_landmark($pos1, -strlen($t));

        while (true) {
            if (($t = ltrim($t)) === "") {
                return $e;
            } else if (preg_match(self::BINARY_OPERATOR_REGEX, $t, $m)) {
                $op = $m[0];
                $tn = substr($t, strlen($m[0]));
            } else if (preg_match('/\A(and|or|in)([\s(].*|)\z/s', $t, $m)) {
                $op = $m[1];
                $tn = $m[2];
            } else if (!$in_qc && substr($t, 0, 1) === ":") {
                $op = ":";
                $tn = substr($t, 1);
            } else {
                return $e;
            }

            $opprec = self::$opprec[$op];
            if ($opprec < $level) {
                return $e;
            }

            $t = $tn;
            $opx = self::$_oprewrite[$op] ?? $op;
            $opassoc = (self::$_oprassoc[$opx] ?? false) ? $opprec : $opprec + 1;

            ++$this->_depth;
            $e2 = $this->_parse_expr($t, $opassoc, $in_qc);
            --$this->_depth;

            if ($op === ":" && (!$e2 || !($e2 instanceof Constant_Fexpr))) {
                $t = ":" . $tn;
                return $e;
            }

            if (!$e2) {
                if (!$e->error_format()) {
                    $this->lerror(-strlen($t), -strlen($t), "Missing expression.");
                }
                $e = Constant_Fexpr::cerror();
            } else if ($opx === "<" || $opx === ">" || $opx === "<=" || $opx === ">=") {
                $e = new Inequality_Fexpr($opx, $e, $e2);
            } else if ($opx === "==" || $opx === "!=") {
                $e = new Equality_Fexpr($opx, $e, $e2);
            } else if ($opx === "&&") {
                $e = new And_Fexpr($e, $e2);
            } else if ($opx === "||") {
                $e = new Or_Fexpr($e, $e2);
            } else if ($opx === "+" || $opx === "-") {
                $e = new Additive_Fexpr($opx, $e, $e2);
            } else if ($opx === "*" || $opx === "/" || $opx === "%") {
                $e = new Multiplicative_Fexpr($opx, $e, $e2);
            } else if ($opx === "&" || $opx === "|" || $opx === "^") {
                $e = new Bitwise_Fexpr($opx, $e, $e2);
            } else if ($opx === "<<" || $opx === ">>") {
                $e = new Shift_Fexpr($opx, $e, $e2);
            } else if ($opx === "**") {
                $e = new Pow_Fexpr($e, $e2);
            } else {
                assert(false);
            }
            $e->set_landmark($pos1, -strlen($t));
        }
    }

    private function _parse_ternary(&$t, $in_qc) {
        $pos1 = -strlen($t);
        $e = $this->_parse_expr($t, 0, $in_qc);
        if (!$e && $this->_depth) {
            $this->lerror($pos1, $pos1, "Expression expected.");
            return $e;
        } else if (!$e || ($t = ltrim($t)) === "" || $t[0] !== "?") {
            return $e;
        }
        $t = substr($t, 1);
        ++$this->_depth;
        $e1 = $this->_parse_ternary($t, true);
        $e2 = null;
        if (!$e1) {
        } else if (($t = ltrim($t)) === "" || $t[0] !== ":") {
            $this->lerror(-strlen($t), -strlen($t), "Expected “:”.");
        } else {
            $t = substr($t, 1);
            $e2 = $this->_parse_ternary($t, $in_qc);
        }
        --$this->_depth;
        $e = $e1 && $e2 ? new Ternary_Fexpr($e, $e1, $e2) : Constant_Fexpr::cerror();
        $e->set_landmark($pos1, -strlen($t));
        return $e;
    }


    private static function compile_body($user, FormulaCompiler $state, $expr,
                                         $sortable) {
        $t = "";
        if ($user) {
            $t .= "assert(\$contact->contactId == $user->contactId);\n  ";
        }
        $t .= $state->statement_text();
        if ($expr !== null) {
            if ($sortable & 3) {
                $t .= "\n  \$x = $expr;";
            }
            if ($sortable & 1) {
                $t .= "\n  \$x = is_bool(\$x) ? (int) \$x : \$x;";
            }
            if ($sortable & 2) {
                $t .= "\n  if (is_float(\$x) && !is_finite(\$x)) {\n"
                    . "    \$x = null;\n  }";
            }
            $t .= "\n  return " . ($sortable & 3 ? "\$x" : $expr) . ";\n";
        }
        return $t;
    }

    private function _compile_function($sortable) {
        if ($this->check()) {
            $state = new FormulaCompiler($this->user);
            $expr = $this->_parse->fexpr->compile($state);
            $t = self::compile_body($this->user, $state, $expr, $sortable);
        } else {
            $t = "return null;\n";
        }

        $args = '$prow, $rrow_cid, $contact';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  // " . simplify_whitespace($this->expression) . "\n  $t}\n");
        return eval("return function ($args) {\n  $t};");
    }

    function compile_function() {
        return $this->_compile_function(0);
    }

    function compile_sortable_function() {
        return $this->_compile_function(1);
    }

    function compile_json_function() {
        return $this->_compile_function(2);
    }

    static function compile_indexes_function(Contact $user, $index_types) {
        $state = new FormulaCompiler($user);
        $g = $state->loop_variable($index_types);
        $t = "assert(\$contact->contactId == $user->contactId);\n  "
            . join("\n  ", $state->gstmt)
            . "\n  return array_keys($g);\n";
        $args = '$prow, $contact';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  $t}\n");
        return eval("return function ($args) {\n  $t};");
    }

    function support_combiner() {
        if ($this->_extractorf === null) {
            $this->check();
            $state = new FormulaCompiler($this->user);
            if ($this->_parse && $this->_parse->fexpr->compile_extractor($state)) {
                $t = self::compile_body($this->user, $state, null, 0);
                if (count($state->fragments) === 1) {
                    $t .= "  return " . $state->fragments[0] . ";\n";
                } else {
                    $t .= "  return [" . join(",", $state->fragments) . "];\n";
                }
                $this->_extractorf = $t;
                $this->_extractor_nfragments = count($state->fragments);
            } else {
                $this->_extractorf = false;
            }
        }
        return $this->_extractorf !== false;
    }

    function compile_extractor_function() {
        $this->support_combiner();
        $t = $this->_extractorf ? : "  return null;\n";
        $args = '$prow, $rrow_cid, $contact';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  // extractor " . simplify_whitespace($this->expression) . "\n  $t}\n");
        return eval("return function ($args) {\n  $t};");
    }

    function compile_combiner_function() {
        if ($this->support_combiner()) {
            $state = new FormulaCompiler($this->user);
            $state->combining = 0;
            $state->fragments = array_fill(0, $this->_extractor_nfragments, "0");
            $t = self::compile_body(null, $state, $this->_parse->fexpr->compile($state), 0);
        } else {
            $t = "return null;\n";
        }
        $args = '$extractor_results';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  // combiner " . simplify_whitespace($this->expression) . "\n  $t}\n");
        return eval("return function ($args) {\n  $t};");
    }

/*    function _unparse_iso_duration($x) {
        $x = round($x);
        $t = "P";
        if ($x < 0) {
            $t .= "-";
            $x = -$x;
        }
        if (($d = floor($x / 86400))) {
            $t .= $d . "D";
            $x -= $d * 86400;
        }
        $tt = "";
        if (($h = floor($x / 3600))) {
            $tt .= $h . "H";
            $x -= $h * 3600;
        }
        if (($m = floor($x / 60))) {
            $tt .= $m . "M";
            $x -= $m * 60;
        }
        if ($x || ($tt === "" && strlen($t) <= 2))
            $tt .= $x . "S";
        if ($tt !== "")
            $t .= "T" . $tt;
        return $t;
    } */
    function _unparse_duration($x) {
        $t = "";
        if ($x < 0) {
            $t .= "-";
            $x = -$x;
        }
        if ($x > 259200) {
            return $t . sprintf("%.1fd", $x / 86400);
        } else if ($x > 7200) {
            return $t . sprintf("%.1fh", $x / 3600);
        } else if ($x > 59) {
            return $t . sprintf("%.1fm", $x / 60);
        } else {
            return $t . sprintf("%.1fs", $x);
        }
    }

    function unparse_html($x, $real_format = null) {
        if ($x === null || $x === false) {
            return "";
        } else if ($x === true) {
            return "✓";
        }
        if (is_int($this->_format) && $this->_format > 0) {
            if ($this->_format === Fexpr::FPREFEXPERTISE) {
                return ReviewField::unparse_letter(91, $x + 2);
            } else if ($this->_format === Fexpr::FREVIEWER) {
                return $this->user->reviewer_html_for($x);
            } else if ($this->_format === Fexpr::FTAG) {
                $tagger = new Tagger($this->user);
                return $tagger->unparse_link($this->_tagrefs[$x]);
            } else if ($this->_format === Fexpr::FDATE
                       || $this->_format === Fexpr::FTIME) {
                $f = $this->_format === Fexpr::FTIME ? "%Y-%m-%dT%T" : "%Y-%m-%d";
                return $x > 0 ? strftime($f, $x) : "";
            } else if ($this->_format === Fexpr::FDATEDELTA
                       || $this->_format === Fexpr::FTIMEDELTA) {
                return $this->_unparse_duration($x);
            }
        }
        $x = round($x * 100) / 100;
        if ($this->_format instanceof ReviewField) {
            return $this->_format->unparse_value($x, ReviewField::VALUE_SC, $real_format);
        } else {
            return $real_format ? sprintf($real_format, $x) : $x;
        }
    }

    function unparse_text($x, $real_format) {
        if ($x === null) {
            return "";
        } else if ($x === true) {
            return "Y";
        } else if ($x === false) {
            return "N";
        }
        if (is_int($this->_format) && $this->_format > 0) {
            if ($this->_format === Fexpr::FPREFEXPERTISE) {
                return ReviewField::unparse_letter(91, $x + 2);
            } else if ($this->_format === Fexpr::FREVIEWER) {
                return $this->user->name_text_for($x);
            } else if ($this->_format === Fexpr::FTAG) {
                $tagger = new Tagger($this->user);
                return $tagger->unparse_hashed($this->_tagrefs[$x]);
            } else if ($this->_format === Fexpr::FDATE
                       || $this->_format === Fexpr::FTIME) {
                $f = $this->_format === Fexpr::FTIME ? "%Y-%m-%dT%T" : "%Y-%m-%d";
                return $x > 0 ? strftime($f, $x) : "";
            } else if ($this->_format === Fexpr::FDATEDELTA
                       || $this->_format === Fexpr::FTIMEDELTA) {
                return $this->_unparse_duration($x);
            }
        }
        $x = round($x * 100) / 100;
        if ($this->_format instanceof ReviewField) {
            return $this->_format->unparse_value($x, 0, $real_format);
        } else {
            return $real_format ? sprintf($real_format, $x) : $x;
        }
    }

    function unparse_diff_html($x, $real_format) {
        if ($x === null) {
            return "";
        } else if (is_int($this->_format)
                   && $this->_format >= Fexpr::FDATE
                   && $this->_format <= Fexpr::FTIMEDELTA) {
            return $this->_unparse_duration($x);
        } else {
            $x = round($x * 100) / 100;
            return $real_format ? sprintf($real_format, $x) : $x;
        }
    }

    function add_query_options(&$queryOptions) {
        if ($this->check()) {
            $state = new FormulaCompiler($this->user);
            $state->queryOptions =& $queryOptions;
            $this->_parse->fexpr->compile($state);
            if ($this->_parse->indexed) {
                $state->loop_variable($this->_parse->index_type);
            }
        }
    }

    function view_score(Contact $user) {
        if ($this->check($this->user ? : $user)) {
            return $this->_parse->fexpr->view_score($user);
        } else {
            return VIEWSCORE_PC;
        }
    }

    function column_header() {
        return $this->name ? : $this->expression;
    }

    function result_format() {
        return $this->check() ? $this->_format : null;
    }
    function result_format_is_real() {
        if (!$this->check()) {
            return null;
        } else if ($this->_format instanceof ReviewField) {
            return !$this->_format->option_letter;
        } else if (is_int($this->_format)
                   && ($this->_format === Fexpr::FBOOL
                       || $this->_format >= Fexpr::FPREFEXPERTISE)) {
            return false;
        } else {
            return true;
        }
    }


    function is_sum() {
        return $this->check() && $this->_parse->fexpr->op === "sum";
    }

    function indexed() {
        return $this->check() && $this->_parse->indexed;
    }
    function index_type() {
        return $this->check() ? $this->_parse->index_type : 0;
    }

    function jsonSerialize() {
        $j = [];
        if ($this->formulaId) {
            $j["id"] = $this->formulaId;
        }
        if ($this->name) {
            $j["name"] = $this->name;
        }
        $j["expression"] = $this->expression;
        $j["parse"] = $this->_parse->fexpr;
        return $j;
    }
}
