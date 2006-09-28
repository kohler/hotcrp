<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');

function spotSecondaryReviewers($howmany)
{
  global $Conf;

  if ( $howmany == 0 ) {
    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper "
		       . " LEFT JOIN ReviewRequest "
		       . " ON ReviewRequest.paperId=Paper.paperId "
		       . " WHERE ReviewRequest.paperId IS NULL "
		       . " ORDER BY Paper.paperId ");
  } else {
    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper "
		       . " LEFT JOIN ReviewRequest "
		       . " ON ReviewRequest.paperId=Paper.paperId "
		       . " GROUP BY ReviewRequest.paperId "
		       . " HAVING COUNT(ReviewRequest.paperId)=$howmany "
		       . " ORDER BY Paper.paperId ");
  }

  if (!DB::isError($result)) {
    $count=$result->numRows();
    print "<table align=center width=80% border=1> ";
    print "<tr> <th colspan=2> There are $count Papers With $howmany Assigned Secondary </th> </tr>";
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";

      $Conf->linkWithPaperId("$title",
			     "../review.php",
			     $paperId);

      print "</td> </tr>";
    }
    print "</table>";
  }
}

function spotReviews($howmany, $finalized=0)
{
  global $Conf;


  if ( $howmany == 0 ) {
    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper "
		       . " LEFT JOIN PaperReview "
		       . " ON PaperReview.paperId=Paper.paperId "
		       . " WHERE PaperReview.paperId IS NULL "
		       . " ORDER BY Paper.paperId ");

  } else {
    if ( $finalized ) {
      $fin = " AND PaperReview.reviewSubmitted>0 ";
    }

    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper "
		       . " LEFT JOIN PaperReview "
		       . " ON PaperReview.paperId=Paper.paperId "
		       . $fin
		       . " GROUP BY PaperReview.paperId "
		       . " HAVING COUNT(PaperReview.paperId)=$howmany "
		       . " ORDER BY Paper.paperId ");
  }
  if (!DB::isError($result)) {
    $count=$result->numRows();
    print "<table align=center width=80% border=1> ";
    if ( $finalized ) {
      print "<tr> <th colspan=2> There are $count Papers With $howmany Finalized Reviews </th> </tr>";
    } else {
      print "<tr> <th colspan=2> There are $count Papers With $howmany Started Reviews </th> </tr>";
    }
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";


      $Conf->linkWithPaperId("$title",
			     "../review.php",
			     $paperId);

      print "</td> </tr>";


    }
    print "</table>";
  }
}

if (! $_SESSION["Me"] -> isChair && ! $Conf -> validTimeFor('PCMeetingView', 0) ) {
  $Conf->infoMsg("You shouldn't be here");
  exit();
}

?>

<html>
<?php  $Conf->header("Spot Problem Papers") ?>
<body>

<h1> Which papers do not have enough assigned secondary reviewers? </h1>

<?php 
spotSecondaryReviewers(0);
print "<br> <br>";
spotSecondaryReviewers(1);
?>

<h1> Which papers do not have enough <b> <i> started </i> </b> reviews? </h1>
<?php 
spotReviews(0,0);
print "<br> <br>";
spotReviews(1,0);
print "<br> <br>";
spotReviews(2,0);
print "<br> <br>";
spotReviews(3,0);
?>

<h1> Which papers do not have enough <b> <i> finished </i> </b> reviews? </h1>
<?php 
spotReviews(0,1);
print "<br> <br>";
spotReviews(1,1);
print "<br> <br>";
spotReviews(2,1);
print "<br> <br>";
spotReviews(3,1);
?>

</body>
<?php  $Conf->footer() ?>
</html>
