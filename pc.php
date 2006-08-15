<?php
require_once('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$rf = reviewForm();

$Conf->header_head("Program Committee");

if ($Me->amAssistant()) {
?>
<script type="text/javascript">
function highlightChange(id) {
    highlightUpdate();
    if (id) {
	var chg = document.getElementById("chg" + id);
	if (chg.value == "")
	    chg.value = "chg";
    }
}
function doRemove(id) {
    var but = document.getElementById("rem" + id);
    var chg = document.getElementById("chg" + id);
    var row = document.getElementById("pcrow" + id);
    var rem = (but.value == "Remove from PC");
    chg.value = (rem ? "rem" : "chg");
    var x = row.className.replace(/ *removed/, '');
    row.className = x + (rem ? " removed" : "");
    but.value = (rem ? "Do not remove" : "Remove from PC");
    highlightChange(id);
}
</script>
<?php }

$Conf->header("Program Committee");

function updateRoles($id, $suff, $email) {
    global $Conf;
    // successfully looked up member, now make them a PC member
    $Conf->qe("delete from PCMember where contactId=$id");
    $Conf->qe("delete from ChairAssistant where contactId=$id");
    $Conf->qe("delete from Chair where contactId=$id");
    if ((isset($_REQUEST["ass$suff"]) && $_REQUEST["ass$suff"])
	|| (isset($_REQUEST["chair$suff"]) && $_REQUEST["chair$suff"]))
	$_REQUEST["pc"] = 1;
    foreach (array("pc$suff" => "PCMember", "ass$suff" => "ChairAssistant", "chair$suff" => "Chair") as $key => $table)
	if (isset($_REQUEST[$key]) && $_REQUEST[$key] > 0) {
	    $result = $Conf->qe("insert into $table set contactId='$id'");
	    if (!DB::isError($result))
		$Conf->log("Added $email as $table", $_SESSION["Me"]);
	}
}

if (isset($_REQUEST["update"]) && $Me->amAssistant()) {
    // Add potential new member
    $email = vtrim($_REQUEST['email']);
    if ($email == '' && (vtrim($_REQUEST['name']) != ''
			 || vtrim($_REQUEST['affiliation']) != ''))
	$Conf->errorMsg("Email address required for new members."); 
    if ($email != '') {
	// Make sure email address exists in system
	$id = $Conf->getContactId($email, true);

	// successfully looked up member, now make them a PC member
	if ($id > 0)
	    updateRoles($id, "new", $email);

	// unset values
	foreach (array('name', 'email', 'affiliation', 'pcnew', 'assnew', 'chairnew') as $what)
	    unset($_REQUEST[$what]);
    }

    // Now, update existing members
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 'c' && $key[1] == 'h' && $key[2] == 'g'
	    && ($id = cvtint(substr($key, 3))) > 0 && $id != $Me->contactId) {
	    // remove?
	    if ($value == "rem") {
		unset($_REQUEST["pc$id"], $_REQUEST["ass$id"], $_REQUEST["chair$id"]);
		$log = "Removed someone";
	    } else {
		$_REQUEST["pc$id"] = 1;
		$log = "Changed someone's status";
	    }
	    updateRoles($id, $id, "ID $id");
	}
} else if (isset($_REQUEST["update"]))
    $Conf->errorMsg("You don't have permission to update the PC.");

function addedvalue($what, $checked) {
    if (isset($_REQUEST[$what])) {
	if ($checked)
	    return "checked='checked' ";
	else
	    return "value=\"" . htmlspecialchars($_REQUEST[$what]) . "\" ";
    } else
	return "";
}

$query = "select ContactInfo.contactId, ContactInfo.firstName,
	ContactInfo.lastName, ContactInfo.email, ContactInfo.affiliation,
	ContactInfo.collaborators,
	group_concat(topicId) as topicIds,
	group_concat(interest) as topicInterest,
	ChairAssistant.contactId as ass, Chair.contactId as chair
	from ContactInfo
	join PCMember using (contactId)
	left join ChairAssistant using (contactId)
	left join Chair using (contactId)
	left join TopicInterest on (TopicInterest.contactId=ContactInfo.contactId and TopicInterest.interest<>1)
	left join TopicArea using (topicId)
	group by ContactInfo.contactId
	order by ContactInfo.lastName";
$result = $Conf->qe($query, "while fetching program committee");
if (DB::isError($result))
    $Conf->errorMsgExit("");


// form
if ($Me->amAssistant())
    echo "<form method='post' action='pc.php?post=1'>\n";

echo "<table class='memberlist'>

<tr>
  <th>Name</th>\n";
if ($Me->isPC)
    echo "  <th>Email</th>\n";
echo "  <th>Affiliation</th>\n";
if ($Me->amAssistant())
    echo "  <th>Chair?</th>
  <th>Assistant?</th>
  <th>Actions</th>\n";
else
    echo "  <th>Role</th>\n";
echo "</tr>\n\n";

$ncol = ($Me->amAssistant() ? 6 : ($Me->isPC ? 4 : 3));


while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
    $id = $row->contactId;

    // main row
    echo "<tr id='pcrow$id' class='pcrow'>
  <td class='pc_name'>", htmlspecialchars(trim("$row->firstName $row->lastName")), "</td>\n";
    if ($Me->isPC)
	echo "  <td class='pc_email'><a href=\"mailto:", htmlspecialchars($row->email), "\">", htmlspecialchars($row->email), "</a></td>\n";
    echo "  <td class='pc_aff'>", htmlspecialchars($row->affiliation), "</td>\n";
    if ($Me->amAssistant()) {
	echo "  <td class='pc_chairbox'><input type='checkbox' name='chair$id' value='1'";
	if ($row->chair)
	    echo " checked='checked'";
	if ($id == $Me->contactId)
	    echo " disabled='disabled'";
	echo " onclick='highlightChange($id)' /></td>
  <td class='pc_assbox'><input type='checkbox' name='ass$id' value='1'";
	if ($row->ass)
	    echo " checked='checked'";
	if ($id == $Me->contactId)
	    echo " disabled='disabled'";
	echo " onclick='highlightChange($id)' /></td>\n";
	if ($id != $Me->contactId)
	    echo "  <td class='pc_action'><input class='button_small' type='button' value='Remove from PC' name='rem$id' id='rem$id' onclick='doRemove($id)' />
    <input type='hidden' value='' name='chg$id' id='chg$id' /></td>\n";
    } else
	echo "  <td class='pc_role'>", ($row->chair ? "PC Chair" : ($row->ass ? "PC Chair's Assistant" : "PC member")), "</td>\n";
    echo "</tr>\n";

    // collaborators
    if ($Me->isPC && $row->collaborators && strtolower($row->collaborators) != "none" && $row->collaborators != "-")
	echo "<tr>\n  <td class='pl_callout' colspan='$ncol'><span class='pl_callouthdr'>Collaborators</span> ", authorTable($row->collaborators), "</td>\n</tr>\n";

    // topics
    if ($Me->isPC && $row->topicIds) {
	echo "<tr>\n  <td class='pl_callout' colspan='$ncol'><span class='pl_callouthdr'>Topic interest</span> ";
	echo join(", ", $rf->webTopicArray($row->topicIds, defval($row->topicInterest))), "</td>\n</tr>\n";
    }
    
    echo "\n";
}


if ($Me->amAssistant())
    echo "<tr class='pcrow'>
  <td class='pc_name'><input class='textlite' type='text' name='name' size='20' onchange='highlightUpdate()' ", addedvalue('name', 0), "/></td>
  <td class='pc_email'><input class='textlite' type='text' name='email' size='20' onchange='highlightUpdate()' ", addedvalue('email', 0), "/></td>
  <td class='pc_aff'><input class='textlite' type='text' name='affiliation' size='10' onchange='highlightUpdate()' ", addedvalue('affiliation', 0), "/></td>
  <td class='pc_chairbox'><input type='checkbox' name='chairnew' value='1' ", addedvalue('nchair', 1), "/></td>
  <td class='pc_assbox'><input type='checkbox' name='assnew' value='1' ", addedvalue('nass', 1), "/></td>
  <td class='pc_action'><input type='hidden' name='pcnew' value='1' />Enter new member here</td>
</tr>

<tr>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class='pc_action'><input class='button' type='submit' value='Save changes' name='update' /></td>
</tr>\n\n";

echo "</table>\n";
if ($Me->amAssistant())
    echo "</form>\n\n";

$Conf->footer();
?>
