<?php
// conference.inc -- HotCRP central helper class (singleton)
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
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

    var $scriptStuff;
    var $footerStuff;
    var $footerScripting;
    var $footerMap;
    var $usertimeId;

    function __construct() {
	global $Opt;

	$this->saveMessages = true;
	$this->headerPrinted = 0;
	$this->tableMessages = false;
	$this->scriptStuff = "";
	$this->footerStuff = "";
	$this->footerScripting = false;
        $this->footerMap = null;
	$this->usertimeId = 1;

	// unpack dsn and connect to database
	if (!isset($Opt["dsn"]))
	    die("Package misconfigured: \$Opt[\"dsn\"] is not set.  Perhaps the web server cannot read <tt>Code/options.inc</tt>?");
	else if (preg_match('|^mysql://([^:@/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = new mysqli(urldecode($m[1]));
	    $dbname = urldecode($m[2]);
	} else if (preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = new mysqli(urldecode($m[2]), urldecode($m[1]));
	    $dbname = urldecode($m[3]);
	} else if (preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $Opt['dsn'], $m)) {
	    $this->dblink = new mysqli(urldecode($m[3]), urldecode($m[1]), urldecode($m[2]));
	    $dbname = urldecode($m[4]);
	} else
	    die("Package misconfigured: dsn syntax error");

	if (!$this->dblink || mysqli_connect_errno()
            || !$this->dblink->select_db($dbname)) {
	    // Obscure password
	    $dsn = preg_replace('{\A(\w+://[^/:]*:)[^\@/]+([\@/])}', '$1PASSWORD$2', $Opt["dsn"]);
	    die("Unable to connect to database at " . $dsn);
	}
	$this->dblink->query("set names 'utf8'");
	// XXX NB: Many MySQL versions, if not all of them, will ignore the
	// @@max_allowed_packet setting.  Keeping the code in case it's
	// useful for some installations.
	$max_file_size = ini_get_bytes("upload_max_filesize");
	$this->dblink->query("set @@max_allowed_packet = $max_file_size");

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
            session_cache_limiter("");
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
	if ($this->settings["allowPaperOption"] < 50) {
	    require_once("updateschema.inc");
	    $oldOK = $OK;
	    updateSchema($this);
	    $OK = $oldOK;
	}
	$this->sversion = $this->settings["allowPaperOption"];
	if (isset($this->settings["frombackup"]) && $this->invalidateCaches()) {
	    $this->qe("delete from Settings where name='frombackup' and value=" . $this->settings["frombackup"]);
	    unset($this->settings["frombackup"]);
	}
	if (isset($Opt["ldapLogin"]) && !$Opt["ldapLogin"])
	    unset($Opt["ldapLogin"]);
	if (isset($Opt["httpAuthLogin"]) && !$Opt["httpAuthLogin"])
	    unset($Opt["httpAuthLogin"]);
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
	// that "+" is re-encoded as "-", "/" is re-encoded as "_", and
	// trailing "="s are removed.
	//
	// Any user who knows the conference's cap_key can construct any
	// capability for any paper.  Longer term, one might set each paper's
	// capVersion to a random value; but the only way to get cap_key is
	// database access, which would give you all the capVersions anyway.

	if (!isset($this->settingTexts["cap_key"]))
	    return false;
	$start = "0" . $prow->paperId . $capType;
	$hash = sha1($start . $prow->capVersion . $this->settingTexts["cap_key"], true);
	$suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
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

    function save_setting($name, $value, $data = null) {
	if ($value === null && $data === null) {
	    if ($this->qe("delete from Settings where name='" . sqlq($name) . "'")) {
		unset($this->settings[$name]);
		unset($this->settingTexts[$name]);
	    }
	} else {
	    if ($this->qe("insert into Settings (name, value, data) values ('" . sqlq($name) . "', " . $value . ", " . ($data === null ? "null" : "'" . sqlq($data) . "'") . ") on duplicate key update value=values(value), data=values(data)", "while updating settings")) {
		$this->settings[$name] = $value;
		$this->settingTexts[$name] = $data;
	    }
	}
    }

    function invalidateCaches($caches = null) {
	global $OK;
	$this->ensureSession();
	$inserts = array();
	$removes = array();
	$time = time();
	if ($caches ? isset($caches["pc"]) : $this->setting("pc") > 0) {
	    if (!$caches || $caches["pc"]) {
		$inserts[] = "('pc',$time)";
		$this->settings["pc"] = $time;
	    } else {
		$removes[] = "'pc'";
		unset($this->settings["pc"]);
	    }
	    unset($_SESSION["pcmembers"]);
	}
	if ($caches ? isset($caches["paperOption"]) : $this->setting("paperOption") > 0) {
	    if (!$caches || $caches["paperOption"]) {
		$inserts[] = "('paperOption',$time)";
		$this->settings["paperOption"] = $time;
	    } else {
		$removes[] = "'paperOption'";
		unset($this->settings["paperOption"]);
	    }
	    unset($_SESSION["paperOption"]);
	}
	if (!$caches || isset($caches["rf"])) {
	    $inserts[] = "('revform_update',$time)";
	    $this->settings["revform_update"] = $time;
	    unset($_SESSION["rf"]);
	}
	$ok = true;
	if (count($inserts))
	    $ok = $ok && ($this->qe("insert into Settings (name, value) values " . join(",", $inserts) . " on duplicate key update value=values(value)") !== false);
	if (count($removes))
	    $ok = $ok && ($this->qe("delete from Settings where name in (" . join(",", $removes) . ")") !== false);
	return $ok;
    }

    function qx($query) {
	return $this->dblink->query($query);
    }

    function q($query) {
	global $OK;
	$result = $this->dblink->query($query);
	if ($result === false)
	    $OK = false;
	return $result;
    }

    function db_error_html($getdb = true, $while = "", $suggestRetry = true) {
	global $Opt;
	$text = "<p>Database error";
	if ($while)
	    $text .= " $while";
	if ($getdb)
	    $text .= ": " . htmlspecialchars($this->dblink->error) . "</p>";
	if ($suggestRetry)
	    $text .= "\n<p>Please try again or contact the site administrator at " . $Opt["emailFrom"] . ".</p>";
	return $text;
    }

    function qe($query, $while = "", $suggestRetry = false) {
	global $OK;
	$result = $this->dblink->query($query);
	if ($result === false) {
	    $this->errorMsg($this->db_error_html(true, $while . " (" . htmlspecialchars($query) . ")", $suggestRetry));
	    $OK = false;
	}
	return $result;
    }

    function lastInsertId($while = "", $suggestRetry = false) {
	global $OK;
	$result = $this->dblink->insert_id;
	if (!$result && $while !== false) {
	    $this->errorMsg($this->db_error_html($result === false, $while, $suggestRetry));
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
	if ($newguy->initialize($email)) {
	    // rigamarole to handle separate or joined first and last names
	    if (isset($_REQUEST["${p}firstName"]) || isset($_REQUEST["${p}lastName"])) {
		if (isset($_REQUEST["${p}firstName"]))
		    $newguy->firstName = $_REQUEST["${p}firstName"];
		if (isset($_REQUEST["${p}lastName"]))
		    $newguy->lastName = $_REQUEST["${p}lastName"];
	    } else if (!isset($_REQUEST["${p}name"]))
		/* nada */;
	    else {
		$matches = Text::split_name($_REQUEST["${p}name"]);
		$newguy->firstName = $matches[0];
		$newguy->lastName = $matches[1];
	    }

	    // other information is easier
	    if (isset($_REQUEST["${p}affiliation"]))
		$newguy->affiliation = $_REQUEST["${p}affiliation"];
	    if (isset($_REQUEST["${p}voicePhoneNumber"]))
		$newguy->voicePhoneNumber = $_REQUEST["${p}voicePhoneNumber"];

	    // actually update database
	    $result = $newguy->updateDB("while creating account for " . htmlspecialchars($email));
	} else
	    $result = false;

	if ($result) {
	    if ($Me->privChair)
		$this->infoMsg("Created account for " . htmlspecialchars($email) . ".");
	    if ($sendInfo)
		$newguy->sendAccountInfo(true, false);
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

    static function _dateFormat($long) {
        global $Opt;
        if (!isset($Opt["_dateFormatInitialized"])) {
            if (!isset($Opt["time24hour"]) && isset($Opt["time24Hour"]))
                $Opt["time24hour"] = $Opt["time24Hour"];
            if (!isset($Opt["dateFormatLong"]) && isset($Opt["dateFormat"]))
                $Opt["dateFormatLong"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormat"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormat"] = "j M Y H:i:s";
                else
                    $Opt["dateFormat"] = "j M Y g:i:sa";
            }
            if (!isset($Opt["dateFormatLong"]))
                $Opt["dateFormatLong"] = "l " . $Opt["dateFormat"];
            if (!isset($Opt["timestampFormat"]))
                $Opt["timestampFormat"] = $Opt["dateFormat"];
            if (!isset($Opt["dateFormatSimplifier"])) {
                if (isset($Opt["time24hour"]) && $Opt["time24hour"])
                    $Opt["dateFormatSimplifier"] = "/:00(?!:)/";
                else
                    $Opt["dateFormatSimplifier"] = "/:00(?::00|)(?= ?[ap]m)/";
            }
            if (!isset($Opt["dateFormatTimezone"]))
                $Opt["dateFormatTimezone"] = null;
            $Opt["_dateFormatInitialized"] = true;
        }
        if ($long == "timestamp")
            return $Opt["timestampFormat"];
        else if ($long)
            return $Opt["dateFormatLong"];
        else
            return $Opt["dateFormat"];
    }

    function parseableTime($value, $include_zone) {
	global $Opt;
        $f = self::_dateFormat(false);
        $d = date($f, $value);
        if ($Opt["dateFormatSimplifier"])
            $d = preg_replace($Opt["dateFormatSimplifier"], "", $d);
        if ($include_zone) {
            if ($Opt["dateFormatTimezone"] === null)
                $d .= " " . date("T", $value);
            else if ($Opt["dateFormatTimezone"])
                $d .= " " . $Opt["dateFormatTimezone"];
        }
        return $d;
    }
    function _printableTime($value, $long, $useradjust, $preadjust = null) {
	global $Opt;
	if ($value <= 0)
	    return "N/A";
        $t = date(self::_dateFormat($long), $value);
        if ($Opt["dateFormatSimplifier"])
            $t = preg_replace($Opt["dateFormatSimplifier"], "", $t);
        if ($Opt["dateFormatTimezone"] === null)
            $t .= " " . date("T", $value);
        else if ($Opt["dateFormatTimezone"])
            $t .= " " . $Opt["dateFormatTimezone"];
        if ($preadjust)
            $t .= $preadjust;
	if ($useradjust) {
	    $sp = strpos($useradjust, " ");
	    $t .= "<$useradjust class='usertime' id='usertime$this->usertimeId' style='display:none'></" . ($sp ? substr($useradjust, 0, $sp) : $useradjust) . ">";
	    $this->footerScript("setLocalTime('usertime$this->usertimeId',$value)");
	    ++$this->usertimeId;
	}
	return $t;
    }
    function printableTime($value, $useradjust = false, $preadjust = null) {
	return $this->_printableTime($value, true, $useradjust, $preadjust);
    }
    function printableTimestamp($value, $useradjust = false, $preadjust = null) {
	return $this->_printableTime($value, "timestamp", $useradjust, $preadjust);
    }
    function printableTimeShort($value, $useradjust = false, $preadjust = null) {
	return $this->_printableTime($value, false, $useradjust, $preadjust);
    }

    function printableTimeSetting($what, $useradjust = false, $preadjust = null) {
	return $this->printableTime(defval($this->settings, $what, 0), $useradjust, $preadjust);
    }
    function printableDeadlineSetting($what, $useradjust = false, $preadjust = null) {
	if (!isset($this->settings[$what]) || $this->settings[$what] <= 0)
	    return "No deadline";
	else
	    return "Deadline: " . $this->printableTime($this->settings[$what], $useradjust, $preadjust);
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
	// and comments.  see also mailtemplate.inc and genericWatch
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
	if (!($requested || ($isPC && $this->settings["pcrev_any"] > 0)))
	    return false;
	$od = ($assumeOpen ? "" : "rev_open");
	$d = ($isPC ? "pcrev_" : "extrev_") . ($hard ? "hard" : "soft");
	return $this->deadlinesBetween($od, $d, "rev_grace") > 0;
    }
    function timePCReviewPreferences() {
	return defval($this->settings, "papersub") > 0;
    }
    function timePCViewAllReviews($myReviewNeedsSubmit = false, $reviewsOutstanding = false) {
	return ($this->settingsAfter("pc_seeallrev")
		&& (!$myReviewNeedsSubmit || $this->settings["pc_seeallrev"] != 3)
		&& (!$reviewsOutstanding || $this->settings["pc_seeallrev"] != 4));
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

    function footerHtml($html, $uniqueid = null) {
	if ($this->footerScripting) {
	    $this->footerStuff .= "</script>";
	    $this->footerScripting = false;
	}
        if ($uniqueid) {
            if (!$this->footerMap)
                $this->footerMap = array();
            if (isset($this->footerMap[$uniqueid]))
                return;
            $this->footerMap[$uniqueid] = true;
        }
	$this->footerStuff .= $html;
    }


    //
    // Paper storage
    //

    function storeDocument($uploadId, $paperId, $documentType) {
        return DocumentHelper::upload(new HotCRPDocument($documentType),
                                      $uploadId,
                                      (object) array("paperId" => $paperId));
    }

    function storePaper($uploadId, $prow, $final) {
	global $ConfSiteSuffix, $Opt;
	$paperId = (is_numeric($prow) ? $prow : $prow->paperId);

	$doc = $this->storeDocument($uploadId, $paperId, $final ? DTYPE_FINAL : DTYPE_SUBMISSION);
        if (isset($doc->error_html)) {
            $this->errorMsg($doc->error_html);
            return false;
        }

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
		$autxt .= Text::user_text($au) . "\n";
	    if ($autxt)
		$message .= wordWrapIndent($autxt, "Contact authors: ");
	    $message .= "\n";

	    $mime = new Mail_mime("\n");
	    $mime->setTXTBody($message);
	    $mime->addAttachment($doc->content, $doc->mimetype,
                                 HotCRPDocument::filename($doc), false);

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
	$dtn = HotCRPDocument::unparse_dtype($documentType);
	$title .= ($dtn ? $dtn : "xxx");
	if (ctype_digit($title[strlen($title) - 1]))
	    $title .= "-";
	return $title . $paperId . Mimetype::extension($mimetype);
    }

    function paperStorageResult($prow, $documentType, $skip_content = false) {
	global $Opt;
	if (is_array($prow) && count($prow) <= 1)
	    $prow = (count($prow) ? $prow[0] : -1);
	if (is_numeric($prow))
	    $paperMatch = "=" . $prow;
	else if (is_array($prow))
	    $paperMatch = " in (" . join(",", $prow) . ")";
	else
	    $paperMatch = "=" . $prow->paperId;
	$q = "select p.paperId, s.mimetype, s.sha1, ";
	if (!defval($Opt, "filestore") && !$skip_content)
	    $q .= "s.paper as content, ";
	if ($this->sversion >= 45)
	    $q .= "s.filename, ";
	$q .= "$documentType documentType, s.paperStorageId from Paper p";
	if ($documentType == DTYPE_SUBMISSION)
	    $sjoin = "p.paperStorageId";
	else if ($documentType == DTYPE_FINAL)
	    $sjoin = "p.finalPaperStorageId";
	else {
	    $q .= " left join PaperOption o on (o.paperId=p.paperId and o.optionId=$documentType)";
	    $sjoin = "o.value";
	}
	return $this->q($q . " left join PaperStorage s on (s.paperStorageId=$sjoin) where p.paperId$paperMatch");
    }

    function paperStorageRow($result, $dtype = DTYPE_SUBMISSION) {
	if (!($doc = edb_orow($result)))
	    return $doc;
        // type doesn't matter
        $docclass = new HotCRPDocument($dtype);
        // in modern versions sha1 is set at storage time; before it wasn't
	if ($doc->paperStorageId && $doc->sha1 == "") {
	    if (!$docclass->load_database_content($doc))
		return false;
	    $doc->sha1 = sha1($doc->content, true);
	    $this->q("update PaperStorage set sha1='" . sqlq($doc->sha1) . "' where paperStorageId=" . $row->paperStorageId);
	}
        DocumentHelper::load($docclass, $doc);
	return $doc;
    }

    function __downloadPaper($paperId, $attachment, $documentType) {
	global $Opt, $Me, $zlib_output_compression;

        $result = $this->paperStorageResult($paperId, $documentType);
        if (!$result) {
	    $this->log("Error downloading for review: " . $this->dblink->error, $Me, $paperId);
	    return set_error_html("Database error while downloading paper.");
	} else if (edb_nrows($result) == 0)
	    return set_error_html("No such document.");

	// Check data
        $docs = array();
	while (($doc = $this->paperStorageRow($result, $documentType))) {
            if (!$doc->mimetype)
                $doc->mimetype = MIMETYPEID_PDF;
            $doc->filename = HotCRPDocument::filename($doc);
            $docs[] = $doc;
        }
	if (count($docs) == 1 && $docs[0]->paperStorageId <= 1)
	    return set_error_html("Paper #" . $docs[0]->paperId . " hasn’t been uploaded yet.");
        $downloadname = false;
        if (count($docs) > 1)
            $downloadname = $Opt["downloadPrefix"] . pluralx(2, HotCRPDocument::unparse_dtype($documentType)) . ".zip";
        return DocumentHelper::download($docs, $attachment, $downloadname);
    }

    function downloadPaper($paperId, $attachment, $documentType = DTYPE_SUBMISSION) {
	global $Me;
	$result = $this->__downloadPaper($paperId, $attachment, $documentType);
	if ($result->error) {
	    $this->errorMsg($result->error_html);
            return false;
        } else {
	    $this->log("Downloaded paper", $Me, $paperId);
            return true;
        }
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
	$where = array();

	// fields
	$pq = "select Paper.*, PaperConflict.conflictType,
		count(AllReviews.reviewSubmitted) as reviewCount,
		count(if(AllReviews.reviewNeedsSubmit<=0,AllReviews.reviewSubmitted,AllReviews.reviewId)) as startedReviewCount";
	$myPaperReview = null;
	if (!isset($options["author"])) {
	    if ($allReviewerQuery)
		$myPaperReview = "MyPaperReview";
            else
		$myPaperReview = "PaperReview";
            // see also papercolumn.php
	    $pq .= ",
		PaperReview.reviewType,
		PaperReview.reviewId,
		PaperReview.reviewModified,
		PaperReview.reviewSubmitted,
		PaperReview.reviewNeedsSubmit,
		PaperReview.reviewOrdinal,
		PaperReview.reviewBlind,
		PaperReview.contactId as reviewContactId,
		PaperReview.requestedBy,
		max($myPaperReview.reviewType) as myReviewType,
		max($myPaperReview.reviewSubmitted) as myReviewSubmitted,
		min($myPaperReview.reviewNeedsSubmit) as myReviewNeedsSubmit,
		PaperReview.reviewRound";
	} else
	    $pq .= ",\nnull reviewType, null reviewId, null myReviewType";
	if (isset($options['reviewerName']))
	    $pq .= ",
		ReviewerContactInfo.firstName as reviewFirstName,
		ReviewerContactInfo.lastName as reviewLastName,
		ReviewerContactInfo.email as reviewEmail,
		ReviewerContactInfo.lastLogin as reviewLastLogin";
	if ($reviewerQuery || $scoresQuery) {
	    $rf = reviewForm();
	    $pq .= ",\n\t\tPaperReview.reviewEditVersion as reviewEditVersion";
	    foreach ($rf->forder as $f)
		if (!$scoresQuery || $f->has_options)
		    $pq .= ",\n\t\tPaperReview.$f->id as $f->id";
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
		if ($myPaperReview)
		    $pq .= ",\n		$myPaperReview.$field";
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
	else if (!isset($options["author"]))
	    $pq .= "		left join PaperReview on (PaperReview.paperId=Paper.paperId and (PaperReview.contactId=$contactId$qr))\n";
	if ($myPaperReview == "MyPaperReview")
	    $pq .= "		left join PaperReview as MyPaperReview on (MyPaperReview.paperId=Paper.paperId and MyPaperReview.contactId=$contactId)\n";
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
	    $pq .= " from PaperTopic left join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=$contactId) group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)\n";
	}

	if (isset($options['options']) && defval($this->settings, "paperOption")) {
	    $pq .= "		left join (select paperId, group_concat(PaperOption.optionId, '#', value) as optionIds from PaperOption group by paperId) as PaperOptions on (PaperOptions.paperId=Paper.paperId)\n";
	}

	if (isset($options['tags']))
	    $pq .= "		left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)\n";
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
	    $pq .= "		left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=$contactId)\n";
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
	    $pq .= "		left join (select paperId, group_concat(concat(contactId,' ',conflictType) separator ',') as allConflictType from PaperConflict where conflictType>0 group by paperId) as AllConflict on (AllConflict.paperId=Paper.paperId)\n";


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
		$whyNot['dbError'] = "Database error while fetching paper (" . htmlspecialchars($q) . "): " . $this->dblink->error;
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
	    if (isset($row->commentContactId))
		$cid = $row->commentContactId;
	    else
		$cid = $row->contactId;
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
	$order = array("paperId");
	if (isset($reviewId))
	    $where[] = "PaperReview.reviewId=$reviewId";
	if (isset($paperId))
	    $where[] = "PaperReview.paperId=$paperId";
	$cwhere = array();
	if (isset($selector["contactId"]))
	    $cwhere[] = "PaperReview.contactId=" . cvtint($selector["contactId"]);
	if (isset($selector["rev_tokens"]) && count($selector["rev_tokens"]))
	    $cwhere[] = "PaperReview.reviewToken in (" . join(",", $selector["rev_tokens"]) . ")";
	if (count($cwhere))
	    $where[] = "(" . join(" or ", $cwhere) . ")";
	if (count($cwhere) > 1)
	    $order[] = "(PaperReview.contactId=" . cvtint($selector["contactId"]) . ") desc";
	if (isset($selector['reviewOrdinal']))
	    $where[] = "PaperReview.reviewSubmitted>0 and reviewOrdinal=" . cvtint($selector['reviewOrdinal']);
	else if (isset($selector['submitted']))
	    $where[] = "PaperReview.reviewSubmitted>0";
	if (!count($where)) {
	    $whyNot['internal'] = 1;
	    return null;
	}

	$q = $q . " where " . join(" and ", $where) . " group by PaperReview.reviewId
		order by " . join(", ", $order) . ", reviewOrdinal, reviewType desc, reviewId";

	$result = $this->q($q);
	if (!$result) {
	    $whyNot['dbError'] = "Database error while fetching review (" . htmlspecialchars($q) . "): " . htmlspecialchars($this->dblink->error);
	    return null;
	}

	$x = array();
	while (($row = edb_orow($result)))
	    $x[] = $row;

	if (isset($selector["array"]))
	    return $x;
	else if (count($x) == 1 || defval($selector, "first"))
	    return $x[0];
	if (count($x) == 0)
	    $whyNot['noReview'] = 1;
	else
	    $whyNot['multipleReviews'] = 1;
	return null;
    }


    function preferenceConflictQuery($type, $extra) {
	$q = "select PRP.paperId, PRP.contactId, PRP.preference
		from PaperReviewPreference PRP
		join PCMember PCM on (PCM.contactId=PRP.contactId)
		join Paper P on (P.paperId=PRP.paperId)
		left join PaperConflict PC on (PC.paperId=PRP.paperId and PC.contactId=PRP.contactId)
		where PRP.preference<=-100 and coalesce(PC.conflictType,0)<=0
		  and P.timeWithdrawn<=0";
	if ($type != "all" && ($type || $this->setting("pc_seeall") <= 0))
	    $q .= " and P.timeSubmitted>0";
	if ($extra)
	    $q .= " " . $extra;
	return $q;
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
		Paper.blind as paperBlind,
		Paper.outcome,
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
		Paper.blind as paperBlind,
		Paper.outcome,
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
		if (!$contact->canViewComment($curcr, $curcr, false)) {
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
		if (!$contact->canViewReview($currr, $currr, false)) {
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
	} else if ($this->tableMessages)
	    echo "<tr>
  <td class='caption'></td>
  <td class='entry' colspan='", $this->tableMessages, "'>", $x, "</td>
</tr>\n\n";
	else
	    echo $x;
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
	if (!$dolocker)
	    return "";
	else if (!defval($this->settings, "rev_roundtag", ""))
	    return ", Settings write";
	else
	    return ", Settings write, PaperTag write";
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
	    echo "<!DOCTYPE html>
<html>
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
	global $ConfSiteBase, $ConfSiteSuffix, $ConfSitePATH, $Me, $Opt;
	if ($this->headerPrinted >= 2)
	    return;
	if ($actionBar === null)
	    $actionBar = actionBar();

	$this->header_head($title);
	$dl = $Me->deadlines();
	$now = $dl["now"];
	echo "</head><body", ($id ? " id='$id'" : ""), " onload='hotcrp_load()'>\n";
	// JavaScript's idea of a timezone offset is the negative of PHP's
	$this->footerScript("hotcrp_load.time($now," . (-date("Z", $now) / 60) . "," . (defval($Opt, "time24hour") ? 1 : 0) . ");"
                            . "loadDeadlines.init(" . json_encode($dl) . ",\"" . hoturl("deadlines") . "\")");
        if ($Me->isPC)
            $this->footerScript("alltags.url=\"" . hoturl("search", "alltags=1") . "\"");

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
		echo "<a class='q' href='", hoturl("profile"), "'><strong>",
		    htmlspecialchars($Me->email),
		    "</strong></a> &nbsp; <a href='", hoturl("profile"), "'>Profile</a>",
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
	    if ($Me->contactId > 0 || isset($Opt["httpAuthLogin"]))
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

	echo "</div>\n<div id=\"initialmsgs\">\n";
	if (isset($_SESSION["msgs"]) && count($_SESSION["msgs"])) {
	    foreach ($_SESSION["msgs"] as $m)
		echo $m;
	    unset($_SESSION["msgs"]);
            echo "<div id=\"initialmsgspacer\"></div>";
	}
	$this->saveMessages = false;
	echo "</div>\n";

	$this->headerPrinted = 2;
	echo "</div>\n<div class='body'>\n";

	// Callback for version warnings
	if ($Me->valid() && $Me->privChair
	    && (!isset($Me->_updatecheck) || $Me->_updatecheck + 20 <= $now)
	    && (!isset($Opt["updatesSite"]) || $Opt["updatesSite"])) {
	    $m = defval($Opt, "updatesSite", "http://hotcrp.lcdf.org/updates");
            if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
                $m = preg_replace(',\Ahttp://,', "https://", $m);
	    $m .= (strpos($m, "?") === false ? "?" : "&") . "version=" . HOTCRP_VERSION
                . "&addr=" . urlencode($_SERVER["SERVER_ADDR"]);
	    if (is_dir("$ConfSitePATH/.git")) {
		$args = array();
		exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git merge-base origin/master HEAD 2>/dev/null", $args);
		if (count($args) >= 1)
		    $m .= "&git-head=" . urlencode($args[0]);
		if (count($args) >= 2)
		    $m .= "&git-upstream=" . urlencode($args[1]);
	    }
	    $this->footerScript("check_version(\"$m\")");
	    $Me->_updatecheck = $now;
	}
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
	global $Opt, $Me, $ConfSitePATH;
	$this->closeTableMessages();
	echo "</div>\n", // class='body'
	    "<div id='footer'>\n  <div id='footer_crp'>",
	    defval($Opt, "extraFooter", ""),
	    "<a href='http://read.seas.harvard.edu/~kohler/hotcrp/'>HotCRP</a> Conference Management Software";
	if (!defval($Opt, "noFooterVersion", 0)) {
	    if ($Me->valid() && $Me->privChair) {
		echo " v", HOTCRP_VERSION;
		if (is_dir("$ConfSitePATH/.git")) {
		    $args = array();
		    exec("export GIT_DIR=" . escapeshellarg($ConfSitePATH) . "/.git; git rev-parse HEAD 2>/dev/null; git rev-parse v" . HOTCRP_VERSION . " 2>/dev/null", $args);
		    if (count($args) == 2 && $args[0] != $args[1])
			echo " [", substr($args[0], 0, 7), "...]";
		}
	    } else
		echo "<!-- Version ", HOTCRP_VERSION, " -->";
	}
	echo "</div>\n  <div class='clear'></div></div>\n";
	if ($this->footerScripting)
	    $this->footerHtml("");
	echo $this->scriptStuff, $this->footerStuff, "</body>\n</html>\n";
	$this->scriptStuff = "";
    }

    function ajaxExit($values = null, $div = false) {
        if ($values === false || $values === true)
            $values = array("ok" => $values);
	else if ($values === null)
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
	if (!isset($values["response"]) && $t !== "")
	    $values["response"] = $t;
	unset($_SESSION["msgs"]);
	if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
	    header("Content-Type: text/plain");
	else
	    header("Content-Type: application/json");
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

    function makeDownloadPath($doc) {
	global $ConfSiteBase, $ConfSiteSuffix;
        if (!property_exists($doc, "mimetype") || !isset($doc->documentType)) {
            $trace = debug_backtrace();
            error_log($trace[0]["file"] . ":" . $trace[0]["line"] . ": makeDownloadPath called with incomplete document");
        }
	if ($doc->mimetype)
	    return $ConfSiteBase . "doc$ConfSiteSuffix/" . HotCRPDocument::filename($doc);
	else {
	    $x = $ConfSiteBase . "doc$ConfSiteSuffix?p=" . $doc->paperId;
	    if ($doc->documentType == DTYPE_FINAL)
		return $x . "&amp;final=1";
	    else if ($doc->documentType > 0)
		return $x . "&amp;dt=$doc->documentType";
	    else
		return $x;
	}
    }

    function allowEmailTo($email) {
	global $Opt;
	return $Opt['sendEmail'] && strstr($email, "@")
	    && !preg_match('/@_\.com$/', $email);
    }
}
