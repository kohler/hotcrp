<?php
// pc_reviewdelegation.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewDelegation_PaperColumn extends PaperColumn {
    private $requester;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->isPC)
            return false;
        $pl->qopts["reviewSignatures"] = true;
        $this->requester = $pl->reviewer_user();
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "Requested reviews";
    }
    function content(PaperList $pl, PaperInfo $row) {
        global $Now;
        $rx = [];
        $row->ensure_reviewer_names();
        $old_overrides = $pl->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($row->reviews_by_display() as $rrow) {
            if ($rrow->reviewType == REVIEW_EXTERNAL
                && $rrow->requestedBy == $this->requester->contactId) {
                if (!$pl->user->can_view_review_assignment($row, $rrow))
                    continue;
                if ($pl->user->can_view_review_identity($row, $rrow))
                    $t = $pl->user->reviewer_html_for($rrow);
                else
                    $t = "review";
                $ranal = $pl->make_review_analysis($rrow, $row);
                $description = $ranal->description_text();
                if ($rrow->reviewOrdinal)
                    $description = rtrim("#" . unparseReviewOrdinal($rrow) . " " . $description);
                $description = $ranal->wrap_link($description, "uu nw");
                if (!$rrow->reviewSubmitted && $rrow->reviewNeedsSubmit >= 0)
                    $description = '<strong class="overdue">' . $description . '</strong>';
                $t .= ", $description";
                if (!$rrow->reviewSubmitted) {
                    $pl->mark_has("need_review");
                    $row->ensure_reviewer_last_login();
                    if (!$rrow->reviewLastLogin)
                        $t .= ' <span class="hint">(never logged in)</span>';
                    else if ($rrow->reviewLastLogin >= $Now - 259200)
                        $t .= ' <span class="hint">(last site activity ' . plural(round(($Now - $rrow->reviewLastLogin) / 3600), "hour") . ' ago)</span>';
                    else
                        $t .= ' <span class="hint">(last site activity ' . plural(round(($Now - $rrow->reviewLastLogin) / 86400), "day") . ' ago)</span>';
                }
                $rx[] = $t;
            }
        }
        $pl->user->set_overrides($old_overrides);
        return join('; ', $rx);
    }
}
