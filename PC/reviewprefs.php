<?php
// PC/reviewprefs.php -- HotCRP review preference global settings page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('../Code/header.inc');
require_once('../Code/paperlist.inc');
require_once('../Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');
$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0 || !$Me->privChair)
    $reviewer = $Me->contactId;

$Conf->header("Review Preferences", "revpref", actionBar());

function savePreferences($reviewer) {
    global $Conf, $Me, $reviewTypeName;

    $setting = array();
    $error = false;
    $pmax = 0;
    foreach ($_REQUEST as $k => $v)
	if ($k[0] == 'r' && substr($k, 0, 7) == "revpref"
	    && ($p = cvtint(substr($k, 7))) > 0) {
	    if (($v = cvtpref($v)) >= -1000000 && $v <= 1000000) {
		if ($v != 0)
		    $setting[$p] = $v;
		$pmax = max($pmax, $p);
	    } else
		$error = true;
	}

    if ($error)
	$Conf->errorMsg("Bad preference setting.");
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
}
if (isset($_REQUEST["update"]))
    savePreferences($reviewer);


$rf = reviewForm();
if (count($rf->topicName))
    $topicnote = " and their topics.  You have high
interest in <span class='topic2'>bold topics</span>, and low interest in <span
class='topic0'>grey topics</span>.  \"Topic score\" is higher the more you
are interested in the paper's topics";
else
    $topicnote = "";

$Conf->infoMsg("<p>Review preferences are integers.
The higher your preference, the more you want to review a paper.
0 means you don't care either way; use negative numbers for papers you don't want to review, and &minus;100 or less for conflicts.
Multiple papers can have the same preference.
The system will try to assign your reviews in preference order.</p>

<p>The list shows all submitted papers$topicnote.
Click on a column heading to sort by
that column.  You may also enter preferences on the paper pages, which 
show abstracts and other information.  Access a paper page by clicking
the paper title.</p>");


// set options to view
if (isset($_REQUEST["redisplay"])) {
    $_SESSION["foldplau"] = !defval($_REQUEST, "showau", 0);
    $_SESSION["foldplanonau"] = !defval($_REQUEST, "showanonau", 0);
    $_SESSION["foldplabstract"] = !defval($_REQUEST, "showabstract", 0);
}


// search
$searchType = ($Conf->setting("pc_seeall") > 0 ? "all" : "s");
$pl = new PaperList(true, true, new PaperSearch($Me, array("t" => $searchType, "c" => $reviewer, "urlbase" => "PC/reviewprefs.php?reviewer=$reviewer")));
$pl->showHeader = PaperList::HEADER_TITLES;
$pl_text = $pl->text("editReviewPreference", $Me);


// DISPLAY OPTIONS
echo "<table id='searchform' class='tablinks1'>
<tr><td>"; // <div class='tlx'><div class='tld1'>";

echo "<form method='get' action='reviewprefs.php' id='redisplayform'>\n<table>";
$redisplayButton = "<td><input class='button' type='submit' name='redisplay' value='Redisplay' /></td>";

if ($Me->privChair) {
    echo "<tr><td><strong>Preferences:</strong> &nbsp;</td><td class='pad'>",
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
    echo "</select><div class='smgap'></div></td>", $redisplayButton, "</tr>\n";
    $redisplayButton = "";
}

echo "<tr><td><strong>Show:</strong> &nbsp;</td><td class='pad'>";
if ($Conf->blindSubmission() <= 1) {
    echo "<input type='checkbox' name='showau' value='1'";
    if ($Conf->blindSubmission() == 1 && !($pl->headerInfo["authors"] & 1))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,1)' />&nbsp;Authors<br />\n";
}
if ($Conf->blindSubmission() >= 1 && $Me->privChair) {
    echo "<input type='checkbox' name='showanonau' value='1'";
    if (!($pl->headerInfo["authors"] & 2))
	echo " disabled='disabled'";
    if (defval($_SESSION, "foldplanonau", 1) == 0)
	echo " checked='checked'";
    echo " onclick='fold(\"pl\",!this.checked,2)' />&nbsp;",
	($Conf->blindSubmission() == 1 ? "Anonymous authors" : "Authors"),
	"<br />\n";
}
if ($pl->headerInfo["abstracts"]) {
    echo "<input type='checkbox' name='showabstract' value='1'";
    if (defval($_SESSION, "foldplabstract", 1) == 0)
	echo " checked='checked'";
    echo " onclick='foldabstract(\"pl\",!this.checked,5)' />&nbsp;Abstracts<br />\n";
}
echo "</td>", $redisplayButton, "</tr>\n";
echo "</table></form>"; // </div></div>
echo "</td></tr></table>\n";


// ajax preferences form
echo "<form id='prefform' method='post' action=\"${ConfSiteBase}paper.php\" enctype='multipart/form-data'><div>",
    "<input type='hidden' name='paperId' value='' />",
    "<input type='hidden' name='revpref' value='' />";
if ($Me->privChair)
    echo "<input type='hidden' name='contactId' value='$reviewer' />";
echo "</div></form>\n\n";


// main form
echo "<form class='assignpc' method='post' action=\"reviewprefs.php?reviewer=$reviewer&amp;post=1\" enctype='multipart/form-data'>\n";
echo $pl_text;
echo "<div class='smgap'></div><table class='center'><tr><td><input class='hbutton' type='submit' name='update' value='Save preferences' /></td></tr></table>\n";
echo "</form>\n";

$Conf->footer();
