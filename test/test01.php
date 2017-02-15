<?php
// test01.php -- HotCRP tests: permissions, assignments, search
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", $Now + 10);
$Conf->save_setting("sub_sub", $Now + 10);

// load users
$user_chair = $Conf->user_by_email("chair@_.com");
$user_estrin = $Conf->user_by_email("estrin@usc.edu"); // pc
$user_kohler = $Conf->user_by_email("kohler@seas.harvard.edu"); // none
$user_marina = $Conf->user_by_email("marina@poema.ru"); // pc
$user_van = $Conf->user_by_email("van@ee.lbl.gov"); // none
$user_mgbaker = $Conf->user_by_email("mgbaker@cs.stanford.edu"); // pc
$user_shenker = $Conf->user_by_email("shenker@parc.xerox.com"); // pc, chair
$user_jon = $Conf->user_by_email("jon@cs.ucl.ac.uk"); // pc, red
$user_varghese = $Conf->user_by_email("varghese@ccrc.wustl.edu"); // pc
$user_wilma = $Conf->user_by_email("ojuelegba@gmail.com"); // pc
$user_mjh = $Conf->user_by_email("mjh@isi.edu"); // pc
$user_pdruschel = $Conf->user_by_email("pdruschel@cs.rice.edu"); // pc
$user_nobody = new Contact;

// users are different
xassert($user_chair && $user_estrin && $user_kohler && $user_marina && $user_van && $user_nobody);
xassert($user_chair->contactId && $user_estrin->contactId && $user_kohler->contactId && $user_marina->contactId && $user_van->contactId && !$user_nobody->contactId);
xassert($user_chair->contactId != $user_estrin->contactId);

// check permissions on paper
function check_paper1($paper1) {
    global $user_chair, $user_estrin, $user_kohler, $user_marina, $user_van, $user_nobody;
    xassert_neqq($paper1, null);

    xassert($user_chair->can_view_paper($paper1));
    xassert($user_estrin->can_view_paper($paper1));
    xassert($user_marina->can_view_paper($paper1));
    xassert($user_van->can_view_paper($paper1));
    xassert(!$user_kohler->can_view_paper($paper1));
    xassert(!$user_nobody->can_view_paper($paper1));

    xassert($user_chair->allow_administer($paper1));
    xassert(!$user_estrin->allow_administer($paper1));
    xassert(!$user_marina->allow_administer($paper1));
    xassert(!$user_van->allow_administer($paper1));
    xassert(!$user_kohler->allow_administer($paper1));
    xassert(!$user_nobody->allow_administer($paper1));

    xassert($user_chair->can_administer($paper1));
    xassert(!$user_estrin->can_administer($paper1));
    xassert(!$user_marina->can_administer($paper1));
    xassert(!$user_van->can_administer($paper1));
    xassert(!$user_kohler->can_administer($paper1));
    xassert(!$user_nobody->can_administer($paper1));

    xassert($user_chair->can_view_tags($paper1));
    xassert(!$user_estrin->can_view_tags($paper1));
    xassert($user_marina->can_view_tags($paper1));
    xassert(!$user_van->can_view_tags($paper1));
    xassert(!$user_kohler->can_view_tags($paper1));
    xassert(!$user_nobody->can_view_tags($paper1));

    xassert($user_chair->can_view_tag($paper1, "foo"));
    xassert($user_chair->can_view_tag($paper1, "~foo"));
    xassert($user_chair->can_view_tag($paper1, $user_chair->contactId . "~foo"));
    xassert($user_chair->can_view_tag($paper1, "~~foo"));
    xassert($user_chair->can_view_tag($paper1, $user_estrin->contactId . "~foo"));
    xassert(!$user_estrin->can_view_tag($paper1, "foo"));
    xassert(!$user_estrin->can_view_tag($paper1, "~foo"));
    xassert(!$user_estrin->can_view_tag($paper1, $user_chair->contactId . "~foo"));
    xassert(!$user_estrin->can_view_tag($paper1, "~~foo"));
    xassert(!$user_estrin->can_view_tag($paper1, $user_estrin->contactId . "~foo"));
    xassert($user_marina->can_view_tag($paper1, "foo"));
    xassert($user_marina->can_view_tag($paper1, "~foo"));
    xassert(!$user_marina->can_view_tag($paper1, $user_chair->contactId . "~foo"));
    xassert(!$user_marina->can_view_tag($paper1, "~~foo"));
    xassert(!$user_marina->can_view_tag($paper1, $user_estrin->contactId . "~foo"));
    xassert($user_marina->can_view_tag($paper1, $user_marina->contactId . "~foo"));

    xassert($user_chair->can_update_paper($paper1));
    xassert($user_estrin->can_update_paper($paper1));
    xassert(!$user_marina->can_update_paper($paper1));
    xassert($user_van->can_update_paper($paper1));
    xassert(!$user_kohler->can_update_paper($paper1));
    xassert(!$user_nobody->can_update_paper($paper1));
}

$paper1 = $Conf->paperRow(1, $user_chair);
check_paper1($paper1);
check_paper1($Conf->paperRow(1, $user_estrin));

// grant user capability to read paper 1, check it doesn't allow PC view
$user_capability = new Contact;
xassert(!$user_capability->can_view_paper($paper1));
$user_capability->apply_capability_text($Conf->capability_text($paper1, "a"));
xassert(!$user_capability->contactId);
xassert($user_capability->can_view_paper($paper1));
xassert(!$user_capability->allow_administer($paper1));
xassert(!$user_capability->can_administer($paper1));
xassert(!$user_capability->can_view_tags($paper1));
xassert(!$user_capability->can_update_paper($paper1));

// change submission date
$Conf->save_setting("sub_update", $Now - 5);
$Conf->save_setting("sub_sub", $Now - 5);
xassert(!$user_chair->can_update_paper($paper1));
xassert(!$user_estrin->can_update_paper($paper1));
xassert(!$user_marina->can_update_paper($paper1));
xassert(!$user_van->can_update_paper($paper1));
xassert(!$user_kohler->can_update_paper($paper1));
xassert(!$user_nobody->can_update_paper($paper1));

// role assignment works
$paper18 = $Conf->paperRow(18, $user_mgbaker);
xassert($user_shenker->can_administer($paper18));
xassert(!$user_mgbaker->can_administer($paper1));
xassert(!$user_mgbaker->can_administer($paper18));

// author derivation works
xassert($user_mgbaker->act_author_view($paper18));

// simple search
$pl = new PaperList(new PaperSearch($user_shenker, "au:berkeley"));
$j = $pl->text_json("id title");
xassert_eqq(join(";", array_keys($j)), "1;6;13;15;24");

// "and"
assert_search_papers($user_shenker, "au:berkeley fountain", "24");
assert_search_papers($user_shenker, "au:berkeley (fountain)", "24");
assert_search_papers($user_shenker, "au:berkeley (fountain", "24");
assert_search_papers($user_shenker, "au:berkeley fountain)", "24");

// sorting works
assert_search_papers($user_shenker, "au:berkeley sort:title", "24 15 13 1 6");

// correct conflict information returned
$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    ["reviewer" => $user_mgbaker]);
$j = $pl->text_json("id selconf");
xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
xassert_eqq($j[3]->selconf, "Y");
xassert_eqq($j[18]->selconf, "Y");
foreach ([1, 2, 4, 5, 15, 16, 17] as $i)
    xassert_eqq($j[$i]->selconf, "N");

$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    ["reviewer" => $user_jon]);
$j = $pl->text_json("id selconf");
xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
xassert_eqq($j[17]->selconf, "Y");
foreach ([1, 2, 3, 4, 5, 15, 16, 18] as $i)
    xassert_eqq($j[$i]->selconf, "N");

assert_search_papers($user_shenker, "re:estrin", "4 8 18");

// normals don't see conflicted reviews
assert_search_papers($user_mgbaker, "re:estrin", "4 8");

// make reviewer identity anonymous until review completion
$Conf->save_setting("rev_open", 1);
$Conf->save_setting("pc_seeblindrev", 1);
assert_search_papers($user_mgbaker, "re:varghese", "");

$revreq = array("overAllMerit" => 5, "reviewerQualification" => 4, "ready" => true);
save_review(1, $user_mgbaker, $revreq);
assert_search_papers($user_mgbaker, "re:varghese", "1");

// check comment identity
xassert($Conf->setting("au_seerev") == Conf::AUSEEREV_NO);
$comment1 = new CommentInfo(null, $paper1);
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
xassert($c1ok);
xassert(!$user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", Conf::AUSEEREV_YES);
xassert($user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", Conf::BLIND_OPTIONAL);
xassert($user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
xassert($c1ok);
xassert($user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", null);
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", Conf::AUSEEREV_NO);

// check comment/review visibility when reviews are incomplete
$Conf->save_setting("pc_seeallrev", Conf::PCSEEREV_UNLESSINCOMPLETE);
Contact::update_rights();
$review1 = $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mgbaker->contactId));
xassert(!$user_wilma->has_review());
xassert(!$user_wilma->has_outstanding_review());
xassert($user_wilma->can_view_review($paper1, $review1, false));
xassert($user_mgbaker->has_review());
xassert($user_mgbaker->has_outstanding_review());
xassert($user_mgbaker->can_view_review($paper1, $review1, false));
xassert($user_mjh->has_review());
xassert($user_mjh->has_outstanding_review());
xassert(!$user_mjh->can_view_review($paper1, $review1, false));
xassert($user_varghese->has_review());
xassert($user_varghese->has_outstanding_review());
xassert(!$user_varghese->can_view_review($paper1, $review1, false));
xassert($user_marina->has_review());
xassert($user_marina->has_outstanding_review());
xassert($user_marina->can_view_review($paper1, $review1, false));
$review2 = save_review(1, $user_mjh, $revreq);
xassert($user_wilma->can_view_review($paper1, $review1, false));
xassert($user_wilma->can_view_review($paper1, $review2, false));
xassert($user_mgbaker->can_view_review($paper1, $review1, false));
xassert($user_mgbaker->can_view_review($paper1, $review2, false));
xassert($user_mjh->can_view_review($paper1, $review1, false));
xassert($user_mjh->can_view_review($paper1, $review2, false));
xassert(!$user_varghese->can_view_review($paper1, $review1, false));
xassert(!$user_varghese->can_view_review($paper1, $review2, false));
xassert($user_marina->can_view_review($paper1, $review1, false));
xassert($user_marina->can_view_review($paper1, $review2, false));

$Conf->save_setting("pc_seeallrev", Conf::PCSEEREV_UNLESSANYINCOMPLETE);
Contact::update_rights();
xassert($user_wilma->can_view_review($paper1, $review1, false));
xassert($user_wilma->can_view_review($paper1, $review2, false));
xassert($user_mgbaker->can_view_review($paper1, $review1, false));
xassert($user_mgbaker->can_view_review($paper1, $review2, false));
xassert($user_mjh->can_view_review($paper1, $review1, false));
xassert($user_mjh->can_view_review($paper1, $review2, false));
xassert(!$user_marina->can_view_review($paper1, $review1, false));
xassert(!$user_marina->can_view_review($paper1, $review2, false));

AssignmentSet::run($user_chair, "paper,action,email\n3,primary,ojuelegba@gmail.com\n");
xassert($user_wilma->has_outstanding_review());
xassert(!$user_wilma->can_view_review($paper1, $review1, false));
xassert(!$user_wilma->can_view_review($paper1, $review2, false));
save_review(3, $user_wilma, $revreq);
xassert(!$user_wilma->has_outstanding_review());
xassert($user_wilma->can_view_review($paper1, $review1, false));
xassert($user_wilma->can_view_review($paper1, $review2, false));

// set up some tags and tracks
AssignmentSet::run($user_chair, "paper,tag\n3 9 13 17,green\n", true);
$Conf->save_setting("tracks", 1, "{\"green\":{\"assrev\":\"-red\"}}");
$Conf->invalidate_caches(["tracks" => true]);
$paper13 = $Conf->paperRow(13, $user_jon);
xassert(!$paper13->has_author($user_jon));
xassert(!$paper13->has_reviewer($user_jon));
xassert(!$Conf->check_tracks($paper13, $user_jon, Track::ASSREV));
xassert($user_jon->can_view_paper($paper13));
xassert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper13));
xassert(!$user_jon->can_accept_review_assignment($paper13));

// check shepherd search visibility
$paper11 = $Conf->paperRow(11, $user_chair);
$paper12 = $Conf->paperRow(12, $user_chair);
$j = call_api("setshepherd", $user_chair, ["shepherd" => $user_estrin->email], $paper11);
xassert_eqq($j->ok, true);
$j = call_api("setshepherd", $user_chair, ["shepherd" => $user_estrin->email], $paper12);
xassert_eqq($j->ok, true);
assert_search_papers($user_chair, "shep:any", "11 12");
assert_search_papers($user_chair, "shep:estrin", "11 12");
assert_search_papers($user_shenker, "shep:any", "11 12");
assert_search_papers($user_shenker, "has:shepherd", "11 12");

// tag searches
assert_search_papers($user_chair, "#green", "3 9 13 17");
Dbl::qe("insert into PaperTag (paperId,tag,tagIndex) values (1,?,10), (1,?,5), (2,?,3)",
        $user_jon->cid . "~vote", $user_marina->cid . "~vote", $user_marina->cid . "~vote");
assert_search_papers($user_jon, "#~vote", "1");
assert_search_papers($user_jon, "#~vote≥10", "1");
assert_search_papers($user_jon, "#~vote>10", "");
assert_search_papers($user_jon, "#~vote=10", "1");
assert_search_papers($user_jon, "#~vote<10", "");
assert_search_papers($user_marina, "#~vote", "1 2");
assert_search_papers($user_marina, "#~vote≥5", "1");
assert_search_papers($user_marina, "#~vote>5", "");
assert_search_papers($user_marina, "#~vote=5", "1");
assert_search_papers($user_marina, "#~vote<5", "2");
assert_search_papers($user_chair, "#marina~vote", "1 2");
assert_search_papers($user_chair, "#red~vote", "1");

// assign some tags using AssignmentSet interface
$assignset = new AssignmentSet($Admin, true);
$assignset->parse("paper,action,tag,index
1-9,tag,g*#clear
2,tag,green,1\n");
assert_search_papers($user_chair, "#green", "3 9 13 17");
$assignset->execute();
assert_search_papers($user_chair, "#green", "2 13 17");
assert_search_papers($user_chair, "#green>0", "2");
assert_search_papers($user_chair, "#green=1", "2");
assert_search_papers($user_chair, "#green=0", "13 17");

$assignset = new AssignmentSet($Admin, true);
$assignset->parse("paper,action,tag,index
1,tag,~vote,clear
2,tag,marina~vote,clear\n");
xassert_eqq(join("\n", $assignset->errors_text()), "");
$assignset->execute();
assert_search_papers($user_chair, "#any~vote", "1");

// check AssignmentSet conflict checking
$assignset = new AssignmentSet($Admin, false);
$assignset->parse("paper,action,email
1,pri,estrin@usc.edu\n");
xassert_eqq(join("\n", $assignset->errors_text()), "Deborah Estrin <estrin@usc.edu> has a conflict with submission #1.");
$assignset->execute();
assert_query("select email from PaperReview r join ContactInfo c on (c.contactId=r.contactId) where paperId=1 order by email", "mgbaker@cs.stanford.edu\nmjh@isi.edu\nvarghese@ccrc.wustl.edu");

assert_search_papers($user_chair, "#fart", "");
$assignset = new AssignmentSet($user_estrin, false);
$assignset->parse("paper,tag
1,fart
2,fart\n");
xassert_eqq(join("\n", $assignset->errors_text()), "You have a conflict with submission #1.");

xassert_assign($user_estrin, false, "paper,tag\n2,fart\n");
assert_search_papers($user_chair, "#fart", "2");

xassert_assign($Admin, false, "paper,tag\n1,#fart\n");
assert_search_papers($user_chair, "#fart", "1 2");
assert_search_papers($user_estrin, "#fart", "2");

// check twiddle tags
xassert_assign($Admin, false, "paper,tag\n1,~fart\n1,~~fart\n1,varghese~fart\n1,mjh~fart\n");
$paper1->load_tags();
xassert_eqq(join(" ", paper_tag_normalize($paper1)),
           "fart chair~fart mjh~fart varghese~fart jon~vote#10 marina~vote#5 ~~fart");

xassert_assign($Admin, false, "paper,tag\n1,all#none\n");
$paper1->load_tags();
xassert_eqq(join(" ", paper_tag_normalize($paper1)),
           "mjh~fart varghese~fart jon~vote#10 marina~vote#5");

xassert_assign($Admin, false, "paper,tag\n1,fart\n");
$paper1->load_tags();
xassert_eqq(join(" ", paper_tag_normalize($paper1)),
           "fart mjh~fart varghese~fart jon~vote#10 marina~vote#5");

xassert_assign($user_varghese, false, "paper,tag\n1,all#clear\n1,~green\n");
$paper1->load_tags();
xassert_eqq(join(" ", paper_tag_normalize($paper1)),
           "mjh~fart varghese~green jon~vote#10 marina~vote#5");

xassert_assign($Admin, true, "paper,tag\nall,fart#clear\n1,fart#4\n2,fart#5\n3,fart#6\n");
assert_search_papers($user_chair, "order:fart", "1 2 3");
xassert_eqq(search_text_col($user_chair, "order:fart", "tagval:fart"), "1 4\n2 5\n3 6\n");

xassert_assign($Admin, true, "action,paper,tag\nnexttag,6,fart\nnexttag,5,fart\nnexttag,4,fart\n");
assert_search_papers($user_chair, "order:fart", "1 2 3 6 5 4");

xassert_assign($Admin, true, "action,paper,tag\nseqnexttag,7,fart#3\nseqnexttag,8,fart\n");
assert_search_papers($user_chair, "order:fart", "7 8 1 2 3 6 5 4");

xassert_assign($Admin, true, "action,paper,tag\ncleartag,8,fArt\n");
assert_search_papers($user_chair, "order:fart", "7 1 2 3 6 5 4");

xassert_assign($Admin, true, "action,paper,tag\ntag,8,fArt#4\n");
assert_search_papers($user_chair, "order:fart", "7 8 1 2 3 6 5 4");

$paper8 = $Conf->paperRow(8, $user_chair);
xassert_eqq($paper8->tag_value("fart"), 4.0);
xassert(strpos($paper8->all_tags_text(), " fArt#") !== false);

// defined tags: chair
xassert_assign($user_varghese, false, "paper,tag\n1,chairtest\n");
assert_search_papers($user_varghese, "#chairtest", "1");
xassert_assign($user_varghese, false, "paper,tag\n1,chairtest#clear\n");
assert_search_papers($user_varghese, "#chairtest", "");

$Conf->save_setting("tag_chair", 1, trim($Conf->setting_data("tag_chair") . " chairtest"));
$Conf->invalidate_caches(["taginfo" => true]);
xassert_assign($Admin, true, "paper,tag\n1,chairtest\n");
assert_search_papers($user_chair, "#chairtest", "1");
assert_search_papers($user_varghese, "#chairtest", "1");
xassert_assign_fail($user_varghese, false, "paper,tag\n1,chairtest#clear\n");
assert_search_papers($user_varghese, "#chairtest", "1");

// pattern tags: chair
xassert_assign($user_varghese, false, "paper,tag\n1,chairtest1\n");
assert_search_papers($user_varghese, "#chairtest1", "1");
xassert_assign($user_varghese, false, "paper,tag\n1,chairtest1#clear\n");
assert_search_papers($user_varghese, "#chairtest1", "");

$Conf->save_setting("tag_chair", 1, trim($Conf->setting_data("tag_chair") . " chairtest*"));
$Conf->invalidate_caches(["taginfo" => true]);
xassert($Conf->tags()->has_pattern);
$ct = $Conf->tags()->check("chairtest0");
xassert(!!$ct);
xassert_assign($Admin, true, "paper,tag\n1,chairtest1\n");
assert_search_papers($user_chair, "#chairtest1", "1");
assert_search_papers($user_varghese, "#chairtest1", "1");
xassert_assign_fail($user_varghese, false, "paper,tag\n1,chairtest1#clear\n");
assert_search_papers($user_varghese, "#chairtest1", "1");

// pattern tag merging
$Conf->save_setting("tag_approval", 1, "chair*");
$Conf->invalidate_caches(["taginfo" => true]);
$ct = $Conf->tags()->check("chairtest0");
xassert($ct && $ct->chair && $ct->approval);

// round searches
assert_search_papers($user_chair, "re:huitema", "8 10 13");
assert_search_papers($user_chair, "re:huitema round:R1", "13");
assert_search_papers($user_chair, "round:R1", "12 13");
assert_search_papers($user_chair, "round:R1 re:any", "12 13");
assert_search_papers($user_chair, "round:R1 re:>=0", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");

xassert_assign($Admin, true, "action,paper,user,round\nclearreview,all,huitema,R1\n");
assert_search_papers($user_chair, "re:huitema", "8 10");

xassert_assign($Admin, true, "action,paper,user,round\nprimary,13,huitema,R1\n");

// search combinations
assert_search_papers($user_chair, "re:huitema", "8 10 13");
assert_search_papers($user_chair, "8 10 13 re:huitema", "8 10 13");

// THEN searches
assert_search_papers($user_chair, "10-12 THEN re:huitema", "10 11 12 8 13");
assert_search_papers($user_chair, "10-12 HIGHLIGHT re:huitema", "10 11 12");
assert_search_papers($user_chair, "10-12 THEN re:huitema THEN 5-6", "10 11 12 8 13 5 6");
assert_search_papers($user_chair, "(10-12 THEN re:huitema) THEN 5-6", "10 11 12 8 13 5 6");

// NOT searches
assert_search_papers($user_chair, "#fart", "1 2 3 4 5 6 7 8");
assert_search_papers($user_chair, "NOT #fart", "9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
assert_search_papers($user_chair, "-#fart", "9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");

// option searches
assert_search_papers($user_chair, "has:calories", "1 2 3 4 5");
assert_search_papers($user_chair, "opt:calories", "1 2 3 4 5");
assert_search_papers($user_chair, "-opt:calories", "6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
assert_search_papers($user_chair, "calories:yes", "1 2 3 4 5");
assert_search_papers($user_chair, "calories:no", "6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30");
assert_search_papers($user_chair, "calories>200", "1 3 4");
assert_search_papers($user_chair, "calories:<1000", "2 5");
assert_search_papers($user_chair, "calories:1040", "3 4");
assert_search_papers($user_chair, "calories≥200", "1 2 3 4");

// Check all tags
assert_search_papers($user_chair, "#none", "9 10 11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");
xassert_assign($Admin, false, "paper,tag\n9,~private\n10,~~chair\n");
assert_search_papers($user_chair, "#none", "11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");
assert_search_papers($user_mgbaker, "#none", "3 9 10 11 12 14 15 16 18 19 20 21 22 23 24 25 26 27 28 29 30");

// comment searches
assert_search_papers($user_chair, "cmt:any", "1");
assert_search_papers($user_chair, "has:comment", "1");
assert_search_papers($user_chair, "has:response", "");
assert_search_papers($user_chair, "has:author-comment", "1");
$comment2 = new CommentInfo(null, $paper18);
$c2ok = $comment2->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
xassert($c2ok);
assert_search_papers($user_chair, "cmt:any", "1 18");
assert_search_papers($user_chair, "cmt:any>1", "");
$comment3 = new CommentInfo(null, $paper18);
$c3ok = $comment3->save(array("text" => "test", "visibility" => "a", "blind" => false, "tags" => "redcmt"), $user_mgbaker);
xassert($c3ok);
assert_search_papers($user_chair, "cmt:any>1", "18");
assert_search_papers($user_chair, "cmt:jon", "");
assert_search_papers($user_chair, "cmt:mgbaker", "1 18");
assert_search_papers($user_chair, "cmt:mgbaker>1", "18");
assert_search_papers($user_chair, "cmt:#redcmt", "18");
$paper2 = $Conf->paperRow(2, $user_chair);
$comment4 = new CommentInfo(null, $paper2);
$c2ok = $comment4->save(array("text" => "test", "visibility" => "p", "blind" => false), $user_mgbaker);
assert_search_papers($user_chair, "has:comment", "1 2 18");
assert_search_papers($user_chair, "has:response", "");
assert_search_papers($user_chair, "has:author-comment", "1 18");


/*$result = Dbl::qe("select paperId, tag, tagIndex from PaperTag order by paperId, tag");
$tags = array();
while ($result && ($row = $result->fetch_row()))
    $tags[] = "$row[0],$row[1],$row[2]\n";
echo join("", $tags);*/

// check review visibility for “not unless completed on same paper”
$Conf->save_setting("pc_seeallrev", Conf::PCSEEREV_IFCOMPLETE);
Contact::update_rights();
$review2a = fetch_review(2, $user_jon);
xassert(!$review2a->reviewSubmitted);
xassert($user_jon->can_view_review($paper2, $review2a, false));
xassert(!$user_pdruschel->can_view_review($paper2, $review2a, false));
xassert(!$user_mgbaker->can_view_review($paper2, $review2a, false));
$review2a = save_review(2, $user_jon, $revreq);
xassert($review2a->reviewSubmitted);
xassert($user_jon->can_view_review($paper2, $review2a, false));
xassert(!$user_pdruschel->can_view_review($paper2, $review2a, false));
xassert(!$user_mgbaker->can_view_review($paper2, $review2a, false));
$review2b = save_review(2, $user_pdruschel, $revreq);
xassert($user_jon->can_view_review($paper2, $review2a, false));
xassert($user_pdruschel->can_view_review($paper2, $review2a, false));
xassert(!$user_mgbaker->can_view_review($paper2, $review2a, false));
AssignmentSet::run($user_chair, "paper,action,email\n2,secondary,mgbaker@cs.stanford.edu\n");
$review2d = fetch_review(2, $user_mgbaker);
xassert(!$review2d->reviewSubmitted);
xassert($review2d->reviewNeedsSubmit == 1);
xassert(!$user_mgbaker->can_view_review($paper2, $review2a, false));
$user_external = Contact::create($Conf, ["email" => "external@_.com", "name" => "External Reviewer"]);
$user_mgbaker->assign_review(2, $user_external->contactId, REVIEW_EXTERNAL);
$review2d = fetch_review(2, $user_mgbaker);
xassert(!$review2d->reviewSubmitted);
xassert($review2d->reviewNeedsSubmit == -1);
xassert(!$user_mgbaker->can_view_review($paper2, $review2a, false));
$review2e = fetch_review(2, $user_external);
xassert(!$user_mgbaker->can_view_review($paper2, $review2e, false));
$review2e = save_review(2, $user_external, $revreq);
$review2d = fetch_review(2, $user_mgbaker);
xassert(!$review2d->reviewSubmitted);
xassert($review2d->reviewNeedsSubmit == 0);
xassert($user_mgbaker->can_view_review($paper2, $review2a, false));
xassert($user_mgbaker->can_view_review($paper2, $review2e, false));

// complex assignments
assert_search_papers($user_chair, "2 AND re:4", "2");
assert_search_papers($user_chair, "re:mgbaker", "1 2 13 17");
assert_search_papers($user_chair, "re:sec:mgbaker", "2");
assert_search_papers($user_chair, "sec:mgbaker", "2");
assert_search_papers($user_chair, "re:pri:mgbaker", "1 13 17");

$assignset = new AssignmentSet($user_chair, null);
$assignset->parse("action,paper,email,reviewtype\nreview,all,mgbaker@cs.stanford.edu,secondary:primary\n");
xassert_eqq(join("\n", $assignset->errors_text()), "");
xassert($assignset->execute());

xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,all,mgbaker@cs.stanford.edu,secondary:primary\n"));
assert_search_papers($user_chair, "re:sec:mgbaker", "");
assert_search_papers($user_chair, "re:pri:mgbaker", "1 2 13 17");
$review2d = fetch_review(2, $user_mgbaker);
xassert(!$review2d->reviewSubmitted);
xassert($review2d->reviewNeedsSubmit == 1);

xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,2,mgbaker@cs.stanford.edu,primary:secondary\n"));
assert_search_papers($user_chair, "re:sec:mgbaker", "2");
assert_search_papers($user_chair, "re:pri:mgbaker", "1 13 17");
$review2d = fetch_review(2, $user_mgbaker);
xassert(!$review2d->reviewSubmitted);
xassert($review2d->reviewNeedsSubmit == 0);

// uploading the current assignment makes no changes
function get_pcassignment_csv() {
    global $user_chair;
    list($header, $texts) = SearchAction::pcassignments_csv_data($user_chair, range(1, 30));
    $csvg = new CsvGenerator;
    $csvg->set_header($header);
    $csvg->set_selection($header);
    $csvg->add($texts);
    return $csvg->unparse();
}
$old_pcassignments = get_pcassignment_csv();
xassert(AssignmentSet::run($user_chair, $old_pcassignments));
xassert_eqq(get_pcassignment_csv(), $old_pcassignments);

// `any` assignments
assert_search_papers($user_chair, "re:R1", "12 13");
assert_search_papers($user_chair, "re:R2", "13");
assert_search_papers($user_chair, "re:R3", "12");
assert_search_papers($user_chair, "round:none", "1 2 3 4 5 6 7 8 9 10 11 14 15 16 17 18");
xassert(AssignmentSet::run($user_chair, "action,paper,email,round\nreview,all,all,R1:none\n"));
assert_search_papers($user_chair, "re:R1", "");
assert_search_papers($user_chair, "re:R2", "13");
assert_search_papers($user_chair, "re:R3", "12");
assert_search_papers($user_chair, "round:none", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18");
xassert(AssignmentSet::run($user_chair, "action,paper,email,round\nreview,1-5,all,none:R1"));
assert_search_papers($user_chair, "re:R1", "1 2 3 4 5");
assert_search_papers($user_chair, "re:R2", "13");
assert_search_papers($user_chair, "re:R3", "12");
assert_search_papers($user_chair, "round:none", "6 7 8 9 10 11 12 13 14 15 16 17 18");

assert_search_papers($user_chair, "sec:any", "2");
assert_search_papers($user_chair, "has:sec", "2");
assert_search_papers($user_chair, "2 AND pri:mgbaker", "");
xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,any,any,secondary:primary"));
assert_search_papers($user_chair, "sec:any", "");
assert_search_papers($user_chair, "has:sec", "");
assert_search_papers($user_chair, "2 AND pri:mgbaker", "2");

assert_search_papers($user_chair, "pri:mgbaker", "1 2 13 17");
xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any"));
assert_search_papers($user_chair, "pri:mgbaker", "1 2 13 17");
xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any:pcreview"));
assert_search_papers($user_chair, "pri:mgbaker", "");
assert_search_papers($user_chair, "re:opt:mgbaker", "1 2 13 17");

xassert(AssignmentSet::run($user_chair, "action,paper,email,reviewtype\nreview,any,mgbaker,any:external"));
assert_search_papers($user_chair, "re:opt:mgbaker", "1 2 13 17");

// tracks and view-paper permissions
AssignmentSet::run($user_chair, "paper,tag\nall,-green\n3 9 13 17,green\n", true);
$Conf->save_setting("tracks", 1, "{\"green\":{\"view\":\"-red\"},\"_\":{\"view\":\"+red\"}}");
$Conf->save_setting("pc_seeallrev", 1);
$Conf->save_setting("pc_seeblindrev", 0);
$Conf->invalidate_caches(["tracks" => true]);
xassert($user_jon->has_tag("red"));
xassert(!$user_marina->has_tag("red"));

$paper13 = $Conf->paperRow(13, $user_jon);
xassert($paper13->has_tag("green"));
xassert(!$paper13->has_author($user_jon));
xassert(!$paper13->has_reviewer($user_jon));
xassert(!$paper13->has_author($user_marina));
xassert(!$paper13->has_reviewer($user_marina));

xassert(!$user_jon->can_view_paper($paper13));
xassert(!$user_jon->can_view_pdf($paper13));
xassert(!$user_jon->can_view_review($paper13, null, null));
xassert(!$user_jon->can_view_review_identity($paper13, null));
xassert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper13));
xassert(!$user_jon->can_accept_review_assignment($paper13));
xassert(!$user_jon->can_review($paper13, null));
xassert($user_marina->can_view_paper($paper13));
xassert($user_marina->can_view_pdf($paper13));
xassert($user_marina->can_view_review($paper13, null, null));
xassert($user_marina->can_view_review_identity($paper13, null));
xassert($user_marina->can_accept_review_assignment_ignore_conflict($paper13));
xassert($user_marina->can_accept_review_assignment($paper13));
xassert($user_marina->can_review($paper13, null));

$paper14 = $Conf->paperRow(14, $user_jon);
xassert(!$paper14->has_tag("green"));
xassert(!$paper14->has_author($user_jon));
xassert(!$paper14->has_reviewer($user_jon));
xassert(!$paper14->has_author($user_marina));
xassert(!$paper14->has_reviewer($user_marina));

xassert($user_jon->can_view_paper($paper14));
xassert($user_jon->can_view_pdf($paper14));
xassert($user_jon->can_view_review($paper14, null, null));
xassert($user_jon->can_view_review_identity($paper14, null));
xassert($user_jon->can_accept_review_assignment_ignore_conflict($paper14));
xassert($user_jon->can_accept_review_assignment($paper14));
xassert($user_jon->can_review($paper14, null));
xassert(!$user_marina->can_view_paper($paper14));
xassert(!$user_marina->can_view_pdf($paper14));
xassert(!$user_marina->can_view_review($paper14, null, null));
xassert(!$user_marina->can_view_review_identity($paper14, null));
xassert(!$user_marina->can_accept_review_assignment_ignore_conflict($paper14));
xassert(!$user_marina->can_accept_review_assignment($paper14));
xassert(!$user_marina->can_review($paper14, null));

$Conf->check_invariants();

xassert_exit();
