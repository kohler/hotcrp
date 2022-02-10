<?php
// listactions/la_getreviewforms.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
                ->add_string($rf->text_form_header(false) . $rf->text_form(null, null, $user, null) . "\n");
        }

        $texts = [];
        $ms = (new MessageSet)->set_ignore_duplicates(true)->set_want_ftext(true, 0);
        foreach ($ssel->paper_set($user) as $prow) {
            $whyNot = $user->perm_edit_review($prow, null);
            if ($whyNot
                && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"])) {
                $ms->error_at(null, "<0>" . $whyNot->unparse_text());
            } else {
                $t = "";
                if ($whyNot) {
                    $m = $whyNot->unparse_text();
                    $ms->warning_at(null, "<0>" . $m);
                    if (!isset($whyNot["deadline"])) {
                        $t .= prefix_word_wrap("==-== ", strtoupper($m) . "\n\n", "==-== ");
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
                    $t .= $rf->text_form($prow, $rrow, $user, null) . "\n";
                    if ($rrow) {
                        $time = max($time ?? 0, $rrow->mtime($user));
                    }
                }
                $texts[] = [$prow->paperId, $t, $time];
            }
        }

        return $this->finish($user, $texts, $ms);
    }
}
