<?php
// search.inc -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $searchKeywords;
$searchKeywords = array("ti" => "ti", "title" => "ti",
	"ab" => "ab", "abstract" => "ab",
	"au" => "au", "author" => "au",
	"co" => "co", "collab" => "co", "collaborators" => "co",
	"re" => "re", "rev" => "re", "review" => "re",
	"sre" => "cre", "srev" => "cre", "sreview" => "cre",
	"cre" => "cre", "crev" => "cre", "creview" => "cre",
	"subre" => "cre", "subrev" => "cre", "subreview" => "cre",
	"ire" => "ire", "irev" => "ire", "ireview" => "ire",
	"pri" => "pri", "primary" => "pri", "prire" => "pri", "prirev" => "pri",
	"cpri" => "cpri", "cprimary" => "cpri",
	"ipri" => "ipri", "iprimary" => "ipri",
	"sec" => "sec", "secondary" => "sec", "secre" => "sec", "secrev" => "sec",
	"csec" => "csec", "csecondary" => "csec",
	"isec" => "isec", "isecondary" => "isec",
	"ext" => "ext", "external" => "ext", "extre" => "ext", "extrev" => "ext",
	"cext" => "cext", "cexternal" => "cext",
	"iext" => "iext", "iexternal" => "iext",
	"cmt" => "cmt", "comment" => "cmt",
	"aucmt" => "aucmt", "aucomment" => "aucmt",
	"tag" => "tag",
	"notag" => "notag",
	"ord" => "order", "order" => "order",
	"rord" => "rorder", "rorder" => "rorder",
	"revord" => "rorder", "revorder" => "rorder",
	"decision" => "decision", "dec" => "decision",
	"topic" => "topic",
	"option" => "option", "opt" => "option",
	"lead" => "lead",
	"shepherd" => "shepherd", "shep" => "shepherd",
	"conflict" => "conflict", "conf" => "conflict",
	"reconflict" => "reconflict", "reconf" => "reconflict",
	"status" => "status",
	"rating" => "rate", "rate" => "rate",
	"revpref" => "revpref", "pref" => "revpref",
	"repref" => "revpref",
	"ss" => "ss", "search" => "ss",
	"HEADING" => "HEADING", "heading" => "HEADING");

class SearchOperator {
    var $op;
    var $unary;
    var $precedence;
    function __construct($what, $unary, $precedence) {
	$this->op = $what;
	$this->unary = $unary;
	$this->precedence = $precedence;
    }
}

global $searchOperators;
$searchOperators = array("(" => new SearchOperator("(", true, null),
			"NOT" => new SearchOperator("NOT", true, 5),
			"-" => new SearchOperator("NOT", true, 5),
			"+" => new SearchOperator("+", true, 5),
			"AND" => new SearchOperator("and", false, 4),
			"OR" => new SearchOperator("or", false, 3),
			"XAND" => new SearchOperator("and", false, 2),
			"XOR" => new SearchOperator("or", false, 2),
			"THEN" => new SearchOperator("then", false, 1),
			")" => null);

global $searchMatchNumber;
$searchMatchNumber = 0;

class SearchTerm {
    var $type;
    var $link;
    var $flags;
    var $value;

    function __construct($t, $f = 0, $v = null, $other = null) {
	$this->type = $t;
	$this->link = false;
	$this->flags = $f;
	$this->value = $v;
	if ($other) {
	    foreach ($other as $k => $v)
		$this->$k = $v;
	}
    }
    static function combine($combiner, $terms) {
	if (count($terms) == 0)
	    return null;
	else if (count($terms) == 1)
	    return $terms[0];
	else
	    return new SearchTerm($combiner, 0, $terms);
    }
    static function negate($term) {
	if (!$term)
	    return null;
	else if ($term->type == "not")
	    return $term->value;
	else if ($term->type == "f")
	    return new SearchTerm("t");
	else if ($term->type == "t")
	    return new SearchTerm("f");
	else
	    return new SearchTerm("not", 0, $term);
    }
    function isfalse() {
	return $this->type == "f";
    }
    function set($k, $v) {
	$this->$k = $v;
    }
    function get($k, $defval = null) {
	return isset($this->$k) ? $this->$k : $defval;
    }
}

class SearchReviewValue {
    var $countexpr;
    var $contactsql;
    var $_contactset;
    var $fieldsql;
    var $compar;
    var $allowed;

    function __construct($countexpr, $contacts = null, $fieldsql = null) {
	$this->countexpr = $countexpr;
	if (!$contacts || is_string($contacts))
	    $this->contactsql = $contacts;
	else
	    $this->contactsql = PaperSearch::_sqlNumericSet($contacts);
	$this->_contactset = $contacts;
	$this->fieldsql = $fieldsql;
	$this->allowed = $this->compar = 0;
	if (preg_match('/\A([!<>]?=|[<>])(-?\d+)\z/', $countexpr, $m)) {
	    if ($m[1] == "!=" || $m[1] == ">" || $m[1] == ">=")
		$this->allowed |= 4;
	    if ($m[1] == "=" || $m[1] == ">=" || $m[1] == "<=")
		$this->allowed |= 2;
	    if ($m[1] == "!=" || $m[1] == "<" || $m[1] == "<=")
		$this->allowed |= 1;
	    $this->compar = (int) $m[2];
	}
    }
    function test($n) {
	if ($n > $this->compar)
	    return ($this->allowed & 4) != 0;
	else if ($n == $this->compar)
	    return ($this->allowed & 2) != 0;
	else
	    return ($this->allowed & 1) != 0;
    }
    static function negateCountexpr($countexpr) {
	$t = new SearchReviewValue($countexpr);
	if ($t->allowed) {
	    $x = array("", "<", "=", "<=", ">", "!=", ">=");
	    return $x[$t->allowed ^ 7] . $t->compar;
	} else
	    return $countexpr;
    }
    function onlyContact($contactid) {
	return is_array($this->_contactset) && count($this->_contactset) == 1
	    && $this->_contactset[0] == $contactid;
    }
    function restrictContact($contactid) {
	if (!$this->_contactset)
	    $cset = array($contactid);
	else if (!is_array($this->_contactset))
	    $cset = $this->_contactset . " and \3=$contactid";
	else if (in_array($contactid, $this->_contactset))
	    $cset = array($contactid);
	else
	    return null;
	return new SearchReviewValue($this->countexpr, $cset, $this->fieldsql);
    }
    function contactWhere($fieldname) {
	return str_replace("\3", $fieldname, "\3" . $this->contactsql);
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
    const F_AUTHORRESPONSE = 0x08000;
    const F_FALSE = 0x10000;

    var $contact;
    var $contactId;
    var $privChair;
    var $amPC;

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
    var $_ssRecursion;
    var $thenmap;
    var $_currentHeading;
    var $headingmap;

    var $_matchTable;

    function __construct($me, $opt, $searchContactId = 0) {
	global $Conf;

	// contact facts
	$this->contact = $me;
	$this->privChair = $me->privChair;
	$this->amPC = $me->isPC;
	$this->contactId = $me->contactId;
	if ($this->privChair && $searchContactId > 0)
	    $this->contactId = $searchContactId;

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
	    || ($this->amPC && !$Conf->subBlindAlways()))
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

	// URL base
	if (isset($opt["urlbase"]))
	    $this->urlbase = $opt["urlbase"];
	else {
	    $this->urlbase = hoturl("search", "t=" . urlencode($this->limitName));
	    if ($qtype != "n")
		$this->urlbase .= "&qt=" . urlencode($qtype);
	}

	$this->overrideMatchPreg = false;

	$this->regex = array();
	$this->contactmatch = array();
	$this->contactmatchPC = true;
	$this->noratings = false;
	$this->interestingRatings = array();
	$this->reviewAdjust = false;
	$this->_reviewAdjustError = false;
	$this->_thenError = false;
	$this->thenmap = null;
	$this->_currentHeading = null;
	$this->headingmap = null;
	$this->orderTags = array();
	$this->reviewerContact = false;
	$this->_matchTable = null;
	$this->_ssRecursion = array();
    }

    function __destruct() {
	global $Conf;
	if ($this->_matchTable)
	    $Conf->q("drop temporary table $this->_matchTable");
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

    static function _fieldPreg($word) {
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
	if (!is_array($word))
	    $extra = array("regex" => array($rtype, self::_fieldPreg($word)));
	else
	    $extra = null;

	if ($this->privChair
	    || ($this->amPC && !$blind)
	    || ($this->amPC && $Conf->subBlindNever()))
	    $qt[] = new SearchTerm($rtype, 0, $word, $extra);
	else {
	    if ($blind && $Conf->subBlindAlways()
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

    static function _cleanCompar($compar) {
	$compar = trim($compar);
	if ($compar == "" || $compar == "==")
	    return "=";
	else if ($compar == "!")
	    return "!=";
	else
	    return $compar;
    }

    static function _matchCompar($text, $quoted = true) {
	$text = trim($text);
	if (($text == "any" || $text == "") && !$quoted)
	    return array("", ">0");
	else if ($text == "none" && !$quoted)
	    return array("", "=0");
	else if (ctype_digit($text))
	    return array("", "=" . $text);
	else if (preg_match('/\A(.*?)([<>!]|[<>!=]?=)\s*(\d+)\z/s', $text, $m))
	    return array($m[1], self::_cleanCompar($m[2]) . $m[3]);
	else
	    return array($text, ">0");
    }

    static function _comparTautology($m) {
	if ($m[1] == "<0")
	    return "f";
	else if ($m[1] == ">=0")
	    return "t";
	else
	    return null;
    }

    static function _typeCompar($compar, $value, $type) {
	$compar = self::_cleanCompar($compar);
	if ($value == 0 && $compar == "<")
	    return array("f", null);
	else if ($value == 0 && $compar == ">=")
	    return array("t", null);
	else
	    return array($type, $compar . $value);
    }

    static function _sqlNumericSet($a, $neg = false) {
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

    function _reviewerMatcher($word, $quoted, $pc_only = false) {
	if (!$quoted && ($word == "" || strcasecmp($word, "pc") == 0))
	    return array_keys(pcMembers());
	else if (!$quoted && strcasecmp($word, "me") == 0)
	    return array($this->contactId);
	else if (!$quoted && $this->amPC && count(($pctags = pcTags()))) {
	    $negtag = $word[0] == "-";
	    $tag = $negtag ? substr($word, 1) : $word;
	    if (isset($pctags[$tag])) {
		$ids = self::_pcContactIdsWithTag($tag);
		if ($negtag) {
		    $this->contactmatch[] = "\2contactId" . self::_sqlNumericSet($ids, true);
		    if (!$pc_only)
			$this->contactmatchPC = false;
		    return "\1" . (count($this->contactmatch) - 1) . "\1";
		} else
		    return $ids;
	    }
	}

	$qword = sqlq_for_like($word);
	if (!count($this->contactmatch)
	    || $this->contactmatch[count($this->contactmatch) - 1] != $qword) {
	    $this->contactmatch[] = sqlq_for_like($word);
	    if (!$pc_only)
		$this->contactmatchPC = false;
	}
	return "\1" . (count($this->contactmatch) - 1) . "\1";
    }

    function _searchCompleteReviewer(&$qt, $type, $rt, $value) {
	global $Conf;
	if ($this->privChair)
	    $qt[] = new SearchTerm($type, $rt, $value);
	else {
	    $deblinder = 0;
	    if (!$value->contactsql)
		/* $deblinder == 0 */;
	    else if ($Conf->blindReview() == BLIND_ALWAYS)
		$deblinder = self::F_FALSE;
	    else if ($Conf->blindReview() == BLIND_OPTIONAL)
		$deblinder = self::F_NONBLIND;

	    if ($this->amPC && $type == "re"
		&& !$value->contactsql && !$value->fieldsql) {
		// 28.Nov.2008 -- PC users can always count reviews for
		// non-conflicted papers.  The "can see all reviews" settings
		// only apply to reviewer names.  This is a change from prior
		// behavior, but prior behavior wasn't particularly consistent
		// (the "can review any submitted paper" setting was affecting
		// visibility; it shouldn't).
		$qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $value);

	    } else if ($this->amPC) {
		$pc_seeblindrev = $Conf->setting("pc_seeblindrev");
		// Can PC member see all reviews?  PC member can generally see
		// all assignments (under control of pc_seeblindrev), so if
		// query doesn't involve text or score, ignore the setting.
		if (!$value->fieldsql && $pc_seeblindrev != 2)
		    $pc_seeallrev = 1;
		else {
		    $pc_seeallrev = $Conf->setting("pc_seeallrev");
		    if ($pc_seeallrev == 4 && !$this->contact->reviewsOutstanding)
			$pc_seeallrev = 1;
		}
		// Can blind reviews be searched?  Yes, if the search doesn't
		// check reviewer name, or pc_seeblindrev allows it.  In *all*
		// cases PC members can see who wrote blind reviews once they
		// submit their own review.
		if (!$value->contactsql || $pc_seeblindrev != 1)
		    $xdeblinder = 0;
		else
		    $xdeblinder = $deblinder;

		// Add terms for reviews not written by current contact
		$limit = array();
		if (!$value->onlyContact($this->contactId)) {
		    if ($pc_seeallrev == 1)
			$limit[] = self::F_NONCONFLICT | $xdeblinder;
		    else if ($pc_seeallrev == 3)
			$limit[] = self::F_NONCONFLICT | self::F_NONREVIEWER | $xdeblinder;
		    if (!count($limit) || $limit[0] != self::F_NONCONFLICT)
			$limit[] = self::F_NONCONFLICT | self::F_REVIEWER;
		    foreach ($limit as $l)
			if (!($l & self::F_FALSE))
			    $qt[] = new SearchTerm($type, $rt | $l, $value);
		}

		// Add more liberal term for reviews written by current
		// contact
		if (($newvalue = $value->restrictContact($this->contactId))
		    && (!count($limit) || $limit[0] != self::F_NONCONFLICT))
		    $qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $newvalue);

	    } else {
		if (!$value->onlyContact($this->contactId)) {
		    $extrev_view = $Conf->setting("extrev_view");
		    $xdeblinder = ($extrev_view == 2 || !$value->contactsql ? 0 : $deblinder);
		    if (($extrev_view == 1 || $extrev_view == 2)
			&& $xdeblinder != self::F_FALSE)
			$qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT | self::F_REVIEWER | $xdeblinder, $value);
		}
		if (($newvalue = $value->restrictContact($this->contactId)))
		    $qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $newvalue);
	    }

	    if ($Conf->timeAuthorViewReviews())
		$qt[] = new SearchTerm($type, ($rt & ~self::F_TYPEMASK) | self::F_AUTHOR | self::F_COMPLETE | $deblinder, $value);
	}
    }

    function _searchReviewer($word, $rtype, &$qt, $quoted) {
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

	$contacts = ($m[0] == "" ? null : $this->_reviewerMatcher($m[0], $quoted));
	$value = new SearchReviewValue($m[1], $contacts);

	$nqt = count($qt);
	$this->_searchCompleteReviewer($qt, "re", $rt, $value);
	if (count($qt) == $nqt)
	    $qt[] = new SearchTerm("f");
    }

    function _searchConflict($word, &$qt, $quoted) {
	$m = self::_matchCompar($word, $quoted);
	if (($type = self::_comparTautology($m))) {
	    $qt[] = new SearchTerm($type);
	    return;
	}

	$contacts = $this->_reviewerMatcher($m[0], $quoted);
	if ($this->privChair
	    || (is_array($contacts) && count($contacts) == 1 && $contacts[0] == $this->contactId))
	    $qt[] = new SearchTerm("conflict", 0, new SearchReviewValue($m[1], $contacts));
	else {
	    $value = new SearchReviewValue($m[1], $contacts);
	    $qt[] = new SearchTerm("conflict", self::F_PAPERNONBLIND, $value);
	    if (($newvalue = $value->restrictContact($this->contactId)))
		$qt[] = new SearchTerm("conflict", 0, $newvalue);
	}
    }

    function _searchReviewerConflict($word, &$qt, $quoted) {
	global $Conf;
	$args = array();
	while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
	    $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
	    foreach (range($m[1], $m[2]) as $p)
		$args[$p] = true;
	    $word = $m[3];
	}
	if ($word != "" || count($args) == 0) {
	    $this->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
	    $qt[] = new SearchTerm("f");
	} else {
	    $result = $Conf->qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")", "while evaluating reconflict keyword");
	    $contacts = array();
	    while (($row = edb_row($result)))
		$contacts[] = $row[0];
	    $qt[] = new SearchTerm("conflict", 0, new SearchReviewValue(">0", $contacts));
	}
    }

    function _searchComment($word, $ctype, &$qt, $quoted) {
	global $Conf;

	$m = self::_matchCompar($word, $quoted);
	if (($type = self::_comparTautology($m))) {
	    $qt[] = new SearchTerm($type);
	    return;
	}

	if ($m[0] == "") {
	    $type = "ncmt";
	    $contacts = null;
	} else {
	    $type = "cmt";
	    $contacts = $this->_reviewerMatcher($m[0], $quoted);
	}
	$value = new SearchReviewValue($m[1], $contacts);

	$rt = ($ctype == "aucmt" ? self::F_AUTHORCOMMENT : 0);
	$nqt = count($qt);
	$this->_searchCompleteReviewer($qt, $type, $rt, $value);
	if (!$this->privChair && !$Conf->timeAuthorViewReviews())
	    $qt[] = new SearchTerm($type, self::F_AUTHOR | self::F_AUTHORRESPONSE, $value);
	if (count($qt) == $nqt)
	    $qt[] = new SearchTerm("f");
    }

    function _searchReviews($word, $rf, $field, &$qt, $quoted) {
	global $Conf;
	$countexpr = ">0";
	$contacts = null;
	$contactword = "";

	if (preg_match('/\A(.+?[^:=<>!])([:=<>!])(.*)\z/s', $word, $m)
	    && !ctype_digit($m[1])) {
	    $contacts = $this->_reviewerMatcher($m[1], $quoted);
	    $word = ($m[2] == ":" ? $m[3] : $m[2] . $m[3]);
	    $contactword = $m[1] . ":";
	}

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
		    else {
			$countexpr = ">=" . $m[1];
			$value = $field . $m[2] . $m[3];
		    }
		}
	    } else if ($letters
		       ? preg_match('/\A\s*([A-Za-z])\s*(-?|\.\.\.?)\s*([A-Za-z])\s*\z/s', $word, $m)
		       : preg_match('/\A\s*(\d+)\s*(-|\.\.\.?)\s*(\d+)\s*\z/s', $word, $m)) {
		$qo = array();
		if ($m[2] == "-" || $m[2] == "") {
		    $this->_searchReviews($contactword . $m[1], $rf, $field, $qo, $quoted);
		    $this->_searchReviews($contactword . $m[3], $rf, $field, $qo, $quoted);
		} else
		    $this->_searchReviews($contactword . ">=" . $m[1], $rf, $field, $qo, $quoted);
		if ($this->_searchReviews($contactword . "<" . $m[1], $rf, $field, $qo, $quoted))
		    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
		if ($this->_searchReviews($contactword . ">" . $m[3], $rf, $field, $qo, $quoted))
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
	$value = new SearchReviewValue($countexpr, $contacts, $value);
	$this->_searchCompleteReviewer($qt, "re", self::F_COMPLETE, $value);
	return count($qt) != $nqt;
    }

    function _searchRevpref($word, &$qt, $quoted) {
	$contacts = null;
	if (preg_match('/\A(.*?[^:=<>!])([:=<>!])(.*)\z/s', $word, $m)
	    && !ctype_digit($m[1])) {
	    $contacts = $this->_reviewerMatcher($m[1], $quoted);
	    $word = ($m[2] == ":" ? $m[3] : $m[2] . $m[3]);
	}

	if (ctype_digit($word))
	    $mx = array(">0", "=" . $word);
	else if (preg_match('/\A(\d*)\s*([<>!]|[<>!=]?=)\s*(-?\d+)\z/s', $word, $m))
	    $mx = array($m[1] == "" ? ">0" : ">=$m[1]", self::_cleanCompar($m[2]) . $m[3]);
	else {
	    $qt[] = new SearchTerm("f");
	    return;
	}

	// since 0 preferences are not stored, we must negate the sense of the
	// comparison if a preference of 0 might match
	$scratch = new SearchReviewValue($mx[1]);
	if ($scratch->test(0)) {
	    $mx[0] = SearchReviewValue::negateCountexpr($mx[0]);
	    $mx[1] = SearchReviewValue::negateCountexpr($mx[1]);
	}

	$value = new SearchReviewValue($mx[0], $contacts, "preference" . $mx[1]);
	$qt[] = new SearchTerm("revpref", 0, $value);
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

	if (($e = checkTagError($tagword, CHECKTAG_ALLOWRESERVED | CHECKTAG_NOINDEX | ($this->privChair ? CHECKTAG_CHAIR : 0) | ($special == "tag" ? CHECKTAG_ALLOWSTAR : 0)))) {
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
	if (strpos($value, "*") !== false)
	    $value = "\3 like '" . str_replace("*", "%", sqlq_for_like($value)) . "'";
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
			$this->warn("Submission option &ldquo;" . htmlspecialchars("$oname:$oval") . "&rdquo; matches multiple values, can&rsquo;t use " . htmlspecialchars($ocompar) . ".");
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
		$this->warn("&ldquo;" . htmlspecialchars($word) . "&rdquo; doesn&rsquo;t match a submission option.");
	}
	$qt = array_merge($qt, $qo);
	return count($qo) != 0;
    }

    function _searchQueryWord($word, $report_error) {
	global $searchKeywords, $ratingTypes, $Conf;

	// check for paper number or "#TAG"
	if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
	    $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
	    return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
	} else if (preg_match('/\A(-?)#(' . TAG_REGEX_OPTVALUE . '[#<>!=\d]*)\z/', $word, $m)) {
	    $qe = $this->_searchQueryWord($m[1] . "tag:" . $m[2], false);
	    if (!$qe->isfalse())
		return $qe;
	}

	// Allow searches like "ovemer>2"; parse as "ovemer:>2".
	if (preg_match('/\A([-_A-Za-z0-9]+)([<>!=]=?[^:]+)\z/', $word, $m)) {
	    $qe = $this->_searchQueryWord($m[1] . ":" . $m[2], false);
	    if (!$qe->isfalse())
		return $qe;
	}

	// Treat unquoted "*", "ANY", and "ALL" as special; return true.
	if ($word == "*" || $word == "ANY" || $word == "ALL")
	    return new SearchTerm("t");

	$keyword = null;
	if (($colon = strpos($word, ':')) !== false) {
	    $x = substr($word, 0, $colon);
	    if (isset($searchKeywords[$x])) {
		$keyword = $searchKeywords[$x];
		$word = substr($word, $colon + 1);
	    } else if (strpos($x, '"') === false) {
		$keyword = $x;
		$word = substr($word, $colon + 1);
	    }
	}

	$quoted = ($word[0] == '"');
	$negated = false;
	if ($quoted)
	    $word = str_replace(array('"', '*'), array('', '\*'), $word);
	if ($keyword ? $keyword == "notag" : isset($this->fields["notag"])) {
	    $keyword = "tag";
	    $negated = true;
	}

	$qt = array();
	if ($keyword ? $keyword == "ti" : isset($this->fields["ti"]))
	    $this->_searchField($word, "ti", $qt, false);
	if ($keyword ? $keyword == "ab" : isset($this->fields["ab"]))
	    $this->_searchField($word, "ab", $qt, false);
	if ($keyword == "au" && !$quoted && strcasecmp($word, "pc") == 0)
	    $this->_searchField(array_keys(pcMembers()), "au_cid", $qt, true);
	else if ($keyword ? $keyword == "au" : isset($this->fields["au"]))
	    $this->_searchField($word, "au", $qt, true);
	if ($keyword ? $keyword == "co" : isset($this->fields["co"]))
	    $this->_searchField($word, "co", $qt, true);
	foreach (array("re", "cre", "ire", "pri", "cpri", "ipri", "sec", "csec", "isec", "ext", "cext", "iext") as $rtype)
	    if ($keyword ? $keyword == $rtype : isset($this->fields[$rtype]))
		$this->_searchReviewer($word, $rtype, $qt, $quoted);
	foreach (array("cmt", "aucmt") as $ctype)
	    if ($keyword ? $keyword == $ctype : isset($this->fields[$ctype]))
		$this->_searchComment($word, $ctype, $qt, $quoted);
	if (($keyword ? $keyword == "revpref" : isset($this->fields["revpref"]))
	    && $this->privChair)
	    $this->_searchRevpref($word, $qt, $quoted);
	if (($keyword ? $keyword == "lead" : isset($this->fields["lead"]))
	    && $this->amPC) {
	    if ($word == "none")
		$x = "=0";
	    else if ($word == "any")
		$x = "!=0";
	    else
		$x = $this->_reviewerMatcher($word, $quoted, true);
	    $rt = ($this->privChair ? 0 : self::F_NONCONFLICT);
	    $qt[] = new SearchTerm("pf", $rt, array("leadContactId", $x));
	}
	if (($keyword ? $keyword == "shepherd" : isset($this->fields["shepherd"]))
	    && $this->amPC) {
	    // XXX searching author's own shepherds?
	    if ($word == "none")
		$x = "=0";
	    else if ($word == "any")
		$x = "!=0";
	    else
		$x = $this->_reviewerMatcher($word, $quoted, true);
	    $rt = ($this->privChair ? 0 : self::F_NONCONFLICT);
	    $qt[] = new SearchTerm("pf", $rt, array("shepherdContactId", $x));
	}
	if (($keyword ? $keyword == "tag" : isset($this->fields["tag"]))
	    || $keyword == "order" || $keyword == "rorder")
	    $this->_searchTags($word, $keyword, $qt);
	if (($keyword ? $keyword == "topic" : isset($this->fields["topic"]))) {
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
	if (($keyword ? $keyword == "option" : isset($this->fields["option"])))
	    $this->_searchOptions($word, $qt, true);
	if ($keyword ? $keyword == "status" : isset($this->fields["status"])) {
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
	if ($keyword ? $keyword == "decision" : isset($this->fields["decision"])) {
	    if (!$quoted && strcasecmp($word, "yes") == 0)
		$value = ">0";
	    else if (!$quoted && strcasecmp($word, "no") == 0)
		$value = "<0";
	    else if ($word == "?" || (!$quoted && strcasecmp($word, "none") == 0))
		$value = "=0";
	    else if (!$quoted && strcasecmp($word, "any") == 0)
		$value = "!=0";
	    else {
		$rf = reviewForm();
		$value = matchValue($rf->options["outcome"], $word, true);
		if (count($value) == 0) {
		    $this->warn("&ldquo;" . htmlspecialchars($word) . "&rdquo; doesn&rsquo;t match a decision.");
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
	if (($keyword ? $keyword == "conflict" : isset($this->fields["conflict"]))
	    && $this->amPC)
	    $this->_searchConflict($word, $qt, $quoted);
	if (($keyword ? $keyword == "reconflict" : isset($this->fields["reconflict"]))
	    && $this->privChair)
	    $this->_searchReviewerConflict($word, $qt, $quoted);
	if (($keyword ? $keyword == "round" : isset($this->fields["round"]))
	    && $this->amPC) {
	    $this->reviewAdjust = true;
	    if ($word == "none")
		$qt[] = new SearchTerm("revadj", 0, array("round" => 0));
	    else if ($word == "any")
		$qt[] = new SearchTerm("revadj", 0, array("round" => range(1, count($Conf->settings["rounds"]) - 1)));
	    else {
		$x = simplifyWhitespace($word);
		$rounds = matchValue($Conf->settings["rounds"], $x);
		if (count($rounds) == 0) {
		    $this->warn("&ldquo;" . htmlspecialchars($x) . "&rdquo; doesn&rsquo;t match a review round.");
		    $qt[] = new SearchTerm("f");
		} else
		    $qt[] = new SearchTerm("revadj", 0, array("round" => $rounds));
	    }
	}
	if (($keyword ? $keyword == "rate" : isset($this->fields["rate"]))) {
	    $this->reviewAdjust = true;
	    if (preg_match('/\A(.+?)\s*(|[<>!=]?=?)\s*(\d*)\z/', $word, $m)
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
		} else if ($m[1] === "any") {
		    $this->interestingRatings["any"] = "!=100";
		    $term = "nrate_any";
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
	if ($keyword ? $keyword == "ss" : isset($this->fields["ss"])) {
	    $t = $Conf->settingText("ss:" . $word, "");
	    $search = json_decode($t);
	    $qe = null;
	    if (isset($this->_ssRecursion[$word]))
		$this->warn("Saved search “" . htmlspecialchars($word) . "” is incorrectly defined in terms of itself.");
	    else if ($t == "")
		$this->warn("There is no saved search called “" . htmlspecialchars($word) . "”.");
	    else {
		if ($search && is_object($search) && isset($search->q)) {
		    $this->_ssRecursion[$word] = true;
		    $qe = $this->_searchQueryType($search->q);
		    unset($this->_ssRecursion[$word]);
		}
		if (!$qe)
		    $this->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
	    }
	    $qt[] = ($qe ? $qe : new SearchTerm("f"));
	}
	if ($keyword == "HEADING") {
	    if (($this->_currentHeading = simplifyWhitespace($word)) != "")
		$this->headingmap = array();
	    $qt[] = new SearchTerm("t");
	}

	// Finally, look for a review field.
	if ($keyword && !isset($searchKeywords[$keyword]) && count($qt) == 0) {
	    $rf = reviewForm();
	    if (($field = $rf->unabbreviateField($keyword)))
		$this->_searchReviews($word, $rf, $field, $qt, $quoted);
	    else if (!$this->_searchOptions("$keyword:$word", $qt, false)
		     && $report_error)
		$this->warn("Unrecognized keyword &ldquo;" . htmlspecialchars($keyword) . "&rdquo;.");
	}

	// Must always return something
	if (count($qt) == 0)
	    $qt[] = new SearchTerm("f");

	$qe = SearchTerm::combine("or", $qt);
	return $negated ? SearchTerm::negate($qe) : $qe;
    }

    static function _searchPopWord(&$str) {
	global $searchKeywords;
	$wordre = '/\A-?"[^"]*"?|-?[a-zA-Z][a-zA-Z0-9]*:"[^"]*"?[^\s()]*|[^"\s()]+/s';

	preg_match($wordre, $str, $m);
	$str = ltrim(substr($str, strlen($m[0])));
	$word = $m[0];

	// commas in paper number strings turn into separate words
	if (preg_match('/\A(#?\d+(?:-#?\d+)?),((?:#?\d+(?:-#?\d+)?,?)*)\z/', $word, $m)) {
	    $word = $m[1];
	    if ($m[2] != "")
		$str = $m[2] . ($str == "" ? "" : " " . $str);
	}

	// allow a space after a keyword
	if (($colon = strpos($word, ":")) !== false) {
	    $x = substr($word, 0, $colon);
	    if ((isset($searchKeywords[$x]) || strpos($x, '"') === false)
		&& strlen($word) <= $colon + 1) {
		preg_match($wordre, $str, $m);
		$word .= $m[0];
		$str = ltrim(substr($str, strlen($m[0])));
	    }
	}

	return $word;
    }

    static function _searchPopKeyword($str) {
	if (preg_match('/\A([-+()]|(?:AND|OR|NOT|THEN)(?=[\s\(]))/s', $str, $m))
	    return array($m[1], ltrim(substr($str, strlen($m[0]))));
	else
	    return array(null, $str);
    }

    static function _searchPopStack($curqe, &$stack) {
	$x = array_pop($stack);
	if (!$curqe)
	    return $x->leftqe;
	else if ($x->op->op == "NOT")
	    return SearchTerm::negate($curqe);
	else if ($x->op->op == "+")
	    return $curqe;
	else if ($x->used) {
	    $x->leftqe->value[] = $curqe;
	    return $x->leftqe;
	} else
	    return SearchTerm::combine($x->op->op, array($x->leftqe, $curqe));
    }

    function _searchQueryType($str) {
	global $searchOperators, $Conf;

	$stack = array();
	$parens = 0;
	$curqe = null;

	while ($str !== "") {
	    list($opstr, $nextstr) = self::_searchPopKeyword($str);
	    $op = $opstr ? $searchOperators[$opstr] : null;

	    if ($curqe && (!$op || $op->unary)) {
		list($opstr, $op, $nextstr) =
		    array("", $searchOperators["XAND"], $str);
	    }

	    if ($opstr === null) {
		$word = self::_searchPopWord($nextstr);
		$curqe = $this->_searchQueryWord($word, true);
	    } else if ($opstr == ")") {
		while (count($stack)
		       && $stack[count($stack) - 1]->op->op != "(")
		    $curqe = self::_searchPopStack($curqe, $stack);
		if (count($stack)) {
		    array_pop($stack);
		    --$parens;
		}
	    } else if ($opstr == "(") {
		assert(!$curqe);
		$stack[] = (object) array("op" => $op, "leftqe" => null, "used" => false);
		++$parens;
	    } else {
		while (count($stack)
		       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
		    $curqe = self::_searchPopStack($curqe, $stack);
		if ($op->op == "then") {
		    $curqe->set("heading", $this->_currentHeading);
		    $this->_currentHeading = null;
		}
		$top = count($stack) ? $stack[count($stack) - 1] : null;
		if ($top && !$op->unary && $top->op->op == $op->op) {
		    if ($top->used)
			$top->leftqe->value[] = $curqe;
		    else {
			$top->leftqe = SearchTerm::combine($op->op, array($top->leftqe, $curqe));
			$top->used = true;
		    }
		} else
		    $stack[] = (object) array("op" => $op, "leftqe" => $curqe, "used" => false);
		$curqe = null;
	    }

	    $str = $nextstr;
	}

	while (count($stack))
	    $curqe = self::_searchPopStack($curqe, $stack);
	return $curqe;
    }

    static function _canonicalizePopStack($curqe, &$stack) {
	$x = array_pop($stack);
	if ($curqe)
	    $x->qe[] = $curqe;
	if (!count($x->qe))
	    return null;
	if ($x->op->unary) {
	    $qe = $x->qe[0];
	    if ($x->op->op == "NOT") {
		if (preg_match('/\A(?:[(-]|NOT )/', $qe))
		    $qe = "NOT $qe";
		else
		    $qe = "-$qe";
	    }
	    return $qe;
	} else if (count($x->qe) == 1)
	    return $x->qe[0];
	else if ($x->op->op == "and" && $x->op->precedence == 2)
	    return "(" . join(" ", $x->qe) . ")";
	else
	    return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static function _canonicalizeQueryType($str, $type) {
	global $searchOperators, $Conf;

	$stack = array();
	$parens = 0;
	$defaultop = ($type == "all" ? "XAND" : "XOR");
	$curqe = null;
	$t = "";

	while ($str !== "") {
	    list($opstr, $nextstr) = self::_searchPopKeyword($str);
	    $op = $opstr ? $searchOperators[$opstr] : null;

	    if ($curqe && (!$op || $op->unary)) {
		list($opstr, $op, $nextstr) =
		    array("", $searchOperators[$parens ? "XAND" : $defaultop], $str);
	    }

	    if ($opstr === null) {
		$curqe = self::_searchPopWord($nextstr);
	    } else if ($opstr == ")") {
		while (count($stack)
		       && $stack[count($stack) - 1]->op->op != "(")
		    $curqe = self::_canonicalizePopStack($curqe, $stack);
		if (count($stack)) {
		    array_pop($stack);
		    --$parens;
		}
	    } else if ($opstr == "(") {
		assert(!$curqe);
		$stack[] = (object) array("op" => $op, "qe" => array());
		++$parens;
	    } else {
		while (count($stack)
		       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
		    $curqe = self::_canonicalizePopStack($curqe, $stack);
		$top = count($stack) ? $stack[count($stack) - 1] : null;
		if ($top && !$op->unary && $top->op->op == $op->op)
		    $top->qe[] = $curqe;
		else
		    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
		$curqe = null;
	    }

	    $str = $nextstr;
	}

	if ($type == "none")
	    array_unshift($stack, (object) array("op" => $searchOperators["NOT"], "qe" => array()));
	while (count($stack))
	    $curqe = self::_canonicalizePopStack($curqe, $stack);
	return $curqe;
    }

    static function canonicalizeQuery($qa, $qo = null, $qx = null) {
	$x = array();
	if ($qa && ($qa = self::_canonicalizeQueryType($qa, "all")))
	    $x[] = $qa;
	if ($qo && ($qo = self::_canonicalizeQueryType($qo, "any")))
	    $x[] = $qo;
	if ($qx && ($qx = self::_canonicalizeQueryType($qx, "none")))
	    $x[] = $qx;
	if (count($x) == 1)
	    return preg_replace('/\A\((.*)\)\z/', '$1', join("", $x));
	else
	    return join(" AND ", $x);
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
		$qe->value[$i]->set("heading", $qv->get("heading"));
		++$i;
	    }
	}
	if (count($qe->value) > 0)
	    $qe->value[count($qe->value) - 1]->set("heading", $this->_currentHeading);
	return $qe;
    }

    // apply rounds to reviewer searches
    function _queryMakeAdjustedReviewSearch($roundterm) {
	if ($this->limitName == "r" || $this->limitName == "rout")
	    $value = new SearchReviewValue(">0", array($this->contactId));
	else if ($this->limitName == "req" || $this->limitName == "reqrevs")
	    $value = new SearchReviewValue(">0", null, "requestedBy=" . $this->contactId . " and reviewType=" . REVIEW_EXTERNAL);
	else
	    $value = new SearchReviewValue(">0");
	return new SearchTerm("re", 0, $value, $roundterm->value);
    }

    function _queryAdjustReviews($qe, $revadj) {
	$applied = $first_applied = 0;
	$adjustments = array("round", "rate");
	if ($qe->type == "not")
	    $this->_queryAdjustReviews($qe->value, $revadj);
	else if ($qe->type == "and") {
	    $myrevadj = ($qe->value[0]->type == "revadj" ? $qe->value[0] : null);
	    if ($myrevadj) {
		$used_revadj = false;
		foreach ($adjustments as $adj)
		    if (!isset($myrevadj->value[$adj]) && isset($revadj->value[$adj])) {
			$myrevadj->value[$adj] = $revadj->value[$adj];
			$used_revadj = true;
		    }
	    }

	    $rdown = $myrevadj ? $myrevadj : $revadj;
	    for ($i = 0; $i < count($qe->value); ++$i)
		if ($qe->value[$i]->type != "revadj")
		    $this->_queryAdjustReviews($qe->value[$i], $rdown);

	    if ($myrevadj && !isset($myrevadj->used_revadj)) {
		$qe->value[0] = $this->_queryMakeAdjustedReviewSearch($myrevadj);
		if ($used_revadj)
		    $revadj->used_revadj = true;
	    }
	} else if ($qe->type == "or" || $qe->type == "then") {
	    for ($i = 0; $i < count($qe->value); ++$i)
		$this->_queryAdjustReviews($qe->value[$i], $revadj);
	} else if ($qe->type == "re" && $revadj) {
	    foreach ($adjustments as $adj)
		if (isset($revadj->value[$adj]))
		    $qe->set($adj, $revadj->value[$adj]);
	    $revadj->used_revadj = true;
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
	if (($x = $qe->get("regex"))) {
	    $this->regex[$x[0]] = defval($this->regex, $x[0], array());
	    $this->regex[$x[0]][] = $x[1];
	}
	if (($x = $qe->get("tagorder")))
	    $this->orderTags[] = $x;
	if ($top && $qe->type == "re") {
	    if ($this->reviewerContact === false) {
		$v = $qe->value->contactsql;
		if ($v[0] == "=")
		    $this->reviewerContact = (int) substr($v, 1);
		else if ($v[0] == "\1") {
		    $v = (int) substr($v, 1, strpos($v, "\1", 1) - 1);
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
	    if ($Conf->subBlindNever())
		$flags &= ~self::F_PAPERNONBLIND;
	    else if ($Conf->subBlindAlways() && !$Conf->timeReviewerViewAcceptedAuthors())
		$flags = ($flags & ~self::F_PAPERNONBLIND) | self::F_FALSE;
	}

	$this->needflags |= $flags;

	if ($flags & self::F_NONCONFLICT)
	    $q[] = "PaperConflict.conflictType is null";
	if ($flags & self::F_AUTHOR)
	    $q[] = $this->contact->actAuthorSql("PaperConflict");
	if ($flags & self::F_REVIEWER)
	    $q[] = "MyReview.reviewNeedsSubmit=0";
	if ($flags & self::F_NONREVIEWER)
	    $q[] = "MyReview.reviewNeedsSubmit is null";
	if ($flags & self::F_PAPERNONBLIND) {
	    if ($Conf->timeReviewerViewAcceptedAuthors()
		|| $Conf->subBlindUntilReview())
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
	    // The purpose of _clauseTermSetField is to match AT LEAST those
	    // papers that contain "$t->value" as a word in the $field field.
	    // A substring match contains at least those papers; but only if
	    // the current term is positive (= not negated).  If the current
	    // term is negated, we say NO paper matches this clause.  (NOT no
	    // paper is every paper.)  Later code will check for a substring.
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
	    $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", "\3$value"));
	} else if ($value[0] == "\3") {
	    $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", $value));
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

    static function unusableRatings($privChair, $contactId) {
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

    function _clauseTermSetReviews($thistab, $extrawhere,
				   &$t, &$tables, &$columns) {
	if (!isset($tables[$thistab])) {
	    $where = array();
	    $reviewtable = "PaperReview";
	    if ($t->flags & self::F_TYPEMASK)
		$where[] = "reviewType=" . ($t->flags & self::F_TYPEMASK);
	    if ($t->flags & self::F_NONBLIND)
		$where[] = "reviewBlind=0";
	    if ($t->flags & self::F_COMPLETE)
		$where[] = "reviewSubmitted>0";
	    else if ($t->flags & self::F_INCOMPLETE)
		$where[] = "reviewNeedsSubmit>0";
	    if (($x = $t->get("round")) !== null) {
		if (count($x) == 0)
		    $where[] = "false";
		else
		    $where[] = "reviewRound in (" . join(",", $x) . ")";
	    }
	    if (($x = $t->get("rate")) !== null)
		$this->_clauseTermSetRating($reviewtable, $where, $x);
	    if ($extrawhere)
		$where[] = $extrawhere;
	    $wheretext = "";
	    if (count($where))
		$wheretext = " where " . join(" and ", $where);
	    $tables[$thistab] = array("left join", "(select paperId, count(PaperReview.reviewId) as count from $reviewtable$wheretext group by paperId)");
	}
	$q = array();
	$this->_clauseTermSetFlags($t->flags, $q);
	$q[] = "coalesce($thistab.count,0)" . $t->value->countexpr;
	$columns[$thistab . "_matches"] = "$thistab.count";
	$t->link = $thistab . "_matches";
	return "(" . join(" and ", $q) . ")";
    }

    function _clauseTermSetRevpref($thistab, $extrawhere,
				   &$t, &$tables, &$columns) {
	if (!isset($tables[$thistab])) {
	    $where = array();
	    $reviewtable = "PaperReviewPreference";
	    if ($extrawhere)
		$where[] = $extrawhere;
	    $wheretext = "";
	    if (count($where))
		$wheretext = " where " . join(" and ", $where);
	    $tables[$thistab] = array("left join", "(select paperId, count(PaperReviewPreference.preference) as count from $reviewtable$wheretext group by paperId)");
	}
	$q = array();
	$this->_clauseTermSetFlags($t->flags, $q);
	$q[] = "coalesce($thistab.count,0)" . $t->value->countexpr;
	$columns[$thistab . "_matches"] = "$thistab.count";
	$t->link = $thistab . "_matches";
	return "(" . join(" and ", $q) . ")";
    }

    function _clauseTermSetComments($thistab, $extrawhere,
				    &$t, &$tables, &$columns) {
	if (!isset($tables[$thistab])) {
	    $where = array();
	    if ($t->flags & self::F_REVIEWERCOMMENT)
		$where[] = "forReviewers=1";
	    if (!$this->privChair)
		$where[] = "(forReviewers!=2 or contactId=$this->contactId)";
	    if ($t->flags & self::F_AUTHORRESPONSE)
		$where[] = "forAuthors=2";
	    else if ($t->flags & (self::F_AUTHOR | self::F_AUTHORCOMMENT))
		$where[] = "forAuthors>0";
	    if ($t->flags & self::F_NONBLIND)
		$where[] = "blind=0";
	    if ($extrawhere)
		$where[] = $extrawhere;
	    $wheretext = "";
	    if (count($where))
		$wheretext = " where " . join(" and ", $where);
	    $tables[$thistab] = array("left join", "(select paperId, count(PaperComment.commentId) as count from PaperComment$wheretext group by paperId)");
	}
	$q = array();
	$this->_clauseTermSetFlags($t->flags, $q);
	$q[] = "coalesce($thistab.count,0)" . $t->value->countexpr;
	$columns[$thistab . "_matches"] = "$thistab.count";
	$t->link = $thistab . "_matches";
	return "(" . join(" and ", $q) . ")";
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
				       "PaperConflict", "contactId",
				       " and " . $this->contact->actAuthorSql("%"),
				       $tables, $columns, $f);
	} else if ($tt == "re") {
	    $extrawhere = array();
	    if ($t->value->contactsql)
		$extrawhere[] = $t->value->contactWhere("contactId");
	    if ($t->value->fieldsql)
		$extrawhere[] = $t->value->fieldsql;
	    $extrawhere = join(" and ", $extrawhere);
	    if ($extrawhere == "" && $t->get("round") === null && $t->get("rate") === null)
		$thistab = "Numreviews_" . ($t->flags & (self::F_TYPEMASK | self::F_NONBLIND | self::F_COMPLETE | self::F_INCOMPLETE));
	    else
		$thistab = "Reviews_" . count($tables);
	    $f[] = $this->_clauseTermSetReviews($thistab, $extrawhere, $t, $tables, $columns);
	} else if ($tt == "revpref") {
	    $extrawhere = array();
	    if ($t->value->contactsql)
		$extrawhere[] = $t->value->contactWhere("contactId");
	    if ($t->value->fieldsql)
		$extrawhere[] = $t->value->fieldsql;
	    $extrawhere = join(" and ", $extrawhere);
	    $thistab = "Revpref_" . count($tables);
	    $f[] = $this->_clauseTermSetRevpref($thistab, $extrawhere, $t, $tables, $columns);
	} else if ($tt == "conflict") {
	    $this->_clauseTermSetTable($t, "\3" . $t->value->contactsql, $t->value->countexpr, "Conflict",
				       "PaperConflict", "contactId", "",
				       $tables, $columns, $f);
	} else if ($tt == "ncmt") {
	    $rtype = $t->flags & (self::F_REVIEWERCOMMENT | self::F_AUTHORCOMMENT | self::F_AUTHORRESPONSE);
	    $f[] = $this->_clauseTermSetComments("Numcomments_$rtype", "", $t, $tables, $columns);
	} else if ($tt == "cmt") {
	    $thistab = "Comments_" . count($tables);
	    $f[] = $this->_clauseTermSetComments($thistab, $t->value->contactWhere("contactId"), $t, $tables, $columns);
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
	    for ($i = 0; $i < count($t->value); $i += 2) {
		if (is_array($t->value[$i + 1]))
		    $q[] = "Paper." . $t->value[$i] . " in (" . join(",", $t->value[$i + 1]) . ")";
		else
		    $q[] = "Paper." . $t->value[$i] . $t->value[$i + 1];
	    }
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
	    $this->_clauseTermSet($t->value, !$negated, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "not (" . join(" or ", $ff) . ")";
	} else if ($tt == "and") {
	    $ff = array();
	    foreach ($t->value as $subt)
		$this->_clauseTermSet($subt, $negated, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "(" . join(" and ", $ff) . ")";
	} else if ($tt == "or" || $tt == "then") {
	    $ff = array();
	    foreach ($t->value as $subt)
		$this->_clauseTermSet($subt, $negated, $tables, $columns, $ff);
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
	if (($flags & self::F_AUTHOR) && $this->actAuthorView($row))
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
	    && $Conf->paperBlind($row, $this->contact))
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
	else if ($tt == "re" || $tt == "conflict" || $tt == "revpref"
		 || $tt == "ncmt" || $tt == "cmt") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		return false;
	    else {
		$fieldname = $t->link;
		return $t->value->test((int) $row->$fieldname);
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
		    if (is_array($expr))
			$ans = in_array($row->$fieldname, $expr);
		    else if ($expr[0] == '=')
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
			$ans = array_search($row->$fieldname, $this->contactmatch[substr($expr, 1, strpos($expr, "\1", 1) - 1)]) !== false;
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

    function _search() {
	global $Conf, $searchMatchNumber;
	if ($this->_matchTable === false)
	    return false;
	assert($this->_matchTable === null);
	$this->_matchTable = "PaperMatches_" . $searchMatchNumber;
	++$searchMatchNumber;

	if ($this->limitName == "x") {
	    if (!$Conf->qe("create temporary table $this->_matchTable select Paper.paperId from Paper where false", "while performing search"))
		return ($this->_matchTable = false);
	    else
		return true;
	}

	// parse and clean the query
	$qe = $this->_queryClean($this->_searchQueryType($this->q));
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
	else if ($this->limitName == "act" || $this->limitName == "r")
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
	    $filters[] = $this->contact->actAuthorSql("PaperConflict");
	    $this->needflags |= self::F_AUTHOR;
	} else if ($this->limitName == "r") {
	    $filters[] = "MyReview.reviewType is not null";
	    $this->needflags |= self::F_REVIEWER;
	} else if ($this->limitName == "ar") {
	    $filters[] = "(" . $this->contact->actAuthorSql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReview.reviewType is not null))";
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
	if (($this->needflags & (self::F_REVIEWER | self::F_NONREVIEWER))
	    || (($this->needflags & self::F_PAPERNONBLIND)
		&& $Conf->subBlindUntilReview())) {
	    $qb = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qb = " or MyReview.reviewToken in (" . join(",", $_SESSION["rev_tokens"]) . ")";
	    $tables["MyReview"] = array("left join", "PaperReview", "(MyReview.contactId=$this->contactId$qb)");
	    $columns["myReviewNeedsSubmit"] = "MyReview.reviewNeedsSubmit";
	    if (($this->needflags & self::F_PAPERNONBLIND)
		&& $Conf->subBlindUntilReview())
		$columns["myReviewSubmitted"] = "MyReview.reviewSubmitted";
	}
	if ($this->needflags & self::F_PAPERNONBLIND) {
	    $columns["paperBlind"] = "Paper.blind";
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
	if (!$Conf->qe("create temporary table $this->_matchTable $q", "while performing search"))
	    return ($this->_matchTable = false);

	// correct query
	$this->thenmap = ($qe->type == "then" ? array() : null);
	if ($this->preciseQuery || $this->thenmap !== null
	    || $this->headingmap !== null) {
	    $delete = array();
	    $result = $Conf->qe("select * from $this->_matchTable", "while performing search");
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
		if ($this->headingmap !== null && $this->thenmap !== null)
		    $this->headingmap[$row->paperId] = $qe->value[$x]->get("heading");
		else
		    $this->headingmap[$row->paperId] = $this->_currentHeading;
	    }
	    if (count($delete)) {
		$q = "delete from $this->_matchTable where paperId in (" . join(",", $delete) . ")";
		//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars($q))));
		if (!$Conf->qe($q, "while performing search")) {
		    $Conf->q("drop temporary table $this->_matchTable");
		    return ($this->_matchTable = false);
		}
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
	if ($this->q)
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
	else if ($this->limitName == "a") {
	    // If complex author SQL, always do search the long way
	    if ($this->contact->actAuthorSql("%", true))
		return true;
	    $queryOptions["author"] = 1;
	} else if ($this->limitName == "req" || $this->limitName == "reqrevs")
	    $queryOptions["myReviewRequests"] = 1;
	else if ($this->limitName == "act")
	    $queryOptions["active"] = 1;
	else if ($this->limitName == "lead")
	    $queryOptions["myLead"] = 1;
	return false;
    }

    function simplePaperList() {
	if (preg_match('/\A\s*#?\d[-#\d\s]*\z/s', $this->q)) {
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
	} else
	    return null;
    }

    function matchTable() {
	if ($this->_matchTable === null)
	    $this->_search();
	return $this->_matchTable;
    }

    function paperList() {
	global $Conf;
	if (!$this->_matchTable && !$this->_search())
	    return array();
	$x = array();
	$result = $Conf->q("select paperId from $this->_matchTable");
	while (($row = edb_row($result)))
	    $x[] = $row[0];
	return $x;
    }

    function url() {
	$url = $this->urlbase;
	if (substr($this->urlbase, 0, 6) == "search" || $this->q != "")
	    $url .= "&q=" . urlencode($this->q);
	return $url;
    }

    function _tagDescription() {
	if ($this->q == "")
	    return false;
	else if (strlen($this->q) <= 24)
	    return htmlspecialchars($this->q);
	else if (substr($this->q, 0, 4) == "tag:")
	    $t = substr($this->q, 4);
	else if (substr($this->q, 0, 5) == "-tag:")
	    $t = substr($this->q, 5);
	else if (substr($this->q, 0, 6) == "notag:"
		 || substr($this->q, 0, 6) == "order:")
	    $t = substr($this->q, 6);
	else if (substr($this->q, 0, 7) == "rorder:")
	    $t = substr($this->q, 7);
	else
	    return false;
	require_once("tags.inc");
	if (!checkTag($t, CHECKTAG_QUIET))
	    return false;
	if ($this->q[0] == "-")
	    return "no" . substr($this->q, 1);
	else
	    return $this->q;
    }

    function description($listname) {
	require_once("paperlist.inc");
	$desc = PaperList::listDescription($listname);
	if ($this->q == "")
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
	return "p:" . $this->limitName . ":" . addcslashes($this->q, "\\:")
	    . ":::" . $sort;
    }

    function decorateSessionList($l, $listname, $sort = "") {
	global $ConfSiteBase;
	$l["url"] = $this->url();
	if ($ConfSiteBase
	    && substr($l["url"], 0, strlen($ConfSiteBase)) == $ConfSiteBase)
	    $l["url"] = substr($l["url"], strlen($ConfSiteBase));
	$l["description"] = $this->description($listname);
	$l["timestamp"] = time();
	$l["listid"] = $this->listId($sort);
	if ($this->matchPreg)
	    $l["matchPreg"] = $this->matchPreg;
	return $l;
    }

    function sessionList() {
	return $this->decorateSessionList($this->paperList(), $this->limitName);
    }

    static function parsePapersel() {
	global $Me, $papersel, $paperselmap;
	if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
	    $_REQUEST["p"] = $_REQUEST["pap"];
	if (isset($_REQUEST["p"]) && $_REQUEST["p"] == "all") {
	    $s = new PaperSearch($Me, $_REQUEST);
	    $_REQUEST["p"] = $s->paperList();
	}
	if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
	    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
	if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"])) {
	    $papersel = array();
	    $paperselmap = array();
	    foreach ($_REQUEST["p"] as $p)
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

    static function clearPaperselRequest() {
	unset($_REQUEST["p"]);
	unset($_REQUEST["pap"]);
    }

    static function searchTypes($me) {
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

    static function searchTypeSelector($tOpt, $type, $tabindex) {
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
