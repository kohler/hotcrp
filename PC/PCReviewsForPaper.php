<?php 
include('../Code/confHeader.inc');
include('../Code/confConfigReview.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();

//
// Input condition: reviewId == record number for ReviewRequest.
// Logic: pull up request
//		check accesss (my request?)
//		pull up reviewer info
//		pull up review
$query="SELECT * from ReviewRequest WHERE reviewRequestId='$_REQUEST[requestId]'";
$r = $Conf->q($query);
if (!$r) {
  $Conf->errorMsg("Unable to get requested review information");
  exit();
}
$reviewInfo = $r->fetchRow(DB_FETCHMODE_ASSOC);
//
// Access control: requesting PC or Chair
//
if ($_SESSION["Me"] -> isChair || $reviewInfo['requestedBy'] == $_SESSION["Me"] -> contactId ) {
  // OK
} else {
  $Conf->errorMsg("You're not authorized to see this review");
  exit();
}

if ( $_SESSION["Me"]->checkConflict($paperId, $Conf)) {
  $Conf -> errorMsg("The program chairs have registered a conflict "
		    . " of interest for you to read this paper."
		    . " If you think this is incorrect, contact the "
		    . " program chair " );
  exit();
}


?>

<html>

<?php 
//
// Get contact Information
//
$paperId=$reviewInfo['paperId'];

$Reviewer = new Contact();
$Reviewer ->lookupById($reviewInfo['asked'], $Conf);

if ( $Reviewer -> contactId == 0 ) {
  $Conf-> errorMsg("Unable to retrieve information about reviewer. Contact site admin.");
  exit();
}

$Review = ReviewFactory($Conf, $Reviewer->contactId, $paperId);
$lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);
$Conf->header("See the reviews for Paper #$paperId by " . $Reviewer->fullnameAndEmail());
?>

<body>

<p>
This review was last modified <?php  echo $lastModified ?>;
</p>

<?php 
$Review -> printViewable();
?>

<?php  $Conf->footer() ?>
</body>
</html>

