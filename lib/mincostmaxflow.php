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
    public $n_outgoing_admissible = 0;
    public $e = array();
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
    }
    public function check_excess() {
        $x = $this->entryflow;
        foreach ($this->e as $e)
            $x += $e->flow_to($this);
        if ($x != $this->excess)
            fwrite(STDERR, "{$this->name}: bad excess e{$this->excess}, have $x\n");
        assert($x == $this->excess);
    }
    public function count_outgoing_admissible() {
        $n = 0;
        foreach ($this->e as $e)
            if ($e->is_price_admissible_from($this))
                ++$n;
        return $n;
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
    public function other($v) {
        return $v === $this->src ? $this->dst : $this->src;
    }
    public function flow_to($v) {
        return $v === $this->dst ? $this->flow : -$this->flow;
    }
    public function residual_cap($isrev) {
        return $isrev ? $this->flow : $this->cap - $this->flow;
    }
    public function residual_cap_from($v) {
        return $v === $this->src ? $this->cap - $this->flow : $this->flow;
    }
    public function residual_cap_to($v) {
        return $v === $this->src ? $this->flow : $this->cap - $this->flow;
    }
    public function is_distance_admissible_from($v) {
        if ($v === $this->src)
            return $this->flow < $this->cap && $v->distance == $this->dst->distance + 1;
        else
            return $this->flow > 0 && $v->distance == $this->src->distance + 1;
    }
    public function is_price_admissible_from($v) {
        $c = $this->cost + $this->src->price - $this->dst->price;
        if ($v === $this->src)
            return $this->flow < $this->cap && $c < 0;
        else
            return $this->flow > 0 && $c > 0;
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
        assert($vs !== $this->sink && $vd !== $this->source);
        // XXX assert(this edge does not exist)
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
            foreach ($v->e as $e)
                if ($e->src === $v && $e->flow > 0)
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
            $e->src->e[] = $e;
            $e->dst->e[] = $e;
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
        $this->e[] = new MinCostMaxFlow_Edge($this->sink, $this->source, count($this->e) * $this->maxcap, -count($this->v) * ($this->maxcost + 1));

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
            foreach ($qhead->e as $e)
                if ($e->residual_cap_to($qhead) > 0
                    && $e->other($qhead)->distance === 0)
                    $qtail = self::pushrelabel_bfs_setdistance($qtail, $e->other($qhead), $d);
            $qhead = $qhead->xlink;
        }
        // It's important to keep the source a little further away
        // from the other nodes; we don't want to push flow back there!
        ++$this->source->distance;
    }

    private function pushrelabel_push_from($e, $v) {
        $amt = min($v->excess, $e->residual_cap_from($v));
        $amt = ($v == $e->dst ? -$amt : $amt);
        //fwrite(STDERR, "push {$amt} {$e->src->name}@{$e->src->distance} -> {$e->dst->name}@{$e->dst->distance}\n");
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
    }

    private function pushrelabel_relabel($v) {
        $d = INF;
        foreach ($v->e as $e)
            if ($e->residual_cap_from($v) > 0)
                $d = min($d, $e->other($v)->distance + 1);
        //fwrite(STDERR, "relabel {$v->name}@{$v->distance}->{$d}\n");
        $v->distance = $d;
        $v->npos = 0;
    }

    private function pushrelabel_discharge($v) {
        $ne = count($v->e);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $ne) {
                $this->pushrelabel_relabel($v);
                $notrelabeled = 0;
            } else {
                $e = $v->e[$v->npos];
                if ($e->is_distance_admissible_from($v))
                    $this->pushrelabel_push_from($e, $v);
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
        foreach ($this->source->e as $e) {
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
        $max_distance = 2 * count($this->v) - 1;
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
            assert($l->distance <= $max_distance);
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

    private function cspushrelabel_push_from($e, $v) {
        $dst = $e->other($v);
        // push lookahead heuristic
        if ($dst->excess >= 0 && !$dst->n_outgoing_admissible) {
            $this->cspushrelabel_relabel($dst);
            return;
        }

        $amt = min($v->excess, $e->residual_cap_from($v));
        $amt = ($e->src === $v ? $amt : -$amt);
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
        if (!$e->residual_cap_from($v))
            --$v->n_outgoing_admissible;

        if ($dst->excess > 0 && $dst->link === false) {
            $this->ltail = $this->ltail->link = $dst;
            $dst->link = null;
        }
        //fwrite(STDERR, "push $amt {$src->name} > {$dst->name}\n" . $this->debug_info(true) . "\n");
    }

    private function cspushrelabel_relabel($v) {
        // calculate new price
        $p = -INF;
        foreach ($v->e as $e)
            if ($e->src === $v && $e->flow < $e->cap)
                $p = max($p, $e->dst->price - $e->cost - $this->epsilon);
            else if ($e->dst === $v && $e->flow > 0)
                $p = max($p, $e->src->price + $e->cost - $this->epsilon);
        $p_delta = $p > -INF ? $p - $v->price : -$this->epsilon;
        $v->price += $p_delta;

        // start over on arcs
        $v->npos = 0;

        // adjust n_outgoing_admissible counts
        foreach ($v->e as $e) {
            $c = $e->cost + $e->src->price - $e->dst->price;
            $old_c = $c + ($e->src === $v ? -$p_delta : $p_delta);
            if (($c < 0) !== ($old_c < 0) && $e->flow < $e->cap)
                $e->src->n_outgoing_admissible += $c < 0 ? 1 : -1;
            if (($c > 0) !== ($old_c > 0) && $e->flow > 0)
                $e->dst->n_outgoing_admissible += $c > 0 ? 1 : -1;
        }
    }

    private function cspushrelabel_discharge($v) {
        $ne = count($v->e);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $ne) {
                $this->cspushrelabel_relabel($v);
                $notrelabeled = 0;
            } else {
                $e = $v->e[$v->npos];
                if ($e->is_price_admissible_from($v))
                    $this->cspushrelabel_push_from($e, $v);
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

        // saturate negative-cost arcs
        foreach ($this->v as $v)
            if ($v->n_outgoing_admissible) {
                foreach ($v->e as $e)
                    if ($e->is_price_admissible_from($v)) {
                        $delta = ($e->src === $v ? $e->cap : 0) - $e->flow;
                        $e->flow += $delta;
                        $e->dst->excess += $delta;
                        $e->src->excess -= $delta;
                        --$v->n_outgoing_admissible;
                    }
            }

        // initialize lists and neighbor position
        $lhead = $this->ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
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

        foreach ($this->v as $v)
            $v->n_outgoing_admissible = $v->count_outgoing_admissible();

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
                $v->e = array();
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
            $v->link = $v->xlink = $v->cycle = $v->e = null;
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
