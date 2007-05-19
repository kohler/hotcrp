<?php
// helpers.inc -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function defval(&$var, $defval = null) {
    return (isset($var) ? $var : $defval);
}

function vtrim(&$var) {
    return (isset($var) ? trim($var) : "");
}


// database helpers

// number of rows returned by a select query, or 'false' if result is an error
function edb_nrows($result) {
    return ($result ? mysql_num_rows($result) : false);
}

// number of rows affected by an update/insert query, or 'false' if result is
// an error
function edb_nrows_affected($result, $conf) {
    return ($result ? mysql_affected_rows($conf->dblink) : false);
}

// next row as an array, or 'false' if no more rows or result is an error
function edb_row($result) {
    return ($result ? mysql_fetch_row($result) : false);
}

// next row as an associative array, or 'false' if no more rows or result is
// an error
function edb_arow($result) {
    return ($result ? mysql_fetch_array($result) : false);
}

// next row as an object, or 'false' if no more rows or result is an error
function edb_orow($result) {
    return ($result ? mysql_fetch_object($result) : false);
}

// quoting for SQL
function sqlq($value) {
    return mysql_real_escape_string($value);
}

function sqlq_for_like($value) {
    return preg_replace("/(?=[%_\\\\'\"\\x00\\n\\r\\x1a])/", "\\", $value);
}

function sqlqtrim($value) {
    return mysql_real_escape_string(trim($value));
}

function sqlq_cleannl($value) {
    $value = str_replace("\r\n", "\n", $value);
    return mysql_real_escape_string(strtr($value, "\r", "\n"));
}


// other helpers
function simplifyWhitespace($x) {
    return trim(preg_replace('/\s+/', " ", $x));
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
    print "<script language='javascript'>\n";
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
    foreach (array("paperId", "reviewId", "commentId", "mode", "forceShow", "validator", "list", "t", "q", "qa", "qx", "qt", "group") as $what)
	if (isset($_REQUEST[$what]) && !isset($extra[$what])) {
	    $x .= "$sep$what=" . urlencode($_REQUEST[$what]);
	    $sep = "&";
	}
    foreach ($extra as $key => $value) {
	$x .= "$sep$key=" . urlencode($value);
	$sep = "&";
    }
    return $x;
}


function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
	return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
	return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_EXTERNAL)
	return "<span class='rtype rtype_req'>External</span>";
    else if ($row->conflictType == CONFLICT_AUTHOR)
	return "<span class='author'>Author</span>";
    else if ($row->conflictType > 0)
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
    else if ($row->reviewModified == 0 && $row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit == 0)
	return "<span class='rstat rstat_no'>Delegated</span>";
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

function paperDownload($prow, $final = false, $conf = null) {
    global $Conf, $ConfSiteBase, $Me;
    $conf = ($conf == null ? $Conf : $conf);
    if ($final && !$prow->finalPaperStorageId)
	$final = false;

    // don't allow PC to download papers in progress
    if ($prow->timeSubmitted <= 0 && !$Me->canDownloadPaper($prow, $conf))
	return "";
    
    $mimetype = defval($prow->mimetype, "");
    $p = $conf->makeDownloadPath($prow->paperId, $mimetype, $final);
    if ($mimetype == "application/postscript")
	$x = "<a href=\"$p\" class='q'><img src='${ConfSiteBase}images/postscript24.png' alt='[PS]' class='dlimg' />";
    else if ($mimetype == "application/pdf")
	$x = "<a href=\"$p\" class='q'><img src='${ConfSiteBase}images/pdf24.png' alt='[PDF]' class='dlimg' />";
    else
	$x .= "<a href=\"$p\">View paper";

    if (($final || !$prow->finalPaperStorageId)	&& isset($prow->size))
	$x .= "&nbsp;<span class='dlsize'>"
	    . round($prow->size/1024) . "kB</span>";

    $x .= "</a>";
    //$x .= "&nbsp;<span class='dlsave'><a href=\"$p?save=1\">Save to disk</a></span>";
    return $x;
}

function topicTable($prow, $active = 0, $conf = null) {
    global $Conf;
    $rf = reviewForm();
    if ($conf == null)
	$conf = $Conf;
    $paperId = ($prow ? $prow->paperId : -1);

    // read from paper row if appropriate
    if ($paperId > 0 && $active < 0 && isset($prow->topicIds)) {
	$top = $rf->webTopicArray($prow->topicIds, defval($prow->topicInterest));
	$out = "<table class='rpad'><tr>\n	<td>";
	$colheight = (int) ((count($top) + 1) / 2);
	for ($i = 0; $i < count($top); $i++) {
	    if ($i > 0 && ($i % $colheight) == 0)
		$out .= "</td>\n	<td>";
	    $out .= $top[$i] . "<br />\n";
	}
	return $out . "</td>\n</tr></table>";
    }

    // get current topics
    $paperTopic = array();
    if ($paperId > 0) {
	$result = $Conf->q("select topicId from PaperTopic where paperId=$paperId");
	while ($row = edb_row($result))
	    $paperTopic[$row[0]] = $rf->topicName[$row[0]];
    }
    $allTopics = ($active < 0 ? $paperTopic : $rf->topicName);
    if (count($allTopics) == 0)
	return "";

    $out = "<table><tr><td class='pad'>";
    $colheight = (int) ((count($allTopics) + 1) / 2);
    $i = 0;
    foreach ($rf->topicOrder as $tid => $bogus) {
	if (!isset($allTopics[$tid]))
	    continue;
	if ($i > 0 && ($i % $colheight) == 0)
	    $out .= "</td><td>";
	if ($paperId <= 0 || $active >= 0) {
	    $out .= "<input type='checkbox' name='top$tid' value='1' ";
	    if ($active > 0 ? isset($_REQUEST["top$tid"]) : isset($paperTopic[$tid]))
		$out .= "checked='checked' ";
	    if ($active < 0)
		$out .= "disabled='disabled' ";
	    $out .= "onclick='highlightUpdate()' />&nbsp;";
	}
	$out .= htmlspecialchars($rf->topicName[$tid]) . "<br />\n";
	$i++;
    }
    return $out . "</td></tr></table>";
}

function authorTable($aus, $viewAs = null) {
    global $ConfSiteBase;
    $out = "";
    if (!is_array($aus))
	$aus = explode("\n", $aus);
    foreach ($aus as $aux) {
	$au = trim(is_array($aux) ? contactHtml($aux) : $aux);
	if ($au != '') {
	    if (strlen($au) > 40)
		$out .= "<span class='autblentry_long'>";
	    else
		$out .= "<span class='autblentry'>";
	    $out .= $au;
	    if ($viewAs !== null && is_array($aux) && count($aux) >= 2 && $viewAs->email != $aux[2] && $viewAs->privChair)
		$out .= " <a href=\"" . selfHref(array("viewContact" => $aux[2])) . "\"><img src='${ConfSiteBase}images/viewas.png' title=\"View as " . contactHtml($aux[0], $aux[1]) . "\" /></a>";
	    $out .= "</span> ";
	}
    }
    return $out;
}

function _cleanContactText(&$f, &$l, &$e) {
    if (is_array($f)) {
	if ($e === null && count($f) > 2)
	    $e = $f[2];
	$l = $f[1];
	$f = $f[0];
    } else if (is_object($f)) {
	if ($e === null && isset($f->email))
	    $e = $f->email;
	if (isset($f->lastName) || isset($f->firstName)) {
	    $l = defval($f->lastName, "");
	    $f = defval($f->firstName, "");
	} else {
	    $l = defval($f->fullName, "");
	    $f = "";
	}
    }
}

function contactText($firstName, $lastName = null, $email = null) {
    _cleanContactText($firstName, $lastName, $email);
    if ($firstName || $lastName) {
	$x = trim("$firstName $lastName");
	if ($email)
	    $x .= " <$email>";
    } else
	$x = $email;
    return $x;
}

function contactHtml($firstName, $lastName = null, $email = null) {
    $h = htmlspecialchars(contactText($firstName, $lastName, $email));
    return preg_replace('|&lt;(\S+?)&gt;$|', '&lt;<a href="mailto:$1">$1</a>&gt;', $h);
}

function contactEmailTo($firstName, $lastName = null, $email = null) {
    _cleanContactText($firstName, $lastName, $email);
    if ($firstName || $lastName) {
	$x = trim("$firstName $lastName");
	if (preg_match("/[^-A-Za-z0-9 !#\$%&'*+\\/=?^_`{|}~]/", $x))
	    $x = "\"" . addslashes($x) . "\"";
	if ($email)
	    $x .= " <$email>";
    } else
	$x = $email;
    return $x;
}

function _tryNewList($opt) {
    global $Conf, $Me;
    require_once("search.inc");
    require_once("paperlist.inc");
    $search = new PaperSearch($Me, $opt);
    $_SESSION["list"] = $search->paperList();
    $_SESSION["list"]["url"] = $search->url();
    $pl = new PaperList(false);
    $fields = $pl->listFields($search->limitName);
    $_SESSION["list"]["description"] = $fields[0];
    unset($_SESSION["matchPreg"]);
    $Conf->q("drop temporary table Matches");
}

function listLinks($paperId, $baseUrl = "paper.php", $args = array()) {
    global $Me, $ConfSiteBase;

    if (isset($_REQUEST["list"]) && substr($_REQUEST["list"], 0, 4) == "list"
	&& strlen($_REQUEST["list"]) > 4 && isset($_SESSION[$_REQUEST["list"]])) {
	$_SESSION["list"] = $_SESSION[$_REQUEST["list"]];
	unset($_SESSION[$_REQUEST["list"]]);
	unset($_SESSION["matchPreg"]);
    } else if (isset($_REQUEST["list"])) {
	$l = $_REQUEST["list"];
	if (preg_match('/\A[a-z]+\Z/', $l))
	    _tryNewList(array("t" => $l));
	else if (preg_match('/\A(all|s):(.*)\Z/s', $l, $m))
	    _tryNewList(array("t" => $m[1], "q" => $m[2]));
	else
	    _tryNewList(array("q" => $l));
    }

    if (!isset($_SESSION["list"]) || !is_array($_SESSION["list"]))
	$_SESSION["list"] = array();

    $k = array_search($paperId, $_SESSION["list"]);
    if ($paperId > 0 && $k === false && !isset($_REQUEST["list"])) {
	_tryNewList(array());
	$k = array_search($paperId, $_SESSION["list"]);
	if ($k === false && $Me->privChair) {
	    _tryNewList(array("t" => "all"));
	    $k = array_search($paperId, $_SESSION["list"]);
	}
    }

    $urlrest = "";
    foreach ($args as $what => $val)
	$urlrest .= "&amp;" . htmlspecialchars($what) . "=" . htmlspecialchars($val);

    $list = $_SESSION["list"];
    $x = "";
    if ($k > 0)
	$x .= "<a href=\"${ConfSiteBase}$baseUrl?paperId="
	    . $list[$k - 1] . $urlrest . "\"><img src=\"${ConfSiteBase}images/prev.png\" />#" . $list[$k - 1] . "</a>";
    if (isset($list["description"])) {
	$x .= ($k > 0 ? "&nbsp;&nbsp;" : "");
	if (defval($list["url"]))
	    $x .= "<a href=\"${ConfSiteBase}" . htmlspecialchars($list["url"]) . "\">" . htmlspecialchars($list["description"]) . "</a>";
	else
	    $x .= htmlspecialchars($listinfo["description"]);
    }
    if (isset($list[$k + 1])) {
	$x .= ($k > 0 || isset($list["description"]) ? "&nbsp;&nbsp;" : "");
	$x .= "<a href=\"${ConfSiteBase}$baseUrl?paperId="
	    . $list[$k + 1] . $urlrest . "\">#" . $list[$k + 1]
	    . "<img src=\"${ConfSiteBase}images/next.png\" /></a>";
    }
    return $x;
}

function goPaperForm($baseUrl = "paper.php", $args = array()) {
    global $ConfSiteBase;
    $x = "<form class='gopaper' action='${ConfSiteBase}$baseUrl' method='get'><div class='inform'>";
    foreach ($args as $what => $val)
	$x .= "<input type='hidden' name=\"" . htmlspecialchars($what) . "\" value=\"" . htmlspecialchars($val) . "\" />";
    $x .= "#<input class='textlite' type='text' size='6' name='paperId' />";
    $x .= "&nbsp;<input class='button_default' type='submit' name='go' value='Search' /></div></form>";
    return $x;
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


function pluralx($n, $what) {
    if (is_array($n))
	$n = count($n);
    return ($n == 1 ? $what : $what . 's');
}

function plural($n, $what) {
    return $n . ' ' . pluralx($n, $what);
}

function commajoin($what) {
    if (count($what) == 2)
	return $what[0] . " and " . $what[1];
    if (count($what) == 1)
	return $what[0];
    return join(", ", array_slice($what, 0, -1)) . " and " . $what[count($what) - 1];
}

function setRet(&$var, $val, $ret = false) {
    $var = $val;
    return $ret;
}

function tabLength($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); $i++)
	if ($text[$i] == ' ')
	    $len++;
	else if ($text[$i] == '\t')
	    $len += 8 - ($len % 8);
	else if (!$all)
	    break;
	else
	    $len++;
    return $len;
}

function htmlWrapText($text) {
    while (strpos($text, "\t"))
	$text = preg_replace("/^(.*?)\t/me", "'\$1' . substr('        ', 0, 8 - (strlen('\$1') % 8))", $text);
    $text = preg_replace("/^( +)/me", "str_repeat('&nbsp;', strlen('\$1'))", $text);
    $text = preg_replace("/\r\n?/", "\n", $text);
    return str_replace("\n", "<br />\n", $text);
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
	    $x = tabLength($line, false);
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

    $x .= "<span id='fold$fid' class='foldc'><span class='ellipsis'> ... </span><a class='unfolder' href='javascript:fold($fid, 0)'>[More]</a><span class='extension'> " . join(" ", array_slice($words, $maxWords)) . " </span><a class='folder' href='javascript:fold($fid, 1)'>[Less]</a></span>";
    
    return $x;
}

function wordWrapIndent($text, $info, $indent = 18, $totWidth = 75, $rjinfo = true) {
    if (is_int($indent)) {
	$indentlen = $indent;
	$indent = str_pad("", $indent);
    } else
	$indentlen = strlen($indent);

    $out = "";
    while (ctype_space($text[0])) {
	$out .= $text[0];
	$text = substr($text, 1);
    }
    
    $out .= preg_replace("/^(?!\\Z)/m", $indent, wordwrap($text, $totWidth - $indentlen));
    if (strlen($info) <= $indentlen) {
	$info = str_pad($info, $indentlen, " ", ($rjinfo ? STR_PAD_LEFT : STR_PAD_RIGHT));
	return $info . substr($out, $indentlen);
    } else
	return $info . "\n" . $out;
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
    if (isset($whyNot['noPaper']) && $paperId < 0 && $reviewId > 0)
	$text .= "No such review" . ($reviewId < 0 ? "" : " #$reviewId") . ".  ";
    else if (isset($whyNot['noPaper']))
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
    if (isset($whyNot['notAccepted']))
	$text .= ucfirst($thisPaper) . " was not accepted for publication.  ";
    if (isset($whyNot['updateSubmitted']))
	$text .= ucfirst($thisPaper) . " has already been submitted and can no longer be updated.  ";
    if (isset($whyNot['notUploaded']))
	$text .= ucfirst($thisPaper) . " can't be submitted because you haven't yet uploaded the paper itself.  Upload the paper and try again.  ";
    if (isset($whyNot['deadline'])) {
	$dname = $whyNot['deadline'];
	if ($dname[0] == "s")
	    $start = $Conf->setting("sub_open", -1);
	else if ($dname[0] == "p" || $dname[0] == "e")
	    $start = $Conf->setting("rev_open", -1);
	else
	    $start = 1;
	$end = $Conf->setting($dname, -1);
	$now = time();
	if ($start <= 0)
	    $text .= "You can't $action $thisPaper yet.  ";
	else if ($start > 0 && $now < $start)
	    $text .= "You can't $action $thisPaper until " . $Conf->printableTime($start) . ".  ";
	else if ($end > 0 && $now > $end) {
	    if ($dname == "sub_reg")
		$text .= "The paper registration deadline has passed.  ";
	    else if ($dname == "sub_update")
		$text .= "The deadline to update papers has passed.  ";
	    else if ($dname == "sub_sub")
		$text .= "The paper submission deadline has passed.  ";
	    else if ($dname == "extrev_hard")
		$text .= "The external review deadline has passed.  ";
	    else if ($dname == "pcrev_hard")
		$text .= "The PC review deadline has passed.  ";
	    else
		$text .= "The deadline to $action $thisPaper has passed.  ";
	    $text .= "It was " . $Conf->printableTime($end) . ".  ";
	} else if ($dname == "au_seerev")
	    $text .= "Authors can't view paper reviews at the moment.  ";
	else
	    $text .= "You can't $action $thisPaper at the moment.  ";
	$text .= "(<a href='${ConfSiteBase}deadlines.php'>View deadlines</a>)  ";
	if (isset($whyNot['override']) && $whyNot['override'])
	    $text .= "Select the \"Override deadlines\" checkbox to override this deadline as an administrator.  ";
    }
    if (isset($whyNot['blindSubmission']))
	$text .= "Submission to this conference is blind.  ";
    if (isset($whyNot['author']))
	$text .= "You aren't a contact author for $thisPaper.  ";
    if (isset($whyNot['conflict']))
	$text .= "You have a conflict with $thisPaper.  ";
    if (isset($whyNot['reviewNotComplete']))
	$text .= "Your own review for $thisPaper is not complete, so you can't view other people's reviews.  ";
    if (isset($whyNot['reviewsOutstanding']))
	$text .= "You must complete <a href=\"${ConfSiteBase}search.php?q=&amp;t=r\">your own reviews for other papers</a> before you can view the reviews for papers you authored.  If you can't complete your reviews, please let the conference organizers know via the relevant \"Refuse review\" links.  ";
    if (isset($whyNot['externalReviewer']))
	$text .= "External reviewers may not view other reviews for the papers they review.  ";
    if (isset($whyNot['reviewNotAssigned']))
	$text .= "You are not assigned to review $thisPaper.  ";
    if (isset($whyNot['differentReviewer']))
	$text .= "You didn't write this review, so you can't change it.  ";
    // finish it off
    if (isset($whyNot['chairMode']))
	$text .= "<a href=\"" . selfHref(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>  ";
    if (isset($whyNot['forceShow']))
	$text .= "<a href=\"". htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Show " . ($action == "comment" ? "comments" : "reviews") . " anyway</a>  ";
    if ($text && $action == "view")
	$text .= "Enter a paper number above, or <a href='${ConfSiteBase}search.php?q='>list the papers you can view</a>.  ";
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
    if ($result) {
	echo "<span class='foldc' id='fold$id'><a class='button_small unfolder' href=\"javascript:fold('${id}', 0)\">Show&nbsp;list</a>";
	echo "<span class='contactpulldown extension'>";
	echo "<select id='${id}_pulldown' name='${name}_pulldown' onchange='contactPulldown(\"$id\")' size='6'>";
	while (($row = edb_row($result))) {
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

function actionBar($prow = null, $newPaper = false, $mode = "", $thisUrl = "") {
    global $Me, $Conf, $ConfSiteBase;
    if ($newPaper)
	$paperId = "new";
    else
	$paperId = ($prow == null ? -1 : $prow->paperId);
    $disableView = (!$newPaper && $paperId < 0);
    $forceShow = ($Me->privChair && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] ? "&amp;forceShow=1" : "");

    $x = "<div class='vubar'><table class='vubar'><tr>\n  <td><table><tr>\n";
    
    if (!$newPaper && $paperId > 0)
	$x .= actionTab("View", "${ConfSiteBase}paper.php?paperId=$paperId&amp;mode=view", $mode == "view");
    
    if ($newPaper || ($paperId > 0 && ($prow->conflictType == CONFLICT_AUTHOR || $Me->privChair)))
	$x .= actionTab("Edit", "${ConfSiteBase}paper.php?paperId=$paperId&amp;mode=edit", $mode == "edit");

    if (!$newPaper && $prow
	&& ($prow->reviewType > 0
	    || $Me->canViewReview($prow, null, $Conf)
	    || ($Me->isPC && $prow->timeSubmitted > 0
		&& $Conf->timeReviewOpen())))
	$x .= actionTab("Reviews", "${ConfSiteBase}review.php?paperId=$paperId", $mode == "review");

    if (!$newPaper && $prow
	&& ($prow->reviewType > 0
	    || (($mode == "comment" || $prow->numAuthorComments > 0
		 || $Conf->timeAuthorRespond())
		&& $Me->canViewComment($prow, null, $Conf))
	    || ($Me->isPC && $prow->timeSubmitted > 0
		&& $Conf->timeReviewOpen()))) {
	$n = ($prow->conflictType == CONFLICT_AUTHOR ? $prow->numAuthorComments : $prow->numComments);
	$txt = ($n ? "Comments ($n)" : "Comments");
	$x .= actionTab($txt, "${ConfSiteBase}comment.php?paperId=$paperId", $mode == "comment");
    }

    if ($mode == "assign"
	|| ($Me->privChair && $prow && $prow->timeSubmitted > 0))
	$x .= actionTab("Assignments", "${ConfSiteBase}assign.php?paperId=$paperId", $mode == "assign");
    
    if ($thisUrl)
	$x .= actionTab($mode, $thisUrl, true);

    $x .= "  </tr></table></td>\n  <td class='spanner'></td>\n";
    
    if ($mode == "assign" || $mode == "review" || $mode == "comment")
	$goBase = "$mode.php";
    else
	$goBase = "paper.php";
    $xmode = (isset($_REQUEST["mode"]) && $goBase == "paper.php" ? array("mode" => $_REQUEST["mode"]) : array());
    if ($prow && ($zz = listLinks($prow->paperId, $goBase, $xmode)))
	$x .= "  <td class='listlinks' nowrap='nowrap'>" . $zz . "</td>\n";
    $x .= "  <td class='gopaper' nowrap='nowrap'>" . goPaperForm($goBase, $xmode) . "</td>\n";
    return $x . "</tr></table></div>";
}

function parseReviewOrdinal($n) {
    if (preg_match('/^[A-Z]$/', $n))
	return ord($n) - 64;
    else if (preg_match('/^([A-Z])([A-Z])$/', $n, $m))
	return (ord($m[0]) - 64) * 26 + ord($m[1]) - 64;
    else
	return -1;
}

function unparseReviewOrdinal($ord) {
    if ($ord === null)
	return "x";
    else if ($ord <= 26)
	return chr($ord + 64);
    else
	return chr(intval(($ord - 1) / 26) + 65) . chr(($ord % 26) + 64);
}

function titleWords($title) {
    // assume that title whitespace has been simplified
    $pos = 0;
    $len = strlen($title);
    while (1) {
	$npos = strpos($title, " ", ($pos ? $pos + 1 : 0));
	if ($npos === false && ($pos == 0 || $len <= 40))
	    return $title;
	else if ($npos === false || $npos > 40)
	    return substr($title, 0, $pos) . "...";
	else
	    $pos = $npos;
    }
}

function downloadText($text, $filename, $description, $inline = false) {
    global $Conf;
    header("Content-Description: $Conf->shortName $description, PHP generated data");
    header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=$filename");
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($text));
    print $text;
}

function cvtpref($n) {
    $n = trim($n);
    if (preg_match('/^-+$/', $n))
	return -strlen($n);
    else if (preg_match('/^\++$/', $n))
	return strlen($n);
    else if ($n == "")
	return 0;
    else if (is_numeric($n))
	return round($n);
    else if (strpos($n, "\xE2") !== false)
	// Translate UTF-8 for minus sign into a real minus sign ;)
	return cvtpref(str_replace("\xE2\x88\x92", '-', $n));
    else
	return -1000001;
}

function maybeSearchPaperId($base, $me) {
    global $Conf, $ConfSiteBase;

    // if a complex request, or a form upload, don't search
    foreach ($_REQUEST as $k => $v)
	if ($k != "paperId" && $k != "mode" && $k != "forceShow" && $k != "go"
	    && $k != "viewContact" && $k != "chairMode"
	    && !isset($_COOKIE[$k]))
	    return false;

    // if no paper ID set, find one
    if (!isset($_REQUEST["paperId"])) {
	$q = "select min(Paper.paperId) from Paper ";
	if ($me->isPC)
	    $q .= "where timeSubmitted>0";
	else if ($me->isReviewer)
	    $q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$me->contactId)";
	else
	    $q .= "join ContactInfo on (ContactInfo.paperId=Paper.paperId and ContactInfo.contactId=$me->contactId and ContactInfo.conflitType=" . CONFLICT_AUTHOR . ")";
	$result = $Conf->q($q);
	if (($paperId = edb_row($result)))
	    $_REQUEST["paperId"] = $paperId[0];
	return false;
    }

    // if you can't search, or it looks like a number, don't search
    if (!$me->isPC || preg_match('/^\s*\d+\s*$/s', $_REQUEST["paperId"]))
	return false;

    // actually try to search
    require_once("search.inc");
    $s = new PaperSearch($me, array("q" => $_REQUEST["paperId"]));
    $pl = $s->paperList();
    if (count($pl) == 1) {
	global $thisMatchPreg;
	$thisMatchPreg = "/(" . $s->matchPreg . ")/i";
	$_REQUEST["paperId"] = $pl[0];
	return true;
    } else {
	$Conf->go("${ConfSiteBase}search.php?q=" . urlencode($_REQUEST["paperId"]));
	exit;
    }
}

function outcomeSelector($curOutcome = 0) {
    $text = "<select class='outcome' name='outcome'>\n";
    $rf = reviewForm();
    $outcomeMap = $rf->options['outcome'];
    $outcomes = array_keys($outcomeMap);
    sort($outcomes);
    $outcomes = array_unique(array_merge(array(0), $outcomes));
    if ($curOutcome === null)
	$text .= "    <option value='' selected='selected'><b>Set decision...</b></option>\n";
    foreach ($outcomes as $key)
	$text .= "    <option value='$key'" . ($curOutcome == $key && $curOutcome !== null ? " selected='selected'" : "") . ">" . htmlspecialchars($outcomeMap[$key]) . "</option>\n";
    return $text . "  </select>";
}

function pcMembers() {
    global $Conf;
    if (!isset($_SESSION["pcmembers"]) || !is_array($_SESSION["pcmembers"])
	|| $Conf->setting('pc') <= 0
	|| $_SESSION["pcmembers"][0] <= $Conf->setting('pc')) {
	$pc = array();
	$result = $Conf->q("select firstName, lastName, email, PCMember.contactId as contactId, coalesce(ChairAssistant.contactId, 0) as isAssistant, coalesce(Chair.contactId, 0) as isChair from ContactInfo join PCMember using (contactId) left join ChairAssistant on (PCMember.contactId=ChairAssistant.contactId) left join Chair on (PCMember.contactId=Chair.contactId) order by lastName, firstName");
	while (($row = edb_orow($result)))
	    $pc[$row->contactId] = $row;
	$_SESSION["pcmembers"] = array($Conf->setting('pc'), $pc);
    }
    return $_SESSION["pcmembers"][1];
}

function pcByEmail($email) {
    $pc = pcMembers();
    foreach ($pc as $id => $row)
	if ($row->email == $email)
	    return $row;
    return null;
}

function paperOptions() {
    global $Conf;
    if ($Conf->setting('paperOption') <= 0 || $Conf->setting('allowPaperOption') <= 0)
	return array();
    if (!isset($_SESSION["paperOption"]) || !is_array($_SESSION["paperOption"])
	|| $_SESSION["paperOption"][0] <= $Conf->setting("paperOption")) {
	$opt = array();
	$result = $Conf->q("select * from OptionType order by optionName");
	while (($row = edb_orow($result)))
	    $opt[$row->optionId] = $row;
	$_SESSION["paperOption"] = array($Conf->setting("paperOption"), $opt);
    }
    return $_SESSION["paperOption"][1];
}
