<?php
// test01.php -- HotCRP tests
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/testsetup.php");

// load users
$user_chair = Contact::find_by_email("chair@_.com");
$user_estrin = Contact::find_by_email("estrin@usc.edu");
$user_kohler = Contact::find_by_email("kohler@seas.harvard.edu");
$user_marina = Contact::find_by_email("marina@poema.ru");
$user_van = Contact::find_by_email("van@ee.lbl.gov");
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
}

$paper1 = $Conf->paperRow(1, $user_chair);
check_paper1($paper1);
check_paper1($Conf->paperRow(1, $user_estrin));

$user_capability = new Contact;
assert(!$user_capability->canViewPaper($paper1));
$user_capability->apply_capability_text($Conf->capability_text($paper1, "a"));
assert(!$user_capability->contactId);
assert($user_capability->canViewPaper($paper1));
assert(!$user_capability->allowAdminister($paper1));
assert(!$user_capability->canAdminister($paper1));
assert(!$user_capability->canViewTags($paper1));

echo "* Tests complete.\n";
