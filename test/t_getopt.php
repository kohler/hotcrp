<?php
// t_getopt.php -- HotCRP tests
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

#[RequireDb(false)]
class Getopt_Tester {
    static function getopt_parse($getopt, $argv) {
        assert($argv[0] === "fart");
        try {
            return $getopt->parse($argv);
        } catch (CommandLineException $ex) {
            return $ex->getMessage();
        }
    }

    function test_getopt() {
        $arg = (new Getopt)->long("a", "ano", "b[]", "c[]", "d:", "e[]+")
            ->parse(["fart", "-a", "-c", "x", "-cy", "-d=a", "-e", "a", "b", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"d":"a","e":["a","b","c"],"_":[]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")
            ->parse(["fart", "-a", "-c", "x", "-cy", "-d=a", "-e", "a", "b", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"d":"a","e":["a","b","c"],"_":[]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")
            ->parse(["fart", "-a", "-c", "x", "-cy", "-d=a", "-e", "a", "b", "--", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"d":"a","e":["a","b"],"_":["c"]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")
            ->parse(["fart", "-a", "-c", "x", "-cy", "-d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"d":"a","e":["a","b"],"_":["c"]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")
            ->parse(["fart", "-a", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"_":["d=a","-e","a","b","-a","c"]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->interleave(true)
            ->parse(["fart", "-a", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"c":["x","y"],"e":["a","b"],"_":["d=a","c"]}');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->interleave(true)
            ->parse(["fart", "-a", "--", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"_":["-c","x","-cy","d=a","-e","a","b","-a","c"]}');

        $arg = self::getopt_parse((new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->interleave(true),
            ["fart", "-a", "--", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '{"a":false,"_":["-c","x","-cy","d=a","-e","a","b","-a","c"]}');

        $arg = self::getopt_parse((new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->interleave(true),
            ["fart", "-a=xxxx", "--", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '"`-a` takes no argument"');

        $arg = self::getopt_parse((new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->interleave(true),
            ["fart", "-axxxx", "--", "-c", "x", "-cy", "d=a", "-e", "a", "b", "-a", "c"]);
        xassert_eqq(json_encode($arg), '"Unknown option `-x`"');

        $arg = self::getopt_parse((new Getopt)->long("a: =FOO {n}"),
            ["fart", "-a10", "c"]);
        xassert_eqq(json_encode($arg), '{"a":10,"_":["c"]}');

        $arg = self::getopt_parse((new Getopt)->long("a: !subc {n} =FOO"),
            ["fart", "-a10", "c"]);
        xassert_eqq(json_encode($arg), '{"a":10,"_":["c"]}');

        $arg = self::getopt_parse((new Getopt)->long("a: {n} =FOO"),
            ["fart", "-a10x", "c"]);
        xassert_eqq(json_encode($arg), '"`-a` requires integer"');

        $arg = self::getopt_parse((new Getopt)->long("a: =FOO"),
            ["fart", "-a10", "c"]);
        xassert_eqq(json_encode($arg), '{"a":"10","_":["c"]}');

        $arg = self::getopt_parse((new Getopt)->long("a:: =FOO", "b:: =BAR"),
            ["fart", "-a", "-bc"]);
        xassert_eqq(json_encode($arg), '{"a":false,"b":"c","_":[]}');

        $arg = self::getopt_parse((new Getopt)->long("a:", "b", "c", "d"),
            ["fart", "-a", "-bc", "-bd"]);
        xassert_eqq(json_encode($arg), '{"a":"-bc","b":false,"d":false,"_":[]}');

        $arg = self::getopt_parse((new Getopt)->long("a:", "b", "c", "d"),
            ["fart", "-a=", "-bc", "-bd"]);
        xassert_eqq(json_encode($arg), '{"a":"","b":false,"c":false,"d":false,"_":[]}');

        $arg = self::getopt_parse((new Getopt)->long("a:", "b", "c", "d"),
            ["fart", "-a=", "-b=c", "-bd"]);
        xassert_eqq(json_encode($arg), '"`-b` takes no argument"');

        $arg = self::getopt_parse((new Getopt)->long("a:", "b", "c"),
            ["fart", "-a=", "-bc", "-bd"]);
        xassert_eqq(json_encode($arg), '"Unknown option `-d`"');
    }

    function test_getopt_cluster() {
        $getopt = (new Getopt)->long("a", "b", "c:", "long")->interleave(true);
        $arg = self::getopt_parse($getopt, ["fart", "-abab", "-abc=x", "y"]);
        xassert_eqq(json_encode($arg), '{"a":false,"b":false,"c":"x","_":["y"]}');
        $arg = self::getopt_parse($getopt, ["fart", "-abcab", "-ba-long"]);
        xassert_eqq(json_encode($arg), '{"a":false,"b":false,"c":"ab","long":false,"_":[]}');
        $arg = self::getopt_parse($getopt, ["fart", "-abx"]);
        xassert_eqq($arg, "Unknown option `-x`");
        $arg = self::getopt_parse((new Getopt)->long("a", "b")->otheropt(true),
            ["fart", "-abxa"]);
        xassert_eqq(json_encode($arg), '{"a":false,"b":false,"-":["-xa"],"_":[]}');
    }

    #[RequirePhp("8.5")]
    function test_getopt_cluster_complexity() {
        $getopt = (new Getopt)->long("a", "b", "c:", "long")->interleave(true);
        // parsing time is linear in cluster length
        $t0 = microtime(true);
        self::getopt_parse($getopt, ["fart", "-" . str_repeat("a", 25000)]);
        $t1 = microtime(true);
        self::getopt_parse($getopt, ["fart", "-" . str_repeat("a", 400000)]);
        $t2 = microtime(true);
        xassert_lt($t2 - $t1, 64 * ($t1 - $t0) + 0.05);
    }

    function test_getopt_count() {
        $arg = (new Getopt)->short("vV#x")->parse(["fart", "-vVVxV"]);
        xassert_eqq(json_encode($arg), '{"v":false,"V":3,"x":false,"_":[]}');
    }

    function test_getopt_order() {
        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->order(true)
            ->parse(["fart", "-a", "-c", "x", "-cy", "-d=a", "-e", "a", "b", "c"]);
        xassert_eqq(json_encode($arg["__"]), '[["a",false],["c","x"],["c","y"],["d","a"],["e","a"],["e","b"],["e","c"]]');

        $arg = (new Getopt)->short("ab[]c[]d:e[]+")->long("ano")->order(true)->interleave(true)
            ->parse(["fart", "-a", "-c", "x", "pos1", "-d=a", "pos2"]);
        xassert_eqq(json_encode($arg["__"]), '[["a",false],["c","x"],["","pos1"],["d","a"],["","pos2"]]');

        $arg = (new Getopt)->long("a")->otheropt(true)->order(true)
            ->parse(["fart", "-a", "--unknown"]);
        xassert_eqq(json_encode($arg["__"]), '[["a",false],["-","--unknown"]]');

        $arg = (new Getopt)->short("vV#")->order(true)
            ->parse(["fart", "-vVVv"]);
        xassert_eqq(json_encode($arg["__"]), '[["v",false],["V",1],["V",2],["v",false]]');

        // Without order, no __ key
        $arg = (new Getopt)->long("a")->parse(["fart", "-a"]);
        xassert_eqq(array_key_exists("__", $arg), false);
    }

    function test_getopt_subcommand() {
        $getopt = (new Getopt)->subcommand("a", "b")
            ->long("x:,y: {n} !a", "y:,x: {i} !b");

        $arg = self::getopt_parse($getopt, ["fart", "a", "-x", "1"]);
        xassert_eqq(json_encode($arg), '{"_subcommand":"a","x":1,"_":[]}');

        $arg = self::getopt_parse($getopt, ["fart", "a", "-y", "1"]);
        xassert_eqq(json_encode($arg), '{"_subcommand":"a","x":1,"_":[]}');

        $arg = self::getopt_parse($getopt, ["fart", "b", "-x", "-1"]);
        xassert_eqq(json_encode($arg), '{"_subcommand":"b","y":-1,"_":[]}');

        $arg = self::getopt_parse($getopt, ["fart", "b", "-y", "-1"]);
        xassert_eqq(json_encode($arg), '{"_subcommand":"b","y":-1,"_":[]}');
    }

    function test_format_help_line() {
        xassert_eqq(Getopt::format_help_line("  -a, --abc", "Help"),
                    "  -a, --abc               Help\n");
        xassert_eqq(Getopt::format_help_line("  -c, --customer SITECLA", "Help"),
                    "  -c, --customer SITECLA  Help\n");
        xassert_eqq(Getopt::format_help_line("  -c, --customer SITECLAS", "Help"),
                    "  -c, --customer SITECLAS Help\n");
        xassert_eqq(Getopt::format_help_line("  -c, --customer SITECLASS", "Help"),
                    "  -c, --customer SITECLASS\n                          Help\n");
        xassert_eqq(Getopt::format_help_line("  -c, --customer SITECLASS", ""),
                    "  -c, --customer SITECLASS\n");
    }
}
