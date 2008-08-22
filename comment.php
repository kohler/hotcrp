<?php 
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
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
	$title = "Paper #$prow->paperId Comments";
    else
	$title = "Paper Comments";
    $Conf->header($title, "comment", actionBar($prow, false, "c"), false);
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
    $paperTable->watchCheckbox = WATCH_COMMENT;
    
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
	$Conf->confirmMsg("Mail preference saved.");
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

    if ($Conf->setting("allowPaperOption") < 6 || !$savedCrow)
	return;

    $result = $Conf->qe("select ContactInfo.contactId,
		firstName, lastName, email, password, roles, defaultWatch,
		reviewType as myReviewType,
		reviewSubmitted as myReviewSubmitted,
		reviewNeedsSubmit as myReviewNeedsSubmit,
		commentId, conflictType, watch
		from ContactInfo
		left join PaperReview on (PaperReview.paperId=$prow->paperId and PaperReview.contactId=ContactInfo.contactId)
		left join PaperComment on (PaperComment.paperId=$prow->paperId and PaperComment.contactId=ContactInfo.contactId)
		left join PaperConflict on (PaperConflict.paperId=$prow->paperId and PaperConflict.contactId=ContactInfo.contactId)
		left join PaperWatch on (PaperWatch.paperId=$prow->paperId and PaperWatch.contactId=ContactInfo.contactId)
		where conflictType>=" . CONFLICT_AUTHOR . " or reviewType is not null or watch is not null or commentId is not null");
    
    $saveProw = (object) null;
    $lastContactId = 0;
    setReviewInfo($saveProw, $prow);

    while (($row = edb_orow($result))) {
	if ($row->contactId == $lastContactId)
	    continue;
	$lastContactId = $row->contactId;
	if ($row->watch & WATCH_COMMENTSET) {
	    if (!($row->watch & WATCH_COMMENT))
		continue;
	} else {
	    if (!($row->defaultWatch & WATCH_COMMENT))
		continue;
	}

	$minic = Contact::makeMinicontact($row);
	setReviewInfo($prow, $row);
	if ($minic->canViewComment($prow, $savedCrow, $Conf)
	    && $minic->contactId != $Me->contactId) {
	    require_once("Code/mailtemplate.inc");
	    Mailer::send("@commentnotify", $prow, $minic, null, array("commentId" => $savedCrow->commentId));
	}
    }

    setReviewInfo($prow, $saveProw);
}


// update comment action
function saveComment($text) {
    global $Me, $Conf, $prow, $crow, $savedCommentId;

    // options
    $reviewLinked = (defval($_REQUEST, "reviewLinked") ? 1 : 0);
    $forAuthors = (defval($_REQUEST, "forAuthors") ? 1 : 0);
    $blind = 0;
    if ($Conf->blindReview() > 1
	|| ($Conf->blindReview() == 1 && defval($_REQUEST, "blind")))
	$blind = 1;
    if (isset($_REQUEST["response"])) {
	$forAuthors = 2;
	$blind = $prow->blind;	// use $prow->blind setting on purpose
    }
    $forReviewers = ($reviewLinked ? -1 : 1);

    // query
    if (!$text) {
	$change = true;
	$q = "delete from PaperComment where commentId=$crow->commentId";
    } else if (!$crow) {
	$change = true;
	$q = "insert into PaperComment (contactId, paperId, timeModified, comment, forReviewers, forAuthors, blind) values ($Me->contactId, $prow->paperId, " . time() . ", '" . sqlq($text) . "', $forReviewers, $forAuthors, $blind)";
    } else {
	$change = ($crow->forAuthors != $forAuthors);
	$q = "update PaperComment set timeModified=" . time() . ", comment='" . sqlq($text) . "', forReviewers=$forReviewers, forAuthors=$forAuthors, blind=$blind where commentId=$crow->commentId";
    }

    $while = "while saving comment";
    $result = $Conf->qe($q, $while);
    if (!$result)
	return;

    // comment ID
    if ($crow)
	$savedCommentId = $crow->commentId;
    else if (!($savedCommentId = $Conf->lastInsertId($while)))
	return;

    // log, end
    $action = ($text == "" ? "deleted" : "saved");
    $Conf->confirmMsg("Comment $action");
    $Conf->log("Comment $savedCommentId $action", $Me, $prow->paperId);

    // adjust comment counts
    if ($change) {
	$Conf->q("unlock tables");	// just in case
	$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$prow->paperId), numAuthorComments=(select count(commentId) from PaperComment where paperId=$prow->paperId and forAuthors>0) where paperId=$prow->paperId", $while);
    }
    
    unset($_REQUEST["c"]);
    $_REQUEST["paperId"] = $prow->paperId;
    if ($text == "")
	unset($_REQUEST["commentId"]);
    else
	$_REQUEST["commentId"] = $savedCommentId;
    $_REQUEST["noedit"] = 1;
}

function saveResponse($text) {
    global $Me, $Conf, $prow, $crow, $linkExtra, $ConfSiteSuffix;

    // make sure there is exactly one response
    if (!$crow) {
	$result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and forAuthors>1");
	if (($row = edb_row($result)))
	    return $Conf->errorMsg("A paper response has already been entered.  <a href=\"comment$ConfSiteSuffix?c=$row[0]$linkExtra\">Edit that response</a>");
    }

    saveComment($text);
}

if (isset($_REQUEST['submit']) && defval($_REQUEST, 'response')) {
    if (!$Me->canRespond($prow, $crow, $Conf, $whyNot, true)) {
	$Conf->errorMsg(whyNotText($whyNot, "respond"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, 'comment')) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	$Conf->qe("lock tables Paper write, PaperComment write, ActionLog write");
	saveResponse($text);
	$Conf->qe("unlock tables");
	loadRows();
	watch();
    }
} else if (isset($_REQUEST['submit'])) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
	$useRequest = true;
    } else if (!($text = defval($_REQUEST, 'comment')) && !$crow) {
	$Conf->errorMsg("Enter a comment.");
	$useRequest = true;
    } else {
	saveComment($text);
	loadRows();
	watch();
    }
} else if (isset($_REQUEST['delete']) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $Conf, $whyNot)) {
	$Conf->errorMsg(whyNotText($whyNot, "comment"));
	$useRequest = true;
    } else {
	saveComment("");
	loadRows();
    }
} else if (isset($_REQUEST["cancel"]) && $crow)
    $_REQUEST["noedit"] = 1;


// paper actions
if (isset($_REQUEST["settags"])) {
    require_once("Code/paperactions.inc");
    PaperActions::setTags($prow);
    loadRows();
}


// can we view/edit reviews?
$viewAny = $Me->canViewReview($prow, null, $Conf, $whyNotView);
$editAny = $Me->canReview($prow, null, $Conf, $whyNotEdit);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (!$Me->canViewPaper($prow, $Conf, $whyNotPaper))
	errorMsgExit(whyNotText($whyNotPaper, "view"));
    if (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["ls"])) {
	$Conf->errorMsg("You can't see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
	$Conf->go("paper$ConfSiteSuffix?p=$prow->paperId$linkExtra");
    }
}


// page header
confHeader();


// mode
if ($paperTable->mode == "r" || $paperTable->mode == "re")
    $paperTable->fixReviewMode();


// paper table
$paperTable->initialize(false, false, true, "review");
$paperTable->paptabBegin($prow);

if (!$viewAny && !$editAny
    && (!$paperTable->rrow
	|| !$Me->canViewReview($prow, $paperTable->rrow, $Conf, $whyNot)))
    $paperTable->paptabEndWithReviewMessage();
else if ($paperTable->mode == "r" && !$paperTable->rrow)
    $paperTable->paptabEndWithReviews();
else
    $paperTable->paptabEndWithEditableReview();

if ($paperTable->mode != "re" || !$paperTable->rrow)
    $paperTable->paptabComments();

echo foldsessionpixel("paper9", "foldpaperp"), foldsessionpixel("paper5", "foldpapert"), foldsessionpixel("paper6", "foldpaperb");
$Conf->footer();
