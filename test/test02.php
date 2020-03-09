<?php
// test02.php -- HotCRP S3 and database unit tests
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

global $ConfSitePATH;
$ConfSitePATH = preg_replace(",/[^/]+/[^/]+$,", "", __FILE__);

require_once("$ConfSitePATH/test/setup.php");

// S3 unit tests
$s3d = new S3Document([
    "key" => "AKIAIOSFODNN7EXAMPLE",
    "secret" => "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
    "bucket" => null,
    "fixed_time" => gmmktime(0, 0, 0, 5, 24, 2013)
]);
global $Now;
$Now = gmmktime(0, 0, 0, 5, 24, 2013);

$sig = $s3d->signature("GET",
                       "https://examplebucket.s3.amazonaws.com/test.txt",
                       array("Range" => "bytes=0-9"));
xassert_eqq($sig["signature"], "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

$sig = $s3d->signature("PUT",
                       "https://examplebucket.s3.amazonaws.com/test%24file.text",
                       array("x-amz-storage-class" => "REDUCED_REDUNDANCY",
                             "Date" => "Fri, 24 May 2013 00:00:00 GMT"),
                       "Welcome to Amazon S3.");
xassert_eqq($sig["signature"], "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

$sig = $s3d->signature("GET",
                       "https://examplebucket.s3.amazonaws.com?lifecycle",
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
xassert_eqq(Dbl::format_query("select a?e, b?e, c?e, d?e", null, 1, 2.1, "e"),
            "select a IS NULL, b=1, c=2.1, d='e'");
xassert_eqq(Dbl::format_query("select a?E, b?E, c?E, d?E", null, 1, 2.1, "e"),
            "select a IS NOT NULL, b!=1, c!=2.1, d!='e'");
xassert_eqq(Dbl::format_query("insert ?v", [1, 2, 3]),
            "insert (1), (2), (3)");
xassert_eqq(Dbl::format_query("insert ?v", [[1, null], [2, "A"], ["b", 0.1]]),
            "insert (1,NULL), (2,'A'), ('b',0.1)");

// Dbl::compare_and_swap test
Dbl::qe("insert into Settings set name='cmpxchg', value=1");
xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 1);
xassert_eqq(Dbl::compare_and_swap(Dbl::$default_dblink,
                                  "select value from Settings where name=?", ["cmpxchg"],
                                  function ($x) { return $x + 1; },
                                  "update Settings set value=?{desired} where name=? and value=?{expected}", ["cmpxchg"]),
            2);
xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 2);
xassert_eqq(Dbl::compare_and_swap(Dbl::$default_dblink,
                                  "select value from Settings where name=?", ["cmpxchg"],
                                  function ($x) { return $x + 1; },
                                  "update Settings set value?{desired}e where name=? and value?{expected}e", ["cmpxchg"]),
            3);
xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 3);

// DocumentInfo::update_metadata test
$paper1 = $Conf->fetch_paper(1, $Conf->user_by_email("chair@_.com"));
$doc = $paper1->document(DTYPE_SUBMISSION);
xassert(!!$doc);
xassert_eqq($doc->metadata(), null);
xassert($doc->update_metadata(["hello" => 1]));
xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
            '{"hello":1}');
xassert($doc->update_metadata(["hello" => 2, "foo" => "bar"]));
xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
            '{"hello":2,"foo":"bar"}');
xassert($doc->update_metadata(["hello" => null]));
xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
            '{"foo":"bar"}');
xassert(!$doc->update_metadata(["too_long" => str_repeat("!", 32768)], true));
xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
            '{"foo":"bar"}');

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

// numrangejoin tests
xassert_eqq(numrangejoin([1, 2, 3, 4, 6, 8]), "1–4, 6, and 8");
xassert_eqq(numrangejoin(["#1", "#2", "#3", 4, "xx6", "xx7", 8]), "#1–3, 4, xx6–7, and 8");

// random PHP behavior tests
if (PHP_MAJOR_VERSION >= 7)
    xassert_eqq(substr("", 0, 1), ""); // UGH
else
    xassert_eqq(substr("", 0, 1), false);
$s = "";
xassert_eqq(@$s[0], "");

xassert(str_starts_with("", ""));
xassert(str_starts_with("a", ""));
xassert(str_starts_with("a", "a"));
xassert(!str_starts_with("a", "ab"));
xassert(str_starts_with("abc", "ab"));
xassert(str_ends_with("", ""));
xassert(str_ends_with("a", ""));
xassert(str_ends_with("a", "a"));
xassert(!str_ends_with("a", "ab"));
xassert(str_ends_with("abc", "bc"));
xassert(stri_ends_with("", ""));
xassert(stri_ends_with("a", ""));
xassert(stri_ends_with("a", "A"));
xassert(!stri_ends_with("a", "Ab"));
xassert(stri_ends_with("abc", "Bc"));


// Json tests
xassert_eqq(json_encode(Json::decode("{}")), "{}");
xassert_eqq(json_encode(Json::decode('"\\u0030"')), '"0"');
xassert_eqq(Json::encode("\n"), '"\\n"');
xassert_eqq(Json::encode("\007"), '"\\u0007"');
xassert_eqq(Json::encode("–"), '"–"');
xassert_eqq(Json::decode(Json::encode("–")), "–");
xassert_eqq(Json::decode(Json::encode("\xE2\x80\xA8\xE2\x80\xA9")), "\xE2\x80\xA8\xE2\x80\xA9");
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
xassert_eqq(Json::decode("[1-2]"), null);
xassert_eqq(json_decode("[1-2]"), null);
xassert_eqq(Json::decode("[1,2,3-4,5,6-10,11]"), null);
xassert_eqq(json_decode("[1,2,3-4,5,6-10,11]"), null);

xassert_eqq(json_encode(json_object_replace(null, ["a" => 1])), '{"a":1}');
xassert_eqq(json_encode(json_object_replace(["a" => 1], ["a" => 2])), '{"a":2}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => 2])), '{"a":2}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => null])), '{}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => null], true)), 'null');

$j = json_encode_db("\xE2\x80\xA8");
xassert($j === "\"\xE2\x80\xA8\"" || $j === "\"\\u2028\"");;
xassert_eqq(json_encode_browser("\xE2\x80\xA8"), "\"\\u2028\"");
xassert_eqq(json_encode_db("å"), "\"å\"");
$j = json_encode_browser("å");
xassert($j === "\"\\u00e5\"" || $j === "\"å\"");;
$j = json_encode_db("å\xE2\x80\xA8");
xassert($j === "\"å\xE2\x80\xA8\"" || $j === "\"å\\u2028\"");
$j = json_encode_browser("å\xE2\x80\xA8");
xassert($j === "\"\\u00e5\\u2028\"" || $j === "\"å\\u2028\"");;

// SessionList tests
xassert_eqq(json_encode(SessionList::decode_ids("[1-2]")), "[1,2]");
xassert_eqq(json_encode(SessionList::decode_ids("[1,2,3-4,5,6-10,11]")), "[1,2,3,4,5,6,7,8,9,10,11]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,2]))), "[1,2]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,2,3,4,5,6,7,8,9,10,11]))), "[1,2,3,4,5,6,7,8,9,10,11]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,3,5,7,9,10,11]))), "[1,3,5,7,9,10,11]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([11,10,9,8,7,6,5,4,3,2,1]))), "[11,10,9,8,7,6,5,4,3,2,1]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([10,9,7,1,3,5,5]))), "[10,9,7,1,3,5,5]");

function random_paper_ids() {
    $a = [];
    $n = mt_rand(1, 10);
    $p = null;
    for ($i = 0; $i < $n; ++$i) {
        $p1 = mt_rand(1, $p === null ? 100 : 150);
        if ($p1 > 100)
            $p1 = max(1, $p + (int) round(($p1 - 125) / 8));
        $p2 = 20 - (int) sqrt(mt_rand(0, 399));
        if (mt_rand(1, 4) === 1) {
            for ($p = $p1; $p >= 1 && $p > $p1 - $p2; --$p)
                $a[] = $p;
        } else {
            for ($p = $p1; $p < $p1 + $p2; ++$p)
                $a[] = $p;
        }
    }
    return $a;
}
for ($i = 0; $i < 1000; ++$i) {
    $ids = random_paper_ids();
    //file_put_contents("/tmp/x", "if (JSON.stringify(decode_ids(" . json_encode(SessionList::encode_ids($ids)) . ")) !== " . json_encode(json_encode($ids)) . ") throw new Error;\n", FILE_APPEND);
    xassert_eqq(SessionList::decode_ids(SessionList::encode_ids($ids)), $ids);
}

// obscure_time tests
$t = $Conf->parse_time("1 Sep 2010 00:00:01");
$t0 = $Conf->obscure_time($t);
xassert_eqq($Conf->unparse_time_obscure($t0), "1 Sep 2010");
xassert_eqq($Conf->unparse_time($t0), "1 Sep 2010 12pm EDT");

$t = $Conf->parse_time("1 Sep 2010 23:59:59");
$t0 = $Conf->obscure_time($t);
xassert_eqq($Conf->unparse_time_obscure($t0), "1 Sep 2010");
xassert_eqq($Conf->unparse_time($t0), "1 Sep 2010 12pm EDT");

// timezone tests
xassert_eqq(true === "obscure", false);
$t = $Conf->parse_time("29 May 2018 11:00:00 EDT");
xassert_eqq($t, 1527606000);
$t = $Conf->parse_time("29 May 2018 03:00:00 AoE");
xassert_eqq($t, 1527606000);
$Conf->set_opt("timezone", "Etc/GMT+12");
$Conf->crosscheck_options();
$t = $Conf->parse_time("29 May 2018 03:00:00");
xassert_eqq($t, 1527606000);
$t = $Conf->unparse_time(1527606000);
xassert_eqq($t, "29 May 2018 3am AoE");
$t = $Conf->parse_time("29 May 2018 23:59:59 AoE");
xassert_eqq($t, 1527681599);
$t = $Conf->parse_time("29 May 2018 AoE");
xassert_eqq($t, 1527681599);
$t = $Conf->parse_time("29 May 2018 12am AoE");
xassert_eqq($t, 1527595200);
$t = $Conf->parse_time("29 May AoE", 1527606000);
xassert_eqq($t, 1527681599);

// review ordinal tests
foreach ([1 => "A", 26 => "Z", 27 => "AA", 28 => "AB", 51 => "AY", 52 => "AZ",
          53 => "BA", 54 => "BB", 702 => "ZZ", 703 => "AAA", 704 => "AAB",
          1378 => "AZZ", 1379 => "BAA"] as $n => $t) {
    xassert_eqq(unparseReviewOrdinal($n), $t);
    xassert_eqq(parseReviewOrdinal($t), $n);
}

// interval tests
xassert_eqq(SettingParser::parse_interval("2y"), 86400 * 365 * 2);
xassert_eqq(SettingParser::parse_interval("15m"), 60 * 15);
xassert_eqq(SettingParser::parse_interval("1h15m"), 60 * 75);
xassert_eqq(SettingParser::parse_interval("1h15mo"), false);

// preference tests
xassert_eqq(Preference_AssignmentParser::parse("--2"), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse("--3 "), [-3, null]);
xassert_eqq(Preference_AssignmentParser::parse("\"--2\""), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse("\"-2-\""), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse("`-2-`"), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse(" - 2"), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse(" – 2"), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse(" — 2"), [-2, null]);
xassert_eqq(Preference_AssignmentParser::parse(" — 2--"), null);
xassert_eqq(Preference_AssignmentParser::parse("+0.2"), [0, null]);
xassert_eqq(Preference_AssignmentParser::parse("-2x"), [-2, 1]);
xassert_eqq(Preference_AssignmentParser::parse("-2     Y"), [-2, 0]);
xassert_eqq(Preference_AssignmentParser::parse("- - - -Y"), null);
xassert_eqq(Preference_AssignmentParser::parse("- - - -"), [-4, null]);
xassert_eqq(Preference_AssignmentParser::parse("++"), [2, null]);
xassert_eqq(Preference_AssignmentParser::parse("+ 2+"), [2, null]);
xassert_eqq(Preference_AssignmentParser::parse("xsaonaif"), null);
xassert_eqq(Preference_AssignmentParser::parse("NONE"), [0, null]);
xassert_eqq(Preference_AssignmentParser::parse("CONFLICT"), [-100, null]);

// AbbreviationMatcher::make_abbreviation tests
xassert_eqq(AbbreviationMatcher::make_abbreviation("novelty", new AbbreviationClass), "Nov");
xassert_eqq(AbbreviationMatcher::make_abbreviation("novelty is an amazing", new AbbreviationClass), "NovIsAma");
xassert_eqq(AbbreviationMatcher::make_abbreviation("novelty is an AWESOME", new AbbreviationClass), "NovIsAWESOME");
xassert_eqq(AbbreviationMatcher::make_abbreviation("novelty isn't an AWESOME", new AbbreviationClass), "NovIsnAWESOME");
$aclass = new AbbreviationClass;
$aclass->type = AbbreviationClass::TYPE_LOWERDASH;
xassert_eqq(AbbreviationMatcher::make_abbreviation("novelty isn't an AWESOME", $aclass), "novelty-isnt-awesome");
xassert_eqq(AbbreviationMatcher::make_abbreviation("_format", $aclass), "format");

// simplify_whitespace
xassert_eqq(simplify_whitespace("abc def GEH îjk"), "abc def GEH îjk");
xassert_eqq(simplify_whitespace("\x7Fabc\x7Fdef       GEH îjk"), "abc def GEH îjk");
xassert_eqq(simplify_whitespace("A.\n\n\x1DEEE MM\n\n\n\n"), "A. EEE MM");

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
xassert_eqq(UnicodeHelper::utf8_word_prefix("\xCC\x90_\xCC\x8E", 1), "\xCC\x90_\xCC\x8E");
xassert_eqq(UnicodeHelper::utf8_word_prefix("\xCC\x90_ \xCC\x8E", 1), "\xCC\x90_");
xassert_eqq(UnicodeHelper::utf8_glyphlen("aaaaaaaa"), 8);
xassert_eqq(UnicodeHelper::utf8_glyphlen("áááááááá"), 8);
xassert_eqq(UnicodeHelper::utf8_glyphlen("a̓a̓a̓a̓a̓a̓a̓a̓"), 8);

// mojibake
xassert_eqq(UnicodeHelper::demojibake("å"), "å");
xassert_eqq(UnicodeHelper::demojibake("Â£"), "£");
xassert_eqq(UnicodeHelper::demojibake("Â£"), "£");
xassert_eqq(UnicodeHelper::demojibake("LÃ¡szlÃ³ MolnÃ¡r"), "László Molnár");
xassert_eqq(UnicodeHelper::demojibake("László Molnár"), "László Molnár");

// utf8 cleanup
xassert_eqq(UnicodeHelper::utf8_truncate_invalid(""), "");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("abc"), "abc");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("\x80bc"), "\x80bc");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xc3\xa5"), "abå");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xc3"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xa5"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xe4\xba\x9c"), "ab亜");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xe4\xba"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xe4"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xf0\x9d\x84\x9e"), "ab𝄞");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xf0\x9d\x84"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xf0\x9d"), "ab");
xassert_eqq(UnicodeHelper::utf8_truncate_invalid("ab\xf0"), "ab");

xassert_eqq(UnicodeHelper::utf8_replace_invalid(""), "");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("abc"), "abc");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("\x80bc"), "\x7fbc");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xc3\xa5"), "abå");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xc3"), "ab\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xa5"), "ab\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab"), "ab");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xe4\xba\x9c"), "ab亜");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xe4\xba"), "ab\x7f\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xe4"), "ab\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xf0\x9d\x84\x9e"), "ab𝄞");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xf0\x9d\x84"), "ab\x7f\x7f\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xf0\x9d"), "ab\x7f\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xf0"), "ab\x7f");
xassert_eqq(UnicodeHelper::utf8_replace_invalid("ab\xf0å"), "ab\x7få");

xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 10),
            "+ This is\n- a thing\n- to be\n- wrapped.\n");
xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 9),
            "+ This is\n- a thing\n- to be\n- wrapped.\n");
xassert_eqq(prefix_word_wrap("+ ", "This\nis\na thing\nto\nbe wrapped.", "- ", 9),
            "+ This\n- is\n- a thing\n- to\n- be\n- wrapped.\n");

xassert_eqq(!!preg_match('/\A\pZ\z/u', ' '), true);

// deaccent tests
xassert_eqq(UnicodeHelper::deaccent("Á é î ç ø U"), "A e i c o U");
$do = UnicodeHelper::deaccent_offsets("Á é î ç ø U .\xE2\x84\xAA");
xassert_eqq($do[0], "A e i c o U .K");
xassert_eqq(json_encode($do[1]), "[[0,0],[1,2],[3,5],[5,8],[7,11],[9,14],[14,21]]");
$regex = (object) ["preg_raw" => Text::word_regex("foo"), "preg_utf8" => Text::utf8_word_regex("foo")];
xassert_eqq(Text::highlight("Is foo bar føo bar fóó bar highlit right? foö", $regex),
            "Is <span class=\"match\">foo</span> bar <span class=\"match\">føo</span> bar <span class=\"match\">fóó</span> bar highlit right? <span class=\"match\">foö</span>");
xassert_eqq(UnicodeHelper::remove_f_ligatures("Héllo ﬀ,ﬁ:fi;ﬂ,ﬃ:ﬄ-ﬅ"), "Héllo ff,fi:fi;fl,ffi:ffl-ﬅ");

// match_pregexes tests
$pregex = Text::star_text_pregexes("foo");
xassert(Text::match_pregexes($pregex, "foo", false));
xassert(Text::match_pregexes($pregex, "foo", "foo"));
xassert(Text::match_pregexes($pregex, "fóo", "foo"));
xassert(!Text::match_pregexes($pregex, "foobar", false));
xassert(!Text::match_pregexes($pregex, "foobar", "foobar"));
xassert(!Text::match_pregexes($pregex, "fóobar", "foobar"));

$pregex = Text::star_text_pregexes("foo*");
xassert(Text::match_pregexes($pregex, "foo", false));
xassert(Text::match_pregexes($pregex, "foo", "foo"));
xassert(Text::match_pregexes($pregex, "fóo", "foo"));
xassert(Text::match_pregexes($pregex, "foobar", false));
xassert(Text::match_pregexes($pregex, "foobar", "foobar"));
xassert(Text::match_pregexes($pregex, "fóobar", "foobar"));
xassert(!Text::match_pregexes($pregex, "ffoobar", false));
xassert(!Text::match_pregexes($pregex, "ffoobar", "ffoobar"));
xassert(!Text::match_pregexes($pregex, "ffóobar", "ffoobar"));

$pregex = Text::star_text_pregexes("foo@butt.com");
xassert(Text::match_pregexes($pregex, "it's foo@butt.com and friends", false));
xassert(Text::match_pregexes($pregex, "it's foo@butt.com and friends", "it's foo@butt.com and friends"));
xassert(Text::match_pregexes($pregex, "it's fóo@butt.com and friends", "it's foo@butt.com and friends"));

// CountMatcher tests
xassert_eqq(CountMatcher::filter_using([0, 1, 2, 3], ">0"), [1 => 1, 2 => 2, 3 => 3]);
xassert_eqq(CountMatcher::filter_using([3, 2, 1, 0], [1]), [2 => 1]);
xassert_eqq(CountMatcher::filter_using([10, 11, -10], "≤10"), [0 => 10, 2 => -10]);

// simple_search tests
xassert_eqq(Text::simple_search("yes", ["yes", "no", "yes-really"]), ["yes"]);
xassert_eqq(Text::simple_search("yes", ["yes", "no", "yes-really"], Text::SEARCH_UNPRIVILEGE_EXACT), ["yes", 2 => "yes-really"]);
xassert_eqq(Text::simple_search("yes", ["yes-maybe", "no", "yes-really"], 0), ["yes-maybe", 2 => "yes-really"]);
xassert_eqq(Text::simple_search("Yes", ["yes", "no", "yes-really"]), ["yes"]);
xassert_eqq(Text::simple_search("Yes", ["yes", "no", "yes-really"], Text::SEARCH_UNPRIVILEGE_EXACT), ["yes", 2 => "yes-really"]);
xassert_eqq(Text::simple_search("Yes", ["yes-maybe", "no", "yes-really"], 0), ["yes-maybe", 2 => "yes-really"]);

$opts = ["x" => "None", "a" => "ACM badges: available", "af" => "ACM badges: available, functional", "afr" => "ACM badges: available, functional, replicated", "ar" => "ACM badges: available, reusable", "arr" => "ACM badges: available, reusable, replicated", "f" => "ACM badges: functional", "fr" => "ACM badges: functional, replicated", "r" => "ACM badges: reusable", "rr" => "ACM badges: reusable, replicated"];
xassert_eqq(array_keys(Text::simple_search("ACM badges: available, functional, replicated", $opts)), ["afr"]);
xassert_eqq(array_keys(Text::simple_search("ACM badges: functional, replicated", $opts)), ["fr"]);
xassert_eqq(array_keys(Text::simple_search("ACM badges: available, functional, replicated", $opts, Text::SEARCH_UNPRIVILEGE_EXACT)), ["afr"]);
xassert_eqq(array_keys(Text::simple_search("ACM badges: functional, replicated", $opts, Text::SEARCH_UNPRIVILEGE_EXACT)), ["afr", "fr"]);

$am = new AbbreviationMatcher;
foreach ($opts as $d => $dname)
    $am->add($dname, $d);
xassert_eqq($am->find_all("ACM badges: available, functional, replicated"), ["afr"]);
xassert_eqq($am->find_all("ACM badges: functional, replicated"), ["fr"]);
xassert_eqq($am->find_all("available"), ["a", "af", "afr", "ar", "arr"]);
xassert_eqq($am->find_all("ACM badges: available"), ["a"]);
xassert_eqq($am->find_all("acm-badges-available"), ["a"]);
xassert_eqq($am->find_all("ACMBadAva"), ["a"]);
xassert_eqq($am->find_all("ava"), ["a", "af", "afr", "ar", "arr"]);

// Qrequest tests
$q = new Qrequest("GET", ["a" => 1, "b" => 2]);
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
$Conf->set_opt("sendEmail", true);
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
$Conf->set_opt("sendEmail", false);
xassert(!Mailer::allow_send("ass@butt.com"));
xassert(!Mailer::allow_send("ass@example.edu"));

// NavigationState tests
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

// Test PHP_SUFFIX override
$ns = new NavigationState(["SERVER_PORT" => 80, "SCRIPT_FILENAME" => __FILE__,
                           "SCRIPT_NAME" => __FILE__, "REQUEST_URI" => "/fart/barf/?butt",
                           "HTTP_HOST" => "butt.com", "SERVER_SOFTWARE" => "Apache 2.4",
                           "HOTCRP_PHP_SUFFIX" => ".xxx"]);
xassert_eqq($ns->php_suffix, ".xxx");

// other helpers
xassert_eqq(ini_get_bytes(null, "1"), 1);
xassert_eqq(ini_get_bytes(null, "1 M"), 1 * (1 << 20));
xassert_eqq(ini_get_bytes(null, "1.2k"), 1229);
xassert_eqq(ini_get_bytes(null, "20G"), 20 * (1 << 30));

// name splitting
xassert_eqq(get(Text::split_name("Bob Kennedy"), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy"), 1), "Kennedy");
xassert_eqq(get(Text::split_name("Bob Kennedy (Butt Pants)"), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy (Butt Pants)"), 1), "Kennedy (Butt Pants)");
xassert_eqq(get(Text::split_name("Bob Kennedy, Esq."), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy, Esq."), 1), "Kennedy, Esq.");
xassert_eqq(get(Text::split_name("Bob Kennedy, Esq. (Butt Pants)"), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy, Esq. (Butt Pants)"), 1), "Kennedy, Esq. (Butt Pants)");
xassert_eqq(get(Text::split_name("Bob Kennedy, Jr., Esq."), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy, Jr., Esq."), 1), "Kennedy, Jr., Esq.");
xassert_eqq(get(Text::split_name("Bob Kennedy, Jr., Esq. (Butt Pants)"), 0), "Bob");
xassert_eqq(get(Text::split_name("Bob Kennedy, Jr., Esq. (Butt Pants)"), 1), "Kennedy, Jr., Esq. (Butt Pants)");
xassert_eqq(get(Text::split_name("Kennedy, Bob, Jr., Esq."), 0), "Bob");
xassert_eqq(get(Text::split_name("Kennedy, Bob, Jr., Esq."), 1), "Kennedy, Jr., Esq.");
xassert_eqq(get(Text::split_name("Kennedy, Bob, Jr., Esq. (Butt Pants)"), 0), "Bob");
xassert_eqq(get(Text::split_name("Kennedy, Bob, Jr., Esq. (Butt Pants)"), 1), "Kennedy, Jr., Esq. (Butt Pants)");
xassert_eqq(get(Text::split_name("Kennedy, Bob"), 0), "Bob");
xassert_eqq(get(Text::split_name("Kennedy, Bob"), 1), "Kennedy");
xassert_eqq(get(Text::split_name("Kennedy, Bob (Butt Pants)"), 0), "Bob (Butt Pants)");
xassert_eqq(get(Text::split_name("Kennedy, Bob (Butt Pants)"), 1), "Kennedy");
xassert_eqq(get(Text::split_name("Claire Le Goues"), 1), "Le Goues");
xassert_eqq(get(Text::split_name("Claire Von La Le Goues"), 1), "Von La Le Goues");
xassert_eqq(get(Text::split_name("CLAIRE VON LA LE GOUES"), 1), "VON LA LE GOUES");
xassert_eqq(get(Text::split_name("C. Von La Le Goues"), 1), "Von La Le Goues");
xassert_eqq(Text::analyze_von("Von Le Goues"), null);
xassert_eqq(Text::analyze_von("von le Goues"), ["von le", "Goues"]);
xassert_eqq(get(Text::split_name("Brivaldo Junior"), 0), "Brivaldo");
xassert_eqq(get(Text::split_name("Brivaldo Junior"), 1), "Junior");

// author matching
$aum = AuthorMatcher::make_string_guess("ETH Zürich");
xassert_eqq(!!$aum->test("Butt (ETH Zürich)"), true);
xassert_eqq(!!$aum->test("Butt (University of Zürich)"), false);
xassert_eqq(!!$aum->test("Butt (ETHZ)"), true);
$aum = AuthorMatcher::make_string_guess("Massachusetts Institute of Technology");
xassert_eqq(!!$aum->test("Butt (Massachusetts Institute of Technology)"), true);
xassert_eqq(!!$aum->test("Butt (MIT)"), true);
xassert_eqq(!!$aum->test("Butt (M.I.T.)"), true);
xassert_eqq(!!$aum->test("Butt (Indian Institute of Technology)"), false);
xassert_eqq(!!$aum->test("Butt (Institute of Technology)"), false);
$aum = AuthorMatcher::make_string_guess("M.I.T.");
xassert_eqq(!!$aum->test("Butt (Massachusetts Institute of Technology)"), true);
xassert_eqq(!!$aum->test("Butt (MIT)"), true);
xassert_eqq(!!$aum->test("Butt (M.I.T.)"), true);
xassert_eqq(!!$aum->test("Butt (Indian Institute of Technology)"), false);
xassert_eqq(!!$aum->test("Butt (Institute of Technology)"), false);
$aum = AuthorMatcher::make_string_guess("Indian Institute of Science");
xassert_eqq(!!$aum->test("Butt (Institute of Technology)"), false);
xassert_eqq(!!$aum->test("Butt (Indian Institute of Technology)"), false);
xassert_eqq(!!$aum->test("Butt (Indian Institute of Science)"), true);
$aum = AuthorMatcher::make_string_guess("D. Thin (Captain Poop)");
xassert_eqq(!!$aum->test("D. Thin"), true);
xassert_eqq(!!$aum->test("D.X. Thin"), true);
xassert_eqq(!!$aum->test("D. X. Thin"), true);
xassert_eqq(!!$aum->test("X.D. Thin"), true);
xassert_eqq(!!$aum->test("X. D. Thin"), true);
xassert_eqq(!!$aum->test("Xavier Thin"), false);
xassert_eqq(!!$aum->test("Daniel Thin"), true);
xassert_eqq(!!$aum->test("Daniel Thin", true), true);
xassert_eqq(!!$aum->test("Daniel X. Thin"), true);
xassert_eqq(!!$aum->test("Daniel X. Thin (Lieutenant)"), true);
xassert_eqq(!!$aum->test("Daniel X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("D. X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("D. X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("Someone Else (Captain Poop)"), true);
xassert_eqq(!!$aum->test("Someone Else (Captain Poop)", true), false);
$aum = AuthorMatcher::make_string_guess("Daniel Thin (Captain Poop)");
xassert_eqq(!!$aum->test("D. Thin"), true);
xassert_eqq(!!$aum->test("D.X. Thin"), true);
xassert_eqq(!!$aum->test("D. X. Thin"), true);
xassert_eqq(!!$aum->test("X.D. Thin"), true);
xassert_eqq(!!$aum->test("X. D. Thin"), true);
xassert_eqq(!!$aum->test("X. D. Thin", true), true);
xassert_eqq(!!$aum->test("Xavier Thin"), false);
xassert_eqq(!!$aum->test("Daniel Thin"), true);
xassert_eqq(!!$aum->test("Daniel X. Thin"), true);
xassert_eqq(!!$aum->test("Daniel X. Thin (Lieutenant)"), true);
xassert_eqq(!!$aum->test("Daniel X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("D. X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("D. X. Think (Lieutenant)"), false);
xassert_eqq(!!$aum->test("Someone Else (Captain Poop)"), true);
xassert_eqq(!!$aum->test("Someone Else (Captain Poop)", true), false);
$aum = AuthorMatcher::make_string_guess("Stephen J. Pink");
xassert_eqq(!!$aum->test("IBM T. J. Watson Research Center"), false);
$aum = AuthorMatcher::make_string_guess("IBM Watson");
xassert_eqq(!!$aum->test("Fart (IBM Watson)"), true);
xassert_eqq(!!$aum->test("Fart (IBM T. J. Watson Research Center)"), true);
$aum = AuthorMatcher::make_string_guess("IBM T. J. Watson Research Center");
xassert_eqq(!!$aum->test("Fart (IBM Watson)"), true);
xassert_eqq(!!$aum->test("Fart (IBM T. J. Watson Research Center)"), true);
$aum = AuthorMatcher::make_string_guess("UCSD");
xassert_eqq(!!$aum->test("Butt (UCSD)"), true);
xassert_eqq(!!$aum->test("Butt (UCSB)"), false);
xassert_eqq(!!$aum->test("Butt (University of California San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California, San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
$aum = AuthorMatcher::make_string_guess("UC San Diego");
xassert_eqq(!!$aum->test("Butt (UCSD)"), true);
xassert_eqq(!!$aum->test("Butt (UCSB)"), false);
xassert_eqq(!!$aum->test("Butt (University of California San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California, San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
$aum = AuthorMatcher::make_string_guess("University of California San Diego");
xassert_eqq(!!$aum->test("Butt (UCSD)"), true);
xassert_eqq(!!$aum->test("Butt (UCSB)"), false);
xassert_eqq(!!$aum->test("Butt (University of California San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California, San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (University of California Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC Santa Barbara)"), false);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
xassert_eqq(!!$aum->test("Butt (UC San Diego)"), true);
$aum = AuthorMatcher::make_string_guess("UT Austin");
xassert_eqq(!!$aum->test("Sepideh Maleki (Texas State University)"), false);
xassert_eqq(!!$aum->test("Sepideh Maleki (University of Texas at Austin)"), true);
$aum = AuthorMatcher::make_string_guess("University of Pennsylvania");
xassert_eqq(!!$aum->test("Sepideh Maleki (Penn State)"), false);
xassert_eqq(!!$aum->test("Sepideh Maleki (Pennsylvania State University)"), false);
xassert_eqq(!!$aum->test("Sepideh Maleki (UPenn)"), true);
xassert_eqq(!!$aum->test("Sepideh Maleki (University of Pennsylvania)"), true);
$aum = AuthorMatcher::make_string_guess("UW");
xassert_eqq(!!$aum->test("Ana Stackelberg (University of Wisconsin—Madison)"), false);
xassert_eqq(!!$aum->test("Ana Stackelberg (University of Washington)"), true);
$aum = AuthorMatcher::make_string_guess("UW Madison");
xassert_eqq(!!$aum->test("Ana Stackelberg (University of Wisconsin—Madison)"), true);
xassert_eqq(!!$aum->test("Ana Stackelberg (University of Washington)"), false);
$aum = AuthorMatcher::make_string_guess("Chinese University of Hong Kong");
xassert_eqq(!!$aum->test("CUHK"), true);
xassert_eqq(!!$aum->test("Chinese University of Hong Kong"), true);
xassert_eqq(!!$aum->test("UHK"), false);
xassert_eqq(!!$aum->test("University of Hong Kong"), false);
xassert_eqq(!!$aum->test("HKUST"), false);
xassert_eqq(!!$aum->test("Hong Kong University of Science & Technology"), false);
$aum = AuthorMatcher::make_string_guess("University of Hong Kong");
xassert_eqq(!!$aum->test("CUHK"), false);
xassert_eqq(!!$aum->test("Chinese University of Hong Kong"), false);
xassert_eqq(!!$aum->test("UHK"), true);
xassert_eqq(!!$aum->test("University of Hong Kong"), true);
xassert_eqq(!!$aum->test("HKUST"), false);
xassert_eqq(!!$aum->test("Hong Kong University of Science & Technology"), false);
$aum = AuthorMatcher::make_string_guess("Hong Kong University of Science & Technology");
xassert_eqq(!!$aum->test("CUHK"), false);
xassert_eqq(!!$aum->test("Chinese University of Hong Kong"), false);
xassert_eqq(!!$aum->test("UHK"), false);
xassert_eqq(!!$aum->test("University of Hong Kong"), false);
xassert_eqq(!!$aum->test("HKUST"), true);
xassert_eqq(!!$aum->test("Hong Kong University of Science & Technology"), true);
$aum = AuthorMatcher::make_string_guess("All (UMass)");
xassert_eqq(!!$aum->test("Bobby (University of Massachusetts)", false), true);
xassert_eqq(!!$aum->test("Bobby (University of Massachusetts)", true), true);
$aum = AuthorMatcher::make_string_guess("Bobby (UMass)");
xassert_eqq(!!$aum->test("All (University of Massachusetts)", false), true);
xassert_eqq(!!$aum->test("All (University of Massachusetts)", true), true);
$aum = AuthorMatcher::make_string_guess("Bobby (UIUC)");
xassert_eqq(!!$aum->test("All (University of Illinois Chicago)", false), false);
xassert_eqq(!!$aum->test("All (University of Illinois Chicago)", true), false);
xassert_eqq(!!$aum->test("All (University of Illinois)", false), true);
xassert_eqq(!!$aum->test("All (University of Illinois)", true), true);
$aum = AuthorMatcher::make_string_guess("Bobby (University of Illinois Chicago)");
xassert_eqq(!!$aum->test("All (UIUC)", false), false);
xassert_eqq(!!$aum->test("All (UIUC)", true), false);
//xassert_eqq(!!$aum->test("All (University of Illinois)", false), true);
//xassert_eqq(!!$aum->test("All (University of Illinois)", true), true);
xassert_eqq(!!$aum->test("All (University of Illinois Chicago)", false), true);
xassert_eqq(!!$aum->test("All (University of Illinois Chicago)", true), true);

// i18n messages
$ms = new IntlMsgSet;
$ms->add("Hello", "Bonjour");
$ms->add(["%d friend", "%d amis", ["$1 ≠ 1"]]);
$ms->add("%d friend", "%d ami");
$ms->add("ax", "a");
$ms->add("ax", "b");
$ms->add("bx", "a", 2);
$ms->add("bx", "b");
$ms->add(["fart", "fart example A", ["$1=bob"]]);
$ms->add(["fart", "fart example B", ["$1^=bob"]]);
$ms->add(["fart", "fart example C"]);
$ms->add(["itext" => "fox", "otext" => "%1\$s", "format" => "expand", "context" => "fox-saying", "template" => true]);
$ms->add(["id" => "fox-saying", "itext" => "What the fox said"]);
$ms->add(["id" => "fox-saying", "itext" => "What the %FOX% said", "require" => ["$1"]]);
$ms->add(["itext" => "butt", "otext" => "%1\$s", "format" => "expand", "context" => "test103", "template" => true]);
$ms->add_override("test103", "%BUTT% %% %s %BU%%MAN%%BUTT%");
xassert_eqq($ms->x("Hello"), "Bonjour");
xassert_eqq($ms->x("%d friend", 1), "1 ami");
xassert_eqq($ms->x("%d friend", 0), "0 amis");
xassert_eqq($ms->x("%d friend", 2), "2 amis");
xassert_eqq($ms->x("%1[foo]\$s friend", ["foo" => 3]), "3 friend");
xassert_eqq($ms->x("ax"), "b");
xassert_eqq($ms->x("bx"), "a");
xassert_eqq($ms->x("%xOOB%x friend", 10, 11), "aOOBb friend");
xassert_eqq($ms->x("%xOOB%x%% friend", 10, 11), "aOOBb% friend");
xassert_eqq($ms->x("fart"), "fart example C");
xassert_eqq($ms->x("fart", "bobby"), "fart example B");
xassert_eqq($ms->x("fart", "bob"), "fart example A");
xassert_eqq($ms->xi("fox-saying"), "What the fox said");
xassert_eqq($ms->xi("fox-saying", false, "Animal"), "What the Animal said");
xassert_eqq($ms->xi("test103", false, "Ass"), "Ass %% %s %BU%%MAN%Ass");

$ms->add(["itext" => "butt", "otext" => "normal butt"]);
$ms->add(["itext" => "butt", "otext" => "fat butt", "require" => ["$1[fat]"]]);
$ms->add(["itext" => "butt", "otext" => "two butts", "require" => ["$1[count]>1"], "priority" => 1]);
$ms->add(["itext" => "butt", "otext" => "three butts", "require" => ["$1[count]>2"], "priority" => 2]);
xassert_eqq($ms->x("butt"), "normal butt");
xassert_eqq($ms->x("butt", []), "normal butt");
xassert_eqq($ms->x("butt", ["thin" => true]), "normal butt");
xassert_eqq($ms->x("butt", ["fat" => true]), "fat butt");
xassert_eqq($ms->x("butt", ["fat" => false]), "normal butt");
xassert_eqq($ms->x("butt", ["fat" => true, "count" => 2]), "two butts");
xassert_eqq($ms->x("butt", ["fat" => false, "count" => 2]), "two butts");
xassert_eqq($ms->x("butt", ["fat" => true, "count" => 3]), "three butts");
xassert_eqq($ms->x("butt", ["fat" => false, "count" => 2.1]), "three butts");

// i18n messages with contexts
$ms = new IntlMsgSet;
$ms->add("Hello", "Hello");
$ms->add(["hello", "Hello", "Hello1"]);
$ms->add(["hello/yes", "Hello", "Hello2"]);
$ms->add(["hellop", "Hello", "Hellop", 2]);
xassert_eqq($ms->xc(null, "Hello"), "Hello");
xassert_eqq($ms->xc("hello", "Hello"), "Hello1");
xassert_eqq($ms->xc("hello/no", "Hello"), "Hello1");
xassert_eqq($ms->xc("hello/yes", "Hello"), "Hello2");
xassert_eqq($ms->xc("hello/yes/whatever", "Hello"), "Hello2");
xassert_eqq($ms->xc("hello/ye", "Hello"), "Hello1");
xassert_eqq($ms->xc("hello/yesp", "Hello"), "Hello1");

// MIME types
xassert_eqq(Mimetype::content_type("%PDF-3.0\nwhatever\n"), Mimetype::PDF_TYPE);
// test that we can parse lib/mime.types for file extensions
xassert_eqq(Mimetype::extension("application/pdf"), ".pdf");
xassert_eqq(Mimetype::extension("image/gif"), ".gif");
xassert_eqq(Mimetype::content_type(null, "application/force"), "application/octet-stream");
xassert_eqq(Mimetype::content_type(null, "application/x-zip-compressed"), "application/zip");
xassert_eqq(Mimetype::content_type(null, "application/gz"), "application/gzip");
xassert_eqq(Mimetype::extension("application/g-zip"), ".gz");
xassert_eqq(Mimetype::type("application/download"), "application/octet-stream");
xassert_eqq(Mimetype::extension("application/smil"), ".smil");
xassert_eqq(Mimetype::type(".smil"), "application/smil");
xassert_eqq(Mimetype::type(".sml"), "application/smil");
// `fileinfo` test
xassert_eqq(Mimetype::content_type("<html><head></head><body></body></html>"), "text/html");

// score sorting
$s = [];
foreach (["1,2,3,4,5", "1,2,3,5,5", "3,5,5", "3,3,5,5", "2,3,3,5,5"] as $st)
    $s[] = new ScoreInfo($st);
xassert($s[0]->compare_by($s[0], "C") == 0);
xassert($s[0]->compare_by($s[1], "C") < 0);
xassert($s[0]->compare_by($s[2], "C") < 0);
xassert($s[0]->compare_by($s[3], "C") < 0);
xassert($s[1]->compare_by($s[1], "C") == 0);
xassert($s[1]->compare_by($s[2], "C") < 0);
xassert($s[1]->compare_by($s[3], "C") < 0);
xassert($s[2]->compare_by($s[2], "C") == 0);
xassert($s[2]->compare_by($s[3], "C") < 0);
xassert($s[3]->compare_by($s[0], "C") > 0);
xassert($s[3]->compare_by($s[1], "C") > 0);
xassert($s[3]->compare_by($s[2], "C") > 0);
xassert($s[3]->compare_by($s[3], "C") == 0);
xassert($s[3]->compare_by($s[4], "C") > 0);

// AbbreviationMatcher
$am = new AbbreviationMatcher;
$am->add("élan", 1, 1);
$am->add("eclat", 2);
$am->add("Should the PC Suck?", 3);
$am->add("Should P. C. Rock?", 4);
xassert_eqq($am->find_all("elan"), [1]);
xassert_eqq($am->find_all("el"), [1]);
xassert_eqq($am->find_all("él"), [1]);
xassert_eqq($am->find_all("ÉL"), [1]);
xassert_eqq($am->find_all("e"), [1, 2]);
xassert_eqq($am->find_all("ecla"), [2]);
xassert_eqq($am->find_all("should-the-pc-suck"), [3]);
xassert_eqq($am->find_all("should-the pc-suck"), [3]);
xassert_eqq($am->find_all("ShoPCSuc"), [3]);
xassert_eqq($am->find_all("ShoPCRoc"), [4]);
$am->add("élan", 5, 2);
xassert_eqq($am->find_all("elan"), [1, 5]);
xassert_eqq($am->find_all("elan", 1), [1]);
xassert_eqq($am->find_all("elan", 2), [5]);
xassert_eqq($am->find_all("elan", 3), [1, 5]);
xassert_eqq($am->find_all("é"), [1, 5]);
$am->add("élange", 6, 2);
xassert_eqq($am->find_all("ela"), [1, 5, 6]);
xassert_eqq($am->find_all("elan"), [1, 5]);
xassert_eqq($am->find_all("elange"), [6]);
xassert_eqq($am->find_all("elan*"), [1, 5, 6]);
xassert_eqq($am->find_all("e*e"), [6]);

xassert(AbbreviationMatcher::is_camel_word("9b"));
xassert(!AbbreviationMatcher::is_camel_word("99"));
xassert(AbbreviationMatcher::is_camel_word("OveMer"));
xassert(!AbbreviationMatcher::is_camel_word("Ovemer"));
xassert(!AbbreviationMatcher::is_camel_word("ovemer"));
xassert(!AbbreviationMatcher::is_camel_word("ove mer"));
xassert(AbbreviationMatcher::is_camel_word("ove-mer"));

$am->add("99 Problems", 7);
xassert_eqq($am->find_all("99p"), [7]);

$am->add("?", 8);
xassert_eqq($am->find_all("ela"), [1, 5, 6]);
xassert_eqq($am->find_all("elan"), [1, 5]);
xassert_eqq($am->find_all("elange"), [6]);
xassert_eqq($am->find_all("elan*"), [1, 5, 6]);
xassert_eqq($am->find_all("e*e"), [6]);
xassert_eqq($am->find_all("99p"), [7]);
xassert_eqq($am->find_all("?"), [8]);

$am = new AbbreviationMatcher;
$am->add("Overall merit", 1);
$am->add("Overall merit 2", 2);
$am->add("Overall merit 3", 3);
$am->add("Overall merit 4", 4);
xassert_eqq($am->find_all("OveMer"), [1]);
xassert_eqq($am->find_all("merit overall"), []);
xassert_eqq($am->find_all("OveMer2"), [2]);
xassert_eqq($am->find_all("overall merit*"), [1, 2, 3, 4]);
xassert_eqq($am->find_all("OveMer*"), [1, 2, 3, 4]);

$am->add("PC Person", 5);
$am->add("PC Person 2", 6);
$am->add("P. C. Person 3", 7);
$am->add("P. C. Person 20", 8);
xassert_eqq($am->find_all("PCPer"), [5]);
xassert_eqq($am->find_all("PCPer2"), [6]);
xassert_eqq($am->find_all("PCPer3"), [7]);
xassert_eqq($am->find_all("PCPer20"), [8]);
xassert_eqq($am->find_all("Per"), [5, 6, 7, 8]);
xassert_eqq($am->find_all("20"), [8]);
xassert_eqq($am->find_all("2"), [2, 6]);

$am->add("Number 2", 9);
$am->add("Number 2 Bis", 10);
$am->add("2 Butts", 11);
xassert_eqq($am->find_all("2"), [2, 6, 9, 10, 11]);

$am = new AbbreviationMatcher;
$am->add("France Land", 5);
$am->add("France Land Flower", 6);
$am->add("France Land Ripen", 7);
$am->add("Glass Flower", 8);
$am->add("Glass Flower Milk", 9);
$am->add("Flower Cheese", 10);
$am->add("Anne France", 11);
xassert_eqq($am->find_all("flower"), [6, 8, 9, 10]);
xassert_eqq($am->find_all("flo"), [6, 8, 9, 10]);
xassert_eqq($am->find_all("fra"), [5, 6, 7, 11]);

$am->add("France", 12);
xassert_eqq($am->find_all("fra"), [12]);
xassert_eqq($am->find_all("fra*"), [5, 6, 7, 12]);
xassert_eqq($am->find_all("*fra*"), [5, 6, 7, 11, 12]);

// AbbreviationMatcher tests taken from old abbreviation styles
$am = new AbbreviationMatcher;
$am->add("Cover Letter", 1);
$am->add("Other Artifact", 2);
xassert_eqq($am->find_all("other-artifact"), [2]);
xassert_eqq($am->find_all("cover-letter"), [1]);

$am = new AbbreviationMatcher;
$am->add("Second Round Paper", 1);
$am->add("Second Round Response (PDF)", 2);
xassert_eqq($am->find_all("second-round-paper"), [1]);
xassert_eqq($am->find_all("second-round-response--pdf"), [2]);

$am = new AbbreviationMatcher;
$am->add("Paper is co-authored with at least one PC member", 1);
xassert_eqq($am->find_all("paper-is-co-authored-with-at-least-one-pc-member"), [1]);

// Filer::docstore_fixed_prefix
xassert_eqq(Filer::docstore_fixed_prefix(null), null);
xassert_eqq(Filer::docstore_fixed_prefix(false), false);
xassert_eqq(Filer::docstore_fixed_prefix(""), "");
xassert_eqq(Filer::docstore_fixed_prefix("/"), "/");
xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e"), "/a/b/c/d/e/");
xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e///"), "/a/b/c/d/e///");
xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b"), "/a/b/c/d/e/%/a/b/");
xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b%"), "/a/b/c/d/e/%/a/b%/");
xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b%h%x"), "/a/b/c/d/e/%/a/");
xassert_eqq(Filer::docstore_fixed_prefix("/%02h%x"), "/");
xassert_eqq(Filer::docstore_fixed_prefix("%02h%x"), "");

// Document::content_binary_hash
$Conf->save_setting("opt.contentHashMethod", 1, "sha1");
$doc = new DocumentInfo(["content" => ""], $Conf);
xassert_eqq($doc->text_hash(), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
xassert_eqq($doc->content_binary_hash(), hex2bin("da39a3ee5e6b4b0d3255bfef95601890afd80709"));
$doc->set_content("Hello\n");
xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
xassert_eqq($doc->content_binary_hash(), hex2bin("1d229271928d3f9e2bb0375bd6ce5db6c6d348d9"));
$Conf->save_setting("opt.contentHashMethod", 1, "sha256");
xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));
$doc->set_content("");
xassert_eqq($doc->text_hash(), "sha2-e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");
xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"));
$doc->set_content("Hello\n");
xassert_eqq($doc->text_hash(), "sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));

// docstore_path expansion and s3_document
$Conf->save_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
$Conf->save_setting("opt.contentHashMethod", 1, "sha1");
$doc->set_content("Hello\n", "text/plain");
xassert_eqq(Filer::docstore_path($doc), "/foo/bar/1d2/1d229/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
$Conf->save_setting("opt.docstore", 1, "/foo/bar");
$Conf->save_setting("opt.docstoreSubdir", 1, true);
xassert_eqq(Filer::docstore_path($doc), "/foo/bar/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");
xassert_eqq($doc->s3_key(), "doc/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");

$Conf->save_setting("opt.contentHashMethod", 1, "sha256");
$doc->set_content("Hello\n", "text/plain");
xassert_eqq(Filer::docstore_path($doc), "/foo/bar/sha2-66/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");
$Conf->save_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
xassert_eqq(Filer::docstore_path($doc), "/foo/bar/sha2-66a/sha2-66a04/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
xassert_eqq($doc->s3_key(), "doc/66a/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");

// collaborator fixing
xassert_eqq(AuthorMatcher::fix_collaborators("\"University of California, San Diego\", \"University of California, Los Angeles\", \"David Culler (University of California, Berkeley)\", \"Kamin Whitehouse (University of Virginia)\", \"Yuvraj Agarwal (Carnegie Mellon University)\", \"Mario Berges (Carnegie Mellon University)\", \"Joern Ploennigs (IBM)\", \"Mikkel Baun Kjaergaard (Southern Denmark University)\", \"Donatella Sciuto (Politecnico di Milano)\", \"Santosh Kumar (University of Memphis)\""),
    "All (University of California, San Diego)
All (University of California, Los Angeles)
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)");
xassert_eqq(AuthorMatcher::fix_collaborators("University of California, San Diego
University of California, Los Angeles
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)\n"),
    "All (University of California, San Diego)
All (University of California, Los Angeles)
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)");
xassert_eqq(AuthorMatcher::fix_collaborators("University of Wisconsin-Madison
AMD Research
University of Illinois at Urbana-Champaign
Sarita Adve (UIUC) - PhD advisor
Karu Sankaralingam (Wisconsin) - MS Advisor
Rakesh Komuravelli (Qualcomm) - recent collaborator (last publication together: 9/2016 (ISPASS))
Tony Gutierrez (AMD Research) – recent collaborator (last publication: 2/2018)
Brad Beckmann (AMD Research) – recent collaborator (last publication: 2/2018)
Alex Dutu (AMD Research) – recent collaborator (last publication: 2/2018)
Joe Gross (Samsung Research) – recent collaborator (last publication: 2/2018)
John Kalamatianos (AMD Research) – recent collaborator (last publication: 2/2018)
Onur Kayiran (AMD Research) – recent collaborator (last publication: 2/2018)
Michael LeBeane (AMD Research) – recent collaborator (last publication: 2/2018)
Matthew Poremba (AMD Research) – recent collaborator (last publication: 2/2018)
Brandon Potter (AMD Research) – recent collaborator (last publication: 2/2018)
Sooraj Puthoor (AMD Research) – recent collaborator (last publication: 2/2018)
Mark Wyse (Washington) – recent collaborator (last publication: 2/2018)
Jieming Yin (AMD Research) – recent collaborator (last publication: 2/2018)
Xianwei Zhang (AMD Research) – recent collaborator (last publication: 2/2018)
Akshay Jain (Qualcomm) – recent collaborator (last publication: 2/2018)
Tim Rogers (Purdue) – recent collaborator (last publication: 2/2018)
"),
    "All (University of Wisconsin-Madison)
All (AMD Research)
All (University of Illinois at Urbana-Champaign)
Sarita Adve (UIUC) - PhD advisor
Karu Sankaralingam (Wisconsin) - MS Advisor
Rakesh Komuravelli (Qualcomm) - recent collaborator (last publication together: 9/2016 (ISPASS))
Tony Gutierrez (AMD Research) - recent collaborator (last publication: 2/2018)
Brad Beckmann (AMD Research) - recent collaborator (last publication: 2/2018)
Alex Dutu (AMD Research) - recent collaborator (last publication: 2/2018)
Joe Gross (Samsung Research) - recent collaborator (last publication: 2/2018)
John Kalamatianos (AMD Research) - recent collaborator (last publication: 2/2018)
Onur Kayiran (AMD Research) - recent collaborator (last publication: 2/2018)
Michael LeBeane (AMD Research) - recent collaborator (last publication: 2/2018)
Matthew Poremba (AMD Research) - recent collaborator (last publication: 2/2018)
Brandon Potter (AMD Research) - recent collaborator (last publication: 2/2018)
Sooraj Puthoor (AMD Research) - recent collaborator (last publication: 2/2018)
Mark Wyse (Washington) - recent collaborator (last publication: 2/2018)
Jieming Yin (AMD Research) - recent collaborator (last publication: 2/2018)
Xianwei Zhang (AMD Research) - recent collaborator (last publication: 2/2018)
Akshay Jain (Qualcomm) - recent collaborator (last publication: 2/2018)
Tim Rogers (Purdue) - recent collaborator (last publication: 2/2018)");
xassert_eqq(AuthorMatcher::fix_collaborators("T. Arselins (LLNL) S. Bagchi (Purdue) D. Bailey (LBL) D. Bailey (Williams) A. Baker (Colorado) D. Beckingsale (U. Warwick) A. Bhatele (LLNL) B. Bihari (LLNL) S. Biswas (LLNL) D. Boehme (LLNL) P.-T. Bremer (LLNL) G. Bronevetsky (LLNL) L. Carrington (SDSC) A. Cook (LLNL) B. de Supinski (LLNL) E. Draeger (LLNL) E. Elnozahy (IBM) M. Fagan (Rice) R. Fowler (UNC) S. Futral (LLNL) J. Galarowicz (Krell) J. Glosli (LLNL) J. Gonzalez (BSC) G. Gopalakrishnan (Utah) W. Gropp (Illinois) J. Gunnels (IBM)", 1),
    "T. Arselins (LLNL)
S. Bagchi (Purdue)
D. Bailey (LBL)
D. Bailey (Williams)
A. Baker (Colorado)
D. Beckingsale (U. Warwick)
A. Bhatele (LLNL)
B. Bihari (LLNL)
S. Biswas (LLNL)
D. Boehme (LLNL)
P.-T. Bremer (LLNL)
G. Bronevetsky (LLNL)
L. Carrington (SDSC)
A. Cook (LLNL)
B. de Supinski (LLNL)
E. Draeger (LLNL)
E. Elnozahy (IBM)
M. Fagan (Rice)
R. Fowler (UNC)
S. Futral (LLNL)
J. Galarowicz (Krell)
J. Glosli (LLNL)
J. Gonzalez (BSC)
G. Gopalakrishnan (Utah)
W. Gropp (Illinois)
J. Gunnels (IBM)");
xassert_eqq(AuthorMatcher::fix_collaborators("Sal Stolfo, Guofei Gu, Manos Antonakakis, Roberto Perdisci, Weidong Cui, Xiapu Luo, Rocky Chang, Kapil Singh, Helen Wang, Zhichun Li, Junjie Zhang, David Dagon, Nick Feamster, Phil Porras."),
    "Sal Stolfo
Guofei Gu
Manos Antonakakis
Roberto Perdisci
Weidong Cui
Xiapu Luo
Rocky Chang
Kapil Singh
Helen Wang
Zhichun Li
Junjie Zhang
David Dagon
Nick Feamster
Phil Porras.");
xassert_eqq(AuthorMatcher::fix_collaborators("UTEXAS
UT Austin
Doe Hyun Yoon \t(Google)
Evgeni Krimer \t(NVIDIA)
Min Kyu Jeong\t(Oracle Labs)
Minsoo Rhu\t(NVIDIA)
Michael Sullivan\t(NVIDIA)"), "All (UTEXAS)
All (UT Austin)
Doe Hyun Yoon (Google)
Evgeni Krimer (NVIDIA)
Min Kyu Jeong (Oracle Labs)
Minsoo Rhu (NVIDIA)
Michael Sullivan (NVIDIA)");
xassert_eqq(AuthorMatcher::fix_collaborators("Vishal Misra\t\tColumbia University
Columbia
Francois Baccelli\tINRIA-ENS\t,\t
Guillaume Bichot\tThomson\t\t
Bartlomiej Blaszczyszyn\tInria-Ens\t\t
Jeffrey Bloom\tThomson Research\t\t
Guillaume Boisson\tThomson\t\t
Olivier Bonaventure\tUniversitÈ catholique de Louvain\t\t
Charles Bordenave\tINRIA / ENS\t\t\n"), "Vishal Misra (Columbia University)
All (Columbia)
Francois Baccelli (INRIA-ENS)
Guillaume Bichot (Thomson)
Bartlomiej Blaszczyszyn (Inria-Ens)
Jeffrey Bloom (Thomson Research)
Guillaume Boisson (Thomson)
Olivier Bonaventure (UniversitÈ catholique de Louvain)
Charles Bordenave (INRIA / ENS)");
xassert_eqq(AuthorMatcher::fix_collaborators("\"Princeton University\"
\"NVIDIA\"
\"Google\"
\"Microsoft Research\"
Agarwal, Anuradha (MIT)
Amarasinghe, Saman (MIT)
Andoni, Alexandr (Columbia)
Arvind (MIT)
Badam, Anirudh (Microsoft)
Banerjee, Kaustav (UCSB)
Beckmann, Nathan (CMU)
Bhanja, Sanjukta (USF)
Carbin, Michael (MIT)
Chakrabarti, Chaitali (ASU)
Chandrakasan, Anantha (MIT)
Chang, Mung (Perdue)
Devadas, Srini (MIT)
Doppa, Jana (Washington State U.)
Elmroth, Erik (Umea University)
Fletcher, Chris (UIUC)
Freedman, Michael (Princeton)
Gu, Tian (MIT)
Heo, Deuk (Washington State U.)
Hoffmann, Henry (University of Chicago)
Hu, Juejun (MIT)
Kalyanaraman, Ananth (Washington State U.)
Kim, Martha (Columbia)
Kim, Nam Sung (UIUC)
Klein, Cristian (Umea University)
Lee, Walter (Google)
Li, Hai (Duke)
Liu, Jifeng (Dartmouth)
Lucia, Brandon (CMU)
Marculescu, Diana (CMU)
Marculescu, Radu (CMU)
Martonosi, Margaret (Princeton)
Miller, Jason (MIT)
Mittal, Prateek (Princeton)
Ogras, Umit (ASU)
Ozev, Sule (ASU)
Pande, Partha (Washington State U.)
Rand, Barry (Princeton)
Sanchez, Daniel (MIT)
Shepard, Kenneth (Columbia)
Sherwood, Timothy (UCSB)
Solar-Lezama, Armando (MIT)
Srinivasa, Sidhartha (CMU)
Strauss, Karen (Microsoft)
Sun, Andy (LaXense)
Taylor, Michael (University of Washington)
Wagh, Sameer Wagh (Princeton)
Yeung, Donald (University of Maryland)
Zheng, Liang (Princeton)
Batten, Christopher (Cornell)
Lam, Patrick (University of Waterloo)"), "All (Princeton University)
All (NVIDIA)
All (Google)
All (Microsoft Research)
Agarwal, Anuradha (MIT)
Amarasinghe, Saman (MIT)
Andoni, Alexandr (Columbia)
Arvind (MIT)
Badam, Anirudh (Microsoft)
Banerjee, Kaustav (UCSB)
Beckmann, Nathan (CMU)
Bhanja, Sanjukta (USF)
Carbin, Michael (MIT)
Chakrabarti, Chaitali (ASU)
Chandrakasan, Anantha (MIT)
Chang, Mung (Perdue)
Devadas, Srini (MIT)
Doppa, Jana (Washington State U.)
Elmroth, Erik (Umea University)
Fletcher, Chris (UIUC)
Freedman, Michael (Princeton)
Gu, Tian (MIT)
Heo, Deuk (Washington State U.)
Hoffmann, Henry (University of Chicago)
Hu, Juejun (MIT)
Kalyanaraman, Ananth (Washington State U.)
Kim, Martha (Columbia)
Kim, Nam Sung (UIUC)
Klein, Cristian (Umea University)
Lee, Walter (Google)
Li, Hai (Duke)
Liu, Jifeng (Dartmouth)
Lucia, Brandon (CMU)
Marculescu, Diana (CMU)
Marculescu, Radu (CMU)
Martonosi, Margaret (Princeton)
Miller, Jason (MIT)
Mittal, Prateek (Princeton)
Ogras, Umit (ASU)
Ozev, Sule (ASU)
Pande, Partha (Washington State U.)
Rand, Barry (Princeton)
Sanchez, Daniel (MIT)
Shepard, Kenneth (Columbia)
Sherwood, Timothy (UCSB)
Solar-Lezama, Armando (MIT)
Srinivasa, Sidhartha (CMU)
Strauss, Karen (Microsoft)
Sun, Andy (LaXense)
Taylor, Michael (University of Washington)
Wagh, Sameer Wagh (Princeton)
Yeung, Donald (University of Maryland)
Zheng, Liang (Princeton)
Batten, Christopher (Cornell)
Lam, Patrick (University of Waterloo)");
xassert_eqq(AuthorMatcher::fix_collaborators("University of Illinois, Urbana-Champaign
Marcelo Cintra  (Intel)  advisor/student
Michael Huang   (University of Rochester)  advisor/student
Jose Martinez   (Cornell University)  advisor/student
Anthony Nguyen  (Intel Corporation) advisor/student
"), "All (University of Illinois, Urbana-Champaign)
Marcelo Cintra (Intel) - advisor/student
Michael Huang (University of Rochester) - advisor/student
Jose Martinez (Cornell University) - advisor/student
Anthony Nguyen (Intel Corporation) - advisor/student");
xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken) Lueh"),
    "G.-Y. (Ken) Lueh (unknown)");
xassert_eqq(AuthorMatcher::fix_collaborators("none\n"), "None");
xassert_eqq(AuthorMatcher::fix_collaborators("none.\n"), "None");
xassert_eqq(AuthorMatcher::fix_collaborators("NONE.\n"), "None");
xassert_eqq(AuthorMatcher::fix_collaborators("University of New South Wales (UNSW)"), "All (University of New South Wales)");
xassert_eqq(AuthorMatcher::fix_collaborators("Gennaro Parlato, University of Southampton, UK"), "Gennaro Parlato (University of Southampton, UK)");
xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken (Butt)) Lueh"), "G.-Y. (Ken (Butt)) Lueh (unknown)");
xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken (Butt)) Lueh (France Telecom)"), "G.-Y. (Ken (Butt)) Lueh (France Telecom)");
xassert_eqq(AuthorMatcher::fix_collaborators("All (Fucktown, Fuckville, Fuck City, Fuck Prefecture, Fuckovia)"), "All (Fucktown, Fuckville, Fuck City, Fuck Prefecture, Fuckovia)");
xassert_eqq(AuthorMatcher::fix_collaborators("Sriram Rajamani (MSR), Aditya Nori (MSR), Akash Lal (MSR), Ganesan Ramalingam (MSR)"), "Sriram Rajamani (MSR)
Aditya Nori (MSR)
Akash Lal (MSR)
Ganesan Ramalingam (MSR)");
xassert_eqq(AuthorMatcher::fix_collaborators("University of Southern California (USC), Universidade de Brasilia (UnB)", 1), "All (University of Southern California)
Universidade de Brasilia (UnB)");
xassert_eqq(AuthorMatcher::fix_collaborators("Schur, Lisa"), "Schur, Lisa");
xassert_eqq(AuthorMatcher::fix_collaborators("Lisa Schur, Lisa"), "Lisa Schur (Lisa)");
xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao; Virginia Tech, USA", 1), "Danfeng(Daphne)Yao (Virginia Tech, USA)");
xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao (Virginia Tech, USA)"), "Danfeng(Daphne)Yao (Virginia Tech, USA)");
xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao (Virginia Tech, USA)", 1), "Danfeng(Daphne)Yao (Virginia Tech, USA)");

$au = Author::make_string("G.-Y. (Ken) Lueh");
xassert_eqq($au->firstName, "G.-Y. (Ken)");
$au = Author::make_string("G.-Y. (Ken (Butt)) Lueh");
xassert_eqq($au->firstName, "G.-Y. (Ken (Butt))");
$au = Author::make_string("G.-Y. (Ken (Butt)) Lueh (France (Crap) Telecom)");
xassert_eqq($au->firstName, "G.-Y. (Ken (Butt))");
xassert_eqq($au->affiliation, "France (Crap) Telecom");
$au = Author::make_string("G.-Y. (Ken (Butt)) Lueh (France (Crap) Telecom)- Inc.");
xassert_eqq($au->firstName, "G.-Y. (Ken (Butt))");
xassert_eqq($au->affiliation, "France (Crap) Telecom");
$au = Author::make_string("G.-Y. (Ken (Butt)) Lueh (France (Crap) Telecom");
xassert_eqq($au->firstName, "G.-Y. (Ken (Butt))");
xassert_eqq($au->affiliation, "France (Crap) Telecom");

// mailer expansion
$mailer = new HotCRPMailer($Conf, null, ["width" => false]);
xassert_eqq($mailer->expand("%CONFNAME%//%CONFLONGNAME%//%CONFSHORTNAME%"),
    "Test Conference I (Testconf I)//Test Conference I//Testconf I\n");
xassert_eqq($mailer->expand("%SITECONTACT%//%ADMINEMAIL%"),
    "Eddie Kohler <ekohler@hotcrp.lcdf.org>//ekohler@hotcrp.lcdf.org\n");
xassert_eqq($mailer->expand("%URLENC(ADMINEMAIL)% : %OPT(ADMINEMAIL)% : %OPT(NULL)% : %OPT(EMAIL)%"),
    "ekohler%40hotcrp.lcdf.org : ekohler@hotcrp.lcdf.org :  : %OPT(EMAIL)%\n");

// HTML cleaning
$err = null;
xassert_eqq(CleanHTML::basic_clean('<a>Hello', $err), false);
xassert_eqq(CleanHTML::basic_clean('<a>Hello</a>', $err), '<a>Hello</a>');
xassert_eqq(CleanHTML::basic_clean('<script>Hello</script>', $err), false);
xassert_eqq(CleanHTML::basic_clean('< SCRIPT >Hello</script>', $err), false);
xassert_eqq(CleanHTML::basic_clean('<a href = fuckovia ><B>Hello</b></a>', $err), '<a href="fuckovia"><b>Hello</b></a>');
xassert_eqq(CleanHTML::basic_clean('<a href = " javaScript:hello" ><B>Hello</b></a>', $err), false);
xassert_eqq(CleanHTML::basic_clean('<a href = "https://hello" onclick="fuck"><B>Hello</b></a>', $err), false);
xassert_eqq(CleanHTML::basic_clean('<a href =\'https:"""//hello\' butt><B>Hello</b></a>', $err), '<a href="https:&quot;&quot;&quot;//hello" butt><b>Hello</b></a>');

// tag sorting
$dt = $Conf->tags();
xassert_eqq($dt->sort(""), "");
xassert_eqq($dt->sort(" a"), " a");
xassert_eqq($dt->sort(" a1 a10 a100 a2"), " a1 a2 a10 a100");

// GMP shim
require_once("$ConfSitePATH/lib/gmpshim.php");
$a = gmpshim_init("0");
xassert_eqq(gmpshim_testbit($a, 0), false);
xassert_eqq(gmpshim_testbit($a, 1), false);
xassert_eqq(gmpshim_testbit($a, 63), false);
xassert_eqq(gmpshim_testbit($a, 64), false);
xassert_eqq(gmpshim_scan1($a, 0), -1);
gmpshim_setbit($a, 10);
xassert_eqq(gmpshim_testbit($a, 0), false);
xassert_eqq(gmpshim_testbit($a, 10), true);
xassert_eqq(gmpshim_testbit($a, 63), false);
xassert_eqq(gmpshim_testbit($a, 64), false);
xassert_eqq(gmpshim_scan1($a, 0), 10);
xassert_eqq(gmpshim_scan1($a, 10), 10);
xassert_eqq(gmpshim_scan1($a, 11), -1);
gmpshim_setbit($a, 10, false);
gmpshim_setbit($a, 63, true);
gmpshim_setbit($a, 127, true);
xassert_eqq(gmpshim_testbit($a, 0), false);
xassert_eqq(gmpshim_testbit($a, 10), false);
xassert_eqq(gmpshim_testbit($a, 63), true);
xassert_eqq(gmpshim_testbit($a, 64), false);
xassert_eqq(gmpshim_testbit($a, 127), true);
xassert_eqq(gmpshim_scan1($a, 0), 63);
xassert_eqq(gmpshim_scan1($a, 63), 63);
xassert_eqq(gmpshim_scan1($a, 64), 127);

xassert_exit();
