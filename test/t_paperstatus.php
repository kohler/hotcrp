<?php
// t_paperstatus.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperStatus_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_estrin;
    /** @var Contact
     * @readonly */
    public $u_varghese;
    /** @var Contact
     * @readonly */
    public $u_sally;
    /** @var Contact
     * @readonly */
    public $u_nobody;
    /** @var Contact */
    public $u_atten;

    /** @var object */
    private $paper1a;
    /** @var PaperInfo */
    private $newpaper1;
    /** @var int */
    private $pid2;
    /** @var int */
    private $docid1;
    /** @var int */
    private $docid2;
    /** @var int */
    private $festrin_cid;
    /** @var int */
    private $gestrin_cid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $conf->save_setting("sub_open", 1);
        $conf->save_setting("sub_update", Conf::$now + 100);
        $conf->save_setting("sub_sub", Conf::$now + 100);
        $conf->save_setting("opt.contentHashMethod", 1, "sha1");
        $conf->save_setting("rev_open", 1);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu"); // pc
        $this->u_varghese = $conf->checked_user_by_email("varghese@ccrc.wustl.edu"); // pc red
        $this->u_sally = $conf->checked_user_by_email("floyd@ee.lbl.gov"); // pc red blue
        $this->u_nobody = Contact::make($conf);
    }

    function test_paper_save_claims_contact() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->conflict_type($this->u_estrin), CONFLICT_AUTHOR);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $this->paper1a = $ps->paper_json(1);
        xassert_eqq($this->paper1a->title, "Scalable Timers for Soft State Protocols");
        $paper1a_pcc = $this->paper1a->pc_conflicts;
        '@phan-var-force object $paper1a_pcc';
        xassert_eqq($paper1a_pcc->{"estrin@usc.edu"}, "author");

        $ps->save_paper_json((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
        xassert_paper_status($ps);
        $paper1->invalidate_conflicts();
        xassert_eqq($paper1->conflict_type($this->u_estrin), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
    }

    function test_paper_json_identity() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $paper1b = $ps->paper_json(1);
        xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
        $paper1b->title = $this->paper1a->title;
        $paper1b->submitted_at = $this->paper1a->submitted_at;
        $s1 = json_encode($this->paper1a);
        $s2 = json_encode($paper1b);
        xassert_eqq($s1, $s2);
        if ($s1 !== $s2) {
            while (substr($s1, 0, 30) === substr($s2, 0, 30)) {
                $s1 = substr($s1, 10);
                $s2 = substr($s2, 10);
            }
            error_log("   > $s1\n   > $s2");
        }
    }

    function test_paper_save_document() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $doc = DocumentInfo::make_uploaded_file([
                "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
                "tmp_name" => SiteLoader::find("etc/sample.pdf"),
                "type" => "application/pdf"
            ], -1, DTYPE_SUBMISSION, $this->conf);
        xassert_eqq($doc->content_text_signature(), "starts with “%PDF-1.2”");
        $ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
        xassert_paper_status($ps);
        $paper1c = $ps->paper_json(1);
        xassert_eqq($paper1c->submission->hash, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");
    }

    function test_paper_replace_document() {
        $ps = new PaperStatus($this->conf);
        $paper2a = $ps->paper_json(2);
        $ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2b = $ps->paper_json(2);
        xassert_eqq($paper2b->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");

        $ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"%PDF-goodbye\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $ps->paper_json(2);
        xassert_eqq($paper2->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");
        xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");

        $ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-again hello\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $ps->paper_json(2);
        xassert_eqq($paper2->submission->hash, "30240fac8417b80709c72156b7f7f7ad95b34a2b");
        xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");
        $paper2 = $this->u_estrin->checked_paper_by_id(2);
        xassert_eqq(bin2hex($paper2->sha1), "e04c778a0af702582bb0e9345fab6540acb28e45");
    }

    function test_document_options_storage() {
        $options = $this->conf->setting_json("options");
        xassert(!array_filter((array) $options, function ($o) { return $o->id === 2; }));
        $options[] = (object) ["id" => 2, "name" => "Attachments", "abbr" => "attachments", "type" => "attachments", "order" => 2];
        $this->conf->save_setting("options", 1, json_encode($options));
        $this->conf->invalidate_caches(["options" => true]);

        $ps = new PaperStatus($this->conf);
        $ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}]}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $this->u_estrin->checked_paper_by_id(2);
        $docs = $paper2->option(2)->documents();
        xassert_eqq(count($docs), 2);
        xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
        $this->docid1 = $docs[0]->paperStorageId;
        xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
        $this->docid2 = $docs[1]->paperStorageId;

        $ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"sha1\": \"4c18e2ec1d1e6d9e53f57499a66aeb691d687370\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}]}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $this->u_estrin->checked_paper_by_id(2);
        $docs = $paper2->option(2)->documents();
        xassert_eqq(count($docs), 3);
        xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
        xassert_eqq($docs[0]->paperStorageId, $this->docid1);
        xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
        xassert_eqq($docs[1]->paperStorageId, $this->docid2);
        xassert($docs[2]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
        xassert_eqq($docs[2]->paperStorageId, $this->docid2);
    }

    function test_old_document_options_storage() {
        $this->conf->qe("delete from PaperOption where paperId=2 and optionId=2");
        $this->conf->qe("insert into PaperOption (paperId,optionId,value,data) values (2,2,{$this->docid1},'0'),(2,2,{$this->docid2},'1')");
        $paper2 = $this->u_estrin->checked_paper_by_id(2);
        $docs = $paper2->option(2)->documents();
        xassert_eqq(count($docs), 2);
        xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
        xassert_eqq($docs[0]->paperStorageId, $this->docid1);
        xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
        xassert_eqq($docs[1]->paperStorageId, $this->docid2);

        // new-style JSON representation
        $ps = new PaperStatus($this->conf);
        $ps->save_paper_json(json_decode("{\"id\":2,\"attachments\":[{\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}]}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $this->u_estrin->checked_paper_by_id(2);
        $docs = $paper2->option(2)->documents();
        xassert_eqq(count($docs), 2);
        xassert($docs[0]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
        xassert($docs[1]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
    }

    function test_sha256() {
        $this->conf->save_setting("opt.contentHashMethod", 1, "sha256");
        $ps = new PaperStatus($this->conf);
        $ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content\":\"%PDF-whatever\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        xassert_eqq($paper3->sha1, "sha2-" . hex2bin("38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4"));
        xassert_eqq($paper3->document(DTYPE_SUBMISSION)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

        $paper3b = $ps->paper_json(3);
        xassert_eqq($paper3b->submission->hash, "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");
    }

    function test_save_new_paper() {
        $ps = new PaperStatus($this->conf);
        $ps->save_paper_json(json_decode("{\"id\":\"new\",\"submission\":{\"content\":\"%PDF-jiajfnbsaf\\n\",\"type\":\"application/pdf\"},\"title\":\"New paper J\",\"abstract\":\"This is a jabstract\\r\\n\",\"authors\":[{\"name\":\"Poopo\"}]}"));
        xassert_paper_status($ps);
        $newpaperj = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert(!!$newpaperj->primary_document());
        ConfInvariants::test_all($this->conf);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com"]), null, "update"));
        xassert_paper_status($ps);
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        xassert_paper_status($ps);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted <= 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", []), $newpaper, "submit"));
        xassert_array_eqq($ps->change_keys(), ["status"], true);
        xassert($ps->execute_save());
        xassert_paper_status_saved_nonrequired($ps, MessageSet::WARNING);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted == 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_submit_new_paper() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web((new Qrequest("POST", ["submitpaper" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com", "has_submission" => 1]))->set_file_content("submission", "%PDF-2", null, "application/pdf"), null, "update"));
        xassert_paper_status($ps);
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        xassert_paper_status_saved_nonrequired($ps);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted > 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        $this->newpaper1 = $newpaper;
    }

    function test_save_submit_new_paper_empty_contacts() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com", "has_contacts" => 1]), null, "update"));
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        xassert_paper_status($ps);

        $newpaperx = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaperx);
        xassert_eqq($newpaperx->title, "New paper");
        xassert_eqq($newpaperx->abstract, "This is an abstract");
        xassert_eqq($newpaperx->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaperx->author_list()), 1);
        $aus = $newpaperx->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaperx->timeSubmitted <= 0);
        xassert($newpaperx->timeWithdrawn <= 0);
        xassert_eqq($newpaperx->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_draft_new_paper() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:name_1" => "Bobby Flay", "authors:email_1" => "flay@_.com", "has_submission" => 1]), null, "update"));
        xassert_paper_status($ps);
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        xassert_paper_status_saved_nonrequired($ps);

        $newpaperx = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaperx);
        xassert($newpaperx->timeSubmitted <= 0);
    }

    function test_save_options() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10", "has_opt1" => "1"]), $this->newpaper1, "update"));
        xassert_array_eqq($ps->change_keys(), ["calories", "status"], true);
        xassert($ps->execute_save());
        xassert_paper_status($ps);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10xxxxx", "has_opt1" => "1"]), $this->newpaper1, "update"));
        xassert_array_eqq($ps->change_keys(), [], true);
        xassert($ps->has_error_at("opt1"));

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "none", "has_opt1" => "1"]), $this->newpaper1, "update"));
        xassert_array_eqq($ps->change_keys(), ["calories"], true);
        xassert_paper_status($ps);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted <= 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->option(1)->value, 10);
    }

    function test_save_old_authors() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["has_authors" => "1", "auname1" => "Robert Flay", "auemail1" => "flay@_.com"]), $this->newpaper1, "update"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->execute_save());
        xassert_paper_status($ps);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract_text(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Robert");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted <= 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->option(1)->value, 10);
    }

    function test_save_new_authors() {
        $qreq = new Qrequest("POST", ["submitpaper" => 1, "has_opt2" => "1", "has_opt2_new_1" => "1", "title" => "Paper about mantis shrimp", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:aff_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $qreq->set_file("opt2_new_1", ["name" => "attachment1.pdf", "type" => "application/pdf", "content" => "%PDF-whatever\n", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert($ps->prepare_save_paper_web($qreq, null, "update"));
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("submission"));
        xassert($ps->execute_save());
        xassert_paper_status($ps);
        $this->pid2 = $ps->paperId;

        $newpaper = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "Paper about mantis shrimp");
        xassert_eqq($newpaper->abstract, "They see lots of colors.");
        xassert_eqq($newpaper->abstract_text(), "They see lots of colors.");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "David");
        xassert_eqq($aus[0]->lastName, "Attenborough");
        xassert_eqq($aus[0]->email, "atten@_.com");
        xassert($newpaper->timeSubmitted > 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert(!$newpaper->option(1));
        xassert(!!$newpaper->option(2));
        xassert(count($newpaper->force_option(0)->documents()) === 1);
        xassert_eqq($newpaper->force_option(0)->document(0)->text_hash(), "sha2-d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b");
        xassert(count($newpaper->option(2)->documents()) === 1);
        xassert_eqq($newpaper->option(2)->document(0)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");
        xassert($newpaper->has_author($this->u_estrin));
    }

    function test_save_missing_required_fields() {
        $qreq = new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null, "update");
        xassert($ps->has_error_at("title"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");

        $qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null, "update");
        xassert($ps->has_error_at("title"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");

        $qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "has_submission" => "1"]);
        $qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null, "update");
        xassert($ps->has_error_at("abstract"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");
    }

    function test_save_no_abstract_submit_ok() {
        $this->conf->set_opt("noAbstract", 1);
        $this->conf->invalidate_caches(["options" => true]);

        $qreq = new Qrequest("POST", ["submitpaper" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:affiliation_1" => "BBC", "has_submission" => "1"]);
        $qreq->set_file("submission", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null, "update");
        xassert(!$ps->has_error_at("abstract"));
        xassert_eqq(count($ps->error_fields()), 0);
        xassert_eqq($ps->feedback_text($ps->error_list()), "");
    }

    function test_save_abstract_format() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->abstract, "They see lots of colors.");

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "abstract" => " They\nsee\r\nlots of\n\n\ncolors. \n\n\n"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["abstract"], true);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->abstract, "They\nsee\r\nlots of\n\n\ncolors.");
    }

    function test_save_collaborators() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "");
        $this->conf->save_refresh_setting("sub_collab", 1);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "  John Fart\rMIT\n\nButt Man (UCLA)"]), $nprow1, "update");
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->change_keys(), ["collaborators"], true);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "John Fart\nAll (MIT)\n\nButt Man (UCLA)");
    }

    function test_save_collaborators_normalization() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "Sal Stolfo, Guofei Gu, Manos Antonakakis, Roberto Perdisci, Weidong Cui, Xiapu Luo, Rocky Chang, Kapil Singh, Helen Wang, Zhichun Li, Junjie Zhang, David Dagon, Nick Feamster, Phil Porras."]), $nprow1, "update");
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->change_keys(), ["collaborators"], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "Sal Stolfo
Guofei Gu
Manos Antonakakis
Roberto Perdisci
Weidong Cui
Xiapu Luo
Rocky Chang
Kapil Singh
Helen Wang
Zhichun Li
Junjie Zhang
David Dagon
Nick Feamster
Phil Porras.");
    }

    function test_save_collaborators_too_long() {
        $long_collab = [];
        for ($i = 0; $i !== 1000; ++$i) {
            $long_collab[] = "Collaborator $i (MIT)";
        }
        $long_collab = join("\n", $long_collab);
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => $long_collab]), $this->u_estrin->checked_paper_by_id($this->pid2), "update");
        xassert_paper_status($ps);
        xassert_array_eqq($ps->change_keys(), ["collaborators"], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators, null);
        xassert_eqq(json_encode_db($nprow1->dataOverflow), json_encode_db(["collaborators" => $long_collab]));
        xassert_eqq($nprow1->collaborators(), $long_collab);

        // the collaborators are short again
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "collaborators" => "One guy (MIT)"]), $nprow1, "update");
        xassert_paper_status($ps);
        xassert_array_eqq($ps->change_keys(), ["collaborators"], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "One guy (MIT)");
        xassert_eqq($nprow1->dataOverflow, null);

        $this->conf->save_refresh_setting("sub_collab", null);
    }

    function test_save_topics() {
        $this->conf->qe("insert into TopicArea (topicName) values ('Cloud computing'), ('Architecture'), ('Security'), ('Cloud networking')");
        $this->conf->save_setting("has_topics", 1);
        $this->conf->invalidate_topics();

        $tset = $this->conf->topic_set();
        xassert_eqq($tset[1], "Cloud computing");
        xassert_eqq($tset[2], "Architecture");
        xassert_eqq($tset[3], "Security");
        xassert_eqq($tset[4], "Cloud networking");

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->topic_list(), []);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["Cloud computing"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => (object) ["Cloud computing" => true, "Security" => true]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1, 3]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => [2, 4]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 4]);

        // extended topic saves
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["architecture", "security"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 3]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["fartchitecture"]
        ]);
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("topics"), "Unknown topic ignored (fartchitecture)\n");
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), []); // XXX should be unchanged

        $ps = new PaperStatus($this->conf, $this->u_estrin, ["add_topics" => true]);
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["fartchitecture", "architecture"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 5]);

        $qreq = new Qrequest("POST", ["submitpaper" => 1, "has_topics" => 1, "top1" => 1, "top5" => 1]);
        $ps->save_paper_web($qreq, $nprow1, "update");
        xassert(!$ps->has_problem());
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1, 5]);
    }

    /** @param PaperInfo $prow
     * @return list<int> */
    static function pc_conflict_keys($prow) {
        return array_keys($prow->pc_conflicts());
    }

    /** @param PaperInfo $prow
     * @return array<int,int> */
    static function pc_conflict_types($prow) {
        return array_map(function ($cflt) { return $cflt->conflictType; }, $prow->pc_conflicts());
    }

    /** @param PaperInfo $prow
     * @return list<string> */
    static function contact_emails($prow) {
        $e = array_map(function ($cflt) { return $cflt->email; }, $prow->contacts(true));
        sort($e);
        return $e;
    }

    function test_save_pc_conflicts() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId]);
        xassert_eqq(self::pc_conflict_types($nprow1), [$this->u_estrin->contactId => CONFLICT_CONTACTAUTHOR]);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => []
        ]);
        xassert(!$ps->has_problem());
        xassert(!$ps->has_change());
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => true, $this->u_sally->email => true]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1),
            [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->u_sally->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => []
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email, "notpc@no.com"]
        ]);
        xassert($ps->has_problem());
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->change_keys(), [], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), Conflict::GENERAL);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "advisor"]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem
        xassert_array_eqq($ps->change_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 4);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "advisor", $this->u_estrin->email => false, $this->u_chair->email => false]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem
        xassert_array_eqq($ps->change_keys(), [], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 4);
    }

    function test_save_pinned_pc_conflicts() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);

        // non-chair cannot pin conflicts
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 2);

        // chair can pin conflicts
        $psc = new PaperStatus($this->conf, $this->u_chair);
        $psc->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned advisor"]
        ]);
        xassert(!$psc->has_problem());

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 5);

        // non-chair cannot change pinned conflicts
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 5);
    }

    function test_save_contacts_no_remove_self() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => []
        ]);
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("contacts"), "Each submission must have at least one contact
You can’t remove yourself from the submission’s contacts
    (Ask another contact to remove you.)\n");

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu"]), $nprow1, "update");
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("contacts"), "Each submission must have at least one contact
You can’t remove yourself from the submission’s contacts
    (Ask another contact to remove you.)\n");

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => ["estrin@usc.edu"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), [], true);
    }

    function test_save_contacts_ignore_empty() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu", "contacts:active_1" => 1, "contacts:email_2" => "", "contacts:active_2" => 1]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), [], true);
    }

    function test_save_contacts_creates_user() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        xassert(!$this->conf->user_by_email("festrin@fusc.fedu"));
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => ["estrin@usc.edu", (object) ["email" => "festrin@fusc.fedu", "name" => "Feborah Festrin"]]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["contacts"], true);

        $new_user = $this->conf->user_by_email("festrin@fusc.fedu");
        xassert(!!$new_user);
        xassert_eqq($new_user->firstName, "Feborah");
        xassert_eqq($new_user->lastName, "Festrin");
        $this->festrin_cid = $new_user->contactId;
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert($nprow1->has_author($new_user));
    }

    function test_save_contacts_creates_user_2() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert(!$this->conf->user_by_email("gestrin@gusc.gedu"));
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "estrin@usc.edu", "contacts:active_1" => 1, "contacts:email_2" => "festrin@fusc.fedu", "contacts:email_3" => "gestrin@gusc.gedu", "contacts:name_3" => "Geborah Gestrin", "contacts:active_3" => 1]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["contacts"], true);

        $new_user2 = $this->conf->user_by_email("gestrin@gusc.gedu");
        xassert(!!$new_user2);
        $this->gestrin_cid = $new_user2->contactId;
        xassert_eqq($new_user2->firstName, "Geborah");
        xassert_eqq($new_user2->lastName, "Gestrin");

        $nprow1->invalidate_conflicts();
        xassert(!$nprow1->has_author($this->festrin_cid));
        xassert($nprow1->has_author($new_user2));
    }

    function test_save_contacts_partial_update() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), [], true);

        $nprow1->invalidate_conflicts();
        xassert_array_eqq(self::contact_emails($nprow1), ["estrin@usc.edu", "gestrin@gusc.gedu"], true);

        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "atten@_.com", "contacts:active_1" => 1]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["contacts"], true);

        $nprow1->invalidate_conflicts();
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "gestrin@gusc.gedu"], true);
        $this->u_atten = $this->conf->checked_user_by_email("ATTEN@_.coM");
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => 1, "contacts:email_1" => "gestrin@gusc.gedu", "contacts:email_2" => "atten@_.com"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["contacts"], true);
        $nprow1->invalidate_conflicts();
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu"], true);
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
    }

    function test_resolve_primary() {
        $this->conf->qe("update ContactInfo set primaryContactId=? where email=?", $this->festrin_cid, "gestrin@gusc.gedu");
        xassert_eqq($this->conf->resolve_primary_emails(["Gestrin@GUSC.gedu", "festrin@fusc.fedu"]), ["festrin@fusc.fedu", "festrin@fusc.fedu"]);
    }

    function test_save_authors_resolve_primary() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com", "authors:name_2" => "Geborah Gestrin", "authors:email_2" => "gestrin@gusc.gedu"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["authors", "contacts"], true);

        $nprow1 = $this->conf->checked_paper_by_id($this->pid2);
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu", "gestrin@gusc.gedu"], true);
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_AUTHOR);
        xassert_eqq($nprow1->conflict_type($this->gestrin_cid), CONFLICT_AUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_authors" => "1", "authors:name_1" => "David Attenborough", "authors:email_1" => "atten@_.com"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["authors", "contacts"], true);
        $nprow1 = $this->conf->checked_paper_by_id($this->pid2);
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu"], true);
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_contacts_resolve_primary() {
        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => "1", "contacts:email_1" => "gestrin@gusc.gedu", "contacts:active_1" => "1"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["contacts"], true);

        $nprow1->invalidate_conflicts();
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu"], true);
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_CONTACTAUTHOR);

        $ps->save_paper_web(new Qrequest("POST", ["submitpaper" => 1, "has_contacts" => "1", "contacts:email_1" => "gestrin@gusc.gedu"]), $nprow1, "update");
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), [], true);

        $nprow1->invalidate_conflicts();
        xassert_array_eqq(self::contact_emails($nprow1), ["atten@_.com", "estrin@usc.edu", "festrin@fusc.fedu"], true);
        xassert_eqq($nprow1->conflict_type($this->u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_conflicts_resolve_primary() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);

        $this->conf->qe("update ContactInfo set roles=1 where contactId=?", $this->festrin_cid);
        $this->conf->invalidate_caches(["pc" => true]);

        $ps = new PaperStatus($this->conf, $this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => ["gestrin@gusc.gedu" => true]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->change_keys(), ["pc_conflicts"], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->festrin_cid]);
    }

    function test_content_text_signature() {
        $doc = new DocumentInfo(["content" => "ABCdefGHIjklMNO"], $this->conf);
        xassert_eqq($doc->content_text_signature(), "starts with “ABCdefGH”");
        $doc = new DocumentInfo(["content" => "\x02\x00A\x80BCdefGHIjklMN"], $this->conf);
        xassert_eqq($doc->content_text_signature(), "starts with “\\x02\\x00A\\x80BCde”");
        $doc = new DocumentInfo(["content" => ""], $this->conf);
        xassert_eqq($doc->content_text_signature(), "is empty");

        $doc = new DocumentInfo(["content_file" => "/tmp/this-file-is-expected-not-to-exist.png.zip"], $this->conf);
        ++Xassert::$disabled;
        $s = $doc->content_text_signature();
        --Xassert::$disabled;
        xassert_eqq($s, "cannot be loaded");
    }

    function test_banal() {
        $spects = max(Conf::$now - 100, @filemtime(SiteLoader::find("src/banal")));
        $this->conf->save_setting("sub_banal", $spects, "letter;30;;6.5x9in");
        $this->conf->invalidate_caches(["options" => true]);
        xassert_eq($this->conf->format_spec(DTYPE_SUBMISSION)->timestamp, $spects);

        $ps = new PaperStatus($this->conf, null, ["content_file_prefix" => SiteLoader::$root . "/"]);
        $ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content_file\":\"test/sample50pg.pdf\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf = new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
        xassert_eqq($doc->npages($cf), null);  // page count not yet calculated
        xassert_eqq($doc->npages(), 50);       // once it IS calculated,
        xassert_eqq($doc->npages($cf), 50);    // it is cached

        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        $doc = $paper3->document(DTYPE_SUBMISSION);
        xassert_eqq($doc->npages($cf), 50);    // ...even on reload

        // check format checker; this uses result from previous npages()
        $cf_nec = new CheckFormat($this->conf, CheckFormat::RUN_IF_NECESSARY);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit textblock");
        xassert(!$cf_nec->need_recheck());
        xassert(!$cf_nec->run_attempted());
        xassert_eq($paper3->pdfFormatStatus, -$spects);

        // change the format spec
        $this->conf->save_setting("sub_banal", $spects + 1, "letter;30;;7.5x9in");
        $this->conf->invalidate_caches(["options" => true]);

        // that actually requires rerunning banal because its cached result is truncated
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
        xassert(!$cf_nec->need_recheck());
        xassert($cf_nec->run_attempted());

        // but then the result is cached
        $paper3->invalidate_documents();
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
        xassert(!$cf_nec->need_recheck());
        xassert(!$cf_nec->run_attempted());

        // new, short document
        $ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content_file\":\"etc/sample.pdf\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);

        // once the format is checked
        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "");
        xassert(!$cf_nec->need_recheck());
        xassert($cf_nec->run_attempted());

        // we can reuse the banal JSON output on another spec
        $this->conf->save_setting("sub_banal", $spects + 1, "letter;1;;7.5x9in");
        $this->conf->invalidate_caches(["options" => true]);

        $paper3->invalidate_documents();
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
        xassert(!$cf_nec->need_recheck());
        xassert(!$cf_nec->run_attempted());

        $this->conf->save_setting("sub_banal", $spects + 1, "letter;2;;7.5x9in");
    }

    function test_option_name_parens() {
        $options = $this->conf->setting_json("options");
        xassert(!array_filter((array) $options, function ($o) { return $o->id === 3; }));
        $options[] = (object) ["id" => 3, "name" => "Supervisor(s)", "type" => "text", "order" => 3];
        $this->conf->save_setting("options", 1, json_encode($options));
        $this->conf->invalidate_caches(["options" => true]);

        $ps = new PaperStatus($this->conf);
        $ps->save_paper_json(json_decode("{\"id\":3,\"Supervisor(s)\":\"fart fart barf barf\"}"));
        xassert_paper_status($ps);
        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        xassert(!!$paper3->option(3));
        xassert_eqq($paper3->option(3)->value, 1);
        xassert_eqq($paper3->option(3)->data(), "fart fart barf barf");

        $ps->save_paper_json(json_decode("{\"id\":3,\"Supervisor\":\"fart fart bark bark\"}"));
        xassert_paper_status($ps);
        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        xassert(!!$paper3->option(3));
        xassert_eqq($paper3->option(3)->value, 1);
        xassert_eqq($paper3->option(3)->data(), "fart fart bark bark");

        $ps->save_paper_json(json_decode("{\"id\":3,\"Supervisors\":\"farm farm bark bark\"}"));
        xassert_paper_status($ps);
        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        xassert(!!$paper3->option(3));
        xassert_eqq($paper3->option(3)->value, 1);
        xassert_eqq($paper3->option(3)->data(), "farm farm bark bark");
    }

    function test_author_mail_lacks_review_info() {
        // mail to authors does not include information that only reviewers can see
        // (this matters when an author is also a reviewer)
        MailChecker::clear();
        $estrin_14_rrow = save_review(14, $this->u_estrin, ["overAllMerit" => 5, "revexp" => 1, "papsum" => "Summary 1", "comaut" => "Comments 1", "ready" => false]);
        xassert($estrin_14_rrow);
        $estrin_14_rid = $estrin_14_rrow->reviewId;
        save_review(14, $this->u_varghese, ["ovemer" => 5, "revexp" => 2, "papsum" => "Summary V", "comaut" => "Comments V", "compc" => "PC V", "ready" => true]);
        $paper14 = $this->u_estrin->checked_paper_by_id(14);
        HotCRPMailer::send_contacts("@rejectnotify", $paper14);
        MailChecker::check_db("test05-reject14-1");
        xassert_assign($this->conf->root_user(), "action,paper,user\ncontact,14,varghese@ccrc.wustl.edu");
        $paper14 = $this->u_estrin->checked_paper_by_id(14);
        HotCRPMailer::send_contacts("@rejectnotify", $paper14);
        MailChecker::check_db("test05-reject14-2");
    }

    function test_paper_page_redirects() {
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/paper/0123"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/123");
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/paper?p=0123"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/123");
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/review/0123"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/review/123");
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/review?p=0123"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/review/123");
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/paper/3"), false);
        xassert($pr instanceof PaperRequest);
        $estrin_14_rid = $this->conf->checked_paper_by_id(14)->checked_review_by_user($this->u_estrin)->reviewId;
        $pr = PaperRequest::make($this->u_estrin, Qrequest::make_url("/paper?r={$estrin_14_rid}"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/14?r={$estrin_14_rid}");
        $pr = PaperRequest::make($this->u_varghese, Qrequest::make_url("/paper?r={$estrin_14_rid}"), false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/14?r={$estrin_14_rid}");
        $pr = PaperRequest::make($this->u_nobody, Qrequest::make_url("/paper?r={$estrin_14_rid}"), false);
        xassert($pr instanceof PermissionProblem);
        xassert($pr["missingId"]);
    }

    function test_invariants_last() {
        ConfInvariants::test_all($this->conf);
    }
}
