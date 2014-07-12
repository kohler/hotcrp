<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = $Warning = array();
require_once("src/initweb.php");
require_once("src/papertable.php");
if ($Me->is_empty())
    $Me->escape();
$useRequest = isset($_REQUEST["after_login"]);


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
        $title = "Paper #$prow->paperId";
    else
        $title = "Paper Comments";
    $Conf->header($title, "comment", actionBar("c", $prow), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->footerScript("shortcut().add()");
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $prow, $paperTable, $crow, $Error;
    if (!($prow = PaperTable::paperRow($whyNot)))
        errorMsgExit(whyNotText($whyNot, "view"));
    $paperTable = new PaperTable($prow);
    $paperTable->resolveReview();
    $paperTable->resolveComments();

    $crow = null;
    $cid = defval($_REQUEST, "commentId", "xxx");
    foreach ($paperTable->crows as $row) {
        if ($row->commentId == $cid
            || ($cid == "response" && ($row->commentType & COMMENTTYPE_RESPONSE)))
            $crow = $row;
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
        $contactId = $Me->cid;
    saveWatchPreference($prow->paperId, $contactId, WATCHTYPE_COMMENT, defval($_REQUEST, "watch"));
    if ($OK)
        $Conf->confirmMsg("Saved");
    if ($ajax)
        $Conf->ajaxExit(array("ok" => $OK));
}


// update comment action
function saveComment($text, $is_response) {
    global $Me, $Conf, $prow, $crow;
    $req = array("visibility" => @$_REQUEST["visibility"],
                 "submit" => $is_response && @$_REQUEST["submitresponse"],
                 "text" => $text,
                 "tags" => @$_REQUEST["commenttags"],
                 "blind" => @$_REQUEST["blind"]);
    $next_crow = CommentSave::save($req, $prow, $crow, $Me, $is_response);
    if ($next_crow === false) {
        if ($is_response) {
            $q = ($Conf->sversion >= 53 ? "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0" : "forAuthors>1");
            $result = $Conf->qe("select commentId from PaperComment where paperId=$prow->paperId and $q");
            if (($row = edb_row($result)))
                return $Conf->errorMsg("A paper response has already been entered.  <a href=\"" . hoturl("comment", "c=$row[0]") . "\">Edit that response</a>");
        }
        return false;
    }

    $what = ($is_response ? "Response" : "Comment");
    if ($next_crow && $is_response && ($next_crow->commentType & COMMENTTYPE_DRAFT)) {
        $deadline = $Conf->printableTimeSetting("resp_done");
        if ($deadline != "N/A")
            $extratext = "  You have until $deadline to submit the response.";
        else
            $extratext = "";
        $Conf->save_session_array("comment_msgs", $next_crow->commentId,
                                  "<div class='xwarning'>$what saved. <strong>This draft response will not be shown to reviewers.</strong>$extratext</div>");
    } else if ($next_crow)
        $Conf->save_session_array("comment_msgs", $next_crow->commentId,
                                  "<div class='xconfirm'>$what submitted.</div>");
    else
        $Conf->confirmMsg("$what deleted.");

    if ($next_crow)
        redirectSelf(array("anchor" => "comment$next_crow->commentId",
                           "noedit" => null, "c" => null));
    else
        redirectSelf(array("c" => null));
    // NB normally redirectSelf() does not return
    loadRows();
    return true;
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
        saveComment($text, true);
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
        saveComment("", ($crow->commentType & COMMENTTYPE_RESPONSE) != 0);
} else if (isset($_REQUEST["cancel"]) && $crow)
    $_REQUEST["noedit"] = 1;


// paper actions
if ((isset($_REQUEST["settags"]) || isset($_REQUEST["settingtags"])) && check_post()) {
    PaperActions::setTags($prow);
    loadRows();
}


// can we view/edit reviews?
$viewAny = $Me->canViewReview($prow, null, null, $whyNotView);
$editAny = $Me->canReview($prow, null, $whyNotEdit);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (!$Me->canViewPaper($prow, $whyNotPaper))
        errorMsgExit(whyNotText($whyNotPaper, "view"));
    if (!isset($_REQUEST["reviewId"]) && !isset($_REQUEST["ls"])) {
        $Conf->errorMsg("You can’t see the reviews for this paper.  " . whyNotText($whyNotView, "review"));
        go(hoturl("paper", "p=$prow->paperId"));
    }
}


// mode
if ($paperTable->mode == "r" || $paperTable->mode == "re")
    $paperTable->fixReviewMode();
if ($paperTable->mode == "pe")
    go(hoturl("paper", array("p" => $prow->paperId, "ls" => @$_REQUEST["ls"])));


// page header
confHeader();


// paper table
$paperTable->initialize(false, false);
$paperTable->paptabBegin();

if (!$viewAny && !$editAny
    && (!$paperTable->rrow
        || !$Me->canViewReview($prow, $paperTable->rrow, null)))
    $paperTable->paptabEndWithReviewMessage();
else if ($paperTable->mode == "r" && !$paperTable->rrow)
    $paperTable->paptabEndWithReviews();
else
    $paperTable->paptabEndWithEditableReview();

$paperTable->paptabComments();

$Conf->footer();
