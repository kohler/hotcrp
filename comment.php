<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = array();
require_once("src/initweb.php");
require_once("src/papertable.php");
if (!$Me->email)
    $Me->escape();


// header
function exit_to_paper() {
    global $prow;
    go(hoturl("paper", ["p" => $prow ? $prow->paperId : req("p"),
                        "c" => req("c"), "response" => req("response")]));
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $prow, $paperTable, $crow, $Error;
    $Conf->paper = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        exit_to_paper();
    $paperTable = new PaperTable($prow, make_qreq());
    $paperTable->resolveReview(false);
    $paperTable->resolveComments();

    $cid = defval($_GET, "commentId", "xxx");
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
        $Conf->ajaxExit(array("ok" => false));
    }
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
        $Error = array();
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->post_missing_msg();


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
        $user = $Conf->user_by_id($cid);

    $req = array("visibility" => req("visibility"),
                 "submit" => $is_response && !req("draft"),
                 "text" => $text,
                 "tags" => req("commenttags"),
                 "blind" => req("blind"));
    if ($is_response && !$crow)
        $cinfo = new CommentInfo((object) array("commentType" => COMMENTTYPE_RESPONSE,
                                                "commentRound" => $roundnum), $prow);
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
    $Conf->ajaxExit($j);
}

function handle_response() {
    global $Conf, $Me, $prow, $crow;
    $rname = trim((string) req("response"));
    $rnum = $Conf->resp_round_number($rname);
    if ($rnum === false && $rname)
        return Conf::msg_error("No such response round “" . htmlspecialchars($rname) . "”.");
    $rnum = (int) $rnum;
    if ($crow && (int) get($crow, "commentRound") !== $rnum) {
        $Conf->warnMsg("Attempt to change response round ignored.");
        $rnum = (int) get($crow, "commentRound");
    }

    if (!($xcrow = $crow))
        $xcrow = (object) array("commentType" => COMMENTTYPE_RESPONSE,
                                "commentRound" => $rnum);
    if (($whyNot = $Me->perm_respond($prow, $xcrow, true)))
        return Conf::msg_error(whyNotText($whyNot, "respond to reviews for"));

    $text = rtrim((string) req("comment"));
    if ($text === "" && !$crow)
        return Conf::msg_error("Enter a response.");

    save_comment($text, true, $rnum);
}

if (req("savedraftresponse"))
    $_POST["draft"] = $_REQUEST["draft"] = 1;
if (req("savedraftresponse") || req("submitresponse"))
    $_GET["submitcomment"] = $_REQUEST["submitcomment"] = 1;

if (!check_post())
    /* do nothing */;
else if (req("submitcomment") && req("response")) {
    handle_response();
    if (req("ajax"))
        $Conf->ajaxExit(array("ok" => false));
} else if (req("submitcomment")) {
    $text = rtrim((string) req("comment"));
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        Conf::msg_error(whyNotText($whyNot, "comment on"));
    else if ($text === "" && !$crow)
        Conf::msg_error("Enter a comment.");
    else
        save_comment($text, false, 0);
    if (req("ajax"))
        $Conf->ajaxExit(array("ok" => false));
} else if ((req("deletecomment") || req("deleteresponse")) && $crow) {
    if (($whyNot = $Me->perm_submit_comment($prow, $crow)))
        Conf::msg_error(whyNotText($whyNot, "comment on"));
    else
        save_comment("", ($crow->commentType & COMMENTTYPE_RESPONSE) != 0, $crow->commentRound);
    if (req("ajax"))
        $Conf->ajaxExit(array("ok" => false));
} else if (req("cancel") && $crow)
    $_REQUEST["noedit"] = $_GET["noedit"] = $_POST["noedit"] = 1;


exit_to_paper();
