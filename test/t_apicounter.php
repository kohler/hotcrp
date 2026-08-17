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

    /** @return int */
    private function count_in_db() {
        return (int) $this->conf->fetch_ivalue(
            "select apiCount from ContactCounter where contactId=?", $this->uid);
    }

    private function cleanup() {
        // drop any charge still waiting on shutdown, so it cannot land on a
        // later test's row
        $this->conf->call_shutdown_function("ContactCounterFlush");
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
        xassert_eqq($cc->api_count(), 3);

        // 4th request is over budget; count is not advanced
        xassert(!$cc->api_account());
        xassert_eqq($cc->api_count(), 3);
        xassert_eqq($this->ratelimit($cc), [
            "limit" => "3", "remaining" => "0", "reset" => "1700000001"
        ]);
        xassert_eqq($cc->api_fail()->status, 429);

        // advance past the window: it refreshes and grants budget again
        Conf::set_current_time(1700000002.0);
        xassert($cc->api_account());
        xassert_eqq($cc->api_count(), 4);
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
        xassert_eqq($cc->api_count(), 3);
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
        xassert_eqq($cc->api_count(), 0);
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
        xassert_eqq($cc->api_count(), 20);
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
        xassert_eqq($cc->api_count(), 3);
        xassert_eqq($this->ratelimit($cc), ["limit" => "unlimited"]);

        $this->cleanup();
    }

    /** `ensure` loads once. It used to reload on every call, which a deferred
     * charge would not survive. */
    function test_ensure_loads_once() {
        $cc = $this->fresh(100000, 1000, 100000, 1000);
        Conf::set_current_time(1700000000.0);

        $cc->ensure();
        xassert($cc->is_loaded);
        $this->conf->qe("update ContactCounter set apiCount=42 where contactId=?", $this->uid);
        $cc->ensure();
        xassert_eqq($cc->api_count(), 0);

        $this->cleanup();
    }

    /** A reload refreshes every database-backed field and leaves this
     * request's unsaved charge alone — the two are tracked apart precisely so
     * `sensitive_search_account`, which invalidates after writing blind,
     * cannot cost a half-finished request its accounting. */
    function test_reload_keeps_pending_charge() {
        $cc = $this->fresh(100000, 1000, 100000, 1000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        $cc->api_charge(4);
        xassert_eqq($cc->api_count(), 5);
        xassert_eqq($cc->apiCount, 0);      // nothing saved yet

        // another request's charge lands, and this object is invalidated
        $this->conf->qe("update ContactCounter set apiCount=30 where contactId=?", $this->uid);
        $cc->sensitive_search_account();
        xassert(!$cc->is_loaded);

        // the reload takes their 30 and keeps our 5
        xassert($cc->api_account());
        xassert_eqq($cc->apiCount, 30);
        xassert_eqq($cc->api_count(), 36);

        $this->conf->call_shutdown_function("ContactCounterFlush");
        xassert_eqq($this->count_in_db(), 36);
        // the row records the window as this request opened it, not as the
        // count happened to stand when the charge was written
        xassert_eqq((int) $this->conf->fetch_ivalue(
            "select apiBase from ContactCounter where contactId=?", $this->uid),
            $cc->apiBase);

        $this->cleanup();
    }

    /** `api_account($cost)` admits a request only if the whole cost fits in
     * the window; `$count >= $base + $amount` is the `$cost == 1` case of it. */
    function test_account_cost_must_fit() {
        $cc = $this->fresh(100000, 3, 100000, 1000000);
        Conf::set_current_time(1700000000.0);

        // exactly filling the window is allowed
        xassert($cc->api_account(3));
        xassert_eqq($cc->api_count(), 3);
        // and nothing more fits
        xassert(!$cc->api_account());
        xassert_eqq($cc->api_count(), 3);

        // a cost that overruns what is left is refused, and charges nothing
        $cc = $this->fresh(100000, 3, 100000, 1000000);
        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert(!$cc->api_account(2));
        xassert_eqq($cc->api_count(), 2);
        xassert($cc->api_account(1));       // but one still fits
        xassert_eqq($cc->api_count(), 3);

        $this->cleanup();
    }

    /** A cost larger than a whole window is admitted as the window opens,
     * rather than being refused forever: the budget already tolerates overshoot
     * from `api_charge`, and a permanent refusal has no Retry-After to offer. */
    function test_account_cost_larger_than_window() {
        $cc = $this->fresh(1000, 3, 100000, 1000000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account(10));
        xassert_eqq($cc->api_count(), 10);
        xassert(!$cc->api_account());       // well over budget now

        // one such request per window, which is the pushback that matters
        Conf::set_current_time(1700000002.0);
        xassert($cc->api_account(10));
        xassert_eqq($cc->api_count(), 20);
        xassert(!$cc->api_account());

        $this->cleanup();
    }

    /** Calling `api_account` more than once in a request is not what the API
     * path does, but it must still behave: a refusal leaves the counter
     * exactly as it found it, so a later call sees the same state. */
    function test_repeated_account_refusal_is_inert() {
        // window 1 expires constantly, window 2 is the one that binds
        $cc = $this->fresh(1, 1000, 100000, 3);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert($cc->api_account());

        // window 1 is now due for a refresh, and window 2 is out of budget
        Conf::set_current_time(1700000001.0);
        $state = [$cc->apiBase, $cc->apiBaseMtime, $cc->apiBase2,
                  $cc->apiBaseMtime2, $cc->api_count()];
        xassert(!$cc->api_account());
        xassert_eqq([$cc->apiBase, $cc->apiBaseMtime, $cc->apiBase2,
                     $cc->apiBaseMtime2, $cc->api_count()], $state);

        // and a repeat refusal is equally inert
        xassert(!$cc->api_account());
        xassert_eqq([$cc->apiBase, $cc->apiBaseMtime, $cc->apiBase2,
                     $cc->apiBaseMtime2, $cc->api_count()], $state);

        $this->cleanup();
    }

    /** A request that reopens a window more than once still guards its write
     * on the mtime the row actually holds, so the reset is not lost. */
    function test_repeated_account_reopens_window_against_the_row() {
        $cc = $this->fresh(1000, 3, 100000, 1000000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        Conf::set_current_time(1700000002.0);
        xassert($cc->api_account());        // window expired; reopen
        Conf::set_current_time(1700000004.0);
        xassert($cc->api_account());        // and again

        $this->conf->call_shutdown_function("ContactCounterFlush");
        xassert_eqq($this->count_in_db(), 3);
        $row = $this->conf->fetch_first_object(
            "select apiBase, apiBaseMtime from ContactCounter where contactId=?", $this->uid);
        xassert_eqq((int) $row->apiBaseMtime, $cc->apiBaseMtime);
        xassert_eqq((int) $row->apiBase, $cc->apiBase);
        // the last reopen is the one that stuck
        xassert_eqq($cc->apiBaseMtime, 1700000004000);

        $this->cleanup();
    }

    /** The charge is saved once, at shutdown, so a request that learns its
     * true cost only after doing the work can still be billed for it. */
    function test_charge_is_deferred_to_shutdown() {
        $cc = $this->fresh(100000, 1000, 100000, 1000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        xassert_eqq($cc->api_count(), 1);
        xassert_eqq($this->count_in_db(), 0);   // nothing written yet

        // the request works out that it did something expensive
        $cc->api_charge(9);
        xassert_eqq($cc->api_count(), 10);
        xassert_eqq($this->count_in_db(), 0);

        // and one write settles the whole request
        $this->conf->call_shutdown_function("ContactCounterFlush");
        xassert_eqq($this->count_in_db(), 10);
        xassert_eqq($cc->api_count(), 10);

        $this->cleanup();
    }

    /** A cost added after the fact bounds the next request, not the one that
     * incurred it — there is nothing to refuse by then. */
    function test_charge_binds_the_next_request() {
        $cc = $this->fresh(100000, 5, 100000, 1000000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        $cc->api_charge(10);                    // way over the budget of 5
        xassert_eqq($cc->api_count(), 11);
        xassert(!$cc->api_account());           // ... so the next one is refused

        $this->cleanup();
    }

    /** The flush adds to what it finds instead of overwriting it, so a
     * concurrent request's charge is not lost. */
    function test_concurrent_charge_is_relative() {
        $cc = $this->fresh(100000, 1000, 100000, 1000);
        Conf::set_current_time(1700000000.0);

        xassert($cc->api_account());
        // a concurrent request bumps the row while this one is still running
        $this->conf->qe("update ContactCounter set apiCount=5 where contactId=?", $this->uid);
        xassert($cc->api_account());

        $this->conf->call_shutdown_function("ContactCounterFlush");
        xassert_eqq($this->count_in_db(), 7);   // 5 + this request's 2

        $this->cleanup();
    }

    /** A 429 says when to come back. An agent that retries a bare error adds
     * load rather than shedding it. */
    function test_retry_after() {
        $cc = $this->fresh(1000, 3, 1000, 1000000);
        Conf::set_current_time(1700000000.5);

        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert($cc->api_account());
        xassert(!$cc->api_account());

        $jr = $cc->api_fail();
        xassert_eqq($jr->status, 429);
        xassert_eqq($cc->api_retry_after(), 1);
        xassert_str_contains($jr->header("Retry-After") ?? "", "1");

        // most of the window gone: still rounds up to a whole second
        Conf::set_current_time(1700000001.2);
        xassert_eqq($cc->api_retry_after(), 1);

        // and a disabled key gets no Retry-After: waiting will not help
        $cc2 = $this->fresh(1000, 0, 1000, 1000000);
        xassert(!$cc2->api_account());
        $jr = $cc2->api_fail();
        xassert_eqq($jr->status, 403);
        xassert_eqq($jr->header("Retry-After"), null);

        $this->cleanup();
    }

    function test_no_user_disabled() {
        // contactId <= 0 is intentionally disabled, with no DB access
        $cc = new ContactCounter($this->conf, false, 0);
        xassert(!$cc->api_account());
        xassert_eqq($cc->api_count(), 0);
        xassert_eqq($this->ratelimit($cc), ["limit" => "0"]);
        xassert_eqq($cc->api_fail()->status, 403);
    }
}
