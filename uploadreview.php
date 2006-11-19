<?php 
require_once('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// general error messages
if (defval($_REQUEST["post"]) && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// download blank review form action
if (isset($_REQUEST['downloadForm'])) {
    $text = $rf->textFormHeader($Conf, false)
	. $rf->textForm(null, null, $Conf, null, ReviewForm::REV_FORM) . "\n";
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
		if (!MDB2::isError($result))
		    $tf['confirm'][] = (isset($req['submit']) ? "Submitted" : "Uploaded") . " review for paper #$prow->paperId.";
	    }
	} else
	    $tf['err'][] = $tf['firstLineno'] . ": " . whyNotText($whyNot, "review");
    }
    $rf->textFormMessages($tf, $Conf);
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


$Conf->header("Offline Reviewing", 'offrev');

$pastDeadline = !$Conf->timeReviewPaper($Me->isPC, true, true);

if ($Me->amReviewer()) {
    if ($pastDeadline)
	$Conf->infoMsg("The <a href='deadlines.php'>deadline</a> for submitting reviews has passed.");
    else
	$Conf->infoMsg("Use this site to download a blank review form, or to upload a review form you've already filled out.");
} else
    $Conf->infoMsg("You aren't registered as a reviewer or PC member for this conference, but for your information, you may download the review form anyway.");

echo "<table>
<tr class='pt_actions'>
  <td class='form_entry'><form method='get' action='uploadreview.php'><input class='button_default' type='submit' name='downloadForm' value='Download review form' /></form></td>\n\n";

if ($Me->amReviewer()) {
    $disabled = ($pastDeadline && !$Me->amAssistant() ? " disabled='disabled'" : "");
    echo "  <td class='form_entry' id='upload'><table class='compact'>
    <tr>
      <td><form action='uploadreview.php?post=1' method='post' enctype='multipart/form-data'>
	<input type='hidden' name='redirect' value='offline' />
	<input type='file' name='uploadedFile' accept='text/plain' size='30' $disabled/>&nbsp;<input class='button_default' type='submit' value='Upload filled-out review form' name='uploadForm' $disabled/>
      </form></td>
    </tr>\n";
    if ($pastDeadline && $Me->amAssistant())
	echo "    <tr>
      <td><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>
    </tr>\n";
    echo "  </table></td>\n";
}

echo "</tr>\n</table>\n\n";

if (($text = $rf->webGuidanceRows($Me->amReviewer())))
    echo "<hr/>\n\n<table>\n<tr class='id'>\n  <td class='caption'></td>\n  <td class='entry'><h3>Review form guidance</h3></td>\n</tr>\n", $text, "</table>\n";
$Conf->footer();
?>
