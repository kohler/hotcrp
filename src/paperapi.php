<?php
// paperapi.php -- HotCRP paper-related API calls
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperApi {
    static function setdecision_api(Contact $user, $qreq, $prow) {
        if (!$user->can_set_decision($prow))
            return ["ok" => false, "error" => "You can’t set the decision for paper #$prow->paperId."];
        $dnum = cvtint($qreq->decision);
        $decs = $user->conf->decision_map();
        if (!isset($decs[$dnum]))
            return ["ok" => false, "error" => "Bad decision value."];
        $result = $user->conf->qe("update Paper set outcome=? where paperId=?", $dnum, $prow->paperId);
        if ($result && ($dnum > 0 || $prow->outcome > 0))
            $user->conf->update_paperacc_setting($dnum > 0);
        Dbl::free($result);
        if ($result)
            return ["ok" => true, "result" => htmlspecialchars($decs[$dnum])];
        else
            return ["ok" => false];
    }

    private static function set_paper_pc_api(Contact $user, $qreq, $prow, $type) {
        // canonicalize $value
        $value = $qreq->$type;
        $pc = null;
        if ($value === "0" || $value === 0 || $value === "none")
            $pc = 0;
        else if (is_string($value))
            $pc = $user->conf->pc_member_by_email($value);
        if (!$pc && $pc !== 0)
            return ["ok" => false, "error" => "No such PC member “" . htmlspecialchars($value) . "”."];

        if ($type == "manager" ? $user->privChair : $user->can_administer($prow)) {
            if (!$pc || ($pc->isPC && $pc->can_accept_review_assignment($prow))) {
                $user->assign_paper_pc($prow, $type, $pc);
                $j = ["ok" => true, "result" => $pc ? $user->name_html_for($pc) : "None"];
                if ($user->can_view_reviewer_tags($prow))
                    $j["color_classes"] = $pc ? $pc->viewable_color_classes($user) : "";
                return $j;
            } else
                return ["ok" => false, "error" => Text::user_html($pc) . " can’t be the $type for paper #{$prow->paperId}."];
        } else
            return ["ok" => false, "error" => "You don’t have permission to set the $type for paper #{$prow->paperId}."];
    }

    static function setlead_api(Contact $user, $qreq, $prow) {
        return self::set_paper_pc_api($user, $qreq, $prow, "lead");
    }

    static function setshepherd_api(Contact $user, $qreq, $prow) {
        return self::set_paper_pc_api($user, $qreq, $prow, "shepherd");
    }

    static function setmanager_api(Contact $user, $qreq, $prow) {
        return self::set_paper_pc_api($user, $qreq, $prow, "manager");
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
        json_exit(["ok" => $treport->ok, "response" => $response], true);
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
        $assigner = new AssignmentSet($user, $user->is_admin_force());
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
            json_exit($taginfo, true);
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
            json_exit(["ok" => true, "p" => (object) $p]);
        } else
            json_exit(["ok" => false, "error" => $error], true);
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
                $j["anno"][] = TagInfo::unparse_anno_json($oa);
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
                $q[] = "update PaperTagAnno set heading=? where tag=? and annoId=?";
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
            while (($result = $mresult->next()))
                Dbl::free($result);
        }
        // return results
        self::taganno_api($user, $qreq, $prow);
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

    static function get_reviewer(Contact $user, $qreq, $prow) {
        $reviewer = $user;
        if ($qreq->reviewer) {
            if (ctype_digit($qreq->reviewer))
                $reviewer = $user->conf->user_by_id($qreq->reviewer);
            else
                $reviewer = $user->conf->user_by_email($qreq->reviewer);
            if (!$reviewer)
                json_exit(["ok" => false, "error" => "No such user."]);
            else if ($reviewer->contactId !== $user->contactId
                     && ($prow ? !$user->can_administer($prow, $qreq->forceShow) : !$user->privChair))
                json_exit(["ok" => false, "error" => "Permission error."]);
        }
        return $reviewer;
    }

    static function setpref_api(Contact $user, $qreq, $prow) {
        $reviewer = self::get_reviewer($user, $qreq, $prow);
        if (($v = parse_preference($qreq->pref))) {
            if (PaperActions::save_review_preferences([[$prow->paperId, $reviewer->contactId, $v[0], $v[1]]]))
                $j = ["ok" => true, "response" => "Saved"];
            else
                $j = ["ok" => false];
            $j["value"] = unparse_preference($v);
        } else
            $j = ["ok" => false, "error" => "Bad preference"];
        return $j;
    }

    static function checkformat_api(Contact $user, $qreq, $prow) {
        $dtype = cvtint($qreq->dt, 0);
        $opt = $user->conf->paper_opts->find_document($dtype);
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
        if ($qreq->f == "authors") {
            $full = (int) $qreq->aufull;
            displayOptionsSet("pldisplay", "aufull", $full);
        }
        $reviewer = null;
        if ($qreq->reviewer && $user->privChair && $user->email !== $qreq->reviewer) {
            $reviewer = $user->conf->user_by_email($qreq->reviewer);
            unset($qreq->reviewer);
        }
        if (!isset($qreq->q) && $prow) {
            $qreq->t = $prow->timeSubmitted > 0 ? "s" : "all";
            $qreq->q = $prow->paperId;
        } else if (!isset($qreq->q))
            $qreq->q = "";
        $search = new PaperSearch($user, $qreq, $reviewer);
        $pl = new PaperList($search, ["reviewer" => $reviewer]);
        $response = $pl->ajaxColumn($qreq->f);
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
            || !($rr = $user->conf->reviewRow($qreq->r))
            || $rr->paperId != $prow->paperId)
            return ["ok" => false, "error" => "No such review."];
        if (!$user->can_administer($prow))
            return ["ok" => false, "error" => "Permission error."];
        $rname = trim((string) $qreq->round);
        $round = $user->conf->sanitize_round_name($rname);
        if ($round === false)
            return ["ok" => false, "error" => Conf::round_name_error($rname)];
        $rnum = $user->conf->round_number($round, true);
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
}
