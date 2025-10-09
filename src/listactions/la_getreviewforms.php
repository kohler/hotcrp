<?php
// listactions/la_getreviewforms.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class GetReviewForms_ListAction extends GetReviewBase_ListAction {
    private $all;
    function __construct($conf, $fj) {
        parent::__construct(true, $fj->zip);
        $this->all = $fj->all;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $this->all ? $user->is_manager() : $user->is_reviewer();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $rf = $user->conf->review_form();
        if ($ssel->is_empty()) {
            // blank form
            return $user->conf->make_text_downloader("review")
                ->set_content($rf->text_form_header(false) . $rf->text_form(null, null, $user) . "\n");
        }

        $texts = [];
        $ms = (new MessageSet)->set_ignore_duplicates(true);
        foreach ($ssel->paper_set($user) as $prow) {
            $whyNot = $user->perm_edit_some_review($prow);
            if ($whyNot
                && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"])) {
                $whyNot->append_to($ms, null, 2);
                continue;
            }
            $t = "";
            if ($whyNot) {
                $whyNot->append_to($ms, null, 1);
                if (!isset($whyNot["deadline"])) {
                    $t .= prefix_word_wrap("==-== ", strtoupper($whyNot->unparse_text()) . "\n\n", "==-== ");
                }
            }
            if (!$this->all || !$user->allow_administer($prow)) {
                $rrows = $prow->full_reviews_by_user($user);
            } else {
                $prow->ensure_full_reviews();
                $rrows = $prow->reviews_as_display();
            }
            $time = null;
            if (empty($rrows)) {
                $rrows[] = null;
            }
            foreach ($rrows as $rrow) {
                $t .= $rf->text_form($prow, $rrow, $user) . "\n";
                if ($rrow) {
                    $time = max($time ?? 0, $rrow->mtime($user));
                }
            }
            $texts[] = [$prow->paperId, $t, $time];
        }

        return $this->finish($user, $texts, $ms);
    }
}
