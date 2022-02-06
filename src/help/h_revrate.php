<?php
// help/h_revrate.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class RevRate_HelpTopic {
    static function print(HelpRenderer $hth) {
        $what = "PC members";
        if ($hth->conf->setting("rev_ratings") == REV_RATINGS_PC_EXTERNAL)
            $what = "PC members and external reviewers";
        echo "<p>{$what} can anonymously rate one another’s
reviews. We hope this feedback will help reviewers improve the quality of
their reviews.</p>

<p>When rating a review, please consider its value for both the program
  committee and the authors.  Helpful reviews are specific, clear, technically
  focused, and provide direction for the authors’ future work.
  The rating options are:</p>

<ul class=\"x\">
<li><strong>Good review</strong>: Thorough, clear, constructive, and gives good
  ideas for next steps.</li>
<li><strong>Needs work</strong>: The review needs revision. If possible,
  indicate why using a more-specific rating.</li>
<li><strong>Too short</strong>: The review is incomplete or too terse.</li>
<li><strong>Too vague</strong>: The review has weak or unconvincing arguments or gives little useful direction.</li>
<li><strong>Too narrow</strong>: The review’s perspective seems limited; for
  instance, it might overly privilege the reviewer’s own work.</li>
<li><strong>Disrespectful</strong>: The review’s tone is unnecessarily aggressive or exhibits bias.</li>
<li><strong>Not correct</strong>: The review misunderstands the paper.</li>
</ul>

<p>HotCRP reports aggregate ratings for each review.
  It does not report who gave the ratings, and it
  never shows review ratings to authors.</p>

<p>To find which of your reviews might need work, simply ",
$hth->search_link("search for “rate:bad:me”", "rate:bad:me"), ".
To find all reviews with positive ratings, ",
$hth->search_link("search for “rate:good”", "rate:good"), ".
You may also search for reviews with specific ratings; for instance, ",
$hth->search_link("search for “rate:short”", "rate:short"), ".</p>";

        if ($hth->conf->setting("rev_ratings") == REV_RATINGS_PC)
            $what = "only PC members";
        else if ($hth->conf->setting("rev_ratings") == REV_RATINGS_PC_EXTERNAL)
            $what = "PC members and external reviewers";
        else
            $what = "no one";
        echo $hth->subhead("Settings");
        echo "<p>Chairs set how ratings work on the ",
            $hth->setting_link("review settings page", "rev_ratings"), ".",
            ($hth->user->is_reviewer() ? " Currently, $what can rate reviews." : ""), "</p>";

        echo $hth->subhead("Visibility");
        echo "<p>A review’s ratings are visible to any unconflicted PC members who can see
the review, but HotCRP tries to hide ratings from review authors if they
could figure out who assigned the rating: if only one PC member could
rate a review, then that PC member’s rating is hidden from the review
author.</p>";
    }
}
