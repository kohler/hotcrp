<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC();
$Conf->goIfInvalidActivity("PCGradePapers", "../");

include('gradeNames.inc');

//
// Handle grade changes
//

if ( IsSet($_REQUEST['gradeForPaper']) ) {
  //$grades = array_unique($_REQUEST['gradeForPaper']);
  $grades = $_REQUEST['gradeForPaper'];

  foreach ( $grades as $paperId => $grade ) {
    $paperId = addSlashes( $paperId );
    $grade = addSlashes( $grade );

    $q = "DELETE FROM PaperGrade "
      . " WHERE paperId=$paperId AND contactId=" . $_SESSION["Me"]->contactId. " ";
    $Conf->qe($q);

    if ($grade > 0) {
      $q = "INSERT INTO PaperGrade "
	. " SET paperId=$paperId, contactId=" . $_SESSION["Me"]->contactId. ", grade='$grade' ";
      $Conf->qe($q);
    }
  }
}


$Conf->header("Grade Papers");

$Conf->infoMsg( "You may enter grades " .
		$Conf -> printableTimeRange('PCGradePapers') ); ?>

<table align='center' width='75%'>
<tr bgcolor=<?php echo $Conf->infoColor?>>
<th> How To Grade Papers </td>
</tr>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<td>
<P>
Please read in detail ALL of the reviews and the authors rebuttal.
After you have done that, please enter a PC grade for the paper.
During this process only PC members will be entering a PC grade.  If
your grade differs from other PC members, please send email and
discuss the issues over the next few days to arrive at an agreed upon
grade.  You can update the grades as often as you like up until the
end of the grading period specified by the above date.
</P><P>
In addition to entering a PC grade you can enter additional comments
for the program committee and/or authors to see.  These may be
additional comments you have after reading the other reviews or the
rebuttal, or additional comments to back up your PC grade.  To enter
additional comments click on the paper link, and enter comments at the
bottom.
</P>
<P>
You should only use "Accept, I will Champion this paper" if you are
willing to champion the paper for an accept at the PC meeting.
Likewise, you also should only use "Reject, I will argue against
accept" if you are willing to argue against the paper from being
accepted at the PC meeting.
</P>
<P>
Note, if you are not going to be at the PC meeting, and want to choose
an A or E for the PC grade, make sure you communicate to another PC
member your praises/arguments ( someone else who is also a reviewer of
the paper) so they can express them at the PC meeting.
</P>
</td>
</tr>
</table>

</table>
<br><br>

<?php 

$meritRange = $Conf->reviewRange('overAllMerit', 'PaperReview');
$gradeRange = $Conf->reviewRange('grade', 'PaperGrade');


$result=$Conf->qe("SELECT Paper.paperId, Paper.title FROM Paper join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$Me->contactId and PaperReview.reviewType=" . REVIEW_PRIMARY . ") order by Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql ");
  exit();
} 

print "<FORM name=Paper$paperId method=\"POST\" action=\"$_SERVER[PHP_SELF]\">";
?>

<table border=1>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=5> Primary Papers </th> </tr>

<tr>
<th width=1%> Paper # </th>
<th width=25%> Title </th>
<th width=5%> Merit </th>
<th width=5%> All Grades </th>
<th width=5%> Your Grade </th>
</tr>
<?php 
while ($row=$result->fetchRow()) {
  $paperId = $row[0];
  $title = $row[1];
  print "<tr> <td> $paperId </td>";
  print "<td> ";

  $Conf->linkWithPaperId($title,
			 "PCAllAnonReviewsForPaper.php",
			 $paperId);
  print "</td> \n";

  $count = $Conf->retCount("SELECT count(reviewSubmitted) "
			   . " FROM PaperReview "
			   . " WHERE PaperReview.paperId='$paperId'"
			   . " AND PaperReview.reviewSubmitted>0 "
			   );

  $done = $Conf->retCount("SELECT reviewSubmitted "
			  . " FROM PaperReview "
			  . " WHERE PaperReview.paperId='$paperId' "
			  . " AND PaperReview.contactId='" . $_SESSION["Me"]->contactId. "'"
			  );

  if ( $done ) {

    print "<td>";
    $q = "SELECT overAllMerit FROM PaperReview "
      . " WHERE paperId=$paperId "
      . " AND reviewSubmitted>0";
    $Conf->graphValues($q, "overAllMerit", $meritRange['min'], $meritRange['max']);

    print "</td>";

    print "<td>";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId ";
    $Conf->graphValues($q, "grade", $gradeRange['min'], $gradeRange['max']);
    print "</td>";

    print "<td>";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId AND contactId=" . $_SESSION["Me"]->contactId . " ";
      
    $r = $Conf->qe($q);
    if (DB::isError($r) ) {
      $Conf->errorMsg("Error in query");
    } else {
      $row = $r->fetchRow(DB_FETCHMODE_ASSOC);
      $grade = $row['grade'];
    }

    print "<SELECT NAME='gradeForPaper[$paperId]'>\n";
    for ($i = 5; $i >= 0; $i--) {
      print "<OPTION value=$i ";
      if ($i == $grade) {
	print " SELECTED ";
      }
      print "> $gradeName[$i] </OPTION>\n";
    }
    print "</SELECT>\n";
    print "</td>";

  } else {
    print "<td colspan=3>";
    print "You have not finished your review, so you cannot see the other reviews";
    print "</td>";
  }
  print "<tr> \n";
}
?>
</table>
<INPUT TYPE="SUBMIT" VALUE="Update Grades">
</FORM>
<?php if( 0 ) { ?>

<p> </p>

<?php 
$Conf->infoMsg("You can assign grades for any paper for which you are a secondary reviewer"
. " once your secondary reviewer finishes the review (or if you did the review yourself) "
);

$result=$Conf->qe("SELECT Paper.paperId, Paper.title "
		  . " FROM Paper join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$Me->contactId and PaperReview.reviewType=" . REVIEW_SECONDARY . ") order by Paper.paperId");

if (DB::isError($result)) {
  $Conf->errorMsg("Error in sql" );
  exit();
} 
?>

<table border=1>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=5> Secondary Papers </th> </tr>

<tr>
<th width='1%'> Paper # </th>
<th width='25%'> Title </th>
<th width='5%'> Merit </th>
<th width='5%'> All Grades </th>
<th width='5%'> Your Grade </th>
</tr>
<?php 
while ($row=$result->fetchRow()) {
  $paperId = $row[0];
  $title = $row[1];
  print "<tr> <td> $paperId </td>";
  print "<td> ";
  print "<a href=\"PCAllAnonReviewsForPaper.php?paperId=$paperId\" target=_blank>";
  print "$title </a> </td> \n";

  $count = $Conf->retCount("SELECT Count(reviewSubmitted) "
			   . " FROM PaperReview "
			   . " WHERE PaperReview.paperId='$paperId'"
			   . " AND PaperReview.reviewSubmitted>0 "
			   );

  $done = $Conf->retCount("SELECT PaperReview.reviewSubmitted "
			  . " FROM PaperReview, ReviewRequest "
			  . " WHERE PaperReview.paperId='$paperId' "
			  . " AND ReviewRequest.paperId=$paperId "
			  . " AND ReviewRequest.requestedBy='" . $_SESSION["Me"]->contactId . "'"
			  . " AND PaperReview.contactId=ReviewRequest.asked "
			  );

  $doneByMe = $Conf->retCount("SELECT PaperReview.reviewSubmitted "
			  . " FROM PaperReview "
			  . " WHERE PaperReview.paperId='$paperId' "
			  . " AND PaperReview.contactId='" . $_SESSION["Me"]->contactId . "'"
			  );

  if ( $done || $doneByMe ) {

    print "<td>";
    $q = "SELECT overAllMerit FROM PaperReview "
      . " WHERE paperId=$paperId "
      . " AND reviewSubmitted>0";
    $Conf->graphValues($q, "overAllMerit", $meritRange['min'], $meritRange['max']);


    print "</td>";

    print "<td>";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId ";
    $Conf->graphValues($q, "grade", $gradeRange['min'], $gradeRange['max']);
    print "</td>";

    print "<td>";
    print "<FORM name=Paper$paperId method=\"POST\" action=\"$_REQUEST[PHP_SELF]\">";
    $q = "SELECT grade FROM PaperGrade "
      . " WHERE paperId=$paperId AND contactId=" . $_SESSION["Me"]->contactId . " ";
      
    $r = $Conf->qe($q);
    if (DB::isError($r) ) {
      $Conf->errorMsg("Error in query");
    } else {
      $row = $r->fetchRow(DB_FETCHMODE_ASSOC);
      $grade = $row['grade'];
    }

    print "<SELECT NAME='gradeForPaper[$paperId]'>\n";
    for ($i = 5; $i >= 0; $i--) {
      print "<OPTION value=$i ";
      if ($i == $grade) {
	print " SELECTED ";
      }
      print "> $gradeName[$i] </OPTION>\n";
    }
    print "</SELECT>\n";
    print "</FORM>";
    print "</td>";

  } else {
    print "<td colspan=3>";
    print "Your secondary reviewer hasn't finished their review, so you can't assign a grade yet";
    print "</td>";
  }
  print "<tr> \n";
}
?>
</table>
<?php } ?>



</body>
<?php  $Conf->footer() ?>
</html>

