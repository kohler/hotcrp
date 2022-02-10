<?php
// listactions/la_getreviews.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetReviews_ListAction extends GetReviewBase_ListAction {
    private $include_paper;
    function __construct($conf, $fj) {
        parent::__construct(false, $fj->zip);
        $this->include_paper = $fj->abstract;
        $this->author_view = $fj->author_view;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $au_seerev = $user->conf->au_seerev;
        if ($this->author_view && $user->privChair) {
            $user->conf->au_seerev = Conf::AUSEEREV_YES;
            Contact::update_rights();
        }
        $texts = $pids = [];
        $ms = (new MessageSet)->set_ignore_duplicates(true)->set_want_ftext(true, 0);
        foreach ($ssel->paper_set($user) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                $mi = $ms->error_at(null, "<0>" . $whyNot->unparse_text());
                $mi->landmark = "#{$prow->paperId}";
                continue;
            }
            $rctext = "";
            if ($this->include_paper) {
                $rctext = GetAbstracts_ListAction::render($prow, $user);
            }
            $last_rc = null;
            $time = null;
            $viewer = $this->author_view && $user->allow_administer($prow) ? $prow->author_view_user() : $user;
            foreach ($prow->viewable_submitted_reviews_and_comments($user) as $rc) {
                if ($viewer === $user
                    || (isset($rc->reviewId)
                        ? $viewer->can_view_review($prow, $rc)
                        : $viewer->can_view_comment($prow, $rc))) {
                    $rctext .= PaperInfo::review_or_comment_text_separator($last_rc, $rc);
                    if (isset($rc->reviewId)) {
                        $rctext .= $rf->unparse_text($prow, $rc, $viewer, ReviewForm::UNPARSE_NO_TITLE);
                    } else {
                        $rctext .= $rc->unparse_text($viewer, ReviewForm::UNPARSE_NO_TITLE);
                    }
                    $last_rc = $rc;
                    $time = max($time ?? 0, $rc->mtime($viewer));
                }
            }
            if ($rctext !== "") {
                if (!$this->include_paper) {
                    $header = "{$user->conf->short_name} Paper #{$prow->paperId} Reviews and Comments\n";
                    $rctext = $header . str_repeat("=", 75) . "\n"
                        . "* Paper #{$prow->paperId} {$prow->title}\n\n" . $rctext;
                }
                $texts[] = [$prow->paperId, $rctext, $time];
                $pids[] = $prow->paperId;
            } else if (($whyNot = $user->perm_view_review($prow, null))) {
                $mi = $ms->error_at(null, "<0>" . $whyNot->unparse_text());
                $mi->landmark = "#{$prow->paperId}";
            } else {
                $ms->msg_at(null, "<0>{$prow->paperId} has no visible reviews", MessageSet::WARNING_NOTE);
            }
        }
        if (!$this->iszip) {
            foreach ($texts as $i => &$pt) {
                if ($i !== 0)
                    $pt[1] = "\n\n\n" . str_repeat("* ", 37) . "*\n\n\n\n" . $pt[1];
            }
            unset($pt);
        }
        $user->set_overrides($overrides);
        if ($this->author_view && $user->privChair) {
            $user->conf->au_seerev = $au_seerev;
            Contact::update_rights();
        }
        if (!empty($pids)) {
            $user->log_activity("Download reviews", $pids);
        }
        return $this->finish($user, $texts, $ms);
    }
}
