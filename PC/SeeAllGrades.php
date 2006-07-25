<?php 
include('../Code/confHeader.inc');
$_SESSION["Me"] -> goIfInvalid($Conf->paperSite);
$_SESSION["Me"] -> goIfNotPC($Conf->paperSite);
$Conf -> connect();

// we are disabling register_globals in php.ini, so we don't need to
// register the following session variable anymore

// session_register('GradeSortKey');

if (IsSet($_REQUEST[setSortKey])) {
  $_SESSION["GradeSortKey"]=$_REQUEST[setSortKey];
}


?>

<html>

<?php  $Conf->header("See Overall Merit and Grades For All Papers") ?>

<body>


<?php 
print "<p> You can see this information ";
print $Conf->printableTimeRange('PCMeetingView');
print "</p>";

if ( ! $Conf -> validTimeFor('PCMeetingView', 0) ) {
  $Conf->errorMsg("You can not see this information right now");
  exit();
}
if (!IsSet($_SESSION["GradeSortKey"])) {
  $_SESSION["GradeSortKey"] = "byReviews";
}

$conflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);

$pcConflicts = $Conf->allPCConflicts();

$meritRange = $Conf->reviewRange('overAllMerit', 'PaperReview');
$gradeRange = $Conf->reviewRange('grade', 'PaperGrade');


if ($_SESSION["GradeSortKey"]=="byReviews") {
  $Conf->infoMsg("Sorting By Merit (as indicated by reviewers) ");
  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " AVG(PaperReview.overAllMerit) as merit "
		    . " FROM Paper "
		    . " LEFT JOIN PaperReview "
		    . " ON PaperReview.paperId=Paper.paperId "
		    . " WHERE PaperReview.reviewSubmitted>0 "
		    . " GROUP BY PaperReview.paperId "
		    . " ORDER BY merit DESC, Paper.paperId "
		    );

} elseif ($_SESSION["GradeSortKey"]=="byGrades") {
  $Conf->infoMsg("Sorting By Grades (as indicated by PC members)");
  $result=$Conf->qe("SELECT Paper.paperId, Paper.title, "
		    . " AVG(PaperGrade.grade) as merit "
		    . " FROM Paper "
		    . " LEFT JOIN PaperGrade "
		    . " ON PaperGrade.paperId=Paper.paperId "
		    . " GROUP BY PaperGrade.paperId "
		    . " ORDER BY merit DESC, Paper.paperId "
		    );

} elseif ($_SESSION["GradeSortKey"]=="byPapers") {
  $Conf->infoMsg("Sorting By Paper Number");
  $result=$Conf->qe("SELECT Paper.paperId, Paper.title "
		    . " FROM Paper "
		    . " ORDER BY Paper.paperId "
		    );

} else {
  $Conf->errorMsg("Invalid sort key");
  exit();
}

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit();
} 

$Conf->infoMsg("This shows the histograms scoring each paper and the grades "
	       . " assigned by PC members for all papers other than the "
	       . " ones for which you have an indicated conflict."
	       . " You can click on the column headers to sort by the "
	       . " specified key");

print "<center>";
$Conf->textButtonPopup("Click here to assign grades to papers ",
		       "GradePapers.php");
print "</center>";


if ( $Conf->validTimeFor('AtTheMeeting', 0) ) {
  $Conf->errorMsg("Papers authored by program committee members (and other "
		  . " conflicting papers ) are not shown at thise time. To "
		  . " maintain confidentiality, please do not discuss or "
		  . " distribute review information about papers you do not "
		  . " see listed here. ");
}
?>

<table border=1>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=6> All Papers </th> </tr>

<tr>
<th width=5%> Row # </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byPapers" > Paper # </a> </th>
<th width=25%> Title </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byReviews" > Merit </a> </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byGrades" > Grades </a> </th>
</tr>
<td> <b> 
<?php 
$rowNum = 0;
while ($row=$result->fetchRow()) {
  $rowNum++;
  $paperId = $row[0];
  $title = $row[1];

  //
  // Don't show the paper if there is a personal conflict OR
  // if it's the meeting and you're not the PC. Otherwise, another
  // program member can lean over and see the paper
  //
  $noShow = $conflicts[$paperId]
    || ( $Conf->validTimeFor('AtTheMeeting',0)
	 && $pcConflicts[$paperId]
	 && ! $_SESSION["Me"]->isChair );


  if ( ! $noShow ) {

    print "<tr> <td> $rowNum </td> ";
    print "<td> $paperId </td>";
    print "<td>\n";

    $Conf->linkWithPaperId($title,
                           "../PC/PCAllAnonReviewsForPaper.php",
                           $paperId);

    print "\n";

    $didBr = 0;
    if ( $_SESSION["Me"] ->isChair && $pcConflicts[$paperId] ) {
      print " <b> PC PAPER </b>\n ";
      $didBr = 1;
    }

    $comments = $Conf->countEntries("PaperComments.paperId",
				    $paperId, "PaperComments");
    if ( $comments > 0) {
      if (! $didBr) {
	print "<br>\n";
      }
      print " <b> $comments Comments </b>\n ";
    }

    print "</td>";


    print "<td align=center>";
    $q = "SELECT overAllMerit FROM PaperReview "
      . " WHERE paperId=$paperId "
      . " AND reviewSubmitted>0";
    $Conf->graphValues($q, "overAllMerit", $meritRange['min'], $meritRange['max']);


    print "</td>";

    print "<td align=center>";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId ";
    $Conf->graphValues($q, "grade", $gradeRange['min'], $gradeRange['max']);
    print "</td>";
    print "<tr> \n";
  }
}
?>
</table>

</body>
<?php  $Conf->footer() ?>
</html>

