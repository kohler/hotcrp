<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
	$title = "Paper #$prow->paperId Reviews";
    else
	$title = "Paper Reviews";
    $Conf->header($title, "review", actionBar($prow, false, "review"));
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
	errorMsgExit(whyNotText($whyNot, "view"));

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


// forceShow
if (isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'] && $Me->amAssistant())
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";


// mode
$mode = "view";
if (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit")
    $mode = "edit";
if (!isset($_REQUEST["mode"]) && isset($_REQUEST["reviewId"])
    && $rrow
    && ($rrow->contactId == $Me->contactId
	|| ($Me->amAssistant() && ($prow->conflict <= 0 || $forceShow))))
    $mode = "edit";
if ($mode == "view" && $prow->conflict <= 0
    && !$Me->canViewReview($prow, (isset($_REQUEST["reviewId"]) ? $rrow : null), $Conf, $whyNot)) {
    if (isset($whyNot['reviewNotComplete'])) {
	if (isset($_REQUEST["mode"]) || isset($whyNot['forceShow']))
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
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
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
function reviewersTable() {
    global $ConfSiteBase, $Conf, $Me, $mode, $prow, $rrows, $rrow, $rf, $forceShow;
    
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $foundNonsub = 0;
    $actingConflict = ($prow->conflict > 0 && !$forceShow);
    $anyScores = false;

    // actual rows
    foreach ($rrows as $rr) {
	$highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
	$foundRrow += $highlight;
	$foundMyReview += ($rr->contactId == $Me->contactId);
	$foundNonsub += ($rr->reviewSubmitted <= 0);
	$canView = $Me->canViewReview($prow, $rr, $Conf);

	// skip unsubmitted reviews
	if (!$canView && $actingConflict)
	    continue;

	$t = "    <tr>";

	// dingbat
	if ($rrow || $mode == "edit")
	    $t .= "<td class='highlight'>" . ($highlight ? "<b>&#187;</b>" : "") . "</td>";

	// review ID
	$id = "Review";
	if ($rr->reviewSubmitted > 0)
	    $id .= "&nbsp;#" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><b>$id</b></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='review.php?reviewId=$rr->reviewId$forceShow'>$id</a></td>";

	// reviewer identity
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf))
	    $t .= "<td class='empty'></td>";
	else if ($rr->contactId == $Me->contactId)
	    $t .= "<td>You</td>";
	else
	    $t .= "<td>" . contactText($rr) . "</td>";

	// review type
	if ($prow->author > 0 || $prow->conflict > 0)
	    $t .= "<td class='empty'></td>";
	else if ($rr->reviewType == REVIEW_PRIMARY)
	    $t .= "<td>Primary</td>";
	else if ($rr->reviewType == REVIEW_SECONDARY)
	    $t .= "<td>Secondary</td>";
	else if ($rr->reviewType == REVIEW_REQUESTED)
	    $t .= "<td>Requested<br /><small>by " . ($rr->reqContactId == $Me->contactId ? "you" : contactText($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail)) . "</small></td>";

	// status
	if ($rr->reviewModified <= 0)
	    $t .= "<td>Not started</td>";
	else if ($rr->reviewSubmitted <= 0)
	    $t .= "<td>In progress</td>";
	else
	    $t .= "<td class='empty'></td>";

	// scores
	$t .= $rf->webNumericScoresRow($rr, $prow, $Me, $Conf, $anyScores);

	// actions
	$actions = array();
	if ($rr->reviewType == REVIEW_REQUESTED
	    && $rr->reviewModified <= 0
	    && ($rr->requestedBy == $Me->contactId || $Me->amAssistant())) {
	    $actions[] = "<a class='button_small' href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId&amp;retract=$rr->reviewId$forceShow\">Retract review request</a>";
	    $nRetractable++;
	}
	if (count($actions) > 0 && $buttons)
	    $t .= "<td>" . join("", $actions) . "</td>";

	$t .= "</tr>\n";

	// affix
	if ($rr->reviewSubmitted <= 0)
	    $nonsubrev[] = $t;
	else
	    $subrev[] = $t;
    }

    // bottom links
    // edit your own review
    if ($mode == "edit" && !$rrow)
	$nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Enter&nbsp;review</b></td><td>You</td><td class='empty'></td><td>Not started</td></tr>\n";
    else if ($mode == "edit" && !$foundMyReview && $Me->canReview($prow, null, $Conf))
	$nonsubrev[] = "    <tr><td></td><td><a href='review.php?paperId=$prow->paperId&amp;mode=edit$forceShow'>Enter&nbsp;review</a></td><td>You</td><td class='empty'></td><td>Not started</td></tr>\n";

    // headers
    $numericHeaders = "";
    if ($anyScores) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$numericHeaders = "    <tr>$t<td class='empty' colspan='4'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    }
    
    // see all reviews
    if (($mode == "view" && isset($_REQUEST['reviewId']) && $Me->canViewReview($prow, null, $Conf))
	|| ($mode == "edit" && $Me->canViewReview($prow, null, $Conf))) {
	$t = "    <tr>" . ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "") . "<td colspan='";
	$t .= ($anyScores ? 4 + $rf->numNumericScores($prow, $Me, $Conf) : 4);
	$t .= "'><a href='review.php?paperId=$prow->paperId&amp;mode=view$forceShow'>View all reviews on one page</a></td></tr>\n";
	$nonsubrev[] = $t;
    }
    
    // completion
    if (count($nonsubrev) || count($subrev))
	$result = "<table class='reviewers'>\n" . $numericHeaders
		     . join("", $subrev) . join("", $nonsubrev)
		     . "  </table>\n";
    else
	$result = "";

    // unfinished review notification
    $notes = array();
    if ($prow->author > 0 && !$forceShow && $foundNonsub) {
	if ($foundNonsub == 1)
	    $t = "1 additional review was requested, but has not been submitted yet.  ";
	else
	    $t = "$foundNonsub additional reviews were requested, but have not been submitted yet.  ";
	$t .= "You will be emailed when additional reviews are submitted and if any existing reviews are changed.";
	$notes[] = $t;
    }

    // forceShow
    if ($prow->conflict > 0 && $Me->amAssistant() && !$forceShow)
	$notes[] = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\"><b>Override your conflict</b> to show reviewer identities and allow editing</a>";

    if (count($notes))
	$result .= "<div class='reviewersbot'>" . join("<br />\n", $notes) . "</div>";

    return $result;
}
// <table class='reviewers'>\n", $revTable, "  </table></td>\n";

// reviewer information
$revTable = reviewersTable($prow, (isset($rrow) ? $rrow->reviewId : -1));
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>Reviews</td>\n";
echo "  <td class='entry'>", ($revTable ? $revTable : "None"), "</td>\n";
echo "</tr>\n\n";


if ($mode == "view" && $Me->canSetOutcome($prow))
    $paperTable->echoOutcomeSelector($prow);


// extra space
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n\n";


// exit on certain errors
if ($mode == "view" && !$Me->canViewReview($prow, (isset($_REQUEST["reviewId"]) ? $rrow : null), $Conf)) {
    echo "<div class='gapbottom'></div>\n";
    errorMsgExit("");
}


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", htmlspecialchars(contactText($rrow)), "</td>"

function reviewView($prow, $rrow, $editMode) {
    global $Conf, $Me, $rf, $forceShow;
    
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
	echo "$forceShow&amp;post=1' method='post' enctype='multipart/form-data'>\n";
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
