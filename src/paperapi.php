<?php
// paperapi.php -- HotCRP paper-related API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class PaperApi {
    static function decision_api(Contact $user, Qrequest $qreq, $prow) {
        if ($qreq->method() !== "GET") {
            $aset = new AssignmentSet($user, true);
            $aset->enable_papers($prow);
            if (is_numeric($qreq->decision))
                $qreq->decision = get($user->conf->decision_map(), +$qreq->decision);
            $aset->parse("paper,action,decision\n{$prow->paperId},decision," . CsvGenerator::quote($qreq->decision));
            if (!$aset->execute())
                return $aset->json_result();
            $prow->outcome = $prow->conf->fetch_ivalue("select outcome from Paper where paperId=?", $prow->paperId);
        }
        if (!$user->can_view_decision($prow))
            json_exit(403, "Permission error.");
        $dname = $prow->conf->decision_name($prow->outcome);
        $jr = new JsonResult(["ok" => true, "value" => (int) $prow->outcome, "result" => htmlspecialchars($dname ? : "?")]);
        if ($user->can_set_decision($prow))
            $jr->content["editable"] = true;
        return $jr;
    }

    private static function paper_pc_api(Contact $user, Qrequest $qreq, $prow, $type) {
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->$type))
                return new JsonResult(400, ["ok" => false, "error" => "Missing parameter."]);
            $aset = new AssignmentSet($user);
            $aset->enable_papers($prow);
            $aset->parse("paper,action,user\n{$prow->paperId},$type," . CsvGenerator::quote($qreq->$type));
            if (!$aset->execute())
                return $aset->json_result();
            $cid = $user->conf->fetch_ivalue("select {$type}ContactId from Paper where paperId=?", $prow->paperId);
        } else {
            $k = "can_view_$type";
            if (!$user->$k($prow))
                return new JsonResult(403, ["ok" => false, "error" => "Permission error."]);
            $k = "{$type}ContactId";
            $cid = $prow->$k;
        }
        $luser = $cid ? $user->conf->pc_member_by_id($cid) : null;
        $j = ["ok" => true, "result" => $luser ? $user->name_html_for($luser) : "None"];
        if ($user->can_view_reviewer_tags($prow))
            $j["color_classes"] = $cid ? $user->user_color_classes_for($luser) : "";
        return $j;
    }

    static function lead_api(Contact $user, Qrequest $qreq, $prow) {
        return self::paper_pc_api($user, $qreq, $prow, "lead");
    }

    static function shepherd_api(Contact $user, Qrequest $qreq, $prow) {
        return self::paper_pc_api($user, $qreq, $prow, "shepherd");
    }

    static function manager_api(Contact $user, Qrequest $qreq, $prow) {
        return self::paper_pc_api($user, $qreq, $prow, "manager");
    }

    static function tagreport(Contact $user, $prow) {
        $ret = (object) ["ok" => $user->can_view_tags($prow)];
        if ($prow)
            $ret->pid = $prow->paperId;
        $ret->tagreport = [];
        if (!$ret->ok)
            return $ret;
        if (($vt = $user->conf->tags()->filter("vote"))) {
            $myprefix = $user->contactId . "~";
            $qv = $myvotes = array();
            foreach ($vt as $lbase => $t) {
                $qv[] = $myprefix . $lbase;
                $myvotes[$lbase] = 0;
            }
            $result = $user->conf->qe("select tag, sum(tagIndex) from PaperTag where tag ?a group by tag", $qv);
            while (($row = edb_row($result))) {
                $lbase = strtolower(substr($row[0], strlen($myprefix)));
                $myvotes[$lbase] += +$row[1];
            }
            Dbl::free($result);
            foreach ($vt as $lbase => $t) {
                if ($myvotes[$lbase] < $t->vote)
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 0, "message" => plural($t->vote - $myvotes[$lbase], "vote") . " remaining", "search" => "editsort:-#~{$t->tag}"];
                else if ($myvotes[$lbase] > $t->vote)
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 1, "message" => plural($myvotes[$lbase] - $t->vote, "vote") . " over", "search" => "editsort:-#~{$t->tag}"];
            }
        }
        return $ret;
    }

    static function tagreport_api(Contact $user, $qreq, $prow) {
        $jr = new JsonResult((array) self::tagreport($user, $prow));
        $jr->transfer_messages($user->conf, true);
        return $jr;
    }

    static function settags_api(Contact $user, $qreq, $prow) {
        if ($qreq->cancel)
            return ["ok" => true];
        if ($prow && !$user->can_view_paper($prow))
            return ["ok" => false, "error" => "No such paper."];

        // save tags using assigner
        $pids = [];
        $x = array("paper,action,tag");
        if ($prow) {
            if (isset($qreq->tags)) {
                $x[] = "$prow->paperId,tag,all#clear";
                foreach (TagInfo::split($qreq->tags) as $t)
                    $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            }
            foreach (TagInfo::split((string) $qreq->addtags) as $t)
                $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            foreach (TagInfo::split((string) $qreq->deltags) as $t)
                $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t . "#clear");
        } else if (isset($qreq->tagassignment)) {
            $pid = -1;
            foreach (preg_split('/[\s,]+/', $qreq->tagassignment) as $w)
                if ($w !== "" && ctype_digit($w))
                    $pid = intval($w);
                else if ($w !== "" && $pid > 0) {
                    $x[] = "$pid,tag," . CsvGenerator::quote($w);
                    $pids[$pid] = true;
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
        } else
            $jr = new JsonResult(["ok" => false, "error" => $error]);
        return $jr;
    }

    static function votereport_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE)))
            json_exit(["ok" => false, "error" => $tagger->error_html]);
        if (!$user->can_view_peruser_tags($prow, $tag))
            json_exit(["ok" => false, "error" => "Permission error."]);
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

    static function get_user(Contact $user, Qrequest $qreq, $forceShow = null) {
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

    static function get_reviewer(Contact $user, $qreq, $prow, $forceShow = null) {
        $u = self::get_user($user, $qreq, $forceShow);
        if ($u->contactId !== $user->contactId
            && ($prow ? !$user->can_administer($prow, $forceShow) : !$user->privChair)) {
            error_log("PaperApi::get_reviewer: rejecting user {$u->contactId}/{$u->email}, requested by {$user->contactId}/{$user->email}");
            json_exit(403, "Permission error.");
        }
        return $u;
    }

    static function pref_api(Contact $user, $qreq, $prow) {
        $u = self::get_reviewer($user, $qreq, $prow, true);
        if ($qreq->method() !== "GET") {
            $aset = new AssignmentSet($user, true);
            $aset->enable_papers($prow);
            $aset->parse("paper,user,preference\n{$prow->paperId}," . CsvGenerator::quote($u->email) . "," . CsvGenerator::quote($qreq->pref, true));
            if (!$aset->execute())
                return $aset->json_result();
            $prow->load_reviewer_preferences();
        }
        if ($u->contactId !== $user->contactId && !$user->allow_administer($prow)) {
            error_log("PaperApi::pref_api: rejecting user {$u->contactId}/{$u->email}, requested by {$user->contactId}/{$user->email}");
            json_exit(403, "Permission error.");
        }
        $pref = $prow->reviewer_preference($u, true);
        $value = unparse_preference($pref[0], $pref[1]);
        $jr = new JsonResult(["ok" => true, "value" => $value === "0" ? "" : $value, "pref" => $pref[0]]);
        if ($pref[1] !== null)
            $jr->content["prefexp"] = unparse_expertise($pref[1]);
        if ($user->conf->has_topics())
            $jr->content["topic_score"] = $pref[2];
        return $jr;
    }

    static function checkformat_api(Contact $user, $qreq, $prow) {
        $dtype = cvtint($qreq->dt, 0);
        $opt = $user->conf->paper_opts->get($dtype);
        if (!$opt || !$user->can_view_paper_option($prow, $opt))
            return ["ok" => false, "error" => "Permission error."];
        $cf = new CheckFormat($prow->conf);
        $doc = $cf->fetch_document($prow, $dtype, $qreq->docid);
        $cf->check_document($prow, $doc);
        return ["ok" => !$cf->failed, "response" => $cf->document_report($prow, $doc)];
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
                if (!$pc->disabled
                    && (!$prow || $pc->can_view_new_comment_ignore_conflict($prow))) {
                    $primary = true;
                    foreach ($pc->completion_items() as $k => $level)
                        if (get($pcmap, $k) === $pc) {
                            $skey = $primary ? "s" : "sm1";
                            $result[$k] = [$skey => $k, "d" => $pc->name_text()];
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
                return new JsonResult(400, "Parameter error.");
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
        foreach ($rrows as $rrow)
            if ($user->can_view_review($prow, $rrow)
                && (!$need_id || $user->can_view_review_identity($prow, $rrow)))
                $vrrows[] = $rf->unparse_review_json($prow, $rrow, $user);
        if (!$vrrows && $rrows)
            return new JsonResult(403, "Permission error.");
        else
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
    }

    static function reviewrating_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$qreq->r
            || ($rrow = $prow->full_review_of_textual_id($qreq->r)) === false)
            return new JsonResult(400, "Parameter error.");
        else if (!$user->can_view_review($prow, $rrow))
            return new JsonResult(403, "Permission error.");
        else if (!$rrow)
            return new JsonResult(404, "No such review.");
        $editable = $user->can_rate_review($prow, $rrow);
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->user_rating)
                || ($rating = ReviewInfo::parse_rating($qreq->user_rating)) === false)
                return new JsonResult(400, "Parameter error.");
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
            return new JsonResult(400, "Parameter error.");
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
