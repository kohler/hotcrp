<?php
// t_search.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Search_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_canonical_query() {
        xassert_eqq(PaperSearch::canonical_query("(a b) OR (c d)", "", "", "", $this->conf),
                    "(a b) OR (c d)");
        xassert_eqq(PaperSearch::canonical_query("", "a b (c d)", "", "", $this->conf),
                    "a OR b OR (c d)");
        xassert_eqq(PaperSearch::canonical_query("e ", "a b (c d)", "", "", $this->conf),
                    "e AND (a OR b OR (c d))");
        xassert_eqq(PaperSearch::canonical_query("", "a b", "c x m", "", $this->conf),
                    "(a OR b) AND NOT (c OR x OR m)");
        xassert_eqq(PaperSearch::canonical_query("", "a b", "(c OR m) (x y)", "", $this->conf),
                    "(a OR b) AND NOT ((c OR m) OR (x y))");
        xassert_eqq(PaperSearch::canonical_query("foo HIGHLIGHT:pink bar", "", "", "", $this->conf),
                    "foo HIGHLIGHT:pink bar");
        xassert_eqq(PaperSearch::canonical_query("foo HIGHLIGHT:pink bar", "", "", "tag", $this->conf),
                    "#foo HIGHLIGHT:pink #bar");
        xassert_eqq(PaperSearch::canonical_query("foo", "", "", "tag", $this->conf, "s"),
                    "#foo in:submitted");
        xassert_eqq(PaperSearch::canonical_query("foo OR abstract:bar", "", "", "tag", $this->conf, "s"),
                    "(#foo OR abstract:bar) in:submitted");
        xassert_eqq(PaperSearch::canonical_query("-has:submission", "", "", "", $this->conf),
                    "-has:submission");
        xassert_eqq(PaperSearch::canonical_query("NOT (foo OR bar)", "", "", "", $this->conf),
                    "NOT (foo OR bar)");
        xassert_eqq(PaperSearch::canonical_query("ti:foo OR bar ti:(foo OR bar)", "", "", "tag", $this->conf),
                    "ti:foo OR (#bar ti:(foo OR bar))");
        xassert_eqq(PaperSearch::canonical_query("ti:foo OR bar ti:(foo bar)", "", "", "tag", $this->conf),
                    "ti:foo OR (#bar ti:(foo bar))");
        xassert_eqq(PaperSearch::canonical_query("ti:foo OR bar ti:(ab:foo)", "", "", "tag", $this->conf),
                    "ti:foo OR (#bar ti:(ab:foo))");
    }

    function test_sort_etag() {
        $u_shenker = $this->conf->checked_user_by_email("shenker@parc.xerox.com");
        $pl = new PaperList("empty", new PaperSearch($u_shenker, "editsort:#f"));
        xassert_eqq($pl->sort_etag(), "f");
        $pl = new PaperList("empty", new PaperSearch($u_shenker, "editsort:#~f"));
        xassert_eqq($pl->sort_etag(), $u_shenker->contactId . "~f");
        $pl = new PaperList("empty", new PaperSearch($u_shenker, "sort:#me~f edit:tagval:~f"));
        xassert_eqq($pl->sort_etag(), $u_shenker->contactId . "~f");
        $pl = new PaperList("empty", new PaperSearch($u_shenker, "sort:[#me~f reverse] edit:tagval:~f"));
        xassert_eqq($pl->sort_etag(), "");
    }

    function test_multihighlight() {
        $srch = new PaperSearch($this->conf->root_user(), "1-10 HIGHLIGHT:pink 1-2 HIGHLIGHT:yellow 1-5 HIGHLIGHT:green 1-8");
        $h = $srch->highlights_by_paper_id();
        assert($h !== null);
        xassert_eqq($h[1], ["pink", "yellow", "green"]);
        xassert_eqq($h[2], ["pink", "yellow", "green"]);
        xassert_eqq($h[3], ["yellow", "green"]);
        xassert_eqq($h[4], ["yellow", "green"]);
        xassert_eqq($h[5], ["yellow", "green"]);
        xassert_eqq($h[6], ["green"]);
        xassert_eqq($h[7], ["green"]);
        xassert_eqq($h[8], ["green"]);
        xassert_eqq($h[9] ?? [], []);
        xassert_eqq($h[10] ?? [], []);
        xassert(!array_key_exists(11, $h));
    }

    function test_xor() {
        assert_search_papers($this->conf->root_user(), "1-10 XOR 4-5", "1 2 3 6 7 8 9 10");
    }

    function test_review_term_to_round_mask() {
        $rl = $this->conf->round_list();
        xassert_eqq($rl[0], "");
        xassert_eqq($this->conf->round_number("unnamed"), 0);
        xassert_eqq($rl[1], "R1");
        xassert_eqq($this->conf->round_number("R1"), 1);
        xassert_eqq($rl[2], "R2");
        xassert_eqq($this->conf->round_number("R2"), 2);
        xassert_eqq($rl[3], "R3");

        $u = $this->conf->root_user();
        $st = (new PaperSearch($u, "hello"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [0, true]);

        $st = (new PaperSearch($u, ""))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [0, false]);

        $st = (new PaperSearch($u, "round:unnamed"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [1, false]);

        $st = (new PaperSearch($u, "round:unnamed OR ANY"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [0, false]);

        $st = (new PaperSearch($u, "round:unnamed OR round:R1"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [3, false]);

        $st = (new PaperSearch($u, "re:unnamed OR re:R1"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [3, false]);

        $st = (new PaperSearch($u, "re:unnamed OR re:R1:ext"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [3, true]);

        $st = (new PaperSearch($u, "re:unnamed OR (re:R1:ext AND re:R2)"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [1, true]);

        $st = (new PaperSearch($u, "(re:unnamed) OR (re:R1 OR re:R2)"))->main_term();
        xassert_eqq(Review_SearchTerm::term_round_mask($st), [7, false]);
    }

    function test_term_phase() {
        $u = $this->conf->root_user();
        $st = (new PaperSearch($u, "phase:final"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), PaperInfo::PHASE_FINAL);
        $st = (new PaperSearch($u, "phase:review"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), PaperInfo::PHASE_REVIEW);
        $st = (new PaperSearch($u, "NOT phase:final"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), null);
        $st = (new PaperSearch($u, "all"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), null);
        $st = (new PaperSearch($u, "phase:final 1-10 OR phase:final 12-30"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), PaperInfo::PHASE_FINAL);
        $st = (new PaperSearch($u, "phase:final AND 1-10"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), PaperInfo::PHASE_FINAL);
        $st = (new PaperSearch($u, "phase:final 1-10 OR 12-30"))->main_term();
        xassert_eqq(Phase_SearchTerm::term_phase($st), null);
    }

    function test_all() {
        $u = $this->conf->root_user();
        $base_ids = (new PaperSearch($u, ""))->paper_ids();
        $ids = (new PaperSearch($u, "all"))->paper_ids();
        xassert_eqq($ids, $base_ids);
        $ids = (new PaperSearch($u, "show:title all"))->paper_ids();
        xassert_eqq($ids, $base_ids);
        $ids = (new PaperSearch($u, "show:title ALL"))->paper_ids();
        xassert_eqq($ids, $base_ids);
        $ids = (new PaperSearch($u, "\"all\""))->paper_ids();
        xassert_neqq($ids, $base_ids);
    }

    function test_search_overflow() {
        $s = join(" AND ", array_fill(0, 1024, "a"));
        $splitter = new SearchSplitter($s);
        xassert_neqq($splitter->parse_expression("SPACE", 1024), null);

        $s = join(" AND ", array_fill(0, 1026, "a"));
        $splitter = new SearchSplitter($s);
        xassert_eqq($splitter->parse_expression("SPACE", 1024), null);

        $s = "ti:x";
        for ($i = 0; $i < 500; ++$i) {
            $s = "ti:({$s})";
        }
        xassert_eqq(PaperSearch::canonical_query($s, "", "", "", $this->conf), $s);

        $s = "ti:x";
        for ($i = 0; $i < 1025; ++$i) {
            $s = "ti:({$s})";
        }
        xassert_neqq(PaperSearch::canonical_query($s, "", "", "", $this->conf), $s);
    }

    function test_search_splitter_parens() {
        $s = "((a) XOR #whatever)";
        $splitter = new SearchSplitter($s);
        $a = $splitter->parse_expression();
        xassert_eqq(json_encode($a->unparse_json()), '{"op":"(","child":[{"op":"xor","child":[{"op":"(","child":["a"]},"#whatever"]}]}');

        $s = "(() XOR #whatever)";
        $splitter = new SearchSplitter($s);
        $a = $splitter->parse_expression();
        xassert_eqq(json_encode($a->unparse_json()), '{"op":"(","child":[{"op":"xor","child":[{"op":"(","child":[""]},"#whatever"]}]}');
    }

    function test_equal_quote() {
        $u = $this->conf->root_user();
        assert_search_papers($u, "ti:\"scalable timers\"", 1);
        assert_search_papers($u, "ti=\"scalable timers\"", 1);
    }
}
