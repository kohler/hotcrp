<?php
// comment.php -- HotCRP paper comment display/edit page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");
if (!$Me->email)
    $Me->escape();


// header
function exit_to_paper() {
    global $prow, $Qreq;
    go(hoturl("paper", ["p" => $prow ? $prow->paperId : $Qreq->p,
                        "c" => $Qreq->c, "response" => $Qreq->response]));
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $Qreq, $prow, $paperTable, $crow;
    $Conf->paper = $prow = PaperTable::paperRow($Qreq, $whyNot);
    if (!$prow)
        exit_to_paper();
    $paperTable = new PaperTable($prow, $Qreq);
    $paperTable->resolveReview(false);
    $paperTable->resolveComments();

    $cid = $Qreq->get("commentId", "xxx");
    $crow = null;
    foreach ($paperTable->crows as $row) {
        if ($row->commentId == $cid
            || ($cid == "response" && ($row->commentType & COMMENTTYPE_RESPONSE)))
            $crow = $row;
    }
    if (!$crow && $cid != "xxx" && $cid != "new"
        /* following are obsolete */
        && $cid != "response" && $cid != "newresponse") {
        Conf::msg_error("No such comment.");
        json_exit(["ok" => false]);
    }
}

loadRows();


// general error messages
if ($Qreq->post && $Qreq->post_empty())
    $Conf->post_missing_msg();


// update comment action
function save_comment($qreq, $text, $is_response, $roundnum) {
    global $Me, $Conf, $prow, $crow;
    if ($crow)
        $roundnum = (int) $crow->commentRound;

    // If I have a review token for this paper, save under that anonymous user.
    $user = $Me;
    if (($token = $qreq->review_token)
        && ($token = decode_token($token, "V"))
        && in_array($token, $Me->review_tokens())
        && ($rrow = $prow->review_of_token($token)))
        $user = $Conf->user_by_id($rrow->contactId);

    $req = ["visibility" => $qreq->visibility,
            "submit" => $is_response && !$qreq->draft,
            "text" => $text,
            "tags" => $qreq->commenttags,
            "blind" => $qreq->blind];
    if ($is_response && !$crow)
        $cinfo = CommentInfo::make_response_template($roundnum, $prow);
    else
        $cinfo = new CommentInfo($crow, $prow);
    $ok = $cinfo->save($req, $user);
    $what = ($is_response ? "Response" : "Comment");

    $confirm = false;
    if (!$ok && $is_response) {
        $crows = $prow->fetch_comments("(commentType&" . COMMENTTYPE_RESPONSE . ")!=0 and commentRound=$roundnum");
        reset($crows);
        $cur_response = empty($crows) ? null : current($crows);
        if ($cur_response && $cur_response->comment == $text) {
            $cinfo = new CommentInfo($cur_response, $prow);
            $ok = true;
        } else
            $confirm = Ht::xmsg("error", "A response was entered concurrently by another user. Reload to see it.");
    }
    if (!$ok)
        /* nada */;
    else if ($is_response && (!$cinfo->commentId || ($cinfo->commentType & COMMENTTYPE_DRAFT))) {
        if ($cinfo->commentId)
            $confirm = 'Response saved. <strong>This draft response will not be shown to reviewers.</strong>';
        else
            $confirm = 'Response deleted.';
        $isuf = $roundnum ? "_$roundnum" : "";
        if (($dl = $Conf->printableTimeSetting("resp_done$isuf")) != "N/A")
            $confirm .= " You have until $dl to submit the response.";
        $confirm = Ht::xmsg("warning", $confirm);
    } else if ($is_response) {
        $rname = $Conf->resp_round_text($roundnum);
        $confirm = Ht::xmsg("confirm", ($rname ? "$rname response" : "Response") . ' submitted.');
    } else if ($cinfo->commentId)
        $confirm = Ht::xmsg("confirm", "Comment saved.");
    else
        $confirm = Ht::xmsg("confirm", "Comment deleted.");

    $j = array("ok" => $ok);
    if ($cinfo->commentId)
        $j["cmt"] = $cinfo->unparse_json($Me);
    if ($confirm)
        $j["msg"] = $confirm;
    json_exit($j);
}

function handle_response($qreq) {
    global $Conf, $Me, $prow, $crow;
    $rname = trim((string) $qreq->response);
    $rnum = $Conf->resp_round_number($rname);
    if ($rnum === false && $rname)
        return Conf::msg_error("No such response round “" . htmlspecialchars($rname) . "”.");
    $rnum = (int) $rnum;
    if ($crow && (int) get($crow, "commentRound") !== $rnum) {
        $Conf->warnMsg("Attempt to change response round ignored.");
        $rnum = (int) get($crow, "commentRound");
    }

    if (!($xcrow = $crow))
        $xcrow = CommentInfo::make_response_template($rnum, $prow);
    if (($whyNot = $Me->perm_respond($prow, $xcrow, true)))
        return Conf::msg_error(whyNotText($whyNot));

    $text = rtrim((string) $qreq->comment);
    if ($text === "" && !$crow)
        return Conf::msg_error("Enter a response.");

    save_comment($qreq, $text, true, $rnum);
}

if ($Qreq->savedraftresponse)
    $Qreq->draft = 1;
if ($Qreq->savedraftresponse || $Qreq->submitresponse)
    $Qreq->submitcomment = 1;

if (!$Qreq->post_ok())
    /* do nothing */;
else if ($Qreq->submitcomment && $Qreq->response) {
    handle_response($Qreq);
    if ($Qreq->ajax)
        json_exit(["ok" => false]);
} else if ($Qreq->submitcomment) {
    $text = rtrim((string) $Qreq->comment);
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        Conf::msg_error(whyNotText($whyNot));
    else if ($text === "" && !$crow)
        Conf::msg_error("Enter a comment.");
    else
        save_comment($Qreq, $text, false, 0);
    if ($Qreq->ajax)
        json_exit(["ok" => false]);
} else if (($Qreq->deletecomment || $Qreq->deleteresponse) && $crow) {
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        Conf::msg_error(whyNotText($whyNot));
    else
        save_comment($Qreq, "", ($crow->commentType & COMMENTTYPE_RESPONSE) != 0, $crow->commentRound);
    if ($Qreq->ajax)
        json_exit(["ok" => false]);
} else if ($Qreq->cancel && $crow)
    $Qreq->noedit = 1;


exit_to_paper();
