<?php
// test05.php -- HotCRP review tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");

// load users
$user_chair = $Conf->user_by_email("chair@_.com");
$user_mgbaker = $Conf->user_by_email("mgbaker@cs.stanford.edu"); // pc
$Conf->save_setting("rev_open", 1);

assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "1-18");
assert_search_papers($user_chair, "-ire:3", "19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "");
assert_search_papers($user_chair, "-pre:mgbaker", "1-30");
assert_search_papers($user_chair, "cre:mgbaker", "");
assert_search_papers($user_chair, "-cre:mgbaker", "1-30");

save_review(1, $user_mgbaker, ["overAllMerit" => 5, "ready" => false]);

assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "1-18");
assert_search_papers($user_chair, "-ire:3", "19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");
assert_search_papers($user_chair, "pre:any", "1");
assert_search_papers($user_chair, "-pre:any", "2-30");
assert_search_papers($user_chair, "cre:any", "");
assert_search_papers($user_chair, "-cre:any", "1-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "1");
assert_search_papers($user_chair, "-pre:mgbaker", "2-30");
assert_search_papers($user_chair, "cre:mgbaker", "");
assert_search_papers($user_chair, "-cre:mgbaker", "1-30");

assert_search_papers($user_chair, "ovemer:5", "");

save_review(1, $user_mgbaker, ["overAllMerit" => 5, "ready" => true]);

assert_search_papers($user_chair, "re:3", "1-18");
assert_search_papers($user_chair, "-re:3", "19-30");
assert_search_papers($user_chair, "ire:3", "2-18");
assert_search_papers($user_chair, "-ire:3", "1 19-30");
assert_search_papers($user_chair, "pre:3", "");
assert_search_papers($user_chair, "-pre:3", "1-30");
assert_search_papers($user_chair, "cre:3", "");
assert_search_papers($user_chair, "-cre:3", "1-30");
assert_search_papers($user_chair, "pre:any", "");
assert_search_papers($user_chair, "-pre:any", "1-30");
assert_search_papers($user_chair, "cre:any", "1");
assert_search_papers($user_chair, "-cre:any", "2-30");

assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_chair, "-re:mgbaker", "2-12 14-16 18-30");
assert_search_papers($user_chair, "ire:mgbaker", "13 17");
assert_search_papers($user_chair, "-ire:mgbaker", "1-12 14-16 18-30");
assert_search_papers($user_chair, "pre:mgbaker", "");
assert_search_papers($user_chair, "-pre:mgbaker", "1-30");
assert_search_papers($user_chair, "cre:mgbaker", "1");
assert_search_papers($user_chair, "-cre:mgbaker", "2-30");

assert_search_papers($user_chair, "ovemer:5", "1");

xassert_exit();
