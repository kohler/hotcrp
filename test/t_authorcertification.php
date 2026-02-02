<?php
// t_authorcertification.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class AuthorCertification_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_sally;
    /** @var Contact
     * @readonly */
    public $u_carole;

    /** @var PaperOption */
    public $cert1;
    /** @var string */
    public $c1key;
    /** @var PaperOption */
    public $cert2;
    /** @var string */
    public $c2key;
    /** @var int */
    public $pid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $conf->save_setting("sub_open", 1);
        $conf->save_setting("sub_update", Conf::$now + 100);
        $conf->save_setting("sub_sub", Conf::$now + 100);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_sally = $conf->checked_user_by_email("floyd@ee.lbl.gov"); // pc red blue
        $this->u_carole = Contact::make_email($conf, "cleita@berkeley.ca")->ensure_account_here();

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Aucert1",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/type" => "author_certification",
            "sf/2/name" => "Aucert2",
            "sf/2/id" => "new",
            "sf/2/order" => 101,
            "sf/2/type" => "author_certification",
            "sf/2/required" => "submit"
        ]);
        xassert($sv->execute());
        $this->cert1 = $this->conf->options()->find("Aucert1");
        $this->c1key = "opt" . $this->cert1->id;
        $this->cert2 = $this->conf->options()->find("Aucert2");
        $this->c2key = "opt" . $this->cert2->id;

        $ps = new PaperStatus($this->u_sally);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "title" => "Sally’s special paper",
            "abstract" => "The Berkeley Public Library is an excellent library.\r\n",
            "has_authors" => "1",
            "authors:1:name" => "Sally Floyd",
            "authors:1:email" => "floyd@ee.lbl.gov",
            "authors:2:name" => "Carole Leita",
            "authors:2:email" => "cleita@berkeley.ca",
            "has_submission" => 1,
            "status:submit" => 1
        ])->set_file_content("submission:file", "%PDF-Monticello", null, "application/pdf")
          ->set_user($this->u_sally), null));
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        $this->pid = $ps->paperId;

        $newpaper = $this->u_sally->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title(), "Sally’s special paper");
        xassert_eqq($newpaper->abstract(), "The Berkeley Public Library is an excellent library.");
        xassert_eqq(count($newpaper->author_list()), 2);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Sally");
        xassert_eqq($aus[0]->lastName, "Floyd");
        xassert_eqq($aus[0]->email, "floyd@ee.lbl.gov");
        xassert_le($newpaper->timeSubmitted, 0);
        xassert_le($newpaper->timeWithdrawn, 0);
        xassert_eqq($newpaper->conflict_type($this->u_sally), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
    }

    /** @return PaperInfo */
    function paper() {
        return $this->conf->checked_paper_by_id($this->pid);
    }

    function test_author_certify() {
        $ps = new PaperStatus($this->u_sally);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => 1,
            "{$this->c1key}:1:email" => $this->u_sally->email,
            "{$this->c1key}:1:value" => 1,
            "has_{$this->c2key}" => 1,
            "{$this->c2key}:1:email" => $this->u_sally->email,
            "{$this->c2key}:1:value" => 1,
            "status:submit" => 1
        ])->set_user($this->u_sally), $this->paper()));
        xassert_array_eqq($ps->changed_keys(), ["aucert_1", "aucert_2"], true);
        xassert($ps->execute_save());

        $prow = $this->paper();
        $ov = $prow->option($this->cert1);
        xassert_eqq($ov->value_count(), 1);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert(!$ov->option->value_present($ov));
        $ov = $prow->option($this->cert2);
        xassert_eqq($ov->value_count(), 1);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert(!$ov->option->value_present($ov));
        xassert_le($prow->timeSubmitted, 0);
        xassert_le($prow->timeWithdrawn, 0);
    }

    function test_cannot_remove_certified_author() {
        $ps = new PaperStatus($this->u_carole);
        xassert(!$ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_authors" => "1",
            "authors:1:name" => "Carole Leita",
            "authors:1:email" => "cleita@berkeley.ca"
        ])->set_user($this->u_carole), $this->paper()));
        xassert_str_contains($ps->full_feedback_text(), "{$this->u_sally->email} has certified the Aucert1 field");
        xassert_str_contains($ps->full_feedback_text(), "{$this->u_sally->email} has certified the Aucert2 field");
    }

    function test_add_uncertified_author() {
        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_authors" => "1",
            "authors:1:name" => "Carole Leita",
            "authors:1:email" => "cleita@berkeley.ca",
            "authors:2:name" => "Sally Floyd",
            "authors:2:email" => "floyd@ee.lbl.gov",
            "authors:3:name" => "Malvina Reynolds",
            "authors:3:email" => "little@boxes.org",
            "status:submit" => 1
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        xassert_eqq(count($prow->author_list()), 3);
        xassert_le($prow->timeSubmitted, 0);
        xassert_le($prow->timeWithdrawn, 0);
    }

    function test_cannot_certify_other() {
        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => "1",
            "{$this->c1key}:1:email" => "little@boxes.org",
            "{$this->c1key}:1:value" => "1"
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        xassert_eqq($prow->option($this->cert1)->value_count(), 1);
    }

    function test_admin_certify_other() {
        $ps = new PaperStatus($this->u_chair);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => "1",
            "{$this->c1key}:1:email" => "little@boxes.org",
            "{$this->c1key}:1:value" => "1"
        ])->set_user($this->u_chair), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        $malvina = $this->conf->checked_user_by_email("little@boxes.org");
        xassert_in_eqq($this->u_sally->contactId, $prow->option($this->cert1)->value_list());
        xassert_not_in_eqq($this->u_carole->contactId, $prow->option($this->cert1)->value_list());
        xassert_in_eqq($malvina->contactId, $prow->option($this->cert1)->value_list());
        xassert_eqq($prow->option($this->cert1)->value_count(), 2);

        $ps = new PaperStatus($this->u_chair);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => "1",
            "{$this->c1key}:1:email" => "little@boxes.org",
            "{$this->c1key}:1:value" => "0"
        ])->set_user($this->u_chair), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        xassert_eqq($prow->option($this->cert1)->value_count(), 1);
    }

    function test_remove_uncertified_author() {
        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_authors" => "1",
            "authors:1:name" => "Carole Leita",
            "authors:1:email" => "cleita@berkeley.ca",
            "authors:2:name" => "Sally Floyd",
            "authors:2:email" => "floyd@ee.lbl.gov",
            "status:submit" => 1
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        xassert_eqq(count($prow->author_list()), 2);
        xassert_le($prow->timeSubmitted, 0);
        xassert_le($prow->timeWithdrawn, 0);
    }

    function test_certify_and_submit() {
        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => "1",
            "{$this->c1key}:1:email" => "cleita@berkeley.ca",
            "{$this->c1key}:1:value" => "1",
            "status:submit" => 1
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        $ov = $prow->option($this->cert1);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert_in_eqq($this->u_carole->contactId, $ov->value_list());
        xassert($ov->option->value_present($ov));
        xassert_le($prow->timeSubmitted, 0);
        xassert_le($prow->timeWithdrawn, 0);

        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c1key}" => "1",
            "{$this->c1key}:1:email" => "cleita@berkeley.ca",
            "{$this->c1key}:1:value" => "0",
            "has_{$this->c2key}" => "1",
            "{$this->c2key}:1:email" => "cleita@berkeley.ca",
            "{$this->c2key}:1:value" => "1",
            "status:submit" => 1
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        $prow = $this->paper();
        $ov = $prow->option($this->cert1);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert_not_in_eqq($this->u_carole->contactId, $ov->value_list());
        xassert(!$ov->option->value_present($ov));
        $ov = $prow->option($this->cert2);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert_in_eqq($this->u_carole->contactId, $ov->value_list());
        xassert($ov->option->value_present($ov));
        xassert_gt($prow->timeSubmitted, 0); // submit finally succeeded
        xassert_le($prow->timeWithdrawn, 0);
    }

    function test_decertify_required() {
        $ps = new PaperStatus($this->u_carole);
        xassert($ps->prepare_save_paper_web(Qrequest::make("POST", [
            "has_{$this->c2key}" => "1",
            "{$this->c2key}:1:email" => "cleita@berkeley.ca",
            "{$this->c2key}:1:value" => "0"
        ])->set_user($this->u_carole), $this->paper()));
        xassert($ps->execute_save());

        // although save succeeded, attempt to decertify was ignored
        $prow = $this->paper();
        $ov = $prow->option($this->cert1);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert_not_in_eqq($this->u_carole->contactId, $ov->value_list());
        xassert(!$ov->option->value_present($ov));
        $ov = $prow->option($this->cert2);
        xassert_in_eqq($this->u_sally->contactId, $ov->value_list());
        xassert_in_eqq($this->u_carole->contactId, $ov->value_list());
        xassert($ov->option->value_present($ov));
        xassert_gt($prow->timeSubmitted, 0); // submit finally succeeded
        xassert_le($prow->timeWithdrawn, 0);
    }

    function test_withdraw_revive() {
        xassert_assign($this->u_chair, "action,paper\nwithdraw,{$this->pid}");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Aucert1",
            "sf/1/id" => $this->cert1->id,
            "sf/1/required" => "submit"
        ]);
        xassert($sv->execute());
        $this->cert1 = $this->conf->options()->option_by_id($this->cert1->id);
        $this->cert2 = $this->conf->options()->option_by_id($this->cert2->id);

        // reviving paper will turn it into a draft because it's missing
        // a required certification
        xassert_assign($this->u_carole, "action,paper\nrevive,{$this->pid}");

        $prow = $this->paper();
        xassert_le($prow->timeSubmitted, 0);
        xassert_le($prow->timeWithdrawn, 0);
    }

    function finalize() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => $this->cert1->id,
            "sf/1/delete" => 1,
            "sf/2/id" => $this->cert2->id,
            "sf/2/delete" => 1
        ]);
        xassert($sv->execute());
    }
}
