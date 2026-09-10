<?php
// t_ht.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

#[RequireDb(false)]
class Ht_Tester {
    function test_select() {
        xassert_eqq(Ht::select("x", ["a", "b", "c"], "a"),
            '<span class="select"><select name="x" data-default-value="0"><option value="0">a</option><option value="1">b</option><option value="2">c</option></select></span>');
        xassert_eqq(Ht::select("x", [
                ["optgroup", "a"],
                "b",
                "c"
            ], 1),
            '<span class="select"><select name="x" data-default-value="1"><optgroup label="a"><option value="1" selected>b</option><option value="2">c</option></optgroup></select></span>');
        xassert_eqq(Ht::select("x", [
                1 => ["optgroup" => "a", "label" => "One"],
                2 => ["optgroup" => "a", "label" => "Two"],
                3 => ["label" => "Three"]
            ], 2),
            '<span class="select"><select name="x" data-default-value="2"><optgroup label="a"><option value="1">One</option><option value="2" selected>Two</option></optgroup><option value="3">Three</option></select></span>');
    }
    function test_link_urls() {
        // basic linkification
        xassert_eqq(Ht::link_urls("see http://ex.com/a here"),
            'see <a href="http://ex.com/a" rel="noreferrer">http://ex.com/a</a> here');
        // trailing sentence punctuation is not part of the URL
        xassert_eqq(Ht::link_urls("go to https://x.org."),
            'go to <a href="https://x.org" rel="noreferrer">https://x.org</a>.');
        // a scheme glued to a preceding word character is not a link
        xassert_eqq(Ht::link_urls("xhttp://foo.com"), "xhttp://foo.com");
        xassert_eqq(Ht::link_urls("1http://foo.com"), "1http://foo.com");
        xassert_eqq(Ht::link_urls("_http://foo.com"), "_http://foo.com");
        // but a boundary character before the scheme still links
        xassert_eqq(Ht::link_urls("(http://foo.com)"),
            '(<a href="http://foo.com" rel="noreferrer">http://foo.com</a>)');
        // no catastrophic backtracking: a crafted run finishes fast
        $evil = str_repeat("ftp://", 20000) . '"x';
        $t0 = microtime(true);
        Ht::link_urls($evil);
        xassert_lt(microtime(true) - $t0, 1.0);
    }

}
