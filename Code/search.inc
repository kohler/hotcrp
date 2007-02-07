<?php

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
		    'tag:' => 'tag',
		    'ord:' => 'order', 'order:' => 'order',
		    'decision:' => 'decision', 'dec:' => 'decision',
		    'topic:' => 'topic',
		    'lead:' => 'lead',
		    'shepherd:' => 'shepherd', 'shep:' => 'shepherd');

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
    
    var $contactId;
    var $amAssistant;
    var $amPC;
    var $limitName;
    var $allowReviewer;
    var $allowNumReview;
    var $allowAuthor;
    var $fields;
    var $any;
    var $orderTag;
    var $reviewerName;
    var $matchPreg;
    var $urlbase;
    
    var $q;
    var $qa;
    var $qx;
    
    function PaperSearch($me, $opt) {
	global $Conf;

	$this->amAssistant = $me->amAssistant();
	$this->amPC = $me->isPC;
	$this->allowReviewer = $me->canViewAllReviewerIdentities($Conf);
	$this->allowNumReview = $me->canViewNumReviews($Conf);
	$this->allowAuthor = ($this->amPC && ($Conf->blindSubmission() >= 1 || $me->amAssistant()));
	$this->orderTag = false;
	$this->reviewerName = false;
	$this->any = 0;
	$this->contactId = $me->contactId;
	if ($this->amAssistant && cvtint($opt["c"]) > 0)
	    $this->contactId = cvtint($opt["c"]);

	// paper selection
	$ptype = defval($opt["t"], 0);
	if ($this->amAssistant && !$ptype && $Conf->timeUpdatePaper())
	    $this->limitName = "all";
	else if ($me->isPC && (!$ptype || $ptype == "s"))
	    $this->limitName = "s";
	else if ($this->amAssistant && $ptype == "all")
	    $this->limitName = "all";
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
	$qtype = defval($opt["qt"], "n");
	if ($qtype == "n" || $qtype == "ti")
	    $this->fields["ti"] = 1;
	if ($qtype == "n" || $qtype == "ab")
	    $this->fields["ab"] = 1;
	if ($this->allowAuthor && ($qtype == "n" || $qtype == "au" || $qtype == "ac"))
	    $this->fields["au"] = 1;
	if ($this->amAssistant && $qtype == "ac")
	    $this->fields["co"] = 1;
	if ($this->allowReviewer && $qtype == "re")
	    $this->fields["re"] = 1;

	// the query itself
	$this->q = trim(defval($opt["q"], ""));
	$this->qa = trim(defval($opt["qa"], ""));
	$this->qx = trim(defval($opt["qx"], ""));

	// URL base
	if (isset($opt["urlbase"]))
	    $this->urlbase = $opt["urlbase"];
	else {
	    $this->urlbase = "search.php?t=" . urlencode($this->limitName);
	    if ($qtype != "n")
		$this->urlbase .= "&qt=" . urlencode($qtype);
	}
    }

    function _searchSuffixFactor($submitted, $type, &$suffix, &$factor) {
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
	$this->_searchSuffixFactor($submitted, $what, $sub, $anyfac);
	if (preg_match('/^([<>]?=?\s*)?(\d+)$/s', $word, $m) && $this->allowNumReview) {
	    if ($m[2] == 0 && $m[1] == "<")
		return " or false";
	    if ($m[2] == 0 && $m[1] == ">=")
		return " or true";
	    $this->any |= self::ANY_NUM_REVIEW * $anyfac;
	    $q = ($m[1][0] == "<" ? " or (numReviews$sub is null)" : "");
	    if ($m[2] == 0 && (!$m[1] || $m[1] == "=" || $m[1] == "<="))
		return $q;
	    else
		return $q . " or (numReviews$sub" . ($m[1] ? $m[1] : "=") . $m[2] . ")";
	} else if (!$this->allowReviewer)
	    return "";
	else {
	    $this->any |= self::ANY_REVIEWER * $anyfac;
	    $and = "";
	    if (!$this->amAssistant) {
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

    function _searchQueryType($str, $type = "any") {
	global $searchTags, $Conf;
	$q = "";

	// quick emptiness check
	if (!$str)
	    return array("", "");

	// paper IDs?
	if (($type == "any" && preg_match('/^\s*\d[\d\s]*$/s', $str))
	    || ($type == "all" && preg_match('/^\s*\d+\s*$/s', $str))) {
	    foreach (preg_split('/\s+/', $str) as $word)
		if ($word != "")
		    $q .= "Paper.paperId=$word or ";
	    return array(substr($q, 0, strlen($q) - 4), "");
	}

	// construct query portion
	$re = "";
	$auextra = ($this->amAssistant ? "" : "blind=0 and ");
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

	    $re .= ($re == "" ? "" : "|") . preg_quote($word);
	    $word = preg_replace("/([%_\\\\])/", "\\\$1", $word);
	    $word = sqlq($word);
	    $qp = "";
	    if ($special ? $special == "ti" : isset($this->fields['ti']))
		$qp .= " or (title like '%$word%')";
	    if ($special ? $special == "ab" : isset($this->fields['ab']))
		$qp .= " or (abstract like '%$word%')";
	    if (($special ? $special == "au" : isset($this->fields['au'])) && $this->allowAuthor)
		$qp .= " or (${auextra}authorInformation like '%$word%')";
	    if (($special ? $special == "co" : isset($this->fields['co'])) && $this->amAssistant)
		$qp .= " or (${auextra}Paper.collaborators like '%$word%')";
	    if ($special ? $special == "re" : isset($this->fields['re']))
		$qp .= $this->_searchReviewer($word, $type, false);
	    if ($special ? $special == "sre" : isset($this->fields['sre']))
		$qp .= $this->_searchReviewer($word, $type, true);
	    if ($special ? $special == "pri" : isset($this->fields['pri']))
		$qp .= $this->_searchReviewer($word, $type, false, REVIEW_PRIMARY);
	    if ($special ? $special == "spri" : isset($this->fields['spri']))
		$qp .= $this->_searchReviewer($word, $type, true, REVIEW_PRIMARY);
	    if ($special ? $special == "sec" : isset($this->fields['sec']))
		$qp .= $this->_searchReviewer($word, $type, false, REVIEW_SECONDARY);
	    if ($special ? $special == "ssec" : isset($this->fields['ssec']))
		$qp .= $this->_searchReviewer($word, $type, true, REVIEW_SECONDARY);
	    if ((($special ? $special == "tag" : isset($this->fields['tag']))
		 || ($special ? $special == "order" : isset($this->fields['order'])))
		&& $this->amPC) {
		if ($special ? $special == "order" : isset($this->fields['order']))
		    $this->orderTag = ($this->orderTag === false ? $word : null);
		$confpart = "";
		if (!$this->amAssistant) {
		    $confpart = " and conflictType is null";
		    $this->any |= self::ANY_CONFLICT;
		}
		if ($word == "none") {
		    $qp .= " or (alltags is null$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		} else if ($type == "any") {
		    $qp .= " or (tag='$word'$confpart)";
		    $this->any |= self::ANY_TAG;
		} else if ($type == "all") {
		    $qp .= " or (alltags like '%#$word#%'$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		} else {
		    $qp .= " or (alltags is not null and alltags like '%#$word#%'$confpart)";
		    $this->any |= self::ANY_ALLTAGS;
		}
	    }
	    if (($special ? $special == "decision" : isset($this->fields['decision'])) && $this->amPC) {
		$confpart = "";
		//if (!$this->amAssistant && !$Conf->timeAuthorViewDecision())
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
		foreach ($rf->topicName as $tid => $n)
		    if (strstr(strtolower($n), $x) !== false) {
			$qp .= " or (topicIds like '%#$tid#%')";
			$this->any |= self::ANY_TOPICS;
		    }
	    }
	    if (($special ? $special == "lead" : isset($this->fields['lead'])) && $this->amPC) {
		$this->any |= self::ANY_LEAD;
		if ($word == "none")
		    $qp .= " or (leadInfo is null)";
		else if ($word == "any")
		    $qp .= " or (leadInfo is not null)";
		else if ($type != "none")
		    $qp .= " or (leadInfo like '%$word%')";
		else
		    $qp .= " or (leadInfo is not null and leadInfo like '%$word%')";
	    }
	    if ($special ? $special == "shepherd" : isset($this->fields['shepherd'])) {
		$this->any |= self::ANY_SHEPHERD;
		if ($word == "none")
		    $qp .= " or (shepherdInfo is null)";
		else if ($word == "any")
		    $qp .= " or (shepherdInfo is not null)";
		else if ($type != "none")
		    $qp .= " or (shepherdInfo like '%$word%')";
		else
		    $qp .= " or (shepherdInfo is not null and shepherdInfo like '%$word%')";
	    }
	    
	    if ($qp)
		$q .= $qjoin . "(" . substr($qp, 4) . ")";
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
	$this->_searchSuffixFactor($submitted, $type, $suffix, $fac);
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
	if ($this->limitName == "s")
	    $res = $this->_combineQuery($res, array("Paper.timeSubmitted>0", ""));

	$q = "select Paper.paperId from Paper";
	if ($this->limitName == "r")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . ")";
	else if ($this->limitName == "rout")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.reviewNeedsSubmit>0)";
	else if ($this->limitName == "a")
	    $q .= " join PaperConflict as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.conflictType=" . CONFLICT_AUTHOR . ")";
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
	
	if ($this->any & self::ANY_TAG)
	    $q .= " left join PaperTag on (PaperTag.paperId=Paper.paperId)";
	if ($this->any & self::ANY_ALLTAGS)
	    $q .= " left join (select paperId, group_concat('#', tag, '#') as alltags from PaperTag group by paperId) as AllPaperTags on (AllPaperTags.paperId=Paper.paperId)";
	if ($this->any & self::ANY_TOPICS)
	    $q .= " left join (select paperId, group_concat('#', topicId, '#') as topicIds from PaperTopic group by paperId) as AllPaperTopics on (AllPaperTopics.paperId=Paper.paperId)";

	if ($res[0])
	    $q .= " where " . $res[0];

	return array("create temporary table Matches $q group by Paper.paperId", $res[1]);
    }

    function complexSearch(&$queryOptions) {
	if ($this->q || $this->qa || $this->qx)
	    return true;
	if ($this->limitName == "s")
	    $queryOptions["finalized"] = 1;
	else if ($this->limitName == "r")
	    $queryOptions["myReviews"] = 1;
	else if ($this->limitName == "rout")
	    $queryOptions["myOutstandingReviews"] = 1;
	else if ($this->limitName == "a")
	    $queryOptions["author"] = 1;
	else if ($this->limitName == "req")
	    $queryOptions["myReviewRequests"] = 1;
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
	global $Conf, $ConfSiteBase;
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
	return $x;
    }

    function url() {
	$url = $this->urlbase;
	if (substr($this->urlbase, 0, 10) == "search.php" || $this->q != "")
	    $url .= "&q=" . urlencode($this->q);
	if ($this->qa != "")
	    $url .= "&qa=" . urlencode($this->qa);
	if ($this->qx != "")
	    $url .= "&qx=" . urlencode($this->qx);
	return $url;
    }

}
