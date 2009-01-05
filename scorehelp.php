<?php
// scorehelp.php -- HotCRP score help page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();


$Conf->header("Score Information", 'scorehelp', actionBar());

if (($text = $rf->webGuidanceRows($Me->viewReviewFieldsScore(null, null))))
    echo "<table>\n", $text, "<tr class='last'><td class='caption'></td><td class='entry'></td></tr></table>\n";
$Conf->footer();
