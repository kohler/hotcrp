<?php
// src/help/h_revround.php -- HotCRP help functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class RevRound_HelpTopic {
    static function render($hth) {
        echo "<p>Many conferences divide their review assignments into multiple <em>rounds</em>.
Each round is given a name, such as “R1” or “lastround.”
(We suggest very short names like “R1”.)
Configure rounds on the ", $hth->settings_link("settings page", ["group" => "reviews", "anchor" => "rounds"]), ".
To search for any paper with a round “R2” review assignment, ",
$hth->search_link("search for “re:R2”", "re:R2"), ".
To list a PC member’s round “R1” review assignments, ",
$hth->search_link("search for “re:membername:R1”", "re:membername:R1"), ".</p>

<p>Different rounds usually share the same review form, but you can also
mark review fields as appearing only in certain rounds. First configure
rounds, then see ", $hth->settings_link("Settings &gt; Review form", "reviewform"), ".</p>";


        echo $hth->subhead("Assigning rounds");
        echo "<p>New assignments are marked by default with the round defined in ",
$hth->settings_link("review settings", "reviews"), ".
The automatic and bulk assignment pages also let you set a review round.</p>";


        // get current tag settings
        if ($hth->user->isPC) {
            $texts = array();
            if (($rr = $hth->conf->assignment_round_name(false))) {
                $texts[] = "The review round for new assignments is “"
                    . $hth->search_link(htmlspecialchars($rr), "round:$rr") . "”."
                    . $hth->settings_link("reviews");
            }
            $rounds = array();
            if ($hth->conf->has_rounds()) {
                $result = $hth->conf->qe("select distinct reviewRound from PaperReview");
                while (($row = edb_row($result)))
                    if ($row[0] && ($rname = $hth->conf->round_name($row[0])))
                        $rounds[] = "“" . $hth->search_link(htmlspecialchars($rname), "round:$rname") . "”";
                sort($rounds);
            }
            if (count($rounds))
                $texts[] = "Review rounds currently in use: " . commajoin($rounds) . ".";
            else if (!count($texts))
                $texts[] = "So far no review rounds have been defined.";
            echo $hth->subhead("Round status");
            echo "<p>", join(" ", $texts), "</p>\n";
        }
    }
}
