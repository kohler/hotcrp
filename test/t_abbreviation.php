<?php
// t_abbreviation.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Abbreviation_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_camel_word() {
        xassert(AbbreviationMatcher::is_camel_word("9b"));
        xassert(!AbbreviationMatcher::is_camel_word("99"));
        xassert(AbbreviationMatcher::is_camel_word("OveMer"));
        xassert(!AbbreviationMatcher::is_camel_word("Ovemer"));
        xassert(!AbbreviationMatcher::is_camel_word("ovemer"));
        xassert(!AbbreviationMatcher::is_camel_word("ove mer"));
    }

    function test_simple() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("élan", 1, 1);
        $am->add_phrase("eclat", 2);
        $am->add_phrase("Should the PC Suck?", 3);
        $am->add_phrase("Should P. C. Rock?", 4);
        xassert_eqq($am->find_all("elan"), [1]);
        xassert_eqq($am->find_all("el"), [1]);
        xassert_eqq($am->find_all("él"), [1]);
        xassert_eqq($am->find_all("ÉL"), [1]);
        xassert_eqq($am->find_all("e"), [1, 2]);
        xassert_eqq($am->find_all("ecla"), [2]);
        xassert_eqq($am->find_all("should-the-pc-suck"), [3]);
        xassert_eqq($am->find_all("should-the pc-suck"), [3]);
        xassert_eqq($am->find_all("ShoPCSuc"), [3]);
        xassert_eqq($am->find_all("ShoPCRoc"), [4]);

        $am->add_phrase("élan", 5, 2);
        xassert_eqq($am->find_all("elan"), [1, 5]);
        xassert_eqq($am->find_all("elan", 1), [1]);
        xassert_eqq($am->find_all("elan", 2), [5]);
        xassert_eqq($am->find_all("elan", 3), [1, 5]);

        $am->add_phrase("élange", 6, 2);
        xassert_eqq($am->find_all("ela"), [1, 5, 6]);
        xassert_eqq($am->find_all("elan"), [1, 5]);
        xassert_eqq($am->find_all("elange"), [6]);
        xassert_eqq($am->find_all("elan*"), [1, 5, 6]);
        xassert_eqq($am->find_all("e*e"), [6]);

        $am->add_phrase("99 Problems", 7);
        xassert_eqq($am->find_all("99p"), [7]);
        xassert_eqq($am->find_all("9p"), []);

        $am->add_phrase("?", 8);
        xassert_eqq($am->find_all("ela"), [1, 5, 6]);
        xassert_eqq($am->find_all("elan"), [1, 5]);
        xassert_eqq($am->find_all("elange"), [6]);
        xassert_eqq($am->find_all("elan*"), [1, 5, 6]);
        xassert_eqq($am->find_all("e*e"), [6]);
        xassert_eqq($am->find_all("99p"), [7]);
        xassert_eqq($am->find_all("?"), [8]);
    }

    function test_suffixes() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("Overall merit", 0);
        $am->add_phrase("Overall merit 2", 1);
        $am->add_phrase("Overall merit 3", 2);
        $am->add_phrase("Overall merit 4", 3);
        xassert_eqq($am->find_all("OveMer"), [0]);
        xassert_eqq($am->find_all("merit overall"), []);
        xassert_eqq($am->find_all("OveMer2"), [1]);
        xassert_eqq($am->find_all("overall merit*"), [0, 1, 2, 3]);
        xassert_eqq($am->find_all("OveMer*"), [0, 1, 2, 3]);

        $am->add_phrase("PC Person", 4);
        $am->add_phrase("PC Person 2", 5);
        $am->add_phrase("P. C. Person 3", 6);
        $am->add_phrase("P. C. Person 20", 7);
        xassert_eqq($am->find_all("PCPer"), [4]);
        xassert_eqq($am->find_all("PCPer2"), [5]);
        xassert_eqq($am->find_all("PCPer3"), [6]);
        xassert_eqq($am->find_all("PCPer20"), [7]);
        xassert_eqq($am->find_all("Per"), [4, 5, 6, 7]);
        xassert_eqq($am->find_all("20"), [7]);
        xassert_eqq($am->find_all("2"), [1, 5]);

        $am->add_phrase("Number 2", 8);
        $am->add_phrase("Number 2 Bis", 9);
        $am->add_phrase("2 Butts", 10);
        xassert_eqq($am->find_all("2"), [1, 5, 8, 9, 10]);
    }

    function test_ambiguous() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("France Land", 0);
        $am->add_phrase("France Land Flower", 1);
        $am->add_phrase("France Land Ripen", 2);
        $am->add_phrase("Glass Flower", 3);
        $am->add_phrase("Glass Flower Milk", 4);
        $am->add_phrase("Flower Cheese", 5);
        $am->add_phrase("Anne France", 6);
        xassert_eqq($am->find_all("flower"), [1, 3, 4, 5]);
        xassert_eqq($am->find_all("flo"), [1, 3, 4, 5]);
        xassert_eqq($am->find_all("fra"), [0, 1, 2, 6]);
        xassert_eqq($am->find_all("fra*"), [0, 1, 2]);
        xassert_eqq($am->find_all("*fra*"), [0, 1, 2, 6]);

        $am->add_phrase("France", 7);
        xassert_eqq($am->find_all("fra"), [7]);
        xassert_eqq($am->find_all("fra*"), [0, 1, 2, 7]);
        xassert_eqq($am->find_all("*fra*"), [0, 1, 2, 6, 7]);
    }

    function test_old_abbreviation_styles() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("Cover Letter", 0);
        $am->add_phrase("Other Artifact", 1);
        xassert_eqq($am->find_all("other-artifact"), [1]);
        xassert_eqq($am->find_all("cover-letter"), [0]);

        $am = new AbbreviationMatcher;
        $am->add_phrase("Second Round Paper", 0);
        $am->add_phrase("Second Round Response (PDF)", 1);
        xassert_eqq($am->find_all("second-round-paper"), [0]);
        xassert_eqq($am->find_all("second-round-response--pdf"), [1]);

        $am = new AbbreviationMatcher;
        $am->add_phrase("Paper is co-authored with at least one PC member", 0);
        xassert_eqq($am->find_all("paper-is-co-authored-with-at-least-one-pc-member"), [0]);
        xassert_eqq($am->find_all("paper-co-authored-pc"), [0]);
        xassert_eqq($am->find_all("paper-coauthored-pc"), []);
    }

    function test_acm() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("Paper is co-authored with at least one PC member", 0);
        $am->add_phrase("Comments for the PC", 1);
        $am->add_phrase("ACM Computing Classification", 2);
        xassert_eqq($am->find_all("ComPC"), [1]);
        xassert_eqq($am->find_all("ComPC*"), [1]);
        xassert_eqq($am->find_all("*ComPC*"), [1, 2]);
        xassert_eqq($am->find_all("compc"), []);
        xassert_eqq($am->find_all("ACMComp"), [2]);

        $am->add_phrase("One hundred things", 3);
        $am->add_phrase("One hundred things (Final)", 4);
        xassert_eqq($am->find_all("OneHunThi"), [3]);
        xassert_eqq($am->find_all("OneHunThiFin"), [4]);
        xassert_eqq($am->find_all("one-hundr-thi"), [3]);
        xassert_eqq($am->find_all("one-hundred-things"), [3]);
        xassert_eqq($am->find_all("OneFin"), [4]);
    }

    function test_acm_badges() {
        $acm_badge_opts = ["x" => "None", "a" => "ACM badges: available", "af" => "ACM badges: available, functional", "afr" => "ACM badges: available, functional, replicated", "ar" => "ACM badges: available, reusable", "arr" => "ACM badges: available, reusable, replicated", "f" => "ACM badges: functional", "fr" => "ACM badges: functional, replicated", "r" => "ACM badges: reusable", "rr" => "ACM badges: reusable, replicated"];
        $am = new AbbreviationMatcher;
        foreach ($acm_badge_opts as $d => $dname) {
            $am->add_phrase($dname, $d);
        }
        xassert_eqq($am->find_all("ACM badges: available, functional, replicated"), ["afr"]);
        xassert_eqq($am->find_all("ACM badges: functional, replicated"), ["fr"]);
        xassert_eqq($am->find_all("available"), ["a", "af", "afr", "ar", "arr"]);
        xassert_eqq($am->find_all("ACM badges: available"), ["a"]);
        xassert_eqq($am->find_all("acm-badges-available"), ["a"]);
        xassert_eqq($am->find_all("ACMBadAva"), ["a"]);
        xassert_eqq($am->find_all("ava"), ["a", "af", "afr", "ar", "arr"]);
    }

    function test_topics() {
        $am = new AbbreviationMatcher;
        $topic_ex = ["Applications - Computer Vision",
                     "Applications - NLP",
                     "Applications - Other Systems",
                     "Applications - Search Engines",
                     "Empirical Studies - Qualitative",
                     "Empirical Studies - Quantitative",
                     "Human-Computer Interaction and Information Visualization",
                     "Law, Policy, and Humanistic/Critical Analysis",
                     "Measurement and Algorithm Audits",
                     "Statistics, Machine Learning, Data Mining",
                     "Systems (Programming Languages, Databases)",
                     "Theory and Privacy"];
        foreach ($topic_ex as $i => $topic) {
            $am->add_phrase($topic, $i);
        }
        foreach ($topic_ex as $i => $topic) {
            xassert_eqq($am->find_all($topic), [$i]);
        }
    }

    function test_explicit_keywords() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("ACM Computing Classification", 0);
        $am->add_keyword("ACMCCS", 0);
        $e = $am->add_phrase("ACM Keywords", 1);
        $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        $e = $am->add_phrase("ACM References", 2);
        $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        $e = $am->add_phrase("ACM Supplemental Material", 3);
        $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        xassert_eqq($am->find_all("acmccs"), [0]);
        xassert_eqq($am->find_all("acm"), [0, 1, 2, 3]);

        $e = $am->add_phrase("ACMCamelCase", 4);
        xassert_eqq($am->find_all("ACMCamCas"), [4]);
        xassert_eqq($am->find_entry_keyword($e, AbbreviationMatcher::KW_CAMEL), "ACMCamCas");
    }

    function test_duplicates() {
        $am = new AbbreviationMatcher;
        $e1 = $am->add_phrase("Comments", 1);
        $e2 = $am->add_phrase("Comments", 2);
        $e3 = $am->add_phrase("Comments", 3);
        $e4 = $am->add_phrase("Comments", 4);
        $am->ensure_entry_keyword($e1, AbbreviationMatcher::KW_CAMEL);
        $am->ensure_entry_keyword($e2, AbbreviationMatcher::KW_CAMEL);
        $am->ensure_entry_keyword($e3, AbbreviationMatcher::KW_CAMEL);
        $am->ensure_entry_keyword($e4, AbbreviationMatcher::KW_CAMEL);
        xassert_eqq($am->find_all("Com"), [1, 2, 3, 4]);
        xassert_eqq($am->find_all("Comments.1"), [1]);
        xassert_eqq($am->find_all("Comments.2"), [2]);
        xassert_eqq($am->find_all("Comments.3"), [3]);
        xassert_eqq($am->find_all("Comments.4"), [4]);
    }

    function test_opt_ids() {
        $am = new AbbreviationMatcher;
        $am->add_keyword("opt0", 0);
        $am->add_keyword("opt1", 1);
        $am->add_keyword("opt2", 2);
        $am->add_keyword("opt-1", -1);
        $am->add_keyword("opt-2", -2);
        xassert_eqq($am->find_all("opt0"), [0]);
        xassert_eqq($am->find_all("opt1"), [1]);
        xassert_eqq($am->find_all("opt2"), [2]);
        xassert_eqq($am->find_all("opt-1"), [-1]);
        xassert_eqq($am->find_all("opt-2"), [-2]);

        $am = new AbbreviationMatcher;
        $am->add_keyword("opt0", 0);
        $am->add_keyword("opt1", 1);
        $am->add_keyword("opt2", 2);
        $am->add_keyword("opt-1", -1);
        $am->add_keyword("opt-2", -2);
        $am->add_phrase("whatever, man", 3);
        xassert_eqq($am->find_all("opt0"), [0]);
        xassert_eqq($am->find_all("opt1"), [1]);
        xassert_eqq($am->find_all("opt2"), [2]);
        xassert_eqq($am->find_all("opt-1"), [-1]);
        xassert_eqq($am->find_all("opt-2"), [-2]);
    }

    function test_initial_underscore() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("confused", 0);
        $am->add_phrase("_confused", 1);
        xassert_eqq($am->find_all("confused"), [0]);
        xassert_eqq($am->find_all("_confused"), [1]);
    }

    function test_match_initial_word() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("Yes - I confirm that I will speak.", 0);
        $am->add_phrase("No - I'm sorry, but I can't present my proposal.", 1);
        $am->add_keyword("none", 3);
        xassert_eqq($am->find_all("No"), [1]);
    }

    function test_paren_spaces() {
        $am = new AbbreviationMatcher;
        $am->add_phrase("Shit", 0);
        $am->add_phrase("Butt(s)", 110);
        $am->add_phrase("Wonder(ment)[2](maybe)", 110);
        $am->add_phrase("Wander (ment) [2](maybe)", 110);
        xassert_eqq($am->find_all("Butt(s)"), [110]);
        xassert_eqq($am->find_all("Butts"), [110]);
    }

    function test_expected_keywords() {
        $am = new AbbreviationMatcher;
        $names = ["Public Talk Title (required)",
                  "Short description (required)",
                  "Keyword-Hash Tags",
                  "Speaker Name(s) for Public Posting (required)",
                  "Bio for each presenter (required)",
                  "Speaker(s)' Slack Handle(s)",
                  "Speaker(s)' Twitter handles",
                  "Speaker(s)'s Headshot (required)",
                  "Long Presentation Video",
                  "Slides",
                  "Proposal Type",
                  "Proposal Length",
                  "Long Description for Program Committee",
                  "Session Outline",
                  "Audience Take-Aways",
                  "Other notes for the program committee",
                  "Agenda Items Complete?",
                  "Paper preparation"];
        foreach ($names as $i => $k) {
            $am->add_phrase($k, $i);
        }
        foreach ($names as $i => $k) {
            $e = new AbbreviationEntry($k, $i);
            $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        }
        xassert_eqq($am->find_all("PubTal"), [0]);
        xassert_eqq($am->find_all("ShoDes"), [1]);
        xassert_eqq($am->find_all("KeyHasTag"), [2]);
        xassert_eqq($am->find_all("SpeNam"), [3]);
        xassert_eqq($am->find_all("BioPre"), [4]);
        xassert_eqq($am->find_all("SpeSlaHan"), [5]);
        xassert_eqq($am->find_all("SpeTwiHan"), [6]);
        xassert_eqq($am->find_all("SpeHea"), [7]);
        xassert_eqq($am->find_all("LonPreVid"), [8]);
    }

    function test_parenthetical_uniqueifiers() {
        $am = new AbbreviationMatcher;
        $names = ["Presentation Video (1-2 minutes)",
                  "Presentation Video (15-20 minutes)",
                  "Presentation Slides"];
        foreach ($names as $i => $k) {
            $am->add_phrase($k, $i);
        }
        foreach ($names as $i => $k) {
            $e = new AbbreviationEntry($k, $i);
            $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL);
        }
        xassert_eqq($am->find_all("PreVid1"), [0]);
        xassert_eqq($am->find_all("PreVid15"), [1]);
        xassert_eqq($am->find_all("PreSli"), [2]);
        xassert_eqq($am->find_all("PreVid"), [0, 1]);
    }

    function test_find_all() {
        $am = new AbbreviationMatcher;
        $names = ["Applications of cryptography",
          "Applications of cryptography: Analysis of deployed cryptography and cryptographic protocols",
          "Applications of cryptography: Cryptographic implementation analysis",
          "Applications of cryptography: New cryptographic protocols with real-world applications",
          "Data-driven security and measurement studies",
          "Data-driven security and measurement studies: Measurements of fraud, malware, spam",
          "Data-driven security and measurement studies: Measurements of human behavior and security",
          "Hardware security",
          "Hardware security: Embedded systems security",
          "Hardware security: Methods for detection of malicious or counterfeit hardware",
          "Hardware security: Secure computer architectures",
          "Hardware security: Side channels"];
        foreach ($names as $i => $k) {
            $am->add_phrase($k, $i, 1);
        }
        foreach ([[0, 1, 2, 3], [4, 5, 6], [7, 8, 9, 10, 11]] as $g) {
            foreach ($g as $i) {
                $am->add_phrase($names[$g[0]], $i, 2);
            }
        }
        xassert_eqq($am->find_all("Applications of cryptography"), [0, 1, 2, 3]);
        xassert_eqq($am->find1("Applications of cryptography"), null);
        xassert_eqq($am->find1("Applications of cryptography", 1), 0);
    }

    function test_unknown_decision() {
        $dm = $this->conf->decision_matcher();
        xassert_eqq($dm->find_all("unknown"), [0]);
        xassert_eqq($dm->find_all("unk"), [0]);
        xassert_eqq($dm->find_all("und"), [0]);
        xassert_eqq($dm->find_all("undecided"), [0]);
        xassert_eqq($dm->find_all("?"), [0]);
    }
}
