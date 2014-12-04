<?php
// comment.php -- HotCRP paper comment display/edit page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = $Warning = array();
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
    if ($cid != "xxx" && !$crow && $cid != "response" && $cid != "new" && $cid != "newresponse")
        errorMsgExit("That comment does not exist.");
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
        $Error = array();
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  The file was ignored.");


// update comment action
function saveComment($text, $is_response) {
    global $Me, $Conf, $prow, $crow;
    $req = array("visibility" => @$_REQUEST["visibility"],
                 "submit" => $is_response && @$_REQUEST["submitresponse"],
                 "text" => $text,
                 "tags" => @$_REQUEST["commenttags"],
                 "blind" => @$_REQUEST["blind"]);
    $next_crow = CommentSave::save($req, $prow, $crow, $Me, $is_response);
    $what = ($is_response ? "Response" : "Comment");

    $confirm = false;
    if ($next_crow === false && $is_response) {
        $crows = $Conf->comment_rows($Conf->comment_query("paperId=$prow->paperId and (commentType&" . COMMENTTYPE_RESPONSE . ")!=0"), $Me);
        reset($crows);
        $cur_response = @current($crows);
        if ($cur_response && $cur_response->comment == $text)
            $next_crow = $cur_response;
        else
            $confirm = '<div class="xmerror">A response was entered concurrently by another user. Reload to see it.</div>';
    }
    if ($next_crow === false)
        /* nada */;
    else if ($next_crow && $is_response && ($next_crow->commentType & COMMENTTYPE_DRAFT)) {
        $deadline = $Conf->printableTimeSetting("resp_done");
        if ($deadline != "N/A")
            $extratext = " You have until $deadline to submit the response.";
        else
            $extratext = "";
        $confirm = "<div class='xwarning'>$what saved. <strong>This draft response will not be shown to reviewers.</strong>$extratext</div>";
    } else if ($next_crow)
        $confirm = "<div class='xconfirm'>$what submitted.</div>";
    else if (@$_REQUEST["ajax"])
        $confirm = "<div class='xconfirm'>$what deleted.</div>";
    else
        $Conf->confirmMsg("$what deleted.");

    if (@$_REQUEST["ajax"]) {
        $j = array("ok" => $next_crow !== false);
        if (@$next_crow) {
            $cv = new CommentView;
            $j["cmt"] = $cv->json($prow, $next_crow);
        }
        if ($confirm)
            $j["msg"] = $confirm;
        $Conf->ajaxExit($j);
    } else {
        if ($confirm && $next_crow)
            $Conf->save_session_array("comment_msgs", $next_crow->commentId, $confirm);
        $x = array("p" => $prow->paperId, "c" => null, "noedit" => null, "ls" => @$_REQUEST["ls"]);
        if ($next_crow)
            $x["anchor"] = "comment$next_crow->commentId";
        go(hoturl("paper", $x));
    }
}

if (!check_post())
    /* do nothing */;
else if ((@$_REQUEST["submitcomment"] || @$_REQUEST["submitresponse"] || @$_REQUEST["savedraftresponse"])
         && @$_REQUEST["response"]) {
    $text = @rtrim($_REQUEST["comment"]);
    if (!$Me->canRespond($prow, $crow, $whyNot, true))
        $Conf->errorMsg(whyNotText($whyNot, "respond to reviews for"));
    else if ($text === "" && !$crow)
        $Conf->errorMsg("Enter a comment.");
    else
        saveComment($text, true);
    if (@$_REQUEST["ajax"])
        $Conf->ajaxExit(array("ok" => false));
} else if (@$_REQUEST["submitcomment"]) {
    $text = @rtrim($_REQUEST["comment"]);
    if (!$Me->canSubmitComment($prow, $crow, $whyNot))
        $Conf->errorMsg(whyNotText($whyNot, "comment on"));
    else if ($text === "" && !$crow)
        $Conf->errorMsg("Enter a comment.");
    else
        saveComment($text, false);
    if (@$_REQUEST["ajax"])
        $Conf->ajaxExit(array("ok" => false));
} else if ((@$_REQUEST["deletecomment"] || @$_REQUEST["deleteresponse"]) && $crow) {
    if (!$Me->canSubmitComment($prow, $crow, $whyNot))
        $Conf->errorMsg(whyNotText($whyNot, "comment on"));
    else
        saveComment("", ($crow->commentType & COMMENTTYPE_RESPONSE) != 0);
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
