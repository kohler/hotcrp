<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid($Conf->paperSite);
$_SESSION[Me] -> goIfNotPC($Conf->paperSite);
$Conf -> goIfInvalidActivity("PCSubmitReviewDeadline",
			     $Conf->paperSite);
$Conf -> connect();
?>

<html>

<?php  $Conf->header("Select A Paper To Review ") ?>

<body>

<h2> Primary Reviews </h2>

<p> You are primary reviewer for the following papers. You are responsible for
reading these papers yourself and submitting the review. Click on the paper
title to submit or modify your review.
<b> You can continue to modify your review(s)
<?php  echo $Conf->printTimeRange('PCSubmitReview') ?>
or until you finalize them. </b>
</p>

<?php 
$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.withdrawn "
		  . " FROM Paper, PrimaryReviewer "
		  . "WHERE PrimaryReviewer.reviewer='" . $_SESSION[Me]->contactId . "' "
		  . " AND Paper.paperId=PrimaryReviewer.paperId "
		  . "ORDER BY Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
} else {
  $ids=array();
  $titles=array();
  $i = 0;
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ( !$row['withdrawn']) {
      $ids[$i] = $row['paperId'];
      $titles[$i] = $row['title'];
      $i++;
    }
  }
  $_SESSION[Me] -> printReviewables($ids, $titles, "PCSubmitReview.php", $Conf);
}
?>

<h2> Secondary Reviews </h2>

<?php 
$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.withdrawn "
		  . " FROM Paper, SecondaryReviewer "
		  . " WHERE SecondaryReviewer.reviewer='" . $_SESSION[Me]->contactId. "' "
		  . " AND Paper.paperId=SecondaryReviewer.paperId "
		  . "ORDER BY Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
} else {
  $ids=array();
  $titles=array();
  $i = 0;
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if (! $row['withdrawn'] ) {
      $ids[$i] = $row['paperId'];
      $titles[$i] = $row['title'];
      $i++;
    }
  }
  $_SESSION[Me] -> printReviewables($ids, $titles, "PCSubmitReview.php", $Conf);
}

if ($Conf->validTimeFor('PCReviewAnyPaper', 0)) {
?>
  
<h2> Additional Reviews </h2>

<p> Your program manager has elected to allow you to review any paper
submitted to the conference. </p>
<?php  $Conf->textButton("Click here to review any paper",
			 ReviewAnyPaper.php);
?>

<?php 
   }
?>







</body>
<?php  $Conf->footer() ?>
</html>




