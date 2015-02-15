<?php
// test02.php -- HotCRP tests
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/testsetup.php");

$s3d = new S3Document(array("key" => "AKIAIOSFODNN7EXAMPLE",
                            "secret" => "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
                            "fixed_time" => gmmktime(0, 0, 0, 5, 24, 2013)));
global $Now;
$Now = gmmktime(0, 0, 0, 5, 24, 2013);

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com/test.txt",
                       array("Range" => "bytes=0-9"));
assert_eqq($sig["signature"], "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com/test%24file.text",
                       array("x-amz-storage-class" => "REDUCED_REDUNDANCY",
                             "method" => "PUT",
                             "Date" => "Fri, 24 May 2013 00:00:00 GMT"),
                       "Welcome to Amazon S3.");
assert_eqq($sig["signature"], "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com?lifecycle",
                       array());
assert_eqq($sig["signature"], "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543");

// Dbl::format_query tests
assert_eqq(Dbl::format_query("Hello"), "Hello");
assert_eqq(Dbl::format_query("Hello??"), "Hello?");
assert_eqq(Dbl::format_query("Hello????"), "Hello??");
assert_eqq(Dbl::format_query("select ?, ?, ?, ?s, ?s, ?s, ?",
                             1, "a", null, 2, "b", null, 3),
           "select 1, 'a', NULL, 2, b, , 3");
assert_eqq(Dbl::format_query_apply("select ?, ?, ?, ?s, ?s, ?s, ?",
                                   array(1, "a", null, 2, "b", null, 3)),
           "select 1, 'a', NULL, 2, b, , 3");
assert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?, ?s, ?s, ?s, ?",
                                   array(1, "a", null, 2, "b", null, 3)),
           "select 'a', 1, NULL, 2, b, , 3");
assert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?{ab}, ?{2}s, ?{1}s, ?{ab}s, ?",
                                   array(1, "a", "ab" => "Woah", "Leftover")),
           "select 'a', 1, 'Woah', a, 1, Woah, 'Leftover'");

echo "* Tests complete.\n";
