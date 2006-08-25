<?php 
require_once('Code/confHeader.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
	$title = "Paper #$prow->paperId Reviews";
    else
	$title = "Paper Reviews";
    $Conf->header($title, "review", actionBar($prow, false, "review"), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $ConfSiteBase, $prow, $rrows, $rrow, $editRrow;
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
    $rrow = $myRrow = null;
    foreach ($rrows as $rr) {
	if (isset($_REQUEST['reviewId']) && $rr->reviewId == $_REQUEST['reviewId'])
	    $rrow = $rr;
	if ($rr->contactId == $Me->contactId)
	    $myRrow = $rr;
    }
    if (isset($_REQUEST['reviewId']) && !$rrow)
	errorMsgExit("That review no longer exists.");
    $editRrow = ($rrow ? $rrow : $myRrow);
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
    else if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$tf['err'][] = $tf['firstLineno'] . ": " . whyNotText($whyNot, "review");
    else if ($rf->checkRequestFields($req, $editRrow, $tf)) {
	$result = $rf->saveRequest($req, $editRrow, $prow, $Me->contactId);
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
if (isset($_REQUEST['update']) || isset($_REQUEST['submit']) || isset($_REQUEST['unsubmit']))
    if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "review"));
    else if ($rf->checkRequestFields($_REQUEST, $editRrow)) {
	$result = $rf->saveRequest($_REQUEST, $editRrow, $prow, $Me->contactId);
	if (!DB::isError($result))
	    $Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	loadRows();
    } else
	$useRequest = true;


// unsubmit review action
if (isset($_REQUEST['unsubmit']) && $Me->amAssistant())
    if (!$editRrow || $editRrow->reviewSubmitted <= 0)
	$Conf->errorMsg("This review has not been submitted.");
    else {
	$result = $Conf->qe("update PaperReview set reviewSubmitted=0 where reviewId=$editRrow->reviewId", "while unsubmitting review");
	if (!DB::isError($result)) {
	    $Conf->log("Review $editRrow->reviewId for $prow->paperId by $editRrow->contactId unsubmitted", $Me);
	    $Conf->confirmMsg("Unsubmitted review.");
	}
	loadRows();
    }


// delete review action
if (isset($_REQUEST['delete']) && $Me->amAssistant())
    if (!$editRrow)
	$Conf->errorMsg("No review to delete.");
    else {
	archiveReview($editRrow);
	$result = $Conf->qe("delete from PaperReview where reviewId=$editRrow->reviewId", "while deleting review");
	if (!DB::isError($result)) {
	    $Conf->log("Review $editRrow->reviewId for $prow->paperId by $editRrow->contactId deleted", $Me);
	    $Conf->confirmMsg("Deleted review.");
	    unset($_REQUEST["reviewId"]);
	    $_REQUEST["paperId"] = $editRrow->paperId;
	}
	loadRows();
    }


// download review form action
function downloadForm($inline) {
    global $rf, $Conf, $Me, $prow, $editRrow, $rrows, $myRrow;
    if (!$Me->canViewReview($prow, $editRrow, $Conf, $whyNot))
	return $Conf->errorMsg(whyNotText($whyNot, "review"));
    $text = $rf->textFormHeader($Conf, false, $Me->canViewAllReviewFields($prow, $Conf))
	. $rf->textForm($prow, $editRrow, $Conf,
			($prow->reviewType > 0 ? $_REQUEST : null),
			$Me->canViewAllReviewFields($prow, $Conf)) . "\n";
    downloadText($text, $Conf->downloadPrefix . "review-" . $prow->paperId . ".txt", "review form", $inline);
    exit;
}
if (isset($_REQUEST['downloadForm']))
    downloadForm(false);
if (isset($_REQUEST['text']))
    downloadForm(true);


// refuse review action
function archiveReview($rrow) {
    $fields = "reviewId, paperId, contactId, reviewType, requestedBy,
		requestedOn, acceptedOn, reviewModified, reviewSubmitted, "
	. join(", ", array_keys($reviewFields));
    $Conf->qe("insert into PaperReviewArchive ($fields) select $fields from PaperReview where reviewId=$rrow->reviewId", "while archiving review");
}

function refuseReview() {
    global $ConfSiteBase, $Conf, $Opt, $Me, $prow, $rrow, $reviewFields;
    
    $while = "while refusing review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, PaperReviewArchive write", $while);

    if ($rrow->reviewModified > 0)
	archiveReview($rrow);

    $result = $Conf->qe("delete from PaperReview where reviewId=$rrow->reviewId", $while);
    if (DB::isError($result))
	return;
    $reason = defval($_REQUEST['reason'], "");
    $result = $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($rrow->paperId, $rrow->contactId, $rrow->requestedBy, '" . sqlqtrim($reason) . "')", $while);
    if (DB::isError($result))
	return;

    // send confirmation email
    $m = "Dear " . contactText($rrow->reqFirstName, $rrow->reqLastName, $rrow->reqEmail) . ",\n\n";
    $m .= wordwrap(contactText($rrow) . " cannot complete the review of $Conf->shortName paper #$prow->paperId that you requested.  " . ($reason ? "They gave the reason \"$reason\".  " : "") . "You may want to find an alternate reviewer.\n\n")
	. wordWrapIndent(trim($prow->title), "Title: ", 14) . "\n"
	. "  Paper site: $Conf->paperSite/review.php?paperId=$prow->paperId

- $Conf->shortName Conference Submissions\n";

    $s = "[$Conf->shortName] Review request for paper #$prow->paperId refused";

    if ($Conf->allowEmailTo($rrow->reqEmail))
	$results = mail($rrow->reqEmail, $s, $m, "From: $Conf->emailFrom");
    else
	$Conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");

    // confirmation message
    $Conf->confirmMsg("The request for you to review paper #$prow->paperId has been removed.  Mail was sent to the person who originally requested the review.");
    $Conf->qe("unlock tables");

    $prow = null;
    confHeader();
    exit;
}

if (isset($_REQUEST['refuse'])) {
    if (!$rrow || ($rrow->contactId != $Me->contactId && !$Me->amAssistant())
	|| $rrow->reviewType != REVIEW_REQUESTED)
	$Conf->errorMsg("This review was not requested of you, so you cannot refuse it.");
    else if ($rrow->reviewSubmitted > 0)
	$Conf->errorMsg("This review has already been submitted; you can't refuse it now.");
    else {
	refuseReview();
	$Conf->qe("unlock tables");
	loadRows();
    }
}
    

// set outcome action
if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the outcome for paper #$prow->paperId." . ($Me->amAssistant() ? "  (<a href='" . selfHref(array("forceShow" => 1)) . "'>Override conflict</a>)" : ""));
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$prow->paperId", "while changing outcome");
	    if (!DB::isError($result))
		$Conf->confirmMsg("Outcome for paper #$prow->paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad outcome value!");
	loadRows();
    }
}


// forceShow
if (defval($_REQUEST['forceShow']) && $Me->amAssistant())
    $forceShow = "&amp;forceShow=1";
else
    $forceShow = "";


// mode
if (defval($_REQUEST["mode"]) == "edit")
    $mode = "edit";
else if (defval($_REQUEST["mode"]) == "view")
    $mode = "view";
else if ($rrow && ($Me->canReview($prow, $rrow, $Conf)
		   || ($Me->amAssistant() && ($prow->conflict <= 0 || $forceShow))))
    $mode = "edit";
else if (!$rrow && (($prow->reviewType > 0 && $prow->reviewSubmitted <= 0)
		    || ($Me->isPC && !$Me->canViewReview($prow, $rrow, $Conf))))
    $mode = "edit";
else
    $mode = "view";
// then fix impossible modes
if ($mode == "view" && $prow->conflict <= 0
    && !$Me->canViewReview($prow, $rrow, $Conf, $whyNot)
    && $Me->canReview($prow, $myRrow, $Conf)) {
    if (isset($whyNot['reviewNotComplete']) || isset($whyNot['externalReviewer'])) {
	if (isset($_REQUEST["mode"]) || isset($whyNot['forceShow']))
	    $Conf->infoMsg(whyNotText($whyNot, "review"));
    } else
	errorMsgExit(whyNotText($whyNot, "review"));
    $mode = "edit";
    $rrow = $myRrow;
}
if ($mode == "edit" && !$Me->canReview($prow, $rrow, $Conf, $whyNot)) {
    $Conf->errorMsg(whyNotText($whyNot, "review"));
    $mode = "view";
}
if ($mode == "edit" && !$rrow)
    $rrow = $editRrow;


// page header
confHeader();


// messages for review viewers
if ($mode == "edit" && !$Me->canReview($prow, $rrow, $Conf, $whyNot))
    $Conf->infoMsg(whyNotText($whyNot, "review"));
if ($mode == "edit" && $prow->reviewType <= 0)
    $Conf->infoMsg("You haven't been assigned to review this paper, but you can review it anyway.");


// begin table
echo "<table class='paper'>\n\n";


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$paperTable = new PaperTable(false, false, true, ($Me->amAssistant() && $prow->blind ? 1 : 2));


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2></td>\n</tr>\n\n";


// paper data
$paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD | PaperTable::STATUS_CONFLICTINFO_PC);
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
$revTable = reviewTable($prow, $rrows, $rrow, $mode);
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
if ($reviewTableFolder)
    $revTableClass .= " folded' id='foldrt";
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>";
if ($reviewTableFolder)
    echo "<a class='foldbutton unfolder' href=\"javascript:fold('rt', 0)\">+</a><a class='foldbutton folder' href=\"javascript:fold('rt', 1)\">&minus;</a>&nbsp;";
echo "Reviews</td>\n";
echo "  <td class='entry'>", ($revTable ? $revTable : "None"), "</td>\n";
echo "</tr>\n\n";


if ($Me->canSetOutcome($prow))
    $paperTable->echoOutcomeSelector($prow);


// extra space
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n\n";


// exit on certain errors
if (!$Me->canViewReview($prow, $rrow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "review"));


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

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
    <td class='entry'>";
    $sep = "";
    if ($Me->canViewReviewerIdentity($prow, $rrow, $Conf)) {
	echo "by ", contactHtml($rrow);
	$sep = " &nbsp;|&nbsp; ";
    }
    if ($rrow && $rrow->reviewModified > 0) {
	echo $sep, "Modified ", $Conf->printableTime($rrow->reviewModified);
	$sep = " &nbsp;|&nbsp; ";
    }
    if ($rrow && !$editMode)
	echo $sep, "<a href='review.php?paperId=$prow->paperId&amp;reviewId=$rrow->reviewId&amp;text=1'>Text version</a>";
    echo "</td>
  </tr>\n";
    

    if ($editMode) {
	echo "\n  <tr class='rev_rev'>
    <td class='caption'></td>
    <td class='entry'>";
	if ($rrow && $rrow->contactId != $Me->contactId)
	    $Conf->infoMsg("You aren't the author of this review, but you can still make changes as PC Chair.");
	echo "<form class='downloadreviewform' action='review.php' method='get'>",
	    "<input type='hidden' name='paperId' value='$prow->paperId' />";
	if ($rrow)
	    echo "<input type='hidden' name='reviewId' value='$rrow->reviewId' />";
	echo "<input class='button_small' type='submit' value='Download", ($editMode ? " form" : ""), "' name='downloadForm' id='downloadForm' />",
	    "</form>";

	echo "<form class='downloadreviewform' action='review.php?post=1&amp;paperId=$prow->paperId";
	if ($rrow)
	    echo "&amp;reviewId=$rrow->reviewId";
	echo "' method='post' enctype='multipart/form-data'>",
	    "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_small' type='submit' value='Upload form' name='uploadForm' />",
	    "</form></td>\n  </tr>\n";
    }
    
    echo "</table>\n";

    if ($editMode) {
	// start review form
	echo "<form action='review.php?";
	if (isset($rrow))
	    echo "reviewId=$rrow->reviewId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;post=1' method='post' enctype='multipart/form-data'>\n";
	echo "<table class='reviewform'>\n";

	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<tr class='rev_blind'>
  <td class='caption'></td>
  <td class='entry'><input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST['blind']) : (!$rrow || $rrow->blind))
		echo " checked='checked'";
	    echo " />&nbsp;Anonymous review</td>
  <td class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won't know who wrote the review).</td>
</tr>\n";
	}
	
	// form body
	echo $rf->webFormRows($rrow, true);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>\n";
	    $buttons = array();
	    if (!$rrow || !$rrow->reviewSubmitted) {
		$buttons[] = array("<input class='button_default' type='submit' value='Save changes' name='update' />", "(does not submit review)");
		$buttons[] = array("<input class='button_default' type='submit' value='Submit' name='submit' />", "(cannot undo)");
	    } else {
		$buttons[] = "<input class='button_default' type='submit' value='Resubmit' name='submit' />";
		if ($Me->amAssistant())
		    $buttons[] = array("<input class='button' type='submit' value='Unsubmit' name='unsubmit' />", "(PC chair only)");
	    }
	    if ($rrow && $Me->amAssistant())
		$buttons[] = array("<div id='folddel' class='folded' style='position: relative'><button type='button' onclick=\"fold('del', 0)\">Delete review</button><div class='popupdialog extension'><p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p><input class='button' type='submit' name='delete' value='Delete review' /> <button type='button' onclick=\"fold('del', 1)\">Cancel</button></div></div>", "(PC chair only)");

	    echo "    <tr>\n";
	    foreach ($buttons as $b) {
		$x = (is_array($b) ? $b[0] : $b);
		echo "      <td class='ptb_button'>", $x, "</td>\n";
	    }
	    echo "    </tr>\n    <tr>\n";
	    foreach ($buttons as $b) {
		$x = (is_array($b) ? $b[1] : "");
		echo "      <td class='ptb_explain'>", $x, "</td>\n";
	    }
	    echo "    </tr>\n  </table></td>\n</tr>\n\n";
	}

	echo "</table>\n</form>\n\n";
	
    } else {
	echo "<table class='review'>\n";
	echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
	echo "</table>\n";
    }
    
}


if ($mode == "view" && !$rrow) {
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
