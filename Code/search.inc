<?php
// search.inc -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2010 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $searchTags;
$searchTags = array('ti' => 'ti', 'title' => 'ti',
		    'ab' => 'ab', 'abstract' => 'ab',
		    'au' => 'au', 'author' => 'au',
		    'co' => 'co', 'collab' => 'co', 'collaborators' => 'co',
		    're' => 're', 'rev' => 're', 'review' => 're',
		    'sre' => 'cre', 'srev' => 'cre', 'sreview' => 'cre',
		    'cre' => 'cre', 'crev' => 'cre', 'creview' => 'cre',
		    'subre' => 'cre', 'subrev' => 'cre', 'subreview' => 'cre',
		    'ire' => 'ire', 'irev' => 'ire', 'ireview' => 'ire',
		    'pri' => 'pri', 'primary' => 'pri', 'prire' => 'pri', 'prirev' => 'pri',
		    'cpri' => 'cpri', 'cprimary' => 'cpri',
		    'ipri' => 'ipri', 'iprimary' => 'ipri',
		    'sec' => 'sec', 'secondary' => 'sec', 'secre' => 'sec', 'secrev' => 'sec',
		    'csec' => 'csec', 'csecondary' => 'csec',
		    'isec' => 'isec', 'isecondary' => 'isec',
		    'ext' => 'ext', 'external' => 'ext', 'extre' => 'ext', 'extrev' => 'ext',
		    'cext' => 'cext', 'cexternal' => 'cext',
		    'iext' => 'iext', 'iexternal' => 'iext',
		    'cmt' => 'cmt', 'comment' => 'cmt',
		    'aucmt' => 'aucmt', 'aucomment' => 'aucmt',
		    'tag' => 'tag',
		    'notag' => 'notag',
		    'ord' => 'order', 'order' => 'order',
		    'rord' => 'rorder', 'rorder' => 'rorder',
		    'revord' => 'rorder', 'revorder' => 'rorder',
		    'decision' => 'decision', 'dec' => 'decision',
		    'topic' => 'topic',
		    'option' => 'option', 'opt' => 'option',
		    'lead' => 'lead',
		    'shepherd' => 'shepherd', 'shep' => 'shepherd',
		    'conflict' => 'conflict', 'conf' => 'conflict',
		    'status' => 'status',
		    'rating' => 'rate', 'rate' => 'rate');

class SearchKeyword {
    var $op;
    var $unary;
    var $precedence;
    function SearchKeyword($what, $unary, $precedence) {
	$this->op = $what;
	$this->unary = $unary;
	$this->precedence = $precedence;
    }
}

global $searchKeywords;
$searchKeywords = array("(" => new SearchKeyword("(", true, null),
			"NOT" => new SearchKeyword("NOT", true, 5),
			"-" => new SearchKeyword("NOT", true, 5),
			"+" => new SearchKeyword("+", true, 5),
			"AND" => new SearchKeyword("and", false, 4),
			"OR" => new SearchKeyword("or", false, 3),
			"XAND" => new SearchKeyword("and", false, 2),
			"XOR" => new SearchKeyword("or", false, 2),
			"THEN" => new SearchKeyword("then", false, 1),
			")" => null);


class SearchTerm {
    var $type;
    var $link;
    var $flags;
    var $value;
    var $extra;

    function SearchTerm($t, $f = 0, $v = null, $extra = null) {
	$this->type = $t;
	$this->link = false;
	$this->flags = $f;
	$this->value = $v;
	if ($extra)
	    $this->extra = $extra;
    }
    function combine($combiner, $terms) {
	if (count($terms) == 0)
	    return null;
	else if (count($terms) == 1)
	    return $terms[0];
	else
	    return new SearchTerm($combiner, 0, $terms);
    }
    function negate($term) {
	if (!$term)
	    return null;
	else if ($term->type == "not")
	    return $term->value;
	else
	    return new SearchTerm("not", 0, $term);
    }
}

class PaperSearch {

    const F_TYPEMASK = 0x00007;
    const F_COMPLETE = 0x00008;
    const F_INCOMPLETE = 0x00010;
    const F_NONCONFLICT = 0x00020;
    const F_AUTHOR = 0x00040;
    const F_REVIEWER = 0x00080;
    const F_NONREVIEWER = 0x00100;
    const F_NONBLIND = 0x00200;
    const F_PAPERNONBLIND = 0x00400;
    const F_TIMEWITHDRAWN = 0x00800;
    const F_TIMESUBMITTED = 0x01000;
    const F_AUTHORCOMMENT = 0x02000;
    const F_REVIEWERCOMMENT = 0x04000;
    const F_FALSE = 0x08000;

    var $contactId;
    var $privChair;
    var $amPC;
    var $reviewsOutstanding;

    var $limitName;
    var $qt;
    var $allowAuthor;
    var $fields;
    var $orderTags;
    var $reviewerContact;
    var $matchPreg;
    var $urlbase;
    var $warnings;

    var $q;
    var $qo;
    var $qx;

    var $regex;
    var $overrideMatchPreg;
    var $contactmatch;
    var $contactmatchPC;
    var $noratings;
    var $interestingRatings;
    var $preciseQuery;
    var $needflags;
    var $reviewAdjust;
    var $_reviewAdjustError;
    var $_thenError;
    var $thenmap;

    function PaperSearch($me, $opt) {
	global $Conf, $ConfSiteSuffix;

	// contact facts
	$this->contactId = $me->contactId;
	if ($this->privChair && rcvtint($opt["c"]) > 0)
	    $this->contactId = rcvtint($opt["c"]);
	$this->privChair = $me->privChair;
	$this->amPC = $me->isPC;
	$this->reviewsOutstanding = $me->reviewsOutstanding;

	// paper selection
	$ptype = defval($opt, "t", "");
	if ($ptype === 0)
	    $ptype = "";
	if ($this->privChair && !$ptype && $Conf->timeUpdatePaper())
	    $this->limitName = "all";
	else if (($me->privChair && $ptype == "act")
		 || ($me->isPC && (!$ptype || $ptype == "act" || $ptype == "all") && $Conf->setting("pc_seeall") > 0))
	    $this->limitName = "act";
	else if ($me->isPC && (!$ptype || $ptype == "s"))
	    $this->limitName = "s";
	else if ($me->isPC && ($ptype == "und" || $ptype == "undec"))
	    $this->limitName = "und";
	else if ($me->isPC && ($ptype == "acc" || $ptype == "revs"
			       || $ptype == "reqrevs" || $ptype == "req"
			       || $ptype == "lead"))
	    $this->limitName = $ptype;
	else if ($this->privChair && ($ptype == "all" || $ptype == "unsub"))
	    $this->limitName = $ptype;
	else if ($ptype == "r" || $ptype == "rout" || $ptype == "a")
	    $this->limitName = $ptype;
	else if (!$me->amReviewer())
	    $this->limitName = "a";
	else if (!$me->isAuthor)
	    $this->limitName = "r";
	else
	    $this->limitName = "ar";

	// track other information
	$this->allowAuthor = false;
	if ($me->privChair || $me->isAuthor
	    || ($this->amPC && $Conf->blindSubmission() <= BLIND_OPTIONAL))
	    $this->allowAuthor = true;
	$this->warnings = null;

	// default query fields
	// NB: If a complex query field, e.g., "re", "tag", or "option", is
	// default, then it must be the only default or query construction
	// will break.
	$this->fields = array();
	$qtype = defval($opt, "qt", "n");
	if ($qtype == "n" || $qtype == "ti")
	    $this->fields["ti"] = 1;
	if ($qtype == "n" || $qtype == "ab")
	    $this->fields["ab"] = 1;
	if ($this->allowAuthor && ($qtype == "n" || $qtype == "au" || $qtype == "ac"))
	    $this->fields["au"] = 1;
	if ($this->privChair && $qtype == "ac")
	    $this->fields["co"] = 1;
	if ($this->amPC && $qtype == "re")
	    $this->fields["re"] = 1;
	if ($this->amPC && $qtype == "tag")
	    $this->fields["tag"] = 1;
	$this->qt = ($qtype == "n" ? "" : $qtype);

	// the query itself
	$this->q = trim(defval($opt, "q", ""));
	$this->qo = trim(defval($opt, "qo", ""));
	$this->qx = trim(defval($opt, "qx", ""));

	// URL base
	if (isset($opt["urlbase"]))
	    $this->urlbase = $opt["urlbase"];
	else {
	    $this->urlbase = "search$ConfSiteSuffix?t=" . urlencode($this->limitName);
	    if ($qtype != "n")
		$this->urlbase .= "&qt=" . urlencode($qtype);
	}

	$this->overrideMatchPreg = false;
    }

    function _resetQuery() {
	$this->regex = array();
	$this->contactmatch = array();
	$this->contactmatchPC = true;
	$this->noratings = false;
	$this->interestingRatings = array();
	$this->reviewAdjust = false;
	$this->_reviewAdjustError = false;
	$this->_thenError = false;
	$this->thenmap = null;
	$this->orderTags = array();
	$this->reviewerContact = false;
    }

    function warn($text) {
	if (!$this->warnings)
	    $this->warnings = array();
	$this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    function _fieldPreg($word) {
	if (ctype_alnum($word))
	    return '\b' . $word . '\b';
	else {
	    $a = (ctype_alnum($word[0]) ? '\b' : '');
	    $b = (ctype_alnum($word[strlen($word) - 1]) ? '\b' : '');
	    $word = preg_replace('/\s+/', '\s+', preg_quote($word));
	    $word = str_replace('\*', '\S*', $word);
	    $word = str_replace('\\\\\S*', '\*', $word);
	    return $a . $word . $b;
	}
    }

    function _searchField($word, $rtype, &$qt, $blind) {
	global $Conf;
	$extra = array("regex" => array($rtype, self::_fieldPreg($word)));

	if ($this->privChair
	    || ($this->amPC && !$blind)
	    || ($this->amPC && $Conf->blindSubmission() == BLIND_NEVER))
	    $qt[] = new SearchTerm($rtype, 0, $word, $extra);
	else {
	    if ($blind && $Conf->blindSubmission() == BLIND_ALWAYS
		&& !$Conf->timeReviewerViewAcceptedAuthors())
		/* only look at papers you submitted */;
	    else if ($this->amPC)
		$qt[] = new SearchTerm($rtype, self::F_PAPERNONBLIND, $word, $extra);
	    else {
		$rt = self::F_REVIEWER | ($blind ? self::F_PAPERNONBLIND : 0);
		$qt[] = new SearchTerm($rtype, $rt, $word, $extra);
	    }
	    $qt[] = new SearchTerm($rtype, self::F_AUTHOR, $word, $extra);
	}
    }

    function _cleanCompar($compar) {
	$compar = trim($compar);
	if ($compar == "" || $compar == "==")
	    return "=";
	else if ($compar == "!")
	    return "!=";
	else
	    return $compar;
    }

    function _matchCompar($text, $quoted = true) {
	$text = trim($text);
	if (($text == "any" || $text == "") && !$quoted)
	    return array("any", "", ">0");
	else if ($text == "none" && !$quoted)
	    return array("none", "", "=0");
	else if (ctype_digit($text))
	    return array($text, "", "=" . $text);
	else if (preg_match('/\A(.*?)([<>!]|[<>!=]?=\s*)(\d+)\z/s', $text, $m))
	    return array($m[0], $m[1], self::_cleanCompar($m[2]) . $m[3]);
	else
	    return array($text, $text, ">0");
    }

    function _comparTautology($m) {
	if ($m[2] == "<0")
	    return "f";
	else if ($m[2] == ">=0")
	    return "t";
	else
	    return null;
    }

    function _typeCompar($compar, $value, $type) {
	$compar = self::_cleanCompar($compar);
	if ($value == 0 && $compar == "<")
	    return array("f", null);
	else if ($value == 0 && $compar == ">=")
	    return array("t", null);
	else
	    return array($type, $compar . $value);
    }

    function _sqlNumericSet($a, $neg = false) {
	if (count($a) == 0)
	    return ($neg ? "!" : "") . "=-1";
	else if (count($a) == 1)
	    return ($neg ? "!" : "") . "=" . $a[0];
	else
	    return ($neg ? " not" : "") . " in (" . join(",", $a) . ")";
    }

    function _pcContactIdsWithTag($tag) {
	$a = array();
	foreach (pcMembers() as $pc)
	    if ($pc->contactTags && strpos($pc->contactTags, " $tag ") !== false)
		$a[] = $pc->contactId;
	return $a;
    }

    function _searchReviewer($word, $rtype, &$qt, $quoted) {
	global $Conf;

	$rt = 0;
	if ($rtype == "pri" || $rtype == "cpri" || $rtype == "ipri")
	    $rt = REVIEW_PRIMARY;
	else if ($rtype == "sec" || $rtype == "csec" || $rtype == "isec")
	    $rt = REVIEW_SECONDARY;
	else if ($rtype == "ext" || $rtype == "cext" || $rtype == "iext")
	    $rt = REVIEW_EXTERNAL;
	if ($rtype == "cre" || $rtype == "cpri" || $rtype == "csec" || $rtype == "cext")
	    $rt |= self::F_COMPLETE;
	if ($rtype == "ire" || $rtype == "ipri" || $rtype == "isec" || $rtype == "iext")
	    $rt |= self::F_INCOMPLETE;

	$m = self::_matchCompar($word, $quoted);
	if (($type = self::_comparTautology($m))) {
	    $qt[] = new SearchTerm($type);
	    return;
	}

	if ($m[1] == "") {
	    $type = "nre";
	    $value = array(null, $m[2]);
	}

	if (!$type && $m[1] != "" && $this->amPC && !$quoted
	    && count($pctags = pcTags())) {
	    $negtag = $m[1][0] == "-";
	    $tag = $negtag ? substr($m[1], 1) : $m[1];
	    if (isset($pctags[$tag])) {
		$this->contactmatch[] = "\2contactId" . self::_sqlNumericSet(self::_pcContactIdsWithTag($tag), $negtag);
		if ($negtag)
		    $this->contactmatchPC = false;
		$type = "re";
		$value = array("\1" . (count($this->contactmatch) - 1) . "\1", $m[2]);
	    }
	}

	if (!$type) {
	    $this->contactmatch[] = sqlq_for_like($m[0]);
	    $this->contactmatchPC = false;
	    $type = "re";
	    $value = array("\1" . (count($this->contactmatch) - 1) . "\1", $m[2]);
	}

	$nqt = count($qt);
	if ($this->privChair)
	    $qt[] = new SearchTerm($type, $rt, $value);
	else {
	    $deblinder = 0;
	    if ($type == "re" && $Conf->blindReview() == BLIND_ALWAYS)
		$deblinder = self::F_FALSE;
	    else if ($type == "re" && $Conf->blindReview() == BLIND_OPTIONAL)
		$deblinder = self::F_NONBLIND;

	    if ($this->amPC && $type != "re") {
		// 28.Nov.2008 -- PC users can always count reviews for
		// non-conflicted papers.  The "can see all reviews" settings
		// only apply to reviewer names.  This is a change from prior
		// behavior, but prior behavior wasn't particularly consistent
		// (the "can review any submitted paper" setting was affecting
		// visibility; it shouldn't).
		$qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $value);

	    } else if ($this->amPC) {
		$pc_seeallrev = $Conf->setting("pc_seeallrev");
		$pc_seeblindrev = $Conf->setting("pc_seeblindrev");
		if ($pc_seeallrev == 4 && !$this->reviewsOutstanding)
		    $pc_seeallrev = 1;

		if ($pc_seeallrev == 1
		    && ($deblinder == 0 || $pc_seeblindrev == 0))
		    $qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $value);
		else {
		    $qt[] = new SearchTerm($type, $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
		    if ($pc_seeallrev == 1)
			$qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT | $deblinder, $value);
		    if ($pc_seeallrev == 3)
			$qt[] = new SearchTerm($type, $rt | self::F_NONREVIEWER | self::F_NONCONFLICT | $deblinder, $value);
		}

	    } else if ($Conf->setting("extrev_view") == 2)
		$qt[] = new SearchTerm($type, $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
	    else if ($Conf->setting("extrev_view") == 1)
		$qt[] = new SearchTerm($type, $rt | self::F_REVIEWER | self::F_NONCONFLICT | $deblinder, $value);

	    if ($Conf->timeAuthorViewReviews())
		$qt[] = new SearchTerm($type, ($rt & ~self::F_TYPEMASK) | self::F_AUTHOR | self::F_COMPLETE | $deblinder, $value);
	}
	if (count($qt) == $nqt)
	    $qt[] = new SearchTerm("f");
    }

    function _searchConflict($word, &$qt, $quoted) {
	global $Conf;

	$m = self::_matchCompar($word, $quoted);
	if (($type = self::_comparTautology($m))) {
	    $qt[] = new SearchTerm($type);
	    return;
	}

	$value = null;
	$self_search = false;
	if (!$quoted && ($m[1] == "" || strcasecmp($m[1], "pc") == 0))
	    $value = array_keys(pcMembers());
	else if (!$quoted && strcasecmp($m[1], "me") == 0) {
	    $value = array($this->contactId);
	    $self_search = true;
	} else if (!$quoted && count(($pctags = pcTags()))
	    && isset($pctags[$m[1]])) {
	    $this->contactmatch[] = "\2contactId" . self::_sqlNumericSet(self::_pcContactIdsWithTag($m[1]), false);
	    $value = "\1" . (count($this->contactmatch) - 1) . "\1";
	} else {
	    $this->contactmatch[] = sqlq_for_like($word);
	    $this->contactmatchPC = false;
	    $value = "\1" . (count($this->contactmatch) - 1) . "\1";
	}

	$flags = 0;
	if (!$this->privChair && !$self_search)
	    $flags |= self::F_PAPERNONBLIND;
	$qt[] = new SearchTerm("conflict", $flags, array($value, $m[2]));
    }

    function _searchComment($word, $ctype, &$qt) {
	global $Conf;

	if ($word == "" || $word == "any")
	    $word = ">0";
	else if ($word == "none")
	    $word = "=0";

	if (preg_match('/\A([<>!]?=?\s*)?(\d+)\z/s', $word, $m))
	    list($type, $value) = self::_typeCompar($m[1], $m[2], "ncmt");
	else
	    $type = "f";

	$rt = ($ctype == "aucmt" ? self::F_AUTHORCOMMENT : 0);
	$nqt = count($qt);
	if ($type == "f" || $type == "t")
	    $qt[] = new SearchTerm($type);
	else if ($this->privChair)
	    $qt[] = new SearchTerm($type, $rt, $value);
	else {
	    // XXX may expose information to the PC inappropriately
	    if ($this->amPC)
		$qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $value);
	    else
		$qt[] = new SearchTerm($type, $rt | self::F_REVIEWER | self::F_REVIEWERCOMMENT, $value);
	    if ($Conf->timeAuthorViewReviews())
		$qt[] = new SearchTerm($type, self::F_AUTHOR | self::F_AUTHORCOMMENT, $value);
	}
	if (count($qt) == $nqt)
	    $qt[] = new SearchTerm("f");
    }

    function _searchReviews($word, $rf, $field, &$qt) {
	global $Conf;

	if (isset($rf->options[$field])) {
	    $letters = $rf->reviewFields[$field] > 1;
	    if ($word == "any")
		$value = "$field>0";
	    else if ($word == "none")
		$value = "$field=0";
	    else if (preg_match('/\A(\d*?)([<>]?=?)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
		if ($m[1] == "")
		    $m[1] = 1;
		$m[2] = self::_cleanCompar($m[2]);
		if ($letters != (ctype_digit($m[3]) == false))
		    $value = "$field=-1"; // XXX
		else {
		    if ($letters) {
			// switch meaning of inequality
			if ($m[2][0] == "<")
			    $m[2] = ">" . substr($m[2], 1);
			else if ($m[2][0] == ">")
			    $m[2] = "<" . substr($m[2], 1);
			$m[3] = $rf->reviewFields[$field] - ord(strtoupper($m[3]));
		    }
		    if (($m[3] < 1 && ($m[2][0] == "<" || $m[2][0] == "="))
			|| ($m[3] == 1 && $m[2] == "<")
			|| ($m[3] == count($rf->options[$field]) && $m[2] == ">")
			|| ($m[3] > count($rf->options[$field]) && ($m[2][0] == ">" || $m[2][0] == "=")))
			return false;
		    else
			$value = array($field . $m[2] . $m[3], ">=" . $m[1]);
		}
	    } else if ($letters
		       ? preg_match('/\A\s*([A-Za-z])\s*-?\s*([A-Za-z])\s*\z/s', $word, $m)
		       : preg_match('/\A\s*(\d+)\s*-\s*(\d+)\s*\z/s', $word, $m)) {
		$qo = array();
		$this->_searchReviews($m[1], $rf, $field, $qo);
		$this->_searchReviews($m[2], $rf, $field, $qo);
		if ($this->_searchReviews("<" . $m[1], $rf, $field, $qo))
		    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
		if ($this->_searchReviews(">" . $m[2], $rf, $field, $qo))
		    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
		$qt[] = new SearchTerm("and", 0, $qo);
		return;
	    } else		// XXX
		$value = "$field=-1";
	} else {
	    if ($word == "any")
		$value = "$field!=''";
	    else if ($word == "none")
		$value = "$field=''";
	    else
		$value = "$field like '%" . sqlq_for_like($word) . "%'";
	}

	$nqt = count($qt);
	$rt = self::F_COMPLETE;
	if ($this->privChair)
	    $qt[] = new SearchTerm("rf", $rt, $value);
	else {
	    if ($this->amPC) {
		$pc_seeallrev = $Conf->setting("pc_seeallrev");
		if ($pc_seeallrev == 4 && !$this->reviewsOutstanding)
		    $pc_seeallrev = 1;

		if ($pc_seeallrev == 1)
		    $qt[] = new SearchTerm("rf", $rt | self::F_NONCONFLICT, $value);
		else {
		    $qt[] = new SearchTerm("rf", $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
		    if ($pc_seeallrev == 3)
			$qt[] = new SearchTerm("rf", $rt | self::F_NONREVIEWER | self::F_NONCONFLICT, $value);
		}
	    } else if ($Conf->setting("extrev_view") >= 1)
		$qt[] = new SearchTerm("rf", $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
	    if ($Conf->timeAuthorViewReviews())
		$qt[] = new SearchTerm("rf", ($rt & ~self::F_TYPEMASK) | self::F_AUTHOR | self::F_COMPLETE, $value);
	}
	return count($qt) != $nqt;
    }

    function _searchTags($word, $special, &$qt) {
	global $Conf;
	require_once("tags.inc");

	// allow external reviewers to search their own rank tag
	if (!$this->amPC) {
	    $ranktag = "~" . $Conf->settingText("tag_rank");
	    if (!$Conf->setting("tag_rank")
		|| substr($word, 0, strlen($ranktag)) !== $ranktag
		|| (strlen($word) > strlen($ranktag)
		    && $word[strlen($ranktag)] != "#"))
		return;
	}

	if (preg_match('/\A([^#<>!=]+)(#?)([<>!=]?=?)(-?\d+)\z/', $word, $m)
	    && $m[1] != "any" && $m[1] != "none"
	    && ($m[2] != "" || $m[3] != "")) {
	    $tagword = $m[1];
	    $compar = self::_cleanCompar($m[3]) . $m[4];
	} else {
	    $tagword = $word;
	    $compar = null;
	}

	$twiddle = strpos($tagword, "~");
	$twiddlecid = $this->contactId;
	if ($this->privChair && $twiddle > 0) {
	    $c = substr($tagword, 0, $twiddle);
	    $twiddlecid = matchContact(pcMembers(), null, null, $c);
	    if ($twiddlecid == -2)
		$this->warn("&ldquo;" . htmlspecialchars($c) . "&rdquo; matches no PC member.");
	    else if ($twiddlecid <= 0)
		$this->warn("&ldquo;" . htmlspecialchars($c) . "&rdquo; matches more than one PC member; be more specific to disambiguate.");
	    $tagword = substr($tagword, $twiddle);
	} else if ($twiddle === 0 && $tagword[1] === "~")
	    $twiddlecid = "";

	if (($e = checkTagError($tagword, CHECKTAG_ALLOWRESERVED | CHECKTAG_NOINDEX | ($this->privChair ? CHECKTAG_CHAIR : 0)))) {
	    $this->warn(htmlspecialchars($e));
	    $qt[] = new SearchTerm("f");
	    return;
	}

	$value = $tagword;
	$rt = 0;
	if ($value && $twiddle !== false)
	    $value = $twiddlecid . $value;
	else if (!$this->privChair && $Conf->setting("tag_seeall") <= 0)
	    $rt |= self::F_NONCONFLICT;
	$extra = null;
	if ($special == "order" || $special == "rorder" || !$special)
	    $extra = array("tagorder" => (object) array("tag" => $value, "reverse" => $special == "rorder"));
	$qt[] = new SearchTerm("tag", $rt, $compar ? array($value, $compar) : $value, $extra);
    }

    function _searchOptions($word, &$qt, $error) {
	$type = "option";
	if (preg_match('/\A(.*?)([:#][<>!=]?=?|[<>!=]=?)(.*)\z/', $word, $m)) {
	    $oname = $m[1];
	    if ($m[2][0] == ":" || $m[2][0] == "#")
		$m[2] = substr($m[2], 1);
	    $ocompar = self::_cleanCompar($m[2]);
	    $oval = strtolower(simplifyWhitespace($m[3]));
	} else {
	    $oname = $word;
	    $ocompar = "=";
	    $oval = "";
	}
	$oname = strtolower(simplifyWhitespace($oname));

	// match all options
	$qo = array();
	$option_failure = false;
	foreach (paperOptions() as $oid => $o)
	    // See also checkOptionNameUnique() in settings.php
	    if ($oname == "none" || $oname == "any"
		|| strstr(strtolower($o->optionName), $oname) !== false) {
		// find the relevant values
		if ($oval !== "" && $o->type == OPTIONTYPE_NUMERIC) {
		    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
			$xval = $ocompar . $m[1];
		    else {
			$this->warn("Submission option &ldquo;" . htmlspecialchars($o->optionName) . "&rdquo; takes integer values.");
			$option_failure = true;
			continue;
		    }
		} else if ($oval !== "" && $o->type == OPTIONTYPE_SELECTOR) {
		    $xval = matchValue(explode("\n", $o->optionValues), $oval);
		    if (count($xval) == 0)
			continue;
		    else if (count($xval) == 1)
			$xval = $ocompar . $xval[0];
		    else if ($ocompar != "=" && $ocompar != "!=") {
			$this->warn("Submission option &ldquo;" . htmlspecialchars("$oname:$oval") . "&rdquo; matches multiple values, can't use " . htmlspecialchars($ocompar) . ".");
			$option_failure = true;
			continue;
		    } else
			$xval = ($ocompar == "!=" ? " not in " : " in ")
			    . "(" . join(",", $xval) . ")";
		} else if ($oval === "" || $oval === "yes")
		    $xval = "!=0";
		else if ($oval === "no")
		    $xval = "=0";
		else
		    continue;

		// add the query to $qo
		if ($this->privChair || ($this->amPC && $o->pcView == 1))
		    $qo[] = new SearchTerm($type, 0, array($oid, $xval));
		else {
		    if ($o->pcView) {
			$rt = self::F_REVIEWER | ($o->pcView == 2 ? self::F_PAPERNONBLIND : 0);
			$qo[] = new SearchTerm($type, $rt, array($oid, $xval));
		    }
		    $qo[] = new SearchTerm($type, self::F_AUTHOR, array($oid, $xval));
		}
	    }

	// if "none", negate
	if (count($qo) > 0 && $oname === "none")
	    $qo = array(SearchTerm::negate(SearchTerm::combine("or", $qo)));
	if (count($qo) == 0 && ($option_failure || $error)) {
	    $qo[] = new SearchTerm("f");
	    if (!$option_failure)
		$this->warn("&ldquo;" . htmlspecialchars($word) . "&rdquo; doesn't match a submission option.");
	}
	$qt = array_merge($qt, $qo);
	return count($qo) != 0;
    }

    function _searchPopWord(&$str) {
	preg_match('/\A(-?"[^"]*"?|-?[a-zA-Z][a-zA-Z0-9]*:"[^"]*"?|[^"\s()]+)/s', $str, $m);
	$str = ltrim(substr($str, strlen($m[0])));
	return $m[0];
    }

    function _searchQueryWord($word, &$rest, $unrecognized_keyword_error) {
	global $searchTags, $ratingTypes, $Conf;

	// check for paper number
	if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
	    $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
	    return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
	}

	// Allow searches like "ovemer>2"; parse as "ovemer:>2".
	if (preg_match('/\A([-_A-Za-z0-9]+)([<>!=]=?[^:]+)\z/', $word, $m)) {
	    if (($qe = $this->_searchQueryWord($m[1] . ":" . $m[2], $rest, $qt, false)))
		return $qe;
	}

	$special = null;
	if (($colon = strpos($word, ':')) !== false) {
	    $x = substr($word, 0, $colon);
	    if (isset($searchTags[$x])) {
		$special = $searchTags[$x];
		$word = substr($word, $colon + 1);
	    } else if (strpos($x, '"') === false) {
		$special = $x;
		$word = substr($word, $colon + 1);
	    }
	    if ($special !== null && $word === false)
		$word = $this->_searchPopWord($rest);
	}

	$quoted = ($word[0] == '"');
	$negated = false;
	if ($quoted)
	    $word = str_replace(array('"', '*'), array('', '\*'), $word);
	if ($special ? $special == "notag" : isset($this->fields['notag'])) {
	    $special = "tag";
	    $negated = true;
	}

	$qt = array();
	if ($special ? $special == "ti" : isset($this->fields['ti']))
	    $this->_searchField($word, "ti", $qt, false);
	if ($special ? $special == "ab" : isset($this->fields['ab']))
	    $this->_searchField($word, "ab", $qt, false);
	if ($special == "au" && !$quoted && strcasecmp($word, "pc") == 0)
	    $this->_searchField(array_keys(pcMembers()), "au_cid", false, $qt, true);
	else if ($special ? $special == "au" : isset($this->fields['au']))
	    $this->_searchField($word, "au", $qt, true);
	if ($special ? $special == "co" : isset($this->fields['co']))
	    $this->_searchField($word, "co", $qt, true);
	foreach (array("re", "cre", "ire", "pri", "cpri", "ipri", "sec", "csec", "isec", "ext", "cext", "iext") as $rtype)
	    if ($special ? $special == $rtype : isset($this->fields[$rtype]))
		$this->_searchReviewer($word, $rtype, $qt, $quoted);
	foreach (array("cmt", "aucmt") as $ctype)
	    if ($special ? $special == $ctype : isset($this->fields[$ctype]))
		$this->_searchComment($word, $ctype, $qt);
	if (($special ? $special == "lead" : isset($this->fields['lead']))
	    && $this->amPC) {
	    if ($word == "none")
		$x = "=0";
	    else if ($word == "any")
		$x = "!=0";
	    else {
		$this->contactmatch[] = sqlq_for_like($word);
		$x = "\1" . (count($this->contactmatch) - 1) . "\1";
	    }
	    $rt = ($this->privChair ? 0 : self::F_NONCONFLICT);
	    $qt[] = new SearchTerm("pf", $rt, array("leadContactId", $x));
	}
	if (($special ? $special == "shepherd" : isset($this->fields['shepherd']))
	    && $this->amPC) {
	    // XXX searching author's own shepherds?
	    if ($word == "none")
		$x = "=0";
	    else if ($word == "any")
		$x = "!=0";
	    else {
		$this->contactmatch[] = sqlq_for_like($word);
		$x = "\1" . (count($this->contactmatch) - 1) . "\1";
	    }
	    $rt = ($this->privChair ? 0 : self::F_NONCONFLICT);
	    $qt[] = new SearchTerm("pf", $rt, array("shepherdContactId", $x));
	}
	if (($special ? $special == "tag" : isset($this->fields['tag']))
	    || $special == "order" || $special == "rorder")
	    $this->_searchTags($word, $special, $qt);
	if (($special ? $special == "topic" : isset($this->fields['topic']))) {
	    $type = "topic";
	    $value = null;
	    if ($word == "none" || $word == "any")
		$value = $word;
	    else {
		$rf = reviewForm();
		$x = strtolower(simplifyWhitespace($word));
		$tids = array();
		foreach ($rf->topicName as $tid => $n)
		    if (strstr(strtolower($n), $x) !== false)
			$tids[] = $tid;
		if (count($tids) == 0 && $word != "none" && $word != "any") {
		    $this->warn("&ldquo;" . htmlspecialchars($x) . "&rdquo; does not match any defined paper topic.");
		    $type = "f";
		} else
		    $value = $tids;
	    }
	    if ($type == "f" || $this->amPC)
		$qt[] = new SearchTerm($type, 0, $value);
	    else {
		$qt[] = new SearchTerm($type, self::F_REVIEWER, $value);
		$qt[] = new SearchTerm($type, self::F_AUTHOR, $value);
	    }
	}
	if (($special ? $special == "option" : isset($this->fields['option'])))
	    $this->_searchOptions($word, $qt, true);
	if ($special ? $special == "status" : isset($this->fields['status'])) {
	    if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
		$qt[] = new SearchTerm("pf", 0, array("timeWithdrawn", ">0"));
	    else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
		$qt[] = new SearchTerm("pf", 0, array("timeSubmitted", ">0"));
	    else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
		$qt[] = new SearchTerm("pf", 0, array("timeSubmitted", "<=0", "timeWithdrawn", "<=0"));
	    else {
		$this->warn("Valid search statuses are 'withdrawn', 'submitted', and 'unsubmitted'.");
		$qt[] = new SearchTerm("f");
	    }
	}
	if ($special ? $special == "decision" : isset($this->fields['decision'])) {
	    if (strcasecmp($word, "yes") == 0)
		$value = ">0";
	    else if (strcasecmp($word, "no") == 0)
		$value = "<0";
	    else if ($word == "?")
		$value = "=0";
	    else {
		$rf = reviewForm();
		$value = matchValue($rf->options["outcome"], $word, true);
		if (count($value) == 0) {
		    $this->warn("&ldquo;" . htmlspecialchars($word) . "&rdquo; doesn't match a decision.");
		    $value[] = -10000000;
		}
		$value = self::_sqlNumericSet($value);
	    }
	    $value = array("outcome", $value);
	    $nqt = count($qt);
	    if ($this->privChair
		|| ($this->amPC && $Conf->timePCViewDecision(true)))
		$qt[] = new SearchTerm("pf", 0, $value);
	    else {
		if ($this->amPC && $Conf->timePCViewDecision(false))
		    $qt[] = new SearchTerm("pf", self::F_NONCONFLICT, $value);
		else if ($Conf->timeReviewerViewDecision())
		    $qt[] = new SearchTerm("pf", self::F_REVIEWER, $value);
		if ($Conf->timeAuthorViewDecision())
		    $qt[] = new SearchTerm("pf", self::F_AUTHOR, $value);
	    }
	    if (count($qt) == $nqt)
		$qt[] = new SearchTerm("f");
	}
	if (($special ? $special == "conflict" : isset($this->fields['conflict']))
	    && $this->amPC)
	    $this->_searchConflict($word, $qt, $quoted);
	if (($special ? $special == "round" : isset($this->fields["round"]))
	    && $this->amPC) {
	    $this->reviewAdjust = true;
	    if ($Conf->sversion < 11)
		$qt[] = new SearchTerm("f");
	    else if ($word == "none")
		$qt[] = new SearchTerm("revadj", 0, array("round" => 0));
	    else if ($word == "any")
		$qt[] = new SearchTerm("revadj", 0, array("round" => range(1, count($Conf->settings["rounds"]) - 1)));
	    else {
		$x = simplifyWhitespace($word);
		$rounds = matchValue($Conf->settings["rounds"], $x);
		if (count($rounds) == 0) {
		    $this->warn("&ldquo;" . htmlspecialchars($x) . "&rdquo; doesn't match a review round.");
		    $qt[] = new SearchTerm("f");
		} else
		    $qt[] = new SearchTerm("revadj", 0, array("round" => $rounds));
	    }
	}
	if (($special ? $special == "rate" : isset($this->fields["rate"]))) {
	    $this->reviewAdjust = true;
	    if ($Conf->sversion < 12)
		$qt[] = new SearchTerm("f");
	    else if (preg_match('/\A(.+?)\s*(|[<>!=]?=?)\s*(\d*)\z/', $word, $m)
		     && ($m[3] !== "" || $m[2] === "")) {
		// adjust counts
		if ($m[3] === "") {
		    $m[2] = ">";
		    $m[3] = "0";
		}
		if ($m[2] === "")
		    $m[2] = ($m[3] == 0 ? "=" : ">=");
		else
		    $m[2] = self::_cleanCompar($m[2]);
		$nqt = count($qt);

		// resolve rating type
		if ($m[1] === "+" || $m[1] === "good") {
		    $this->interestingRatings["good"] = ">0";
		    $term = "nrate_good";
		} else if ($m[1] === "-" || $m[1] === "bad"
			   || $m[1] == "\xE2\x88\x92" /* unicode MINUS */) {
		    $this->interestingRatings["bad"] = "<1";
		    $term = "nrate_bad";
		} else {
		    $rf = reviewForm();	/* load for $ratingTypes */
		    $x = array_diff(matchValue($ratingTypes, $m[1]),
				    array("n")); /* don't allow "average" */
		    if (count($x) == 0) {
			$this->warn("Unknown rating type &ldquo;" . htmlspecialchars($m[1]) . "&rdquo;.");
			$qt[] = new SearchTerm("f");
		    } else {
			$type = count($this->interestingRatings);
			$this->interestingRatings[$type] = " in (" . join(",", $x) . ")";
			$term = "nrate_$type";
		    }
		}

		if (count($qt) == $nqt) {
		    if ($m[2][0] === "<" || $m[2] === "!="
			|| ($m[2] === "=" && $m[3] == 0)
			|| ($m[2] === ">=" && $m[3] == 0))
			$term = "coalesce($term,0)";
		    $qt[] = new SearchTerm("revadj", 0, array("rate" => $term . $m[2] . $m[3]));
		}
	    } else {
		$this->warn("Bad review rating query &ldquo;" . htmlspecialchars($word) . "&rdquo;.");
		$qt[] = new SearchTerm("f");
	    }
	}

	// Finally, look for a review field.
	if ($special && !isset($searchTags[$special]) && count($qt) == 0) {
	    $rf = reviewForm();
	    if (($field = $rf->unabbreviateField($special))) {
		if (!$this->_searchReviews($word, $rf, $field, $qt))
		    $qt[] = new SearchTerm("f");
	    } else if (!$this->_searchOptions("$special:$word", $qt, false)
		       && $unrecognized_keyword_error) {
		$this->warn("Unrecognized keyword &ldquo;" . htmlspecialchars($special) . "&rdquo;.");
		$qt[] = new SearchTerm("f");
	    }
	}

	$qe = SearchTerm::combine("or", $qt);
	return $negated ? SearchTerm::negate($qe) : $qe;
    }

    function _searchPopKeyword($str) {
	if (preg_match('/\A([-+()]|(?:AND|OR|NOT|THEN)(?=[\s\(]))/s', $str, $m))
	    return array($m[1], ltrim(substr($str, strlen($m[0]))));
	else
	    return array(null, $str);
    }

    function _searchPopStack($curqe, &$stack) {
	$x = array_pop($stack);
	if (!$curqe)
	    return $x->leftqe;
	else if ($x->kw->op == "NOT")
	    return SearchTerm::negate($curqe);
	else if ($x->kw->op == "+")
	    return $curqe;
	else if ($x->used) {
	    $x->leftqe->value[] = $curqe;
	    return $x->leftqe;
	} else
	    return SearchTerm::combine($x->kw->op, array($x->leftqe, $curqe));
    }

    function _searchQueryType($str, $type) {
	global $searchKeywords, $Conf;

	$stack = array();
	$parens = 0;
	$defaultop = ($type == "all" ? "XAND" : "XOR");
	$curqe = null;
	$lastprec = 1000;

	while ($str !== "") {
	    list($kwstr, $nextstr) = self::_searchPopKeyword($str);
	    $kw = $kwstr ? $searchKeywords[$kwstr] : null;

	    if ($curqe && (!$kw || $kw->unary)) {
		list($kwstr, $kw, $nextstr) =
		    array("", $searchKeywords[$parens ? "XAND" : $defaultop], $str);
	    }

	    if ($kwstr === null) {
		$word = self::_searchPopWord($nextstr);
		$curqe = $this->_searchQueryWord($word, $nextstr, true);
	    } else if ($kwstr == ")") {
		while (count($stack)
		       && $stack[count($stack) - 1]->kw->op != "(")
		    $curqe = self::_searchPopStack($curqe, $stack);
		if (count($stack)) {
		    array_pop($stack);
		    --$parens;
		}
	    } else if ($kwstr == "(") {
		assert(!$curqe);
		$stack[] = (object) array("kw" => $kw, "leftqe" => null, "used" => false);
		++$parens;
	    } else {
		while (count($stack)
		       && $stack[count($stack) - 1]->kw->precedence > $kw->precedence)
		    $curqe = self::_searchPopStack($curqe, $stack);
		$top = count($stack) ? $stack[count($stack) - 1] : null;
		if ($top && !$kw->unary && $top->kw->op == $kw->op) {
		    if ($top->used)
			$top->leftqe->value[] = $curqe;
		    else {
			$top->leftqe = SearchTerm::combine($kw->op, array($top->leftqe, $curqe));
			$top->used = true;
		    }
		} else
		    $stack[] = (object) array("kw" => $kw, "leftqe" => $curqe, "used" => false);
		$curqe = null;
	    }

	    $str = $nextstr;
	}

	while (count($stack))
	    $curqe = self::_searchPopStack($curqe, $stack);
	return $type == "none" ? SearchTerm::negate($curqe) : $curqe;
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).

    function _queryClean($qe, $below = false) {
	global $Conf;
	if (!$qe)
	    return $qe;
	else if ($qe->type == "not")
	    return $this->_queryCleanNot($qe);
	else if ($qe->type == "or")
	    return $this->_queryCleanOr($qe);
	else if ($qe->type == "then")
	    return $this->_queryCleanThen($qe, $below);
	else if ($qe->type == "and")
	    return $this->_queryCleanAnd($qe);
	else
	    return $qe;
    }

    function _queryCleanNot($qe) {
	$qe->value = $qv = $this->_queryClean($qe->value, true);
	if ($qv->type == "not")
	    return $qv->value;
	else if ($qv->type == "pn")
	    return new SearchTerm("pn", 0, array($qv->value[1], $qv->value[0]));
	else if ($qv->type == "revadj") {
	    $x = array();
	    if (isset($qv->value["round"]))
		$x["round"] = array_diff(array_keys($Conf->settings["rounds"]), $qv->value["round"]);
	    if (isset($qv->value["rate"]))
		$x["rate"] = "not (" . $qv->value["rate"] . ")";
	    return new SearchTerm("revadj", 0, $x);
	} else
	    return $qe;
    }

    function _queryCleanOr($qe) {
	$revadj = null;

	for ($i = 0; $i < count($qe->value); ) {
	    $qv = $this->_queryClean($qe->value[$i], true);
	    if ($qv->type == "revadj") {
		if (!$revadj)
		    $revadj = $qv;
		else {
		    if (isset($qv->value["round"]))
			$revadj->value["round"] = array_unique(array_merge(defval($revadj->value, "round", array()), $qv->value["round"]));
		    if (isset($qv->value["rate"]))
			$revadj->value["rate"] = "(" . defval($revadj->value, "rate", "false") . ") or (" . $qv->value["rate"] . ")";
		}
		array_splice($qe->value, $i, 1);
	    } else {
		$qe->value[$i] = $qv;
		++$i;
	    }
	}

	if ($revadj && count($qe->value) == 0)
	    return $revadj;
	else if ($revadj)
	    $this->_reviewAdjustError = true;
	return SearchTerm::combine("or", $qe->value);
    }

    function _queryCleanAnd($qe) {
	$pn = array(array(), array());
	$revadj = null;

	for ($i = 0; $i < count($qe->value); ) {
	    $qe->value[$i] = $qv = $this->_queryClean($qe->value[$i], true);
	    if ($qv->type == "pn") {
		$pn[0] = array_merge($pn[0], $qv->value[0]);
		$pn[1] = array_merge($pn[1], $qv->value[1]);
		array_splice($qe->value, $i, 1);
	    } else if ($qv->type == "revadj") {
		if (!$revadj)
		    $revadj = $qv;
		else {
		    if (isset($revadj->value["round"]) && isset($qv->value["round"]))
			$revadj->value["round"] = array_intersect($revadj->value["round"], $qv->value["round"]);
		    else if (isset($qv->value["round"]))
			$revadj->value["round"] = $qv->value["round"];
		    if (isset($qv->value["rate"]))
			$revadj->value["rate"] = "(" . defval($revadj->value, "rate", "true") . ") and (" . $qv->value["rate"] . ")";
		}
		array_splice($qe->value, $i, 1);
	    } else {
		$qe->value[$i] = $qv;
		++$i;
	    }
	}

	if (count($pn[0]) || count($pn[1]))
	    array_unshift($qe->value, new SearchTerm("pn", 0, $pn));
	if ($revadj)		// must be first
	    array_unshift($qe->value, $revadj);
	return SearchTerm::combine("and", $qe->value);
    }

    function _queryCleanThen($qe, $below) {
	if ($below) {
	    $this->_thenError = true;
	    $qe->type = "or";
	    return $this->_queryCleanOr($qe);
	}
	for ($i = 0; $i < count($qe->value); ) {
	    $qv = $qe->value[$i];
	    if ($qv->type == "then")
		array_splice($qe->value, $i, 1, $qv->value);
	    else {
		$qe->value[$i] = $this->_queryClean($qv, true);
		++$i;
	    }
	}
	return $qe;
    }

    // apply rounds to reviewer searches
    function _queryMakeAdjustedReviewSearch($roundterm) {
	if ($this->limitName == "r" || $this->limitName == "rout")
	    return new SearchTerm("re", 0, "=" . $this->contactId, $roundterm->value);
	else if ($this->limitName == "req" || $this->limitName == "reqrevs")
	    return new SearchTerm("rf", 0, "requestedBy=" . $this->contactId . " and reviewType=" . REVIEW_EXTERNAL, $roundterm->value);
	else
	    return new SearchTerm("nre", 0, array(null, ">0"), $roundterm->value);
    }

    function _queryAdjustReviews($qe, $revadj) {
	$applied = $first_applied = 0;
	if ($qe->type == "not")
	    $this->_queryAdjustReviews($qe->value, $revadj);
	else if ($qe->type == "and") {
	    $myrevadj = ($qe->value[0]->type == "revadj" ? $qe->value[0] : null);
	    if ($myrevadj) {
		$used_revadj = false;
		if (!isset($myrevadj->value["round"]) && isset($revadj->value["round"])) {
		    $myrevadj->value["round"] = $revadj->value["round"];
		    $used_revadj = true;
		}
		if (!isset($myrevadj->value["rate"]) && isset($revadj->value["rate"])) {
		    $myrevadj->value["rate"] = $revadj->value["rate"];
		    $used_revadj = true;
		}
	    }

	    $rdown = $myrevadj ? $myrevadj : $revadj;
	    for ($i = 0; $i < count($qe->value); ++$i)
		if ($qe->value[$i]->type != "revadj")
		    $this->_queryAdjustReviews($qe->value[$i], $rdown);

	    if ($myrevadj && !$myrevadj->extra) {
		$qe->value[0] = $this->_queryMakeAdjustedReviewSearch($myrevadj);
		if ($used_revadj)
		    $revadj->extra = true;
	    }
	} else if ($qe->type == "or" || $qe->type == "then") {
	    for ($i = 0; $i < count($qe->value); ++$i)
		$this->_queryAdjustReviews($qe->value[$i], $revadj);
	} else if (($qe->type == "re" || $qe->type == "nre" || $qe->type == "rf") && $revadj) {
	    $qe->extra = $revadj->value;
	    $revadj->extra = true;
	} else if ($qe->type == "revadj") {
	    assert(!$revadj);
	    return $this->_queryMakeAdjustedReviewSearch($qe);
	}
	return $qe;
    }

    function _queryExtractInfo($qe, $top) {
	if ($qe->type == "and" || $qe->type == "or" || $qe->type == "then") {
	    foreach ($qe->value as $qv)
		$this->_queryExtractInfo($qv, $top && $qe->type == "and");
	}
	if (isset($qe->extra)) {
	    if (($x = defval($qe->extra, "regex"))) {
		$this->regex[$x[0]] = defval($this->regex, $x[0], array());
		$this->regex[$x[0]][] = $x[1];
	    }
	    if (($x = defval($qe->extra, "tagorder")))
		$this->orderTags[] = $x;
	}
	if ($top && $qe->type == "re") {
	    if ($this->reviewerContact === false) {
		$v = $qe->value[0];
		if ($v[0] == "=")
		    $this->reviewerContact = (int) substr($v, 1);
		else if ($v[0] == "\1") {
		    $v = (int) substr($v, 1, strlen($v) - 2);
		    if (count($this->contactmatch[$v]) == 1)
			$this->reviewerContact = $this->contactmatch[$v][0];
		}
	    } else
		$this->reviewerContact = null;
	}
    }


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be conservative; QUERY EVALUATION makes it precise.

    function _clauseTermSetFlags($flags, &$q) {
	global $Conf;

	// Maybe we don't actually need to check blindness.
	if ($flags & self::F_PAPERNONBLIND) {
	    if ($Conf->blindSubmission() == BLIND_NEVER)
		$flags &= ~self::F_PAPERNONBLIND;
	    else if ($Conf->blindSubmission() == BLIND_ALWAYS)
		$flags = ($flags & ~self::F_PAPERNONBLIND) | self::F_FALSE;
	}

	$this->needflags |= $flags;

	if ($flags & self::F_NONCONFLICT)
	    $q[] = "PaperConflict.conflictType is null";
	if ($flags & self::F_AUTHOR)
	    $q[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	if ($flags & self::F_REVIEWER)
	    $q[] = "MyReview.reviewNeedsSubmit=0";
	if ($flags & self::F_NONREVIEWER)
	    $q[] = "MyReview.reviewNeedsSubmit is null";
	if ($flags & self::F_PAPERNONBLIND) {
	    if ($Conf->timeReviewerViewAcceptedAuthors())
		$this->preciseQuery = true;
	    else
		$q[] = "Paper.blind=0";
	}
	if ($flags & self::F_FALSE)
	    $q[] = "false";
    }

    function _clauseTermSetField(&$t, $field, $negated, &$f) {
	$this->needflags |= $t->flags;
	$v = $t->value;
	if ($v && $v[0] == "*")
	    $v = substr($v, 1);
	if ($v && $v[strlen($v) - 1] == "*")
	    $v = substr($v, 0, strlen($v) - 1);
	if ($negated)
	    $f[] = "false";
	else if (!ctype_alnum($v))
	    $f[] = "true";
	else {
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "Paper.$field like '%$v%'";
	    $f[] = "(" . join(" and ", $q) . ")";
	}
	$t->link = $field;
	$this->preciseQuery = true;
    }

    function _clauseTermSetTable(&$t, $value, $compar, $shorttab,
				 $table, $field, $where,
				 &$tables, &$columns, &$f) {
	// see also first "tag" case below
	$q = array();
	$this->_clauseTermSetFlags($t->flags, $q);

	if ($value == "none" && !$compar)
	    list($compar, $value) = array("=0", "");
	else if (($value == "" || $value == "any") && !$compar)
	    list($compar, $value) = array(">0", "");
	else if (!$compar || $compar == ">=1")
	    $compar = ">0";
	else if ($compar == "<=0" || $compar == "<1")
	    $compar = "=0";

	$thistab = $shorttab . "_" . count($tables);
	if ($value == "") {
	    if ($compar == ">0" || $compar == "=0")
		$thistab = "Any" . $shorttab;
	    $tdef = array("left join", $table);
	} else if (is_array($value)) {
	    if (count($value))
		$tdef = array("left join", $table, "$thistab.$field in (" . join(",", $value) . ")");
	    else
		$tdef = array("left join", $table, "false");
	} else if ($value[0] == "\1") {
	    $tdef = array("left join", $table, "$thistab.$field" . $value);
	} else {
	    $tdef = array("left join", $table, "$thistab.$field='" . sqlq($value) . "'");
	}
	if ($where)
	    $tdef[2] .= str_replace("%", $thistab, $where);

	if ($compar != ">0" && $compar != "=0") {
	    $tdef[1] = "(select paperId, count(*) ct from " . $tdef[1] . " as " . $thistab;
	    if (count($tdef) > 2)
		$tdef[1] .= " where " . array_pop($tdef);
	    $tdef[1] .= " group by paperId)";
	    $columns[$thistab . "_ct"] = "$thistab.ct";
	    $q[] = "coalesce($thistab.ct,0)$compar";
	} else {
	    $columns[$thistab . "_ct"] = "count($thistab.$field)";
	    if ($compar == "=0")
		$q[] = "$thistab.$field is null";
	    else
		$q[] = "$thistab.$field is not null";
	}

	$tables[$thistab] = $tdef;
	$t->link = $thistab . "_ct";
	$f[] = "(" . join(" and ", $q) . ")";
    }

    function unusableRatings($privChair, $contactId) {
	global $Conf;
	if ($privChair || $Conf->timePCViewAllReviews())
	    return array();
	$noratings = array();
	$rateset = $Conf->setting("rev_rating");
	if ($rateset == REV_RATINGS_PC)
	    $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
	else
	    $npr_constraint = "true";
	// This query supposedly returns those reviewIds whose ratings
	// are not visible to the current querier
	$result = $Conf->q("select MPR.reviewId
	from PaperReview as MPR
	left join (select paperId, count(reviewId) as numReviews from PaperReview where $npr_constraint and reviewNeedsSubmit<=0 group by paperId) as NPR on (NPR.paperId=MPR.paperId)
	left join (select paperId, count(rating) as numRatings from PaperReview join ReviewRating using (reviewId) group by paperId) as NRR on (NRR.paperId=MPR.paperId)
	where MPR.contactId=$contactId
	and numReviews<=2
	and numRatings<=2");
	while (($row = edb_row($result)))
	    $noratings[] = $row[0];
	return $noratings;
    }

    function _clauseTermSetRating(&$reviewtable, &$where, $rate) {
	global $Conf;

	$noratings = "";
	if ($this->noratings === false)
	    $this->noratings = self::unusableRatings($this->privChair, $this->contactId);
	if (count($this->noratings) > 0)
	    $noratings .= " and not (reviewId in (" . join(",", $this->noratings) . "))";
	else
	    $noratings = "";

	$reviewtable = "PaperReview";
	foreach ($this->interestingRatings as $k => $v)
	    $reviewtable .= " left join (select reviewId, count(rating) as nrate_$k from ReviewRating where rating$v$noratings group by reviewId) as Ratings_$k on (Ratings_$k.reviewId=PaperReview.reviewId)";
	$where[] = $rate;
    }

    function _clauseTermSet(&$t, $negated, &$tables, &$columns, &$f) {
	global $Conf;
	$tt = $t->type;
	$thistab = null;

	// collect columns
	if ($tt == "ti") {
	    $columns["title"] = "Paper.title";
	    $this->_clauseTermSetField($t, "title", $negated, $f);
	} else if ($tt == "ab") {
	    $columns["abstract"] = "Paper.abstract";
	    $this->_clauseTermSetField($t, "abstract", $negated, $f);
	} else if ($tt == "au") {
	    $columns["authorInformation"] = "Paper.authorInformation";
	    $this->_clauseTermSetField($t, "authorInformation", $negated, $f);
	} else if ($tt == "co") {
	    $columns["collaborators"] = "Paper.collaborators";
	    $this->_clauseTermSetField($t, "collaborators", $negated, $f);
	} else if ($tt == "au_cid") {
	    $this->_clauseTermSetTable($t, $t->value, null, "AuthorConflict",
				       "PaperConflict", "contactId", " and %.conflictType>=" . CONFLICT_AUTHOR,
				       $tables, $columns, $f);
	} else if ($tt == "nre") {
	    $rflags = $t->flags & (self::F_TYPEMASK | self::F_NONBLIND | self::F_COMPLETE | self::F_INCOMPLETE);
	    $rtype = $rflags;
	    if (isset($t->extra))
		$rtype = "X" . count($tables);
	    if (!isset($tables["Numreviews_$rtype"])) {
		$where = array();
		$reviewtable = "PaperReview";
		if ($rflags & self::F_TYPEMASK)
		    $where[] = "reviewType=" . ($rflags & self::F_TYPEMASK);
		if ($rflags & self::F_NONBLIND)
		    $where[] = "reviewBlind=0";
		if ($rflags & self::F_COMPLETE)
		    $where[] = "reviewSubmitted>0";
		else if ($rflags & self::F_INCOMPLETE)
		    $where[] = "reviewNeedsSubmit>0";
		if (isset($t->extra) && isset($t->extra["round"])) {
		    if (count($t->extra["round"]) == 0)
			$where[] = "false";
		    else
			$where[] = "reviewRound in (" . join(",", $t->extra["round"]) . ")";
		}
		if (isset($t->extra) && isset($t->extra["rate"]))
		    $this->_clauseTermSetRating($reviewtable, $where, $t->extra["rate"]);
		$wheretext = "";
		if (count($where))
		    $wheretext = " where " . join(" and ", $where);
		$tables["Numreviews_$rtype"] = array("left join", "(select paperId, count(PaperReview.reviewId) as nre from $reviewtable$wheretext group by paperId)");
	    }
	    $columns["nre_$rtype"] = "Numreviews_$rtype.nre";
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "coalesce(Numreviews_$rtype.nre,0)" . $t->value[1];
	    $f[] = "(" . join(" and ", $q) . ")";
	    $t->link = "nre_$rtype";
	} else if ($tt == "re" || $tt == "rf") {
	    $thistab = "Reviews_" . count($tables);
	    $reviewtable = "PaperReview";
	    $where = array();
	    if ($t->flags & self::F_TYPEMASK)
		$where[] = "reviewType=" . ($t->flags & self::F_TYPEMASK);
	    if ($t->flags & self::F_NONBLIND)
		$where[] = "reviewBlind=0";
	    if ($t->flags & self::F_COMPLETE)
		$where[] = "reviewSubmitted>0";
	    else if ($t->flags & self::F_INCOMPLETE)
		$where[] = "reviewNeedsSubmit>0";
	    if (isset($t->extra) && isset($t->extra["round"])) {
		if (count($t->extra["round"]) == 0)
		    $where[] = "false";
		else
		    $where[] = "reviewRound in (" . join(",", $t->extra["round"]) . ")";
	    }
	    if (isset($t->extra) && isset($t->extra["rate"]))
		$this->_clauseTermSetRating($reviewtable, $where, $t->extra["rate"]);
	    if (!is_array($t->value))
		$t->value = array($t->value, ">=1");
	    if ($tt == "re")
		$where[] = "contactId" . $t->value[0];
	    else
		$where[] = $t->value[0];
	    $wheretext = " where " . join(" and ", $where);
	    $tables[$thistab] = array("left join", "(select paperId, count(PaperReview.reviewId) as nre from $reviewtable$wheretext group by paperId)");
	    $columns[$thistab . "_matches"] = "$thistab.nre";
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "coalesce($thistab.nre,0)" . $t->value[1];
	    $f[] = "(" . join(" and ", $q) . ")";
	    $t->link = $thistab . "_matches";
	} else if ($tt == "conflict") {
	    $this->_clauseTermSetTable($t, $t->value[0], $t->value[1], "Conflict",
				       "PaperConflict", "contactId", "",
				       $tables, $columns, $f);
	} else if ($tt == "ncmt") {
	    $rtype = $t->flags & (self::F_REVIEWERCOMMENT | self::F_AUTHORCOMMENT);
	    if (!isset($tables["Numcomments_$rtype"])) {
		$where = array();
		if ($rtype & self::F_REVIEWERCOMMENT)
		    $where[] = "forReviewers!=0";
		if ($rtype & self::F_AUTHORCOMMENT)
		    $where[] = "forAuthors>0";
		$wheretext = "";
		if (count($where))
		    $wheretext = " where " . join(" and ", $where);
		$tables["Numcomments_$rtype"] = array("left join", "(select paperId, count(commentId) as ncmt from PaperComment$wheretext group by paperId)");
	    }
	    $columns["ncmt_$rtype"] = "Numcomments_$rtype.ncmt";
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "coalesce(Numcomments_$rtype.ncmt,0)$t->value";
	    $f[] = "(" . join(" and ", $q) . ")";
	    $t->link = "ncmt_$rtype";
	} else if ($tt == "pn") {
	    $q = array();
	    if (count($t->value[0]))
		$q[] = "Paper.paperId in (" . join(",", $t->value[0]) . ")";
	    if (count($t->value[1]))
		$q[] = "Paper.paperId not in (" . join(",", $t->value[1]) . ")";
	    if (!count($q))
		$q[] = "false";
	    $f[] = "(" . join(" and ", $q) . ")";
	} else if ($tt == "pf") {
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    for ($i = 0; $i < count($t->value); $i += 2)
		$q[] = "Paper." . $t->value[$i] . $t->value[$i + 1];
	    $f[] = "(" . join(" and ", $q) . ")";
	} else if ($tt == "tag" && is_array($t->value)) {
	    $this->_clauseTermSetTable($t, $t->value[0], null, "Tag",
				       "PaperTag", "tag", " and %.tagIndex" . $t->value[1],
				       $tables, $columns, $f);
	} else if ($tt == "tag") {
	    $this->_clauseTermSetTable($t, $t->value, null, "Tag",
				       "PaperTag", "tag", "",
				       $tables, $columns, $f);
	} else if ($tt == "topic") {
	    $this->_clauseTermSetTable($t, $t->value, null, "Topic",
				       "PaperTopic", "topicId", "",
				       $tables, $columns, $f);
	} else if ($tt == "option") {
	    // expanded from _clauseTermSetTable
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $thistab = "Option_" . count($tables);
	    $tables[$thistab] = array("left join", "PaperOption", "$thistab.optionId=" . $t->value[0]);
	    $columns[$thistab . "_x"] = "coalesce($thistab.value,0)" . $t->value[1];
	    $t->link = $thistab . "_x";
	    $q[] = $columns[$t->link];
	    $f[] = "(" . join(" and ", $q) . ")";
	} else if ($tt == "not") {
	    $ff = array();
	    $this->_clauseTermSet($t->value, true, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "not (" . join(" or ", $ff) . ")";
	} else if ($tt == "and") {
	    $ff = array();
	    foreach ($t->value as $subt)
		$this->_clauseTermSet($subt, false, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "(" . join(" and ", $ff) . ")";
	} else if ($tt == "or" || $tt == "then") {
	    $ff = array();
	    foreach ($t->value as $subt)
		$this->_clauseTermSet($subt, false, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "(" . join(" or ", $ff) . ")";
	} else if ($tt == "f")
	    $f[] = "false";
	else if ($tt == "t")
	    $f[] = "true";

	if ($tt == "pf")
	    for ($i = 0; $i < count($t->value); $i += 2)
		$columns[$t->value[$i]] = "Paper." . $t->value[$i];
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    function _clauseTermCheckFlags($flags, &$row) {
	global $Conf;
	if (($flags & self::F_AUTHOR) && $row->conflictType < CONFLICT_AUTHOR)
	    return false;
	if (($flags & self::F_REVIEWER)
	    && $row->myReviewNeedsSubmit !== 0
	    && $row->myReviewNeedsSubmit !== "0")
	    return false;
	if (($flags & self::F_NONREVIEWER)
	    && $row->myReviewNeedsSubmit !== null)
	    return false;
	if (($flags & self::F_NONCONFLICT) && $row->conflictType)
	    return false;
	// NB: F_REVIEWER must be set by caller if necessary
	if (($flags & self::F_PAPERNONBLIND)
	    && $Conf->blindSubmission() != BLIND_NEVER
	    && ($row->paperBlind || $Conf->blindSubmission() == BLIND_ALWAYS)
	    && !($row->outcome > 0 && $Conf->timeReviewerViewAcceptedAuthors()))
	    return false;
	if ($flags & self::F_FALSE)
	    return false;
	return true;
    }

    function _clauseTermCheckField(&$t, &$row) {
	if (!$this->_clauseTermCheckFlags($t->flags, $row))
	    return false;
	$m = self::_fieldPreg($t->value);
	$fieldname = $t->link;
	if (!preg_match('{' . $m . '}i', $row->$fieldname))
	    return false;
	return true;
    }

    function _clauseTermCheck(&$t, &$row) {
	global $Conf;
	$tt = $t->type;

	// collect columns
	if ($tt == "ti" || $tt == "ab" || $tt == "au" || $tt == "co")
	    return $this->_clauseTermCheckField($t, $row);
	else if ($tt == "nre" || $tt == "re" || $tt == "rf"
		 || $tt == "conflict") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		return false;
	    else {
		$fieldname = $t->link;
		$x = $row->$fieldname;
		return eval("return \$x" . $t->value[1] . ";");
	    }
	} else if ($tt == "ncmt") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		return false;
	    else {
		$fieldname = $t->link;
		$x = $row->$fieldname;
		return eval("return \$x$t->value;");
	    }
	} else if ($tt == "pn") {
	    if (count($t->value[0]) && array_search($row->paperId, $t->value[0]) === false)
		return false;
	    else if (count($t->value[1]) && array_search($row->paperId, $t->value[1]) !== false)
		return false;
	    else
		return true;
	} else if ($tt == "pf") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		return false;
	    else {
		$ans = true;
		for ($i = 0; $ans && $i < count($t->value); $i += 2) {
		    $fieldname = $t->value[$i];
		    $expr = $t->value[$i + 1];
		    if ($expr[0] == '=')
			$ans = $row->$fieldname == substr($expr, 1);
		    else if ($expr[0] == '!')
			$ans = $row->$fieldname != substr($expr, 2);
		    else if ($expr[0] == '<' && $expr[1] == '=')
			$ans = $row->$fieldname <= substr($expr, 2);
		    else if ($expr[0] == '>' && $expr[1] == '=')
			$ans = $row->$fieldname >= substr($expr, 2);
		    else if ($expr[0] == '<')
			$ans = $row->$fieldname < substr($expr, 1);
		    else if ($expr[0] == '>')
			$ans = $row->$fieldname > substr($expr, 1);
		    else if ($expr[0] == "\1")
			$ans = array_search($row->$fieldname, $this->contactmatch[substr($expr, 1, strlen($expr) - 2)]) !== false;
		    else
			$ans = false;
		}
		return $ans;
	    }
	} else if ($tt == "tag" || $tt == "topic" || $tt == "option") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		return false;
	    else {
		$fieldname = $t->link;
		if (is_string($t->value) && $t->value == "none")
		    return $row->$fieldname == 0;
		else
		    return $row->$fieldname != 0;
	    }
	} else if ($tt == "not") {
	    return !$this->_clauseTermCheck($t->value, $row);
	} else if ($tt == "and") {
	    foreach ($t->value as $subt)
		if (!$this->_clauseTermCheck($subt, $row))
		    return false;
	    return true;
	} else if ($tt == "or" || $tt == "then") {
	    foreach ($t->value as $subt)
		if ($this->_clauseTermCheck($subt, $row))
		    return true;
	    return false;
	} else if ($tt == "f")
	    return false;
	else if ($tt == "t")
	    return true;
	else
	    return true;
    }


    // BASIC QUERY FUNCTION

    function search() {
	global $Conf;

	$this->_resetQuery();
	if ($this->limitName == "x")
	    return array("create temporary table Matches select Paper.paperId from Paper where false", "");

	// collect query terms
	$qt = array();
	if (($qe = $this->_searchQueryType($this->q, "all")))
	    $qt[] = $qe;
	if (($qe = $this->_searchQueryType($this->qo, "any")))
	    $qt[] = $qe;
	if (($qe = $this->_searchQueryType($this->qx, "none")))
	    $qt[] = $qe;

	// clean the query
	$qe = $this->_queryClean(SearchTerm::combine("and", $qt));
	if (!$qe)
	    $qe = new SearchTerm("t");

	// apply review rounds (top down, needs separate step)
	if ($this->reviewAdjust) {
	    $qe = $this->_queryAdjustReviews($qe, null);
	    if ($this->_reviewAdjustError)
		$Conf->errorMsg("Unexpected use of &ldquo;round:&rdquo; or &ldquo;rate:&rdquo; ignored.  Stick to the basics, such as &ldquo;re:reviewername round:roundname&rdquo;.");
	}

	//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars(var_export($qe, true)))));

	// collect clauses into tables, columns, and filters
	$tables = array("Paper" => true);
	$columns = array("paperId" => "Paper.paperId");
	$filters = array();
	$this->preciseQuery = false;
	$this->needflags = 0;
	$this->_clauseTermSet($qe, false, $tables, $columns, $filters);
	//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars(var_export($filters, true)))));

	// status limitation parts
	if ($this->limitName == "s" || $this->limitName == "req"
	    || $this->limitName == "acc" || $this->limitName == "und")
	    $filters[] = "Paper.timeSubmitted>0";
	else if ($this->limitName == "act")
	    $filters[] = "Paper.timeWithdrawn<=0";
	else if ($this->limitName == "unsub")
	    $filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";
	else if ($this->limitName == "lead")
	    $filters[] = "Paper.leadContactId=" . $this->contactId;

	// decision limitation parts
	if ($this->limitName == "acc")
	    $filters[] = "Paper.outcome>0";
	else if ($this->limitName == "und")
	    $filters[] = "Paper.outcome=0";

	// other search limiters
	if ($this->limitName == "a") {
	    $filters[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	    $this->needflags |= self::F_AUTHOR;
	} else if ($this->limitName == "r") {
	    $filters[] = "MyReview.reviewType is not null";
	    $this->needflags |= self::F_REVIEWER;
	} else if ($this->limitName == "ar") {
	    $filters[] = "(PaperConflict.conflictType>=" . CONFLICT_AUTHOR . " or MyReview.reviewType is not null)";
	    $this->needflags |= self::F_AUTHOR | self::F_REVIEWER;
	} else if ($this->limitName == "rout") {
	    $filters[] = "MyReview.reviewNeedsSubmit!=0";
	    $this->needflags |= self::F_REVIEWER;
	} else if ($this->limitName == "revs")
	    $tables["Limiter"] = array("join", "PaperReview");
	else if ($this->limitName == "req")
	    $tables["Limiter"] = array("join", "PaperReview", "Limiter.requestedBy=$this->contactId and Limiter.reviewType=" . REVIEW_EXTERNAL);

	// add common tables: conflicts, my own review, paper blindness
	if ($this->needflags & (self::F_NONCONFLICT | self::F_AUTHOR)) {
	    $tables["PaperConflict"] = array("left join", "PaperConflict", "PaperConflict.contactId=$this->contactId");
	    $columns["conflictType"] = "PaperConflict.conflictType";
	}
	if ($this->needflags & (self::F_REVIEWER | self::F_NONREVIEWER)) {
	    $qb = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qb = " or MyReview.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
	    $tables["MyReview"] = array("left join", "PaperReview", "(MyReview.contactId=$this->contactId$qb)");
	    $columns["myReviewNeedsSubmit"] = "MyReview.reviewNeedsSubmit";
	}
	if ($this->needflags & self::F_PAPERNONBLIND) {
	    $columns["paperBlind"] = "Paper.blind";
	    if ($Conf->timeReviewerViewAcceptedAuthors())
		$columns["outcome"] = "Paper.outcome";
	}

	// search contacts
	if (count($this->contactmatch)) {
	    $qa = "select ContactInfo.contactId";
	    $qb = " from ContactInfo"
		. ($this->contactmatchPC ? " join PCMember using (contactId)" : "")
		. " where ";
	    for ($i = 0; $i < count($this->contactmatch); ++$i) {
		$s = simplifyWhitespace($this->contactmatch[$i]);
		if ($s[0] == "\2")
		    $qm = "(" . substr($s, 1) . ")";
		else if (($pos = strpos($s, "@")) !== false)
		    $qm = "(email like '" . substr($s, 0, $pos + 1) . "%" . substr($s, $pos + 1) . "%')";
		else if (preg_match('/\A(.*?)\s*([,\s])\s*(.*)\z/', $s, $m)) {
		    if ($m[2] == ",")
			$qm = "(firstName like '" . trim($m[3]) . "%' and lastName like '" . trim($m[1]) . "%')";
		    else
			$qm = "(concat(firstName, ' ', lastName) like '%$s%')";
		} else
		    $qm = "(firstName like '%$s%' or lastName like '%$s%' or email like '%$s%')";
		$qa .= (count($this->contactmatch) == 1 ? ", true" : ", $qm");
		$qb .= ($i == 0 ? "" : " or ") . $qm;
	    }
	    //$Conf->infoMsg(htmlspecialchars($qa . $qb));
	    $result = $Conf->q($qa . $qb);
	    $contacts = array_fill(0, count($this->contactmatch), array());
	    while (($row = edb_row($result)))
		for ($i = 0; $i < count($this->contactmatch); ++$i)
		    if ($row[$i + 1])
			$contacts[$i][] = $row[0];
	    $this->contactmatch = $contacts;
	}

	// create query
	$q = "select ";
	foreach ($columns as $colname => $value)
	    $q .= $value . " " . $colname . ", ";
	$q = substr($q, 0, strlen($q) - 2) . " from ";
	foreach ($tables as $tabname => $value)
	    if ($value === true)
		$q .= $tabname;
	    else {
		$joiners = array("$tabname.paperId=Paper.paperId");
		for ($i = 2; $i < count($value); ++$i)
		    $joiners[] = $value[$i];
		$q .= " " . $value[0] . " " . $value[1] . " as " . $tabname
		    . " on (" . join(" and ", $joiners) . ")";
	    }
	if (count($filters))
	    $q .= " where " . join(" and ", $filters);
	$q .= " group by Paper.paperId";

	// clean up contact matches
	if (count($this->contactmatch))
	    for ($i = 0; $i < count($this->contactmatch); $i++)
		$q = str_replace("\1$i\1", self::_sqlNumericSet($this->contactmatch[$i]), $q);
	//$Conf->infoMsg(htmlspecialchars($q));

	// actually perform query
	if (!$Conf->qe("create temporary table Matches $q", "while performing search"))
	    return false;

	// correct query
	$this->thenmap = ($qe->type == "then" ? array() : null);
	if ($this->preciseQuery || $this->thenmap !== null) {
	    $delete = array();
	    $result = $Conf->qe("select * from Matches", "while performing search");
	    while (($row = edb_orow($result))) {
		if ($this->thenmap !== null) {
		    $x = false;
		    for ($i = 0; $i < count($qe->value) && $x === false; ++$i)
			if ($this->_clauseTermCheck($qe->value[$i], $row))
			    $x = $i;
		} else
		    $x = !!$this->_clauseTermCheck($qe, $row);
		if ($x === false)
		    $delete[] = $row->paperId;
		else if ($this->thenmap !== null)
		    $this->thenmap[$row->paperId] = $x;
	    }
	    if (count($delete)) {
		$q = "delete from Matches where paperId in (" . join(",", $delete) . ")";
		//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars($q))));
		if (!$Conf->qe($q, "while performing search"))
		    return false;
	    }
	}

	// extract regular expressions and set reviewerContact if the query is
	// about exactly one reviewer
	$this->_queryExtractInfo($qe, true);

	// set $this->matchPreg from $this->regex
	if (!$this->overrideMatchPreg) {
	    $this->matchPreg = array();
	    foreach (array("ti" => "title", "au" => "authorInformation",
			   "ab" => "abstract", "co" => "collaborators")
		     as $k => $v)
		if (isset($this->regex[$k]) && count($this->regex[$k]))
		    $this->matchPreg[$v] = join("|", $this->regex[$k]);
	}

	return true;
    }

    function complexSearch(&$queryOptions) {
	if ($this->q || $this->qo || $this->qx)
	    return true;
	if ($this->limitName == "s" || $this->limitName == "revs")
	    $queryOptions["finalized"] = 1;
	else if ($this->limitName == "unsub") {
	    $queryOptions["unsub"] = 1;
	    $queryOptions["active"] = 1;
	} else if ($this->limitName == "acc") {
	    $queryOptions["accepted"] = 1;
	    $queryOptions["finalized"] = 1;
	} else if ($this->limitName == "und") {
	    $queryOptions["undecided"] = 1;
	    $queryOptions["finalized"] = 1;
	} else if ($this->limitName == "r")
	    $queryOptions["myReviews"] = 1;
	else if ($this->limitName == "rout")
	    $queryOptions["myOutstandingReviews"] = 1;
	else if ($this->limitName == "a")
	    $queryOptions["author"] = 1;
	else if ($this->limitName == "req" || $this->limitName == "reqrevs")
	    $queryOptions["myReviewRequests"] = 1;
	else if ($this->limitName == "act")
	    $queryOptions["active"] = 1;
	else if ($this->limitName == "lead")
	    $queryOptions["myLead"] = 1;
	$this->_resetQuery();
	return false;
    }

    function simplePaperList() {
	if (!$this->qo && !$this->qx && preg_match('/\A\s*#?\d[-#\d\s]*\z/s', $this->q)) {
	    $a = array();
	    foreach (preg_split('/\s+/', $this->q) as $word) {
		if ($word[0] == "#" && preg_match('/\A#\d+(-#?\d+)?/', $word))
		    $word = substr($word, 1);
		if (ctype_digit($word))
		    $a[] = $word;
		else if (preg_match('/\A(\d+)-#?(\d+)\z/s', $word, $m))
		    $a = array_merge($a, range($m[1], $m[2]));
		else
		    return null;
	    }
	    return $a;
	} else if (!$this->qx && !$this->q && preg_match('/\A\s*#?(\d+)\s*\z/s', $this->qo, $m))
	    return array($m[1]);
	else
	    return null;
    }

    function paperList() {
	global $Conf;
	if (!$this->search())
	    return array();
	$x = array();
	$result = $Conf->qe("select paperId from Matches", "while searching papers");
	while (($row = edb_row($result)))
	    $x[] = $row[0];
	$Conf->q("drop temporary table Matches");
	return $x;
    }

    function url() {
	$url = $this->urlbase;
	if (substr($this->urlbase, 0, 6) == "search" || $this->q != "")
	    $url .= "&q=" . urlencode($this->q);
	if ($this->qo != "")
	    $url .= "&qo=" . urlencode($this->qo);
	if ($this->qx != "")
	    $url .= "&qx=" . urlencode($this->qx);
	return $url;
    }

    function _tagDescription() {
	if (($this->q == "" ? 0 : 1) + ($this->qo == "" ? 0 : 1)
	    + ($this->qx == "" ? 0 : 1) != 1)
	    return false;
	$d = trim($this->q == "" ? ($this->qo == "" ? $this->qx : $this->qo) : $this->q);
	if (substr($d, 0, 4) == "tag:")
	    $t = substr($d, 4);
	else if (substr($d, 0, 6) == "notag:" || substr($d, 0, 6) == "order:")
	    $t = substr($d, 6);
	else if (substr($d, 0, 7) == "rorder:")
	    $t = substr($d, 7);
	else
	    return false;
	require_once("tags.inc");
	if (!checkTag($t, CHECKTAG_QUIET))
	    return false;
	if ($d[0] == "o" && !$this->qx)
	    return $d;
	else
	    return (($d[0] == "n") == ($this->qx == "") ? "notag:" : "tag:") . $t;
    }

    function description($listname) {
	require_once("paperlist.inc");
	$desc = PaperList::listDescription($listname);
	if ($this->q == "" && $this->qo == "" && $this->qx == "")
	    return $desc;
	if (($td = $this->_tagDescription())) {
	    if ($desc == "Submitted papers")
		return $td;
	    else
		return "$desc $td";
	} else
	    return "$desc search";
    }

    function listId($sort = "") {
	if ($this->q == "" && $this->qo == "" && $this->qx == "")
	    return $this->limitName . ":" . $sort;
	else
	    return "";
    }

    function decorateSessionList($l, $listname, $sort = "") {
	$l["url"] = $this->url();
	$l["t"] = $this->limitName;
	$l["description"] = $this->description($listname);
	$l["timestamp"] = time();
	$l["listid"] = $this->listId($sort);
	if ($this->matchPreg)
	    $l["matchPreg"] = $this->matchPreg;
	return $l;
    }

    function sessionList($listname) {
	return $this->decorateSessionList($this->paperList(), $listname);
    }

    function parsePapersel() {
	global $Me, $papersel, $paperselmap;
	if (isset($_REQUEST["pap"]) && $_REQUEST["pap"] == "all") {
	    $s = new PaperSearch($Me, $_REQUEST);
	    $_REQUEST["pap"] = $s->paperList();
	}
	if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
	    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
	if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"])) {
	    $papersel = array();
	    $paperselmap = array();
	    foreach ($_REQUEST["pap"] as $p)
		if (($p = cvtint($p)) > 0 && !isset($paperselmap[$p])) {
		    $paperselmap[$p] = count($papersel);
		    $papersel[] = $p;
		}
	    if (count($papersel) == 0) {
		unset($papersel);
		unset($paperselmap);
	    }
	}
    }

    function searchTypes($me) {
	global $Conf;
	$tOpt = array();
	if ($me->isPC && $Conf->setting("pc_seeall") > 0)
	    $tOpt["act"] = "Active papers";
	if ($me->isPC)
	    $tOpt["s"] = "Submitted papers";
	if ($me->isPC && ($Conf->timeAuthorViewDecision() || $Conf->setting("paperacc") > 0))
	    $tOpt["acc"] = "Accepted papers";
	if ($me->privChair)
	    $tOpt["all"] = "All papers";
	if ($me->privChair && $Conf->setting("pc_seeall") <= 0 && defval($_REQUEST, "t") == "act")
	    $tOpt["act"] = "Active papers";
	if ($me->amReviewer())
	    $tOpt["r"] = "Your reviews";
	if ($me->reviewsOutstanding
	    || ($me->amReviewer() && defval($_REQUEST, "t") == "rout"))
	    $tOpt["rout"] = "Your incomplete reviews";
	if ($me->isPC)
	    $tOpt["req"] = "Your review requests";
	if ($me->isPC && $Conf->setting("paperlead") > 0
	    && $me->amDiscussionLead(0))
	    $tOpt["lead"] = "Your discussion leads";
	if ($me->isAuthor)
	    $tOpt["a"] = "Your submissions";
	return $tOpt;
    }

    function searchTypeSelector($tOpt, $type, $tabindex) {
	if (count($tOpt) > 1) {
	    $sel_opt = array();
	    foreach ($tOpt as $k => $v) {
		if (count($sel_opt) && $k == "a")
		    $sel_opt["xxxa"] = null;
		if (count($sel_opt) && ($k == "lead" || $k == "r") && !isset($sel_opt["xxxa"]))
		    $sel_opt["xxxb"] = null;
		$sel_opt[$k] = $v;
	    }
	    $sel_extra = array();
	    if ($tabindex)
		$sel_extra["tabindex"] = 1;
	    return tagg_select("t", $sel_opt, $type, $sel_extra);
	} else
	    return current($tOpt);
    }

}
