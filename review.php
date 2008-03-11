<?php 
// review.php -- HotCRP paper review display/edit page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
require_once("Code/reviewtable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = isset($_REQUEST["afterLogin"]);
$forceShow = (defval($_REQUEST, "forceShow") && $Me->privChair ? "&amp;forceShow=1" : "");
$linkExtra = $forceShow;


// header
function confHeader() {
    global $prow, $mode, $Conf, $linkExtra, $CurrentList;
    if ($prow)
	$title = "Paper #$prow->paperId Reviews";
    else
	$title = "Paper Reviews";
    $Conf->header($title, "review", actionBar($prow, false, "review"), false);
    if (isset($CurrentList) && $CurrentList > 0
	&& strpos($linkExtra, "ls=") === false)
	$linkExtra .= "&amp;ls=" . $CurrentList;
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $ConfSiteBase, $ConfSiteSuffix, $prow, $rrows, $rrow, $myRrow, $editRrow, $nExternalRequests, $editRrowLogname;
    if (!isset($_REQUEST["reviewId"]) && isset($_REQUEST["r"]))
	$_REQUEST["reviewId"] = $_REQUEST["r"];
    if (isset($_REQUEST["reviewId"]))
	$sel = array("reviewId" => $_REQUEST["reviewId"]);
    else {
	maybeSearchPaperId($Me);
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
	if (isset($_REQUEST['reviewId'])) {
	    if ($rr->reviewId == $_REQUEST['reviewId']
		|| ($rr->reviewOrdinal && $rr->paperId . unparseReviewOrdinal($rr->reviewOrdinal) == $_REQUEST["reviewId"]))
		$rrow = $rr;
	}
	if ($rr->contactId == $Me->contactId)
	    $myRrow = $rr;
	if ($rr->reviewType == REVIEW_EXTERNAL && $rr->requestedBy == $Me->contactId)
	    $nExternalRequests++;
    }
    if (isset($_REQUEST['reviewId']) && !$rrow)
	errorMsgExit("That review no longer exists.");
    $editRrow = ($rrow ? $rrow : $myRrow);
    if ($editRrow && $editRrow->contactId == $Me->contactId)
	$editRrowLogname = "Review $editRrow->reviewId";
    else if ($editRrow)
	$editRrowLogname = "Review $editRrow->reviewId by $editRrow->email";
}
loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");
else if (isset($_REQUEST["post"]) && isset($_REQUEST["default"])) {
    if (fileUploaded($_FILES["uploadedFile"], $Conf))
	$_REQUEST["uploadForm"] = 1;
    else
	$_REQUEST["update"] = 1;
}


// upload review form action
if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    // parse form, store reviews
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);

    if (!($req = $rf->parseTextForm($tf, $Conf)))
	/* error already reported */;
    else if (isset($req['paperId']) && $req['paperId'] != $prow->paperId)
	$rf->tfError($tf, "This review form is for paper #" . $req['paperId'] . ", not paper #$prow->paperId; did you mean to upload it here?  I have ignored the form.<br /><a class='button_small' href='${ConfSiteBase}review$ConfSiteSuffix?p=" . $req['paperId'] . "'>Review paper #" . $req['paperId'] . "</a> <a class='button_small' href='${ConfSiteBase}offline$ConfSiteSuffix'>General review upload site</a>");
    else if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot))
	$rf->tfError($tf, whyNotText($whyNot, "review"));
    else {
	$req['paperId'] = $prow->paperId;
	if ($rf->checkRequestFields($req, $editRrow, $tf)) {
	    if ($rf->saveRequest($req, $editRrow, $prow, $Me->contactId))
		$tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
	}
    }

    if (count($tf['err']) == 0 && $rf->parseTextForm($tf, $Conf))
	$rf->tfError($tf, "Only the first review form in the file was parsed.  <a href='${ConfSiteBase}offline$ConfSiteSuffix'>Upload multiple-review files here.</a>");

    $rf->textFormMessages($tf, $Conf);
    loadRows();
} else if (isset($_REQUEST['uploadForm']))
    $Conf->errorMsg("Select a review form to upload.");


// check review submit requirements
if (isset($_REQUEST['update']) && $editRrow && $editRrow->reviewSubmitted)
    if (isset($_REQUEST["ready"]))
	/* do nothing */;
    else if (!$Me->privChair)
	$_REQUEST["ready"] = 1;
    else {
	$while = "while unsubmitting review";
	$Conf->qe("lock tables PaperReview write", $while);
	$needsSubmit = 1;
	if ($editRrow->reviewType == REVIEW_SECONDARY) {
	    $result = $Conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where requestedBy=$editRrow->contactId and paperId=$prow->paperId", $while);
	    if (($row = edb_row($result)) && $row[0])
		$needsSubmit = 0;
	    else if ($row && $row[1])
		$needsSubmit = -1;
	}
	$result = $Conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=$needsSubmit where reviewId=$editRrow->reviewId", $while);
	$Conf->qe("unlock tables", $while);
	if ($result) {
	    $Conf->log("$editRrowLogname unsubmitted", $Me, $prow->paperId);
	    $Conf->confirmMsg("Unsubmitted review.");
	}
	loadRows();
    }


// update review action
if (isset($_REQUEST['update']))
    if (!$Me->canSubmitReview($prow, $editRrow, $Conf, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "review"));
	$useRequest = true;
    } else if ($rf->checkRequestFields($_REQUEST, $editRrow)) {
	if ($rf->saveRequest($_REQUEST, $editRrow, $prow, $Me->contactId))
	    $Conf->confirmMsg(isset($_REQUEST['ready']) ? "Review submitted." : "Review saved.");
	loadRows();
    } else
	$useRequest = true;


// delete review action
if (isset($_REQUEST['delete']) && $Me->privChair)
    if (!$editRrow)
	$Conf->errorMsg("No review to delete.");
    else {
	archiveReview($editRrow);
	$while = "while deleting review";
	$result = $Conf->qe("delete from PaperReview where reviewId=$editRrow->reviewId", $while);
	if ($result) {
	    $Conf->log("$editRrowLogname deleted", $Me, $prow->paperId);
	    $Conf->confirmMsg("Deleted review.");

	    // perhaps a delegatee needs to redelegate
	    if ($editRrow->reviewType == REVIEW_EXTERNAL && $editRrow->requestedBy > 0) {
		$result = $Conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where requestedBy=$editRrow->requestedBy and paperId=$editRrow->paperId", $while);
		if (!($row = edb_row($result)) || $row[0] == 0)
		    $Conf->qe("update PaperReview set reviewNeedsSubmit=" . ($row && $row[1] ? -1 : 1) . " where reviewType=" . REVIEW_SECONDARY . " and paperId=$editRrow->paperId and contactId=$editRrow->requestedBy and reviewSubmitted is null", $while);
	    }
	    
	    unset($_REQUEST["reviewId"]);
	    unset($_REQUEST["r"]);
	    $_REQUEST["paperId"] = $editRrow->paperId;
	}
	loadRows();
    }


// download review form action
function downloadView($prow, $rr, $editable) {
    global $rf, $Me, $Conf;
    if ($editable && $prow->reviewType > 0 && $rr->contactId == $Me->contactId)
	return $rf->textForm($prow, $rr, $Me, $Conf, $_REQUEST, true) . "\n";
    else if ($editable)
	return $rf->textForm($prow, $rr, $Me, $Conf, null, true) . "\n";
    else
	return $rf->prettyTextForm($prow, $rr, $Me, $Conf, false) . "\n";
}

function downloadForm($editable) {
    global $rf, $Conf, $Me, $prow, $rrow, $rrows, $myRrow, $Opt;
    if ($rrow || count($rrows) == 0)
	$rrows = array($rrow);
    $text = "";
    foreach ($rrows as $rr)
	if ($rr->reviewSubmitted
	    && $Me->canViewReview($prow, $rr, $Conf, $whyNot))
	    $text .= downloadView($prow, $rr, $editable);
    foreach ($rrows as $rr)
	if (!$rr->reviewSubmitted && $rr->reviewModified > 0
	    && $Me->canViewReview($prow, $rr, $Conf))
	    $text .= downloadView($prow, $rr, $editable);
    if (!$text)
	return $Conf->errorMsg(whyNotText($whyNot, "review"));
    if ($editable)
	$text = $rf->textFormHeader($Conf, count($rrows) > 1, $Me->viewReviewFieldsScore($prow, null, $Conf)) . $text;
    downloadText($text, $Opt['downloadPrefix'] . "review-" . $prow->paperId . ".txt", "review form", !$editable);
    exit;
}
if (isset($_REQUEST['downloadForm']))
    downloadForm(true);
else if (isset($_REQUEST['text']))
    downloadForm(false);


// refuse review action
function archiveReview($rrow) {
    global $Conf;
    $rf = reviewForm();
    $fields = "reviewId, paperId, contactId, reviewType, requestedBy,
		requestedOn, acceptedOn, reviewModified, reviewSubmitted,
		reviewNeedsSubmit, "
	. join(", ", array_keys($rf->reviewFields));
    // compensate for 2.12 schema error
    if ($Conf->setting("allowPaperOption") == 8)
	$fields = str_replace(", textField7, textField8", "", $fields);
    $Conf->qe("insert into PaperReviewArchive ($fields) select $fields from PaperReview where reviewId=$rrow->reviewId", "while archiving review");
}

function refuseReview() {
    global $Conf, $Opt, $Me, $prow, $rrow;
    
    $while = "while refusing review";
    $Conf->qe("lock tables PaperReview write, PaperReviewRefused write, PaperReviewArchive write", $while);

    if ($rrow->reviewModified > 0)
	archiveReview($rrow);

    $result = $Conf->qe("delete from PaperReview where reviewId=$rrow->reviewId", $while);
    if (!$result)
	return;
    $reason = defval($_REQUEST, 'reason', "");
    $result = $Conf->qe("insert into PaperReviewRefused (paperId, contactId, requestedBy, reason) values ($rrow->paperId, $rrow->contactId, $rrow->requestedBy, '" . sqlqtrim($reason) . "')", $while);
    if (!$result)
	return;

    // now the requester must potentially complete their review
    if ($rrow->reviewType == REVIEW_EXTERNAL && $rrow->requestedBy > 0) {
	$result = $Conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where requestedBy=$rrow->requestedBy and paperId=$rrow->paperId", $while);
	if (!($row = edb_row($result)) || $row[0] == 0)
	    $Conf->qe("update PaperReview set reviewNeedsSubmit=" . ($row && $row[1] ? -1 : 1) . " where reviewType=" . REVIEW_SECONDARY . " and paperId=$rrow->paperId and contactId=$rrow->requestedBy and reviewSubmitted is null", $while);
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


// set outcome action
if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the decision for paper #$prow->paperId." . ($Me->privChair ? "  (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Override conflict</a>)" : ""));
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$prow->paperId", "while changing decision");
	    if ($result)
		$Conf->confirmMsg("Decision for paper #$prow->paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	    if ($o > 0 || $prow->outcome > 0)
		$Conf->updatePaperaccSetting($o > 0);
	} else
	    $Conf->errorMsg("Bad decision value!");
	loadRows();
    }
}


// set tags action (see also comment.php)
if (isset($_REQUEST["settags"])) {
    if ($Me->canSetTags($prow, $Conf, $forceShow)) {
	require_once("Code/tags.inc");
	setTags($prow->paperId, defval($_REQUEST, "tags", ""), 'p', $Me->privChair);
	loadRows();
    } else
	$Conf->errorMsg("You cannot set tags for paper #$prow->paperId." . ($Me->privChair ? "  (<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\">Override conflict</a>)" : ""));
}


// can we view/edit reviews?
$viewAny = $Me->canViewReview($prow, null, $Conf, $whyNotView);
$editAny = $Me->canReview($prow, null, $Conf, $whyNotEdit);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["ls"])) {
	$Conf->errorMsg("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
	$Conf->go("paper$ConfSiteSuffix?p=$prow->paperId$linkExtra");
    } else
	errorMsgExit("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
}
if ($forceShow && !$Me->canViewReview($prow, null, $Conf, $fakeWhyNotView, true))
    $Conf->infoMsg("You have used administrator privileges to view and edit reviews for this paper.");


// page header
confHeader();


// mode
if (defval($_REQUEST, "mode") == "edit")
    $mode = "edit";
else if (defval($_REQUEST, "mode") == "view")
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
    if (isset($whyNot['reviewNotComplete']) || isset($whyNot["reviewNotSubmitted"]) || isset($whyNot['externalReviewer'])) {
	if (isset($_REQUEST["mode"]) || isset($whyNot["forceShow"]) || isset($_REQUEST["reviewId"]))
	    $Conf->infoMsg(whyNotText($whyNot, "review") . "  Showing all available reviews instead.");
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
// print deadline message
if ($rrow && ($rrow->contactId == $Me->contactId
	      || ($Me->privChair && $mode == "edit"))
    && !$Conf->timeReviewPaper($Me->isPC, true, true)) {
    $override = ($Me->privChair ? "  As an administrator, you can override this deadline using the \"Override deadlines\" checkbox." : "");
    $Conf->infoMsg("The <a href='deadlines$ConfSiteSuffix'>deadline</a> for changing reviews has passed, so the review can no longer be changed.$override");
}


// messages for review viewers
if ($mode == "edit" && $prow->reviewType <= 0 && !$rrow)
    $Conf->infoMsg("You haven't been assigned to review this paper, but you can review it anyway.");


// paper table
$canViewAuthors = $Me->canViewAuthors($prow, $Conf, $forceShow);
$authorsFolded = (!$canViewAuthors && $Me->privChair && paperBlind($prow) ? 1 : 2);
$paperTable = new PaperTable(false, false, true, $authorsFolded, "review");
$paperTable->echoDivEnter();
echo "<table class='paper'>\n\n";
$Conf->tableMsg(2, $paperTable);

echo "<tr class='id'>\n  <td class='caption'><h2>#$prow->paperId</h2></td>\n";
echo "  <td class='entry' colspan='2'><h2>";
$paperTable->echoTitle($prow);
echo "<img id='foldsession.paper9' alt='' src='${ConfSiteBase}sessionvar$ConfSiteSuffix?var=foldreviewp&amp;val=", defval($_SESSION, "foldreviewp", 1), "&amp;cache=1' width='1' height='1' />";
echo "</h2></td>\n</tr>\n\n";

$paperTable->echoPaperRow($prow, PaperTable::STATUS_CONFLICTINFO_PC);
if ($canViewAuthors || $Me->privChair) {
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoContactAuthor($prow);
    $paperTable->echoCollaborators($prow);
}
$paperTable->echoAbstractRow($prow);
$paperTable->echoTopics($prow);
$paperTable->echoOptions($prow, $Me->privChair);
if ($Me->canViewTags($prow, $Conf, $forceShow))
    $paperTable->echoTags($prow, "${ConfSiteBase}review$ConfSiteSuffix?p=$prow->paperId$linkExtra");
if ($Me->privChair)
    $paperTable->echoPCConflicts($prow, true);
if ($Me->isPC && ($prow->conflictType == 0 || ($Me->privChair && $forceShow)))
    $paperTable->echoLead($prow);
if ($viewAny)
    $paperTable->echoShepherd($prow);


// reviewer information
$revTable = reviewTable($prow, $rrows, $rrow, $mode);
$revTableClass = (preg_match("/<th/", $revTable) ? "rev_reviewers_hdr" : "rev_reviewers");
if ($reviewTableFolder)
    $revTableClass .= " foldc' id='foldrt";
echo "<tr class='", $revTableClass, "'>\n";
echo "  <td class='caption'>";
if ($reviewTableFolder)
    echo "<a class='foldbutton unfolder' href=\"javascript:fold('rt', 0)\">+</a><a class='foldbutton folder' href=\"javascript:fold('rt', 1)\">&ndash;</a>&nbsp;";
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
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $Me, $rf, $forceShow,
	$linkExtra, $useRequest, $nExternalRequests;
    
    $reviewLink = "review$ConfSiteSuffix?"
	. ($rrow ? "r=$rrow->reviewId" : "p=$prow->paperId")
	. $linkExtra . "&amp;mode=edit&amp;post=1";
    if ($editMode)
	echo "<form method='post' action=\"$reviewLink\" enctype='multipart/form-data'>",
	    "<input class='hidden' type='submit' name='default' value='' />";
    else
	echo "<div class='relative'>";
    
    echo "<table class='review'>
<tr class='id'>
  <td class='caption'><h3";
    if ($rrow)
	echo " id='review$rrow->reviewId'";
    echo ">";
    if ($rrow) {
	echo "<a href='review$ConfSiteSuffix?r=",
	    ($rrow->reviewSubmitted ? $prow->paperId . unparseReviewOrdinal($rrow->reviewOrdinal) : $rrow->reviewId),
	    "$linkExtra' class='q'>Review";
	if ($rrow->reviewSubmitted)
	    echo "&nbsp;#", $prow->paperId, unparseReviewOrdinal($rrow->reviewOrdinal);
	echo "</a>";
    } else
	echo "Review";
    echo "</h3></td>
  <td class='entry' colspan='", ($editMode ? 2 : 3), "'>";
    $sep = "";
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
    if ($rrow && $Me->canViewReviewerIdentity($prow, $rrow, $Conf)) {
	echo ($rrow->reviewBlind ? "[" : ""), "by ", contactHtml($rrow);
	$sep = ($rrow->reviewBlind ? "]" : "") . $xsep;
    }
    if ($rrow && $rrow->reviewModified > 0) {
	echo $sep, "Modified ", $Conf->printableTime($rrow->reviewModified);
	$sep = $xsep;
    }
    if ($rrow) {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;r=$rrow->reviewId&amp;text=1$linkExtra'>";
	echo $sep, $a, $Conf->cacheableImage("txt.png", "[Text]", null, "b"),
	    "</a>&nbsp;", $a, "Text version</a>";
	$sep = $xsep;
    }
    if ($rrow && !$editMode && $Me->canReview($prow, $rrow, $Conf))
	echo $sep, "<a class='button' href='review$ConfSiteSuffix?r=$rrow->reviewId'>Edit</a>";
    echo "</td>
</tr>\n";
    

    if ($editMode) {
	// refuse?
	if ($rrow && !$rrow->reviewSubmitted && $rrow->reviewType < REVIEW_SECONDARY) {
	    echo "\n<tr class='rev_ref'>\n  <td class='caption'></td>\n  <td class='entry' colspan='2'>";
	    echo "<a id='popupanchor_ref' href=\"javascript:void popup(null, 'ref', 0)\">Refuse review</a> if you are unable or unwilling to complete it\n";
	    $Conf->footerStuff .= "<div id='popup_ref' class='popupc'><p>Thank you for telling us that you cannot complete your review.  You may give a few words of explanation if you'd like.</p><form method='post' action=\"$reviewLink\" enctype='multipart/form-data'><div class='popup_actions'>
  <input class='textlite' type='text' name='reason' value='' size='40' />
  <div class='smgap'></div>
  <input class='button' type='submit' name='refuse' value='Refuse review' />
  &nbsp;<button type='button' onclick=\"popup(null, 'ref', 1)\">Cancel</button></div></form></div>";
	    echo "</td>\n</tr>\n";
	}

	// delegate?
	if ($rrow && !$rrow->reviewSubmitted && $rrow->reviewType == REVIEW_SECONDARY) {
	    echo "\n<tr class='rev_del'>\n  <td class='caption'></td>\n  <td class='entry' colspan='2'>";
	    if ($nExternalRequests == 0)
		echo "As a secondary reviewer, you can <a href=\"assign$ConfSiteSuffix?p=$rrow->paperId$linkExtra\">delegate this review to an external reviewer</a>, but if your external reviewer refuses to review the paper, you should complete the review yourself.";
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
	echo "Upload form:&nbsp; ",
	    "<input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp; ",
	    "<input class='button_small' type='submit' value='Go' name='uploadForm' />";
	echo "</td>\n</tr>\n";
    }
    
    if ($editMode) {
	// blind?
	if ($Conf->blindReview() == 1) {
	    echo "<tr class='rev_blind'>
  <td class='caption'>Anonymity</td>
  <td class='entry'><div class='hint'>", htmlspecialchars($Conf->shortName), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won't know who wrote the review).</div>
    <input type='checkbox' name='blind' value='1'";
	    if ($useRequest ? defval($_REQUEST, 'blind') : (!$rrow || $rrow->reviewBlind))
		echo " checked='checked'";
	    echo " />&nbsp;Anonymous review</td>\n</tr>\n";
	}
	
	// form body
	echo $rf->webFormRows($Me, $prow, $rrow, $useRequest);

	// review actions
	if ($Me->timeReview($prow, $Conf) || $Me->privChair) {
	    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><div class='smgap'></div>",
		"<table><tr><td><input type='checkbox' name='ready' value='1'";
	    if ($rrow && ($useRequest ? defval($_REQUEST, "ready") : $rrow->reviewSubmitted))
		echo " checked='checked'";
	    if ($rrow && $rrow->reviewSubmitted && !$Me->privChair)
		echo " disabled='disabled'";
	    echo " />&nbsp;</td><td>The review is ready for others to see.";
	    if ($rrow && $rrow->reviewSubmitted && !$Me->privChair)
		echo "<div class='hint'>Only administrators can remove the review from the system at this point.</div>";
	    echo "</td></tr></table>",
		"<div class='smgap'></div><table class='pt_buttons'>\n";
	    $buttons = array();
	    $buttons[] = "<input class='hbutton' type='submit' value='Save changes' name='update' />";
	    if ($rrow && $Me->privChair) {
		$buttons[] = array("<button type='button' onclick=\"popup(this, 'd', 0)\">Delete review</button>", "(admin only)");
		$Conf->footerStuff .= "<div id='popup_d' class='popupc'><p>Be careful: This will permanently delete all information about this review assignment from the database and <strong>cannot be undone</strong>.</p><form method='post' action=\"$reviewLink\" enctype='multipart/form-data'><div class='popup_actions'><input class='button' type='submit' name='delete' value='Delete review' /> &nbsp;<button type='button' onclick=\"popup(null, 'd', 1)\">Cancel</button></div></form></div>";
	    }

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
	    echo "    </tr>\n  </table></td>\n</tr>";
	    if ($Me->privChair)
		echo "<tr>\n  <td class='caption'></td>\n  <td class='entry'>",
		    "<input type='checkbox' name='override' value='1' />&nbsp;Override deadlines",
		    "</td>\n</tr>";
	    echo "\n\n";
	}

	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table>\n</form>\n\n";
    } else {
	echo $rf->webDisplayRows($rrow, $Me->viewReviewFieldsScore($prow, $rrow, $Conf), true);
	echo "<tr class='last'><td class='caption'></td></tr>\n";
	echo "</table></div>\n";
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
