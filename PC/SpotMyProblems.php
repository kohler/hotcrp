<?php 
require_once('../Code/header.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC("../index.php");

function spotSecondaryReviewers($howmany) {
    global $Conf, $Me;
    $q = "select Paper.paperId, Paper.title from Paper join PaperReview as MPR on (MPR.paperId=Paper.paperId and MPR.contactId=" . $Me->contactId . " and MPR.reviewType=" . REVIEW_SECONDARY . ") left join PaperReview as SPR on (SPR.paperId=Paper.paperId and SPR.requestedBy=" . $Me->contactId . " and SPR.reviewType=" . REVIEW_REQUESTED . ")";
    if ($howmany == 0)
	$q .= " where MPR.reviewModified is null and SPR.reviewId is null";
    else
	$q .= " where MPR.reviewModified is not null or SPR.reviewId is not null";
    $q .= " group by Paper.paperId order by Paper.paperId";
    $result = $Conf->qe($q);

  if (!MDB2::isError($result)) {
    print "<table align=center width=80% border=1> ";
    print "<tr> <th colspan=2> Papers With $howmany Assigned Secondary </th> </tr>";
    while ($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";

      $Conf->linkWithPaperId($title,
			     "paper.php",
			     $paperId);

      print "</td> </tr>";
    }
    print "</table>";
  }
}

function spotReviews($howmany, $finalized, $reviewType) {
    global $Conf, $reviewTypeName, $ConfSiteBase;
    $fin = ($finalized ? "reviewSubmitted" : "reviewModified");

    $query=
	"select Paper.paperId, Paper.title "
	. " from Paper join PaperReview as MyReview on (MyReview.paperId=Paper.paperId and MyReview.contactId=" . $_SESSION["Me"]->contactId . ") "
	. " left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.$fin is not null) "
	. " group by Paper.paperId having count(PaperReview.paperId)=$howmany "
	. " order by Paper.paperId ";

    //  print "<p> query is $query </p>";
    $result=$Conf->qe($query);

  if (!MDB2::isError($result)) {
    print "<table align=center width=80% border=1> ";
    if ( $finalized ) {
      print "<tr> <th colspan=2> Papers With $howmany Finalized " . $reviewTypeName[$reviewType] . " Reviews </th> </tr>";
    } else {
      print "<tr> <th colspan=2> Papers With $howmany Started " . $reviewTypeName[$reviewType] . " Reviews </th> </tr>";
    }
    while ($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      $paperId=$row['paperId'];
      $title=$row['title'];
      print "<tr> <td> $paperId </td><td> ";

      $Conf->linkWithPaperId($title,
			     "${ConfSiteBase}review.php",
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
These tables simply show you how many "review requests" you've made.
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
spotReviews(0,0,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(1,0,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(2,0,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(3,0,REVIEW_PRIMARY);
?>

<h2> You're supposed to be have assigned reviews for the following papers. </h2>
<p> If you see entries in these tables, it means your reviewers haven't started
and neither has anyone else -- start nagging now! </p>
<?php 
spotReviews(0,0,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(1,0,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(2,0,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(3,0,REVIEW_SECONDARY);
?>

<h1> Which papers do not have enough <b> <i> finished </i> </b> reviews? </h1>

<h2> You're supposed to be reviewing the following papers (you're a primary reviewer) </h2>
<p> If you haven't done your reviews and you see entries in the "0" or "1" started
							   reviews, you
should get to work! </p>

<?php 
spotReviews(0,1,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(1,1,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(2,1,REVIEW_PRIMARY);
print "<br> <br>";
spotReviews(3,1,REVIEW_PRIMARY);
?>

<h2> You're supposed to be have assigned reviews for the following papers. </h2>
<p> If you see entries in these tables, it means your reviewers haven't started
and neither has anyone else -- start nagging now! </p>
<?php 
spotReviews(0,1,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(1,1,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(2,1,REVIEW_SECONDARY);
print "<br> <br>";
spotReviews(3,1,REVIEW_SECONDARY);
?>

</body>
<?php  $Conf->footer() ?>
</html>
