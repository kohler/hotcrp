<?php
// pages/p_paper.php -- HotCRP paper view and edit page
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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

    /** @param ?FailureReason $perm */
    function error_exit($perm = null) {
        http_response_code($this->user->is_signed_in() ? 403 : 401);
        // 401 spec requires WWW-Authenticate, but many sites omit it
        if ($perm && (!$perm->secondary || $this->conf->saved_messages_status() < 2)) {
            $perm->set("expand", true);
            $perm->set("listViewable", $this->user->is_author() || $this->user->is_reviewer());
            $this->conf->feedback_msg($perm->message_list());
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
            $this->qreq->set_paper($pr->prow);
            $this->prow = $pr->prow;
        } catch (Redirection $redir) {
            throw $redir;
        } catch (FailureReason $perm) {
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
            $this->conf->feedback_msg($whynot->set("expand", true)->message_list(), MessageItem::urgent_note("<0>The {$this->conf->snouns[0]} has not been withdrawn."));
            return;
        }

        $reason = (string) $this->qreq->reason;
        if ($reason === ""
            && $this->user->can_manage($this->prow)
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
            $this->conf->feedback_msg($whynot->set("expand", true)->message_list());
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
        } else if (!$this->user->can_manage($this->prow)) {
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

    private function handle_if_unmodified_since() {
        $stripfields = $this->ps->strip_unchanged_fields_qreq($this->qreq, $this->prow);
        $fields = $this->ps->changed_fields_qreq($this->qreq, $this->prow);
        if (empty($fields) && $this->prow->paperId) {
            $this->conf->redirect_self($this->qreq, ["p" => $this->prow->paperId, "m" => "edit"]);
        } else {
            $this->ps->inform_at("status:if_unmodified_since",
                $this->conf->_("<5>Your changes were not saved because the {submission} has changed since you last loaded this page. Unsaved changes to {:list} are highlighted. Check them and save again, or <a href=\"{url}\" class=\"uic js-ignore-unload-protection\">discard your edits</a>.",
                    PaperTable::field_title_links($fields, "edit_title"),
                    new FmtArg("url", $this->prow->hoturl(["m" => "edit"], Conf::HOTURL_RAW), 0)));
        }
    }

    function handle_update() {
        $conf = $this->conf;
        // XXX lock tables
        $is_new = $this->prow->is_new();
        $was_submitted = $this->prow->timeSubmitted > 0;
        $is_final = $this->prow->phase() === PaperInfo::PHASE_FINAL
            && $this->qreq["status:phase"] === "final";
        $this->useRequest = true;

        $this->ps = new PaperStatus($this->user);
        $prepared = $this->ps->prepare_save_paper_web($this->qreq, $this->prow);

        if (!$prepared) {
            if ($is_new && $this->qreq->has_files()) {
                // XXX save uploaded files
                $this->ps->prepend_item(MessageItem::error("<5><strong>Your uploaded files were ignored.</strong>"));
            }
            if ($this->ps->has_error_at("status:if_unmodified_since")) {
                $this->handle_if_unmodified_since();
            } else {
                $this->ps->prepend_item(MessageItem::error("<5><strong>Changes not saved.</strong> Please correct these issues and try again."));
            }
            $conf->feedback_msg($this->ps->decorated_message_list());
            return;
        }

        // check deadlines
        // NB PaperStatus also checks deadlines now; this is likely redundant.
        $whynot = $this->user->perm_edit_paper($this->prow);
        if ($whynot
            && !$is_new
            && !$is_final
            && !count(array_diff($this->ps->changed_keys(), ["contacts", "status"]))) {
            $whynot = $this->user->perm_finalize_paper($this->prow);
        }
        if ($whynot) {
            $conf->feedback_msg($whynot->set("expand", true)->message_list());
            $this->useRequest = !$is_new; // XXX used to have more complex logic
            $this->ps->abort_save();
            return;
        }

        // actually update
        $this->ps->execute_save();

        $new_prow = $this->ps->saved_prow();
        if (!$new_prow) {
            $conf->feedback_msg(
                MessageItem::error($conf->_("<0>{Submission} not saved; please correct these errors and try again")),
                $this->ps->decorated_message_list()
            );
            return;
        }
        if (!$this->user->can_view_paper($new_prow)) {
            error_log("{$conf->dbname}: user {$this->user->email} #{$this->user->contactId} cannot view new paper #{$new_prow->paperId} because " . json_encode($this->user->perm_view_paper($new_prow)));
        }
        assert($this->user->can_view_paper($new_prow));

        // submit paper if no error so far
        $_GET["paperId"] = $_GET["p"] = $this->qreq->paperId = $this->qreq->p = $this->ps->paperId;

        $newsubmit = $new_prow->timeSubmitted > 0 && !$was_submitted;
        $sr = $new_prow->submission_round();

        // log message
        $this->ps->log_save_activity();

        // HTML confirmation
        $ml = [];
        if (!$this->ps->has_change()) {
            if (!$this->ps->has_error()) {
                $ml[] = MessageItem::warning_note($conf->_("<0>No changes"));
            }
        } else if ($new_prow->is_new()) {
            $ml[] = MessageItem::success($conf->_("<0>Registered {submission} as #{}", $new_prow->paperId));
        } else {
            $chf = array_map(function ($f) { return $f->edit_title(); }, $this->ps->changed_fields());
            $ml[] = MessageItem::success($conf->_("<0>Updated {submission} (changed {:list})", $chf, new FmtArg("phase", $is_final ? "final" : "review")));
        }
        if ($this->ps->has_error()) {
            if (!$this->ps->has_change()) {
                $ml[] = MessageItem::error($conf->_("<5><strong>Changes not saved.</strong> Please correct these issues and save again."));
            } else {
                $ml[] = MessageItem::urgent_note($conf->_("<0>Please correct these issues and save again."));
            }
        } else if (($this->ps->has_problem() || $this->ps->has_urgent_note())
                   && $this->user->can_edit_paper($new_prow)) {
            $ml[] = MessageItem::warning_note($conf->_("<0>Please check these issues before completing the {submission}."));
        }
        $notes_mi = $this->ps->save_notes_message();
        $conf->feedback_msg($ml, $this->ps->decorated_message_list(), $notes_mi);

        // mail notification
        if ($this->ps->has_change()) {
            if ($this->user->can_manage($new_prow)) {
                if (friendly_boolean($this->qreq["status:notify"])) {
                    $this->ps->set_notify_reason($this->qreq["status:notify_reason"] ?? "");
                } else {
                    $this->ps->set_notify_authors(false);
                }
            }
            $this->ps->notify_followers($notes_mi);
        }

        $this->qreq->set_paper($new_prow);
        $this->prow = $new_prow;
        if (!$this->ps->has_error() || $new_prow->is_new()) {
            $conf->redirect_self($this->qreq, ["p" => $new_prow->paperId, "m" => "edit"]);
        }
    }

    function handle_updatecontacts() {
        $conf = $this->conf;
        $this->useRequest = true;

        if (!$this->user->can_manage($this->prow)
            && !$this->prow->has_author($this->user)) {
            $conf->feedback_msg($this->prow->failure_reason(["permission" => "contact:edit", "expand" => true])->message_list());
            return;
        }

        $this->ps = new PaperStatus($this->user);
        $this->qreq["status:phase"] = "contacts";
        if (!$this->ps->prepare_save_paper_web($this->qreq, $this->prow)) {
            $conf->feedback_msg($this->ps->decorated_message_list([PaperOption::CONTACTSID]));
            return;
        }

        if (!$this->ps->has_change()) {
            $ml = [MessageItem::warning_note("<0>No changes"), MessageItem::warning("")];
        } else  if ($this->ps->execute_save()) {
            $ml = [MessageItem::success($conf->_("<0>Updated contacts"))];
            $this->ps->log_save_activity();
        } else {
            $ml = [];
        }
        if (!empty($ml)) {
            $conf->feedback_msg($ml, $this->ps->decorated_message_list([PaperOption::CONTACTSID]));
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
                    $this->ps->append_messages_from($ov);
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
            $this->pt()->add_pre_status_feedback(MessageItem::warning_note($m));
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
        if ($pp->prow->is_new()) {
            if (!$pp->prow->submission_round()->time_register(true)
                && $user->privChair) {
                $user->add_overrides(Contact::OVERRIDE_CONFLICT);
            }
            if (($perm = $user->perm_edit_paper($pp->prow))) {
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
        if (($capuid = $user->reviewer_capability($pp->prow))
            && $capuid !== $user->contactXid) {
            $pp->print_capability_user_message($capuid);
        }

        // render
        $pp->print();
    }
}
