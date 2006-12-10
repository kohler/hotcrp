<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

?>

<html>

<?php  $Conf->header("Check on PC Reviewing Progress ") ?>

<?php 

$query = "SELECT ContactInfo.contactId FROM ContactInfo "
  . " join PCMember using (contactId)"
  . " ORDER BY ContactInfo.lastName, ContactInfo.FirstName ";

$pcresult = $Conf -> qe($query);

if ($pcresult) {
  while ($row = edb_arow($pcresult)) {
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


