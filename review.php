<?php 
require_once('Code/header.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;
$forceShow = (defval($_REQUEST['forceShow']) && $Me->amAssistant() ? "&amp;forceShow=1" : "");


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
    global $Conf, $Me, $ConfSiteBase, $prow, $rrows, $rrow, $editRrow, $nExternalRequests;
    if (isset($_REQUEST["reviewId"]))
	$sel = array("reviewId" => $_REQUEST["reviewId"]);
    else if (isset($_REQUEST["paperId"])) {
	maybeSearchPaperId("review.php", $Me);
	$sel = array("paperId" => $_REQUEST["paperId"]);
    } else
	errorMsgExit("Select a paper ID above, or <a href='${ConfSiteBase}search.php?q='>list the papers you can view</a>.");
    $sel["tags"] = 1;
    if (!(($prow = $Conf->paperRow($sel, $Me->contactId, $whyNot))
	  && $Me->canViewPaper($prow, $Conf, $whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));

    $rrows = $Conf->reviewRow(array('paperId' => $prow->paperId, 'array' => 1), $whyNot);
    $rrow = $myRrow = null;
    $nExternalRequests = 0;
    foreach ($rrows as $rr) {
	if (isset($_REQUEST['reviewId']) && $rr->reviewId == $_REQUEST['reviewId'])
	    $rrow = $rr;
	if ($rr->contactId == $Me->contactId)
	    $myRrow = $rr;
	if ($rr->reviewType == REVIEW_REQUESTED && $rr->requestedBy == $Me->contactId)
	    $nExternalRequests++;
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
	$tf['err'][] = $tf['firstLineno'] . ": This review form is for paper #" . $req['paperId'] . ", not paper #$prow->paperId; did you mean to upload it here?  I have ignored the form.  <a class='button_small' href='${ConfSiteBase}review.php?paperId=" . $req['paperId'] . "'>Review paper #" . $req['paperId'] . "</a> <a class='button_small' href='${ConfSiteBase}offline.php'>General review upload site</a>";
    else if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$tf['err'][] = $tf['firstLineno'] . ": " . whyNotText($whyNot, "review");
    else if ($rf->checkRequestFields($req, $editRrow, $tf)) {
	$result = $rf->saveRequest($req, $editRrow, $prow, $Me->contactId);
	if (!MDB2::isError($result))
	    $tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
    }

    if (count($tf['err']) == 0 && $rf->parseTextForm($tf, $Conf))
	$tf['err'][] = $tf['firstLineno'] . ": Only the first review form in the file was parsed.  <a href='${ConfSiteBase}offline.php'>Upload multiple-review files here.</a>";

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
	if (!MDB2::isError($result))
	    $Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	loadRows();
    } else
	$useRequest = true;


// unsubmit review action
if (isset($_REQUEST['unsubmit']) && $Me->amAssistant())
    if (!$editRrow || $editRrow->reviewSubmitted <= 0)
	$Conf->errorMsg("This review has not been submitted.");
    else {
	$result = $Conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=1 where reviewId=$editRrow->reviewId", "while unsubmitting review");
	if (!MDB2::isError($result)) {
	    $Conf->log("Review $editRrow->reviewId by $editRrow->contactId unsubmitted", $Me, $prow->paperId);
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
	if (!MDB2::isError($result)) {
	    $Conf->log("Review $editRrow->reviewId by $editRrow->contactId deleted", $Me, $prow->paperId);
	    $Conf->confirmMsg("Deleted review.");
	    unset($_REQUEST["reviewId"]);
	    $_REQUEST["paperId"] = $editRrow->paperId;
	}
	loadRows();
    }


// download review form action
function downloadForm($inline) {
    global $rf, $Conf, $Me, $prow, $editRrow, $rrows, $myRrow, $Opt;
    if (!$Me->canViewReview($prow, $editRrow, $Conf, $whyNot))
	return $Conf->errorMsg(whyNotText($whyNot, "review"));
    $text = $rf->textFormHeader($Conf, false, $Me->canViewAllReviewFields($prow, $Conf))
	. $rf->textForm($prow, $editRrow, $Conf,
			($prow->reviewType > 0 ? $_REQUEST : null),
			$Me->canViewAllReviewFields($prow, $Conf) ? ReviewForm::REV_PC : ReviewForm::REV_AUTHOR) . "\n";
    downloadText($text, $Opt['downloadPrefix'] . "review-" . $prow->paperId . ".txt", "review form", $inline);
    exit;
}
if (isset($_REQUEST['downloadForm']))
    downloadForm(false);
if (isset($_REQUEST['text']))
    downloadForm(true);


// refuse review action
function archiveReview($rrow) {
    global $reviewFields, $Conf;
    $fields = "reviewId, paperId, contactId, reviewType, requestedBy,
		requestedOn, acceptedOn, reviewModified, reviewSubmitted,
		reviewNeedsSubmit, "
	. join(", ", array_keys($reviewFields));
    $Conf->qe("insert into PaperReviewArchive ($fields) select $fields from PaperReview where reviewId=$rrow->reviewId", "while archiving review");
}

function refuseReview() {
    global $ConfSiteBase, $Conf, $Opt, $Me, $prow, $rrow;
    
    $while = "while refusing review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, PaperReviewArchive write", $while);

    if ($rrow->reviewModified > 0)
	archiveReview($rrow);

    $result = $Conf->qe("delete from PaperReview where reviewId=$rrow->reviewId", $while);
    if (MDB2::isError($result))
	return;
    $reason = defval($_REQUEST['reason'], "");
    $result = $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($rrow->paperId, $rrow->contactId, $rrow->requestedBy, '" . sqlqtrim($reason) . "')", $while);
    if (MDB2::isError($result))
	return;

    // now the requester must potentially complete their review
    if ($rrow->requestedBy > 0) {
	$result = $Conf->qe("select reviewId from PaperReview where requestedBy=$rrow->requestedBy and paperId=$rrow->paperId", $while);
	if (!MDB2::isError($result) && $result->numRows() == 0)
	    $Conf->qe("update PaperReview set reviewNeedsSubmit=1 where reviewType=" . REVIEW_SECONDARY . " and paperId=$rrow->paperId and contactId=$rrow->requestedBy and reviewSubmitted<=0", $while);
    }

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
    if (!$rrow || ($rrow->contactId != $Me->contactId && !$Me->amAssistant()))
	$Conf->errorMsg("This review was not assigned to you, so you cannot refuse it.");
    else if ($rrow->reviewType >= REVIEW_SECONDARY)
	$Conf->errorMsg("PC members cannot refuse reviews that were explicitly assigned to them.  Contact the PC chairs directly if you really cannot finish this review.");
    else if ($rrow->reviewSubmitted > 0)
	$Conf->errorMsg("This review has already been submitted; you can't refuse it now.");
    else {
	refuseReview();
	$Conf->qe("unlock tables");
	loadRows();
    }
}

if (isset($_REQUEST['delegate'])) {
    if (!$rrow || ($rrow->contactId != $Me->contactId && !$Me->amAssistant()))
	$Conf->errorMsg("This review was not assigned to you, so you cannot delegate it.");
    else if ($rrow->reviewType != REVIEW_SECONDARY)
	$Conf->errorMsg("Only secondary reviewers can delegate their reviews to others.");
    else if ($rrow->reviewSubmitted > 0)
	$Conf->errorMsg("This review has already been submitted; there's no point in delegating.");
    else if ($nExternalRequests == 0)
	$Conf->errorMsg("You can't delegate your secondary review for this paper since you haven't actually asked anyone to review it.  <a href=\"assign.php?paperId=$prow->paperId\">Request one or more external reviews</a> then try again.");
    else {
	$Conf->qe("update PaperReview set reviewNeedsSubmit=0 where reviewId=$rrow->reviewId", "while delegating review");
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
	    if (!MDB2::isError($result))
		$Conf->confirmMsg("Outcome for paper #$prow->paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad outcome value!");
	loadRows();
    }
}


// set tags action
if (isset($_REQUEST["settags"])) {
    if ($Me->isPC && ($prow->conflict <= 0 || ($Me->amAssistant() && $forceShow))) {
	require_once("Code/tags.inc");
	setTags($prow->paperId, defval($_REQUEST["tags"], ""), 'p', $Me->amAssistant());
	loadRows();
    } else
	$Conf->errorMsg("You cannot set tags for paper #$prow->paperId." . ($Me->amAssistant() ? "  (<a href='" . selfHref(array("forceShow" => 1)) . "'>Override conflict</a>)" : ""));
}


// page header
confHeader();


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
if ($Me->isPC)
    $paperTable->echoTags($prow, "${ConfSiteBase}review.php?paperId=$prow->paperId");
if ($Me->amAssistant())
    $paperTable->echoPCConflicts($prow);


// can we see any reviews?
$Conf->tableMsg(1);
$viewAny = $Me->canViewReview($prow, null, $Conf, $whyNotView);
$editAny = $Me->canReview($prow, null, $Conf, $whyNotEdit);
if (!$viewAny && !$editAny)
    errorMsgExit("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));


// mode
if (defval($_REQUEST["mode"]) == "edit")
    $mode = "edit";
else if (defval($_REQUEST["mode"]) == "view")
    $mode = "view";
else if ($rrow && ($Me->canReview($prow, $rrow, $Conf)
		   || ($Me->amAssistant() && ($prow->conflict <= 0 || $forceShow))))
    $mode = "edit";
else if (!$rrow && (($prow->reviewType > 0 && $prow->reviewSubmitted <= 0)
		    || ($Me->isPC && !$viewAny)))
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


// messages for review viewers
if ($mode == "edit" && $prow->reviewType <= 0)
    $Conf->infoMsg("You haven't been assigned to review this paper, but you can review it anyway.");


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
$Conf->tableMsg(0);


// exit on certain errors
if ($rrow && !$Me->canViewReview($prow, $rrow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "review"));


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

function reviewView($prow, $rrow, $editMode) {
    global $Conf, $ConfSiteBase, $Me, $rf, $forceShow, $useRequest, $nExternalRequests;
    
    echo "<div class='gap'></div>\n\n";

    if ($editMode) {
	echo "<form action='review.php?";
	if ($rrow)
	    echo "reviewId=$rrow->reviewId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;post=1' method='post' enctype='multipart/form-data'>\n";
    }
    
    echo "<table class='review'>
<tr class='id'>
  <td class='caption'><h3";
    if ($rrow)
	echo " id='review$rrow->reviewId'";
    echo ">Review";
    if ($rrow && $rrow->reviewSubmitted > 0)
	echo "&nbsp;#", $prow->paperId, unparseReviewOrdinal($rrow->reviewOrdinal);
    echo "</h3></td>
  <td class='entry' colspan='", ($editMode ? 2 : 3), "'>";
    $sep = "";
    if ($rrow && $Me->canViewReviewerIdentity($prow, $rrow, $Conf)) {
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
	// refuse?
	if ($rrow && $rrow->reviewSubmitted <= 0 && $rrow->reviewType < REVIEW_SECONDARY) {
	    echo "\n<tr class='rev_ref'>\n  <td class='caption'></td>\n  <td class='entry' colspan='2'>";
	    echo "<div id='foldref' class='folded' style='position: relative'><a href=\"javascript:fold('ref', 0)\">Refuse review</a> if you are unable or unwilling to complete it
  <div class='popupdialog extension'><p>Thank you for telling us that you cannot complete your review.  You may give a few words of explanation if you'd like.</p>\n";
	    echo "    <input class='textlite' type='text' name='reason' value='' size='40' />
    <hr class='smgap' />
    <input class='button' type='submit' name='refuse' value='Refuse review' />
    <button type='button' onclick=\"fold('ref', 1)\">Cancel</button>
  </div></div>";
	    echo "</td>\n</tr>\n";
	}

	// delegate?
	if ($rrow && $rrow->reviewSubmitted <= 0 && $rrow->reviewType == REVIEW_SECONDARY) {
	    echo "\n<tr class='rev_del'>\n  <td class='caption'></td>\n  <td class='entry' colspan='2'>";
	    if ($nExternalRequests == 0)
		echo "As a secondary reviewer, you can delegate your review, expressing your intention not to write a review yourself, once you have <a href=\"assign.php?paperId=$rrow->paperId$forceShow\">requested at least one external review</a>.";
	    else if ($rrow->reviewNeedsSubmit)
		echo "<a href=\"review.php?reviewId=$rrow->reviewId&amp;delegate=1$forceShow\">Delegate review</a> if you don't plan to finish this review yourself";
	    else
		echo "This secondary review has been delegated, but you can still complete it if you'd like.";
	    echo "</td>\n</tr>\n";
	}
	
	// download?
	echo "\n<tr class='rev_rev'>
  <td class='caption'></td>
  <td class='entry' colspan='2'>";
	if ($rrow && $rrow->contactId != $Me->contactId)
	    $Conf->infoMsg("You didn't write this review, but you can still make changes as PC Chair.");
	echo "<input class='button_small' type='submit' value='Download", ($editMode ? " form" : ""), "' name='downloadForm' id='downloadForm' />";
	echo "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_small' type='submit' value='Upload form' name='uploadForm' /></td>\n</tr>\n";
    }
    
    if ($editMode) {
	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<tr class='rev_blind'>
  <td class='caption'></td>
  <td class='entry'><input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST['blind']) : (!$rrow || $rrow->reviewBlind))
		echo " checked='checked'";
	    echo " />&nbsp;Anonymous review</td>
  <td class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won't know who wrote the review).</td>
</tr>\n";
	}
	
	// form body
	echo $rf->webFormRows($rrow, $useRequest);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>\n";
	    $buttons = array();
	    if (!$rrow || $rrow->reviewSubmitted <= 0) {
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
	echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
	echo "</table>\n";
    }
    
}


if ($mode == "view" && !$rrow) {
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted > 0)
	    reviewView($prow, $rr, false);
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted <= 0 && $rr->reviewModified > 0
	    && $Me->canViewReview($prow, $rr, $Conf))
	    reviewView($prow, $rr, false);
} else
    reviewView($prow, $rrow, $mode == "edit");


echo "<div class='gapbottom'></div>\n";

$Conf->footer();
