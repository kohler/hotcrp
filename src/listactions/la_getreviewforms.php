<?php
// listactions/la_getreviewforms.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
            return $user->conf->make_csvg("review", CsvGenerator::TYPE_STRING)
                ->set_inline(false)
                ->add_string($rf->textFormHeader("blank") . $rf->textForm(null, null, $user, null) . "\n");
        }

        $texts = $errors = [];
        foreach ($ssel->paper_set($user) as $prow) {
            $whyNot = $user->perm_review($prow, null);
            if ($whyNot
                && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"])) {
                $errors[$whyNot->unparse(0)] = true;
            } else {
                $t = "";
                if ($whyNot) {
                    $m = $whyNot->unparse(0);
                    $errors[$m] = false;
                    if (!isset($whyNot["deadline"])) {
                        $t .= prefix_word_wrap("==-== ", strtoupper($m) . "\n\n", "==-== ");
                    }
                }
                if (!$this->all || !$user->allow_administer($prow)) {
                    $rrows = $prow->full_reviews_of_user($user);
                } else {
                    $prow->ensure_full_reviews();
                    $rrows = $prow->reviews_by_display($user);
                }
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

        return $this->finish($user, $texts, $errors);
    }
}
