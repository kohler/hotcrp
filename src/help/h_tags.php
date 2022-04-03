<?php
// help/h_tags.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Tags_HelpTopic {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var HelpRenderer */
    private $hth;

    function __construct(HelpRenderer $hth) {
        $this->conf = $hth->conf;
        $this->user = $hth->user;
        $this->hth = $hth;
    }

    function print_intro() {
        $conflictmsg = "";
        if ($this->user->isPC && !$this->conf->tag_seeall) {
            $conflictmsg = " and conflicted PC members";
        }

        echo "<p>PC members and administrators can attach tag names to papers.
It’s easy to change tags and to list all papers with a given tag,
and <em>ordered</em> tags preserve a particular paper order.
Tags also affect color highlighting in paper lists.</p>

<p>Tags are visible to the PC and hidden from authors$conflictmsg. <em>Twiddle
tags</em>, with names like “#~tag”, are visible only to their creators. Tags
with two twiddles, such as “#~~tag”, are visible only to PC chairs. Tags are
case insensitive, so “#TAG” and “#tAg” are considered identical.</p>";
    }

    function print_finding() {
        $hth = $this->hth;
        echo $hth->subhead("Find tags", "find");
        echo "<p>A paper’s tags are shown like this on the paper page:</p>

<div class=\"pcard-left p c\" style=\"position:static;margin-bottom:1rem\">
<div class=\"pspcard\"><div class=\"psc has-fold foldc\"><div class=\"pst ui js-foldup\">",
    '<span class="psfn"><a href="" class="q ui js-foldup">',
    expander(null, 0), "Tags</a></span></div><div class=\"psv\">",
    '<div class="fn">', $hth->search_link(null, "#earlyaccept", ["class" => "qo pw"]), '</div>',
    '<div class="fx"><textarea cols="20" rows="4" name="tags" class="w-99 want-focus need-suggest tags">earlyaccept</textarea>',
    '<div class="aab flex-row-reverse mt-1"><div class="aabut">',
    Ht::button("Save", ["class" => "btn-primary ui js-foldup"]),
    '</div><div class="aabut">',
    Ht::button("Cancel", ["class" => "ui js-foldup"]),
    '</div></div></div>',
    "</div></div></div></div><hr class=\"c\">

<p>To find all papers with tag “#discuss”:</p>

<div class=\"p\">", $hth->search_form("#discuss"), "</div>

<p>You can also search with “", $hth->search_link("show:tags"), "” to see each
paper’s tags, or “", $hth->search_link("show:#tagname"), "” to see a particular tag
as a column.</p>

<p>Tags are only shown to PC members and administrators. ";
        if ($this->user->isPC) {
            if ($this->conf->tag_seeall) {
                echo "Currently PC members can see tags for any paper, including conflicts.";
            } else {
                echo "They are hidden from conflicted PC members; for instance, if a PC member searches for a tag, the result will never include their conflicts.";
            }
            echo $this->hth->change_setting_link("tag_seeall"), " ";
        }
        echo "</p>";
    }

    function print_changing() {
        $hth = $this->hth;
        echo $hth->subhead("Change tags", "change");
        echo "
<ul>
<li><p><strong>For one paper:</strong> Go to a paper page, click the
“", expander(true), "Tags” expander, and enter tags separated by spaces.</p></li>

<li><p><strong>For many papers:</strong> ", $hth->hotlink("Search", "search"),
" for papers, select them, and use the action area underneath the
search list. <b>Add</b> adds tags to the selected papers, <b>Remove</b> removes
tags from the selected papers, and <b>Define</b> adds the tag to the selected
papers and removes it from all others.</p>

<p>", Ht::img("extagssearch.png", "[Setting tags on the search page]", ["width" => 510, "height" => 94]), "</p></li>

<li><p><strong>With search keywords:</strong> Search for “",
            $hth->search_link("edit:tag:tagname"), "” to add tags with checkboxes;
search for “", $hth->search_link("edit:tagval:tagname"), "” to type in <a
href=\"#values\">tag values</a>; or search for “", $hth->search_link("edit:tags"), "”
to edit papers’ full tag lists.</p>

<p>", Ht::img("extagseditkw.png", "[Tag editing search keywords]", ["width" => 543, "height" => 133]), "</p></li>

<li><p><strong>In bulk:</strong> Administrators can also upload tag
assignments using ", $hth->hotlink("bulk assignment", "bulkassign"), ".</p></li>

</ul>

<p>Although any PC member can view or search
most tags, certain tags may be changed only by administrators",
          $this->hth->current_tag_list("chair"), ".",
          $this->hth->change_setting_link("tag_chair"), "</p>";
    }

    function print_values() {
        $hth = $this->hth;
        echo $hth->subhead("Tag values and discussion orders", "values");
        echo "<p>Tags can have numeric values, as in “#tagname#100”. The
default tag value is 0: “#t#0” is displayed as “#t”. You can search for
specific values with search terms like “", $hth->search_link("#discuss#2"),
"” or “", $hth->search_link("#discuss>1"),
"”. A search like “", $hth->search_link("order:#tagname"), "” selects
papers with the named tag and displays them ordered by that tag’s values.</p>

<p>It’s common to assign increasing tag values to a set of papers.  Do this
using the ", $hth->hotlink("search screen", "search"), ". Search for the
papers you want, sort them into the right order, select their checkboxes, and
choose <b>Define order</b> in the tag action area.  If no sort gives what
you want, search for the desired paper numbers in order—for instance,
“", $hth->search_link("4 1 12 9"), "”—then <b>Select all</b> and <b>Define
order</b>.</p>

<p><b>Define order</b> might assign values “#tag#1”,
“#tag#3”, “#tag#6”, and “#tag#7”
to adjacent papers.  The gaps make it harder to infer
conflicted papers’ positions.  (Any given gap might or might not hold a
conflicted paper.)  The <b>Define gapless order</b> action assigns
strictly sequential values, like “#tag#1”,
“#tag#2”, “#tag#3”, “#tag#4”.
<b>Define order</b> is better for most purposes.</p>

<p>To add new papers at the end of an existing discussion order, use <b>Add to
order</b>. To create an order by entering explicit positions and/or dragging
papers into order, use a search like “", $hth->search_link("editsort:#tagname"), "”.</p>

<p>The ", $hth->hotlink("autoassigner", "autoassign", "a=discorder"), "
has special support for creating discussion orders. It tries to group papers
with similar PC conflicts, which can make the meeting run smoother.</p>";
    }

    function print_colors() {
        $hth = $this->hth;
        echo $hth->subhead("Colors", "colors");
        echo "<p>Tags “red”, “orange”, “yellow”, “green”, “blue”, “purple”, “gray”, and
“white” act as highlight colors. For example, papers tagged with “#red” will
appear <span class=\"redtag tagbg\">red</span> in paper lists (for people
who can see that tag).  Tag a paper “#~red” to make it red only on your display.
Other styles are available; try “#bold”, “#italic”, “#big”, “#small”, and
“#dim”. The ", $hth->setting_link("settings page", "tag_color"), " can associate other tags
with colors so that, for example, “" . $hth->search_link("#reject") . "” papers appear
gray.</p>\n";
    }

    function print_examples() {
        echo $this->hth->subhead("Examples");
        echo "<p>Here are some common ways tags are used.</p>\n";
        $this->hth->print_group("tagexamples");
    }

    function print_example_r1reject() {
        echo "<p><strong>Skip low-ranked submissions.</strong> Mark
low-ranked submissions with tag “#r1reject”, then ask the PC to " .
$this->hth->search_link("search for “#r1reject”", "#r1reject") . ". PC members
can check the list for papers they’d like to discuss anyway. They can email
the chairs about such papers, or remove the tag themselves. (You might make
the “#r1reject” tag chair-only so an evil PC member couldn’t add it to a
high-ranked paper, but it’s usually better to trust the PC.)</p>\n";
    }

    function print_example_controversial() {
        echo "<p><strong>Mark controversial papers that would benefit from additional review.</strong>
 PC members could add the “#controversial” tag when the current reviewers disagree.
 A ", $this->hth->hotlink("search", "search", ["q" => "#controversial"]),
    " shows where the PC thinks more review is needed.</p>\n";
    }

    function print_example_pcpaper() {
        echo "<p><strong>Mark PC-authored papers for extra scrutiny.</strong>
 First, ", $this->hth->hotlink("search for PC members’ last names in author fields", "search", "t=s&amp;qt=au"), ".
 Check for accidental matches and select the papers with PC members as authors, then use the action area below the search list to add the tag “#pcpaper”.
 A ", $this->hth->hotlink("search", "search", "t=s&amp;q=-%23pcpaper"), " shows papers without PC authors.</p>\n";
    }

    function print_example_allotment() {
        $vt = $this->hth->example_tag("allotment");
        echo "<p><strong>Vote for papers.</strong>
 The chair can define tags used for allotment voting", $this->hth->current_tag_list("allotment"), ".",
            $this->hth->change_setting_link("tag_vote"),
            " Each PC member is assigned an allotment of votes to distribute among papers.
 For instance, if “#{$vt}” were a voting tag with an allotment of 10, then a PC member could assign 5 votes to a paper by adding the twiddle tag “#~{$vt}#5”.
 The system automatically sums PC members’ votes into the public “#{$vt}” tag.
 To search for papers by vote count, search for “", $this->hth->search_link("rorder:#$vt"),
    "”. (", $this->hth->help_link("votetags"), ")</p>\n";
    }

    function print_example_rank() {
        echo "<p><strong>Rank papers.</strong>
 Each PC member can set tags indicating their preference ranking for papers.
 For instance, a PC member’s favorite paper would get tag “#~rank#1”, the next favorite “#~rank#2”, and so forth.
 The chair can then combine these rankings into a global preference order using a Condorcet method.
 (", $this->hth->help_link("ranking"), ")</p>\n";
    }

    function print_example_discuss() {
        echo "<p><strong>Define a discussion order.</strong>
Publishing the order lets PC members prepare to discuss upcoming papers.
Define an ordered tag such as “#discuss”, then ask the PC to ", $this->hth->search_link("search for “order:#discuss”", "order:#discuss"), ".
The PC can now see the order and use quick links to go from paper to paper.";
        if ($this->user->isPC && !$this->conf->tag_seeall) {
            echo " However, since PC members can’t see tags for conflicted papers, each PC member might see a different list.", $this->hth->change_setting_link("tag_seeall");
        }
        echo "</p>\n";
    }

    function print_example_decisions() {
        echo "<p><strong>Mark tentative decisions during the PC meeting</strong> using
“#accept” and “#reject” tags, or mark more granular decisions with tags like “#revisit”
or “#exciting” or “#boring”. After the meeting, use ", $this->hth->search_link("Search", "#accept"),
" &gt; Decide to mark the final decisions. (Or just use the per-paper decision selectors.)</p>\n";
    }
}
