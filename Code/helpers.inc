<?php

function vtrim(&$var) {
    return (isset($var) ? trim($var) : "");
}

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

function mkarray($value) {
    if (is_array($value))
	return $value;
    else
	return array($value);
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


$reviewButton[0] = array
    ("past" => "<a class='rev_past' ~>Review&nbsp;overdue</a>",
     "start" => "<a class='rev_start' ~>Start&nbsp;review</a>",
     "comp" => "<a class='rev_comp' ~>Complete&nbsp;review</a>",
     "edit" => "<a class='rev_edit' ~>Edit&nbsp;review</a>",
     "view" => "<a class='rev_view' ~>See&nbsp;review</a>",
     "author" => "", // <span class='author'>Author</span>",
     "conflict" => "", // <span class='conflict'>Conflict</span>",
     "" => "");
$reviewButton[1] = array
    ("past" => "<button class='rev_past' type='submit'>Review&nbsp;past&nbsp;due</button>",
     "start" => "<button class='rev_start' type='submit'>Start&nbsp;review</button>",
     "comp" => "<button class='rev_comp' type='submit'>Complete&nbsp;review</button>",
     "edit" => "<button class='rev_edit' type='submit'>Edit&nbsp;review</button>",
     "view" => "<button class='rev_view' type='submit'>See&nbsp;review</button>",
     "author" => "<button class='rev_author' type='button' disabled='disabled'>Review</button>",
     "conflict" => "<button class='rev_conflict' type='button' disabled='disabled'>Review</button>",
     "" => "<button class='rev_other' type='button' disabled='disabled'>Review</button>");
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
    else if ($row->reviewModified == 0)
	return "<span class='rstat rstat_no'>Not started</span>";
    else if ($row->reviewSubmitted == 0)
	$x = "<span class='rstat rstat_prog'>In progress</span>";
    else
	$x = "<span class='rstat rstat_done'>Complete</span>";
    if ($when && $row->reviewModified > 0 && $_SESSION["Me"]->isPC)
	$x .= " <span class='rstat_date'>(" . htmlspecialchars($conf->printableTime($row->reviewModified)) . ")</span>";
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
	$revulink = "href='${ConfSiteBase}review.php?paperId=$paperId'";
    else
	$revulink = "href='${ConfSiteBase}review.php?reviewId=$row->reviewId'";
    $button = $reviewButton[$buttonType][$type];
    if ($reviewType == REVIEW_PRIMARY) {
	$button = str_replace("review", "primary&nbsp;review", $button);
	$button = str_replace("Review", "Primary&nbsp;review", $button);
    } else if ($reviewType == REVIEW_SECONDARY) {
	$button = str_replace("review", "secondary&nbsp;review", $button);
	$button = str_replace("Review", "Secondary&nbsp;review", $button);
    }
    return str_replace("~", $revulink, $button);
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
	$email = isset($firstName->email) ? $firstName->email : "";
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

function goPaperForm($baseUrl = "paper.php", $args = array()) {
    global $ConfSiteBase;
    $x = "<form class='gopaper' action='${ConfSiteBase}$baseUrl' method='get'><div class='inform'>";
    foreach ($args as $what => $val)
	$x .= "<input type='hidden' name=\"" . htmlspecialchars($what) . "\" value=\"" . htmlspecialchars($val) . "\" />";
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

function whyNotText($whyNot, $action) {
    global $ConfSiteBase, $Conf;
    if (!is_array($whyNot))
	$whyNot = array($whyNot => 1);
    $paperId = (isset($whyNot['paperId']) ? $whyNot['paperId'] : -1);
    $reviewId = (isset($whyNot['reviewId']) ? $whyNot['reviewId'] : -1);
    $thisPaper = ($paperId < 0 ? "this paper" : "paper #$paperId");
    $text = '';
    if (isset($whyNot['invalidId'])) {
	$x = $whyNot['invalidId'] . "Id";
	$xid = (isset($whyNot[$x]) ? " \"" . $whyNot[$x] . "\"" : "");
	$text .= "Invalid " . $whyNot['invalidId'] . " number" . htmlspecialchars($xid) . ".  ";
    }
    if (isset($whyNot['noPaper']))
	$text .= "No such paper" . ($paperId < 0 ? "" : " #$paperId") . ".  ";
    if (isset($whyNot['noReview']))
	$text .= "No such review" . ($reviewId < 0 ? "" : " #$reviewId") . ".  ";
    if (isset($whyNot['dbError']))
	$text .= $whyNot['dbError'] . "  ";
    if (isset($whyNot['permission']))
	$text .= "You don't have permission to $action $thisPaper.  ";
    if (isset($whyNot['withdrawn']))
	$text .= ucfirst($thisPaper) . " has been withdrawn.  ";
    if (isset($whyNot['notWithdrawn']))
	$text .= ucfirst($thisPaper) . " has not been withdrawn.  ";
    if (isset($whyNot['notSubmitted']))
	$text .= ucfirst($thisPaper) . " was never officially submitted.  ";
    if (isset($whyNot['updateSubmitted']))
	$text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated.  ";
    if (isset($whyNot['notUploaded']))
	$text .= ucfirst($thisPaper) . " can't be submitted because you haven't yet uploaded the paper itself.  Upload the paper and try again.  ";
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
    if (isset($whyNot['author']))
	$text .= "You aren't a contact author for $thisPaper.  ";
    if (isset($whyNot['conflict']))
	$text .= "You have a conflict with $thisPaper.  ";
    if (isset($whyNot['reviewNotComplete']))
	$text .= "Your own review for $thisPaper is not complete, so you can't view other people's reviews.  ";
    if (isset($whyNot['reviewNotAssigned']))
	$text .= "You have not been assigned to review $thisPaper.  ";
    if (isset($whyNot['differentReviewer']))
	$text .= "You didn't write this review, so you can't change it.  ";
    // finish it off
    if (isset($whyNot['chairMode']))
	$text .= "<a href=\"" . selfHref(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>  ";
    if (isset($whyNot['forceShow']))
	$text .= "<a href=\"". htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Show reviews anyway</a>  ";
    if ($text && $action == "view")
	$text .= "Enter a paper number above, or <a href='${ConfSiteBase}list.php'>list the papers you can view</a>.  ";
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

function actionTab($text, $url, $default) {
    $sep = "    <td class='sep'></td>\n";
    if ($default)
	return "$sep    <td class='tab_default' nowrap='nowrap'><a href='$url'>$text</a></td>\n";
    else
	return "$sep    <td class='tab' nowrap='nowrap'><a href='$url'>$text</a></td>\n";
}

function actionBar($prow, $newPaper, $mode, $thisUrl = "") {
    global $Me, $Conf, $ConfSiteBase;
    if ($newPaper)
	$paperId = "new";
    else
	$paperId = ($prow == null ? -1 : $prow->paperId);
    $disableView = (!$newPaper && $paperId < 0);

    $x = "<table class='vubar'><tr>\n  <td><table><tr>\n";
    
    if (!$newPaper && $paperId > 0)
	$x .= actionTab("View", "${ConfSiteBase}paper.php?paperId=$paperId&amp;mode=view", $mode == "view");
    
    if ($newPaper || ($paperId > 0 && ($prow->author > 0 || $Me->amAssistant())))
	$x .= actionTab("Edit", "${ConfSiteBase}paper.php?paperId=$paperId&amp;mode=edit", $mode == "edit");
    
    if ($newPaper || !$prow)
	/* do nothing */;
    else if (isset($prow->reviewType) && $prow->reviewType > 0) {
	$name = ($prow->reviewModified ? "Review" : "Add review");
	$x .= actionTab($name, "${ConfSiteBase}review.php?paperId=$paperId", $mode == "editreview");
    } else if ((($Me->isPC && $Conf->timePCReviewAnyPaper() && $prow->author <= 0 && $prow->conflict <= 0)
		|| $Me->amAssistant())
	       && $prow->acknowledged > 0 && $prow->withdrawn <= 0)
	$x .= actionTab("Add review", "${ConfSiteBase}review.php?paperId=$paperId", $mode == "editreview");
    
    if (!$newPaper && $prow && ($Me->isPC || $Me->canViewReview($prow, null, $Conf))
	&& $prow->acknowledged > 0 && $prow->withdrawn <= 0)
	$x .= actionTab("Read reviews" . ($prow ? " ($prow->reviewCount)" : ""), "${ConfSiteBase}paper.php?paperId=$paperId&amp;mode=reviews", $mode == "reviews");
    
    if ($thisUrl)
	$x .= actionTab($mode, $thisUrl, true);

    $goBase = ($mode == "editreview" ? "review.php" : "paper.php");
    $xmode = (isset($_REQUEST["mode"]) ? array("mode" => $_REQUEST["mode"]) : array());
    $x .= "  </tr></table></td>\n  <td class='spanner'></td>\n  <td class='gopaper' nowrap='nowrap'>" . goPaperForm($goBase, $xmode) . "</td>\n</tr></table>\n";
    return $x;
}

function unparseReviewOrdinal($ord) {
    if ($ord < 26)
	return chr($ord + 65);
    else
	return chr(int($ord / 26) + 65) . chr(($ord % 26) + 65);
}

function reviewersTable($prow, $reviewId = null, $all = false, $buttons = false) {
    global $Conf, $Me, $ConfSiteBase;
    
    $result = $Conf->qe("select ContactInfo.contactId, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		ReqContactInfo.contactId as reqContactId, ReqContactInfo.firstName as reqFirstName, ReqContactInfo.lastName as reqLastName, ReqContactInfo.email as reqEmail,
		reviewId, reviewType, reviewModified, reviewSubmitted
		from PaperReview
		join ContactInfo using (contactId)
		left join ContactInfo as ReqContactInfo on (ReqContactInfo.contactId=PaperReview.requestedBy)
		where paperId=$prow->paperId
		order by reviewSubmitted, reviewType desc, lastName, firstName, email");
    if (DB::isError($result))
	return "";
    
    $subrev = array();
    $nonsubrev = array();
    $ordinal = 0;
    $anyThisReview = $anyMyReview = 0;
    $nRetractable = 0;
    $link = ($Me->canViewReview($prow, null, $Conf) || $Me->amAssistant());
    
    while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	// review ID
	$t = "    <tr>";

	// dingbat
	$thisReview = ($reviewId == $row->reviewId);
	$anyThisReview += $thisReview;
	$anyMyReview += ($row->contactId == $Me->contactId);
	if ($reviewId !== null)
	    $t .= ($thisReview ? "<td class='highlight'><b>&#187;</b></td>" : "<td></td>");

	// review ID
	$t .= "<td>" . ($thisReview ? "<b>" : ($link ? "<a href='review.php?reviewId=$row->reviewId'>" : ""));
	$t .= "Review";
	if ($row->reviewSubmitted > 0)
	    $t .= " #$prow->paperId" . unparseReviewOrdinal($ordinal++);
	$t .= ($thisReview ? "</b>" : ($link ? "</a>" : ""));
	$t .= "</td>";

	// reviewer
	if (!$Me->canViewReviewerIdentity($prow, $row, $Conf))
	    $t .= "<td></td>";
	else if ($row->contactId == $Me->contactId)
	    $t .= "<td>You</td>";
	else
	    $t .= "<td>" . contactText($row) . "</td>";

	// review type
	$t .= "<td>";
	if ($prow->author > 0 || $prow->conflict > 0)
	    $t .= "";
	else if ($row->reviewType == REVIEW_PRIMARY)
	    $t .= "Primary";
	else if ($row->reviewType == REVIEW_SECONDARY)
	    $t .= "Secondary";
	else if ($row->reviewType == REVIEW_REQUESTED)
	    $t .= "Requested by " . ($row->reqContactId == $Me->contactId ? "you" : contactText($row->reqFirstName, $row->reqLastName, $row->reqEmail));
	$t .= "</td>";

	// status
	$t .= "<td>";
	if ($row->reviewModified <= 0)
	    $t .= "Not started";
	else if ($row->reviewSubmitted <= 0)
	    $t .= "In progress";
	else
	    $t .= "Complete";
	$t .= "</td>";

	// actions
	$actions = array();
	if ($row->reviewType == REVIEW_REQUESTED
	    && $row->reviewModified <= 0
	    && ($row->requestedBy == $Me->contactId || $Me->amAssistant())) {
	    $actions[] = "<a class='button_small' href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId&amp;retract=$row->reviewId\">Retract review request</a>";
	    $nRetractable++;
	}
	if (count($actions) > 0 && $buttons)
	    $t .= "<td>" . join("", $actions) . "</td>";

	$t .= "</tr>\n";

	// affix
	if ($row->reviewSubmitted <= 0)
	    $nonsubrev[] = $t;
	else
	    $subrev[] = $t;
    }
    
    if (!$anyMyReview && $reviewId !== null)
	if (!$anyThisReview)
	    $nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Review</b></td><td>" . contactText($Me) . "</td><td></td><td>Not started</td></tr>\n";
	else
	    $nonsubrev[] = "    <tr><td></td><td><a href=\"${ConfSiteBase}review.php?paperId=$prow->paperId\">Enter review</a></td><td>" . contactText($Me) . "</td><td></td><td>Not started</td></tr>\n";

    if (count($nonsubrev) || count($subrev))
	return join("", $subrev) . join("", $nonsubrev);
    else
	return "";
}

function downloadText($text, $filename, $description) {
    global $Conf;
    header("Content-Description: $Conf->shortName $description, PHP generated data");
    header("Content-Disposition: attachment; filename=$filename");
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($text));
    print $text;
}
