<?php 
include('../Code/confHeader.inc');
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
	$query = "insert into $AddWhatTable set contactId='$id'";
	$result = $Conf->qe($query, "while adding " . htmlspecialchars($_REQUEST['email']) . " to $AddWhat");
	if (!DB::isError($result)) {
	    $Conf->infoMsg("Added " . htmlspecialchars($_REQUEST['email']) . " to $AddWhat.");
	    $Conf->log("Added " . $_REQUEST['email'] . " as $AddWhat", $_SESSION["Me"]);
	}
    }
    
}
		
		

if (IsSet($_REQUEST["removeThem"])) {
    for ($i = 0; $i < sizeof($_REQUEST["removePC"]); $i++) {
	$id =  $_REQUEST["removePC"][$i];
	$result=$Conf->qe("DELETE FROM $AddWhatTable WHERE $AddWhatId='$id'");
	if ( DB::isError($result) ) {
	    $Conf->errorMsg("delete " . $result->getMessage());
	}

	$Conf->log("Removed someone as $AddWhat ", $_SESSION["Me"]);
    }
 }
?>

<?php 
$query = "select $AddWhatTable.contactId, $AddWhatTable.$AddWhatId, "
    . " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
    . " from ContactInfo, $AddWhatTable where "
    . " ($AddWhatTable.contactId=ContactInfo.contactId) order by ContactInfo.lastName";
$result = $Conf->q($query);
if (DB::isError($result))
    $Conf->errorMsg("Database error: " . $result->getMessage());
 else { ?>
<form METHOD="post" ACTION="<?php echo $_SERVER["PHP_SELF"] ?>" >
<table class="memberlist">
   <tr> <th>Name</th> <th>Email</th> <th>Remove?</th> </tr>
<?php
    while ($row = $result->fetchRow()) {
	$i = 0;
	$id = $row[$i++];
	$pcid = $row[$i++];
	$first = $row[$i++];
	$last = $row[$i++];
	$email = $row[$i++];
	print "<tr> <td> $first $last </td> <td> $email </td>";
	print "<td> <INPUT type=\"checkbox\" name=\"removePC[]\" value=\"$pcid\"> </td>";
	print "</tr>";
    }
?>
<tr><td><input type="text" name="name" size="20" /></td>
    <td><input type="text" name="email" size="20" /></td>
    <td><input class='button' type="submit" value="Add member" name='add' /></td></tr>
</table>
<?php } ?>

<p>
You may see multiple listings for the same committee member.
This can occur when a committee member is added to the list twice
or two accounts are merged. You can safely delete additional
entries if you like.
</p>

<br>
<INPUT TYPE="SUBMIT" name="removeThem" value="Remove Selected <?php  echo $AddWhat?> ">
</form>

<hr>
   <form method="POST" action="<?php echo $_SERVER[PHP_SELF]?>">
   <p> Or, add a new <?php  echo $AddWhat ?> member. </p>
    <p> You can add as much information as you care to
        (the <?php  echo $AddWhat ?> can change it later)
    but you must at least enter the first email address since that is how people will
    log in.  If the person doesn't have an account, you need to add the email
    twice for confirmation.
</p>
<p><input type="submit" value="Add <?php  echo $AddWhat ?> member" name="addPCMember"></p>
          <div align="center">
            <table border="1" width="75%" bgcolor="<?php echo $Conf->bgTwo?>">
              <tr>
                <td width="100%" COLSPAN=2 align="center">
    		<INPUT TYPE="checkbox" name="inform" value=1>
    		Send them email about the account if they don't already have one?
    		</td>
              </tr>
              <tr>
                <td width="35%">First Name</td>
                <td width="65%"><input type="text" name="firstName" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Last Name</td>
                <td width="65%"><input type="text" name="lastName" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Email</td>
                <td width="65%"><input type="text" name="firstEmail" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Email Again</td>
                <td width="65%"><input type="text" name="secondEmail" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Affiliation</td>
                <td width="65%"><input type="text" name="affiliation" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Voice Phone</td>
                <td width="65%"><input type="text" name="phone" size="44"></td>
              </tr>
              <tr>
                <td width="35%">Fax</td>
                <td width="65%"><input type="text" name="fax" size="44"></td>
              </tr>
            </table>
          </div>
          <p><input type="submit" value="Add <?php echo $AddWhat ?> member" name="addPCMember"></p>
        </form>
        <p>&nbsp;</td>
    </tr>
  </table>
  </center>
</div>

</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
