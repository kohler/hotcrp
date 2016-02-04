<?php
// test02.php -- HotCRP S3 and database unit tests
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");

// S3 unit tests
$s3d = new S3Document(array("key" => "AKIAIOSFODNN7EXAMPLE",
                            "secret" => "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
                            "fixed_time" => gmmktime(0, 0, 0, 5, 24, 2013)));
global $Now;
$Now = gmmktime(0, 0, 0, 5, 24, 2013);

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com/test.txt",
                       array("Range" => "bytes=0-9"));
xassert_eqq($sig["signature"], "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com/test%24file.text",
                       array("x-amz-storage-class" => "REDUCED_REDUNDANCY",
                             "method" => "PUT",
                             "Date" => "Fri, 24 May 2013 00:00:00 GMT"),
                       "Welcome to Amazon S3.");
xassert_eqq($sig["signature"], "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

$sig = $s3d->signature("https://examplebucket.s3.amazonaws.com?lifecycle",
                       array());
xassert_eqq($sig["signature"], "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543");

// Dbl::format_query tests
xassert_eqq(Dbl::format_query("Hello"), "Hello");
xassert_eqq(Dbl::format_query("Hello??"), "Hello?");
xassert_eqq(Dbl::format_query("Hello????"), "Hello??");
xassert_eqq(Dbl::format_query("select ?, ?, ?, ?s, ?s, ?s, ?",
                              1, "a", null, 2, "b", null, 3),
            "select 1, 'a', NULL, 2, b, , 3");
xassert_eqq(Dbl::format_query_apply("select ?, ?, ?, ?s, ?s, ?s, ?",
                                    array(1, "a", null, 2, "b", null, 3)),
            "select 1, 'a', NULL, 2, b, , 3");
xassert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?, ?s, ?s, ?s, ?",
                                    array(1, "a", null, 2, "b", null, 3)),
            "select 'a', 1, NULL, 2, b, , 3");
xassert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?{ab}, ?{2}s, ?{1}s, ?{ab}s, ?",
                                    array(1, "a", "ab" => "Woah", "Leftover")),
            "select 'a', 1, 'Woah', a, 1, Woah, 'Leftover'");

// Csv::split_lines tests
xassert_array_eqq(CsvParser::split_lines(""),
                  array());
xassert_array_eqq(CsvParser::split_lines("\r"),
                  array("\r"));
xassert_array_eqq(CsvParser::split_lines("\n"),
                  array("\n"));
xassert_array_eqq(CsvParser::split_lines("\r\n"),
                  array("\r\n"));
xassert_array_eqq(CsvParser::split_lines("\r\r\n"),
                  array("\r", "\r\n"));
xassert_array_eqq(CsvParser::split_lines("\r\naaa"),
                  array("\r\n", "aaa"));
xassert_array_eqq(CsvParser::split_lines("\na\r\nb\rc\n"),
                  array("\n", "a\r\n", "b\r", "c\n"));

// random PHP behavior tests
xassert_eqq(substr("", 0, 1), false);
$s = "";
xassert_eqq(@$s[0], "");

// Json tests
xassert_eqq(json_encode(Json::decode("{}")), "{}");
xassert_eqq(json_encode(Json::decode('"\\u0030"')), '"0"');
xassert_eqq(Json::encode("\n"), '"\\n"');
xassert_eqq(Json::encode("\007"), '"\\u0007"');
xassert_eqq(json_encode(Json::decode('{"1":"1"}')), '{"1":"1"}');
$x = Json::decode_landmarks('{
    "a": ["b", "c"],
    "b": {
        "c": "d"
    }
}', "x.txt");
xassert_match($x->a[0], ",^x.txt:2(?::|\$),");
xassert_match($x->a[1], ",^x.txt:2(?::|\$),");
xassert_match($x->b->c, ",^x.txt:4(?::|\$),");
xassert_match($x->b->__LANDMARK__, ",^x.txt:3(?::|\$),");

// obscure_time tests
$t = $Conf->parse_time("1 Sep 2010 00:00:01");
$t0 = $Conf->obscure_time($t);
xassert_eqq($Conf->unparse_time_obscure($t0), "1 Sep 2010");
xassert_eqq($Conf->printableTime($t0), "1 Sep 2010 12pm EDT");

$t = $Conf->parse_time("1 Sep 2010 23:59:59");
$t0 = $Conf->obscure_time($t);
xassert_eqq($Conf->unparse_time_obscure($t0), "1 Sep 2010");
xassert_eqq($Conf->printableTime($t0), "1 Sep 2010 12pm EDT");

// review ordinal tests
foreach ([1 => "A", 26 => "Z", 27 => "AA", 28 => "AB", 51 => "AY", 52 => "AZ",
          53 => "BA", 54 => "BB", 702 => "ZZ"] as $n => $t) {
    xassert_eqq(unparseReviewOrdinal($n), $t);
    xassert_eqq(parseReviewOrdinal($t), $n);
}

// ReviewField::make_abbreviation tests
xassert_eqq(ReviewField::make_abbreviation("novelty", 0, 0), "Nov");
xassert_eqq(ReviewField::make_abbreviation("novelty is an amazing", 0, 0), "NovIsAma");
xassert_eqq(ReviewField::make_abbreviation("novelty is an AWESOME", 0, 0), "NovIsAWESOME");
xassert_eqq(ReviewField::make_abbreviation("novelty isn't an AWESOME", 0, 0), "NovIsnAWESOME");
xassert_eqq(ReviewField::make_abbreviation("novelty isn't an AWESOME", 0, 1), "novelty-isnt-awesome");
xassert_eqq(ReviewField::make_abbreviation("_format", 0, 1), "format");

// utf8_word_prefix, etc. tests
xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 7), "aaaaaaa");
xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 8), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 9), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 7), "ááááááá");
xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 8), "áááááááá");
xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 9), "áááááááá");
xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 7), "a̓a̓a̓a̓a̓a̓a̓");
xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 8), "a̓a̓a̓a̓a̓a̓a̓a̓");
xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 9), "a̓a̓a̓a̓a̓a̓a̓a̓");
xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 7), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 8), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 9), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 10), "aaaaaaaa");
xassert_eqq(UnicodeHelper::utf8_glyphlen("aaaaaaaa"), 8);
xassert_eqq(UnicodeHelper::utf8_glyphlen("áááááááá"), 8);
xassert_eqq(UnicodeHelper::utf8_glyphlen("a̓a̓a̓a̓a̓a̓a̓a̓"), 8);

xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 10),
            "+ This is\n- a thing\n- to be\n- wrapped.\n");
xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 9),
            "+ This is\n- a thing\n- to be\n- wrapped.\n");
xassert_eqq(prefix_word_wrap("+ ", "This\nis\na thing\nto\nbe wrapped.", "- ", 9),
            "+ This\n- is\n- a thing\n- to\n- be\n- wrapped.\n");

xassert_eqq(!!preg_match('/\A\pZ\z/u', ' '), true);

// Qobject tests
$q = new Qobject(["a" => 1, "b" => 2]);
xassert_eqq($q->a, 1);
xassert_eqq($q->b, 2);
xassert_eqq(count($q), 2);
xassert_eqq($q->c, null);
xassert_eqq(count($q), 2);
$q->c = array();
xassert_eqq(count($q), 3);
$q->c[] = 1;
xassert_eqq(json_encode($q->c), "[1]");
xassert_eqq(count($q), 3);
xassert_eqq(json_encode($q), "{\"a\":1,\"b\":2,\"c\":[1]}");
xassert_eqq(Json::encode($q), "{\"a\":1,\"b\":2,\"c\":[1]}");

// Contact::is_anonymous_email tests
xassert(Contact::is_anonymous_email("anonymous"));
xassert(Contact::is_anonymous_email("anonymous1"));
xassert(Contact::is_anonymous_email("anonymous10"));
xassert(Contact::is_anonymous_email("anonymous9"));
xassert(!Contact::is_anonymous_email("anonymous@example.com"));
xassert(!Contact::is_anonymous_email("example@anonymous"));

// Mailer::allow_send tests
$Opt["sendEmail"] = true;
xassert(Mailer::allow_send("ass@butt.com"));
xassert(Mailer::allow_send("ass@example.edu"));
xassert(!Mailer::allow_send("ass"));
xassert(!Mailer::allow_send("ass@_.com"));
xassert(!Mailer::allow_send("ass@_.co.uk"));
xassert(!Mailer::allow_send("ass@example.com"));
xassert(!Mailer::allow_send("ass@example.org"));
xassert(!Mailer::allow_send("ass@example.net"));
xassert(!Mailer::allow_send("ass@Example.com"));
xassert(!Mailer::allow_send("ass@Example.ORG"));
xassert(!Mailer::allow_send("ass@Example.net"));
$Opt["sendEmail"] = false;
xassert(!Mailer::allow_send("ass@butt.com"));
xassert(!Mailer::allow_send("ass@example.edu"));

xassert_exit();
