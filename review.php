<?php
// review.php -- HotCRP paper review display/edit page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

if (session_id() === "" && $Me->is_reviewer()) {
    ensure_session();
}

class ReviewPage {
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

    /** @return ReviewForm */
    function rf() {
        return $this->conf->review_form();
    }

    function header() {
        PaperTable::do_header($this->pt, "review", $this->qreq->m, $this->qreq);
    }

    function error_exit($msg) {
        $this->header();
        Ht::stash_script("hotcrp.shortcut().add()");
        $msg && Conf::msg_error($msg);
        $this->conf->footer();
        exit;
    }

    function load_prow() {
        // determine whether request names a paper
        try {
            $pr = new PaperRequest($this->user, $this->qreq, true);
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
            $this->conf->redirect($redir->url);
        } catch (PermissionProblem $perm) {
            $this->error_exit($perm->set("listViewable", true)->unparse_html());
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
        $this->conf->redirect($this->prow->hoturl());
    }

    function handle_update() {
        // do not unsubmit submitted review
        if ($this->rrow && $this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $this->qreq->ready = 1;
        }

        $rv = new ReviewValues($this->rf());
        $rv->paperId = $this->prow->paperId;
        if (($whynot = $this->user->perm_submit_review($this->prow, $this->rrow))) {
            $rv->msg_at(null, $whynot->unparse_html(), MessageSet::ERROR);
        } else if ($rv->parse_web($this->qreq, $this->qreq->override)) {
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
        if (!$this->qreq->has_file("uploadedFile")) {
            Conf::msg_error("Select a review form to upload.");
            return;
        }
        $rv = ReviewValues::make_text($this->rf(),
                $this->qreq->file_contents("uploadedFile"),
                $this->qreq->file_filename("uploadedFile"));
        if ($rv->parse_text($this->qreq->override)
            && $rv->check_and_save($this->user, $this->prow, $this->rrow)) {
            $this->qreq->r = $this->qreq->reviewId = $rv->review_ordinal_id;
        }
        if (!$rv->has_error() && $rv->parse_text($this->qreq->override)) {
            $rv->msg_at(null, "Only the first review form in the file was parsed. " . Ht::link("Upload multiple-review files here.", $this->conf->hoturl("offline")), MessageSet::WARNING);
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
                         . $rf->text_form($this->prow, $this->rrow, $this->user, null))
            ->emit();
        exit;
    }

    function handle_download_text() {
        $rf = $this->rf();
        if ($this->rrow) {
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
        exit;
    }

    function handle_adopt() {
        if (!$this->rrow || !$this->rrow_explicit) {
            Conf::msg_error("Missing review to delete.");
            return;
        } else if (!$this->user->can_approve_review($this->prow, $this->rrow)) {
            return;
        }

        $rv = new ReviewValues($this->rf());
        $rv->paperId = $this->prow->paperId;
        $my_rrow = $this->prow->review_by_user($this->user);
        $my_rid = ($my_rrow ?? $this->rrow)->unparse_ordinal_id();
        if (($whynot = $this->user->perm_submit_review($this->prow, $my_rrow))) {
            $rv->msg_at(null, $whynot->unparse_html(), MessageSet::ERROR);
        } else if ($rv->parse_web($this->qreq, $this->qreq->override)) {
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
            Conf::msg_error("Missing review to delete.");
            return;
        } else if (!$this->user->can_administer($this->prow)) {
            return;
        }
        $result = $this->conf->qe("delete from PaperReview where paperId=? and reviewId=?", $this->prow->paperId, $this->rrow->reviewId);
        if ($result->affected_rows) {
            $this->user->log_activity_for($this->rrow->contactId, "Review {$this->rrow->reviewId} deleted", $this->prow);
            $this->conf->confirmMsg("Deleted review.");
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
            $result = $this->user->unsubmit_review_row($this->rrow);
            if ($result->affected_rows) {
                $this->user->log_activity_for($this->rrow->contactId, "Review {$this->rrow->reviewId} unsubmitted", $this->prow);
                $this->conf->confirmMsg("Unsubmitted review.");
            }
            $this->conf->redirect_self($this->qreq);
        }
    }

    /** @return ?int */
    function current_capability_rrid() {
        if (($capuid = $this->user->capability("@ra{$this->prow->paperId}"))) {
            $u = $this->conf->cached_user_by_id($capuid);
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

    function handle_accept_decline_redirect($capuid) {
        if (!$this->qreq->is_get()
            || !($rrid = $this->current_capability_rrid())) {
            return;
        }
        $isaccept = $this->qreq->accept;
        echo "<!DOCTYPE html><html lang=\"en\"><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<body>\n",
            Ht::form($this->conf->hoturl_post("api/" . ($isaccept ? "acceptreview" : "declinereview"), ["p" => $this->prow->paperId, "r" => $rrid, "verbose" => 1, "redirect" => 1]), ["id" => "redirectform"]),
            Ht::submit("Press to continue"),
            "</form>",
            Ht::script("document.getElementById('redirectform').submit()"),
            "</body></html>";
        exit;
    }

    function render_decline_message($capuid) {
        $ref = $this->prow->review_refusals_by_user_id($capuid);
        if ($ref && $ref[0] && $ref[0]->refusedReviewId) {
            $rrid = $ref[0]->refusedReviewId;
            $this->conf->msg(
                "<p>You declined to complete this review. Thank you for informing us.</p>"
                . Ht::form($this->conf->hoturl_post("api/declinereview", ["p" => $this->prow->paperId, "r" => $rrid, "redirect" => 1]))
                . '<div class="f-i mt-3"><label for="declinereason">Optional explanation</label>'
                . ($ref[0]->reason ? "" : '<div class="field-d">If you’d like, you may enter a brief explanation here.</div>')
                . Ht::textarea("reason", $ref[0]->reason, ["rows" => 3, "cols" => 40, "spellcheck" => true, "class" => "w-text", "id" => "declinereason"])
                . '</div><div class="aab mt-3">'
                . '<div class="aabut">' . Ht::submit("Update explanation", ["class" => "btn-primary"])
                . '</div><div class="aabut">' . Ht::submit("Accept review", ["formaction" => $this->conf->hoturl_post("api/acceptreview", ["p" => $this->prow->paperId, "r" => $rrid, "verbose" => 1, "redirect" => 1])])
                . '</div></div></form>', 1);
        } else {
            $this->conf->msg("<p>You have declined to complete this review. Thank you for informing us.</p>", 1);
        }
    }

    function render_accept_other_message($capuid) {
        if (($u = $this->conf->cached_user_by_id($capuid))) {
            if (PaperRequest::simple_qreq($this->qreq)
                && ($i = $this->user->session_user_index($u->email)) >= 0) {
                $selfurl = $this->conf->selfurl($this->qreq, null, Conf::HOTURL_SITE_RELATIVE);
                $this->conf->transfer_messages_to_session();
                Navigation::redirect_base("u/$i/$selfurl");
            } else if ($this->user->has_email()) {
                $mx = 'This review is assigned to ' . htmlspecialchars($u->email) . ', while you are signed in as ' . htmlspecialchars($this->user->email) . '. You can edit the review anyway since you accessed it using a special link.';
                if ($this->rrow->reviewStatus <= ReviewInfo::RS_DRAFTED) {
                    $m = Ht::form($this->conf->hoturl_post("api/claimreview", ["p" => $this->prow->paperId, "r" => $this->rrow->reviewId, "redirect" => 1]), ["class" => "has-fold foldc"])
                        . "<p class=\"mb-0\">$mx Alternately, you can <a href=\"\" class=\"ui js-foldup\">reassign it to this account</a>.</p>"
                        . '<div class="aab mt-3 fx">';
                    foreach ($this->user->session_users() as $e) {
                        $m .= '<div class="aabut">' . Ht::submit("Reassign to " . htmlspecialchars($e), ["name" => "email", "value" => $e]) . '</div>';
                    }
                    $m .= '</div></div></form>';
                } else {
                    $m = "<p>{$mx}</p>";
                }
                $this->conf->msg($m, 1);
            } else {
                $this->conf->msg(
                    '<p>This review is assigned to ' . htmlspecialchars($u->email) . '. You can edit the review since you accessed it using a special link.</p>', 1);
            }
        }
    }

    function render() {
        $this->pt = $pt = new PaperTable($this->user, $this->qreq, $this->prow);
        $pt->resolve_review($this->rrow);

        // mode
        $pt->fix_mode();
        if ($this->rv) {
            $pt->set_review_values($this->rv);
        } else if ($this->qreq->has_annex("after_login")) {
            $rv = new ReviewValues($this->rf());
            $rv->parse_web($this->qreq, $this->qreq->override);
            $pt->set_review_values($rv);
        }

        // paper table
        $this->header();

        $pt->initialize(false, false);
        $pt->paptabBegin();
        $pt->resolve_comments();

        if (!$this->rrow
            && !$this->user->can_view_review($this->prow, null)
            && !$this->user->can_edit_review($this->prow, null)) {
            $pt->paptabEndWithReviewMessage();
        } else {
            if ($pt->mode === "re") {
                $pt->paptabEndWithEditableReview();
                $pt->paptabComments();
            } else {
                $pt->paptabEndWithReviewsAndComments();
            }
        }

        echo "</article>\n";
        $this->conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        // fix request
        if (!isset($qreq->m) && isset($qreq->mode)) {
            $qreq->m = $qreq->mode;
        }
        if ($qreq->post && $qreq->default) {
            if ($qreq->has_file("uploadedFile")) {
                $qreq->uploadForm = 1;
            } else {
                $qreq->update = 1;
            }
        } else if ($qreq->submitreview) {
            $qreq->update = $qreq->ready = 1;
        } else if ($qreq->savedraft) {
            $qreq->update = 1;
            unset($qreq->ready);
        }

        $pp = new ReviewPage($user, $qreq);
        $pp->load_prow();

        // fix user
        $user->add_overrides(Contact::OVERRIDE_CHECK_TIME);
        $capuid = $user->capability("@ra{$pp->prow->paperId}");

        // action
        if ($qreq->cancel) {
            $pp->handle_cancel();
        } else if ($qreq->update && $qreq->valid_post()) {
            $pp->handle_update();
        } else if ($qreq->adoptreview && $qreq->valid_post()) {
            $pp->handle_adopt();
        } else if ($qreq->uploadForm && $qreq->valid_post()) {
            $pp->handle_upload_form();
        } else if ($qreq->downloadForm) {
            $pp->handle_download_form();
        } else if ($qreq->text) {
            $pp->handle_download_text();
        } else if ($qreq->unsubmitreview && $qreq->valid_post()) {
            $pp->handle_unsubmit();
        } else if ($qreq->deletereview && $qreq->valid_post()) {
            $pp->handle_delete();
        } else if (($qreq->accept || $qreq->decline) && $capuid) {
            $pp->handle_accept_decline_redirect($capuid);
        }

        // capability messages: decline, accept to different user
        if ($capuid) {
            if (!$pp->rrow
                && $pp->prow->review_refusals_by_user_id($capuid)) {
                $pp->render_decline_message($capuid);
            } else if ($pp->rrow
                       && $capuid === $pp->rrow->contactId
                       && $capuid !== $user->contactXid) {
                $pp->render_accept_other_message($capuid);
            }
        }

        $pp->render();
    }
}

ReviewPage::go($Me, $Qreq);
