<?php
// paperapi.php -- HotCRP paper-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class PaperApi {
    /** @return Contact */
    static function get_user(Contact $user, Qrequest $qreq) {
        $u = $user;
        if (isset($qreq->u) || isset($qreq->reviewer)) {
            $x = isset($qreq->u) ? $qreq->u : $qreq->reviewer;
            if ($x === ""
                || strcasecmp($x, "me") == 0
                || ($user->contactId > 0 && $x == $user->contactId)
                || strcasecmp($x, $user->email) == 0) {
                $u = $user;
            } else if (ctype_digit($x)) {
                $u = $user->conf->cached_user_by_id($x);
            } else {
                $u = $user->conf->cached_user_by_email($x);
            }
            if (!$u) {
                error_log("PaperApi::get_user: rejecting user {$x}, requested by {$user->email}");
                json_exit(403, $user->isPC ? "No such user." : "Permission error.");
                exit;
            }
        }
        return $u;
    }

    /** @param ?PaperInfo $prow */
    static function get_reviewer(Contact $user, $qreq, $prow) {
        $u = self::get_user($user, $qreq);
        if ($u->contactId !== $user->contactId
            && ($prow ? !$user->can_administer($prow) : !$user->privChair)) {
            error_log("PaperApi::get_reviewer: rejecting user {$u->contactId}/{$u->email}, requested by {$user->contactId}/{$user->email}");
            json_exit(403, "Permission error.");
        }
        return $u;
    }

    /** @param ?PaperInfo $prow */
    static function follow_api(Contact $user, $qreq, $prow) {
        $reviewer = self::get_reviewer($user, $qreq, $prow);
        $following = friendly_boolean($qreq->following);
        if ($following === null) {
            return ["ok" => false, "error" => "Bad 'following'."];
        }
        $bits = Contact::WATCH_REVIEW_EXPLICIT | ($following ? Contact::WATCH_REVIEW : 0);
        $user->conf->qe("insert into PaperWatch set paperId=?, contactId=?, watch=? on duplicate key update watch=(watch&~?)|?",
            $prow->paperId, $reviewer->contactId, $bits,
            Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW, $bits);
        return ["ok" => true, "following" => $following];
    }

    /** @param ?PaperInfo $prow */
    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $result = [];
        if ($user->can_view_pc()) {
            $pcmap = $user->conf->pc_completion_map();
            foreach ($user->conf->pc_users() as $pc) {
                if (!$pc->is_disabled()
                    && (!$prow || $pc->can_view_new_comment_ignore_conflict($prow))) {
                    $primary = true;
                    foreach ($pc->completion_items() as $k => $level) {
                        if (get($pcmap, $k) === $pc) {
                            $skey = $primary ? "s" : "sm1";
                            $result[$k] = [$skey => $k, "d" => $pc->name()];
                            $primary = false;
                        }
                    }
                }
            }
        }
        ksort($result);
        return ["ok" => true, "mentioncompletion" => array_values($result)];
    }

    static function review_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_review($prow, null))
            return new JsonResult(403, "Permission error.");
        $need_id = false;
        if (isset($qreq->r)) {
            $rrow = $prow->full_review_of_textual_id($qreq->r);
            if ($rrow === false) {
                return new JsonResult(400, "Bad request.");
            }
            $rrows = $rrow ? [$rrow] : [];
        } else if (isset($qreq->u)) {
            $need_id = true;
            $u = self::get_user($user, $qreq);
            $rrows = $prow->full_reviews_of_user($u);
            if (!$rrows
                && $user->contactId !== $u->contactId
                && !$user->can_view_review_identity($prow, null)) {
                return new JsonResult(403, "Permission error.");
            }
        } else {
            $prow->ensure_full_reviews();
            $rrows = $prow->viewable_submitted_reviews_by_display($user);
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
            return new JsonResult(403, "Permission error.");
        } else {
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
        }
    }

    static function reviewrating_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$qreq->r
            || ($rrow = $prow->full_review_of_textual_id($qreq->r)) === false) {
            return new JsonResult(400, "Bad request.");
        } else if (!$user->can_view_review($prow, $rrow)) {
            return new JsonResult(403, "Permission error.");
        } else if (!$rrow) {
            return new JsonResult(404, "No such review.");
        }
        $editable = $user->can_rate_review($prow, $rrow);
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->user_rating)
                || ($rating = ReviewInfo::parse_rating($qreq->user_rating)) === false) {
                return new JsonResult(400, "Bad request.");
            } else if (!$editable) {
                return new JsonResult(403, "Permission error.");
            }
            if ($rating === null) {
                $user->conf->qe("delete from ReviewRating where paperId=? and reviewId=? and contactId=?", $prow->paperId, $rrow->reviewId, $user->contactId);
            } else {
                $user->conf->qe("insert into ReviewRating set paperId=?, reviewId=?, contactId=?, rating=? on duplicate key update rating=values(rating)", $prow->paperId, $rrow->reviewId, $user->contactId, $rating);
            }
            $rrow = $prow->fresh_review_of_id($rrow->reviewId);
        }
        $rating = $rrow->rating_of_user($user);
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
    static function reviewround_api(Contact $user, $qreq, $prow) {
        if (!$qreq->r
            || ($rrow = $prow->full_review_of_textual_id($qreq->r)) === false) {
            return new JsonResult(400, "Bad request.");
        } else if (!$user->can_administer($prow)) {
            return new JsonResult(403, "Permission error.");
        } else if (!$rrow) {
            return new JsonResult(404, "No such review.");
        } else {
            $rname = trim((string) $qreq->round);
            $round = $user->conf->sanitize_round_name($rname);
            if ($round === false) {
                return ["ok" => false, "error" => Conf::round_name_error($rname)];
            }
            $rnum = (int) $user->conf->round_number($round, true);
            $user->conf->qe("update PaperReview set reviewRound=? where paperId=? and reviewId=?", $rnum, $prow->paperId, $rrow->reviewId);
            return ["ok" => true];
        }
    }
}
