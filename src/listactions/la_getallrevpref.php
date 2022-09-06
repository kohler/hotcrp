<?php
// listactions/la_getallrevpref.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAllRevpref_ListAction extends ListAction {
    /** @var bool */
    private $has_conflict;
    /** @var bool */
    private $has_expertise;
    /** @var bool */
    private $has_topic_score;
    /** @var array<int,string> */
    private $titles;
    /** @var list<int|float|bool> */
    private $pupec;

    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }

    /** @param Contact $user
     * @param SearchSelection $ssel */
    private function prepare_pupec($user, $ssel) {
        $pcm = $user->conf->pc_members();
        $this->has_conflict = $this->has_expertise = $this->has_topic_score = false;
        $this->titles = $this->pupec = [];
        foreach ($ssel->paper_set($user, ["allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1]) as $prow) {
            if (!$user->allow_administer($prow)) {
                continue;
            }
            $this->titles[$prow->paperId] = $prow->title;
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->preference($p);
                $cflt = $conflicts[$cid] ?? null;
                $is_cflt = $cflt && $cflt->is_conflicted();
                $ts = $prow->topicIds !== "" ? $prow->topic_interest_score($p) : 0;
                if ($pref[0] !== 0 || $pref[1] !== null || $is_cflt || $ts !== 0) {
                    array_push($this->pupec, $prow->paperId, $cid, $pref[0], $pref[1], $ts, $is_cflt);
                }
                if ($is_cflt) {
                    $this->has_conflict = true;
                }
                if ($pref[1] !== null) {
                    $this->has_expertise = true;
                }
                if ($ts !== 0) {
                    $this->has_topic_score = true;
                }
            }
        }
    }

    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        // Reduce memory requirements with two passes
        $this->prepare_pupec($user, $ssel);

        $headers = ["paper", "title", "first", "last", "email", "preference"];
        if ($this->has_expertise) {
            $headers[] = "expertise";
        }
        if ($this->has_topic_score) {
            $headers[] = "topic_score";
        }
        if ($this->has_conflict) {
            $headers[] = "conflict";
        }
        $csvg = $user->conf->make_csvg("allprefs")->set_header($headers);
        $n = count($this->pupec);
        $pcm = $user->conf->pc_members();
        for ($i = 0; $i !== $n; $i += 6) {
            list($pid, $uid, $pref, $exp, $ts, $cflt) = array_slice($this->pupec, $i, 6);
            $pc = $pcm[$uid];
            $l = [$pid, $this->titles[$pid], $pc->firstName, $pc->lastName, $pc->email, $pref ? : ""];
            if ($this->has_expertise) {
                $l[] = unparse_expertise($exp);
            }
            if ($this->has_topic_score) {
                $l[] = $ts ? : "";
            }
            if ($this->has_conflict) {
                $l[] = $cflt ? "conflict" : "";
            }
            $csvg->add_row($l);
        }
        return $csvg;
    }
}
