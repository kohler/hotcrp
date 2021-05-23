<?php
// paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

class PaperPage {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var PaperInfo */
    public $prow;
    /** @var ?PaperStatus */
    public $ps;
    /** @var PaperTable */
    public $pt;
    /** @var bool */
    public $useRequest;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    function echo_header() {
        $m = $this->pt ? $this->pt->mode : ($this->qreq->m ?? "p");
        PaperTable::echo_header($this->pt, "paper-" . ($m === "edit" ? "edit" : "view"), $m, $this->qreq);
    }

    function error_exit($msg) {
        $this->echo_header();
        Ht::stash_script("hotcrp.shortcut().add()");
        $msg && Conf::msg_error($msg);
        $this->conf->footer();
        exit;
    }

    function load_prow() {
        // determine whether request names a paper
        try {
            $pr = new PaperRequest($this->user, $this->qreq, false);
            $this->prow = $this->conf->paper = $pr->prow;
        } catch (Redirection $redir) {
            assert(PaperRequest::simple_qreq($this->qreq));
            $this->conf->redirect($redir->url);
        } catch (PermissionProblem $perm) {
            $this->error_exit($perm->set("listViewable", true)->unparse_html());
        }
    }

    function handle_cancel() {
        if ($this->prow->timeSubmitted && $this->qreq->m === "edit") {
            unset($this->qreq->m);
        }
        $this->conf->redirect_self($this->qreq);
    }

    function handle_withdraw() {
        if (($whynot = $this->user->perm_withdraw_paper($this->prow))) {
            Conf::msg_error($whynot->unparse_html() . " The submission has not been withdrawn.");
            return;
        }

        $reason = (string) $this->qreq->reason;
        if ($reason === ""
            && $this->user->can_administer($this->prow)
            && $this->qreq->doemail > 0) {
            $reason = (string) $this->qreq->emailNote;
        }

        $aset = new AssignmentSet($this->user, true);
        $aset->enable_papers($this->prow);
        $aset->parse("paper,action,withdraw reason\n{$this->prow->paperId},withdraw," . CsvGenerator::quote($reason));
        if (!$aset->execute()) {
            error_log("{$this->conf->dbname}: withdraw #{$this->prow->paperId} failure: " . json_encode($aset->json_result()));
        }
        $this->load_prow();

        // email contact authors themselves
        if (!$this->user->can_administer($this->prow) || $this->qreq->doemail) {
            $tmpl = $this->prow->has_author($this->user) ? "@authorwithdraw" : "@adminwithdraw";
            HotCRPMailer::send_contacts($tmpl, $this->prow, ["reason" => $reason, "infoNames" => 1]);
        }

        // email reviewers
        if ($this->prow->all_reviews()) {
            $preps = [];
            foreach ($this->prow->review_followers() as $minic) {
                if ($minic->contactId !== $this->user->contactId
                    && ($p = HotCRPMailer::prepare_to($minic, "@withdrawreviewer", ["prow" => $this->prow, "reason" => $reason]))) {
                    if (!$minic->can_view_review_identity($this->prow, null)) {
                        $p->unique_preparation = true;
                    }
                    $preps[] = $p;
                }
            }
            HotCRPMailer::send_combined_preparations($preps);
        }

        $this->conf->redirect_self($this->qreq);
    }

    function handle_revive() {
        if (($whynot = $this->user->perm_revive_paper($this->prow))) {
            Conf::msg_error($whynot->unparse_html());
            return;
        }

        $aset = new AssignmentSet($this->user, true);
        $aset->enable_papers($this->prow);
        $aset->parse("paper,action\n{$this->prow->paperId},revive");
        if (!$aset->execute()) {
            error_log("{$this->conf->dbname}: revive #{$this->prow->paperId} failure: " . json_encode($aset->json_result()));
        }
        $this->conf->redirect_self($this->qreq);
    }

    function handle_delete() {
        if ($this->prow->paperId <= 0) {
            $this->conf->confirmMsg("Submission deleted.");
        } else if (!$this->user->can_administer($this->prow)) {
            Conf::msg_error("Only the program chairs can permanently delete submissions. Authors can withdraw submissions, which is effectively the same.");
        } else {
            // mail first, before contact info goes away
            if ($this->qreq->doemail) {
                HotCRPMailer::send_contacts("@deletepaper", $this->prow, ["reason" => (string) $this->qreq->emailNote, "infoNames" => 1]);
            }
            if ($this->prow->delete_from_database($this->user)) {
                $this->conf->confirmMsg("Submission #{$this->prow->paperId} deleted.");
            }
            $this->error_exit("");
        }
    }

    function handle_update($action) {
        $conf = $this->conf;
        // XXX lock tables
        $is_new = $this->prow->paperId <= 0;
        $was_submitted = $this->prow->timeSubmitted > 0;
        $this->useRequest = true;

        $this->ps = new PaperStatus($conf, $this->user);
        $prepared = $this->ps->prepare_save_paper_web($this->qreq, $this->prow, $action);

        if (!$prepared) {
            if ($is_new && $this->qreq->has_files()) {
                // XXX save uploaded files
                $this->ps->error_at(null, "<strong>Your uploaded files were ignored.</strong>");
            }
            $emsg = $this->ps->landmarked_message_texts();
            Conf::msg_error(space_join($conf->_("Your changes were not saved. Please fix these errors and try again."), count($emsg) ? "<ul><li>" . join("</li><li>", $emsg) . "</li></ul>" : ""));
            return;
        }

        // check deadlines
        if ($is_new) {
            // we know that can_start_paper implies can_finalize_paper
            $whynot = $this->user->perm_start_paper();
        } else if ($action === "final") {
            $whynot = $this->user->perm_edit_final_paper($this->prow);
        } else {
            $whynot = $this->user->perm_edit_paper($this->prow);
            if ($whynot
                && $action === "update"
                && !count(array_diff($this->ps->diffs, ["contacts", "status"]))) {
                $whynot = $this->user->perm_finalize_paper($this->prow);
            }
        }
        if ($whynot) {
            Conf::msg_error($whynot->unparse_html());
            $this->useRequest = !$is_new; // XXX used to have more complex logic
            return;
        }

        // actually update
        $this->ps->execute_save();

        $webnotes = "";
        if ($this->ps->has_messages()) {
            $webnotes .= " <ul><li>" . join("</li><li>", $this->ps->landmarked_message_texts()) . "</li></ul>";
        }

        $new_prow = $conf->paper_by_id($this->ps->paperId, $this->user, ["topics" => true, "options" => true]);
        if (!$new_prow) {
            $conf->msg($conf->_("Your submission was not saved. Please correct these errors and save again.") . $webnotes, "merror");
            return;
        }
        assert($this->user->can_view_paper($new_prow));

        // submit paper if no error so far
        $_GET["paperId"] = $_GET["p"] = $this->qreq->paperId = $this->qreq->p = $this->ps->paperId;

        if ($action === "final") {
            $submitkey = "timeFinalSubmitted";
            $storekey = "finalPaperStorageId";
        } else {
            $submitkey = "timeSubmitted";
            $storekey = "paperStorageId";
        }
        $newsubmit = $new_prow->timeSubmitted > 0 && !$was_submitted;

        // confirmation message
        if ($action === "final") {
            $actiontext = "Updated final";
            $template = "@submitfinalpaper";
        } else if ($newsubmit) {
            $actiontext = "Submitted";
            $template = "@submitpaper";
        } else if ($is_new) {
            $actiontext = "Registered";
            $template = "@registerpaper";
        } else {
            $actiontext = "Updated";
            $template = "@updatepaper";
        }

        // log message
        $this->ps->log_save_activity($this->user, $action);

        // additional information
        $notes = [];
        if ($action == "final") {
            if ($new_prow->timeFinalSubmitted <= 0) {
                $notes[] = $conf->_("The final version has not yet been submitted.");
            }
            $deadline = $conf->unparse_setting_time_span("final_soft");
            if ($deadline !== "N/A" && $conf->time_after_setting("final_soft")) {
                $x = $conf->_("The deadline for submitting final versions was %s.", $deadline);
                if ($x != "") {
                    $notes[] = "<strong>$x</strong>";
                }
            } else if ($deadline != "N/A") {
                $notes[] = $conf->_("You have until %s to make further changes.", $deadline);
            }
        } else {
            if ($new_prow->timeSubmitted > 0) {
                $notes[] = $conf->_("The submission will be considered for review.");
            } else if ($new_prow->size == 0 && !$conf->opt("noPapers")) {
                $notes[] = $conf->_("The submission has not yet been uploaded.");
            } else if ($conf->setting("sub_freeze") > 0) {
                $notes[] = $conf->_("The submission has not yet been completed.");
            } else {
                $notes[] = $conf->_("The submission is marked as not ready for review.");
            }
            $deadline = $conf->unparse_setting_time_span("sub_update");
            if ($deadline !== "N/A"
                && ($new_prow->timeSubmitted <= 0 || $conf->setting("sub_freeze") <= 0)) {
                $notes[] = $conf->_("Further updates are allowed until %s.", $deadline);
            }
            $deadline = $conf->unparse_setting_time_span("sub_sub");
            if ($deadline != "N/A" && $new_prow->timeSubmitted <= 0) {
                if ($conf->setting("sub_freeze") > 0) {
                    $x = $conf->_("If the submission is not completed by %s, it will not be considered.", $deadline);
                } else {
                    $x = $conf->_("If the submission is not ready for review by %s, it will not be considered.", $deadline);
                }
                if ($x != "") {
                    $notes[] = "<strong>$x</strong>";
                }
            }
        }
        $notes = join(" ", array_filter($notes, function ($n) { return $n !== ""; }));

        // HTML confirmation
        if (empty($this->ps->diffs)) {
            $webmsg = $conf->_("No changes to submission #%d.", $new_prow->paperId);
        } else {
            $webmsg = $conf->_("$actiontext submission #%d.", $new_prow->paperId);
        }
        if ($this->ps->has_error()) {
            $webmsg .= " " . $conf->_("Please correct these issues and save again.");
        }
        if ($notes || $webnotes) {
            $webmsg .= " " . $notes . $webnotes;
        }
        $conf->msg($webmsg, $new_prow->$submitkey > 0 ? "confirm" : "warning");

        // mail confirmation to all contact authors if changed
        if (!empty($this->ps->diffs)) {
            if (!$this->user->can_administer($new_prow) || $this->qreq->doemail) {
                $options = ["infoNames" => 1];
                if ($this->user->can_administer($new_prow)) {
                    if (!$new_prow->has_author($this->user)) {
                        $options["adminupdate"] = true;
                    }
                    if (isset($this->qreq->emailNote)) {
                        $options["reason"] = $this->qreq->emailNote;
                    }
                }
                if ($notes !== "") {
                    $options["notes"] = preg_replace('/<\/?(?:span.*?|strong)>/', "", $notes) . "\n\n";
                }
                HotCRPMailer::send_contacts($template, $new_prow, $options);
            }

            // other mail confirmations
            if ($action == "final" && !Dbl::has_error() && !$this->ps->has_error()) {
                foreach ($new_prow->final_submit_followers() as $minic) {
                    if ($minic->contactId !== $this->user->contactId)
                        HotCRPMailer::send_to($minic, "@finalsubmitnotify", ["prow" => $new_prow]);
                }
            }
        }

        $conf->paper = $this->prow = $new_prow;
        if (!$this->ps->has_error() || ($is_new && $new_prow)) {
            $conf->redirect_self($this->qreq, ["p" => $new_prow->paperId, "m" => "edit"]);
        }
    }

    function handle_updatecontacts() {
        $conf = $this->conf;
        $this->useRequest = true;

        if (!$this->user->can_administer($this->prow)
            && !$this->prow->has_author($this->user)) {
            Conf::msg_error($this->prow->make_whynot(["permission" => "edit_contacts"])->unparse_html());
            return;
        }

        $this->ps = new PaperStatus($this->conf, $this->user);
        if (!$this->ps->prepare_save_paper_web($this->qreq, $this->prow, "updatecontacts")) {
            Conf::msg_error("<ul><li>" . join("</li><li>", $this->ps->message_texts()) . "</li></ul>");
            return;
        }

        if (!$this->ps->diffs) {
            Conf::msg_warning($conf->_("No changes to submission #%d.", $this->prow->paperId));
        } else if ($this->ps->execute_save()) {
            Conf::msg_confirm($conf->_("Updated contacts for submission #%d.", $this->prow->paperId));
            $this->user->log_activity("Paper edited: contacts", $this->prow->paperId);
        }

        if (!$this->ps->has_error()) {
            $conf->redirect_self($this->qreq);
        }
    }

    private function prepare_edit_mode() {
        if (!$this->ps) {
            $this->prow->set_allow_absent($this->prow->paperId === 0);
            $this->ps = PaperStatus::make_prow($this->user, $this->prow);
            $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($this->prow->form_fields() as $o) {
                if ($this->user->can_edit_option($this->prow, $o)) {
                    $ov = $this->prow->force_option($o);
                    $ov->set_message_set($this->ps);
                    $o->value_check($ov, $this->user);
                }
            }
            $this->user->set_overrides($old_overrides);
            $this->prow->set_allow_absent(false);
        }

        $old_overrides = $this->user->remove_overrides(Contact::OVERRIDE_CHECK_TIME);
        $editable = $this->user->can_edit_paper($this->prow)
            || $this->user->can_edit_final_paper($this->prow);
        $this->user->set_overrides($old_overrides);
        $this->pt->set_edit_status($this->ps, $editable, $editable && $this->useRequest);
    }

    function render() {
        // correct modes
        $this->pt = $pt = new PaperTable($this->user, $this->qreq, $this->prow);
        if ($pt->can_view_reviews()
            || $pt->mode === "re"
            || ($this->prow->paperId > 0 && $this->user->can_edit_review($this->prow))) {
            $pt->resolve_review(false);
        }
        $pt->resolve_comments();
        if ($pt->mode === "edit") {
            $this->prepare_edit_mode();
        }

        // produce paper table
        $this->echo_header();
        $pt->echo_paper_info();

        if ($pt->mode === "edit") {
            $pt->paptabEndWithoutReviews();
        } else {
            if ($pt->mode === "re") {
                $pt->echo_review_form();
                $pt->echo_main_link();
            } else if ($pt->can_view_reviews()) {
                $pt->paptabEndWithReviewsAndComments();
            } else {
                $pt->paptabEndWithReviewMessage();
                $pt->echo_comments();
            }
            // restore comment across logout bounce
            if ($this->qreq->editcomment) {
                $cid = $this->qreq->c;
                $preferred_resp_round = false;
                if (($x = $this->qreq->response)) {
                    $preferred_resp_round = $this->conf->resp_round_number($x);
                }
                if ($preferred_resp_round === false) {
                    $preferred_resp_round = $this->user->preferred_resp_round_number($this->prow);
                }
                $j = null;
                foreach ($this->prow->viewable_comments($this->user) as $crow) {
                    if ($crow->commentId == $cid
                        || ($cid === null
                            && ($crow->commentType & COMMENTTYPE_RESPONSE) != 0
                            && $crow->commentRound === $preferred_resp_round))
                        $j = $crow->unparse_json($this->user);
                }
                if (!$j) {
                    $j = (object) ["is_new" => true, "editable" => true];
                    if ($this->user->act_author_view($this->prow)) {
                        $j->by_author = true;
                    }
                    if ($preferred_resp_round !== false) {
                        $j->response = $this->conf->resp_round_name($preferred_resp_round);
                    }
                }
                if (($x = $this->qreq->text) !== null) {
                    $j->text = $x;
                    $j->visibility = $this->qreq->visibility;
                    $tags = trim((string) $this->qreq->tags);
                    $j->tags = $tags === "" ? [] : preg_split('/\s+/', $tags);
                    $j->blind = !!$this->qreq->blind;
                    $j->draft = !!$this->qreq->draft;
                }
                Ht::stash_script("hotcrp.edit_comment(" . json_encode_browser($j) . ")");
            }
        }

        echo "</article>\n";
        $this->conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->m) && ($pc = $qreq->path_component(1))) {
            $qreq->m = $pc;
        } else if (!isset($qreq->m) && isset($qreq->mode)) {
            $qreq->m = $qreq->mode;
        }

        $pp = new PaperPage($user, $qreq);
        $pp->load_prow();

        // fix user
        if ($qreq->is_post() && $qreq->valid_token()) {
            $user->ensure_account_here();
            // XXX escape unless update && can_start_paper???
        }
        $user->add_overrides(Contact::OVERRIDE_CHECK_TIME);
        if ($pp->prow->paperId == 0 && $user->privChair && !$user->conf->time_start_paper()) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }

        // fix request
        $pp->useRequest = isset($qreq->title) && $qreq->has_annex("after_login");
        if ($qreq->emailNote === "Optional explanation") {
            unset($qreq->emailNote);
        }
        if ($qreq->reason === "Optional explanation") {
            unset($qreq->reason);
        }
        if ($qreq->post && $qreq->post_empty()) {
            $pp->conf->post_missing_msg();
        }

        // action
        if ($qreq->cancel) {
            $pp->handle_cancel();
        } else if ($qreq->update && $qreq->valid_post()) {
            $pp->handle_update($qreq->submitfinal ? "final" : "update");
        } else if ($qreq->updatecontacts && $qreq->valid_post()) {
            $pp->handle_updatecontacts();
        } else if ($qreq->withdraw && $qreq->valid_post()) {
            $pp->handle_withdraw();
        } else if ($qreq->revive && $qreq->valid_post()) {
            $pp->handle_revive();
        } else if ($qreq->delete && $qreq->valid_post()) {
            $pp->handle_delete();
        } else if ($qreq->updateoverride && $qreq->valid_token()) {
            $pp->conf->redirect_self($qreq, ["m" => "edit", "forceShow" => 1]);
        }

        // render
        $pp->render();
    }
}

PaperPage::go($Me, $Qreq);
