<?php 
// settings.php -- HotCRP chair-only conference settings management page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/tags.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair('index.php');
$SettingError = array();
$Error = array();
$Values = array();
$rf = reviewForm();

$SettingGroups = array("acc" => array(
			     "acct_addr" => "check",
			     "next" => "msg"),
		       "msg" => array(
			     "homemsg" => "string",
			     "next" => "sub"),
		       "sub" => array(
			     "sub_open" => "cdate",
			     "sub_blind" => 2,
			     "sub_reg" => "date",
			     "sub_sub" => "date",
			     "sub_grace" => "grace",
			     "sub_pcconf" => "check",
			     "sub_collab" => "check",
			     "sub_freeze" => 1,
			     "pc_seeall" => "check",
			     "homemsg" => "string",
			     "next" => "opt"),
		       "opt" => array(
			     "topics" => "special",
			     "options" => "special",
			     "next" => "rev"),
		       "rev" => array(
			     "rev_open" => "cdate",
			     "rev_blind" => 2,
			     "rev_notifychair" => "check",
			     "pcrev_any" => "check",
			     "pcrev_soft" => "date",
			     "pcrev_hard" => "date",
			     "pc_seeallrev" => "check",
			     "extrev_chairreq" => "check",
			     "tags" => "special",
			     "extrev_soft" => "date",
			     "extrev_hard" => "date",
			     "extrev_view" => 2,
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

$Group = defval($_REQUEST["group"]);
if (!isset($SettingGroups[$Group]))
    $Group = "sub";
if ($Group == "rfo")
    require_once("Chair/SetReviewForm.php");
if ($Group == "acc")
    require_once("Code/contactlist.inc");


$SettingText = array(
	"sub_open" => "Submissions open setting",
	"sub_reg" => "Paper registration deadline",
	"sub_sub" => "Paper submission deadline",
	"rev_open" => "Reviews open setting",
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
	"au_seerev" => "Allow authors to see reviews setting",
	"au_seedec" => "Allow authors to see decisions setting",
	"rev_seedec" => "Allow reviewers to see decisions setting",
	"final_open" => "Collect final copies setting",
	"final_done" => "Final copy upload deadline"
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
    } else if ($type == "string")
	return ($v == "" ? 0 : array(0, $v));
    else if (is_int($type)) {
	if (ctype_digit($v) && $v >= 0 && $v <= $type)
	    return intval($v);
	else
	    $err = $SettingText[$name] . ": parse error.";
    } else
	return $v;

    $SettingError[$name] = true;
    $Error[] = $err;
    return null;
}

function doTags($set) {
    global $Conf, $Values, $Error, $SettingError;
    if (!$set && isset($_REQUEST["tag_chair"])) {
	$Values["tags"] = preg_split('/\s+/', $_REQUEST["tag_chair"]);
	foreach ($Values["tags"] as $ct)
	    if ($ct && !checkTag($ct, false)) {
		$Error[] = "One of the chair-only tags contains odd characters.";
		$SettingError["tag_chair"] = true;
	    }
    } else if ($set) {
	$Conf->qe("delete from ChairTag", "while updating tags");
	if (count($Values["tags"]) > 0) {
	    $q = "insert into ChairTag (tag) values ";
	    foreach ($Values["tags"] as $ct)
		if ($ct)
		    $q .= "('" . sqlq($ct) . "'), ";
	    $Conf->qe(substr($q, 0, strlen($q) - 2), "while updating tags");
	}
    }
}

function doTopics($set) {
    global $Conf, $Values, $rf;
    if (!$set) {
	$Values["topics"] = true;
	return;
    }

    foreach ($_REQUEST as $k => $v) {
	if (!($k[0] == "t" && $k[1] == "o" && $k[2] == "p"))
	    continue;
	if ($k[3] == "n" && $v != "")
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
    
    $ochange = false;
    $anyo = false;
    foreach (paperOptions() as $id => $o)
	if (isset($_REQUEST["optn$id"])
	    && ($_REQUEST["optn$id"] != $o->optionName
		|| defval($_REQUEST["optd$id"]) != $o->description
		|| defval($_REQUEST["optp$id"], 0) != $o->pcView)) {
	    if ($_REQUEST["optn$id"] == "") {
		$Conf->qe("delete from OptionType where optionId=$id", $while);
		$Conf->qe("delete from PaperOption where optionId=$id", $while);
	    } else {
		$Conf->qe("update OptionType set optionName='" . sqlq($_REQUEST["optn$id"]) . "', description='" . sqlq(defval($_REQUEST["optd$id"])) . "', pcView=" . (defval($_REQUEST["optp$id"]) ? 1 : 0) . " where optionId=$id", $while);
		$anyo = true;
	    }
	    $ochange = true;
	} else
	    $anyo = true;
    
    if (defval($_REQUEST["optnn"]) && $_REQUEST["optnn"] != "New option") {
	$Conf->qe("insert into OptionType (optionName, description, pcView) values ('" . sqlq($_REQUEST["optnn"]) . "', '" . sqlq(defval($_REQUEST["optdn"], "")) . "', " . (defval($_REQUEST["optpn"]) ? 1 : 0) . ")", $while);
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
	if ($k[0] == "d" && $k[1] == "e" && $k[2] == "c"
	    && ($k = cvtint(substr($k, 3), 0)) != 0) {
	    if ($v == "") {
		$Conf->qe("delete from ReviewFormOptions where fieldName='outcome' and level=$k", $while);
		$Conf->qe("update Paper set outcome=0 where outcome=$k", $while);
	    } else if ($v != $dec[$k])
		$Conf->qe("update ReviewFormOptions set description='" . sqlq($v) . "' where fieldName='outcome' and level=$k", $while);
	}

    if (defval($_REQUEST["decn"], "") != "") {
	$delta = (defval($_REQUEST["dtypn"], 1) > 0 ? 1 : -1);
	for ($k = $delta; true; $k += $delta)
	    if (!isset($dec[$k]))
		break;
	
	$Conf->qe("insert into ReviewFormOptions set fieldName='outcome', level=$k, description='" . sqlq($_REQUEST["decn"]) . "'");
    }
}

function doSpecial($name, $set) {
    global $Values;
    if ($name == "tags")
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
	else
	    rf_update(false);
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
    if (isset($Values["resp_open"]) && defval($Conf->settings["resp_open"]) <= 0)
	$Values["resp_open"] = time();

    // warn on other relationships
    if (defval($Values["resp_open"], 0) > 0 && defval($Values["au_seerev"], 0) <= 0)
	$Conf->warnMsg("You have allowed authors to respond to the reviews, but authors can't see the reviews.  This seems odd.");

    // report errors
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br/>\n", $Error));
    else if (count($Values) > 0) {
	$while = "updating settings";
	$tables = "Settings write, ChairTag write, TopicArea write, PaperTopic write";
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
	    if (defval($settings[$n]) == "special")
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


function decorateSettingText($name, $text) {
    global $SettingError;
    if (isset($SettingError[$name]))
	return "<span class='error'>$text</span>";
    else
	return $text;
}

function setting($name) {
    global $Error, $Conf;
    if (count($Error) > 0)
	return defval($_REQUEST[$name], null);
    else
	return defval($Conf->settings[$name], null);
}

function doCheckbox($name, $text, $tr = false) {
    $x = setting($name);
    echo ($tr ? "<tr><td class='nowrap'>" : ""), "<input type='checkbox' name='$name' value='1'";
    if ($x !== null && $x > 0)
	echo " checked='checked'";
    echo " onchange='highlightUpdate()' />&nbsp;", ($tr ? "</td><td>" : ""), decorateSettingText($name, $text), ($tr ? "</td></tr>\n" : "<br />\n");
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
	    echo decorateSettingText($name, $text[0]), "<br /><small>", $text[1], "</small>";
	else
	    echo decorateSettingText($name, $text);
	echo "</td></tr>\n";
    }
    echo "</table>\n";
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
    echo "<tr><td class='$capclass'>", decorateSettingText($name, $text), "</td><td class='lentry'><input type='text' class='textlite' name='$name' value=\"", htmlspecialchars($v), "\" size='30' onchange='highlightUpdate()' />";
    if (!isset($DateExplanation)) {
	echo "<br /><small>Examples: \"now\", \"10 Dec 2006 11:59:59pm PST\" <a href='http://www.gnu.org/software/tar/manual/html_node/tar_109.html'>(more)</a></small>";
	$DateExplanation = true;
    }
    echo "</td></tr>\n";
}

function doGraceRow($name, $text, $capclass = "lcaption") {
    echo "<tr><td class='$capclass'>", decorateSettingText($name, "Grace period"), "</td><td class='lentry'><input type='text' class='textlite' name='$name' value=\"", htmlspecialchars(unparseGrace(setting($name))), "\" size='15' onchange='highlightUpdate()' />";
    if ($capclass == "lcaption")
	echo "<br /><small>Example: \"15 min\"</small>";
    echo "</td></tr>\n";
}


// Accounts
function doAccGroup() {
    global $Conf, $ConfSiteBase, $Me, $belowHr;

    if ($Conf->setting("allowPaperOption") >= 5)
	doCheckbox("acct_addr", "Collect users' addresses and phone numbers");
    else
	doCheckbox("acct_addr", "Collect users' phone numbers");

    echo "<hr /><h3>Program committee &amp; system administrators</h3>";

    echo "<p><a href='${ConfSiteBase}account.php?new=1' class='button'>Create account</a> &nbsp;|&nbsp; ",
	"Select a user's name to edit a profile or change PC/administrator status.</p>\n";
    $pl = new ContactList(false);
    echo $pl->text("pcadminx", $Me, "${ConfSiteBase}contacts.php?t=pcadmin");

    $belowHr = false;
}

// Messages
function doMsgGroup() {
    global $Conf, $ConfSiteBase;
    echo "<strong>Home page message</strong> (HTML allowed)<br />
<textarea class='textlite' name='homemsg' cols='60' rows='10' onchange='highlightUpdate()'>", htmlspecialchars($Conf->settingText("homemsg")), "</textarea>";
}

// Submissions
function doSubGroup() {
    global $Conf, $ConfSiteBase;

    doCheckbox('sub_open', '<b>Open site for submissions</b>');

    echo "<div class='smgap'></div>\n";
    doRadio("sub_blind", array(2 => "Blind submission", 1 => "Optionally blind submission", 0 => "Non-blind submission"));

    echo "<div class='smgap'></div>\n<table>\n";
    doDateRow("sub_reg", "Paper registration deadline", "sub_sub");
    doDateRow("sub_sub", "Paper submission deadline");
    doGraceRow("sub_grace", 'Grace period');
    echo "</table>\n";

    echo "<div class='smgap'></div>\n";
    doCheckbox("sub_pcconf", "Collect authors' PC conflicts with checkboxes");
    doCheckbox("sub_collab", "Collect authors' other collaborators as text");

    echo "<div class='smgap'></div>\n";
    doRadio("sub_freeze", array(0 => array("Authors can update submissions until the deadline", "PC members cannot download submitted papers until the submission deadline passes."), 1 => array("Authors must freeze the final version of each submission", "PC members can download papers as soon as they are submitted.")));
    
    echo "<div class='smgap'></div><table>\n";
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
	echo "<div class='smgap'></div>\n";
	echo "<table>";
	$opt = paperOptions();
	$sep = "";
	foreach ($opt as $o) {
	    echo $sep;
	    echo "<tr><td class='lxcaption'>Option name</td><td class='lentry'><input type='text' class='textlite' name='optn$o->optionId' value=\"", htmlspecialchars($o->optionName), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	    echo "<tr><td class='lxcaption'>Description</td><td class='lentry textarea'><textarea class='textlite' name='optd$o->optionId' rows='2' cols='50' onchange='highlightUpdate()'>", htmlspecialchars($o->description), "</textarea><br />\n",
		"<input type='checkbox' name='optp$o->optionId' value='1'", ($o->pcView ? " checked='checked'" : ""), " />&nbsp;Visible to PC</td></tr>\n";
	    $sep = "<tr><td></td><td><div class='smgap'></div></td></tr>\n";
	}
    
	echo ($sep ? "<tr><td colspan='2'><hr /></td></tr>\n" : "");
	
	echo "<tr><td class='lxcaption'>Option name</td><td class='lentry'><input type='text' class='textlite' name='optnn' value=\"New option\" size='50' onchange='highlightUpdate()' onfocus=\"tempText(this, 'New option', 1)\" onblur=\"tempText(this, 'New option', 0)\" /></td></tr>\n";
	echo "<tr><td class='lxcaption'>Description</td><td class='lentry textarea'><textarea class='textlite' name='optdn' rows='2' cols='50' onchange='highlightUpdate()'></textarea><br />\n",
	    "<input type='checkbox' name='optpn' value='1' checked='checked' />&nbsp;Visible to PC</td></tr>\n";
	
	echo "</table>\n";
    }


    // Topics
    echo "<hr /><h3>Topics</h3>\n";
    echo "Enter topics one per line.  Authors use checkboxes to identify the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its text.  Add topics in batches of up to 3 at a time.\n";
    echo "<div class='smgap'></div><table>";
    $td1 = "<td class='lcaption'>Current</td>";
    foreach ($rf->topicOrder as $tid => $crap) {
	echo "<tr>$td1<td><input type='text' class='textlite' name='top$tid' value=\"", htmlspecialchars($rf->topicName[$tid]), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	$td1 = "<td class='lcaption'><br /></td>";
    }
    $td1 = "<td class='lcaption'>New</td>";
    for ($i = 1; $i <= 3; $i++) {
	echo "<tr>$td1<td><input type='text' class='textlite' name='topn$i' value=\"\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	$td1 = "<td class='lcaption'><br /></td>";
    }

    echo "</table>\n";
}

// Reviews
function doRevGroup() {
    global $Conf, $Error;

    doCheckbox('rev_open', '<b>Open site for reviewing</b>');

    echo "<div class='smgap'></div>\n";
    doRadio("rev_blind", array(2 => "Blind review", 1 => "Optionally blind review", 0 => "Non-blind review"));

    echo "<div class='smgap'></div>\n";
    doCheckbox('rev_notifychair', 'PC chairs are notified of new reviews by email');

    echo "<div class='smgap'></div>\n";
    echo "<table><tr><td class='lcaption'>", decorateSettingText("tag_chair", "Chair-only tags"), "</td>";
    if (count($Error) > 0)
	$v = defval($_REQUEST["tag_chair"], "");
    else {
	$t = array_keys(chairTags());
	sort($t);
	$v = join(" ", $t);
    }
    echo "<td><input type='text' class='textlite' name='tag_chair' value=\"", htmlspecialchars($v), "\" size='50' onchange='highlightUpdate()' /><br /><small>Only PC chairs can change these tags.  (PC members can still <i>view</i> the tags.)</small></td></tr></table>";

    echo "<hr />";


    // PC reviews
    echo "<h3>PC reviews</h3>\n";

    doCheckbox('pcrev_any', 'PC members can review <i>any</i> submitted paper');

    echo "<div class='smgap'></div>\n<table>\n";
    doDateRow("pcrev_soft", "Soft deadline", "pcrev_hard");
    doDateRow("pcrev_hard", "Hard deadline");
    echo "</table>\n";

    echo "<div class='smgap'></div>\n<table>\n";
    doCheckbox('pc_seeallrev', "<b>Allow PC to see all reviews</b> except for conflicts<br /><small>When unchecked, a PC member can see reviews for a paper only after submitting their own review for that paper.</small>", true);
    echo "</table>\n";

    echo "<hr />";


    // External reviews
    echo "<h3>External reviews</h3>\n";

    if ($Conf->setting("allowPaperOption") > 1) {
	doCheckbox('extrev_chairreq', "PC chair must approve proposed external reviewers");
	echo "<div class='smgap'></div>";
    }
    
    echo "<table>\n";
    doDateRow("extrev_soft", "Soft deadline", "extrev_hard");
    doDateRow("extrev_hard", "Hard deadline");
    echo "</table>\n";

    echo "<div class='smgap'></div>";
    echo "Can external reviewers view the other reviews for their assigned papers, once they've submitted their own?<br />\n";
    doRadio("extrev_view", array(0 => "No", 2 => "Yes", 1 => "Yes, but they can't see who wrote blind reviews"));
}

// Review form
function doRfoGroup() {
    require_once("Chair/SetReviewForm.php");
    rf_show();
}

// Responses and decisions
function doDecGroup() {
    global $Conf, $rf;
    doCheckbox('au_seerev', '<b>Allow authors to see reviews</b>');

    echo "<div class='smgap'></div>\n<table>";
    doCheckbox('resp_open', "<b>Collect authors' responses to the reviews:</b>", true);
    echo "<tr><td></td><td><table>";
    doDateRow('resp_done', 'Deadline', null, "lxcaption");
    doGraceRow('resp_grace', 'Grace period', "lxcaption");
    echo "</table></td></tr></table>";

    echo "<div class='smgap'></div>\n";
    doCheckbox('au_seedec', '<b>Allow authors to see decisions</b> (accept/reject)');
    doCheckbox('rev_seedec', 'Allow reviewers to see decisions and accepted authors');

    echo "<div class='smgap'></div>\n";
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
    echo "<tr><td class='lcaption'>New decision type<br /></td><td class='lentry nowrap'><input type='text' class='textlite' name='decn' value=\"\" size='35' /> &nbsp; <select name='dtypn'><option value='1' selected='selected'>Accept class</option><option value='-1'>Reject class</option></select>";
    echo "<br /><small>Examples: \"Accepted as short paper\", \"Early reject\"</small>";
    echo "</td></tr>\n</table>\n";
    
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

echo "<form method='post' action='settings.php?post=1' enctype='multipart/form-data'><div><input type='hidden' name='group' value='$Group' />\n";

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
	echo "<div class='lhl1'><a class='q' href='settings.php?group=$k'>$v</a></div>";
    else
	echo "<div class='lhl0'><a href='settings.php?group=$k'>$v</a></div>";
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

echo ($belowHr ? "<hr />\n" : "<div class='smgap'></div>\n");
echo "<input type='submit' class='button",
    (defval($_REQUEST["sample"], "none") == "none" ? "" : "_alert"),
    "' name='update' value='Save changes' /> ";
echo "&nbsp;<input type='submit' class='button' name='cancel' value='Cancel' />";

echo "</div></td></tr>
<tr class='last'><td class='caption'></td></tr>
</table></div></form>\n";

$Conf->footer();
