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
    
    function PaperList($sortVal = "1", $sortUri = null) {
	$this->showHeader = 1;

	$x = (strlen($sortVal) ? $sortVal[strlen($sortVal)-1] : "");
	$this->reverseSort = ($x == "R");
	if ($x == "R" || $x == "N")
	    $sortVal = substr($sortVal, 0, -1);
	if ((int) $sortVal == $sortVal)
	    $this->sortField = (int) $sortVal;
	else
	    $this->sortField = null;
	$this->sortUri = $sortUri;

	$this->count = 0;
	$this->needFinalize = 0;
	$this->needSubmitReview = 0;
	$this->anySelector = 0;
    }

    function selector($fieldId, &$queryOptions) {
	switch ($fieldId) {
	case self::FIELD_REVIEWER_NAME:
	    $queryOptions['reviewerName'] = 1;
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
	    return "order by Paper.outcome $down";
	case self::FIELD_REVIEWER_TYPE:
	    return "order by ReviewRequest.reviewType $down";
	case self::FIELD_REVIEWER_NAME:
	    return "order by ReviewerContactInfo.lastName, ReviewerContactInfo.firstName $up";
	default:
	    return null;
	}
    }
    
    function header($fieldId, $ordinal) {
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
	    return "Download";
	case self::FIELD_REVIEWER_TYPE:
	    return "Reviewer";
	case self::FIELD_REVIEWER_STATUS:
	    return "Reviewer status";
	case self::FIELD_SELECTOR:
	    return ($ordinal ? "&nbsp;" : "");
	case self::FIELD_REVIEW:
	    return "&nbsp;";
	case self::FIELD_REVIEWER_NAME:
	    return "Reviewer name";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function content($fieldId, $row, $plist) {
	global $ConfSiteBase, $Conf;
	$Me = $_SESSION["Me"];
	switch ($fieldId) {
	case self::FIELD_ID:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId'>#$row->paperId</a>";
	case self::FIELD_ID_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId'>#$row->paperId</a>";
	case self::FIELD_ID_REVIEW:
	    if ($row->reviewId)
		return "<a href='${ConfSiteBase}All/ReviewPaper.php?reviewId=$row->reviewId'>#$row->paperId</a>";
	    else
		return "<a href='${ConfSiteBase}All/ReviewPaper.php?paperId=$row->paperId'>#$row->paperId</a>";
	case self::FIELD_TITLE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_MANAGE:
	    return "<a href='${ConfSiteBase}paper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_REVIEW:
	    if ($row->reviewId)
		return "<a href='${ConfSiteBase}All/ReviewPaper.php?paperId=$row->reviewId'>" . htmlspecialchars($row->title) . "</a>";
	    else
		return "<a href='${ConfSiteBase}All/ReviewPaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_STATUS:
	case self::FIELD_STATUS_SHORT:
	    if ($row->acknowledged <= 0 && $row->withdrawn <= 0)
		$this->needFinalize++;
	    return $Me->paperStatus($row->paperId, $row, $fieldId == self::FIELD_STATUS ? 0 : -1);
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
	case self::FIELD_SELECTOR:
	    $this->anySelector = 1;
	    return "<input class='rstat rstat_sel' type='checkbox' name='papersel[]' value='$row->paperId' />";
	case self::FIELD_REVIEW:
	    return reviewButton($row->paperId, $row);
	case self::FIELD_REVIEWER_NAME:
	    return htmlspecialchars(contactText($row->reviewFirstName, $row->reviewLastName, $row->reviewEmail));
	default:
	    return "";
	}
    }

    function text($listname, $me) {
	global $Conf;

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

	// initialize query
	$queryOptions = array();
	if ($plist->queryType == 'pc')
	    $queryOptions['finalized'] = 1;
	else if ($plist->queryType == 'author' || $plist->queryType == 'myReviews' || $plist->queryType == 'myReviewRequests')
	    $queryOptions[$plist->queryType] = 1;
	
	// get field array
	$result = $Conf->qe(
		"select PaperFields.fieldId, PaperFields.fieldName
		from PaperListColumns left join PaperFields using (fieldId)
		where PaperListColumns.paperListId=$plist->paperListId
		order by PaperListColumns.col", "while looking up paper list information");
	if (DB::isError($result))
	    return NULL;
	
	$fieldId = array();
	while ($row = $result->fetchRow()) {
	    $fieldId[] = array($row[0], htmlspecialchars($row[1]));
	    $this->selector($row[0], $queryOptions);
	}

	// prepare query text
	$pq = $Conf->paperQuery($me->contactId, $queryOptions);

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
		    $x .= "    <th class=\"pl_$f[1]\">"
			. $this->header($f[0], $ord++) . "</th>\n";
	    }
	    
	    $x .= "  </tr>\n";
	}

	if ($result->numRows() == 0)
	    $x .= "  <tr>\n    <td class=\"pl_nopapers\" colspan='" . count($fieldId) . "'>No papers available for this query</td>\n  </tr>\n";
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    $this->count++;
	    $x .= "  <tr>\n";
	    foreach ($fieldId as $f) {
		$x .= "    <td class=\"pl_$f[1]\">";
		$x .= $this->content($f[0], $row, $plist) . "</td>\n";
	    }
	    $x .= "  </tr>\n";
	}

	$x .= "</table>";

	return $x;
    }

}
