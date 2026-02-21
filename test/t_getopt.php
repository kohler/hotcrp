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
    }

    function test_getopt_count() {
        $arg = (new Getopt)->short("vV#x")->parse(["fart", "-vVVxV"]);
        xassert_eqq(json_encode($arg), '{"v":false,"V":3,"x":false,"_":[]}');
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
}
