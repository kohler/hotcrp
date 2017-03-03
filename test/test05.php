<?php
// test05.php -- HotCRP paper submission tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", $Now + 100);
$Conf->save_setting("sub_sub", $Now + 100);

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
        "tmp_name_safe" => true, "type" => "application/pdf"
    ]);
$ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
xassert(!$ps->has_error());

$paper1c = $ps->paper_json(1);
xassert_eqq($paper1c->submission->sha1, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");

$ps = new PaperStatus($Conf);

$paper2a = $ps->paper_json(2);
$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2b = $ps->paper_json(2);
xassert_eqq($paper2b->submission->sha1, "24aaabecc9fac961d52ae62f620a47f04facc2ce");

$ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"%PDF-goodbye\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->sha1, "24aaabecc9fac961d52ae62f620a47f04facc2ce");
xassert_eqq($paper2->final->sha1, "e04c778a0af702582bb0e9345fab6540acb28e45");

$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"%PDF-again hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->sha1, "30240fac8417b80709c72156b7f7f7ad95b34a2b");
xassert_eqq($paper2->final->sha1, "e04c778a0af702582bb0e9345fab6540acb28e45");
xassert_eqq(bin2hex($ps->paper_row()->sha1), "e04c778a0af702582bb0e9345fab6540acb28e45");

// test new-style options storage
$options = $Conf->setting_json("options");
xassert(!isset($options->{"2"}));
$options->{"2"} = ["id" => 2, "name" => "Attachments", "abbr" => "attachments", "type" => "attachments", "position" => 2];
$Conf->save_setting("options", 1, json_encode($options));
$Conf->invalidate_caches("options");

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"type\":\"application/pdf\"}]}}"));
xassert(!$ps->has_error());

$paper2 = $Conf->paperRow(2, $user_estrin);
$docs = $paper2->option(2)->documents();
xassert_eqq(count($docs), 2);
xassert($docs[0]->check_text_hash("4c18e2ec1d1e6d9e53f57499a66aeb691d687370"));
$d0psid = $docs[0]->paperStorageId;
xassert($docs[1]->check_text_hash("2e866582768e8954f55b974a2ad8503ef90717ab"));
$d1psid = $docs[1]->paperStorageId;

$ps->save_paper_json(json_decode("{\"id\":2,\"options\":{\"attachments\":[{\"content\":\"%PDF-1\", \"sha1\": \"4c18e2ec1d1e6d9e53f57499a66aeb691d687370\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}, {\"content\":\"%PDF-2\", \"sha1\": \"2e866582768e8954f55b974a2ad8503ef90717ab\", \"type\":\"application/pdf\"}]}}"));
xassert(!$ps->has_error());

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

xassert_exit();
