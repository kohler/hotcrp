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
    public $ein = array();
    public $eout = array();
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
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
        assert($src->height == $dst->height + 1 && $amt > 0);
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
        if ($v->excess == 0)
            return;
        $nout = count($v->eout);
        $nin = count($v->ein);
        while ($v->excess > 0) {
            if ($v->npos == $nout + $nin) {
                $this->pushrelabel_relabel($v);
                $v->npos = 0;
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
            $old_height = $l->height;
            $this->pushrelabel_discharge($l);
            if ($l->height > $old_height && $l !== $lhead) {
                $lprev->link = $l->link;
                $l->link = $lhead;
                $lhead = $l;
            }
            $lprev = $l;
            $l = $l->link;
        }
    }


    public function run() {
        assert(!$this->hasrun);
        $this->hasrun = true;
        $this->shuffle();
        if ($this->mincost != 0 || $this->maxcost != 0)
            return $this->cyclecancel_run();
        else
            return $this->pushrelabel_run();
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
                $v->flow = $v->height = $v->excess = 0;
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
