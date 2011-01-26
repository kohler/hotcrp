<?php
// helpers.inc -- HotCRP non-class helper functions
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
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
function edb_nrows_affected($result) {
    global $Conf;
    return ($result ? mysql_affected_rows($Conf->dblink) : false);
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


// string helpers

function simplifyWhitespace($x) {
    return trim(preg_replace('/\s+/', " ", $x));
}

function csvq($text, $quote_empty = false) {
    if ($text == "")
	return $quote_empty ? '""' : $text;
    else if (preg_match('/\A[-_@\$#+A-Za-z0-9.](?:[-_@\$#+A-Za-z0-9. \t]*[-_\$#+A-Za-z0-9.]|)\z/', $text))
	return $text;
    else
	return '"' . str_replace('"', '""', $text) . '"';
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

function cvtnum($value, $default = -1) {
    $v = trim($value);
    if (is_numeric($v))
	return floatval($v);
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

if (function_exists("mb_check_encoding")) {
    function is_valid_utf8($str) {
	return @mb_check_encoding($str, "UTF-8");
    }
} else if (function_exists("iconv")) {
    // Aren't these hoops delicious?
    function _is_valid_utf8_error_handler($errno, $errstr) {
	global $_is_valid_utf8_result;
	$_is_valid_utf8_result = false;
	return false;
    }
    function is_valid_utf8($str) {
	global $_is_valid_utf8_result;
	$_is_valid_utf8_result = true;
	set_error_handler("_is_valid_utf8_error_handler");
	@iconv("UTF-8", "UTF-8", $str); // possible E_NOTICE captured above
	restore_error_handler();
	return $_is_valid_utf8_result;
	// While it might also work to compare iconv's return value to the
	// original string, who knows whether iconv canonicalizes composed
	// Unicode character sequences or something?  Safer to check for
	// errors.
    }
} else {
    function is_valid_utf8($str) {
	return true;		// give up
    }
}

if (function_exists("iconv")) {
    function windows_1252_to_utf8($str) {
	return iconv("Windows-1252", "UTF-8//TRANSLIT//IGNORE", $str);
    }
} else {
    function windows_1252_to_utf8($str) {
	return $str;		// give up
    }
}

if (function_exists("iconv")) {
    function utf8_substr($str, $off, $len) {
	return iconv_substr($str, $off, $len, "UTF-8");
    }
} else if (function_exists("mb_substr")) {
    function utf8_substr($str, $off, $len) {
	return mb_substr($str, $off, $len, "UTF-8");
    }
} else {
    function utf8_substr($str, $off, $len) {
	$x = substr($str, $off, $len);
	$poff = 0;
	while (($n = preg_match_all("/[\200-\277]/", $x, $m, PREG_PATTERN_ORDER, $poff))) {
	    $poff = strlen($x);
	    $x .= substr($str, $poff, $n);
	}
	if (preg_match("/\\A([\200-\277]+)/", substr($str, strlen($x)), $m))
	    $x .= $m[1];
	return $x;
    }
}

function simple_array_to_json($x) {
    $s = "{";
    $sep = "";
    foreach ($x as $name => $value) {
	$s .= $sep . "\"" . $name . "\":";
	if ($value === null)
	    $s .= "null";
	else if ($value === false)
	    $s .= "false";
	else if ($value === true)
	    $s .= "true";
	else if (is_int($value))
	    $s .= $value;
	else
	    // XXX Not tested that it produces true JSON.  (Currently OK since
	    // script.js uses eval(), not JSON.parse.)
	    $s .= "\"" . addcslashes($value, "\000..\037\"\\") . "\"";
	$sep = ",";
    }
    return $s . "}";
}


// web helpers

function go($url) {
    // The URL is often a relative one; make it absolute if possible.
    if (!preg_match('|\A[a-z]+://|', $url)
	&& ($absuri = requestAbsoluteURI(true))) {
	if (substr($url, 0, 1) == "/")
	    $url = preg_replace('|\A(.*?//[^/]+).*|', '\1', $absuri) . $url;
	else {
	    $absuri = preg_replace('|[^/]+\z|', "", $absuri);
	    while (strlen($url) >= 3 && substr($url, 0, 3) == "../") {
		$absuri = preg_replace('|\A(.*?)/+[^/]+/*\z|', '\1', $absuri) . "/";
		$url = substr($url, 3);
	    }
	    $url = $absuri . $url;
	}
    }

    // Might have an HTML-encoded URL; decode at least &amp;.
    $url = str_replace("&amp;", "&", $url);

    if (preg_match('|\A[a-z]+://|', $url))
	header("Location: $url");

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n";
    echo "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script type='text/javascript'>
  location=\"$url\";
</script></head><body>
<p>You should be automatically redirected <a href='", htmlspecialchars($url), "'>to here</a>.</p>
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

function selfHref($extra = array(), $self = null, $htmlspecialchars = true) {
    global $CurrentList, $ConfSiteSuffix;
    $x = ($self ? $self : $_SERVER["PHP_SELF"]);
    if ($ConfSiteSuffix == "" && strlen($x) > 4) {
	if (substr($x, strlen($x) - 4, 4) == ".php")
	    $x = substr($x, 0, strlen($x) - 4);
	else if (($pos = strpos($x, ".php/")) !== false)
	    $x = substr($x, 0, $pos) . substr($x, $pos + 4);
    }
    $sep = "?";
    if (isset($_REQUEST["paperId"]) && !isset($_REQUEST["p"]))
	$_REQUEST["p"] = $_REQUEST["paperId"];
    if (isset($_REQUEST["pap"]) && !isset($_REQUEST["p"]))
	$_REQUEST["p"] = $_REQUEST["pap"];
    if (isset($_REQUEST["reviewId"]) && !isset($_REQUEST["r"]))
	$_REQUEST["r"] = $_REQUEST["reviewId"];
    if (isset($_REQUEST["commentId"]) && !isset($_REQUEST["c"]))
	$_REQUEST["c"] = $_REQUEST["commentId"];
    foreach (array("p", "r", "c", "m", "mode", "forceShow", "validator", "ls", "list", "t", "q", "qo", "qx", "qt", "tab", "atab", "group", "sort", "monreq", "noedit") as $what)
	if (isset($_REQUEST[$what]) && !isset($extra[$what])) {
	    $x .= "$sep$what=" . urlencode($_REQUEST[$what]);
	    $sep = "&";
	}
    foreach ($extra as $key => $value)
	if ($key != "anchor" && $value !== null) {
	    $x .= "$sep$key=" . urlencode($value);
	    $sep = "&";
	}
    if (isset($CurrentList) && $CurrentList > 0 && !isset($_REQUEST["ls"]) && !isset($extra["ls"]))
	$x .= $sep . "ls=" . $CurrentList;
    if (isset($extra["anchor"]))
	$x .= "#" . $extra["anchor"];
    return $htmlspecialchars ? htmlspecialchars($x) : $x;
}

function requestAbsoluteURI($trim) {
    foreach (array("HTTP_HOST", "SERVER_PORT", "REQUEST_URI") as $x)
	if (!isset($_SERVER[$x]) || !$_SERVER[$x])
	    return false;

    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "" && $_SERVER["HTTPS"] != "off") {
	$host = "https://" . $_SERVER["HTTP_HOST"];
	if (($port = defval($_SERVER, "SERVER_PORT", 443)) != 443)
	    $host .= ":" . $port;
    } else {
	$host = "http://" . $_SERVER["HTTP_HOST"];
	if (($port = defval($_SERVER, "SERVER_PORT", 80)) != 80)
	    $host .= ":" . $port;
    }

    $tail = $_SERVER["REQUEST_URI"];
    if ($trim)
	$tail = preg_replace('/\A([^\?\#]*).*\z/', '\1', $tail);
    return $host . $tail;
}

function redirectSelf($extra = array()) {
    global $Conf;
    if (!($absuri = requestAbsoluteURI(true)))
	return false;
    $want = preg_replace('/\A.*\?/', "", selfHref($extra, null, false));
    if ($want != "")
	$absuri .= "?" . $want;
    $Conf->go($absuri);
}

function validateEmail($email) {
    // validate email address
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (!preg_match(':\A[-!#$%&\'*+./0-9=?A-Z^_`a-z{|}~]+@(.+)\z:', $email, $m))
	return false;
    if ($m[1][0] == "_")
	return preg_match(':\A_\.[0-9A-Za-z]+\z:', $m[1]);
    else
	return preg_match(':\A([-0-9A-Za-z]+\.)+[0-9A-Za-z]+\z:', $m[1]);
}

function foldsessionpixel($name, $var, $sub = false) {
    global $ConfSiteSuffix;

    $val = "&amp;val=";
    if ($sub === false)
	$val .= defval($_SESSION, $var, 1);
    else if ($sub === null)
	$val .= "&amp;sub=";
    else if ($sub !== null) {
	if (!isset($_SESSION[$var])
	    || array_search($sub, explode(" ", $_SESSION[$var])) === false)
	    $val .= "1";
	else
	    $val .= "0";
	$val = "&amp;sub=" . $sub . $val;
    }

    return "<img id='foldsession." . $name . "' alt='' src='sessionvar$ConfSiteSuffix?var=" . $var . $val . "&amp;cache=1' width='1' height='1' />";
}

function foldbutton($foldtype, $title, $foldnum = 0) {
    $showtitle = ($title ? " title='" . htmlspecialchars("Show $title") . "'" : "");
    $hidetitle = ($title ? " title='" . htmlspecialchars("Hide $title") . "'" : "");
    $foldclass = ($foldnum ? $foldnum : "");
    $foldnum = ($foldnum ? ",$foldnum" : "");
    return "<a href=\"javascript:void fold('$foldtype',0$foldnum)\" class='foldbutton fn$foldclass'$showtitle>+</a><a href=\"javascript:void fold('$foldtype',1$foldnum)\" class='foldbutton fx$foldclass'$hidetitle>&ndash;</a>";
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

function documentDownload($data) {
    global $Conf;
    $mimetype = defval($data, "mimetype", "");
    $documentType = defval($data, "documentType", DOCUMENT_SUBMISSION);
    $p = $Conf->makeDownloadPath($data->paperId, $mimetype, $documentType);
    $finalsuffix = ($documentType < 0 ? "f" : "");
    if ($mimetype == "application/postscript")
	$x = "<a href=\"$p\" class='q nowrap'>" . $Conf->cacheableImage("postscript${finalsuffix}24.png", "[PS]", null, "dlimg");
    else if ($mimetype == "application/pdf")
	$x = "<a href=\"$p\" class='q nowrap'>" . $Conf->cacheableImage("pdf${finalsuffix}24.png", "[PDF]", null, "dlimg");
    else
	$x = "<a href=\"$p\" class='nowrap'>View paper";
    if (defval($data, "size", 0) > 0)
	$x .= "&nbsp;<span class='dlsize'>" . round($data->size/1024) . "kB</span>";
    return $x . "</a>";
}

function paperDocumentData($prow, $documentType = DOCUMENT_SUBMISSION, $paperStorageId = 0) {
    global $Conf, $Opt;
    assert($paperStorageId || $documentType == DOCUMENT_SUBMISSION || $documentType == DOCUMENT_FINAL);
    if ($documentType == DOCUMENT_FINAL && $prow->finalPaperStorageId <= 0)
	$documentType = DOCUMENT_SUBMISSION;
    if ($paperStorageId == 0 && $documentType == DOCUMENT_FINAL)
	$paperStorageId = $prow->finalPaperStorageId;
    else if ($paperStorageId == 0)
	$paperStorageId = $prow->paperStorageId;
    if ($paperStorageId == 1)
	return null;

    // pre-load document object from paper
    $doc = (object) array("paperId" => $prow->paperId,
			  "mimetype" => defval($prow, "mimetype", ""),
			  "size" => defval($prow, "size", 0),
			  "timestamp" => defval($prow, "timestamp", 0),
			  "sha1" => defval($prow, "sha1", ""));
    if ($prow->finalPaperStorageId > 0) {
	$doc->paperStorageId = $prow->finalPaperStorageId;
	$doc->documentType = DOCUMENT_FINAL;
    } else {
	$doc->paperStorageId = $prow->paperStorageId;
	$doc->documentType = DOCUMENT_SUBMISSION;
    }

    // load document object from database if pre-loaded version doesn't work
    if ($paperStorageId > 0
	&& ($doc->documentType != $documentType
	    || $paperStorageId != $doc->paperStorageId)) {
	$qa = ($Conf->sversion >= 28 ? "sha1, documentType" : "'' as sha1, $documentType as documentType");
	$result = $Conf->qe("select paperStorageId, paperId, length(paper) as size, mimetype, timestamp, $qa from PaperStorage where paperStorageId=$paperStorageId", "while reading documents");
	$doc = edb_orow($result);
    }

    return $doc;
}

function paperDownload($prow, $final = false) {
    global $Conf, $Me;
    // don't let PC download papers in progress
    if ($prow->timeSubmitted <= 0 && !$Me->canDownloadPaper($prow))
	return "";
    $data = paperDocumentData($prow, $final ? -1 : 0);
    return $data ? documentDownload($data) : "";
}

function topicTable($prow, $active = 0) {
    global $Conf;
    $rf = reviewForm();
    $paperId = ($prow ? $prow->paperId : -1);

    // read from paper row if appropriate
    if ($paperId > 0 && $active < 0 && isset($prow->topicIds)) {
	$top = $rf->webTopicArray($prow->topicIds, defval($prow, "topicInterest"));
	return join(" <span class='sep'></span> ", $top);
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
	$tname = htmlspecialchars($rf->topicName[$tid]);
	if ($paperId <= 0 || $active >= 0) {
	    $out .= tagg_checkbox_h("top$tid", 1, ($active > 0 ? isset($_REQUEST["top$tid"]) : isset($paperTopic[$tid])),
				    array("disabled" => $active < 0))
		. "&nbsp;" . tagg_label($tname) . "<br />\n";
	} else
	    $out .= $tname . "<br />\n";
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
	    if (strlen($au) > 30)
		$out .= "<span class='autblentry_long'>";
	    else
		$out .= "<span class='autblentry'>";
	    $out .= $au;
	    if ($viewAs !== null && is_array($aux) && count($aux) >= 2 && $viewAs->email != $aux[2] && $viewAs->privChair)
		$out .= " <a href=\"" . selfHref(array("viewContact" => $aux[2])) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . (is_array($aux) ? contactNameHtml($aux) : $aux)) . "</a>";
	    $out .= "</span> ";
	}
    }
    return $out;
}

function abbreviateNameHtml($f, $l, $email = "") {
    $front = $rest = "";
    if ($l != "")
	$rest = $l;
    else if ($f != "")
	$rest = $f;
    else
	$rest = $email ? $email : "???";
    if ($f != "" && $l != "") {
	if (ctype_alpha($f[0]))
	    $front = $f[0];
	else if (preg_match("/^(\\pL)/us", $f, $m))
	    $front = $m[1];
	// Don't add a period if first name is a single letter
	if ($front != "" && $front != $f && substr($f, 0, strlen($front) + 1) != $front . " ")
	    $front .= ".";
    }
    if ($front == "")
	return htmlspecialchars($rest);
    else
	return htmlspecialchars($front) . "&nbsp;" . htmlspecialchars($rest);
}

function highlightMatch($match, $text, &$n = null) {
    if ($match == "") {
	$n = 0;
	return $text;
    }
    if ($match[0] != "{")
	$match = "{(" . $match . ")}i";
    return preg_replace($match, "<span class='match'>\$1</span>", $text, -1, $n);
}

function decorateNumber($n) {
    if ($n < 0)
	return "&minus;" . (-$n);
    else if ($n > 0)
	return $n;
    else
	return 0;
}

function preferenceSpan($preference, $topicInterestScore = 0) {
    if (is_array($preference))
	list($preference, $topicInterestScore) = $preference;
    if ($preference != 0)
	$type = ($preference > 0 ? 1 : -1);
    else
	$type = ($topicInterestScore > 0 ? 1 : -1);
    $t = " <span class='asspref$type'>";
    if ($preference)
	$t .= "P" . decorateNumber($preference);
    if ($preference && $topicInterestScore)
	$t .= " ";
    if ($topicInterestScore)
	$t .= "T" . decorateNumber($topicInterestScore);
    return $t . "</span>";
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
	} else
	    list($f, $l) = splitName(defval($f, "fullName", ""));
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
    if (!$email)
	$email = "none";
    if ($firstName || $lastName) {
	$x = trim("$firstName $lastName");
	if (preg_match('/[\000-\037()[\]<>@,;:\\".]/', $x))
	    $x = "\"" . addcslashes($x, '"\\') . "\"";
	return $email ? "$x <$email>" : "$x <none>";
    } else
	return $email ? $email : "<none>";
}

function allocateListNumber($listid) {
    if (!isset($_SESSION["l"]))
	$_SESSION["l"] = array();
    $oldest = $empty = 0;
    for ($i = 1; $i <= 8; ++$i)
	if (($l = defval($_SESSION["l"], $i))) {
	    if (defval($l, "listid") == $listid)
		return $i;
	    else if (!$oldest || defval($l, "timestamp", 0) < defval($_SESSION["l"][$oldest], "timestamp", 0))
		$oldest = $i;
	} else if (!$empty)
	    $empty = $i;
    return $empty ? $empty : $oldest;
}

function _tryNewList($opt, $listtype) {
    global $Conf, $ConfSiteSuffix, $Me;
    if ($listtype == "a" && $Me->privChair) {
	$searchtype = (defval($opt, "t") === "all" ? "all" : "pc");
	$q = "select email from ContactInfo";
	if ($searchtype == "pc")
	    $q .= " join PCMember using (contactId)";
	$result = $Conf->qx("$q order by lastName, firstName, email");
	$a = array();
	while (($row = edb_row($result)))
	    $a[] = $row[0];
	$a["description"] = ($searchtype == "pc" ? "Program committee" : "Users");
	$a["listid"] = "a:" . $searchtype . "::";
	$a["url"] = "contacts$ConfSiteSuffix?t=" . $searchtype;
	return $a;
    } else {
	require_once("search.inc");
	$search = new PaperSearch($Me, $opt);
	return $search->sessionList();
    }
}

function _oneListLink($id, $baseUrl, $urlrest, $listtype, $isprev) {
    global $Conf;
    if ($listtype == "a") {
	$result = $Conf->qx("select email from ContactInfo where contactId=$id");
	$row = edb_row($result);
	$paperText = ($row ? htmlspecialchars($row[0]) : "#$id");
    } else
	$paperText = "#$id";
    return "<a href=\"$baseUrl$id$urlrest\" onclick=\"return !Miniajax.isoutstanding('revprefform', make_link_callback(this))\">"
	. ($isprev ? $Conf->cacheableImage("_.gif", "&lt;-", null, "prev") : "")
	. $paperText
	. ($isprev ? "" : $Conf->cacheableImage("_.gif", "-&gt;", null, "next"))
	. "</a>";
}

function listLinks($id, $baseUrl, $args, $listtype) {
    global $Me, $ConfSiteSuffix, $Conf, $CurrentList;

    $list = false;
    $CurrentList = 0;
    if (isset($_REQUEST["ls"])
	&& ($listno = rcvtint($_REQUEST["ls"])) > 0
	&& isset($_SESSION["l"][$listno])
	&& substr(defval($_SESSION["l"][$listno], "listid", "p"), 0, 1) == $listtype) {
	$list = $_SESSION["l"][$listno];
	$CurrentList = $listno;
    } else if (isset($_REQUEST["list"]) && $listtype == "p") {
	$l = $_REQUEST["list"];
	if (preg_match('/\A[a-z]+\z/', $l))
	    $list = _tryNewList(array("t" => $l), $listtype);
	else if (preg_match('/\A(all|s):(.*)\z/s', $l, $m))
	    $list = _tryNewList(array("t" => $m[1], "q" => $m[2]), $listtype);
	else
	    $list = _tryNewList(array("q" => $l), $listtype);
    }

    $k = false;
    if ($list)
	$k = array_search($id, $list);

    if ($k === false && !isset($_REQUEST["list"])) {
	$CurrentList = 0;
	$list = _tryNewList(array(), $listtype);
	$k = array_search($id, $list);
	if ($k === false && $Me->privChair) {
	    $list = _tryNewList(array("t" => "all"), $listtype);
	    $k = array_search($id, $list);
	}
	if ($k === false)
	    $list = false;
    }

    if (!$list)
	return "";

    if ($CurrentList == 0) {
	$CurrentList = allocateListNumber($list["listid"]);
	$_SESSION["l"][$CurrentList] = $list;
    }
    $_SESSION["l"][$CurrentList]["timestamp"] = time();

    $urlrest = "&amp;ls=" . $CurrentList;
    foreach ($args as $what => $val)
	$urlrest .= "&amp;" . urlencode($what) . "=" . urlencode($val);

    $x = "";
    if ($k > 0)
	$x .= _oneListLink($list[$k - 1], $baseUrl, $urlrest, $listtype, true);
    if (isset($list["description"])) {
	$x .= ($k > 0 ? "&nbsp;&nbsp;" : "");
	if (defval($list, "url"))
	    $x .= "<a href=\"" . htmlspecialchars($list["url"]) . "\">" . $list["description"] . "</a>";
	else
	    $x .= $list["description"];
    }
    if (isset($list[$k + 1])) {
	$x .= ($k > 0 || isset($list["description"]) ? "&nbsp;&nbsp;" : "");
	$x .= _oneListLink($list[$k + 1], $baseUrl, $urlrest, $listtype, false);
    }
    return $x;
}

function goPaperForm($baseUrl = null, $args = array()) {
    global $Conf, $ConfSiteSuffix, $CurrentList;
    if (!$_SESSION["Me"]->valid())
	return "";
    if ($baseUrl !== null && substr($baseUrl, -3) === "?p")
	$baseUrl = substr($baseUrl, 0, -3);
    else if ($_SESSION["Me"]->isPC && $Conf->setting("rev_open"))
	$baseUrl = "review$ConfSiteSuffix";
    else
	$baseUrl = "paper$ConfSiteSuffix";
    $x = "<form class='gopaper' action='$baseUrl' method='get' accept-charset='UTF-8'><div class='inform'>";
    $x .= "<input class='textlite' type='text' size='10' name='p' title='Enter paper numbers or search terms' />";
    foreach ($args as $what => $val)
	$x .= "<input type='hidden' name=\"" . htmlspecialchars($what) . "\" value=\"" . htmlspecialchars($val) . "\" />";
    if (isset($CurrentList) && $CurrentList > 0)
	$x .= "<input type='hidden' name='ls' value='$CurrentList' />";
    $x .= "&nbsp; <input class='b' type='submit' value='Search' /></div></form>";
    return $x;
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


function paperBlind($prow) {
    global $Conf;
    $bs = $Conf->blindSubmission();
    return $bs == BLIND_ALWAYS
	|| ($bs == BLIND_OPTIONAL && (!$prow || $prow->blind));
}

function reviewBlind($rrow) {
    global $Conf;
    $br = $Conf->blindReview();
    return $br == BLIND_ALWAYS
	|| ($br == BLIND_OPTIONAL && (!$rrow || $rrow->reviewBlind));
}

function commentBlind($crow) {
    global $Conf;
    $br = $Conf->blindReview();
    // logic: "$br == BLIND_ALWAYS || ($br == BLIND_OPTIONAL && (!$crow || $crow->blind))"
    if ($br != BLIND_OPTIONAL || !$crow)
	return $br >= BLIND_OPTIONAL;
    return (isset($crow->commentBlind) ? $crow->commentBlind : $crow->blind);
}

// watch functions
function setReviewInfo($dst, $src) {
    $dst->myReviewType = $src->myReviewType;
    $dst->myReviewSubmitted = $src->myReviewSubmitted;
    $dst->myReviewNeedsSubmit = $src->myReviewNeedsSubmit;
    $dst->conflictType = $src->conflictType;
}

function saveWatchPreference($paperId, $contactId, $watchtype, $on) {
    global $Conf, $OK;
    $explicit = ($watchtype << WATCHSHIFT_EXPLICIT);
    $selected = ($watchtype << WATCHSHIFT_NORMAL);
    $onvalue = $explicit | ($on ? $selected : 0);
    $Conf->qe("insert into PaperWatch (paperId, contactId, watch)
		values ($paperId, $contactId, $onvalue)
		on duplicate key update watch = (watch & ~" . ($explicit | $selected) . ") | $onvalue",
	      "while saving email notification preference");
    return $OK;
}

function genericWatch($prow, $watchtype, $callback) {
    global $Conf, $Me;

    $q = "select ContactInfo.contactId, firstName, lastName, email,
		password, roles, defaultWatch,
		reviewType as myReviewType,
		reviewSubmitted as myReviewSubmitted,
		reviewNeedsSubmit as myReviewNeedsSubmit,
		conflictType, watch";
    if ($Conf->sversion >= 25)
	$q .= ", preferredEmail";

    $q .= "\nfrom ContactInfo
		left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
		left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
		left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
		left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)\n";

    $q .= "where watch is not null"
	. " or conflictType>=" . CONFLICT_AUTHOR
	. " or reviewType is not null or commentId is not null"
	. " or (defaultWatch & " . ($watchtype << WATCHSHIFT_ALL) . ")!=0";

    // save review information since we modify $prow
    $saveProw = (object) null;
    setReviewInfo($saveProw, $prow);

    $lastContactId = 0;
    $result = $Conf->qe($q, "while processing email notifications");
    while (($row = edb_orow($result))) {
	if ($row->contactId == $lastContactId
	    || $row->contactId == $Me->contactId)
	    continue;
	$lastContactId = $row->contactId;

	if ($row->watch
	    && ($row->watch & ($watchtype << WATCHSHIFT_EXPLICIT))) {
	    if (!($row->watch & ($watchtype << WATCHSHIFT_NORMAL)))
		continue;
	} else {
	    if (!($row->defaultWatch & (($watchtype << WATCHSHIFT_NORMAL) | ($watchtype << WATCHSHIFT_ALL))))
		continue;
	}

	$minic = Contact::makeMinicontact($row);
	setReviewInfo($prow, $row);
	$callback($prow, $minic);
    }

    setReviewInfo($prow, $saveProw);
}


// text helpers
function commajoin($what) {
    $what = array_values($what);
    $c = count($what);
    if ($c == 0)
	return "";
    else if ($c == 1)
	return $what[0];
    else if ($c == 2)
	return $what[0] . " and " . $what[1];
    else
	return join(", ", array_slice($what, 0, -1)) . ", and " . $what[count($what) - 1];
}

function pluralx($n, $what) {
    if (is_array($n))
	$n = count($n);
    if ($what == "this")
	return ($n == 1 ? "this" : "these");
    else
	return ($n == 1 ? $what : $what . "s");
}

function plural($n, $what) {
    return (is_array($n) ? count($n) : $n) . ' ' . pluralx($n, $what);
}

function ordinal($n) {
    if ($n >= 1 && $n <= 3)
	return $n . ($n == 1 ? "st" : ($n == 2 ? "nd" : "rd"));
    else
	return $n . "th";
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

function htmlWrapText($text) {
    $lines = explode("\n", $text);
    $text = "";
    for ($i = 0; $i < count($lines); $i++) {
	$l = $lines[$i];
	while (($pos = strpos($l, "\t")) !== false)
	    $l = substr($l, 0, $pos) . substr('        ', 0, 8 - ($pos % 8)) . substr($l, $pos + 1);
	if (preg_match("/\\A  +.*[^.?!-'\"]   +/", $l))
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

    $x .= "<span id='fold$fid' class='foldc'><span class='fn'> ... </span><a class='fn' href='javascript:void fold($fid, 0)'>[More]</a><span class='fx'> " . join(" ", array_slice($words, $maxWords)) . " </span><a class='fx' href='javascript:void fold($fid, 1)'>[Less]</a></span>";

    return $x;
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
    global $Conf, $ConfSiteSuffix;
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
	$text .= "You don&rsquo;t have permission to $action $thisPaper.  ";
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
	$text .= ucfirst($thisPaper) . " can&rsquo;t be submitted because you haven't yet uploaded the paper itself.  Upload the paper and try again.  ";
    if (isset($whyNot['reviewNotSubmitted']))
	$text .= "This review is not yet ready for others to see.  ";
    if (isset($whyNot['reviewNotComplete']))
	$text .= "Your own review for $thisPaper is not complete, so you can&rsquo;t view other people&rsquo;s reviews.  ";
    if (isset($whyNot['responseNotReady']))
	$text .= "The authors&rsquo; response for $thisPaper is not yet ready for reviewers to view.  ";
    if (isset($whyNot['reviewsOutstanding']))
	$text .= "You will get access to the reviews for your submitted papers once you complete <a href=\"search$ConfSiteSuffix?q=&amp;t=r\">your assigned reviews for other papers</a>.  If you can&rsquo;t complete your reviews, please let the conference organizers know via the &ldquo;Refuse review&rdquo; links.  ";
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
	    $text .= "You can&rsquo;t $action $thisPaper yet.  ";
	else if ($start > 0 && $now < $start)
	    $text .= "You can&rsquo;t $action $thisPaper until " . $Conf->printableTime($start, "span") . ".  ";
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
	    $text .= "It was " . $Conf->printableTime($end, "span") . ".  ";
	} else if ($dname == "au_seerev") {
	    if ($Conf->setting("au_seerev") == AU_SEEREV_YES)
		$text .= "Authors who are also reviewers can&rsquo;t see reviews for their papers while they still have <a href='search$ConfSiteSuffix?t=rout&amp;q='>incomplete reviews</a> of their own.  ";
	    else
		$text .= "Authors can&rsquo;t view paper reviews at the moment.  ";
	} else
	    $text .= "You can&rsquo;t $action $thisPaper at the moment.  ";
	$text .= "(<a class='nowrap' href='deadlines$ConfSiteSuffix'>View deadlines</a>)  ";
	if (isset($whyNot['override']) && $whyNot['override'])
	    $text .= "Select the &ldquo;Override deadlines&rdquo; checkbox to override this deadline as an administrator.  ";
    }
    if (isset($whyNot['blindSubmission']))
	$text .= "Submission to this conference is blind.  ";
    if (isset($whyNot['author']))
	$text .= "You aren&rsquo;t a contact for $thisPaper.  ";
    if (isset($whyNot['conflict']))
	$text .= "You have a conflict with $thisPaper.  ";
    if (isset($whyNot['externalReviewer']))
	$text .= "External reviewers may not view other reviews for the papers they review.  ";
    if (isset($whyNot['differentReviewer']))
	$text .= "You didn&rsquo;t write this review, so you can't change it.  ";
    if (isset($whyNot['reviewToken']))
	$text .= "If you know a valid review token, enter it above to edit that review.  ";
    // finish it off
    if (isset($whyNot['chairMode']))
	$text .= "(<a class='nowrap' href=\"" . selfHref(array("forceShow" => 1)) . "\">" . ucfirst($action) . " the paper anyway</a>)  ";
    if (isset($whyNot['forceShow']))
	$text .= "(<a class='nowrap' href=\"". selfHref(array("forceShow" => 1)) . "\">Override conflict</a>)  ";
    if ($text && $action == "view")
	$text .= "Enter a paper number above, or <a href='search$ConfSiteSuffix?q='>list the papers you can view</a>.  ";
    return ($text ? substr($text, 0, -2) : $text);
}

function contactPulldown($id, $name, $conf, $contact) {
    echo "<input class='textlite' id='${id}_name' name='${name}_name' type='text' value=\"", htmlspecialchars(trim("$contact->firstName $contact->lastName")), "\" />&nbsp;&nbsp;";
    echo "<input class='textlite' id='${id}_email' name='${name}_email' type='text' value=\"", htmlspecialchars($contact->email), "\" />&nbsp;&nbsp;";

    $result = $conf->q("select firstName, lastName, email from ContactInfo order by lastName, firstName, email");
    if ($result) {
	echo "<span class='foldc' id='fold$id'><a class='button_small fn' href=\"javascript:void fold('${id}', 0)\">Show&nbsp;list</a>";
	echo "<span class='contactpulldown fx'>";
	echo "<select id='${id}_pulldown' name='${name}_pulldown' onchange='contactPulldown(\"$id\")' size='6'>";
	while (($row = edb_row($result))) {
	    $selected = ($row[2] == $contact->email ? " selected='selected'" : "");
	    if ($row[0] || $row[1]) {
		echo "<option$selected value=\"", htmlspecialchars($row[2]), "`````", htmlspecialchars(trim("$row[0] $row[1]")), "\">", htmlspecialchars(trim("$row[0] $row[1]")), " (", htmlspecialchars($row[2]), ")</option>\n";
	    } else
		echo "<option$selected>", htmlspecialchars($row[2]), "</option>\n";
	}
	echo "</select>&nbsp;<a class='button_small' href=\"javascript:void fold('${id}', 1)\">Hide</a></span></span>\n";
    }
}

function actionTab($text, $url, $default) {
    if ($default)
	return "    <td><div class='vbtab1'><div class='vbtab1x'><div class='vbtab1y'><a href='$url'>$text</a></div></div></div></td>\n";
    else
	return "    <td><div class='vbtab'><a href='$url'>$text</a></div></td>\n";
}

function actionBar($mode = "", $prow = null) {
    global $Me, $Conf, $ConfSiteSuffix, $CurrentList;
    $forceShow = ($Me->privChair && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] ? "&amp;forceShow=1" : "");

    $goBase = "paper$ConfSiteSuffix?p=";
    $xmode = array();
    $listtype = "p";

    if ($mode == "assign")
	$goBase = "assign$ConfSiteSuffix?p=";
    else if ($mode == "r" || $mode == "re" || $mode == "review")
	$goBase = "review$ConfSiteSuffix?p=";
    else if ($mode == "c" || $mode == "comment")
	$goBase = "comment$ConfSiteSuffix?p=";
    else if ($mode == "contactauthors")
	$goBase = "contactauthors$ConfSiteSuffix?p=";
    else if ($mode == "account") {
	$listtype = "a";
	if ($Me->privChair)
	    $goBase = "account$ConfSiteSuffix?contact=";
	else
	    $prow = null;
    } else if ($mode == "" && $Me->isPC && $Conf->setting("rev_open"))
	$goBase = "review$ConfSiteSuffix?p=";
    else if (($wantmode = defval($_REQUEST, "m", defval($_REQUEST, "mode"))))
	$xmode["m"] = $wantmode;

    $listarg = $forceShow;
    $listlinks_txt = "";
    if ($prow) {
	$id = ($listtype === "a" ? $prow->contactId : $prow->paperId);
	$listlinks_txt = listLinks($id, $goBase, $xmode, $listtype);
	if (isset($CurrentList) && $CurrentList > 0)
	    $listarg .= "&amp;ls=$CurrentList";
    }

    // collect actions
    $x = "<div class='nvbar'><table class='vbar'><tr><td class='spanner'></td>\n";
    if ($listlinks_txt)
	$x .= "  <td class='listlinks nowrap'>" . $listlinks_txt . "</td>\n";

    $x .= "  <td class='gopaper nowrap'>" . goPaperForm($goBase, $xmode) . "</td>\n";

    return $x . "</tr></table></div>";
}

function parseReviewOrdinal($text) {
    $text = strtoupper($text);
    if (preg_match('/^[A-Z]$/', $text))
	return ord($text) - 64;
    else if (preg_match('/^([A-Z])([A-Z])$/', $text, $m))
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

function titleWords($title, $chars = 40) {
    // assume that title whitespace has been simplified
    if (strlen($title) <= $chars)
	return $title;
    // don't over-shorten due to UTF-8
    $xtitle = utf8_substr($title, 0, $chars);
    if (($pos = strrpos($xtitle, " ")) > 0
	&& substr($title, strlen($xtitle), 1) != " ")
	$xtitle = substr($xtitle, 0, $pos);
    return $xtitle . "...";
}

function __downloadCSV(&$row, $csv) {
    $t = array();
    reset($row);
    error_log(var_export(current($row), true));
    if (count($row) == 0)
	return "";
    else if (is_array(current($row))) {
	foreach ($row as &$x)
	    $t[] = __downloadCSV($x, $csv);
	unset($x);
	return join("", $t);
    } else if ($csv) {
	foreach ($row as &$x)
	    $t[] = csvq($x);
	unset($x);
	return join(",", $t) . "\n";
    } else
	return join("\t", $row) . "\n";
}

function downloadCSV($info, $header, $filename, $description, $opt = array()) {
    global $Conf, $Opt, $zlib_output_compression;
    $iscsv = defval($opt, "type", "csv") == "csv" && !isset($Opt["disableCSV"]);
    if (is_array($info))
	$text = __downloadCSV($info, $iscsv);
    else
	$text = $info;
    if ($header && $iscsv)
	$headertext = __downloadCSV($header, $iscsv);
    else if ($header)
	$headertext = "#" . __downloadCSV($header, $iscsv);
    else
	$headertext = "";
    header("Content-Description: " . $Opt["shortName"] . " $description, PHP generated data");
    header("Content-Disposition: " . (defval($opt, "inline") ? "inline" : "attachment") . "; filename=" . $Opt["downloadPrefix"] . $filename . ($iscsv ? ".csv" : ".txt"));
    if ($iscsv)
	header("Content-Type: text/csv; charset=utf-8; header=" . ($headertext ? "present" : "absent"));
    else
	header("Content-Type: text/plain; charset=utf-8");
    if (!defval($opt, "nolength") && !$zlib_output_compression)
	header("Content-Length: " . (strlen($headertext) + strlen($text)));
    echo $headertext, $text;
}

function downloadText($text, $filename, $description, $inline = false, $length = true) {
    downloadCSV($text, false, preg_replace('/\.txt\z/', "", $filename), $description, array("inline" => $inline, "nolength" => !$length, "type" => "txt"));
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

function decisionSelector($curOutcome = 0, $id = null, $extra = "") {
    $text = "<select" . ($id === null ? "" : " id='$id'") . " name='decision'$extra>\n";
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
	$qa = ($Conf->sversion >= 35 ? ", contactTags" : "");
	$result = $Conf->q("select firstName, lastName, affiliation, email, ContactInfo.contactId as contactId, coalesce(ChairAssistant.contactId, 0) as isAssistant, coalesce(Chair.contactId, 0) as isChair$qa from ContactInfo join PCMember using (contactId) left join ChairAssistant on (PCMember.contactId=ChairAssistant.contactId) left join Chair on (PCMember.contactId=Chair.contactId) order by lastName, firstName");
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
	$qa = ($Conf->sversion >= 35 ? ", contactTags" : "");
	$result = $Conf->q("select firstName, lastName, email, ContactInfo.contactId as contactId, coalesce(ChairAssistant.contactId, 0) as isAssistant, coalesce(Chair.contactId, 0) as isChair$qa from ContactInfo left join PCMember using (contactId) left join ChairAssistant on (ContactInfo.contactId=ChairAssistant.contactId) left join Chair on (ContactInfo.contactId=Chair.contactId) where PCMember.contactId is not null or ChairAssistant.contactId is not null order by lastName, firstName");
	while (($row = edb_orow($result)))
	    $pc[$row->contactId] = $row;
	$_SESSION["pcmembersa"] = array($Conf->setting('pc'), $pc);
    }
    return $_SESSION["pcmembersa"][1];
}

function pcTags() {
    $pcm = pcMembers();
    $tags = array();
    foreach ($pcm as $pc)
	if (isset($pc->contactTags) && $pc->contactTags) {
	    foreach (explode(" ", $pc->contactTags) as $t)
		if ($t !== "")
		    $tags[$t] = $t;
	}
    ksort($tags);
    return $tags;
}

function pcByEmail($email) {
    $pc = pcMembers();
    foreach ($pc as $id => $row)
	if ($row->email == $email)
	    return $row;
    return null;
}

function matchContact($pcm, $firstName, $lastName, $email) {
    $lastmax = $firstmax = false;
    if (!$lastName) {
	$lastName = $email;
	$lastmax = true;
    }
    if (!$firstName) {
	$firstName = $lastName;
	$firstmax = true;
    }
    assert(is_string($email) && is_string($firstName) && is_string($lastName));

    $cid = -2;
    $matchprio = 0;
    foreach ($pcm as $pcid => $pc) {
	// Match full email => definite match.
	// Otherwise, sum priorities as follows:
	//   Entire front of email, or entire first or last name => +10 each
	//   Part of word in email, first, or last name          => +1 each
	// If a string is used for more than one of email, first, and last,
	// don't count a match more than once.  Pick closest match.

	$emailprio = $firstprio = $lastprio = 0;
	if ($email !== "") {
	    if ($pc->email === $email)
		return $pcid;
	    if (($pos = stripos($pc->email, $email)) !== false) {
		if ($pos === 0 && $pc->email[strlen($email)] == "@")
		    $emailprio = 10;
		else if ($pos === 0 || !ctype_alnum($pc->email[$pos - 1]))
		    $emailprio = 1;
	    }
	}
	if ($firstName != "") {
	    if (($pos = stripos($pc->firstName, $firstName)) !== false) {
		if ($pos === 0 && strlen($pc->firstName) == strlen($firstName))
		    $firstprio = 10;
		else if ($pos === 0 || !ctype_alnum($pc->firstName[$pos - 1]))
		    $firstprio = 1;
	    }
	}
	if ($lastName != "") {
	    if (($pos = stripos($pc->lastName, $lastName)) !== false) {
		if ($pos === 0 && strlen($pc->lastName) == strlen($lastName))
		    $lastprio = 10;
		else if ($pos === 0 || !ctype_alnum($pc->firstName[$pos - 1]))
		    $lastprio = 1;
	    }
	}
	if ($lastmax && $firstmax)
	    $thisprio = max($emailprio, $firstprio, $lastprio);
	else if ($lastmax)
	    $thisprio = max($emailprio, $lastprio) + $firstprio;
	else if ($firstmax)
	    $thisprio = $emailprio + max($firstprio, $lastprio);
	else
	    $thisprio = $emailprio + $firstprio + $lastprio;

	if ($thisprio && $matchprio <= $thisprio) {
	    $cid = ($matchprio < $thisprio ? $pcid : -1);
	    $matchprio = $thisprio;
	}
    }
    return $cid;
}

function matchValue($a, $word, $allowKey = false) {
    $outa = array();
    $outb = array();
    $outc = array();
    foreach ($a as $k => $v)
	if (strcmp($word, $v) == 0
	    || ($allowKey && strcmp($word, $k) == 0))
	    $outa[] = $k;
	else if (strcasecmp($word, $v) == 0)
	    $outb[] = $k;
	else if (strstr(strtolower($v), strtolower($word)) !== false)
	    $outc[] = $k;
    if (count($outa) > 0)
	return $outa;
    else if (count($outb) > 0)
	return $outb;
    else
	return $outc;
}

function paperOptions() {
    global $Conf;
    if ($Conf->setting('paperOption') <= 0 || $Conf->sversion <= 0)
	return array();
    if (!isset($_SESSION["paperOption"]) || !is_array($_SESSION["paperOption"])
	|| $_SESSION["paperOption"][0] <= $Conf->setting("paperOption")) {
	$opt = array();
	$orderby = ($Conf->sversion >= 29 ? "sortOrder, " : "") . "optionName";
	$result = $Conf->q("select * from OptionType order by $orderby");
	$order = 0;
	while (($row = edb_orow($result))) {
	    if (!isset($row->optionValues))	   // backwards compatibility
		$row->optionValues = "";
	    if (!isset($row->type) && $row->optionValues == "\x7Fi")
		$row->type = OPTIONTYPE_NUMERIC;
	    else if (!isset($row->type))
		$row->type = ($row->optionValues ? OPTIONTYPE_SELECTOR : OPTIONTYPE_CHECKBOX);
	    $row->optionAbbrev = preg_replace("/-+\$/", "", preg_replace("/[^a-z0-9_]+/", "-", strtolower($row->optionName)));
	    $row->sortOrder = $order++;
	    if (!isset($row->displayType))
		$row->displayType = 0;
	    $opt[$row->optionId] = $row;
	}
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

function updatePaperListDisplayOptions($x) {
    global $paperListFolds;
    require_once("paperlist.inc");
    $foldmap = array();
    foreach ($paperListFolds as $name => $fdef)
	$foldmap[$fdef->foldnum] = $name;

    if ($x) {
	$a = str_split($x);
	$x = " ";
	for ($i = 0; $i < count($a); ++$i) {
	    $score = ord($a[$i]);
	    if (isset($foldmap[$score]))
		$x .= $foldmap[$score] . " ";
	}
    }
    return $x;
}

function displayOptionsSet($sessionvar, $var = null, $val = null) {
    global $Conf;
    if (isset($_SESSION[$sessionvar]))
	$x = $_SESSION[$sessionvar];
    else if ($sessionvar == "pldisplay")
	$x = $Conf->settingText("pldisplay_default", " overAllMerit ");
    else
	$x = "";
    if ($x == null || $x == "")
	$x = " ";

    // translate old form (character list) into new form (space-separated)
    if (strpos($x, " ") === false)
	$x = updatePaperListDisplayOptions($x);

    // set $var to $val in list
    if ($var) {
	$x = str_replace(" $var ", " ", $x);
	if ($val)
	    $x .= "$var ";
    }

    // store list in $_SESSION
    return ($_SESSION[$sessionvar] = $x);
}


function splitName($name, $withEmail = false) {
    if ($withEmail) {
	if (preg_match('%^\s*\"?(.*?)\"?\s*<([^<>]+)>\s*$%', $name, $m)
	    || preg_match('%^\s*\"(.*)\"\s+(\S+)\s*$%', $name, $m))
	    return array_merge(splitName($m[1]), array($m[2]));
	else if (!preg_match('%^\s*(.*?)\s+(\S+)\s*$%', $name, $m))
	    return array("", "", trim($name));
	else if (strpos($m[1], "@") !== false && strpos($m[2], "@") === false)
	    return array_merge(splitName($m[2]), array($m[1]));
	else
	    return array_merge(splitName($m[1]), array($m[2]));
    }
    if (($p1 = strrpos($name, ",")) !== false) {
	$first = trim(substr($name, $p1 + 1));
	if (!preg_match('@^(Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Esquire|Junior|Senior|Jr.?|Sr.?|I+)$@i', $first))
	    return array($first, trim(substr($name, 0, $p1)));
    }
    if (preg_match('@[^\s,]+(\s+Jr\.?|\s+Sr\.?|\s+i+|\s+Ph\.?D\.?|\s+M\.?[SD]\.?)?(,.*)?\s*$@i', $name, $m)) {
	$first = trim(substr($name, 0, strlen($name) - strlen($m[0])));
	$last = trim($m[0]);
	if (preg_match('@^(\S.*?)\s+(v[oa]n|d[eu])$@i', $first, $m)) {
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

function reviewForm() {
    global $Conf, $ReviewFormCache;
    if (!$ReviewFormCache && isset($_SESSION["rf"]))
	$ReviewFormCache = @unserialize($_SESSION["rf"]);
    if (!$ReviewFormCache || !$ReviewFormCache->reviewFields)
	$ReviewFormCache = new ReviewForm($Conf);
    else
	$ReviewFormCache->validate($Conf);
    return $ReviewFormCache;
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
	    if (strcmp($value, $selected) == 0)
		$x .= " selected='selected'";
	    if ($extra && isset($extra["disabled"])
		&& defval($extra["disabled"], $value))
		$x .= " disabled='disabled'";
	    $x .= ">" . $text . "</option>";
	}
    return $x . "</select>";
}

function tagg_cbox($type, $bottom, $classextra="") {
    $c = ($bottom ? "l" : "u");
    if ($bottom)
	return "	<tr><td class='${type}cll'></td><td></td><td class='${type}clr'></td></tr>\n</table>";
    else
	return "<table class='${type}c" . ($classextra ? " $classextra" : "")
	    . "'>\n	<tr><td class='${type}cul'></td><td></td><td class='${type}cur'></td></tr>\n";
}


global $tagg_controlid, $tagg_lastcontrolid;
$tagg_controlid = 0;

function tagg_checkbox($name, $value = 1, $checked = false, $js = null) {
    global $tagg_controlid, $tagg_lastcontrolid;
    if ($js && defval($js, "id"))
	$id = $js["id"];
    else
	$id = "taggctl" . ++$tagg_controlid;
    if ($js && isset($js["class"]))
	$class = $js["class"];
    else
	$class = "cb";
    $tagg_lastcontrolid = $id;
    $t = "<input type=\"checkbox\" class=\"$class\" id=\"$id\"";
    if ($name)
	$t .= " name=\"$name\" value=\"" . htmlspecialchars($value) . "\"";
    if ($checked)
	$t .= " checked=\"checked\"";
    if ($js && isset($js["tabindex"]))
	$t .= " tabindex=\"" . $js["tabindex"] . "\"";
    if ($js && defval($js, "disabled"))
	$t .= " disabled=\"disabled\"";
    if ($js && defval($js, "onchange"))
	$t .= " onchange=\"" . $js["onchange"] . "\"";
    if ($js && isset($js["onclick"]))
	$t .= " onclick=\"" . $js["onclick"] . "\"";
    return $t . " />";
}

function tagg_radio($name, $value = 1, $checked = false, $js = null) {
    $t = tagg_checkbox($name, $value, $checked, $js);
    return "<input type=\"radio\"" . substr($t, 22);
}

function tagg_checkbox_h($name, $value = 1, $checked = false, $js = null) {
    if (!$js)
	$js = array();
    if (!isset($js["onchange"]))
	$js["onchange"] = "hiliter(this)";
    return tagg_checkbox($name, $value, $checked, $js);
}

function tagg_radio_h($name, $value = 1, $checked = false, $js = null) {
    $t = tagg_checkbox_h($name, $value, $checked, $js);
    return "<input type=\"radio\"" . substr($t, 22);
}

function tagg_label($text, $id = null) {
    global $tagg_lastcontrolid;
    if (!$id)
	$id = $tagg_lastcontrolid;
    return "<label for=\"$id\">" . $text . "</label>";
}
