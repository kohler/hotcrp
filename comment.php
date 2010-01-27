<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2010 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;
$forceShow = (defval($_REQUEST, "forceShow") && $Me->privChair);
$linkExtra = ($forceShow ? "&amp;forceShow=1" : "");
$Error = array();


// header
function confHeader() {
    global $prow, $mode, $Conf, $linkExtra, $CurrentList;
    if ($prow)
	$title = "Paper #$prow->paperId";
    else
	$title = "Paper Comments";
    $Conf->header($title, "comment", actionBar("c", $prow), false);
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
    global $Conf, $Me, $prow, $paperTable, $crow, $savedCommentId, $savedCrow;
    if (!($prow = PaperTable::paperRow($whyNot)))
	errorMsgExit(whyNotText($whyNot, "view"));
    $paperTable = new PaperTable($prow);
    $paperTable->resolveReview();
    $paperTable->resolveComments();

    $crow = null;
    $cid = defval($_REQUEST, "commentId", "xxx");
    foreach ($paperTable->crows as $row) {
	if ($row->commentId == $cid
	    || ($cid == "response" && $row->forAuthors > 1))
	    $crow = $row;
	if (isset($savedCommentId) && $row->commentId == $savedCommentId)
	    $savedCrow = $row;
    }
    if ($cid != "xxx" && !$crow && $cid != "response" && $cid != "new")
	errorMsgExit("That comment does not exist.");
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// set watch preference action
if (isset($_REQUEST['setwatch']) && $prow) {
    $ajax = defval($_REQUEST, "ajax", 0);
    if (!$Me->privChair
	|| ($contactId = rcvtint($_REQUEST["contactId"])) <= 0)
	$contactId = $Me->contactId;
    if (defval($_REQUEST, 'watch'))
	$q = "insert into PaperWatch (paperId, contactId, watch) values ($prow->paperId, $contactId, " . (WATCH_COMMENTSET | WATCH_COMMENT) . ") on duplicate key update watch = watch | " . (WATCH_COMMENTSET | WATCH_COMMENT);
    else
	$q = "insert into PaperWatch (paperId, contactId, watch) values ($prow->paperId, $contactId, " . WATCH_COMMENTSET . ") on duplicate key update watch = (watch | " . WATCH_COMMENTSET . ") & " . (~WATCH_COMMENT & 127);
    $Conf->qe($q, "while saving watch preference");
    if ($OK)
	$Conf->confirmMsg("Saved");
    if ($ajax)
	$Conf->ajaxExit(array("ok" => $OK));
}


// send watch messages
function setReviewInfo($dst, $src) {
    $dst->myReviewType = $src->myReviewType;
    $dst->myReviewSubmitted = $src->myReviewSubmitted;
    $dst->myReviewNeedsSubmit = $src->myReviewNeedsSubmit;
    $dst->conflictType = $src->conflictType;
}

function watch() {
    global $Conf, $Me, $prow, $savedCrow;

    $apo = $Conf->sversion;
    if ($apo < 6 || !$savedCrow)
	return;

    // ignore changes to a comment within 3 hours (see saveComment())
    if ($apo >= 21 && $savedCrow->timeNotified != $savedCrow->timeModified)
	return;

    $qa = ($apo >= 25 ? ", preferredEmail" : "");
    $result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email$qa, password, roles, defaultWatch,
		reviewType as myReviewType,
		reviewSubmitted as myReviewSubmitted,
		reviewNeedsSubmit as myReviewNeedsSubmit,
		commentId, conflictType, watch
		from ContactInfo
		left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
		left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
		left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
		left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
		where conflictType>=" . CONFLICT_AUTHOR . " or reviewType is not null or watch is not null or commentId is not null or (defaultWatch & " . WATCH_ALLCOMMENTS . ")!=0");

    $saveProw = (object) null;
    $lastContactId = 0;
    setReviewInfo($saveProw, $prow);
    $tmpl = ($savedCrow->forAuthors > 1 ? "@responsenotify" : "@commentnotify");

    while (($row = edb_orow($result))) {
	if ($row->contactId == $lastContactId)
	    continue;
	$lastContactId = $row->contactId;
	if ($row->watch & WATCH_COMMENTSET) {
	    if (!($row->watch & WATCH_COMMENT))
		continue;
	} else {
	    if (!($row->defaultWatch & (WATCH_COMMENT | WATCH_ALLCOMMENTS)))
		continue;
	}

	$minic = Contact::makeMinicontact($row);
	setReviewInfo($prow, $row);
	if ($minic->canViewComment($prow, $savedCrow)
	    && $minic->contactId != $Me->contactId) {
	    require_once("Code/mailtemplate.inc");
	    Mailer::send($tmpl, $prow, $minic, null, array("commentId" => $savedCrow->commentId));
	}
    }

    setReviewInfo($prow, $saveProw);
}


// update comment action
function saveComment($text, $locked) {
    global $Me, $Conf, $prow, $crow, $savedCommentId;

    // options
    $visibility = defval($_REQUEST, "visibility", "r");
    if ($visibility != "a" && $visibility != "r" && $visibility != "p")
	$visibility = "r";
    $forReviewers = ($visibility == "p" ? 0 : -1);
    $forAuthors = ($visibility == "a" ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > BLIND_OPTIONAL
	|| ($Conf->blindReview() == BLIND_OPTIONAL && defval($_REQUEST, "blind")))
	$blind = 1;
    if (isset($_REQUEST["response"])) {
	$forAuthors = 2;
	$forReviewers = (defval($_REQUEST, "forReviewers") ? -1 : 0);
	$blind = $prow->blind;	// use $prow->blind setting on purpose
    }

    // query
    $now = time();
    $notify = ($Conf->sversion >= 21);
    if (!$text) {
	$change = true;
	$q = "delete from PaperComment where commentId=$crow->commentId";
    } else if (!$crow) {
	$change = true;
	if ($notify) {
	    $qa = ", timeNotified";
	    $qb = ", $now";
	} else
	    $qa = $qb = "";
	$q = "insert into PaperComment (contactId, paperId, timeModified$qa, comment, forReviewers, forAuthors, blind) values ($Me->contactId, $prow->paperId, $now$qb, '" . sqlq($text) . "', $forReviewers, $forAuthors, $blind)";
    } else {
	$change = ($crow->forAuthors != $forAuthors);
	if ($crow->timeModified >= $now)
	    $now = $crow->timeModified + 1;
	// do not notify on updates within 3 hours
	if ($notify && $crow->timeNotified + 10800 < $now)
	    $qa = ", timeNotified=$now";
	else
	    $qa = "";
	$q = "update PaperComment set timeModified=$now$qa, comment='" . sqlq($text) . "', forReviewers=$forReviewers, forAuthors=$forAuthors, blind=$blind where commentId=$crow->commentId";
    }

    $while = "while saving comment";
    $result = $Conf->qe($q, $while);
    if (!$result)
	return false;

    // comment ID
    if ($crow)
	$savedCommentId = $crow->commentId;
    else if (!($savedCommentId = $Conf->lastInsertId($while)))
	return false;

    // we are done saving the comment; unlock tables
    if ($locked)
	$Conf->q("unlock tables");

    // log, end
    $what = ($forAuthors > 1 ? "Response" : "Comment");
    if ($text != "" && !isset($_SESSION["comment_msgs"]))
	$_SESSION["comment_msgs"] = array();
    if ($text != "" && $forAuthors > 1 && $forReviewers == 0) {
	$deadline = $Conf->printableTimeSetting("resp_done");
	if ($deadline != "N/A")
	    $extratext = "  You have until $deadline to send the response to the reviewers.";
	else
	    $extratext = "";
	$_SESSION["comment_msgs"][$savedCommentId] = "<div class='xwarning'>$what saved.  However, at your request, this response will not be shown to reviewers.$extratext</div>";
    } else if ($text != "")
	$_SESSION["comment_msgs"][$savedCommentId] = "<div class='xconfirm'>$what saved.</div>";
    else
	$Conf->confirmMsg("$what deleted.");
    $Conf->log("Comment $savedCommentId " . ($text != "" ? "saved" : "deleted"),
	       $Me, $prow->paperId);

    // adjust comment counts
    if ($change) {
	// see also account.php:delete user
	$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$prow->paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=$prow->paperId and forAuthors>0) where paperId=$prow->paperId", $while);
    }

    unset($_REQUEST["c"]);
    $_REQUEST["paperId"] = $prow->paperId;
    if ($text != "")
	$_REQUEST["commentId"] = $savedCommentId;
    else
	unset($_REQUEST["commentId"]);
    $_REQUEST["noedit"] = 1;

    loadRows();
    if ($text != "") {
	watch();
	redirectSelf(array("anchor" => "comment$savedCommentId"));
    } else
	redirectSelf();
    // NB normally redirectSelf() does not return
    return true;
}

function saveResponse($text) {
    global $Me, $Conf, $prow, $crow, $linkExtra, $ConfSiteSuffix;

    // make sure there is exactly one response
    if (!$crow) {
	$result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and forAuthors>1");
	if (($row = edb_row($result)))
	    return $Conf->errorMsg("A paper response has already been entered.  <a href=\"comment$ConfSiteSuffix?c=$row[0]$linkExtra\">Edit that response</a>");
    }

    $Conf->qe("lock tables Paper write, PaperComment write, ActionLog write");
    $success = saveComment($text, true);
    if (!$success)
	$Conf->qe("unlock tables");
}

if (isset($_REQUEST["submit"]) && defval($_REQUEST, "response")) {
    if (!$Me->canRespond($prow, $crow, $whyNot, true)) {
	$Conf->errorMsg(whyNotText($whyNot, "respond to reviews for"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, "comment")) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else
	saveResponse($text);
} else if (isset($_REQUEST["submit"])) {
    if (!$Me->canSubmitComment($prow, $crow, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment on"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, "comment")) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else
	saveComment($text, false);
} else if (isset($_REQUEST["delete"]) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment on"));
	$useRequest = true;
    } else
	saveComment("", false);
} else if (isset($_REQUEST["cancel"]) && $crow)
    $_REQUEST["noedit"] = 1;


// paper actions
if (isset($_REQUEST["settingtags"])) {
    require_once("Code/paperactions.inc");
    PaperActions::setTags($prow);
    loadRows();
}


// can we view/edit reviews?
$viewAny = $Me->canViewReview($prow, null, $whyNotView);
$editAny = $Me->canReview($prow, null, $whyNotEdit);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (!$Me->canViewPaper($prow, $whyNotPaper))
	errorMsgExit(whyNotText($whyNotPaper, "view"));
    if (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["ls"])) {
	$Conf->errorMsg("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
	$Conf->go("paper$ConfSiteSuffix?p=$prow->paperId$linkExtra");
    }
}


// mode
if ($paperTable->mode == "r" || $paperTable->mode == "re")
    $paperTable->fixReviewMode();
if ($paperTable->mode == "pe")
    $Conf->go("paper$ConfSiteSuffix?p=$prow->paperId$linkExtra");


// page header
confHeader();


// paper table
$paperTable->initialize(false, false);
$paperTable->paptabBegin();

if (!$viewAny && !$editAny
    && (!$paperTable->rrow
	|| !$Me->canViewReview($prow, $paperTable->rrow, $whyNot)))
    $paperTable->paptabEndWithReviewMessage();
else if ($paperTable->mode == "r" && !$paperTable->rrow)
    $paperTable->paptabEndWithReviews();
else
    $paperTable->paptabEndWithEditableReview();

$paperTable->paptabComments();

$Conf->footer();
