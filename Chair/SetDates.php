<?php 
include('../Code/confHeader.inc');
include('../Code/Calendar.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../index.php");
$_SESSION["Me"]->goIfNotChair('../index.php');
include('Code.inc');

$DateStartMap = array("updatePaperSubmission" => "startPaperSubmission",
		      "finalizePaperSubmission" => "startPaperSubmission");

$DateName['startPaperSubmission'][0] = "Submissions open";
$DateName['startPaperSubmission'][1] = "Deadline for creating new submissions";
$DateName['updatePaperSubmission'][1] = "Deadline for updating submissions";
$DateName['finalizePaperSubmission'][1] = "Deadline for finalizing submissions";
$DateName['authorViewReviews'] = "Reviews visible";
$DateName['authorRespondToReviews'] = "Responses allowed";
$DateName['authorViewDecision'] = "Decision visible";

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

function showDate($name, $label) {
  global $Conf;
  global $DateDescr;

  print "<FORM METHOD=POST ACTION=$_SERVER[PHP_SELF]>";
  print "<table align=center width=95% ";
  print "    bgcolor=$Conf->contrastColorOne border=1>";
  print "<tr>";
  print "<td align=center colspan=2> <b> <big> $label </big> </b> </td>";
  print "</tr>";
  print "<tr>";

  if (isset($DateDescr[$name])) {
    print "<tr>";
    print "<td colspan=2> $DateDescr[$name] </td>";
    print "</tr>";
    print "<tr>";
  }

  if (isset($Conf->startTime[$name]) ) {
    $start = $Conf->parseableTime($Conf->startTime[$name]);
  } else {
    $start = "Not Set";
  }
  if (isset($Conf->endTime[$name]) ) {
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

function crp_dateview($name, $end) {
    global $Conf;
    $var = ($end ? $Conf->endTime : $Conf->startTime);
    $tname = $name . ($end ? "_end" : "_start");
    if (isset($_REQUEST[$tname]))
	return $_REQUEST[$tname];
    else if (isset($var[$name]))
	return $Conf->parseableTime($var[$name]);
    else
	return "N/A";
}

function crp_showdate($name) {
    global $Conf, $DateDescr, $DateName, $DateError;
    $label = preg_replace('/ /', '&nbsp;', $DateName[$name]);

    echo "<tr>\n";
    echo "  <td class='datename'>$label</td>\n";
    $formclass = isset($DateError["${name}_start"]) ? "form_caption_error" : "form_caption";
    echo "  <td class='$formclass'>From:</td>\n";
    echo "  <td class='form_entry'><input type='text' name='${name}_start' id='${name}_start' value='", htmlspecialchars(crp_dateview($name, 0)), "' size='20' onchange='highlightUpdate()' /></td>\n";
    $formclass = isset($DateError["${name}_end"]) ? "form_caption_error" : "form_caption";
    echo "  <td class='$formclass'>To:</td>\n";
    echo "  <td class='form_entry'><input type='text' name='${name}_end' id='${name}_end' value='", htmlspecialchars(crp_dateview($name, 1)), "' size='20' onchange='highlightUpdate()' /></td>\n";
    echo "  <td><button class='button' type='button' onclick='javascript: clearDates(\"", $name, "\")'>Clear</button></td>\n";
    echo "  <td width='100%'></td>\n";
    echo "</tr>\n";

    if (isset($DateDescr[$name])) {
	echo "<tr>\n";
	echo "  <td colspan='7' class='datedesc'>", $DateDescr[$name], "</td>\n";
	echo "</tr>\n";
    }
}

function crp_show1date($name, $which) {
    global $Conf, $DateDescr, $DateName, $DateError;
    $namex = $name . ($which ? "_end" : "_start");
    $label = preg_replace('/ /', '&nbsp;', $DateName[$name][$which]);

    echo "<tr>\n";
    $formclass = isset($DateError["$namex"]) ? "datename_error" : "datename";
    echo "  <td class='$formclass' colspan='2'>$label</td>\n";
    echo "  <td class='form_entry'><input type='text' name='${namex}' id='${namex}' value='", htmlspecialchars(crp_dateview($name, $which)), "' size='20' onchange='highlightUpdate()' /></td>\n";
    echo "  <td class='form_caption'></td>\n";
    echo "  <td class='form_entry'></td>\n";
    echo "  <td><button class='button' type='button' onclick='javascript: clear1Date(\"", $namex, "\")'>Clear</button></td>\n";
    echo "  <td width='100%'></td>\n";
    echo "</tr>\n";

    if (isset($DateDescr[$name])) {
	echo "<tr>\n";
	echo "  <td colspan='7' class='datedesc'>", $DateDescr[$name], "</td>\n";
	echo "</tr>\n";
    }
}

$Conf->header_head("Set Dates");
?>
<script type="text/javascript"><!--
function highlightUpdate() {
    document.getElementById("blerg").className = "button_alert";
}
function clear1Date(name) {
    document.getElementById(name).value = "N/A";
}
function clearDates(name) {
    clear1Date(name + "_start");
    clear1Date(name + "_end");
}
// -->
</script>

<?php $Conf->header("Set Conference Dates", 0); ?>
<div id='body'>

<?php 
//
// Now catch any modified dates
//

function crp_strtotime($tname, $which) {
    global $Error, $DateName, $DateStartMap, $DateError;
    
    $req_tname = $tname;
    if ($which == 0 && isset($DateStartMap[$tname]))
	$req_tname = $DateStartMap[$tname];
    $varname = $req_tname . ($which ? "_end" : "_start");

    if (!isset($_REQUEST[$varname]))
	$err = "missing from form";
    else {
	$t = ltrim(rtrim($_REQUEST[$varname]));
	if ($t == "" || strtoupper($t) == "N/A")
	    return -1;
	else if (($t = strtotime($t)) >= 0)
	    return $t;
	else
	    $err = "parse error";
    }
    
    $DateError[$varname] = 1;
    if ($req_tname != $tname)
	/* do nothing */;
    else if (is_array($DateName[$tname]))
	$Error[] = $DateName[$tname][$which] . " " . $err . ".";
    else
	$Error[] = $DateName[$tname] . ($which ? " (end) " : " (start) ") . $err . ".";
    return -1;
}

if (isset($_REQUEST['update'])) {
    $Error = array();
    $Dates = array();
    $Messages = array();
    foreach (array('startPaperSubmission', 'updatePaperSubmission',
		   'finalizePaperSubmission', 'authorViewReviews',
		   'authorRespondToReviews', 'authorViewDecision') as $s) {
	$Dates[$s][0] = crp_strtotime($s, 0);
	$Dates[$s][1] = crp_strtotime($s, 1);
	if ($Dates[$s][1] > 0 && $Dates[$s][0] < 0) {
	    $today = getdate();
	    $Dates[$s][0] = mktime(0, 0, 0, 1, 1, $today["year"]);
	    if (!isset($DateStartMap[$s]))
		$Messages[] = (is_array($DateName[$s]) ? $DateName[$s][0] : $DateName[$s] . " begin") . " missing; set to the beginning of this year.";
	} else if ($Dates[$s][1] > 0 && $Dates[$s][1] < $Dates[$s][0]) {
	    $Error[] = (is_array($DateName[$s]) ? $DateName[$s][1] : $DateName[$s]) . " period ends before it begins.";
	    $DateError["${s}_end"] = 1;
	}
    }
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br/>\n", $Error));
    else {
	// specific date validation
	foreach (array("updatePaperSubmission" => "startPaperSubmission",
		       "finalizePaperSubmission" => "updatePaperSubmission",
		       "updatePaperSubmission" => "finalizePaperSubmission",
		       "startPaperSubmission" => "updatePaperSubmission")
		 as $dest => $src) {
	    if ($Dates[$dest][1] < 0 && $Dates[$src][1] > 0) {
		$Dates[$dest][1] = $Dates[$src][1];
		$Messages[] = $DateName[$dest][1] . " set to " . $DateName[$src][1] . ".";
	    }
	}
	
	if (count($Messages) > 0)
	    $Conf->infoMsg(join("<br/>\n", $Messages));
	$result = $Conf->qe("delete from ImportantDates");
	if (!DB::isError($result)) {
	    foreach ($Dates as $n => $v) {
		$sx = ($v[0] > 0 ? "from_unixtime($v[0])" : "'0'");
		$ex = ($v[1] > 0 ? "from_unixtime($v[1])" : "'0'");
		$Conf->qe("insert into ImportantDates set name='$n', start=$sx, end=$ex");
		unset($_REQUEST["${n}_start"]);
		unset($_REQUEST["${n}_end"]);
	    }
	}
    }
}

$Conf->updateImportantDates();
?>

<table>
<tr> <td>Need a popup calendar for reference?</td>
<td>
<?php $Conf->textButtonPopup("Click here!",
			 "ShowCalendar.php", "")?>
</td> </tr>
<tr> <td>Need to understand how to specify a time and date?</td>
<td>
<?php $Conf->textButtonPopup("Click here!",
			 "http://www.php.net/manual/en/function.strtotime.php", "")?>
</td> </tr>
</table>


<h2>Dates Affecting Authors</h2>

<form class='date' method='post' action='SetDates.php'>
<table class='imptdates'>
<?php crp_show1date('startPaperSubmission', 0); ?>
<?php crp_show1date('startPaperSubmission', 1); ?>
<?php crp_show1date('updatePaperSubmission', 1); ?>
<?php crp_show1date('finalizePaperSubmission', 1); ?>
<?php crp_showdate('authorViewReviews'); ?>
<?php crp_showdate('authorRespondToReviews'); ?>
<?php crp_showdate('authorViewDecision'); ?>
</table>

<input id="blerg" class='button_default' type='submit' value='Update' name='update' />
</form>


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

</div>
<?php $Conf->footer() ?>
</body>
</html>

