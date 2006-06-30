<?php 
include('../Code/confHeader.inc');
include('../Code/Calendar.inc');
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotChair('../index.php');
$Conf -> connect();
include('Code.inc');



$DateDescr['startPaperSubmission']
= " Note that all papers must be 'started' before they"
. " can be updated or finalized. "
. " If you want to force authors to submit author information "
. " and an abstract prior to the final submission date, set the "
. " end time for this range to the time when abstracts are due. ";

$DateDescr['updatePaperSubmission']
= "If you want to allow authors to start a paper and then "
. "update it before the final deadline, set this range to "
. "that time. The starting time of this should usually be "
. "the same as the time to start papers. ";

$DateDescr['finalizePaperSubmission']
= "Authors 'finalize' their submission to indicate that this "
. "was a complete submission - i.e. they didn't just start a paper "
. "and then wander off. It's usually a good idea to set the time to "
. "finalize a paper to be a day or two after the submissiond deadine "
;

$DateDescr['authorViewReviews']
= "If you want authors to be able to see their reviews, set this time range";

$DateDescr['authorRespondToReviews']
= "If you want authors to be able to respond to reviews, set this time range. "
. "This should obviously overlap with being able to see the reviws"
;

$DateDescr['authorViewDecision']
= "If you want authors to be able to log in to see review decisions, set this time range. "
. "You can also send authors mail based on whether their paper was accepted or not."
;

$DateDescr['PCReviewAnyPaper']
= "If you want the PC members to be able to review ANY PAPER that does not"
. " have conflicts indicated, set this date range. "
;

function showDate($name, $label)
{
  global $Conf;
  global $DateDescr;

  print "<FORM METHOD=POST ACTION=$_SERVER[PHP_SELF]>";
  print "<table align=center width=95% ";
  print "    bgcolor=$Conf->contrastColorOne border=1>";
  print "<tr>";
  print "<td align=center colspan=2> <b> <big> $label </big> </b> </td>";
  print "</tr>";
  print "<tr>";

  if (IsSet($DateDescr[$name])) {
    print "<tr>";
    print "<td colspan=2> $DateDescr[$name] </td>";
    print "</tr>";
    print "<tr>";
  }

  if ( IsSet($Conf->startTime[$name]) ) {
    $start = $Conf->parseableTime($Conf->startTime[$name]);
  } else {
    $start = "Not Set";
  }
  if ( IsSet($Conf->endTime[$name]) ) {
    $end = $Conf->parseableTime($Conf->endTime[$name]);
  } else {
    $end = "Not Set";
  }

  print "<tr> <td width=30%> From </td> <td align=center> ";
  print "<input type=text name='startTime' value='$start' size=50> </td>";
  print "     <td align=center> ";
  print "</tr>";

  print "<tr> <td> To </td> <td align=center> ";
  print "<input type=text name='endTime' value='$end' size=50> </td>";
  print "     <td align=center> ";
  print "</tr>";

  print "<tr> <td colspan=2> If you want to change either date, ";
  print "modify the text box and then hit ";
  print "<input type=submit name='modifyDate' value='Modify Date'>.";
  print "<br>";
  print "If you want to remove this date, hit ";
  print "<input type=submit name='removeDate' value='Remove Date'>.";
  print "<input type=hidden name='dateToModify' value='$name'>";
  print "</td> </tr>";

  print "</table>";
  print "</FORM>";
}

?>

<html>

<?php  $Conf->header("Set Important Conference Dates") ?>

<body>

<?php 
//
// Now catch any modified dates
//

if (IsSet($_REQUEST[removeDate])) {
  $Conf->qe("DELETE FROM ImportantDates WHERE name='$_REQUEST[dateToModify]'");
}

if (IsSet($_REQUEST[modifyDate])) {
  $start = strtotime($_REQUEST[startTime]);
  $end = strtotime($_REQUEST[endTime]);

  if ($start == -1 || $end == -1) {
    if ( $start == -1 && $end != -1) {
      $Conf->errorMsg("There was an error in your "
		      . "starting date speciciation. ");
    } else if ( $start != -1 && $end == -1) {
      $Conf->errorMsg("There was an error in your "
		      . "ending date speciciation. ");
    } else {
      $Conf->errorMsg("There was an error in both your "
		      . "starting and ending date speciciation. ");
    }
  } else {
    $Conf->qe("DELETE FROM ImportantDates WHERE name='$_REQUEST[dateToModify]'");
    $Conf->qe("INSERT INTO ImportantDates SET name='$_REQUEST[dateToModify]', start=from_unixtime($start), end=from_unixtime($end)");
  }
}

$Conf->updateImportantDates();

?>

<table>
<tr> <td> <p> Need a popup calendar for reference? </p> </td>
<td>
<?php $Conf->textButtonPopup("Click here!",
			 "ShowCalendar.php", "")?>
</td> </tr>
<tr> <td> <p> Need to understand how to specify a time and date? </p> </td>
<td>
<?php $Conf->textButtonPopup("Click here!",
			 "http://www.php.net/manual/en/function.strtotime.php", "")?>
</td> </tr>
</table>

<hr>

<table align=center width=100% bgcolor=<?php echo $Conf->contrastColorTwo?>
   cellspacing=2>

<tr> <td align=center> <big> Dates Affecting Authors </big> </td> </tr>

<tr> <td>
<?php  showDate('startPaperSubmission',
	    "When paper submissions can be started?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('updatePaperSubmission',
	    "When can a paper be updated?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('finalizePaperSubmission',
	    "When can a paper be finalized?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('authorViewReviews',
	    "When can authors see their reviews?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('authorRespondToReviews',
	    "When can the author respond to reviews?");
?>

<tr> <td>
<?php  showDate('authorViewDecision',
	    "When can the author view the program committe decision?");
?>
</td> </tr>
</table>

<br>

<table align=center width=100% bgcolor=<?php echo $Conf->contrastColorTwo?>
   cellspacing=2>

<tr> <td align=center> <big> Dates Affecting Peer Reviews </big> </td> </tr>


<tr> <td>
<?php  showDate('reviewerSubmitReview',
	    "This is the ADVERTISED deadline for peer reviews.");
?>
</td> </tr>

<tr> <td>
<?php  showDate('reviewerSubmitReviewDeadline',
	    "This is the ACTUAL deadline for peer reviews.");
?>
</td> </tr>

<tr> <td>
<?php  showDate('notifyChairAboutReviews',
	    "When should the chair be notified about new reviews?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('reviewerViewDecision',
	    "When can the reviewers see the rebuttal and paper outcome?");
?>
</td> </tr>

</table>

<br>

<table align=center width=100% bgcolor=<?php echo $Conf->contrastColorTwo?>
   cellspacing=2>

<tr> <td align=center> <big> Dates Affecting The Program Committee </big> </td> </tr>


<tr> <td>
<?php  showDate('PCSubmitReview',
	    "This is the ADVERTISED deadline for program committee reviews.");
?>
</td> </tr>

<tr> <td>
<?php  showDate('PCSubmitReviewDeadline',
	    "This is the ACTUAL deadline for program committee reviews.");
?>
</td> </tr>

<tr> <td>
<?php  showDate('PCReviewAnyPaper',
	    "When can PC members review any paper?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('PCViewAllPapers',
	    "When can PC members see all non-conflicting papers and reviews?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('PCGradePapers',
	    "When can PC members grade papers?");
?>
</td> </tr>

<tr> <td>
<?php  showDate('PCMeetingView',
	    "When can PC members see the identity of reviews for"
	    . " non-conflicting papers and assign paper grades.");
?>
</td> </tr>

<tr> <td>
<?php  showDate('AtTheMeeting',
	    "When is the PC meeting (used to hide information about chair opinions) ");
?>
</td> </tr>

<tr> <td>
<?php  showDate('EndOfTheMeeting',
	    "The very end of the PC meeting (look at accepted papers)");
?>
</td> </tr>


</table>




</body>


<?php  $Conf->footer() ?>
</html>

