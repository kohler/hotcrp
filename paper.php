<?php
// paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");

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
    $Conf->self_redirect($Qreq);
}
if (!isset($Qreq->p)
    && !isset($Qreq->paperId)
    && ($x = $Qreq->path_component(0)) !== false) {
    if (preg_match(',\A(?:new|\d+)\z,i', $x)) {
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
    global $Conf, $Qreq;
    if ($Qreq->ajax) {
        Conf::msg_error($msg);
        json_exit(["ok" => false]);
    } else {
        confHeader();
        Ht::stash_script("shortcut().add()");
        $msg && Conf::msg_error($msg);
        Conf::$g->footer();
        exit;
    }
}


// general error messages
if ($Qreq->post && $Qreq->post_empty()) {
    $Conf->post_missing_msg();
}


// grab paper row
function loadRows() {
    global $prow, $Me, $Qreq;
    if (!($prow = PaperTable::fetch_paper_request($Qreq, $Me)))
        errorMsgExit(whyNotText($Qreq->annex("paper_whynot") + ["listViewable" => true]));
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

        $Conf->self_redirect($Qreq);
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
        $Conf->self_redirect($Qreq);
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
    global $Conf, $Me, $prow, $ps;
    // XXX lock tables
    $wasSubmitted = $prow && $prow->timeSubmitted > 0;

    $pj = PaperSaver::apply_all($qreq, $prow, $Me, $action);

    $ps = new PaperStatus($Conf, $Me);
    $prepared = $ps->prepare_save_paper_json($pj);

    if (!$prepared) {
        if (!$prow && $qreq->has_files()) {
            $ps->error_at(null, "<strong>Your uploaded files were ignored.</strong>");
        }
        $emsg = $ps->landmarked_messages();
        Conf::msg_error(space_join($Conf->_("Please fix these errors and try again."), count($emsg) ? "<ul><li>" . join("</li><li>", $emsg) . "</li></ul>" : ""));
        return false;
    }

    // check deadlines
    if (!$prow) {
        // we know that can_start_paper implies can_finalize_paper
        $whyNot = $Me->perm_start_paper();
    } else if ($action == "final") {
        $whyNot = $Me->perm_submit_final_paper($prow);
    } else {
        $whyNot = $Me->perm_update_paper($prow);
        if ($whyNot
            && $action == "submit"
            && !count(array_diff($ps->diffs, ["contacts", "status"])))
            $whyNot = $Me->perm_finalize_paper($prow);
    }
    if ($whyNot) {
        Conf::msg_error(whyNotText($whyNot));
        return $whyNot;
    }

    // actually update
    $ps->execute_save_paper_json($pj);

    $webnotes = "";
    if ($ps->has_messages()) {
        $webnotes .= " <ul><li>" . join("</li><li>", $ps->landmarked_messages()) . "</li></ul>";
    }

    $new_prow = $Me->conf->fetch_paper(["paperId" => $pj->pid, "topics" => true, "options" => true], $Me);
    if (!$new_prow) {
        $Conf->msg($Conf->_("Your submission was not saved. Please correct these errors and save again.") . $webnotes, "merror");
        return false;
    }
    assert($Me->can_view_paper($new_prow));

    // submit paper if no error so far
    $_GET["paperId"] = $_GET["p"] = $qreq->paperId = $qreq->p = $pj->pid;

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
        if ((int) $new_prow->timeFinalSubmitted <= 0) {
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
        if ((int) $new_prow->timeFinalSubmitted <= 0) {
            $notes[] = $Conf->_("The final version has not yet been submitted.");
        }
        $deadline = $Conf->printableTimeSetting("final_soft", "span");
        if ($deadline != "N/A" && $Conf->deadlinesAfter("final_soft")) {
            $x = $Conf->_("The deadline for submitting final versions was %s.", $deadline);
            if ($x != "")
                $notes[] = "<strong>$x</strong>";
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
        $deadline = $Conf->printableTimeSetting("sub_update", "span");
        if ($deadline != "N/A"
            && ($new_prow->timeSubmitted <= 0 || $Conf->setting("sub_freeze") <= 0)) {
            $notes[] = $Conf->_("Further updates are allowed until %s.", $deadline);
        }
        $deadline = $Conf->printableTimeSetting("sub_sub", "span");
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
    if ($ps->has_error()) {
        $webmsg = $Conf->_("Some or all of your changes were not saved. Please correct these errors and save again.");
    } else if (empty($ps->diffs)) {
        $webmsg = $Conf->_("No changes to submission #%d.", $new_prow->paperId);
    } else {
        $webmsg = $Conf->_("$actiontext submission #%d.", $new_prow->paperId);
    }
    if ($notes || $webnotes) {
        $webmsg .= " " . $notes . $webnotes;
    }
    if ($ps->has_error()) {
        $Conf->msg($webmsg, "merror");
    } else {
        $Conf->msg($webmsg, $new_prow->$submitkey > 0 ? "confirm" : "warning");
    }

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
                $options["notes"] = preg_replace(",</?(?:span.*?|strong)>,", "", $notes) . "\n\n";
            }
            HotCRPMailer::send_contacts($template, $new_prow, $options);
        }

        // other mail confirmations
        if ($action == "final" && !Dbl::has_error() && !$ps->has_error()) {
            $new_prow->notify_final_submit("final_submit_watch_callback", $Me);
        }
    }

    $prow = $new_prow;
    return !$ps->has_error();
}


if (($Qreq->update || $Qreq->submitfinal) && $Qreq->post_ok()) {
    // choose action
    $action = "update";
    if ($Qreq->submitfinal && $prow) {
        $action = "final";
    } else if ($Qreq->submitpaper
               && (($prow && $prow->size > 0)
                   || $Qreq->has_file("paperUpload")
                   || $Conf->opt("noPapers"))) {
        $action = "submit";
    }

    $whyNot = update_paper($Qreq, $action);
    if ($whyNot === true) {
        $Conf->self_redirect($Qreq, ["p" => $prow->paperId, "m" => "edit"]);
    } else {
        // $Conf->self_redirect($Qreq, ["p" => $prow ? $prow->paperId : "new", "m" => "edit"]);
    }

    // If we get here, we failed to update.
    // Use the request unless the request failed because updates
    // aren't allowed.
    $useRequest = !$whyNot || !$prow
        || !($action != "final" && !$Me->can_update_paper($prow)
             && $Me->can_finalize_paper($prow));
}

if ($Qreq->updatecontacts && $Qreq->post_ok() && $prow) {
    if ($Me->can_administer($prow) || $Me->act_author_view($prow)) {
        $pj = PaperSaver::apply_all($Qreq, $prow, $Me, "updatecontacts");
        $ps = new PaperStatus($Conf, $Me);
        if ($ps->prepare_save_paper_json($pj)) {
            if (!$ps->diffs) {
                Conf::msg_warning($Conf->_("No changes to submission #%d.", $prow->paperId));
            } else if ($ps->execute_save_paper_json($pj)) {
                Conf::msg_confirm($Conf->_("Updated contacts for submission #%d.", $prow->paperId));
                $Me->log_activity("Paper edited: contacts", $prow->paperId);
                $Conf->self_redirect($Qreq);
            }
        } else {
            Conf::msg_error("<ul><li>" . join("</li><li>", $ps->messages()) . "</li></ul>");
        }
    } else {
        Conf::msg_error(whyNotText($prow->make_whynot(["permission" => "edit_contacts"])));
    }

    // use request?
    $useRequest = true;
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
    $Conf->self_redirect($Qreq);
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
    if (!$prow) {
        $editable = true;
    } else {
        $old_overrides = $Me->remove_overrides(Contact::OVERRIDE_CHECK_TIME);
        $editable = $Me->can_update_paper($prow);
        if ($Me->can_submit_final_paper($prow))
            $editable = "f";
        $Me->set_overrides($old_overrides);
    }
} else {
    $editable = false;
}

$paperTable->initialize($editable, $editable && $useRequest);
if (($ps || $prow) && $paperTable->mode === "edit") {
    if ($ps) {
        $ps->ignore_duplicates = true;
    } else {
        $ps = new PaperStatus($Conf, $Me);
    }
    if ($prow) {
        $old_overrides = $Me->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($Conf->paper_opts->form_field_list($prow) as $o) {
            if ($Me->can_edit_option($prow, $o)) {
                $o->value_messages($prow->force_option($o), $ps);
            }
        }
        $Me->set_overrides($old_overrides);
    }
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
                $j = $crow->unparse_json($Me, true);
        }
        if (!$j) {
            $j = (object) ["is_new" => true, "editable" => true];
            if ($Me->act_author_view($prow))
                $j->by_author = true;
            if ($preferred_resp_round !== false)
                $j->response = $Conf->resp_round_name($preferred_resp_round);
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

echo "</div>\n";
$Conf->footer();
