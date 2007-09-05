<?php 
// offline.php -- HotCRP offline review management page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// general error messages
if (defval($_REQUEST["post"]) && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// download blank review form action
if (isset($_REQUEST['downloadForm'])) {
    $text = $rf->textFormHeader($Conf, "blank")
	. $rf->textForm(null, null, $Me, $Conf, null) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review.txt", "review form");
    exit;
}


// upload review form action
if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    while (($req = $rf->parseTextForm($tf, $Conf))) {
	if (($prow = $Conf->paperRow($req['paperId'], $Me->contactId, $whyNot))
	    && $Me->canSubmitReview($prow, null, $Conf, $whyNot)) {
	    $rrow = $Conf->reviewRow(array('paperId' => $prow->paperId, 'contactId' => $Me->contactId));
	    if ($rf->checkRequestFields($req, $rrow, $tf)) {
		$result = $rf->saveRequest($req, $rrow, $prow, $Me->contactId);
		if ($result)
		    $tf['confirm'][] = (isset($req['submit']) ? "Submitted" : "Uploaded") . " review for paper #$prow->paperId.";
	    }
	} else
	    $rf->tfError($tf, whyNotText($whyNot, "review"));
    }
    $rf->textFormMessages($tf, $Conf);
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


$pastDeadline = !$Conf->timeReviewPaper($Me->isPC, true, true);

if ($pastDeadline && !$Conf->settingsAfter("rev_open") && !$Me->privChair) {
    $Conf->errorMsg("The site is not yet open for review.");
    $Me->go("index.php");
}

$Conf->header("Offline Reviewing", 'offrev', actionBar());

if ($Me->amReviewer()) {
    if ($pastDeadline && !$Conf->settingsAfter("rev_open"))
	$Conf->infoMsg("The site is not yet open for review.");
    else if ($pastDeadline)
	$Conf->infoMsg("The <a href='deadlines.php'>deadline</a> for submitting reviews has passed.");
    else
	$Conf->infoMsg("Use this page to download a blank review form, or to upload a review form you've already filled out.");
} else
    $Conf->infoMsg("You aren't registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");

echo "<table id='offlineform'><tr>
<td><h3>Download forms</h3>
<table>";
$formcaption = "Get forms for: &nbsp;";
if ($Me->amReviewer()) {
    echo "<tr><td>Get forms for: &nbsp;</td><td><a href='${ConfSiteBase}search.php?get=revform&amp;q=&amp;t=r&amp;pap=all'>My reviews</a></td></tr>\n";
    if ($Me->reviewsOutstanding)
	echo "<tr><td></td><td><a href='${ConfSiteBase}search.php?get=revform&amp;q=&amp;t=rout&amp;pap=all'>My incomplete reviews</a></td></tr>\n";
    echo "<tr><td></td><td><a href='offline.php?downloadForm=1'>Blank form</a></td></tr>\n";
} else
    echo "<tr><td><a href='offline.php?downloadForm=1'>Get blank form</a></td></tr>\n";
echo "</table></td>\n";
if ($Me->amReviewer()) {
    $disabled = ($pastDeadline && !$Me->privChair ? " disabled='disabled'" : "");
    echo "<td><h3>Upload filled-out forms</h3>
<form action='offline.php?post=1' method='post' enctype='multipart/form-data'><div class='inform'>
	<input type='hidden' name='redirect' value='offline' />
	<input type='file' name='uploadedFile' accept='text/plain' size='30' $disabled/>&nbsp; <input class='button' type='submit' value='Upload' name='uploadForm' $disabled/>";
    if ($pastDeadline && $Me->privChair)
	echo "<br /><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines";
    echo "</div></form></td>\n";
}
echo "</tr></table>\n";

if (($text = $rf->webGuidanceRows($Me->amReviewer())))
    echo "<hr />\n\n<table>\n<tr class='id'>\n  <td class='caption'></td>\n  <td class='entry'><h3>Review form guidance</h3></td>\n</tr>\n", $text, "<tr class='last'><td class='caption'></td><td class='entry'></td></tr></table>\n";
$Conf->footer();
