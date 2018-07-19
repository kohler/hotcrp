<?php
// test05.php -- HotCRP review and some setting tests
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");
require_once("$ConfSitePATH/src/settingvalues.php");

// load users
$user_chair = $Conf->user_by_email("chair@_.com");
$user_mgbaker = $Conf->user_by_email("mgbaker@cs.stanford.edu"); // pc
$user_diot = $Conf->user_by_email("christophe.diot@sophia.inria.fr"); // pc, red
$user_pdruschel = $Conf->user_by_email("pdruschel@cs.rice.edu"); // pc
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
$rrow = fetch_review($paper1, $user_mgbaker);
xassert_eqq($rrow->t03, "  This is a test of leading whitespace\n\n  It should be preserved\nAnd defended\n");

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
    "shortName_s01" => "Overall merit",
    "options_s01" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\nNo entry\n"
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
    "shortName_s01" => "Overall merit",
    "options_s01" => "1. Reject\n2. Weak reject\n3. Weak accept\nNo entry\n"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// So the 4 score has been removed
assert_search_papers($user_chair, "ovemer:4", "");

// revexp has not
assert_search_papers($user_chair, "revexp:2", "1");
assert_search_papers($user_chair, "has:revexp", "1");

// Stop displaying reviewer expertise
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s02" => "Reviewer expertise",
    "order_s02" => 0
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// Add reviewer expertise back
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s02" => "Reviewer expertise",
    "options_s02" => "1. No familiarity\n2. Some familiarity\n3. Knowledgeable\n4. Expert",
    "order_s02" => 1.5
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// It has been removed from the review
assert_search_papers($user_chair, "has:revexp", "");

// Text fields not there yet
assert_search_papers($user_chair, "has:papsum", "");
assert_search_papers($user_chair, "has:comaut", "");

// Check text field representation
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "ready" => true
]);
$rrow = fetch_review($paper1, $user_mgbaker);
xassert_eqq((string) $rrow->overAllMerit, "2");
xassert_eqq((string) $rrow->reviewerQualification, "1");
xassert_eqq((string) $rrow->t01, "This is the summary\n");
xassert_eqq((string) $rrow->t02, "Comments for äuthor\n");
xassert_eqq((string) $rrow->t03, "Comments for PC\n");

assert_search_papers($user_chair, "has:papsum", "1");
assert_search_papers($user_chair, "has:comaut", "1");
assert_search_papers($user_chair, "has:compc", "1");
assert_search_papers($user_chair, "papsum:this", "1");
assert_search_papers($user_chair, "comaut:author", "1");
assert_search_papers($user_chair, "comaut:äuthor", "1");
assert_search_papers($user_chair, "papsum:author", "");
assert_search_papers($user_chair, "comaut:pc", "");
assert_search_papers($user_chair, "compc:author", "");

// Add extension fields
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s03" => "Score 3", "options_s03" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s03" => 2.03,
    "shortName_s04" => "Score 4", "options_s04" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s04" => 2.04,
    "shortName_s05" => "Score 5", "options_s05" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s05" => 2.05,
    "shortName_s06" => "Score 6", "options_s06" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s06" => 2.06,
    "shortName_s07" => "Score 7", "options_s07" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s07" => 2.07,
    "shortName_s08" => "Score 8", "options_s08" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s08" => 2.08,
    "shortName_s09" => "Score 9", "options_s09" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s09" => 2.09,
    "shortName_s10" => "Score 10", "options_s10" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s10" => 2.10,
    "shortName_s11" => "Score 11", "options_s11" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s11" => 2.11,
    "shortName_s12" => "Score 12", "options_s12" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s12" => 2.12,
    "shortName_s13" => "Score 13", "options_s13" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s13" => 2.13,
    "shortName_s14" => "Score 14", "options_s14" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s14" => 2.14,
    "shortName_s15" => "Score 15", "options_s15" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s15" => 2.15,
    "shortName_s16" => "Score 16", "options_s16" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "order_s16" => 2.16,
    "shortName_t04" => "Text 4", "order_t04" => 5.04,
    "shortName_t05" => "Text 5", "order_t05" => 5.05,
    "shortName_t06" => "Text 6", "order_t06" => 5.06,
    "shortName_t07" => "Text 7", "order_t07" => 5.07,
    "shortName_t08" => "Text 8", "order_t08" => 5.08,
    "shortName_t09" => "Text 9", "order_t09" => 5.09,
    "shortName_t10" => "Text 10", "order_t10" => 5.10,
    "shortName_t11" => "Text 11", "order_t11" => 5.11
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco3" => 1, "sco4" => 2, "sco5" => 3, "sco6" => 0,
    "sco7" => 1, "sco8" => 2, "sco9" => 3, "sco10" => 0,
    "sco11" => 1, "sco12" => 2, "sco13" => 3, "sco14" => 0,
    "sco15" => 1, "sco16" => 3,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat",
    "tex7" => "tiger", "tex8" => "leopard", "tex9" => "tremolo",
    "tex10" => "underwear", "tex11" => "",
    "ready" => true
]);

assert_search_papers($user_chair, "has:sco3", "1");
assert_search_papers($user_chair, "has:sco4", "1");
assert_search_papers($user_chair, "has:sco5", "1");
assert_search_papers($user_chair, "has:sco6", "");
assert_search_papers($user_chair, "has:sco7", "1");
assert_search_papers($user_chair, "has:sco8", "1");
assert_search_papers($user_chair, "has:sco9", "1");
assert_search_papers($user_chair, "has:sco10", "");
assert_search_papers($user_chair, "has:sco11", "1");
assert_search_papers($user_chair, "has:sco12", "1");
assert_search_papers($user_chair, "has:sco13", "1");
assert_search_papers($user_chair, "has:sco14", "");
assert_search_papers($user_chair, "has:sco15", "1");
assert_search_papers($user_chair, "has:sco16", "1");
assert_search_papers($user_chair, "has:tex4", "1");
assert_search_papers($user_chair, "has:tex5", "");
assert_search_papers($user_chair, "has:tex6", "1");
assert_search_papers($user_chair, "has:tex7", "1");
assert_search_papers($user_chair, "has:tex8", "1");
assert_search_papers($user_chair, "has:tex9", "1");
assert_search_papers($user_chair, "has:tex10", "1");
assert_search_papers($user_chair, "has:tex11", "");

$rrow = fetch_review($paper1, $user_mgbaker);
xassert_eqq((string) $rrow->s16, "3");

// Remove some fields and truncate their options
$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s15" => "Score 15", "order_s15" => 0,
    "shortName_s16" => "Score 16", "options_s16" => "1. Yes\n2. No\nNo entry\n",
    "shortName_t10" => "Text 10", "order_t10" => 0
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

$sv = SettingValues::make_request($user_chair, [
    "has_review_form" => 1,
    "shortName_s15" => "Score 15", "options_s15" => "1. Yes\n2. No\nNo entry\n", "order_s15" => 100,
    "shortName_t10" => "Text 10", "order_t10" => 101
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

$rrow = fetch_review($paper1, $user_mgbaker);
xassert(!isset($rrow->s16) || (string) $rrow->s16 === "0");
xassert(!isset($rrow->s15) || (string) $rrow->s15 === "0");
xassert(!isset($rrow->t10) || $rrow->t10 === "");

assert_search_papers($user_chair, "has:sco3", "1");
assert_search_papers($user_chair, "has:sco4", "1");
assert_search_papers($user_chair, "has:sco5", "1");
assert_search_papers($user_chair, "has:sco6", "");
assert_search_papers($user_chair, "has:sco7", "1");
assert_search_papers($user_chair, "has:sco8", "1");
assert_search_papers($user_chair, "has:sco9", "1");
assert_search_papers($user_chair, "has:sco10", "");
assert_search_papers($user_chair, "has:sco11", "1");
assert_search_papers($user_chair, "has:sco12", "1");
assert_search_papers($user_chair, "has:sco13", "1");
assert_search_papers($user_chair, "has:sco14", "");
assert_search_papers($user_chair, "has:sco15", "");
assert_search_papers($user_chair, "has:sco16", "");
assert_search_papers($user_chair, "has:tex4", "1");
assert_search_papers($user_chair, "has:tex5", "");
assert_search_papers($user_chair, "has:tex6", "1");
assert_search_papers($user_chair, "has:tex7", "1");
assert_search_papers($user_chair, "has:tex8", "1");
assert_search_papers($user_chair, "has:tex9", "1");
assert_search_papers($user_chair, "has:tex10", "");
assert_search_papers($user_chair, "has:tex11", "");

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:1", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:0", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");

// check handling of sfields and tfields: don't lose unchanged fields
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco11" => 2, "sco16" => 1, "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "comaut:author", "1");
assert_search_papers($user_chair, "comaut:äuthor", "1");
assert_search_papers($user_chair, "comaut:áuthor", "");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

// check handling of sfields and tfields: no changes at all
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco13" => 3, "sco14" => 0, "sco15" => 0, "sco16" => 1,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
    "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

// check handling of sfields and tfields: clear extension fields
save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "",
    "comaut" => "", "compc" => "", "sco12" => 0,
    "sco13" => 0, "sco14" => 0, "sco15" => 0, "sco16" => 0,
    "tex4" => "", "tex5" => "", "tex6" => "", "tex7" => "",
    "tex8" => "", "tex9" => "", "tex10" => "", "tex11" => "",
    "ready" => true
]);

$rrow = fetch_review($paper1, $user_mgbaker);
xassert(!$rrow->sfields);
xassert(!$rrow->tfields);

save_review(1, $user_mgbaker, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
    "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
    "sco3" => 1, "sco4" => 2, "sco5" => 3, "sco6" => 0, "sco7" => 1,
    "sco8" => 2, "sco9" => 3, "sco10" => 0, "sco11" => 2,
    "sco12" => 2, "sco13" => 3, "sco14" => 0, "sco15" => 0, "sco16" => 1,
    "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
    "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
    "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:0", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

save_review(1, $user_mgbaker, [
    "ovemer" => 3, "sco15" => 2,
    "tex8" => "leopardino", "ready" => true
]);

assert_search_papers($user_chair, "sco3:1", "1");
assert_search_papers($user_chair, "sco4:2", "1");
assert_search_papers($user_chair, "sco5:3", "1");
assert_search_papers($user_chair, "sco6:0", "1");
assert_search_papers($user_chair, "sco7:1", "1");
assert_search_papers($user_chair, "sco8:2", "1");
assert_search_papers($user_chair, "sco9:3", "1");
assert_search_papers($user_chair, "sco10:0", "1");
assert_search_papers($user_chair, "sco11:2", "1");
assert_search_papers($user_chair, "sco12:2", "1");
assert_search_papers($user_chair, "sco13:3", "1");
assert_search_papers($user_chair, "sco14:0", "1");
assert_search_papers($user_chair, "sco15:2", "1");
assert_search_papers($user_chair, "sco16:1", "1");
assert_search_papers($user_chair, "tex4:bobcat", "1");
assert_search_papers($user_chair, "tex6:fisher*", "1");
assert_search_papers($user_chair, "tex7:tiger", "1");
assert_search_papers($user_chair, "tex8:leopard", "");
assert_search_papers($user_chair, "tex8:leopardino", "1");
assert_search_papers($user_chair, "tex9:tremolo", "1");
assert_search_papers($user_chair, "tex11:butt", "1");

// simplify review form
$sv = ["has_review_form" => 1];
for ($i = 2; $i <= 16; ++$i)
    $sv[sprintf("order_s%02d", $i)] = $sv[sprintf("order_t%02d", $i)] = -1;
$sv = SettingValues::make_request($user_chair, $sv);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "review_form");

// saving a JSON review defaults to ready
$paper17 = fetch_paper(17, $user_mgbaker);
$rrow17m = fetch_review($paper17, $user_mgbaker);
xassert(!$rrow17m->reviewModified);

$tf = new ReviewValues($Conf->review_form());
xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
xassert($tf->check_and_save($user_mgbaker, $paper17));

$rrow17m = fetch_review($paper17, $user_mgbaker);
xassert_eq($rrow17m->overAllMerit, 2);
xassert_eq($rrow17m->reviewerQualification, 1);
xassert_eqq($rrow17m->t01, "No summary\n");
xassert_eqq($rrow17m->t02, "No comments\n");
xassert_eqq($rrow17m->reviewOrdinal, 1);
xassert($rrow17m->reviewSubmitted > 0);

// Check review diffs
$paper18 = fetch_paper(18, $user_diot);
$tf = new ReviewValues($Conf->review_form());
xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
xassert($tf->check_and_save($user_diot, $paper18));

$rrow18d = fetch_review($paper18, $user_diot);
$rd = new ReviewDiffInfo($paper18, $rrow18d);
$rd->add_field($Conf->find_review_field("ovemer"), 3);
$rd->add_field($Conf->find_review_field("papsum"), "There definitely is a summary in this position.");
xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch()),
            '{"s01":2,"t01":"No summary\\n"}');
xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch(1)),
            '{"s01":3,"t01":"There definitely is a summary in this position."}');

$rrow18d2 = clone $rrow18d;
xassert_eq($rrow18d2->overAllMerit, 2);
xassert_eq($rrow18d2->reviewerQualification, 1);
xassert_eqq($rrow18d2->t01, "No summary\n");
ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
xassert_eq($rrow18d2->overAllMerit, 3);
xassert_eq($rrow18d2->reviewerQualification, 1);
xassert_eqq($rrow18d2->t01, "There definitely is a summary in this position.");
ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch());
xassert_eq($rrow18d2->overAllMerit, 2);
xassert_eq($rrow18d2->reviewerQualification, 1);
xassert_eqq($rrow18d2->t01, "No summary\n");

$tf = new ReviewValues($Conf->review_form());
xassert($tf->parse_json(["papsum" =>
    "Four score and seven years ago our fathers brought forth on this continent, a new nation, conceived in Liberty, and dedicated to the proposition that all men are created equal.\n\
\n\
Now we are engaged in a great civil war, testing whether that nation, or any nation so conceived and so dedicated, can long endure. We are met on a great battle-field of that war. We have come to dedicate a portion of that field, as a final resting place for those who here gave their lives that that nation might live. It is altogether fitting and proper that we should do this.\n\
\n\
But, in a larger sense, we can not dedicate -- we can not consecrate -- we can not hallow -- this ground. The brave men, living and dead, who struggled here, have consecrated it, far above our poor power to add or detract. The world will little note, nor long remember what we say here, but it can never forget what they did here. It is for us the living, rather, to be dedicated here to the unfinished work which they who fought here have thus far so nobly advanced. It is rather for us to be here dedicated to the great task remaining before us -- that from these honored dead we take increased devotion to that cause for which they gave the last full measure of devotion -- that we here highly resolve that these dead shall not have died in vain -- that this nation, under God, shall have a new birth of freedom -- and that government of the people, by the people, for the people, shall not perish from the earth.\n"]));
xassert($tf->check_and_save($user_diot, $paper18));

$rrow18d = fetch_review($paper18, $user_diot);
$gettysburg = $rrow18d->t01;
$gettysburg2 = str_replace("by the people", "near the people", $gettysburg);

$rd = new ReviewDiffInfo($paper18, $rrow18d);
$rd->add_field($Conf->find_review_field("papsum"), $gettysburg2);

$rrow18d2 = clone $rrow18d;
xassert_eqq($rrow18d2->t01, $gettysburg);
ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
xassert_eqq($rrow18d2->t01, $gettysburg2);
ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch());
xassert_eqq($rrow18d2->t01, $gettysburg);

// check some review visibility policies
$user_external = Contact::create($Conf, null, ["email" => "external@_.com", "name" => "External Reviewer"]);
$user_mgbaker->assign_review(17, $user_external->contactId, REVIEW_EXTERNAL,
    ["round_number" => 3]);
xassert(!$user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
$Conf->save_setting("extrev_view", 0);
save_review(17, $user_external, [
    "ovemer" => 2, "revexp" => 1, "papsum" => "Hi", "comaut" => "Bye", "ready" => true
]);
MailChecker::check_db("test06-17external");
xassert(!$user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
$Conf->save_setting("extrev_view", 1);
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
$Conf->save_setting("extrev_view", 2);
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert($user_external->can_view_review_identity($paper17, $rrow17m));

// per-round review visibility
$user_lixia = $Conf->user_by_email("lixia@cs.ucla.edu");
$tf = new ReviewValues($Conf->review_form());
xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "Radical", "comaut" => "Nonradical"]));
xassert($tf->check_and_save($user_lixia, $paper17));
MailChecker::check_db("test06-17lixia");
$rrow17h = fetch_review($paper17, $user_lixia);
$rrow17x = fetch_review($paper17, $user_external);
xassert_eqq($rrow17m->reviewRound, 3);
xassert_eqq($rrow17h->reviewRound, 1);
xassert_eqq($rrow17x->reviewRound, 3);
Contact::update_rights();

xassert($user_mgbaker->can_view_review($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review($paper17, $rrow17x));
xassert($user_lixia->can_view_review($paper17, $rrow17m));
xassert($user_lixia->can_view_review($paper17, $rrow17h));
xassert($user_lixia->can_view_review($paper17, $rrow17x));
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert($user_external->can_view_review($paper17, $rrow17h));
xassert($user_external->can_view_review($paper17, $rrow17x));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17x));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17m));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17h));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17x));
xassert($user_external->can_view_review_identity($paper17, $rrow17m));
xassert($user_external->can_view_review_identity($paper17, $rrow17h));
xassert($user_external->can_view_review_identity($paper17, $rrow17x));

$Conf->save_setting("round_settings", 1, '[null,{"extrev_view":0}]');
Contact::update_rights();

xassert($user_mgbaker->can_view_review($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review($paper17, $rrow17x));
xassert($user_lixia->can_view_review($paper17, $rrow17m));
xassert($user_lixia->can_view_review($paper17, $rrow17h));
xassert($user_lixia->can_view_review($paper17, $rrow17x));
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review($paper17, $rrow17h));
xassert($user_external->can_view_review($paper17, $rrow17x));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17x));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17m));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17h));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17x));
xassert($user_external->can_view_review_identity($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
xassert($user_external->can_view_review_identity($paper17, $rrow17x));
assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_lixia, "re:mgbaker", "1 13 17");

// Extrev cannot view R1; PC cannot view R2
$Conf->save_setting("round_settings", 1, '[null,{"extrev_view":0},null,{"pc_seeallrev":-1}]');
Contact::update_rights();

xassert($user_mgbaker->can_view_review($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review($paper17, $rrow17h));
xassert(!$user_mgbaker->can_view_review($paper17, $rrow17x));
xassert(!$user_lixia->can_view_review($paper17, $rrow17m));
xassert($user_lixia->can_view_review($paper17, $rrow17h));
xassert(!$user_lixia->can_view_review($paper17, $rrow17x));
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review($paper17, $rrow17h));
xassert($user_external->can_view_review($paper17, $rrow17x));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17x));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17m));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17h));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17x));
xassert($user_external->can_view_review_identity($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
xassert($user_external->can_view_review_identity($paper17, $rrow17x));
assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_lixia, "re:mgbaker", "1 13 17");

// Extrev cannot view R1; PC cannot view R2 identity
$Conf->save_setting("round_settings", 1, '[null,{"extrev_view":0},null,{"pc_seeblindrev":-1}]');
Contact::update_rights();

xassert($user_mgbaker->can_view_review($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review($paper17, $rrow17x));
xassert($user_lixia->can_view_review($paper17, $rrow17m));
xassert($user_lixia->can_view_review($paper17, $rrow17h));
xassert($user_lixia->can_view_review($paper17, $rrow17x));
xassert($user_external->can_view_review($paper17, $rrow17m));
xassert(!$user_external->can_view_review($paper17, $rrow17h));
xassert($user_external->can_view_review($paper17, $rrow17x));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17m));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17h));
xassert($user_mgbaker->can_view_review_identity($paper17, $rrow17x));
xassert(!$user_lixia->can_view_review_identity($paper17, $rrow17m));
xassert($user_lixia->can_view_review_identity($paper17, $rrow17h));
xassert(!$user_lixia->can_view_review_identity($paper17, $rrow17x));
xassert($user_external->can_view_review_identity($paper17, $rrow17m));
xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
xassert($user_external->can_view_review_identity($paper17, $rrow17x));
assert_search_papers($user_chair, "re:mgbaker", "1 13 17");
assert_search_papers($user_lixia, "re:mgbaker", "1");

save_review(17, $user_external, [
    "ovemer" => 1
]);
assert_search_papers($user_chair, "17 ovemer:2<=1", "");
assert_search_papers($user_chair, "17 ovemer:=1<=1", "17");
assert_search_papers($user_chair, "17 ovemer=1<=1", "17");

save_review(17, $user_pdruschel, [
    "ready" => true, "ovemer" => 1, "revexp" => 1
]);
assert_search_papers($user_chair, "17 ovemer:2<=1", "17");
assert_search_papers($user_chair, "17 ovemer:=2<=1", "17");
assert_search_papers($user_chair, "17 ovemer:1<=1", "17");
assert_search_papers($user_chair, "17 ovemer:=1<=1", "");
assert_search_papers($user_chair, "17 ovemer=1<=1", "");

assert_search_papers($user_chair, "ovemer:1..2", "17 18");
assert_search_papers($user_chair, "ovemer:1..3", "1 17 18");
assert_search_papers($user_chair, "ovemer:1–2", "17");
assert_search_papers($user_chair, "ovemer:1-3", "");
assert_search_papers($user_chair, "ovemer:2..1", "17 18");
assert_search_papers($user_chair, "ovemer:3..1", "1 17 18");

// `r` vs. `rout`
assert_search_papers($user_mgbaker, ["t" => "r", "q" => ""], "1 13 17");
assert_search_papers($user_mgbaker, ["t" => "rout", "q" => ""], "13");
assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13");
assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13");

xassert_assign($user_chair, "paper,action,user\n19,review,new-anonymous");
$user_mgbaker->change_review_token($Conf->fetch_ivalue("select reviewToken from PaperReview where paperId=19 and reviewToken!=0"), true);
assert_search_papers($user_mgbaker, ["t" => "r", "q" => ""], "1 13 17 19");
assert_search_papers($user_mgbaker, ["t" => "rout", "q" => ""], "13 19");
assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet"], "13");
assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet"], "13");
assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13 19");
assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13 19");
assert_search_papers($user_mgbaker, "(internet OR datagram) 13 19", "13 19");

// paper options
assert_search_papers($user_mgbaker, "has:calories", "1 2 3 4 5");
$sv = SettingValues::make_request($user_chair, [
    "has_options" => 1,
    "optn_1" => "Fudge",
    "optid_1" => 1,
    "optfp_1" => 1,
    "optvt_1" => "numeric"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "options");
assert_search_papers($user_mgbaker, "has:fudge", "1 2 3 4 5");

$sv = SettingValues::make_request($user_chair, [
    "has_options" => 1,
    "optn_1" => "Fudge",
    "optid_1" => 1,
    "optfp_1" => 1,
    "optvt_1" => "checkbox"
]);
xassert($sv->execute());
xassert_eqq(join(" ", $sv->changes()), "options");
assert_search_papers($user_mgbaker, "has:fudge", "");

xassert_exit();
