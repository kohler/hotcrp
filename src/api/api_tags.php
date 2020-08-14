<?php
// api/api_tags.php -- HotCRP tags API call
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class Tags_API {
    /** @param ?PaperInfo $prow */
    static function tagreport(Contact $user, $prow) {
        $ret = (object) ["ok" => $user->can_view_tags($prow)];
        if ($prow) {
            $ret->pid = $prow->paperId;
        }
        $ret->tagreport = [];
        if (!$ret->ok) {
            return $ret;
        }
        if ($user->can_administer($prow)
            && stripos($prow->all_tags_text(), " perm:") !== false) {
            foreach (Tagger::split_unpack($prow->sorted_editable_tags($user)) as $ti) {
                if (strncasecmp($ti[0], "perm:", 5) === 0
                    && !$prow->conf->is_known_perm_tag($ti[0])) {
                    $ret->tagreport[] = (object) ["tag" => $ti[0], "status" => 1, "message" => "Unknown permission"];
                }
            }
        }
        if (($vt = $user->conf->tags()->filter("allotment"))) {
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
                if ($myvotes[$lbase] < $t->allotment) {
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 0, "message" => plural($t->allotment - $myvotes[$lbase], "vote") . " remaining", "search" => "editsort:-#~{$t->tag}"];
                } else if ($myvotes[$lbase] > $t->allotment) {
                    $ret->tagreport[] = (object) ["tag" => "~{$t->tag}", "status" => 1, "message" => plural($myvotes[$lbase] - $t->allotment, "overvote"), "search" => "editsort:-#~{$t->tag}"];
                }
            }
        }
        return $ret;
    }

    /** @param ?PaperInfo $prow */
    static function tagreport_api(Contact $user, $qreq, $prow) {
        return new JsonResult((array) self::tagreport($user, $prow));
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
        $error = join("<br>", $assigner->messages_html());
        $ok = $assigner->execute();

        // exit
        if ($ok && $prow) {
            $prow->load_tags();
            $taginfo = self::tagreport($user, $prow);
            $prow->add_tag_info_json($taginfo, $user);
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
            json_exit(["ok" => true, "result" => ""]);
        } else {
            json_exit(["ok" => true, "result" => '<span class="nw">' . join(',</span> <span class="nw">', $result) . '</span>']);
        }
    }
}
