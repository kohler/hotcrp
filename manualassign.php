<?php
// manualassign.php -- HotCRP chair's paper assignment page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("index$ConfSiteSuffix");
$kind = defval($_REQUEST, "kind", "a");
if ($kind != "a" && $kind != "c")
    $kind = "a";
if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if (isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"]) && $kind == "c") {
    foreach ($_REQUEST["pap"] as $p)
	if (($p = cvtint($p)) > 0)
	    $_REQUEST["assrev$p"] = -1;
}

// set review round
if (!isset($_REQUEST["rev_roundtag"]))
    $rev_roundtag = $Conf->settingText("rev_roundtag");
else if (($rev_roundtag = $_REQUEST["rev_roundtag"]) == "(None)")
    $rev_roundtag = "";
$Error = array();
if ($rev_roundtag && !preg_match('/^[a-zA-Z0-9]+$/', $rev_roundtag)) {
    $Error["rev_roundtag"] = true;
    $Conf->errorMsg("The review round must contain only letters and numbers.");
    $rev_roundtag = "";
}
if ($rev_roundtag) {
    $Conf->settings["rev_roundtag"] = 1;
    $Conf->settingTexts["rev_roundtag"] = $rev_roundtag;
} else
    unset($Conf->settings["rev_roundtag"]);


$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Automatic", "autoassign$ConfSiteSuffix", false);
$abar .= actionTab("Manual", "manualassign$ConfSiteSuffix", true);
$abar .= actionTab("Offline", "bulkassign$ConfSiteSuffix", false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "assignpc", $abar);


$reviewer = cvtint($_REQUEST["reviewer"]);
if ($reviewer < 0)
    $reviewer = $Me->contactId;


function saveAssignments($reviewer) {
    global $Conf, $Me, $reviewTypeName, $kind;

    $while = "while saving review assignments";
    $result = $Conf->qe("lock tables Paper read, PaperReview write, PaperConflict write" . $Conf->tagRoundLocker($kind == "a" && ($type == REVIEW_PRIMARY || $type == REVIEW_SECONDARY)), $while);
    if (!$result)
	return $result;

    $result = $Conf->qe("select Paper.paperId, PaperConflict.conflictType,
	reviewId, reviewType, reviewModified
	from Paper
	left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=$reviewer)
	left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=$reviewer)
	where timeSubmitted>0
	order by paperId asc, reviewId asc", $while);

    $lastPaperId = -1;
    $del = $ins = "";
    while (($row = edb_orow($result))) {
	if ($row->paperId == $lastPaperId || $row->conflictType >= CONFLICT_AUTHOR)
	    continue;
	$lastPaperId = $row->paperId;
	$type = cvtint($_REQUEST["assrev$row->paperId"], 0);
	if ($type >= 0 && $row->conflictType > 0 && $row->conflictType < CONFLICT_AUTHOR)
	    $del .= " or paperId=$row->paperId";
	if ($type < 0 && $row->conflictType < CONFLICT_CHAIRMARK)
	    $ins .= ", ($row->paperId, $reviewer, " . CONFLICT_CHAIRMARK . ")";
	if ($kind == "a")
	    $Me->assignPaper($row->paperId, $row, $reviewer, $type, $Conf);
    }

    if ($ins)
	$Conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values " . substr($ins, 2) . " on duplicate key update conflictType=greatest(conflictType,values(conflictType))", $while);
    if ($del)
	$Conf->qe("delete from PaperConflict where contactId=$reviewer and (" . substr($del, 4) . ")", $while);
    
    $Conf->qe("unlock tables", $while);
}


if (isset($_REQUEST["update"]) && $reviewer > 0)
    saveAssignments($reviewer);
else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You need to select a reviewer.");


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='${ConfSiteBase}autoassign$ConfSiteSuffix'>Automatic</a></li>
 <li><a href='${ConfSiteBase}manualassign$ConfSiteSuffix' class='q'><strong>By PC member</strong></a></li>
 <li><a href='${ConfSiteBase}assign$ConfSiteSuffix'>By paper</a></li>
 <li><a href='${ConfSiteBase}bulkassign$ConfSiteSuffix'>Offline (bulk upload)</a></li>
</ul>
<hr />\n";
if ($kind == "a")
    echo "Types of PC assignment:
<dl><dt><img src='${ConfSiteBase}images/ass", REVIEW_PRIMARY, ".png' alt='Primary' /> Primary</dt><dd>Expected to review the paper themselves</dd>
  <dt><img src='${ConfSiteBase}images/ass", REVIEW_SECONDARY, ".png' alt='Secondary' /> Secondary</dt><dd>May delegate to external reviewers</dd></dl>
<hr />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
if ($kind == "a")
    echo "<dt>Preference</dt><dd><a href='${ConfSiteBase}reviewprefs$ConfSiteSuffix'>Review preference</a></dd>
  <dt>Topic score</dt><dd>+2 for every paper topic marked high interest, &minus;1 for every paper topic marked low interest</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>\n";
echo "</dl>\nClick a heading to sort.\n</div></div>";


echo "<form method='get' action='manualassign$ConfSiteSuffix' accept-charset='UTF-8' id='selectreviewerform'>
<table>
  <tr class='id'><td class='caption'></td><td class='entry'></td></tr>
  <tr><td class='caption'>PC member</td><td class='entry'>
<select name='reviewer'>\n";

$query = "select ContactInfo.contactId, firstName, lastName,
		count(reviewId) as reviewCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReview on (ContactInfo.contactId=PaperReview.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
		group by contactId
		order by lastName, firstName, email";
$result = $Conf->qe($query);
if ($reviewer < 0)
    echo "<option value='-1' selected='selected'>(Select a PC member)</option>";
while (($row = edb_orow($result))) {
    echo "<option value='$row->contactId'";
    if ($row->contactId == $reviewer)
	echo " selected='selected'";
    echo ">", contactHtml($row);
    echo " (", plural($row->reviewCount, "assignment"), ")";
    echo "</option>";
}
echo "</select></td></tr>\n";

if ($reviewer >= 0) {
    // Topic links
    $result = $Conf->qe("select topicName, interest from TopicArea join TopicInterest using (topicId) where contactId=$reviewer order by topicName");
    $interest = array();
    while (($row = edb_row($result)))
	$interest[$row[1]][] = htmlspecialchars($row[0]);
    if (isset($interest[2]))
	echo "<tr><td class='caption'>High interest topics</td><td class='entry'><span class='topic2'>", join("</span>, <span class='topic2'>", $interest[2]), "</span></td></tr>\n";
    if (isset($interest[0]))
	echo "<tr><td class='caption'>Low interest topics</td><td class='entry'><span class='topic0'>", join("</span>, <span class='topic0'>", $interest[0]), "</span></td></tr>\n";

    // Conflict information
    $result = $Conf->qe("select firstName, lastName, affiliation, collaborators from ContactInfo where contactId=$reviewer");
    if ($result && ($row = edb_orow($result))) {
	// search outline from old CRP, done here in a very different way
	preg_match_all('/[a-z]{3,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation), $match);
	$useless = array("university" => 1, "the" => 1, "and" => 1, "univ" => 1);
	$sco = $showco = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$sco .= "co:" . $s . " ";
		$showco .= $s . " ";
		$useless[$s] = 1;
	    }

	preg_match_all('/[a-z]{3,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation . " " . $row->collaborators), $match);
	$useless = array("university" => 1, "the" => 1, "and" => 1, "univ" => 1);
	$sau = $showau = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$sau .= "au:" . $s . " ";
		$showau .= $s . " ";
		$useless[$s] = 1;
	    }

	echo "<tr><td class='caption'>Potential conflicts</td><td class='entry top'><table>",
	    "<tr><td class='lxcaption'>Authors</td><td>", htmlspecialchars(substr($showau, 0, strlen($showau) - 1)), "</td></tr>\n",
	    "<tr><td class='lxcaption'>Collaborators</td><td>", htmlspecialchars(substr($showco, 0, strlen($showco) - 1)), "</td></tr>\n",
	    "</table>",
	    "<a href=\"${ConfSiteBase}search$ConfSiteSuffix?q=", urlencode($sco), "+", urlencode($sau), "&amp;qt=ac&amp;linkto=assign\">Search for potential conflicts</a>",
	    "</td></tr>\n";
    }
}


echo "<tr><td class='caption'></td><td class='entry'></div></td></tr>
<tr><td class='caption'>Assignments</td><td class='entry'>",
    "<input type='radio' name='kind' value='a'",
    ($kind == "a" ? " checked='checked'" : ""),
    " />&nbsp;Assign reviews and/or conflicts for all papers<br />\n",
    "<input type='radio' name='kind' value='c'",
    ($kind == "c" ? " checked='checked'" : ""),
    " />&nbsp;Focus on potential conflicts<br />\n";    

if ($kind == "a")
    echo "<div class='xsmgap'></div>",
	(isset($Error["rev_roundtag"]) ? "<span class='error'>" : ""),
	"Review round: &nbsp;",
	"<input id='assrevroundtag' class='textlite' type='text' size='15' name='rev_roundtag' value=\"", htmlspecialchars($rev_roundtag ? $rev_roundtag : "(None)"), "\" onfocus=\"tempText(this, '(None)', 1)\" onblur=\"tempText(this, '(None)', 0)\" />",
	(isset($Error["rev_roundtag"]) ? "</span>" : ""),
	" &nbsp;<span class='hint'><a href='${ConfSiteBase}help$ConfSiteSuffix?t=revround' target='new'>What is this?</a></span>\n";

echo "<div class='xsmgap'></div><input id='assrevimmediate' type='checkbox' checked='checked' />&nbsp;Save assignments as they are made<br />\n";

echo "<div class='xsmgap'></div><input class='button' type='submit' value='Redisplay' /></td></tr>\n";

echo "<tr class='last'><td class='caption'></td><td class='entry'></td></tr>\n";

echo "</table></form>";

if ($reviewer >= 0) {
    // ajax assignment form
    echo "<form id='assrevform' method='post' action=\"${ConfSiteBase}assign$ConfSiteSuffix?update=1\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='clear'>",
	"<input type='hidden' name='kind' value='$kind' />",
	"<input type='hidden' name='p' value='' />",
	"<input type='hidden' name='pcs$reviewer' value='' />",
	"<input type='hidden' name='reviewer' value='$reviewer' />",
	"<input type='hidden' name='rev_roundtag' value=\"", htmlspecialchars($rev_roundtag), "\" />",
	"</div></form>\n\n";


    $paperList = new PaperList(true, true, new PaperSearch($Me, array("t" => "s", "c" => $reviewer, "urlbase" => "manualassign$ConfSiteSuffix?reviewer=$reviewer")));
    if (isset($sau)) {
	$paperList->authorMatch = strtr(substr($showau, 0, strlen($showau) - 1), " ", "|");
	$paperList->collaboratorsMatch = strtr(substr($showco, 0, strlen($showco) - 1), " ", "|");
    }
    echo "<div class='searchresult'><form class='assignpc' method='post' action=\"manualassign$ConfSiteSuffix?reviewer=$reviewer&amp;kind=$kind&amp;post=1";
    if (isset($_REQUEST["sort"]))
	echo "&amp;sort=", urlencode($_REQUEST["sort"]);
    echo "\" enctype='multipart/form-data' accept-charset='UTF-8'><div>\n";
    echo $paperList->text(($kind == "c" ? "conflict" : "reviewAssignment"), $Me);
    //if (isset($sau) && ($paperList->authorMatch || $paperList->collaboratorsMatch))
    //   $_SESSION["matchPreg"] = "/(" . $paperList->authorMatch . ($paperList->authorMatch && $paperList->collaboratorsMatch ? "|" : "") . $paperList->collaboratorsMatch . ")/i";
    echo "<div class='smgap'></div>\n",
	"<table class='center'><tr><td><input class='hbutton' type='submit' name='update' value='Save assignments' /></td></tr></table>\n",
	"</div></form></div>\n";
}

$Conf->footer();
