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
}
