<?php 
require_once('Code/header.inc');
require_once('Code/tags.inc');
require_once('Code/review.inc');
require_once('Code/Calendar.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('index.php');
$SettingError = array();
$Error = array();
$Values = array();


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
	"rev_notifychair" => "Notify chairs about reviews setting",
	"pcrev_any" => "PC can review any paper setting",
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
    } else if (is_int($type)) {
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

function accountValue($name, $type) {
    global $Values;
    $v = parseValue($name, $type);
    if ($v === null) {
	if ($type != "cdate" && $type != "check")
	    return;
	$v = 0;
    }
    if ($v <= 0 && !is_int($type))
	$Values[$name] = null;
    else
	$Values[$name] = $v;
}

if (isset($_REQUEST["update"])) {
    foreach (array("sub_reg", "sub_sub", "pcrev_soft", "pcrev_hard",
		   "extrev_soft", "extrev_hard", "final_done", "resp_done")
	     as $date)
	accountValue($date, "date");
    foreach (array("rev_notifychair", "pcrev_any", "au_seerev", "au_seedec",
		   "final_open", "resp_open", "pc_seeallrev", "sub_pcconf",
		   "sub_collab") as $item)
	accountValue($item, "check");
    accountValue("sub_grace", "grace");
    accountValue("sub_open", "cdate");
    accountValue("rev_open", "cdate");
    accountValue("sub_blind", 2);
    accountValue("rev_blind", 2);
    accountValue("extrev_view", 2);
    accountValue("final_grace", "grace");
    accountValue("resp_grace", "grace");

    // check date relationships
    foreach (array("sub_reg" => "sub_sub", "pcrev_soft" => "pcrev_hard",
		   "extrev_soft" => "extrev_hard") as $first => $second)
	if (isset($Values[$first]) && isset($Values[$second])) {
	    if ($Values[$second] !== null && $Values[$first] === null)
		$Values[$first] = $Values[$second];
	    else if ($Values[$second] !== null && $Values[$first] > $Values[$second]) {
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
    
    // check tags
    if (isset($_REQUEST["tag_chair"])) {
	$chairtags = preg_split('/\s+/', $_REQUEST["tag_chair"]);
	foreach ($chairtags as $ct)
	    if ($ct && !checkTag($ct, false)) {
		$Error[] = "One of the special tags contains odd characters.";
		$SettingError["tag_chair"] = true;
	    }
    }
    
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br/>\n", $Error));
    else if (count($Values) > 0) {
	$rf = reviewForm();
	$while = "updating settings";
	$tables = "Settings write, ChairTag write, TopicArea write, PaperTopic write";
	if ($Conf->setting("allowPaperOption"))
	    $tables .= ", OptionType write, PaperOption write";
	$Conf->qe("lock tables $tables", $while);
	// alert others since we're changing settings
	$Values['revform_update'] = time();

	// first, settings
	$dq = $aq = "";
	foreach ($Values as $n => $v) {
	    $dq .= " or name='$n'";
	    if ($v !== null)
		$aq .= ", ('$n', '" . sqlq($v) . "')";
	}
	$Conf->qe("delete from Settings where " . substr($dq, 4), $while);
	if (strlen($aq))
	    $Conf->qe("insert into Settings (name, value) values " . substr($aq, 2), $while);

	// then, chair-only tags
	if (isset($_REQUEST["tag_chair"])) {
	    $Conf->qe("delete from ChairTag", $while);
	    if (count($chairtags) > 0) {
		$q = "insert into ChairTag (tag) values ";
		foreach ($chairtags as $ct)
		    if ($ct)
			$q .= "('" . sqlq($ct) . "'), ";
		$Conf->qe(substr($q, 0, strlen($q) - 2), $while);
	    }
	}

	// then, paper topics
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

	// then, if appropriate, paper options
	if ($Conf->setting("allowPaperOption")) {
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
	
	$Conf->qe("unlock tables", $while);
	$Conf->updateSettings();
    }
}


// header and script
$Conf->header("Conference Settings");


echo "<form method='post' action='settings.php?post=1' enctype='multipart/form-data'>\n";
echo "<div class='smgap'></div>\n";
echo "<table class='center'><tr><td><input type='submit' class='button' name='update' value='Save changes' /> ";
echo "&nbsp;<input type='submit' class='button' name='cancel' value='Cancel' />";
echo "</td></tr></table>\n";
echo "<table class='half'><tr><td class='l'>";


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
    foreach ($varr as $k => $text) {
	echo "<input type='radio' name='$name' value='$k'";
	if ($k == $x)
	    echo " checked='checked'";
	echo " onchange='highlightUpdate()' />&nbsp;", decorateSettingText($name, $text), "<br />\n";
    }
}

function doDateRow($name, $text, $capclass = "rcaption") {
    global $Conf, $Error;
    $x = setting($name);
    if ($x === null || (count($Error) == 0 && $x <= 0))
	$v = "N/A";
    else if (count($Error) == 0)
	$v = $Conf->parseableTime($x);
    else
	$v = $x;
    echo "<tr><td class='$capclass'>", decorateSettingText($name, $text), "</td><td><input type='text' class='textlite' name='$name' value=\"", htmlspecialchars($v), "\" size='30' onchange='highlightUpdate()' /></td></tr>\n";
}

function doGraceRow($name, $text, $capclass = "rcaption") {
    echo "<tr><td class='$capclass'>", decorateSettingText($name, "Grace period"), "</td><td><input type='text' class='textlite' name='$name' value=\"", htmlspecialchars(unparseGrace(setting($name))), "\" size='15' onchange='highlightUpdate()' />";
    if ($capclass == "rcaption")
	echo "<br /><small>Example: \"15 min\"</small>";
    echo "</td></tr>\n";
}

// Submissions
echo "<div class='bgrp'><div class='bgrp_head'>Submissions</div><div class='bgrp_body'>";
doCheckbox('sub_open', '<b>Open site for submissions</b>');

echo "<div class='smgap'></div>\n";
doRadio("sub_blind", array(2 => "Blind submission", 1 => "Optionally blind submission", 0 => "Non-blind submission"));

echo "<div class='smgap'></div>\n<table>\n";
doDateRow("sub_reg", "Paper registration deadline");
doDateRow("sub_sub", "Paper submission deadline");
doGraceRow("sub_grace", 'Grace period');
echo "</table>\n";

echo "<div class='smgap'></div>\n";
doCheckbox("sub_pcconf", "Collect authors' PC conflicts with checkboxes");
doCheckbox("sub_collab", "Collect authors' potential conflicts as text");

echo "</div></div>\n\n";


// Paper topics
$rf = reviewForm();
echo "<div class='bgrp ", (count($rf->topicName) && defval($Conf->settings["sub_open"]) ? "folded" : "unfolded"), "' id='foldtopic'><div class='bgrp_head'><a href=\"javascript:fold('topic', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('topic', 1)\" class='foldbutton folder'>&minus;</a>&nbsp;Paper topics</div><div class='bgrp_body extension'>\n";
echo "<table>";
$td1 = "<td class='rcaption'>Current</td>";
foreach ($rf->topicOrder as $tid => $crap) {
    echo "<tr>$td1<td><input type='text' class='textlite' name='top$tid' value=\"", htmlspecialchars($rf->topicName[$tid]), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
    $td1 = "<td class='rcaption'><br /></td>";
}
$td1 = "<td class='rcaption'>New</td>";
for ($i = 1; $i <= 3; $i++) {
    echo "<tr>$td1<td><input type='text' class='textlite' name='topn$i' value=\"\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
    $td1 = "<td class='rcaption'><br /></td>";
}

echo "</table>\n";
echo "<div class='smgap'></div>\n<small>Enter topics one per line.  Authors use checkboxes to identify the topics that apply to their papers; PC members use this information to find papers they'll want to review.  To delete a topic, delete its text.  Add topics in batches of up to 3 at a time.</small>\n";
echo "</div></div>";


// Paper options
if ($Conf->setting("allowPaperOption")) {
    echo "<div class='bgrp folded' id='foldoption'><div class='bgrp_head'>",
	"<a href=\"javascript:fold('option', 0)\" class='foldbutton unfolder'>+</a>",
	"<a href=\"javascript:fold('option', 1)\" class='foldbutton folder'>&minus;</a>",
	"&nbsp;Paper options</div><div class='bgrp_body extension'>\n";
    echo "<table>";
    $opt = paperOptions();
    $sep = "";
    foreach ($opt as $o) {
	echo $sep;
	echo "<tr><td class='xcaption'>Option name</td><td><input type='text' class='textlite' name='optn$o->optionId' value=\"", htmlspecialchars($o->optionName), "\" size='50' onchange='highlightUpdate()' /></td></tr>\n";
	echo "<tr><td class='xcaption'>Description</td><td><textarea class='textlite' name='optd$o->optionId' rows='2' cols='50' onchange='highlightUpdate()'>", htmlspecialchars($o->description), "</textarea></td></tr>\n";
	echo "<tr><td></td><td><input type='checkbox' name='optp$o->optionId' value='1'", ($o->pcView ? " checked='checked'" : ""), " />&nbsp;Visible to PC</td></tr>\n";
	$sep = "<tr><td></td><td><div class='smgap'></div></td></tr>\n";
    }
    
    echo ($sep ? "<tr><td colspan='2'><hr /></td></tr>\n" : "");
    
    echo "<tr><td class='xcaption'>Option name</td><td><input type='text' class='textlite' name='optnn' value=\"New option\" size='50' onchange='highlightUpdate()' onfocus=\"tempText(this, 'New option', 1)\" onblur=\"tempText(this, 'New option', 0)\" /></td></tr>\n";
    echo "<tr><td class='xcaption'>Description</td><td><textarea class='textlite' name='optdn' rows='2' cols='50' onchange='highlightUpdate()'></textarea></td></tr>\n";
    echo "<tr><td></td><td><input type='checkbox' name='optpn' value='1' checked='checked' />&nbsp;Visible to PC</td></tr>\n";

    echo "</table>\n";
    echo "<div class='smgap'></div>\n<small>Paper options are things like \"Consider this paper for a Best Student Paper award\" or \"Allow the shadow PC to see this paper\" selected by authors at submission time.  The \"option name\" should be brief, three or four words at most; it appears as caption text to the left of the option.  The description should be longer and may use HTML.  To delete an option, delete its name.  Add options one at a time.</small>\n";
    echo "</div></div>";
}


// Responses and decisions
echo "<div class='bgrp'><div class='bgrp_head'>Decisions</div><div class='bgrp_body'>";
doCheckbox('au_seerev', '<b>Allow authors to see reviews</b>');

echo "<div class='smgap'></div>\n<table>";
doCheckbox('resp_open', '<b>Collect responses to the reviews:</b>', true);
echo "<tr><td></td><td><table>";
doDateRow('resp_done', 'Deadline', "xcaption");
doGraceRow('resp_grace', 'Grace period', "xcaption");
echo "</table></td></tr></table>";

echo "<div class='smgap'></div>\n";
doCheckbox('au_seedec', '<b>Allow authors to see decisions</b> (accept/reject)');
doCheckbox('rev_seedec', 'Allow reviewers to see decisions and accepted authors');

echo "</div></div>\n\n";


// Final copies
echo "<div class='bgrp'><div class='bgrp_head'>Final copies</div><div class='bgrp_body'>";
echo "<table>";
doCheckbox('final_open', '<b>Collect final copies of accepted papers:</b>', true);
echo "<tr><td></td><td><table>";
doDateRow("final_done", "Deadline", "xcaption");
doGraceRow("final_grace", "Grace period", "xcaption");
echo "</table></td></tr></table></div></div>\n\n";


echo "</td><td class='r'>";


// Reviews
echo "<div class='bgrp'><div class='bgrp_head'>Reviews</div><div class='bgrp_body'>";
doCheckbox('rev_open', '<b>Open site for reviewing</b>');

echo "<div class='smgap'></div>\n";
doRadio("rev_blind", array(2 => "Blind review", 1 => "Optionally blind review", 0 => "Non-blind review"));

echo "<div class='smgap'></div>\n";
doCheckbox('rev_notifychair', 'PC chairs are notified of new reviews by email');

echo "<div class='smgap'></div>\n<table>\n";
doCheckbox('pc_seeallrev', "<b>Allow PC to see all reviews</b> except for conflicts<br /><small>When unchecked, a PC member can see reviews for a paper only after submitting their own review for that paper.</small>", true);
echo "</table>\n";

echo "</div></div>\n\n";


// PC reviews
echo "<div class='bgrp'><div class='bgrp_head'>PC reviews</div><div class='bgrp_body'>";

doCheckbox('pcrev_any', 'PC members can review <i>any</i> submitted paper');

echo "<div class='smgap'></div>\n<table>\n";
doDateRow("pcrev_soft", "Soft deadline");
doDateRow("pcrev_hard", "Hard deadline");
echo "</table>\n";

echo "</div></div>\n\n";


// External reviews
echo "<div class='bgrp'><div class='bgrp_head'>External reviews</div><div class='bgrp_body'>";

echo "<table>\n";
doDateRow("extrev_soft", "Soft deadline");
doDateRow("extrev_hard", "Hard deadline");
echo "</table>\n";

echo "<div class='smgap'></div>";
echo "Can external reviewers view the other reviews for their assigned papers, once they've submitted their own?<br />\n";
doRadio("extrev_view", array(0 => "No", 2 => "Yes", 1 => "Yes, but they can't see who wrote the reviews"));

echo "</div></div>\n\n";


// Tags
echo "<div class='bgrp'><div class='bgrp_head'>Tags</div><div class='bgrp_body'>";
echo "<table><tr><td class='rcaption'>", decorateSettingText("tag_chair", "Special tags"), "</td>";
if (count($Error) > 0)
    $v = defval($_REQUEST["tag_chair"], "");
else {
    $t = array_keys(chairTags());
    sort($t);
    $v = join(" ", $t);
}
echo "<td><input type='text' class='textlite' name='tag_chair' value=\"", htmlspecialchars($v), "\" size='50' onchange='highlightUpdate()' /><br /><small>Only PC chairs can change these tags.</small></td></tr></table>";
echo "</div></div>\n\n";


echo "</td></tr></table>\n</form>\n";


if ($Me->amAssistant()) {
    echo "<p><a href='Chair/ShowCalendar.php' target='_blank'>Show calendar</a> &mdash;
<a href='http://www.php.net/manual/en/function.strtotime.php' target='_blank'>How to specify a date</a></p>\n";

    //crp_showdate('reviewerViewDecision');
    //crp_showdate('PCGradePapers');
    //crp_showdate('PCMeetingView');
    //crp_showdate('EndOfTheMeeting');
}

$Conf->footer();
