<?php
// pages/p_paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Paper_Page {
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

    /** @param bool $error */
    function print_header($error) {
        PaperTable::print_header($this->pt, $this->qreq, $error);
    }

    /** @param MessageItem ...$mls */
    function error_exit(...$mls) {
        $this->print_header(true);
        Ht::stash_script("hotcrp.shortcut().add()");
        $this->conf->feedback_msg(...$mls);
        $this->conf->footer();
        throw new PageCompletion;
    }

    function load_prow() {
        // determine whether request names a paper
        try {
            $pr = new PaperRequest($this->user, $this->qreq, false);
            $this->prow = $this->conf->paper = $pr->prow;
        } catch (Redirection $redir) {
            assert(PaperRequest::simple_qreq($this->qreq));
            throw $redir;
        } catch (PermissionProblem $perm) {
            $this->error_exit(MessageItem::error("<5>" . $perm->set("listViewable", true)->unparse_html()));
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
            $this->conf->error_msg("<5>" . $whynot->unparse_html() . " The submission has not been withdrawn.");
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
        $this->conf->redirect_self($this->qreq);
    }

    function handle_revive() {
        if (($whynot = $this->user->perm_revive_paper($this->prow))) {
            $this->conf->error_msg("<5>" . $whynot->unparse_html());
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
            $this->conf->success_msg("<0>Submission deleted");
        } else if (!$this->user->can_administer($this->prow)) {
            $this->conf->feedback_msg(
                MessageItem::error("<0>Only program chairs can permanently delete a submission"),
                MessageItem::inform("<0>Authors can withdraw submissions.")
            );
        } else {
            // mail first, before contact info goes away
            if ($this->qreq->doemail) {
                HotCRPMailer::send_contacts("@deletepaper", $this->prow, [
                    "reason" => (string) $this->qreq->emailNote,
                    "confirm_message_for" => $this->user
                ]);
            }
            if ($this->prow->delete_from_database($this->user)) {
                $this->conf->success_msg("<0>Submission #{$this->prow->paperId} deleted");
            }
            $this->error_exit();
        }
    }

    /** @param string $dl
     * @param string $future_msg
     * @param string $past_msg
     * @return string */
    private function deadline_note($dl, $future_msg, $past_msg) {
        $deadline = $this->conf->unparse_setting_time_span($dl);
        $strong = false;
        if ($deadline === "N/A") {
            $msg = "";
        } else if ($this->conf->time_after_setting($dl)) {
            $msg = $past_msg;
            $strong = true;
        } else {
            $msg = $future_msg;
        }
        if ($msg !== "") {
            $msg = $this->conf->_($msg, $deadline);
        }
        if ($msg !== "" && $strong) {
            $msg = "<5><strong>" . Ftext::unparse_as($msg, 5) . "</strong>";
        }
        return $msg;
    }

    /** @return list<PaperOption> */
    private function missing_required_fields(PaperInfo $prow) {
        $missing = [];
        foreach ($prow->form_fields() as $o) {
            if ($o->test_required($prow) && !$o->value_present($prow->force_option($o)))
                $missing[] = $o;
        }
        return $missing;
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
                $this->ps->prepend_msg("<5><strong>Your uploaded files were ignored.</strong>", 2);
            }
            $this->ps->prepend_msg("<0>Changes not saved; please correct these errors and try again", 2);
            $conf->feedback_msg($this->ps);
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
                && !count(array_diff($this->ps->change_keys(), ["contacts", "status"]))) {
                $whynot = $this->user->perm_finalize_paper($this->prow);
            }
        }
        if ($whynot) {
            $this->conf->error_msg("<5>" . $whynot->unparse_html());
            $this->useRequest = !$is_new; // XXX used to have more complex logic
            return;
        }

        // actually update
        $this->ps->execute_save();

        $new_prow = $conf->paper_by_id($this->ps->paperId, $this->user, ["topics" => true, "options" => true]);
        if (!$new_prow) {
            $this->ps->prepend_msg("<0>Submission not saved; please correct these errors and try again", MessageSet::ERROR);
            $conf->feedback_msg($this->ps->decorated_message_list());
            return;
        }
        assert($this->user->can_view_paper($new_prow));

        // submit paper if no error so far
        $_GET["paperId"] = $_GET["p"] = $this->qreq->paperId = $this->qreq->p = $this->ps->paperId;

        if ($is_new) {
            $new_prow->anno["is_new"] = true;
        }
        $newsubmit = $new_prow->timeSubmitted > 0 && !$was_submitted;

        // confirmation message
        if ($action === "final") {
            $template = "@submitfinalpaper";
        } else if ($newsubmit) {
            $template = "@submitpaper";
        } else if ($is_new) {
            $template = "@registerpaper";
        } else {
            $template = "@updatepaper";
        }

        // log message
        $this->ps->log_save_activity($this->user, $action);

        // additional information
        $notes = [];
        $note_status = MessageSet::PLAIN;
        if ($action == "final") {
            if ($new_prow->timeFinalSubmitted <= 0) {
                $notes[] = $conf->_("<0>The final version has not yet been submitted.");
            }
            $notes[] = $this->deadline_note("final_soft",
                "<5>You have until %s to make further changes.",
                "<5>The deadline for submitting final versions was %s.");
        } else if ($new_prow->timeSubmitted > 0) {
            $notes[] = $conf->_("<0>The submission is ready for review.");
            $note_status = MessageSet::SUCCESS;
            if ($conf->setting("sub_freeze") <= 0) {
                $notes[] = $this->deadline_note("sub_update",
                    "<5>You have until %s to make further changes.", "");
            }
        } else {
            if ($conf->setting("sub_freeze") > 0) {
                $notes[] = $conf->_("<0>The submission has not yet been completed.");
            } else if (($missing = $this->missing_required_fields($new_prow))) {
                $notes[] = $conf->_("<5>The submission is not ready for review. Required fields %#s are missing.", PaperTable::field_title_links($missing, "missing_title"));
            } else {
                $notes[] = $conf->_("<0>The submission is marked as not ready for review.");
            }
            $note_status = MessageSet::WARNING_NOTE;
            $notes[] = $this->deadline_note("sub_update",
                "<5>You have until %s to make further changes.",
                "<5>The deadline for updating submissions was %s.");
            if (($msg = $this->deadline_note("sub_sub", "<5>Submissions incomplete as of %s will not be considered for review.", "")) !== "") {
                $notes[] = "<5><strong>" . Ftext::unparse_as($msg, 5) . "</strong>";
            }
        }

        // HTML confirmation
        $msgpos = 0;
        if (!$this->ps->has_change()) {
            if (!$this->ps->has_error()) {
                $this->ps->splice_msg($msgpos++, $conf->_("<0>No changes"), MessageSet::MARKED_NOTE);
            }
        } else if ($is_new) {
            $this->ps->splice_msg($msgpos++, $conf->_("<0>Registered submission as #%d", $new_prow->paperId), MessageSet::SUCCESS);
        } else {
            $t = $action === "final" ? "<0>Updated final version (changed %#s)" : "<0>Updated submission (changed %#s)";
            $chf = array_map(function ($f) { return $f->edit_title(); }, $this->ps->change_fields());
            $this->ps->splice_msg($msgpos++, $conf->_($t, $chf), MessageSet::SUCCESS);
        }
        if ($this->ps->has_error()) {
            if (!$this->ps->has_change()) {
                $this->ps->splice_msg($msgpos++, $conf->_("<0>Changes not saved. Please correct these issues and save again:"), MessageSet::ERROR);
            } else {
                $this->ps->splice_msg($msgpos++, $conf->_("<0>Please correct these issues and save again:"), MessageSet::URGENT_NOTE);
            }
        } else if ($this->ps->has_problem() && $conf->setting("sub_freeze") <= 0) {
            $this->ps->splice_msg($msgpos++, $conf->_("<0>Please check these issues before completing the submission:"), MessageSet::WARNING_NOTE);
        }
        $notes = array_filter($notes, function ($n) { return $n !== ""; });
        if (!empty($notes)) {
            $this->ps->splice_msg(-1, Ftext::join(" ", $notes), $note_status);
        }
        $conf->feedback_msg($this->ps->decorated_message_list());

        // mail confirmation to all contact authors if changed
        if ($this->ps->has_change()) {
            if (!$this->user->can_administer($new_prow) || $this->qreq->doemail) {
                $options = [];
                if ($this->user->can_administer($new_prow)) {
                    if (!$new_prow->has_author($this->user)) {
                        $options["confirm_message_for"] = $this->user;
                        $options["adminupdate"] = true;
                    }
                    if (isset($this->qreq->emailNote)) {
                        $options["reason"] = $this->qreq->emailNote;
                    }
                }
                if (!empty($notes)) {
                    $options["notes"] = Ftext::unparse_as(Ftext::join(" ", $notes), 0) . "\n\n";
                }
                if (!$is_new) {
                    $chf = array_map(function ($f) { return $f->edit_title(); }, $this->ps->change_fields());
                    if (!empty($chf)) {
                        $options["change"] = $conf->_("%#s were changed.", $chf);
                    }
                }
                HotCRPMailer::send_contacts($template, $new_prow, $options);
            }

            // other mail confirmations
            if ($action === "final" && $new_prow->timeFinalSubmitted > 0) {
                $followers = $new_prow->final_update_followers();
                $template = "@finalsubmitnotify";
            } else if ($is_new || $newsubmit) {
                $followers = $new_prow->submission_followers();
                $template = $newsubmit ? "@newsubmitnotify" : "@registernotify";
            } else {
                $followers = [];
                $template = "@none";
            }
            foreach ($followers as $minic) {
                if ($minic->contactId !== $this->user->contactId)
                    HotCRPMailer::send_to($minic, $template, ["prow" => $new_prow]);
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
            $conf->error_msg("<5>" . $this->prow->make_whynot(["permission" => "edit_contacts"])->unparse_html());
            return;
        }

        $this->ps = new PaperStatus($this->conf, $this->user);
        if (!$this->ps->prepare_save_paper_web($this->qreq, $this->prow, "updatecontacts")) {
            $conf->feedback_msg($this->ps);
            return;
        }

        if (!$this->ps->has_change()) {
            $this->ps->prepend_msg($conf->_("<0>No changes", $this->prow->paperId), MessageSet::MARKED_NOTE);
            $this->ps->warning_at(null, "");
            $conf->feedback_msg($this->ps);
        } else if ($this->ps->execute_save()) {
            $this->ps->prepend_msg($conf->_("<0>Updated contacts", $this->prow->paperId), MessageSet::SUCCESS);
            $conf->feedback_msg($this->ps);
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
                    $o->value_check($ov, $this->user);
                    $ov->append_messages_to($this->ps);
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

    function print() {
        // correct modes
        $this->pt = $pt = new PaperTable($this->user, $this->qreq, $this->prow);
        if ($pt->can_view_reviews()
            || $pt->mode === "re"
            || ($this->prow->paperId > 0 && $this->user->can_edit_some_review($this->prow))) {
            $pt->resolve_review(false);
        }
        $pt->resolve_comments();
        if ($pt->mode === "edit") {
            $this->prepare_edit_mode();
        }

        // produce paper table
        $this->print_header(false);
        $pt->print_paper_info();

        if ($pt->mode === "edit") {
            $pt->paptabEndWithoutReviews();
        } else {
            if ($pt->mode === "re") {
                $pt->print_review_form();
                $pt->print_main_link();
            } else if ($pt->can_view_reviews()) {
                $pt->paptabEndWithReviewsAndComments();
            } else {
                $pt->paptabEndWithReviewMessage();
                $pt->print_comments();
            }
            // restore comment across logout bounce
            if ($this->qreq->editcomment) {
                $cid = $this->qreq->c;
                $preferred_resp_round = null;
                if (($x = $this->qreq->response)) {
                    $preferred_resp_round = $this->conf->response_round($x);
                }
                if ($preferred_resp_round === null) {
                    $preferred_resp_round = $this->user->preferred_response_round($this->prow);
                }
                $j = null;
                foreach ($this->prow->viewable_comments($this->user) as $crow) {
                    if ($crow->commentId == $cid
                        || ($cid === null
                            && ($crow->commentType & CommentInfo::CT_RESPONSE) != 0
                            && $preferred_resp_round
                            && $crow->commentRound === $preferred_resp_round->number))
                        $j = $crow->unparse_json($this->user);
                }
                if (!$j) {
                    $j = (object) ["is_new" => true, "editable" => true];
                    if ($this->user->act_author_view($this->prow)) {
                        $j->by_author = true;
                    }
                    if ($preferred_resp_round) {
                        $j->response = $preferred_resp_round->name;
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

        $pp = new Paper_Page($user, $qreq);
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
        $pp->print();
    }
}
