<?php
// paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");
'@phan-var-force PaperInfo $prow';

// prepare request
$useRequest = isset($Qreq->title) && $Qreq->has_annex("after_login");
foreach (["emailNote", "reason"] as $x) {
    if ($Qreq[$x] === "Optional explanation")
        unset($Qreq[$x]);
}
if (isset($Qreq->p)
    && ctype_digit($Qreq->p)
    && !$Qreq->path()
    && !$Qreq->post_ok()) {
    $Conf->redirect_self($Qreq);
}
if (!isset($Qreq->p)
    && !isset($Qreq->paperId)
    && ($x = $Qreq->path_component(0)) !== false) {
    if (preg_match('/\A(?:new|\d+)\z/i', $x)) {
        $Qreq->p = $x;
        if (!isset($Qreq->m) && ($x = $Qreq->path_component(1))) {
            $Qreq->m = $x;
        }
        if ($Qreq->m === "api"
            && !isset($Qreq->fn)
            && ($x = $Qreq->path_component(2))) {
            $Qreq->fn = $x;
        }
    }
}

// prepare user
if ($Me->is_empty()) {
    $Me->escape();
}
if ($Qreq->post_ok() && !$Me->has_account_here()) {
    if (isset($Qreq->update) && $Me->can_start_paper()) {
        $Me->activate_database_account();
    } else {
        $Me->escape();
    }
}
$Me->add_overrides(Contact::OVERRIDE_CHECK_TIME);

// header
function confHeader() {
    global $paperTable, $Qreq;
    $mode = $paperTable ? $paperTable->mode : "p";
    PaperTable::do_header($paperTable, "paper-" . ($mode == "edit" ? "edit" : "view"), $mode, $Qreq);
}

function errorMsgExit($msg) {
    global $Qreq;
    if ($Qreq->ajax) {
        Conf::msg_error($msg);
        json_exit(["ok" => false]);
    } else {
        confHeader();
        Ht::stash_script("shortcut().add()");
        $msg && Conf::msg_error($msg);
        Conf::$main->footer();
        exit;
    }
}


// general error messages
if ($Qreq->post && $Qreq->post_empty()) {
    Conf::$main->post_missing_msg();
}


// grab paper row
function loadRows() {
    global $prow, $Me, $Qreq;
    if (!($prow = PaperTable::fetch_paper_request($Qreq, $Me))) {
        $whyNot = $Qreq->checked_annex("paper_whynot", "PermissionProblem");
        errorMsgExit(whyNotText($whyNot->set("listViewable", true)));
    }
}
$prow = $ps = null;
if (strcasecmp((string) $Qreq->p, "new")
    && strcasecmp((string) $Qreq->paperId, "new")) {
    loadRows();
}


// paper actions
if ($prow && $Qreq->m === "api" && isset($Qreq->fn) && $Conf->has_api($Qreq->fn)) {
    $Conf->call_api_exit($Qreq->fn, $Me, $Qreq, $prow);
}


// withdraw and revive actions
if (isset($Qreq->withdraw) && $prow && $Qreq->post_ok()) {
    if (!($whyNot = $Me->perm_withdraw_paper($prow))) {
        $reason = (string) $Qreq->reason;
        if ($reason === ""
            && $Me->can_administer($prow)
            && $Qreq->doemail > 0) {
            $reason = (string) $Qreq->emailNote;
        }

        $aset = new AssignmentSet($Me, true);
        $aset->enable_papers($prow);
        $aset->parse("paper,action,withdraw reason\n{$prow->paperId},withdraw," . CsvGenerator::quote($reason));
        if (!$aset->execute()) {
            error_log("{$Conf->dbname}: withdraw #{$prow->paperId} failure: " . json_encode($aset->json_result()));
        }
        loadRows();

        // email contact authors themselves
        if (!$Me->can_administer($prow) || $Qreq->doemail > 0) {
            $tmpl = $prow->has_author($Me) ? "@authorwithdraw" : "@adminwithdraw";
            HotCRPMailer::send_contacts($tmpl, $prow, ["reason" => $reason, "infoNames" => 1]);
        }

        // email reviewers
        if ($prow->reviews_by_id()) {
            $preps = [];
            $prow->notify_reviews(function ($prow, $minic) use ($reason, &$preps) {
                if (($p = HotCRPMailer::prepare_to($minic, "@withdrawreviewer", ["prow" => $prow, "reason" => $reason]))) {
                    if (!$minic->can_view_review_identity($prow, null))
                        $p->unique_preparation = true;
                    $preps[] = $p;
                }
            }, $Me);
            HotCRPMailer::send_combined_preparations($preps);
        }

        $Conf->redirect_self($Qreq);
    } else {
        Conf::msg_error(whyNotText($whyNot) . " The submission has not been withdrawn.");
    }
}
if (isset($Qreq->revive) && $prow && $Qreq->post_ok()) {
    if (!($whyNot = $Me->perm_revive_paper($prow))) {
        $aset = new AssignmentSet($Me, true);
        $aset->enable_papers($prow);
        $aset->parse("paper,action\n{$prow->paperId},revive");
        if (!$aset->execute())
            error_log("{$Conf->dbname}: revive #{$prow->paperId} failure: " . json_encode($aset->json_result()));
        loadRows();
        $Conf->redirect_self($Qreq);
    } else {
        Conf::msg_error(whyNotText($whyNot));
    }
}


// send watch messages
function final_submit_watch_callback($prow, $minic) {
    if ($minic->can_view_paper($prow)
        && $minic->allow_administer($prow)) {
        HotCRPMailer::send_to($minic, "@finalsubmitnotify", ["prow" => $prow]);
    }
}

function update_paper(Qrequest $qreq, $action) {
    global $Me, $prow, $ps;
    $Conf = Conf::$main;
    // XXX lock tables
    $wasSubmitted = $prow && $prow->timeSubmitted > 0;

    $ps = new PaperStatus($Conf, $Me);
    $prepared = $ps->prepare_save_paper_web($qreq, $prow, $action);

    if (!$prepared) {
        if (!$prow && $qreq->has_files()) {
            $ps->error_at(null, "<strong>Your uploaded files were ignored.</strong>");
        }
        $emsg = $ps->landmarked_message_texts();
        Conf::msg_error(space_join($Conf->_("Your changes were not saved. Please fix these errors and try again."), count($emsg) ? "<ul><li>" . join("</li><li>", $emsg) . "</li></ul>" : ""));
        return false;
    }

    // check deadlines
    if (!$prow) {
        // we know that can_start_paper implies can_finalize_paper
        $whyNot = $Me->perm_start_paper();
    } else if ($action === "final") {
        $whyNot = $Me->perm_edit_final_paper($prow);
    } else {
        $whyNot = $Me->perm_edit_paper($prow);
        if ($whyNot
            && $action === "submit"
            && !count(array_diff($ps->diffs, ["contacts", "status"])))
            $whyNot = $Me->perm_finalize_paper($prow);
    }
    if ($whyNot) {
        Conf::msg_error(whyNotText($whyNot));
        return $whyNot;
    }

    // actually update
    $ps->execute_save();

    $webnotes = "";
    if ($ps->has_messages()) {
        $webnotes .= " <ul><li>" . join("</li><li>", $ps->landmarked_message_texts()) . "</li></ul>";
    }

    $new_prow = $Me->conf->paper_by_id($ps->paperId, $Me, ["topics" => true, "options" => true]);
    if (!$new_prow) {
        $Conf->msg($Conf->_("Your submission was not saved. Please correct these errors and save again.") . $webnotes, "merror");
        return false;
    }
    assert($Me->can_view_paper($new_prow));

    // submit paper if no error so far
    $_GET["paperId"] = $_GET["p"] = $qreq->paperId = $qreq->p = $ps->paperId;

    if ($action === "final") {
        $submitkey = "timeFinalSubmitted";
        $storekey = "finalPaperStorageId";
    } else {
        $submitkey = "timeSubmitted";
        $storekey = "paperStorageId";
    }
    $newsubmit = $new_prow->timeSubmitted > 0 && !$wasSubmitted;

    // confirmation message
    if ($action === "final") {
        $actiontext = "Updated final";
        $template = "@submitfinalpaper";
    } else if ($newsubmit) {
        $actiontext = "Submitted";
        $template = "@submitpaper";
    } else if (!$prow) {
        $actiontext = "Registered";
        $template = "@registerpaper";
    } else {
        $actiontext = "Updated";
        $template = "@updatepaper";
    }

    // log message
    $actions = [];
    if (!$prow) {
        $actions[] = "started";
    }
    if ($newsubmit) {
        $actions[] = "submitted";
    }
    if ($prow && !$newsubmit && $ps->diffs) {
        $actions[] = "edited";
    }
    $logtext = "Paper " . join(", ", $actions);
    if ($action === "final") {
        $logtext .= " final";
        if ($new_prow->timeFinalSubmitted <= 0) {
            $logtext .= " draft";
        }
    } else if ($new_prow->timeSubmitted <= 0) {
        $logtext .= " draft";
    }
    $diffkeys = array_keys($ps->diffs);
    if (!$prow) {
        $diffkeys = array_intersect($diffkeys, ["submission", "final"]);
    }
    if ($diffkeys) {
        $logtext .= ": " . join(", ", $diffkeys);
    }
    $Me->log_activity($logtext, $new_prow->paperId);

    // additional information
    $notes = [];
    if ($action == "final") {
        if ($new_prow->timeFinalSubmitted <= 0) {
            $notes[] = $Conf->_("The final version has not yet been submitted.");
        }
        $deadline = $Conf->unparse_setting_time_span("final_soft");
        if ($deadline !== "N/A" && $Conf->deadlinesAfter("final_soft")) {
            $x = $Conf->_("The deadline for submitting final versions was %s.", $deadline);
            if ($x != "") {
                $notes[] = "<strong>$x</strong>";
            }
        } else if ($deadline != "N/A") {
            $notes[] = $Conf->_("You have until %s to make further changes.", $deadline);
        }
    } else {
        if ($new_prow->timeSubmitted > 0) {
            $notes[] = $Conf->_("You will receive email when reviews are available.");
        } else if ($new_prow->size == 0 && !$Conf->opt("noPapers")) {
            $notes[] = $Conf->_("The submission has not yet been uploaded.");
        } else if ($Conf->setting("sub_freeze") > 0) {
            $notes[] = $Conf->_("The submission has not yet been completed.");
        } else {
            $notes[] = $Conf->_("The submission is marked as not ready for review.");
        }
        $deadline = $Conf->unparse_setting_time_span("sub_update");
        if ($deadline !== "N/A"
            && ($new_prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0)) {
            $notes[] = $Conf->_("Further updates are allowed until %s.", $deadline);
        }
        $deadline = $Conf->unparse_setting_time_span("sub_sub");
        if ($deadline != "N/A" && $new_prow->timeSubmitted <= 0) {
            if ($Conf->setting("sub_freeze") > 0) {
                $x = $Conf->_("If the submission is not completed by %s, it will not be considered.", $deadline);
            } else {
                $x = $Conf->_("If the submission is not ready for review by %s, it will not be considered.", $deadline);
            }
            if ($x != "") {
                $notes[] = "<strong>$x</strong>";
            }
        }
    }
    $notes = join(" ", array_filter($notes, function ($n) { return $n !== ""; }));

    // HTML confirmation
    if (empty($ps->diffs)) {
        $webmsg = $Conf->_("No changes to submission #%d.", $new_prow->paperId);
    } else {
        $webmsg = $Conf->_("$actiontext submission #%d.", $new_prow->paperId);
    }
    if ($ps->has_error()) {
        $webmsg .= " " . $Conf->_("Please correct these issues and save again.");
    }
    if ($notes || $webnotes) {
        $webmsg .= " " . $notes . $webnotes;
    }
    $Conf->msg($webmsg, $new_prow->$submitkey > 0 ? "confirm" : "warning");

    // mail confirmation to all contact authors if changed
    if (!empty($ps->diffs)) {
        if (!$Me->can_administer($new_prow) || $qreq->doemail > 0) {
            $options = array("infoNames" => 1);
            if ($Me->can_administer($new_prow)) {
                if (!$new_prow->has_author($Me)) {
                    $options["adminupdate"] = true;
                }
                if (isset($qreq->emailNote)) {
                    $options["reason"] = $qreq->emailNote;
                }
            }
            if ($notes !== "") {
                $options["notes"] = preg_replace('/<\/?(?:span.*?|strong)>/', "", $notes) . "\n\n";
            }
            HotCRPMailer::send_contacts($template, $new_prow, $options);
        }

        // other mail confirmations
        if ($action == "final" && !Dbl::has_error() && !$ps->has_error()) {
            $new_prow->notify_final_submit("final_submit_watch_callback", $Me);
        }
    }

    $Conf->paper = $prow = $new_prow;
    return !$ps->has_error();
}


if (($Qreq->update || $Qreq->submitfinal) && $Qreq->post_ok()) {
    // choose action
    $action = "update";
    if ($Qreq->submitfinal && $prow) {
        $action = "final";
    } else if ($Qreq->submitpaper
               && (($prow && $prow->size > 0)
                   || $Qreq->has_file("opt0")
                   || $Conf->opt("noPapers"))) {
        $action = "submit";
    }

    $whyNot = update_paper($Qreq, $action);
    if ($whyNot === true) {
        $Conf->redirect_self($Qreq, ["p" => $prow->paperId, "m" => "edit"]);
    } else {
        // $Conf->redirect_self($Qreq, ["p" => $prow ? $prow->paperId : "new", "m" => "edit"]);
    }

    // If we get here, we failed to update.
    // Use the request unless the request failed because updates
    // aren't allowed.
    $useRequest = !$whyNot || !$prow
        || !($action != "final" && !$Me->can_edit_paper($prow)
             && $Me->can_finalize_paper($prow));
}

if ($Qreq->updatecontacts && $Qreq->post_ok() && $prow) {
    if ($Me->can_administer($prow) || $Me->act_author_view($prow)) {
        $ps = new PaperStatus($Conf, $Me);
        if ($ps->prepare_save_paper_web($Qreq, $prow, "updatecontacts")) {
            if (!$ps->diffs) {
                Conf::msg_warning($Conf->_("No changes to submission #%d.", $prow->paperId));
            } else if ($ps->execute_save()) {
                Conf::msg_confirm($Conf->_("Updated contacts for submission #%d.", $prow->paperId));
                $Me->log_activity("Paper edited: contacts", $prow->paperId);
                $Conf->redirect_self($Qreq);
            }
        } else {
            Conf::msg_error("<ul><li>" . join("</li><li>", $ps->message_texts()) . "</li></ul>");
        }
    } else {
        Conf::msg_error(whyNotText($prow->make_whynot(["permission" => "edit_contacts"])));
    }

    // use request?
    $useRequest = true;
}

if ($Qreq->updateoverride && $Qreq->post_ok() && $prow) {
    $Conf->redirect_self($Qreq, ["p" => $prow->paperId, "m" => "edit", "forceShow" => 1]);
}


// delete action
if ($Qreq->delete && $Qreq->post_ok()) {
    if (!$prow) {
        $Conf->confirmMsg("Submission deleted.");
    } else if (!$Me->can_administer($prow)) {
        Conf::msg_error("Only the program chairs can permanently delete submissions. Authors can withdraw submissions, which is effectively the same.");
    } else {
        // mail first, before contact info goes away
        if (!$Me->can_administer($prow) || $Qreq->doemail > 0) {
            HotCRPMailer::send_contacts("@deletepaper", $prow, array("reason" => (string) $Qreq->emailNote, "infoNames" => 1));
        }
        if ($prow->delete_from_database($Me)) {
            $Conf->confirmMsg("Submission #{$prow->paperId} deleted.");
        }
        $prow = null;
        errorMsgExit("");
    }
}
if ($Qreq->cancel && $Qreq->post_ok()) {
    if ($prow && $prow->timeSubmitted && $Qreq->m === "edit") {
        unset($Qreq->m);
    }
    $Conf->redirect_self($Qreq);
}


// correct modes
$paperTable = new PaperTable($prow, $Qreq);
$paperTable->resolveComments();
if ($paperTable->can_view_reviews()
    || $paperTable->mode === "re"
    || ($prow && $Me->can_review($prow))) {
    $paperTable->resolveReview(false);
    $paperTable->fixReviewMode();
}


// prepare paper table
if ($paperTable->mode == "edit") {
    if (!$prow) {
        $editable = true;
    } else {
        $old_overrides = $Me->remove_overrides(Contact::OVERRIDE_CHECK_TIME);
        $editable = $Me->can_edit_paper($prow);
        if ($Me->can_edit_final_paper($prow)) {
            $editable = "f";
        }
        $Me->set_overrides($old_overrides);
    }
} else {
    $editable = false;
}

$paperTable->initialize($editable, $editable && $useRequest);
if ($paperTable->mode === "edit" && !$ps) {
    $nnprow = $paperTable->prow;
    if (!$prow) {
        $nnprow->set_allow_absent(true);
    }
    $ps = $ps ?? PaperStatus::make_prow($Me, $nnprow);
    $old_overrides = $Me->add_overrides(Contact::OVERRIDE_CONFLICT);
    foreach ($Conf->options()->form_fields($nnprow) as $o) {
        if ($Me->can_edit_option($nnprow, $o)) {
            $ov = $nnprow->force_option($o);
            $ov->set_message_set($ps);
            $o->value_check($ov, $Me);
        }
    }
    $Me->set_overrides($old_overrides);
    $nnprow->set_allow_absent(false);
}
if ($paperTable->mode === "edit") {
    $paperTable->set_edit_status($ps);
}

// produce paper table
confHeader();
$paperTable->paptabBegin();

if ($paperTable->mode === "edit") {
    $paperTable->paptabEndWithoutReviews();
} else {
    if ($paperTable->mode === "re") {
        $paperTable->paptabEndWithEditableReview();
        $paperTable->paptabComments();
    } else if ($paperTable->can_view_reviews()) {
        $paperTable->paptabEndWithReviewsAndComments();
    } else {
        $paperTable->paptabEndWithReviewMessage();
        $paperTable->paptabComments();
    }
    // restore comment across logout bounce
    if ($Qreq->editcomment) {
        '@phan-var-force PaperInfo $prow';
        $cid = $Qreq->c;
        $preferred_resp_round = false;
        if (($x = $Qreq->response)) {
            $preferred_resp_round = $Conf->resp_round_number($x);
        }
        if ($preferred_resp_round === false) {
            $preferred_resp_round = $Me->preferred_resp_round_number($prow);
        }
        $j = null;
        foreach ($prow->viewable_comments($Me) as $crow) {
            if ($crow->commentId == $cid
                || ($cid === null
                    && ($crow->commentType & COMMENTTYPE_RESPONSE) != 0
                    && $crow->commentRound === $preferred_resp_round))
                $j = $crow->unparse_json($Me);
        }
        if (!$j) {
            $j = (object) ["is_new" => true, "editable" => true];
            if ($Me->act_author_view($prow)) {
                $j->by_author = true;
            }
            if ($preferred_resp_round !== false) {
                $j->response = $Conf->resp_round_name($preferred_resp_round);
            }
        }
        if (($x = $Qreq->text) !== null) {
            $j->text = $x;
            $j->visibility = $Qreq->visibility;
            $tags = trim((string) $Qreq->tags);
            $j->tags = $tags === "" ? [] : preg_split('/\s+/', $tags);
            $j->blind = !!$Qreq->blind;
            $j->draft = !!$Qreq->draft;
        }
        Ht::stash_script("papercomment.edit(" . json_encode_browser($j) . ")");
    }
}

echo "</article>\n";
$Conf->footer();
