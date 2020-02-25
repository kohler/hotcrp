<?php
// listactions/la_get_rev.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetPcassignments_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        list($header, $items) = ListAction::pcassignments_csv_data($user, $ssel->selection());
        return $user->conf->make_csvg("pcassignments")->select($header)->add($items);
    }
}

class GetReviewBase_ListAction extends ListAction {
    protected $isform;
    protected $iszip;
    protected $author_view;
    function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    protected function finish(Contact $user, $texts, $errors) {
        uksort($errors, "strnatcmp");

        if (empty($texts)) {
            if (empty($errors)) {
                Conf::msg_error("No papers selected.");
            } else {
                $errors = array_map("htmlspecialchars", array_keys($errors));
                Conf::msg_error(join("<br>", $errors) . "<br>Nothing to download.");
            }
            return;
        }

        $warnings = array();
        $nerrors = 0;
        foreach ($errors as $ee => $iserror) {
            $warnings[] = $ee;
            if ($iserror) {
                $nerrors++;
            }
        }
        if ($nerrors) {
            array_unshift($warnings, "Some " . ($this->isform ? "review forms" : "reviews") . " are missing:");
        }

        $rfname = $this->author_view ? "aureview" : "review";
        if (!$this->iszip) {
            $rfname .= count($texts) === 1 ? $texts[0][0] : "s";
        }

        if ($this->isform) {
            $header = $user->conf->review_form()->textFormHeader(count($texts) > 1 && !$this->iszip);
        } else {
            $header = "";
        }

        if (!$this->iszip) {
            $text = $header;
            if (!empty($warnings) && $this->isform) {
                foreach ($warnings as $w)
                    $text .= prefix_word_wrap("==-== ", $w, "==-== ");
                $text .= "\n";
            } else if (!empty($warnings)) {
                $text .= join("\n", $warnings) . "\n\n";
            }
            foreach ($texts as $pt) {
                $text .= $pt[1];
            }
            downloadText($text, $rfname);
        } else {
            $zip = new ZipDocument($user->conf->download_prefix . "reviews.zip");
            $zip->warnings = $warnings;
            foreach ($texts as $pt) {
                $zip->add_as($header . $pt[1], $user->conf->download_prefix . $rfname . $pt[0] . ".txt");
            }
            $result = $zip->download();
            if (!$result->error) {
                exit;
            }
        }
    }
}

class GetReviewForm_ListAction extends GetReviewBase_ListAction {
    function __construct($conf, $fj) {
        parent::__construct(true, $fj->name === "get/revformz");
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_reviewer();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        if ($ssel->is_empty()) {
            // blank form
            $text = $rf->textFormHeader("blank") . $rf->textForm(null, null, $user, null) . "\n";
            downloadText($text, "review");
            return;
        }

        $texts = $errors = [];
        foreach ($user->paper_set($ssel) as $prow) {
            $whyNot = $user->perm_review($prow, null);
            if ($whyNot
                && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"])) {
                $errors[whyNotText($whyNot, true)] = true;
            } else {
                $t = "";
                if ($whyNot) {
                    $t = whyNotText($whyNot, true);
                    $errors[$t] = false;
                    if (!isset($whyNot["deadline"]))
                        $t .= prefix_word_wrap("==-== ", strtoupper($t) . "\n\n", "==-== ");
                }
                $rrows = $prow->full_reviews_of_user($user);
                if (empty($rrows)) {
                    $rrows[] = null;
                }
                foreach ($rrows as $rrow) {
                    $t .= $rf->textForm($prow, $rrow, $user, null) . "\n";
                }
                $texts[] = [$prow->paperId, $t];
            }
        }

        $this->finish($user, $texts, $errors);
    }
}

class GetReviews_ListAction extends GetReviewBase_ListAction {
    private $include_paper;
    function __construct($conf, $fj) {
        parent::__construct(false, !!get($fj, "zip"));
        $this->include_paper = !!get($fj, "abstract");
        $this->author_view = !!get($fj, "author_view");
        require_once("la_get_sub.php");
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        if ($this->author_view && $user->privChair) {
            $au_seerev = $user->conf->au_seerev;
            $user->conf->au_seerev = Conf::AUSEEREV_YES;
        }
        $errors = $texts = $pids = [];
        foreach ($user->paper_set($ssel) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                $errors["#$prow->paperId: " . whyNotText($whyNot, true)] = true;
                continue;
            }
            $rctext = "";
            if ($this->include_paper) {
                $rctext = GetAbstract_ListAction::render($prow, $user);
            }
            $last_rc = null;
            $viewer = $this->author_view ? $prow->author_view_user() : $user;
            foreach ($prow->viewable_submitted_reviews_and_comments($user) as $rc) {
                if ($viewer === $user
                    || (isset($rc->reviewId)
                        ? $viewer->can_view_review($prow, $rc)
                        : $viewer->can_view_comment($prow, $rc))) {
                    $rctext .= PaperInfo::review_or_comment_text_separator($last_rc, $rc);
                    if (isset($rc->reviewId)) {
                        $rctext .= $rf->pretty_text($prow, $rc, $viewer, false, true);
                    } else {
                        $rctext .= $rc->unparse_text($viewer, true);
                    }
                    $last_rc = $rc;
                }
            }
            if ($rctext !== "") {
                if (!$this->include_paper) {
                    $header = "{$user->conf->short_name} Paper #{$prow->paperId} Reviews and Comments\n";
                    $rctext = $header . str_repeat("=", 75) . "\n"
                        . "* Paper #{$prow->paperId} {$prow->title}\n\n" . $rctext;
                }
                $texts[] = [$prow->paperId, $rctext];
                $pids[$prow->paperId] = true;
            } else if (($whyNot = $user->perm_view_review($prow, null))) {
                $errors["#$prow->paperId: " . whyNotText($whyNot, true)] = true;
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
            $user->log_activity("Download reviews", array_keys($pids));
        }
        $this->finish($user, $texts, $errors);
    }
}
