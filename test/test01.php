<?php
// test01.php -- HotCRP tests: permissions, assignments, search
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
$Conf->save_setting("sub_open", 1);
$Conf->save_setting("sub_update", $Now + 10);
$Conf->save_setting("sub_sub", $Now + 10);

// load users
$user_chair = Contact::find_by_email("chair@_.com");
$user_estrin = Contact::find_by_email("estrin@usc.edu"); // pc
$user_kohler = Contact::find_by_email("kohler@seas.harvard.edu"); // none
$user_marina = Contact::find_by_email("marina@poema.ru"); // pc
$user_van = Contact::find_by_email("van@ee.lbl.gov"); // none
$user_mgbaker = Contact::find_by_email("mgbaker@cs.stanford.edu"); // pc
$user_shenker = Contact::find_by_email("shenker@parc.xerox.com"); // pc, chair
$user_jon = Contact::find_by_email("jon@cs.ucl.ac.uk"); // pc, red
$user_varghese = Contact::find_by_email("varghese@ccrc.wustl.edu"); // pc
$user_wilma = Contact::find_by_email("ojuelegba@gmail.com"); // pc
$user_mjh = Contact::find_by_email("mjh@isi.edu"); // pc
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
xassert($user_mgbaker->actAuthorView($paper18));

// simple search
$pl = new PaperList(new PaperSearch($user_shenker, "au:berkeley"));
$j = $pl->text_json("id title");
xassert_eqq(join(";", array_keys($j)), "1;6;13;15");

// sorting works
assert_search_papers($user_shenker, "au:berkeley sort:title", "15 13 1 6");

// correct conflict information returned
$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_mgbaker));
$j = $pl->text_json("id selconf");
xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
xassert(!@$j[1]->selconf && !@$j[2]->selconf && @$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && !@$j[17]->selconf && @$j[18]->selconf);

$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_jon));
$j = $pl->text_json("id selconf");
xassert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
xassert(!@$j[1]->selconf && !@$j[2]->selconf && !@$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && @$j[17]->selconf && !@$j[18]->selconf);

assert_search_papers($user_shenker, "re:estrin", "4 8 18");

// normals don't see conflicted reviews
assert_search_papers($user_mgbaker, "re:estrin", "4 8");

// make reviewer identity anonymous until review completion
$Conf->save_setting("rev_open", 1);
$Conf->save_setting("pc_seeblindrev", 1);
assert_search_papers($user_mgbaker, "re:varghese", "");

$revreq = array("overAllMerit" => 5, "reviewerQualification" => 4, "ready" => true);
$rf = ReviewForm::get();
$rf->save_review($revreq,
                 $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mgbaker->contactId)),
                 $Conf->paperRow(1, $user_mgbaker),
                 $user_mgbaker);
assert_search_papers($user_mgbaker, "re:varghese", "1");

// check comment identity
xassert($Conf->setting("au_seerev") == Conference::AUSEEREV_NO);
$comment1 = new CommentInfo(null, $paper1);
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
xassert($c1ok);
xassert(!$user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", Conference::AUSEEREV_YES);
xassert($user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", Conference::BLIND_OPTIONAL);
xassert($user_van->can_view_comment($paper1, $comment1, false));
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
xassert($c1ok);
xassert($user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", null);
xassert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", Conference::AUSEEREV_NO);

// check comment/review visibility when reviews are incomplete
$Conf->save_setting("pc_seeallrev", Conference::PCSEEREV_UNLESSINCOMPLETE);
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
$rf->save_review($revreq,
                 $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mjh->contactId)),
                 $Conf->paperRow(1, $user_mjh),
                 $user_mjh);
$review2 = $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mjh->contactId));
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

$Conf->save_setting("pc_seeallrev", Conference::PCSEEREV_UNLESSANYINCOMPLETE);
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
$rf->save_review($revreq,
                 $Conf->reviewRow(array("paperId" => 3, "contactId" => $user_wilma->contactId)),
                 $Conf->paperRow(3, $user_wilma),
                 $user_wilma);
xassert(!$user_wilma->has_outstanding_review());
xassert($user_wilma->can_view_review($paper1, $review1, false));
xassert($user_wilma->can_view_review($paper1, $review2, false));

// set up some tags and tracks
AssignmentSet::run($user_chair, "paper,tag\n3 9 13 17,green\n", true);
$Conf->save_setting("tracks", 1, "{\"green\":{\"assrev\":\"-red\"}}");
$paper17 = $Conf->paperRow(17, $user_jon);
xassert(!$Conf->check_tracks($paper17, $user_jon, "assrev"));
xassert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper17));
xassert(!$user_jon->can_accept_review_assignment($paper17));

// check shepherd search visibility
$paper11 = $Conf->paperRow(11, $user_chair);
$paper12 = $Conf->paperRow(12, $user_chair);
xassert(PaperActions::set_shepherd($paper11, $user_estrin, $user_chair));
xassert(PaperActions::set_shepherd($paper12, $user_estrin, $user_chair));
assert_search_papers($user_chair, "shep:any", "11 12");
assert_search_papers($user_shenker, "shep:any", "11 12");

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
xassert_eqq(join("\n", $assignset->errors_text()), "Deborah Estrin <estrin@usc.edu> has a conflict with paper #1.");
$assignset->execute();
assert_query("select email from PaperReview r join ContactInfo c on (c.contactId=r.contactId) where paperId=1 order by email", "mgbaker@cs.stanford.edu\nmjh@isi.edu\nvarghese@ccrc.wustl.edu");

assert_search_papers($user_chair, "#fart", "");
$assignset = new AssignmentSet($user_estrin, false);
$assignset->parse("paper,tag
1,fart
2,fart\n");
xassert_eqq(join("\n", $assignset->errors_text()), "You have a conflict with paper #1.");

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

// round searches
assert_search_papers($user_chair, "re:huitema", "8 10 13");
assert_search_papers($user_chair, "re:huitema round:R1", "13");
assert_search_papers($user_chair, "round:R1", "12 13");
assert_search_papers($user_chair, "round:R1 re:any", "12 13");
assert_search_papers($user_chair, "round:R1 re:>=0", "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18");

xassert_assign($Admin, true, "action,paper,user,round\nclearreview,all,huitema,R1\n");
assert_search_papers($user_chair, "re:huitema", "8 10");

xassert_assign($Admin, true, "action,paper,user,round\nprimary,13,huitema,R1\n");

// THEN searches
assert_search_papers($user_chair, "10-12 THEN re:huitema", "10 11 12 8 13");
assert_search_papers($user_chair, "10-12 HIGHLIGHT re:huitema", "10 11 12");
assert_search_papers($user_chair, "10-12 THEN re:huitema THEN 5-6", "10 11 12 8 13 5 6");
assert_search_papers($user_chair, "(10-12 THEN re:huitema) THEN 5-6", "10 11 12 8 13 5 6");

// NOT searches
assert_search_papers($user_chair, "#fart", "1 2 3 4 5 6 7 8");
assert_search_papers($user_chair, "NOT #fart", "9 10 11 12 13 14 15 16 17 18");
assert_search_papers($user_chair, "-#fart", "9 10 11 12 13 14 15 16 17 18");

// Check all tags
assert_search_papers($user_chair, "#none", "9 10 11 12 14 15 16 18");
xassert_assign($Admin, false, "paper,tag\n9,~private\n10,~~chair\n");
assert_search_papers($user_chair, "#none", "11 12 14 15 16 18");
assert_search_papers($user_mgbaker, "#none", "3 9 10 11 12 14 15 16 18");

// comment searches
assert_search_papers($user_chair, "cmt:any", "1");
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

/*$result = Dbl::qe("select paperId, tag, tagIndex from PaperTag order by paperId, tag");
$tags = array();
while ($result && ($row = $result->fetch_row()))
    $tags[] = "$row[0],$row[1],$row[2]\n";
echo join("", $tags);*/

xassert_exit();
