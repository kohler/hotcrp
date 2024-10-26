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

    function test_li() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<ul> <li> A </li> <li> B </li> </ul>'), '<ul> <li> A </li> <li> B </li> </ul>');
        $t = "<h2>ACM CCS 2025 - Cycle A</h2>\n<ul>\n<li><a href=\"https://www.sigsac.org/ccs/CCS2024/call-for/call-for-papers.html\">Call for Papers</a></li>\n<li><a href=\"https://www.acm.org/publications/policies/new-acm-policy-on-authorship\">ACM Authorship policies</a></li>\n</ul>";
        xassert_eqq($chtml->clean($t), $t);
    }

    function test_table() {
        $chtml = CleanHTML::basic();
        $t = "Conflict of interest guidelines:\n<table border=\"1\">\n  <tbody>\n    <tr>\n      <td>\n        <div>1. Between advisors and advisees: permanent.</div>\n      </td>\n    </tr>\n    <tr>\n      <td>\n        <div>2. Between family members (if they could be potential reviewers): permanent.</div>\n      </td>\n    </tr>\n    <tr>\n      <td>\n        <div>\n          3. Between individuals who have collaborated in the past <b>5 years</b>. Collaboration includes joint research, projects, papers, or direct funding (not corporate funding) from the potential reviewer to an author. Co-participation in professional activities, such as tutorials, is not considered a conflict.\n        </div>\n      </td>\n    </tr>\n    <tr>\n      <td>\n        <div>4. Between individuals from the same institution, or who were at the same institution within the last <b>5 years</b>.</div>\n      </td>\n    </tr>\n    <tr>\n      <td>\n        <div>5. Between individuals whose relationship could compromise objectivity in the review process.</div>\n      </td>\n    </tr>\n  </tbody>\n</table>";
        xassert_eqq($chtml->clean($t), $t);
    }
}
