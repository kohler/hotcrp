<?php
// t_usersecurity.php -- HotCRP tests for UserSecurityEvent
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class UserSecurity_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param list<string> $emails
     * @return Qsession */
    private function make_qsession($emails = []) {
        $qs = new MemoryQsession;
        foreach ($emails as $email) {
            UserSecurityEvent::session_user_add($qs, $email);
        }
        return $qs;
    }

    /** @param list<string> $emails
     * @return Qrequest */
    private function make_qrequest($emails = []) {
        return TestQreq::get()->set_qsession($this->make_qsession($emails));
    }


    // Serialization: `usec` entries are persisted in sessions, so the compact
    // format in devel/manual/sessions.md must round-trip exactly.

    function test_as_array_omits_defaults() {
        $use = UserSecurityEvent::make("estrin@usc.edu");
        $use->uindex = 0;
        $use->timestamp = 1000;
        // type 0, reason 0, success, uindex 0 are all defaults
        xassert_eqq($use->as_array(), ["a" => 1000]);
    }

    function test_as_array_records_nondefaults() {
        $use = UserSecurityEvent::make("estrin@usc.edu", UserSecurityEvent::TYPE_OAUTH,
                                       UserSecurityEvent::REASON_REAUTH);
        $use->uindex = 2;
        $use->timestamp = 1000;
        $use->set_subtype("google")->set_success(false);
        xassert_eqq($use->as_array(), [
            "u" => 2, "t" => 1, "s" => "google", "r" => 1, "x" => true, "a" => 1000
        ]);
    }

    function test_as_array_uses_email_when_unindexed() {
        $use = UserSecurityEvent::make("estrin@usc.edu");
        $use->timestamp = 1000;
        // uindex unset means the entry is keyed by email, not index
        xassert_eqq($use->as_array(), ["e" => "estrin@usc.edu", "a" => 1000]);
    }

    function test_make_array_round_trip() {
        foreach ([["a" => 1000],
                  ["u" => 2, "t" => 1, "s" => "google", "r" => 1, "x" => true, "a" => 1000],
                  ["e" => "estrin@usc.edu", "a" => 1000]] as $x) {
            xassert_eqq(UserSecurityEvent::make_array($x)->as_array(), $x);
        }
    }

    function test_make_array_defaults() {
        $use = UserSecurityEvent::make_array(["a" => 5]);
        xassert_eqq($use->uindex, 0);
        xassert_eqq($use->type, 0);
        xassert_eqq($use->subtype, null);
        xassert_eqq($use->reason, 0);
        xassert_eqq($use->success, true);
        xassert_eqq($use->timestamp, 5);
    }

    function test_make_array_email_form_is_unindexed() {
        $use = UserSecurityEvent::make_array(["e" => "estrin@usc.edu", "a" => 5]);
        xassert_eqq($use->uindex, -1);
        xassert_eqq($use->email, "estrin@usc.edu");
    }


    // store()

    function test_store_assigns_uindex() {
        $qreq = $this->make_qrequest(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        $usec = $qreq->gsession("usec");
        xassert_eqq(count($usec), 1);
        xassert_eqq($usec[0]["u"] ?? 0, 1);
    }

    function test_store_keeps_unrelated_events() {
        $qreq = $this->make_qrequest(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        UserSecurityEvent::make("estrin@usc.edu")->store($qreq);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        xassert_eqq(count($qreq->gsession("usec")), 2);
    }

    function test_store_success_supersedes_same_kind() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $old = UserSecurityEvent::make("estrin@usc.edu");
        $old->timestamp = Conf::$now - 100;
        $old->store($qreq);
        UserSecurityEvent::make("estrin@usc.edu")->store($qreq);
        // same user, type, subtype, and reason: only the newer entry survives
        $usec = $qreq->gsession("usec");
        xassert_eqq(count($usec), 1);
        xassert_eqq($usec[0]["a"], Conf::$now);
    }

    function test_store_success_keeps_other_kinds() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        UserSecurityEvent::make("estrin@usc.edu", UserSecurityEvent::TYPE_PASSWORD)->store($qreq);
        UserSecurityEvent::make("estrin@usc.edu", UserSecurityEvent::TYPE_OAUTH)
            ->set_subtype("google")->store($qreq);
        xassert_eqq(count($qreq->gsession("usec")), 2);

        // differing subtype is also a different kind
        UserSecurityEvent::make("estrin@usc.edu", UserSecurityEvent::TYPE_OAUTH)
            ->set_subtype("orcid")->store($qreq);
        xassert_eqq(count($qreq->gsession("usec")), 3);
    }

    function test_store_failure_does_not_supersede() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        UserSecurityEvent::make("estrin@usc.edu")->set_success(false)->store($qreq);
        UserSecurityEvent::make("estrin@usc.edu")->set_success(false)->store($qreq);
        // failures accumulate; only a success clears earlier matches
        xassert_eqq(count($qreq->gsession("usec")), 2);
    }

    function test_store_drops_stale_reauth() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $qreq->set_gsession("usec", [
            ["r" => 1, "a" => Conf::$now - 86401],  // stale reauth: dropped
            ["r" => 1, "a" => Conf::$now - 86399],  // still fresh
            ["a" => Conf::$now - 200000]            // signin: no age limit
        ]);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        $ages = [];
        foreach ($qreq->gsession("usec") as $x) {
            $ages[] = Conf::$now - $x["a"];
        }
        xassert_eqq($ages, [86399, 200000, 0]);
    }

    function test_store_drops_old_failures_when_crowded() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        // under the 150-entry threshold, old failures are kept
        $usec = [];
        for ($i = 0; $i !== 149; ++$i) {
            $usec[] = ["e" => "x{$i}@example.com", "x" => true, "a" => Conf::$now - 901];
        }
        $qreq->set_gsession("usec", $usec);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        xassert_eqq(count($qreq->gsession("usec")), 150);

        // at the threshold, failures older than 900s go
        $usec[] = ["e" => "x149@example.com", "x" => true, "a" => Conf::$now - 899];
        $qreq->set_gsession("usec", $usec);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        $usec = $qreq->gsession("usec");
        xassert_eqq(count($usec), 2);
        xassert_eqq($usec[0]["e"], "x149@example.com");
    }

    function test_store_backfills_uindex() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        // an entry stored before the account was indexed is keyed by email
        $qreq->set_gsession("usec", [["e" => "estrin@usc.edu", "t" => 2, "a" => Conf::$now - 100]]);
        UserSecurityEvent::make("estrin@usc.edu", UserSecurityEvent::TYPE_OAUTH)->store($qreq);
        $usec = $qreq->gsession("usec");
        xassert_eqq(count($usec), 2);
        // the old entry is rewritten against index 0, losing its `e` key
        xassert_eqq($usec[0], ["t" => 2, "a" => Conf::$now - 100]);
    }


    // session_list_by_email

    function test_session_list_by_email_matches_index_and_email() {
        $qreq = $this->make_qrequest(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        $qs = $qreq->qsession();
        $qreq->set_gsession("usec", [
            ["a" => 1],                                 // index 0 = estrin
            ["u" => 1, "a" => 2],                       // index 1 = floyd
            ["e" => "estrin@usc.edu", "a" => 3],        // email-keyed estrin
            ["e" => "other@example.com", "a" => 4]
        ]);
        $ts = [];
        foreach (UserSecurityEvent::session_list_by_email($qs, "estrin@usc.edu") as $use) {
            $ts[] = $use->timestamp;
        }
        xassert_eqq($ts, [1, 3]);

        $ts = [];
        foreach (UserSecurityEvent::session_list_by_email($qs, "FLOYD@ee.lbl.gov") as $use) {
            $ts[] = $use->timestamp;
        }
        xassert_eqq($ts, [2]);
    }

    function test_session_latest_signin_by_email() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $qs = $qreq->qsession();
        $qs->set("usec", [
            ["a" => 1],
            ["r" => 1, "a" => 2],   // reauth, not a signin
            ["a" => 3],
            ["r" => 1, "a" => 4]
        ]);
        $use = UserSecurityEvent::session_latest_signin_by_email($qs, "estrin@usc.edu");
        xassert(!!$use);
        xassert_eqq($use->timestamp, 3);

        xassert_eqq(UserSecurityEvent::session_latest_signin_by_email($qs, "floyd@ee.lbl.gov"), null);
    }


    // session_user_add / session_user_remove

    function test_session_user_add_indexes() {
        $qs = new MemoryQsession;
        xassert_eqq(UserSecurityEvent::session_user_add($qs, "estrin@usc.edu"), 0);
        xassert_eqq($qs->get("u"), "estrin@usc.edu");
        xassert_eqq($qs->get("us"), null); // one account needs no list

        xassert_eqq(UserSecurityEvent::session_user_add($qs, "floyd@ee.lbl.gov"), 1);
        xassert_eqq($qs->get("us"), ["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        xassert_eqq($qs->get("u"), "estrin@usc.edu");

        // adding an existing account returns its index and changes nothing
        xassert_eqq(UserSecurityEvent::session_user_add($qs, "ESTRIN@usc.edu"), 0);
        xassert_eqq($qs->get("us"), ["ESTRIN@usc.edu", "floyd@ee.lbl.gov"]);
    }

    function test_session_user_remove_leaves_tombstone() {
        $qs = $this->make_qsession(["estrin@usc.edu", "floyd@ee.lbl.gov", "chair@_.com"]);
        UserSecurityEvent::session_user_remove($qs, "floyd@ee.lbl.gov");
        // the slot is blanked, not compacted, so later indexes stay put
        xassert_eqq($qs->get("us"), ["estrin@usc.edu", "", "chair@_.com"]);
        xassert_eqq($qs->get("u"), "estrin@usc.edu");
        xassert_eqq(Contact::session_index_by_email($qs, "chair@_.com"), 2);
    }

    function test_session_user_add_reuses_blank_slot() {
        $qs = $this->make_qsession(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        UserSecurityEvent::session_user_remove($qs, "estrin@usc.edu");
        xassert_eqq($qs->get("us"), ["", "floyd@ee.lbl.gov"]);
        xassert_eqq($qs->get("u"), "floyd@ee.lbl.gov");

        xassert_eqq(UserSecurityEvent::session_user_add($qs, "chair@_.com"), 0);
        xassert_eqq($qs->get("us"), ["chair@_.com", "floyd@ee.lbl.gov"]);
    }

    function test_session_user_remove_trims_trailing_blanks() {
        $qs = $this->make_qsession(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        UserSecurityEvent::session_user_remove($qs, "floyd@ee.lbl.gov");
        // a trailing blank is dropped, so one account remains and `us` goes away
        xassert_eqq($qs->get("us"), null);
        xassert_eqq($qs->get("u"), "estrin@usc.edu");
    }

    function test_session_user_remove_last_clears_session_users() {
        $qs = $this->make_qsession(["estrin@usc.edu"]);
        UserSecurityEvent::session_user_remove($qs, "estrin@usc.edu");
        xassert_eqq($qs->get("u"), null);
        xassert_eqq($qs->get("us"), null);
    }

    function test_session_user_remove_drops_that_users_events() {
        $qs = $this->make_qsession(["estrin@usc.edu", "floyd@ee.lbl.gov"]);
        $qs->set("usec", [
            ["a" => 1],                              // index 0 = estrin
            ["u" => 1, "a" => 2],                    // index 1 = floyd
            ["e" => "floyd@ee.lbl.gov", "a" => 3],   // email-keyed floyd
            ["e" => "other@example.com", "a" => 4]
        ]);
        UserSecurityEvent::session_user_remove($qs, "floyd@ee.lbl.gov");
        $ts = [];
        foreach ($qs->get("usec") as $x) {
            $ts[] = $x["a"];
        }
        xassert_eqq($ts, [1, 4]);
    }
}
