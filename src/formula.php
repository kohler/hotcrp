<?php
// formula.php -- HotCRP helper class for paper expressions
// HotCRP is Copyright (c) 2009-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaCompileState {
    public $contact;
    public $gtmp = array();
    public $gstmt = array();
    public $lstmt = array();
    public $aggt = 0;
    private $lprefix = 0;
    private $maxlprefix = 0;
    public $indent = 2;
    public $queryOptions = array();
    private $_stack = array();

    const AGG_NO = 0;
    const AGG_MY = 1;
    const AGG_LOOP = 2;

    function __construct($contact) {
        $this->contact = $contact;
    }

    private function _addgtemp($name, $expr) {
        if (($tname = @$this->gtmp[$name]) === null) {
            if (preg_match('/\A\w+\z/', $name))
                $tname = "\$$name";
            else
                $tname = "\$g" . count($this->gtmp);
            $this->gstmt[] = "$tname = $expr;";
            $this->gtmp[$name] = $tname;
        }
        return $tname;
    }
    private function _push() {
        $this->_stack[] = array($this->lprefix, $this->lstmt, $this->aggt);
        $this->lprefix = ++$this->maxlprefix;
        $this->lstmt = array();
        $this->aggt = self::AGG_NO;
        $this->indent += 2;
        return $this->lprefix;
    }
    private function _pop($content) {
        list($this->lprefix, $this->lstmt, $this->aggt) = array_pop($this->_stack);
        $this->indent -= 2;
        $this->lstmt[] = $content;
    }
    private function _addltemp($expr = "null", $always_var = false) {
        if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
            return $expr;
        $tname = "\$t" . $this->lprefix . "_" . count($this->lstmt);
        $this->lstmt[] = "$tname = $expr;";
        return $tname;
    }
    private function _join_lstmt($isblock) {
        $indent = "\n" . str_pad("", $this->indent);
        if ($isblock)
            return "{" . $indent . join($indent, $this->lstmt) . substr($indent, 0, $this->indent - 1) . "}";
        else
            return join($indent, $this->lstmt);
    }
    private function _add_pc_can_review() {
        if (!isset($this->gtmp["pc_can_review"])) {
            $this->gtmp["pc_can_review"] = "\$pc_can_review";
            $this->gstmt[] = "\$pc_can_review = \$prow->pc_can_become_reviewer();";
        }
        return "\$pc_can_review";
    }
    private function _add_submitted_reviewers() {
        if (!isset($this->gtmp["submitted_reviewers"])) {
            $this->queryOptions["reviewContactIds"] = true;
            $this->gtmp["submitted_reviewers"] = "\$submitted_reviewers";
            $this->gstmt[] = "\$submitted_reviewers = \$prow->viewable_submitted_reviewers(\$contact, \$forceShow);";
        }
        return "\$submitted_reviewers";
    }
    private function _add_review_prefs() {
        if (!isset($this->gtmp["allrevprefs"])) {
            $this->queryOptions["allReviewerPreference"] = true;
            $this->gtmp["allrevprefs"] = "\$allrevprefs";
            $this->gstmt[] = "\$allrevprefs = (\$forceShow || \$contact->can_view_review(\$prow, null, false) ? \$prow->reviewer_preferences() : array());";
        }
        return "\$allrevprefs";
    }
    private function _add_decision() {
        if (!isset($this->gtmp["decision"])) {
            $this->gtmp["decision"] = "\$decision";
            $this->gstmt[] = "\$decision = \$contact->can_view_decision(\$prow, \$forceShow) ? \$prow->outcome : 0;";
        }
        return "\$decision";
    }


    public function loop_variable($aggt) {
        if ($aggt & FormulaExpr::APCCANREV) {
            $g = $this->_add_pc_can_review();
            if ($aggt & FormulaExpr::ASUBREV)
                $g = $this->_addgtemp("rev_and_pc_can_review", "$g + array_flip(" . $this->_add_submitted_reviewers() . ")");
            return array($g, true);
        } else if ($aggt === (FormulaExpr::ASUBREV | FormulaExpr::APREF)) {
            $g = $this->_addgtemp("rev_and_pref_cids", $this->_add_review_prefs() . " + array_flip(" . $this->_add_submitted_reviewers() . ")");
            return array($g, true);
        } else if ($aggt === FormulaExpr::ASUBREV)
            return array($this->_add_submitted_reviewers(), false);
        else
            return array($this->_add_review_prefs(), true);
    }
    private function _compile_loop($initial_value, $combiner, $e) {
        $t_result = $this->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $this->_push();
        $this->aggt = self::AGG_LOOP;

        $aggt = 0;
        foreach ($e->args as $i => $ee) {
            $t = $this->_addltemp($this->compile($ee));
            $combiner = str_replace("~l" . ($i ? : "") . "~", $t, $combiner);
            $aggt |= $ee->aggt;
        }
        $this->lstmt[] = "$t_result = $combiner;";

        $t_looper = "\$i$p";

        $g = $this->loop_variable($aggt);
        $loop = "foreach ($g[0] as \$i$p" . ($g[1] ? " => \$v$p" : "") . ") "
            . $this->_join_lstmt(true);
        if ($aggt == FormulaExpr::APREF)
            $loop = str_replace("\$allrevprefs[~i~]", "\$v$p", $loop);
        $loop = str_replace("~i~", "\$i$p", $loop);

        $this->_pop($loop);
        return $t_result;
    }

    private function _compile_my($e) {
        $p = $this->_push();
        $this->aggt = self::AGG_MY;
        $t = $this->_addltemp($this->compile($e));
        $this->_pop($this->_join_lstmt(false));
        return $t;
    }

    private static function _cast_bool($t) {
        return "($t !== null ? (bool) $t : null)";
    }

    public function compile($e) {
        $op = $e->op;
        if ($op == "")
            return $e->args[0];

        if ($op == "pid")
            return "\$prow->paperId";

        if ($op == "dec")
            return $this->_add_decision();

        if ($op == "tag" || $op == "tagval") {
            $this->queryOptions["tags"] = true;
            $tagger = new Tagger($this->contact);
            $e_tag = $tagger->check($e->args[0]);
            $t_tags = $this->_addgtemp("tags", "(\$forceShow || \$contact->can_view_tags(\$prow, true) ? \$prow->all_tags_text() : '')");
            $t_tagpos = $this->_addgtemp("tagpos {$e->args[0]}", "stripos($t_tags, \" $e_tag#\")");
            $t_tagval = $this->_addgtemp("tagval {$e->args[0]}", "($t_tagpos !== false ? (int) substr($t_tags, $t_tagpos + " . (strlen($e_tag) + 2) . ") : null)");
            if ($op == "tag")
                return "($t_tagval !== 0 ? $t_tagval : true)";
            else
                return $t_tagval;
        }

        if ($op == "revtype") {
            if ($this->aggt == self::AGG_MY)
                $rt = $this->_addgtemp("myrevtype", "\$prow->review_type(\$contact)");
            else {
                $view_score = $this->contact->viewReviewFieldsScore(null, true);
                if (VIEWSCORE_PC <= $view_score)
                    return "null";
                $this->queryOptions["reviewTypes"] = true;
                $rt = $this->_addgtemp("revtypes", "\$prow->submitted_review_types()");
                if ($this->aggt == self::AGG_NO)
                    $rt .= "[\$rrow_cid]";
                else
                    $rt .= "[~i~]";
            }
            return "({$rt}==" . $e->args[0] . ")";
        }

        if ($op == "rf") {
            $f = $e->args[0];
            if (!isset($this->queryOptions["scores"]))
                $this->queryOptions["scores"] = array();
            $this->queryOptions["scores"][$f->id] = $f->id;
            if ($this->aggt == self::AGG_NO)
                $fidx = "[\$rrow_cid]";
            else if ($this->aggt == self::AGG_MY)
                $fidx = "[\$contact->contactId]";
            else {
                $view_score = $this->contact->viewReviewFieldsScore(null, true);
                if ($f->view_score <= $view_score)
                    return "null";
                $fidx = "[~i~]";
            }
            $t_f = $this->_addgtemp($f->id, "\$prow->scores(\"{$f->id}\")") . $fidx;
            return "((int) @$t_f ? : null)";
        }

        if ($op == "revpref" || $op == "revprefexp") {
            if ($this->aggt == self::AGG_NO)
                $fidx = "[\$rrow_cid]";
            else if ($this->aggt == self::AGG_MY)
                $fidx = "[\$contact->contactId]";
            else {
                $view_score = $this->contact->viewReviewFieldsScore(null, true);
                if (VIEWSCORE_PC <= $view_score)
                    return "null";
                $fidx = "[~i~]";
            }
            $this->queryOptions["allReviewerPreference"] = true;
            return "@" . $this->_add_review_prefs() . $fidx . "[" . ($op == "revpref" ? 0 : 1) . "]";
        }

        if ($op == "topicscore") {
            $this->queryOptions["topics"] = true;
            if ($this->aggt == self::AGG_NO)
                return "null";
            else if ($this->aggt == self::AGG_MY)
                return $this->_addgtemp("mytopicscore", "\$prow->topic_interest_score(\$contact)");
            else
                return "\$prow->topic_interest_score(~i~)";
        }


        if ($op == "?:") {
            $t = $this->_addltemp($this->compile($e->args[0]));
            $tt = $this->_addltemp($this->compile($e->args[1]));
            $tf = $this->_addltemp($this->compile($e->args[2]));
            return "($t ? $tt : $tf)";
        }

        if (count($e->args) == 1 && isset(Formula::$opprec["u$op"])) {
            $t = $this->_addltemp($this->compile($e->args[0]));
            if ($op == "!")
                return "$op$t";
            else
                return "($t === null ? $t : $op$t)";
        }

        if (count($e->args) == 2 && isset(Formula::$opprec[$op])) {
            $t1 = $this->_addltemp($this->compile($e->args[0]));
            $t2 = $this->_addltemp($this->compile($e->args[1]));
            if ($op == "&&")
                return "($t1 ? $t2 : $t1)";
            else if ($op == "||")
                return "($t1 ? : $t2)";
            else
                return "($t1 !== null && $t2 !== null ? $t1 $op $t2 : null)";
        }

        if (count($e->args) == 1 && $op == "my")
            return $this->_compile_my($e->args[0]);

        if (count($e->args) == 1 && $op == "all") {
            $t = $this->_compile_loop("null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", $e);
            return self::_cast_bool($t);
        }

        if (count($e->args) == 1 && $op == "any") {
            $t = $this->_compile_loop("null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", $e);
            return self::_cast_bool($t);
        }

        if (count($e->args) == 1 && $op == "min")
            return $this->_compile_loop("null", "(~l~ !== null && (~r~ === null || ~l~ < ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "max")
            return $this->_compile_loop("null", "(~l~ !== null && (~r~ === null || ~l~ > ~r~) ? ~l~ : ~r~)", $e);

        if (count($e->args) == 1 && $op == "count")
            return $this->_compile_loop("0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)", $e);

        if (count($e->args) == 1 && $op == "sum")
            return $this->_compile_loop("null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)", $e);

        if (count($e->args) == 1 && ($op == "avg" || $op == "wavg")) {
            $t = $this->_compile_loop("array(0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~, ~r~[1] + 1) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 2 && $op == "wavg") {
            $t = $this->_compile_loop("array(0, 0)", "(~l~ !== null && ~l1~ !== null ? array(~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~) : ~r~)", $e);
            return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
        }

        if (count($e->args) == 1 && ($op == "variance" || $op == "var" || $op == "var_pop" || $op == "var_samp" || $op == "std" || $op == "stddev" || $op == "stddev_pop" || $op == "stddev_samp")) {
            $t = $this->_compile_loop("array(0, 0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1) : ~r~)", $e);
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
}

class FormulaExpr {
    public $op;
    public $aggt;
    public $args = array();
    public $text;
    public $format = null;

    const ASUBREV = 1;
    const APREF = 2;
    const APCCANREV = 4;

    public function __construct($op, $aggt = 0) {
        $this->op = $op;
        $this->aggt = $aggt;
    }
    public function add($x) {
        if ($x instanceof FormulaExpr)
            $this->aggt |= $x->aggt;
        $this->args[] = $x;
    }
    static public function make($op) {
        $e = new FormulaExpr($op);
        $args = func_get_args();
        for ($i = 1; $i < count($args); ++$i)
            $e->add($args[$i]);
        return $e;
    }
    static public function make_aggt($op, $aggt /* , ... */) {
        $e = new FormulaExpr($op, $aggt);
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
            $this->format = $this->format ? : null;
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
        if (preg_match(',\A(?:[<>=!]=?|≤|≥|≠)\z,', $this->op)
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
    public function view_score($contact) {
        $op = $this->op;
        if ($op == "" || $op == "pid")
            return VIEWSCORE_AUTHOR;

        if ($op == "tag" || $op == "tagval") {
            $tagger = new Tagger($contact);
            $e_tag = $tagger->check($this->args[0]);
            return $tagger->view_score($e_tag, $contact);
        }

        if ($op == "rf")
            return $this->args[0]->view_score;

        if ($op == "revtype" || $op == "revpref" || $op == "revprefexp"
            || $op == "topicscore")
            return VIEWSCORE_PC;

        if ($op == "?:") {
            $t = $this->args[0]->view_score($contact);
            $tt = $this->args[1]->view_score($contact);
            $tf = $this->args[2]->view_score($contact);
            return min($t, max($tt, $tf));
        }

        // Robustness: return VIEWSCORE_FALSE if this function
        // doesn't understand this expression type
        $score = 1000;
        foreach ($this->args as $e)
            if ($e instanceof FormulaExpr)
                $score = min($score, $e->view_score($contact));
        return $score === 1000 ? VIEWSCORE_FALSE : $score;
    }
}

class Formula {

    public $formulaId = null;
    public $name = null;
    public $heading = "";
    public $headingTitle = "";
    public $expression = null;
    public $authorView = null;
    public $allowReview = false;
    private $needsReview = false;
    public $createdBy = 0;
    public $timeModified = 0;

    private $_parse = null;
    private $_error_html = null;

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠)/';

    public static $opprec = array(
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


    public function __construct(/* $fobj OR $expression, [$allowReview] */) {
        $args = func_get_args();
        if (is_object(@$args[0])) {
            foreach ($args[0] as $k => $v)
                $this->$k = $v;
        } else if (is_string(@$args[0])) {
            $this->expression = $args[0];
            $this->allowReview = !!@$args[1];
        }
    }


    /* parsing */

    public function check() {
        if ($this->_parse !== null)
            return !!$this->_parse;

        $t = $this->expression;
        $e = $this->_parse_ternary($t, false);
        if ((string) $this->expression === "")
            $this->_error_html = "Empty formula.";
        else if ($t !== "" || !$e) {
            $prefix = substr($this->expression, 0, strlen($this->expression) - strlen($t));
            $this->_error_html = "Parse error in formula “" . htmlspecialchars($prefix) . "<span style='color:red;text-decoration:underline'>" . htmlspecialchars(substr($this->expression, strlen($prefix))) . "</span>”.";
        } else if ($e->aggt && !$this->allowReview)
            $this->_error_html = "Illegal formula: can’t return a raw score, use an aggregate function.";
        else if (($x = $e->resolve_scores()))
            $this->_error_html = "Illegal formula: can’t resolve “" . htmlspecialchars($x) . "” to a score.";
        else {
            $e->text = $this->expression;
            $this->needsReview = !!$e->aggt;
            $e->set_format();
        }
        $this->_parse = (count($this->_error_html) ? false : $e);
        if ($this->authorView === null) {
            global $Me;
            $this->authorView = $this->view_score($Me);
        }
        return !!$this->_parse;
    }

    public function error_html() {
        $this->check();
        return $this->_error_html;
    }

    private function _parse_ternary(&$t, $in_qc) {
        $e = $this->_parse_expr($t, 0, $in_qc);
        if (!$e || ($t = ltrim($t)) === "" || $t[0] !== "?")
            return $e;
        $t = substr($t, 1);
        if (($e1 = $this->_parse_ternary($t, true)) !== null)
            if (($t = ltrim($t)) !== "" && $t[0] === ":") {
                $t = substr($t, 1);
                if (($e2 = $this->_parse_ternary($t, $in_qc)))
                    return FormulaExpr::make("?:", $e, $e1, $e2);
            }
        return null;
    }

    private function _parse_function($op, &$t, $is_aggregate) {
        $t = ltrim($t);
        $e = FormulaExpr::make($op);

        // collect arguments
        if ($t !== "" && $t[0] === "(") {
            while (1) {
                $t = substr($t, 1);
                if (!($e2 = $this->_parse_ternary($t, false)))
                    return null;
                $e->add($e2);
                $t = ltrim($t);
                if ($t !== "" && $t[0] === ")")
                    break;
                else if ($t === "" || $t[0] !== ",")
                    return null;
            }
            $t = substr($t, 1);
        } else if (($e2 = $this->_parse_expr($t, self::$opprec["u+"], false)))
            $e->add($e2);
        else
            return null;

        // maybe clear aggregateness
        if ($is_aggregate && count($e->args) <= ($e->op === "wavg" ? 2 : 1))
            $e->aggt = 0;
        return $e;
    }

    private function _parse_expr(&$t, $level, $in_qc) {
        if (($t = ltrim($t)) === "")
            return null;

        if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = $this->_parse_ternary($t, false);
            $t = ltrim($t);
            if (!$e || $t[0] !== ")")
                return null;
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = $this->_parse_expr($t, self::$opprec["u$op"], $in_qc)))
                return null;
            $e = FormulaExpr::make($op, $e);
        } else if (preg_match('/\Anot([\s(].*|)\z/i', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_expr($t, self::$opprec["u!"], $in_qc)))
                return null;
            $e = FormulaExpr::make("!", $e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", $m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/si', $t, $m)) {
            $e = FormulaExpr::make("", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(pid|paperid)\b(.*)\z/si', $t, $m)) {
            $e = FormulaExpr::make("pid", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(dec|decision)\b(.*)\z/si', $t, $m)) {
            $e = FormulaExpr::make("dec", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(?:tag(?:\s*:\s*|\s+)|#)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tag", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\Atag(?:v|-?val|-?value)(?:\s*:\s*|\s+)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag(?:v|-?val|-?value)\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = FormulaExpr::make("tagval", $m[1]);
            $t = $m[2];
        } else if (preg_match('/\A(my|all|any|avg|count|min|max|std(?:dev(?:_pop|_samp)?)?|sum|var(?:iance|_pop|_samp)?|wavg)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, true)))
                return null;
        } else if (preg_match('/\A(greatest|least)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, false)))
                return null;
        } else if (preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make("", "null");
            $t = $m[1];
        } else if (preg_match('/\A(?:is)?(pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?)\b(.*)\z/s', $t, $m)) {
            if ($m[1] == "pri" || $m[1] == "primary")
                $rt = REVIEW_PRIMARY;
            else if ($m[1] == "sec" || $m[1] == "secondary")
                $rt = REVIEW_SECONDARY;
            else if ($m[1] == "ext" || $m[1] == "external")
                $rt = REVIEW_EXTERNAL;
            $e = FormulaExpr::make_aggt("revtype", FormulaExpr::ASUBREV, $rt);
            $t = $m[2];
        } else if (preg_match('/\Atopicscore\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_aggt("topicscore", FormulaExpr::APCCANREV);
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?pref\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_aggt("revpref", FormulaExpr::APREF);
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?prefexp(?:ertise)?\b(.*)\z/s', $t, $m)) {
            $e = FormulaExpr::make_aggt("revprefexp", FormulaExpr::APREF);
            $t = $m[1];
        } else if (preg_match('/\A([a-zA-Z0-9_]+|\".*?\")(.*)\z/s', $t, $m)
                   && $m[1] !== "\"\"") {
            $field = $m[1];
            $t = $m[2];
            if (($quoted = $field[0] === "\""))
                $field = substr($field, 1, strlen($field) - 2);
            $rf = reviewForm();
            if (($fid = $rf->unabbreviateField($field)))
                $e = FormulaExpr::make_aggt("rf", FormulaExpr::ASUBREV, $rf->field($fid));
            else if (!$quoted && strlen($field) === 1 && ctype_alpha($field))
                $e = FormulaExpr::make_aggt("??", 0, strtoupper($field));
            else
                return null;
        } else
            return null;

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
            $op = @self::$_oprewrite[$op] ? : $op;
            if (!($e2 = $this->_parse_expr($t, @self::$_oprassoc[$op] ? $opprec : $opprec + 1, $in_qc)))
                return null;
            $e = FormulaExpr::make($op, $e, $e2);
        }
    }


    public function compile_function($contact) {
        global $Conf;
        $this->check();
        $state = new FormulaCompileState($contact);
        $expr = $state->compile($this->_parse);

        $loop = "";
        if ($this->needsReview) {
            $g = $state->loop_variable($this->_parse->aggt);
            $loop = "\n  if (\$format == \"loop\")
    return " . ($g[1] ? "array_keys($g[0])" : $g[0]) . ";\n";
        }

        $t = join("\n  ", $state->gstmt)
            . (count($state->gstmt) && count($state->lstmt) ? "\n  " : "")
            . $loop . join("\n  ", $state->lstmt) . "\n"
            . "  \$x = $expr;\n\n"
            . '  if ($format == "h") {
    if ($x === null || $x === false)
      return "";
    else if ($x === true)
      return "&#x2713;";
    else';

        // HTML format for output depends on type of output
        if ($this->_parse->format === "revprefexp")
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(91, $x + 2);';
        else if ($this->_parse->format instanceof ReviewField
                 && $this->_parse->format->option_letter)
            $t .= "\n      "
                . 'return ReviewField::unparse_letter(' . $this->_parse->format->option_letter . ', $x);';
        else
            $t .= "\n      "
                . 'return round($x * 100) / 100;';

        $t .= "\n" . '  } else if ($format == "s")
    return ($x === true ? 1 : $x);
  else
    return $x;' . "\n";

        $args = '$prow, $rrow_cid, $contact, $format = null, $forceShow = false';
        //$Conf->infoMsg(Ht::pre_text("function ($args) {\n  /* $this->expression */\n  $t}\n"));
        return create_function($args, $t);
    }

    public function add_query_options(&$queryOptions, $contact) {
        $this->check();
        $state = new FormulaCompileState($contact);
        $state->queryOptions =& $queryOptions;
        $state->compile($this->_parse);
        if ($this->needsReview)
            $state->loop_variable($this->_parse->aggt);
    }

    public function base_view_score() {
        $this->check();
        return $this->authorView;
    }

    public function view_score($contact) {
        return $this->check() ? $this->_parse->view_score($contact) : VIEWSCORE_FALSE;
    }

    public function column_header() {
        return $this->heading ? : ($this->name ? : $this->expression);
    }

    public function needs_review() {
        $this->check();
        return $this->needsReview;
    }

    public function field_type() {
        $this->check();
        if ($this->_parse && $this->_parse->format instanceof ReviewField)
            return $this->_parse->format;
        return null;
    }
}
