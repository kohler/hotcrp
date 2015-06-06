<?php
// autoassigner.php -- HotCRP helper classes for autoassignment
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Autoassigner {
    const BALANCE_NEW = 0;
    const BALANCE_ALL = 1;

    private $pcm;
    private $badpairs = array();
    private $papersel;
    private $ass;
    private $load = array();
    private $prefs;
    public $prefinfo = array();
    private $pref_groups;
    private $method = self::METHOD_MCMF;
    private $balance = self::BALANCE_NEW;
    private $progressf = array();
    private $mcmf_round_descriptor; // for use in MCMF progress
    private $mcmf_max_cost;
    private $ndesired;
    public $profile = array();

    const METHOD_MCMF = 0;
    const METHOD_RANDOM = 1;
    const METHOD_STUPID = 2;

    const PMIN = -1000000;
    const PNOASSIGN = -1000001;
    const POLDASSIGN = -1000002;
    const PNEWASSIGN = -1000003;

    const COSTPERPAPER = 100;

    public function __construct($papersel) {
        $this->pcm = pcMembers();
        $this->papersel = $papersel;
        $this->ass = array("paper,action,email,round");
    }

    public function select_pc($pcids) {
        $this->pcm = array();
        $pcids = array_flip($pcids);
        foreach (pcMembers() as $cid => $p)
            if (isset($pcids[$cid]))
                $this->pcm[$cid] = $p;
        return count($this->pcm);
    }

    public function avoid_pair_assignment($pc1, $pc2) {
        if (!is_numeric($pc1)) {
            $pc1 = pcByEmail($pc1);
            $pc1 = $pc1 ? $pc1->contactId : null;
        }
        if (!is_numeric($pc2)) {
            $pc2 = pcByEmail($pc2);
            $pc2 = $pc2 ? $pc2->contactId : null;
        }
        if ($pc1 && $pc2)
            $this->badpairs[$pc1][$pc2] = $this->badpairs[$pc2][$pc1] = true;
    }

    public function set_balance($balance) {
        $this->balance = $balance;
    }

    public function set_method($method) {
        $this->method = $method;
    }

    public function add_progressf($progressf) {
        $this->progressf[] = $progressf;
    }

    private function set_progress($status) {
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $status);
    }


    public function run_prefconflict($papertype) {
        global $Conf;
        $papers = array_fill_keys($this->papersel, 1);
        $result = Dbl::qe_raw($Conf->preferenceConflictQuery($papertype, ""));
        while (($row = edb_row($result))) {
            if (!@$papers[$row[0]] || !@$this->pcm[$row[1]])
                continue;
            $this->ass[] = "$row[0],conflict," . $this->pcm[$row[1]]->email;
            $this->prefinfo["$row[0] $row[1]"] = $row[2];
        }
        Dbl::free($result);
    }

    public function run_clear($reviewtype) {
        $papers = array_fill_keys($this->papersel, 1);
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
        $result = Dbl::qe_raw($q);
        while (($row = edb_row($result))) {
            if (!@$papers[$row[0]] || !@$this->pcm[$row[1]])
                continue;
            $this->ass[] = "$row[0],$action," . $this->pcm[$row[1]]->email;
        }
        Dbl::free($result);
    }


    private function balance_reviews($reviewtype) {
        $q = "select contactId, count(reviewId) from PaperReview where contactId ?a";
        if ($reviewtype)
            $q .= " and reviewType={$reviewtype}";
        $result = Dbl::qe($q . " group by contactId", array_keys($this->pcm));
        while (($row = edb_row($result)))
            $this->load[$row[0]] = (int) $row[1];
        Dbl::free($result);
    }

    private function balance_paperpc($action) {
        $q = "select {$action}ContactId, count(paperId) from Paper where paperId ?A group by {$action}ContactId";
        $result = Dbl::qe($q, $this->papersel);
        while (($row = edb_row($result)))
            $this->load[$row[0]] = (int) $row[1];
        Dbl::free($result);
    }

    private function preferences_review() {
        global $Conf;
        $time = microtime(true);
        $this->prefs = array();
        foreach ($this->pcm as $cid => $p)
            $this->prefs[$cid] = array();

        // first load topics
        $topicIds = PaperInfo::make_topic_map($this->papersel);

        $query = "select Paper.paperId, ? contactId,
            coalesce(PaperConflict.conflictType, 0) as conflictType,
            coalesce(PaperReviewPreference.preference, 0) as preference,
            PaperReviewPreference.expertise,
            coalesce(PaperReview.reviewType, 0) as myReviewType,
            coalesce(PaperReview.reviewSubmitted, 0) as myReviewSubmitted,
            Paper.outcome,
            coalesce(PRR.contactId, 0) as refused,
            Paper.managerContactId
        from Paper
        left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=?)
        left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=?)
        left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=?)
        left join PaperReviewRefused PRR on (PRR.paperId=Paper.paperId and PRR.contactId=?)
        where Paper.paperId ?a
        group by Paper.paperId";

        $nmade = 0;
        foreach ($this->pcm as $cid => $p) {
            $result = Dbl::qe($query, $cid, $cid, $cid, $cid, $cid, $this->papersel);

            while (($row = PaperInfo::fetch($result, true))) {
                $row->topicIds = @$topicIds[$row->paperId];
                $topic_interest_score = $row->topic_interest_score($p);
                $this->prefinfo["$row->paperId $row->contactId"] = array($row->preference, $row->expertise, $topic_interest_score);
                if ($row->myReviewType > 0)
                    $pref = self::POLDASSIGN;
                else if ($row->conflictType > 0 || $row->refused > 0
                         || !$p->can_accept_review_assignment($row))
                    $pref = self::PNOASSIGN;
                else
                    $pref = max($row->preference, -1000) + ($topic_interest_score / 100);
                $this->prefs[$row->contactId][$row->paperId] = $pref;
            }

            Dbl::free($result);
            ++$nmade;
            if ($nmade % 4 == 0)
                $this->set_progress(sprintf("Loading reviewer preferences (%d%% done)", (int) ($nmade * 100 / count($this->pcm) + 0.5)));
        }
        $this->make_pref_groups();

        // need to populate review assignments for badpairs not in `pcm`
        foreach ($this->badpairs as $cid => $x)
            if (!isset($this->pcm[$cid])) {
                $result = Dbl::qe("select paperId from PaperReview where contactId=? and paperId ?a", $cid, $this->papersel);
                while (($row = edb_row($result)))
                    $this->prefs[$cid][$row[0]] = self::POLDASSIGN;
                Dbl::free($result);
            }

        // mark badpairs as noassign
        foreach ($this->badpairs as $cid => $bp)
            foreach ($this->papersel as $pid) {
                if ($this->prefs[$cid][$pid] < self::PMIN)
                    continue;
                foreach ($bp as $cid2 => $x)
                    if ($this->prefs[$cid2][$pid] <= self::POLDASSIGN)
                        $this->prefs[$cid][$pid] = self::PNOASSIGN;
            }

        $this->profile["preferences"] = microtime(true) - $time;
    }

    private function preferences_paperpc($scoreinfo) {
        global $Conf;
        $time = microtime(true);
        $this->prefs = array();
        foreach ($this->pcm as $cid => $p)
            $this->prefs[$cid] = array();

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

        $nmade = 0;
        foreach ($this->pcm as $cid => $p) {
            $result = Dbl::qe($query, $cid, $cid, $cid, $this->papersel);

            // First, collect score extremes
            $scoreextreme = array();
            $rows = array();
            while (($row = edb_orow($result))) {
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
                    $this->prefs[$row->contactId][$row->paperId] = $scoredifference;
            }
            unset($rows);        // don't need the memory any more

            Dbl::free($result);
            ++$nmade;
            if ($nmade % 4 == 0)
                $this->set_progress(sprintf("Loading reviewer preferences (%d%% done)", (int) ($nmade * 100 / count($this->pcm) + 0.5)));
        }
        $this->make_pref_groups();

        $this->profile["preferences"] = microtime(true) - $time;
    }

    private function make_pref_groups() {
        $this->pref_groups = array();
        foreach ($this->pcm as $cid => $p) {
            arsort($this->prefs[$cid]);
            $last_group = null;
            $this->pref_groups[$cid] = array();
            foreach ($this->prefs[$cid] as $pid => $pref)
                if (!$last_group || $pref != $last_group->pref) {
                    if ($pref < self::PMIN)
                        break;
                    $last_group = (object) array("pref" => $pref, "pids" => array($pid));
                    $this->pref_groups[$cid][] = $last_group;
                } else
                    $last_group->pids[] = $pid;
            reset($this->pref_groups[$cid]);
        }
    }

    private function make_assignment($action, $round, $cid, $pid, &$papers) {
        $this->ass[] = "$pid,$action," . $this->pcm[$cid]->email . $round;
        $this->prefs[$cid][$pid] = self::PNEWASSIGN;
        $papers[$pid]--;
        @$this->load[$cid]++;
        if (isset($this->badpairs[$cid]))
            foreach ($this->badpairs[$cid] as $cid2 => $x)
                if ($this->prefs[$cid2][$pid] >= self::PMIN)
                    $this->prefs[$cid2][$pid] = self::PNOASSIGN;
    }

    private function action_takes_badpairs($action) {
        return $action !== "lead" && $action !== "shepherd";
    }

    private function assign_desired(&$papers, $nperpc) {
        if ($nperpc)
            return $nperpc * count($this->pcm);
        $n = 0;
        foreach ($papers as $ct)
            $n += $ct;
        return $n;
    }

    // This assignment function assigns without considering preferences.
    private function assign_stupidly(&$papers, $action, $round, $nperpc) {
        foreach ($this->pcm as $cid => $p)
            $this->load[$cid] = (int) @$this->load[$cid];
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $pcm = $this->pcm;
        while (count($pcm)) {
            // choose a pc member at random, equalizing load
            $pc = null;
            foreach ($pcm as $pcx => $p)
                if ($pc === null
                    || $this->load[$pcx] < $this->load[$pc]) {
                    $numminpc = 0;
                    $pc = $pcx;
                } else if ($this->load[$pcx] == $this->load[$pc]) {
                    $numminpc++;
                    if (mt_rand(0, $numminpc) == 0)
                        $pc = $pcx;
                }

            // select a paper
            $apids = array_keys(array_filter($papers, function ($ct) { return $ct > 0; }));
            while (count($apids)) {
                $pididx = mt_rand(0, count($apids) - 1);
                $pid = $apids[$pididx];
                array_splice($apids, $pididx, 1);
                if ($this->prefs[$pc][$pid] < self::PMIN)
                    continue;
                // make assignment
                $this->make_assignment($action, $round, $pc, $pid, $papers);
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0)
                    $this->set_progress(sprintf("Making assignments stupidly (%d%% done)", (int) ($nmade * 100 / $ndesired + 0.5)));
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$apids || $this->load[$pc] === $nperpc)
                unset($pcm[$pc]);
        }
    }

    private function assign_randomly(&$papers, $action, $round, $nperpc) {
        foreach ($this->pcm as $cid => $p)
            $this->load[$cid] = (int) @$this->load[$cid];
        $pref_unhappiness = $pref_dist = array_fill_keys(array_keys($this->pcm), 0);
        $pcids = array_keys($this->pcm);
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $pcm = $this->pcm;
        while (count($pcm)) {
            // choose a pc member at random, equalizing load
            $pc = null;
            foreach ($pcm as $pcx => $p)
                if ($pc === null
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
                // create copy of pids for assignment
                if (@$pg->apids === null)
                    $pg->apids = $pg->pids;
                // skip if no papers left
                if (!count($pg->apids)) {
                    next($this->pref_groups[$pc]);
                    ++$pref_dist[$pc];
                    continue;
                }
                // pick a random paper at current preference level
                $pididx = mt_rand(0, count($pg->apids) - 1);
                $pid = $pg->apids[$pididx];
                array_splice($pg->apids, $pididx, 1);
                // skip if not assignable
                if (!isset($papers[$pid]) || $this->prefs[$pc][$pid] < self::PMIN
                    || $papers[$pid] <= 0)
                    continue;
                // make assignment
                $this->make_assignment($action, $round, $pc, $pid, $papers);
                $pref_unhappiness[$pc] += $pref_dist[$pc];
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0)
                    $this->set_progress(sprintf("Making assignments (%d%% done)", (int) ($nmade * 100 / $ndesired + 0.5)));
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$pg || $this->load[$pc] === $nperpc)
                unset($pcm[$pc]);
        }
    }

    public function mcmf_progress($mcmf, $what, $phaseno = 0, $nphases = 0) {
        if ($what <= MinCostMaxFlow::PMAXFLOW_DONE) {
            $n = max($mcmf->current_flow() - $mcmf->current_excess(), 0);
            $this->set_progress(sprintf("Preparing unoptimized assignment$this->mcmf_round_descriptor (%d%% done)", (int) ($n * 100 / $this->ndesired + 0.5)));
        } else {
            $x = array();
            $cost = $mcmf->current_cost();
            if (!$this->mcmf_max_cost)
                $this->mcmf_max_cost = $cost;
            else if ($cost < $this->mcmf_max_cost)
                $x[] = sprintf("%.1f%% better", ((int) (($this->mcmf_max_cost - $cost) * 1000 / $this->mcmf_max_cost + 0.5)) / 10);
            if ($mcmf->has_excess() && $x)
                $x[] = "but needs correction";
            $phasedescriptor = $nphases > 1 ? ", phase " . ($phaseno + 1) . "/" . $nphases : "";
            $this->set_progress("Optimizing assignment for preferences and balance"
                                . $this->mcmf_round_descriptor . $phasedescriptor
                                . ($x ? " (" . join(", ", $x) . ")" : ""));
        }
    }

    private function assign_mcmf_once(&$papers, $action, $round, $nperpc) {
        global $Conf;
        $m = new MinCostMaxFlow;
        $m->add_progressf(array($this, "mcmf_progress"));
        $papers = array_filter($papers, function ($ct) { return $ct > 0; });
        $this->ndesired = $this->assign_desired($papers, $nperpc);
        $this->mcmf_max_cost = null;
        $this->set_progress("Preparing assignment optimizer" . $this->mcmf_round_descriptor);
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
        if ($this->action_takes_badpairs($action)) {
            foreach ($this->badpairs as $cid => $bp)
                $bpclass[$cid] = $cid;
            $done = false;
            while (!$done) {
                $done = true;
                foreach ($this->badpairs as $ocid => $bp) {
                    foreach ($bp as $cid => $x)
                        if ($bpclass[$ocid] > $bpclass[$cid]) {
                            $bpclass[$ocid] = $bpclass[$cid];
                            $done = false;
                        }
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
        $m->shuffle();
        $m->run();
        // make assignments
        $this->set_progress("Completing assignment" . $this->mcmf_round_descriptor);
        $time = microtime(true);
        $nassigned = 0;
        foreach ($this->pcm as $cid => $p) {
            foreach ($m->reachable("u$cid", "p") as $v) {
                $this->make_assignment($action, $round, $cid,
                                       substr($v->name, 1), $papers);
                ++$nassigned;
            }
        }
        $m->clear(); // break circular refs
        $this->profile["maxflow"] = $m->maxflow_end_at - $m->maxflow_start_at;
        if ($m->mincost_start_at)
            $this->profile["mincost"] = $m->mincost_end_at - $m->mincost_start_at;
        $this->profile["traverse"] = microtime(true) - $time;
        return $nassigned;
    }

    private function assign_mcmf(&$papers, $action, $round, $nperpc) {
        $this->mcmf_round_descriptor = "";
        $mcmf_round = 1;
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
            ++$mcmf_round;
            $this->mcmf_round_descriptor = ", round $mcmf_round";
        }
    }

    private function assign_method(&$papers, $action, $round, $nperpc) {
        if ($this->method == self::METHOD_RANDOM)
            $this->assign_randomly($papers, $action, $round, $nperpc);
        else if ($this->method == self::METHOD_STUPID)
            $this->assign_stupidly($papers, $action, $round, $nperpc);
        else
            $this->assign_mcmf($papers, $action, $round, $nperpc);
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

    public function run_paperpc($action, $preference) {
        global $Conf;
        if ($this->balance !== self::BALANCE_NEW)
            $this->balance_paperpc($action);
        $this->preferences_paperpc($preference);
        $papers = array_fill_keys($this->papersel, 0);
        $result = Dbl::qe("select paperId from Paper where {$action}ContactId=0");
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
        $this->preferences_review();
        $papers = array_fill_keys($this->papersel, ceil((count($this->pcm) * ($nass + 2)) / count($this->papersel)));
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, $nass);
    }

    public function run_more_reviews($reviewtype, $round, $nass) {
        if ($this->balance !== self::BALANCE_NEW)
            $this->balance_reviews($reviewtype);
        $this->preferences_review();
        $papers = array_fill_keys($this->papersel, $nass);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "revadd");
    }

    public function run_ensure_reviews($reviewtype, $round, $nass) {
        global $Conf;
        if ($this->balance !== self::BALANCE_NEW)
            $this->balance_reviews($reviewtype);
        $this->preferences_review();
        $papers = array_fill_keys($this->papersel, $nass);
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

    public function pc_unhappiness() {
        if (!$this->prefs)
            return array();

        $ubypid = array();
        foreach ($this->pcm as $cid => $p) {
            $u = array();
            foreach ($this->pref_groups[$cid] as $i => $pg)
                foreach ($pg->pids as $pid)
                    $u[$pid] = $i;
            $ubypid[$cid] = $u;
        }

        $u = array_fill_keys(array_keys($this->pcm), 0);
        foreach ($this->prefs as $cid => $m) {
            foreach ($m as $pid => $pref)
                if ($pref === self::PNEWASSIGN)
                    $u[$cid] += $ubypid[$cid][$pid];
        }
        return $u;
    }
}
