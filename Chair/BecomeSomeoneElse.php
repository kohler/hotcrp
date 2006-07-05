<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Switch Roles To That Of Someone Else") ?>

<body>
<?php 
//
// Process actions from this form..
//
if (IsSet($_REQUEST[becomePerson])) {
  $_SESSION["Me"] -> invalidate();
  $_SESSION["Me"] -> lookupById($_REQUEST[become], $Conf);
}
?>

<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<table align=center>
<tr> <td>
<?php  $Conf->makePCSelector('become', $_SESSION["Me"]->contactId); ?>  
</td>
<td>
<INPUT TYPE="SUBMIT" name="becomePerson" value="Become this PC member">
</td> </tr>
</table>
</form>

<br> <br>

<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<table align=center>
<tr> <td>
  <SELECT name=become SINGLE SIZE=10>
  <?php 
  $query = "SELECT ContactInfo.contactId, firstName, lastName, email "
  . " FROM ContactInfo, ChairAssistant "
  . " WHERE ChairAssistant.contactId=ContactInfo.contactId "
  . " ORDER BY email, lastName, firstName ";
$result = $Conf->qe($query);
 if (!DB::isError($result)) {
   while($row=$result->fetchRow()) {
     print "<OPTION VALUE=\"$row[0]\" ";
     if ( $row[0] == $_SESSION["Me"] -> contactId) {
       print " SELECTED ";
     }
     print "> $row[1] $row[2] ($row[3]) </OPTION>";
   }
 }
 ?>
 </SELECT>
</td>
<td>
<INPUT TYPE="SUBMIT" name="becomePerson" value="Become assistant to the chair">
</td> </tr>
</table>
</form>
<br> <br>

<FORM METHOD="POST" ACTION="<?php echo $_SERVER[PHP_SELF] ?>">
<table align=center>
<tr> <td>
  <SELECT name=become SINGLE SIZE=10>
  <?php 
  $query = "SELECT contactId, firstName, lastName, email "
  . " FROM ContactInfo "
 . " ORDER BY email,lastName, firstName ";
$result = $Conf->qe($query);
 if (!DB::isError($result)) {
   while($row=$result->fetchRow()) {
     print "<OPTION VALUE=\"$row[0]\" ";
     if ( $row[0] == $_SESSION["Me"] -> contactId) {
       print " SELECTED ";
     }
     print "> $row[1] $row[2] ($row[3]) </OPTION>";
   }
 }
 ?>
 </SELECT>
</td> <td>
     <INPUT TYPE="SUBMIT" name="becomePerson" value="Become this random person">
</td> </tr>
</table>
</form>

<?php $Conf->footer() ?>

