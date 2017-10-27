<?php
// pc_reviewdelegation.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewDelegation_PaperColumn extends PaperColumn {
    private $requester;
    function __construct($cj) {
        parent::__construct($cj);
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
        foreach ($row->reviews_by_display() as $rrow) {
            if ($rrow->reviewType == REVIEW_EXTERNAL
                && $rrow->requestedBy == $this->requester->contactId) {
                if (!$pl->user->can_view_review($row, $rrow, true))
                    continue;
                if ($pl->user->can_view_review_identity($row, $rrow, true))
                    $t = $pl->user->reviewer_html_for($rrow);
                else
                    $t = "review";
                $ranal = $pl->make_review_analysis($rrow, $row);
                $description = $ranal->description_text();
                if ($rrow->reviewOrdinal)
                    $description = rtrim("#" . unparseReviewOrdinal($rrow) . " " . $description);
                $description = $ranal->wrap_link($description, "uu");
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
        return join('; ', $rx);
    }
}
