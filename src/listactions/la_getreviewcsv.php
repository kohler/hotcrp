<?php
// listactions/la_getreviewcsv.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetReviewCSV_ListAction extends ListAction {
    private $include_paper;
    private $author_view;
    function __construct($conf, $fj) {
        $this->author_view = !!($fj->author_view ?? false);
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $rf = $user->conf->review_form();
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        if ($this->author_view && $user->privChair) {
            $au_seerev = $user->conf->au_seerev;
            $user->conf->au_seerev = Conf::AUSEEREV_YES;
        }
        $errors = $items = $fields = $pids = [];
        $has_id = $has_ordinal = false;
        foreach ($ssel->paper_set($user) as $prow) {
            if (($whyNot = $user->perm_view_paper($prow))) {
                $errors["#$prow->paperId: " . $whyNot->unparse_text()] = true;
                continue;
            }
            $viewer = $this->author_view ? $prow->author_view_user() : $user;
            $prow->ensure_full_reviews();
            foreach ($prow->viewable_reviews_as_display($user) as $rrow) {
                if (($viewer === $user || $viewer->can_view_review($prow, $rrow))
                    && $rrow->reviewSubmitted) {
                    $text = [
                        "paper" => $prow->paperId, "title" => $prow->title
                    ];
                    if ($rrow->reviewOrdinal > 0) {
                        $has_ordinal = true;
                        $text["review"] = $rrow->unparse_ordinal_id();
                    }
                    if ($viewer->can_view_review_identity($prow, $rrow)) {
                        $has_id = true;
                        $text["email"] = $rrow->email;
                        $text["reviewername"] = Text::nameo($rrow, 0);
                    }
                    foreach ($rrow->viewable_fields($viewer) as $f) {
                        $fields[$f->short_id] = true;
                        $text[$f->name] = $f->unparse_value($rrow->fields[$f->order], ReviewField::VALUE_TRIM);
                    }
                    $items[] = $text;
                    $pids[$prow->paperId] = true;
                }
            }
        }
        $selection = ["paper", "title"];
        if ($has_ordinal) {
            $selection[] = "review";
        }
        if ($has_id) {
            array_push($selection, "reviewername", "email");
        }
        foreach ($rf->all_fields() as $f) {
            if (isset($fields[$f->short_id]))
                $selection[] = $f->name;
        }
        if (!empty($pids)) {
            $user->log_activity("Download reviews CSV", array_keys($pids));
        }
        $user->set_overrides($overrides);
        return $user->conf->make_csvg($this->author_view ? "aureviews" : "reviews")
            ->select($selection)->append($items);
    }
}
