<?php
// listactions/la_getreviewforms.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetReviewForms_ListAction extends GetReviewBase_ListAction {
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
        foreach ($ssel->paper_set($user) as $prow) {
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
                $time = null;
                if (empty($rrows)) {
                    $rrows[] = null;
                }
                foreach ($rrows as $rrow) {
                    $t .= $rf->textForm($prow, $rrow, $user, null) . "\n";
                    if ($rrow) {
                        $time = max($time ?? 0, $rrow->mtime($user));
                    }
                }
                $texts[] = [$prow->paperId, $t, $time];
            }
        }

        $this->finish($user, $texts, $errors);
    }
}
