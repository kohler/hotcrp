<?php

class PaperList {

    const FIELD_ID = 1;
    const FIELD_TITLE = 2;
    const FIELD_STATUS = 3;
    const FIELD_DOWNLOAD = 4;
    const FIELD_ID_MANAGE = 5;
    const FIELD_TITLE_MANAGE = 6;
    const FIELD_ID_REVIEW = 7;
    const FIELD_TITLE_REVIEW = 8;
    const FIELD_REVIEW_TYPE = 9;
    const FIELD_REVIEW_STATUS = 10;

    var $showHeader;
    var $sortCol;
    var $sortLinks;
    var $count;
    var $needFinalize;
    
    function PaperList() {
	$this->showHeader = 1;
	$this->sortCol = 0;
	$this->sortLinks = 0;
	$this->count = 0;
	$this->needFinalize = 0;
    }
    
    function selector($fieldId, &$queryFields, &$queryTables) {
    }

    function header($fieldId) {
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
	    return "Status";
	case self::FIELD_DOWNLOAD:
	    return "Download";
	case self::FIELD_REVIEW_STATUS:
	    return "Review Status";
	default:
	    return "&lt;$fieldId&gt;?";
	}
    }

    function content($fieldId, $row, $plist) {
	global $ConfSiteBase;
	switch ($fieldId) {
	case self::FIELD_ID:
	    return "<a href='${ConfSiteBase}All/ViewPaper.php?paperId=$row->paperId'>[#$row->paperId]</a>";
	case self::FIELD_ID_MANAGE:
	    return "<a href='${ConfSiteBase}Author/ManagePaper.php?paperId=$row->paperId'>[#$row->paperId]</a>";
	case self::FIELD_ID_REVIEW:
	    return "<a href='${ConfSiteBase}All/ReviewPaper.php?paperId=$row->paperId'>[#$row->paperId]</a>";
	case self::FIELD_TITLE:
	    return "<a href='${ConfSiteBase}All/ViewPaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_MANAGE:
	    return "<a href='${ConfSiteBase}Author/ManagePaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_REVIEW:
	    return "<a href='${ConfSiteBase}All/ReviewPaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_STATUS:
	    if ($row->acknowledged <= 0 && $row->withdrawn <= 0)
		$this->needFinalize++;
	    return $_SESSION['Me']->paperStatus($row->paperId, $row, 0);
	case self::FIELD_DOWNLOAD:
	    if ($row->size > 0)
		return paperDownload($row->paperId, $row);
	    else
		return "";
	case self::FIELD_REVIEW_TYPE:
	    if ($row->myReviewType == REVIEW_PRIMARY)
		return "<span class='rtype rtype_pri'>Primary</span>";
	    else if ($row->myReviewType == REVIEW_SECONDARY)
		return "<span class='rtype rtype_sec'>Secondary</span>";
	    else if ($row->myReviewType == REVIEW_REQUEST)
		return "<span class='rtype rtype_req'>Special request</span>";
	    else
		return "";
	case self::FIELD_REVIEW_STATUS:
	    if ($row->myReviewFinalized == null)
		return "<span class='rstat rstat_no'>Not started</span>";
	    else if ($row->myReviewFinalized == 0)
		return "<span class='rstat rstat_prog'>In progress</span>";
	    else
		return "<span class='rstat rstat_done'>Complete</span>";
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

	// check role type
	if ($plist->minRole >= ROLE_PC && !$me->isPC) {
	    $Conf->errorMsg("Only PC members may view that type of paper list.");
	    return NULL;
	} else if ($plist->minRole >= ROLE_ASSISTANT && !$me->amAssistant()) {
	    $Conf->errorMsg("Only PC chairs and their assistants may view that type of paper list.");
	    return NULL;
	} else if ($plist->minRole >= ROLE_CHAIR && !$me->isChair) {
	    $Conf->errorMsg("Only PC chairs may view that type of paper list.");
	    return NULL;
	}

	// initialize query
	$queryFields = array();
	$queryTables = array();
	
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
	    PaperList::selector($row[0], $queryFields, $queryTables);
	}

	// prepare query text
	if ($plist->minRole == ROLE_PC || $plist->minRole == ROLE_ASSISTANT)
	    $pq = $Conf->paperQuery($me->contactId, array("finalized" => 1));
	else if ($plist->minRole < ROLE_PC)
	    $pq = $Conf->paperQuery($me->contactId, array("author" => 1));
	else if ($plist->minRole == ROLE_REVIEWER)
	    $pq = $Conf->paperQuery($me->contactId, array("reviewer" => 1));
	else
	    $pq = $Conf->paperQuery($me->contactId);

	// make query
	$result = $Conf->qe($pq, "while selecting papers");
	if (DB::isError($result))
	    return NULL;

	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname) . "\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    foreach ($fieldId as $f) {
		$x .= "    <th class=\"pl_$f[1]\">";
		$x .= $this->header($f[0]) . "</th>\n";
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
  
?>