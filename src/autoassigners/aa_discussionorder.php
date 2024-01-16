<?php
// autoassigners/aa_discussionorder.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class DiscussionOrder_Autoassigner extends Autoassigner {
    /** @var string */
    private $tag;
    /** @var bool */
    private $sequential = false;

    /** @param ?list<int> $pcids
     * @param list<int> $papersel
     * @param array<string,mixed> $subreq
     * @param object $gj */
    function __construct(Contact $user, $pcids, $papersel, $subreq, $gj) {
        parent::__construct($user, $pcids, $papersel);
        $t = trim($subreq["tag"] ?? "");
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($t, Tagger::NOVALUE))) {
            $this->tag = $tag;
        } else {
            $this->error_at("tag", $tagger->error_ftext());
        }
    }

    /** @param array<int,list<int>> $cflt */
    private function run_discussion_order_once($cflt, $plist) {
        $m = new MinCostMaxFlow;
        $m->add_progress_function(array($this, "mcmf_progress"));
        $this->mark_progress("Preparing assignment optimizer");
        // paper nodes
        // set p->po edge cost so low that traversing that edge will
        // definitely lower total cost; all positive costs are <=
        // count($this->acs), so this edge should have cost:
        $pocost = -(count($this->aausers()) + 1);
        $this->mcmf_max_cost = $pocost * count($plist) * 0.75;
        $m->add_node(".s", "source");
        $m->add_edge(".source", ".s", 1, 0);
        foreach ($plist as $i => $pids) {
            $m->add_node("p{$i}", "p");
            $m->add_node("po{$i}", "po");
            $m->add_edge(".s", "p{$i}", 1, 0);
            $m->add_edge("p{$i}", "po{$i}", 1, $pocost);
            $m->add_edge("po{$i}", ".sink", 1, 0);
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
                    $m->add_edge("po{$i}", "p{$j}", 1, $cost);
                }
            }
        }
        // run MCMF
        $this->mcmf = $m;
        $m->shuffle();
        $m->run();
        // extract next roots
        $roots = array_keys($plist);
        $result = [];
        while (!$m->infeasible && !empty($roots)) {
            $source = ".source";
            if (count($roots) !== count($plist)) {
                $source = "p" . $roots[mt_rand(0, count($roots) - 1)];
            }
            $pgroup = $igroup = [];
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

    function run() {
        if (!$this->paper_ids()) {
            return;
        }
        $this->mcmf_round_descriptor = "";
        $this->mcmf_optimizing_for = "Optimizing assignment";
        // load conflicts
        $cflt = [];
        foreach ($this->paper_ids() as $pid) {
            $cflt[$pid] = [];
        }
        $result = $this->conf->qe("select paperId, contactId from PaperConflict where paperId?a and contactId?a and conflictType>" . CONFLICT_MAXUNCONFLICTED, $this->paper_ids(), $this->user_ids());
        while (($row = $result->fetch_row())) {
            $cflt[(int) $row[0]][] = (int) $row[1];
        }
        Dbl::free($result);
        // run max-flow
        $result = $this->paper_ids();
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
        $this->mark_progress("Completing assignment");
        $this->ass = [
            "paper,action,tag\n",
            "### hotcrp_assign_display_search\n",
            "### hotcrp_assign_show pcconf\n",
            "all,cleartag,{$this->tag}\n"
        ];
        $curgroup = -1;
        $index = 0;
        $search = ["LEGEND:none"];
        foreach ($result[0] as $pid) {
            if ($groupmap[$pid] != $curgroup && $curgroup != -1) {
                $search[] = "THEN LEGEND:none";
            }
            $curgroup = $groupmap[$pid];
            $index += Tagger::value_increment($this->sequential);
            $this->ass[] = "{$pid},tag,{$this->tag}#{$index}\n";
            $search[] = $pid;
        }
        $this->ass[1] = "### hotcrp_assign_display_search " . join(" ", $search) . "\n";
        //echo Ht::unstash_script("$('#propass').before(" . json_encode_browser(Ht::pre_text_wrap($m->debug_info(true) . "\n")) . ")");
        $this->finish_assignment(); // recover memory
    }
}
