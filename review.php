<?php 
// review.php -- HotCRP paper review display/edit page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/papertable.inc');
require_once('Code/reviewtable.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = isset($_REQUEST["afterLogin"]);
$forceShow = (defval($_REQUEST["forceShow"]) && $Me->privChair ? "&amp;forceShow=1" : "");


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
	$title = "Paper #$prow->paperId Reviews";
    else
	$title = "Paper Reviews";
    $Conf->header($title, "review", actionBar($prow, false, "review"), false);
    $Conf->expandBody();
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
    else {
	maybeSearchPaperId("review.php", $Me);
	$sel = array("paperId" => $_REQUEST["paperId"]);
    }
    $sel["tags"] = $sel["topics"] = $sel["options"] = 1;
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
	if ($rr->reviewType == REVIEW_EXTERNAL && $rr->requestedBy == $Me->contactId)
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
	$rf->tfError($tf, "This review form is for paper #" . $req['paperId'] . ", not paper #$prow->paperId; did you mean to upload it here?  I have ignored the form.  <a class='button_small' href='${ConfSiteBase}review.php?paperId=" . $req['paperId'] . "'>Review paper #" . $req['paperId'] . "</a> <a class='button_small' href='${ConfSiteBase}offline.php'>General review upload site</a>");
    else if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$rf->tfError($tf, whyNotText($whyNot, "review"));
    else if ($rf->checkRequestFields($req, $editRrow, $tf)) {
	if ($rf->saveRequest($req, $editRrow, $prow, $Me->contactId))
	    $tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
    }

    if (count($tf['err']) == 0 && $rf->parseTextForm($tf, $Conf))
	$rf->tfError($tf, "Only the first review form in the file was parsed.  <a href='${ConfSiteBase}offline.php'>Upload multiple-review files here.</a>");

    $rf->textFormMessages($tf, $Conf);
    loadRows();
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


// update review action
if (isset($_REQUEST['update']) || isset($_REQUEST['submit']) || isset($_REQUEST['unsubmit']))
    if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$Conf->errorMsg(whyNotText($whyNot, "review"));
    else if ($rf->checkRequestFields($_REQUEST, $editRrow)) {
	if ($rf->saveRequest($_REQUEST, $editRrow, $prow, $Me->contactId))
	    $Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	loadRows();
    } else
	$useRequest = true;


// unsubmit review action
if (isset($_REQUEST['unsubmit']) && $Me->privChair)
    if (!$editRrow || !$editRrow->reviewSubmitted)
	$Conf->errorMsg("This review has not been submitted.");
    else {
	$while = "while unsubmitting review";
	$Conf->qe("lock tables PaperReview write", $while);
	$needsSubmit = 1;
	if ($editRrow->reviewType == REVIEW_SECONDARY) {
	    $result = $Conf->qe("select reviewSubmitted from PaperReview where paperId=$prow->paperId and reviewType=" . REVIEW_EXTERNAL . " and requestedBy=$editRrow->contactId", $while);
	    $row = edb_row($result);
	    if ($row && $row[0] > 0 && $Conf->setting("allowPaperOption") >= 3)
		$needsSubmit = "null";
	    else
		$needsSubmit = 0;
	}
	$result = $Conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=$needsSubmit where reviewId=$editRrow->reviewId", $while);
	$Conf->qe("unlock tables", $while);
	if ($result) {
	    $Conf->log("Review $editRrow->reviewId by $editRrow->contactId unsubmitted", $Me, $prow->paperId);
	    $Conf->confirmMsg("Unsubmitted review.");
	}
	loadRows();
    }


// delete review action
if (isset($_REQUEST['delete']) && $Me->privChair)
    if (!$editRrow)
	$Conf->errorMsg("No review to delete.");
    else {
	archiveReview($editRrow);
	$result = $Conf->qe("delete from PaperReview where reviewId=$editRrow->reviewId", "while deleting review");
	if ($result) {
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
    if ($editRrow || count($rrows) == 0)
	$rrows = array($editRrow);
    $text = $rf->textFormHeader($Conf, count($rrows) > 1, $Me->canViewAllReviewFields($prow, $Conf));
    foreach ($rrows as $rr)
	$text .= $rf->textForm($prow, $rr, $Me, $Conf,
			($prow->reviewType > 0 && $rr->contactId == $Me->contactId ? $_REQUEST : null)) . "\n";
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
    if (!$result)
	return;
    $reason = defval($_REQUEST['reason'], "");
    $result = $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($rrow->paperId, $rrow->contactId, $rrow->requestedBy, '" . sqlqtrim($reason) . "')", $while);
    if (!$result)
	return;

    // now the requester must potentially complete their review
    if ($rrow->requestedBy > 0) {
	$result = $Conf->qe("select reviewId from PaperReview where requestedBy=$rrow->requestedBy and paperId=$rrow->paperId", $while);
	if ($result && edb_nrows($result) == 0)
	    $Conf->qe("update PaperReview set reviewNeedsSubmit=1 where reviewType=" . REVIEW_SECONDARY . " and paperId=$rrow->paperId and contactId=$rrow->requestedBy and reviewSubmitted is null", $while);
    }

    // send confirmation email
    require_once("Code/mailtemplate.inc");
    $Requester = (object) array("firstName" => $rrow->reqFirstName, "lastName" => $rrow->reqLastName, "email" => $rrow->reqEmail);
    Mailer::send("@refusereviewrequest", $prow, $Requester, $rrow, array("reason" => $reason));

    // confirmation message
    $Conf->confirmMsg("The request for you to review paper #$prow->paperId has been removed.  Mail was sent to the person who originally requested the review.");
    $Conf->qe("unlock tables");

    $prow = null;
    confHeader();
    exit;
}

if (isset($_REQUEST['refuse'])) {
    if (!$rrow || ($rrow->contactId != $Me->contactId && !$Me->privChair))
	$Conf->errorMsg("This review was not assigned to you, so you cannot refuse it.");
    else if ($rrow->reviewType >= REVIEW_SECONDARY)
	$Conf->errorMsg("PC members cannot refuse reviews that were explicitly assigned to them.  Contact the PC chairs directly if you really cannot finish this review.");
    else if ($rrow->reviewSubmitted)
	$Conf->errorMsg("This review has already been submitted; you can't refuse it now.");
    else {
	refuseReview();
	$Conf->qe("unlock tables");
	loadRows();
    }
}

if (isset($_REQUEST['delegate'])) {
    if (!$rrow || ($rrow->contactId != $Me->contactId && !$Me->privChair))
	$Conf->errorMsg("This review was not assigned to you, so you cannot delegate it.");
    else if ($rrow->reviewType != REVIEW_SECONDARY)
	$Conf->errorMsg("Only secondary reviewers can delegate their reviews to others.");
    else if ($rrow->reviewSubmitted)
	$Conf->errorMsg("This review has already been submitted; there's no point in delegating.");
    else if ($nExternalRequests == 0)
	$Conf->errorMsg("You can't delegate your secondary review for this paper since you haven't actually asked anyone to review it.  <a href=\"assign.php?paperId=$prow->paperId\">Request one or more external reviews</a> then try again.");
    else {
	$while = "while delegating review";
	$Conf->qe("lock tables PaperReview write", $while);
	if ($Conf->setting("allowPaperOption") >= 3) {
	    $result = $Conf->qe("select paperId from PaperReview where paperId=$rrow->paperId and reviewType=" . REVIEW_EXTERNAL . " and requestedBy=$rrow->contactId and reviewSubmitted>0", $while);
	    $needsSubmit = (edb_nrows($result) ? "null" : 0);
	} else
	    $needsSubmit = 0;
	$Conf->qe("update PaperReview set reviewNeedsSubmit=$needsSubmit where reviewId=$rrow->reviewId", "while delegating review");
	$Conf->qe("unlock tables", $while);
	loadRows();
    }
}


// set outcome action
if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the decision for paper #$prow->paperId." . ($Me->privChair ? "  (<a href='" . selfHref(array("forceShow" => 1)) . "'>Override conflict</a>)" : ""));
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$prow->paperId", "while changing decision");
	    if ($result)
		$Conf->confirmMsg("Decision for paper #$prow->paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad decision value!");
	loadRows();
    }
}


// set tags action
if (isset($_REQUEST["settags"])) {
    if ($Me->isPC && ($prow->conflictType == 0 || ($Me->privChair && $forceShow))) {
	require_once("Code/tags.inc");
	setTags($prow->paperId, defval($_REQUEST["tags"], ""), 'p', $Me->privChair);
	loadRows();
    } else
	$Conf->errorMsg("You cannot set tags for paper #$prow->paperId." . ($Me->privChair ? "  (<a href='" . selfHref(array("forceShow" => 1)) . "'>Override conflict</a>)" : ""));
}


// page header
confHeader();


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$authorsFolded = (!$canViewAuthors && $Me->privChair && $prow->blind ? 1 : 2);
$paperTable = new PaperTable(false, false, true, $authorsFolded);


// begin table
$paperTable->echoDivEnter();
echo "<table class='paper'>\n\n";
$Conf->tableMsg(2, $paperTable);


// title
echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "</h2></td>\n</tr>\n\n";


// can we view/edit reviews?
$viewAny = $Me->canViewReview($prow, null, $Conf, $whyNotView);
$editAny = $Me->canReview($prow, null, $Conf, $whyNotEdit);


// paper data
$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO_PC);
if ($canViewAuthors || $Me->privChair) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
}
$paperTable->echoAbstractRow($prow);
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->privChair);
if ($Me->isPC && ($prow->conflictType == 0 || ($Me->privChair && $forceShow)))
    $paperTable->echoTags($prow, "${ConfSiteBase}review.php?paperId=$prow->paperId$forceShow");
if ($Me->privChair)
    $paperTable->echoPCConflicts($prow);
if ($canViewAuthors || $Me->privChair)
    $paperTable->echoCollaborators($prow);
if ($Me->isPC && ($prow->conflictType == 0 || ($Me->privChair && $forceShow)))
    $paperTable->echoLead($prow);
if ($viewAny)
    $paperTable->echoShepherd($prow);


// can we see any reviews?
if (!$viewAny && !$editAny)
    errorMsgExit("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
if ($Me->privChair && $prow->conflictType > 0 && !$Me->canViewReview($prow, null, $Conf, $fakeWhyNotView, true))
    $Conf->infoMsg("You have explicitly overridden your conflict and are able to view and edit reviews for this paper.");


// mode
if (defval($_REQUEST["mode"]) == "edit")
    $mode = "edit";
else if (defval($_REQUEST["mode"]) == "view")
    $mode = "view";
else if ($rrow && ($Me->canReview($prow, $rrow, $Conf)
		   || ($Me->privChair && ($prow->conflictType == 0 || $forceShow))))
    $mode = "edit";
 else if (!$rrow && $editAny && !$viewAny)
    $mode = "edit";
else
    $mode = "view";
// then fix impossible modes
if ($mode == "view" && $prow->conflictType == 0
    && !$Me->canViewReview($prow, $rrow, $Conf, $whyNot)
    && $Me->canReview($prow, $myRrow, $Conf)) {
    $Conf->errorMsg("?");
    if (isset($whyNot['reviewNotComplete']) || isset($whyNot['externalReviewer'])) {
	if (isset($_REQUEST["mode"]) || isset($whyNot['forceShow']))
	    $Conf->infoMsg(whyNotText($whyNot, "review"));
    } else
	errorMsgExit(whyNotText($whyNot, "review"));
    $mode = "edit";
    $rrow = $myRrow;
}
if ($mode == "edit" && !$Me->canReview($prow, $rrow, $Conf, $whyNot)) {
    $Conf->errorMsg("!");
    $Conf->errorMsg(whyNotText($whyNot, "review"));
    $mode = "view";
}
if ($mode == "edit" && !$rrow)
    $rrow = $editRrow;


// messages for review viewers
if ($mode == "edit" && $prow->reviewType <= 0 && !$rrow)
    $Conf->infoMsg("You haven't been assigned to review this paper, but you can review it anyway.");


// reviewer information
$revTable = reviewTable($prow, $rrows, $rrow, $mode);
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
if ($reviewTableFolder)
    $revTableClass .= " foldc' id='foldrt";
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
</table>";
$paperTable->echoDivExit();
$Conf->tableMsg(0);


// exit on certain errors
if ($rrow && !$Me->canViewReview($prow, $rrow, $Conf, $whyNot))
    errorMsgExit(whyNotText($whyNot, "review"));


// review information
// XXX reviewer ID
// XXX "<td class='entry'>", contactHtml($rrow), "</td>"

function reviewView($prow, $rrow, $editMode) {
    global $Conf, $ConfSiteBase, $Me, $rf, $forceShow, $useRequest, $nExternalRequests;
    
    if ($editMode) {
	echo "<form action='review.php?";
	if ($rrow)
	    echo "reviewId=$rrow->reviewId";
	else
	    echo "paperId=$prow->paperId";
	echo "$forceShow&amp;mode=edit&amp;post=1' method='post' enctype='multipart/form-data'>\n";
    }
    
    echo "<table class='review'>
<tr class='id'>
  <td class='caption'><h3";
    if ($rrow)
	echo " id='review$rrow->reviewId'";
    echo ">";
    if ($rrow) {
	echo "<a href='review.php?reviewId=$rrow->reviewId$forceShow' class='q'>Review";
	if ($rrow->reviewSubmitted)
	    echo "&nbsp;#", $prow->paperId, unparseReviewOrdinal($rrow->reviewOrdinal);
	echo "</a>";
    } else
	echo "Review";
    echo "</h3></td>
  <td class='entry' colspan='", ($editMode ? 2 : 3), "'>";
    $sep = "";
    if ($rrow && $Me->canViewReviewerIdentity($prow, $rrow, $Conf)) {
	echo ($rrow->reviewBlind ? "[" : ""), "by ", contactHtml($rrow);
	$sep = ($rrow->reviewBlind ? "]" : "") . " &nbsp;|&nbsp; ";
    }
    if ($rrow && $rrow->reviewModified > 0) {
	echo $sep, "Modified ", $Conf->printableTime($rrow->reviewModified);
	$sep = " &nbsp;|&nbsp; ";
    }
    if ($rrow)
	echo $sep, "<a href='review.php?paperId=$prow->paperId&amp;reviewId=$rrow->reviewId&amp;text=1$forceShow'>Text version</a>";
    echo "</td>
</tr>\n";
    

    if ($editMode) {
	// refuse?
	if ($rrow && !$rrow->reviewSubmitted && $rrow->reviewType < REVIEW_SECONDARY) {
	    echo "\n<tr class='rev_ref'>\n  <td class='caption'></td>\n  <td class='entry' colspan='2'>";
	    echo "<div id='foldref' class='foldc' style='position: relative'><a href=\"javascript:fold('ref', 0)\">Refuse review</a> if you are unable or unwilling to complete it
  <div class='popupdialog extension'><p>Thank you for telling us that you cannot complete your review.  You may give a few words of explanation if you'd like.</p>\n";
	    echo "    <input class='textlite' type='text' name='reason' value='' size='40' />
    <div class='xsmgap'></div>
    <input class='button' type='submit' name='refuse' value='Refuse review' />
    <button type='button' onclick=\"fold('ref', 1)\">Cancel</button>
  </div></div>";
	    echo "</td>\n</tr>\n";
	}

	// delegate?
	if ($rrow && !$rrow->reviewSubmitted && $rrow->reviewType == REVIEW_SECONDARY) {
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
	    $Conf->infoMsg("You didn't write this review, but as an administrator you can still make changes.");
	echo "<input class='button_small' type='submit' value='Download", ($editMode ? " form" : ""), "' name='downloadForm' id='downloadForm' />";
	echo "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp; <input class='button_small' type='submit' value='Upload form' name='uploadForm' />";
	echo "</td>\n</tr>\n";
    }
    
    if ($editMode) {
	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<tr class='rev_blind'>
  <td class='caption'>Anonymity</td>
  <td class='entry'><div class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won't know who wrote the review).</div>
    <input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST['blind']) : (!$rrow || $rrow->reviewBlind))
		echo " checked='checked'";
	    echo " />&nbsp;Anonymous review</td>\n</tr>\n";
	}
	
	// form body
	echo $rf->webFormRows($rrow, $useRequest);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->privChair) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><div class='smgap'></div><table class='pt_buttons'>\n";
	    $buttons = array();
	    if (!$rrow || !$rrow->reviewSubmitted) {
		$buttons[] = array("<input class='button' type='submit' value='Save changes' name='update' />", "(does not submit review)");
		$buttons[] = array("<input class='button' type='submit' value='Submit' name='submit' />", "(cannot undo)");
	    } else {
		$buttons[] = "<input class='button' type='submit' value='Resubmit' name='submit' />";
		if ($Me->privChair)
		    $buttons[] = array("<input class='button' type='submit' value='Unsubmit' name='unsubmit' />", "(PC chair only)");
	    }
	    if ($rrow && $Me->privChair)
		$buttons[] = array("<div id='folddel' class='foldc' style='position: relative'><button type='button' onclick=\"fold('del', 0)\">Delete review</button><div class='popupdialog extension'><p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p><input class='button' type='submit' name='delete' value='Delete review' /> <button type='button' onclick=\"fold('del', 1)\">Cancel</button></div></div>", "(PC chair only)");

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
	    echo "    </tr>\n";
	    if ($Me->privChair)
		echo "      <tr><td colspan='" . count($buttons) . "'><input type='checkbox' name='override' value='1' />&nbsp;Override deadlines</td></tr>\n";
	    echo "  </table></td>\n</tr>\n\n";
	}

	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table>\n</form>\n\n";
    } else {
	echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table>\n";
    }
    
}


if ($mode == "view" && !$rrow) {
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted)
	    reviewView($prow, $rr, false);
    foreach ($rrows as $rr)
	if (!$rr->reviewSubmitted && $rr->reviewModified > 0
	    && $Me->canViewReview($prow, $rr, $Conf))
	    reviewView($prow, $rr, false);
} else
    reviewView($prow, $rrow, $mode == "edit");


$Conf->footer();
