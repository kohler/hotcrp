<?php
// help/h_revround.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class RevRound_HelpTopic {
    static function print(HelpRenderer $hth) {
        echo "<p>Many conferences divide their review assignments into named <em>rounds</em>,
such as “R1” or “lastround”.
(We suggest very short names like “R1”.)
Different review rounds can have different deadlines and can even have different fields on their review forms.
To search for any paper with a round “R2” review assignment, ",
$hth->search_link("search for “re:R2”", "re:R2"), ".
To list a PC member’s round “R1” review assignments, ",
$hth->search_link("search for “re:membername:R1”", "re:membername:R1"), ".</p>

<p>Administrators can configure review rounds on ", $hth->setting_link("Settings &gt; Reviews", "review"), ".
To configure per-round review fields, first configure
rounds, then see ", $hth->setting_link("Settings &gt; Review form", "rf"), ".</p>";


        echo $hth->subhead("Assigning rounds");
        echo "<p>New assignments are marked by default with the round defined in ",
            $hth->setting_link("review settings", "review_default_round_index"), ".
The automatic and bulk assignment pages also let you set a review round.</p>";


        // get current tag settings
        if ($hth->user->isPC) {
            $texts = [];
            if (($rr = $hth->conf->assignment_round_option(false)) !== "unnamed") {
                $texts[] = "The review round for new assignments is “"
                    . $hth->search_link(htmlspecialchars($rr), "re:{$rr}") . "”."
                    . $hth->change_setting_link("review_default_round_index");
            }
            $rounds = [];
            if ($hth->conf->has_rounds()) {
                $result = $hth->conf->qe("select distinct reviewRound from PaperReview");
                $has_unnamed = false;
                while (($row = $result->fetch_row())) {
                    if (($rname = $hth->conf->round_name((int) $row[0]))) {
                        $rounds[] = "“" . $hth->search_link(htmlspecialchars($rname), "re:$rname") . "”";
                    } else {
                        $has_unnamed = true;
                    }
                }
                sort($rounds);
                if ($has_unnamed) {
                    $rounds[] = $hth->search_link("unnamed", "re:unnamed");
                }
            }
            if (count($rounds)) {
                $texts[] = "Review rounds currently in use: " . commajoin($rounds) . ".";
            } else if (!count($texts)) {
                $texts[] = "So far no review rounds have been defined.";
            }
            echo $hth->subhead("Round status");
            echo "<p>", join(" ", $texts), "</p>\n";
        }
    }
}
