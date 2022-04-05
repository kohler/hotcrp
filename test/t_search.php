<?php
// t_search.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
}
