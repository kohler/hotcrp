<?php
// t_ht.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
}
