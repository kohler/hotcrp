<?php
// t_unit.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Unit_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_xassert_nan() {
        xassert_eqq(NAN, NAN);
        xassert_in_eqq(NAN, [NAN]);
    }

    function test_dbl_format_query() {
        xassert_eqq(Dbl::format_query("Hello"), "Hello");
        xassert_eqq(Dbl::format_query("Hello??"), "Hello?");
        xassert_eqq(Dbl::format_query("Hello????"), "Hello??");
        xassert_eqq(Dbl::format_query("Hello????? What the heck", 1), "Hello??1 What the heck");
        xassert_in_eqq(Dbl::format_query("Hello ?U? ?U(a)?", 1, 2), ["Hello 1 values(a)2", "Hello  as __values 1 __values.a2"]);
        xassert_eqq(Dbl::format_query("select ?, ?, ?, ?s, ?s, ?s, ?",
                                      1, "a", null, 2, "b", null, 3),
                    "select 1, 'a', NULL, 2, b, , 3");
        xassert_eqq(Dbl::format_query_apply("select ?, ?, ?, ?s, ?s, ?s, ?",
                                            [1, "a", null, 2, "b", null, 3]),
                    "select 1, 'a', NULL, 2, b, , 3");
        xassert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?, ?s, ?s, ?s, ?",
                                            [1, "a", null, 2, "b", null, 3]),
                    "select 'a', 1, NULL, 2, b, , 3");
        xassert_eqq(Dbl::format_query_apply("select ?{2}, ?{1}, ?{ab}, ?{2}s, ?{1}s, ?{ab}s, ?",
                                            [1, "a", "ab" => "Woah", "Leftover"]),
                    "select 'a', 1, 'Woah', a, 1, Woah, 'Leftover'");
        xassert_eqq(Dbl::format_query("select a?e, b?e, c?e, d?e", null, 1, 2.1, "e"),
                    "select a IS NULL, b=1, c=2.1, d='e'");
        xassert_eqq(Dbl::format_query("select a?E, b?E, c?E, d?E", null, 1, 2.1, "e"),
                    "select a IS NOT NULL, b!=1, c!=2.1, d!='e'");
        xassert_eqq(Dbl::format_query("insert ?v", [1, 2, 3]),
                    "insert (1), (2), (3)");
        xassert_eqq(Dbl::format_query("insert ?v", [[1, null], [2, "A"], ["b", 0.1]]),
                    "insert (1,NULL), (2,'A'), ('b',0.1)");
    }

    function test_escape_like() {
        xassert_eqq(Dbl::fetch_ivalue("select '1' like cast(? as binary) from dual", Dbl::escape_like("1")), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '%' like cast(? as binary) from dual", Dbl::escape_like("%")), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '!' like cast(? as binary) from dual", Dbl::escape_like("%")), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(? as binary) from dual", "\\", Dbl::escape_like("\\")), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(? as binary) from dual", "\n", Dbl::escape_like("\n")), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(? as binary) from dual", "\\x", Dbl::escape_like("\\x")), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(? as binary) from dual", "xx", Dbl::escape_like("\\x")), 0);

        xassert_eqq(Dbl::fetch_ivalue("select '1' like cast(?l as binary) from dual", "1"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '1' like cast(?l as binary) from dual", 1), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '%' like cast(?l as binary) from dual", "%"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '!' like cast(?l as binary) from dual", "%"), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(?l as binary) from dual", "\\", "\\"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(?l as binary) from dual", "\n", "\n"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(?l as binary) from dual", "\\x", "\\x"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(?l as binary) from dual", "xx", "\\x"), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(?l as binary) from dual", "xx", "%x"), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast(? as binary) from dual", "xx", "%x"), 1);

        xassert_eqq(Dbl::fetch_ivalue("select '1' like cast('?ls' as binary) from dual", "1"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '1' like cast('?ls' as binary) from dual", 1), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '%' like cast('?ls' as binary) from dual", "%"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select '!' like cast('?ls' as binary) from dual", "%"), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast('?ls' as binary) from dual", "\\", "\\"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast('?ls' as binary) from dual", "\n", "\n"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast('?ls' as binary) from dual", "\\x", "\\x"), 1);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast('?ls' as binary) from dual", "xx", "\\x"), 0);
        xassert_eqq(Dbl::fetch_ivalue("select ? like cast('?ls' as binary) from dual", "xx", "%x"), 0);
    }

    function test_dbl_compare_exchange() {
        Dbl::qe("delete from Settings where name='cmpxchg'");
        Dbl::qe("insert into Settings set name='cmpxchg', value=1");
        xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 1);
        xassert_eqq(Dbl::compare_exchange(Dbl::$default_dblink,
                                          "select value from Settings where name=?", ["cmpxchg"],
                                          function ($x) { return (int) $x + 1; },
                                          "update Settings set value=?{desired} where name=? and value=?{expected}", ["cmpxchg"]),
                    2);
        xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 2);
        xassert_eqq(Dbl::compare_exchange(Dbl::$default_dblink,
                                          "select value from Settings where name=?", ["cmpxchg"],
                                          function ($x) { return (int) $x + 1; },
                                          "update Settings set value?{desired}e where name=? and value?{expected}e", ["cmpxchg"]),
                    3);
        xassert_eqq(Dbl::fetch_ivalue("select value from Settings where name='cmpxchg'"), 3);
    }

    function test_dbl_multiquery() {
        $mresult = Dbl::multi_q("select 0 from dual; select u2410ufwqoidvhslaihwqhwf from ahselhg1huql; select 1 from dual");
        $result = $mresult->next();
        xassert($result instanceof mysqli_result);
        xassert_array_eqq($result->fetch_row(), ["0"]);
        Dbl::free($result);
        $result = $mresult->next();
        xassert_neqq($result->errno, 0);
        xassert_eqq($result->fetch_row(), null);
        Dbl::free($result);
        $result = $mresult->next();
        xassert_eqq($result, false);
    }

    function test_array_sort_unique() {
        $a = [1, 100, 200, 100, 300, 400];
        array_sort_unique($a);
        xassert_array_eqq($a, [1, 100, 200, 300, 400]);
        array_sort_unique($a);
        xassert_array_eqq($a, [1, 100, 200, 300, 400]);

        // edge cases
        $a = [];
        array_sort_unique($a);
        xassert_array_eqq($a, []);
        $a = [1];
        array_sort_unique($a);
        xassert_array_eqq($a, [1]);
    }

    function test_document_update_metadata() {
        $user_chair = $this->conf->checked_user_by_email("chair@_.com");
        $paper1 = $user_chair->checked_paper_by_id(1);
        $doc = $paper1->document(DTYPE_SUBMISSION);
        xassert(!!$doc);
        xassert_eqq((array) $doc->metadata(), []);

        $doc->set_prop("hello", 1);
        xassert($doc->save_prop());
        xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
                    '{"hello":1}');

        $doc->set_prop("hello", 2);
        $doc->set_prop("foo", "bar");
        xassert($doc->save_prop());
        xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
                    '{"hello":2,"foo":"bar"}');

        $doc->set_prop("hello", null);
        xassert($doc->save_prop());
        xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
                    '{"foo":"bar"}');

        $doc->set_prop("too_long", str_repeat("!", 32768));
        xassert(!$doc->save_prop(true));
        xassert_eqq(Dbl::fetch_value("select infoJson from PaperStorage where paperStorageId=?", $doc->paperStorageId),
                    '{"foo":"bar"}');

        $this->conf->qe("update PaperStorage set infoJson=null where paperStorageId=?", $doc->paperStorageId);
    }

    function test_document_sanitize_filename() {
        xassert_eqq(DocumentInfo::sanitize_filename(""), null);
        xassert_eqq(DocumentInfo::sanitize_filename(".a"), "_a");
        xassert_eqq(DocumentInfo::sanitize_filename("a/b.txt"), "a_b.txt");
        xassert_eqq(DocumentInfo::sanitize_filename("a/\\b.txt"), "a__b.txt");
        xassert_eqq(DocumentInfo::sanitize_filename("a/\x80M.txt"), "a_\x7fM.txt");
        xassert_eqq(DocumentInfo::sanitize_filename(str_repeat("i", 1024) . ".txt"), str_repeat("i", 248) . "....txt");
        xassert_eqq(strlen(DocumentInfo::sanitize_filename(str_repeat("i", 1024) . ".txt")), 255);
        xassert_eqq(DocumentInfo::sanitize_filename(str_repeat("i", 1024)), str_repeat("i", 252) . "...");
    }

    function test_csv_split_lines() {
        xassert_array_eqq(CsvParser::split_lines(""), []);
        xassert_array_eqq(CsvParser::split_lines("\r"), ["\r"]);
        xassert_array_eqq(CsvParser::split_lines("\n"), ["\n"]);
        xassert_array_eqq(CsvParser::split_lines("\r\n"), ["\r\n"]);
        xassert_array_eqq(CsvParser::split_lines("\r\r\n"), ["\r", "\r\n"]);
        xassert_array_eqq(CsvParser::split_lines("\r\naaa"), ["\r\n", "aaa"]);
        xassert_array_eqq(CsvParser::split_lines("\na\r\nb\rc\n"), ["\n", "a\r\n", "b\r", "c\n"]);
    }

    function test_csv_next_list() {
        $csv = new CsvParser("0,1,2\n3,4,5\n6,7\n8,9,10\n");
        xassert_array_eqq($csv->next_list(), ["0", "1", "2"]);
        xassert_array_eqq($csv->next_list(), ["3", "4", "5"]);
        xassert_array_eqq($csv->next_list(), ["6", "7"]);
        xassert_array_eqq($csv->next_list(), ["8", "9", "10"]);
        xassert_eqq($csv->next_list(), null);
    }

    function test_csv_next_map() {
        $csv = new CsvParser("0,1,2\n3,4,5\n6,7\n8,9,10\n");
        xassert_array_eqq($csv->next_map(), ["0", "1", "2"]);
        xassert_array_eqq($csv->next_map(), ["3", "4", "5"]);
        xassert_array_eqq($csv->next_map(), ["6", "7"]);
        xassert_array_eqq($csv->next_map(), ["8", "9", "10"]);
        xassert_eqq($csv->next_map(), null);
    }

    function test_csv_next_list_header() {
        $csv = new CsvParser("0,1,2\n3,4,5\n6,7\n8,9,10\n");
        $csv->set_header($csv->next_row());
        xassert_array_eqq($csv->next_list(), ["3", "4", "5"]);
        xassert_array_eqq($csv->next_list(), ["6", "7"]);
        xassert_array_eqq($csv->next_list(), ["8", "9", "10"]);
        xassert_eqq($csv->next_list(), null);
    }

    function test_csv_next_map_header() {
        $csv = new CsvParser("0,1,2\n3,4,5\n6,7\n8,9,10\n");
        $csv->set_header($csv->next_row());
        xassert_array_eqq($csv->next_map(), ["0" => "3", "1" => "4", "2" => "5"]);
        xassert_array_eqq($csv->next_map(), ["0" => "6", "1" => "7"]);
        xassert_array_eqq($csv->next_map(), ["0" => "8", "1" => "9", "2" => "10"]);
        xassert_eqq($csv->next_map(), null);
    }

    function test_csv_next_row_header() {
        $csv = new CsvParser("0,1,2\n3,4,5\n6,7\n8,9,10\n");
        $csv->set_header($csv->next_row());
        xassert_array_eqq(iterator_to_array($csv->next_row()), ["0" => "3", "1" => "4", "2" => "5"]);
        xassert_array_eqq(iterator_to_array($csv->next_row()), ["0" => "6", "1" => "7"]);
        xassert_array_eqq(iterator_to_array($csv->next_row()), ["0" => "8", "1" => "9", "2" => "10"]);
        xassert_eqq($csv->next_row(), null);
    }

    function test_csv_row_set_component() {
        $csv = new CsvParser("2,1,0\n3,4,5\n6,7\n8,9,10\n");
        $csv->set_header($csv->next_row());
        $csvr = $csv->next_row();
        xassert(isset($csvr[0]));
        xassert_eqq($csvr[0], "3");
        $k = "0"; // Work around PHP bug #63217 in PHP 7.2 and before
        xassert(isset($csvr[$k]));
        xassert_eqq($csvr[$k], "5");
        xassert(!isset($csvr[3]));
        $csvr[3] = "10";
        xassert_eqq($csvr[3], "10");
        $csvr["xxxx"] = "1010";
        xassert_eqq($csvr["xxxx"], "1010");
        xassert_eqq($csvr["xxxxajajaj"], null);
    }

    function test_csv_header_transformations() {
        $csv = new CsvParser("Butts,Butt and Money,Yes\n3,4,5\n6,7\n8,9,10\n");
        $csv->set_header($csv->next_row());
        $csvr = $csv->next_row();
        xassert(isset($csvr[0]));
        xassert_eqq($csvr[0], "3");
        xassert(isset($csvr["butts"]));
        xassert_eqq($csvr["butts"], "3");
        xassert(isset($csvr["Butts"]));
        xassert_eqq($csvr["Butts"], "3");
        xassert(isset($csvr["butt_and_money"]));
        xassert_eqq($csvr["butt_and_money"], "4");
        $csvr = $csv->next_row();
        xassert(!isset($csvr[2]));
        $csvr["Yes"] = "Hi";
        xassert_eqq($csvr[2], "Hi");
    }

    function test_csv_json() {
        $csv = CsvParser::make_json(json_decode('[
    {"pid":2,"Butts and Money":4,"thing":true},
    {"paper":4, "Fungi": 10}
]'));
        $csv->add_synonym("pid", "paper");
        $csvr = $csv->next_row();
        xassert_eqq($csvr["pid"], "2");
        xassert_eqq($csvr["butts_and_money"], "4");
        xassert_eqq($csvr["thing"], "Y");
        $csvr = $csv->next_row();
        xassert_eqq($csvr["pid"], "4");
        xassert_eqq($csvr["Fungi"], "10");
    }

    function test_numrangejoin() {
        xassert_eqq(numrangejoin([1, 2, 3, 4, 6, 8]), "1–4, 6, and 8");
        xassert_eqq(numrangejoin(["#1", "#2", "#3", 4, "xx6", "xx7", 8]), "#1–3, 4, xx6–7, and 8");
    }

    function test_php_behavior() {
        xassert(PHP_MAJOR_VERSION >= 7);
        xassert_eqq(substr("", 0, 1), ""); // UGH
        xassert(!ctype_digit(""));
        xassert_eqq(!!preg_match('/\A\pZ\z/u', ' '), true);
    }

    function test_str_starts_ends() {
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
    }

    function test_gmp_shim() {
        $a = GMPShim::init("0");
        xassert_eqq(GMPShim::testbit($a, 0), false);
        xassert_eqq(GMPShim::testbit($a, 1), false);
        xassert_eqq(GMPShim::testbit($a, 63), false);
        xassert_eqq(GMPShim::testbit($a, 64), false);
        xassert_eqq(GMPShim::scan1($a, 0), -1);
        GMPShim::setbit($a, 10);
        xassert_eqq(GMPShim::testbit($a, 0), false);
        xassert_eqq(GMPShim::testbit($a, 10), true);
        xassert_eqq(GMPShim::testbit($a, 63), false);
        xassert_eqq(GMPShim::testbit($a, 64), false);
        xassert_eqq(GMPShim::scan1($a, 0), 10);
        xassert_eqq(GMPShim::scan1($a, 10), 10);
        xassert_eqq(GMPShim::scan1($a, 11), -1);
        GMPShim::setbit($a, 10, false);
        GMPShim::setbit($a, 63, true);
        GMPShim::setbit($a, 127, true);
        xassert_eqq(GMPShim::testbit($a, 0), false);
        xassert_eqq(GMPShim::testbit($a, 10), false);
        xassert_eqq(GMPShim::testbit($a, 63), true);
        xassert_eqq(GMPShim::testbit($a, 64), false);
        xassert_eqq(GMPShim::testbit($a, 127), true);
        xassert_eqq(GMPShim::scan1($a, 0), 63);
        xassert_eqq(GMPShim::scan1($a, 63), 63);
        xassert_eqq(GMPShim::scan1($a, 64), 127);
    }

    function test_json() {
        xassert_eqq(json_encode(Json::decode("{}")), "{}");
        xassert_eqq(Json::decode('"\\u0030"'), "0");
        xassert_eqq(json_encode(Json::decode('"\\u0030"')), '"0"');
        xassert_eqq(json_encode(Json::decode('""')), '""');
        xassert_eqq(json_encode(Json::decode('"')), 'null');
        xassert_eqq(json_encode(Json::decode('"\\""')), '"\\""');
        xassert_eqq(json_encode(Json::decode('"\\"\\\\"')), '"\\"\\\\"');
        xassert_eqq(json_encode(Json::decode('"\\"\\\\\\')), 'null');
        xassert_eqq(json_encode(Json::decode("null")), "null");
        xassert_eqq(json_encode(Json::decode("true")), "true");
        xassert_eqq(json_encode(Json::decode("false")), "false");
        xassert_eqq(Json::decode('"'), null);
        xassert_eqq(Json::decode("\"\r\n\""), null);
        xassert_eqq(Json::decode("\"\027\""), null);
        xassert_eqq(Json::encode("\n"), '"\\n"');
        xassert_eqq(Json::encode("\007"), '"\\u0007"');
        xassert_eqq(Json::encode("–"), '"–"');
        xassert_eqq(Json::decode(Json::encode("–")), "–");
        xassert_eqq(Json::decode(Json::encode("\xE2\x80\xA8\xE2\x80\xA9")), "\xE2\x80\xA8\xE2\x80\xA9");
        xassert_eqq(json_encode(Json::decode('{"1":"1"}')), '{"1":"1"}');
        xassert_eqq(json_encode(Json::decode('[1,"1"]   ')), '[1,"1"]');
        xassert_eqq(json_encode(Json::decode('[1,"1"]   !')), "null");

        $input = '{
    "a": ["b", "c"],
    "b": {
        "c": "d"
    }
}';
        $jp = (new JsonParser)->input($input)->flags(JSON_THROW_ON_ERROR)->filename("x.txt");
        xassert_eqq($jp->position_landmark(11), "x.txt:2:10");
        xassert_eqq($jp->path_landmark(" . a   "), "x.txt:2:10");
        xassert_eqq($jp->path_landmark("\$.a"), "x.txt:2:10");
        xassert_eqq($jp->path_landmark("b"), "x.txt:3:10");
        xassert_eqq($jp->path_landmark("a[0]"), "x.txt:2:11");
        xassert_eqq($jp->path_landmark("\$.a[0]"), "x.txt:2:11");
        xassert_eqq($jp->position_landmark(16), "x.txt:2:15");
        xassert_eqq($jp->path_landmark(".a.1"), "x.txt:2:16");
        xassert_eqq($jp->path_landmark("\$.a.1"), "x.txt:2:16");
        xassert_eqq($jp->path_landmark("[  \"b\"   ][   \"c\"]"), "x.txt:4:14");
        xassert_eqq($jp->path_landmark("\$[\"b\"].c"), "x.txt:4:14");
        xassert_eqq($jp->path_landmark("[  \"b\"   ][   \"c\"].d"), null);
        $jpp = $jp->path_position("\$");
        xassert_eqq($jpp->vpos1, 0);
        xassert_eqq($jpp->vpos2, strlen($input));

        xassert_eqq(JsonParser::path_push(null, 0), "\$[0]");
        xassert_eqq(JsonParser::path_push(null, "0"), "\$[0]");
        xassert_eqq(JsonParser::path_push(null, ""), "\$[\"\"]");
        xassert_eqq(JsonParser::path_push("\$[0]", "a"), "\$[0].a");
        xassert_eqq(JsonParser::path_push("\$[0]", "a\\"), "\$[0][\"a\\\\\"]");
        xassert_eqq(JsonParser::path_push("\$[0]", "\n"), "\$[0][\"\\n\"]");
    }

    function test_json5() {
        $jp = (new JsonParser)->assoc(true);
        $jp5 = (new JsonParser)->assoc(true)->flags(JsonParser::JSON5);

        xassert_eqq($jp->input("[\"a\"]")->decode(), ["a"]);
        xassert($jp->ok());
        xassert_eqq($jp5->input("[\"a\"]")->decode(), ["a"]);
        xassert($jp5->ok());
        xassert_eqq($jp->input("[\"a\",]")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("[\"a\",]")->decode(), ["a"]);
        xassert($jp5->ok());
        xassert_eqq($jp->input("[,]")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("[,]")->decode(), null);
        xassert(!$jp5->ok());

        xassert_eqq($jp->input("{\"a\":1}")->decode(), ["a" => 1]);
        xassert($jp->ok());
        xassert_eqq($jp5->input("{\"a\":1}")->decode(), ["a" => 1]);
        xassert($jp5->ok());
        xassert_eqq($jp->input("{\"a\":1,}")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("{\"a\":1,}")->decode(), ["a" => 1]);
        xassert($jp5->ok());
        xassert_eqq($jp->input("{\"b\",\"a\":1}")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("{\"b\",\"a\":1}")->decode(), null);
        xassert(!$jp5->ok());
        xassert_eqq($jp->input("{,}")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("{,}")->decode(), null);
        xassert(!$jp5->ok());

        xassert_eqq($jp->input("0x1A")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("0x1A")->decode(), 26);
        xassert($jp5->ok());
        xassert_eqq($jp->input("+12")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("+12")->decode(), 12);
        xassert($jp5->ok());

        xassert_eqq($jp->input("Infinity")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("Infinity")->decode(), INF);
        xassert($jp5->ok());

        xassert_eqq($jp->input("NaN")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("NaN")->decode(), NAN);
        xassert($jp5->ok());

        xassert_eqq($jp->input("// Comment\ntrue\n/* Yep */")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp5->input("// Comment\ntrue\n/* Yep */")->decode(), true);
        xassert($jp5->ok());

        xassert_eqq($jp5->input("'Hello.\t'")->decode(), "Hello.\t");
        xassert($jp5->ok());
        xassert_eqq($jp5->input("'Hello.\n'")->decode(), null);
        xassert(!$jp5->ok());
        xassert_eqq($jp5->input("'Hello.\\\n'")->decode(), "Hello.");
        xassert($jp5->ok());
        xassert_eqq($jp5->input("'Hello.\\\n\"'")->decode(), "Hello.\"");
        xassert($jp5->ok());
        xassert_eqq($jp5->input("\"Hello.\t'")->decode(), null);
        xassert(!$jp5->ok());
        xassert_eqq($jp5->input("\"Hello.\t\"")->decode(), "Hello.\t");
        xassert($jp5->ok());
        xassert_eqq($jp5->input("\"Hello.\n\"")->decode(), null);
        xassert(!$jp5->ok());
        xassert_eqq($jp5->input("\"Hello.\\\n\"")->decode(), "Hello.");
        xassert($jp5->ok());
        xassert_eqq($jp5->input("\"Hello.\\\n'\"")->decode(), "Hello.'");
        xassert($jp5->ok());

        xassert_eqq($jp->input("\"Hello.\t'")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp->input("\"Hello.\t\"")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp->input("\"Hello.\n\"")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp->input("\"Hello.\\\n\"")->decode(), null);
        xassert(!$jp->ok());
        xassert_eqq($jp->input("\"Hello.\\\n'\"")->decode(), null);
        xassert(!$jp->ok());

        xassert_eqq($jp5->input("{a:1}")->decode(), ["a" => 1]);
        xassert($jp5->ok());
        xassert_eqq($jp->input("{a:1}")->decode(), null);
        xassert(!$jp->ok());
    }

    /** @param JsonParser $jp
     * @param string $input
     * @return list<JsonParserPosition> */
    private function get_member_positions($jp, $input) {
        $jpps = [];
        foreach ($jp->input($input)->member_positions(0) as $jpp) {
            $jpps[] = $jpp;
        }
        return $jpps;
    }

    function test_json_member_positions() {
        $jp = new JsonParser;
        xassert_eqq(json_encode($this->get_member_positions($jp, "    null ")),
                    '[[null,null,null,4,8]]');
        xassert_eqq(json_encode($this->get_member_positions($jp, "[  1   ,\n\n3,4] ")),
                    '[[0,null,null,3,4],[1,null,null,10,11],[2,null,null,12,13]]');
        xassert_eqq(json_encode($this->get_member_positions($jp, "{    \"a\": {\"b\":\"c\",\"d\":1001010101010101} } ")),
                    '[["a",5,8,10,40]]');
    }

    function test_object_replace_recursive() {
        $obj = (object) [];
        object_replace_recursive($obj, ["a" => 1]);
        xassert_eqq(json_encode($obj), '{"a":1}');

        object_replace_recursive($obj, (object) ["a" => 2]);
        xassert_eqq(json_encode($obj), '{"a":2}');

        $obj->a = 1;
        object_replace_recursive($obj, ["a" => 2]);
        xassert_eqq(json_encode($obj), '{"a":2}');

        $obj->a = 1;
        object_replace_recursive($obj, ["a" => null]);
        xassert_eqq(json_encode($obj), '{}');
    }

    function test_json_object_replace_recursive() {
        xassert_eqq(json_object_replace_recursive(null, ["a" => 1]), '{"a":1}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', (object) ["a" => 2]), '{"a":2}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', (object) ["b" => 2]), '{"a":1,"b":2}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', (object) ["b" => 2, OBJECT_REPLACE_NO_RECURSE => true]), '{"b":2}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', (object) ["b" => 2, OBJECT_REPLACE_NO_RECURSE => false]), '{"a":1,"b":2}');
        xassert_eqq(json_object_replace_recursive('{"a":1,"b":{"x":1},"c":{"y":1}}', (object) ["b" => ["y" => 2], "c" => [OBJECT_REPLACE_NO_RECURSE => true]]), '{"a":1,"b":{"x":1,"y":2},"c":{}}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', ["a" => 2]), '{"a":2}');
        xassert_eqq(json_object_replace_recursive('{"a":1}', ["a" => null]), null);
    }

    function test_json_encode_browser_db() {
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
    }

    function test_session_list_ids() {
        xassert_eqq(Json::decode("[1-2]"), null);
        xassert_eqq(json_decode("[1-2]"), null);
        xassert_eqq(Json::decode("[1,2,3-4,5,6-10,11]"), null);
        xassert_eqq(json_decode("[1,2,3-4,5,6-10,11]"), null);

        xassert_eqq(SessionList::decode_ids("[1-2]"), [1,2]);
        xassert_eqq(SessionList::decode_ids("1 2"), [1,2]);
        xassert_eqq(SessionList::decode_ids("[1,2,3-4,5,6-10,11]"), [1,2,3,4,5,6,7,8,9,10,11]);
        xassert_eqq(SessionList::decode_ids("1 2 3 4 5 6 7 8 9 10 11"), [1,2,3,4,5,6,7,8,9,10,11]);
        xassert_eqq(SessionList::decode_ids(SessionList::encode_ids([1,2])), [1,2]);
        xassert_eqq(SessionList::decode_ids(SessionList::encode_ids([1,2,3,4,5,6,7,8,9,10,11])), [1,2,3,4,5,6,7,8,9,10,11]);
        xassert_eqq(SessionList::decode_ids(SessionList::encode_ids([1,3,5,7,9,10,11])), [1,3,5,7,9,10,11]);
        xassert_eqq(SessionList::decode_ids(SessionList::encode_ids([11,10,9,8,7,6,5,4,3,2,1])), [11,10,9,8,7,6,5,4,3,2,1]);
        xassert_eqq(SessionList::decode_ids(SessionList::encode_ids([10,9,7,1,3,5,5])), [10,9,7,1,3,5,5]);

        // check some old-style decodings
        xassert_eqq(SessionList::decode_ids("1z20zz34"), [1,20,34]);
        xassert_eqq(SessionList::decode_ids("10zjh"), [10,9,8,7,6,5,4,3,2]);
        xassert_eqq(SessionList::decode_ids("10Zh"), [10,9,8,7,6,5,4,3,2]);
        xassert_eqq(SessionList::decode_ids("10Zc"), [10,9,8,7]);
        xassert_eqq(SessionList::decode_ids("10ZCJa"), [10,9,8,7,5,4,1]);
        xassert_eqq(SessionList::decode_ids("45b"), [45,46,47]);
        xassert_eqq(SessionList::decode_ids("68q11Zzbzib99g74Zzf11Za33"), SessionList::decode_ids("[68-79,78-79,79,78,99-106,74,73-78,11,10,33]"));

        // check for short encodings
        xassert(strlen(SessionList::encode_ids([10,9,8,7])) <= 5);
        xassert(strlen(SessionList::encode_ids([10,9,8,7,5,4,1])) <= 7);
    }

    /** @return list<int> */
    function random_paper_ids() {
        $a = [];
        $n = mt_rand(1, 10);
        $p = null;
        for ($i = 0; $i < $n; ++$i) {
            $p1 = mt_rand(1, $p === null ? 100 : 150);
            if ($p1 > 100) {
                $p1 = max(1, (int) $p + (int) round(($p1 - 125) / 8));
            }
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

    function test_random_session_list_ids() {
        for ($i = 0; $i < 1000; ++$i) {
            $ids = $this->random_paper_ids();
            //file_put_contents("/tmp/x", "if (JSON.stringify(decode_ids(" . json_encode(SessionList::encode_ids($ids)) . ")) !== " . json_encode(json_encode($ids)) . ") throw new Error;\n", FILE_APPEND);
            $encoded_ids = SessionList::encode_ids($ids);
            $decoded_ids = SessionList::decode_ids($encoded_ids);
            if ($ids !== $decoded_ids) {
                xassert_eqq(json_encode($ids), json_encode($decoded_ids));
                error_log("! Encoded version: $encoded_ids");
            }
        }
    }

    function test_obscure_time() {
        $t = $this->conf->parse_time("Sep 1, 2010 00:00:01");
        $t0 = $this->conf->obscure_time($t);
        xassert_eqq($this->conf->unparse_time_obscure($t0), "Sep 1, 2010");
        xassert_eqq($this->conf->unparse_time($t0), "Sep 1, 2010, 12 PM EDT");

        $t = $this->conf->parse_time("Sep 1, 2010 23:59:59");
        $t0 = $this->conf->obscure_time($t);
        xassert_eqq($this->conf->unparse_time_obscure($t0), "Sep 1, 2010");
        xassert_eqq($this->conf->unparse_time($t0), "Sep 1, 2010, 12 PM EDT");
    }

    function test_timezones() {
        $t = $this->conf->parse_time("29 May 2018 11:00:00 EDT");
        xassert_eqq($t, 1527606000);
        $t = $this->conf->parse_time("May 29, 2018 11:00:00 EDT");
        xassert_eqq($t, 1527606000);
        $t = $this->conf->parse_time("May 29, 2018 03:00:00 AoE");
        xassert_eqq($t, 1527606000);
        $this->conf->set_opt("timezone", "Etc/GMT+12");
        $this->conf->refresh_options();
        $this->conf->refresh_globals();
        $t = $this->conf->parse_time("May 29, 2018 03:00:00");
        xassert_eqq($t, 1527606000);
        $t = $this->conf->unparse_time(1527606000);
        xassert_eqq($t, "May 29, 2018, 3 AM AoE");
        $t = $this->conf->parse_time("May 29, 2018, 23:59:59 AoE");
        xassert_eqq($t, 1527681599);
        $t = $this->conf->parse_time("May 29, 2018 AoE");
        xassert_eqq($t, 1527681599);
        $t = $this->conf->parse_time("29 May 2018 AoE");
        xassert_eqq($t, 1527681599);
        $t = $this->conf->parse_time("May 29, 2018 12 AM AoE");
        xassert_eqq($t, 1527595200);
        $t = $this->conf->parse_time("May 29, 2018 12am AoE");
        xassert_eqq($t, 1527595200);
        $t = $this->conf->parse_time("29 May 2018 12 AM AoE");
        xassert_eqq($t, 1527595200);
        $t = $this->conf->parse_time("29 May 2018 12am AoE");
        xassert_eqq($t, 1527595200);
        $t = $this->conf->parse_time("May 29 AoE", 1527606000);
        xassert_eqq($t, 1527681599);
        $t = $this->conf->parse_time("29 May AoE", 1527606000);
        xassert_eqq($t, 1527681599);
    }

    function test_tagger_checks() {
        $tagger = new Tagger($this->conf->root_user());
        xassert_eqq($tagger->check("none", 0), false);
        xassert_eqq($tagger->check("any", 0), false);
        xassert_eqq($tagger->check("undefined", 0), false);
        xassert_eqq($tagger->check("none", Tagger::ALLOWRESERVED), "none");
        xassert_eqq($tagger->check("any", Tagger::ALLOWRESERVED), "any");
        xassert_eqq($tagger->check("undefined", Tagger::ALLOWRESERVED), "undefined");
        xassert_eqq($tagger->check("~none", 0), false);
        xassert_eqq($tagger->check("~any", 0), false);
        xassert_eqq($tagger->check("~undefined", 0), false);
        xassert_eqq($tagger->check("~~none", 0), false);
        xassert_eqq($tagger->check("~~any", 0), false);
        xassert_eqq($tagger->check("~~undefined", 0), false);
        xassert_eqq($tagger->check("hello", 0), "hello");
        xassert_eqq($tagger->check("hello#0", 0), "hello#0");
        xassert_eqq($tagger->check("~hello", 0), "~hello");
        xassert_eqq($tagger->check("~hello#0", 0), "~hello#0");
        xassert_eqq($tagger->check("~~hello", 0), "~~hello");
        xassert_eqq($tagger->check("~~hello#0", 0), "~~hello#0");
        xassert_eqq($tagger->check("hello", Tagger::NOVALUE), "hello");
        xassert_eqq($tagger->check("hello#0", Tagger::NOVALUE), false);
        xassert_eqq($tagger->check("~hello", Tagger::NOVALUE), "~hello");
        xassert_eqq($tagger->check("~hello#0", Tagger::NOVALUE), false);
        xassert_eqq($tagger->check("~~hello", Tagger::NOVALUE), "~~hello");
        xassert_eqq($tagger->check("~~hello#0", Tagger::NOVALUE), false);
        xassert_eqq($tagger->check("hello", Tagger::NOPRIVATE), "hello");
        xassert_eqq($tagger->check("hello#0", Tagger::NOPRIVATE), "hello#0");
        xassert_eqq($tagger->check("~hello", Tagger::NOPRIVATE), false);
        xassert_eqq($tagger->check("~hello#0", Tagger::NOPRIVATE), false);
        xassert_eqq($tagger->check("~~hello", Tagger::NOPRIVATE), "~~hello");
        xassert_eqq($tagger->check("~~hello#0", Tagger::NOPRIVATE), "~~hello#0");
        xassert_eqq($tagger->check("hello", Tagger::NOCHAIR), "hello");
        xassert_eqq($tagger->check("hello#0", Tagger::NOCHAIR), "hello#0");
        xassert_eqq($tagger->check("~hello", Tagger::NOCHAIR), "~hello");
        xassert_eqq($tagger->check("~hello#0", Tagger::NOCHAIR), "~hello#0");
        xassert_eqq($tagger->check("~~hello", Tagger::NOCHAIR), false);
        xassert_eqq($tagger->check("~~hello#0", Tagger::NOCHAIR), false);
    }

    function test_ordinal() {
        xassert_eqq(ordinal(0), "0th");
        xassert_eqq(ordinal(1), "1st");
        xassert_eqq(ordinal(2), "2nd");
        xassert_eqq(ordinal(3), "3rd");
        xassert_eqq(ordinal(4), "4th");
        xassert_eqq(ordinal(10), "10th");
        xassert_eqq(ordinal(11), "11th");
        xassert_eqq(ordinal(20), "20th");
        xassert_eqq(ordinal(21), "21st");
        xassert_eqq(ordinal(100), "100th");
        xassert_eqq(ordinal(101), "101st");
        xassert_eqq(ordinal(-1), "-1st");
    }

    function test_review_ordinals() {
        foreach ([1 => "A", 26 => "Z", 27 => "AA", 28 => "AB", 51 => "AY", 52 => "AZ",
                  53 => "BA", 54 => "BB", 702 => "ZZ", 703 => "AAA", 704 => "AAB",
                  1378 => "AZZ", 1379 => "BAA"] as $n => $t) {
            xassert_eqq(unparse_latin_ordinal($n), $t);
            xassert_eqq(parse_latin_ordinal($t), $n);
        }
    }

    function test_plural() {
        xassert_eqq(plural_word(1, "that"), "that");
        xassert_eqq(plural_word(1, "that butt"), "that butt");
        xassert_eqq(plural_word(2, "that"), "those");
        xassert_eqq(plural_word(2, "that butt"), "those butts");
        xassert_eqq(plural_word(2, "this"), "these");
        xassert_eqq(plural_word(2, "this butt"), "these butts");
        xassert_eqq(plural_word(2, "day"), "days");
        xassert_eqq(plural_word(2, "ply"), "plies");
        xassert_eqq(plural_word(2, "worth"), "worths");
        xassert_eqq(plural_word(2, "hutch"), "hutches");
        xassert_eqq(plural_word(2, "ass"), "asses");
        xassert_eqq(plural_word(1, "ass", ""), "ass");
        xassert_eqq(plural_word(2, "ass", ""), "asses");
        xassert_eqq(plural_word(1, "ass", "fun"), "ass");
        xassert_eqq(plural_word(2, "ass", "fun"), "fun");
    }

    function test_parse_interval() {
        xassert_eqq(SettingParser::parse_interval("2y"), 86400 * 365 * 2.0);
        xassert_eqq(SettingParser::parse_interval("15m"), 60 * 15.0);
        xassert_eqq(SettingParser::parse_interval("1h15m"), 60 * 75.0);
        xassert_eqq(SettingParser::parse_interval("1h15mo"), false);
    }

    function test_parse_preference() {
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
    }

    function test_span_balanced_parens() {
        xassert_eqq(SearchSplitter::span_balanced_parens("abc def"), 3);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc() def"), 5);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc()def ghi"), 8);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc(def g)hi"), 12);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc(def g)hi jk"), 12);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc(def g)h)i jk"), 11);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc(def [g)h)i jk"), 12);
        xassert_eqq(SearchSplitter::span_balanced_parens("abc(def sajf"), 12);

        $m = SearchSplitter::split_balanced_parens(" a(b) )c");
        xassert_array_eqq($m, ["a(b)", ")c"]);
    }

    function test_unpack_comparison() {
        xassert_eqq(CountMatcher::unpack_comparison("x:2"), ["x", 2, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x:2."), ["x", 2, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x:=2"), ["x", 2, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x: = 2"), ["x", 2, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x:== 2"), ["x", 2, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x:!= 2"), ["x", 5, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x ≠ 2"), ["x", 5, 2.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x:≥ 200"), ["x", 6, 200.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x≥ 200"), ["x", 6, 200.0]);
        xassert_eqq(CountMatcher::unpack_comparison("x 200"), null);
    }

    function test_flip_relation() {
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELEQ), CountMatcher::RELEQ);
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELNE), CountMatcher::RELNE);
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELGT), CountMatcher::RELLT);
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELLT), CountMatcher::RELGT);
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELGE), CountMatcher::RELLE);
        xassert_eqq(CountMatcher::flip_relation(CountMatcher::RELLE), CountMatcher::RELGE);
        xassert_eqq(CountMatcher::flip_unparsed_relation("="), "=");
        xassert_eqq(CountMatcher::flip_unparsed_relation("=="), "==");
        xassert_eqq(CountMatcher::flip_unparsed_relation("≤"), ">=");
    }

    function test_simplify_whitespace() {
        xassert_eqq(simplify_whitespace("abc def GEH îjk"), "abc def GEH îjk");
        xassert_eqq(simplify_whitespace("\x7Fabc\x7Fdef       GEH îjk"), "abc def GEH îjk");
        xassert_eqq(simplify_whitespace("A.\n\n\x1DEEE MM\n\n\n\n"), "A. EEE MM");
    }

    function test_utf8_prefix() {
        xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 7), "aaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 8), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_prefix("aaaaaaaa", 9), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 7), "ááááááá");
        xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 8), "áááááááá");
        xassert_eqq(UnicodeHelper::utf8_prefix("áááááááá", 9), "áááááááá");
        xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 7), "a̓a̓a̓a̓a̓a̓a̓");
        xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 8), "a̓a̓a̓a̓a̓a̓a̓a̓");
        xassert_eqq(UnicodeHelper::utf8_prefix("a̓a̓a̓a̓a̓a̓a̓a̓", 9), "a̓a̓a̓a̓a̓a̓a̓a̓");

        xassert_eqq(UnicodeHelper::utf8_word_prefix("a aaaaaaabbb", 7), "a");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 7), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 8), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 9), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("aaaaaaaa bbb", 10), "aaaaaaaa");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("\xCC\x90_\xCC\x8E", 1), "\xCC\x90_\xCC\x8E");
        xassert_eqq(UnicodeHelper::utf8_word_prefix("\xCC\x90_ \xCC\x8E", 1), "\xCC\x90_");
    }

    function test_utf8_line_break() {
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("a aaaaaaabbb", 7), ["a", "aaaaaaabbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 7), ["aaaaaaaa", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 8), ["aaaaaaaa", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 9), ["aaaaaaaa", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 10), ["aaaaaaaa", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("a\naaaaaa bbb", 10), ["a", "aaaaaa bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("a aaaaaaabbb", 7), ["a", "aaaaaaabbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 7, true), ["aaaaaaaa ", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 8, true), ["aaaaaaaa ", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa   bbb", 9, true), ["aaaaaaaa   ", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("aaaaaaaa bbb", 10, true), ["aaaaaaaa ", "bbb"]);
        xassert_eqq(UnicodeHelper::utf8_line_break_parts("a\naaaaaa bbb", 10), ["a", "aaaaaa bbb"]);
    }

    function test_utf8_glyphlen() {
        xassert_eqq(UnicodeHelper::utf8_glyphlen("aaaaaaaa"), 8);
        xassert_eqq(UnicodeHelper::utf8_glyphlen("áááááááá"), 8);
        xassert_eqq(UnicodeHelper::utf8_glyphlen("a̓a̓a̓a̓a̓a̓a̓a̓"), 8);
    }

    function test_utf8_demojibake() {
        xassert_eqq(UnicodeHelper::demojibake("å"), "å");
        xassert_eqq(UnicodeHelper::demojibake("Â£"), "£");
        xassert_eqq(UnicodeHelper::demojibake("Â£"), "£");
        xassert_eqq(UnicodeHelper::demojibake("LÃ¡szlÃ³ MolnÃ¡r"), "László Molnár");
        xassert_eqq(UnicodeHelper::demojibake("László Molnár"), "László Molnár");
    }

    function test_utf8_invalid() {
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
    }

    function test_utf8_entities() {
        xassert_eqq(UnicodeHelper::utf8_to_html_entities("<!á&"), "&lt;!&aacute;&amp;");
        xassert_eqq(UnicodeHelper::utf8_to_html_entities("<!á&", ENT_XML1), "&lt;!&#225;&amp;");
        xassert_eqq(UnicodeHelper::utf8_to_xml_numeric_entities("<!á&"), "&#60;!&#225;&#38;");
    }

    function test_utf8_deaccent() {
        xassert_eqq(UnicodeHelper::deaccent("Á é î ç ø U"), "A e i c o U");
        $do = UnicodeHelper::deaccent_offsets("Á é î ç ø U .\xE2\x84\xAA");
        xassert_eqq($do[0], "A e i c o U .K");
        xassert_eqq(json_encode($do[1]), "[0,0,1,2,3,5,5,8,7,11,9,14,14,21]");
        xassert_eqq(UnicodeHelper::deaccent_translate_offset($do[1], 4), 6);
        $regex = new TextPregexes(Text::word_regex("foo"), Text::utf8_word_regex("foo"));
        xassert_eqq(Text::highlight("Is foo bar føo bar fóó bar highlit right? foö", $regex),
                    "Is <span class=\"match\">foo</span> bar <span class=\"match\">føo</span> bar <span class=\"match\">fóó</span> bar highlit right? <span class=\"match\">foö</span>");
        xassert_eqq(UnicodeHelper::remove_f_ligatures("Héllo ﬀ,ﬁ:fi;ﬂ,ﬃ:ﬄ-ﬅ"), "Héllo ff,fi:fi;fl,ffi:ffl-ﬅ");
    }

    function test_prefix_word_wrap() {
        xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 10),
                    "+ This is\n- a thing\n- to be\n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 9),
                    "+ This is\n- a thing\n- to be\n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ ", "This\nis\na thing\nto\nbe wrapped.", "- ", 9),
                    "+ This\n- is\n- a thing\n- to\n- be\n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 10, true),
                    "+ This is \n- a thing \n- to be \n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ ", "This is a thing to be wrapped.", "- ", 9, true),
                    "+ This is \n- a thing \n- to be \n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ ", "This\nis\na thing\nto\nbe wrapped.", "- ", 9, true),
                    "+ This\n- is\n- a thing\n- to\n- be \n- wrapped.\n");
        xassert_eqq(prefix_word_wrap("+ long lengthy long long ", "This is a thing to be wrapped.", "- ", 10),
                    "+ long lengthy long long\n- This is\n- a thing\n- to be\n- wrapped.\n");
    }

    function test_star_text_pregexes() {
        $pregex = Text::star_text_pregexes("foo");
        xassert(Text::match_pregexes($pregex, "foo", null));
        xassert(Text::match_pregexes($pregex, "foo", "foo"));
        xassert(Text::match_pregexes($pregex, "fóo", "foo"));
        xassert(!Text::match_pregexes($pregex, "foobar", null));
        xassert(!Text::match_pregexes($pregex, "foobar", "foobar"));
        xassert(!Text::match_pregexes($pregex, "fóobar", "foobar"));

        $pregex = Text::star_text_pregexes("foo*");
        xassert(Text::match_pregexes($pregex, "foo", null));
        xassert(Text::match_pregexes($pregex, "foo", "foo"));
        xassert(Text::match_pregexes($pregex, "fóo", "foo"));
        xassert(Text::match_pregexes($pregex, "foobar", null));
        xassert(Text::match_pregexes($pregex, "foobar", "foobar"));
        xassert(Text::match_pregexes($pregex, "fóobar", "foobar"));
        xassert(!Text::match_pregexes($pregex, "ffoobar", null));
        xassert(!Text::match_pregexes($pregex, "ffoobar", "ffoobar"));
        xassert(!Text::match_pregexes($pregex, "ffóobar", "ffoobar"));

        $pregex = Text::star_text_pregexes("foo@butt.com");
        xassert(Text::match_pregexes($pregex, "it's foo@butt.com and friends", null));
        xassert(Text::match_pregexes($pregex, "it's foo@butt.com and friends", "it's foo@butt.com and friends"));
        xassert(Text::match_pregexes($pregex, "it's fóo@butt.com and friends", "it's foo@butt.com and friends"));
    }

    function test_simple_search() {
        xassert_eqq(Text::simple_search("yes", ["yes", "no", "yes-really"]), ["yes"]);
        xassert_eqq(Text::simple_search("yes", ["yes", "no", "yes-really"], Text::SEARCH_UNPRIVILEGE_EXACT), ["yes", 2 => "yes-really"]);
        xassert_eqq(Text::simple_search("yes", ["yes-maybe", "no", "yes-really"], 0), ["yes-maybe", 2 => "yes-really"]);
        xassert_eqq(Text::simple_search("Yes", ["yes", "no", "yes-really"]), ["yes"]);
        xassert_eqq(Text::simple_search("Yes", ["yes", "no", "yes-really"], Text::SEARCH_UNPRIVILEGE_EXACT), ["yes", 2 => "yes-really"]);
        xassert_eqq(Text::simple_search("Yes", ["yes-maybe", "no", "yes-really"], 0), ["yes-maybe", 2 => "yes-really"]);

        $acm_badge_opts = ["x" => "None", "a" => "ACM badges: available", "af" => "ACM badges: available, functional", "afr" => "ACM badges: available, functional, replicated", "ar" => "ACM badges: available, reusable", "arr" => "ACM badges: available, reusable, replicated", "f" => "ACM badges: functional", "fr" => "ACM badges: functional, replicated", "r" => "ACM badges: reusable", "rr" => "ACM badges: reusable, replicated"];
        xassert_eqq(array_keys(Text::simple_search("ACM badges: available, functional, replicated", $acm_badge_opts)), ["afr"]);
        xassert_eqq(array_keys(Text::simple_search("ACM badges: functional, replicated", $acm_badge_opts)), ["fr"]);
        xassert_eqq(array_keys(Text::simple_search("ACM badges: available, functional, replicated", $acm_badge_opts, Text::SEARCH_UNPRIVILEGE_EXACT)), ["afr"]);
        xassert_eqq(array_keys(Text::simple_search("ACM badges: functional, replicated", $acm_badge_opts, Text::SEARCH_UNPRIVILEGE_EXACT)), ["afr", "fr"]);
    }

    function test_review_search_split() {
        xassert_eqq(Review_SearchTerm::split("butt>2:foo:3"), ["butt", ">2", "foo", "3"]);
        xassert_eqq(Review_SearchTerm::split("butt>2:foo:3>=2"), ["butt", ">2", "foo", "3", ">=2"]);
    }

    function test_count_matcher() {
        xassert_eqq(CountMatcher::filter_using([0, 1, 2, 3], ">0"), [1 => 1, 2 => 2, 3 => 3]);
        xassert_eqq(CountMatcher::filter_using([3, 2, 1, 0], [1]), [2 => 1]);
        xassert_eqq(CountMatcher::filter_using([10, 11, -10], "≤10"), [0 => 10, 2 => -10]);
    }

    function test_qrequest() {
        $q = new Qrequest("GET", ["a" => 1, "b" => 2]);
        xassert_eqq($q->a, 1);
        xassert_eqq($q->b, 2);
        xassert_eqq(count($q), 2);
        xassert_eqq($q->c, null);
        xassert_eqq(count($q), 2);
        $q->c = "s";
        xassert_eqq(count($q), 3);
        xassert_eqq(json_encode($q->c), "\"s\"");
        xassert_eqq(json_encode($q->d), "null");
        xassert_eqq(count($q), 3);
        xassert_eqq(json_encode($q), "{\"a\":1,\"b\":2,\"c\":\"s\"}");
        xassert_eqq(Json::encode($q), "{\"a\":1,\"b\":2,\"c\":\"s\"}");
    }

    function test_is_anonymous_email() {
        xassert(Contact::is_anonymous_email("anonymous"));
        xassert(Contact::is_anonymous_email("anonymous1"));
        xassert(Contact::is_anonymous_email("anonymous10"));
        xassert(Contact::is_anonymous_email("anonymous9"));
        xassert(!Contact::is_anonymous_email("anonymous@example.com"));
        xassert(!Contact::is_anonymous_email("example@anonymous"));
    }

    function test_valid_email() {
        xassert(Contact::make_email($this->conf, "ass@butt.com")->can_receive_mail());
        xassert(Contact::make_email($this->conf, "ass@fxample.edu")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@_.com")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@_.co.uk")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@example.com")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@example.org")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@example.net")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@Example.com")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@Example.ORG")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@Example.net")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@example.edu")->can_receive_mail());
        xassert(!Contact::make_email($this->conf, "ass@fart.example.edu")->can_receive_mail());
    }

    function test_sensitive_mail_preparation() {
        $prep1 = new MailPreparation($this->conf, Contact::make_email($this->conf, "ass@butt.com"));
        $prep2 = new MailPreparation($this->conf, Contact::make_email($this->conf, "ass@example.edu"));
        $prep1->sensitive = $prep2->sensitive = true;
        xassert(!$this->conf->opt("sendEmail") && $this->conf->opt("debugShowSensitiveEmail"));
        $this->conf->set_opt("sendEmail", true);
        $this->conf->set_opt("debugShowSensitiveEmail", false);
        xassert($prep1->can_send());
        xassert($prep2->can_send());
        $this->conf->set_opt("sendEmail", false);
        xassert(!$prep1->can_send());
        xassert(!$prep2->can_send());
        $this->conf->set_opt("debugShowSensitiveEmail", true);
    }

    function test_clean_html() {
        xassert_eqq(CleanHTML::basic_clean('<a>Hello'), false);
        xassert_eqq(CleanHTML::basic_clean('<a>Hello</a>'), '<a>Hello</a>');
        xassert_eqq(CleanHTML::basic_clean('<script>Hello</script>'), false);
        xassert_eqq(CleanHTML::basic_clean('< SCRIPT >Hello</script>'), false);
        xassert_eqq(CleanHTML::basic_clean('<a href = fuckovia ><B>Hello</b></a>'), '<a href="fuckovia"><b>Hello</b></a>');
        xassert_eqq(CleanHTML::basic_clean('<a href = " javaScript:hello" ><B>Hello</b></a>'), false);
        xassert_eqq(CleanHTML::basic_clean('<a href = "https://hello" onclick="fuck"><B>Hello</b></a>'), false);
        xassert_eqq(CleanHTML::basic_clean('<a href =\'https:"""//hello\' butt><B>Hello</b></a>'), '<a href="https:&quot;&quot;&quot;//hello" butt><b>Hello</b></a>');
    }

    function test_base48() {
        for ($i = 0; $i !== 1000; ++$i) {
            $n = mt_rand(0, 99);
            $b = $n === 0 ? "" : random_bytes($n);
            $t = base48_encode($b);
            xassert(strspn($t, "abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXY") === strlen($t));
            xassert_eqq(base48_decode($t), $b);
        }
    }

    function test_mailer_expand() {
        $mailer = new HotCRPMailer($this->conf, null, ["width" => false]);
        xassert_eqq($mailer->expand("%CONFNAME%//%CONFLONGNAME%//%CONFSHORTNAME%"),
            "Test Conference I (Testconf I)//Test Conference I//Testconf I\n");
        xassert_eqq($mailer->expand("%SITECONTACT%//%ADMINEMAIL%"),
            "Eddie Kohler <ekohler@hotcrp.lcdf.org>//ekohler@hotcrp.lcdf.org\n");
        xassert_eqq($mailer->expand("%URLENC(ADMINEMAIL)% : %OPT(ADMINEMAIL)% : %OPT(NULL)% : %OPT(EMAIL)%"),
            "ekohler%40hotcrp.lcdf.org : ekohler@hotcrp.lcdf.org :  : %OPT(EMAIL)%\n");
        $mailer->reset(null, [
            "requester_contact" => Author::make_tabbed("Bob\tJones\tbobjones@a.com"),
            "reviewer_contact" => Author::make_tabbed("France \"Butt\"\tvon Ranjanath\tvanraj@b.com"),
            "other_contact" => Author::make_email("noname@c.com")
        ]);
        xassert_eqq($mailer->expand("%REQUESTERFIRST%"), "Bob\n");
        xassert_eqq($mailer->expand("%REQUESTERFIRST%", "to"), "Bob");
        xassert_eqq($mailer->expand("%REQUESTERNAME%"), "Bob Jones\n");
        xassert_eqq($mailer->expand("%REQUESTERNAME%", "to"), "Bob Jones");
        xassert_eqq($mailer->expand("%REVIEWERFIRST%"), "France \"Butt\"\n");
        xassert_eqq($mailer->expand("%REVIEWERFIRST%", "to"), "\"France \\\"Butt\\\"\"");
        xassert_eqq($mailer->expand("%REVIEWERNAME%"), "France \"Butt\" von Ranjanath\n");
        xassert_eqq($mailer->expand("%REVIEWERNAME%", "to"), "\"France \\\"Butt\\\" von Ranjanath\"");
        xassert_eqq($mailer->expand("%OTHERNAME%"), "noname@c.com\n");
        xassert_eqq($mailer->expand("%OTHERNAME%", "to"), "");
        xassert_eqq($mailer->expand("%OTHERCONTACT%"), "noname@c.com\n");
        xassert_eqq($mailer->expand("%OTHERCONTACT%", "to"), "noname@c.com");

        xassert_eqq($mailer->expand(" %IF(NULL)%A%ELSE%Y%ENDIF%"), " Y\n");
        xassert_eqq($mailer->expand(" %IF(CONFLONGNAME)%A%ELSE%Y%ENDIF%"), " A\n");
        xassert_eqq($mailer->expand(" {{IF(NULL)}}A{{ELSE}}Y{{ENDIF}}"), " Y\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}}A{{ELSE}}Y{{ENDIF}}"), " {{IF(UNKNOWN)}}A{{ELSE}}Y{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}}A{{ELIF(NULL)}}Y{{ENDIF}}"), " {{IF(UNKNOWN)}}A{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(CONFLONGNAME)}}A{{ELIF(UNKNOWN)}}Y{{ENDIF}}"), " A\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}}A{{ELIF(CONFLONGNAME)}}Y{{ELIF(BOO)}}Z{{ENDIF}}"), " {{IF(UNKNOWN)}}A{{ELSE}}Y{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(CONFLONGNAME)}}{{IF(UNKNOWN)}}A{{ELSE}}B{{ENDIF}}{{ELIF(UNKNOWN)}}Y{{ENDIF}}"), " {{IF(UNKNOWN)}}A{{ELSE}}B{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}}{{IF(CONFLONGNAME)}}A{{ELSE}}B{{ENDIF}}{{ELIF(UNKNOWN2)}}Y{{ENDIF}}"), " {{IF(UNKNOWN)}}A{{ELIF(UNKNOWN2)}}Y{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}} a {{ELIF(NULL)}} b {{ELIF(NULL)}} c {{ELSE}} d {{ENDIF}} "),
            " {{IF(UNKNOWN)}} a {{ELSE}} d {{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(UNKNOWN)}} a {{ELIF(NULL)}} b {{ELIF(NULL)}} c {{ENDIF}} "),
            " {{IF(UNKNOWN)}} a {{ENDIF}}\n");
        xassert_eqq($mailer->expand(" {{IF(NULL)}}A{{ELIF(UNKNOWN)}}Y{{ENDIF}}"), " {{IF(UNKNOWN)}}Y{{ENDIF}}\n");
        xassert_eqq($mailer->expand(" %IF(NULL)%A%ELIF(UNKNOWN)%Y%ENDIF%"), " %IF(UNKNOWN)%Y%ENDIF%\n");
        xassert_eqq($mailer->expand(" %IF(NULL)%A%ELIF(UNKNOWN)%Y"), " %IF(UNKNOWN)%Y%ENDIF%\n");
        xassert_eqq($mailer->expand("Hello\n%IF(CONFLONGNAME)%\nHello\n\n%ENDIF%\n\nGoodbye\n"),
            "Hello\nHello\n\nGoodbye\n");
    }

    function test_collator() {
        $collator = $this->conf->collator();
        xassert($collator->compare("aé", "af") < 0);
        xassert($collator->compare("ad", "aé") < 0);
        xassert($collator->compare("é", "F") < 0);
        xassert($collator->compare("D", "é") < 0);
        xassert($collator->compare("É", "f") < 0);
        xassert($collator->compare("d", "É") < 0);
    }

    function test_tag_sort() {
        $dt = $this->conf->tags();
        xassert_eqq($dt->sort_string(""), "");
        xassert_eqq($dt->sort_string(" a"), " a");
        xassert_eqq($dt->sort_string(" a1 a10 a100 a2"), " a1 a2 a10 a100");
    }

    function test_ini_get_bytes() {
        xassert_eqq(ini_get_bytes("", "1"), 1);
        xassert_eqq(ini_get_bytes("", "1 M"), 1 * (1 << 20));
        xassert_eqq(ini_get_bytes("", "1.2k"), 1229);
        xassert_eqq(ini_get_bytes("", "20G"), 20 * (1 << 30));
    }

    function test_split_name() {
        xassert_eqq((Text::split_name("Bob Kennedy"))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy"))[1], "Kennedy");
        xassert_eqq((Text::split_name("Bob Kennedy (Butt Pants)"))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy (Butt Pants)"))[1], "Kennedy (Butt Pants)");
        xassert_eqq((Text::split_name("Bob Kennedy, Esq."))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy, Esq."))[1], "Kennedy, Esq.");
        xassert_eqq((Text::split_name("Bob Kennedy, Esq. (Butt Pants)"))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy, Esq. (Butt Pants)"))[1], "Kennedy, Esq. (Butt Pants)");
        xassert_eqq((Text::split_name("Bob Kennedy, Jr., Esq."))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy, Jr., Esq."))[1], "Kennedy, Jr., Esq.");
        xassert_eqq((Text::split_name("Bob Kennedy, Jr., Esq. (Butt Pants)"))[0], "Bob");
        xassert_eqq((Text::split_name("Bob Kennedy, Jr., Esq. (Butt Pants)"))[1], "Kennedy, Jr., Esq. (Butt Pants)");
        xassert_eqq((Text::split_name("Kennedy, Bob, Jr., Esq."))[0], "Bob");
        xassert_eqq((Text::split_name("Kennedy, Bob, Jr., Esq."))[1], "Kennedy, Jr., Esq.");
        xassert_eqq((Text::split_name("Kennedy, Bob, Jr., Esq. (Butt Pants)"))[0], "Bob");
        xassert_eqq((Text::split_name("Kennedy, Bob, Jr., Esq. (Butt Pants)"))[1], "Kennedy, Jr., Esq. (Butt Pants)");
        xassert_eqq((Text::split_name("Kennedy, Bob"))[0], "Bob");
        xassert_eqq((Text::split_name("Kennedy, Bob"))[1], "Kennedy");
        xassert_eqq((Text::split_name("Kennedy, Bob (Butt Pants)"))[0], "Bob (Butt Pants)");
        xassert_eqq((Text::split_name("Kennedy, Bob (Butt Pants)"))[1], "Kennedy");
    }

    function test_le_von() {
        xassert_eqq((Text::split_name("Claire Le Goues"))[1], "Le Goues");
        xassert_eqq((Text::split_name("Claire Von La Le Goues"))[1], "Von La Le Goues");
        xassert_eqq((Text::split_name("CLAIRE VON LA LE GOUES"))[1], "VON LA LE GOUES");
        xassert_eqq((Text::split_name("C. Von La Le Goues"))[1], "Von La Le Goues");
        xassert_eqq(Text::analyze_von("Von Le Goues"), null);
        xassert_eqq(Text::analyze_von("von le Goues"), ["von le", "Goues"]);
        xassert_eqq((Text::split_name("Amir Al Sadi"))[1], "Al Sadi");
    }

    function test_prefix_suffix() {
        xassert_eqq((Text::split_name("Brivaldo Junior"))[0], "Brivaldo");
        xassert_eqq((Text::split_name("Brivaldo Junior"))[1], "Junior");
        xassert_array_eqq(Text::split_first_prefix("Dr."), ["Dr.", ""]);
        xassert_array_eqq(Text::split_first_prefix("Dr. John"), ["John", "Dr."]);
        xassert_array_eqq(Text::split_first_prefix("Dr. Prof."), ["Prof.", "Dr."]);
        xassert_array_eqq(Text::split_first_prefix("Dr. Prof. Mr. Bob"), ["Bob", "Dr. Prof. Mr."]);
    }

    function test_unsplit_name() {
        xassert_eqq(Text::name("Bob", "Jones", "", 0), "Bob Jones");
        xassert_eqq(Text::name("Bob", "Jones", "", NAME_L), "Jones, Bob");
        xassert_eqq(Text::name("Bob", "Jones", "", NAME_PARSABLE), "Bob Jones");
        xassert_eqq(Text::name("Bob", "von Jones", "", 0), "Bob von Jones");
        xassert_eqq(Text::name("Bob", "von Jones", "", NAME_L), "von Jones, Bob");
        xassert_eqq(Text::name("Bob", "von Jones", "", NAME_PARSABLE), "Bob von Jones");
        xassert_eqq(Text::name("Bob", "Ferreira Costa", "", 0), "Bob Ferreira Costa");
        xassert_eqq(Text::name("Bob", "Ferreira Costa", "", NAME_L), "Ferreira Costa, Bob");
        xassert_eqq(Text::name("Bob", "Ferreira Costa", "", NAME_PARSABLE), "Ferreira Costa, Bob");
    }

    function test_score_sort_counts() {
        $s = [];
        foreach (["1,2,3,4,5", "1,2,3,5,5", "3,5,5", "3,3,5,5", "2,3,3,5,5"] as $st) {
            $s[] = new ScoreInfo($st);
        }
        xassert($s[0]->compare_by($s[0], "counts") == 0);
        xassert($s[0]->compare_by($s[1], "counts") < 0);
        xassert($s[0]->compare_by($s[2], "counts") < 0);
        xassert($s[0]->compare_by($s[3], "counts") < 0);
        xassert($s[1]->compare_by($s[1], "counts") == 0);
        xassert($s[1]->compare_by($s[2], "counts") < 0);
        xassert($s[1]->compare_by($s[3], "counts") < 0);
        xassert($s[2]->compare_by($s[2], "counts") == 0);
        xassert($s[2]->compare_by($s[3], "counts") < 0);
        xassert($s[3]->compare_by($s[0], "counts") > 0);
        xassert($s[3]->compare_by($s[1], "counts") > 0);
        xassert($s[3]->compare_by($s[2], "counts") > 0);
        xassert($s[3]->compare_by($s[3], "counts") == 0);
        xassert($s[3]->compare_by($s[4], "counts") > 0);

        xassert_eqq(ScoreInfo::parse_score_sort("avg"), "average");
        xassert_eqq(ScoreInfo::parse_score_sort("A"), "average");
        xassert_eqq(ScoreInfo::parse_score_sort("myscore"), "my");
        xassert_eqq(ScoreInfo::parse_score_sort("C"), "counts");
    }

    function test_qreq_make_url() {
        $user = Contact::make($this->conf);
        $qreq = TestRunner::make_qreq($user, "signin?email=foo@x.com", "POST");
        xassert_eqq($qreq->page(), "signin");
        xassert_eqq($qreq->path(), "");
        xassert_eqq($qreq->method(), "POST");
        xassert($qreq->valid_post());
        xassert_eqq($qreq["email"], "foo@x.com");

        $qreq = TestRunner::make_qreq($user, "signin/shit/yeah/?email=foo@x.com", "POST");
        xassert_eqq($qreq->page(), "signin");
        xassert_eqq($qreq->path(), "/shit/yeah/");
        xassert_eqq($qreq->method(), "POST");
        xassert($qreq->valid_post());
        xassert_eqq($qreq["email"], "foo@x.com");

        $qreq = TestRunner::make_qreq($user, "signin/shit/yeah/?%65%3Dmail=foo%40x.com&password=x", "POST");
        xassert_eqq($qreq->page(), "signin");
        xassert_eqq($qreq->path(), "/shit/yeah/");
        xassert_eqq($qreq->method(), "POST");
        xassert($qreq->valid_post());
        xassert_eqq($qreq["e=mail"], "foo@x.com");
        xassert_eqq($qreq["password"], "x");
    }

    function test_ftext() {
        xassert_eqq(Ftext::parse("< 0><hello"), [null, "< 0><hello"]);
        xassert_eqq(Ftext::parse("<0><hello"), [0, "<hello"]);
        xassert_eqq(Ftext::as(5, "<0><hello>"), "&lt;hello&gt;");
        xassert_eqq(Ftext::concat("<0><hello>", "<5>?"), "<5>&lt;hello&gt;?");
        xassert_eqq(Ftext::concat("<hello>", "?"), "<hello>?");
        xassert_eqq(Ftext::concat("<0><hello>", "?"), "<0><hello>?");
    }

    function test_str_list_lower_bound() {
        xassert_eqq(str_list_lower_bound("a", ["0", "ab", "ac", "ad"]), 1);
        xassert_eqq(str_list_lower_bound("aa", ["0", "ab", "ac", "ad"]), 1);
        xassert_eqq(str_list_lower_bound("ab", ["0", "ab", "ac", "ad"]), 1);
        xassert_eqq(str_list_lower_bound("abb", ["0", "ab", "ac", "ad"]), 2);
        xassert_eqq(str_list_lower_bound("af", ["0", "ab", "ac", "ad"]), 4);
        xassert_eqq(str_list_lower_bound("a", ["0", "1", "2", "ab", "ac", "ad"]), 3);
        xassert_eqq(str_list_lower_bound("aa", ["0", "1", "2", "ab", "ac", "ad"]), 3);
        xassert_eqq(str_list_lower_bound("ab", ["0", "1", "2", "ab", "ac", "ad"]), 3);
        xassert_eqq(str_list_lower_bound("abb", ["0", "1", "2", "ab", "ac", "ad"]), 4);
        xassert_eqq(str_list_lower_bound("ad", ["0", "1", "2", "ab", "ac", "ad"]), 5);
        xassert_eqq(str_list_lower_bound("ad1", ["0", "1", "2", "ab", "ac", "ad"]), 6);
        xassert_eqq(str_list_lower_bound("af", ["0", "1", "2", "ab", "ac", "ad"]), 6);
    }

    function test_glob_to_regex() {
        xassert_eqq(glob_to_regex("*foo{a,b}?"), ".*foo\\{a,b\\}.");
        xassert_eqq(glob_to_regex("*foo{a,b}?|", GLOB_BRACE), ".*foo(?:a|b).\\|");
        xassert_eqq(glob_to_regex("x.txt", GLOB_BRACE), "x\\.txt");
    }

    function test_topic_set() {
        $ts = new TopicSet($this->conf);
        $ts->__add(1, "None of the above");
        $ts->__add(2, "Other");
        $ts->__add(3, "Fudge");
        $ts->__add(4, "Fudge: Packing");
        $ts->__add(5, "Fudge: Opening");
        $ts->__add(6, "Fudge: Others");
        $ts->__add(7, "Fudge: Really");
        $ts->__add(8, "Fudgey");
        $ts->__add(9, "Fudge: All of them");
        xassert_eqq(json_encode($ts->as_array()), '{"1":"None of the above","2":"Other","3":"Fudge","4":"Fudge: Packing","5":"Fudge: Opening","6":"Fudge: Others","7":"Fudge: Really","8":"Fudgey","9":"Fudge: All of them"}');

        $ts->sort_by_name();
        xassert_eqq(json_encode($ts->as_array()), '{"3":"Fudge","9":"Fudge: All of them","5":"Fudge: Opening","4":"Fudge: Packing","7":"Fudge: Really","6":"Fudge: Others","8":"Fudgey","1":"None of the above","2":"Other"}');

        $ts->__add(10, "Fudge:Questions");
        $ts->sort_by_name();
        xassert_eqq(json_encode($ts->as_array()), '{"3":"Fudge","9":"Fudge: All of them","5":"Fudge: Opening","4":"Fudge: Packing","7":"Fudge: Really","10":"Fudge:Questions","6":"Fudge: Others","8":"Fudgey","1":"None of the above","2":"Other"}');
    }

    static function getopt_parse($getopt, $argv) {
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
    }

    function test_friendly_boolean() {
        xassert_eqq(friendly_boolean(""), false);
        xassert_eqq(friendly_boolean("0"), false);
        xassert_eqq(friendly_boolean("1"), true);
        xassert_eqq(friendly_boolean("!#($!"), null);
    }

    function test_merge_whitespace() {
        xassert_eqq(Text::merge_whitespace("a", "b"), "ab");
        xassert_eqq(Text::merge_whitespace("a ", "b"), "a b");
        xassert_eqq(Text::merge_whitespace("a  ", " b"), "a  b");
        xassert_eqq(Text::merge_whitespace("a\n", "b"), "a\nb");
        xassert_eqq(Text::merge_whitespace("a\n", "   b"), "a\n   b");
        xassert_eqq(Text::merge_whitespace("a\n\n", "\nb"), "a\n\nb");
        xassert_eqq(Text::merge_whitespace("a\n", "\n\nb"), "a\n\nb");
        xassert_eqq(Text::merge_whitespace("a\n", "\n   b"), "a\n   b");
    }

    function test_search_word_unquote() {
        xassert_eqq(SearchWord::unquote("abc"), "abc");
        xassert_eqq(SearchWord::unquote("\"abc"), "abc");
        xassert_eqq(SearchWord::unquote("abc\""), "abc\"");
        xassert_eqq(SearchWord::unquote("\"abc\""), "abc");
        xassert_eqq(SearchWord::unquote("“abc\""), "abc");
        xassert_eqq(SearchWord::unquote("”abc\""), "abc");
        xassert_eqq(SearchWord::unquote("“abc”"), "abc");
        xassert_eqq(SearchWord::unquote("\"abc”"), "abc");
        xassert_eqq(SearchWord::unquote("\"abc“"), "abc");
    }
}
