<?php
// t_authormatch.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class AuthorMatch_Tester {
    function test_affiliations() {
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
    }

    function test_initials() {
        $aum = AuthorMatcher::make_string_guess("D. Thin (Captain Poop)");
        xassert_eqq(!!$aum->test("D. Thin"), true);
        xassert_eqq(!!$aum->test("D.X. Thin"), true);
        xassert_eqq(!!$aum->test("D. X. Thin"), true);
        xassert_eqq(!!$aum->test("X.D. Thin"), false);
        xassert_eqq(!!$aum->test("X. D. Thin"), false);
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
        xassert_eqq(!!$aum->test("X.D. Thin"), false);
        xassert_eqq(!!$aum->test("X. D. Thin"), false);
        xassert_eqq(!!$aum->test("X. D. Thin", true), false);
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

        $aum = AuthorMatcher::make_string_guess("L. Peter Deutsch");
        xassert_eqq(!!$aum->test("L. Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. P. Deutsch"), true);
        xassert_eqq(!!$aum->test("L P Deutsch"), true);
        xassert_eqq(!!$aum->test("L.P. Deutsch"), true);
        xassert_eqq(!!$aum->test("P. Deutsch"), true);
        xassert_eqq(!!$aum->test("Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. Deutsch"), false);
        xassert_eqq(!!$aum->test("Lon Deutsch"), false);

        $aum = AuthorMatcher::make_string_guess("Lon Peter Deutsch");
        xassert_eqq(!!$aum->test("Lon Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. P. Deutsch"), true);
        xassert_eqq(!!$aum->test("L P Deutsch"), true);
        xassert_eqq(!!$aum->test("L.P. Deutsch"), true);
        xassert_eqq(!!$aum->test("P. Deutsch"), false);
        xassert_eqq(!!$aum->test("Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. Deutsch"), true);
        xassert_eqq(!!$aum->test("Lon Deutsch"), true);

        $aum = AuthorMatcher::make_string_guess("Jun L.C. Choi");
        xassert_eqq(!!$aum->test("Jun L.C. Choi"), true);
        xassert_eqq(!!$aum->test("Jun Choi"), true);
        xassert_eqq(!!$aum->test("J. Choi"), true);
        xassert_eqq(!!$aum->test("Crimini Choi"), false);

        $aum = AuthorMatcher::make_string_guess("L. P. Deutsch");
        xassert_eqq(!!$aum->test("L. Peter Deutsch"), true);
        xassert_eqq(!!$aum->test("L. P. Deutsch"), true);
        xassert_eqq(!!$aum->test("L P Deutsch"), true);
        xassert_eqq(!!$aum->test("L.P. Deutsch"), true);
        xassert_eqq(!!$aum->test("P. Deutsch"), false);
        xassert_eqq(!!$aum->test("Peter Deutsch"), false);
        xassert_eqq(!!$aum->test("L. Deutsch"), true);
        xassert_eqq(!!$aum->test("Lon Deutsch"), true);
    }

    function test_affiliation_alterates() {
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
    }

    function test_author_parentheses() {
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
    }

    function test_user_comparator() {
        $aus = [
            Author::make_string("Yu Hua <csyhua@whatever.com>"),
            Author::make_string("Wen Hu <wen.hu@whatever.com>"),
            Author::make_string("Y. Charlie Hu <ychu@whatever.com>"),
            Author::make_string("\"Peggy\" Chamberlain <pchamber@whatever.com>"),
            Author::make_string("Peggy Donnelan <pdo@whatever.com>"),
            Author::make_string("Ocarina Donnelan <ocarina@whatever.com>"),
            Author::make_string("Quisling Donnelan <quis@whatever.com>")
        ];
        $mkaus = function ($as) { return array_map(function ($a) { return $a->name(); }, $as); };

        $fcoll = Conf::make_user_comparator(false);
        usort($aus, $fcoll);
        xassert_array_eqq($mkaus($aus), [
            "Ocarina Donnelan", "\"Peggy\" Chamberlain", "Peggy Donnelan",
            "Quisling Donnelan", "Wen Hu", "Y. Charlie Hu", "Yu Hua"
        ]);

        $lcoll = Conf::make_user_comparator(true);
        usort($aus, $lcoll);
        xassert_array_eqq($mkaus($aus), [
            "\"Peggy\" Chamberlain", "Ocarina Donnelan", "Peggy Donnelan",
            "Quisling Donnelan", "Wen Hu", "Y. Charlie Hu", "Yu Hua"
        ]);
    }
}
