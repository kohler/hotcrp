<?php
// t_invariants.php -- Tester that checks HotCRP invariants
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Invariants_Tester {
    /** @var Conf */
    public $conf;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
    }

    function test_invariants() {
        xassert(ConfInvariants::test_all($this->conf));
    }
}
