<?php
// paperexpr.inc -- HotCRP helper class for paper expressions
// HotCRP is Copyright (c) 2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("tags.inc");

global $paperExprOperators;
$paperExprOperators = array(
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

class PaperExprCompileState {
    var $contact;
    var $rf;
    var $gtmp;
    var $ltmp;
    var $gstmt;
    var $lstmt;
    var $lprefix;
    var $maxlprefix;
    var $indent;
    var $queryOptions;

    function PaperExprCompileState($contact) {
	$this->contact = $contact;
	$this->rf = reviewForm();
	$this->gtmp = array();
	$this->gstmt = array();
	$this->lstmt = array();
	$this->lprefix = 0;
	$this->maxlprefix = 0;
	$this->indent = 2;
	$this->queryOptions = array();
    }
}

class PaperExpr {

    const UNARY_OPERATOR_REGEX = '/\A[-+!]/';
    const BINARY_OPERATOR_REGEX = '/\A(?:[-\+\/%\&^\|]|[=!]=|\*\*?|\&\&|\|\||<[<=]?|>[>=]?)/';

    function parse($t, $noErrors = false) {
	global $Conf;
	$e = self::_parse_ternary($t);
	if ($t != "") {
	    if (!$noErrors)
		$Conf->errorMsg("Illegal expression: parse error at &ldquo;" . htmlspecialchars($t) . "&rdquo;.");
	    return null;
	} else if (!$e) {
	    if (!$noErrors)
		$Conf->errorMsg("Illegal expression: parse error at end of expression.");
	    return null;
	} else if ($e[1]) {
	    if (!$noErrors)
		$Conf->errorMsg("Illegal expression: can't return a raw score, use an aggregate function.");
	    return null;
	} else {
	    //$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars(var_export($e, true)))));
	    return $e;
	}
    }

    function _parse_ternary(&$t) {
	$e = self::_parse_expr($t, 0);
	if (($t = ltrim($t)) == "" || $t[0] != "?")
	    return $e;
	$t = substr($t, 1);
	if (($e1 = self::_parse_ternary($t)))
	    if (($t = ltrim($t)) != "" && $t[0] == ":") {
		$t = substr($t, 1);
		if (($e2 = self::_parse_ternary($t))) {
		    $perrev = (is_array($e) && $e[1])
			|| (is_array($e1) && $e1[1])
			|| (is_array($e2) && $e2[1]);
		    return array("?:", $perrev, $e, $e1, $e2);
		}
	    }
	return null;
    }

    function _parse_function($op, &$t, $allow_aggregate) {
	$t = ltrim($t);
	$e = array($op, false);

	// collect arguments
	if ($t != "" && $t[0] == "(") {
	    while (1) {
		$t = substr($t, 1);
		if (!($e2 = self::_parse_ternary($t)))
		    return null;
		$e[] = $e2;
		$t = ltrim($t);
		if ($t != "" && $t[0] == ")")
		    break;
		else if ($t == "" || $t[0] != ",")
		    return null;
	    }
	    $t = substr($t, 1);
	} else if (($e2 = self::_parse_expr($t, $paperExprOperators["u+"])))
	    $e[] = $e2;
	else
	    return null;

	// check for aggregateness
	for ($i = 2, $any = false; $i < count($e); ++$i) {
	    if (is_array($e[$i]) && $e[$i][1])
		$any = true;
	}
	if ($any && count($e) == 3 && $allow_aggregate)
	    $e[1] = false;
	else
	    $e[1] = $any;

	return $e;
    }

    function _parse_expr(&$t, $level) {
	global $paperExprOperators;

	if (($t = ltrim($t)) == "")
	    return null;

	if ($t[0] == "(") {
	    $t = substr($t, 1);
	    $e = self::_parse_ternary($t);
	    $t = ltrim($t);
	    if (!$e || $t[0] != ")")
		return null;
	    $t = substr($t, 1);
	} else if ($t[0] == "-" || $t[0] == "+" || $t[0] == "!") {
	    $op = $t[0];
	    $t = substr($t, 1);
	    if (!($e = self::_parse_expr($t, $paperExprOperators["u$op"])))
		return null;
	    $perrev = is_array($e) ? $e[1] : false;
	    $e = array($op, $perrev, $e);
	} else if (preg_match('/\A(\d+\.?\d*|\.\d+)(.*)\z/s', $t, $m)) {
	    $e = $m[1] + 0.0;
	    $t = $m[2];
	} else if (preg_match('/\Atag\s*:?\s*(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
		   || preg_match('/\Atag\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
	    $e = array("tag", false, $m[1]);
	    $t = $m[2];
	} else if (preg_match('/\Atag(?:v|-?val|-?value)\s*:?\s*(' . TAG_REGEX . ')(.*)\z/is', $t, $m)
		   || preg_match('/\Atag(?:v|-?val|-?value)\s*\(\s*(' . TAG_REGEX . ')\s*\)(.*)\z/is', $t, $m)) {
	    $e = array("tagval", false, $m[1]);
	    $t = $m[2];
	} else if (preg_match('/\A(avg|count|min|max|std(?:dev(?:_pop|_samp)?)?|sum|var(?:iance|_pop|_samp)?|wavg)\b(.*)\z/s', $t, $m)) {
	    $t = $m[2];
	    if (!($e = self::_parse_function($m[1], $t, true)))
		return null;
	    if ($e[0] === "wavg" && count($e) == 4)
		$e[1] = false;
	} else if (preg_match('/\A(greatest|least)\b(.*)\z/s', $t, $m)) {
	    $t = $m[2];
	    if (!($e = self::_parse_function($m[1], $t, false)))
		return null;
	} else if (preg_match('/\A([a-zA-Z0-9_]+|\".*?\")(.*)\z/s', $t, $m)
		   && $m[1] != "\"\"") {
	    $field = $m[1];
	    $t = $m[2];
	    if ($field[0] == "\"")
		$field = substr($field, 1, strlen($field) - 2);
	    $rf = reviewForm();
	    if (!($field = $rf->unabbreviateField($field)))
		return null;
	    $e = array("rf", true, $field);
	} else
	    return null;

	$perrev = is_array($e) ? $e[1] : false;

	while (1) {
	    if (($t = ltrim($t)) == ""
		|| !preg_match(self::BINARY_OPERATOR_REGEX, $t, $m))
		return $e;

	    $op = $m[0];
	    $opprec = $paperExprOperators[$op];
	    if ($opprec < $level)
		return $e;

	    $t = substr($t, strlen($op));
	    if (!($e2 = self::_parse_expr($t, $opprec == 12 ? $opprec : $opprec + 1)))
		return null;
	    if (is_array($e2) && $e2[1])
		$perrev = true;

	    $e = array($op, $perrev, $e, $e2);
	}
    }


    function _addgtemp($state, $expr, $name) {
	if (isset($state->gtmp[$name]))
	    return $state->gtmp[$name];
	$tname = "\$tg" . count($state->gtmp);
	$state->gstmt[] = "$tname = $expr;";
	$state->gtmp[$name] = $tname;
	return $tname;
    }

    function _addltemp($state, $expr = "null", $always_var = false) {
	if (!$always_var && preg_match('/\A(?:[\d.]+|\$\w+|null)\z/', $expr))
	    return $expr;
	$tname = "\$t" . $state->lprefix . "_" . count($state->lstmt);
	$state->lstmt[] = "$tname = $expr;";
	return $tname;
    }

    function _addnumscores($state) {
	if (!isset($state->gtmp["numScores"])) {
	    $state->gtmp["numscores"] = "\$numScores";
	    $state->gstmt[] = "\$numScores = (\$contact->canViewReview(\$prow, null, \$fakeWhyNotView, true) ? \$prow->numScores : 0);";
	}
	return "\$numScores";
    }

    function _compilereviewloop($state, $initial_value, $combiner, $e, $e2 = null) {
	$t_result = self::_addltemp($state, $initial_value, true);
	$combiner = str_replace("~r~", $t_result, $combiner);

	$save_lprefix = $state->lprefix;
	$save_lstmt = $state->lstmt;

	$state->lprefix = ++$state->maxlprefix;
	$state->lstmt = array();
	$state->indent += 2;
	$loopval = self::_compile($state, $e);
	if ($e2)
	    $loopval2 = self::_compile($state, $e2);
	$state->indent -= 2;

	$t_loop = self::_addltemp($state, $loopval);
	$combiner = str_replace("~l~", $t_loop, $combiner);
	if ($e2) {
	    $t_loop2 = self::_addltemp($state, $loopval2);
	    $combiner = str_replace("~l2~", $t_loop2, $combiner);
	}
	$state->lstmt[] = "$t_result = $combiner;";

	$t_looper = "\$ri_" . $state->lprefix;
	$indent = "\n" . str_pad("", $state->indent + 2);
	self::_addnumscores($state);
	$loop = "for ($t_looper = 0; $t_looper < \$numScores; ++$t_looper) {" . $indent . join($indent, $state->lstmt) . "\n" . str_pad("", $state->indent) . "}\n";

	$state->lprefix = $save_lprefix;
	$state->lstmt = $save_lstmt;
	$state->lstmt[] = $loop;
	return $t_result;
    }

    function _compile($state, $e) {
	global $paperExprOperators;

	if (!is_array($e))
	    return $e;

	$op = $e[0];

	if ($op == "tag" || $op == "tagval") {
	    $state->queryOptions["tags"] = true;
	    $e_tag = checkTag($e[2], CHECKTAG_QUIET, $state->contact->contactId);
	    $t_tags = self::_addgtemp($state, "(\$contact->canViewTags(\$prow) ? ' ' . \$prow->paperTags : '')", "tags");
	    $t_tagpos = self::_addgtemp($state, "strpos($t_tags, \" $e_tag#\")", "tagpos $e[2]");
	    $t_tagval = self::_addgtemp($state, "($t_tagpos === false ? null : (int) substr($t_tags, $t_tagpos + " . (strlen($e_tag) + 2) . "))", "tagval $e[2]");
	    if ($op == "tag")
		return "($t_tagval === 0 ? true : $t_tagval)";
	    else
		return $t_tagval;
	}

	if ($op == "rf") {
	    $score = $e[2];
	    $revView = $state->contact->viewReviewFieldsScore(null, true);
	    if ($state->rf->authorView[$score] <= $revView)
		$t_f = "0";
	    else {
		if (!isset($state->queryOptions["scores"]))
		    $state->queryOptions["scores"] = array();
		$state->queryOptions["scores"][$score] = true;
		$t_f = self::_addgtemp($state, "explode(',', \$prow->${score}Scores)", "rev $score") . "[\$ri_" . $state->lprefix . "]";
	    }
	    return "($t_f === 0 ? null : $t_f)";
	}

	if ($op == "?:") {
	    $t = self::_addltemp($state, self::_compile($state, $e[2]));
	    $tt = self::_addltemp($state, self::_compile($state, $e[3]));
	    $tf = self::_addltemp($state, self::_compile($state, $e[4]));
	    return "($t ? $tt : $tf)";
	}

	if (count($e) == 3 && isset($paperExprOperators["u$op"])) {
	    $t = self::_addltemp($state, self::_compile($state, $e[2]));
	    return "($t === null ? $t : $op$t)";
	}

	if (count($e) == 4 && isset($paperExprOperators[$op])) {
	    $t1 = self::_addltemp($state, self::_compile($state, $e[2]));
	    $t2 = self::_addltemp($state, self::_compile($state, $e[3]));
	    return "($t1 === null || $t2 === null ? null : $t1 $op $t2)";
	}

	if (count($e) == 3 && $op == "min")
	    return self::_compilereviewloop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ < ~r~) ? ~l~ : ~r~)", $e[2]);

	if (count($e) == 3 && $op == "max")
	    return self::_compilereviewloop($state, "null", "(~l~ !== null && (~r~ === null || ~l~ > ~r~) ? ~l~ : ~r~)", $e[2]);

	if (count($e) == 3 && $op == "count")
	    return self::_compilereviewloop($state, "0", "(~l~ !== null ? ~r~ + 1 : ~r~)", $e[2]);

	if (count($e) == 3 && $op == "sum")
	    return self::_compilereviewloop($state, "null", "(~l~ !== null ? (~r~ !== null ? ~r~ + ~l~ : ~l~) : ~r~)", $e[2]);

	if (count($e) == 3 && ($op == "avg" || $op == "wavg")) {
	    $t = self::_compilereviewloop($state, "array(0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~, ~r~[1] + 1) : ~r~)", $e[2]);
	    return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
	}

	if (count($e) == 4 && $op == "wavg") {
	    $t = self::_compilereviewloop($state, "array(0, 0)", "(~l~ !== null && ~l2~ !== null ? array(~r~[0] + ~l~ * ~l2~, ~r~[1] + ~l2~) : ~r~)", $e[2], $e[3]);
	    return "(${t}[1] ? ${t}[0] / ${t}[1] : null)";
	}

	if (count($e) == 3 && ($op == "variance" || $op == "var" || $op == "var_pop" || $op == "var_samp" || $op == "std" || $op == "stddev" || $op == "stddev_pop" || $op == "stddev_samp")) {
	    $t = self::_compilereviewloop($state, "array(0, 0, 0)", "(~l~ !== null ? array(~r~[0] + ~l~ * ~l~, ~r~[1] + ~l~, ~r~[2] + 1) : ~r~)", $e[2]);
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

    function compile_function_body($e, $contact) {
	global $Conf;
	$state = new PaperExprCompileState($contact);
	$e = self::_compile($state, $e);
	$t =  join("\n  ", $state->gstmt)
	    . (count($state->gstmt) && count($state->lstmt) ? "\n  " : "")
	    . join("\n  ", $state->lstmt) . "\n"
	    . "  \$x = $e;\n"
	    . '  if ($format == "h") {
    if ($x === null)
      return "";
    else if ($x === true)
      return "&#x2713;";
    else
      return round($x * 100) / 100;
  } else if ($format == "s")
    return ($x === true ? 1 : $x);
  else
    return $x;' . "\n";
	//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars("function (\$prow) {\n  " . $t . "}\n"))));
	return $t;
    }

    function compile_function($e, $contact) {
	return create_function("\$prow, \$contact, \$format = null", self::compile_function_body($e, $contact));
    }

    function add_query_options(&$queryOptions, $e, $contact) {
	$state = new PaperExprCompileState($contact);
	$state->queryOptions =& $queryOptions;
	self::_compile($state, $e);
    }

}
