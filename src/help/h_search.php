<?php
// help/h_search.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Search_HelpTopic {
    static function print(HelpRenderer $hth) {
        echo "<p>All HotCRP lists are obtained through flexible
search. Some hints for PC members and chairs:</p>

<ul>
<li><div class=\"d-inline-block\">", $hth->search_form(""), "</div>&nbsp; finds all submissions.  (Leave the search field blank.)</li>
<li><div class=\"d-inline-block\">", $hth->search_form("12"), "</div>&nbsp; finds submission #12.  When entered from a
 <a href=\"#quicklinks\">quicksearch</a> box, this search will jump to #12 directly.</li>
<li>", $hth->help_link("Search keywords", "keywords"), "
 let you search specific fields, review scores, and more.</li>
<li>Use <a href=\"#quicklinks\">quicklinks</a> on paper pages to navigate
 through search results. Typing <code>j</code> and <code>k</code> also goes
 from paper to paper.</li>
<li>On list pages, shift-click checkboxes to
 select ranges of submissions.</li>
</ul>";

    echo $hth->subhead("How to search");
    echo "
<p>The default search box returns submissions that match
<em>all</em> of the space-separated terms you enter.
To search for terms that <em>start</em> with
a prefix, try “term*”.
To find <em>some</em> of the terms,
type “term1 OR term2”.
To find submissions that <em>don’t</em> match a term,
try “-term”.  Or select ", $hth->hotlink("Advanced search", "search", "opt=1"),
" and use “With <b>any</b> of the words” and “<b>Without</b> the words.”</p>

<p>You can search several categories, depending on your role in the
conference. Options include:</p>
<ul>
<li><b>", PaperSearch::limit_description($hth->conf, "s"), "</b> &mdash; all submissions ready for review.</li>
<li><b>", PaperSearch::limit_description($hth->conf, "a"), "</b> &mdash; submissions for which you’re a contact.</li>
<li><b>", PaperSearch::limit_description($hth->conf, "r"), "</b> &mdash; submissions you’ve been assigned to review.</li>
<li><b>", PaperSearch::limit_description($hth->conf, "rout"), "</b> &mdash; submissions you’ve been assigned to review, but have not reviewed yet.</li>
<li><b>", PaperSearch::limit_description($hth->conf, "all"), "</b> &mdash; all submissions, including withdrawn submissions and submissions that were never completed.</li>
</ul>

<p>Search won’t show you information you aren’t supposed to see.  For example,
authors can only search their own submissions, and if the conference used
anonymous submission, then only the PC chairs can search by author.</p>

<p>By default, search examines titles, abstracts, and authors. ",
$hth->hotlink("Advanced search", "search", "opt=1"), "
can search other fields, including authors/collaborators and reviewers.
Also, <b>keywords</b> search specific characteristics such as titles,
authors, reviewer names, and numbers of reviewers.  For example,
“ti:foo” means “search for ‘foo’ in submission
titles.”  Keywords are listed in the ", $hth->help_link("search keywords reference", "keywords"), ".</p>";

    echo $hth->subhead("Search results");
    echo "
<p>Click on a paper number or title to jump to that paper.
Search matches are <span class=\"match\">highlighted</span> on paper screens.
Once on a paper screen use <a href=\"#quicklinks\">quicklinks</a>
to navigate through the rest of the search matches.</p>

<p>Underneath the paper list is the action area:</p>

" . Ht::img("exsearchaction.png", "[Search action area]") . "<br />

<p>Use the checkboxes to select some papers, then choose an action.
You can:</p>

<ul>
<li>Download a <code>.zip</code> file with the selected papers.</li>
<li>Download all reviews for the selected papers.</li>
<li>Download tab-separated text files with authors, PC
 conflicts, review scores, and so forth (some options chairs only).</li>
<li>Add, remove, and define ", $hth->help_link("tags", "tags"), ".</li>
<li>Assign reviewers and mark conflicts (chairs only).</li>
<li>Set decisions (chairs only).</li>
<li>Send mail to paper authors or reviewers (chairs only).</li>
</ul>

<p>Select papers one by one, select in groups by shift-clicking
the checkboxes, or use the “select all” link.
The easiest way to tag a set of papers is
to enter their numbers in the search box, search, “select all,” and add the
tag.</p>";

    echo $hth->subhead("Quicksearch and quicklinks", "quicklinks");
    echo "
<p>Most screens have a quicksearch box in the upper right corner:<br />
" . Ht::img("quicksearchex.png", "[Quicksearch box]") . "<br />
This box supports the full search syntax.  Enter
a paper number, or search terms that match exactly
one paper, to go directly to that paper.</p>

<p>Paper screens have quicklinks that step through search results:<br />
" . Ht::img("pageresultsex.png", "[Quicklinks]") . "<br />
Click on the search description (here, “Submitted papers search”) to return
to the search results.  On many pages, you can press “<code>j</code>” or
“<code>k</code>” to go to the previous or next paper in the list.</p>";
    }
}
