<?php
// mincostmaxflow.php -- HotCRP min-cost max-flow
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MinCostMaxFlow_Node {
    public $num;
    public $name;
    public $klass;
    public $flow = 0;
    public $pred = null;
    public $predrev = null;
    public $cycle = null;
    public $ein = array();
    public $eout = array();
    public function __construct($num, $name, $klass) {
        $this->num = $num;
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
};

class MinCostMaxFlow {
    private $v = array();
    private $vmap = array();
    private $e = array();
    private $maxcap = 0;
    private $maxcost = 0;
    private $hasrun = false;

    const VSOURCE = 0;
    const VSINK = 1;

    public function __construct() {
        $this->add_node(".source", ".internal");
        $this->add_node(".sink", ".internal");
    }

    public function add_node($name, $klass) {
        assert(is_string($name) && !isset($this->vmap[$name]));
        $i = count($this->v);
        $this->v[] = new MinCostMaxFlow_Node($i, $name, $klass);
        $this->vmap[$name] = $i;
        return $i;
    }

    public function add_edge($vs, $vd, $cap, $cost) {
        if (is_string($vs))
            $vs = $this->vmap[$vs];
        if (is_string($vd))
            $vd = $this->vmap[$vd];
        assert(is_int($vs) && is_int($vd) && $vs < count($this->v) && $vd < count($this->v));
        // XXX assert(this edge does not exist)
        $ei = count($this->e);
        $this->e[] = new MinCostMaxFlow_Edge($this->v[$vs], $this->v[$vd],
                                             $cap, $cost, 0);
        $this->maxcap = max($this->maxcap, $cap);
        $this->maxcost = max($this->maxcost, $cost);
    }

    public function nodes($klass) {
        $a = array();
        foreach ($this->v as $v)
            if ($v->klass === $klass)
                $a[] = $v;
        return $a;
    }

    private function bf_walk($v) {
        $e = $v->pred;
        if ($e === null)
            return array(null, null, null, null);
        else if (!$v->predrev)
            return array($e, false, $e->cap - $e->flow, $e->src);
        else
            return array($e, true, $e->flow, $e->dst);
    }

    private function bf_iteration() {
        // initialize
        foreach ($this->v as $v) {
            $v->dist = INF;
            $v->pred = $v->predrev = $v->cycle = null;
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
                        $e->dst->pred = $e;
                        $e->dst->predrev = false;
                        $more = true;
                    }
                }
                if ($e->flow) {
                    $xdist = $e->dst->dist - $e->cost;
                    if ($e->src->dist > $xdist) {
                        $e->src->dist = $xdist;
                        $e->src->pred = $e;
                        $e->src->predrev = true;
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
                list($e, $erev, $ecap, $xv) = $this->bf_walk($xv);
            }
            if ($xv !== null && $xv->cycle === $v) {
                $yv = $xv;
                // find available capacity
                $cap = INF;
                do {
                    list($e, $erev, $ecap, $yv) = $this->bf_walk($yv);
                    $cap = min($cap, $ecap);
                } while ($yv !== $xv);
                // saturate
                do {
                    list($e, $erev, $ecap, $yv) = $this->bf_walk($yv);
                    $e->flow += $erev ? -$cap : $cap;
                } while ($yv !== $xv);
                $any_cycles = true;
            }
        }

        set_time_limit(30);
        return $any_cycles;
    }

    public function run() {
        assert(!$this->hasrun);

        // shuffle edges because edge order affects which circulations
        // we choose; this randomizes the assignment
        shuffle($this->e);
        // make it a circulation
        $this->add_edge(self::VSINK, self::VSOURCE, count($this->e) * $this->maxcap, -count($this->v) * ($this->maxcost + 1));
        $this->hasrun = true;

        while ($this->bf_iteration())
            /* nada */;

        foreach ($this->e as $i => $e)
            if ($e->flow > 0) {
                $e->src->flow += $e->flow;
                $e->src->eout[] = $i;
                $e->dst->ein[] = $i;
            }
        unset($e);
    }

    private function add_reachable($v, $klass, &$a) {
        if ($v->klass === $klass)
            $a[] = $v;
        else if ($v->num !== self::VSINK) {
            foreach ($v->eout as $ei)
                $this->add_reachable($this->e[$ei]->dst, $klass, $a);
        }
    }

    public function reachable($v, $klass) {
        if (is_string($v))
            $v = $this->vmap[$v];
        if (is_int($v))
            $v = $this->v[$v];
        $a = array();
        $this->add_reachable($v, $klass, $a);
        return $a;
    }

    public function reset() {
        if ($this->hasrun) {
            array_pop($this->e);
            foreach ($this->v as $v) {
                $v->flow = 0;
                $v->ein = array();
                $v->eout = array();
            }
            foreach ($this->e as $e)
                $e->flow = 0;
            $this->hasrun = false;
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
            $e = $this->e[count($this->e) - 1];
            $cost -= $e->flow * $e->cost;
            $x = "total {$e->flow} $cost\n";
        }
        return $x . join("", $ex);
    }
}
