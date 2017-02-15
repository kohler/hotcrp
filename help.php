<?php
// help.php -- HotCRP help page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

class HelpTopic {
    public $id;
    public $name;
    public $description;
    static public $list = array();
    public function __construct($id, $name, $description = null) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
    }
    static public function register($id, $name, $description = null) {
        assert(!isset(self::$list[$id]));
        self::$list[$id] = new HelpTopic($id, $name, $description);
    }
}

HelpTopic::register("topics", "Help topics");
HelpTopic::register("chair", "Chair’s guide", "How to run a conference using HotCRP.");
HelpTopic::register("search", "Search", "About paper searching.");
HelpTopic::register("keywords", "Search keywords", "Quick reference to search keywords and syntax.");
HelpTopic::register("tags", "Tags", "How to use tags to define paper sets and discussion orders.");
HelpTopic::register("tracks", "Tracks", "How tags can control PC access to papers.");
HelpTopic::register("scoresort", "Sorting scores", "How scores are sorted in paper lists.");
HelpTopic::register("revround", "Review rounds", "Review rounds are sets of reviews with different deadlines.");
HelpTopic::register("reviewratings", "Review ratings", "Rating the quality of reviews.");
HelpTopic::register("votetags", "Voting", "PC members can vote for papers using tags.");
HelpTopic::register("ranking", "Ranking", "PC members can rank papers using tags.");
HelpTopic::register("formulas", "Formulas", "Create and display formulas in search orders.");

if (!isset($_REQUEST["t"])
    && preg_match(',\A/(\w+)\z,i', Navigation::path()))
    $_REQUEST["t"] = substr(Navigation::path(), 1);
$topic = defval($_REQUEST, "t", "topics");
if ($topic == "syntax")
    $topic = "keywords";
if ($topic == "revrate")
    $topic = "reviewratings";
if (!isset(HelpTopic::$list[$topic]))
    $topic = "topics";

if ($topic === "topics")
    $Conf->header("Help", "help", actionBar());
else
    $Conf->header("Help &nbsp;&#x2215;&nbsp; <strong>" . HelpTopic::$list[$topic]->name . "</strong>", "help", actionBar());


function _alternateRow($caption, $entry, $next = null) {
    global $rowidx;
    if ($caption) {
        $below = "";
        if (is_array($caption))
            list($caption, $below) = $caption;
        if (!preg_match('/<a/', $caption)) {
            $anchor = strtolower(preg_replace('/\W+/', "_", $caption));
            $caption = '<a class="qq" name="' . $anchor . '" href="#' . $anchor
                . '">' . $caption . '</a>';
        }
        echo '<tr><td class="sentry nw" colspan="2">',
            '<h4 class="helppage">', $caption, '</h4>', $below, '</td></tr>', "\n";
        $rowidx = null;
    }
    if ($entry || $next) {
        $rowidx = (isset($rowidx) ? $rowidx + 1 : 0);
        echo '<tr class="k', ($rowidx % 2), '">',
            '<td class="sentry"', ($next === null ? ' colspan="2">' : ">"),
            $entry, "</td>";
        if ($next !== null)
            echo '<td class="sentry">', $next, "</td>";
        echo "</tr>\n";
    }
}

function _subhead($head, $entry, $id = false) {
    if ($id || $head)
        echo '<h3 class="helppage"', ($id ? ' id="' . $id . '"' : ''),
            '>', $head, "</h3>\n";
    echo '<div class="helppagetext">', $entry, "</div>\n";
}


function topics() {
    echo "<dl>\n";
    foreach (HelpTopic::$list as $ht)
        if ($ht->id !== "topics")
            echo '<dt><strong><a href="', hoturl("help", "t=$ht->id"), '">',
                $ht->name, '</a></strong></dt><dd>', $ht->description, '</dd>', "\n";
    echo "</dl>\n";
}


function _searchForm($forwhat, $other = null, $size = 20) {
    $text = "";
    if ($other && preg_match_all('/(\w+)=([^&]*)/', $other, $matches, PREG_SET_ORDER))
        foreach ($matches as $m)
            $text .= Ht::hidden($m[1], urldecode($m[2]));
    return Ht::form_div(hoturl("search"), array("method" => "get", "divclass" => "nw"))
        . "<input type='text' name='q' value=\""
        . htmlspecialchars($forwhat) . "\" size='$size' /> &nbsp;"
        . Ht::submit("go", "Search")
        . $text . "</div></form>";
}

function _searchLink($q, $linkhtml = null) {
    return '<a href="' . hoturl("search", ["q" => $q]) . '">'
        . ($linkhtml ? : htmlspecialchars($q)) . '</a>';
}

function search() {
    _subhead("", "
<p>All HotCRP paper lists are obtained through search, search syntax is flexible,
and it’s possible to download all matching papers and/or reviews at once.</p>

<p>Some hints for PC members and chairs:</p>

<ul class='compact'>
<li><div style='display:inline-block'>" . _searchForm("") . "</div>&nbsp; finds all papers.  (Leave the search field blank.)</li>
<li><div style='display:inline-block'>" . _searchForm("12") . "</div>&nbsp; finds paper #12.  When entered from a
 <a href='#quicklinks'>quicksearch</a> box, this search will jump to
 paper #12 directly.</li>
<li><a href='" . hoturl("help", "t=keywords") . "'>Search keywords</a>
 let you search specific fields, review scores, and more.</li>
<li>Use <a href='#quicklinks'>quicklinks</a> on paper pages to navigate
 through search results. Typing <code>j</code> and <code>k</code> also goes
 from paper to paper.</li>
<li>On search results pages, shift-click checkboxes to
 select paper ranges.</li>
</ul>");

    _subhead("How to search", "
<p>The default search box returns papers that match
<em>all</em> of the space-separated terms you enter.
To search for words that <em>start</em> with
a prefix, try “term*”.
To search for papers that match <em>some</em> of the terms,
type “term1 OR term2”.
To search for papers that <em>don’t</em> match a term,
try “-term”.  Or select
<a href='" . hoturl("search", "opt=1") . "'>Advanced search</a>
and use “With <b>any</b> of the words” and “<b>Without</b> the words.”</p>

<p>You can search in several paper classes, depending on your role in the
conference. Options include:</p>
<ul class='compact'>
<li><b>Submitted papers</b> &mdash; all submitted papers.</li>
<li><b>All papers</b> &mdash; all papers, including withdrawn and other non-submitted papers.</li>
<li><b>Your submissions</b> &mdash; papers for which you’re a contact.</li>
<li><b>Your reviews</b> &mdash; papers you’ve been assigned to review.</li>
<li><b>Your incomplete reviews</b> &mdash; papers you’ve been assigned to review, but haven’t submitted a review yet.</li>
</ul>

<p>Search won’t show you information you aren’t supposed to see.  For example,
authors can only search their own submissions, and if the conference used
anonymous submission, then only the PC chairs can search by author.</p>

<p>By default, search examines paper titles, abstracts, and authors.
<a href='" . hoturl("search", "opt=1") . "'>Advanced search</a>
can search other fields, including authors/collaborators and reviewers.
Also, <b>keywords</b> search specific characteristics such as titles,
authors, reviewer names, and numbers of reviewers.  For example,
“ti:foo” means “search for ‘foo’ in paper
titles.”  Keywords are listed in the
<a href='" . hoturl("help", "t=keywords") . "'>search keywords reference</a>.</p>");

    _subhead("Search results", "
<p>Click on a paper number or title to jump to that paper.
Search matches are <span class='match'>highlighted</span> on paper screens.
Once on a paper screen use <a href='#quicklinks'>quicklinks</a>
to navigate through the rest of the search matches.</p>

<p>Underneath the paper list is the action area:</p>

" . Ht::img("exsearchaction.png", "[Search action area]") . "<br />

<p>Use the checkboxes to select some papers, then choose an action.
You can:</p>

<ul class='compact'>
<li>Download a <code>.zip</code> file with the selected papers.</li>
<li>Download all reviews for the selected papers.</li>
<li>Download tab-separated text files with authors, PC
 conflicts, review scores, and so forth (some options chairs only).</li>
<li>Add, remove, and define <a href='" . hoturl("help", "t=tags") . "'>tags</a>.</li>
<li>Assign reviewers and mark conflicts (chairs only).</li>
<li>Set decisions (chairs only).</li>
<li>Send mail to paper authors or reviewers (chairs only).</li>
</ul>

<p>Select papers one by one, select in groups by shift-clicking
the checkboxes, or use the “select all” link.
The easiest way to tag a set of papers is
to enter their numbers in the search box, search, “select all,” and add the
tag.</p>");

    _subhead("<a name='quicklinks'>Quicksearch and quicklinks</a>", "
<p>Most screens have a quicksearch box in the upper right corner:<br />
" . Ht::img("quicksearchex.png", "[Quicksearch box]") . "<br />
This box supports the full search syntax.  Enter
a paper number, or search terms that match exactly
one paper, to go directly to that paper.</p>

<p>Paper screens have quicklinks that step through search results:<br />
" . Ht::img("pageresultsex.png", "[Quicklinks]") . "<br />
Click on the search description (here, “Submitted papers search”) to return
to the search results.  On many pages, you can press “<code>j</code>” or
“<code>k</code>” to go to the previous or next paper in the list.</p>");
}

function _searchQuickrefRow($caption, $search, $explanation, $other = null) {
    global $rowidx;
    if ($caption) {
        echo '<h3 class="helppage">', $caption, "</h3>\n";
        $rowidx = null;
    }
    echo '<div class="helplist_item k' . ((int) @$rowidx % 2) . '">';
    echo '<table class="helppage"><tbody><tr><td class="helplist_dt">',
        _searchForm($search, $other, 36),
        '</td><td class="helplist_dd">',
        $explanation,
        "</td></tr></tbody></table></div>\n";
    $rowidx = +$rowidx + 1;
}

function meaningful_pc_tag() {
    global $Me;
    if ($Me->isPC)
        foreach ($Me->conf->pc_tags() as $tag)
            if ($tag !== "pc")
                return $tag;
    return false;
}

function meaningful_round_name() {
    global $Conf;
    $rounds = $Conf->round_list();
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] !== ";")
            return $rounds[$i];
    return false;
}

function searchQuickref() {
    global $rowidx, $Conf, $Me;

    // how to report author searches?
    if ($Conf->subBlindNever())
        $aunote = "";
    else if (!$Conf->subBlindAlways())
        $aunote = "<br /><span class='hint'>Search uses fields visible to the searcher. For example, PC member searches do not examine anonymous authors.</span>";
    else
        $aunote = "<br /><span class='hint'>Search uses fields visible to the searcher. For example, PC member searches do not examine authors.</span>";

    // does a reviewer tag exist?
    $retag = meaningful_pc_tag() ? : "";

    _searchQuickrefRow("Basics", "", "all papers in the search category");
    _searchQuickrefRow("", "story", "“story” in title, abstract, authors$aunote");
    _searchQuickrefRow("", "119", "paper #119");
    _searchQuickrefRow("", "1 2 5 12-24 kernel", "papers in the numbered set with “kernel” in title, abstract, authors");
    _searchQuickrefRow("", "\"802\"", "“802” in title, abstract, authors (not paper #802)");
    _searchQuickrefRow("", "very new", "“very” <em>and</em> “new” in title, abstract, authors");
    _searchQuickrefRow("", "very AND new", "the same");
    _searchQuickrefRow("", "\"very new\"", "the phrase “very new” in title, abstract, authors");
    _searchQuickrefRow("", "very OR new", "<em>either</em> “very” <em>or</em> “new” in title, abstract, authors");
    _searchQuickrefRow("", "(very AND new) OR newest", "use parentheses to group");
    _searchQuickrefRow("", "very -new", "“very” <em>but not</em> “new” in title, abstract, authors");
    _searchQuickrefRow("", "very NOT new", "the same");
    _searchQuickrefRow("", "ve*", "words that <em>start with</em> “ve” in title, abstract, authors");
    _searchQuickrefRow("", "*me*", "words that <em>contain</em> “me” in title, abstract, authors");
    _searchQuickrefRow("Title", "ti:flexible", "title contains “flexible”");
    _searchQuickrefRow("Abstract", "ab:\"very novel\"", "abstract contains “very novel”");
    _searchQuickrefRow("Authors", "au:poletto", "author list contains “poletto”");
    if ($Me->isPC)
        _searchQuickrefRow("", "au:pc", "one or more authors are PC members (author email matches PC email)");
    _searchQuickrefRow("Collaborators", "co:liskov", "collaborators contains “liskov”");
    _searchQuickrefRow("Topics", "topic:link", "selected topics match “link”");

    $oex = array();
    foreach ($Conf->paper_opts->option_list() as $o)
        $oex = array_merge($oex, $o->example_searches());
    if (count($oex)) {
        $section = "Options";
        foreach ($oex as $extype => $oex) {
            if ($extype === "has") {
                $desc = "paper has “" . htmlspecialchars($oex[1]->name) . "” submission option";
                $oabbr = array();
                foreach ($Conf->paper_opts->option_list() as $ox)
                    if ($ox !== $oex[1])
                        $oabbr[] = "“has:" . htmlspecialchars($ox->search_keyword()) . "”";
                if (count($oabbr))
                    $desc .= '<div class="hint">Other option ' . pluralx(count($oabbr), "search") . ': ' . commajoin($oabbr) . '</div>';
            } else if ($extype === "yes")
                $desc = "same meaning; abbreviations also accepted";
            else if ($extype === "numeric")
                $desc = "paper’s “" . htmlspecialchars($oex[1]->name) . "” option has value &gt; 100";
            else if ($extype === "selector")
                $desc = "paper’s “" . htmlspecialchars($oex[1]->name) . "” option has value “" . htmlspecialchars($oex[1]->selector[1]) . "”";
            else if ($extype === "attachment-count")
                $desc = "paper has more than 2 “" . htmlspecialchars($oex[1]->name) . "” attachments";
            else if ($extype === "attachment-filename")
                $desc = "paper has an “" . htmlspecialchars($oex[1]->name) . "” attachment with a .gif extension";
            else
                continue;
            _searchQuickrefRow($section, $oex[0], $desc);
            $section = "";
        }
    }

    _searchQuickrefRow("<a href='" . hoturl("help", "t=tags") . "'>Tags</a>", "#discuss", "tagged “discuss” (“tag:discuss” also works)");
    _searchQuickrefRow("", "-#discuss", "not tagged “discuss”");
    _searchQuickrefRow("", "order:discuss", "tagged “discuss”, sort by tag order (“rorder:” for reverse order)");
    _searchQuickrefRow("", "#disc*", "matches any tag that <em>starts with</em> “disc”");

    $cx = null;
    $cm = array();
    foreach ($Conf->tags() as $t)
        foreach ($t->colors ? : array() as $c) {
            $cx = $cx ? : $c;
            if ($cx === $c)
                $cm[] = "“{$t->tag}”";
        }
    if (!empty($cm)) {
        array_unshift($cm, "“{$cx}”");
        _searchQuickrefRow("", "style:$cx", "tagged to appear $cx (tagged " . commajoin($cm, "or") . ")");
    }

    $roundname = meaningful_round_name();

    _searchQuickrefRow("Reviews", "re:me", "you are a reviewer");
    _searchQuickrefRow("", "re:fdabek", "“fdabek” in reviewer name/email");
    if ($retag)
        _searchQuickrefRow("", "re:#$retag", "has a reviewer tagged “#" . $retag . "”");
    _searchQuickrefRow("", "re:4", "four reviewers (assigned and/or completed)");
    if ($retag)
        _searchQuickrefRow("", "re:#$retag>1", "at least two reviewers (assigned and/or completed) tagged “#" . $retag . "”");
    _searchQuickrefRow("", "re:complete<3", "less than three completed reviews<br /><span class=\"hint\">Use “cre:<3” for short.</span>");
    _searchQuickrefRow("", "re:incomplete>0", "at least one incomplete review");
    _searchQuickrefRow("", "re:inprogress", "at least one in-progress review (started, but not completed)");
    _searchQuickrefRow("", "re:primary>=2", "at least two primary reviewers");
    _searchQuickrefRow("", "re:secondary", "at least one secondary reviewer");
    _searchQuickrefRow("", "re:external", "at least one external reviewer");
    _searchQuickrefRow("", "re:primary:fdabek:complete", "“fdabek” has completed a primary review");
    if ($roundname)
        _searchQuickrefRow("", "re:$roundname", "review in round “" . htmlspecialchars($roundname) . "”");
    _searchQuickrefRow("", "re:auwords<100", "has a review with less than 100 words in author-visible fields");
    if ($Conf->setting("rev_ratings") != REV_RATINGS_NONE)
        _searchQuickrefRow("", "rate:+", "review was rated positively (“rate:-” and “rate:+>2” also work; can combine with “re:”)");
    _searchQuickrefRow("Comments", "has:cmt", "at least one visible reviewer comment (not including authors’ response)");
    _searchQuickrefRow("", "cmt:>=3", "at least <em>three</em> visible reviewer comments");
    _searchQuickrefRow("", "has:aucmt", "at least one reviewer comment visible to authors");
    _searchQuickrefRow("", "cmt:sylvia", "“sylvia” (in name/email) wrote at least one visible comment; can combine with counts, use reviewer tags");
    $rnames = $Conf->resp_round_list();
    if (count($rnames) > 1) {
        _searchQuickrefRow("", "has:response", "has an author’s response");
        _searchQuickrefRow("", "has:{$rnames[1]}response", "has $rnames[1] response");
    } else
        _searchQuickrefRow("", "has:response", "has author’s response");
    _searchQuickrefRow("", "anycmt:>1", "at least two visible comments, possibly <em>including</em> author’s response");
    _searchQuickrefRow("Leads", "lead:fdabek", "“fdabek” (in name/email) is discussion lead");
    _searchQuickrefRow("", "lead:none", "no assigned discussion lead");
    _searchQuickrefRow("", "lead:any", "some assigned discussion lead");
    _searchQuickrefRow("Shepherds", "shep:fdabek", "“fdabek” (in name/email) is shepherd (“none” and “any” also work)");
    _searchQuickrefRow("Conflicts", "conflict:me", "you have a conflict with the paper");
    _searchQuickrefRow("", "conflict:fdabek", "“fdabek” (in name/email) has a conflict with the paper<br /><span class='hint'>This search is only available to chairs and to PC members who can see the paper’s author list.</span>");
    _searchQuickrefRow("", "conflict:pc", "some PC member has a conflict with the paper");
    _searchQuickrefRow("", "conflict:pc>2", "at least three PC members have conflicts with the paper");
    _searchQuickrefRow("", "reconflict:\"1 2 3\"", "a reviewer of paper 1, 2, or 3 has a conflict with the paper");
    _searchQuickrefRow("Preferences", "pref:fdabek>0", "“fdabek” (in name/email) has review preference &gt;&nbsp;0<br /><span class='hint'>PC members can search their own preferences; chairs can search anyone’s preferences.</span>");
    _searchQuickrefRow("", "pref:X", "some PC member has a preference expertise of “X” (expert)");
    _searchQuickrefRow("Status", "status:sub", "paper is submitted for review", "t=all");
    _searchQuickrefRow("", "status:unsub", "paper is neither submitted nor withdrawn", "t=all");
    _searchQuickrefRow("", "status:withdrawn", "paper has been withdrawn", "t=all");
    _searchQuickrefRow("", "has:final", "final copy uploaded");

    foreach ($Conf->decision_map() as $dnum => $dname)
        if ($dnum)
            break;
    $qdname = strtolower($dname);
    if (strpos($qdname, " ") !== false)
        $qdname = "\"$qdname\"";
    _searchQuickrefRow("Decision", "dec:$qdname", "decision is “" . htmlspecialchars($dname) . "” (partial matches OK)");
    _searchQuickrefRow("", "dec:yes", "one of the accept decisions");
    _searchQuickrefRow("", "dec:no", "one of the reject decisions");
    _searchQuickrefRow("", "dec:any", "decision specified");
    _searchQuickrefRow("", "dec:none", "decision unspecified");

    // find names of review fields to demonstrate syntax
    $farr = array(array(), array());
    foreach ($Conf->all_review_fields() as $f)
        $farr[$f->has_options ? 0 : 1][] = $f;
    $t = "Review&nbsp;fields";
    if (count($farr[0])) {
        $r = $farr[0][0];
        _searchQuickrefRow($t, "{$r->abbreviation1()}:{$r->typical_score()}", "at least one completed review has $r->name_html score {$r->typical_score()}");
        _searchQuickrefRow("", "{$r->abbreviation()}:{$r->typical_score()}", "other abbreviations accepted");
        if (count($farr[0]) > 1) {
            $r2 = $farr[0][1];
            _searchQuickrefRow("", strtolower($r2->abbreviation()) . ":{$r2->typical_score()}", "other fields accepted (here, $r2->name_html)");
        }
        if (($range = $r->typical_score_range())) {
            _searchQuickrefRow("", "{$r->abbreviation()}:{$range[0]}..{$range[1]}", "completed reviews’ $r->name_html scores are in the {$range[0]}&ndash;{$range[1]} range<br /><small>(all scores between {$range[0]} and {$range[1]})</small>");
            $rt = $range[0] . ($r->option_letter ? "" : "-") . $range[1];
            _searchQuickrefRow("", "{$r->abbreviation()}:$rt", "completed reviews’ $r->name_html scores <em>fill</em> the {$range[0]}&ndash;{$range[1]} range<br /><small>(all scores between {$range[0]} and {$range[1]}, with at least one {$range[0]} and at least one {$range[1]})</small>");
        }
        if (!$r->option_letter)
            list($greater, $less, $hint) = array("greater", "less", "");
        else {
            $hint = "<br /><small>(better scores are closer to A than Z)</small>";
            if (opt("smartScoreCompare"))
                list($greater, $less) = array("better", "worse");
            else
                list($greater, $less) = array("worse", "better");
        }
        _searchQuickrefRow("", "{$r->abbreviation()}:>{$r->typical_score()}", "at least one completed review has $r->name_html score $greater than {$r->typical_score()}" . $hint);
        _searchQuickrefRow("", "{$r->abbreviation()}:2<={$r->typical_score()}", "at least two completed reviews have $r->name_html score $less than or equal to {$r->typical_score()}");
        if ($roundname)
            _searchQuickrefRow("", "{$r->abbreviation()}:$roundname>{$r->typical_score()}", "at least one completed review in round " . htmlspecialchars($roundname) . " has $r->name_html score $greater than {$r->typical_score()}");
        _searchQuickrefRow("", "{$r->abbreviation()}:ext>{$r->typical_score()}", "at least one completed external review has $r->name_html score $greater than {$r->typical_score()}");
        _searchQuickrefRow("", "{$r->abbreviation()}:pc:2>{$r->typical_score()}", "at least two completed PC reviews have $r->name_html score $greater than {$r->typical_score()}");
        _searchQuickrefRow("", "{$r->abbreviation()}:sylvia={$r->typical_score()}", "“sylvia” (reviewer name/email) gave $r->name_html score {$r->typical_score()}");
        $t = "";
    }
    if (count($farr[1])) {
        $r = $farr[1][0];
        _searchQuickrefRow($t, $r->abbreviation1() . ":finger", "at least one completed review has “finger” in the $r->name_html field");
        _searchQuickrefRow($t, "{$r->abbreviation()}:finger", "other abbreviations accepted");
        _searchQuickrefRow($t, "{$r->abbreviation()}:any", "at least one completed review has text in the $r->name_html field");
    }

    if (count($farr[0])) {
        $r = $farr[0][0];
        _searchQuickrefRow("<a href=\"" . hoturl("help", "t=formulas") . "\">Formulas</a>",
                           "formula:all({$r->abbreviation()}={$r->typical_score()})",
                           "all reviews have $r->name_html score {$r->typical_score()}<br />"
                           . "<span class='hint'><a href=\"" . hoturl("help", "t=formulas") . "\">Formulas</a> can express complex numerical queries across review scores and preferences.</span>");
        _searchQuickrefRow("", "f:all({$r->abbreviation()}={$r->typical_score()})", "“f” is shorthand for “formula”");
        _searchQuickrefRow("", "formula:var({$r->abbreviation()})>0.5", "variance in {$r->abbreviation()} is above 0.5");
        _searchQuickrefRow("", "formula:any({$r->abbreviation()}={$r->typical_score()} && pref<0)", "at least one reviewer had $r->name_html score {$r->typical_score()} and review preference &lt; 0");
    }

    _searchQuickrefRow("Display", "show:tags show:conflicts", "show tags and PC conflicts in the results");
    _searchQuickrefRow("", "hide:title", "hide title in the results");
    if (count($farr[0])) {
        $r = $farr[0][0];
        _searchQuickrefRow("", "show:max({$r->abbreviation()})", "show a <a href=\"" . hoturl("help", "t=formulas") . "\">formula</a>");
        _searchQuickrefRow("", "show:statistics", "show summary statistics for formulas");
        _searchQuickrefRow("", "sort:{$r->abbreviation()}", "sort by score");
        _searchQuickrefRow("", "sort:\"{$r->abbreviation()} variance\"", "sort by score variance");
    }
    _searchQuickrefRow("", "sort:-status", "sort by reverse status");
    _searchQuickrefRow("", "edit:#discuss", "edit the values for tag “#discuss”");
    _searchQuickrefRow("", "search1 THEN search2", "like “search1 OR search2”, but papers matching “search1” are grouped together and appear earlier in the sorting order");
    _searchQuickrefRow("", "1-5 THEN 6-10 show:compact", "display searches in compact columns");
    _searchQuickrefRow("", "search1 HIGHLIGHT search2", "search for “search1”, but <span class=\"taghl highlightmark\">highlight</span> papers in that list that match “search2” (also try HIGHLIGHT:pink, HIGHLIGHT:green, HIGHLIGHT:blue)");
}

function _current_tag_list($property) {
    global $Conf;
    $ct = $Conf->tags()->filter($property);
    return empty($ct) ? "" : " (currently "
            . join(", ", array_map(function ($t) { return "“" . Ht::link($t->tag, hoturl("search", "q=%23{$t->tag}")) . "”"; }, $ct))
            . ")";
}

function _singleVoteTag() {
    global $Conf;
    $vt = $Conf->tags()->filter("vote");
    return empty($vt) ? "vote" : current($vt)->tag;
}

function tags() {
    global $Conf, $Me;

    // get current tag settings
    $chairtags = "";
    $votetags = "";
    $votetag1 = "vote";
    $conflictmsg1 = "";
    $conflictmsg2 = "";
    $conflictmsg3 = "";
    $setting = "";

    if ($Me->isPC) {
        $chairtags = _current_tag_list("chair");
        $votetags = _current_tag_list("vote");
        $votetag1 = _singleVoteTag();

        if ($Me->privChair)
            $setting = "  (<a href='" . hoturl("settings", "group=tags") . "'>Change this setting</a>)";

        if ($Conf->tag_seeall) {
            $conflictmsg3 = "Currently PC members can see tags for any paper, including conflicts.";
        } else {
            $conflictmsg1 = " and conflicted PC members";
            $conflictmsg2 = "  However, since PC members can’t see tags for conflicted papers, each PC member might see a different list." . $setting;
            $conflictmsg3 = "They are hidden from conflicted PC members—for instance, if a PC member searches for a tag, the results will never include conflicts.";
        }
    }

    _subhead("", "
<p>PC members and administrators can attach tag names to papers.
It’s easy to add and remove tags and to list all papers with a given tag,
and <em>ordered</em> tags preserve a particular paper order.
Tags also affect color highlighting in paper lists.</p>

<p>Tags are visible to the PC and hidden from authors$conflictmsg1.
<em>Twiddle tags</em>, with names like “#~tag”, are visible only
to their creators.  Tags with two twiddles, such as “#~~tag”, are
visible only to PC chairs.</p>");

    _subhead("Finding tags", "
<p>A paper’s tags are shown like this on the paper page:</p>

<div class='pspcard_container' style='position:static'><div class='pspcard'><div class='pspcard_body'>
<div class='psc psc1'>
 <div class='pst'>
  <span class='psfn'>Tags</span>
  <span class='pstedit'><a class='xx'><span style='display:inline-block;position:relative;width:16px'>" . Ht::img("edit48.png", "[Edit]", "editimg") . "</span>&nbsp;<u class='x'>Edit</u></a></span>
  <hr class='c' /></div>
<div class='psv'><div class='taghl'>#earlyaccept</div></div></div>
</div></div></div><hr class='c' />

<p>To find all papers with tag “#discuss”:&nbsp; " . _searchForm("#discuss") . "</p>

<p>You can also search with “" . _searchLink("show:tags") . "” to see each
paper’s tags, or “" . _searchLink("show:#tagname") . "” to see a particular tag
as a column.</p>

<p>Tags are only shown to PC members and administrators.
$conflictmsg3$setting
Additionally, twiddle tags, which have names like “#~tag”, are
visible only to their creators; each PC member has an independent set.
Tags are not case sensitive.</p>");

    _subhead("<a name='changing'>Changing tags</a>", "
<ul>
<li><p><strong>For one paper:</strong> Go to a paper page, select the Tags box’s
“Edit” link, and enter tags separated by spaces.</p>

<p>" . Ht::img("extagsset.png", "[Tag entry on review screen]", ["width" => 142, "height" => 87]) . "</p></li>

<li><p><strong>For many papers:</strong> <a href=\"" . hoturl("search")
. "\">Search</a> for papers, select them, and use the action area underneath the
search list. <b>Add</b> adds tags to the selected papers, <b>Remove</b> removes
tags from the selected papers, and <b>Define</b> adds the tag to the selected
papers and removes it from all others.  The chair-only <b>Clear twiddle</b>
action removes a tag and all users’ matching twiddle tags.</p>

<p>" . Ht::img("extagssearch.png", "[Setting tags on the search page]", ["width" => 510, "height" => 94]) . "</p></li>

<li><p><strong>With search keywords:</strong> Search for “"
. _searchLink("edit:tag:tagname") . "” to add tags with checkboxes;
search for “" . _searchLink("edit:tagval:tagname") . "” to type in <a
href='#values'>tag values</a>; or search for “" . _searchLink("edit:tags") . "”
to edit papers’ full tag lists.</p>

<p>" . Ht::img("extagseditkw.png", "[Tag editing search keywords]", ["width" => 543, "height" => 133]) . "</p></li>

<li><p><strong>In bulk:</strong> Administrators can also upload tag
assignments using <a href='" .
hoturl("bulkassign") . "'>bulk assignment</a>.</p></li>

</ul>

<p>Although any PC member can view or search
most tags, certain tags may be changed only by administrators$chairtags.
$setting</p>");

    _subhead("<a id='values'>Tag values and discussion orders</a>", "
<p>Tags have optional numeric values, which are displayed as
“#tag#100”. Search for “" . _searchLink("order:tag") . "” to sort tagged
papers by value. You can also search for specific values with search terms
like “" . _searchLink("#discuss#2") . "” or “" . _searchLink("#discuss>1") .
"”.</p>

<p>It’s common to assign increasing tag values to a set of papers.  Do this
using the <a href='" . hoturl("search") . "'>search screen</a>.  Search for the
papers you want, sort them into the right order, select their checkboxes, and
choose <b>Define order</b> in the tag action area.  If no sort gives what
you want, search for the desired paper numbers in order—for instance,
“" . _searchLink("4 1 12 9") . "”—then <b>Select all</b> and <b>Define
order</b>. To add new papers at the end of an existing discussion order, use
<b>Add to order</b>. To insert papers into an existing order, use <b>Add to
order</b> with a tag value; for example, to insert starting at value 5, use
<b>Add to order</b> with “#tag#5”.  The rest of the order is renumbered to
accommodate the insertion.</p>

<p>Even easier, you can <em>drag</em> papers into order using a search like “"
. _searchLink("editsort:#tag") . "”.</p>

<p><b>Define order</b> might assign values “#tag#1”,
“#tag#3”, “#tag#6”, and “#tag#7”
to adjacent papers.  The gaps make it harder to infer
conflicted papers’ positions.  (Any given gap might or might not hold a
conflicted paper.)  The <b>Define gapless order</b> action assigns
strictly sequential values, like “#tag#1”,
“#tag#2”, “#tag#3”, “#tag#4”.
<b>Define order</b> is better for most purposes.</p>

<p>The <a href=\"" . hoturl("autoassign", "a=discorder") . "\">autoassigner</a>
has special support for creating discussion orders. It tries to group papers
with similar PC conflicts, which can make the meeting run smoother.</p>");

    _subhead("Tag colors, badges, and emoji", "

<p>Tags “red”, “orange”, “yellow”, “green”, “blue”, “purple”, “gray”, and
“white” act as highlight colors. For example, papers tagged with “#red” will
appear <span class=\"tagcolorspan redtag\">red</span> in paper lists (for people
who can see that tag).  Tag a paper “#~red” to make it red only on your display.
Other styles are available; try “#bold”, “#italic”, “#big”, “#small”, and
“#dim”. The <a href='" .
hoturl("settings", "group=tags") . "'>settings page</a> can associate other tags
with colors so that, for example, “" . _searchLink("#reject") . "” papers appear
gray.</p>

<p>The " . Ht::link("settings page", hoturl("settings", "group=tags")) . " can
declare certain tags as <span class=\"badge normalbadge\">badges</span>, which
display near titles.</p>

<p><a href=\"http://www.emoji-cheat-sheet.com/\">Emoji codes</a> like “:fire:”
and “:confused:” can be used as tags. The corresponding emoji displays next to
the paper title. <a href=\"#values\">Tag values</a> show multiple emoji,
so “#:star:#5” shows five stars.</p>

<p>" . Ht::img("extagcolors.png", "[Tag colors, badges, and emoji]", ["width" => 498, "height" => 151]) . "</p></li>

", "colors");

    _subhead("Using tags", "
<p>Here are some example ways to use tags.</p>

<ul>

<li><strong>Skip low-ranked submissions at the PC meeting.</strong> Mark
low-ranked submissions with tag “#nodiscuss”, then ask the PC to " .
_searchLink("#nodiscuss", "search for “#nodiscuss”") . " (“" .
_searchLink("tag:nodiscuss") . "” also works). PC members can check the list
for papers they’d like to discuss anyway. They can email the chairs about
such papers, or, even easier, add a “#discussanyway” tag. (You might make the
“#nodiscuss” tag chair-only so an evil PC member couldn’t add it to a
high-ranked paper, but it’s usually better to trust the PC.)</li>

<li><strong>Mark controversial papers that would benefit from additional review.</strong>
 PC members could add the “#controversy” tag when the current reviewers disagree.
 A <a href='" . hoturl("search", "q=%23controversy") . "'>search</a> shows where the PC thinks more review is needed.</li>

<li><strong>Mark PC-authored papers for extra scrutiny.</strong>
 First, <a href='" . hoturl("search", "t=s&amp;qt=au") . "'>search for PC members’ last names in author fields</a>.
 Check for accidental matches and select the papers with PC members as authors, then use the action area below the search list to add the tag “#pcpaper”.
 A <a href='" . hoturl("search", "t=s&amp;qx=%23pcpaper") . "'>search</a> shows papers without PC authors.
 (Since PC members can see whether a paper is tagged “#pcpaper”, you may want to delay defining the tag until just before the meeting.)</li>

<li><strong>Vote for papers.</strong>
 The chair can define special allotment voting tags$votetags$setting.
 Each PC member is assigned an allotment of votes to distribute among papers.
 For instance, if “#{$votetag1}” were a voting tag with an allotment of 10, then a PC member could assign 5 votes to a paper by adding the twiddle tag “#~{$votetag1}#5”.
 The system automatically sums PC members’ votes into the public “#{$votetag1}” tag.
 To search for papers by vote count, search for “<a href='" . hoturl("search", "t=s&amp;q=rorder:$votetag1") . "'>rorder:{$votetag1}</a>”. (<a href='" . hoturl("help", "t=votetags") . "'>Learn more</a>)</li>

<li><strong>Rank papers.</strong>
 Each PC member can set tags indicating their preference ranking for papers.
 For instance, a PC member’s favorite paper would get tag “#~rank#1”, the next favorite “#~rank#2”, and so forth.
 The chair can then combine these rankings into a global preference order using a Condorcet method.
 (<a href='" . hoturl("help", "t=ranking") . "'>Learn more</a>)</li>

<li><strong>Define a discussion order for the PC meeting.</strong>
 Publishing the order lets PC members prepare to discuss upcoming papers.
 Define an ordered tag such as “#discuss”, then ask the PC to <a href='" . hoturl("search", "q=order:discuss") . "'>search for “order:discuss”</a>.
 The PC can now see the order and use quick links to go from paper to paper.$conflictmsg2</li>

<li><strong>Mark tentative decisions during the PC meeting</strong> either
 using decision selectors or, perhaps, “#accept” and
 “#reject” tags.</li>

</ul>");
}


function tracks() {
    global $Me;

    _subhead("", "
<p>Tracks give you fine-grained control over PC member rights. With tracks, PC
members can have different rights to see and review papers, depending on the
papers’ " . Ht::link("tags", hoturl("help", "t=tags")) . ".</p>

<p>Set up tracks on the <a href=\"" . hoturl("settings", "group=tracks") . "\">Settings &gt;
Tracks</a> page.</p>");

    _subhead("Example: External review committee", "
<p>An <em>external review committee</em> is a subset of the PC that may bid on
papers to review, and may be assigned reviews (using, for example, the
<a href=\"" . hoturl("autoassign") . "\">autoassignment tool</a>), but may not
self-assign reviews, and may not view reviews except for papers they have
reviewed. To set this up:</p>

<ul>
<li>Give external review committee members the “erc” tag.</li>
<li>On Settings &gt; Tracks, “For papers not on other
tracks,” select “Who can see reviews? &gt; PC members without tag: erc”
and “Who can self-assign a review? &gt; PC members without tag: erc”.</li>
</ul>");

    _subhead("Example: PC-paper review committee", "
<p>A <em>PC-paper review committee</em> is a subset of the PC that reviews papers
with PC coauthors. PC-paper review committees are kept separate from the main
PC; they only bid on and review PC papers, while the main PC handles all other
papers. To set this up:</p>

<ul>
<li>Give PC-paper review committee members the “pcrc” tag.</li>
<li>Give PC papers the “pcrc” tag.</li>
<li>On Settings &gt; Tracks, add a track for tag “pcrc” and
  select “Who can see these papers? &gt; PC members with tag: pcrc”.
  (Users who can’t see a paper also can’t review it,
  so there’s no need to explicitly set the other permissions.)</li>
<li>For papers not on other tracks, select “Who can see these papers? &gt; PC
  members without tag: pcrc”.</li>

</ul>");

    _subhead("Example: Track chair", "
<p>A <em>track chair</em> is a PC member with full administrative
rights over a subset of papers. To set this up for, say, an “industrial”
track:</p>

<ul>
<li>Give the industrial track chair(s) the “industrial-chair” tag.</li>
<li>Give industrial-track papers the “industrial” tag.</li>
<li>On Settings &gt; Tracks, add a track for tag “industrial”,
  and select “Who can administer these papers? &gt; PC members with tag:
  industrial-chair”.</li>
</ul>

<p>A track chair can run the autoassigner, make assignments, edit papers, and
generally administer all papers on their tracks. Track chairs cannot modify
site settings or change track tags, however.</p>");

    _subhead("Understanding permissions", "
<p>Tracks restrict permissions.
For example, when
the “PC members can review <strong>any</strong> submitted paper”
setting is off, <em>no</em> PC member can enter an unassigned review,
no matter what the track settings say.
It can be useful to “act as” a member of the PC to check which permissions
are actually in effect.</p>");
}



function revround() {
    global $Conf, $Me;

    _subhead("", "
<p>Many conferences divide their review assignments into multiple <em>rounds</em>.
Each round is given a name, such as “R1” or “lastround”
(we suggest very short names like “R1”).
Configure rounds on the <a href='" . hoturl("settings", "group=reviews#rounds")
. "'>settings page</a>.
To search for any paper with a round “R2” review assignment, <a href='" . hoturl("search", "q=re:R2") . "'>search for re:R2”</a>.
To list a PC member’s round “R1” review assignments, <a href='" . hoturl("search", "q=re:membername:R1") . "'>search for “re:membername:R1”</a>.</p>

<p>Different rounds usually share the same review form, but you can also
mark review fields as appearing only in certain rounds. First configure
rounds, then see
<a href=\"" . hoturl("settings", "group=reviewform") . "\">Settings &gt; Review form</a>.</p>");

    _subhead("Assigning rounds", "
<p>New assignments are marked by default with the round defined in
<a href='" . hoturl("settings", "group=reviews#rounds") . "'>review settings</a>.
The automatic and bulk assignment pages also let you set a review round.</p>");

    // get current tag settings
    if ($Me->isPC) {
        $texts = array();
        if (($rr = $Conf->assignment_round_name(false))) {
            $texts[] = "The review round for new assignments is “<a href=\""
                . hoturl("search", "q=round%3A" . urlencode($rr))
                . "\">" . htmlspecialchars($rr) . "</a>”";
            if ($Me->privChair)
                $texts[0] .= " (use <a href=\"" . hoturl("settings", "group=reviews#rounds") . "\">Settings &gt; Reviews</a> to change this).";
            else
                $texts[0] .= ".";
        }
        $rounds = array();
        if ($Conf->has_rounds()) {
            $result = $Conf->qe("select distinct reviewRound from PaperReview");
            while (($row = edb_row($result)))
                if ($row[0] && ($rname = $Conf->round_name($row[0])))
                    $rounds[] = "“<a href=\""
                        . hoturl("search", "q=round%3A" . urlencode($rname))
                        . "\">" . htmlspecialchars($rname) . "</a>”";
            sort($rounds);
        }
        if (count($rounds))
            $texts[] = "Review rounds currently in use: " . commajoin($rounds) . ".";
        else if (!count($texts))
            $texts[] = "So far no review rounds have been defined.";
        _subhead("Round status", join(" ", $texts));
    }
}


function revrate() {
    global $Conf, $Me;

    _subhead("", "
<p>PC members and, optionally, external reviewers can rate one another’s
reviews.  We hope this feedback will help reviewers improve the quality of
their reviews.  The interface appears above each visible review:</p>

<p><div class='rev_rating'>
  How helpful is this review? &nbsp;<form class><div class=\"inline\">"
                  . Ht::select("rating", ReviewForm::$rating_types, "n")
                  . "</div></form>
</div></p>

<p>When rating a review, please consider its value for both the program
  committee and the authors.  Helpful reviews are specific, clear, technically
  focused, and, when possible, provide direction for the authors’ future work.
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
<dd>The review’s arguments are weak, mushy, or otherwise technically
  unconvincing.</dd>
<dt><strong>Too narrow</strong></dt>
<dd>The review’s perspective seems limited; for instance, it might
  overly privilege the reviewer’s own work.</dd>
<dt><strong>Not constructive</strong></dt>
<dd>The review’s tone is unnecessarily aggressive or gives little useful
  direction.</dd>
<dt><strong>Not correct</strong></dt>
<dd>The review misunderstands the paper.</dd>
</dl>

<p>HotCRP reports the numbers of non-average ratings for each review.
  It does not report who gave the ratings, and it
  never shows rating counts to authors.</p>

<p>To find which of your reviews might need work, simply
<a href='" . hoturl("search", "q=rate:-") . "'>search for “rate:&minus;”</a>.
To find all reviews with positive ratings,
<a href='" . hoturl("search", "q=re:any+rate:%2B") . "'>search for “re:any&nbsp;rate:+”</a>.
You may also search for reviews with specific ratings; for instance,
<a href='" . hoturl("search", "q=rate:helpful") . "'>search for “rate:helpful”</a>.</p>");

    if ($Conf->setting("rev_ratings") == REV_RATINGS_PC)
        $what = "only PC members";
    else if ($Conf->setting("rev_ratings") == REV_RATINGS_PC_EXTERNAL)
        $what = "PC members and external reviewers";
    else
        $what = "no one";
    _subhead("Settings", "
<p>Chairs set how ratings work on the <a href=\"" . hoturl("settings", "group=reviews") . "\">review settings
page</a>.", ($Me->is_reviewer() ? " Currently, $what can rate reviews." : ""), "</p>");

    _subhead("Visibility", "
<p>A review’s ratings are visible to any unconflicted PC members who can see
the review, but HotCRP tries to hide ratings from review authors if they
could figure out who assigned the rating: if only one PC member could
rate a review, then that PC member’s rating is hidden from the review
author.</p>");
}


function scoresort() {
    global $Conf, $Me;

    _subhead("", "
<p>Some paper search results include columns with score graphs. Click on a score
column heading to sort the paper list using that score. Search &gt; Display
options changes how scores are sorted.  There are five choices:</p>

<dl>

<dt><strong>Counts</strong> (default)</dt>

<dd>Sort by the number of highest scores, then the number of second-highest
scores, then the number of third-highest scores, and so on.  To sort a paper
with fewer reviews than others, HotCRP adds phantom reviews with scores just
below the paper’s lowest real score.  Also known as Minshall score.</dd>

<dt><strong>Average</strong></dt>
<dd>Sort by the average (mean) score.</dd>

<dt><strong>Median</strong></dt>
<dd>Sort by the median score.</dd>

<dt><strong>Variance</strong></dt>
<dd>Sort by the variance in scores.</dd>

<dt><strong>Max &minus; min</strong></dt>
<dd>Sort by the difference between the largest and smallest scores (a good
measure of differences of opinion).</dd>

<dt><strong>My score</strong></dt>
<dd>Sort by your score.  In the score graphs, your score is highlighted with a
darker colored square.</dd>

</dl>");
}


function showvotetags() {
    global $Conf, $Me;

    _subhead("", "
<p>Some conferences have PC members vote for papers.
Each PC member is assigned a vote allotment, and can distribute that allotment
arbitrarily among unconflicted papers.
Alternately, each PC member can vote, once, for as many papers as they like (“approval voting”).
The PC’s aggregated vote totals might help determine
which papers to discuss.</p>

<p>HotCRP supports voting through the <a href='" . hoturl("help", "t=tags") . "'>tags system</a>.
The chair can <a href='" . hoturl("settings", "group=tags") . "'>define a set of voting tags</a> and allotments" . _current_tag_list("vote") . ".
PC members vote by assigning the corresponding twiddle tags;
the aggregated PC vote is visible in the public tag.</p>

<p>For example, assume that an administrator defines a voting tag
 “". _singleVoteTag() . "” with an allotment of 10.
To use two votes for a paper, a PC member tags the paper as
“~". _singleVoteTag() . "#2”. The system
automatically adds the tag “". _singleVoteTag() . "#2” to that
paper (note the
lack of the “~”), indicating that the paper has two total votes.
As other PC members add their votes with their own “~” tags, the system
updates the main tag to reflect the total.
(The system ensures no PC member exceeds their allotment.) </p>

<p>
To see the current voting status, search by
<a href=\"" . hoturl("search", "q=rorder:" . _singleVoteTag() . "") . "\">
rorder:". _singleVoteTag() . "</a>. Use the display options to show tags
in the search results (or set up a
<a href='" . hoturl("help", "t=formulas") . "'>formula</a>).
</p>

<p>
Hover to learn how the PC voted:</p>

<p>" . Ht::img("extagvotehover.png", "[Hovering over a voting tag]", ["width" => 390, "height" => 46]) . "</p>");
}


function showranking() {
    global $Conf, $Me;

    _subhead("", "
<p>Paper ranking is a way to extract the PC’s preference order for
submitted papers.  Each PC member ranks the submitted papers, and a voting
algorithm, <a href='http://en.wikipedia.org/wiki/Schulze_method'>the Schulze
method</a> by default, combines these rankings into a global preference order.</p>

<p>HotCRP supports ranking through <a
href='" . hoturl("help", "t=tags") . "'>tags</a>.  The chair chooses
a tag for ranking—“rank” is a good default—and enters it on <a
href='" . hoturl("settings", "group=tags") . "'>the settings page</a>.
PC members then rank papers using their private versions of this tag,
tagging their first preference with “~rank#1”,
their second preference with “~rank#2”,
and so forth.  To combine PC rankings into a global preference order, the PC
chair selects all papers on the <a href='" . hoturl("search", "q=") . "'>search page</a>
and chooses Tags &gt; Calculate&nbsp;rank, entering
“rank” for the tag.  At that point, the global rank can be viewed
by a <a href='" . hoturl("search", "q=order:rank") . "'>search for
“order:rank”</a>.</p>

<p>PC members can enter rankings by reordering rows in a paper list.
For example, for rank tag “rank”, PC members should
<a href=\"" . hoturl("search", "q=editsort%3A%23~rank") . "\">search for “editsort:#~rank”</a>.
Ranks can be entered directly in the text fields, or the rows can be dragged
into position using the dotted areas on the right-hand side of the list.</p>

<p>Alternately, PC members can use an <a href='" . hoturl("offline") . "'>offline
ranking form</a>. Download a ranking file, rearrange the lines to create a
rank, and upload the form again.  For example, here is an initial ranking
file:</p>

<pre class='entryexample'>
# Edit the rank order by rearranging this file's lines.
# The first line has the highest rank.

# Lines that start with \"#\" are ignored.  Unranked papers appear at the end
# in lines starting with \"X\", sorted by overall merit.  Create a rank by
# removing the \"X\"s and rearranging the lines.  A line that starts with \"=\"
# marks a paper with the same rank as the preceding paper.  A line that starts
# with \">>\", \">>>\", and so forth indicates a rank gap between the preceding
# paper and the current paper.  When you are done, upload the file at
#   http://your.site.here.com/offline

Tag: ~rank


X	1	Write-Back Caches Considered Harmful
X	2	Deconstructing Suffix Trees
X	4	Deploying Congestion Control Using Homogeneous Modalities
X	5	The Effect of Collaborative Epistemologies on Theory
X	6	The Influence of Probabilistic Methodologies on Networking
X	8	Rooter: A Methodology for the Typical Unification of Access Points and Redundancy
X	10	Decoupling Lambda Calculus from 802.11 Mesh Networks in Moore's Law
X	11	Analyzing Scatter/Gather I/O Using Encrypted Epistemologies
</pre>

<p>The user might edit the file as follows:</p>

<pre class='entryexample'>
	8	Rooter: A Methodology for the Typical Unification of Access Points and Redundancy
	5	The Effect of Collaborative Epistemologies on Theory
=	1	Write-Back Caches Considered Harmful
	2	Deconstructing Suffix Trees
>>	4	Deploying Congestion Control Using Homogeneous Modalities

X	6	The Influence of Probabilistic Methodologies on Networking
X	10	Decoupling Lambda Calculus from 802.11 Mesh Networks in Moore's Law
X	11	Analyzing Scatter/Gather I/O Using Encrypted Epistemologies
</pre>

<p>Uploading this file produces the following ranking:</p>

<p><table><tr><th class='pad'>ID</th><th>Title</th><th>Rank tag</th></tr>
<tr><td class='pad'>#8</td><td class='pad'>Rooter: A Methodology for the Typical Unification of Access Points and Redundancy</td><td class='pad'>~rank#1</td></tr>
<tr><td class='pad'>#5</td><td class='pad'>The Effect of Collaborative Epistemologies on Theory</td><td class='pad'>~rank#2</td></tr>
<tr><td class='pad'>#1</td><td class='pad'>Write-Back Caches Considered Harmful</td><td class='pad'>~rank#2</td></tr>
<tr><td class='pad'>#2</td><td class='pad'>Deconstructing Suffix Trees</td><td class='pad'>~rank#3</td></tr>
<tr><td class='pad'>#4</td><td class='pad'>Deploying Congestion Control Using Homogeneous Modalities</td><td class='pad'>~rank#5</td></tr></table></p>

<p>Since #6, #10, and #11 still had X prefixes, they were not assigned a rank.
 Searching for “order:~rank” returns the user’s personal ranking;
 administrators can search for
 “order:<i>pcname</i>~rank” to see a PC member’s ranking.
 Once a global ranking is assigned, “order:rank” will show it.</p>");
}


function showformulas() {
    global $Conf, $Me, $rowidx;

    _subhead("", "
<p>Program committee members and administrators can search and display <em>formulas</em>
that calculate properties of paper scores&mdash;for instance, the
standard deviation of papers’ Overall merit scores, or average Overall
merit among reviewers with high Reviewer expertise.</p>

<p>To display a formula, use a search term such as “<a href=\""
             . hoturl("search", "q=show%3avar%28OveMer%29") . "\">show:var(OveMer)</a>” (show
the variance in Overall merit scores).
Add “<a href=\"" . hoturl("search", "q=show%3avar%28OveMer%29+show%3astatistics") . "\">show:statistics</a>” to show summary statistics over all papers,
and <a href=\"" . hoturl("graph", "g=formula") . "\">graph formulas</a>.
To search for a formula, use a search term such as “<a href=\""
             . hoturl("search", "q=formula%3avar%28OveMer%29%3e0.5") . "\">formula:var(OveMer)>0.5</a>”
(select papers with variance in Overall merit greater than 0.5).
Or save formulas using <a
href=\"" . hoturl("search", "q=&amp;tab=formulas") . "\">Search &gt; Display options
&gt; Edit formulas</a>.</p>

<p>Formulas use a familiar expression language.
For example, this computes the sum of the squares of the overall merit scores:</p>

<blockquote>sum(OveMer*OveMer)</blockquote>

This calculates an average of overall merit scores, weighted by expertise
(high-expertise reviews are given slightly more weight):</p>

<blockquote>wavg(OveMer, RevExp >= 4 ? 1 : 0.8)</blockquote>

<p>And there are many variations. This version gives more weight to PC
reviewers with the “#heavy” tag:</p>

<blockquote>wavg(OveMer, re:#heavy + 1)</blockquote>

<p>(“re:#heavy + 1” equals 2 for #heavy reviews and 1 for others.)</p>

<p>Formulas work better for numeric scores, but you can use them for letter
scores too. HotCRP uses alphabetical order for letter scores, so the “min” of
scores A, B, and D is A. For instance:</p>

<blockquote>count(confidence=X)</blockquote>");

    _subhead("Expressions", "
<p>Formula expressions are built from the following parts:</p>");
    echo "<table class=\"helppage\">";
    _alternateRow("Arithmetic", "2", "Numbers");
    _alternateRow("", "true, false", "Booleans");
    _alternateRow("", "<em>e</em> + <em>e</em>, <em>e</em> - <em>e</em>", "Addition, subtraction");
    _alternateRow("", "<em>e</em> * <em>e</em>, <em>e</em> / <em>e</em>, <em>e</em> % <em>e</em>", "Multiplication, division, remainder");
    _alternateRow("", "<em>e</em> ** <em>e</em>", "Exponentiation");
    _alternateRow("", "<em>e</em> == <em>e</em>, <em>e</em> != <em>e</em>,<br /><em>e</em> &lt; <em>e</em>, <em>e</em> &gt; <em>e</em>, <em>e</em> &lt;= <em>e</em>, <em>e</em> &gt;= <em>e</em>", "Comparisons");
    _alternateRow("", "!<em>e</em>", "Logical not");
    _alternateRow("", "<em>e1</em> &amp;&amp; <em>e2</em>", "Logical and (returns <em>e1</em> if <em>e1</em> is false, otherwise returns <em>e2</em>)");
    _alternateRow("", "<em>e1</em> || <em>e2</em>", "Logical or (returns <em>e1</em> if <em>e1</em> is true, otherwise returns <em>e2</em>)");
    _alternateRow("", "<em>test</em> ? <em>iftrue</em> : <em>iffalse</em>", "If-then-else operator");
    _alternateRow("", "(<em>e</em>)", "Parentheses");
    _alternateRow("", "greatest(<em>e</em>, <em>e</em>, ...)", "Maximum");
    _alternateRow("", "least(<em>e</em>, <em>e</em>, ...)", "Minimum");
    _alternateRow("", "log(<em>e</em>)", "Natural logarithm");
    _alternateRow("", "log(<em>e</em>, <em>b</em>)", "Log to the base <em>b</em>");
    _alternateRow("", "round(<em>e</em>[, <em>m</em>])", "Round to the nearest multiple of <em>m</em>");
    _alternateRow("", "null", "The null value");
    _alternateRow("Tags", "#<em>tagname</em>", "True if this paper has tag <em>tagname</em>");
    _alternateRow("", "tagval:<em>tagname</em>", "The value of tag <em>tagname</em>, or null if this paper doesn’t have that tag");
    _alternateRow("Scores", "overall-merit", "This review’s Overall merit score<div class=\"hint\">Only completed reviews are considered.</div>");
    _alternateRow("", "OveMer", "Abbreviations also accepted");
    _alternateRow("", "OveMer:external", "Overall merit for external reviews, null for other reviews");
    _alternateRow("", "OveMer:R2", "Overall merit for round R2 reviews, null for other reviews");
    _alternateRow("Submitted reviews", "re:type", "Review type");
    _alternateRow("", "re:round", "Review round");
    _alternateRow("", "re:auwords", "Review word count (author-visible fields only)");
    _alternateRow("", "re:primary", "True for primary reviews");
    _alternateRow("", "re:secondary", "True for secondary reviews");
    _alternateRow("", "re:external", "True for external reviews");
    _alternateRow("", "re:pc", "True for PC reviews");
    _alternateRow("", "re:sylvia", "True if reviewer matches “sylvia”");
    if (($retag = meaningful_pc_tag()))
        _alternateRow("", "re:#$retag", "True if reviewer has tag “#{$retag}”");
    _alternateRow("Review preferences", "pref", "Review preference");
    _alternateRow("", "prefexp", "Predicted expertise");
    echo "</table>\n";

    _subhead("Aggregate functions", "
<p>Aggregate functions calculate a
value based on all of a paper’s submitted reviews and/or review preferences.
For instance, “max(OveMer)” would return the maximum Overall merit score
assigned to a paper.</p>

<p>An aggregate function’s argument is calculated once per visible review
or preference.
For instance, “max(OveMer/RevExp)” calculates the maximum value of
“OveMer/RevExp” for any review, whereas
“max(OveMer)/max(RevExp)” divides the maximum overall merit by the
maximum reviewer expertise.</p>

<p>The top-level value of a formula expression cannot be a raw review score
or preference.
Use an aggregate function to calculate a property over all review scores.</p>");
    echo "<table class=\"helppage\">";
    $rowidx = null;
    _alternateRow("Aggregates", "max(<em>e</em>), min(<em>e</em>)", "Maximum, minimum");
    _alternateRow("", "count(<em>e</em>)", "Number of reviews where <em>e</em> is not null or false");
    _alternateRow("", "sum(<em>e</em>)", "Sum");
    _alternateRow("", "avg(<em>e</em>)", "Average (mean)");
    _alternateRow("", "wavg(<em>e</em>, <em>weight</em>)", "Weighted average; equals “sum(<em>e</em> * <em>weight</em>) / sum(<em>weight</em>)”");
    _alternateRow("", "median(<em>e</em>)", "Median");
    _alternateRow("", "quantile(<em>e</em>, <em>p</em>)", "Quantile; 0≤<em>p</em>≤1; 0 yields min, 0.5 median, 1 max");
    _alternateRow("", "stddev(<em>e</em>)", "Population standard deviation");
    _alternateRow("", "var(<em>e</em>)", "Population variance");
    _alternateRow("", "stddev_samp(<em>e</em>), var_samp(<em>e</em>)", "Sample standard deviation, sample variance");
    _alternateRow("", "any(<em>e</em>)", "True if any of the reviews have <em>e</em> true");
    _alternateRow("", "all(<em>e</em>)", "True if all of the reviews have <em>e</em> true");
    _alternateRow("", "argmin(<em>x</em>, <em>e</em>)", "Value of <em>x</em> when <em>e</em> is minimized");
    _alternateRow("", "argmax(<em>x</em>, <em>e</em>)", "Value of <em>x</em> when <em>e</em> is maximized");
    _alternateRow("", "my(<em>e</em>)", "Calculate <em>e</em> for your review");
    echo "</table>\n";

}


function chair() {
    _subhead("Submission time", "
<p>Follow these steps to prepare to accept paper submissions.</p>

<ol>

<li><p><strong><a href='" . hoturl("settings", "group=users") . "'>Set up PC
  member accounts</a></strong>. Many PCs are divided into classes, such as
  “heavy” and “light”, or “PC” and “ERC”. Mark these classes with user tags.
  It’s also useful to configure <a href='" . hoturl("settings",
  "group=tags") . "'>tag colors</a> so that PC member names are displayed
  differently based on class (for instance, heavy PC member names might appear
  in <b>bold</b>).</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=sub") . "'>Set submission
  policies</a></strong>, including whether submission is blind.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=sub") . "'>Set submission
  deadlines.</a></strong> Authors first <em>register</em>, then <em>submit</em>
  their papers, possibly multiple times; they choose for each submitted
  version whether that version is ready for review.  Normally, HotCRP allows
  authors to update their papers until the deadline, but you can also require
  that authors “freeze” each submission explicitly; only
  administrators can update frozen submissions.
  The only deadline that really matters is the paper submission
  deadline, but HotCRP also supports a separate paper registration deadline,
  which will force authors to register a few days before they submit.  An
  optional <em>grace period</em> applies to both deadlines:
  HotCRP reports the deadlines, but allows submissions and updates post-deadline
  for the specified grace period.  This provides some
  protection against last-minute server overload and gives authors
  some slack.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=subform") . "'>Set up
  the submission form</a></strong>, including whether abstracts are required,
  whether authors check off conflicted PC members (“Collect authors’ PC
  conflicts with checkboxes”), and whether authors must enter additional
  non-PC collaborators, which can help detect conflicts with external
  reviewers (“Collect authors’ other collaborators as text”). The submission
  form also can include:</p>

  <ul>

  <li><p><strong>PDF format checker.</strong> This adds a “Check format” link
  to the Edit Paper screen. Clicking the link checks the paper for formatting
  errors, such as going over the page limit.  Papers with formatting errors
  may still be submitted, since the checker itself can make mistakes, but the
  automated checker leaves cheating authors no excuse.</p></li>

  <li><p><strong>Options</strong> such as checkboxes, selectors, freeform
  text, and uploaded attachments. Checkbox options might include “Consider
  this paper for the Best Student Paper award” or “Provide this paper to the
  European shadow PC.” Attachment options might include supplemental material.
  You can <a href='" . hoturl("search") . "'>search</a> for papers with or
  without each option.</p></li>

  <li><p><strong>Topics.</strong> Authors can select topics, such as
  “Applications” or “Network databases,” that characterize their paper’s
  subject areas.  PC members express topics for which they have high, medium,
  and low interest, improving automatic paper assignment.  Although explicit
  preferences (see below) are better than topic-based assignments, busy PC
  members might not specify their preferences; topic matching lets you do a
  reasonable job at assigning papers anyway.</p></li>

  </ul></li>

<li><p>Take a look at a <a href='" . hoturl("paper", "p=new") . "'>paper
  submission page</a> to make sure it looks right.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=sub") . "'>Open the site
  for submissions.</a></strong> Submissions will be accepted only until the
  listed deadline.</p></li>

</ol>");

    _subhead("Assignments", "
<p>After the submission deadline has passed:</p>

<ol>

<li><p>Consider checking <a
  href='" . hoturl("search", "q=&amp;t=all") . "'>the papers</a> for
  anomalies.  Withdraw and/or delete duplicates or update details on the <a
  href='" . hoturl("paper") . "'>paper pages</a> (via “Edit paper”).
  Also consider contacting the authors of <a
  href='" . hoturl("search", "q=status:unsub&amp;t=all") . "'>papers that
  were never officially submitted</a>, especially if a PDF document was
  uploaded; sometimes a
  user will uncheck “The paper is ready for review” by mistake.</p></li>

<li><p><strong>Check for formatting violations (optional).</strong> <a href='" . hoturl("search", "q=") . "'>Search</a>
  &gt; Download &gt; Format check will download a summary report. Serious errors
  are also shown on paper pages (problematic PDFs are distinguished by an
  “X”).</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=reviewform") . "'>Prepare the
  review form.</a></strong> Take a look at the templates to get
  ideas.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=reviews") . "'>Set review
  policies and deadlines</a></strong>, including reviewing deadlines, whether
  review is blind, and whether PC members may review any paper
  (usually “yes” is the right answer).</p></li>

<li><p><strong><a href='" . hoturl("help", "t=tracks") . "'>Prepare tracks
  (optional).</a></strong> Tracks give chairs fine-grained control over PC
  members’ access rights for individual papers. Example situations calling for
  tracks include external review committees, PC-paper review committees, and
  multi-track conferences.</li>

<li><p><strong><a href='" . hoturl("reviewprefs") . "'>Collect review
  preferences from the PC.</a></strong> PC members can rank-order papers they
  want or don’t want to review.  They can either set their preferences <a
  href='" . hoturl("reviewprefs") . "'>all at once</a>, or (often more
  convenient) page through the <a
  href='" . hoturl("search", "q=&amp;t=s") . "'>list of submitted papers</a>
  setting their preferences on the <a
  href='" . hoturl("paper") . "'>paper pages</a>.</p>

  <p>If you’d like, you can collect review preferences before the submission
  deadline.  Select <a href='" . hoturl("settings", "group=sub") . "'>“PC can
  see <em>all registered papers</em> until submission deadline”</a>, which
  allows PC members to see abstracts for registered papers that haven’t yet
  been submitted.</p></li>

<li><p><strong><a href='" . hoturl("manualassign", "kind=c") . "'>Assign
  conflicts.</a></strong> You can assign conflicts <a
  href='" . hoturl("manualassign", "kind=c") . "'>by PC member</a> or, if
  PC members have entered preferences, <a
  href='" . hoturl("autoassign", "a=prefconflict") . "'>automatically</a>
  by searching for preferences of &minus;100 or less.</p></li>

<li><p><strong><a href='" . hoturl("manualassign") . "'>Assign
  reviews.</a></strong> You can make assignments <a
  href='" . hoturl("assign") . "'>by paper</a>, <a
  href='" . hoturl("manualassign") . "'>by PC member</a>, <a
  href='" . hoturl("bulkassign") . "'>by uploading an assignments
  file</a>, or, even easier, <a
  href='" . hoturl("autoassign") . "'>automatically</a>.  PC
  review assignments can be “primary” or “secondary”; the difference is
  that primary reviewers are expected to complete their review, but a
  secondary reviewer can choose to delegate their review to someone else.</p>

  <p>The default assignments pages apply to all submitted papers.  You can
  also assign subsets of papers obtained through <a
  href='" . hoturl("help", "t=search") . "'>search</a>, such as <a
  href='" . hoturl("search", "q=cre:%3C3&amp;t=s") . "'>papers
  with fewer than three completed reviews</a>.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=reviews") . "'>Open the site
  for reviewing.</a></strong></p></li>

</ol>");

    _subhead("Chair conflicts", "
<p>Chairs and system administrators can access any information stored in the
conference system, including reviewer identities for conflicted papers.
It is easiest to simply accept such conflicts as a fact of life. Chairs
who can’t handle conflicts fairly shouldn’t be chairs. However, HotCRP
does offer other mechanisms for conflicted reviews.</p>

<p>The key step is to pick a PC member to manage the reviewing and
discussion process for the relevant papers. This PC member is called the
<em>paper administrator</em>. Use the left-hand side of the
<a href='" . hoturl("assign") . "'>paper assignment pages</a> to enter paper administrators. (You may need to
“Override conflicts” to access the assignment page.)
A paper’s administrators have full privilege to assign and view reviews
for that paper, although they cannot change conference settings.</p>

<p>Assigned administrators change conflicted chairs’
access rights. Normally, a conflicted chair can easily override
their conflict. If a paper has an administrator, however, conflicts cannot
be overridden until the administrator is removed.</p>

<p>Paper administrators make life easy for PC reviewers while hiding
conflicts from chairs in most circumstances.
However, determined chairs can still discover reviewer identities
via HotCRP logs, review counts, and mails (and, of course,
by removing the administrator).
For additional privacy, a conference can use
<em>review tokens</em>, which are completely anonymous
review slots. To create a token, an administrator
goes to an <a href='" . hoturl("assign") . "'>assignment page</a>
and clicks on “Request review” without entering a name
or email address. This reports the token, a short string of letters and
numbers such as “9HDZYUB”. Any user who knows the token can
enter it on HotCRP’s home page, after which the system lets them
view the paper and anonymously modify the corresponding “Jane Q. Public”
review. True reviewer identities will not appear in HotCRP’s
database or its logs.
For even more privacy, the paper administrator could collect
offline review forms via email and upload them using
review tokens; then even web server access logs store only the
administrator’s identity.</p>");

    _subhead("Before the meeting", "
<ol>

<li><p><strong><a href='" . hoturl("settings", "group=dec") . "'>Collect
  authors’ responses to the reviews (optional).</a></strong>  Authors’ responses
  (also called rebuttals) let authors correct reviewer misconceptions
  before decisions are made.  Responses are entered
  into the system as comments.  On the <a
  href='" . hoturl("settings", "group=dec") . "'>decision settings page</a>,
  update “Can authors see reviews” and “Collect responses to the
  reviews,” then <a href='" . hoturl("mail") . "'>send mail to
  authors</a> informing them of the response deadline.  PC members can still
  update their reviews up to the <a
  href='" . hoturl("settings", "group=reviews") . "'>review deadline</a>; authors
  are informed via email of any review changes.  At the end of the response
  period you should generally <a
  href='" . hoturl("settings", "group=dec") . "'>turn off “Authors can see
  reviews”</a> so PC members can update their reviews in peace.</p></li>

<li><p>Set <strong><a href='" . hoturl("settings", "group=reviews") . "'>PC can
  see all reviews</a></strong> if you haven’t already, allowing the program
  committee to see reviews and scores for
  non-conflicted papers.  (During most conferences’ review periods, a PC member
  can see a paper’s reviews only after completing their own
  review for that paper.  This supposedly reduces bias.)</p></li>

<li><p><strong><a href='" . hoturl("search", "q=&amp;t=s&amp;sort=50") . "'>Examine
  paper scores</a></strong>, either one at a time or en masse, and decide
  which papers will be discussed.  The <a
  href='" . hoturl("help", "t=tags") . "'>tags</a> system lets you prepare
  discussion sets.  Use <a href='" . hoturl("help", "t=keywords") . "'>search
  keywords</a> to, for example, find all papers with at least two overall
  merit ratings of 2 or better.</p></li>

<li><p><strong>Assign discussion orders using <a
  href='" . hoturl("help", "t=tags") . "'>tags</a></strong> (optional).  Common
  discussion orders include sorted by overall ranking (high-to-low,
  low-to-high, or alternating), sorted by topic, and <a href=\"" .
  hoturl("autoassign", "a=discorder") . "\">grouped by PC conflicts</a>.
  Explicit tag-based orders make it easier for the PC to follow along.</p></li>

<li><p><strong><a href='" . hoturl("autoassign") . "'>Assign discussion leads
  (optional).</a></strong> Discussion leads are expected to be able to
  summarize the paper and the reviews.  You can assign leads either <a
  href='" . hoturl("assign") . "'>paper by paper</a> or <a
  href='" . hoturl("autoassign") . "'>automatically</a>.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=dec") . "'>Define decision
  types (optional).</a></strong> By default, HotCRP has two decision types,
  “accept” and “reject,” but you can add other types of acceptance and
  rejection, such as “accept as short paper.”</p></li>

<li><p>The night before the meeting, <strong><a
  href='" . hoturl("search", "q=&amp;t=s") . "'>download all
  reviews onto a laptop</a></strong> (Download &gt; All reviews) in case the
  Internet explodes and you can’t reach HotCRP from the meeting
  place.</p></li>

</ol>");

    _subhead("At the meeting", "
<ol>

<li><p>The <b>meeting tracker</b> can keep PC members coordinated.
  Search for a discussion order, navigate to the first paper in that
  order, and activate the tracker using the “&#9759;”
  button. From that point on, the paper being viewed by that tab
  is broadcast to all logged-in PC members, along with the next papers
  in the discussion order. You can also view the discussion
  status on the <a href=\"" . hoturl("buzzer") . "\">discussion
  status page</a>.</p></li>

<li><p>Scribes can, if you like, capture discussions as comments for the authors’
  reference.</p></li>

<li><p><strong>Paper decisions</strong> can be recorded on the <a
  href='" . hoturl("review") . "'>paper pages</a> or en masse via <a
  href='" . hoturl("search", "q=&amp;t=s") . "'>search</a>.  Use <a
  href='" . hoturl("settings", "group=dec") . "'>decision settings</a> to expose
  decisions to PC members if desired.</p></li>

<li><p><strong>Shepherding (optional).</strong> If your conference uses
  shepherding for accepted papers, you can assign shepherds either <a
  href='" . hoturl("paper") . "'>paper by paper</a> or <a
  href='" . hoturl("autoassign", "t=acc") . "'>automatically</a>.</p></li>

</ol>", "meeting");

    _subhead("After the meeting", "
<ol>

<li><p><strong><a
  href='" . hoturl("search", "q=&amp;t=s") . "'>Enter
  decisions</a> and <a
  href='" . hoturl("search", "q=dec:yes&amp;t=s") . "'>shepherds</a></strong>
  if you didn’t do this at the meeting.</p></li>

<li><p>Give reviewers some time to <strong>update their reviews</strong> in
  response to PC discussion (optional).</p></li>

<li><p>Set <a href='" . hoturl("settings", "group=dec") . "'>“Who can
  <strong>see decisions?</strong>”</a> to “Authors, PC members,
  and reviewers.”</p></li>

<li><p><strong><a href='" . hoturl("mail") . "'>Send mail to
  authors</a></strong> informing them that reviews and decisions are
  available.  The mail can also contain the reviews and comments
  themselves.</p></li>

<li><p><strong><a href='" . hoturl("settings", "group=dec") . "'>Collect final
  papers (optional).</a></strong> If you’re putting together the program
  yourself, it can be convenient to collect final versions using HotCRP.
  Authors upload final versions just as they did submissions.  You can then <a
  href='" . hoturl("search", "q=dec:yes&amp;t=s") . "'>download
  all final versions as a <code>.zip</code> archive</a>.  (The submitted
  versions are archived for reference.)</p></li>

</ol>");
}



echo '<div class="leftmenu_menucontainer"><div class="leftmenu_list">';
foreach (HelpTopic::$list as $ht) {
    if ($ht->id === $topic)
        echo '<div class="leftmenu_item_on">', $ht->name, '</div>';
    else
        echo '<div class="leftmenu_item">',
            '<a href="', hoturl("help", "t=$ht->id"), '">', $ht->name, '</a></div>';
    if ($ht->id === "topics")
        echo '<div class="c g"></div>';
}
echo "</div></div>\n",
    '<div class="leftmenu_content_container"><div class="leftmenu_content">',
    '<div class="leftmenu_body">';
Ht::stash_script("jQuery(\".leftmenu_item\").click(divclick)");

if ($topic == "topics")
    topics();
else if ($topic == "search")
    search();
else if ($topic == "keywords")
    searchQuickref();
else if ($topic == "tags")
    tags();
else if ($topic == "tracks")
    tracks();
else if ($topic == "revround")
    revround();
else if ($topic == "reviewratings")
    revrate();
else if ($topic == "votetags")
    showvotetags();
else if ($topic == "scoresort")
    scoresort();
else if ($topic == "ranking")
    showranking();
else if ($topic == "formulas")
    showformulas();
else if ($topic == "chair")
    chair();

echo "</div></div></div>\n";


$Conf->footer();
