<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid("../");
$Me->goIfNotChair('../');
$Conf->header_head("Manage $AddWhat");
?>
<script type="text/javascript"><!--
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
// -->
</script>

<html>
<?php $Conf->header("Manage $AddWhat") ?>

<?php
function vtrim(&$var) {
    if (isset($var))
	return trim($var);
    else
	return '';
}

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

if (isset($_REQUEST["update"])) {
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
 }

function addedvalue($what, $checked) {
    if (isset($_REQUEST[$what])) {
	if ($checked)
	    echo "checked='checked' ";
	else
	    echo "value=\"", htmlspecialchars($_REQUEST[$what]), "\" ";
    }
}

$query = "select ContactInfo.contactId, ContactInfo.firstName,
	ContactInfo.lastName, ContactInfo.email, ContactInfo.affiliation,
	ChairAssistant.contactId as ass, Chair.contactId as chair
	from ContactInfo
	join PCMember using (contactId)
	left join ChairAssistant using (contactId)
	left join Chair using (contactId)
	order by ContactInfo.lastName";
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
    while ($row = $result->fetchRow()) {
	$id = $row[0];
	echo "<tr id='pcrow$id'>
  <td class='pc_name'>", htmlspecialchars(trim("$row[1] $row[2]")), "</td>
  <td class='pc_email'>", htmlspecialchars($row[3]), "</td>
  <td class='pc_aff'>", htmlspecialchars($row[4]), "</td>
  <td class='pc_chairbox'><input type='checkbox' name='chair$id' value='1'";
	if ($row[6])
	    echo " checked='checked'";
	if ($id == $Me->contactId)
	    echo " disabled='disabled'";
	echo " onclick='highlightChange($id)' /></td>
  <td class='pc_assbox'><input type='checkbox' name='ass$id' value='1'";
	if ($row[5])
	    echo " checked='checked'";
	if ($id == $Me->contactId)
	    echo " disabled='disabled'";
	echo " onclick='highlightChange($id)' /></td>
  <td class='pc_action'><input class='button' type='button' value='Remove from PC' name='rem$id' id='rem$id'";
	if ($id == $Me->contactId)
	    echo " disabled='disabled'";
	echo " onclick='doRemove($id)' />
    <input type='hidden' value='' name='chg$id' id='chg$id' /></td>
</tr>\n";
    }
?>

<tr>
  <td class='pc_name'><input class='textlite' type="text" name="name" size="20" onchange='highlightUpdate()' <?php addedvalue('name', 0) ?>/></td>
  <td class='pc_email'><input class='textlite' type="text" name="email" size="20" onchange='highlightUpdate()' <?php addedvalue('email', 0) ?>/></td>
  <td class='pc_aff'><input class='textlite' type="text" name="affiliation" size="10" onchange='highlightUpdate()' <?php addedvalue('affiliation', 0) ?>/></td>
  <td class='pc_chairbox'><input type='checkbox' name='chairnew' value='1' <?php addedvalue('nchair', 1) ?>/></td>
  <td class='pc_assbox'><input type='checkbox' name='assnew' value='1' <?php addedvalue('nass', 1) ?>/></td>
  <td class='pc_action'><input type='hidden' name='pcnew' value='1' />Enter new member here</td>
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
