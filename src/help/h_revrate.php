<?php
// src/help/h_revrate.php -- HotCRP help functions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class RevRate_HelpTopic {
    static function render($hth) {
        echo "<p>PC members and, optionally, external reviewers can rate one another’s
reviews.  We hope this feedback will help reviewers improve the quality of
their reviews.  The interface appears above each visible review:</p>

<p><div class='rev_rating'>
  How helpful is this review? &nbsp;<form class><div class=\"inline\">",
            Ht::select("rating", ReviewInfo::$rating_options, "n"),
            "</div></form>
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

<p>To find which of your reviews might need work, simply ",
$hth->search_link("search for “rate:bad”", "rate:bad"), ".
To find all reviews with positive ratings, ",
$hth->search_link("search for “re:any&nbsp;rate:good”", "re:any rate:good"), ".
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
            $hth->settings_link("review settings page", "reviews"), ".",
            ($hth->user->is_reviewer() ? " Currently, $what can rate reviews." : ""), "</p>";

        echo $hth->subhead("Visibility");
        echo "<p>A review’s ratings are visible to any unconflicted PC members who can see
the review, but HotCRP tries to hide ratings from review authors if they
could figure out who assigned the rating: if only one PC member could
rate a review, then that PC member’s rating is hidden from the review
author.</p>";
    }
}
