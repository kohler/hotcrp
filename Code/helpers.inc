<?php

function sqlqtrim($value) {
    return mysql_real_escape_string(ltrim(rtrim($value)));
}

function sqlq($value) {
    return mysql_real_escape_string($value);
}

function cvtint(&$value, $default = -1) {
    if (isset($value) && is_numeric($value))
	return (int) $value;
    else
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
	$result = $conf->q("select withdrawn, acknowledged, Paper.paperStorageId, mimetype, PaperStatus.timestamp from Paper, PaperStorage where Paper.paperId=$paperId and PaperStorage.paperStorageId=Paper.paperStorageId");
	if (DB::isError($result))
	    return "<span class='pstat pstat_err'>Database error</span>";
	else if ($result->numRows() == 0)
	    return "<span class='pstat pstat_no'>No such paper</span>";
	$row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    }
    
    if ($row['withdrawn'] > 0) {
	$x = "<span class='pstat pstat_with'>Withdrawn</span>";
	return $x . ($long ? " at " . $conf->printTime($row['withdrawn']) : '');    } else if ($row['acknowledged'] <= 0 && $row['paperStorageId'] == 1)
	return "<span class='pstat pstat_noup'>Not yet uploaded</span>";
    else {
	if ($row['acknowledged'] > 0) {
	    $x = "<span class='pstat pstat_sub'>Submitted</span>";
	    if ($long)
		$x .= " at " . $conf->printTime($row['acknowledged']);
	} else {
	    $x = "<span class='pstat pstat_prog'>In progress</span>";
	    if ($long)
		$x .= "; updated at " . $conf->printTime($row['timestamp']);
	}
	/* $x .= " <a href='" . $conf->makeDownloadPath($paperId, $row['mimetype']) . "'>[";
	if ($row['mimetype'] == 'application/pdf')
	    $x .= "PDF";
	else if ($row['mimetype'] == 'application/postscript')
	    $x .= "PostScript";
	else if ($row['mimetype'] == 'text/plain')
	    $x .= "Text";
	else
	    $x .= htmlspecialchars($row['mimetype']);
	    $x .= " " . round($row['size'] / 1024) . "&nbsp;kB]</a>"; */
	return $x;
    }
}
    
function paperDownload($paperId, $row = null, $conf = null) {
    global $Conf, $ConfSiteBase;
    if ($conf == null)
	$conf = $Conf;
    
    if ($row == null) {
	$result = $conf->q("select length(PaperStorage.paper) as size, mimetype from Paper, PaperStorage where Paper.paperId=$paperId and PaperStorage.paperStorageId=Paper.paperStorageId");
	if (DB::isError($result))
	    return "Database error";
	$row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    }

    $p = $conf->makeDownloadPath($paperId, $row['mimetype']);
    $x = "<a class='download' href='" . $p . "'>View</a>&nbsp;";
    $x .= "<a class='download' href='" . $p . "?save=1'>Save&nbsp;to&nbsp;disk</a>";
    if (isset($row['size']) || isset($row['mimetype'])) {
	$x .= "&nbsp;(";
	if (isset($row['size']))
	    $x .= round($row['size']/1024) . "kB" . (isset($row['mimetype']) ? "&nbsp;" : "");
	if (isset($row['mimetype']))
	    $x .= htmlspecialchars($conf->getFileTypeAbbrev($row['mimetype']));
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
    

?>