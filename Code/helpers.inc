<?php

function sqlqtrim($value) {
    return mysql_real_escape_string(ltrim(rtrim($value)));
}

function sqlq($value) {
    return mysql_real_escape_string($value);
}

function sqlq_cleannl($value) {
    $value = str_replace("\r\n", "\n", $value);
    return mysql_real_escape_string(strtr($value, "\r", "\n"));
}

function cvtint(&$value, $default = -1) {
    if (isset($value) && is_numeric($value)) {
	$ival = intval($value);
	if ($ival == floatval($value))
	    return $ival;
    }
    return $default;
}

function alert($string = "") {
  print "<script language=javascript>\n";
  print "alert(\"" . addslashes($string).  "\");";
  print "</script>";
}

function go($url) {
    echo "<html><head>";
    echo "<script language='javascript'>\n";
    echo "location=\"$url\";";
    echo "</script>";
    echo "</head><body><p>You should be automatically redirected <a href='$url'>to here</a>.</p></body>\n";
    echo "</html>";
    exit();
}

function fileUploaded(&$var) {
    return isset($var) && $var["tmp_name"] != "none" && $var["tmp_name"] != ""
	&& is_uploaded_file($var["tmp_name"]);
}

function paperStatus($paperId, $row = null, $long = 0, $conf = null) {
    global $Conf, $ConfSiteBase;
    if ($conf == null)
	$conf = $Conf;
    
    if ($row == null) {
	$result = $conf->q("select withdrawn, acknowledged, outcome,
		Paper.paperStorageId, mimetype, PaperStatus.timestamp
		from Paper
		left join PaperStorage using (paperStorageId)
		where Paper.paperId=$paperId");
	if (DB::isError($result))
	    return "<span class='pstat pstat_err'>Database error</span>";
	else if ($result->numRows() == 0)
	    return "<span class='pstat pstat_no'>No such paper</span>";
	$row = $result->fetchRow(DB_FETCHMODE_OBJECT);
    }

    $canViewDecision = 0;

    if ($row->withdrawn > 0) {
	$x = "<span class='pstat pstat_with'>Withdrawn</span>";
	return $x . ($long ? " at " . $conf->printTime($row->withdrawn) : '');
    } else if ($row->acknowledged <= 0 && $row->paperStorageId == 1)
	return "<span class='pstat pstat_noup'>Not yet uploaded</span>";
    else {
	if ($row->acknowledged > 0) {
	    $x = "<span class='pstat pstat_sub'>Submitted</span>";
	    if ($long)
		$x .= " at " . $conf->printTime($row->acknowledged);
	} else {
	    $x = "<span class='pstat pstat_prog'>In progress</span>";
	    if ($long)
		$x .= "; updated at " . $conf->printTime($row->timestamp);
	}
	/* $x .= " <a href='" . $conf->makeDownloadPath($paperId, $row->mimetype) . "'>[";
	if ($row->mimetype == 'application/pdf')
	    $x .= "PDF";
	else if ($row->mimetype == 'application/postscript')
	    $x .= "PostScript";
	else if ($row->mimetype == 'text/plain')
	    $x .= "Text";
	else
	    $x .= htmlspecialchars($row->mimetype);
	    $x .= " " . round($row->size / 1024) . "&nbsp;kB]</a>"; */
	return $x;
    }
}

$reviewButton[0] = array
    ("past" => "<a class='review_past' ~>Review&nbsp;past&nbsp;due</a>",
     "start" => "<a class='review_start' ~>Start&nbsp;review</a>",
     "comp" => "<a class='review_comp' ~>Complete&nbsp;review</a>",
     "edit" => "<a class='review_edit' ~>Edit&nbsp;review</a>",
     "view" => "<a class='review_view' ~>See&nbsp;review</a>",
     "author" => "<span class='author'>Author</span>",
     "conflict" => "<span class='conflict'>Conflict</span>",
     "" => "");
$reviewButton[1] = array
    ("past" => "<button class='review_past' type='submit'>Review&nbsp;past&nbsp;due</button>",
     "start" => "<button class='review_start' type='submit'>Start&nbsp;review</button>",
     "comp" => "<button class='review_comp' type='submit'>Complete&nbsp;review</button>",
     "edit" => "<button class='review_edit' type='submit'>Edit&nbsp;review</button>",
     "view" => "<button class='review_view' type='submit'>See&nbsp;review</button>",
     "author" => "<button class='review_author' type='button' disabled='disabled'>Review</button>",
     "conflict" => "<button class='review_conflict' type='button' disabled='disabled'>Review</button>",
     "" => "<button class='review_other' type='button' disabled='disabled'>Review</button>");

function reviewType($paperId, $row) {
    if ($row->reviewType == REVIEW_PRIMARY)
	return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
	return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_REQUESTED)
	return "<span class='rtype rtype_req'>Requested</span>";
    else if (!($row->reviewId === null))
	return "<span class='rtype rtype_pc'>PC</span>";
    else
	return "";
}

function reviewStatus($row, $when = 0, $conf = null) {
    global $Conf;
    $conf = ($conf ? $conf : $Conf);
    
    if ($row->reviewType == 0 && $row->reviewId === null)
	return "";
    else if ($row->reviewSubmitted === null)
	return "<span class='rstat rstat_no'>Not started</span>";
    else if ($row->reviewSubmitted == 0)
	$x = "<span class='rstat rstat_prog'>In progress</span>";
    else
	$x = "<span class='rstat rstat_done'>Complete</span>";
    if ($when && isset($row->reviewLastModified) && $_SESSION["Me"]->isPC)
	$x .= " <span class='rstat_date'>(" . htmlspecialchars($conf->printTime(strtotime($row->reviewLastModified))) . ")</span>";
    return $x;
}

function reviewButton($paperId, $row = null, $buttonType = 0, $conf = null) {
    global $Conf, $ConfSiteBase, $reviewButton;
    $conf = ($conf ? $conf : $Conf);
    $Me = $_SESSION["Me"];
    
    if ($row === null && ($row = $conf->getPaperRow($paperId, $Me->contactId)) === null)
	return "Database error";

    $timeGood = $Conf->timeReviewPaper($Me->isPC, $row->reviewType>0, false);
    $timeOK = $Conf->timeReviewPaper($Me->isPC, $row->reviewType>0, true);
    $need = ($row->reviewType > 0 || $row->reviewId);
    if ($need && !$row->reviewSubmitted && !$timeGood)
	$type = "past";
    else if (($need && $row->reviewSubmitted === null)
	     || (!$need && $row->conflict <= 0 && $timeOK && $row->acknowledged > 0 && $row->withdrawn <= 0))
	$type = "start";
    else if ($need && !$row->reviewSubmitted)
	$type = "comp";
    else if ($need && $timeOK)
	$type = "edit";
    else if ($need)
	$type = "view";
    else if ($Me->isPC && $row->author > 0)
	$type = "author";
    else if ($Me->isPC && $row->conflict > 0)
	$type = "conflict";
    else
	$type = "";

    if ($row->reviewId === null)
	$revulink = "href='${ConfSiteBase}All/ReviewPaper.php?paperId=$paperId'";
    else
	$revulink = "href='${ConfSiteBase}All/ReviewPaper.php?reviewId=$row->reviewId'";
    return str_replace("~", $revulink, $reviewButton[$buttonType][$type]);
}

function paperDownload($paperId, $row = null, $long = 0, $conf = null) {
    global $Conf, $ConfSiteBase;
    if ($conf == null)
	$conf = $Conf;
    
    if ($row == null) {
	$result = $conf->q("select length(PaperStorage.paper) as size, mimetype
		from Paper left join PaperStorage using (paperStorageId)
		where Paper.paperId=$paperId");
	if (DB::isError($result))
	    return "Database error";
	$row = $result->fetchRow(DB_FETCHMODE_OBJECT);
    }

    $p = $conf->makeDownloadPath($paperId, $row->mimetype);
    $x = "<a class='download' href='" . $p . "'>View</a>&nbsp;";
    $x .= "<a class='download' href='" . $p . "?save=1'>Save&nbsp;to&nbsp;disk</a>";
    if ($long && (isset($row->size) || isset($row->mimetype))) {
	$x .= "&nbsp;(";
	if (isset($row->size))
	    $x .= round($row->size/1024) . "kB" . (isset($row->mimetype) ? "&nbsp;" : "");
	if (isset($row->mimetype))
	    $x .= htmlspecialchars($conf->getFileTypeAbbrev($row->mimetype));
	$x .= ")";
    }
    return $x;
}

function topicTable($paperId = -1, $active = 0, $conf = null) {
    global $Conf;
    if ($conf == null)
	$conf = $Conf;

    // get current topics
    $extraQ = '';
    if ($paperId > 0 && $active < 0)
	$extraQ = ", PaperTopic where PaperTopic.paperId=$paperId and PaperTopic.topicId=TopicArea.topicId";
    else if ($paperId > 0) {
	$result = $Conf->q("select topicId from PaperTopic where paperId=$paperId");
	if (!DB::isError($result))
	    while ($row = $result->fetchRow())
		$paperTopic[$row[0]] = 1;
    }

    $result = $Conf->q("select TopicArea.topicId, topicName from TopicArea$extraQ order by topicName");
    if (!DB::isError($result) && $result->numRows() > 0) {
	$out = "<table class='topictable'><tr>\n	<td>";
	$colheight = (int) (($result->numRows() + 1) / 2);
	for ($i = 0; $i < $result->numRows(); $i++) {
	    if ($i > 0 && ($i % $colheight) == 0)
		$out .= "</td>\n	<td>";
	    $row = $result->fetchRow();
	    if ($paperId <= 0 || $active >= 0) {
		$out .= "<input type='checkbox' name='top$row[0]' value='1' ";
		if ($active > 0 ? isset($_REQUEST["top$row[0]"]) : isset($paperTopic[$row[0]]))
		    $out .= "checked='checked' ";
		if ($active < 0)
		    $out .= "disabled='disabled' ";
		$out .= "onclick='highlightUpdate()' />&nbsp;";
	    }
	    $out .= htmlspecialchars($row[1]) . "<br/>\n";
	}
	return $out . "</td>\n</tr></table>";
    } else
	return "";
}

function authorTable($aus, $columns = 2) {
    $out = "";
    if (!is_array($aus))
	$aus = explode("\n", $aus);
    foreach ($aus as $au) {
	$au = ltrim(rtrim($au));
	if ($au != '')
	    $out .= "<span class='autblentry'>" . htmlspecialchars($au) . "</span> ";
    }
    return $out;
}

function goPaperForm($review = 0) {
    global $ConfSiteBase;
    $what = ($review ? 'Review' : 'View');
    $x = "<form class='gopaper' action='${ConfSiteBase}All/${what}Paper.php' method='get'>";
    $x .= "#<input class='textlite' type='text' size='3' name='paperId' />";
    $x .= "&nbsp;<input class='button_default' type='submit' name='go' value='Go to paper' /></form>";
    return $x;
}

function paperAccepted($prow) {
    return $prow->outcome == 'accepted' || $prow->outcome == 'acceptedShort';
}

function textArrayJoin($arr) {
    if (count($arr) == 1)
	return $arr[0];
    else if (count($arr) == 2)
	return $arr[0] . " and " . $arr[1];
    else
	return join(", ", array_slice($arr, 0, -1)) . ", and " . $arr[count($arr) - 1];
}

function tempdir($dir, $prefix, $mode = 0700) {
    if (!isset($dir) || !is_dir($dir))
	$dir = "/tmp";
    if (substr($dir, -1) != '/')
	$dir .= '/';
    for ($i = 0; $i < 100; $i++) {
	$path = $dir . $prefix . mt_rand(0, 9999999);
	if (mkdir($path, $mode))
	    return $path;
    }
    return false;
}

function plural($n, $what) {
    if ($n == 1)
	return "$n $what";
    else
	return "$n ${what}s";
}

?>