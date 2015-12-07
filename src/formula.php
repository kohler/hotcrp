<?php
// formula.php -- HotCRP helper class for paper expressions
// HotCRP is Copyright (c) 2009-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Fexpr_Error {
    public $expr;
    public $error_html;
    public function __construct($expr, $error_html) {
        $this->expr = $expr;
        $this->error_html = $error_html;
    }
}

class Fexpr {
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

    public function __construct($op = null) {
        $this->op = $op;
        if ($this->op === "trunc")
            $this->op = "floor";
        $args = func_get_args();
        if (count($args) == 2 && is_array($args[1]))
            $this->args = $args[1];
        else if (count($args) > 1)
            $this->args = array_slice($args, 1);
    }
    public function add($x) {
        $this->args[] = $x;
    }
    public function set_landmark($left, $right) {
        $this->left_landmark = $left;
        $this->right_landmark = $right;
    }

    public function format() {
        return $this->format_;
    }
    public function format_comparator($cmp) {
        global $Opt;
        if ($this->format_ && $this->format_ instanceof ReviewField
            && $this->format_->option_letter && !@$Opt["smartScoreCompare"]) {
            if ($cmp[0] == "<")
                return ">" . substr($cmp, 1);
            if ($cmp[0] == ">")
                return "<" . substr($cmp, 1);
        }
        return $cmp;
    }
    public function is_null() {
        return false;
    }

    public function typecheck_format() {
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
            return "bool";
        else
            return null;
    }

    public function typecheck() {
        // comparison operators help us resolve
        if (preg_match(',\A(?:[<>=!]=?|≤|≥|≠)\z,', $this->op)
            && count($this->args) === 2) {
            list($a0, $a1) = $this->args;
            if ($a0 instanceof ConstantFexpr)
                $a0->typecheck_neighbor($a1);
            if ($a1 instanceof ConstantFexpr)
                $a1->typecheck_neighbor($a0);
        }
        foreach ($this->args as $a)
            if ($a instanceof Fexpr && ($x = $a->typecheck()))
                return $x;
        if ($this->format_ === false)
            $this->format_ = $this->typecheck_format();
        return false;
    }

    public function view_score(Contact $contact) {
        if ($this->op == "?:") {
            $t = $this->args[0]->view_score($contact);
            $tt = $this->args[1]->view_score($contact);
            $tf = $this->args[2]->view_score($contact);
            return min($t, max($tt, $tf));
        } else if ($this->op == "||")
            return $this->args[0]->view_score($contact);
        else {
            $score = VIEWSCORE_AUTHOR;
            foreach ($this->args as $e)
                if ($e instanceof Fexpr)
                    $score = min($score, $e->view_score($contact));
            return $score;
        }
    }

    public static function cast_bool($t) {
        return "($t !== null ? (bool) $t : null)";
    }

    public function compile(FormulaCompiler $state) {
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
            else {
                if (Formula::$opprec[$op] == 8)
                    $op = $this->args[0]->format_comparator($op);
                return "($t1 !== null && $t2 !== null ? $t1 $op $t2 : null)";
            }
        }

        if ($op == "greatest" || $op == "least") {
            $cmp = $this->format_comparator($op == "greatest" ? ">" : "<");
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
}

class ConstantFexpr extends Fexpr {
    private $x;
    public function __construct($x, $format = null) {
        parent::__construct("");
        $this->x = $x;
        $this->format_ = $format;
    }
    public function is_null() {
        return $this->x === "null";
    }
    public function typecheck() {
        if ($this->format_ !== false)
            return false;
        return new Fexpr_Error($this, "unresolved constant “" . htmlspecialchars($this->x) . "”");
    }
    public function typecheck_neighbor($e) {
        global $Conf;
        if ($this->format_ !== false || !($e instanceof Fexpr)
            || $e->typecheck())
            return;
        $format = $e->format();
        $letter = "";
        if (strlen($this->x) == 1 && ctype_alpha($this->x))
            $letter = strtoupper($this->x);
        if ($format === "expertise" && $letter >= "X" && $letter <= "Z")
            $this->x = 89 - ord($word);
        else if ($format instanceof ReviewField && $letter
                 && ($x = $format->parse_value($letter, true)))
            $this->x = $x;
        else if ($format === "revround"
                 && (($round = $Conf->round_number($this->x, false))
                     || $this->x === "unnamed"))
            $this->x = $round;
        else if ($format === "revtype"
                 && ($rt = ReviewSearchMatcher::parse_review_type($this->x)))
            $this->x = $rt;
        else
            return;
        $this->format_ = $format;
    }
    public function compile(FormulaCompiler $state) {
        return $this->x;
    }
    public static function zero() {
        return new ConstantFexpr("0");
    }
}

class NegateFexpr extends Fexpr {
    public function __construct(Fexpr $e) {
        parent::__construct("!", $e);
        $this->format_ = "bool";
    }
    public function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "!$t";
    }
}

class InFexpr extends Fexpr {
    private $values;
    public function __construct(Fexpr $e, array $values) {
        parent::__construct("in", $e);
        $this->values = $values;
        $this->format_ = "bool";
    }
    public function compile(FormulaCompiler $state) {
        $t = $state->_addltemp($this->args[0]->compile($state));
        return "(array_search($t, [" . join(", ", $this->values) . "]) !== false)";
    }
}

class AggregateFexpr extends Fexpr {
    static public function make($op, $args) {
        if ($op === "average" || $op === "mean"
            || ($op === "wavg" && count($args) == 1))
            $op = "avg";
        $arg_count = 1;
        if ($op === "atminof" || $op === "atmaxof"
            || $op === "wavg" || $op === "quantile")
            $arg_count = 2;
        if (count($args) == $arg_count)
            return new AggregateFexpr($op, $args);
        else
            return null;
    }

    public function typecheck_format() {
        if ($this->op === "atminof" || $this->op === "atmaxof"
            && count($this->args) >= 2
            && $this->args[1] instanceof Fexpr)
            return $this->args[1]->format();
        else if (count($this->args) >= 1
                 && $this->args[0] instanceof Fexpr)
            return $this->args[0]->format();
        else
            return null;
    }

    public static function quantile($a, $p) {
        // The “R-7” quantile implementation
        if (count($a) === 0 || $p < 0 || $p > 1)
            return null;
        $ix = (count($a) - 1) * $p + 1;
        $i = (int) $ix;
        $v = $a[$i - 1];
        if (($e = $ix - $i))
            $v += $e * ($a[$i] - $v);
        return $v;
    }

    public function loop_info() {
        if ($this->op == "all")
            return ["null", "(~r~ !== null ? ~l~ && ~r~ : ~l~)", self::cast_bool("~x~")];
        if ($this->op == "any")
            return ["null", "(~l~ !== null || ~r~ !== null ? ~l~ || ~r~ : ~r~)", self::cast_bool("~x~")];
        if ($this->op == "min" || $this->op == "max") {
            $cmp = $this->format_comparator($this->op == "min" ? "<" : ">");
            return ["null", "(~l~ !== null && (~r~ === null || ~l~ $cmp ~r~) ? ~l~ : ~r~)"];
        }
        if ($this->op == "atminof" || $this->op == "atmaxof") {
            $cmp = $this->args[0]->format_comparator($this->op == "atminof" ? "<" : ">");
            return ["[null, [null]]",
"if (~l~ !== null && (~r~[0] === null || ~l~ $cmp ~r~[0])) {
  ~r~[0] = ~l~;
  ~r~[1] = [~l1~];
} else if (~l~ !== null && ~l~ == ~r~[0])
  ~r~[1][] = ~l1~;",
                    "~x~[1][count(~x~[1]) > 1 ? mt_rand(0, count(~x~[1]) - 1) : 0]"];
        }
        if ($this->op == "count")
            return ["0", "(~l~ !== null && ~l~ !== false ? ~r~ + 1 : ~r~)"];
        if ($this->op == "sum")
            return ["null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)"];
        if ($this->op == "avg")
            return ["[0, 0]", "(~l~ !== null ? [~r~[0] + ~l~, ~r~[1] + 1] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        if ($this->op == "median" || $this->op == "quantile") {
            if ($this->op == "median")
                $q = "0.5";
            else {
                $q = $state->_addltemp($this->args[1]->compile($state));
                if ($this->format_comparator("<") == ">")
                    $q = "1 - $q";
            }
            return ["[]", "if (~l~ !== null)\n  array_push(~r~, ~l~);",
                    "AggregateFexpr::quantile(~x~, $q)"];
        }
        if ($this->op == "wavg")
            return ["[0, 0]", "(~l~ !== null && ~l1~ !== null ? [~r~[0] + ~l~ * ~l1~, ~r~[1] + ~l1~] : ~r~)",
                    "(~x~[1] ? ~x~[0] / ~x~[1] : null)"];
        if (preg_match('/\A(var(?:iance)?|std(?:d?ev)?)(|_pop|_samp|[_.][ps])\z/', $this->op, $m)) {
            $ispop = preg_match('/\A(?:|_pop|[_.]p)\z/', $m[2]);
            if ($m[1][0] == "v" && !$ispop)
                $x = "(~x~[2] > 1 ? ~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1)) : (~x~[2] ? 0 : null))";
            else if ($m[1][0] == "v")
                $x = "(~x~[2] ? ~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2]) : null)";
            else if (!$ispop)
                $x = "(~x~[2] > 1 ? sqrt(~x~[0] / (~x~[2] - 1) - (~x~[1] * ~x~[1]) / (~x~[2] * (~x~[2] - 1))) : (~x~[2] ? 0 : null))";
            else
                $x = "(~x~[2] ? sqrt(~x~[0] / ~x~[2] - (~x~[1] * ~x~[1]) / (~x~[2] * ~x~[2])) : null)";
            return ["[0, 0, 0]", "(~l~ !== null ? [~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1] : ~r~)", $x];
        }
        return null;
    }

    public function compile(FormulaCompiler $state) {
        if ($this->op == "my")
            return $state->_compile_my($this->args[0]);
        else if (($li = $this->loop_info())) {
            $t = $state->_compile_loop($li[0], $li[1], $this);
            return @$li[2] ? str_replace("~x~", $t, $li[2]) : $t;
        } else
            return "null";
    }
}

class ScoreFexpr extends Fexpr {
    private $field;
    public function __construct(ReviewField $field) {
        parent::__construct("rf");
        $this->field = $this->format_ = $field;
    }
    public function view_score(Contact $contact) {
        return $this->field->view_score;
    }
    public function compile(FormulaCompiler $state) {
        if ($this->field->view_score <= $state->contact->permissive_view_score_bound())
            return "null";
        $fid = $this->field->id;
        if (!isset($state->queryOptions["scores"]))
            $state->queryOptions["scores"] = array();
        $state->queryOptions["scores"][$fid] = $fid;
        $state->datatype |= Fexpr::ASUBREV;
        $scores = $state->define_gvar($fid, "\$prow->viewable_scores(\"$fid\", \$contact, \$forceShow)");
        return "((int) @{$scores}[" . $state->_rrow_cid() . "] ? : null)";
    }
}

class PrefFexpr extends Fexpr {
    private $isexpertise;
    public function __construct($isexpertise) {
        $this->isexpertise = $isexpertise;
        $this->format_ = $this->isexpertise ? "expertise" : null;
    }
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        if (!$state->contact->is_reviewer())
            return "null";
        $state->queryOptions["allReviewerPreference"] = true;
        $state->datatype |= self::APREF;
        return "@" . $state->_add_review_prefs() . "[" . $state->_rrow_cid()
            . "][" . ($this->isexpertise ? 1 : 0) . "]";
    }
}

class TagFexpr extends Fexpr {
    private $tag;
    private $isvalue;
    public function __construct($tag, $isvalue) {
        $this->tag = $tag;
        $this->isvalue = $isvalue;
        $this->format_ = $isvalue ? null : "bool";
    }
    public function view_score(Contact $contact) {
        $tagger = new Tagger($contact);
        $e_tag = $tagger->check($this->tag);
        return $tagger->view_score($e_tag, $contact);
    }
    public function compile(FormulaCompiler $state) {
        $state->queryOptions["tags"] = true;
        $tagger = new Tagger($state->contact);
        $e_tag = $tagger->check($this->tag);
        $t_tags = $state->define_gvar("tags", "\$contact->can_view_tags(\$prow, \$forceShow) ? \$prow->all_tags_text() : \"\"");
        $t_tagpos = $state->define_gvar("tagpos_{$this->tag}", "stripos($t_tags, \" $e_tag#\")");
        $t_tagval = $state->define_gvar("tagval_{$this->tag}", "($t_tagpos !== false ? (float) substr($t_tags, $t_tagpos + " . (strlen($e_tag) + 2) . ") : false)");
        if ($this->isvalue)
            return $t_tagval;
        else
            return "($t_tagval !== 0 ? $t_tagval : true)";
    }
}

class OptionFexpr extends Fexpr {
    private $option;
    public function __construct(PaperOption $option) {
        $this->option = $this->format_ = $option;
        if ($this->option->type === "checkbox")
            $this->format_ = "bool";
    }
    public function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $ovar = "\$opt$id";
        if ($state->check_gvar($ovar)) {
            $state->queryOptions["options"] = true;
            $state->gstmt[] = "if (\$contact->can_view_paper_option(\$prow, $id, \$forceShow)) {";
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

class DecisionFexpr extends Fexpr {
    public function __construct() {
        $this->format_ = "dec";
    }
    public function view_score(Contact $contact) {
        global $Conf;
        return $Conf->timeAuthorViewDecision() ? VIEWSCORE_AUTHOR :
            $Conf->timePCViewDecision(false) ? VIEWSCORE_PC : VIEWSCORE_ADMINONLY;
    }
    public function compile(FormulaCompiler $state) {
        if ($state->check_gvar('$decision'))
            $state->gstmt[] = "\$decision = \$contact->can_view_decision(\$prow, \$forceShow) ? (int) \$prow->outcome : 0;";
        return '$decision';
    }
}

class TimeFieldFexpr extends Fexpr {
    private $field;
    public function __construct($field) {
        $this->field = $field;
    }
    public function compile(FormulaCompiler $state) {
        return "((int) \$prow->" . $this->field . ")";
    }
}

class TopicScoreFexpr extends Fexpr {
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= Fexpr::APCCANREV;
        $state->queryOptions["topics"] = true;
        if ($state->looptype == self::LMY)
            return $state->define_gvar("mytopicscore", "\$prow->topic_interest_score(\$contact)");
        else
            return "\$prow->topic_interest_score(" . $state->_rrow_cid() . ")";
    }
}

class RevtypeFexpr extends Fexpr {
    public function __construct() {
        $this->format_ = "revtype";
    }
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        if ($state->looptype == self::LMY)
            $rt = $state->define_gvar("myrevtype", "\$prow->review_type(\$contact)");
        else {
            $view_score = $state->contact->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["reviewTypes"] = true;
            $rt = $state->define_gvar("revtypes", "\$prow->submitted_review_types()");
            return "@{$rt}[" . $state->_rrow_cid() . "]";
        }
        return $rt;
    }
}

class ReviewRoundFexpr extends Fexpr {
    public function __construct() {
        $this->format_ = "revround";
    }
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        if ($state->looptype == self::LMY)
            $rt = $state->define_gvar("myrevround", "\$prow->review_round(\$contact->contactId)");
        else {
            $view_score = $state->contact->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["reviewRounds"] = true;
            $rt = $state->define_gvar("revrounds", "\$prow->submitted_review_rounds()");
            return "@{$rt}[" . $state->_rrow_cid() . "]";
        }
        return $rt;
    }
}

class ConflictFexpr extends Fexpr {
    private $ispc;
    public function __construct($ispc) {
        $this->ispc = $ispc;
        $this->format_ = "bool";
    }
    public function compile(FormulaCompiler $state) {
        // XXX the actual search is different
        $state->datatype |= self::ACONF;
        if ($state->looptype == self::LMY)
            $rt = $state->contact->isPC ? "!!\$prow->conflictType" : "false";
        else {
            $idx = "[" . $state->_rrow_cid() . "]";
            $rt = "!!@" . $state->_add_conflicts() . $idx;
            if ($this->ispc)
                $rt = "(@" . $state->_add_pc() . $idx . " ? $rt : null)";
        }
        return $rt;
    }
}

class ReviewFexpr extends Fexpr {
    public function view_score(Contact $contact) {
        global $Conf;
        if (!$Conf->setting("rev_blind"))
            return VIEWSCORE_AUTHOR;
        else if ($Conf->setting("pc_seeblindrev"))
            return VIEWSCORE_REVIEWERONLY;
        else
            return VIEWSCORE_PC;
    }
}

class ReviewerFexpr extends ReviewFexpr {
    public function __construct() {
        $this->format_ = "reviewer";
    }
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        return '($contact->can_view_review_identity($prow, null, $forceShow) ? ' . $state->_rrow_cid() . ' : null)';
    }
}

class ReviewerMatchFexpr extends ReviewFexpr {
    private $arg;
    private $flags;
    private $istag;
    private static $tagmap = array();
    public function __construct($arg) {
        $this->arg = $arg;
        $this->istag = $arg[0] === "#" || ($arg[0] !== "\"" && pcTags($arg));
        $this->format_ = "bool";
    }
    public function view_score(Contact $contact) {
        return $this->istag ? VIEWSCORE_PC : parent::view_score($contact);
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        $flags = 0;
        $arg = $this->arg;
        if ($arg[0] === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $arg = str_replace("\"", "", $arg);
        }
        if (!($flags & ContactSearch::F_QUOTED)
            && ($arg[0] === "#" || pcTags($arg))
            && $state->contact->can_view_reviewer_tags()) {
            $cvt = $state->define_gvar('can_view_reviewer_tags', '$contact->can_view_reviewer_tags($prow)');
            $tag = ($arg[0] === "#" ? substr($arg, 1) : $arg);
            $e = "($cvt ? ReviewerMatchFexpr::check_tagmap(" . $state->_rrow_cid() . ", " . json_encode($tag) . ") : null)";
        } else {
            $flags |= ContactSearch::F_TAG | ContactSearch::F_NOUSER;
            $cs = new ContactSearch($flags, $arg, $state->contact);
            if ($cs->ids)
                // XXX information leak?
                $e = "(\$contact->can_view_review_identity(\$prow, null, \$forceShow) ? array_search(" . $state->_rrow_cid() . ", [" . join(", ", $cs->ids) . "]) !== false : null)";
            else
                $e = "null";
        }
        return $e;
    }
    public static function check_tagmap($cid, $tag) {
        if (@($a = self::$tagmap[$tag]) === null) {
            $a = array();
            foreach (pcMembers() as $pc)
                if (($v = $pc->tag_value($tag)) !== false)
                    $a[$pc->contactId] = $v ? : true;
            self::$tagmap[$tag] = $a;
        }
        return @$a[$cid] ? : false;
    }
}

class ReviewWordCountFexpr extends Fexpr {
    public function view_score(Contact $contact) {
        return VIEWSCORE_PC;
    }
    public function compile(FormulaCompiler $state) {
        $state->datatype |= self::ASUBREV;
        if ($state->looptype == self::LMY)
            $rt = $state->define_gvar("myrevwordcount", "\$prow->review_word_count(\$contact->contactId)");
        else {
            $view_score = $state->contact->permissive_view_score_bound();
            if (VIEWSCORE_PC <= $view_score)
                return "null";
            $state->queryOptions["reviewWordCounts"] = true;
            $rt = $state->define_gvar("revwordcounts", "\$prow->submitted_review_word_counts()");
            return "@{$rt}[" . $state->_rrow_cid() . "]";
        }
        return $rt;
    }
}

class FormulaCompiler {
    public $contact;
    private $gvar = array();
    public $gstmt = array();
    public $lstmt = array();
    public $looptype = Fexpr::LNONE;
    public $datatype = 0;
    private $lprefix = 0;
    private $maxlprefix = 0;
    public $indent = 2;
    public $queryOptions = array();
    private $_stack = array();

    function __construct(Contact $contact) {
        $this->contact = $contact;
    }

    public function check_gvar($gvar) {
        if (@$this->gvar[$gvar])
            return false;
        else {
            $this->gvar[$gvar] = $gvar;
            return true;
        }
    }
    public function define_gvar($name, $expr) {
        if (preg_match(',\A\$?(.*[^A-Ya-z0-9_].*)\z,', $name, $m))
            $name = '$' . preg_replace_callback(',[^A-Ya-z0-9_],', function ($m) { return "Z" . dechex(ord($m[0])); }, $m[1]);
        else
            $name = $name[0] == "$" ? $name : '$' . $name;
        if (@$this->gvar[$name] === null) {
            $this->gstmt[] = "$name = $expr;";
            $this->gvar[$name] = $name;
        }
        return $name;
    }

    public function _add_pc_can_review() {
        if ($this->check_gvar('$pc_can_review'))
            $this->gstmt[] = "\$pc_can_review = \$prow->pc_can_become_reviewer();";
        return '$pc_can_review';
    }
    public function _add_submitted_reviewers() {
        if ($this->check_gvar('$submitted_reviewers')) {
            $this->queryOptions["reviewContactIds"] = true;
            $this->gstmt[] = "\$submitted_reviewers = array_flip(\$prow->viewable_submitted_reviewers(\$contact, \$forceShow));";
        }
        return '$submitted_reviewers';
    }
    public function _add_review_prefs() {
        if ($this->check_gvar('$allrevprefs')) {
            $this->queryOptions["allReviewerPreference"] = true;
            $this->gstmt[] = "\$allrevprefs = \$contact->can_view_review(\$prow, null, \$forceShow) ? \$prow->reviewer_preferences() : [];";
        }
        return '$allrevprefs';
    }
    public function _add_conflicts() {
        if ($this->check_gvar('$conflicts')) {
            $this->queryOptions["allConflictType"] = true;
            $this->gstmt[] = "\$conflicts = \$contact->can_view_conflicts(\$prow, \$forceShow) ? \$prow->conflicts() : [];";
        }
        return '$conflicts';
    }
    public function _add_pc() {
        if ($this->check_gvar('$pc'))
            $this->gstmt[] = "\$pc = pcMembers();";
        return '$pc';
    }

    public function _rrow_cid() {
        if ($this->looptype == Fexpr::LNONE)
            return '$rrow_cid';
        else if ($this->looptype == Fexpr::LMY)
            return (string) $this->contact->contactId;
        else
            return '~i~';
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
        list($this->lprefix, $this->lstmt, $this->looptype, $this->datatype) = array_pop($this->_stack);
        $this->indent -= 2;
        $this->lstmt[] = $content;
    }
    public function _addltemp($expr = "null", $always_var = false) {
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

    public function loop_variable() {
        $g = array();
        if ($this->datatype & Fexpr::APCCANREV)
            $g[] = $this->_add_pc_can_review();
        if ($this->datatype & Fexpr::ASUBREV)
            $g[] = $this->_add_submitted_reviewers();
        if ($this->datatype & Fexpr::APREF)
            $g[] = $this->_add_review_prefs();
        if ($this->datatype & Fexpr::ACONF)
            $g[] = $this->_add_conflicts();
        if (count($g) > 1) {
            $gx = str_replace('$', "", join("_and_", $g));
            return $this->define_gvar($gx, join(" + ", $g));
        } else if (count($g))
            return $g[0];
        else
            return $this->define_gvar("trivial_loop", "[0]");
    }
    public function _compile_loop($initial_value, $combiner, AggregateFexpr $e) {
        $t_result = $this->_addltemp($initial_value, true);
        $combiner = str_replace("~r~", $t_result, $combiner);
        $p = $this->_push();
        $this->looptype = Fexpr::LALL;
        $this->datatype = 0;

        preg_match_all('/~l(\d*)~/', $combiner, $m);
        foreach (array_unique($m[1]) as $i) {
            $t = $this->_addltemp($e->args[(int) $i]->compile($this));
            $combiner = str_replace("~l{$i}~", $t, $combiner);
        }

        if (preg_match('/[;}]\s*\z/', $combiner))
            $this->lstmt[] = str_replace("\n", str_pad("\n", $this->indent + 1), $combiner);
        else
            $this->lstmt[] = "$t_result = $combiner;";

        $t_looper = "\$i$p";

        $g = $this->loop_variable();
        $loop = "foreach ($g as \$i$p => \$v$p) " . $this->_join_lstmt(true);
        if ($this->datatype == Fexpr::APREF)
            $loop = str_replace("\$allrevprefs[~i~]", "\$v$p", $loop);
        $loop = str_replace("~i~", "\$i$p", $loop);

        $this->_pop($loop);
        return $t_result;
    }

    public function _compile_my(Fexpr $e) {
        $p = $this->_push();
        $this->looptype = Fexpr::LMY;
        $t = $this->_addltemp($e->compile($this));
        $this->_pop($this->_join_lstmt(false));
        return $t;
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
    private $_format;
    private $_error_html = array();

    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%^]|\*\*?|\&\&?|\|\|?|==?|!=|<[<=]?|>[>=]?|≤|≥|≠)/';

    public static $opprec = array(
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
        global $Me;
        if ($this->_parse !== null)
            return !!$this->_parse;

        $t = $this->expression;
        $expr_len = strlen($this->expression);
        $e = $this->_parse_ternary($t, false);
        if ((string) $this->expression === "")
            $this->_error_html[] = "Empty formula.";
        else if ($t !== "" || !$e) {
            $pfx = substr($this->expression, 0, $expr_len - strlen($t));
            if (strlen($pfx) == $expr_len)
                $this->_error_html[] = "Parse error in formula “" . htmlspecialchars($pfx) . "” at end.";
            else
                $this->_error_html[] = "Parse error in formula “" . htmlspecialchars($pfx) . '<span style="color:red;text-decoration:underline">☞' . htmlspecialchars(substr($this->expression, strlen($pfx))) . "</span>”.";
        } else if (($err = $e->typecheck())) {
            $xe = $err->expr;
            $this->_error_html[] = "Type error in formula “"
                . htmlspecialchars(substr($this->expression, 0, $expr_len + $xe->left_landmark))
                . '<span style="color:red;text-decoration:underline">'
                . htmlspecialchars(substr($this->expression, $expr_len + $xe->left_landmark, $xe->right_landmark - $xe->left_landmark))
                . '</span>'
                . htmlspecialchars(substr($this->expression, $expr_len + $xe->right_landmark))
                . '”: ' . $err->error_html;
        } else {
            $state = new FormulaCompiler($Me);
            $e->compile($state);
            if ($state->datatype && !$this->allowReview)
                $this->_error_html[] = "Illegal formula: can’t return a raw score, use an aggregate function.";
            else {
                $e->text = $this->expression;
                $this->needsReview = !!$state->datatype;
                $this->_format = $e->format();
            }
        }
        $this->_parse = (count($this->_error_html) ? false : $e);
        if ($this->authorView === null)
            $this->authorView = $this->view_score($Me);
        return !!$this->_parse;
    }

    public function error_html() {
        $this->check();
        return join("<br/>", $this->_error_html);
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

    private function _parse_function($op, &$t, $agg) {
        $t = ltrim($t);
        $es = array();

        // collect arguments
        if ($t !== "" && $t[0] === "(") {
            while (1) {
                $t = substr($t, 1);
                if (!($e = $this->_parse_ternary($t, false)))
                    return null;
                $es[] = $e;
                $t = ltrim($t);
                if ($t !== "" && $t[0] === ")")
                    break;
                else if ($t === "" || $t[0] !== ",")
                    return null;
            }
            $t = substr($t, 1);
        } else if (($e = $this->_parse_expr($t, self::$opprec["u+"], false)))
            $es[] = $e;
        else
            return null;

        return $agg ? AggregateFexpr::make($op, $es) : new Fexpr($op, $es);
    }

    static private function _pop_argument($t) {
        if (preg_match(',\s*((?:"[^"]*(?:"|\z)|[^\s()]*)*)(.*)\z,s', $t, $m) && $m[1] !== "")
            return $m;
        else
            return array($t, "", $t);
    }

    const ARGUMENT_REGEX = '((?:"[^"]*"|[-#a-zA-Z0-9_.@+!*\/?~]|:(?=\S))+)';

    static private function field_search_fexpr($fval) {
        $fn = null;
        for ($i = 0; $i < count($fval); $i += 2) {
            list($k, $v) = [$fval[$i], $fval[$i + 1]];
            $fx = ($k === "outcome" ? new DecisionFexpr : new TimeFieldFexpr($k));
            if (is_string($v))
                $fx = new Fexpr(str_replace("0", "", $v), $fx, ConstantFexpr::zero());
            else
                $fx = new InFexpr($fx, $v);
            $fn = $fn ? new Fexpr("&&", $fn, $fx) : $fx;
        }
        return $fn;
    }

    private function _parse_expr(&$t, $level, $in_qc) {
        global $Conf;
        if (($t = ltrim($t)) === "")
            return null;
        $lpos = -strlen($t);

        if ($t[0] === "(") {
            $t = substr($t, 1);
            $e = $this->_parse_ternary($t, false);
            $t = ltrim($t);
            if (!$e || @$t[0] !== ")")
                return null;
            $t = substr($t, 1);
        } else if ($t[0] === "-" || $t[0] === "+" || $t[0] === "!") {
            $op = $t[0];
            $t = substr($t, 1);
            if (!($e = $this->_parse_expr($t, self::$opprec["u$op"], $in_qc)))
                return null;
            $e = $op == "!" ? new NegateFexpr($e) : new Fexpr($op, $e);
        } else if (preg_match('/\Aopt(?:ion)?:\s*(.*)\z/s', $t, $m)) {
            $rest = self::_pop_argument($m[1]);
            $os = PaperSearch::analyze_option_search($rest[1]);
            foreach ($os->warn as $w)
                $this->_error_html[] = $w;
            if (!count($os->os) && !count($os->warn))
                $this->_error_html[] = "“" . htmlspecialchars($rest[1]) . "” doesn’t match a submission option.";
            if (!count($os->os))
                return null;
            $e = null;
            foreach ($os->os as $o) {
                $ex = new OptionFexpr($o[0]);
                if ($o[2] === "special")
                    $this->_error_html[] = "“" . htmlspecialchars($rest[1]) . "” can’t be used in formulas.";
                else if (@$o[3] !== "" && $o[1] == "not in")
                    $ex = new NegateFexpr(new InFexpr($ex, $o[2]));
                else if (@$o[3] !== "" && $o[1] == "in")
                    $ex = new InFexpr($ex, $o[2]);
                else
                    $ex = new Fexpr(@self::$_oprewrite[$o[1]] ? : $o[1], $ex, new ConstantFexpr($o[2], $o[0]));
                $e = $e ? new Fexpr("||", $e, $ex) : $ex;
            }
            if ($os->negate)
                $e = new NegateFexpr($e);
            $t = $rest[2];
        } else if (preg_match('/\Anot([\s(].*|)\z/i', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_expr($t, self::$opprec["u!"], $in_qc)))
                return null;
            $e = new NegateFexpr($e);
        } else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
            $e = new ConstantFexpr($m[1] + 0.0);
            $t = $m[2];
        } else if (preg_match('/\A(false|true)\b(.*)\z/si', $t, $m)) {
            $e = new ConstantFexpr($m[1], "bool");
            $t = $m[2];
        } else if (preg_match('/\A(?:pid|paperid)\b(.*)\z/si', $t, $m)) {
            $e = new ConstantFexpr("\$prow->paperId");
            $t = $m[1];
        } else if (preg_match('/\A(?:dec|decision):\s*' . self::ARGUMENT_REGEX . '(.*)\z/si', $t, $m)) {
            $e = $this->field_search_fexpr(["outcome", PaperSearch::matching_decisions($m[1])]);
            $t = $m[2];
        } else if (preg_match('/\A(?:dec|decision)\b(.*)\z/si', $t, $m)) {
            $e = new DecisionFexpr;
            $t = $m[1];
        } else if (preg_match('/\A(?:is|status):\s*' . self::ARGUMENT_REGEX . '(.*)\z/si', $t, $m)) {
            $e = $this->field_search_fexpr(PaperSearch::status_field_matcher($m[1]));
            $t = $m[2];
        } else if (preg_match('/\A(?:tag(?:\s*:\s*|\s+)|#)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = new TagFexpr($m[1], false);
            $t = $m[2];
        } else if (preg_match('/\Atag(?:v|-?val|-?value)(?:\s*:\s*|\s+)(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
                   || preg_match('/\Atag(?:v|-?val|-?value)\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
            $e = new TagFexpr($m[1], true);
            $t = $m[2];
        } else if (preg_match('/\A(?:r|re|rev|review)(?::|(?=#))\s*'. self::ARGUMENT_REGEX . '(.*)\z/is', $t, $m)) {
            $ex = $m[1];
            $t = $m[2];
            $e = null;
            $tailre = '(?:\z|:)(.*)\z/s';
            while ($ex !== "") {
                if (preg_match('/\A(pri|primary|sec|secondary|ext|external|pc|pcre|pcrev)' . $tailre, $ex, $m)) {
                    $rt = ReviewSearchMatcher::parse_review_type($m[1]);
                    $op = $rt == 0 || $rt == REVIEW_PC ? ">=" : "==";
                    $ee = new Fexpr($op, new RevtypeFexpr, new ConstantFexpr($rt, "revtype"));
                    $ex = $m[2];
                } else if (preg_match('/\Awords' . $tailre, $ex, $m)) {
                    $ee = new ReviewWordCountFexpr;
                    $ex = $m[1];
                } else if (preg_match('/\Areviewer' . $tailre, $ex, $m)) {
                    $ee = new ReviewerFexpr;
                    $ex = $m[1];
                } else if (preg_match('/\Atype' . $tailre, $ex, $m)) {
                    $ee = new RevtypeFexpr;
                    $ex = $m[1];
                } else if (preg_match('/\Around' . $tailre, $ex, $m)) {
                    $ee = new ReviewRoundFexpr;
                    $ex = $m[1];
                } else if (preg_match('/\A([A-Za-z0-9]+)' . $tailre, $ex, $m)
                           && (($round = $Conf->round_number($m[1], false))
                               || $m[1] === "unnamed")) {
                    $ee = new Fexpr("==", new ReviewRoundFexpr, new ConstantFexpr($round, "revround"));
                    $ex = $m[2];
                } else if (preg_match('/\A(..*?|"[^"]+(?:"|\z))' . $tailre, $ex, $m)) {
                    if (($quoted = $m[1][0] === "\""))
                        $m[1] = str_replace(array('"', '*'), array('', '\*'), $m[1]);
                    $ee = new ReviewerMatchFexpr($m[1]);
                    $ex = $m[2];
                } else {
                    $ee = new ConstantFexpr("false", "bool");
                    $ex = "";
                }
                $e = $e ? new Fexpr("&&", $e, $ee) : $ee;
            }
        } else if (preg_match('/\A(my|all|any|avg|average|mean|median|quantile|count|min|max|atminof|atmaxof|std(?:d?ev(?:_pop|_samp|[_.][ps])?)?|sum|var(?:iance)?(?:_pop|_samp|[_.][ps])?|wavg)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, true)))
                return null;
        } else if (preg_match('/\A(greatest|least|round|floor|trunc|ceil|log)\b(.*)\z/s', $t, $m)) {
            $t = $m[2];
            if (!($e = $this->_parse_function($m[1], $t, false)))
                return null;
        } else if (preg_match('/\Anull\b(.*)\z/s', $t, $m)) {
            $e = new ConstantFexpr("null");
            $t = $m[1];
        } else if (preg_match('/\Arevtype\b(.*)\z/s', $t, $m)) {
            $e = new RevtypeFexpr;
            $t = $m[1];
        } else if (preg_match('/\A(?:revround|round)\b(.*)\z/s', $t, $m)) {
            $e = new ReviewRoundFexpr;
            $t = $m[1];
        } else if (preg_match('/\Areviewer\b(.*)\z/s', $t, $m)) {
            $e = new ReviewerFexpr;
            $t = $m[1];
        } else if (preg_match('/\Are(?:|v|view)words\b(.*)\z/s', $t, $m)) {
            $e = new ReviewWordCountFexpr;
            $t = $m[1];
        } else if (preg_match('/\A(?:is:?)?(rev?|pc(?:rev?)?|pri(?:mary)?|sec(?:ondary)?|ext(?:ernal)?)\b(.*)\z/s', $t, $m)) {
            $rt = ReviewSearchMatcher::parse_review_type($m[1]);
            $op = $rt == 0 || $rt == REVIEW_PC ? ">=" : "==";
            $e = new Fexpr($op, new RevtypeFexpr, new ConstantFexpr($rt, "revtype"));
            $t = $m[2];
        } else if (preg_match('/\Atopicscore\b(.*)\z/s', $t, $m)) {
            $e = new TopicScoreFexpr;
            $t = $m[1];
        } else if (preg_match('/\Aconf(?:lict)?\b(.*)\z/s', $t, $m)) {
            $e = new ConflictFexpr(false);
            $t = $m[1];
        } else if (preg_match('/\Apcconf(?:lict)?\b(.*)\z/s', $t, $m)) {
            $e = new ConflictFexpr(true);
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?pref\b(.*)\z/s', $t, $m)) {
            $e = new PrefFexpr(false);
            $t = $m[1];
        } else if (preg_match('/\A(?:rev)?prefexp(?:ertise)?\b(.*)\z/s', $t, $m)) {
            $e = new PrefFexpr(true);
            $t = $m[1];
        } else if (preg_match('/\A([A-Za-z0-9_]+|\".*?\")(.*)\z/s', $t, $m)
                   && $m[1] !== "\"\"") {
            $field = $m[1];
            $t = $m[2];
            if (($quoted = $field[0] === "\""))
                $field = substr($field, 1, strlen($field) - 2);
            if (($f = ReviewForm::field_search($field))
                && $f->has_options)
                $e = new ScoreFexpr($f);
            else if (!$quoted)
                $e = new ConstantFexpr($field, false);
            else
                return null;
        } else
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
            $op = @self::$_oprewrite[$op] ? : $op;
            if (!($e2 = $this->_parse_expr($t, @self::$_oprassoc[$op] ? $opprec : $opprec + 1, $in_qc)))
                return null;
            $e = new Fexpr($op, $e, $e2);
            $e->set_landmark($lpos, -strlen($t));
        }
    }


    public function compile_function(Contact $contact) {
        global $Conf;
        $this->check();
        $state = new FormulaCompiler($contact);
        $expr = $this->_parse ? $this->_parse->compile($state) : "0";

        $loop = "";
        if ($this->needsReview) {
            $g = $state->loop_variable();
            $loop = "\n  if (\$format == \"loop\")
    return array_keys($g);\n";
        }

        $t = "assert(\$contact->contactId == $contact->contactId);\n  "
            . join("\n  ", $state->gstmt)
            . (count($state->gstmt) && count($state->lstmt) ? "\n  " : "")
            . $loop . join("\n  ", $state->lstmt) . "\n"
            . "  \$x = $expr;\n\n"
            . '  if ($format == "s")
    return ($x === true ? 1 : $x);
  else
    return $x;' . "\n";

        $args = '$prow, $rrow_cid, $contact, $format = null, $forceShow = false';
        //$Conf->infoMsg(Ht::pre_text("function ($args) {\n  // " . simplify_whitespace($this->expression) . "\n  $t}\n"));
        return create_function($args, $t);
    }

    public function unparse_html($x) {
        if ($x === null || $x === false)
            return "";
        else if ($x === true)
            return "✓";
        else if ($this->_format === "expertise")
            return ReviewField::unparse_letter(91, $x + 2);
        else if ($this->_format instanceof ReviewField && $this->_format->option_letter)
            return ReviewField::unparse_letter($this->_format->option_letter, $x);
        else
            return round($x * 100) / 100;
    }

    public function unparse_text($x) {
        return $this->unparse_html($x);
    }

    public function add_query_options(&$queryOptions, $contact) {
        if ($this->check()) {
            $state = new FormulaCompiler($contact);
            $state->queryOptions =& $queryOptions;
            $this->_parse->compile($state);
            if ($this->needsReview)
                $state->loop_variable();
        }
    }

    public function base_view_score() {
        $this->check();
        return $this->authorView;
    }

    public function view_score(Contact $contact) {
        return $this->check() ? $this->_parse->view_score($contact) : VIEWSCORE_FALSE;
    }

    public function column_header() {
        return $this->heading ? : ($this->name ? : $this->expression);
    }

    public function needs_review() {
        $this->check();
        return $this->needsReview;
    }

    public function result_format() {
        return $this->check() ? $this->_format : null;
    }
}
