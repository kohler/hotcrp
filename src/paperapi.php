<?php
// paperapi.php -- HotCRP paper-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class PaperApi {
    static function tagreport(Contact $user, $prow) {
        $ret = (object) ["ok" => $user->can_view_tags($prow)];
        if ($prow) {
            $ret->pid = $prow->paperId;
        }
        $ret->tagreport = [];
        if (!$ret->ok) {
            return $ret;
        }
        if (($vt = $user->conf->tags()->filter("vote"))) {
            $myprefix = $user->contactId . "~";
            $qv = $myvotes = array();
            foreach ($vt as $lbase => $t) {
                $qv[] = $myprefix . $lbase;
                $myvotes[$lbase] = 0;
            }
            $result = $user->conf->qe("select tag, sum(tagIndex) from PaperTag join Paper using (paperId) where timeSubmitted>0 and tag?a group by tag", $qv);
            while (($row = $result->fetch_row())) {
                $lbase = strtolower(substr($row[0], strlen($myprefix)));
                $myvotes[$lbase] += +$row[1];
            }
            Dbl::free($result);
            foreach ($vt as $lbase => $t) {
                if ($myvotes[$lbase] < $t->vote) {
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 0, "message" => plural($t->vote - $myvotes[$lbase], "vote") . " remaining", "search" => "editsort:-#~{$t->tag}"];
                } else if ($myvotes[$lbase] > $t->vote) {
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 1, "message" => plural($myvotes[$lbase] - $t->vote, "overvote"), "search" => "editsort:-#~{$t->tag}"];
                }
            }
        }
        return $ret;
    }

    static function tagreport_api(Contact $user, $qreq, $prow) {
        return new JsonResult((array) self::tagreport($user, $prow));
    }

    static function settags_api(Contact $user, $qreq, $prow) {
        if ($qreq->cancel) {
            return ["ok" => true];
        } else if ($prow && !$user->can_view_paper($prow)) {
            return ["ok" => false, "error" => "No such paper."];
        }

        // save tags using assigner
        $pids = [];
        $x = array("paper,action,tag");
        if ($prow) {
            if (isset($qreq->tags)) {
                $x[] = "$prow->paperId,tag,all#clear";
                foreach (TagInfo::split($qreq->tags) as $t)
                    $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            }
            foreach (TagInfo::split((string) $qreq->addtags) as $t) {
                $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            }
            foreach (TagInfo::split((string) $qreq->deltags) as $t) {
                $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t . "#clear");
            }
        } else if (isset($qreq->tagassignment)) {
            $pid = -1;
            foreach (preg_split('/[\s,]+/', $qreq->tagassignment) as $w) {
                if ($w !== "" && ctype_digit($w)) {
                    $pid = intval($w);
                } else if ($w !== "" && $pid > 0) {
                    $x[] = "$pid,tag," . CsvGenerator::quote($w);
                    $pids[$pid] = true;
                }
            }
        }
        $assigner = new AssignmentSet($user);
        $assigner->parse(join("\n", $x));
        $error = join("<br />", $assigner->errors_html());
        $ok = $assigner->execute();
        $user->remove_overrides(Contact::OVERRIDE_CONFLICT);

        // exit
        if ($ok && $prow) {
            $prow->load_tags();
            $taginfo = self::tagreport($user, $prow);
            $prow->add_tag_info_json($taginfo, $user);
            $jr = new JsonResult($taginfo);
        } else if ($ok) {
            $p = [];
            if ($pids) {
                foreach ($user->paper_set(array_keys($pids)) as $prow) {
                    $p[$prow->paperId] = (object) [];
                    $prow->add_tag_info_json($p[$prow->paperId], $user);
                }
            }
            $jr = new JsonResult(["ok" => true, "p" => (object) $p]);
        } else {
            $jr = new JsonResult(["ok" => false, "error" => $error]);
        }
        return $jr;
    }

    static function votereport_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            json_exit(["ok" => false, "error" => $tagger->error_html]);
        }
        if (!$user->can_view_peruser_tag($prow, $tag)) {
            json_exit(["ok" => false, "error" => "Permission error."]);
        }
        $votemap = [];
        preg_match_all('/ (\d+)~' . preg_quote($tag) . '#(\S+)/i', $prow->all_tags_text(), $m);
        $is_approval = $user->conf->tags()->is_approval($tag);
        $min_vote = $is_approval ? 0 : 0.001;
        for ($i = 0; $i != count($m[0]); ++$i)
            if ($m[2][$i] >= $min_vote)
                $votemap[$m[1][$i]] = $m[2][$i];
        $user->ksort_cid_array($votemap);
        $result = [];
        foreach ($votemap as $k => $v)
            if ($is_approval)
                $result[] = $user->reviewer_html_for($k);
            else
                $result[] = $user->reviewer_html_for($k) . " ($v)";
        if (empty($result))
            json_exit(["ok" => true, "result" => ""]);
        else
            json_exit(["ok" => true, "result" => '<span class="nw">' . join(',</span> <span class="nw">', $result) . '</span>']);
    }

    static function get_user(Contact $user, Qrequest $qreq) {
        $u = $user;
        if (isset($qreq->u) || isset($qreq->reviewer)) {
            $x = isset($qreq->u) ? $qreq->u : $qreq->reviewer;
            if ($x === ""
                || strcasecmp($x, "me") == 0
                || ($user->contactId > 0 && $x == $user->contactId)
                || strcasecmp($x, $user->email) == 0)
                $u = $user;
            else if (ctype_digit($x))
                $u = $user->conf->cached_user_by_id($x);
            else
                $u = $user->conf->cached_user_by_email($x);
            if (!$u) {
                error_log("PaperApi::get_user: rejecting user {$x}, requested by {$user->email}");
                json_exit(403, $user->isPC ? "No such user." : "Permission error.");
            }
        }
        return $u;
    }

    static function get_reviewer(Contact $user, $qreq, $prow) {
        $u = self::get_user($user, $qreq);
        if ($u->contactId !== $user->contactId
            && ($prow ? !$user->can_administer($prow) : !$user->privChair)) {
            error_log("PaperApi::get_reviewer: rejecting user {$u->contactId}/{$u->email}, requested by {$user->contactId}/{$user->email}");
            json_exit(403, "Permission error.");
        }
        return $u;
    }

    static function follow_api(Contact $user, $qreq, $prow) {
        $reviewer = self::get_reviewer($user, $qreq, $prow);
        $following = friendly_boolean($qreq->following);
        if ($following === null)
            return ["ok" => false, "error" => "Bad 'following'."];
        $bits = Contact::WATCH_REVIEW_EXPLICIT | ($following ? Contact::WATCH_REVIEW : 0);
        $user->conf->qe("insert into PaperWatch set paperId=?, contactId=?, watch=? on duplicate key update watch=(watch&~?)|?",
            $prow->paperId, $reviewer->contactId, $bits,
            Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW, $bits);
        return ["ok" => true, "following" => $following];
    }

    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $result = [];
        if ($user->can_view_pc()) {
            $pcmap = $user->conf->pc_completion_map();
            foreach ($user->conf->pc_members_and_admins() as $pc)
                if (!$pc->is_disabled()
                    && (!$prow || $pc->can_view_new_comment_ignore_conflict($prow))) {
                    $primary = true;
                    foreach ($pc->completion_items() as $k => $level)
                        if (get($pcmap, $k) === $pc) {
                            $skey = $primary ? "s" : "sm1";
                            $result[$k] = [$skey => $k, "d" => $pc->name()];
                            $primary = false;
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
            if ($rrow === false)
                return new JsonResult(400, "Bad request.");
            $rrows = $rrow ? [$rrow] : [];
        } else if (isset($qreq->u)) {
            $need_id = true;
            $u = self::get_user($user, $qreq);
            $rrows = $prow->full_reviews_of_user($u);
            if (!$rrows
                && $user->contactId !== $u->contactId
                && !$user->can_view_review_identity($prow, null))
                return new JsonResult(403, "Permission error.");
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
        if (!$vrrows && $rrows)
            return new JsonResult(403, "Permission error.");
        else
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
    }

    static function reviewrating_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$qreq->r
            || ($rrow = $prow->full_review_of_textual_id($qreq->r)) === false)
            return new JsonResult(400, "Bad request.");
        else if (!$user->can_view_review($prow, $rrow))
            return new JsonResult(403, "Permission error.");
        else if (!$rrow)
            return new JsonResult(404, "No such review.");
        $editable = $user->can_rate_review($prow, $rrow);
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->user_rating)
                || ($rating = ReviewInfo::parse_rating($qreq->user_rating)) === false)
                return new JsonResult(400, "Bad request.");
            else if (!$editable)
                return new JsonResult(403, "Permission error.");
            if ($rating === null)
                $user->conf->qe("delete from ReviewRating where paperId=? and reviewId=? and contactId=?", $prow->paperId, $rrow->reviewId, $user->contactId);
            else
                $user->conf->qe("insert into ReviewRating set paperId=?, reviewId=?, contactId=?, rating=? on duplicate key update rating=values(rating)", $prow->paperId, $rrow->reviewId, $user->contactId, $rating);
            $rrow = $prow->fresh_review_of_id($rrow->reviewId);
        }
        $rating = $rrow->rating_of_user($user);
        $jr = new JsonResult(["ok" => true, "user_rating" => $rating]);
        if ($editable)
            $jr->content["editable"] = true;
        if ($user->can_view_review_ratings($prow, $rrow))
            $jr->content["ratings"] = array_values($rrow->ratings());
        return $jr;
    }

    static function reviewround_api(Contact $user, $qreq, $prow) {
        if (!$qreq->r
            || ($rrow = $prow->full_review_of_textual_id($qreq->r)) === false)
            return new JsonResult(400, "Bad request.");
        else if (!$user->can_administer($prow))
            return new JsonResult(403, "Permission error.");
        else if (!$rrow)
            return new JsonResult(404, "No such review.");
        $rname = trim((string) $qreq->round);
        $round = $user->conf->sanitize_round_name($rname);
        if ($round === false)
            return ["ok" => false, "error" => Conf::round_name_error($rname)];
        $rnum = (int) $user->conf->round_number($round, true);
        $user->conf->qe("update PaperReview set reviewRound=? where paperId=? and reviewId=?", $rnum, $prow->paperId, $rrow->reviewId);
        return ["ok" => true];
    }
}
