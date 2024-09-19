<?php
// t_search.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Search_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_root;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_root = $conf->root_user();
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
        $srch = new PaperSearch($this->u_root, "1-10 HIGHLIGHT:pink 1-2 HIGHLIGHT:yellow 1-5 HIGHLIGHT:green 1-8");
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
        xassert(!array_key_exists(11, $h ?? []));
    }

    function test_nested_highlight() {
        $srch = new PaperSearch($this->u_root, "(1-10 AND Scalable HIGHLIGHT:pink) OR (2 4 6 8 10 HIGHLIGHT:blue)");
        $h = $srch->highlights_by_paper_id();
        assert($h !== null);
        xassert_eqq($h[1], ["pink"]);
        xassert_eqq($h[2], ["blue"]);
        xassert_eqq($h[3] ?? [], []);
        xassert_eqq($h[4], ["pink", "blue"]);
        xassert_eqq($h[5] ?? [], []);
        xassert_eqq($h[6], ["blue"]);
        xassert_eqq($h[7] ?? [], []);
        xassert_eqq($h[8], ["blue"]);
        xassert_eqq($h[9] ?? [], []);
        xassert_eqq($h[10], ["blue"]);
        xassert(!array_key_exists(11, $h ?? []));
    }

    function test_xor() {
        xassert_search($this->u_root, "1-10 XOR 4-5", "1 2 3 6 7 8 9 10");
    }

    function test_halfopen_interval() {
        xassert_search($this->u_root, "5-100000 XOR 10-100000", "5 6 7 8 9");
        xassert_search($this->u_root, "5- XOR 10-100000", "5 6 7 8 9");
        xassert_search($this->u_root, "8-,7-,6-,5- XOR 10-100000", "5 6 7 8 9");
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

        $u = $this->u_root;
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
        $u = $this->u_root;
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
        $u = $this->u_root;
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
        $splitter = new SearchParser($s);
        xassert_neqq($splitter->parse_expression(null, "SPACE", 1024), null);

        $s = join(" AND ", array_fill(0, 1026, "a"));
        $splitter = new SearchParser($s);
        xassert_eqq($splitter->parse_expression(null, "SPACE", 1024), null);

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

    /** @suppress PhanTypeArraySuspiciousNullable */
    function test_search_splitter_parens() {
        $s = "((a) XOR #whatever)";
        $splitter = new SearchParser($s);
        $a = $splitter->parse_expression();
        xassert_eqq(json_encode($a->unparse_json()), '{"op":"(","child":[{"op":"xor","child":[{"op":"(","child":["a"]},"#whatever"]}]}');

        $s = "(() XOR #whatever)";
        $splitter = new SearchParser($s);
        $a = $splitter->parse_expression();
        xassert_eqq(json_encode($a->unparse_json()), '{"op":"(","child":[{"op":"xor","child":[{"op":"(","child":[""]},"#whatever"]}]}');

        $s = "((OveMer:>3 OveMer:<2) or (OveMer:>4 OveMer:<3)) #r2";
        $splitter = new SearchParser($s);
        $a = $splitter->parse_expression();
        xassert_eqq($a->op->type, "space");
        xassert_eqq($a->child[0]->op->type, "(");
        xassert_eqq($a->child[0]->child[0]->op->type, "or");
        xassert_eqq(json_encode($a->child[0]->child[0]->child[0]->unparse_json()), '{"op":"(","child":[{"op":"space","child":["OveMer:>3","OveMer:<2"]}]}');
        xassert_eqq(json_encode($a->child[0]->child[0]->child[1]->unparse_json()), '{"op":"(","child":[{"op":"space","child":["OveMer:>4","OveMer:<3"]}]}');
        xassert_eqq(json_encode($a->child[1]->unparse_json()), '"#r2"');
    }

    function test_equal_quote() {
        $u = $this->u_root;
        xassert_search($u, "ti:\"scalable timers\"", 1);
        xassert_search($u, "ti=\"scalable timers\"", 1);
    }

    function test_combine_script_expressions() {
        xassert_eqq(Op_SearchTerm::combine_script_expressions("and", []), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("and", [false, null]), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("and", [true, null]), null);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("and", [true]), true);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("or", []), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("or", [false, null]), null);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("or", [true, null]), true);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("or", [null]), null);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("or", [false, ["type" => "x"]]), ["type" => "x"]);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("not", [false]), true);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("not", [true]), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("not", [["type" => "x"]]), ["type" => "not", "child" => [["type" => "x"]]]);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("not", [null]), null);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [false, null]), null);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [false, false]), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [false, true]), true);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [true, true]), false);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [true, false]), true);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [true, false, true, true, ["type" => "x"]]), ["type" => "not", "child" => [["type" => "x"]]]);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [true, false, true, ["type" => "x"]]), ["type" => "x"]);
        xassert_eqq(Op_SearchTerm::combine_script_expressions("xor", [true, false, false, ["type" => "x"], ["type" => "y"]]), ["type" => "xor", "child" => [["type" => "x"], ["type" => "y"], true]]);
    }

    function test_named_searches() {
        $sv = (new SettingValues($this->u_root))->add_json_string('{
            "named_search": [
                {"name": "foo", "search": "#fart OR #faart"},
                {"name": "bar", "search": "#bar OR #baar"}
            ]
        }');
        xassert($sv->execute());

        $ns = $this->conf->setting_json("named_searches");
        $n = 0;
        foreach ($this->conf->setting_json("named_searches") as $nsj) {
            if ($nsj->name === "bar") {
                xassert_eqq($nsj->q, "#bar OR #baar");
                ++$n;
            } else if ($nsj->name === "foo") {
                xassert_eqq($nsj->q, "#fart OR #faart");
                ++$n;
            }
        }
        xassert_eqq($n, 2);

        $srch = new PaperSearch($this->u_root, "ss:foo OR #faaart THEN ss:bar OR #baaar");
        $tas = $srch->group_anno_list();
        xassert_eqq(count($tas), 2);
        xassert_eqq($tas[0]->heading, "ss:foo OR #faaart");
        xassert_eqq($tas[1]->heading, "ss:bar OR #baaar");
    }

}
