<?php
// contactlist.inc -- HotCRP helper class for producing lists of contacts
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("baselist.inc");

class ContactList extends BaseList {

    const FIELD_SELECTOR = 100;
    const FIELD_SELECTOR_ON = 101;
    
    const FIELD_NAME = 1;
    const FIELD_EMAIL = 2;
    const FIELD_AFFILIATION = 3;
    const FIELD_VISITS = 4;
    const FIELD_LASTVISIT = 5;
    const FIELD_HIGHTOPICS = 6;
    const FIELD_LOWTOPICS = 7;
    const FIELD_REVIEWS = 8;
    const FIELD_PAPERS = 9;
    const FIELD_REVIEW_PAPERS = 10;
    const FIELD_AFFILIATION_ROW = 11;
    
    var $showHeader;
    var $sortField;
    var $reverseSort;
    var $sortable;
    var $count;
    var $anySelector;
    var $contact;
    var $scoreMax;
    var $authorMatch;
    var $collaboratorsMatch;
    var $limit;
    var $haveAffrow;
    var $haveTopics;
    
    function ContactList($sortable = true) {
	global $contactListFields;
	$this->showHeader = true;

	$s = ($sortable ? defval($_REQUEST["sort"], "") : "");
	$x = (strlen($s) ? $s[strlen($s)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $s = substr($s, 0, strlen($s) - 1);
	if (("x" . (int) $s) == ("x" . $s))
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortable = $sortable;
	$this->haveAffrow = $this->haveTopics = null;
    }

    function _normalizeField($fieldId) {
	if ($fieldId >= self::FIELD_SCORE && $fieldId < self::FIELD_SCORE + self::FIELD_NUMSCORES)
	    return self::FIELD_SCORE;
	else
	    return $fieldId;
    }
    
    function selector($fieldId, &$queryOptions) {
	global $reviewScoreNames;
	if (!$this->contact->isPC
	    && $fieldId != self::FIELD_NAME
	    && $fieldId != self::FIELD_AFFILIATION
	    && $fieldId != self::FIELD_AFFILIATION_ROW)
	    return false;
	if ($fieldId == self::FIELD_HIGHTOPICS || $fieldId == self::FIELD_LOWTOPICS) {
	    $queryOptions['topics'] = true;
	    $this->haveTopics = true;
	}
	if ($fieldId == self::FIELD_REVIEWS)
	    $queryOptions['reviews'] = true;
	if ($fieldId == self::FIELD_PAPERS)
	    $queryOptions['papers'] = true;
	if ($fieldId == self::FIELD_REVIEW_PAPERS)
	    $queryOptions['repapers'] = true;
	if ($fieldId == self::FIELD_AFFILIATION_ROW)
	    $this->haveAffrow = true;
	if (self::_normalizeField($fieldId) == self::FIELD_SCORE) {
	    // XXX scoresOk
	    if (!isset($queryOptions['scores']))
		$queryOptions['scores'] = array();
	    $rf = reviewForm();
	    $queryOptions['reviews'] = true;
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    $queryOptions['scores'][] = $scoreName;
	    $this->scoreMax[$scoreName] = $rf->maxNumericScore($scoreName);
	}
	return true;
    }

    function _sortBase($a, $b) {
	$x = strcasecmp($a->lastName, $b->lastName);
	$x = $x ? $x : strcasecmp($a->firstName, $b->firstName);
	return $x ? $x : strcasecmp($a->email, $b->email);
    }
    
    function _sortEmail($a, $b) {
	return strcasecmp($a->email, $b->email);
    }
    
    function _sortAffiliation($a, $b) {
	$x = strcasecmp($a->affiliation, $b->affiliation);
	return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortVisits($a, $b) {
	$x = $b->visits - $a->visits;
	return $x ? $x : self::_sortBase($a, $b);
    }
 
    function _sortLastVisit($a, $b) {
	$x = $b->lastLogin - $a->lastLogin;
	return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortReviews($a, $b) {
	$x = $b->numReviewsSubmitted - $a->numReviewsSubmitted;
	$x = $x ? $x : $b->numReviews - $a->numReviews;
	return $x ? $x : self::_sortBase($a, $b);
    }

    function _sortPapers($a, $b) {
	$x = (int) $a->paperIds - (int) $b->paperIds;
	$x = $x ? $x : strcmp($a->paperIds, $b->paperIds);
	return $x ? $x : self::_sortBase($a, $b);
    }

    function _sort($rows) {
	global $Conf, $reviewScoreNames;
	switch (self::_normalizeField($this->sortField)) {
	case self::FIELD_EMAIL:
	    usort($rows, array("ContactList", "_sortEmail"));
	    break;
	case self::FIELD_AFFILIATION:
	case self::FIELD_AFFILIATION_ROW:
	    usort($rows, array("ContactList", "_sortAffiliation"));
	    break;
	case self::FIELD_VISITS:
	    usort($rows, array("ContactList", "_sortVisits"));
	    break;
	case self::FIELD_LASTVISIT:
	    usort($rows, array("ContactList", "_sortLastVisit"));
	    break;
	case self::FIELD_REVIEWS:
	    usort($rows, array("ContactList", "_sortReviews"));
	    break;
	case self::FIELD_PAPERS:
	case self::FIELD_REVIEW_PAPERS:
	    usort($rows, array("ContactList", "_sortPapers"));
	    break;
	case self::FIELD_SCORE:
	    $scoreName = $reviewScoreNames[$this->sortField - self::FIELD_SCORE];
	    $scoreMax = $this->scoreMax[$scoreName];
	    $this->sortInfo = array();
	    $this->sortAverage = array();
	    $scoresort = defval($_SESSION["pplscoresort"], 1);
	    if ($scoresort == 0)
		$scoresort = 1;
	    foreach ($rows as $row)
		$this->_setSortRow($row, $scoreName, $scoreMax, $scoresort);
	    usort($rows, array($this, ($scoresort ? "_sortScoreNum" : "_sortScore")));
	}
	if ($this->reverseSort)
	    return array_reverse($rows);
	else
	    return $rows;
    }
    
    function header($fieldId, $ordinal, $row = null) {
	global $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_NAME:
	    return "Name";
	case self::FIELD_EMAIL:
	    return "Email";
	case self::FIELD_AFFILIATION:
	case self::FIELD_AFFILIATION_ROW:
	    return "Affiliation";
	case self::FIELD_VISITS:
	    return "Visits";
	case self::FIELD_LASTVISIT:
	    return "Last visit";
	case self::FIELD_HIGHTOPICS:
	    return "High-interest topics";
	case self::FIELD_LOWTOPICS:
	    return "Low-interest topics";
	case self::FIELD_REVIEWS:
	    return "<acronym title='\"1/2\" means 1 complete review out of 2 assigned reviews'>Reviews</acronym>";
	case self::FIELD_SELECTOR:
	    return "";
	case self::FIELD_PAPERS:
	case self::FIELD_REVIEW_PAPERS:
	    return "Papers";
	case self::FIELD_SCORE: {
	    $rf = reviewForm();
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    return $rf->webFieldAbbrev($scoreName);
	}
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }
    
    function content($fieldId, $row) {
	global $ConfSiteBase, $Conf, $reviewTypeName, $reviewScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_NAME:
	    $t = contactHtml($row->firstName, $row->lastName);
	    if (trim($t) == "")
		$t = "[none]";
	    if ($this->contact->privChair)
		$t = "<a href='${ConfSiteBase}account.php?contact=$row->contactId'>$t</a>";
	    if ($row->chair)
		$t .= " <span class='pcrole'>(Chair)</span>";
	    else if ($row->assistant)
		$t .= " <span class='pcrole'>(Administrator)</span>";
	    else if ($row->pc && $this->limit != "pc")
		$t .= " <span class='pcrole'>(PC)</span>";
	    if ($this->contact->privChair && $row->email != $this->contact->email)
		$t .= " <a href=\"${ConfSiteBase}index.php?viewContact=" . urlencode($row->email) . "\"><img src='${ConfSiteBase}images/viewas.png' title=\"Act as " . htmlspecialchars(contactNameText($row)) . "\" /></a>";
	    return $t;
	case self::FIELD_EMAIL:
	    if (!$this->contact->isPC)
		return "";
	    return "<a href=\"mailto:" . htmlspecialchars($row->email) . "\">" . htmlspecialchars($row->email) . "</a>";
	case self::FIELD_AFFILIATION:
	case self::FIELD_AFFILIATION_ROW:
	    return htmlspecialchars($row->affiliation);
	case self::FIELD_VISITS:
	    return $row->visits;
	case self::FIELD_LASTVISIT:
	    if (!$row->lastLogin)
		return "Never";
	    return $Conf->printableTimeShort($row->lastLogin);
	case self::FIELD_SELECTOR:
	case self::FIELD_SELECTOR_ON:
	    $this->anySelector = 1;
	    $c = "";
	    if ($fieldId == self::FIELD_SELECTOR_ON)
		$c = " checked='checked'";
	    return "<input type='checkbox' name='pap[]' value='$row->contactId' tabindex='1' id='psel$this->count' onclick='pselClick(event, this, $this->count)' $c/>";
	case self::FIELD_HIGHTOPICS:
	case self::FIELD_LOWTOPICS:
	    if (!defval($row->topicIds))
		return "";
	    $want = ($fieldId == self::FIELD_HIGHTOPICS ? 2 : 0);
	    $topics = array_combine(explode(",", $row->topicIds), explode(",", $row->topicInterest));
	    $nt = array();
	    foreach ($topics as $k => $v)
		if ($v == $want)
		    $nt[] = $k;
	    $rf = reviewForm();
	    if (count($nt))
		return join(", ", $rf->webTopicArray($nt, array_fill(0, count($nt), $want)));
	    else
		return "";
	case self::FIELD_REVIEWS:
	    if (!$row->numReviews && !$row->numReviewsSubmitted)
		return "";
	    $a1 = "<a href=\"${ConfSiteBase}search.php?t=s&amp;q=re:" . urlencode($row->email) . "\">";
	    if ($row->numReviews == $row->numReviewsSubmitted)
		return "$a1<b>$row->numReviewsSubmitted</b></a>";
	    else
		return "$a1<b>$row->numReviewsSubmitted</b>/$row->numReviews</a>";
	case self::FIELD_PAPERS:
	    if (!$row->paperIds)
		return "";
	    $x = explode(",", $row->paperIds);
	    sort($x, SORT_NUMERIC);
	    $all = ($this->limit == "auuns" || $this->limit == "all");
	    $extra = "&amp;list=" . ($all ? "all:" : "s:") . join("+", $x);
	    $m = array();
	    foreach ($x as $v)
		$m[] = "<a href=\"${ConfSiteBase}paper.php?paperId=$v$extra\">$v</a>";
	    return join(", ", $m);
	case self::FIELD_REVIEW_PAPERS:
	    if (!$row->paperIds)
		return "";
	    $x = array_combine(explode(",", $row->paperIds), explode(",", $row->reviewIds));
	    ksort($x, SORT_NUMERIC);
	    $extra = "&amp;list=s:" . join("+", array_keys($x));
	    $m = array();
	    foreach ($x as $k => $v)
		$m[] = "<a href=\"${ConfSiteBase}review.php?reviewId=$v$extra\">$k</a>";
	    return join(", ", $m);
	case self::FIELD_SCORE:
	    if (!$row->pc && !$this->contact->privChair
		&& $this->limit != "req")
		return "";
	    $scoreName = $reviewScoreNames[$fieldId - self::FIELD_SCORE];
	    $v = scoreCounts($row->$scoreName, $this->scoreMax[$scoreName]);
	    $m = "";
	    if ($v->n > 0) {
		$m = $Conf->textValuesGraph($v, 0, 2);
		/*foreach ($scores as $score => $count)
		    $m .= ($m ? " " : "") . "<span class='sc$score'>$count</span>";
		$avg = $sum / $n;
		$dev = ($n > 1 ? sqrt(($sumsq - $sum*$sum/$n) / ($n - 1)) : 0);
		$avgtext = ($n > 1 ? sprintf("%0.2f &plusmn; %0.2f", $avg, $dev) : sprintf("%0.2f", $avg));
		$m .= "<br /><span class='sc_sum'>$avgtext</span>"; */
	    }
	    return $m;
	default:
	    return "";
	}
    }

    function addScores($a) {
	if ($this->contact->isPC) {
	    $scores = defval($_SESSION["pplscores"], 1);
	    for ($i = 0; $i < self::FIELD_NUMSCORES; $i++)
		if ($scores & (1 << $i))
		    array_push($a, self::FIELD_SCORE + $i);
	    $this->scoreMax = array();
	}
	return $a;
    }

    function listFields($listname) {
	switch ($listname) {
	  case "pc":
	  case "admin":
	    return $this->addScores(array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS));
	  case "re":
	    return $this->addScores(array("re", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS));
	  case "ext":
	    return $this->addScores(array("ext", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_PAPERS));
	  case "req":
	    return $this->addScores(array("req", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS, self::FIELD_REVIEW_PAPERS));
	  case "au":
	  case "aurej":
	  case "auacc":
	  case "auuns":
	    return array($listname, self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_PAPERS);
	  case "all":
	    return array("all", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION_ROW, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_PAPERS);
	  default:
	    return null;
	}
    }

    function footer($ncol) {
	global $Conf, $ConfSiteBase;
	if ($this->count == 0)
	    return "";
	
	$t = "  <tr class='pl_footrow'>\n    <td class='pl_selector' style='vertical-align: top'><img src=\"${ConfSiteBase}images/bendulft.png\" style='position: relative; top: 6px' /></td>\n";
	$t .= "    <td id='pplact' class='linelinks1' colspan='" . ($ncol - 1) . "'><b>Select people</b> (or <a href='javascript:void papersel(true)'>select all " . $this->count . "</a>), then ";

	// Begin linelinks
	$t .= "<span class='lll1'><a href='javascript:void tablink(\"pplact\",1)'>Get</a></span><span class='lld1'><b>:</b> &nbsp;";
	$t .= "<select id='pplact1_d' name='getaction'>
    <option value='nameemail' selected='selected'>Names and emails</option>\n";
	$t .= "</select>&nbsp; <input type='submit' class='button_small' name='getgo' value='Go' /></span>";

	return $t . "</td></tr>\n";
    }

    function text($listname, $me, $url) {
	global $Conf, $ConfSiteBase, $contactListFields;

	$this->contact = $me;
	$this->count = 0;
	$this->anySelector = 0;

	// get paper list
	if (!($baseFieldId = $this->listFields($listname))) {
	    $Conf->errorMsg("There is no people list query named '" . htmlspecialchars($listname) . "'.");
	    return null;
	}
	$this->limit = array_shift($baseFieldId);

	if (!in_array($this->sortField, $baseFieldId))
	    $this->sortField = null;
	
	// get field array
	$fieldDef = array();
	$ncol = 0;
	$queryOptions = array();
	foreach ($baseFieldId as $fid) {
	    if ($this->selector($fid, $queryOptions) === false)
		continue;
	    $normFid = self::_normalizeField($fid);
	    $fieldDef[$fid] = $contactListFields[$normFid];
	    if ($contactListFields[$normFid][1] == 1)
		$ncol++;
	}

	// prepare query text
	$aulimit = (strlen($this->limit) >= 2 && $this->limit[0] == 'a' && $this->limit[1] == 'u');
	$pq = "select ContactInfo.contactId,
	ContactInfo.contactId as paperId,
	firstName, lastName, email, affiliation,
	voicePhoneNumber, faxPhoneNumber,
	ContactInfo.collaborators, lastLogin, visits, note,
	PCMember.contactId as pc, Chair.contactId as chair,
	ChairAssistant.contactId as assistant";
	if (isset($queryOptions['topics']))
	    $pq .= ",\n	topicIds, topicInterest";
	if (isset($queryOptions['reviews']))
	    $pq .= ",
	count(coalesce(PaperReview.reviewSubmitted,nullif(PaperReview.reviewNeedsSubmit,0))) as numReviews,
	count(PaperReview.reviewSubmitted) as numReviewsSubmitted";
	if (isset($queryOptions['scores']))
	    foreach ($queryOptions['scores'] as $score)
		$pq .= ",\n\tgroup_concat(PaperReview.$score) as $score";
	if (isset($queryOptions['repapers']))
	    $pq .= ",\n\tgroup_concat(PaperReview.paperId) as paperIds,
	group_concat(PaperReview.reviewId) as reviewIds";
	else if (isset($queryOptions['papers']))
	    $pq .= ",\n\tgroup_concat(PaperConflict.paperId) as paperIds";
	
	$pq .= "\n	from ContactInfo\n";
	$pq .= ($this->limit == "pc" ? "\tjoin" : "\tleft join") . " PCMember on (PCMember.contactId=ContactInfo.contactId)\n";
	$pq .= "\tleft join Chair on (Chair.contactId=ContactInfo.contactId)
	left join ChairAssistant on (ChairAssistant.contactId=ContactInfo.contactId)\n";
	if (isset($queryOptions['topics']))
	    $pq .= "	left join (select contactId, group_concat(topicId) as topicIds, group_concat(interest) as topicInterest from TopicInterest group by contactId) as TopicInterests on (TopicInterests.contactId=ContactInfo.contactId)\n";
	if (isset($queryOptions['reviews'])) {
	    $j = "left join";
	    if ($this->limit == "re" || $this->limit == "req" || $this->limit == "ext")
		$j = "join";
	    $pq .= "	$j (select PaperReview.* from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) where Paper.timeSubmitted>0";
	    if ($this->limit == "req" || $this->limit == "ext")
		$pq .= " and PaperReview.reviewType=" . REVIEW_EXTERNAL;
	    if ($this->limit == "req")
		$pq .= " and PaperReview.requestedBy=" . $this->contact->contactId;
	    $pq .= ") as PaperReview on (PaperReview.contactId=ContactInfo.contactId)\n";
	}
	if ($aulimit)
	    $pq .= "\tjoin PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.conflictType=" . CONFLICT_AUTHOR . ")\n";
	if ($this->limit == "au")
	    $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.timeSubmitted>0)\n";
	if ($this->limit == "aurej")
	    $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.outcome<0)\n";
	if ($this->limit == "auacc")
	    $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.outcome>0)\n";
	if ($this->limit == "auuns")
	    $pq .= "\tjoin Paper on (Paper.paperId=PaperConflict.paperId and Paper.timeSubmitted<=0)\n";
	if ($this->limit == "all")
	    $pq .= "\tleft join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId and PaperConflict.conflictType=" . CONFLICT_AUTHOR . ")\n";

	if ($this->limit == "admin")
	    $pq .= "\twhere Chair.contactId is not null or ChairAssistant.contactId is not null\n";
	
	$pq .= "	group by ContactInfo.contactId
	order by lastName, firstName, email";

	// make query
	$result = $Conf->qe($pq, "while selecting people");
	if (!$result)
	    return NULL;

	// fetch data
	if (edb_nrows($result) == 0)
	    return "No matching people";
	$rows = array();
	while (($row = edb_orow($result)))
	    $rows[] = $row;

	// sort rows
	$srows = $this->_sort($rows);

	$anyData = array();
	$body = '';
	$extrainfo = false;
	foreach ($srows as $row) {
	    $trclass = "k" . ($this->count % 2);
	    $this->count++;

	    $t = "  <tr class=\"pl $trclass\">\n";
	    foreach ($fieldDef as $fieldId => $fdef)
		if ($fdef[1] == 1) {
		    $c = $this->content($fieldId, $row);
		    $t .= "    <td class=\"pl_$fdef[0]\">$c</td>\n";
		    if ($c != "")
			$anyData[$fieldId] = 1;
		}
	    $t .= "  </tr>\n";
	    
	    foreach ($fieldDef as $fieldId => $fdef)
		if ($fdef[1] >= 2 && ($d = $this->content($fieldId, $row))) {
		    $t .= "  <tr class=\"pl_$fdef[0] pl_callout $trclass";
		    if ($fdef[1] == 3 || $fdef[1] == 4)
			$t .= " extension" . ($fdef[1] - 2);
		    $t .= "\">\n";
		    $t .= "<td class=\"pl_$fdef[0] pl_callout\" colspan='$ncol'>";
		    $t .= "<span class='pl_callouthdr'>" . $this->header($fieldId, -1, $row) . ":</span>";
		    $t .= $d . "</td>\n  </tr>\n";
		}

	    $body .= $t;
	}

	$x = "<table class=\"ppltable plt_" . htmlspecialchars($listname);
	if ($this->haveAffrow !== null) {
	    $this->haveAffrow = (defval($_SESSION["foldpplaff"], 0) == 0);
	    $x .= ($this->haveAffrow ? " fold2o" : " fold2c");
	}
	if ($this->haveTopics !== null) {
	    $this->haveTopics = (defval($_SESSION["foldppltopics"], 1) == 0);
	    $x .= ($this->haveTopics ? " fold1o" : " fold1c");
	}
	$x .= "\" id=\"foldppl\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    if ($this->sortable && $url) {
		$sortUrl = $ConfSiteBase . $url . (strpos($url, "?") ? "&" : "?") . "sort=";
		$q = "<a class='pl_sort' href=\"" . $sortUrl;
		foreach ($fieldDef as $fieldId => $fdef) {
		    if ($fdef[1] != 1)
			continue;
		    else if (!isset($anyData[$fieldId])) {
			$x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
			continue;
		    }
		    $x .= "    <th class=\"pl_$fdef[0]\">";
		    $ftext = $this->header($fieldId, $ord++);
		    if ($this->sortField == null && $fieldId == 1)
			$this->sortField = $fieldId;
		    if ($fieldId == $this->sortField)
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' href=\"" . $sortUrl . $fieldId . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($fdef[2])
			$x .= $q . $fieldId . "\">" . $ftext . "</a>";
		    else
			$x .= $ftext;
		    $x .= "</th>\n";
		}
		
	    } else {
		foreach ($fieldDef as $fieldId => $fdef)
		    if ($fdef[1] == 1 && isset($anyData[$fieldId]))
			$x .= "    <th class=\"pl_$fdef[0]\">"
			    . $this->header($fieldId, $ord++) . "</th>\n";
		    else if ($fdef[1] == 1)
			$x .= "    <th class=\"pl_$fdef[0]\"></th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	    $x .= "  <tr><td class='pl_headgap' colspan='$ncol'></td></tr>\n";
	}

	$x .= $body;

	$x .= "  <tr class='pl_footgap k" . (($this->count - 1) % 2) . "'><td class='pl_blank' colspan='$ncol'></td></tr>\n";
	reset($fieldDef);
	if (key($fieldDef) == self::FIELD_SELECTOR)
	    $x .= $this->footer($ncol);
	
	$x .= "</table>";

	return $x;
    }

}


$contactListFields = array(
	ContactList::FIELD_SELECTOR => array('selector', 1, 0),
	ContactList::FIELD_SELECTOR_ON => array('selector', 1, 0),
	ContactList::FIELD_NAME => array('name', 1, 1),
	ContactList::FIELD_EMAIL => array('email', 1, 1),
	ContactList::FIELD_AFFILIATION => array('affiliation', 1, 1),
	ContactList::FIELD_AFFILIATION_ROW => array('affrow', 4, 0),
	ContactList::FIELD_VISITS => array('visits', 1, 1),
	ContactList::FIELD_LASTVISIT => array('lastvisit', 1, 1),
	ContactList::FIELD_HIGHTOPICS => array('topics', 3, 0),
	ContactList::FIELD_LOWTOPICS => array('topics', 3, 0),
	ContactList::FIELD_REVIEWS => array('reviewsStatus', 1, 1),
	ContactList::FIELD_PAPERS => array('papers', 1, 1),
	ContactList::FIELD_REVIEW_PAPERS => array('papers', 1, 1),
	ContactList::FIELD_SCORE => array('score', 1, 1)
	);
