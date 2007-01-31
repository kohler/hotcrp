<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotAssistant('../index.php');


$Conf->header("Modify Names In Contact Base");


if (IsSet($_REQUEST[updateContacts])) {
  $Conf->infoMsg("<center>...updating, may be slow...</center>");
  
  $r = $Conf->qe("SELECT * FROM ContactInfo "
		 . " ORDER BY contactId");
  $oldFirst = array();
  $oldLast = array();
  while ($row=edb_arow($r) ) {
      $first = $row['firstName'];
      $last = $row['lastName'];
      $id = $row['contactId'];
      $oldFirst[$id] = $first;
      $oldLast[$id] = $last;
  }

  while (list($id,$first) = each($_REQUEST["newFirst"])) {
    //
    // Now, compare new name with old to see if it
    // changed, and if so, update database
    //

    $last = $_REQUEST[newLast][$id];

    $first = trim($first);
    $last = trim($last);
    
    if ($oldFirst[$id] != $first || $oldLast[$id] != $last) {

      $sep = "";
      if ( $first != "" ) {
	$sep = ", ";
	$uf = "firstName='$first'";
      }

      if ( $last != "" ) {
	$ul = "$sep lastName='$last'";
      }

      $Conf->qe("UPDATE ContactInfo SET "
		. " $uf $ul "
		. " WHERE contactId=$id");
      $Conf->infoMsg("Updated '$oldFirst[$id] $oldLast[$id]' to "
		     . " '$first $last' ");
    }
  }
}

$r = $Conf->qe("SELECT * FROM ContactInfo "
	       . " ORDER BY lastName, email");
if ($r) {
  print "<FORM METHOD=POST ACTION=" . $_SERVER[PHP_SELF] . ">\n";
  print "<input type=submit name=updateContacts value='Update Contacts'>\n";
  print "<table align=center width=85% border=1 >\n";
  print "<tr> <th> First </th> <th> Last </th> <th> Email </th> </tr>\n";

  while ($row=edb_arow($r)) {
    $first = $row['firstName'];
    $last = $row['lastName'];
    $email = $row['email'];
    $id=$row['contactId'];
    print "<tr> ";
    print "<td> <input type=text name='newFirst[$id]' value='$first'> </td> ";
    print "<td> <input type=text name='newLast[$id]' value='$last'> </td> ";
    print "<td> $email </td> </tr>\n";
  }
  print "</table>\n";
  print "<input type=submit name=updateContacts value='Update Contacts'>\n";
  print "</form>\n";
}


$Conf->footer();
