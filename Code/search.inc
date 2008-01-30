<?php
// search.inc -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $searchTags;
$searchTags = array('ti:' => 'ti', 'title:' => 'ti',
		    'ab:' => 'ab', 'abstract:' => 'ab',
		    'au:' => 'au', 'author:' => 'au',
		    'co:' => 'co', 'collab:' => 'co', 'collaborators:' => 'co',
		    're:' => 're', 'rev:' => 're', 'review:' => 're',
		    'sre:' => 'sre', 'srev:' => 'sre', 'sreview:' => 'sre',
		    'cre:' => 'sre', 'crev:' => 'sre', 'creview:' => 'sre',
		    'subre:' => 'sre', 'subrev:' => 'sre', 'subreview:' => 'sre',
		    'pri:' => 'pri', 'primary:' => 'pri',
		    'cpri:' => 'spri', 'cprimary:' => 'spri',
		    'sec:' => 'sec', 'secondary:' => 'sec',
		    'csec:' => 'ssec', 'csecondary:' => 'ssec',
		    'cmt:' => 'cmt', 'comment:' => 'cmt',
		    'aucmt:' => 'aucmt', 'aucomment:' => 'aucmt',
		    'tag:' => 'tag',
		    'notag:' => 'notag',
		    'ord:' => 'order', 'order:' => 'order',
		    'decision:' => 'decision', 'dec:' => 'decision',
		    'topic:' => 'topic',
		    'option:' => 'option', 'opt:' => 'option',
		    'lead:' => 'lead',
		    'shepherd:' => 'shepherd', 'shep:' => 'shepherd',
		    'conflict:' => 'conflict', 'conf:' => 'conflict',
		    'status:' => 'status');

class PaperSearch {

    const ANY_REVIEWER = 1;
    const ANY_SUB_REVIEWER = 2;		// must be 2*ANY_REVIEWER
    const ANY_NUM_REVIEW = 4;
    const ANY_SUB_NUM_REVIEW = 8;	// must be 2*ANY_NUM_REVIEW
    const ANY_PRIMARY = 16;		// 16 * ANY_REVIEWER series
    const ANY_SUB_PRIMARY = 32;
    const ANY_NUM_PRIMARY = 64;
    const ANY_SUB_NUM_PRIMARY = 128;
    const ANY_SECONDARY = 256;		// 256 * ANY_REVIEWER series
    const ANY_SUB_SECONDARY = 512;
    const ANY_NUM_SECONDARY = 1024;
    const ANY_SUB_NUM_SECONDARY = 2048;
    const ANY_TAG = 4096;
    const ANY_ALLTAGS = 8192;
    const ANY_TOPICS = 16384;
    const ANY_LEAD = 32768;
    const ANY_SHEPHERD = 65536;
    const ANY_CONFLICT = 131072;
    const ANY_OPTIONS = 262144;
    const ANY_CONFLICTINFO = 524288;

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
    
    function PaperSearch($me, $opt) {
	global $Conf, $ConfSiteSuffix;

	$this->privChair = $me->privChair;
	$this->amPC = $me->isPC;
	$this->allowAuthor = ($this->amPC && ($Conf->blindSubmission() <= 1 || $me->privChair));
	$this->orderTag = false;
	$this->reviewerName = false;
	$this->any = 0;
	$this->contactId = $me->contactId;
	$this->warnings = null;
	if ($this->privChair && cvtint($opt["c"]) > 0)
	    $this->contactId = cvtint($opt["c"]);

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

	// query type
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

    function warn($text) {
	if (!$this->warnings)
	    $this->warnings = array();
	$this->warnings[] = $text;
    }

    function _searchReviewSuffixFactor($submitted, $type, &$suffix, &$factor) {
	$factor = ($submitted ? 2 : 1);
	$suffix = ($submitted ? "Sub" : "");
	if ($type == REVIEW_PRIMARY) {
	    $factor *= 16;
	    $suffix .= "Primary";
	} else if ($type == REVIEW_SECONDARY) {
	    $factor *= 256;
	    $suffix .= "Secondary";
	}
    }
    
    function _searchReviewer($word, $type, $submitted, $what = null) {
	global $Conf;
	$this->_searchReviewSuffixFactor($submitted, $what, $sub, $anyfac);
	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $word, $m)) {
	    $m[1] = ($m[1] ? $m[1] : "=");
	    if ($m[2] == 0 && $m[1] == "<")
		return " or false";
	    if ($m[2] == 0 && $m[1] == ">=")
		return " or true";
	    $this->any |= self::ANY_NUM_REVIEW * $anyfac;
	    $and = "";
	    if (!$this->privChair && !$Conf->timeAuthorViewReviews()) {
		$this->any |= self::ANY_CONFLICT;
		$and = " and conflictType is null";
	    }
	    $q = "";
	    if ($m[1][0] == "<" || ($m[2] == 0 && $m[1] == "="))
		$q .= " or (numReviews$sub is null$and)";
	    if ($m[2] != 0 || $m[1][1] == ">")
		$q .= " or (numReviews$sub" . $m[1] . $m[2] . "$and)";
	    return $q;
	} else if (!$this->amPC)
	    return "";
	else {
	    $this->any |= self::ANY_REVIEWER * $anyfac;
	    $and = "";
	    if (!$this->privChair) {
		$this->any |= self::ANY_CONFLICT;
		$and = " and conflictType is null";
	    }
	    if ($type != "none") {
		$this->reviewerName = ($this->reviewerName === false ? $word : null);
		return " or (reviewerInfo$sub like '%$word%'$and)";
	    } else
		return " or (reviewerInfo$sub is not null and reviewerInfo$sub like '%$word%'$and)";
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
	    if ($field == "aucmt" || !$this->privChair)
		$value = "numAuthorComments";
	    else if ($this->privChair)
		$value = "numComments";
	    else {
		$this->any |= self::ANY_CONFLICT;
		if ($Conf->timeAuthorViewReviews())
		    $value = "if(conflictType,numAuthorComments,numComments)";
		else
		    $value = "if(conflictType,0,numComments)";
	    }
	    return " or ($value" . $m[1] . $m[2] . ")";
	} else
	    return "";
    }

    function _searchQueryType($str, $type = "any") {
	global $searchTags, $Conf;
	$q = "";

	// quick emptiness check
	if ($str == "")
	    return array("", "");

	// quick paper IDs check
	if (($type == "any" && preg_match('/^\s*\d[\d\s]*$/s', $str))
	    || ($type == "all" && preg_match('/^\s*\d+\s*$/s', $str))) {
	    foreach (preg_split('/\s+/', $str) as $word)
		if ($word != "")
		    $q .= "Paper.paperId=$word or ";
	    return array(substr($q, 0, strlen($q) - 4), "");
	}

	// construct query portion
	$re = "";
	$auextra = "";
	if (!$this->privChair && $Conf->blindSubmission() >= 1)
	    $auextra = "blind=0 and ";
	$qjoin = ($type == "all" ? " and " : " or ");
	$qpapers = "";
	while (($str = ltrim($str)) != "") {
	    preg_match('/^("[^"]+"?|[a-z]+:"[^"]+"?|[^"\s]+)/s', $str, $m);
	    $word = $m[0];
	    $str = substr($str, strlen($word));

	    // check for paper number
	    if (ctype_digit($word)) {
		$qpapers .= " or Paper.paperId=$word";
		continue;
	    } else if (preg_match('/^(\d+)-(\d+)$/i', $word, $m)) {
		for ($i = $m[1]; $i <= $m[2]; $i++)
		    $qpapers .= " or Paper.paperId=$i";
		continue;
	    }
	    
	    $special = null;
	    if (($colon = strpos($word, ':')) !== false) {
		$x = substr($word, 0, $colon + 1);
		if (isset($searchTags[$x])) {
		    $special = $searchTags[$x];
		    $word = substr($word, $colon + 1);
		}
	    }

	    if ($word[0] == '"')
		$word = str_replace('"', '', $word);

	    $lword = sqlq_for_like($word);
	    $mword = $qp = "";
	    if ($special ? $special == "ti" : isset($this->fields['ti'])) {
		$qp .= " or (title like '%$lword%')";
		$mword = $word;
	    }
	    if ($special ? $special == "ab" : isset($this->fields['ab'])) {
		$qp .= " or (abstract like '%$lword%')";
		$mword = $word;
	    }
	    if (($special ? $special == "au" : isset($this->fields['au'])) && $this->allowAuthor) {
		$qp .= " or (${auextra}authorInformation like '%$lword%')";
		$mword = $word;
	    }
	    if (($special ? $special == "co" : isset($this->fields['co'])) && $this->privChair) {
		$qp .= " or (${auextra}Paper.collaborators like '%$lword%')";
		$mword = $word;
	    }
	    if ($special ? $special == "re" : isset($this->fields['re']))
		$qp .= $this->_searchReviewer($lword, $type, false);
	    if ($special ? $special == "sre" : isset($this->fields['sre']))
		$qp .= $this->_searchReviewer($lword, $type, true);
	    if ($special ? $special == "pri" : isset($this->fields['pri']))
		$qp .= $this->_searchReviewer($lword, $type, false, REVIEW_PRIMARY);
	    if ($special ? $special == "spri" : isset($this->fields['spri']))
		$qp .= $this->_searchReviewer($lword, $type, true, REVIEW_PRIMARY);
	    if ($special ? $special == "sec" : isset($this->fields['sec']))
		$qp .= $this->_searchReviewer($lword, $type, false, REVIEW_SECONDARY);
	    if ($special ? $special == "ssec" : isset($this->fields['ssec']))
		$qp .= $this->_searchReviewer($lword, $type, true, REVIEW_SECONDARY);
	    if ($special ? $special == "cmt" : isset($this->fields['cmt']))
		$qp .= $this->_searchComment($lword, $type, "cmt");
	    if ($special ? $special == "aucmt" : isset($this->fields['aucmt']))
		$qp .= $this->_searchComment($lword, $type, "aucmt");
	    if ((($special ? $special == "tag" : isset($this->fields['tag']))
		 || ($special ? $special == "order" : isset($this->fields['order'])))
		&& $this->amPC) {
		require_once("tags.inc");
		$confpart = "";
		if (!$this->privChair && $Conf->setting("tag_seeall") <= 0) {
		    $confpart = " and conflictType is null";
		    $this->any |= self::ANY_CONFLICT;
		}
		if (($e = checkTagError($word)))
		    $this->warn(htmlspecialchars($e));
		if ($word && $word[0] == "~") {
		    $word = $this->contactId . $word;
		    $lword = $this->contactId . $lword;
		}
		if (($special ? $special == "order" : isset($this->fields['order']))
		    && $type != "none")
		    $this->orderTag = ($this->orderTag === false ? $word : null);
		if ($word == "none") {
		    $qp .= " or (alltags is null$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		} else if ($type == "any") {
		    $qp .= " or (tag='" . sqlq($word) . "'$confpart)";
		    $this->any |= self::ANY_TAG;
		} else if ($type == "all") {
		    $qp .= " or (alltags like '%#$lword#%'$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		} else {
		    $qp .= " or (alltags is not null and alltags like '%#$lword#%'$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		}
	    }
	    if (($special ? $special == "notag" : isset($this->fields['notag']))
		&& $this->amPC) {
		require_once("tags.inc");
		$confpart = "";
		if (!$this->privChair && $Conf->setting("tag_seeall") <= 0) {
		    $confpart = " or conflictType is not null";
		    $this->any |= self::ANY_CONFLICT;
		}
		if (($e = checkTagError($word)))
		    $this->warn(htmlspecialchars($e));
		if ($lword && $lword[0] == "~")
		    $lword = $this->contactId . $lword;
		$this->any |= self::ANY_ALLTAGS;
		if ($word == "none")
		    $qp .= " or (alltags is not null$confpart)";
		else
		    $qp .= " or (alltags is null or alltags not like '%#$lword#%'$confpart)";
	    }
	    if ($special ? $special == "status" : isset($this->fields['status'])) {
		if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
		    $qp .= " or (timeWithdrawn>0)";
		else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
		    $qp .= " or (timeSubmitted>0)";
		else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
		    $qp .= " or (timeSubmitted<=0 and timeWithdrawn<=0)";
		else
		    $this->warn("Valid search statuses are 'withdrawn', 'submitted', and 'unsubmitted'.");
	    }
	    if (($special ? $special == "decision" : isset($this->fields['decision'])) && $this->amPC) {
		$confpart = "";
		//if (!$this->privChair && !$Conf->timeAuthorViewDecision())
		//    $confpart = " and conflictType=0";
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
		$rf = reviewForm();
		$x = strtolower($word);
		$got = false;
		foreach ($rf->topicName as $tid => $n)
		    if (strstr(strtolower($n), $x) !== false) {
			$qp .= " or (topicIds like '%#$tid#%')";
			$got = true;
		    }
		if ($got)
		    $this->any |= self::ANY_TOPICS;
		else
		    $this->warn("'" . htmlspecialchars($x) . "' does not match any defined paper topic.");
	    }
	    if (($special ? $special == "option" : isset($this->fields['option']))) {
		$opt = paperOptions();
		$x = strtolower($word);
		$got = false;
		foreach ($opt as $oid => $o)
		    if (strstr(strtolower($o->optionName), $x) !== false) {
			$qp .= " or (optionIds like '%#${oid}:%')";
			$got = true;
		    }
		if ($type == "none")
		    $qp = " or (optionIds is not null and (" . substr($qp, 4) . "))";
		if ($got)
		    $this->any |= self::ANY_OPTIONS;
		else
		    $this->warn("'" . htmlspecialchars($x) . "' does not match any defined submission option.");
	    }
	    if (($special ? $special == "lead" : isset($this->fields['lead'])) && $this->amPC) {
		$this->any |= self::ANY_LEAD;
		if ($word == "none")
		    $qp .= " or (leadInfo is null)";
		else if ($word == "any")
		    $qp .= " or (leadInfo is not null)";
		else if ($type != "none")
		    $qp .= " or (leadInfo like '%$lword%')";
		else
		    $qp .= " or (leadInfo is not null and leadInfo like '%$lword%')";
	    }
	    if ($special ? $special == "shepherd" : isset($this->fields['shepherd'])) {
		$this->any |= self::ANY_SHEPHERD;
		if ($word == "none")
		    $qp .= " or (shepherdInfo is null)";
		else if ($word == "any")
		    $qp .= " or (shepherdInfo is not null)";
		else if ($type != "none")
		    $qp .= " or (shepherdInfo like '%$lword%')";
		else
		    $qp .= " or (shepherdInfo is not null and shepherdInfo like '%$lword%')";
	    }
	    if ($special ? $special == "conflict" : isset($this->fields['conflict'])) {
		$this->any |= self::ANY_CONFLICTINFO;
		if ($type != "none")
		    $qp .= " or (conflictInfo like '%$lword%')";
		else
		    $qp .= " or (conflictInfo is not null and conflictInfo like '%$lword%')";
	    }
	    
	    if ($qp)
		$q .= $qjoin . "(" . substr($qp, 4) . ")";
	    if ($mword)
		$re .= ($re == "" ? "" : "|") . preg_quote($mword);
	}

	// construct table
	if (!$q && !$qpapers)
	    return array("true", "");

	if ($qpapers)
	    $q = "(" . substr($qpapers, 4) . ")" . $q;
	else
	    $q = substr($q, strlen($qjoin));
	return array(($type == "none" ? "not ($q)" : $q), $re);
    }
    
    function _combineQuery($res, $qr) {
	if ($qr[0] == "" || $qr[0] == "true")
	    return $res;
	else if ($res[0] == "" || $res[0] == "true")
	    return $qr;
	else {
	    $rr = ($qr[1] ? "$res[1]|$qr[1]" : $res[1]);
	    return array("($res[0]) and ($qr[0])", $rr);
	}
    }
    
    function _searchReviewerLimiter($submitted, $type) {
	$this->_searchReviewSuffixFactor($submitted, $type, $suffix, $fac);
	$q = " left join (select paperId";
	if ($this->any & (self::ANY_REVIEWER * $fac))
	    $q .= ", group_concat(firstName, ' ', lastName, ' ', email) as reviewerInfo$suffix";
	if ($this->any & (self::ANY_NUM_REVIEW * $fac))
	    $q .= ", count(reviewId) as numReviews$suffix";
	$q .= " from PaperReview join ContactInfo using (contactId)
		where ";
	if ($type)
	    $q .= "reviewType=$type and ";
	if ($submitted)
	    $q .= "reviewSubmitted>0";
	else
	    $q .= "(reviewNeedsSubmit=1 or reviewSubmitted>0)";
	$q .= "\n		group by paperId) as ReviewerInfo$suffix
		on (ReviewerInfo$suffix.paperId=Paper.paperId)";
	return $q;
    }
    
    function _searchQuery() {
	if ($this->limitName == "x")
	    return array("create temporary table Matches select Paper.paperId from Paper where false", "");
	
	$res = $this->_searchQueryType($this->q, "any");
	$res = $this->_combineQuery($res, $this->_searchQueryType($this->qa, "all"));
	$res = $this->_combineQuery($res, $this->_searchQueryType($this->qx, "none"));
	if ($this->limitName == "s" || $this->limitName == "r"
	    || $this->limitName == "req" || $this->limitName == "revs"
	    || $this->limitName == "acc" || $this->limitName == "und")
	    $res = $this->_combineQuery($res, array("Paper.timeSubmitted>0", ""));
	else if ($this->limitName == "act")
	    $res = $this->_combineQuery($res, array("Paper.timeWithdrawn<=0", ""));
	else if ($this->limitName == "unsub")
	    $res = $this->_combineQuery($res, array("(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)", ""));
	if ($this->limitName == "acc")
	    $res = $this->_combineQuery($res, array("Paper.outcome>0", ""));
	else if ($this->limitName == "und")
	    $res = $this->_combineQuery($res, array("Paper.outcome=0", ""));

	$q = "select Paper.paperId from Paper";
	if ($this->limitName == "r")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . ")";
	else if ($this->limitName == "rout")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.reviewNeedsSubmit>0)";
	else if ($this->limitName == "a")
	    $q .= " join PaperConflict as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.conflictType>=" . CONFLICT_AUTHOR . ")";
	else if ($this->limitName == "req")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.requestedBy=" . $this->contactId . " and Limiter.reviewType=" . REVIEW_EXTERNAL . ")";

	if (($this->any & self::ANY_CONFLICT) && $this->limitName != "a")
	    $q .= " left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=" . $this->contactId . ")";

	if ($this->any & (self::ANY_REVIEWER | self::ANY_NUM_REVIEW))
	    $q .= $this->_searchReviewerLimiter(false, 0);
	if ($this->any & (self::ANY_SUB_REVIEWER | self::ANY_SUB_NUM_REVIEW))
	    $q .= $this->_searchReviewerLimiter(true, 0);
	if ($this->any & (self::ANY_PRIMARY | self::ANY_NUM_PRIMARY))
	    $q .= $this->_searchReviewerLimiter(false, REVIEW_PRIMARY);
	if ($this->any & (self::ANY_SUB_PRIMARY | self::ANY_SUB_NUM_PRIMARY))
	    $q .= $this->_searchReviewerLimiter(true, REVIEW_PRIMARY);
	if ($this->any & (self::ANY_SECONDARY | self::ANY_NUM_SECONDARY))
	    $q .= $this->_searchReviewerLimiter(false, REVIEW_SECONDARY);
	if ($this->any & (self::ANY_SUB_SECONDARY | self::ANY_SUB_NUM_SECONDARY))
	    $q .= $this->_searchReviewerLimiter(true, REVIEW_SECONDARY);

	if ($this->any & self::ANY_LEAD)
	    $q .= " left join (select paperId, concat(firstName, ' ', lastName, ' ', email) as leadInfo from Paper join ContactInfo on (Paper.leadContactId=ContactInfo.contactId)) as LeadInfo on (LeadInfo.paperId=Paper.paperId)";
	if ($this->any & self::ANY_SHEPHERD)
	    $q .= " left join (select paperId, concat(firstName, ' ', lastName, ' ', email) as shepherdInfo from Paper join ContactInfo on (Paper.shepherdContactId=ContactInfo.contactId)) as ShepherdInfo on (ShepherdInfo.paperId=Paper.paperId)";
	if ($this->any & self::ANY_CONFLICTINFO)
	    $q .= " left join (select Paper.paperId, group_concat(firstName, ' ', lastName, ' ', email separator ' ') as conflictInfo from Paper left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.conflictType>0) join ContactInfo on (PaperConflict.contactId=ContactInfo.contactId) group by Paper.paperId) as ConflictInfo on (ConflictInfo.paperId=Paper.paperId)";
	
	if ($this->any & self::ANY_TAG)
	    $q .= " left join PaperTag on (PaperTag.paperId=Paper.paperId)";
	if ($this->any & self::ANY_ALLTAGS)
	    $q .= " left join (select paperId, group_concat('#', tag, '#') as alltags from PaperTag group by paperId) as AllPaperTags on (AllPaperTags.paperId=Paper.paperId)";
	if ($this->any & self::ANY_TOPICS)
	    $q .= " left join (select paperId, group_concat('#', topicId, '#') as topicIds from PaperTopic group by paperId) as AllPaperTopics on (AllPaperTopics.paperId=Paper.paperId)";
	if ($this->any & self::ANY_OPTIONS)
	    $q .= " left join (select paperId, group_concat('#', optionId, ':', value) as optionIds from PaperOption group by paperId) as AllPaperOptions on (AllPaperOptions.paperId=Paper.paperId)";

	if ($res[0])
	    $q .= " where " . $res[0];

	return array("create temporary table Matches $q group by Paper.paperId", $res[1]);
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

}
