<?php
// listactions/la_getallrevpref.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAllRevpref_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $texts = [];
        $pcm = $user->conf->pc_members();
        $has_conflict = $has_expertise = $has_topic_score = false;
        foreach ($ssel->paper_set($user, ["allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1]) as $prow) {
            if (!$user->allow_administer($prow)) {
                continue;
            }
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->preference($p);
                $cflt = $conflicts[$cid] ?? null;
                $is_cflt = $cflt && $cflt->is_conflicted();
                $tv = $prow->topicIds !== "" ? $prow->topic_interest_score($p) : 0;
                if ($pref[0] !== 0 || $pref[1] !== null || $is_cflt || $tv) {
                    $texts[] = array("paper" => $prow->paperId, "title" => $prow->title, "first" => $p->firstName, "last" => $p->lastName, "email" => $p->email,
                                "preference" => $pref[0] ? : "",
                                "expertise" => unparse_expertise($pref[1]),
                                "topic_score" => $tv ? : "",
                                "conflict" => ($is_cflt ? "conflict" : ""));
                    $has_conflict = $has_conflict || $is_cflt;
                    $has_expertise = $has_expertise || $pref[1] !== null;
                    $has_topic_score = $has_topic_score || $tv;
                }
            }
        }

        $headers = ["paper", "title", "first", "last", "email", "preference"];
        if ($has_expertise) {
            $headers[] = "expertise";
        }
        if ($has_topic_score) {
            $headers[] = "topic_score";
        }
        if ($has_conflict) {
            $headers[] = "conflict";
        }
        return $user->conf->make_csvg("allprefs")->select($headers)->append($texts);
    }
}
