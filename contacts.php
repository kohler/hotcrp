<?php
// contacts.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/contactlist.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC("index.php");
$rf = reviewForm();

$Conf->header("Account Listing", "pc", actionBar());

// form
echo "<div class='xsmgap'></div>\n";

// list type
$tOpt = array();
$tOpt["pc"] = "Program committee";
if ($Me->amAssistant() || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    $Conf->errorMsg("You aren't allowed to list those accounts.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);

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
echo "</select> &nbsp;<input class='button' type='submit' value='Go' /></form>\n";



$pl = new ContactList(true);
echo $pl->text($_REQUEST["t"], $Me, "${ConfSiteBase}contacts.php");

$Conf->footer();
