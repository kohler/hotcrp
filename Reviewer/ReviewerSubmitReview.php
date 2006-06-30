<?php 
include('../Code/confHeader.inc');

$_SESSION["Me"] -> goIfInvalid("../index.php");
$Conf -> goIfInvalidActivity("reviewerSubmitReviewDeadline", "../index.php");
$Conf -> connect();

include('../Reviewer/CommonSubmitReview.php');

?>
