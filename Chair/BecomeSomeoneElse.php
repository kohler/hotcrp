<?php 
// Chair/BecomeSomeoneElse.php -- HotCRP page for logging in as someone else
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPrivChair("../");

$Conf->header("Switch Roles");

echo "<form method='get' action='$ConfSiteBase'>
<select name='viewContact'>\n";

$result = $Conf->qe("select contactId, firstName, lastName, email,
	Chair.contactId as chair, ChairAssistant.contactId as ass,
	PCMember.contactId as pc
	from ContactInfo
	left join Chair using (contactId)
	left join ChairAssistant using (contactId)
	left join PCMember using (contactId)
	order by chair desc, ass desc, pc desc, lastName, firstName, email");
$oldtype = "";
while (($row = edb_orow($result))) {
    $type = ($row->chair ? "PC Chair" : ($row->ass ? "System Administrator" : ($row->pc ? "PC Member" : "Others")));
    if ($type != $oldtype)
	echo ($oldtype ? "</optgroup>" : ""), "<optgroup label=\"$type\">\n";
    echo "<option value='$row->contactId'>", contactHtml($row), "</option>\n";
    $oldtype = $type;
}

echo "</optgroup></select>&nbsp;
<input class='button_default' type='submit' name='go' value='Become contact' />
</form>";

$Conf->footer();
