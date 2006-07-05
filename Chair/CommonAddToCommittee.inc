<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
$Conf->header_head("Manage $AddWhat");
?>
<script type="text/javascript"><!--
function highlightUpdate(id) {
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update")
	    ins[i].className = "button_alert";
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
    chg.value = (but.value == "Remove" ? "rem" : "chg");
    var x = row.className.replace(/ *removed/, '');
    row.className = x + (but.value == "Remove" ? " removed" : "");
    but.value = (but.value == "Remove" ? "Do not remove" : "Remove");
    highlightUpdate(id);
}
// -->
</script>

<html>
<?php $Conf->header("Manage $AddWhat", 0) ?>

<?php
function vtrim(&$var) {
    if (isset($var))
	return ltrim(rtrim($var));
    else
	return '';
}

if (isset($_REQUEST["update"])) {
    // Add potential new member
    $email = vtrim($_REQUEST['email']);
    if ($email == '' && (vtrim($_REQUEST['name']) != ''
			 || vtrim($_REQUEST['affiliation']) != ''))
	$Conf->errorMsg("Email address required for new members."); 
    if ($email != '') {
	// Make sure email address exists in system
	$id = $Conf->emailRegistered($email);
	if (!$id) { // try to add them
	    $newguy = new Contact();
	    $result = $newguy->initialize($email, $Conf);
	    if (!DB::isError($result)) {
		if (!isset($_REQUEST['name']))
		    /* nada */;
		else if (preg_match('/^(.*),\s*(\S.*)$/', $_REQUEST['name'], $matches)) {
		    $newguy->firstName = $matches[2];
		    $newguy->lastName = $matches[1];
		} else if (preg_match('/^(.*)\s+(\S*)$/', $_REQUEST['name'], $matches)) {
		    $newguy->firstName = $matches[1];
		    $newguy->lastName = $matches[2];
		} else
		    $newguy->lastName = $_REQUEST['name'];
		if (isset($_REQUEST['affiliation']))
		    $newguy->affiliation = $_REQUEST['affiliation'];
		if (isset($_REQUEST['voicePhoneNumber']))
		    $newguy->affiliation = $_REQUEST['voicePhoneNumber'];
		if (isset($_REQUEST['faxPhoneNumber']))
		    $newguy->affiliation = $_REQUEST['faxPhoneNumber'];
		$result = $newguy->updateDB($Conf, "while creating account for " . htmlspecialchars($email));
	    }
	    if (!DB::isError($result)) {
		$Conf->infoMsg("Created account for " . htmlspecialchars($email) . ".");
		$newguy->sendAccountInfo($Conf);
		$Conf->log("PC chair created account", $newguy);
	    } else
		$Conf->log("PC chair account creation failure", $newguy);
	    $id = $newguy->contactId;
	}

	// successfully looked up member, now make them a PC member
	for ($role = ROLE_PC; $role <= ROLE_CHAIR; $role++) {
	    if (!isset($_REQUEST["role$role"]) || !$_REQUEST["role$role"])
		continue;
	    $query = "insert into Roles set contactId='$id', role=$role";
	    $result = $Conf->qe($query, "while adding " . htmlspecialchars($email) . " to $AddWhat");
	    if (!DB::isError($result)) {
		//$Conf->infoMsg("Added " . htmlspecialchars($email) . " to $AddWhat.");
		$Conf->log("Added $email as $AddWhat (role $role)", $_SESSION["Me"]);
	    } else
		break;
	}

	// unset values
	foreach (array('name', 'email', 'affiliation', 'role' . ROLE_PC,
		       'role' . ROLE_ASSISTANT, 'role' . ROLE_CHAIR) as $what)
	    unset($_REQUEST[$what]);
    }

    // Now, update existing members
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 'c' && $key[1] == 'h' && $key[2] == 'g'
	    && ($id = (int) substr($key, 3)) > 0) {
	    // remove?
	    if ($value == "rem") {
		$result = $Conf->qe("delete from Roles where contactId='$id' and role >= " . ROLE_PC, "while deleting member");
		if (!DB::isError($result))
		    $Conf->log("Removed someone from PC", $_SESSION["Me"]);
		continue;
	    }

	    // remove existing PC roles
	    $result = $Conf->qe("delete from Roles where contactId='$id' and role>" . ROLE_PC, "while updating member's roles");
	    if (!DB::isError($result) && isset($_REQUEST['chair']) && in_array($id, $_REQUEST['chair']))
		$result = $Conf->qe("insert into Roles set contactId='$id', role=" . ROLE_CHAIR, "while adding member as Chair");
	    if (!DB::isError($result) && isset($_REQUEST['ass']) && in_array($id, $_REQUEST['ass']))
		$result = $Conf->qe("insert into Roles set contactId='$id', role=" . ROLE_ASSISTANT, "while adding member as assistant");
	    if (!DB::isError($result))
		$Conf->log("Added someone as Chair", $_SESSION["Me"]);
	}
 }

function outrow() {
    global $id, $first, $last, $email, $affiliation, $assistant, $chair;
    echo "<tr id='pcrow$id'>\n";
    echo "  <td class='pc_name'>$first $last</td>\n";
    echo "  <td class='pc_email'>$email</td>\n";
    echo "  <td class='pc_aff'>$affiliation</td>\n";
    echo "  <td class='pc_chairbox'><input type='checkbox' name='chair[]' value='$id' ", ($chair ? "checked='checked'" : ""), " onclick='highlightUpdate(\"$id\")' /></td>\n";
    echo "  <td class='pc_assbox'><input type='checkbox' name='ass[]' value='$id' ", ($assistant ? "checked='checked'" : ""), " onclick='highlightUpdate(\"$id\")' /></td>\n";
    echo "  <td class='pc_action'><input class='button' type='button' value='Remove from PC' name='rem$id' id='rem$id' onclick='doRemove(\"$id\")' />
    <input type='hidden' value='' name='chg$id' id='chg$id' /></td>
</tr>\n";
}

function addedvalue($what, $checked) {
    if (isset($_REQUEST[$what])) {
	if ($checked)
	    echo "checked='checked' ";
	else
	    echo "value=\"", htmlspecialchars($_REQUEST[$what]), "\" ";
    }
}

$query = "select ContactInfo.contactId, ContactInfo.firstName, "
    . " ContactInfo.lastName, ContactInfo.email, ContactInfo.affiliation, "
    . " Roles.role from ContactInfo, Roles where "
    . " Roles.contactId = ContactInfo.contactId and Roles.role >= " . ROLE_PC
    . " order by ContactInfo.lastName";
$result = $Conf->q($query);
if (DB::isError($result))
    $Conf->errorMsg("Database error: " . $result->getMessage());
 else { ?>
<form METHOD="post" ACTION="<?php echo $_SERVER["PHP_SELF"] ?>" >
<table class="memberlist">

<tr>
  <th>Name</th>
  <th>Email</th>
  <th>Affiliation</th>
  <th>Chair?</th>
  <th>Assistant?</th>
  <th>Actions</th>
</tr>

<?php
    $id = -1; $chair = $assistant = 0;
    while ($row = $result->fetchRow()) {
	if ($row[0] != $id && $id > 0) {
	    outrow();
	    $chair = $assistant = 0;
	}
	$id = $row[0];
	$first = $row[1];
	$last = $row[2];
	$email = $row[3];
	$affiliation = $row[4];
	if ($row[5] == ROLE_CHAIR)
	    $chair = 1;
	if ($row[5] == ROLE_ASSISTANT)
	    $assistant = 1;
    }
    if ($id > 0)
	outrow();
?>

<tr>
  <td class='pc_name'><input type="text" name="name" size="20" onchange='highlightUpdate(0)' <?php addedvalue('name', 0) ?>/></td>
  <td class='pc_email'><input type="text" name="email" size="20" onchange='highlightUpdate(0)' <?php addedvalue('email', 0) ?>/></td>
  <td class='pc_aff'><input type="text" name="affiliation" size="10" onchange='highlightUpdate(0)' <?php addedvalue('affiliation', 0) ?>/></td>
  <td class='pc_chairbox'><input type='checkbox' name='role<?php echo ROLE_CHAIR ?>' value='1' <?php addedvalue('role' . ROLE_CHAIR, 1) ?>/></td>
  <td class='pc_assbox'><input type='checkbox' name='role<?php echo ROLE_ASSISTANT ?>' value='1' <?php addedvalue('role' . ROLE_ASSISTANT, 1) ?>/></td>
  <td class='pc_action'><input type='hidden' name='role<?php echo ROLE_PC ?>' value='1' />Enter new member here</td>
</tr>

<tr>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class='pc_action'><input class='button' type="submit" value="Save Changes" name='update' /></td>
</tr>

</table>
<?php } ?>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
