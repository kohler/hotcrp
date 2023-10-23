<?php
// t_fmt.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Fmt_Tester {
    function test_1() {
        $ms = new Fmt;
        $ms->addj(["Hello", "Bonjour"]);
        $ms->addj(["%d friend", "%d amis", ["$1 ≠ 1"]]);
        $ms->addj(["%d friend", "%d ami"]);
        $ms->addj(["ax", "a"]);
        $ms->addj(["ax", "b"]);
        $ms->addj(["bx", "a", 2]);
        $ms->addj(["bx", "b"]);
        $ms->addj(["fart", "fart example A", ["{0}=bob", "{0}=bob"]]);
        $ms->addj(["fart", "fart example B", ["{0}^=bob"]]);
        $ms->addj(["fart", "fart example C"]);
        $ms->addj(["in" => "fox-saying", "out" => "What the fox said"]);
        $ms->addj(["in" => "fox-saying", "out" => "What the {fox} said", "require" => ["{fox}"]]);
        $ms->addj(["in" => "butt", "out" => "%1\$s", "template" => true]);
        $ms->define_override("test103", "%BUTT% %% %s %BU%%MAN%%BUTT%");
        xassert_eqq($ms->_("Hello"), "Bonjour");
        xassert_eqq($ms->_("%d friend", 1), "1 ami");
        xassert_eqq($ms->_("%d friend", 0), "0 amis");
        xassert_eqq($ms->_("%d friend", 2), "2 amis");
        xassert_eqq($ms->_("%1[foo]\$s friend", ["foo" => 3]), "3 friend");
        xassert_eqq($ms->_("ax"), "b");
        xassert_eqq($ms->_("bx"), "a");
        xassert_eqq($ms->_("%xOOB%x friend", 10, 11), "aOOBb friend");
        xassert_eqq($ms->_("%xOOB%x%% friend", 10, 11), "aOOBb% friend");
        xassert_eqq($ms->_("fart"), "fart example C");
        xassert_eqq($ms->_("fart", "bobby"), "fart example B");
        xassert_eqq($ms->_("fart", "bob"), "fart example A");
        xassert_eqq($ms->_i("fox-saying"), "What the fox said");
        xassert_eqq($ms->_i("fox-saying", new FmtArg("fox", "Animal")), "What the Animal said");
        xassert_eqq($ms->_i("test103", "Ass"), "Ass %% %s %BU%%MAN%Ass");

        $ms->addj(["in" => "butt", "out" => "normal butt"]);
        $ms->addj(["in" => "butt", "out" => "fat butt", "require" => ["$1[fat]"]]);
        $ms->addj(["in" => "butt", "out" => "two butts", "require" => ["$1[count]>1"], "priority" => 1]);
        $ms->addj(["in" => "butt", "out" => "three butts", "require" => ["$1[count]>2"], "priority" => 2]);
        xassert_eqq($ms->_("butt"), "normal butt");
        xassert_eqq($ms->_("butt", []), "normal butt");
        xassert_eqq($ms->_("butt", ["thin" => true]), "normal butt");
        xassert_eqq($ms->_("butt", ["fat" => true]), "fat butt");
        xassert_eqq($ms->_("butt", ["fat" => false]), "normal butt");
        xassert_eqq($ms->_("butt", ["fat" => true, "count" => 2]), "two butts");
        xassert_eqq($ms->_("butt", ["fat" => false, "count" => 2]), "two butts");
        xassert_eqq($ms->_("butt", ["fat" => true, "count" => 3]), "three butts");
        xassert_eqq($ms->_("butt", ["fat" => false, "count" => 2.1]), "three butts");
    }

    function test_contexts() {
        $ms = new Fmt;
        $ms->addj(["Hello", "Hello"]);
        $ms->addj(["hello", "Hello", "Hello1"]);
        $ms->addj(["hello/yes", "Hello", "Hello2"]);
        $ms->addj(["hellop", "Hello", "Hellop", 2]);
        xassert_eqq($ms->_c(null, "Hello"), "Hello");
        xassert_eqq($ms->_c("hello", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/no", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/yes", "Hello"), "Hello2");
        xassert_eqq($ms->_c("hello/yes/whatever", "Hello"), "Hello2");
        xassert_eqq($ms->_c("hello/ye", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/yesp", "Hello"), "Hello1");
    }

    function test_braces() {
        $ms = new Fmt;
        $ms->addj(["Hello", "Bonjour"]);
        $ms->addj(["{} friend", "{} amis", ["$1 ≠ 1"]]);
        $ms->addj(["{} friend", "{} ami"]);
        xassert_eqq($ms->_("Hello"), "Bonjour");
        xassert_eqq($ms->_("{} friend", 1), "1 ami");
        xassert_eqq($ms->_("{} friend", 0), "0 amis");
        xassert_eqq($ms->_("{} friend", 2), "2 amis");
        xassert_eqq($ms->_("{0[foo]} friend", ["foo" => 3]), "3 friend");
        xassert_eqq($ms->_("{0[foo]} friend", ["foo" => "&"]), "& friend");
        xassert_eqq($ms->_("{:html} friend", "&"), "&amp; friend");
        xassert_eqq($ms->_("{:url} friend", "&"), "%26 friend");
        xassert_eqq($ms->_("{:list} friend", ["a", "b"]), "a and b friend");
        xassert_eqq($ms->_("{0[foo]:html} friend", ["foo" => "&"]), "&amp; friend");
        xassert_eqq($ms->_("{0[foo]:url} friend", ["foo" => "&"]), "%26 friend");
        xassert_eqq($ms->_("{0[foo]:list} friend", ["foo" => ["a", "b"]]), "a and b friend");
        xassert_eqq($ms->_("{0[foo]", ["foo" => "a"]), "{0[foo]");
        xassert_eqq($ms->_("{ hello {{", ["foo" => "a"]), "{ hello {");
    }

    function test_ftext() {
        $ms = new Fmt;
        xassert_eqq($ms->_("{:ftext}", "Ftext"), "<0>Ftext");
        xassert_eqq($ms->_("{:ftext}", "<0>Ftext"), "<0>Ftext");
        xassert_eqq($ms->_("{:ftext}", "<5>Ftext"), "<5>Ftext");
        xassert_eqq($ms->_("<{:ftext}", "Ftext"), "<Ftext");
        xassert_eqq($ms->_("<{:ftext}", "<0>Ftext"), "<Ftext");
        xassert_eqq($ms->_("<{:ftext}", "<5>Ftext"), "<Ftext");
        xassert_eqq($ms->_("<0>{:ftext}", "Ftext&amp;"), "<0>Ftext&amp;");
        xassert_eqq($ms->_("<0>{:ftext}", "<0>Ftext&amp;"), "<0>Ftext&amp;");
        xassert_eqq($ms->_("<0>{:ftext}", "<5>Ftext&amp;"), "<0>Ftext&");
        xassert_eqq($ms->_("<5>{:ftext}", "Ftext&amp;"), "<5>Ftext&amp;amp;");
        xassert_eqq($ms->_("<5>{:ftext}", "<0>Ftext&amp;"), "<5>Ftext&amp;amp;");
        xassert_eqq($ms->_("<5>{:ftext}", "<5>Ftext&amp;"), "<5>Ftext&amp;");

        xassert_eqq($ms->_("<5>{a}", new FmtArg("a", "&")), "<5>&");
        xassert_eqq($ms->_("<5>{a}", new FmtArg("a", "&", 0)), "<5>&amp;");

        xassert_eqq($ms->_("{a}", new FmtArg("a", "&", 0)), "&");
        xassert_eqq($ms->_("{a:html}", new FmtArg("a", "&", 0)), "&amp;");
        xassert_eqq($ms->_("<5>{a}", new FmtArg("a", "&", 0)), "<5>&amp;");
        xassert_eqq($ms->_("<5>{a:html}", new FmtArg("a", "&", 0)), "<5>&amp;");

        $ms->define_template("company1", "<0>Fortnum & Mason");
        $ms->define_template("company2", "<5>Sanford &amp; Sons");
        xassert_eqq($ms->_("<0>{company1} and {company2}"), "<0>Fortnum & Mason and Sanford & Sons");
        xassert_eqq($ms->_("<5>{company1} and {company2}"), "<5>Fortnum &amp; Mason and Sanford &amp; Sons");
    }

    function test_humanize_url() {
        $ms = new Fmt;
        xassert_eqq($ms->_("{}", "http://www.hello.com/"), "http://www.hello.com/");
        xassert_eqq($ms->_("{:humanize_url}", "http://www.hello.com/"), "www.hello.com");
        xassert_eqq($ms->_("{:humanize_url}", "https://www.hello.com/"), "www.hello.com");
        xassert_eqq($ms->_("<5><a href=\"{0}\">{0:humanize_url}</a>", new FmtArg(0, "http://www.hello.com/", 0)), "<5><a href=\"http://www.hello.com/\">www.hello.com</a>");
        xassert_eqq($ms->_("<5><a href=\"{0}\">{0:humanize_url}</a>", new FmtArg(0, "https://www.hello.com/\"", 0)), "<5><a href=\"https://www.hello.com/&quot;\">www.hello.com/&quot;</a>");
    }

    function test_nblist() {
        $ms = new Fmt;
        xassert_eqq($ms->_("<5>{:nblist}", ["a"]), "<5><span class=\"nb\">a</span>");
        xassert_eqq($ms->_("<5>{:nblist}", ["a", "b c", "d"]), "<5><span class=\"nb\">a,</span> <span class=\"nb\">b c,</span> and <span class=\"nb\">d</span>");
        xassert_eqq($ms->_("<0>{:nblist}", ["a", "b c", "d"]), "<0>a, b c, and d");
        xassert_eqq($ms->_("<0>{:nblist}", ["a", "b c"]), "<0>a and b c");
    }

    function test_template_expand() {
        $ms = new Fmt;
        $ms->define("x", FmtItem::make_template("Xexp"));
        $ms->define("a", FmtItem::make_template("<0>{{}} {0} {x}", FmtItem::EXPAND_ALL));
        $ms->define("b", FmtItem::make_template("<0>{{}} {0} {x}", FmtItem::EXPAND_NONE));
        $ms->define("c", FmtItem::make_template("<0>{{}} {0} {x}", FmtItem::EXPAND_TEMPLATE));
        xassert_eqq($ms->_("{a}", "Arg"), "{} Arg Xexp");
        xassert_eqq($ms->_("{b}", "Arg"), "{{}} {0} {x}");
        xassert_eqq($ms->_("{c}", "Arg"), "{{}} {0} Xexp");
    }

    function test_members() {
        $ms = new Fmt;
        $jl = json_decode('[
    {"in": "Hello", "m": ["Hello", ["Bonjour", ["{lang}=fr"]], ["Hola", ["{lang}=es"]]]},
    {"in": "Hello!", "expand": "none", "m": ["Hello!{x}", ["Bonjour", ["{lang}=fr"]], ["Hola", ["{lang}=es"]]]},
    {"in": "Hello!!", "expand": "template", "m": ["Hello!{x}", ["Bonjour", ["{lang}=fr"]], ["Hola", ["{lang}=es"]]]},
    {"in": "x", "out": "HI", "template": true}
]');
        foreach ($jl as $j) {
            $ms->addj($j);
        }
        xassert_eqq($ms->_("Hello", new FmtArg("lang", "en")), "Hello");
        xassert_eqq($ms->_("Hello!", new FmtArg("lang", "en")), "Hello!{x}");
        xassert_eqq($ms->_("Hello!!", new FmtArg("lang", "en")), "Hello!HI");
        xassert_eqq($ms->_("Hello", new FmtArg("lang", "fr")), "Bonjour");
        xassert_eqq($ms->_("Hello!", new FmtArg("lang", "fr")), "Bonjour");
        xassert_eqq($ms->_("Hello!!", new FmtArg("lang", "fr")), "Bonjour");
    }

    function test_example() {
        $ms = new Fmt;
        $jl = json_decode('[{"in": "Hello", "out": "Hello, {name:list}", "require": ["{name}"]},
    {"in": "Hello", "out": "Hello, all", "require": ["#{name}>2"]}]');
        foreach ($jl as $j) {
            $ms->addj($j);
        }
        xassert_eqq($ms->_("Hello"), "Hello");
        xassert_eqq($ms->_("Hello", new FmtArg("name", ["Bob"])), "Hello, Bob");
        xassert_eqq($ms->_("Hello", new FmtArg("name", ["Bob", "Jane"])), "Hello, Bob and Jane");
        xassert_eqq($ms->_("Hello", new FmtArg("name", ["Bob", "Jane", "Fred"])), "Hello, all");
    }

    function test_context_starts_with() {
        xassert(FmtItem::context_starts_with(null, null));
        xassert(FmtItem::context_starts_with("", null));
        xassert(FmtItem::context_starts_with(null, ""));
        xassert(!FmtItem::context_starts_with(null, "a"));
        xassert(FmtItem::context_starts_with("a", null));
        xassert(FmtItem::context_starts_with("a", "a"));
        xassert(!FmtItem::context_starts_with("a", "ab"));
        xassert(FmtItem::context_starts_with("a/b", "a"));
        xassert(!FmtItem::context_starts_with("a/b", "ab"));
        xassert(!FmtItem::context_starts_with("a", "a/b"));
    }
}
