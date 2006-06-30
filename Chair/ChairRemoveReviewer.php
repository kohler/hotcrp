<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Remove Program Committee Members From Reviewing") ?>

<body>
<?php 
  //
  // We're removing a specific contactId from reviewing a primary or secondary paper.
  //
  if ( !IsSet($_REQUEST[who]) || !IsSet($_REQUEST[paperId]) || !IsSet($_REQUEST[reviewType]) ) {
    $Conf->errorMsg("You're missing some vital data about which review "
		 . "to remove. <a href=\"AssignPapers.php\"> go back </a> "
		 . "and try again");
  } else {
    if ($_REQUEST[reviewType]=="Primary") {
      $result = $Conf->q("DELETE FROM PrimaryReviewer "
			    . " WHERE reviewer='$_REQUEST[who]' AND paperId='$_REQUEST[paperId]'");
      if ( ! DB::isError($result) ) {
	$Conf->errorMsg("I removed " . $_SESSION[DB]->affectedRows() . "  primary reviews ");
      } else {
	$Conf->errorMsg("There was an error removing the reviewers: " . $result->getMessage());
      }
    } else if ($_REQUEST[reviewType]=="Secondary") {
      $result = $Conf->q("DELETE FROM SecondaryReviewer "
			 . " WHERE reviewer='$_REQUEST[who]' AND paperId='$_REQUEST[paperId]'");
      if (!DB::isError($result) ) {
	$Conf->errorMsg("I removed " . $_SESSION[DB]->affectedRows() . "  secondary reviews ");

      } else {
	$Conf->errorMsg("There was an error removing the reviewers: " . $result->getMessage());
      }
    } else {
      $Conf->errorMsg("You need to remove either primary or secondary reviews");
    }
  }
?>

<p> You can <a href="AssignPapers.php"> return to the paper assignment page. </a>

</body>
<?php  $Conf->footer() ?>
</html>


