<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
?>

<html>
<?php $Conf->header("Manage $AddWhat") ?>
<div id='body'>

<?php
if (isset($_REQUEST['add'])) {
    if (!isset($_REQUEST['email']))
	$Conf->errorMsg("Enter the email address to add.");
    else {
	// Make sure email address exists in system
	$id = $Conf->emailRegistered($_REQUEST['email']);
	if (!$id) { // try to add them
	    $newguy = new Contact();
	    $result = $newguy->initialize($_REQUEST['email'], $Conf);
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
		$result = $newguy->updateDB($Conf);
	    }
	    if (!DB::isError($result)) {
		$Conf->infoMsg("Created account for " . htmlspecialchars($_REQUEST['email'] . "."));
		$newguy->sendAccountInfo($Conf);
		$Conf->log("PC chair created account", $newguy);
	    } else {
		$Conf->errorMsg($Conf->dbErrorText($result, "while creating account for " . htmlspecialchars($_REQUEST['email'])));
		$Conf->log("PC chair account creation failure", $newguy);
	    }
	    $id = $newguy->contactId;
	}

	// successfully looked up member, now make them a PC member
	for ($role = ROLE_PC; $role <= ROLE_CHAIR; $role++) {
	    if (!isset($_REQUEST["role$role"]) || !$_REQUEST["role$role"])
		continue;
	    $query = "insert into Roles set contactId='$id', role=$role";
	    $result = $Conf->qe($query, "while adding " . htmlspecialchars($_REQUEST['email']) . " to $AddWhat");
	    if (!DB::isError($result)) {
		//$Conf->infoMsg("Added " . htmlspecialchars($_REQUEST['email']) . " to $AddWhat.");
		$Conf->log("Added " . $_REQUEST['email'] . " as $AddWhat (role $role)", $_SESSION["Me"]);
	    } else
		break;
	}
    }
}

foreach ($_REQUEST as $key => $value) {
    if ($key[0] == 'r' && $key[1] == 'e' && $key[2] == 'm'
	&& ($id = (int) substr($key, 3)) > 0) {
	$result = $Conf->qe("delete from Roles where contactId='$id' and role >= " . ROLE_PC, "while deleting member");
	if (!DB::isError($result))
	    $Conf->log("Removed someone from PC", $_SESSION["Me"]);
    } else if ($key[0] == 'u' && $key[1] == 'p' && $key[2] == 'd'
	       && ($id = (int) substr($key, 3)) > 0) {
	if (isset($_REQUEST['chair']) && in_array($id, $_REQUEST['chair']))
	    $result = $Conf->qe("insert into Roles set contactId='$id', role=" . ROLE_CHAIR, "while adding member as Chair");
	else
	    $result = $Conf->qe("delete from Roles where contactId='$id' and role=" . ROLE_CHAIR, "while deleting member as Chair");
	if (DB::isError($result))
	    /* do nothing */;
	else if (isset($_REQUEST['ass']) && in_array($id, $_REQUEST['ass']))
	    $result = $Conf->qe("insert into Roles set contactId='$id', role=" . ROLE_ASSISTANT, "while adding member as assistant");
	else
	    $result = $Conf->qe("delete from Roles where contactId='$id' and role=" . ROLE_ASSISTANT, "while deleting member as assistant");
	if (!DB::isError($result))
	    $Conf->log("Added someone as Chair", $_SESSION["Me"]);
    }
}
?>

<?php
function outrow() {
    global $id, $first, $last, $email, $affiliation, $assistant, $chair;
    echo "<tr>\n";
    echo "  <td class='pc_name'>$first $last</td>\n";
    echo "  <td class='pc_email'>$email</td>\n";
    echo "  <td class='pc_aff'>$affiliation</td>\n";
    echo "  <td class='pc_chairbox'><input type='checkbox' name='chair[]' value='$id' ", ($chair ? "checked='checked'" : ""), " /></td>\n";
    echo "  <td class='pc_assbox'><input type='checkbox' name='ass[]' value='$id' ", ($assistant ? "checked='checked'" : ""), " /></td>\n";
    echo "  <td><input class='button' type='submit' value='Update' name='upd$id' />
    <input class='button' type='submit' value='Remove' name='rem$id' /></td>
</tr>\n";
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
   <tr> <th>Name</th> <th>Email</th> <th>Affiliation</th>
	<th>Chair?</th> <th>Assistant?</th> <th>Actions</th> </tr>
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
  <td class='pc_name'><input type="text" name="name" size="20" /></td>
  <td class='pc_email'><input type="text" name="email" size="20" /></td>
  <td class='pc_aff'><input type="text" name="affiliation" size="10" /></td>
  <td class='pc_chairbox'><input type='checkbox' name='role<?php echo ROLE_CHAIR ?>' value='1' /></td>
  <td class='pc_assbox'><input type='checkbox' name='role<?php echo ROLE_ASSISTANT ?>' value='1' /></td>
  <td><input type='hidden' name='role<?php echo ROLE_PC ?>' value='1' /><input class='button' type="submit" value="Add member" name='add' /></td>
</tr>
</table>
<?php } ?>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
