<?php
// t_userstatus.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UserStatus_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param string $email
     * @param array<string,mixed> $req
     * @return array{Contact,Qrequest} */
    private function make_qreq_for($email, $req = []) {
        $u = $this->conf->fresh_user_by_email($email);
        $qreq = (new Qrequest("POST", $req))->approve_token();
        $qreq->set_qsession(new TestQsession);
        UserSecurityEvent::session_user_add($qreq, $email);
        UserSecurityEvent::make($email)
            ->set_reason(UserSecurityEvent::REASON_REAUTH)
            ->store($qreq);
        $u = $u->activate($qreq, true);
        $qreq->set_user($u);
        return [$u, $qreq];
    }

    function test_edit_own_password() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu");
        xassert_eqq($u->email, "estrin@usc.edu");
        $newpw = $u->check_password("maksdfnqw") ? "MAKsdfnqw" : "maksdfnqw";
        $qreq->upassword = $qreq->upassword2 = $newpw;

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        xassert($us->is_auth_self());
        xassert($us->has_recent_authentication());
        $us->request_group("");
        xassert_eqq($us->jval->new_password, $newpw);
        xassert($us->execute_update());

        xassert($us->user->check_password($newpw));
    }

    static function reauth_query($qreq, $email, $bound) {
        $x = false;
        foreach (UserSecurityEvent::session_list_by_email($qreq, $email) as $use) {
            if ($use->type === UserSecurityEvent::TYPE_PASSWORD
                && $use->reason === UserSecurityEvent::REASON_REAUTH
                && $use->timestamp >= $bound)
                $x = $use->success;
        }
        return $x;
    }

    function test_edit_own_password_fail_no_recent_auth() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", [
            "upassword" => "maksdf", "upassword2" => "maksdf"
        ]);
        xassert_eqq($u->email, "estrin@usc.edu");
        $qreq->qsession()->set("usec", [["a" => Conf::$now - 40000, "r" => 1]]);
        xassert(self::reauth_query($qreq, "estrin@usc.edu", Conf::$now - 50000));
        xassert(!self::reauth_query($qreq, "estrin@usc.edu", Conf::$now - 20000));

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        xassert($us->is_auth_self());
        xassert(!$us->has_recent_authentication());
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdf"));
    }

    #[RequireCdb(false)]
    function test_edit_other_password_chair() {
        if ($this->conf->contactdb()) {
            return;
        }

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["upassword" => "maksdfnq!", "upassword2" => "maksdfnq!"]
        );
        xassert_eqq($u->email, "chair@_.com");
        xassert($u->can_edit_any_password());

        $ux = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($ux);
        $us->request_group("");
        xassert($us->execute_update());

        xassert($us->user->check_password("maksdfnq!"));
    }

    function test_edit_other_password_fail_nonchair() {
        list($u, $qreq) = $this->make_qreq_for(
            "floyd@ee.lbl.gov",
            ["upassword" => "maksdfnq11", "upassword2" => "maksdfnq11"]
        );
        xassert(!$u->can_edit_any_password());

        $ux = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($ux);
        $us->request_group("");
        $us->execute_update();

        xassert(!$us->user->check_password("maksdfnq11"));
    }

    function test_edit_actas_password_fail() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["upassword" => "maksdfnqw11", "upassword2" => "maksdfnqw11",
             "actas" => "estrin@usc.edu"]
        );
        xassert_eqq($u->email, "estrin@usc.edu");

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdfnqw11"));
    }

    function test_anonymous_fail() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "anonymous"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->request_group("");
        xassert(!$us->execute_update());
    }

    function test_no_follow_primary() {
        $this->conf->qe("delete from ContactInfo where email='xvan@usc.edu'");
        $van = $this->conf->user_by_email("van@ee.lbl.gov");
        xassert(!$van->isPC);
        $result = $this->conf->qe("insert into ContactInfo set email='xvan@usc.edu', password=' unset', primaryContactId=?", $van->contactId);
        xassert_gt($result->insert_id ?? 0, 0);
        $van->set_prop("cflags", $van->cflags | Contact::CF_PRIMARY);
        $van->save_prop();

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "xvan@usc.edu", "firstName" => "Ximena"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "xvan@usc.edu");
        xassert_eqq($us->user->firstName, "Ximena");
    }

    function test_follow_primary() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "xvan@usc.edu", "pctype" => "pc"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "van@ee.lbl.gov");
        xassert_eqq($us->user->isPC, true);
    }

    #[RequireCdb(true)]
    function test_follow_primary_cdb() {
        if (!$this->conf->contactdb()) {
            return;
        }

        $this->conf->qe("delete from ContactInfo where email='yvan@usc.edu'");
        Dbl::qe($this->conf->contactdb(), "delete from ContactInfo where email='yvan@usc.edu'");
        $c_van = $this->conf->cdb_user_by_email("van@ee.lbl.gov");
        $result = Dbl::qe($this->conf->contactdb(), "insert into ContactInfo set email='yvan@usc.edu', password=' unset', primaryContactId=?", $c_van->contactDbId);
        xassert_gt($result->insert_id ?? 0, 0);
        $c_van->set_prop("cflags", $c_van->cflags | Contact::CF_PRIMARY);
        $c_van->save_prop();

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "yvan@usc.edu", "pctype" => "chair"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        xassert_eqq($us->user->email, "van@ee.lbl.gov");
        xassert_eqq($us->user->privChair, true);
    }

    function test_cleanup() {
        $this->conf->qe("delete from ContactInfo where email='xvan@usc.edu' or email='yvan@usc.edu'");
        $this->conf->qe("update ContactInfo set roles=0, cflags=cflags&~? where email='van@ee.lbl.gov'", Contact::CF_PRIMARY);
        if (($cdb = $this->conf->contactdb())) {
            $this->conf->qe("delete from ContactInfo where email='xvan@usc.edu' or email='yvan@usc.edu'");
            $this->conf->qe("update ContactInfo set cflags=cflags&~? where email='van@ee.lbl.gov'", Contact::CF_PRIMARY);
        }
    }
}
