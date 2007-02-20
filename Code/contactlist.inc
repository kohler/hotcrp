<?php
// contactlist.inc -- HotCRP helper class for producing lists of contacts
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ContactList {

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
    
    var $showHeader;
    var $sortField;
    var $sortInfo;
    var $sortAverage;
    var $reverseSort;
    var $sortable;
    var $count;
    var $anySelector;
    var $contact;
    var $scoreMax;
    var $authorMatch;
    var $collaboratorsMatch;
    var $unfolded;
    var $limit;
    
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
    }

    function _normalizeField($fieldId) {
	return $fieldId;
    }
    
    function selector($fieldId, &$queryOptions) {
	if (!$this->contact->isPC
	    && $fieldId != self::FIELD_NAME && $fieldId != self::FIELD_AFFILIATION)
	    return false;
	if ($fieldId == self::FIELD_HIGHTOPICS || $fieldId == self::FIELD_LOWTOPICS)
	    $queryOptions['topics'] = true;
	if ($fieldId == self::FIELD_REVIEWS)
	    $queryOptions['reviews'] = true;
	return true;
    }

    function _sortName($a, $b) {
	$x = strcasecmp($a->lastName, $b->lastName);
	$x = $x ? $x : strcasecmp($a->firstName, $b->firstName);
	return $x ? $x : strcasecmp($a->email, $b->email);
    }
    
    function _sortEmail($a, $b) {
	return strcasecmp($a->email, $b->email);
    }
    
    function _sortAffiliation($a, $b) {
	$x = strcasecmp($a->affiliation, $b->affiliation);
	return $x ? $x : self::_sortName($a, $b);
    }

    function _sortVisits($a, $b) {
	$x = $b->visits - $a->visits;
	return $x ? $x : self::_sortName($a, $b);
    }
 
    function _sortLastVisit($a, $b) {
	$x = $b->lastLogin - $a->lastLogin;
	return $x ? $x : self::_sortName($a, $b);
    }

    function _sortReviews($a, $b) {
	$x = $b->numReviewsSubmitted - $a->numReviewsSubmitted;
	$x = $x ? $x : $b->numReviews - $a->numReviews;
	return $x ? $x : self::_sortName($a, $b);
    }

    function _sort($rows) {
	global $Conf;
	switch (self::_normalizeField($this->sortField)) {
	case self::FIELD_EMAIL:
	    usort($rows, array("ContactList", "_sortEmail"));
	    break;
	case self::FIELD_AFFILIATION:
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
	}
	if ($this->reverseSort)
	    return array_reverse($rows);
	else
	    return $rows;
    }
    
    function header($fieldId, $ordinal, $row = null) {
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_NAME:
	    return "Name";
	case self::FIELD_EMAIL:
	    return "Email";
	case self::FIELD_AFFILIATION:
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
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }
    
    function content($fieldId, $row) {
	global $ConfSiteBase, $Conf, $reviewTypeName, $paperListScoreNames;
	switch (self::_normalizeField($fieldId)) {
	case self::FIELD_NAME:
	    $t = contactHtml($row->firstName, $row->lastName);
	    if ($this->contact->amAssistant())
		$t = "<a href='${ConfSiteBase}account.php?contact=$row->contactId'>$t</a>";
	    if ($row->chair)
		$t .= " <span class='pcrole'>(Chair)</span>";
	    else if ($row->assistant)
		$t .= " <span class='pcrole'>(Chair's assistant)</span>";
	    else if ($row->pc && $this->limit != "pc")
		$t .= " <span class='pcrole'>(PC)</span>";
	    return $t;
	case self::FIELD_EMAIL:
	    if (!$this->contact->isPC)
		return "";
	    return "<a href=\"mailto:" . htmlspecialchars($row->email) . "\">" . htmlspecialchars($row->email) . "</a>";
	case self::FIELD_AFFILIATION:
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
	    if ($fieldId == self::FIELD_SELECTOR_ON) {
		$c = " checked='checked'";
		$this->unfolded = true;
	    }
	    return "<input type='checkbox' name='cid[]' value='$row->contactId' tabindex='1' id='psel$this->count' onclick='pselClick(event, this, $this->count)' $c/>";
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
	    if (count($nt)) {
		$this->unfolded = false;
		return join(", ", $rf->webTopicArray($nt, array_fill(0, count($nt), $want)));
	    } else
		return "";
	case self::FIELD_REVIEWS:
	    if (!$row->numReviews && !$row->numReviewsSubmitted)
		return "";
	    $a1 = "<a href=\"${ConfSiteBase}search.php?t=s&amp;q=re:" . urlencode($row->email) . "\">";
	    if ($row->numReviews == $row->numReviewsSubmitted)
		return "$a1<b>$row->numReviewsSubmitted</b></a>";
	    else
		return "$a1<b>$row->numReviewsSubmitted</b>/$row->numReviews</a>";
	default:
	    return "";
	}
    }

    function listFields($listname) {
	switch ($listname) {
	  case "pc":
	    return array("pc", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS);
	  case "re":
	    return array("re", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS);
	  case "ext":
	    return array("ext", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS);
	  case "req":
	    return array("req", self::FIELD_SELECTOR, self::FIELD_NAME, self::FIELD_EMAIL, self::FIELD_AFFILIATION, self::FIELD_VISITS, self::FIELD_LASTVISIT, self::FIELD_HIGHTOPICS, self::FIELD_LOWTOPICS, self::FIELD_REVIEWS);
	  default:
	    return null;
	}
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
	$this->unfolded = true;
	$queryOptions = array();
	foreach ($baseFieldId as $fid) {
	    if ($this->selector($fid, $queryOptions) === false)
		continue;
	    $normFid = self::_normalizeField($fid);
	    $fieldDef[$fid] = $contactListFields[$normFid];
	    if ($contactListFields[$normFid][1] == 1)
		$ncol++;
	}
	$unfolded = $this->unfolded;

	// prepare query text
	$pq = "select ContactInfo.contactId,
	firstName, lastName, email, affiliation,
	voicePhoneNumber, faxPhoneNumber,
	collaborators, lastLogin, visits, note,
	PCMember.contactId as pc, Chair.contactId as chair,
	ChairAssistant.contactId as assistant";
	if (isset($queryOptions['topics']))
	    $pq .= ",\n	topicIds, topicInterest";
	if (isset($queryOptions['reviews']))
	    $pq .= ",
	count(coalesce(PaperReview.reviewSubmitted,nullif(PaperReview.reviewNeedsSubmit,0))) as numReviews,
	count(PaperReview.reviewSubmitted) as numReviewsSubmitted";
	
	$pq .= "\n	from ContactInfo
	" . ($this->limit == "pc" ? "join" : "left join") . " PCMember on (PCMember.contactId=ContactInfo.contactId)
	left join Chair on (Chair.contactId=ContactInfo.contactId)
	left join ChairAssistant on (ChairAssistant.contactId=ContactInfo.contactId)\n";
	if (isset($queryOptions['topics']))
	    $pq .= "	left join (select contactId, group_concat(topicId) as topicIds, group_concat(interest) as topicInterest from TopicInterest group by contactId) as TopicInterests on (TopicInterests.contactId=ContactInfo.contactId)\n";
	if (isset($queryOptions['reviews'])) {
	    $j = "left join";
	    if ($this->limit == "re" || $this->limit == "req" || $this->limit == "ext")
		$j = "join";
	    $pq .= "	$j PaperReview on (PaperReview.contactId=ContactInfo.contactId";
	    if ($this->limit == "req" || $this->limit == "ext")
		$pq .= " and PaperReview.reviewType=" . REVIEW_EXTERNAL;
	    if ($this->limit == "req")
		$pq .= " and PaperReview.requestedBy=" . $this->contact->contactId;
	    $pq .= ")\n";
	}
	
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
	$foldclass = defval($_SESSION["pplFold"], 1);
	$extrainfo = false;
	foreach ($srows as $row) {
	    $trclass = "k" . ($this->count % 2);
	    $this->count++;
	    $this->unfolded = $unfolded;

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
		    if ($fdef[1] == 3) {
			$extrainfo = true;
			$t .= " extension1";
		    }
		    $t .= "\">\n";
		    $t .= "<td class=\"pl_$fdef[0] pl_callout\" colspan='$ncol'>";
		    $t .= "<span class='pl_callouthdr'>" . $this->header($fieldId, -1, $row) . ":</span>";
		    $t .= $d . "</td>\n  </tr>\n";
		}

	    if (!$this->unfolded)
		$t = str_replace("<tr class=\"", "<tr class=\"extension3 ", $t);

	    $body .= $t;
	}

	$x = "<table class=\"ppltable plt_" . htmlspecialchars($listname);
	if ($extrainfo)
	    $x .= " fold1c";
	if (!$unfolded)
	    $x .= " fold3c";
	if ($extrainfo || !$unfolded)
	    $x .= "\" id=\"foldppl";
	$x .= "\">\n";

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

	if ($extrainfo || ($this->limit == "pc" && $this->contact->amAssistant())) {
	    $x .= "  <tr class='pl_footgap k" . (($this->count - 1) % 2) . "'><td class='pl_blank' colspan='$ncol'></td></tr>\n";
	    $x .= "  <tr class='pl_footrow'>\n    <td colspan='$ncol'>";
	    if ($this->limit == "pc" && $this->contact->amAssistant())
		$x .= "<a href='${ConfSiteBase}account.php?new=1&amp;pc=1'>Add program committee member</a>";
	    if ($extrainfo && ($this->limit == "pc" && $this->contact->amAssistant()))
		$x .= "<span class='sep'></span>";
	    if ($extrainfo)
		$x .= "<a class='unfolder1' href='javascript:fold(\"ppl\",0,1)'>Show topic interests</a><a class='folder1' href='javascript:fold(\"ppl\",1,1)'>Hide topic interests</a>";
	    $x .= "</td></tr>\n";
	}
	
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
	ContactList::FIELD_VISITS => array('visits', 1, 1),
	ContactList::FIELD_LASTVISIT => array('lastvisit', 1, 1),
	ContactList::FIELD_HIGHTOPICS => array('topics', 3, 0),
	ContactList::FIELD_LOWTOPICS => array('topics', 3, 0),
	ContactList::FIELD_REVIEWS => array('reviewsStatus', 1, 1)
	);
