<?php
// help.php -- HotCRP help page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me = $_SESSION["Me"];
$Me->valid();


$topicTitles = array("topics" => "Help topics",
		     "keywords" => "Search keywords",
		     "search" => "Search",
		     "tags" => "Tags",
		     "revround" => "Review rounds",
		     "revrate" => "Review ratings",
		     "votetags" => "Voting tags",
		     "scoresort" => "Sorting scores",
		     "chair" => "Chair's guide");

$topic = defval($_REQUEST, "t", "topics");
if ($topic == "syntax")
    $topic = "keywords";
if (!isset($topicTitles[$topic]))
    $topic = "topics";

$abar = "<div class='vbar'><table class='vbar'><tr><td id='vbartabs'><table><tr>\n";
$abar .= actionTab("Help topics", "help$ConfSiteSuffix?t=topics", $topic == "topics");
if ($topic == "search" || $topic == "keywords")
    $abar .= actionTab("Search help", "help$ConfSiteSuffix?t=search", $topic == "search");
if ($topic == "search" || $topic == "keywords")
    $abar .= actionTab("Search keywords", "help$ConfSiteSuffix?t=keywords", $topic == "keywords");
if ($topic != "topics" && $topic != "search" && $topic != "keywords")
    $abar .= actionTab($topicTitles[$topic], "help$ConfSiteSuffix?t=$topic", true);
$abar .= "</tr></table></td>\n<td class='spanner'></td>\n<td class='gopaper nowrap'>" . goPaperForm() . "</td></tr></table></div>\n";

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
    global $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("<a href='help$ConfSiteSuffix?t=chair'>Chair's guide</a>", "How to run a conference using HotCRP.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=search'>Search</a>", "About paper searching.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=keywords'>Search keywords</a>", "Quick reference to search keywords and search syntax.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=tags'>Tags</a>", "How to use tags to define paper sets and discussion orders.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=revround'>Review rounds</a>", "Defining review rounds.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=revrate'>Review ratings</a>", "Rating reviews.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=votetags'>Voting tags</a>", "Voting for papers.");
    _alternateRow("<a href='help$ConfSiteSuffix?t=scoresort'>Sorting scores</a>", "How scores are sorted in paper lists.");
    echo "</table>";
}


function _searchForm($forwhat, $other = null) {
    global $ConfSiteSuffix;
    $text = "";
    if ($other && preg_match_all('/(\w+)=([^&]*)/', $other, $matches, PREG_SET_ORDER))
	foreach ($matches as $m)
	    $text .= "<input type='hidden' name='$m[1]' value=\"" . htmlspecialchars(urldecode($m[2])) . "\" />";
    return "<form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'>"
	. "<input type='text' class='textlite' name='q' value=\""
	. htmlspecialchars($forwhat) . "\" size='20' /> &nbsp;"
	. "<input type='submit' class='b' name='go' value='Search' />"
	. $text . "</form>";
}

function search() {
    global $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("Search basics", "
All HotCRP paper lists are obtained through search, search syntax is flexible,
and it's possible to download all matching papers and/or reviews at once.

<p>Some useful hints for PC members and chairs:</p>

<ul class='compact'>
<li>" . _searchForm("") . "&nbsp; finds all papers.  (Leave the search field blank.)</li>
<li>" . _searchForm("12") . "&nbsp; finds paper #12.  When entered from a
 <a href='#quicklinks'>quicksearch</a> box, this search will jump to
 paper #12 directly.</li>
<li><a href='help$ConfSiteSuffix?t=keywords'>Search keywords</a>
 let you search specific fields, review scores, and more.</li>
<li>Use <a href='#quicklinks'>quicklinks</a> on paper pages to navigate
 through search results.</li>
<li>On search results pages, <em>shift-click</em> the checkboxes to
 select paper ranges.</li>
</ul>
");
    _alternateRow("How to search", "
The default search box returns papers that match
<em>all</em> of the space-separated terms you enter.
To search for words that <em>start</em> with
a prefix, try &ldquo;term*&rdquo;.
To search for papers that match <em>some</em> of the terms,
type &ldquo;term1 OR term2&rdquo;.
To search for papers that <em>don't</em> match a term,
try &ldquo;-term&rdquo;.  Or select
<a href='search$ConfSiteSuffix?opt=1'>Advanced search</a>
and use \"With <b>any</b> of the words\" and \"<b>Without</b> the words\".

<p>You can search in several paper classes, depending on your role in the
conference. Options include:</p>
<ul class='compact'>
<li><b>Submitted papers</b> &mdash; all submitted papers.</li>
<li><b>All papers</b> &mdash; all papers, including withdrawn and other non-submitted papers.</li>
<li><b>Your submissions</b> &mdash; papers for which you're a contact author.</li>
<li><b>Your reviews</b> &mdash; papers you've been assigned to review.</li>
<li><b>Your incomplete reviews</b> &mdash; papers you've been assigned to review, but haven't submitted a review yet.</li>
</ul>

<p>Search won't show you information you aren't supposed to see.  For example,
authors can only search their own submissions, and if the conference used
anonymous submission, then only the PC chairs can search by author.</p>

<p>By default, search examines paper titles, abstracts, and authors.
<a href='search$ConfSiteSuffix?opt=1'>Advanced search</a>
can search other fields, including authors/collaborators and reviewers.
Also, <b>keywords</b> search specific characteristics such as titles,
authors, reviewer names, and numbers of reviewers.  For example,
\"ti:foo\" means \"search for 'foo' in paper
titles\".  Keywords are listed in the
<a href='help$ConfSiteSuffix?t=keywords'>search keywords reference</a>.</p>");
    _alternateRow("Search results", "
Click on a paper number or title to jump to that paper.
Search matches are <span class='match'>highlighted</span> on paper screens,
which, for example, makes it easier to tell whether a conflict is real.
Once on a paper screen use <a href='#quicklinks'>quicklinks</a>
to navigate through the rest of the search matches.

<p>Underneath the paper list is the action area:</p>

<img src='images/exsearchaction.png' alt='[Search action area example]' /><br />

<p>Use the checkboxes to select some papers, then choose an action.
You can:</p>

<ul class='compact'>
<li>Download a <tt>.zip</tt> file with the selected papers.</li>
<li>Download all reviews for the selected papers.</li>
<li>Download tab-separated text files with authors, PC
 conflicts, review scores, and so forth (some options chairs only).</li>
<li>Add, remove, and define <a href='help$ConfSiteSuffix?t=tags'>tags</a>.</li>
<li>Assign reviewers and mark conflicts (chairs only).</li>
<li>Set decisions (chairs only).</li>
<li>Send mail to paper authors or reviewers (chairs only).</li>
</ul>

<p>Select papers one by one, in groups by <em>shift</em>-clicking on either end
of a <em>range</em> of checkboxes, or using the \"select all\" link.
For instance, the easiest way to tag a set of papers is
to enter their numbers in the search box, search, \"select all\", and add the
tag.</p>
");
    _alternateRow("<a name='quicklinks'>Quicksearch<br />and quicklinks</a>", "
Most screens have a quicksearch box in the upper right corner:<br />
<img src='images/quicksearchex.png' alt='[Quick search example]' /><br />
This box supports the full search syntax.  Also, entering
a paper number, or search terms that match exactly
one paper, will take you directly to that paper.

<p>All paper screens have quicklinks in the upper right corner that navigate
through the most recent search results:<br />
<img src='images/pageresultsex.png' alt='[Result paging example]' /><br />
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
    global $rowidx, $Conf, $ConfSiteSuffix, $Me;

    // how to report author searches?
    if ($Conf->blindSubmission() == BLIND_NEVER)
	$aunote = "";
    else if ($Conf->blindSubmission() == BLIND_OPTIONAL)
	$aunote = "<br /><span class='hint'>Search only examines visible fields.  For example, PC member searches do not examine anonymous authors.</span>";
    else
	$aunote = "<br /><span class='hint'>Search only examines visible fields.  For example, PC member searches do not examine authors.</span>";

    echo "<table>\n";
    _searchQuickrefRow("Basics", "", "all papers in the search category");
    _searchQuickrefRow("", "story", "&ldquo;story&rdquo; in title, abstract, authors$aunote");
    _searchQuickrefRow("", "119", "paper #119");
    _searchQuickrefRow("", "1 2 5 12-24 kernel", "papers in the numbered set with &ldquo;kernel&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "\"802\"", "&ldquo;802&rdquo; in title, abstract, authors (not paper #802)");
    _searchQuickrefRow("", "very new", "&ldquo;very&rdquo; <em>and</em> &ldquo;new&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "\"very new\"", "the phrase &ldquo;very new&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "very OR new", "<em>either</em> &ldquo;very&rdquo; <em>or</em> &ldquo;new&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "very -new", "&ldquo;very&rdquo; <em>but not</em> &ldquo;new&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "ve*", "words that <em>start with</em> &ldquo;ve&rdquo; in title, abstract, authors");
    _searchQuickrefRow("", "*me*", "words that <em>contain</em> &ldquo;me&rdquo; in title, abstract, authors");
    _searchQuickrefRow("Title", "ti:flexible", "title contains &ldquo;flexible&rdquo;");
    _searchQuickrefRow("Abstract", "ab:\"very novel\"", "abstract contains &ldquo;very novel&rdquo;");
    _searchQuickrefRow("Authors", "au:poletto", "author list contains &ldquo;poletto&rdquo;");
    _searchQuickrefRow("Collaborators", "co:liskov", "collaborators contains &ldquo;liskov&rdquo;");
    _searchQuickrefRow("Topics", "topic:link", "selected topics match &ldquo;link&rdquo;");
    _searchQuickrefRow("Options", "opt:shadow", "selected submission options match &ldquo;shadow&rdquo;");
    _searchQuickrefRow("<a href='help$ConfSiteSuffix?t=tags'>Tags</a>", "tag:discuss", "tagged &ldquo;discuss&rdquo;");
    _searchQuickrefRow("", "-tag:discuss", "not tagged &ldquo;discuss&rdquo;");
    _searchQuickrefRow("", "order:discuss", "tagged &ldquo;discuss&rdquo;, sort by tag order (&ldquo;rorder:&rdquo; for reverse order)");
    _searchQuickrefRow("Reviews", "re:fdabek", "&ldquo;fdabek&rdquo; in reviewer name/email");
    _searchQuickrefRow("", "cre:fdabek", "&ldquo;fdabek&rdquo; (in reviewer name/email) has completed a review");
    _searchQuickrefRow("", "re:4", "four reviewers (assigned and/or completed)");
    _searchQuickrefRow("", "cre:<3", "less than three completed reviews");
    _searchQuickrefRow("", "ire:>0", "at least one incomplete review");
    _searchQuickrefRow("", "pri:>=1", "at least one primary reviewer (&ldquo;cpri:&rdquo;, &ldquo;ipri:&rdquo;, and reviewer name/email also work)");
    _searchQuickrefRow("", "sec:pai", "&ldquo;pai&rdquo; (reviewer name/email) is secondary reviewer (&ldquo;csec:&rdquo;, &ldquo;isec:&rdquo;, and review counts also work)");
    if (($roundtags = $Conf->settingText("tag_rounds"))) {
	preg_match('/ (\S+) /', $roundtags, $m);
	_searchQuickrefRow("", "round:$m[1]", "review assignment is &ldquo;$m[1]&rdquo;");
    }
    if ($Conf->setting("allowPaperOption") >= 12
	&& $Conf->setting("rev_ratings") != REV_RATINGS_NONE)
	_searchQuickrefRow("", "rate:+", "review was rated positively (&ldquo;rate:-&rdquo; and &ldquo;rate:+>2&rdquo; also work; can combine with &ldquo;re:&rdquo;)");
    _searchQuickrefRow("Comments", "cmt:>0", "at least one comment visible to PC (including authors' response)");
    _searchQuickrefRow("", "aucmt:>0", "at least one comment visible to authors (including authors' response)");
    _searchQuickrefRow("Leads", "lead:fdabek", "&ldquo;fdabek&rdquo; (in name/email) is discussion lead");
    _searchQuickrefRow("", "lead:none", "no assigned discussion lead");
    _searchQuickrefRow("", "lead:any", "some assigned discussion lead");
    _searchQuickrefRow("Shepherds", "shep:fdabek", "&ldquo;fdabek&rdquo; (in name/email) is shepherd (&ldquo;none&rdquo; and &ldquo;any&rdquo; also work)");
    _searchQuickrefRow("Conflicts", "conflict:fdabek", "&ldquo;fdabek&rdquo; (in name/email) has a conflict with the paper");
    _searchQuickrefRow("Status", "status:sub", "paper is submitted for review", "t=all");
    _searchQuickrefRow("", "status:unsub", "paper is neither submitted nor withdrawn", "t=all");
    _searchQuickrefRow("", "status:withdrawn", "paper has been withdrawn", "t=all");

    $rf = reviewForm();
    foreach ($rf->options["outcome"] as $dec)
	$dec = simplifyWhitespace(strtolower($dec));
    $qdec = (strpos($dec, " ") !== false ? "\"$dec\"" : $dec);
    _searchQuickrefRow("Decision", "dec:$qdec", "decision is &ldquo;$dec&rdquo; (partial matches OK)");
    _searchQuickrefRow("", "dec:yes", "one of the accept decisions");
    _searchQuickrefRow("", "dec:no", "one of the reject decisions");
    _searchQuickrefRow("", "dec:?", "decision unspecified");

    // find names of review fields to demonstrate syntax
    $rf = reviewForm();
    $f = array(null, null);
    foreach ($rf->fieldOrder as $fn) {
	$fx = (isset($rf->options[$fn]) ? 0 : 1);
	if ($f[$fx] === null)
	    $f[$fx] = $fn;
    }
    $t = "Review&nbsp;fields";
    if ($f[0]) {
	$scores = array_keys($rf->options[$f[0]]);
	$score = $scores[1];
	$revname = $rf->abbreviateField($rf->shortName[$f[0]], 1);
	_searchQuickrefRow($t, "$revname:$score", "at least one completed review has " . htmlspecialchars($rf->shortName[$f[0]]) . " score $score");
	_searchQuickrefRow("", "$revname:>$score", "at least one completed review has " . htmlspecialchars($rf->shortName[$f[0]]) . " score greater than $score");
	_searchQuickrefRow("", "$revname:2<=$score", "at least two completed reviews have " . htmlspecialchars($rf->shortName[$f[0]]) . " score less than or equal to $score");
	$revname = $rf->abbreviateField($rf->shortName[$f[0]]);
	_searchQuickrefRow("", "$revname:$score", "other abbreviations accepted");
	$t = "";
    }
    if ($f[1]) {
	$revname = $rf->abbreviateField($rf->shortName[$f[1]], 1);
	_searchQuickrefRow($t, "$revname:finger", "at least one completed review has &ldquo;finger&rdquo; in the " . htmlspecialchars($rf->shortName[$f[1]]) . " field");
	_searchQuickrefRow($t, "$revname:any", "at least one completed review has text in the " . htmlspecialchars($rf->shortName[$f[1]]) . " field");
	$revname = $rf->abbreviateField($rf->shortName[$f[1]]);
	_searchQuickrefRow($t, "$revname:any", "other abbreviations accepted");
    }
    echo "</table>\n";
}


function _currentVoteTags() {
    global $ConfSiteSuffix;
    require_once("Code/tags.inc");
    $vt = array_keys(voteTags());
    if (count($vt)) {
	sort($vt);
	$votetags = " (currently ";
	foreach ($vt as $v)
	    $votetags .= "&ldquo;<a href=\"search$ConfSiteSuffix?q=rorder:$v\">$v</a>&rdquo;, ";
	return substr($votetags, 0, strlen($votetags) - 2) . ")";
    } else
	return "";
}

function tags() {
    global $Conf, $ConfSiteSuffix, $Me;

    // get current tag settings
    $chairtags = "";
    $votetags = "";
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
		$chairtags .= "&ldquo;<a href=\"search$ConfSiteSuffix?q=tag:$c\">$c</a>&rdquo;, ";
	    $chairtags = substr($chairtags, 0, strlen($chairtags) - 2) . ")";
	}

	$votetags = _currentVoteTags();

	if ($Me->privChair)
	    $setting = "  (<a href='settings$ConfSiteSuffix?group=rev'>Change this setting</a>)";

	if ($Conf->setting("tag_seeall") > 0) {
	    $conflictmsg3 = "Currently PC members can see tags for any paper, including conflicts.";
	} else {
	    $conflictmsg1 = " or conflicted PC members";
	    $conflictmsg2 = "  However, since PC members currently can't see tags for conflicted papers, each PC member might see a different list." . $setting;
	    $conflictmsg3 = "They are currently hidden from conflicted PC members&mdash;for instance, if a PC member searches for a tag, the results will never include conflicts.";
	}
    }

    echo "<table>";
    _alternateRow("Tag basics", "
PC members and administrators can attach tag names to papers.
Papers can have many tags, and you can invent new tags on the fly.
Tags are never shown to authors$conflictmsg1.
It&rsquo;s easy to add and remove tags and to list all papers with a given tag,
and <em>ordered</em> tags preserve a particular paper order.

<p><em>Twiddle tags</em>, with names like &ldquo;~tag&rdquo;, are visible only
to their creators.  Tags with two twiddles, such as &ldquo;~~tag&rdquo;, are
visible only to PC chairs.  All other tags are visible to the entire PC.</p>");

    _alternateRow("Using tags", "
Here are some example ways to use tags.

<ul>
<li><strong>Avoid discussing low-ranked submissions at the PC meeting.</strong>
 Mark low-ranked submissions with tag &ldquo;nodiscuss&rdquo;, then ask the PC to
 <a href='search$ConfSiteSuffix?q=tag:nodiscuss'>search for &ldquo;tag:nodiscuss&rdquo;</a>.
 PC members can easily check the list for controversial papers they'd like to discuss despite their ranking.
 They can email the chairs about such papers, or, even easier, add a &ldquo;discussanyway&rdquo; tag.
 (You might make the &ldquo;nodiscuss&rdquo; tag chair-only so an evil PC member couldn't add it to a high-ranked paper, but it's usually better to trust the PC.)</li>

<li><strong>Mark controversial papers that would benefit from additional review.</strong>
 PC members could add the &ldquo;controversy&rdquo; tag when the current reviewers disagree.
 A <a href='search$ConfSiteSuffix?q=tag:controversy'>search</a> shows where the PC thinks more review is needed.</li>

<li><strong>Mark PC-authored papers for extra scrutiny.</strong>
 First, <a href='search$ConfSiteSuffix?t=s&amp;qt=au'>search for PC members' last names in author fields</a>.
 Check for accidental matches and select the papers with PC members as authors, then use the action area below the search list to add the tag &ldquo;pcpaper&rdquo;.
 A <a href='search$ConfSiteSuffix?t=s&amp;qx=tag:pcpaper'>search</a> shows papers without PC authors.
 (Since PC members can see whether a paper is tagged &ldquo;pcpaper&rdquo;, you may want to delay defining the tag until just before the meeting.)</li>

<li><strong>Vote for papers.</strong>
 The chair can define special voting tags$votetags$setting.
 Each PC member is assigned an allotment of votes to distribute among papers.
 For instance, if &ldquo;v&rdquo; were a voting tag with an allotment of 10, then a PC member could assign 5 votes to a paper by adding the twiddle tag &ldquo;~v#5&rdquo;.
 The system automatically sums PC members' votes into the public &ldquo;v&rdquo; tag.
 To search for papers by vote count, search for &ldquo;<a href='search$ConfSiteSuffix?t=s&amp;q=rorder:v'>rorder:v</a>&rdquo;. (<a href='help$ConfSiteSuffix?t=votetags'>Learn more</a>)</li>

<li><strong>Define a discussion order for the PC meeting.</strong>
 Publishing the order lets PC members prepare to discuss upcoming papers.
 Define an ordered tag such as &ldquo;discuss&rdquo; (see below for how), then ask the PC to <a href='search$ConfSiteSuffix?q=order:discuss'>search for &ldquo;order:discuss&rdquo;</a>.
 The PC can now see the order and use quick links to go from paper to paper.$conflictmsg2</li>

<li><strong>Mark tentative decisions during the PC meeting</strong> either
 using decision selectors or, perhaps, &ldquo;accept&rdquo; and
 &ldquo;reject&rdquo; tags.</li>

</ul>
");
    _alternateRow("Finding tags", "
A paper's tags are shown like this:

<p><img src='images/extagsnone.png' alt='[Tag list on review screen]' /></p>

To find all papers with tag &ldquo;discuss&rdquo;:&nbsp; " . _searchForm("tag:discuss") . "

<p>Tags are only shown to PC members and administrators.
$conflictmsg3$setting
Additionally, twiddle tags, which have names like &ldquo;~tag&rdquo;, are
visible only to their creators; each PC member has an independent set.</p>");
    _alternateRow("<a name='changing'>Changing tags</a>", "
To change a paper's tags, click the Tags box's <img src='images/edit.png'
alt='[Edit]' />&nbsp;Edit link, then enter one or more alphanumeric tags
separated by spaces.

<p><img src='images/extagsset.png' alt='[Tags entry on review screen]' /></p>

<p>To tag multiple papers at once, find the papers in a
<a href='search$ConfSiteSuffix'>search</a>, select
their checkboxes, and add tags using the action area.</p>

<p><img src='images/extagssearch.png' alt='[Setting tags on the search page]' /></p>

<p><b>Add</b> adds tags to the selected papers, <b>Remove</b> removes existing
tags from the selected papers, and <b>Define</b> adds the tag to all selected
papers and removes it from all non-selected papers.  The chair-only <b>Clear
twiddle</b> action removes a tag and all users' matching twiddle tags.</p>

<p>Although any PC member can view or search
most tags, only PC chairs can change certain tags$chairtags.  $setting</p>");
    _alternateRow("Tag values<br />and discussion orders", "
Tags have optional per-paper numeric values, which are displayed as
&ldquo;tag#100&rdquo;.  Searching for a tag with &ldquo;<a
href='search$ConfSiteSuffix?q=order:tagname'>order:tagname</a>&rdquo; will
return the papers sorted by the tag value.  This is useful, for example, for
PC meeting discussion orders.  Change the order by editing the tag values.
Search for specific values with search terms like &ldquo;<a
href='search$ConfSiteSuffix?q=tag:discuss%232'>tag:discuss#2</a>&rdquo;
or &ldquo;<a
href='search$ConfSiteSuffix?q=tag:discuss%3E1'>tag:discuss>1</a>&rdquo;.

<p>It's common to assign increasing tag values to a set of papers.  Do this
using the <a href='search$ConfSiteSuffix'>search screen</a>.  Search for the
papers you want, sort them into the right order, select their checkboxes, and
choose <b>Define ordered</b> in the tag action area.  If no sort gives what
you want, search for the desired paper numbers in order&mdash;for instance,
you might search for &ldquo;<a href='search$ConfSiteSuffix?q=4+1+12+9'>4 1 12
19</a>&rdquo;&mdash;then <b>Select all</b> and <b>Define ordered</b>.  To add
new papers at the end of an existing discussion order, use <b>Add ordered</b>.
To insert papers into an existing order, use <b>Add ordered</b> with a tag
value; for example, to insert starting at value 5, use <b>Add ordered</b> with
&ldquo;tag#5&rdquo;.  The rest of the order is renumbered to accomodate the
insertion.</p>

<p><b>Define ordered</b> might assign values &ldquo;tag#1&rdquo;,
&ldquo;tag#3&rdquo;, &ldquo;tag#6&rdquo;, and &ldquo;tag#7&rdquo;
to adjacent papers.  The gaps make it harder to infer
conflicted papers' positions.  (Any given gap might or might not hold a
conflicted paper.)  In contrast, the <b>Define sequential</b> action assigns
strictly sequential values, like &ldquo;tag#1&rdquo;,
&ldquo;tag#2&rdquo;, &ldquo;tag#3&rdquo;, &ldquo;tag#4&rdquo;.
<b>Define ordered</b> is better for most purposes.</p>");
    echo "</table>\n";
}



function revround() {
    global $Conf, $ConfSiteSuffix, $Me;

    echo "<table>";
    _alternateRow("Review round basics", "
Many conferences divide reviews into multiple <em>rounds</em>.
HotCRP lets chairs label assignments in each round with names, such as
&ldquo;R1&rdquo; or &ldquo;lastround&rdquo;.
(We suggest very short names like &ldquo;R1&rdquo;.)
To list another PC member&rsquo;s round &ldquo;R1&rdquo; review assignments, <a href='search$ConfSiteSuffix?q=re:membername+round:R1'>search for &ldquo;re:membername round:R1&rdquo;</a>.");

    // get current tag settings
    if (!$Me->isPC)
	/* do nothing */;
    else if (($rounds = trim($Conf->settingText("tag_rounds"))))
	_alternateRow("Defined rounds", "So far the following review rounds have been defined: &ldquo;" . join("&rdquo;, &ldquo;", preg_split('/\s+/', htmlspecialchars($rounds))) . "&rdquo;.");
    else
	_alternateRow("Defined rounds", "So far no review rounds have been defined.");

    _alternateRow("Assigning rounds", "
New assignments are marked by default with the current round defined in
<a href='settings$ConfSiteSuffix?group=rev'>review settings</a>.
The automatic and bulk assignment pages also let you set a review round.");

    echo "</table>\n";
}


function revrate() {
    global $Conf, $ConfSiteSuffix, $ratingTypes, $Me;
    $rf = reviewForm();

    echo "<table>";
    _alternateRow("Review ratings basics", "
PC members and, optionally, external reviewers can rate one another's
reviews.  We hope this feedback will help reviewers improve the quality of
their reviews.  The interface appears above each visible review:

<div class='g'></div>

<div class='rev_rating'>
  How helpful is this review? &nbsp;<form><div class='inform'>"
		  . tagg_select("rating", $ratingTypes, "n")
		  . "</div></form>
</div>

<p>When rating a review, please consider its value for both the program
  committee and the authors.  Helpful reviews are specific, clear, technically
  focused, and, when possible, provide direction for the authors' future work.
  The rating options are:</p>

<dl>
<dt><strong>Average</strong></dt>
<dd>The review has acceptable quality.  This is the default, and should be
  used for most reviews.</dd>
<dt><strong>Very helpful</strong></dt>
<dd>Great review.  Thorough, clear, constructive, and gives
  good ideas for next steps.</dd>
<dt><strong>Too short</strong></dt>
<dd>The review is incomplete or too terse.</dd>
<dt><strong>Too vague</strong></dt>
<dd>The review's arguments are weak, mushy, or otherwise technically
  unconvincing.</dd>
<dt><strong>Not constructive</strong></dt>
<dd>The review's tone is unnecessarily aggressive or gives little useful
  direction.</dd>
<dt><strong>Not correct</strong></dt>
<dd>The review misunderstands the paper.</dd>
</dl>

<p>HotCRP reports the numbers of non-average ratings for each review.
  It does not report who gave the ratings, and it
  never shows rating counts to authors.</p>

<p>To find which of your reviews might need work, simply
<a href='search$ConfSiteSuffix?q=rate:-'>search for &ldquo;rate:&minus;&rdquo;</a>.
To find all reviews with positive ratings,
<a href='search$ConfSiteSuffix?q=re:any+rate:%2B'>search for &ldquo;re:any&nbsp;rate:+&rdquo;</a>.
You may also search for reviews with specific ratings; for instance,
<a href='search$ConfSiteSuffix?q=rate:helpful'>search for &ldquo;rate:helpful&rdquo;</a>.</p>");
    if ($Conf->setting("rev_ratings") == REV_RATINGS_PC)
	$what = "only PC members";
    else if ($Conf->setting("rev_ratings") == REV_RATINGS_PC_EXTERNAL)
	$what = "PC members and external reviewers";
    else
	$what = "no one";
    _alternateRow("Settings", "
Chairs set how ratings work on the <a
href='settings$ConfSiteSuffix?group=rev'>review settings
page</a>." . ($Me->amReviewer() ? "  Currently, $what can rate reviews." : ""));
    _alternateRow("Visibility", "
A review's ratings are visible to any unconflicted PC members who can see
the review, but HotCRP tries to hide ratings from review authors if they
could figure out who assigned the rating: if only one PC member could
rate a review, then that PC member's rating is hidden from the review
author.");

    echo "</table>\n";
}


function scoresort() {
    global $Conf, $ConfSiteSuffix, $Me;

    echo "<table>";
    _alternateRow("Sorting scores", "
Some paper search results include columns with score graphs.  Click on a score
column heading to sort the paper list using that score.  Search &gt; Display
options changes how scores are sorted.  There are five choices:

<dl>

<dt><strong>Counts</strong> (default)</dt>

<dd>Sort by the number of highest scores, then the number of second-highest
scores, then the number of third-highest scores, and so on.  To sort a paper
with fewer reviews than others, HotCRP adds phantom reviews with scores just
below the paper's lowest real score.  Also known as Minshall score.</dd>

<dt><strong>Average</strong></dt>
<dd>Sort by the average score.</dd>

<dt><strong>Variance</strong></dt>
<dd>Sort by the variance in scores.</dd>

<dt><strong>Max &minus; min</strong></dt>
<dd>Sort by the difference between the largest and smallest scores (a good
measure of differences of opinion).</dd>

<dt><strong>Your score</strong></dt>
<dd>Sort by your score.  In the score graphs, your score is highlighted with a
darker colored square.</dd>

</dl>");

    echo "</table>\n";
}


function showvotetags() {
    global $Conf, $ConfSiteSuffix, $Me;

    echo "<table>";
    _alternateRow("Voting tags basics", "
Some conferences have PC members vote for papers.
Each PC member is assigned a vote allotment, and can distribute that allotment
arbitrarily among unconflicted papers.
The PC's aggregated vote totals might help determine
which papers to discuss.

<p>HotCRP supports voting through the <a href='help$ConfSiteSuffix?t=tags'>tags system</a>.
The chair can <a href='settings$ConfSiteSuffix?group=rev'>define a set of voting tags</a> and allotments" . _currentVoteTags() . ".
PC members vote by assigning the corresponding twiddle tags;
the aggregated PC vote is visible in the public tag.</p>

<p>For example, assume that an administrator defines a voting tag &ldquo;vote&rdquo; with an allotment of 10 votes.
To vote for a paper, PC members add the &ldquo;~vote&rdquo; tag.
Adding &ldquo;~vote#2&rdquo; assigns two votes, and so forth.
The system ensures no PC member exceeds the allotment.
The publicly visible &ldquo;vote&rdquo; tag is automatically set to the total number of PC votes for each paper.
Hover to learn how the PC voted:</p>

<p><img src='images/extagvotehover.png' alt='[Hovering over a voting tag]' /></p>");

    echo "</table>\n";
}


function chair() {
    global $ConfSiteSuffix;
    echo "<table>";
    _alternateRow("Submission time", "
Follow these steps to prepare to accept paper submissions.

<ol>

<li><p><strong><a href='settings$ConfSiteSuffix?group=acc'>Set up PC
  member accounts</a></strong> and decide whether to collect authors'
  snail-mail addresses and phone numbers.</li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=sub'>Set submission
  policies</a></strong>, including whether submission is blind, whether
  authors check off conflicted PC members (&ldquo;Collect authors' PC conflicts
  with checkboxes&rdquo;), and whether authors must enter additional non-PC collaborators,
  which can help detect conflicts with external reviewers (&ldquo;Collect authors'
  other collaborators as text&rdquo;).</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=sub'>Set submission
  deadlines.</a></strong> Authors first <em>register</em>, then <em>submit</em>
  their papers, possibly multiple times; they choose for each submitted
  version whether that version is ready for review.  Normally, HotCRP allows
  authors to update their papers until the deadline, but you can also require
  that authors &ldquo;freeze&rdquo; each submission explicitly; only
  administrators can update frozen submissions.
  The only deadline that really matters is the paper submission
  deadline, but HotCRP also supports a separate paper registration deadline,
  which will force authors to register a few days before they submit.  An
  optional <em>grace period</em> applies to both deadlines:
  HotCRP reports the deadlines, but allows submissions and updates post-deadline
  for the specified grace period.  This provides some
  protection against last-minute server overload and gives authors
  some slack.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=opt'>Define
  submission options (optional).</a></strong>  You can add
  additional checkboxes to the submission form, such as \"Consider this
  paper for the Best Student Paper award\" or \"Provide this paper to the
  European shadow PC.\"  You can
  <a href='search$ConfSiteSuffix'>search</a> for papers with or without
  each option.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=opt'>Define paper
  topics (optional).</a></strong> Authors can select topics, such as
  \"Applications\" or \"Network databases,\" that characterize their
  paper's subject areas.  PC members express topics for which they have high,
  medium, and low interest, improving automatic paper assignment.  Although
  explicit preferences (see below) are better than topic-based assignments,
  busy PC members might not specify their preferences; topic matching lets you
  do a reasonable job at assigning papers anyway.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=sub'>Set
  up the automated format checker (optional).</a></strong> This adds a
  &ldquo;Check format&rdquo; button to the Edit Paper screen.
  Clicking the button checks the paper for formatting errors, such as going
  over the page limit.  Papers with formatting errors may still be submitted,
  since the checker itself can make mistakes, but the automated checker leaves
  cheating authors no excuse.</p></li>

<li><p>Take a look at a <a href='paper$ConfSiteSuffix?p=new'>paper
  submission page</a> to make sure it looks right.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=sub'>Open the site
  for submissions.</a></strong> Submissions will be accepted only until the
  listed deadline.</p></li>

</ol>");
    _alternateRow("Assignments", "
After the submission deadline has passed:

<ol>

<li><p>Consider looking through <a
  href='search$ConfSiteSuffix?q=&amp;t=all'>all papers</a> for
  anomalies.  Withdraw and/or delete duplicates or update details on the <a
  href='paper$ConfSiteSuffix'>paper pages</a> (via &ldquo;Edit paper&rdquo;).
  Also consider contacting the authors of <a
  href='search$ConfSiteSuffix?q=status:unsub&amp;t=all'>papers that
  were never officially submitted</a>, especially if a PDF document was
  uploaded (you can tell from the icon in the search list).  Sometimes a
  user will uncheck &ldquo;The paper is ready for review&rdquo; by mistake.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=rfo'>Prepare the
  review form.</a></strong> Take a look at the templates to get
  ideas.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=rev'>Set review
  policies and deadlines</a></strong>, including reviewing deadlines, whether
  review is blind, and whether PC members may review any paper
  (usually &ldquo;yes&rdquo; is the right answer).</p></li>

<li><p><strong><a href='reviewprefs$ConfSiteSuffix'>Collect review
  preferences from the PC.</a></strong> PC members can rank-order papers they
  want or don't want to review.  They can either set their preferences <a
  href='reviewprefs$ConfSiteSuffix'>all at once</a>, or (often more
  convenient) page through the <a
  href='search$ConfSiteSuffix?q=&amp;t=s'>list of submitted papers</a>
  setting their preferences on the <a
  href='paper$ConfSiteSuffix'>paper pages</a>.</p>

  <p>If you'd like, you can collect review preferences before the submission
  deadline.  Select <a href='settings$ConfSiteSuffix?group=sub'>&ldquo;PC can
  see <em>all registered papers</em> until submission deadline&rdquo;</a>, which
  allows PC members to see abstracts for registered papers that haven't yet
  been submitted.</p></li>

<li><p><strong><a href='manualassign$ConfSiteSuffix?kind=c'>Assign
  conflicts.</a></strong> You can assign conflicts <a
  href='manualassign$ConfSiteSuffix?kind=c'>by PC member</a> or, if
  PC members have entered preferences, <a
  href='autoassign$ConfSiteSuffix'>automatically</a> by searching for
  preferences of &minus;100 or less.</p></li>

<li><p><strong><a href='manualassign$ConfSiteSuffix'>Assign
  reviews.</a></strong> You can make assignments <a
  href='assign$ConfSiteSuffix'>by paper</a>, <a
  href='manualassign$ConfSiteSuffix'>by PC member</a>, <a
  href='bulkassign$ConfSiteSuffix'>by uploading an assignments
  file</a>, or, even easier, <a
  href='autoassign$ConfSiteSuffix'>automatically</a>.  PC
  review assignments can be &ldquo;primary&rdquo; or &ldquo;secondary&rdquo;; the difference is
  that primary reviewers are expected to complete their review, but a
  secondary reviewer can choose to delegate their review to someone else.</p>

  <p>The default assignments pages apply to all submitted papers.  You can
  also assign subsets of papers obtained through <a
  href='help$ConfSiteSuffix?t=search'>search</a>, such as <a
  href='search$ConfSiteSuffix?q=cre:%3C3&amp;t=s'>papers
  with fewer than three completed reviews</a>.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=rev'>Open the site
  for reviewing.</a></strong></p></li>

</ol>
");
    _alternateRow("Chair conflicts", "
Chairs and system administrators can access any information stored in the
conference system, including reviewer identities for conflicted papers.  For
this reason, some chairs prefer not to use the normal review assignment
process for their own submissions, and HotCRP supports an alternate review
mechanism.  For each chair conflict:

<ol>

<li>A chair or system administrator goes to the paper's <a
  href='assign$ConfSiteSuffix'>assignment page</a> and clicks
  on &ldquo;Request review&rdquo; without entering a name or email address.
  This creates a new, completely anonymous review slot and reports a
  corresponding <em>review token</em>, a short string of letters and numbers
  such as &ldquo;9HDZYUB&rdquo;.  The chair creates as many slots as
  desired.</li>

<li>The chair sends the resulting review tokens to a PC member designated as
  the paper's manager.  This trusted party decides which users should
  review the paper, and sends each reviewer one of the review tokens.</li>

<li>When a reviewer signs in and enters their review token on the home page,
  the system lets them view the paper and anonymously modify the corresponding
  review.</li>

</ol>

<p>Reviews entered using this procedure appear to be authored by &ldquo;Jane
  Q. Public.&rdquo; Chairs can still see (and edit) the reviews if they
  override their conflicts, but reviewer identities are not stored in the
  database at all.</p>

<p>Alternately, the trusted manager can send the reviewers the paper and an
  offline review form via email (not using HotCRP).  The reviewers complete
  the offline forms and send them to the manager, who uploads them into the
  &ldquo;Jane Q. Public&rdquo; review slots using the review tokens.  This
  way, even web server access logs store only the manager's identity.</p>

");
    _alternateRow("Before the meeting", "
Before the meeting, you will generally <a
href='settings$ConfSiteSuffix?group=rev'>set &ldquo;PC can see all
reviews&rdquo;</a>, allowing the program committee to view reviews and scores for
non-conflicted papers.  (In many conferences, PC members are initially
prevented from seeing a paper's reviews until they have completed their own
review for that paper; this supposedly reduces bias.)

<ol>

<li><p><strong><a href='settings$ConfSiteSuffix?group=dec'>Collect
  authors' responses to the reviews (optional).</a></strong> Some conferences
  allow authors to respond to the reviews before decisions are made, giving
  them a chance to correct misconceptions and such.  Responses are entered
  into the system as <a
  href='comment$ConfSiteSuffix'>comments</a>.  On the <a
  href='settings$ConfSiteSuffix?group=dec'>decision settings page</a>,
  update &ldquo;Can authors see reviews&rdquo; and &ldquo;Collect responses to the
  reviews,&rdquo; then <a href='mail$ConfSiteSuffix'>send mail to
  authors</a> informing them of the response deadlines.  PC members will still
  be able to update their reviews, assuming it's before the <a
  href='settings$ConfSiteSuffix?group=rev'>review deadline</a>; authors
  are informed via email of any review changes.  At the end of the response
  period it's generally good to <a
  href='settings$ConfSiteSuffix?group=dec'>turn off &ldquo;Authors can see
  reviews&rdquo;</a> so PC members can update their reviews in peace.</p></li>

<li><p>Set <strong><a href='settings$ConfSiteSuffix?group=rev'>PC can
  see all reviews</a></strong> if you haven't already.</p></li>

<li><p><strong><a
  href='search$ConfSiteSuffix?q=&amp;t=s&amp;sort=50'>Examine paper
  scores</a></strong>, either one at a time or en masse, and decide which
  papers will be discussed.  The <a href='help$ConfSiteSuffix?t=tags'>tags</a> system
  lets you prepare discussion sets and even discussion orders.  Use
  <a href='help$ConfSiteSuffix?t=keywords'>search keywords</a>
  to, for example, find all papers with at least two overall merit ratings of 2 or better.</p></li>

<li><p><strong><a href='autoassign$ConfSiteSuffix'>Assign discussion leads
  (optional).</a></strong> Discussion leads are expected to be able to
  summarize the paper and the reviews.  You can assign leads either <a
  href='assign$ConfSiteSuffix'>paper by paper</a> or <a
  href='autoassign$ConfSiteSuffix'>automatically</a>.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=dec'>Define decision
  types (optional).</a></strong> By default, HotCRP has two decision types,
  &ldquo;accept&rdquo; and &ldquo;reject,&rdquo; but you can add other types of acceptance and
  rejection, such as &ldquo;accept as short paper.&rdquo;</p></li>

<li><p>The night before the meeting, <strong><a
  href='search$ConfSiteSuffix?q=&amp;t=s'>download all
  reviews onto a laptop</a></strong> (Download &gt; All reviews) in case the
  Internet explodes and you can't reach HotCRP from the meeting
  place.</p></li>

</ol>
");
    _alternateRow("At the meeting", "
<ol>

<li><p>It's often useful to have a PC member or scribe capture the discussion
  about a paper and enter it as a <a
  href='comment$ConfSiteSuffix'>comment</a> for the authors'
  reference.</p></li>

<li><p><strong>Paper decisions</strong> can be recorded on the <a
  href='review$ConfSiteSuffix'>paper pages</a> or en masse via <a
  href='search$ConfSiteSuffix?q=&amp;t=s'>search</a>.  Use <a
  href='settings$ConfSiteSuffix?group=dec'>decision settings</a> to expose
  decisions to PC members if desired.</p></li>

<li><p><strong>Shepherding (optional).</strong> If your conference uses
  shepherding for accepted papers, you can assign shepherds either <a
  href='assign$ConfSiteSuffix'>paper by paper</a> on the
  assignments screen or <a
  href='autoassign$ConfSiteSuffix?t=acc'>automatically</a>.</p></li>

</ol>
");
    _alternateRow("After the meeting", "
<ol>

<li><p><strong><a
  href='search$ConfSiteSuffix?q=&amp;t=s'>Enter
  decisions</a> and <a
  href='search$ConfSiteSuffix?q=dec:yes&amp;t=s'>shepherds</a></strong>
  if you didn't do this at the meeting.</p></li>

<li><p>Give reviewers some time to <strong>update their reviews</strong> in
  response to PC discussion (optional).</p></li>

<li><p>Set <a href='settings$ConfSiteSuffix?group=dec'>&ldquo;Who can
  <strong>see decisions?</strong>&rdquo;</a> to &ldquo;Authors, PC members,
  and reviewers.&rdquo;</p></li>

<li><p><strong><a href='mail$ConfSiteSuffix'>Send mail to
  authors</a></strong> informing them that reviews and decisions are
  available.  The mail can also contain the reviews and comments
  themselves.</p></li>

<li><p><strong><a href='settings$ConfSiteSuffix?group=dec'>Collect final
  papers (optional).</a></strong> If you're putting together the program
  yourself, it can be convenient to collect final copies using HotCRP.
  Authors upload final copies the same way they did the submission, although
  the submitted version is archived for reference.  You can then <a
  href='search$ConfSiteSuffix?q=dec:yes&amp;t=s'>download
  all final copies as a <tt>.zip</tt> archive</a>.</p></li>

</ol>
");
    echo "</table>\n";
}



if ($topic == "topics")
    topics();
else if ($topic == "search")
    search();
else if ($topic == "keywords")
    searchQuickref();
else if ($topic == "tags")
    tags();
else if ($topic == "revround")
    revround();
else if ($topic == "revrate")
    revrate();
else if ($topic == "votetags")
    showvotetags();
else if ($topic == "scoresort")
    scoresort();
else if ($topic == "chair")
    chair();

$Conf->footer();
