<?php
// t_session.php -- HotCRP tests for the session API
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Session_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
    }

    /** @return Qrequest */
    private function make_qreq() {
        $qreq = new Qrequest("POST");
        $qreq->set_user($this->u_chair);
        $qreq->set_qsession(new MemoryQsession);
        return $qreq;
    }

    function test_change_session_applies_recognized() {
        $qreq = $this->make_qreq();
        xassert_eqq(Session_API::change_session($qreq, "uldisplay.lead=0"), true);
        xassert_str_contains($qreq->csession("uldisplay"), " lead ");
        // a nonzero value (here, hide) restores the default display
        xassert_eqq(Session_API::change_session($qreq, "uldisplay.lead=1"), true);
        xassert_eqq($qreq->csession("uldisplay"), null);
    }

    function test_change_session_ignores_unrecognized() {
        // Unrecognized components are silently ignored; the call still succeeds
        // and recognized components in the same request are still applied.
        $qreq = $this->make_qreq();
        xassert_eqq(Session_API::change_session($qreq, "supercalifragilistic=1"), true);
        // A recognized name in an unrecognized shape is likewise ignored, not
        // an error (this once returned false, leaving siblings applied anyway).
        xassert_eqq(Session_API::change_session($qreq, "scoresort.bogus=1 uldisplay.lead=0"), true);
        xassert_str_contains($qreq->csession("uldisplay"), " lead ");
    }

    function test_change_session_applies_each_assignment() {
        // an assignment without a value does not absorb the ones after it
        $qreq = $this->make_qreq();
        Session_API::change_session($qreq, "uldisplay.lead uldisplay.collab=0 uldisplay.tags=1");
        $uld = $qreq->csession("uldisplay");
        xassert_str_contains($uld, " lead ");
        xassert_str_contains($uld, " collab ");
        xassert_not_str_contains($uld, " tags ");
        // later assignments to the same column win
        Session_API::change_session($qreq, "uldisplay.lead=1 uldisplay.collab=1 uldisplay.collab=0");
        $uld = $qreq->csession("uldisplay");
        xassert_not_str_contains($uld, " lead ");
        xassert_str_contains($uld, " collab ");
    }

    function test_change_session_view_length_limit() {
        // an oversized request is ignored, and an oversized view is not saved
        $qreq = $this->make_qreq();
        $v = [];
        for ($i = 0; $i !== 2000; ++$i) {
            $v[] = "pldisplay.?c{$i}=0";
        }
        Session_API::change_session($qreq, "pldisplay.abstract=0");
        Session_API::change_session($qreq, join(" ", $v));
        xassert_eqq($qreq->csession("pldisplay"), "show:abstract");
        foreach (array_chunk($v, 500) as $vx) {
            Session_API::change_session($qreq, join(" ", $vx));
        }
        xassert_str_contains($qreq->csession("pldisplay"), " show:?c999");
        xassert_not_str_contains($qreq->csession("pldisplay"), " show:?c1999");
        xassert_le(strlen($qreq->csession("pldisplay")), 16384);
        Session_API::change_session($qreq, "pldisplay.abstract=1");
        xassert_not_str_contains($qreq->csession("pldisplay"), "show:abstract");
    }

    function test_change_session_ulscoresort() {
        $qreq = $this->make_qreq();
        Session_API::change_session($qreq, "ulscoresort=variance");
        xassert_str_contains($qreq->csession("uldisplay"), " scoresort=variance ");
        Session_API::change_session($qreq, "uldisplay.scoresort=maxmin");
        xassert_str_contains($qreq->csession("uldisplay"), " scoresort=maxmin ");
        Session_API::change_session($qreq, "ulscoresort=average");
        xassert_eqq($qreq->csession("uldisplay"), null);
    }

    function test_store_smsg_skips_null() {
        // `feedback_msg_content` yields null for messages that render empty;
        // `store_smsg` must not stash a null entry for one.
        $qreq = $this->make_qreq();
        Session_API::store_smsg($qreq, "nullsmsg01", null);
        xassert_eqq($qreq->gsession("smsg"), null);

        // a real content entry is stored
        Session_API::store_smsg($qreq, "realsmsg01", ["<5>hi", 2]);
        $smsgs = $qreq->gsession("smsg");
        xassert_eqq(count($smsgs), 1);
        xassert_eqq($smsgs[0][0], "realsmsg01");
        xassert_eqq($smsgs[0][2], ["<5>hi", 2]);

        // a null mixed with content keeps only the content, and never stores
        // the null
        Session_API::store_smsg($qreq, "mixsmsg01", null, ["<5>yo", 1]);
        $smsgs = $qreq->gsession("smsg");
        xassert_eqq(count($smsgs), 2);
        xassert_eqq($smsgs[1], ["mixsmsg01", Conf::$now, ["<5>yo", 1]]);
    }

    /** @return Qrequest */
    private function committed_session_qreq() {
        $qs = TestQsession::start_request(null);
        $qs->open();
        $qs->commit();
        return TestQreq::post()->set_user($this->u_chair)->set_qsession($qs);
    }

    /** Run `Conf::activate_smsgs` with the given cookies.
     * @param array<string,string> $cookies
     * @return list<array{string,int}> */
    private function activate_smsgs_with(Qrequest $qreq, $cookies) {
        $old_cookie = $_COOKIE;
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2; // collect messages rather than print them
        $_COOKIE = $cookies;
        try {
            $this->conf->saved_messages_begin();
            $this->conf->activate_smsgs($qreq);
            return $this->conf->claim_saved_messages();
        } finally {
            $_COOKIE = $old_cookie;
            Navigation::$test_mode = $old_test_mode;
        }
    }

    function test_setting_smsg_reported_once() {
        TestQsession::reset();
        $qreq = $this->committed_session_qreq();
        xassert(!$qreq->qsession()->is_writable());
        $smsg = $this->conf->store_setting_smsg($qreq, [["<0>Saved", 1]]);
        xassert(is_string($smsg) && ctype_alnum($smsg));
        // the session itself is untouched
        xassert_eqq(TestQsession::$store[$qreq->qsid()], ["v" => 2]);
        // the in-memory row matches the database
        $mem = [$this->conf->setting("__smsg.{$smsg}"), $this->conf->setting_data("__smsg.{$smsg}")];
        xassert_eqq($mem[0], Conf::$now);
        $this->conf->load_settings();
        xassert_eqq([$this->conf->setting("__smsg.{$smsg}"), $this->conf->setting_data("__smsg.{$smsg}")], $mem);
        // the session ID does not appear in the stored row
        xassert_not_str_contains($this->conf->setting_data("__smsg.{$smsg}"), $qreq->qsid());

        // an unknown ID reports nothing, and leaves the row alone
        xassert_eqq($this->activate_smsgs_with($qreq, ["hotcrp-smsg-Nonexistent0" => "1"]), []);
        xassert_eqq($this->conf->setting("__smsg.{$smsg}"), Conf::$now);

        // the ID reports its messages exactly once
        $ck = ["hotcrp-smsg-{$smsg}" => Conf::smsg_cookie_value()];
        xassert_eqq($this->activate_smsgs_with($qreq, $ck), [["<0>Saved", 1]]);
        xassert_eqq($this->activate_smsgs_with($qreq, $ck), []);
        $this->conf->load_settings();
        xassert_eqq($this->conf->setting("__smsg.{$smsg}"), null);
    }

    function test_setting_smsg_reported_only_by_deleter() {
        // a concurrent request that loaded the row, but lost the race to
        // delete it, reports nothing
        TestQsession::reset();
        $qreq = $this->committed_session_qreq();
        $smsg = $this->conf->store_setting_smsg($qreq, [["<0>Raced", 1]]);
        $this->conf->load_settings();
        xassert_eqq($this->conf->setting("__smsg.{$smsg}"), Conf::$now);
        $this->conf->qe("delete from Settings where name=?", "__smsg.{$smsg}");
        xassert_eqq($this->activate_smsgs_with($qreq, ["hotcrp-smsg-{$smsg}" => "1"]), []);
    }

    function test_setting_smsg_dated_at_store_time() {
        // a slow request (here, one that began 100s ago) dates its message
        // from when it is stored, not from when the request began
        // (Use real time: the suite's clock may run ahead of it.)
        TestQsession::reset();
        $old_unow = Conf::$unow;
        $t0 = microtime(true);
        Conf::set_current_time($t0 - 100);
        try {
            $qreq = $this->committed_session_qreq();
            $smsg = $this->conf->store_setting_smsg($qreq, [["<0>Slow", 1]]);
            $this->conf->load_settings();
            xassert_ge($this->conf->setting("__smsg.{$smsg}"), (int) $t0);
            xassert_eqq($this->activate_smsgs_with($qreq, ["hotcrp-smsg-{$smsg}" => Conf::smsg_cookie_value()]), [["<0>Slow", 1]]);
        } finally {
            Conf::set_current_time(max($old_unow, Conf::$unow));
        }
    }

    function test_setting_smsg_expires_and_gcs() {
        TestQsession::reset();
        $qreq = $this->committed_session_qreq();
        $smsg = $this->conf->store_setting_smsg($qreq, [["<0>Old", 1]]);
        $old = Conf::$now - Conf::SMSG_LIFETIME - 1;
        $this->conf->qe("update Settings set value=? where name=?", $old, "__smsg.{$smsg}");
        $this->conf->load_settings();
        // loading skipped the expired row and collected it
        xassert_eqq($this->conf->setting("__smsg.{$smsg}"), null);
        xassert_eqq($this->conf->fetch_ivalue("select value from Settings where name=?", "__smsg.{$smsg}"), null);
        xassert_eqq($this->activate_smsgs_with($qreq, ["hotcrp-smsg-{$smsg}" => "1"]), []);
    }

    function test_smsgs_merge_session_and_settings() {
        // session and Settings messages report together, in cookie order;
        // session entries without cookies stay unless expired
        TestQsession::reset();
        $qreq = $this->make_qreq();
        Session_API::store_smsg($qreq, "sessionsmsg1", ["<0>Session first", 1]);
        Session_API::store_smsg($qreq, "sessionsmsg2", ["<0>Session third", 1]);
        Session_API::store_smsg($qreq, "unclaimedsm1", ["<0>Not yet", 1]);
        $smsgs = $qreq->gsession("smsg");
        $smsgs[] = ["expiredsmsg1", Conf::$now - Conf::SMSG_LIFETIME - 1, ["<0>Expired", 1]];
        $qreq->set_gsession("smsg", $smsgs);
        $smsg = $this->conf->store_setting_smsg($this->committed_session_qreq(), [["<0>Setting second", 1]]);
        $this->conf->load_settings();

        $ml = $this->activate_smsgs_with($qreq, [
            "hotcrp-smsg-sessionsmsg2" => "1700000000300",
            "hotcrp-smsg-{$smsg}" => "1700000000200",
            "hotcrp-smsg-sessionsmsg1" => "1700000000100",
            "hotcrp-smsg-expiredsmsg1" => "1700000000000",
            "unrelated" => "1"
        ]);
        xassert_eqq($ml, [["<0>Session first", 1], ["<0>Setting second", 1], ["<0>Session third", 1]]);
        $smsgs = $qreq->gsession("smsg");
        xassert_eqq(count($smsgs), 1);
        xassert_eqq($smsgs[0][0], "unclaimedsm1");

        // the last claim removes the session entry entirely
        xassert_eqq($this->activate_smsgs_with($qreq, ["hotcrp-smsg-unclaimedsm1" => "5"]), [["<0>Not yet", 1]]);
        xassert_eqq($qreq->gsession("smsg"), null);
    }

    function test_saved_messages_commit_without_session() {
        TestQsession::reset();
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2; // collect messages rather than print them
        try {
            // committed session: messages go to Settings, not the session
            $qreq = $this->committed_session_qreq();
            $this->conf->saved_messages_begin();
            Conf::msg_on($this->conf, "<0>Updated", 1);
            $this->conf->saved_messages_commit($qreq);
            xassert(!$qreq->qsession()->is_writable());
            xassert_eqq(TestQsession::$store[$qreq->qsid()], ["v" => 2]);
            $n = $this->conf->fetch_ivalue("select count(*) from Settings where name>'__smsg.' and name<'__smsg/' and data like ?", "%Updated%");
            xassert_eqq($n, 1);

            // writable session: messages go to the session, as before
            $qs = TestQsession::start_request(null);
            $qs->open();
            $qreq = TestQreq::post()->set_user($this->u_chair)->set_qsession($qs);
            $this->conf->saved_messages_begin();
            Conf::msg_on($this->conf, "<0>Again", 1);
            $this->conf->saved_messages_commit($qreq);
            xassert_eqq(TestQsession::$store[$qs->sid][$this->conf->session_key]["msgs"] ?? null, [["<0>Again", 1]]);

            // no session yet: open one, as before, rather than use Settings
            $qs = TestQsession::start_request(null);
            $qreq = TestQreq::post()->set_user($this->u_chair)->set_qsession($qs);
            xassert(!$qs->is_readable());
            $this->conf->saved_messages_begin();
            Conf::msg_on($this->conf, "<0>Fresh", 1);
            $this->conf->saved_messages_commit($qreq);
            xassert($qs->sid !== null);
            xassert_eqq(TestQsession::$store[$qs->sid][$this->conf->session_key]["msgs"] ?? null, [["<0>Fresh", 1]]);
            $n = $this->conf->fetch_ivalue("select count(*) from Settings where name>'__smsg.' and name<'__smsg/' and data like ?", "%Fresh%");
            xassert_eqq($n, 0);
        } finally {
            Navigation::$test_mode = $old_test_mode;
            $this->conf->claim_saved_messages();
            $this->conf->qe("delete from Settings where name>'__smsg.' and name<'__smsg/'");
        }
    }

    /** @param string $email
     * @param TestQsession $qs
     * @return Qrequest */
    private function signin_request($email, $qs) {
        $qreq = TestQreq::post(["email" => $email])
            ->set_page("signin")->set_qsession($qs);
        $user = $this->conf->checked_user_by_email($email);
        $info = LoginHelper::login_complete(["ok" => true, "user" => $user], $qreq);
        xassert($info["ok"]);
        return $qreq;
    }

    function test_old_sid_not_forwarded() {
        // Changing the session ID (as at sign-in) leaves the old session
        // holding its data at that moment. A request that presents the old
        // ID afterwards -- perhaps the same browser's, in flight across the
        // change -- gets that data, but never anything added after the
        // change, never the new ID, and no cookie: anyone might know the
        // old ID [session fixation].
        TestQsession::reset();

        // request 1: a visitor obtains anonymous session S
        $qs = TestQsession::start_request(null);
        $qs->open();
        $sid_s = $qs->sid;
        xassert(is_string($sid_s));
        xassert_eqq(Contact::session_emails($qs), []);
        $qs->set("marker", 1);
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_s]);

        // request 2: a browser carrying S signs in and gets new ID N
        $qs = TestQsession::start_request($sid_s);
        $qs->maybe_open();
        $this->signin_request("chair@_.com", $qs);
        $sid_n = $qs->sid;
        xassert(is_string($sid_n));
        xassert_neqq($sid_n, $sid_s);
        xassert_eqq(Contact::session_emails($qs), ["chair@_.com"]);
        xassert_eqq($qs->get("marker"), 1);
        xassert(!$qs->has("deletedat"));
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_n]);
        // S is deleted and does not name N
        xassert_eqq(TestQsession::$store[$sid_s]["deletedat"] ?? null, Conf::$now);
        xassert_not_str_contains(json_encode(TestQsession::$store[$sid_s]), $sid_n);

        // request 3: a holder of S gets S's anonymous data and no cookie
        $qs = TestQsession::start_request($sid_s);
        $qs->maybe_open();
        xassert_eqq($qs->sid, $sid_s);
        xassert_eqq(Contact::session_emails($qs), []);
        xassert_eqq($qs->get("marker"), 1);
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, []);

        // ...and if it requests a new ID, it gets an unrelated empty session
        $qs = TestQsession::start_request($sid_s);
        $qs->open_new_sid();
        $sid_x = $qs->sid;
        xassert_not_in_eqq($sid_x, [$sid_s, $sid_n]);
        xassert_eqq(Contact::session_emails($qs), []);
        xassert(!$qs->has("marker"));
        xassert(!$qs->has("deletedat"));
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_x]);

        // request 4: N adds an account and becomes N2
        $qs = TestQsession::start_request($sid_n);
        $qs->maybe_open();
        xassert_eqq(Contact::session_emails($qs), ["chair@_.com"]);
        $this->signin_request("marina@poema.ru", $qs);
        $sid_n2 = $qs->sid;
        xassert_not_in_eqq($sid_n2, [$sid_s, $sid_n, $sid_x]);
        xassert_eqq(Contact::session_emails($qs), ["chair@_.com", "marina@poema.ru"]);
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_n, $sid_n2]);

        // request 5: a straggler presenting N is still signed in as before
        // the change, but does not get the added account
        $qs = TestQsession::start_request($sid_n);
        $qs->maybe_open();
        xassert_eqq($qs->sid, $sid_n);
        xassert_eqq(Contact::session_emails($qs), ["chair@_.com"]);
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, []);

        // after 30 seconds, a holder of S gets an unrelated empty session
        TestQsession::$store[$sid_s]["deletedat"] = Conf::$now - 31;
        $qs = TestQsession::start_request($sid_s);
        $qs->maybe_open();
        $sid_y = $qs->sid;
        xassert_not_in_eqq($sid_y, [$sid_s, $sid_n, $sid_n2, $sid_x]);
        xassert_eqq(Contact::session_emails($qs), []);
        xassert(!$qs->has("marker"));
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_y]);

        // N2 holds everything
        $qs = TestQsession::start_request($sid_n2);
        $qs->maybe_open();
        xassert_eqq($qs->sid, $sid_n2);
        xassert_eqq(Contact::session_emails($qs), ["chair@_.com", "marina@poema.ru"]);
        xassert_eqq($qs->get("marker"), 1);
        xassert(!$qs->has("deletedat"));
        $qs->commit();
        xassert_eqq(TestQsession::$cookies, [$sid_n2]);

        TestQsession::reset();
    }
}
