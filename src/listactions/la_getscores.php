<?php
// listactions/la_getscores.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetScores_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        // compose scores; NB chair is always forceShow
        $texts = $any_scores = [];
        $ms = new MessageSet;
        $any_decision = $any_reviewer_identity = $any_ordinal = false;
        foreach ($ssel->paper_set($user) as $row) {
            if (($whyNot = $user->perm_view_paper($row))) {
                $mi = $ms->error_at(null, "<5>" . $whyNot->unparse_html());
                $mi->landmark = "#{$row->paperId}";
            } else if (($whyNot = $user->perm_view_review($row, null))) {
                $mi = $ms->error_at(null, "<5>" . $whyNot->unparse_html());
                $mi->landmark = "#{$row->paperId}";
            } else {
                $row->ensure_full_reviews();
                $a = ["paper" => $row->paperId, "title" => $row->title];
                if ($row->outcome && $user->can_view_decision($row)) {
                    $a["decision"] = $any_decision = $user->conf->decision_name($row->outcome);
                }
                foreach ($row->viewable_reviews_as_display($user) as $rrow) {
                    if ($rrow->reviewSubmitted) {
                        $this_scores = false;
                        $b = $a;
                        foreach ($rrow->viewable_fields($user) as $f) {
                            if ($f->has_options) {
                                $v = $rrow->fields[$f->order];
                                if ($v || !$f->required) {
                                    $b[$f->search_keyword()] = $f->unparse_value($v);
                                    $any_scores[$f->search_keyword()] = $this_scores = true;
                                }
                            }
                        }
                        if ($this_scores) {
                            if ($rrow->reviewOrdinal > 0) {
                                $any_ordinal = true;
                                $b["review"] = $rrow->unparse_ordinal_id();
                            }
                            if ($user->can_view_review_identity($row, $rrow)) {
                                $any_reviewer_identity = true;
                                $b["reviewername"] = trim("{$rrow->firstName} {$rrow->lastName}");
                                $b["email"] = $rrow->email;
                            }
                            $texts[] = $b;
                        }
                    }
                }
            }
        }
        $user->set_overrides($overrides);

        if (!empty($texts)) {
            $header = ["paper", "title"];
            if ($any_decision) {
                $header[] = "decision";
            }
            if ($any_ordinal) {
                $header[] = "review";
            }
            if ($any_reviewer_identity) {
                array_push($header, "reviewername", "email");
            }
            return $user->conf->make_csvg("scores")
                ->select(array_merge($header, array_keys($any_scores)))
                ->append($texts);
        } else {
            if (!$ms->has_message()) {
                $ms->msg_at(null, "<0>Nothing to download", MessageSet::MARKED_NOTE);
            }
            $user->conf->feedback_msg($ms);
        }
    }
}
