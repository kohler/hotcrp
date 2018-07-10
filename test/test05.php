<?php
// test05.php -- HotCRP paper submission tests
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", $Now + 100);
$Conf->save_setting("sub_sub", $Now + 100);
$Conf->save_setting("opt.contentHashMethod", 1, "sha1");

// load users
$user_estrin = $Conf->user_by_email("estrin@usc.edu"); // pc
$user_nobody = new Contact;

$ps = new PaperStatus($Conf, $user_estrin);

$paper1a = $ps->paper_json(1);
xassert_eqq($paper1a->title, "Scalable Timers for Soft State Protocols");

$ps->save_paper_json((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
xassert(!$ps->has_error());

$paper1b = $ps->paper_json(1);

xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
$paper1b->title = $paper1a->title;
$paper1b->submitted_at = $paper1a->submitted_at;
xassert_eqq(json_encode($paper1b), json_encode($paper1a));

$doc = DocumentInfo::make_file_upload(-1, DTYPE_SUBMISSION, [
        "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
        "tmp_name" => "$ConfSitePATH/src/sample.pdf",
        "type" => "application/pdf"
    ]);
xassert_eqq($doc->content_text_signature(), "starts with “%PDF-1.2”");
$ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
xassert(!$ps->has_error());
if ($ps->has_error())
    error_log(join("\n", $ps->errors()));

$paper1c = $ps->paper_json(1);
xassert_eqq($paper1c->submission->hash, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");

$ps = new PaperStatus($Conf);

$paper2a = $ps->paper_json(2);
$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper2b = $ps->paper_json(2);
xassert_eqq($paper2b->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");

$ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"%PDF-goodbye\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->hash, "24aaabecc9fac961d52ae62f620a47f04facc2ce");
xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");

$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-again hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->hash, "30240fac8417b80709c72156b7f7f7ad95b34a2b");
xassert_eqq($paper2->final->hash, "e04c778a0af702582bb0e9345fab6540acb28e45");
xassert_eqq(bin2hex($ps->paper_row()->sha1), "e04c778a0af702582bb0e9345fab6540acb28e45");

// test new-style options storage
$options = $Conf->setting_json("options");
xassert(!array_filter((array) $options, function ($o) { return $o->id === 2; }));
$options[] = (object) ["id" => 2, "name" => "Attachments", "abbr" => "attachments", "type" => "attachments", "position" => 2];
$Conf->save_setting("options", 1, json_encode($options));
$Conf->invalidate_caches("options");

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}]}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper2 = $Conf->paperRow(2, $user_estrin);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
$d0psid = $docs[0]->paperStorageId;
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
$d1psid = $docs[1]->paperStorageId;

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"sha1\": \"4c18e2ec1d1e6d9e53f57499a66aeb691d687370\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}]}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper2 = $Conf->paperRow(2, $user_estrin);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 3);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
xassert_eqq($docs[0]->paperStorageId, $d0psid);
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[1]->paperStorageId, $d1psid);
xassert($docs[2]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[2]->paperStorageId, $d1psid);

// backwards compatibility
$Conf->qe("delete from PaperOption where paperId=2 and optionId=2");
$Conf->qe("insert into PaperOption (paperId,optionId,value,data) values (2,2,$d0psid,'0'),(2,2,$d1psid,'1')");
$paper2 = $Conf->paperRow(2, $user_estrin);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
xassert_eqq($docs[0]->paperStorageId, $d0psid);
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
xassert_eqq($docs[1]->paperStorageId, $d1psid);

// test SHA-256
$Conf->save_setting("opt.contentHashMethod", 1, "sha256");

$ps->save_paper_json(json_decode("{\"id\":3,\"submission\":{\"content\":\"%PDF-whatever\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());
$Conf->check_document_inactive_invariants();

$paper3 = $Conf->paperRow(3, $user_estrin);
xassert_eqq($paper3->sha1, "sha2-" . hex2bin("38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4"));
xassert_eqq($paper3->document(DTYPE_SUBMISSION)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

$paper3b = $ps->paper_json(3);
xassert_eqq($paper3b->submission->hash, "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

// test submitting a new paper
$pj = PaperSaver::apply_all(new Qrequest("POST", ["title" => "New paper", "abstract" => "This is an abstract\r\n", "auname1" => "Bobby Flay", "auemail1" => "flay@_.com"]), null, $user_estrin, "update");
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_json($pj));
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->execute_save_paper_json($pj));
xassert(!$ps->has_error());

$newpaper = $Conf->paperRow($ps->paperId, $user_estrin);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted <= 0);
xassert($newpaper->timeWithdrawn <= 0);

$pj = PaperSaver::apply_all(new Qrequest("POST", ["ready" => 1]), $newpaper, $user_estrin, "submit");
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_json($pj));
xassert_eqq(count($ps->diffs), 1);
xassert($ps->diffs["status"]);
xassert($ps->execute_save_paper_json($pj));
xassert(!$ps->has_error());

$newpaper = $Conf->paperRow($ps->paperId, $user_estrin);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted > 0);
xassert($newpaper->timeWithdrawn <= 0);

$pj = PaperSaver::apply_all(new Qrequest("POST", ["ready" => 0, "opt1" => "10", "has_opt1" => "1"]), $newpaper, $user_estrin, "update");
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_json($pj));
xassert_eqq(count($ps->diffs), 2);
xassert($ps->diffs["status"]);
xassert($ps->diffs["calories"]);
xassert($ps->execute_save_paper_json($pj));
xassert(!$ps->has_error());

$newpaper = $Conf->paperRow($ps->paperId, $user_estrin);
xassert($newpaper);
xassert_eqq($newpaper->title, "New paper");
xassert_eqq($newpaper->abstract, "This is an abstract");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "Bobby");
xassert_eqq($aus[0]->lastName, "Flay");
xassert_eqq($aus[0]->email, "flay@_.com");
xassert($newpaper->timeSubmitted <= 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert_eqq($newpaper->option(1)->value, 10);

$qreq = new Qrequest("POST", ["ready" => 1, "has_opt2" => "1", "has_opt2_new_1" => "1", "title" => "Paper about mantis shrimp", "auname1" => "David Attenborough", "auemail1" => "atten@_.com", "auaff1" => "BBC", "abstract" => "They see lots of colors."]);
$qreq->set_file("paperUpload", ["name" => "amazing-sample.pdf", "tmp_name" => "$ConfSitePATH/src/sample.pdf", "type" => "application/pdf", "error" => UPLOAD_ERR_OK]);
$qreq->set_file("opt2_new_1", ["name" => "attachment1.pdf", "type" => "application/pdf", "content" => "%PDF-whatever\n", "error" => UPLOAD_ERR_OK]);
$pj = PaperSaver::apply_all($qreq, null, $user_estrin, "submit");
$ps = new PaperStatus($Conf, $user_estrin);
xassert($ps->prepare_save_paper_json($pj));
xassert($ps->diffs["title"]);
xassert($ps->diffs["abstract"]);
xassert($ps->diffs["authors"]);
xassert($ps->execute_save_paper_json($pj));
xassert(!$ps->has_error());

$newpaper = $Conf->paperRow($ps->paperId, $user_estrin);
xassert($newpaper);
xassert_eqq($newpaper->title, "Paper about mantis shrimp");
xassert_eqq($newpaper->abstract, "They see lots of colors.");
xassert_eqq(count($newpaper->author_list()), 1);
$aus = $newpaper->author_list();
xassert_eqq($aus[0]->firstName, "David");
xassert_eqq($aus[0]->lastName, "Attenborough");
xassert_eqq($aus[0]->email, "atten@_.com");
xassert($newpaper->timeSubmitted > 0);
xassert($newpaper->timeWithdrawn <= 0);
xassert(!$newpaper->option(1));
xassert(!!$newpaper->option(2));
xassert(count($newpaper->option(2)->documents()) == 1);
xassert_eqq($newpaper->option(2)->document(0)->text_hash(), "sha2-38b74d4ab9d3897b0166aa975e5e00dd2861a218fad7ec8fa08921fff7f0f0f4");

// check some content_text_signature functionality
$doc = new DocumentInfo(["content" => "ABCdefGHIjklMNO"]);
xassert_eqq($doc->content_text_signature(), "starts with “ABCdefGH”");
$doc = new DocumentInfo(["content" => "\x02\x00A\x80BCdefGHIjklMN"]);
xassert_eqq($doc->content_text_signature(), "starts with “\\x02\\x00A\\x80BCde”");
$doc = new DocumentInfo(["content" => ""]);
xassert_eqq($doc->content_text_signature(), "is empty");

$doc = new DocumentInfo(["content_file" => "/tmp/this-file-is-expected-not-to-exist.png.zip"]);
++Xassert::$disabled;
$s = $doc->content_text_signature();
--Xassert::$disabled;
xassert_eqq($s, "cannot be loaded");

$Conf->check_invariants();

xassert_exit();
