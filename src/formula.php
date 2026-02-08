<?php
// formula.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

abstract class Fexpr implements JsonSerializable {
    /** @var string */
    public $op = "";
    /** @var list<Fexpr> */
    public $args = [];
    /** @var int */
    private $_format = 0;
    /** @var mixed */
    private $_format_detail;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;
    /** @var ?SearchStringContext */
    public $string_context;

    const IDX_NONE = 0;
    const IDX_PC = 0x01;
    const IDX_REVIEW = 0x02;
    const IDX_MY = 0x04;
    const IDX_PC_SET_PRIVATE_TAG = 0x08;
    const IDX_CREVIEW = 0x12;
    const IDX_ANYREVIEW = 0x22;
    const IDX_REVIEW_MASK = 0x32;
    const IDX_X = 0x40;

    const FUNKNOWN = 0;
    const FNULL = 1;
    const FERROR = 2;
    const FNUMERIC = 3; /* first real format */
    const FBOOL = 4;
    const FROUND = 5;
    const FREVTYPE = 6;
    const FDECISION = 7;
    const FPREFEXPERTISE = 8;
    const FREVIEWER = 9;
    const FREVIEWFIELD = 10;
    const FTAG = 11; // used in formulagraph.php
    const FSEARCH = 12; // used in formulagraph.php
    const FTAGVALUE = 13;
    const FDATE = 14; // FDATE..FTIMEDELTA must be consecutive
    const FTIME = 15;
    const FDATEDELTA = 16;
    const FTIMEDELTA = 17;
    const FSUBFIELD = 18;

    const FORMAT_DESCRIPTIONS = [
        "unknown", "null", "error", "number", "bool",
        "review round", "review type", "decision", "expertise", "reviewer",
        "review field", "tag", "search", "tag value", "date",
        "time", "duration", "duration", "submission field"
    ];

    /** @param list<Fexpr> $args */
    function __construct($op = null, $args = []) {
        if (is_string($op)) {
            $this->op = $op;
            $this->args = $args;
        } else {
            assert($op instanceof FormulaCall);
            $this->op = $op->name;
            $this->args = $op->args;
            $this->pos1 = $op->pos1;
            $this->pos2 = $op->pos2;
            $this->string_context = $op->parser->string_context;
        }
    }

    /** @param Fexpr $x */
    function add($x) {
        $this->args[] = $x;
    }

    /** @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $context */
    function apply_strspan($pos1, $pos2, $context) {
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
        $this->string_context = $context;
        return $this;
    }


    /** @return int */
    function format() {
        return $this->_format;
    }

    /** @return bool */
    final function ok() {
        return $this->_format !== self::FERROR;
    }

    /** @return bool */
    final function has_format() {
        return $this->_format !== self::FUNKNOWN;
    }

    /** @return bool */
    final function math_format() {
        return $this->_format !== self::FREVIEWER
            && $this->_format !== self::FSUBFIELD;
    }

    /** @return bool */
    final function nonnullable_format() {
        return $this->_format === self::FNUMERIC
            || $this->_format === self::FBOOL;
    }

    /** @return bool */
    final function nonnull_format() {
        return $this->nonnullable_format() && $this->_format_detail;
    }

    /** @return mixed */
    final function format_detail() {
        return $this->_format_detail;
    }

    /** @return string */
    final function format_description() {
        if ($this->_format === Fexpr::FREVIEWFIELD) {
            return $this->_format_detail->name;
        } else if ($this->_format === Fexpr::FSUBFIELD) {
            return $this->_format_detail->name;
        } else {
            return Fexpr::FORMAT_DESCRIPTIONS[$this->_format];
        }
    }

    /** @param int $format
     * @param mixed $format_detail */
    final function set_format($format, $format_detail = null) {
        assert($this->_format === Fexpr::FUNKNOWN);
        $this->_format = $format;
        $this->_format_detail = $format_detail;
    }

    final function set_format_error() {
        $this->_format = Fexpr::FERROR;
        $this->_format_detail = null;
    }

    /** @return string */
    function disallowed_use_error() {
        return "<0>Expression of type " . $this->format_description() . " can’t be used here";
    }

    function typecheck_resolve_neighbors(Formula $formula) {
        foreach ($this->args as $i => $a) {
            if ($a instanceof Constant_Fexpr
                && !$a->has_format()
                && ($b = $this->args[$i ? $i - 1 : $i + 1] ?? null)) {
                $a->resolve_neighbor($formula, $b);
            }
        }
    }

    /** @param bool $ismath
     * @return bool */
    function typecheck_arguments(Formula $formula, $ismath = false) {
        $ok = true;
        foreach ($this->args as $a) {
            $ok = $a->typecheck($formula)
                && (!$ismath || $a->typecheck_math_format($formula))
                && $ok;
        }
        if ($ok
            && ($this->_format === Fexpr::FUNKNOWN
                || ($this->nonnullable_format() && $this->_format_detail === null))) {
            $this->_typecheck_format();
        }
        return $ok;
    }

    private function _typecheck_format() {
        $commonf = null;
        $inferred = $this->inferred_format();
        $nonnull = !empty($inferred);
        foreach ($inferred ?? [] as $fe) {
            $nonnull = $nonnull && $fe->nonnull_format();
            if ($fe->format() < Fexpr::FNUMERIC) {
                /* ignore it */
            } else if (!$commonf) {
                $commonf = $fe;
            } else if ($commonf->_format !== $fe->_format
                       || (!$commonf->nonnullable_format()
                           && $commonf->_format_detail !== $fe->_format_detail)) {
                $commonf = null;
                break;
            }
        }
        if ($this->_format === Fexpr::FUNKNOWN) {
            $this->_format = $commonf ? $commonf->_format : Fexpr::FNUMERIC;
            $this->_format_detail = $commonf && !$commonf->nonnullable_format() ? $commonf->_format_detail : $nonnull;
        }
        if ($this->nonnullable_format() && $this->_format_detail === null) {
            $this->_format_detail = $nonnull;
        }
    }

    /** @return bool */
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula);
    }

    /** @return ?list<Fexpr> */
    function inferred_format() {
        return null;
    }

    /** @return ?list<Fexpr> */
    static function inferred_numeric_format(Fexpr $fexpr) {
        $f = $fexpr->format();
        if ($f === self::FBOOL || $f === self::FTAGVALUE) {
            return null;
        } else {
            return [$fexpr];
        }
    }

    /** @return bool */
    function typecheck_math_format(Formula $formula) {
        if ($this->math_format()) {
            return true;
        }
        $formula->fexpr_lerror($this, $this->disallowed_use_error());
        return false;
    }

    /** @return int */
    function inferred_index() {
        $index = 0;
        foreach ($this->args as $a) {
            $index |= $a->inferred_index();
        }
        return $index;
    }

    /** @return int */
    final function some_inferred_index() {
        $index = $this->inferred_index();
        if ($index === 0 || ($index & ($index - 1)) === 0) {
            return $index;
        }
        for ($i = 1; ($i & $index) === 0; $i <<= 1) {
        }
        return $i;
    }

    /** @param string $t
     * @return string */
    static function cast_bool($t) {
        return "({$t} !== null ? (bool) {$t} : null)";
    }

    /** @param string $expr
     * @param string ...$ts
     * @return string */
    protected function check_null_args($expr, ...$ts) {
        assert(count($ts) === count($this->args));
        $nc = [];
        foreach ($this->args as $i => $e) {
            if (!$e->nonnull_format())
                $nc[] = "{$ts[$i]} !== null";
        }
        if (empty($nc)) {
            return $expr;
        } else {
            return "(" . join(" && ", $nc) . " ? {$expr} : null)";
        }
    }

    /** @return bool */
    function matches_at_most_once() {
        return false;
    }

    abstract function compile(FormulaCompiler $state);

    /** @param ?Fexpr $other_expr */
    function compiled_relation($cmp, $other_expr = null) {
        if ($this->_format === Fexpr::FREVIEWFIELD
            && $this->_format_detail->flip_relation()
            && (!$other_expr
                || ($other_expr->format() === Fexpr::FREVIEWFIELD
                    && $other_expr->_format_detail === $this->_format_detail))) {
            return CountMatcher::flip_unparsed_relation($cmp);
        }
        return $cmp;
    }

    /** @param array<int,true> &$oids */
    function paper_options(&$oids) {
        foreach ($this->args as $a) {
            $a->paper_options($oids);
        }
    }

    #[\ReturnTypeWillChange]
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

    /** @return Constant_Fexpr */
    static function cnull() {
        return new Constant_Fexpr("null", Fexpr::FNULL);
    }
    /** @return Constant_Fexpr */
    static function cnever() {
        return self::cnull();
    }
    /** @return Constant_Fexpr */
    static function cerror() {
        return new Constant_Fexpr("null", Fexpr::FERROR);
    }
    /** @return Constant_Fexpr */
    static function czero() {
        return new Constant_Fexpr("0", Fexpr::FNUMERIC);
    }
    /** @return Constant_Fexpr */
    static function cfalse() {
        return new Constant_Fexpr("false", Fexpr::FBOOL);
    }
    /** @return Constant_Fexpr */
    static function ctrue() {
        return new Constant_Fexpr("true", Fexpr::FBOOL);
    }
}

class Constant_Fexpr extends Fexpr {
    /** @var null|int|float|string */
    private $x;
    /** @param null|int|float|string $x
     * @param int $format */
    function __construct($x, $format) {
        $this->x = $x;
        $fd = $format === self::FNUMERIC || $format === self::FBOOL ? true : null;
        $this->set_format($format, $fd);
    }
    private function _check_revtype() {
        $rsm = new ReviewSearchMatcher;
        if ($rsm->apply_review_type($this->x)) {
            $this->x = $rsm->review_type();
            return true;
        }
        return false;
    }
    function typecheck(Formula $formula) {
        if ($this->format() === Fexpr::FREVTYPE
            && is_string($this->x)
            && !$this->_check_revtype()) {
            $formula->fexpr_lerror($this, "<0>Review type ‘{$this->x}’ not found");
            return false;
        } else if (!$this->has_format()) {
            $this->set_format(Fexpr::FERROR);
            $formula->fexpr_lerror($this, "<0>Term ‘{$this->x}’ not found");
            return false;
        }
        return true;
    }
    function resolve_neighbor(Formula $formula, $e) {
        if ($this->has_format()
            || !$e->typecheck($formula)) {
            return;
        }
        $conf = $formula->conf;
        $format = $e->format();
        $format_detail = $e->format_detail();
        switch ($format) {
        case Fexpr::FPREFEXPERTISE:
            $f = Reviewfield::make_expertise($conf);
            $x = $f->parse($this->x);
            if (!$x) {
                return;
            }
            $x -= 2;
            break;
        case Fexpr::FREVIEWFIELD:
            $x = $format_detail->parse($this->x);
            if (!$x) {
                return;
            }
            break;
        case Fexpr::FSUBFIELD:
            $pv = $format_detail->parse_json_user($formula->placeholder_prow(), $this->x, $formula->user);
            if (!$pv || $pv->has_error()) {
                return;
            }
            $x = $pv->value;
            break;
        case Fexpr::FROUND:
            $x = $conf->round_number($this->x);
            if ($x === null) {
                return;
            }
            break;
        case Fexpr::FREVTYPE:
            if (!$this->_check_revtype()) {
                return;
            }
            $x = $this->x;
        default:
            return;
        }
        $this->x = $x;
        $this->set_format($format, $format_detail);
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
}

class Ternary_Fexpr extends Fexpr {
    function __construct($e0, $e1, $e2) {
        parent::__construct("?:", [$e0, $e1, $e2]);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula);
    }
    function inferred_format() {
        return [$this->args[1], $this->args[2]];
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        $tt = $state->_addltemp($this->args[1]->compile($state));
        $tf = $state->_addltemp($this->args[2]->compile($state));
        return "({$t} ? {$tt} : {$tf})";
    }
    function matches_at_most_once() {
        return $this->args[0]->matches_at_most_once()
            && $this->args[2]->format() === Fexpr::FNULL;
    }
}

class Equality_Fexpr extends Fexpr {
    function __construct($op, $e0, $e1) {
        assert($op === "==" || $op === "!=");
        parent::__construct($op, [$e0, $e1]);
        $this->set_format(Fexpr::FBOOL);
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return $this->check_null_args("{$t1} {$this->op} {$t2}", $t1, $t2);
    }
}

class Inequality_Fexpr extends Fexpr {
    function __construct($op, $e0, $e1) {
        assert(in_array($op, ["<", ">", "<=", ">="], true));
        parent::__construct($op, [$e0, $e1]);
        $this->set_format(Fexpr::FBOOL);
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        $op = $this->args[0]->compiled_relation($this->op, $this->args[1]);
        return $this->check_null_args("{$t1} {$op} {$t2}", $t1, $t2);
    }
}

class And_Fexpr extends Fexpr {
    function __construct($e0, $e1) {
        parent::__construct("&&", [$e0, $e1]);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "({$t1} ? {$t2} : {$t1})";
    }
    function matches_at_most_once() {
        return $this->args[0]->matches_at_most_once() || $this->args[1]->matches_at_most_once();
    }
}

class Or_Fexpr extends Fexpr {
    function __construct($e0, $e1) {
        parent::__construct("||", [$e0, $e1]);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return "({$t1} ? : {$t2})";
    }
}

class Not_Fexpr extends Fexpr {
    function __construct(Fexpr $e) {
        parent::__construct("!", [$e]);
        $this->set_format(Fexpr::FBOOL, true);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "!{$t}";
    }
}

class Unary_Fexpr extends Fexpr {
    function __construct($op, Fexpr $e) {
        assert($op === "+" || $op === "-");
        parent::__construct($op, [$e]);
        $this->set_format(Fexpr::FNUMERIC, true);
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "{$this->op}{$t}";
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
    function inferred_format() {
        $f0 = $this->args[0]->format();
        $f1 = $this->args[1]->format();
        $d0 = $f0 >= self::FDATE && $f0 <= self::FTIMEDELTA;
        $delta0 = $d0 && $f0 >= self::FDATEDELTA;
        $d1 = $f1 >= self::FDATE && $f1 <= self::FTIMEDELTA;
        $delta1 = $d1 && $f1 >= self::FDATEDELTA;
        if ((!$d0 && !$d1)
            || (!$d0 && $f0)
            || (!$d1 && $f1)) {
            return $this->args;
        } else if ($this->op === "-" && $d0 && $d1 && !$delta0 && !$delta1) {
            $fx = Fexpr::FDATEDELTA;
        } else if ($d0 && (!$d1 || $delta1)) {
            $fx = $delta0 ? Fexpr::FDATEDELTA : Fexpr::FDATE;
        } else if ($d1 && (!$d0 || $delta0)) {
            $fx = $delta1 ? Fexpr::FDATEDELTA : Fexpr::FDATE;
        } else {
            return $this->args;
        }
        if ($f0 === Fexpr::FTIME || $f0 === Fexpr::FTIMEDELTA
            || $f1 === Fexpr::FTIME || $f1 === Fexpr::FTIMEDELTA) {
            $fx = $fx === Fexpr::FDATEDELTA ? Fexpr::FTIMEDELTA : Fexpr::FTIME;
        }
        $this->set_format($fx);
        return null;
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        return $this->check_null_args("{$t1} {$this->op} {$t2}", $t1, $t2);
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
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        $t2 = $state->_addltemp($this->args[1]->compile($state));
        if ($this->op === "*") {
            return $this->check_null_args("{$t1} * {$t2}", $t1, $t2);
        } else {
            return "({$t1} !== null && {$t2} ? {$t1} {$this->op} {$t2} : null)";
        }
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
        return $this->check_null_args("{$t1} {$this->op} {$t2}", $t1, $t2);
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
        return $this->check_null_args("{$t1} {$this->op} {$t2}", $t1, $t2);
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
        return $this->check_null_args("pow({$t1}, {$t2})", $t1, $t2);
    }
}

class In_Fexpr extends Fexpr {
    private $values;
    function __construct(Fexpr $e, array $values) {
        parent::__construct("in", [$e]);
        $this->values = $values;
        $this->set_format(Fexpr::FBOOL);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "(array_search($t, [" . join(", ", $this->values) . "]) !== false)";
    }
}

class LeastGreatest_Fexpr extends Fexpr {
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
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        if (count($this->args) === 0) {
            return "null";
        }
        $t = $state->_addltemp($this->args[0]->compile($state));
        $cmp = $this->compiled_relation($this->op === "greatest" ? ">" : "<");
        for ($i = 0; $i < count($this->args); ++$i) {
            $t2 = $state->_addltemp($this->args[$i]->compile($state));
            $state->lstmt[] = "if ({$t} !== null && ({$t2} === null || {$t2} {$cmp} {$t}))\n  {$t} = {$t2};";
        }
        return $t;
    }
}

class Coalesce_Fexpr extends Fexpr {
    function __construct(FormulaCall $ff) {
        parent::__construct("coalesce", $ff->args);
    }
    function typecheck(Formula $formula) {
        $this->typecheck_resolve_neighbors($formula);
        return $this->typecheck_arguments($formula);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        if (count($this->args) === 0) {
            return "null";
        }
        $t = $state->_addltemp($this->args[0]->compile($state));
        for ($i = 1; $i < count($this->args); ++$i) {
            $state->lstmt[] = "{$t} = {$t} ?? (" . $this->args[$i]->compile($state) . ");";
        }
        return $t;
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
            return $this->check_null_args("log({$t1}, {$t2})", $t1, $t2);
        } else if ($this->op === "log10") {
            return $this->check_null_args("log10({$t1})", $t1);
        } else if ($this->op === "log2" || $this->op === "lg") {
            return $this->check_null_args("log({$t1}, 2.0)", $t1);
        } else if ($this->op === "log" || $this->op === "ln") {
            return $this->check_null_args("log({$t1})", $t1);
        } else {
            return $this->check_null_args("{$this->op}({$t1})", $t1);
        }
    }
}

class IsNull_Fexpr extends Fexpr {
    function __construct($ff) {
        parent::__construct($ff);
        $this->set_format(self::FBOOL, true);
    }
    function compile(FormulaCompiler $state) {
        $t1 = $state->_addltemp($this->args[0]->compile($state));
        return "({$t1} !== null)";
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
            return $this->check_null_args("{$op}({$t1})", $t1);
        } else {
            $t2 = $state->_addltemp($this->args[1]->compile($state));
            return "({$t1} !== null && {$t2} ? {$op}({$t1} / {$t2}) * {$t2} : null)";
        }
    }
}

abstract class Aggregate_Fexpr extends Fexpr {
    /** @var int */
    public $index_type;
    /** @var bool */
    public $explicit_index;

    function __construct($fn, array $values, ?int $index_type) {
        parent::__construct($fn, $values);
        $this->index_type = $index_type ?? 0;
        $this->explicit_index = $this->index_type !== 0;
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!str_starts_with($arg, ".")) {
            return false;
        }
        if (($ff->index_type ?? 0) !== 0) {
            $ff->parser->lerror($ff->pos2 - strlen($arg), $ff->pos2, "<0>Collection already specified");
            return true;
        }
        if ($arg === ".pc") {
            $it = Fexpr::IDX_PC;
        } else if ($arg === ".re" || $arg === ".rev" || $arg === ".review") {
            $it = Fexpr::IDX_REVIEW;
        } else if ($arg === ".cre" || $arg === ".creview") {
            $it = Fexpr::IDX_CREVIEW;
        } else if ($arg === ".anyre" || $arg === ".anyreview") {
            $it = Fexpr::IDX_ANYREVIEW;
        } else {
            $ff->parser->lerror($ff->pos2 - strlen($arg), $ff->pos2, "<0>Collection ‘{$arg}’ not found");
            return true;
        }
        $ff->index_type = $it;
        return true;
    }
    function typecheck_index(Formula $formula) {
        if ($this->index_type !== 0) {
            return true;
        }
        $lt = parent::inferred_index();
        if ($lt === 0 || ($lt & ($lt - 1)) === 0) {
            $this->index_type = $lt;
            return true;
        }
        $formula->fexpr_lerror($this, "<0>Ambiguous collection, specify ‘{$this->op}.pc’ or ‘{$this->op}.re’");
        return false;
    }
    function inferred_index() {
        return 0;
    }
}

class My_Fexpr extends Aggregate_Fexpr {
    function __construct(Fexpr $e) {
        parent::__construct("my", [$e], Fexpr::IDX_MY);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new My_Fexpr($ff->args[0]) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        return $state->_compile_my($this->args[0]);
    }
}

class All_Fexpr extends Aggregate_Fexpr {
    function __construct($fn, Fexpr $e, ?int $index_type) {
        parent::__construct($fn, [$e], $index_type);
        $this->set_format(Fexpr::FBOOL);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new All_Fexpr($ff->name, $ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula)
            && $this->typecheck_index($formula);
    }
    function compile(FormulaCompiler $state) {
        $x = $this->op === "all" ? "(~r~ !== null ? ~l~ && ~r~ : ~l~)" : "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)";
        return self::cast_bool($state->_compile_loop("null", $x, $this));
    }
}

class Some_Fexpr extends Aggregate_Fexpr {
    function __construct(Fexpr $e, ?int $index_type = null) {
        parent::__construct("some", [$e], $index_type);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new Some_Fexpr($ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        return $state->_compile_loop("null", "~r~ = ~l~;
if (~r~ !== null && ~r~ !== false) {
  break;
}", $this);
    }
}

class Variance_Fexpr extends Aggregate_Fexpr {
    function __construct($fn, Fexpr $e, ?int $index_type) {
        parent::__construct($fn, [$e], $index_type);
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!$ff->modifier
            && strpos($ff->text, "_") === false
            && in_array($arg, [".p", ".pop", ".s", ".samp"], true)) {
            $ff->modifier = true;
            if ($arg === ".p" || $arg === ".pop") {
                $ff->name .= "_pop";
            }
            return true;
        } else {
            return parent::parse_modifier($ff, $arg);
        }
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new Variance_Fexpr($ff->name, $ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_compile_loop("[0.0, 0.0, 0]", "(~l~ !== null ? [~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1] : ~r~)", $this);
        if ($this->op === "variance") {
            return "({$t}[2] > 1 ? {$t}[0] / ({$t}[2] - 1) - ({$t}[1] * {$t}[1]) / ({$t}[2] * ({$t}[2] - 1)) : ({$t}[2] ? 0.0 : null))";
        } else if ($this->op === "variance_pop") {
            return "({$t}[2] ? {$t}[0] / {$t}[2] - ({$t}[1] * {$t}[1]) / ({$t}[2] * {$t}[2]) : null)";
        } else if ($this->op === "stddev") {
            return "({$t}[2] > 1 ? sqrt({$t}[0] / ({$t}[2] - 1) - ({$t}[1] * {$t}[1]) / ({$t}[2] * ({$t}[2] - 1))) : ({$t}[2] ? 0.0 : null))";
        }
        return "({$t}[2] ? sqrt({$t}[0] / {$t}[2] - ({$t}[1] * {$t}[1]) / ({$t}[2] * {$t}[2])) : null)";
    }
}

class Quantile_Fexpr extends Aggregate_Fexpr {
    /** @var int */
    private $varg = 0;
    function __construct($fn, array $values, ?int $index_type) {
        parent::__construct($fn, $values, $index_type);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs($ff->name === "median" ? 1 : 2)
            ? new Quantile_Fexpr($ff->name, $ff->args, $ff->index_type)
            : null;
    }
    function typecheck(Formula $formula) {
        if ($this->op !== "median"
            && $this->args[0]->inferred_index() === 0
            && $this->args[1]->inferred_index() !== 0) {
            $this->varg = 1;
        }
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return [$this->args[$this->varg]];
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
        if (($e = $ix - $i) && $a[$i] !== $v) {
            $v += $e * ($a[$i] - $v);
        }
        return $v;
    }
    function compile(FormulaCompiler $state) {
        if ($this->op === "median") {
            $q = "0.5";
        } else {
            $q = $state->_addltemp($this->args[1 - $this->varg]->compile($state));
            if ($this->compiled_relation("<") === ">") {
                $q = "1 - {$q}";
            }
        }
        $carg = $this->varg ? "~l1~" : "~l~";
        $t = $state->_compile_loop("[]", "if ({$carg} !== null)\n  array_push(~r~, +{$carg});", $this);
        return "Quantile_Fexpr::quantile({$t}, {$q})";
    }
}

class Extremum_Fexpr extends Aggregate_Fexpr {
    function __construct($fn, Fexpr $e, ?int $index_type) {
        parent::__construct($fn, [$e], $index_type);
    }
    static function make(FormulaCall $ff) {
        if (count($ff->args) > 1 && !$ff->modifier) {
            return new LeastGreatest_Fexpr($ff);
        }
        return $ff->check_nargs(1) ? new Extremum_Fexpr($ff->name, $ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return $this->args;
    }
    function compile(FormulaCompiler $state) {
        $cmp = $this->compiled_relation($this->op === "min" ? "<" : ">");
        $cmpx = $this->args[0]->nonnull_format() ? "~l~ {$cmp} ~r~" : "(~l~ !== null && ~l~ {$cmp} ~r~)";
        return $state->_compile_loop("null", "(~r~ === null || {$cmpx} ? ~l~ : ~r~)", $this);
    }
}

class Count_Fexpr extends Aggregate_Fexpr {
    function __construct(Fexpr $e, ?int $index_type = null) {
        parent::__construct("count", [$e], $index_type);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new Count_Fexpr($ff->args[0], $ff->index_type) : null;
    }
    /** @return true
     * @suppress PhanUndeclaredMethod */
    static function check_private_tag_index(Aggregate_Fexpr $fexpr) {
        if ($fexpr->index_type === self::IDX_PC
            && $fexpr->args[0] instanceof Tag_Fexpr
            && str_starts_with($fexpr->args[0]->tag(), "_~")) {
            $fexpr->index_type = self::IDX_PC_SET_PRIVATE_TAG;
        }
        return true;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula)
            && $this->typecheck_index($formula)
            && self::check_private_tag_index($this);
    }
    function compile(FormulaCompiler $state) {
        return $state->_compile_loop("0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)", $this);
    }
}

class Sum_Fexpr extends Aggregate_Fexpr {
    function __construct(Fexpr $e, ?int $index_type = null) {
        parent::__construct("sum", [$e], $index_type);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new Sum_Fexpr($ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula)
            && Count_Fexpr::check_private_tag_index($this);
    }
    function compile(FormulaCompiler $state) {
        if ($this->args[0]->nonnull_format()) {
            return $state->_compile_loop("null", "(~r~ !== null ? ~r~ + ~l~ : +~l~)", $this);
        }
        return $state->_compile_loop("null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : +~l~) : ~r~)", $this);
    }
}

class ArgExtremum_Fexpr extends Aggregate_Fexpr {
    function __construct($fn, array $values, ?int $index_type) {
        if (str_starts_with($fn, "at")) { // atminof, atmaxof
            parent::__construct("arg" . substr($fn, 2, 3), [$values[1], $values[0]], $index_type);
        } else {
            parent::__construct($fn, $values, $index_type);
        }
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(2) ? new ArgExtremum_Fexpr($ff->name, $ff->args, $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula)
            && $this->args[1]->typecheck_math_format($formula)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return [$this->args[0]];
    }
    function compile(FormulaCompiler $state) {
        $cmp = $this->args[1]->compiled_relation($this->op === "argmin" ? "<" : ">");
        $t = $state->_compile_loop("[null, [null]]",
"if (~l1~ !== null && (~r~[0] === null || ~l1~ {$cmp} ~r~[0])) {
  ~r~[0] = ~l1~;
  ~r~[1] = [~l~];
} else if (~l1~ !== null && ~l1~ == ~r~[0]) {
  ~r~[1][] = ~l~;
}", $this);
        return "({$t}[1][count({$t}[1]) > 1 ? mt_rand(0, count({$t}[1]) - 1) : 0])";
    }
}

class Mean_Fexpr extends Aggregate_Fexpr {
    function __construct(Fexpr $e, ?int $index_type = null) {
        parent::__construct("avg", [$e], $index_type);
    }
    static function make(FormulaCall $ff) {
        return $ff->check_nargs(1) ? new Mean_Fexpr($ff->args[0], $ff->index_type) : null;
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return self::inferred_numeric_format($this->args[0]);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_compile_loop("[0.0, 0]", "(~l~ !== null ? [~r~[0] + ~l~, ~r~[1] + 1] : ~r~)", $this);
        return "({$t}[1] ? {$t}[0] / {$t}[1] : null)";
    }
}

class Wavg_Fexpr extends Aggregate_Fexpr {
    function __construct(array $values, ?int $index_type = null) {
        parent::__construct("wavg", $values, $index_type);
    }
    static function make(FormulaCall $ff) {
        if (count($ff->args) === 1) {
            return Mean_Fexpr::make($ff);
        } else {
            return $ff->check_nargs(2) ? new Wavg_Fexpr($ff->args, $ff->index_type) : null;
        }
    }
    function typecheck(Formula $formula) {
        return $this->typecheck_arguments($formula, true)
            && $this->typecheck_index($formula);
    }
    function inferred_format() {
        return self::inferred_numeric_format($this->args[0]);
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_compile_loop("[0, 0]", "(~l~ !== null && ~l1~ !== null ? [~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~] : ~r~)", $this);
        return "({$t}[1] ? {$t}[0] / {$t}[1] : null)";
    }
}

class Pid_Fexpr extends Fexpr {
    function compile(FormulaCompiler $state) {
        return $state->_prow() . '->paperId';
    }
}

class Score_Fexpr extends Fexpr {
    function __construct(ReviewField $field) {
        parent::__construct("rf");
        $this->set_format(Fexpr::FREVIEWFIELD, $field);
    }
    function inferred_index() {
        return self::IDX_REVIEW;
    }
    function compile(FormulaCompiler $state) {
        $field = $this->format_detail();
        '@phan-var-force ReviewField $field';
        if ($field->view_score <= $state->user->permissive_view_score_bound()) {
            return "null";
        }
        $state->_ensure_rrow_score($field);
        $rrow = $state->_rrow();
        $rrow_vsb = $state->_rrow_view_score_bound(true);
        if ($field->always_exists()) {
            $fval = "{$rrow}->fields[{$field->order}]";
        } else {
            $fval = "{$rrow}->fidval(" . json_encode($field->short_id) . ")";
        }
        if ($field instanceof Checkbox_ReviewField) {
            $fval = "nbool({$fval})";
        }
        return "({$field->view_score} > {$rrow_vsb} ? {$fval} : null)";
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $field = $this->format_detail();
        '@phan-var-force ReviewField $field';
        return ["op" => "rf", "field" => $field->search_keyword()];
    }
}

class Let_Fexpr extends Fexpr {
    /** @var VarDef_Fexpr */
    private $vardef;
    function __construct(VarDef_Fexpr $vardef, Fexpr $val, Fexpr $body) {
        parent::__construct("let", [$val, $body]);
        $this->vardef = $vardef;
    }
    function typecheck(Formula $formula) {
        $val = $this->args[0];
        $body = $this->args[1];
        if (($ok0 = $val->typecheck($formula))) {
            $this->vardef->set_format($val->format(), $val->format_detail());
            $this->vardef->_index_type = $val->inferred_index();
        } else {
            $this->vardef->set_format(Fexpr::FERROR);
            $this->vardef->_index_type = 0;
        }
        $ok1 = $body->typecheck($formula);
        if ($ok0 && $ok1) {
            $this->set_format($body->format(), $body->format_detail());
        } else {
            $this->set_format(Fexpr::FERROR);
        }
        return $ok0 && $ok1;
    }
    function compile(FormulaCompiler $state) {
        $this->vardef->ltemp = $state->_addltemp($this->args[0]->compile($state));
        return $this->args[1]->compile($state);
    }
    function jsonSerialize() {
        return ["op" => "let", "name" => $this->vardef->name(),
                "value" => $this->args[0], "body" => $this->args[1]];
    }
}

class VarDef_Fexpr extends Fexpr {
    /** @var string */
    private $name;
    /** @var string */
    public $ltemp;
    /** @var ?int */
    public $_index_type;
    /** @param string $name */
    function __construct($name) {
        parent::__construct("vardef");
        $this->name = $name;
    }
    /** @return string */
    function name() {
        return $this->name;
    }
    /** @return int */
    function inferred_index() {
        assert($this->_index_type !== null);
        return $this->_index_type;
    }
    function compile(FormulaCompiler $state) {
        assert(false);
    }
    function jsonSerialize() {
        return ["op" => "vardef", "name" => $this->name];
    }
}

class VarUse_Fexpr extends Fexpr {
    /** @var VarDef_Fexpr */
    private $vardef;
    function __construct(VarDef_Fexpr $vardef) {
        parent::__construct("varuse");
        $this->vardef = $vardef;
    }
    function typecheck(Formula $formula) {
        $this->set_format($this->vardef->format(), $this->vardef->format_detail());
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
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Formula
     * @readonly */
    public $formula;
    /** @var Tagger
     * @readonly */
    public $tagger;
    /** @var array<string,string> */
    private $gvar;
    /** @var list<string> */
    private $g0stmt;
    /** @var list<string> */
    public $gstmt;
    /** @var list<string> */
    public $lstmt;
    /** @var int */
    public $index_type;
    /** @var bool */
    public $indexed;
    /** @var int */
    private $_lprefix;
    /** @var int */
    private $_maxlprefix;
    /** @var int */
    private $_lflags;
    /** @var int */
    public $indent = 2;
    public $queryOptions = [];
    private $_stack;
    /** @var ?FormulaCompiler */
    public $term_compiler;
    /** @var ?list<string> */
    public $term_list;
    /** @var bool */
    public $term_error = false;

    const LFLAG_RROW = 1;
    const LFLAG_RROW_VSB = 2;
    const LFLAG_RROW_VSBS = 4;
    const LFLAG_ANYCID = 8;
    const LFLAG_CID = 16;
    const LFLAG_PREFERENCES = 32;

    function __construct(Formula $formula) {
        $this->conf = $formula->conf;
        $this->user = $formula->user;
        $this->formula = $formula;
        $this->tagger = new Tagger($formula->user);
        $this->clear();
    }

    /** @return FormulaCompiler */
    static function make_combiner(Formula $formula) {
        $fc = new FormulaCompiler($formula);
        $fc->term_compiler = new FormulaCompiler($formula);
        $fc->term_compiler->term_list = [];
        return $fc;
    }

    function clear() {
        $this->gvar = $this->g0stmt = $this->gstmt = $this->lstmt = [];
        $this->index_type = Fexpr::IDX_NONE;
        $this->indexed = false;
        $this->_lprefix = 0;
        $this->_maxlprefix = 0;
        $this->_lflags = 0;
        $this->_stack = [];
        $this->term_compiler = $this->term_list = null;
        $this->term_error = false;
    }

    /** @param string $gvar
     * @return bool */
    function check_gvar($gvar) {
        if ($this->gvar[$gvar] ?? false) {
            return false;
        }
        $this->gvar[$gvar] = $gvar;
        return true;
    }

    /** @param string $name
     * @param string $expr
     * @return string */
    function define_gvar($name, $expr) {
        if (preg_match('/\A\$?(\d.*|.*[^A-Ya-z0-9_].*)\z/', $name, $m)) {
            $name = '$' . preg_replace_callback('/\A\d|[^A-Ya-z0-9_]/', function ($m) { return "Z" . dechex(ord($m[0])); }, $m[1]);
        } else {
            $name = $name[0] === "$" ? $name : '$' . $name;
        }
        if (!isset($this->gvar[$name])) {
            $this->gstmt[] = "$name = $expr;";
            $this->gvar[$name] = $name;
        }
        return $name;
    }

    /** @return string */
    function _add_pc() {
        if ($this->check_gvar('$pc')) {
            $this->gstmt[] = "\$pc = \$formula->conf->pc_members();";
        }
        return '$pc';
    }

    /** @return string */
    function _add_vreviews() {
        if ($this->check_gvar('$vreviews')) {
            $this->queryOptions["reviewSignatures"] = true;
            $this->gstmt[] = "\$vreviews = " . $this->_prow() . "->viewable_reviews_as_display(\$user);";
        }
        return '$vreviews';
    }

    /** @return string */
    function _add_preferences() {
        $this->queryOptions["allReviewerPreference"] = true;
        if ($this->_lprefix) {
            $this->_lflags |= self::LFLAG_PREFERENCES;
            return "\$preferences_{$this->_lprefix}";
        }
        if ($this->check_gvar('$preferences')) {
            $prow = $this->_prow();
            $this->gstmt[] = "\$preferences = \$user->can_view_preference({$prow}) ? {$prow}->preferences() : [];";
        }
        return '$preferences';
    }

    /** @return string */
    function _add_conflict_types() {
        if ($this->check_gvar('$conflict_types')) {
            $this->queryOptions["allConflictType"] = true;
            $prow = $this->_prow();
            $this->gstmt[] = "\$conflict_types = \$user->can_view_conflicts({$prow}) ? {$prow}->conflict_types() : [];";
        }
        return '$conflict_types';
    }

    /** @return string */
    function _add_tags() {
        if ($this->check_gvar('$tags')) {
            $this->queryOptions["tags"] = true;
            $this->gstmt[] = "\$tags = " . $this->_prow() . "->searchable_tags(\$user);";
        }
        return '$tags';
    }

    /** @return string */
    function _add_pc_set_private_tag() {
        if ($this->check_gvar('$tag_pc')) {
            $tags = $this->_add_tags();
            $pc = $this->_add_pc();
            $this->gstmt[] = "\$tag_pc = [];";
            $this->gstmt[] = "if ({$tags} !== \"\") {";
            $this->gstmt[] = "  preg_match_all(\"/ \d+/\", {$tags}, \$m);";
            $this->gstmt[] = "  foreach (\$m[0] as \$c) {";
            $this->gstmt[] = "    if ((\$p = {$pc}[(int) \$c] ?? null))";
            $this->gstmt[] = "      \$tag_pc[\$p->contactId] = \$p;";
            $this->gstmt[] = "  }";
            $this->gstmt[] = "}";
        }
        return '$tag_pc';
    }

    /** @return string */
    function _add_option_value(PaperOption $o) {
        $n = '$ov_' . ($o->id < 0 ? "m" . -$o->id : $o->id);
        if ($this->check_gvar($n)) {
            $this->queryOptions["options"] = true;
            $this->gstmt[] = "$n = " . $this->_prow() . "->option({$o->id});";
        }
        return $n;
    }

    /** @return string */
    function _add_now() {
        return 'Conf::$now';
    }

    /** @return string */
    function _add_decision() {
        if ($this->check_gvar('$decision')) {
            $prow = $this->_prow();
            $this->gstmt[] = "\$decision = \$user->can_view_decision({$prow}) ? {$prow}->outcome : 0;";
        }
        return '$decision';
    }

    /** @return string */
    function _add_primary_document() {
        if ($this->check_gvar('$primary_document')) {
            $prow = $this->_prow();
            $decision = $this->_add_decision();
            $this->gstmt[] = "\$primary_document = \$user->can_view_pdf({$prow}) ? {$prow}->document({$prow}->finalPaperStorageId > 0 ? " . DTYPE_FINAL . " : " . DTYPE_SUBMISSION . ") : null;";
        }
        return '$primary_document';
    }

    /** @return string */
    function loop_cid($aggregate = false) {
        $this->indexed = true;
        if ($this->index_type === Fexpr::IDX_NONE) {
            return '$rrow_cid';
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return (string) $this->user->contactId;
        }
        $this->_lflags |= self::LFLAG_ANYCID | ($aggregate ? 0 : self::LFLAG_CID);
        return '~i~';
    }

    /** @return string */
    function review_identity_loop_cid() {
        $this->indexed = true;
        if ($this->index_type === Fexpr::IDX_NONE) {
            $rrow = $this->_rrow();
            return $this->define_gvar("rrow_vcid", "({$rrow} && \$user->can_view_review_identity(\$prow, {$rrow}) ? \$rrow_cid : null)");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return (string) $this->user->contactId;
        }
        $this->_lflags |= self::LFLAG_ANYCID;
        return '~i~';
    }

    /** @return string */
    function _prow() {
        if ($this->term_compiler) {
            $this->term_error = true;
        }
        return '$prow';
    }

    /** @return string */
    function _rrow() {
        $this->indexed = true;
        if ($this->index_type === Fexpr::IDX_NONE) {
            return $this->define_gvar("rrow", $this->_prow() . "->review_by_user(\$rrow_cid)");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return $this->define_gvar("myrrow", $this->_prow() . "->review_by_user(" . $this->user->contactId . ")");
        }
        $this->_lflags |= self::LFLAG_RROW;
        return "\$rrow_{$this->_lprefix}";
    }

    /** @param bool $submitted
     * @return string */
    function _rrow_view_score_bound($submitted) {
        $rrow = $this->_rrow();
        $sfx = $clause = "";
        if (($submitted || $this->index_type === Fexpr::IDX_CREVIEW)
            && $this->index_type !== Fexpr::IDX_ANYREVIEW) {
            $sfx = "s";
            $clause = $submitted ? " && {$rrow}->reviewSubmitted" : "";
        }
        if ($this->index_type === Fexpr::IDX_NONE) {
            return $this->define_gvar("rrow_vsb{$sfx}", "({$rrow}{$clause} ? \$user->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return $this->define_gvar("myrrow_vsb{$sfx}", "({$rrow}{$clause} ? \$user->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
        }
        $this->_lflags |= $submitted ? self::LFLAG_RROW_VSBS : self::LFLAG_RROW_VSB;
        return "\$rrow_vsb{$sfx}_{$this->_lprefix}";
    }

    /** @param ReviewField $f */
    function _ensure_rrow_score($f) {
        if (!in_array($f, $this->queryOptions["scores"] ?? [], true)) {
            $this->queryOptions["scores"][] = $f;
        }
        if ($this->check_gvar('$ensure_score_' . $f->short_id)) {
            $this->g0stmt[] = $this->_prow() . '->ensure_review_field_order(' . $f->order . ');';
        }
    }

    function _ensure_review_word_counts() {
        $this->queryOptions["reviewWordCounts"] = true;
        if ($this->check_gvar('$ensure_reviewWordCounts')) {
            $this->g0stmt[] = $this->_prow() . '->ensure_review_word_counts();';
        }
    }

    /** @return int */
    private function _push() {
        $this->_stack[] = [$this->_lprefix, $this->lstmt, $this->index_type, $this->indexed, $this->_lflags];
        $this->_lprefix = ++$this->_maxlprefix;
        $this->lstmt = [];
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

    /** @param string $expr
     * @param bool $always_var
     * @return string */
    function _addltemp($expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr)) {
            return $expr;
        }
        $tname = "\$t" . $this->_lprefix . "_" . count($this->lstmt);
        $this->lstmt[] = "{$tname} = {$expr};";
        return $tname;
    }

    /** @param bool $isblock
     * @param list<string> $prefix
     * @return string */
    private function _join_lstmt($isblock, $prefix = []) {
        $indent = "\n" . str_repeat(" ", $this->indent);
        $body = join($indent, array_merge($prefix, $this->lstmt));
        if ($isblock) {
            return "{{$indent}{$body}" . substr($indent, 0, -2) . "}";
        }
        return $body;
    }

    /** @param int $index_types
     * @return string */
    function index_range($index_types) {
        if ($index_types === Fexpr::IDX_PC_SET_PRIVATE_TAG) {
            return $this->_add_pc_set_private_tag();
        } else if (($index_types & Fexpr::IDX_REVIEW_MASK) === $index_types) {
            return $this->_add_vreviews();
        } else if ($index_types === Fexpr::IDX_PC) {
            return $this->_add_pc();
        }
        assert($index_types === 0 || $index_types === Fexpr::IDX_MY || $index_types === Fexpr::IDX_X);
        return "[0]";
    }

    function _compile_loop($initial_value, $combiner, Aggregate_Fexpr $e) {
        $t_result = $this->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $this->_push();
        $this->index_type = $e->index_type;
        $loopstmt = [];

        preg_match_all('/~l(\d*)~/', $combiner, $m);
        foreach (array_unique($m[1]) as $i) {
            if ($this->term_compiler !== null) {
                $n = count($this->term_compiler->term_list);
                $tx = $this->term_compiler->_addltemp($e->args[(int) $i]->compile($this->term_compiler));
                $this->term_compiler->term_list[] = $tx;
                $t = "\$v{$p}[{$n}]";
            } else {
                $t = $this->_addltemp($e->args[(int) $i]->compile($this));
            }
            $combiner = str_replace("~l{$i}~", $t, $combiner);
        }

        if (preg_match('/[;}]\s*\z/', $combiner)) {
            $this->lstmt[] = str_replace("\n", "\n" . str_repeat(" ", $this->indent), $combiner);
        } else {
            $this->lstmt[] = "{$t_result} = {$combiner};";
        }

        $lprefix = [];
        if (($this->index_type & Fexpr::IDX_REVIEW) !== 0) {
            $lprefix[] = "\$rrow_{$p} = \$v{$p};";
            if ($this->index_type === Fexpr::IDX_CREVIEW) {
                $lprefix[] = "if (!\$rrow_{$p}->reviewSubmitted) { continue; }";
            }
            if (($this->_lflags & self::LFLAG_ANYCID) !== 0) {
                $lprefix[] = "\$i{$p} = \$user->can_view_review_identity(\$prow, \$rrow_{$p}) ? \$rrow_{$p}->contactId : null;";
            }
        } else {
            if (($this->_lflags & self::LFLAG_RROW) !== 0) {
                $lprefix[] = "\$rrow_{$p} = \$prow->viewable_review_by_user(\$i{$p}, \$user);";
            }
        }
        if (($this->_lflags & (self::LFLAG_RROW_VSB | self::LFLAG_RROW_VSBS)) !== 0) {
            $lprefix[] = "\$rrow_vsb_{$p} = \$rrow_{$p} ? \$user->view_score_bound(\$prow, \$rrow_{$p}) : " . VIEWSCORE_EMPTYBOUND . ";";
        }
        if (($this->_lflags & self::LFLAG_RROW_VSBS) !== 0) {
            $lprefix[] = "\$rrow_vsbs_{$p} = \$rrow_{$p} && \$rrow_{$p}->reviewSubmitted ? \$rrow_vsb_{$p} : " . VIEWSCORE_EMPTYBOUND . ";";
        }
        if (($this->_lflags & self::LFLAG_PREFERENCES) !== 0) {
            $lprefix[] = "\$preferences_{$p} = \$prow->viewable_preferences(\$user"
                . ($this->_lflags & self::LFLAG_CID ? "" : ", true")
                . ");";
        }

        if ($this->term_compiler !== null) {
            $loop = "foreach (\$extractor_results as \$v{$p}) " . $this->_join_lstmt(true, $lprefix);
        } else {
            $g = $this->index_range($this->index_type);
            if (($this->index_type & Fexpr::IDX_REVIEW) !== 0) {
                $loop = "foreach ({$g} as \$v{$p}) ";
            } else {
                $loop = "foreach ({$g} as \$i{$p} => \$v{$p}) ";
            }
            $loop .= str_replace("~i~", "\$i{$p}", $this->_join_lstmt(true, $lprefix));
        }
        $loopstmt[] = $loop;

        $this->_pop();
        $this->lstmt = array_merge($this->lstmt, $loopstmt);
        return $t_result;
    }

    /** @return string */
    function _compile_my(Fexpr $e) {
        $this->_push();
        $this->index_type = Fexpr::IDX_MY;
        $t = $this->_addltemp($e->compile($this));
        $loop = $this->_join_lstmt(false);
        $this->_pop();
        $this->lstmt[] = $loop;
        return $t;
    }

    /** @return string */
    function statement_text() {
        return join("\n  ", $this->g0stmt)
            . (empty($this->g0stmt) || empty($this->gstmt) ? "" : "\n  ")
            . join("\n  ", $this->gstmt)
            . (empty($this->gstmt) || empty($this->lstmt) ? "" : "\n  ")
            . join("\n  ", $this->lstmt) . "\n";
    }
}

class Formula implements JsonSerializable {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?Contact
     * @readonly */
    public $user;
    /** @var string
     * @readonly */
    public $expression;

    /** @var Fexpr */
    private $_fexpr;
    /** @var list<MessageItem> */
    public $lerrors = [];
    /** @var bool */
    private $_indexed = false;
    /** @var int */
    private $_index_type = 0;
    /** @var int */
    private $_format = Fexpr::FUNKNOWN;
    private $_format_detail;
    /** @var ?ValueFormat */
    private $_value_format;

    /** @var ?callable(PaperInfo,?int,Contact,Formula):mixed */
    private $_f_main;
    /** @var ?callable(PaperInfo,?int,Contact,Formula):mixed */
    private $_f_sortable;
    /** @var ?callable(PaperInfo,?int,Contact,Formula):mixed */
    private $_f_json;
    /** @var ?callable(PaperInfo,Contact,Formula):list */
    private $_f_indexer;
    /** @var ?callable(PaperInfo,?int,Contact,Formula):mixed */
    private $_f_extractor;
    /** @var ?callable(list<mixed>):mixed */
    private $_f_combiner;
    /** @var ?bool */
    private $_supports_combiner;

    /** @var ?PaperInfo */
    private $_placeholder_prow;
    /** @var list<mixed> */
    public $info = [];

    const BINARY_OPERATOR_REGEX = '/\G(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|\?\?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠|(?:and|or|in)(?=[\s(]|\z))/';

    /** @var 0|1|2 */
    const DEBUG = 0;

    static public $opprec = [
        "**" => 14,
        "u+" => 13, "u-" => 13, "u!" => 13,
        "*" => 12, "/" => 12, "%" => 12,
        "+" => 11, "-" => 11,
        "<<" => 10, ">>" => 10,
        "<" => 9, ">" => 9, "<=" => 9, ">=" => 9, "≤" => 9, "≥" => 9,
        "=" => 8, "==" => 8, "!=" => 8, "≠" => 8,
        "&" => 7,
        "^" => 6,
        "|" => 5,
        ":" => 4,
        "&&" => 3, "and" => 3,
        "||" => 2, "or" => 2,
        "??" => 1,
        "?:" => 0,
        "in" => -1
    ];


    /** @param string $t
     * @return int */
    static function span_maximal_formula($t) {
        return FormulaParser::span_parens_until($t, 0, ",;)]}");
    }


    const ALLOW_INDEXED = 1;

    /** @param string $expr
     * @param int $flags
     * @return Formula
     * @suppress PhanAccessReadOnlyProperty */
    static function make(Contact $user, $expr, $flags = 0) {
        $f = new Formula;
        $f->conf = $user->conf;
        $f->user = $user;
        $f->expression = $expr;

        $fp = new FormulaParser($f, $expr);
        $f->_fexpr = $f->_adjust_fexpr($fp->parse(), $flags);
        if (MessageSet::list_status($f->lerrors) >= MessageSet::ERROR) {
            $f->_fexpr->set_format_error();
        }
        $f->_format = $f->_fexpr->format();
        $f->_format_detail = $f->_fexpr->format_detail();

        return $f;
    }

    /** @param string $expr
     * @return Formula */
    static function make_indexed(Contact $user, $expr) {
        return self::make($user, $expr, self::ALLOW_INDEXED);
    }



    /* parsing */

    /** @param int $flags */
    private function _adjust_fexpr(Fexpr $fe, $flags) {
        if ($fe->format() === Fexpr::FERROR) {
            if (empty($this->lerrors)) {
                $this->fexpr_lerror($fe, "<0>Formula parse error");
            }
            return $fe;
        }
        if (!$fe->typecheck($this)) {
            if (empty($this->lerrors)) {
                $this->fexpr_lerror($fe, "<0>Formula type mismatch");
            }
            return $fe;
        }
        $state = new FormulaCompiler($this);
        $fe->compile($state);
        if ($state->indexed && ($flags & self::ALLOW_INDEXED) === 0) {
            if ($fe->matches_at_most_once()) {
                $some_fe = new Some_Fexpr($fe, $fe->some_inferred_index());
                $some_fe->apply_strspan($fe->pos1, $fe->pos2, null);
                return $this->_adjust_fexpr($some_fe, $flags);
            }
            $this->fexpr_lerror($fe, "<0>Need an aggregate function like ‘sum’ or ‘max’");
            return $fe;
        }
        if ($state->indexed) {
            $this->_indexed = true;
            $this->_index_type = $fe->inferred_index();
        } else if ($fe instanceof Aggregate_Fexpr) {
            $this->_index_type = $fe->index_type;
        }
        return $fe;
    }


    /** @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $context
     * @param string $message */
    function lcerror($pos1, $pos2, $context, $message) {
        $ml = SearchStringContext::expand(MessageItem::error($message), $pos1, $pos2, $context, $this->expression);
        array_push($this->lerrors, ...$ml);
    }

    /** @param string $message */
    function fexpr_lerror(Fexpr $expr, $message) {
        $this->lcerror($expr->pos1, $expr->pos2, $expr->string_context, $message);
    }


    /** @return int */
    function register_info($info) {
        $index = array_search($info, $this->info, true);
        if ($index === false) {
            $this->info[] = $info;
            $index = count($this->info) - 1;
        }
        return $index;
    }

    /** @return bool */
    function ok() {
        return $this->_format !== Fexpr::FERROR;
    }

    /** @return Fexpr */
    function fexpr() {
        return $this->_fexpr;
    }

    /** @return list<MessageItem> */
    function message_list() {
        return $this->lerrors;
    }

    /** @param string $field
     * @return bool */
    function has_problem_at($field) {
        foreach ($this->lerrors as $mi) {
            if ($mi->field === $field && $mi->status >= 2)
                return true;
        }
        return false;
    }

    /** @return string */
    function full_feedback_text() {
        return MessageSet::feedback_text($this->message_list());
    }

    /** @return PaperInfo */
    function placeholder_prow() {
        if ($this->_placeholder_prow === null) {
            $this->_placeholder_prow = PaperInfo::make_placeholder($this->conf, -1);
        }
        return $this->_placeholder_prow;
    }

    /** @return PaperOption */
    function format_sf() {
        assert($this->_format === Fexpr::FSUBFIELD);
        return $this->_format_detail;
    }

    /** @return ReviewField */
    function format_rf() {
        assert($this->_format === Fexpr::FREVIEWFIELD);
        return $this->_format_detail;
    }

    private static function debug_report($function) {
        if (self::DEBUG === 1) {
            error_log("{$function}\n");
        } else if (self::DEBUG > 1) {
            Conf::msg_debugt("{$function}\n");
        }
    }

    /** @param Contact $user
     * @param string $expr
     * @param int $sortable
     * @return string */
    private static function compile_body($user, FormulaCompiler $state, $expr,
                                         $sortable) {
        $t = "";
        if ($user) {
            $t .= "assert(\$user->contactXid === {$user->contactXid});\n  ";
            // $t .= "if (\$user->contactXid !== {$user->contactXid}) { error_log(debug_string_backtrace()); }\n  ";
        }
        $t .= $state->statement_text();
        if ($expr !== null) {
            if ($sortable & 3) {
                $t .= "\n  \$x = {$expr};";
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

    static private function protect_string($s) {
        return str_replace("?>", "? >", simplify_whitespace($s));
    }

    /** @param int $sortable
     * @return callable(PaperInfo,?int,Contact):mixed */
    private function _compile_function($sortable) {
        if ($this->ok()) {
            $state = new FormulaCompiler($this);
            $expr = $this->_fexpr->compile($state);
            $body = self::compile_body($this->user, $state, $expr, $sortable);
        } else {
            $body = "return null;\n";
        }
        $function = "function (\$prow, \$rrow_cid, \$user, \$formula) {\n  ";
        if (self::DEBUG) {
            $function .= "// " . self::protect_string($this->expression) . "\n  ";
        }
        $function .= $body . "}";
        if (self::DEBUG) {
            self::debug_report($function);
        }
        return eval("return {$function};");
    }

    /** @return $this */
    function prepare() {
        $this->_f_main = $this->_f_main ?? $this->_compile_function(0);
        return $this;
    }

    /** @param PaperInfo $prow
     * @param ?int $rrow_cid
     * @return mixed */
    function eval($prow, $rrow_cid) {
        return call_user_func($this->_f_main, $prow, $rrow_cid, $this->user, $this);
    }

    /** @return $this */
    function prepare_sortable() {
        $this->_f_sortable = $this->_f_sortable ?? $this->_compile_function(1);
        return $this;
    }

    /** @param PaperInfo $prow
     * @param ?int $rrow_cid
     * @return mixed */
    function eval_sortable($prow, $rrow_cid) {
        return call_user_func($this->_f_sortable, $prow, $rrow_cid, $this->user, $this);
    }

    /** @return $this */
    function prepare_json() {
        $this->_f_json = $this->_f_json ?? $this->_compile_function(2);
        return $this;
    }

    /** @param PaperInfo $prow
     * @param ?int $rrow_cid
     * @return mixed */
    function eval_json($prow, $rrow_cid) {
        return call_user_func($this->_f_json, $prow, $rrow_cid, $this->user, $this);
    }

    /** @param int ...$index_types
     * @return int */
    static function combine_index_types(...$index_types) {
        $rit = 0;
        foreach ($index_types as $it) {
            if ($it === 0 || $it === Fexpr::IDX_MY || $it === $rit) {
                // nothing
            } else if ($rit === 0) {
                $rit = $it;
            } else {
                $rit = Fexpr::IDX_PC;
            }
        }
        return $rit;
    }

    /** @param ?int $index_types
     * @return $this */
    function prepare_indexer($index_types = null) {
        $index_types = $index_types ?? $this->index_type();
        if ($index_types === 0) {
            $this->_f_indexer = function () { return [null]; };
            return $this;
        }
        $state = new FormulaCompiler($this);
        $g = $state->index_range($index_types);
        $body = join("\n  ", $state->gstmt) . "\n";
        if (($index_types & Fexpr::IDX_REVIEW) !== 0) {
            $check = "";
            if ($index_types === Fexpr::IDX_CREVIEW) {
                $check = "    if (!\$rrow->reviewSubmitted) { continue; }\n";
            }
            $body .= "  \$cids = [];\n"
                . "  foreach ({$g} as \$rrow) {\n"
                . $check
                . "    \$cids[] = \$rrow->contactId;\n"
                . "  }\n"
                . "  return \$cids;\n";
        } else {
            $body .= "  return array_keys({$g});\n";
        }
        $function = "function (\$prow, \$user, \$formula) {\n  ";
        if (self::DEBUG) {
            $function .= "// index types " . dechex($index_types) . "\n  ";
        }
        $function .= $body . "}";
        if (self::DEBUG) {
            self::debug_report($function);
        }
        $this->_f_indexer = eval("return {$function};");
        return $this;
    }

    /** @param PaperInfo $prow
     * @return mixed */
    function eval_indexer($prow) {
        return call_user_func($this->_f_indexer, $prow, $this->user, $this);
    }

    /** @return bool */
    function support_combiner() {
        if ($this->_supports_combiner !== null) {
            return $this->_supports_combiner;
        } else if (!$this->ok()) {
            return false;
        }
        $state = FormulaCompiler::make_combiner($this);
        $fexpr = $this->_fexpr->compile($state);
        if ($state->term_error || $state->term_compiler->term_error) {
            $this->_f_extractor = function ($prow, $rrow_cid, $user, $formula) { return null; };
            $this->_f_combiner = function ($extractor_results) { return null; };
            $this->_supports_combiner = false;
            return false;
        }
        $extractor_str = "function (\$prow, \$rrow_cid, \$user, \$formula) {\n  ";
        if (self::DEBUG) {
            $extractor_str .= "// extractor " . self::protect_string($this->expression) . "\n  ";
        }
        $extractor_str .= self::compile_body($this->user, $state->term_compiler, "[" . join(",", $state->term_compiler->term_list) . "]", 0)
            . "}";
        $this->_f_extractor = eval("return {$extractor_str};\n");
        $combiner_str = "function (\$extractor_results) {\n  ";
        if (self::DEBUG) {
            $combiner_str .= "// combiner " . self::protect_string($this->expression) . "\n  ";
        }
        $combiner_str .= self::compile_body(null, $state, $fexpr, 0)
            . "}";
        $this->_f_combiner = eval("return {$combiner_str};\n");
        $this->_supports_combiner = true;
        return true;
    }

    /** @return $this */
    function prepare_extractor() {
        $this->support_combiner();
        return $this;
    }

    /** @param PaperInfo $prow
     * @param ?int $rrow_cid
     * @return mixed */
    function eval_extractor($prow, $rrow_cid) {
        return call_user_func($this->_f_extractor, $prow, $rrow_cid, $this->user, $this);
    }

    /** @return $this */
    function prepare_combiner() {
        $this->support_combiner();
        return $this;
    }

    /** @param list<mixed> $extractor_results
     * @return mixed */
    function eval_combiner($extractor_results) {
        return call_user_func($this->_f_combiner, $extractor_results);
    }

    function add_query_options(&$queryOptions) {
        if ($this->ok()) {
            $state = new FormulaCompiler($this);
            $state->queryOptions =& $queryOptions;
            $this->_fexpr->compile($state);
            if ($this->_index_type > 0) {
                $state->index_range($this->_index_type);
            }
        }
    }

    /** @var array<int,true> &$ods */
    function paper_options(&$oids) {
        if ($this->ok()) {
            $this->_fexpr->paper_options($oids);
        }
    }

    /** @return bool */
    function viewable() {
        return $this->_format !== Fexpr::FERROR && $this->_format !== Fexpr::FNULL;
    }

    /** @return int */
    function result_format() {
        if (!$this->ok()) {
            return null;
        }
        return $this->_format;
    }

    /** @return mixed */
    function result_format_detail() {
        if (!$this->ok()) {
            return null;
        }
        return $this->_fexpr->format_detail();
    }

    /** @return ?bool */
    function result_format_is_numeric() {
        if (!$this->ok()) {
            return null;
        }
        return $this->_format === Fexpr::FNULL
            || $this->_format === Fexpr::FNUMERIC
            || ($this->_format === Fexpr::FREVIEWFIELD
                && $this->_format_detail->is_numeric());
    }

    /** @return string */
    function result_format_description() {
        if (!$this->ok()) {
            return "error";
        }
        return $this->_fexpr->format_description();
    }

    /** @return ValueFormat */
    function value_format() {
        require_once(__DIR__ . "/valueformat.php");
        if ($this->_value_format !== null) {
            return $this->_value_format;
        }
        if (!$this->ok()) {
            $this->_value_format = Null_ValueFormat::main();
        } else if ($this->_format <= Fexpr::FNUMERIC) {
            $this->_value_format = Numeric_ValueFormat::main();
        } else if ($this->_format === Fexpr::FBOOL) {
            $this->_value_format = Bool_ValueFormat::main();
        } else if ($this->_format === Fexpr::FREVIEWFIELD) {
            $this->_value_format = new ReviewField_ValueFormat($this->format_rf());
        } else if ($this->_format === Fexpr::FSUBFIELD) {
            $this->_value_format = new SubmissionField_ValueFormat($this->user, $this->format_sf());
        } else if ($this->_format === Fexpr::FPREFEXPERTISE) {
            $this->_value_format = new Expertise_ValueFormat($this->conf);
        } else if ($this->_format === Fexpr::FREVIEWER) {
            $this->_value_format = new User_ValueFormat($this->user);
        } else if ($this->_format === Fexpr::FDATE) {
            $this->_value_format = new Date_ValueFormat;
        } else if ($this->_format === Fexpr::FTIME) {
            $this->_value_format = new Time_ValueFormat;
        } else if ($this->_format === Fexpr::FDATEDELTA
                   || $this->_format === Fexpr::FTIMEDELTA) {
            $this->_value_format = Duration_ValueFormat::main();
        } else {
            $this->_value_format = Numeric_ValueFormat::main();
        }
        return $this->_value_format;
    }


    /** @return bool */
    function is_sumlike() {
        return $this->ok()
            && ($this->_fexpr->op === "sum"
                || $this->_fexpr->op === "count");
    }

    /** @return bool */
    function indexed() {
        return $this->_indexed;
    }

    /** @return int */
    function index_type() {
        return $this->_index_type;
    }

    #[\ReturnTypeWillChange]
    /** @return array<string,mixed> */
    function jsonSerialize() {
        $j = [];
        $j["expression"] = $this->expression;
        $j["parse"] = $this->_fexpr;
        return $j;
    }
}
