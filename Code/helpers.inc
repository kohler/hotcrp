<?php
// helpers.inc -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function defval($var, $idx, $defval = null) {
    if (is_array($var))
	return (isset($var[$idx]) ? $var[$idx] : $defval);
    else
	return (isset($var->$idx) ? $var->$idx : $defval);
}

function defappend(&$var, $str) {
    if (!isset($var))
	$var = "";
    $var .= $str;
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

function cleannl($text) {
    if (strpos($text, "\r") !== false) {
	$text = str_replace("\r\n", "\n", $text);
	$text = strtr($text, "\r", "\n");
    }
    if (strlen($text) && $text[strlen($text) - 1] != "\n")
	$text .= "\n";
    return $text;
}


// other helpers
function simplifyWhitespace($x) {
    return trim(preg_replace('/\s+/', " ", $x));
}

function cvtint($value, $default = -1) {
    $v = trim($value);
    if (is_numeric($v)) {
	$ival = intval($v);
	if ($ival == floatval($v))
	    return $ival;
    }
    return $default;
}

function rcvtint(&$value, $default = -1) {
    return (isset($value) ? cvtint($value, $default) : $default);
}

function mkarray($value) {
    if (is_array($value))
	return $value;
    else
	return array($value);
}

function alert($string = "") {
    print "<script type='text/javascript'>\n";
    print "alert(\"" . addslashes($string).  "\");";
    print "</script>";
}

function go($url) {
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n";
    echo "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script type='text/javascript'>
  location=\"$url\";
</script></head><body>
<p>You should be automatically redirected <a href='$url'>to here</a>.</p>
</body></html>\n";
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

function selfHref($extra = array(), $self = null) {
    global $CurrentList, $ConfSiteSuffix;
    $x = ($self ? $self : $_SERVER["PHP_SELF"]);
    if ($ConfSiteSuffix == "" && strlen($x) > 4 && substr($x, strlen($x) - 4, 4) == ".php")
	$x = substr($x, 0, strlen($x) - 4);
    $sep = "?";
    if (isset($_REQUEST["paperId"]) && !isset($_REQUEST["p"]))
	$_REQUEST["p"] = $_REQUEST["paperId"];
    if (isset($_REQUEST["reviewId"]) && !isset($_REQUEST["r"]))
	$_REQUEST["r"] = $_REQUEST["reviewId"];
    if (isset($_REQUEST["commentId"]) && !isset($_REQUEST["c"]))
	$_REQUEST["c"] = $_REQUEST["commentId"];
    foreach (array("p", "r", "c", "mode", "forceShow", "validator", "list", "t", "q", "qo", "qx", "qt", "group", "sort", "monreq") as $what)
	if (isset($_REQUEST[$what]) && !isset($extra[$what])) {
	    $x .= "$sep$what=" . urlencode($_REQUEST[$what]);
	    $sep = "&";
	}
    foreach ($extra as $key => $value) {
	$x .= "$sep$key=" . urlencode($value);
	$sep = "&";
    }
    if (isset($CurrentList) && $CurrentList > 0)
	$x .= $sep . "ls=" . $CurrentList;
    return $x;
}

function validateEmail($email) {
    // validate email address
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    return preg_match('/^([*+!.&#$|\'\\%\/0-9a-z^_`{}=?~:-]+)@(([0-9a-z-]+\.)+[0-9a-z]+)$/i', $email)
	|| preg_match('/^([*+!.&#$|\'\\%\/0-9a-z^_`{}=?~:-]+)@(_\.[0-9a-z]+)$/i', $email);
}

function foldbutton($foldtype, $title, $foldnum=0) {
    $showtitle = ($title ? " title='" . htmlspecialchars("Show $title") . "'" : "");
    $hidetitle = ($title ? " title='" . htmlspecialchars("Hide $title") . "'" : "");
    $foldclass = ($foldnum ? $foldnum : ""); 
    $foldnum = ($foldnum ? ", $foldnum" : "");
    return "<a href=\"javascript:fold('$foldtype', 0$foldnum)\" class='foldbutton unfolder$foldclass'$showtitle>+</a><a href=\"javascript:fold('$foldtype', 1$foldnum)\" class='foldbutton folder$foldclass'$hidetitle>&ndash;</a>";
}

function reviewType($paperId, $row, $long = 0) {
    if ($row->reviewType == REVIEW_PRIMARY)
	return "<span class='rtype rtype_pri'>Primary</span>";
    else if ($row->reviewType == REVIEW_SECONDARY)
	return "<span class='rtype rtype_sec'>Secondary</span>";
    else if ($row->reviewType == REVIEW_EXTERNAL)
	return "<span class='rtype rtype_req'>External</span>";
    else if ($row->conflictType >= CONFLICT_AUTHOR)
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
    else if ($row->reviewModified == 0 && $row->reviewType == REVIEW_SECONDARY && $row->reviewNeedsSubmit <= 0)
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

function paperDownload($prow, $final = false, $conf = null) {
    global $Conf, $Me;
    $conf = ($conf == null ? $Conf : $conf);
    if ($final && !$prow->finalPaperStorageId)
	$final = false;

    // don't allow PC to download papers in progress
    if ($prow->timeSubmitted <= 0 && !$Me->canDownloadPaper($prow, $conf))
	return "";
    
    $mimetype = defval($prow, "mimetype", "");
    $p = $conf->makeDownloadPath($prow->paperId, $mimetype, $final);
    if ($mimetype == "application/postscript")
	$x = "<a href=\"$p\" class='q'>" . $Conf->cacheableImage("postscript24.png", "[PS]", null, "dlimg");
    else if ($mimetype == "application/pdf")
	$x = "<a href=\"$p\" class='q'>" . $Conf->cacheableImage("pdf24.png", "[PDF]", null, "dlimg");
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
	$top = $rf->webTopicArray($prow->topicIds, defval($prow, "topicInterest"));
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
	    $out .= "onclick='hiliter(this)' />&nbsp;";
	}
	$out .= htmlspecialchars($rf->topicName[$tid]) . "<br />\n";
	$i++;
    }
    return $out . "</td></tr></table>";
}

function authorTable($aus, $viewAs = null) {
    global $Conf;
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
		$out .= " <a href=\"" . htmlspecialchars(selfHref(array("viewContact" => $aux[2]))) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . (is_array($aux) ? contactNameHtml($aux) : $aux)) . "</a>";
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
	    $l = defval($f, "lastName", "");
	    $f = defval($f, "firstName", "");
	} else {
	    $l = defval($f, "fullName", "");
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

function contactNameText($firstName, $lastName = null, $email = null) {
    _cleanContactText($firstName, $lastName, $email);
    if ($firstName || $lastName)
	$x = trim("$firstName $lastName");
    else
	$x = $email;
    return $x;
}

function contactHtml($firstName, $lastName = null, $email = null) {
    _cleanContactText($firstName, $lastName, $email);
    if ($email)
	$email = htmlspecialchars($email);
    if ($firstName || $lastName) {
	$x = htmlspecialchars(trim("$firstName $lastName"));
	if ($email)
	    $x .= " &lt;<a href=\"mailto:$email\">$email</a>&gt;";
    } else if ($email)
	$x = "&lt;<a href=\"mailto:$email\">$email</a>&gt;";
    else
	$x = "[No name]";
    return $x;
}

function contactNameHtml($firstName, $lastName = null, $email = null) {
    return htmlspecialchars(contactNameText($firstName, $lastName, $email));
}

function contactEmailTo($firstName, $lastName = null, $email = null) {
    _cleanContactText($firstName, $lastName, $email);
    if ($firstName || $lastName) {
	$x = trim("$firstName $lastName");
	if (preg_match("/[\x80-\xFF]/", $x)) {
	    require_once("mailtemplate.inc");
	    $x = Mailer::mimeHeaderQuote($x, 73 - 21, true);
	} else if (preg_match("/[^-A-Za-z0-9 !#\$%&'*+\\/=?^_`{|}~]/", $x))
	    $x = "\"" . addslashes($x) . "\"";
	if ($email)
	    $x .= " <$email>";
    } else
	$x = $email;
    return $x;
}

function oldestListNumber($listid = "") {
    if (!isset($_SESSION["l"]))
	$_SESSION["l"] = array();
    if (count($_SESSION["l"]) == 0)
	return 1;
    $l = $_SESSION["l"][1];
    $oldest = 0;
    for ($i = 1; $i <= 8; $i++)
	if (!isset($_SESSION["l"][$i]))
	    return $i;
	else if ($listid && $_SESSION["l"][$i]["listid"] == $listid)
	    return $i;
	else if (!$oldest
		 || (defval($_SESSION["l"][$i], 'timestamp')
		     < defval($_SESSION["l"][$oldest], 'timestamp')))
	    $oldest = $i;
    return $oldest;
}

function _tryNewList($opt, $which) {
    global $Conf, $Me;
    require_once("search.inc");
    $search = new PaperSearch($Me, $opt);
    $_SESSION["l"][$which] = $search->sessionList($search->limitName);
    $Conf->qx("drop temporary table Matches");
}

function listLinks($paperId, $baseUrl = null, $args = array()) {
    global $Me, $ConfSiteBase, $ConfSiteSuffix, $Conf, $CurrentList;
    $replaceList = oldestListNumber();
    if ($baseUrl === null)
	$baseUrl = "paper$ConfSiteSuffix";

    if (isset($_REQUEST["ls"])
	&& ($CurrentList = rcvtint($_REQUEST["ls"])) > 0
	&& isset($_SESSION["l"][$CurrentList]))
	$_SESSION["l"][$CurrentList]["timestamp"] = time();
    else if (isset($_REQUEST["list"])) {
	$l = $_REQUEST["list"];
	if (preg_match('/\A[a-z]+\Z/', $l))
	    _tryNewList(array("t" => $l), $replaceList);
	else if (preg_match('/\A(all|s):(.*)\Z/s', $l, $m))
	    _tryNewList(array("t" => $m[1], "q" => $m[2]), $replaceList);
	else
	    _tryNewList(array("q" => $l), $replaceList);
	$CurrentList = $replaceList;
    } else
	$CurrentList = 0;

    $k = false;
    if ($CurrentList != 0)
	$k = array_search($paperId, $_SESSION["l"][$CurrentList]);

    if ($paperId > 0 && $k === false && !isset($_REQUEST["list"])) {
	_tryNewList(array(), $replaceList);
	$k = array_search($paperId, $_SESSION["l"][$replaceList]);
	if ($k === false && $Me->privChair) {
	    _tryNewList(array("t" => "all"), $replaceList);
	    $k = array_search($paperId, $_SESSION["l"][$replaceList]);
	}
	$CurrentList = $replaceList;
    }

    $urlrest = "&amp;ls=" . $CurrentList;
    foreach ($args as $what => $val)
	$urlrest .= "&amp;" . htmlspecialchars($what) . "=" . htmlspecialchars($val);

    $list = $_SESSION["l"][$CurrentList];
    $x = "";
    if ($k > 0)
	$x .= "<a href=\"${ConfSiteBase}$baseUrl?p="
	    . $list[$k - 1] . $urlrest . "\">"
	    . $Conf->cacheableImage("prev.png", "&lt;-")
	    . "#" . $list[$k - 1] . "</a>";
    if (isset($list["description"])) {
	$x .= ($k > 0 ? "&nbsp;&nbsp;" : "");
	if (defval($list, "url"))
	    $x .= "<a href=\"${ConfSiteBase}" . htmlspecialchars($list["url"]) . "\">" . $list["description"] . "</a>";
	else
	    $x .= $listinfo["description"];
    }
    if (isset($list[$k + 1])) {
	$x .= ($k > 0 || isset($list["description"]) ? "&nbsp;&nbsp;" : "");
	$x .= "<a href=\"${ConfSiteBase}$baseUrl?p="
	    . $list[$k + 1] . $urlrest . "\">#" . $list[$k + 1]
	    . $Conf->cacheableImage("next.png", "-&gt;") . "</a>";
    }
    return $x;
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $CurrentList;
    if (!$_SESSION["Me"]->valid())
	return "";
    if ($baseUrl === null) {
	if ($_SESSION["Me"]->isPC && $Conf->setting("rev_open"))
	    $baseUrl = "review$ConfSiteSuffix";
	else
	    $baseUrl = "paper$ConfSiteSuffix";
    }
    $x = "<form class='gopaper' action='${ConfSiteBase}$baseUrl' method='get' accept-charset='UTF-8'><div class='inform'>";
    $x .= "<input class='textlite' type='text' size='10' name='p' title='Enter paper numbers or search terms' />";
    foreach ($args as $what => $val)
	$x .= "<input type='hidden' name=\"" . htmlspecialchars($what) . "\" value=\"" . htmlspecialchars($val) . "\" />";
    if (isset($CurrentList) && $CurrentList > 0)
	$x .= "<input type='hidden' name='ls' value='$CurrentList' />";
    $x .= "&nbsp; <input class='b' type='submit' value='Search' /></div></form>";
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
    if (!isset($dir) || !is_dir($dir)) {
	if (function_exists("sys_get_temp_dir"))
	    $dir = sys_get_temp_dir();
	else
	    $dir = "/tmp";
    }
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

function paperBlind($prow) {
    global $Conf;
    $bs = $Conf->blindSubmission();
    return $bs == 2 || ($bs == 1 && (!$prow || $prow->blind));
}

function reviewBlind($rrow) {
    global $Conf;
    $br = $Conf->blindReview();
    return $br == 2 || ($br == 1 && (!$rrow || $rrow->reviewBlind));
}

function commentBlind($crow) {
    global $Conf;
    $br = $Conf->blindReview();
    // logic: "$br == 2 || ($br == 1 && (!$crow || $crow->blind))"
    if ($br != 1 || !$crow)
	return $br == 1 || $br == 2;
    return (isset($crow->commentBlind) ? $crow->commentBlind : $crow->blind);
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
    $lines = explode("\n", $text);
    $text = "";
    for ($i = 0; $i < count($lines); $i++) {
	$l = $lines[$i];
	while (($pos = strpos($l, "\t")) !== false)
	    $l = substr($l, 0, $pos) . substr('        ', 0, 8 - ($pos % 8)) . substr($l, $pos + 1);
	if (preg_match('/\A  +.*  +/', $l))
	    $l = str_replace(" ", "&nbsp;", $l);
	else if (strlen($l) && $l[0] == " ") {
	    for ($x = 0; $x < strlen($l) && $l[$x] == " "; $x++)
		/* nada */;
	    $l = str_repeat('&nbsp;', $x) . substr($l, $x);
	}
	$lines[$i] = $l . "<br />\n";
    }
    return join("", $lines);
    
    // $lines = explode("\n", $text);
    // Rules: Indented line that starts with "-", "*", or "#[.]" starts
    //   indented text.
    //      Other indented text is preformatted.
    //
    // States: -1 initial, 0 normal text, 1 preformatted text, 2 indented text
    // $state = -1;
    // $savedPar = "";
    // $savedParLines = 0;
    // $indent = 0;
    // $out = "";
    // for ($i = 0; $i < count($lines); $i++) {
    //    $line = $lines[$i];
    //    if (preg_match("/^\\s*\$/", $line)) {
    //		$savedPar .= $line . "\n";
    //		$savedParLines++;
    //    } else if ($state == 1 && ctype_isspace($line[0]))
    //		$out .= $line . "\n";
    //    else if (preg_match("/^(\\s+)(-+|\\*+|\\d+\\.?)\\s/", $line, $matches)) {
    //		$x = tabLength($line, false);
    //    } 
    // }
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
    global $ConfSiteBase, $Conf, $ConfSiteSuffix;
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
    if (isset($whyNot['reviewNotSubmitted']))
	$text .= "This review is not yet ready for others to see.  "; 
    if (isset($whyNot['reviewNotComplete']))
	$text .= "Your own review for $thisPaper is not complete, so you can't view other people's reviews.  ";
    if (isset($whyNot['responseNotReady']))
	$text .= "The authors' response for $thisPaper is not yet ready for reviewers to view.  ";
    if (isset($whyNot['reviewsOutstanding']))
	$text .= "You must complete <a href=\"${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=r\">your assigned reviews for other papers</a> before you can see the reviews for this paper.  If you can't complete your reviews, please let the conference organizers know via the relevant \"Refuse review\" links.  ";
    if (isset($whyNot['reviewNotAssigned']))
	$text .= "You are not assigned to review $thisPaper.  ";
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
	} else if ($dname == "au_seerev") {
	    if ($Conf->setting("au_seerev") == AU_SEEREV_YES)
		$text .= "Authors who are also reviewers can't see reviews for their papers while they still have <a href='${ConfSiteBase}search$ConfSiteSuffix?t=rout&amp;q='>incomplete reviews</a> of their own.  ";
	    else
		$text .= "Authors can't view paper reviews at the moment.  ";
	} else
	    $text .= "You can't $action $thisPaper at the moment.  ";
	$text .= "(<a href='${ConfSiteBase}deadlines$ConfSiteSuffix'>View deadlines</a>)  ";
	if (isset($whyNot['override']) && $whyNot['override'])
	    $text .= "Select the &ldquo;Override deadlines&rdquo; checkbox to override this deadline as an administrator.  ";
    }
    if (isset($whyNot['blindSubmission']))
	$text .= "Submission to this conference is blind.  ";
    if (isset($whyNot['author']))
	$text .= "You aren't a contact author for $thisPaper.  ";
    if (isset($whyNot['conflict']))
	$text .= "You have a conflict with $thisPaper.  ";
    if (isset($whyNot['externalReviewer']))
	$text .= "External reviewers may not view other reviews for the papers they review.  ";
    if (isset($whyNot['differentReviewer']))
	$text .= "You didn't write this review, so you can't change it.  ";
    if (isset($whyNot['reviewToken']))
	$text .= "If you know a valid review token, enter it above to edit that review.  ";
    // finish it off
    if (isset($whyNot['chairMode']))
	$text .= "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">" . ucfirst($action) . " the paper anyway</a>  ";
    if (isset($whyNot['forceShow']))
	$text .= "<a href=\"". htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Show " . ($action == "comment" ? "comments" : "reviews") . " anyway</a>  ";
    if ($text && $action == "view")
	$text .= "Enter a paper number above, or <a href='${ConfSiteBase}search$ConfSiteSuffix?q='>list the papers you can view</a>.  ";
    return ($text ? substr($text, 0, -2) : $text);
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
    if ($default)
	return "    <td><div class='vbtab1'><div class='vbtab1x'><div class='vbtab1y'><a href='$url'>$text</a></div></div></div></td>\n";
    else
	return "    <td><div class='vbtab'><a href='$url'>$text</a></div></td>\n";
}

function actionBar($prow = null, $newPaper = false, $mode = "", $thisUrl = "") {
    global $Me, $Conf, $ConfSiteBase, $ConfSiteSuffix, $CurrentList;
    if ($newPaper)
	$paperId = "new";
    else
	$paperId = ($prow == null ? -1 : $prow->paperId);
    $forceShow = ($Me->privChair && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] ? "&amp;forceShow=1" : "");

    // collect list links now, so we find the current list
    if ($mode == "assign" || $mode == "review" || $mode == "comment")
	$goBase = "$mode$ConfSiteSuffix";
    else if ($mode == "" && $Me->isPC && $Conf->setting("rev_open"))
	$goBase = "review$ConfSiteSuffix";
    else
	$goBase = "paper$ConfSiteSuffix";
    $xmode = (isset($_REQUEST["mode"]) && $goBase == "paper$ConfSiteSuffix" ? array("mode" => $_REQUEST["mode"]) : array());
    
    $listarg = $forceShow;
    $listlinks_txt = "";
    if ($prow) {
	$listlinks_txt = listLinks($prow->paperId, $goBase, $xmode);
	if (isset($CurrentList) && $CurrentList > 0)
	    $listarg .= "&amp;ls=$CurrentList";
    }

    // collect actions
    $act = "";
    if ($newPaper || $paperId > 0)
	$act .= actionTab("Paper", "${ConfSiteBase}paper$ConfSiteSuffix?p=$paperId$listarg", $mode == "view" || $mode == "edit");
    
    if (!$newPaper && $prow
	&& ($prow->reviewType > 0
	    || $Me->canViewReview($prow, null, $Conf)
	    || ($Me->isPC && $prow->timeSubmitted > 0
		&& $Conf->timeReviewOpen())
	    || $Me->privChair
	    || $mode == "review"))
	$act .= actionTab("Reviews", "${ConfSiteBase}review$ConfSiteSuffix?p=$paperId$listarg", $mode == "review");

    if (!$newPaper && $prow
	&& ($prow->reviewType > 0
	    || (($mode == "comment" || $prow->numAuthorComments > 0
		 || $Conf->timeAuthorRespond())
		&& $Me->canViewComment($prow, null, $Conf))
	    || ($Me->isPC && $prow->timeSubmitted > 0
		&& $Conf->timeReviewOpen()))) {
	if ($prow->conflictType >= CONFLICT_AUTHOR)
	    $n = $prow->numAuthorComments;
	else if ($Me->isPC)
	    $n = $prow->numComments;
	else {
	    $result = $Conf->q("select count(commentId) from PaperComment where paperId=$prow->paperId and (forReviewers>0 or contactId=$Me->contactId)");
	    $row = edb_row($result);
	    $n = ($row ? $row[0] : 0);
	}
	$txt = ($n ? "Comments ($n)" : "Comments");
	$act .= actionTab($txt, "${ConfSiteBase}comment$ConfSiteSuffix?p=$paperId$listarg", $mode == "comment");
    }

    if ($mode == "assign" || ($Me->privChair && $prow))
	$act .= actionTab("Assignments", "${ConfSiteBase}assign$ConfSiteSuffix?p=$paperId$listarg", $mode == "assign");
    
    if ($thisUrl)
	$act .= actionTab($mode, $thisUrl, true);

    // create action bar
    if ($act)
	$x = "<div class='vbar'><table class='vbar'><tr><td id='vbartabs'><table><tr>$act</tr></table></td>";
    else
	$x = "<div class='nvbar'><table class='vbar'><tr>";

    $x .= "<td class='spanner'></td>\n";
    
    if ($listlinks_txt)
	$x .= "  <td class='listlinks nowrap'>" . $listlinks_txt . "</td>\n";
    
    $x .= "  <td class='gopaper nowrap'>" . goPaperForm($goBase, $xmode) . "</td>\n";
    
    return $x . "</tr></table></div>";
}

function parseReviewOrdinal($n) {
    $n = strtoupper($n);
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
    else if (is_object($ord)) {
	if ($ord->reviewOrdinal)
	    return $ord->paperId . unparseReviewOrdinal($ord->reviewOrdinal);
	else
	    return $ord->reviewId;
    } else if ($ord <= 26)
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

function downloadText($text, $filename, $description, $inline = false, $length = true) {
    global $Conf, $zlib_output_compression;
    header("Content-Description: $Conf->shortName $description, PHP generated data");
    header("Content-Disposition: " . ($inline ? "inline" : "attachment") . "; filename=$filename");
    header("Content-Type: text/plain");
    if ($length && !$zlib_output_compression)
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
    else if (is_numeric($n) && $n <= 1000000)
	return round($n);
    else if (strpos($n, "\xE2") !== false)
	// Translate UTF-8 for minus sign into a real minus sign ;)
	return cvtpref(str_replace("\xE2\x88\x92", '-', $n));
    else
	return -1000001;
}

function maybeSearchPaperId($me) {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $CurrentList;

    // set $_REQUEST["paperId"] from $_REQUEST["p"] for backwards compatibility
    if (!isset($_REQUEST["paperId"]) && isset($_REQUEST["p"]))
	$_REQUEST["paperId"] = $_REQUEST["p"];
    
    // if a complex request, or a form upload, don't search
    foreach ($_REQUEST as $k => $v)
	if ($k != "paperId" && $k != "mode" && $k != "forceShow" && $k != "go"
	    && $k != "viewContact" && $k != "chairMode" && $k != "ls"
	    && $k != "p" && $k != "t" && !isset($_COOKIE[$k]))
	    return false;

    // if no paper ID set, find one
    if (!isset($_REQUEST["paperId"])) {
	$q = "select min(Paper.paperId) from Paper ";
	if ($me->isPC)
	    $q .= "where timeSubmitted>0";
	else if ($me->isReviewer)
	    $q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$me->contactId)";
	else
	    $q .= "join ContactInfo on (ContactInfo.paperId=Paper.paperId and ContactInfo.contactId=$me->contactId and ContactInfo.conflictType>=" . CONFLICT_AUTHOR . ")";
	$result = $Conf->q($q);
	if (($paperId = edb_row($result)))
	    $_REQUEST["paperId"] = $paperId[0];
	return false;
    }

    // if invalid contact, or it looks like a number, don't search
    if (!$me->validated)
	return false;
    if (preg_match('/^\s*#?([1-9]\d*)\s*$/s', $_REQUEST["paperId"], $m)) {
	$_REQUEST["paperId"] = $m[1];
	return false;
    }

    // actually try to search
    require_once("search.inc");
    $search = new PaperSearch($me, array("q" => $_REQUEST["paperId"], "t" => defval($_REQUEST, "t", 0)));
    $pl = $search->paperList();
    if (count($pl) == 1) {
	global $thisMatchPreg;
	if ($search->matchPreg)
	    $thisMatchPreg = "{(" . $search->matchPreg . ")}i";
	$_REQUEST["paperId"] = $pl[0];
	$replaceList = oldestListNumber();
	$_SESSION["l"][$replaceList] = $search->sessionList($search->limitName);
	$_REQUEST["ls"] = $replaceList;
	return true;
    } else {
	$t = (defval($_REQUEST, "t", 0) ? "&t=" . urlencode($_REQUEST["t"]) : "");
	$Conf->go("${ConfSiteBase}search$ConfSiteSuffix?q=" . urlencode($_REQUEST["paperId"]) . $t);
	exit;
    }
}

function outcomeSelector($curOutcome = 0, $id = null, $extra = "") {
    $text = "<select" . ($id === null ? "" : " id='$id'") . " name='outcome'$extra>\n";
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
	|| count($_SESSION["pcmembers"]) != 2
	|| $Conf->setting('pc') <= 0
	|| $_SESSION["pcmembers"][0] < $Conf->setting('pc')
	|| !is_array($_SESSION["pcmembers"][1])
	|| count($_SESSION["pcmembers"][1]) == 0) {
	$pc = array();
	$result = $Conf->q("select firstName, lastName, email, ContactInfo.contactId as contactId, coalesce(ChairAssistant.contactId, 0) as isAssistant, coalesce(Chair.contactId, 0) as isChair from ContactInfo join PCMember using (contactId) left join ChairAssistant on (PCMember.contactId=ChairAssistant.contactId) left join Chair on (PCMember.contactId=Chair.contactId) order by lastName, firstName");
	while (($row = edb_orow($result)))
	    $pc[$row->contactId] = $row;
	$_SESSION["pcmembers"] = array($Conf->setting('pc'), $pc);
    }
    return $_SESSION["pcmembers"][1];
}

function pcMembersAndAdmins() {
    global $Conf;
    if (!isset($_SESSION["pcmembersa"]) || !is_array($_SESSION["pcmembersa"])
	|| count($_SESSION["pcmembersa"]) != 2
	|| $Conf->setting('pc') <= 0
	|| $_SESSION["pcmembersa"][0] < $Conf->setting('pc')
	|| !is_array($_SESSION["pcmembersa"][1])
	|| count($_SESSION["pcmembersa"][1]) == 0) {
	$pc = array();
	$result = $Conf->q("select firstName, lastName, email, ContactInfo.contactId as contactId, coalesce(ChairAssistant.contactId, 0) as isAssistant, coalesce(Chair.contactId, 0) as isChair from ContactInfo left join PCMember using (contactId) left join ChairAssistant on (ContactInfo.contactId=ChairAssistant.contactId) left join Chair on (ContactInfo.contactId=Chair.contactId) where PCMember.contactId is not null or ChairAssistant.contactId is not null order by lastName, firstName");
	while (($row = edb_orow($result)))
	    $pc[$row->contactId] = $row;
	$_SESSION["pcmembersa"] = array($Conf->setting('pc'), $pc);
    }
    return $_SESSION["pcmembersa"][1];
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

function scoreCounts($text, $max = null) {
    $merit = ($max ? array_fill(1, $max, 0) : array());
    $n = $sum = $sumsq = 0;
    foreach (preg_split('/[\s,]+/', $text) as $i)
	if (($i = cvtint($i)) > 0) {
	    while ($i > count($merit))
		$merit[count($merit) + 1] = 0;
	    $merit[$i]++;
	    $sum += $i;
	    $sumsq += $i * $i;
	    $n++;
	}
    $avg = ($n > 0 ? $sum / $n : 0);
    $dev = ($n > 1 ? sqrt(($sumsq - $sum*$sum/$n) / ($n - 1)) : 0);
    return (object) array("v" => $merit, "max" => count($merit),
			  "n" => $n, "avg" => $avg, "stddev" => $dev);
}

function unparseScoreAverage($avg, $levelChar) {
    if ($levelChar > 1) {
	$iavg = (int) $avg;
	if ($avg < $iavg + 0.25)
	    return chr($levelChar - $iavg);
	else if ($avg < $iavg + 0.75)
	    return chr($levelChar - $iavg - 1) . chr($levelChar - $iavg);
	else
	    return chr($levelChar - $iavg - 1);
    } else
	return sprintf("%0.2f", $avg);
}


function splitName($name) {
    if (($p1 = strrpos($name, ",")) !== false) {
	$first = trim(substr($name, $p1 + 1));
	if (!preg_match('@^(Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Esquire|Junior|Senior|Jr.?|Sr.?|I+)$@i', $first))
	    return array($first, trim(substr($name, 0, $p1)));
    }
    if (preg_match('@[^\s,]+(\s+Jr\.?|\s+Sr\.?|\s+i+|\s+Ph\.?D\.?|\s+M\.?[SD]\.?)?(,.*)?\s*$@i', $name, $m)) {
	$first = trim(substr($name, 0, strlen($name) - strlen($m[0])));
	$last = trim($m[0]);
	if (preg_match('@^(\S.*?)\s+(v[oa]n)$@i', $first, $m)) {
	    $first = $m[1];
	    $last = $m[2] . " " . $last;
	}
	return array($first, $last);
    } else
	return array("", trim($name));
}

function cleanAuthor($row) {
    if (isset($row->authorTable))
	return;
    $row->authorTable = array();
    if (strpos($row->authorInformation, "\t") === false) {
	foreach (explode("\n", $row->authorInformation) as $line)
	    if ($line != "") {
		$email = $aff = "";
		if (($p1 = strpos($line, '<')) !== false) {
		    $p2 = strpos($line, '>', $p1);
		    if ($p2 === false)
			$p2 = strlen($line);
		    $email = substr($line, $p1 + 1, $p2 - ($p1 + 1));
		    $line = substr($line, 0, $p1) . substr($line, $p2 + 1);
		}
		if (($p1 = strpos($line, '(')) !== false) {
		    $p2 = strpos($line, ')', $p1);
		    if ($p2 === false)
			$p2 = strlen($line);
		    $aff = substr($line, $p1 + 1, $p2 - ($p1 + 1));
		    $line = substr($line, 0, $p1) . substr($line, $p2 + 1);
		    if (!$email && strpos($aff, '@') !== false
			&& preg_match('_^\S+@\S+\.\S+$_', $aff)) {
			$email = $aff;
			$aff = '';
		    }
		}
		$a = splitName($line);
		$a[2] = $email;
		$a[3] = $aff;
		$row->authorTable[] = $a;
	    }
    } else {
	$info = "";
	foreach (explode("\n", $row->authorInformation) as $line)
	    if ($line != "") {
		$row->authorTable[] = $a = explode("\t", $line);
		if ($a[0] && $a[1])
		    $info .= "$a[0] $a[1]";
		else
		    $info .= $a[0] . $a[1];
		if ($a[3])
		    $info .= " (" . $a[3] . ")";
		else if ($a[2])
		    $info .= " <" . $a[2] . ">";
		$info .= "\n";
	    }
	$row->authorInformation = $info;
    }
}

function cleanAuthorText($row) {
    if (!isset($row->authorTable) && strpos($row->authorInformation, "\t") !== false)
	cleanAuthor($row);
    return $row->authorInformation;
}


function encodeToken($x) {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    while ($x > 0) {
	$t .= $s[$x & 31];
	$x >>= 5;
    }
    return $t;
}

function decodeToken($x) {
    $n = 0;
    $x = trim(strtoupper($x));
    while (($l = strlen($x))) {
	$o = ord($x[$l - 1]);
	$x = substr($x, 0, $l - 1);
	if ($o >= 65 && $o <= 72)
	    $n = ($n << 5) + ($o - 65);
	else if ($o >= 74 && $o <= 78)
	    $n = ($n << 5) + ($o - 74 + 8);
	else if ($o >= 80 && $o <= 90)
	    $n = ($n << 5) + ($o - 80 + 13);
	else if ($o >= 50 && $o <= 57)
	    $n = ($n << 5) + ($o - 50 + 24);
	else if ($o == 46 /*.*/ || $o == 34 /*"*/)
	    continue;
	else
	    return 0;
    }
    return $n;
}


// HTML helpers
function tagg_select($name, $opt, $selected=null, $extra=null) {
    $x = "<select name='$name'";
    foreach (array("id", "tabindex", "onchange", "onclick", "onfocus",
		   "onblur", "class", "style", "size") as $k)
	if ($extra && isset($extra[$k]))
	    $x .= " $k='" . $extra[$k] . "'";
    $x .= ">";
    if ($selected === null || !isset($opt[$selected]))
	$selected = key($opt);
    foreach ($opt as $value => $text)
	if ($text === null)
	    $x .= "<option disabled='disabled'></option>";
	else {
	    $x .= "<option value='$value'";
	    if ($value == $selected)
		$x .= " selected='selected'";
	    $x .= ">" . $text . "</option>";
	}
    return $x . "</select>";
}
