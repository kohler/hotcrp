<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();

?>

<html>

<?php  $Conf->header("Check on PC Reviewing Progress ") ?>

<?php 

$query = "SELECT ContactInfo.contactId FROM ContactInfo "
  . " join Roles on (Roles.contactId=ContactInfo.contactId and Roles.role=" . ROLE_PC . ")"
  . " ORDER BY ContactInfo.lastName, ContactInfo.FirstName ";

$pcresult = $Conf -> qe($query);

if ( !DB::isError($pcresult) ) {
  while ($row = $pcresult->fetchRow(DB_FETCHMODE_ASSOC)) {
    $pcId=$row['contactId'];

    $Conf->reviewerSummary($pcId,
			   1, 1,
			   "<center>" .
			   $Conf->mkTextButtonPopup("Details",
						    "CheckOnSinglePCProgress.php",
						    "<input type=hidden NAME=pcId value=$pcId>")
			   . " </center>"
			   );

    print "<br> <br>";
  }
}

?>

<body>


</body>
<?php  $Conf->footer() ?>
</html>


