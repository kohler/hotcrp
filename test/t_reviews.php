<?php
// t_reviews.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Reviews_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function save_round_settings($map) {
        $settings = [];
        foreach ($this->conf->round_list() as $rname) {
            $settings[] = isset($map[$rname]) ? $map[$rname] : null;
        }
        $this->conf->save_refresh_setting("round_settings", 1, json_encode_db($settings));
    }

    function test_all() {
        $conf = $this->conf;

        // load users
        $user_chair = $conf->checked_user_by_email("chair@_.com");
        $user_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu"); // pc
        $user_diot = $conf->checked_user_by_email("christophe.diot@sophia.inria.fr"); // pc, red
        $user_pdruschel = $conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc
        $user_mjh = $conf->checked_user_by_email("mjh@isi.edu"); // pc
        $conf->save_setting("rev_open", 1);

        // 1-18 have 3 assignments, reset have 0
        assert_search_papers($user_chair, "re:3", "1-18");
        assert_search_papers($user_chair, "-re:3", "19-30");
        assert_search_papers($user_chair, "ire:3", "1-18");
        assert_search_papers($user_chair, "-ire:3", "19-30");
        assert_search_papers($user_chair, "pre:3", "");
        assert_search_papers($user_chair, "-pre:3", "1-30");
        assert_search_papers($user_chair, "cre:3", "");
        assert_search_papers($user_chair, "-cre:3", "1-30");
        assert_search_papers($user_chair, "re<4", "1-30");
        assert_search_papers($user_chair, "-re<4", "");
        assert_search_papers($user_chair, "re≤3", "1-30");
        assert_search_papers($user_chair, "-re≤3", "");
        assert_search_papers($user_chair, "re<=3", "1-30");
        assert_search_papers($user_chair, "-re<=3", "");
        assert_search_papers($user_chair, "re!=3", "19-30");
        assert_search_papers($user_chair, "-re!=3", "1-18");
        assert_search_papers($user_chair, "re≠3", "19-30");
        assert_search_papers($user_chair, "-re≠3", "1-18");
        assert_search_papers($user_chair, "-re>4", "1-30");
        assert_search_papers($user_chair, "re>4", "");
        assert_search_papers($user_chair, "-re≥3", "19-30");
        assert_search_papers($user_chair, "re≥3", "1-18");
        assert_search_papers($user_chair, "-re>=3", "19-30");
        assert_search_papers($user_chair, "re>=3", "1-18");

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
        $paper1 = $conf->checked_paper_by_id(1, $user_chair);
        $rrow = fetch_review($paper1, $user_mgbaker);
        $review1A = file_get_contents(SiteLoader::find("test/review1A.txt"));
        $tf = ReviewValues::make_text($conf->review_form(), $review1A, "review1A.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker));

        assert_search_papers($user_chair, "ovemer:4", "1");
        $rrow = fetch_review($paper1, $user_mgbaker);
        xassert_eqq($rrow->fval("t03"), "  This is a test of leading whitespace\n\n  It should be preserved\nAnd defended\n");

        // Catch different-conference form
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/Testconf I/', 'Testconf IIII', $review1A), "review1A-1.txt");
        xassert(!$tf->parse_text(false));
        xassert($tf->has_error_at("confid"));

        // Catch invalid value
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/^4/m', 'Mumps', $review1A), "review1A-2.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker));
        xassert_eqq(join(" ", $tf->unchanged), "#1A");
        xassert($tf->has_problem_at("overAllMerit"));

        // “No entry” is invalid
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-3.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker));
        xassert_eqq(join(" ", $tf->unchanged), "#1A");
        xassert($tf->has_problem_at("overAllMerit"));
        xassert(strpos($tf->feedback_text_at("overAllMerit"), "Entry required") !== false);
        //error_log(var_export($tf->message_list(), true));

        // Different reviewer
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: butt@butt.com', $review1A), "review1A-4.txt");
        xassert($tf->parse_text(false));
        xassert(!$tf->check_and_save($user_mgbaker));
        xassert($tf->has_problem_at("reviewerEmail"));

        // Different reviewer
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baaaker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $review1A)), "review1A-5.txt");
        xassert($tf->parse_text(false));
        xassert(!$tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
        xassert($tf->has_problem_at("reviewerEmail"));

        // Different reviewer with same name (OK)
        // Also add a description of the field
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5. Strong accept", $review1A)), "review1A-5.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker, $paper1, fetch_review($paper1, $user_mgbaker)));
        xassert(!$tf->has_problem_at("reviewerEmail"));
        //error_log(var_export($tf->message_list(), true));


        // Settings changes

        // Check settings aspects
        $siset = $conf->si_set();
        $si = $conf->si("sub_banal_data_0");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal");
        $si = $conf->si("sub_banal_data_4");
        xassert_eqq($si->storage_type, Si::SI_DATA | Si::SI_SLICE);
        xassert_eqq($si->storage_name(), "sub_banal_4");
        $si = $conf->si("sub_banal_m1");
        xassert_eqq($si->group, "decisions");

        // Check message defaults
        $sv = SettingValues::make_request($user_chair, []);
        $s = $conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") === false);
        $sv = SettingValues::make_request($user_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Whatever\n"
        ])->parse();
        $s = $conf->si("preference_instructions")->default_value($sv);
        xassert(strpos($s, "review preference") !== false);
        xassert(strpos($s, "topic") !== false);

        // Add “no entry”
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Overall merit",
            "rf__1__id" => "s01",
            "rf__1__choices" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\nNo entry\n"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        // Now it's OK to save “no entry”
        $tf = ReviewValues::make_text($conf->review_form(), preg_replace('/^4/m', 'No entry', $review1A), "review1A-6.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker));
        xassert_eqq(join(" ", $tf->updated ?? []), "#1A");
        xassert(!$tf->has_problem_at("overAllMerit"));
        //error_log(var_export($tf->message_list(), true));

        assert_search_papers($user_chair, "has:ovemer", "");

        // Restore overall-merit 4
        $tf = ReviewValues::make_text($conf->review_form(), $review1A, "review1A-7.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_mgbaker));
        xassert_eqq(join(" ", $tf->updated), "#1A");
        xassert(!$tf->has_problem_at("overAllMerit"));
        //error_log(var_export($tf->message_list(), true));

        assert_search_papers($user_chair, "ovemer:4", "1");

        // “4” is no longer a valid overall-merit score
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Overall merit",
            "rf__1__id" => "s01",
            "rf__1__choices" => "1. Reject\n2. Weak reject\n3. Weak accept\nNo entry\n"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        // So the 4 score has been removed
        assert_search_papers($user_chair, "ovemer:4", "");

        // revexp has not
        assert_search_papers($user_chair, "revexp:2", "1");
        assert_search_papers($user_chair, "has:revexp", "1");

        // Stop displaying reviewer expertise
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Reviewer expertise",
            "rf__1__id" => "s02",
            "rf__1__order" => 0
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        // Add reviewer expertise back
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Reviewer expertise",
            "rf__1__id" => "s02",
            "rf__1__choices" => "1. No familiarity\n2. Some familiarity\n3. Knowledgeable\n4. Expert",
            "rf__1__order" => 1.5
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

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
        xassert_eqq((string) $rrow->fval("s01"), "2");
        xassert_eqq((string) $rrow->fval("s02"), "1");
        xassert_eqq((string) $rrow->fval("t01"), "This is the summary\n");
        xassert_eqq((string) $rrow->fval("t02"), "Comments for äuthor\n");
        xassert_eqq((string) $rrow->fval("t03"), "Comments for PC\n");
        //error_log($conf->setting_data("review_form"));

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
            "rf__1__name" => "Score 3", "rf__1__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__1__order" => 2.03, "rf__1__id" => "s03",
            "rf__2__name" => "Score 4", "rf__2__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__2__order" => 2.04, "rf__2__id" => "s04",
            "rf__3__name" => "Score 5", "rf__3__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__3__order" => 2.05, "rf__3__id" => "s05",
            "rf__4__name" => "Score 6", "rf__4__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__4__order" => 2.06, "rf__4__id" => "s06",
            "rf__5__name" => "Score 7", "rf__5__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__5__order" => 2.07, "rf__5__id" => "s07",
            "rf__6__name" => "Score 8", "rf__6__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__6__order" => 2.08, "rf__6__id" => "s08",
            "rf__7__name" => "Score 9", "rf__7__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__7__order" => 2.09, "rf__7__id" => "s09",
            "rf__8__name" => "Score 10", "rf__8__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__8__order" => 2.10, "rf__8__id" => "s10",
            "rf__9__name" => "Score 11", "rf__9__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__9__order" => 2.11, "rf__9__id" => "s11",
            "rf__10__name" => "Score 12", "rf__10__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__10__order" => 2.12, "rf__10__id" => "s12",
            "rf__11__name" => "Score 13", "rf__11__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__11__order" => 2.13, "rf__11__id" => "s13",
            "rf__12__name" => "Score 14", "rf__12__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__12__order" => 2.14, "rf__12__id" => "s14",
            "rf__13__name" => "Score 15", "rf__13__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__13__order" => 2.15, "rf__13__id" => "s15",
            "rf__14__name" => "Score 16", "rf__14__choices" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf__14__order" => 2.16, "rf__14__id" => "s16",
            "rf__15__name" => "Text 4", "rf__15__order" => 5.04, "rf__15__id" => "t04",
            "rf__16__name" => "Text 5", "rf__16__order" => 5.05, "rf__16__id" => "t05",
            "rf__17__name" => "Text 6", "rf__17__order" => 5.06, "rf__17__id" => "t06",
            "rf__18__name" => "Text 7", "rf__18__order" => 5.07, "rf__18__id" => "t07",
            "rf__19__name" => "Text 8", "rf__19__order" => 5.08, "rf__19__id" => "t08",
            "rf__20__name" => "Text 9", "rf__20__order" => 5.09, "rf__20__id" => "t09",
            "rf__21__name" => "Text 10", "rf__21__order" => 5.10, "rf__21__id" => "t10",
            "rf__22__name" => "Text 11", "rf__22__order" => 5.11, "rf__22__id" => "t11"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

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
        xassert_eqq((string) $rrow->fval("s16"), "3");

        // Remove some fields and truncate their options
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Score 15", "rf__1__order" => 0, "rf__1__id" => "s15",
            "rf__2__name" => "Score 16", "rf__2__choices" => "1. Yes\n2. No\nNo entry\n", "rf__2__id" => "s16",
            "rf__3__name" => "Text 10", "rf__3__order" => 0, "rf__3__id" => "t10"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Score 15", "rf__1__choices" => "1. Yes\n2. No\nNo entry\n", "rf__1__order" => 100, "rf__1__id" => "s15",
            "rf__2__name" => "Text 10", "rf__2__order" => 101, "rf__2__id" => "t10"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        $rrow = fetch_review($paper1, $user_mgbaker);
        xassert($rrow->fval("s15") === null || (string) $rrow->fval("s15") === "0");
        xassert($rrow->fval("s16") === null || (string) $rrow->fval("s16") === "0");
        xassert($rrow->fval("t10") === null || (string) $rrow->fval("t10") === "");

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

        $rrow = $conf->fetch_first_object("select * from PaperReview where paperId=1 and contactId=?", $user_mgbaker->contactId);
        xassert(!!$rrow);
        xassert($rrow->sfields === null);
        xassert($rrow->tfields === null);

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
        $sx = ["has_review_form" => 1];
        for ($i = 3, $ctr = 1; $i <= 16; ++$i) {
            $sx["rf__{$ctr}__id"] = sprintf("s%02d", $i);
            $sx["rf__{$ctr}__delete"] = true;
            ++$ctr;
            $sx["rf__{$ctr}__id"] = sprintf("t%02d", $i);
            $sx["rf__{$ctr}__delete"] = true;
            ++$ctr;
        }
        $sv = SettingValues::make_request($user_chair, $sx);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "review_form");

        // saving a JSON review defaults to ready
        xassert_assign($user_chair, "paper,lead\n17,pdruschel\n");
        $paper17 = $user_mgbaker->checked_paper_by_id(17);

        xassert_eqq($paper17->review_type($user_mgbaker), REVIEW_PRIMARY);
        xassert_eqq($paper17->review_type($user_diot), 0);
        xassert(!$user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_NEVER);
        Contact::update_rights();
        xassert($user_mgbaker->can_view_authors($paper17));
        xassert($user_diot->can_view_authors($paper17));
        xassert($user_pdruschel->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_OPTIONAL);
        Contact::update_rights();
        xassert(!$user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_UNTILREVIEW);
        Contact::update_rights();
        xassert(!$user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        xassert(!$user_pdruschel->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_ALWAYS);

        $rrow17m = fetch_review($paper17, $user_mgbaker);
        xassert(!$rrow17m->reviewModified);

        $tf = new ReviewValues($conf->review_form());
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($user_mgbaker, $paper17));

        $rrow17m = fetch_review($paper17, $user_mgbaker);
        xassert_eq($rrow17m->fval("s01"), 2);
        xassert_eq($rrow17m->fval("s02"), 1);
        xassert_eqq($rrow17m->fval("t01"), "No summary\n");
        xassert_eqq($rrow17m->fval("t02"), "No comments\n");
        xassert_eqq($rrow17m->reviewOrdinal, 1);
        xassert($rrow17m->reviewSubmitted > 0);

        xassert(!$user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_NEVER);
        Contact::update_rights();
        xassert($user_mgbaker->can_view_authors($paper17));
        xassert($user_diot->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_OPTIONAL);
        Contact::update_rights();
        xassert(!$user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_UNTILREVIEW);
        Contact::update_rights();
        xassert($user_mgbaker->can_view_authors($paper17));
        xassert(!$user_diot->can_view_authors($paper17));
        $conf->save_setting("sub_blind", Conf::BLIND_ALWAYS);

        // Check review diffs
        $paper18 = $user_diot->checked_paper_by_id(18);
        $tf = new ReviewValues($conf->review_form());
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($user_diot, $paper18));

        $rrow18d = fetch_review($paper18, $user_diot);
        $rd = new ReviewDiffInfo($paper18, $rrow18d);
        $rd->add_field($conf->find_review_field("ovemer"), 3);
        $rd->add_field($conf->find_review_field("papsum"), "There definitely is a summary in this position.");
        xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch()),
                    '{"s01":2,"t01":"No summary\\n"}');
        xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch(1)),
                    '{"s01":3,"t01":"There definitely is a summary in this position."}');

        $rrow18d2 = clone $rrow18d;
        xassert_eq($rrow18d2->fval("s01"), 2);
        xassert_eq($rrow18d2->fval("s02"), 1);
        xassert_eqq($rrow18d2->fval("t01"), "No summary\n");
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
        xassert_eq($rrow18d2->fval("s01"), 3);
        xassert_eq($rrow18d2->fval("s02"), 1);
        xassert_eqq($rrow18d2->fval("t01"), "There definitely is a summary in this position.");
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch());
        xassert_eq($rrow18d2->fval("s01"), 2);
        xassert_eq($rrow18d2->fval("s02"), 1);
        xassert_eqq($rrow18d2->fval("t01"), "No summary\n");

        $tf = new ReviewValues($conf->review_form());
        xassert($tf->parse_json(["papsum" =>
            "Four score and seven years ago our fathers brought forth on this continent, a new nation, conceived in Liberty, and dedicated to the proposition that all men are created equal.\n\
        \n\
        Now we are engaged in a great civil war, testing whether that nation, or any nation so conceived and so dedicated, can long endure. We are met on a great battle-field of that war. We have come to dedicate a portion of that field, as a final resting place for those who here gave their lives that that nation might live. It is altogether fitting and proper that we should do this.\n\
        \n\
        But, in a larger sense, we can not dedicate -- we can not consecrate -- we can not hallow -- this ground. The brave men, living and dead, who struggled here, have consecrated it, far above our poor power to add or detract. The world will little note, nor long remember what we say here, but it can never forget what they did here. It is for us the living, rather, to be dedicated here to the unfinished work which they who fought here have thus far so nobly advanced. It is rather for us to be here dedicated to the great task remaining before us -- that from these honored dead we take increased devotion to that cause for which they gave the last full measure of devotion -- that we here highly resolve that these dead shall not have died in vain -- that this nation, under God, shall have a new birth of freedom -- and that government of the people, by the people, for the people, shall not perish from the earth.\n"]));
        xassert($tf->check_and_save($user_diot, $paper18));

        $rrow18d = fetch_review($paper18, $user_diot);
        $gettysburg = $rrow18d->fval("t01");
        $gettysburg2 = str_replace("by the people", "near the people", $gettysburg);

        $rd = new ReviewDiffInfo($paper18, $rrow18d);
        $rd->add_field($conf->find_review_field("papsum"), $gettysburg2);

        $rrow18d2 = clone $rrow18d;
        xassert_eqq($rrow18d2->fval("t01"), $gettysburg);
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
        xassert_eqq($rrow18d2->fval("t01"), $gettysburg2);
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch());
        xassert_eqq($rrow18d2->fval("t01"), $gettysburg);

        // offline review parsing for UTF-8 review questions
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Questions for authors’ response",
            "rf__1__description" => "Specific questions that could affect your accept/reject decision. Remember that the authors have limited space and must respond to all reviewers.",
            "rf__1__visibility" => "au",
            "rf__1__order" => 5,
            "rf__1__id" => "t04"
        ]);
        xassert($sv->execute());

        $review18A = file_get_contents(SiteLoader::find("test/review18A.txt"));
        $tf = ReviewValues::make_text($conf->review_form(), $review18A, "review18A.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_diot));
        xassert_eqq($tf->summary_status(), MessageSet::SUCCESS);
        xassert_eqq($tf->full_feedback_text(), "Review #18A updated.\n");

        $tf = ReviewValues::make_text($conf->review_form(), $review18A, "review18A.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_diot));
        xassert_eqq($tf->summary_status(), MessageSet::WARNING);
        xassert_eqq($tf->full_feedback_text(), "Review #18A unchanged.\n");

        $rrow = fetch_review($paper18, $user_diot);
        xassert_eqq($rrow->fval("t04"), "This is the stuff I want to add for the authors’ response.\n");

        $review18A2 = str_replace("This is the stuff", "That was the stuff",
            str_replace("authors’ response\n", "authors' response\n", $review18A));
        $tf = ReviewValues::make_text($conf->review_form(), $review18A2, "review18A2.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_diot));

        $rrow = fetch_review($paper18, $user_diot);
        xassert_eqq($rrow->fval("t04"), "That was the stuff I want to add for the authors’ response.\n");

        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "Questions for authors’ response (hidden from authors)",
            "rf__1__name_force" => 1,
            "rf__1__id" => "t04"
        ]);
        xassert($sv->execute());

        $review18A3 = str_replace("That was the stuff", "Whence the stuff",
            str_replace("authors' response\n", "authors' response (hidden from authors)\n", $review18A2));
        $tf = ReviewValues::make_text($conf->review_form(), $review18A3, "review18A3.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_diot));

        $rrow = fetch_review($paper18, $user_diot);
        xassert_eqq($rrow->fval("t04"), "Whence the stuff I want to add for the authors’ response.\n");

        $review18A4 = file_get_contents(SiteLoader::find("test/review18A-4.txt"));
        $tf = ReviewValues::make_text($conf->review_form(), $review18A4, "review18A-4.txt");
        xassert($tf->parse_text(false));
        xassert($tf->check_and_save($user_diot));

        $rrow = fetch_review($paper18, $user_diot);
        xassert(str_ends_with($rrow->fval("t01"), "\n==+== Want to make sure this works\n"));
        xassert_eqq($rrow->fval("t04"), "Whitherto the stuff I want to add for the authors’ response.\n");

        // check some review visibility policies
        $user_external = Contact::make_keyed($conf, ["email" => "external@_.com", "name" => "External Reviewer"])->store();
        assert(!!$user_external);
        $user_mgbaker->assign_review(17, $user_external->contactId, REVIEW_EXTERNAL,
            ["round_number" => $conf->round_number("R2", false)]);
        xassert(!$user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        xassert(!$user_mjh->can_view_review($paper17, $rrow17m));
        $conf->save_setting("extrev_view", 0);
        save_review(17, $user_external, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "Hi", "comaut" => "Bye", "ready" => true
        ]);
        MailChecker::check_db("test06-17external");
        xassert(!$user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        $conf->save_setting("extrev_view", 1);
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        $conf->save_setting("extrev_view", 2);
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));

        // per-round review visibility
        $user_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $tf = new ReviewValues($conf->review_form());
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "Radical", "comaut" => "Nonradical"]));
        xassert($tf->check_and_save($user_lixia, $paper17));
        MailChecker::check_db("test06-17lixia");
        $rrow17h = fetch_review($paper17, $user_lixia);
        $rrow17x = fetch_review($paper17, $user_external);
        xassert_eqq($rrow17m->reviewRound, $conf->round_number("R2", false));
        xassert_eqq($rrow17h->reviewRound, $conf->round_number("R1", false));
        xassert_eqq($rrow17x->reviewRound, $conf->round_number("R2", false));
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

        // check round_number(..., true) works
        xassert_eqq($conf->setting_data("tag_rounds"), "R1 R2 R3");
        xassert_eqq($conf->round_number("R1", false), 1);
        xassert_eqq($conf->round_number("R1", true), 1);
        xassert_eqq($conf->round_number("R5", false), null);
        xassert_eqq($conf->round_number("R5", true), 4);
        xassert_eqq($conf->setting_data("tag_rounds"), "R1 R2 R3 R5");

        // check the settings page works for round tags
        xassert_eqq($conf->assignment_round(false), 0);
        xassert_eqq($conf->assignment_round(true), 0);
        $sv = SettingValues::make_request($user_chair, [
            "extrev_roundtag" => "R1"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 0);
        xassert_eqq($conf->assignment_round(true), 1);
        $sv = SettingValues::make_request($user_chair, [
            "rev_roundtag" => "R3",
            "extrev_roundtag" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 3);
        xassert_eqq($conf->assignment_round(true), 0);
        $sv = SettingValues::make_request($user_chair, [
            "extrev_roundtag" => "default"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 3);
        xassert_eqq($conf->assignment_round(true), 3);
        xassert_eqq($conf->setting("extrev_roundtag"), null);
        $sv = SettingValues::make_request($user_chair, [
            "rev_roundtag" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 0);
        xassert_eqq($conf->assignment_round(true), 0);
        xassert_eqq($conf->setting("rev_roundtag"), null);
        xassert_eqq($conf->setting_data("rev_roundtag"), null);

        $this->save_round_settings(["R1" => ["extrev_view" => 0]]);
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
        $this->save_round_settings(["R1" => ["extrev_view" => 0], "R2" => ["pc_seeallrev" => -1]]);
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
        $this->save_round_settings(["R1" => ["extrev_view" => 0], "R2" => ["pc_seeblindrev" => -1]]);
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

        // new external reviewer does not get combined email
        $conf->save_refresh_setting("round_settings", null);
        $conf->save_refresh_setting("extrev_view", 1);
        $conf->save_refresh_setting("pcrev_editdelegate", 2);
        Contact::update_rights();
        MailChecker::clear();

        $xqreq = new Qrequest("POST", ["email" => "external2@_.com", "name" => "Jo March", "affiliation" => "Concord"]);
        $result = RequestReview_API::requestreview($user_lixia, $xqreq, $paper17);
        $result = JsonResult::make($result);
        MailChecker::check_db("test06-external2-request17");
        xassert($result->content["ok"]);

        $user_external2 = $conf->checked_user_by_email("external2@_.com");
        save_review(17, $user_external2, [
            "ready" => true, "ovemer" => 3, "revexp" => 3
        ]);
        MailChecker::check_db("test06-external2-approval17");

        save_review(17, $user_lixia, ["ready" => true], fetch_review(17, $user_external2));
        MailChecker::check_db("test06-external2-submit17");

        // review requests
        assert_search_papers($user_chair, "has:proposal", "");
        assert_search_papers($user_lixia, "has:proposal", "");
        assert_search_papers($user_mgbaker, "has:proposal", "");
        assert_search_papers($user_mjh, "has:proposal", "");
        assert_search_papers($user_chair, "re:proposal", "");
        assert_search_papers($user_lixia, "re:proposal", "");
        assert_search_papers($user_mgbaker, "re:proposal", "");
        assert_search_papers($user_mjh, "re:proposal", "");

        $conf->save_refresh_setting("extrev_chairreq", 1);
        Contact::update_rights();

        $xqreq = new Qrequest("POST", ["email" => "external3@_.com", "name" => "Amy March", "affiliation" => "Transcendent"]);
        $result = RequestReview_API::requestreview($user_lixia, $xqreq, $paper17);
        $result = JsonResult::make($result);
        MailChecker::check_db("test06-external3-request17");

        assert_search_papers($user_chair, "has:proposal", "17");
        assert_search_papers($user_lixia, "has:proposal", "17");
        assert_search_papers($user_mgbaker, "has:proposal", "17");
        assert_search_papers($user_mjh, "has:proposal", "");
        assert_search_papers($user_chair, "re:proposal", "17");
        assert_search_papers($user_lixia, "re:proposal", "17");
        assert_search_papers($user_mgbaker, "re:proposal", "17");
        assert_search_papers($user_mjh, "re:proposal", "");

        assert_search_papers($user_chair, "has:proposal admin:me", "17");
        assert_search_papers($user_lixia, "has:proposal admin:me", "");
        assert_search_papers($user_mgbaker, "has:proposal", "17");

        // `r` vs. `rout`
        assert_search_papers($user_mgbaker, ["t" => "r", "q" => ""], "1 13 17");
        assert_search_papers($user_mgbaker, ["t" => "rout", "q" => ""], "13");
        assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13");
        assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13");

        xassert_assign($user_chair, "paper,action,user\n19,review,new-anonymous");
        $user_mgbaker->change_review_token($conf->fetch_ivalue("select reviewToken from PaperReview where paperId=19 and reviewToken!=0"), true);
        assert_search_papers($user_mgbaker, ["t" => "r", "q" => ""], "1 13 17 19");
        assert_search_papers($user_mgbaker, ["t" => "rout", "q" => ""], "13 19");
        assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet"], "13");
        assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet"], "13");
        assert_search_papers($user_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13 19");
        assert_search_papers($user_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13 19");
        assert_search_papers($user_mgbaker, "(internet OR datagram) 13 19", "13 19");

        // author review visibility
        xassert(!$user_mjh->can_view_review($paper17, $rrow17m));
        $conf->save_refresh_setting("au_seerev", 2);
        xassert($user_mjh->can_view_review($paper17, $rrow17m));
        xassert_assign_fail($user_mgbaker, "paper,tag\n17,perm:author-read-review\n");
        xassert_assign_fail($user_mjh, "paper,tag\n17,perm:author-read-review\n");
        xassert_assign($user_chair, "paper,tag\n17,perm:author-read-review#-1\n");
        $paper17 = $conf->checked_paper_by_id(17);
        xassert(!$user_mjh->can_view_review($paper17, $rrow17m));
        $conf->save_refresh_setting("au_seerev", null);
        xassert_assign($user_chair, "paper,tag\n17,perm:author-read-review#1\n");
        $paper17 = $conf->checked_paper_by_id(17);
        xassert($user_mjh->can_view_review($paper17, $rrow17m));
        xassert_assign($user_chair, "paper,tag\n17,perm:author-read-review#clear\n");
        $paper17 = $conf->checked_paper_by_id(17);
        xassert(!$user_mjh->can_view_review($paper17, $rrow17m));

        // paper options
        assert_search_papers($user_mgbaker, "has:calories", "1 2 3 4 5");
        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__name" => "Fudge",
            "sf__1__id" => 1,
            "sf__1__order" => 1,
            "sf__1__type" => "numeric"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "options");
        assert_search_papers($user_mgbaker, "has:fudge", "1 2 3 4 5");

        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__name" => "Fudge",
            "sf__1__id" => 1,
            "sf__1__order" => 1,
            "sf__1__type" => "checkbox"
        ]);
        xassert(!$sv->execute());
        assert_search_papers($user_mgbaker, "has:fudge", "1 2 3 4 5");

        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__name" => "Fudge",
            "sf__1__id" => 1,
            "sf__1__order" => 1,
            "sf__1__delete" => 1,
            "sf__2__name" => "Fudge",
            "sf__2__id" => "\$",
            "sf__2__type" => "checkbox",
            "sf__2__order" => 2
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "options");
        assert_search_papers($user_mgbaker, "has:fudge", "");

        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__name" => "Brownies",
            "sf__1__id" => "\$",
            "sf__1__order" => 100,
            "sf__1__type" => "numeric"
        ]);
        xassert($sv->execute());
        xassert_eqq(join(" ", $sv->updated_fields()), "options");
        assert_search_papers($user_mgbaker, "has:brownies", "");

        $opts = array_values(Options_SettingParser::configurable_options($conf));
        xassert_eqq(count($opts), 2);
        xassert_eqq($opts[0]->name, "Fudge");
        xassert_eqq($opts[1]->name, "Brownies");

        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__name" => "Brownies",
            "sf__1__id" => "\$",
            "sf__1__order" => 100,
            "sf__1__type" => "numeric"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert($sv->has_error_at("sf__1__name"));

        $sv = SettingValues::make_request($user_chair, [
            "has_options" => 1,
            "sf__1__id" => "\$",
            "sf__1__order" => 100,
            "sf__1__type" => "numeric"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Entry required"), false);
        xassert($sv->has_error_at("sf__1__name"));

        // decision settings
        xassert_eqq(json_encode($conf->decision_map()), '{"0":"Unspecified","1":"Accepted","-1":"Rejected"}');
        xassert_eqq($conf->setting("decisions"), null);
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__name" => "Accepted!",
            "decision__1__id" => "1",
            "decision__2__name" => "Newly accepted",
            "decision__2__id" => "\$"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($conf->decision_map()), '{"0":"Unspecified","1":"Accepted!","2":"Newly accepted","-1":"Rejected"}');
        xassert_eqq($conf->setting("decisions"), null);
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "1",
            "decision__1__delete" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Accept-category decision"), false);
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Rejected",
            "decision__1__name_force" => "1"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($conf->decision_map()), '{"0":"Unspecified","2":"Newly accepted","-1":"Rejected"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "2",
            "decision__1__name" => "Really Rejected",
            "decision__1__name_force" => "1"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($conf->decision_map()), '{"0":"Unspecified","2":"Really Rejected","-1":"Rejected"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_decisions" => 1,
            "decision__1__id" => "\$"
        ]);
        xassert(!$sv->execute());

        // topic settings
        xassert_eqq(json_encode($conf->topic_set()->as_array()), '[]');
        $sv = SettingValues::make_request($user_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart\n   Barf"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart"
        ]);
        xassert(!$sv->execute());
        xassert_eqq($sv->reqstr("topic__3__name"), "Fart");
        xassert($sv->has_error_at("topic__3__name"));
        xassert_neqq(strpos($sv->full_feedback_text(), "is not unique"), false);
        xassert_eqq(json_encode($conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_topics" => 1,
            "topic__newlist" => "Fart2"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode($conf->topic_set()->as_array()), '{"2":"Barf","1":"Fart","3":"Fart2"}');
        $sv = SettingValues::make_request($user_chair, [
            "has_topics" => 1,
            "topic__1__id" => "2",
            "topic__1__name" => "Fért",
            "topic__2__id" => "",
            "topic__2__name" => "Festival Fartal",
            "topic__3__id" => "\$",
            "topic__3__name" => "Fet",
            "topic__newlist" => "Fart3"
        ]);
        xassert($sv->execute());
        xassert_eqq(json_encode_db($conf->topic_set()->as_array()), '{"1":"Fart","3":"Fart2","6":"Fart3","2":"Fért","4":"Festival Fartal","5":"Fet"}');

        // review settings
        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__name" => "B9",
            "rf__1__id" => "s03",
            "rf__1__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I",
            "rf__2__name" => "B15",
            "rf__2__id" => "s04",
            "rf__2__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J\n11. K\n12. L\n13. M\n14. N\n15. O",
            "rf__3__name" => "B10",
            "rf__3__id" => "s06",
            "rf__3__choices" => "1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H\n9. I\n10. J",
            "rf__4__name" => "B5",
            "rf__4__id" => "s07",
            "rf__4__choices" => "A. A\nB. B\nC. C\nD. D\nE. E"
        ]);
        xassert($sv->execute());
        $rf = $conf->find_review_field("B5");
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv1");
        $rf = $conf->find_review_field("B9");
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(7), "sv sv7");
        xassert_eqq($rf->value_class(8), "sv sv8");
        xassert_eqq($rf->value_class(9), "sv sv9");
        $rf = $conf->find_review_field("B15");
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv2");
        xassert_eqq($rf->value_class(4), "sv sv3");
        xassert_eqq($rf->value_class(5), "sv sv3");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(9), "sv sv6");
        xassert_eqq($rf->value_class(10), "sv sv6");
        xassert_eqq($rf->value_class(11), "sv sv7");
        xassert_eqq($rf->value_class(12), "sv sv7");
        xassert_eqq($rf->value_class(13), "sv sv8");
        xassert_eqq($rf->value_class(14), "sv sv8");
        xassert_eqq($rf->value_class(15), "sv sv9");
        $rf = $conf->find_review_field("B10");
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv2");
        xassert_eqq($rf->value_class(3), "sv sv3");
        xassert_eqq($rf->value_class(4), "sv sv4");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(8), "sv sv7");
        xassert_eqq($rf->value_class(9), "sv sv8");
        xassert_eqq($rf->value_class(10), "sv sv9");

        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__id" => "s03",
            "rf__1__colors" => "svr",
            "rf__2__id" => "s04",
            "rf__2__colors" => "svr",
            "rf__3__id" => "s06",
            "rf__3__colors" => "svr",
            "rf__4__id" => "s07",
            "rf__4__colors" => "svr"
        ]);
        xassert($sv->execute());
        $rf = $conf->find_review_field("B5");
        xassert_eqq($rf->value_class(1), "sv sv1");
        xassert_eqq($rf->value_class(2), "sv sv3");
        xassert_eqq($rf->value_class(3), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(5), "sv sv9");
        $rf = $conf->find_review_field("B9");
        xassert_eqq($rf->value_class(1), "sv sv9");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(6), "sv sv4");
        xassert_eqq($rf->value_class(7), "sv sv3");
        xassert_eqq($rf->value_class(8), "sv sv2");
        xassert_eqq($rf->value_class(9), "sv sv1");
        $rf = $conf->find_review_field("B15");
        xassert_eqq($rf->value_class(15), "sv sv1");
        xassert_eqq($rf->value_class(14), "sv sv2");
        xassert_eqq($rf->value_class(13), "sv sv2");
        xassert_eqq($rf->value_class(12), "sv sv3");
        xassert_eqq($rf->value_class(11), "sv sv3");
        xassert_eqq($rf->value_class(10), "sv sv4");
        xassert_eqq($rf->value_class(9), "sv sv4");
        xassert_eqq($rf->value_class(8), "sv sv5");
        xassert_eqq($rf->value_class(7), "sv sv6");
        xassert_eqq($rf->value_class(6), "sv sv6");
        xassert_eqq($rf->value_class(5), "sv sv7");
        xassert_eqq($rf->value_class(4), "sv sv7");
        xassert_eqq($rf->value_class(3), "sv sv8");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");
        $rf = $conf->find_review_field("B10");
        xassert_eqq($rf->value_class(10), "sv sv1");
        xassert_eqq($rf->value_class(9), "sv sv2");
        xassert_eqq($rf->value_class(8), "sv sv3");
        xassert_eqq($rf->value_class(7), "sv sv4");
        xassert_eqq($rf->value_class(6), "sv sv5");
        xassert_eqq($rf->value_class(5), "sv sv5");
        xassert_eqq($rf->value_class(4), "sv sv6");
        xassert_eqq($rf->value_class(3), "sv sv7");
        xassert_eqq($rf->value_class(2), "sv sv8");
        xassert_eqq($rf->value_class(1), "sv sv9");

        $sv = SettingValues::make_request($user_chair, [
            "has_review_form" => 1,
            "rf__1__id" => "s90",
            "rf__1__choices" => "1. A\n2. B\n"
        ]);
        xassert(!$sv->execute());
        xassert_neqq(strpos($sv->full_feedback_text(), "Entry required"), false);

        // review form setting updates
        $updater = new UpdateSchema($conf);
        xassert_eqq($updater->v258_review_form_setting('{"overAllMerit":{"name":"Overall merit","position":1,"visibility":"au","options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},"reviewerQualification":{"name":"Reviewer expertise","position":2,"visibility":"au","options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},"t01":{"name":"Paper summary","position":3,"visibility":"au"},"t02":{"name":"Comments for author","position":4,"visibility":"au"},"t03":{"name":"Comments for PC","position":5,"visibility":"pc"}}'),
            '[{"name":"Overall merit","visibility":"au","options":["Reject","Weak reject","Weak accept","Accept","Strong accept"],"id":"s01","order":1},{"name":"Reviewer expertise","visibility":"au","options":["No familiarity","Some familiarity","Knowledgeable","Expert"],"id":"s02","order":2},{"name":"Paper summary","visibility":"au","id":"t01","order":3},{"name":"Comments for author","visibility":"au","id":"t02","order":4},{"name":"Comments for PC","visibility":"pc","id":"t03","order":5}]');
        xassert_eqq($updater->v258_review_form_setting('[{"id":"s01","name":"Overall merit","position":1,"visibility":"au","options":["Reject","Weak reject","Weak accept","Strong accept"],"option_letter":"A","required":true},{"id":"s02","name":"Reviewer expertise","position":2,"visibility":"au","options":["Some familiarity","Knowledgeable","Expert"],"option_letter":"X","option_class_prefix":"sv-blpu","required":true},{"id":"t02","name":"Comments for author","position":3,"visibility":"au"},{"id":"t03","name":"Comments for PC","position":4,"visibility":"pc"}]'),
            '[{"id":"s01","name":"Overall merit","visibility":"au","options":["Reject","Weak reject","Weak accept","Strong accept"],"option_letter":"A","required":true,"scheme":"svr","order":1},{"id":"s02","name":"Reviewer expertise","visibility":"au","options":["Some familiarity","Knowledgeable","Expert"],"option_letter":"X","required":true,"scheme":"publ","order":2},{"id":"t02","name":"Comments for author","visibility":"au","order":3},{"id":"t03","name":"Comments for PC","visibility":"pc","order":4}]');
        xassert_eqq($updater->v258_review_form_setting('{"overAllMerit":{"name":"Overall merit","view_score":"author","position":1,"options":["Reject","Weak reject","Weak accept","Accept"],"round_mask":0},"reviewerQualification":{"name":"Reviewer expertise","view_score":"author","position":2,"options":["No familiarity","Some familiarity","Knowledgeable","Expert"],"round_mask":0},"paperSummary":{"name":"Paper summary","view_score":"author","position":3,"round_mask":0},"commentsToAuthor":{"name":"Comments for author","view_score":"author","position":4,"round_mask":0},"commentsToPC":{"name":"Comments for PC","view_score":"pc","position":5,"round_mask":0},"commentsToAddress":{"view_score":null,"round_mask":0},"fixability":{"view_score":null,"options":[],"round_mask":0},"grammar":{"view_score":null,"options":[],"round_mask":0},"interestToCommunity":{"view_score":null,"options":[],"round_mask":0},"likelyPresentation":{"view_score":null,"options":[],"round_mask":0},"longevity":{"view_score":null,"options":[],"round_mask":0},"novelty":{"view_score":null,"options":[],"round_mask":0},"potential":{"view_score":null,"options":[],"round_mask":0},"strengthOfPaper":{"view_score":null,"round_mask":0},"suitableForShort":{"view_score":null,"options":[],"round_mask":0},"technicalMerit":{"view_score":null,"options":[],"round_mask":0},"textField7":{"view_score":null,"round_mask":0},"textField8":{"view_score":null,"round_mask":0},"weaknessOfPaper":{"view_score":null,"round_mask":0}}'),
            '[{"name":"Overall merit","options":["Reject","Weak reject","Weak accept","Accept"],"id":"s01","visibility":"au","order":1},{"name":"Reviewer expertise","options":["No familiarity","Some familiarity","Knowledgeable","Expert"],"id":"s02","visibility":"au","order":2},{"name":"Paper summary","id":"t01","visibility":"au","order":3},{"name":"Comments for author","id":"t02","visibility":"au","order":4},{"name":"Comments for PC","id":"t03","visibility":"pc","order":5},{"id":"t04"},{"id":"s11"},{"id":"s07"},{"id":"s05"},{"id":"s08"},{"id":"s06"},{"id":"s03"},{"id":"s10"},{"id":"t06"},{"id":"s09"},{"id":"s04"},{"id":"t07"},{"id":"t08"},{"id":"t05"}]');
        xassert_eqq($updater->v258_review_form_setting('{"novelty":{"name":"Overall merit","position":1,"visibility":"au","options":["Reject","Weak paper, though I will not fight strongly against it","OK paper, but I will not champion it","Good paper, I will champion it"],"option_letter":"A"},"overAllMerit":{"name":"Reviewer Confidence","position":2,"visibility":"au","options":["I am not an expert; my evaluation is that of an informed outsider","I am knowledgeable in this area, but not an expert","I am an expert in this area"],"option_letter":"X"},"reviewerQualification":{"name":"How likely is the paper to spur discussion?","position":3,"visibility":"au","options":["Will not spur discussion","May spur discussion","Will definitely spur discussion"],"option_letter":"A"},"interestToCommunity":{"name":"Does the paper contain surprising results or a new research direction?","position":4,"visibility":"au","options":["no","Yes"],"option_letter":"A"},"longevity":{"name":"Accept as Paper or Poster?","description":"If you think the paper should be accepted, indicate whether you think it should be as a full paper with presentation, or as a poster to be displayed in the workshop.","position":5,"visibility":"au","options":["Paper with presentation","Poster","Not Applicable"],"option_class_prefix":"svr"},"technicalMerit":{"name":"Nominate for Outstanding New Direction Award?","position":6,"visibility":"pc","options":["Yes","No"],"option_class_prefix":"svr"},"t05":{"name":"Paper summary","description":"Please summarize the paper briefly in your own words.","position":7,"visibility":"au"},"t01":{"name":"Strengths","description":"What aspects of the paper are innovate or provocative (likely to spur discussion)? Just a couple sentences, please.","position":8,"visibility":"au"},"t04":{"name":"Weaknesses","description":"What are the paper’s weaknesses? Just a couple sentences, please.\\n\\nPlease remember that this is a workshop -- it is okay for the work to be incomplete.","position":9,"visibility":"au"},"t02":{"name":"Comments for author","position":10,"visibility":"au"},"t03":{"name":"Comments for PC","position":11,"visibility":"pc"}}'),
            '[{"name":"Overall merit","visibility":"au","options":["Reject","Weak paper, though I will not fight strongly against it","OK paper, but I will not champion it","Good paper, I will champion it"],"option_letter":"A","id":"s03","scheme":"svr","order":1},{"name":"Reviewer Confidence","visibility":"au","options":["I am not an expert; my evaluation is that of an informed outsider","I am knowledgeable in this area, but not an expert","I am an expert in this area"],"option_letter":"X","id":"s01","scheme":"svr","order":2},{"name":"How likely is the paper to spur discussion?","visibility":"au","options":["Will not spur discussion","May spur discussion","Will definitely spur discussion"],"option_letter":"A","id":"s02","scheme":"svr","order":3},{"name":"Does the paper contain surprising results or a new research direction?","visibility":"au","options":["no","Yes"],"option_letter":"A","id":"s05","scheme":"svr","order":4},{"name":"Accept as Paper or Poster?","description":"If you think the paper should be accepted, indicate whether you think it should be as a full paper with presentation, or as a poster to be displayed in the workshop.","visibility":"au","options":["Paper with presentation","Poster","Not Applicable"],"id":"s06","scheme":"svr","order":5},{"name":"Nominate for Outstanding New Direction Award?","visibility":"pc","options":["Yes","No"],"id":"s04","scheme":"svr","order":6},{"name":"Paper summary","description":"Please summarize the paper briefly in your own words.","visibility":"au","id":"t05","order":7},{"name":"Strengths","description":"What aspects of the paper are innovate or provocative (likely to spur discussion)? Just a couple sentences, please.","visibility":"au","id":"t01","order":8},{"name":"Weaknesses","description":"What are the paper’s weaknesses? Just a couple sentences, please.\\n\\nPlease remember that this is a workshop -- it is okay for the work to be incomplete.","visibility":"au","id":"t04","order":9},{"name":"Comments for author","visibility":"au","id":"t02","order":10},{"name":"Comments for PC","visibility":"pc","id":"t03","order":11}]');

        // Unambiguous renumberings
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Hi", "Hello"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hello", "Fart", "Hi"]), [1 => 2]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi"], ["Hi", "Hello"]), [0 => 1, 1 => 0]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Hello"]), [0 => 1, 1 => 0, 2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Hi", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Barf"]), [2 => -1]);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Money", "Barf"]), []);
        xassert_eqq($sv->unambiguous_renumbering(["Hello", "Hi", "Fart"], ["Fart", "Hello", "Hi"]), [0 => 1, 1 => 2, 2 => 0]);
    }
}
