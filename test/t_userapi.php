<?php
// t_userapi.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class UserAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Contact */
    public $u_marina; // pc
    /** @var Contact */
    public $u_van; // none

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $this->u_van = $conf->checked_user_by_email("van@ee.lbl.gov");
    }

    function test_disable() {
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        xassert_eqq($user->is_disabled(), false);

        $j = call_api("=account", $this->user, ["u" => "marina@poema.ru", "disable" => true], null);
        xassert($j->ok);
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        xassert_eqq($user->is_disabled(), true);

        $j = call_api("=account", $this->user, ["u" => "marina@poema.ru", "enable" => true], null);
        xassert($j->ok);
        $user = $this->conf->checked_user_by_email("marina@poema.ru");
        xassert_eqq($user->is_disabled(), false);
    }

    function test_users_nameemail_respects_visibility() {
        // A Users page test, not an API test, but close enough:
        // Users "name & email" export must not disclose users
        // outside the visible current listing.
        $viewer = $this->u_marina;
        xassert($viewer->isPC && !$viewer->is_manager() && !$viewer->privChair);
        $nonpc = $this->conf->checked_user_by_email("kohler@seas.harvard.edu");
        xassert($nonpc->contactId > 0 && !$nonpc->isPC);

        $qreq = TestQreq::get(["t" => "pc", "pap" => "all"])
            ->set_conf($this->conf);
        $up = new Users_Page($viewer, $qreq);
        $emails = array_map(function ($u) { return $u->email; }, $up->selected_users());
        xassert_in_eqq($viewer->email, $emails);
        xassert_not_in_eqq($nonpc->email, $emails);
        xassert_in_eqq("chair@_.com", $emails);

        $qreq = TestQreq::get(["t" => "pc", "pap" => json_encode([$viewer->contactId, $nonpc->contactId])])
            ->set_conf($this->conf);
        $up = new Users_Page($viewer, $qreq);
        $emails = array_map(function ($u) { return $u->email; }, $up->selected_users());
        xassert_in_eqq($viewer->email, $emails);
        xassert_not_in_eqq($nonpc->email, $emails);
        xassert_not_in_eqq("chair@_.com", $emails);

        // a user who can't make a search gets nothing
        $viewer = $this->u_van;
        $qreq = TestQreq::get(["t" => "pcadmin", "pap" => json_encode([$this->u_van->contactId, $this->u_marina->contactId])])
            ->set_conf($this->conf);
        $up = new Users_Page($this->u_van, $qreq);
        $emails = array_map(function ($u) { return $u->email; }, $up->selected_users());
        xassert_eqq($emails, []);
    }
}
