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

    function test_pc_tag_members() {
        $pc = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $j = call_api("pc", $pc, ["ui" => 1]);
        xassert($j->ok);
        xassert(!isset($j->tag_members));

        $j = call_api("pc", $pc, ["ui" => 1, "tag_members" => 1]);
        xassert($j->ok);
        $red = $j->tag_members->red ?? null;
        xassert(is_array($red) && !empty($red));
        $emails = array_map(function ($i) use ($j) { return $j->pc[$i]->email; }, $red);
        xassert_in_eqq("estrin@usc.edu", $emails);
        xassert_not_in_eqq("mgbaker@cs.stanford.edu", $emails);
        foreach ($red as $i) {
            xassert($this->conf->checked_user_by_email($j->pc[$i]->email)->has_tag("red"));
        }
        xassert(!isset($j->tag_members->pc));

        // users who cannot see user tags get no membership
        $u = $this->conf->checked_user_by_email("puneet@catarina.usc.edu");
        $j = call_api("pc", $u, ["tag_members" => 1]);
        xassert(!isset($j->tag_members));
    }

    function test_lookup_by_prefix() {
        // a prefix finds the first matching account
        $pc = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        foreach (["estrin@us", "estrin@usc", "ESTRIN@USC.e"] as $prefix) {
            $j = call_api("user", $pc, ["email" => $prefix]);
            xassert($j->ok, $prefix);
            xassert_eqq($j->match ?? null, false, $prefix);
            xassert_eqq($j->email ?? null, "estrin@usc.edu", $prefix);
        }
        // `match` means the whole address
        $j = call_api("user", $pc, ["email" => "Estrin@USC.edu"]);
        xassert_eqq($j->match ?? null, true);
        xassert_eqq($j->email ?? null, "estrin@usc.edu");
        $j = call_api("user", $pc, ["email" => "estrin@usx"]);
        xassert_eqq($j->email ?? null, null);
    }
}
