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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
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
}
