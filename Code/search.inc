<?php
// search.inc -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
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
		    'pri' => 'pri', 'primary' => 'pri',
		    'cpri' => 'cpri', 'cprimary' => 'cpri',
		    'ipri' => 'ipri', 'iprimary' => 'ipri',
		    'sec' => 'sec', 'secondary' => 'sec',
		    'csec' => 'csec', 'csecondary' => 'csec',
		    'isec' => 'isec', 'isecondary' => 'isec',
		    'ext' => 'ext', 'external' => 'ext',
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

class SearchTerm {
    var $type;
    var $link;
    var $flags;
    var $value;
    var $extra;

    function SearchTerm($t, $f = 0, $v = null) {
	$this->type = $t;
	$this->link = false;
	$this->flags = $f;
	$this->value = $v;
    }
}

class PaperSearch {

    const F_TYPEMASK = 7;
    const F_COMPLETE = 8;
    const F_INCOMPLETE = 16;
    const F_NONCONFLICT = 32;
    const F_AUTHOR = 64;
    const F_REVIEWER = 128;
    const F_NONBLIND = 256;
    const F_PAPERNONBLIND = 512;
    const F_TIMEWITHDRAWN = 1024;
    const F_TIMESUBMITTED = 2048;
    const F_AUTHORCOMMENT = 4096;
    const F_REVIEWERCOMMENT = 8192;
    const F_FALSE = 16384;

    var $contactId;
    var $privChair;
    var $amPC;
    var $limitName;
    var $allowAuthor;
    var $fields;
    var $orderTag;
    var $orderReverse;
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

    function PaperSearch($me, $opt) {
	global $Conf, $ConfSiteSuffix;

	// contact facts
	$this->privChair = $me->privChair;
	$this->amPC = $me->isPC;

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
	$this->orderTag = false;
	$this->orderReverse = false;
	$this->reviewerContact = 0;
	$this->contactId = $me->contactId;
	$this->warnings = null;
	if ($this->privChair && rcvtint($opt["c"]) > 0)
	    $this->contactId = rcvtint($opt["c"]);

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
    }

    function warn($text) {
	if (!$this->warnings)
	    $this->warnings = array();
	$this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into a series of terms.
    // By default terms are linked by "and" ($t->link == false);
    // occasionally terms are linked by "or" ($t->link == true).
    // Negation is represented by nesting within a "not" term ($t->type "not").

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

    function _searchField($word, $rtype, $negated, &$qt, $blind) {
	global $Conf;

	if ($this->privChair
	    || ($this->amPC && !$blind)
	    || ($this->amPC && $Conf->blindSubmission() == BLIND_NEVER))
	    $qt[] = new SearchTerm($rtype, 0, $word);
	else {
	    if ($blind && $Conf->blindSubmission() == BLIND_ALWAYS)
		/* only look at papers you submitted */;
	    else if ($this->amPC)
		$qt[] = new SearchTerm($rtype, self::F_PAPERNONBLIND, $word);
	    else {
		$rt = self::F_REVIEWER;
		if ($blind && $Conf->blindSubmission() != BLIND_NEVER)
		    $rt |= self::F_PAPERNONBLIND;
		$qt[] = new SearchTerm($rtype, $rt, $word);
	    }
	    $qt[] = new SearchTerm($rtype, self::F_AUTHOR, $word);
	}

	if (!$negated)
	    $this->regex[$rtype][] = self::_fieldPreg($word);
    }

    function _searchReviewer($word, $rtype, $negated, &$qt) {
	global $Conf;

	if ($word == "any")
	    $word = ">0";
	else if ($word == "none")
	    $word = "=0";

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

	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $word, $m)) {
	    $m[1] = ($m[1] ? $m[1] : "=");
	    if ($m[2] == 0 && $m[1] == "<")
		$type = "f";
	    else if ($m[2] == 0 && $m[1] == ">=")
		$type = "t";
	    else {
		$type = "nre";
		$value = $m[1] . $m[2];
	    }
	} else {
	    $this->contactmatch[] = sqlq_for_like($word);
	    $this->contactmatchPC = false;
	    $type = "re";
	    $value = "\1" . (count($this->contactmatch) - 1) . "\1";
	    $this->reviewerContact++;
	}

	$nqt = count($qt);
	if ($type == "f" || $type == "t")
	    $qt[] = new SearchTerm($type);
	else if ($this->privChair)
	    $qt[] = new SearchTerm($type, $rt, $value);
	else {
	    $deblinder = 0;
	    if ($type == "re" && $Conf->blindReview() == BLIND_ALWAYS)
		$deblinder = self::F_FALSE;
	    else if ($type == "re" && $Conf->blindReview() == BLIND_OPTIONAL)
		$deblinder = self::F_NONBLIND;

	    if ($this->amPC) {
		$pc_seeallrev = $Conf->setting("pc_seeallrev");
		if ($pc_seeallrev == 1
		    || ($pc_seeallrev == 0 && $Conf->setting("pcrev_any")))
		    $qt[] = new SearchTerm($type, $rt | self::F_NONCONFLICT, $value);
		else {
		    if ($pc_seeallrev == 2)
			$qt[] = new SearchTerm($type, $rt | $deblinder | self::F_NONCONFLICT, $value);
		    $qt[] = new SearchTerm($type, $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
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

    function _searchComment($word, $ctype, &$qt) {
	global $Conf;

	if ($word == "" || $word == "any")
	    $word = ">0";
	else if ($word == "none")
	    $word = "=0";

	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $word, $m)) {
	    $m[1] = ($m[1] ? $m[1] : "=");
	    if ($m[2] == 0 && $m[1] == "<")
		$type = "f";
	    else if ($m[2] == 0 && $m[1] == ">=")
		$type = "t";
	    else {
		$type = "ncmt";
		$value = $m[1] . $m[2];
	    }
	} else
	    $type = "f";

	$rt = ($ctype == "aucmt" ? self::F_AUTHORCOMMENT : 0);
	$nqt = count($qt);
	if ($type == "f" || $type == "t")
	    $qt[] = new SearchTerm($type);
	else if ($this->privChair)
	    $qt[] = new SearchTerm($type, $rt, $value);
	else {
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

    function _searchReviews($word, $rf, $field, $negated, &$qt) {
	global $Conf;

	if (isset($rf->options[$field])) {
	    if ($word == "any")
		$value = "$field>0";
	    else if ($word == "none")
		$value = "$field=0";
	    else if (preg_match('/^(\d*?)([<>]?=?)?\s*([A-Za-z]|\d+)$/s', $word, $m)) {
		if ($m[1] == "")
		    $m[1] = 1;
		if (($rf->reviewFields[$field] > 1) != (ctype_digit($m[3]) == false))
		    $value = "$field=-1";
		else if ($rf->reviewFields[$field] > 1) {
		    // switch meaning of inequality
		    if ($m[2] == "" || $m[2] == "=")
			$m[2] = "=";
		    else if ($m[2][0] == "<")
			$m[2] = ">" . substr($m[2], 1);
		    else
			$m[2] = "<" . substr($m[2], 1);
		    $value = array($field . $m[2] . ($rf->reviewFields[$field] - ord(strtoupper($m[3]))), $m[1]);
		} else
		    $value = array($field . ($m[2] ? $m[2] : "=") . $m[3], $m[1]);
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
		if ($pc_seeallrev >= 1
		    || ($pc_seeallrev == 0 && $Conf->setting("pcrev_any")))
		    $qt[] = new SearchTerm("rf", $rt | self::F_NONCONFLICT, $value);
		else
		    $qt[] = new SearchTerm("rf", $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
	    } else if ($Conf->setting("extrev_view") >= 1)
		$qt[] = new SearchTerm("rf", $rt | self::F_REVIEWER | self::F_NONCONFLICT, $value);
	    if ($Conf->timeAuthorViewReviews())
		$qt[] = new SearchTerm("rf", ($rt & ~self::F_TYPEMASK) | self::F_AUTHOR | self::F_COMPLETE, $value);
	}
	if (count($qt) == $nqt)
	    $qt[] = new SearchTerm("f");
    }

    function _searchTags($word, $special, $negated, &$qt) {
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

	if (preg_match('/\A([^#<>!=]+)#?([<>!]?=?)(-?\d+)\z/', $word, $m)
	    && $m[1] != "any" && $m[1] != "none") {
	    $tagword = $m[1];
	    $extra = ($m[2] == "!" ? "!=" : ($m[2] == "" ? "=" : $m[2])) . $m[3];
	} else {
	    $tagword = $word;
	    $extra = null;
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
	}

	if (($e = checkTagError($tagword, CHECKTAG_ALLOWRESERVED | CHECKTAG_NOINDEX)))
	    $this->warn(htmlspecialchars($e));
	$value = $tagword;
	$rt = 0;
	if ($value && $twiddle !== false)
	    $value = $twiddlecid . $value;
	else if (!$this->privChair && $Conf->setting("tag_seeall") <= 0)
	    $rt |= self::F_NONCONFLICT;
	if (($special == "order" || $special == "rorder" || !$special)
	    && !$negated) {
	    $this->orderTag = ($this->orderTag === false ? $value : null);
	    $this->orderReverse = ($special == "rorder");
	}
	$qt[] = new SearchTerm("tag", $rt, $extra ? array($value, $extra) : $value);
    }

    function _searchPopWord(&$str) {
	preg_match('/^([-+]?"[^"]*"?|[a-zA-Z][a-zA-Z0-9]*:"[^"]*"?|[^"\s]+)/s', $str, $m);
	$str = ltrim(substr($str, strlen($m[0])));
	return $m[0];
    }

    function _searchQueryWord($word, &$rest, $negated, &$qt) {
	global $searchTags, $ratingTypes, $Conf;
	$nqt = count($qt);

	// check for paper number
	if ($word[0] == "#" && preg_match('/^#\d+(-#?\d+)?/', $word))
	    $word = substr($word, 1);
	if (ctype_digit($word)) {
	    $qt[] = new SearchTerm($negated ? "npn" : "pn", 0, $word);
	    return;
	} else if (preg_match('/^(\d+)-#?(\d+)$/', $word, $m)) {
	    if ($m[1] <= $m[2]) {
		for ($i = $m[1]; $i <= $m[2]; ++$i)
		    $qt[] = new SearchTerm($negated ? "npn" : "pn", 0, $i);
	    } else
		$qt[] = new SearchTerm($negated ? "npn" : "pn", 0, -1);
	    return;
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

	if ($word[0] == '"')
	    $word = str_replace(array('"', '*'), array('', '\*'), $word);
	if ($special ? $special == "notag" : isset($this->fields['notag'])) {
	    $special = "tag";
	    $negated = !$negated;
	}

	if ($special ? $special == "ti" : isset($this->fields['ti']))
	    $this->_searchField($word, "ti", $negated, $qt, false);
	if ($special ? $special == "ab" : isset($this->fields['ab']))
	    $this->_searchField($word, "ab", $negated, $qt, false);
	if ($special ? $special == "au" : isset($this->fields['au']))
	    $this->_searchField($word, "au", $negated, $qt, true);
	if ($special ? $special == "co" : isset($this->fields['co']))
	    $this->_searchField($word, "co", $negated, $qt, true);
	foreach (array("re", "cre", "ire", "pri", "cpri", "ipri", "sec", "csec", "isec", "ext", "cext", "iext") as $rtype)
	    if ($special ? $special == $rtype : isset($this->fields[$rtype]))
		$this->_searchReviewer($word, $rtype, $negated, $qt);
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
	    $this->_searchTags($word, $special, $negated, $qt);
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
	if (($special ? $special == "option" : isset($this->fields['option']))) {
	    $type = "option";
	    if ((($pos = strpos($word, "#")) !== false)
		|| (($pos = strpos($word, "=")) !== false)) {
		$oname = substr($word, 0, $pos);
		$oval = strtolower(simplifyWhitespace(substr($word, $pos + 1)));
	    } else {
		$oname = $word;
		$oval = "";
	    }
	    $oname = strtolower(simplifyWhitespace($oname));

	    // match all options
	    $qo = array();
	    foreach (paperOptions() as $oid => $o)
		// See also checkOptionNameUnique() in settings.php
		if ($oname == "none" || $oname == "any"
		    || strstr(strtolower($o->optionName), $oname) !== false) {
		    // find the relevant values
		    if ($oval !== ""
			&& ($v = defval($o, "optionValues", "")) !== "") {
			$xval = matchValue(explode("\n", $v), $oval);
			if (count($xval) == 0)
			    continue;
			$xval = " in (" . join(",", $xval) . ")";
		    } else if ($oval === "" || $oval === "yes")
			$xval = "!=0";
		    else if ($oval === "no")
			$xval = "=0";
		    else
			continue;

		    // add the query to $qo
		    if ($this->privChair || ($this->amPC && $o->pcView))
			$qo[] = new SearchTerm($type, 0, array($oid, $xval));
		    else {
			if ($o->pcView)
			    $qo[] = new SearchTerm($type, self::F_REVIEWER, array($oid, $xval));
			$qo[] = new SearchTerm($type, self::F_AUTHOR, array($oid, $xval));
		    }
		}

	    // if "none", negate
	    if (count($qo) > 0 && $oname === "none")
		$qo = array("not", 0, $qo);
	    if (count($qo) == 0) {
		$this->warn("&ldquo;" . htmlspecialchars($word) . "&rdquo; doesn't match a submission option.");
		$qo[] = new SearchTerm("f", 0, "");
	    }
	    $qt = array_merge($qt, $qo);
	}
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
		if (count($value) == 1)
		    $value = "=" . $value[0];
		else
		    $value = " in (" . join(",", $value) . ")";
	    }
	    $value = array("outcome", $value);
	    $nqt = count($qt);
	    if ($this->privChair)
		$qt[] = new SearchTerm("pf", 0, $value);
	    else {
		if ($this->amPC && $Conf->timePCViewDecision())
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
	    && $this->privChair) {
	    if ($word == "none" || $word == "any")
		$value = $word;
	    else {
		$this->contactmatch[] = sqlq_for_like($word);
		$this->contactmatchPC = false;
		$value = "\1" . (count($this->contactmatch) - 1) . "\1";
	    }
	    $qt[] = new SearchTerm("conflict", 0, $value);
	}
	if (($special ? $special == "round" : isset($this->fields["round"]))
	    && $this->amPC) {
	    $this->reviewAdjust = true;
	    if ($Conf->setting("allowPaperOption") < 11)
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
	    if ($Conf->setting("allowPaperOption") < 12)
		$qt[] = new SearchTerm("f");
	    else if (preg_match('/\A(.+?)\s*(|[!=<>]?=?)\s*(\d*)\z/', $word, $m)
		     && ($m[3] !== "" || $m[2] === "")) {
		// adjust counts
		if ($m[3] === "") {
		    $m[2] = ">";
		    $m[3] = "0";
		}
		if ($m[2] === "")
		    $m[2] = ($m[3] == 0 ? "=" : ">=");
		else if ($m[2] === "==")
		    $m[2] = "=";
		else if ($m[2] === "!")
		    $m[2] = "!=";

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
	if ($special && !isset($searchTags[$special])) {
	    $a = preg_split("/([^a-zA-Z0-9])/", $special, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
	    for ($i = 0; $i < count($a); ++$i)
		$a[$i] = preg_quote($a[$i]);
	    $field = null;
	    $rf = reviewForm();
	    foreach ($rf->fieldOrder as $fn) {
		if (count($a) == 1 && strcasecmp($a[0], $rf->abbrevName[$fn]) == 0) {
		    $field = $fn;
		    break;
		}
		for ($i = 0; $i < count($a); ++$i)
		    if ($a[$i] != "-" && $a[$i] != "_"
			&& !preg_match("/\\b$a[$i]/i", $rf->shortName[$fn]))
			break;
		if ($i == count($a))
		    $field = ($field === null ? $fn : false);
	    }
	    if ($field)
		$this->_searchReviews($word, $rf, $field, $negated, $qt);
	}

	for ($i = $nqt; $i < count($qt) - 1; ++$i)
	    $qt[$i]->link = true;
	//if ($negated && count($qt) == $nqt + 1 && $qt[$nqt]->type == "round")
	//$qt->value = array_diff(array_keys($Conf->settings["rounds"]), $qt->value);
	if ($negated && count($qt) != $nqt) {
	    $value = array_splice($qt, $nqt);
	    $qt[] = new SearchTerm("not", 0, $value);
	}
    }

    function _searchQueryType($str, $type, &$qt) {
	$nqt = count($qt);
	while ($str !== "") {
	    $word = $this->_searchPopWord($str);
	    if ($word == "OR" || $type == "any") {
		if (count($qt) > $nqt)
		    $qt[count($qt) - 1]->link = true;
		continue;
	    }

	    $neg = false;
	    if ($word[0] == "-" || $word[0] == "+") {
		$neg = ($word[0] == "-");
		$word = substr($word, 1);
	    }
	    $this->_searchQueryWord($word, $str, $neg || $type == "none", $qt);
	}
    }


    // CLAUSING
    // Combines the term series into clauses.  The basic purpose of this step
    // is to combine all paper numbers into a single group, and link associated
    // "or" terms.

    function _queryMakeClauses(&$qt, &$qc) {
	// group words into "CNF" clauses: an array of clauses connected by
	// AND, where each clause is an array of terms connected by OR.
	// Words connected by "OR" are in the same CNF clause.
	// All words connected by "OR" to any paper number are in the same
	// CNF clause.

	$tpn = new SearchTerm("pn", 0, array(array(), array()));
	$qc = array();
	$qor = null;
	for ($i = 0; $i < count($qt); ++$i) {
	    $t = $qt[$i];

	    if ($t->type == "pn" || $t->type == "npn") {
		// Combine adjacent paper number queries.
		$x = array(array(), array());
		while (1) {
		    $x[$t->type == "pn" ? 0 : 1][] = $t->value;
		    if ($i + 1 == count($qt)
			|| $t->link
			|| ($qt[$i+1]->type != "pn"
			    && $qt[$i+1]->type != "npn"))
			break;
		    ++$i;
		    $t = $qt[$i];
		}
		// Collect top-level paper numbers into a single $tpn.
		if ((!$t->link || $i + 1 == count($qt)) && !$qor) {
		    $tpn->value[0] = array_merge($tpn->value[0], $x[0]);
		    $tpn->value[1] = array_merge($tpn->value[1], $x[1]);
		    continue;
		}
		$tt = new SearchTerm("pn", 0, $x);
	    } else
		$tt = $t;

	    // Clauses joined by OR go into a $qor array.
	    if ($qor)
		$qor[] = $tt;
	    else if ($t->link)
		$qor = array($tt);
	    else
		$qc[] = array($tt);

	    // If done with an OR array, we've built a clause.
	    if ((!$t->link || $i + 1 == count($qt)) && $qor) {
		$qc[] = $qor;
		$qor = null;
	    }
	}

	if (count($tpn->value[0]) || count($tpn->value[1]))
	    $qc[] = array($tpn);

	// Now we have an array of arrays in "CNF"
	if ($this->reviewAdjust) {
	    $rounderror = false;
	    for ($i = 0; $i < count($qc); $i++)
		$this->_queryFixRounds($qc[$i], $rounderror);
	    $this->_queryApplyAllRounds($qc);
	    if ($rounderror) {
		global $Conf;
		$Conf->errorMsg("Unexpected use of &ldquo;round:&rdquo; or &ldquo;rate:&rdquo; ignored.  Stick to the basics, such as &ldquo;re:reviewername round:roundname&rdquo;.");
	    }
	}
    }

    // remove strange uses of rounds
    function _queryFixRounds(&$qcl, &$rounderror) {
	global $Conf;

	foreach ($qcl as &$qt)
	    if ($qt->type == "not") {
		$this->_queryFixRounds($qt->value, $rounderror);
		if (count($qt->value) == 1 && $qt->value[0]->type == "revadj") {
		    $negterm = $qt->value[0];
		    $x = array();
		    if (isset($negterm->value["round"]))
			$x["round"] = array_diff(array_keys($Conf->settings["rounds"]), $negterm->value["round"]);
		    if (isset($negterm->value["rate"]))
			$x["rate"] = "not (" . $negterm->value["rate"] . ")";
		    $qt = new SearchTerm("revadj", 0, $x);
		}
	    }

	for ($i = 1; $i < count($qcl); $i++)
	    if ($qcl[$i]->type == "revadj") {
		if ($i == 1 && $qcl[0]->type == "revadj") {
		    if (isset($qcl[1]->value["round"]))
			$qcl[0]->value["round"] = array_unique(array_merge(defval($qcl[0]->value, "round", array()), $qcl[1]->value["round"]));
		    if (isset($qcl[1]->value["rate"]))
			$qcl[0]->value["rate"] = "(" . defval($qcl[0]->value, "rate", "false") . ") or (" . $qcl[1]->value["rate"] . ")";
		} else
		    $rounderror = true;
		array_splice($qcl, 1, 1);
		$i--;
	    }
	if ($qcl[0]->type == "revadj" && count($qcl) > 1) {
	    $rounderror = true;
	    array_splice($qcl, 0, 1);
	}
    }

    // apply rounds to reviewer searches
    function _queryApplyRounds(&$qcl, &$roundterm) {
	$applied = 0;
	foreach ($qcl as &$qt)
	    if ($qt->type == "not")
		$applied += $this->_queryApplyRounds($qt->value, $roundterm);
	    else if ($qt->type == "re" || $qt->type == "nre"
		     || $qt->type == "rf") {
		$qt->extra = $roundterm->value;
		++$applied;
	    }
	return $applied;
    }

    function _queryApplyAllRounds(&$qc) {
	$roundterm = null;
	$applied = $first_applied = 0;
	for ($i = count($qc) - 1; $i >= 0; --$i)
	    if ($qc[$i][0]->type == "revadj") {
		if (!$roundterm || $applied) {
		    $roundterm =& $qc[$i][0];
		    $applied = false;
		} else {
		    if (isset($roundterm->value["round"])
			&& isset($qc[$i][0]->value["round"]))
			$roundterm->value["round"] = array_intersect($roundterm->value["round"], $qc[$i][0]->value["round"]);
		    else if (isset($qc[$i][0]->value["round"]))
			$roundterm->value["round"] = $qc[$i][0]->value["round"];
		    if (isset($qc[$i][0]->value["rate"]))
			$roundterm->value["rate"] = "(" . defval($roundterm->value, "rate", "true") . ") and (" . $qc[$i][0]->value["rate"] . ")";
		}
	    } else if ($roundterm) {
		$applied += $this->_queryApplyRounds($qc[$i], $roundterm);
		if ($applied && !$first_applied) {
		    for ($j = $i + 1; $j < count($qc); ++$j)
			$this->_queryApplyRounds($qc[$j], $roundterm);
		    $first_applied = true;
		}
	    }
	if ($roundterm && !$first_applied)
	    for ($j = 0; $j < count($qc); ++$j)
		$applied = $this->_queryApplyRounds($qc[$j], $roundterm);
	if ($roundterm && !$applied) {
	    $t = new SearchTerm("re", 0, "=" . $this->contactId);
	    $t->extra = $roundterm->value;
	    $qc[] = array($t);
	    $this->reviewerContact++;
	}
    }


    // QUERY CONSTRUCTION
    // Build a database query corresponding to a set of clauses.
    // The query may be conservative; QUERY EVALUATION makes it precise.
    // At this point $t->link is garbage, and is reused.

    function _clauseTermSetFlags($flags, &$q) {
	$this->needflags |= $flags;
	if ($flags & self::F_NONCONFLICT)
	    $q[] = "PaperConflict.conflictType is null";
	if ($flags & self::F_AUTHOR)
	    $q[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
	if ($flags & self::F_REVIEWER)
	    $q[] = "MyReview.reviewNeedsSubmit=0";
	if ($flags & self::F_PAPERNONBLIND)
	    $q[] = "Paper.blind=0";
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

    function _clauseTermSetTable(&$t, $shorttab, $table, $field,
				 &$tables, &$columns, &$f) {
	// see also first "tag" case below
	$q = array();
	$this->_clauseTermSetFlags($t->flags, $q);
	if ($t->value == "none" || $t->value == "any") {
	    $thistab = "Any" . $shorttab;
	    $tables[$thistab] = array("left join", $table, "Paper.paperId=$thistab.paperId");
	} else if (is_array($t->value)) {
	    $thistab = $shorttab . "_" . count($tables);
	    $tables[$thistab] = array("left join", $table, "Paper.paperId=$thistab.paperId and $thistab.$field in (" . join(",", $t->value) . ")");
	} else if ($t->value[0] == "\1") {
	    $thistab = $shorttab . "_" . count($tables);
	    $tables[$thistab] = array("left join", $table, "Paper.paperId=$thistab.paperId and $thistab.$field" . $t->value);
	} else {
	    $thistab = $shorttab . "_" . count($tables);
	    $tables[$thistab] = array("left join", $table, "Paper.paperId=$thistab.paperId and $thistab.$field='" . sqlq($t->value) . "'");
	}
	$columns[$thistab . "_ct"] = "count($thistab.$field)";
	$t->link = $thistab . "_ct";
	if ($t->value == "none")
	    $q[] = "$thistab.$field is null";
	else
	    $q[] = "$thistab.$field is not null";
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
	if (($pc_seerev = $Conf->setting("pc_seeallrev")) == 0)
	    $pc_seerev = $Conf->setting("pcrev_any");
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
	} else if ($tt == "nre") {
	    $rtype = $t->flags & (self::F_TYPEMASK | self::F_NONBLIND | self::F_COMPLETE | self::F_INCOMPLETE);
	    if (isset($t->extra))
		$rtype = "X" . count($tables);
	    if (!isset($tables["Numreviews_$rtype"])) {
		$where = array();
		$reviewtable = "PaperReview";
		if ($rtype & self::F_TYPEMASK)
		    $where[] = "reviewType=" . ($rtype & self::F_TYPEMASK);
		if ($rtype & self::F_NONBLIND)
		    $where[] = "reviewBlind=0";
		if ($rtype & self::F_COMPLETE)
		    $where[] = "reviewSubmitted>0";
		else if ($rtype & self::F_INCOMPLETE)
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
		$tables["Numreviews_$rtype"] = array("left join", "(select paperId, count(PaperReview.reviewId) as nre from $reviewtable$wheretext group by paperId)", "Paper.paperId=Numreviews_$rtype.paperId");
	    }
	    $columns["nre_$rtype"] = "Numreviews_$rtype.nre";
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "coalesce(Numreviews_$rtype.nre,0)$t->value";
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
	    if ($tt != "rf" || !is_array($t->value))
		$t->value = array($t->value, 1);
	    if ($tt == "re")
		$where[] = "contactId" . $t->value[0];
	    else
		$where[] = $t->value[0];
	    $wheretext = " where " . join(" and ", $where);
	    $tables[$thistab] = array("left join", "(select paperId, count(PaperReview.reviewId) as nre from $reviewtable$wheretext group by paperId)", "Paper.paperId=$thistab.paperId");
	    $columns[$thistab . "_matches"] = "$thistab.nre";
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $q[] = "coalesce($thistab.nre,0)>=" . $t->value[1];
	    $f[] = "(" . join(" and ", $q) . ")";
	    $t->link = $thistab . "_matches";
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
		$tables["Numcomments_$rtype"] = array("left join", "(select paperId, count(commentId) as ncmt from PaperComment$wheretext group by paperId)", "Paper.paperId=Numcomments_$rtype.paperId");
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
	    // expanded from _clauseTermSetTable
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $thistab = "Tag_" . count($tables);
	    $tables[$thistab] = array("left join", "PaperTag", "Paper.paperId=$thistab.paperId and $thistab.tag='" . sqlq($t->value[0]) . "' and $thistab.tagIndex" . $t->value[1]);
	    $columns[$thistab . "_ct"] = "count($thistab.tag)";
	    $t->link = $thistab . "_ct";
	    $q[] = "$thistab.tag is not null";
	    $f[] = "(" . join(" and ", $q) . ")";
	} else if ($tt == "tag") {
	    $this->_clauseTermSetTable($t, "Tag", "PaperTag", "tag",
				       $tables, $columns, $f);
	} else if ($tt == "topic") {
	    $this->_clauseTermSetTable($t, "Topic", "PaperTopic", "topicId",
				       $tables, $columns, $f);
	} else if ($tt == "option") {
	    // expanded from _clauseTermSetTable
	    $q = array();
	    $this->_clauseTermSetFlags($t->flags, $q);
	    $thistab = "Option_" . count($tables);
	    $tables[$thistab] = array("left join", "PaperOption", "Paper.paperId=$thistab.paperId and $thistab.optionId=" . $t->value[0]);
	    $columns[$thistab . "_x"] = "coalesce($thistab.value,0)" . $t->value[1];
	    $t->link = $thistab . "_x";
	    $q[] = $columns[$t->link];
	    $f[] = "(" . join(" and ", $q) . ")";
	} else if ($tt == "conflict") {
	    $this->_clauseTermSetTable($t, "Conflict", "PaperConflict", "contactId",
				       $tables, $columns, $f);
	} else if ($tt == "not") {
	    $ff = array();
	    foreach ($t->value as $subt)
		$this->_clauseTermSet($subt, true, $tables, $columns, $ff);
	    if (!count($ff))
		$ff[] = "false";
	    $f[] = "not (" . join(" or ", $ff) . ")";
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
	if (($flags & self::F_AUTHOR) && $row->conflictType < CONFLICT_AUTHOR)
	    return false;
	if (($flags & self::F_REVIEWER) && $row->myReviewNeedsSubmit !== 0)
	    return false;
	if (($flags & self::F_NONCONFLICT) && $row->conflictType)
	    return false;
	if (($flags & self::F_PAPERNONBLIND) && $row->paperBlind)
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
	    $ans = $this->_clauseTermCheckField($t, $row);
	else if ($tt == "nre") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		$ans = false;
	    else {
		$fieldname = $t->link;
		$x = $row->$fieldname;
		$ans = eval("return \$x$t->value;");
	    }
	} else if ($tt == "re" || $tt == "rf") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		$ans = false;
	    else {
		$fieldname = $t->link;
		$ans = $row->$fieldname >= $t->value[1];
	    }
	} else if ($tt == "ncmt") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		$ans = false;
	    else {
		$fieldname = $t->link;
		$x = $row->$fieldname;
		$ans = eval("return \$x$t[2];");
	    }
	} else if ($tt == "pn") {
	    if (count($t->value[0]) && array_search($row->paperId, $t->value[0]) === false)
		$ans = false;
	    else if (count($t->value[1]) && array_search($row->paperId, $t->value[1]) !== false)
		$ans = false;
	    else
		$ans = true;
	} else if ($tt == "pf") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		$ans = false;
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
	    }
	} else if ($tt == "tag" || $tt == "topic" || $tt == "option"
		   || $tt == "conflict") {
	    if (!$this->_clauseTermCheckFlags($t->flags, $row))
		$ans = false;
	    else {
		$fieldname = $t->link;
		if (is_string($t->value) && $t->value == "none")
		    $ans = $row->$fieldname == 0;
		else
		    $ans = $row->$fieldname != 0;
	    }
	} else if ($tt == "not") {
	    $ans = true;
	    foreach ($t->value as $subt)
		$ans = !$this->_clauseTermCheck($subt, $row);
	} else if ($tt == "f")
	    $ans = false;
	else if ($tt == "t")
	    $ans = true;
	else
	    $ans = true;

	return $ans;
    }


    // BASIC QUERY FUNCTION

    function search() {
	global $Conf;

	if ($this->limitName == "x")
	    return array("create temporary table Matches select Paper.paperId from Paper where false", "");
	$this->_resetQuery();

	// collect query terms
	$qt = array();
	$this->_searchQueryType($this->q, "all", $qt);
	$this->_searchQueryType($this->qo, "any", $qt);
	$this->_searchQueryType($this->qx, "none", $qt);

	// group terms into clauses
	$this->_queryMakeClauses($qt, $qc);
	//$Conf->infoMsg(nl2br(str_replace(" ", "&nbsp;", htmlspecialchars(var_export($qc, true)))));

	// collect clauses into tables, columns, and filters
	$tables = array("Paper" => true);
	$columns = array("paperId" => "Paper.paperId");
	$filters = array();
	$this->preciseQuery = false;
	$this->needflags = 0;
	foreach ($qc as &$c) {
	    $f = array();
	    foreach ($c as &$t)
		$this->_clauseTermSet($t, false, $tables, $columns, $f);
	    unset($t);
	    if (count($f))
		$filters[] = "(" . join(" or ", $f) . ")";
	}
	unset($c);
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
	    $tables["Limiter"] = array("join", "PaperReview", "Paper.paperId=Limiter.paperId");
	else if ($this->limitName == "req")
	    $tables["Limiter"] = array("join", "PaperReview", "Paper.paperId=Limiter.paperId and Limiter.requestedBy=$this->contactId and Limiter.reviewType=" . REVIEW_EXTERNAL);

	// add common tables: conflicts, my own review, paper blindness
	if ($this->needflags & (self::F_NONCONFLICT | self::F_AUTHOR)) {
	    $tables["PaperConflict"] = array("left join", "PaperConflict", "Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=$this->contactId");
	    $columns["conflictType"] = "PaperConflict.conflictType";
	}
	if ($this->needflags & self::F_REVIEWER) {
	    $qb = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qb = " or MyReview.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
	    $tables["MyReview"] = array("left join", "PaperReview", "Paper.paperId=MyReview.paperId and (MyReview.contactId=$this->contactId$qb)");
	    $columns["myReviewNeedsSubmit"] = "MyReview.reviewNeedsSubmit";
	}
	if ($this->needflags & self::F_PAPERNONBLIND)
	    $columns["paperBlind"] = "Paper.blind";

	// search contacts
	if (count($this->contactmatch)) {
	    $qa = "select ContactInfo.contactId";
	    $qb = " from ContactInfo"
		. ($this->contactmatchPC ? " join PCMember using (contactId)" : "")
		. " where ";
	    for ($i = 0; $i < count($this->contactmatch); ++$i) {
		$s = simplifyWhitespace($this->contactmatch[$i]);
		if (($pos = strpos($s, "@")) !== false)
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
	    else
		$q .= " " . $value[0] . " " . $value[1] . " as " . $tabname
		    . " on (" . $value[2] . ")";
	if (count($filters))
	    $q .= " where " . join(" and ", $filters);
	$q .= " group by Paper.paperId";

	// clean up contact matches
	if (count($this->contactmatch))
	    for ($i = 0; $i < count($this->contactmatch); $i++) {
		$carr = $this->contactmatch[$i];
		if (count($carr) == 0)
		    $c = "=-1";
		else if (count($carr) == 1)
		    $c = "=$carr[0]";
		else
		    $c = " in (" . join(",", $carr) . ")";
		$q = str_replace("\1$i\1", $c, $q);
	    }
	//$Conf->infoMsg(htmlspecialchars($q));

	// actually perform query
	if (!$Conf->qe("create temporary table Matches $q", "while performing search"))
	    return false;

	// correct query
	if ($this->preciseQuery) {
	    $delete = array();
	    $result = $Conf->qe("select * from Matches", "while performing search");
	    while (($row = edb_orow($result))) {
		foreach ($qc as &$c) {
		    $f = false;
		    foreach ($c as &$t)
			if (($f = $this->_clauseTermCheck($t, $row)))
			    break;
		    unset($t);
		    if (!$f) {
			$delete[] = $row->paperId;
			break;
		    }
		}
		unset($c);
	    }
	    if (count($delete))
		if (!$Conf->qe("delete from Matches where paperId in (" . join(",", $delete) . ")", "while performing search"))
		    return false;
	}

	// set matchPreg
	if (!$this->overrideMatchPreg) {
	    $this->matchPreg = array();
	    foreach (array("ti" => "title", "au" => "authorInformation",
			   "ab" => "abstract", "co" => "collaborators")
		     as $k => $v)
		if (isset($this->regex[$k]) && count($this->regex[$k]))
		    $this->matchPreg[$v] = join("|", $this->regex[$k]);
	}

	// set reviewerContact if the query is about exactly one reviewer
	if ($this->reviewerContact == 1) {
	    $this->reviewerContact = false;
	    foreach ($qc as &$c) {
		if (count($c) == 1 && $c[0]->type == "re") {
		    $v = $c[0]->value[0];
		    if ($v[0] == "=")
			$this->reviewerContact = (int) substr($v, 1);
		    else if ($v[0] == "\1") {
			$v = (int) substr($v, 1, strlen($v) - 2);
			if (count($this->contactmatch[$v]) == 1)
			    $this->reviewerContact = $this->contactmatch[$v][0];
		    }
		    break;
		}
	    }
	} else
	    $this->reviewerContact = false;

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
	return false;
    }

    function simplePaperList() {
	if (!$this->qo && !$this->qx && preg_match('/^\s*#?\d[-#\d\s]*$/s', $this->q)) {
	    $a = array();
	    foreach (preg_split('/\s+/', $this->q) as $word) {
		if ($word[0] == "#" && preg_match('/^#\d+(-#?\d+)?/', $word))
		    $word = substr($word, 1);
		if (ctype_digit($word))
		    $a[] = $word;
		else if (preg_match('/^(\d+)-#?(\d+)$/s', $word, $m)) {
		    for ($i = $m[1]; $i <= $m[2]; $i++)
			$a[] = $i;
		} else
		    return null;
	    }
	    return $a;
	} else if (!$this->qx && !$this->q && preg_match('/^\s*#?(\d+)\s*$/s', $this->qo, $m))
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
	    && $me->amDiscussionLead(0, $Conf))
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
