<?php
// mincostmaxflow.php -- HotCRP min-cost max-flow
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MinCostMaxFlow_Node {
    public $name;
    public $klass;
    public $flow = 0;
    public $link = null;
    public $linkrev = null;
    public $xlink = null;
    public $npos = null;
    public $cycle = null;
    public $distance = 0;
    public $excess = 0;
    public $price = 0;
    public $entryflow = 0;
    public $check_outgoing_admissible = null;
    public $ein = array();
    public $eout = array();
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
    }
    public function check_excess() {
        $x = $this->entryflow;
        foreach ($this->ein as $e)
            $x += $e->flow;
        foreach ($this->eout as $e)
            $x -= $e->flow;
        if ($x != $this->excess)
            fwrite(STDERR, "{$this->name}: bad excess e{$this->excess}, have $x\n");
        assert($x == $this->excess);
    }
    public function has_outgoing_admissible() {
        if ($this->check_outgoing_admissible !== null)
            return $this->check_outgoing_admissible;
        foreach ($this->ein as $e)
            if ($e->flow > 0 && $e->reduced_cost(true) < 0)
                return $this->check_outgoing_admissible = true;
        foreach ($this->eout as $e)
            if ($e->flow < $e->cap && $e->reduced_cost(false) < 0)
                return $this->check_outgoing_admissible = true;
        return $this->check_outgoing_admissible = false;
    }
};

class MinCostMaxFlow_Edge {
    public $src;
    public $dst;
    public $cap;
    public $cost;
    public $flow = 0;
    public function __construct($src, $dst, $cap, $cost) {
        $this->src = $src;
        $this->dst = $dst;
        $this->cap = $cap;
        $this->cost = $cost;
    }
    public function residual_cap($isrev) {
        return $isrev ? $this->flow : $this->cap - $this->flow;
    }
    public function reduced_cost($isrev) {
        if ($isrev)
            return -$this->cost - $this->src->price + $this->dst->price;
        else
            return $this->cost + $this->src->price - $this->dst->price;
    }
};

class MinCostMaxFlow {
    private $v = array();
    private $vmap;
    private $source;
    private $sink;
    private $e = array();
    private $maxcap;
    private $mincost;
    private $maxcost;
    private $progressf = array();
    private $hasrun;
    // times
    public $maxflow_start_at = null;
    public $maxflow_end_at = null;
    public $mincost_start_at = null;
    public $mincost_end_at = null;
    // pushrelabel/cspushrelabel state
    private $epsilon;
    private $ltail;

    const PMAXFLOW = 0;
    const PMAXFLOW_DONE = 1;
    const PMINCOST_BEGINROUND = 2;
    const PMINCOST_INROUND = 3;
    const PMINCOST_DONE = 4;

    const CSPUSHRELABEL_ALPHA = 12;

    public function __construct() {
        $this->clear();
    }

    public function add_node($name, $klass) {
        assert(is_string($name) && !isset($this->vmap[$name]));
        $v = new MinCostMaxFlow_Node($name, $klass);
        $this->v[] = $this->vmap[$name] = $v;
        return $v;
    }

    public function add_edge($vs, $vd, $cap, $cost = 0) {
        if (is_string($vs))
            $vs = $this->vmap[$vs];
        if (is_string($vd))
            $vd = $this->vmap[$vd];
        assert(($vs instanceof MinCostMaxFlow_Node) && ($vd instanceof MinCostMaxFlow_Node));
        // XXX assert(this edge does not exist)
        $ei = count($this->e);
        $this->e[] = new MinCostMaxFlow_Edge($vs, $vd, $cap, $cost);
        $this->maxcap = max($this->maxcap, $cap);
        $this->mincost = min($this->mincost, $cost);
        $this->maxcost = max($this->maxcost, $cost);
    }

    public function add_progressf($progressf) {
        $this->progressf[] = $progressf;
    }


    // extract information

    public function nodes($klass) {
        $a = array();
        foreach ($this->v as $v)
            if ($v->klass === $klass)
                $a[] = $v;
        return $a;
    }

    public function current_flow() {
        if ($this->source->entryflow !== true)
            return $this->source->entryflow;
        else
            return min(-$this->source->excess, $this->sink->excess);
    }

    public function has_excess() {
        foreach ($this->v as $v)
            if ($v->excess)
                return true;
        return false;
    }

    public function current_excess() {
        $n = 0;
        foreach ($this->v as $v)
            if (!$v->entryflow && $v->excess)
                $n += $v->excess;
        return $n;
    }

    public function current_cost() {
        $cost = 0;
        foreach ($this->e as $e)
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        return $cost;
    }

    private function add_reachable($v, $klass, &$a) {
        if ($v->klass === $klass)
            $a[] = $v;
        else if ($v !== $this->sink) {
            foreach ($v->eout as $e)
                if ($e->flow > 0)
                    $this->add_reachable($e->dst, $klass, $a);
        }
    }

    public function reachable($v, $klass) {
        if (is_string($v))
            $v = $this->vmap[$v];
        $a = array();
        $this->add_reachable($v, $klass, $a);
        return $a;
    }


    // internals

    private function shuffle() {
        // shuffle vertices and edges because edge order affects which
        // circulations we choose; this randomizes the assignment
        shuffle($this->v);
        shuffle($this->e);
        foreach ($this->e as $e) {
            $e->src->eout[] = $e;
            $e->dst->ein[] = $e;
        }
    }


    // Cycle canceling via Bellman-Ford (very slow)

    private function bf_walk($v) {
        $e = $v->link;
        if ($e === null)
            return array(null, null, null, null);
        else if (!$v->linkrev)
            return array($e, false, $e->src);
        else
            return array($e, true, $e->dst);
    }

    private function cyclecancel_iteration() {
        // initialize
        foreach ($this->v as $v) {
            $v->distance = INF;
            $v->link = $v->linkrev = $v->cycle = null;
        }
        $this->source->distance = 0;

        // run Bellman-Ford algorithm
        $more = true;
        for ($iter = 1; $more && $iter < count($this->v); ++$iter) {
            $more = false;
            foreach ($this->e as $i => $e) {
                if ($e->flow < $e->cap) {
                    $xdist = $e->src->distance + $e->cost;
                    if ($e->dst->distance > $xdist) {
                        $e->dst->distance = $xdist;
                        $e->dst->link = $e;
                        $e->dst->linkrev = false;
                        $more = true;
                    }
                }
                if ($e->flow) {
                    $xdist = $e->dst->distance - $e->cost;
                    if ($e->src->distance > $xdist) {
                        $e->src->distance = $xdist;
                        $e->src->link = $e;
                        $e->src->linkrev = true;
                        $more = true;
                    }
                }
            }
        }

        // saturate minimum negative-cost cycles, which must be disjoint
        $any_cycles = false;
        foreach ($this->v as $vi => $v) {
            $xv = $v;
            while ($xv !== null && $xv->cycle === null) {
                $xv->cycle = $v;
                list($e, $erev, $xv) = $this->bf_walk($xv);
            }
            if ($xv !== null && $xv->cycle === $v) {
                $yv = $xv;
                // find available capacity
                $cap = INF;
                do {
                    list($e, $erev, $yv) = $this->bf_walk($yv);
                    $cap = min($cap, $e->residual_cap($erev));
                } while ($yv !== $xv);
                // saturate
                do {
                    list($e, $erev, $yv) = $this->bf_walk($yv);
                    $e->flow += $erev ? -$cap : $cap;
                } while ($yv !== $xv);
                $any_cycles = true;
            }
        }

        return $any_cycles;
    }

    private function cyclecancel_run() {
        // make it a circulation
        $this->add_edge($this->sink, $this->source, count($this->e) * $this->maxcap, -count($this->v) * ($this->maxcost + 1));

        while ($this->cyclecancel_iteration())
            /* nada */;

        array_pop($this->e);
        foreach ($this->e as $i => $e)
            if ($e->flow > 0)
                $e->src->flow += $e->flow;
    }


    // push-relabel: maximum flow only, ignores costs

    private static function pushrelabel_bfs_setdistance($qtail, $v, $dist) {
        $v->distance = $dist;
        $qtail->xlink = $v;
        return $v;
    }

    private function pushrelabel_make_distance() {
        foreach ($this->v as $v) {
            $v->distance = 0;
            $v->xlink = null;
        }
        $qhead = $qtail = $this->sink;
        while ($qhead) {
            $d = $qhead->distance + 1;
            foreach ($qhead->eout as $e)
                if ($e->flow > 0 && $e->dst->distance === 0)
                    $qtail = self::pushrelabel_bfs_setdistance($qtail, $e->dst, $d);
            foreach ($qhead->ein as $e)
                if ($e->flow < $e->cap && $e->src->distance === 0)
                    $qtail = self::pushrelabel_bfs_setdistance($qtail, $e->src, $d);
            $qhead = $qhead->xlink;
        }
    }

    private function pushrelabel_push($e, $erev) {
        $src = $erev ? $e->dst : $e->src;
        $dst = $erev ? $e->src : $e->dst;
        $amt = min($src->excess, $e->residual_cap($erev));
        $e->flow += $erev ? -$amt : $amt;
        $src->excess -= $amt;
        $dst->excess += $amt;
    }

    private function pushrelabel_relabel($v) {
        $h = INF;
        foreach ($v->eout as $e)
            if ($e->flow < $e->cap)
                $h = min($h, $e->dst->distance);
        foreach ($v->ein as $e)
            if ($e->flow > 0)
                $h = min($h, $e->src->distance);
        $v->distance = 1 + $h;
    }

    private function pushrelabel_discharge($v) {
        $nout = count($v->eout);
        $nin = count($v->ein);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $nout + $nin) {
                $this->pushrelabel_relabel($v);
                $v->npos = $notrelabeled = 0;
            } else {
                $erev = $v->npos >= $nout;
                $e = $erev ? $v->ein[$v->npos - $nout] : $v->eout[$v->npos];
                $other = $erev ? $e->src : $e->dst;
                if ($e->residual_cap($erev) > 0
                    && $v->distance == $other->distance + 1)
                    $this->pushrelabel_push($e, $erev);
                else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function pushrelabel_run() {
        $this->maxflow_start_at = microtime(true);

        // initialize preflow
        $this->pushrelabel_make_distance();
        foreach ($this->source->eout as $e) {
            $e->flow = $e->cap;
            $e->dst->excess += $e->cap;
            $e->src->excess -= $e->cap;
        }

        // initialize lists and neighbor position
        $lhead = $ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            if ($v !== $this->source && $v !== $this->sink) {
                $ltail ? ($ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $ltail = $v;
            }
        }

        // relabel-to-front
        $n = 0;
        $l = $lhead;
        $lprev = null;
        $max_height = 2 * count($this->v) - 1;
        $nrelabels = 0;
        while ($l) {
            // check progress
            ++$n;
            if ($n % 32768 == 0)
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMAXFLOW);

            // discharge current vertex
            if ($this->pushrelabel_discharge($l)) {
                ++$nrelabels;
                // global relabeling heuristic is quite useful
                if ($nrelabels % count($this->v) == 0)
                    $this->pushrelabel_make_distance();
                if ($l !== $lhead) {
                    $lprev->link = $l->link;
                    $l->link = $lhead;
                    $lhead = $l;
                }
            }
            assert($l->distance <= $max_height);
            $lprev = $l;
            $l = $l->link;

            // thanks to global relabeling heuristic, may still have active
            // nodes; go one more time through
            if (!$l) {
                $lprev = null;
                for ($l = $lhead; $l && $l->excess == 0; ) {
                    $lprev = $l;
                    $l = $l->link;
                }
            }
        }

        $this->source->entryflow = -$this->source->excess;
        $this->sink->entryflow = -$this->sink->excess;
        $this->source->excess = $this->sink->excess = 0;
        $this->maxflow_end_at = microtime(true);
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMAXFLOW_DONE);
    }


    // cost-scaling push-relabel

    private function cspushrelabel_push($e, $erev) {
        $dst = $erev ? $e->src : $e->dst;
        // push lookahead heuristic
        if ($dst->excess >= 0 && !$dst->has_outgoing_admissible()) {
            $this->cspushrelabel_relabel($dst);
            return;
        }

        $src = $erev ? $e->dst : $e->src;
        $amt = min($src->excess, $e->residual_cap($erev));
        $e->flow += $erev ? -$amt : $amt;
        $src->excess -= $amt;
        $dst->excess += $amt;
        $src->check_outgoing_admissible = $dst->check_outgoing_admissible = null;

        if ($dst->excess > 0 && $dst->link === false) {
            $this->ltail = $this->ltail->link = $dst;
            $dst->link = null;
        }
        //fwrite(STDERR, "push $amt {$src->name} > {$dst->name}\n" . $this->debug_info(true) . "\n");
    }

    private function cspushrelabel_relabel($v) {
        $p = -INF;
        foreach ($v->eout as $e) {
            if ($e->flow < $e->cap)
                $p = max($p, $e->dst->price - $e->cost - $this->epsilon);
            if ($e->flow > 0)
                $e->dst->check_outgoing_admissible = null;
        }
        foreach ($v->ein as $e) {
            if ($e->flow > 0)
                $p = max($p, $e->src->price + $e->cost - $this->epsilon);
            if ($e->flow < $e->cap)
                $e->src->check_outgoing_admissible = null;
        }
        //fwrite(STDERR, "relabel {$v->name} {$v->price}->$p\n");
        if ($p > -INF)
            $v->price = $p;
        else
            $v->price -= $this->epsilon;
        $v->npos = 0;
        $v->check_outgoing_admissible = null;
    }

    private function cspushrelabel_discharge($v) {
        $nout = count($v->eout);
        $nin = count($v->ein);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $nout + $nin) {
                $this->cspushrelabel_relabel($v);
                $notrelabeled = 0;
            } else {
                $erev = $v->npos >= $nout;
                $e = $erev ? $v->ein[$v->npos - $nout] : $v->eout[$v->npos];
                if ($e->residual_cap($erev) > 0
                    && $e->reduced_cost($erev) < 0)
                    $this->cspushrelabel_push($e, $erev);
                else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function cspushrelabel_refine($phaseno, $nphases) {
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_BEGINROUND, $phaseno, $nphases);

        $this->epsilon = $this->epsilon / self::CSPUSHRELABEL_ALPHA;
        foreach ($this->e as $e) {
            if ($e->reduced_cost(false) < 0 && $e->flow < $e->cap) {
                assert($e->reduced_cost(true) >= 0);
                $delta = $e->cap - $e->flow;
                $e->flow = $e->cap;
                $e->dst->excess += $delta;
                $e->src->excess -= $delta;
            } else if ($e->reduced_cost(true) < 0 && $e->flow > 0) {
                $delta = $e->flow;
                $e->flow = 0;
                $e->src->excess += $delta;
                $e->dst->excess -= $delta;
            }
        }

        // initialize lists and neighbor position
        $lhead = $this->ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            $v->check_outgoing_admissible = null;
            if ($v->excess > 0) {
                $this->ltail ? ($this->ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $this->ltail = $v;
            } else
                $v->link = false;
        }

        // relabel-to-front
        $n = 0;
        while ($lhead) {
            // check progress
            ++$n;
            if ($n % 1024 == 0)
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMINCOST_INROUND, $phaseno, $nphases);

            // discharge current vertex
            $this->cspushrelabel_discharge($lhead);
            $l = $lhead->link;
            $lhead->link = false;
            $lhead = $l;
        }
    }

    private function cspushrelabel_run() {
        // get a maximum flow
        $this->pushrelabel_run();

        // refine the maximum flow to achieve min cost
        $this->mincost_start_at = microtime(true);
        $phaseno = $nphases = 0;
        for ($e = $this->maxcost; $e >= 1 / count($this->v); $e /= self::CSPUSHRELABEL_ALPHA)
            ++$nphases;
        $this->epsilon = $this->maxcost;

        while ($this->epsilon >= 1 / count($this->v)) {
            $this->cspushrelabel_refine($phaseno, $nphases);
            ++$phaseno;
        }
        $this->mincost_end_at = microtime(true);

        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_DONE, $phaseno, $nphases);
    }


    public function run() {
        assert(!$this->hasrun);
        $this->hasrun = true;
        $this->shuffle();
        if ($this->mincost == 0 && $this->maxcost == 0)
            return $this->pushrelabel_run();
        else
            return $this->cspushrelabel_run();
    }


    public function reset() {
        if ($this->hasrun) {
            foreach ($this->v as $v) {
                $v->distance = $v->excess = $v->price = 0;
                $v->ein = array();
                $v->eout = array();
            }
            $this->source->entryflow = $this->sink->entryflow = true;
            foreach ($this->e as $e)
                $e->flow = 0;
            $this->maxflow_start_at = $this->maxflow_end_at = null;
            $this->mincost_start_at = $this->mincost_end_at = null;
            $this->hasrun = false;
        }
    }

    public function clear() {
        // break circular references
        foreach ($this->v as $v)
            $v->link = $v->xlink = $v->cycle = $v->ein = $v->eout = null;
        foreach ($this->e as $e)
            $e->src = $e->dst = null;
        $this->v = array();
        $this->e = array();
        $this->vmap = array();
        $this->maxcap = $this->mincost = $this->maxcost = 0;
        $this->source = $this->add_node(".source", ".internal");
        $this->sink = $this->add_node(".sink", ".internal");
        $this->source->entryflow = $this->sink->entryflow = true;
        $this->hasrun = false;
    }

    public function debug_info($only_flow = false) {
        $ex = array();
        $cost = 0;
        foreach ($this->e as $e) {
            if ($e->flow || !$only_flow)
                $ex[] = "{$e->src->name} {$e->dst->name} $e->cap $e->cost $e->flow\n";
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        }
        sort($ex);
        $vx = array();
        foreach ($this->v as $v)
            if ($v->excess)
                $vx[] = "E {$v->name} {$v->excess}\n";
        sort($vx);
        $x = "";
        if ($this->hasrun)
            $x = "total {$e->flow} $cost\n";
        return $x . join("", $ex) . join("", $vx);
    }
}
