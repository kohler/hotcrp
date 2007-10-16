<?php 
// help.php -- HotCRP help page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


$topicTitles = array("topics" => "Help topics",
		     "syntax" => "Search syntax",
		     "search" => "Search",
		     "tags" => "Tags",
		     "chair" => "Chair's guide");

$topic = defval($_REQUEST["t"], "topics");
if (!isset($topicTitles[$topic]))
    $topic = "topics";

$abar = "<div class='vbar'><table class='vbar'><tr><td><table><tr>\n";
$abar .= actionTab("Help topics", "help.php?t=topics", $topic == "topics");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search help", "help.php?t=search", $topic == "search");
if ($topic == "search" || $topic == "syntax")
    $abar .= actionTab("Search syntax", "help.php?t=syntax", $topic == "syntax");
if ($topic != "topics" && $topic != "search" && $topic != "syntax")
    $abar .= actionTab($topicTitles[$topic], "help.php?t=$topic", true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper' nowrap='nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";

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
    _alternateRow("<a href='help.php?t=chair'>Chair's guide</a>", "How to run a conference using HotCRP.");
    _alternateRow("<a href='help.php?t=search'>Search</a>", "About paper searching.");
    _alternateRow("<a href='help.php?t=syntax'>Search syntax</a>", "Quick reference to search syntax.");
    _alternateRow("<a href='help.php?t=tags'>Tags</a>", "How to use tags to define paper sets and discussion orders.");
    echo "</table>";
}


function _searchForm($forwhat, $other = null) {
    global $ConfSiteBase;
    $text = "";
    if ($other && preg_match_all('/(\w+)=([^&]*)/', $other, $matches, PREG_SET_ORDER))
	foreach ($matches as $m)
	    $text .= "<input type='hidden' name='$m[1]' value=\"" . htmlspecialchars(urldecode($m[2])) . "\" />";
    return "<form method='get' action='${ConfSiteBase}search.php'>"
	. "<input type='text' class='textlite' name='q' value=\""
	. htmlspecialchars($forwhat) . "\" size='20' /> &nbsp;"
	. "<input type='submit' class='button' name='go' value='Search' />"
	. $text . "</form>";
}

function search() {
    global $ConfSiteBase;
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
<li>Investigate <a href='${ConfSiteBase}help.php?t=syntax'>search syntax</a>.</li>
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
<a href='${ConfSiteBase}search.php?opt=1'>Advanced search</a>
and use \"With <b>all</b> the words\" and \"<b>Without</b> the words\".

<p>You can search in several paper classes, depending on your role in the
conference. Options include:</p>
<ul class='compact'>
<li><b>Submitted papers</b> &mdash; all submitted papers.</li>
<li><b>All papers</b> &mdash; all papers, including withdrawn and other non-submitted papers.</li>
<li><b>My papers</b> &mdash; papers for which you're a contact author.</li>
<li><b>My reviews</b> &mdash; papers you've been assigned to review.</li>
<li><b>My incomplete reviews</b> &mdash; papers you've been assigned to review, but haven't submitted a review yet.</li>
</ul>

<p>Search won't show you information you aren't supposed to see.  For example,
authors can only search their own submissions, and if the conference used
anonymous submission, then only the PC chairs can search by author.</p>

<p>By default, search examines paper titles, abstracts, and authors.
<a href='${ConfSiteBase}search.php?opt=1'>Advanced search</a>
can search other fields, including authors/collaborators and reviewers.
Also, <b>keywords</b> search specific characteristics such as titles,
authors, reviewer names, and numbers of reviewers.  For example,
\"ti:foo\" means \"search for 'foo' in paper
titles\".  Keywords are listed in the
<a href='help.php?t=syntax'>search syntax reference</a>.</p>");
    _alternateRow("Search results", "
Click on a paper number or paper title to jump to that paper's screen.
Search matches are <span class='match'>highlighted</span> on paper screens,
which, for example, makes it easier to tell whether a conflict is real.
Once on a paper screen use <a href='#quicklinks'>quicklinks</a>
to navigate through the rest of the search matches.

<p>The search results screen also lets you act on many papers at once.
Select the checkboxes for the interesting papers, then choose an action
underneath the paper list.  You can:</p>

<ul class='compact'>
<li>Download a <tt>.zip</tt> file with the selected papers.</li>
<li>Download all reviews for the selected papers.</li>
<li>Download tab-separated text files with authors, PC
 conflicts, review scores, and so forth (some options only
 available to chairs).</li>
<li>Add, remove, and define <a href='${ConfSiteBase}help.php?t=tags'>tags</a>.</li>
<li>Mark conflicts, assign reviewers, or assign reviewers automatically (chairs only).</li>
<li>Set decisions (chairs only).</li>
</ul>

<p>Interact with the action area to see what you can do.
Select papers by clicking their checkboxes, by <i>shift</i>-clicking on
either end of a <i>range</i> of checkboxes, or by clicking the \"Select all\"
link underneath the list.
For instance, the easiest way to tag a set of papers is to enter their numbers
in the search box, search, \"Select all\", and add the tag.</p>
");
    _alternateRow("<a name='quicklinks'>Quicksearch<br />and quicklinks</a>", "
Most screens have a quicksearch box in the upper right corner:<br />
<img src='${ConfSiteBase}images/quicksearchex.png' alt='[Quick search example]' /><br />
This box supports the full search syntax, but entering
a single paper number, or any search terms that match exactly
one paper, will take you directly to that paper's screen.

<p>All paper screens have quicklinks in the upper right corner that navigate
through the most recent search results:<br />
<img src='${ConfSiteBase}images/pageresultsex.png' alt='[Result paging example]' /><br />
Using these links can speed up many tasks.  Click on the search description
(here, \"This search\") to return to the search results.</p>
");
    echo "</table>\n";
}

function _searchQuickrefRow($caption, $search, $explanation, $other = null) {
    global $rowidx, $ConfSiteBase;
    $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
    echo "<tr class='k", ($rowidx % 2), "'>";
    echo "<td class='srcaption nowrap'>", $caption, "</td>";
    echo "<td class='sentry nowrap'>", _searchForm($search, $other), "</td>";
    echo "<td class='sentry'>", $explanation, "<span class='sep'></span></td></tr>\n";
}

function searchQuickref() {
    global $rowidx;
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
    _searchQuickrefRow("<a href='help.php?t=tags'>Tags</a>", "tag:discuss", "tagged \"discuss\"");
    _searchQuickrefRow("", "notag:discuss", "not tagged \"discuss\"");
    _searchQuickrefRow("", "order:discuss", "tagged \"discuss\", sort by tag order");
    _searchQuickrefRow("Reviews", "re:fdabek", "\"fdabek\" in reviewer name/email");
    _searchQuickrefRow("", "cre:fdabek", "\"fdabek\" (in reviewer name/email) has completed a review");
    _searchQuickrefRow("", "re:4", "four reviewers (assigned and/or completed)");
    _searchQuickrefRow("", "cre:<3", "less than three completed reviews");
    _searchQuickrefRow("", "pri:>=1", "at least one primary reviewer (\"cpri:\" and reviewer name/email also work)");
    _searchQuickrefRow("", "sec:pai", "\"pai\" (reviewer name/email) is secondary reviewer (\"csec:\" and review counts also work)");
    _searchQuickrefRow("", "lead:fdabek", "\"fdabek\" (in name/email) is discussion lead");
    _searchQuickrefRow("", "lead:none", "no assigned discussion lead");
    _searchQuickrefRow("", "lead:any", "some assigned discussion lead");
    _searchQuickrefRow("", "shep:fdabek", "\"fdabek\" (in name/email) is shepherd (\"none\" and \"any\" also work)");
    _searchQuickrefRow("", "conflict:fdabek", "\"fdabek\" (in name/email) has a conflict with the paper");
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
    global $ConfSiteBase;

    // get current chair-only tags
    require_once('Code/tags.inc');
    $ct = array_keys(chairTags());
    if (count($ct)) {
	sort($ct);
	$ctxt = " (currently ";
	foreach ($ct as $c)
	    $ctxt .= "\"$c\", ";
	$ctxt = substr($ctxt, 0, strlen($ctxt) - 2) . ")";
    } else
	$ctxt = "";

    echo "<table>";
    _alternateRow("Basics", "
Tags are names that PC members and chairs can attach to papers.
Any paper can have any number of tags, and there's no need to predefine which
tags are allowed; you can invent new ones on the fly.
Tags are never shown to authors or conflicted PC members.
It's easy to add and remove tags and to list all papers with a given tag,
and <i>ordered</i> tags preserve a particular paper order.");
    _alternateRow("Using tags", "
Here are some example ways to use tags.

<ul>
<li><strong>Avoid discussing low-ranked submissions at the PC meeting.</strong>
 Mark low-ranked submissions with tag \"nodiscuss\", then ask the PC to
 <a href='${ConfSiteBase}search.php?q=tag:nodiscuss'>search for \"tag:nodiscuss\"</a>.
 PC members can easily check the list for controversial papers they'd like to discuss despite their ranking.
 They can email the chairs about such papers, or, even easier, add a \"discussanyway\" tag.
 (You might make the \"nodiscuss\" tag chair-only so an evil PC member couldn't add it to a high-ranked paper, but it's usually better to trust the PC.)</li>

<li><strong>Mark controversial papers that would benefit from additional review.</strong>
 Tell PC members to add the tag \"controversy\" when the current reviewers disagree.
 A <a href='${ConfSiteBase}search.php?q=tag:controversy'>search</a> shows you where the PC thinks more review is needed.</li>

<li><strong>Mark PC-authored papers for extra scrutiny.</strong>
 First, <a href='${ConfSiteBase}search.php?t=s&amp;qt=au'>search for PC members' last names in author fields</a>.
 Check for accidental matches and select the papers with PC members as authors, then use the action area below the search list to add the tag \"pcpaper\".
 A <a href='${ConfSiteBase}search.php?t=s&amp;qx=tag:pcpaper'>search</a> shows papers without PC authors.
 (Since PC members can see whether a paper is tagged \"pcpaper\", you may want to delay defining the tag until just before the meeting.)</li>

<li><strong>Define a discussion order for the PC meeting.</strong>
 Publishing the order lets PC members prepare to discuss upcoming papers.
 Define an ordered tag such as \"discuss\" (see below for how), then ask the PC to <a href='${ConfSiteBase}search.php?q=order:discuss'>search for \"order:discuss\"</a>.
 The PC can now see the order and use quick links to go from paper to paper.</li>

<li><strong>Mark tentative decisions during the PC meeting.</strong>
 Chairs add \"accept\" and \"reject\" tags as decisions are made, leaving the explicit decision setting for the end of the meeting.
 Among the reasons for this: PC members can see decisions as soon as they are entered into the system, even for conflicted papers, but they can never see tags for conflicted papers.</li>
</ul>
");
    _alternateRow("Finding tags", "
A list of each paper's tags is shown on its <a href='${ConfSiteBase}review.php'>review page</a>, and the other paper pages.

<p><img src='${ConfSiteBase}images/extagsnone.png' alt='[Tag list on review screen]' /></p>

To find all papers with tag \"discuss\":&nbsp; " . _searchForm("tag:discuss") . "

<p>Only PC members can view a paper's tags.
If a PC member has a conflict with a paper, they can't see its tags either
directly or through searches.</p>");
    _alternateRow("Changing tags", "
To change a single paper's tags, go to the Tags entry on its <a href='${ConfSiteBase}review.php'>review page</a>,
click the <img src='${ConfSiteBase}images/next.png' alt='right arrow' />,
then enter one or more alphanumeric tags separated by spaces.

<p><img src='${ConfSiteBase}images/extagsset.png' alt='[Tags entry on review screen]' /></p>

<p>To tag multiple papers at once, find the papers in a
<a href='${ConfSiteBase}search.php'>search</a>, select
their checkboxes, and add tags using the action area.</p>

<p><img src='${ConfSiteBase}images/extagssearch.png' alt='[Setting tags on the search page]' /></p>

<p>You can <b>Add</b> and <b>Remove</b> tags to/from the selected papers, or
<b>Define</b> a tag, which adds the tag to all selected papers and removes it
from all non-selected papers.</p>

Although any PC member can view or search
any tag, only PC chairs can change certain tags$ctxt.
Change the set of chair-only tags on the
<a href='${ConfSiteBase}settings.php?group=rev'>conference settings page</a>.");
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
choose <b>Define ordered</b> in the tag action area.  If no sort
gives what you want, search for the desired paper numbers in order.
For instance, you might search for \"<a href='${ConfSiteBase}search.php?q=4+1+12+9'>4 1 12 19</a>\", then <b>Select all</b> and <b>Define ordered</b>.</p>");
    echo "</table>\n";
}



function chair() {
    global $ConfSiteBase;
    echo "<table>";
    _alternateRow("Submission time", "
Follow these steps to prepare to accept paper submissions.

<ol>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=acc'>Set up PC
  member accounts</a></strong> and decide whether to collect authors'
  snail-mail addresses and phone numbers.</li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=sub'>Set submission
  policies</a></strong>, including whether submission is blind, whether
  authors check off conflicted PC members (\"Collect authors' PC conflicts
  with checkboxes\"), and whether authors must enter additional non-PC collaborators,
  which can help detect conflicts with external reviewers (\"Collect authors'
  other collaborators as text\").</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=sub'>Set submission
  deadlines.</a></strong> Authors first <em>register</em>, then <em>submit</em>
  their papers, possibly multiple times; authors choose for each submitted
  version whether that version is ready for review.  Normally, HotCRP allows
  authors to update their papers until the deadline, but you can also require
  that authors \"freeze\" each submission explicitly; only 
  administrators can update frozen submissions.
  The only deadline that really matters is the paper submission
  deadline, but HotCRP also supports a separate paper registration deadline,
  which will force authors to register a few days before they submit.  The
  optional <em>grace period</em>, which applies to both deadlines, can give
  authors some slack. HotCRP will allow post-deadline submissions and updates
  for the specified grace period.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=opt'>Define
  submission options (optional).</a></strong> If desired, the paper submission
  form can list additional checkboxes, such as \"Consider this
  paper for the Best Student Paper award\" or \"Provide this paper to the
  European shadow PC\", that submitters can select.  You can
  <a href='${ConfSiteBase}search.php'>search</a> for papers with or without
  each option.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=opt'>Define paper
  topics (optional).</a></strong> Authors can select topics, such as
  \"Applications\" or \"Network databases\", that characterize their
  paper's subject areas.  PC members express topics for which they have high,
  medium, and low interest, improving automatic paper assignment.  Although
  explicit preferences (see below) are better than topic-based assignments,
  busy PC members might not specify their preferences; topic matching lets you
  do a reasonable job at assigning papers anyway.</p></li>

<li><p>Take a look at a <a href='${ConfSiteBase}paper.php?paperId=new'>paper
  submission page</a> to make sure it looks right.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=sub'>Open the site
  for submissions.</a></strong> Submissions will be accepted only until the
  listed deadline.</p></li>

</ol>");
    _alternateRow("Assignments", "
After the submission deadline has passed, you may want to look through <a
href='${ConfSiteBase}search.php?q=&amp;t=all'>all papers</a> for anomalies.
You can withdraw and delete papers and update their details on the edit
screens (select \"Edit paper\").  You might want to contact the authors of any
papers that never got officially submitted.  Then:

<ol>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=rfo'>Prepare the
  review form.</a></strong> Take a look at the templates to get
  ideas.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=rev'>Set review
  policies and deadlines</a></strong>, including reviewing deadlines, whether
  review is blind, and whether PC members may review non-assigned papers
  (usually \"yes\" is the right answer).</p></li>

<li><p><strong><a href='${ConfSiteBase}PC/reviewprefs.php'>Collect review
  preferences from the PC.</a></strong> PC members can rank-order papers they
  want or don't want to review.  They can either set their preferences <a
  href='${ConfSiteBase}PC/reviewprefs.php'>all at once</a>, or (often more
  convenient) page through the <a
  href='${ConfSiteBase}search.php?q=&amp;t=s'>list of submitted papers</a>
  setting their preferences on the <a
  href='${ConfSiteBase}paper.php'>paper pages</a>.</p>

  <p>If you'd like, you can collect review preferences before the submission
  deadline.  Select <a href='${ConfSiteBase}settings.php?group=sub'>\"PC can
  see <i>all registered papers</i> until submission deadline\"</a>, which
  allows PC members to see abstracts for registered papers that haven't yet
  been submitted.</p></li>

<li><p><strong><a href='${ConfSiteBase}Chair/AssignPapers.php?kind=c'>Assign
  conflicts.</a></strong> You can assign conflicts <a
  href='${ConfSiteBase}Chair/AssignPapers.php?kind=c'>by PC member</a> or, if
  PC members have entered preferences, <a
  href='${ConfSiteBase}autoassign.php'>automatically</a> by searching for
  preferences of &minus;100 or less.</p></li>

<li><p><strong><a href='${ConfSiteBase}Chair/AssignPapers.php'>Make review
  assignments.</a></strong> You can make assignments <a
  href='${ConfSiteBase}assign.php'>by paging through papers</a>, <a
  href='${ConfSiteBase}Chair/AssignPapers.php'>by PC member</a>, or, even
  easier, <a href='${ConfSiteBase}autoassign.php'>automatically</a>.  PC
  review assignments can be \"primary\" or \"secondary\"; the difference is
  that primary reviewers are expected to complete their review, but a
  secondary reviewer can choose to delegate their review to someone else (once
  they've invited an external reviewer).</p>

  <p>The default assignments pages apply to all submitted papers.  You can
  also assign subsets of papers obtained through <a
  href='help.php?t=search'>search</a>, such as <a
  href='${ConfSiteBase}search.php?q=cre:%3C3&amp;t=s'>papers
  with fewer than three completed reviews</a>.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=rev'>Open the site
  for reviewing.</a></strong></p></li>

</ol>
");
    _alternateRow("Before the meeting", "
Before the meeting, you will generally open up the site to the whole PC,
allowing members to view reviews and scores for non-conflicted papers.  (In
most conferences, PC members are initially prevented from seeing a paper's
reviews until they have completed their own review for that paper.  This
supposedly reduces bias.)

<ol>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=dec'>Collect
  authors' responses to the reviews (optional).</a></strong> Some conferences
  allow authors to respond to the reviews before decisions are made, giving
  them a chance to correct misconceptions and such.  Responses are entered
  into the system as <a
  href='${ConfSiteBase}comment.php'>comments</a>.  On the <a
  href='${ConfSiteBase}settings.php?group=dec'>decision settings page</a>,
  select \"Allow authors to see reviews\" and \"Collect responses to the
  reviews\", then <a href='${ConfSiteBase}mail.php'>send mail to
  authors</a> informing them of the response deadlines.  PC members will still
  be able to update their reviews, assuming it's before the <a
  href='${ConfSiteBase}settings.php?group=rev'>review deadline</a>; authors
  are informed via email of any review changes.  At the end of the response
  period it's generally good to <a
  href='${ConfSiteBase}settings.php?group=dec'>turn off \"Allow authors to see
  reviews\"</a> so PC members can update their reviews in peace.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=rev'>Allow the PC to
  see all submitted reviews</a></strong> if you haven't already.</p></li>

<li><p><strong><a
  href='${ConfSiteBase}search.php?q=&amp;t=s&amp;sort=50'>Examine paper
  scores</a></strong>, either one at a time or en masse, and decide which
  papers will be discussed.  The <a href='help.php?t=tags'>tags</a> system
  lets you prepare discussion sets and even discussion orders.</p></li>

<li><p><strong><a href='${ConfSiteBase}autoassign.php'>Assign discussion leads
  (optional).</a></strong> Discussion leads are expected to be able to
  summarize the paper and the reviews.  You can assign leads either <a
  href='${ConfSiteBase}assign.php'>paper by paper</a> or <a
  href='${ConfSiteBase}autoassign.php'>automatically</a>.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=dec'>Define decision
  types (optional).</a></strong> By default, HotCRP has two decision types,
  \"accept\" and \"reject\", but you can add other types of acceptance and
  rejection, such as \"accept as short paper\".</p></li>

<li><p>The night before the meeting, <strong><a
  href='${ConfSiteBase}search.php?q=&amp;t=s'>download all
  reviews onto a laptop</a></strong> (Get &gt; All reviews) in case the
  Internet explodes and you can't reach HotCRP from the meeting
  place.</p></li>

</ol>
");
    _alternateRow("At the meeting", "
<ol>

<li><p>It's often useful to have a PC member or scribe capture the discussion
  about a paper and enter it as a <a
  href='${ConfSiteBase}comment.php'>comment</a> for the authors'
  reference.</p></li>

<li><p><strong>Paper decisions</strong> can be recorded on the <a
  href='${ConfSiteBase}review.php'>review screens</a> or en masse
  via the <a
  href='${ConfSiteBase}search.php?q=&amp;t=s'>search
  screen</a>.  Note that PC members can see paper decisions as soon as they
  are entered into the system, even when they have a conflict.  If you don't
  like this, mark decisions with <a href='help.php?t=tags'>tags</a> until the
  meeting is over; PC members can never see tags for conflicted papers.</p></li>

<li><p><strong>Shepherding (optional).</strong> If your conference uses
  shepherding for accepted papers, you can assign shepherds either <a
  href='${ConfSiteBase}assign.php'>paper by paper</a> on the
  assignments screen or <a
  href='${ConfSiteBase}autoassign.php?t=acc'>automatically</a>.</p></li>

</ol>
");
    _alternateRow("After the meeting", "
<ol>

<li><p><strong><a
  href='${ConfSiteBase}search.php?q=&amp;t=s'>Enter
  decisions</a> and <a
  href='${ConfSiteBase}search.php?q=dec:yes&amp;t=s'>shepherds</a></strong>
  if you didn't do this at the meeting.</p></li>

<li><p>Give reviewers some time to <strong>update their reviews</strong> in
  response to PC discussion (optional).</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=dec'>Allow authors to
  see reviews and decisions.</a></strong></p></li>

<li><p><strong><a href='${ConfSiteBase}mail.php'>Send mail to
  authors</a></strong> informing them that reviews and decisions are
  available.  The mail can also contain the reviews and comments
  themselves.</p></li>

<li><p><strong><a href='${ConfSiteBase}settings.php?group=dec'>Collect final
  papers (optional).</a></strong> If you're putting together the program
  yourself, it can be convenient to collect final copies using HotCRP.
  Authors upload final copies the same way they did the submission, although
  the submitted version is archived for reference.  You can then <a
  href='${ConfSiteBase}search.php?q=dec:yes&amp;t=s'>download
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
