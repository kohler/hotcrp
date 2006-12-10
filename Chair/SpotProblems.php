<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');

function spotSecondaryReviewers($howmany) {
    global $Conf;
    $q = "select Paper.paperId, Paper.title from Paper join PaperReview as MPR on (MPR.paperId=Paper.paperId and MPR.reviewType=" . REVIEW_SECONDARY . ") left join PaperReview as SPR on (SPR.paperId=Paper.paperId and SPR.requestedBy=MPR.contactId and SPR.reviewType=" . REVIEW_REQUESTED . ")";
    if ($howmany == 0)
	$q .= " where MPR.reviewModified is null and SPR.reviewId is null";
    else
	$q .= " where MPR.reviewModified is not null or SPR.reviewId is not null";
    $q .= " group by Paper.paperId order by Paper.paperId";
    $result = $Conf->qe($q);

  if ($result) {
    $count=edb_nrows($result);
    print "<table align=center width=80% border=1> ";
    print "<tr> <th colspan=2> There are $count Papers With $howmany Assigned Secondary </th> </tr>";
    while ($row = edb_arow($result)) {
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

function spotReviews($howmany, $finalized=0) {
    global $Conf, $reviewTypeName, $ConfSiteBase;
    $fin = ($finalized ? "reviewSubmitted" : "reviewModified");

    $query =
	"select Paper.paperId, Paper.title "
	. " from Paper"
	. " left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.$fin is not null) "
	. " where Paper.timeSubmitted>0 and Paper.timeWithdrawn<=0 "
	. " group by Paper.paperId having count(PaperReview.paperId)=$howmany "
	. " order by Paper.paperId ";

    //  print "<p> query is $query </p>";
    $result=$Conf->qe($query);

  if ($result) {
    $count=edb_nrows($result);
    print "<table align=center width=80% border=1> ";
    if ( $finalized ) {
      print "<tr> <th colspan=2> There are $count Papers With $howmany Finalized Reviews </th> </tr>";
    } else {
      print "<tr> <th colspan=2> There are $count Papers With $howmany Started Reviews </th> </tr>";
    }
    while ($row = edb_arow($result)) {
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

if (!$_SESSION["Me"]->isChair && !$Conf->timePCViewAllReviews()) {
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
