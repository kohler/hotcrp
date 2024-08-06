<?php
// t_reviews.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Reviews_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;
    /** @var Contact
     * @readonly */
    public $u_lixia;
    /** @var Contact
     * @readonly */
    public $u_mjh;
    /** @var Contact
     * @readonly */
    public $u_floyd;
    /** @var string */
    private $review1A;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $this->u_mjh = $conf->checked_user_by_email("mjh@isi.edu");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
    }

    function save_round_settings($map) {
        $settings = [];
        foreach ($this->conf->round_list() as $rname) {
            $settings[] = isset($map[$rname]) ? $map[$rname] : null;
        }
        $this->conf->save_refresh_setting("round_settings", 1, json_encode_db($settings));
    }

    function test_initial_state() {
        // 1-18 have 3 assignments, rest have 0
        xassert_search($this->u_chair, "re:3", "1-18");
        xassert_search($this->u_chair, "-re:3", "19-30");
        xassert_search($this->u_chair, "ire:3", "1-18");
        xassert_search($this->u_chair, "-ire:3", "19-30");
        xassert_search($this->u_chair, "pre:3", "");
        xassert_search($this->u_chair, "-pre:3", "1-30");
        xassert_search($this->u_chair, "cre:3", "");
        xassert_search($this->u_chair, "-cre:3", "1-30");
        xassert_search($this->u_chair, "re<4", "1-30");
        xassert_search($this->u_chair, "-re<4", "");
        xassert_search($this->u_chair, "re≤3", "1-30");
        xassert_search($this->u_chair, "-re≤3", "");
        xassert_search($this->u_chair, "re<=3", "1-30");
        xassert_search($this->u_chair, "-re<=3", "");
        xassert_search($this->u_chair, "re!=3", "19-30");
        xassert_search($this->u_chair, "-re!=3", "1-18");
        xassert_search($this->u_chair, "re≠3", "19-30");
        xassert_search($this->u_chair, "-re≠3", "1-18");
        xassert_search($this->u_chair, "-re>4", "1-30");
        xassert_search($this->u_chair, "re>4", "");
        xassert_search($this->u_chair, "-re≥3", "19-30");
        xassert_search($this->u_chair, "re≥3", "1-18");
        xassert_search($this->u_chair, "-re>=3", "19-30");
        xassert_search($this->u_chair, "re>=3", "1-18");

        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_chair, "-re:mgbaker", "2-12 14-16 18-30");
        xassert_search($this->u_chair, "ire:mgbaker", "1 13 17");
        xassert_search($this->u_chair, "-ire:mgbaker", "2-12 14-16 18-30");
        xassert_search($this->u_chair, "pre:mgbaker", "");
        xassert_search($this->u_chair, "-pre:mgbaker", "1-30");
        xassert_search($this->u_chair, "cre:mgbaker", "");
        xassert_search($this->u_chair, "-cre:mgbaker", "1-30");

        $this->conf->save_refresh_setting("rev_open", 1);
    }

    function test_add_incomplete_review() {
        save_review(1, $this->u_mgbaker, ["s01" => 5, "ready" => false], null, ["quiet" => true]);

        xassert_search($this->u_chair, "re:3", "1-18");
        xassert_search($this->u_chair, "-re:3", "19-30");
        xassert_search($this->u_chair, "ire:3", "1-18");
        xassert_search($this->u_chair, "-ire:3", "19-30");
        xassert_search($this->u_chair, "pre:3", "");
        xassert_search($this->u_chair, "-pre:3", "1-30");
        xassert_search($this->u_chair, "cre:3", "");
        xassert_search($this->u_chair, "-cre:3", "1-30");
        xassert_search($this->u_chair, "pre:any", "1");
        xassert_search($this->u_chair, "-pre:any", "2-30");
        xassert_search($this->u_chair, "cre:any", "");
        xassert_search($this->u_chair, "-cre:any", "1-30");

        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_chair, "-re:mgbaker", "2-12 14-16 18-30");
        xassert_search($this->u_chair, "ire:mgbaker", "1 13 17");
        xassert_search($this->u_chair, "-ire:mgbaker", "2-12 14-16 18-30");
        xassert_search($this->u_chair, "pre:mgbaker", "1");
        xassert_search($this->u_chair, "-pre:mgbaker", "2-30");
        xassert_search($this->u_chair, "cre:mgbaker", "");
        xassert_search($this->u_chair, "-cre:mgbaker", "1-30");

        xassert_search($this->u_chair, "ovemer:5", "");
    }

    function test_complete_incomplete_review() {
        save_review(1, $this->u_mgbaker, ["s01" => 5, "s02" => 1, "ready" => true]);

        xassert_search($this->u_chair, "re:3", "1-18");
        xassert_search($this->u_chair, "-re:3", "19-30");
        xassert_search($this->u_chair, "ire:3", "2-18");
        xassert_search($this->u_chair, "-ire:3", "1 19-30");
        xassert_search($this->u_chair, "pre:3", "");
        xassert_search($this->u_chair, "-pre:3", "1-30");
        xassert_search($this->u_chair, "cre:3", "");
        xassert_search($this->u_chair, "-cre:3", "1-30");
        xassert_search($this->u_chair, "pre:any", "");
        xassert_search($this->u_chair, "-pre:any", "1-30");
        xassert_search($this->u_chair, "cre:any", "1");
        xassert_search($this->u_chair, "-cre:any", "2-30");

        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_chair, "-re:mgbaker", "2-12 14-16 18-30");
        xassert_search($this->u_chair, "ire:mgbaker", "13 17");
        xassert_search($this->u_chair, "-ire:mgbaker", "1-12 14-16 18-30");
        xassert_search($this->u_chair, "pre:mgbaker", "");
        xassert_search($this->u_chair, "-pre:mgbaker", "1-30");
        xassert_search($this->u_chair, "cre:mgbaker", "1");
        xassert_search($this->u_chair, "-cre:mgbaker", "2-30");

        xassert_search($this->u_chair, "ovemer:5", "1");
    }

    private function print_review_history(ReviewInfo $rrow) {
        $result = $rrow->conf->qe("select * from PaperReviewHistory where paperId=? and reviewId=? order by reviewTime asc", $rrow->paperId, $rrow->reviewId);
        while (($rhrow = ReviewHistoryInfo::fetch($result))) {
            error_log(json_encode($rhrow));
        }
        $result->close();
    }

    function test_offline_review_update() {
        $paper1 = $this->conf->checked_paper_by_id(1, $this->u_chair);
        fresh_review($paper1, $this->u_mgbaker);
        $this->review1A = file_get_contents(SiteLoader::find("test/review1A.txt"));

        // correct update
        $tf = (new ReviewValues($this->conf))->set_text($this->review1A, "review1A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, null));

        xassert_search($this->u_chair, "ovemer:4", "1");
        $rrow = fresh_review($paper1, $this->u_mgbaker);
        xassert_eqq($rrow->fidval("t03"), "  This is a test of leading whitespace\n\n  It should be preserved\nAnd defended\n");

        // different-conference form fails
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/Testconf I/', 'Testconf IIII', $this->review1A), "review1A-1.txt");
        xassert(!$tf->parse_text());
        xassert($tf->has_error_at("confid"));

        // invalid value fails
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/^4/m', 'Mumps', $this->review1A), "review1A-2.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, null));
        xassert_eqq(join(" ", $tf->unchanged), "#1A");
        xassert($tf->has_problem_at("s01"));

        // invalid “No entry” fails
        //$this->print_review_history($rrow);
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/^4/m', 'No entry', $this->review1A), "review1A-3.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, null));
        xassert_eqq(join(" ", $tf->unchanged ?? []), "#1A");
        xassert($tf->has_problem_at("s01"));
        xassert_str_contains($tf->feedback_text_at("s01"), "Entry required");
        //error_log(var_export($tf->message_list(), true));
        //error_log(json_encode($tf->json_report()));
    }

    function test_offline_review_different_reviewer() {
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/Reviewer: .*/m', 'Reviewer: butt@butt.com', $this->review1A), "review1A-4.txt");
        xassert($tf->parse_text());
        xassert(!$tf->check_and_save($this->u_mgbaker, null));
        xassert($tf->has_problem_at("reviewerEmail"));

        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baaaker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5", $this->review1A)), "review1A-5.txt");
        xassert($tf->parse_text());
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert(!$tf->check_and_save($this->u_mgbaker, $paper1, fresh_review($paper1, $this->u_mgbaker)));
        xassert($tf->has_problem_at("reviewerEmail"));
        xassert_search($this->u_chair, "ovemer:4", "1");
        xassert_search($this->u_chair, "ovemer:5", "");

        // it IS ok to save a form that's meant for a different EMAIL but same name
        // Also add a description of the field
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/Reviewer: .*/m', 'Reviewer: Mary Baker <mgbaker193r8219@butt.com>', preg_replace('/^4/m', "5. Strong accept", $this->review1A)), "review1A-5.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, $paper1, fresh_review($paper1, $this->u_mgbaker)));
        xassert(!$tf->has_problem_at("reviewerEmail"));
        xassert_search($this->u_chair, "ovemer:4", "");
        xassert_search($this->u_chair, "ovemer:5", "1");
        xassert_search($this->u_chair, "ovemer:>4", "1");
        //error_log(var_export($tf->message_list(), true));
    }

    function test_change_review_choices() {
        $rfield = $this->conf->checked_review_field("s01");
        xassert($rfield->required);

        // add “no entry” to overall merit choices
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Overall merit",
            "rf/1/id" => "s01",
            "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\nNo entry\n"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);
        $rfield = $this->conf->checked_review_field("s01");
        xassert(!$rfield->required);

        // now it's OK to save “no entry”
        $tf = (new ReviewValues($this->conf))->set_text(preg_replace('/^4/m', 'No entry', $this->review1A), "review1A-6.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, null));
        xassert_eqq(join(" ", $tf->updated ?? []), "#1A");
        xassert(!$tf->has_problem_at("s01"));

        xassert_search($this->u_chair, "has:ovemer", "");

        // Restore review
        $tf = (new ReviewValues($this->conf))->set_text($this->review1A, "review1A-7.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($this->u_mgbaker, null));
        xassert_eqq(join(" ", $tf->updated), "#1A");
        xassert(!$tf->has_problem_at("s01"));

        xassert_search($this->u_chair, "ovemer:4", "1");

        // remove “4” choice from overall merit
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Overall merit",
            "rf/1/id" => "s01",
            "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\nNo entry\n"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        // overall-merit 4 has been removed, revexp has not
        xassert_search_ignore_warnings($this->u_chair, "ovemer:4", "");
        xassert_search($this->u_chair, "revexp:2", "1");
        xassert_search($this->u_chair, "has:revexp", "1");

        // restore “4” and “5” choices
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Overall merit",
            "rf/1/id" => "s01",
            "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\n"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);
    }

    function test_remove_review_field() {
        xassert_search($this->u_chair, "has:revexp AND 1", "1");

        // Stop displaying reviewer expertise
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Reviewer expertise",
            "rf/1/id" => "s02",
            "rf/1/order" => 0
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        // Add reviewer expertise back
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Reviewer expertise",
            "rf/1/id" => "s02",
            "rf/1/values_text" => "1. No familiarity\n2. Some familiarity\n3. Knowledgeable\n4. Expert",
            "rf/1/required" => "1",
            "rf/1/order" => 1.5
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        // It has been removed from the review
        xassert_search($this->u_chair, "has:revexp", "");
    }

    function test_review_text_fields() {
        xassert_search($this->u_chair, "has:papsum", "");
        xassert_search($this->u_chair, "has:comaut", "");

        save_review(1, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
            "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
            "ready" => true
        ]);
        $rrow = fresh_review(1, $this->u_mgbaker);
        xassert_eqq((string) $rrow->fidval("s01"), "2");
        xassert_eqq((string) $rrow->fidval("s02"), "1");
        xassert_eqq((string) $rrow->fidval("t01"), "This is the summary\n");
        xassert_eqq((string) $rrow->fidval("t02"), "Comments for äuthor\n");
        xassert_eqq((string) $rrow->fidval("t03"), "Comments for PC\n");

        xassert_search($this->u_chair, "has:papsum", "1");
        xassert_search($this->u_chair, "has:comaut", "1");
        xassert_search($this->u_chair, "has:compc", "1");
        xassert_search($this->u_chair, "papsum:this", "1");
        xassert_search($this->u_chair, "comaut:author", "1");
        xassert_search($this->u_chair, "comaut:äuthor", "1");
        xassert_search($this->u_chair, "papsum:author", "");
        xassert_search($this->u_chair, "comaut:pc", "");
        xassert_search($this->u_chair, "compc:author", "");

        $f = $this->conf->checked_review_field("t01");
        xassert_eqq($f->parse("Hi"), "Hi\n");
        xassert_eqq($f->parse_json("Hi"), "Hi\n");
        xassert_eqq($f->parse("Hi\n\n\n"), "Hi\n");
        xassert_eqq($f->parse("\n\n\n"), null);
        xassert_eqq($f->parse("\xA1\xC2ll\xF8!"), "¡Âllø!\n"); // ISO 8859-1 -> UTF-8
    }

    function test_many_fields() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Score 3", "rf/1/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/1/order" => 2.03, "rf/1/id" => "s03",
            "rf/2/name" => "Score 4", "rf/2/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/2/order" => 2.04, "rf/2/id" => "s04",
            "rf/3/name" => "Score 5", "rf/3/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/3/order" => 2.05, "rf/3/id" => "s05",
            "rf/4/name" => "Score 6", "rf/4/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/4/order" => 2.06, "rf/4/id" => "s06",
            "rf/5/name" => "Score 7", "rf/5/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/5/order" => 2.07, "rf/5/id" => "s07",
            "rf/6/name" => "Score 8", "rf/6/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/6/order" => 2.08, "rf/6/id" => "s08",
            "rf/7/name" => "Score 9", "rf/7/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/7/order" => 2.09, "rf/7/id" => "s09",
            "rf/8/name" => "Score 10", "rf/8/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/8/order" => 2.10, "rf/8/id" => "s10",
            "rf/9/name" => "Score 11", "rf/9/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/9/order" => 2.11, "rf/9/id" => "s11",
            "rf/10/name" => "Score 12", "rf/10/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/10/order" => 2.12, "rf/10/id" => "s12",
            "rf/11/name" => "Score 13", "rf/11/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/11/order" => 2.13, "rf/11/id" => "s13",
            "rf/12/name" => "Score 14", "rf/12/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/12/order" => 2.14, "rf/12/id" => "s14",
            "rf/13/name" => "Score 15", "rf/13/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/13/order" => 2.15, "rf/13/id" => "s15",
            "rf/14/name" => "Score 16", "rf/14/values_text" => "1. Yes\n2. No\n3. Maybe\nNo entry\n", "rf/14/order" => 2.16, "rf/14/id" => "s16",
            "rf/15/name" => "Text 4", "rf/15/order" => 5.04, "rf/15/id" => "t04",
            "rf/16/name" => "Text 5", "rf/16/order" => 5.05, "rf/16/id" => "t05",
            "rf/17/name" => "Text 6", "rf/17/order" => 5.06, "rf/17/id" => "t06",
            "rf/18/name" => "Text 7", "rf/18/order" => 5.07, "rf/18/id" => "t07",
            "rf/19/name" => "Text 8", "rf/19/order" => 5.08, "rf/19/id" => "t08",
            "rf/20/name" => "Text 9", "rf/20/order" => 5.09, "rf/20/id" => "t09",
            "rf/21/name" => "Text 10", "rf/21/order" => 5.10, "rf/21/id" => "t10",
            "rf/22/name" => "Text 11", "rf/22/order" => 5.11, "rf/22/id" => "t11"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        save_review(1, $this->u_mgbaker, [
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

        xassert_search($this->u_chair, "has:sco3", "1");
        xassert_search($this->u_chair, "has:sco4", "1");
        xassert_search($this->u_chair, "has:sco5", "1");
        xassert_search($this->u_chair, "has:sco6", "");
        xassert_search($this->u_chair, "has:sco7", "1");
        xassert_search($this->u_chair, "has:sco8", "1");
        xassert_search($this->u_chair, "has:sco9", "1");
        xassert_search($this->u_chair, "has:sco10", "");
        xassert_search($this->u_chair, "has:sco11", "1");
        xassert_search($this->u_chair, "has:sco12", "1");
        xassert_search($this->u_chair, "has:sco13", "1");
        xassert_search($this->u_chair, "has:sco14", "");
        xassert_search($this->u_chair, "has:sco15", "1");
        xassert_search($this->u_chair, "has:sco16", "1");
        xassert_search($this->u_chair, "has:tex4", "1");
        xassert_search($this->u_chair, "has:tex5", "");
        xassert_search($this->u_chair, "has:tex6", "1");
        xassert_search($this->u_chair, "has:tex7", "1");
        xassert_search($this->u_chair, "has:tex8", "1");
        xassert_search($this->u_chair, "has:tex9", "1");
        xassert_search($this->u_chair, "has:tex10", "1");
        xassert_search($this->u_chair, "has:tex11", "");

        $rrow = fresh_review(1, $this->u_mgbaker);
        xassert_eqq((string) $rrow->fidval("s16"), "3");

        // Remove some fields and truncate their options
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Score 15", "rf/1/order" => 0, "rf/1/id" => "s15",
            "rf/2/name" => "Score 16", "rf/2/values_text" => "1. Yes\n2. No\nNo entry\n", "rf/2/id" => "s16",
            "rf/3/name" => "Text 10", "rf/3/order" => 0, "rf/3/id" => "t10"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Score 15", "rf/1/values_text" => "1. Yes\n2. No\nNo entry\n", "rf/1/order" => 100, "rf/1/id" => "s15",
            "rf/2/name" => "Text 10", "rf/2/order" => 101, "rf/2/id" => "t10"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        $rrow = fresh_review(1, $this->u_mgbaker);
        xassert($rrow->fidval("s15") === null || (string) $rrow->fidval("s15") === "0");
        xassert($rrow->fidval("s16") === null || (string) $rrow->fidval("s16") === "0");
        xassert($rrow->fidval("t10") === null || (string) $rrow->fidval("t10") === "");

        xassert_search($this->u_chair, "has:sco3", "1");
        xassert_search($this->u_chair, "has:sco4", "1");
        xassert_search($this->u_chair, "has:sco5", "1");
        xassert_search($this->u_chair, "has:sco6", "");
        xassert_search($this->u_chair, "has:sco7", "1");
        xassert_search($this->u_chair, "has:sco8", "1");
        xassert_search($this->u_chair, "has:sco9", "1");
        xassert_search($this->u_chair, "has:sco10", "");
        xassert_search($this->u_chair, "has:sco11", "1");
        xassert_search($this->u_chair, "has:sco12", "1");
        xassert_search($this->u_chair, "has:sco13", "1");
        xassert_search($this->u_chair, "has:sco14", "");
        xassert_search($this->u_chair, "has:sco15", "");
        xassert_search($this->u_chair, "has:sco16", "");
        xassert_search($this->u_chair, "has:tex4", "1");
        xassert_search($this->u_chair, "has:tex5", "");
        xassert_search($this->u_chair, "has:tex6", "1");
        xassert_search($this->u_chair, "has:tex7", "1");
        xassert_search($this->u_chair, "has:tex8", "1");
        xassert_search($this->u_chair, "has:tex9", "1");
        xassert_search($this->u_chair, "has:tex10", "");
        xassert_search($this->u_chair, "has:tex11", "");

        xassert_search($this->u_chair, "sco3:1", "1");
        xassert_search($this->u_chair, "sco4:2", "1");
        xassert_search($this->u_chair, "sco5:3", "1");
        xassert_search($this->u_chair, "sco6:0", "1");
        xassert_search($this->u_chair, "sco7:1", "1");
        xassert_search($this->u_chair, "sco8:2", "1");
        xassert_search($this->u_chair, "sco9:3", "1");
        xassert_search($this->u_chair, "sco10:0", "1");
        xassert_search($this->u_chair, "sco11:1", "1");
        xassert_search($this->u_chair, "sco12:2", "1");
        xassert_search($this->u_chair, "sco13:3", "1");
        xassert_search($this->u_chair, "sco14:0", "1");
        xassert_search($this->u_chair, "sco15:0", "1");
        xassert_search($this->u_chair, "sco16:0", "1");
        xassert_search($this->u_chair, "tex4:bobcat", "1");
        xassert_search($this->u_chair, "tex6:fisher*", "1");
        xassert_search($this->u_chair, "tex7:tiger", "1");
        xassert_search($this->u_chair, "tex8:leopard", "1");
        xassert_search($this->u_chair, "tex9:tremolo", "1");

        // check handling of sfields and tfields: don't lose unchanged fields
        save_review(1, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
            "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
            "sco11" => 2, "sco16" => 1, "tex11" => "butt",
            "ready" => true
        ]);

        xassert_search($this->u_chair, "sco3:1", "1");
        xassert_search($this->u_chair, "sco4:2", "1");
        xassert_search($this->u_chair, "sco5:3", "1");
        xassert_search($this->u_chair, "sco6:0", "1");
        xassert_search($this->u_chair, "sco7:1", "1");
        xassert_search($this->u_chair, "sco8:2", "1");
        xassert_search($this->u_chair, "sco9:3", "1");
        xassert_search($this->u_chair, "sco10:0", "1");
        xassert_search($this->u_chair, "sco11:2", "1");
        xassert_search($this->u_chair, "sco12:2", "1");
        xassert_search($this->u_chair, "sco13:3", "1");
        xassert_search($this->u_chair, "sco14:0", "1");
        xassert_search($this->u_chair, "sco15:0", "1");
        xassert_search($this->u_chair, "sco16:1", "1");
        xassert_search($this->u_chair, "comaut:author", "1");
        xassert_search($this->u_chair, "comaut:äuthor", "1");
        xassert_search($this->u_chair, "comaut:áuthor", "");
        xassert_search($this->u_chair, "tex4:bobcat", "1");
        xassert_search($this->u_chair, "tex6:fisher*", "1");
        xassert_search($this->u_chair, "tex7:tiger", "1");
        xassert_search($this->u_chair, "tex8:leopard", "1");
        xassert_search($this->u_chair, "tex9:tremolo", "1");
        xassert_search($this->u_chair, "tex11:butt", "1");

        // check handling of sfields and tfields: no changes at all
        save_review(1, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
            "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
            "sco13" => 3, "sco14" => 0, "sco15" => 0, "sco16" => 1,
            "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
            "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
            "ready" => true
        ]);

        xassert_search($this->u_chair, "sco3:1", "1");
        xassert_search($this->u_chair, "sco4:2", "1");
        xassert_search($this->u_chair, "sco5:3", "1");
        xassert_search($this->u_chair, "sco6:0", "1");
        xassert_search($this->u_chair, "sco7:1", "1");
        xassert_search($this->u_chair, "sco8:2", "1");
        xassert_search($this->u_chair, "sco9:3", "1");
        xassert_search($this->u_chair, "sco10:0", "1");
        xassert_search($this->u_chair, "sco11:2", "1");
        xassert_search($this->u_chair, "sco12:2", "1");
        xassert_search($this->u_chair, "sco13:3", "1");
        xassert_search($this->u_chair, "sco14:0", "1");
        xassert_search($this->u_chair, "sco15:0", "1");
        xassert_search($this->u_chair, "sco16:1", "1");
        xassert_search($this->u_chair, "tex4:bobcat", "1");
        xassert_search($this->u_chair, "tex6:fisher*", "1");
        xassert_search($this->u_chair, "tex7:tiger", "1");
        xassert_search($this->u_chair, "tex8:leopard", "1");
        xassert_search($this->u_chair, "tex9:tremolo", "1");
        xassert_search($this->u_chair, "tex11:butt", "1");

        // check handling of sfields and tfields: clear extension fields
        save_review(1, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "",
            "comaut" => "", "compc" => "", "sco12" => "",
            "sco13" => "", "sco14" => "", "sco15" => "", "sco16" => "",
            "tex4" => "", "tex5" => "", "tex6" => "", "tex7" => "",
            "tex8" => "", "tex9" => "", "tex10" => "", "tex11" => "",
            "ready" => true
        ]);

        $rrow = $this->conf->fetch_first_object("select * from PaperReview where paperId=1 and contactId=?", $this->u_mgbaker->contactId);
        xassert(!!$rrow);
        xassert($rrow->sfields === null);
        xassert($rrow->tfields === null);

        save_review(1, $this->u_mgbaker, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "This is the summary",
            "comaut" => "Comments for äuthor", "compc" => "Comments for PC",
            "sco3" => 1, "sco4" => 2, "sco5" => 3, "sco6" => "", "sco7" => 1,
            "sco8" => 2, "sco9" => 3, "sco10" => "", "sco11" => 2,
            "sco12" => 2, "sco13" => 3, "sco14" => "", "sco15" => "", "sco16" => 1,
            "tex4" => "bobcat", "tex5" => "", "tex6" => "fishercat", "tex7" => "tiger",
            "tex8" => "leopard", "tex9" => "tremolo", "tex10" => "", "tex11" => "butt",
            "ready" => true
        ]);

        xassert_search($this->u_chair, "sco3:1", "1");
        xassert_search($this->u_chair, "sco4:2", "1");
        xassert_search($this->u_chair, "sco5:3", "1");
        xassert_search($this->u_chair, "sco6:0", "1");
        xassert_search($this->u_chair, "sco7:1", "1");
        xassert_search($this->u_chair, "sco8:2", "1");
        xassert_search($this->u_chair, "sco9:3", "1");
        xassert_search($this->u_chair, "sco10:0", "1");
        xassert_search($this->u_chair, "sco11:2", "1");
        xassert_search($this->u_chair, "sco12:2", "1");
        xassert_search($this->u_chair, "sco13:3", "1");
        xassert_search($this->u_chair, "sco14:0", "1");
        xassert_search($this->u_chair, "sco15:0", "1");
        xassert_search($this->u_chair, "sco16:1", "1");
        xassert_search($this->u_chair, "tex4:bobcat", "1");
        xassert_search($this->u_chair, "tex6:fisher*", "1");
        xassert_search($this->u_chair, "tex7:tiger", "1");
        xassert_search($this->u_chair, "tex8:leopard", "1");
        xassert_search($this->u_chair, "tex9:tremolo", "1");
        xassert_search($this->u_chair, "tex11:butt", "1");

        save_review(1, $this->u_mgbaker, [
            "ovemer" => 3, "sco15" => 2,
            "tex8" => "leopardino", "ready" => true
        ]);

        xassert_search($this->u_chair, "sco3:1", "1");
        xassert_search($this->u_chair, "sco4:2", "1");
        xassert_search($this->u_chair, "sco5:3", "1");
        xassert_search($this->u_chair, "sco6:0", "1");
        xassert_search($this->u_chair, "sco7:1", "1");
        xassert_search($this->u_chair, "sco8:2", "1");
        xassert_search($this->u_chair, "sco9:3", "1");
        xassert_search($this->u_chair, "sco10:0", "1");
        xassert_search($this->u_chair, "sco11:2", "1");
        xassert_search($this->u_chair, "sco12:2", "1");
        xassert_search($this->u_chair, "sco13:3", "1");
        xassert_search($this->u_chair, "sco14:0", "1");
        xassert_search($this->u_chair, "sco15:2", "1");
        xassert_search($this->u_chair, "sco16:1", "1");
        xassert_search($this->u_chair, "tex4:bobcat", "1");
        xassert_search($this->u_chair, "tex6:fisher*", "1");
        xassert_search($this->u_chair, "tex7:tiger", "1");
        xassert_search($this->u_chair, "tex8:leopard", "");
        xassert_search($this->u_chair, "tex8:leopardino", "1");
        xassert_search($this->u_chair, "tex9:tremolo", "1");
        xassert_search($this->u_chair, "tex11:butt", "1");

        // simplify review form
        $sx = ["has_rf" => 1];
        for ($i = 3, $ctr = 1; $i <= 16; ++$i) {
            $sx["rf/{$ctr}/id"] = sprintf("s%02d", $i);
            $sx["rf/{$ctr}/delete"] = true;
            ++$ctr;
            $sx["rf/{$ctr}/id"] = sprintf("t%02d", $i);
            $sx["rf/{$ctr}/delete"] = true;
            ++$ctr;
        }
        $sv = SettingValues::make_request($this->u_chair, $sx);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);
    }

    function test_body() {
        $conf = $this->conf;
        $user_chair = $this->u_chair;
        $user_mgbaker = $this->u_mgbaker;
        $user_diot = $conf->checked_user_by_email("christophe.diot@sophia.inria.fr"); // pc, red
        $user_pdruschel = $conf->checked_user_by_email("pdruschel@cs.rice.edu"); // pc

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
        $conf->save_refresh_setting("sub_blind", null);
        xassert_eqq($conf->settings["sub_blind"], Conf::BLIND_ALWAYS);

        $rrow17m = fresh_review($paper17, $user_mgbaker);
        xassert(!$rrow17m->reviewModified);

        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($user_mgbaker, $paper17));

        $rrow17m = fresh_review($paper17, $user_mgbaker);
        xassert_eq($rrow17m->fidval("s01"), 2);
        xassert_eq($rrow17m->fidval("s02"), 1);
        xassert_eqq($rrow17m->fidval("t01"), "No summary\n");
        xassert_eqq($rrow17m->fidval("t02"), "No comments\n");
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
        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "No summary", "comaut" => "No comments"]));
        xassert($tf->check_and_save($user_diot, $paper18));

        $rrow18d = fresh_review($paper18, $user_diot);
        $rrow18d->set_fval_prop($conf->find_review_field("ovemer"), 3, true);
        $rrow18d->set_fval_prop($conf->find_review_field("papsum"), "There definitely is a summary in this position.", true);
        $rd = $rrow18d->prop_diff();
        xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch(0)),
                    '{"s01":2,"t01":"No summary\\n"}');
        xassert_eqq(ReviewDiffInfo::unparse_patch($rd->make_patch(1)),
                    '{"s01":3,"t01":"There definitely is a summary in this position."}');

        $rrow18d2 = fresh_review($paper18, $user_diot);
        xassert_eq($rrow18d2->fidval("s01"), 2);
        xassert_eq($rrow18d2->fidval("s02"), 1);
        xassert_eqq($rrow18d2->fidval("t01"), "No summary\n");
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
        xassert_eq($rrow18d2->fidval("s01"), 3);
        xassert_eq($rrow18d2->fidval("s02"), 1);
        xassert_eqq($rrow18d2->fidval("t01"), "There definitely is a summary in this position.");
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(0));
        xassert_eq($rrow18d2->fidval("s01"), 2);
        xassert_eq($rrow18d2->fidval("s02"), 1);
        xassert_eqq($rrow18d2->fidval("t01"), "No summary\n");

        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["papsum" =>
            "Four score and seven years ago our fathers brought forth on this continent, a new nation, conceived in Liberty, and dedicated to the proposition that all men are created equal.\n\
\n\
Now we are engaged in a great civil war, testing whether that nation, or any nation so conceived and so dedicated, can long endure. We are met on a great battle-field of that war. We have come to dedicate a portion of that field, as a final resting place for those who here gave their lives that that nation might live. It is altogether fitting and proper that we should do this.\n\
\n\
But, in a larger sense, we can not dedicate -- we can not consecrate -- we can not hallow -- this ground. The brave men, living and dead, who struggled here, have consecrated it, far above our poor power to add or detract. The world will little note, nor long remember what we say here, but it can never forget what they did here. It is for us the living, rather, to be dedicated here to the unfinished work which they who fought here have thus far so nobly advanced. It is rather for us to be here dedicated to the great task remaining before us -- that from these honored dead we take increased devotion to that cause for which they gave the last full measure of devotion -- that we here highly resolve that these dead shall not have died in vain -- that this nation, under God, shall have a new birth of freedom -- and that government of the people, by the people, for the people, shall not perish from the earth.\n"]));
        xassert($tf->check_and_save($user_diot, $paper18));

        $rrow18d = fresh_review($paper18, $user_diot);
        $gettysburg = $rrow18d->fidval("t01");
        $rrow18d2 = clone $rrow18d;

        $gettysburg2 = str_replace("by the people", "near the people", $gettysburg);
        $rrow18d->set_fval_prop($conf->find_review_field("papsum"), $gettysburg2, true);
        $rd = $rrow18d->prop_diff();

        xassert_eqq($rrow18d2->fidval("t01"), $gettysburg);
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(1));
        xassert_eqq($rrow18d2->fidval("t01"), $gettysburg2);
        ReviewDiffInfo::apply_patch($rrow18d2, $rd->make_patch(0));
        xassert_eqq($rrow18d2->fidval("t01"), $gettysburg);

        // offline review parsing for UTF-8 review questions
        $sv = SettingValues::make_request($user_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Questions for authors’ response",
            "rf/1/description" => "Specific questions that could affect your accept/reject decision. Remember that the authors have limited space and must respond to all reviewers.",
            "rf/1/visibility" => "au",
            "rf/1/order" => 5,
            "rf/1/id" => "t04"
        ]);
        xassert($sv->execute());

        $review18A = file_get_contents(SiteLoader::find("test/review18A.txt"));
        $tf = (new ReviewValues($conf))->set_text($review18A, "review18A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($user_diot, null));
        xassert_eqq($tf->summary_status(), MessageSet::SUCCESS);
        xassert_eqq($tf->full_feedback_text(), "Updated review #18A\n");

        $tf = (new ReviewValues($conf))->set_text($review18A, "review18A.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($user_diot, null));
        xassert_eqq($tf->summary_status(), MessageSet::WARNING);
        xassert_eqq($tf->full_feedback_text(), "No changes to review #18A\n");

        $rrow = fresh_review($paper18, $user_diot);
        xassert_eqq($rrow->fidval("t04"), "This is the stuff I want to add for the authors’ response.\n");

        $review18A2 = str_replace("This is the stuff", "That was the stuff",
            str_replace("authors’ response\n", "authors' response\n", $review18A));
        $tf = (new ReviewValues($conf))->set_text($review18A2, "review18A2.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($user_diot, null));

        $rrow = fresh_review($paper18, $user_diot);
        xassert_eqq($rrow->fidval("t04"), "That was the stuff I want to add for the authors’ response.\n");

        $sv = SettingValues::make_request($user_chair, [
            "has_rf" => 1,
            "rf/1/name" => "Questions for authors’ response (hidden from authors)",
            "rf/1/name_force" => 1,
            "rf/1/id" => "t04"
        ]);
        xassert($sv->execute());

        $review18A3 = str_replace("That was the stuff", "Whence the stuff",
            str_replace("authors' response\n", "authors' response (hidden from authors)\n", $review18A2));
        $tf = (new ReviewValues($conf))->set_text($review18A3, "review18A3.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($user_diot, null));

        $rrow = fresh_review($paper18, $user_diot);
        xassert_eqq($rrow->fidval("t04"), "Whence the stuff I want to add for the authors’ response.\n");

        $review18A4 = file_get_contents(SiteLoader::find("test/review18A-4.txt"));
        $tf = (new ReviewValues($conf))->set_text($review18A4, "review18A-4.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save($user_diot, null));

        $rrow = fresh_review($paper18, $user_diot);
        xassert(str_ends_with($rrow->fidval("t01"), "\n==+== Want to make sure this works\n"));
        xassert_eqq($rrow->fidval("t04"), "Whitherto the stuff I want to add for the authors’ response.\n");
    }

    function test_review_history() {
        $conf = $this->conf;
        $paper17 = $conf->checked_paper_by_id(17);
        $rrow17a = fresh_review($paper17, $this->u_mgbaker);
        xassert_eqq($rrow17a->fidval("s01"), 2);
        xassert_eqq($rrow17a->fidval("s02"), 1);
        xassert_eqq($rrow17a->fidval("t01"), "No summary\n");
        xassert_eqq($rrow17a->fidval("t02"), "No comments\n");

        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 3, "revexp" => 2, "papsum" => "This institution, perhaps one should say enterprise out of respect for which one says one need not change one's mind about a thing one has believed in, requiring public promises of one's intention to fulfill a private obligation;\n", "comaut" => "Now there are comments\n"]));
        xassert($tf->check_and_save($this->u_mgbaker, $paper17));
        $rrow17b = fresh_review($paper17, $this->u_mgbaker);
        xassert_eqq($rrow17b->fidval("s01"), 3);
        xassert_eqq($rrow17b->fidval("s02"), 2);
        xassert_eqq($rrow17b->fidval("t01"), "This institution, perhaps one should say enterprise out of respect for which one says one need not change one's mind about a thing one has believed in, requiring public promises of one's intention to fulfill a private obligation;\n");
        xassert_eqq($rrow17b->fidval("t02"), "Now there are comments\n");
        xassert_eqq($rrow17a->fidval("s01"), 2); // no change to old version
        xassert($rrow17b->reviewModified > $rrow17a->reviewModified);
        xassert($rrow17b->reviewTime > $rrow17a->reviewTime);

        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 4, "revexp" => 3, "papsum" => "This institution, perhaps one should say Starship Enterprise out of respect for which one says one need not change one's mind about a thing one has believed in, requiring public promises of one's intention to fulfill a private obligation;\n", "comaut" => "Now there are comments\n"]));
        xassert($tf->check_and_save($this->u_mgbaker, $paper17));
        $rrow17c = fresh_review($paper17, $this->u_mgbaker);
        xassert_eqq($rrow17c->fidval("s01"), 4);
        xassert_eqq($rrow17c->fidval("s02"), 3);
        xassert_eqq($rrow17c->fidval("t01"), "This institution, perhaps one should say Starship Enterprise out of respect for which one says one need not change one's mind about a thing one has believed in, requiring public promises of one's intention to fulfill a private obligation;\n");
        xassert_eqq($rrow17c->fidval("t02"), "Now there are comments\n");
        xassert($rrow17c->reviewModified > $rrow17b->reviewModified);
        xassert($rrow17c->reviewTime > $rrow17b->reviewTime);

        //$x = $conf->fetch_first_object("select * from PaperReviewHistory where paperId=17 and reviewId=? and reviewNextTime=?", $rrow17c->reviewId, $rrow17c->reviewTime);
        //error_log(json_encode($x));

        $rrow17b2 = $rrow17c->version_at($rrow17c->reviewModified - 1);
        xassert(!!$rrow17b2);
        xassert_eqq($rrow17b->fidval("s01"), $rrow17b2->fidval("s01"));
        xassert_eqq($rrow17b->fidval("s02"), $rrow17b2->fidval("s02"));
        xassert_eqq($rrow17b->fidval("t01"), $rrow17b2->fidval("t01"));
        xassert_eqq($rrow17b->fidval("t02"), $rrow17b2->fidval("t02"));

        $rrow17a2 = $rrow17c->version_at($rrow17b->reviewModified - 1);
        xassert(!!$rrow17a2);
        xassert_eqq($rrow17a->fidval("s01"), $rrow17a2->fidval("s01"));
        xassert_eqq($rrow17a->fidval("s02"), $rrow17a2->fidval("s02"));
        xassert_eqq($rrow17a->fidval("t01"), $rrow17a2->fidval("t01"));
        xassert_eqq($rrow17a->fidval("t02"), $rrow17a2->fidval("t02"));

        // restore original scores
        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1]));
        xassert($tf->check_and_save($this->u_mgbaker, $paper17));
    }

    function test_review_visibility() {
        $conf = $this->conf;
        $paper17 = $conf->checked_paper_by_id(17);
        $rrow17m = fresh_review($paper17, $this->u_mgbaker);

        // check some review visibility policies
        $user_external = Contact::make_keyed($conf, ["email" => "external@_.com", "name" => "External Reviewer"])->store();
        assert(!!$user_external);
        $this->u_mgbaker->assign_review(17, $user_external, REVIEW_EXTERNAL,
            ["round_number" => $conf->round_number("R2")]);
        xassert(!$user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        xassert(!$this->u_mjh->can_view_review($paper17, $rrow17m));
        $conf->save_setting("viewrev_ext", -1);
        $conf->save_setting("viewrevid_ext", -1);
        save_review(17, $user_external, [
            "ovemer" => 2, "revexp" => 1, "papsum" => "Hi", "comaut" => "Bye", "ready" => true
        ]);
        MailChecker::check_db("test06-17external");
        xassert(!$user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        $conf->save_setting("viewrev_ext", null);
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17m));
        $conf->save_setting("viewrevid_ext", null);
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));

        // per-round review visibility
        $tf = new ReviewValues($conf);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "Radical", "comaut" => "Nonradical"]));
        xassert($tf->check_and_save($this->u_lixia, $paper17));
        MailChecker::check_db("test06-17lixia");
        $rrow17h = fresh_review($paper17, $this->u_lixia);
        $rrow17x = fresh_review($paper17, $user_external);
        xassert_eqq($rrow17m->reviewRound, $conf->round_number("R2"));
        xassert_eqq($rrow17h->reviewRound, $conf->round_number("R1"));
        xassert_eqq($rrow17x->reviewRound, $conf->round_number("R2"));
        Contact::update_rights();

        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17x));
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert($user_external->can_view_review($paper17, $rrow17h));
        xassert($user_external->can_view_review($paper17, $rrow17x));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17x));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));
        xassert($user_external->can_view_review_identity($paper17, $rrow17h));
        xassert($user_external->can_view_review_identity($paper17, $rrow17x));

        // check the settings page works for round tags
        xassert_eqq($conf->assignment_round(false), 0);
        xassert_eqq($conf->assignment_round(true), 0);
        xassert_eqq($conf->setting_data("rev_roundtag"), null);
        xassert_eqq($conf->setting_data("extrev_roundtag"), null);
        $sv = SettingValues::make_request($this->u_chair, [
            "review_default_round" => "R1"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 1);
        xassert_eqq($conf->assignment_round(true), 1);
        xassert_eqq($conf->setting_data("rev_roundtag"), "R1");
        xassert_eqq($conf->setting_data("extrev_roundtag"), null);
        $sv = SettingValues::make_request($this->u_chair, [
            "review_default_round" => "R3",
            "review_default_external_round" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 3);
        xassert_eqq($conf->assignment_round(true), 0);
        xassert_eqq($conf->setting_data("tag_rounds"), "R1 R2 R3");
        xassert_eqq($conf->setting_data("rev_roundtag"), "R3");
        xassert_eqq($conf->setting_data("extrev_roundtag"), "unnamed");
        $sv = SettingValues::make_request($this->u_chair, [
            "review_default_external_round" => "default"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 3);
        xassert_eqq($conf->assignment_round(true), 3);
        xassert_eqq($conf->setting_data("rev_roundtag"), "R3");
        xassert_eqq($conf->setting_data("extrev_roundtag"), null);
        $sv = SettingValues::make_request($this->u_chair, [
            "review_default_round" => "unnamed"
        ]);
        xassert($sv->execute());
        xassert_eqq($conf->assignment_round(false), 0);
        xassert_eqq($conf->assignment_round(true), 0);
        xassert_eqq($conf->setting_data("rev_roundtag"), null);
        xassert_eqq($conf->setting_data("extrev_roundtag"), null);

        $this->save_round_settings(["R1" => ["viewrev_ext" => -1, "viewrevid_ext" => -1]]);
        Contact::update_rights();

        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17x));
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review($paper17, $rrow17h));
        xassert($user_external->can_view_review($paper17, $rrow17x));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17x));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
        xassert($user_external->can_view_review_identity($paper17, $rrow17x));
        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_lixia, "re:mgbaker", "1 13 17");

        // Extrev cannot view R1; PC cannot view R2
        $this->save_round_settings(["R1" => ["viewrev_ext" => -1, "viewrevid_ext" => -1], "R2" => ["viewrev" => -1]]);
        Contact::update_rights();

        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17h));
        xassert(!$this->u_mgbaker->can_view_review($paper17, $rrow17x));
        xassert(!$this->u_lixia->can_view_review($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17h));
        xassert(!$this->u_lixia->can_view_review($paper17, $rrow17x));
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review($paper17, $rrow17h));
        xassert($user_external->can_view_review($paper17, $rrow17x));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17x));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
        xassert($user_external->can_view_review_identity($paper17, $rrow17x));
        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_lixia, "re:mgbaker", "1 13 17");

        // Extrev cannot view R1; PC cannot view R2 identity
        $this->save_round_settings(["R1" => ["viewrev_ext" => -1, "viewrevid_ext" => -1], "R2" => ["viewrevid" => -1]]);
        Contact::update_rights();

        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review($paper17, $rrow17x));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17h));
        xassert($this->u_lixia->can_view_review($paper17, $rrow17x));
        xassert($user_external->can_view_review($paper17, $rrow17m));
        xassert(!$user_external->can_view_review($paper17, $rrow17h));
        xassert($user_external->can_view_review($paper17, $rrow17x));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17h));
        xassert($this->u_mgbaker->can_view_review_identity($paper17, $rrow17x));
        xassert(!$this->u_lixia->can_view_review_identity($paper17, $rrow17m));
        xassert($this->u_lixia->can_view_review_identity($paper17, $rrow17h));
        xassert(!$this->u_lixia->can_view_review_identity($paper17, $rrow17x));
        xassert($user_external->can_view_review_identity($paper17, $rrow17m));
        xassert(!$user_external->can_view_review_identity($paper17, $rrow17h));
        xassert($user_external->can_view_review_identity($paper17, $rrow17x));
        xassert_search($this->u_chair, "re:mgbaker", "1 13 17");
        xassert_search($this->u_lixia, "re:mgbaker", "1");

        $this->conf->save_refresh_setting("round_settings", null);
        Contact::update_rights();
    }

    function print_scores() {
        $result = $this->conf->qe("select paperId, group_concat(s01), group_concat(s02) from PaperReview where reviewSubmitted>0 group by paperId");
        while (($row = $result->fetch_row())) {
            fwrite(STDOUT, sprintf("%-5s %-12s %s\n", ...$row));
        }
        Dbl::free($result);
    }

    function test_search_ranges() {
        $user_external = $this->conf->checked_user_by_email("external@_.com");
        $user_pdruschel = $this->conf->checked_user_by_email("pdruschel@cs.rice.edu");
        $user_danzig = $this->conf->checked_user_by_email("peter.danzig@usc.edu");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01", "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\n", "rf/1/required" => 0
        ]);
        xassert($sv->execute());
        xassert_eqq($this->conf->checked_review_field("s01")->required, false);

        save_review(17, $user_external, [
            "ovemer" => 1
        ]);
        xassert_search($this->u_chair, "17 ovemer:2<=1", "");
        xassert_search($this->u_chair, "17 ovemer:=1<=1", "17");
        xassert_search($this->u_chair, "17 ovemer=1<=1", "17");

        save_review(17, $user_pdruschel, [
            "ready" => true, "ovemer" => 1, "revexp" => 1
        ]);
        xassert_search($this->u_chair, "17 ovemer:2<=1", "17");
        xassert_search($this->u_chair, "17 ovemer:=2<=1", "17");
        xassert_search($this->u_chair, "17 ovemer:1<=1", "17");
        xassert_search($this->u_chair, "17 ovemer:=1<=1", "");
        xassert_search($this->u_chair, "17 ovemer=1<=1", "");

        save_review(19, $this->u_lixia, ["ready" => true, "ovemer" => 2, "revexp" => 2]);
        save_review(19, $user_danzig, ["ready" => true, "ovemer" => 3, "revexp" => 2]);
        save_review(19, $user_pdruschel, ["ready" => true, "ovemer" => 4, "revexp" => 3]);
        save_review(20, $this->u_lixia, ["ready" => true, "ovemer" => 1, "revexp" => 1]);
        save_review(20, $user_danzig, ["ready" => true, "ovemer" => 4, "revexp" => 1]);
        save_review(20, $user_pdruschel, ["ready" => true, "ovemer" => 5, "revexp" => 1]);
        save_review(21, $this->u_lixia, ["ready" => true, "ovemer" => 0, "revexp" => 3]);
        save_review(21, $user_danzig, ["ready" => true, "ovemer" => 4, "revexp" => 3]);
        save_review(21, $user_pdruschel, ["ready" => true, "ovemer" => 5, "revexp" => 3]);

        // Submitted reviews:
        // pid   ovemer       revexp
        // 1     3            1
        // 17    2,2,1,1      1,1,1,1
        // 18    2            1
        // 19    2,3,4        2,2,3
        // 20    1,4,5        1,1,1
        // 21    none,4,5     3,3,3

        xassert_search($this->u_chair, "ovemer:1", "17 20");
        xassert_search($this->u_chair, "ovemer:2", "17 18 19");
        xassert_search($this->u_chair, "ovemer:3", "1 19");
        xassert_search($this->u_chair, "ovemer:4", "19 20 21");
        xassert_search($this->u_chair, "ovemer:5", "20 21");
        xassert_search($this->u_chair, "ovemer:empty", "21");

        xassert_search($this->u_chair, "ovemer:any ovemer:none:1", "1 18 19 21");
        xassert_search($this->u_chair, "ovemer:any ovemer:=0:1", "1 18 19 21");
        xassert_search($this->u_chair, "ovemer:>1:1", "17");
        xassert_search($this->u_chair, "ovemer:>2:1", "");
        xassert_search($this->u_chair, "ovemer:any ovemer:<2:1", "1 18 19 20 21");
        xassert_search($this->u_chair, "ovemer:any ovemer:≤2:1", "1 17 18 19 20 21");

        xassert_search($this->u_chair, "ovemer:any:1..2", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:1..2", "17 18");
        xassert_search($this->u_chair, "ovemer:1..2", "17 18");
        xassert_search($this->u_chair, "ovemer:span:1..2", "17");
        xassert_search($this->u_chair, "ovemer:any:1…2", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:1-2", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:1—2", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any ovemer:none:2-3", "20 21");

        xassert_search($this->u_chair, "ovemer:any:1-5", "1 17 18 19 20 21");
        xassert_search($this->u_chair, "ovemer:all:1-5", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:1-5", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:span:1-5", "20");

        xassert_search($this->u_chair, "ovemer:any:1..3", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:1-3", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:1..3", "1 17 18");
        xassert_search($this->u_chair, "ovemer:all:1—3", "1 17 18");
        xassert_search($this->u_chair, "ovemer:1—3", "1 17 18");
        xassert_search($this->u_chair, "ovemer:span:1..3", "");
        xassert_search($this->u_chair, "ovemer:any:1–2", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:1-2", "17 18");
        xassert_search($this->u_chair, "ovemer:span:1–2", "17");

        $this->conf->set_opt("allowObsoleteScoreSearch", 1);
        xassert_search($this->u_chair, "ovemer:1..2", "17 18");
        xassert_search($this->u_chair, "ovemer:1..3", "1 17 18");
        xassert_search($this->u_chair, "ovemer:1-3", "");
        $this->conf->set_opt("allowObsoleteScoreSearch", null);
    }

    function test_search_alpha_ranges() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01", "rf/1/values_text" => "E. Reject\nD. Weak reject\nC. Weak accept\nB. Accept\nA. Strong accept\n"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);

        xassert_search($this->u_chair, "ovemer:E", "17 20");
        xassert_search($this->u_chair, "ovemer:D", "17 18 19");
        xassert_search($this->u_chair, "ovemer:C", "1 19");
        xassert_search($this->u_chair, "ovemer:B", "19 20 21");
        xassert_search($this->u_chair, "ovemer:A", "20 21");
        xassert_search($this->u_chair, "ovemer:empty", "21");

        xassert_search($this->u_chair, "ovemer:any ovemer:none:E", "1 18 19 21");
        xassert_search($this->u_chair, "ovemer:any ovemer:=0:E", "1 18 19 21");
        //xassert_search($this->u_chair, "ovemer:>1:1", "17");
        //xassert_search($this->u_chair, "ovemer:>2:1", "");
        xassert_search($this->u_chair, "ovemer:any ovemer:<2:E", "1 18 19 20 21");
        xassert_search($this->u_chair, "ovemer:any ovemer:≤2:E", "1 17 18 19 20 21");

        xassert_search($this->u_chair, "ovemer:any:DE", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:DE", "17 18");
        xassert_search($this->u_chair, "ovemer:span:DE", "17");
        xassert_search($this->u_chair, "ovemer:any:D..E", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:D…E", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:D-E", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:D-E", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any ovemer:none:C-D", "20 21");

        xassert_search($this->u_chair, "ovemer:any:A-E", "1 17 18 19 20 21");
        xassert_search($this->u_chair, "ovemer:all:A-E", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:span:A-E", "20");

        xassert_search($this->u_chair, "ovemer:any:C..E", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:any:C-E", "1 17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:C..E", "1 17 18");
        xassert_search($this->u_chair, "ovemer:C..E", "1 17 18");
        xassert_search($this->u_chair, "ovemer:all:C—E", "1 17 18");
        xassert_search($this->u_chair, "ovemer:C—E", "1 17 18");
        xassert_search($this->u_chair, "ovemer:span:C..E", "");
        xassert_search($this->u_chair, "ovemer:any:D–E", "17 18 19 20");
        xassert_search($this->u_chair, "ovemer:all:D-E", "17 18");
        xassert_search($this->u_chair, "ovemer:span:D–E", "17");

        $this->conf->set_opt("allowObsoleteScoreSearch", 1);
        xassert_search($this->u_chair, "ovemer:D..E", "17 18");
        xassert_search($this->u_chair, "ovemer:C..E", "1 17 18");
        xassert_search($this->u_chair, "ovemer:C-E", "");
        $this->conf->set_opt("allowObsoleteScoreSearch", null);

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01", "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\n"
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->changed_keys(), ["review_form"]);
    }

    function test_new_external_reviewer() {
        // new external reviewer does not get combined email
        $this->conf->save_refresh_setting("viewrev_ext", null);
        $this->conf->save_refresh_setting("viewrevid_ext", -1);
        $this->conf->save_refresh_setting("pcrev_editdelegate", 2);
        Contact::update_rights();
        MailChecker::clear();

        $user_external2 = $this->conf->user_by_email("external2@_.com");
        xassert(!$user_external2);

        $xqreq = new Qrequest("POST", ["email" => "external2@_.com", "name" => "Jo March", "affiliation" => "Concord"]);
        $paper17 = $this->conf->checked_paper_by_id(17);
        $result = RequestReview_API::requestreview($this->u_lixia, $xqreq, $paper17);
        MailChecker::check_db("t_review-external2-request17");
        xassert($result instanceof JsonResult);
        xassert($result->content["ok"]);
        $user_external2 = $this->conf->checked_user_by_email("external2@_.com");
        xassert(!$user_external2->is_placeholder());
        $this->conf->invalidate_user($user_external2);
        $user_external2 = $this->conf->user_by_email("external2@_.com"); // ensure cached user
        assert($user_external2 !== null);
        $paper17->load_reviews(true);

        // check for review accept capability
        $rrow = $paper17->review_by_user($user_external2);
        $tok = ReviewAccept_Capability::make($rrow, false);
        xassert(!!$tok);
        xassert(str_starts_with($tok->salt, "hcra"));

        // check that review accept capability works
        $emptyuser = Contact::make($this->conf);
        assert(!$emptyuser->can_view_paper($paper17));
        $emptyuser->apply_capability_text($tok->salt); // had an infinite loop here
        assert(!!$emptyuser->can_view_paper($paper17));
        xassert_eqq($emptyuser->reviewer_capability_user(17)->contactId, $user_external2->contactId);

        // confirm review
        $xqreq = new Qrequest("POST", ["r" => $rrow->reviewId]);
        $result = RequestReview_API::acceptreview($emptyuser, $xqreq, $paper17);
        xassert($result instanceof JsonResult);
        xassert($result->content["ok"]);
        MailChecker::check_db("t_review-external2-accept17");
        $rrow = $paper17->fresh_review_by_user($user_external2);
        xassert_eqq($rrow->reviewStatus, ReviewInfo::RS_ACKNOWLEDGED);
        xassert_eqq($rrow->reviewSubmitted, null);
        xassert_eqq($rrow->reviewModified, 1);
        xassert_eqq($rrow->timeRequestNotified, Conf::$now);
        xassert_eqq($rrow->view_score(), VIEWSCORE_EMPTY);

        // check clickthrough
        assert($emptyuser->can_clickthrough("review", $paper17));
        $this->conf->set_opt("clickthrough_review", 1);
        $this->conf->fmt()->define_override("clickthrough_review", "fart");
        assert(!$emptyuser->can_clickthrough("review", $paper17));
        assert(!$user_external2->can_clickthrough("review", $paper17));
        xassert_eqq($user_external2->reviewer_capability_user(17), null);
        $user_external2->apply_capability_text($tok->salt);
        xassert_neqq($user_external2->reviewer_capability_user(17), null);
        assert(!$user_external2->can_clickthrough("review", $paper17));

        $user_external2->merge_and_save_data(["clickthrough" => ["de1027d6806d42584748f76733d55a9ca1c41f3a" => true]]); // sha1("fart")
        $this->conf->invalidate_user($user_external2);

        assert($user_external2->can_clickthrough("review", $paper17));
        $user_external2->clear_capabilities();
        assert($user_external2->can_clickthrough("review", $paper17));
        assert($emptyuser->can_clickthrough("review", $paper17));
        $emptyuser->clear_capabilities();
        assert(!$emptyuser->can_clickthrough("review", $paper17));

        // no longer want clickthrough
        $this->conf->set_opt("clickthrough_review", null);

        // save review, check mail
        save_review(17, $user_external2, [
            "ready" => true, "ovemer" => 3, "revexp" => 3
        ]);
        MailChecker::check_db("t_review-external2-approval17");

        save_review(17, $this->u_lixia, ["approvesubmit" => true], fresh_review(17, $user_external2));
        MailChecker::check_db("t_review-external2-submit17");
    }

    function test_review_proposal() {
        xassert_search($this->u_chair, "has:proposal", "");
        xassert_search($this->u_lixia, "has:proposal", "");
        xassert_search($this->u_mgbaker, "has:proposal", "");
        xassert_search($this->u_mjh, "has:proposal", "");
        xassert_search($this->u_chair, "re:proposal", "");
        xassert_search($this->u_lixia, "re:proposal", "");
        xassert_search($this->u_mgbaker, "re:proposal", "");
        xassert_search($this->u_mjh, "re:proposal", "");

        $this->conf->save_refresh_setting("extrev_chairreq", 1);
        Contact::update_rights();

        $xqreq = new Qrequest("POST", ["email" => "external3@_.com", "name" => "Amy March", "affiliation" => "Transcendent"]);
        $paper17 = $this->conf->checked_paper_by_id(17);
        $result = RequestReview_API::requestreview($this->u_lixia, $xqreq, $paper17);
        MailChecker::check_db("test06-external3-request17");

        xassert_search($this->u_chair, "has:proposal", "17");
        xassert_search($this->u_lixia, "has:proposal", "17");
        xassert_search($this->u_mgbaker, "has:proposal", "17");
        xassert_search($this->u_mjh, "has:proposal", "");
        xassert_search($this->u_chair, "re:proposal", "17");
        xassert_search($this->u_lixia, "re:proposal", "17");
        xassert_search($this->u_mgbaker, "re:proposal", "17");
        xassert_search($this->u_mjh, "re:proposal", "");

        xassert_search($this->u_chair, "has:proposal admin:me", "17");
        xassert_search($this->u_lixia, "has:proposal admin:me", "");
        xassert_search($this->u_mgbaker, "has:proposal", "17");

        $xqreq = new Qrequest("POST", ["email" => "external4@_.com", "firstName" => "Beth  ", "lastName" => " March", "affiliation" => "Transcendent"]);
        $paper17 = $this->conf->checked_paper_by_id(17);
        $result = RequestReview_API::requestreview($this->u_lixia, $xqreq, $paper17);
        MailChecker::check_db("test06-external4-request17");
    }

    function test_search_routstanding() {
        xassert_search($this->u_mgbaker, ["t" => "r", "q" => ""], "1 13 17");
        xassert_search($this->u_mgbaker, ["t" => "rout", "q" => ""], "13");
        xassert_search($this->u_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13");
        xassert_search($this->u_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13");

        xassert_assign($this->u_chair, "paper,action,user\n19,review,new-anonymous");
        $this->u_mgbaker->change_review_token($this->conf->fetch_ivalue("select reviewToken from PaperReview where paperId=19 and reviewToken!=0"), true);
        xassert_search($this->u_mgbaker, ["t" => "r", "q" => ""], "1 13 17 19");
        xassert_search($this->u_mgbaker, ["t" => "rout", "q" => ""], "13 19");
        xassert_search($this->u_mgbaker, ["t" => "r", "q" => "internet"], "13");
        xassert_search($this->u_mgbaker, ["t" => "rout", "q" => "internet"], "13");
        xassert_search($this->u_mgbaker, ["t" => "r", "q" => "internet OR datagram"], "13 19");
        xassert_search($this->u_mgbaker, ["t" => "rout", "q" => "internet OR datagram"], "13 19");
        xassert_search($this->u_mgbaker, "(internet OR datagram) 13 19", "13 19");

        // author review visibility
        $paper17 = $this->conf->checked_paper_by_id(17);
        $rrow17m = fresh_review($paper17, $this->u_mgbaker);
        xassert(!$this->u_mjh->can_view_review($paper17, $rrow17m));
        $this->conf->save_refresh_setting("au_seerev", 2);
        xassert($this->u_mjh->can_view_review($paper17, $rrow17m));
    }

    function test_review_symbols() {
        $FNUM = Score_ReviewField::FLAG_NUMERIC;
        $FLET = Score_ReviewField::FLAG_ALPHA;
        $FCHR = Score_ReviewField::FLAG_SINGLE_CHAR;
        $FDEF = Score_ReviewField::FLAG_DEFAULT_SYMBOLS;
        xassert_eqq(Score_ReviewField::analyze_symbols([], false), $FNUM|$FDEF);
        xassert_eqq(Score_ReviewField::analyze_symbols([1, 2, 3], false), $FNUM|$FDEF);
        xassert_eqq(Score_ReviewField::analyze_symbols([1, 2, 3], true), 0);
        xassert_eqq(Score_ReviewField::analyze_symbols(["←", "B"], true), $FCHR);
        xassert_eqq(Score_ReviewField::analyze_symbols(["C", "B", "A"], true), $FLET|$FCHR|$FDEF);
        xassert_eqq(Score_ReviewField::analyze_symbols(["C", "B", "A", "@"], true), $FCHR);
        xassert_eqq(Score_ReviewField::analyze_symbols(["C", "B", "A"], false), $FLET|$FCHR);
    }

    function test_checkboxes_review_field() {
        xassert_eqq(Checkboxes_ReviewField::unpack_value(0), []);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(1), [1]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(2), [2]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(3), [1, 2]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(4), [3]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(5), [1, 3]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(6), [2, 3]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(7), [1, 2, 3]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(8), [4]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(16), [5]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(32), [6]);
        xassert_eqq(Checkboxes_ReviewField::unpack_value(64), [7]);
    }

    function test_clean_name() {
        xassert_eqq(ReviewField::clean_name("Fudge (shown only to administrator)"), "Fudge");
        xassert_eqq(ReviewField::clean_name("Fudge (shown only to chair)"), "Fudge");
        xassert_eqq(ReviewField::clean_name("Fudge (shown only to chairs)"), "Fudge");
        xassert_eqq(ReviewField::clean_name("Fudge (secret)"), "Fudge");
        xassert_eqq(ReviewField::clean_name("Fudge (shown only to Emmanuel Macron)"), "Fudge (shown only to Emmanuel Macron)");
    }

    function test_self_assign() {
        save_review(30, $this->u_lixia, ["ready" => true, "ovemer" => 2, "revexp" => 2]);
        $prow = $this->conf->checked_paper_by_id(30);
        $rrow = $prow->review_by_user($this->u_lixia);
        xassert_eqq($rrow->requestedBy, $this->u_lixia->contactId);
        xassert_eqq($rrow->reviewType, REVIEW_PC);
        xassert_neqq($rrow->rflags & ReviewInfo::RF_SELF_ASSIGNED, 0);
        xassert($prow->contact_info($this->u_lixia)->self_assigned());
    }

    function test_rflags_type() {
        for ($i = 0; $i <= REVIEW_META; ++$i) {
            xassert_eqq(ReviewInfo::rflags_type(1 << $i), $i);
            xassert_eqq(ReviewInfo::rflags_type((1 << $i) | ReviewInfo::RF_LIVE), $i);
            xassert_eqq(ReviewInfo::rflags_type((1 << $i) | ReviewInfo::RF_LIVE | ReviewInfo::RF_BLIND), $i);
        }
        xassert_eqq(ReviewInfo::RF_LIVE, 1);
        xassert_eqq(ReviewInfo::RFM_NONDRAFT, ReviewInfo::RF_DELIVERED | ReviewInfo::RF_APPROVED | ReviewInfo::RF_SUBMITTED);
        xassert_eqq(ReviewInfo::RFM_NONEMPTY, ReviewInfo::RF_ACKNOWLEDGED | ReviewInfo::RF_DRAFTED | ReviewInfo::RF_DELIVERED | ReviewInfo::RF_APPROVED | ReviewInfo::RF_SUBMITTED);
    }

    function test_ensure_full_reviews_preserves_prop_changes() {
        $prow = $this->conf->checked_paper_by_id(30, null, ["reviewSignatures" => true]);
        $rrow = $prow->review_by_user($this->u_lixia);
        $t = ($rrow->reviewSubmitted ? : Conf::$now) + 1;
        $rrow->set_prop("reviewSubmitted", $t);
        xassert($rrow->prop_changed());

        $prow->ensure_full_reviews();
        $rrow2 = $prow->review_by_user($this->u_lixia);
        xassert_neqq($rrow, $rrow2);
        xassert_eqq($rrow->reviewSubmitted, $rrow2->reviewSubmitted);
    }

    /** @param bool $reset_fields
     * @return ReviewInfo */
    static function set_review_status(ReviewInfo $rrow, $status, $reset_fields = false) {
        if ($reset_fields) {
            if ($status >= ReviewInfo::RS_DELIVERED) {
                $f = ", s01=3, s02=1, tfields=null";
            } else if ($status >= ReviewInfo::RS_DRAFTED) {
                $f = ", s01=3, s02=0, tfields=null";
            } else {
                $f = ", s01=0, s02=0, tfields=null";
            }
        } else {
            $f = "";
        }
        $rflags = $rrow->rflags & ~ReviewInfo::RFM_NONEMPTY;
        if ($status >= ReviewInfo::RS_DRAFTED) {
            $rflags |= ReviewInfo::RF_DRAFTED;
        }
        if ($status >= ReviewInfo::RS_ACKNOWLEDGED) {
            $rflags |= ReviewInfo::RF_ACKNOWLEDGED;
        }
        if ($status >= ReviewInfo::RS_APPROVED) {
            $rflags |= ReviewInfo::RF_APPROVED;
        }
        if ($status >= ReviewInfo::RS_DELIVERED) {
            $rflags |= ReviewInfo::RF_DELIVERED;
        }
        if ($status >= ReviewInfo::RS_COMPLETED) {
            $rflags |= ReviewInfo::RF_SUBMITTED;
        }
        $rrow->conf->qe("update PaperReview set reviewSubmitted=?, reviewModified=?, timeApprovalRequested=?, reviewNeedsSubmit=?, rflags=?{$f} where paperId=? and reviewId=?",
            $status >= ReviewInfo::RS_COMPLETED ? Conf::$now : null,
            $status >= ReviewInfo::RS_DRAFTED ? Conf::$now
                : ($status >= ReviewInfo::RS_ACKNOWLEDGED ? 1 : 0),
            $rrow->reviewType === REVIEW_EXTERNAL && $rrow->conf->ext_subreviews > 1
                ? ($status >= ReviewInfo::RS_APPROVED ? -Conf::$now
                        : ($status >= ReviewInfo::RS_DELIVERED ? Conf::$now : 0))
                : 0,
            $status >= ReviewInfo::RS_DELIVERED ? 0 : 1,
            $rflags,
            $rrow->paperId, $rrow->reviewId);
        $rrow = $rrow->prow->fresh_review_by_id($rrow->reviewId);
        assert($rrow->reviewStatus === $status);
        return $rrow;
    }

    function test_external_review_update_matrix() {
        $this->conf->save_refresh_setting("viewrevid_ext", null);
        $this->conf->save_refresh_setting("pcrev_editdelegate", 2);
        xassert_eqq($this->conf->setting("viewrev_ext"), null);
        xassert_eqq($this->conf->setting("viewrevid_ext"), null);
        xassert_eqq($this->conf->setting("pcrev_editdelegate"), 2);
        xassert_eqq($this->conf->setting("extrev_chairreq"), 1);
        xassert_gt($this->conf->ext_subreviews, 1);
        MailChecker::clear();

        // request review on paper 16
        $u_floyd = $this->u_floyd;
        $xqreq = new Qrequest("POST", ["email" => "external4@_.com", "name" => "Rrhea Bisers", "affiliation" => "Charli Fan Club"]);
        $p16 = $this->conf->checked_paper_by_id(16);
        $result = RequestReview_API::requestreview($u_floyd, $xqreq, $p16);
        xassert($result instanceof JsonResult);
        xassert($result->content["ok"]);
        xassert_eqq($result->content["action"], "propose");

        // confirm proposal
        $xqreq = new Qrequest("POST", ["email" => "external4@_.com"]);
        $result = RequestReview_API::requestreview($this->u_chair, $xqreq, $p16);
        xassert($result->content["ok"]);
        xassert_eqq($result->content["action"], "request");

        // check that new review exists
        $u_ext4 = $this->conf->checked_user_by_email("external4@_.com");
        xassert_eqq($u_ext4->firstName, "Rrhea");
        xassert_eqq($u_ext4->lastName, "Bisers");
        $p16->load_reviews(true);
        $r16x = $p16->review_by_user($u_ext4);
        xassert(!!$r16x);
        xassert_eqq($r16x->reviewSubmitted, null);
        xassert_eqq($r16x->reviewModified, 0);
        xassert_eqq($r16x->timeApprovalRequested, 0);
        xassert_eqq($r16x->reviewNeedsSubmit, 1);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_EMPTY);


        // empty save moves to accepted
        $r16x = save_review($p16, $u_ext4, ["update" => 1], $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_ACKNOWLEDGED);
        // XXX should send acceptance email


        // change a field => review becomes drafted
        $revqreq = ["update" => 1, "ovemer" => 3];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);


        // request readiness without completing required fields
        $revqreq = ["ready" => 1, "ovemer" => 3];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);


        // request readiness with all required fields
        $revqreq = ["ready" => 1, "ovemer" => 3, "revexp" => 1];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);


        // request unreadiness
        $revqreq = ["ready" => 0, "ovemer" => 3, "revexp" => 1];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);


        // external reviewer requests approval (ignored)
        $revqreq = ["ready" => 1, "approval" => "approved", "ovemer" => 3, "revexp" => 1];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DELIVERED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED);
        $r16x = save_review($p16, $u_ext4, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);


        // requester approval is not ignored
        $revqreq = ["approval" => "approved"];
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_EMPTY, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_EMPTY);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_ACKNOWLEDGED, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_ACKNOWLEDGED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DRAFTED, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_DRAFTED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_DELIVERED, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_APPROVED, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_APPROVED);

        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED, true);
        $r16x = save_review($p16, $u_floyd, $revqreq, $r16x, ["quiet" => true]);
        xassert_eqq($r16x->reviewStatus, ReviewInfo::RS_COMPLETED);
    }

    function test_invalid_updates() {
        $p16 = $this->conf->checked_paper_by_id(16);
        $u_ext4 = $this->conf->checked_user_by_email("external4@_.com");
        $r16x = $p16->review_by_user($u_ext4);
        $r16x = self::set_review_status($r16x, ReviewInfo::RS_COMPLETED, true);
        $r16x = save_review($p16, $u_ext4, ["ovemer" => 4, "revexp" => 1], $r16x);
        xassert_eqq($r16x->fidval("s01"), 4);
        xassert_eqq($r16x->fidval("s02"), 1);
        $rt = $r16x->reviewTime;

        // test update by wrong user
        save_review($p16, $this->u_mgbaker, ["ovemer" => 3, "revexp" => 1], $r16x, ["quiet" => true]);
        $r16x = $p16->fresh_review_by_user($u_ext4);
        xassert_eqq($r16x->fidval("s01"), 4);
        xassert_eqq($r16x->fidval("s02"), 1);
        xassert_eqq($r16x->reviewTime, $rt);

        // test upload by correct user of incorrect form
        $review1A = file_get_contents(SiteLoader::find("test/review1A.txt"));
        $rv = (new ReviewValues($this->conf))->set_text($review1A, "review1A.txt");
        xassert($rv->parse_text());
        xassert(!$rv->check_and_save($u_ext4, $p16));
        $r16x = $p16->fresh_review_by_user($u_ext4);
        xassert_eqq($r16x->reviewTime, $rt);
        xassert_str_contains($rv->full_feedback_text(), "Submission mismatch");
    }

    function test_rv_self_assignment() {
        $p16 = $this->conf->checked_paper_by_id(16);
        $u_rguerin = $this->conf->checked_user_by_email("rguerin@ibm.com");
        $r16g = $p16->review_by_user($u_rguerin);
        xassert(!$r16g);
        $r16f = $p16->review_by_user($this->u_floyd);
        xassert(!!$r16f);

        // allow self assignment
        xassert_eqq($this->conf->setting("pcrev_any"), 1);
        $r16g = save_review($p16, $u_rguerin, ["ovemer" => 3, "revexp" => 1]);
        xassert(!!$r16g);
        xassert_eqq($r16g->fidval("s01"), 3);
        xassert_eqq($r16g->fidval("s02"), 1);
        $r16f = save_review($p16, $this->u_floyd, ["ovemer" => 1, "revexp" => 3]);
        xassert(!!$r16f);
        xassert_eqq($r16f->fidval("s01"), 1);
        xassert_eqq($r16f->fidval("s02"), 3);

        // delete self-assigned review
        $this->conf->qe("delete from PaperReview where paperId=? and reviewId=?", $r16g->paperId, $r16g->reviewId);
        $p16->invalidate_reviews();
        Contact::update_rights();
        $r16g = $p16->review_by_user($u_rguerin);
        xassert(!$r16g);

        // deny self assignment
        $this->conf->save_refresh_setting("pcrev_any", null);
        $r16g = save_review($p16, $u_rguerin, ["ovemer" => 2, "revexp" => 4], null, ["quiet" => true]);
        xassert(!$r16g);
        $r16g = $p16->fresh_review_by_user($u_rguerin);
        xassert(!$r16g);
        $r16f = save_review($p16, $this->u_floyd, ["ovemer" => 4, "revexp" => 2]);
        xassert(!!$r16f);
        xassert_eqq($r16f->fidval("s01"), 4);
        xassert_eqq($r16f->fidval("s02"), 2);
    }

    function test_empty_review_form() {
        $p16 = $this->conf->checked_paper_by_id(16);
        $r16f = save_review($p16, $this->u_floyd, ["ready" => true]);
        $r16f_ts = $r16f->reviewSubmitted;
        xassert_gt($r16f_ts, 0);
        xassert_eqq($r16f->fidval("s01"), 4);
        xassert_eqq($r16f->fidval("s02"), 2);

        $emptyform = file_get_contents(SiteLoader::find("test/review0.txt"));
        $s16 = str_replace("#0", "#16", $emptyform);
        $rv = (new ReviewValues($this->conf))->set_text($s16, "review16.txt");
        $rv->parse_text();
        $rv->check_and_save($this->u_floyd, $p16);
        xassert_eqq($rv->json_report(), ["blank" => ["#16"]]);

        $r16f2 = $p16->fresh_review_by_user($this->u_floyd);
        xassert_eqq($r16f2->reviewSubmitted, $r16f_ts);
        xassert_eqq($r16f2->fidval("s01"), 4);
        xassert_eqq($r16f2->fidval("s02"), 2);
    }

    function test_rv_unsubmit() {
        $p16 = $this->conf->checked_paper_by_id(16);
        $r16f = $p16->review_by_user($this->u_floyd);
        xassert_gt($r16f->reviewSubmitted, 0);

        // user cannot unsubmit their own review
        $rv = new ReviewValues($this->conf);
        $rv->set_can_unsubmit(true);
        $rv->parse_qreq(new Qrequest("POST", ["ready" => false]));
        $rv->check_and_save($this->u_floyd, $p16, $r16f);

        $r16f = $p16->fresh_review_by_user($this->u_floyd);
        xassert_gt($r16f->reviewSubmitted, 0);

        // admin can unsubmit another review
        $rv = new ReviewValues($this->conf);
        $rv->set_can_unsubmit(true);
        $rv->parse_qreq(new Qrequest("POST", ["ready" => false]));
        $rv->check_and_save($this->u_chair, $p16, $r16f);

        $r16f = $p16->fresh_review_by_user($this->u_floyd);
        xassert_eqq($r16f->reviewSubmitted, null);
    }

    function test_bulk_unsubmit() {
        $p16 = $this->conf->checked_paper_by_id(16);
        $r16f = $p16->review_by_user($this->u_floyd);
        $r16f = save_review($p16, $this->u_floyd, ["ready" => true]);
        xassert_gt($r16f->reviewSubmitted, 0);

        xassert_assign($this->u_chair, "paper,action,user\n16,unsubmitreview,floyd");

        $r16f = $p16->fresh_review_by_user($this->u_floyd);
        xassert_eqq($r16f->reviewSubmitted, null);
    }

    function test_requested_reviewer_placeholder() {
        if (!($cdb = $this->conf->contactdb())) {
            return;
        }

        $this->conf->save_refresh_setting("extrev_chairreq", 2);
        $this->conf->save_refresh_setting("pcrev_editdelegate", 2);
        Contact::update_rights();
        MailChecker::clear();

        $u_ext2p = $this->conf->user_by_email("external2p@_.com");
        xassert(!$u_ext2p);

        $uc_ext2p = $this->conf->cdb_user_by_email("external2p@_.com");
        xassert(!$uc_ext2p);
        $result = Dbl::qe($cdb, "insert into ContactInfo set firstName='Thorsten', lastName='Gorsten', email='external2p@_.com', affiliation='Brandeis University', collaborators='German Strawberries', password='', cflags=2, disabled=2");
        assert(!Dbl::is_error($result));
        Dbl::free($result);

        $uc_ext2p = $this->conf->fresh_cdb_user_by_email("external2p@_.com");
        xassert(!!$uc_ext2p);
        $this->conf->invalidate_user($uc_ext2p, true);

        $xqreq = new Qrequest("POST", ["email" => "external2p@_.com", "name" => "Jo March", "affiliation" => "Concord"]);
        $paper17 = $this->conf->checked_paper_by_id(17);
        $result = RequestReview_API::requestreview($this->u_lixia, $xqreq, $paper17);
        xassert($result instanceof JsonResult);
        xassert($result->content["ok"]);

        $u_ext2p = $this->conf->checked_user_by_email("external2p@_.com");
        xassert(!$u_ext2p->is_placeholder());
    }

    function test_invariants_last() {
        xassert(ConfInvariants::test_all($this->conf));
    }
}
