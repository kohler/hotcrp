<?php
// test03.php -- HotCRP min-cost max-flow tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");

function mcmf_assignment_text($m) {
    $a = array();
    foreach ($m->nodes("user") as $u)
        foreach ($m->reachable($u, "paper") as $p)
            $a[] = "$u->name $p->name\n";
    sort($a);
    return join("", $a);
}


// (1) one possible result

$m = new MinCostMaxFlow;
foreach (array("u0", "u1", "u2") as $x) {
    $m->add_node($x, "user");
    $m->add_edge(".source", $x, 1, 0);
}
foreach (array("p0", "p1", "p2") as $x) {
    $m->add_node($x, "paper");
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
    $m->run();
    assert_eqq(mcmf_assignment_text($m), "u0 p1\nu1 p2\nu2 p0\n");
}


// (2) no preferences =>  all possible results

$m = new MinCostMaxFlow;
foreach (array("u0", "u1", "u2") as $x) {
    $m->add_node($x, "user");
    $m->add_edge(".source", $x, 1, 0);
}
foreach (array("p0", "p1", "p2") as $x) {
    $m->add_node($x, "paper");
    $m->add_edge($x, ".sink", 1, 0);
}
foreach (array("u0", "u1", "u2") as $x)
    foreach (array("p0", "p1", "p2") as $y)
        $m->add_edge($x, $y, 1, 0);
$assignments = array();
foreach (range(100, 921384, 1247) as $seed) {
    $m->reset();
    srand($seed); // the shuffle() uses this seed
    $m->run();
    $assignments[mcmf_assignment_text($m)] = true;
}
$assignments = array_keys($assignments);
sort($assignments);
assert_eqq(count($assignments), 6);
assert_eqq($assignments[0], "u0 p0\nu1 p1\nu2 p2\n");
assert_eqq($assignments[1], "u0 p0\nu1 p2\nu2 p1\n");
assert_eqq($assignments[2], "u0 p1\nu1 p0\nu2 p2\n");
assert_eqq($assignments[3], "u0 p1\nu1 p2\nu2 p0\n");
assert_eqq($assignments[4], "u0 p2\nu1 p0\nu2 p1\n");
assert_eqq($assignments[5], "u0 p2\nu1 p1\nu2 p0\n");


// (3) several possible results

$m = new MinCostMaxFlow;
foreach (array("u0", "u1", "u2") as $x) {
    $m->add_node($x, "user");
    $m->add_edge(".source", $x, 1, 0);
}
foreach (array("p0", "p1", "p2") as $x) {
    $m->add_node($x, "paper");
    $m->add_edge($x, ".sink", 1, 0);
}
foreach (array("u0", "u1", "u2") as $x)
    foreach (array("p0", "p1", "p2") as $y) {
        $c = 1;
        if (($x === "u0" || $x === "u1") && ($y === "p0" || $y === "p1"))
            $c = 0;
        $m->add_edge($x, $y, 1, $c);
    }
$assignments = array();
foreach (range(100, 921384, 1247) as $seed) {
    $m->reset();
    srand($seed); // the shuffle() uses this seed
    $m->run();
    $assignments[mcmf_assignment_text($m)] = true;
}
$assignments = array_keys($assignments);
sort($assignments);
assert_eqq(count($assignments), 2);
assert_eqq($assignments[0], "u0 p0\nu1 p1\nu2 p2\n");
assert_eqq($assignments[1], "u0 p1\nu1 p0\nu2 p2\n");


xassert_exit();
