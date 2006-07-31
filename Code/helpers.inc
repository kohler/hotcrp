<?php

function sqlqtrim($value) {
    return mysql_real_escape_string(trim($value));
}

function sqlq($value) {
    return mysql_real_escape_string($value);
}

function sqlq_cleannl($value) {
    $value = str_replace("\r\n", "\n", $value);
    return mysql_real_escape_string(strtr($value, "\r", "\n"));
}

function cvtint(&$value, $default = -1) {
    if (isset($value)) {
	$v = trim($value);
	if (is_numeric($v)) {
	    $ival = intval($v);
	    if ($ival == floatval($v))
		return $ival;
	}
    }
    return $default;
}

function alert($string = "") {
  print "<script language=javascript>\n";
  print "alert(\"" . addslashes($string).  "\");";
  print "</script>";
}

function go($url) {
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">\n";
    echo "<html><head>";
    echo "<script language='javascript'>\n";
    echo "location=\"$url\";";
    echo "</script>";
    echo "</head><body><p>You should be automatically redirected <a href='$url'>to here</a>.</p></body>\n";
    echo "</html>";
    exit();
}

function fileUploaded(&$var, $conf) {
    if (!isset($var) || ($var['error'] != UPLOAD_ERR_OK && !$conf))
	return false;
    switch ($var['error']) {
    case UPLOAD_ERR_OK:
	return is_uploaded_file($var['tmp_name']);
    case UPLOAD_ERR_NO_FILE:
	return false;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
	$conf->errorMsg("You tried to upload a file that's too big for our system to accept.  The maximum size is " . ini_get("upload_max_filesize") . "B.");
	return false;
    case UPLOAD_ERR_PARTIAL:
	$conf->errorMsg("You appear to have interrupted the upload process; I am not storing that file.");
	return false;
    default:
	$conf->errorMsg("Internal upload error " . $var['error'] . "!");
	return false;
    }
}

function selfHref($extra = array()) {
    $x = $_SERVER["PHP_SELF"];
    $sep = "?";
    foreach (array("paperId", "reviewId", "list", "mode", "validator") as $what)
	if (isset($_REQUEST[$what])) {
	    $x .= "$sep$what=" . urlencode($_REQUEST[$what]);
	    $sep = "&";
	}
    foreach ($extra as $key => $value) {
	$x .= "$sep$key=" . urlencode($value);
	$sep = "&";
    }
    return $x;
}

function chairModeLink($text = null, $enterMode = -1) {
    $Me = $_SESSION["Me"];
    if ($enterMode < 0)
	$enterMode = ($Me->amAssistant() ? 1 - (int) $Me->chairMode : 0);

    $out = "<a href=\"" . htmlspecialchars(selfHref(array("chairMode" => $enterMode))) . "\">";
    
    if ($text === null) {
	if ($Me->amAssistant())
	    $text = ($Me->chairMode ? "Leave" : "Enter") . "&nbsp;chair&nbsp;mode";
	else
	    $text = "View&nbsp;as&nbsp;chair";
    }

    return "$out$text</a>";
}


$reviewButton[0] = array
    ("past" => "<a class='review_past' ~>Review&nbsp;past&nbsp;due</a>",
     "start" => "<a class='review_start' ~>Start&nbsp;review</a>",
     "comp" => "<a class='review_comp' ~>Complete&nbsp;review</a>",
     "edit" => "<a class='review_edit' ~>Edit&nbsp;review</a>",
     "view" => "<a class='review_view' ~>See&nbsp;review</a>",
     "author" => "", // <span class='author'>Author</span>",
     "conflict" => "", // <span class='conflict'>Conflict</span>",
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
     //"author" => "",
     //"conflict" => "",
     //"" => "");

function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
	return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
	return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_REQUESTED)
	return "<span class='rtype rtype_req'>Requested</span>";
    else if ($row->author > 0)
	return "<span class='author'>Author</span>";
    else if ($row->conflict > 0)
	return "<span class='conflict'>Conflict</span>";
    else if (!($row->reviewId === null) || $long)
	return "<span class='rtype rtype_pc'>PC</span>";
    else
	return "";
}

function reviewStatus($row, $when = 0, $long = 0, $conf = null) {
    global $Conf;
    $conf = ($conf ? $conf : $Conf);

    if (!isset($row->reviewType) && !$row->reviewId && !$long)
	return "";
    else if ($row->reviewSubmitted === null)
	return "<span class='rstat rstat_no'>Not started</span>";
    else if ($row->reviewSubmitted == 0)
	$x = "<span class='rstat rstat_prog'>In progress</span>";
    else
	$x = "<span class='rstat rstat_done'>Complete</span>";
    if ($when && isset($row->reviewLastModified) && $_SESSION["Me"]->isPC)
	$x .= " <span class='rstat_date'>(" . htmlspecialchars($conf->printableTime(strtotime($row->reviewLastModified))) . ")</span>";
    return $x;
}

function viewContactButton($href, $contactId) {
    $href .= (strpos($href, "?") === false ? "?" : "&amp;") . "viewContact=$contactId";
    return "<a class='button_small' href=\"$href\">View as</a>";
}

function reviewButton($paperId, $row = null, $buttonType = 0, $conf = null) {
    global $Conf, $ConfSiteBase, $reviewButton;
    $conf = ($conf ? $conf : $Conf);
    $Me = $_SESSION["Me"];
    
    if ($row === null && ($row = $conf->paperRow($paperId, $Me->contactId)) === null)
	return "Database error";

    $reviewType = (isset($row->reviewType) ? $row->reviewType : 0);
    $rreq = ($reviewType > 0 || $row->reviewId);
    $timeGood = $Conf->timeReviewPaper($Me->isPC, $rreq, false);
    $timeOK = $Conf->timeReviewPaper($Me->isPC, $rreq, true);
    $need = ($reviewType > 0 || $row->reviewId);
    $conflict = (isset($row->conflict) ? $row->conflict : 0);
    $acknowledged = (isset($row->acknowledged) ? $row->acknowledged : 0);
    $withdrawn = (isset($row->withdrawn) ? $row->withdrawn : 0);
    
    if ($rreq && !$row->reviewSubmitted && !$timeGood)
	$type = "past";
    else if (($rreq && $row->reviewSubmitted === null)
	     || (!$rreq && $conflict <= 0 && $timeOK && $acknowledged > 0 && $withdrawn <= 0))
	$type = "start";
    else if ($rreq && !$row->reviewSubmitted)
	$type = "comp";
    else if ($rreq && $timeOK)
	$type = "edit";
    else if ($rreq)
	$type = "view";
    else if ($Me->isPC && $row->author > 0)
	$type = "author";
    else if ($Me->isPC && $conflict > 0)
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
	$extraQ = " join PaperTopic using (topicId) where PaperTopic.paperId=$paperId";
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

function authorTable($aus, $htmlspecial = true) {
    $out = "";
    if (!is_array($aus))
	$aus = explode("\n", $aus);
    foreach ($aus as $au) {
	$au = trim($au);
	if ($au != '') {
	    $out .= "<span class='autblentry'>";
	    $out .= ($htmlspecial ? htmlspecialchars($au) : $au) . "</span> ";
	}
    }
    return $out;
}

function contactText($firstName, $lastName=null, $email=null) {
    if (is_array($firstName)) {
	$email = $firstName[2];
	$lastName = $firstName[1];
	$firstName = $firstName[0];
    } else if (is_object($firstName)) {
	$email = $firstName->email;
	$lastName = $firstName->lastName;
	$firstName = $firstName->firstName;
    }
    if ($firstName || $lastName) {
	$x = trim("$firstName $lastName");
	if ($email)
	    $x .= " ($email)";
    } else
	$x = $email;
    return $x;
}

function rowContactText($row) {
    return contactText($row->firstName, $row->lastName, isset($row->email) ? $row->email : "");
}

function goPaperForm($baseUrl = "paper.php") {
    global $ConfSiteBase;
    $x = "<form class='gopaper' action='${ConfSiteBase}$baseUrl' method='get'><div>";
    $x .= "#<input class='textlite' type='text' size='3' name='paperId' />";
    $x .= "&nbsp;<input class='button_default' type='submit' name='go' value='Go to paper' /></div></form>";
    return $x;
}

function paperAccepted($prow) {
    return $prow->outcome > 0;
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

function setRet(&$var, $val, $ret = false) {
    $var = $val;
    return $ret;
}

function tabLength($text) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++)
	if ($text[$i] == ' ')
	    $len++;
	else if ($text[$i] == '\t')
	    $len += 8 - ($len % 8);
	else
	    break;
    return $len;
}

function htmlWrapText($text) {
    return nl2br($text);//"<pre>$text</pre>";
    $lines = explode("\n", $text);
    // Rules: Indented line that starts with "-", "*", or "#[.]" starts
    //   indented text.
    //      Other indented text is preformatted.

    // States: -1 initial, 0 normal text, 1 preformatted text, 2 indented text
    $state = -1;
    $savedPar = "";
    $savedParLines = 0;
    $indent = 0;
    $out = "";
    for ($i = 0; $i < count($lines); $i++) {
	$line = $lines[$i];
	if (preg_match("/^\\s*\$/", $line)) {
	    $savedPar .= $line . "\n";
	    $savedParLines++;
	} else if ($state == 1 && ctype_isspace($line[0]))
	    $out .= $line . "\n";
	else if (preg_match("/^(\\s+)(-+|\\*+|\\d+\\.?)\\s/", $line, $matches)) {
	    $x = tabLength($line);
	} 
    }
}

function htmlFold($text, $maxWords) {
    global $foldId;

    if (strlen($text) < $maxWords * 7)
	return $text;
    $words = preg_split('/\\s+/', $text);
    if (count($words) < $maxWords)
	return $text;

    $x = join(" ", array_slice($words, 0, $maxWords));
    
    $fid = (isset($foldId) ? $foldId : 1);
    $foldId = $fid + 1;

    $x .= "<span id='fold$fid' class='folded'><span class='ellipsis'> ... </span><a class='unfolder' href='javascript:fold($fid, 0)'>[More]</a><span class='extension'> " . join(" ", array_slice($words, $maxWords)) . " </span><a class='folder' href='javascript:fold($fid, 1)'>[Less]</a></span>";
    
    return $x;
}

function wordWrapIndent($text, $info, $indent = 18, $width = 67, $rjinfo = true) {
    $text = preg_replace("/^(?!\\Z)/m", str_pad("", $indent), wordwrap($text, $width));
    if (strlen($info) <= $indent) {
	$info = str_pad($info, $indent, " ", ($rjinfo ? STR_PAD_LEFT : STR_PAD_RIGHT));
	return $info . substr($text, $indent);
    } else
	return $info . "\n" . $text;
}

function ini_get_bytes($varname) {
    // from PHP manual
    $val = trim(ini_get($varname));
    $last = strtolower($val[strlen($val)-1]);
    switch ($last) {
    case 'g':
	$val *= 1024; // fallthru
    case 'm':
	$val *= 1024; // fallthru
    case 'k':
	$val *= 1024;
    }
    return $val;
}

function whyNotText($whyNot, $action, $paperId = -1) {
    global $ConfSiteBase, $Conf;
    $thisPaper = ($paperId < 0 ? "this paper" : "paper #$paperId");
    if (!is_array($whyNot))
	$whyNot = array($whyNot => 1);
    $text = '';
    if (isset($whyNot['invalidId']))
	$text .= "Invalid paper number.  ";
    if (isset($whyNot['noPaper']))
	$text .= "No such paper" . ($paperId < 0 ? "" : " #$paperId") . ".  ";
    if (isset($whyNot['withdrawn']))
	$text .= ucfirst($thisPaper) . " has been withdrawn.  ";
    if (isset($whyNot['notWithdrawn']))
	$text .= ucfirst($thisPaper) . " has not been withdrawn.  ";
    if (isset($whyNot['notSubmitted']))
	$text .= ucfirst($thisPaper) . " was never officially submitted.  ";
    if (isset($whyNot['updateSubmitted']))
	$text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated.  ";
    if (isset($whyNot['notUploaded']))
	$text .= ucfirst($thisPaper) . " cannot be submitted until you upload a version of the paper.  ";
    if (isset($whyNot['deadline'])) {
	$dname = $whyNot['deadline'];
	$start = $Conf->startTime[$dname];
	$end = $Conf->endTime[$dname];
	$now = time();
	if ($start > 0 && $now < $start)
	    $text .= "You can't $action $thisPaper until " . $Conf->printableStartTime($dname) . ".  ";
	else if ($end > 0 && $now > $end)
	    $text .= "The deadline to $action $thisPaper has passed.  It was " . $Conf->printableEndTime($dname) . ".  ";
	else
	    $text .= "You can't $action $thisPaper at the moment.  ";
	$text .= "(<a href='${ConfSiteBase}deadlines.php'>View deadlines</a>)  ";
	if (isset($whyNot['override']))
	    $text .= "Select the \"Override deadlines\" checkbox to override this deadline.  ";
    }
    if (isset($whyNot['blindSubmission']))
	$text .= "Submission to this conference is blind.  ";
    if (isset($whyNot['permission']))
	$text .= "You don't have permission to $action $thisPaper.  ";
    if (isset($whyNot['author']))
	$text .= "You aren't an author of $thisPaper.  ";
    if (isset($whyNot['conflict']))
	$text .= "You have a conflict with $thisPaper.  ";
    if (isset($whyNot['reviewNotComplete']))
	$text .= "Your own review for $thisPaper is not complete.  ";
    if (isset($whyNot['reviewNotAssigned']))
	$text .= "You have not been assigned to review $thisPaper.  ";
    // finish it off
    if (isset($whyNot['chairMode']))
	$text .= "You could $action this paper by " . chairModeLink("entering chair mode", 1) . ".  ";
    if (isset($whyNot['forceShow']))
	$text .= "<a href=\"". htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Show reviews anyway</a>  ";
    if ($text && $action == "view")
	$text .= "Enter a paper number above, or <a href='${ConfSiteBase}All/ListPapers.php'>list the papers you can view</a>.  ";
    return ($text ? substr($text, 0, -2) : $text);
}

function array_ensure(&$arr, $val) {
    for ($i = 2; $i < func_num_args(); $i++) {
	$a = func_get_arg($i);
	if (!isset($arr[$a]))
	    $arr[$a] = $val;
    }
}

function contactPulldown($id, $name, $conf, $contact) {
    echo "<input class='textlite' id='${id}_name' name='${name}_name' type='text' value=\"", htmlspecialchars(trim("$contact->firstName $contact->lastName")), "\" />&nbsp;&nbsp;";
    echo "<input class='textlite' id='${id}_email' name='${name}_email' type='text' value=\"", htmlspecialchars($contact->email), "\" />&nbsp;&nbsp;";
    
    $result = $conf->q("select firstName, lastName, email from ContactInfo order by lastName, firstName, email");
    if (!DB::isError($result)) {
	echo "<span class='folded' id='fold$id'><a class='button_small unfolder' href=\"javascript:fold('${id}', 0)\">Show&nbsp;list</a>";
	echo "<span class='contactpulldown extension'>";
	echo "<select id='${id}_pulldown' name='${name}_pulldown' onchange='contactPulldown(\"$id\")' size='6'>";
	while (($row = $result->fetchRow())) {
	    $selected = ($row[2] == $contact->email ? " selected='selected'" : "");
	    if ($row[0] || $row[1]) {
		echo "<option$selected value=\"", htmlspecialchars($row[2]), "`````", htmlspecialchars(trim("$row[0] $row[1]")), "\">", htmlspecialchars(trim("$row[0] $row[1]")), " (", htmlspecialchars($row[2]), ")</option>\n";
	    } else
		echo "<option$selected>", htmlspecialchars($row[2]), "</option>\n";
	}
	echo "</select>&nbsp;<a class='button_small' href=\"javascript:fold('${id}', 1)\">Hide</a></span></span>\n";
    }
}
