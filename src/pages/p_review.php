<?php
// pages/p_review.php -- HotCRP paper review display/edit page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @return ReviewForm */
    function rf() {
        return $this->conf->review_form();
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
            $this->prow = $this->conf->paper = $pr->prow;
            if ($pr->rrow) {
                $this->rrow = $pr->rrow;
                $this->rrow_explicit = true;
            } else {
                $this->rrow = $this->my_rrow($this->qreq->m === "rea");
                $this->rrow_explicit = false;
            }
        } catch (Redirection $redir) {
            assert(PaperRequest::simple_qreq($this->qreq));
            throw $redir;
        } catch (PermissionProblem $perm) {
            $perm->set("listViewable", $this->user->is_author() || $this->user->is_reviewer());
            if (!$perm->secondary || $this->conf->saved_messages_status() < 2) {
                $this->conf->error_msg("<5>" . $perm->unparse_html());
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
        // do not unsubmit submitted review
        if ($this->rrow && $this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $this->qreq->ready = 1;
        }

        $rv = new ReviewValues($this->rf());
        $rv->paperId = $this->prow->paperId;
        if (($whynot = ($this->rrow
                        ? $this->user->perm_edit_review($this->prow, $this->rrow, true)
                        : $this->user->perm_create_review($this->prow)))) {
            $whynot->append_to($rv, null, MessageSet::ERROR);
        } else if ($rv->parse_qreq($this->qreq, !!$this->qreq->override)) {
            if (isset($this->qreq->approvesubreview)
                && $this->rrow
                && $this->user->can_approve_review($this->prow, $this->rrow)) {
                $rv->set_adopt();
            }
            if ($rv->check_and_save($this->user, $this->prow, $this->rrow)) {
                $this->qreq->r = $this->qreq->reviewId = $rv->review_ordinal_id;
            }
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
        $rv = ReviewValues::make_text($this->rf(),
                $this->qreq->file_contents("file"),
                $this->qreq->file_filename("file"));
        if ($rv->parse_text($this->qreq->override)
            && $rv->check_and_save($this->user, $this->prow, $this->rrow)) {
            $this->qreq->r = $this->qreq->reviewId = $rv->review_ordinal_id;
        }
        if (!$rv->has_error() && $rv->parse_text($this->qreq->override)) {
            $rv->msg_at(null, "<5>Only the first review form in the file was parsed. " . Ht::link("Upload multiple-review files here.", $this->conf->hoturl("offline")), MessageSet::WARNING);
        }
        $rv->report();
        if (!$rv->has_error()) {
            $this->conf->redirect_self($this->qreq);
        }
        $this->reload_prow();
    }

    function handle_download_form() {
        $filename = "review-" . ($this->rrow ? $this->rrow->unparse_ordinal_id() : $this->prow->paperId);
        $rf = $this->rf();
        $this->conf->make_csvg($filename, CsvGenerator::TYPE_STRING)
            ->set_inline(false)
            ->add_string($rf->text_form_header(false)
                         . $rf->text_form($this->prow, $this->rrow, $this->user))
            ->emit();
        throw new PageCompletion;
    }

    function handle_download_text() {
        $rf = $this->rf();
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

        $rv = new ReviewValues($this->rf());
        $rv->paperId = $this->prow->paperId;
        $my_rrow = $this->prow->review_by_user($this->user);
        $my_rid = ($my_rrow ?? $this->rrow)->unparse_ordinal_id();
        if (($whynot = ($my_rrow
                        ? $this->user->perm_edit_review($this->prow, $my_rrow, true)
                        : $this->user->perm_create_review($this->prow)))) {
            $rv->msg_at(null, "<5>" . $whynot->unparse_html(), MessageSet::ERROR);
        } else if ($rv->parse_qreq($this->qreq, !!$this->qreq->override)) {
            $rv->set_ready($this->qreq->adoptsubmit);
            if ($rv->check_and_save($this->user, $this->prow, $my_rrow)) {
                $my_rid = $rv->review_ordinal_id;
                if (!$rv->has_problem_at("ready")) {
                    // mark the source review as approved
                    $rvx = new ReviewValues($this->rf());
                    $rvx->set_adopt();
                    $rvx->check_and_save($this->user, $this->prow, $this->rrow);
                }
            }
        }
        $rv->report();
        $this->conf->redirect_self($this->qreq, ["r" => $my_rid]);
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
            if ($this->rrow->reviewType == REVIEW_META) {
                $this->conf->update_metareviews_setting(-1);
            }

            // perhaps a delegatee needs to redelegate
            if ($this->rrow->reviewType < REVIEW_SECONDARY
                && $this->rrow->requestedBy > 0) {
                $this->user->update_review_delegation($this->prow->paperId, $this->rrow->requestedBy, -1);
            }
        }
        $this->conf->redirect_self($this->qreq, ["r" => null, "reviewId" => null]);
    }

    function handle_unsubmit() {
        if ($this->rrow
            && $this->rrow->reviewStatus >= ReviewInfo::RS_DELIVERED
            && $this->user->can_administer($this->prow)) {
            if ($this->user->unsubmit_review_row($this->rrow)) {
                $this->conf->success_msg("<0>Review unsubmitted");
            }
            $this->conf->redirect_self($this->qreq);
        }
    }

    /** @return ?int */
    function current_capability_rrid() {
        if (($capuid = $this->user->capability("@ra{$this->prow->paperId}"))) {
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
            $rv = new ReviewValues($this->rf());
            $rv->parse_qreq($this->qreq, !!$this->qreq->override);
            $pt->set_review_values($rv);
        }

        // paper table
        $this->print_header(false);
        $pt->print_paper_info();

        if (!$this->user->can_view_review($this->prow, $this->rrow)
            && !($this->rrow
                 ? $this->user->can_edit_review($this->prow, $this->rrow)
                 : $this->user->can_create_review($this->prow))) {
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
        } else if ($qreq->submitreview) {
            $qreq->update = $qreq->ready = 1;
        } else if ($qreq->savedraft) {
            $qreq->update = 1;
            unset($qreq->ready);
        }
        if ($user->is_reviewer()) {
            $qreq->open_session();
        }

        $pp = new Review_Page($user, $qreq);
        $pp->load_prow();

        // fix user
        $capuid = $user->capability("@ra{$pp->prow->paperId}");

        // action
        if ($qreq->cancel) {
            $pp->handle_cancel();
        } else if ($qreq->update && $qreq->valid_post()) {
            $pp->handle_update();
        } else if ($qreq->adoptreview && $qreq->valid_post()) {
            $pp->handle_adopt();
        } else if ($qreq->upload && $qreq->valid_post()) {
            $pp->handle_upload_form();
        } else if ($qreq->download || $qreq->downloadForm /* XXX */) {
            $pp->handle_download_form();
        } else if ($qreq->text) {
            $pp->handle_download_text();
        } else if ($qreq->unsubmitreview && $qreq->valid_post()) {
            $pp->handle_unsubmit();
        } else if ($qreq->deletereview && $qreq->valid_post()) {
            $pp->handle_delete();
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
