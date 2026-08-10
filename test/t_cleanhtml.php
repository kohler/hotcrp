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

    function test_bogus_comment() {
        // A `<!` that opens neither a comment nor CDATA is an error, and the
        // error must span the whole bogus declaration. Nothing need follow the
        // `<!`, so the declaration-name match can be empty; check the reported
        // positions, which are what go wrong if that match is assumed nonempty.
        $expected = [
            "<!" => [0, 2, "<0>Incorrectly opened HTML comment"],
            "<! " => [0, 3, "<0>Incorrectly opened HTML comment"],
            "<!\n\t" => [0, 4, "<0>Incorrectly opened HTML comment"],
            "a<!" => [1, 3, "<0>Incorrectly opened HTML comment"],
            "<!x" => [0, 3, "<0>Incorrectly opened HTML comment"],
            "<!doctype html>" => [0, 9, "<0>HTML DOCTYPE declarations not allowed"],
            "<!DOCTYPE html>" => [0, 9, "<0>HTML DOCTYPE declarations not allowed"],
            "<![if x]>" => [0, 5, "<0>Conditional HTML comments not allowed"],
            "<![endif]>" => [0, 10, "<0>Conditional HTML comments not allowed"]
        ];
        foreach ($expected as $t => $pm) {
            $ch = new CleanHTML;
            xassert_eqq($ch->clean($t), null);
            $ml = $ch->message_list();
            xassert_eqq(count($ml), 1);
            xassert_eqq($ml[0]->message, $pm[2]);
            xassert_eqq($ml[0]->pos1, $pm[0]);
            xassert_eqq($ml[0]->pos2, $pm[1]);
        }
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
    }

    function test_attr_denied() {
        $chtml = CleanHTML::basic();
        // event handlers, in any case, spelled with any prefix length
        xassert_eqq($chtml->clean('<img src="/x" onerror="alert(1)">'), null);
        xassert_eqq($chtml->clean('<img src="/x" ONERROR="alert(1)">'), null);
        xassert_eqq($chtml->clean('<img src="/x" onerror = "alert(1)">'), null);
        xassert_eqq($chtml->clean('<b onmouseover=x>Hi</b>'), null);
        // `on` itself is not an event handler
        xassert_eqq($chtml->clean('<b on="x">Hi</b>'), '<b on="x">Hi</b>');
        // style/script/id: CSS injection and JS lookup by id
        xassert_eqq($chtml->clean('<b style="color:red">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b STYLE=x>Hi</b>'), null);
        xassert_eqq($chtml->clean('<b script="x">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b id="x">Hi</b>'), null);
        // `name` on <img> would clobber `document.<name>` just like `id`
        xassert_eqq($chtml->clean('<img name="getElementById" src="/x">'), null);
        // `ping` POSTs to an arbitrary URL when a link is clicked
        xassert_eqq($chtml->clean('<a href="/x" ping="https://evil.example/c">Hi</a>'), null);
        // data-* drives JS behavior, including several innerHTML sinks
        xassert_eqq($chtml->clean('<b data-format="5" data-content="x">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b data-tooltip-info="x">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b data-tooltip="x">Hi</b>'), null);
        // formaction/xlink:href are inert today (<button>, <svg> are disabled)
        // but would be live URL sinks if those elements were ever enabled
        xassert_eqq($chtml->clean('<b formaction="/x">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b xlink:href="/x">Hi</b>'), null);
        // ordinary attributes are unaffected
        xassert_eqq($chtml->clean('<b title="t" aria-label="a" lang="en">Hi</b>'),
            '<b title="t" aria-label="a" lang="en">Hi</b>');
    }

    function test_attr_class_denied() {
        $chtml = CleanHTML::basic();
        // classes that hook up JS behavior
        xassert_eqq($chtml->clean('<b class="ui">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="js-foo">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="s-x">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="need-format">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="need-tooltip">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="pl">Hi</b>'), null);
        xassert_eqq($chtml->clean('<b class="plx">Hi</b>'), null);
        // one bad class in a list rejects the whole thing
        xassert_eqq($chtml->clean('<b class="mt-1 need-format">Hi</b>'), null);
        // near misses are fine
        xassert_eqq($chtml->clean('<b class="needy pl-2 xui sx">Hi</b>'),
            '<b class="needy pl-2 xui sx">Hi</b>');
    }

    function test_url_scheme() {
        $chtml = CleanHTML::basic();
        // rejected schemes
        xassert_eqq($chtml->clean('<a href="javascript:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="JaVaScRiPt:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="data:text/html,x">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="vbscript:msgbox(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<img src="javascript:alert(1)">'), null);
        // Browsers strip leading whitespace and C0 controls from URLs before
        // parsing the scheme, so the sanitizer must too.
        xassert_eqq($chtml->clean('<a href=" javascript:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean("<a href=\"\tjavascript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"\njavascript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"\x01javascript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"\x08javascript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"\x0bjavascript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"\x1fjavascript:alert(1)\">X</a>"), null);
        // Browsers also remove tab/CR/LF from *anywhere* in a URL, including
        // the middle of the scheme; entity references decode to those too.
        xassert_eqq($chtml->clean("<a href=\"java\tscript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"java\nscript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"java\rscript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean("<a href=\"j\ta\nva\rscript:alert(1)\">X</a>"), null);
        xassert_eqq($chtml->clean('<a href="java&#9;script:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="java&#10;script:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="java&Tab;script:alert(1)">X</a>'), null);
        xassert_eqq($chtml->clean('<a href="da&#9;ta:text/html,x">X</a>'), null);
        // accepted URLs
        xassert_eqq($chtml->clean('<a href="https://example.com/x">X</a>'), '<a href="https://example.com/x">X</a>');
        xassert_eqq($chtml->clean('<a href="HTTP://example.com/x">X</a>'), '<a href="HTTP://example.com/x">X</a>');
        xassert_eqq($chtml->clean('<a href="//example.com/x">X</a>'), '<a href="//example.com/x">X</a>');
        xassert_eqq($chtml->clean('<a href="/paper/1">X</a>'), '<a href="/paper/1">X</a>');
        xassert_eqq($chtml->clean('<a href="paper/1">X</a>'), '<a href="paper/1">X</a>');
        xassert_eqq($chtml->clean('<a href="#frag">X</a>'), '<a href="#frag">X</a>');
        xassert_eqq($chtml->clean('<a href="?q=1">X</a>'), '<a href="?q=1">X</a>');
        // a colon that is not a scheme separator
        xassert_eqq($chtml->clean('<a href="/x/a:b">X</a>'), '<a href="/x/a:b">X</a>');
        // http itself survives the same normalization
        xassert_eqq($chtml->clean("<a href=\"htt\tp://example.com/\">X</a>"), "<a href=\"htt\tp://example.com/\">X</a>");
    }

    function test_unquoted_attr_value() {
        $chtml = CleanHTML::basic();
        // An unquoted value ends at the first non-word character, so the rest
        // of `javascript:alert(1)` is parsed as a second attribute. Browsers
        // agree that the result is inert: `:alert(1)` is a valid attribute
        // name, and href is the relative URL `javascript`.
        xassert_eqq($chtml->clean('<a href=javascript:alert(1)>X</a>'),
            '<a href="javascript" :alert(1)>X</a>');
        xassert_eqq($chtml->clean('<img src=x:y>'), '<img src="x" :y>');
        // The tail is still checked as an attribute, so it cannot smuggle in
        // an event handler.
        xassert_eqq($chtml->clean('<img src=x onerror=alert(1)>'), null);
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
        // CLEAN_FIX materializes a paragraph for a stray </p>, but must not do
        // so in inline mode, where it would emit output it then rejects
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX);
        xassert_eqq($ch->clean('bbb</p>'), 'bbb<p></p>');
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX | CleanHTML::CLEAN_INLINE);
        xassert_eqq($ch->clean('bbb</p>'), null);
        xassert_eqq($ch->clean('<b>x</b>'), '<b>x</b>');
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
        // stray </p> after a block element that implicitly closed the <p>
        xassert_eqq($ch->clean('<p>A<ol><li>X</li></ol></p>'),
            '<p>A</p><ol><li>X</li></ol>');
        xassert_eqq($ch->clean('<p>A<ul><li>X</li></ul></p>'),
            '<p>A</p><ul><li>X</li></ul>');
        xassert_eqq($ch->clean('<p>A<div>B</div></p>'), '<p>A</p><div>B</div>');
        // but a genuinely open <p> under a non-end-optional element is not dropped
        xassert_eqq($ch->clean('<p><span>X</p>'), null);
        // non-fix mode still rejects implied close cases
        $basic = CleanHTML::basic();
        xassert_eqq($basic->clean('<ul><li>X<li>Y</ul>'), null);
    }

    function test_fix_stray_close_p() {
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX);
        // Orphan </p> (no <p> was ever opened): the author signaled a
        // paragraph break, so materialize an empty paragraph to preserve it.
        xassert_eqq($ch->clean('bbb</p>'), 'bbb<p></p>');
        xassert_eqq($ch->clean('bbb</p> xxxx</p>'), 'bbb<p></p> xxxx<p></p>');
        // A </p> that balances an implicitly-closed <p> is redundant: drop it,
        // rather than emit a spurious trailing empty paragraph.
        xassert_eqq($ch->clean('<p>A<ol><li>X</li></ol></p>'),
            '<p>A</p><ol><li>X</li></ol>');
        xassert_eqq($ch->clean('<p>A<ol><li>X</li></ol> B </p>'),
            '<p>A</p><ol><li>X</li></ol> B ');
        xassert_eqq($ch->clean('<p>A<div>B</div></p>'), '<p>A</p><div>B</div>');
        // One auto-close credit, two stray </p>: the first balances the
        // implicit close (drop); the second is an orphan (emit empty).
        xassert_eqq($ch->clean('<p>A<ol><li>X</li></ol></p> xxxx</p>'),
            '<p>A</p><ol><li>X</li></ol> xxxx<p></p>');
        // Nested <p> auto-closes the outer one; a trailing stray </p> then
        // balances that implicit close.
        xassert_eqq($ch->clean('<p>A<p>B</p></p>'), '<p>A</p><p>B</p>');
        // <p> implicitly closed by a misnested block close (</div>) also
        // earns a credit, so the following stray </p> drops rather than
        // emitting an empty paragraph.
        xassert_eqq($ch->clean('<div><p>A</div></p>'), '<div><p>A</p></div>');
        xassert_eqq($ch->clean('<div><p>A</div></p></p>'),
            '<div><p>A</p></div><p></p>');
        // A normally-matched </p> must not consume the credit.
        xassert_eqq($ch->clean('<p>A<p>B</p>'), '<p>A</p><p>B</p>');
        // Known limitation: an unclaimed auto-close credit can leak across an
        // intervening block and be spent by an unrelated </p>, so this drops
        // rather than emitting <div>bbb<p></p></div>. Accepted as cosmetic.
        xassert_eqq($ch->clean('<p>A<ol><li>X</li></ol><div>bbb</p></div>'),
            '<p>A</p><ol><li>X</li></ol><div>bbb</div>');
        // A genuinely open <p> under a non-end-optional element is still an
        // error, not a stray close.
        xassert_eqq($ch->clean('<p><span>X</p>'), null);
        // non-fix mode keeps rejecting stray closes.
        xassert_eqq(CleanHTML::basic()->clean('bbb</p>'), null);
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

    function test_fix_adoption_agency_stack_repair() {
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX);
        // The adoption agency splices a node out of the middle of the open
        // element stack. The node above it saved *that* node's flags as its
        // enclosing context, so they must be repaired, or a later fixup walks
        // off the end of the stack.
        xassert_eqq($ch->clean('<a><small></a></div>'), null);
        xassert_eqq($ch->clean('<a><small></a></p>'),
            '<a><small></small></a><small></small><p></p>');
        xassert_eqq($ch->clean('<b><i></b><div>'), null);
        $ch = new CleanHTML(CleanHTML::CLEAN_FIX | CleanHTML::CLEAN_INLINE);
        xassert_eqq($ch->clean('<p<b><a></b><li><p><tr>'), null);
        xassert_eqq($ch->clean('</ul><b><code></b></b>'), null);
    }
}
