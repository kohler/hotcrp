<?php
// t_paperstatus.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

#[RequireDb("fresh")]
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
        $conf->save_setting("viewrev", null);
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

        $this->paper1a = (new PaperExport($this->u_estrin))->paper_json(1);
        xassert_eqq($this->paper1a->title, "Scalable Timers for Soft State Protocols");
        $paper1a_pcc = $this->paper1a->pc_conflicts;
        '@phan-var-force object $paper1a_pcc';
        xassert_eqq($paper1a_pcc->{"estrin@usc.edu"}, "author");

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
        xassert_paper_status($ps);
        $paper1->invalidate_conflicts();
        xassert_eqq($paper1->conflict_type($this->u_estrin), CONFLICT_AUTHOR);

        $ps->save_paper_json(json_decode('{
            "id": 1, "title": "Scalable Timers? for Soft State Protocols",
            "authors": [
                {"name": "Puneet Sharma", "email": "puneet@catarina.usc.edu", "affiliation": "Information Sciences Institute, University of Southern California"},
                {"name": "Sally Floyd", "email": "floyd@ee.lbl.gov", "affiliation": "Lawrence Berkeley National Laboratory"},
                {"name": "Van Jacobson", "email": "van@ee.lbl.gov", "affiliation": "Lawrence Berkeley National Laboratory"}
            ]
        }'));
        xassert_paper_status($ps);
        $paper1->invalidate_conflicts();
        xassert_eqq($paper1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

        $ps->save_paper_json(json_decode('{
            "id": 1, "title": "Scalable Timers? for Soft State Protocols",
            "authors": [
                {"name": "Puneet Sharma", "email": "puneet@catarina.usc.edu", "affiliation": "Information Sciences Institute, University of Southern California"},
                {"name": "Deborah Estrin", "email": "estrin@USC.edu", "affiliation": "Information Sciences Institute, University of Southern California"},
                {"name": "Sally Floyd", "email": "floyd@ee.lbl.gov", "affiliation": "Lawrence Berkeley National Laboratory"},
                {"name": "Van Jacobson", "email": "van@ee.lbl.gov", "affiliation": "Lawrence Berkeley National Laboratory"}
            ]
        }'));
        xassert_paper_status($ps);
        $paper1->invalidate_conflicts();
        xassert_eqq($paper1->conflict_type($this->u_estrin), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
    }

    function test_paper_json_identity() {
        $paper1b = (new PaperExport($this->u_estrin))->paper_json(1);
        xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
        $paper1b->title = $this->paper1a->title;
        $paper1b->submitted_at = $this->paper1a->submitted_at;
        $paper1b->modified_at = $this->paper1a->modified_at;
        $s1 = json_encode($this->paper1a);
        $s2 = json_encode($paper1b);
        xassert_eqq($s1, $s2);
        if ($s1 !== $s2) {
            while (substr($s1, 0, 30) === substr($s2, 0, 30)) {
                $s1 = substr($s1, 10);
                $s2 = substr($s2, 10);
            }
            error_log("   > {$s1}\n   > {$s2}");
        }
    }

    function test_paper_save_document() {
        $ps = new PaperStatus($this->u_estrin);
        $doc = DocumentInfo::make_uploaded_file($this->conf, QrequestFile::make_finfo([
                "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
                "tmp_name" => SiteLoader::find("etc/sample.pdf"),
                "type" => "application/pdf"
            ]))->set_document_type(DTYPE_SUBMISSION);
        xassert_eqq($doc->content_text_signature(), "starts with “%PDF-1.2”");
        $ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
        xassert_paper_status($ps);
        $paper1c = (new PaperExport($this->u_estrin))->paper_json(1);
        xassert_eqq($paper1c->submission->hash, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq(sha1($paper1->primary_document()->content()), "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");
    }

    function test_paper_replace_document() {
        $pex = new PaperExport($this->conf->root_user());
        $paper2a = $pex->paper_json(2);
        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2b = $pex->paper_json(2);
        xassert_eqq($paper2b->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");

        // final versions can’t be saved unless final versions are open
        $this->conf->qe("update Paper set outcome=1 where paperId=2");
        $this->conf->save_setting("final_open", 1);
        $this->conf->save_setting("au_seedec", 1);
        $this->conf->load_settings();

        $p2 = $this->conf->checked_paper_by_id(2);
        xassert_gt($p2->outcome_sign, 0);
        xassert_eqq($p2->phase(), PaperInfo::PHASE_FINAL);
        $hello_docid = $p2->paperStorageId;

        $ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"%PDF-goodbye\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper2 = $pex->paper_json(2);
        xassert_eqq($paper2->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");
        xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");

        $ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-again hello\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $p2 = $this->u_estrin->checked_paper_by_id(2);
        xassert_eqq(bin2hex($p2->sha1), "e04c778a0af702582bb0e9345fab6540acb28e45");
        xassert_neqq($p2->paperStorageId, $hello_docid);
        $again_docid = $p2->paperStorageId;
        $doc = $p2->document(DTYPE_SUBMISSION, $hello_docid);
        xassert_eqq($doc->inactive, 1);
        $p2j = $pex->paper_json(2);
        xassert_eqq($p2j->submission->hash, "30240fac8417b80709c72156b7f7f7ad95b34a2b");
        xassert_eqq($p2j->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");

        $this->conf->qe("delete from Settings where name='final_open' or name='au_seedec'");
        $this->conf->qe("update Paper set outcome=0 where paperId=2");

        $ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $p2 = $this->conf->checked_paper_by_id(2);
        xassert_eqq($p2->paperStorageId, $hello_docid);
        $doc = $p2->document(DTYPE_SUBMISSION, $hello_docid);
        xassert_eqq($doc->inactive, 0);
        $doc = $p2->document(DTYPE_SUBMISSION, $again_docid);
        xassert_eqq($doc->inactive, 1);
    }

    function test_paper_new_document_after_deadline() {
        $p3 = $this->conf->checked_paper_by_id(3);
        $u_sclin = $this->conf->user_by_email("sclin@leland.stanford.edu");
        $p3did = $p3->paperStorageId;

        $deadline = $this->conf->setting("sub_update");
        $this->conf->save_refresh_setting("sub_update", Conf::$now - 3600);

        $ps = new PaperStatus($u_sclin);
        $ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content\":\"%PDF-fsanfndsakfndskajfndskjanfkjsdanfkjsdnafkjsnak\\n\",\"type\":\"application/pdf\"}}"));
        xassert($ps->has_error());

        $p3 = $this->conf->checked_paper_by_id(3);
        xassert_eqq($p3->paperStorageId, $p3did);
        $this->conf->save_refresh_setting("sub_update", $deadline);
        xassert(ConfInvariants::test_document_inactive($this->conf));
    }

    function test_document_options_storage() {
        TestRunner::reset_options();
        $options = $this->conf->setting_json("options");
        xassert(!array_filter((array) $options, function ($o) { return $o->id === 2; }));
        if (array_filter((array) $options, function ($o) { return $o->id === 2; })) {
            error_log("! " . json_encode($options, JSON_PRETTY_PRINT));
        }
        $options[] = (object) ["id" => 2, "name" => "Attachments", "abbr" => "attachments", "type" => "attachments", "order" => 2];
        $this->conf->save_setting("options", 1, json_encode($options));
        $this->conf->invalidate_caches("options");

        $ps = new PaperStatus($this->conf->root_user());
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
        $ps = new PaperStatus($this->conf->root_user());
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
        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content\":\"%PDF-whatever\\n\",\"type\":\"application/pdf\"}}"));
        xassert_paper_status($ps);
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        xassert_eqq($paper3->sha1, "sha2-" . hex2bin("38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4"));
        xassert_eqq($paper3->document(DTYPE_SUBMISSION)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

        $paper3b = (new PaperExport($this->conf->root_user()))->paper_json(3);
        xassert_eqq($paper3b->submission->hash, "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");
    }

    function test_document_time_referenced() {
        $u_root = $this->conf->root_user();
        $ps = new PaperStatus($u_root);
        $p3 = $u_root->checked_paper_by_id(3);
        $p3tm = $p3->timeModified;
        $p3abstract = $p3->abstract();
        $d3 = $p3->document(DTYPE_SUBMISSION, 0, true);
        $d3content = $d3->content();
        $d3ts = $d3->timestamp;
        $d3tr = $d3->timeReferenced;
        $d3id = $d3->paperStorageId;
        xassert($d3tr === null || ($d3tr >= $d3->timestamp && $d3tr <= $p3tm));

        $ps->save_paper_json(json_decode('{"id":3,"abstract":"Second try at an abstract"}'));
        xassert_paper_status($ps);
        $p3 = $u_root->checked_paper_by_id(3);
        $d3 = $p3->document(DTYPE_SUBMISSION, 0, true);
        xassert_gt($p3->timeModified, $p3tm);
        xassert_eqq($d3->paperStorageId, $d3id);
        xassert_eqq($d3->timestamp, $d3ts);
        xassert_eqq($d3->timeReferenced, $d3tr);
        $p3tm = $p3->timeModified;

        Conf::advance_current_time();
        $ps->save_paper_json((object) [
            "id" => 3,
            "submission" => (object) [ "content" => $d3content, "type" => "application/pdf" ]
        ]);
        xassert_paper_status($ps);
        $p3 = $u_root->checked_paper_by_id(3);
        $d3 = $p3->document(DTYPE_SUBMISSION, 0, true);
        xassert_eqq($p3->timeModified, $p3tm);
        xassert_eqq($d3->paperStorageId, $d3id);
        xassert_eqq($d3->timestamp, $d3ts);
        xassert_eqq($d3->timeReferenced, $d3tr);
        $p3tm = $p3->timeModified;

        Conf::advance_current_time();
        $d3newcontent = $d3content . "\nHello, friends\n";
        $ps->save_paper_json((object) [
            "id" => 3,
            "submission" => (object) [ "content" => $d3newcontent, "type" => "application/pdf" ]
        ]);
        xassert_paper_status($ps);
        $p3 = $u_root->checked_paper_by_id(3);
        $d3 = $p3->document(DTYPE_SUBMISSION, 0, true);
        xassert_gt($p3->timeModified, $p3tm);
        xassert_neqq($d3->paperStorageId, $d3id);
        xassert_gt($d3->timestamp, $d3ts);
        xassert_eqq($d3->content(), $d3newcontent);
        xassert($d3->timeReferenced === null || $d3->timeReferenced === $p3->timeModified);
        $p3tm = $p3->timeModified;

        Conf::advance_current_time();
        $ps->save_paper_json((object) [
            "id" => 3,
            "abstract" => $p3abstract,
            "submission" => (object) [ "content" => $d3content, "type" => "application/pdf" ]
        ]);
        xassert_paper_status($ps);
        $p3 = $u_root->checked_paper_by_id(3);
        $d3 = $p3->document(DTYPE_SUBMISSION, 0, true);
        xassert_gt($p3->timeModified, $p3tm);
        xassert_eqq($d3->paperStorageId, $d3id);
        xassert_eqq($d3->timestamp, $d3ts);
        xassert_eqq($d3->timeReferenced, $p3->timeModified);
        $p3tm = $p3->timeModified;
    }

    function test_document_image_dimensions() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Image",
            "sf/1/id" => "new",
            "sf/1/order" => 100,
            "sf/1/type" => "document"
        ]);
        xassert($sv->execute());
        $opt = $this->conf->options()->find("Image");
        xassert(!!$opt);

        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json(json_decode('{"id":3,"Image":{"content_base64":"R0lGODlhIAAeAPAAAP///wAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQEMgD/ACwAAAAAIAAeAAACHYSPqcvtD6OctNqLs968+w+G4kiW5omm6sq27isVADs="}}'));
        xassert_paper_status($ps);

        $paper3 = $this->u_estrin->checked_paper_by_id(3);
        $doc = $paper3->document($opt->id);
        xassert(!!$doc);
        xassert_eqq($doc->mimetype, "image/gif");
        xassert_eqq($doc->width(), 32);
        xassert_eqq($doc->height(), 30);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/name" => "Image",
            "sf/1/delete" => "1"
        ]);
        xassert($sv->execute());
        $opt = $this->conf->options()->find("Image");
        xassert(!$opt);
        xassert(ConfInvariants::test_document_inactive($this->conf));
    }

    function test_save_new_paper() {
        $ps = new PaperStatus($this->conf->root_user());
        $ps->save_paper_json(json_decode("{\"id\":\"new\",\"submission\":{\"content\":\"%PDF-jiajfnbsaf\\n\",\"type\":\"application/pdf\"},\"title\":\"New paper J\",\"abstract\":\"This is a jabstract\\r\\n\",\"authors\":[{\"name\":\"Poopo\"}],\"pc_conflicts\":{\"mjh@isi.edu\":false,\"estrin@usc.edu\":false}}"));
        xassert_paper_status($ps);
        $newpaperj = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert(!!$newpaperj->primary_document());
        ConfInvariants::test_all($this->conf);

        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com"]), null));
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
        xassert_eqq($newpaper->title(), "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted <= 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["status:submit" => 1]), $newpaper));
        xassert_array_eqq($ps->changed_keys(), [], true);
        xassert($ps->execute_save());
        xassert_paper_status_saved_nonrequired($ps, MessageSet::WARNING);

        $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted === 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_submit_new_paper() {
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web((new Qrequest("POST", ["status:submit" => 1, "title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1]))->set_file_content("submission:file", "%PDF-2", null, "application/pdf"), null));
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
        xassert_eqq($newpaper->abstract(), "This is an abstract");
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
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_contacts" => 1]), null));
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
        xassert_eqq($newpaperx->abstract(), "This is an abstract");
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
        $ps = new PaperStatus($this->u_estrin);
        // NB old style of entries
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1]), null));
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

    function test_save_draft_new_paper_no_conflicts() {
        $u_bark = Contact::make_email($this->conf, "bark@_.com")->store();
        $ps = new PaperStatus($u_bark);
        // NB old style of entries
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Richard Attenborough", "authors:1:email" => "bark@_.com", "has_submission" => 1, "has_pc_conflicts" => 1, "has_pcconf:{$this->u_estrin->contactId}" => 1]), null));
        xassert_paper_status($ps);
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("contacts"));
        xassert($ps->execute_save());
        xassert_paper_status_saved_nonrequired($ps);

        $newpaperx = $u_bark->checked_paper_by_id($ps->paperId);
        xassert($newpaperx);
        xassert($newpaperx->timeSubmitted <= 0);
        ConfInvariants::test_all($this->conf);
    }

    function test_save_new_fail_deadline() {
        $old_sub_reg = $this->conf->setting("sub_reg");
        xassert($this->conf->setting("sub_update") > Conf::$now);
        $this->conf->save_refresh_setting("sub_reg", Conf::$now - 1000);
        xassert(!$this->conf->unnamed_submission_round()->time_register(true));
        xassert($this->conf->unnamed_submission_round()->time_update(true));

        $ps = new PaperStatus($this->u_estrin);
        xassert(!$ps->user->privChair);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Bobby Flay", "authors:1:email" => "flay@_.com", "has_submission" => 1]), null));
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        try {
            $ok = $ps->execute_save();
        } catch (Exception $e) {
            $ok = false;
        }
        xassert(!$ok);

        // updating existing paper would succeed though
        $ps = new PaperStatus($this->u_estrin);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert(!!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols???"]), $paper1));
        xassert($ps->has_change_at("title"));

        $this->conf->save_refresh_setting("sub_reg", $old_sub_reg);
    }

    function test_save_existing_fail_deadline() {
        $old_sub_reg = $this->conf->setting("sub_reg");
        $old_sub_update = $this->conf->setting("sub_update");
        $this->conf->save_setting("sub_reg", Conf::$now - 1000);
        $this->conf->save_refresh_setting("sub_update", Conf::$now - 1000);
        xassert(!$this->conf->unnamed_submission_round()->time_register(true));
        xassert(!$this->conf->unnamed_submission_round()->time_update(true));

        $ps = new PaperStatus($this->u_estrin);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert(!$ps->user->privChair);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols!!!!"]), $paper1));
        xassert($ps->has_change_at("title"));
        try {
            $ok = $ps->execute_save();
        } catch (Exception $e) {
            $ok = false;
        }
        xassert(!$ok);

        $this->conf->save_setting("sub_reg", $old_sub_reg);
        $this->conf->save_refresh_setting("sub_update", $old_sub_update);
    }

    function test_save_finalize() {
        $ps = new PaperStatus($this->u_chair);
        xassert($ps->save_paper_json(json_decode('{"pid":1,"draft":true,"title":"Scalable Timers for Soft State Protocols"}')));
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert($paper1->timeSubmitted === 0);

        $old_sub_update = $this->conf->setting("sub_update");
        $this->conf->save_refresh_setting("sub_update", Conf::$now - 1000);
        xassert(!$this->conf->unnamed_submission_round()->time_update(true));
        xassert($this->conf->unnamed_submission_round()->time_submit(true));

        $ps = new PaperStatus($this->u_estrin);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols 2", "status:submit" => 1]), $paper1));
        xassert($ps->has_change_at("title"));
        try {
            $ok = $ps->execute_save();
        } catch (Exception $e) {
            $ok = false;
        }
        xassert(!$ok);

        $ps = new PaperStatus($this->u_estrin);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->title, "Scalable Timers for Soft State Protocols");
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols", "status:submit" => 1]), $paper1));
        xassert(!$ps->has_change_at("title"));
        xassert($ps->execute_save());

        $this->conf->save_refresh_setting("sub_update", $old_sub_update);
    }

    function test_save_finalize_no_edit() {
        // Check that information is not leaked about papers by trying
        // to save them

        $ps = new PaperStatus($this->u_chair);
        xassert($ps->save_paper_json(json_decode('{"pid":1,"draft":true,"title":"Scalable Timers for Soft State Protocols"}')));
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert($paper1->timeSubmitted === 0);

        $old_sub_update = $this->conf->setting("sub_update");
        $this->conf->save_refresh_setting("sub_update", Conf::$now - 1000);
        xassert(!$this->conf->unnamed_submission_round()->time_update(true));
        xassert($this->conf->unnamed_submission_round()->time_submit(true));
        $nonpc = $this->conf->checked_user_by_email("waldvogel@tik.ee.ethz.ch");

        $ps = new PaperStatus($nonpc);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols 2", "status:submit" => 1]), $paper1));
        xassert(!$ps->has_change_at("title"));
        xassert_eqq($ps->decorated_feedback_text(), "You aren’t allowed to view submission #1\n");

        $ps = new PaperStatus($nonpc);
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->title, "Scalable Timers for Soft State Protocols");
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["title" => "Scalable Timers for Soft State Protocols", "status:submit" => 1]), $paper1));
        xassert(!$ps->has_change_at("title"));
        xassert_eqq($ps->decorated_feedback_text(), "You aren’t allowed to view submission #1\n");

        $this->conf->save_refresh_setting("sub_update", $old_sub_update);
    }

    function test_save_decision() {
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->outcome, 0);
        $modtime = $paper1->timeModified;

        // chair can change decision
        $ps = new PaperStatus($this->u_chair);
        xassert($ps->save_paper_json((object) ["pid" => 1, "decision" => "accepted"]));

        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->outcome, 1);
        xassert_eqq($paper1->timeModified, $modtime);

        $ps = new PaperStatus($this->u_chair);
        xassert($ps->save_paper_json((object) ["pid" => 1, "decision" => "unknown"]));

        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->outcome, 0);
        xassert_eqq($paper1->timeModified, $modtime);

        // author can’t change decision
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->save_paper_json((object) ["pid" => 1, "decision" => "accepted"]));

        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($paper1->outcome, 0);
        xassert_eqq($paper1->timeModified, $modtime);
    }

    function test_save_options() {
        $this->newpaper1 = $this->newpaper1->reload();
        xassert($this->newpaper1->timeSubmitted > 0);

        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10", "has_opt1" => "1", "has_status:submit" => 1]), $this->newpaper1));
        xassert_array_eqq($ps->changed_keys(), ["calories", "status"], true);
        xassert($ps->execute_save());
        xassert_paper_status($ps);

        $ps = new PaperStatus($this->u_estrin);
        xassert(!$ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "10xxxxx", "has_opt1" => "1"]), $this->newpaper1));
        xassert_array_eqq($ps->changed_keys(), [], true);
        xassert($ps->has_error_at("opt1"));

        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web(new Qrequest("POST", ["opt1" => "none", "has_opt1" => "1"]), $this->newpaper1));
        xassert_array_eqq($ps->changed_keys(), ["calories"], true);
        xassert_paper_status($ps);
        // ...but do not save!!

        $newpaper = $this->u_estrin->checked_paper_by_id($this->newpaper1->paperId);
        xassert($newpaper);
        xassert_eqq($newpaper->title, "New paper");
        xassert_eqq($newpaper->abstract, "This is an abstract");
        xassert_eqq($newpaper->abstract(), "This is an abstract");
        xassert_eqq(count($newpaper->author_list()), 1);
        $aus = $newpaper->author_list();
        xassert_eqq($aus[0]->firstName, "Bobby");
        xassert_eqq($aus[0]->lastName, "Flay");
        xassert_eqq($aus[0]->email, "flay@_.com");
        xassert($newpaper->timeSubmitted <= 0);
        xassert($newpaper->timeWithdrawn <= 0);
        xassert_eqq($newpaper->option(1)->value, 10);
    }

    function test_save_new_authors() {
        $qreq = new Qrequest("POST", ["status:submit" => 1, "has_opt2" => "1", "opt2:1" => "new", "title" => "Paper about mantis shrimp", "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:1:affiliation" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission:file", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $qreq->set_file("opt2:1:file", ["name" => "attachment1.pdf", "type" => "application/pdf", "content" => "%PDF-whatever\n", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web($qreq, null));
        xassert($ps->has_change_at("title"));
        xassert($ps->has_change_at("abstract"));
        xassert($ps->has_change_at("authors"));
        xassert($ps->has_change_at("submission"));
        xassert($ps->execute_save());
        xassert_paper_status($ps);
        $this->pid2 = $ps->paperId;
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert($nprow1);
        xassert_eqq($nprow1->title, "Paper about mantis shrimp");
        xassert_eqq($nprow1->abstract, "They see lots of colors.");
        xassert_eqq($nprow1->abstract(), "They see lots of colors.");
        xassert_eqq(count($nprow1->author_list()), 1);
        $aus = $nprow1->author_list();
        xassert_eqq($aus[0]->firstName, "David");
        xassert_eqq($aus[0]->lastName, "Attenborough");
        xassert_eqq($aus[0]->email, "atten@_.com");
        $attenu = $this->conf->user_by_email("atten@_.com");
        xassert_eqq($nprow1->conflict_type($attenu), CONFLICT_AUTHOR);
        xassert_eqq($attenu->roles & Contact::ROLE_DBMASK, 0);
        xassert_eqq($attenu->disabled_flags(), Contact::CF_PLACEHOLDER);
        xassert($nprow1->timeSubmitted > 0);
        xassert($nprow1->timeWithdrawn <= 0);
        xassert(!$nprow1->option(1));
        xassert(!!$nprow1->option(2));
        xassert(count($nprow1->force_option(0)->documents()) === 1);
        xassert_eqq($nprow1->force_option(0)->document(0)->text_hash(), "sha2-d16c7976d9081368c7dca2da3a771065c3222069a1ad80dcd99d972b2efadc8b");
        xassert(count($nprow1->option(2)->documents()) === 1);
        xassert_eqq($nprow1->option(2)->document(0)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");
        xassert($nprow1->has_author($this->u_estrin));
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "estrin@usc.edu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu");
        xassert(ConfInvariants::test_document_inactive($this->conf));
    }

    function test_save_missing_required_fields() {
        $np = $this->conf->fetch_ivalue("select count(*) from Paper");

        $qreq = new Qrequest("POST", ["status:submit" => 1, "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:1:affiliation" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission:file", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null);
        xassert($ps->has_error_at("title"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");
        $ps->abort_save();
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $np1 = $this->conf->fetch_ivalue("select count(*) from Paper");
        xassert_eqq($np, $np1);

        $qreq = new Qrequest("POST", ["status:submit" => 1, "title" => "", "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:1:affiliation" => "BBC", "abstract" => "They see lots of colors.", "has_submission" => "1"]);
        $qreq->set_file("submission:file", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null);
        xassert($ps->has_error_at("title"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");
        $ps->abort_save();
        xassert(ConfInvariants::test_document_inactive($this->conf));

        $qreq = new Qrequest("POST", ["status:submit" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:1:affiliation" => "BBC", "has_submission" => "1"]);
        $qreq->set_file("submission:file", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null);
        xassert($ps->has_error_at("abstract"));
        xassert_eqq(count($ps->error_fields()), 1);
        xassert_eqq($ps->feedback_text($ps->error_list()), "Entry required\n");
        $ps->abort_save();
        xassert(ConfInvariants::test_document_inactive($this->conf));
    }

    function test_save_no_abstract_submit_ok() {
        $this->conf->set_opt("noAbstract", 1);
        $this->conf->invalidate_caches("options");

        $qreq = new Qrequest("POST", ["status:submit" => 1, "title" => "Another Mantis Shrimp Paper", "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:1:affiliation" => "BBC", "has_submission" => "1"]);
        $qreq->set_file("submission:file", ["name" => "amazing-sample.pdf", "tmp_name" => SiteLoader::find("etc/sample.pdf"), "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
        $ps = new PaperStatus($this->u_estrin);
        $ps->prepare_save_paper_web($qreq, null);
        xassert(!$ps->has_error_at("abstract"));
        xassert_eqq(count($ps->error_fields()), 0);
        xassert_eqq($ps->feedback_text($ps->error_list()), "");
        $ps->abort_save();

        $this->conf->set_opt("noAbstract", null);
        $this->conf->invalidate_caches("options");
    }

    function test_save_abstract_format() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->abstract, "They see lots of colors.");

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "abstract" => " They\nsee\r\nlots of\n\n\ncolors. \n\n\n"]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["abstract"], true);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->abstract, "They\nsee\nlots of\n\n\ncolors.");
    }

    function test_save_collaborators() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "");
        $this->conf->save_refresh_setting("sub_collab", 1);

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "collaborators" => "  John Fart\rMIT\n\nButt Man (UCLA)"]), $nprow1);
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->changed_keys(), ["collaborators"], true);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "John Fart\nAll (MIT)\n\nButt Man (UCLA)");
    }

    function test_save_collaborators_normalization() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "collaborators" => "Sal Stolfo, Guofei Gu, Manos Antonakakis, Roberto Perdisci, Weidong Cui, Xiapu Luo, Rocky Chang, Kapil Singh, Helen Wang, Zhichun Li, Junjie Zhang, David Dagon, Nick Feamster, Phil Porras."]), $nprow1);
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->changed_keys(), ["collaborators"], true);

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
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "collaborators" => $long_collab]), $this->u_estrin->checked_paper_by_id($this->pid2));
        xassert_paper_status($ps);
        xassert_array_eqq($ps->changed_keys(), ["collaborators"], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators, null);
        xassert_eqq($nprow1->dataOverflow, '{"collaborators":' . json_encode_db($long_collab) . '}');
        xassert_eqq($nprow1->collaborators(), $long_collab);

        // the collaborators are short again
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "collaborators" => "One guy (MIT)"]), $nprow1);
        xassert_paper_status($ps);
        xassert_array_eqq($ps->changed_keys(), ["collaborators"], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->collaborators(), "One guy (MIT)");
        xassert_eqq($nprow1->dataOverflow, null);

        $this->conf->save_refresh_setting("sub_collab", null);
    }

    function test_save_topics() {
        $this->conf->qe("insert into TopicArea (topicName) values ('Cloud computing'), ('Architecture'), ('Security'), ('Cloud networking')");
        $this->conf->save_refresh_setting("has_topics", 1);

        $tset = $this->conf->topic_set();
        xassert_eqq($tset[1], "Cloud computing");
        xassert_eqq($tset[2], "Architecture");
        xassert_eqq($tset[3], "Security");
        xassert_eqq($tset[4], "Cloud networking");

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($nprow1->topic_list(), []);

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["Cloud computing"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => (object) ["Cloud computing" => true, "Security" => true]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1, 3]);

        xassert_search($this->u_chair, "topic:\"cloud computing\"", "{$this->pid2}");

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => [2, 4]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 4]);

        // extended topic saves
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["architecture", "security"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 3]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["fartchitecture"]
        ]);
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("topics"), "Topic ‘fartchitecture’ not found\n");
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), []); // XXX should be unchanged

        $this->conf->topic_set()->set_auto_add(true);
        $this->conf->options()->refresh_topics();
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2,
            "topics" => ["fartchitecture", "architecture"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["topics"]);
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [2, 5]);

        $qreq = new Qrequest("POST", ["status:submit" => 1, "has_topics" => 1, "topics:1" => 1, "topics:5" => 1]);
        $ps->save_paper_web($qreq, $nprow1);
        xassert(!$ps->has_problem());
        $nprow1->invalidate_topics();
        xassert_eqq($nprow1->topic_list(), [1, 5]);
        $this->conf->topic_set()->set_auto_add(false);
    }

    /** @param PaperInfo $prow
     * @return list<int> */
    static function pc_conflict_keys($prow) {
        $uids = [];
        foreach ($prow->conflict_list() as $cu) {
            if ($cu->user->is_pc_member())
                $uids[] = $cu->contactId;
        }
        return $uids;
    }

    /** @param PaperInfo $prow
     * @return associative-array<int,int> */
    static function pc_conflict_types($prow) {
        $ctypes = [];
        foreach ($prow->conflict_list() as $cu) {
            if ($cu->user->is_pc_member())
                $ctypes[$cu->contactId] = $cu->conflictType;
        }
        return $ctypes;
    }

    function test_prop_with_overflow() {
        $p = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ab1 = $p->abstract;
        xassert_eqq($p->abstract, "They\nsee\nlots of\n\n\ncolors.");
        xassert_eqq($p->abstract(), "They\nsee\nlots of\n\n\ncolors.");
        xassert_eqq($p->prop_with_overflow("abstract"), "They\nsee\nlots of\n\n\ncolors.");

        $p->set_prop("abstract", null);
        $p->set_overflow_prop("abstract", "Paper about eating mantis shrimp");

        xassert_eqq($p->abstract, null);
        xassert_eqq($p->abstract(), "Paper about eating mantis shrimp");
        xassert_eqq($p->prop_with_overflow("abstract"), "Paper about eating mantis shrimp");
        xassert_eqq($p->base_prop("abstract"), "They\nsee\nlots of\n\n\ncolors.");
        xassert_eqq($p->base_prop_with_overflow("abstract"), "They\nsee\nlots of\n\n\ncolors.");

        $this->conf->qe("update Paper set abstract=?, dataOverflow=? where paperId=?",
            $p->abstract, $p->dataOverflow, $p->paperId);

        $p = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq($p->abstract, null);
        xassert_eqq($p->abstract(), "Paper about eating mantis shrimp");
        xassert_eqq($p->prop_with_overflow("abstract"), "Paper about eating mantis shrimp");

        $p->set_prop("abstract", "Mother");
        xassert_eqq($p->abstract, "Mother");
        xassert_eqq($p->abstract(), "Paper about eating mantis shrimp");
        xassert_eqq($p->prop_with_overflow("abstract"), "Paper about eating mantis shrimp");
        xassert_eqq($p->base_prop_with_overflow("abstract"), "Paper about eating mantis shrimp");

        $p->set_overflow_prop("abstract", "Assbutt");
        xassert_eqq($p->abstract(), "Assbutt");
        xassert_eqq($p->prop_with_overflow("abstract"), "Assbutt");
        xassert_eqq($p->base_prop_with_overflow("abstract"), "Paper about eating mantis shrimp");

        $this->conf->qe("update Paper set abstract=?, dataOverflow=? where paperId=?",
            $ab1, null, $p->paperId);
    }

    function test_save_pc_conflicts() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId]);
        xassert_eqq(self::pc_conflict_types($nprow1), [$this->u_estrin->contactId => CONFLICT_CONTACTAUTHOR]);

        $ps = new PaperStatus($this->u_estrin);
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
        xassert_array_eqq($ps->changed_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1),
            [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->u_sally->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => []
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email, "notpc@no.com"]
        ]);
        xassert($ps->has_problem());
        xassert_paper_status($ps, MessageSet::WARNING);
        xassert_array_eqq($ps->changed_keys(), [], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), Conflict::CT_DEFAULT);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "advisor"]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem
        xassert_array_eqq($ps->changed_keys(), ["pc_conflicts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 4);

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "advisor", $this->u_estrin->email => false, $this->u_chair->email => false]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem
        xassert_array_eqq($ps->changed_keys(), [], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 4);
    }

    function test_save_pinned_pc_conflicts() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);

        // non-chair cannot pin conflicts
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert(!$ps->has_problem()); // XXX should have problem

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_varghese), 2);

        // chair can pin conflicts
        $psc = new PaperStatus($this->u_chair);
        $psc->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned advisor"]
        ]);
        xassert(!$psc->has_problem());
        xassert_eqq($psc->full_feedback_text(), "");

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

    function test_save_pc_conflicts_disabled() {
        xassert_eqq($this->conf->setting("sub_pcconf"), 1);
        $this->conf->save_refresh_setting("sub_pcconf", null);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);

        // chair can change conflicts
        $psc = new PaperStatus($this->u_chair);
        $psc->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_sally->email => "collaborator", $this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert(!$psc->has_problem());
        xassert_eqq($psc->decorated_feedback_text(), "");

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->u_sally->contactId]);

        // author cannot change conflicts
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert($ps->has_problem());
        xassert_eqq($ps->decorated_feedback_text(), "PC conflicts: Changes ignored\n");

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->u_sally->contactId]);

        // author can list conflicts without warning if no change
        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator", $this->u_sally->email => "collaborator"]
        ]);
        xassert(!$ps->has_problem());

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->u_sally->contactId]);

        // restore expected conflicts
        $this->conf->save_refresh_setting("sub_pcconf", 1);
        $psc = new PaperStatus($this->u_chair);
        $psc->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => [$this->u_varghese->email => "pinned collaborator"]
        ]);
        xassert(!$psc->has_problem());
    }

    function test_save_contacts_no_remove_self() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "estrin@usc.edu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu");
        xassert_eqq(count($nprow1->author_list()), 1);
        xassert_eqq(($nprow1->author_list())[0]->email, "atten@_.com");

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => []
        ]);
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("contacts"), "You can’t remove yourself from the submission’s contacts
    (Ask another contact to remove you.)\n");

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "estrin@usc.edu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu");

        // old style of request
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1, "contacts:1:email" => "estrin@usc.edu", "has_contacts:1:active" => 1]), $nprow1);
        xassert($ps->has_problem());
        xassert_eqq($ps->feedback_text_at("contacts"), "You can’t remove yourself from the submission’s contacts
    (Ask another contact to remove you.)\n");

        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => ["estrin@usc.edu"]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), [], true);

        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "estrin@usc.edu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu");
    }

    function test_save_contacts_ignore_empty() {
        $ps = new PaperStatus($this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1, "contacts:1:email" => "estrin@usc.edu", "contacts:1:active" => 1, "contacts:2:email" => "", "contacts:2:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), [], true);
    }

    function test_save_contacts_creates_user() {
        $ps = new PaperStatus($this->u_estrin);
        xassert(!$this->conf->fresh_user_by_email("festrin@fusc.fedu"));
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "contacts" => ["estrin@usc.edu", (object) ["email" => "festrin@fusc.fedu", "name" => "Feborah Festrin"]]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);

        $new_user = $this->conf->fresh_user_by_email("festrin@fusc.fedu");
        xassert(!!$new_user);
        xassert_eqq($new_user->firstName, "Feborah");
        xassert_eqq($new_user->lastName, "Festrin");
        $this->festrin_cid = $new_user->contactId;
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert($nprow1->has_author($new_user));
    }

    function test_save_contacts_creates_user_2() {
        $ps = new PaperStatus($this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert(!$this->conf->fresh_user_by_email("gestrin@gusc.gedu"));
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1, "contacts:1:email" => "estrin@usc.edu", "contacts:1:active" => 1, "contacts:2:email" => "festrin@fusc.fedu", "has_contacts:2:active" => 1, "contacts:3:email" => "gestrin@gusc.gedu", "contacts:3:name" => "Geborah Gestrin", "contacts:3:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);

        $new_user2 = $this->conf->fresh_user_by_email("gestrin@gusc.gedu");
        xassert(!!$new_user2);
        $this->gestrin_cid = $new_user2->contactId;
        xassert_eqq($new_user2->firstName, "Geborah");
        xassert_eqq($new_user2->lastName, "Gestrin");

        $nprow1->invalidate_conflicts();
        xassert(!$nprow1->has_author($this->festrin_cid));
        xassert($nprow1->has_author($new_user2));
    }

    function test_save_contacts_partial_update() {
        $ps = new PaperStatus($this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), [], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu gestrin@gusc.gedu");
        $u_atten = $this->conf->checked_user_by_email("atten@_.com");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR);

        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1, "contacts:1:email" => "atten@_.com", "contacts:1:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu gestrin@gusc.gedu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu gestrin@gusc.gedu");
        $u_atten = $this->conf->checked_user_by_email("ATTEN@_.coM");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => 1, "contacts:1:email" => "gestrin@gusc.gedu", "has_contacts:1:active" => 1, "contacts:2:email" => "atten@_.com", "has_contacts:2:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);
        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu");
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
    }

    function test_resolve_primary() {
        $this->conf->qe("update ContactInfo set primaryContactId=? where contactId=?", $this->festrin_cid, $this->gestrin_cid);
        $this->conf->qe("update ContactInfo set cflags=cflags|? where contactId=?", Contact::CF_PRIMARY, $this->festrin_cid);
        $this->conf->qe("insert into ContactPrimary set contactId=?, primaryContactId=?", $this->gestrin_cid, $this->festrin_cid);
        $this->conf->invalidate_caches("users");
        xassert_eqq($this->conf->resolve_primary_emails(["Gestrin@GUSC.gedu", "festrin@fusc.fedu"]), ["festrin@fusc.fedu", "festrin@fusc.fedu"]);
    }

    function test_save_authors_resolve_primary() {
        $u_atten = $this->conf->user_by_email("atten@_.com");

        // input: gestrin -linksto-> festrin
        $ps = new PaperStatus($this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com", "authors:2:name" => "Geborah Gestrin", "authors:2:email" => "gestrin@gusc.gedu"]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["authors", "contacts"], true);

        // *primary* contacts are atten, estrin, festrin (not gestrin)
        $nprow1 = $this->conf->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS | TESTSC_DISABLED), "atten@_.com estrin@usc.edu festrin@fusc.fedu gestrin@gusc.gedu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_AUTHOR);
        xassert_eqq($nprow1->conflict_type($this->gestrin_cid), CONFLICT_AUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

        // primary gets mail, secondary doesn’t
        MailChecker::clear();
        $ps2 = (new PaperStatus($this->u_estrin))
            ->set_notify(true)
            ->set_notify_authors(true);
        $ps2->save_paper_web(new Qrequest("POST", ["abstract" => "They see lots and LOTS of colors."]), $nprow1);
        $ps2->notify_followers();
        xassert(!$ps2->has_problem());
        xassert_array_eqq($ps2->changed_keys(), ["abstract"], true);
        MailChecker::check_db("t_paperstatus-primary-01");

        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_authors" => "1", "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com"]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["authors", "contacts"], true);
        $nprow1 = $this->conf->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
    }

    function test_save_contacts_resolve_primary() {
        $u_atten = $this->conf->user_by_email("atten@_.com");

        $ps = new PaperStatus($this->u_estrin);
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu");
        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => "1", "contacts:1:email" => "gestrin@gusc.gedu", "contacts:1:active" => "1"]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu festrin@fusc.fedu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->gestrin_cid), 0);

        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => "1", "contacts:1:email" => "gestrin@gusc.gedu", "has_contacts:1:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), [], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu festrin@fusc.fedu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->gestrin_cid), 0);

        $ps->save_paper_web(new Qrequest("POST", ["status:submit" => 1, "has_contacts" => "1", "contacts:1:email" => "festrin@fusc.fedu", "has_contacts:1:active" => 1]), $nprow1);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["contacts"], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(sorted_conflicts($nprow1, TESTSC_CONTACTS), "atten@_.com estrin@usc.edu");
        xassert_eqq($nprow1->conflict_type($u_atten), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($nprow1->conflict_type($this->festrin_cid), 0);
        xassert_eqq($nprow1->conflict_type($this->gestrin_cid), 0);
    }

    function test_save_conflicts_resolve_primary() {
        $nprow1 = $this->u_estrin->checked_paper_by_id($this->pid2);
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId]);

        $this->conf->qe("update ContactInfo set roles=1 where contactId=?", $this->festrin_cid);
        $this->conf->invalidate_caches("pc");

        $ps = new PaperStatus($this->u_estrin);
        $ps->save_paper_json((object) [
            "id" => $this->pid2, "pc_conflicts" => ["gestrin@gusc.gedu" => true]
        ]);
        xassert(!$ps->has_problem());
        xassert_array_eqq($ps->changed_keys(), ["pc_conflicts"], true);

        $nprow1->invalidate_conflicts();
        xassert_eqq(self::pc_conflict_keys($nprow1), [$this->u_estrin->contactId, $this->u_varghese->contactId, $this->festrin_cid]);
    }

    function test_resolve_secondary_contact() {
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->prepare_save_paper_web((new Qrequest("POST", [
            "status:submit" => 1, "title" => "Fun in the sun with G",
            "abstract" => "This is an abstract\r\n",
            "has_authors" => "1", "authors:1:name" => "Ethan Iverson",
            "authors:1:email" => "estrin@usc.edu",
            "has_contacts" => "1", "contacts:1:email" => "gestrin@gusc.gedu",
            "has_submission" => 1
        ]))->set_file_content("submission:file", "%PDF-2", null, "application/pdf"), null));
        xassert_paper_status($ps);
        xassert($ps->execute_save());

        $prow = $this->conf->checked_paper_by_id($ps->paperId);
        xassert(!!$prow);
        xassert($prow->conflict_type($this->gestrin_cid) === 0);
        xassert($prow->conflict_type($this->festrin_cid) >= CONFLICT_CONTACTAUTHOR);
    }

    function test_preserve_secondary_contact() {
        $u_gestrin = $this->conf->user_by_id($this->gestrin_cid);
        $ps = new PaperStatus($u_gestrin);
        xassert($ps->prepare_save_paper_web((new Qrequest("POST", [
            "status:submit" => 1, "title" => "Fun in the sun with G",
            "abstract" => "This is an abstract\r\n",
            "has_authors" => "1", "authors:1:name" => "Ethan Iverson",
            "authors:1:email" => "estrin@usc.edu", "has_submission" => 1
        ]))->set_file_content("submission:file", "%PDF-2", null, "application/pdf"), null));
        xassert_paper_status($ps);
        xassert($ps->execute_save());

        $prow = $this->conf->checked_paper_by_id($ps->paperId);
        xassert(!!$prow);
        xassert($prow->conflict_type($u_gestrin) >= CONFLICT_CONTACTAUTHOR);
    }

    function test_change_primary_existing_authors() {
        $u_shenker = $this->conf->checked_user_by_email("shenker@parc.xerox.com");
        $u_shenker2 = Contact::make_email($this->conf, "shenker2@parc.xerox.com")->store();
        $u_shenker3 = Contact::make_email($this->conf, "shenker3@parc.xerox.com")->store();
        $u_bajaj = $this->conf->checked_user_by_email("bajaj@parc.xerox.com");
        $u_bajaj2 = Contact::make_email($this->conf, "bajaj2@xerox-parc.net")->store();
        $this->conf->qe("insert into PaperConflict set paperId=30, contactId=?, conflictType=?",
            $u_shenker2->contactId, CONFLICT_CONTACTAUTHOR);

        $p30 = $this->u_estrin->checked_paper_by_id(30);
        // no primaries
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker3), 0);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);

        (new ContactPrimary)->link($u_bajaj, $u_bajaj2);
        // bajaj -> bajaj2
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker3), 0);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), CONFLICT_AUTHOR);

        (new ContactPrimary)->link($u_bajaj, null);
        // no primaries
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker3), 0);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);

        (new ContactPrimary)->link($u_shenker, $u_shenker2);
        // shenker -> shenker2
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker3), 0);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);

        (new ContactPrimary)->link($u_shenker2, $u_shenker3);
        // shenker -> shenker3, shenker2 -> shenker3
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), 0);
        xassert_eqq($p30->conflict_type($u_shenker3), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);

        (new ContactPrimary)->link($u_shenker2, null);
        // shenker -> shenker3
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), 0);
        xassert_eqq($p30->conflict_type($u_shenker3), CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);

        (new ContactPrimary)->link($u_shenker, null);
        // no primaries
        $p30->invalidate_conflicts();
        xassert_eqq($p30->conflict_type($u_shenker), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_shenker2), 0);
        xassert_eqq($p30->conflict_type($u_shenker3), CONFLICT_CONTACTAUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj), CONFLICT_AUTHOR);
        xassert_eqq($p30->conflict_type($u_bajaj2), 0);
    }

    function test_content_text_signature() {
        $doc = DocumentInfo::make_content($this->conf, "ABCdefGHIjklMNO");
        xassert_eqq($doc->content_text_signature(), "starts with “ABCdefGH”");
        $doc = DocumentInfo::make_content($this->conf, "\x02\x00A\x80BCdefGHIjklMN");
        xassert_eqq($doc->content_text_signature(), "starts with “\\x02\\x00A\\x80BCde”");
        $doc = DocumentInfo::make_content($this->conf, "");
        xassert_eqq($doc->content_text_signature(), "is empty");

        $doc = DocumentInfo::make_content_file($this->conf, "/tmp/this-file-is-expected-not-to-exist.png.zip");
        ++Xassert::$disabled;
        $s = $doc->content_text_signature();
        --Xassert::$disabled;
        xassert_eqq($s, "cannot be loaded");
    }

    function test_banal() {
        $spects = $this->conf->setting("sub_banal") ?? Conf::$now - 10;
        $spects = max($spects, @filemtime(SiteLoader::find("src/banal")));
        $this->conf->save_setting("sub_banal", $spects, "letter;30;;6.5x9in");
        $this->conf->invalidate_caches("options");
        xassert_eq($this->conf->format_spec(DTYPE_SUBMISSION)->timestamp, $spects);

        $ps = new PaperStatus($this->conf->root_user());
        $ps->on_document_import(function ($dj, $opt, $pstatus) {
            if (is_string($dj->content_file ?? null) && !($dj instanceof DocumentInfo)) {
                $dj->content_file = SiteLoader::$root . "/" . $dj->content_file;
            }
        });
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
        ++$spects;
        $this->conf->save_setting("sub_banal", $spects, "letter;30;;7.5x9in");
        $this->conf->invalidate_caches("options");

        // that requires rerunning banal because cached result was truncated
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
        ++$spects;
        $this->conf->save_setting("sub_banal", $spects, "letter;1;;7.5x9in");
        $this->conf->invalidate_caches("options");

        $paper3->invalidate_documents();
        $doc = $paper3->document(DTYPE_SUBMISSION);
        $cf_nec->check_document($doc);
        xassert_eqq(join(" ", $cf_nec->problem_fields()), "pagelimit");
        xassert(!$cf_nec->need_recheck());
        xassert(!$cf_nec->run_attempted());

        ++$spects;
        $this->conf->save_setting("sub_banal", $spects, "letter;2;;7.5x9in");
    }

    function test_option_name_parens() {
        $options = $this->conf->setting_json("options");
        xassert(!array_filter((array) $options, function ($o) { return $o->id === 3; }));
        $options[] = (object) ["id" => 3, "name" => "Supervisor(s)", "type" => "text", "order" => 3];
        $this->conf->save_setting("options", 1, json_encode($options));
        $this->conf->invalidate_caches("options");

        $ps = new PaperStatus($this->conf->root_user());
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
        $qreq = TestQreq::get_page("paper/0123")->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/123");

        $qreq = TestQreq::get_page("paper", ["p" => "0123"])->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/123");

        $qreq = TestQreq::get_page("review/0123")->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/review/123");

        $qreq = TestQreq::get_page("review", ["p" => "0123"])->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/review/123");

        $qreq = TestQreq::get_page("paper/3")->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof PaperRequest);
        $estrin_14_rid = $this->conf->checked_paper_by_id(14)->checked_review_by_user($this->u_estrin)->reviewId;

        $qreq = TestQreq::get_page("paper", ["r" => $estrin_14_rid])->set_user($this->u_estrin);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/14?r={$estrin_14_rid}");

        $qreq = TestQreq::get_page("paper", ["r" => $estrin_14_rid])->set_user($this->u_varghese);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof Redirection);
        xassert_eqq($pr->url, "/paper/14?r={$estrin_14_rid}");

        $qreq = TestQreq::get_page("paper", ["r" => $estrin_14_rid])->set_user($this->u_nobody);
        $pr = PaperRequest::make($qreq, false);
        xassert($pr instanceof FailureReason);
        xassert($pr["missingId"]);
    }

    function test_conditional_fields() {
        $sv = (new SettingValues($this->u_chair))->add_json_string('{
    "sf": [
        {
            "id": "new", "name": "Submission Type", "type": "radio",
            "order": 1,
            "values": [
                {"id": 1, "name": "First"},
                {"id": 2, "name": "Second"}
            ]
        },
        {
            "id": "new", "name": "First Text", "type": "text",
            "order": 2, "presence": "custom", "condition": "SubTyp:First",
            "required": "register"
        }
    ],
    "sf_abstract": "optional"
}');
        xassert($sv->execute());
        $o1 = $this->conf->options()->find("Submission Type");
        $o2 = $this->conf->options()->find("First Text");

        $ps = new PaperStatus($this->u_sally);
        $ps->save_paper_json((object) [
            "id" => "new",
            "title" => "Conditional Field Test",
            "abstract" => "This is my submission",
            "authors" => [
                (object) ["name" => "Deborah", "email" => $this->u_sally->email]
            ],
            "submission_type" => "First",
            "first_text" => "Feck"
        ]);
        xassert_paper_status($ps);
        $prow = $this->u_sally->checked_paper_by_id($ps->paperId);
        xassert_eqq($prow->title(), "Conditional Field Test");
        xassert_eqq($prow->option($o1)->value, 1);
        xassert_eqq($prow->option($o2)->data(), "Feck");

        // Value of non-included conditional field ignored
        $ps->save_paper_json((object) [
            "id" => $prow->paperId,
            "submission_type" => "Second",
            "first_text" => "Fick"
        ]);
        xassert_paper_status($ps);
        $prow = $this->u_sally->checked_paper_by_id($ps->paperId);
        xassert_eqq($prow->title(), "Conditional Field Test");
        xassert_eqq($prow->option($o1)->value, 2);
        xassert_eqq($prow->option($o2)->data(), "Feck");
    }

    function test_if_unmodified_since() {
        $p2 = $this->conf->checked_paper_by_id(2);
        $p2au = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $p2_title = $p2->title();
        $p2_abstract = $p2->abstract();
        $p2_modified_at = $p2->timeModified;

        $ps = new PaperStatus($p2au);
        $x = $ps->save_paper_web(new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "{$p2_title}?",
            "abstract" => $p2_abstract,
            "status:if_unmodified_since" => $p2_modified_at
        ]), $p2);
        xassert_eqq($x, 2);

        $p2b = $this->conf->checked_paper_by_id(2);
        xassert_eqq($p2b->title, "{$p2_title}?");
        xassert_eqq($p2b->abstract, $p2_abstract);
        xassert_neqq($p2b->timeModified, $p2_modified_at);

        $ps = new PaperStatus($p2au);
        $qreq = new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "{$p2_title}??",
            "abstract" => "{$p2_abstract}??",
            "status:if_unmodified_since" => $p2_modified_at
        ]);
        $x = $ps->save_paper_web($qreq, $p2b);
        xassert_eqq($x, false);
        xassert($ps->has_error_at("status:if_unmodified_since"));
        $fl = $ps->changed_fields_qreq($qreq, $p2b);
        xassert_eqq(count($fl), 2);
        xassert_eqq($fl[0]->formid, "title");
        xassert_eqq($fl[1]->formid, "abstract");

        $ps = new PaperStatus($p2au);
        $qreq = new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "{$p2_title}??",
            "abstract" => "{$p2_abstract}??",
            "status:if_unmodified_since" => $p2_modified_at,
            "status:changed" => "title",
            "status:unchanged" => "abstract"
        ]);
        $x = $ps->save_paper_web($qreq, $p2b);
        xassert_eqq($x, false);
        xassert($ps->has_error_at("status:if_unmodified_since"));
        $ps->strip_unchanged_fields_qreq($qreq, $p2b);
        $fl = $ps->changed_fields_qreq($qreq, $p2b);
        xassert_eqq(count($fl), 1);
        xassert_eqq($fl[0]->formid, "title");
    }

    function test_submit_noneditable_submission() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => "submission",
            "sf/1/edit_condition" => "NONE"
        ]);
        xassert($sv->execute());

        $p2au = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $ps = new PaperStatus($p2au);
        $qreq = new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "This paper should not be submitted",
            "abstract" => "Though it has an abstract",
            "has_authors" => "1",
            "authors:1:name" => "David Attenborough", "authors:1:email" => "atten@_.com",
            "authors:2:name" => "Geborah Gestrin", "authors:2:email" => "gestrin@gusc.gedu"
        ]);
        xassert($ps->prepare_save_paper_web($qreq, null));
        xassert_eqq($ps->decorated_feedback_text(), "Submission: Entry required to complete submission\n");
        xassert($ps->execute_save());

        $newprow = $this->conf->checked_paper_by_id($ps->paperId);
        xassert(!!$newprow);
        xassert(!$this->conf->option_by_id(DTYPE_SUBMISSION)->test_editable($newprow));
        xassert_le($newprow->timeSubmitted ?? 0, 0);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => "submission",
            "sf/1/edit_condition" => ""
        ]);
        xassert($sv->execute());

        $newprow = $this->conf->checked_paper_by_id($ps->paperId);
        xassert($this->conf->option_by_id(DTYPE_SUBMISSION)->test_editable($newprow));
    }

    function test_new_paper_random_pids() {
        $this->conf->save_refresh_setting("random_pids", 1);
        $next_pid = $this->conf->fetch_ivalue("select max(paperId)+1 from Paper");

        $ntries = 0;
        while ($ntries < 20) {
            // Try 20 times to insert a paper. It's very unlikely all 20 random
            // IDs will be sequentially assigned
            $ps = new PaperStatus($this->u_estrin);
            $title = "New paper with random ID #" . ($ntries + 1);

            xassert($ps->prepare_save_paper_web((new Qrequest("POST", ["status:submit" => 1, "title" => $title, "abstract" => "This is an abstract\r\n", "has_authors" => "1", "authors:1:name" => "Ethan Iverson", "authors:1:email" => "iverson@_.com", "has_submission" => 1]))->set_file_content("submission:file", "%PDF-2", null, "application/pdf"), null));
            xassert_paper_status($ps);
            xassert($ps->has_change_at("title"));
            xassert($ps->has_change_at("abstract"));
            xassert($ps->has_change_at("authors"));
            xassert($ps->has_change_at("contacts"));
            xassert($ps->execute_save());
            xassert_paper_status_saved_nonrequired($ps);

            $newpaper = $this->u_estrin->checked_paper_by_id($ps->paperId);
            xassert($newpaper);
            xassert_eqq($newpaper->title, $title);
            xassert_eqq($newpaper->abstract, "This is an abstract");
            xassert_eqq($newpaper->abstract(), "This is an abstract");
            xassert_eqq(count($newpaper->author_list()), 1);
            $aus = $newpaper->author_list();
            xassert_eqq($aus[0]->firstName, "Ethan");
            xassert_eqq($aus[0]->lastName, "Iverson");
            xassert_eqq($aus[0]->email, "iverson@_.com");
            xassert($newpaper->timeSubmitted > 0);
            xassert($newpaper->timeWithdrawn <= 0);
            xassert_eqq($newpaper->conflict_type($this->u_estrin), CONFLICT_CONTACTAUTHOR);

            if ($ps->paperId !== $next_pid) {
                break;
            }
            ++$next_pid;
        }

        xassert_lt($ntries, 20);
    }

    function test_adopt_author_nea() {
        $u_agnes = Contact::make_email($this->conf, "agnes@martin.ca")->store();
        xassert_eqq($u_agnes->firstName, "");
        xassert_eqq($u_agnes->lastName, "");
        xassert_eqq($u_agnes->affiliation, "");

        $cdb_agnes = $this->conf->cdb_user_by_email("agnes@martin.ca");
        xassert(!$cdb_agnes || $cdb_agnes->firstName === "");

        $ps = new PaperStatus($u_agnes);
        $ps->save_paper_web(new Qrequest("POST", [
            "title" => "I Love the Whole World",
            "abstract" => "Beauty and perfection are the same. They never occur without happiness.",
            "has_authors" => "1",
            "authors:1:name" => "Agnes Martin",
            "authors:1:email" => "agnes@martin.ca",
            "authors:1:affiliation" => "Taos Institute"
        ]), null);
        xassert_paper_status($ps);

        xassert_eqq($u_agnes->firstName, "Agnes");
        xassert_eqq($u_agnes->lastName, "Martin");
        xassert_eqq($u_agnes->affiliation, "Taos Institute");
        $u_agnes = $this->conf->fresh_user_by_email("agnes@martin.ca");
        xassert_eqq($u_agnes->firstName, "Agnes");
        xassert_eqq($u_agnes->lastName, "Martin");
        xassert_eqq($u_agnes->affiliation, "Taos Institute");
        $cdb_agnes = $this->conf->fresh_cdb_user_by_email("agnes@martin.ca");
        xassert(!$cdb_agnes || $cdb_agnes->firstName === "Agnes");
    }

    function test_newly_present_option() {
        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "sf": [
                {"name": "Include thing", "id": "new", "order": 100, "type": "checkbox"},
                {"name": "Thing", "id": "new", "order": 101, "type": "checkbox", "required": "submit", "condition": "IncThi:yes"}
            ]
        }');
        xassert($sv->execute());
        $o1 = $this->conf->options()->option_by_key("include_thing");
        $o2 = $this->conf->options()->option_by_key("thing");
        xassert($o1 && $o2);

        // can change submitted paper
        $p2 = $this->conf->checked_paper_by_id(2);
        $p2au = $this->conf->checked_user_by_email("micke@cdt.luth.se");
        $p2_title = $p2->title();
        xassert_gt($p2->timeSubmitted, 0);

        $ps = new PaperStatus($p2au);
        $x = $ps->save_paper_web(new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "{$p2_title}?"
        ]), $p2);
        xassert_eqq($x, 2);

        // make it so this submitted paper lacks a required field
        $this->conf->qe("insert into PaperOption set paperId=?, optionId=?, value=1", 2, $o1->id);

        // it should be OK to update the paper despite the missing field
        $p2 = $this->conf->checked_paper_by_id(2);
        $x = $ps->save_paper_web(new Qrequest("POST", [
            "status:submit" => 1,
            "title" => "{$p2_title}??"
        ]), $p2);
        xassert_eqq($x, 2);

        // remove the option
        $this->conf->qe("delete from PaperOption where paperId=? and optionId=?", 2, $o1->id);

        // but if the change we want to make *introduces* a new field that
        // isn't ready, reject the change altogether
        $p2 = $this->conf->checked_paper_by_id(2);
        $x = $ps->save_paper_web(new Qrequest("POST", [
            "status:submit" => 1,
            "title" => $p2_title,
            "has_opt{$o1->id}" => 1,
            "opt{$o1->id}" => 1
        ]), $p2);
        xassert_eqq($x, false);

        // remove newly-added options
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => $o1->id,
            "sf/1/delete" => 1,
            "sf/2/id" => $o2->id,
            "sf/2/delete" => 1
        ]);
        xassert($sv->execute());
    }

    function test_invariants_last() {
        ConfInvariants::test_all($this->conf);
    }
}
