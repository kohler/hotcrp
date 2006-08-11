<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");

if (! $_SESSION["Me"]->isChair ) {
  $Conf -> goIfInvalidActivity("reviewerViewDecision", "../index.php");
}

$Conf -> connect();

?>

<html>

<?php  $Conf->header("See Reviewer Outcome, Reviews and Comments For Paper #" . $_REQUEST["paperId"]) ?>

<body>

<?php 

if (!IsSet($_REQUEST["paperId"]) ) {
  $Conf -> errorMsg("You did not specify a paper to examine.");
  exit;
}
$paperId = $_REQUEST["paperId"];


$revByMe = $Conf->countEntries("paperId",
			      $paperId,
			      "PaperReview",
			      "AND contactId=" . $_SESSION["Me"]->contactId. " AND reviewSubmitted>0");


if ( $revByMe ) {
  //
  // Ok, I'm author...
  //
} else if ( $_SESSION["Me"] -> isChair ) {
    $Conf -> infoMsg("....chair exemption...");
} else {
  $Conf -> errorMsg("You did not review or finish reviewing this paper, so "
		    . " you can't see any of this information ");
  exit;
}

//
// Print header using dummy review
//

//$Review=ReviewFactory($Conf, $_SESSION["Me"]->contactId, $paperId);
$rf = reviewForm();
$prow = $Conf->paperRow($paperId, $_SESSION["Me"]->contactId);
if (!$prow) {
  $Conf->errorMsg("You've stumbled on to an invalid review? -- contact chair");
  exit;
}

$outcome = $prow->outcome;
if ($outcome == OUTCOME_ACCEPTED) {
  $Conf->confirmMsg("This paper was selected for the conference");
} else if ($outcome == OUTCOME_ACCEPTED_SHORT) {
  $Conf->confirmMsg("This paper was selected as a short paper for the conference");
} else if ($outcome == OUTCOME_REJECTED) {
  $Conf->errorMsg("This paper was not selected for the conference");
} else {
  $Conf->errorMsg("No outcome was specified for this paper");
}
//print "<center>";
//
// Print review header with existing author response
//
//$Review->printAnonReviewHeader($Conf,
//			       $Review->paperFields['showReponseToReviewers']
//			       );
//print "</center>";

$rrows = $Conf->reviewRow(array("paperId" => $paperId, "submitted" => 1, "array" => 1));


/* EDDIE
if ($Review -> paperFields['showReviewsToReviewers']) {
   $result = $Conf->qe("SELECT PaperReview.contactId, "
 		      . " PaperReview.reviewId, "
 		      . " ContactInfo.firstName, ContactInfo.lastName, "
 		      . " ContactInfo.email "
 		      . " FROM PaperReview, ContactInfo "
 		      . " WHERE PaperReview.paperId='$paperId'"
 		      . " AND PaperReview.contactId=ContactInfo.contactId"
 		      . " AND PaperReview.reviewSubmitted=1"
 		      );

if (!DB::isError($result) && $result->numRows()) */ {
    /*    $header = 0;
    $reviewerId = array();

    $i = 1;
    while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $reviewer=$row['contactId'];
      $reviewId=$row['reviewId'];
      $first=$row['firstName'];
      $last=$row['lastName'];
      $email=$row['email'];

      $Review=ReviewFactory($Conf, $reviewer, $paperId);
      print "<table width=100% >";

      if ($i & 0x1 ) {
	$color = $Conf->contrastColorOne;
      } else {
	$color = $Conf->contrastColorTwo;
      }

      print "<tr bgcolor=$color>";
      print "<th> <big> <big> Review #$reviewId For Paper #$paperId </big></big> </th>";
      print "</tr>";
    
      print "<tr bgcolor=$color> <td> ";

      if ($Review->valid) {
	$Review -> authorViewDecision($Conf);
      }

      print "</td> </tr>";
      $i++;
      print "</table>";
    }
    } */

    foreach ($rrows as $rr) {
	echo "<table class='review'>\n";
	echo $rf->webDisplayRows($rr, true);
	echo "</table>\n";
    }
}

//
// Now, print out the comments
//

$result = $Conf -> qe("SELECT *, UNIX_TIMESTAMP(time) as unixtime "
		      . " FROM PaperComments "
		      . " WHERE paperId=$paperId "
		      . " ORDER BY time ");

if (DB::isError($result) ) {
  $Conf->errorMsg("Error in SQL " . $result->getMessage());
}

if ($result->numRows() == 0) {
  //
  // No comment if there are none...
  //
  // $Conf->infoMsg("There are no comments");
} else {
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ($row['forReviewers']) {
      print "<table width=75% align=center>\n";

      $when = date ("l dS of F Y h:i:s A",
		    $row['unixtime']);

      print "<tr bgcolor=$Conf->infoColor>";
      print "<th align=left> $when </th>";
      print "<th align=right> Comment Left By Program Committee </th>";
      print "</tr>";
      print "<tr bgcolor=$Conf->contrastColorOne>\n";
      print "<td colspan=3>";
      print nl2br($row['comment']);
      print "</td>";
      print "</tr>";
      print "</table>";
      print "<br> <br>";
    }
  }
}
?>

<?php $Conf->footer() ?> 
