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
    public $npos = null;
    public $cycle = null;
    public $height = 0;
    public $excess = 0;
    public $price = 0;
    public $ein = array();
    public $eout = array();
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
    }
    public function check_excess() {
        $x = 0;
        foreach ($this->ein as $e)
            $x += $e->flow;
        foreach ($this->eout as $e)
            $x -= $e->flow;
        if ($x != $this->excess)
            fwrite(STDERR, "{$this->name}: bad excess e{$this->excess}, have $x\n");
        assert($x == $this->excess);
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
    private $vmap = array();
    private $source;
    private $sink;
    private $e = array();
    private $maxcap = 0;
    private $mincost = 0;
    private $maxcost = 0;
    private $hasrun = false;
    private $hascirculation = false;
    // cspushrelabel state
    private $epsilon;
    private $ltail;

    public function __construct() {
        $this->source = $this->add_node(".source", ".internal");
        $this->sink = $this->add_node(".sink", ".internal");
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
        $this->e[] = new MinCostMaxFlow_Edge($vs, $vd, $cap, $cost, 0);
        $this->maxcap = max($this->maxcap, $cap);
        $this->mincost = min($this->mincost, $cost);
        $this->maxcost = max($this->maxcost, $cost);
    }

    public function nodes($klass) {
        $a = array();
        foreach ($this->v as $v)
            if ($v->klass === $klass)
                $a[] = $v;
        return $a;
    }

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


    // Cycle canceling via Bellman-Ford

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
            $v->dist = INF;
            $v->link = $v->linkrev = $v->cycle = null;
        }
        $this->v[0]->dist = 0;

        // run Bellman-Ford algorithm
        $more = true;
        for ($iter = 1; $more && $iter < count($this->v); ++$iter) {
            $more = false;
            foreach ($this->e as $i => $e) {
                if ($e->flow < $e->cap) {
                    $xdist = $e->src->dist + $e->cost;
                    if ($e->dst->dist > $xdist) {
                        $e->dst->dist = $xdist;
                        $e->dst->link = $e;
                        $e->dst->linkrev = false;
                        $more = true;
                    }
                }
                if ($e->flow) {
                    $xdist = $e->dst->dist - $e->cost;
                    if ($e->src->dist > $xdist) {
                        $e->src->dist = $xdist;
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

        set_time_limit(30);
        return $any_cycles;
    }

    private function cyclecancel_run() {
        // make it a circulation
        $this->add_edge($this->sink, $this->source, count($this->e) * $this->maxcap, -count($this->v) * ($this->maxcost + 1));
        $this->hascirculation = true;

        while ($this->cyclecancel_iteration())
            /* nada */;

        foreach ($this->e as $i => $e)
            if ($e->flow > 0)
                $e->src->flow += $e->flow;
    }


    // push-relabel: maximum flow only, ignores costs

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
                $h = min($h, $e->dst->height);
        foreach ($v->ein as $e)
            if ($e->flow > 0)
                $h = min($h, $e->src->height);
        assert($v->height <= $h);
        $v->height = 1 + $h;
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
                    && $v->height == $other->height + 1)
                    $this->pushrelabel_push($e, $erev);
                else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function pushrelabel_run() {
        // initialize preflow
        $this->source->height = count($this->v);
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
        $l = $lhead;
        $lprev = null;
        while ($l) {
            if ($this->pushrelabel_discharge($l) && $l !== $lhead) {
                $lprev->link = $l->link;
                $l->link = $lhead;
                $lhead = $l;
            }
            $lprev = $l;
            $l = $l->link;
        }
    }


    // cost-scaling push-relabel

    private function cspushrelabel_relabel($v) {
        $p = -INF;
        foreach ($v->eout as $e)
            if ($e->flow < $e->cap) {
                assert($e->reduced_cost(false) >= 0);
                $p = max($p, $e->dst->price - $e->cost - $this->epsilon);
            }
        foreach ($v->ein as $e)
            if ($e->flow > 0) {
                assert($e->reduced_cost(true) >= 0);
                $p = max($p, $e->src->price + $e->cost - $this->epsilon);
            }
        $v->price = $p;
        fwrite(STDERR, "{$v->name} relabel P{$v->price} e{$v->excess}\n");
    }

    private function cspushrelabel_discharge($v) {
        $nout = count($v->eout);
        $nin = count($v->ein);
        $notrelabeled = 1;
        while ($v->excess > 0) {
            if ($v->npos == $nout + $nin) {
                $this->cspushrelabel_relabel($v);
                $v->check_excess();
                $v->npos = $notrelabeled = 0;
            } else {
                $erev = $v->npos >= $nout;
                $e = $erev ? $v->ein[$v->npos - $nout] : $v->eout[$v->npos];
                $other = $erev ? $e->src : $e->dst;
                fwrite(STDERR, " ... {$e->src->name}P{$e->src->price} {$e->dst->name}P{$e->dst->price} {$e->cap} {$e->cost} {$e->flow} / " . $e->residual_cap($erev) . " " . $e->reduced_cost($erev) . "\n");
                if ($e->residual_cap($erev) > 0
                    && $e->reduced_cost($erev) < 0) {
                    $this->pushrelabel_push($e, $erev);
                    if ($other->excess > 0 && $other->link === false) {
                        $this->ltail = $this->ltail->link = $other;
                        $other->link = null;
                    }
                } else
                    ++$v->npos;
            }
        }
        return !$notrelabeled;
    }

    private function cspushrelabel_refine() {
        $this->epsilon = $this->epsilon / 4; /* Goldberg has 12 */
        foreach ($this->e as $e) {
            if ($e->reduced_cost(false) < 0 && $e->flow < $e->cap) {
                assert($e->reduced_cost(true) >= 0);
                $delta = $e->cap - $e->flow;
                $e->flow = $e->cap;
                $e->dst->excess += $delta;
                $e->src->excess -= $delta;
                fwrite(STDERR, "moving $delta {$e->src->name} > {$e->dst->name}\n");
            } else if ($e->reduced_cost(true) < 0 && $e->flow) {
                $delta = $e->flow;
                $e->flow = 0;
                $e->src->excess += $delta;
                $e->dst->excess -= $delta;
                fwrite(STDERR, "moving $delta {$e->dst->name}.e{$e->dst->excess} > {$e->src->name}.e{$e->src->excess}\n");
            }
        }
        foreach ($this->v as $v)
            $v->check_excess();

        // initialize lists and neighbor position
        $lhead = $this->ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            if ($v === $this->source || $v === $this->sink)
                $v->link = null;
            else if ($v->excess > 0) {
                $this->ltail ? ($this->ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $this->ltail = $v;
            } else
                $v->link = false;
        }

        // relabel-to-front
        while ($lhead) {
            fwrite(STDERR, ".");
            $this->cspushrelabel_discharge($lhead);
            $l = $lhead->link;
            $lhead->link = false;
            $lhead = $l;
        }

        fwrite(STDERR, "!\n" . $this->debug_info() . "\n");
        foreach ($this->v as $v) {
            fwrite(STDERR, "{$v->name}.e{$v->excess} ");
            $v->check_excess();
        }
        fwrite(STDERR, "\n");
    }

    private function cspushrelabel_run() {
        $this->epsilon = $this->maxcost;
        $this->pushrelabel_run();
        fwrite(STDERR, "====\n" . $this->debug_info() . "\n");
        while ($this->epsilon >= 1 / count($this->v))
            $this->cspushrelabel_refine();
    }


    public function run() {
        assert(!$this->hasrun);
        $this->hasrun = true;
        $this->shuffle();
        if ($this->mincost == 0 && $this->maxcost == 0)
            return $this->pushrelabel_run();
        else
            return $this->cyclecancel_run();
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

    public function reset() {
        if ($this->hasrun) {
            if ($this->hascirculation)
                array_pop($this->e);
            foreach ($this->v as $v) {
                $v->flow = $v->height = $v->excess = $v->price = 0;
                $v->ein = array();
                $v->eout = array();
            }
            foreach ($this->e as $e)
                $e->flow = 0;
            $this->hasrun = $this->hascirculation = false;
        }
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
        $x = "";
        if ($this->hasrun) {
            if ($this->hascirculation) {
                $e = $this->e[count($this->e) - 1];
                $cost -= $e->flow * $e->cost;
            }
            $x = "total {$e->flow} $cost\n";
        }
        return $x . join("", $ex);
    }
}
