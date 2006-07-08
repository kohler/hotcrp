<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();

function spotSecondaryReviewers($howmany)
{
  global $Conf;

  if ( $howmany == 0 ) {
    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper, SecondaryReviewer "
		       . " LEFT JOIN ReviewRequest "
		       . " ON ReviewRequest.paperId=Paper.paperId "
		       . " WHERE ReviewRequest.paperId IS NULL "
		       . " AND SecondaryReviewer.paperId=Paper.paperId "
		       . " AND SecondaryReviewer.reviewer=" . $_SESSION["Me"]->contactId. " "
		       . " ORDER BY Paper.paperId ");
  } else {
    $result =$Conf->qe(
		       " SELECT Paper.paperId, Paper.title "
		       . " FROM Paper, SecondaryReviewer "
		       . " LEFT JOIN ReviewRequest "
		       . " ON ReviewRequest.paperId=Paper.paperId "
		       . " WHERE SecondaryReviewer.paperId=Paper.paperId "
		       . " AND SecondaryReviewer.reviewer=" . $_SESSION["Me"]->contactId. " "
		       . " GROUP BY ReviewRequest.paperId "
		       . " HAVING COUNT(ReviewRequest.paperId)=$howmany "
		       . " ORDER BY Paper.paperId ");
  }

  if (!DB::isError($result)) {
    print "<table align=center width=80% border=1> ";
    print "<tr> <th colspan=2> Papers With $howmany Assigned Secondary </th> </tr>";
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";

      $Conf->linkWithPaperId($title,
			     "ShowAbstract.php",
			     $paperId);

      print "</td> </tr>";
    }
    print "</table>";
  }
}

function spotReviews($howmany, $finalized, $table)
{
  global $Conf;

  if ( $howmany == 0 ) {
    $query=
      " SELECT Paper.paperId, Paper.title "
      . " FROM Paper, $table "
      . " LEFT JOIN PaperReview "
      . " ON PaperReview.paperId=Paper.paperId "
      . " WHERE PaperReview.paperId IS NULL "
      . " AND $table" . ".paperId=Paper.paperId "
      . " AND $table" . ".contactId=" . $_SESSION["Me"]->contactId . " "
      . " ORDER BY Paper.paperId ";

  } else {
    if ( $finalized ) {
      $fin = " AND PaperReview.reviewSubmitted>0 ";
    }

    $query=
      " SELECT Paper.paperId, Paper.title "
      . " FROM Paper,$table "
      . " LEFT JOIN PaperReview "
      . " ON PaperReview.paperId=Paper.paperId "
      . " WHERE "
      . "     $table" . ".paperId=Paper.paperId "
      . " AND $table" . ".contactId=" . $_SESSION["Me"]->contactId . " "
      . $fin
      . " GROUP BY PaperReview.paperId "
      . " HAVING COUNT(PaperReview.paperId)=$howmany "
      . " ORDER BY Paper.paperId ";
  }

  //  print "<p> query is $query </p>";
  $result=$Conf->qe($query);

  if (!DB::isError($result)) {
    print "<table align=center width=80% border=1> ";
    if ( $finalized ) {
      print "<tr> <th colspan=2> Papers With $howmany Finalized $table Reviews </th> </tr>";
    } else {
      print "<tr> <th colspan=2> Papers With $howmany Started $table Reviews </th> </tr>";
    }
    while ($row = $result->fetchRow(DB_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";

      $Conf->linkWithPaperId($title,
			     "ShowAbstract.php",
			     $paperId);

      print "</td> </tr>";
    }
    print "</table>";
  }
}

?>

<html>
<?php  $Conf->header("Spot Problem Papers") ?>
<body>

<h1> Which papers do not have enough assigned secondary reviewers? </h1>

<p> These are papers for which you are supposed to find a secondary reviewer.
These tables simply show you how many "review requests" have been made.
If you've assigned all your secondary reviews, the first table will
be empty and you can ignore this.
</p>

<?php 
spotSecondaryReviewers(0);
print "<br> <br>";
spotSecondaryReviewers(1);
?>

<h1> Which papers do not have enough <b> <i> started </i> </b> reviews? </h1>

<h2> You're supposed to be reviewing the following papers (you're a primary reviewer) </h2>
<p> If you haven't done your reviews and you see entries in the "0" or "1" started
							   reviews, you
should get to work! </p>

<?php 
spotReviews(0,0,"PrimaryReviewer");
print "<br> <br>";
spotReviews(1,0,"PrimaryReviewer");
print "<br> <br>";
spotReviews(2,0,"PrimaryReviewer");
print "<br> <br>";
spotReviews(3,0,"PrimaryReviewer");
?>

<h2> You're supposed to be have assigned reviews for the following papers. </h2>
<p> If you see entries in these tables, it means your reviewers haven't started
and neither has anyone else -- start nagging now! </p>
<?php 
spotReviews(0,0,"SecondaryReviewer");
print "<br> <br>";
spotReviews(1,0,"SecondaryReviewer");
print "<br> <br>";
spotReviews(2,0,"SecondaryReviewer");
print "<br> <br>";
spotReviews(3,0,"SecondaryReviewer");
?>

<h1> Which papers do not have enough <b> <i> finished </i> </b> reviews? </h1>

<h2> You're supposed to be reviewing the following papers (you're a primary reviewer) </h2>
<p> If you haven't done your reviews and you see entries in the "0" or "1" started
							   reviews, you
should get to work! </p>

<?php 
spotReviews(0,1,"PrimaryReviewer");
print "<br> <br>";
spotReviews(1,1,"PrimaryReviewer");
print "<br> <br>";
spotReviews(2,1,"PrimaryReviewer");
print "<br> <br>";
spotReviews(3,1,"PrimaryReviewer");
?>

<h2> You're supposed to be have assigned reviews for the following papers. </h2>
<p> If you see entries in these tables, it means your reviewers haven't started
and neither has anyone else -- start nagging now! </p>
<?php 
spotReviews(0,1,"SecondaryReviewer");
print "<br> <br>";
spotReviews(1,1,"SecondaryReviewer");
print "<br> <br>";
spotReviews(2,1,"SecondaryReviewer");
print "<br> <br>";
spotReviews(3,1,"SecondaryReviewer");
?>

</body>
<?php  $Conf->footer() ?>
</html>
