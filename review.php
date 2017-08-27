<?php
// review.php -- HotCRP paper review display/edit page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$Error = array();
require_once("src/initweb.php");
require_once("src/papertable.php");

// special case: if "accept" or "refuse" is set, and "email" and "password"
// are both set, vector through the signin page
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])
    && (isset($_REQUEST["accept"]) || isset($_REQUEST["refuse"])
        || isset($_REQUEST["decline"]))) {
    PaperTable::cleanRequest();
    $after = "";
    foreach (array("paperId" => "p", "pap" => "p", "reviewId" => "r", "commentId" => "c") as $k => $v)
        if (isset($_REQUEST[$k]) && !isset($_REQUEST[$v]))
            $_REQUEST[$v] = $_GET[$v] = $_POST[$v] = $_REQUEST[$k];
    foreach (array("p", "r", "c", "accept", "refuse", "decline") as $opt)
        if (isset($_REQUEST[$opt]))
            $after .= ($after === "" ? "" : "&") . $opt . "=" . urlencode($_REQUEST[$opt]);
    $url = hoturl_site_relative_raw("review", $after);
    go(hoturl("index", "email=" . urlencode($_REQUEST["email"]) . "&password=" . urlencode($_REQUEST["password"]) . "&go=" . urlencode($url)));
}

if ($Me->is_empty())
    $Me->escape();
$rf = $Conf->review_form();


// header
function confHeader() {
    global $paperTable;
    PaperTable::do_header($paperTable, "review", req("mode"));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    Ht::stash_script("shortcut().add()");
    $msg && Conf::msg_error($msg);
    Conf::$g->footer();
    exit;
}


// collect paper ID
function loadRows() {
    global $Conf, $Me, $prow, $paperTable, $editRrowLogname, $Error;
    $Conf->paper = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot, "view", true));
    $paperTable = new PaperTable($prow, make_qreq());
    $paperTable->resolveReview(true);

    if ($paperTable->editrrow && $paperTable->editrrow->contactId == $Me->contactId)
        $editRrowLogname = "Review " . $paperTable->editrrow->reviewId;
    else if ($paperTable->editrrow)
        $editRrowLogname = "Review " . $paperTable->editrrow->reviewId . " by " . $paperTable->editrrow->email;
    if (isset($Error["paperId"]) && $Error["paperId"] != $prow->paperId)
        $Error = array();
}

loadRows();


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->post_missing_msg();
else if (isset($_REQUEST["post"]) && isset($_REQUEST["default"])) {
    if (file_uploaded($_FILES["uploadedFile"]))
        $_REQUEST["uploadForm"] = $_GET["uploadForm"] = $_POST["uploadForm"] = 1;
    else
        $_REQUEST["update"] = $_GET["update"] = $_POST["update"] = 1;
} else if (isset($_REQUEST["submitreview"]))
    $_REQUEST["update"] = $_REQUEST["ready"] = $_POST["update"] = $_POST["ready"] = 1;
else if (isset($_REQUEST["savedraft"])) {
    $_REQUEST["update"] = $_POST["update"] = 1;
    unset($_REQUEST["ready"], $_POST["ready"]);
}


// upload review form action
if (isset($_REQUEST["uploadForm"])
    && file_uploaded($_FILES['uploadedFile'])
    && check_post()) {
    // parse form, store reviews
    $tf = ReviewValues::make_text($rf, file_get_contents($_FILES["uploadedFile"]["tmp_name"]),
            $_FILES["uploadedFile"]["name"]);
    if ($tf->parse_text(req("override")))
        $tf->check_and_save($Me, $prow, $paperTable->editrrow);
    if (!$tf->has_error() && $tf->parse_text(req("override")))
        $tf->msg(null, "Only the first review form in the file was parsed. <a href='" . hoturl("offline") . "'>Upload multiple-review files here.</a>", MessageSet::WARNING);
    $tf->report();
    loadRows();
} else if (isset($_REQUEST["uploadForm"]))
    Conf::msg_error("Select a review form to upload.");


// check review submit requirements
if (isset($_REQUEST["unsubmitreview"]) && $paperTable->editrrow
    && $paperTable->editrrow->reviewSubmitted && $Me->can_administer($prow)
    && check_post()) {
    Dbl::qe_raw("lock tables PaperReview write");
    $result = $Me->unsubmit_review_row($paperTable->editrrow);
    Dbl::qe_raw("unlock tables");
    if ($result) {
        $Me->log_activity("$editRrowLogname unsubmitted", $prow);
        $Conf->confirmMsg("Unsubmitted review.");
    }
    redirectSelf();             // normally does not return
    loadRows();
} else if (isset($_REQUEST["update"]) && $paperTable->editrrow
           && $paperTable->editrrow->reviewSubmitted)
    $_REQUEST["ready"] = $_POST["ready"] = 1;


// review rating action
if (isset($_REQUEST["rating"]) && $paperTable->rrow && check_post()) {
    if (!$Me->can_rate_review($prow, $paperTable->rrow)
        || !$Me->can_view_review($prow, $paperTable->rrow, null))
        Conf::msg_error("You can’t rate that review.");
    else if (!isset(ReviewForm::$rating_types[$_REQUEST["rating"]]))
        Conf::msg_error("Invalid rating.");
    else if ($_REQUEST["rating"] == "n")
        $Conf->qe("delete from ReviewRating where paperId=? and reviewId=? and contactId=?",
                  $paperTable->prow->paperId, $paperTable->rrow->reviewId, $Me->contactId);
    else
        $Conf->qe("insert into ReviewRating set paperId=?, reviewId=?, contactId=?, rating=? on duplicate key update rating=?",
                  $paperTable->prow->paperId, $paperTable->rrow->reviewId, $Me->contactId, $_REQUEST["rating"], $_REQUEST["rating"]);
    if (defval($_REQUEST, "ajax", 0))
        $Conf->ajaxExit(["ok" => !Dbl::has_error(), "result" => "Thanks! Your feedback has been recorded."]);
    if (isset($_REQUEST["allr"])) {
        $_REQUEST["paperId"] = $_GET["paperId"] = $_POST["paperId"] = $paperTable->rrow->paperId;
        unset($_REQUEST["reviewId"], $_GET["reviewId"], $_POST["reviewId"]);
        unset($_REQUEST["r"], $_GET["r"], $_POST["r"]);
    }
    loadRows();
}


// update review action
if (isset($_REQUEST["update"]) && check_post()) {
    $tf = new ReviewValues($rf);
    $tf->paperId = $prow->paperId;
    if (($whyNot = $Me->perm_submit_review($prow, $paperTable->editrrow)))
        $tf->msg(null, whyNotText($whyNot, "review"), MessageSet::ERROR);
    else if ($tf->parse_web(make_qreq(), req("forceShow"))
             && $tf->check_and_save($Me, $prow, $paperTable->editrrow)
             && !$tf->has_problem_at("ready")) {
        $tf->report();
        redirectSelf(); // normally does not return
    }
    loadRows();
    $tf->report();
    $paperTable->set_review_values($tf);
}


// delete review action
if (isset($_REQUEST["deletereview"]) && check_post()
    && $Me->can_administer($prow))
    if (!$paperTable->editrrow)
        Conf::msg_error("No review to delete.");
    else {
        $result = Dbl::qe("delete from PaperReview where paperId=? and reviewId=?", $prow->paperId, $paperTable->editrrow->reviewId);
        if ($result) {
            $Me->log_activity("$editRrowLogname deleted", $prow);
            $Conf->confirmMsg("Deleted review.");
            if ($paperTable->editrrow->reviewToken != 0)
                $Conf->update_rev_tokens_setting(-1);

            // perhaps a delegatee needs to redelegate
            if ($paperTable->editrrow->reviewType < REVIEW_SECONDARY && $paperTable->editrrow->requestedBy > 0)
                $Me->update_review_delegation($paperTable->editrrow->paperId, $paperTable->editrrow->requestedBy, -1);

            unset($_REQUEST["reviewId"], $_GET["reviewId"], $_POST["reviewId"]);
            unset($_REQUEST["r"], $_GET["r"], $_POST["r"]);
            $_REQUEST["paperId"] = $_GET["paperId"] = $paperTable->editrrow->paperId;
            go(hoturl("paper", ["p" => $_GET["paperId"]]));
        }
        redirectSelf();         // normally does not return
        loadRows();
    }


// download review form action
function downloadForm($editable) {
    global $rf, $Conf, $Me, $prow, $paperTable;
    $rrow = $paperTable->rrow;
    $use_request = (!$rrow || $rrow->contactId == $Me->contactId)
        && $prow->review_type($Me) > 0;
    $text = $rf->textFormHeader(false) . $rf->textForm($prow, $rrow, $Me, $use_request ? $_REQUEST : null);
    $filename = "review-{$prow->paperId}";
    if ($rrow && $rrow->reviewOrdinal)
        $filename .= unparseReviewOrdinal($rrow->reviewOrdinal);
    downloadText($text, $filename, false);
}

if (isset($_REQUEST["downloadForm"]))
    downloadForm(true);


function download_all_text_reviews() {
    global $rf, $Conf, $Me, $prow, $paperTable;
    $lastrc = null;
    $text = "";
    foreach ($prow->viewable_submitted_reviews_and_comments($Me, null) as $rc) {
        $text .= PaperInfo::review_or_comment_text_separator($lastrc, $rc);
        if (isset($rc->reviewId))
            $text .= $rf->pretty_text($prow, $rc, $Me, false, true);
        else
            $text .= $rc->unparse_text($Me, true);
        $lastrc = $rc;
    }
    if ($text === "") {
        $whyNot = $Me->perm_view_review($prow, null, null);
        return Conf::msg_error(whyNotText($whyNot ? : array("fail" => 1), "review"));
    }
    $text = $Conf->short_name . " Paper #2 Reviews and Comments\n"
        . str_repeat("=", 75) . "\n"
        . prefix_word_wrap("", "Paper #{$prow->paperId} {$prow->title}", 0, 75)
        . "\n\n" . $text;
    downloadText($text, "reviews-{$prow->paperId}", true);
}

function download_one_text_review(ReviewInfo $rrow) {
    global $rf, $Conf, $Me, $prow, $paperTable;
    $filename = "review-{$prow->paperId}";
    if ($rrow->reviewOrdinal)
        $filename .= unparseReviewOrdinal($rrow->reviewOrdinal);
    downloadText($rf->pretty_text($prow, $rrow, $Me), $filename, true);
}

if (isset($_REQUEST["text"])) {
    if ($paperTable->rrow)
        download_one_text_review($paperTable->rrow);
    else
        download_all_text_reviews();
}


// refuse review action
function refuseReview() {
    global $Conf, $Me, $prow, $paperTable;

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write");

    $rrow = $paperTable->editrrow;
    $hadToken = defval($rrow, "reviewToken", 0) != 0;

    $result = Dbl::qe("delete from PaperReview where paperId=? and reviewId=?", $prow->paperId, $rrow->reviewId);
    if (!$result)
        return;
    $reason = defval($_REQUEST, "reason", "");
    if ($reason == "Optional explanation")
        $reason = "";
    $result = Dbl::qe("insert into PaperReviewRefused set paperId=?, contactId=?, requestedBy=?, reason=?", $rrow->paperId, $rrow->contactId, $rrow->requestedBy, trim($reason));
    if (!$result)
        return;

    // now the requester must potentially complete their review
    if ($rrow->reviewType < REVIEW_SECONDARY && $rrow->requestedBy > 0)
        $Me->update_review_delegation($rrow->paperId, $rrow->requestedBy, -1);

    Dbl::qe_raw("unlock tables");

    // send confirmation email
    $Requester = $Conf->user_by_id($rrow->requestedBy);
    $reqprow = $Conf->paperRow($prow->paperId, $Requester);
    HotCRPMailer::send_to($Requester, "@refusereviewrequest", $reqprow,
                          array("reviewer_contact" => $rrow,
                                "reason" => $reason));

    // confirmation message
    $Conf->confirmMsg("The request that you review paper #$prow->paperId has been removed.  Mail was sent to the person who originally requested the review.");
    if ($hadToken)
        $Conf->update_rev_tokens_setting(-1);

    $prow = null;
    confHeader();
    $Conf->footer();
    exit;
}

if (isset($_REQUEST["refuse"]) || isset($_REQUEST["decline"])) {
    if (!$paperTable->editrrow
        || (!$Me->is_my_review($paperTable->editrrow) && !$Me->can_administer($prow)))
        Conf::msg_error("This review was not assigned to you, so you can’t decline it.");
    else if ($paperTable->editrrow->reviewType >= REVIEW_SECONDARY)
        Conf::msg_error("PC members can’t decline their primary or secondary reviews.  Contact the PC chairs directly if you really cannot finish this review.");
    else if ($paperTable->editrrow->reviewSubmitted)
        Conf::msg_error("This review has already been submitted; you can’t decline it now.");
    else if (defval($_REQUEST, "refuse") == "1"
             || defval($_REQUEST, "decline") == "1") {
        $Conf->confirmMsg("<p>Select “Decline review” to decline this review (you may enter a brief explanation, if you’d like). Thank you for telling us that you cannot complete your review.</p><div class='g'></div><form method='post' action=\"" . hoturl_post("review", "p=" . $paperTable->prow->paperId . "&amp;r=" . $paperTable->editrrow->reviewId) . "\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='aahc'>"
                          . Ht::hidden("refuse", "refuse") . "  "
                          . Ht::textarea("reason", "", array("rows" => 3, "cols" => 40, "spellcheck" => "true"))
                          . "\n  <span class='sep'></span>"
                          . Ht::submit("Decline review")
                          . "</div></form>");
    } else {
        refuseReview();
        Dbl::qe_raw("unlock tables");
        loadRows();
    }
}

if (isset($_REQUEST["accept"])) {
    if (!$paperTable->editrrow
        || (!$Me->is_my_review($paperTable->editrrow) && !$Me->can_administer($prow)))
        Conf::msg_error("This review was not assigned to you, so you cannot confirm your intention to write it.");
    else {
        if ($paperTable->editrrow->reviewModified <= 0)
            Dbl::qe("update PaperReview set reviewModified=1 where paperId=? and reviewId=? and coalesce(reviewModified,0)<=0", $prow->paperId, $paperTable->editrrow->reviewId);
        $Conf->confirmMsg("Thank you for confirming your intention to finish this review.  You can download the paper and review form below.");
        loadRows();
    }
}


// can we view/edit reviews?
$viewAny = $Me->can_view_review($prow, null, null);
$editAny = $Me->can_review($prow, null);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (($whyNotPaper = $Me->perm_view_paper($prow)))
        errorMsgExit(whyNotText($whyNotPaper, "view", true));
    if (req("reviewId") === null) {
        Conf::msg_error("You can’t see the reviews for this paper. "
                        . whyNotText($Me->perm_view_review($prow, null, null), "review"));
        go(hoturl("paper", "p=$prow->paperId"));
    }
}


// mode
$paperTable->fixReviewMode();
if ($paperTable->mode == "edit")
    go(hoturl("paper", ["p" => $prow->paperId]));


// paper table
confHeader();

$paperTable->initialize(false, false);
$paperTable->paptabBegin();
$paperTable->resolveComments();

if (!$viewAny && !$editAny
    && (!$paperTable->rrow
        || !$Me->can_view_review($prow, $paperTable->rrow, null)))
    $paperTable->paptabEndWithReviewMessage();
else {
    if ($paperTable->mode === "re") {
        $paperTable->paptabEndWithEditableReview();
        $paperTable->paptabComments();
    } else
        $paperTable->paptabEndWithReviewsAndComments();
}

$Conf->footer();
