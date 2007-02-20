<?php
// pc.php -- HotCRP program committee listing/editing page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/contactlist.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();

$Conf->header("Program Committee", "pc", actionBar());

if ($Me->amAssistant())
    $Conf->infoMsg("Click on a PC member's name to edit their information or remove them from the PC.");

// form
echo "<div class='xsmgap'></div>\n";

$pl = new ContactList(true);
echo $pl->text("pc", $Me, "${ConfSiteBase}pc.php");

$Conf->footer();
