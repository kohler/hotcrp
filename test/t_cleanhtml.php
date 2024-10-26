<?php
// t_cleanhtml.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CleanHTML_Tester {
    function test_basic() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<a>Hello'), false);
        xassert_eqq($chtml->clean('<a>Hello</a>'), '<a>Hello</a>');
        xassert_eqq($chtml->clean('<script>Hello</script>'), false);
        xassert_eqq($chtml->clean('< SCRIPT >Hello</script>'), false);
        xassert_eqq($chtml->clean('<a href = fuckovia ><B>Hello</b></a>'), '<a href="fuckovia"><b>Hello</b></a>');
        xassert_eqq($chtml->clean('<a href = " javaScript:hello" ><B>Hello</b></a>'), false);
        xassert_eqq($chtml->clean('<a href = "https://hello" onclick="fuck"><B>Hello</b></a>'), false);
        xassert_eqq($chtml->clean('<a href =\'https:"""//hello\' butt><B>Hello</b></a>'), '<a href="https:&quot;&quot;&quot;//hello" butt><b>Hello</b></a>');
        xassert_eqq($chtml->clean('<p><b><p>a</p></b></p>'), false);
        xassert_eqq($chtml->clean('<table> X </table>'), false);
        xassert_eqq($chtml->clean('<table><tr><td>hi</td><td>there</td></tr></table>'), '<table><tr><td>hi</td><td>there</td></tr></table>');
        xassert_eqq($chtml->clean("<ul><li>X</li> <li>Y</li>\n\n<li>Z</li>\n</ul>\n"), "<ul><li>X</li> <li>Y</li>\n\n<li>Z</li>\n</ul>\n");
        xassert_eqq($chtml->clean("<ul><li>X</li> p <li>Y</li>\n\n<li>Z</li>\n</ul>\n"), false);
        xassert_eqq($chtml->clean("<i><![CDATA[<alert>]]></i>"), "<i>&lt;alert&gt;</i>");
    }

    function test_comments() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<!-->'), false);
        xassert_eqq($chtml->clean('<![ie foo>'), false);
        xassert_eqq($chtml->clean('<!--->'), false);
        xassert_eqq($chtml->clean('<!---'), false);
        xassert_eqq($chtml->clean('<!---->'), "");
        xassert_eqq($chtml->clean('<!--<!-->'), "");
        xassert_eqq($chtml->clean('<!--<!--->'), false);
        xassert_eqq($chtml->clean('<!--My favorite operators are > ad <!-->x'), "x");
        xassert_eqq($chtml->clean('<!----!>-->'), false);
    }

    function test_void() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<br>'), '<br>');
        xassert_eqq($chtml->clean('<br/>'), '<br>');
        xassert_eqq($chtml->clean('<br />'), '<br>');
        xassert_eqq($chtml->clean('<br / >'), '<br>');
        xassert_eqq($chtml->clean('<div / >'), false);
    }
}
