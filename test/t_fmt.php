<?php
// t_fmt.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Fmt_Tester {
    function test_1() {
        $ms = new Fmt;
        $ms->add("Hello", "Bonjour");
        $ms->add(["%d friend", "%d amis", ["$1 ≠ 1"]]);
        $ms->add("%d friend", "%d ami");
        $ms->add("ax", "a");
        $ms->add("ax", "b");
        $ms->add("bx", "a", 2);
        $ms->add("bx", "b");
        $ms->add(["fart", "fart example A", ["{0}=bob"]]);
        $ms->add(["fart", "fart example B", ["{0}^=bob"]]);
        $ms->add(["fart", "fart example C"]);
        $ms->add(["id" => "fox-saying", "itext" => "What the fox said"]);
        $ms->add(["id" => "fox-saying", "itext" => "What the {fox} said", "require" => ["{fox}"]]);
        $ms->add(["itext" => "butt", "otext" => "%1\$s", "context" => "test103", "template" => true]);
        $ms->add_override("test103", "%BUTT% %% %s %BU%%MAN%%BUTT%");
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

        $ms->add(["itext" => "butt", "otext" => "normal butt"]);
        $ms->add(["itext" => "butt", "otext" => "fat butt", "require" => ["$1[fat]"]]);
        $ms->add(["itext" => "butt", "otext" => "two butts", "require" => ["$1[count]>1"], "priority" => 1]);
        $ms->add(["itext" => "butt", "otext" => "three butts", "require" => ["$1[count]>2"], "priority" => 2]);
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
        $ms->add("Hello", "Hello");
        $ms->add(["hello", "Hello", "Hello1"]);
        $ms->add(["hello/yes", "Hello", "Hello2"]);
        $ms->add(["hellop", "Hello", "Hellop", 2]);
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
        $ms->add("Hello", "Bonjour");
        $ms->add(["{} friend", "{} amis", ["$1 ≠ 1"]]);
        $ms->add("{} friend", "{} ami");
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
    }

    function test_humanize_url() {
        $ms = new Fmt;
        xassert_eqq($ms->_("{}", "http://www.hello.com/"), "http://www.hello.com/");
        xassert_eqq($ms->_("{:humanize_url}", "http://www.hello.com/"), "www.hello.com");
        xassert_eqq($ms->_("{:humanize_url}", "https://www.hello.com/"), "www.hello.com");
        xassert_eqq($ms->_("<5><a href=\"{0}\">{0:humanize_url}</a>", new FmtArg(0, "http://www.hello.com/", 0)), "<5><a href=\"http://www.hello.com/\">www.hello.com</a>");
        xassert_eqq($ms->_("<5><a href=\"{0}\">{0:humanize_url}</a>", new FmtArg(0, "https://www.hello.com/\"", 0)), "<5><a href=\"https://www.hello.com/&quot;\">www.hello.com/&quot;</a>");
    }
}
