<?php
// t_apicounter.php -- HotCRP tests for ContactCounter API rate limiting
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class APICounter_Tester {
    /** @var Conf */
    public $conf;
    /** @var int */
    private $uid;
    /** @var float */
    private $orig_unow;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->uid = $conf->checked_user_by_email("mgbaker@cs.stanford.edu")->contactId;
        $this->orig_unow = Conf::$unow;
    }

    /** Reset the counter row, set the four policy options, and return a fresh
     * counter object using the global (non-per-user) policy.
     * @return ContactCounter */
    private function fresh($w1, $a1, $w2, $a2) {
        $this->conf->qe("delete from ContactCounter where contactId=?", $this->uid);
        $this->conf->set_opt("apiRefreshWindow", $w1);
        $this->conf->set_opt("apiRefreshAmount", $a1);
        $this->conf->set_opt("apiRefreshWindow2", $w2);
        $this->conf->set_opt("apiRefreshAmount2", $a2);
        return $this->conf->fresh_user_by_id($this->uid)->contact_counter();
    }

    /** Render the rate-limit headers into a name=>value map.
     * @return array<string,string> */
    private function ratelimit(ContactCounter $cc) {
        Navigation::headers_reset();
        $cc->api_ratelimit_headers();
        $r = [];
        foreach (Navigation::headers_list() as $h) {
            if (preg_match('/\Ax-ratelimit-(\S+):\s*(.*)\z/', $h, $m)) {
                $r[$m[1]] = $m[2];
            }
        }
        return $r;
    }

    private function cleanup() {
        $this->conf->qe("delete from ContactCounter where contactId=?", $this->uid);
        foreach (["apiRefreshWindow", "apiRefreshAmount", "apiRefreshWindow2", "apiRefreshAmount2"] as $k) {
            $this->conf->set_opt($k, null);
        }
        Conf::set_current_time($this->orig_unow);
    }

    function test_basic_drain_and_refresh() {
        // window 1 = 3 req / 1 s; window 2 generous so it never binds
        $cc = $this->fresh(1000, 3, 1000, 1000000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 3);

        // 4th request is over budget; count is not advanced
        xassert(!$cc->api_account());
        xassert_eqq($cc->apiCount, 3);
        xassert_eqq($this->ratelimit($cc), [
            "limit" => "3", "remaining" => "0", "reset" => "1700000001"
        ]);
        xassert_eqq($cc->api_fail()->status, 429);

        // advance past the window: it refreshes and grants budget again
        Conf::set_current_time(1700000002.0);
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 4);
        $r = $this->ratelimit($cc);
        xassert_eqq($r["limit"], "3");
        xassert_eqq($r["remaining"], "2");      // base advanced to 3; 3+3-4
        xassert_eqq($r["reset"], "1700000003"); // (1700000002000 + 1000) / 1000

        $this->cleanup();
    }

    function test_two_window_binding() {
        // window 1 loose (100 / 10 s), window 2 a tight burst (3 / 1 s)
        $cc = $this->fresh(10000, 100, 1000, 3);
        Conf::set_current_time(1700000000.0);

        // the tighter window is the one reported
        xassert($cc->api_account());
        $r = $this->ratelimit($cc);
        xassert_eqq($r["limit"], "3");
        xassert_eqq($r["remaining"], "2");

        // drain window 2; window 1 still has plenty but window 2 blocks
        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 3);
        xassert(!$cc->api_account());

        $r = $this->ratelimit($cc);
        xassert_eqq($r["limit"], "3");
        xassert_eqq($r["remaining"], "0");
        // reset points at window 2's end, not window 1's
        xassert_eqq($r["reset"], (string) (int) (($cc->apiBaseMtime2 + 1000) / 1000));
        xassert_eqq($cc->api_fail()->status, 429);

        $this->cleanup();
    }

    function test_disabled() {
        // window 1 disabled (amount 0); window 2 generous
        $cc = $this->fresh(1000, 0, 1000, 1000000);
        Conf::set_current_time(1700000000.0);

        xassert(!$cc->api_account());           // blocked from the first request
        xassert_eqq($cc->apiCount, 0);
        xassert_eqq($this->ratelimit($cc), ["limit" => "0"]);
        xassert_eqq($cc->api_fail()->status, 403);

        $this->cleanup();
    }

    function test_unlimited() {
        // window == 0 on both windows: unlimited
        $cc = $this->fresh(0, 5, 0, 5);
        Conf::set_current_time(1700000000.0);

        for ($i = 0; $i !== 20; ++$i) {
            xassert($cc->api_account());
        }
        xassert_eqq($cc->apiCount, 20);
        xassert_eqq($this->ratelimit($cc), ["limit" => "unlimited"]);

        $this->cleanup();
    }

    function test_unlimited_amount1_header() {
        // Regression: window == 0 && amount == 1 is still unlimited. The header
        // must not misreport "remaining: 0" just because used (== 1 post-account)
        // reaches the placeholder amount.
        $cc = $this->fresh(0, 1, 0, 1);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 3);
        xassert_eqq($this->ratelimit($cc), ["limit" => "unlimited"]);

        $this->cleanup();
    }

    function test_cas_reload() {
        // loose limits so accounting itself never blocks
        $cc = $this->fresh(100000, 1000, 100000, 1000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 1);

        // a concurrent writer bumps the row; $cc's in-memory apiCount is now stale
        $this->conf->qe("update ContactCounter set apiCount=5 where contactId=?", $this->uid);

        // the CAS update must miss (where apiCount=1), reload, and retry from 5
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 6);
        xassert_eqq($this->conf->fetch_ivalue("select apiCount from ContactCounter where contactId=?", $this->uid), 6);

        $this->cleanup();
    }

    function test_no_user_disabled() {
        // contactId <= 0 is intentionally disabled, with no DB access
        $cc = new ContactCounter($this->conf, false, 0);
        xassert(!$cc->api_account());
        xassert_eqq($cc->apiCount, 0);
        xassert_eqq($this->ratelimit($cc), ["limit" => "0"]);
        xassert_eqq($cc->api_fail()->status, 403);
    }
}
