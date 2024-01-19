<?php
// t_navigation.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Navigation_Tester {
    function test_simple() {
        $ns = NavigationState::make_server([
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "SCRIPT_FILENAME" => __FILE__,
            "REQUEST_URI" => "/fart/barf/?butt",
            "SCRIPT_NAME" => "/fart"
        ]);
        xassert_eqq($ns->host, "butt.com");
        xassert_eqq($ns->php_suffix, "");
        xassert_eqq($ns->resolve("https://foo/bar/baz"), "https://foo/bar/baz");
        xassert_eqq($ns->resolve("http://fooxxx/bar/baz"), "http://fooxxx/bar/baz");
        xassert_eqq($ns->resolve("//foo/bar/baz"), "http://foo/bar/baz");
        xassert_eqq($ns->resolve("/foo/bar/baz"), "http://butt.com/foo/bar/baz");
        xassert_eqq($ns->resolve("after/path"), "http://butt.com/fart/barf/after/path");
        xassert_eqq($ns->resolve("../after/path"), "http://butt.com/fart/after/path");
        xassert_eqq($ns->resolve("?confusion=20"), "http://butt.com/fart/barf/?confusion=20");

        $ns = NavigationState::make_server([
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "SCRIPT_FILENAME" => __FILE__,
            "REQUEST_URI" => "/fart/barf/",
            "SCRIPT_NAME" => "/fart"
        ]);
        xassert_eqq($ns->host, "butt.com");
        xassert_eqq($ns->php_suffix, "");
        xassert_eqq($ns->resolve("after/path"), "http://butt.com/fart/barf/after/path");
        xassert_eqq($ns->resolve("../after/path"), "http://butt.com/fart/after/path");
        xassert_eqq($ns->resolve("?confusion=20"), "http://butt.com/fart/barf/?confusion=20");
        xassert_eqq($ns->resolve("#ass"), "http://butt.com/fart/barf/#ass");

        $ns = NavigationState::make_server([
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "SCRIPT_FILENAME" => __FILE__,
            "REQUEST_URI" => "/fart/barf?whatever",
            "SCRIPT_NAME" => "/fart"
        ]);
        xassert_eqq($ns->host, "butt.com");
        xassert_eqq($ns->php_suffix, "");
        xassert_eqq($ns->resolve("after/path"), "http://butt.com/fart/after/path");
        xassert_eqq($ns->resolve("../after/path"), "http://butt.com/after/path");
        xassert_eqq($ns->resolve("../../after/path"), "http://butt.com/after/path");
        xassert_eqq($ns->resolve("?confusion=20"), "http://butt.com/fart/barf?confusion=20");
        xassert_eqq($ns->resolve("#ass"), "http://butt.com/fart/barf#ass");
    }

    function test_resolve_within() {
        $ns = NavigationState::make_server([
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "SCRIPT_FILENAME" => __FILE__,
            "REQUEST_URI" => "/fart/barf/?butt",
            "SCRIPT_NAME" => "/fart"
        ]);
        xassert_eqq($ns->resolve_within("https://foo/bar/baz", "/fart/"), null);
        xassert_eqq($ns->resolve_within("//fooxxx/bar/baz", "/fart/"), null);
        xassert_eqq($ns->resolve_within("/fart/foo/bar/baz", "/fart/"), "http://butt.com/fart/foo/bar/baz");
        xassert_eqq($ns->resolve_within("/fart/foo/../baz", "/fart/"), "http://butt.com/fart/baz");
        xassert_eqq($ns->resolve_within("/fart/foo/../baz", "/fart/"), "http://butt.com/fart/baz");
        xassert_eqq($ns->resolve_within("foo/../baz", "/fart/"), "http://butt.com/fart/baz");
        xassert_eqq($ns->resolve_within("foo/..", "/fart/"), "http://butt.com/fart/");
        xassert_eqq($ns->resolve_within("foo/../", "/fart/"), "http://butt.com/fart/");
        xassert_eqq($ns->resolve_within("./foo/././../", "/fart/"), "http://butt.com/fart/");
        xassert_eqq($ns->resolve_within("./fo1/fo2/././../?x#a", "/fart/"), "http://butt.com/fart/fo1/?x#a");
        xassert_eqq($ns->resolve_within("./fo1/.afo2/a.fo3/././../?x#a", "/fart/"), "http://butt.com/fart/fo1/.afo2/?x#a");
        xassert_eqq($ns->resolve_within("./fo1/fo2/././../?x#a", "httpx://fart.com"), "httpx://fart.com/fo1/?x#a");
        xassert_eqq($ns->resolve_within("httpx://fart.com/./fo1/fo2/././../?x#a", "httpx://fart.com"), "httpx://fart.com/fo1/?x#a");

        xassert_eqq($ns->resolve_within("https://foo/bar/baz", "/fart"), null);
        xassert_eqq($ns->resolve_within("//fooxxx/bar/baz", "/fart"), null);
        xassert_eqq($ns->resolve_within("/fart/foo/bar/baz", "/fart"), "http://butt.com/fart/foo/bar/baz");
        xassert_eqq($ns->resolve_within("/fart/foo/../baz", "/fart"), "http://butt.com/fart/baz");
        xassert_eqq($ns->resolve_within("/fart/foo/../baz", "/fart"), "http://butt.com/fart/baz");
        xassert_eqq($ns->resolve_within("foo/../fart", "/fart"), "http://butt.com/fart/");
        xassert_eqq($ns->resolve_within("foo/..", "/fart"), null);
        xassert_eqq($ns->resolve_within("./foo/././../fart", "/fart"), "http://butt.com/fart/");
        xassert_eqq($ns->resolve_within("./foo/././../farting", "/fart"), null);
        xassert_eqq($ns->resolve_within("/fart/barf/"), "http://butt.com/fart/barf/");
        xassert_eqq($ns->resolve_within("/fort/bark/"), null);

        $nav = NavigationState::make_base("https://y.com/z/q/");
        xassert_eqq($nav->resolve_within("/z/q/a/b"), "https://y.com/z/q/a/b");
        xassert_eqq($nav->resolve_within("/z/q"), "https://y.com/z/q/");
        xassert_eqq($nav->resolve_within("../q/", "https://y.com/z/q/"), "https://y.com/z/q/");
        xassert_eqq($nav->resolve_within("../q/", ""), "https://y.com/z/q/");
    }

    const FL_OSF = 1;
    const FL_OSN = 2;
    const FL_OSFN = 3;
    const FL_NO_PATHINFO = 4;
    const FL_OSFONLY = 5;

    /** @param string $request_uri
     * @param string $script_name
     * @param string $script_file
     * @param ?string $path_info
     * @param int $flags
     * @return NavigationState */
    static private function make_navstate_file($request_uri, $script_name, $script_file, $path_info = null, $flags = 0, $extra = []) {
        $args = [
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "REQUEST_URI" => $request_uri,
            "SCRIPT_FILENAME" => "/test/installation/" . $script_file,
            "SCRIPT_NAME" => urldecode($script_name)
        ];
        assert($path_info !== null || $flags === 0);
        if (isset($path_info)) {
            if (($flags & self::FL_NO_PATHINFO) === 0) {
                $args["PATH_INFO"] = $path_info;
            }
            if (($flags & self::FL_OSF) !== 0) {
                $args["ORIG_SCRIPT_FILENAME"] = $args["SCRIPT_FILENAME"] . $path_info;
            }
            if (($flags & self::FL_OSN) !== 0) {
                $args["ORIG_SCRIPT_NAME"] = $args["SCRIPT_NAME"] . $path_info;
            }
        }
        return NavigationState::make_server(array_merge($args, $extra));
    }

    /** @param string $request_uri
     * @param string $script_name
     * @param ?string $path_info
     * @param int $flags
     * @return NavigationState */
    static private function make_navstate($request_uri, $script_name, $path_info = null, $flags = 0) {
        return self::make_navstate_file($request_uri, $script_name, "index.php", $path_info, $flags);
    }

    /** @param NavigationState $ns */
    static private function assert_navstate($ns, $base_path, $page, $path, $unproxied = false) {
        xassert_eqq($ns->base_path, $base_path);
        xassert_eqq($ns->page, $page);
        xassert_eqq($ns->path, $path);
        xassert_eqq($ns->unproxied, $unproxied);
    }

    function test_analyze_apache_fpm_proxy() {
        // ProxyPass /base fcgi://localhost:9000/$hotcrp/index.php
        // ProxyFCGIBackendType FPM

        $ns = self::make_navstate("/base", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base", "/base", "/", self::FL_NO_PATHINFO | self::FL_OSF);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/", "/base", "/", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index", "/base", "/index", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index.php", "/base", "/index.php", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/ind%65x.php", "/base", "/index.php", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/search", "/base", "/search", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/search.php", "/base", "/search.php", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/assign/1", "/base", "/assign/1", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign/%31", "/base", "/assign/1", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign.php/1", "/base", "/assign.php/1", self::FL_OSFN);
        self::assert_navstate($ns, "/base/", "assign", "/1");
    }

    function test_analyze_apache_generic_proxy() {
        // ProxyPass /base fcgi://localhost:9000/$hotcrp/index.php
        // ProxyFCGIBackendType GENERIC

        $ns = self::make_navstate("/base", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/", "/base/", "/", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index", "/base/index", "/index", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index.php", "/base/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/ind%65x.php", "/base/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/search", "/base/search", "/search", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/search.php", "/base/search.php", "/search.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/assign/1", "/base/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign/%31", "/base/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign.php/1", "/base/assign.php/1", "/assign.php/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/base/", "assign", "/1");
    }

    function test_analyze_apache_scriptalias() {
        // ScriptAlias "/base" "/$hotcrp/index.php"

        $ns = self::make_navstate("/base", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/", "/base", "/");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index", "/base", "/index");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index.php", "/base", "/index.php");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/ind%65x.php", "/base", "/index.php");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/search", "/base", "/search");
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/search.php", "/base", "/search.php");
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/assign/1", "/base", "/assign/1");
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign/%31", "/base", "/assign/1");
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign.php/1", "/base", "/assign.php/1");
        self::assert_navstate($ns, "/base/", "assign", "/1");
    }

    function test_analyze_apache_module() {
        // Alias "/base" "/$hotcrp"
        // DirectoryIndex index.php

        $ns = self::make_navstate("/base/", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/ind%65x.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate_file("/base/search.php", "/base/search.php", "search.php");
        self::assert_navstate($ns, "/base/", "search", "", true);

        $ns = self::make_navstate_file("/base/assign.php/1", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);

        $ns = self::make_navstate_file("/base/assign.php/%31", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);
    }

    function test_analyze_apache_module_rewrite() {
        // Alias "/base" "/$hotcrp"
        // DirectoryIndex index.php
        // + RewriteRules to add `.php` suffix

        $ns = self::make_navstate("/base/", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/index", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/index.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/ind%65x.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate_file("/base/search", "/base/search.php", "search.php");
        self::assert_navstate($ns, "/base/", "search", "", true);

        $ns = self::make_navstate_file("/base/search.php", "/base/search.php", "search.php");
        self::assert_navstate($ns, "/base/", "search", "", true);

        $ns = self::make_navstate_file("/base/assign/1", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);

        $ns = self::make_navstate_file("/base/assign/%31", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);

        $ns = self::make_navstate_file("/base/assign.php/1", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);
    }

    function test_analyze_apache_module_fallback() {
        // Alias "/base" "/$hotcrp"
        // DirectoryIndex index.php

        $ns = self::make_navstate("/base/", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/index", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/index.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/ind%65x.php", "/base/index.php");
        self::assert_navstate($ns, "/base/", "index", "", true);

        $ns = self::make_navstate("/base/search", "/base/index.php");
        self::assert_navstate($ns, "/base/", "search", "", true);

        $ns = self::make_navstate_file("/base/search.php", "/base/search.php", "search.php");
        self::assert_navstate($ns, "/base/", "search", "", true);

        $ns = self::make_navstate("/base/assign/1", "/base/index.php");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);

        $ns = self::make_navstate("/base/assign/%31", "/base/index.php");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);

        $ns = self::make_navstate_file("/base/assign.php/1", "/base/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/base/", "assign", "/1", true);
    }

    function test_analyze_nginx() {
        // fastcgi_pass 127.0.0.1:9000
        // fastcgi_split_path_info

        $ns = self::make_navstate("/base/", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/index.php", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/ind%65x.php", "/base");
        self::assert_navstate($ns, "/base/", "index", "");

        $ns = self::make_navstate("/base/search", "/base");
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/search.php", "/base");
        self::assert_navstate($ns, "/base/", "search", "");

        $ns = self::make_navstate("/base/assign/1", "/base");
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign/%31", "/base");
        self::assert_navstate($ns, "/base/", "assign", "/1");

        $ns = self::make_navstate("/base/assign.php/1", "/base");
        self::assert_navstate($ns, "/base/", "assign", "/1");
    }


    function test_analyze_encbase_apache_fpm_proxy() {
        // ProxyPass /base fcgi://localhost:9000/$hotcrp/index.php
        // ProxyFCGIBackendType FPM

        $ns = self::make_navstate("/%62%61se/", "/%62%61se", "/", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index", "/%62%61se", "/index", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se", "/index.php", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se", "/index.php", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/search", "/%62%61se", "/search", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/search.php", "/%62%61se", "/search.php", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/assign/1", "/%62%61se", "/assign/1", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign/%31", "/%62%61se", "/assign/1", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign.php/1", "/%62%61se", "/assign.php/1", self::FL_OSFN);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");
    }

    function test_analyze_encbase_apache_generic_proxy() {
        // ProxyPass /base fcgi://localhost:9000/$hotcrp/index.php
        // ProxyFCGIBackendType GENERIC

        $ns = self::make_navstate("/%62%61se/", "/%62%61se/", "/", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index", "/%62%61se/index", "/index", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/search", "/%62%61se/search", "/search", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/search.php", "/%62%61se/search.php", "/search.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/assign/1", "/%62%61se/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign/%31", "/%62%61se/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign.php/1", "/%62%61se/assign.php/1", "/assign.php/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");
    }

    function test_analyze_encbase_apache_scriptalias() {
        // ScriptAlias "/base" "/$hotcrp/index.php"

        $ns = self::make_navstate("/%62%61se/", "/%62%61se", "/");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index", "/%62%61se", "/index");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se", "/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se", "/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/search", "/%62%61se", "/search");
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/search.php", "/%62%61se", "/search.php");
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/assign/1", "/%62%61se", "/assign/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign/%31", "/%62%61se", "/assign/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign.php/1", "/%62%61se", "/assign.php/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");
    }

    function test_analyze_encbase_apache_module() {
        // Alias "/base" "/$hotcrp"
        // DirectoryIndex index.php

        $ns = self::make_navstate("/%62%61se/", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate_file("/%62%61se/search.php", "/%62%61se/search.php", "search.php");
        self::assert_navstate($ns, "/%62%61se/", "search", "", true);

        $ns = self::make_navstate_file("/%62%61se/assign.php/1", "/%62%61se/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1", true);

        $ns = self::make_navstate_file("/%62%61se/assign.php/%31", "/%62%61se/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1", true);
    }

    function test_analyze_encbase_apache_module_rewrite() {
        // Alias "/base" "/$hotcrp"
        // DirectoryIndex index.php
        // + RewriteRules to add `.php` suffix

        $ns = self::make_navstate("/%62%61se/", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate("/%62%61se/index", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se/index.php");
        self::assert_navstate($ns, "/%62%61se/", "index", "", true);

        $ns = self::make_navstate_file("/%62%61se/search", "/%62%61se/search.php", "search.php");
        self::assert_navstate($ns, "/%62%61se/", "search", "", true);

        $ns = self::make_navstate_file("/%62%61se/search.php", "/%62%61se/search.php", "search.php");
        self::assert_navstate($ns, "/%62%61se/", "search", "", true);

        $ns = self::make_navstate_file("/%62%61se/assign/1", "/%62%61se/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1", true);

        $ns = self::make_navstate_file("/%62%61se/assign/%31", "/%62%61se/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1", true);

        $ns = self::make_navstate_file("/%62%61se/assign.php/1", "/%62%61se/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1", true);
    }

    function test_analyze_encbase_nginx() {
        // fastcgi_pass 127.0.0.1:9000
        // fastcgi_split_path_info

        $ns = self::make_navstate("/%62%61se/", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/index.php", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/ind%65x.php", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "index", "");

        $ns = self::make_navstate("/%62%61se/search", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/search.php", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "search", "");

        $ns = self::make_navstate("/%62%61se/assign/1", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign/%31", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");

        $ns = self::make_navstate("/%62%61se/assign.php/1", "/%62%61se");
        self::assert_navstate($ns, "/%62%61se/", "assign", "/1");
    }


    function test_analyze_root_apache_fpm_proxy() {
        // ProxyPass / fcgi://localhost:9000/$hotcrp/index.php/
        // ProxyFCGIBackendType FPM

        $ns = self::make_navstate("/", "/", "/", self::FL_OSF);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index", "/index", "/index", self::FL_OSF);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index.php", "/index.php", "/index.php", self::FL_OSF);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/ind%65x.php", "/", "/index.php", self::FL_OSF);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/search", "/search", "/search", self::FL_OSF);
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/search.php", "/search.php", "/search.php", self::FL_OSF);
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/assign/1", "/assign/1", "/assign/1", self::FL_OSF);
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign/%31", "/assign/1", "/assign/1", self::FL_OSF);
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign.php/1", "/assign.php/1", "/assign.php/1", self::FL_OSF);
        self::assert_navstate($ns, "/", "assign", "/1");
    }

    function test_analyze_root_apache_generic_proxy() {
        // ProxyPass / fcgi://localhost:9000/$hotcrp/index.php
        // ProxyFCGIBackendType GENERIC

        $ns = self::make_navstate("/", "/", "/", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index", "/index", "/index", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index.php", "/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/ind%65x.php", "/index.php", "/index.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/search", "/search", "/search", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/search.php", "/search.php", "/search.php", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/assign/1", "/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign/%31", "/assign/1", "/assign/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign.php/1", "/assign.php/1", "/assign.php/1", self::FL_OSFONLY);
        self::assert_navstate($ns, "/", "assign", "/1");
    }

    function test_analyze_root_apache_scriptalias() {
        // ScriptAlias "/" "/$hotcrp/index.php/"

        $ns = self::make_navstate("/", "", "/");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index", "", "/index");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index.php", "", "/index.php");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/ind%65x.php", "", "/index.php");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/search", "", "/search");
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/search.php", "", "/search.php");
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/assign/1", "", "/assign/1");
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign/%31", "", "/assign/1");
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign.php/1", "", "/assign.php/1");
        self::assert_navstate($ns, "/", "assign", "/1");
    }

    function test_analyze_root_apache_module() {
        // Alias "/" "/$hotcrp"
        // DirectoryIndex index.php

        $ns = self::make_navstate("/", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate("/index.php", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate("/ind%65x.php", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate_file("/search.php", "/search.php", "search.php");
        self::assert_navstate($ns, "/", "search", "", true);

        $ns = self::make_navstate_file("/assign.php/1", "/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/", "assign", "/1", true);

        $ns = self::make_navstate_file("/assign.php/%31", "/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/", "assign", "/1", true);
    }

    function test_analyze_root_apache_module_rewrite() {
        // Alias "/" "/$hotcrp"
        // DirectoryIndex index.php
        // + RewriteRules to add `.php` suffix

        $ns = self::make_navstate("/", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate("/index", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate("/index.php", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate("/ind%65x.php", "/index.php");
        self::assert_navstate($ns, "/", "index", "", true);

        $ns = self::make_navstate_file("/search", "/search.php", "search.php");
        self::assert_navstate($ns, "/", "search", "", true);

        $ns = self::make_navstate_file("/search.php", "/search.php", "search.php");
        self::assert_navstate($ns, "/", "search", "", true);

        $ns = self::make_navstate_file("/assign/1", "/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/", "assign", "/1", true);

        $ns = self::make_navstate_file("/assign/%31", "/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/", "assign", "/1", true);

        $ns = self::make_navstate_file("/assign.php/1", "/assign.php", "assign.php", "/1");
        self::assert_navstate($ns, "/", "assign", "/1", true);
    }

    function test_analyze_root_nginx() {
        // fastcgi_pass 127.0.0.1:9000
        // fastcgi_split_path_info

        $ns = self::make_navstate("/", "");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index", "");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/index.php", "");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/ind%65x.php", "");
        self::assert_navstate($ns, "/", "index", "");

        $ns = self::make_navstate("/search", "");
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/search.php", "");
        self::assert_navstate($ns, "/", "search", "");

        $ns = self::make_navstate("/assign/1", "");
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign/%31", "");
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign.php/1", "");
        self::assert_navstate($ns, "/", "assign", "/1");

        $ns = self::make_navstate("/assign.php/%3F", "");
        self::assert_navstate($ns, "/", "assign", "/%3F");
    }


    function test_php_suffix_override() {
        $ns = NavigationState::make_server([
            "HTTP_HOST" => "butt.com", "SERVER_PORT" => 80,
            "SERVER_SOFTWARE" => "Apache 2.4", "SCRIPT_FILENAME" => __FILE__,
            "REQUEST_URI" => "/fart/barf/?butt",
            "SCRIPT_NAME" => "/fart",
            "HOTCRP_PHP_SUFFIX" => ".xxx"
        ]);
        xassert_eqq($ns->php_suffix, ".xxx");
    }
}
