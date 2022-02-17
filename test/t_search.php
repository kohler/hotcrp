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
}
