<?php
// t_mincostmaxflow.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class MinCostMaxFlow_Tester {
    /** @param MinCostMaxFlow $m
     * @return string */
    function mcmf_assignment_text($m) {
        $a = [];
        foreach ($m->nodes("u") as $u) {
            foreach ($m->downstream($u, "p") as $p) {
                $a[] = "$u->name $p->name\n";
            }
        }
        sort($a);
        return join("", $a);
    }

    function test_one_result() {
        $m = new MinCostMaxFlow;
        foreach (["u0", "u1", "u2"] as $x) {
            $m->add_node($x, "u");
            $m->add_edge(".source", $x, 1, 0);
        }
        foreach (["p0", "p1", "p2"] as $x) {
            $m->add_node($x, "p");
            $m->add_edge($x, ".sink", 1, 0);
        }
        $m->add_edge("u0", "p0", 1, 0);
        $m->add_edge("u0", "p1", 1, -1);
        $m->add_edge("u1", "p0", 1, 0);
        $m->add_edge("u1", "p1", 1, 0);
        $m->add_edge("u1", "p2", 1, 0);
        $m->add_edge("u2", "p0", 1, 0);
        $m->add_edge("u2", "p2", 1, 1);

        // test that this graph "always" produces the same assignment
        foreach (range(100, 921384, 1247) as $seed) {
            $m->reset();
            srand($seed); // the shuffle() uses this seed
            $m->shuffle();
            $m->run();
            xassert_eqq($this->mcmf_assignment_text($m), "u0 p1\nu1 p2\nu2 p0\n");
            if ($this->mcmf_assignment_text($m) !== "u0 p1\nu1 p2\nu2 p0\n") {
                fwrite(STDERR, "-- bad seed $seed\n");
            }
        }

        Xassert::will_print();
        fwrite(STDERR, "- Phase 1 complete.\n");
    }

    function test_full_range_of_results() {
        $m = new MinCostMaxFlow;
        foreach (["u0", "u1", "u2"] as $x) {
            $m->add_node($x, "u");
            $m->add_edge(".source", $x, 1, 0);
        }
        foreach (["p0", "p1", "p2"] as $x) {
            $m->add_node($x, "p");
            $m->add_edge($x, ".sink", 1, 0);
        }
        foreach (["u0", "u1", "u2"] as $x) {
            foreach (["p0", "p1", "p2"] as $y) {
                $m->add_edge($x, $y, 1, 1);
            }
        }
        $assignments = [];
        foreach (range(100, 921384, 1247) as $seed) {
            $m->reset();
            srand($seed); // the shuffle() uses this seed
            $m->shuffle();
            $m->run();
            $assignments[$this->mcmf_assignment_text($m)] = true;
        }
        $assignments = array_keys($assignments);
        sort($assignments);
        xassert_eqq(count($assignments), 6);
        xassert_eqq($assignments[0], "u0 p0\nu1 p1\nu2 p2\n");
        xassert_eqq($assignments[1], "u0 p0\nu1 p2\nu2 p1\n");
        xassert_eqq($assignments[2], "u0 p1\nu1 p0\nu2 p2\n");
        xassert_eqq($assignments[3], "u0 p1\nu1 p2\nu2 p0\n");
        xassert_eqq($assignments[4], "u0 p2\nu1 p0\nu2 p1\n");
        xassert_eqq($assignments[5], "u0 p2\nu1 p1\nu2 p0\n");

        Xassert::will_print();
        fwrite(STDERR, "- Phase 2 complete.\n");
    }

    function test_several_results() {
        $m = new MinCostMaxFlow;
        foreach (["u0", "u1", "u2"] as $x) {
            $m->add_node($x, "u");
            $m->add_edge(".source", $x, 1, 0);
        }
        foreach (["p0", "p1", "p2"] as $x) {
            $m->add_node($x, "p");
            $m->add_edge($x, ".sink", 1, 0);
        }
        foreach (["u0", "u1", "u2"] as $x) {
            foreach (["p0", "p1", "p2"] as $y) {
                $c = 1;
                if (($x === "u0" || $x === "u1") && ($y === "p0" || $y === "p1")) {
                    $c = 0;
                }
                $m->add_edge($x, $y, 1, $c);
            }
        }
        $assignments = [];
        foreach (range(100, 921384, 1247) as $seed) {
            $m->reset();
            srand($seed); // the shuffle() uses this seed
            $m->shuffle();
            $m->run();
            $assignments[$this->mcmf_assignment_text($m)] = true;
        }
        $assignments = array_keys($assignments);
        sort($assignments);
        xassert_eqq(count($assignments), 2);
        xassert_eqq($assignments[0], "u0 p0\nu1 p1\nu2 p2\n");
        xassert_eqq($assignments[1], "u0 p1\nu1 p0\nu2 p2\n");

        Xassert::will_print();
        fwrite(STDERR, "- Phase 3 complete.\n");
    }

    function test_zero_preference_results() {
        // (4) all zero preferences => all possible results; this uses push-relabel
        $m = new MinCostMaxFlow;
        foreach (["u0", "u1", "u2"] as $x) {
            $m->add_node($x, "u");
            $m->add_edge(".source", $x, 1);
        }
        foreach (["p0", "p1", "p2"] as $x) {
            $m->add_node($x, "p");
            $m->add_edge($x, ".sink", 1);
        }
        foreach (["u0", "u1", "u2"] as $x) {
            foreach (["p0", "p1", "p2"] as $y) {
                $m->add_edge($x, $y, 1);
            }
        }
        $assignments = [];
        foreach (range(100, 921384, 1247) as $seed) {
            $m->reset();
            srand($seed); // the shuffle() uses this seed
            $m->shuffle();
            $m->run();
            $assignments[$this->mcmf_assignment_text($m)] = true;
        }
        $assignments = array_keys($assignments);
        sort($assignments);
        xassert_eqq(count($assignments), 6);
        xassert_eqq($assignments[0], "u0 p0\nu1 p1\nu2 p2\n");
        xassert_eqq($assignments[1], "u0 p0\nu1 p2\nu2 p1\n");
        xassert_eqq($assignments[2], "u0 p1\nu1 p0\nu2 p2\n");
        xassert_eqq($assignments[3], "u0 p1\nu1 p2\nu2 p0\n");
        xassert_eqq($assignments[4], "u0 p2\nu1 p0\nu2 p1\n");
        xassert_eqq($assignments[5], "u0 p2\nu1 p1\nu2 p0\n");

        Xassert::will_print();
        fwrite(STDERR, "- Phase 4 complete.\n");
    }

    function test_dimacs() {
        $m = new MinCostMaxFlow;
        $m->parse_dimacs("n 1 s
n 2 t
c ninfo 3 u0 u
c ninfo 4 u1 u
c ninfo 5 u2 u
c ninfo 6 p0 p
c ninfo 7 p1 p
c ninfo 8 p2 p
a 1 3 1
a 1 4 1
a 1 5 1
a 3 6 0 1 0
a 3 7 0 1 -1
a 4 6 0 1 0
a 4 7 0 1 0
a 4 8 0 1 0
a 5 6 0 1 0
a 5 8 0 1 1
a 6 2 1
a 7 2 1
a 8 2 1");
        $m->run();
        xassert_eqq($this->mcmf_assignment_text($m), "u0 p1\nu1 p2\nu2 p0\n");
        xassert_eqq(preg_replace('/^c[^\n]*\n/m', "", $m->mincost_dimacs_output()),
                   "s -1
f 1 3 1
f 1 4 1
f 1 5 1
f 3 7 1
f 4 8 1
f 5 6 1
f 6 2 1
f 7 2 1
f 8 2 1\n");
    }
}
