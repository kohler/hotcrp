<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = array();
require_once("Code/header.inc");
require_once("Code/papertable.inc");
$Me->goIfInvalid();
$rf = reviewForm();
$useRequest = false;
$forceShow = (defval($_REQUEST, "forceShow") && $Me->privChair);
$linkExtra = ($forceShow ? "&amp;forceShow=1" : "");


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
    global $Conf, $Me, $prow, $paperTable, $crow, $savedCommentId, $savedCrow, $Error;
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
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
	$Error = array();
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// set watch preference action
if (isset($_REQUEST["setwatch"]) && $prow && check_post()) {
    $ajax = defval($_REQUEST, "ajax", 0);
    if (!$Me->privChair
	|| ($contactId = rcvtint($_REQUEST["contactId"])) <= 0)
	$contactId = $Me->contactId;
    saveWatchPreference($prow->paperId, $contactId, WATCHTYPE_COMMENT, defval($_REQUEST, "watch"));
    if ($OK)
	$Conf->confirmMsg("Saved");
    if ($ajax)
	$Conf->ajaxExit(array("ok" => $OK));
}


// send watch messages
function comment_watch_callback($prow, $minic) {
    global $savedCrow;
    $tmpl = ($savedCrow->forAuthors > 1 ? "@responsenotify" : "@commentnotify");
    if ($minic->canViewComment($prow, $savedCrow)) {
	require_once("Code/mailtemplate.inc");
	Mailer::send($tmpl, $prow, $minic, null, array("commentId" => $savedCrow->commentId));
    }
}

function watch() {
    global $prow, $savedCrow;
    if (!$savedCrow
	// ignore changes to a comment within 3 hours (see saveComment())
	|| $savedCrow->timeNotified != $savedCrow->timeModified)
	return;
    genericWatch($prow, WATCHTYPE_COMMENT, "comment_watch_callback");
}


// update comment action
function saveComment($text) {
    global $Me, $Conf, $prow, $crow, $savedCommentId;

    // options
    $visibility = defval($_REQUEST, "visibility", "r");
    if ($visibility != "a" && $visibility != "r" && $visibility != "p" && $visibility != "admin")
	$visibility = "r";
    $forReviewers = ($visibility == "p" ? 0 : 1);
    if ($visibility == "admin")
	$forReviewers = 2;
    $forAuthors = ($visibility == "a" ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > BLIND_OPTIONAL
	|| ($Conf->blindReview() == BLIND_OPTIONAL && defval($_REQUEST, "blind")))
	$blind = 1;
    if (isset($_REQUEST["response"])) {
	$forAuthors = 2;
        if (defval($_REQUEST, "forReviewers")
            || isset($_REQUEST["submitresponse"]))
            $forReviewers = 1;
        else
            $forReviewers = 0;
	$blind = $prow->blind;	// use $prow->blind setting on purpose
    }

    $while = $insert_id_while = "while saving comment";

    // query
    $now = time();
    if (!$text) {
	$change = true;
	$q = "delete from PaperComment where commentId=$crow->commentId";
    } else if (!$crow) {
	$change = true;
	$qa = $qb = "";
	if (!(($forAuthors == 2 && $forReviewers == 0) || $forReviewers == 2)
	    && $Conf->sversion >= 43) {
	    $qa .= ", ordinal";
	    $qb .= ", greatest(commentCount,maxOrdinal)+1";
	}
	$q = "insert into PaperComment
		(contactId, paperId, timeModified, comment, forReviewers,
		forAuthors, blind, timeNotified$qa)
	select $Me->contactId, $prow->paperId, $now, '" . sqlq($text) . "',
		$forReviewers, $forAuthors, $blind, $now$qb\n";
	if ($forAuthors == 2) {
	    // make sure there is exactly one response
	    $q .= "	from (select P.paperId, coalesce(C.commentId,0) commentId, 0 commentCount, 0 maxOrdinal
		from Paper P
		left join PaperComment C on (C.paperId=P.paperId and C.forAuthors=2)
		where P.paperId=$prow->paperId group by P.paperId) T
	where T.commentId=0";
	    $insert_id_while = false;
	} else {
	    $q .= "	from (select P.paperId, coalesce(count(C.commentId),0) commentCount, coalesce(max(C.ordinal),0) maxOrdinal
		from Paper P
		left join PaperComment C on (C.paperId=P.paperId and C.forReviewers=$forReviewers and C.forAuthors=$forAuthors)
		where P.paperId=$prow->paperId group by P.paperId) T";
	}
    } else {
	$change = ($crow->forAuthors != $forAuthors);
	if ($crow->timeModified >= $now)
	    $now = $crow->timeModified + 1;
	// do not notify on updates within 3 hours
	$qa = "";
	if ($crow->timeNotified + 10800 < $now
            || ($forAuthors == 2 && $forReviewers && !$crow->forReviewers))
	    $qa = ", timeNotified=$now";
	$q = "update PaperComment set timeModified=$now$qa, comment='" . sqlq($text) . "', forReviewers=$forReviewers, forAuthors=$forAuthors, blind=$blind where commentId=$crow->commentId";
    }

    $result = $Conf->qe($q, $while);
    if (!$result)
	return false;

    // comment ID
    if ($crow)
	$savedCommentId = $crow->commentId;
    else if (!($savedCommentId = $Conf->lastInsertId($insert_id_while)))
	return false;

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
	$_SESSION["comment_msgs"][$savedCommentId] = "<div class='xwarning'>$what saved. However, at your request, this response will not be shown to reviewers.$extratext</div>";
    } else if ($text != "")
	$_SESSION["comment_msgs"][$savedCommentId] = "<div class='xconfirm'>$what submitted.</div>";
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
	redirectSelf(array("anchor" => "comment$savedCommentId",
			   "noedit" => null, "c" => null));
    } else
	redirectSelf();
    // NB normally redirectSelf() does not return
    return true;
}

function saveResponse($text) {
    global $Me, $Conf, $prow, $linkExtra;

    $success = saveComment($text);
    if (!$success) {
	$result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and forAuthors>1");
	if (($row = edb_row($result)))
	    return $Conf->errorMsg("A paper response has already been entered.  <a href=\"" . hoturl("comment", "c=$row[0]$linkExtra") . "\">Edit that response</a>");
    }
}

if (!check_post())
    /* do nothing */;
else if ((isset($_REQUEST["submit"]) || isset($_REQUEST["submitresponse"])
          || isset($_REQUEST["savedraft"]))
         && defval($_REQUEST, "response")) {
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
	saveComment($text);
} else if (isset($_REQUEST["delete"]) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment on"));
	$useRequest = true;
    } else
	saveComment("");
} else if (isset($_REQUEST["cancel"]) && $crow)
    $_REQUEST["noedit"] = 1;


// paper actions
if ((isset($_REQUEST["settags"]) || isset($_REQUEST["settingtags"])) && check_post()) {
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
	$Conf->errorMsg("You can’t see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
	$Conf->go(hoturl("paper", "p=$prow->paperId$linkExtra"));
    }
}


// mode
if ($paperTable->mode == "r" || $paperTable->mode == "re")
    $paperTable->fixReviewMode();
if ($paperTable->mode == "pe")
    $Conf->go(hoturl("paper", "p=$prow->paperId$linkExtra"));


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
