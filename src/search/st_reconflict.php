<?php
// search/st_reconflict.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

abstract class Reconflict_SearchTerm extends SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $st = new PaperID_SearchTerm;
        $xword = $word;
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $xword, $m)) {
            if (isset($m[2]) && $m[2]) {
                $st->add_range((int) $m[1], (int) $m[2]);
            } else {
                $st->add_range((int) $m[1], (int) $m[1]);
            }
            $xword = $m[3];
        }
        if ($xword !== "" || $st->is_empty()) {
            $srch->lwarning($sword, "<0>List of paper numbers expected");
            return new False_SearchTerm;
        }

        $old_overrides = $srch->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $cids = [];
        foreach ($srch->user->paper_set(["paperId" => $st, "reviewSignatures" => true, "finalized" => $srch->limit_submitted()]) as $prow) {
            if ($srch->user->can_view_paper($prow)) {
                foreach ($prow->all_reviews() as $rrow) {
                    if ($rrow->reviewToken === 0
                        && $srch->user->can_view_review_identity($prow, $rrow)) {
                        $cids[$rrow->contactId] = true;
                    }
                }
            }
        }
        $srch->user->set_overrides($old_overrides);

        if (!empty($cids)) {
            return new Conflict_SearchTerm($srch->user, ">0", array_keys($cids), false);
        } else {
            $srch->lwarning($sword, "<0>No visible reviewers");
            return new False_SearchTerm;
        }
    }
}
