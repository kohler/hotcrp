<?php
// scorehelp.php -- HotCRP score help page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
$rf = reviewForm();


$Conf->header("Score Information", 'scorehelp', actionBar());

if (($text = $rf->webGuidanceRows($Me->viewReviewFieldsScore(null, null))))
    echo "<table>\n", $text, "<tr class='last'><td class='caption'></td><td class='entry'></td></tr></table>\n";
$Conf->footer();
