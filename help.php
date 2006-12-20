<?php 
require_once('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


$topicTitles = array("topics" => "Help topics",
		     "syntax" => "Search syntax",
		     "search" => "Search",
		     "tags" => "Tags");

$topic = defval($_REQUEST["t"], "topics");
if (!isset($topicTitles[$topic]))
    $topic = "topics";

$abar = "<table class='vubar'><tr><td><table><tr>\n";
$abar .= actionTab("Help topics", "help.php?t=topics", $topic == "topics");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search help", "help.php?t=search", $topic == "search");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search syntax", "help.php?t=syntax", $topic == "syntax");
if ($topic != "topics" && $topic != "search" && $topic != "syntax")
    $abar .= actionTab($topicTitles[$topic], "help.php?t=$topic", true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper' nowrap='nowrap'>" . goPaperForm() . "</td></tr></table>\n";

if ($topic == "topics")
    $Conf->header("Help", null, $abar);
else
    $Conf->header("<a href='help.php'>Help</a>", null, $abar);


function _alternateRow($caption, $entry) {
    global $rowidx;
    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    echo "<tr class='k", ($rowidx % 2), "'>";
    echo "<td class='srcaption nowrap'>", $caption, "</td>";
    echo "<td class='sentry'>", $entry, "</td></tr>\n";
}


function topics() {
    global $ConfSiteBase;
    echo "<table>";
    _alternateRow("<a href='help.php?t=search'>Search</a>", "About paper searching.");
    _alternateRow("<a href='help.php?t=syntax'>Search syntax</a>", "Quick reference to search syntax.");
    _alternateRow("<a href='help.php?t=tags'>Tags</a>", "How to use tags and ordered tags to define sets of papers and discussion orders.");
    echo "</table>";
}


function search() {
    global $ConfSiteBase;
    echo "<table>";
    _alternateRow("Basics", "
Enter search terms in the <a href='${ConfSiteBase}search.php'>search box</a>
separated by spaces.  The default search box returns papers that match
<b>any</b> of the terms you enter.  To search for papers that match <b>all</b>
the terms you enter, or that <b>don't</b> match some terms, expand the search
box with the <a href='${ConfSiteBase}search.php?opt=1'>Options&nbsp;&raquo;</a>
link and use \"With <b>all</b> the words\" and \"<b>Without</b> the words\".");
    _alternateRow("Paper selection", "
You can search in several paper classes, depending on your role in the
conference. Options include:
<ul class='compact'>
<li><b>Submitted papers</b> &mdash; all submitted papers.</li>
<li><b>Review assignment</b> &mdash; papers that you've been assigned to review.</li>
<li><b>Authored papers</b> &mdash; papers for which you're a contact author.</li>
<li><b>All papers</b> &mdash; all papers, including withdrawn and other non-submitted papers.</li>
</ul>");
    _alternateRow("Search type", "
By default, search examines title, abstract, and author fields.
Expand the search box with the <a href='${ConfSiteBase}search.php?opt=1'>Options&nbsp;&raquo;</a> link to search other fields, including
authors/collaborators and reviewers.");
    _alternateRow("Syntax", "
Specify search fields on a word-by-word basis with
markers like <span class='textlite'>ti:</span>, which means \"search in
titles\".  For information on available markers, see the
<a href='help.php?t=syntax'>search syntax quick reference</a>.");
    _alternateRow("Listing all papers", "To list all papers in a search category, simply perform the search with no search terms.");
    _alternateRow("Paging through results", "All paper screens have links in the upper right corner that let you page through the most recent search results:<br />
  <img src='${ConfSiteBase}images/pageresultsex.png' alt='[Result paging example]' /><br />
  Using these links can speed up many tasks.  Additionally, search matches are <span class='match'>highlighted</span> on the paper screens.  This makes it easier to tell whether a conflict is real, for example.");
    _alternateRow("Quick search", "Most screens have a quick search box in the upper right corner:<br />
  <img src='${ConfSiteBase}images/quicksearchex.png' alt='[Quick search example]' /><br />
  Entering a single paper number, or any search term that matches exactly one paper, will take you directly to that paper.");
    _alternateRow("Paper number search", "Enter a paper number to add that paper to the search results.<br />
  Example: Search <span class='textlite'>1 2 3 4 5 6 7 8</span> will return papers 1-8.<br />
  Example: Search <span class='textlite'>100 case</span> will return papers matching \"case\", plus paper 100.<br />
  To actually search for a number in a paper's title, abstract, or whatever, put it in quotes: <span class='textlite'>\"119\"</span>");
    _alternateRow("Paper actions", "To act on many papers at once, select their checkboxes and choose an action underneath the paper list.
For example, to download a <tt>.zip</tt> file with all submitted papers, PC members can search for all submitted papers, choose the \"select all\" link, then \"Get: Papers\".  Pull down the menu to see what else you can do.
The \"More &raquo;\" link allows PC members and chairs to <a href='help.php?t=tags'>add tags</a>, set conflicts, set decisions, and so forth.  The easiest way to tag a set of papers is to enter their numbers in the search box, search, \"select all\", and add the tag.");
    _alternateRow("Limitations", "Search won't show you information you aren't supposed to see.  For example, authors can only search their own submissions, and if the conference used anonymous submission, then only the PC chairs can search by author.");
    echo "</table>\n";
}


function _searchQuickrefRow($caption, $search, $explanation) {
    global $rowidx, $ConfSiteBase;
    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    echo "<tr class='k", ($rowidx % 2), "'>";
    echo "<td class='srcaption nowrap'>", $caption, "</td>";
    echo "<td class='sentry nowrap'>",
	"<form action='${ConfSiteBase}search.php' method='get'>",
	"<input type='text' class='textlite' name='q' value=\"", htmlspecialchars($search), "\" size='20' />",
	" &nbsp;<input type='submit' class='button' name='go' value='Search' />",
	"</form></td>";
    echo "<td class='sentry'>", $explanation, "<span class='sep'></span></td></tr>\n";
}

function searchQuickref() {
    global $rowidx;
    echo "<table>\n";
    _searchQuickrefRow("Basics", "story", "\"story\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "119", "paper #119");
    _searchQuickrefRow("", "1 2 5 12-24 kernel", "the numbered papers, plus papers with \"kernel\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "very new", "\"very\" <i>or</i> \"new\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "\"very new\"", "the phrase \"very new\" in title, abstract, possibly authors<br />(To search for papers matching both \"very\" and \"new\", but not necessarily the phrase, expand the search options and use \"With <i>all</i> the words\".)");
    _searchQuickrefRow("", "", "all papers in the search category");
    _searchQuickrefRow("Title", "ti:flexible", "title contains \"flexible\"");
    _searchQuickrefRow("Abstract", "ab:\"very novel\"", "abstract contains \"very novel\"");
    _searchQuickrefRow("Authors", "au:poletto", "author list contains \"poletto\"");
    _searchQuickrefRow("Collaborators", "co:liskov", "collaborators contains \"liskov\"");
    _searchQuickrefRow("Topics", "topic:link", "author-selected topics match \"link\"");
    _searchQuickrefRow("<a href='help.php?t=tags'>Tags</a>", "tag:discuss", "tagged \"discuss\"");
    _searchQuickrefRow("", "order:discuss", "tagged \"discuss\", sort by tag order");
    _searchQuickrefRow("Reviews", "re:fdabek", "\"fdabek\" in reviewer name/email");
    _searchQuickrefRow("", "cre:fdabek", "\"fdabek\" (in reviewer name/email) has completed a review");
    _searchQuickrefRow("", "re:4", "four reviewers (assigned and/or completed)");
    _searchQuickrefRow("", "cre:<3", "less than three completed reviews");
    _searchQuickrefRow("", "lead:fdabek", "\"fdabek\" (in name/email) is discussion lead");
    _searchQuickrefRow("", "shep:fdabek", "\"fdabek\" (in name/email) is shepherd");
    _searchQuickrefRow("Decision", "dec:accept", "decision matches \"accept\"");
    _searchQuickrefRow("", "dec:yes", "one of the accept decisions");
    _searchQuickrefRow("", "dec:no", "one of the reject decisions");
    _searchQuickrefRow("", "dec:?", "decision unspecified");
    echo "</table>\n";
}




function tags() {
    global $ConfSiteBase;
    echo "<table>";
    _alternateRow("Basics", "
The <i>tag</i> system keeps track of named paper sets.
PC members can add tags to papers,
remove tags from papers, and list all papers with a given tag.
It is also possible to define <i>ordered</i> tags, which preserve a particular
paper order.");
    _alternateRow("Tagging a paper", "
View and set a single paper's tags on its <a href='${ConfSiteBase}review.php'>review screen</a>.
Current tags are shown in a list:<br />
<img src='${ConfSiteBase}images/extagsnone.png' alt='[Tag list on review screen]' /><br />
Only non-conflicted PC members can see and edit tags.
Click on the <img src='${ConfSiteBase}images/next.png' alt='right arrow' />
to edit the tags, then enter one or more tags separated by spaces.<br />
<img src='${ConfSiteBase}images/extagsset.png' alt='[Tags entry on review screen]' /><br />
Tags should be mostly alphanumeric.");
    _alternateRow("Tag search", "
To find all papers with tag \"discuss\", simply <a href='${ConfSiteBase}search.php?q=tag:discuss'>search for \"tag:discuss\"</a>.");
    _alternateRow("Tagging multiple papers", "
To tag multiple papers at once, find the papers in a
<a href='${ConfSiteBase}search.php'>search</a>, select the papers you want
using their checkboxes, and add tags using the action area.

<p><img src='${ConfSiteBase}images/extagssearch.png' alt='[Setting tags on the search page]' /></p>

You can <b>Add</b> and <b>Remove</b> tags to/from the selected papers, or
<b>Define</b> a tag, which adds the tag to all selected papers and removes it
from all non-selected papers.");
    _alternateRow("Special tags", "
PC chairs can define special tags that only they can add and remove
on the <a href='${ConfSiteBase}settings.php'>conference settings page</a>.
By default, the tags \"accept\" and \"reject\" are special.
PC members can still search special tags.");
    _alternateRow("Ordered tags<br />and discussion orders", "
An ordered tag names an <i>ordered</i> set of papers.  Searching for the
tag with \"<a href='${ConfSiteBase}search.php?q=order:tagname'>order:tagname</a>\" will return the papers in the order
you defined.  This is useful for PC meeting discussion orders, for example.
In tag listings, the first paper in the \"discuss\" ordered tag will appear as
\"discuss#1\", the second as \"discuss#2\", and so forth; you can change
the order by editing the tag numbers.

<p>It's easiest to define ordered tags using the
<a href='${ConfSiteBase}search.php'>search screen</a>.  Search for the
papers you want, sort them into the right order, select them, and
choose <b>Define ordered</b> in the tag action area.  If no sort order
gives the order you want, just enter the paper numbers in order into the
search box; for instance, search for \"<a href='${ConfSiteBase}search.php?q=4+1+12+9'>4 1 12 19</a>\".</p>");
    echo "</table>\n";
}


if ($topic == "topics")
    topics();
else if ($topic == "search")
    search();
else if ($topic == "syntax")
    searchQuickref();
else if ($topic == "tags")
    tags();

$Conf->footer();
