<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair("../");

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
    $type = ($row->chair ? "PC Chair" : ($row->ass ? "PC Chair's Assistant" : ($row->pc ? "PC Member" : "Others")));
    if ($type != $oldtype)
	echo "<option value='-1' disabled='disabled'>", $type, "</option>\n";
    echo "<option value='$row->contactId'>&nbsp;&nbsp;", contactHtml($row), "</option>\n";
    $oldtype = $type;
}

echo "</select>
<input class='button_default' type='submit' name='go' value='Become contact' />
</form>";

$Conf->footer();
