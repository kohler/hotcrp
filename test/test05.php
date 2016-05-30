<?php
// test05.php -- HotCRP paper submission tests
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", $Now + 100);
$Conf->save_setting("sub_sub", $Now + 100);

// load users
$user_estrin = Contact::find_by_email("estrin@usc.edu"); // pc
$user_nobody = new Contact;

$ps = new PaperStatus($user_estrin);

$paper1a = $ps->paper_json(1);
xassert_eqq($paper1a->title, "Scalable Timers for Soft State Protocols");

$ps->save_paper_json((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
xassert(!$ps->has_error());

$paper1b = $ps->paper_json(1);

xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
$paper1b->title = $paper1a->title;
$paper1b->submitted_at = $paper1a->submitted_at;
xassert_eqq(json_encode($paper1b), json_encode($paper1a));

$doc = DocumentInfo::make_file_upload([
        "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
        "tmp_name" => "$ConfSitePATH/src/sample.pdf",
        "tmp_name_safe" => true, "type" => "application/pdf"
    ]);
$ps->save_paper_json((object) ["id" => 1, "submission" => $doc]);
xassert(!$ps->has_error());

$paper1c = $ps->paper_json(1);
xassert_eqq($paper1c->submission->sha1, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");

$ps = new PaperStatus(null);

$paper2a = $ps->paper_json(2);
$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2b = $ps->paper_json(2);
xassert_eqq($paper2b->submission->sha1, "f572d396fae9206628714fb2ce00f72e94f2258f");

$ps->save_paper_json(json_decode("{\"id\":2,\"final\":{\"content\":\"goodbye\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->sha1, "f572d396fae9206628714fb2ce00f72e94f2258f");
xassert_eqq($paper2->final->sha1, "e7d9b82b45d5833c9dada13f2379e7b66c823434");

$ps->save_paper_json(json_decode("{\"id\":2,\"submission\":{\"content\":\"again hello\\n\",\"type\":\"application/pdf\"}}"));
xassert(!$ps->has_error());

$paper2 = $ps->paper_json(2);
xassert_eqq($paper2->submission->sha1, "a933bc1661997cd0c5ac3597e454744383576246");
xassert_eqq($paper2->final->sha1, "e7d9b82b45d5833c9dada13f2379e7b66c823434");
xassert_eqq(bin2hex($ps->paper_row()->sha1), "e7d9b82b45d5833c9dada13f2379e7b66c823434");

xassert_exit();
