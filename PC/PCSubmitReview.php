<?php 
include('../Code/confHeader.inc');

$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> goIfInvalidActivity("PCSubmitReviewDeadline",
			     $Conf->paperSite);
$Conf -> connect();

include('../Reviewer/CommonSubmitReview.php');
?>
