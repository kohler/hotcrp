<?php
// test05.php -- HotCRP review and some setting tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
require_once("$ConfSitePATH/src/settingvalues.php");

// load users
$user_chair = $Conf->user_by_email("chair@_.com");
$user_mgbaker = $Conf->user_by_email("mgbaker@cs.stanford.edu"); // pc
$Conf->save_setting("rev_open", 1);

// 1-18 have 3 assignments, reset have 0
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

// Add a partial review
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

// Add a complete review
save_review(1, $user_mgbaker, ["overAllMerit" => 5, "reviewerQualification" => 1, "ready" => true]);

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


// Test offline review parsing

// Change a score
$paper1 = fetch_paper(1, $user_chair);
$rrow = fetch_review($paper1, $user_mgbaker);
$review1A = file_get_contents("$ConfSitePATH/test/review1A.txt");
$tf = ReviewValues::make_text($Conf->review_form(), $review1A, "review1A.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));

assert_search_papers($user_chair, "ovemer:4", "1");

// Catch different-conference form
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Testconf I/', 'Testconf IIII', $review1A), "review1A-1.txt");
xassert(!$tf->parse_text(false));
xassert($tf->has_error_at("confid"));

// Catch invalid value
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'Mumps', $review1A), "review1A-2.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->unchanged), "#1A");
xassert($tf->has_problem_at("overAllMerit"));

// “No entry” is invalid
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-3.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->unchanged), "#1A");
xassert($tf->has_problem_at("overAllMerit"));
xassert(strpos(join("\n", $tf->messages_at("overAllMerit")), "must provide") !== false);
//error_log(var_export($tf->messages(true), true));

// Different reviewer
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: butt@butt.com', $review1A), "review1A-4.txt");
xassert($tf->parse_text(false));
xassert(!$tf->check_and_save($user_mgbaker));
xassert($tf->has_problem_at("reviewerEmail"));

// Different reviewer
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baaaker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $review1A)), "review1A-5.txt");
xassert($tf->parse_text(false));
xassert(!$tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
xassert($tf->has_problem_at("reviewerEmail"));

// Different reviewer with same name (OK)
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $review1A)), "review1A-5.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
xassert(!$tf->has_problem_at("reviewerEmail"));
//error_log(var_export($tf->messages(true), true));


// Settings changes

// Add “no entry”
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_overAllMerit" => "Overall merit",
    "options_overAllMerit" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\nNo entry\n"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// Now it's OK to save “no entry”
$tf = ReviewValues::make_text($Conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-6.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->updated), "#1A");
xassert(!$tf->has_problem_at("overAllMerit"));
//error_log(var_export($tf->messages(true), true));

assert_search_papers($user_chair, "has:ovemer", "");

// Restore overall-merit 4
$tf = ReviewValues::make_text($Conf->review_form(), $review1A, "review1A-7.txt");
xassert($tf->parse_text(false));
xassert($tf->check_and_save($user_mgbaker));
xassert_eqq(join(" ", $tf->updated), "#1A");
xassert(!$tf->has_problem_at("overAllMerit"));
//error_log(var_export($tf->messages(true), true));

assert_search_papers($user_chair, "ovemer:4", "1");

// “4” is no longer a valid overall-merit score
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_overAllMerit" => "Overall merit",
    "options_overAllMerit" => "1. Reject\n2. Weak reject\n3. Weak accept\nNo entry\n"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// So the 4 score has been removed
assert_search_papers($user_chair, "ovemer:4", "");

xassert_exit();
