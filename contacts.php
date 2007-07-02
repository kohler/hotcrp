<?php
// contacts.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/contactlist.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();

// list type
$tOpt = array();
$tOpt["pc"] = "Program committee";
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if ($Me->privChair || ($Me->isPC && !$Conf->blindSubmission()))
    $tOpt["au"] = "Contact authors of submitted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->timeReviewerViewDecision()))
    $tOpt["auacc"] = "Contact authors of accepted papers";
if ($Me->privChair
    || ($Me->isPC && !$Conf->blindSubmission() && $Conf->timeReviewerViewDecision()))
    $tOpt["aurej"] = "Contact authors of rejected papers";
if ($Me->privChair) {
    $tOpt["auuns"] = "Contact authors of non-submitted papers";
    $tOpt["all"] = "All accounts";
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren't allowed to list those accounts.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


$title = ($_REQUEST["t"] == "pc" ? "Program Committee" : "Account Listing");
$Conf->header($title, "accounts", actionBar());


$pl = new ContactList(true);
$pl_text = $pl->text($_REQUEST["t"], $Me, "${ConfSiteBase}contacts.php?t=" . $_REQUEST["t"]);


// form
echo "<div class='xsmgap'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='searchform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";
    
    echo "<form method='get' action='contacts.php'>";
    if (isset($_REQUEST["sort"]))
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />";
    echo "<select name='t'>";
    foreach ($tOpt as $k => $v) {
	echo "<option value='$k'";
	if ($_REQUEST["t"] == $k)
	    echo " selected='selected'";
	echo ">$v</option>";
}
    echo "</select> &nbsp;<input class='button' type='submit' value='Show' /></form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo "<form method='get' action='contacts.php'>\n";
    foreach (array("t", "sort") as $x)
	if (isset($_REQUEST[$x]))
	    echo "<input type='hidden' name='$x' value=\"", htmlspecialchars($_REQUEST[$x]), "\" />\n";

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    if ($pl->haveAffrow) {
	echo "<input type='checkbox' name='showaff' value='1'";
	echo " onclick='fold(\"ppl\",!this.checked,2)' />&nbsp;Affiliations<br />\n";
    }
    if ($pl->haveTopics) {
	echo "<input type='checkbox' name='showtop' value='1'";
	echo " onclick='fold(\"ppl\",!this.checked,1)' />&nbsp;Topic interests<br />\n";
    }
    echo "</td>";
    if (isset($pl->scoreMax)) {
	echo "<td class='pad'>";
	$rf = reviewForm();
	$theScores = defval($_SESSION["scores"], 1);
	$seeAllScores = ($Me->amReviewer() && $_REQUEST["t"] != "a");
	for ($i = 0; $i < ContactList::FIELD_NUMSCORES; $i++) {
	    $score = $reviewScoreNames[$i];
	    if (in_array($score, $rf->fieldOrder)
		&& ($seeAllScores || $rf->authorView[$score] > 0)) {
		echo "<input type='checkbox' name='score[]' value='$i' ";
		if ($theScores & (1 << $i))
		    echo "checked='checked' ";
		echo "/>&nbsp;" . htmlspecialchars($rf->shortName[$score]) . "<br />";
	    }
	}
	echo "</td>";
    }
    echo "<td><input class='button' type='submit' value='Redisplay' /></td></tr>\n";
    if (isset($pl->scoreMax)) {
	echo "<tr><td colspan='3'><div class='smgap'></div><b>Sort scores by:</b> &nbsp;<select name='scoresort'>";
	foreach (array("Average", "Variance", "Max &minus; min") as $k => $v) {
	    echo "<option value='", $k + 1, "'";
	    if (defval($_SESSION["scoresort"], 1) == $k + 1)
		echo " selected='selected'";
	    echo ">$v</option>";
	}
	echo "</select></td></tr>";
    }
    echo "</table></form>";
    
    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a onclick='return tablink(\"searchform\", 1)' href=''>Account types</a></div></td>
  <td><div class='tll2'><a onclick='return tablink(\"searchform\", 2)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='${ConfSiteBase}account.php?new=1&amp;pc=1' class='button'>Add PC member</a></p><p>Click on a PC member's name to edit their information or remove them from the PC.</p>");


echo $pl_text;


$Conf->footer();
