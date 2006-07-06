<?php

class PaperList {

    const FIELD_ID = 1;
    const FIELD_TITLE = 2;
    const FIELD_STATUS = 3;
    const FIELD_DOWNLOAD = 4;
    const FIELD_ID_MANAGE = 5;
    const FIELD_TITLE_MANAGE = 6;

    var $showHeader;
    var $sortCol;
    var $sortLinks;
    
    function PaperList($showHeader = 1, &$sortCol = NULL, $sortLinks = '') {
	$this->showHeader = $showHeader;
	$this->sortCol = (isset($sortCol) ? (int) $sortCol : 0);
	$this->sortLinks = $sortLinks;
    }
    
    function selector($fieldId, &$queryFields, &$queryTables) {
    }

    function header($fieldId) {
	switch ($fieldId) {
	case self::FIELD_ID:
	case self::FIELD_ID_MANAGE:
	    return "ID";
	case self::FIELD_TITLE:
	case self::FIELD_TITLE_MANAGE:
	    return "Title";
	case self::FIELD_STATUS:
	    return "Status";
	case self::FIELD_DOWNLOAD:
	    return "Download";
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
	case self::FIELD_TITLE:
	    return "<a href='${ConfSiteBase}All/ViewPaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_TITLE_MANAGE:
	    return "<a href='${ConfSiteBase}Author/ManagePaper.php?paperId=$row->paperId'>" . htmlspecialchars($row->title) . "</a>";
	case self::FIELD_STATUS:
	    return paperStatus($row->paperId, $row);
	case self::FIELD_DOWNLOAD:
	    if ($row->size > 0)
		return paperDownload($row->paperId, $row);
	    else
		return "";
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
	if ($plist->minRole < ROLE_PC)
	    $queryTables["join Roles on (Roles.contactId=$me->contactId and Roles.paperId=Paper.paperId and Roles.role=" . ROLE_AUTHOR . ")"] = 1;
	$pq = "select Paper.*, length(PaperStorage.paper) as size, mimetype";
	if (count($queryFields) > 0)
	    $pq .= ", " . join(", ", array_keys($queryFields));
	$pq .= " from Paper left join PaperStorage using (paperStorageId)";
	if (count($queryTables) > 0)
	    $pq .= " " . join(" ", array_keys($queryTables));
	if ($plist->minRole == ROLE_PC || $plist->minRole == ROLE_ASSISTANT)
	    $pq .= " where acknowledged>0 and withdrawn<=0";

	// make query
	$result = $Conf->qe($pq, "while selecting papers");
	if (DB::isError($result))
	    return NULL;

	$x = "<table class=\"pltable plt_" . htmlspecialchars($listname) . "\">\n";

	if ($this->showHeader) {
	    $x .= "  <tr class=\"pl_headrow\">\n";
	    foreach ($fieldId as $f) {
		$x .= "    <th class=\"pl_$f[1]\">";
		$x .= PaperList::header($f[0]) . "</th>\n";
	    }
	    $x .= "  </tr>\n";
	}

	if ($result->numRows() == 0)
	    $x .= "  <tr>\n    <td class=\"pl_nopapers\">No papers available for this query</td>\n  </tr>\n";
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    $x .= "  <tr>\n";
	    foreach ($fieldId as $f) {
		$x .= "    <td class=\"pl_$f[1]\">";
		$x .= PaperList::content($f[0], $row, $plist) . "</td>\n";
	    }
	    $x .= "  </tr>\n";
	}

	$x .= "</table>";

	return $x;
    }

}
  
?>