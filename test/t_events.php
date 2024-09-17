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

        $u_diot = $this->conf->checked_user_by_email("ojuelegba@gmail.com");
        $evs = new PaperEvents($u_diot);
        foreach ($evs->events(Conf::$now, 10) as $x) {
            error_log(Conf::$now . " " . json_encode($x));
        }
        xassert_eqq(count($evs->events(Conf::$now, 10)), 0);
    }
}
