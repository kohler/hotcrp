<?php
// formula.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class FormulaCall {
    /** @var Formula */
    public $formula;
    /** @var string */
    public $name;
    /** @var string */
    public $text;
    /** @var list<Fexpr> */
    public $args = [];
    /** @var list<string> */
    public $rawargs = [];
    /** @var ?int */
    public $index_type;
    /** @var ?mixed */
    public $modifier;
    /** @var object */
    public $kwdef;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;

    function __construct(Formula $formula, $kwdef, $name) {
        $this->formula = $formula;
        $this->name = $kwdef ? $kwdef->name : "";
        $this->text = $name;
        $this->kwdef = $kwdef;
    }

    /** @param list<Fexpr> $args
     * @return FormulaCall */
    static function make_args(Formula $formula, $name, $args) {
        $fc = new FormulaCall($formula, null, $name);
        $fc->args = $args;
        foreach ($args as $a) {
            $fc->pos1 = min($fc->pos1 ?? $a->pos1, $a->pos1);
            $fc->pos2 = max($fc->pos2 ?? $a->pos2, $a->pos2);
        }
        return $fc;
    }

    /** @param string $message
     * @return MessageItem */
    function lerror($message) {
        return $this->formula->lerror($this->pos1, $this->pos2, $message);
    }

    /** @param mixed $args
     * @return bool */
    function check_nargs($args) {
        if (is_int($args)) {
            $args = [$args, $args];
        }
        if (!is_array($args)) {
            return true;
        }
        if (count($this->args) < $args[0]) {
            $note = $args[0] < $args[1] ? "at least " : "";
            $this->lerror("<0>Too few arguments to function ‘{$this->name}’ (expected {$note}{$args[0]})");
            return false;
        } else if (count($this->args) > $args[1]) {
            $note = $args[0] < $args[1] ? "{$args[0]}–{$args[1]}" : $args[1];
            $this->lerror("<0>Too many arguments to function ‘{$this->name}’ (expected {$note})");
            return false;
        } else {
            return true;
        }
    }
}

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
            $this->pos2 = $op->pos1;
        }
    }

    /** @param Fexpr $x */
    function add($x) {
        $this->args[] = $x;
    }

    /** @param int $left
     * @param int $right */
    function set_landmark($left, $right) {
        $this->pos1 = $left;
        $this->pos2 = $right;
        return $this;
    }


    /** @return int */
    function format() {
        return $this->_format;
    }

    /** @return bool */
    final function has_format() {
        return $this->_format !== Fexpr::FUNKNOWN;
    }

    /** @return bool */
    final function math_format() {
        return $this->_format !== Fexpr::FREVIEWER
            && $this->_format !== Fexpr::FSUBFIELD;
    }

    /** @return bool */
    final function nonnullable_format() {
        return $this->_format === Fexpr::FNUMERIC
            || $this->_format === Fexpr::FBOOL;
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
        } else {
            $formula->fexpr_lerror($this, $this->disallowed_use_error());
            return false;
        }
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

    /** @return bool */
    function viewable_by(Contact $user) {
        foreach ($this->args as $e) {
            if (!$e->viewable_by($user))
                return false;
        }
        return true;
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
}

class Constant_Fexpr extends Fexpr {
    /** @var null|int|float|string */
    private $x;
    /** @param null|int|float|string $x
     * @param int $format
     * @param ?int $pos1
     * @param ?int $pos2 */
    function __construct($x, $format, $pos1 = null, $pos2 = null) {
        $this->x = $x;
        $fd = $format === self::FNUMERIC || $format === self::FBOOL ? true : null;
        $this->set_format($format, $fd);
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
        if ($this->format() === Fexpr::FREVTYPE
            && is_string($this->x)
            && !$this->_check_revtype()) {
            $formula->fexpr_lerror($this, "<0>Review type ‘{$this->x}’ not found");
            return false;
        } else if (!$this->has_format()) {
            $this->set_format(Fexpr::FERROR);
            $formula->fexpr_lerror($this, "<0>Term ‘{$this->x}’ not found");
            return false;
        } else {
            return true;
        }
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
    function viewable_by(Contact $user) {
        return true;
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
    /** @return Constant_Fexpr */
    static function cerror($pos1 = null, $pos2 = null) {
        return new Constant_Fexpr("null", Fexpr::FERROR, $pos1, $pos2);
    }
    /** @return Constant_Fexpr */
    static function make_error_call(FormulaCall $ff) {
        $ff->formula->lerror($ff->pos1, $ff->pos1 + strlen($ff->name), "<0>Function ‘{$ff->name}’ not found");
        return self::cerror($ff->pos1, $ff->pos2);
    }
    /** @return Constant_Fexpr */
    static function cnull() {
        return new Constant_Fexpr("null", Fexpr::FNULL);
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
    function viewable_by(Contact $user) {
        return $this->args[2]->viewable_by($user)
            || ($this->args[0]->viewable_by($user) && $this->args[1]->viewable_by($user));
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
    function viewable_by(Contact $user) {
        return $this->args[0]->viewable_by($user) || $this->args[1]->viewable_by($user);
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
            $ff->formula->lerror($ff->pos2 - strlen($arg), $ff->pos2, "<0>Collection already specified");
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
            $ff->formula->lerror($ff->pos2 - strlen($arg), $ff->pos2, "<0>Collection ‘{$arg}’ not found");
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
        } else {
            return "({$t}[2] ? sqrt({$t}[0] / {$t}[2] - ({$t}[1] * {$t}[1]) / ({$t}[2] * {$t}[2])) : null)";
        }
    }
}

class Quantile_Fexpr extends Aggregate_Fexpr {
    /** @var int */
    private $varg;
    function __construct($fn, array $values, ?int $index_type) {
        parent::__construct($fn, $values, $index_type);
        $this->varg = $fn === "median" ? 0 : 1;
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
        } else {
            return $ff->check_nargs(1) ? new Extremum_Fexpr($ff->name, $ff->args[0], $ff->index_type) : null;
        }
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
        } else {
            return $state->_compile_loop("null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : +~l~) : ~r~)", $this);
        }
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
    function viewable_by(Contact $user) {
        return $this->format_detail()->view_score > $user->permissive_view_score_bound();
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
    function viewable_by(Contact $user) {
        return $this->args[1]->viewable_by($user);
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
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Tagger */
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

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->tagger = new Tagger($user);
        $this->clear();
    }

    /** @return FormulaCompiler */
    static function make_combiner(Contact $user) {
        $fc = new FormulaCompiler($user);
        $fc->term_compiler = new FormulaCompiler($user);
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
            $this->gstmt[] = "\$pc = \$contact->conf->pc_members();";
        }
        return '$pc';
    }

    /** @return string */
    function _add_vreviews() {
        if ($this->check_gvar('$vreviews')) {
            $this->queryOptions["reviewSignatures"] = true;
            $this->gstmt[] = "\$vreviews = " . $this->_prow() . "->viewable_reviews_as_display(\$contact);";
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
            $this->gstmt[] = "\$preferences = \$contact->can_view_preference({$prow}) ? {$prow}->preferences() : [];";
        }
        return '$preferences';
    }

    /** @return string */
    function _add_conflict_types() {
        if ($this->check_gvar('$conflict_types')) {
            $this->queryOptions["allConflictType"] = true;
            $prow = $this->_prow();
            $this->gstmt[] = "\$conflict_types = \$contact->can_view_conflicts({$prow}) ? {$prow}->conflict_types() : [];";
        }
        return '$conflict_types';
    }

    /** @return string */
    function _add_tags() {
        if ($this->check_gvar('$tags')) {
            $this->queryOptions["tags"] = true;
            $this->gstmt[] = "\$tags = " . $this->_prow() . "->searchable_tags(\$contact);";
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
            $this->gstmt[] = "\$decision = \$contact->can_view_decision({$prow}) ? {$prow}->outcome : 0;";
        }
        return '$decision';
    }

    /** @return string */
    function _add_primary_document() {
        if ($this->check_gvar('$primary_document')) {
            $prow = $this->_prow();
            $decision = $this->_add_decision();
            $this->gstmt[] = "\$primary_document = \$contact->can_view_pdf({$prow}) ? {$prow}->document({$prow}->finalPaperStorageId > 0 ? " . DTYPE_FINAL . " : " . DTYPE_SUBMISSION . ") : null;";
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
            return $this->define_gvar("rrow_vcid", "({$rrow} && \$contact->can_view_review_identity(\$prow, {$rrow}) ? \$rrow_cid : null)");
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
            return $this->define_gvar("rrow_vsb{$sfx}", "({$rrow}{$clause} ? \$contact->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
        } else if ($this->index_type === Fexpr::IDX_MY) {
            return $this->define_gvar("myrrow_vsb{$sfx}", "({$rrow}{$clause} ? \$contact->view_score_bound(\$prow, {$rrow}) : " . VIEWSCORE_EMPTYBOUND . ")");
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
        } else {
            return $body;
        }
    }

    /** @param int $index_types
     * @return string */
    function loop_variable($index_types) {
        if ($index_types === Fexpr::IDX_PC_SET_PRIVATE_TAG) {
            return $this->_add_pc_set_private_tag();
        } else if (($index_types & Fexpr::IDX_REVIEW_MASK) === $index_types) {
            return $this->_add_vreviews();
        } else if ($index_types === Fexpr::IDX_PC) {
            return $this->_add_pc();
        }
        assert($index_types === 0 || $index_types === Fexpr::IDX_X);
        return $this->define_gvar("trivial_loop", "[0]");
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
                $lprefix[] = "\$i{$p} = \$contact->can_view_review_identity(\$prow, \$rrow_{$p}) ? \$rrow_{$p}->contactId : null;";
            }
        } else {
            if (($this->_lflags & self::LFLAG_RROW) !== 0) {
                $lprefix[] = "\$rrow_{$p} = \$prow->viewable_review_by_user(\$i{$p}, \$contact);";
            }
        }
        if (($this->_lflags & (self::LFLAG_RROW_VSB | self::LFLAG_RROW_VSBS)) !== 0) {
            $lprefix[] = "\$rrow_vsb_{$p} = \$rrow_{$p} ? \$contact->view_score_bound(\$prow, \$rrow_{$p}) : " . VIEWSCORE_EMPTYBOUND . ";";
        }
        if (($this->_lflags & self::LFLAG_RROW_VSBS) !== 0) {
            $lprefix[] = "\$rrow_vsbs_{$p} = \$rrow_{$p} && \$rrow_{$p}->reviewSubmitted ? \$rrow_vsb_{$p} : " . VIEWSCORE_EMPTYBOUND . ";";
        }
        if (($this->_lflags & self::LFLAG_PREFERENCES) !== 0) {
            $lprefix[] = "\$preferences_{$p} = \$prow->viewable_preferences(\$contact"
                . ($this->_lflags & self::LFLAG_CID ? "" : ", true")
                . ");";
        }

        if ($this->term_compiler !== null) {
            $loop = "foreach (\$extractor_results as \$v{$p}) " . $this->_join_lstmt(true, $lprefix);
        } else {
            $g = $this->loop_variable($this->index_type);
            if (($this->index_type & Fexpr::IDX_REVIEW) !== 0) {
                $loop = "foreach ({$g} as \$v{$p}) ";
            } else {
                $loop = "foreach ({$g} as \$i{$p} => \$v{$p}) ";
            }
            $loop .= str_replace("~i~", "\$i{$p}", $this->_join_lstmt(true, $lprefix));
            $loop = str_replace("({$g}[\$i{$p}] ?? null)", "\$v{$p}", $loop);
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

class FormulaParse {
    /** @var Fexpr */
    public $fexpr;
    /** @var bool */
    public $indexed = false;
    /** @var int */
    public $index_type = 0;
    /** @var list<MessageItem> */
    public $lerrors;

    function __construct(Fexpr $fexpr) {
        $this->fexpr = $fexpr;
    }
}

class Formula implements JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var ?Contact */
    public $user;

    /** @var ?int */
    public $formulaId;
    public $name;
    /** @var string */
    public $expression;
    /** @var int */
    public $createdBy = 0;
    /** @var int */
    public $timeModified = 0;

    /** @var bool */
    private $_allow_indexed;
    private $_abbreviation;

    /** @var int */
    private $pos;
    /** @var int */
    private $_depth = 0;
    /** @var ?FormulaCall */
    private $_macro;
    /** @var ?array<string,VarDef_Fexpr> */
    private $_bind;
    /** @var list<MessageItem> */
    private $_lerrors;

    /** @var ?FormulaParse */
    private $_parse;
    /** @var int */
    private $_format = Fexpr::FUNKNOWN;
    private $_format_detail;
    /** @var ?ValueFormat */
    private $_value_format;

    private $_extractorf;
    private $_combinerf;

    /** @var ?PaperInfo */
    private $_placeholder_prow;

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

    static private $_oprassoc = [
        "**" => true
    ];

    static private $_oprewrite = [
        "=" => "==", ":" => "==", "≤" => "<=", "≥" => ">=", "≠" => "!=",
        "and" => "&&", "or" => "||"
    ];

    const ALLOW_INDEXED = 1;
    /** @param ?string $expr
     * @param int $flags */
    function __construct($expr = null, $flags = 0) {
        assert(is_int($flags));
        if ($expr !== null) {
            $this->expression = $expr;
        }
        $this->_allow_indexed = ($flags & self::ALLOW_INDEXED) !== 0;
    }

    private function fetch_incorporate() {
        $this->formulaId = (int) $this->formulaId;
        $this->createdBy = (int) $this->createdBy;
        $this->timeModified = (int) $this->timeModified;
    }

    /** @param Dbl_Result $result
     * @return ?Formula */
    static function fetch(Conf $conf, $result) {
        if (($formula = $result->fetch_object("Formula"))) {
            $formula->conf = $conf;
            $formula->fetch_incorporate();
        }
        return $formula;
    }

    /** @param string $t
     * @return int */
    static function span_maximal_formula($t) {
        return self::span_parens_until($t, 0, ",;)]}");
    }


    function assign_search_keyword(AbbreviationMatcher $am) {
        if ($this->_abbreviation === null) {
            $e = new AbbreviationEntry($this->name, $this, Conf::MFLAG_FORMULA);
            $this->_abbreviation = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        } else {
            $am->add_keyword($this->_abbreviation, $this, Conf::MFLAG_FORMULA);
        }
    }

    /** @return string */
    function abbreviation() {
        if ($this->_abbreviation === null) {
            $this->conf->abbrev_matcher();
            assert($this->_abbreviation !== null);
        }
        return $this->_abbreviation;
    }


    /* parsing */

    /** @param int $pos1
     * @param int $pos2
     * @param string $message
     * @return MessageItem */
    function lerror($pos1, $pos2, $message) {
        if (!Ftext::is_ftext($message)) {
            error_log("not ftext: " . debug_string_backtrace());
        }
        $mi = MessageItem::error($message);
        $mi->pos1 = $pos1;
        $mi->pos2 = $pos2;
        $mi->context = (string) $this->expression;
        $this->_lerrors[] = $mi;
        return $mi;
    }

    /** @param string $message
     * @return MessageItem */
    function fexpr_lerror(Fexpr $expr, $message) {
        return $this->lerror($expr->pos1, $expr->pos2, $message);
    }

    /** @return FormulaParse */
    private function parse(Contact $user) {
        assert(!$this->conf || $this->conf === $user->conf);
        assert($this->_depth === 0);
        if ($user !== $this->user) {
            $this->conf = $user->conf;
            $this->user = $user;
        }
        $this->_lerrors = [];
        $this->_bind = [];
        if ((string) $this->expression === "") {
            $fe = Constant_Fexpr::cerror(0, 0);
            $this->fexpr_lerror($fe, "<0>Empty formula");
        } else {
            ++$this->_depth;
            $this->pos = 0;
            $fe = $this->_parse_ternary(false)
                ?? Constant_Fexpr::cerror(0, strlen($this->expression));
            if (self::skip_whitespace($this->expression, $this->pos) !== strlen($this->expression)) {
                $this->lerror($this->pos, strlen($this->expression), "<0>Expected end of formula");
            }
            --$this->_depth;
        }
        $fp = $this->_fexpr_to_parse($fe);
        $fp->lerrors = $this->_lerrors;
        if (MessageSet::list_status($fp->lerrors) >= MessageSet::ERROR) {
            $fp->fexpr->set_format_error();
        }
        $this->_lerrors = null;
        $this->_bind = null;
        return $fp;
    }

    /** @return FormulaParse */
    private function _fexpr_to_parse(Fexpr $fe) {
        if ($fe->format() === Fexpr::FERROR) {
            if (empty($this->_lerrors)) {
                $this->fexpr_lerror($fe, "<0>Formula parse error");
            }
            return new FormulaParse($fe);
        }
        if (!$fe->typecheck($this)) {
            if (empty($this->_lerrors)) {
                $this->fexpr_lerror($fe, "<0>Formula type mismatch");
            }
            return new FormulaParse($fe);
        }
        $state = new FormulaCompiler($this->user);
        $fe->compile($state);
        if ($state->indexed && !$this->_allow_indexed) {
            if ($fe->matches_at_most_once()) {
                $some_fe = new Some_Fexpr($fe, $fe->some_inferred_index());
                $some_fe->set_landmark($fe->pos1, $fe->pos2);
                return $this->_fexpr_to_parse($some_fe);
            }
            $this->fexpr_lerror($fe, "<0>Need an aggregate function like ‘sum’ or ‘max’");
            return new FormulaParse($fe);
        }
        $fp = new FormulaParse($fe);
        $fp->indexed = !!$state->indexed;
        if ($fp->indexed) {
            $fp->index_type = $fe->inferred_index();
        } else if ($fe instanceof Aggregate_Fexpr) {
            $fp->index_type = $fe->index_type;
        }
        return $fp;
    }

    /** @return bool */
    function check(?Contact $user = null) {
        $user = $user ?? $this->user;
        assert($user !== null);
        if ($this->_parse === null || $user !== $this->user) {
            $this->_parse = $this->parse($user);
            $this->_format = $this->_parse->fexpr->format();
            $this->_format_detail = $this->_parse->fexpr->format_detail();
            $this->_value_format = null;
            $this->_extractorf = $this->_combinerf = null;
        }
        return $this->_format !== Fexpr::FERROR;
    }

    /** @return Fexpr */
    function fexpr() {
        $this->check();
        return $this->_parse->fexpr;
    }

    /** @return list<MessageItem> */
    function message_list() {
        $this->check();
        return $this->_parse->lerrors ?? [];
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
    private static function span_parens_until($t, $pos, $span) {
        $len = strlen($t);
        while ($pos !== $len && strpos($span, $t[$pos]) === false) {
            $x = SearchParser::span_balanced_parens($t, $pos, $span, true);
            $pos = max($pos + 1, $x);
        }
        return $pos;
    }

    /** @param string &$name
     * @return ?object */
    private function _find_formula_function(&$name) {
        while ($name !== "") {
            if (($kwdef = $this->conf->formula_function($name, $this->user))) {
                return $kwdef;
            }
            $pos1 = strrpos($name, ":");
            $pos2 = strrpos($name, ".");
            $name = substr($name, 0, max((int) $pos1, (int) $pos2));
        }
        return null;
    }

    /** @return bool */
    private function _parse_function_args(FormulaCall $ff) {
        $argtype = $ff->kwdef->args;
        $needargs = $argtype !== "optional"
            && ($argtype === "raw" ? empty($ff->rawargs) : empty($ff->args));

        // skip space
        $t = $this->expression;
        $len = strlen($t);
        $pos0 = $this->pos;
        $pos = self::skip_whitespace($t, $pos0);

        // no parenthesis
        if ($pos === $len || $t[$pos] !== "(") {
            if (!$needargs) {
                return true;
            }
            $this->pos = $pos;
            if (($e = $this->_parse_expr(self::$opprec["u+"], false))) {
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
        ++$this->_depth;
        $pos = self::skip_whitespace($t, $pos1 + 1);
        while ($pos !== $len && $t[$pos] !== ")") {
            if ($comma && $t[$pos] === ",") {
                $pos = self::skip_whitespace($t, $pos + 1);
            }
            $this->pos = $apos = $pos;
            if (($e = $this->_parse_ternary(false))) {
                $ff->args[] = $e;
            } else {
                $ff->args[] = Constant_Fexpr::cerror($apos, $this->pos);
            }
            $pos = self::skip_whitespace($t, $this->pos);
            if ($pos !== $len && $t[$pos] !== ")" && $t[$pos] !== ",") {
                if (!$warned) {
                    $this->lerror($pos, $pos, "<0>Expected ‘,’ or ‘)’");
                    $warned = true;
                }
                $pos = self::span_parens_until($t, $pos, "),");
            }
            $comma = true;
        }
        if ($pos === $len) {
            $this->lerror(0, 0, "<0>Expected ‘)’");
        } else {
            ++$pos;
        }
        --$this->_depth;
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
            while (preg_match('/\G[.#:](?:"[^"]*(?:"|\z)|[-a-zA-Z0-9_.@!*?~:\/#]+)/s', $this->expression, $m, 0, $mpos)
                   && ($args !== false
                       || !preg_match('/\G\s*\(/', $this->expression, $mx, 0, $mpos + strlen($m[0])))) {
                $ff->pos2 = $mpos + strlen($m[0]);
                if (!call_user_func($kwdef->parse_modifier_function, $ff, $m[0], $this)) {
                    $ff->pos2 = $this->pos;
                    break;
                }
                $this->pos = $mpos = $mpos + strlen($m[0]);
            }
        }

        if ($args !== false) {
            if (!$this->_parse_function_args($ff)) {
                $this->lerror($ff->pos1, $this->pos, "<0>Function ‘{$name}’ requires arguments");
                return Constant_Fexpr::cerror($ff->pos1, $this->pos);
            } else if (!$ff->check_nargs($args)) {
                return Constant_Fexpr::cerror($ff->pos1, $this->pos);
            }
        }

        return $this->_create_function($kwdef, $ff) ?? Constant_Fexpr::cerror($ff->pos1, $ff->pos2);
    }

    /** @return ?Fexpr */
    private function _create_function($kwdef, FormulaCall $ff) {
        $before = count($this->_lerrors);

        if (isset($kwdef->function)) {
            if ($kwdef->function[0] === "+") {
                $class = substr($kwdef->function, 1);
                /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
                return new $class($ff, $this);
            }
            if (($e = call_user_func($kwdef->function, $ff, $this))) {
                return $e;
            }
            if (count($this->_lerrors) === $before) {
                $this->lerror($ff->pos1, $ff->pos2, "<0>Parse error");
            }
            return null;
        }

        if (isset($kwdef->macro)) {
            if ($this->_depth > 100) {
                $this->lerror($ff->pos1, $ff->pos2, "<0>Circular macro definition");
                return null;
            }
            $old_macro = $this->_macro;
            $old_expression = $this->expression;
            $old_pos = $this->pos;
            $this->_macro = $ff;
            $this->expression = $kwdef->macro; // XXX should use SearchStringContext mechanism
            $this->pos = 0;
            ++$this->_depth;

            $e = $this->_parse_ternary(false);
            $end_pos = self::skip_whitespace($this->expression, $this->pos);

            --$this->_depth;
            $this->pos = $old_pos;
            $this->expression = $old_expression;
            $this->_macro = $old_macro;

            if ($e && $end_pos === strlen($kwdef->macro)) {
                return $e;
            }
            if (count($this->_lerrors) === $before) {
                $this->lerror($ff->pos1, $ff->pos2, "<0>Parse error in macro");
            }
            return null;
        }

        return null;
    }

    /** @return ?Fexpr */
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
                error_log("field_search_fexpr given {$v}");
                $fx = Constant_Fexpr::cnull();
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
        if (preg_match('/\A(?:r|re|rev|review)type\z/i', $t)) {
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

    /** @param ?Fexpr $e0
     * @return ?Fexpr */
    private function _reviewer_decoration($e0) {
        $es = [];
        $rsm = new ReviewSearchMatcher;
        $t = $this->expression;
        $len = strlen($t);
        while ($this->pos !== $len) {
            if (!preg_match('/\G:((?:"[^"]*(?:"|\z)|~*[-A-Za-z0-9_.\#@]+(?!\s*\())+)/si', $t, $m, 0, $this->pos)
                || preg_match('/\A(?:null|false|true|pid|paperid)\z/i', $m[1])) {
                break;
            }
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
            $this->pos += strlen($m[0]);
        }

        $rsm->finish();
        if ($rsm->review_type()) {
            $es[] = new Equality_Fexpr("==", new RevType_Fexpr, new Constant_Fexpr($rsm->review_type(), Fexpr::FREVTYPE));
        }
        if ($rsm->round_list !== null) {
            $es[] = new In_Fexpr(new ReviewRound_Fexpr, $rsm->round_list);
        }

        $e1 = empty($es) ? null : $es[0];
        for ($i = 1; $i < count($es); ++$i) {
            $e1 = new And_Fexpr($e1, $es[$i]);
        }

        if ($e0 && $e1) {
            return new Ternary_Fexpr($e1, $e0, Constant_Fexpr::cnull());
        } else {
            return $e0 ?? $e1;
        }
    }

    /** @param string &$field
     * @return ?object */
    private function _find_formula_field(&$field) {
        while (true) {
            if (strlen($field) > 1) {
                $fs = $this->conf->find_all_fields($field);
                if (count($fs) === 1) {
                    return $fs[0];
                }
            }
            if (($dash = strrpos($field, "-")) === false) {
                break;
            }
            $field = substr($field, 0, $dash);
        }
        return null;
    }

    /** @param int $pos1
     * @return Fexpr */
    private function _parse_one_option($pos1, PaperOption $opt) {
        $ff = new FormulaCall($this, null, $opt->search_keyword());
        $ff->pos1 = $pos1;
        $ff->pos2 = $this->pos;
        $nerrors = count($this->_lerrors);
        if (($fex = $opt->parse_fexpr($ff))) {
            return $fex;
        }
        if (count($this->_lerrors) === $nerrors) {
            $ff->lerror("<0>Submission field ‘{$opt->name}’ can’t be used in formulas");
        }
        return Constant_Fexpr::cerror($ff->pos1, $ff->pos2);
    }

    /** @param int $pos1
     * @return Fexpr */
    private function _parse_option($pos1) {
        if (!preg_match('/\G[A-Za-z0-9_.@]+/', $this->expression, $m, 0, $this->pos)) {
            $this->lerror($pos1, $pos1, "<0>Submission field missing");
            return Constant_Fexpr::cerror($pos1, $pos1);
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
        return Constant_Fexpr::cerror($pos1, $this->pos);
    }

    /** @param int $pos1
     * @return Fexpr */
    private function _parse_field($pos1, $f) {
        if ($f instanceof PaperOption) {
            return $this->_parse_one_option($pos1, $f);
        } else if ($f instanceof ReviewField) {
            if ($f instanceof Score_ReviewField
                || $f instanceof Checkbox_ReviewField) {
                return $this->_reviewer_decoration(new Score_Fexpr($f));
            }
            $this->lerror($pos1, $this->pos, "<0>Review field ‘{$f->name}’ can’t be used in formulas");
        } else if ($f instanceof Formula) {
            if ($f->_depth === 0) {
                $fp = $f->parse($this->user);
                if ($fp->fexpr->format() !== Fexpr::FERROR) {
                    return $fp->fexpr;
                }
                $this->lerror($pos1, $this->pos, "<0>Formula definition contains an error");
            } else {
                $this->lerror($pos1, $this->pos, "<0>Self-referential formula");
            }
        } else {
            $this->lerror($pos1, $this->pos, "<0>Field not found");
        }
        return Constant_Fexpr::cerror($pos1, $this->pos);
    }

    /** @param int $level
     * @param bool $in_qc
     * @return ?Fexpr */
    private function _parse_expr($level, $in_qc) {
        $t = $this->expression;
        $len = strlen($t);
        $this->pos = self::skip_whitespace($t, $this->pos);
        if ($this->pos === $len) {
            return null;
        }
        $pos1 = $this->pos;

        $e = null;
        $ch = strtolower($t[$this->pos]);
        if ($ch === "(") {
            ++$this->pos;
            ++$this->_depth;
            $e = $this->_parse_ternary(false);
            --$this->_depth;
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len || $t[$this->pos] !== ")") {
                $this->lerror($this->pos, $this->pos, "<0>Expected ‘)’");
                $this->pos = self::span_parens_until($t, $this->pos, ")");
            }
            if (!$e || $this->pos === $len) {
                return $e;
            }
            ++$this->pos;
        } else if ($ch === "-" || $ch === "+" || $ch === "!") {
            $op = $ch;
            ++$this->pos;
            if (!($e = $this->_parse_expr(self::$opprec["u{$op}"], $in_qc))) {
                return null;
            }
            $e = $op === "!" ? new Not_Fexpr($e) : new Unary_Fexpr($op, $e);
        } else if ($ch === "n"
                   && preg_match('/\Gnot(?=[\s(]|\z)/s', $t, $m, 0, $this->pos)) {
            $this->pos += 3;
            if (!($e = $this->_parse_expr(self::$opprec["u!"], $in_qc))) {
                return null;
            }
            $e = new Not_Fexpr($e);
        } else if (preg_match('/\G(?:\d+\.?\d*|\.\d+)/s', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Constant_Fexpr((float) $m[0], Fexpr::FNUMERIC);
        } else if (($ch === "f" || $ch === "t")
                   && preg_match('/\G(?:false|true)\b/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Constant_Fexpr($m[0], Fexpr::FBOOL);
        } else if ($ch === "n"
                   && preg_match('/\Gnull\b/s', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = Constant_Fexpr::cnull();
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
            $e = new Decision_Fexpr;
        } else if ($ch === "i"
                   && preg_match('/\Gis:?rev?\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Inequality_Fexpr(">=", new Revtype_Fexpr, new Constant_Fexpr(0, Fexpr::FREVTYPE));
        } else if (($ch === "i" || $ch === "p")
                   && preg_match('/\G(?:is:?)?pcrev?\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Inequality_Fexpr(">=", new Revtype_Fexpr, new Constant_Fexpr(REVIEW_PC, Fexpr::FREVTYPE));
        } else if (preg_match('/\G(?:is:?)?(meta|pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?|optional)\b/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = new Equality_Fexpr("==", new Revtype_Fexpr, new Constant_Fexpr($m[1], Fexpr::FREVTYPE));
        } else if (preg_match('/\G(?:is|status):\s*([-a-zA-Z0-9_.\#@*]+)/si', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->field_search_fexpr(PaperSearch::status_field_matcher($this->conf, $m[1]));
        } else if ($ch === "r"
                   && preg_match('/\G(?:(?:r|re|rev|review)(?:type|round|words|auwords|)|round|reviewer)(?=[:\#])/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_decoration($this->_reviewer_base($m[0]));
        } else if ($ch === "r"
                   && preg_match('/\G((?:r|re|rev|review)(?:type|round|words|auwords)|round|reviewer|re|rev|review)\b\s*(?!\()/is', $t, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            $e = $this->_reviewer_base($m[1]);
        } else if ($ch === "l"
                   && preg_match('/\G(let\s+)([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/si', $t, $m, 0, $this->pos)) {
            $var = $m[2];
            $varpos = $this->pos + strlen($m[1]);
            if (preg_match('/\A(?:null|true|false|let|and|or|not|in)\z/', $var)) {
                $this->lerror($varpos, $varpos + strlen($var), "<0>Cannot redefine reserved word ‘{$var}’");
            }
            $vare = new VarDef_Fexpr($var);
            $vare->set_landmark($varpos, $varpos + strlen($var));
            $this->pos += strlen($m[0]);
            $e = $this->_parse_ternary(false);
            if ($e && preg_match('/\G\s*in(?=[\s(])/si', $t, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
                $old_bind = $this->_bind[$var] ?? null;
                $this->_bind[$var] = $vare;
                $e2 = $this->_parse_ternary($in_qc);
                $this->_bind[$var] = $old_bind;
            } else {
                $this->lerror($this->pos, $this->pos, "<0>Expected ‘in’");
                $e2 = null;
            }
            if ($e && $e2) {
                $e = new Let_Fexpr($vare, $e, $e2);
            }
        } else if ($ch === "\$"
                   && preg_match('/\G\$(\d+)/s', $t, $m, 0, $this->pos)
                   && $this->_macro
                   && intval($m[1]) > 0) {
            $this->pos += strlen($m[0]);
            if (intval($m[1]) <= count($this->_macro->args)) {
                $e = $this->_macro->args[intval($m[1]) - 1];
            } else {
                $e = Constant_Fexpr::cnull();
            }
        } else if (($ch === "\"" && preg_match('/\G"(.*?)"/s', $t, $m, 0, $this->pos))
                   || ($ch === "\xE2" && preg_match('/\G(?:“|”)(.*?)(?:"|“|”)/s', $t, $m, 0, $this->pos))) {
            $this->pos += strlen($m[0]);
            $fs = $m[1] === "" ? [] : $this->conf->find_all_fields($m[1]);
            if (count($fs) === 1) {
                $e = $this->_parse_field($pos1, $fs[0]);
            } else {
                $e = new Constant_Fexpr($m[1], Fexpr::FUNKNOWN);
            }
        } else if (!empty($this->_bind)
                   && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $t, $m, 0, $this->pos)
                   && isset($this->_bind[$m[0]])) {
            $this->pos += strlen($m[0]);
            $e = new VarUse_Fexpr($this->_bind[$m[0]]);
        } else if (preg_match('/\G(?:\#|[A-Za-z_][A-Za-z0-9_.@:]*)/is', $t, $m, 0, $this->pos)
                   && ($kwdef = $this->_find_formula_function($m[0]))) {
            $e = $this->_parse_function($m[0], $kwdef);
        } else if (preg_match('/\G[-A-Za-z0-9_.@]+(?!\s*\()/s', $t, $m, 0, $this->pos)) {
            $f = $this->_find_formula_field($m[0]);
            $this->pos += strlen($m[0]);
            $e = $f ? $this->_parse_field($pos1, $f) : new Constant_Fexpr($m[0], Fexpr::FUNKNOWN);
        } else if (preg_match('/\G[A-Za-z][A-Za-z0-9_.@:]*/is', $t, $m, 0, $this->pos)) {
            $e = $this->_parse_function($m[0], (object) [
                "name" => $m[0], "args" => true, "optional" => true,
                "function" => "Constant_Fexpr::make_error_call"
            ]);
        }

        if (!$e) {
            return null;
        }
        $e->set_landmark($pos1, $this->pos);

        while (true) {
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len) {
                return $e;
            }

            if (preg_match(self::BINARY_OPERATOR_REGEX, $t, $m, 0, $this->pos)) {
                $op = $m[0];
            } else if (!$in_qc && $t[$this->pos] === ":") {
                $op = ":";
            } else {
                return $e;
            }

            $opprec = self::$opprec[$op];
            if ($opprec < $level) {
                return $e;
            }

            $posx = $this->pos;
            $this->pos += strlen($op);
            $opx = self::$_oprewrite[$op] ?? $op;
            $opassoc = (self::$_oprassoc[$opx] ?? false) ? $opprec : $opprec + 1;

            ++$this->_depth;
            $e2 = $this->_parse_expr($opassoc, $in_qc);
            --$this->_depth;

            if ($op === ":" && (!$e2 || !($e2 instanceof Constant_Fexpr))) {
                $this->pos = $posx;
                return $e;
            }

            if (!$e2) {
                if ($e->format() !== Fexpr::FERROR) {
                    $this->lerror($this->pos, $this->pos, "<0>Missing expression");
                    $e = Constant_Fexpr::cerror($this->pos, $this->pos);
                }
            } else if ($opx === "<" || $opx === ">" || $opx === "<=" || $opx === ">=") {
                $e = new Inequality_Fexpr($opx, $e, $e2);
            } else if ($opx === "==" || $opx === "!=") {
                $e = new Equality_Fexpr($opx, $e, $e2);
            } else if ($opx === "&&") {
                $e = new And_Fexpr($e, $e2);
            } else if ($opx === "||") {
                $e = new Or_Fexpr($e, $e2);
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
                $e = new Pow_Fexpr($e, $e2);
            } else {
                throw new Exception("Unknown operator {$opx}");
            }
            $e->set_landmark($pos1, $this->pos);
        }
    }

    /** @param bool $in_qc
     * @return ?Fexpr */
    private function _parse_ternary($in_qc) {
        $t = $this->expression;
        $len = strlen($t);
        $pos1 = $this->pos;
        $before = count($this->_lerrors);

        if (!($ec = $this->_parse_expr(0, $in_qc))) {
            if (count($this->_lerrors) === $before) {
                $this->lerror($pos1, $pos1, "<0>Expression expected");
            }
            return null;
        }

        $this->pos = self::skip_whitespace($t, $this->pos);
        if ($this->pos === $len || $t[$this->pos] !== "?") {
            return $ec;
        }

        ++$this->pos;
        ++$this->_depth;
        $er = null;

        if (($et = $this->_parse_ternary(true))) {
            $this->pos = self::skip_whitespace($t, $this->pos);
            if ($this->pos === $len || $t[$this->pos] !== ":") {
                $this->lerror($this->pos, $this->pos, "<0>Expected ‘:’");
                $this->pos = self::span_parens_until($t, $this->pos, ":)");
            }
            if ($this->pos !== $len && $t[$this->pos] === ":") {
                ++$this->pos;
            }
            if (($ef = $this->_parse_ternary($in_qc))) {
                $er = new Ternary_Fexpr($ec, $et, $ef);
            }
        }

        --$this->_depth;
        $er = $er ?? Constant_Fexpr::cerror();
        $er->set_landmark($pos1, $this->pos);
        return $er;
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
            $t .= "assert(\$contact->contactXid === {$user->contactXid});\n  ";
            // $t .= "if (\$contact->contactXid !== {$user->contactXid}) { error_log(debug_string_backtrace()); }\n  ";
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

    /** @param int $sortable
     * @return callable(PaperInfo,?int,Contact):mixed */
    private function _compile_function($sortable) {
        if ($this->check()) {
            $state = new FormulaCompiler($this->user);
            $expr = $this->_parse->fexpr->compile($state);
            $body = self::compile_body($this->user, $state, $expr, $sortable);
        } else {
            $body = "return null;\n";
        }
        $function = "function (\$prow, \$rrow_cid, \$contact) {\n"
            . "  // " . simplify_whitespace($this->expression)
            . "\n  {$body}}";
        self::DEBUG && self::debug_report($function);
        return eval("return {$function};");
    }

    /** @return callable(PaperInfo,?int,Contact):mixed */
    function compile_function() {
        return $this->_compile_function(0);
    }

    /** @return callable(PaperInfo,?int,Contact):mixed */
    function compile_sortable_function() {
        return $this->_compile_function(1);
    }

    /** @return callable(PaperInfo,?int,Contact):mixed */
    function compile_json_function() {
        return $this->_compile_function(2);
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

    static function compile_indexes_function(Contact $user, $index_types) {
        if ($index_types === 0) {
            return null;
        }
        $state = new FormulaCompiler($user);
        $g = $state->loop_variable($index_types);
        $body = "assert(\$contact->contactXid === {$user->contactXid});\n  "
            . join("\n  ", $state->gstmt) . "\n";
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
        $function = "function (\$prow, \$contact) {\n  {$body}}";
        self::DEBUG && self::debug_report($function);
        return eval("return {$function};");
    }

    /** @return bool */
    function support_combiner() {
        if ($this->_extractorf !== null) {
            return $this->_extractorf !== false;
        } else if (!$this->check()) {
            return false;
        }
        $state = FormulaCompiler::make_combiner($this->user);
        $expr = $this->_parse->fexpr->compile($state);
        if (!$state->term_error && !$state->term_compiler->term_error) {
            $this->_extractorf = self::compile_body($this->user, $state->term_compiler, "[" . join(",", $state->term_compiler->term_list) . "]", 0);
            $this->_combinerf = self::compile_body(null, $state, $expr, 0);
            return true;
        } else {
            $this->_extractorf = $this->_combinerf = false;
            return false;
        }
    }

    function compile_extractor_function() {
        $this->support_combiner();
        $function = "function (\$prow, \$rrow_cid, \$contact) {\n"
            . "  // extractor " . simplify_whitespace($this->expression)
            . "\n  " . ($this->_extractorf ? : "return null;\n") . "}";
        self::DEBUG && self::debug_report($function);
        return eval("return {$function};");
    }

    function compile_combiner_function() {
        $this->support_combiner();
        $function = "function (\$extractor_results) {\n"
            . "  // combiner " . simplify_whitespace($this->expression)
            . "\n  " . ($this->_combinerf ? : "return null;\n") . "}";
        self::DEBUG && self::debug_report($function);
        return eval("return {$function};");
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

    /** @var array<int,true> &$ods */
    function paper_options(&$oids) {
        if ($this->check()) {
            $this->_parse->fexpr->paper_options($oids);
        }
    }

    /** @return bool */
    function viewable_by(Contact $user) {
        return $this->check($this->user ?? $user)
            && $this->_parse->fexpr->viewable_by($user);
    }

    /** @return string */
    function column_header() {
        return $this->name ? : $this->expression;
    }

    /** @return int */
    function result_format() {
        if (!$this->check()) {
            return null;
        }
        return $this->_format;
    }

    /** @return mixed */
    function result_format_detail() {
        if (!$this->check()) {
            return null;
        }
        return $this->_parse->fexpr->format_detail();
    }

    /** @return ?bool */
    function result_format_is_numeric() {
        if (!$this->check()) {
            return null;
        }
        return $this->_format === Fexpr::FNULL
            || $this->_format === Fexpr::FNUMERIC
            || ($this->_format === Fexpr::FREVIEWFIELD
                && $this->_format_detail->is_numeric());
    }

    /** @return string */
    function result_format_description() {
        if (!$this->check()) {
            return "error";
        }
        return $this->_parse->fexpr->format_description();
    }

    /** @return ValueFormat */
    function value_format() {
        require_once(__DIR__ . "/valueformat.php");
        if ($this->_value_format !== null) {
            return $this->_value_format;
        }
        if (!$this->check()) {
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
        return $this->check()
            && ($this->_parse->fexpr->op === "sum"
                || $this->_parse->fexpr->op === "count");
    }

    /** @return bool */
    function indexed() {
        return $this->check() && $this->_parse->indexed;
    }

    /** @return int */
    function index_type() {
        return $this->check() ? $this->_parse->index_type : 0;
    }

    #[\ReturnTypeWillChange]
    /** @return array<string,mixed> */
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
