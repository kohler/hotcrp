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

function olink($key,$string)
{
  return "<a href=\"$_SERVER[PHP_SELF]?orderBy=$key\"> $string </a>";
}
$ORDER="ORDER BY ContactInfo.contactId";
if (IsSet($_REQUEST[orderBy])) {
  $ORDER="ORDER BY $_REQUEST[orderBy]";
}

if (IsSet($_REQUEST[removeThem])) {
  for ($i = 0; $i < sizeof($_REQUEST[removeContact]); $i++) {
    $id =  $_REQUEST[removeContact][$i];
    $query="DELETE FROM ContactInfo WHERE contactId='$id'";
    $Conf->qe($query);
  }
} else if (IsSet($_REQUEST[remindThem])) {
  $Them = new Contact();
  for ($i = 0; $i < sizeof($_REQUEST[remindWho]); $i++) {
    $email =  $_REQUEST[remindWho][$i];
    $Them -> lookupByEmail($email,$Conf);
    $Them -> sendAccountInfo($Conf);
    $Conf->errorMsg("Sent reminder to $email");
  }
}
?>

<div align="center">
<center>
<form METHOD="POST" ACTION="<?php  echo $_SERVER[PHP_SELF]?>" >
<table border=1>
    <tr>
<th>
<?php  echo olink("ContactInfo.contactId", "#") ?>
</th>
<th>
<?php  echo olink("ContactInfo.lastName", "Name") ?>
</th> <th>
<?php  echo olink("ContactInfo.email", "Email") ?>
</th>
    <th> Remove? </th>
    <th> Send account reminder? </th>
    </tr>
<?php 
  $query = "SELECT ContactInfo.contactId, ContactInfo.firstName, "
   . " ContactInfo.lastName, ContactInfo.email "
   . " FROM ContactInfo $ORDER ";
$result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    print "<tr> <td>n";
    $Conf->errorMsg("There are no program committee memebers? "
		    . $result->getMessage());
    print "</td></tr>";
  } else {
    while ($row = $result->fetchRow() ) {
      $i = 0;
      $id = $row[$i++];
      $first = $row[$i++];
      $last = $row[$i++];
      $email = $row[$i++];
      print "<tr> <td> $id </td> <td> $first $last </td> <td> $email </td>";
      print "<td> <INPUT type=\"checkbox\" name=\"removeContact[]\" value=\"$id\"> </td>";
      print "<td> <INPUT type=\"checkbox\" name=\"remindWho[]\" value=\"$email\"> </td>";
      print "</tr>";
    }
  }
?>

</table>
</center>
</div>

<br>
<?php 
$Conf->infoMsg(" <b> NOTE: </b> All the data in the database is keyed to the contact id. "
	       . " Once you remove a persons contact ID, there's really no way "
	       . " that person can review papers, etc. Make certain you know what "
	       . " you're doing ");
?>

<center>
<INPUT TYPE="SUBMIT" name="removeThem" value="Remove Selected Contact Information">
<INPUT TYPE="SUBMIT" name="remindThem" value="Send account reminders to selected accounts">
</center>
</form>

<?php  $Conf->footer() ?>
</body>
</html>
