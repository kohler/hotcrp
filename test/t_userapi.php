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

    function test_lookup_unlisted_pc() {
        $email = "unlisted-lookup@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $us = new UserStatus($this->conf->root_user());
        $acct = $us->save_user((object) ["email" => $email, "name" => "Ulla Unlisted", "roles" => ["unlistedpc"]]);
        xassert(!!$acct, $us->full_feedback_text());
        $this->conf->invalidate_caches("users", "pc");

        $pc = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert(!$pc->is_track_manager() && !$pc->privChair);
        $j = call_api("user", $pc, ["email" => $email]);
        xassert($j->ok);
        xassert_eqq($j->match ?? null, true);
        xassert_eqq($j->email ?? null, $email);

        // a non-PC user still finds nobody
        $u = $this->conf->checked_user_by_email("puneet@catarina.usc.edu");
        xassert(!$u->isPC);
        $j = call_api("user", $u, ["email" => $email]);
        xassert(!($j->match ?? false));

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
    }
}
