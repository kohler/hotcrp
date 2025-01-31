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
        UpdateSession::user_change($qreq, $email, true);
        UpdateSession::usec_add($qreq, $email, 0, 1, true);
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
        $us->start_update((object) ["id" => $u->contactId])->set_user($u);
        xassert($us->is_auth_self());
        xassert($us->has_recent_authentication());
        $us->request_group("");
        xassert_eqq($us->jval->new_password, $newpw);
        xassert($us->execute_update());

        xassert($us->user->check_password($newpw));
    }

    function test_edit_own_password_fail_no_recent_auth() {
        list($u, $qreq) = $this->make_qreq_for("estrin@usc.edu", [
            "upassword" => "maksdf", "upassword2" => "maksdf"
        ]);
        xassert_eqq($u->email, "estrin@usc.edu");
        $qreq->qsession()->set("usec", [["a" => Conf::$now - 40000, "r" => 1]]);
        xassert(UpdateSession::usec_query($qreq, "estrin@usc.edu", 0, 1, Conf::$now - 50000));
        xassert(!UpdateSession::usec_query($qreq, "estrin@usc.edu", 0, 1, Conf::$now - 20000));

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update((object) ["id" => $u->contactId])->set_user($u);
        xassert($us->is_auth_self());
        xassert(!$us->has_recent_authentication());
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdf"));
    }

    function test_edit_other_password_chair() {
        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["upassword" => "maksdfnq!", "upassword2" => "maksdfnq!"]
        );
        xassert_eqq($u->email, "chair@_.com");
        xassert($u->can_edit_any_password());

        $ux = $this->conf->fresh_user_by_email("estrin@usc.edu");
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update((object) ["id" => $ux->contactId])->set_user($ux);
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
        $us->start_update((object) ["id" => $ux->contactId])->set_user($ux);
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
        $us->start_update((object) ["id" => $u->contactId])->set_user($u);
        $us->request_group("");
        xassert($us->execute_update());

        xassert(!$us->user->check_password("maksdfnqw11"));
    }
}
