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

    function test_potential_conflict_list_bounded() {
        // every collaborator matches every author, so the number of matching
        // pairs is the product of the list lengths; record a few per author
        $pc = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $old_collaborators = $pc->collaborators();
        $old_authors = $this->conf->fetch_value("select authorInformation from Paper where paperId=1");
        $collab = $authors = [];
        for ($i = 0; $i !== 40; ++$i) {
            $collab[] = "Ann Bee (Org{$i})";
            $authors[] = "Ann\tBee\t\tZ{$i}\n";
        }
        $pc->set_prop("collaborators", join("\n", $collab));
        $pc->save_prop();
        $this->conf->qe("update Paper set authorInformation=? where paperId=1", join("", $authors));

        $n = 40 * PaperInfoPotentialConflictList::MAX_MATCHES_PER_AUTHOR;
        $prow = $this->user->checked_paper_by_id(1);
        $potconf = $prow->potential_conflict_list($pc);
        xassert(!!$potconf);
        xassert_eqq(count($potconf->list()), $n);
        xassert_eqq($potconf->description_text(), "Possible conflict with authors #1–40");
        $j = call_api("user", $this->user, ["email" => $pc->email, "potential_conflict" => 1], $prow);
        xassert($j->ok);
        xassert_eqq(substr_count($j->potential_conflict ?? "", "<em>author</em>"), $n);

        $this->conf->qe("update Paper set authorInformation=? where paperId=1", $old_authors);
        $pc->set_prop("collaborators", $old_collaborators);
        $pc->save_prop();
    }
}
