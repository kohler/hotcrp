<?php
// api/api_tags.php -- HotCRP tags API call
// Copyright (c) 2008-2021 Eddie Kohler; see LICENSE.

class Tags_API {
    /** @param ?PaperInfo $prow */
    static function tagmessages(Contact $user, $prow) {
        $ret = (object) ["ok" => $user->can_view_tags($prow)];
        if ($prow) {
            $ret->pid = $prow->paperId;
        }
        if ($ret->ok
            && $prow
            && (stripos($prow->all_tags_text(), "perm:") !== false
                || $user->conf->tags()->has_allotment)) {
            $ret->message_list = self::paper_tagmessages($user, $prow);
        } else if ($ret->ok && !$prow) {
            $ret->message_list = self::generic_tagmessages($user);
        } else {
            $ret->message_list = [];
        }
        return $ret;
    }
    static private function paper_tagmessages(Contact $user, PaperInfo $prow) {
        $tagmap = $user->conf->tags();
        $want_perm = $user->can_administer($prow);
        $mypfx = $user->contactId . "~";
        $rep = $vt = [];
        foreach (Tagger::split_unpack($prow->sorted_editable_tags($user)) as $ti) {
            if (strncasecmp($ti[0], "perm:", 5) === 0
                && $want_perm) {
                if (!$prow->conf->is_known_perm_tag($ti[0])) {
                    $rep[] = (object) ["status" => 1, "message" => "#{$ti[0]}: Unknown permission."];
                } else if ($ti[1] != -1 && $ti[1] != 0) {
                    $rep[] = (object) ["status" => 1, "message" => "#{$ti[0]}#{$ti[1]}: Permission tags should have value 0 (allow) or -1 (deny)."];
                }
            }
            if (str_starts_with($ti[0], $mypfx)
                && ($dt = $tagmap->check(substr($ti[0], strlen($mypfx))))
                && $dt->allotment) {
                $vt[strtolower($ti[0])] = $dt;
            }
        }
        if (!empty($vt)) {
            $result = $user->conf->qe("select tag, sum(tagIndex) from PaperTag join Paper using (paperId) where timeSubmitted>0 and tag?a group by tag", array_keys($vt));
            while (($row = $result->fetch_row())) {
                $dt = $vt[strtolower($row[0])];
                if ((float) $row[1] > $dt->allotment) {
                    $rep[] = (object) ["status" => 1, "message" => "#~{$dt->tag}: Your vote total ({$row[1]}) is over the allotment ({$dt->allotment}).", "search" => "editsort:-#~{$dt->tag}"];
                }
            }
            Dbl::free($result);
        }
        return $rep;
    }
    static private function generic_tagmessages(Contact $user) {
        $mypfx = $user->contactId . "~";
        $rep = $vt = [];
        foreach ($user->conf->tags()->filter("allotment") as $dt) {
            $vt[$mypfx . strtolower($dt->tag)] = [$dt, 0.0];
        }
        if (!empty($vt)) {
            $result = $user->conf->qe("select tag, sum(tagIndex) from PaperTag join Paper using (paperId) where timeSubmitted>0 and tag?a group by tag", array_keys($vt));
            while (($row = $result->fetch_row())) {
                $vt[$row[0]][1] = (float) $row[1];
            }
            Dbl::free($result);
            foreach ($vt as $tv) {
                $dt = $tv[0];
                if ($tv[1] < $dt->allotment) {
                    $rep[] = (object) ["status" => 0, "message" => "#~{$dt->tag}: " . ($dt->allotment - $tv[1]) . " of " . plural($dt->allotment, "vote") . " remaining.", "search" => "editsort:-#~{$dt->tag}"];
                } else if ($tv[1] > $dt->allotment) {
                    $rep[] = (object) ["status" => 1, "message" => "#~{$dt->tag}: Your vote total ({$tv[1]}) is over the allotment ({$dt->allotment}).", "search" => "editsort:-#~{$dt->tag}"];
                }
            }
        }
        return $rep;
    }

    /** @param ?PaperInfo $prow */
    static function tagmessages_api(Contact $user, $qreq, $prow) {
        return new JsonResult((array) self::tagmessages($user, $prow));
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

    /** @param ?PaperInfo $prow */
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
                foreach (Tagger::split($qreq->tags) as $t)
                    $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            }
            foreach (Tagger::split((string) $qreq->addtags) as $t) {
                $x[] = "$prow->paperId,tag," . CsvGenerator::quote($t);
            }
            foreach (Tagger::split((string) $qreq->deltags) as $t) {
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
        $mlist = $assigner->message_list();
        $ok = $assigner->execute();

        // exit
        if ($ok && $prow) {
            $prow->load_tags();
            $taginfo = self::tagmessages($user, $prow);
            $prow->add_tag_info_json($taginfo, $user);
            $taginfo->message_list = self::combine_message_lists($mlist, $taginfo->message_list);
            $jr = new JsonResult($taginfo);
        } else if ($ok) {
            $p = [];
            if ($pids) {
                foreach ($user->paper_set(["paperId" => array_keys($pids)]) as $pr) {
                    $p[$pr->paperId] = (object) [];
                    $pr->add_tag_info_json($p[$pr->paperId], $user);
                }
            }
            $jr = new JsonResult(["ok" => true, "p" => (object) $p]);
        } else {
            $jr = new JsonResult(["ok" => false, "message_list" => $mlist]);
        }
        return $jr;
    }

    static function votereport_api(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $tagger = new Tagger($user);
        if (!($tag = $tagger->check($qreq->tag, Tagger::NOVALUE))) {
            return MessageItem::make_error_json($tagger->error_html());
        }
        if (!$user->can_view_peruser_tag($prow, $tag)) {
            return ["ok" => false, "error" => "Permission error."];
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
