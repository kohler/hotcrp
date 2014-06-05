<?php
// test02.php -- HotCRP tests
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
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
assert($sig["signature"] === "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com/test%24file.text",
                       array("x-amz-storage-class" => "REDUCED_REDUNDANCY",
                             "method" => "PUT",
                             "Date" => "Fri, 24 May 2013 00:00:00 GMT"),
                       "Welcome to Amazon S3.");
assert($sig["signature"] === "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com?lifecycle",
                       array());
assert($sig["signature"] === "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543");

echo "* Tests complete.\n";
