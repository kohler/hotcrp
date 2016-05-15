<?php
// paper.php -- HotCRP paper view and edit page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

$ps = null;
require_once("src/initweb.php");
require_once("src/papertable.php");
if ($Me->is_empty())
    $Me->escape();
if (isset($_REQUEST["update"]) && check_post() && !$Me->has_database_account()
    && $Me->can_start_paper())
    $Me = $Me->activate_database_account();
$useRequest = isset($_REQUEST["after_login"]);
foreach (array("emailNote", "reason") as $x)
    if (isset($_REQUEST[$x]) && $_REQUEST[$x] == "Optional explanation")
        unset($_REQUEST[$x], $_GET[$x], $_POST[$x]);
if (!isset($_REQUEST["p"]) && !isset($_REQUEST["paperId"])
    && preg_match(',\A(?:new|\d+)\z,i', Navigation::path_component(0))) {
    $_REQUEST["p"] = $_GET["p"] = Navigation::path_component(0);
    if (!isset($_REQUEST["m"]) && ($x = Navigation::path_component(1)))
        $_REQUEST["m"] = $_GET["m"] = $x;
    if (isset($_REQUEST["m"]) && $_REQUEST["m"] === "api"
        && !isset($_REQUEST["fn"])
        && ($x = Navigation::path_component(2)))
        $_REQUEST["fn"] = $_GET["fn"] = $x;
} else if (!Navigation::path() && isset($_REQUEST["p"])
           && $_REQUEST["p"] && ctype_digit($_REQUEST["p"])
           && !check_post())
    go(selfHref());


// header
function confHeader() {
    global $paperTable;
    $mode = $paperTable ? $paperTable->mode : "p";
    PaperTable::do_header($paperTable, "paper_" . ($mode == "edit" ? "edit" : "view"), $mode);
}

function errorMsgExit($msg) {
    global $Conf;
    if (@$_REQUEST["ajax"]) {
        Conf::msg_error($msg);
        $Conf->ajaxExit(array("ok" => false));
    } else {
        confHeader();
        $Conf->footerScript("shortcut().add()");
        $msg && Conf::msg_error($msg);
        Conf::$g->footer();
        exit;
    }
}


// collect paper ID: either a number or "new"
$newPaper = (defval($_REQUEST, "p") == "new"
             || defval($_REQUEST, "paperId") == "new");


// general error messages
if (isset($_GET["post"]) && $_GET["post"] && !count($_POST))
    $Conf->post_missing_msg();


// grab paper row
function loadRows() {
    global $prow, $Conf;
    $Conf->paper = $prow = PaperTable::paperRow($whyNot);
    if (!$prow)
        errorMsgExit(whyNotText($whyNot, "view"));
}
$prow = null;
if (!$newPaper)
    loadRows();


// paper actions
if (isset($_REQUEST["setrevpref"]) && $prow && check_post()) {
    PaperActions::setReviewPreference($prow);
    loadRows();
}
if (isset($_REQUEST["setfollow"]) && $prow && check_post()) {
    PaperActions::set_follow($prow);
    loadRows();
}
if ($prow && isset($_GET["m"]) && $_GET["m"] === "api"
    && isset($_GET["fn"]) && isset(SiteLoader::$api_map[$_GET["fn"]])) {
    $Qreq = make_qreq();
    SiteLoader::call_api($Qreq->fn, $Me, $Qreq, $prow);
    json_exit(["ok" => false, "error" => "Internal error."]);
}


// check paper action
if (isset($_REQUEST["checkformat"]) && $prow && $Conf->setting("sub_banal")) {
    $ajax = defval($_REQUEST, "ajax", 0);
    $cf = new CheckFormat();
    $dt = HotCRPDocument::parse_dtype(req("dt"));
    if ($dt === null)
        $dt = req("final") ? DTYPE_FINAL : DTYPE_SUBMISSION;
    $suffix = "";
    if ($dt)
        $suffix = $dt < 0 ? "_m" . -$dt : "_" . $dt;
    if ($Conf->setting("sub_banal$suffix"))
        $format = $Conf->setting_data("sub_banal$suffix", "");
    else
        $format = $Conf->setting_data("sub_banal", "");
    $status = $cf->analyzePaper($prow->paperId, $dt, $format);

    // chairs get a hint message about multiple checking
    if ($Me->privChair) {
        $nbanal = $Conf->session("nbanal", 0) + 1;
        $Conf->save_session("nbanal", $nbanal);
        if ($nbanal >= 3 && $nbanal <= 6)
            $cf->msg("info", "To run the format checker for many papers, use Download &gt; Format check on the <a href='" . hoturl("search", "q=") . "'>search page</a>.");
    }

    $cf->reportMessages();
    if ($ajax)
        $Conf->ajaxExit(array("status" => $status), true);
}


// withdraw and revive actions
if (isset($_REQUEST["withdraw"]) && !$newPaper && check_post()) {
    if (!($whyNot = $Me->perm_withdraw_paper($prow))) {
        $reason = defval($_REQUEST, "reason", "");
        if ($reason == "" && $Me->privChair && defval($_REQUEST, "doemail") > 0)
            $reason = defval($_REQUEST, "emailNote", "");
        Dbl::qe("update Paper set timeWithdrawn=$Now, timeSubmitted=if(timeSubmitted>0,-100,0), withdrawReason=? where paperId=$prow->paperId", $reason != "" ? $reason : null);
        $numreviews = Dbl::fetch_ivalue("select count(*) from PaperReview where paperId=$prow->paperId and reviewNeedsSubmit!=0");
        $Conf->update_papersub_setting(false);
        loadRows();

        // email contact authors themselves
        if (!$Me->privChair || defval($_REQUEST, "doemail") > 0)
            HotCRPMailer::send_contacts(($prow->conflictType >= CONFLICT_AUTHOR ? "@authorwithdraw" : "@adminwithdraw"),
                                        $prow, array("reason" => $reason, "infoNames" => 1));

        // email reviewers
        if (($numreviews > 0 && $Conf->time_review_open())
            || $prow->num_reviews_assigned() > 0)
            HotCRPMailer::send_reviewers("@withdrawreviewer", $prow, array("reason" => $reason));

        // remove voting tags so people don't have phantom votes
        if (TagInfo::has_vote()) {
            $q = array();
            foreach (TagInfo::vote_tags() as $t => $v)
                $q[] = "tag='" . sqlq($t) . "' or tag like '%~" . sqlq_for_like($t) . "'";
            Dbl::qe_raw("delete from PaperTag where paperId=$prow->paperId and (" . join(" or ", $q) . ")");
        }

        $Me->log_activity("Withdrew", $prow->paperId);
        redirectSelf();
    } else
        Conf::msg_error(whyNotText($whyNot, "withdraw"));
}
if (isset($_REQUEST["revive"]) && !$newPaper && check_post()) {
    if (!($whyNot = $Me->perm_revive_paper($prow))) {
        Dbl::qe("update Paper set timeWithdrawn=0, timeSubmitted=if(timeSubmitted=-100,$Now,0), withdrawReason=null where paperId=$prow->paperId");
        $Conf->update_papersub_setting(true);
        loadRows();
        $Me->log_activity("Revived", $prow->paperId);
        redirectSelf();
    } else
        Conf::msg_error(whyNotText($whyNot, "revive"));
}


// send watch messages
global $Qreq;
$Qreq = make_qreq();

function final_submit_watch_callback($prow, $minic) {
    if ($minic->can_view_paper($prow))
        HotCRPMailer::send_to($minic, "@finalsubmitnotify", $prow);
}

function update_paper(PaperStatus $ps, $pj, $opj, $qreq, $action, $diffs) {
    global $Conf, $Me, $Opt, $OK, $prow;
    // XXX lock tables

    $saved = $ps->save_paper_json($pj);

    if (!$saved && !$prow && count($qreq->_FILES))
        $ps->set_error_html("paper", "<strong>Your uploaded files were ignored.</strong>");
    if (!get($pj, "collaborators") && $Conf->setting("sub_collab")) {
        $field = ($Conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts");
        $ps->set_warning_html("collaborators", "Please enter the authors’ potential conflicts in the $field field. If none of the authors have potential conflicts, just enter “None”.");
    }

    if (!$saved) {
        $emsg = $ps->error_html();
        Conf::msg_error("There were errors in saving your paper. Please fix them and try again." . (count($emsg) ? "<ul><li>" . join("</li><li>", $emsg) . "</li></ul>" : ""));
        return false;
    }

    // note differences in contacts
    $contacts = $ocontacts = [];
    foreach (get($pj, "contacts", []) as $v)
        $contacts[] = strtolower(is_string($v) ? $v : $v->email);
    if ($opj && get($opj, "contacts"))
        foreach ($opj->contacts as $v)
            $ocontacts[] = strtolower($v->email);
    sort($contacts);
    sort($ocontacts);
    if (json_encode($contacts) !== json_encode($ocontacts))
        $diffs["contacts"] = true;

    // submit paper if no error so far
    $_REQUEST["paperId"] = $_GET["paperId"] = $qreq->paperId = $pj->pid;
    loadRows();
    if ($action === "final") {
        $submitkey = "timeFinalSubmitted";
        $storekey = "finalPaperStorageId";
    } else {
        $submitkey = "timeSubmitted";
        $storekey = "paperStorageId";
    }
    $wasSubmitted = $opj && get($opj, "submitted");
    if (get($pj, "submitted") || $Conf->can_pc_see_all_submissions())
        $Conf->update_papersub_setting(true);
    if ($wasSubmitted != get($pj, "submitted"))
        $diffs["submission"] = 1;

    // confirmation message
    if ($action == "final") {
        $actiontext = "Updated final version of";
        $template = "@submitfinalpaper";
    } else if (get($pj, "submitted") && !$wasSubmitted) {
        $actiontext = "Submitted";
        $template = "@submitpaper";
    } else if (!$opj) {
        $actiontext = "Registered new";
        $template = "@registerpaper";
    } else {
        $actiontext = "Updated";
        $template = "@updatepaper";
    }

    // additional information
    $notes = array();
    if ($action == "final") {
        if ($prow->$submitkey === null || $prow->$submitkey <= 0)
            $notes[] = "The final version has not yet been submitted.";
        $deadline = $Conf->printableTimeSetting("final_soft", "span");
        if ($deadline != "N/A" && $Conf->deadlinesAfter("final_soft"))
            $notes[] = "<strong>The deadline for submitting final versions was $deadline.</strong>";
        else if ($deadline != "N/A")
            $notes[] = "You have until $deadline to make further changes.";
    } else {
        if (get($pj, "submitted"))
            $notes[] = "You will receive email when reviews are available.";
        else if ($prow->size == 0 && !opt("noPapers"))
            $notes[] = "The submission has not yet been uploaded.";
        else if ($Conf->setting("sub_freeze") > 0)
            $notes[] = "The submission has not yet been completed.";
        else
            $notes[] = "The submission is marked as not ready for review.";
        $deadline = $Conf->printableTimeSetting("sub_update", "span");
        if ($deadline != "N/A" && ($prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0))
            $notes[] = "Further updates are allowed until $deadline.";
        $deadline = $Conf->printableTimeSetting("sub_sub", "span");
        if ($deadline != "N/A" && $prow->timeSubmitted <= 0)
            $notes[] = "<strong>If the submission "
                . ($Conf->setting("sub_freeze") > 0 ? "is not completed"
                   : "is not ready for review")
                . " by $deadline, it will not be considered.</strong>";
    }
    $notes = join(" ", $notes);

    $webnotes = "";
    if (count($ps->error_html()))
        $webnotes .= " <ul><li>" . join("</li><li>", $ps->error_html()) . "</li></ul>";

    if (!count($diffs)) {
        $Conf->warnMsg("There were no changes to submission #$prow->paperId. " . $notes . $webnotes);
        return true;
    }

    // HTML confirmation
    if ($prow->$submitkey > 0)
        $Conf->confirmMsg($actiontext . " submission #$prow->paperId. " . $notes . $webnotes);
    else
        $Conf->warnMsg($actiontext . " submission #$prow->paperId. " . $notes . $webnotes);

    // mail confirmation to all contact authors
    if (!$Me->privChair || $qreq->doemail > 0) {
        $options = array("infoNames" => 1);
        if ($Me->privChair && $prow->conflictType < CONFLICT_AUTHOR)
            $options["adminupdate"] = true;
        if ($Me->privChair && isset($qreq->emailNote))
            $options["reason"] = $qreq->emailNote;
        if ($notes !== "")
            $options["notes"] = preg_replace(",</?(?:span.*?|strong)>,", "", $notes) . "\n\n";
        HotCRPMailer::send_contacts($template, $prow, $options);
    }

    // other mail confirmations
    if ($action == "final" && $OK && !$ps->has_error())
        $prow->notify(WATCHTYPE_FINAL_SUBMIT, "final_submit_watch_callback", $Me);

    $Me->log_activity($actiontext, $prow->paperId);
    return true;
}


if (($Qreq->update || $Qreq->submitfinal) && check_post($Qreq)) {
    // choose action
    $action = "update";
    if ($Qreq->submitfinal && !$newPaper)
        $action = "final";
    else if ($Qreq->submitpaper
             && (($prow && $prow->size > 0)
                 || $Qreq->_FILES->paperUpload
                 || opt("noPapers")))
        $action = "submit";

    $ps = new PaperStatus($Me);
    $opj = $prow ? $ps->paper_json($prow) : null;
    $pj = PaperStatus::clone_json($opj);
    PaperSaver::apply_all($Me, $pj, $opj, $Qreq, $action);
    $diffs = PaperSaver::all_diffs($pj, $opj);

    // check deadlines
    if ($newPaper)
        // we know that can_start_paper implies can_finalize_paper
        $whyNot = $Me->perm_start_paper();
    else if ($action == "final")
        $whyNot = $Me->perm_submit_final_paper($prow);
    else {
        $whyNot = $Me->perm_update_paper($prow);
        if ($whyNot && $action == "submit" && empty($diffs))
            $whyNot = $Me->perm_finalize_paper($prow);
    }

    // actually update
    if (!$whyNot) {
        if (update_paper($ps, $pj, $opj, $Qreq, $action, $diffs))
            redirectSelf(array("p" => $prow->paperId, "m" => "edit"));
    } else {
        if ($action == "final")
            $adescription = "submit final version for";
        else
            $adescription = $prow ? "update" : "register";
        Conf::msg_error(whyNotText($whyNot, $adescription));
    }

    // If we get here, we failed to update.
    // Use the request unless the request failed because updates
    // aren't allowed.
    $useRequest = !$whyNot || !$prow
        || !($action != "final" && !$Me->can_update_paper($prow)
             && $Me->can_finalize_paper($prow));
}

if ($Qreq->updatecontacts && check_post($Qreq) && $prow) {
    if ($Me->can_administer($prow) || $Me->act_author_view($prow)) {
        $ps = new PaperStatus($Me);
        $opj = $ps->paper_json($prow);
        $pj = PaperStatus::clone_json($opj);
        PaperSaver::replace_contacts($pj, $Qreq);
        if ($ps->save_paper_json($pj, $opj))
            redirectSelf();
        else
            Conf::msg_error("<ul><li>" . join("</li><li>", $ps->error_html()) . "</li></ul>");
    } else
        Conf::msg_error(whyNotText(array("permission" => 1), "update contacts for"));

    // use request?
    $useRequest = true;
}


// delete action
if ($Qreq->delete && check_post()) {
    if ($newPaper)
        $Conf->confirmMsg("Paper deleted.");
    else if (!$Me->privChair)
        Conf::msg_error("Only the program chairs can permanently delete papers. Authors can withdraw papers, which is effectively the same.");
    else {
        // mail first, before contact info goes away
        if (!$Me->privChair || $Qreq->doemail > 0)
            HotCRPMailer::send_contacts("@deletepaper", $prow, array("reason" => (string) $Qreq->emailNote, "infoNames" => 1));
        // XXX email self?

        $error = false;
        $tables = array('Paper', 'PaperStorage', 'PaperComment', 'PaperConflict', 'PaperReview', 'PaperReviewPreference', 'PaperTopic', 'PaperTag', "PaperOption");
        foreach ($tables as $table) {
            $result = Dbl::qe_raw("delete from $table where paperId=$prow->paperId");
            $error |= ($result == false);
        }
        if (!$error) {
            $Conf->confirmMsg("Paper #$prow->paperId deleted.");
            $Conf->update_papersub_setting(false);
            if ($prow->outcome > 0)
                $Conf->update_paperacc_setting(false);
            $Me->log_activity("Deleted", $prow->paperId);
        }

        $prow = null;
        errorMsgExit("");
    }
}


// correct modes
$paperTable = new PaperTable($prow, $Qreq);
$paperTable->resolveComments();
if ($paperTable->can_view_reviews() || $paperTable->mode == "re") {
    $paperTable->resolveReview(false);
    $paperTable->fixReviewMode();
}


// prepare paper table
if ($paperTable->mode == "edit") {
    $editable = $newPaper || $Me->can_update_paper($prow, true);
    if ($prow && $prow->outcome > 0 && $Conf->collectFinalPapers()
        && (($Conf->timeAuthorViewDecision() && $Conf->timeSubmitFinalPaper())
            || $Me->allow_administer($prow)))
        $editable = "f";
} else
    $editable = false;

$paperTable->initialize($editable, $editable && $useRequest);
if ($ps && $paperTable->mode === "edit")
    $paperTable->set_edit_status($ps);

// produce paper table
confHeader();
$paperTable->paptabBegin();

if ($paperTable->mode === "edit")
    $paperTable->paptabEndWithReviewMessage();
else {
    if ($paperTable->mode === "re") {
        $paperTable->paptabEndWithEditableReview();
        $paperTable->paptabComments();
    } else if ($paperTable->can_view_reviews())
        $paperTable->paptabEndWithReviewsAndComments();
    else {
        $paperTable->paptabEndWithReviewMessage();
        $paperTable->paptabComments();
    }
}

$Conf->footer();
