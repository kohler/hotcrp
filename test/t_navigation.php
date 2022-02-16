<?php
// t_navigation.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Navigation_Tester {
    function test_simple() {
        $ns = new NavigationState(["SERVER_PORT" => 80, "SCRIPT_FILENAME" => __FILE__,
                                   "SCRIPT_NAME" => __FILE__, "REQUEST_URI" => "/fart/barf/?butt",
                                   "HTTP_HOST" => "butt.com", "SERVER_SOFTWARE" => "nginx"]);
        xassert_eqq($ns->host, "butt.com");
        xassert_eqq($ns->php_suffix, "");
        xassert_eqq($ns->make_absolute("https://foo/bar/baz"), "https://foo/bar/baz");
        xassert_eqq($ns->make_absolute("http://fooxxx/bar/baz"), "http://fooxxx/bar/baz");
        xassert_eqq($ns->make_absolute("//foo/bar/baz"), "http://foo/bar/baz");
        xassert_eqq($ns->make_absolute("/foo/bar/baz"), "http://butt.com/foo/bar/baz");
        xassert_eqq($ns->make_absolute("after/path"), "http://butt.com/fart/barf/after/path");
        xassert_eqq($ns->make_absolute("../after/path"), "http://butt.com/fart/after/path");
        xassert_eqq($ns->make_absolute("?confusion=20"), "http://butt.com/fart/barf/?confusion=20");
    }

    function test_php_suffix_override() {
        $ns = new NavigationState(["SERVER_PORT" => 80, "SCRIPT_FILENAME" => __FILE__,
                                   "SCRIPT_NAME" => __FILE__, "REQUEST_URI" => "/fart/barf/?butt",
                                   "HTTP_HOST" => "butt.com", "SERVER_SOFTWARE" => "Apache 2.4",
                                   "HOTCRP_PHP_SUFFIX" => ".xxx"]);
        xassert_eqq($ns->php_suffix, ".xxx");
    }
}
