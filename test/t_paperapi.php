<?php
// t_paperapi.php -- HotCRP tests
// Copyright (c) 2024-2025 Eddie Kohler; see LICENSE.

#[RequireDb("fresh")]
class PaperAPI_Tester {
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
    public $u_puneet;
    /** @var Contact
     * @readonly */
    public $u_micke;
    /** @var int */
    public $npid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $conf->save_setting("sub_open", 1);
        $conf->save_setting("rev_open", 1);
        $conf->save_setting("viewrev", null);
        if (!$this->allow_submission()) {
            $conf->refresh_settings();
        }
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu"); // pc
        $this->u_puneet = $conf->checked_user_by_email("puneet@catarina.usc.edu");
        $this->u_micke = $conf->checked_user_by_email("micke@cdt.luth.se");
    }

    function allow_submission() {
        $this->set_submission_deadline(Conf::$now + 100);
    }

    function prevent_submission() {
        $this->set_submission_deadline(Conf::$now - 10);
    }

    function set_submission_deadline($t) {
        $a = $this->conf->save_setting("sub_update", $t);
        $b = $this->conf->save_setting("sub_sub", $t);
        if ($a || $b) {
            $this->conf->refresh_settings();
        }
        return $a || $b;
    }

    function test_save_submit_new_paper() {
        $qreq = TestQreq::post(["p" => "new", "status:submit" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1])->set_file_content("submission:file", "%PDF-2", null, "application/pdf");
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "New paper");
        xassert_eqq($jr->paper->abstract, "This is an abstract");
        $this->npid = $jr->paper->pid;
    }

    function test_save_submit_new_paper_zip() {
        $qreq = TestQreq::post_zip([
            "data.json" => ["pid" => "new", "title" => "Jans paper", "abstract" => "Swafford 4eva\r\n", "authors" => [["name" => "Jan Swafford", "email" => "swafford@_.com"]], "submission" => ["content_file" => "janspaper.pdf"], "status" => "submitted"],
            "janspaper.pdf" => "%PDF-JAN"
        ], ["p" => "new"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "Jans paper");
        xassert_eqq($jr->paper->abstract, "Swafford 4eva");
        $prow = $this->conf->checked_paper_by_id($jr->paper->pid);
        $doc = $prow->document(DTYPE_SUBMISSION, 0, true);
        xassert_eqq($doc->filename, "janspaper.pdf");
        xassert_eqq($doc->mimetype, "application/pdf");
        xassert_eqq($doc->content(), "%PDF-JAN");
    }

    function test_submit_new_paper_pleb() {
        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "Soft Timers for Scalable Protocols",
            "abstract" => "The softest timers are the most scalable. So delicious, so delightful",
            "authors" => [["name" => "Puneet Sharma", "email" => $this->u_puneet->email]],
            "submission" => ["content" => "%PDF-2"],
            "status" => "draft"
        ], ["p" => "new"]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "Soft Timers for Scalable Protocols");
    }

    function test_update_paper_pleb() {
        $qreq = TestQreq::post_json([
            "pid" => 1, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
    }

    function test_update_attack_paper_pleb() {
        $prow = $this->conf->checked_paper_by_id(2);
        xassert_eqq($this->u_puneet->can_view_paper($prow), false);
        $qreq = TestQreq::post_json([
            "pid" => 2, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->change_list, []);
        xassert_eqq($jr->message_list[0]->message, "<0>You aren’t allowed to view submission #2");

        $qreq = TestQreq::post_json([
            "pid" => 10000, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ], ["p" => "10000"]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->change_list, []);
        xassert_eqq($jr->message_list[0]->message, "<0>You aren’t allowed to view submission #10000");
    }

    function test_assigned_paper_id() {
        // Only chairs can assign papers with a specific ID
        $qreq = TestQreq::post_json([
            "pid" => 10000, "title" => "Scalable Timers for Soft State Protocols: György’s Version",
            "abstract" => "Hello", "authors" => [["name" => "My Name"]],
            "status" => "draft"
        ]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->change_list, []);
        xassert_eqq($jr->message_list[0]->message, "<0>Submission #10000 does not exist");

        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->pid, 10000);

        // Not possible to change ID
        $qreq->p = 1;
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
    }

    function test_dry_run() {
        $prow = $this->conf->checked_paper_by_id($this->npid);
        $original_title = $prow->title;
        $qreq = TestQreq::post(["dry_run" => 1, "title" => "New paper with changed ID", "p" => $prow->paperId]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $prow);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper ?? null, null);
        xassert_eqq($jr->change_list, ["title"]);

        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_eqq($prow->title, "New paper");

        // dry run does not create new paper
        $npapers = $this->conf->fetch_ivalue("select count(*) from Paper");
        $qreq = TestQreq::post(["p" => "new", "status:submit" => 1, "title" => "Goddamnit", "abstract" => "This is an abstract", "has_authors" => 1, "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper ?? null, null);
        xassert_eqq($npapers, $this->conf->fetch_ivalue("select count(*) from Paper"));
        $this->conf->id_randomizer()->cleanup();
    }

    function test_pid_mismatch() {
        $qreq = TestQreq::post_json(["title" => "Foo", "pid" => $this->npid + 1],
            ["p" => 1, "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $this->conf->checked_paper_by_id(1));
        xassert_eqq($jr->ok, false);
    }

    function test_decision() {
        $qreq = TestQreq::post_json(["decision" => "Rejected", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_lt($prow->outcome, 0);

        $qreq = TestQreq::post_json(["decision" => "Accepted", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_lt($prow->outcome, 0);

        $qreq = TestQreq::post_json(["decision" => "Accepted", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_gt($prow->outcome, 0);
    }

    function test_multiple() {
        $qreq = TestQreq::post_json([
            ["title" => "Fun with people", "pid" => 1],
            ["title" => "Fun with animals", "pid" => $this->npid]
        ]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count($jr->papers), 2);
        xassert_eqq($jr->papers[0]->pid, 1);
        xassert_eqq($jr->papers[0]->title, "Fun with people");
        xassert_eqq($jr->papers[1]->pid, $this->npid);
        xassert_eqq($jr->papers[1]->title, "Fun with animals");
        xassert_eqq($jr->status_list[0]->valid, true);
        xassert_eqq($jr->status_list[1]->valid, true);
        xassert_eqq($jr->status_list[0]->change_list, ["title"]);
        xassert_eqq($jr->status_list[1]->change_list, ["title"]);
        xassert_eqq($jr->status_list[0]->pid, 1);
        xassert_eqq($jr->status_list[1]->pid, $this->npid);
    }

    function test_if_unmodified_since_create() {
        $qreq = TestQreq::post_json(["pid" => 200, "title" => "Fart", "abstract" => "Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list[0], "pid");

        $qreq = TestQreq::post_json(["pid" => 201, "title" => "Fart Again", "abstract" => "Extra Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "status" => ["if_unmodified_since" => 0]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list[0], "pid");

        $qreq = TestQreq::post_json(["pid" => 201, "title" => "Fart", "abstract" => "Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "status" => ["if_unmodified_since" => 0]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
    }

    function test_get_sort() {
        $jr = call_api("papers", $this->u_chair, ["q" => "1-5 sort:title"]);
        xassert_eqq($jr->ok, true);
        $lastt = "";
        $ptotal = 0;
        $collator = $this->conf->collator();
        foreach ($jr->papers as $pj) {
            $ptotal += $pj->pid;
            xassert_lt($collator->compare($lastt, $pj->title), 0);
            $lastt = $pj->title;
        }
        xassert_eqq($ptotal, 15);

        $jr = call_api("papers", $this->u_chair, ["q" => "1-5", "sort" => "-title"]);
        xassert_eqq($jr->ok, true);
        $lastt = "ZZZZZZ";
        $ptotal = 0;
        $collator = $this->conf->collator();
        foreach ($jr->papers as $pj) {
            $ptotal += $pj->pid;
            xassert_gt($collator->compare($lastt, $pj->title), 0);
            $lastt = $pj->title;
        }
        xassert_eqq($ptotal, 15);
    }

    function test_match() {
        $qreq = TestQreq::post_json(["calories" => 10], ["q" => "1-10"]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        for ($i = 0; $i !== 10; ++$i) {
            xassert_eqq($jr->status_list[$i]->change_list, ["calories"]);
            xassert_eqq($jr->status_list[$i]->pid, $i + 1);
            xassert_eqq($jr->papers[$i]->pid, $i + 1);
            xassert_eqq($jr->papers[$i]->calories, 10);
        }

        $qreq = TestQreq::post_json(["calories" => 10, "pid" => 1], ["q" => "1-10"]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
    }

    function test_no_pid() {
        $qreq = TestQreq::post_json(["calories" => 9], ["p" => "1"]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["calories"]);
        xassert_eqq($jr->paper->pid, 1);
        xassert_eqq($jr->paper->calories, 9);
    }

    function test_delete() {
        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "Softer Timers for Scalable Protocols",
            "abstract" => "These timers are the softest yet!",
            "authors" => [["name" => "Shilpa Shamzi", "email" => $this->u_puneet->email]],
            "submission" => ["content" => "%PDF-2"],
            "status" => "draft"
        ], ["p" => "new"]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "Softer Timers for Scalable Protocols");
        $pid = $jr->paper->pid;
        $modified_at = $jr->paper->modified_at;

        $qreq = TestQreq::delete(["p" => $pid]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->status_code, 403);

        $qreq = TestQreq::delete(["p" => $pid, "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["delete"]);
        xassert_eqq($jr->valid, true);

        $qreq = TestQreq::delete(["p" => $pid, "dry_run" => 1, "if_unmodified_since" => $modified_at - 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->valid, false);

        $qreq = TestQreq::delete(["p" => $pid]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["delete"]);
        xassert_eqq($jr->valid, true);

        $qreq = TestQreq::delete(["p" => $pid, "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->status_code, 404);
    }

    function test_dryrun_users() {
        $u = $this->conf->fresh_user_by_email("vadhan@_.com");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));
        $u = $this->conf->fresh_user_by_email("vadhan2@_.com");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n",
            "authors" => [
                ["name" => "New User", "email" => "vadhan@_.com"],
                ["name" => "Second New User", "email" => "vadhan2@_.com", "contact" => true]
            ], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ], ["dry_run" => 1, "disable_users" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);

        $u = $this->conf->fresh_user_by_email("vadhan@_.com");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));
        $u = $this->conf->fresh_user_by_email("vadhan2@_.com");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n",
            "authors" => [
                ["name" => "New User", "email" => "vadhan@_.com"],
                ["name" => "Second New User", "email" => "vadhan2@_.com", "contact" => true]
            ], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ], ["disable_users" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_gt($jr->pid, 0);

        $u = $this->conf->fresh_user_by_email("vadhan@_.com");
        xassert(!!$u);
        xassert($u->is_placeholder());
        xassert($u->is_explicitly_disabled());
        $u = $this->conf->fresh_user_by_email("vadhan2@_.com");
        xassert(!!$u);
        xassert(!$u->is_placeholder());
        xassert($u->is_explicitly_disabled());
    }

    function test_dryrun_users_cdb() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }

        Dbl::qe($cdb, "insert into ContactInfo set firstName='Hello', lastName='Kitty', email='krist@toilet.edu', affiliation='University', password='', cflags=0");
        Dbl::qe($cdb, "insert into ContactInfo set firstName='Hello', lastName='Kitty', email='kassi@toilet.edu', affiliation='University', password='', cflags=0");
        Dbl::qe($cdb, "insert into ContactInfo set firstName='Hello', lastName='Kitty', email='tomie@toilet.edu', affiliation='University', password='', cflags=0");

        $u = $this->conf->fresh_user_by_email("krist@toilet.edu");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));
        $u = $this->conf->fresh_user_by_email("kassi@toilet.edu");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n",
            "authors" => [
                ["name" => "New User", "email" => "krist@toilet.edu"],
                ["name" => "Second New User", "email" => "kassi@toilet.edu", "contact" => true]
            ], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ], ["dry_run" => 1, "disable_users" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);

        $u = $this->conf->fresh_user_by_email("krist@toilet.edu");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));
        $u = $this->conf->fresh_user_by_email("kassi@toilet.edu");
        xassert(!$u || ($u->is_placeholder() && !$u->is_explicitly_disabled()));

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n",
            "authors" => [
                ["name" => "New User", "email" => "krist@toilet.edu"],
                ["name" => "Second New User", "email" => "kassi@toilet.edu", "contact" => true]
            ], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ], ["disable_users" => 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_gt($jr->pid, 0);

        $u = $this->conf->fresh_user_by_email("krist@toilet.edu");
        xassert(!!$u);
        xassert($u->is_explicitly_disabled());
        $u = $this->conf->fresh_user_by_email("kassi@toilet.edu");
        xassert(!!$u);
        xassert($u->is_explicitly_disabled());

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n",
            "authors" => [
                ["name" => "New User", "email" => "krist@toilet.edu"],
                ["name" => "Second New User", "email" => "kassi@toilet.edu", "contact" => true],
                ["name" => "Third New User", "email" => "tomie@toilet.edu"]
            ], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_gt($jr->pid, 0);

        $u = $this->conf->fresh_user_by_email("krist@toilet.edu");
        xassert(!!$u);
        xassert($u->is_explicitly_disabled());
        $u = $this->conf->fresh_user_by_email("kassi@toilet.edu");
        xassert(!!$u);
        xassert($u->is_explicitly_disabled());
        $u = $this->conf->fresh_user_by_email("tomie@toilet.edu");
        xassert(!!$u);
        xassert(!$u->is_placeholder());
        xassert(!$u->is_explicitly_disabled());
    }

    function test_new_paper_after_deadline() {
        $this->prevent_submission();

        $qreq = TestQreq::post(["p" => "new", "status:submit" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1])->set_file_content("submission:file", "%PDF-2", null, "application/pdf");
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "status:submitted");

        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n", "authors" => [["name" => "Bobby Flay", "email" => "flay@_.com"]], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "status:submitted");
    }

    function test_get_fail() {
        $qreq = TestQreq::get(["p" => 100101]);
        $jr = call_api("paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_str_contains($jr->message_list[0]->message, "does not exist");

        $qreq = TestQreq::get();
        $jr = call_api("paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "p");
        xassert_eqq($jr->message_list[0]->message, "<0>Parameter missing");
    }

    function test_document() {
        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 200);
        // this is the hash of `%PDF-whatever`
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");
        xassert_eqq($dl->header("Cache-Control"), null);

        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $dl = call_api_result("document", $this->u_micke, $qreq);
        xassert_eqq($dl->response_code(), 403);

        $qreq = TestQreq::get(["p" => 2, "dt" => 0]);
        $dl = call_api_result("document", $this->u_micke, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-7a501599e6d2a520603a6f13c868f0cdae4b4afdd9ce962d68b0cb437c045057\"");
        xassert_eqq($dl->header("Cache-Control"), null);
    }

    function test_document_docid() {
        $p1 = $this->conf->checked_paper_by_id(1);
        xassert($p1 && $p1->paperStorageId > 0);
        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "docid" => $p1->paperStorageId]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");
        xassert_not_str_contains($dl->header("Cache-Control"), "must-revalidate");

        // other user can't scan by docid :(
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert($p2 && $p2->paperStorageId > 0);
        xassert($p1->paperStorageId !== $p2->paperStorageId);
        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "docid" => $p2->paperStorageId]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 404);
    }

    function test_document_hash() {
        $this->allow_submission();

        // install a new PDF, then revert to the previous
        $qreq = TestQreq::post_zip([
            "data.json" => ["pid" => 1, "submission" => ["content_file" => "janspaper.pdf"], "status" => "submitted"],
            "janspaper.pdf" => "%PDF-whatever-1-2\n"
        ], ["p" => "1"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);

        $qreq = TestQreq::post_zip([
            "data.json" => ["pid" => 1, "submission" => ["content_file" => "janspaper.pdf"], "status" => "submitted"],
            "janspaper.pdf" => "%PDF-whatever"
        ], ["p" => "1"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);

        // current version has original checksum
        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");

        // author can fetch both
        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "hash" => "sha2-a06937feee4591ae4639312701b11e1125b321f7f3c8c6920962f20b38613882"]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-a06937feee4591ae4639312701b11e1125b321f7f3c8c6920962f20b38613882\"");
        xassert_eqq($dl->header("Content-Length"), "18");
        xassert_not_str_contains($dl->header("Cache-Control"), "must-revalidate");

        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "hash" => "sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f"]);
        $dl = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");
        xassert_eqq($dl->header("Content-Length"), "13");
        xassert_not_str_contains($dl->header("Cache-Control"), "must-revalidate");

        // other author cannot fetch
        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "hash" => "sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f"]);
        $dl = call_api_result("document", $this->u_micke, $qreq);
        xassert_eqq($dl->response_code(), 403);

        $qreq = TestQreq::get(["p" => 2, "dt" => 0, "hash" => "sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f"]);
        $dl = call_api_result("document", $this->u_micke, $qreq);
        xassert_eqq($dl->response_code(), 404);
    }

    function test_document_history() {
        // PC members cannot see document history
        $this->prevent_submission();

        $result = $this->conf->ql("select paperStorageId, sha1 from PaperStorage where paperId=1 and documentType=0");
        $map = [];
        while (($row = $result->fetch_row())) {
            $map[HashAnalysis::hash_as_text($row[1])] = (int) $row[0];
        }
        $result->close();

        $u_marina = $this->conf->checked_user_by_email("marina@poema.ru");
        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $dl = call_api_result("document", $u_marina, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");

        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "hash" => "sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f"]);
        $dl = call_api_result("document", $u_marina, $qreq);
        xassert_eqq($dl->response_code(), 200);
        xassert_eqq($dl->header("ETag"), "\"sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f\"");

        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "hash" => "sha2-a06937feee4591ae4639312701b11e1125b321f7f3c8c6920962f20b38613882"]);
        $dl = call_api_result("document", $u_marina, $qreq);
        xassert_eqq($dl->response_code(), 404);

        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "docid" => $map["sha2-a06937feee4591ae4639312701b11e1125b321f7f3c8c6920962f20b38613882"]]);
        $dl = call_api_result("document", $u_marina, $qreq);
        xassert_eqq($dl->response_code(), 404);

        $qreq = TestQreq::get(["p" => 1, "dt" => 0, "docid" => $map["sha2-e8f3545b84aa20fa534d2d0c95f7dce446df8fc0df9af32dae5396d223c1b16f"]]);
        $dl = call_api_result("document", $u_marina, $qreq);
        xassert_eqq($dl->response_code(), 200);
    }

    function test_api_scope() {
        $qreq = TestQreq::get(["p" => 1]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert_eqq($resp->get("paper")->pid, 1);

        $qreq = TestQreq::get(["p" => 2]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert_eqq($resp->get("paper")->pid, 2);

        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert($resp instanceof Downloader);

        $qreq = TestQreq::get(["p" => 2, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert($resp instanceof Downloader);

        $this->u_estrin->set_scope("paper:read#1");

        $qreq = TestQreq::get(["p" => 1]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert_eqq($resp->get("paper")->pid, 1);

        $qreq = TestQreq::get(["p" => 2]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 401);
        xassert_eqq($resp->get("paper"), null);
        Scope_Tester::xassert_scope_error($resp, "submeta:read");

        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert($resp instanceof Downloader);

        $qreq = TestQreq::get(["p" => 2, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        Scope_Tester::xassert_scope_error($resp, "submeta:read");

        $this->u_estrin->set_scope("submeta:read document:read#2");

        $qreq = TestQreq::get(["p" => 1]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert_eqq($resp->get("paper")->pid, 1);

        $qreq = TestQreq::get(["p" => 2]);
        $resp = call_api_result("paper", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert_eqq($resp->get("paper")->pid, 2);

        $qreq = TestQreq::get(["p" => 1, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 401);
        Scope_Tester::xassert_scope_error($resp, "document:read");

        $qreq = TestQreq::get(["p" => 2, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert($resp instanceof Downloader);

        $this->u_estrin->set_scope();
    }
}
