<?php
// formula.php -- HotCRP helper class for paper expressions
// HotCRP is Copyright (c) 2009-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaCompileState {
    var $contact;
    var $gtmp;
    var $ltmp;
    var $gstmt;
    var $lstmt;
    var $lprefix;
    var $maxlprefix;
    var $indent;
    var $queryOptions;

    function __construct($contact) {
        $this->contact = $contact;
        $this->gtmp = array();
        $this->gstmt = array();
        $this->lstmt = array();
        $this->lprefix = 0;
        $this->maxlprefix = 0;
        $this->indent = 2;
        $this->queryOptions = array();
    }
}

class FormulaExpr {
    public $op;
    public $agg;
    public $args = array();
    public $text;
    public $format = null;

    public function __construct($op, $agg = false) {
        $this->op = $op;
        $this->agg = $agg;
    }
    public function add($x) {
        if ($x instanceof FormulaExpr && $x->agg)
            $this->agg = ($this->agg && $this->agg !== $x->agg ? "mix" : $x->agg);
        $this->args[] = $x;
    }
    static public function make($op) {
        $e = new FormulaExpr($op);
        $args = func_get_args();
        for ($i = 1; $i < count($args); ++$i)
            $e->add($args[$i]);
        return $e;
    }
    static public function make_agg($op, $agg) {
        $e = new FormulaExpr($op, $agg);
        $args = func_get_args();
        for ($i = 2; $i < count($args); ++$i)
            $e->add($args[$i]);
        return $e;
    }
    public function set_format() {
        foreach ($this->args as $a)
            if ($a instanceof FormulaExpr)
                $a->set_format();
        if ($this->op === "revprefexp")
            $this->format = "revprefexp";
        else if ($this->op === "rf")
            $this->format = $this->args[0];
        else if (($this->op === "max" || $this->op === "min"
                  || $this->op === "avg" || $this->op === "wavg")
                 && count($this->args) >= 1
                 && $this->args[0] instanceof FormulaExpr)
            $this->format = $this->args[0]->format;
        else if ($this->op === "greatest" || $this->op === "least"
                 || $this->op === "?:") {
            $this->format = false;
            for ($i = ($this->op === "?:" ? 1 : 0); $i < count($this->args); ++$i) {
                $a = $this->args[$i];
                if ($a instanceof FormulaExpr
                    && ($this->format === false || $this->format === $a->format))
                    $this->format = $a->format;
                else
                    $this->format = null;
            }
            if ($this->format === false)
                $this->format = null;
        } else
            $this->format = null;
    }
    private function _resolve_using($e) {
        $word = $this->args[0];
        if (!($e instanceof FormulaExpr) || ($x = $e->resolve_scores()))
            return $word;
        $e->set_format();
        if ($e->format === "revprefexp" && $word >= "X" && $word <= "Z") {
            $this->op = "";
            $this->args[0] = 89 - ord($word);
            return false;
        } else if ($e->format instanceof ReviewField
                   && ($x = $e->format->parse_value($word, true))) {
            $this->op = "";
            $this->args[0] = $x;
            return false;
        } else
            return $word;
    }
    public function resolve_scores() {
        // comparison operators help us resolve
        if (preg_match(',\A[<>=!]=?\z,', $this->op)
            && count($this->args) === 2) {
            list($a0, $a1) = $this->args;
            if ($a0 instanceof FormulaExpr && $a0->op === "??")
                $a0->_resolve_using($a1);
            if ($a1 instanceof FormulaExpr && $a1->op === "??")
                $a1->_resolve_using($a0);
        }

        if ($this->op === "??")
            return $this->args[0];
        foreach ($this->args as $a)
            if ($a instanceof FormulaExpr && ($x = $a->resolve_scores()))
                return $x;
        return false;
    }
}

class Formula {

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|=|[=!]=|<[<=]?|>[>=]?)/';

    private static $_operators = array(
        "**" => 12,
        "u+" => 11, "u-" => 11, "u!" => 11,
        "*" => 10, "/" => 10, "%" => 10,
        "+" => 9, "-" => 9,
        "<<" => 8, ">>" => 8,
        "<" => 7, ">" => 7, "<=" => 7, ">=" => 7,
        "==" => 6, "!=" => 6,
        "&" => 5,
        "^" => 4,
        "|" => 3,
        "&&" => 2,
        "||" => 1,
        "?:" => 0
    );

    static function parse($t, $no_errors = false) {
        global $Conf;
        $in_text = $t;
        $e = self::_parse_ternary($t);
        $errors = array();
        if ($t !== "" || !$e) {
            $prefix = substr($in_text, 0, strlen($in_text) - strlen($t));
            $errors[] = "Parse error in formula “" . htmlspecialchars($prefix) . "&nbsp;<span style='color:red'>&larr;</span>&nbsp;" . htmlspecialchars(substr($in_text, strlen($prefix))) . "”.";
        } else if ($e->agg === "mix")
            $errors[] = "Illegal formula: can’t mix scores and preferences in the same aggregate function.";
        else if ($e->agg)
            $errors[] = "Illegal formula: can’t return a raw score, use an aggregate function.";
        else if (($x = $e->resolve_scores()))
            $errors[] = "Illegal formula: can’t resolve “" . htmlspecialchars($x) . "” to a score.";
        else {
            //$Conf->infoMsg(Ht::pre_text($e));
            $e->text = $in_text;
            $e->set_format();
        }
        if (!$no_errors)
            foreach ($errors as $e)
                $Conf->errorMsg($e);
        return count($errors) ? null : $e;
    }

    static function _parse_ternary(&$t) {
        $e = self::_parse_expr($t, 0);
        if (!$e || ($t = ltrim($t)) === "" || $t[0] !== "?")
            return $e;
        $t = substr($t, 1);
        if (($e1 = self::_parse_ternary($t)) !== null)
            if (($t = ltrim($t)) !== "" && $t[0] === ":") {
                $t = substr($t, 1);
                if (($e2 = self::_parse_ternary($t)))
                    return FormulaExpr::make("?:", $e, $e1, $e2);
            }
        return null;
    }

    static function _parse_function($op, &$t, $is_aggregate) {
        $t = ltrim($t);
        $e = FormulaExpr::make($op);

        // collect arguments
        if ($t !== "" && $t[0] === "(") {
            while (1) {
                $t = substr($t, 1);
                if (!($e2 = self::_parse_ternary($t)))
                    return null;
                $e->add($e2);
                $t = ltrim($t);
                if ($t !== "" && $t[0] === ")")
                    break;
                else if ($t === "" || $t[0] !== ",")
                    return null;
            }
            $t = substr($t, 1);
        } else if (($e2 = self::_parse_expr($t, self::$_operators["u+"])))
            $e->add($e2);
        else
            return null;

        // maybe clear aggregateness
        if ($is_aggregate && $e->agg !== "mix"
            && count($e->args) <= ($e->op === "wavg" ? 2 : 1))
            $e->agg = false;
        return $e;
    }

    static function _parse_expr(&$t, $level) {
        if (($t = ltrim($t)) === "")
            return null;

        if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = self::_parse_ternary($t);
            $t = ltrim($t);
            if (!$e || $t[0] !== ")")
                return null;
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = self::_parse_expr($t, self::$_operators["u$op"])))
                return null;
            $e = FormulaExpr::make($op, $e);
        } else if (preg_match('/\Anot([\s(].*|)\z/i', $t, $m)) {
            $t = $m[2];
            if (!($e = self::_parse_expr($t, self::$_operators["u!"])))
                return null;
            $e = FormulaExpr::make("!", $e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", $m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(?:tag(?:\s*:\s*|\s+)|#)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tag", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\Atag(?:v|-?val|-?value)(?:\s*:\s*|\s+)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag(?:v|-?val|-?value)\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tagval", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(all|any|avg|count|min|max|std(?:dev(?:_pop|_samp)?)?|sum|var(?:iance|_pop|_samp)?|wavg)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = self::_parse_function($m[1], $t, true)))
                return null;
        } else if (preg_match('/\A(greatest|least)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = self::_parse_function($m[1], $t, false)))
                return null;
        } else if (preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", "null");
            $t = $m[1];
        } else if (preg_match('/\A(ispri(?:mary)?|issec(?:ondary)?|(?:is)?ext(?:ernal)?)\b(.*)\z/s', $t, $m)) {
            if ($m[1] == "ispri" || $m[1] == "isprimary")
                $rt = REVIEW_PRIMARY;
            else if ($m[1] == "issec" || $m[1] == "issecondary")
                $rt = REVIEW_SECONDARY;
            else if ($m[1] == "ext" || $m[1] == "external"
                     || $m[1] == "isext" || $m[1] == "isexternal")
                $rt = REVIEW_EXTERNAL;
            $e = FormulaExpr::make_agg("revtype", true, $rt);
            $t = $m[2];
        } else if (preg_match('/\A(?:rev)?pref\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_agg("revpref", "revpref");
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?prefexp(?:ertise)?\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_agg("revprefexp", "revpref");
            $t = $m[1];
        } else if (preg_match('/\A([a-zA-Z0-9_]+|\".*?\")(.*)\z/s', $t, $m)
                   && $m[1] !== "\"\"") {
            $field = $m[1];
            $t = $m[2];
            if (($quoted = $field[0] === "\""))
                $field = substr($field, 1, strlen($field) - 2);
            $rf = reviewForm();
            if (($fid = $rf->unabbreviateField($field)))
                $e = FormulaExpr::make_agg("rf", true, $rf->field($fid));
            else if (!$quoted && strlen($field) === 1 && ctype_alpha($field))
                $e = FormulaExpr::make_agg("??", false, strtoupper($field));
            else
                return null;
        } else
            return null;

        while (1) {
            if (($t = ltrim($t)) === "")
                return $e;
            else if (preg_match(self::BINARY_OPERATOR_REGEX, $t, $m)) {
                $op = $m[0] === "=" ? "==" : $m[0];
                $tn = substr($t, strlen($m[0]));
            } else if (preg_match('/\A(and|or)([\s(].*|)\z/i', $t, $m)) {
                $op = strlen($m[1]) == 3 ? "&&" : "||";
                $tn = $m[2];
            } else
                return $e;

            $opprec = self::$_operators[$op];
            if ($opprec < $level)
                return $e;

            $t = $tn;
            if (!($e2 = self::_parse_expr($t, $opprec == 12 ? $opprec : $opprec + 1)))
                return null;

            $e = FormulaExpr::make($op, $e, $e2);
        }
    }


    static function _addgtemp($state, $expr, $name) {
        if (isset($state->gtmp[$name]))
            return $state->gtmp[$name];
        $tname = "\$tg" . count($state->gtmp);
        $state->gstmt[] = "$tname = $expr;";
        $state->gtmp[$name] = $tname;
        return $tname;
    }

    static function _addltemp($state, $expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
            return $expr;
        $tname = "\$t" . $state->lprefix . "_" . count($state->lstmt);
        $state->lstmt[] = "$tname = $expr;";
        return $tname;
    }

    static function _addnumscores($state) {
        if (!isset($state->gtmp["numscores"])) {
            $state->gtmp["numscores"] = "\$numScores";
            $state->gstmt[] = "\$numScores = (\$forceShow || \$contact->canViewReview(\$prow, null, false) ? \$prow->numScores : 0);";
        }
        return "\$numScores";
    }

    static function _addreviewprefs($state) {
        if (!isset($state->gtmp["allrevprefs"])) {
            $state->gtmp["allrevprefs"] = "\$allrevprefs";
            $state->gstmt[] = "\$allrevprefs = (\$forceShow || \$contact->canViewReview(\$prow, null, false) ? \$prow->reviewer_preferences() : array());";
        }
        return "\$allrevprefs";
    }

    static function _compilereviewloop($state, $initial_value, $combiner, $e,
                                       $type = "int") {
        $t_result = self::_addltemp($state, $initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);

        $save_lprefix = $state->lprefix;
        $save_lstmt = $state->lstmt;

        $state->lprefix = ++$state->maxlprefix;
        $state->lstmt = array();
        $state->indent += 2;
        $loopval = self::_compile($state, $e->args[0]);
        if (count($e->args) == 2)
            $loopval2 = self::_compile($state, $e->args[1]);
        $state->indent -= 2;

        $t_loop = self::_addltemp($state, $loopval);
        $combiner = str_replace("~l~", $t_loop, $combiner);
        if (count($e->args) == 2) {
            $t_loop2 = self::_addltemp($state, $loopval2);
            $combiner = str_replace("~l2~", $t_loop2, $combiner);
        }
        $state->lstmt[] = "$t_result = $combiner;";

        $t_looper = "\$ri_" . $state->lprefix;
        $indent = "\n" . str_pad("", $state->indent + 2);
        if ($e->args[0]->agg === true) {
            $t_bound = self::_addnumscores($state);
            $loop = "for ($t_looper = 0; $t_looper < $t_bound; ++$t_looper)";
        } else {
            $t_bound = self::_addreviewprefs($state);
            $loop = "foreach ($t_bound as $t_looper)";
        }
        $loop .= " {" . $indent . join($indent, $state->lstmt) . "\n" . str_pad("", $state->indent) . "}\n";

        $state->lprefix = $save_lprefix;
        $state->lstmt = $save_lstmt;
        $state->lstmt[] = $loop;
        if ($type == "int")
            $state->lstmt[] = "if ($t_result === true || $t_result === false) $t_result = (int) $t_result;\n";
        else if ($type == "bool")
            $state->lstmt[] = "if ($t_result !== null) $t_result = (bool) $t_result;\n";
        return $t_result;
    }

    static function _compile($state, $e) {
        $op = $e->op;
        if ($op == "")
            return $e->args[0];

        if ($op == "tag" || $op == "tagval") {
            $state->queryOptions["tags"] = true;
            $tagger = new Tagger($state->contact);
            $e_tag = $tagger->check($e->args[0]);
            $t_tags = self::_addgtemp($state, "(\$forceShow || \$contact->canViewTags(\$prow, true) ? \$prow->paperTags : '')", "tags");
            $t_tagpos = self::_addgtemp($state, "strpos($t_tags, \" $e_tag#\")", "tagpos " . $e->args[0]);
            $t_tagval = self::_addgtemp($state, "($t_tagpos === false ? null : (int) substr($t_tags, $t_tagpos + " . (strlen($e_tag) + 2) . "))", "tagval " . $e->args[0]);
            if ($op == "tag")
                return "($t_tagval !== null)";
            else
                return $t_tagval;
        }

        if ($op == "revtype") {
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if (VIEWSCORE_PC <= $view_score)
                $t_f = "null";
            else {
                $state->queryOptions["reviewTypes"] = true;
                $t_f = "(" . self::_addgtemp($state, "explode(',', \$prow->reviewTypes)", "rev type") . "[\$ri_" . $state->lprefix . "]==" . $e->args[0] . ")";
            }
            return $t_f;
        }

        if ($op == "rf") {
            $f = $e->args[0];
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if ($f->view_score <= $view_score)
                return "null";
            if (!isset($state->queryOptions["scores"]))
                $state->queryOptions["scores"] = array();
            $state->queryOptions["scores"][$f->id] = true;
            $t_f = self::_addgtemp($state, "explode(',', \$prow->{$f->id}Scores)", "rev $f->id") . "[\$ri_" . $state->lprefix . "]";
            return "($t_f == 0 ? null : (int) $t_f)";
        }

        if ($op == "revpref" || $op == "revprefexp") {
            $view_score = $state->contact->viewReviewFieldsScore(null, true);
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["allReviewerPreference"] = true;
            return "\$ri_{$state->lprefix}[" . ($op == "revpref" ? 0 : 1) . "]";
        }

        if ($op == "?:") {
            $t = self::_addltemp($state, self::_compile($state, $e->args[0]));
            $tt = self::_addltemp($state, self::_compile($state, $e->args[1]));
            $tf = self::_addltemp($state, self::_compile($state, $e->args[2]));
            return "($t ? $tt : $tf)";
        }

        if (count($e->args) == 1 && isset(self::$_operators["u$op"])) {
            $t = self::_addltemp($state, self::_compile($state, $e->args[0]));
            if ($op == "!")
                return "$op$t";
            else
                return "($t === null ? $t : $op$t)";
        }

        if (count($e->args) == 2 && isset(self::$_operators[$op])) {
            $t1 = self::_addltemp($state, self::_compile($state, $e->args[0]));
            $t2 = self::_addltemp($state, self::_compile($state, $e->args[1]));
            if ($op == "&&")
                return "($t1 ? $t2 : $t1)";
            else if ($op == "||")
                return "($t1 ? $t1 : $t2)";
            else
                return "($t1 === null || $t2 === null ? null : $t1 $op $t2)";
        }

        if (count($e->args) == 1 && $op == "all")
            return self::_compilereviewloop($state, "null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", $e, "bool");

        if (count($e->args) == 1 && $op == "any")
            return self::_compilereviewloop($state, "null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", $e, "bool");

        if (count($e->args) == 1 && $op == "min")
            return self::_compilereviewloop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ < ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "max")
            return self::_compilereviewloop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ > ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "count")
            return self::_compilereviewloop($state, "0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)", $e);

        if (count($e->args) == 1 && $op == "sum")
            return self::_compilereviewloop($state, "null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)", $e);

        if (count($e->args) == 1 && ($op == "avg" || $op == "wavg")) {
            $t = self::_compilereviewloop($state, "array(0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~, ~r~[1] + 1) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 2 && $op == "wavg") {
            $t = self::_compilereviewloop($state, "array(0, 0)", "(~l~ !== null && ~l2~ !== null ? array(~r~[0] + ~l~ * ~l2~, ~r~[1] + ~l2~) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 1 && ($op == "variance" || $op == "var" || $op == "var_pop" || $op == "var_samp" || $op == "std" || $op == "stddev" || $op == "stddev_pop" || $op == "stddev_samp")) {
            $t = self::_compilereviewloop($state, "array(0, 0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1) : ~r~)", $e);
            if ($op == "variance" || $op == "var" || $op == "var_samp")
                return "(${t}[2] > 1 ? ${t}[0] / (${t}[2] - 1) - (${t}[1] * ${t}[1]) / (${t}[2] * (${t}[2] - 1)) : (${t}[2] ? 0 : null))";
            else if ($op == "var_pop")
                return "(${t}[2] ? ${t}[0] / ${t}[2] - (${t}[1] * ${t}[1]) / (${t}[2] * ${t}[2]) : null)";
            else if ($op == "std" || $op == "stddev" || $op == "stddev_samp")
                return "(${t}[2] > 1 ? sqrt(${t}[0] / (${t}[2] - 1) - (${t}[1] * ${t}[1]) / (${t}[2] * (${t}[2] - 1))) : (${t}[2] ? 0 : null))";
            else if ($op == "stddev_pop")
                return "(${t}[2] ? sqrt(${t}[0] / ${t}[2] - (${t}[1] * ${t}[1]) / (${t}[2] * ${t}[2])) : null)";
        }

        return "null";
    }

    static function compile_function_body($e, $contact) {
        global $Conf;
        $state = new FormulaCompileState($contact);
        $expr = self::_compile($state, $e);

        $t = join("\n  ", $state->gstmt)
            . (count($state->gstmt) && count($state->lstmt) ? "\n  " : "")
            . join("\n  ", $state->lstmt) . "\n"
            . "  \$x = $expr;\n"
            . '  if ($format == "h") {
    if ($x === null || $x === false)
      return "";
    else if ($x === true)
      return "&#x2713;";
    else';

        // HTML format for output depends on type of output
        if ($e->format === "revprefexp")
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(91, $x + 2);';
        else if ($e->format instanceof ReviewField
                 && $e->format->option_letter)
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(' . $e->format->option_letter . ', $x);';
        else
            $t .= "\n      "
                . 'return round($x * 100) / 100;';

        $t .= "\n" . '  } else if ($format == "s")
    return ($x === true ? 1 : $x);
  else
    return $x;' . "\n";
        //$Conf->infoMsg(Ht::pre_text("function (\$prow) {\n  /* $e->text */\n  $t}\n"));
        return $t;
    }

    static function compile_function($e, $contact) {
        return create_function("\$prow, \$contact, \$format = null, \$forceShow = false", self::compile_function_body($e, $contact));
    }

    static function add_query_options(&$queryOptions, $e, $contact) {
        $state = new FormulaCompileState($contact);
        $state->queryOptions =& $queryOptions;
        self::_compile($state, $e);
    }

    static function expression_view_score($e, $contact) {
        $op = $e->op;
        if ($op == "")
            return VIEWSCORE_AUTHOR;

        if ($op == "tag" || $op == "tagval") {
            $tagger = new Tagger($contact);
            $e_tag = $tagger->check($e->args[0]);
            return $tagger->view_score($e_tag, $contact);
        }

        if ($op == "rf")
            return $e->args[0]->view_score;

        if ($op == "revtype" || $op == "revpref" || $op == "revprefexp")
            return VIEWSCORE_PC;

        if ($op == "?:") {
            $t = self::expression_view_score($e->args[0], $contact);
            $tt = self::expression_view_score($e->args[1], $contact);
            $tf = self::expression_view_score($e->args[2], $contact);
            return min($t, max($tt, $tf));
        }

        $score = 1;
        for ($i = 0; $i < count($e->args); ++$i)
            $score = min($score, self::expression_view_score($e->args[$i], $contact));
        return $score;
    }

}

class FormulaInfo {
    public $name;
    public $heading = "";
    public $headingTitle = "";
    public $expression;
    public $authorView;

    public function __construct($name, $fexpr) {
        global $Me;
        $this->name = $name;
        if (is_string($fexpr))
            $fexpr = Formula::parse($fexpr, true);
        if (!$fexpr) {
            $this->expression = "";
            $this->authorView = VIEWSCORE_FALSE;
        } else {
            $this->expression = $fexpr->text;
            $this->authorView = Formula::expression_view_score($fexpr, $Me);
        }
    }
}
