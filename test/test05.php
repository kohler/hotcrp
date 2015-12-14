<?php
// test05.php -- HotCRP paper submission tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
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

$ps = new PaperStatus(["view_contact" => $user_estrin]);

$paper1a = $ps->load(1);
xassert_eqq($paper1a->title, "Scalable Timers for Soft State Protocols");

$ps->save((object) ["id" => 1, "title" => "Scalable Timers? for Soft State Protocols"]);
xassert(!$ps->nerrors);

$paper1b = $ps->load(1);
xassert_eqq($paper1b->title, "Scalable Timers? for Soft State Protocols");
$paper1b->title = $paper1a->title;
$paper1b->submitted_at = $paper1a->submitted_at;
xassert_eqq(json_encode($paper1b), json_encode($paper1a));

$doc = Filer::file_upload_json([
        "error" => UPLOAD_ERR_OK, "name" => "amazing-sample.pdf",
        "tmp_name" => "$ConfSitePATH/src/sample.pdf",
        "tmp_name_safe" => true, "type" => "application/pdf"
    ]);
$ps->save((object) ["id" => 1, "submission" => $doc]);
xassert(!$ps->nerrors);

$paper1c = $ps->load(1);
xassert_eqq($paper1c->submission->sha1, "2f1bccbf1e0e98004c01ef5b26eb9619f363e38e");

xassert_exit();
