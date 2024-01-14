<?php
// t_permission.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Permission_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $u_chair; // chair
    /** @var Contact */
    public $u_estrin; // pc
    /** @var Contact */
    public $u_kohler; // none
    /** @var Contact */
    public $u_marina; // pc
    /** @var Contact */
    public $u_van; // none
    /** @var Contact */
    public $u_mgbaker; // pc
    /** @var Contact */
    public $u_shenker; // pc, chair
    /** @var Contact */
    public $u_mogul;
    /** @var Contact */
    public $u_nobody;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $this->u_kohler = $conf->checked_user_by_email("kohler@seas.harvard.edu");
        $this->u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $this->u_van = $conf->checked_user_by_email("van@ee.lbl.gov");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_shenker = $conf->checked_user_by_email("shenker@parc.xerox.com");
        $this->u_mogul = $conf->checked_user_by_email("mogul@wrl.dec.com");
        $this->u_nobody = Contact::make($conf);
    }

    function test_search_basics() {
        assert_search_papers($this->u_chair, "timers", "1 21");
        assert_search_papers($this->u_chair, "ti:timers", "1");
        assert_search_papers($this->u_chair, "routing", "2 3 4 11 19 21 27");
        assert_search_papers($this->u_chair, "routing scalable", "4 19");
        assert_search_papers($this->u_chair, "ti:routing ti:scalable", "4");
        assert_search_papers($this->u_chair, "ti:(routing scalable)", "4");
        assert_search_papers($this->u_chair, "ab:routing ab:scalable", "19");
        assert_search_papers($this->u_chair, "ab:routing scalable", "4 19");
        assert_search_papers($this->u_chair, "ab:(routing scalable)", "19");
        assert_search_papers($this->u_chair, "many", "8 16 25 29");
        assert_search_papers($this->u_chair, "many applications", "8 25");
        assert_search_papers($this->u_chair, "\"many applications\"", "8");
        assert_search_papers($this->u_chair, "“many applications”", "8");
        assert_search_papers($this->u_chair, "“many applications“", "8");
        assert_search_papers_ignore_warnings($this->u_chair, "status:mis[take", "");
    }

    // check permissions on paper
    /** @param PaperInfo $paper1 */
    function check_paper1($paper1) {
        xassert_neqq($paper1, null);

        xassert($this->u_chair->can_view_paper($paper1));
        xassert($this->u_estrin->can_view_paper($paper1));
        xassert($this->u_marina->can_view_paper($paper1));
        xassert($this->u_van->can_view_paper($paper1));
        xassert(!$this->u_kohler->can_view_paper($paper1));
        xassert(!$this->u_nobody->can_view_paper($paper1));

        xassert($this->u_chair->allow_administer($paper1));
        xassert(!$this->u_estrin->allow_administer($paper1));
        xassert(!$this->u_marina->allow_administer($paper1));
        xassert(!$this->u_van->allow_administer($paper1));
        xassert(!$this->u_kohler->allow_administer($paper1));
        xassert(!$this->u_nobody->allow_administer($paper1));

        xassert($this->u_chair->can_administer($paper1));
        xassert(!$this->u_estrin->can_administer($paper1));
        xassert(!$this->u_marina->can_administer($paper1));
        xassert(!$this->u_van->can_administer($paper1));
        xassert(!$this->u_kohler->can_administer($paper1));
        xassert(!$this->u_nobody->can_administer($paper1));

        xassert($this->u_chair->can_view_tags($paper1));
        xassert(!$this->u_estrin->can_view_tags($paper1));
        xassert($this->u_marina->can_view_tags($paper1));
        xassert(!$this->u_van->can_view_tags($paper1));
        xassert(!$this->u_kohler->can_view_tags($paper1));
        xassert(!$this->u_nobody->can_view_tags($paper1));

        xassert($this->u_chair->can_view_tag($paper1, "foo"));
        xassert($this->u_chair->can_view_tag($paper1, "~foo"));
        xassert($this->u_chair->can_view_tag($paper1, $this->u_chair->contactId . "~foo"));
        xassert($this->u_chair->can_view_tag($paper1, "~~foo"));
        xassert($this->u_chair->can_view_tag($paper1, $this->u_estrin->contactId . "~foo"));
        xassert(!$this->u_estrin->can_view_tag($paper1, "foo"));
        xassert(!$this->u_estrin->can_view_tag($paper1, "~foo"));
        xassert(!$this->u_estrin->can_view_tag($paper1, $this->u_chair->contactId . "~foo"));
        xassert(!$this->u_estrin->can_view_tag($paper1, "~~foo"));
        xassert(!$this->u_estrin->can_view_tag($paper1, $this->u_estrin->contactId . "~foo"));
        xassert($this->u_marina->can_view_tag($paper1, "foo"));
        xassert($this->u_marina->can_view_tag($paper1, "~foo"));
        xassert(!$this->u_marina->can_view_tag($paper1, $this->u_chair->contactId . "~foo"));
        xassert(!$this->u_marina->can_view_tag($paper1, "~~foo"));
        xassert(!$this->u_marina->can_view_tag($paper1, $this->u_estrin->contactId . "~foo"));
        xassert($this->u_marina->can_view_tag($paper1, $this->u_marina->contactId . "~foo"));

        xassert($this->u_chair->can_edit_paper($paper1));
        xassert($this->u_estrin->can_edit_paper($paper1));
        xassert(!$this->u_marina->can_edit_paper($paper1));
        xassert($this->u_van->can_edit_paper($paper1));
        xassert(!$this->u_kohler->can_edit_paper($paper1));
        xassert(!$this->u_nobody->can_edit_paper($paper1));
    }

    /** @return string */
    function get_pcassignment_csv() {
        list($header, $texts) = ListAction::pcassignments_csv_data($this->u_chair, range(1, 30));
        $csvg = new CsvGenerator;
        return $csvg->select($header)->append($texts)->unparse();
    }

    /** @param Contact $contact
     * @param string $text
     * @param bool $override
     * @return bool */
    static function run_assignment($contact, $text, $override = false) {
        $aset = new AssignmentSet($contact);
        $aset->set_override_conflicts($override);
        $aset->parse($text);
        return $aset->execute();
    }

    function test_main() {
        ConfInvariants::test_all($this->conf);

        $this->conf->save_setting("sub_open", 1);
        $this->conf->save_setting("sub_update", Conf::$now + 10);
        $this->conf->save_setting("sub_sub", Conf::$now + 10);
        $this->conf->refresh_settings();

        // load users
        $user_chair = $this->u_chair;
        $user_estrin = $this->u_estrin;
        $user_kohler = $this->u_kohler;
        $user_marina = $this->u_marina;
        $user_van = $this->u_van;
        $user_mgbaker = $this->u_mgbaker;
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $user_varghese = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // pc
        $user_wilma = $this->conf->checked_user_by_email("ojuelegba@gmail.com"); // pc
        // $user_mjh = $this->conf->checked_user_by_email("mjh@isi.edu"); // pc
        $user_pdruschel = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc
        $user_randy = $this->conf->checked_user_by_email("randy@cs.berkeley.edu"); // author
        $user_lixia = $this->conf->checked_user_by_email("lixia@cs.ucla.edu"); // pc

        // users are different
        xassert($user_chair->contactId != $user_estrin->contactId);

        $paper1 = $user_chair->checked_paper_by_id(1);
        $this->check_paper1($paper1);
        $this->check_paper1($user_estrin->checked_paper_by_id(1));
        xassert($paper1->author_user()->can_view_paper($paper1));
        xassert($paper1->author_user()->can_edit_paper($paper1));

        // grant user capability to read paper 1, check it doesn't allow PC view
        $user_capability = Contact::make($this->conf);
        xassert(!$user_capability->can_view_paper($paper1));
        $user_capability->apply_capability_text(AuthorView_Capability::make($paper1));
        xassert(!$user_capability->contactId);
        xassert($user_capability->can_view_paper($paper1));
        xassert(!$user_capability->allow_administer($paper1));
        xassert(!$user_capability->can_administer($paper1));
        xassert(!$user_capability->can_view_tags($paper1));
        xassert(!$user_capability->can_edit_paper($paper1));

        // rejected papers cannot be updated
        xassert($user_estrin->can_edit_paper($paper1));
        xassert_assign($user_chair, "paper,action,decision\n1,decision,no\n");
        $paper1 = $user_chair->checked_paper_by_id(1);
        xassert(!$user_estrin->can_edit_paper($paper1));

        // clear decision
        xassert_eq($paper1->outcome, -1);
        xassert_assign($user_chair, "paper,action,decision\n1,cleardecision,yes\n");
        $paper1 = $user_chair->checked_paper_by_id(1);
        xassert_eq($paper1->outcome, -1);
        xassert_assign($user_chair, "paper,action,decision\n1,cleardecision,no\n");
        $paper1 = $user_chair->checked_paper_by_id(1);
        xassert_eq($paper1->outcome, 0);

        // check `paperacc` invariant
        xassert_eq($this->conf->setting("paperacc") ?? 0, 0);
        xassert_assign($user_chair, "paper,action,decision\n1,decision,yes\n");
        xassert_eq($this->conf->setting("paperacc") ?? 0, 1);
        MailChecker::check0();
        xassert_assign($user_chair, "paper,action\n1,withdraw\n");
        MailChecker::check_db("withdraw-1-admin");
        xassert_eq($this->conf->setting("paperacc") ?? 0, 0);
        xassert_assign($user_chair, "paper,action\n1,revive\n");
        xassert_eq($this->conf->setting("paperacc") ?? 0, 1);
        xassert_assign($user_chair, "paper,action,decision\n1,cleardecision,yes\n");
        xassert_eq($this->conf->setting("paperacc") ?? 0, 0);

        // change submission date
        $this->conf->save_setting("sub_update", Conf::$now - 5);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now - 5);
        $paper1 = $user_chair->checked_paper_by_id(1);
        xassert($user_chair->can_edit_paper($paper1));
        xassert(!$paper1->author_user()->can_edit_paper($paper1));
        xassert(!$user_estrin->can_edit_paper($paper1));
        xassert(!$user_marina->can_edit_paper($paper1));
        xassert(!$user_van->can_edit_paper($paper1));
        xassert(!$user_kohler->can_edit_paper($paper1));
        xassert(!$this->u_nobody->can_edit_paper($paper1));

        // role assignment works
        $paper18 = $user_mgbaker->checked_paper_by_id(18);
        xassert($this->u_shenker->can_administer($paper18));
        xassert(!$user_mgbaker->can_administer($paper1));
        xassert(!$user_mgbaker->can_administer($paper18));

        // author derivation works
        xassert($user_mgbaker->act_author_view($paper18));

        // limits are obeyed: all searches return subsets of `viewable`
        assert_search_papers($user_chair, ["q" => "", "t" => "s"], "1-30");
        assert_search_papers($this->u_shenker, ["q" => "", "t" => "s"], "1-30");
        assert_search_papers($user_randy, ["q" => "", "t" => "s"], "6");
        assert_search_papers($user_chair, ["q" => "", "t" => "a"], "");
        assert_search_papers($this->u_shenker, ["q" => "", "t" => "a"], "20 29 30");
        assert_search_papers($user_randy, ["q" => "", "t" => "a"], "6");

        assert_search_ids($user_chair, ["q" => "", "t" => "s"], "1-30");
        assert_search_ids($this->u_shenker, ["q" => "", "t" => "s"], "1-30");
        assert_search_ids($user_randy, ["q" => "", "t" => "s"], "6");
        assert_search_ids($user_chair, ["q" => "", "t" => "a"], "");
        assert_search_ids($this->u_shenker, ["q" => "", "t" => "a"], "20 29 30");
        assert_search_ids($user_randy, ["q" => "", "t" => "a"], "6");

        // correct conflict information returned
        $j = search_json($this->u_shenker, ["q" => "1 2 3 4 5 15-18", "reviewer" => $user_mgbaker], "id conf");
        xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
        xassert_eqq($j[3]["conf"], "Y");
        xassert_eqq($j[18]["conf"], "Y");
        foreach ([1, 2, 4, 5, 15, 16, 17] as $i) {
            xassert_eqq($j[$i]["conf"], "N");
        }

        $j = search_json($this->u_shenker, ["q" => "1 2 3 4 5 15-18", "reviewer" => $user_jon], "id conf");
        xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
        xassert_eqq($j[17]["conf"], "Y");
        foreach ([1, 2, 3, 4, 5, 15, 16, 18] as $i) {
            xassert_eqq($j[$i]["conf"], "N");
        }

        assert_search_papers($user_chair, "re:estrin", "4 8 18");
        assert_search_papers($this->u_shenker, "re:estrin", "4 8 18");

        // normals don't see conflicted reviews
        assert_search_papers($user_mgbaker, "re:estrin", "4 8");

        // make reviewer identity anonymous until review completion
        $this->conf->save_refresh_setting("rev_open", 1);
        $this->conf->save_refresh_setting("viewrevid", 0);
        $this->conf->refresh_settings();
        assert_search_papers($user_mgbaker, "re:varghese", "");

        $revreq = ["s01" => 5, "s02" => 4, "ready" => true];
        save_review(1, $user_mgbaker, $revreq);
        assert_search_papers($user_mgbaker, "re:varghese", "1");

        // or lead assignment
        assert_search_papers($user_marina, "re:varghese", "");
        xassert_assign($user_chair, "paper,lead\n1,marina\n", true);
        assert_search_papers($user_marina, "re:varghese", "1");
        assert_search_papers($user_marina, "re:\"washington\"", "1");
        assert_search_papers($user_marina, "re:\"washington louis\"", "1");

        // check comment identity
        xassert_eqq($this->conf->_au_seerev, null);
        xassert_eqq($this->conf->setting("rev_blind"), Conf::BLIND_ALWAYS);
        $comment1 = new CommentInfo($paper1);
        $c1ok = $comment1->save_comment(["text" => "test", "visibility" => "a", "blind" => false], $user_mgbaker);
        xassert($c1ok);
        xassert(!$user_van->can_view_comment($paper1, $comment1));
        xassert(!$user_van->can_view_comment_identity($paper1, $comment1));
        xassert_eqq($user_van->add_comment_state($paper1), 0);
        $this->conf->save_refresh_setting("cmt_author", 1);
        xassert_eqq($user_van->add_comment_state($paper1), 0);
        $this->conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_YES);
        xassert_neqq($user_van->add_comment_state($paper1), 0);
        $this->conf->save_refresh_setting("cmt_author", null);
        xassert_eqq($user_van->add_comment_state($paper1), 0);
        xassert($user_van->can_view_comment($paper1, $comment1));
        xassert(!$user_van->can_view_comment_identity($paper1, $comment1));
        $this->conf->save_refresh_setting("rev_blind", Conf::BLIND_OPTIONAL);
        xassert($user_van->can_view_comment($paper1, $comment1));
        xassert(!$user_van->can_view_comment_identity($paper1, $comment1));
        $c1ok = $comment1->save_comment(["text" => "test", "visibility" => "a", "blind" => false], $user_mgbaker);
        xassert($c1ok);
        xassert($user_van->can_view_comment_identity($paper1, $comment1));
        $this->conf->save_refresh_setting("rev_blind", null);
        xassert(!$user_van->can_view_comment_identity($paper1, $comment1));
        $this->conf->save_refresh_setting("au_seerev", null);
        xassert_eqq($this->conf->_au_seerev, null);

        // check comment/review visibility when reviews are incomplete
        $this->conf->save_refresh_setting("viewrev", Conf::VIEWREV_UNLESSINCOMPLETE);
        Contact::update_rights();
        $review1 = fresh_review($paper1, $user_mgbaker);
        xassert(!$user_wilma->has_review());
        xassert(!$user_wilma->has_outstanding_review());
        xassert($user_wilma->can_view_review($paper1, $review1));
        xassert($user_mgbaker->has_review());
        xassert($user_mgbaker->has_outstanding_review());
        xassert($user_mgbaker->can_view_review($paper1, $review1));
        xassert($user_lixia->has_review());
        xassert($user_lixia->has_outstanding_review());
        xassert(!$user_lixia->can_view_review($paper1, $review1));
        xassert($user_varghese->has_review());
        xassert($user_varghese->has_outstanding_review());
        xassert(!$user_varghese->can_view_review($paper1, $review1));
        xassert($user_marina->has_review());
        xassert($user_marina->has_outstanding_review());
        xassert($user_marina->can_view_review($paper1, $review1));
        $review2 = save_review(1, $user_lixia, $revreq);
        MailChecker::check_db("test01-save-review1B");
        xassert($user_wilma->can_view_review($paper1, $review1));
        xassert($user_wilma->can_view_review($paper1, $review2));
        xassert($user_mgbaker->can_view_review($paper1, $review1));
        xassert($user_mgbaker->can_view_review($paper1, $review2));
        xassert($user_lixia->can_view_review($paper1, $review1));
        xassert($user_lixia->can_view_review($paper1, $review2));
        xassert(!$user_varghese->can_view_review($paper1, $review1));
        xassert(!$user_varghese->can_view_review($paper1, $review2));
        xassert($user_marina->can_view_review($paper1, $review1));
        xassert($user_marina->can_view_review($paper1, $review2));

        $this->conf->save_refresh_setting("viewrev", Conf::VIEWREV_UNLESSANYINCOMPLETE);
        Contact::update_rights();
        xassert($user_wilma->can_view_review($paper1, $review1));
        xassert($user_wilma->can_view_review($paper1, $review2));
        xassert($user_mgbaker->can_view_review($paper1, $review1));
        xassert($user_mgbaker->can_view_review($paper1, $review2));
        xassert($user_lixia->can_view_review($paper1, $review1));
        xassert($user_lixia->can_view_review($paper1, $review2));
        xassert(!$user_marina->can_view_review($paper1, $review1));
        xassert(!$user_marina->can_view_review($paper1, $review2));

        self::run_assignment($user_chair, "paper,action,email\n3,primary,ojuelegba@gmail.com\n");
        xassert($user_wilma->has_outstanding_review());
        xassert(!$user_wilma->can_view_review($paper1, $review1));
        xassert(!$user_wilma->can_view_review($paper1, $review2));
        save_review(3, $user_wilma, $revreq);
        $paper3 = $this->conf->checked_paper_by_id(3);
        $rrow3 = $paper3->checked_review_by_user($user_wilma);
        xassert_eqq($rrow3->reviewStatus, ReviewInfo::RS_COMPLETED);
        xassert(!$user_wilma->has_outstanding_review());
        xassert($user_wilma->can_view_review($paper1, $review1));
        xassert($user_wilma->can_view_review($paper1, $review2));
    }

    /** @param list<Contact> $users */
    static private function check_rights_version($users) {
        Contact::update_rights();
        foreach ($users as $u) {
            $u->check_rights_version();
        }
    }

    function test_dangerous_track_mask() {
        $user_chair = $this->u_chair;
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $user_marina = $this->conf->checked_user_by_email("marina@poema.ru"); // pc
        $user_pfrancis = $this->conf->checked_user_by_email("pfrancis@ntt.jp"); // pc, blue
        xassert_eqq($user_chair->contactTags, null);
        xassert_eqq($user_jon->contactTags, " red#0");
        xassert_eqq($user_marina->contactTags, null);
        xassert_eqq($user_pfrancis->contactTags, " blue#0");
        $users = [$user_chair, $user_jon, $user_marina, $user_pfrancis];

        xassert_eqq($this->conf->setting("tracks"), null);
        self::check_rights_version($users);
        xassert_eqq($user_chair->dangerous_track_mask(), 0);
        xassert_eqq($user_jon->dangerous_track_mask(), 0);
        xassert_eqq($user_marina->dangerous_track_mask(), 0);
        xassert_eqq($user_pfrancis->dangerous_track_mask(), 0);

        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"view\":\"-red\"}}");
        self::check_rights_version($users);
        xassert_eqq($user_chair->dangerous_track_mask(), 0);
        xassert_eqq($user_jon->dangerous_track_mask() & Track::BITS_VIEW, Track::BITS_VIEW);
        xassert_eqq($user_marina->dangerous_track_mask(), 0);
        xassert_eqq($user_pfrancis->dangerous_track_mask(), 0);

        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"view\":\"-red\"},\"_\":{\"view\":\"+blue\"}}");
        self::check_rights_version($users);
        xassert_eqq($user_chair->dangerous_track_mask(), 0);
        xassert_eqq($user_jon->dangerous_track_mask() & Track::BITS_VIEW, Track::BITS_VIEW);
        xassert_eqq($user_marina->dangerous_track_mask() & Track::BITS_VIEW, Track::BITS_VIEW);
        xassert_eqq($user_pfrancis->dangerous_track_mask(), 0);

        $this->conf->save_refresh_setting("tracks", null);
        self::check_rights_version($users);
    }

    function test_tags() {
        $user_chair = $this->u_chair;
        $user_mgbaker = $this->u_mgbaker;
        $user_estrin = $this->u_estrin;
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $user_varghese = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // pc

        // set up some tags and tracks
        self::run_assignment($user_chair, "paper,tag\n3 9 13 17,green\n", true);
        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"assrev\":\"-red\"}}");
        $paper13 = $user_jon->checked_paper_by_id(13);
        xassert(!$paper13->has_author($user_jon));
        xassert(!$paper13->has_reviewer($user_jon));
        xassert(!$this->conf->check_tracks($paper13, $user_jon, Track::ASSREV));
        xassert($user_jon->can_view_paper($paper13));
        xassert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper13));
        xassert(!$user_jon->can_accept_review_assignment($paper13));

        // tag searches
        assert_search_papers($user_chair, "#green", "3 9 13 17");
        Dbl::qe("insert into PaperTag (paperId,tag,tagIndex) values (1,?,10), (1,?,5), (2,?,3)",
                $user_jon->contactId . "~vote", $this->u_marina->contactId . "~vote", $this->u_marina->contactId . "~vote");
        assert_search_papers($user_jon, "#~vote", "1");
        assert_search_papers($user_jon, "#~vote≥10", "1");
        assert_search_papers($user_jon, "#~vote>10", "");
        assert_search_papers($user_jon, "#~vote=10", "1");
        assert_search_papers($user_jon, "#~vote<10", "");
        assert_search_papers($user_jon, "#~v*", "1");
        assert_search_papers($this->u_marina, "#~vote", "2 1");
        assert_search_papers($this->u_marina, "#~vote≥5", "1");
        assert_search_papers($this->u_marina, "#~vote>5", "");
        assert_search_papers($this->u_marina, "#~vote=5", "1");
        assert_search_papers($this->u_marina, "#~vote<5", "2");
        assert_search_papers($user_chair, "#marina~vote", "2 1");
        assert_search_papers($user_chair, "#red~vote", "1");

        // assign some tags using AssignmentSet interface
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(true);
        $assignset->parse("paper,action,tag,index
1-9,tag,g*#clear
2,tag,green,1\n");
        assert_search_papers($user_chair, "#green", "3 9 13 17");
        $assignset->execute();
        assert_search_papers($user_chair, "#green", "13 17 2");
        assert_search_papers($user_chair, "#green>0", "2");
        assert_search_papers($user_chair, "#green=1", "2");
        assert_search_papers($user_chair, "#green=0", "13 17");

        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(true);
        $assignset->parse("paper,action,tag,index
1,tag,~vote,clear
2,tag,marina~vote,clear\n");
        xassert_eqq($assignset->full_feedback_text(), "");
        $assignset->execute();
        assert_search_papers($user_chair, "#any~vote", "1");

        // check \v in AssignmentSet
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(true);
        $assignset->parse("paper,action,tag\n1,tag,fun#clear)nofun#clear\n");
        xassert_eqq($assignset->full_feedback_text(), "Invalid tag ‘)nofun#clear’\n");
        $assignset->execute();

        // check AssignmentSet conflict checking
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,email\n1,pri,estrin@usc.edu\n");
        xassert_eqq($assignset->full_feedback_text(), "Deborah Estrin <estrin@usc.edu> cannot review #1 because they are conflicted\n");
        $assignset->execute();
        assert_query("select email from PaperReview r join ContactInfo c on (c.contactId=r.contactId) where paperId=1 order by email", "lixia@cs.ucla.edu\nmgbaker@cs.stanford.edu\nvarghese@ccrc.wustl.edu");

        // check AssignmentSet error messages and landmarks
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,email\n1,pri,estrin@usc.edu\n", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "fart.txt:2: Deborah Estrin <estrin@usc.edu> cannot review #1 because they are conflicted\n");
        xassert(!$assignset->execute());

        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,email,landmark\n1,pri,estrin@usc.edu,butt.txt:740\n", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "butt.txt:740: Deborah Estrin <estrin@usc.edu> cannot review #1 because they are conflicted\n");
        xassert(!$assignset->execute());

        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,email,landmark,message\n1,pri,estrin@usc.edu,butt.txt:740\n1,error,none,butt.txt/10,GODDAMNIT", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "butt.txt/10: GODDAMNIT\nbutt.txt:740: Deborah Estrin <estrin@usc.edu> cannot review #1 because they are conflicted\n");
        xassert(!$assignset->execute());

        assert_search_papers($user_chair, "#testo", "");
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,tag,message,landmark\n1,tag,testo,,butt.txt:740\n1,error,,GODDAMNIT,butt.txt/10", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "butt.txt/10: GODDAMNIT\n");
        xassert(!$assignset->execute());

        assert_search_papers($user_chair, "#testo", "");
        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,tag,message,landmark\n1,tag,testo,,butt.txt:740\n1,warning,,GODDAMNIT,butt.txt/10", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "butt.txt/10: GODDAMNIT\n");
        xassert($assignset->execute());

        assert_search_papers($user_chair, "#testo", "1");
        xassert_assign($user_chair, "paper,tag\n1,testo#clear");

        $assignset = (new AssignmentSet($user_chair))->set_override_conflicts(false);
        $assignset->parse("paper,action,email,landmark,message\n,error,none,butt.txt/10,GODDAMNIT", "fart.txt");
        xassert_eqq($assignset->full_feedback_text(), "butt.txt/10: GODDAMNIT\n");
        xassert(!$assignset->execute());

        // more AssignmentSet conflict checking
        assert_search_papers($user_chair, "#fart", "");
        $assignset = (new AssignmentSet($this->u_estrin))->set_override_conflicts(false);
        $assignset->parse("paper,tag
        1,fart
        2,fart\n");
        xassert_eqq($assignset->full_feedback_text(), "You have a conflict with #1\n");

        xassert_assign($this->u_estrin, "paper,tag\n2,fart\n");
        assert_search_papers($user_chair, "#fart", "2");

        xassert_assign($user_chair, "paper,tag\n1,#fart\n");
        assert_search_papers($user_chair, "#fart", "1 2");
        assert_search_papers($this->u_estrin, "#fart", "2");

        // check twiddle tags
        xassert_assign($user_chair, "paper,tag\n1,~fart\n1,~~fart\n1,varghese~fart\n1,mjh~fart\n");
        $paper1 = $user_chair->checked_paper_by_id(1);
        xassert_eqq(paper_tag_normalize($paper1),
                    "fart chair~fart mjh~fart varghese~fart jon~vote#10 marina~vote#5 ~~fart");
        assert_search_papers($user_chair, "#~~*art", "1");

        xassert_assign($user_chair, "paper,tag\n1,all#none\n");
        $paper1->load_tags();
        xassert_eqq(paper_tag_normalize($paper1),
                    "mjh~fart varghese~fart jon~vote#10 marina~vote#5");

        xassert_assign($user_chair, "paper,tag\n1,fart\n");
        $paper1->load_tags();
        xassert_eqq(paper_tag_normalize($paper1),
                    "fart mjh~fart varghese~fart jon~vote#10 marina~vote#5");

        xassert_assign($user_varghese, "paper,tag\n1,all#clear\n1,~green\n");
        $paper1->load_tags();
        xassert_eqq(paper_tag_normalize($paper1),
                    "mjh~fart varghese~green jon~vote#10 marina~vote#5");

        xassert_assign($user_chair, "paper,tag\nall,fart#clear\n1,fart#4\n2,fart#5\n3,fart#6\n", true);
        assert_search_papers($user_chair, "order:fart", "1 2 3");
        xassert_eqq(search_text_col($user_chair, "order:fart", "tagval:fart"), "1 4\n2 5\n3 6\n");

        xassert_assign($user_chair, "action,paper,tag\nnexttag,6,fart\nnexttag,5,fart\nnexttag,4,fart\n", true);
        assert_search_papers($user_chair, "order:fart", "1 2 3 6 5 4");

        xassert_assign($user_chair, "action,paper,tag\nseqnexttag,7,fart#3\nseqnexttag,8,fart\n", true);
        assert_search_papers($user_chair, "order:fart", "7 8 1 2 3 6 5 4");

        xassert_assign($user_chair, "action,paper,tag\ncleartag,8,fArt\n", true);
        assert_search_papers($user_chair, "order:fart", "7 1 2 3 6 5 4");

        xassert_assign($user_chair, "action,paper,tag\ntag,8,fArt#4\n", true);
        assert_search_papers($user_chair, "order:fart", "7 8 1 2 3 6 5 4");

        $paper8 = $user_chair->checked_paper_by_id(8);
        xassert_eqq($paper8->tag_value("fart"), 4.0);
        xassert_str_contains($paper8->all_tags_text(), " fArt#");

        xassert_assign($user_chair, "action,paper,tag\ntag,8,fArt#(pid+104)\n", true);
        $paper8 = $user_chair->checked_paper_by_id(8);
        xassert_eqq($paper8->tag_value("fart"), 112.0);

        xassert_assign($user_chair, "action,paper,tag\ntag,8 9 10,fArt#(pid + 105) fart2#(1==1)\n", true);
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(8)), "fArt#113 fart2");
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(9)), "fArt#114 fart2");
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(10)), "fArt#115 fart2");

        xassert_assign($user_chair, "action,paper,tag\ntag,8 9 10,fArt#(pid<9 ? 4: false) fart2#clear\n", true);
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(8)), "fArt#4");
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(9)), "");
        xassert_eqq(paper_tag_normalize($user_chair->checked_paper_by_id(10)), "");

        // defined tags: chair
        xassert_assign($user_varghese, "paper,tag\n1,chairtest\n");
        assert_search_papers($user_varghese, "#chairtest", "1");
        xassert_assign($user_varghese, "paper,tag\n1,chairtest#clear\n");
        assert_search_papers($user_varghese, "#chairtest", "");

        xassert_eqq($this->conf->setting_data("tag_chair"), "accept pcpaper reject");
        $this->conf->save_refresh_setting("tag_chair", 1, "accept chairtest pcpaper reject");
        xassert_assign($user_chair, "paper,tag\n1,chairtest\n", true);
        assert_search_papers($user_chair, "#chairtest", "1");
        assert_search_papers($user_varghese, "#chairtest", "1");
        xassert_assign_fail($user_varghese, "paper,tag\n1,chairtest#clear\n");
        assert_search_papers($user_varghese, "#chairtest", "1");

        // pattern tags: chair
        xassert_assign($user_varghese, "paper,tag\n1,chairtest1\n");
        assert_search_papers($user_varghese, "#chairtest1", "1");
        xassert_assign($user_varghese, "paper,tag\n1,chairtest1#clear\n");
        assert_search_papers($user_varghese, "#chairtest1", "");

        $this->conf->save_refresh_setting("tag_chair", 1, "accept chairtest chairtest* pcpaper reject");
        $ct = $this->conf->tags()->find("chairtest0");
        xassert(!!$ct);
        xassert_assign($user_chair, "paper,tag\n1,chairtest1\n", true);
        assert_search_papers($user_chair, "#chairtest1", "1");
        assert_search_papers($user_varghese, "#chairtest1", "1");
        xassert_assign_fail($user_varghese, "paper,tag\n1,chairtest1#clear\n");
        assert_search_papers($user_varghese, "#chairtest1", "1");

        // pattern tag merging
        $this->conf->save_refresh_setting("tag_hidden", 1, "chair*");
        $ct = $this->conf->tags()->find("chairtest0");
        xassert($ct && $ct->is(TagInfo::TF_READONLY) && $ct->is(TagInfo::TF_HIDDEN));

        // colon tag setting
        xassert(!$this->conf->setting("has_colontag"));
        xassert_assign($user_chair, "paper,tag\n1,:poop:\n", true);
        xassert(!!$this->conf->setting("has_colontag"));

        // NOT searches
        assert_search_papers($user_chair, "#fart", "7 8 1 2 3 6 5 4");
        assert_search_papers($user_chair, "tag:#fart", "1 2 3 4 5 6 7 8");
        assert_search_papers($user_chair, "tag:fart", "1 2 3 4 5 6 7 8");
        assert_search_papers($user_chair, "NOT #fart", "9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($user_chair, "-#fart", "9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");

        // Check all tags
        assert_search_papers($user_chair, "#none", "9 10 11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");
        xassert_assign($user_chair, "paper,tag\n9,~private\n10,~~chair\n");
        assert_search_papers($user_chair, "#none", "11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($user_mgbaker, "#none", "3 9 10 11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");

        // restore chair tag setting
        $this->conf->save_refresh_setting("tag_chair", 1, "accept pcpaper reject");
    }

    function test_review_rounds() {
        $user_chair = $this->u_chair;

        // round searches
        assert_search_papers($user_chair, "re:huitema", "8 10 13");
        assert_search_papers($user_chair, "re:huitema round:R1", "13");
        assert_search_papers($user_chair, "round:R1", "12 13 17");
        assert_search_papers($user_chair, "round:R1 re:any", "12 13 17");
        assert_search_papers($user_chair, "round:R1:>=0", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");

        xassert_assign($user_chair, "action,paper,user,round\nclearreview,all,huitema,R1\n", true);
        assert_search_papers($user_chair, "re:huitema", "8 10");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,R1\n", true);
        assert_search_papers($user_chair, "round:R1 re:huitema", "13");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,R2\n", true);
        assert_search_papers($user_chair, "round:R1:huitema", "");
        assert_search_papers($user_chair, "round:R2:huitema", "13");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,:R1\n", true);
        assert_search_papers($user_chair, "round:R1:huitema", "");
        assert_search_papers($user_chair, "round:R2:huitema", "13");
        assert_search_papers($user_chair, "round:unnamed:huitema", "8 10");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,unnamed\n", true);
        assert_search_papers($user_chair, "round:R1:huitema", "");
        assert_search_papers($user_chair, "round:R2:huitema", "");
        assert_search_papers($user_chair, "round:unnamed:huitema", "8 10 13");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,:R1\n", true);
        assert_search_papers($user_chair, "round:R1:huitema", "");
        assert_search_papers($user_chair, "round:R2:huitema", "");
        assert_search_papers($user_chair, "round:unnamed:huitema", "8 10 13");

        xassert_assign($user_chair, "action,paper,user,round\nprimary,13,huitema,R1\n", true);
        assert_search_papers($user_chair, "round:R1:huitema", "13");
        assert_search_papers($user_chair, "round:R2:huitema", "");
        assert_search_papers($user_chair, "round:unnamed:huitema", "8 10");

        // search combinations
        assert_search_papers($user_chair, "re:huitema", "8 10 13");
        assert_search_papers($user_chair, "8 10 13 re:huitema", "8 10 13");
    }

    function test_comment_search() {
        $paper1 = $this->u_chair->checked_paper_by_id(1);
        $paper2 = $this->u_chair->checked_paper_by_id(2);
        $paper18 = $this->u_chair->checked_paper_by_id(18);
        xassert($this->u_mgbaker->add_comment_state($paper2) !== 0);
        xassert($this->u_mgbaker->add_comment_state($paper18) === 0);
        xassert($this->u_marina->add_comment_state($paper1) !== 0);
        xassert($this->u_marina->add_comment_state($paper18) !== 0);
        assert_search_papers($this->u_chair, "cmt:any", "1");
        assert_search_papers($this->u_chair, "has:comment", "1");
        assert_search_papers($this->u_chair, "has:response", "");
        assert_search_papers($this->u_chair, "has:author-comment", "1");
        $comment2 = new CommentInfo($paper18);
        $c2ok = $comment2->save_comment(["text" => "test", "visibility" => "a", "blind" => false], $this->u_marina);
        xassert($c2ok);
        assert_search_papers($this->u_chair, "cmt:any", "1 18");
        assert_search_papers($this->u_chair, "cmt:any>1", "");
        $comment3 = new CommentInfo($paper18);
        $c3ok = $comment3->save_comment(["text" => "test", "visibility" => "a", "blind" => false, "tags" => "redcmt"], $this->u_marina);
        xassert($c3ok);
        assert_search_papers($this->u_chair, "cmt:any>1", "18");
        assert_search_papers($this->u_chair, "cmt:jon", "");
        assert_search_papers($this->u_chair, "cmt:marina", "18");
        assert_search_papers($this->u_chair, "cmt:marina>1", "18");
        assert_search_papers($this->u_chair, "cmt:#redcmt", "18");
    }

    function test_comment_notification() {
        $this->conf->save_refresh_setting("viewrevid", 1);
        Contact::update_rights();

        $paper2 = $this->u_chair->checked_paper_by_id(2);
        xassert($paper2->has_reviewer($this->u_chair));
        $comment4 = new CommentInfo($paper2);
        $comment4->save_comment(["text" => "test", "visibility" => "p", "topic" => "paper", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment2");
        assert_search_papers($this->u_chair, "has:comment", "1 2 18");
        assert_search_papers($this->u_chair, "has:response", "");
        assert_search_papers($this->u_chair, "has:author-comment", "1 18");

        // if cannot see comment identity, then do not combine mails
        $this->conf->save_refresh_setting("viewrevid", null);
        $this->conf->save_refresh_setting("cmt_revid", 1);
        Contact::update_rights();

        $comment4x = new CommentInfo($paper2);
        $comment4x->save_comment(["text" => "my identity should be hidden", "visibility" => "p", "topic" => "paper", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment2-noid");

        $this->conf->save_refresh_setting("viewrevid", 1);
        $this->conf->save_refresh_setting("cmt_revid", null);
        Contact::update_rights();

        // turn off chair notification and insert comment
        $this->conf->qe("insert into PaperWatch (paperId, contactId, watch) values (2,?,?) ?U on duplicate key update watch=?U(watch)", $this->u_chair->contactId, Contact::WATCH_REVIEW_EXPLICIT);
        $paper2->load_watch();
        $comment5 = new CommentInfo($paper2);
        $comment5->save_comment(["text" => "second test", "visibility" => "p", "blind" => false], $this->u_mgbaker);
        MailChecker::check0();

        // explicit mention overrides no-notification
        $comment6 = new CommentInfo($paper2);
        $comment6->save_comment(["text" => "third test, @Jane Chair", "visibility" => "p", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment6");

        // restore watch
        $this->conf->qe("delete from PaperWatch where paperId=2 and contactId=?", $this->u_chair->contactId);
    }

    function test_viewrev() {
        $paper2 = $this->u_chair->checked_paper_by_id(2);
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $user_pdruschel = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc

        $this->conf->save_refresh_setting("viewrev", Conf::VIEWREV_AFTERREVIEW);
        Contact::update_rights();

        $review2a = fresh_review($paper2, $user_jon);
        xassert(!$review2a->reviewSubmitted && !$review2a->reviewAuthorSeen);
        xassert($review2a->reviewOrdinal == 0);
        xassert($user_jon->can_view_review($paper2, $review2a));
        xassert(!$user_pdruschel->can_view_review($paper2, $review2a));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2a));

        $revreq = ["s01" => 5, "s02" => 4, "ready" => true];
        $review2a = save_review(2, $user_jon, $revreq);
        MailChecker::check0();
        xassert($review2a->reviewSubmitted && !$review2a->reviewAuthorSeen);
        xassert($review2a->reviewOrdinal == 1);
        xassert($user_jon->can_view_review($paper2, $review2a));
        xassert(!$user_pdruschel->can_view_review($paper2, $review2a));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2a));

        $review2b = save_review(2, $user_pdruschel, $revreq);
        MailChecker::check_db("test01-review2B");
        xassert($user_jon->can_view_review($paper2, $review2a));
        xassert($user_pdruschel->can_view_review($paper2, $review2a));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2a));

        self::run_assignment($this->u_chair, "paper,action,email\n2,secondary,mgbaker@cs.stanford.edu\n");
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 1);
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2a));

        $user_external = Contact::make_keyed($this->conf, ["email" => "external@_.com", "name" => "External Reviewer"])->store();
        $this->u_mgbaker->assign_review(2, $user_external->contactId, REVIEW_EXTERNAL);
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == -1);
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2a));
        $review2c = fresh_review($paper2, $user_external);
        xassert(!$this->u_mgbaker->can_view_review($paper2, $review2c));
        $review2c = save_review(2, $user_external, $revreq);
        MailChecker::check_db("test01-review2C");
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 0);
        xassert($this->u_mgbaker->can_view_review($paper2, $review2a));
        xassert($this->u_mgbaker->can_view_review($paper2, $review2c));

        assert_search_papers($this->u_chair, "2 AND re:4", "2");

        // Previous notifications did not include chair because chair's own
        // review was incomplete. Changing chair watch should change
        // notification
        $comment7 = new CommentInfo($paper2);
        $comment7->save_comment(["text" => "Do not notify chair", "visibility" => "r", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment7");

        $this->conf->qe("insert into PaperWatch (paperId, contactId, watch) values (?,?,?) ?U on duplicate key update watch=?U(watch)", $paper2->paperId, $this->u_chair->contactId, Contact::WATCH_REVIEW_EXPLICIT | Contact::WATCH_REVIEW);
        $paper2->load_watch();
        $comment8 = new CommentInfo($paper2);
        $comment8->save_comment(["text" => "Do notify chair", "visibility" => "r", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment8");

        $this->conf->qe("delete from PaperWatch where paperId=? and contactId=?", $paper2->paperId, $this->u_chair->contactId);
        $paper2->load_watch();
        $this->u_chair->set_prop("defaultWatch", Contact::WATCH_REVIEW | Contact::WATCH_REVIEW_MANAGED);
        $this->u_chair->save_prop();
        $comment9 = new CommentInfo($paper2);
        $comment9->save_comment(["text" => "Do notify chair #2", "visibility" => "r", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment9");

        $this->u_chair->set_prop("defaultWatch", Contact::WATCH_REVIEW);
        $this->u_chair->save_prop();
        $comment10 = new CommentInfo($paper2);
        $comment10->save_comment(["text" => "Do not notify chair #2", "visibility" => "r", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment10");

        $revreq = ["s01" => 5, "s02" => 4, "ready" => true];
        save_review(2, $this->u_chair, $revreq);
        MailChecker::check_db("test01-review2D");

        $comment11 = new CommentInfo($paper2);
        $comment11->save_comment(["text" => "Do notify chair #3", "visibility" => "r", "blind" => false], $this->u_mgbaker);
        MailChecker::check_db("test01-comment11");
    }

    function test_viewrev_none() {
        $paper2 = $this->u_chair->checked_paper_by_id(2);
        $u_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $u_pdru = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc
        $u_ext = $this->conf->checked_user_by_email("external@_.com");

        $r2chair = $paper2->review_by_user($this->u_chair);
        $r2jon = $paper2->review_by_user($u_jon);
        $r2pdru = $paper2->review_by_user($u_pdru);
        $r2ext = $paper2->review_by_user($u_ext);

        xassert($this->u_chair->can_view_review($paper2, $r2chair));
        xassert($u_jon->can_view_review($paper2, $r2chair));
        xassert($u_pdru->can_view_review($paper2, $r2chair));
        xassert($this->u_mgbaker->can_view_review($paper2, $r2chair));
        xassert($u_ext->can_view_review($paper2, $r2chair));

        $this->conf->save_refresh_setting("viewrev", -1);
        Contact::update_rights();

        xassert($this->u_chair->can_view_review($paper2, $r2chair));
        xassert(!$u_jon->can_view_review($paper2, $r2chair));
        xassert(!$u_pdru->can_view_review($paper2, $r2chair));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $r2chair));

        xassert($this->u_chair->can_view_review($paper2, $r2jon));
        xassert($u_jon->can_view_review($paper2, $r2jon));
        xassert(!$u_pdru->can_view_review($paper2, $r2jon));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $r2jon));

        xassert($this->u_chair->can_view_review($paper2, $r2pdru));
        xassert(!$u_jon->can_view_review($paper2, $r2pdru));
        xassert($u_pdru->can_view_review($paper2, $r2pdru));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $r2pdru));

        xassert($this->u_chair->can_view_review($paper2, $r2ext));
        xassert(!$u_jon->can_view_review($paper2, $r2ext));
        xassert(!$u_pdru->can_view_review($paper2, $r2ext));
        xassert(!$this->u_mgbaker->can_view_review($paper2, $r2ext));

        $this->conf->save_refresh_setting("viewrev", null);
        Contact::update_rights();
    }

    function test_assign_review_retype() {
        assert_search_papers($this->u_chair, "re:mgbaker", "1 2 13 17");
        assert_search_papers($this->u_chair, "re:sec:mgbaker", "2");
        assert_search_papers($this->u_chair, "sec:any", "2");
        assert_search_papers($this->u_chair, "has:sec", "2");
        assert_search_papers($this->u_chair, "sec:mgbaker", "2");
        assert_search_papers($this->u_chair, "re:pri:mgbaker", "1 13 17");
        $paper2 = $this->conf->checked_paper_by_id(2);
        xassert($paper2->timeSubmitted > 0);
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert_eqq($review2d->reviewType, REVIEW_SECONDARY);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 0);

        // mgbaker secondary -> primary
        $assignset = new AssignmentSet($this->u_chair);
        $assignset->parse("action,paper,email,reviewtype\nreview,all,mgbaker@cs.stanford.edu,secondary:primary\n");
        xassert_eqq($assignset->full_feedback_text(), "");
        xassert($assignset->execute());

        assert_search_papers($this->u_chair, "re:sec:mgbaker", "");
        assert_search_papers($this->u_chair, "re:pri:mgbaker", "1 2 13 17");
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert_eqq($review2d->reviewType, REVIEW_PRIMARY);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 1);

        // mgbaker primary -> secondary
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,2,mgbaker@cs.stanford.edu,primary:secondary\n");

        assert_search_papers($this->u_chair, "re:sec:mgbaker", "2");
        assert_search_papers($this->u_chair, "re:pri:mgbaker", "1 13 17");
        assert_search_papers($this->u_chair, "sec:any", "2");
        assert_search_papers($this->u_chair, "has:sec", "2");
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert_eqq($review2d->reviewType, REVIEW_SECONDARY);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 0);

        // no change to assignment
        $old_pcassignments = $this->get_pcassignment_csv();
        xassert_assign($this->u_chair, $old_pcassignments);
        xassert_eqq($this->get_pcassignment_csv(), $old_pcassignments);
        $review2d = fresh_review($paper2, $this->u_mgbaker);
        xassert_eqq($review2d->reviewType, REVIEW_SECONDARY);
        xassert(!$review2d->reviewSubmitted);
        xassert($review2d->reviewNeedsSubmit == 0);

        // another secondary -> primary
        assert_search_papers($this->u_chair, "sec:any", "2");
        assert_search_papers($this->u_chair, "has:sec", "2");
        assert_search_papers($this->u_chair, "2 AND pri:mgbaker", "");
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,any,any,secondary:primary");
        assert_search_papers($this->u_chair, "sec:any", "");
        assert_search_papers($this->u_chair, "has:sec", "");
        assert_search_papers($this->u_chair, "2 AND pri:mgbaker", "2");
        assert_search_papers($this->u_chair, "pri:mgbaker", "1 2 13 17");

        // `any` does not change type
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any");
        assert_search_papers($this->u_chair, "pri:mgbaker", "1 2 13 17");

        // any -> pcreview
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any:pcreview");
        assert_search_papers($this->u_chair, "pri:mgbaker", "");
        assert_search_papers($this->u_chair, "re:opt:mgbaker", "1 2 13 17");

        // any -> external does nothing
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any:external");
        assert_search_papers($this->u_chair, "re:opt:mgbaker", "1 2 13 17");

        // back to primary
        xassert_assign($this->u_chair, "action,paper,email,reviewtype\nreview,all,mgbaker,any:pri\nreview,2,mgbaker,any:sec");
        assert_search_papers($this->u_chair, "pri:mgbaker", "1 13 17");
        assert_search_papers($this->u_chair, "sec:mgbaker", "2");
        assert_search_papers($this->u_chair, "re:opt:mgbaker", "");
        ConfInvariants::test_all($this->conf);
    }

    function test_assign_review_round() {
        assert_search_papers($this->u_chair, "re:R1", "12 13 17");
        assert_search_papers($this->u_chair, "re:R2", "13 17");
        assert_search_papers($this->u_chair, "re:R3", "12");
        assert_search_papers($this->u_chair, "round:none", "1 2 3 4 5 6 7 8 9 10 11 14 15 16 18");

        xassert_assign($this->u_chair, "action,paper,email,round\nreview,all,all,R1:none\n");
        assert_search_papers($this->u_chair, "re:R1", "");
        assert_search_papers($this->u_chair, "re:R2", "13 17");
        assert_search_papers($this->u_chair, "re:R3", "12");
        assert_search_papers($this->u_chair, "round:none", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18");

        xassert_assign($this->u_chair, "action,paper,email,round\nreview,1-5,all,none:R1");
        assert_search_papers($this->u_chair, "re:R1", "1 2 3 4 5");
        assert_search_papers($this->u_chair, "re:R2", "13 17");
        assert_search_papers($this->u_chair, "re:R3", "12");
        assert_search_papers($this->u_chair, "round:none", "6 7 8 9 10 11 12 13 14 15 16 17 18");
        ConfInvariants::test_all($this->conf);
    }

    function test_assign_external_review() {
        xassert(!$this->conf->fresh_user_by_email("newexternal@_.com"));
        assert_search_papers($this->u_chair, "re:newexternal@_.com", "");
        xassert_assign($this->u_chair, "action,paper,email\nreview,3,newexternal@_.com");
        xassert(!!$this->conf->fresh_user_by_email("newexternal@_.com"));
        assert_search_papers($this->u_chair, "re:newexternal@_.com", "3");

        assert_search_papers($this->u_chair, "re:external@_.com", "2");
        xassert_assign($this->u_chair, "action,paper,email\nreview,3,external@_.com");
        assert_search_papers($this->u_chair, "re:external@_.com", "2 3");
    }

    function test_assign_administrator() {
        assert_search_papers($this->u_chair, "has:admin", "");
        assert_search_papers($this->u_chair, "conflict:me", "");
        assert_search_papers($this->u_chair, "admin:me", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($this->u_marina, "admin:me", "");
        xassert(!$this->u_marina->is_manager());

        xassert_assign($this->u_chair, "action,paper,user\nadministrator,4,marina@poema.ru\n");
        xassert($this->conf->setting("papermanager") > 0);
        assert_search_papers($this->u_chair, "has:admin", "4");
        assert_search_papers($this->u_chair, "admin:me", "1 2 3 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($this->u_chair, "admin:marina", "4");
        assert_search_papers($this->u_marina, "admin:me", "4");
        xassert($this->u_marina->is_manager());
    }

    function test_override_conflicts() {
        xassert_assign($this->conf->root_user(), "action,paper,user,tag\nconflict,4 5,chair@_.com\ntag,4 5,,testtag");
        $paper4 = $this->u_chair->checked_paper_by_id(4);
        $paper5 = $this->u_chair->checked_paper_by_id(5);
        assert(!$this->u_chair->can_administer($paper4));
        assert(!$this->u_chair->allow_administer($paper4));
        assert(!$this->u_chair->can_administer($paper5));
        assert($this->u_chair->allow_administer($paper5));
        xassert_eqq($paper4->viewable_tags($this->u_chair), "");
        xassert_eqq($paper5->viewable_tags($this->u_chair), "");

        $overrides = $this->u_chair->add_overrides(Contact::OVERRIDE_CONFLICT);
        assert(!$this->u_chair->can_administer($paper4));
        assert(!$this->u_chair->allow_administer($paper4));
        assert($this->u_chair->can_administer($paper5));
        assert($this->u_chair->allow_administer($paper5));
        xassert_eqq($paper4->viewable_tags($this->u_chair), "");
        xassert_match($paper5->viewable_tags($this->u_chair), '/\A fart#\d+ testtag#0\z/');
        $this->u_chair->set_overrides($overrides);
        xassert_assign($this->conf->root_user(), "action,paper,user,tag\nclearconflict,4 5,chair@_.com\ncleartag,4 5,,testtag");
    }

    function test_assign_preference() {
        xassert_assign($this->u_chair, "paper,user,pref\n1,marina,10\n");
        xassert_assign($this->u_chair, "paper,user,pref\n1,chair@_.com,10\n");
        xassert_assign($this->u_chair, "paper,user,pref\n4,marina,10\n");
        xassert_assign($this->u_chair, "paper,user,pref\n4,chair@_.com,10\n");

        xassert_eqq($this->u_marina->contactId, $this->conf->checked_paper_by_id(4)->managerContactId);
        xassert_assign($this->u_marina, "paper,user,action\n4,chair@_.com,conflict\n");

        xassert_assign($this->u_chair, "paper,user,pref\n1,marina,11\n");
        xassert_assign($this->u_chair, "paper,user,pref\n1,chair@_.com,11\n");
        xassert_assign_fail($this->u_chair, "paper,user,pref\n4,marina,11\n");
        xassert_assign($this->u_chair, "paper,user,pref\n4,chair@_.com,11\n");

        xassert_assign($this->u_marina, "paper,user,pref\n1,marina,12\n");
        xassert_assign_fail($this->u_marina, "paper,user,pref\n1,chair@_.com,12\n");
        xassert_assign($this->u_marina, "paper,user,pref\n4,marina,12\n");
        xassert_assign($this->u_marina, "paper,user,pref\n4,chair@_.com,12\n");

        xassert_assign($this->u_marina, "paper,user,action\n4,chair@_.com,noconflict\n");

        $paper1 = $this->conf->checked_paper_by_id(1);
        $paper1->load_preferences();
        xassert_eqq($paper1->preference($this->u_marina)->as_list(), [12, null]);
        xassert_assign($this->u_marina, "paper,pref\n1,13\n");
        $paper1->load_preferences();
        xassert_eqq($paper1->preference($this->u_marina)->as_list(), [13, null]);
    }

    function test_assign_clear_administrator() {
        xassert($this->u_marina->is_manager());
        assert_search_papers($this->u_chair, "admin:marina", "4");

        // cannot remove self as administrator
        xassert_assign_fail($this->u_marina, "paper,action\n4,clearadministrator\n");
        xassert($this->u_marina->is_manager());
        assert_search_papers($this->u_chair, "admin:marina", "4");

        xassert_assign($this->u_chair, "paper,action\n4,clearadministrator\n");
        xassert(!$this->u_marina->is_manager());
        assert_search_papers($this->u_chair, "admin:marina", "");
    }

    function test_assign_conflicts() {
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ALL), "mgbaker@cs.stanford.edu nickm@ee.stanford.edu sclin@leland.stanford.edu");
        xassert_eqq(sorted_conflicts($paper3, TESTSC_CONTACTS), "sclin@leland.stanford.edu");
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu sclin@leland.stanford.edu");

        // reopen submissions: author can update conflicts
        $user_sclin = $this->conf->checked_user_by_email("sclin@leland.stanford.edu");
        $this->conf->save_setting("sub_update", Conf::$now + 10);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now + 10);
        xassert($user_sclin->can_edit_paper($paper3));

        xassert_assign($user_sclin, "paper,action,user\n3,conflict,rguerin@ibm.com\n");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu rguerin@ibm.com sclin@leland.stanford.edu");

        // test conflict types
        $user_rguerin = $this->conf->checked_user_by_email("rguerin@ibm.com");
        xassert_eqq($paper3->conflict_type($user_rguerin), Conflict::GENERAL);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,pinned\n");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq($paper3->conflict_type($user_rguerin), Conflict::GENERAL);
        xassert_assign($this->u_chair, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,pinned\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), Conflict::set_pinned(Conflict::GENERAL, true));
        xassert_assign($user_sclin, "paper,action,user,conflict type\n3,conflict,rguerin@ibm.com,pinned\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), Conflict::set_pinned(Conflict::GENERAL, true));
        xassert_assign($this->u_chair, "paper,action,user,conflicttype\n3,conflict,rguerin@ibm.com,none\n");
        xassert_assign($user_sclin, "paper,action,user,conflicttype\n3,conflict,rguerin@ibm.com,conflict\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), Conflict::GENERAL);

        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,collaborator\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 2);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,advisor\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 4);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,advisee\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 4);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin,collaborator:none\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 4);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,any,advisee:collaborator\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 2);
        xassert_assign($this->u_chair, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,pin unconflicted\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 1);
        xassert(!$paper3->has_conflict($user_rguerin));
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,advisee\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 1);
        xassert_assign($this->u_chair, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,unpin\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 0);
        xassert_assign($user_sclin, "paper,action,user,conflict\n3,conflict,rguerin@ibm.com,advisee\n");
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_rguerin), 4);

        $this->conf->save_setting("sub_update", Conf::$now - 5);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now - 5);
        xassert_assign_fail($user_sclin, "paper,action,user\n3,clearconflict,rguerin@ibm.com\n");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu rguerin@ibm.com sclin@leland.stanford.edu");

        xassert(!$paper3->has_author($user_rguerin));
        xassert($paper3->has_conflict($user_rguerin));
        xassert_assign($user_sclin, "paper,action,user\n3,contact,rguerin@ibm.com");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert($paper3->has_author($user_rguerin));
        xassert($paper3->has_conflict($user_rguerin));
        xassert_assign($user_sclin, "paper,action,user\n3,clearcontact,rguerin@ibm.com");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert(!$paper3->has_author($user_rguerin));
        xassert($paper3->has_conflict($user_rguerin));
        xassert_assign($user_sclin, "paper,action,user\n3,contact,rguerin@ibm.com");
        xassert_assign($this->u_chair, "paper,action,user\n3,clearconflict,rguerin@ibm.com");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert($paper3->has_author($user_rguerin));
        xassert($paper3->has_conflict($user_rguerin));
        xassert_assign($user_sclin, "paper,action,user\n3,clearcontact,rguerin@ibm.com");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert(!$paper3->has_author($user_rguerin));
        xassert(!$paper3->has_conflict($user_rguerin));

        xassert_assign($this->u_chair, "paper,action,user\n3,clearconflict,rguerin@ibm.com\n3,clearconflict,sclin@leland.stanford.edu\n3,clearcontact,mgbaker@cs.stanford.edu\n");
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_CONTACTS), "sclin@leland.stanford.edu");
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu sclin@leland.stanford.edu");

        xassert_assign_fail($this->u_chair, "paper,action,user\n3,clearcontact,sclin@leland.stanford.edu\n");
        xassert_assign($this->u_chair, "paper,action,user\n3,clearcontact,sclin@leland.stanford.edu\n3,contact,mgbaker@cs.stanford.edu\n");
        // though no longer a contact, sclin is still a listed author, so
        // has a conflict that way
        $paper3 = $this->u_chair->checked_paper_by_id(3);
        xassert_eqq($paper3->conflict_type($user_sclin), CONFLICT_AUTHOR);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_CONTACTS), "mgbaker@cs.stanford.edu sclin@leland.stanford.edu");
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu sclin@leland.stanford.edu");

        // change author list => remove conflict
        $ps = new PaperStatus($this->conf->root_user());
        xassert($ps->save_paper_json(json_decode('{"id":3,"authors":[{"name":"Nick McKeown", "email": "nickm@ee.stanford.edu", "affiliation": "Stanford University"}]}')));
        $paper3->invalidate_conflicts();
        xassert_eqq($paper3->conflict_type($user_sclin), 0);
        xassert_eqq(sorted_conflicts($paper3, TESTSC_CONTACTS), "mgbaker@cs.stanford.edu");
        xassert_eqq(sorted_conflicts($paper3, TESTSC_ENABLED), "mgbaker@cs.stanford.edu");
    }

    function test_tracker_permissionizer() {
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red

        $this->conf->save_refresh_setting("tracks", 1, '{"green":{"admin":"+red"}}');
        self::run_assignment($this->u_chair, "paper,tag\nall,-green\n3 9 13 17,green\n", true);
        SiteLoader::autoload("MeetingTracker");

        $permissionizer = new MeetingTracker_Permissionizer($this->conf, [1, 2, 3]);
        xassert_eqq($permissionizer->admin_perm(), []);
        xassert($permissionizer->check_admin_perm($this->conf->root_user()));
        xassert(!$permissionizer->check_admin_perm($user_jon));

        $permissionizer = new MeetingTracker_Permissionizer($this->conf, [3, 9, 13]);
        xassert_eqq($permissionizer->admin_perm(), [["+red"]]);
        xassert($permissionizer->check_admin_perm($this->conf->root_user()));
        xassert($permissionizer->check_admin_perm($user_jon));

        xassert_eqq($permissionizer->default_visibility(), "");
        $this->conf->save_refresh_setting("tracks", 1, '{"green":{"admin":"+red","view":"-blue"}}');
        $permissionizer = new MeetingTracker_Permissionizer($this->conf, [3, 9, 13]);
        xassert_eqq($permissionizer->default_visibility(), "-blue");

        $this->conf->save_refresh_setting("tracks", null);
    }

    function test_tracks() {
        $user_jon = $this->conf->checked_user_by_email("jon@cs.ucl.ac.uk"); // pc, red
        $user_randy = $this->conf->checked_user_by_email("randy@cs.berkeley.edu"); // author

        // tracks and view-paper permissions
        self::run_assignment($this->u_chair, "paper,tag\nall,-green\n3 9 13 17,green\n", true);
        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"view\":\"-red\"},\"_\":{\"view\":\"+red\"}}");
        $this->conf->save_refresh_setting("viewrev", 1);
        $this->conf->save_refresh_setting("viewrevid", 1);
        xassert($user_jon->has_tag("red"));
        xassert(!$this->u_marina->has_tag("red"));

        $paper13 = $user_jon->checked_paper_by_id(13);
        xassert($paper13->has_tag("green"));
        xassert(!$paper13->has_author($user_jon));
        xassert(!$paper13->has_reviewer($user_jon));
        xassert(!$paper13->has_author($this->u_marina));
        xassert(!$paper13->has_reviewer($this->u_marina));

        xassert(!$user_jon->can_view_paper($paper13));
        xassert(!$user_jon->can_view_pdf($paper13));
        xassert(!$user_jon->can_view_review($paper13, null));
        xassert(!$user_jon->can_view_review_identity($paper13, null));
        xassert($user_jon->can_accept_review_assignment_ignore_conflict($paper13));
        xassert($user_jon->can_accept_review_assignment($paper13));
        xassert(!$user_jon->can_edit_some_review($paper13));
        xassert($this->u_marina->can_view_paper($paper13));
        xassert($this->u_marina->can_view_pdf($paper13));
        xassert($this->u_marina->can_view_review($paper13, null));
        xassert($this->u_marina->can_view_review_identity($paper13, null));
        xassert($this->u_marina->can_accept_review_assignment_ignore_conflict($paper13));
        xassert($this->u_marina->can_accept_review_assignment($paper13));
        xassert($this->u_marina->can_edit_some_review($paper13));

        xassert($user_jon->can_view_some_review_identity());
        xassert($this->u_marina->can_view_some_review_identity());
        xassert(!$user_randy->can_view_some_review_identity());
        xassert(!$this->u_nobody->can_view_some_review_identity());
        $this->conf->save_refresh_setting("rev_blind", 0);
        Contact::update_rights();
        xassert($user_jon->can_view_some_review_identity());
        xassert($this->u_marina->can_view_some_review_identity());
        // `rev_blind` no longer affects reviewers.
        xassert(!$user_randy->can_view_some_review_identity());
        xassert(!$this->u_nobody->can_view_some_review_identity());
        $this->conf->save_refresh_setting("rev_blind", null);
        Contact::update_rights();
        xassert($user_jon->can_view_some_review_identity());
        xassert($this->u_marina->can_view_some_review_identity());
        xassert(!$user_randy->can_view_some_review_identity());
        xassert(!$this->u_nobody->can_view_some_review_identity());

        $paper14 = $user_jon->checked_paper_by_id(14);
        xassert(!$paper14->has_tag("green"));
        xassert(!$paper14->has_author($user_jon));
        xassert(!$paper14->has_reviewer($user_jon));
        xassert(!$paper14->has_author($this->u_marina));
        xassert(!$paper14->has_reviewer($this->u_marina));

        xassert($user_jon->can_view_paper($paper14));
        xassert($user_jon->can_view_pdf($paper14));
        xassert($user_jon->can_view_review($paper14, null));
        xassert($user_jon->can_view_review_identity($paper14, null));
        xassert($user_jon->can_accept_review_assignment_ignore_conflict($paper14));
        xassert($user_jon->can_accept_review_assignment($paper14));
        xassert($user_jon->can_edit_some_review($paper14));
        xassert(!$this->u_marina->can_view_paper($paper14));
        xassert(!$this->u_marina->can_view_pdf($paper14));
        xassert(!$this->u_marina->can_view_review($paper14, null));
        xassert(!$this->u_marina->can_view_review_identity($paper14, null));
        xassert($this->u_marina->can_accept_review_assignment_ignore_conflict($paper14));
        xassert($this->u_marina->can_accept_review_assignment($paper14));
        xassert(!$this->u_marina->can_edit_some_review($paper14));

        xassert_assign($this->u_chair, "paper,action,user\n13,primary,jon@cs.ucl.ac.uk\n");
        xassert_assign($this->u_chair, "paper,action,user\n14,primary,jon@cs.ucl.ac.uk\n");
        xassert_assign($this->u_chair, "paper,action,user\n13,primary,marina@poema.ru\n");
        xassert_assign($this->u_chair, "paper,action,user\n14,primary,marina@poema.ru\n");
        xassert_assign($this->u_chair, "paper,action,user\n13-14,clearreview,jon@cs.ucl.ac.uk\n13-14,clearreview,marina@poema.ru\n");

        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"view\":\"-red\",\"assrev\":\"-red\"},\"_\":{\"view\":\"+red\",\"assrev\":\"+red\"}}");

        xassert(!$user_jon->can_view_paper($paper13));
        xassert(!$user_jon->can_view_pdf($paper13));
        xassert(!$user_jon->can_view_review($paper13, null));
        xassert(!$user_jon->can_view_review_identity($paper13, null));
        xassert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper13));
        xassert(!$user_jon->can_accept_review_assignment($paper13));
        xassert(!$user_jon->can_edit_some_review($paper13));
        xassert($this->u_marina->can_view_paper($paper13));
        xassert($this->u_marina->can_view_pdf($paper13));
        xassert($this->u_marina->can_view_review($paper13, null));
        xassert($this->u_marina->can_view_review_identity($paper13, null));
        xassert($this->u_marina->can_accept_review_assignment_ignore_conflict($paper13));
        xassert($this->u_marina->can_accept_review_assignment($paper13));
        xassert($this->u_marina->can_edit_some_review($paper13));

        xassert($user_jon->can_view_paper($paper14));
        xassert($user_jon->can_view_pdf($paper14));
        xassert($user_jon->can_view_review($paper14, null));
        xassert($user_jon->can_view_review_identity($paper14, null));
        xassert($user_jon->can_accept_review_assignment_ignore_conflict($paper14));
        xassert($user_jon->can_accept_review_assignment($paper14));
        xassert($user_jon->can_edit_some_review($paper14));
        xassert(!$this->u_marina->can_view_paper($paper14));
        xassert(!$this->u_marina->can_view_pdf($paper14));
        xassert(!$this->u_marina->can_view_review($paper14, null));
        xassert(!$this->u_marina->can_view_review_identity($paper14, null));
        xassert(!$this->u_marina->can_accept_review_assignment_ignore_conflict($paper14));
        xassert(!$this->u_marina->can_accept_review_assignment($paper14));
        xassert(!$this->u_marina->can_edit_some_review($paper14));

        xassert_assign_fail($this->u_chair, "paper,action,user\n13,primary,jon@cs.ucl.ac.uk\n");
        xassert_assign($this->u_chair, "paper,action,user\n14,primary,jon@cs.ucl.ac.uk\n");
        xassert_assign($this->u_chair, "paper,action,user\n13,primary,marina@poema.ru\n");
        xassert_assign_fail($this->u_chair, "paper,action,user\n14,primary,marina@poema.ru\n");
        xassert_assign($this->u_chair, "paper,action,user\n13-14,clearreview,jon@cs.ucl.ac.uk\n13-14,clearreview,marina@poema.ru\n");

        // combinations of tracks
        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"view\":\"-red\",\"assrev\":\"-red\"},\"red\":{\"view\":\"+red\"},\"blue\":{\"view\":\"+blue\"},\"_\":{\"view\":\"+red\",\"assrev\":\"+red\"}}");

        // 1: none; 2: red; 3: green; 4: red green; 5: blue;
        // 6: red blue; 7: green blue; 8: red green blue
        xassert_assign($this->u_chair, "paper,tag\nall,-green\nall,-red\nall,-blue\n2 4 6 8,+red\n3 4 7 8,+green\n5 6 7 8,+blue\n");
        xassert_assign($this->u_chair, "paper,action,user\nall,clearadministrator\nall,clearlead\n");
        assert_search_papers($this->u_chair, "#red", "2 4 6 8");
        assert_search_papers($this->u_chair, "#green", "3 4 7 8");
        assert_search_papers($this->u_chair, "#blue", "5 6 7 8");

        $user_floyd = $this->conf->checked_user_by_email("floyd@ee.lbl.gov");
        $user_pfrancis = $this->conf->checked_user_by_email("pfrancis@ntt.jp");
        xassert(!$this->u_marina->has_tag("red") && !$this->u_marina->has_tag("blue"));
        xassert($this->u_estrin->has_tag("red") && !$this->u_estrin->has_tag("blue"));
        xassert(!$user_pfrancis->has_tag("red") && $user_pfrancis->has_tag("blue"));
        xassert($user_floyd->has_tag("red") && $user_floyd->has_tag("blue"));

        for ($pid = 1; $pid <= 8; ++$pid) {
            $paper = $this->u_chair->checked_paper_by_id($pid);
            foreach ([$this->u_marina, $this->u_estrin, $user_pfrancis, $user_floyd] as $cidx => $user) {
                if ((!($cidx & 1) && (($pid - 1) & 2)) /* user not red && paper green */
                    || (($cidx & 1) && ($pid == 1 || (($pid - 1) & 1))) /* user red && paper red or none */
                    || (($cidx & 2) && (($pid - 1) & 4))) /* user blue && paper blue */
                    xassert($user->can_view_paper($paper), "user {$user->email} can view paper $pid");
                else
                    xassert(!$user->can_view_paper($paper), "user {$user->email} can't view paper $pid");
            }
        }

        // primary administrators
        $this->conf->save_refresh_setting("tracks", null);
        for ($pid = 1; $pid <= 3; ++$pid) {
            $p = $this->u_chair->checked_paper_by_id($pid);
            xassert($this->u_chair->allow_administer($p));
            xassert(!$this->u_marina->allow_administer($p));
            xassert($this->u_chair->can_administer($p));
            xassert($this->u_chair->is_primary_administrator($p));
        }
        xassert_assign($this->u_chair, "paper,action,user\n2,administrator,marina@poema.ru");
        for ($pid = 1; $pid <= 3; ++$pid) {
            $p = $this->u_chair->checked_paper_by_id($pid);
            xassert($this->u_chair->allow_administer($p));
            xassert_eqq($this->u_marina->allow_administer($p), $pid === 2);
            xassert($this->u_chair->can_administer($p));
            xassert_eqq($this->u_marina->can_administer($p), $pid === 2);
            xassert_eqq($this->u_chair->is_primary_administrator($p), $pid !== 2);
            xassert_eqq($this->u_marina->is_primary_administrator($p), $pid === 2);
        }
        $this->conf->save_refresh_setting("tracks", 1, "{\"green\":{\"admin\":\"+red\"}}");
        for ($pid = 1; $pid <= 3; ++$pid) {
            $p = $this->u_chair->checked_paper_by_id($pid);
            xassert($this->u_chair->allow_administer($p));
            xassert_eqq($this->u_marina->allow_administer($p), $pid === 2);
            xassert_eqq($this->u_estrin->allow_administer($p), $pid === 3);
            xassert($this->u_chair->can_administer($p));
            xassert_eqq($this->u_marina->can_administer($p), $pid === 2);
            xassert_eqq($this->u_estrin->can_administer($p), $pid === 3);
            xassert_eqq($this->u_chair->is_primary_administrator($p), $pid === 1);
            xassert_eqq($this->u_marina->is_primary_administrator($p), $pid === 2);
            xassert_eqq($this->u_estrin->is_primary_administrator($p), $pid === 3);
        }
        $this->conf->save_refresh_setting("tracks", null);

        // check content upload
        $paper30 = $this->u_chair->checked_paper_by_id(30);
        $old_hash = $paper30->document(DTYPE_SUBMISSION)->text_hash();
        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json(json_decode('{"id":30,"submission":{"content_file":"/etc/passwd","mimetype":"application/pdf"}}'));
        xassert($ps->has_error_at("submission"));
        $paper30 = $this->u_chair->checked_paper_by_id(30);
        xassert_eqq($paper30->document(DTYPE_SUBMISSION)->text_hash(), $old_hash);
        $ps->save_paper_json(json_decode('{"id":30,"submission":{"content_file":"./../../../../etc/passwd","mimetype":"application/pdf"}}'));
        xassert($ps->has_error_at("submission"));
        $paper30 = $this->u_chair->checked_paper_by_id(30);
        xassert_eqq($paper30->document(DTYPE_SUBMISSION)->text_hash(), $old_hash);

        // check accept invariant
        assert_search_papers($this->u_chair, "dec:yes", "");
        xassert(!$this->conf->setting("paperacc"));
        xassert_assign($this->u_chair, "paper,decision\n1,accept\n");
        assert_search_papers($this->u_chair, "dec:yes", "1");
        xassert($this->conf->setting("paperacc"));

        // check reviewAuthorSeen
        $user_author2 = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $user_pdruschel = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc
        $paper2 = $this->conf->checked_paper_by_id(2);
        $review2b = fresh_review($paper2, $user_pdruschel);
        xassert(!$user_author2->can_view_review($paper2, $review2b));
        xassert(!$review2b->reviewAuthorSeen);
        $this->conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_YES);
        xassert($user_author2->can_view_review($paper2, $review2b));

        $rjson = $this->conf->review_form()->unparse_review_json($this->u_chair, $paper2, $review2b);
        ReviewForm::update_review_author_seen();
        $review2b = fresh_review($paper2, $user_pdruschel);
        xassert(!$review2b->reviewAuthorSeen);

        $rjson = $this->conf->review_form()->unparse_review_json($user_author2, $paper2, $review2b);
        ReviewForm::update_review_author_seen();
        $review2b = fresh_review($paper2, $user_pdruschel);
        xassert(!!$review2b->reviewAuthorSeen);

        // check review visibility
        $this->conf->save_refresh_setting("au_seerev", null);
        xassert_eqq($this->conf->_au_seerev, null);
        xassert(!$user_author2->can_view_review($paper2, $review2b));
        xassert($paper2->has_tag("fart"));
        xassert(!$paper2->has_tag("faart"));
        $this->conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_SEARCH);
        $this->conf->save_refresh_setting("tag_au_seerev", 1, "fart");
        xassert($user_author2->can_view_review($paper2, $review2b));
        $this->conf->save_refresh_setting("tag_au_seerev", 1, "faart");
        xassert(!$user_author2->can_view_review($paper2, $review2b));
        $this->conf->save_refresh_setting("resp_active", 1);
        $this->conf->save_refresh_setting("responses", 1, '[{"open":1,"done":' . (Conf::$now + 100) . '}]');
        xassert($user_author2->can_view_review($paper2, $review2b));
        $this->conf->save_refresh_setting("au_seerev", null);
        xassert_eqq($this->conf->_au_seerev, null);
        xassert($user_author2->can_view_review($paper2, $review2b));
        $this->conf->save_refresh_setting("resp_active", null);
        xassert(!$user_author2->can_view_review($paper2, $review2b));

        // more tags
        $this->conf->save_refresh_setting("tag_vote", 1, "vote#10 crap#3");
        $this->conf->save_refresh_setting("tag_approval", 1, "app#0");
        $this->conf->update_automatic_tags();
        xassert_assign($this->u_chair,
            "paper,tag\n16,+huitema~vote#5 +crowcroft~vote#1 +crowcroft~crap#2 +estrin~app +estrin~crap#1 +estrin~bar");
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->tag_value("{$this->u_estrin->contactId}~crap"), 1.0);
        xassert_eqq($paper16->tag_value("{$this->u_estrin->contactId}~app"), 0.0);
        xassert_eqq($paper16->tag_value("vote"), 6.0);
        xassert_eqq($paper16->tag_value("crap"), 3.0);
        xassert_eqq($paper16->tag_value("app"), 1.0);
        xassert_eqq($paper16->sorted_viewable_tags($this->u_chair), " app#1 crap#3 vote#6");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_chair), " 2~vote#5 4~app#0 4~bar#0 4~crap#1 8~crap#2 8~vote#1 app#1 crap#3 vote#6");
        xassert(!$this->u_marina->allow_administer($paper16));
        xassert_eqq($paper16->sorted_viewable_tags($this->u_marina), " app#1 crap#3 vote#6");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_marina), " 2~vote#5 4~app#0 4~crap#1 8~crap#2 8~vote#1 app#1 crap#3 vote#6");
        xassert(SettingValues::make_request($this->u_chair, [
            "has_tag_vote_approval" => 1, "tag_vote_approval" => ""
        ])->execute());
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->sorted_searchable_tags($this->u_chair), " 2~vote#5 4~app#0 4~bar#0 4~crap#1 8~crap#2 8~vote#1 crap#3 vote#6");
        xassert_eqq($paper16->sorted_viewable_tags($this->u_marina), " crap#3 vote#6");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_marina), " 2~vote#5 4~crap#1 8~crap#2 8~vote#1 crap#3 vote#6");
        xassert_assign($this->u_chair, "paper,tag\n16,+floyd~app#0");
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->sorted_searchable_tags($this->u_chair), " 2~vote#5 4~app#0 4~bar#0 4~crap#1 8~crap#2 8~vote#1 17~app#0 crap#3 vote#6");

        xassert(SettingValues::make_request($this->u_chair, [
            "has_tag_vote_approval" => 1, "tag_vote_approval" => "app"
        ])->execute());
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->sorted_viewable_tags($this->u_marina), " app#2 crap#3 vote#6");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_chair), " 2~vote#5 4~app#0 4~bar#0 4~crap#1 8~crap#2 8~vote#1 17~app#0 app#2 crap#3 vote#6");

        $this->conf->invalidate_caches(["pc" => true]);
        xassert(SettingValues::make_request($this->u_chair, [
            "has_tag_vote_approval" => 1, "tag_vote_approval" => "app app2"
        ])->execute());
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->sorted_viewable_tags($this->u_marina), " app#2 crap#3 vote#6");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_chair), " 2~vote#5 4~app#0 4~bar#0 4~crap#1 8~crap#2 8~vote#1 17~app#0 app#2 crap#3 vote#6");

        $this->conf->save_refresh_setting("tag_approval", null);
        xassert_assign($this->u_chair, "paper,action,notify\n16,withdraw,no\n");
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16->sorted_searchable_tags($this->u_marina), " app#2");
        xassert_eqq($paper16->sorted_searchable_tags($this->u_estrin), " 4~app#0 4~bar#0 app#2");
        xassert_assign($this->u_chair, "paper,action\n16,revive\n");
        MailChecker::check0();

        ConfInvariants::test_all($this->conf);

        // assignment synonyms
        $user_varghese = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // pc
        xassert_eqq($paper16->preference($user_varghese)->as_list(), [0, null]);
        xassert_assign($user_varghese, "ID,Title,Preference\n16,Potential Benefits of Delta Encoding and Data Compression for HTTP,1X\n");
        $paper16->load_preferences();
        xassert_eqq($paper16->preference($user_varghese)->as_list(), [1, 1]);

        xassert_eq($paper16->leadContactId, 0);
        xassert_assign($this->u_chair, "paperID,lead\n16,varghese\n", true);
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert_eq($paper16->leadContactId, $user_varghese->contactId);
    }

    function test_review_visibility_settings() {
        xassert_eqq($this->conf->_au_seerev, null);
        $paper10 = $this->conf->checked_paper_by_id(10);
        $paper2 = $this->conf->checked_paper_by_id(2);
        xassert(!$paper10->has_tag("fart"));
        xassert(!$paper10->has_tag("faart"));
        xassert($paper2->has_tag("fart"));
        xassert(!$paper2->has_tag("faart"));
        xassert(!$paper10->can_author_view_submitted_review());
        xassert(!$paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author" => Conf::AUSEEREV_SEARCH,
            "review_visibility_author_tags" => "fart"
        ])->execute());
        xassert(!$paper10->can_author_view_submitted_review());
        xassert($paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author_condition" => "#fart"
        ])->execute());
        xassert(!$paper10->can_author_view_submitted_review());
        xassert($paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author" => Conf::AUSEEREV_YES,
            "review_visibility_author_condition" => "#fart"
        ])->execute());
        xassert($paper10->can_author_view_submitted_review());
        xassert($paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author_condition" => "#fart OR 10"
        ])->execute());
        xassert($paper10->can_author_view_submitted_review());
        xassert($paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author" => Conf::AUSEEREV_NO,
            "review_visibility_author_condition" => "#fart"
        ])->execute());
        xassert(!$paper10->can_author_view_submitted_review());
        xassert(!$paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author" => Conf::AUSEEREV_SEARCH,
            "review_visibility_author_condition" => "#faart"
        ])->execute());
        xassert(!$paper10->can_author_view_submitted_review());
        xassert(!$paper2->can_author_view_submitted_review());

        xassert(SettingValues::make_request($this->u_chair, [
            "review_visibility_author" => Conf::AUSEEREV_NO,
            "review_visibility_author_tags" => ""
        ])->execute());
    }

    function test_author_view_capability_users() {
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        $paper19 = $this->u_chair->checked_paper_by_id(19);
        $blank1 = Contact::make($this->conf);
        $blank1->set_capability("@av19", true);
        $blank2 = Contact::make($this->conf);
        $blank2->set_capability("@av16", true);
        xassert($blank1->can_view_paper($paper19));
        xassert(!$blank1->can_view_paper($paper16));
        xassert(!$blank2->can_view_paper($paper19));
        xassert($blank2->can_view_paper($paper16));
        $blank2->set_capability("@av16", null);
        xassert($blank1->can_view_paper($paper19));
        xassert(!$blank1->can_view_paper($paper16));
        xassert(!$blank2->can_view_paper($paper19));
        xassert(!$blank2->can_view_paper($paper16));

        $pset = $blank1->paper_set(["author" => true]);
        xassert_array_eqq($pset->paper_ids(), [19]);
        $pset = $this->u_mogul->paper_set(["author" => true]);
        xassert_array_eqq($pset->paper_ids(), [16]);
        $this->u_mogul->set_capability("@av12", true);
        $pset = $this->u_mogul->paper_set(["author" => true]);
        xassert_array_eqq($pset->paper_ids(), [12, 16]);
    }

    function test_make_anonymous_user_nologin() {
        xassert(!maybe_user("anonymous10"));
        $u = Contact::make_keyed($this->conf, [
            "email" => "anonymous10",
            "disablement" => Contact::CF_UDISABLED
        ])->store(Contact::SAVE_ANY_EMAIL);
        xassert($u->contactId > 0);
        xassert_eqq($this->conf->fetch_value("select password from ContactInfo where email='anonymous10'"), " nologin");
    }

    function test_user_registration() {
        xassert(!maybe_user("sclinx@leland.stanford.edu"));
        $u = Contact::make_keyed($this->conf, ["email" => "sclinx@leland.stanford.edu", "name" => "Stephen Lon", "affiliation" => "Fart World"])->store();
        xassert(!!$u);
        xassert($u->contactId > 0);
        xassert_eqq($u->email, "sclinx@leland.stanford.edu");
        xassert_eqq($u->firstName, "Stephen");
        xassert_eqq($u->lastName, "Lon");
        xassert_eqq($u->affiliation, "Fart World");

        xassert(!maybe_user("scliny@leland.stanford.edu"));
        $u = Contact::make_keyed($this->conf, ["email" => "scliny@leland.stanford.edu", "affiliation" => "Fart World"])->store();
        xassert(!!$u);
        xassert($u->contactId > 0);
        xassert_eqq($u->email, "scliny@leland.stanford.edu");
        xassert_eqq($u->firstName, "");
        xassert_eqq($u->lastName, "");
        xassert_eqq($u->affiliation, "Fart World");

        // registering email of an author grants author privilege
        $u = maybe_user("thalerd@eecs.umich.edu");
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $u = Contact::make_email($this->conf, "thalerd@eecs.umich.edu")->store();
        assert($u !== null);
        xassert($u->contactId > 0);
        xassert_eqq($u->email, "thalerd@eecs.umich.edu");
        xassert_eqq($u->firstName, "David");
        xassert_eqq($u->lastName, "Thaler");
        xassert_eqq($u->affiliation, "University of Michigan");
        xassert_eqq($u->disabled_flags(), 0);
        xassert($this->conf->checked_paper_by_id(27)->has_author($u));

        // registration-time name overrides author name
        $u = maybe_user("schwartz@ctr.columbia.edu");
        xassert(!!$u);
        xassert_eqq($u->disabled_flags(), Contact::CF_PLACEHOLDER);
        $u = Contact::make_keyed($this->conf, ["email" => "schwartz@ctr.columbia.edu", "first" => "cengiz!", "last" => "SCHwarTZ", "affiliation" => "Coyumbia"])->store();
        assert($u !== null);
        xassert($u->contactId > 0);
        xassert_eqq($u->email, "schwartz@ctr.columbia.edu");
        xassert_eqq($u->firstName, "cengiz!");
        xassert_eqq($u->lastName, "SCHwarTZ");
        xassert_eqq($u->affiliation, "Coyumbia");
        xassert_eqq($u->disabled_flags(), 0);
        xassert($this->conf->checked_paper_by_id(26)->has_author($u));
    }

    function test_can_view_user_tags() {
        xassert($this->u_chair->can_view_user_tags());
        xassert($this->u_estrin->can_view_user_tags());
        xassert(!$this->u_kohler->can_view_user_tags());
        xassert(!$this->u_van->can_view_user_tags());
        xassert(!$this->u_nobody->can_view_user_tags());
    }

    function test_sub_blind() {
        $user_diot = $this->conf->checked_user_by_email("christophe.diot@sophia.inria.fr");
        $user_pdruschel = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu");

        xassert_assign($this->u_chair, "paper,lead\n17,pdruschel\n");
        $paper17 = $this->u_mgbaker->checked_paper_by_id(17);
        xassert_eqq($paper17->review_type($this->u_mgbaker), REVIEW_PRIMARY);
        xassert_eqq($paper17->review_type($user_diot), 0);

        xassert(!$this->u_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));

        $this->conf->save_setting("sub_blind", Conf::BLIND_NEVER);
        Contact::update_rights();
        xassert($this->u_mgbaker->can_view_authors($paper17));
        xassert($user_diot->can_view_authors($paper17));
        xassert($user_pdruschel->can_view_authors($paper17));

        $this->conf->save_setting("sub_blind", Conf::BLIND_OPTIONAL);
        Contact::update_rights();
        xassert(!$this->u_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));

        $this->conf->save_setting("sub_blind", Conf::BLIND_UNTILREVIEW);
        Contact::update_rights();
        xassert(!$this->u_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));

        $this->conf->save_refresh_setting("sub_blind", null);
        xassert_eqq($this->conf->setting("sub_blind"), Conf::BLIND_ALWAYS);
    }

    function test_search_authors() {
        // simple search
        $j = search_json($this->u_shenker, "au:berkeley", "id title");
        xassert_eqq(join(";", array_keys($j)), "1;6;13;15;24");

        // "and"
        assert_search_papers($this->u_shenker, "au:berkeley fountain", "24");
        assert_search_papers($this->u_shenker, "au:berkeley (fountain)", "24");
        assert_search_papers($this->u_shenker, "au:berkeley (fountain", "24");
        assert_search_papers($this->u_shenker, "au:berkeley fountain)", "24");
        assert_search_papers($this->u_shenker, "fountain au:berkeley", "24");
        assert_search_papers($this->u_shenker, "(fountain) au:berkeley", "24");
        assert_search_papers($this->u_shenker, "(fountain au:berkeley", "24");
        assert_search_papers($this->u_shenker, "fountain) au:berkeley", "24");

        // more complex author searches
        assert_search_papers($this->u_shenker, "au:estrin@usc.edu", "1");
        assert_search_papers($this->u_shenker, "au:usc.edu", "1");
        assert_search_papers($this->u_shenker, "au:stanford.edu", "3 18 19");
        assert_search_papers($this->u_shenker, "au:*@*.stanford.edu", "3 18 19");
        assert_search_papers($this->u_shenker, "au:n*@*u", "3 10");
    }

    function test_search_sort() {
        assert_search_papers($this->u_shenker, "au:berkeley sort:title", "24 15 13 1 6");
        assert_search_papers($this->u_shenker, "au:berkeley sort:[title]", "24 15 13 1 6");
        assert_search_papers($this->u_shenker, "au:berkeley sort:title[down forward]", "24 15 13 1 6");
        assert_search_papers($this->u_shenker, "au:berkeley sort:title[down,forward]", "24 15 13 1 6");
        assert_search_papers($this->u_shenker, "au:berkeley sort:-title", "6 1 13 15 24");
        assert_search_papers($this->u_shenker, "au:berkeley sort:title[reverse]", "6 1 13 15 24");
        assert_search_papers($this->u_shenker, "au:berkeley sort:[title reverse]", "6 1 13 15 24");
        assert_search_papers($this->u_shenker, "au:berkeley sort:[title down]", "6 1 13 15 24");
        assert_search_papers($this->u_shenker, "au:berkeley sort:[title down forward]", "24 15 13 1 6");
        assert_search_papers($this->u_shenker, "au:berkeley sort:[-title]", "6 1 13 15 24");
    }

    function test_search_shepherd() {
        $paper11 = $this->u_chair->checked_paper_by_id(11);
        $paper12 = $this->u_chair->checked_paper_by_id(12);
        $j = call_api("=shepherd", $this->u_chair, ["shepherd" => $this->u_estrin->email], $paper11);
        xassert_eqq($j->ok, true);
        $j = call_api("=shepherd", $this->u_chair, ["shepherd" => $this->u_estrin->email], $paper12);
        xassert_eqq($j->ok, true);
        assert_search_papers($this->u_chair, "shep:any", "11 12");
        assert_search_papers($this->u_chair, "shep:estrin", "11 12");
        assert_search_papers($this->u_shenker, "shep:any", "11 12");
        assert_search_papers($this->u_shenker, "has:shepherd", "11 12");
    }

    function test_search_numeric_order() {
        assert_search_papers($this->u_chair, "1-5 15-18", "1 2 3 4 5 15 16 17 18");
        assert_search_papers($this->u_chair, "#1-5 15-#18", "1 2 3 4 5 15 16 17 18");
        assert_search_papers($this->u_chair, "#1-#5 #15-18", "1 2 3 4 5 15 16 17 18");
        assert_search_papers($this->u_chair, "5–1 15—18", "5 4 3 2 1 15 16 17 18");
        assert_search_papers($this->u_chair, "5–1,#15—17,#20", "5 4 3 2 1 15 16 17 20");
        assert_search_papers($this->u_chair, "13 10 8 9 12", "13 10 8 9 12");
    }

    function test_search_then() {
        assert_search_papers($this->u_chair, "10-12 THEN re:huitema", "10 11 12 8 13");
        assert_search_papers($this->u_chair, "10-12 HIGHLIGHT re:huitema", "10 11 12");
        assert_search_papers($this->u_chair, "10-12 THEN re:huitema THEN 5-6", "10 11 12 8 13 5 6");
        assert_search_papers($this->u_chair, "(10-12 THEN re:huitema) THEN 5-6", "10 11 12 8 13 5 6");
    }

    function test_search_then_sort() {
        assert_search_papers($this->u_chair, "10-12 THEN 3-1", "10 11 12 3 2 1");
        assert_search_papers($this->u_chair, "10-12 THEN 3-1 sort:title", "10 11 12 3 1 2");
        assert_search_papers($this->u_chair, "(10-12 THEN 3-1) sort:title", "10 12 11 3 2 1");
    }

    function test_search_submission_field() {
        assert_search_papers($this->u_chair, "has:calories", "1 2 3 4 5");
        assert_search_papers($this->u_chair, "-has:calories", "6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($this->u_chair, "calories:any", "1 2 3 4 5");
        assert_search_papers($this->u_chair, "calories:none", "6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
        assert_search_papers($this->u_chair, "calories>200", "1 3 4");
        assert_search_papers($this->u_chair, "calories:<1000", "2 5");
        assert_search_papers($this->u_chair, "calories:1040", "3 4");
        assert_search_papers($this->u_chair, "calories≥200", "1 2 3 4");
    }

    function test_search_submission_field_edit_condition() {
        $this->conf->save_refresh_setting("options", 1, '[{"id":1,"name":"Calories","abbr":"calories","type":"numeric","position":1,"display":"default"},{"id":2,"name":"Fattening","type":"numeric","position":2,"display":"default","exists_if":"calories>200"}]');
        $this->conf->invalidate_caches(["options" => true]);
        $this->conf->qe("insert into PaperOption (paperId,optionId,value) values (1,2,1),(2,2,1),(3,2,1),(4,2,1),(5,2,1)");
        assert_search_papers($this->u_chair, "has:fattening", "1 3 4");
    }

    function test_withdraw_admin() {
        $paper16 = $this->u_chair->checked_paper_by_id(16);
        xassert($paper16->timeSubmitted > 0);
        xassert_eq($paper16->timeWithdrawn, 0);
        xassert_eqq($paper16->withdrawReason, null);
        MailChecker::clear();
        xassert_assign($this->u_chair, "paper,action,reason,notify\n16,withdraw,Paper is bad,no\n");
        MailChecker::check0();

        $paper16b = $this->u_chair->checked_paper_by_id(16);
        xassert_eqq($paper16b->timeSubmitted, -$paper16->timeSubmitted);
        xassert($paper16b->timeWithdrawn > 0);
        xassert_eqq($paper16b->withdrawReason, "Paper is bad");
        xassert_assign($this->u_chair, "paper,action,reason\n16,revive\n");
    }

    function test_withdraw_author() {
        $paper16 = $this->u_mogul->checked_paper_by_id(16);
        xassert($paper16->timeSubmitted > 0);
        xassert($paper16->timeWithdrawn <= 0);

        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        $paper16 = $this->u_mogul->checked_paper_by_id(16);
        xassert($paper16->timeSubmitted < 0);
        xassert($paper16->timeWithdrawn > 0);
        xassert_eqq($paper16->withdrawReason, "Sucky");

        xassert_assign_fail($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        $this->conf->save_refresh_setting("sub_sub", Conf::$now + 5);
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");
        $paper16 = $this->u_mogul->checked_paper_by_id(16);
        xassert($paper16->timeSubmitted > 0);
        xassert($paper16->timeWithdrawn <= 0);
    }

    function test_withdraw_nonauthor() {
        xassert_assign_fail($this->u_estrin, "paper,action,reason\n16,withdraw,Fucker\n");
    }

    function test_withdraw_review_interaction() {
        $this->u_chair->assign_review(16, $this->u_mgbaker->contactId, REVIEW_PC, []);
        // can withdraw because review not started
        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        save_review(16, $this->u_mgbaker, ["ovemer" => 2, "revexp" => 1, "ready" => false]);
        // can withdraw because review not ready
        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        save_review(16, $this->u_mgbaker, ["ovemer" => 2, "revexp" => 1, "ready" => true]);
        // can withdraw because review not seen
        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        $this->conf->qe("update PaperReview set reviewAuthorSeen=1 where paperId=? and contactId=?", 16, $this->u_mgbaker->contactId);
        // cannot withdraw because review seen
        xassert_assign_fail($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        assert_search_papers($this->u_chair, "16", "16");

        $this->conf->save_setting("sub_withdraw", 1);
        // can withdraw because setting allows it
        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        $this->conf->qe("update Paper set outcome=1 where paperId=?", 16);
        // cannot withdraw because decision set
        xassert_assign_fail($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");

        $this->conf->qe("update Paper set outcome=0 where paperId=?", 16);
        // can withdraw without decision set
        xassert_assign($this->u_mogul, "paper,action,reason\n16,withdraw,Sucky\n");
        xassert_assign($this->u_mogul, "paper,action,reason\n16,revive,Sucky\n");

        $this->conf->save_setting("sub_withdraw", null);
    }

    function test_withdraw_notification() {
        $u = $this->conf->checked_user_by_email("anja@research.att.com");
        xassert_eqq($u->disabled_flags(), 0);
        MailChecker::clear();
        xassert_assign($this->u_chair, "paper,action,reason\n16,withdraw,Suckola\n");
        MailChecker::check_db("withdraw-16-admin-notify");
        xassert_assign($this->u_chair, "paper,action,reason\n16,revive,Suckola\n");
        xassert_assign($this->u_chair, "paper,action,reason,notify\n16,withdraw,Suckola,no\n");
        MailChecker::check0();
        xassert_assign($this->u_chair, "paper,action,reason\n16,revive,Suckola\n");
        xassert_assign($this->u_chair, "paper,action,reason,notify\n16,withdraw,Suckola,yes\n");
        MailChecker::check_db("withdraw-16-admin-notify");
        xassert_assign($this->u_chair, "paper,action,reason\n16,revive,Suckola\n");
    }

    function test_review_tokens() {
        assert_search_papers($this->u_chair, "re:any 19", "");
        xassert_assign($this->u_chair, "paper,action,user\n19,review,anonymous\n");
        assert_search_papers($this->u_chair, "re:any 19", "19");
        assert_search_papers($this->u_chair, "re:1 19", "19");

        // check rev_tokens setting
        ConfInvariants::test_all($this->conf);
        xassert_assign($this->u_chair, "paper,action,user\n19,clearreview,anonymous\n");
        assert_search_papers($this->u_chair, "re:any 19", "");
        ConfInvariants::test_all($this->conf);
        xassert_assign($this->u_chair, "paper,action,user\n19,review,anonymous\n");

        xassert_assign($this->u_chair, "paper,action,user\n19,review,anonymous\n");
        assert_search_papers($this->u_chair, "re:1 19", "19");
        assert_search_papers($this->u_chair, "re:2 19", "");
        xassert_assign($this->u_chair, "paper,action,user\n19,review,new-anonymous\n");
        assert_search_papers($this->u_chair, "re:1 19", "");
        assert_search_papers($this->u_chair, "re:2 19", "19");
        xassert_assign($this->u_chair, "paper,action,user\n19,review,new-anonymous\n19,review,new-anonymous\n");
        assert_search_papers($this->u_chair, "re:1 19", "");
        assert_search_papers($this->u_chair, "re:4 19", "19");

        // check that there actually are tokens
        $paper19 = $this->u_chair->checked_paper_by_id(19);
        xassert_eqq(count($paper19->all_reviews()), 4);
        $revs = $paper19->reviews_as_list();
        for ($i = 0; $i < 4; ++$i) {
            xassert($revs[$i]->reviewToken);
            for ($j = $i + 1; $j < 4; ++$j) {
                xassert($revs[$i]->reviewToken != $revs[$j]->reviewToken);
            }
        }
    }

    function test_reset_deadlines() {
        $this->conf->save_setting("sub_reg", Conf::$now + 10);
        $this->conf->save_setting("sub_update", Conf::$now + 10);
        $this->conf->save_setting("sub_sub", Conf::$now + 10);
    }
}
