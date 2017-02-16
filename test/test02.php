<?php
// test02.php -- HotCRP S3 and database unit tests
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
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
$paper1 = $Conf->paperRow(1, $Conf->user_by_email("chair@_.com"));
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

// random PHP behavior tests
if (PHP_MAJOR_VERSION >= 7)
    xassert_eqq(substr("", 0, 1), ""); // UGH
else
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
xassert_eqq(Json::decode("[1-2]"), null);
xassert_eqq(json_decode("[1-2]"), null);
xassert_eqq(Json::decode("[1,2,3-4,5,6-10,11]"), null);
xassert_eqq(json_decode("[1,2,3-4,5,6-10,11]"), null);

xassert_eqq(json_encode(json_object_replace(null, ["a" => 1])), '{"a":1}');
xassert_eqq(json_encode(json_object_replace(["a" => 1], ["a" => 2])), '{"a":2}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => 2])), '{"a":2}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => null])), '{}');
xassert_eqq(json_encode(json_object_replace((object) ["a" => 1], ["a" => null], true)), 'null');

// SessionList tests
xassert_eqq(json_encode(SessionList::decode_ids("[1-2]")), "[1,2]");
xassert_eqq(json_encode(SessionList::decode_ids("[1,2,3-4,5,6-10,11]")), "[1,2,3,4,5,6,7,8,9,10,11]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,2]))), "[1,2]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,2,3,4,5,6,7,8,9,10,11]))), "[1,2,3,4,5,6,7,8,9,10,11]");
xassert_eqq(json_encode(SessionList::decode_ids(SessionList::encode_ids([1,3,5,7,9,10,11]))), "[1,3,5,7,9,10,11]");

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
xassert_eqq($ns->make_absolute("https://foo/bar/baz"), "https://foo/bar/baz");
xassert_eqq($ns->make_absolute("http://fooxxx/bar/baz"), "http://fooxxx/bar/baz");
xassert_eqq($ns->make_absolute("//foo/bar/baz"), "http://foo/bar/baz");
xassert_eqq($ns->make_absolute("/foo/bar/baz"), "http://butt.com/foo/bar/baz");
xassert_eqq($ns->make_absolute("after/path"), "http://butt.com/fart/barf/after/path");
xassert_eqq($ns->make_absolute("../after/path"), "http://butt.com/fart/after/path");
xassert_eqq($ns->make_absolute("?confusion=20"), "http://butt.com/fart/barf/?confusion=20");

// other helpers
xassert_eqq(ini_get_bytes(null, "1"), 1);
xassert_eqq(ini_get_bytes(null, "1 M"), 1 * (1 << 20));
xassert_eqq(ini_get_bytes(null, "1.2k"), 1.2 * (1 << 10));
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

// i18n messages
$ms = new IntlMsgSet;
$ms->add("Hello", "Bonjour");
$ms->add(["%d friend", "%d amis", ["$1 ≠ 1"]]);
$ms->add("%d friend", "%d ami");
$ms->add("ax", "a");
$ms->add("ax", "b");
$ms->add("bx", "a", 2);
$ms->add("bx", "b");
$ms->set("FOO", 100);
xassert_eqq($ms->x("Hello"), "Bonjour");
xassert_eqq($ms->x("%d friend", 1), "1 ami");
xassert_eqq($ms->x("%d friend", 0), "0 amis");
xassert_eqq($ms->x("%d friend", 2), "2 amis");
xassert_eqq($ms->x("%FOO\$s friend"), "100 friend");
xassert_eqq($ms->x("ax"), "b");
xassert_eqq($ms->x("bx"), "a");
xassert_eqq($ms->x("%FOO% friend"), "100 friend");
xassert_eqq($ms->x("%xOOB%x friend", 10, 11), "aOOBb friend");

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
xassert_eqq($am->find("elan"), [1]);
xassert_eqq($am->find("el"), [1]);
xassert_eqq($am->find("él"), [1]);
xassert_eqq($am->find("ÉL"), [1]);
xassert_eqq($am->find("e"), [1, 2]);
xassert_eqq($am->find("ecla"), [2]);
xassert_eqq($am->find("should-the-pc-suck"), [3]);
xassert_eqq($am->find("should-the pc-suck"), [3]);
xassert_eqq($am->find("ShoPCSuc"), [3]);
xassert_eqq($am->find("ShoPCRoc"), [4]);
$am->add("élan", 5, 2);
xassert_eqq($am->find("elan"), [1, 5]);
xassert_eqq($am->find("elan", 1), [1]);
xassert_eqq($am->find("elan", 2), [5]);
xassert_eqq($am->find("elan", 3), [1, 5]);

xassert_exit();
