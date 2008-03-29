<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC("index$ConfSiteSuffix");
$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0 || !$Me->privChair)
    $reviewer = $Me->contactId;
$_REQUEST["t"] = ($Conf->setting("pc_seeall") > 0 ? "act" : "s");

// choose a sensible default action (if someone presses enter on a form element)
if (isset($_REQUEST["default"]) && defval($_REQUEST, "defaultact"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
else if (isset($_REQUEST["default"]))
    $_REQUEST["update"] = true;

if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $_REQUEST["get"] = $_REQUEST["getaction"];
if ((defval($_REQUEST, "get") == "revpref" || defval($_REQUEST, "get") == "revprefx") && !isset($_REQUEST["pap"]))
    $_REQUEST["pap"] = "all";


// Update preferences
function savePreferences($reviewer) {
    global $Conf, $Me, $reviewTypeName, $OK;

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($_REQUEST as $k => $v)
	if (strlen($k) > 7 && $k[0] == "r" && substr($k, 0, 7) == "revpref"
	    && ($p = cvtint(substr($k, 7))) > 0) {
	    if (($v = cvtpref($v)) >= -1000000) {
		if ($v != 0)
		    $setting[$p] = $v;
		$pmax = max($pmax, $p);
	    } else
		$error = true;
	}

    if ($error)
	$Conf->errorMsg("Preferences must be small positive or negative integers.");
    if ($pmax == 0 && !$error)
	$Conf->errorMsg("No reviewer preferences to update.");
    if ($pmax == 0)
	return;

    $while = "while saving review preferences";
    $result = $Conf->qe("lock tables PaperReviewPreference write", $while);
    if (!$result)
	return $result;

    $delete = "delete from PaperReviewPreference where contactId=$reviewer and (";
    $orjoin = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p])) {
	    $delete .= $orjoin;
	    if (!isset($setting[$p + 1]))
		$delete .= "paperId=$p";
	    else {
		$delete .= "paperId between $p and ";
		for ($p++; isset($setting[$p + 1]); $p++)
		    /* nada */;
		$delete .= $p;
	    }
	    $orjoin = " or ";
	}
    $Conf->qe($delete . ")", $while);

    $q = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p]))
	    $q .= "($p, $reviewer, $setting[$p]), ";
    if (strlen($q))
	$Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values " . substr($q, 0, strlen($q) - 2), $while);

    $Conf->qe("unlock tables", $while);
    if ($OK)
	$Conf->confirmMsg("Preferences saved.");
}
if (isset($_REQUEST["update"]))
    savePreferences($reviewer);


// Select papers
if (isset($_REQUEST["setpaprevpref"]) || isset($_REQUEST["get"])) {
    PaperSearch::parsePapersel();
    if (!isset($papersel))
	$Conf->errorMsg("No papers selected.");
}


// Set multiple paper preferences
if (isset($_REQUEST["setpaprevpref"]) && isset($papersel)) {
    if (($v = cvtpref($_REQUEST["paprevpref"])) < -1000000)
	$Conf->errorMsg("Preferences must be small positive or negative integers.");
    else {
	foreach ($papersel as $p)
	    $_REQUEST["revpref$p"] = $v;
	savePreferences($reviewer);
    }
}


// Parse paper preferences
function parseUploadedPreferences($filename, $printFilename, $reviewer) {
    global $Conf;
    if (($text = file_get_contents($filename)) === false)
	return $Conf->errorMsg("Cannot read uploaded file.");
    $printFilename = htmlspecialchars($printFilename);
    $text = cleannl($text);
    $lineno = 0;
    $successes = 0;
    $errors = array();
    foreach (split("\n", $text) as $line) {
	$line = trim($line);
	$lineno++;
	
	if ($line == "" || $line[0] == "#" || substr($line, 0, 6) == "==-== ")
	    /* do nothing */;
	else if (preg_match('/^(\d+)\s*[\t,]\s*([^\s,]+)\s*([\t,]|$)/', $line, $m)) {
	    if (($pref = cvtpref($m[2])) >= -1000000) {
		$_REQUEST["revpref$m[1]"] = $m[2];
		$successes++;
	    } else if (($m[2] = strtolower($m[2])) != "x"
		       && $m[2] != "conflict")
		$errors[] = "<span class='lineno'>$printFilename:$lineno:</span> bad review preference, should be integer";
	} else if (count($errors) < 20)
	    $errors[] = "<span class='lineno'>$printFilename:$lineno:</span> syntax error, expected <code>paperID,preference</code>";
	else {
	    $errors[] = "<span class='lineno'>$printFilename:$lineno:</span> too many syntax errors, giving up";
	    break;
	}
    }

    if (count($errors) > 0)
	$Conf->errorMsg("There were some errors while parsing the uploaded preferences file. <div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p></div>");
    if ($successes > 0)
	savePreferences($reviewer);
}
if (isset($_REQUEST["upload"]) && fileUploaded($_FILES["uploadedFile"], $Conf))
    parseUploadedPreferences($_FILES["uploadedFile"]["tmp_name"], $_FILES["uploadedFile"]["name"], $reviewer);
else if (isset($_REQUEST["upload"]))
    $Conf->errorMsg("Select a preferences file to upload.");


// Search actions
if (isset($_REQUEST["get"]) && isset($papersel)) {
    include("search.php");
    exit;
}


// Header and body
$Conf->header("Review Preferences", "revpref", actionBar());


$rf = reviewForm();
if (count($rf->topicName))
    $topicnote = " and their topics.  You have high
interest in <span class='topic2'>bold topics</span> and low interest in <span
class='topic0'>grey topics</span>.  &ldquo;Topic score&rdquo; is higher the more you
are interested in the paper's topics";
else
    $topicnote = "";

$Conf->infoMsg("<p>Review preferences are integers.
The higher your preference, the more you want to review a paper.
0 means you don't care either way; use negative numbers for papers you don't want to review, and &minus;100 or less for conflicts.
Multiple papers can have the same preference.
The system will try to assign your reviews in preference order, using
topic scores to break ties.</p>

<p>The list shows all submitted papers$topicnote.  Click on a column heading
to sort by that column.  Enter preferences in the text boxes or by following
the paper links.  You may also upload preferences from a text file; see the
&ldquo;Download&rdquo; and &ldquo;Upload&rdquo; links below the paper
list.</p>");


// set options to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["foldplau"] = !defval($_REQUEST, "showau", 0);
    $_SESSION["foldplanonau"] = !defval($_REQUEST, "showanonau", 0);
    $_SESSION["foldplabstract"] = !defval($_REQUEST, "showabstract", 0);
}


// search
$search = new PaperSearch($Me, array("t" => $_REQUEST["t"], "c" => $reviewer,
				     "urlbase" => "reviewprefs$ConfSiteSuffix?reviewer=$reviewer",
				     "q" => defval($_REQUEST, "q", "")));
$pl = new PaperList(true, true, $search);
$pl->showHeader = PaperList::HEADER_TITLES;
$pl->extraFooter = "<div id='plactr'><input class='hbutton' type='submit' name='update' value='Save changes' /></div>";
$pl_text = $pl->text("editReviewPreference", $Me);


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

echo "<form method='get' action='reviewprefs$ConfSiteSuffix' accept-encoding='UTF-8' id='redisplayform'>\n<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>",
	"<select name='reviewer' onchange='e(\"redisplayform\").submit()'>";

    $query = "select ContactInfo.contactId, firstName, lastName,
		count(preference) as preferenceCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReviewPreference on (ContactInfo.contactId=PaperReviewPreference.contactId)
		group by contactId
		order by lastName, firstName, email";
    $result = $Conf->qe($query);
    while (($row = edb_orow($result))) {
	echo "<option value='$row->contactId'";
	if ($row->contactId == $reviewer)
	    echo " selected='selected'";
	echo ">", contactHtml($row);
	if ($row->preferenceCount <= 0)
	    echo " (no preferences)";
	echo "</option>";
    }
    echo "</select><div class='smgap'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input class='textlite' type='text' size='32' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>",
    "<td><input class='button' type='submit' name='redisplay' value='Redisplay' /></td>",
    "</tr>\n";

echo "<tr><td class='lxcaption'><strong>Show:</strong> &nbsp;</td><td class='lentry'>";
if ($Conf->blindSubmission() <= 1) {
    echo "<input type='checkbox' name='showau' value='1'";
    if ($Conf->blindSubmission() == 1 && !($pl->headerInfo["authors"] & 1))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,1)' />&nbsp;Authors<span class='sep'></span>\n";
}
if ($Conf->blindSubmission() >= 1 && $Me->privChair) {
    echo "<input type='checkbox' name='showanonau' value='1'";
    if (!($pl->headerInfo["authors"] & 2))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplanonau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,2)' />&nbsp;",
	($Conf->blindSubmission() == 1 ? "Anonymous authors" : "Authors"),
	"<span class='sep'></span>\n";
}
if ($pl->headerInfo["abstracts"]) {
    echo "<input type='checkbox' name='showabstract' value='1'";
    if (defval($_SESSION, "foldplabstract", 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldabstract(\"pl\",!this.checked,5)' />&nbsp;Abstracts<img id='foldsession.pl5' src='${ConfSiteBase}sessionvar$ConfSiteSuffix?var=foldplabstract&amp;val=", defval($_SESSION, "foldplabstract", 1), "&amp;cache=1' width='1' height='1' /><br /><div id='abstractloadformresult'></div>\n";
}
echo "</td></tr>\n</table></form>"; // </div></div>
echo "</td></tr></table>\n";


// ajax preferences form
echo "<form id='prefform' method='post' action=\"${ConfSiteBase}paper$ConfSiteSuffix\" enctype='multipart/form-data' accept-encoding='UTF-8'><div>",
    "<input type='hidden' name='p' value='' />",
    "<input type='hidden' name='revpref' value='' />";
if ($Me->privChair)
    echo "<input type='hidden' name='contactId' value='$reviewer' />";
echo "</div></form>\n\n";


// main form
echo "<form class='assignpc' method='post' action=\"reviewprefs$ConfSiteSuffix?reviewer=$reviewer",
    (defval($_REQUEST, "q") ? "&amp;q=" . htmlspecialchars($_REQUEST["q"]) : ""),
    "&amp;post=1\" enctype='multipart/form-data' accept-encoding='UTF-8'>",
    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
    "<input class='hidden' type='submit' name='default' value='1' />",
    "<div class='assignresult'>\n",
    $pl_text,
    "</div>";
// echo "<table class='center'><tr><td><input class='hbutton' type='submit' name='update' value='Save preferences' /></td></tr></table>\n";
echo "</form>\n";

$Conf->footer();
