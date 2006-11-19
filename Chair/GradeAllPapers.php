<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotChair('../index.php');

// we are disabling register_globals in php.ini, so we don't need to
// register the following session variable anymore

// session_register('GradeSortKey');


$gradeName[0] = "-- PICK A VALUE --";
$gradeName[1] = "Clear Reject";
$gradeName[2] = "I say reject, but...";
$gradeName[3] = "Undecided..";
$gradeName[4] = "Needs Discussion";
$gradeName[5] = "Clear Accept";

$outcomeName[0] = "Unspecified";
$outcomeName[1] = "Accept";
$outcomeName[2] = "Reject";
$outcomeName[3] = "Short Paper";

$outcomeValue[0] = "unspecified";
$outcomeValue[1] = "accepted";
$outcomeValue[2] = "rejected";
$outcomeValue[3] = "acceptedShort";

//
// Handle grade changes
//

if ( IsSet($_REQUEST[gradeForPaper]) && IsSet($_REQUEST[paperId]) ) {
  $q = "DELETE FROM PaperGrade "
    . " WHERE paperId=$_REQUEST[paperId] AND contactId=" . $_SESSION["Me"]->contactId. " ";
  $Conf->qe($q);

  if ($_REQUEST[gradeForPaper] > 0) {
    $q = "INSERT INTO PaperGrade "
      . " SET paperId=$_REQUEST[paperId], contactId=" . $_SESSION["Me"]->contactId . ", grade=$_REQUEST[gradeForPaper] ";
    $Conf->qe($q);
  }
}

if ( IsSet($_REQUEST[outcomeForPaper]) && IsSet($_REQUEST[paperId]) ) {
  $q = "UPDATE Paper SET outcome='$_REQUEST[outcomeForPaper]' "
    . " WHERE paperId=$_REQUEST[paperId]";
  $Conf->qe($q);
}


if (IsSet($_REQUEST[setSortKey])) {
  $_SESSION["GradeSortKey"]=$_REQUEST[setSortKey];
}


?>

<html>

<?php  $Conf->header("Grade Or Accept/Reject All Papers") ?>

<body>
<FORM method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>">
<INPUT type=checkbox name=ShowGrades value=1
   <?php  if ($_REQUEST["ShowGrades"]) {echo "checked";}?> > Show Grades </br>

<INPUT type=checkbox name=ShowChairGrades value=1
   <?php  if ($_REQUEST["ShowChairGrades"]) {echo "checked";}?> > Show Chair Grades </br>

<INPUT type=checkbox name=ShowOutcome value=1
   <?php  if ($_REQUEST["ShowOutcome"]) {echo "checked";}?> > Show Outcome (Accept/Reject) </br>

<INPUT type=checkbox name=ShowPCPapers value=1
   <?php  if ($_REQUEST["ShowPCPapers"]) {echo "checked";}?> > Show PC Member Papers </br>

<input type="submit" value="Update View" name="submit">

</FORM>

<?php 

if (!IsSet($_SESSION["GradeSortKey"])) {
  $_SESSION["GradeSortKey"] = "byReviews";
}

$pcConflicts = $Conf->allPCConflicts();
if ( $_REQUEST["ShowPCPapers"] ) {
  $conflicts = $Conf->allMyConflicts($_SESSION["Me"]->contactId);
} else {
  $conflicts = $pcConflicts;
}

if ($_SESSION["GradeSortKey"]=="byReviews") {
  $Conf->infoMsg("Sorting By Overall Merit (assigned by reviewers)");
  $order = "ORDER BY overallMerit DESC, Paper.paperId ";
}
elseif ($_SESSION["GradeSortKey"]=="byGrades") {
  $Conf->infoMsg("Sorting By Grades (assigned by PC members)");
  $order = "ORDER BY grade DESC, Paper.paperId ";
} elseif ($_SESSION["GradeSortKey"]=="byPapers") {
  $Conf->infoMsg("Sorting By Paper Number");
  $order = "ORDER BY Paper.paperId ";
} elseif ($_SESSION["GradeSortKey"]=="byOutcome") {
  $Conf->infoMsg("Sorting By Paper Number");
  $order = "ORDER BY Paper.outcome ";
} else {
  $Conf->errorMsg("Invalid sort key");
  exit();
}

$result=$Conf->qe("SELECT Paper.paperId, Paper.title, Paper.outcome, "
. " AVG(PaperReview.overAllMerit) as overallMerit, "
. " AVG(PaperGrade.grade) as grade "
. " FROM Paper "
. "  LEFT JOIN PaperReview ON PaperReview.paperId=Paper.paperId "
. "  LEFT JOIN PaperGrade ON PaperGrade.paperId=Paper.paperId "
. " WHERE PaperReview.reviewSubmitted>0 "
. " GROUP BY PaperReview.paperId, PaperGrade.paperId "
. $order
);

if (MDB2::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit();
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

<?php 
  if ( $_REQUEST["ShowGrades"]) { 
    print "<th width=5%> <a href=\"$_SERVER[PHP_SELF]?setSortKey=byGrades\" > Grades </a> </th>\n";
  }
  if ( $_REQUEST["ShowChairGrades"] ) {
    print "<th width=5%> Your Grade </th>\n";
  }
  if ( $_REQUEST["ShowOutcome"] ) {
    print "<th width=5%> <a href=\"$_SERVER[PHP_SELF]?setSortKey=byOutcome\" > Outcome </a> </th>\n";
  }
?>
</tr>
<td> <b> 
<?php 
$rf = reviewForm();
$meritMax = $rf->maxNumericScore('overAllMerit');
$gradeMax = $rf->maxNumericScore('grade');

$rowNum = 0;
while ($row=$result->fetchRow(MDB2_FETCHMODE_ASSOC)){
  $rowNum++;
  $paperId = $row['paperId'];
  $title = $row['title'];
  $outcome = $row['outcome'];
  $grade = $row['grade'];

  if ( ! $conflicts[$paperId] ) {
    print "<tr> <td> $rowNum </td> <td> $paperId </td>";
    print "<td> ";

    $Conf->linkWithPaperId($title,
                           "../review.php",
                           $paperId);

    $didBr = 0;
    if ( $pcConflicts[$paperId] ) {
      print "<br> <b> PC Member Conflict </b>";
      $didBr = 1;
    }

    $comments = $Conf->countEntries("PaperComment.paperId",
				    $paperId, "PaperComment");
    if ( $comments > 0) {
      if (! $didBr) {
	print "<br>\n";
      }
      print " <b> $comments Comments </b>\n ";
    }

    $unfinished = $Conf->countEntries(
				      "PaperReview.paperId",
				      $paperId,
				      "PaperReview",
				      " AND PaperReview.reviewSubmitted=0 ");
    if ( $unfinished > 0) {
      if (! $didBr) {
	print "<br>\n";
      }
      print " <b> $unfinished Unfinished reviews </b>\n ";
    }
    print "</td> \n";


    print "<td align=center>";
    $q = "SELECT overAllMerit FROM PaperReview "
      . " WHERE paperId=$paperId "
      . " AND reviewSubmitted>0";
    $Conf->graphValues($q, "overAllMerit", $meritMax);

    print "</td>";

    if ( $_REQUEST["ShowGrades"] ) {
      print "<td align=center>";
      $q = "SELECT grade FROM PaperGrade "
        . " WHERE paperId=$paperId ";
      $Conf->graphValues($q, "grade", $gradeMax);
      print "</td>";
    }

    if ( $_REQUEST["ShowChairGrades"] ) {
      print "<td>";
      print "<FORM name=Paper$paperId method=\"POST\" action=\"$_SERVER[PHP_SELF]\">";
      $q = "SELECT grade FROM PaperGrade "
	. " WHERE paperId=$paperId AND contactId=" . $_SESSION["Me"]->contactId . " ";
      $r = $Conf->qe($q);
      if (! $r ) {
	$Conf->errorMsg("Bummer .. " . $result->getMessage());
      } else {
	$row = $r->fetchRow(MDB2_FETCHMODE_ASSOC);
	$grade = $row['grade'];
      }

      print "<input type=hidden name=paperId value=$paperId>\n";
      print "<input type=hidden name=ShowGrades value=$_REQUEST["ShowGrades"]>\n";
      print "<input type=hidden name=ShowChairGrades value=$_REQUEST["ShowChairGrades"]>\n";
      print "<input type=hidden name=ShowOutcome value=$_REQUEST["ShowOutcome"]>\n";
      print "<input type=hidden name=ShowPCPapers value=$_REQUEST["ShowPCPapers"]>\n";

      print "<SELECT NAME=gradeForPaper "
	. " onChange=document.Paper$paperId.submit() >\n";
      for ($i = 0; $i <= 5; $i++) {
	print "<OPTION value=$i ";
	if ($i == $grade) {
	  print " SELECTED ";
	}
	print "> $gradeName[$i] </OPTION>\n";
      }
      print "</SELECT>\n";
      print "</FORM>";
      print "</td>";
    }

    if ( $_REQUEST["ShowOutcome"] ) {
      print "<td>";
      print "<FORM name=Outcome$paperId method=\"POST\" action=\"$_SERVER[PHP_SELF]\">";

      print "<input type=hidden name=paperId value=$paperId>\n";
      print "<input type=hidden name=ShowGrades value=$_REQUEST["ShowGrades"]>\n";
      print "<input type=hidden name=ShowChairGrades value=$_REQUEST["ShowChairGrades"]>\n";
      print "<input type=hidden name=ShowOutcome value=$_REQUEST["ShowOutcome"]>\n";
      print "<input type=hidden name=ShowPCPapers value=$_REQUEST["ShowPCPapers"]>\n";

      print "<SELECT NAME=outcomeForPaper "
	. " onChange=document.Outcome$paperId.submit() >\n";
      print "<p> $outcome </p>";
      for ($i = 0; $i < 4; $i++) {
	print "<OPTION value=$outcomeValue[$i] ";
	if ($outcomeValue[$i] == $outcome) {
	  print " SELECTED ";
	}
	print "> $outcomeName[$i] </OPTION>\n";
      }
      print "</SELECT>\n";
      print "</FORM>";
      print "</td>";
    }
    print "<tr> \n";
  }
}
?>
</table>

</body>
<?php  $Conf->footer() ?>
</html>

