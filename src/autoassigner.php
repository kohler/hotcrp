<?php
// autoassigner.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AutoassignerCosts implements JsonSerializable {
    public $assignment = 100;
    public $preference = 60;
    public $expertise_x = -200;
    public $expertise_y = -140;
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return get_object_vars($this);
    }
}

class AutoassignerElement {
    /** @var ?int */
    public $pref;
    /** @var ?int */
    public $exp;
    /** @var ?int */
    public $topicscore;
    /** @var null|int|float */
    public $cpref;
    /** @var null|int|float */
    public $cost;
    /** @var int */
    public $eass = 0;
}

class AutoassignerPrefGroup {
    /** @var int|float */
    public $cpref;
    /** @var list<int> */
    public $pids = [];
    /** @var ?list<int> */
    public $apids;
    /** @param int|float $cpref */
    function __construct($cpref) {
        $this->cpref = $cpref;
    }
}

class AutoassignerContact {
    /** @var int */
    public $cid;
    /** @var Contact */
    public $user;
    /** @var int */
    public $load = 0;
    /** @var int */
    public $unhappiness = 0;
    /** @var int */
    public $pref_dist = 0;
    /** @var list<AutoassignerPrefGroup> */
    public $pref_groups = [];
    /** @var ?list<int> */
    public $avoid_cid;
    /** @var int */
    public $avoid_class;
    /** @var ?list<int> */
    public $newass;
    /** @param Contact $user */
    function __construct(Contact $user) {
        assert($user->contactId > 0);
        $this->cid = $this->avoid_class = $user->contactId;
        $this->user = $user;
    }
}

class Autoassigner {
    /** @var Conf */
    public $conf;
    /** @var array<int,AutoassignerContact> */
    private $acs = [];
    /** @var list<array{int,int}> */
    private $avoid_pairs = [];
    /** @var list<int> */
    private $papersel;
    /** @var array<int,array<int,AutoassignerElement>> */
    private $ainfo = [];
    /** @var list<string> */
    private $ass = [];
    /** @var bool */
    private $has_pref_groups = false;
    /** @var int */
    private $method = self::METHOD_MCMF;
    /** @var int */
    private $balance = self::BALANCE_NEW;
    /** @var int */
    private $review_gadget = self::REVIEW_GADGET_DEFAULT;
    /** @var AutoassignerCosts */
    public $costs;
    private $progressf = [];
    /** @var ?MinCostMaxFlow */
    private $mcmf;
    /** @var ?string */
    private $mcmf_round_descriptor; // for use in MCMF progress
    /** @var ?string */
    private $mcmf_optimizing_for; // for use in MCMF progress
    /** @var ?float */
    private $mcmf_max_cost;
    /** @var ?int */
    private $ndesired;
    public $profile = ["maxflow" => 0, "mincost" => 0];

    const METHOD_MCMF = 0;
    const METHOD_RANDOM = 1;
    const METHOD_STUPID = 2;

    const BALANCE_NEW = 0;
    const BALANCE_ALL = 1;

    const REVIEW_GADGET_DEFAULT = 0;
    const REVIEW_GADGET_EXPERTISE = 1;

    const ENOASSIGN = 1;
    const EOTHERASSIGN = 2; // order matters
    const EOLDASSIGN = 3;
    const ENEWASSIGN = 4;

    /** @param list<int> $papersel */
    function __construct(Conf $conf, $papersel) {
        $this->conf = $conf;
        $this->select_pc(array_keys($this->conf->pc_members()));
        $this->papersel = $papersel;
        $this->costs = new AutoassignerCosts;
    }

    /** @param list<int> $pcids */
    function select_pc($pcids) {
        assert(empty($this->avoid_pairs));
        $this->acs = [];
        foreach ($pcids as $cid) {
            if (($p = $this->conf->pc_member_by_id($cid))) {
                $this->acs[$cid] = new AutoassignerContact($p);
            }
        }
    }

    /** @return list<int> */
    function selected_pc_ids() {
        return array_keys($this->acs);
    }

    /** @param int $cid1
     * @param int $cid2 */
    function avoid_pair_assignment($cid1, $cid2) {
        assert($cid1 > 0 && $cid2 > 0);
        if ($cid1 !== $cid2
            && !in_array([$cid1, $cid2], $this->avoid_pairs)) {
            $this->avoid_pairs[] = [$cid1, $cid2];
            $this->avoid_pairs[] = [$cid2, $cid1];
            if (($a1 = $this->acs[$cid1] ?? null)
                && ($a2 = $this->acs[$cid2] ?? null)) {
                $a1->avoid_cid[] = $cid2;
                $a1->avoid_class = min($a1->avoid_class, $cid2);
                $a2->avoid_cid[] = $cid1;
                $a2->avoid_class = min($a2->avoid_class, $cid1);
            }
        }
    }

    /** @param int $balance */
    function set_balance($balance) {
        $this->balance = $balance;
    }

    /** @param int $method */
    function set_method($method) {
        $this->method = $method;
    }

    function set_review_gadget($review_gadget) {
        $this->review_gadget = $review_gadget;
    }

    /** @param callable $progressf */
    function add_progress_handler($progressf) {
        $this->progressf[] = $progressf;
    }

    private function set_progress($status) {
        foreach ($this->progressf as $progressf) {
            call_user_func($progressf, $status);
        }
    }


    function run_prefconflict($papertype) {
        $papers = array_fill_keys($this->papersel, 1);
        $result = $this->conf->preference_conflict_result($papertype, "");
        $this->ass = ["paper,action,email"];
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $cid = (int) $row[1];
            if (isset($papers[$pid]) && ($ac = $this->acs[$cid] ?? null)) {
                $this->ass[] = "$pid,conflict," . $ac->user->email;
                $ac->newass[] = $pid;
            }
        }
        Dbl::free($result);
    }

    function run_clear($reviewtype) {
        $papers = array_fill_keys($this->papersel, 1);
        if ($reviewtype == REVIEW_META
            || $reviewtype == REVIEW_PRIMARY
            || $reviewtype == REVIEW_SECONDARY
            || $reviewtype == REVIEW_PC) {
            $q = "select paperId, contactId from PaperReview where reviewType=" . $reviewtype;
            $action = "noreview";
        } else if ($reviewtype === "conflict") {
            $q = "select paperId, contactId from PaperConflict where conflictType>" . CONFLICT_MAXUNCONFLICTED . " and conflictType<" . CONFLICT_AUTHOR;
            $action = "noconflict";
        } else if ($reviewtype === "lead" || $reviewtype === "shepherd") {
            $q = "select paperId, {$reviewtype}ContactId from Paper where {$reviewtype}ContactId!=0";
            $action = "no" . $reviewtype;
        } else {
            return false;
        }
        $this->ass = ["paper,action,email"];
        $result = $this->conf->qe_raw($q);
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $cid = (int) $row[1];
            if (isset($papers[$pid]) && ($ac = $this->acs[$cid] ?? null)) {
                $this->ass[] = "$pid,$action," . $ac->user->email;
                $ac->newass[] = $pid;
            }
        }
        Dbl::free($result);
    }


    private function balance_reviews($reviewtype) {
        $q = "select contactId, count(reviewId) from PaperReview where contactId?a";
        if ($reviewtype) {
            $q .= " and reviewType={$reviewtype}";
        } else {
            $q .= " and reviewType>0";
        }
        $result = $this->conf->qe($q . " group by contactId", array_keys($this->acs));
        while (($row = $result->fetch_row())) {
            $this->acs[(int) $row[0]]->load = (int) $row[1];
        }
        Dbl::free($result);
    }

    private function balance_paperpc($action) {
        $q = "select {$action}ContactId, count(paperId) from Paper where paperId ?A group by {$action}ContactId";
        $result = $this->conf->qe($q, $this->papersel);
        while (($row = $result->fetch_row())) {
            if (($ac = $this->acs[(int) $row[0]] ?? null)) {
                $ac->load = (int) $row[1];
            }
        }
        Dbl::free($result);
    }

    private function reset_prefs() {
        gc_collect_cycles();
        $this->ainfo = [];
        foreach ($this->acs as $ac) {
            $alist = [];
            foreach ($this->papersel as $pid) {
                $alist[$pid] = new AutoassignerElement;
            }
            $this->ainfo[$ac->cid] = $alist;
        }
    }

    private function preferences_review($reviewtype) {
        $time = microtime(true);
        $this->reset_prefs();

        // first load refusals
        $result = $this->conf->qe("select contactId, paperId from PaperReviewRefused where paperId ?a", $this->papersel);
        while (($row = $result->fetch_row())) {
            $cid = (int) $row[0];
            $pid = (int) $row[1];
            if (($a = $this->ainfo[$cid][$pid] ?? null)) {
                $a->eass = self::ENOASSIGN;
            }
        }
        Dbl::free($result);

        // then load preferences
        $result = $this->conf->paper_result(["paperId" => $this->papersel, "topics" => true, "allReviewerPreference" => true, "allConflictType" => true, "reviewSignatures" => true, "tags" => $this->conf->check_track_sensitivity(Track::ASSREV)]);
        $nmade = 0;
        while (($row = PaperInfo::fetch($result, null, $this->conf))) {
            $pid = $row->paperId;
            foreach ($this->acs as $ac) {
                $a = $this->ainfo[$ac->cid][$pid];
                list($a->pref, $a->exp, $a->topicscore) = $row->preference($ac->user, true);
                $rt = $row->review_type($ac->user);
                if ($rt == $reviewtype) {
                    $a->eass = self::EOLDASSIGN;
                } else if ($rt) {
                    $a->eass = self::EOTHERASSIGN;
                } else if ($row->has_conflict($ac->user)
                           || !$ac->user->can_accept_review_assignment($row)) {
                    $a->eass = self::ENOASSIGN;
                }
                $a->cpref = max($a->pref, -1000) + ((float) $a->topicscore / 100.0);
            }
            ++$nmade;
            if ($nmade % 16 == 0) {
                $this->set_progress(sprintf("Loading reviewer preferences (%d%% done)", (int) ($nmade * 100 / count($this->papersel) + 0.5)));
            }
        }
        Dbl::free($result);
        $row = $result = null;
        gc_collect_cycles();
        $this->make_pref_groups();

        // mark badpairs as noassign;
        // need to populate review assignments for unselected badpairs users
        $missing_bp = [];
        foreach ($this->avoid_pairs as $pair) {
            if (($ac = $this->acs[$pair[0]] ?? null)) {
                foreach ($this->ainfo[$ac->cid] as $pid => $a) {
                    if ($a->eass > self::ENOASSIGN) {
                        foreach ($ac->avoid_cid ?? [] as $cid2) {
                            if (($a2 = $this->ainfo[$cid2][$pid] ?? null)) {
                                $a2->eass = max($a2->eass, self::ENOASSIGN);
                            }
                        }
                    }
                }
            } else if (isset($this->acs[$pair[1]])) {
                $missing_bp[$pair[0]][] = $pair[1];
            }
        }
        if (!empty($missing_bp)) {
            $result = $this->conf->qe("select contactId, paperId from PaperReview where paperId?a and contactId?a and reviewType>0", $this->papersel, array_keys($missing_bp));
            while (($row = $result->fetch_row())) {
                $cid = (int) $row[0];
                $pid = (int) $row[1];
                foreach ($missing_bp[$cid] as $cid2) {
                    $a2 = $this->ainfo[$cid2][$pid];
                    $a2->eass = max($a2->eass, self::ENOASSIGN);
                }
            }
            Dbl::free($result);
        }

        $this->profile["preferences"] = microtime(true) - $time;
    }

    private function preferences_paperpc($scoreinfo) {
        $time = microtime(true);
        $this->reset_prefs();

        $all_fields = $this->conf->all_review_fields();
        $score = null;
        $scoredir = 1;
        $scoreorder = 0;
        if ((substr($scoreinfo, 0, 1) === "-"
             || substr($scoreinfo, 0, 1) === "+")
            && isset($all_fields[substr($scoreinfo, 1)])) {
            $score = substr($scoreinfo, 1);
            $scoredir = substr($scoreinfo, 0, 1) === "-" ? -1 : 1;
            $scoreorder = $all_fields[substr($scoreinfo, 1)]->order;
        }

        $set = $this->conf->paper_set(["paperId" => $this->papersel, "allConflictType" => true, "reviewSignatures" => true, "scores" => $score ? [$all_fields[$score]] : []]);

        $scorearr = [];
        foreach ($set as $prow) {
            if ($scoreorder) {
                $prow->ensure_review_field_order($scoreorder);
            }
            foreach ($this->acs as $cid => $ac) {
                if ($prow->has_conflict($cid)
                    || !($rrow = $prow->review_by_user($cid))
                    || ($scoreinfo !== "xa" && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED)
                    || ($scoreorder && !$rrow->fields[$scoreorder])) {
                    $scorearr[$prow->paperId][$cid] = -1;
                } else {
                    $s = $score ? $rrow->fields[$scoreorder] : 1;
                    if ($scoredir == -1) {
                        $s = 1000 - $s;
                    }
                    $scorearr[$prow->paperId][$cid] = $s;
                }
            }
        }

        foreach ($scorearr as $pid => $carr) {
            $extreme = max($carr);
            foreach ($carr as $cid => $s) {
                if ($s < 0) {
                    $this->ainfo[$cid][$pid]->eass = self::ENOASSIGN;
                } else {
                    $this->ainfo[$cid][$pid]->cpref = max(0, $s - $extreme + 2);
                }
            }
        }

        $this->make_pref_groups();
        $this->profile["preferences"] = microtime(true) - $time;
    }

    private function make_pref_groups() {
        foreach ($this->acs as $ac) {
            $pf = $this->ainfo[$ac->cid] ?? [];
            uasort($pf, function ($a1, $a2) {
                return $a1->cpref < $a2->cpref ? 1 : ($a1->cpref == $a2->cpref ? 0 : -1);
            });
            $last_group = null;
            $ac->pref_groups = [];
            foreach ($pf as $pid => $a) {
                if (!$last_group || $a->cpref != $last_group->cpref) {
                    $ac->pref_groups[] = $last_group = new AutoassignerPrefGroup($a->cpref);
                }
                $last_group->pids[] = $pid;
            }
            reset($ac->pref_groups);
        }
        $this->has_pref_groups = true;
    }

    private function make_assignment($action, $round, $cid, $pid, &$papers) {
        if (empty($this->ass)) {
            $this->ass = ["paper,action,email,round"];
        }
        $ac = $this->acs[$cid];
        $this->ass[] = "$pid,$action,{$ac->user->email}{$round}";
        if (!($a = $this->ainfo[$cid][$pid] ?? null)) {
            $a = $this->ainfo[$cid][$pid] = new AutoassignerElement;
        }
        $a->eass = self::ENEWASSIGN;
        $papers[$pid]--;
        $ac->newass[] = $pid;
        ++$ac->load;
        foreach ($ac->avoid_cid ?? [] as $cid2) {
            if (($a2 = $this->ainfo[$cid2][$pid] ?? null)) {
                $a2->eass = max($a2->eass, self::ENOASSIGN);
            }
        }
    }

    private function action_avoids_pairs($action) {
        return $action !== "lead" && $action !== "shepherd";
    }

    private function assign_desired(&$papers, $nperpc) {
        if ($nperpc) {
            return $nperpc * count($this->acs);
        }
        $n = 0;
        foreach ($papers as $ct) {
            $n += max($ct, 0);
        }
        return $n;
    }

    // This assignment function assigns without considering preferences.
    private function assign_stupidly(&$papers, $action, $round, $nperpc) {
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $acs = $this->acs;
        while (!empty($acs)) {
            // choose a pc member at random, equalizing load
            $ac = null;
            $numminpc = 0;
            foreach ($acs as $acx) {
                if ($ac === null || $acx->load < $ac->load) {
                    $numminpc = 0;
                    $ac = $acx;
                } else if ($acx->load === $ac->load) {
                    ++$numminpc;
                    if (mt_rand(0, $numminpc) === 0) {
                        $ac = $acx;
                    }
                }
            }

            // select a paper
            $apids = array_keys(array_filter($papers, function ($ct) { return $ct > 0; }));
            while (!empty($apids)) {
                $pididx = mt_rand(0, count($apids) - 1);
                $pid = $apids[$pididx];
                array_splice($apids, $pididx, 1);
                if (($a = $this->ainfo[$ac->cid][$pid]) && $a->eass !== 0) {
                    continue;
                }
                // make assignment
                $this->make_assignment($action, $round, $ac->cid, $pid, $papers);
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0) {
                    $this->set_progress(sprintf("Making assignments stupidly (%d%% done)", (int) ($nmade * 100 / $ndesired + 0.5)));
                }
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$apids || $ac->load === $nperpc) {
                unset($acs[$ac->cid]);
            }
        }
    }

    private function assign_randomly(&$papers, $action, $round, $nperpc) {
        $ndesired = $this->assign_desired($papers, $nperpc);
        $nmade = 0;
        $acs = $this->acs;
        while (!empty($acs)) {
            // choose a pc member at random, equalizing load
            $ac = null;
            $numminpc = 0;
            foreach ($acs as $acx) {
                if ($ac === null
                    || $acx->load < $ac->load
                    || ($acx->load === $ac->load
                        && $acx->unhappiness < $ac->unhappiness)) {
                    $numminpc = 0;
                    $ac = $acx;
                } else if ($acx->load === $ac->load
                           && $acx->unhappiness === $ac->unhappiness) {
                    ++$numminpc;
                    if (mt_rand(0, $numminpc) == 0) {
                        $ac = $acx;
                    }
                }
            }

            // traverse preferences in descending order until encountering an
            // assignable paper
            $pg = null;
            while (!empty($ac->pref_groups)
                   && ($pg = current($ac->pref_groups))) {
                // create copy of pids for assignment
                if ($pg->apids === null) {
                    $pg->apids = $pg->pids;
                }
                // skip if no papers left
                if (empty($pg->apids)) {
                    next($ac->pref_groups);
                    ++$ac->pref_dist;
                    continue;
                }
                // pick a random paper at current preference level
                $pididx = mt_rand(0, count($pg->apids) - 1);
                /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
                $pid = $pg->apids[$pididx];
                array_splice($pg->apids, $pididx, 1);
                // skip if not assignable
                if (!isset($papers[$pid])
                    || $papers[$pid] <= 0
                    || (($a = $this->ainfo[$ac->cid][$pid] ?? null) && $a->eass !== 0)) {
                    continue;
                }
                // make assignment
                $this->make_assignment($action, $round, $ac->cid, $pid, $papers);
                $ac->unhappiness += $ac->pref_dist;
                // report progress
                ++$nmade;
                if ($nmade % 10 == 0) {
                    $this->set_progress(sprintf("Making assignments (%d%% done)", (int) ($nmade * 100 / $ndesired + 0.5)));
                }
                break;
            }

            // if have exhausted preferences, remove pc member
            if (!$pg || $ac->load === $nperpc) {
                unset($acs[$ac->cid]);
            }
        }
    }

    /** @param MinCostMaxFlow $mcmf */
    function mcmf_progress($mcmf, $what, $phaseno = 0, $nphases = 0) {
        if ($what <= MinCostMaxFlow::PMAXFLOW_DONE) {
            $n = min(max($mcmf->current_flow(), 0), $this->ndesired);
            $ndesired = max($this->ndesired, 1);
            $this->set_progress(sprintf("Preparing unoptimized assignment$this->mcmf_round_descriptor (%d%% done)", (int) ($n * 100 / $ndesired + 0.5)));
        } else {
            $x = [];
            $cost = $mcmf->current_cost();
            if (!$this->mcmf_max_cost) {
                $this->mcmf_max_cost = $cost;
            } else if ($cost < $this->mcmf_max_cost) {
                $x[] = sprintf("%.1f%% better", ((int) (($this->mcmf_max_cost - $cost) * 1000 / abs($this->mcmf_max_cost) + 0.5)) / 10);
            }
            $phasedescriptor = $nphases > 1 ? ", phase " . ($phaseno + 1) . "/" . $nphases : "";
            $this->set_progress($this->mcmf_optimizing_for
                                . $this->mcmf_round_descriptor . $phasedescriptor
                                . ($x ? " (" . join(", ", $x) . ")" : ""));
        }
    }

    private function assign_mcmf_once(&$papers, $action, $round, $nperpc) {
        $m = new MinCostMaxFlow;
        $m->add_progress_handler([$this, "mcmf_progress"]);
        $this->ndesired = $this->assign_desired($papers, $nperpc);
        $this->mcmf_max_cost = null;
        $this->set_progress("Preparing assignment optimizer" . $this->mcmf_round_descriptor);
        // existing assignment counts
        $ceass = array_fill_keys(array_keys($this->acs), 0);
        $peass = array_fill_keys($this->papersel, 0);
        foreach ($this->ainfo as $cid => $alist) {
            foreach ($alist as $pid => $a) {
                if ($a->eass === self::ENEWASSIGN
                    || ($a->eass >= self::EOTHERASSIGN && $this->balance !== self::BALANCE_NEW)) {
                    ++$ceass[$cid];
                    ++$peass[$pid];
                }
            }
        }
        // paper nodes
        $nass = 0;
        foreach ($papers as $pid => $ct) {
            if (($tct = $peass[$pid] + $ct) <= 0) {
                continue;
            }
            $m->add_node("p$pid", "p");
            $m->add_edge("p$pid", ".sink", $tct, 0, $peass[$pid]);
            if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                $m->add_node("p{$pid}x", "px");
                $m->add_node("p{$pid}y", "py");
                $m->add_node("p{$pid}xy", "pxy");
                $m->add_edge("p{$pid}x", "p{$pid}xy", 1, $this->costs->expertise_x);
                $m->add_edge("p{$pid}x", "p{$pid}y", $tct, 0);
                $m->add_edge("p{$pid}y", "p{$pid}xy", 2, $this->costs->expertise_y);
                $m->add_edge("p{$pid}y", "p$pid", $tct, 0);
                $m->add_edge("p{$pid}xy", "p$pid", 2, 0);
            }
            $nass += $ct;
        }
        // user nodes
        $assperpc = ceil($nass / count($this->acs));
        $minload = PHP_INT_MAX;
        $maxload = $assperpc;
        foreach ($this->acs as $ac) {
            $minload = min($minload, $ac->load);
            $maxload = max($maxload, $ac->load + $assperpc);
        }
        foreach ($this->acs as $cid => $ac) {
            $m->add_node("u$cid", "u");
            if ($nperpc) {
                $m->add_edge(".source", "u$cid", $nperpc, 0);
            } else {
                for ($l = $ac->load; $l < $maxload; ++$l) {
                    $m->add_edge(".source", "u$cid", 1, $this->costs->assignment * ($l - $minload));
                }
            }
            if ($ceass[$cid]) {
                $m->add_edge(".source", "u$cid", $ceass[$cid], 0, $ceass[$cid]);
            }
            // cost determination
            $alist = $this->ainfo[$cid] ?? [];
            foreach ($ac->pref_groups as $pgi => $pg) {
                $adjusted_pgi = (int) ($pgi * $this->costs->preference / count($ac->pref_groups));
                foreach ($pg->pids as $pid) {
                    $alist[$pid]->cost = $adjusted_pgi;
                }
            }
        }
        // figure out members of badpairs classes
        $bpmembers = [];
        if ($this->action_avoids_pairs($action)) {
            foreach ($this->acs as $ac) {
                if (!empty($ac->avoid_cid)) {
                    $bpmembers[$ac->avoid_class][] = $ac->cid;
                }
            }
        }
        // paper <-> contact map
        $bpdone = array();
        foreach ($papers as $pid => $ct) {
            if ($ct <= 0 && $peass[$pid] <= 0) {
                continue;
            }
            foreach ($this->acs as $cid => $ac) {
                $a = $this->ainfo[$cid][$pid];
                if ($a->eass === self::ENOASSIGN
                    || ($a->eass > 0 && $a->eass < self::ENEWASSIGN && $this->balance == self::BALANCE_NEW)
                    || ($a->eass === 0 && $ct <= 0)) {
                    continue;
                }
                if (isset($bpmembers[$ac->avoid_class])) {
                    $dst = "b{$pid}.{$ac->avoid_class}";
                    if (!$m->node_exists($dst)) {
                        // Existing assignments might invalidate the badpair
                        // requirement.
                        $capacity = 0;
                        foreach ($bpmembers[$ac->avoid_class] as $cid2) {
                            if (($a2 = $this->ainfo[$cid2][$pid] ?? null)
                                && $a2->eass > self::ENOASSIGN
                                && ($a2->eass >= self::ENEWASSIGN || $this->balance !== self::BALANCE_NEW)) {
                                ++$capacity;
                            }
                        }
                        $m->add_node($dst, "b");
                        $m->add_edge($dst, "p$pid", max($capacity, 1), 0);
                    }
                } else if ($this->review_gadget == self::REVIEW_GADGET_EXPERTISE) {
                    if ($a->exp > 0) {
                        $dst = "p{$pid}x";
                    } else if ($a->exp === 0) {
                        $dst = "p{$pid}y";
                    } else {
                        $dst = "p$pid";
                    }
                } else {
                    $dst = "p$pid";
                }
                $m->add_edge("u$cid", $dst, 1, $a->cost, $a->eass ? 1 : 0);
            }
        }
        // run MCMF
        $this->mcmf = $m;
        $m->shuffle();
        $m->run();
        // make assignments
        $this->set_progress("Completing assignment" . $this->mcmf_round_descriptor);
        $time = microtime(true);
        $nassigned = 0;
        if (!$m->infeasible) {
            foreach ($this->acs as $cid => $p) {
                foreach ($m->reachable("u$cid", "p") as $v) {
                    $pid = (int) substr($v->name, 1);
                    if ($this->ainfo[$cid][$pid]->eass === 0) {
                        $this->make_assignment($action, $round, $cid, $pid, $papers);
                        ++$nassigned;
                    }
                }
            }
        }
        $this->profile["maxflow"] = $m->maxflow_end_at - $m->maxflow_start_at;
        if ($m->mincost_start_at) {
            $this->profile["mincost"] = $m->mincost_end_at - $m->mincost_start_at;
        }
        $this->mcmf = null;
        $this->profile["traverse"] = microtime(true) - $time;
        return $nassigned;
    }

    private function assign_mcmf(&$papers, $action, $round, $nperpc) {
        $this->mcmf_round_descriptor = "";
        $this->mcmf_optimizing_for = "Optimizing assignment for preferences and balance";
        $mcmf_round = 1;
        while ($this->assign_mcmf_once($papers, $action, $round, $nperpc)) {
            $nmissing = 0;
            foreach ($papers as $pid => $ct) {
                if ($ct > 0)
                    $nmissing += $ct;
            }
            $navailable = 0;
            if ($nperpc) {
                foreach ($this->acs as $ac) {
                    $navailable += max($nperpc - $ac->load, 0);
                }
            }
            if ($nmissing == 0 || $navailable == 0) {
                break;
            }
            ++$mcmf_round;
            $this->mcmf_round_descriptor = ", round $mcmf_round";
            gc_collect_cycles();
        }
    }

    private function assign_method(&$papers, $action, $round, $nperpc) {
        if ($this->method == self::METHOD_RANDOM) {
            $this->assign_randomly($papers, $action, $round, $nperpc);
        } else if ($this->method == self::METHOD_STUPID) {
            $this->assign_stupidly($papers, $action, $round, $nperpc);
        } else {
            $this->assign_mcmf($papers, $action, $round, $nperpc);
        }
        gc_collect_cycles();
    }


    private function check_missing_assignments(&$papers, $action) {
        ksort($papers);
        $badpids = array();
        foreach ($papers as $pid => $n) {
            if ($n > 0)
                $badpids[] = $pid;
        }
        if (!count($badpids)) {
            return;
        }
        $b = array();
        $pidx = join("+", $badpids);
        foreach ($badpids as $pid) {
            $b[] = $this->conf->hotlink($pid, "assign", "p=$pid&amp;ls=$pidx");
        }
        $x = "";
        if ($action === "rev" || $action === "revadd") {
            $x = ", possibly because of conflicts or previously declined reviews in the PC members you selected";
        } else {
            $x = ", possibly because the selected PC members didn’t review these submissions";
        }
        $y = (count($b) > 1 ? ' (' . $this->conf->hotlink("list them", "search", "q=$pidx", ["class" => "nw"]) . ')' : '');
        $this->conf->feedback_msg(
            MessageItem::warning("<0>The assignment could not be completed{$x}"),
            MessageItem::inform("<5>The following submissions got fewer than the required number of assignments: " . join(", ", $b) . $y . ".")
        );
    }

    private function finish_assignment() {
        $this->ainfo = [];
    }

    function run_paperpc($action, $preference) {
        if ($this->balance !== self::BALANCE_NEW) {
            $this->balance_paperpc($action);
        }
        $this->preferences_paperpc($preference);
        $papers = array_fill_keys($this->papersel, 0);
        $result = $this->conf->qe("select paperId from Paper where {$action}ContactId=0");
        while (($row = $result->fetch_row())) {
            if (isset($papers[$row[0]]))
                $papers[$row[0]] = 1;
        }
        Dbl::free($result);
        $this->assign_method($papers, $action, "", null);
        $this->check_missing_assignments($papers, $action);
        $this->finish_assignment(); // recover memory
    }

    private function analyze_reviewtype($reviewtype, $round) {
        return [ReviewInfo::unparse_assigner_action($reviewtype),
                $round ? ",$round" : ""];
    }

    function run_reviews_per_pc($reviewtype, $round, $nass) {
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel,
            ceil((count($this->acs) * ($nass + 2)) / max(count($this->papersel), 1)));
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, $nass);
        $this->finish_assignment(); // recover memory
    }

    function run_more_reviews($reviewtype, $round, $nass) {
        if ($this->balance !== self::BALANCE_NEW) {
            $this->balance_reviews($reviewtype);
        }
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel, $nass);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "revadd");
        $this->finish_assignment(); // recover memory
    }

    function run_ensure_reviews($reviewtype, $round, $nass) {
        if ($this->balance !== self::BALANCE_NEW) {
            $this->balance_reviews($reviewtype);
        }
        $this->preferences_review($reviewtype);
        $papers = array_fill_keys($this->papersel, $nass);
        $result = $this->conf->qe("select paperId, count(reviewId) from PaperReview where reviewType={$reviewtype} group by paperId");
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            if (isset($papers[$pid])) {
                $papers[$pid] = max($nass - (int) $row[1], 0);
            }
        }
        Dbl::free($result);
        list($action, $round) = $this->analyze_reviewtype($reviewtype, $round);
        $this->assign_method($papers, $action, $round, null);
        $this->check_missing_assignments($papers, "rev");
        $this->finish_assignment(); // recover memory
    }

    /** @param array<int,list<int>> $cflt */
    private function run_discussion_order_once($cflt, $plist) {
        $m = new MinCostMaxFlow;
        $m->add_progress_handler(array($this, "mcmf_progress"));
        $this->set_progress("Preparing assignment optimizer");
        // paper nodes
        // set p->po edge cost so low that traversing that edge will
        // definitely lower total cost; all positive costs are <=
        // count($this->acs), so this edge should have cost:
        $pocost = -(count($this->acs) + 1);
        $this->mcmf_max_cost = $pocost * count($plist) * 0.75;
        $m->add_node(".s", "source");
        $m->add_edge(".source", ".s", 1, 0);
        foreach ($plist as $i => $pids) {
            $m->add_node("p$i", "p");
            $m->add_node("po$i", "po");
            $m->add_edge(".s", "p$i", 1, 0);
            $m->add_edge("p$i", "po$i", 1, $pocost);
            $m->add_edge("po$i", ".sink", 1, 0);
        }
        // conflict edges
        $plist2 = $plist; // need copy for different iteration ptr
        foreach ($plist as $i => $pid1) {
            foreach ($plist2 as $j => $pid2) {
                if ($i != $j) {
                    $pid1 = is_array($pid1) ? $pid1[count($pid1) - 1] : $pid1;
                    $pid2 = is_array($pid2) ? $pid2[0] : $pid2;
                    // cost of edge is number of different conflicts
                    $cost = count($cflt[$pid1] + $cflt[$pid2]) - count(array_intersect($cflt[$pid1], $cflt[$pid2]));
                    $m->add_edge("po$i", "p$j", 1, $cost);
                }
            }
        }
        // run MCMF
        $this->mcmf = $m;
        $m->shuffle();
        $m->run();
        // extract next roots
        $roots = array_keys($plist);
        $result = array();
        while (!$m->infeasible && !empty($roots)) {
            $source = ".source";
            if (count($roots) !== count($plist)) {
                $source = "p" . $roots[mt_rand(0, count($roots) - 1)];
            }
            $pgroup = $igroup = array();
            foreach ($m->topological_sort($source, "p") as $v) {
                $pidx = (int) substr($v->name, 1);
                $igroup[] = $pidx;
                if (is_array($plist[$pidx])) {
                    $pgroup = array_merge($pgroup, $plist[$pidx]);
                } else {
                    $pgroup[] = $plist[$pidx];
                }
            }
            $result[] = $pgroup;
            $roots = array_values(array_diff($roots, $igroup));
        }
        // done
        $m->clear(); // break circular refs
        $this->mcmf = null;
        $this->profile["maxflow"] += $m->maxflow_end_at - $m->maxflow_start_at;
        if ($m->mincost_start_at) {
            $this->profile["mincost"] += $m->mincost_end_at - $m->mincost_start_at;
        }
        return $result;
    }

    function run_discussion_order($tag, $sequential = false) {
        if (empty($this->papersel)) {
            $this->ass = [];
            return;
        }
        $this->mcmf_round_descriptor = "";
        $this->mcmf_optimizing_for = "Optimizing assignment";
        // load conflicts
        $cflt = array();
        foreach ($this->papersel as $pid) {
            $cflt[$pid] = array();
        }
        $result = $this->conf->qe("select paperId, contactId from PaperConflict where paperId?a and contactId?a and conflictType>" . CONFLICT_MAXUNCONFLICTED, $this->papersel, array_keys($this->acs));
        while (($row = $result->fetch_row())) {
            $cflt[(int) $row[0]][] = (int) $row[1];
        }
        Dbl::free($result);
        // run max-flow
        $result = $this->papersel;
        $groupmap = [];
        for ($roundno = 0; !$roundno || count($result) > 1; ++$roundno) {
            $this->mcmf_round_descriptor = $roundno ? ", round " . ($roundno + 1) : "";
            $result = $this->run_discussion_order_once($cflt, $result);
            gc_collect_cycles();
            if (!$roundno) {
                foreach ($result as $i => $pids) {
                    foreach ($pids as $pid) {
                        $groupmap[$pid] = $i;
                    }
                }
            }
        }
        // make assignments
        $this->set_progress("Completing assignment");
        $this->ass = array("paper,action,tag", "# hotcrp_assign_display_search",
                           "# hotcrp_assign_show pcconf", "all,cleartag,$tag");
        $curgroup = -1;
        $index = 0;
        $search = array("LEGEND:none");
        foreach ($result[0] as $pid) {
            if ($groupmap[$pid] != $curgroup && $curgroup != -1) {
                $search[] = "THEN LEGEND:none";
            }
            $curgroup = $groupmap[$pid];
            $index += Tagger::value_increment($sequential);
            $this->ass[] = "{$pid},tag,{$tag}#{$index}";
            $search[] = $pid;
        }
        $this->ass[1] = "# hotcrp_assign_display_search " . join(" ", $search);
        //echo Ht::unstash_script("$('#propass').before(" . json_encode_browser(Ht::pre_text_wrap($m->debug_info(true) . "\n")) . ")");
        $this->finish_assignment(); // recover memory
    }


    /** @return list<string> */
    function assignments() {
        return count($this->ass) > 1 ? $this->ass : [];
    }

    /** @return array<int,int> */
    function pc_unhappiness() {
        $u = [];
        if ($this->has_pref_groups) {
            foreach ($this->acs as $ac) {
                $pidm = [];
                foreach ($ac->pref_groups as $i => $pg) {
                    foreach ($pg->pids as $pid) {
                        $pidm[$pid] = $i;
                    }
                }
                $unhappiness = 0;
                foreach ($ac->newass ?? [] as $pid) {
                    $unhappiness += $pidm[$pid];
                }
                $u[$ac->cid] = $unhappiness;
            }
        }
        return $u;
    }

    /** @return bool */
    function has_tentative_assignment() {
        return count($this->ass) || $this->mcmf;
    }

    /** @return array<int,array<int,true>> */
    function tentative_assignment_map() {
        $pcmap = $a = [];
        foreach ($this->acs as $ac) {
            $a[$ac->cid] = [];
            foreach ($ac->newass ?? [] as $pid) {
                $a[$ac->cid][$pid] = true;
            }
        }
        if (($m = $this->mcmf)) {
            foreach ($this->acs as $cid => $p) {
                foreach ($m->reachable("u$cid", "p") as $v) {
                    $a[$cid][(int) substr($v->name, 1)] = true;
                }
            }
        }
        return $a;
    }
}
