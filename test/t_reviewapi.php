<?php
// t_reviewapi.php -- HotCRP tests
// Copyright (c) 2024-2025 Eddie Kohler; see LICENSE.

class ReviewAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_estrin;
    /** @var Contact
     * @readonly */
    public $u_diot;
    /** @var int
     * @readonly */
    public $r18a_id;
    /** @var int */
    public $npid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $conf->save_setting("sub_open", 1);
        $conf->save_setting("sub_update", Conf::$now + 100);
        $conf->save_setting("sub_sub", Conf::$now + 100);
        $conf->save_setting("rev_open", 1);
        $conf->save_setting("viewrev", null);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu"); // pc
        $this->u_diot = $conf->checked_user_by_email("christophe.diot@sophia.inria.fr"); // pc, red

        Reviews_Tester::add_questions_for_response($this->conf);
        $review18A = file_get_contents(SiteLoader::find("test/review18A.txt"));
        $tf = (new ReviewValues($conf))->set_text($review18A, "review18A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_diot, null));
        xassert_eqq($tf->summary_status(), MessageSet::SUCCESS);
        $rrow = $conf->checked_paper_by_id(18)->checked_review_by_user($this->u_diot);
        $this->r18a_id = $rrow->reviewId;
    }

    function test_fetch() {
        $qreq = TestQreq::get(["p" => 18, "r" => $this->r18a_id]);
        $jr = call_api("review", $this->u_diot, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->review->object, "review");
        xassert_eqq($jr->review->OveMer, 2);
        xassert_eqq($jr->review->ComAut, "No comments\n");

        $qreq = TestQreq::get(["p" => 18, "r" => "A"]);
        $jr = call_api("review", $this->u_diot, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->review->object, "review");
        xassert_eqq($jr->review->OveMer, 2);
        xassert_eqq($jr->review->ComAut, "No comments\n");

        $qreq = TestQreq::get(["p" => 18, "r" => "18A"]);
        $jr = call_api("review", $this->u_diot, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->review->object, "review");
        xassert_eqq($jr->review->OveMer, 2);
        xassert_eqq($jr->review->ComAut, "No comments\n");
    }

    function test_multi_fetch() {
        $qreq = TestQreq::get(["p" => 18, "u" => $this->u_diot->email]);
        $jr = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count($jr->reviews), 1);
        xassert_eqq($jr->reviews[0]->object, "review");
        xassert_eqq($jr->reviews[0]->OveMer, 2);
        xassert_eqq($jr->reviews[0]->ComAut, "No comments\n");

        $qreq = TestQreq::get(["p" => 18]);
        $jr = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count($jr->reviews), 3);

        $qreq = TestQreq::get(["q" => "consistent overhead", "rq" => "re:complete"]);
        $jr = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count($jr->reviews), 1);
        xassert_eqq($jr->reviews[0]->pid, 18);
        xassert_eqq($jr->reviews[0]->reviewer_email, $this->u_diot->email);
    }
}
