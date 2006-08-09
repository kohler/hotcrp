<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// header
function confHeader() {
    global $paperId, $prow, $mode, $Conf;
    if ($paperId > 0)
	$title = "Paper #$paperId Reviews";
    else
	$title = "Paper Reviews";
    $Conf->header($title, "review", actionBar($prow, false, "editreview"));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $ConfSiteBase, $prow, $rrows, $rrow;
    if (isset($_REQUEST["reviewId"]))
	$sel = array("reviewId" => $_REQUEST["reviewId"]);
    else if (isset($_REQUEST["paperId"]))
	$sel = array("paperId" => $_REQUEST["paperId"]);
    else
	errorMsgExit("Select a paper ID above, or <a href='${ConfSiteBase}list.php'>list the papers you can view</a>.");
    if (!(($prow = $Conf->paperRow($sel, $Me->contactId, $whyNot))
	  && $Me->canViewPaper($prow, $Conf, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "review"));

    $rrows = $Conf->reviewRow(array('paperId' => $prow->paperId, 'array' => 1), $whyNot);
    $rrow = null;
    foreach ($rrows as $rr)
	if (isset($_REQUEST['reviewId']) ? $rr->reviewId == $_REQUEST['reviewId'] : $rr->contactId == $Me->contactId)
	    $rrow = $rr;
    if (isset($_REQUEST['reviewId']) && !$rrow)
	errorMsgExit("That review no longer exists.");
}
loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// upload review form action
if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    // parse form, store reviews
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);

    if (!($req = $rf->parseTextForm($tf, $Conf)))
	/* error already reported */;
    else if ($req['paperId'] != $prow->paperId)
	$tf['err'][] = $tf['firstLineno'] . ": This review form is for paper #" . $req['paperId'] . ", not paper #$prow->paperId; did you mean to upload it here?  I have ignored the form.  <a class='button_small' href='${ConfSiteBase}review.php?paperId=" . $req['paperId'] . "'>Review paper #" . $req['paperId'] . "</a> <a class='button_small' href='${ConfSiteBase}uploadreview.php'>General review upload site</a>";
    else if (!$Me->canSubmitReview($prow, $rrow, $Conf, $whyNot))
	$tf['err'][] = $tf['firstLineno'] . ": " . whyNotText($whyNot, "review");
    else if ($rf->checkRequestFields($req, $rrow, $tf)) {
	$result = $rf->saveRequest($req, $rrow, $prow, $Me->contactId);
	if (!DB::isError($result))
	    $tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
    }

    if (count($tf['err']) == 0 && $rf->parseTextForm($tf, $Conf))
	$tf['err'][] = $tf['firstLineno'] . ": Only the first review form in the file was parsed.  <a href='${ConfSiteBase}uploadreview.php'>Upload a file with multiple reviews</a>";

    $rf->textFormMessages($tf, $Conf);
    loadRows();
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


// update review action
if (isset($_REQUEST['update']) || isset($_REQUEST['submit']))
    if (!$Me->canSubmitReview($prow, $rrow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "review"));
    else if ($rf->checkRequestFields($_REQUEST, $rrow)) {
	$result = $rf->saveRequest($_REQUEST, $rrow, $prow, $Me->contactId);
	if (!DB::isError($result))
	    $Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	loadRows();
    }


// download review form action
function downloadForm() {
    global $rf, $Conf, $Me, $prow, $rrow, $rrows;
    if (!$Me->canViewReview($prow, $rrow, $Conf, $whyNot))
	return $Conf->errorMsg(whyNotText($whyNot, "review"));
    $text = $rf->textFormHeader($Conf)
	. $rf->textForm($prow, $rrow, $Conf,
			($prow->reviewType > 0 ? $_REQUEST : null),
			$Me->canViewAllReviewFields($prow, $Conf)) . "\n";
    downloadText($text, $Conf->downloadPrefix . "review-" . $prow->paperId . ".txt", "review form");
    exit;
}
if (isset($_REQUEST['downloadForm']))
    downloadForm();


// mode
$mode = "view";
if (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit")
    $mode = "edit";
if (!isset($_REQUEST["mode"]) && isset($_REQUEST["reviewId"])
    && $rrow && $rrow->contactId == $Me->contactId)
    $mode = "edit";
if ($mode == "view"
    && !$Me->canViewReview($prow, (isset($_REQUEST["reviewId"]) ? $rrow : null), $Conf, $whyNot)) {
    if (isset($whyNot['reviewNotComplete'])) {
	if (isset($_REQUEST["mode"]) || $whyNot['forceShow'])
	    $Conf->infoMsg(whyNotText($whyNot, "review"));
    } else
	errorMsgExit(whyNotText($whyNot, "review"));
    $mode = "edit";
    $rrow = null;
    foreach ($rrows as $rr)
	if ($rr->contactId == $Me->contactId)
	    $rrow = $rr;
}
if ($mode == "edit" && !$Me->canReview($prow, $rrow, $Conf, $whyNot)) {
    $Conf->errorMsg(whyNotText($whyNot, "review"));
    $mode = "view";
}


// page header
confHeader();


// messages for review viewers
if ($mode == "edit" && !$Me->timeReview($prow, $Conf))
    $Conf->infoMsg("The <a href='${ConfSiteBase}deadlines.php'>deadline</a> for modifying this review has passed.");
if ($mode == "edit" && $prow->reviewType <= 0)
    $Conf->infoMsg("You haven't been assigned to review this paper, but you can review it anyway.");


// begin table
echo "<table class='paper'>\n\n";


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, true);
$paperTable = new PaperTable(false, false, true, !$canViewAuthors && $Me->amAssistant());

$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD);
$paperTable->echoAbstractRow($prow);
if ($canViewAuthors || $Me->amAssistant()) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoTopics($prow);
if ($Me->amAssistant())
    $paperTable->echoPCConflicts($prow);


// reviewer information
if (($revTable = reviewersTable($prow, (isset($rrow) ? $rrow->reviewId : -1)))) {
    echo "<tr class='rev_reviewers'>\n";
    echo "  <td class='caption'>Reviewers</td>\n";
    echo "  <td class='entry'><table class='reviewers'>\n", $revTable, "  </table></td>\n";
    echo "</tr>\n\n";
}


if ($mode == "view" && $Me->canSetOutcome($prow))
    $paperTable->echoOutcomeSelector($prow);


// extra space
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n\n";


//if ($rrow && $rrow->reviewSubmitted > 0)
//    echo unparseReviewOrdinal($rrow->reviewOrdinal);
//else
//    echo "x";
//echo "</h2></td>\n";


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", htmlspecialchars(contactText($rrow)), "</td>"

function reviewView($prow, $rrow, $editMode) {
    global $Conf, $Me, $rf;
    
    echo "<div class='gap'></div>

<table class='rev'>
  <tr class='id'>
    <td class='caption'><h3";
    if ($rrow)
	echo " id='review$rrow->reviewId'";
    echo ">Review";
    if ($rrow && $rrow->reviewSubmitted)
	echo "&nbsp;#", $prow->paperId, unparseReviewOrdinal($rrow->reviewOrdinal);
    echo "</h3></td>
    <td class='entry' entry='3'></td>
  </tr>

  <tr class='rev_rev'>
    <td class='caption'></td>
    <td class='entry'>";
    if ($editMode && $rrow && $rrow->contactId != $Me->contactId)
	$Conf->infoMsg("You aren't the author of this review, but you can still make changes as PC Chair.");
    echo "<form class='downloadreviewform' action='review.php' method='get'>",
	"<input type='hidden' name='paperId' value='$prow->paperId' />";
    if ($rrow)
	echo "<input type='hidden' name='reviewId' value='$rrow->reviewId' />";
    echo "<input class='button_small' type='submit' value='Download", ($editMode ? " form" : ""), "' name='downloadForm' id='downloadForm' />",
	"</form>";

    if ($editMode) {
	echo "<form class='downloadreviewform' action='review.php?post=1&amp;paperId=$prow->paperId";
	if ($rrow)
	    echo "&amp;reviewId=$rrow->reviewId";
	echo "' method='post' enctype='multipart/form-data'>",
	    "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_small' type='submit' value='Upload form' name='uploadForm' />",
	    "</form>";
    }
    
    echo "</td>
  </tr>
</table>\n";

    if ($editMode) {
	// start review form
	echo "<form action='review.php?";
	if (isset($rrow))
	    echo "reviewId=$rrow->reviewId";
	else
	    echo "paperId=$prow->paperId";
	if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'])
	    echo "&amp;forceShow=1";
	echo "&amp;post=1' method='post' enctype='multipart/form-data'>\n";
	echo "<table class='reviewform'>\n";

	// form body
	echo $rf->webFormRows($rrow, 1);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
	    if (!$rrow || !$rrow->reviewSubmitted) {
		echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save changes' name='update' /></td>
      <td class='ptb_button'><input class='button_default' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review)</td>\n";
	    } else
		echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>\n";
	    if (!$Me->timeReview($prow, $Conf))
		echo "    </tr>\n    <tr>\n      <td colspan='3'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<table class='review'>\n";
	echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
	echo "</table>\n";
    }
    
}


if ($mode == "view" && !isset($_REQUEST["reviewId"])) {
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted > 0)
	    reviewView($prow, $rr, false);
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted <= 0 && $rr->reviewModified > 0)
	    reviewView($prow, $rr, false);
} else
    reviewView($prow, $rrow, $mode == "edit");


echo "<div class='gapbottom'></div>\n";

$Conf->footer(); ?>
