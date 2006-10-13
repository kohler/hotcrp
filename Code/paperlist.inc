<?php

class PaperList {

    const FIELD_ID = 1;
    const FIELD_ID_MANAGE = 2;
    const FIELD_ID_REVIEW = 3;
    const FIELD_TITLE = 11;
    const FIELD_TITLE_MANAGE = 12;
    const FIELD_TITLE_REVIEW = 13;
    const FIELD_TITLE_REVIEW_DOWNLOAD = 14;
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
    const FIELD_MATCHES = 42;
    const FIELD_DESIRABILITY = 43;
    const FIELD_ALL_PREFERENCES = 44;
    const FIELD_REVIEWER_TYPE_ICON = 45;
    const FIELD_OPT_MERIT_ICON = 46;

    var $showHeader;
    var $sortField;
    var $reverseSort;
    var $sortable;
    var $count;
    var $needFinalize;
    var $needSubmitReview;
    var $anySelector;
    var $shortDescription;
    var $description;
    var $sessionVar;
    var $contact;
    var $scoresOk;
    var $meritMax;
    
    function PaperList($sortable = true, $sessionVar = null) {
	$this->showHeader = 1;

	$s = ($sortable ? defval($_REQUEST["sort"], "") : "");
	$x = (strlen($s) ? $s[strlen($s)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $sortVal = substr($s, 0, -1);
	if ((int) $s == $s)
	    $this->sortField = (int) $s;
	else
	    $this->sortField = null;
	$this->sortable = $sortable;

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
	case self::FIELD_MATCHES:
	    $queryOptions['joinTable'] = 'Matches';
	    break;
	case self::FIELD_DESIRABILITY:
	    $queryOptions['desirability'] = 1;
	    break;
	case self::FIELD_ALL_PREFERENCES:
	    $queryOptions['allReviewerPreference'] = 1;
	    break;
	case self::FIELD_OPT_MERIT_ICON:
	    if (!$this->scoresOk)
		return false;
	    $queryOptions['overallMeritScores'] = 1;
	    $rf = reviewForm();
	    $this->meritMax = $rf->maxNumericScore('overAllMerit');
	    break;
	}
	if ($this->sortField == $fieldId && ($order = $this->sort($fieldId)))
	    $queryOptions['order'] = $order;
	return true;
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
	case self::FIELD_TITLE_REVIEW_DOWNLOAD:
	    return "order by Paper.title $up";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "order by Paper.outcome $down, (Paper.timeWithdrawn > 0) $up, (Paper.timeSubmitted > 0) $down, (paperStorageId > 1) $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_TYPE:
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "order by PaperReview.reviewType $down";
	case self::FIELD_REVIEWER_NAME:
	    return "order by ReviewerContactInfo.lastName $up, ReviewerContactInfo.firstName $up, ReviewerContactInfo.email $up";
	case self::FIELD_REVIEWS_STATUS:
	    return "order by reviewCount $up, startedReviewCount $up";
	case self::FIELD_ASSIGN_REVIEW:
	    return "order by PaperReview.reviewType $down, Paper.paperId $up";
	case self::FIELD_TOPIC_INTEREST:
	    return "order by topicInterestScore $down, Paper.paperId $up";
	case self::FIELD_REVIEWER_PREFERENCE:
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    return "order by reviewerPreference $down";
	case self::FIELD_DESIRABILITY:
	    return "order by desirability $down";
	case self::FIELD_OPT_MERIT_ICON:
	    return "order by overallMeritAvg $down";
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
	case self::FIELD_TITLE_REVIEW_DOWNLOAD:
	    return "Title";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    return "Status";
	case self::FIELD_DOWNLOAD:
	    return "";
	case self::FIELD_REVIEWER_TYPE:
	    return "Review";
	case self::FIELD_REVIEWER_TYPE_ICON:
	    return "Rev";
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
	case self::FIELD_DESIRABILITY:
	    return "Desirability";
	case self::FIELD_ALL_PREFERENCES:
	    return "Preferences";
	case self::FIELD_OPT_MERIT_ICON:
	    return "Merit!";
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
	case self::FIELD_TITLE_REVIEW_DOWNLOAD:
	    $t = "<a href='${ConfSiteBase}review.php?paperId=$row->paperId' tabindex='3'>" . htmlspecialchars($row->title) . "</a>";
	    if ($row->size == 0)
		/* nothing */;
	    else if ($row->mimetype == "application/pdf")
		$t .= "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/pdf.png\" alt='[PDF]' /></a>";
	    else if ($row->mimetype == "application/postscript")
		$t .= "&nbsp;<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/postscript.png\" alt='[PS]' /></a>";
	    else
		$t .= "&nbsp;<small><a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">[Download]</a></small>";
	    return $t;
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0)
		$this->needFinalize++;
	    return $this->contact->paperStatus($row->paperId, $row, $fieldId == self::FIELD_STATUS ? 0 : -1);
	case self::FIELD_DOWNLOAD:
	    if ($row->size == 0)
		return "";
	    else if ($row->mimetype == "application/pdf")
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/pdf.png\" alt='[PDF]' /></a>";
	    else if ($row->mimetype == "application/postscript")
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\"><img src=\"${ConfSiteBase}images/postscript.png\" alt='[PS]' /></a>";
	    else
		return "<a href=\"" . $Conf->makeDownloadPath($row->paperId, $row->mimetype) . "\">Paper</a>";
	case self::FIELD_REVIEWER_TYPE:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if ($row->reviewId) {
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId\">";
		$a2 = "</a>&nbsp;";
		if ($row->reviewSubmitted > 0)
		    $a2 .= "<span class='rstat rstat_done'>(Complete)</span>";
		else if ($row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit == 0)
		    $a2 .= "<span class='rstat rstat_no'>(Delegated)</span>";
		else if ($row->reviewModified == 0)
		    $a2 .= "<span class='rstat rstat_no'>(Not&nbsp;started)</span>";
		else
		    $a2 .= "<span class='rstat rstat_prog'>(In&nbsp;progress)</span>";
	    } else
		$a1 = $a2 = "";
	    if ($row->reviewType == REVIEW_PRIMARY)
		return "$a1<span class='rtype rtype_pri'>Primary</span>$a2";
	    else if ($row->reviewType == REVIEW_SECONDARY)
		return "$a1<span class='rtype rtype_sec'>Secondary</span>$a2";
	    else if ($row->reviewType == REVIEW_REQUESTED)
		return "$a1<span class='rtype rtype_req'>Requested</span>$a2";
	    else if ($row->reviewType)
		return "$a1<span class='rtype'>Review</span>$a2";
	    else if ($row->author > 0)
		return "<span class='rtype rtype_con'>Author</span>";
	    else if ($row->conflict > 0)
		return "<span class='rtype rtype_con'>Conflict</span>";
	    else
		return "";
	case self::FIELD_REVIEWER_TYPE_ICON:
	    if ($row->reviewType > 0 && (!isset($row->reviewSubmitted) || !$row->reviewSubmitted))
		$this->needSubmitReview++;
	    if ($row->reviewId) {
		$a1 = "<a href=\"${ConfSiteBase}review.php?reviewId=$row->reviewId\">";
		$a2 = "</a>";
	    } else
		$a1 = $a2 = "";
	    $which = ($row->reviewType === null ? 0 : $row->reviewType);
	    return "$a1<img src='${ConfSiteBase}images/ass$which.png' />$a2";
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
	    $rt = ($row->conflict > 0 ? -1 : min(max($row->reviewType, 0), REVIEW_PRIMARY));
	    $x = "<select name='assrev$row->paperId' tabindex='1' onchange='highlightUpdate()'>";
	    $x .= "<option value='0'" . ($rt == 0 ? " selected='selected'" : "") . ">None</option>";
	    $x .= "<option value='" . REVIEW_PRIMARY . "'" . ($rt == REVIEW_PRIMARY ? " selected='selected'" : "") . ">Primary</option>";
	    $x .= "<option value='" . REVIEW_SECONDARY . "'" . ($rt == REVIEW_SECONDARY ? " selected='selected'" : "") . ">Secondary</option>";
	    $x .= "<option value='-1'" . ($rt == -1 ? " selected='selected'" : "") . ">Conflict</option>";
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
			    $n .= "&nbsp;<img src='${ConfSiteBase}images/ass" . REVIEW_PRIMARY . ".png' alt='Primary' />";
			else if ($xrow->reviewType == REVIEW_SECONDARY)
			    $n .= "&nbsp;<img src='${ConfSiteBase}images/ass" . REVIEW_SECONDARY . ".png' alt='Secondary' />";
		    }
	    return $n;
	case self::FIELD_REVIEWER_PREFERENCE:
	    return (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	case self::FIELD_EDIT_REVIEWER_PREFERENCE:
	    if ($row->conflict > 0)
		return "N/A";
	    $x = (isset($row->reviewerPreference) ? htmlspecialchars($row->reviewerPreference) : "0");
	    return "<input class='textlite revpref' type='text' size='4' name='revpref$row->paperId' value=\"$x\" />";
	case self::FIELD_DESIRABILITY:
	    return (isset($row->desirability) ? htmlspecialchars($row->desirability) : "0");
	case self::FIELD_ALL_PREFERENCES:
	    $x = "";
	    foreach (explode(';;;', $row->allReviewerPreference) as $rp)
		if (preg_match("/(.*):::(.*):::(.*)/", $rp, $what)
		    && $what[3] != 0) {
		    $x .= ($x ? ", " : "");
		    $x .= contactHtml($what[2], $what[1]);
		    $x .= " [" . htmlspecialchars($what[3]) . "]";
		}
	    return $x;
	case self::FIELD_OPT_MERIT_ICON:
	    return $Conf->textValuesGraph($row->overallMeritScores, $this->meritMax, 1);
	default:
	    return "";
	}
    }

    function updatePaperLists() {
	global $Conf;
	if (!isset($_SESSION["paperLists"])
	    || defval($Conf->startTime["paperListUpdate"]) <= 0
	    || $_SESSION["paperLists"]["date"] <= $Conf->startTime["paperListUpdate"]) {
	    $pl = array();
	    $result = $Conf->qe("select * from PaperList");
	    if (!DB::isError($result))
		while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT)))
		    $pl[$row->paperListName] = $row;
	    $pl["date"] = defval($Conf->startTime["paperListUpdate"]);
	    $_SESSION["paperLists"] = $pl;
	}
    }

    function listContact($listname) {
	self::updatePaperLists();
	if (($plist = defval($_SESSION["paperLists"][$listname])))
	    return $plist->listContact;
	else
	    return '';
    }

    function listContactType($listname) {
	self::updatePaperLists();
	if (($plist = defval($_SESSION["paperLists"][$listname])))
	    return $plist->listContactType;
	else
	    return '';
    }
    
    function text($listname, $me, $contactId = -1) {
	global $Conf, $ConfSiteBase;

	$this->contact = $me;
	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = 0;

	// get paper list id
	self::updatePaperLists();
	if (!($plist = defval($_SESSION["paperLists"][$listname]))) {
	    $Conf->errorMsg("There is no paper list query named '" . htmlspecialchars($listname) . "'.");
	    return NULL;
	}
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
	$this->scoresOk = $me->amAssistant() || ($me->isPC && $Conf->validTimeFor("PCGradePapers", 0));

	// initialize query
	$queryOptions = array();
	if ($plist->queryType == 'pc')
	    $queryOptions['finalized'] = 1;
	else if ($plist->queryType == 'author' || $plist->queryType == 'myReviews' || $plist->queryType == 'myReviewRequests')
	    $queryOptions[$plist->queryType] = 1;

	// get URL
	$url = $ConfSiteBase . $plist->listHome;
	if ($plist->listContact && $contactId != $me->contactId)
	    $url .= (strpos($url, "?") ? "&" : "?") . $plist->listContact . "=" . $contactId;
	$url = preg_replace_callback("|(\\w+)=\\*|", create_function('$m', 'return $m[1] . "=" . urlencode($_REQUEST[$m[1]]);'), $url);
	
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
	    if ($this->selector($row[0], $queryOptions) === false)
		continue;
	    $fieldId[] = array($row[0], htmlspecialchars($row[1]), $row[2]);
	    if ($row[2] == 1)
		$ncol++;
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

	if ($result->numRows() == 0)
	    return "No papers";
	// $x .= "  <tr class=\"pl_firstrow\">\n    <td class=\"pl_nopapers\" colspan='$ncol'>No papers available for this query</td>\n  </tr>\n";

	$ids = array();
	$anyData = array();
	$body = '';
	while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	    $ids[] = $row->paperId;
	    $trclass = "k" . ($this->count % 4 < 2 ? 0 : 1);
	    $this->count++;
	    
	    $body .= "  <tr class=\"" . $trclass;
	    if ($this->showHeader && $this->count == 1)
		$body .= " pl_firstrow";
	    $body .= "\">\n";
	    foreach ($fieldId as $f)
		if ($f[2] == 1) {
		    $c = $this->content($f[0], $row, $plist, $reviewList);
		    $body .= "    <td class=\"pl_$f[1]\">$c</td>\n";
		    if ($c != "")
			$anyData[$f[0]] = 1;
		}
	    $body .= "  </tr>\n";
	    
	    foreach ($fieldId as $f)
		if ($f[2] == 2 && ($d = $this->content($f[0], $row, $plist, $reviewList))) {
		    $body .= "  <tr class=\"pl_$f[1] pl_callout $trclass\">\n";
		    $body .= "<td class=\"pl_$f[1] pl_callout\" colspan='$ncol'>";
		    $body .= "<span class='pl_callouthdr'>" . $this->header($f[0], -1, $row, $reviewList) . "</span>";
		    $body .= $d . "</td>\n  </tr>\n";
		}
	}

	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname) . "\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    $ord = 0;

	    if ($this->sortable && $url) {
		$sortUrl = $url . (strpos($url, "?") ? "&" : "?") . "sort=";
		$q = "<a class='pl_sort' href=\"" . $sortUrl;
		foreach ($fieldId as $f) {
		    if ($f[2] != 1)
			continue;
		    else if (!isset($anyData[$f[0]])) {
			$x .= "    <th class=\"pl_$f[1]\"></th>\n";
			continue;
		    }
		    $x .= "    <th class=\"pl_$f[1]\">";
		    $ftext = $this->header($f[0], $ord++);
		    if ($this->sortField == null && $f[0] < 10)
			$this->sortField = $f[0];
		    if ($f[0] == $this->sortField)
			$x .= "<a class='pl_sort_def" . ($this->reverseSort ? "_rev" : "") . "' href=\"" . $sortUrl . $f[0] . ($this->reverseSort ? "N" : "R") . "\">" . $ftext . "</a>";
		    else if ($this->sort($f[0]))
			$x .= $q . $f[0] . "\">" . $ftext . "</a>";
		    else
			$x .= $ftext;
		    $x .= "</th>\n";
		}
		
	    } else {
		foreach ($fieldId as $f)
		    if ($f[2] == 1 && isset($anyData[$f[0]]))
			$x .= "    <th class=\"pl_$f[1]\">"
			    . $this->header($f[0], $ord++) . "</th>\n";
		    else if ($f[2] == 1)
			$x .= "    <th class=\"pl_$f[1]\"></th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	}

	$x .= $body . "</table>";

	// session variable to remember the list
	if ($this->sessionVar) {
	    if (isset($_REQUEST["sort"]))
		$url .= (strpos($url, "?") ? "&" : "?") . "sort=" . urlencode($_REQUEST["sort"]);
	    $_SESSION[$this->sessionVar] = $ids;
	    $_SESSION[$this->sessionVar . "!"] = array($url, $this->shortDescription);
	}
	
	return $x;
    }

}
