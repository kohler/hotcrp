<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf->connect();
?>

<html>

<?php  $Conf->header("Add Contact Information Or Send Notices") ?>

<body>

<?php 
if (IsSet($_REQUEST[addContact]) ) {
  if ( !IsSet($_REQUEST[firstEmail]) || $_REQUEST[firstEmail] == "") {
    $Conf->errorMsg("You need to supply an email address to add someone");
  } else if ($_REQUEST[firstEmail] != $_REQUEST[secondEmail]) {
    $Conf->errorMsg("Email addresses do not match.");   
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
  }
}
?>

<hr>
   <form method="POST" action="<?php echo $_SERVER[PHP_SELF]?>">
   <p> Or, add a new contact record. </p>
    <p> You can add as much information as you care to (the person can change
							it later)
    but you must at least enter the first email address since that is how people will
    log in.  If the person doesn't have an account, you need to add the email
    twice for confirmation.
</p>
<p><input type="submit" value="Add Contact Info" name="addContact"></p>
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
<p><input type="submit" value="Add Contact Info" name="addContact"></p>
        </form>
        <p>&nbsp;</td>
    </tr>
  </table>
  </center>
</div>
</form>

	<?php  $Conf->taskHeader("Existing Accounts", "100") ?>

<div align="center">
<center>
<table border=1>
    <tr> <th> Name </th> <th> Email </th>
    </tr>
<?php 
  $query = "SELECT ContactInfo.contactId, ContactInfo.firstName, "
   . " ContactInfo.lastName, ContactInfo.email "
   . " FROM ContactInfo ";
$result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    print "<tr> <td>n";
    $Conf->errorMsg("There are no contacts? "
		    . $result->getMessage());
    print "</td></tr>";
  } else {
    $cnt = $result->numRows();
    while ($row = $result->fetchRow() ) {
      $i = 0;
      $id = $row[$i++];
      $first = $row[$i++];
      $last = $row[$i++];
      $email = $row[$i++];
      print "<tr> <td> $first $last </td> <td> $email </td> </tr>";
    }
  }
?>

</table>
</center>
</div>

<?php  $Conf->footer() ?>
</body>
</html>
