<?php
// api/api_tags.php -- HotCRP tags API call
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Tags_API {
    /** @param ?PaperInfo $prow
     * @param ?array<string,true> $interest
     * @return TagMessageReport */
    static function tagmessages(Contact $user, $prow, $interest) {
        $tmr = new TagMessageReport;
        $tmr->ok = $user->can_view_tags($prow);
        if ($prow) {
            $tmr->pid = $prow->paperId;
        }
        $tmr->message_list = [];
        if ($tmr->ok
            && $user->conf->tags()->has(TagInfo::TF_ALLOTMENT)) {
            self::allotment_tagmessages($user, $tmr, $interest);
        }
        return $tmr;
    }
    /** @param Contact $user
     * @param TagMessageReport $tmr
     * @param ?array<string,true> $interest */
    static private function allotment_tagmessages($user, $tmr, $interest) {
        $pfx = "{$user->contactId}~";
        $allotments = [];
        foreach ($user->conf->tags()->sorted_entries_having(TagInfo::TF_ALLOTMENT) as $ti) {
            $ltag = $ti->ltag();
            if ($interest === null || isset($interest["{$pfx}{$ltag}"])) {
                $allotments["{$pfx}{$ltag}"] = [$ti, 0.0];
            }
        }
        if (empty($allotments)) {
            return;
        }
        $result = $user->conf->qe("select tag, sum(tagIndex) from PaperTag join Paper using (paperId) where timeSubmitted>0 and tag?a group by tag", array_keys($allotments));
        while (($row = $result->fetch_row())) {
            $allotments[strtolower($row[0])][1] = (float) $row[1];
        }
        Dbl::free($result);
        foreach ($allotments as $tv) {
            $t = $tv[0];
            $link = $user->conf->hoturl("search", ["q" => "editsort:-#~{$t->tag}"]);
            if ($tv[1] < $t->allotment) {
                $nleft = $t->allotment - $tv[1];
                $tmr->message_list[] = new MessageItem(null, "<5><a href=\"{$link}\">#~{$t->tag}</a>: " . plural($nleft, "vote") . " remaining", MessageSet::MARKED_NOTE);
            } else if ($tv[1] > $t->allotment) {
                $tmr->message_list[] = new MessageItem(null, "<5><a href=\"{$link}\">#~{$t->tag}</a>: Too many votes", 1);
                $tmr->message_list[] = new MessageItem(null, "<0>Your vote total, {$tv[1]}, is over the allotment, {$t->allotment}.", MessageSet::INFORM);
            }
        }
    }

    /** @param ?PaperInfo $prow
     * @return JsonResult */
    static function tagmessages_api(Contact $user, $qreq, $prow) {
        return new JsonResult(self::tagmessages($user, $prow, null)->jsonSerialize());
    }

    /** @param list<MessageItem> $ms1
     * @param list<MessageItem> $ms2
     * @return list<MessageItem> */
    static private function combine_message_lists($ms1, $ms2) {
        foreach ($ms2 as $mx2) {
            foreach ($ms1 as $mx1) {
                if ($mx1->message === $mx2->message) {
                    $mx2 = null;
                    break;
                }
            }
            if ($mx2) {
                $ms1[] = $mx2;
            }
        }
        return $ms1;
    }

    /** @param Qrequest $qreq
     * @param ?PaperInfo $prow
     * @return JsonResult */
    static function run(Contact $user, $qreq, $prow) {
        if ($prow && ($whyNot = $user->perm_view_paper($prow))) {
            return Conf::paper_error_json_result($whyNot);
        }
        if ($qreq->is_get() || $qreq->cancel) {
            if (!$prow) {
                return JsonResult::make_error(400, "<0>Missing parameter");
            }
            $taginfo = self::tagmessages($user, $prow, null);
            $prow->add_tag_info_json($taginfo, $user);
            return new JsonResult($taginfo);
        }

        // save tags using assigner
        $x = ["paper,action,tag"];
        $interestall = !$prow || isset($qreq->tags);
        if ($prow) {
            if (isset($qreq->tags)) {
                $x[] = "{$prow->paperId},tag,all#clear";
                foreach (Tagger::split($qreq->tags) as $t) {
                    $x[] = "{$prow->paperId},tag," . CsvGenerator::quote($t);
                }
            }
            foreach (Tagger::split((string) $qreq->addtags) as $t) {
                $x[] = "{$prow->paperId},tag," . CsvGenerator::quote($t);
            }
            foreach (Tagger::split((string) $qreq->deltags) as $t) {
                $x[] = "{$prow->paperId},tag," . CsvGenerator::quote($t . "#clear");
            }
        } else if (isset($qreq->tagassignment)) {
            $pid = -1;
            foreach (preg_split('/[\s,]+/', $qreq->tagassignment) as $w) {
                if ($w !== "" && ctype_digit($w)) {
                    $pid = intval($w);
                } else if ($w !== "" && $pid > 0) {
                    $x[] = "{$pid},tag," . CsvGenerator::quote($w);
                }
            }
        }

        $assigner = new AssignmentSet($user);
        if ($prow) {
            $assigner->enable_papers($prow);
        }
        $assigner->parse(join("\n", $x));
        $mlist = $assigner->message_list();
        $ok = $assigner->execute();

        // execute
        if ($ok && $prow) {
            $prow->load_tags();
            if ($interestall) {
                $interest = null;
            } else {
                $interest = [];
                foreach ($assigner->assignments() as $ai) {
                    if ($ai instanceof Tag_Assigner)
                        $interest[strtolower($ai->tag)] = true;
                }
            }
            $taginfo = self::tagmessages($user, $prow, $interest);
            $prow->add_tag_info_json($taginfo, $user);
            $taginfo->message_list = self::combine_message_lists($mlist, $taginfo->message_list);
            $jr = new JsonResult($taginfo);
        } else if ($ok) {
            $jr = new JsonResult([
                "ok" => true,
                "p" => Assign_API::assigned_paper_info($user, $assigner)
            ]);
        } else {
            $jr = new JsonResult(["ok" => false, "message_list" => $mlist]);
        }
        if ($qreq->search) {
            Search_API::apply_search($jr, $user, $qreq, $qreq->search);
        }
        return $jr;
    }

    static function votereport_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return JsonResult::make_error(400, $tagger->error_ftext());
        }
        if (!$user->can_view_peruser_tag($prow, $tag)) {
            return ["ok" => false, "error" => "Permission error"];
        }
        $votemap = [];
        preg_match_all('/ (\d+)~' . preg_quote($tag) . '#(\S+)/i', $prow->all_tags_text(), $m);
        $is_approval = $user->conf->tags()->is_approval($tag);
        $min_vote = $is_approval ? 0 : 0.001;
        for ($i = 0; $i != count($m[0]); ++$i) {
            if ($m[2][$i] >= $min_vote)
                $votemap[(int) $m[1][$i]] = $m[2][$i];
        }
        $user->ksort_cid_array($votemap);
        $result = [];
        foreach ($votemap as $k => $v) {
            if ($is_approval) {
                $result[] = $user->reviewer_html_for($k);
            } else {
                $result[] = $user->reviewer_html_for($k) . " ($v)";
            }
        }
        if (empty($result)) {
            return ["ok" => true, "result" => ""];
        } else {
            return ["ok" => true, "result" => '<span class="nw">' . join(',</span> <span class="nw">', $result) . '</span>'];
        }
    }
}
