<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAuthor("../index.php");
$Conf -> goIfInvalidActivity("authorViewReviews", "../index.php");
$Conf -> connect();
include('../Code/confConfigReview.inc');

if ( $Conf -> validTimeFor('authorViewReviews', 0) ) {
  $type = 'finished';
  $latetables = '';
  $laterestrict = '';
} else {
  $type = 'late';
  $latetables = ', ImportantDates';
  $laterestrict = " AND PaperReview.lastModified > ImportantDates.start "
                . " AND ImportantDates.name = 'authorRespondToReviews' ";
}


?>

<html>
<?php  $Conf->header("See all the $type reviews for Paper #$_REQUEST[paperId]") ?>

<body>

<?php 
if (!IsSet($_REQUEST[paperId]) ) {
  $Conf -> errorMsg("You did not specify a paper to modify.");
  exit;
}

if ( $_SESSION["Me"] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  //
  // Ok, I'm author...
  //
} else if ( $_SESSION["Me"] -> isChair ) {
    $Conf -> infoMsg("....chair exemption...");
} else {
  $Conf -> errorMsg("Only the submitting paper author can modify the "
		    . "paper information.");
  exit;
}
?>

<p>
You can see the paper reviews
<?php  echo $Conf->printableTimeRange('authorViewReviews') ?>.
</p>

<?php 
//
// Make certain that the author has submitted all their reviews
// prior to viewing their own reviews

$missingReviews = $Conf->listMissingReviews($_SESSION["Me"]->contactId);

if ($missingReviews) {
  $Conf->errorMsg("Before you view the reviews for your own paper, "
		  . " PLEASE finish reviewing the papers you were "
		  . " asked to review, or you tell the program "
		  . " committee member that you can not finish your "
		  . " reviews. ");
}



print "<center>";

$revFin = $Conf->countEntries("paperId",
			      $_REQUEST[paperId],
			      "PaperReview",
			      "AND reviewSubmitted=1");

$revUnfin = $Conf->countEntries("paperId",
				$_REQUEST[paperId],
				"PaperReview",
				"AND reviewSubmitted=0");

$revReq = $Conf->countEntries("paperId",
			      $_REQUEST[paperId],
			      "ReviewRequest");

$revTot = $revReq;

print "<p> There are $revFin finalized reviews and ";
print "$revUnfin started, but unfinalized, reviews for your paper.<br> ";
print "A total of $revTot reviews were requested by the program committee.<br> ";
print "You will receive an email notifcation when any of the unfinished ";
print "reviews are finalized. ";
print "</p>";

if ( $Conf -> validTimeFor('authorRespondToReviews', 0) ) {
  $Conf->linkWithPaperId("Respond To The Reviewers Comments",
			 "SubmitResponse.php",
			 $_REQUEST[paperId]);
}
print "</center>";


//
// Print header using dummy review
//

$Review=ReviewFactory($Conf, $_SESSION["Me"]->contactId, $_REQUEST[paperId]);

if ( ! $Review -> valid ) {
  $Conf->errorMsg("You've stumbled on to an invalid review? -- contact chair");
  exit;
}

print "<center>";
//
// Print review header with existing author response only if responses allowed
//
if ( $Conf -> validTimeFor('authorRespondToReviews', 0) ) {
  $Review->printAnonReviewHeader($Conf,1);
} else {
  $Review->printAnonReviewHeader($Conf,0);
}
print "</center>";

$result = $Conf->qe("SELECT PaperReview.contactId, "
		    . " PaperReview.reviewId, "
		    . " ContactInfo.firstName, ContactInfo.lastName, "
		    . " ContactInfo.email "
		    . " FROM PaperReview, ContactInfo $latetables "
		    . " WHERE PaperReview.paperId='$_REQUEST[paperId]'"
		    . " AND PaperReview.contactId=ContactInfo.contactId"
		    . " AND PaperReview.finalized=1 $laterestrict "
		    );

if (!DB::isError($result) && $result->numRows() > 0) {
  $header = 0;
  $reviewerId = array();

  $i = 1;
  while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $reviewer=$row['contactId'];
    $reviewId=$row['reviewId'];
    $first=$row['firstName'];
    $last=$row['lastName'];
    $email=$row['email'];

    $Review=ReviewFactory($Conf, $reviewer, $_REQUEST[paperId]);

    $lastModified=$Conf->printableTime($Review->reviewFields['timestamp']);

    print "<table width=100%>";

    if ($i & 0x1 ) {
      $color = $Conf->contrastColorOne;
    } else {
      $color = $Conf->contrastColorTwo;
    }

    print "<tr bgcolor=$color>";
    print "<th> <big> <big> Review #$reviewId For Paper #$_REQUEST[paperId] </big></big> </th>";
    print "</tr>";
    print "<tr bgcolor=$color>";
    print "<th> (review last modified $lastModified) </th> </tr>\n";
    
    print "<tr bgcolor=$color> <td> ";

    if ($Review->valid) {
      $Review -> authorViewReview($Conf);
    }

    print "</td> </tr>";
    $i++;
    print "</table>";
  }

}

if ( $Conf -> validTimeFor('authorRespondToReviews', 0) ) {
  print "<center>";
  $Conf->linkWithPaperId("Respond To The Reviewers Comments",
			 "SubmitResponse.php",
			 $_REQUEST[paperId]);
  print "</center>";
}

?>

<?php $Conf->footer() ?>

