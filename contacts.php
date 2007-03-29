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


// form
echo "<div class='xsmgap'></div>\n";
if (count($tOpt) > 1) {
    echo "<form method='get' action='contacts.php'>";
    if (isset($_REQUEST["sort"]))
	echo "<input type='hidden' name='sort' value=\"", htmlspecialchars($_REQUEST["sort"]), "\" />";
    echo "<strong>Show:</strong> &nbsp;<select name='t'>";
    foreach ($tOpt as $k => $v) {
	echo "<option value='$k'";
	if ($_REQUEST["t"] == $k)
	    echo " selected='selected'";
	echo ">$v</option>";
}
    echo "</select> &nbsp;<input class='button' type='submit' value='Go' /></form>
<div class='smgap'></div>\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='${ConfSiteBase}account.php?new=1&amp;pc=1' class='button'>Add PC member</a></p><p>Click on a PC member's name to edit their information or remove them from the PC.</p>");


$pl = new ContactList(true);
echo $pl->text($_REQUEST["t"], $Me, "${ConfSiteBase}contacts.php?t=" . $_REQUEST["t"]);

$Conf->footer();
