<?php
// t_userspage.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class UsersPage_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_marina; // pc
    /** @var Contact */
    public $u_van; // none

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $this->u_van = $conf->checked_user_by_email("van@ee.lbl.gov");
    }

    function test_users_nameemail_respects_visibility() {
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

    /** @return int */
    private function users_nprefs(Contact $viewer, Contact $user) {
        $qreq = TestQreq::get(["t" => "pc"])
            ->set_conf($this->conf)
            ->set_user($viewer)
            ->set_qsession(new MemoryQsession);
        $qreq->set_csession("uldisplay", " nprefs ");
        $pl = new ContactList($viewer, false, $qreq);
        xassert_str_contains($pl->table_html("pc", ""), "# Prefs");
        return (int) $pl->content(ContactList::FIELD_NPREFS, $user);
    }

    function test_users_nprefs_respects_conflicts() {
        // the Users page "# Prefs" column counts others' preferences only on
        // papers where the viewer can see aggregate preferences
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $author = $this->conf->checked_user_by_email("estrin@usc.edu");
        $pc = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk");
        $preffer = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $p1 = $this->conf->checked_paper_by_id(1);
        xassert(!$p1->has_conflict($chair));
        xassert($author->isPC && !$author->is_manager() && $p1->has_author($author));
        xassert($pc->isPC && !$pc->is_manager() && !$p1->has_conflict($pc));
        xassert($preffer->isPC && !$p1->has_conflict($preffer));

        $pref1 = $p1->preference($preffer)->unparse();
        xassert(call_api("=revpref", $preffer, ["pref" => "0"], $p1)->ok);
        $n_chair = $this->users_nprefs($chair, $preffer);
        $n_author = $this->users_nprefs($author, $preffer);
        $n_pc = $this->users_nprefs($pc, $preffer);
        $n_self = $this->users_nprefs($preffer, $preffer);

        xassert(call_api("=revpref", $preffer, ["pref" => "-2"], $p1)->ok);
        xassert_search($author, "1 pref:pc<0", "");
        xassert_search($pc, "1 pref:pc<0", "1");
        xassert_eqq($this->users_nprefs($chair, $preffer), $n_chair + 1);
        xassert_eqq($this->users_nprefs($author, $preffer), $n_author);
        xassert_eqq($this->users_nprefs($pc, $preffer), $n_pc + 1);
        xassert_eqq($this->users_nprefs($preffer, $preffer), $n_self + 1);

        // a PC conflict hides the preference too
        xassert_assign($chair, "paper,action,user\n1,conflict,{$pc->email}\n");
        xassert_search($pc, "1 pref:pc<0", "");
        xassert_eqq($this->users_nprefs($pc, $preffer), $n_pc);

        // but one's own preferences always count
        xassert_assign($chair, "paper,action,user\n1,conflict,{$preffer->email}\n");
        xassert_eqq($this->users_nprefs($preffer, $preffer), $n_self + 1);
        xassert_eqq($this->users_nprefs($chair, $preffer), $n_chair + 1);

        xassert_assign($chair, "paper,action,user\n1,clearconflict,{$preffer->email}\n1,clearconflict,{$pc->email}\n");
        xassert(call_api("=revpref", $preffer, ["pref" => $pref1], $p1)->ok);
    }

    function test_users_unlisted_pc_columns() {
        // the collaborators and scores columns treat unlisted PC members
        // like other PC members
        $email = "unlisted-columns@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $us = new UserStatus($this->conf->root_user());
        $acct = $us->save_user((object) ["email" => $email, "name" => "Ursula Unlisted",
            "roles" => ["unlistedpc"], "collaborators" => "Zebulon Quixote (Nowhere U)"]);
        xassert(!!$acct, $us->full_feedback_text());
        $this->conf->invalidate_caches("users", "pc");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $old_rev_open = $this->conf->setting("rev_open");
        $old_viewrev = $this->conf->setting("viewrev");
        $this->conf->save_setting("viewrev", Conf::VIEWREV_ALWAYS);
        $this->conf->save_refresh_setting("rev_open", 1);
        xassert_assign($chair, "paper,action,user\n1,primary,{$email}\n");
        $acct = $this->conf->checked_user_by_email($email);
        save_review(1, $acct, ["ovemer" => 2, "revexp" => 1, "ready" => true]);

        $viewer = $this->u_marina;
        xassert($viewer->isPC && !$viewer->privChair);
        $qreq = TestQreq::get(["t" => "fullpc"])
            ->set_conf($this->conf)
            ->set_user($viewer)
            ->set_qsession(new MemoryQsession);
        $ovemer = $this->conf->find_review_field("ovemer");
        $qreq->set_csession("uldisplay", " collab {$ovemer->short_id} ");
        $rfields = array_values(array_filter($this->conf->review_form()->viewable_fields($viewer),
            function ($f) { return $f instanceof Discrete_ReviewField; }));
        $ovemer_fid = ContactList::FIELD_SCORE + array_search($ovemer, $rfields, true);
        $pl = new ContactList($viewer, false, $qreq);
        $h = $pl->table_html("fullpc", "");
        xassert_str_contains($h, "Ursula Unlisted");
        xassert_str_contains($pl->content(ContactList::FIELD_COLLABORATORS, $acct), "Zebulon Quixote");
        xassert_neqq($pl->content($ovemer_fid, $acct), "");

        // but a viewer who may not see PC roles gets neither, even on a
        // list that is not limited to the PC
        $this->conf->set_opt("secretPC", true);
        Contact::update_rights();
        xassert_eqq($viewer->viewable_roles_mask(), 0);
        xassert(ContactList::can_view_list($viewer, "re"));
        $pl = new ContactList($viewer, false, $qreq);
        $h = $pl->table_html("re", "");
        xassert_str_contains($h, "Ursula Unlisted");
        xassert_eqq($pl->content(ContactList::FIELD_COLLABORATORS, $acct), "");
        xassert_eqq($pl->content($ovemer_fid, $acct), "");
        $this->conf->set_opt("secretPC", null);
        Contact::update_rights();

        $this->conf->qe("delete from PaperReview where contactId=?", $acct->contactId);
        $this->conf->save_setting("viewrev", $old_viewrev);
        $this->conf->save_refresh_setting("rev_open", $old_rev_open);
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
    }

    /** @param array<string,string> $args
     * @return list<string> */
    private function selected_emails(Contact $viewer, $args) {
        $qreq = TestQreq::get($args)->set_conf($this->conf);
        $up = new Users_Page($viewer, $qreq);
        return array_map(function ($u) { return $u->email; }, $up->selected_users());
    }

    /** @param array<string,string> $args
     * @return string */
    private function run_users_page(Contact $viewer, $args) {
        $qreq = TestQreq::apply_user($viewer, TestQreq::post($args)->set_conf($this->conf))
            ->set_page("users");
        Qrequest::set_main_request($qreq);
        // test_mode 2 routes feedback messages into the page
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            Users_Page::go($viewer, $qreq);
        } catch (Redirection $unused) {
        }
        Navigation::$test_mode = $old_test_mode;
        return ob_get_clean();
    }

    function test_users_selection_is_an_id_set() {
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $all = $this->selected_emails($chair, ["t" => "all", "pap" => "all"]);
        xassert_gt(count($all), 10);

        // user selections are not limited to paper IDs or a fixed count
        $huge = $this->selected_emails($chair, ["t" => "all", "pap" => "1-99999999999"]);
        xassert_eqq($huge, $all);
        $maxcid = max(array_map(function ($e) {
            return $this->conf->checked_user_by_email($e)->contactId;
        }, $all));
        $top = $this->conf->user_by_id($maxcid);
        xassert_eqq($this->selected_emails($chair, ["t" => "all", "pap" => "{$maxcid}-99999999999"]),
                    [$top->email]);
        xassert_eqq($this->selected_emails($chair, ["t" => "all", "p" => "{$maxcid}-99999999999"]),
                    [$top->email]);

        // a selection with too many pieces for exact SQL is still exact
        $odd = join(" ", range(1, 2 * PaperIDSet::MAX_SQL_RANGES + 3, 2));
        xassert(!SearchSelection::make_raw(TestQreq::get(["pap" => $odd]))->id_set()->is_sql_predicate_precise());
        $odd_emails = array_values(array_filter($all, function ($e) {
            return $this->conf->checked_user_by_email($e)->contactId % 2 === 1;
        }));
        xassert_gt(count($odd_emails), 0);
        xassert_lt(count($odd_emails), count($all));
        xassert_eqq($this->selected_emails($chair, ["t" => "all", "pap" => $odd]), $odd_emails);

        // actions over an open-ended range touch listable accounts only,
        // never placeholders
        $email = "placeholder.sel@cs.hotcrp-test.edu";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $ph = Contact::make_keyed($this->conf, ["email" => $email, "firstName" => "Pla", "lastName" => "Ceholder"])->store();
        $this->conf->qe("update ContactInfo set cflags=cflags|? where contactId=?", Contact::CF_PLACEHOLDER, $ph->contactId);
        $ph = $this->conf->fresh_user_by_email($email);
        xassert($ph->is_placeholder());
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "a",
                                       "tag" => "hugerange", "pap" => "{$maxcid}-99999999999"]);
        $tagged = Dbl::fetch_first_columns($this->conf->dblink, "select contactId from ContactInfo where contactTags like '% hugerange#%' order by contactId");
        $listable_ids = function ($where) {
            $ids = [];
            foreach (Dbl::fetch_rows($this->conf->dblink, "select contactId, email from ContactInfo where {$where} and (cflags&?)=0 order by contactId", Contact::CFM_PLACEHOLDER) as $row) {
                if (!Contact::is_anonymous_email($row[1]))
                    $ids[] = $row[0];
            }
            return $ids;
        };
        $listable = $listable_ids("contactId>={$maxcid}");
        xassert_eqq($tagged, $listable);
        xassert_eqq($tagged[0] ?? null, (string) $maxcid);
        xassert_not_in_eqq((string) $ph->contactId, $tagged);
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "d",
                                       "tag" => "hugerange", "pap" => "1-99999999999"]);
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from ContactInfo where contactTags like '% hugerange#%'"), 0);

        // actions over an imprecise selection leave unselected accounts alone
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "a",
                                       "tag" => "hugerange", "pap" => "1-99999999999"]);
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "d",
                                       "tag" => "hugerange", "pap" => $odd]);
        $tagged = Dbl::fetch_first_columns($this->conf->dblink, "select contactId from ContactInfo where contactTags like '% hugerange#%' order by contactId");
        $even = $listable_ids("contactId%2=0");
        xassert_gt(count($tagged), 0);
        xassert_eqq($tagged, $even);
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "d",
                                       "tag" => "hugerange", "pap" => "1-99999999999"]);
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from ContactInfo where contactTags like '% hugerange#%'"), 0);

        MailChecker::clear();
        $out = $this->run_users_page($chair, ["t" => "all", "fn" => "modify", "modifyfn" => "sendaccount",
                                              "pap" => (string) $ph->contactId]);
        xassert_eqq(count(MailChecker::$preps), 0);
        xassert_str_contains($out, "No users selected");
        $this->run_users_page($chair, ["t" => "all", "fn" => "modify", "modifyfn" => "add_pc",
                                       "pap" => (string) $ph->contactId]);
        xassert(!$this->conf->fresh_user_by_email($email)->isPC);
        MailChecker::clear();

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");

        // anonymous review accounts aren't listed, so actions skip them too
        $this->conf->qe("delete from ContactInfo where email='anonymous97'");
        $this->conf->qe("insert into ContactInfo set email='anonymous97', firstName='Jane Q.', lastName='Public', affiliation='', password='', cflags=?", Contact::CF_UDISABLED);
        $anon = $this->conf->user_by_id($this->conf->fetch_ivalue("select contactId from ContactInfo where email='anonymous97'"));
        xassert($anon && $anon->is_anonymous_user());
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "a",
                                       "tag" => "hugerange", "pap" => "{$anon->contactId} {$maxcid}"]);
        $tagged = Dbl::fetch_first_columns($this->conf->dblink, "select contactId from ContactInfo where contactTags like '% hugerange#%'");
        xassert_eqq($tagged, [(string) $maxcid]);
        $this->run_users_page($chair, ["t" => "all", "fn" => "modify", "modifyfn" => "enable",
                                       "pap" => (string) $anon->contactId]);
        xassert_eqq($this->conf->fetch_ivalue("select cflags from ContactInfo where contactId=?", $anon->contactId),
                    Contact::CF_UDISABLED);
        $this->run_users_page($chair, ["t" => "all", "fn" => "tag", "tagfn" => "d",
                                       "tag" => "hugerange", "pap" => (string) $maxcid]);
        $this->conf->qe("delete from ContactInfo where email='anonymous97'");
        $this->conf->invalidate_caches("users", "pc");
    }
}
