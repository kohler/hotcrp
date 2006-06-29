<?php 
include('../Code/confHeader.inc');
$_SESSION[Me] -> goIfInvalid("../index.php");
if ( !$_SESSION[Me]->isChair ) {
  $_SESSION[Me] -> goIfNotAuthor("../index.php");
  $Conf -> goIfInvalidActivity("authorViewDecision", "../index.php");
}
$Conf -> connect();
include('../Code/confConfigReview.inc');

?>

<html>

<?php  $Conf->header("See Outcome, Reviews and Comments For Paper #$_REQUEST[paperId]") ?>

<body>

<?php 

if (!IsSet($_REQUEST[paperId]) ) {
  $Conf -> errorMsg("You did not specify a paper to modify.");
  exit;
}

if ( $_SESSION[Me] -> amPaperAuthor($_REQUEST[paperId], $Conf) ) {
  //
  // Ok, I'm author...
  //
} else if ( $_SESSION[Me] -> isChair ) {
    $Conf -> infoMsg("....chair exemption...");
} else {
  $Conf -> errorMsg("Only the submitting paper author can examine the "
		    . "paper information.");
  exit;
}

if (IsSet($updateReviewerView)) {
  if (!IsSet($showResponseToReviewers)) {
    $showResponseToReviewers = 0;
  }

  if (!IsSet($showReviewsToReviewers)) {
    $showReviewsToReviewers = 0;
  }

  $q = ("UPDATE Paper SET "
	. " showResponseToReviewers=$showResponseToReviewers, "
	. " showReviewsToReviewers=$showReviewsToReviewers "
	. " WHERE paperId=$_REQUEST[paperId]");
  $Conf->qe($q);
}

//
// Print header using dummy review
//

$Review=ReviewFactory($Conf, $_SESSION[Me]->contactId, $_REQUEST[paperId]);

if ( ! $Review -> valid ) {
  $Conf->errorMsg("You've stumbled on to an invalid review? -- contact chair");
  exit;
}

$outcome = $Review->paperFields['outcome'];
if ($outcome == "accepted") {
  $Conf->confirmMsg("This paper was selected for the conference");
} else if ($outcome == "acceptedShort") {
  $Conf->confirmMsg("This paper was selected as a short paper for the conference");
} else if ($outcome == "rejected") {
  $Conf->errorMsg("This paper was not selected for the conference");
} else {
  $Conf->errorMsg("No outcome was specified for this paper");
}

if (1 || $Conf->validTimeFor('reviewerViewDecision', 0) ) {
  $Conf -> infoMsg("EDUCATING REVIEWERS: Reviewing papers is a "
		   . " difficult process, and it is sometimes difficult "
		   . " for reviewers to determine if their review was "
		   . " appropriate or to understand why a paper was rejected "
		   . " or accepted. If you would like to allow the reviewers "
		   . " for your specific paper to see either the author response "
		   . " or the other reviews, select the appropriate options below. "
		   . " This information is only available to reviewers of your paper, "
		   . " and unless you or reviewers have revealed any information, "
		   . " the information is anonymous. ");

  $showResponse = $Review->paperFields['showResponseToReviewers'];
  $showReviews  = $Review->paperFields['showReviewsToReviewers'];
  print "<table align=center width=50% border=1>\n";
  print "<tr> <td> \n";
  print "<FORM name=Checks method=\"POST\" action=\"$_SERVER[PHP_SELF]\">\n";
  print "<input type=hidden name=paperId value=$_REQUEST[paperId]>\n";
  print "<input type=checkbox name=showResponseToReviewers value=1 " ;
  if ( $showResponse ) print " CHECKED ";
  print "> Allow reviewers to see your response to all reviews";
  print "</td> </tr> <tr> <td>\n";
  print "<input type=checkbox name=showReviewsToReviewers value=1 " ;
  if ( $showReviews ) print " CHECKED ";
  print "> Allow reviewers to see all reviews";
  print "</td> </tr>\n";
  print "<tr> <td align=center>";
  print "<input type=submit name=updateReviewerView value='Update Choices'>\n";
  print "</td> </tr>";
  print "</FORM>\n";
  print "</table>";
  print "<br> <br> <br>\n";
}

print "<center>";
//
// Print review header with existing author response
//
$Review->printAnonReviewHeader($Conf,1);
print "</center>";

$result = $Conf->qe("SELECT PaperReview.reviewer, "
		    . " PaperReview.paperReviewId, "
		    . " ContactInfo.firstName, ContactInfo.lastName, "
		    . " ContactInfo.email "
		    . " FROM PaperReview, ContactInfo "
		    . " WHERE PaperReview.paperId='$_REQUEST[paperId]'"
		    . " AND PaperReview.reviewer=ContactInfo.contactId"
		    . " AND PaperReview.finalized=1"
		    );

$num_reviews = $result->numRows();

if (!DB::isError($result) && $result->numRows() > 0) {
  $header = 0;
  $reviewerId = array();

  $i = 1;
  while($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
    $reviewer=$row['reviewer'];
    $reviewId=$row['paperReviewId'];
    $first=$row['firstName'];
    $last=$row['lastName'];
    $email=$row['email'];

    $Review=ReviewFactory($Conf, $reviewer, $_REQUEST[paperId]);
    print "<table width=100% >";

    if ($i & 0x1 ) {
      $color = $Conf->contrastColorOne;
    } else {
      $color = $Conf->contrastColorTwo;
    }

    print "<tr bgcolor=$color>";
    print "<th> <big> <big> Review #$reviewId For Paper #$_REQUEST[paperId] </big></big> </th>";
    print "</tr>";
    
    print "<tr bgcolor=$color> <td> ";

    if ($Review->valid) {
      $Review -> authorViewDecision($Conf);
    }

    print "</td> </tr>";
    $i++;
    print "</table>";
  }
}

//
// Now, print out the comments
//

$result = $Conf -> qe("SELECT *, UNIX_TIMESTAMP(time) as unixtime "
		      . " FROM PaperComments "
		      . " WHERE paperId=$_REQUEST[paperId] "
		      . " ORDER BY time ");

if (DB::isError($result) ) {
  $Conf->errorMsg("Error in SQL " . $result->getMessage());
}

if ($result->numRows() < 1) {
  //
  // No comment if there are none...
  //
  // $Conf->infoMsg("There are no comments");
} else {
  while ($row=$result->fetchRow(DB_FETCHMODE_ASSOC)) {
    if ($row['forAuthor']) {
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

<?php  $Conf->footer() ?> 
</body>
</html>
