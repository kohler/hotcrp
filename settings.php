<?php 
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/tags.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("index$ConfSiteSuffix");
$SettingError = array();
$Error = array();
$Values = array();
$rf = reviewForm();

$SettingGroups = array("acc" => array(
			     "acct_addr" => "check",
			     "next" => "msg"),
		       "msg" => array(
			     "homemsg" => "htmlstring",
			     "conflictdefmsg" => "htmlstring",
			     "next" => "sub"),
		       "sub" => array(
			     "sub_open" => "cdate",
			     "sub_blind" => 2,
			     "sub_reg" => "date",
			     "sub_sub" => "date",
			     "sub_grace" => "grace",
			     "sub_pcconf" => "check",
			     "sub_collab" => "check",
			     "banal" => "special",
			     "sub_freeze" => 1,
			     "pc_seeall" => "check",
			     "next" => "opt"),
		       "opt" => array(
			     "topics" => "special",
			     "options" => "special",
			     "next" => "rev"),
		       "rev" => array(
			     "rev_open" => "cdate",
			     "cmt_always" => "check",
			     "rev_blind" => 2,
			     "rev_notifychair" => "check",
			     "pcrev_any" => "check",
			     "pcrev_soft" => "date",
			     "pcrev_hard" => "date",
			     "x_rev_roundtag" => "special",
			     "pc_seeallrev" => 2,
			     "extrev_chairreq" => "check",
			     "x_tag_chair" => "special",
			     "tag_seeall" => "check",
			     "extrev_soft" => "date",
			     "extrev_hard" => "date",
			     "extrev_view" => 2,
			     "mailbody_requestreview" => "string",
			     "rev_ratings" => 2,
			     "next" => "rfo"),
		       "rfo" => array(
			     "reviewform" => "special",
			     "next" => "dec"),
		       "dec" => array(
			     "au_seerev" => "check",
			     "au_seedec" => "check",
			     "rev_seedec" => "check",
			     "resp_open" => "check",
			     "resp_done" => "date",
			     "resp_grace" => "grace",
			     "decisions" => "special",
			     "final_open" => "check",
			     "final_done" => "date",
			     "final_grace" => "grace"));

$Group = defval($_REQUEST, "group");
if (!isset($SettingGroups[$Group])) {
    if ($Conf->timeAuthorViewReviews())
	$Group = "dec";
    else if ($Conf->settingsAfter("sub_sub") || $Conf->timeReviewOpen())
	$Group = "rev";
    else
	$Group = "sub";
}
if ($Group == "rfo")
    require_once("Code/reviewsetform.inc");
if ($Group == "acc")
    require_once("Code/contactlist.inc");


$SettingText = array(
	"sub_open" => "Submissions open setting",
	"sub_reg" => "Paper registration deadline",
	"sub_sub" => "Paper submission deadline",
	"rev_open" => "Reviews open setting",
	"cmt_always" => "Comments open setting",
	"pcrev_soft" => "PC soft review deadline",
	"pcrev_hard" => "PC hard review deadline",
	"extrev_soft" => "External reviewer soft review deadline",
	"extrev_hard" => "External reviewer hard review deadline",
	"sub_grace" => "Submissions grace period",
	"sub_blind" => "Blind submission setting",
	"rev_blind" => "Blind review setting",
	"sub_pcconf" => "Collect PC conflicts setting",
	"sub_collab" => "Collect collaborators setting",
	"acct_addr" => "Collect addresses setting",
	"sub_freeze" => "Submitters can update until the deadline setting",
	"rev_notifychair" => "Notify chairs about reviews setting",
	"pc_seeall" => "PC can see all papers setting",
	"pcrev_any" => "PC can review any paper setting",
	"extrev_chairreq" => "PC chair must approve proposed external reviewers",
	"pc_seeallrev" => "PC can see all reviews setting",
	"extrev_view" => "External reviewers can view reviewer identities setting",
	"tag_chair" => "Chair tags",
	"tag_seeall" => "PC can see tags for conflicted papers",
	"rev_ratings" => "Review ratings setting",
	"au_seerev" => "Authors can see reviews setting",
	"au_seedec" => "Authors can see decisions setting",
	"rev_seedec" => "Reviewers can see decisions setting",
	"final_open" => "Collect final copies setting",
	"final_done" => "Final copy upload deadline",
	"homemsg" => "Home page message",
	"conflictdefmsg" => "Definition of conflict of interest",
	"mailbody_requestreview" => "Mail template for external review requests"
	);

function parseGrace($v) {
    $t = 0;
    $v = trim($v);
    if ($v == "" || strtoupper($v) == "N/A" || strtoupper($v) == "NONE" || $v == "0")
	return -1;
    if (ctype_digit($v))
	return $v * 60;
    if (preg_match('/^\s*([\d]+):([\d.]+)\s*$/', $v, $m))
	return $m[1] * 60 + $m[2];
    if (preg_match('/^\s*([\d.]+)\s*d(ays?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 3600 * 24;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*h(rs?|ours?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 3600;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*m(in(ute)?s?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1] * 60;
	$v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^\s*([\d.]+)\s*s(ec(ond)?s?)?(?![a-z])/i', $v, $m)) {
	$t += $m[1];
	$v = substr($v, strlen($m[0]));
    }
    if (trim($v) == "")
	return $t;
    else
	return null;
}

function unparseGrace(&$v) {
    if (!isset($v) || $v <= 0 || !is_numeric($v))
	return "none";
    if ($v % 3600 == 0)
	return ($v / 3600) . " hr";
    if ($v % 60 == 0)
	return ($v / 60) . " min";
    return sprintf("%d:%02d", intval($v / 60), $v % 60);
}

function expandMailTemplate($name, $default) {
    global $nullMailer;
    if (!isset($nullMailer)) {
	require_once("Code/mailtemplate.inc");
	$nullMailer = new Mailer(null, null);
	$nullMailer->width = 10000000;
    }
    return $nullMailer->expandTemplate($name, true, $default);
}

function parseValue($name, $type) {
    global $SettingText, $Error, $SettingError;

    if (!isset($_REQUEST[$name]))
	return null;
    $v = trim($_REQUEST[$name]);

    if ($type == "check")
	return $v != "";
    if ($type == "cdate" && $v == "1")
	return 1;
    if ($type == "date" || $type == "cdate") {
	if ($v == "" || strtoupper($v) == "N/A" || $v == "0")
	    return -1;
	else if (($v = strtotime($v)) !== false)
	    return $v;
	else
	    $err = $SettingText[$name] . ": not a valid date.";
    } else if ($type == "grace") {
	if (($v = parseGrace($v)) !== null)
	    return intval($v);
	else
	    $err = $SettingText[$name] . ": parse error.";
    } else if ($type == "string") {
	// Avoid storing the default message in the database
	if (substr($name, 0, 9) == "mailbody_") {
	    $t = expandMailTemplate(substr($name, 9), true);
	    $v = cleannl($v);
	    if ($t[1] == $v)
		return 0;
	}
	return ($v == "" ? 0 : array(0, $v));
    } else if ($type == "htmlstring") {
	if ($v && preg_match("/(<!DOCTYPE|<\s*head\s*>|<\s*body\s*>)/i", $v, $m))
	    $err = $SettingText[$name] . ": Your HTML code appears to contain a <code>" . htmlspecialchars($m[1]) . "</code> definition.  Please remove it; this setting only accepts HTML content tags, such as <tt>&lt;p&gt;</tt>, <tt>&lt;strong&gt;</tt>, and <tt>&lt;h1&gt;</tt>.";
	else
	    return ($v == "" ? 0 : array(0, $v));
    } else if (is_int($type)) {
	if (ctype_digit($v) && $v >= 0 && $v <= $type)
	    return intval($v);
	else
	    $err = $SettingText[$name] . ": parse error on &ldquo;" . htmlspecialchars($v) . "&rdquo;.";
    } else
	return $v;

    $SettingError[$name] = true;
    $Error[] = $err;
    return null;
}

function doTags($set) {
    global $Conf, $Values, $Error, $SettingError;
    if (!$set && isset($_REQUEST["tag_chair"])) {
	$vs = array();
	foreach (preg_split('/\s+/', $_REQUEST["tag_chair"]) as $ct)
	    if ($ct && checkTag($ct, false))
		$vs[] = $ct;
	    else {
		$Error[] = "One of the chair-only tags contains odd characters.";
		$SettingError["tag_chair"] = true;
	    }
	if (!isset($SettingError["tag_chair"]))
	    $Values["tag_chair"] = array(1, join(" ", $vs));
    }
}

function doTopics($set) {
    global $Conf, $Values, $rf;
    if (!$set) {
	$Values["topics"] = true;
	return;
    }
    $while = "while updating topics";
    
    $numnew = defval($_REQUEST, "newtopcount", 50);
    foreach ($_REQUEST as $k => $v) {
	if (!(strlen($k) > 3 && $k[0] == "t" && $k[1] == "o" && $k[2] == "p"))
	    continue;
	$v = simplifyWhitespace($v);
	if ($k[3] == "n" && $v != "" && cvtint(substr($k, 4), 100) <= $numnew)
	    $Conf->qe("insert into TopicArea (topicName) values ('" . sqlq($v) . "')", $while);
	else if (($k = cvtint(substr($k, 3), -1)) >= 0) {
	    if ($v == "") {
		$Conf->qe("delete from TopicArea where topicId=$k", $while);
		$Conf->qe("delete from PaperTopic where topicId=$k", $while);
	    } else if (isset($rf->topicName[$k]) && $v != $rf->topicName[$k])
		$Conf->qe("update TopicArea set topicName='" . sqlq($v) . "' where topicId=$k", $while);
	}
    }
}

function doOptions($set) {
    global $Conf, $Values;
    if (!$set) {
	if ($Conf->setting("allowPaperOption"))
	    $Values["options"] = true;
	return;
    }
    $while = "while updating options";
    
    $ochange = false;
    $anyo = false;
    foreach (paperOptions() as $id => $o)
	if (isset($_REQUEST["optn$id"])
	    && ($_REQUEST["optn$id"] != $o->optionName
		|| defval($_REQUEST, "optd$id") != $o->description
		|| defval($_REQUEST, "optp$id", 0) != $o->pcView)) {
	    if ($_REQUEST["optn$id"] == "") {
		$Conf->qe("delete from OptionType where optionId=$id", $while);
		$Conf->qe("delete from PaperOption where optionId=$id", $while);
	    } else {
		$Conf->qe("update OptionType set optionName='" . sqlq($_REQUEST["optn$id"]) . "', description='" . sqlq(defval($_REQUEST, "optd$id")) . "', pcView=" . (defval($_REQUEST, "optp$id") ? 1 : 0) . " where optionId=$id", $while);
		$anyo = true;
	    }
	    $ochange = true;
	} else
	    $anyo = true;
    
    if (defval($_REQUEST, "optnn") && $_REQUEST["optnn"] != "New option") {
	$Conf->qe("insert into OptionType (optionName, description, pcView) values ('" . sqlq($_REQUEST["optnn"]) . "', '" . sqlq(defval($_REQUEST, "optdn", "")) . "', " . (defval($_REQUEST, "optpn") ? 1 : 0) . ")", $while);
	$ochange = $anyo = true;
    }

    if (!$anyo)
	$Conf->qe("delete from Settings where name='paperOption'", $while);
    else if ($ochange) {
	$t = time();
	$Conf->qe("insert into Settings (name, value) values ('paperOption', $t) on duplicate key update value=$t", $while);
    }
}

function doDecisions($set) {
    global $Conf, $Values, $rf;
    if (!$set) {
	$Values["decisions"] = true;
	return;
    }

    // mark all used decisions
    $while = "while updating decisions";
    $dec = $rf->options["outcome"];
    $update = false;
    foreach ($_REQUEST as $k => $v)
	if (strlen($k) > 3 && $k[0] == "d" && $k[1] == "e" && $k[2] == "c"
	    && ($k = cvtint(substr($k, 3), 0)) != 0) {
	    if ($v == "") {
		$Conf->qe("delete from ReviewFormOptions where fieldName='outcome' and level=$k", $while);
		$Conf->qe("update Paper set outcome=0 where outcome=$k", $while);
	    } else if ($v != $dec[$k])
		$Conf->qe("update ReviewFormOptions set description='" . sqlq($v) . "' where fieldName='outcome' and level=$k", $while);
	}

    if (defval($_REQUEST, "decn", "") != "") {
	$delta = (defval($_REQUEST, "dtypn", 1) > 0 ? 1 : -1);
	for ($k = $delta; true; $k += $delta)
	    if (!isset($dec[$k]))
		break;
	
	$Conf->qe("insert into ReviewFormOptions set fieldName='outcome', level=$k, description='" . sqlq($_REQUEST["decn"]) . "'");
    }
}

function doBanal($set) {
    global $Conf, $Values, $SettingError, $Error, $ConfSitePATH;
    if ($set)
	return true;
    if (!isset($_REQUEST["sub_banal"])) {
	if (($t = $Conf->settingText("sub_banal", "")) != "")
	    $Values["sub_banal"] = array(0, $t);
	else
	    $Values["sub_banal"] = null;
	return true;
    }

    // check banal subsettings
    require_once("Code/checkformat.inc");
    $old_error_count = count($Error);
    $bs = array_fill(0, 6, "");
    if (($s = trim(defval($_REQUEST, "sub_banal_papersize", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	if (!cvtdimen($s, 2)) {
	    $SettingError["sub_banal_papersize"] = true;
	    $Error[] = "Invalid paper size.";
	} else
	    $bs[0] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_pagelimit", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	if (($s = cvtint($s, -1)) <= 0) {
	    $SettingError["sub_banal_pagelimit"] = true;
	    $Error[] = "Page limit must be a whole number bigger than 0.";
	} else
	    $bs[1] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_textblock", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	// change margin specifications into text block measurements
	if (preg_match('/^(.*\S)\s+mar(gins?)?/i', $s, $m)) {
	    $s = $m[1];
	    if (!($ps = cvtdimen($bs[0]))) {
		$SettingError["sub_banal_pagesize"] = true;
		$SettingError["sub_banal_textblock"] = true;
		$Error[] = "You must specify a page size as well as margins.";
	    } else if (strpos($s, "x") !== false) {
		if (!($m = cvtdimen($s)) || !is_array($m) || count($m) > 4) {
		    $SettingError["sub_banal_textblock"] = true;
		    $Error[] = "Invalid margin definition.";
		    $s = "";
		} else if (count($m) == 2)
		    $s = array($ps[0] - 2 * $m[0], $ps[1] - 2 * $m[1]);
		else if (count($m) == 3)
		    $s = array($ps[0] - 2 * $m[0], $ps[1] - $m[1] - $m[2]);
		else
		    $s = array($ps[0] - $m[0] - $m[2], $ps[1] - $m[1] - $m[3]);
	    } else {
		$s = preg_replace('/\s+/', 'x', $s);
		if (!($m = cvtdimen($s)) || (is_array($m) && count($m) > 4)) {
		    $SettingError["sub_banal_textblock"] = true;
		    $Error[] = "Invalid margin definition.";
		} else if (!is_array($m))
		    $s = array($ps[0] - 2 * $m, $ps[1] - 2 * $m);
		else if (count($m) == 2)
		    $s = array($ps[0] - 2 * $m[1], $ps[1] - 2 * $m[0]);
		else if (count($m) == 3)
		    $s = array($ps[0] - 2 * $m[1], $ps[1] - $m[0] - $m[2]);
		else
		    $s = array($ps[0] - $m[1] - $m[3], $ps[1] - $m[0] - $m[2]);
	    }
	    $s = (is_array($s) ? unparsedimen($s) : "");
	}
	// check text block measurements
	if ($s && !cvtdimen($s, 2)) {
	    $SettingError["sub_banal_textblock"] = true;
	    $Error[] = "Invalid text block definition.";
	} else if ($s)
	    $bs[3] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_bodyfontsize", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	if (!is_numeric($s) || $s <= 0) {
	    $SettingError["sub_banal_bodyfontsize"] = true;
	    $Error[] = "Minimum body font size must be a number bigger than 0.";
	} else
	    $bs[4] = $s;
    }

    if (($s = trim(defval($_REQUEST, "sub_banal_bodyleading", ""))) != ""
	&& strcasecmp($s, "N/A") != 0) {
	if (!is_numeric($s) || $s <= 0) {
	    $SettingError["sub_banal_bodyleading"] = true;
	    $Error[] = "Minimum body leading must be a number bigger than 0.";
	} else
	    $bs[5] = $s;
    }

    while (count($bs) > 0 && $bs[count($bs) - 1] == "")
	array_pop($bs);

    // actually create setting
    if (count($Error) == $old_error_count) {
	$Values["sub_banal"] = array(1, join(";", $bs));
	$zoomarg = "";

	// Perhaps we have an old pdftohtml with a bad -zoom.
	for ($tries = 0; $tries < 2; ++$tries) {
	    $cf = new CheckFormat();
	    $s1 = $cf->analyzeFile("$ConfSitePATH/Code/sample.pdf", "letter;2;;6.5inx9in;12;14" . $zoomarg);
	    $e1 = $cf->errors;
	    if ($s1 == 1 && ($e1 & CheckFormat::ERR_PAPERSIZE) && $tries == 0)
		$zoomarg = ">-zoom=1";
	    else if ($s1 != 2 && $tries == 1)
		$zoomarg = "";
	}

	$Values["sub_banal"][1] .= $zoomarg;
	$e1 = $cf->errors;
	$s2 = $cf->analyzeFile("$ConfSitePATH/Code/sample.pdf", "a4;1;;3inx3in;13;15" . $zoomarg);
	$e2 = $cf->errors;
	$want_e2 = CheckFormat::ERR_PAPERSIZE | CheckFormat::ERR_PAGELIMIT
	    | CheckFormat::ERR_TEXTBLOCK | CheckFormat::ERR_BODYFONTSIZE
	    | CheckFormat::ERR_BODYLEADING;
	if ($s1 != 2 || $e1 != 0 || $s2 != 1 || ($e2 & $want_e2) != $want_e2)
	    $Conf->warnMsg("Running the automated paper checker on a sample PDF file produced unexpected results.  Check that your <code>pdftohtml</code> package is up to date.  You may want to disable the automated checker for now. (Internal error information: $s1 $e1 $s2 $e2)");
    }
}

function doSpecial($name, $set) {
    global $Values, $Error, $SettingError;
    if ($name == "x_tag_chair")
	doTags($set);
    else if ($name == "topics")
	doTopics($set);
    else if ($name == "options")
	doOptions($set);
    else if ($name == "decisions")
	doDecisions($set);
    else if ($name == "reviewform") {
	if (!$set)
	    $Values[$name] = true;
	else {
	    rf_update(false);
	    $Values["revform_update"] = time();
	}
    } else if ($name == "banal")
	doBanal($set);
    else if ($name == "x_rev_roundtag") {
	if (!$set && !isset($_REQUEST["rev_roundtag"]))
	    $Values["rev_roundtag"] = null;
	else if (!$set) {
	    require_once("Code/tags.inc");
	    $t = trim($_REQUEST["rev_roundtag"]);
	    if ($t == "" || $t == "(None)")
		$Values["rev_roundtag"] = null;
	    else if (preg_match('/^[a-zA-Z0-9]+$/', $t))
		$Values["rev_roundtag"] = array(1, $t);
	    else {
		$Error[] = "The review round must contain only letters and numbers.";
		$SettingError["rev_roundtag"] = true;
	    }
	}
    }
}

function accountValue($name, $type) {
    global $Values;
    if ($type == "special")
	doSpecial($name, false);
    else if ($name != "next") {
	$v = parseValue($name, $type);
	if ($v === null) {
	    if ($type != "cdate" && $type != "check")
		return;
	    $v = 0;
	}
	if (!is_array($v) && $v <= 0 && !is_int($type))
	    $Values[$name] = null;
	else
	    $Values[$name] = $v;
    }
}

if (isset($_REQUEST["update"])) {
    // parse settings
    $settings = $SettingGroups[$Group];
    foreach ($settings as $name => $value)
	accountValue($name, $value);

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "pcrev_soft" => "pcrev_hard",
		   "extrev_soft" => "extrev_hard") as $first => $second)
	if (!isset($Values[$first]) && isset($Values[$second]))
	    $Values[$first] = $Values[$second];
	else if (isset($Values[$first]) && isset($Values[$second])) {
	    if ($Values[$second] && !$Values[$first])
		$Values[$first] = $Values[$second];
	    else if ($Values[$second] && $Values[$first] > $Values[$second]) {
		$Error[] = $SettingText[$first] . " must come before " . $SettingText[$second] . ".";
		$SettingError[$first] = true;
		$SettingError[$second] = true;
	    }
	}
    if (array_key_exists("sub_sub", $Values))
	$Values["sub_update"] = $Values["sub_sub"];
    // need to set 'resp_open' to a timestamp,
    // so we can join on later review changes
    if (array_key_exists("resp_open", $Values)
	&& $Values["resp_open"] > 0
	&& defval($Conf->settings, "resp_open") <= 0)
	$Values["resp_open"] = time();

    // update 'papersub'
    if (isset($settings["pc_seeall"])) {
	// see also conference.inc
	$result = $Conf->q("select ifnull(min(paperId),0) from Paper where " . (defval($Values, "pc_seeall", 0) <= 0 ? "timeSubmitted>0" : "timeWithdrawn<=0"));
	if (($row = edb_row($result)) && $row[0] != $Conf->setting("papersub"))
	    $Values["papersub"] = $row[0];
    }
    
    // warn on other relationships
    if (array_key_exists("resp_open", $Values)
	&& $Values["resp_open"] > 0
	&& (!array_key_exists("au_seerev", $Values)
	    || $Values["au_seerev"] <= 0)
	&& (!array_key_exists("resp_done", $Values)
	    || time() < $Values["resp_done"]))
	$Conf->warnMsg("You have allowed authors to respond to the reviews, but authors can't see the reviews.  This seems odd.");
    if (array_key_exists("sub_freeze", $Values)
	&& $Values["sub_freeze"] == 0
	&& defval($Values, "sub_open", 0) > 0
	&& defval($Values, "sub_sub", 0) <= 0)
	$Conf->warnMsg("You have not set a paper submission deadline, but authors can update their submissions until the deadline.  This seems odd.  You probably should (1) specify a paper submission deadline; (2) select &ldquo;Authors must freeze the final version of each submission&rdquo;; or (3) manually turn off &ldquo;Open site for submissions&rdquo; when submissions complete.");

    // unset text messages that equal the default
    if (array_key_exists("conflictdefmsg", $Values)
	&& trim($Values["conflictdefmsg"]) == $Conf->conflictDefinitionText(true))
	$Values["conflictdefmsg"] = null;

    // report errors
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br />\n", $Error));
    else if (count($Values) > 0) {
	$while = "updating settings";
	$tables = "Settings write, TopicArea write, PaperTopic write";
	if ($Conf->setting("allowPaperOption"))
	    $tables .= ", OptionType write, PaperOption write";
	if (isset($Values['decisions']) || isset($Values['reviewform']))
	    $tables .= ", ReviewFormOptions write";
	else
	    $tables .= ", ReviewFormOptions read";
	if (isset($Values['decisions']))
	    $tables .= ", Paper write";
	if (isset($Values['reviewform']))
	    $tables .= ", ReviewFormField write, PaperReview write";
	else
	    $tables .= ", ReviewFormField read";
	$Conf->qe("lock tables $tables", $while);
	// alert others since we're changing settings
	$Values['revform_update'] = time();

	// apply settings
	$dq = $aq = "";
	foreach ($Values as $n => $v)
	    if (defval($settings, $n) == "special")
		doSpecial($n, true);
	    else {
		$dq .= " or name='$n'";
		if (is_array($v))
		    $aq .= ", ('$n', '" . sqlq($v[0]) . "', '" . sqlq($v[1]) . "')";
		else if ($v !== null)
		    $aq .= ", ('$n', '" . sqlq($v) . "', null)";
	    }
	$Conf->qe("delete from Settings where " . substr($dq, 4), $while);
	if (strlen($aq))
	    $Conf->qe("insert into Settings (name, value, data) values " . substr($aq, 2), $while);
	
	$Conf->qe("unlock tables", $while);
	$Conf->log("Updated settings group '$Group'", $Me);
	$Conf->updateSettings();
	$rf->validate($Conf, true);
    }
} else if ($Group == "rfo")
    rf_update(false);


// header and script
$Conf->header("Conference Settings", "settings", actionBar());


function decorateSettingName($name, $text) {
    global $SettingError;
    if (isset($SettingError[$name]))
	return "<span class='error'>$text</span>";
    else
	return $text;
}

function setting($name, $defval = null) {
    global $Error, $Conf;
    if (count($Error) > 0)
	return defval($_REQUEST, $name, $defval);
    else
	return defval($Conf->settings, $name, $defval);
}

function settingText($name, $defval = null) {
    global $Error, $Conf;
    if (count($Error) > 0)
	return defval($_REQUEST, $name, $defval);
    else
	return defval($Conf->settingTexts, $name, $defval);
}

function doCheckbox($name, $text, $tr = false, $js = "highlightUpdate()") {
    $x = setting($name);
    echo ($tr ? "<tr><td class='nowrap'>" : ""), "<input type='checkbox' name='$name' value='1'";
    if ($x !== null && $x > 0)
	echo " checked='checked'";
    echo " onchange='$js' />&nbsp;", ($tr ? "</td><td>" : ""), decorateSettingName($name, $text), ($tr ? "</td></tr>\n" : "<br />\n");
}

function doRadio($name, $varr) {
    $x = setting($name);
    if ($x === null || !isset($varr[$x]))
	$x = 0;
    echo "<table>\n";
    foreach ($varr as $k => $text) {
	echo "<tr><td class='nowrap'><input type='radio' name='$name' value='$k'";
	if ($k == $x)
	    echo " checked='checked'";
	echo " onchange='highlightUpdate()' />&nbsp;</td><td>";
	if (is_array($text))
	    echo decorateSettingName($name, $text[0]), "<br /><small>", $text[1], "</small>";
	else
	    echo decorateSettingName($name, $text);
	echo "</td></tr>\n";
    }
    echo "</table>\n";
}

function doSelect($name, $nametext, $varr, $tr = false) {
    echo ($tr ? "<tr><td class='nowrap lcaption'>" : ""),
	decorateSettingName($name, $nametext),
	($tr ? "</td><td class='lentry'>" : ": &nbsp;"),
	tagg_select($name, $varr, setting($name),
		    array("onchange" => "highlightUpdate()")),
	($tr ? "</td></tr>\n" : "<br />\n");
}

function doTextRow($name, $text, $v, $size = 30, $capclass = "lcaption",
		   $tempText = "") {
    $settingname = (is_array($text) ? $text[0] : $text);
    if ($tempText)
	$tempText = " onfocus=\"tempText(this, '$tempText', 1)\" onblur=\"tempText(this, '$tempText', 0)\"";
    echo "<tr><td class='$capclass'>", decorateSettingName($name, $settingname), "</td><td class='lentry'><input type='text' class='textlite' name='$name' value=\"", htmlspecialchars($v), "\" size='$size'$tempText onchange='highlightUpdate()' />";
    if (is_array($text) && isset($text[2]))
	echo $text[2];
    if (is_array($text) && $text[1])
	echo "<br /><span class='hint'>", $text[1], "</span>";
    echo "</td></tr>\n";
}

function doDateRow($name, $text, $othername = null, $capclass = "lcaption") {
    global $Conf, $Error, $DateExplanation;
    $x = setting($name);
    if ($x === null || (count($Error) == 0 && $x <= 0)
	|| (count($Error) == 0 && $othername && setting($othername) == $x))
	$v = "N/A";
    else if (count($Error) == 0)
	$v = $Conf->parseableTime($x);
    else
	$v = $x;
    if (!isset($DateExplanation)) {
	$text = array($text, "Examples: &ldquo;now&rdquo;, &ldquo;10 Dec 2006 11:59:59pm PST&rdquo; <a href='http://www.gnu.org/software/tar/manual/html_node/tar_109.html'>(more)</a>");
	$DateExplanation = true;
    }
    doTextRow($name, $text, $v, 30, $capclass);
}

function doGraceRow($name, $text, $capclass = "lcaption") {
    global $GraceExplanation;
    if (!isset($GraceExplanation)) {
	$text = array($text, "Example: &ldquo;15 min&rdquo;");
	$GraceExplanation = true;
    }
    doTextRow($name, $text, unparseGrace(setting($name)), 15, $capclass);
}


// Accounts
function doAccGroup() {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $Me, $belowHr;

    if ($Conf->setting("allowPaperOption") >= 5)
	doCheckbox("acct_addr", "Collect users' addresses and phone numbers");
    else
	doCheckbox("acct_addr", "Collect users' phone numbers");

    echo "<hr /><h3>Program committee &amp; system administrators</h3>";

    echo "<p><a href='${ConfSiteBase}account$ConfSiteSuffix?new=1' class='button'>Create account</a> &nbsp;|&nbsp; ",
	"Select a user's name to edit a profile or change PC/administrator status.</p>\n";
    $pl = new ContactList(false);
    echo $pl->text("pcadminx", $Me, "${ConfSiteBase}contacts$ConfSiteSuffix?t=pcadmin");

    $belowHr = false;
}

// Messages
function doMsgGroup() {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix;
    echo "<strong>", decorateSettingName("homemsg", "Home page message"), "</strong> (HTML allowed)<br />
<textarea class='textlite' name='homemsg' cols='60' rows='10' onchange='highlightUpdate()'>", htmlspecialchars(settingText("homemsg", "")), "</textarea>";
    echo "<hr class='g' />\n";

    echo "<strong>", decorateSettingName("conflictdefmsg", "Definition of conflict of interest"), "</strong> (HTML allowed)<br />
<textarea class='textlite' name='conflictdefmsg' cols='60' rows='2' onchange='highlightUpdate()'>", htmlspecialchars(settingText("conflictdefmsg", $Conf->conflictDefinitionText(true))), "</textarea>";
}

// Submissions
function doSubGroup() {
    global $Conf, $ConfSiteBase;

    doCheckbox('sub_open', '<b>Open site for submissions</b>');

    echo "<hr class='g' />\n";
    echo "<strong>Blind submission:</strong> Are author names visible to reviewers?<br />\n";
    doRadio("sub_blind", array(0 => "Yes", 2 => "No&mdash;submissions are anonymous", 1 => "Maybe (authors decide whether to expose their names)"));

    echo "<hr class='g' />\n<table>\n";
    doDateRow("sub_reg", "Paper registration deadline", "sub_sub");
    doDateRow("sub_sub", "Paper submission deadline");
    doGraceRow("sub_grace", 'Grace period');
    echo "</table>\n";

    echo "<hr class='g' />\n";
    doCheckbox("sub_pcconf", "Collect authors&rsquo; PC conflicts with checkboxes");
    doCheckbox("sub_collab", "Collect authors&rsquo; other collaborators as text");

    if (is_executable("Code/banal")) {
	echo "<hr class='g' /><table id='foldbanal' class='", ($Conf->setting("sub_banal") ? "foldo" : "foldc"), "'>";
	doCheckbox("sub_banal", "<strong>Automated format checker<span class='extension'>:</span></strong>", true, "highlightUpdate();fold(\"banal\",!this.checked)");
	echo "<tr class='extension'><td></td><td class='top'><table>";
	$bsetting = explode(";", preg_replace("/>.*/", "", $Conf->settingText("sub_banal", "")));
	for ($i = 0; $i < 6; $i++)
	    $bsetting[$i] = ($bsetting[$i] == "" ? "N/A" : $bsetting[$i]);
	doTextRow("sub_banal_papersize", array("Paper size", "Examples: &ldquo;letter&rdquo;, &ldquo;A4&rdquo;, &ldquo;8.5in&nbsp;x&nbsp;14in&rdquo;"), setting("sub_banal_papersize", $bsetting[0]), 18, "lxcaption");
	doTextRow("sub_banal_pagelimit", "Page limit", setting("sub_banal_pagelimit", $bsetting[1]), 4, "lxcaption");
	doTextRow("sub_banal_textblock", array("Text block", "Examples: &ldquo;6.5in&nbsp;x&nbsp;9in&rdquo;, &ldquo;1in&nbsp;margins&rdquo;"), setting("sub_banal_textblock", $bsetting[3]), 18, "lxcaption");
	echo "</table></td><td><span class='sep'></span></td><td class='top'><table>";
	doTextRow("sub_banal_bodyfontsize", array("Minimum body font size", null, "&nbsp; pt"), setting("sub_banal_bodyfontsize", $bsetting[4]), 4, "lxcaption");
	doTextRow("sub_banal_bodyleading", array("Minimum leading", null, "&nbsp; pt"), setting("sub_banal_bodyleading", $bsetting[5]), 4, "lxcaption");
	echo "</table></td></tr></table>";
    }
    
    echo "<hr />\n";
    doRadio("sub_freeze", array(0 => "<strong>Authors can update submissions until the deadline</strong>", 1 => array("Authors must freeze the final version of each submission", "&ldquo;Authors can update submissions&rdquo; is usually the best choice.  Freezing submissions is mostly useful when there is no submission deadline.")));
    
    echo "<hr class='g' /><table>\n";
    // compensate for pc_seeall magic
    if ($Conf->setting("pc_seeall") < 0)
	$Conf->settings["pc_seeall"] = 1;
    doCheckbox('pc_seeall', "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences <em>before</em> most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>", true);
    echo "</table>";
}

// Submission options
function doOptGroup() {
    global $Conf, $rf;
    
    if ($Conf->setting("allowPaperOption")) {
	echo "<h3>Submission options</h3>\n";
	echo "Options may be selected by authors at submission time, and might include \"Consider this paper for a Best Student Paper award\" or \"Allow the shadow PC to see this paper\".  The \"option name\" should be brief, three or four words at most; it appears as a caption to the left of the option.  The description should be longer and may use HTML.  To delete an option, delete its name.  Add options one at a time.\n";
	echo "<hr class='g' />\n";
	echo "<table>";
	$opt = paperOptions();
	$sep = "";
	foreach ($opt as $o) {
	    echo $sep;
	    echo "<tr><td class='lxcaption'>Option name</td><td class='lentry'><input type='text' class='textlite' name='optn$o->optionId' value=\"", htmlspecialchars($o->optionName), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	    echo "<tr><td class='lxcaption'>Description</td><td class='lentry textarea'><textarea class='textlite' name='optd$o->optionId' rows='2' cols='50' onchange='highlightUpdate()'>", htmlspecialchars($o->description), "</textarea><br />\n",
		"<input type='checkbox' name='optp$o->optionId' value='1'", ($o->pcView ? " checked='checked'" : ""), " />&nbsp;Visible to PC</td></tr>\n";
	    $sep = "<tr><td></td><td><hr class='g' /></td></tr>\n";
	}
    
	echo ($sep ? "<tr><td colspan='2'><hr /></td></tr>\n" : "");
	
	echo "<tr><td class='lxcaption'>Option name</td><td class='lentry'><input type='text' class='textlite' name='optnn' value=\"New option\" size='50' onchange='highlightUpdate()' onfocus=\"tempText(this, 'New option', 1)\" onblur=\"tempText(this, 'New option', 0)\" /></td></tr>\n";
	echo "<tr><td class='lxcaption'>Description</td><td class='lentry textarea'><textarea class='textlite' name='optdn' rows='2' cols='50' onchange='highlightUpdate()'></textarea><br />\n",
	    "<input type='checkbox' name='optpn' value='1' checked='checked' />&nbsp;Visible to PC</td></tr>\n";
	
	echo "</table>\n";
    }


    // Topics
    echo "<hr /><h3>Topics</h3>\n";
    echo "Enter topics one per line.  Authors use checkboxes to identify the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its text.\n";
    echo "<hr class='g' /><table id='newtoptable'>";
    $td1 = "<td class='lcaption'>Current</td>";
    foreach ($rf->topicOrder as $tid => $crap) {
	echo "<tr>$td1<td class='lentry'><input type='text' class='textlite' name='top$tid' value=\"", htmlspecialchars($rf->topicName[$tid]), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	$td1 = "<td></td>";
    }
    $td1 = "<td class='lcaption' rowspan='40'>New<br /><small><a href='javascript:authorfold(\"newtop\",1,1)'>More</a> | <a href='javascript:authorfold(\"newtop\",1,-1)'>Fewer</a></small></td>";
    for ($i = 1; $i <= 40; $i++) {
	echo "<tr id='newtop$i' class='auedito'>$td1<td class='lentry'><input type='text' class='textlite' name='topn$i' value=\"\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	$td1 = "";
    }
    echo "</table><input id='newtopcount' type='hidden' name='newtopcount' value='40' />";
    $Conf->echoScript("authorfold(\"newtop\",0,3)");
}

// Reviews
function doRevGroup() {
    global $Conf, $Error, $ConfSiteBase, $ConfSiteSuffix;

    doCheckbox('rev_open', '<b>Open site for reviewing</b>');
    doCheckbox('cmt_always', 'Allow comments even if reviewing is closed');

    echo "<hr class='g' />\n";
    echo "<strong>Anonymous review:</strong> Are reviewer names visible to authors?<br />\n";
    doRadio("rev_blind", array(0 => "Yes", 2 => "No&mdash;reviewers are anonymous", 1 => "Maybe (reviewers decide whether to expose their names)"));

    echo "<hr class='g' />\n";
    doCheckbox('rev_notifychair', 'PC chairs are notified of new reviews by email');

    echo "<hr />";


    // PC reviews
    echo "<h3>PC reviews</h3>\n";

    echo "<table>\n";
    doDateRow("pcrev_soft", "Soft deadline", "pcrev_hard");
    doDateRow("pcrev_hard", "Hard deadline");
    if (!($rev_roundtag = settingText("rev_roundtag")))
	$rev_roundtag = "(None)";
    doTextRow("rev_roundtag", array("Review round", "This will mark new PC review assignments by default.  Examples: &ldquo;R1&rdquo;, &ldquo;R2&rdquo; &nbsp;<span class='barsep'>|</span>&nbsp; <a href='${ConfSiteBase}help$ConfSiteSuffix?t=revround'>What is this?</a>"), $rev_roundtag, 15, "lcaption", "(None)");
    echo "</table>\n";

    echo "<hr class='g' />\n";
    doCheckbox('pcrev_any', 'PC members can review <strong>any</strong> submitted paper');

    echo "<hr class='g' />\n";
    echo "Can PC members <strong>see all reviews</strong> except for conflicts?<br />\n";
    doRadio("pc_seeallrev", array(0 => "No&mdash;a PC member can see a paper's reviews only after submitting their own review for that paper", 1 => "Yes", 2 => "Yes, but they can't see who wrote blind reviews"));

    echo "<hr />";


    // External reviews
    echo "<h3>External reviews</h3>\n";

    if ($Conf->setting("allowPaperOption") > 1) {
	doCheckbox('extrev_chairreq', "PC chair must approve proposed external reviewers");
	echo "<hr class='g' />";
    }
    
    echo "<table>\n";
    doDateRow("extrev_soft", "Soft deadline", "extrev_hard");
    doDateRow("extrev_hard", "Hard deadline");
    echo "</table>\n";

    echo "<hr class='g' />";
    echo "Can external reviewers view the other reviews for their assigned papers, once they've submitted their own?<br />\n";
    doRadio("extrev_view", array(0 => "No", 2 => "Yes", 1 => "Yes, but they can't see who wrote blind reviews"));

    echo "<hr class='g' />\n";
    $t = expandMailTemplate("requestreview", false);
    echo "<div id='foldmailbody_requestreview' class='foldc'>", foldbutton("mailbody_requestreview", ""), "&nbsp;
  <a href=\"javascript:fold('mailbody_requestreview', 0)\" class='unfolder q'><strong>Mail template for external review requests</strong></a>\n";
    echo "  <span class='extension'><strong>Mail template for external review requests</strong> (<a href='${ConfSiteBase}mail$ConfSiteSuffix'>keywords</a> allowed)<br /></span>
<textarea class='tt extension' name='mailbody_requestreview' cols='80' rows='20' onchange='highlightUpdate()'>", htmlspecialchars($t[1]), "</textarea></div>\n";

    echo "<hr />";

    // Tags
    echo "<h3>Tags</h3>\n";

    doCheckbox('tag_seeall', "PC can see tags for conflicted papers");
    echo "<hr class='g' />";
    echo "<table><tr><td class='lcaption'>", decorateSettingName("tag_chair", "Chair-only tags"), "</td>";
    if (count($Error) > 0)
	$v = defval($_REQUEST, "tag_chair", "");
    else {
	$t = array_keys(chairTags());
	sort($t);
	$v = join(" ", $t);
    }
    echo "<td><input type='text' class='textlite' name='tag_chair' value=\"", htmlspecialchars($v), "\" size='50' onchange='highlightUpdate()' /><br /><small>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</small></td></tr></table>";

    echo "<hr />";

    // Tags
    echo "<h3>Review ratings</h3>\n";

    echo "Should HotCRP collect ratings of reviews? &nbsp; <a class='hint' href='help$ConfSiteSuffix?t=revrate'>(Learn more)</a><br />\n";
    doRadio("rev_ratings", array(REV_RATINGS_PC => "Yes, PC members can rate reviews", REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews", REV_RATINGS_NONE => "No"));
}

// Review form
function doRfoGroup() {
    require_once("Code/reviewsetform.inc");
    rf_show();
}

// Responses and decisions
function doDecGroup() {
    global $Conf, $rf;
    doCheckbox('au_seerev', '<b>Authors can see reviews</b>');

    echo "<hr class='g' />\n<table>";
    doCheckbox('resp_open', "<b>Collect authors&rsquo; responses to the reviews:</b>", true);
    echo "<tr><td></td><td><table>";
    doDateRow('resp_done', 'Deadline', null, "lxcaption");
    doGraceRow('resp_grace', 'Grace period', "lxcaption");
    echo "</table></td></tr></table>";

    echo "<hr class='g' />\n";
    doCheckbox('au_seedec', '<b>Authors can see decisions</b> (accept/reject)');
    doCheckbox('rev_seedec', 'Reviewers can see decisions and accepted authors');

    echo "<hr class='g' />\n";
    echo "<table>\n";
    $decs = $rf->options['outcome'];
    krsort($decs);
    $n = 0;
    foreach ($decs as $k => $v)
	if ($k)
	    $n++;
    $caption = "<td class='lcaption' rowspan='$n'>Current decision types</td>";
    foreach ($decs as $k => $v)
	if ($k) {
	    echo "<tr>$caption<td class='lentry nowrap'>";
	    echo "<input type='text' class='textlite' name='dec$k' value=\"", htmlspecialchars($v), "\" size='35' /> &nbsp; ", ($k > 0 ? "Accept class" : "Reject class"), "</td></tr>\n";
	    $caption = "";
	}
    echo "<tr><td class='lcaption'>New decision type<br /></td><td class='lentry nowrap'><input type='text' class='textlite' name='decn' value=\"\" size='35' /> &nbsp; ",
	tagg_select("dtypn", array(1 => "Accept class", -1 => "Reject class")),
	"<br /><small>Examples: &ldquo;Accepted as short paper&rdquo;, &ldquo;Early reject&rdquo;</small>",
	"</td></tr>\n</table>\n";
    
    // Final copies
    echo "<hr />";
    echo "<h3>Final copies</h3>\n";
    echo "<table>";
    doCheckbox('final_open', '<b>Collect final copies of accepted papers:</b>', true);
    echo "<tr><td></td><td><table>";
    doDateRow("final_done", "Deadline", null, "lxcaption");
    doGraceRow("final_grace", "Grace period", "lxcaption");
    echo "</table></td></tr></table>\n\n";
}

$belowHr = true;

echo "<form method='post' action='settings$ConfSiteSuffix?post=1' enctype='multipart/form-data' accept-charset='UTF-8'><div><input type='hidden' name='group' value='$Group' />\n";

echo "<table class='settings'><tr><td class='caption'>";
echo "<table class='lhsel'>";
foreach (array("acc" => "Accounts",
	       "msg" => "Messages",
	       "sub" => "Submissions",
	       "opt" => "Submission options",
	       "rev" => "Reviews",
	       "rfo" => "Review form",
	       "dec" => "Decisions") as $k => $v) {
    echo "<tr><td>";
    if ($Group == $k)
	echo "<div class='lhl1'><a class='q' href='settings$ConfSiteSuffix?group=$k'>$v</a></div>";
    else
	echo "<div class='lhl0'><a href='settings$ConfSiteSuffix?group=$k'>$v</a></div>";
    echo "</td></tr>";
}
echo "</table></td><td class='top'><div class='lht'>";

if ($Group == "acc")
    doAccGroup();
else if ($Group == "msg")
    doMsgGroup();
else if ($Group == "sub")
    doSubGroup();
else if ($Group == "opt")
    doOptGroup();
else if ($Group == "rev")
    doRevGroup();
else if ($Group == "rfo")
    doRfoGroup();
else
    doDecGroup();

echo ($belowHr ? "<hr />\n" : "<hr class='g' />\n");
echo "<input type='submit' class='hbutton",
    (defval($_REQUEST, "sample", "none") == "none" ? "" : "_alert"),
    "' name='update' value='Save changes' /> ";
echo "&nbsp;<input type='submit' class='button' name='cancel' value='Cancel' />";

echo "</div></td></tr>
<tr class='last'><td class='caption'></td></tr>
</table></div></form>\n";

$Conf->footer();
