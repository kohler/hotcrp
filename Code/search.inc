<?php

$searchTags = array('ti:' => 'ti', 'title:' => 'ti',
		    'ab:' => 'ab', 'abstract:' => 'ab',
		    'au:' => 'au', 'author:' => 'au',
		    'co:' => 'co', 'collab:' => 'co', 'collaborators:' => 'co',
		    're:' => 're', 'rev:' => 're', 'reviewer:' => 're',
		    'tag:' => 'tag',
		    'outcome:' => 'outcome', 'out:' => 'outcome');

class PaperSearch {

    var $contactId;
    var $amAssistant;
    var $amPC;
    var $limitName;
    var $allowReviewer;
    var $allowAuthor;
    var $fields;
    var $anyReviewer;
    var $anyTag;
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
	$this->allowAuthor = ($Conf->blindSubmission() > 1 || $me->amAssistant());
	$this->anyReviewer = false;
	$this->anyTag = false;
	$this->contactId = $me->contactId;
	if ($this->amAssistant && cvtint($opt["c"]) > 0)
	    $this->contactId = cvtint($opt["c"]);

	// paper selection
	$ptype = defval($opt["t"], 0);
	if ($me->isPC && (!$ptype || $ptype == "s"))
	    $this->limitName = "s";
	else if ($this->amAssistant && $ptype == "all")
	    $this->limitName = "all";
	else if ($me->amReviewer() && (!$ptype || $ptype == "r"))
	    $this->limitName = "r";
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
	if ($me->amAssistant() && $qtype == "ac")
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
    
    function _searchQueryType($str, $type = "any") {
	global $searchTags;
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
	while (($str = ltrim($str)) != "") {
	    preg_match('/^("[^"]+"?|[a-z]+:"[^"]+"?|[^"\s]+)/s', $str, $m);
	    $word = $m[0];
	    $str = substr($str, strlen($word));
	    
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
	    if (($special ? $special == "re" : isset($this->fields['re'])) && $this->allowReviewer) {
		$qp .= " or (reviewerInfo like '%$word%')";
		$this->anyReviewer = true;
	    }
	    if (($special ? $special == "tag" : isset($this->fields['tag'])) && $this->amPC) {
		$qp .= " or (tag = '$word')";
		$this->anyTag = true;
	    }
	    if (($special ? $special == "outcome" : isset($this->fields['outcome'])) && $this->amPC) {
		$rf = reviewForm();
		foreach ($rf->options['outcome'] as $num => $what)
		    if (strcmp($word, $num) == 0 || strcasecmp($word, $what) == 0) {
			$qp .= " or (outcome = '$num')";
			break;
		    }
	    }
	    if ($qp)
		$q .= $qjoin . "(" . substr($qp, 4) . ")";
	}

	// construct table
	if (!$q)
	    return array("true", "");
	else if ($type == "none")
	    return array("not (" . substr($q, strlen($qjoin)) . ")", $re);
	else
	    return array(substr($q, strlen($qjoin)), $re);
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
	else if ($this->limitName == "a")
	    $q .= " join PaperConflict as Limiter on (Limiter.paperId=Paper.paperId and Limiter.contactId=" . $this->contactId . " and Limiter.author>0)";
	else if ($this->limitName == "req")
	    $q .= " join PaperReview as Limiter on (Limiter.paperId=Paper.paperId and Limiter.requestedBy=" . $this->contactId . " and Limiter.reviewType=" . REVIEW_REQUESTED . ")";

	if ($this->anyReviewer)
	    $q .= " left join (select paperId, group_concat(firstName, lastName, email) as reviewerInfo from PaperReview join ContactInfo using (contactId) group by paperId) as ReviewerInfo on (ReviewerInfo.paperId=Paper.paperId)";
	if ($this->anyTag)
	    $q .= " left join PaperTag on (PaperTag.paperId=Paper.paperId)";

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
	else if ($this->limitName == "a")
	    $queryOptions["author"] = 1;
	else if ($this->limitName == "req")
	    $queryOptions["myReviewRequests"] = 1;
	return false;
    }
    
    function search() {
	global $Conf, $ConfSiteBase;
	$x = $this->_searchQuery();
	$this->matchPreg = $x[1];
	return $Conf->qe($x[0], "while searching papers");
    }

    function paperList() {
	global $Conf;
	$x = $this->_searchQuery();
	$result = $Conf->qe($x[0], "while searching papers");
	if (MDB2::isError($result))
	    return array();
	$x = array();
	$result = $Conf->qe("select * from Matches", "while searching papers");
	while (($row = $result->fetchRow()))
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
