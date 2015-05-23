<?php
// autoassigner.php -- HotCRP helper classes for autoassignment
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Autoassigner {
    private $pcm;
    private $assignments;
    private $load = array();
    private $prefs;
    private $pref_groups;
    private $use_mcmf = false;

    const PMIN = -1000000;
    const PNOASSIGN = -1000001;
    const PASSIGNED = -1000002;

    const COSTPERPAPER = 100;

    public function __construct() {
        $this->pcm = pcMembers();
        $this->ass = array("paper,action,email,round");
    }

    public function select_pc($pcids) {
        $this->pcm = array();
        $pcids = array_flip($pcids);
        foreach (pcMembers() as $cid => $pc)
            if (isset($pcids[$cid]))
                $this->pcm[$cid] = $pc;
        return count($this->pcm);
    }

    public function set_mcmf($use_mcmf) {
        $this->use_mcmf = $use_mcmf;
    }

    private function run_prefconflict($papertype) {
        global $Conf, $papersel, $assignprefs;
        $papers = array_fill_keys($papersel, 1);
        $result = $Conf->qe($Conf->preferenceConflictQuery($papertype, ""));
        while (($row = edb_row($result))) {
            if (!@$papers[$row[0]] || !@$this->pcm[$row[1]])
                continue;
            $this->ass[] = "$row[0],conflict," . $this->pcm[$row[1]]->email;
            $assignprefs["$row[0]:$row[1]"] = $row[2];
        }
        Dbl::free($result);
    }

    private function run_clear($reviewtype) {
        global $Conf, $papersel, $assignprefs;
        $papers = array_fill_keys($papersel, 1);
        if ($reviewtype == REVIEW_PRIMARY
            || $reviewtype == REVIEW_SECONDARY
            || $reviewtype == REVIEW_PC) {
            $q = "select paperId, contactId from PaperReview where reviewType=" . $reviewtype;
            $action = "noreview";
        } else if ($reviewtype === "conflict") {
            $q = "select paperId, contactId from PaperConflict where conflictType>0 and conflictType<" . CONFLICT_AUTHOR;
            $action = "noconflict";
        } else if ($reviewtype === "lead" || $reviewtype === "shepherd") {
            $q = "select paperId, {$reviewtype}ContactId from Paper where {$reviewtype}ContactId!=0";
            $action = "no" . $reviewtype;
        } else
            return false;
        $result = $Conf->qe($q);
        while (($row = edb_row($result))) {
            if (!@$papers[$row[0]] || !@$this->pcm[$row[1]])
                continue;
            $this->ass[] = "$row[0],$action," . $this->pcm[$row[1]]->email;
            $assignprefs["$row[0]:$row[1]"] = "*";
        }
        Dbl::free($result);
    }

    public function balance_reviews($reviewtype) {
        $q = "select contactId, count(reviewId) from PaperReview where contactId ?a";
        if ($reviewtype)
            $q .= " and reviewType={$reviewtype}";
        $result = Dbl::qe($q . " group by contactId", array_keys($this->pcm));
        while (($row = edb_row($result)))
            $this->load[$row[0]] = (int) $row[1];
        Dbl::free($result);
    }

    public function balance_paperpc($action) {
        global $papersel;
        $q = "select {$action}ContactId, count(paperId) from Paper where paperId ?A group by {$action}ContactId";
        $result = Dbl::qe($q, $papersel);
        while (($row = edb_row($result)))
            $this->load[$row[0]] = (int) $row[1];
        Dbl::free($result);
    }

    public function preferences_review() {
        global $Conf, $assignprefs, $papersel, $badpairs;
        $this->prefs = array();
        foreach ($this->pcm as $cid => $p)
            $this->prefs[$cid] = array();

        $query = "select Paper.paperId, ? contactId,
            coalesce(PaperConflict.conflictType, 0) as conflictType,
            coalesce(PaperReviewPreference.preference, 0) as preference,
            coalesce(PaperReview.reviewType, 0) as myReviewType,
            coalesce(PaperReview.reviewSubmitted, 0) as myReviewSubmitted,
            Paper.outcome,
            topicInterestScore,
            coalesce(PRR.contactId, 0) as refused,
            Paper.managerContactId
        from Paper
        left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
        left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=?)
        left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
        left join (select paperId,
                   sum(" . $Conf->query_topic_interest_score() . ") as topicInterestScore
               from PaperTopic
               join TopicInterest on (TopicInterest.topicId=PaperTopic.topicId and TopicInterest.contactId=?)
               where paperId ?a
               group by paperId) as PaperTopics on (PaperTopics.paperId=Paper.paperId)
        left join PaperReviewRefused PRR on (PRR.paperId=Paper.paperId and PRR.contactId=?)
        where Paper.paperId ?a
        group by Paper.paperId";

        foreach ($this->pcm as $cid => $p) {
            $result = Dbl::qe($query, $cid, $cid, $cid, $cid, $cid, $papersel, $cid, $papersel);

            while (($row = PaperInfo::fetch($result, true))) {
                $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
                if ($row->myReviewType > 0)
                    $pref = self::PASSIGNED;
                else if ($row->conflictType > 0 || $row->refused > 0
                         || !$p->can_accept_review_assignment($row))
                    $pref = self::PNOASSIGN;
                else
                    $pref = max($row->preference, -1000) + ($row->topicInterestScore / 100);
                $this->prefs[$row->contactId][$row->paperId] = $pref;
            }

            Dbl::free($result);
            set_time_limit(30);
        }
        $this->make_pref_groups();

        // need to populate review assignments for badpairs not in `pcm`
        foreach ($badpairs as $cid => $x)
            if (!isset($this->pcm[$cid])) {
                $result = Dbl::qe("select paperId from PaperReview where contactId=? and paperId ?a", $cid, $papersel);
                while (($row = edb_row($result)))
                    $this->prefs[$cid][$row[0]] = self::PASSIGNED;
                Dbl::free($result);
            }

        // mark badpairs as noassign
        foreach ($badpairs as $cid => $bp)
            foreach ($papersel as $pid) {
                if ($this->prefs[$cid][$pid] < self::PMIN)
                    continue;
                foreach ($bp as $cid2 => $x)
                    if ($this->prefs[$cid2][$pid] == self::PASSIGNED)
                        $this->prefs[$cid][$pid] = self::PNOASSIGN;
            }
    }

    public function preferences_paperpc($scoreinfo) {
        global $Conf, $assignprefs, $papersel, $badpairs;
        $this->prefs = array();
        foreach ($this->pcm as $pcid => $p)
            $this->prefs[$pcid] = array();

        $all_fields = ReviewForm::all_fields();
        $scoredir = 1;
        if ($scoreinfo === "x")
            $score = "1";
        else if ((substr($scoreinfo, 0, 1) === "-"
                  || substr($scoreinfo, 0, 1) === "+")
                 && @$all_fields[substr($scoreinfo, 1)]) {
            $score = "PaperReview." . substr($scoreinfo, 1);
            $scoredir = substr($scoreinfo, 0, 1) === "-" ? -1 : 1;
        } else
            $score = "PaperReview.overAllMerit";

        $query = "select Paper.paperId, ? contactId,
            coalesce(PaperConflict.conflictType, 0) as conflictType,
            coalesce(PaperReview.reviewType, 0) as myReviewType,
            coalesce(PaperReview.reviewSubmitted, 0) as myReviewSubmitted,
            coalesce($score, 0) as reviewScore,
            Paper.outcome,
            Paper.managerContactId
        from Paper
        left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
        left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
        where Paper.paperId ?a
        group by Paper.paperId";

        foreach ($this->pcm as $cid => $p) {
            $result = Dbl::qe($query, $cid, $cid, $cid, $papersel);

            // First, collect score extremes
            $scoreextreme = array();
            $rows = array();
            while (($row = edb_orow($result))) {
                $assignprefs["$row->paperId:$row->contactId"] = $row->preference;
                if ($row->conflictType > 0
                    || $row->myReviewType == 0
                    || $row->myReviewSubmitted == 0
                    || $row->reviewScore == 0)
                    $this->prefs[$row->contactId][$row->paperId] = self::PNOASSIGN;
                else {
                    if (!isset($scoreextreme[$row->paperId])
                        || $scoredir * $row->reviewScore > $scoredir * $scoreextreme[$row->paperId])
                        $scoreextreme[$row->paperId] = $row->reviewScore;
                    $rows[] = $row;
                }
            }
            // Then, collect preferences; ignore score differences farther
            // than 1 score away from the relevant extreme
            foreach ($rows as $row) {
                $scoredifference = $scoredir * ($row->reviewScore - $scoreextreme[$row->paperId]);
                if ($scoredifference >= -1)
                    $this->prefs[$row->contactId][$row->paperId] = max($scoredifference * 1001 + max(min($row->preference, 1000), -1000) + ($row->topicInterestScore / 100), self::PMIN);
            }
            $badpairs = array(); // bad pairs only relevant for reviews,
                                 // not discussion leads or shepherds
            unset($rows);        // don't need the memory any more

            Dbl::free($result);
            set_time_limit(30);
        }
        $this->make_pref_groups();
    }

    private function make_pref_groups() {
        $this->pref_groups = array();
        foreach ($this->pcm as $cid => $p) {
            arsort($this->prefs[$cid]);
            $last_group = null;
            $this->pref_groups[$cid] = array();
            foreach ($this->prefs[$cid] as $pid => $pref)
                if (!$last_group || $pref != $last_group->pref) {
                    $last_group = (object) array("pref" => $pref, "pids" => array($pid));
                    $this->pref_groups[$cid][] = $last_group;
                } else
                    $last_group->pids[] = $pid;
            reset($this->pref_groups[$cid]);
        }
    }

    private function make_assignment($action, $round, $cid, $pid, &$papers) {
        global $badpairs;
        $this->ass[] = "$pid,$action," . $this->pcm[$cid]->email . $round;
        $this->prefs[$cid][$pid] = self::PASSIGNED;
        $papers[$pid]--;
        $this->load[$cid]++;
        if (isset($badpairs[$cid]))
            foreach ($badpairs[$cid] as $cid2 => $x)
                if ($this->prefs[$cid2][$pid] >= self::PMIN)
                    $this->prefs[$cid2][$pid] = self::PNOASSIGN;
    }

    private function assign_randomly(&$papers, $action, $round, $nperpc) {
        foreach ($this->pcm as $cid => $p)
            $this->load[$cid] = (int) @$this->load[$cid];
        $pref_unhappiness = $pref_dist = $pref_nextdist = array_fill_keys(array_keys($this->pcm), 0);
        $pcids = array_keys($this->pcm);
        $progress = false;
        while (count($this->pcm)) {
            // choose a pc member at random, equalizing load
            $pc = null;
            foreach ($this->pcm as $pcx => $pcxval)
                if ($pc == null
                    || $this->load[$pcx] < $this->load[$pc]
                    || ($this->load[$pcx] == $this->load[$pc]
                        && $pref_unhappiness[$pcx] > $pref_unhappiness[$pc])) {
                    $numminpc = 0;
                    $pc = $pcx;
                } else if ($this->load[$pcx] == $this->load[$pc]
                           && $pref_unhappiness[$pcx] == $pref_unhappiness[$pc]) {
                    $numminpc++;
                    if (mt_rand(0, $numminpc) == 0)
                        $pc = $pcx;
                }

            // traverse preferences in descending order until encountering an
            // assignable paper
            $pg = null;
            while ($this->pref_groups[$pc]
                   && ($pg = current($this->pref_groups[$pc]))) {
                // skip if no papers left
                if (!count($pg->pids)) {
                    next($this->pref_groups[$pc]);
                    $pref_dist[$pc] = $pref_nextdist[$pc];
                    continue;
                }
                // pick a random paper at current preference level
                $pididx = mt_rand(0, count($pg->pids) - 1);
                $pid = $pg->pids[$pididx];
                array_splice($pg->pids, $pididx, 1);
                // skip if not assignable
                if (!isset($papers[$pid]) || $this->prefs[$pc][$pid] < self::PMIN
                    || $papers[$pid] <= 0)
                    continue;
                // make assignment
                $this->make_assignment($action, $round, $pc, $pid, $papers);
                $pref_unhappiness[$pc] += $pref_dist[$pc];
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$pg || $this->load[$pc] === $nperpc)
                unset($this->pcm[$pc]);
        }
    }

    private function assign_mcmf_once(&$papers, $action, $round, $nperpc) {
        global $Conf, $badpairs;
        $m = new MinCostMaxFlow;
        $papers = array_filter($papers, function ($ct) { return $ct > 0; });
        // paper nodes
        $nass = 0;
        foreach ($papers as $pid => $ct) {
            $m->add_node("p$pid", "p");
            $m->add_edge("p$pid", ".sink", $ct, 0);
            $nass += $ct;
        }
        // user nodes
        $assperpc = ceil($nass / count($this->pcm));
        $minload = $this->load ? min($this->load) : 0;
        $maxload = ($this->load ? max($this->load) : 0) + $assperpc;
        foreach ($this->pcm as $cid => $p) {
            $m->add_node("u$cid", "u");
            if ($nperpc)
                $m->add_edge(".source", "u$cid", $nperpc, 0);
            else {
                for ($l = (int) @$this->load[$cid]; $l < $maxload; ++$l)
                    $m->add_edge(".source", "u$cid", 1, self::COSTPERPAPER * ($l - $minload));
            }
        }
        // cost determination
        $cost = array();
        foreach ($this->pcm as $cid => $x) {
            foreach ($this->pref_groups[$cid] as $pgi => $pg)
                foreach ($pg->pids as $pid)
                    $cost[$cid][$pid] = $pgi;
        }
        // figure out badpairs class for each user
        $bpclass = array();
        foreach ($badpairs as $cid => $bp)
            $bpclass[$cid] = $cid;
        $done = false;
        while (!$done) {
            $done = true;
            foreach ($badpairs as $ocid => $bp) {
                foreach ($bp as $cid => $x)
                    if ($bpclass[$ocid] > $bpclass[$cid]) {
                        $bpclass[$ocid] = $bpclass[$cid];
                        $done = false;
                    }
            }
        }
        // paper <-> contact map
        foreach ($papers as $pid => $ct)
            foreach ($this->pcm as $cid => $p) {
                if ((int) @$this->prefs[$cid][$pid] < self::PMIN)
                    continue;
                if (isset($bpclass[$cid]) && $bpclass[$cid] == $cid) {
                    $m->add_node("b{$pid}.$cid", "b");
                    $x = "b{$pid}.$cid";
                    $m->add_edge($x, "p$pid", 1, 0);
                } else if (isset($bpclass[$cid]))
                    $x = "b{$pid}." . $bpclass[$cid];
                else
                    $x = "p$pid";
                $m->add_edge("u$cid", $x, 1, $cost[$cid][$pid]);
            }
        // run MCMF
        $m->run();
        // make assignments
        $nassigned = 0;
        foreach ($this->pcm as $cid => $p) {
            foreach ($m->reachable("u$cid", "p") as $v) {
                $this->make_assignment($action, $round, $cid,
                                       substr($v->name, 1), $papers);
                ++$nassigned;
            }
        }
        return $nassigned;
    }

    private function assign_mcmf(&$papers, $action, $round, $nperpc) {
        while ($this->assign_mcmf_once($papers, $action, $round, $nperpc)) {
            $nmissing = 0;
            foreach ($papers as $pid => $ct)
                if ($ct > 0)
                    $nmissing += $ct;
            $navailable = 0;
            if ($nperpc) {
                foreach ($this->pcm as $cid => $p)
                    if ($this->load[$cid] < $nperpc)
                        $navailable += $nperpc - $load;
            }
            if ($nmissing == 0 || $navailable == 0)
                break;
        }
    }

    private function assign_method(&$papers, $action, $round, $nperpc) {
        if ($this->use_mcmf)
            $this->assign_mcmf($papers, $action, $round, $nperpc);
        else
            $this->assign_randomly($papers, $action, $round, $nperpc);
    }


    private function check_missing_assignments(&$papers, $action) {
        global $Conf;
        ksort($papers);
        $badpids = array();
        foreach ($papers as $pid => $n)
            if ($n > 0)
                $badpids[] = $pid;
        if (!count($badpids))
            return;
        $b = array();
        $pidx = join("+", $badpids);
        foreach ($badpids as $pid)
            $b[] = "<a href='" . hoturl("assign", "p=$pid&amp;ls=$pidx") . "'>$pid</a>";
        $x = "";
        if ($action === "rev" || $action === "revadd")
            $x = ", possibly because of conflicts or previously declined reviews in the PC members you selected";
        else
            $x = ", possibly because the selected PC members didn’t review these papers";
        $y = (count($b) > 1 ? " (<a class='nowrap' href='" . hoturl("search", "q=$pidx") . "'>list them</a>)" : "");
        $Conf->warnMsg("I wasn’t able to complete the assignment$x.  The following papers got fewer than the required number of assignments: " . join(", ", $b) . $y . ".");
    }

    public function run_paperpc($action) {
        global $Conf, $papersel, $assignprefs;
        if (@$_REQUEST["balance"] && $_REQUEST["balance"] !== "new")
            $this->balance_paperpc($action);
        $this->preferences_paperpc(@$_REQUEST["{$action}score"]);
        $papers = array_fill_keys($papersel, 0);
        $result = $Conf->qe("select paperId from Paper where {$action}ContactId=0");
        while (($row = edb_row($result)))
            if (isset($papers[$row[0]]))
                $papers[$row[0]] = 1;
        Dbl::free($result);
        $this->assign_method($papers, $action, "", null);
        $this->check_missing_assignments($papers, $action);
    }

    private function analyze_reviewtype($reviewtype, $round) {
        if ($reviewtype == REVIEW_PRIMARY)
            $action = "primary";
        else if ($reviewtype == REVIEW_SECONDARY)
            $action = "secondary";
        else
            $action = "pcreview";
        return array($action, $round ? ",$round" : "");
    }

    public function run_reviews_per_pc($reviewtype, $nass) {
        global $Conf, $papersel;
        $this->preferences_review();
        $papers = array_fill_keys($papersel, ceil((count($this->pcm) * ($nass + 2)) / count($papersel)));
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, $nass);
    }

    public function run_more_reviews($reviewtype, $round, $nass) {
        global $Conf, $papersel;
        if (@$_REQUEST["balance"] && $_REQUEST["balance"] !== "new")
            $this->balance_reviews($reviewtype);
        $this->preferences_review();
        $papers = array_fill_keys($papersel, $nass);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "revadd");
    }

    public function run_ensure_reviews($reviewtype, $round, $nass) {
        global $Conf, $papersel, $assignprefs;
        if (@$_REQUEST["balance"] && $_REQUEST["balance"] !== "new")
            $this->balance_reviews($reviewtype);
        $this->preferences_review();
        $papers = array_fill_keys($papersel, $nass);
        $result = Dbl::qe("select paperId, count(reviewId) from PaperReview where reviewType={$reviewtype} group by paperId");
        while (($row = edb_row($result)))
            if (isset($papers[$row[0]]))
                $papers[$row[0]] = max($nass - $row[1], 0);
        Dbl::free($result);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "rev");
    }

    public function assignments() {
        return count($this->ass) > 1 ? $this->ass : null;
    }
}
