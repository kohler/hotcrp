<?php
// reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC();
$reviewer = rcvtint($_REQUEST["reviewer"]);
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
if ((defval($_REQUEST, "get") == "revpref" || defval($_REQUEST, "get") == "revprefx") && !isset($_REQUEST["pap"]) && !isset($_REQUEST["p"]))
    $_REQUEST["p"] = "all";


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

    $deletes = array();
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p])) {
	    $p0 = $p;
	    while (isset($setting[$p + 1]))
		++$p;
	    if ($p0 == $p)
		$deletes[] = "paperId=$p0";
	    else
		$deletes[] = "paperId between $p0 and $p";
	}
    if (count($deletes))
	$Conf->qe("delete from PaperReviewPreference where contactId=$reviewer and (" . join(" or ", $deletes) . ")", $while);

    $q = "";
    for ($p = 1; $p <= $pmax; $p++)
	if (isset($setting[$p]) && $setting[$p] != 0)
	    $q .= "($p, $reviewer, $setting[$p]), ";
    if (strlen($q))
	$Conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values " . substr($q, 0, strlen($q) - 2) . " on duplicate key update preference=values(preference)", $while);

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
PaperSearch::clearPaperselRequest();


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
    foreach (explode("\n", $text) as $line) {
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


// set options to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["pfdisplay"] = " ";
    foreach ($paperListFolds as $n => $fdef)
	if (defval($_REQUEST, "show$n"))
	    $_SESSION["pfdisplay"] .= "$n ";
    redirectSelf();
}
$pldisplay = displayOptionsSet("pfdisplay");


// Header and body
$Conf->header("Review Preferences", "revpref", actionBar());


$rf = reviewForm();
if (count($rf->topicName)) {
    $topicnote = " and their topics.  You have high
interest in <span class='topic2'>bold topics</span> and low interest in <span
class='topic0'>grey topics</span>.  &ldquo;Topic score&rdquo; is higher the more you
are interested in the paper's topics";
    $topicnote2 = ", using topic scores to break ties";
} else
    $topicnote = $topicnote2 = "";

$Conf->infoMsg("<p>A review preference is a small integer that indicates how much you want to
review a paper.  Positive numbers mean you want to review the paper, negative
numbers mean you don&rsquo;t.  The further from 0, the stronger you feel; the
default, 0, means you&rsquo;re indifferent.  &minus;100 means you think you have a conflict, and
&minus;20 to 20 is a typical range for real preferences.  Multiple papers can
have the same preference.  The system&rsquo;s automatic assignment algorithm
attempts to assign reviews in preference order$topicnote2.  Different users&rsquo;
preference values are not compared and need not use the same
scale.</p>

<p>The list shows all submitted papers$topicnote.  Select a column heading
to sort by that column.  Enter preferences in the text boxes or by following
the paper links.  You may also upload preferences from a text file; see the
&ldquo;Download&rdquo; and &ldquo;Upload&rdquo; links below the paper
list.</p>");


// search
$search = new PaperSearch($Me, array("t" => $_REQUEST["t"],
				     "urlbase" => "reviewprefs$ConfSiteSuffix?reviewer=$reviewer",
				     "q" => defval($_REQUEST, "q", "")), $reviewer);
$pl = new PaperList($search, true, true);
$pl->showHeader = PaperList::HEADER_TITLES;
$pl->foldtype = "pf";
$pl->footer = "<div id='plactr'><input class='hb' type='submit' name='update' value='Save changes' /></div>";
$pl_text = $pl->text("editReviewPreference", $Me, "pltable_full");


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

$showing_au = ($Conf->blindSubmission() <= BLIND_OPTIONAL && strpos($pldisplay, " au ") !== false);
$showing_anonau = (($Conf->blindSubmission() >= BLIND_OPTIONAL || $Me->privChair) && strpos($pldisplay, " anonau ") !== false);

echo "<form method='get' action='reviewprefs$ConfSiteSuffix' accept-charset='UTF-8' id='redisplayform' class='",
    ($showing_au || $showing_anonau ? "fold10o" : "fold10c"),
    "'>\n<table>";

if ($Me->privChair) {
    echo "<tr><td class='lxcaption'><strong>Preferences:</strong> &nbsp;</td><td class='lentry'>";

    $query = "select ContactInfo.contactId, firstName, lastName,
		count(preference) as preferenceCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReviewPreference on (ContactInfo.contactId=PaperReviewPreference.contactId)
		group by contactId
		order by lastName, firstName, email";
    $result = $Conf->qe($query);
    $revopt = array();
    while (($row = edb_orow($result))) {
	$revopt[$row->contactId] = contactHtml($row);
	if ($row->preferenceCount <= 0)
	    $revopt[$row->contactId] .= " (no preferences)";
    }

    echo tagg_select("reviewer", $revopt, $reviewer, array("onchange" => "\$\$(\"redisplayform\").submit()")),
	"<div class='g'></div></td></tr>\n";
}

echo "<tr><td class='lxcaption'><strong>Search:</strong></td><td class='lentry'><input class='textlite' type='text' size='32' name='q' value=\"", htmlspecialchars(defval($_REQUEST, "q", "")), "\" /><span class='sep'></span></td>",
    "<td><input class='b' type='submit' name='redisplay' value='Redisplay' /></td>",
    "</tr>\n";

echo "<tr><td class='lxcaption'><strong>Show:</strong> &nbsp;",
    foldsessionpixel("pl", "pfdisplay", null),
    "</td><td colspan='2' class='lentry'>";
$sep = "";
$loadforms = "";
if ($Conf->blindSubmission() <= BLIND_OPTIONAL) {
    echo $sep,
	tagg_checkbox("showau", 1, strpos($pldisplay, " au ") !== false,
		      array("disabled" => ($Conf->blindSubmission() == BLIND_OPTIONAL && !($pl->headerInfo["authors"] & 1)),
			    "onchange" => "foldplinfo(this,'au')",
			    "id" => "showau")),
	"&nbsp;", tagg_label("Authors");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='auloadformresult'></div>";
}
if ($Conf->blindSubmission() >= BLIND_OPTIONAL && $Me->privChair) {
    echo $sep,
	tagg_checkbox("showanonau", 1, strpos($pldisplay, " anonau ") !== false,
		      array("disabled" => !($pl->headerInfo["authors"] & 2),
			    "onchange" => "foldplinfo(this,'anonau')",
			    "id" => ($Conf->blindSubmission() == BLIND_OPTIONAL ? "showanonau" : "showau"))),
	"&nbsp;", tagg_label($Conf->blindSubmission() == BLIND_OPTIONAL ? "Anonymous authors" : "Authors");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='anonauloadformresult'></div>";
}
if ($Conf->blindSubmission() <= BLIND_OPTIONAL || $Me->privChair) {
    echo "<span class='fx10'>", $sep,
	tagg_checkbox("showaufull", 1, strpos($pldisplay, " aufull ") !== false,
		      array("onchange" => "foldplinfo(this,'aufull')")),
	"&nbsp;", tagg_label("Full author info"), "</span>";
    $Conf->footerScript("function foldplinfo_extra(type,dofold){var x=(type=='au'?!dofold:(\$\$('showau')||{}).checked),y=(type=='anonau'?!dofold:(\$\$('showanonau')||{}).checked);fold('redisplayform',!(x||y),10)}");
    $loadforms .= "<div id='aufullloadformresult'></div>";
}
if ($pl->headerInfo["abstract"]) {
    echo $sep,
	tagg_checkbox("showabstract", 1, strpos($pldisplay, " abstract ") !== false,
		      array("onchange" => "foldplinfo(this,'abstract')")),
	"&nbsp;", tagg_label("Abstracts");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='abstractloadformresult'></div>";
}
if ($pl->headerInfo["topics"]) {
    echo $sep,
	tagg_checkbox("showtopics", 1, strpos($pldisplay, " topics ") !== false,
		      array("onchange" => "foldplinfo(this,'topics')")),
	"&nbsp;", tagg_label("Topics");
    $sep = "<span class='sep'></span>\n";
    $loadforms .= "<div id='topicsloadformresult'></div>";
}
if ($loadforms)
     echo "<br />", $loadforms;
echo "</td></tr>\n</table></form>"; // </div></div>
echo "</td></tr></table>\n";


// ajax preferences form
echo "<form id='prefform' method='post' action=\"paper$ConfSiteSuffix\" enctype='multipart/form-data' accept-charset='UTF-8'><div>",
    "<input type='hidden' name='setrevpref' value='1' />",
    "<input type='hidden' name='p' value='' />",
    "<input type='hidden' name='revpref' value='' />";
if ($Me->privChair)
    echo "<input type='hidden' name='contactId' value='$reviewer' />";
echo "</div></form>\n\n";


// main form
echo "<form class='assignpc' method='post' action=\"reviewprefs$ConfSiteSuffix?reviewer=$reviewer",
    (defval($_REQUEST, "q") ? "&amp;q=" . urlencode($_REQUEST["q"]) : ""),
    "&amp;post=1\" enctype='multipart/form-data' accept-charset='UTF-8'>",
    "<div class='inform'>",
    "<input id='defaultact' type='hidden' name='defaultact' value='' />",
    "<input class='hidden' type='submit' name='default' value='1' />",
    "<div class='pltable_full_ctr'>\n",
    $pl_text,
    "</div>";
// echo "<table class='center'><tr><td><input class='hb' type='submit' name='update' value='Save preferences' /></td></tr></table>\n";
echo "</div></form>\n";

$Conf->footer();
