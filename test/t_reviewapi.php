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
        $review18A = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        $tf = (new ReviewValues($conf))->set_text($review18A, "review18A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_diot, null));
        xassert_eqq($tf->summary_status(), MessageSet::SUCCESS);
        $rrow = $conf->checked_paper_by_id(18)->checked_review_by_user($this->u_diot);
        $this->r18a_id = $rrow->reviewId;
    }

    function test_post_edit_form() {
        $prow = $this->conf->checked_paper_by_id(18);
        // edit diot's own submitted review (OveMer 2 -> 3) via form POST;
        // no `r` param means "the acting reviewer's review"
        $j = call_api("=review", $this->u_diot, ["OveMer" => "3", "ready" => "1"], $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["s01"]);
        xassert_eqq($j->review->OveMer, 3);
        xassert_eqq($j->rid, "18A");
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore (r=0 is equivalent to omitting r)
        $j = call_api("=review", $this->u_diot, ["r" => "0", "OveMer" => "2", "ready" => "1"], $prow);
        xassert_eqq($j->ok, true);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_edit_json() {
        $prow = $this->conf->checked_paper_by_id(18);
        $qreq = TestQreq::post_json(["object" => "review", "OveMer" => 3]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["s01"]);
        xassert_eqq($j->review->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("review", $this->u_diot, TestQreq::post_json(["object" => "review", "OveMer" => 2]), $prow);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_dry_run() {
        $prow = $this->conf->checked_paper_by_id(18);
        $j = call_api("=review", $this->u_diot, ["OveMer" => "1", "ready" => "1", "dry_run" => "1"], $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->dry_run, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["s01"]);
        xassert(!isset($j->review));
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_conflict() {
        $prow = $this->conf->checked_paper_by_id(18);
        $rr = $prow->checked_review_by_user($this->u_diot);
        $j = call_api("=review", $this->u_diot, ["OveMer" => "1", "ready" => "1", "if_vtag_match" => (string) ($rr->reviewTime + 7)], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->conflict, true);
        xassert_eqq($j->valid, false);
        // the conflict still reports the change it would have made
        xassert_eqq($j->change_list, ["s01"]);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_new_requires_new() {
        $prow = $this->conf->checked_paper_by_id(18);
        // `r=new` on a reviewer who already has a review is a conflict
        $j = call_api("=review", $this->u_diot, ["r" => "new", "OveMer" => "1", "ready" => "1"], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->conflict, true);
        xassert_eqq($j->valid, false);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_pid_mismatch() {
        $prow = $this->conf->checked_paper_by_id(18);
        // an object `pid` that disagrees with the URL's paper is rejected
        // (by ReviewValues::prepare_save)
        $qreq = TestQreq::post_json(["object" => "review", "pid" => 17, "OveMer" => 1]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "does not match"));
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_pid_in_body() {
        // no URL `p`: the paper is taken from the object's `pid`
        $qreq = TestQreq::post_json(["object" => "review", "pid" => 18, "OveMer" => 3]);
        $j = call_api("review", $this->u_diot, $qreq, null);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->review->OveMer, 3);
        $prow = $this->conf->checked_paper_by_id(18);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("review", $this->u_diot, TestQreq::post_json(["object" => "review", "pid" => 18, "OveMer" => 2]), null);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_no_p_no_pid() {
        // no URL `p` and no object `pid` is an error
        $qreq = TestQreq::post_json(["object" => "review", "OveMer" => 1]);
        $j = call_api("review", $this->u_diot, $qreq, null);
        xassert_eqq($j->ok, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "Submission ID required"));
    }

    function test_post_rid_mismatch() {
        $prow = $this->conf->checked_paper_by_id(18);
        $erow = $prow->review_by_user($this->u_estrin);
        xassert(!!$erow);
        // URL `r` names diot's review, but the object's `rid` names estrin's
        $qreq = TestQreq::post_json(["object" => "review", "rid" => $erow->reviewId, "OveMer" => 1], ["r" => "18A"]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "Review ID does not match"));
    }

    function test_post_rid_confirms() {
        $prow = $this->conf->checked_paper_by_id(18);
        // an object `rid` that agrees with the URL's `r` is accepted
        $qreq = TestQreq::post_json(["object" => "review", "rid" => "18A", "OveMer" => 3], ["r" => "18A"]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->review->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("review", $this->u_diot, TestQreq::post_json(["object" => "review", "OveMer" => 2]), $prow);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_not_found() {
        $prow = $this->conf->checked_paper_by_id(18);
        $j = call_api("=review", $this->u_diot, ["r" => "99Z", "OveMer" => "1"], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->status_code, 404);
    }

    function test_post_create() {
        $prow = $this->conf->checked_paper_by_id(18);
        xassert(!$prow->review_by_user($this->u_chair));
        // an administrator creates a fresh (draft) review; change_list leads with `new`
        $j = call_api("=review", $this->u_chair, ["r" => "new", "OveMer" => "2", "ready" => "0"], $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list[0], "new");
        xassert(in_array("s01", $j->change_list, true));
        $prow->load_reviews(true);
        $rr = $prow->review_by_user($this->u_chair);
        xassert(!!$rr);
        xassert_eq($rr->fidval("s01"), 2);
        // clean up
        $rr->delete($this->u_chair);
    }

    function test_post_cannot_edit_others_review() {
        $prow = $this->conf->checked_paper_by_id(18);
        $erow = $prow->review_by_user($this->u_estrin);
        xassert(!!$erow);
        $etime = $erow->reviewTime;
        // diot is a PC reviewer, not an administrator: he may not edit estrin's
        // review, whether addressed by review ID through the URL `r`...
        $j = call_api("=review", $this->u_diot, ["r" => (string) $erow->reviewId, "OveMer" => "1", "ready" => "1"], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->valid, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "permission"));
        // ...or by `rid` in a JSON body (which prepare_save resolves)
        $qreq = TestQreq::post_json(["object" => "review", "rid" => $erow->reviewId, "OveMer" => 1]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->valid, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "permission"));
        // estrin's review is untouched
        $prow->load_reviews(true);
        xassert_eqq($prow->review_by_user($this->u_estrin)->reviewTime, $etime);
    }

    function test_post_review_id_from_other_paper() {
        $prow = $this->conf->checked_paper_by_id(18);
        // a real review that lives on a different paper
        $orid = $this->conf->fetch_ivalue("select reviewId from PaperReview where paperId!=18 and reviewId>0 order by reviewId limit 1");
        xassert($orid > 0);
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=18 and reviewId=?", $orid), 0);
        // even an administrator cannot reach it through paper 18's endpoint:
        // review lookup is scoped to the URL paper
        $j = call_api("=review", $this->u_chair, ["r" => (string) $orid, "OveMer" => "1"], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->status_code, 404);
        xassert(str_contains(json_encode($j->message_list ?? []), "not found"));
        // and likewise via a JSON body `rid`
        $qreq = TestQreq::post_json(["object" => "review", "rid" => $orid, "OveMer" => 1]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "not found"));
    }

    function test_post_text_upload() {
        $prow = $this->conf->checked_paper_by_id(18);
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        // an uploaded offline form (text) is parsed with parse_text and saved
        $modified = str_replace("2. Weak reject", "3. Weak accept", $text);
        $qreq = TestQreq::post(["p" => 18])
            ->set_file_content("file", $modified, "review18A.txt", "text/plain");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->review->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore by re-uploading the original form
        $qreq = TestQreq::post(["p" => 18])
            ->set_file_content("file", $text, "review18A.txt", "text/plain");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_text_body() {
        $prow = $this->conf->checked_paper_by_id(18);
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        // a raw text/plain body (with charset parameter) is parsed as an
        // offline review form
        $modified = str_replace("2. Weak reject", "3. Weak accept", $text);
        $qreq = TestQreq::post(["p" => 18])->set_body($modified, "text/plain; charset=utf-8");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->review->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("review", $this->u_diot, TestQreq::post(["p" => 18])->set_body($text, "text/plain; charset=utf-8"), $prow);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_text_wrong_paper() {
        $prow = $this->conf->checked_paper_by_id(18);
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        // a form whose paper section names a different submission is ignored
        $other = str_replace("Paper #18", "Paper #17", $text);
        $qreq = TestQreq::post(["p" => 18])
            ->set_file_content("file", $other, "review.txt", "text/plain");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "not for this"));
        // diot's paper 18 review is untouched
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_text_other_reviewer_denied() {
        $prow = $this->conf->checked_paper_by_id(18);
        $etime0 = $prow->review_by_user($this->u_estrin)->reviewTime;
        // the form names diot as the reviewer; estrin (a PC member, not an admin)
        // may not upload a review on diot's behalf
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        $qreq = TestQreq::post(["p" => 18])
            ->set_file_content("file", $text, "review18A.txt", "text/plain");
        $j = call_api("review", $this->u_estrin, $qreq, $prow);
        xassert_eqq($j->ok, false);
        // diot's review unchanged
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
        xassert_eqq($prow->review_by_user($this->u_estrin)->reviewTime, $etime0);
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
