<?php
// formula.php -- HotCRP helper class for paper expressions
// Copyright (c) 2009-2018 Eddie Kohler; see LICENSE.

class FormulaCall {
    public $name;
    public $text;
    public $args = [];
    public $modifier = false;
    public $kwdef;

    function __construct($kwdef, $name) {
        $this->name = $kwdef->name;
        $this->text = $name;
        $this->kwdef = $kwdef;
    }
}

class Fexpr_Error {
    public $expr;
    public $error_html;
    function __construct($expr, $error_html) {
        $this->expr = $expr;
        $this->error_html = $error_html;
    }
}

class Fexpr implements JsonSerializable {
    public $op;
    public $args = array();
    public $text;
    public $format_ = false;
    public $left_landmark;
    public $right_landmark;

    const ASUBREV = 1;
    const APREF = 2;
    const APCCANREV = 4;
    const ACONF = 8;

    const LNONE = 0;
    const LMY = 1;
    const LALL = 2;

    const FBOOL = 1;
    const FROUND = 2;
    const FREVTYPE = 3;
    const FDECISION = 4;
    const FPREFEXPERTISE = 5;
    const FREVIEWER = 6;
    const FTAG = 7; // used in formulagraph.php

    function __construct($op = null) {
        $this->op = $op;
        if ($this->op === "trunc")
            $this->op = "floor";
        $args = func_get_args();
        if (count($args) == 2 && is_array($args[1]))
            $this->args = $args[1];
        else if (count($args) > 1)
            $this->args = array_slice($args, 1);
    }
    function add($x) {
        $this->args[] = $x;
    }
    function set_landmark($left, $right) {
        $this->left_landmark = $left;
        $this->right_landmark = $right;
    }
    static function make(FormulaCall $ff) {
        return new Fexpr($ff->name, $ff->args);
    }

    function format() {
        return $this->format_;
    }
    function format_comparator($cmp, Conf $conf, $other_expr = null) {
        if ($this->format_
            && $this->format_ instanceof ReviewField
            && $this->format_->option_letter
            && !$conf->opt("smartScoreCompare")
            && (!$other_expr
                || $other_expr->format() === $this->format_)) {
            if ($cmp[0] == "<")
                return ">" . substr($cmp, 1);
            if ($cmp[0] == ">")
                return "<" . substr($cmp, 1);
        }
        return $cmp;
    }
    function is_null() {
        return false;
    }

    function typecheck_format() {
        if ($this->op === "greatest" || $this->op === "least"
            || $this->op === "?:" || $this->op === "&&" || $this->op === "||") {
            $format = false;
            for ($i = ($this->op === "?:" ? 1 : 0); $i < count($this->args); ++$i) {
                $a = $this->args[$i];
                if ($a instanceof Fexpr && $a->is_null())
                    continue;
                $f = $a instanceof Fexpr ? $a->format() : false;
                if ($f !== false && ($format === false || $format === $f))
                    $format = $f;
                else
                    $format = null;
            }
            return $format ? : null;
        } else if (preg_match(',\A(?:[<>=!]=?|≤|≥|≠)\z,', $this->op))
            return self::FBOOL;
        else
            return null;
    }

    function typecheck_arguments(Conf $conf) {
        foreach ($this->args as $a)
            if ($a instanceof Fexpr && ($x = $a->typecheck($conf)))
                return $x;
        if ($this->format_ === false)
            $this->format_ = $this->typecheck_format();
        return false;
    }

    function typecheck(Conf $conf) {
        // comparison operators help us resolve
        if (preg_match(',\A(?:[<>=!]=?|≤|≥|≠)\z,', $this->op)
            && count($this->args) === 2) {
            list($a0, $a1) = $this->args;
            if ($a0 instanceof ConstantFexpr)
                $a0->typecheck_neighbor($conf, $a1);
            if ($a1 instanceof ConstantFexpr)
                $a1->typecheck_neighbor($conf, $a0);
        }
        if (($x = $this->typecheck_arguments($conf)))
            return $x;
        if (preg_match(',\A(?:[<>][<>=]?|≤|≥|\*\*|[-+*/%&^|]|greatest|least|log|sqrt|exp|round|floor|ceil)\z,', $this->op)) {
            foreach ($this->args as $a)
                if ($a->format_ === self::FREVIEWER)
                    return new Fexpr_Error($a, "reviewers can’t be used in math expressions");
        }
        return false;
    }

    function view_score(Contact $user) {
        if ($this->op == "?:") {
            $t = $this->args[0]->view_score($user);
            $tt = $this->args[1]->view_score($user);
            $tf = $this->args[2]->view_score($user);
            return min($t, max($tt, $tf));
        } else if ($this->op == "||")
            return $this->args[0]->view_score($user);
        else {
            $score = VIEWSCORE_AUTHOR;
            foreach ($this->args as $e)
                if ($e instanceof Fexpr)
                    $score = min($score, $e->view_score($user));
            return $score;
        }
    }

    static function cast_bool($t) {
        return "($t !== null ? (bool) $t : null)";
    }

    function compile(FormulaCompiler $state) {
        $op = $this->op;
        if ($op == "?:") {
            $t = $state->_addltemp($this->args[0]->compile($state));
            $tt = $state->_addltemp($this->args[1]->compile($state));
            $tf = $state->_addltemp($this->args[2]->compile($state));
            return "($t ? $tt : $tf)";
        }

        if (count($this->args) == 1 && isset(Formula::$opprec["u$op"])) {
            $t = $state->_addltemp($this->args[0]->compile($state));
            return "$op$t";
        }

        if (count($this->args) == 2 && isset(Formula::$opprec[$op])) {
            $t1 = $state->_addltemp($this->args[0]->compile($state));
            $t2 = $state->_addltemp($this->args[1]->compile($state));
            if ($op == "&&")
                return "($t1 ? $t2 : $t1)";
            else if ($op == "||")
                return "($t1 ? : $t2)";
            else if ($op == "/" || $op == "%")
                return "($t1 !== null && $t2 ? $t1 $op $t2 : null)";
            else if ($op == "**")
                return "($t1 !== null && $t2 !== null ? pow($t1, $t2) : null)";
            else {
                if (Formula::$opprec[$op] == 8)
                    $op = $this->args[0]->format_comparator($op, $state->conf, $this->args[1]);
                return "($t1 !== null && $t2 !== null ? $t1 $op $t2 : null)";
            }
        }

        if ($op == "greatest" || $op == "least") {
            $cmp = $this->format_comparator($op == "greatest" ? ">" : "<", $state->conf);
            $t1 = $state->_addltemp($this->args[0]->compile($state), true);
            for ($i = 1; $i < count($this->args); ++$i) {
                $t2 = $state->_addltemp($this->args[$i]->compile($state));
                $state->lstmt[] = "$t1 = ($t1 === null || ($t2 !== null && $t2 $cmp $t1) ? $t2 : $t1);";
            }
            return $t1;
        }

        if (count($this->args) >= 1 && $op == "log") {
            $t1 = $state->_addltemp($this->args[0]->compile($state));
            if (count($this->args) == 2) {
                $t2 = $state->_addltemp($this->args[1]->compile($state));
                return "($t1 !== null && $t2 !== null ? log($t1, $t2) : null)";
            } else
                return "($t1 !== null ? log($t1) : null)";
        }

        if (count($this->args) >= 1 && ($op == "sqrt" || $op == "exp")) {
            $t1 = $state->_addltemp($this->args[0]->compile($state));
            return "($t1 !== null ? $op($t1) : null)";
        }

        if (count($this->args) >= 1 && ($op == "round" || $op == "floor" || $op == "ceil")) {
            $t1 = $state->_addltemp($this->args[0]->compile($state));
            if (count($this->args) == 1)
                return "($t1 !== null ? $op($t1) : null)";
            else {
                $t2 = $state->_addltemp($this->args[1]->compile($state));
                return "($t1 !== null && $t2 ? $op($t1 / $t2) * $t2 : null)";
            }
        }

        return "null";
    }

    function can_combine() {
        foreach ($this->args as $e)
            if (!$e->can_combine())
                return false;
        return true;
    }

    function matches_at_most_once() {
        if ($this->op === "?:")
            return $this->args[0]->matches_at_most_once() && $this->args[2]->is_null();
        if ($this->op === "&&")
            return $this->args[0]->matches_at_most_once() || $this->args[1]->matches_at_most_once();
        return false;
    }

    function compile_fragments(FormulaCompiler $state) {
        foreach ($this->args as $e)
            if ($e instanceof Fexpr)
                $e->compile_fragments($state);
    }

    function jsonSerialize() {
        if ($this->op)
            $x = ["op" => $this->op];
        else
            $x = ["type" => get_class($this)];
        foreach ($this->args as $e)
            $x["args"][] = $e->jsonSerialize();
        return $x;
    }
}

class ConstantFexpr extends Fexpr {
    private $x;
    function __construct($x, $format = null) {
        parent::__construct("");
        $this->x = $x;
        $this->format_ = $format;
    }
    function is_null() {
        return $this->x === "null";
    }
    private function _check_revtype() {
        $rsm = new ReviewSearchMatcher;
        if ($rsm->apply_review_type($this->x, true)) {
            $this->x = $rsm->review_type();
            return true;
        } else
            return false;
    }
    function typecheck(Conf $conf) {
        if ($this->format_ === self::FREVTYPE && is_string($this->x)
            && !$this->_check_revtype())
            return new Fexpr_Error($this, "unknown review type “" . htmlspecialchars($this->x) . "”");
        if ($this->format_ !== false)
            return false;
        return new Fexpr_Error($this, "“" . htmlspecialchars($this->x) . "” undefined");
    }
    function typecheck_neighbor(Conf $conf, $e) {
        if ($this->format_ !== false || !($e instanceof Fexpr)
            || $e->typecheck($conf))
            return;
        $format = $e->format();
        $letter = "";
        if (strlen($this->x) == 1 && ctype_alpha($this->x))
            $letter = strtoupper($this->x);
        if ($format === self::FPREFEXPERTISE && $letter >= "X" && $letter <= "Z")
            $this->x = 89 - ord($letter);
        else if ($format instanceof ReviewField && $letter
                 && ($x = $format->parse_value($letter, true)))
            $this->x = $x;
        else if ($format === self::FROUND
                 && ($round = $conf->round_number($this->x, false)) !== false)
            $this->x = $round;
        else if ($format === self::FREVTYPE && $this->_check_revtype())
            /* OK */;
        else
            return;
        $this->format_ = $format;
    }
    function compile(FormulaCompiler $state) {
        return $this->x;
    }
    function jsonSerialize() {
        if ($this->x === "null")
            return null;
        else if ($this->x === "true")
            return true;
        else if ($this->x === "false")
            return false;
        else if (is_numeric($this->x))
            return (float) $this->x;
        else
            return $this->x;
    }
    static function cnull() {
        return new ConstantFexpr("null");
    }
    static function czero() {
        return new ConstantFexpr("0");
    }
    static function cfalse() {
        return new ConstantFexpr("false", self::FBOOL);
    }
    static function ctrue() {
        return new ConstantFexpr("true", self::FBOOL);
    }
}

class NegateFexpr extends Fexpr {
    function __construct(Fexpr $e) {
        parent::__construct("!", $e);
        $this->format_ = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "!$t";
    }
}

class InFexpr extends Fexpr {
    private $values;
    function __construct(Fexpr $e, array $values) {
        parent::__construct("in", $e);
        $this->values = $values;
        $this->format_ = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "(array_search($t, [" . join(", ", $this->values) . "]) !== false)";
    }
}

class AggregateFexpr extends Fexpr {
    function __construct($fn, array $values) {
        parent::__construct($fn, $values);
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (($ff->name === "variance" || $ff->name === "stddev")
            && !$ff->modifier
            && strpos($ff->text, "_") === false
            && ($arg === ".p" || $arg === ".pop" || $arg === ".s" || $arg === ".samp")) {
            $ff->modifier = true;
            if ($arg === ".p" || $arg === ".pop")
                $ff->name .= "_pop";
            return true;
        } else
            return false;
    }
    static function make(FormulaCall $ff) {
        $op = $ff->name;
        if (($op === "min" || $op === "max")
            && count($ff->args) > 1)
            return new Fexpr($op === "min" ? "least" : "greatest", $ff->args);
        if ($op === "wavg" && count($ff->args) == 1)
            $op = "avg";
        $arg_count = 1;
        if ($op === "atminof" || $op === "atmaxof"
            || $op === "argmin" || $op === "argmax"
            || $op === "wavg" || $op === "quantile")
            $arg_count = 2;
        if (count($ff->args) != $arg_count)
            return null;
        if ($op === "atminof" || $op === "atmaxof") {
            $op = "arg" . substr($op, 2, 3);
            $ff->args = [$ff->args[1], $ff->args[0]];
        }
        return new AggregateFexpr($op, $ff->args);
    }

    function typecheck_format() {
        if ($this->op === "all" || $this->op === "any")
            return self::FBOOL;
        else if (($this->op === "min" || $this->op === "max"
                  || $this->op === "argmin" || $this->op === "argmax")
                 && $this->args[0] instanceof Fexpr)
            return $this->args[0]->format();
        else if (($this->op === "avg" || $this->op === "wavg"
                  || $this->op === "median" || $this->op === "quantile")
                 && $this->args[0] instanceof Fexpr) {
            $f = $this->args[0]->format();
            return $f === self::FBOOL ? null : $f;
        } else
            return null;
    }

    function typecheck(Conf $conf) {
        if (($x = $this->typecheck_arguments($conf)))
            return $x;
        if ($this->op !== "argmin" && $this->op !== "argmax"
            && $this->args[0] instanceof Fexpr
            && $this->args[0]->format() === self::FREVIEWER)
            return new Fexpr_Error($this->args[0], "reviewers can’t be used in math expressions");
        if (count($this->args) > 1
            && $this->args[1] instanceof Fexpr
            && $this->args[1]->format() === self::FREVIEWER)
            return new Fexpr_Error($this->args[1], "reviewers can’t be used in math expressions");
        return false;
    }

    static function quantile($a, $p) {
        // The “R-7” quantile implementation
        if (count($a) === 0 || $p < 0 || $p > 1)
            return null;
        sort($a, SORT_NUMERIC);
        $ix = (count($a) - 1) * $p + 1;
        $i = (int) $ix;
        $v = $a[$i - 1];
        if (($e = $ix - $i))
            $v += $e * ($a[$i] - $v);
        return $v;
    }

    private function loop_info(FormulaCompiler $state) {
        if ($this->op === "all")
            return ["null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", self::cast_bool("~x~")];
        else if ($this->op === "any")
            return ["null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", self::cast_bool("~x~")];
        else if ($this->op === "some")
            return ["null", "~r~ = ~l~;
  if (~r~ !== null && ~r~ !== false)
    break;"];
        else if ($this->op === "min" || $this->op === "max") {
            $cmp = $this->format_comparator($this->op === "min" ? "<" : ">", $state->conf);
            return ["null", "(~l~ !== null && (~r~ === null || ~l~ $cmp ~r~) ? ~l~ : ~r~)"];
        } else if ($this->op == "argmin" || $this->op == "argmax") {
            $cmp = $this->args[1]->format_comparator($this->op == "argmin" ? "<" : ">", $state->conf);
            return ["[null, [null]]",
"if (~l1~ !== null && (~r~[0] === null || ~l1~ $cmp ~r~[0])) {
  ~r~[0] = ~l1~;
  ~r~[1] = [~l~];
} else if (~l1~ !== null && ~l1~ == ~r~[0])
  ~r~[1][] = ~l~;",
                    "~x~[1][count(~x~[1]) > 1 ? mt_rand(0, count(~x~[1]) - 1) : 0]"];
        } else if ($this->op === "count")
            return ["0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)"];
        else if ($this->op === "sum")
            return ["null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)"];
        else if ($this->op === "avg")
            return ["[0, 0]", "(~l~ !== null ? [~r~[0] + ~l~, ~r~[1] + 1] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        else if ($this->op === "median" || $this->op === "quantile") {
            if ($this->op === "median")
                $q = "0.5";
            else {
                $q = $state->_addltemp($this->args[1]->compile($state));
                if ($this->format_comparator("<", $state->conf) == ">")
                    $q = "1 - $q";
            }
            return ["[]", "if (~l~ !== null)\n  array_push(~r~, ~l~);",
                    "AggregateFexpr::quantile(~x~, $q)"];
        } else if ($this->op === "wavg")
            return ["[0, 0]", "(~l~ !== null && ~l1~ !== null ? [~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        else if ($this->op === "stddev" || $this->op === "stddev_pop"
                 || $this->op === "variance" || $this->op === "variance_pop") {
            if ($this->op === "variance")
                $x = "(~x~[2] > 1 ? ~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1)) : (~x~[2] ? 0 : null))";
            else if ($this->op === "variance_pop")
                $x = "(~x~[2] ? ~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2]) : null)";
            else if ($this->op === "stddev")
                $x = "(~x~[2] > 1 ? sqrt(~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1))) : (~x~[2] ? 0 : null))";
            else
                $x = "(~x~[2] ? sqrt(~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2])) : null)";
            return ["[0, 0, 0]", "(~l~ !== null ? [~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1] : ~r~)", $x];
        }
        return null;
    }

    function compile(FormulaCompiler $state) {
        if ($this->op == "my")
            return $state->_compile_my($this->args[0]);
        if (($li = $this->loop_info($state))) {
            $t = $state->_compile_loop($li[0], $li[1], $this);
            return get($li, 2) ? str_replace("~x~", $t, $li[2]) : $t;
        }
        return "null";
    }

    function can_combine() {
        if ($this->op == "my")
            return $this->args[0]->can_combine();
        if ($this->op == "quantile")
            return $this->args[1]->can_combine();
        return true;
    }

    function compile_fragments(FormulaCompiler $state) {
        foreach ($this->args as $i => $e)
            if (($i == 0 && $this->op == "my")
                || ($i == 1 && $this->op == "quantile"))
                $e->compile_fragments($state);
            else
                $state->fragments[] = $e->compile($state);
    }
}

class Sub_Fexpr extends Fexpr {
    function can_combine() {
        return false;
    }
}

class Pid_Fexpr extends Sub_Fexpr {
    function __construct() {
        parent::__construct("");
    }
    function compile(FormulaCompiler $state) {
        return '$prow->paperId';
    }
}

class PdfSize_Fexpr extends Sub_Fexpr {
    function __construct() {
        parent::__construct("");
    }
    function compile(FormulaCompiler $state) {
        return '($contact->can_view_pdf($prow) ? (int) $prow->size : null)';
    }
}

class Author_Fexpr extends Sub_Fexpr {
    private $matchtype;
    private $matchidx;
    static private $matchers = [];
    function __construct(FormulaCall $ff, Formula $formula) {
        if ($ff->modifier === "none")
            $this->matchtype = $ff->modifier;
        else if (is_array($ff->modifier) && $ff->modifier[0] == $formula->user->contactId)
            $this->matchtype = $formula->user->contactId;
        else if (is_array($ff->modifier) || is_object($ff->modifier)) {
            self::$matchers[] = $ff->modifier;
            $this->matchtype = "m";
            $this->matchidx = count(self::$matchers) - 1;
        }
    }
    static function parse_modifier(FormulaCall $ff, $arg, $rest, Formula $formula) {
        if ($ff->modifier === false && !str_starts_with($arg, ".")) {
            if (str_starts_with($arg, ":"))
                $arg = substr($arg, 1);
            $csm = new ContactSearch(ContactSearch::F_TAG, $arg, $formula->user);
            if ($csm->ids !== false)
                $ff->modifier = $csm->ids;
            else if (!str_starts_with($arg, "#"))
                $ff->modifier = Text::star_text_pregexes($arg);
            else
                return false;
            return true;
        } else
            return false;
    }
    function compile(FormulaCompiler $state) {
        if ($this->matchtype === null)
            $v = 'count($prow->author_list())';
        else if ($this->matchtype === "none")
            $v = '!$prow->author_list()';
        else if (is_int($this->matchtype))
            // can always see if you are an author
            return '($prow->has_author(' . $this->matchtype . ') ? 1 : 0)';
        else
            $v = 'Author_Fexpr::count_matches($prow, ' . $this->matchidx . ')';
        return '($contact->allow_view_authors($prow) ? ' . $v . ' : null)';
    }
    static function count_matches(PaperInfo $prow, $matchidx) {
        $mf = self::$matchers[$matchidx];
        $n = 0;
        if (is_array($mf)) {
            foreach ($prow->contacts() as $cid => $x)
                if (array_search($cid, $mf) !== false)
                    ++$n;
        } else {
            foreach ($prow->author_list() as $au) {
                $text = $au->name_email_aff_text();
                if (Text::match_pregexes($mf, $text, UnicodeHelper::deaccent($text)))
                    ++$n;
            }
        }
        return $n;
    }
}

class Score_Fexpr extends Sub_Fexpr {
    private $field;
    function __construct(ReviewField $field) {
        parent::__construct("rf");
        $this->field = $this->format_ = $field;
    }
    function view_score(Contact $user) {
        return $this->field->view_score;
    }
    function compile(FormulaCompiler $state) {
        if ($this->field->view_score <= $state->user->permissive_view_score_bound())
            return "null";
        $state->datatype |= Fexpr::ASUBREV;
        $fid = $this->field->id;
        $state->_ensure_rrow_score($fid);
        $rrow = $state->_rrow();
        return "({$rrow} && isset({$rrow}->$fid) && {$rrow}->$fid ? {$rrow}->$fid : null)";
    }
}

class Pref_Fexpr extends Sub_Fexpr {
    private $isexpertise;
    function __construct($isexpertise) {
        $this->isexpertise = is_object($isexpertise) ? $isexpertise->kwdef->is_expertise : $isexpertise;
        $this->format_ = $this->isexpertise ? self::FPREFEXPERTISE : null;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        if (!$state->user->is_reviewer())
            return "null";
        $state->queryOptions["allReviewerPreference"] = true;
        $state->datatype |= self::APREF;
        return "get(get(" . $state->_add_review_prefs() . ", " . $state->_rrow_cid()
            . "), " . ($this->isexpertise ? 1 : 0) . ")";
    }
}

class Tag_Fexpr extends Sub_Fexpr {
    private $tag;
    private $isvalue;
    function __construct($tag, $isvalue) {
        $this->tag = $tag;
        $this->isvalue = $isvalue;
        $this->format_ = $isvalue ? null : self::FBOOL;
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!$ff->args && $arg[0] !== ".") {
            $ff->args[] = substr($arg, 1);
            return true;
        } else if (count($ff->args) === 1 && $arg[0] === ":") {
            $ff->args[0] .= $arg;
            return true;
        } else
            return false;
    }
    static function make(FormulaCall $ff) {
        if (count($ff->args) !== 1
            || !preg_match('/\A' . TAG_REGEX . '\z/', $ff->args[0]))
            return null;
        return new Tag_Fexpr($ff->args[0], $ff->kwdef->is_value);
    }
    function view_score(Contact $user) {
        $tagger = new Tagger($user);
        $e_tag = $tagger->check($this->tag, Tagger::ALLOWSTAR);
        return $tagger->view_score($e_tag, $user);
    }
    function tag() {
        return $this->tag;
    }
    function compile(FormulaCompiler $state) {
        $e_tag = $state->tagger->check($this->tag, Tagger::ALLOWSTAR);
        $t_tagval = $state->_add_tagval($e_tag);
        if ($this->isvalue)
            return $t_tagval;
        else
            return "($t_tagval !== (float) 0 ? $t_tagval : true)";
    }
}

class FirstTag_Fexpr extends Sub_Fexpr {
    private $tags;
    function __construct($tags) {
        $this->tags = $tags;
        $this->format_ = Fexpr::FTAG;
    }
    static function make(FormulaCall $ff) {
        $ts = [];
        foreach ($ff->args as $arg) {
            if ($arg instanceof Tag_Fexpr)
                $ts[] = $arg->tag();
            else if ($arg instanceof FirstTag_Fexpr)
                $ts = array_merge($ts, $arg->tags);
            else
                return null; /* XXX error message */
        }
        return new FirstTag_Fexpr($ts);
    }
    function view_score(Contact $user) {
        $tagger = new Tagger($user);
        $vs = VIEWSCORE_MAX;
        foreach ($this->tags as $t) {
            $e_tag = $tagger->check($t);
            $vs = min($vs, $tagger->view_score($e_tag, $user));
        }
        return $vs;
    }
    function compile(FormulaCompiler $state) {
        $v = "null";
        foreach (array_reverse($this->tags) as $t) {
            $e_tag = $state->tagger->check($t);
            $t_tagpos = $state->_add_tagpos($e_tag);
            $v = "($t_tagpos !== false ? " . $state->known_tag_index($e_tag) . " : $v)";
        }
        return $v;
    }
}

class Option_Fexpr extends Sub_Fexpr {
    private $option;
    function __construct(PaperOption $option) {
        $this->option = $this->format_ = $option;
        if ($this->option->type === "checkbox")
            $this->format_ = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $ovar = "\$opt" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($ovar)) {
            $state->queryOptions["options"] = true;
            $state->gstmt[] = "if (\$contact->can_view_paper_option(\$prow, $id)) {";
            $state->gstmt[] = "  $ovar = \$prow->option($id);";
            if ($this->option->type == "checkbox")
                $state->gstmt[] = "  $ovar = !!($ovar && {$ovar}->value);";
            else
                $state->gstmt[] = "  $ovar = $ovar ? {$ovar}->value : null;";
            $state->gstmt[] = "} else\n    $ovar = null;";
        }
        return $ovar;
    }
}

class Decision_Fexpr extends Sub_Fexpr {
    function __construct() {
        $this->format_ = self::FDECISION;
    }
    function view_score(Contact $user) {
        if ($user->can_view_some_decision_as_author())
            return VIEWSCORE_AUTHOR;
        else if ($user->conf->timePCViewDecision(false))
            return VIEWSCORE_PC;
        else
            return VIEWSCORE_ADMINONLY;
    }
    function compile(FormulaCompiler $state) {
        if ($state->check_gvar('$decision'))
            $state->gstmt[] = "\$decision = \$contact->can_view_decision(\$prow) ? (int) \$prow->outcome : 0;";
        return '$decision';
    }
}

class TimeField_Fexpr extends Sub_Fexpr {
    private $field;
    function __construct($field) {
        $this->field = $field;
    }
    function compile(FormulaCompiler $state) {
        return "((int) \$prow->" . $this->field . ")";
    }
}

class TopicScore_Fexpr extends Sub_Fexpr {
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= Fexpr::APCCANREV;
        $state->queryOptions["topics"] = true;
        if ($state->looptype == self::LMY)
            return $state->define_gvar("mytopicscore", "\$prow->topic_interest_score(\$contact)");
        else
            return "\$prow->topic_interest_score(" . $state->_rrow_cid() . ")";
    }
}

class Revtype_Fexpr extends Sub_Fexpr {
    function __construct() {
        $this->format_ = self::FREVTYPE;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        if ($state->looptype == self::LMY)
            $rt = $state->define_gvar("myrevtype", "\$prow->review_type(\$contact)");
        else {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["reviewSignatures"] = true;
            $rrow = $state->_rrow();
            return "($rrow ? {$rrow}->reviewType : null)";
        }
        return $rt;
    }
}

class ReviewRound_Fexpr extends Sub_Fexpr {
    function __construct() {
        $this->format_ = self::FROUND;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        $rrow = $state->_rrow();
        if ($state->looptype == self::LMY)
            return $state->define_gvar("myrevround", "{$rrow} ? {$rrow}->reviewRound : null");
        else {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["reviewSignatures"] = true;
            return "($rrow ? {$rrow}->reviewRound : null)";
        }
        return $rt;
    }
}

class Conflict_Fexpr extends Sub_Fexpr {
    private $ispc;
    function __construct($ispc) {
        $this->ispc = is_object($ispc) ? $ispc->kwdef->is_pc : $ispc;
        $this->format_ = self::FBOOL;
    }
    function compile(FormulaCompiler $state) {
        // XXX the actual search is different
        $state->datatype |= self::ACONF;
        $idx = $state->_rrow_cid();
        if ($state->looptype == self::LMY) {
            $rt = "\$prow->has_conflict($idx)";
        } else {
            $rt = "!!get(" . $state->_add_conflicts() . ", " . $idx . ")";
            if ($this->ispc)
                $rt = "(get(" . $state->_add_pc() . ", " . $idx . ") ? $rt : null)";
        }
        return $rt;
    }
}

class Review_Fexpr extends Sub_Fexpr {
    function view_score(Contact $user) {
        if (!$user->conf->setting("rev_blind"))
            return VIEWSCORE_AUTHOR;
        else if ($user->conf->setting("pc_seeblindrev"))
            return VIEWSCORE_REVIEWERONLY;
        else
            return VIEWSCORE_PC;
    }
}

class Reviewer_Fexpr extends Review_Fexpr {
    function __construct() {
        $this->format_ = Fexpr::FREVIEWER;
    }
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        $state->queryOptions["reviewSignatures"] = true;
        return '($prow->can_view_review_identity_of(' . $state->_rrow_cid() . ', $contact) ? ' . $state->_rrow_cid() . ' : null)';
    }
}

class ReviewerMatch_Fexpr extends Review_Fexpr {
    private $user;
    private $arg;
    private $flags;
    private $istag;
    private $csearch;
    private static $tagmap = array();
    private static $tagmap_conf = null;
    function __construct(Contact $user, $arg) {
        $this->user = $user;
        $this->format_ = self::FBOOL;
        $this->arg = $arg;
        $this->istag = $arg[0] === "#" || ($arg[0] !== "\"" && $user->conf->pc_tag_exists($arg));
        $flags = ContactSearch::F_USER;
        if ($user->can_view_reviewer_tags())
            $flags |= ContactSearch::F_TAG;
        if ($arg[0] === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $arg = str_replace("\"", "", $arg);
        }
        $this->csearch = new ContactSearch($flags, $arg, $user);
    }
    function view_score(Contact $user) {
        return $this->istag ? VIEWSCORE_PC : parent::view_score($user);
    }
    function compile(FormulaCompiler $state) {
        assert($state->user === $this->user);
        if (!$this->csearch->ids)
            return "null";
        $state->datatype |= self::ASUBREV;
        $state->queryOptions["reviewSignatures"] = true;
        if ($this->istag) {
            $cvt = $state->define_gvar('can_view_reviewer_tags', '$contact->can_view_reviewer_tags($prow)');
            $tag = $this->arg[0] === "#" ? substr($this->arg, 1) : $this->arg;
            return "($cvt ? ReviewerMatch_Fexpr::check_tagmap(\$contact->conf, " . $state->_rrow_cid() . ", " . json_encode($tag) . ") : null)";
        } else
            return '($prow->can_view_review_identity_of(' . $state->_rrow_cid() . ', $contact) ? array_search(' . $state->_rrow_cid() . ", [" . join(", ", $this->csearch->ids) . "]) !== false : null)";
    }
    function matches_at_most_once() {
        return count($this->csearch->ids) <= 1;
    }
    static function check_tagmap(Conf $conf, $cid, $tag) {
        if ($conf !== self::$tagmap_conf) {
            self::$tagmap = [];
            self::$tagmap_conf = $conf;
        }
        if (($a = get(self::$tagmap, $tag)) === null) {
            $a = array();
            foreach ($conf->pc_members() as $pc)
                if (($v = $pc->tag_value($tag)) !== false)
                    $a[$pc->contactId] = $v ? : true;
            self::$tagmap[$tag] = $a;
        }
        return get($a, $cid) ? : false;
    }
}

class ReviewWordCount_Fexpr extends Sub_Fexpr {
    function view_score(Contact $user) {
        return VIEWSCORE_PC;
    }
    function compile(FormulaCompiler $state) {
        if ($state->looptype != self::LMY) {
            $view_score = $state->user->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
        }
        $state->datatype |= self::ASUBREV;
        $state->_ensure_review_word_counts();
        $rrow = $state->_rrow();
        return "($rrow ? {$rrow}->reviewWordCount : null)";
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
    public $looptype;
    public $datatype;
    public $all_datatypes = 0;
    private $lprefix;
    private $maxlprefix;
    public $indent = 2;
    public $queryOptions = array();
    public $known_tag_indexes = [];
    public $tagrefs = null;
    private $_stack;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->tagger = new Tagger($user);
        $this->clear();
    }

    function clear() {
        $this->gvar = $this->g0stmt = $this->gstmt = $this->lstmt = [];
        $this->looptype = Fexpr::LNONE;
        $this->datatype = 0;
        $this->lprefix = 0;
        $this->maxlprefix = 0;
        $this->_stack = array();
    }

    function check_gvar($gvar) {
        if (get($this->gvar, $gvar))
            return false;
        else {
            $this->gvar[$gvar] = $gvar;
            return true;
        }
    }
    function define_gvar($name, $expr) {
        if (preg_match(',\A\$?(.*[^A-Ya-z0-9_].*)\z,', $name, $m))
            $name = '$' . preg_replace_callback(',[^A-Ya-z0-9_],', function ($m) { return "Z" . dechex(ord($m[0])); }, $m[1]);
        else
            $name = $name[0] == "$" ? $name : '$' . $name;
        if (get($this->gvar, $name) === null) {
            $this->gstmt[] = "$name = $expr;";
            $this->gvar[$name] = $name;
        }
        return $name;
    }

    function _add_pc_can_review() {
        if ($this->check_gvar('$pc_can_review'))
            $this->gstmt[] = "\$pc_can_review = \$prow->pc_can_become_reviewer();";
        return '$pc_can_review';
    }
    function _add_vsreviews() {
        if ($this->check_gvar('$vsreviews')) {
            $this->queryOptions["reviewSignatures"] = true;
            $this->gstmt[] = "\$vsreviews = \$prow->viewable_submitted_reviews_by_user(\$contact);";
        }
        return '$vsreviews';
    }
    function _add_review_prefs() {
        if ($this->check_gvar('$allrevprefs')) {
            $this->queryOptions["allReviewerPreference"] = true;
            $this->gstmt[] = "\$allrevprefs = \$contact->can_view_review(\$prow, null) ? \$prow->reviewer_preferences() : [];";
        }
        return '$allrevprefs';
    }
    function _add_conflicts() {
        if ($this->check_gvar('$conflicts')) {
            $this->queryOptions["allConflictType"] = true;
            $this->gstmt[] = "\$conflicts = \$contact->can_view_conflicts(\$prow) ? \$prow->conflicts() : [];";
        }
        return '$conflicts';
    }
    function _add_pc() {
        if ($this->check_gvar('$pc'))
            $this->gstmt[] = "\$pc = \$contact->conf->pc_members();";
        return '$pc';
    }
    function _add_tagpos($tag) {
        if ($tag === false)
            return "false";
        if ($this->check_gvar('$tags')) {
            $this->queryOptions["tags"] = true;
            $this->gstmt[] = "\$tags = \$contact->can_view_tags(\$prow) ? \$prow->all_tags_text() : \"\";";
        }
        if (!isset($this->known_tag_indexes[$tag])) {
            $this->tagrefs[] = $tag;
            $this->known_tag_indexes[$tag] = count($this->tagrefs) - 1;
        }
        if (strpos($tag, "*") === false)
            $e = "stripos(\$tags, \" $tag#\")";
        else
            $e = "preg_matchpos(\"{ " . str_replace("\\*", "[^#\\s]*", preg_quote($tag)) . "#}i\", \$tags)";
        return $this->define_gvar("tagpos_$tag", $e);
    }
    function _add_tagval($tag) {
        if ($tag === false)
            return "false";
        $t_tagpos = $this->_add_tagpos($tag);
        if (strpos($tag, "*") === false)
            $delta = "$t_tagpos + " . (strlen($tag) + 2);
        else
            $delta = "strpos(\$tags, \"#\", $t_tagpos) + 1";
        return $this->define_gvar("tagval_$tag", "($t_tagpos !== false ? (float) substr(\$tags, $delta) : false)");
    }
    function known_tag_index($tag) {
        return $tag === false ? -1 : get($this->known_tag_indexes, $tag);
    }

    function _rrow_cid() {
        if ($this->looptype == Fexpr::LNONE)
            return '$rrow_cid';
        else if ($this->looptype == Fexpr::LMY)
            return (string) $this->user->contactId;
        else
            return '~i~';
    }
    function _rrow() {
        if ($this->looptype == Fexpr::LNONE)
            return $this->define_gvar("rrow", "\$prow->review_of_user(\$rrow_cid)");
        else if ($this->looptype == Fexpr::LMY)
            return $this->define_gvar("myrrow", "\$prow->review_of_user(" . $this->user->contactId . ")");
        else {
            $this->_add_vsreviews();
            return 'get($vsreviews, ~i~)';
        }
    }
    function _ensure_rrow_score($fid) {
        if (!isset($this->queryOptions["scores"]))
            $this->queryOptions["scores"] = array();
        $this->queryOptions["scores"][$fid] = $fid;
        if ($this->check_gvar('$ensure_score_' . $fid))
            $this->g0stmt[] = '$prow->ensure_review_score("' . $fid . '");';
    }
    function _ensure_review_word_counts() {
        $this->queryOptions["reviewWordCounts"] = true;
        if ($this->check_gvar('$ensure_reviewWordCounts'))
            $this->g0stmt[] = '$prow->ensure_review_word_counts();';
    }

    private function _push() {
        $this->_stack[] = array($this->lprefix, $this->lstmt, $this->looptype, $this->datatype);
        $this->lprefix = ++$this->maxlprefix;
        $this->lstmt = array();
        $this->looptype = Fexpr::LNONE;
        $this->datatype = 0;
        $this->indent += 2;
        return $this->lprefix;
    }
    private function _pop($content) {
        $this->all_datatypes |= $this->datatype;
        list($this->lprefix, $this->lstmt, $this->looptype, $this->datatype) = array_pop($this->_stack);
        $this->indent -= 2;
        $this->lstmt[] = $content;
    }
    function _addltemp($expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
            return $expr;
        $tname = "\$t" . $this->lprefix . "_" . count($this->lstmt);
        $this->lstmt[] = "$tname = $expr;";
        return $tname;
    }
    private function _join_lstmt($isblock) {
        $indent = "\n" . str_pad("", $this->indent);
        $t = $isblock ? "{" . $indent : "";
        $t .= join($indent, $this->lstmt);
        if ($isblock)
            $t .= substr($indent, 0, $this->indent - 1) . "}";
        return $t;
    }

    function loop_variable($datatype) {
        $g = array();
        if ($datatype & Fexpr::APCCANREV)
            $g[] = $this->_add_pc_can_review();
        if ($datatype & Fexpr::ASUBREV)
            $g[] = $this->_add_vsreviews();
        if ($datatype & Fexpr::APREF)
            $g[] = $this->_add_review_prefs();
        if ($datatype & Fexpr::ACONF)
            $g[] = $this->_add_conflicts();
        if (count($g) > 1) {
            $gx = str_replace('$', "", join("_and_", $g));
            return $this->define_gvar($gx, join(" + ", $g));
        } else if (count($g))
            return $g[0];
        else
            return $this->define_gvar("trivial_loop", "[0]");
    }
    function _compile_loop($initial_value, $combiner, AggregateFexpr $e) {
        $t_result = $this->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $this->_push();
        $this->looptype = Fexpr::LALL;
        $this->datatype = 0;

        preg_match_all('/~l(\d*)~/', $combiner, $m);
        foreach (array_unique($m[1]) as $i) {
            if ($this->combining !== null) {
                $t = "\$v{$p}";
                if (count($this->fragments) != 1)
                    $t .= "[{$this->combining}]";
                ++$this->combining;
            } else
                $t = $this->_addltemp($e->args[(int) $i]->compile($this));
            $combiner = str_replace("~l{$i}~", $t, $combiner);
        }

        if (preg_match('/[;}]\s*\z/', $combiner))
            $this->lstmt[] = str_replace("\n", str_pad("\n", $this->indent + 1), $combiner);
        else
            $this->lstmt[] = "$t_result = $combiner;";

        if ($this->combining !== null)
            $loop = "foreach (\$groups as \$v$p) " . $this->_join_lstmt(true);
        else {
            $g = $this->loop_variable($this->datatype);
            $loop = "foreach ($g as \$i$p => \$v$p) " . $this->_join_lstmt(true);
            if ($this->datatype === Fexpr::ASUBREV)
                $loop = str_replace('get($vsreviews, ~i~)', "\$v$p", $loop);
            else if ($this->datatype === Fexpr::APREF)
                $loop = str_replace('$allrevprefs[~i~]', "\$v$p", $loop);
            $loop = str_replace("~i~", "\$i$p", $loop);
        }

        $this->_pop($loop);
        return $t_result;
    }

    function _compile_my(Fexpr $e) {
        $p = $this->_push();
        $this->looptype = Fexpr::LMY;
        $t = $this->_addltemp($e->compile($this));
        $this->_pop($this->_join_lstmt(false));
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

class Formula {
    public $conf;
    public $user;
    public $formulaId = null;
    public $name = null;
    public $heading = "";
    public $headingTitle = "";
    public $expression = null;
    public $allowReview = false;
    private $needsReview = false;
    private $datatypes = 0;
    public $createdBy = 0;
    public $timeModified = 0;

    private $_parse = null;
    private $_format;
    private $_tagrefs;
    private $_error_html = [];
    private $_recursion;

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠)/';

    const DEBUG = 0;

    static public $opprec = array(
        "**" => 13,
        "u+" => 12, "u-" => 12, "u!" => 12,
        "*" => 11, "/" => 11, "%" => 11,
        "+" => 10, "-" => 10,
        "<<" => 9, ">>" => 9,
        "<" => 8, ">" => 8, "<=" => 8, ">=" => 8, "≤" => 8, "≥" => 8, // XXX value matters
        "=" => 7, "==" => 7, "!=" => 7, "≠" => 7,
        "&" => 6,
        "^" => 5,
        "|" => 4,
        ":" => 3,
        "&&" => 2,
        "||" => 1,
        "?:" => 0
    );

    private static $_oprassoc = array(
        "**" => true
    );

    private static $_oprewrite = array(
        "=" => "==", ":" => "==", "≤" => "<=", "≥" => ">=", "≠" => "!="
    );


    function __construct($expr = null, $allow_review = false) {
        if ($expr !== null) {
            $this->expression = $expr;
            $this->allowReview = $allow_review;
        }
        $this->merge();
    }

    private function merge() {
        $this->allowReview = !!$this->allowReview;
        $this->formulaId = (int) $this->formulaId;
    }

    static function fetch(Conf $conf, $result) {
        $formula = $result ? $result->fetch_object("Formula") : null;
        if ($formula && !is_int($formula->formulaId)) {
            $formula->conf = $conf;
            $formula->merge();
        }
        assert(!$formula || $formula->datatypes === 0);
        return $formula;
    }


    /* parsing */

    private function set_user(Contact $user) {
        assert(!$this->conf || $this->conf === $user->conf);
        $this->conf = $user->conf;
        $this->user = $user;
    }

    private function add_error_at($type, $pos1, $pos2, $rest) {
        $elen = strlen($this->expression);
        $h1 = htmlspecialchars(substr($this->expression, 0, $elen + $pos1));
        if ($pos1 == 0)
            $rest = " at end" . $rest;
        else {
            $p2 = substr($this->expression, $elen + $pos1, $pos2 - $pos1);
            $h1 .= '<span class="error">☞<u>' . htmlspecialchars($p2) . '</u></span>' . htmlspecialchars(substr($this->expression, $elen + $pos2));
        }
        $this->_error_html[] = "$type in formula “{$h1}”$rest";
    }

    private function parse_prefix(Contact $user = null, $allow_suffix) {
        if ($user)
            $this->set_user($user);
        assert($this->conf && $this->user);
        $t = $this->expression;
        if ((string) $t === "") {
            $this->_error_html[] = "Empty formula.";
            return false;
        }
        $e = $this->_parse_ternary($t, false);
        if (!$e || ($t !== "" && !$allow_suffix)) {
            if (!$this->_error_html)
                $this->add_error_at("Parse error", -strlen($t), 0, ".");
        } else if (($err = $e->typecheck($this->conf))) {
            $this->add_error_at("Type error", $err->expr->left_landmark, $err->expr->right_landmark, ': ' . $err->error_html);
        } else {
            $state = new FormulaCompiler($this->user);
            $e->compile($state);
            if ($state->datatype && !$this->allowReview && $e->matches_at_most_once()) {
                $e = new AggregateFexpr("some", [$e]);
                $state = new FormulaCompiler($this->user);
                $e->compile($state);
            }
            $this->datatypes = $state->all_datatypes | $state->datatype;
            if ($state->datatype && !$this->allowReview)
                $this->_error_html[] = "Illegal formula: can’t return a raw score, use an aggregate function.";
            else {
                $e->text = $this->expression;
                $this->needsReview = !!$state->datatype;
                $this->_format = $e->format();
                $this->_tagrefs = $state->tagrefs;
            }
        }
        if (($tlen = strlen($t)) > 0)
            $this->expression = rtrim(substr($this->expression, 0, -$tlen));
        return empty($this->_error_html) ? $e : false;
    }

    function check(Contact $user = null) {
        if ($this->_parse === null || ($user && $user !== $this->user))
            $this->_parse = $this->parse_prefix($user, false);
        return !!$this->_parse;
    }
    function check_prefix(Contact $user = null) {
        $suffix = "";
        if ($this->_parse === null || ($user && $user !== $this->user)) {
            $expr = $this->expression;
            $this->_parse = $this->parse_prefix($user, true);
            $suffix = ltrim((string) substr($expr, strlen($this->expression)));
        }
        return $this->_parse ? $suffix : false;
    }

    function error_html() {
        $this->check();
        return join("<br />", $this->_error_html);
    }
    function add_error_html($e) {
        $this->_error_html[] = $e;
    }

    private function _parse_ternary(&$t, $in_qc) {
        $lpos = -strlen($t);
        $e = $this->_parse_expr($t, 0, $in_qc);
        if (!$e || ($t = ltrim($t)) === "" || $t[0] !== "?")
            return $e;
        $t = substr($t, 1);
        if (($e1 = $this->_parse_ternary($t, true)) !== null)
            if (($t = ltrim($t)) !== "" && $t[0] === ":") {
                $t = substr($t, 1);
                if (($e2 = $this->_parse_ternary($t, $in_qc))) {
                    $e = new Fexpr("?:", $e, $e1, $e2);
                    $e->set_landmark($lpos, -strlen($t));
                    return $e;
                }
            }
        return null;
    }

    private function _parse_function_args(FormulaCall $ff, &$t) {
        $argtype = $ff->kwdef->args;
        $t = ltrim($t);
        // collect arguments
        if ($t !== "" && $t[0] === "(" && $argtype === "raw") {
            $arg = "";
            $t = substr($t, 1);
            $depth = 1;
            while ($t !== "") {
                preg_match('/\A("[^"]+(?:"|\z)|\(|\)|[^()"]+)(.*)\z/s', $t, $m);
                $t = $m[2];
                if ($m[1] === ")") {
                    if (--$depth === 0)
                        break;
                } else if ($m[1] === "(")
                    ++$depth;
                $arg .= $m[1];
            }
            $ff->args[] = $arg;
            return true;
        } else if ($t !== "" && $t[0] === "(") {
            while (1) {
                $t = substr($t, 1);
                if (!($e = $this->_parse_ternary($t, false)))
                    return false;
                $ff->args[] = $e;
                $t = ltrim($t);
                if ($t !== "" && $t[0] === ")")
                    break;
                else if ($t === "" || $t[0] !== ",")
                    return false;
            }
            $t = substr($t, 1);
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
        if (preg_match(',\s*((?:"[^"]*(?:"|\z)|[^\s()]*)*)(.*)\z,s', $t, $m) && $m[1] !== "")
            return $m;
        else
            return array($t, "", $t);
    }

    const ARGUMENT_REGEX = '((?:"[^"]*"|[-#a-zA-Z0-9_.@!*?~]+|:(?="|[-#a-zA-Z0-9_.@+!*\/?~]+(?![()])))+)';

    static private function field_search_fexpr($fval) {
        $fn = null;
        for ($i = 0; $i < count($fval); $i += 2) {
            $k = $fval[$i];
            $v = $fval[$i + 1];
            $fx = ($k === "outcome" ? new Decision_Fexpr : new TimeField_Fexpr($k));
            if (is_string($v))
                $fx = new Fexpr(str_replace("0", "", $v), $fx, ConstantFexpr::czero());
            else
                $fx = new InFexpr($fx, $v);
            $fn = $fn ? new Fexpr("&&", $fn, $fx) : $fx;
        }
        return $fn;
    }

    private function _reviewer_base($t) {
        $t = strtolower($t);
        if (preg_match('/\A(?:|r|re|rev|review)type\z/i', $t))
            return new Revtype_Fexpr;
        else if (preg_match('/\A(?:|r|re|rev|review)round\z/i', $t))
            return new ReviewRound_Fexpr;
        else if (preg_match('/\A(?:|r|re|rev)reviewer\z/i', $t))
            return new Reviewer_Fexpr;
        else if (preg_match('/\A(?:|r|re|rev|review)(?:|au)words\z/i', $t))
            return new ReviewWordCount_Fexpr;
        else
            return null;
    }

    private function _reviewer_decoration($e0, &$ex) {
        $e1 = null;
        $rsm = new ReviewSearchMatcher;
        while ($ex !== "") {
            if (!preg_match('/\A:((?:"[^"]*(?:"|\z)|[-A-Za-z0-9_.#@]+(?!\s*\())+)(.*)/si', $ex, $m)
                || preg_match('/\A(?:null|false|true|pid|paperid)\z/i', $m[1]))
                break;
            $ee = null;
            if (preg_match('/\A(?:type|round|reviewer|words|auwords)\z/i', $m[1])) {
                if ($e0)
                    break;
                $e0 = $this->_reviewer_base($m[1]);
            } else if ($rsm->apply_review_type($m[1], true)) {
                $op = strtolower($m[1][0]) === "p" ? ">=" : "==";
                $ee = new Fexpr($op, new Revtype_Fexpr, new ConstantFexpr($rsm->review_type(), Fexpr::FREVTYPE));
            } else if ($rsm->apply_round($m[1], $this->conf))
                /* OK */;
            else {
                if (strpos($m[1], "\"") !== false)
                    $m[1] = str_replace(["\"", "*"], ["", "\\*"], $m[1]);
                $ee = new ReviewerMatch_Fexpr($this->user, $m[1]);
            }
            if ($ee)
                $e1 = $e1 ? new Fexpr("&&", $e1, $ee) : $ee;
            $ex = $m[2];
        }
        if ($rsm->round) {
            $ee = new InFexpr(new ReviewRound_Fexpr, $rsm->round);
            $e1 = $e1 ? new Fexpr("&&", $e1, $ee) : $ee;
        }
        if ($e0 && $e1)
            return new Fexpr("?:", $e1, $e0, ConstantFexpr::cnull());
        else
            return $e0 ? : $e1;
    }

    private function _parse_option($text) {
        $os = Option_SearchTerm::analyze($this->conf, $text);
        foreach ($os->warn as $w)
            $this->_error_html[] = $w;
        $e = null;
        foreach ($os->os as $o) {
            $ex = new Option_Fexpr($o->option);
            if ($o->kind)
                $this->_error_html[] = "“" . htmlspecialchars($text) . "” can’t be used in formulas.";
            else if ($os->value_word === "")
                /* stick with raw option fexpr */;
            else if (is_array($o->value) && $o->compar === "!=")
                $ex = new NegateFexpr(new InFexpr($ex, $o->value));
            else if (is_array($o->value))
                $ex = new InFexpr($ex, $o->value);
            else
                $ex = new Fexpr(get(self::$_oprewrite, $o->compar, $o->compar),
                                $ex, new ConstantFexpr($o->value, $o->option));
            $e = $e ? new Fexpr("||", $e, $ex) : $ex;
        }
        if ($e && $os->negate)
            $e = new NegateFexpr($e);
        return $e;
    }

    private function _parse_expr(&$t, $level, $in_qc) {
        if (($t = ltrim($t)) === "")
            return null;
        $lpos = -strlen($t);

        $e = null;
        if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = $this->_parse_ternary($t, false);
            $t = ltrim($t);
            if (!$e || $t === "" || $t[0] !== ")")
                return null;
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = $this->_parse_expr($t, self::$opprec["u$op"], $in_qc)))
                return null;
            $e = $op == "!" ? new NegateFexpr($e) : new Fexpr($op, $e);
        } else if (preg_match('/\Anot([\s(].*|)\z/si', $t, $m)) {
            $t = $m[1];
            if (!($e = $this->_parse_expr($t, self::$opprec["u!"], $in_qc)))
                return null;
            $e = new NegateFexpr($e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = new ConstantFexpr($m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/si', $t, $m)) {
            $e = new ConstantFexpr($m[1], Fexpr::FBOOL);
            $t = $m[2];
        } else if (preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = ConstantFexpr::cnull();
            $t = $m[1];
        } else if (preg_match('/\Aopt(?:ion)?:\s*(.*)\z/s', $t, $m)) {
            $rest = self::_pop_argument($m[1]);
            $e = $this->_parse_option($rest[1]);
            $t = $rest[2];
        } else if (preg_match('/\A(?:dec|decision):\s*([-a-zA-Z0-9_.#@*]+)(.*)\z/si', $t, $m)) {
            $me = PaperSearch::decision_matchexpr($this->conf, $m[1], Text::SEARCH_UNPRIVILEGE_EXACT);
            $e = $this->field_search_fexpr(["outcome", $me]);
            $t = $m[2];
        } else if (preg_match('/\A(?:dec|decision)\b(.*)\z/si', $t, $m)) {
            $e = new Decision_Fexpr;
            $t = $m[1];
        } else if (preg_match('/\Ais:?rev?\b(.*)\z/is', $t, $m)) {
            $e = new Fexpr(">=", new Revtype_Fexpr, new ConstantFexpr(0, Fexpr::FREVTYPE));
            $t = $m[1];
        } else if (preg_match('/\A(?:is:?)?pcrev?\b(.*)\z/is', $t, $m)) {
            $e = new Fexpr(">=", new Revtype_Fexpr, new ConstantFexpr(REVIEW_PC, Fexpr::FREVTYPE));
            $t = $m[1];
        } else if (preg_match('/\A(?:is:?)?(meta|pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?|optional)\b(.*)\z/is', $t, $m)) {
            $e = new Fexpr("==", new Revtype_Fexpr, new ConstantFexpr($m[1], Fexpr::FREVTYPE));
            $t = $m[2];
        } else if (preg_match('/\A(?:is|status):\s*([-a-zA-Z0-9_.#@*]+)(.*)\z/si', $t, $m)) {
            $e = $this->field_search_fexpr(PaperSearch::status_field_matcher($this->conf, $m[1]));
            $t = $m[2];
        } else if (preg_match('/\A((?:r|re|rev|review)(?:type|round|words|auwords)?|round|reviewer)(?::|(?=#))\s*(.*)\z/is', $t, $m)) {
            $t = ":" . $m[2];
            $e = $this->_reviewer_decoration($this->_reviewer_base($m[1]), $t);
        } else if (preg_match('/\A((?:r|re|rev|review)(?:type|round|words|auwords)|round|reviewer)\b(.*)\z/is', $t, $m)) {
            $e = $this->_reviewer_base($m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(#|[A-Za-z][A-Za-z_]*)(.*)\z/is', $t, $m)
                   && ($kwdef = $this->conf->formula_function($m[1], $this->user))) {
            $pos1 = -strlen($t);
            $ff = new FormulaCall($kwdef, $m[1]);
            if ($m[1] !== "#")
                $t = $m[2];
            if (get($kwdef, "parse_modifier_callback")) {
                while (preg_match('/\A([.#:](?:"[^"]*(?:"|\z)|[-a-zA-Z0-9!@*_:.\/#~]+))(.*)/s', $t, $m)
                       && (get($kwdef, "args") || !preg_match('/\A\s*\(/s', $m[2]))
                       && ($marg = call_user_func($kwdef->parse_modifier_callback, $ff, $m[1], $m[2], $this)))
                    $t = $m[2];
            }
            if (get($kwdef, "args") && !$this->_parse_function_args($ff, $t)) {
                $this->add_error_at("Type error", $pos1, -strlen($t), ": function “" . htmlspecialchars($ff->name) . "” requires arguments.");
                return null;
            } else if (!isset($kwdef->callback)) {
                return null;
            }
            if ($kwdef->callback[0] === "+") {
                $class = substr($kwdef->callback, 1);
                $e = new $class($ff, $this);
            } else
                $e = call_user_func($kwdef->callback, $ff, $this);
            if (!$e)
                return null;
        } else if (preg_match('/\A([-A-Za-z0-9_.@]+|\".*?\")(.*)\z/s', $t, $m)
                   && $m[1] !== "\"\""
                   && !preg_match('/\A\s*\(/s', $m[2])) {
            $field = $m[1];
            if (($quoted = $field[0] === "\""))
                $field = substr($field, 1, strlen($field) - 2);
            while (1) {
                $fs = $this->conf->find_all_fields($field);
                if (count($fs) === 1) {
                    $f = $fs[0];
                    if ($f instanceof PaperOption)
                        $e = $this->_parse_option($f->search_keyword());
                    else if ($f instanceof ReviewField) {
                        if ($f->has_options)
                            $e = $this->_reviewer_decoration(new Score_Fexpr($f), $m[2]);
                        else
                            $this->_error_html[] = "Textual review field " . htmlspecialchars($field) . " can’t be used in formulas.";
                    } else if ($f instanceof Formula) {
                        if (!$f->_recursion) {
                            $this->_recursion = true;
                            $f->set_user($this->user);
                            $tt = $f->expression;
                            $e = $f->_parse_ternary($tt, false);
                            $this->_recursion = false;
                        } else
                            $this->_error_html[] = "Circular formula reference.";
                    }
                    break;
                }
                $f = $this->conf->find_review_field($field);
                if ($f) {
                    if (!$f->has_options)
                        return null;
                    $e = $this->_reviewer_decoration(new Score_Fexpr($f), $m[2]);
                    break;
                }
                if ($quoted)
                    return null;
                $dash = strrpos($field, "-");
                if ($dash === false || $dash === 0) {
                    $e = new ConstantFexpr($field, false);
                    break;
                }
                $m[2] = substr($field, $dash) . $m[2];
                $field = substr($field, 0, $dash);
            }
            $t = $m[2];
        }

        if (!$e)
            return null;
        $e->set_landmark($lpos, -strlen($t));

        while (1) {
            if (($t = ltrim($t)) === "")
                return $e;
            else if (preg_match(self::BINARY_OPERATOR_REGEX, $t, $m)) {
                $op = $m[0];
                $tn = substr($t, strlen($m[0]));
            } else if (preg_match('/\A(and|or)([\s(].*|)\z/i', $t, $m)) {
                $op = strlen($m[1]) == 3 ? "&&" : "||";
                $tn = $m[2];
            } else if (!$in_qc && substr($t, 0, 1) === ":") {
                $op = ":";
                $tn = substr($t, 1);
            } else
                return $e;

            $opprec = self::$opprec[$op];
            if ($opprec < $level)
                return $e;

            $t = $tn;
            $opx = get(self::$_oprewrite, $op) ? : $op;
            $opassoc = get(self::$_oprassoc, $opx) ? $opprec : $opprec + 1;
            if (!($e2 = $this->_parse_expr($t, $opassoc, $in_qc)))
                return null;
            if ($op === ":" && !($e2 instanceof ConstantFexpr)) {
                $t = ":" . $tn;
                return $e;
            }
            $e = new Fexpr($opx, $e, $e2);
            $e->set_landmark($lpos, -strlen($t));
        }
    }


    private static function compile_body($user, FormulaCompiler $state, $expr,
                                         $sortable = 0) {
        $t = "";
        if ($user)
            $t .= "assert(\$contact->contactId == $user->contactId);\n  ";
        $t .= $state->statement_text();
        if ($expr !== null) {
            if ($sortable & 3)
                $t .= "\n  \$x = $expr;";
            if ($sortable & 1)
                $t .= "\n  \$x = is_bool(\$x) ? (int) \$x : \$x;";
            if ($sortable & 2)
                $t .= "\n  if (is_float(\$x) && !is_finite(\$x)) {\n"
                    . "    \$x = null;\n  }";
            $t .= "\n  return " . ($sortable & 3 ? "\$x" : $expr) . ";\n";
        }
        return $t;
    }

    private function _compile_function($sortable) {
        if ($this->check()) {
            $state = new FormulaCompiler($this->user);
            $expr = $this->_parse->compile($state);
            $t = self::compile_body($this->user, $state, $expr, $sortable);
        } else
            $t = "return 0;";

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

    static function compile_indexes_function(Contact $user, $datatypes) {
        $state = new FormulaCompiler($user);
        $g = $state->loop_variable($datatypes);
        $t = "assert(\$contact->contactId == $user->contactId);\n  "
            . join("\n  ", $state->gstmt)
            . "\n  return array_keys($g);\n";
        $args = '$prow, $contact';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  $t}\n");
        return eval("return function ($args) {\n  $t};");
    }

    function compile_combine_functions() {
        $this->check();
        $state = new FormulaCompiler($this->user);
        $this->_parse && $this->_parse->compile_fragments($state);
        $t = self::compile_body($this->user, $state, null);
        if (count($state->fragments) == 1)
            $t .= "  return " . $state->fragments[0] . ";\n";
        else
            $t .= "  return [" . join(", ", $state->fragments) . "];\n";
        $args = '$prow, $rrow_cid, $contact';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  // fragments " . simplify_whitespace($this->expression) . "\n  $t}\n");
        $outf = eval("return function ($args) {\n  $t};");

        // regroup function
        $state->clear();
        $state->combining = 0;
        $expr = $this->_parse ? $this->_parse->compile($state) : "0";
        $t = self::compile_body(null, $state, $expr);
        $args = '$groups';
        self::DEBUG && Conf::msg_debugt("function ($args) {\n  // combine " . simplify_whitespace($this->expression) . "\n  $t}\n");
        $inf = eval("return function ($args) {\n  $t};");

        return [$outf, $inf];
    }

    function unparse_html($x, $real_format = null) {
        if ($x === null || $x === false)
            return "";
        else if ($x === true)
            return "✓";
        else if ($this->_format === Fexpr::FPREFEXPERTISE)
            return ReviewField::unparse_letter(91, $x + 2);
        else if ($this->_format === Fexpr::FREVIEWER)
            return $this->user->reviewer_html_for($x);
        else if ($this->_format === Fexpr::FTAG) {
            $tagger = new Tagger($this->user);
            return $tagger->unparse_and_link($this->_tagrefs[$x]);
        } else {
            $x = round($x * 100) / 100;
            if ($this->_format instanceof ReviewField)
                return $this->_format->unparse_value($x, ReviewField::VALUE_SC, $real_format);
            else
                return $real_format ? sprintf($real_format, $x) : $x;
        }
    }

    function unparse_text($x, $real_format) {
        if ($x === null)
            return "";
        else if (is_bool($x))
            return $x ? "Y" : "N";
        else if ($this->_format === Fexpr::FPREFEXPERTISE)
            return ReviewField::unparse_letter(91, $x + 2);
        else if ($this->_format === Fexpr::FREVIEWER)
            return $this->user->name_text_for($x);
        else if ($this->_format === Fexpr::FTAG) {
            $tagger = new Tagger($this->user);
            return $tagger->unparse_hashed($this->_tagrefs[$x]);
        } else {
            $x = round($x * 100) / 100;
            if ($this->_format instanceof ReviewField)
                return $this->_format->unparse_value($x, 0, $real_format);
            else
                return $real_format ? sprintf($real_format, $x) : $x;
        }
    }

    function add_query_options(&$queryOptions) {
        if ($this->check()) {
            $state = new FormulaCompiler($this->user);
            $state->queryOptions =& $queryOptions;
            $this->_parse->compile($state);
            if ($this->needsReview)
                $state->loop_variable($state->all_datatypes);
        }
    }

    function view_score(Contact $user) {
        if ($this->check($this->user ? : $user))
            return $this->_parse->view_score($user);
        else
            return VIEWSCORE_FALSE;
    }

    function column_header() {
        return $this->heading ? : ($this->name ? : $this->expression);
    }

    function is_indexed() {
        $this->check();
        return $this->needsReview;
    }

    function result_format() {
        return $this->check() ? $this->_format : null;
    }
    function result_format_is_real() {
        if (!$this->check())
            return null;
        else if ($this->_format instanceof ReviewField)
            return !$this->_format->option_letter;
        else if ($this->_format === Fexpr::FREVIEWER
                 || $this->_format === Fexpr::FBOOL
                 || $this->_format === Fexpr::FPREFEXPERTISE
                 || $this->_format === Fexpr::FTAG)
            return false;
        else
            return true;
    }

    function can_combine() {
        return $this->check() && $this->_parse->can_combine();
    }

    function is_sum() {
        return $this->check() && $this->_parse->op === "sum";
    }

    function datatypes() {
        return $this->check() ? $this->datatypes : 0;
    }
}
