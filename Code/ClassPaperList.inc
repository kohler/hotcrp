<?php

class PaperList {

    const FIELD_ID = 1;
    const FIELD_ID_MANAGE = 2;
    const FIELD_ID_REVIEW = 3;
    const FIELD_TITLE = 11;
    const FIELD_TITLE_MANAGE = 12;
    const FIELD_TITLE_REVIEW = 13;
    const FIELD_STATUS = 27;
    const FIELD_DOWNLOAD = 28;
    const FIELD_REVIEWER_TYPE = 29;
    const FIELD_REVIEWER_STATUS = 30;
    const FIELD_SELECTOR = 31;
    const FIELD_REVIEW = 32;
    const FIELD_STATUS_SHORT = 33;
    const FIELD_REVIEWER_NAME = 34;
    const FIELD_ASSIGN_REVIEW = 35;
    const FIELD_TOPIC_INTEREST = 36;
    const FIELD_TOPIC_NAMES = 37;
    const FIELD_ALL_REVIEWER_NAMES = 38;
    const FIELD_REVIEWER_PREFERENCE = 39;
    const FIELD_EDIT_REVIEWER_PREFERENCE = 40;
    const FIELD_REVIEWS_STATUS = 41;

    var $showHeader;
    var $sortField;
    var $reverseSort;
    var $sortUri;
    var $count;
    var $needFinalize;
    var $needSubmitReview;
    var $anySelector;
    var $shortDescription;
    var $description;
    var $sessionVar;
    var $contact;
    
    function PaperList($sortVal = "1", $sortUri = null, $sessionVar = null) {
	$this->showHeader = 1;

	$s = ($sortVal !== null ? $sortVal : "");
	$x = (strlen($s) ? $sortVal[strlen($s)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $sortVal = substr($s, 0, -1);
	if ((int) $s == $s)
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortUri = $sortUri;

	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = 0;
	$this->sessionVar = $sessionVar;
    }

    function selector($fieldId, &$queryOptions) {
	switch ($fieldId) {
	case self::FIELD_REVIEWER_NAME:
	    $queryOptions['reviewerName'] = 1;
	    break;
	case self::FIELD_TOPIC_INTEREST:
	    $queryOptions['topicInterestScore'] = 1;
	    break;
	case self::FIELD_TOPIC_NAMES:
	    $queryOptions['topics'] = 1;
	    break;
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $queryOptions['reviewList'] = 1;
	    break;
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    $queryOptions['reviewerPreference'] = 1;
	    break;
	}
	if ($this->sortField == $fieldId && ($order = $this->sort($fieldId)))
	    $queryOptions['order'] = $order;
    }

    function sort($fieldId) {
	$up = ($this->reverseSort ? "desc" : "asc");
	$down = ($this->reverseSort ? "asc" : "desc");
	switch ($fieldId) {
	case self::FIELD_ID:
	case self::FIELD_ID_MANAGE:
	case self::FIELD_ID_REVIEW:
	    return "order by Paper.paperId $up";
	case self::FIELD_TITLE:
	case self::FIELD_TITLE_MANAGE:
	case self::FIELD_TITLE_REVIEW:
	    return "order by Paper.title $up";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "order by Paper.outcome $down, (Paper.timeWithdrawn > 0) $up, (Paper.timeSubmitted > 0) $down, (paperStorageId > 1) $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_TYPE:
	    return "order by PaperReview.reviewType $down";
	case self::FIELD_REVIEWER_NAME:
	    return "order by ReviewerContactInfo.lastName $up, ReviewerContactInfo.firstName $up";
	case self::FIELD_REVIEWS_STATUS:
	    return "order by reviewCount $up, startedReviewCount $up";
	case self::FIELD_ASSIGN_REVIEW:
	    return "order by PaperReview.reviewType $down, Paper.paperId $up";
	case self::FIELD_TOPIC_INTEREST:
	    return "order by topicInterestScore $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "order by reviewerPreference $down";
	default:
	    return null;
	}
    }
    
    function header($fieldId, $ordinal, $row = null, $reviewList = null) {
	switch ($fieldId) {
	case self::FIELD_ID:
	case self::FIELD_ID_MANAGE:
	case self::FIELD_ID_REVIEW:
	    return "ID";
	case self::FIELD_TITLE:
	case self::FIELD_TITLE_MANAGE:
	case self::FIELD_TITLE_REVIEW:
	    return "Title";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "Status";
	case self::FIELD_DOWNLOAD:
	    return "";
	case self::FIELD_REVIEWER_TYPE:
	    return "Reviewer";
	case self::FIELD_REVIEWER_STATUS:
	    return "Reviewer status";
	case self::FIELD_REVIEWS_STATUS:
	    return "<acronym title='\"1/2\" means 1 complete review out of 2 assigned reviews'>#&nbsp;Reviews</acronym>";
	case self::FIELD_SELECTOR:
	    return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_REVIEW:
	    return "&nbsp;";
	case self::FIELD_REVIEWER_NAME:
	    return "Reviewer name";
	case self::FIELD_ASSIGN_REVIEW:
	    return "Assignment";
	case self::FIELD_TOPIC_INTEREST:
	    return "Topic<br/>score";
	case self::FIELD_TOPIC_NAMES:
	    return "Topics";
	case self::FIELD_ALL_REVIEWER_NAMES:
	    if ($row === null || !isset($reviewList[$row->paperId]))
		return "Reviewers";
	    else
		return "Reviewers (" . count($reviewList[$row->paperId]) . ")";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "Preference";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }
    
    function content($fieldId, $row, $plist, $reviewList) {
	global $ConfSiteBase, $Conf;
	switch ($fieldId) {
	case self::FIELD_ID:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_ID_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_ID_REVIEW:
	    return "<a href='${ConfSiteBase}review.php?paperId=$row->paperId' tabindex='2'>#$row->paperId</a>";
	case self::FIELD_TITLE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId' tabindex='3'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId' tabindex='3'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_REVIEW:
	    return "<a href='${ConfSiteBase}review.php?paperId=$row->paperId' tabindex='3'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    return $this->contact->paperStatus($row->paperId, $row, $fieldId == self::FIELD_STATUS ? 0 : -1);
	case self::FIELD_DOWNLOAD:
	    if ($row->size > 0)
		return paperDownload($row->paperId, $row);
	    else
		return "";
	case self::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    return reviewType($row->paperId, $row);
	case self::FIELD_REVIEWER_STATUS:
	    return reviewStatus($row, 0);
	case self::FIELD_REVIEWS_STATUS:
	    if ($row->reviewCount != $row->startedReviewCount)
		return "<b>$row->reviewCount</b>/$row->startedReviewCount";
	    else
		return "<b>$row->reviewCount</b>";
	case self::FIELD_SELECTOR:
	    $this->anySelector = 1;
	    return "<input class='rstat rstat_sel' type='checkbox' name='papersel[]' value='$row->paperId' tabindex='1' />";
	case self::FIELD_REVIEW:
	    return reviewButton($row->paperId, $row);
	case self::FIELD_REVIEWER_NAME:
	    return contactHtml($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail);
	case self::FIELD_ASSIGN_REVIEW:
	    if ($row->author > 0)
		return "<span class='author'>Author</span>";
	    else if ($row->conflict > 0)
		return "<span class='conflict'>Conflict</span>";
	    $x = "<select name='assrev$row->paperId' tabindex='1' onchange='highlightUpdate()'>";
	    $x .= "<option value='0'" . ($row->reviewType <= 0 ? " selected='selected'" : "") . ">None</option>";
	    $x .= "<option value='" . REVIEW_PRIMARY . "'" . ($row->reviewType == REVIEW_PRIMARY ? " selected='selected'" : "") . ">Primary</option>";
	    $x .= "<option value='" . REVIEW_SECONDARY . "'" . ($row->reviewType == REVIEW_SECONDARY ? " selected='selected'" : "") . ">Secondary</option>";
	    $x .= "</select>";
	    return $x;
	case self::FIELD_TOPIC_INTEREST:
	    return htmlspecialchars($row->topicInterestScore + 0);
	case self::FIELD_TOPIC_NAMES:
	    $rf = reviewForm();
	    return join(", ", $rf->webTopicArray($row->topicIds, defval($row->topicInterest)));
	case self::FIELD_ALL_REVIEWER_NAMES:
	    $n = "";
	    if (isset($reviewList[$row->paperId]))
		foreach ($reviewList[$row->paperId] as $xrow)
		    if ($xrow->lastName) {
			$n .= ($n ? ", " : "");
			$n .= contactHtml($xrow->firstName, $xrow->lastName);
			if ($xrow->reviewType == REVIEW_PRIMARY)
			    $n .= "<sub><b>1</b></sub>";
			else if ($xrow->reviewType == REVIEW_SECONDARY)
			    $n .= "<sub><b>2</b></sub>";
		    }
	    return $n;
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflict > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite revpref' type='text' size='4' name='revpref$row->paperId' value=\"$x\" />";
	default:
	    return "";
	}
    }

    function text($listname, $me, $contactId = -1) {
	global $Conf;
	$this->contact = $me;

	// get paper list id
	$result = $Conf->qe("select * from PaperList where paperListName='" . sqlq($listname) . "'", "while looking up list type");
	if (DB::isError($result))
	    return NULL;
	else if ($result->numRows() == 0) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return NULL;
	}
	$plist = $result->fetchRow(DB_FETCHMODE_OBJECT);
	$this->description = $plist->description;
	$this->shortDescription = $plist->shortDescription;

	// check role type
	if ($plist->queryType == 'pc' && !$me->isPC) {
	    $Conf->errorMsg("Only PC members may view this type of paper list.");
	    return NULL;
	} else if ($plist->queryType == 'chair' && !$me->amAssistant()) {
	    $Conf->errorMsg("Only PC chairs and their assistants may view this type of paper list.");
	    return NULL;
	}
	if (!$me->amAssistant() || $contactId < 0)
	    $contactId = $me->contactId;

	// initialize query
	$queryOptions = array();
	if ($plist->queryType == 'pc')
	    $queryOptions['finalized'] = 1;
	else if ($plist->queryType == 'author' || $plist->queryType == 'myReviews' || $plist->queryType == 'myReviewRequests')
	    $queryOptions[$plist->queryType] = 1;
	
	// get field array
	$result = $Conf->qe(
		"select PaperFields.fieldId, fieldName, display
		from PaperListColumns join PaperFields using (fieldId)
		where PaperListColumns.paperListId=$plist->paperListId
		order by PaperListColumns.col", "while looking up paper list information");
	if (DB::isError($result))
	    return NULL;
	
	$fieldId = array();
	$ncol = 0;
	while ($row = $result->fetchRow()) {
	    $fieldId[] = array($row[0], htmlspecialchars($row[1]), $row[2]);
	    if ($row[2] == 1)
		$ncol++;
	    $this->selector($row[0], $queryOptions);
	}

	// prepare review query
	$reviewList = array();
	if (isset($queryOptions['reviewList'])) {
	    $result = $Conf->qe("select Paper.paperId, reviewId, reviewType,
		reviewSubmitted, reviewModified,
		PaperReview.contactId, lastName, firstName, email
		from Paper
		join PaperReview using (paperId)
		join ContactInfo on (PaperReview.contactId=ContactInfo.contactId)
		where " . ($plist->queryType == 'pc' ? "timeSubmitted>0" : "paperId=-1") . "
		order by lastName, firstName, email", "while fetching reviews");
	    if (!DB::isError($result))
		while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
		    $reviewList[$row->paperId][] = $row;
	}
	
	// prepare query text
	if ($me->contactId == $contactId)
	    $pq = $Conf->paperQuery($me, $queryOptions);
	else
	    $pq = $Conf->paperQuery($contactId, $queryOptions);

	// make query
	$result = $Conf->qe($pq, "while selecting papers");
	if (DB::isError($result))
	    return NULL;

	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname) . "\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    if ($this->sortUri) {
		$q = "<a class='pl_sort' href=\"" . $this->sortUri;
		foreach ($fieldId as $f) {
		    if ($f[2] == 2)
			continue;
		    $x .= "    <th class=\"pl_$f[1]\">";
		    $ftext = $this->header($f[0], $ord++);
		    if ($this->sortField == null && $f[0] < 10)
			$this->sortField = $f[0];
		    if ($f[0] == $this->sortField)
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' href=\"" . $this->sortUri . $f[0] . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($this->sort($f[0]))
			$x .= $q . $f[0] . "\">" . $ftext . "</a>";
		    else
			$x .= $ftext;
		    $x .= "</th>\n";
		}
		
	    } else {
		foreach ($fieldId as $f)
		    if ($f[2] != 2)
			$x .= "    <th class=\"pl_$f[1]\">"
			    . $this->header($f[0], $ord++) . "</th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	}

	if ($result->numRows() == 0)
	    $x .= "  <tr class=\"pl_firstrow\">\n    <td class=\"pl_nopapers\" colspan='$ncol'>No papers available for this query</td>\n  </tr>\n";

	$ids = array();
	$trclass = ($this->showHeader ? " class=\"pl_firstrow\"" : "");
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    $this->count++;
	    $ids[] = $row->paperId;
	    $x .= "  <tr$trclass>\n";
	    $trclass = "";
	    foreach ($fieldId as $f)
		if ($f[2] != 2) {
		    $x .= "    <td class=\"pl_$f[1]\">";
		    $x .= $this->content($f[0], $row, $plist, $reviewList) . "</td>\n";
		}
	    $x .= "  </tr>\n";
	    foreach ($fieldId as $f)
		if ($f[2] == 2 && ($d = $this->content($f[0], $row, $plist, $reviewList))) {
		    $x .= "  <tr class=\"pl_$f[1] pl_callout\">\n";
		    $x .= "<td class=\"pl_$f[1] pl_callout\" colspan='$ncol'>";
		    $x .= "<span class='pl_callouthdr'>" . $this->header($f[0], -1, $row, $reviewList) . "</span>";
		    $x .= $d . "</td>\n  </tr>\n";
		}
	}

	$x .= "</table>";

	if ($this->sessionVar) {
	    $_SESSION[$this->sessionVar] = $ids;
	    $_SESSION[$this->sessionVar . "!"] = array($listname, $this->shortDescription);
	}
	
	return $x;
    }

}
