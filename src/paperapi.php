<?php
// paperapi.php -- HotCRP paper-related API calls
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
        $ret = (object) ["ok" => $user->can_view_tags($prow), "warnings" => [], "messages" => []];
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
            $vlo = $vhi = array();
            foreach ($vt as $lbase => $t) {
                if ($myvotes[$lbase] < $t->vote)
                    $vlo[] = '<a class="q" href="' . hoturl("search", "q=editsort:-%23~{$t->tag}") . '">~' . $t->tag . '</a>#' . ($t->vote - $myvotes[$lbase]);
                else if ($myvotes[$lbase] > $t->vote
                         && (!$prow || $prow->has_tag($myprefix . $lbase)))
                    $vhi[] = '<span class="nw"><a class="q" href="' . hoturl("search", "q=sort:-%23~{$t->tag}+edit:%23~{$t->tag}") . '">~' . $t->tag . '</a> (' . ($myvotes[$lbase] - $t->vote) . " over)</span>";
            }
            if (count($vlo))
                $ret->messages[] = 'Remaining <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vlo);
            if (count($vhi))
                $ret->warnings[] = 'Overallocated <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vhi);
        }
        return $ret;
    }

    static function tagreport_api(Contact $user, $qreq, $prow) {
        $treport = self::tagreport($user, $prow);
        $response = "";
        if (!empty($treport->warnings))
            $response .= Ht::xmsg("warning", join("<br>", $treport->warnings));
        if (!empty($treport->messages))
            $response .= Ht::xmsg("info", join("<br>", $treport->messages));
        $jr = new JsonResult(["ok" => $treport->ok, "response" => $response]);
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

        // exit
        if ($ok && $prow) {
            $prow->load_tags();
            $treport = self::tagreport($user, $prow);
            if ($treport->warnings)
                $user->conf->warnMsg(join("<br>", $treport->warnings));
            $taginfo = (object) ["ok" => true, "pid" => $prow->paperId];
            $prow->add_tag_info_json($taginfo, $user);
            $jr = new JsonResult($taginfo);
        } else if ($ok) {
            $p = [];
            if ($pids) {
                $result = $user->paper_result(["paperId" => array_keys($pids), "tags" => true]);
                while (($prow = PaperInfo::fetch($result, $user))) {
                    $p[$prow->paperId] = (object) [];
                    $prow->add_tag_info_json($p[$prow->paperId], $user);
                }
                Dbl::free($result);
            }
            $jr = new JsonResult(["ok" => true, "p" => (object) $p]);
        } else
            $jr = new JsonResult(false);
        $jr->transfer_messages($user->conf, true);
        return $jr;
    }

    static function taganno_api(Contact $user, $qreq, $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE)))
            return ["ok" => false, "error" => $tagger->error_html];
        $j = ["ok" => true, "tag" => $tag, "editable" => $user->can_change_tag_anno($tag),
              "anno" => []];
        $dt = $user->conf->tags()->add(TagInfo::base($tag));
        foreach ($dt->order_anno_list() as $oa)
            if ($oa->annoId !== null)
                $j["anno"][] = $oa;
        return $j;
    }

    static function settaganno_api($user, $qreq, $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE)))
            json_exit(["ok" => false, "error" => $tagger->error_html]);
        if (!$user->can_change_tag_anno($tag))
            json_exit(["ok" => false, "error" => "Permission error."]);
        if (!isset($qreq->anno) || ($reqanno = json_decode($qreq->anno)) === false
            || (!is_object($reqanno) && !is_array($reqanno)))
            json_exit(["ok" => false, "error" => "Bad request."]);
        $q = $qv = $errors = $errf = $inserts = [];
        $next_annoid = $user->conf->fetch_value("select greatest(coalesce(max(annoId),0),0)+1 from PaperTagAnno where tag=?", $tag);
        // parse updates
        foreach (is_object($reqanno) ? [$reqanno] : $reqanno as $anno) {
            if (!isset($anno->annoid)
                || (!is_int($anno->annoid) && !preg_match('/^n/', $anno->annoid)))
                json_exit(["ok" => false, "error" => "Bad request."]);
            if (isset($anno->deleted) && $anno->deleted) {
                if (is_int($anno->annoid)) {
                    $q[] = "delete from PaperTagAnno where tag=? and annoId=?";
                    array_push($qv, $tag, $anno->annoid);
                }
                continue;
            }
            if (is_int($anno->annoid))
                $annoid = $anno->annoid;
            else {
                $annoid = $next_annoid;
                ++$next_annoid;
                $q[] = "insert into PaperTagAnno (tag,annoId) values (?,?)";
                array_push($qv, $tag, $annoid);
            }
            if (isset($anno->heading)) {
                $q[] = "update PaperTagAnno set heading=?, annoFormat=null where tag=? and annoId=?";
                array_push($qv, $anno->heading, $tag, $annoid);
            }
            if (isset($anno->tagval)) {
                $tagval = trim($anno->tagval);
                if ($tagval === "")
                    $tagval = "0";
                if (is_numeric($tagval)) {
                    $q[] = "update PaperTagAnno set tagIndex=? where tag=? and annoId=?";
                    array_push($qv, floatval($tagval), $tag, $annoid);
                } else {
                    $errf["tagval_{$anno->annoid}"] = true;
                    $errors[] = "Tag value should be a number.";
                }
            }
        }
        // return error if any
        if (!empty($errors))
            json_exit(["ok" => false, "error" => join("<br />", $errors), "errf" => $errf]);
        // apply changes
        if (!empty($q)) {
            $mresult = Dbl::multi_qe_apply($user->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }
        // return results
        return self::taganno_api($user, $qreq, $prow);
    }

    static function votereport_api(Contact $user, $qreq, $prow) {
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

    static function alltags_api(Contact $user, $qreq, $prow) {
        if (!$user->isPC)
            json_exit(["ok" => false]);

        $need_paper = $cflt_where = false;
        $where = $args = array();

        if ($user->allow_administer(null)) {
            $need_paper = true;
            if ($user->conf->has_any_manager() && !$user->conf->tag_seeall)
                $cflt_where = "(p.managerContactId=0 or p.managerContactId=$user->contactId or pc.conflictType is null)";
        } else if ($user->conf->check_track_sensitivity(Track::VIEW)) {
            $where[] = "t.paperId ?a";
            $args[] = $user->list_submitted_papers_with_viewable_tags();
        } else {
            $need_paper = true;
            if ($user->conf->has_any_manager() && !$user->conf->tag_seeall)
                $cflt_where = "(p.managerContactId=$user->contactId or pc.conflictType is null)";
            else if (!$user->conf->tag_seeall)
                $cflt_where = "pc.conflictType is null";
        }

        $q = "select distinct tag from PaperTag t";
        if ($need_paper) {
            $q .= " join Paper p on (p.paperId=t.paperId)";
            $where[] = "p.timeSubmitted>0";
        }
        if ($cflt_where) {
            $q .= " left join PaperConflict pc on (pc.paperId=t.paperId and pc.contactId=$user->contactId)";
            $where[] = $cflt_where;
        }
        $q .= " where " . join(" and ", $where);

        $tags = array();
        $result = $user->conf->qe_apply($q, $args);
        while (($row = edb_row($result))) {
            $twiddle = strpos($row[0], "~");
            if ($twiddle === false
                || ($twiddle == 0 && $row[0][1] === "~" && $user->privChair))
                $tags[] = $row[0];
            else if ($twiddle > 0 && substr($row[0], 0, $twiddle) == $user->contactId)
                $tags[] = substr($row[0], $twiddle);
        }
        Dbl::free($result);
        json_exit(["ok" => true, "tags" => $tags]);
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
        $cf = new CheckFormat;
        $doc = $cf->fetch_document($prow, $dtype, $qreq->docid);
        $cf->check_document($prow, $doc);
        return ["ok" => !$cf->failed, "response" => $cf->document_report($prow, $doc)];
    }

    static function whoami_api(Contact $user, $qreq, $prow) {
        return ["ok" => true, "email" => $user->email];
    }

    static function fieldhtml_api(Contact $user, $qreq, $prow) {
        $fdef = $qreq->f ? PaperColumn::lookup($user, $qreq->f) : null;
        if ($fdef && is_array($fdef))
            $fdef = count($fdef) == 1 ? $fdef[0] : null;
        if (!$fdef || !$fdef->fold)
            return ["ok" => false, "error" => "No such field."];
        if ($qreq->f == "au" || $qreq->f == "authors")
            PaperList::change_display($user, "pl", "aufull", (int) $qreq->aufull);
        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q))
            $qreq->q = "";
        $reviewer = null;
        if ($qreq->reviewer && $user->email !== $qreq->reviewer)
            $reviewer = $user->conf->user_by_email($qreq->reviewer);
        unset($qreq->reviewer);
        $search = new PaperSearch($user, $qreq, $reviewer);
        $pl = new PaperList($search, ["report" => "pl"]);
        $response = $pl->column_json($qreq->f);
        $response["ok"] = !empty($response);
        return $response;
    }

    static function follow_api(Contact $user, $qreq, $prow) {
        $reviewer = self::get_reviewer($user, $qreq, $prow);
        $following = friendly_boolean($qreq->following);
        if ($following === null)
            return ["ok" => false, "error" => "Bad 'following'."];
        saveWatchPreference($prow->paperId, $reviewer->contactId,
            WATCHTYPE_COMMENT, $following, true);
        return ["ok" => true, "following" => $following];
    }

    static function reviewround_api(Contact $user, $qreq, $prow) {
        if (!$qreq->r
            || !($rr = $prow->review_of_id($qreq->r)))
            return ["ok" => false, "error" => "No such review."];
        if (!$user->can_administer($prow))
            return ["ok" => false, "error" => "Permission error."];
        $rname = trim((string) $qreq->round);
        $round = $user->conf->sanitize_round_name($rname);
        if ($round === false)
            return ["ok" => false, "error" => Conf::round_name_error($rname)];
        $rnum = (int) $user->conf->round_number($round, true);
        $user->conf->qe("update PaperReview set reviewRound=? where paperId=? and reviewId=?", $rnum, $prow->paperId, $rr->reviewId);
        return ["ok" => true];
    }

    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $result = [];
        if ($user->isPC) {
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

    static function search_api(Contact $user, Qrequest $qreq, $prow) {
        $topt = PaperSearch::search_types($user, $qreq->t);
        if (empty($topt) || ($qreq->t && !isset($topt[$qreq->t])))
            return new JsonResult(403, "Permission error.");
        $t = $qreq->t ? : key($topt);

        $q = $qreq->q;
        if (isset($q)) {
            $q = trim($q);
            if ($q === "(All)")
                $q = "";
        } else if (isset($qreq->qa) || isset($qreq->qo) || isset($qreq->qx))
            $q = PaperSearch::canonical_query((string) $qreq->qa, (string) $qreq->qo, (string) $qreq->qx, $user->conf);
        else
            return new JsonResult(400, "Missing parameter.");

        $sarg = ["t" => $t, "q" => $q];
        if ($qreq->qt)
            $sarg["qt"] = $qreq->qt;
        if ($qreq->urlbase)
            $sarg["urlbase"] = $qreq->urlbase;

        $search = new PaperSearch($user, $sarg);
        $pl = new PaperList($search, ["sort" => true], $qreq);
        $ih = $pl->ids_and_groups();
        return ["ok" => true, "ids" => $ih[0], "groups" => $ih[1],
                "hotlist_info" => $pl->session_list_object()->info_string()];
    }

    static function review_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_view_review($prow, null))
            return new JsonResult(403, "Permission error.");
        if (isset($qreq->r)) {
            if (ctype_digit($qreq->r))
                $rrow = $prow->full_review_of_id(intval($qreq->r));
            else if (preg_match('/\A(?:|' . $prow->paperId . ')([A-Z]+)\z/', $qreq->r, $m))
                $rrow = $prow->full_review_of_ordinal(parseReviewOrdinal($m[1]));
            else
                return new JsonResult(400, "Parameter error.");
            $rrows = $rrow ? [$rrow] : [];
        } else if (isset($qreq->u)) {
            $u = self::get_user($user, $qreq);
            $rrow = $prow->full_review_of_user($u);
            if (!$user->can_view_review_identity($prow, $rrow))
                return new JsonResult(403, "Permission error.");
            $rrows = $rrow ? [$rrow] : [];
        } else {
            $prow->ensure_full_reviews();
            $rrows = $prow->viewable_submitted_reviews_by_display($user, null);
        }
        $vrrows = [];
        $rf = $user->conf->review_form();
        foreach ($rrows as $rrow)
            if ($user->can_view_review($prow, $rrow))
                $vrrows[] = $rf->unparse_review_json($prow, $rrow, $user);
        if (!$vrrows && $rrows)
            return new JsonResult(403, "Permission error.");
        else
            return new JsonResult(["ok" => true, "reviews" => $vrrows]);
    }

    static function listreport_api(Contact $user, Qrequest $qreq, $prow) {
        $report = get($qreq, "report", "pl");
        if ($report !== "pl" && $report !== "pf")
            return new JsonResult(400, "Parameter error.");
        if ($qreq->method() !== "GET" && $user->privChair) {
            if (!isset($qreq->display))
                return new JsonResult(400, "Parameter error.");
            $base_display = "";
            if ($report === "pl")
                $base_display = $user->conf->review_form()->default_display();
            $display = simplify_whitespace($qreq->display);
            if ($display === $base_display)
                $user->conf->save_setting("{$report}display_default", null);
            else
                $user->conf->save_setting("{$report}display_default", 1, $display);
        }
        $s1 = new PaperSearch($user, get($qreq, "q", "NONE"));
        $l1 = new PaperList($s1, ["sort" => get($qreq, "sort", true), "report" => $report]);
        $s2 = new PaperSearch($user, "NONE");
        $l2 = new PaperList($s2, ["sort" => true, "report" => $report, "no_session_display" => true]);
        return new JsonResult(["ok" => true, "report" => $report, "display_current" => $l1->display("s"), "display_default" => $l2->display("s")]);
    }
}
