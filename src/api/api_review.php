<?php
// api/api_review.php -- HotCRP paper-related API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Review_API {
    static function review(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_review($prow, null)) {
            return JsonResult::make_permission_error();
        }
        $need_id = false;
        if (isset($qreq->r)) {
            $rrow = $prow->full_review_by_ordinal_id($qreq->r);
            if (!$rrow && $prow->parse_ordinal_id($qreq->r) === false) {
                return JsonResult::make_error(400, "<0>Bad request");
            }
            $rrows = $rrow ? [$rrow] : [];
        } else if (isset($qreq->u)) {
            $need_id = true;
            $u = APIHelpers::parse_reviewer_for($qreq->u, $user, $prow);
            $rrows = $prow->full_reviews_by_user($u);
            if (!$rrows
                && $user->contactId !== $u->contactId
                && !$user->can_view_review_identity($prow, null)) {
                return JsonResult::make_permission_error();
            }
        } else {
            $prow->ensure_full_reviews();
            $rrows = $prow->reviews_as_display();
        }
        $vrrows = [];
        $rf = $user->conf->review_form();
        foreach ($rrows as $rrow) {
            if ($user->can_view_review($prow, $rrow)
                && (!$need_id || $user->can_view_review_identity($prow, $rrow))) {
                $vrrows[] = $rf->unparse_review_json($user, $prow, $rrow);
            }
        }
        if (!$vrrows && $rrows) {
            return JsonResult::make_permission_error();
        } else {
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
        }
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
            if (!isset($qreq->user_rating)
                || ($rating = ReviewInfo::parse_rating($qreq->user_rating)) === null) {
                return JsonResult::make_error(400, "<0>Bad request");
            } else if (!$editable) {
                return JsonResult::make_permission_error();
            }
            if ($rating === 0) {
                $user->conf->qe("delete from ReviewRating where paperId=? and reviewId=? and contactId=?", $prow->paperId, $rrow->reviewId, $user->contactId);
            } else {
                $user->conf->qe("insert into ReviewRating set paperId=?, reviewId=?, contactId=?, rating=? on duplicate key update rating=?", $prow->paperId, $rrow->reviewId, $user->contactId, $rating, $rating);
            }
            $rrow = $prow->fresh_review_by_id($rrow->reviewId);
        }
        $rating = $rrow->rating_by_rater($user);
        $jr = new JsonResult(["ok" => true, "user_rating" => $rating]);
        if ($editable) {
            $jr->content["editable"] = true;
        }
        if ($user->can_view_review_ratings($prow, $rrow)) {
            $jr->content["ratings"] = array_values($rrow->ratings());
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
