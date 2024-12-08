<?php
// pages/p_review.php -- HotCRP paper review display/edit page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Review_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var PaperInfo */
    public $prow;
    /** @var ?ReviewInfo */
    public $rrow;
    /** @var bool */
    public $rrow_explicit;
    /** @var PaperTable */
    public $pt;
    /** @var ?ReviewValues */
    public $rv;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    /** @return PaperTable */
    function pt() {
        if ($this->pt === null) {
            $this->pt = new PaperTable($this->user, $this->qreq, $this->prow);
        }
        return $this->pt;
    }

    /** @param bool $is_error */
    function print_header($is_error) {
        PaperTable::print_header($this->pt, $this->qreq, $is_error);
    }

    function error_exit() {
        $this->print_header(true);
        Ht::stash_script("hotcrp.shortcut().add()");
        $this->qreq->print_footer();
        throw new PageCompletion;
    }

    function load_prow() {
        // determine whether request names a paper
        try {
            $pr = new PaperRequest($this->qreq, true);
            $this->qreq->set_paper($pr->prow);
            $this->prow = $pr->prow;
            if ($pr->rrow) {
                $this->rrow = $pr->rrow;
                $this->rrow_explicit = true;
            } else {
                $this->rrow = $this->my_rrow($this->qreq->m === "rea");
                $this->rrow_explicit = false;
            }
        } catch (Redirection $redir) {
            throw $redir;
        } catch (FailureReason $perm) {
            $perm->set("expand", true);
            $perm->set("listViewable", $this->user->is_author() || $this->user->is_reviewer());
            if (!$perm->secondary || $this->conf->saved_messages_status() < 2) {
                $this->conf->feedback_msg($perm->message_list());
            }
            $this->error_exit();
        }
    }

    /** @return ?ReviewInfo */
    function my_rrow($prefer_approvable) {
        $myrrow = $apprrow1 = $apprrow2 = null;
        $admin = $this->user->can_administer($this->prow);
        foreach ($this->prow->reviews_as_display() as $rrow) {
            if ($this->user->can_view_review($this->prow, $rrow)) {
                if ($rrow->contactId === $this->user->contactId
                    || (!$myrrow && $this->user->is_my_review($rrow))) {
                    $myrrow = $rrow;
                } else if ($rrow->reviewStatus === ReviewInfo::RS_DELIVERED
                           && !$apprrow1
                           && $rrow->requestedBy === $this->user->contactXid) {
                    $apprrow1 = $rrow;
                } else if ($rrow->reviewStatus === ReviewInfo::RS_DELIVERED
                           && !$apprrow2
                           && $admin) {
                    $apprrow2 = $rrow;
                }
            }
        }
        if (($apprrow1 || $apprrow2)
            && ($prefer_approvable || !$myrrow)) {
            return $apprrow1 ?? $apprrow2;
        } else {
            return $myrrow;
        }
    }

    function reload_prow() {
        $this->prow->load_reviews(true);
        if ($this->rrow) {
            $this->rrow = $this->prow->review_by_id($this->rrow->reviewId);
        } else {
            $this->rrow = $this->prow->review_by_ordinal_id($this->qreq->reviewId);
        }
    }

    function handle_cancel() {
        $this->conf->redirect($this->prow->hoturl([], Conf::HOTURL_RAW));
    }

    function handle_update() {
        $rv = new ReviewValues($this->conf);
        if ($rv->parse_qreq($this->qreq)
            && $rv->check_and_save($this->user, $this->prow, $this->rrow)) {
            $this->qreq->r = $this->qreq->reviewId = $rv->review_ordinal_id;
        }
        $rv->report();
        if (!$rv->has_error() && !$rv->has_problem_at("ready")) {
            $this->conf->redirect_self($this->qreq);
        }
        $this->rv = $rv;
        $this->reload_prow();
    }

    function handle_upload_form() {
        if (!$this->qreq->has_file("file")) {
            $this->conf->error_msg("<0>File upload required");
            return;
        }
        $rv = (new ReviewValues($this->conf))
            ->set_text($this->qreq->file_content("file"), $this->qreq->file_filename("file"));
        $match = $other = false;
        while ($rv->set_req_override(!!$this->qreq->override)->parse_text()) {
            if ($rv->req_pid() === $this->prow->paperId) {
                $match = true;
                if ($rv->check_and_save($this->user, $this->prow, $this->rrow)) {
                    $this->qreq->r = $this->qreq->reviewId = $rv->review_ordinal_id;
                }
            } else {
                $other = true;
            }
            $rv->clear_req();
        }
        if (!$match && !$other) {
            $rv->msg_at(null, "<0>Uploaded file had no valid review forms", MessageSet::ERROR);
        } else if (!$match) {
            $rv->msg_at(null, "<0>Uploaded form was not for this {submission}", MessageSet::ERROR);
        } else if ($other) {
            $rv->msg_at(null, "<0>Reviews for other {submissions} ignored", MessageSet::WARNING);
            $rv->msg_at(null, "<5>Upload multiple-review files " . Ht::link("here", $this->conf->hoturl("offline")) . ".", MessageSet::INFORM);
        }
        $rv->report();
        if (!$rv->has_error()) {
            $this->conf->redirect_self($this->qreq);
        }
        $this->reload_prow();
    }

    function handle_download_form() {
        $filename = "review-" . ($this->rrow ? $this->rrow->unparse_ordinal_id() : $this->prow->paperId);
        $rf = $this->conf->review_form();
        $this->conf->make_text_downloader($filename)
            ->set_content($rf->text_form_header(false)
                . $rf->text_form($this->prow, $this->rrow, $this->user))
            ->emit();
        throw new PageCompletion;
    }

    function handle_download_text() {
        $rf = $this->conf->review_form();
        if ($this->rrow && $this->rrow_explicit) {
            $this->conf->make_csvg("review-" . $this->rrow->unparse_ordinal_id(), CsvGenerator::TYPE_STRING)
                ->add_string($rf->unparse_text($this->prow, $this->rrow, $this->user))
                ->emit();
        } else {
            $lastrc = null;
            $texts = [
                "{$this->conf->short_name} Paper #{$this->prow->paperId} Reviews and Comments\n",
                str_repeat("=", 75) . "\n",
                prefix_word_wrap("", "Paper #{$this->prow->paperId} {$this->prow->title}", 0, 75),
                "\n\n"
            ];
            foreach ($this->prow->viewable_submitted_reviews_and_comments($this->user) as $rc) {
                $texts[] = PaperInfo::review_or_comment_text_separator($lastrc, $rc);
                if (isset($rc->reviewId)) {
                    $texts[] = $rf->unparse_text($this->prow, $rc, $this->user, ReviewForm::UNPARSE_NO_TITLE);
                } else {
                    $texts[] = $rc->unparse_text($this->user, ReviewForm::UNPARSE_NO_TITLE);
                }
                $lastrc = $rc;
            }
            if (!$lastrc) {
                $texts[] = "Nothing to show.\n";
            }
            $this->conf->make_csvg("reviews-{$this->prow->paperId}", CsvGenerator::TYPE_STRING)
                ->append_strings($texts)
                ->emit();
        }
        throw new PageCompletion;
    }

    function handle_adopt() {
        if (!$this->rrow || !$this->rrow_explicit) {
            $this->conf->error_msg("<0>Review not found");
            return;
        } else if (!$this->user->can_approve_review($this->prow, $this->rrow)) {
            return;
        }

        $rv = new ReviewValues($this->conf);
        $my_rrow = $this->prow->review_by_user($this->user);
        $want_rid = $this->rrow->unparse_ordinal_id();
        if ($rv->parse_qreq($this->qreq)
            && $rv->check_vtag($this->rrow)) {
            $rv->set_req_ready(!!$this->qreq->adoptsubmit);
            // Be careful about if_vtag_match, since vtag corresponds to
            // *subreview*, not $my_rrow
            $rv->clear_req_vtag();
            if ($rv->check_and_save($this->user, $this->prow, $my_rrow)) {
                $want_rid = $rv->review_ordinal_id;
                if (!$rv->has_problem_at("ready")) {
                    // approve the source review
                    $rvx = new ReviewValues($this->conf);
                    $rvx->set_req_approval("approved");
                    $rvx->check_and_save($this->user, $this->prow, $this->rrow);
                }
            }
        }
        $rv->report();
        $this->conf->redirect_self($this->qreq, ["r" => $want_rid]);
    }

    function handle_delete() {
        if (!$this->rrow || !$this->rrow_explicit) {
            $this->conf->error_msg("<0>Review not found");
            return;
        } else if (!$this->user->can_administer($this->prow)) {
            return;
        }
        $result = $this->conf->qe("delete from PaperReview where paperId=? and reviewId=?", $this->prow->paperId, $this->rrow->reviewId);
        if ($result->affected_rows) {
            $this->user->log_activity_for($this->rrow->contactId, "Review {$this->rrow->reviewId} deleted", $this->prow);
            $this->conf->success_msg("<0>Review deleted");
            $this->conf->qe("delete from ReviewRating where paperId=? and reviewId=?", $this->prow->paperId, $this->rrow->reviewId);
            if ($this->rrow->reviewToken !== 0) {
                $this->conf->update_rev_tokens_setting(-1);
            }
            if ($this->rrow->reviewType === REVIEW_META) {
                $this->conf->update_metareviews_setting(-1);
            }

            // perhaps a delegator needs to redelegate
            if ($this->rrow->reviewType < REVIEW_SECONDARY
                && $this->rrow->requestedBy > 0) {
                $this->conf->update_review_delegation($this->prow->paperId, $this->rrow->requestedBy, -1);
            }
        }
        $this->conf->redirect_self($this->qreq, ["r" => null, "reviewId" => null]);
    }

    function handle_unsubmit() {
        if ($this->rrow
            && $this->rrow->reviewStatus >= ReviewInfo::RS_DELIVERED
            && $this->user->can_administer($this->prow)) {
            $rv = new ReviewValues($this->conf);
            $rv->set_can_unsubmit(true)->set_req_ready(false);
            if ($rv->check_and_save($this->user, $this->prow, $this->rrow)) {
                $this->conf->success_msg("<0>Review unsubmitted");
            }
            $this->conf->redirect_self($this->qreq);
        }
    }

    function handle_valid_post() {
        $qreq = $this->qreq;
        if ($qreq->update
            || $qreq->savedraft
            || $qreq->submitreview
            || $qreq->approvesubreview
            || $qreq->approvesubmit) {
            $this->handle_update();
        } else if ($qreq->adoptreview) {
            $this->handle_adopt();
        } else if ($qreq->upload) {
            $this->handle_upload_form();
        } else if ($qreq->unsubmitreview) {
            $this->handle_unsubmit();
        } else if ($qreq->deletereview) {
            $this->handle_delete();
        }
    }

    /** @return ?int */
    function current_capability_rrid() {
        if (($capuid = $this->user->reviewer_capability($this->prow))) {
            $u = $this->conf->user_by_id($capuid, USER_SLICE);
            $rrow = $this->prow->review_by_user($capuid);
            $refs = $u ? $this->prow->review_refusals_by_user($u) : [];
            if ($rrow && (!$this->rrow || $this->rrow === $rrow)) {
                return $rrow->reviewId;
            } else if (!$rrow && !empty($refs) && $refs[0]->refusedReviewId > 0) {
                return $refs[0]->refusedReviewId;
            }
        }
        return null;
    }

    function add_capability_user_message($capuid) {
        if (($u = $this->conf->user_by_id($capuid, USER_SLICE))) {
            if (PaperRequest::simple_qreq($this->qreq)
                && ($i = Contact::session_index_by_email($this->qreq, $u->email)) >= 0) {
                $selfurl = $this->conf->selfurl($this->qreq, null, Conf::HOTURL_SITEREL | Conf::HOTURL_RAW);
                $this->conf->redirect($this->qreq->navigation()->base_absolute() . "u/{$i}/{$selfurl}");
                return;
            }

            $hemail = htmlspecialchars($u->email);
            if ($this->user->has_email()) {
                $mx = "You’re accessing this review using a special link for reviewer {$hemail}. (You are signed in as " . htmlspecialchars($this->user->email) . ".)";
                if ($this->rrow->reviewStatus <= ReviewInfo::RS_DRAFTED) {
                    $m = "<5><p class=\"mb-0\">{$mx} If you wish, you can reassign the linked review to one your current accounts.</p>"
                        . Ht::form("", ["class" => "has-fold foldo"])
                        . '<div class="aab mt-2 fx">';
                    foreach ($this->user->session_users($this->qreq) as $e) {
                        if ($e === "") {
                            continue;
                        }
                        $url = $this->conf->hoturl("=api/claimreview", ["p" => $this->prow->paperId, "r" => $this->rrow->reviewId, "email" => $e, "smsg" => 1]);
                        $m .= '<div class="aabut">'
                            . Ht::submit("Reassign to " . htmlspecialchars($e), [
                                "formaction" => $url, "class" => "ui js-acceptish-review"
                            ]) . '</div>';
                    }
                    $m .= '</div></form>';
                } else {
                    $m = "<5>{$mx}";
                }
            } else {
                $m = "<5>You’re accessing this review using a special link for reviewer {$hemail}. " . Ht::link("Sign in to the site", $this->conf->hoturl("signin", ["email" => $u->email, "cap" => null]), ["class" => "nw"]);
            }
            $this->pt()->add_pre_status_feedback(new MessageItem(null, $m, MessageSet::WARNING_NOTE));
        }
    }

    function print() {
        $pt = $this->pt();
        $pt->resolve_comments();
        $pt->resolve_review(!!$this->rrow);

        // mode
        if ($this->rv) {
            $pt->set_review_values($this->rv);
        } else if ($this->qreq->has_annex("after_login")) {
            $rv = new ReviewValues($this->conf);
            $rv->parse_qreq($this->qreq);
            $pt->set_review_values($rv);
        }

        // paper table
        $this->print_header(false);
        $pt->print_paper_info();

        if (!$this->user->can_view_review($this->prow, $this->rrow)
            && !($this->rrow
                 ? $this->user->can_edit_review($this->prow, $this->rrow)
                 : $this->user->can_create_review($this->prow, $this->user))) {
            $pt->paptabEndWithReviewMessage();
        } else {
            if ($pt->mode === "re" || $this->rrow) {
                $pt->print_review_form(); // might just render review
                $pt->print_main_link();
            } else {
                $pt->paptabEndWithReviewsAndComments();
            }
        }

        echo "</article>\n";
        $this->qreq->print_footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        // fix request
        if (!isset($qreq->m) && isset($qreq->mode)) {
            $qreq->m = $qreq->mode;
        }
        if ($qreq->post && $qreq->default) {
            if ($qreq->has_file("file")) {
                $qreq->upload = 1;
            } else {
                $qreq->update = 1;
            }
        }
        if ($user->is_reviewer()) {
            $qreq->open_session();
        }

        $pp = new Review_Page($user, $qreq);
        $pp->load_prow();

        // fix user
        $capuid = $user->reviewer_capability($pp->prow);

        // action
        if ($qreq->cancel) {
            $pp->handle_cancel();
        } else if ($qreq->download) {
            $pp->handle_download_form();
        } else if ($qreq->text) {
            $pp->handle_download_text();
        } else if ($qreq->valid_post()) {
            $pp->handle_valid_post();
        }

        // capability may accept to different user
        if ($capuid
            && $pp->rrow
            && $capuid === $pp->rrow->contactId
            && $capuid !== $user->contactXid) {
            $pp->add_capability_user_message($capuid);
        }

        $pp->print();
    }
}
