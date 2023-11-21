<?php
// t_events.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Events_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_events() {
        $u_mgbaker = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $evs = new PaperEvents($u_mgbaker);
        xassert_gt(count($evs->events(Conf::$now, 10)), 0);

        $u_diot = $this->conf->checked_user_by_email("christophe.diot@sophia.inria.fr");
        $evs = new PaperEvents($u_diot);
        xassert_eqq(count($evs->events(Conf::$now, 10)), 0);
    }
}
