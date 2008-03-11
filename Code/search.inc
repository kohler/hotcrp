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
		    'sre' => 'sre', 'srev' => 'sre', 'sreview' => 'sre',
		    'cre' => 'sre', 'crev' => 'sre', 'creview' => 'sre',
		    'subre' => 'sre', 'subrev' => 'sre', 'subreview' => 'sre',
		    'pri' => 'pri', 'primary' => 'pri',
		    'cpri' => 'spri', 'cprimary' => 'spri',
		    'sec' => 'sec', 'secondary' => 'sec',
		    'csec' => 'ssec', 'csecondary' => 'ssec',
		    'cmt' => 'cmt', 'comment' => 'cmt',
		    'aucmt' => 'aucmt', 'aucomment' => 'aucmt',
		    'tag' => 'tag',
		    'notag' => 'notag',
		    'ord' => 'order', 'order' => 'order',
		    'decision' => 'decision', 'dec' => 'decision',
		    'topic' => 'topic',
		    'option' => 'option', 'opt' => 'option',
		    'lead' => 'lead',
		    'shepherd' => 'shepherd', 'shep' => 'shepherd',
		    'conflict' => 'conflict', 'conf' => 'conflict',
		    'status' => 'status');

class PaperSearch {

    const ANY_CONFLICT = 1;
    const ANY_YOURREVIEW = 2;

    var $contactId;
    var $privChair;
    var $amPC;
    var $limitName;
    var $allowAuthor;
    var $fields;
    var $any;
    var $orderTag;
    var $reviewerName;
    var $matchPreg;
    var $urlbase;
    var $warnings;
    
    var $q;
    var $qa;
    var $qx;

    var $filters;
    var $regex;
    var $match;
    var $needMatchcount;
    var $contactmatch;
    var $contactmatchPC;
    
    function PaperSearch($me, $opt) {
	global $Conf, $ConfSiteSuffix;

	// contact facts
	$this->privChair = $me->privChair;
	$this->amPC = $me->isPC;

	// paper selection
	$ptype = defval($opt, "t", 0);
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
			       || $ptype == "reqrevs"))
	    $this->limitName = $ptype;
	else if ($this->privChair && $ptype == "all")
	    $this->limitName = "all";
	else if ($this->privChair && $ptype == "unsub")
	    $this->limitName = "unsub";
	else if ($me->amReviewer() && (!$ptype || $ptype == "r"))
	    $this->limitName = "r";
	else if ($me->amReviewer() && $ptype == "rout")
	    $this->limitName = "rout";
	else if ($me->isAuthor && (!$ptype || $ptype == "a"))
	    $this->limitName = "a";
	else if ($me->isPC && $ptype == "req")
	    $this->limitName = "req";
	else
	    $this->limitName = "x";

	// track other information
	$this->allowAuthor = false;
	if ($me->privChair || $me->isAuthor
	    || ($this->amPC && $Conf->blindSubmission() <= 1))
	    $this->allowAuthor = true;
	$this->orderTag = false;
	$this->reviewerName = false;
	$this->any = 0;
	$this->contactId = $me->contactId;
	$this->warnings = null;
	if ($this->privChair && cvtint($opt["c"]) > 0)
	    $this->contactId = cvtint($opt["c"]);

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
	    $this->fields["tag"] = $this->fields["order"] = 1;

	// the query itself
	$this->q = trim(defval($opt, "q", ""));
	$this->qa = trim(defval($opt, "qa", ""));
	$this->qx = trim(defval($opt, "qx", ""));

	// URL base
	if (isset($opt["urlbase"]))
	    $this->urlbase = $opt["urlbase"];
	else {
	    $this->urlbase = "search$ConfSiteSuffix?t=" . urlencode($this->limitName);
	    if ($qtype != "n")
		$this->urlbase .= "&qt=" . urlencode($qtype);
	}
    }

    function _resetQuery() {
	$this->filters = array();
	$this->regex = array();
	$this->match = array();
	foreach (array("topic", "conflict", "option", "tag", "re", "cre",
		       "pri", "cpri", "sec", "csec", "twiddletag") as $m)
	    $this->match[$m] = array();
	$this->needMatchcount = array();
	$this->contactmatch = array();
	$this->contactmatchPC = true;
    }

    function warn($text) {
	if (!$this->warnings)
	    $this->warnings = array();
	$this->warnings[] = $text;
    }

    function _searchWordMatch($word, $mclass, $field,
			      $type, $negated, $confpart) {
	if ($word == "any" && ($type == "all" || $type == "any")
	    && !$negated && !$confpart)
	    $mi = "uany";
	else if ($word == "none" || $word == "any")
	    $mi = "univ";
	else
	    $mi = ($negated ? "not" : "") . $type;

	if (!isset($this->match[$mclass][$mi]))
	    $this->match[$mclass][$mi] = array();
	
	if ($mi == "uany") {
	    $this->match[$mclass][$mi][0] = "true";
	    return "";
	} if ($mi == "univ") {
	    $this->match[$mclass][$mi][0] = "true";
	    $this->needMatchcount["$mi$mclass"] = true;
	    if ($negated ? $word == "any" : $word == "none")
		return " or (${mi}${mclass}count is null$confpart)";
	    else
		return " or (${mi}${mclass}count is not null$confpart)";
	} else {
	    if (is_array($word) && count($word) == 1)
		$word = $word[0];
	    else if (is_array($word) && count($word) == 0)
		$word = 0;
	    if (is_array($word))
		$out = "$field in (" . join(",", $word) . ")";
	    else if (ctype_digit($word))
		$out = "$field=$word";
	    else if ($field == "contactId")
		$out = "$field$word";
	    else
		$out = "$field='" . sqlq($word) . "'";
	    $this->match[$mclass][$mi][] = $out;
	    if (($mi == "all" || $mi == "any") && $confpart == "")
		return "";
	    $this->needMatchcount["$mi$mclass"] = true;
	    $matchcount = ($mi == "any" ? 1 : count($this->match[$mclass][$mi]));
	    $qp = "(${mi}${mclass}count>=$matchcount$confpart)";
	    if ($negated)
		return " or not ifnull($qp, 0)";
	    else
		return " or $qp";
	}
    }

    function _searchReviewerNameConfpart() {
	global $Conf;
	if ($this->privChair)
	    return "";
	$au_seerev = ($Conf->setting("au_seerev") && $Conf->setting("rev_blind") > 0);
	if ($this->amPC) {
	    $pc_seerev = $Conf->setting("pc_seeallrev");
	    if ($pc_seerev == 0)
		$pc_seerev = $Conf->setting("pcrev_any");
	    $confpart = "";
	    if (!$pc_seerev) {
		$this->any |= self::ANY_YOURREVIEW;
		$confpart .= " and YourReview.reviewNeedsSubmit=0";
	    } else if ($au_seerev) {
		$this->any |= self::ANY_CONFLICT;
		$confpart .= " and conflictType is null";
	    }
	    return $confpart;
	} else
	    return ($au_seerev ? "" : false);
    }
    
    function _searchReviewer($lword, $mclass, $type) {
	global $Conf;
	if ($lword == "any")
	    $lword = ">0";
	else if ($lword == "none")
	    $lword = "=0";
	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $lword, $m)) {
	    $m[1] = ($m[1] ? $m[1] : "=");
	    if ($m[2] == 0 && $m[1] == "<")
		return " or false";
	    if ($m[2] == 0 && $m[1] == ">=")
		return " or true";
	    $confpart = "";
	    if (!$this->privChair && !$Conf->timeAuthorViewReviews()) {
		$this->any |= self::ANY_CONFLICT;
		$confpart = " and conflictType is null";
	    }
	    if ($m[2] == 0 && $m[1] == ">" && !$confpart)
		$mi = "uany";
	    else
		$mi = "univ";
	    if (!isset($this->match[$mclass][$mi]))
		$this->match[$mclass][$mi] = array();
	    $this->match[$mclass][$mi][0] = true;
	    if ($mi == "uany")
		return "";
	    $qp = "";
	    $this->needMatchcount["$mi$mclass"] = true;
	    if ($m[1][0] == "<" || ($m[2] == 0 && $m[1] == "="))
		$qp .= " or (${mi}${mclass}count is null$confpart)";
	    if ($m[2] != 0 || $m[1][0] == ">")
		$qp .= " or (${mi}${mclass}count$m[1]$m[2]$confpart)";
	    return $qp;
	} else if (!$this->amPC)
	    return " or false";
	else {
	    $confpart = $this->_searchReviewerNameConfpart();
	    if ($type != "none" && $this->privChair)
		$this->reviewerName = ($this->reviewerName === false ? $lword : null);
	    $this->contactmatch[] = $lword;
	    $this->contactmatchPC = false;
	    $x = "\1" . (count($this->contactmatch) - 1) . "\1";
	    return $this->_searchWordMatch($x, $mclass, "contactId",
					   $type, null, $confpart);
	}
    }

    function _searchComment($word, $type, $field) {
	global $Conf;
	if ($word == "")
	    $word = ">0";
	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $word, $m)) {
	    $m[1] = ($m[1] ? $m[1] : "=");
	    if ($m[2] == 0 && $m[1] == "<")
		return " or false";
	    if ($m[2] == 0 && $m[1] == ">=")
		return " or true";
	    $column = ($field == "aucmt" ? "numAuthorComments" : "numComments");
	    if ($this->privChair)
		$value = $column;
	    else if ($this->amPC) {
		$this->any |= self::ANY_CONFLICT;
		if ($Conf->timeAuthorViewReviews() && $field == "aucmt")
		    $value = "numAuthorComments";
		else if ($Conf->timeAuthorViewReviews())
		    $value = "if(conflictType,numAuthorComments,numComments)";
		else
		    $value = "if(conflictType,0,$column)";
	    } else if ($Conf->timeAuthorViewReviews())
		$value = "numAuthorComments";
	    else
		$value = "0";
	    return " or ($value" . $m[1] . $m[2] . ")";
	} else
	    return " or false";
    }

    function _searchQueryWord($word, $type, &$qpapers) {
	global $searchTags, $Conf;

	// check for paper number
	if (ctype_digit($word)) {
	    $qpapers[] = "Paper.paperId=$word";
	    return "";
	} else if (preg_match('/^(\d+)-(\d+)$/i', $word, $m)) {
	    $qpapers[] = "Paper.paperId between $m[1] and $m[2]";
	    return "";
	}

	$auextra = "";
	if (!$this->privChair && $Conf->blindSubmission() >= 1)
	    $auextra = "Paper.blind=0 and ";
	
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
	}

	if ($word[0] == '"')
	    $word = str_replace('"', '', $word);

	$lword = sqlq_for_like($word);
	$mword = $qp = "";
	$any = false;
	if ($special ? $special == "ti" : isset($this->fields['ti'])) {
	    $any = true;
	    $qp .= " or (title like '%$lword%')";
	    $mword = $word;
	}
	if ($special ? $special == "ab" : isset($this->fields['ab'])) {
	    $any = true;
	    $qp .= " or (abstract like '%$lword%')";
	    $mword = $word;
	}
	if (($special ? $special == "au" : isset($this->fields['au']))
	    && $this->allowAuthor) {
	    $any = true;
	    $qp .= " or (${auextra}authorInformation like '%$lword%')";
	    $mword = $word;
	}
	if (($special ? $special == "co" : isset($this->fields['co']))
	    && $this->privChair) {
	    $any = true;
	    $qp .= " or (${auextra}Paper.collaborators like '%$lword%')";
	    $mword = $word;
	}
	foreach (array("re", "sre", "pri", "spri", "sec", "ssec") as $rtype)
	    if ($special ? $special == $rtype : isset($this->fields[$rtype])) {
		$any = true;
		$qp .= $this->_searchReviewer($lword, $rtype, $type);
	    }
	if ($special ? $special == "cmt" : isset($this->fields['cmt'])) {
	    $any = true;
	    $qp .= $this->_searchComment($lword, $type, "cmt");
	}
	if ($special ? $special == "aucmt" : isset($this->fields['aucmt'])) {
	    $any = true;
	    $qp .= $this->_searchComment($lword, $type, "aucmt");
	}
	if ((($special ? $special == "tag" : isset($this->fields['tag']))
	     || ($special ? $special == "order" : isset($this->fields['order']))
	     || ($special ? $special == "notag" : isset($this->fields['notag'])))
	    && $this->amPC) {
	    require_once("tags.inc");
	    $any = true;
 	    if (($e = checkTagError($word, true)))
		$this->warn(htmlspecialchars($e));
	    if (($roundtags = $Conf->settingText("tag_rounds", ""))
		&& strpos($roundtags, " $word ") !== false)
		$word = "~$word";
	    $confpart = "";
	    if ($word && $word[0] == "~")
		$word = $this->contactId . $word;
	    else if (!$this->privChair && $Conf->setting("tag_seeall") <= 0) {
		$this->any |= self::ANY_CONFLICT;
		$confpart = " and conflictType is null";
	    }
	    if (($special ? $special == "order" : isset($this->fields['order']))
		&& $type != "none")
		$this->orderTag = ($this->orderTag === false ? $word : null);
	    $negated = ($special ? $special == "notag" : isset($this->fields['notag']));
	    $qp .= $this->_searchWordMatch($word, "tag", "tag",
					   $type, $negated, $confpart);
	}
	if ($special ? $special == "status" : isset($this->fields['status'])) {
	    $any = true;
	    if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
		$qp .= " or (timeWithdrawn>0)";
	    else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
		$qp .= " or (timeSubmitted>0)";
	    else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
		$qp .= " or (timeSubmitted<=0 and timeWithdrawn<=0)";
	    else
		$this->warn("Valid search statuses are 'withdrawn', 'submitted', and 'unsubmitted'.");
	}
	if (($special ? $special == "decision" : isset($this->fields['decision']))
	    && ($this->amPC || $Conf->timeAuthorViewDecision())) {
	    $any = true;
	    $confpart = "";
	    if (!$this->amPC && !$Conf->timeAuthorViewDecision()) {
		$this->any |= self::ANY_CONFLICT;
		$confpart = " and conflictType is null";
	    }
	    if (strcasecmp($word, "yes") == 0)
		$qp .= " or (outcome>0$confpart)";
	    else if (strcasecmp($word, "no") == 0)
		$qp .= " or (outcome<0$confpart)";
	    else if ($word == "?")
		$qp .= " or (outcome=0$confpart)";
	    else {
		$rf = reviewForm();
		$any = false;
		foreach ($rf->options['outcome'] as $num => $what)
		    if (strcmp($word, $num) == 0 || strcasecmp($word, $what) == 0) {
			$qp .= " or (outcome=$num$confpart)";
			$any = true;
			break;
		    }
		if (!$any)
		    foreach ($rf->options['outcome'] as $num => $what)
			if (strstr(strtolower($what), strtolower($word)) !== false) {
			    $qp .= " or (outcome=$num$confpart)";
			    $any = true;
			}
		if (!$any)
		    $qp .= " or false";
	    }
	}
	if (($special ? $special == "topic" : isset($this->fields['topic']))) {
	    $any = true;
	    if ($word == "none" || $word == "any")
		$qp .= $this->_searchWordMatch($word, "topic", "topicId",
					       $type, null, false);
	    else {
		$rf = reviewForm();
		$x = strtolower(simplifyWhitespace($word));
		$tids = array();
		foreach ($rf->topicName as $tid => $n)
		    if (strstr(strtolower($n), $x) !== false)
			$tids[] = $tid;
		if (count($tids) == 0 && $word != "none" && $word != "any") {
		    $this->warn("&ldquo;" . htmlspecialchars($x) . "&rdquo; does not match any defined paper topic.");
		    $qp .= " or false";
		} else
		    $qp .= $this->_searchWordMatch($tids, "topic", "topicId",
						   $type, null, false);
	    }
	}
	if (($special ? $special == "option" : isset($this->fields['option']))) {
	    $any = true;
	    if ($word == "none" || $word == "any")
		$qp .= $this->_searchWordMatch($word, "option", "optionId",
					       $type, null, false);
	    else {
		$opt = paperOptions();
		$x = strtolower(simplifyWhitespace($word));
		$oids = array();
		foreach ($opt as $oid => $o)
		    if (strstr(strtolower($o->optionName), $x) !== false)
			$oids[] = $oid;
		if (count($oids) == 0) {
		    $this->warn("&ldquo;" . htmlspecialchars($x) . "&rdquo; does not match any defined submission option.");
		    $qp .= " or false";
		} else
		    $qp .= $this->_searchWordMatch($oids, "option", "optionId",
						   $type, null, false);
	    }
	}
	if (($special ? $special == "lead" : isset($this->fields['lead']))
	    && $this->amPC) {
	    $any = true;
	    if ($word == "none")
		$qp .= " or (leadContactId=0)";
	    else if ($word == "any")
		$qp .= " or (leadContactId!=0)";
	    else {
		$this->contactmatch[] = $lword;
		$qp .= " or (leadContactId\1" . (count($this->contactmatch) - 1) . "\1)";
	    }
	}
	if (($special ? $special == "shepherd" : isset($this->fields['shepherd']))
	    && ($this->amPC || $Conf->timeAuthorViewDecision())) {
	    $any = true;
	    if ($word == "none")
		$qp .= " or (shepherdContactId=0)";
	    else if ($word == "any")
		$qp .= " or (shepherdContactId!=0)";
	    else {
		$this->contactmatch[] = $lword;
		$qp .= " or (shepherdContactId\1" . (count($this->contactmatch) - 1) . "\1)";
	    }
	}
	if (($special ? $special == "conflict" : isset($this->fields['conflict']))
	    && $this->privChair) {
	    $any = true;
	    if ($word == "none" || $word == "any")
		$x = $word;
	    else {
		$this->contactmatch[] = $lword;
		$this->contactmatchPC = false;
		$x = "\1" . (count($this->contactmatch) - 1) . "\1";
	    }
	    $qp .= $this->_searchWordMatch($x, "conflict", "contactId",
					   $type, null, false);
	}
	if (!$any && $special && $this->amPC
	    && ($this->privChair
		|| (($roundtags = $Conf->settingText("tag_rounds", ""))
		    && strpos($roundtags, " $special ") !== false))) {
	    $any = true;
	    $confpart = $this->_searchReviewerNameConfpart();
	    $this->contactmatch[] = $lword;
	    $qp .= $this->_searchWordMatch((count($this->contactmatch) - 1) . "~$special", "twiddletag", "tag",
					   $type, null, $confpart);
	}
	if (!$any)
	    $qp .= " or false";

	if ($mword && $type != "none")
	    $this->regex[] = preg_quote($mword);
	return ($qp ? "(" . substr($qp, 4) . ")" : "");
    }
    
    function _searchQueryType($str, $type) {
	global $Conf;

	// quick emptiness check
	if ($str == "")
	    return;
	
	$auextra = "";
	if (!$this->privChair && $Conf->blindSubmission() >= 1)
	    $auextra = "Paper.blind=0 and ";
	$qpapers = array();
	$qnorm = array();
	$n = 0;
	
	while ($str != "") {
	    preg_match('/^("[^"]+"?|[a-zA-Z][a-zA-Z0-9]*:"[^"]+"?|[^"\s]+)/s', $str, $m);
	    $word = $m[0];
	    $str = ltrim(substr($str, strlen($word)));
	    if ($n == 0 && $str == "" && $type == "any")
		$type = "all";
	    ++$n;

	    if (($qp = $this->_searchQueryWord($word, $type, $qpapers)))
		$qnorm[] = $qp;
	}

	if (count($qpapers))
	    $qnorm[] = join(" or ", $qpapers);
	
	if ($type == "any" && count($qnorm) > 1)
	    $qnorm = array("(" . join(" or ", $qnorm) . ")");

	if ($type == "any" || $type == "all") {
	    foreach ($qnorm as $qp)
		$this->filters[] = $qp;
	} else {
	    foreach ($qnorm as $qp)
		$this->filters[] = "not ifnull($qp, 0)";
	}
    }
    
    function _tableMatch($table, $column, $fieldabbr, $andclause, $type, &$tarr) {
	global $Conf;
	$confpart = isset($this->needMatchcount["$type$fieldabbr"]);
	$modtable = "${table}_$type$fieldabbr";
	$xandclause = str_replace("*", "$modtable.", $andclause);
	$andclause = str_replace("*", "", $andclause);
	if ($type == "uany"
	    || ($type == "all" && count($tarr) == 1 && !$confpart))
	    return " join $table as $modtable on ($modtable.paperId=Paper.paperId and $modtable.$tarr[0]$xandclause)";
	else if (($type == "all" || $type == "any") && count($tarr) == 0 && !$confpart)
	    return " join $table as $modtable on (false)";
	else if (count($tarr) == 0)
	    return " left join $table as $modtable on (false)";
	else if ($type == "all" && !$confpart)
	    return " join (select paperId, count($column) as ${type}${fieldabbr}count from $table where (" . join(" or ", $tarr) . ")$andclause group by paperId) as $modtable on ($modtable.paperId=Paper.paperId and $modtable.${type}${fieldabbr}count=" . count($tarr) . ")";
	else if ($type == "any" && !$confpart)
	    return " join $table as $modtable on ($modtable.paperId=Paper.paperId and ($modtable." . join(" or $modtable.", $tarr) . ")$xandclause)";
	else
	    return " left join (select paperId, count($column) as ${type}${fieldabbr}count from $table where (" . join(" or ", $tarr) . ")$andclause group by paperId) as $modtable on ($modtable.paperId=Paper.paperId)";
    }
    
    function _searchQuery() {
	global $Conf;
	
	if ($this->limitName == "x")
	    return array("create temporary table Matches select Paper.paperId from Paper where false", "");
	$this->_resetQuery();

	// query parts
	$this->_searchQueryType($this->q, "any");
	$this->_searchQueryType($this->qa, "all");
	$this->_searchQueryType($this->qx, "none");

	// status limitation parts
	if ($this->limitName == "s" || $this->limitName == "r"
	    || $this->limitName == "req" || $this->limitName == "revs"
	    || $this->limitName == "acc" || $this->limitName == "und")
	    $this->filters[] = "Paper.timeSubmitted>0";
	else if ($this->limitName == "act")
	    $this->filters[] = "Paper.timeWithdrawn<=0";
	else if ($this->limitName == "unsub")
	    $this->filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";

	// decision limitation parts
	if ($this->limitName == "acc")
	    $this->filters[] = "Paper.outcome>0";
	else if ($this->limitName == "und")
	    $this->filters[] = "Paper.outcome=0";

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
		else if (preg_match('/\A(.*?)\s*([,\s])\s*(.*)\Z/', $s, $m)) {
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
	$q = "select Paper.paperId from Paper";
	if ($this->limitName == "r")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . ")";
	else if ($this->limitName == "rout")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.reviewNeedsSubmit!=0)";
	else if ($this->limitName == "a")
	    $q .= " join PaperConflict as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.conflictType>=" . CONFLICT_AUTHOR . ")";
	else if ($this->limitName == "req")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.requestedBy=" . $this->contactId . " and Limiter.reviewType=" . REVIEW_EXTERNAL . ")";
	
	if (($this->any & self::ANY_CONFLICT) && $this->limitName != "a")
	    $q .= " left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=" . $this->contactId . ")";
	if (($this->any & self::ANY_YOURREVIEW))
	    $q .= " left join PaperReview as YourReview on (YourReview.paperId=Paper.paperId and YourReview.contactId=" . $this->contactId . ")";

	foreach ($this->match["tag"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperTag", "tag", "tag", "", $t, $tarr);
	foreach ($this->match["conflict"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperConflict", "contactId", "conflict", " and *conflictType>0", $t, $tarr);
	foreach ($this->match["topic"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperTopic", "topicId", "topic", "", $t, $tarr);
	foreach ($this->match["option"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperOption", "optionId", "option", "", $t, $tarr);
	
	foreach ($this->match["re"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "re", "", $t, $tarr);
	foreach ($this->match["cre"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "cre", " and *reviewSubmitted>0", $t, $tarr);
	foreach ($this->match["pri"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "pri", " and *reviewType=" . REVIEW_PRIMARY, $t, $tarr);
	foreach ($this->match["cpri"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "cpri", " and *reviewType=" . REVIEW_PRIMARY . " and *reviewSubmitted>0", $t, $tarr);
	foreach ($this->match["sec"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "sec", " and *reviewType=" . REVIEW_SECONDARY, $t, $tarr);
	foreach ($this->match["csec"] as $t => $tarr)
	    $q .= $this->_tableMatch("PaperReview", "reviewId", "csec", " and *reviewType=" . REVIEW_SECONDARY . " and *reviewSubmitted>0", $t, $tarr);
	foreach ($this->match["twiddletag"] as $t => $tarr) {
	    $xarr = array();
	    foreach ($tarr as $tx) {
		preg_match("/^tag=\'(\\d+)(~\\S+)'$/", $tx, $m);
		foreach ($this->contactmatch[$m[1]] as $c)
		    $xarr[] = "tag='$c$m[2]'";
	    }
	    $q .= $this->_tableMatch("PaperTag", "tag", "twiddletag", "", $t, $xarr);
	}

	if (count($this->filters))
	    $q .= " where " . join(" and ", $this->filters);

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

	return array("create temporary table Matches $q group by Paper.paperId", join("|", $this->regex));
    }

    function complexSearch(&$queryOptions) {
	if ($this->q || $this->qa || $this->qx)
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
	} else if ($this->limitName == "r") {
	    $queryOptions["finalized"] = 1;
	    $queryOptions["myReviews"] = 1;
	} else if ($this->limitName == "rout") {
	    $queryOptions["finalized"] = 1;
	    $queryOptions["myOutstandingReviews"] = 1;
	} else if ($this->limitName == "a")
	    $queryOptions["author"] = 1;
	else if ($this->limitName == "req" || $this->limitName == "reqrevs")
	    $queryOptions["myReviewRequests"] = 1;
	else if ($this->limitName == "act")
	    $queryOptions["active"] = 1;
	return false;
    }

    function simplePaperList() {
	if (!$this->qa && !$this->qx && preg_match('/^\s*\d[-\d\s]*$/s', $this->q)) {
	    $a = array();
	    foreach (preg_split('/\s+/', $this->q) as $word)
		if (ctype_digit($word))
		    $a[] = $word;
		else if (preg_match('/^(\d+)-(\d+)$/s', $word, $m)) {
		    for ($i = $m[1]; $i <= $m[2]; $i++)
			$a[] = $i;
		} else
		    return null;
	    return $a;
	} else if (!$this->qx && !$this->q && preg_match('/^\s*\d+\s*$/s', $this->qa))
	    return array(trim($this->qa));
	else
	    return null;
    }

    function search() {
	global $Conf;
	$x = $this->_searchQuery();
	$this->matchPreg = $x[1];
	//$Conf->infoMsg(htmlspecialchars($x[0]));
	return $Conf->qe($x[0], "while searching papers");
    }

    function paperList() {
	global $Conf;
	$x = $this->_searchQuery();
	$this->matchPreg = $x[1];
	$result = $Conf->qe($x[0], "while searching papers");
	if (!$result)
	    return array();
	$x = array();
	$result = $Conf->qe("select * from Matches", "while searching papers");
	while (($row = edb_row($result)))
	    $x[] = $row[0];
	$Conf->q("drop temporary table Matches");
	return $x;
    }

    function url() {
	$url = $this->urlbase;
	if (substr($this->urlbase, 0, 6) == "search" || $this->q != "")
	    $url .= "&q=" . urlencode($this->q);
	if ($this->qa != "")
	    $url .= "&qa=" . urlencode($this->qa);
	if ($this->qx != "")
	    $url .= "&qx=" . urlencode($this->qx);
	return $url;
    }

    function _tagDescription() {
	if (($this->q == "" ? 0 : 1) + ($this->qa == "" ? 0 : 1)
	    + ($this->qx == "" ? 0 : 1) != 1)
	    return false;
	$d = trim($this->q == "" ? ($this->qa == "" ? $this->qx : $this->qa) : $this->q);
	if (substr($d, 0, 4) == "tag:")
	    $t = substr($d, 4);
	else if (substr($d, 0, 6) == "notag:" || substr($d, 0, 6) == "order:")
	    $t = substr($d, 6);
	else
	    return false;
	require_once("tags.inc");
	if (!checkTag($t, false))
	    return false;
	if ($d[0] == "o" && !$this->qx)
	    return $d;
	else
	    return (($d[0] == "n") == ($this->qx == "") ? "notag:" : "tag:") . $t;
    }
    
    function description($listname) {
	require_once("paperlist.inc");
	$desc = PaperList::listDescription($listname);
	if ($this->q == "" && $this->qa == "" && $this->qx == "")
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
	if ($this->q == "" && $this->qa == "" && $this->qx == "")
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
	    $l["matchPreg"] = "/(" . $this->matchPreg . ")/i";
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
    
}
