<?php
// listactions/la_getscores.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetScores_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        // compose scores; NB chair is always forceShow
        $errors = $texts = $any_scores = array();
        $any_decision = $any_reviewer_identity = $any_ordinal = false;
        foreach ($user->paper_set($ssel) as $row) {
            if (($whyNot = $user->perm_view_paper($row)))
                $errors[] = "#$row->paperId: " . whyNotText($whyNot);
            else if (($whyNot = $user->perm_view_review($row, null)))
                $errors[] = "#$row->paperId: " . whyNotText($whyNot);
            else {
                $row->ensure_full_reviews();
                $a = ["paper" => $row->paperId, "title" => $row->title];
                if ($row->outcome && $user->can_view_decision($row))
                    $a["decision"] = $any_decision = $user->conf->decision_name($row->outcome);
                foreach ($row->viewable_submitted_reviews_by_display($user) as $rrow) {
                    $view_bound = $user->view_score_bound($row, $rrow);
                    $this_scores = false;
                    $b = $a;
                    foreach ($rf->forder as $field => $f)
                        if ($f->view_score > $view_bound
                            && $f->has_options
                            && $f->is_round_visible($rrow)
                            && ($rrow->$field || $f->allow_empty)) {
                            $b[$f->search_keyword()] = $f->unparse_value($rrow->$field);
                            $any_scores[$f->search_keyword()] = $this_scores = true;
                        }
                    if ($this_scores) {
                        if ($rrow->reviewOrdinal > 0) {
                            $any_ordinal = true;
                            $b["review"] = $row->paperId . unparseReviewOrdinal($rrow->reviewOrdinal);
                        }
                        if ($user->can_view_review_identity($row, $rrow)) {
                            $any_reviewer_identity = true;
                            $b["reviewername"] = trim($rrow->firstName . " " . $rrow->lastName);
                            $b["email"] = $rrow->email;
                        }
                        $texts[] = $b;
                    }
                }
            }
        }
        $user->set_overrides($overrides);

        if (!empty($texts)) {
            $header = ["paper", "title"];
            if ($any_decision)
                $header[] = "decision";
            if ($any_ordinal)
                $header[] = "review";
            if ($any_reviewer_identity)
                array_push($header, "reviewername", "email");
            return $user->conf->make_csvg("scores")
                ->select(array_merge($header, array_keys($any_scores)))
                ->add($texts);
        } else {
            if (empty($errors))
                $errors[] = "No papers selected.";
            Conf::msg_error(join("<br>", $errors));
        }
    }
}
