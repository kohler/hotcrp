<?php
// pages/p_paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @return PaperTable */
    function pt() {
        if (!$this->pt) {
            $this->pt = new PaperTable($this->user, $this->qreq, $this->prow);
        }
        return $this->pt;
    }

    /** @param bool $error */
    function print_header($error) {
        $pt = $this->prow ? $this->pt() : null;
        PaperTable::print_header($pt, $this->qreq, $error);
    }

    /** @param ?PermissionProblem $perm */
    function error_exit($perm = null) {
        if ($perm) {
            $perm->set("listViewable", $this->user->is_author() || $this->user->is_reviewer());
            if (!$perm->secondary || $this->conf->saved_messages_status() < 2) {
                $this->conf->error_msg("<5>" . $perm->unparse_html());
            }
        }
        $this->print_header(true);
        Ht::stash_script("hotcrp.shortcut().add()");
        $this->qreq->print_footer();
        throw new PageCompletion;
    }

    function load_prow() {
        // determine whether request names a paper
        try {
            $pr = new PaperRequest($this->qreq, false);
            $this->prow = $this->conf->paper = $pr->prow;
        } catch (Redirection $redir) {
            assert(PaperRequest::simple_qreq($this->qreq));
            throw $redir;
        } catch (PermissionProblem $perm) {
            $this->error_exit($perm);
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
            $this->conf->error_msg("<5>" . $whynot->unparse_html() . " The {$this->conf->snouns[0]} has not been withdrawn.");
            return;
        }

        $reason = (string) $this->qreq->reason;
        if ($reason === ""
            && $this->user->can_administer($this->prow)
            && $this->qreq["status:notify"] > 0) {
            $reason = (string) $this->qreq["status:notify_reason"];
        }

        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
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

        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->enable_papers($this->prow);
        $aset->parse("paper,action\n{$this->prow->paperId},revive");
        if (!$aset->execute()) {
            error_log("{$this->conf->dbname}: revive #{$this->prow->paperId} failure: " . json_encode($aset->json_result()));
        }
        $this->conf->redirect_self($this->qreq);
    }

    function handle_delete() {
        if ($this->prow->paperId <= 0) {
            $this->conf->success_msg("<0>{$this->conf->snouns[2]} deleted");
        } else if (!$this->user->can_administer($this->prow)) {
            $this->conf->feedback_msg(
                MessageItem::error("<0>Only program chairs can permanently delete a {$this->conf->snouns[0]}"),
                MessageItem::inform("<0>Authors can withdraw {$this->conf->snouns[1]}.")
            );
        } else {
            // mail first, before contact info goes away
            if ($this->qreq["status:notify"]) {
                HotCRPMailer::send_contacts("@deletepaper", $this->prow, [
                    "reason" => (string) $this->qreq["status:notify_reason"],
                    "confirm_message_for" => $this->user
                ]);
            }
            if ($this->prow->delete_from_database($this->user)) {
                $this->conf->success_msg("<0>{$this->conf->snouns[2]} #{$this->prow->paperId} deleted");
            }
            $this->error_exit();
        }
    }

    /** @param int $t
     * @param string $future_msg
     * @param string $past_msg
     * @return string */
    private function time_note($t, $future_msg, $past_msg) {
        if ($t <= 0) {
            return "";
        }
        $msg = $t < Conf::$now ? $past_msg : $future_msg;
        if ($msg !== "") {
            $msg = $this->conf->_($msg, $this->conf->unparse_time_with_local_span($t));
        }
        if ($msg !== "" && $t < Conf::$now) {
            $msg = "<5><strong>" . Ftext::as(5, $msg) . "</strong>";
        }
        return $msg;
    }

    private function handle_if_unmodified_since() {
        $stripfields = $this->ps->strip_unchanged_fields_qreq($this->qreq, $this->prow);
        $fields = $this->ps->changed_fields_qreq($this->qreq, $this->prow);
        if (empty($fields) && $this->prow->paperId) {
            $this->conf->redirect_self($this->qreq, ["p" => $this->prow->paperId, "m" => "edit"]);
        } else {
            $this->ps->inform_at("status:if_unmodified_since",
                $this->conf->_("<5>Your unsaved changes to {:list} are highlighted. Check them and save again, or <a href=\"{url}\" class=\"uic js-ignore-unload-protection\">discard your edits</a>.",
                    PaperTable::field_title_links($fields, "edit_title"),
                    new FmtArg("url", $this->prow->hoturl(["m" => "edit"], Conf::HOTURL_RAW), 0)));
        }
    }

    function handle_update() {
        $conf = $this->conf;
        // XXX lock tables
        $is_new = $this->prow->paperId <= 0;
        $was_submitted = $this->prow->timeSubmitted > 0;
        $is_final = $this->prow->phase() === PaperInfo::PHASE_FINAL
            && $this->qreq["status:phase"] === "final";
        $this->useRequest = true;

        $this->ps = new PaperStatus($this->user);
        $prepared = $this->ps->prepare_save_paper_web($this->qreq, $this->prow);

        if (!$prepared) {
            if ($is_new && $this->qreq->has_files()) {
                // XXX save uploaded files
                $this->ps->prepend_msg("<5><strong>Your uploaded files were ignored.</strong>", 2);
            }
            if ($this->ps->has_error_at("status:if_unmodified_since")) {
                $this->handle_if_unmodified_since();
            } else {
                $this->ps->prepend_msg("<0>Changes not saved; please correct these errors and try again.", 2);
            }
            $conf->feedback_msg($this->ps->decorated_message_list());
            return;
        }

        // check deadlines
        // NB PaperStatus also checks deadlines now; this is likely redundant.
        if ($is_new) {
            // we know that can_start_paper implies can_finalize_paper
            $whynot = $this->user->perm_start_paper($this->prow);
        } else {
            $whynot = $this->user->perm_edit_paper($this->prow);
            if ($whynot
                && !$is_final
                && !count(array_diff($this->ps->changed_keys(), ["contacts", "status"]))) {
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
            $this->ps->prepend_msg($conf->_("<0>{Submission} not saved; please correct these errors and try again"), MessageSet::ERROR);
            $conf->feedback_msg($this->ps->decorated_message_list());
            return;
        }
        if (!$this->user->can_view_paper($new_prow)) {
            error_log("{$conf->dbname}: user {$this->user->email} #{$this->user->contactId} cannot view new paper #{$new_prow->paperId} because " . json_encode($this->user->perm_view_paper($new_prow)));
        }
        assert($this->user->can_view_paper($new_prow));

        // submit paper if no error so far
        $_GET["paperId"] = $_GET["p"] = $this->qreq->paperId = $this->qreq->p = $this->ps->paperId;

        if ($is_new) {
            $new_prow->set_is_new(true);
        }
        $newsubmit = $new_prow->timeSubmitted > 0 && !$was_submitted;
        $sr = $new_prow->submission_round();

        // confirmation message
        if ($is_final) {
            $template = "@submitfinalpaper";
        } else if ($newsubmit) {
            $template = "@submitpaper";
        } else if ($is_new) {
            $template = "@registerpaper";
        } else {
            $template = "@updatepaper";
        }

        // log message
        $this->ps->log_save_activity();

        // additional information
        $notes = [];
        $note_status = MessageSet::PLAIN;
        if ($this->ps->has_error()) {
            // only print save error message; to do otherwise is confusing
        } else if ($is_final) {
            if ($new_prow->timeFinalSubmitted <= 0) {
                $notes[] = $conf->_("<0>The final version has not yet been submitted.");
            }
            $notes[] = $this->time_note($this->conf->setting("final_soft") ?? 0,
                "<5>You have until {} to make further changes.",
                "<5>The deadline for submitting final versions was {}.");
        } else if ($new_prow->timeSubmitted > 0) {
            $note_status = MessageSet::SUCCESS;
            $notes[] = $conf->_("<0>The {submission} is ready for review.");
            if (!$sr->freeze) {
                $notes[] = $this->time_note($sr->update,
                    "<5>You have until {} to make further changes.", "");
            }
        } else {
            $note_status = MessageSet::URGENT_NOTE;
            if ($sr->freeze) {
                $notes[] = $conf->_("<0>This {submission} has not yet been completed.");
            } else if (($missing = PaperTable::missing_required_fields($new_prow))) {
                $notes[] = $conf->_("<5>This {submission} is not ready for review. Required fields {:list} are missing.", PaperTable::field_title_links($missing, "missing_title"));
            } else {
                $first = $conf->_("<5>This {submission} is marked as not ready for review.");
                $notes[] = "<5><strong>" . Ftext::as(5, $first) . "</strong>";
            }
            $notes[] = $this->time_note($sr->update,
                "<5>You have until {} to make further changes.",
                "<5>The deadline for updating {submissions} was {}.");
            if (($msg = $this->time_note($sr->submit,
                "<5>{Submissions} incomplete as of {} will not be considered.", "")) !== "") {
                $notes[] = $msg;
            }
        }

        // HTML confirmation
        $msgpos = 0;
        if (!$this->ps->has_change()) {
            if (!$this->ps->has_error()) {
                $this->ps->splice_msg($msgpos++, $conf->_("<0>No changes"), MessageSet::WARNING_NOTE);
            }
        } else if ($is_new) {
            $this->ps->splice_msg($msgpos++, $conf->_("<0>Registered {submission} as #{}", $new_prow->paperId), MessageSet::SUCCESS);
        } else {
            $chf = array_map(function ($f) { return $f->edit_title(); }, $this->ps->changed_fields());
            $this->ps->splice_msg($msgpos++, $conf->_("<0>Updated {submission} (changed {:list})", $chf, new FmtArg("phase", $is_final ? "final" : "review")), MessageSet::SUCCESS);
        }
        if ($this->ps->has_error()) {
            if (!$this->ps->has_change()) {
                $this->ps->splice_msg($msgpos++, $conf->_("<5><strong>Changes not saved.</strong> Please correct these issues and save again."), MessageSet::ERROR);
            } else {
                $this->ps->splice_msg($msgpos++, $conf->_("<0>Please correct these issues and save again."), MessageSet::URGENT_NOTE);
            }
        } else if ($this->ps->has_problem() && !$sr->freeze) {
            $this->ps->splice_msg($msgpos++, $conf->_("<0>Please check these issues before completing the {submission}."), MessageSet::WARNING_NOTE);
        }
        $notes_ftext = Ftext::join_nonempty(" ", $notes);
        if ($notes_ftext !== "") {
            $this->ps->splice_msg(-1, $notes_ftext, $note_status);
        }
        $conf->feedback_msg($this->ps->decorated_message_list());

        // mail confirmation to all contact authors if changed
        if ($this->ps->has_change()) {
            if (!$this->user->can_administer($new_prow) || $this->qreq["status:notify"]) {
                $options = [];
                if ($this->user->can_administer($new_prow)) {
                    if (!$new_prow->has_author($this->user)) {
                        $options["confirm_message_for"] = $this->user;
                        $options["adminupdate"] = true;
                    }
                    if (isset($this->qreq["status:notify_reason"])) {
                        $options["reason"] = $this->qreq["status:notify_reason"];
                    }
                }
                if ($notes_ftext !== "") {
                    $options["notes"] = Ftext::as(0, $notes_ftext) . "\n\n";
                }
                if (!$is_new) {
                    $chf = array_map(function ($f) { return $f->edit_title(); }, $this->ps->changed_fields());
                    if (!empty($chf)) {
                        $options["change"] = $conf->_("{:list} were changed.", $chf);
                    }
                }
                HotCRPMailer::send_contacts($template, $new_prow, $options);
            }

            // other mail confirmations
            if ($is_final && $new_prow->timeFinalSubmitted > 0) {
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
        $this->useRequest = false;
    }

    function handle_updatecontacts() {
        $conf = $this->conf;
        $this->useRequest = true;

        if (!$this->user->can_administer($this->prow)
            && !$this->prow->has_author($this->user)) {
            $conf->error_msg("<5>" . $this->prow->make_whynot(["permission" => "contact:edit"])->unparse_html());
            return;
        }

        $this->ps = new PaperStatus($this->user);
        $this->qreq["status:phase"] = "contacts";
        if (!$this->ps->prepare_save_paper_web($this->qreq, $this->prow)) {
            $conf->feedback_msg($this->ps->decorated_message_list([PaperOption::CONTACTSID]));
            return;
        }

        if (!$this->ps->has_change()) {
            $this->ps->prepend_msg($conf->_("<0>No changes", $this->prow->paperId), MessageSet::WARNING_NOTE);
            $this->ps->warning_at(null, "");
            $conf->feedback_msg($this->ps->decorated_message_list([PaperOption::CONTACTSID]));
        } else if ($this->ps->execute_save()) {
            $this->ps->prepend_msg($conf->_("<0>Updated contacts", $this->prow->paperId), MessageSet::SUCCESS);
            $conf->feedback_msg($this->ps->decorated_message_list([PaperOption::CONTACTSID]));
            $this->ps->log_save_activity();
        }

        if (!$this->ps->has_error()) {
            $conf->redirect_self($this->qreq);
        }
        $this->useRequest = false;
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

        $this->pt->set_edit_status($this->ps, $this->useRequest);
    }

    /** @param int $capuid */
    private function print_capability_user_message($capuid) {
        if (($u = $this->conf->user_by_id($capuid, USER_SLICE))) {
            $m = $this->conf->_("<0>You’re accessing this {submission} using a special link for reviewer {reviewer}",
                new FmtArg("reviewer", $u->email, 0),
                new FmtArg("self", $this->user->email, 0),
                new FmtArg("signinurl", $this->conf->hoturl_raw("signin", ["email" => $u->email, "cap" => null])));
            $this->pt()->add_pre_status_feedback(new MessageItem(null, $m, MessageSet::WARNING_NOTE));
        }
    }

    function print() {
        // correct modes
        $pt = $this->pt();
        $pt->resolve_comments();
        if ($pt->can_view_reviews()
            || $pt->mode === "re"
            || ($this->prow->paperId > 0 && $this->user->can_edit_some_review($this->prow))) {
            $pt->resolve_review(false);
        }
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
                $this->_stash_edit_comment();
            }
        }

        echo "</article>\n";
        $this->qreq->print_footer();
    }

    private function _stash_edit_comment() {
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
                    && $crow->commentRound === $preferred_resp_round->id)) {
                $j = $crow->unparse_json($this->user);
            }
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

    static function go(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->m) && ($pc = $qreq->path_component(1))) {
            $qreq->m = $pc;
        } else if (!isset($qreq->m) && isset($qreq->mode)) {
            $qreq->m = $qreq->mode;
        }

        $pp = new Paper_Page($user, $qreq);
        $pp->load_prow();

        // new papers: maybe fix user, maybe error exit
        if ($pp->prow->paperId === 0) {
            if (!$pp->prow->submission_round()->time_register(true)
                && $user->privChair) {
                $user->add_overrides(Contact::OVERRIDE_CONFLICT);
            }
            if (($perm = $user->perm_start_paper($pp->prow))) {
                $pp->error_exit($perm);
            }
        }

        // fix request
        $pp->useRequest = isset($qreq->title) && $qreq->has_annex("after_login");
        if ($qreq["status:notify_reason"] === "Optional explanation") {
            unset($qreq["status:notify_reason"]);
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
            if (!isset($qreq["status:phase"])) {
                $qreq["status:phase"] = $qreq->submitfinal ? "final" : "review"; /* XXX backward compat */
            }
            $pp->handle_update();
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

        // capability messages: decline, accept to different user
        if (($capuid = $user->capability("@ra{$pp->prow->paperId}"))
            && $capuid !== $user->contactXid) {
            $pp->print_capability_user_message($capuid);
        }

        // render
        $pp->print();
    }
}
