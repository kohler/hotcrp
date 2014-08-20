<?php
// test01.php -- HotCRP tests
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
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
    assert($paper1 !== null);

    assert($user_chair->canViewPaper($paper1));
    assert($user_estrin->canViewPaper($paper1));
    assert($user_marina->canViewPaper($paper1));
    assert($user_van->canViewPaper($paper1));
    assert(!$user_kohler->canViewPaper($paper1));
    assert(!$user_nobody->canViewPaper($paper1));

    assert($user_chair->allowAdminister($paper1));
    assert(!$user_estrin->allowAdminister($paper1));
    assert(!$user_marina->allowAdminister($paper1));
    assert(!$user_van->allowAdminister($paper1));
    assert(!$user_kohler->allowAdminister($paper1));
    assert(!$user_nobody->allowAdminister($paper1));

    assert($user_chair->canAdminister($paper1));
    assert(!$user_estrin->canAdminister($paper1));
    assert(!$user_marina->canAdminister($paper1));
    assert(!$user_van->canAdminister($paper1));
    assert(!$user_kohler->canAdminister($paper1));
    assert(!$user_nobody->canAdminister($paper1));

    assert($user_chair->canViewTags($paper1));
    assert(!$user_estrin->canViewTags($paper1));
    assert($user_marina->canViewTags($paper1));
    assert(!$user_van->canViewTags($paper1));
    assert(!$user_kohler->canViewTags($paper1));
    assert(!$user_nobody->canViewTags($paper1));

    assert($user_chair->canUpdatePaper($paper1));
    assert($user_estrin->canUpdatePaper($paper1));
    assert(!$user_marina->canUpdatePaper($paper1));
    assert($user_van->canUpdatePaper($paper1));
    assert(!$user_kohler->canUpdatePaper($paper1));
    assert(!$user_nobody->canUpdatePaper($paper1));
}

$paper1 = $Conf->paperRow(1, $user_chair);
check_paper1($paper1);
check_paper1($Conf->paperRow(1, $user_estrin));

// grant user capability to read paper 1, check it doesn't allow PC view
$user_capability = new Contact;
assert(!$user_capability->canViewPaper($paper1));
$user_capability->apply_capability_text($Conf->capability_text($paper1, "a"));
assert(!$user_capability->contactId);
assert($user_capability->canViewPaper($paper1));
assert(!$user_capability->allowAdminister($paper1));
assert(!$user_capability->canAdminister($paper1));
assert(!$user_capability->canViewTags($paper1));
assert(!$user_capability->canUpdatePaper($paper1));

// change submission date
$Conf->save_setting("sub_update", $Now - 5);
$Conf->save_setting("sub_sub", $Now - 5);
assert(!$user_chair->canUpdatePaper($paper1));
assert(!$user_estrin->canUpdatePaper($paper1));
assert(!$user_marina->canUpdatePaper($paper1));
assert(!$user_van->canUpdatePaper($paper1));
assert(!$user_kohler->canUpdatePaper($paper1));
assert(!$user_nobody->canUpdatePaper($paper1));

// role assignment works
$paper18 = $Conf->paperRow(18, $user_mgbaker);
assert($user_shenker->canAdminister($paper18));
assert(!$user_mgbaker->canAdminister($paper1));
assert(!$user_mgbaker->canAdminister($paper18));

// author derivation works
assert($user_mgbaker->actAuthorView($paper18));

// simple search
$pl = new PaperList(new PaperSearch($user_shenker, "au:berkeley"));
$j = $pl->text_json("id title");
assert(join(";", array_keys($j)) == "1;6;13;15");

// sorting works
$pl = new PaperList(new PaperSearch($user_shenker, "au:berkeley sort:title"));
$j = $pl->text_json("id title");
assert(join(";", array_keys($j)) == "15;13;1;6");

// correct conflict information returned
$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_mgbaker));
$j = $pl->text_json("id selconf");
assert(join(";", array_keys($j)) == "1;2;3;4;5;15;16;17;18");
assert(!@$j[1]->selconf && !@$j[2]->selconf && @$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && !@$j[17]->selconf && @$j[18]->selconf);

$pl = new PaperList(new PaperSearch($user_shenker, "1 2 3 4 5 15-18"),
                    array("reviewer" => $user_jon));
$j = $pl->text_json("id selconf");
assert(join(";", array_keys($j)) == "1;2;3;4;5;15;16;17;18");
assert(!@$j[1]->selconf && !@$j[2]->selconf && !@$j[3]->selconf && !@$j[4]->selconf && !@$j[5]->selconf
       && !@$j[15]->selconf && !@$j[16]->selconf && @$j[17]->selconf && !@$j[18]->selconf);

$pl = new PaperList(new PaperSearch($user_shenker, "re:estrin"));
$j = $pl->text_json("id");
assert(join(";", array_keys($j)) == "4;8;18");

// normals don't see conflicted reviews
$pl = new PaperList(new PaperSearch($user_mgbaker, "re:estrin"));
$j = $pl->text_json("id");
assert(join(";", array_keys($j)) == "4;8");

// make reviewer identity anonymous until review completion
$Conf->save_setting("rev_open", 1);
$Conf->save_setting("pc_seeblindrev", 1);
$pl = new PaperList(new PaperSearch($user_mgbaker, "re:varghese"));
$j = $pl->text_json("id");
assert(join(";", array_keys($j)) == "");

$revreq = array("overAllMerit" => 5, "reviewerQualification" => 4, "ready" => true);
$rf = reviewForm();
$rf->save_review($revreq,
                 $Conf->reviewRow(array("paperId" => 1, "contactId" => $user_mgbaker->contactId)),
                 $Conf->paperRow(1, $user_mgbaker),
                 $user_mgbaker);
$pl = new PaperList(new PaperSearch($user_mgbaker, "re:varghese"));
$j = $pl->text_json("id");
assert(join(";", array_keys($j)) == "1");

// check comment identity
$comment1 = CommentSave::save(array("text" => "test", "visibility" => "a", "blind" => false),
                              $paper1, null, $user_mgbaker, false);
assert($comment1);
assert(!$user_van->canViewComment($paper1, $comment1, false));
assert(!$user_van->canViewCommentIdentity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", AU_SEEREV_ALWAYS);
assert($user_van->canViewComment($paper1, $comment1, false));
assert(!$user_van->canViewCommentIdentity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", Conference::BLIND_OPTIONAL);
assert($user_van->canViewComment($paper1, $comment1, false));
assert(!$user_van->canViewCommentIdentity($paper1, $comment1, false));
$comment1 = CommentSave::save(array("text" => "test", "visibility" => "a", "blind" => false),
                              $paper1, null, $user_mgbaker, false);
assert($comment1);
assert($user_van->canViewCommentIdentity($paper1, $comment1, false));
$Conf->save_setting("rev_blind", null);
assert(!$user_van->canViewCommentIdentity($paper1, $comment1, false));
$Conf->save_setting("au_seerev", AU_SEEREV_NO);

// set up some tags and tracks
$tagger = new Tagger($user_chair);
$tagger->save(array(3, 9, 13, 17), "green", "a");
$Conf->save_setting("tracks", 1, "{\"green\":{\"assrev\":\"-red\"}}");
$paper17 = $Conf->paperRow(17, $user_jon);
assert(!$Conf->check_tracks($paper17, $user_jon, "assrev"));
assert(!$user_jon->allow_review_assignment_ignore_conflict($paper17));

// check shepherd search visibility
$paper11 = $Conf->paperRow(11, $user_chair);
$paper12 = $Conf->paperRow(12, $user_chair);
assert(PaperActions::set_shepherd($paper11, $user_estrin, $user_chair));
assert(PaperActions::set_shepherd($paper12, $user_estrin, $user_chair));
$pl = new PaperList(new PaperSearch($user_chair, "shep:any"));
$j = $pl->text_json("id");
assert_eqq(join(";", array_keys($j)), "11;12");
$pl = new PaperList(new PaperSearch($user_shenker, "shep:any"));
$j = $pl->text_json("id");
assert_eqq(join(";", array_keys($j)), "11;12");

echo "* Tests complete.\n";
