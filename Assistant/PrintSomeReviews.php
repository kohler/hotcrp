<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

// we are disabling register_globals in php.ini, so we don't need to
// register the following session variable anymore

// session_register('GradeSortKey');

if (IsSet($_REQUEST[setSortKey])) {
  $_SESSION["GradeSortKey"]=$_REQUEST[setSortKey];
}

?>

<html>

<?php  $Conf->header("Select Reviews To Print") ?>

<body>

<table align=center>
<tr>
<td align=center colspan=2>
<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<INPUT TYPE=SUBMIT name=deselectAll value="Deselect All Papers">
</FORM>
</td>
<td align=center colspan=2>
<FORM METHOD=POST ACTION=<?php echo $_SERVER[PHP_SELF]?>>
<INPUT TYPE=SUBMIT name=selectAll value="Select All Papers">
</FORM>
</td>
</tr>
<?php 
print "</table>";

if ( $_REQUEST["ShowPCPapers"] ) {
  $conflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);
} else {
  $conflicts = $Conf->allPCConflicts();
}

if (!IsSet($_SESSION["GradeSortKey"])) {
  $_SESSION["GradeSortKey"] = "byReviews";
}

$pcConflicts = $Conf->allPCConflicts($_SESSION["Me"]->contactId);
if ( $_REQUEST["ShowPCPapers"] ) {
  $conflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);
} else {
  $conflicts = $pcConflicts;
}

if ($_SESSION["GradeSortKey"]=="byReviews") {
  $Conf->infoMsg("Sorting By Overall Merit (assigned by reviewers)");
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
  $Conf->infoMsg("Sorting By Grades (assigned by PC members)");
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

if (MDB2::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage() );
  exit();
} 

?>

<FORM METHOD=POST ACTION="PrintAllReviews.php">
<table border=1>
<tr>
<td align=center colspan=6>
<INPUT type=checkbox name=SeeOnlyFinalized value=1
   <?php  if ($_REQUEST["SeeOnlyFinalized"]) {echo "checked";}?> > See Only Finalized Reviews </br>

<INPUT type=checkbox name=SeeAuthorInfo value=1
   <?php  if ($_REQUEST["SeeAuthorInfo"]) {echo "checked";}?> > See Author Information </br>

<INPUT type=checkbox name=SeeReviewerInfo value=1
   <?php  if ($_REQUEST["SeeReviewerInfo"]) {echo "checked";}?> > See Reviewer Information </br>

<INPUT type=checkbox name=ShowPCPapers value=1
   <?php  if ($_REQUEST["ShowPCPapers"]) {echo "checked";}?> > Show PC Member Papers </br>

<INPUT TYPE=SUBMIT name=printThoseSuckers 
	value="Print The Reviews For Marked Papers"
	target=_blank	
>
</td>
</tr>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=6> All Papers </th> </tr>
<th width=5%> Row # </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byPapers"> Paper # </a> </th>
<th width=5%> Print? </th>
<th width=25%> Title </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byReviews"> Merit </a> </th>
<th width=5%> <a href="<?php echo $_SERVER[PHP_SELF]?>?setSortKey=byGrades"> Grades </a> </th>
</tr>
<td> <b> 
<?php
$rf = reviewForm();
$meritMax = $rf->maxNumericScore('overAllMerit');
$gradeMax = $rf->maxNumericScore('grade');


$rowNum = 0;
while ($row=$result->fetchRow()) {
  $rowNum++;
  $paperId = $row[0];
  $title = $row[1];
  if ( ! $conflicts[$paperId] ) {

    if ( IsSet($_REQUEST[selectAll]) ) {
      $_SESSION['paperReviewsToPrint'][$paperId] = 1;
    }

    if (IsSet($_REQUEST[deselectAll])) {
      $_SESSION['paperReviewsToPrint'][$paperId] = 0;
    }

    print "<tr> <td> $rowNum </td> <td> $paperId </td>";
    print "<td> <input type=checkbox name=paperReviewsToPrint[] ";
    if ( $_SESSION['paperReviewsToPrint'][$paperId] ) {
      print " CHECKED ";
    }
    print "value=$paperId>\n";
    print "<td> ";
    $Conf->linkWithPaperId($title, 
			   "../review.php", 
			   $paperId);
    if ( $pcConflicts[$paperId] ) {
      print "<br> <b> PC Paper </b>";
    }
    print "</td> \n";


    print "<td align=center>";
    $q = "SELECT overAllMerit FROM PaperReview "
      . " WHERE paperId=$paperId "
      . " AND reviewSubmitted>0";
    $Conf->graphValues($q, "overAllMerit", $meritMax);

    print "</td>";

    print "<td align=center>";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId ";
    $Conf->graphValues($q, "grade", $gradeMax);
    print "</td>";
    print "<tr> \n";
  }
}
?>
</table>
</FORM>

</body>
<?php  $Conf->footer() ?>
</html>

