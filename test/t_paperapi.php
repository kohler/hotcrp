<?php
// t_paperapi.php -- HotCRP tests
// Copyright (c) 2024 Eddie Kohler; see LICENSE.

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
        $this->u_puneet = $conf->checked_user_by_email("puneet@catarina.usc.edu");
    }

    /** @param array<string,mixed> $args
     * @return Qrequest */
    function make_post_form_qreq($args) {
        return (new Qrequest("POST", $args))
            ->approve_token()
            ->set_body(null, "application/x-www-form-urlencoded");
    }

    /** @param mixed $body
     * @param array<string,mixed> $args
     * @return Qrequest */
    function make_post_json_qreq($body, $args = []) {
        return (new Qrequest("POST", $args))
            ->approve_token()
            ->set_body(json_encode_db($body), "application/json");
    }

    /** @param array<string,mixed> $contents
     * @param array<string,mixed> $args
     * @return Qrequest */
    function make_post_zip_qreq($contents, $args = []) {
        if (($fn = tempnam("/tmp", "hctz")) === false) {
            throw new ErrorException("Failed to create temporary file");
        }
        unlink($fn);
        $zip = new ZipArchive;
        $zip->open($fn, ZipArchive::CREATE);
        foreach ($contents as $name => $value) {
            $zip->addFromString($name, is_string($value) ? $value : json_encode_db($value));
        }
        $zip->close();
        $qreq = (new Qrequest("POST", $args))
            ->approve_token()
            ->set_body(file_get_contents($fn), "application/zip");
        unlink($fn);
        return $qreq;
    }

    function test_save_submit_new_paper() {
        $qreq = $this->make_post_form_qreq(["p" => "new", "status:submit" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1])->set_file_content("submission", "%PDF-2", null, "application/pdf");
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
        xassert_eqq($jr->paper->title, "New paper");
        xassert_eqq($jr->paper->abstract, "This is an abstract");
        $this->npid = $jr->paper->pid;
    }

    function test_save_submit_new_paper_zip() {
        $qreq = $this->make_post_zip_qreq([
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
        $qreq = $this->make_post_json_qreq([
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
        $qreq = $this->make_post_json_qreq([
            "pid" => 1, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper->object, "paper");
    }

    function test_update_attack_paper_pleb() {
        $prow = $this->conf->checked_paper_by_id(2);
        xassert_eqq($this->u_puneet->can_view_paper($prow), false);
        $qreq = $this->make_post_json_qreq([
            "pid" => 2, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->change_list, []);
        xassert_eqq($jr->message_list[0]->message, "<0>You aren’t allowed to view submission #2");

        $qreq = $this->make_post_json_qreq([
            "pid" => 10000, "title" => "Scalable Timers for Soft State Protocols: Taylor’s Version"
        ], ["p" => "10000"]);
        $jr = call_api("=paper", $this->u_puneet, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->change_list, []);
        xassert_eqq($jr->message_list[0]->message, "<0>You aren’t allowed to view submission #10000");
    }

    function test_assigned_paper_id() {
        // Only chairs can assign papers with a specific ID
        $qreq = $this->make_post_json_qreq([
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
        $qreq = $this->make_post_form_qreq(["dry_run" => 1, "title" => "New paper with changed ID", "p" => $prow->paperId]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $prow);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper ?? null, null);
        xassert_eqq($jr->change_list, ["title"]);

        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_eqq($prow->title, "New paper");

        // dry run does not create new paper
        $npapers = $this->conf->fetch_ivalue("select count(*) from Paper");
        $qreq = $this->make_post_form_qreq(["p" => "new", "status:submit" => 1, "title" => "Goddamnit", "abstract" => "This is an abstract", "has_authors" => 1, "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->paper ?? null, null);
        xassert_eqq($npapers, $this->conf->fetch_ivalue("select count(*) from Paper"));
        $this->conf->id_randomizer()->cleanup();
    }

    function test_pid_mismatch() {
        $qreq = $this->make_post_json_qreq(["title" => "Foo", "pid" => $this->npid + 1], ["p" => 1, "dry_run" => 1]);
        $jr = call_api("=paper", $this->u_estrin, $qreq, $this->conf->checked_paper_by_id(1));
        xassert_eqq($jr->ok, false);
    }

    function test_decision() {
        $qreq = $this->make_post_json_qreq(["decision" => "Rejected", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_lt($prow->outcome, 0);

        $qreq = $this->make_post_json_qreq(["decision" => "Accepted", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_lt($prow->outcome, 0);

        $qreq = $this->make_post_json_qreq(["decision" => "Accepted", "pid" => $this->npid]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        $prow = $this->conf->checked_paper_by_id($this->npid);
        xassert_gt($prow->outcome, 0);
    }

    function test_multiple() {
        $qreq = $this->make_post_json_qreq([
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
        xassert_eqq($jr->valid, [true, true]);
        xassert_eqq($jr->change_lists, [["title"], ["title"]]);
    }

    function test_if_unmodified_since_create() {
        $qreq = $this->make_post_json_qreq(["pid" => 200, "title" => "Fart", "abstract" => "Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list[0], "pid");

        $qreq = $this->make_post_json_qreq(["pid" => 201, "title" => "Fart Again", "abstract" => "Extra Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "status" => ["if_unmodified_since" => 0]]);
        $jr = call_api("=paper", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->change_list[0], "pid");

        $qreq = $this->make_post_json_qreq(["pid" => 201, "title" => "Fart", "abstract" => "Fart", "authors" => [["name" => "Dan Bisers", "email" => "farterchild@example.net"]], "status" => ["if_unmodified_since" => 0]]);
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
        $qreq = $this->make_post_json_qreq(["calories" => 10], ["q" => "1-10"]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, true);
        for ($i = 0; $i !== 10; ++$i) {
            xassert_eqq($jr->change_lists[$i], ["calories"]);
            xassert_eqq($jr->papers[$i]->pid, $i + 1);
            xassert_eqq($jr->papers[$i]->calories, 10);
        }

        $qreq = $this->make_post_json_qreq(["calories" => 10, "pid" => 1], ["q" => "1-10"]);
        $jr = call_api("=papers", $this->u_chair, $qreq);
        xassert_eqq($jr->ok, false);
    }

    function test_new_paper_after_deadline() {
        $this->conf->save_setting("sub_update", Conf::$now - 10);
        $this->conf->save_setting("sub_sub", Conf::$now - 10);
        $this->conf->refresh_settings();

        $qreq = $this->make_post_form_qreq(["p" => "new", "status:submit" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1])->set_file_content("submission", "%PDF-2", null, "application/pdf");
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "status:submitted");

        $qreq = $this->make_post_json_qreq([
            "pid" => "new", "title" => "New paper", "abstract" => "This is an abstract\r\n", "authors" => [["name" => "Bobby Flay", "email" => "flay@_.com"]], "submission" => ["content" => "%PDF-2"], "status" => "draft"
        ]);
        $jr = call_api("=paper", $this->u_estrin, $qreq);
        xassert_eqq($jr->ok, false);
        xassert_eqq($jr->message_list[0]->field, "status:submitted");
    }
}
