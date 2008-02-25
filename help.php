<?php 
// help.php -- HotCRP help page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me = $_SESSION["Me"];
$Me->valid();


$topicTitles = array("topics" => "Help topics",
		     "syntax" => "Search syntax",
		     "search" => "Search",
		     "tags" => "Tags",
		     "chair" => "Chair's guide");

$topic = defval($_REQUEST, "t", "topics");
if (!isset($topicTitles[$topic]))
    $topic = "topics";

$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Help topics", "help$ConfSiteSuffix?t=topics", $topic == "topics");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search help", "help$ConfSiteSuffix?t=search", $topic == "search");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search syntax", "help$ConfSiteSuffix?t=syntax", $topic == "syntax");
if ($topic != "topics" && $topic != "search" && $topic != "syntax")
    $abar .= actionTab($topicTitles[$topic], "help$ConfSiteSuffix?t=$topic", true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper' nowrap='nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";

if ($topic == "topics")
    $Conf->header("Help", null, $abar);
else
    $Conf->header("<a href='help$ConfSiteSuffix'>Help</a>", null, $abar);


function _alternateRow($caption, $entry) {
    global $rowidx;
    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    echo "<tr class='k", ($rowidx % 2), "'>";
    echo "<td class='srcaption nowrap'>", $caption, "</td>";
    echo "<td class='sentry'>", $entry, "</td></tr>\n";
}


function topics() {
    global $ConfSiteBase, $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("<a href='help$ConfSiteSuffix?t=chair'>Chair's guide</a>", "How to run a conference using HotCRP.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=search'>Search</a>", "About paper searching.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=syntax'>Search syntax</a>", "Quick reference to search syntax.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=tags'>Tags</a>", "How to use tags to define paper sets and discussion orders.");
    echo "</table>";
}


function _searchForm($forwhat, $other = null) {
    global $ConfSiteBase, $ConfSiteSuffix;
    $text = "";
    if ($other && preg_match_all('/(\w+)=([^&]*)/', $other, $matches, PREG_SET_ORDER))
	foreach ($matches as $m)
	    $text .= "<input type='hidden' name='$m[1]' value=\"" . htmlspecialchars(urldecode($m[2])) . "\" />";
    return "<form method='get' action='${ConfSiteBase}search$ConfSiteSuffix'>"
	. "<input type='text' class='textlite' name='q' value=\""
	. htmlspecialchars($forwhat) . "\" size='20' /> &nbsp;"
	. "<input type='submit' class='button' name='go' value='Search' />"
	. $text . "</form>";
}

function search() {
    global $ConfSiteBase, $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("Basics", "
All HotCRP paper lists are obtained through search, search syntax is flexible,
and it's possible to download all matching papers and/or reviews at once.

<p>Some useful hints for PC members and chairs:</p>

<ul class='compact'>
<li>" . _searchForm("") . "&nbsp; finds all papers.  (Leave the search field blank.)</li>
<li>" . _searchForm("12") . "&nbsp; finds paper #12.  When entered from a
 <a href='#quicklinks'>quicksearch</a> box, this search will <i>jump</i> to
 paper #12 directly.</li>
<li>Investigate <a href='${ConfSiteBase}help$ConfSiteSuffix?t=syntax'>search syntax</a>.</li>
<li>Use <a href='#quicklinks'>quicklinks</a> on paper pages to navigate
 through search results.</li>
<li>On search results pages, <i>shift-click</i> the checkboxes to
 select paper ranges.</li>
</ul>
");
    _alternateRow("How to search", "
The default search box returns papers that match
<i>any</i> of the space-separated terms you enter.
To search for papers that match <i>all</i>
the terms, or that <i>don't</i> match some terms, select
<a href='${ConfSiteBase}search$ConfSiteSuffix?opt=1'>Advanced search</a>
and use \"With <b>all</b> the words\" and \"<b>Without</b> the words\".

<p>You can search in several paper classes, depending on your role in the
conference. Options include:</p>
<ul class='compact'>
<li><b>Submitted papers</b> &mdash; all submitted papers.</li>
<li><b>All papers</b> &mdash; all papers, including withdrawn and other non-submitted papers.</li>
<li><b>Your papers</b> &mdash; papers for which you're a contact author.</li>
<li><b>Your reviews</b> &mdash; papers you've been assigned to review.</li>
<li><b>Your incomplete reviews</b> &mdash; papers you've been assigned to review, but haven't submitted a review yet.</li>
</ul>

<p>Search won't show you information you aren't supposed to see.  For example,
authors can only search their own submissions, and if the conference used
anonymous submission, then only the PC chairs can search by author.</p>

<p>By default, search examines paper titles, abstracts, and authors.
<a href='${ConfSiteBase}search$ConfSiteSuffix?opt=1'>Advanced search</a>
can search other fields, including authors/collaborators and reviewers.
Also, <b>keywords</b> search specific characteristics such as titles,
authors, reviewer names, and numbers of reviewers.  For example,
\"ti:foo\" means \"search for 'foo' in paper
titles\".  Keywords are listed in the
<a href='help$ConfSiteSuffix?t=syntax'>search syntax reference</a>.</p>");
    _alternateRow("Search results", "
Click on a paper number or title to jump to that paper.
Search matches are <span class='match'>highlighted</span> on paper screens,
which, for example, makes it easier to tell whether a conflict is real.
Once on a paper screen use <a href='#quicklinks'>quicklinks</a>
to navigate through the rest of the search matches.

<p>Underneath the paper list is the action area:</p>

<img src='${ConfSiteBase}images/exsearchaction.png' alt='[Search action area example]' /><br />

<p>Use the checkboxes to select some papers, then choose an action.
You can:</p>

<ul class='compact'>
<li>Download a <tt>.zip</tt> file with the selected papers.</li>
<li>Download all reviews for the selected papers.</li>
<li>Download tab-separated text files with authors, PC
 conflicts, review scores, and so forth (some options chairs only).</li>
<li>Add, remove, and define <a href='${ConfSiteBase}help$ConfSiteSuffix?t=tags'>tags</a>.</li>
<li>Assign reviewers and mark conflicts (chairs only).</li>
<li>Set decisions (chairs only).</li>
<li>Send mail to paper authors or reviewers (chairs only).</li>
</ul>

<p>Select papers one by one, in groups by <i>shift</i>-clicking on either end
of a <i>range</i> of checkboxes, or using the \"select all\" link.
For instance, the easiest way to tag a set of papers is
to enter their numbers in the search box, search, \"select all\", and add the
tag.</p>
");
    _alternateRow("<a name='quicklinks'>Quicksearch<br />and quicklinks</a>", "
Most screens have a quicksearch box in the upper right corner:<br />
<img src='${ConfSiteBase}images/quicksearchex.png' alt='[Quick search example]' /><br />
This box supports the full search syntax.  Also, entering
a paper number, or search terms that match exactly
one paper, will take you directly to that paper.

<p>All paper screens have quicklinks in the upper right corner that navigate
through the most recent search results:<br />
<img src='${ConfSiteBase}images/pageresultsex.png' alt='[Result paging example]' /><br />
Using these links can speed up many tasks.  Click on the search description
(here, \"This search\") to return to the search results.</p>
");
    echo "</table>\n";
}

function _searchQuickrefRow($caption, $search, $explanation, $other = null) {
    global $rowidx;
    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    echo "<tr class='k", ($rowidx % 2), "'>";
    echo "<td class='srcaption nowrap'>", $caption, "</td>";
    echo "<td class='sentry nowrap'>", _searchForm($search, $other), "</td>";
    echo "<td class='sentry'>", $explanation, "<span class='sep'></span></td></tr>\n";
}

function searchQuickref() {
    global $rowidx, $ConfSiteSuffix;
    echo "<table>\n";
    _searchQuickrefRow("Basics", "", "all papers in the search category");
    _searchQuickrefRow("", "story", "\"story\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "119", "paper #119");
    _searchQuickrefRow("", "1 2 5 12-24 kernel", "the numbered papers, plus papers with \"kernel\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "\"802\"", "\"802\" in title, abstract, possibly authors (not paper #802)");
    _searchQuickrefRow("", "very new", "\"very\" <i>or</i> \"new\" in title, abstract, possibly authors");
    _searchQuickrefRow("", "\"very new\"", "the phrase \"very new\" in title, abstract, possibly authors<br />(To search for papers matching both \"very\" and \"new\", but not necessarily the phrase, expand the search options and use \"With <i>all</i> the words\".)");
    _searchQuickrefRow("Title", "ti:flexible", "title contains \"flexible\"");
    _searchQuickrefRow("Abstract", "ab:\"very novel\"", "abstract contains \"very novel\"");
    _searchQuickrefRow("Authors", "au:poletto", "author list contains \"poletto\"");
    _searchQuickrefRow("Collaborators", "co:liskov", "collaborators contains \"liskov\"");
    _searchQuickrefRow("Topics", "topic:link", "selected topics match \"link\"");
    _searchQuickrefRow("Options", "option:shadow", "selected submission options match \"shadow\"");
    _searchQuickrefRow("<a href='help$ConfSiteSuffix?t=tags'>Tags</a>", "tag:discuss", "tagged \"discuss\"");
    _searchQuickrefRow("", "notag:discuss", "not tagged \"discuss\"");
    _searchQuickrefRow("", "order:discuss", "tagged \"discuss\", sort by tag order");
    _searchQuickrefRow("Reviews", "re:fdabek", "\"fdabek\" in reviewer name/email");
    _searchQuickrefRow("", "cre:fdabek", "\"fdabek\" (in reviewer name/email) has completed a review");
    _searchQuickrefRow("", "re:4", "four reviewers (assigned and/or completed)");
    _searchQuickrefRow("", "cre:<3", "less than three completed reviews");
    _searchQuickrefRow("", "pri:>=1", "at least one primary reviewer (\"cpri:\" and reviewer name/email also work)");
    _searchQuickrefRow("", "sec:pai", "\"pai\" (reviewer name/email) is secondary reviewer (\"csec:\" and review counts also work)");
    _searchQuickrefRow("Comments", "cmt:>0", "at least one comment visible to PC (including authors' response)");
    _searchQuickrefRow("", "aucmt:>0", "at least one comment visible to authors (including authors' response)");
    _searchQuickrefRow("Leads", "lead:fdabek", "\"fdabek\" (in name/email) is discussion lead");
    _searchQuickrefRow("", "lead:none", "no assigned discussion lead");
    _searchQuickrefRow("", "lead:any", "some assigned discussion lead");
    _searchQuickrefRow("Shepherds", "shep:fdabek", "\"fdabek\" (in name/email) is shepherd (\"none\" and \"any\" also work)");
    _searchQuickrefRow("Conflicts", "conflict:fdabek", "\"fdabek\" (in name/email) has a conflict with the paper");
    _searchQuickrefRow("Status", "status:sub", "paper is submitted for review", "t=all");
    _searchQuickrefRow("", "status:unsub", "paper is neither submitted nor withdrawn", "t=all");
    _searchQuickrefRow("", "status:withdrawn", "paper has been withdrawn", "t=all");
    _searchQuickrefRow("Decision", "dec:accept", "decision matches \"accept\"");
    _searchQuickrefRow("", "dec:yes", "one of the accept decisions");
    _searchQuickrefRow("", "dec:no", "one of the reject decisions");
    _searchQuickrefRow("", "dec:?", "decision unspecified");
    echo "</table>\n";
}


function tags() {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $Me;

    // get current tag settings
    $chairtags = "";
    $conflictmsg1 = "";
    $conflictmsg2 = "";
    $conflictmsg3 = "";
    $setting = "";

    if ($Me->isPC) {
	require_once("Code/tags.inc");
	$ct = array_keys(chairTags());
	if (count($ct)) {
	    sort($ct);
	    $chairtags = " (currently ";
	    foreach ($ct as $c)
		$chairtags .= "&ldquo;$c&rdquo;, ";
	    $chairtags = substr($chairtags, 0, strlen($chairtags) - 2) . ")";
	}

	if ($Me->privChair)
	    $setting = "  (<a href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>Change this setting</a>)";

	if ($Conf->setting("tag_seeall") > 0) {
	    $conflictmsg3 = "Currently PC members can see tags for any paper, including conflicts.";
	} else {
	    $conflictmsg1 = " or conflicted PC members";
	    $conflictmsg2 = "  However, since PC members currently can't see tags for conflicted papers, each PC member might see a different list." . $setting;
	    $conflictmsg3 = "They are currently hidden from conflicted PC members&mdash;for instance, if a PC member searches for a tag, the results will never include conflicts.";
	}
    }

    echo "<table>";
    _alternateRow("Basics", "
PC members and administrators can attach tag names to papers.
Papers can have many tags, and you can invent new tags on the fly.
Tags are never shown to authors$conflictmsg1.
It's easy to add and remove tags and to list all papers with a given tag,
and <i>ordered</i> tags preserve a particular paper order.

<p>By default, tags are visible to the entire PC, but <em>twiddle tags</em>,
with names like &ldquo;~tag&rdquo;, are visible only to their creators.</p>");

    _alternateRow("Using tags", "
Here are some example ways to use tags.

<ul>
<li><strong>Avoid discussing low-ranked submissions at the PC meeting.</strong>
 Mark low-ranked submissions with tag &ldquo;nodiscuss&rdquo;, then ask the PC to
 <a href='${ConfSiteBase}search$ConfSiteSuffix?q=tag:nodiscuss'>search for &ldquo;tag:nodiscuss&rdquo;</a>.
 PC members can easily check the list for controversial papers they'd like to discuss despite their ranking.
 They can email the chairs about such papers, or, even easier, add a &ldquo;discussanyway&rdquo; tag.
 (You might make the &ldquo;nodiscuss&rdquo; tag chair-only so an evil PC member couldn't add it to a high-ranked paper, but it's usually better to trust the PC.)</li>

<li><strong>Mark controversial papers that would benefit from additional review.</strong>
 Tell PC members to add the tag &ldquo;controversy&rdquo; when the current reviewers disagree.
 A <a href='${ConfSiteBase}search$ConfSiteSuffix?q=tag:controversy'>search</a> shows you where the PC thinks more review is needed.</li>

<li><strong>Mark PC-authored papers for extra scrutiny.</strong>
 First, <a href='${ConfSiteBase}search$ConfSiteSuffix?t=s&amp;qt=au'>search for PC members' last names in author fields</a>.
 Check for accidental matches and select the papers with PC members as authors, then use the action area below the search list to add the tag &ldquo;pcpaper&rdquo;.
 A <a href='${ConfSiteBase}search$ConfSiteSuffix?t=s&amp;qx=tag:pcpaper'>search</a> shows papers without PC authors.
 (Since PC members can see whether a paper is tagged &ldquo;pcpaper&rdquo;, you may want to delay defining the tag until just before the meeting.)</li>

<li><strong>Define a discussion order for the PC meeting.</strong>
 Publishing the order lets PC members prepare to discuss upcoming papers.
 Define an ordered tag such as &ldquo;discuss&rdquo; (see below for how), then ask the PC to <a href='${ConfSiteBase}search$ConfSiteSuffix?q=order:discuss'>search for &ldquo;order:discuss&rdquo;</a>.
 The PC can now see the order and use quick links to go from paper to paper.$conflictmsg2</li>

<li><strong>Mark tentative decisions during the PC meeting.</strong>
 Chairs add &ldquo;accept&rdquo; and &ldquo;reject&rdquo; tags as decisions are made, leaving the explicit decision setting for the end of the meeting.
 Among the reasons for this: PC members can see decisions as soon as they are entered into the system, even for conflicted papers, but they can't see tags for conflicted papers unless you explicitly allow it.</li>
</ul>
");
    _alternateRow("Finding tags", "
A paper's tags are shown on its <a href='${ConfSiteBase}review$ConfSiteSuffix'>review page</a> and the other paper pages.

<p><img src='${ConfSiteBase}images/extagsnone.png' alt='[Tag list on review screen]' /></p>

To find all papers with tag &ldquo;discuss&rdquo;:&nbsp; " . _searchForm("tag:discuss") . "

<p>Tags are only shown to PC members and administrators.
$conflictmsg3$setting
Additionally, twiddle tags, which have names like &ldquo;~tag&rdquo;, are
visible only to their creators; each PC member has an independent set.</p>");
    _alternateRow("Changing tags", "
To change a single paper's tags, go to the Tags entry on its <a href='${ConfSiteBase}review$ConfSiteSuffix'>review page</a>,
click <img src='${ConfSiteBase}images/next.png' alt='right arrow' />,
then enter one or more alphanumeric tags separated by spaces.

<p><img src='${ConfSiteBase}images/extagsset.png' alt='[Tags entry on review screen]' /></p>

<p>To tag multiple papers at once, find the papers in a
<a href='${ConfSiteBase}search$ConfSiteSuffix'>search</a>, select
their checkboxes, and add tags using the action area.</p>

<p><img src='${ConfSiteBase}images/extagssearch.png' alt='[Setting tags on the search page]' /></p>

<p>You can <b>Add</b> and <b>Remove</b> tags to/from the selected papers, or
<b>Define</b> a tag, which adds the tag to all selected papers and removes it
from all non-selected papers.</p>

Although any PC member can view or search
any tag, only PC chairs can change certain tags$chairtags.  $setting");
    _alternateRow("Ordered tags<br />and discussion orders", "
An ordered tag names an <i>ordered</i> set of papers.  Searching for the
tag with &ldquo;<a href='${ConfSiteBase}search$ConfSiteSuffix?q=order:tagname'>order:tagname</a>&rdquo; will return the papers in the order
you defined.  This is useful for PC meeting discussion orders, for example.
In tag listings, the first paper in the &ldquo;discuss&rdquo; ordered tag will appear as
&ldquo;discuss#1&rdquo;, the second as &ldquo;discuss#2&rdquo;, and so forth; you can change
the order by editing the tag numbers.

<p>It's easiest to define ordered tags using the
<a href='${ConfSiteBase}search$ConfSiteSuffix'>search screen</a>.  Search for the
papers you want, sort them into the right order, select them, and
choose <b>Define ordered</b> in the tag action area.  If no sort
gives what you want, search for the desired paper numbers in order.
For instance, you might search for &ldquo;<a href='${ConfSiteBase}search$ConfSiteSuffix?q=4+1+12+9'>4 1 12 19</a>&rdquo;, then <b>Select all</b> and <b>Define ordered</b>.</p>");
    echo "</table>\n";
}



function chair() {
    global $ConfSiteBase, $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("Submission time", "
Follow these steps to prepare to accept paper submissions.

<ol>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=acc'>Set up PC
  member accounts</a></strong> and decide whether to collect authors'
  snail-mail addresses and phone numbers.</li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=sub'>Set submission
  policies</a></strong>, including whether submission is blind, whether
  authors check off conflicted PC members (\"Collect authors' PC conflicts
  with checkboxes\"), and whether authors must enter additional non-PC collaborators,
  which can help detect conflicts with external reviewers (\"Collect authors'
  other collaborators as text\").</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=sub'>Set submission
  deadlines.</a></strong> Authors first <em>register</em>, then <em>submit</em>
  their papers, possibly multiple times; they choose for each submitted
  version whether that version is ready for review.  Normally, HotCRP allows
  authors to update their papers until the deadline, but you can also require
  that authors \"freeze\" each submission explicitly; only 
  administrators can update frozen submissions.
  The only deadline that really matters is the paper submission
  deadline, but HotCRP also supports a separate paper registration deadline,
  which will force authors to register a few days before they submit.  An
  optional <em>grace period</em> applies to both deadlines:
  HotCRP reports the deadlines, but allows submissions and updates post-deadline
  for the specified grace period.  This provides some
  protection against last-minute server overload and gives authors
  some slack.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=opt'>Define
  submission options (optional).</a></strong>  You can add
  additional checkboxes to the submission form, such as \"Consider this
  paper for the Best Student Paper award\" or \"Provide this paper to the
  European shadow PC.\"  You can
  <a href='${ConfSiteBase}search$ConfSiteSuffix'>search</a> for papers with or without
  each option.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=opt'>Define paper
  topics (optional).</a></strong> Authors can select topics, such as
  \"Applications\" or \"Network databases,\" that characterize their
  paper's subject areas.  PC members express topics for which they have high,
  medium, and low interest, improving automatic paper assignment.  Although
  explicit preferences (see below) are better than topic-based assignments,
  busy PC members might not specify their preferences; topic matching lets you
  do a reasonable job at assigning papers anyway.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=sub'>Set
  up the automated format checker (optional).</a></strong> This adds a
  &ldquo;Check format requirements&rdquo; button to the Edit Paper screen.
  Clicking the button checks the paper for formatting errors, such as going
  over the page limit.  Papers with formatting errors may still be submitted,
  since the checker itself can make mistakes, but cheating authors now have no
  excuse.</p></li>

<li><p>Take a look at a <a href='${ConfSiteBase}paper$ConfSiteSuffix?p=new'>paper
  submission page</a> to make sure it looks right.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=sub'>Open the site
  for submissions.</a></strong> Submissions will be accepted only until the
  listed deadline.</p></li>

</ol>");
    _alternateRow("Assignments", "
After the submission deadline has passed:

<ol>

<li><p>Consider looking through <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=all'>all papers</a> for
  anomalies.  Withdraw and/or delete duplicates or update details on the <a
  href='${ConfSiteBase}paper$ConfSiteSuffix'>paper pages</a> (via \"Edit paper\").
  Also consider contacting the authors of <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=status:unsub&amp;t=all'>papers that
  were never officially submitted</a>, especially if a PDF document was
  uploaded (you can tell from the icon in the search list).  Sometimes a
  user will uncheck \"The paper is ready for review\" and forget to check
  it.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=rfo'>Prepare the
  review form.</a></strong> Take a look at the templates to get
  ideas.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>Set review
  policies and deadlines</a></strong>, including reviewing deadlines, whether
  review is blind, and whether PC members may review non-assigned papers
  (usually \"yes\" is the right answer).</p></li>

<li><p><strong><a href='${ConfSiteBase}reviewprefs$ConfSiteSuffix'>Collect review
  preferences from the PC.</a></strong> PC members can rank-order papers they
  want or don't want to review.  They can either set their preferences <a
  href='${ConfSiteBase}reviewprefs$ConfSiteSuffix'>all at once</a>, or (often more
  convenient) page through the <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=s'>list of submitted papers</a>
  setting their preferences on the <a
  href='${ConfSiteBase}paper$ConfSiteSuffix'>paper pages</a>.</p>

  <p>If you'd like, you can collect review preferences before the submission
  deadline.  Select <a href='${ConfSiteBase}settings$ConfSiteSuffix?group=sub'>\"PC can
  see <i>all registered papers</i> until submission deadline\"</a>, which
  allows PC members to see abstracts for registered papers that haven't yet
  been submitted.</p></li>

<li><p><strong><a href='${ConfSiteBase}manualassign$ConfSiteSuffix?kind=c'>Assign
  conflicts.</a></strong> You can assign conflicts <a
  href='${ConfSiteBase}manualassign$ConfSiteSuffix?kind=c'>by PC member</a> or, if
  PC members have entered preferences, <a
  href='${ConfSiteBase}autoassign$ConfSiteSuffix'>automatically</a> by searching for
  preferences of &minus;100 or less.</p></li>

<li><p><strong><a href='${ConfSiteBase}manualassign$ConfSiteSuffix'>Make review
  assignments.</a></strong> You can make assignments <a
  href='${ConfSiteBase}assign$ConfSiteSuffix'>by paging through papers</a>, <a
  href='${ConfSiteBase}manualassign$ConfSiteSuffix'>by PC member</a>, or, even
  easier, <a href='${ConfSiteBase}autoassign$ConfSiteSuffix'>automatically</a>.  PC
  review assignments can be \"primary\" or \"secondary\"; the difference is
  that primary reviewers are expected to complete their review, but a
  secondary reviewer can choose to delegate their review to someone else.</p>

  <p>The default assignments pages apply to all submitted papers.  You can
  also assign subsets of papers obtained through <a
  href='help$ConfSiteSuffix?t=search'>search</a>, such as <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=cre:%3C3&amp;t=s'>papers
  with fewer than three completed reviews</a>.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>Open the site
  for reviewing.</a></strong></p></li>

</ol>
");
    _alternateRow("Before the meeting", "
Before the meeting, you will generally <a
href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>set \"PC can see all
reviews\"</a>, allowing members to view reviews and scores for
non-conflicted papers.  (In many conferences, PC members are initially
prevented from seeing a paper's reviews until they have completed their own
review for that paper; this supposedly reduces bias.)

<ol>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>Collect
  authors' responses to the reviews (optional).</a></strong> Some conferences
  allow authors to respond to the reviews before decisions are made, giving
  them a chance to correct misconceptions and such.  Responses are entered
  into the system as <a
  href='${ConfSiteBase}comment$ConfSiteSuffix'>comments</a>.  On the <a
  href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>decision settings page</a>,
  select \"Authors can see reviews\" and \"Collect responses to the
  reviews,\" then <a href='${ConfSiteBase}mail$ConfSiteSuffix'>send mail to
  authors</a> informing them of the response deadlines.  PC members will still
  be able to update their reviews, assuming it's before the <a
  href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>review deadline</a>; authors
  are informed via email of any review changes.  At the end of the response
  period it's generally good to <a
  href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>turn off \"Authors can see
  reviews\"</a> so PC members can update their reviews in peace.</p></li>

<li><p>Set <strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=rev'>PC can
  see all reviews</a></strong> if you haven't already.</p></li>

<li><p><strong><a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=s&amp;sort=50'>Examine paper
  scores</a></strong>, either one at a time or en masse, and decide which
  papers will be discussed.  The <a href='help$ConfSiteSuffix?t=tags'>tags</a> system
  lets you prepare discussion sets and even discussion orders.</p></li>

<li><p><strong><a href='${ConfSiteBase}autoassign$ConfSiteSuffix'>Assign discussion leads
  (optional).</a></strong> Discussion leads are expected to be able to
  summarize the paper and the reviews.  You can assign leads either <a
  href='${ConfSiteBase}assign$ConfSiteSuffix'>paper by paper</a> or <a
  href='${ConfSiteBase}autoassign$ConfSiteSuffix'>automatically</a>.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>Define decision
  types (optional).</a></strong> By default, HotCRP has two decision types,
  \"accept\" and \"reject\", but you can add other types of acceptance and
  rejection, such as \"accept as short paper\".</p></li>

<li><p>The night before the meeting, <strong><a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=s'>download all
  reviews onto a laptop</a></strong> (Download &gt; All reviews) in case the
  Internet explodes and you can't reach HotCRP from the meeting
  place.</p></li>

</ol>
");
    _alternateRow("At the meeting", "
<ol>

<li><p>It's often useful to have a PC member or scribe capture the discussion
  about a paper and enter it as a <a
  href='${ConfSiteBase}comment$ConfSiteSuffix'>comment</a> for the authors'
  reference.</p></li>

<li><p><strong>Paper decisions</strong> can be recorded on the <a
  href='${ConfSiteBase}review$ConfSiteSuffix'>review screens</a> or en masse
  via the <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=s'>search
  screen</a>.  Note that PC members can see paper decisions as soon as they
  are entered into the system, even when they have a conflict.  If you don't
  like this, mark decisions with <a href='help$ConfSiteSuffix?t=tags'>tags</a> until the
  meeting is over.</p></li>

<li><p><strong>Shepherding (optional).</strong> If your conference uses
  shepherding for accepted papers, you can assign shepherds either <a
  href='${ConfSiteBase}assign$ConfSiteSuffix'>paper by paper</a> on the
  assignments screen or <a
  href='${ConfSiteBase}autoassign$ConfSiteSuffix?t=acc'>automatically</a>.</p></li>

</ol>
");
    _alternateRow("After the meeting", "
<ol>

<li><p><strong><a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=&amp;t=s'>Enter
  decisions</a> and <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=dec:yes&amp;t=s'>shepherds</a></strong>
  if you didn't do this at the meeting.</p></li>

<li><p>Give reviewers some time to <strong>update their reviews</strong> in
  response to PC discussion (optional).</p></li>

<li><p>Set <strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>Authors can
  see reviews and decisions.</a></strong></p></li>

<li><p><strong><a href='${ConfSiteBase}mail$ConfSiteSuffix'>Send mail to
  authors</a></strong> informing them that reviews and decisions are
  available.  The mail can also contain the reviews and comments
  themselves.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings$ConfSiteSuffix?group=dec'>Collect final
  papers (optional).</a></strong> If you're putting together the program
  yourself, it can be convenient to collect final copies using HotCRP.
  Authors upload final copies the same way they did the submission, although
  the submitted version is archived for reference.  You can then <a
  href='${ConfSiteBase}search$ConfSiteSuffix?q=dec:yes&amp;t=s'>download
  all final copies as a <tt>.zip</tt> archive</a>.</p></li>

</ol>
");
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
else if ($topic == "chair")
    chair();

$Conf->footer();
