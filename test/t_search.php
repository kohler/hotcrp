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

    function test_listed_author() {
        // Paper 30 has Christopher Walken as a listed author and
        // d.francis2@place.edu (Dottie Francis, "Place Investigations")
        // as a separately-added contact.
        $u = $this->u_root;

        // listed author: both `au:` and `listedau:` find Walken
        xassert_search($u, "au:Walken", "30");
        xassert_search($u, "listedau:Walken", "30");

        // contact-only author: `au:` finds Dottie by email, name,
        // or affiliation; `listedau:` does not
        xassert_search($u, "au:d.francis2@place.edu", "30");
        xassert_search($u, "listedau:d.francis2@place.edu", "");
        xassert_search($u, "au:Dottie", "30");
        xassert_search($u, "listedau:Dottie", "");
        xassert_search($u, "au:\"Place Investigations\"", "30");
        xassert_search($u, "listedau:\"Place Investigations\"", "");
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

    function test_namedsearch_evaluates_in_caller_context() {
        // A shared saved search executes in the CALLING user's context, not the
        // owner's: a personal `~tag` in the definition resolves to the caller's
        // own tag, so no saving-user state leaks into another user's results
        // (only the definition text is shared). Invariant, not policy.
        $before = $this->conf->setting_data("named_searches");
        $a = $this->conf->checked_user_by_email("marina@poema.ru");
        $b = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($a->isPC && $b->isPC && $a->contactId !== $b->contactId);

        xassert_assign($a, "paper,tag\n1,~sscx\n");
        xassert_assign($b, "paper,tag\n2,~sscx\n");
        // `a` saves a global search whose body is the personal tag "#~sscx"
        $this->conf->save_setting("named_searches", 1,
            json_encode([(object) ["name" => "sscx", "q" => "#~sscx", "owner" => $a->contactId]]));
        $this->conf->load_settings();

        xassert_search($a, "ss:sscx", "1");   // a's own ~sscx (paper 1)
        xassert_search($b, "ss:sscx", "2");   // b's own ~sscx (paper 2), NOT paper 1

        if ($before === null) {
            $this->conf->save_setting("named_searches", null);
        } else {
            $this->conf->save_setting("named_searches", 1, $before);
        }
        $this->conf->load_settings();
        $this->conf->qe("delete from PaperTag where tag like '%~sscx'");
    }

    function test_sensitive_search_rate_limit() {
        $u = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($u->contactId > 0);
        $this->conf->qe("delete from ContactCounter where contactId=?", $u->contactId);
        $this->conf->set_opt("sensitiveSearchRefreshAmount", 2);
        $this->conf->set_opt("sensitiveSearchRefreshWindow", 3600000);

        // `sensitive_search_account()` updates the counter row directly in SQL
        // and marks the in-memory object stale, so throughout this test we
        // `invalidate_contact_counter()` before each search (to model a fresh
        // per-request counter) and `->ensure()` afterward (to reload the
        // persisted counts before asserting on them).

        // `ti:the` is imprecise (SQL superset filtered in PHP); the budget
        // permits 2 such searches, then degrades to leak-free precise SQL.
        $u->invalidate_contact_counter();
        $s1 = new PaperSearch($u, "ti:the");
        $base = $s1->paper_ids();
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 1);   // accounted
        xassert_eqq($cc->sensitiveSearchFallbackCount, 0);
        xassert(count($base) > 0);

        // Precise searches never consume the budget or degrade.
        for ($i = 0; $i < 5; ++$i) {
            $u->invalidate_contact_counter();
            $sp = new PaperSearch($u, "status:submitted");
            $sp->paper_ids();
        }
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 1);   // unchanged by precise searches
        xassert_eqq($cc->sensitiveSearchFallbackCount, 0);

        // Go back to `ti:the`
        $u->invalidate_contact_counter();
        $s2 = new PaperSearch($u, "ti:the");
        $s2->paper_ids();
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 2);
        xassert_eqq($cc->sensitiveSearchFallbackCount, 0);

        $u->invalidate_contact_counter();
        $s3 = new PaperSearch($u, "ti:the");
        $ids3 = $s3->paper_ids();
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 2);    // overbudget => fallback
        xassert_eqq($cc->sensitiveSearchFallbackCount, 1);
        xassert_eqq($ids3, $base);                   // results unchanged

        // Chairs are exempt: their searches leak nothing, so they are never
        // accounted even when imprecise.
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert($chair->privChair);
        $this->conf->qe("delete from ContactCounter where contactId=?", $chair->contactId);
        for ($i = 0; $i < 5; ++$i) {
            $chair->invalidate_contact_counter();
            $sc = new PaperSearch($chair, "ti:the");
            $sc->paper_ids();
        }
        $cc = $chair->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 0);  // never accounted
        xassert_eqq($cc->sensitiveSearchFallbackCount, 0);
        $this->conf->qe("delete from ContactCounter where contactId=?", $chair->contactId);

        $this->conf->qe("delete from ContactCounter where contactId=?", $u->contactId);
        $this->conf->set_opt("sensitiveSearchRefreshAmount", null);
        $this->conf->set_opt("sensitiveSearchRefreshWindow", null);
    }

    function test_sensitive_search_window_rollover() {
        $u = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->conf->qe("delete from ContactCounter where contactId=?", $u->contactId);
        $this->conf->set_opt("sensitiveSearchRefreshAmount", 2);
        $this->conf->set_opt("sensitiveSearchRefreshWindow", 1000);   // 1-second window

        // Drive accounting directly so we can control time and cross a window
        // boundary; `invalidate_contact_counter()` models a fresh request.
        $account = function () use ($u) {
            $u->invalidate_contact_counter();
            return $u->contact_counter()->sensitive_search_account();
        };

        $save = Conf::$unow;
        Conf::set_current_time(1700000000.0);

        xassert($account());                                // 1: accounted
        xassert($account());                                // 2: accounted
        xassert(!$account());                               // 3: over budget within the window
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 2);
        xassert_eqq($cc->sensitiveSearchFallbackCount, 1);

        // roll past the window end: the budget refreshes
        Conf::set_current_time(1700000002.0);
        xassert($account());
        $cc = $u->contact_counter()->ensure();
        xassert_eqq($cc->sensitiveSearchCount, 3);          // accounted again
        xassert_eqq($cc->sensitiveSearchBase, 2);           // base advanced to the rollover count
        xassert_eqq($cc->sensitiveSearchFallbackCount, 1);  // unchanged by the refresh

        Conf::set_current_time($save);
        $this->conf->qe("delete from ContactCounter where contactId=?", $u->contactId);
        $this->conf->set_opt("sensitiveSearchRefreshAmount", null);
        $this->conf->set_opt("sensitiveSearchRefreshWindow", null);
    }

    function test_decision_none_matches_invisible_decision() {
        // When a user cannot see a paper's decision, that decision degrades to
        // 0 ("no decision") for that user (see `Decision_SearchTerm::test()`),
        // so the paper *should* match `dec:none`. This checks that the SQL
        // prefilter agrees with the precise `test()` semantics.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // Non-conflicted reviewers may see decisions; conflicted ones may not.
        $conf->save_refresh_setting("seedec", Conf::SEEDEC_NCREV);

        // Give paper 3 a positive (accept) decision. mgbaker is a PC conflict
        // on paper 3, so under SEEDEC_NCREV she cannot see that decision.
        xassert_assign($chair, "paper,action,decision\n3,decision,accept\n");
        $prow = $conf->checked_paper_by_id(3);
        xassert_gt($prow->outcome, 0);
        xassert(!$mgbaker->can_view_decision($prow));

        // She reviews other, non-conflicted papers, so she *can* see some
        // decisions and is not an all-powerful administrator. This is exactly
        // the case that exercises the buggy branch of `sqlexpr()`.
        xassert($mgbaker->can_view_some_decision());
        xassert(!$mgbaker->allow_admin_all());

        // The decision is invisible to her, so paper 3 degrades to "no
        // decision" and must match `dec:none`.
        $srch = new PaperSearch($mgbaker, "dec:none");
        xassert_in_eqq(3, $srch->paper_ids());

        // clean up
        xassert_assign($chair, "paper,action,decision\n3,cleardecision,accept\n");
        $conf->save_refresh_setting("seedec", null);
    }

    function test_decision_none_seedec_rev_hides_own_decision() {
        // Regression: under SEEDEC_REV a PC member `can_view_all_decision()`,
        // which drove a *precise* `outcome=0` SQL prefilter for `dec:none`. But a
        // PC member who authors a submission views that paper as an author, so its
        // decision follows the author-release rules — and until decisions are
        // released to authors, it stays hidden even though the paper is visible.
        // The precise prefilter dropped that decided paper before `test()` could
        // keep it as "no decision", omitting it from `dec:none` and so leaking
        // that a decision exists. `can_view_all_decision()` therefore holds only
        // once decisions are released to all authors.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");

        $old_seedec = $conf->setting("seedec");
        $old_au_seedec = $conf->setting("au_seedec");
        $conf->save_refresh_setting("seedec", Conf::SEEDEC_REV);
        $conf->save_refresh_setting("au_seedec", null); // decisions NOT released to authors

        // estrin is a PC member and an author of paper 1.
        $estrin = $conf->checked_user_by_email("estrin@usc.edu");
        xassert($estrin->isPC);

        xassert_assign($chair, "paper,action,decision\n1,decision,accept\n");
        $prow = $conf->checked_paper_by_id(1);
        xassert_gt($prow->outcome, 0);

        // She sees her own paper but not its (author-release-gated) decision, is
        // not an administrator, and — with the fix — no longer claims to view all
        // decisions, since her own decision is hidden from her.
        xassert($estrin->can_view_paper($prow));
        xassert(!$estrin->can_view_decision($prow));
        xassert(!$estrin->allow_admin_all());
        xassert(!$estrin->can_view_all_decision());

        // The decision is invisible to her, so paper 1 degrades to "no
        // decision" and must appear in both decision-none search vectors.
        xassert_in_eqq(1, (new PaperSearch($estrin, "dec:none"))->paper_ids());
        xassert_in_eqq(1, (new PaperSearch($estrin, "in:undecided"))->paper_ids());

        // Once decisions are released to all authors, she can view her own
        // decision, so `can_view_all_decision()` holds and the precise prefilter
        // is sound: paper 1 leaves `dec:none` and appears in `dec:yes`.
        $conf->save_refresh_setting("au_seedec", 1); // released to all authors
        $estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $prow = $conf->checked_paper_by_id(1);
        xassert($estrin->can_view_decision($prow));
        xassert($estrin->can_view_all_decision());
        xassert_not_in_eqq(1, (new PaperSearch($estrin, "dec:none"))->paper_ids());
        xassert_in_eqq(1, (new PaperSearch($estrin, "dec:yes"))->paper_ids());

        // clean up
        xassert_assign($chair, "paper,action,decision\n1,cleardecision,accept\n");
        $conf->save_refresh_setting("seedec", $old_seedec);
        $conf->save_refresh_setting("au_seedec", $old_au_seedec);
    }

    function test_conflict_count_hides_invisible_conflicts() {
        // Regression: `Conflict_SearchTerm::sqlexpr` emitted a subtractive
        // `not exists` for `conflict:U=0` (and raw upper-bound counts) using the
        // TRUE conflict rows, while `test()` counts a member's conflict only when
        // the searcher `can_view_conflicts($row)`. When conflicts are hidden from
        // the PC (`sub_pcconfvis=1`), a paper with a hidden conflict was dropped
        // from `conflict:U=0` before `test()` could keep it (it looks like 0 to
        // the searcher) — so the omission leaked that U is conflicted there.
        $conf = $this->conf;
        $old_pccv = $conf->setting("sub_pcconfvis");
        $conf->save_refresh_setting("sub_pcconfvis", 1); // hide conflicts from PC

        $chair = $conf->checked_user_by_email("chair@_.com");
        $searcher = $conf->checked_user_by_email("mgbaker@cs.stanford.edu"); // PC, not admin of p5
        $marina = $conf->checked_user_by_email("marina@poema.ru");

        // marina gets a hidden PC conflict on paper 5; searcher is not conflicted there.
        xassert_assign($chair, "paper,action,user\n5,conflict,marina@poema.ru\n");
        $p5 = $conf->checked_paper_by_id(5);
        xassert($searcher->can_view_paper($p5));
        xassert(!$searcher->can_view_conflicts($p5));

        // The conflict is invisible to the searcher, so paper 5 counts as 0 and
        // must satisfy `=0` and `<2`; it must never surface via `>0`.
        xassert_in_eqq(5, (new PaperSearch($searcher, "conflict:\"marina@poema.ru\"=0"))->paper_ids());
        xassert_in_eqq(5, (new PaperSearch($searcher, "conflict:\"marina@poema.ru\"<2"))->paper_ids());
        xassert_not_in_eqq(5, (new PaperSearch($searcher, "conflict:\"marina@poema.ru\">0"))->paper_ids());

        // An administrator sees the conflict, so their results are exact.
        xassert_not_in_eqq(5, (new PaperSearch($chair, "conflict:\"marina@poema.ru\"=0"))->paper_ids());
        xassert_in_eqq(5, (new PaperSearch($chair, "conflict:\"marina@poema.ru\">0"))->paper_ids());

        // clean up
        xassert_assign($chair, "paper,action,user\n5,noconflict,marina@poema.ru\n");
        $conf->save_refresh_setting("sub_pcconfvis", $old_pccv);
    }

    function test_desirability_column_respects_aggregate_pref_visibility() {
        // Regression: the Desirability column renders the signed reviewer-
        // preference aggregate. `prepare()` gates only on the global `is_manager()`
        // role, so without a per-paper `content_empty()` a manager who is
        // conflicted with (or track-restricted from) a paper saw its desirability
        // even though `can_view_preference($row, aggregate)` is false there.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");

        // marina becomes an assigned manager of paper 4 -> is_manager() globally.
        xassert_assign($chair, "action,paper,user\nadministrator,4,marina@poema.ru\n");
        // ...but she is conflicted with paper 3, which she does not manage.
        xassert_assign($chair, "paper,action,user\n3,conflict,marina@poema.ru\n");
        // seed reviewer preferences on paper 3 so its desirability is non-zero.
        foreach ([["mogul@wrl.dec.com", 8], ["van@ee.lbl.gov", 6], ["jon@cs.ucl.ac.uk", -20]] as list($em, $pf)) {
            $rid = $conf->checked_user_by_email($em)->contactId;
            $conf->qe("insert into PaperReviewPreference (paperId,contactId,preference,expertise) values (3,?,?,null) on duplicate key update preference=?", $rid, $pf, $pf);
        }
        $conf->invalidate_caches("pc");

        $marina = $conf->checked_user_by_email("marina@poema.ru");
        $p3 = $conf->checked_paper_by_id(3);
        $p4 = $conf->checked_paper_by_id(4);
        xassert($marina->is_manager());
        xassert($marina->can_view_paper($p3));
        xassert(!$marina->can_view_preference($p3, true)); // conflicted -> no aggregate prefs
        xassert_neqq($p3->desirability(), 0);

        $col = PaperColumn::make($conf, $conf->paper_columns("desirability", $marina)[0]);
        $pl = new PaperList("empty", new PaperSearch($marina, "3 4"));
        xassert($col->prepare($pl, FieldRender::CFLIST));
        // The conflicted paper's cell is blanked; her own managed paper renders.
        xassert($col->content_empty($pl, $p3));
        xassert(!$col->content_empty($pl, $p4));

        // clean up
        xassert_assign($chair, "action,paper,user\nclearadministrator,4,marina@poema.ru\n");
        xassert_assign($chair, "paper,action,user\n3,noconflict,marina@poema.ru\n");
        $conf->qe("delete from PaperReviewPreference where paperId=3 and contactId in (select contactId from ContactInfo where email in ('mogul@wrl.dec.com','van@ee.lbl.gov','jon@cs.ucl.ac.uk'))");
        $conf->invalidate_caches("pc");
    }

    function test_pdf_none_hides_unviewable_pdf() {
        // Regression: `pdf:none`/`submission:none` emitted a subtractive
        // `paperStorageId<=1` on raw document presence, so a paper whose PDF the
        // searcher cannot view (but whose row exists) was dropped from `:none`,
        // leaking that a hidden PDF exists. The absent branch is now a conservative
        // superset and `test()` filters via `viewable_primary_document`. Also pins
        // that the unqualified `pdf:` form loads `mimetype`, so it agrees with
        // document visibility (previously it saw no PDF at all).
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $old_open = $conf->setting("sub_open");
        $old_sub = $conf->setting("sub_sub");
        $old_seeallpdf = $conf->setting("pc_seeallpdf");
        $old_seeall = $conf->setting("pc_seeall");
        $conf->save_refresh_setting("sub_open", 1);
        $conf->save_refresh_setting("sub_sub", Conf::$now + 100000);
        $conf->save_refresh_setting("pc_seeallpdf", 0); // PC cannot view PDFs...
        $conf->save_refresh_setting("pc_seeall", 1);    // ...but can view papers

        $mg = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // Ground truth: for every paper mgbaker can view, `pdf:any` must hold
        // exactly when they can view a PDF primary document, and `pdf:none` the
        // rest. A subtractive prefilter would have dropped hidden-PDF papers from
        // `pdf:none` (leak); the missing-`mimetype` bug would have made every
        // paper PDF-less. Both are excluded by matching ground truth exactly.
        $truth_any = $truth_none = [];
        foreach ($conf->paper_set(["allConflictType" => true]) as $prow) {
            if (!$mg->can_view_paper($prow)) {
                continue;
            }
            $doc = $prow->viewable_primary_document($mg);
            if ($doc && $doc->mimetype === "application/pdf") {
                $truth_any[] = $prow->paperId;
            } else {
                $truth_none[] = $prow->paperId;
            }
        }
        sort($truth_any);
        sort($truth_none);
        $search_any = (new PaperSearch($mg, "pdf:any"))->paper_ids();
        $search_none = (new PaperSearch($mg, "pdf:none"))->paper_ids();
        sort($search_any);
        sort($search_none);
        xassert_eqq($search_any, $truth_any);
        xassert_eqq($search_none, $truth_none);
        // Config took effect and the check is non-vacuous: with PC PDFs hidden,
        // there are viewable papers whose PDF mgbaker cannot see.
        xassert_gt(count($truth_none), 0);

        // The dtype-specific `submission:` form must match the same ground truth
        // (this exercises the conservative absent-branch: a non-admin must not get
        // the subtractive `paperStorageId<=1` prefilter that would drop a viewable
        // paper whose submission PDF is hidden).
        $sub_any = $sub_none = [];
        foreach ($conf->paper_set(["allConflictType" => true]) as $prow) {
            if (!$mg->can_view_paper($prow)) {
                continue;
            }
            $ov = $prow->option(DTYPE_SUBMISSION);
            $doc = $ov && $mg->can_view_option($prow, $ov->option)
                ? $ov->document_set(true)->document_by_index(0) : null;
            if ($doc && $doc->mimetype === "application/pdf") {
                $sub_any[] = $prow->paperId;
            } else {
                $sub_none[] = $prow->paperId;
            }
        }
        sort($sub_any);
        sort($sub_none);
        $ssub_none = (new PaperSearch($mg, "submission:none"))->paper_ids();
        sort($ssub_none);
        xassert_eqq($ssub_none, $sub_none);

        // Administrator sees every PDF: the unqualified `pdf:` form matches
        // `submission:` (it no longer classifies every paper as PDF-less), and the
        // null-dtype `:none` form does not fault.
        xassert_eqq((new PaperSearch($chair, "pdf:any"))->paper_ids(),
                    (new PaperSearch($chair, "submission:any"))->paper_ids());
        xassert(count((new PaperSearch($chair, "final:none"))->paper_ids()) >= 0);

        $conf->save_refresh_setting("sub_open", $old_open);
        $conf->save_refresh_setting("sub_sub", $old_sub);
        $conf->save_refresh_setting("pc_seeallpdf", $old_seeallpdf);
        $conf->save_refresh_setting("pc_seeall", $old_seeall);
    }

    function test_token_none_hides_invisible_token_review() {
        // Regression: `token:none` emitted a subtractive `count(reviewToken)=0`
        // over ALL reviews, so a paper with a token review the searcher cannot see
        // was dropped from `token:none`, leaking that a hidden token review exists.
        // The sqlexpr is now conservative and `test()` counts a token review only
        // when the searcher can view its assignment AND identity.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $old_auseerev = $conf->setting("au_seerev");
        $conf->save_refresh_setting("au_seerev", null); // authors cannot see reviews

        // Stamp a review token on an existing submitted review of estrin's paper 1.
        $rid = $conf->fetch_ivalue("select reviewId from PaperReview where paperId=1 and reviewType>0 order by reviewId asc limit 1");
        xassert_gt($rid, 0);
        $conf->qe("update PaperReview set reviewToken=? where reviewId=?", 8675309, $rid);
        $conf->invalidate_caches("pc");

        $estrin = $conf->checked_user_by_email("estrin@usc.edu"); // author of paper 1
        $p1 = $conf->checked_paper_by_id(1);
        $rrow = null;
        foreach ($p1->all_reviews() as $r) {
            if ($r->reviewId === $rid) {
                $rrow = $r;
            }
        }
        xassert(!$estrin->can_view_review_assignment($p1, $rrow)); // author can't see it exists

        // Leak closed: author can't see the token review, so paper 1 stays in `token:none`.
        xassert_in_eqq(1, (new PaperSearch($estrin, "token:none"))->paper_ids());
        xassert_not_in_eqq(1, (new PaperSearch($estrin, "token:any"))->paper_ids());

        // Chair sees it: `token:any` includes paper 1, `token:none` excludes it.
        xassert_in_eqq(1, (new PaperSearch($chair, "token:any"))->paper_ids());
        xassert_not_in_eqq(1, (new PaperSearch($chair, "token:none"))->paper_ids());

        // clean up
        $conf->qe("update PaperReview set reviewToken=0 where reviewId=?", $rid);
        $conf->save_refresh_setting("au_seerev", $old_auseerev);
    }

    function test_reviewer_aliases_to_search_user() {
        // A `reviewer` naming the search user is canonicalized away, even when
        // it arrives as a distinct Contact object for that same user (as from
        // `Conf::user_by_email`). Otherwise the search would treat the user as
        // a foreign reviewer and apply the administrator restrictions below.
        $conf = $this->conf;
        $mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $mgbaker2 = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($mgbaker !== $mgbaker2);
        xassert_eqq($mgbaker->contactXid, $mgbaker2->contactXid);

        foreach ([$mgbaker2, $mgbaker->email, strtoupper($mgbaker->email)] as $reviewer) {
            $srch = new PaperSearch($mgbaker, ["q" => "", "t" => "reviewable", "reviewer" => $reviewer]);
            xassert_eqq($srch->reviewer_user(), $mgbaker);
            xassert_not_str_contains($srch->encoded_query_params(), "reviewer=");
            xassert_not_str_contains($srch->url_site_relative_raw(), "reviewer=");
        }

        // `reviewable` for oneself is not narrowed to administered papers
        $self = new PaperSearch($mgbaker, ["q" => "", "t" => "reviewable"]);
        $aliased = new PaperSearch($mgbaker, ["q" => "", "t" => "reviewable", "reviewer" => $mgbaker2]);
        xassert(!$mgbaker->allow_admin_all());
        xassert_neqq($self->paper_ids(), []);
        xassert_eqq($aliased->paper_ids(), $self->paper_ids());

        // `re:me` stays `re:me`
        xassert((new PaperSearch($mgbaker, ["q" => "re:me", "reviewer" => $mgbaker2]))->query_is_re_me());

        // a genuinely different reviewer is still honored
        $chair = $conf->checked_user_by_email("chair@_.com");
        $srch = new PaperSearch($chair, ["q" => "", "t" => "reviewable", "reviewer" => $mgbaker->email]);
        xassert_eqq($srch->reviewer_user()->contactId, $mgbaker->contactId);
        xassert_str_contains($srch->encoded_query_params(), "reviewer=" . urlencode($mgbaker->email));
        xassert(!$srch->query_is_re_me());
    }

    function test_nonpc_limits_restricted_to_own_papers() {
        // Non-PC users cannot search all papers: `Limit_SearchTerm::sqlexpr()`
        // ANDs an author-or-reviewer restriction into every base limit
        // (`need_ar === 3`, a left join on `MyReviews`). A bug in that join
        // silently emptied every base-limit search for authors.
        $van = $this->conf->checked_user_by_email("van@ee.lbl.gov");
        xassert(!$van->isPC);
        xassert($van->is_author());
        foreach (["a", "ar", "s", "active", "all", "viewable"] as $t) {
            xassert_search($van, ["t" => $t, "q" => ""], "1");
        }
    }

    function test_reviewable_limit_requires_administrator() {
        // `reviewable` is the one limit relative to `reviewer_user()`; naming
        // another user requires administrator rights over each paper.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        xassert($chair->is_manager());
        xassert(!$lixia->is_manager());
        xassert($lixia->can_view_pc());

        // one's own reviewable set needs no administrator rights
        xassert_eqq(count((new PaperSearch($lixia, ["q" => "", "t" => "reviewable"]))->paper_ids()), 30);

        // an administrator sees another user's reviewable set, and it differs
        // from their own
        $chair_mg = (new PaperSearch($chair, ["q" => "", "t" => "reviewable", "reviewer" => $mgbaker->email]))->paper_ids();
        xassert_eqq(count($chair_mg), 28);
        xassert_neqq($chair_mg, (new PaperSearch($chair, ["q" => "", "t" => "reviewable"]))->paper_ids());

        // a non-administrator gets nothing, though `reviewer` is accepted
        $srch = new PaperSearch($lixia, ["q" => "", "t" => "reviewable", "reviewer" => $mgbaker->email]);
        xassert_eqq($srch->reviewer_user()->contactId, $mgbaker->contactId);
        xassert_eqq($srch->paper_ids(), []);
    }

    function test_limit_evaluators_agree() {
        // `Limit_SearchTerm` is evaluated two ways: `sqlexpr()` + `test()`,
        // which `PaperSearch::paper_ids()` uses, and `simple_search()`, which
        // hands query options straight to PaperList and skips both. Whenever
        // `simple_search()` claims a limit, the two must agree.
        $conf = $this->conf;
        $emails = ["chair@_.com", "mgbaker@cs.stanford.edu", "lixia@cs.ucla.edu",
                   "van@ee.lbl.gov"];
        $limits = ["a", "ar", "r", "rout", "req", "lead", "s", "active", "all",
                   "viewable", "reviewable", "accepted", "undecided", "unsub",
                   "admin", "alladmin", "actadmin"];
        foreach ($emails as $email) {
            $u = $conf->checked_user_by_email($email);
            foreach ($limits as $t) {
                $q = ["q" => "", "t" => $t];
                $via_sql = (new PaperSearch($u, $q))->paper_ids();
                $via_list = array_keys(search_json($u, $q, "id", true));
                sort($via_sql);
                sort($via_list);
                xassert_eqq("{$email} t={$t}: " . join(" ", $via_list),
                            "{$email} t={$t}: " . join(" ", $via_sql));
            }
        }
    }

    /** @param Contact ...$users
     * @return int */
    private function unreviewed_submitted_pid(...$users) {
        foreach ((new PaperSearch($this->conf->root_user(), ["q" => "", "t" => "s"]))->paper_ids() as $pid) {
            $prow = $this->conf->checked_paper_by_id($pid);
            $ok = true;
            foreach ($users as $user) {
                $ok = $ok && !$prow->review_by_user($user) && !$prow->has_conflict($user);
            }
            if ($ok) {
                return $pid;
            }
        }
        throw new ErrorException("no unreviewed submitted paper");
    }

    /** A limit has three evaluators that must agree: `sqlexpr()` + `test()`
     * (PaperSearch), `simple_search()` (PaperList, which skips both `test()`
     * and `can_view_paper()`), and, since `is_sqlexpr_precise()` lets
     * `Not_SearchTerm` negate it in SQL, `-in:LIMIT`.
     * @param Contact $u
     * @param string $t
     * @return list<int> */
    private function xassert_limit_agrees($u, $t) {
        $ids = (new PaperSearch($u, ["q" => "", "t" => $t]))->paper_ids();
        $lids = array_keys(search_json($u, ["q" => "", "t" => $t], "id", true));
        sort($ids);
        sort($lids);
        xassert_eqq("{$u->email} t={$t}: " . join(" ", $lids),
                    "{$u->email} t={$t}: " . join(" ", $ids));
        $all = (new PaperSearch($u, ["q" => "", "t" => "all"]))->paper_ids();
        $in = (new PaperSearch($u, ["q" => "in:{$t}", "t" => "all"]))->paper_ids();
        $out = (new PaperSearch($u, ["q" => "-in:{$t}", "t" => "all"]))->paper_ids();
        xassert_eqq("{$u->email} in:{$t} + -in:{$t}: " . (count($in) + count($out)),
                    "{$u->email} in:{$t} + -in:{$t}: " . count($all));
        return $ids;
    }

    function test_ghost_review_status() {
        // Ghostliness must mean the same to `ReviewInfo::is_ghost()`,
        // `Contact::act_reviewer_sql()`, and `PaperContactInfo::mark_review_type()`:
        // once reviewing closes only *empty* reviews are ghosts, and a ghost
        // confers no review status whatever its reviewNeedsSubmit.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $ext = $conf->checked_user_by_email("van@ee.lbl.gov");
        $pc = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $old_rev_open = $conf->setting("rev_open");
        $conf->save_refresh_setting("rev_open", 1);

        // a drafted, unsubmitted review
        $pid = $this->unreviewed_submitted_pid($ext);
        xassert_assign($chair, "paper,action,email\n{$pid},external,{$ext->email}\n");
        $prow = $conf->checked_paper_by_id($pid);
        $rv = new ReviewValues($ext);
        xassert($rv->parse_json(["ovemer" => 2, "papsum" => "Draft", "ready" => false]));
        xassert($rv->check_and_save($prow, $prow->review_by_user($ext)));

        // a ghost review whose reviewNeedsSubmit is 0, as a secondary's is
        // once its delegate has submitted
        $gpid = $this->unreviewed_submitted_pid($pc);
        xassert_assign($chair, "paper,action,email,ghost\n{$gpid},secondary,{$pc->email},yes\n");
        $conf->qe("update PaperReview set reviewNeedsSubmit=0 where paperId=? and contactId=?",
            $gpid, $pc->contactId);

        $conf->save_refresh_setting("rev_open", null);
        $conf->invalidate_caches("users");
        $ext = $conf->checked_user_by_email("van@ee.lbl.gov");
        $pc = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // the drafted review keeps its author's review rights
        $prow = $conf->checked_paper_by_id($pid);
        xassert(!$prow->review_by_user($ext)->is_ghost());
        xassert($prow->has_active_reviewer($ext));
        xassert($ext->can_view_paper($prow));
        xassert_in_eqq($pid, $this->xassert_limit_agrees($ext, "r"));

        // the ghost review confers nothing
        $gprow = $conf->checked_paper_by_id($gpid);
        xassert($gprow->review_by_user($pc)->is_ghost());
        xassert(!$gprow->has_active_reviewer($pc));
        xassert_not_in_eqq($gpid, $this->xassert_limit_agrees($pc, "r"));

        $conf->qe("delete from PaperReview where (paperId=? and contactId=?) or (paperId=? and contactId=?)",
            $pid, $ext->contactId, $gpid, $pc->contactId);
        $conf->qe("delete from PaperConflict where conflictType<=? and ((paperId=? and contactId=?) or (paperId=? and contactId=?))",
            CONFLICT_MAXUNCONFLICTED, $pid, $ext->contactId, $gpid, $pc->contactId);
        $conf->save_refresh_setting("rev_open", $old_rev_open);
        $conf->invalidate_caches("users");
    }

    function test_limit_edge_cases_agree() {
        // Each state below made a limit's evaluators disagree: `ar` is the
        // union of `a` and `r`, so like them it keeps withdrawn submissions;
        // an author-view capability is an author credential whatever its
        // holder's role; and members of `req` and `lead` may be unable to view
        // the submission, which the PaperList path never checks.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $pc = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $nonpc = $conf->checked_user_by_email("van@ee.lbl.gov");
        $old_rev_open = $conf->setting("rev_open");
        $conf->save_refresh_setting("rev_open", 1);

        // `ar`: a withdrawn submission that `pc` reviews but does not author
        $wpid = null;
        foreach ((new PaperSearch($pc, ["q" => "", "t" => "r"]))->paper_ids() as $x) {
            $prow = $conf->checked_paper_by_id($x);
            if ($prow->timeWithdrawn <= 0 && !$prow->has_author($pc)) {
                $wpid = $x;
                break;
            }
        }
        xassert_neqq($wpid, null);
        xassert_assign($chair, "paper,action\n{$wpid},withdraw\n");
        xassert_in_eqq($wpid, $this->xassert_limit_agrees($pc, "ar"));
        $union = array_unique(array_merge(
            (new PaperSearch($pc, ["q" => "in:a", "t" => "all"]))->paper_ids(),
            (new PaperSearch($pc, ["q" => "in:r", "t" => "all"]))->paper_ids()));
        sort($union);
        xassert_eqq((new PaperSearch($pc, ["q" => "in:ar", "t" => "all"]))->paper_ids(),
                    $union);
        xassert_assign($chair, "paper,action\n{$wpid},revive\n");

        // `a`: an author-view capability, held by a PC member and by a non-PC user
        foreach ([$pc->email, $nonpc->email] as $email) {
            $u = $conf->fresh_user_by_email($email); // private, so no capability leaks
            $pid = $this->unreviewed_submitted_pid($u);
            $u->set_capability("@av{$pid}", true);
            xassert($u->is_author());
            xassert_in_eqq($pid, $this->xassert_limit_agrees($u, "a"));
        }

        // `req`: a requester of a review on a submission that was withdrawn.
        // (A requester keeps `requestedBy` after being unassigned.)
        $pid = $this->unreviewed_submitted_pid($pc, $nonpc);
        xassert_assign($chair, "paper,action,email\n{$pid},external,{$nonpc->email}\n");
        $conf->qe("update PaperReview set requestedBy=? where paperId=? and contactId=?",
            $pc->contactId, $pid, $nonpc->contactId);
        xassert_assign($chair, "paper,action\n{$pid},withdraw\n");
        $conf->invalidate_caches("users");
        xassert(!$pc->can_view_paper($conf->checked_paper_by_id($pid)));
        xassert_not_in_eqq($pid, $this->xassert_limit_agrees($pc, "req"));
        // the request is moot, so it leaves `req` even for an administrator
        xassert_not_in_eqq($pid, $this->xassert_limit_agrees($chair, "req"));

        // `lead`: a lead who is no longer a PC member
        xassert_assign($chair, "paper,action\n{$pid},revive\n");
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $pid, $nonpc->contactId);
        $conf->qe("update Paper set leadContactId=? where paperId=?", $nonpc->contactId, $pid);
        $conf->invalidate_caches("users");
        $nonpc = $conf->checked_user_by_email("van@ee.lbl.gov");
        xassert(!$nonpc->can_view_paper($conf->checked_paper_by_id($pid)));
        xassert_not_in_eqq($pid, $this->xassert_limit_agrees($nonpc, "lead"));

        $conf->qe("update Paper set leadContactId=0 where paperId=?", $pid);
        $conf->qe("delete from PaperConflict where paperId=? and contactId=? and conflictType<=?",
            $pid, $nonpc->contactId, CONFLICT_MAXUNCONFLICTED);
        $conf->save_refresh_setting("rev_open", $old_rev_open);
        $conf->invalidate_caches("users");
    }

    function test_pref_search_hides_individual_preferences() {
        // `can_view_preference($prow, false)` reserves an individual's review
        // preferences to administrators; only the PC-wide aggregate is open to
        // ordinary PC members (see h_keywords.php: "Administrators can search
        // preferences by name; PC members can only search preferences for the
        // PC as a whole"). `Revpref_SearchTerm::parse()` relaxes the gate to
        // the aggregate form whenever `matching_special_uids()` resolves the
        // word without error — but `chair`, `admin`, and a user tag can each
        // resolve to a *single named person*, so that relaxation must not be
        // reachable for them.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        xassert(!$lixia->is_manager());
        $p1 = $conf->checked_paper_by_id(1);
        xassert(!$lixia->can_view_preference($p1, false));

        $conf->qe("delete from PaperReviewPreference where contactId=?", $chair->contactId);
        $conf->qe("insert into PaperReviewPreference (paperId,contactId,preference) values (2,?,5), (4,?,-3)",
            $chair->contactId, $chair->contactId);
        // a *user* tag borne by exactly one PC member resolves the same way
        $old_tags = $chair->contactTags;
        $conf->qe("update ContactInfo set contactTags=? where contactId=?",
            " chairtag#0", $chair->contactId);
        $conf->invalidate_caches("pc");
        xassert($conf->pc_tag_exists("chairtag"));
        xassert($lixia->can_view_user_tag("chairtag"));

        // the sanctioned spelling is denied...
        xassert_search_all($lixia, "pref:{$chair->email}>0", "");
        xassert_search_all($lixia, "pref:\"{$chair->email}\">0", "");
        // ...so these must be denied too: each names the same one person
        xassert_search_all($lixia, "pref:chair>0", "");
        xassert_search_all($lixia, "pref:chair<0", "");
        xassert_search_all($lixia, "pref:admin>0", "");
        xassert_search_all($lixia, "pref:#chairtag>0", "");

        // an administrator may still search preferences by name
        xassert_search_all($chair, "pref:{$chair->email}>0", "2");
        xassert_search_all($chair, "pref:chair<0", "4");
        // and the PC-wide aggregate remains open to ordinary PC members.
        // Other testers leave preferences behind, so check membership rather
        // than pinning the whole result.
        $agg = (new PaperSearch($lixia, ["q" => "pref:pc>0", "t" => "all"]))->paper_ids();
        xassert(in_array(2, $agg, true));

        $conf->qe("delete from PaperReviewPreference where contactId=?", $chair->contactId);
        $conf->qe("update ContactInfo set contactTags=? where contactId=?",
            $old_tags, $chair->contactId);
        $conf->invalidate_caches("pc");
    }

    /** Give the conference a desk-reject decision and hand it to one paper.
     * @param string $name
     * @return int the decision id */
    private function add_desk_reject($name) {
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        $sv = SettingValues::make_request($chair, [
            "has_decision" => 1,
            "decision/1/name" => $name,
            "decision/1/id" => "new",
            "decision/1/category" => "desk_reject"
        ]);
        xassert($sv->execute());
        foreach ($this->conf->decision_set() as $dec) {
            if ($dec->name === $name) {
                xassert_eqq($dec->sign, -2);
                return $dec->id;
            }
        }
        xassert(false);
        return 0;
    }

    /** @param string $name */
    private function remove_decision($name) {
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        foreach ($this->conf->decision_set() as $dec) {
            if ($dec->name === $name) {
                $sv = SettingValues::make_request($chair, [
                    "has_decision" => 1,
                    "decision/1/id" => (string) $dec->id,
                    "decision/1/delete" => "1"
                ]);
                xassert($sv->execute());
                return;
            }
        }
    }

    function test_limit_sa_includes_desk_rejected() {
        // `s` means "submitted, still under consideration": it drops papers
        // whose decision is a desk rejection. `sa` is the same set without that
        // exclusion, so it is a superset of `s` and differs from it by exactly
        // the desk-rejected papers.
        $conf = $this->conf;
        $chair = $conf->checked_user_by_email("chair@_.com");
        $this->add_desk_reject("Desk rejected");

        $s_before = (new PaperSearch($this->u_root, ["q" => "", "t" => "s"]))->paper_ids();
        $sa_before = (new PaperSearch($this->u_root, ["q" => "", "t" => "sa"]))->paper_ids();
        xassert_eqq($sa_before, $s_before);

        xassert_assign($chair, "paper,action,decision\n2,decision,Desk rejected\n");
        xassert_eqq($conf->checked_paper_by_id(2)->outcome_sign, -2);

        $s_after = (new PaperSearch($this->u_root, ["q" => "", "t" => "s"]))->paper_ids();
        $sa_after = (new PaperSearch($this->u_root, ["q" => "", "t" => "sa"]))->paper_ids();
        xassert(!in_array(2, $s_after, true));
        xassert_in_eqq(2, $sa_after);
        // Nothing else moved: the two limits differ by that paper alone.
        xassert_eqq($sa_after, $sa_before);
        xassert_eqq(array_values(array_diff($sa_after, $s_after)), [2]);

        // A desk-rejected paper is still reachable by number only under `sa`
        xassert_search($this->u_root, ["q" => "2", "t" => "sa"], "2");
        xassert_search($this->u_root, ["q" => "2", "t" => "s"], "");

        xassert_assign($chair, "paper,action,decision\n2,cleardecision,Desk rejected\n");
        $this->remove_decision("Desk rejected");
    }
}
