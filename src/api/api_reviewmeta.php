<?php
// api_reviewmeta.php -- HotCRP review metadata API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class ReviewMeta_API {
    /** @return JsonResult|ReviewInfo */
    static function lookup_review(Contact $user, PaperInfo $prow, $r) {
        if ($r === null) {
            return JsonResult::make_missing_error("r");
        }
        $rrow = $prow->full_review_by_ordinal_id($r);
        if ($rrow && $user->can_view_review($prow, $rrow)) {
            return $rrow;
        } else if ($rrow && $user->can_view_review_assignment($prow, $rrow)) {
            return JsonResult::make_permission_error("r");
        } else if (!$prow->parse_ordinal_id($r)) {
            return JsonResult::make_parameter_error("r", "<0>Invalid review");
        }
        return JsonResult::make_not_found_error("r", "<0>Review not found");
    }

    static function reviewhistory(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_submitted_review($prow)) {
            return JsonResult::make_permission_error();
        }
        $rrow = self::lookup_review($user, $prow, $qreq->r);
        if ($rrow instanceof JsonResult) {
            return $rrow;
        }
        if (!$user->is_my_review($rrow)
            && !$user->is_admin($prow)) {
            return JsonResult::make_permission_error("r");
        }
        $pex = new PaperExport($user);
        $pex->set_include_permissions(false);
        $pex->set_include_ratings(false);
        $fullh = $rrow;
        $vs = [$pex->review_json($prow, $rrow)];
        $history = $rrow->history();
        $expand = isset($qreq->expand) && friendly_boolean($qreq->expand);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $h = $history[$i];
            if ($expand && $h instanceof ReviewHistoryInfo) {
                $h = $fullh->apply_history($h);
            }
            if ($h instanceof ReviewInfo) {
                $vs[] = $pex->review_json($prow, $h);
                $fullh = $h;
            } else {
                $vs[] = $pex->review_history_json($prow, $rrow, $h);
            }
        }
        return new JsonResult(["ok" => true, "pid" => $prow->paperId, "rid" => $rrow->reviewId, "versions" => $vs]);
    }

    static function reviewrating(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $rrow = self::lookup_review($user, $prow, $qreq->r);
        if ($rrow instanceof JsonResult) {
            return $rrow;
        }
        $editable = $user->can_rate_review($prow, $rrow);
        if ($qreq->method() !== "GET") {
            if ($qreq->user_rating === "clearall") {
                if (!$user->can_manage_reviews($prow)) {
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
        if (!$user->can_manage_reviews($prow)) {
            return JsonResult::make_permission_error();
        }
        $rrow = self::lookup_review($user, $prow, $qreq->r);
        if ($rrow instanceof JsonResult) {
            return $rrow;
        }
        $rname_in = trim((string) $qreq->round);
        if (($rname = $user->conf->sanitize_round_name($rname_in)) === false) {
            return JsonResult::make_parameter_error("round", "<0>" . Conf::round_name_error($rname_in));
        } else if (($rnum = $user->conf->round_number($rname)) === null) {
            return JsonResult::make_parameter_error("round", "<0>Review round not found");
        }
        $user->conf->qe("update PaperReview set reviewRound=? where paperId=? and reviewId=?", $rnum, $prow->paperId, $rrow->reviewId);
        return ["ok" => true];
    }
}
