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
        $a = $this->conf->save_setting("sub_sub", $t);
        if ($a) {
            $this->conf->refresh_settings();
        }
        return $a;
    }

    /** Long text fields are served as a reader sees them. `word_limit=hard`
     * asks for what the page shows behind “Show more”, and stops at the hard
     * limit, past which HotCRP displays nothing to anyone. */
    function test_get_paper_word_limit() {
        $conf = $this->conf;
        $options = $conf->setting_json("options");
        $options[] = (object) ["id" => 6, "name" => "Notes", "type" => "text",
                               "order" => 9, "wordlimit" => 10, "hard_wordlimit" => 20];
        $conf->save_setting("options", 1, json_encode($options));
        $conf->invalidate_caches("options");

        $words = [];
        for ($i = 1; $i <= 30; ++$i) {
            $words[] = "w{$i}";
        }
        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => "new",
                "title" => "Word limit paper", "abstract" => "Abstract",
                "authors" => [["name" => "Puneet Sharma", "email" => "puneet@catarina.usc.edu"]],
                "Notes" => join(" ", $words), "status" => "draft"]));
        xassert_eqq($jr->ok, true, $jr->message_list[0]->message ?? "");
        $pid = $jr->paper->pid;

        $get = function ($args) use ($pid) {
            return call_api("paper", $this->u_estrin, TestQreq::get(["p" => $pid] + $args));
        };

        // by default the field is cut at its word limit, and says so
        $jr = $get([]);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count_words($jr->paper->Notes ?? ""), 10);
        xassert_eqq(($jr->paper->overlong ?? [])["Notes"] ?? null, true);
        xassert_eqq(($jr->paper->truncated ?? [])["Notes"] ?? null, "soft");

        // `hard` is the “Show more” view, and stops at the hard limit
        $jr = $get(["word_limit" => "hard"]);
        xassert_eqq($jr->ok, true);
        xassert_eqq(count_words($jr->paper->Notes ?? ""), 20);
        xassert_eqq(($jr->paper->overlong ?? [])["Notes"] ?? null, true);
        xassert_eqq(($jr->paper->truncated ?? [])["Notes"] ?? null, "hard");
        xassert(!str_contains($jr->paper->Notes ?? "", "w21"));

        // `soft` is the default spelled out
        $jr = $get(["word_limit" => "soft"]);
        xassert_eqq(count_words($jr->paper->Notes ?? ""), 10);

        // a field under its limit says nothing at all
        xassert_eqq(($jr->paper->overlong ?? [])["title"] ?? null, null);
        xassert_eqq(($jr->paper->truncated ?? [])["title"] ?? null, null);

        // an unknown value is refused rather than silently defaulted
        $jr = $get(["word_limit" => "none"]);
        xassert_eqq($jr->ok, false);
        xassert_str_contains($jr->message_list[0]->field ?? "", "word_limit");

        // and the multi-paper endpoint takes it too
        $jr = call_api("papers", $this->u_estrin,
            TestQreq::get(["q" => "Word limit paper", "word_limit" => "hard"]));
        xassert_eqq($jr->ok, true);
        $found = false;
        foreach ($jr->papers ?? [] as $pj) {
            if ($pj->pid === $pid) {
                $found = true;
                xassert_eqq(count_words($pj->Notes ?? ""), 20);
                xassert_eqq(($pj->truncated ?? [])["Notes"] ?? null, "hard");
            }
        }
        xassert($found);

        TestRunner::reset_options();
        $conf->invalidate_caches("options", "paper");
    }

    /** A field the caller may see but not write is no longer dropped in
     * silence: the API reports it, attributed to the field, and writes nothing
     * for it. A browser posts every field it rendered, including read-only
     * ones, so the form path keeps ignoring them — that is what
     * `set_ignore_unwritable_fields` selects. */
    function test_save_unwritable_field_is_reported() {
        $conf = $this->conf;
        $options = $conf->setting_json("options");
        $options[] = (object) ["id" => 5, "name" => "Locked", "type" => "numeric",
                               "order" => 8, "editable_if" => "NONE"];
        $conf->save_setting("options", 1, json_encode($options));
        $conf->invalidate_caches("options");

        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => "new",
                "title" => "Unwritable field paper", "abstract" => "Abstract",
                "authors" => [["name" => "Puneet Sharma", "email" => "puneet@catarina.usc.edu"]],
                "status" => "draft"]));
        xassert_eqq($jr->ok, true);
        $pid = $jr->paper->pid;

        // an administrator may write it, so there is a value to leave alone
        $jr = call_api("=paper", $this->u_chair,
            TestQreq::post_json((object) ["object" => "paper", "pid" => $pid, "Locked" => 5]));
        xassert_eqq([$jr->ok, $jr->paper->Locked ?? null], [true, 5]);

        $locked = function () use ($conf, $pid) {
            $conf->invalidate_caches("paper");
            $ov = $conf->checked_paper_by_id($pid)->option(5);
            return $ov ? $ov->value : null;
        };
        $modify_message = function ($jr) {
            foreach ($jr->message_list ?? [] as $mi) {
                if (str_contains($mi->message ?? "", "allowed to edit"))
                    return $mi;
            }
            return null;
        };

        // the author may not write it, and is told which field rather than
        // left to infer it from an absence
        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => $pid, "Locked" => 6]));
        $mi = $modify_message($jr);
        xassert_neqq($mi, null);
        if ($mi) {
            // the catalog's `field:edit` wording, so it names the field and
            // takes the conference's word for a submission
            xassert_eqq($mi->message, "<0>You aren’t allowed to edit the Locked field for submission #{$pid}");
            xassert_eqq($mi->field, "Locked");
            xassert_eqq($mi->status, MessageSet::ESTOP);
        }
        xassert_eqq($jr->change_list, []);
        xassert_eqq($locked(), 5);

        // echoing the field back unchanged is what a round-trip does, so it
        // says nothing: a client that GETs a paper and PUTs it whole is not
        // scolded for every field it may not edit
        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => $pid,
                                          "title" => "Renamed", "Locked" => 5]));
        xassert_eqq($jr->ok, true);
        xassert_eqq($modify_message($jr), null);
        xassert_eqq($conf->checked_paper_by_id($pid)->title, "Renamed");

        // the refusal stops the whole request: a save that cannot do what was
        // asked does none of it, rather than leaving the caller to work out
        // which half landed
        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => $pid,
                                          "title" => "Renamed again", "Locked" => 6]));
        xassert_neqq($modify_message($jr), null);
        xassert_eqq($jr->ok, false);
        xassert_eqq($conf->checked_paper_by_id($pid)->title, "Renamed");
        xassert_eqq($locked(), 5);

        // a caller that means to post a whole paper it only partly owns asks
        // for the lenient reading, and gets the form path's behavior
        $jr = call_api("=paper", $this->u_estrin,
            TestQreq::post_json((object) ["object" => "paper", "pid" => $pid,
                                          "title" => "Renamed again", "Locked" => 6],
                                ["ignore_unwritable_fields" => 1]));
        xassert_eqq($jr->ok, true);
        xassert_eqq($modify_message($jr), null);
        xassert_eqq($conf->checked_paper_by_id($pid)->title, "Renamed again");
        xassert_eqq($locked(), 5);

        // the form path still drops it quietly, which is what lets a browser
        // post the fields it rendered read-only
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->save_paper_json(json_decode("{\"pid\":{$pid},\"Locked\":7}")));
        foreach ($ps->message_list() as $mi) {
            xassert(!str_contains($mi->message, "allowed to edit"));
        }
        xassert_eqq($locked(), 5);

        TestRunner::reset_options();
        $conf->invalidate_caches("options", "paper");
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

    function test_update_paper_json_param_no_body() {
        // `json` supplied as a bare parameter with no request body: the null
        // content type must not crash `is_form()`, and the JSON still defines
        // the modification (previously `json` required a form content type)
        $qreq = (new Qrequest("POST", [
            "json" => json_encode(["pid" => 1, "title" => "Scalable Timers, No-Body Edition"])
        ]))->approve_token();
        xassert_eqq($qreq->body_content_type(), null);
        $jr = call_api("paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "Scalable Timers, No-Body Edition");
    }

    function test_post_json_and_upload_conflict() {
        // `json` and `upload` are alternative payload selectors; supplying both
        // is an error (the upload token need not even resolve)
        $qreq = TestQreq::post([
            "json" => json_encode(["pid" => 1, "title" => "Should Not Apply"]),
            "upload" => "hct_nonexistent"
        ]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_str_contains($jr->message_list[0]->message, "at most one of `json` and `upload`");
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

    function test_dry_run_conditional() {
        $prow = $this->conf->checked_paper_by_id($this->npid);
        $title = $prow->title;
        // a paper save is forgiving: a bad field is reported and left alone
        // while the rest is saved, so `if_error` withholds the whole save
        $qreq = TestQreq::post_json((object) ["object" => "paper", "pid" => $prow->paperId,
            "title" => "Conditional dry run", "submission" => (object) ["content_base64" => "!!"]],
            ["dry_run" => "if_error"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $prow);
        xassert_eqq($jr->dry_run, true);
        xassert_eqq($jr->paper ?? null, null);
        $this->conf->invalidate_caches("paper");
        xassert_eqq($this->conf->checked_paper_by_id($prow->paperId)->title, $title);

        // with no errors, `if_error` commits
        $qreq = TestQreq::post_json((object) ["object" => "paper", "pid" => $prow->paperId,
            "title" => "Conditional dry run"], ["dry_run" => "if_error"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $prow);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->dry_run ?? null, null);
        xassert_eqq($jr->change_list, ["title"]);
        $this->conf->invalidate_caches("paper");
        xassert_eqq($this->conf->checked_paper_by_id($prow->paperId)->title, "Conditional dry run");

        // an unrecognized mode is a parameter error, not a silent live save
        $qreq = TestQreq::post_json((object) ["object" => "paper", "pid" => $prow->paperId,
            "title" => "Should not be saved"], ["dry_run" => "bogus"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $prow);
        xassert_eqq($jr->ok, false);
        $this->conf->invalidate_caches("paper");
        xassert_eqq($this->conf->checked_paper_by_id($prow->paperId)->title, "Conditional dry run");

        // restore
        $qreq = TestQreq::post_json((object) ["object" => "paper", "pid" => $prow->paperId,
            "title" => $title]);
        call_api("=paper", $this->u_estrin, $qreq, $prow);
        $this->conf->invalidate_caches("paper");
    }

    function test_pid_mismatch() {
        $qreq = TestQreq::post_json(["title" => "Foo", "pid" => $this->npid + 1],
            ["p" => 1, "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $this->conf->checked_paper_by_id(1));
        xassert_eqq($jr->ok, false);
    }

    function test_object_type_mismatch() {
        // a JSON whose `object` is not `paper` is rejected
        $qreq = TestQreq::post_json(["object" => "comment", "title" => "Foo"]);
        $jr = call_api_result("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->content["ok"], false);
        xassert_eqq($jr->message_item(0)->field, "object");
        xassert_match($jr->message_item(0)->message, '/Object type mismatch/');
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
        xassert_eqq($jr->change_list[0], "new");

        $qreq = TestQreq::post_json(["pid" => 201, "title" => "Fart Again", "abstract" => "Extra Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "if_unmodified_since" => 0]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list[0], "new");

        $qreq = TestQreq::post_json(["pid" => 201, "title" => "Fart", "abstract" => "Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "status" => ["if_unmodified_since" => 0]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->valid, false);
        xassert_eqq($jr->conflict, true);
    }

    // The flat `if_unmodified_since` parameter is an alias for the JSON
    // `if_unmodified_since/status.if_unmodified_since` field, and edit
    // conflicts report `conflict`.
    function test_if_unmodified_since_param() {
        $qreq = TestQreq::post_json(["pid" => 202, "title" => "IUS", "abstract" => "A", "authors" => [["name" => "Ann Ug", "email" => "ann@_.com"]]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        $mod = (int) $this->conf->fetch_ivalue("select timeModified from Paper where paperId=202");
        xassert($mod > 0);

        // a stale flat precondition is an edit conflict
        $qreq = TestQreq::post_json(["pid" => 202, "title" => "IUS 2"], ["if_unmodified_since" => $mod - 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->valid, false);
        xassert_eqq($jr->conflict, true);

        // `if_unmodified_since=0` also conflicts on an existing submission
        $qreq = TestQreq::post_json(["pid" => 202, "title" => "IUS 3"], ["if_unmodified_since" => 0]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->conflict, true);

        // the flat parameter works for a form-encoded POST too
        $qreq = TestQreq::post(["p" => 202, "title" => "IUS form", "if_unmodified_since" => $mod - 1]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->conflict, true);

        // a current precondition allows the edit
        $qreq = TestQreq::post_json(["pid" => 202, "title" => "IUS 4"], ["if_unmodified_since" => $mod]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert(!isset($jr->conflict));
    }

    // For a multi-submission request the flat `if_unmodified_since` is a
    // per-paper backup, overridable by each paper's `status.if_unmodified_since`.
    // Conflicts are reported per item in `status_list` (absent or true); there
    // is no top-level aggregate.
    function test_if_unmodified_since_multi() {
        $qreq = TestQreq::post_json([
            ["pid" => 210, "title" => "M1", "abstract" => "A", "authors" => [["name" => "Al Fa", "email" => "alfa@_.com"]]],
            ["pid" => 211, "title" => "M2", "abstract" => "A", "authors" => [["name" => "Be Ta", "email" => "beta@_.com"]]]
        ]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert(!isset($jr->conflict));
        xassert_eqq($jr->status_list[0]->valid, true);
        xassert_eqq($jr->status_list[0]->conflict ?? false, false);
        xassert_eqq($jr->status_list[1]->valid, true);
        $mod210 = (int) $this->conf->fetch_ivalue("select timeModified from Paper where paperId=210");
        xassert($mod210 > 0);

        // flat `if_unmodified_since=0` conflicts every paper as a per-paper backup
        $qreq = TestQreq::post_json([
            ["pid" => 210, "title" => "M1x"],
            ["pid" => 211, "title" => "M2x"]
        ], ["if_unmodified_since" => 0]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
        xassert(!isset($jr->conflict));
        xassert_eqq($jr->status_list[0]->valid, false);
        xassert_eqq($jr->status_list[0]->conflict, true);
        xassert_eqq($jr->status_list[1]->valid, false);
        xassert_eqq($jr->status_list[1]->conflict, true);

        // a per-paper `if_unmodified_since` overrides the flat backup
        $qreq = TestQreq::post_json([
            ["pid" => 210, "title" => "M1y", "if_unmodified_since" => $mod210],
            ["pid" => 211, "title" => "M2y"]
        ], ["if_unmodified_since" => 0]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->status_list[0]->valid, true);
        xassert_eqq($jr->status_list[0]->conflict ?? false, false);
        xassert_eqq($jr->status_list[1]->valid, false);
        xassert_eqq($jr->status_list[1]->conflict, true);
    }

    function test_get_download_json() {
        // `download=1` trades the response envelope for the bare payload that
        // `POST /paper` accepts
        $jr = call_api_result("paper", $this->u_chair, TestQreq::get(["p" => 1, "download" => 1]));
        xassert($jr instanceof JsonResult);
        xassert($jr->minimal);
        xassert_str_contains($jr->header("Content-Disposition"),
            $this->conf->download_prefix . "paper1.json");
        $pj = (object) $jr->content;
        xassert_eqq($pj->object, "paper");
        xassert_eqq($pj->pid, 1);
        xassert(!isset($pj->ok));

        // `/papers` downloads the bare array `POST /papers` accepts
        $jr = call_api_result("papers", $this->u_chair, TestQreq::get(["q" => "1-3", "download" => 1]));
        xassert($jr instanceof JsonResult);
        xassert($jr->minimal);
        xassert_str_contains($jr->header("Content-Disposition"),
            $this->conf->download_prefix . "papers.json");
        xassert(is_list($jr->content));
        xassert_eqq(count($jr->content), 3);
        xassert_eqq($jr->content[0]->object, "paper");
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
            xassert_eqq($jr->status_list[$i]->change_list, ["Calories"]);
            xassert_eqq($jr->status_list[$i]->pid, $i + 1);
            xassert_eqq($jr->papers[$i]->pid, $i + 1);
            xassert_eqq($jr->papers[$i]->Calories, 10);
        }

        $qreq = TestQreq::post_json(["calories" => 10, "pid" => 1], ["q" => "1-10"]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
    }

    function test_no_pid() {
        $qreq = TestQreq::post_json(["calories" => 9], ["p" => "1"]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list, ["Calories"]);
        xassert_eqq($jr->paper->pid, 1);
        xassert_eqq($jr->paper->Calories, 9);
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

    function test_get() {
        $qreq = TestQreq::get(["p" => 3]);
        $jr = call_api("paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->pid, 3);
    }

    function test_get_fail() {
        // unknown pid
        $qreq = TestQreq::get(["p" => 1093]);
        $jr = call_api("paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_str_contains($jr->message_list[0]->message, "does not exist");

        // absent `p`
        $qreq = TestQreq::get();
        $jr = call_api("paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "p");
        xassert_eqq($jr->message_list[0]->message, "<0>Parameter missing");

        // broken `p`
        $qreq = TestQreq::get(["p" => "xxx"]);
        $jr = call_api("paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_str_contains($jr->message_list[0]->message, "Invalid");
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
        xassert_eqq($resp->response_code(), 403);
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
        xassert_eqq($resp->response_code(), 403);
        Scope_Tester::xassert_scope_error($resp, "document:read");

        $qreq = TestQreq::get(["p" => 2, "dt" => 0]);
        $resp = call_api_result("document", $this->u_estrin, $qreq);
        xassert_eqq($resp->response_code(), 200);
        xassert($resp instanceof Downloader);

        $this->u_estrin->set_scope();
    }

    // An API response’s real HTTP status is surfaced only for non-cross-site
    // requests; otherwise it’s 200 + status_code to avoid an XS-Leak oracle.
    // See JsonResult::emit and Qrequest::same_origin.
    function test_emit_status_disclosure() {
        $save_code = Navigation::$http_response_code;
        // emit a 404 through $qreq, return [wire status, decoded body]
        $emit = function ($qreq) {
            Navigation::$http_response_code = 200;
            ob_start();
            JsonResult::make_error(404, "<0>Function not found")->emit($qreq);
            return [Navigation::$http_response_code, json_decode(ob_get_clean())];
        };

        // cross-site no-cors element load: status veiled as 200 + status_code
        $qreq = TestQreq::get()->set_user($this->user);
        $qreq->set_header("Sec-Fetch-Site", "cross-site");
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 200);
        xassert_eqq($body->ok, false);
        xassert_eqq($body->status_code, 404);

        // no Sec-Fetch-Site but a cross-origin Origin: inferred cross-site, veiled
        $qreq = TestQreq::get()->set_user($this->user);
        $qreq->set_header("Origin", "https://evil.example");
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 200);
        xassert_eqq($body->status_code, 404);

        // same-origin request: real 404 on the wire, no status_code veil
        $qreq = TestQreq::get()->set_user($this->user);
        $qreq->set_header("Sec-Fetch-Site", "same-origin");
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 404);
        xassert_eqq($body->status_code ?? null, null);

        // direct navigation (Sec-Fetch-Site: none): real 404
        $qreq = TestQreq::get()->set_user($this->user);
        $qreq->set_header("Sec-Fetch-Site", "none");
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 404);

        // no fetch-metadata and no Origin (non-browser client): treated same-origin
        $qreq = TestQreq::get()->set_user($this->user);
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 404);

        // authorized request (valid CSRF/bearer token) is surfaced even cross-site
        $qreq = TestQreq::post()->set_user($this->user);
        $qreq->set_header("Sec-Fetch-Site", "cross-site");
        list($code, $body) = $emit($qreq);
        xassert_eqq($code, 404);

        Navigation::$http_response_code = $save_code;
    }

    // `notify=off` is honored for submission administrators (not only site
    // chairs), decided per paper via can_manage()
    function test_paper_notify_off() {
        // create a submitted paper with two real (enabled) contact authors
        $qreq = TestQreq::post_json([
            "pid" => "new", "title" => "Notify Test Paper",
            "abstract" => "First abstract",
            "authors" => [
                ["name" => "Puneet Sharma", "email" => $this->u_puneet->email],
                ["name" => "Mikael Degermark", "email" => $this->u_micke->email]
            ],
            "submission" => ["content" => "%PDF-2"],
            "status" => "submitted"
        ], ["p" => "new"]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert($jr->ok);
        $pid = $jr->paper->pid;

        // make estrin (PC, not a site chair) the paper's administrator
        xassert_assign($this->u_chair, "action,paper,user\nadministrator,{$pid},{$this->u_estrin->email}\n");
        $prow = $this->conf->checked_paper_by_id($pid);
        xassert(!$this->u_estrin->privChair);
        xassert($this->u_estrin->can_manage($prow));

        // baseline: an administrator's edit notifies the contact authors
        MailChecker::clear();
        $qreq = TestQreq::post_json(["pid" => $pid, "abstract" => "Second abstract"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert($jr->ok);
        xassert_gt(count(MailChecker::$preps), 0);

        // a non-chair submission administrator may suppress with notify=off
        MailChecker::clear();
        $qreq = TestQreq::post_json(["pid" => $pid, "abstract" => "Third abstract"], ["notify" => "off"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert($jr->ok);
        MailChecker::check0();

        // ... but an ordinary edit still notifies
        MailChecker::clear();
        $qreq = TestQreq::post_json(["pid" => $pid, "abstract" => "Fourth abstract"]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert($jr->ok);
        xassert_gt(count(MailChecker::$preps), 0);

        // clean up the administrator assignment
        xassert_assign($this->u_chair, "action,paper,user\nadministrator,{$pid},none\n");
        MailChecker::clear();
    }

    function test_contacts_no_privilege_escalation() {
        // Regression: a non-author, non-manager user must not be able to make
        // themselves a contact-author of someone else's paper via POST /api/paper.
        // A contacts-only (or even no-op) save skipped the edit-permission gate
        // and `_check_contacts_last` auto-added the saving user as a contact-
        // author — granting paper edit and author-view of reviews on a paper
        // they don't own, even past the submission deadline.
        $conf = $this->conf;
        $pid = 2;
        $attacker = $this->u_estrin; // PC, not author/contact/manager of paper 2
        $prow = $conf->checked_paper_by_id($pid);
        xassert($attacker->isPC);
        xassert(!$attacker->can_manage($prow));
        xassert(!$prow->has_author($attacker));
        xassert(!$attacker->can_edit_paper($prow));
        xassert_eqq($prow->conflict_type($attacker) & CONFLICT_CONTACTAUTHOR, 0);
        // a legitimate contact exists and must survive
        xassert(($prow->conflict_type($this->u_micke) & CONFLICT_CONTACTAUTHOR) !== 0);

        // (a) a no-op save must not escalate the saver to contact-author
        call_api_result("=paper", $attacker, TestQreq::post_json(["pid" => $pid], ["p" => $pid]));
        $prow = $conf->checked_paper_by_id($pid);
        xassert_eqq($prow->conflict_type($attacker) & CONFLICT_CONTACTAUTHOR, 0);
        xassert(!$attacker->can_edit_paper($prow));

        // (b) explicitly naming self as a contact must also be refused
        call_api_result("=paper", $attacker,
            TestQreq::post_json(["pid" => $pid, "contacts" => [$attacker->email => true]], ["p" => $pid]));
        $prow = $conf->checked_paper_by_id($pid);
        xassert_eqq($prow->conflict_type($attacker) & CONFLICT_CONTACTAUTHOR, 0);
        xassert(!$attacker->can_edit_paper($prow));

        // (c) the legitimate contact must be untouched by the unauthorized saves
        xassert(($prow->conflict_type($this->u_micke) & CONFLICT_CONTACTAUTHOR) !== 0);
    }
}
