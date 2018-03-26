<?php
// review.php -- HotCRP paper review display/edit page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");

// special case: if "accept" or "refuse" is set, and "email" and "password"
// are both set, vector through the signin page
if (isset($Qreq->email)
    && isset($Qreq->password)
    && (isset($Qreq->accept) || isset($Qreq->refuse) || isset($Qreq->decline))) {
    PaperTable::clean_request($Qreq);
    $after = "";
    foreach (array("paperId" => "p", "pap" => "p", "reviewId" => "r", "commentId" => "c") as $k => $v) {
        if (isset($Qreq[$k]) && !isset($Qreq[$v]))
            $Qreq[$v] = $Qreq[$k];
    }
    foreach (array("p", "r", "c", "accept", "refuse", "decline") as $opt)
        if (isset($Qreq[$opt]))
            $after .= ($after === "" ? "" : "&") . $opt . "=" . urlencode($Qreq[$opt]);
    $url = hoturl_site_relative_raw("review", $after);
    go(hoturl("index", "email=" . urlencode($Qreq->email) . "&password=" . urlencode($Qreq->password) . "&go=" . urlencode($url)));
}

if ($Me->is_empty())
    $Me->escape();
$rf = $Conf->review_form();


// header
function confHeader() {
    global $paperTable, $Qreq;
    PaperTable::do_header($paperTable, "review", $Qreq->mode, $Qreq);
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
    global $Conf, $Me, $Qreq, $prow, $paperTable, $editRrowLogname;
    $Conf->paper = $prow = PaperTable::paperRow($Qreq, $whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot + ["listViewable" => true]));
    $paperTable = new PaperTable($prow, $Qreq);
    $paperTable->resolveReview(true);

    if ($paperTable->editrrow && $paperTable->editrrow->contactId == $Me->contactId)
        $editRrowLogname = "Review " . $paperTable->editrrow->reviewId;
    else if ($paperTable->editrrow)
        $editRrowLogname = "Review " . $paperTable->editrrow->reviewId . " by " . $paperTable->editrrow->email;
}

loadRows();


// general error messages
if ($Qreq->post && $Qreq->post_empty())
    $Conf->post_missing_msg();
else if ($Qreq->post && $Qreq->default) {
    if ($Qreq->has_file("uploadedFile"))
        $Qreq->uploadForm = 1;
    else
        $Qreq->update = 1;
} else if (isset($Qreq->submitreview))
    $Qreq->update = $Qreq->ready = 1;
else if (isset($Qreq->savedraft)) {
    $Qreq->update = 1;
    unset($Qreq->ready);
}


// upload review form action
if (isset($Qreq->uploadForm)
    && $Qreq->has_file("uploadedFile")
    && $Qreq->post_ok()) {
    // parse form, store reviews
    $tf = ReviewValues::make_text($rf, $Qreq->file_contents("uploadedFile"),
            $Qreq->file_filename("uploadedFile"));
    if ($tf->parse_text($Qreq->override))
        $tf->check_and_save($Me, $prow, $paperTable->editrrow);
    if (!$tf->has_error() && $tf->parse_text($Qreq->override))
        $tf->msg(null, "Only the first review form in the file was parsed. <a href='" . hoturl("offline") . "'>Upload multiple-review files here.</a>", MessageSet::WARNING);
    $tf->report();
    loadRows();
} else if (isset($Qreq->uploadForm))
    Conf::msg_error("Select a review form to upload.");


// check review submit requirements
if (isset($Qreq->unsubmitreview)
    && $paperTable->editrrow
    && $paperTable->editrrow->reviewSubmitted
    && $Me->can_administer($prow)
    && $Qreq->post_ok()) {
    Dbl::qe_raw("lock tables PaperReview write");
    $result = $Me->unsubmit_review_row($paperTable->editrrow);
    Dbl::qe_raw("unlock tables");
    if ($result) {
        $Me->log_activity("$editRrowLogname unsubmitted", $prow);
        $Conf->confirmMsg("Unsubmitted review.");
    }
    SelfHref::redirect($Qreq);             // normally does not return
    loadRows();
} else if (isset($Qreq->update)
           && $paperTable->editrrow
           && $paperTable->editrrow->reviewSubmitted)
    $Qreq->ready = 1;


// update review action
if (isset($Qreq->update) && $Qreq->post_ok()) {
    $tf = new ReviewValues($rf);
    $tf->paperId = $prow->paperId;
    if (($whyNot = $Me->perm_submit_review($prow, $paperTable->editrrow)))
        $tf->msg(null, whyNotText($whyNot), MessageSet::ERROR);
    else if ($tf->parse_web($Qreq, $Qreq->forceShow)
             && $tf->check_and_save($Me, $prow, $paperTable->editrrow)
             && !$tf->has_problem_at("ready")) {
        $tf->report();
        SelfHref::redirect($Qreq); // normally does not return
    }
    loadRows();
    $tf->report();
    $paperTable->set_review_values($tf);
} else if ($Qreq->has_attachment("after_login")) {
    $tf = new ReviewValues($rf);
    $tf->parse_web($Qreq, $Qreq->forceShow);
    $paperTable->set_review_values($tf);
}


// adopt review action
if (isset($Qreq->adoptreview) && $Qreq->post_ok()) {
    $tf = new ReviewValues($rf);
    $tf->paperId = $prow->paperId;
    $my_rrow = $prow->review_of_user($Me);
    if (($whyNot = $Me->perm_submit_review($prow, $my_rrow)))
        $tf->msg(null, whyNotText($whyNot), MessageSet::ERROR);
    else if ($tf->parse_web($Qreq, $Qreq->forceShow)
             && $tf->unset_ready()
             && $tf->check_and_save($Me, $prow, $my_rrow)
             && !$tf->has_problem_at("ready"))
        $tf->report();
    if (($my_rrow = $prow->fresh_review_of_user($Me))) {
        $Qreq->r = $my_rrow->reviewId;
    }
    SelfHref::redirect($Qreq); // normally does not return
}


// delete review action
if (isset($Qreq->deletereview)
    && $Qreq->post_ok()
    && $Me->can_administer($prow)) {
    if (!$paperTable->editrrow)
        Conf::msg_error("No review to delete.");
    else {
        $result = $Conf->qe("delete from PaperReview where paperId=? and reviewId=?", $prow->paperId, $paperTable->editrrow->reviewId);
        if ($result) {
            $Me->log_activity("$editRrowLogname deleted", $prow);
            $Conf->confirmMsg("Deleted review.");
            $Conf->qe("delete from ReviewRating where paperId=? and reviewId=?", $prow->paperId, $paperTable->editrrow->reviewId);
            if ($paperTable->editrrow->reviewToken != 0)
                $Conf->update_rev_tokens_setting(-1);

            // perhaps a delegatee needs to redelegate
            if ($paperTable->editrrow->reviewType < REVIEW_SECONDARY && $paperTable->editrrow->requestedBy > 0)
                $Me->update_review_delegation($paperTable->editrrow->paperId, $paperTable->editrrow->requestedBy, -1);

            unset($Qreq->r, $Qreq->reviewId);
            $Qreq->paperId = $Qreq->p = $paperTable->editrrow->paperId;
            go(hoturl("paper", ["p" => $Qreq->paperId]));
        }
        SelfHref::redirect($Qreq);         // normally does not return
        loadRows();
    }
}


// download review form action
function downloadForm($qreq) {
    global $rf, $Conf, $Me, $prow, $paperTable;
    $rrow = $paperTable->rrow;
    $use_request = (!$rrow || $rrow->contactId == $Me->contactId)
        && $prow->review_type($Me) > 0;
    $text = $rf->textFormHeader(false) . $rf->textForm($prow, $rrow, $Me, $use_request ? $qreq : null);
    $filename = "review-{$prow->paperId}";
    if ($rrow && $rrow->reviewOrdinal)
        $filename .= unparseReviewOrdinal($rrow->reviewOrdinal);
    downloadText($text, $filename, false);
}

if (isset($Qreq->downloadForm))
    downloadForm($Qreq);


function download_all_text_reviews() {
    global $rf, $Conf, $Me, $prow, $paperTable;
    $lastrc = null;
    $text = "";
    foreach ($prow->viewable_submitted_reviews_and_comments($Me) as $rc) {
        $text .= PaperInfo::review_or_comment_text_separator($lastrc, $rc);
        if (isset($rc->reviewId))
            $text .= $rf->pretty_text($prow, $rc, $Me, false, true);
        else
            $text .= $rc->unparse_text($Me, true);
        $lastrc = $rc;
    }
    if ($text === "") {
        $whyNot = $Me->perm_view_review($prow, null) ? : $prow->make_whynot();
        return Conf::msg_error(whyNotText($whyNot));
    }
    $text = $Conf->short_name . " Paper #{$prow->paperId} Reviews and Comments\n"
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

if (isset($Qreq->text)) {
    if ($paperTable->rrow)
        download_one_text_review($paperTable->rrow);
    else
        download_all_text_reviews();
}


// refuse review action
function refuseReview($qreq) {
    global $Conf, $Me, $prow, $paperTable;

    Dbl::qe_raw("lock tables PaperReview write, PaperReviewRefused write");

    $rrow = $paperTable->editrrow;
    $hadToken = defval($rrow, "reviewToken", 0) != 0;

    $result = Dbl::qe("delete from PaperReview where paperId=? and reviewId=?", $prow->paperId, $rrow->reviewId);
    if (!$result)
        return;
    $reason = (string) $qreq->reason;
    if ($reason === "Optional explanation")
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

if (isset($Qreq->refuse) || isset($Qreq->decline)) {
    // XXX post_ok()
    if (!$paperTable->editrrow
        || (!$Me->is_my_review($paperTable->editrrow) && !$Me->can_administer($prow)))
        Conf::msg_error("This review was not assigned to you, so you can’t decline it.");
    else if ($paperTable->editrrow->reviewType >= REVIEW_SECONDARY)
        Conf::msg_error("PC members can’t decline their primary or secondary reviews.  Contact the PC chairs directly if you really cannot finish this review.");
    else if ($paperTable->editrrow->reviewSubmitted)
        Conf::msg_error("This review has already been submitted; you can’t decline it now.");
    else if ($Qreq->refuse === "1" || $Qreq->decline === "1") {
        $Conf->confirmMsg("<p>Select “Decline review” to decline this review (you may enter a brief explanation, if you’d like). Thank you for telling us that you cannot complete your review.</p><div class='g'></div><form method='post' action=\"" . hoturl_post("review", "p=" . $paperTable->prow->paperId . "&amp;r=" . $paperTable->editrrow->reviewId) . "\" enctype='multipart/form-data' accept-charset='UTF-8'><div>"
                          . Ht::hidden("refuse", "refuse") . "  "
                          . Ht::textarea("reason", "", array("rows" => 3, "cols" => 40, "spellcheck" => "true"))
                          . "\n  <span class='sep'></span>"
                          . Ht::submit("Decline review")
                          . "</div></form>");
    } else {
        refuseReview($Qreq);
        Dbl::qe_raw("unlock tables");
        loadRows();
    }
}

if (isset($Qreq->accept)) {
    // XXX post_ok()
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
$viewAny = $Me->can_view_review($prow, null);
$editAny = $Me->can_review($prow, null);


// can we see any reviews?
if (!$viewAny && !$editAny) {
    if (($whyNotPaper = $Me->perm_view_paper($prow)))
        errorMsgExit(whyNotText($whyNotPaper + ["listViewable" => true]));
    if (isset($Qreq->reviewId)) {
        Conf::msg_error("You can’t see the reviews for this paper. "
                        . whyNotText($Me->perm_view_review($prow, null)));
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
        || !$Me->can_view_review($prow, $paperTable->rrow)))
    $paperTable->paptabEndWithReviewMessage();
else {
    if ($paperTable->mode === "re") {
        $paperTable->paptabEndWithEditableReview();
        $paperTable->paptabComments();
    } else
        $paperTable->paptabEndWithReviewsAndComments();
}

$Conf->footer();
