<?php
// help/h_ranking.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Ranking_HelpTopic {
    static function print(HelpRenderer $hth) {
        echo "<p>Paper ranking is a way to extract the PC’s preference order for
submitted papers.  Each PC member ranks the submitted papers, and a voting
algorithm, <a href=\"http://en.wikipedia.org/wiki/Schulze_method\">the Schulze
method</a> by default, combines these rankings into a global preference order.</p>

<p>HotCRP supports ranking through ", $hth->help_link("tags", "tags"), ". The chair chooses
a tag for ranking—“rank” is a good default—and enters it on ",
$hth->setting_link("the settings page", "tag_rank"), ".
PC members then rank papers using their private versions of this tag,
tagging their first preference with “~rank#1”,
their second preference with “~rank#2”,
and so forth.  To combine PC rankings into a global preference order, the PC
chair selects all papers on ", $hth->search_link("the search page", ""),
" and chooses Tags &gt; Calculate&nbsp;rank, entering
“rank” for the tag.  At that point, the global rank can be viewed
by ", $hth->search_link("searching for “order:rank”", "order:rank"), ".</p>

<p>PC members can enter rankings by reordering rows in a paper list.
For example, for rank tag “rank”, PC members should ",
$hth->search_link("search for “editsort:#~rank”", "editsort:#~rank"), ".
Ranks can be entered directly in the text fields, or the rows can be dragged
into position using the dotted areas on the right-hand side of the list.</p>

<p>Alternately, PC members can use an ", $hth->hotlink("offline ranking form", "offline"),
". Download a ranking file, rearrange the lines to create a
rank, and upload the form again.  For example, here is an initial ranking
file:</p>

<pre class=\"entryexample\">
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


X       1       Write-Back Caches Considered Harmful
X       2       Deconstructing Suffix Trees
X       4       Deploying Congestion Control Using Homogeneous Modalities
X       5       The Effect of Collaborative Epistemologies on Theory
X       6       The Influence of Probabilistic Methodologies on Networking
X       8       Rooter: A Methodology for the Typical Unification of Access Points and Redundancy
X       10      Decoupling Lambda Calculus from 802.11 Mesh Networks in Moore's Law
X       11      Analyzing Scatter/Gather I/O Using Encrypted Epistemologies
</pre>

<p>The user might edit the file as follows:</p>

<pre class=\"entryexample\">
        8       Rooter: A Methodology for the Typical Unification of Access Points and Redundancy
        5       The Effect of Collaborative Epistemologies on Theory
=       1       Write-Back Caches Considered Harmful
        2       Deconstructing Suffix Trees
>>      4       Deploying Congestion Control Using Homogeneous Modalities

X       6       The Influence of Probabilistic Methodologies on Networking
X       10      Decoupling Lambda Calculus from 802.11 Mesh Networks in Moore's Law
X       11      Analyzing Scatter/Gather I/O Using Encrypted Epistemologies
</pre>

<p>Uploading this file produces the following ranking:</p>

<p><table><tr><th class=\"pad\">ID</th><th>Title</th><th>Rank tag</th></tr>
<tr><td class=\"pad\">#8</td><td class=\"pad\">Rooter: A Methodology for the Typical Unification of Access Points and Redundancy</td><td class=\"pad\">~rank#1</td></tr>
<tr><td class=\"pad\">#5</td><td class=\"pad\">The Effect of Collaborative Epistemologies on Theory</td><td class=\"pad\">~rank#2</td></tr>
<tr><td class=\"pad\">#1</td><td class=\"pad\">Write-Back Caches Considered Harmful</td><td class=\"pad\">~rank#2</td></tr>
<tr><td class=\"pad\">#2</td><td class=\"pad\">Deconstructing Suffix Trees</td><td class=\"pad\">~rank#3</td></tr>
<tr><td class=\"pad\">#4</td><td class=\"pad\">Deploying Congestion Control Using Homogeneous Modalities</td><td class=\"pad\">~rank#5</td></tr></table></p>

<p>Since #6, #10, and #11 still had X prefixes, they were not assigned a rank.
 Searching for “order:~rank” returns the user’s personal ranking;
 administrators can search for
 “order:<i>pcname</i>~rank” to see a PC member’s ranking.
 Once a global ranking is assigned, “order:rank” will show it.</p>";
    }
}
