<?php

$searchTags = array('ti:' => 'ti', 'title:' => 'ti',
		    'ab:' => 'ab', 'abstract:' => 'ab',
		    'au:' => 'au', 'author:' => 'au',
		    'co:' => 'co', 'collab:' => 'co', 'collaborators:' => 'co',
		    're:' => 're', 'reviewer:' => 're',
		    'tag:' => 'tag',
		    'outcome:' => 'outcome', 'out:' => 'outcome');

class PaperSearch {

    var $amAssistant;
    var $allPapers;
    var $allowReviewer;
    var $allowAuthor;
    var $allowOutcome;
    var $fields;
    var $anyReviewer;
    var $matchPreg;
    
    function PaperSearch($type, $me) {
	global $Conf;

	$this->amAssistant = $me->amAssistant();
	$this->allowReviewer = $me->canViewAllReviewerIdentities($Conf);
	$this->allowAuthor = ($Conf->blindSubmission() > 1 || $me->amAssistant());
	$this->allowOutcome = $me->isPC;
	$this->allPapers = ($me->amAssistant() ? 0 : -1);
	$this->anyReviewer = false;

	$this->fields = array();
	if ($type == "n" || $type == "ti")
	    $this->fields["ti"] = 1;
	if ($type == "n" || $type == "ab")
	    $this->fields["ab"] = 1;
	if ($this->allowAuthor && ($type == "n" || $type == "au" || $type == "ac"))
	    $this->fields["au"] = 1;
	if ($me->amAssistant() && $type == "ac")
	    $this->fields["co"] = 1;
	if ($this->allowReviewer && $type == "re")
	    $this->fields["re"] = 1;
    }

    function setAllPapers() {
	if ($this->allPapers == 0)
	    $this->allPapers = 1;
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
		$qp .= " or (firstName like '%$word%') or (lastName like '%$word%') or (email like '%$word%')";
		$this->anyReviewer = true;
	    }
	    if ($special ? $special == "tag" : isset($this->fields['tag']))
		$qp .= " or (tag = '$word')";
	    if (($special ? $special == "outcome" : isset($this->fields['outcome'])) && $this->allowOutcome) {
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
    
    function _searchQuery($q, $qa, $qx) {
	$res = $this->_searchQueryType($q, "any");
	$res = $this->_combineQuery($res, $this->_searchQueryType($qa, "all"));
	$res = $this->_combineQuery($res, $this->_searchQueryType($qx, "none"));
	if (!$this->allPapers)
	    $res = $this->_combineQuery($res, array("Paper.timeSubmitted>0", ""));
	$q = ($res[0] ? $res[0] : "true");
	if ($this->anyReviewer)
	    $q = "create temporary table Matches select Paper.paperId from Paper left join PaperReview using (paperId) left join ContactInfo on (PaperReview.contactId=ContactInfo.contactId) left join PaperTag on (PaperTag.paperId=Paper.paperId) where $q group by Paper.paperId";
	else
	    $q = "create temporary table Matches select Paper.paperId from Paper left join PaperTag on (PaperTag.paperId=Paper.paperId) where $q group by Paper.paperId";
	return array($q, $res[1]);
    }
    
    function search($q, $qa = "", $qx = "") {
	global $Conf;
	$x = $this->_searchQuery($q, $qa, $qx);
	$this->matchPreg = $x[1];
	return $Conf->qe($x[0], "while searching papers");
    }

    function paperList($q, $qa, $qx) {
	global $Conf;
	$x = $this->_searchQuery($q, $qa, $qx);
	$result = $Conf->qe($x[0], "while searching papers");
	if (DB::isError($result))
	    return array();
	$x = array();
	$result = $Conf->qe("select * from Matches", "while searching papers");
	while (($row = $result->fetchRow()))
	    $x[] = $row[0];
	return $x;
    }
    
}

    /* $find = sqlqtrim($_REQUEST["search"]);
    if ($Me->amAssistant())
	$q .= "	where match(title, abstract, authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n";
    else
	$q .= "	where match(title, abstract) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)
	or (blind=0 and match(authorInformation, collaborators) against ('" . sqlqtrim($_REQUEST["search"]) . "' in boolean mode)\n"; */
