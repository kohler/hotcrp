<?php
// manualassign.php -- HotCRP chair's paper assignment page
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');
$Me->goIfInvalid();
$Me->goIfNotPrivChair();

// paper selection
if (!isset($_REQUEST["q"]) || trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = "s";

$kind = defval($_REQUEST, "kind", "a");
if ($kind != "a" && $kind != "c")
    $kind = "a";
if (!isset($_REQUEST["p"]) && isset($_REQUEST["pap"]))
    $_REQUEST["p"] = $_REQUEST["pap"];
if (isset($_REQUEST["p"]) && is_string($_REQUEST["p"]))
    $_REQUEST["p"] = preg_split('/\s+/', $_REQUEST["p"]);
if (isset($_REQUEST["p"]) && is_array($_REQUEST["p"]) && $kind == "c") {
    foreach ($_REQUEST["p"] as $p)
	if (($p = cvtint($p)) > 0)
	    $_REQUEST["assrev$p"] = -1;
}
if (isset($_REQUEST["papx"]) && is_string($_REQUEST["papx"]))
    $_REQUEST["papx"] = preg_split('/\s+/', $_REQUEST["papx"]);
if (isset($_REQUEST["papx"]) && is_array($_REQUEST["papx"])) {
    foreach ($_REQUEST["papx"] as $p)
	if (($p = cvtint($p)) > 0 && !isset($_REQUEST["assrev$p"]))
	    $_REQUEST["assrev$p"] = 0;
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
$abar .= actionTab("Automatic", hoturl("autoassign"), false);
$abar .= actionTab("Manual", hoturl("manualassign"), true);
$abar .= actionTab("Upload", hoturl("bulkassign"), false);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";


$Conf->header("Review Assignments", "assignpc", $abar);


$reviewer = rcvtint($_REQUEST["reviewer"]);
if ($reviewer <= 0)
    $reviewer = $Me->contactId;


function saveAssignments($reviewer) {
    global $Conf, $Me, $kind;

    $while = "while saving review assignments";
    $result = $Conf->qe("lock tables Paper read, PaperReview write, PaperConflict write" . $Conf->tagRoundLocker($kind == "a"), $while);
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
	if ($row->paperId == $lastPaperId
	    || $row->conflictType >= CONFLICT_AUTHOR
	    || !isset($_REQUEST["assrev$row->paperId"]))
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
    $Conf->updateRevTokensSetting(false);
}


if (isset($_REQUEST["update"]) && $reviewer > 0)
    saveAssignments($reviewer);
else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You need to select a reviewer.");


// Help list
echo "<div class='helpside'><div class='helpinside'>
Assignment methods:
<ul><li><a href='", hoturl("autoassign"), "'>Automatic</a></li>
 <li><a href='", hoturl("manualassign"), "' class='q'><strong>Manual by PC member</strong></a></li>
 <li><a href='", hoturl("assign"), "'>Manual by paper</a></li>
 <li><a href='", hoturl("bulkassign"), "'>Upload</a></li>
</ul>
<hr class='hr' />\n";
if ($kind == "a")
    echo "Types of PC assignment:
<dl><dt><img class='ass", REVIEW_PRIMARY, "' src='images/_.gif' alt='Primary' /> Primary</dt><dd>Expected to review the paper themselves</dd>
  <dt><img class='ass", REVIEW_SECONDARY, "' src='images/_.gif' alt='Secondary' /> Secondary</dt><dd>May delegate to external reviewers</dd></dl>
<hr class='hr' />\n";
echo "<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>\n";
if ($kind == "a")
    echo "<dt>Preference</dt><dd><a href='", hoturl("reviewprefs"), "'>Review preference</a></dd>
  <dt>Topic score</dt><dd>+2 for each high interest paper topic, &minus;1 for each low interest paper topic</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd>\n";
echo "</dl>\nClick a heading to sort.\n</div></div>";


$pcm = pcMembers();
if ($reviewer > 0)
    echo "<h2 style='margin-top:1em'>Assignments for ", contactNameHtml($pcm[$reviewer]), ($pcm[$reviewer]->affiliation ? " (" . htmlspecialchars($pcm[$reviewer]->affiliation) . ")" : ""), "</h2>\n";
else
    echo "<h2 style='margin-top:1em'>Assignments by PC member</h2>\n";


// Change PC member
echo "<table><tr><td><div class='aahc assignpc_pcsel'><form method='get' action='", hoturl("manualassign"), "' accept-charset='UTF-8' id='selectreviewerform'><div class='inform'>\n";

$query = "select ContactInfo.contactId, firstName, lastName,
		count(reviewId) as reviewCount
		from ContactInfo
		join PCMember using (contactId)
		left join PaperReview on (ContactInfo.contactId=PaperReview.contactId and PaperReview.reviewType>=" . REVIEW_SECONDARY . ")
		group by contactId
		order by lastName, firstName, email";
$result = $Conf->qe($query);
$rev_opt = array();
if ($reviewer <= 0)
    $rev_opt[0] = "(Select a PC member)";
while (($row = edb_orow($result)))
    $rev_opt[$row->contactId] = contactHtml($row) . " ("
	. plural($row->reviewCount, "assignment") . ")";

echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
    "<td>", tagg_select("reviewer", $rev_opt, $reviewer, array("onchange" => "hiliter(this)")), "</td></tr>",
    "<tr><td colspan='2'><div class='g'></div></td></tr>\n";

// Paper selection
$tOpt = array("s" => "Submitted papers",
	      "acc" => "Accepted papers",
	      "und" => "Undecided papers",
	      "all" => "All papers");
if (!isset($_REQUEST["t"]) || !isset($tOpt[$_REQUEST["t"]]))
    $_REQUEST["t"] = "s";
$q = (defval($_REQUEST, "q", "") == "" ? "(All)" : $_REQUEST["q"]);
echo "<tr><td>Paper selection: &nbsp;</td>",
    "<td><input id='manualassignq' class='textlite temptextoff' type='text' size='40' name='q' value=\"", htmlspecialchars($q), "\" onchange='hiliter(this)' title='Enter paper numbers or search terms' /> &nbsp;in &nbsp;",
    tagg_select("t", $tOpt, $_REQUEST["t"], array("onchange" => "hiliter(this)")),
    "</td></tr>\n",
    "<tr><td colspan='2'><div class='g'></div>\n";
$Conf->footerScript("mktemptext('manualassignq','(All)')");

echo tagg_radio("kind", "a", $kind == "a",
	       array("onchange" => "hiliter(this)")),
    "&nbsp;", tagg_label("Assign reviews and/or conflicts"), "<br />\n",
    tagg_radio("kind", "c", $kind == "c",
	       array("onchange" => "hiliter(this)")),
    "&nbsp;", tagg_label("Assign conflicts only (and limit papers to potential conflicts)"), "</td></tr>\n";

if ($kind == "a") {
    echo "<tr><td colspan='2'><div class='g'></div></td></tr>\n",
	"<tr><td>",
	(isset($Error["rev_roundtag"]) ? "<span class='error'>" : ""),
	"Review round: &nbsp;</td>",
	"<td><input id='assrevroundtag' class='textlite temptextoff' type='text' size='15' name='rev_roundtag' value=\"", htmlspecialchars($rev_roundtag ? $rev_roundtag : "(None)"), "\" />",
	(isset($Error["rev_roundtag"]) ? "</span>" : ""),
	" &nbsp;<a class='hint' href='", hoturl("help", "t=revround"), "'>What is this?</a>\n",
	"</td></tr>";
    $Conf->footerScript("mktemptext('assrevroundtag','(None)')");
}

echo "<tr><td colspan='2'><div class='aax' style='text-align:right'>",
    "<input class='bb' type='submit' value='Go' />",
    "</div></td></tr>\n",
    "</table>\n</div></form></div></td></tr></table>\n";


// Current PC member information
if ($reviewer > 0) {
    $col = array(array(), array(), array());

    // Conflict information
    $result = $Conf->qe("select firstName, lastName, affiliation, collaborators from ContactInfo where contactId=$reviewer");
    if ($result && ($row = edb_orow($result))) {
	if ($row->collaborators)
	    $col[1][] = "<div class='f-c'>Collaborators</div><div class='f-e'>"
		. nl2br(htmlspecialchars($row->collaborators))
		. "</div>";

	$useless_words = array("university" => 1, "the" => 1, "and" => 1, "of" => 1, "univ" => 1, "none" => 1, "a" => 1, "an" => 1, "jr" => 1, "sr" => 1, "iii" => 1);

	// search outline from old CRP, done here in a very different way
	preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation), $match);
	$useless = $useless_words;
	$search = array();
	$showco = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$search[] = "co:" . (ctype_alnum($s) ? $s : "\"$s\"");
		$showco .= $s . " ";
		$useless[$s] = 1;
	    }

	preg_match_all('/[a-z&]{2,}/', strtolower($row->firstName . " " . $row->lastName . " " . $row->affiliation . " " . $row->collaborators), $match);
	$useless = $useless_words;
	$showau = "";
	foreach ($match[0] as $s)
	    if (!isset($useless[$s])) {
		$search[] = "au:" . (ctype_alnum($s) ? $s : "\"$s\"");
		$showau .= $s . " ";
		$useless[$s] = 1;
	    }

	$col[2][] = "<div class='f-c'>Conflict search terms for paper authors</div><div class='f-e'>"
	    . htmlspecialchars(substr($showau, 0, strlen($showau) - 1))
	    . "</div>";
	$col[2][] = "<div class='f-c'>Conflict search terms for paper collaborators</div><div class='f-e'>"
	    . htmlspecialchars(substr($showco, 0, strlen($showco) - 1))
	    . "</div>";
	$col[2][] = "<a href=\"" . hoturl("search", "q=" . urlencode(join(" OR ", $search)) . "&amp;linkto=assign") . "\">Search for potential conflicts</a>";
    }

    // Topic links
    $result = $Conf->qe("select topicName, interest from TopicArea join TopicInterest using (topicId) where contactId=$reviewer order by topicName");
    $interest = array();
    while (($row = edb_row($result)))
	$interest[$row[1]][] = htmlspecialchars($row[0]);
    if (isset($interest[2]))
	$col[0][] = "<div class='f-c'>High interest topics</div><div class='f-e'><span class='topic2'>"
	    . join("</span>, <span class='topic2'>", $interest[2])
	    . "</span></div>";
    if (isset($interest[0]))
	$col[0][] = "<div class='f-c'>Low interest topics</div><div class='f-e'><span class='topic0'>"
	    . join("</span>, <span class='topic0'>", $interest[0])
	    . "</span></div>";

    // Table
    if (count($col[0]) || count($col[1]) || count($col[2])) {
	echo "<table><tr>\n";
	foreach ($col as $thecol)
	    if (count($thecol)) {
		echo "<td class='top'><table>";
		foreach ($thecol as $td)
		    echo "<tr><td style='padding:0 2em 1ex 0'>", $td, "</td></tr>";
		echo "</table></td>\n";
	    }
	echo "</tr></table>\n";
    }

    // ajax assignment form
    echo "<form id='assrevform' method='post' action=\"", hoturl("assign", "update=1"), "\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='clear'>",
	"<input type='hidden' name='kind' value='$kind' />",
	"<input type='hidden' name='p' value='' />",
	"<input type='hidden' name='pcs$reviewer' value='' />",
	"<input type='hidden' name='reviewer' value='$reviewer' />",
	"<input type='hidden' name='rev_roundtag' value=\"", htmlspecialchars($rev_roundtag), "\" />",
	"</div></form>\n\n";

    // main assignment form
    $search = new PaperSearch($Me, array("t" => $_REQUEST["t"],
					 "q" => $_REQUEST["q"],
					 "urlbase" => hoturl("manualassign", "reviewer=$reviewer")), $reviewer);
    $paperList = new PaperList($search, array("sort" => true, "list" => true));
    if (isset($showau)) {
	$search->overrideMatchPreg = true;
	$search->matchPreg = array();
	if ($showau)
	    $search->matchPreg["authorInformation"] = "\\b" . str_replace(" ", "\\b|\\b", preg_quote(substr($showau, 0, strlen($showau) - 1))) . "\\b";
	if ($showco)
	    $search->matchPreg["collaborators"] = "\\b" . str_replace(" ", "\\b|\\b", preg_quote(substr($showau, 0, strlen($showco) - 1))) . "\\b";
    }
    $a = isset($_REQUEST["sort"]) ? "&amp;sort=" . urlencode($_REQUEST["sort"]) : "";
    echo "<div class='aahc'><form class='assignpc' method='post' action=\"", hoturl("manualassign", "reviewer=$reviewer&amp;kind=$kind&amp;post=1$a"),
	"\" enctype='multipart/form-data' accept-charset='UTF-8'><div>\n",
	"<input type='hidden' name='t' value='", $_REQUEST["t"], "' />",
	"<input type='hidden' name='q' value=\"", htmlspecialchars($_REQUEST["q"]), "\" />",
	"<input type='hidden' name='papx' value='", join(" ", $search->paperList()), "' />",
	"<div class='aa'><input type='submit' class='bb' name='update' value='Save assignments' />",
	"<span style='padding:0 0 0 2em'>",
	tagg_checkbox(false, false, true, array("id" => "assrevimmediate")),
	"&nbsp;", tagg_label("Automatically save assignments", "assrevimmediate"),
	"</span></div>\n",
	$paperList->text(($kind == "c" ? "conflict" : "reviewAssignment"), $Me, "pltable_full"),
	"<div class='aa'><input type='submit' class='bb' name='update' value='Save assignments' /></div>\n",
	"</div></form></div>\n";
}

echo "<div class='clear'></div>";
$Conf->footer();
