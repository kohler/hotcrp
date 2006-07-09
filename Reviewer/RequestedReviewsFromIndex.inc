<h2> Requested Reviews </h2>

<p>You have been asked to review the following paper(s) by a member of the progam committee.
Click on the link to start or modify a review or to see your finalized review.
<b> You can continue to modify your review(s)
<?php  echo $Conf->printTimeRange('reviewerSubmitReview') ?>
or until you finalize them. </b>
</p>

<p> You can download the papers by clicking on the appropriate paper title; you will
find the link to download the paper at the top of the review form </p>

<?php 
 //
 // Now, look for reviews directed to this person
 //

$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.withdrawn FROM Paper join ReviewRequest using (paperId) "
		    . "WHERE ReviewRequest.contactId='" . $_SESSION["Me"]->contactId . "' and ReviewRequest.reviewType=" . REVIEW_REQUESTED
		    . " ORDER BY Paper.paperId");
if (!DB::isError($result)) {
  $i = 0;
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ($row['withdrawn'] <= 0) {
      $ids[$i] = $row['paperId'];
      $titles[$i] = $row['title'];
      $i++;
    }
  }
} else {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
}

$_SESSION["Me"]->printReviewables($ids, $titles, $PREFIX . "ReviewerSubmitReview.php", $Conf);
?>

<?php 
//
// Some PC members also like seeing their primary reviews here..
//
if ($_SESSION["Me"] -> isPC) {
?>

<h2> Primary Reviews </h2>

<p>You have been asked to review the following paper(s)
by the program chair.
Click on the link to start or modify a review or to see your finalized review.
<b> You can continue to modify your review(s)
<?php  echo $Conf->printTimeRange('PCSubmitReview') ?>
or until you finalize them. </b>
</p>

<p> You can download the papers by clicking on the appropriate paper title; you will
find the link to download the paper at the top of the review form </p>
<?php 
$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.withdrawn "
		  . " FROM Paper join ReviewRequest using (paperId) "
		  . "where ReviewRequest.contactId='" . $_SESSION["Me"]->contactId. "' "
		  . " AND ReviewRequest.reviewType=" . REVIEW_PRIMARY
		  . " ORDER BY Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
} else {
  $ids=array();
  $titles=array();
  $i = 0;
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ($row['withdrawn'] <= 0) {
      $ids[$i] = $row['paperId'];
      $titles[$i] = $row['title'];
      $i++;
    }
  }
  $_SESSION["Me"] -> printReviewables($ids, $titles, "../PC/PCSubmitReview.php", $Conf);
}
}
