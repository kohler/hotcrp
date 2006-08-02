<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"]->goIfInvalid("../index.php");
$_SESSION["Me"]->goIfNotChair('../index.php');
$Conf->connect();

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
if (!DB::isError($result)) {
    $oldtype = "";
    while (($row = $result->fetchRow(DB_FETCHMODE_OBJECT))) {
	$type = ($row->chair ? "PC Chair" : ($row->ass ? "PC Chair's Assistant" : ($row->pc ? "PC Member" : "Others")));
	if ($type != $oldtype)
	    echo "<option value='-1' disabled='disabled'>", $type, "</option>\n";
	echo "<option value='$row->contactId'>&nbsp;&nbsp;", contactText($row), "</option>\n";
	$oldtype = $type;
    }
}

echo "</select>
<input class='button_default' type='submit' name='go' value='Become contact' />
</form>";

$Conf->footer() ?>
