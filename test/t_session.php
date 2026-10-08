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
