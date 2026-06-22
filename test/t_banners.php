<?php
// t_banners.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Banners_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_varghese;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_varghese = $conf->checked_user_by_email("varghese@ccrc.wustl.edu");
    }

    /** @return Qrequest */
    private function make_qreq(Contact $user) {
        $qreq = new Qrequest("GET");
        $qreq->set_user($user);
        $qreq->set_qsession(new MemoryQsession);
        return $qreq;
    }

    private function set_banners($banners_json) {
        $this->conf->save_setting("banners", 1, $banners_json);
        $this->conf->change_setting("banners", 1, $banners_json);
    }

    private function clear_banners() {
        $this->conf->save_setting("banners", null);
        $this->conf->change_setting("banners", null);
    }

    /** @param string $name
     * @param string $q
     * @return object */
    static private function count_param($name, $q) {
        return (object) ["name" => $name, "q" => $q];
    }

    /** @param string $name
     * @param string $value
     * @return object */
    static private function calc_param($name, $value) {
        return (object) ["name" => $name, "type" => "calc", "value" => $value];
    }

    private function establish_mcache() {
        $this->conf->invalidate_mcache();
        Conf::advance_current_time(Conf::$now + 1);
        $this->conf->request_mcache();
        Conf::advance_current_time(Conf::$now + 1);
    }


    // Basic banner computation

    function test_no_banners() {
        $this->clear_banners();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq($bs, []);
    }

    function test_simple_count_banner() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 1);
        xassert(isset($bs["b1"]));
    }

    function test_visibility_admin() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "admin",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        // chair sees admin-visible banner
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 1);

        // non-admin PC member does not
        $qreq = $this->make_qreq($this->u_varghese);
        $cb = new CustomBanners($this->conf, $this->u_varghese, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 0);
    }

    function test_visibility_none() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "none",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 0);
    }

    function test_multiple_banners() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ],
            (object) [
                "id" => "b2",
                "visibility" => "pc",
                "ftext" => "<0>{m} total",
                "params" => [self::count_param("m", "all")]
            ]
        ]));
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 2);
        xassert(isset($bs["b1"]));
        xassert(isset($bs["b2"]));
    }


    // Mcache caching

    function test_mcache_caching() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);

        // First call: computes banners fresh (no session cache)
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 1);
        xassert(!$cb->used_session_cache());

        // active() stores results to csession
        $cb->active();

        // Second call with same qreq: should use session cache
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs2 = $cb2->active();
        xassert_eqq(count($bs2), 1);
        xassert($cb2->used_session_cache());
        xassert_eqq($bs2["b1"], $bs["b1"]);
    }

    function test_mcache_invalidation() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        // Establish mcache, compute and cache banners
        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb->active();
        xassert(!$cb->used_session_cache());

        // Confirm cache is being used
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb2->active();
        xassert($cb2->used_session_cache());

        // Invalidate mcache and re-establish
        $this->establish_mcache();

        // Cache should no longer be valid (mcache epoch changed)
        $cb3 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb3->active();
        xassert(!$cb3->used_session_cache());
    }

    function test_per_user_banners() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        $this->establish_mcache();
        $qreq_chair = $this->make_qreq($this->u_chair);
        $qreq_varghese = $this->make_qreq($this->u_varghese);

        // Both PC members see the banner
        $cb1 = new CustomBanners($this->conf, $this->u_chair, $qreq_chair);
        $bs1 = $cb1->active();
        xassert_eqq(count($bs1), 1);

        $cb2 = new CustomBanners($this->conf, $this->u_varghese, $qreq_varghese);
        $bs2 = $cb2->active();
        xassert_eqq(count($bs2), 1);

        // Neither used session cache (independent sessions, first call each)
        xassert(!$cb1->used_session_cache());
        xassert(!$cb2->used_session_cache());
    }


    // Deadline boundary invalidation

    function test_deadline_no_deadlines() {
        // When all deadlines are in the past, cache stays valid indefinitely.
        // Advance time far past any configured deadline to ensure none are future.
        Conf::advance_current_time(Conf::$now + 365 * 86400);

        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb->active();
        xassert(!$cb->used_session_cache());

        // Much later: cache should still be valid (no future deadlines)
        Conf::advance_current_time(Conf::$now + 100000);
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb2->active();
        xassert($cb2->used_session_cache());
    }

    function test_deadline_crossing_invalidates_cache() {
        // Set a submission deadline in the future
        $future = Conf::$now + 100;
        $old_sub_open = $this->conf->setting("sub_open");
        $old_sub_sub = $this->conf->setting("sub_sub");
        $this->conf->save_refresh_setting("sub_open", Conf::$now - 1000);
        $this->conf->save_refresh_setting("sub_sub", $future);

        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        // Establish mcache and cache banners
        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb->active();
        xassert(!$cb->used_session_cache());

        // Time passes but before deadline: cache should still be valid
        Conf::advance_current_time($future - 10);
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb2->active();
        xassert($cb2->used_session_cache());

        // Advance past the deadline: cache should be stale
        Conf::advance_current_time($future + 1);
        $cb3 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb3->active();
        xassert(!$cb3->used_session_cache());

        // Restore
        $this->conf->save_refresh_setting("sub_open", $old_sub_open);
        $this->conf->save_refresh_setting("sub_sub", $old_sub_sub);
    }

    function test_deadline_next_tracks_correctly() {
        // Set two future submission deadlines
        $dl1 = Conf::$now + 50;
        $dl2 = Conf::$now + 200;
        $old_sub_open = $this->conf->setting("sub_open");
        $old_sub_sub = $this->conf->setting("sub_sub");
        $old_sub_update = $this->conf->setting("sub_update");
        $this->conf->save_refresh_setting("sub_open", Conf::$now - 1000);
        $this->conf->save_refresh_setting("sub_sub", $dl1);
        $this->conf->save_refresh_setting("sub_update", $dl2);

        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        // Establish mcache, compute and cache
        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb->active();
        xassert(!$cb->used_session_cache());

        // Before first deadline: cache valid
        Conf::advance_current_time($dl1 - 10);
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb2->active();
        xassert($cb2->used_session_cache());

        // Cross first deadline: cache stale
        Conf::advance_current_time($dl1 + 1);
        $cb3 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb3->active();
        xassert(!$cb3->used_session_cache());

        // Re-cache after first deadline
        $cb3->active();

        // Before second deadline: cache valid again
        Conf::advance_current_time($dl2 - 10);
        $cb4 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb4->active();
        xassert($cb4->used_session_cache());

        // Cross second deadline: cache stale again
        Conf::advance_current_time($dl2 + 1);
        $cb5 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb5->active();
        xassert(!$cb5->used_session_cache());

        // Re-cache after second deadline
        $cb5->active();

        // No more deadlines: cache stays valid far in the future
        Conf::advance_current_time(Conf::$now + 100000);
        $cb6 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb6->active();
        xassert($cb6->used_session_cache());

        // Restore
        $this->conf->save_refresh_setting("sub_open", $old_sub_open);
        $this->conf->save_refresh_setting("sub_sub", $old_sub_sub);
        $this->conf->save_refresh_setting("sub_update", $old_sub_update);
    }

    function test_submission_deadline_crossing() {
        // Test that submission deadlines trigger invalidation
        $future = Conf::$now + 100;
        $old_sub_open = $this->conf->setting("sub_open");
        $old_sub_sub = $this->conf->setting("sub_sub");
        $this->conf->save_refresh_setting("sub_open", Conf::$now - 1000);
        $this->conf->save_refresh_setting("sub_sub", $future);

        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{n} submitted",
                "params" => [self::count_param("n", "status:submitted")]
            ]
        ]));

        $this->establish_mcache();
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $cb->active();

        // Cross the deadline
        Conf::advance_current_time($future + 1);
        $cb2 = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs2 = $cb2->active();
        // Should have recomputed (deadline crossed invalidates cache)
        xassert(!$cb2->used_session_cache());
        xassert_eqq(count($bs2), 1);

        // Restore
        $this->conf->save_refresh_setting("sub_open", $old_sub_open);
        $this->conf->save_refresh_setting("sub_sub", $old_sub_sub);
    }


    // Calc params

    function test_calc_param_constant() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>result is {x}",
                "params" => [self::calc_param("x", "3 + 5")]
            ]
        ]));
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 1);
        xassert(str_contains($bs["b1"], "8"));
    }

    function test_calc_param_depends_on_count() {
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{total} total from {n} and {m}",
                "params" => [
                    self::count_param("n", "status:submitted"),
                    self::count_param("m", "status:submitted"),
                    self::calc_param("total", "n + m")
                ]
            ]
        ]));
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        xassert_eqq(count($bs), 1);
        // n and m count the same query, so total = 2 * n
        $bi = $cb->unparse_json((object) [
            "id" => "b1",
            "visibility" => "pc",
            "ftext" => "<0>{total} total from {n} and {m}",
            "params" => [
                self::count_param("n", "status:submitted"),
                self::count_param("m", "status:submitted"),
                self::calc_param("total", "n + m")
            ]
        ]);
        xassert(!!$bi);
        // Extract the values: "{total} total from {n} and {m}"
        // n == m, total == 2*n
        xassert(preg_match('/^(\d+) total from (\d+) and (\d+)$/', $bi->html, $mm));
        if ($mm) {
            xassert_eqq((int) $mm[1], (int) $mm[2] + (int) $mm[3]);
            xassert_eqq((int) $mm[2], (int) $mm[3]);
        }
    }

    function test_calc_chain() {
        $bi = (new CustomBanners($this->conf, $this->u_chair, $this->make_qreq($this->u_chair)))
            ->unparse_json((object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{quad}",
                "params" => [
                    self::count_param("n", "status:submitted"),
                    self::calc_param("double", "n * 2"),
                    self::calc_param("quad", "double * 2")
                ]
            ]);
        xassert(!!$bi);
        // quad = n * 4; get n separately to verify
        $srch = new PaperSearch($this->u_chair, ["q" => "status:submitted", "t" => "default"]);
        $n = count($srch->paper_ids());
        xassert_eqq($bi->html, (string) ($n * 4));
    }

    function test_calc_no_search_params() {
        // All calc params, no search params
        $bi = (new CustomBanners($this->conf, $this->u_chair, $this->make_qreq($this->u_chair)))
            ->unparse_json((object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>{y}",
                "params" => [
                    self::calc_param("x", "10"),
                    self::calc_param("y", "x * x + 1")
                ]
            ]);
        xassert(!!$bi);
        xassert_eqq($bi->html, "101");
    }

    function test_calc_cycle_dropped() {
        // a and b form a cycle; banner should still render but without those params
        $this->set_banners(json_encode([
            (object) [
                "id" => "b1",
                "visibility" => "pc",
                "ftext" => "<0>ok",
                "params" => [
                    self::calc_param("a", "b + 1"),
                    self::calc_param("b", "a + 1")
                ]
            ]
        ]));
        $qreq = $this->make_qreq($this->u_chair);
        $cb = new CustomBanners($this->conf, $this->u_chair, $qreq);
        $bs = $cb->active();
        // Banner still renders (ftext doesn't depend on the cycled params)
        xassert_eqq(count($bs), 1);
        xassert_eqq($bs["b1"], "ok");
    }


    // Clean up

    function test_cleanup() {
        $this->clear_banners();
        $this->conf->invalidate_mcache();
    }
}
