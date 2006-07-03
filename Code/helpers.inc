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

function normalizePeopleList($list) {
    $out = '';
    foreach (preg_split('/[\r\n]+/', $list) as $x) {
	$x = ltrim(trim($x));
	if ($x == '')
	    continue;
	$out .= $x . "\n";
    }
    return $out;
}

function paperUploaded($size, $mimetype) {
    return $size > 100 || $mimetype != "text/plain";
}

function paperStatus($paperId, $row = null, $long = 0, $conf = null) {
    global $Conf, $ConfSiteBase;
    if ($conf == null)
	$conf = $Conf;
    
    if ($row == null) {
	$result = $conf->q("select withdrawn, acknowledged, length(PaperStatus.paper) as size, mimetype from Paper, PaperStorage where Paper.paperId=$paperId and PaperStorage.paperStorageId=Paper.paperStorageId");
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
	}
	$x .= "<a href='${ConfSiteBase}Download/GetPaper.php?paperId=";
	$x = ($row['acknowledged'] > 0 ? "Finalized " : 0);
    }
}
    
?>