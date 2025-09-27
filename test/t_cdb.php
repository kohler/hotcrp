<?php
// t_cdb.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

#[RequireCdb(true)]
class Cdb_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var UserStatus
     * @readonly */
    public $us1;
    /** @var Contact
     * @readonly */
    public $user_chair;
    /** @var \mysqli
     * @readonly */
    public $cdb;

    const MARINA = "marina@poema.ru";

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->us1 = new UserStatus($conf->root_user());
        $this->user_chair = $conf->checked_user_by_email("chair@_.com");

        if (!($this->cdb = $conf->contactdb())) {
            error_log("! Error: The test contactdb has not been initialized.");
            error_log("! You may need to run `lib/createdb.sh -c test/cdb-options.php --no-dbuser --batch`.");
            exit(1);
        }
    }

    function test_setup() {
        $removables = ["te@tl.edu", "te2@tl.edu", "akhmatova@poema.ru", "leopard@fart.edu", "puma@fart.edu"];
        $this->conf->qe("delete from ContactInfo where email?a", $removables);
        Dbl::qe($this->conf->contactdb(), "delete from ContactInfo where email?a", $removables);
    }

    function test_passwords_1() {
        user(self::MARINA)->change_password("rosdevitch");
        xassert_eqq(password(self::MARINA), "");
        xassert_neqq(password(self::MARINA, true), "");
        xassert(user(self::MARINA)->check_password("rosdevitch"));
        $this->conf->qe("update ContactInfo set password=? where contactId=?", "rosdevitch", user(self::MARINA)->contactId);
        xassert_neqq(password(self::MARINA), "");
        xassert_neqq(password(self::MARINA, true), "");
        xassert(user(self::MARINA)->check_password("rosdevitch"));

        // different password in localdb => both passwords work
        save_password(self::MARINA, "crapdevitch", false);
        xassert(user(self::MARINA)->check_password("crapdevitch"));
        xassert(user(self::MARINA)->check_password("rosdevitch"));

        // change contactdb password => both passwords change
        user(self::MARINA)->change_password("dungdevitch");
        xassert(user(self::MARINA)->check_password("dungdevitch"));
        xassert(!user(self::MARINA)->check_password("assdevitch"));
        xassert(!user(self::MARINA)->check_password("rosdevitch"));
        xassert(!user(self::MARINA)->check_password("crapdevitch"));

        // update contactdb password => old local password useless
        save_password(self::MARINA, "isdevitch", true);
        xassert_eqq(password(self::MARINA), "");
        xassert(user(self::MARINA)->check_password("isdevitch"));
        xassert(!user(self::MARINA)->check_password("dungdevitch"));

        // update local password only
        save_password(self::MARINA, "ncurses", false);
        xassert_eqq(password(self::MARINA), "ncurses");
        xassert_neqq(password(self::MARINA, true), "ncurses");
        xassert(user(self::MARINA)->check_password("ncurses"));

        // logging in with global password makes local password obsolete
        Conf::advance_current_time(Conf::$now + 3);
        xassert(user(self::MARINA)->check_password("isdevitch"));
        Conf::advance_current_time(Conf::$now + 3);
        xassert(!user(self::MARINA)->check_password("ncurses"));

        // null contactdb password => password is unset
        save_password(self::MARINA, null, true);
        $info = user(self::MARINA)->check_password_info("ncurses");
        xassert(!$info["ok"]);
        xassert($info["unset"] ?? null);

        // restore to "this is a cdb password"
        user(self::MARINA)->change_password("isdevitch");
        xassert_eqq(password(self::MARINA), "");
        save_password(self::MARINA, "isdevitch", true);
        xassert_eqq(password(self::MARINA, true), "isdevitch");
        // current status: local password is empty, global password "isdevitch"
    }

    function test_password_encryption() {
        // checking an unencrypted password encrypts it
        $mu = user(self::MARINA);
        xassert($mu->check_password("isdevitch"));
        $cdbpw = password(self::MARINA, true);
        xassert_eqq(substr($cdbpw, 0, 3), " \$\$");
        xassert_eqq(password(self::MARINA), "");

        // checking an encrypted password doesn't change it
        xassert(user(self::MARINA)->check_password("isdevitch"));
        xassert_eqq(password(self::MARINA, true), $cdbpw);
    }

    function test_cdb_import_1() {
        $result = Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Te', lastName='Thamrongrattanarit', email='te@tl.edu', affiliation='Brandeis University', collaborators='Computational Linguistics Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
        assert(!Dbl::is_error($result));
        Dbl::free($result);
        xassert(!maybe_user("te@tl.edu"));

        $u = $this->conf->cdb_user_by_email("te@tl.edu");
        xassert(!!$u);
        xassert_eqq($u->firstName, "Te");
        xassert_eqq($u->disabled_flags(), 0);

        // inserting them should succeed and borrow their data
        $acct = $this->us1->save_user((object) ["email" => "te@tl.edu"]);
        xassert(!!$acct);

        $te = user("te@tl.edu");
        xassert(!!$te);
        xassert_eqq($te->firstName, "Te");
        xassert_eqq($te->lastName, "Thamrongrattanarit");
        xassert_eqq($te->affiliation, "Brandeis University");
        xassert($te->check_password("isdevitch"));
        xassert_eqq($te->collaborators(), "Computational Linguistics Magazine");
    }

    function test_change_email() {
        $result = Dbl::qe($this->cdb, "insert into ContactInfo set firstName='', lastName='Thamrongrattanarit 2', email='te2@tl.edu', affiliation='Brandeis University or something', collaborators='Newsweek Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
        xassert(!Dbl::is_error($result));
        Dbl::free($result);

        $u = $this->conf->cdb_user_by_email("te@tl.edu");
        xassert(!!$u);
        xassert_eqq($u->firstName, "Te");
        xassert_eqq($u->disabled_flags(), 0);

        $u = $this->conf->cdb_user_by_email("te2@tl.edu");
        xassert(!!$u);
        xassert_eqq($u->firstName, "");
        xassert_eqq($u->disabled_flags(), 0);

        // changing email works locally
        user("te@tl.edu")->change_email("te2@tl.edu");
        $te = maybe_user("te@tl.edu");
        xassert(!$te);

        $te2 = user("te2@tl.edu");
        xassert(!!$te2);
        xassert_eqq($te2->firstName, "Te");
        xassert_eqq($te2->lastName, "Thamrongrattanarit");
        xassert_eqq($te2->affiliation, "Brandeis University");

        $te2_cdb = $this->conf->fresh_cdb_user_by_email("te2@tl.edu");
        xassert(!!$te2_cdb);
        xassert_eqq($te2_cdb->firstName, "Te");
        xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 2");
        xassert_eqq($te2_cdb->email, "te2@tl.edu");
        xassert_eqq($te2_cdb->affiliation, "Brandeis University or something");
        xassert_eqq($te2_cdb->disabled_flags(), 0);

        // changing local email does not change cdb
        $acct = $this->us1->save_user((object) ["email" => "te2@tl.edu", "lastName" => "Thamrongrattanarit 1", "firstName" => "Te 1"]);
        xassert(!!$acct);

        $te2 = user("te2@tl.edu");
        xassert_eqq($te2->firstName, "Te 1");
        xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
        xassert_eqq($te2->affiliation, "Brandeis University");

        $te2_cdb = $this->conf->fresh_cdb_user_by_email("te2@tl.edu");
        xassert(!!$te2_cdb);
        xassert_eqq($te2_cdb->firstName, "Te");
        xassert_eqq($te2_cdb->lastName, "Thamrongrattanarit 2");
        xassert_eqq($te2_cdb->email, "te2@tl.edu");
        xassert_eqq($te2_cdb->affiliation, "Brandeis University or something");
        xassert_eqq($te2_cdb->disabled_flags(), 0);
    }

    function test_simplify_whitespace_on_save() {
        $acct = $this->us1->save_user((object) ["email" => "te2@tl.edu", "lastName" => " Thamrongrattanarit  1  \t", "firstName" => "Te  1", "affiliation" => "  Brandeis   Friendiversity"]);
        xassert(!!$acct);
        $te2 = user("te2@tl.edu");
        xassert_eqq($te2->firstName, "Te 1");
        xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
        xassert_eqq($te2->affiliation, "Brandeis Friendiversity");
    }

    function test_chair_update_no_cdb() {
        Contact::set_main_user(user("marina@poema.ru"));
        $te2 = user("te2@tl.edu");
        $te2_cdb_first = $te2->cdb_user()->firstName;
        $te2_cdb_last = $te2->cdb_user()->lastName;
        Dbl::qe($this->cdb, "update ContactInfo set affiliation='' where email='te2@tl.edu'");
        $acct = $this->us1->save_user((object) ["firstName" => "Wacky", "affiliation" => "String", "email" => "te2@tl.edu"]);
        xassert(!!$acct);
        $te2 = user("te2@tl.edu");
        xassert(!!$te2);
        xassert_eqq($te2->firstName, "Wacky");
        xassert_eqq($te2->lastName, "Thamrongrattanarit 1");
        xassert_eqq($te2->affiliation, "String");
        $te2_cdb = $this->conf->fresh_cdb_user_by_email("te2@tl.edu");
        xassert(!!$te2_cdb);
        xassert_eqq($te2_cdb->firstName, $te2_cdb_first);
        xassert_eqq($te2_cdb->lastName, $te2_cdb_last);
        xassert_eqq($te2_cdb->affiliation, "String");
    }

    function test_cdb_import_2() {
        $acct = $this->us1->save_user((object) ["email" => "te@tl.edu"]);
        xassert(!!$acct);
        $te = user("te@tl.edu");
        xassert_eqq($te->email, "te@tl.edu");
        xassert_eqq($te->firstName, "Te");
        xassert_eqq($te->lastName, "Thamrongrattanarit");
        xassert_eqq($te->affiliation, "Brandeis University");
        xassert_eqq($te->collaborators(), "Computational Linguistics Magazine");
    }

    function test_create_no_password_mail() {
        MailChecker::clear();
        $anna = "akhmatova@poema.ru";
        xassert(!maybe_user($anna));
        $acct = $this->us1->save_user((object) ["email" => $anna, "first" => "Anna", "last" => "Akhmatova"]);
        xassert(!!$acct);
        Dbl::qe("delete from ContactInfo where email=?", $anna);
        $this->conf->invalidate_user(Contact::make_email($this->conf, $anna));
        Dbl::qe($this->cdb, "update ContactInfo set passwordUseTime=1 where email=?", $anna);
        save_password($anna, "aquablouse", true);
        MailChecker::check0();
    }

    function test_author_becomes_contact() {
        $user_estrin = user("estrin@usc.edu");
        $user_floyd = user("floyd@EE.lbl.gov");
        $user_van = user("van@ee.lbl.gov");
        xassert(!maybe_user("akhmatova@poema.ru")); // but she is in cdb

        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json((object) [
            "id" => 1,
            "authors" => ["puneet@catarina.usc.edu", $user_estrin->email,
                          $user_floyd->email, $user_van->email, "akhmatova@poema.ru"]
        ]);

        $paper1 = $this->user_chair->checked_paper_by_id(1);
        $user_anna = user("akhmatova@poema.ru");
        xassert(!!$user_anna);
        xassert($user_anna->act_author_view($paper1));
        xassert($user_estrin->act_author_view($paper1));
        xassert($user_floyd->act_author_view($paper1));
        xassert($user_van->act_author_view($paper1));
    }

    function test_add_annes() {
        // user merging
        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "tags" => ["a#1"], "roles" => (object) ["pc" => true], "first" => "Anne Elizabeth", "last" => "Dudfield"]);
        $this->us1->save_user((object) ["email" => "anne2@dudfield.org", "first" => "Anne", "last" => "Dudfield", "tags" => ["a#2", "b#3"], "roles" => (object) ["sysadmin" => true], "collaborators" => "derpo\n"]);
        $user_anne1 = user("anne1@dudfield.org");
        $a1id = $user_anne1->contactId;
        xassert_eqq($user_anne1->firstName, "Anne Elizabeth");
        xassert_eqq($user_anne1->lastName, "Dudfield");
        xassert_eqq($user_anne1->collaborators(), "");
        xassert_eqq($user_anne1->tag_value("a"), 1.0);
        xassert_eqq($user_anne1->tag_value("b"), null);
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC);
        xassert_eqq($user_anne1->email, "anne1@dudfield.org");
        xassert_assign($user_anne1, "paper,tag\n1,~butt#1\n2,~butt#2");

        $user_anne2 = user("anne2@dudfield.org");
        $a2id = $user_anne2->contactId;
        xassert_eqq($user_anne2->firstName, "Anne");
        xassert_eqq($user_anne2->lastName, "Dudfield");
        xassert_eqq($user_anne2->collaborators(), "All (derpo)");
        xassert_eqq($user_anne2->tag_value("a"), 2.0);
        xassert_eqq($user_anne2->tag_value("b"), 3.0);
        xassert_eqq($user_anne2->roles, Contact::ROLE_ADMIN);
        xassert_eqq($user_anne2->email, "anne2@dudfield.org");
        xassert_assign($user_anne2, "paper,tag\n2,~butt#3\n3,~butt#4");
        xassert_assign($this->user_chair, "paper,action,user\n1,conflict,anne2@dudfield.org");
        xassert($user_anne1 && $user_anne2);

        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->tag_value("{$a1id}~butt"), 1.0);
        xassert_eqq($paper1->tag_value("{$a2id}~butt"), null);
        $paper2 = $this->conf->checked_paper_by_id(2);
        xassert_eqq($paper2->tag_value("{$a1id}~butt"), 2.0);
        xassert_eqq($paper2->tag_value("{$a2id}~butt"), 3.0);
        $paper3 = $this->conf->checked_paper_by_id(3);
        xassert_eqq($paper3->tag_value("{$a1id}~butt"), null);
        xassert_eqq($paper3->tag_value("{$a2id}~butt"), 4.0);
    }

    function test_role_save_formats() {
        // different forms of profile saving
        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => "pc"]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC);

        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => ["pc", "sysadmin"]]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_ADMIN);

        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => "chair, sysadmin"]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_CHAIR | Contact::ROLE_ADMIN);

        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => "-chair"]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_ADMIN);

        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => "-sysadmin"]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC);

        $this->us1->save_user((object) ["email" => "anne1@dudfield.org", "roles" => "+chair"]);
        $user_anne1 = user("anne1@dudfield.org");
        xassert_eqq($user_anne1->roles, Contact::ROLE_PC | Contact::ROLE_CHAIR);
    }

    function test_import_props() {
        // betty1 is in neither db;
        // betty2 is in local db with no password or name;
        // betty3-5 are in cdb with name but no password;
        Dbl::qe($this->conf->dblink, "insert into ContactInfo (email, password) values ('betty2@manchette.net','')");
        Dbl::qe($this->cdb, "insert into ContactInfo (email, password, firstName, lastName) values
            ('betty3@manchette.net','','Betty','Shabazz'),
            ('betty4@manchette.net','','Betty','Kelly'),
            ('betty5@manchette.net','','Betty','Davis')");
        foreach (["betty3@manchette.net", "betty4@manchette.net", "betty5@manchette.net"] as $email) {
            $this->conf->invalidate_user(Contact::make_cdb_email($this->conf, $email));
        }

        // registration name populates new records
        $u = Contact::make_keyed($this->conf, [
            "email" => "betty1@manchette.net",
            "name" => "Betty Grable"
        ])->store();
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Grable");
        xassert(!$u->is_disabled());
        $u = $this->conf->fresh_cdb_user_by_email("betty1@manchette.net");
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Grable");
        xassert(!$u->is_disabled());

        // registration name replaces empty local name, populates new cdb record
        $u = Contact::make_keyed($this->conf, [
            "email" => "betty2@manchette.net",
            "name" => "Betty Apiafi"
        ])->store();
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Apiafi");
        xassert(!$u->is_disabled());
        $u = $this->conf->fresh_cdb_user_by_email("betty2@manchette.net");
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Apiafi");
        xassert(!$u->is_disabled());

        // cdb name overrides registration name
        $u = Contact::make_keyed($this->conf, [
            "email" => "betty3@manchette.net",
            "name" => "Betty Crocker"
        ])->store();
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Shabazz");
        xassert(!$u->is_disabled());
        $u = $this->conf->fresh_cdb_user_by_email("betty3@manchette.net");
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Shabazz");
        xassert(!$u->is_disabled());

        // registration affiliation replaces empty affiliations
        $u = Contact::make_keyed($this->conf, [
            "email" => "betty4@manchette.net",
            "name" => "Betty Crocker",
            "affiliation" => "France"
        ]);
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Crocker");
        xassert_eqq($u->affiliation, "France");
        $u = $u->store();
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Kelly");
        xassert_eqq($u->affiliation, "France");
        xassert(!$u->is_disabled());
        $u = $this->conf->fresh_cdb_user_by_email("betty4@manchette.net");
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Kelly");
        xassert_eqq($u->affiliation, "France");
        xassert(!$u->is_disabled());

        // ensure_account_here
        $u = $this->conf->fresh_user_by_email("betty5@manchette.net");
        xassert(!$u);
        $u = $this->conf->cdb_user_by_email("betty5@manchette.net");
        xassert(!$u->is_disabled());
        $u->ensure_account_here();
        $u = $this->conf->checked_user_by_email("betty5@manchette.net");
        xassert($u->has_account_here());
        xassert_eqq($u->firstName, "Betty");
        xassert_eqq($u->lastName, "Davis");
        xassert(!$u->is_disabled());
    }

    function test_pc_json() {
        $pc_json = $this->conf->hotcrp_pc_json($this->user_chair, Conf::PCJM_UI);
        xassert_eqq($pc_json->pc[0]->email, "anne1@dudfield.org");
        $this->conf->sort_by_last = true;
        $this->conf->invalidate_caches(["pc" => true]);
        $pc_json = $this->conf->hotcrp_pc_json($this->user_chair, Conf::PCJM_UI);
        xassert_eqq($pc_json->pc[0]->email, "mgbaker@cs.stanford.edu");
        xassert_eqq($pc_json->pc[0]->uid, 12);
        xassert_eqq($pc_json->pc[0]->lastpos, 5);
        xassert_eqq($pc_json->pc[5]->email, "vera@bombay.com");
        xassert_eqq($pc_json->pc[5]->uid, 21);
        xassert_eqq($pc_json->pc[5]->lastpos, 5);
    }

    function test_cdb_update() {
        // Betty is in the local db, but not yet the contact db;
        // cdb_update should put her in the cdb
        Dbl::qe($this->conf->dblink, "insert into ContactInfo set email='betty6@manchette.net', password='Fart', firstName='Betty', lastName='Knowles'");
        $u = $this->conf->checked_user_by_email("betty6@manchette.net");
        xassert(!!$u);
        xassert(!$u->cdb_user());
        $u->update_cdb();
        $v = $u->cdb_user();
        xassert(!!$v);
        xassert_eqq($v->firstName, "Betty");
        xassert_eqq($v->lastName, "Knowles");
    }

    function test_email_authored_papers() {
        // Cengiz is in localdb and cdb as placeholder
        $u = $this->conf->fresh_user_by_email("cengiz@isi.edu");
        xassert(!!$u);
        xassert_eqq($u->firstName, "Cengiz");
        xassert_eqq($u->lastName, "Alaettinoğlu");
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $ldb_cid = $u->contactId;

        $u = $this->conf->cdb_user_by_email("cengiz@isi.edu");
        xassert(!!$u);
        xassert_eqq($u->firstName, "Cengiz");
        xassert_eqq($u->lastName, "Alaettinoğlu");
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $cdb_cid = $u->contactId;

        // remove cdb user's roles
        Dbl::qe($this->cdb, "delete from Roles where contactDbId=?", $cdb_cid);

        // make cdb user non-disabled, but empty name
        Dbl::qe($this->cdb, "update ContactInfo set email=?, password=?, firstName=?, lastName=?, cflags=cflags&~? where email=?",
            'cenGiz@isi.edu', 'TEST PASSWORD', '',
            '', Contact::CFM_DISABLEMENT, 'cengiz@isi.edu');
        $this->conf->invalidate_user(Contact::make_cdb_email($this->conf, "cengiz@isi.edu"));

        // creating a local user updates empty name from contactdb
        $u = Contact::make_email($this->conf, "Cengiz@isi.edu")->store();
        xassert($u->contactId > 0);
        xassert_eqq($u->firstName, "Cengiz");
        xassert_eqq($u->lastName, "Alaettinoğlu");

        $cdbu = $this->conf->fresh_cdb_user_by_email("CENGiz@ISI.edu");
        xassert_eqq($cdbu->firstName, "Cengiz");
        xassert_eqq($cdbu->lastName, "Alaettinoğlu");

        // both accounts have correct roles
        $prow = $this->conf->checked_paper_by_id(27);
        xassert($prow->has_author($u));
        xassert($u->is_author());
        xassert_eqq($u->cdb_roles(), Contact::ROLE_AUTHOR);
        xassert_eqq($cdbu->roles, Contact::ROLE_AUTHOR);
    }

    function test_claim_review() {
        // Sophia is in cdb, not local db
        Dbl::qe($this->cdb, "insert into ContactInfo set email='sophia@dros.nl', password='', firstName='Sophia', lastName='Dros'");
        $user_sophia = $this->conf->fresh_user_by_email("sophia@dros.nl");
        xassert(!$user_sophia);
        $user_sophia = $this->conf->cdb_user_by_email("sophia@dros.nl");
        xassert(!!$user_sophia);

        // Cengiz gets a review
        $user_cengiz = $this->conf->checked_user_by_email("cengiz@isi.edu");
        $rrid = $this->user_chair->assign_review(3, $user_cengiz, REVIEW_EXTERNAL);
        xassert($rrid > 0);
        $paper3 = $this->conf->checked_paper_by_id(3);
        $rrow = $paper3->fresh_review_by_id($rrid);
        xassert(!!$rrow);
        xassert_eqq($rrow->contactId, $user_cengiz->contactId);

        // current user is logged in as both Cengiz and Sophia
        $qsession = new MemoryQsession("dfnoafndwqf", ["us" => ["cengiz@isi.edu", "sophia@dros.nl"]]);

        // current user cannot edit Cengiz's review for some random user
        $qreq = (new Qrequest("POST", ["p" => "3", "r" => "{$rrid}", "email" => "betty6@manchette.net"]))
            ->set_qsession($qsession);
        $result = RequestReview_API::claimreview($user_cengiz, $qreq, $paper3);
        xassert_eqq($result->content["ok"], false);
        $rrow = $paper3->fresh_review_by_id($rrid);
        xassert(!!$rrow);
        xassert_eqq($rrow->contactId, $user_cengiz->contactId);

        // current user can claim Sophia's review, even as Cengiz
        $qreq = (new Qrequest("POST", ["p" => "3", "r" => "{$rrid}", "email" => "sophia@dros.nl"]))
            ->set_qsession($qsession);
        $result = RequestReview_API::claimreview($user_cengiz, $qreq, $paper3);
        xassert_eqq($result->content["ok"], true);
        $user_sophia = $this->conf->checked_user_by_email("sophia@dros.nl");
        xassert(!!$user_sophia);
        $rrow = $paper3->fresh_review_by_id($rrid);
        xassert(!!$rrow);
        xassert_neqq($rrow->contactId, $user_cengiz->contactId);
        xassert_eqq($rrow->contactId, $user_sophia->contactId);
    }

    function test_cdb_roles_1() {
        // saving PC role works
        $acct = $this->conf->fresh_user_by_email("jmrv@startup.com");
        xassert(!$acct);
        $acct = $this->us1->save_user((object) ["email" => "jmrv@startup.com", "lastName" => "Rutherford", "firstName" => "John", "roles" => "pc"]);
        xassert(!!$acct);
        $acct = $this->conf->fresh_user_by_email("jmrv@startup.com");
        xassert(($acct->roles & Contact::ROLE_PCLIKE) === Contact::ROLE_PC);

        $acct = $this->conf->fresh_cdb_user_by_email("jmrv@startup.com");
        xassert_eqq($acct->roles, Contact::ROLE_PC);
    }

    function test_cdb_roles_2() {
        // authorship is encoded in placeholder
        $acct = $this->conf->fresh_user_by_email("pavlin@isi.edu");
        xassert_eqq($acct->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert($acct->is_author());
        xassert_eqq($acct->cdb_roles(), Contact::ROLE_AUTHOR);
        $acct = $this->conf->fresh_cdb_user_by_email("pavlin@isi.edu");
        xassert_eqq($acct->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert_eqq($acct->roles, Contact::ROLE_AUTHOR);

        // saving without disablement wakes up cdb
        $acct = $this->us1->save_user((object) ["email" => "pavlin@isi.edu"]);
        xassert_eqq($acct->disabled_flags(), 0);

        $acct = $this->conf->fresh_cdb_user_by_email("pavlin@isi.edu");
        xassert_eqq($acct->disabled_flags(), 0);
    }

    function test_cdb_roles_3() {
        // saving a user with a role does both role and authorship
        $email = "lam@cs.utexas.edu";
        $acct = $this->conf->fresh_user_by_email($email);
        xassert_eqq($acct->disabled_flags(), Contact::CF_PLACEHOLDER);

        $acct = $this->us1->save_user((object) ["email" => $email, "roles" => "sysadmin"]);
        xassert(!!$acct);
        xassert_eqq($acct->disabled_flags(), 0);
        xassert($acct->is_author());
        xassert($acct->isPC);
        xassert($acct->privChair);
        xassert_eqq($acct->cdb_roles(), Contact::ROLE_AUTHOR | Contact::ROLE_ADMIN);

        $acct = $this->conf->fresh_cdb_user_by_email($email);
        xassert_eqq($acct->roles, Contact::ROLE_AUTHOR | Contact::ROLE_ADMIN);
    }

    function test_placeholder() {
        // create a placeholder user
        Contact::make_keyed($this->conf, [
            "email" => "scapegoat@harvard.edu",
            "firstName" => "Shane",
            "disablement" => Contact::CF_PLACEHOLDER
        ])->store();

        $u = $this->conf->checked_user_by_email("scapegoat@harvard.edu");
        xassert_eqq($u->firstName, "Shane");
        xassert_eqq($u->lastName, "");
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $cdb_u = $u->cdb_user();
        xassert_eqq($cdb_u->firstName, "Shane");
        xassert_eqq($cdb_u->lastName, "");
        xassert_eqq($cdb_u->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert_eqq($cdb_u->is_placeholder(), true);

        // creating another placeholder will override properties
        Contact::make_keyed($this->conf, [
            "email" => "scapegoat@harvard.edu",
            "firstName" => "Shapely",
            "lastName" => "Montréal",
            "disablement" => Contact::CF_PLACEHOLDER
        ])->store();

        $u = $this->conf->checked_user_by_email("scapegoat@harvard.edu");
        xassert_eqq($u->firstName, "Shapely");
        xassert_eqq($u->lastName, "Montréal");
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $cdb_u = $u->cdb_user();
        xassert_eqq($cdb_u->firstName, "Shapely");
        xassert_eqq($cdb_u->lastName, "Montréal");
        xassert_eqq($cdb_u->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert_eqq($cdb_u->prop("password"), " unset");

        // enable user
        Contact::make_keyed($this->conf, [
            "email" => "scapegoat@harvard.edu",
            "disablement" => 0
        ])->store();

        $u = $this->conf->checked_user_by_email("scapegoat@harvard.edu");
        xassert_eqq($u->firstName, "Shapely");
        xassert_eqq($u->lastName, "Montréal");
        xassert_eqq($u->disabled_flags(), 0);
        $cdb_u = $u->cdb_user();
        xassert_eqq($cdb_u->firstName, "Shapely");
        xassert_eqq($cdb_u->lastName, "Montréal");
        xassert_eqq($cdb_u->disabled_flags(), 0);

        // saving another placeholder will not override properties
        // or disable the current user
        Contact::make_keyed($this->conf, [
            "email" => "scapegoat@harvard.edu",
            "firstName" => "Stickly",
            "lastName" => "Milquetoast",
            "disablement" => Contact::CF_PLACEHOLDER
        ])->store();

        $u = $this->conf->checked_user_by_email("scapegoat@harvard.edu");
        xassert_eqq($u->firstName, "Shapely");
        xassert_eqq($u->lastName, "Montréal");
        xassert_eqq($u->disabled_flags(), 0);
        $cdb_u = $u->cdb_user();
        xassert_eqq($cdb_u->firstName, "Shapely");
        xassert_eqq($cdb_u->lastName, "Montréal");
        xassert_eqq($cdb_u->disabled_flags(), 0);
    }

    function xxx_test_updatecontactdb_authors() {
        // XXX This test requires email_authored_papers.
        $paper9 = $this->conf->checked_paper_by_id(9);
        $aulist = $paper9->author_list();
        $aulist[] = Author::make_keyed([
            "name" => "Nonsense Person",
            "email" => "NONSENSE@xx.com"
        ]);
        $austr = join("\n", array_map(function ($au) { return $au->unparse_tabbed(); }, $aulist));
        $this->conf->qe("update Paper set authorInformation=? where paperId=9", $austr);

        $paper10 = $this->conf->checked_paper_by_id(10);
        $aulist = $paper9->author_list();
        $aulist[] = Author::make_keyed([
            "email" => "nonsense@xx.com",
            "affiliation" => "Nonsense University"
        ]);
        $austr = join("\n", array_map(function ($au) { return $au->unparse_tabbed(); }, $aulist));
        $this->conf->qe("update Paper set authorInformation=? where paperId=10", $austr);

        $u = $this->conf->fresh_user_by_email("nonsense@xx.com");
        xassert(!$u);
        $u = $this->conf->fresh_cdb_user_by_email("nonsense@xx.com");
        xassert(!$u);

        $ucdb = new UpdateContactdb_Batch($this->conf, ["authors" => false]);
        $ucdb->run_authors();

        $u = $this->conf->fresh_user_by_email("nonsense@xx.com");
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert_eqq($u->email, "NONSENSE@xx.com");
        xassert_eqq($u->firstName, "Nonsense");
        xassert_eqq($u->lastName, "Person");
        xassert_eqq($u->affiliation, "Nonsense University");
        $paper9 = $this->conf->checked_paper_by_id(9);
        xassert($paper9->has_author($u));
        $paper10 = $this->conf->checked_paper_by_id(10);
        xassert($paper10->has_author($u));

        $u = $this->conf->fresh_cdb_user_by_email("nonsense@xx.com");
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert_eqq($u->email, "NONSENSE@xx.com");
        xassert_eqq($u->firstName, "Nonsense");
        xassert_eqq($u->lastName, "Person");
        xassert_eqq($u->affiliation, "Nonsense University");
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function test_cdb_user_new_paper() {
        $u = $this->conf->fresh_cdb_user_by_email("newuser@fresh.com");
        xassert(!$u);

        $result = Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Te', lastName='Thamrongrattanarit', email='newuser@fresh.com', affiliation='Brandeis University', collaborators='Computational Linguistics Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
        $u = $this->conf->fresh_cdb_user_by_email("newuser@fresh.com");
        xassert(!!$u);
        xassert(!$this->conf->fresh_user_by_email("newuser@fresh.com"));

        $so = $this->conf->setting("sub_open");
        $ss = $this->conf->setting("sub_sub");
        $this->conf->save_setting("sub_open", 1);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now + 10);
        $mu = Contact::$main_user;
        Contact::$main_user = $u;
        xassert(!$u->has_account_here());

        $p = PaperInfo::make_new($u, null);
        $ps = new PaperStatus($u);
        $pid = $ps->save_paper_web(new Qrequest("POST", [
            "title" => "A Systematic Study of Neural Discourse Models for Implicit Discourse Relation",
            "has_authors" => 1,
            "authors:1:name" => "Attapol T. Rutherford",
            "authors:1:affiliation" => "Yelp",
            "authors:1:email" => "newuser@fresh.com",
            "authors:2:name" => "Vera Demberg",
            "authors:2:affiliation" => "Saarland University",
            "authors:2:email" => "vera@coli.uni-saarland.de",
            "authors:3:name" => "Nianwen Xue",
            "authors:3:affiliation" => "Brandeis University",
            "authors:3:email" => "xuen@brandeis.edu",
            "abstract" => "Inferring implicit discourse relations in natural language text is the most difficult subtask in discourse parsing. Many neural network models have been proposed to tackle this problem."
        ]), $p);
        xassert_gt($pid, 0);
        xassert($u->has_account_here());

        $p1 = $this->conf->checked_paper_by_id($pid);
        xassert($p1->has_author($u));
        $u1 = $this->conf->fresh_user_by_email("newuser@fresh.com");
        xassert(!$u1->is_dormant());
        xassert_eqq($p1->conflict_type($u1), CONFLICT_CONTACTAUTHOR | CONFLICT_AUTHOR);
        xassert(!empty($p1->conflict_type_list()));

        $this->conf->save_setting("sub_open", $so);
        $this->conf->save_refresh_setting("sub_sub", $ss);
        Contact::$main_user = $mu;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function test_cdb_user_new_paper_2() {
        $u = $this->conf->fresh_cdb_user_by_email("newuser1@fresh.com");
        xassert(!$u);

        $result = Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Te', lastName='Thamrongrattanarit', email='newuser1@fresh.com', affiliation='Brandeis University', collaborators='Computational Linguistics Magazine', password=' $$2y$10$/URgqlFgQHpfE6mg4NzJhOZbg9Cc2cng58pA4cikzRD9F0qIuygnm'");
        $u = $this->conf->fresh_cdb_user_by_email("newuser1@fresh.com");
        xassert(!!$u);
        xassert(!$this->conf->fresh_user_by_email("newuser1@fresh.com"));

        $so = $this->conf->setting("sub_open");
        $ss = $this->conf->setting("sub_sub");
        $this->conf->save_setting("sub_open", 1);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now + 10);
        $mu = Contact::$main_user;
        Contact::$main_user = $u;
        xassert(!$u->has_account_here());

        $p = PaperInfo::make_new($u, null);
        $ps = new PaperStatus($u);
        $pid = $ps->save_paper_web(new Qrequest("POST", [
            "title" => "A Systematic Study of Neural Discourse Models for Implicit Discourse Relation",
            "has_authors" => 1,
            "authors:1:email" => "fart@f.com",
            "has_contacts" => 1,
            "contacts:1:email" => "newuser1??????<Dmaoxn@)(*\$IR",
            "contacts:1:active" => 1,
            "contacts:2:email" => "newuser2@fresh.com",
            "contacts:2:active" => 1,
            "abstract" => "Inferring implicit discourse relations in natural language text is the most difficult subtask in discourse parsing. Many neural network models have been proposed to tackle this problem."
        ]), $p);
        xassert_gt($pid, 0);
        xassert($u->has_account_here());

        $p1 = $this->conf->checked_paper_by_id($pid);
        xassert($p1->has_author($u));
        $u1 = $this->conf->fresh_user_by_email("newuser1@fresh.com");
        xassert(!$u1->is_dormant());
        xassert_eqq($p1->conflict_type($u1), CONFLICT_CONTACTAUTHOR);
        xassert(!empty($p1->conflict_type_list()));

        $this->conf->save_setting("sub_open", $so);
        $this->conf->save_refresh_setting("sub_sub", $ss);
        Contact::$main_user = $mu;
    }

    function test_cdb_new_locally_disabled_user() {
        $u = $this->conf->fresh_cdb_user_by_email("belling@cat.com");
        xassert(!$u);

        $this->conf->set_opt("disableNonPC", 1);
        $this->conf->refresh_settings();

        $p = PaperInfo::make_new($this->conf->root_user(), null);
        $ps = (new PaperStatus($this->conf->root_user()))->set_disable_users(true);
        $pid = $ps->save_paper_web(new Qrequest("POST", [
            "title" => "A Systematic Sterdy of Neural Discourse Models for Implicit Discourse Relation",
            "has_authors" => 1,
            "authors:1:email" => "belling@cat.com",
            "has_contacts" => 1,
            "contacts:1:email" => "belling@cat.com",
            "contacts:1:active" => 1,
            "abstract" => "Inferring implicit discourse relations in natural language text is the most difficult subtask in discourse parsing. Many neural network models have been proposed to tackle this problem."
        ]), $p);
        xassert_gt($pid, 0);

        $u = $this->conf->fresh_cdb_user_by_email("belling@cat.com");
        xassert_eqq($u->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_ROLEDISABLED);
        $u2 = $this->conf->fresh_user_by_email("belling@cat.com");
        xassert_eqq($u2->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_UDISABLED | Contact::CF_ROLEDISABLED);

        $u = $this->conf->fresh_cdb_user_by_email("kitcat@cat.com");
        xassert(!$u);

        $u = Contact::make_keyed($this->conf, [
            "firstName" => "Kit",
            "lastName" => "Cat",
            "email" => "kitcat@cat.com",
            "affiliation" => "Fart University",
            "disablement" => Contact::CF_UDISABLED
        ])->store();
        xassert_eqq($u->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_UDISABLED | Contact::CF_ROLEDISABLED);
        $uu = $u->cdb_user();
        xassert_eqq($uu->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_ROLEDISABLED);

        Dbl::qe($this->conf->dblink, "insert into ContactInfo set firstName='Martha', lastName='Tanner', email='marthatanner@cat.com', affiliation='University of Connecticut', password='', cflags=1");
        Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Martha', lastName='Tanner', email='marthatanner@cat.com', affiliation='University of Connecticut', password=' unset', cflags=2");
        $u = $this->conf->fresh_user_by_email("marthatanner@cat.com");
        xassert_eqq($u->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_ROLEDISABLED | Contact::CF_UDISABLED);
        $u->update_cdb();
        $uu = $this->conf->fresh_cdb_user_by_email("marthatanner@cat.com");
        xassert_eqq($uu->disabled_flags() & ~Contact::CF_PLACEHOLDER, Contact::CF_ROLEDISABLED);

        $this->conf->set_opt("disableNonPC", null);
        $this->conf->refresh_settings();
    }

    function check_disablement($email, $want) {
        $u = $this->conf->fresh_cdb_user_by_email($email);
        xassert_eqq($u->disabled_flags(), $want);
        $u = $this->conf->fresh_user_by_email($email);
        xassert_eqq($u->disabled_flags(), $want);
    }

    function test_cdb_placeholder_reset() {
        $u = $this->conf->fresh_cdb_user_by_email("fuzzle@cat.com");
        xassert(!$u);
        $u = $this->conf->fresh_cdb_user_by_email("gussie@cat.com");
        xassert(!$u);

        $p = PaperInfo::make_new($this->conf->root_user(), null);
        $ps = new PaperStatus($this->conf->root_user());
        $pid = $ps->save_paper_web(new Qrequest("POST", [
            "title" => "Beautiful Companions",
            "has_authors" => 1,
            "authors:1:email" => "fuzzle@cat.com",
            "authors:2:email" => "gussie@cat.com",
            "has_contacts" => 1,
            "contacts:1:email" => "fuzzle@cat.com",
            "contacts:1:active" => 1,
            "abstract" => "Tiny Paws Catlets!"
        ]), $p);
        xassert_gt($pid, 0);

        $this->check_disablement("fuzzle@cat.com", 0);
        $this->check_disablement("gussie@cat.com", Contact::CF_PLACEHOLDER);

        // fuzzle has cdb roles
        $u = $this->conf->fresh_cdb_user_by_email("fuzzle@cat.com");
        xassert(!!$u);
        $roles = Dbl::fetch_ivalue($this->cdb, "select roles from Roles where confid=? and contactDbId=?", $this->conf->cdb_confid(), $u->contactDbId);
        xassert_eqq($roles, Contact::ROLE_AUTHOR);

        // reset gussie's password
        $gussie = $this->conf->fresh_user_by_email("gussie@cat.com");
        $qreq = TestQreq::post_page("newaccount", ["email" => "gussie@cat.com"])->set_user($gussie);
        $cs = $this->conf->page_components($gussie, $qreq);
        $sp = $cs->callable("Signin_Page");
        try {
            $sp->create_request($gussie, $qreq);
        } catch (Redirection $r) {
        }
        xassert_str_starts_with($sp->_reset_tokstr ?? "", "hcpw1");

        $qreq = TestQreq::post_page("resetpassword", ["email" => "gussie@cat.com"])->set_user($gussie);
        $qreq->set_req("resetcap", $sp->_reset_tokstr);
        $qreq->set_req("password", "Tiny dancer");
        $qreq->set_req("password2", "Tiny dancer");
        $cs = $this->conf->page_components($gussie, $qreq);
        $sp = $cs->callable("Signin_Page");
        try {
            $sp->reset_request($gussie, $qreq, $cs);
        } catch (Redirection $r) {
        }

        // now gussie is no longer disabled and has cdb roles
        $this->check_disablement("gussie@cat.com", 0);
        $u = $this->conf->fresh_cdb_user_by_email("gussie@cat.com");
        xassert(!!$u);
        $roles = Dbl::fetch_ivalue($this->cdb, "select roles from Roles where confid=? and contactDbId=?", $this->conf->cdb_confid(), $u->contactDbId);
        xassert_eqq($roles, Contact::ROLE_AUTHOR);
    }

    function test_cdb_example_user() {
        // users with example email addresses are not saved to cdb
        xassert(!$this->conf->fresh_cdb_user_by_email("wonderful@example.edu"));
        $acct = $this->us1->save_user((object) ["email" => "wonderful@example.edu"]);
        xassert(!!$acct);
        xassert(!$acct->cdb_user());
        xassert(!$this->conf->fresh_cdb_user_by_email("wonderful@example.edu"));
    }

    function test_cdb_user_update() {
        $gussie = $this->conf->user_by_email("gussie@cat.com");
        xassert_eqq($gussie->firstName, "");
        xassert_eqq($gussie->lastName, "");

        // update CDB user name, add fake role
        Dbl::qe($this->conf->contactdb(), "update ContactInfo set firstName='Gussie', lastName='Onufryk', orcid='XXXX-9999-CATK-ITTY', updateTime=? where email='gussie@cat.com'", Conf::$now);
        Dbl::qe($this->conf->contactdb(), "insert into ConferenceUpdates (confid, user_update_at) values (?,?) on duplicate key update user_update_at=greatest(?,user_update_at)", $this->conf->cdb_confid(), Conf::$now, Conf::$now);

        // if requested fields already present, CdbUserUpdate does nothing
        $nq = Dbl::$nqueries;
        (new CdbUserUpdate($this->conf))->add("floyd@ee.lbl.gov")->check("firstName");
        xassert_le(Dbl::$nqueries, $nq + 2);

        // does something
        Conf::advance_current_time(Conf::$now + 5);
        (new CdbUserUpdate($this->conf))->add("gussie@cat.com")->check();

        $gussie = $this->conf->user_by_email("gussie@cat.com");
        xassert_eqq($gussie->firstName, "Gussie");
        xassert_eqq($gussie->lastName, "Onufryk");
        xassert_eqq($gussie->unaccentedName, "gussie onufryk");
        xassert_ge($this->conf->setting("__cdb_user_update_at"), Conf::$now - 3);
    }

    function test_import_secondary() {
        Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Leopard', lastName='Face', email='leopard@fart.edu', affiliation='Place University', collaborators='Newsweek Magazine', password=' unset', cflags=0");
        Dbl::qe($this->cdb, "insert into ContactInfo set firstName='Puma', lastName='Face', email='puma@fart.edu', affiliation='Place University', collaborators='Newsweek Magazine', password=' unset', cflags=0");

        $cu_leopard = $this->conf->cdb_user_by_email("leopard@fart.edu");
        $cu_puma = $this->conf->cdb_user_by_email("puma@fart.edu");
        (new ContactPrimary)->link($cu_puma, $cu_leopard);

        $lu_leopard = $this->conf->user_by_email("leopard@fart.edu");
        $lu_puma = $this->conf->user_by_email("puma@fart.edu");
        $lu_mtnlion = $this->conf->user_by_email("mtnlion@fart.edu");
        xassert_eqq($lu_leopard, null);
        xassert_eqq($lu_puma, null);
        xassert_eqq($lu_mtnlion, null);

        $lu_puma = $this->conf->ensure_user_by_email("puma@fart.edu");
        xassert(!!$lu_puma);
        $lu_leopard = $this->conf->user_by_email("leopard@fart.edu");
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($lu_puma->primaryContactId, $lu_leopard->contactId);
        $primary_emails = $this->conf->resolve_primary_emails(["PuMa@fART.edu"]);
        xassert_eqq($primary_emails, ["leopard@fart.edu"]);

        // changing a primary into a secondary also affects its secondaries
        Dbl::qe($this->conf->dblink, "insert into ContactInfo set firstName='Mountain Lion', lastName='Face', email='mtnlion@fart.edu', affiliation='Place University', collaborators='Newsweek Magazine', password=' unset', cflags=0");
        $lu_mtnlion = $this->conf->fresh_user_by_email("mtnlion@fart.edu");
        (new ContactPrimary)->link($lu_leopard, $lu_mtnlion);
        xassert_eqq($lu_mtnlion->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($lu_puma->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_puma->primaryContactId, $lu_mtnlion->contactId);
        xassert_eqq($lu_leopard->primaryContactId, $lu_mtnlion->contactId);
        xassert_eqq($lu_mtnlion->primaryContactId, 0);

        (new ContactPrimary)->link($lu_mtnlion, $lu_leopard);
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($lu_puma->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->primaryContactId, $lu_leopard->contactId);
        xassert_eqq($lu_puma->primaryContactId, $lu_leopard->contactId);
        xassert_eqq($lu_leopard->primaryContactId, 0);

        // remove secondaries one at a time
        (new ContactPrimary)->link($lu_mtnlion, null);
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($lu_puma->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->primaryContactId, 0);
        xassert_eqq($lu_puma->primaryContactId, $lu_leopard->contactId);
        xassert_eqq($lu_leopard->primaryContactId, 0);

        (new ContactPrimary)->link($lu_puma, null);
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_puma->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->primaryContactId, 0);
        xassert_eqq($lu_puma->primaryContactId, 0);
        xassert_eqq($lu_leopard->primaryContactId, 0);

        // link secondary groups
        Dbl::qe($this->conf->dblink, "insert into ContactInfo set firstName='Cougar', lastName='Brain', email='cougar@fart.edu', affiliation='Place University', collaborators='Newsweek Magazine', password=' unset', cflags=0");
        $lu_cougar = $this->conf->user_by_email("cougar@fart.edu");
        (new ContactPrimary)->link($lu_puma, $lu_cougar);
        (new ContactPrimary)->link($lu_leopard, $lu_mtnlion);
        // have puma->cougar, leopard->mtnlion
        // link cougar->leopard
        // want puma->leopard, cougar->leopard, mtnlion independent
        // (it's not clear what the right semantics are)
        (new ContactPrimary)->link($lu_cougar, $lu_leopard);
        xassert_eqq($lu_cougar->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_leopard->cflags & Contact::CF_PRIMARY, Contact::CF_PRIMARY);
        xassert_eqq($lu_puma->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_mtnlion->cflags & Contact::CF_PRIMARY, 0);
        xassert_eqq($lu_cougar->primaryContactId, $lu_leopard->contactId);
        xassert_eqq($lu_leopard->primaryContactId, 0);
        xassert_eqq($lu_mtnlion->primaryContactId, 0);
        xassert_eqq($lu_puma->primaryContactId, $lu_leopard->contactId);

        (new ConfInvariants($this->conf))->check_users();
    }
}
