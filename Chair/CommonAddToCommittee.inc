<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Add a $AddWhat Member") ?>

<body>

<?php 
if (IsSet($_REQUEST[addPCMember]) ) {
  if ( !IsSet($_REQUEST[firstEmail]) || $_REQUEST[firstEmail] == "") {
    $Conf->errorMsg("You need to supply an email address to add someone");
  } else {
    //
    // Check to see if they exist
    //
    $id = $Conf->emailRegistered($_REQUEST[firstEmail]);

    if ( $id == 0 ) {
      //
      // Try to add them if they don't exist...
      //
      $newguy = new Contact();
      $newguy -> initialize($_REQUEST[firstName], $_REQUEST[lastName], $_REQUEST[firstEmail], $_REQUEST[affiliation],
			$_REQUEST[phone], $_REQUEST[fax]);
      $result = $newguy -> addToDB($Conf);

      if ( $result ) {
	$Conf->infoMsg("Created account for $_REQUEST[firstEmail]");
	if ($_REQUEST[inform]) {
	  $newguy -> sendAccountInfo($Conf);
	}
      } else {
	$Conf->errorMsg("Had trouble creating an account for $_REQUEST[firstEmail]");
      }
      $id = $Conf->emailRegistered($_REQUEST[firstEmail]);
    }
    else {
      $Conf->errorMsg("They already have an account");
    }
    if ($id != 0) {
      $query="INSERT into $AddWhatTable SET contactId='$id'";
      $result = $Conf->qe($query);
      if (DB::isError($result)) {
	$Conf->errorMsg("insert error - " . $result->getMessage());
      }
      $Conf->infoMsg("Added $_REQUEST[firstEmail] as $AddWhat ");

      $Conf->log("Added $_REQUEST[firstEmail] as $AddWhat ", $_SESSION["Me"]);
    }
  }
} else if (IsSet($_REQUEST[removeThem])) {
  for ($i = 0; $i < sizeof($_REQUEST[removePC]); $i++) {
    $id =  $_REQUEST[removePC][$i];
    $result=$Conf->qe("DELETE FROM $AddWhatTable WHERE $AddWhatId='$id'");
    if ( DB::isError($result) ) {
      $Conf->errorMsg("delete " . $result->getMessage());
    }

    $Conf->log("Removed someone as $AddWhat ", $_SESSION["Me"]);
  }
}
?>

<div align="center">
<center>
<form METHOD="POST" ACTION="<?php  echo $_SERVER[PHP_SELF] ?>" >
<table border=1>
    <tr> <th> Name </th> <th> Email </th> <th> Remove? </th> </tr>
<?php 
  $query = "SELECT $AddWhatTable.contactId, $AddWhatTable.$AddWhatId, "
	. " ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email "
    . " FROM ContactInfo, $AddWhatTable WHERE "
    . " ($AddWhatTable.contactId=ContactInfo.contactId) ORDER BY ContactInfo.lastName";
$result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    print "<tr> <td>n";
    $Conf->errorMsg("There are no $AddWhat memebers? "
		    . $result->getMessage());
    print "</td></tr>";
  } else {
    $cnt = $result->numRows();
    while ($row = $result->fetchRow() ) {
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
  }
?>

</table>
</center>
</div>

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

<?php  $Conf->footer() ?>
</body>
</html>
