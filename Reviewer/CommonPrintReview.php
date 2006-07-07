<?php 
include('../Code/confConfigReview.inc');
?>
<html>

<?php  $Conf->header("Submit or Update A Review For Paper #$_REQUEST[paperId]") ?>

<body>
<?php 
if (!$_SESSION["Me"]->canReview($_REQUEST[paperId], $Conf) ) {
  $Conf->errorMsg("You aren't supposed to be able to review paper #$_REQUEST[paperId]. "
		  . "If you think this is in error, contact the program chair. ");
} else {
  $Review = ReviewFactory($Conf, $_SESSION["Me"]->contactId, $_REQUEST[paperId]);
  $Review -> printAnonReviewHeader($Conf);
  print "<hr>\n";
  print "<div align=\"center\">\n";
  print "<center>\n";
  $Review -> printViewable();
  print "</center>";
  print "</div>";
}
?>

<?php $Conf->footer() ?>
