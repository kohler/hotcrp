<?php
// t_userstatus.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UserStatus_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $raju_uid;
    /** @var int
     * @readonly */
    public $chris_uid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->raju_uid = $this->conf->fetch_ivalue("select contactId from ContactInfo where email='raju@watson.ibm.com'");
        $this->chris_uid = $this->conf->fetch_ivalue("select contactId from ContactInfo where email='chris@w3.org'");
    }

    /** @param string $email
     * @param array<string,mixed> $req
     * @return array{Contact,Qrequest} */
    private function make_qreq_for($email, $req = []) {
        $u = $this->conf->fresh_user_by_email($email);
        $qreq = (new Qrequest("POST", $req))->approve_token();
        $qreq->set_qsession(new MemoryQsession);
        UserSecurityEvent::session_user_add($qreq->qsession(), $email);
        UserSecurityEvent::make($email)
            ->set_reason(UserSecurityEvent::REASON_REAUTH)
            ->store($qreq->qsession());
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
        foreach (UserSecurityEvent::session_list_by_email($qreq->qsession(), $email) as $use) {
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

    private function delete_secondary($cdb, $email) {
        $db = $cdb ? $this->conf->contactdb() : $this->conf->dblink;
        $id = $cdb ? "contactDbId" : "contactId";
        if (($uid = Dbl::fetch_ivalue($db, "select {$id} from ContactInfo where email=?", $email)) > 0) {
            Dbl::qe($db, "delete from ContactInfo where {$id}=?", $uid);
            Dbl::qe($db, "delete from ContactPrimary where contactId=?", $uid);
        }
    }

    private function insert_secondary($cdb, $sec_email, $pri_id) {
        $db = $cdb ? $this->conf->contactdb() : $this->conf->dblink;
        $result = Dbl::qe($db, "insert into ContactInfo set email=?, password=' unset', primaryContactId=?",
            $sec_email, $pri_id);
        xassert_gt($result->insert_id ?? 0, 0);
        Dbl::qe($db, "insert into ContactPrimary set contactId=?, primaryContactId=?",
            $result->insert_id, $pri_id);
        return $result->insert_id;
    }

    function test_no_follow_primary() {
        $this->delete_secondary(false, "xvan@usc.edu");

        $van = $this->conf->user_by_email("van@ee.lbl.gov");
        xassert(!$van->isPC);
        $this->insert_secondary(false, "xvan@usc.edu", $van->contactId);
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
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }

        $this->delete_secondary(false, "yvan@usc.edu");
        $this->delete_secondary(true, "yvan@usc.edu");
        $c_van = $this->conf->cdb_user_by_email("van@ee.lbl.gov");
        $this->insert_secondary(true, "yvan@usc.edu", $c_van->contactDbId);
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

    function test_secondary_pc_not_followed() {
        $this->conf->qe("delete from ContactPrimary where contactId in (select contactId from ContactInfo where email like 'globert%')");
        $this->conf->qe("delete from ContactInfo where email like 'globert%'");

        $result = $this->conf->qe("insert into ContactInfo (firstName, lastName, email, affiliation, collaborators, password, cflags, roles) values
            ('Jimena', 'Globert', 'globert1@_.com', 'Brandeis', 'German Strawberries', '', 0, 1),
            ('Jimena', 'Globert', 'globert1p@_.com', 'Brandeis', 'German Strawberries', '', 0, 0)");
        xassert(!Dbl::is_error($result));
        $globert1 = $this->conf->fresh_user_by_email("globert1@_.com");
        $globert1p = $this->conf->fresh_user_by_email("globert1p@_.com");
        (new ContactPrimary)->link($globert1, $globert1p);
        xassert(!$globert1->has_tag("red"));

        list($u, $qreq) = $this->make_qreq_for(
            "chair@_.com",
            ["uemail" => "globert1@_.com", "tags" => "red"]
        );

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->set_follow_primary(true);
        $us->start_update();
        $us->request_group("");
        xassert($us->execute_update());

        $globert1 = $this->conf->fresh_user_by_email("globert1@_.com");
        xassert($globert1->has_tag("red"));
    }

    function test_confactions_link() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com", "email" => "chris@w3.org"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->raju_uid), $this->chris_uid);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_confactions_unlink() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->raju_uid), 0);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_confactions_relink() {
        ConfActions::link($this->conf, (object) ["u" => "raju@watson.ibm.com", "email" => "chris@w3.org"]);
        ConfActions::link($this->conf, (object) ["u" => "chris@w3.org", "email" => "raju@watson.ibm.com"]);
        xassert_eqq($this->conf->fetch_ivalue("select primaryContactId from ContactInfo where contactId=?", $this->chris_uid), $this->raju_uid);
        $this->conf->invalidate_caches("users");
        (new ConfInvariants($this->conf))->check_users();
    }

    #[RequireCdb(true)]
    function test_confactions_delay() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }
        $this->delete_secondary(false, "rajuu@watson.edu");
        $this->delete_secondary(true, "rajuu@watson.edu");

        $u_raju = $this->conf->user_by_id($this->raju_uid)->cdb_user();
        $u_rajuu = $this->conf->fresh_user_by_email("rajuu@watson.edu");
        xassert(!!$u_raju);
        xassert(!$u_rajuu);
        $this->insert_secondary(true, "rajuu@watson.edu", $u_raju->contactDbId);
        $this->conf->qe("insert into Settings set name='confactions', value=1, data=?",
            "\x1e{\"action\":\"link\",\"u\":\"raju@watson.ibm.com\",\"email\":\"rajuu@watson.edu\"}\n"
            . "\x1e{\"action\":\"link\",\"u\":\"rajuu@watson.edu\",\"email\":\"raju@watson.ibm.com\"}\n");
        $this->conf->invalidate_caches("users");
        $this->conf->load_settings();
        $u_raju = $this->conf->fresh_user_by_id($this->raju_uid);
        $u_rajuu = $this->conf->fresh_user_by_email("rajuu@watson.edu");
        $u_chris = $this->conf->fresh_user_by_id($this->chris_uid);
        xassert(!!$u_raju);
        xassert(!!$u_rajuu);
        xassert(!!$u_chris);
        xassert_eqq($u_rajuu->primaryContactId, $u_raju->contactId);
        xassert_eqq($u_chris->primaryContactId, $u_raju->contactId);
        xassert_eqq($u_raju->primaryContactId, 0);
        xassert_eqq($u_raju->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($u_rajuu->cflags & Contact::CF_PRIMARY, 0);
        $this->conf->invalidate_caches("users");
        (new ConfInvariants($this->conf))->check_users();
    }

    private function cps_text($ids) {
        return json_encode(Dbl::fetch_iimap($this->conf->dblink, "select * from ContactPrimary where contactId?a or primaryContactId?a", $ids, $ids));
    }

    function test_relink_series() {
        $u1 = $this->conf->ensure_user_by_email("yue1@x.com");
        $u2 = $this->conf->ensure_user_by_email("yue2@x.com");
        $u3 = $this->conf->ensure_user_by_email("yue3@x.com");
        $ids = [$u1->contactId, $u2->contactId, $u3->contactId];
        //error_log("$u1->contactId $u2->contactId $u3->contactId");
        //error_log("$u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));

        (new ContactPrimary($u1))->link($u2, $u1);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));
        (new ContactPrimary($u1))->link($u1, $u3);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));
        (new ContactPrimary($u1))->link($u2, $u1);
        //error_log(". $u1->primaryContactId $u2->primaryContactId $u3->primaryContactId " . $this->cps_text($ids));

        $u1 = $this->conf->fresh_user_by_id($u1->contactId);
        $u2 = $this->conf->fresh_user_by_id($u2->contactId);
        $u3 = $this->conf->fresh_user_by_id($u3->contactId);
        xassert_eqq($u1->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($u2->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($u3->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($u1->primaryContactId, 0);
        xassert_eqq($u2->primaryContactId, $u1->contactId);
        xassert_eqq($u3->primaryContactId, 0);
        (new ConfInvariants($this->conf))->check_users();
    }

    function test_create_disabled_primary() {
        $u1 = $this->conf->user_by_email("lina1@y.com");
        xassert(!$u1);
        $u1 = Contact::make_keyed($this->conf, ["email" => "lina1@y.com", "disablement" => Contact::CF_UDISABLED]);
        $u1->store();

        $u2 = $this->conf->user_by_email("lina2@y.com");
        xassert(!$u2);
        (new ContactPrimary($u1))->link($u1, Contact::make_email($this->conf, "lina2@y.com"));

        $u2 = $this->conf->fresh_user_by_email("lina2@y.com");
        xassert($u2->is_explicitly_disabled());

        $this->conf->qe("delete from ContactInfo where email='lina1@y.com' or email='lina2@y.com'");
        $this->conf->qe("delete from ContactPrimary where contactId?a", [$u1->contactId, $u2->contactId]);
    }

    function test_cleanup() {
        $emails = ["van@ee.lbl.gov", "raju@watson.ibm.com", "chris@w3.org"];
        $this->delete_secondary(false, "xvan@usc.edu");
        $this->delete_secondary(false, "yvan@usc.edu");
        $this->delete_secondary(false, "rajuu@watson.edu");
        $this->conf->qe("update ContactInfo set roles=0, cflags=cflags&~? where email?a", Contact::CF_PRIMARY, $emails);
        $this->conf->qe("delete from ContactPrimary where contactId?a", [$this->raju_uid, $this->chris_uid]);
        if (($cdb = $this->conf->contactdb())) {
            $this->delete_secondary(true, "xvan@usc.edu");
            $this->delete_secondary(true, "yvan@usc.edu");
            $this->delete_secondary(true, "rajuu@watson.edu");
            Dbl::qe($cdb, "update ContactInfo set cflags=cflags&~? where email?a", Contact::CF_PRIMARY, $emails);
            Dbl::qe($cdb, "delete from ContactPrimary where contactId in (select contactDbId from ContactInfo where email?a)", $emails);
        }
    }
}
