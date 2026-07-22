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
        $conf->invalidate_caches(["pc" => true]);
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
        $conf->invalidate_caches(["pc" => true]);
    }

}
