<?php
// t_cleanhtml.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class CleanHTML_Tester {
    function test_basic() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<a>Hello'), null);
        xassert_eqq($chtml->clean('<a>Hello</a>'), '<a>Hello</a>');
        xassert_eqq($chtml->clean('<script>Hello</script>'), null);
        xassert_eqq($chtml->clean('< SCRIPT >Hello</script>'), null);
        xassert_eqq($chtml->clean('<a href = fuckovia ><B>Hello</b></a>'), '<a href="fuckovia"><b>Hello</b></a>');
        xassert_eqq($chtml->clean('<a href = " javaScript:hello" ><B>Hello</b></a>'), null);
        xassert_eqq($chtml->clean('<a href = "https://hello" onclick="fuck"><B>Hello</b></a>'), null);
        xassert_eqq($chtml->clean('<a href =\'https:"""//hello\' butt><B>Hello</b></a>'), '<a href="https:&quot;&quot;&quot;//hello" butt><b>Hello</b></a>');
        xassert_eqq($chtml->clean('<p><b><p>a</p></b></p>'), null);
        xassert_eqq($chtml->clean('<table> X </table>'), null);
        xassert_eqq($chtml->clean('<table><tr><td>hi</td><td>there</td></tr></table>'), '<table><tr><td>hi</td><td>there</td></tr></table>');
        xassert_eqq($chtml->clean("<ul><li>X</li> <li>Y</li>\n\n<li>Z</li>\n</ul>\n"), "<ul><li>X</li> <li>Y</li>\n\n<li>Z</li>\n</ul>\n");
        xassert_eqq($chtml->clean("<ul><li>X</li> p <li>Y</li>\n\n<li>Z</li>\n</ul>\n"), null);
        xassert_eqq($chtml->clean("<i><![CDATA[<alert>]]></i>"), "<i>&lt;alert&gt;</i>");
    }

    function test_comments() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<!-->'), null);
        xassert_eqq($chtml->clean('<![ie foo>'), null);
        xassert_eqq($chtml->clean('<!--->'), null);
        xassert_eqq($chtml->clean('<!---'), null);
        xassert_eqq($chtml->clean('<!---->'), "");
        xassert_eqq($chtml->clean('<!--<!-->'), "");
        xassert_eqq($chtml->clean('<!--<!--->'), null);
        xassert_eqq($chtml->clean('<!--My favorite operators are > ad <!-->x'), "x");
        xassert_eqq($chtml->clean('<!----!>-->'), null);
    }

    function test_void() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<br>'), '<br>');
        xassert_eqq($chtml->clean('<br/>'), '<br>');
        xassert_eqq($chtml->clean('<br />'), '<br>');
        xassert_eqq($chtml->clean('<br / >'), '<br>');
        xassert_eqq($chtml->clean('<div / >'), null);
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

    function test_attr() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<span class="ui">Hi</span>'), null);
        xassert_eqq($chtml->clean('<span class="xui">Hi</span>'), '<span class="xui">Hi</span>');
        xassert_eqq($chtml->clean('<span class="hi" data-fart>Hi</span>'), null);
        xassert_eqq($chtml->clean('<span class="hi" data-tooltip="hi&">Hi</span>'), '<span class="hi" data-tooltip="hi&amp;">Hi</span>');
    }

    function test_nesting_scope() {
        $chtml = CleanHTML::basic();
        // elements outside required scope fail
        xassert_eqq($chtml->clean('<li>X</li>'), null);
        xassert_eqq($chtml->clean('<td>X</td>'), null);
        xassert_eqq($chtml->clean('<dt>X</dt>'), null);
        xassert_eqq($chtml->clean('<dd>X</dd>'), null);
        xassert_eqq($chtml->clean('<caption>X</caption>'), null);
        xassert_eqq($chtml->clean('<legend>X</legend>'), null);
        xassert_eqq($chtml->clean('<summary>X</summary>'), null);
        xassert_eqq($chtml->clean('<figcaption>X</figcaption>'), null);
        // valid structures
        xassert_eqq($chtml->clean('<details><summary>X</summary>Y</details>'), '<details><summary>X</summary>Y</details>');
        xassert_eqq($chtml->clean('<fieldset><legend>X</legend>Y</fieldset>'), '<fieldset><legend>X</legend>Y</fieldset>');
        xassert_eqq($chtml->clean('<figure><figcaption>X</figcaption>Y</figure>'), '<figure><figcaption>X</figcaption>Y</figure>');
        // tr via REQSCP1 (trows scope) and REQSCP2 (table scope)
        xassert_eqq($chtml->clean('<table><tbody><tr><td>X</td></tr></tbody></table>'), '<table><tbody><tr><td>X</td></tr></tbody></table>');
        xassert_eqq($chtml->clean('<table><tr><td>X</td></tr></table>'), '<table><tr><td>X</td></tr></table>');
    }

    function test_close_mismatch() {
        $chtml = CleanHTML::basic();
        // misnested tags
        xassert_eqq($chtml->clean('<b><i>x</b></i>'), null);
        // extra close tag
        xassert_eqq($chtml->clean('<b>x</b></b>'), null);
        // </br> silently ignored (void)
        xassert_eqq($chtml->clean('x</br>y'), 'xy');
        // close for disabled tag
        xassert_eqq($chtml->clean('x</script>'), null);
    }

    function test_notext() {
        $chtml = CleanHTML::basic();
        xassert_eqq($chtml->clean('<ul>text</ul>'), null);
        xassert_eqq($chtml->clean('<ol>text</ol>'), null);
        xassert_eqq($chtml->clean('<dl>text</dl>'), null);
        // whitespace ok
        xassert_eqq($chtml->clean("<table> \n </table>"), "<table> \n </table>");
    }

    function test_inline_flag() {
        $chtml = new CleanHTML(CleanHTML::CLEAN_INLINE);
        // block elements fail
        xassert_eqq($chtml->clean('<div>x</div>'), null);
        xassert_eqq($chtml->clean('<p>x</p>'), null);
        // inline elements pass
        xassert_eqq($chtml->clean('<b>x</b>'), '<b>x</b>');
        xassert_eqq($chtml->clean('<i>x</i>'), '<i>x</i>');
        xassert_eqq($chtml->clean('<span>x</span>'), '<span>x</span>');
    }

    function test_strip_ignore_flags() {
        // CLEAN_STRIP_UNKNOWN strips disabled open tags
        $ch = new CleanHTML(CleanHTML::CLEAN_STRIP_UNKNOWN);
        xassert_eqq($ch->clean('a<script>b'), 'ab');
        xassert_eqq($ch->clean('a<html>b'), 'ab');
        // close tags for disabled elements still error if first error
        xassert_eqq($ch->clean('<script>alert(1)</script>'), null);
        // CLEAN_IGNORE_UNKNOWN replaces < with &lt; for open and close
        $ch = new CleanHTML(CleanHTML::CLEAN_IGNORE_UNKNOWN);
        xassert_eqq($ch->clean('<script>alert(1)</script>'), '&lt;script>alert(1)&lt;/script>');
        xassert_eqq($ch->clean('a<html>b</html>c'), 'a&lt;html>b&lt;/html>c');
    }

    function test_malformed() {
        $chtml = CleanHTML::basic();
        // truncated tag becomes &lt;
        xassert_eqq($chtml->clean('<b'), '&lt;b');
        xassert_eqq($chtml->clean('<>'), '&lt;>');
        // self-closing non-void
        xassert_eqq($chtml->clean('<div/>'), null);
        // attribute with empty value
        xassert_eqq($chtml->clean('<a href=>X</a>'), null);
    }

    function test_fix_implied_close() {
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX);
        // li implied close
        xassert_eqq($ch->clean('<ul><li>X<li>Y</ul>'), '<ul><li>X</li><li>Y</li></ul>');
        xassert_eqq($ch->clean('<ul><li>X<li>Y<li>Z</ul>'), '<ul><li>X</li><li>Y</li><li>Z</li></ul>');
        // dt/dd implied close
        xassert_eqq($ch->clean('<dl><dt>X<dd>Y</dl>'), '<dl><dt>X</dt><dd>Y</dd></dl>');
        xassert_eqq($ch->clean('<dl><dt>A<dt>B<dd>C<dd>D</dl>'), '<dl><dt>A</dt><dt>B</dt><dd>C</dd><dd>D</dd></dl>');
        // td/th implied close
        xassert_eqq($ch->clean('<table><tr><td>A<td>B</tr></table>'), '<table><tr><td>A</td><td>B</td></tr></table>');
        xassert_eqq($ch->clean('<table><tr><th>H<td>D</tr></table>'), '<table><tr><th>H</th><td>D</td></tr></table>');
        // tr implied close (cascading: closes td first)
        xassert_eqq($ch->clean('<table><tr><td>A<tr><td>B</table>'), '<table><tr><td>A</td></tr><tr><td>B</td></tr></table>');
        // p closed by block element
        xassert_eqq($ch->clean('<p>X<p>Y'), '<p>X</p><p>Y</p>');
        xassert_eqq($ch->clean('<p>X<div>Y</div>'), '<p>X</p><div>Y</div>');
        // close tag closes intervening end-optional elements
        xassert_eqq($ch->clean('<div><p>X</div>'), '<div><p>X</p></div>');
        xassert_eqq($ch->clean('<table><tr><td>A</table>'), '<table><tr><td>A</td></tr></table>');
        // unclosed end-optional tags at end of input
        xassert_eqq($ch->clean('<p>X'), '<p>X</p>');
        xassert_eqq($ch->clean('<ul><li>X<li>Y'), null);  // <ul> not end-optional
        xassert_eqq($ch->clean('<div><p>X'), null);  // <div> not end-optional
        // still rejects truly broken HTML
        xassert_eqq($ch->clean('<div><div>X</div>'), null);  // <div> not end-optional
        // non-fix mode still rejects implied close cases
        $basic = CleanHTML::basic();
        xassert_eqq($basic->clean('<ul><li>X<li>Y</ul>'), null);
    }

    function test_fix_adoption_agency() {
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX);
        // basic: close and reopen intervening formatting
        xassert_eqq($ch->clean('<b><i>X</b>Y</i>'), '<b><i>X</i></b><i>Y</i>');
        xassert_eqq($ch->clean('<b>X<i>Y</b>Z</i>'), '<b>X<i>Y</i></b><i>Z</i>');
        // multiple intervening elements
        xassert_eqq($ch->clean('<b><i><u>X</b>Y</u></i>'), '<b><i><u>X</u></i></b><i><u>Y</u></i>');
        // with attributes preserved on reopen
        xassert_eqq($ch->clean('<a href="u"><b>X</a>Y</b>'), '<a href="u"><b>X</b></a><b>Y</b>');
        xassert_eqq($ch->clean('<b><a href="u">X</b>Y</a>'), '<b><a href="u">X</a></b><a href="u">Y</a>');
        // formatting within block
        xassert_eqq($ch->clean('<p><b><i>X</b>Y</i></p>'), '<p><b><i>X</i></b><i>Y</i></p>');
        // closing CLOSEP elements also closes formatting elements
        xassert_eqq($ch->clean('<div><b>X</div>'), '<div><b>X</b></div>');
        xassert_eqq($ch->clean('<b><div>X</b>'), null);
        // close tag not on stack at all
        xassert_eqq($ch->clean('<i>X</b>Y</i>'), null);
        // whitespace in tags: opener must use cleaned tag name
        xassert_eqq($ch->clean('< B >< I >X</ B >Y</ I >'), '<b><i>X</i></b><i>Y</i>');
        xassert_eqq($ch->clean('<B   ><I   >X</B>Y</I>'), '<b><i>X</i></b><i>Y</i>');
        // whitespace in tags with attributes
        xassert_eqq($ch->clean('< A  href="u" ><b>X</ A >Y</b>'), '<a href="u"><b>X</b></a><b>Y</b>');
        xassert_eqq($ch->clean('<b>< A  href="u" >X</b>Y</ A >'), '<b><a href="u">X</a></b><a href="u">Y</a>');
        // non-fix mode still rejects
        $basic = CleanHTML::basic();
        xassert_eqq($basic->clean('<b><i>X</b></i>'), null);
    }

    function test_base_url() {
        $ch = (new CleanHTML)->set_base_url('https://example.com/base/');
        // relative href gets base prepended
        xassert_eqq($ch->clean('<a href="page">X</a>'), '<a href="https://example.com/base/page">X</a>');
        // relative src gets base prepended
        xassert_eqq($ch->clean('<img src="img.png">'), '<img src="https://example.com/base/img.png">');
        // absolute URLs are unchanged
        xassert_eqq($ch->clean('<a href="https://other.com/x">X</a>'), '<a href="https://other.com/x">X</a>');
        xassert_eqq($ch->clean('<a href="http://other.com/x">X</a>'), '<a href="http://other.com/x">X</a>');
        // protocol-relative URLs are unchanged
        xassert_eqq($ch->clean('<a href="//other.com/x">X</a>'), '<a href="//other.com/x">X</a>');
        // root-relative, fragment-only, query-only URLs are unchanged
        xassert_eqq($ch->clean('<a href="/path">X</a>'), '<a href="/path">X</a>');
        xassert_eqq($ch->clean('<a href="#frag">X</a>'), '<a href="#frag">X</a>');
        xassert_eqq($ch->clean('<a href="?q=1">X</a>'), '<a href="?q=1">X</a>');
        // non-URL attributes are unaffected
        xassert_eqq($ch->clean('<span class="rel">X</span>'), '<span class="rel">X</span>');
        // disallowed schemes still rejected
        xassert_eqq($ch->clean('<a href="javascript:alert(1)">X</a>'), null);
        // no base_url: relative URLs pass through unchanged
        $ch2 = new CleanHTML;
        xassert_eqq($ch2->clean('<a href="page">X</a>'), '<a href="page">X</a>');
    }
}
