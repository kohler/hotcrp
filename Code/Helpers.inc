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

function paperUploaded($size, $mimetype) {
    return $size > 100 || $mimetype != "text/plain";
}

function paperStatus($paperId, $row = null, $long = 0, $conf = null) {
    global $Conf, $ConfSiteBase;
    if ($conf == null)
	$conf = $Conf;
    
    if ($row == null) {
	$result = $conf->q("select withdrawn, acknowledged, length(PaperStatus.paper) as size, mimetype, PaperStatus.timestamp from Paper, PaperStorage where Paper.paperId=$paperId and PaperStorage.paperStorageId=Paper.paperStorageId");
	if (DB::isError($result))
	    return "Database error";
	$row = $result->fetchRow(DB_FETCHMODE_ASSOC);
    }
    
    if ($row['withdrawn'] > 0) {
	if ($long)
	    return "Withdrawn at " . $conf->printTime($row['withdrawn']);
	else
	    return "Withdrawn";
    } else if ($row['acknowledged'] <= 0 && !paperUploaded($row['size'], $row['mimetype']))
	return "Not yet uploaded";
    else {
	if ($row['acknowledged'] > 0) {
	    if ($long)
		$x = "Finalized at " . $conf->printTime($row['acknowledged']);
	    else
		$x = "Finalized";
	} else {
	    if ($long)
		$x = "Not finalized; updated at " . $conf->printTime($row['timestamp']);
	    else
		$x = "Not finalized";
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
    $x .= "<a class='download' href='" . $p . "?save=1'>Save</a>&nbsp;";
    $x .= "(" . round($row['size']/1024) . "kB&nbsp;" . htmlspecialchars($conf->getFileTypeAbbrev($row['mimetype'])) . ")";
    return $x;
}
    
?>