<?php
// api/api_review.php -- HotCRP review-related API calls
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class Review_API {
    static function review(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_review($prow, null)) {
            return JsonResult::make_permission_error();
        }
        $need_id = false;
        if (isset($qreq->r)) {
            $rrow = $prow->full_review_by_ordinal_id($qreq->r);
            if (!$rrow && $prow->parse_ordinal_id($qreq->r) === false) {
                return JsonResult::make_parameter_error("r");
            }
            $rrows = $rrow ? [$rrow] : [];
            $need_id = true;
        } else if (isset($qreq->u)) {
            $u = APIHelpers::parse_user($qreq->u, $user, "u");
            $rrows = $prow->full_reviews_by_user($u);
            $need_id = $user->contactId !== $u->contactId;
        } else {
            $prow->ensure_full_reviews();
            $rrows = $prow->reviews_as_display();
        }
        $vrrows = [];
        $pex = new PaperExport($user);
        $rf = $user->conf->review_form();
        foreach ($rrows as $rrow) {
            if ($user->can_view_review($prow, $rrow)
                && (!$need_id || $user->can_view_review_identity($prow, $rrow))) {
                $vrrows[] = $pex->review_json($prow, $rrow);
            }
        }
        if ($vrrows || (!$rrows && !$need_id)) {
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
        } else {
            return JsonResult::make_permission_error();
        }
    }

    static function reviewhistory(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_review($prow, null)) {
            return JsonResult::make_permission_error();
        }
        if (!isset($qreq->r)
            || !($rrow = $prow->full_review_by_ordinal_id($qreq->r))) {
            return JsonResult::make_parameter_error("r");
        }
        if (!$user->is_my_review($rrow)
            && !$user->can_administer($prow)) {
            return JsonResult::make_permission_error();
        }
        $pex = new PaperExport($user);
        $pex->set_include_permissions(false);
        $pex->set_include_ratings(false);
        $vs = [$pex->review_json($prow, $rrow)];
        $history = $rrow->history();
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if ($history[$i] instanceof ReviewInfo) {
                $vs[] = $pex->review_json($prow, $history[$i]);
            } else {
                $vs[] = $pex->review_history_json($prow, $rrow, $history[$i]);
            }
        }
        return new JsonResult(["ok" => true, "versions" => $vs]);
    }

    static function reviewrating(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$qreq->r) {
            return JsonResult::make_error(400, "<0>Bad request");
        }
        $rrow = $prow->full_review_by_ordinal_id($qreq->r);
        if (!$rrow && $prow->parse_ordinal_id($qreq->r) === false) {
            return JsonResult::make_error(400, "<0>Bad request");
        } else if (!$user->can_view_review($prow, $rrow)) {
            return JsonResult::make_permission_error();
        } else if (!$rrow) {
            return JsonResult::make_error(404, "<0>Review not found");
        }
        $editable = $user->can_rate_review($prow, $rrow);
        if ($qreq->method() !== "GET") {
            if ($qreq->user_rating === "clearall") {
                if (!$user->can_administer($prow)) {
                    return JsonResult::make_permission_error();
                }
                $rating = -1;
            } else {
                if (!$editable) {
                    return JsonResult::make_permission_error();
                }
                $rating = ReviewInfo::parse_rating($qreq->user_rating);
            }
            if ($rating === null) {
                return JsonResult::make_parameter_error("user_rating");
            }
            if ($rating < 0) {
                $user->conf->qe("delete from ReviewRating where paperId=? and reviewId=?", $prow->paperId, $rrow->reviewId);
            } else if ($rating === 0) {
                $user->conf->qe("delete from ReviewRating where paperId=? and reviewId=? and contactId=?", $prow->paperId, $rrow->reviewId, $user->contactId);
            } else {
                $user->conf->qe("insert into ReviewRating set paperId=?, reviewId=?, contactId=?, rating=? on duplicate key update rating=?", $prow->paperId, $rrow->reviewId, $user->contactId, $rating, $rating);
            }
            $rrow = $prow->fresh_review_by_id($rrow->reviewId);
        }
        $jr = new JsonResult(["ok" => true]);
        if ($user->can_view_review_ratings($prow, $rrow)) {
            $jr->content["ratings"] = ReviewInfo::unparse_rating_json(...$rrow->ratings());
        }
        if ($editable) {
            $jr->content["user_rating"] = ReviewInfo::unparse_rating_json($rrow->rating_by_rater($user));
        }
        return $jr;
    }

    /** @param PaperInfo $prow */
    static function reviewround(Contact $user, $qreq, $prow) {
        if (!$qreq->r) {
            return JsonResult::make_error(400, "<0>Bad request");
        }
        $rrow = $prow->full_review_by_ordinal_id($qreq->r);
        if (!$rrow && $prow->parse_ordinal_id($qreq->r) === false) {
            return JsonResult::make_error(400, "<0>Bad request");
        } else if (!$user->can_administer($prow)) {
            return JsonResult::make_permission_error();
        } else if (!$rrow) {
            return JsonResult::make_error(404, "<0>Review not found");
        }
        $rname_in = trim((string) $qreq->round);
        if (($rname = $user->conf->sanitize_round_name($rname_in)) === false) {
            return JsonResult::make_error(400, "<0>" . Conf::round_name_error($rname_in));
        } else if (($rnum = $user->conf->round_number($rname)) === null) {
            return JsonResult::make_error(400, "<0>Review round not found");
        }
        $user->conf->qe("update PaperReview set reviewRound=? where paperId=? and reviewId=?", $rnum, $prow->paperId, $rrow->reviewId);
        return ["ok" => true];
    }
}
