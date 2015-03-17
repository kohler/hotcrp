<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = array();
require_once("src/initweb.php");
require_once("src/papertable.php");
if ($Me->is_empty())
    $Me->escape();


// header
function confHeader() {
    global $prow, $mode, $Conf;
    if ($prow)
        $title = "Paper #$prow->paperId";
    else
        $title = "Paper Comments";
    $Conf->header($title, "comment", actionBar(null, $prow), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->footerScript("shortcut().add()");
    $Conf->errorMsgExit($msg);
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $CurrentProw, $prow, $paperTable, $crow, $Error;
    $CurrentProw = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot, "view"));
    $paperTable = new PaperTable($prow);
    $paperTable->resolveReview();
    $paperTable->resolveComments();

    $cid = defval($_REQUEST, "commentId", "xxx");
    $crow = null;
    foreach ($paperTable->crows as $row) {
        if ($row->commentId == $cid
            || ($cid == "response" && ($row->commentType & COMMENTTYPE_RESPONSE)))
            $crow = $row;
    }
    if (!$crow && $cid != "xxx" && $cid != "new"
        /* following are obsolete */
        && $cid != "response" && $cid != "newresponse")
        errorMsgExit("That comment does not exist.");
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
        $Error = array();
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// update comment action
function save_comment($text, $is_response, $roundnum) {
    global $Me, $Conf, $prow, $crow;
    if ($crow)
        $roundnum = (int) $crow->commentRound;

    // If I have a review token for this paper, save under that anonymous user.
    $user = $Me;
    if ((!$crow || $crow->contactId != $Me->contactId)
        && ($cid = $Me->review_token_cid($prow))
        && (!$crow || $crow->contactId == $cid))
        $user = Contact::find_by_id($cid);

    $req = array("visibility" => @$_REQUEST["visibility"],
                 "submit" => $is_response && @$_REQUEST["submitresponse"],
                 "text" => $text,
                 "tags" => @$_REQUEST["commenttags"],
                 "blind" => @$_REQUEST["blind"]);
    if ($is_response && !$crow)
        $cinfo = new CommentInfo((object) array("commentType" => COMMENTTYPE_RESPONSE,
                                                "commentRound" => $roundnum), $prow);
    else
        $cinfo = new CommentInfo($crow, $prow);
    $ok = $cinfo->save($req, $user);
    $what = ($is_response ? "Response" : "Comment");

    $confirm = false;
    if (!$ok && $is_response) {
        $crows = $Conf->comment_rows($Conf->comment_query("paperId=$prow->paperId and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$roundnum"), $Me);
        reset($crows);
        $cur_response = @current($crows);
        if ($cur_response && $cur_response->comment == $text) {
            $cinfo = new CommentInfo($cur_response, $prow);
            $ok = true;
        } else
            $confirm = '<div class="xmsg xmerror">A response was entered concurrently by another user. Reload to see it.</div>';
    }
    if (!$ok)
        /* nada */;
    else if ($is_response && (!$cinfo->commentId || ($cinfo->commentType & COMMENTTYPE_DRAFT))) {
        $confirm = '<div class="xmsg xwarning">';
        if ($cinfo->commentId)
            $confirm .= 'Response saved. <strong>This draft response will not be shown to reviewers.</strong>';
        else
            $confirm .= 'Response deleted.';
        $isuf = $roundnum ? "_$roundnum" : "";
        if (($dl = $Conf->printableTimeSetting("resp_done$isuf")) != "N/A")
            $confirm .= " You have until $dl to submit the response.";
        $confirm .= '</div>';
    } else if ($is_response) {
        $rname = $Conf->resp_round_text($roundnum);
        $confirm = '<div class="xmsg xconfirm">' . ($rname ? "$rname response" : "Response") . ' submitted.</div>';
    } else if ($cinfo->commentId)
        $confirm = '<div class="xmsg xconfirm">Comment saved.</div>';
    else
        $confirm = '<div class="xmsg xconfirm">Comment deleted.</div>';

    $j = array("ok" => $ok);
    if ($cinfo->commentId)
        $j["cmt"] = $cinfo->unparse_json($Me);
    if ($confirm)
        $j["msg"] = $confirm;
    $Conf->ajaxExit($j);
}

function handle_response() {
    global $Conf, $Me, $prow, $crow;
    $rname = @trim($_REQUEST["response"]);
    $rnum = $Conf->resp_round_number($rname);
    if ($rnum === false && $rname)
        return $Conf->errorMsg("No such response round “" . htmlspecialchars($rname) . "”.");
    $rnum = (int) $rnum;
    if ($crow && @(int) $crow->commentRound !== $rnum) {
        $Conf->warnMsg("Attempt to change response round ignored.");
        $rnum = @+$crow->commentRound;
    }

    if (!($xcrow = $crow))
        $xcrow = (object) array("commentType" => COMMENTTYPE_RESPONSE,
                                "commentRound" => $rnum);
    if (($whyNot = $Me->perm_respond($prow, $xcrow, true)))
        return $Conf->errorMsg(whyNotText($whyNot, "respond to reviews for"));

    $text = @rtrim($_REQUEST["comment"]);
    if ($text === "" && !$crow)
        return $Conf->errorMsg("Enter a response.");

    save_comment($text, true, $rnum);
}


if (!check_post())
    /* do nothing */;
else if ((@$_REQUEST["submitcomment"] || @$_REQUEST["submitresponse"] || @$_REQUEST["savedraftresponse"])
         && @$_REQUEST["response"]) {
    handle_response();
    if (@$_REQUEST["ajax"])
        $Conf->ajaxExit(array("ok" => false));
} else if (@$_REQUEST["submitcomment"]) {
    $text = @rtrim($_REQUEST["comment"]);
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        $Conf->errorMsg(whyNotText($whyNot, "comment on"));
    else if ($text === "" && !$crow)
        $Conf->errorMsg("Enter a comment.");
    else
        save_comment($text, false, 0);
    if (@$_REQUEST["ajax"])
        $Conf->ajaxExit(array("ok" => false));
} else if ((@$_REQUEST["deletecomment"] || @$_REQUEST["deleteresponse"]) && $crow) {
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        $Conf->errorMsg(whyNotText($whyNot, "comment on"));
    else
        save_comment("", ($crow->commentType & COMMENTTYPE_RESPONSE) != 0, $crow->commentRound);
    if (@$_REQUEST["ajax"])
        $Conf->ajaxExit(array("ok" => false));
} else if (@$_REQUEST["cancel"] && $crow)
    $_REQUEST["noedit"] = 1;


// paper actions
if (isset($_REQUEST["settags"]) && check_post()) {
    PaperActions::setTags($prow);
    loadRows();
}


go(hoturl("paper", array("p" => $prow->paperId,
                         "c" => @$_REQUEST["c"],
                         "ls" => @$_REQUEST["ls"])));
