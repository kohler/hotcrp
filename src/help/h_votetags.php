<?php
// src/help/h_votetags.php -- HotCRP help functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class VoteTags_HelpTopic {
    static function render($hth) {
        $votetag = $hth->example_tag("vote");
        echo "<p>Some conferences have PC members vote for papers.
Each PC member is assigned a vote allotment, and can distribute that allotment
arbitrarily among unconflicted papers.
Alternately, each PC member can vote, once, for as many papers as they like (“approval voting”).
The PC’s aggregated vote totals might help determine
which papers to discuss.</p>

<p>HotCRP supports voting through ", $hth->help_link("tags", "tags"), ".
The chair can ", $hth->settings_link("define a set of voting tags", "tags"),
" and allotments" . $hth->current_tag_list("vote") . ".
PC members vote by assigning the corresponding twiddle tags;
the aggregated PC vote is visible in the public tag.</p>

<p>For example, assume that an administrator defines a voting tag
 “". $votetag . "” with an allotment of 10.
To use two votes for a paper, a PC member tags the paper as
“~". $votetag . "#2”. The system
automatically adds the tag “". $votetag . "#2” to that
paper (note the
lack of the “~”), indicating that the paper has two total votes.
As other PC members add their votes with their own “~” tags, the system
updates the main tag to reflect the total.
(The system ensures no PC member exceeds their allotment.) </p>

<p>
To see the current voting status, search by
<a href=\"" . hoturl("search", "q=rorder:" . $votetag . "") . "\">
rorder:". $votetag . "</a>. Use view options to show tags
in the search results (or set up a
<a href='" . hoturl("help", "t=formulas") . "'>formula</a>).
</p>

<p>
Hover to learn how the PC voted:</p>

<p>" . Ht::img("extagvotehover.png", "[Hovering over a voting tag]", ["width" => 390, "height" => 46]) . "</p>";
    }
}
