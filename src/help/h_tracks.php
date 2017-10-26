<?php
// src/help/h_tracks.php -- HotCRP help functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Tracks_HelpTopic {
    static function render($hth) {
        echo "<p>Tracks give you fine-grained control over PC member rights. With tracks, PC
members can have different rights to see and review papers, depending on the
papers’ " . Ht::link("tags", hoturl("help", "t=tags")) . ".</p>

<p>Set up tracks on the ", $hth->settings_link("Settings &gt; Tracks", "tracks"),
" page.</p>";

        echo $hth->subhead("Example: External review committee");
        echo "<p>An <em>external review committee</em> is a subset of the PC that may bid on
papers to review, and may be assigned reviews (using, for example, the
<a href=\"" . hoturl("autoassign") . "\">autoassignment tool</a>), but may not
self-assign reviews, and may not view reviews except for papers they have
reviewed. To set this up:</p>

<ul>
<li>Give external review committee members the “erc” tag.</li>
<li>On Settings &gt; Tracks, “For papers not on other
tracks,” select “Who can see reviews? &gt; PC members without tag: erc”
and “Who can self-assign a review? &gt; PC members without tag: erc”.</li>
</ul>";

        echo $hth->subhead("Example: PC-paper review committee");
        echo "<p>A <em>PC-paper review committee</em> is a subset of the PC that reviews papers
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

</ul>";

        echo $hth->subhead("Example: Track chair");
        echo "<p>A <em>track chair</em> is a PC member with full administrative
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
site settings or change track tags, however.</p>";

        echo $hth->subhead("Understanding permissions");
        echo "<p>Tracks restrict permissions.
For example, when
the “PC members can review <strong>any</strong> submitted paper”
setting is off, <em>no</em> PC member can enter an unassigned review,
no matter what the track settings say.
It can be useful to “act as” a member of the PC to check which permissions
are actually in effect.</p>";
    }
}
