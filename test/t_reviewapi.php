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
        $conf->save_setting("sub_sub", Conf::$now + 100);
        $conf->save_setting("rev_open", 1);
        $conf->save_setting("viewrev", null);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu"); // pc
        $this->u_diot = $conf->checked_user_by_email("christophe.diot@sophia.inria.fr"); // pc, red

        Reviews_Tester::add_questions_for_response($this->conf);
        $review18A = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        $tf = (new ReviewValues($this->u_diot))->set_text($review18A, "review18A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save(null));
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
        // diot is a PC reviewer, not an administrator, and may not edit estrin's
        // review. diot can see estrin is assigned, so the error names a specific
        // reason (the review is not yet visible) rather than hiding its
        // existence — whether addressed by review ID through the URL `r`...
        $j = call_api("=review", $this->u_diot, ["r" => (string) $erow->reviewId, "OveMer" => "1", "ready" => "1"], $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->valid, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "not yet ready"));
        // ...or by `rid` in a JSON body (which prepare_save resolves)
        $qreq = TestQreq::post_json(["object" => "review", "rid" => $erow->reviewId, "OveMer" => 1]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->valid, false);
        xassert(str_contains(json_encode($j->message_list ?? []), "not yet ready"));
        // estrin's review is untouched
        $prow->load_reviews(true);
        xassert_eqq($prow->review_by_user($this->u_estrin)->reviewTime, $etime);
    }

    function test_review_hidden_from_author() {
        $prow = $this->conf->checked_paper_by_id(18);
        $rrow = $prow->checked_review_by_user($this->u_diot); // diot's submitted review 18A
        // an author of #18 can view the submission but cannot see its review
        // assignments (reviews are not released to authors here)
        $author = $this->conf->checked_user_by_email("cheshire@cs.stanford.edu");
        xassert($author->can_view_paper($prow));
        xassert(!$author->can_view_review_assignment($prow, $rrow));
        // so the review's existence is hidden as a 404 “not found”,
        // indistinguishable from a nonexistent review id...
        $jr = call_api_result("review", $author, TestQreq::get(["p" => 18, "r" => (string) $rrow->reviewId]));
        xassert_eqq($jr->status, 404);
        xassert(str_contains(json_encode($jr->content["message_list"] ?? []), "not found"));
        $jr = call_api_result("review", $author, TestQreq::get(["p" => 18, "r" => "99999"]));
        xassert_eqq($jr->status, 404);
        xassert(str_contains(json_encode($jr->content["message_list"] ?? []), "not found"));
        // ...for every `format`, which cannot bypass the permission check
        foreach (["text", "form"] as $fmt) {
            $jr = call_api_result("review", $author, TestQreq::get(["p" => 18, "r" => (string) $rrow->reviewId, "format" => $fmt]));
            xassert($jr instanceof JsonResult);
            xassert_eqq($jr->status, 404);
        }
        // a multiple-review download gives the author nothing
        foreach (["text", "textzip"] as $fmt) {
            $dl = call_api_result("reviews", $author, TestQreq::get(["p" => 18, "format" => $fmt]));
            xassert($dl instanceof Downloader);
            xassert_not_str_contains($dl->content_string(), "Weak reject");
        }
        // ...on POST too (attempting to edit it by `r`)
        $jr = call_api_result("=review", $author, ["r" => (string) $rrow->reviewId, "OveMer" => "1", "ready" => "1"], $prow);
        xassert_eqq($jr->status, 404);
        xassert(str_contains(json_encode($jr->content["message_list"] ?? []), "not found"));
        // an administrator can still fetch the review
        $j = call_api("review", $this->u_chair, TestQreq::get(["p" => 18, "r" => (string) $rrow->reviewId]));
        xassert_eqq($j->ok, true);
        xassert_eqq($j->review->object, "review");
        // diot's review is untouched
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
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

    function test_post_json_param_over_body() {
        $prow = $this->conf->checked_paper_by_id(18);
        // a `json` parameter is honored regardless of the request body's content
        // type: here it overrides a text/plain body that would otherwise be
        // parsed as an offline review form (and, being garbage, would fail)
        $qreq = TestQreq::post(["json" => json_encode(["object" => "review", "OveMer" => 1])])
            ->set_body("this is not a review form", "text/plain; charset=utf-8");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["s01"]);
        xassert_eqq($j->review->OveMer, 1);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 1);
        // restore
        call_api("review", $this->u_diot, TestQreq::post_json(["object" => "review", "OveMer" => 2]), $prow);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_json_and_upload_conflict() {
        $prow = $this->conf->checked_paper_by_id(18);
        // `json` and `upload` are alternative payload selectors; supplying both
        // is an error (the upload token need not even resolve)
        $qreq = TestQreq::post([
            "json" => json_encode(["object" => "review", "OveMer" => 1]),
            "upload" => "hct_nonexistent"
        ]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_str_contains($j->message_list[0]->message, "at most one of `json` and `upload`");
        // review unchanged
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_text_upload_capability() {
        $prow = $this->conf->checked_paper_by_id(18);
        $old_docstore = $this->conf->opt("docstore");
        $tmpdir = tempdir();
        $this->conf->set_opt("docstore", "{$tmpdir}%h%x");
        $this->conf->refresh_settings();
        xassert(!!$this->conf->docstore());

        // upload the offline review form as a text/plain file
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        $modified = str_replace("2. Weak reject", "3. Weak accept", $text);
        $qreq = (new Qrequest("POST", [
                "start" => 1, "temp" => 1, "size" => strlen($modified),
                "filename" => "review.txt", "mimetype" => "text/plain",
                "offset" => 0, "finish" => 1
            ]))->approve_token()->set_file_content("blob", $modified);
        $j = call_api("=upload", $this->u_diot, $qreq, null);
        xassert_eqq($j->ok, true);
        $token = $j->token;
        xassert(is_string($token));

        // POST /review referencing the uploaded text form via `upload`. The
        // body carries a non-form content type, which the `upload` overrides.
        $qreq = (new Qrequest("POST", ["p" => 18, "upload" => $token]))
            ->approve_token()->set_body("ignored body", "application/json");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->review->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("=review", $this->u_diot, ["r" => "0", "OveMer" => "2", "ready" => "1"], $prow);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);

        $this->conf->set_opt("docstore", $old_docstore);
        $this->conf->refresh_settings();
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

    function test_post_multi_json() {
        $prow = $this->conf->checked_paper_by_id(18);
        $drow = $prow->review_by_user($this->u_diot);
        // a dry-run batch: one valid edit (chair editing diot's review) and one
        // item naming a nonexistent paper
        $qreq = TestQreq::post_json([
            (object) ["object" => "review", "pid" => 18, "rid" => $drow->reviewId, "OveMer" => 3],
            (object) ["object" => "review", "pid" => 99999, "OveMer" => 1]
        ], ["dry_run" => 1]);
        $j = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->dry_run, true);
        xassert(!isset($j->review));
        xassert_eqq(count($j->status_list), 2);
        xassert_eqq($j->status_list[0]->valid, true);
        xassert_eqq($j->status_list[0]->change_list, ["s01"]);
        xassert_eqq($j->status_list[0]->pid, 18);
        xassert_eqq($j->status_list[1]->valid, false);
        // dry run: diot's review is unchanged
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_multi_json_commit() {
        $prow = $this->conf->checked_paper_by_id(18);
        // a committed batch of one item (diot editing his own review)
        $qreq = TestQreq::post_json([
            (object) ["object" => "review", "pid" => 18, "OveMer" => 3]
        ]);
        $j = call_api("reviews", $this->u_diot, $qreq);
        xassert_eqq($j->ok, true);
        xassert(!isset($j->single));
        xassert_eqq(count($j->status_list), 1);
        xassert_eqq($j->status_list[0]->valid, true);
        xassert_eqq(count($j->reviews), 1);
        xassert_eqq($j->reviews[0]->OveMer, 3);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 3);
        // restore
        call_api("reviews", $this->u_diot, TestQreq::post_json([(object) ["object" => "review", "pid" => 18, "OveMer" => 2]]));
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_text_multi() {
        // an offline review file uploaded to /reviews resolves each review's own
        // paper (no URL `p`); dry-run so nothing persists
        $text = file_get_contents(SiteLoader::resolve("test/review18A.txt"));
        $modified = str_replace("2. Weak reject", "3. Weak accept", $text);
        $qreq = TestQreq::post(["dry_run" => 1])->set_body($modified, "text/plain; charset=utf-8");
        $j = call_api("reviews", $this->u_diot, $qreq);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->dry_run, true);
        xassert_eqq(count($j->status_list), 1);
        xassert_eqq($j->status_list[0]->valid, true);
        xassert_eqq($j->status_list[0]->pid, 18);
        xassert(in_array("s01", $j->status_list[0]->change_list, true));
        $prow = $this->conf->checked_paper_by_id(18);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_batch_if_unmodified_since() {
        $prow = $this->conf->checked_paper_by_id(18);
        $drow = $prow->checked_review_by_user($this->u_diot);
        // a batch-wide `if_unmodified_since` guards every item; a stale value
        // conflicts
        $qreq = TestQreq::post_json([
            (object) ["object" => "review", "pid" => 18, "rid" => $drow->reviewId, "OveMer" => 3]
        ], ["if_unmodified_since" => (string) ($drow->reviewModified - 100)]);
        $j = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->status_list[0]->valid, false);
        xassert_eqq($j->status_list[0]->conflict, true);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);

        // a review object overrides the batch default with its own precondition
        $qreq = TestQreq::post_json([
            (object) ["object" => "review", "pid" => 18, "rid" => $drow->reviewId,
                      "OveMer" => 3, "if_unmodified_since" => $drow->reviewModified]
        ], ["if_unmodified_since" => (string) ($drow->reviewModified - 100), "dry_run" => 1]);
        $j = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->status_list[0]->valid, true);
        xassert_eqq($j->status_list[0]->conflict ?? false, false);
    }

    function test_post_batch_if_vtag_match_new() {
        $prow = $this->conf->checked_paper_by_id(18);
        $drow = $prow->checked_review_by_user($this->u_diot);
        // a batch-wide `if_vtag_match=0` requires every review be new; diot’s
        // review already exists, so the item conflicts
        $qreq = TestQreq::post_json([
            (object) ["object" => "review", "pid" => 18, "rid" => $drow->reviewId, "OveMer" => 3]
        ], ["if_vtag_match" => "0"]);
        $j = call_api("reviews", $this->u_chair, $qreq);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->status_list[0]->valid, false);
        xassert_eqq($j->status_list[0]->conflict, true);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_post_single_json_if_unmodified_since() {
        $prow = $this->conf->checked_paper_by_id(18);
        $drow = $prow->checked_review_by_user($this->u_diot);
        // a JSON single POST honors the query `if_unmodified_since` (previously
        // read only for form-encoded submissions)
        $qreq = TestQreq::post_json(["object" => "review", "OveMer" => 3],
            ["if_unmodified_since" => (string) ($drow->reviewModified - 100)]);
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_eqq($j->conflict, true);
        xassert_eqq($j->valid, false);
        $prow->load_reviews(true);
        xassert_eq($prow->checked_review_by_user($this->u_diot)->fidval("s01"), 2);
    }

    function test_delete_review() {
        $prow = $this->conf->checked_paper_by_id(18);
        $var = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        // chair creates a fresh review for a PC member (so the delete disturbs no
        // seeded review)
        $qreq = TestQreq::post_json(["object" => "review", "email" => $var->email,
            "OveMer" => 2, "ready" => true], ["r" => "new"]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, true);
        $prow->load_reviews(true);
        $rrow = $prow->review_by_user($var);
        xassert(!!$rrow);
        $rid = (string) $rrow->reviewId;

        // a non-administrator cannot delete a review
        $jr = call_api_result("review", $this->u_diot, TestQreq::delete(["p" => 18, "r" => $rid]), $prow);
        xassert_eqq($jr->status, 403);

        // dry-run delete reports the change without performing it
        $j = call_api("review", $this->u_chair, TestQreq::delete(["p" => 18, "r" => $rid, "dry_run" => 1]), $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->dry_run, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["delete"]);
        $prow->load_reviews(true);
        xassert(!!$prow->review_by_user($var));

        // real delete
        $j = call_api("review", $this->u_chair, TestQreq::delete(["p" => 18, "r" => $rid]), $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, ["delete"]);
        $prow->load_reviews(true);
        xassert(!$prow->review_by_user($var));

        // deleting again → not found
        $jr = call_api_result("review", $this->u_chair, TestQreq::delete(["p" => 18, "r" => $rid]), $prow);
        xassert_eqq($jr->status, 404);
    }

    /** @param int $cid
     * @return array<string,?string> */
    private function paper18_review_row($cid) {
        $obj = Dbl::fetch_first_object($this->conf->dblink,
            "select * from PaperReview where paperId=18 and contactId=?", $cid);
        return $obj ? (array) $obj : [];
    }

    /** An administrator creates an empty review of `$review_type` for `$var` on
     * paper 18 via POST /review, then the same review via POST /assign, and the
     * database rows must be identical (save for the unique reviewId and random
     * reviewTime version tag).
     * @param string $review_type
     * @param string $assign_action
     * @param int $expected_type */
    private function check_create_matches_assignment(PaperInfo $prow, Contact $var, $review_type, $assign_action, $expected_type) {
        $cid = $var->contactId;
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=18 and contactId=?", $cid), 0);

        // via POST /review with `r=new` and an explicit `review_type`
        $qreq = TestQreq::post_json(["object" => "review", "email" => $var->email,
            "review_type" => $review_type], ["r" => "new"]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, true);
        $rev_row = $this->paper18_review_row($cid);
        xassert_eqq($rev_row["reviewType"] ?? null, (string) $expected_type);
        $this->conf->qe("delete from PaperReview where paperId=18 and contactId=?", $cid);

        // via POST /assign
        $qreq = TestQreq::post(["assignments" => "paper,action,email\n18,{$assign_action},{$var->email}"]);
        $j = call_api("assign", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, true);
        $assign_row = $this->paper18_review_row($cid);
        xassert_eqq($assign_row["reviewType"] ?? null, (string) $expected_type);
        $this->conf->qe("delete from PaperReview where paperId=18 and contactId=?", $cid);

        unset($rev_row["reviewId"], $rev_row["reviewTime"]);
        unset($assign_row["reviewId"], $assign_row["reviewTime"]);
        xassert_eqq($rev_row, $assign_row);
    }

    function test_create_empty_review_matches_assignment() {
        $prow = $this->conf->checked_paper_by_id(18);
        $var = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        xassert(!$prow->review_by_user($var));

        // POST /review and POST /assign create identical reviews of each type
        $this->check_create_matches_assignment($prow, $var, "pc", "pcreview", REVIEW_PC);
        $this->check_create_matches_assignment($prow, $var, "primary", "primaryreview", REVIEW_PRIMARY);
        $this->check_create_matches_assignment($prow, $var, "secondary", "secondaryreview", REVIEW_SECONDARY);
        $this->check_create_matches_assignment($prow, $var, "meta", "metareview", REVIEW_META);
    }

    function test_create_review_type_permissions() {
        $prow = $this->conf->checked_paper_by_id(18);
        $var = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        $cid = $var->contactId;
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=18 and contactId=?", $cid), 0);

        // a non-administrator (PC member) cannot set a non-default review type
        $qreq = TestQreq::post_json(["object" => "review", "review_type" => "primary"], ["r" => "new"]);
        $j = call_api("review", $this->u_estrin, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_str_contains(json_encode($j->message_list ?? []), "administrator can set the review type");

        // an administrator cannot assign a PC review to a non-PC reviewer
        $author = $this->conf->checked_user_by_email("cheshire@cs.stanford.edu");
        xassert(!$author->is_pc_member());
        $qreq = TestQreq::post_json(["object" => "review", "email" => $author->email,
            "review_type" => "primary"], ["r" => "new"]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_str_contains(json_encode($j->message_list ?? []), "not a PC member");

        // an unknown review type is rejected
        $qreq = TestQreq::post_json(["object" => "review", "email" => $var->email,
            "review_type" => "bogus"], ["r" => "new"]);
        $j = call_api("review", $this->u_chair, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_str_contains(json_encode($j->message_list ?? []), "Invalid review type");

        // nothing was created
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=18 and contactId=?", $cid), 0);
    }

    function test_create_review_type_nonadmin_default() {
        // enable PC self-assignment
        $this->conf->save_setting("pcrev_any", 1);
        $this->conf->refresh_settings();
        $prow = $this->conf->checked_paper_by_id(18);
        $var = $this->conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // PC member
        $cid = $var->contactId;
        xassert($var->isPC);
        xassert(!$var->can_manage_reviews($prow));
        xassert(!$prow->review_by_user($var));

        // a non-administrator PC member may self-assign a review of their default
        // (optional PC) type
        $qreq = TestQreq::post_json(["object" => "review", "review_type" => "pc"], ["r" => "new"]);
        $j = call_api("review", $var, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($this->paper18_review_row($cid)["reviewType"] ?? null, (string) REVIEW_PC);
        $this->conf->qe("delete from PaperReview where paperId=18 and contactId=?", $cid);

        // but not a primary review
        $qreq = TestQreq::post_json(["object" => "review", "review_type" => "primary"], ["r" => "new"]);
        $j = call_api("review", $var, $qreq, $prow);
        xassert_eqq($j->ok, false);
        xassert_str_contains(json_encode($j->message_list ?? []), "administrator can set the review type");
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=18 and contactId=?", $cid), 0);

        $this->conf->save_setting("pcrev_any", null);
        $this->conf->refresh_settings();
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

    function test_fetch_format_text() {
        $qreq = TestQreq::get(["p" => 18, "r" => "18A", "format" => "text"]);
        $dl = call_api_result("review", $this->u_diot, $qreq);
        xassert($dl instanceof Downloader);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("Content-Type"), "text/plain; charset=utf-8");
        xassert_str_contains($dl->header("Content-Disposition"), "review18A.txt");
        $text = $dl->content_string();
        xassert_str_contains($text, "Review #18A");
        xassert_str_contains($text, "Paper: #18");
        xassert_str_contains($text, "No comments");
    }

    function test_fetch_format_form() {
        $qreq = TestQreq::get(["p" => 18, "r" => "18A", "format" => "form"]);
        $dl = call_api_result("review", $this->u_diot, $qreq);
        xassert($dl instanceof Downloader);
        $text = $dl->content_string();
        xassert_str_contains($text, "==+== Begin Review #18A");
        xassert_str_contains($text, "==+== Paper #18");
        // the form round-trips: POST it back and the review is unchanged
        $prow = $this->conf->checked_paper_by_id(18);
        $qreq = TestQreq::post(["p" => 18])
            ->set_file_content("file", $text, "review18A.txt", "text/plain");
        $j = call_api("review", $this->u_diot, $qreq, $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->valid, true);
        xassert_eqq($j->change_list, []);
    }

    function test_fetch_format_bad() {
        $qreq = TestQreq::get(["p" => 18, "r" => "18A", "format" => "xml"]);
        $jr = call_api_result("review", $this->u_diot, $qreq);
        xassert_eqq($jr->status, 400);
        // ZIP formats are available only on the multiple-review endpoint
        $qreq = TestQreq::get(["p" => 18, "r" => "18A", "format" => "textzip"]);
        $jr = call_api_result("review", $this->u_diot, $qreq);
        xassert_eqq($jr->status, 400);
    }

    function test_multi_fetch_format_text() {
        $qreq = TestQreq::get(["p" => 18, "format" => "text"]);
        $dl = call_api_result("reviews", $this->u_chair, $qreq);
        xassert($dl instanceof Downloader);
        xassert_str_contains($dl->header("Content-Disposition"), "reviews.txt");
        $text = $dl->content_string();
        // paper 18 has three viewable reviews, each with its own title block
        xassert_eqq(substr_count($text, "* Paper: #18 "), 3);

        // the search path must render whole reviews, not just their scores
        $qreq = TestQreq::get(["q" => "consistent overhead", "rq" => "re:complete", "format" => "text"]);
        $dl = call_api_result("reviews", $this->u_chair, $qreq);
        xassert($dl instanceof Downloader);
        $text = $dl->content_string();
        xassert_str_contains($text, "Review #18A");
        xassert_str_contains($text, "No comments");
        xassert_str_contains($text, "Four score and seven years ago");

        // one matching review yields a single-review filename
        $qreq = TestQreq::get(["p" => 18, "u" => $this->u_diot->email, "format" => "text"]);
        $dl = call_api_result("reviews", $this->u_chair, $qreq);
        xassert($dl instanceof Downloader);
        xassert_str_contains($dl->header("Content-Disposition"), "review18A.txt");
        xassert_str_contains($dl->content_string(), "Review #18A");
    }

    function test_multi_fetch_format_zip() {
        $qreq = TestQreq::get(["p" => 18, "format" => "textzip"]);
        $dl = call_api_result("reviews", $this->u_chair, $qreq);
        xassert($dl instanceof Downloader);
        xassert_eqq($dl->header("Content-Type"), "application/zip");
        xassert_str_contains($dl->header("Content-Disposition"), "reviews.zip");
        $zc = self::zip_contents($dl->content_string());
        xassert_eqq(count($zc), 3);
        $pfx = $this->conf->download_prefix;
        xassert_in_eqq("{$pfx}review18A.txt", array_keys($zc));
        xassert_str_contains($zc["{$pfx}review18A.txt"], "Review #18A");
        xassert_str_contains($zc["{$pfx}review18A.txt"], "No comments");

        // `formzip` packages offline forms instead
        $qreq = TestQreq::get(["p" => 18, "u" => $this->u_diot->email, "format" => "formzip"]);
        $dl = call_api_result("reviews", $this->u_chair, $qreq);
        xassert($dl instanceof Downloader);
        $zc = self::zip_contents($dl->content_string());
        xassert_eqq(array_keys($zc), ["{$pfx}review18A.txt"]);
        xassert_str_contains($zc["{$pfx}review18A.txt"], "==+== Begin Review #18A");
    }

    function test_fetch_download_json() {
        // `download=1` trades the response envelope for the bare payload that
        // `POST /review` accepts
        $jr = call_api_result("review", $this->u_diot,
            TestQreq::get(["p" => 18, "r" => "18A", "download" => 1]));
        xassert($jr instanceof JsonResult);
        xassert($jr->minimal);
        xassert_str_contains($jr->header("Content-Disposition"),
            $this->conf->download_prefix . "review18A.json");
        $j = (object) $jr->content;
        xassert_eqq($j->object, "review");
        xassert_eqq($j->pid, 18);
        xassert_eqq($j->OveMer, 2);
        xassert(!isset($j->ok));

        // and it round-trips: uploading it again changes nothing
        $prow = $this->conf->checked_paper_by_id(18);
        $j2 = call_api("review", $this->u_diot, TestQreq::post_json($j), $prow);
        xassert_eqq($j2->ok, true);
        xassert_eqq($j2->valid, true);
        xassert_eqq($j2->change_list, []);
    }

    function test_multi_fetch_download_json() {
        // `/reviews` downloads the bare array `POST /reviews` accepts
        $jr = call_api_result("reviews", $this->u_chair,
            TestQreq::get(["p" => 18, "download" => 1]));
        xassert($jr instanceof JsonResult);
        xassert($jr->minimal);
        xassert_str_contains($jr->header("Content-Disposition"), "reviews.json");
        $a = $jr->content;
        xassert(is_list($a));
        xassert_eqq(count($a), 3);
        xassert_eqq($a[0]->object, "review");
    }

    function test_download_inline() {
        // `download=0` makes a text rendering inline rather than an attachment
        $dl = call_api_result("review", $this->u_diot,
            TestQreq::get(["p" => 18, "r" => "18A", "format" => "text", "download" => 0]));
        xassert($dl instanceof Downloader);
        xassert_str_contains($dl->header("Content-Disposition"), "inline; filename=");
        xassert_str_contains($dl->content_string(), "Review #18A");
        // the default is an attachment
        $dl = call_api_result("review", $this->u_diot,
            TestQreq::get(["p" => 18, "r" => "18A", "format" => "text"]));
        xassert_str_contains($dl->header("Content-Disposition"), "attachment; filename=");
    }

    // A non-JSON `format` must not expose review fields the caller cannot see:
    // the text renderings apply the same view-score bound as the JSON one.
    function test_format_field_visibility() {
        $conf = $this->conf;
        // add a field only the review's own reviewer and administrators may see
        $sv = SettingValues::make_request($conf->root_user(), [
            "has_rf" => 1,
            "rf/1/id" => "t09",
            "rf/1/name" => "Reviewer notes",
            "rf/1/visibility" => "admin",
            "rf/1/order" => 20
        ]);
        xassert($sv->execute());
        $f = $conf->review_field("t09");
        xassert(!!$f);
        xassert_eqq($f->view_score, VIEWSCORE_REVIEWERONLY);
        $uid = $f->uid();

        // diot records a value in it
        $prow = $conf->checked_paper_by_id(18);
        $j = call_api("review", $this->u_diot,
            TestQreq::post_json(["object" => "review", $uid => "SEKRIT"]), $prow);
        xassert_eqq($j->ok, true);
        xassert_eqq($j->review->$uid, "SEKRIT\n");

        // let PC members read one another's reviews, then find one who is not
        // the reviewer: they must not see the reviewer-only field
        $conf->save_refresh_setting("viewrev", Conf::VIEWREV_ALWAYS);
        $prow = $conf->checked_paper_by_id(18);
        $rrow = $prow->checked_review_by_user($this->u_diot);
        $u_other = null;
        foreach ($conf->pc_members() as $u) {
            if ($u->contactId !== $this->u_diot->contactId
                && !$u->privChair
                && $u->can_view_review($prow, $rrow)) {
                $u_other = $u;
                break;
            }
        }
        xassert(!!$u_other);

        // hide reviewer identities from the PC: a name is subject to the same
        // kind of bound as a field
        $conf->save_refresh_setting("viewrevid", Conf::VIEWREV_NEVER);
        xassert($this->u_chair->can_view_review_identity($prow, $rrow));
        xassert(!$u_other->can_view_review_identity($prow, $rrow));

        foreach (["text", "form"] as $fmt) {
            // the reviewer and administrators see the field
            foreach ([$this->u_diot, $this->u_chair] as $u) {
                $dl = call_api_result("review", $u, TestQreq::get(["p" => 18, "r" => "18A", "format" => $fmt]));
                xassert($dl instanceof Downloader);
                $t = $dl->content_string();
                xassert_str_contains($t, "SEKRIT");
                xassert_str_contains($t, "Diot");
            }
            // ...but another PC member gets the review without it, and without
            // the reviewer's name
            $dl = call_api_result("review", $u_other, TestQreq::get(["p" => 18, "r" => "18A", "format" => $fmt]));
            xassert($dl instanceof Downloader);
            $t = $dl->content_string();
            xassert_str_contains($t, "18A");
            xassert_not_str_contains($t, "SEKRIT");
            xassert_not_str_contains($t, "Reviewer notes");
            xassert_not_str_contains($t, "Diot");
        }

        // the same bound applies to the multiple-review and ZIP renderings
        $dl = call_api_result("reviews", $u_other, TestQreq::get(["p" => 18, "format" => "text"]));
        xassert_not_str_contains($dl->content_string(), "SEKRIT");
        $dl = call_api_result("reviews", $u_other, TestQreq::get(["p" => 18, "format" => "formzip"]));
        xassert_not_str_contains(join("", self::zip_contents($dl->content_string())), "SEKRIT");
        $dl = call_api_result("reviews", $this->u_chair, TestQreq::get(["p" => 18, "format" => "textzip"]));
        xassert_str_contains(join("", self::zip_contents($dl->content_string())), "SEKRIT");

        // and matches what `format=json` reports
        $jr = call_api("review", $u_other, TestQreq::get(["p" => 18, "r" => "18A"]));
        xassert_eqq($jr->ok, true);
        xassert(!isset($jr->review->$uid));

        // restore the review form and review visibility
        $sv = SettingValues::make_request($conf->root_user(), [
            "has_rf" => 1,
            "rf/1/id" => "t09",
            "rf/1/delete" => 1
        ]);
        xassert($sv->execute());
        xassert(!$conf->review_field("t09"));
        $conf->save_refresh_setting("viewrev", null);
        $conf->save_refresh_setting("viewrevid", null);
    }

    /** @param string $zipdata
     * @return array<string,string> */
    static private function zip_contents($zipdata) {
        $fn = tempnam("/tmp", "hctz");
        file_put_contents($fn, $zipdata);
        $zip = new ZipArchive;
        xassert_eqq($zip->open($fn), true);
        $zc = [];
        for ($i = 0; $i !== $zip->numFiles; ++$i) {
            $zc[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($fn);
        return $zc;
    }
}
