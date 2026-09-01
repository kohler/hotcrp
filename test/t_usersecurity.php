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

    /** @return UserSecurityEvent */
    private function make_confirmation($email, $client_id, $scope = "read",
                                       $dbname = "confA") {
        return UserSecurityEvent::make($email)
            ->set_oauth_confirmation($client_id, "https://c.example.com/cb",
                $scope, $dbname);
    }

    /** An authorization reaches one conference's submissions and reviews, so a
     * consent granted at one must not answer for another. Sessions can be
     * shared across conferences, and a site-wide client has the same id,
     * redirect URI, and scope at every one of them. */
    function test_oauth_confirmation_is_scoped_to_its_conference() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $this->make_confirmation("estrin@usc.edu", "c1", "read", "confA")->store($qreq);
        $qs = $qreq->qsession();

        // the conference it was granted at answers
        xassert_neqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", "confA"), null);
        // a different one does not
        xassert_eqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", "confB"), null);
        // nor does a cdb client's cross-conference lookup
        xassert_eqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", null), null);

        // and granting at confB leaves confA's consent intact
        $this->make_confirmation("estrin@usc.edu", "c1", "read", "confB")->store($qreq);
        xassert_neqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", "confA"), null);
        xassert_neqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", "confB"), null);
    }

    /** An authorization is per client, so granting one again replaces what
     * that client had rather than piling up. */
    function test_oauth_confirmation_replaces_same_client() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $this->make_confirmation("estrin@usc.edu", "c1")->store($qreq);
        $this->make_confirmation("estrin@usc.edu", "c2")->store($qreq);
        xassert_eqq(count($qreq->gsession("usec")), 2);

        // same client again, wider scope: one record, and it is the new one
        $this->make_confirmation("estrin@usc.edu", "c1", "read paper:write")->store($qreq);
        $usec = $qreq->gsession("usec");
        xassert_eqq(count($usec), 2);
        $found = UserSecurityEvent::session_oauth_confirmation($qreq->qsession(),
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read paper:write", "confA");
        xassert_neqq($found, null);
        // the scope it replaced is no longer on offer
        xassert_eqq(UserSecurityEvent::session_oauth_confirmation($qreq->qsession(),
            "estrin@usc.edu", "c1", "https://c.example.com/cb", "read", "confA"), null);
    }

    /** Authorizations are not replaced by a later sign-in the way
     * authentications are, so the session caps how many it keeps. */
    function test_oauth_confirmations_are_capped() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $n = UserSecurityEvent::MAX_OAUTH_CONFIRMATIONS;
        for ($i = 0; $i !== $n + 5; ++$i) {
            $this->make_confirmation("estrin@usc.edu", "c{$i}")->store($qreq);
        }
        xassert_eqq(count($qreq->gsession("usec")), $n);
        // the oldest went first
        xassert_eqq(UserSecurityEvent::session_oauth_confirmation($qreq->qsession(),
            "estrin@usc.edu", "c0", "https://c.example.com/cb", "read", "confA"), null);
        xassert_neqq(UserSecurityEvent::session_oauth_confirmation($qreq->qsession(),
            "estrin@usc.edu", "c" . ($n + 4), "https://c.example.com/cb", "read", "confA"), null);
    }

    /** A caller asking for more than the bound is answered with the bound, so
     * a confirmation older than that is never fresh however wide the ask. */
    function test_max_age_bound_clamps_down() {
        $u = $this->conf->checked_user_by_email("estrin@usc.edu");
        $bound = AuthenticationChecker::MAX_AGE_BOUND;

        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $qreq->set_gsession("usec", [["r" => 1, "a" => Conf::$now - $bound - 10]]);
        xassert(!$u->authentication_checker($qreq, "api")
            ->set_max_age(PHP_INT_MAX)->test());

        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $qreq->set_gsession("usec", [["r" => 1, "a" => Conf::$now - $bound + 10]]);
        xassert($u->authentication_checker($qreq, "api")
            ->set_max_age(PHP_INT_MAX)->test());
    }

    /** An account that never authenticated is never fresh, whatever window was
     * asked for. */
    function test_no_authentication_is_never_fresh() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $u = $this->conf->checked_user_by_email("estrin@usc.edu");
        $ac = $u->authentication_checker($qreq, "api")->set_max_age(PHP_INT_MAX);
        xassert_eqq($ac->latest(), 0);
        xassert(!$ac->test());
    }

    /** Stale authorizations are collected when something else is stored. */
    function test_oauth_confirmations_are_collected() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $life = UserSecurityEvent::OAUTH_CONFIRMATION_LIFETIME;
        $qreq->set_gsession("usec", [
            ["r" => 2, "ci" => "old", "ru" => "https://c.example.com/cb",
             "sc" => "read", "db" => "confA", "a" => Conf::$now - $life - 10],
            ["r" => 2, "ci" => "new", "ru" => "https://c.example.com/cb",
             "sc" => "read", "db" => "confA", "a" => Conf::$now - $life + 10]
        ]);
        UserSecurityEvent::make("estrin@usc.edu")->store($qreq);
        $qs = $qreq->qsession();
        xassert_eqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "old", "https://c.example.com/cb", "read", "confA"), null);
        xassert_neqq(UserSecurityEvent::session_oauth_confirmation($qs,
            "estrin@usc.edu", "new", "https://c.example.com/cb", "read", "confA"), null);
    }

    /** An authorization is not an authentication, and must never satisfy one. */
    function test_oauth_confirmation_is_not_an_authentication() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $this->make_confirmation("estrin@usc.edu", "c1")->store($qreq);
        $u = $this->conf->checked_user_by_email("estrin@usc.edu");
        $ac = $u->authentication_checker($qreq, "api");
        xassert_eqq($ac->latest(), 0);
        xassert(!$ac->test());
        xassert_eqq(UserSecurityEvent::session_latest_signin_by_email($qreq->qsession(),
            "estrin@usc.edu"), null);
    }

    /** A reauth is kept as long as some window could still honor it, so the
     * horizon tracks the checker's bound rather than a fixed day. */
    function test_store_drops_stale_reauth() {
        $qreq = $this->make_qrequest(["estrin@usc.edu"]);
        $bound = AuthenticationChecker::MAX_AGE_BOUND;
        $qreq->set_gsession("usec", [
            ["r" => 1, "a" => Conf::$now - $bound - 2],  // beyond any window: dropped
            ["r" => 1, "a" => Conf::$now - $bound + 2],  // still usable
            ["a" => Conf::$now - 200000]                 // signin: no age limit
        ]);
        UserSecurityEvent::make("floyd@ee.lbl.gov")->store($qreq);
        $ages = [];
        foreach ($qreq->gsession("usec") as $x) {
            $ages[] = Conf::$now - $x["a"];
        }
        xassert_eqq($ages, [$bound - 2, 200000, 0]);
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
