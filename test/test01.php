<?php
// test01.php -- HotCRP tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/testsetup.php");
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
$user_nobody = new Contact;

// users are different
assert($user_chair && $user_estrin && $user_kohler && $user_marina && $user_van && $user_nobody);
assert($user_chair->contactId && $user_estrin->contactId && $user_kohler->contactId && $user_marina->contactId && $user_van->contactId && !$user_nobody->contactId);
assert($user_chair->contactId != $user_estrin->contactId);

// check permissions on paper
function check_paper1($paper1) {
    global $user_chair, $user_estrin, $user_kohler, $user_marina, $user_van, $user_nobody;
    assert_neqq($paper1, null);

    assert($user_chair->can_view_paper($paper1));
    assert($user_estrin->can_view_paper($paper1));
    assert($user_marina->can_view_paper($paper1));
    assert($user_van->can_view_paper($paper1));
    assert(!$user_kohler->can_view_paper($paper1));
    assert(!$user_nobody->can_view_paper($paper1));

    assert($user_chair->allow_administer($paper1));
    assert(!$user_estrin->allow_administer($paper1));
    assert(!$user_marina->allow_administer($paper1));
    assert(!$user_van->allow_administer($paper1));
    assert(!$user_kohler->allow_administer($paper1));
    assert(!$user_nobody->allow_administer($paper1));

    assert($user_chair->can_administer($paper1));
    assert(!$user_estrin->can_administer($paper1));
    assert(!$user_marina->can_administer($paper1));
    assert(!$user_van->can_administer($paper1));
    assert(!$user_kohler->can_administer($paper1));
    assert(!$user_nobody->can_administer($paper1));

    assert($user_chair->can_view_tags($paper1));
    assert(!$user_estrin->can_view_tags($paper1));
    assert($user_marina->can_view_tags($paper1));
    assert(!$user_van->can_view_tags($paper1));
    assert(!$user_kohler->can_view_tags($paper1));
    assert(!$user_nobody->can_view_tags($paper1));

    assert($user_chair->can_update_paper($paper1));
    assert($user_estrin->can_update_paper($paper1));
    assert(!$user_marina->can_update_paper($paper1));
    assert($user_van->can_update_paper($paper1));
    assert(!$user_kohler->can_update_paper($paper1));
    assert(!$user_nobody->can_update_paper($paper1));
}

$paper1 = $Conf->paperRow(1, $user_chair);
check_paper1($paper1);
check_paper1($Conf->paperRow(1, $user_estrin));

// grant user capability to read paper 1, check it doesn't allow PC view
$user_capability = new Contact;
assert(!$user_capability->can_view_paper($paper1));
$user_capability->apply_capability_text($Conf->capability_text($paper1, "a"));
assert(!$user_capability->contactId);
assert($user_capability->can_view_paper($paper1));
assert(!$user_capability->allow_administer($paper1));
assert(!$user_capability->can_administer($paper1));
assert(!$user_capability->can_view_tags($paper1));
assert(!$user_capability->can_update_paper($paper1));

// change submission date
$Conf->save_setting("sub_update", $Now - 5);
$Conf->save_setting("sub_sub", $Now - 5);
assert(!$user_chair->can_update_paper($paper1));
assert(!$user_estrin->can_update_paper($paper1));
assert(!$user_marina->can_update_paper($paper1));
assert(!$user_van->can_update_paper($paper1));
assert(!$user_kohler->can_update_paper($paper1));
assert(!$user_nobody->can_update_paper($paper1));

// role assignment works
$paper18 = $Conf->paperRow(18, $user_mgbaker);
assert($user_shenker->can_administer($paper18));
assert(!$user_mgbaker->can_administer($paper1));
assert(!$user_mgbaker->can_administer($paper18));

// author derivation works
assert($user_mgbaker->actAuthorView($paper18));

// simple search
$pl = new PaperList(new PaperSearch($user_shenker, "au:berkeley"));
$j = $pl->text_json("id title");
assert_eqq(join(";", array_keys($j)), "1;6;13;15");

// sorting works
assert_search_papers($user_shenker, "au:berkeley sort:title", "15 13 1 6");

// correct conflict information returned
$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_mgbaker));
$j = $pl->text_json("id selconf");
assert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
assert(!@$j[1]->selconf && !@$j[2]->selconf && @$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && !@$j[17]->selconf && @$j[18]->selconf);

$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_jon));
$j = $pl->text_json("id selconf");
assert_eqq(join(";", array_keys($j)), "1;2;3;4;5;15;16;17;18");
assert(!@$j[1]->selconf && !@$j[2]->selconf && !@$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && @$j[17]->selconf && !@$j[18]->selconf);

assert_search_papers($user_shenker, "re:estrin", "4 8 18");

// normals don't see conflicted reviews
assert_search_papers($user_mgbaker, "re:estrin", "4 8");

// make reviewer identity anonymous until review completion
$Conf->save_setting("rev_open", 1);
$Conf->save_setting("pc_seeblindrev", 1);
assert_search_papers($user_mgbaker, "re:varghese", "");

$revreq = array("overAllMerit" => 5, "reviewerQualification" => 4, "ready" => true);
$rf = reviewForm();
$rf->save_review($revreq,
                 $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mgbaker->contactId)),
                 $Conf->paperRow(1, $user_mgbaker),
                 $user_mgbaker);
assert_search_papers($user_mgbaker, "re:varghese", "1");

// check comment identity
$comment1 = new CommentInfo(null, $paper1);
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
assert($c1ok);
assert(!$user_van->can_view_comment($paper1, $comment1, false));
assert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", AU_SEEREV_ALWAYS);
assert($user_van->can_view_comment($paper1, $comment1, false));
assert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", Conference::BLIND_OPTIONAL);
assert($user_van->can_view_comment($paper1, $comment1, false));
assert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$c1ok = $comment1->save(array("text" => "test", "visibility" => "a", "blind" => false), $user_mgbaker);
assert($c1ok);
assert($user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", null);
assert(!$user_van->can_view_comment_identity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", AU_SEEREV_NO);

// set up some tags and tracks
$tagger = new Tagger($user_chair);
$tagger->save(array(3, 9, 13, 17), "green", "a");
$Conf->save_setting("tracks", 1, "{\"green\":{\"assrev\":\"-red\"}}");
$paper17 = $Conf->paperRow(17, $user_jon);
assert(!$Conf->check_tracks($paper17, $user_jon, "assrev"));
assert(!$user_jon->can_accept_review_assignment_ignore_conflict($paper17));
assert(!$user_jon->can_accept_review_assignment($paper17));

// check shepherd search visibility
$paper11 = $Conf->paperRow(11, $user_chair);
$paper12 = $Conf->paperRow(12, $user_chair);
assert(PaperActions::set_shepherd($paper11, $user_estrin, $user_chair));
assert(PaperActions::set_shepherd($paper12, $user_estrin, $user_chair));
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
$assignset->execute();
assert_search_papers($user_chair, "#any~vote", "1");

echo "* Tests complete.\n";
