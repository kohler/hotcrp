<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');

?>

<html>

<?php  $Conf->header("Check on PC Reviewing Progress ") ?>

<?php 

print "<p> You can see this information ";
print $Conf->printableTimeRange('pc_seeallrev', '');
print "</p>";

if (!$Conf->timePCViewAllReviews()) {
  $Conf->errorMsg("You can not see this information right now");
  exit();
}

$query = "SELECT ContactInfo.contactId FROM PCMember,ContactInfo "
  . " WHERE PCMember.contactId=ContactInfo.contactId "
  . " ORDER BY ContactInfo.lastName, ContactInfo.FirstName ";

$pcresult = $Conf -> qe($query);
while ($row = edb_arow($pcresult)) {
    $pcId=$row['contactId'];

    if ( $_SESSION["Me"] -> isChair ) {
      $extra = 
	"<center>" .
	$Conf->mkTextButtonPopup("Details",
				 "../Chair/CheckOnSinglePCProgress.php",
				 "<input type=hidden NAME=pcId value=$pcId>")
	. " </center>";
    }else {
      $extra="";
    }

    $Conf->reviewerSummary($pcId, 0, $extra);

    print "<br> <br>";
}

?>

<body>


</body>
<?php  $Conf->footer() ?>
</html>


