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

    function test_watching_includes_managed_papers() {
        $u_chair = $this->conf->checked_user_by_email("chair@_.com");
        $u_marina = $this->conf->checked_user_by_email("marina@poema.ru");
        $pid = 10;
        xassert_eqq($this->conf->checked_paper_by_id($pid)->managerContactId, 0);
        xassert_not_in_eqq($pid, $u_chair->paper_set(["myWatching" => true])->paper_ids());
        xassert_not_in_eqq($pid, $u_marina->paper_set(["myWatching" => true])->paper_ids());

        // a chair who manages a paper watches it
        xassert_assign($u_chair, "paper,action,user\n{$pid},manager,chair@_.com\n");
        $u_chair = $this->conf->fresh_user_by_email("chair@_.com");
        xassert_in_eqq($pid, $u_chair->paper_set(["myWatching" => true])->paper_ids());

        // so does a PC member who manages a paper
        xassert_assign($u_chair, "paper,action,user\n{$pid},manager,marina@poema.ru\n");
        $u_marina = $this->conf->fresh_user_by_email("marina@poema.ru");
        xassert_in_eqq($pid, $u_marina->paper_set(["myWatching" => true])->paper_ids());

        xassert_assign($u_chair, "paper,action\n{$pid},clearmanager\n");
    }
}
