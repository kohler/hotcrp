<?php
// conference.inc -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Conference {

    var $dblink;

    var $settings;
    var $settingTexts;
    var $sversion;
    var $deadlineCache;

    var $saveMessages;
    var $headerPrinted;
    var $tableMessages;
    var $tableMessagesObj;
    var $msgcount;

    var $scriptStuff;
    var $footerStuff;
    var $footerScripting;
    var $usertimeId;

    function __construct() {
	global $Opt;

	$this->saveMessages = true;
	$this->headerPrinted = 0;
	$this->tableMessages = false;
	$this->msgcount = 0;
	$this->scriptStuff = "";
	$this->footerStuff = "";
	$this->footerScripting = false;
	$this->usertimeId = 1;

	// unpack dsn and connect to database
	if (!isset($Opt['dsn']))
	    die("Package misconfigured: \$Opt[\"dsn\"] is not set.  Perhaps the web server cannot read <tt>Code/options.inc</tt>?");
	else if (preg_match('|^mysql://([^:@/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[1]));
	    $dbname = urldecode($m[2]);
	} else if (preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[2]), urldecode($m[1]));
	    $dbname = urldecode($m[3]);
	} else if (preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = mysql_connect(urldecode($m[3]), urldecode($m[1]), urldecode($m[2]));
	    $dbname = urldecode($m[4]);
	} else
	    die("Package misconfigured: dsn syntax error");

	if (!$this->dblink || !mysql_select_db($dbname, $this->dblink)) {
	    // Obscure password
	    $dsn = preg_replace('{\A(\w+://[^/:]*:)[^\@/]+([\@/])}', '$1PASSWORD$2', $Opt["dsn"]);
	    die("Unable to connect to database at " . $dsn);
	}
	mysql_query("set names 'utf8'", $this->dblink);
	// XXX NB: Many MySQL versions, if not all of them, will ignore the
	// @@max_allowed_packet setting.  Keeping the code in case it's
	// useful for some installations.
	$max_file_size = ini_get_bytes("upload_max_filesize");
	mysql_query("set @@max_allowed_packet = $max_file_size", $this->dblink);

	$this->updateSettings();

	// clean up options: remove final slash from $Opt["paperSite"]
	$Opt["paperSite"] = preg_replace('|/+\z|', '', $Opt["paperSite"]);
	if (!$Opt["paperSite"])
	    die("Package misconfigured: \$Opt[\"paperSite\"] is not set.  Perhaps the web server cannot read <tt>Code/options.inc</tt>?");
    }


    //
    // Initialization functions
    //

    function ensureSession() {
	if (!session_id()) {
	    global $Opt;
	    session_name($Opt["sessionName"]);
	    session_start();
	}
    }

    function updateSettings() {
	global $Opt, $OK;
	$this->settings = array();
	$this->settingTexts = array();
	$this->deadlineCache = null;
	$result = $this->q("select name, value, data from Settings");
	while (($row = edb_row($result))) {
	    $this->settings[$row[0]] = $row[1];
	    if ($row[2] !== null)
		$this->settingTexts[$row[0]] = $row[2];
	    if (substr($row[0], 0, 4) == "opt.")
		$Opt[substr($row[0], 4)] = ($row[2] === null ? $row[1] : $row[2]);
	}
	foreach (array("pc_seeall", "pcrev_any", "extrev_view", "rev_notifychair") as $x)
	    if (!isset($this->settings[$x]))
		$this->settings[$x] = 0;
	if (!isset($this->settings["sub_blind"]))
	    $this->settings["sub_blind"] = BLIND_ALWAYS;
	if (!isset($this->settings["rev_blind"]))
	    $this->settings["rev_blind"] = BLIND_ALWAYS;
	if (!isset($this->settings["seedec"])) {
	    $au = defval($this->settings, "au_seedec", false);
	    $re = defval($this->settings, "rev_seedec", false);
	    if ($au)
		$this->settings["seedec"] = SEEDEC_ALL;
	    else if ($re)
		$this->settings["seedec"] = SEEDEC_REV;
	}
	if ($this->settings["pc_seeall"] && !$this->timeFinalizePaper())
	    $this->settings["pc_seeall"] = -1;
	if (defval($this->settings, "pc_seeallrev") == 2) {
	    $this->settings["pc_seeblindrev"] = 1;
	    $this->settings["pc_seeallrev"] = 1;
	}
	$this->settings["rounds"] = array("");
	if (isset($this->settingTexts["tag_rounds"])) {
	    foreach (explode(" ", $this->settingTexts["tag_rounds"]) as $r)
		if ($r != "")
		    $this->settings["rounds"][] = $r;
	}
	if ($this->settings["allowPaperOption"] < 41) {
	    require_once("updateschema.inc");
	    $oldOK = $OK;
	    updateSchema($this);
	    $OK = $oldOK;
	}
	$this->sversion = $this->settings["allowPaperOption"];
    }

    function setting($name, $defval = false) {
	return defval($this->settings, $name, $defval);
    }

    function settingText($name, $defval = false) {
	return defval($this->settingTexts, $name, $defval);
    }

    function capabilityText($prow, $capType) {
	// A capability has the following representation (. is concatenation):
	//    capFormat . paperId . capType . hashPrefix
	// capFormat -- Character denoting format (currently 0).
	// paperId -- Decimal representation of paper number.
	// capType -- Capability type (e.g. "a" for author view).
	// To create hashPrefix, calculate a SHA-1 hash of:
	//    capFormat . paperId . capType . paperCapVersion . capKey
	// where paperCapVersion is a decimal representation of the paper's
	// capability version (usually 0, but could allow conference admins
	// to disable old capabilities paper-by-paper), and capKey
	// is a random string specific to the conference, stored in Settings
	// under cap_key (created in header.inc).  Then hashPrefix
	// is the base-64 encoding of the first 8 bytes of this hash, except
	// that "+" is re-encoded as "-" and trailing "="s are removed.
	//
	// Any user who knows the conference's cap_key can construct any
	// capability for any paper.  Longer term, one might set each paper's
	// capVersion to a random value; but the only way to get cap_key is
	// database access, which would give you all the capVersions anyway.

	if (!isset($this->settingTexts["cap_key"]))
	    return false;
	$start = "0" . $prow->paperId . $capType;
	$hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
	$suffix = str_replace(array("+", "="), array("-", ""),
			      base64_encode(substr($hash, 0, 8)));
	return $start . $suffix;
    }

    // update the 'papersub' setting: are there any submitted papers?
    function updatePapersubSetting($forsubmit) {
	$papersub = defval($this->settings, "papersub");
	if ($papersub === null && $forsubmit)
	    $this->q("insert into Settings (name, value) values ('papersub', 1) on duplicate key update name=name");
	else if ($papersub <= 0 || !$forsubmit)
	    // see also settings.php
	    $this->q("update Settings set value=(select ifnull(min(paperId),0) from Paper where " . (defval($this->settings, "pc_seeall") <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0") . ") where name='papersub'");
    }

    function updatePaperaccSetting($foraccept) {
	if (!isset($this->settings["paperacc"]) && $foraccept)
	    $this->q("insert into Settings (name, value) values ('paperacc', " . time() . ") on duplicate key update name=name");
	else if (defval($this->settings, "paperacc") <= 0 || !$foraccept)
	    $this->q("update Settings set value=(select max(outcome) from Paper where timeSubmitted>0 group by paperId>0) where name='paperacc'");
    }

    function updateRevTokensSetting($always) {
	if ($always || defval($this->settings, "rev_tokens", 0) < 0)
	    $this->qe("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)", "while updating review tokens settings");
    }


    function qx($query) {
	return mysql_query($query, $this->dblink);
    }

    function q($query) {
	global $OK;
	$result = mysql_query($query, $this->dblink);
	if ($result === false)
	    $OK = false;
	return $result;
    }

    function dbErrorText($getdb = true, $while = "", $suggestRetry = true) {
	global $Opt;
	$text = "<p>Database error";
	if ($while)
	    $text .= " $while";
	if ($getdb)
	    $text .= ": " . htmlspecialchars(mysql_error($this->dblink)) . "</p>";
	if ($suggestRetry)
	    $text .= "\n<p>Please try again or contact the site administrator at " . $Opt["emailFrom"] . ".</p>";
	return $text;
    }

    function qe($query, $while = "", $suggestRetry = false) {
	global $OK;
	$result = mysql_query($query, $this->dblink);
	if ($result === false) {
	    $this->errorMsg($this->dbErrorText(true, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
	    $OK = false;
	}
	return $result;
    }

    function lastInsertId($while = "", $suggestRetry = false) {
	global $OK;
	$result = mysql_insert_id($this->dblink);
	if (!$result) {
	    $this->errorMsg($this->dbErrorText($result === false, $while, $suggestRetry));
	    $OK = false;
	}
	return $result;
    }


    // fetch a contact ID from the database
    function getContactId($email, $registerFromRequest = false,
			  $sendInfo = true) {
	global $Me;
	// XXX should lock table

	$email = ($email ? trim($email) : "");
	if (!$email)
	    return false;

	$result = $this->q("select contactId from ContactInfo where email='" . sqlq($email) . "'");
	if (edb_nrows($result) > 0) {
	    $row = edb_row($result);
	    return (int) $row[0];
	}

	if ($registerFromRequest === false)
	    return false;

	// validate email address
	if (!validateEmail($email))
	    return false;

	$p = (is_string($registerFromRequest) ? $registerFromRequest : "");

	// try to add them
	$newguy = new Contact();
	$result = $newguy->initialize($email);
	if ($result) {
	    // rigamarole to handle separate or joined first and last names
	    if (isset($_REQUEST["${p}firstName"]) || isset($_REQUEST["${p}lastName"])) {
		if (isset($_REQUEST["${p}firstName"]))
		    $newguy->firstName = $_REQUEST["${p}firstName"];
		if (isset($_REQUEST["${p}lastName"]))
		    $newguy->lastName = $_REQUEST["${p}lastName"];
	    } else if (!isset($_REQUEST["${p}name"]))
		/* nada */;
	    else {
		$matches = splitName($_REQUEST["${p}name"]);
		$newguy->firstName = $matches[0];
		$newguy->lastName = $matches[1];
	    }

	    // other information is easier
	    if (isset($_REQUEST["${p}affiliation"]))
		$newguy->affiliation = $_REQUEST["${p}affiliation"];
	    if (isset($_REQUEST["${p}voicePhoneNumber"]))
		$newguy->voicePhoneNumber = $_REQUEST["${p}voicePhoneNumber"];
	    if (isset($_REQUEST["${p}faxPhoneNumber"]))
		$newguy->faxPhoneNumber = $_REQUEST["${p}faxPhoneNumber"];

	    // actually update database
	    $result = $newguy->updateDB("while creating account for " . htmlspecialchars($email));
	}

	if ($result) {
	    if ($Me->privChair)
		$this->infoMsg("Created account for " . htmlspecialchars($email) . ".");
	    if ($sendInfo)
		$newguy->sendAccountInfo($this, true, false);
	    $this->log("$Me->email created account", $newguy);
	} else
	    $this->log("$Me->email account creation failure", $newguy);

	return $newguy->contactId;
    }


    // times

    function deadlines() {
	// Return all deadline-relevant settings as integers.
	if (!$this->deadlineCache) {
	    $dl = array("now" => time());
	    foreach (array("sub_open", "sub_reg", "sub_update", "sub_sub",
			   "sub_grace",
			   "resp_open", "resp_done", "resp_grace",
			   "rev_open", "pcrev_soft", "pcrev_hard",
			   "extrev_soft", "extrev_hard", "rev_grace",
			   "final_open", "final_soft", "final_done",
			   "final_grace") as $x)
		$dl[$x] = isset($this->settings[$x]) ? +$this->settings[$x] : 0;
	    $this->deadlineCache = $dl;
	}
	return $this->deadlineCache;
    }

    function printableInterval($amt) {
	if ($amt > 259200 /* 3 days */) {
	    $amt = ceil($amt / 86400);
	    $what = "day";
	} else if ($amt > 28800 /* 8 hours */) {
	    $amt = ceil($amt / 3600);
	    $what = "hour";
	} else if ($amt > 3600 /* 1 hour */) {
	    $amt = ceil($amt / 1800) / 2;
	    $what = "hour";
	} else if ($amt > 180) {
	    $amt = ceil($amt / 60);
	    $what = "minute";
	} else if ($amt > 0) {
	    $amt = ceil($amt);
	    $what = "second";
	} else
	    return "past";
	return plural($amt, $what);
    }

    function parseableTime($value, $include_zone) {
	global $Opt;
	$zone = $include_zone ? " T" : "";
	if (defval($Opt, "time24hour"))
	    return date("j M Y H:i:s" . $zone, $value);
	else
	    return date("j M Y g:i:sa" . $zone, $value);
    }
    function _printableTime($value, $dayformat, $useradjust) {
	global $Opt;
	if ($value <= 0)
	    return "N/A";
	$hourformat = (defval($Opt, "time24hour") ? "H:i:s" : "g:i:sa");
	$t = date("$dayformat $hourformat T", $value);
	if ($useradjust) {
	    $sp = strpos($useradjust, " ");
	    $t .= "<$useradjust class='usertime' id='usertime$this->usertimeId' style='display:none'></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
	    $this->footerScript("setLocalTime('usertime$this->usertimeId',$value)");
	    ++$this->usertimeId;
	}
	return $t;
    }
    function printableTime($value, $useradjust = false) {
	return $this->_printableTime($value, "l j M Y", $useradjust);
    }
    function printableTimeShort($value, $useradjust = false) {
	return $this->_printableTime($value, "j M Y", $useradjust);
    }

    function printableTimeSetting($what, $useradjust = false) {
	return $this->printableTime(defval($this->settings, $what, 0), $useradjust);
    }
    function printableDeadlineSetting($what, $useradjust = false) {
	if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
	    return "No deadline";
	else
	    return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust);
    }

    function settingsAfter($name) {
	$dl = $this->deadlines();
	$t = defval($this->settings, $name, null);
	return ($t !== null && $t > 0 && $t <= $dl["now"]);
    }
    function deadlinesAfter($name, $grace = null) {
	$dl = $this->deadlines();
	$t = defval($dl, $name, null);
	if ($t !== null && $t > 0 && $grace && isset($dl[$grace]))
	    $t += $dl[$grace];
	return ($t !== null && $t > 0 && $t <= $dl["now"]);
    }
    function deadlinesBetween($name1, $name2, $grace = null) {
	$dl = $this->deadlines();
	$t = defval($dl, $name1, null);
	if (($t === null || $t <= 0 || $t > $dl["now"]) && $name1)
	    return false;
	$t = defval($dl, $name2, null);
	if ($t !== null && $t > 0 && $grace && isset($dl[$grace]))
	    $t += $dl[$grace];
	return ($t === null || $t <= 0 || $t >= $dl["now"]);
    }

    function timeStartPaper() {
	return $this->deadlinesBetween("sub_open", "sub_reg", "sub_grace");
    }
    function timeUpdatePaper($prow = null) {
	return $this->deadlinesBetween("sub_open", "sub_update", "sub_grace")
	    && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function timeFinalizePaper($prow = null) {
	return $this->deadlinesBetween("sub_open", "sub_sub", "sub_grace")
	    && (!$prow || $prow->timeSubmitted <= 0 || $this->setting('sub_freeze') <= 0);
    }
    function collectFinalPapers() {
	return $this->setting('final_open') > 0;
    }
    function timeSubmitFinalPaper() {
	return $this->timeAuthorViewDecision()
	    && $this->deadlinesBetween("final_open", "final_done", "final_grace");
    }
    function timeAuthorViewReviews($reviewsOutstanding = false) {
	// also used to determine when authors can see review counts
	// and comments.  see also mailtemplate.inc
	$s = $this->setting("au_seerev");
	return $s == AU_SEEREV_ALWAYS || ($s > 0 && !$reviewsOutstanding);
    }
    function timeAuthorRespond() {
	return $this->deadlinesBetween("resp_open", "resp_done", "resp_grace");
    }
    function timeAuthorViewDecision() {
	return $this->setting("seedec") == SEEDEC_ALL;
    }
    function timeReviewOpen() {
	$dl = $this->deadlines();
	return $dl["rev_open"] > 0 && $dl["now"] >= $dl["rev_open"];
    }
    function timeReviewPaper($isPC = false, $requested = true, $hard = true,
			     $assumeOpen = false) {
	if (!($requested || ($isPC && $this->settings['pcrev_any'] > 0)))
	    return false;
	$od = ($assumeOpen ? "" : "rev_open");
	$d = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
	return $this->deadlinesBetween($od, $d, "rev_grace") > 0;
    }
    function timePCReviewPreferences() {
	return defval($this->settings, "papersub") > 0;
    }
    function timePCViewAllReviews() {
	return $this->settingsAfter('pc_seeallrev');
    }
    function timePCViewDecision($conflicted) {
	$s = $this->setting("seedec");
	if ($conflicted)
	    return $s == SEEDEC_ALL || $s == SEEDEC_REV;
	else
	    return $s >= SEEDEC_REV;
    }
    function timeReviewerViewDecision() {
	return $this->setting("seedec") >= SEEDEC_REV;
    }
    function timeReviewerViewAcceptedAuthors() {
	return $this->setting("seedec") == SEEDEC_ALL;
    }
    function timePCViewPaper($prow, $download) {
	if ($prow->timeWithdrawn > 0)
	    return false;
	else if ($prow->timeSubmitted > 0)
	    return true;
	    //return !$download || $this->setting('sub_freeze') > 0
	    //	|| $this->deadlinesAfter("sub_sub", "sub_grace")
	    //	|| $this->setting('sub_open') <= 0;
	else
	    return !$download && $this->setting('pc_seeall') > 0;
    }
    function timeReviewerViewSubmittedPaper() {
	return true;
    }
    function timeEmailChairAboutReview() {
	return $this->settings['rev_notifychair'] > 0;
    }
    function timeEmailAuthorsAboutReview() {
	return $this->settingsAfter('au_seerev');
    }

    function subBlindAlways() {
	return $this->settings["sub_blind"] == BLIND_ALWAYS;
    }
    function subBlindNever() {
	return $this->settings["sub_blind"] == BLIND_NEVER;
    }
    function subBlindOptional() {
	return $this->settings["sub_blind"] == BLIND_OPTIONAL;
    }
    function subBlindUntilReview() {
	return $this->settings["sub_blind"] == BLIND_UNTILREVIEW;
    }
    function paperBlind($prow, $contact) {
	if ($prow && $prow->outcome > 0
	    && $contact && ($contact->isPC || $prow->myReviewType > 0)
	    && $this->timeReviewerViewAcceptedAuthors())
	    return false;
	$bs = $this->settings["sub_blind"];
	return $bs == BLIND_ALWAYS
	    || ($bs == BLIND_OPTIONAL && (!$prow || $prow->blind))
	    || ($bs == BLIND_UNTILREVIEW
		&& (!$prow
		    || (!defval($prow, "myReviewSubmitted")
			&& (!$contact || !$contact->privChair))));
    }

    function blindReview() {
	return $this->settings['rev_blind'];
    }


    function go($url) {
	go($url);
    }


    function cacheableImage($name, $alt, $title = null, $class = null, $style = null) {
	global $ConfSiteBase, $ConfSitePATH;
	$t = "<img src='${ConfSiteBase}images/$name' alt=\"$alt\"";
	if ($title)
	    $t .= " title=\"$title\"";
	if ($class)
	    $t .= " class=\"$class\"";
	if ($style)
	    $t .= " style=\"$style\"";
	return $t . " />";
    }

    function echoScript($script) {
	if ($this->scriptStuff)
	    echo $this->scriptStuff;
	$this->scriptStuff = "";
	echo "<script type='text/javascript'>", $script, "</script>";
    }

    function footerScript($script) {
	if ($script != "") {
	    if (!$this->footerScripting) {
		$this->footerStuff .= "<script type='text/javascript'>";
		$this->footerScripting = true;
	    } else if (($c = $this->footerStuff[strlen($this->footerStuff) - 1]) != "}" && $c != "{" && $c != ";")
		$this->footerStuff .= ";";
	    $this->footerStuff .= $script;
	}
    }

    function footerHtml($html) {
	if ($this->footerScripting) {
	    $this->footerStuff .= "</script>";
	    $this->footerScripting = false;
	}
	$this->footerStuff .= $html;
    }


    //
    // Paper storage
    //

    function storePDF($uploadId, $paperId, $documentType, $mimetypes = null) {
	global $Opt;
	$while = "while storing paper in database";

	if (!$uploadId
	    || !fileUploaded($_FILES[$uploadId], $this)
	    || !isset($_FILES[$uploadId]["tmp_name"]))
	    return $this->errorMsg("There was an upload error with your file.  Please try again.");

	$filename = $_FILES[$uploadId]["tmp_name"];
	if (($contents = file_get_contents($filename)) === false
	    || strlen($contents) == 0)
	    return $this->errorMsg("Your uploaded file appears to be empty.  Please try again.");

	// Check if paper one of the allowed mimetypes.
	// We prefer to look at data since MacOS browsers get this wrong.
	if (strncmp("%PDF-", $contents, 5) == 0)
	    $mimetype = "application/pdf";
	else if (strncmp("%!PS-", $contents, 5) == 0)
	    $mimetype = "application/postscript";
	else
	    $mimetype = defval($_FILES[$uploadId], "type", "application/octet-stream");
	if (!$mimetypes)
	    $mimetypes = array("application/pdf");
	if (array_search($mimetype, $mimetypes) === false) {
	    $message = "Your uploaded file does not appear to be ";
	    if (count($mimetypes) == 1 && $mimetypes[0] == "application/pdf")
		$message .= "a PDF.  ";
	    else if (count($mimetypes) == 1 && $mimetypes[0] == "application/postscript")
		$message .= "a PostScript file.  ";
	    else if (count($mimetypes) == 2 && $mimetypes[0] == "application/pdf" && $mimetypes[1] == "application/postscript")
		$message .= "a PDF or PostScript file.  ";
	    else if (count($mimetypes) > 0)
		$message .= "a valid type (one of " . join(", ", $mimetypes) . ").  ";
	    else
		$message .= "valid (internal error: there are no valid formats).  ";
	    $message .= "(Your file has MIME type &ldquo;" . htmlentities($mimetype) . "&rdquo; and starts with &ldquo;" . htmlspecialchars(substr($contents, 0, 5)) . "&rdquo;.)<br />Please convert your file to a supported type and try again.";
	    return $this->errorMsg($message);
	}

	// store the paper
	$extra = "";
	$contents_sha1 = (!defval($Opt, "disableSHA1") ? sha1($contents, true) : "");
	if ($this->sversion >= 28)
	    $extra = ", sha1='" . sqlq($contents_sha1) . "', documentType=$documentType";
	$timestamp = time();
	if (!$this->qe("insert into PaperStorage set paperId=$paperId, timestamp=$timestamp, mimetype='" . sqlq($mimetype) . "', paper='" . sqlq(substr($contents, 0, 400000)) . "'" . $extra, $while))
	    return false;

	$paperStorageId = $this->lastInsertId($while);
	if (!$paperStorageId)
	    return $this->errorMsg("Database error: last_insert_id unsuccessful!");

	for ($pos = 400000; $pos < strlen($contents); $pos += 400000)
	    if (!$this->qe("update PaperStorage set paper=concat(paper,'" . sqlq(substr($contents, $pos, 400000)) . "') where paperStorageId=$paperStorageId", $while))
		break;

	// check that paper storage succeeded
	if (!($result = $this->qe("select length(paper) from PaperStorage where paperStorageId=$paperStorageId", $while))
	    || !($row = edb_row($result))
	    || $row[0] != strlen($contents))
	    return $this->errorMsg("Failed to store your paper.  Usually, this is because the file you tried to upload was too big for our system.  Please try again.");

	// return data about the paper
	return (object) array("paperStorageId" => $paperStorageId,
			      "contents" => $contents,
			      "size" => strlen($contents),
			      "mimetype" => $mimetype,
			      "timestamp" => $timestamp,
			      "sha1" => $contents_sha1);
    }

    function storePaper($uploadId, $prow, $final) {
	global $ConfSiteSuffix, $Opt;
	$paperId = (is_numeric($prow) ? $prow : $prow->paperId);

	$mimetypes = array();
	if (!defval($Opt, "disablePDF"))
	    $mimetypes[] = "application/pdf";
	if (!defval($Opt, "disablePS"))
	    $mimetypes[] = "application/postscript";

	$doc = $this->storePDF($uploadId, $paperId, $final ? DOCUMENT_FINAL : DOCUMENT_SUBMISSION, $mimetypes);
	if (!$doc)
	    return false;

	$while = "while storing paper in database";

	if (!$this->qe("update Paper set "
		. ($final ? "finalPaperStorageId" : "paperStorageId") . "=" . $doc->paperStorageId
		. ", size=" . $doc->size
		. ", mimetype='" . sqlq($doc->mimetype)
		. "', timestamp=" . $doc->timestamp
		. ", sha1='" . sqlq($doc->sha1)
		. "' where paperId=$paperId and timeWithdrawn<=0", $while))
	    return false;

	// potentially email the paper to Greg Minshall
	if (defval($Opt, "emailSubmissions", false)) {
	    if (is_numeric($prow))
		$prow = $this->paperRow($paperId);

	    require_once("Mail.php");
	    require_once("Mail/mime.php");

	    // prepare data
	    $subject = "[" . $Opt["shortName"] . "] Paper #$paperId upload";
	    $message = "           Paper: " . hoturl_absolute("paper", "p=$paperId") . "\n"
		. "       Submitted: " . $this->printableTime($doc->timestamp) . "\n\n"
		. wordWrapIndent(trim($prow->title), "Title: ") . "\n"
		. wordWrapIndent(trim($prow->abstract), "Abstract: ") . "\n\n"
		. wordWrapIndent(cleanAuthorText($prow), "Authors: ") . "\n"
		. wordWrapIndent(trim($prow->collaborators), "Collaborators: ") . "\n";

	    // collect contact authors
	    $aus = $this->paperContactAuthors($paperId);
	    $autxt = "";
	    foreach ($aus as $au)
		$autxt .= contactText($au) . "\n";
	    if ($autxt)
		$message .= wordWrapIndent($autxt, "Contact authors: ");
	    $message .= "\n";

	    $mime = new Mail_mime("\n");
	    $mime->setTXTBody($message);
	    $mime->addAttachment($doc->contents, $doc->mimetype,
				 $this->downloadPaperName($paperId, $doc->mimetype, 0), false);

	    $body = $mime->get();
	    $hdrs = $mime->headers(array("From" => $Opt["emailFrom"],
					 "Subject" => $subject));

	    $mfac =& Mail::factory("mail");
	    if ($this->allowEmailTo($Opt["emailSubmissions"]))
		$mfac->send($Opt["emailSubmissions"], $hdrs, $body);
	    else
		$this->infoMsg("<pre>" . htmlspecialchars($body) . "</pre>");
	}

	return $doc->size;
    }

    function downloadPaperName($paperId, $mimetype, $documentType) {
	global $Opt;
	$title = $Opt["downloadPrefix"];
	$dtn = documentTypeName($documentType);
	$title .= ($dtn ? $dtn : "xxx");
	if (ctype_digit($title[strlen($title) - 1]))
	    $title .= "-";
	return $title . $paperId . $this->getFileExtension($mimetype);
    }

    function paperStorageRow($prow, $documentType) {
	if (is_array($prow) && count($prow) <= 1)
	    $prow = (count($prow) ? $prow[0] : -1);
	if (is_numeric($prow))
	    $paperMatch = "=" . $prow;
	else if (is_array($prow))
	    $paperMatch = " in (" . join(",", $prow) . ")";
	else
	    $paperMatch = "=" . $prow->paperId;
	$q = "select p.paperId, s.mimetype, s.paper as content, s.paperStorageId, s.compression from Paper p";
	if ($documentType == DOCUMENT_SUBMISSION)
	    $sjoin = "p.paperStorageId";
	else if ($documentType == DOCUMENT_FINAL)
	    $sjoin = "p.finalPaperStorageId";
	else {
	    $q .= " left join PaperOption o on (o.paperId=p.paperId and o.optionId=$documentType)";
	    $sjoin = "o.value";
	}
	return $this->q($q . " left join PaperStorage s on (s.paperStorageId=$sjoin) where p.paperId$paperMatch");
    }

    function __downloadPaper($paperId, $saveit, $documentType) {
	global $Me, $zlib_output_compression;

	// Database query
	$result = $this->paperStorageRow($paperId, $documentType);
	if (!$result) {
	    $this->log("Error downloading for review: " . mysql_error($this->dblink), $Me, $paperId);
	    return new PEAR_Error("Database error while downloading paper.");
	} else if (edb_nrows($result) == 0)
	    return new PEAR_Error("No such document.");

	// Check data
	$row = edb_orow($result);
	if ($row->paperStorageId <= 1)
	    return new PEAR_Error("Paper #$paperId has not been uploaded yet.");
	else if (strlen($row->content) == 0)
	    return new PEAR_Error("Paper #$paperId appears to be empty.");

	// Print paper
	header("Content-Type: $row->mimetype");
	header("Content-Description: PHP Generated Data");
	$name = $this->downloadPaperName($paperId, $row->mimetype, $documentType);
	header("Content-Disposition: " . ($saveit ? "attachment" : "inline") . "; filename=$name");
	// reduce likelihood of XSS attacks in IE
	header("X-Content-Type-Options: nosniff");
	if ($row->compression == 1)
	    $row->content = gzinflate($row->content);
	if (!$zlib_output_compression)
	    header("Content-Length: " . strlen($row->content));
	print $row->content;
	return true;
    }

    function downloadPaper($paperId, $saveit, $documentType = 0) {
	global $Me;
	$result = $this->__downloadPaper($paperId, $saveit, $documentType);
	if ($result !== true)
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded paper", $Me, $paperId);
	return $result;
    }

    function zipAdd(&$tmpdir, $name, $data, &$warnings, &$files) {
	if (!isset($tmpdir) || !$tmpdir)
	    if (($tmpdir = tempdir()) === false) {
		$warnings[] = "Could not create temporary directory!";
		return false;
	    }
	$filename = "$tmpdir/$name";
	$trylen = file_put_contents($filename, $data);
	if ($trylen != strlen($data)) {
	    clean_tempdirs();
	    $trylen = file_put_contents($filename, $data);
	}
	if ($trylen != strlen($data)) {
	    $warnings[] = "Could not save $name.";
	    return false;
	} else {
	    $files[] = $filename;
	    return true;
	}
    }

    function zipFinish(&$tmpdir, $name, &$files) {
	global $Opt, $zlib_output_compression;

	if (!($zipcmd = defval($Opt, "zipCommand", "zip")))
	    return new PEAR_Error("<code>zip</code> is not supported on this installation.");
	$out = system("$zipcmd -jq $tmpdir/x.zip " . join(" ", $files) . " 2>&1", $status);
	if ($status != 0)
	    return new PEAR_Error("<code>zip</code> returned an error.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");
	if (!file_exists("$tmpdir/x.zip"))
	    return new PEAR_Error("<code>zip</code> output unreadable or empty.  Its output: <pre>" . htmlspecialchars($out) . "</pre>");

	// output
	header("Content-Description: PHP Generated Data");
	header("Content-Disposition: attachment; filename=$name");
	header("Content-Type: application/zip");
	if (!$zlib_output_compression)
	    header("Content-Length: " . filesize("$tmpdir/x.zip"));
	// flush all output buffers to avoid holding large files in memory
	ob_clean();
	flush();
	readfile("$tmpdir/x.zip");
	return true;
    }

    function __downloadPapers($paperIds, &$tmpdir, $documentType) {
	global $Opt, $zlib_output_compression;

	// special cases
	if (count($paperIds) == 0)
	    return new PEAR_Error("No papers selected for download.");
	else if (count($paperIds) == 1)
	    return $this->__downloadPaper($paperIds[0], 1, $documentType);

	// set up temporary directory
	if (($tmpdir = tempdir()) === false)
	    return new PEAR_Error("Could not create temporary directory!");

	// write papers into temporary directory
	$warnings = array();
	$files = array();
	$batch = 3;

	for ($pidno = 0; $pidno < count($paperIds); $pidno += $batch) {
	    // paper query
	    // do in groups of 3 papers at a time to avoid database killage
	    $downloadP = array();
	    for ($xpidno = $pidno;
		 $xpidno < count($paperIds) && $xpidno < $pidno + $batch;
		 ++$xpidno)
		$downloadP[] = $paperIds[$xpidno];
	    $result = $this->paperStorageRow($downloadP, $documentType);
	    if (!$result)
		return new PEAR_Error("Database error while downloading papers: " . mysql_error($this->dblink));

	    while ($row = edb_orow($result)) {
		if ($row->compression == 1)
		    $row->content = gzinflate($row->content);
		if ($row->paperStorageId <= 1 || strlen($row->content) == 0)
		    $warnings[] = "Paper #$row->paperId has not been uploaded yet.";
		else
		    $this->zipAdd($tmpdir, $this->downloadPaperName($row->paperId, $row->mimetype, $documentType), $row->content, $warnings, $files);
	    }
	}

	// complete
	if (!count($files))
	    return new PEAR_Error("No papers could successfully be downloaded.");
	if (count($warnings))
	    $this->zipAdd($tmpdir, "README-warnings.txt", join("\n", $warnings) . "\n", $warnings, $files);

	// run zip
	return $this->zipFinish($tmpdir, $Opt["downloadPrefix"] . pluralx(2, documentTypeName($documentType)) . ".zip", $files);
    }

    function downloadPapers($paperIds, $documentType) {
	global $Me;
	$result = $this->__downloadPapers($paperIds, $tmpdir, $documentType);
	if ($result !== true)
	    $this->errorMsg($result->getMessage());
	else
	    $this->log("Downloaded papers", $Me, $paperIds);
	if (isset($tmpdir) && $tmpdir)
	    exec("/bin/rm -rf $tmpdir");
	return $result;
    }


    //
    // Paper search
    //

    function _paperQuery_where($optarr, $field) {
	$ids = array();
	foreach (mkarray($optarr) as $id)
	    if (($id = cvtint($id)) > 0)
		$ids[] = "$field=$id";
	if (is_array($optarr) && count($ids) == 0)
	    $ids[] = "$field=0";
	return (count($ids) ? "(" . join(" or ", $ids) . ")" : "false");
    }

    function paperQuery($contact, $options = array()) {
	// Options:
	//   "paperId" => $pid	Only paperId $pid (if array, any of those)
	//   "reviewId" => $rid Only paper reviewed by $rid
	//   "commentId" => $c  Only paper where comment is $c
	//   "finalized"	Only submitted papers
	//   "unsub"		Only unsubmitted papers
	//   "accepted"		Only accepted papers
	//   "active"		Only nonwithdrawn papers
	//   "author"		Only papers authored by $contactId
	//   "myReviewRequests"	Only reviews requested by $contactId
	//   "myReviews"	All reviews authored by $contactId
	//   "myOutstandingReviews" All unsubmitted reviews auth by $contactId
	//   "myReviewsOpt"	myReviews, + include papers not yet reviewed
	//   "reviewerContact"	Sets reviewer contact ID
	//   "allReviews"	All reviews (multiple rows per paper)
	//   "allReviewScores"	All review scores (multiple rows per paper)
	//   "allComments"	All comments (multiple rows per paper)
	//   "reviewerName"	Include reviewer names
	//   "commenterName"	Include commenter names
	//   "joins"		Table(s) to join
	//   "tags"		Include paperTags
	//   "tagIndex" => $tag	Include tagIndex of named tag
	//   "tagIndex" => tag array -- include tagIndex, tagIndex1, ...
	//   "topics"
	//   "options"
	//   "scores" => array(fields to score)
	//   "order" => $sql	$sql is SQL 'order by' clause (or empty)

	$reviewerQuery = isset($options['myReviews']) || isset($options['allReviews']) || isset($options['myReviewRequests']) || isset($options['myReviewsOpt']) || isset($options['myOutstandingReviews']);
	$allReviewerQuery = isset($options['allReviews']) || isset($options['allReviewScores']);
	$scoresQuery = !$reviewerQuery && isset($options['allReviewScores']);
	if (is_object($contact))
	    $contactId = $contact->contactId;
	else {
	    $contactId = $contact;
	    $contact = null;
	}
	$reviewContactId = $contactId;
	if (isset($options['reviewerContact']))
	    $reviewContactId = $options['reviewerContact'];
	$where = array();

	// fields
	$pq = "select Paper.*, PaperConflict.conflictType,
		count(AllReviews.reviewSubmitted) as reviewCount,
		count(if(AllReviews.reviewNeedsSubmit<=0,AllReviews.reviewSubmitted,AllReviews.reviewId)) as startedReviewCount";
	$myPaperReview = $highlightPaperReview = null;
	if (!isset($options['author'])) {
	    if ($allReviewerQuery) {
		$myPaperReview = $highlightPaperReview = "MyPaperReview";
		if ($reviewContactId != $contactId)
		    $highlightPaperReview = "HighlightPaperReview";
	    } else if ($reviewContactId != $contactId) {
		$myPaperReview = "MyPaperReview";
		$highlightPaperReview = "PaperReview";
	    } else
		$myPaperReview = $highlightPaperReview = "PaperReview";
	    $pq .= ",
		PaperReview.reviewType,
		PaperReview.reviewId,
		PaperReview.reviewModified,
		PaperReview.reviewSubmitted,
		PaperReview.reviewNeedsSubmit,
		PaperReview.reviewOrdinal,
		PaperReview.reviewBlind,
		PaperReview.contactId as reviewContactId,
		max($myPaperReview.reviewType) as myReviewType,
		max($myPaperReview.reviewSubmitted) as myReviewSubmitted,
		min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit,
		PaperReview.reviewRound";
	} else
	    $pq .= ",\nnull as myReviewType";
	if (isset($options['reviewerName']))
	    $pq .= ",
		ReviewerContactInfo.firstName as reviewFirstName,
		ReviewerContactInfo.lastName as reviewLastName,
		ReviewerContactInfo.email as reviewEmail,
		ReviewerContactInfo.lastLogin as reviewLastLogin";
	if ($reviewerQuery || $scoresQuery) {
	    $rf = reviewForm();
	    $pq .= ",\n\t\tPaperReview.reviewEditVersion as reviewEditVersion";
	    foreach ($rf->fieldOrder as $k)
		if (!$scoresQuery || $rf->reviewFields[$k])
		    $pq .= ",\n\t\tPaperReview.$k as $k";
	}
	if (isset($options['allComments'])) {
	    $pq .= ",
		PaperComment.commentId,
		PaperComment.contactId as commentContactId,
		CommentConflict.conflictType as commentConflictType,
		PaperComment.timeModified,
		PaperComment.comment,
		PaperComment.forReviewers,
		PaperComment.forAuthors,
		PaperComment.blind as commentBlind,
		PaperComment.replyTo";
	}
	if (isset($options['topics']))
	    $pq .= ",
		PaperTopics.topicIds,
		PaperTopics.topicInterest";
	if (isset($options['options']) && defval($this->settings, "paperOption"))
	    $pq .= ",
		PaperOptions.optionIds";
	else if (isset($options['options']))
	    $pq .= ",
		'' as optionIds";
	if (isset($options['tags']))
	    $pq .= ",
		PaperTags.paperTags";
	if (isset($options["tagIndex"]) && !is_array($options["tagIndex"]))
	    $options["tagIndex"] = array($options["tagIndex"]);
	if (isset($options["tagIndex"]))
	    for ($i = 0; $i < count($options["tagIndex"]); ++$i)
		$pq .= ",\n\t\tTagIndex$i.tagIndex as tagIndex" . ($i?$i:"");
	if (isset($options['scores'])) {
	    foreach ($options['scores'] as $field) {
		$pq .= ",\n		PaperScores.${field}Scores";
		if ($highlightPaperReview)
		    $pq .= ",\n		$highlightPaperReview.$field";
	    }
	    $pq .= ",\n		PaperScores.numScores";
	}
	if (isset($options['topicInterestScore']))
	    $pq .= ",
		coalesce(PaperTopics.topicInterestScore, 0) as topicInterestScore";
	if (defval($options, 'reviewerPreference'))
	    $pq .= ",
		coalesce(PaperReviewPreference.preference, 0) as reviewerPreference";
	if (defval($options, 'allReviewerPreference'))
	    $pq .= ",
		APRP.allReviewerPreference";
	if (defval($options, 'desirability'))
	    $pq .= ",
		coalesce(APRP.desirability, 0) as desirability";
	if (defval($options, 'allConflictType'))
	    $pq .= ",
		AllConflict.allConflictType";

	// tables
	$pq .= "
		from Paper\n";

	if (isset($options['reviewId']))
	    $pq .= "		join PaperReview as ReviewSelector on (ReviewSelector.paperId=Paper.paperId)\n";
	if (isset($options['commentId']))
	    $pq .= "		join PaperComment as CommentSelector on (CommentSelector.paperId=Paper.paperId)\n";

	$aujoinwhere = null;
	if (isset($options["author"]) && $contact
	    && ($aujoinwhere = $contact->actAuthorSql("PaperConflict", true)))
	    $where[] = $aujoinwhere;
	if (isset($options["author"]) && !$aujoinwhere)
	    $pq .= "		join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . " and PaperConflict.contactId=$contactId)\n";
	else
	    $pq .= "		left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$contactId)\n";

	if (isset($options['joins']))
	    foreach ($options['joins'] as $jt)
		$pq .= "		$jt\n";

	$pq .= "		left join PaperReview as AllReviews on (AllReviews.paperId=Paper.paperId)\n";

	$qr = "";
	if (isset($_SESSION["rev_tokens"]))
	    $qr = " or PaperReview.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
	if (isset($options['myReviewRequests']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.requestedBy=$contactId and PaperReview.reviewType=" . REVIEW_EXTERNAL . ")\n";
	else if (isset($options['myReviews']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
	else if (isset($options['myOutstandingReviews']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr) and PaperReview.reviewNeedsSubmit!=0)\n";
	else if (isset($options['myReviewsOpt']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
	else if (isset($options['allReviews']) || isset($options['allReviewScores']))
	    $pq .= "		join PaperReview on (PaperReview.paperId=Paper.paperId)\n";
	else if (!isset($options['author']))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$reviewContactId$qr))\n";
	if ($myPaperReview == "MyPaperReview")
	    $pq .= "		left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)\n";
	if ($highlightPaperReview == "HighlightPaperReview")
	    $pq .= "		left join PaperReview as HighlightPaperReview on (HighlightPaperReview.paperId=Paper.paperId and HighlightPaperReview.contactId=$reviewContactId)\n";
	if (isset($options['allComments']))
	    $pq .= "		join PaperComment on (PaperComment.paperId=Paper.paperId)
		left join PaperConflict as CommentConflict on (CommentConflict.paperId=PaperComment.paperId and CommentConflict.contactId=PaperComment.contactId)\n";

	if (isset($options['reviewerName']) && isset($options['allComments']))
	    $pq .= "		left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperComment.contactId)\n";
	else if (isset($options['reviewerName']))
	    $pq .= "		left join ContactInfo as ReviewerContactInfo on (ReviewerContactInfo.contactId=PaperReview.contactId)\n";

	if (isset($options['topics']) || isset($options['topicInterestScore'])) {
	    $pq .= "		left join (select paperId";
	    if (isset($options['topics']))
		$pq .= ", group_concat(PaperTopic.topicId) as topicIds, group_concat(ifnull(TopicInterest.interest,1)) as topicInterest";
	    if (isset($options['topicInterestScore']))
		$pq .= ", sum(if(interest=2,2,interest-1)) as topicInterestScore";
	    $pq .= " from PaperTopic left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$reviewContactId) group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)\n";
	}

	if (isset($options['options']) && defval($this->settings, "paperOption")) {
	    $pq .= "		left join (select paperId, group_concat(PaperOption.optionId, '#', value) as optionIds from PaperOption group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)\n";
	}

	if (isset($options['tags']))
	    $pq .= "		left join (select paperId, group_concat(tag, '#', tagIndex order by tag separator ' ') as paperTags from PaperTag group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)\n";
	if (isset($options["tagIndex"]))
	    for ($i = 0; $i < count($options["tagIndex"]); ++$i)
		$pq .= "		left join PaperTag as TagIndex$i on (TagIndex$i.paperId=Paper.paperId and TagIndex$i.tag='" . sqlq($options["tagIndex"][$i]) . "')\n";

	if (isset($options['scores'])) {
	    $pq .= "		left join (select paperId";
	    foreach ($options['scores'] as $field)
		$pq .= ", group_concat($field) as ${field}Scores";
	    $pq .= ", count(*) as numScores";
	    $pq .= " from PaperReview where reviewSubmitted>0 group by paperId) as PaperScores on (PaperScores.paperId=Paper.paperId)\n";
	}

	if (defval($options, 'reviewerPreference'))
	    $pq .= "		left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$reviewContactId)\n";
	if (defval($options, 'allReviewerPreference')
	    || defval($options, 'desirability')) {
	    $subq = "select paperId";
	    if (defval($options, 'allReviewerPreference'))
		$subq .= ", group_concat(concat(contactId,' ',preference) separator ',') as allReviewerPreference";
	    if (defval($options, 'desirability'))
		$subq .= ", sum(if(preference<=-100,0,greatest(least(preference,1),-1))) as desirability";
	    $subq .= " from PaperReviewPreference group by paperId";
	    $pq .= "		left join ($subq) as APRP on (APRP.paperId=Paper.paperId)\n";
	}
	if (defval($options, 'allConflictType'))
	    $pq .= "		left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)\n";


	// conditions
	if (isset($options['paperId']))
	    $where[] = $this->_paperQuery_where($options['paperId'], "Paper.paperId");
	if (isset($options["reviewId"])) {
	    if (is_numeric($options["reviewId"]))
		$where[] = $this->_paperQuery_where($options["reviewId"], "ReviewSelector.reviewId");
	    else if (preg_match('/^(\d+)([A-Z][A-Z]?)$/i', $options["reviewId"], $m)) {
		$where[] = $this->_paperQuery_where($m[1], "Paper.paperId");
		$where[] = $this->_paperQuery_where(parseReviewOrdinal($m[2]), "ReviewSelector.reviewOrdinal");
	    } else
		$where[] = $this->_paperQuery_where(-1, "Paper.paperId");
	}
	if (isset($options['commentId']))
	    $where[] = $this->_paperQuery_where($options['commentId'], "CommentSelector.commentId");
	if (isset($options['finalized']))
	    $where[] = "timeSubmitted>0";
	else if (isset($options['unsub']))
	    $where[] = "timeSubmitted<=0";
	if (isset($options['accepted']))
	    $where[] = "outcome>0";
	if (isset($options['undecided']))
	    $where[] = "outcome=0";
	if (isset($options["active"]) || isset($options["myReviews"])
	    || isset($options["myReviewRequests"]))
	    $where[] = "timeWithdrawn<=0";
	if (isset($options["myLead"]))
	    $where[] = "leadContactId=$contactId";

	if (count($where))
	    $pq .= "		where " . join(" and ", $where) . "\n";

	// grouping and ordering
	if (isset($options["allComments"]))
	    $pq .= "		group by Paper.paperId, PaperComment.commentId\n";
	else if ($reviewerQuery || $scoresQuery)
	    $pq .= "		group by Paper.paperId, PaperReview.reviewId\n";
	else
	    $pq .= "		group by Paper.paperId\n";
	if (isset($options['order']) && $options['order'] != "order by Paper.paperId")
	    $pq .= "		" . $options['order'];
	else {
	    $pq .= "		order by Paper.paperId";
	    if ($reviewerQuery || $scoresQuery)
		$pq .= ", PaperReview.reviewOrdinal";
	    if (isset($options["allComments"]))
		$pq .= ", PaperComment.commentId";
	}

	//$this->infoMsg("<pre>" . htmlspecialchars($pq) . "</pre>");
	return $pq . "\n";
    }

    function paperRow($sel, $contactId = -1, &$whyNot = null) {
	$whyNot = array();
	if (!is_array($sel))
	    $sel = array('paperId' => $sel);
	if (isset($sel['paperId']))
	    $whyNot['paperId'] = $sel['paperId'];
	if (isset($sel['reviewId']))
	    $whyNot['reviewId'] = $sel['reviewId'];

	if (isset($sel['paperId']) && cvtint($sel['paperId']) < 0)
	    $whyNot['invalidId'] = 'paper';
	else if (isset($sel['reviewId']) && cvtint($sel['reviewId']) < 0
		 && !preg_match('/^\d+[A-Z][A-Z]?$/i', $sel['reviewId']))
	    $whyNot['invalidId'] = 'review';
	else {
	    $q = $this->paperQuery($contactId, $sel);
	    $result = $this->q($q);

	    if (!$result)
		$whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . mysql_error($this->dblink);
	    else if (edb_nrows($result) == 0)
		$whyNot['noPaper'] = 1;
	    else
		return edb_orow($result);
	}

	return null;
    }

    function reviewRows($q) {
	$result = $this->qe($q, "while loading reviews");
	$rrows = array();
	while (($row = edb_orow($result)))
	    $rrows[$row->reviewId] = $row;
	return $rrows;
    }

    function commentRows($q) {
	$result = $this->qe($q, "while loading comments");
	$crows = array();
	while (($row = edb_orow($result))) {
	    $crows[$row->commentId] = $row;
	    $cid = defval($row, "commentContactId", $row->contactId);
	    $row->threadContacts = array($cid => 1);
	    for ($r = $row; defval($r, "replyTo", 0) && isset($crows[$r->replyTo]); $r = $crows[$r->replyTo])
		/* do nothing */;
	    $row->threadHead = $r->commentId;
	    $r->threadContacts[$cid] = 1;
	}
	foreach ($crows as $row)
	    if ($row->threadHead != $row->commentId)
		$row->threadContacts = $crows[$row->threadHead]->threadContacts;
	return $crows;
    }


    function paperContactAuthors($paperId) {
	$result = $this->qe("select firstName, lastName, email, contactId from ContactInfo join PaperConflict using (contactId) where paperId=$paperId and conflictType>=" . CONFLICT_AUTHOR, "while looking up paper contacts");
	$aus = array();
	while (($row = edb_row($result)))
	    $aus[] = $row;
	return $aus;
    }


    function reviewRow($selector, &$whyNot = null) {
	$whyNot = array();

	if (!is_array($selector))
	    $selector = array('reviewId' => $selector);
	if (isset($selector['reviewId'])) {
	    $whyNot['reviewId'] = $selector['reviewId'];
	    if (($reviewId = cvtint($selector['reviewId'])) <= 0) {
		$whyNot['invalidId'] = 'review';
		return null;
	    }
	}
	if (isset($selector['paperId'])) {
	    $whyNot['paperId'] = $selector['paperId'];
	    if (($paperId = cvtint($selector['paperId'])) <= 0) {
		$whyNot['invalidId'] = 'paper';
		return null;
	    }
	}
	$contactTags = "NULL as contactTags";
	if ($this->sversion >= 35)
	    $contactTags = "ContactInfo.contactTags";

	$q = "select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email,
		$contactTags,
		ReqCI.firstName as reqFirstName, ReqCI.lastName as reqLastName, ReqCI.email as reqEmail, ReqCI.contactId as reqContactId";
	if (isset($selector["ratings"]))
	    $q .= ",
		group_concat(ReviewRating.rating order by ReviewRating.rating desc) as allRatings,
		count(ReviewRating.rating) as numRatings";
	if (isset($selector["myRating"]))
	    $q .= ",
		MyRating.rating as myRating";
	$q .= "\n		from PaperReview
		join ContactInfo using (contactId)
		left join ContactInfo as ReqCI on (ReqCI.contactId=PaperReview.requestedBy)\n";
	if (isset($selector["ratings"]))
	    $q .= "		left join ReviewRating on (ReviewRating.reviewId=PaperReview.reviewId)\n";
	if (isset($selector["myRating"]))
	    $q .= "		left join ReviewRating as MyRating on (MyRating.reviewId=PaperReview.reviewId and MyRating.contactId=" . $selector["myRating"] . ")\n";

	$where = array();
	if (isset($reviewId))
	    $where[] = "PaperReview.reviewId=$reviewId";
	if (isset($paperId))
	    $where[] = "PaperReview.paperId=$paperId";
	if (isset($selector['contactId']))
	    $where[] = "PaperReview.contactId=" . cvtint($selector['contactId']);
	if (isset($selector['reviewOrdinal']))
	    $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=" . cvtint($selector['reviewOrdinal']);
	else if (isset($selector['submitted']))
	    $where[] = "PaperReview.reviewSubmitted>0";
	if (!count($where)) {
	    $whyNot['internal'] = 1;
	    return null;
	}

	$q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId order by paperId, reviewOrdinal, reviewType desc, reviewId";

	$result = $this->q($q);
	if (!$result) {
	    $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars(mysql_error($this->dblink));
	    return null;
	}

	$x = array();
	while (($row = edb_orow($result)))
	    $x[] = $row;

	if (isset($selector['array']))
	    return $x;
	else if (count($x) == 1)
	    return $x[0];
	if (count($x) == 0)
	    $whyNot['noReview'] = 1;
	else
	    $whyNot['multipleReviews'] = 1;
	return null;
    }


    // Activity

    function _flowQueryWheres(&$where, $table, $t0) {
	$time = $table . ($table == "PaperReview" ? ".reviewSubmitted" : ".timeModified");
	if (is_array($t0))
	    $where[] = "($time<$t0[0] or ($time=$t0[0] and $table.contactId>$t0[1]) or ($time=$t0[0] and $table.contactId=$t0[1] and $table.paperId>$t0[2]))";
	else if ($t0)
	    $where[] = "$time<$t0";
    }

    function _commentFlowQuery($contact, $t0, $limit) {
	$q = "select PaperComment.*,
		substring(PaperComment.comment from 1 for 300) as shortComment,
		Paper.title,
		substring(Paper.title from 1 for 80) as shortTitle,
		Paper.timeSubmitted,
		Paper.timeWithdrawn,
		ContactInfo.firstName as reviewFirstName,
		ContactInfo.lastName as reviewLastName,
		ContactInfo.email as reviewEmail,
		PaperConflict.conflictType,
		MyPaperReview.reviewType as myReviewType,
		MyPaperReview.reviewSubmitted as myReviewSubmitted,
		MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit
		from PaperComment
		join ContactInfo on (ContactInfo.contactId=PaperComment.contactId)
		join Paper on (Paper.paperId=PaperComment.paperId)
		left join PaperConflict on (PaperConflict.paperId=PaperComment.paperId and PaperConflict.contactId=$contact->contactId)
		left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperComment.paperId and MyPaperReview.contactId=$contact->contactId)\n";
	$where = $contact->canViewCommentReviewWheres();
	self::_flowQueryWheres($where, "PaperComment", $t0);
	if (count($where))
	    $q .= " where " . join(" and ", $where);
	$q .= "	order by PaperComment.timeModified desc, PaperComment.contactId asc, PaperComment.paperId asc";
	if ($limit)
	    $q .= " limit $limit";
	return $q;
    }

    function _reviewFlowQuery($contact, $t0, $limit) {
	$q = "select PaperReview.*,
		Paper.title,
		substring(Paper.title from 1 for 80) as shortTitle,
		Paper.timeSubmitted,
		Paper.timeWithdrawn,
		ContactInfo.firstName as reviewFirstName,
		ContactInfo.lastName as reviewLastName,
		ContactInfo.email as reviewEmail,
		PaperConflict.conflictType,
		MyPaperReview.reviewType as myReviewType,
		MyPaperReview.reviewSubmitted as myReviewSubmitted,
		MyPaperReview.reviewNeedsSubmit as myReviewNeedsSubmit
		from PaperReview
		join ContactInfo on (ContactInfo.contactId=PaperReview.contactId)
		join Paper on (Paper.paperId=PaperReview.paperId)
		left join PaperConflict on (PaperConflict.paperId=PaperReview.paperId and PaperConflict.contactId=$contact->contactId)
		left join PaperReview as MyPaperReview on (MyPaperReview.paperId=PaperReview.paperId and MyPaperReview.contactId=$contact->contactId)\n";
	$where = $contact->canViewCommentReviewWheres();
	self::_flowQueryWheres($where, "PaperReview", $t0);
	$where[] = "PaperReview.reviewSubmitted>0";
	$q .= " where " . join(" and ", $where);
	$q .= "	order by PaperReview.reviewSubmitted desc, PaperReview.contactId asc, PaperReview.paperId asc";
	if ($limit)
	    $q .= " limit $limit";
	return $q;
    }

    function _activity_compar($a, $b) {
	if (!$a || !$b)
	    return !$a && !$b ? 0 : ($a ? -1 : 1);
	$at = isset($a->timeModified) ? $a->timeModified : $a->reviewSubmitted;
	$bt = isset($b->timeModified) ? $b->timeModified : $b->reviewSubmitted;
	if ($at != $bt)
	    return $at > $bt ? -1 : 1;
	else if ($a->contactId != $b->contactId)
	    return $a->contactId < $b->contactId ? -1 : 1;
	else if ($a->paperId != $b->paperId)
	    return $a->paperId < $b->paperId ? -1 : 1;
	else
	    return 0;
    }

    function reviewerActivity($contact, $t0, $limit) {
	// Return the $limit most recent pieces of activity on or before $t0.
	// Requires some care, since comments and reviews are loaded from
	// different queries, and we want to return the results sorted.  So we
	// load $limit comments and $limit reviews -- but if the comments run
	// out before the $limit is reached (because some comments cannot be
	// seen by the current user), we load additional comments & try again,
	// and the same for reviews.

	if ($t0 && preg_match('/\A(\d+)\.(\d+)\.(\d+)\z/', $t0, $m))
	    $ct0 = $rt0 = array($m[1], $m[2], $m[3]);
	else
	    $ct0 = $rt0 = $t0;
	$activity = array();

	$crows = $rrows = array(); // comment/review rows being worked through
	$curcr = $currr = null;	   // current comment/review row
	// We read new comment/review rows when the current set is empty.

	while (count($activity) < $limit) {
	    // load $curcr with most recent viewable comment
	    if ($curcr)
		/* do nothing */;
	    else if (($curcr = array_pop($crows))) {
		if (!$contact->canViewComment($curcr, $curcr, $whyNot, true)) {
		    $curcr = null;
		    continue;
		}
	    } else if ($ct0) {
		$crows = array_reverse($this->commentRows(self::_commentFlowQuery($contact, $ct0, $limit)));
		if (count($crows) == $limit)
		    $ct0 = array($crows[0]->timeModified, $crows[0]->contactId, $crows[0]->paperId);
		else
		    $ct0 = null;
		continue;
	    }

	    // load $currr with most recent viewable review
	    if ($currr)
		/* do nothing */;
	    else if (($currr = array_pop($rrows))) {
		if (!$contact->canViewReview($currr, $currr, $whyNot, true)) {
		    $currr = null;
		    continue;
		}
	    } else if ($rt0) {
		$rrows = array_reverse($this->reviewRows(self::_reviewFlowQuery($contact, $rt0, $limit)));
		if (count($rrows) == $limit)
		    $rt0 = array($rrows[0]->reviewSubmitted, $rrows[0]->contactId, $rrows[0]->paperId);
		else
		    $rt0 = null;
		continue;
	    }

	    // if neither, ran out of activity
	    if (!$curcr && !$currr)
		break;

	    // otherwise, choose the later one first
	    if (self::_activity_compar($curcr, $currr) < 0) {
		$curcr->isComment = true;
		$activity[] = $curcr;
		$curcr = null;
	    } else {
		$currr->isComment = false;
		$activity[] = $currr;
		$currr = null;
	    }
	}

	return $activity;
    }


    //
    // Message routines
    //

    function msg($text, $type) {
	$x = "<div class=\"$type\">$text</div>\n";
	if ($this->saveMessages) {
	    $this->ensureSession();
	    $_SESSION["msgs"][] = $x;
	} else if ($this->tableMessages) {
	    echo "<tr>
  <td class='caption'></td>
  <td class='entry' colspan='", $this->tableMessages, "'>", $x, "</td>
</tr>\n\n";
	    $this->msgcount++;
	} else {
	    echo $x;
	    $this->msgcount++;
	}
    }

    function infoMsg($text, $minimal = false) {
	$this->msg($text, $minimal ? "xinfo" : "info");
    }

    function warnMsg($text, $minimal = false) {
	$this->msg($text, $minimal ? "xwarning" : "warning");
    }

    function confirmMsg($text, $minimal = false) {
	$this->msg($text, $minimal ? "xconfirm" : "confirm");
    }

    function errorMsg($text, $minimal = false) {
	$this->msg($text, $minimal ? "xmerror" : "merror");
	return false;
    }

    function errorMsgExit($text) {
	$this->closeTableMessages();
	if ($text)
	    $this->msg($text, 'merror');
	$this->footer();
	exit;
    }

    function tableMsg($colspan, $obj = null) {
	$this->tableMessages = $colspan;
	$this->tableMessagesObj = $obj;
    }

    function conflictDefinitionText($usedefault = false) {
	if (!$usedefault && isset($this->settingTexts["conflictdefmsg"])
	    && ($x = $this->settingTexts["conflictdefmsg"]) != "")
	    return $x;
	else
	    return "This includes past advisors and students, people with the same affiliation, and any recent (~2 years) coauthors and collaborators.";
    }

    function tagRoundLocker($dolocker) {
	if (!$dolocker || !defval($this->settings, "rev_roundtag", ""))
	    return "";
	else if (strpos(defval($this->settingTexts, "tag_rounds", ""), " " . $this->settingTexts["rev_roundtag"] . " ") === false)
	    return ", Settings write, PaperTag write";
	else
	    return ", PaperTag write";
    }


    //
    // Conference header, footer
    //

    function header_css_link($css) {
	global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH;
	echo "<link rel='stylesheet' type='text/css' href=\"";
	if (strpos($css, "/") === false
	    && ($mtime = @filemtime("$ConfSitePATH/$css")) !== false)
	    echo "${ConfSiteBase}cacheable$ConfSiteSuffix?file=", urlencode($css), "&amp;mtime=", $mtime, "\" />\n";
	else
	    echo str_replace("\"", "&quot;", $css), "\" />\n";
    }

    function header_head($title) {
	global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH, $Opt;
	if (!$this->headerPrinted) {
	    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd\">\n";
	    // PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">\n";
	    echo "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">
<head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Style-Type\" content=\"text/css\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />\n";
	    if (strstr($title, "<") !== false)
		$title = preg_replace("/<([^>\"']|'[^']*'|\"[^\"]*\")*>/", "", $title);

	    $this->header_css_link("style.css");
	    if (isset($Opt["stylesheets"]))
		foreach ($Opt["stylesheets"] as $css)
		    $this->header_css_link($css);

	    // favicon
	    if (($favicon = defval($Opt, "favicon", "images/review24.png"))) {
		$url = (strpos($favicon, "://") !== false || $favicon[0] == "/" ? $favicon : $ConfSiteBase . $favicon);
		if (substr($favicon, -4) == ".png")
		    echo "<link rel=\"icon\" type=\"image/png\" href=\"$url\" />\n";
		else if (substr($favicon, -4) == ".ico")
		    echo "<link rel=\"shortcut icon\" href=\"$url\" />\n";
		else if (substr($favicon, -4) == ".gif")
		    echo "<link rel=\"icon\" type=\"image/gif\" href=\"$url\" />\n";
		else
		    echo "<link rel=\"icon\" href=\"$url\" />\n";
	    }

	    $this->scriptStuff = "<script type='text/javascript' src='${ConfSiteBase}cacheable$ConfSiteSuffix?file=script.js&amp;mtime=" . filemtime("$ConfSitePATH/script.js") . "'></script>\n";
	    $this->scriptStuff .= "<!--[if lte IE 6]> <script type='text/javascript' src='${ConfSiteBase}cacheable$ConfSiteSuffix?file=supersleight.js'></script> <![endif]-->\n";
	    echo "<title>", $title, " - ", htmlspecialchars($Opt["shortName"]), "</title>\n";
	    $this->headerPrinted = 1;
	}
    }

    function header($title, $id = "", $actionBar = null, $showTitle = true) {
	global $ConfSiteBase, $ConfSiteSuffix, $Me, $Opt;
	if ($this->headerPrinted >= 2)
	    return;
	if ($actionBar === null)
	    $actionBar = actionBar();

	$this->header_head($title);
	$dl = $Me->deadlines();
	$now = $dl["now"];
	echo "</head><body";
	if ($id)
	    echo " id='$id'";
	// JavaScript's idea of a timezone offset is the negative of PHP's
	$this->footerScript("hotcrpLoad.time($now," . (-date("Z", $now) / 60) . "," . (defval($Opt, "time24hour") ? 1 : 0) . ")");
	$this->footerScript("loadDeadlines.init(" . json_encode($dl) . ",\"" . hoturl("deadlines") . "\")");
	echo " onload='hotcrpLoad()'>\n";

	echo "<div id='prebody'>\n";

	echo "<div id='header'>\n<div id='header_left_conf'><h1>";
	if ($title && $showTitle && $title == "Home")
	    echo "<a class='q' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a>";
	else
	    echo "<a class='x' href='", hoturl("index"), "' title='Home'>", htmlspecialchars($Opt["shortName"]), "</a></h1></div><div id='header_left_page'><h1>", $title;
	echo "</h1></div><div id='header_right'>";
	if ($Me->valid()) {
	    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
	    if ($Me->contactId > 0) {
		echo "<a class='q' href='", hoturl("account"), "'><strong>",
		    htmlspecialchars($Me->email),
		    "</strong></a> &nbsp; <a href='", hoturl("account"), "'>Profile</a>",
		    $xsep;
	    }
	    if ($Me->chairContact) {
		if (!$Me->privChair)
		    echo "<a href=\"", selfHref(array("chairMode" => 0)), "\">Admin&nbsp;<img src='${ConfSiteBase}images/viewas.png' alt='[Return to administrator view]' /></a>", $xsep;
		else if (!is_int($Me->chairContact))
		    echo "<a href=\"", selfHref(array("viewContact" => $Me->chairContact)), "\">", htmlspecialchars($Me->chairContact), "&nbsp;<img src='${ConfSiteBase}images/viewas.png' alt='[Unprivileged view]' /></a>", $xsep;
	    }
	    $x = ($id == "search" ? "t=$id" : ($id == "settings" ? "t=chair" : ""));
	    echo "<a href='", hoturl("help", $x), "'>Help</a>", $xsep;
	    if ($Me->contactId > 0)
		echo "<a href='", hoturl("index", "signout=1"), "'>Sign&nbsp;out</a>";
	    else
		echo "<a href='", hoturl("index", "signin=1"), "'>Sign&nbsp;in</a>";
	}
	echo "<div id='maindeadline' style='display:none'>";

	// This is repeated in script.js:loadDeadlines
	$dlname = "";
	$dltime = 0;
	if ($dl["sub_open"]) {
	    foreach (array("sub_reg" => "registration", "sub_update" => "update", "sub_sub" => "submission") as $subtype => $subname)
		if (isset($dl["${subtype}_ingrace"]) || $now <= defval($dl, $subtype, 0)) {
		    $dlname = "Paper $subname deadline";
		    $dltime = defval($dl, $subtype, 0);
		    break;
		}
	}
	if ($dlname) {
	    $s = "<a href=\"" . hoturl("deadlines") . "\">$dlname</a> ";
	    if (!$dltime || $dltime <= $now)
		$s .= "is NOW";
	    else
		$s .= "in " . $this->printableInterval($dltime - $now);
	    if (!$dltime || $dltime - $now <= 180)
		$s = "<span class='impending'>$s</span>";
	    echo $s;
	}

	echo "</div></div>\n";
	//echo "    <div id='header_public'><a href=\"", htmlspecialchars($Opt['conferenceSite']), "\">", htmlspecialchars($Opt["shortName"]), " Public Site</a></div>\n";

	echo "  <div class='clear'></div>\n";

	echo $actionBar;

	echo "</div>\n";

	if (isset($_SESSION["msgs"])) {
	    echo "<div id='initialmsgs'>\n";
	    foreach ($_SESSION["msgs"] as $m) {
		echo $m;
		$this->msgcount++;
	    }
	    unset($_SESSION["msgs"]);
	    echo "</div>\n";
	}
	$this->saveMessages = false;

	$this->headerPrinted = 2;
	echo "</div>\n<div class='body'>\n";
    }

    function closeTableMessages() {
	if ($this->tableMessages) {
	    echo "<tr>
  <td class='caption final'></td>
  <td class='entry final' colspan='2'></td>
</tr>
</table>\n\n";
	    if ($this->tableMessagesObj)
		$this->tableMessagesObj->echoDivExit();
	    $this->tableMessages = false;
	}
    }

    function footer() {
	global $Opt;
	$this->closeTableMessages();
	echo "</div>\n", // class='body'
	    "<div id='footer'>\n  <div id='footer_crp'>",
	    defval($Opt, "extraFooter", ""),
	    "<a href='http://www.cs.ucla.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software";
	if (!defval($Opt, "noFooterVersion", 0))
	    echo "<!-- Version ", HOTCRP_VERSION, " -->";
	echo "</div>\n  <div class='clear'></div></div>\n";
	if ($this->footerScripting)
	    $this->footerHtml("");
	echo $this->scriptStuff, $this->footerStuff, "</body>\n</html>\n";
	$this->scriptStuff = "";
    }

    function ajaxExit($values = null, $div = false) {
	if (!$values)
	    $values = array();
	$t = "";
	foreach (defval($_SESSION, "msgs", array()) as $msg)
	    if (preg_match('|\A<div class="(.*?)">([\s\S]*)</div>\s*\z|', $msg, $m)) {
		if ($m[1] == "merror" && !isset($values["error"]))
		    $values["error"] = $m[2];
		if ($div)
		    $t .= "<div class=\"x$m[1]\">$m[2]</div>\n";
		else
		    $t .= "<span class=\"$m[1]\">$m[2]</span>\n";
	    }
	if (!isset($values["response"]))
	    $values["response"] = $t;
	unset($_SESSION["msgs"]);
	header("Content-Type: text/json");
	echo json_encode($values);
	exit;
    }


    //
    // Action recording
    //

    function log($text, $who, $paperId = null) {
	if (is_array($paperId)) {
	    if (count($paperId) == 0)
		$paperId = null;
	    else if (count($paperId) == 1)
		$paperId = $paperId[0];
	    else {
		$text .= " (papers " . join(", ", $paperId) . ")";
		$paperId = null;
	    }
	}
	if ($paperId === null || $paperId === 0)
	    $paperId = "null";
	$this->q("insert into ActionLog (ipaddr, contactId, paperId, action) values ('" . sqlq($_SERVER['REMOTE_ADDR']) . "', " . (is_numeric($who) ? $who : $who->contactId) . ", $paperId, '" . sqlq($text) . "')");
    }


    //
    // Miscellaneous
    //

    function getFileExtension(&$mimetype) {
	if (!isset($mimetype) || $mimetype == null || $mimetype == "")
	    return "";
	else if ($mimetype == "application/postscript")
	    return ".ps";
	else if ($mimetype == "application/pdf")
	    return ".pdf";
	else if ($mimetype == "text/plain")
	    return ".txt";
	else
	    return "";
    }

    function makeDownloadPath($paperId, $mimetype, $documentType) {
	global $ConfSiteBase, $ConfSiteSuffix;
	if ($mimetype)
	    return $ConfSiteBase . "doc$ConfSiteSuffix/" . $this->downloadPaperName($paperId, $mimetype, $documentType);
	else {
	    $x = $ConfSiteBase . "doc$ConfSiteSuffix?p=" . $paperId;
	    if ($documentType < 0)
		return $x . "&amp;final=1";
	    else if ($documentType > 0)
		return $x . "&amp;dt=$documentType";
	    else
		return $x;
	}
    }

    function allowEmailTo($email) {
	global $Opt;
	return $Opt['sendEmail'] && strstr($email, "@")
	    && !preg_match('/@_\.com$/', $email);
    }

    function textValuesGraph($v, $max, $style, $myscore = 0, $levelChar = 0) {
	global $ConfSiteBase, $ConfSiteSuffix;

	if (is_string($v))
	    $v = scoreCounts($v, $max);

	$avgtext = unparseScoreAverage($v->avg, $levelChar);
	if ($v->n > 1)
	    $avgtext .= sprintf(" &plusmn; %0.2f", $v->stddev);

	$url = "";
	for ($key = 1; $key <= $v->max; $key++)
	    $url .= ($url == "" ? "" : ",") . $v->v[$key];
	$url = "${ConfSiteBase}images/GenChart$ConfSiteSuffix?v=$url";
	if ($myscore && $v->v[$myscore] > 0)
	    $url .= "&h=$myscore";
	if ($levelChar > 1)
	    $url .= "&c=" . chr($levelChar - 1);

	if ($style == 1) {
	    $retstr = "<img src=\"" . htmlspecialchars($url) . "&amp;s=1\" alt=\"$avgtext\" title=\"$avgtext\" width='" . (5 * $v->max + 3)
		. "' height='" . (5 * max(3, max($v->v)) + 3) . "' />";
	} else if ($style == 2) {
	    $retstr = "<div class='sc'><img src=\"" . htmlspecialchars($url) . "&amp;s=2\" alt=\"$avgtext\" title=\"$avgtext\" /><br />";
	    if ($levelChar > 1)
		for ($key = $v->max; $key >= 1; $key--) {
		    $xkey = (int) (($key - 1) * 8.0 / ($v->max - 1) + 1.5);
		    $retstr .= ($key < $v->max ? " " : "") . "<span class='sc$xkey'>" . $v->v[$key] . "</span>";
		}
	    else
		for ($key = 1; $key <= $v->max; $key++) {
		    $xkey = (int) (($key - 1) * 8.0 / ($v->max - 1) + 1.5);
		    $retstr .= ($key > 1 ? " " : "") . "<span class='sc$xkey'>" . $v->v[$key] . "</span>";
		}
	    $retstr .= "<br /><span class='sc_sum'>" . $avgtext . "</span></div>";
	}

	return $retstr;
    }

}
