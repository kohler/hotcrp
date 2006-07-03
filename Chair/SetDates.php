<?php 
include('../Code/confHeader.inc');
include('../Code/Calendar.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$_SESSION["Me"]->goIfNotChair('../');
include('Code.inc');

$DateStartMap = array("updatePaperSubmission" => "startPaperSubmission",
		      "finalizePaperSubmission" => "startPaperSubmission",
		      "reviewerSubmitReviewDeadline" => "reviewerSubmitReview",
		      "PCSubmitReviewDeadline" => "PCSubmitReview");

$DateName['startPaperSubmission'][0] = "Submissions open";
$DateName['startPaperSubmission'][1] = "Deadline for creating new submissions";
$DateName['updatePaperSubmission'][1] = "Deadline for updating submissions";
$DateName['finalizePaperSubmission'][1] = "Deadline for finalizing submissions";
$DateName['authorViewReviews'] = "Reviews visible";
$DateName['authorRespondToReviews'] = "Responses allowed";
$DateName['authorViewDecision'] = "Outcomes visible to authors";

$DateName['reviewerSubmitReview'] = "Peer review period";
$DateName['reviewerSubmitReviewDeadline'][1] = "Hard peer review deadline";
$DateName['notifyChairAboutReviews'] = "Chairs are notified of new reviews";
$DateName['reviewerViewDecision'] = "Outcomes and responses visible to reviewers";

$DateName['PCSubmitReview'] = "PC review period";
$DateName['PCSubmitReviewDeadline'][1] = "Hard PC review deadline";
$DateName['PCReviewAnyPaper'] = "PC can review any paper";
$DateName['PCGradePapers'] = "Paper grading period";
$DateName['PCMeetingView'] = "PC meeting view";
$DateName['AtTheMeeting'] = "PC meeting";
$DateName['EndOfTheMeeting'] = "End of PC meeting";

$DateDescr['updatePaperSubmission']
= "If you want to allow authors to start a paper and then "
    . "update it before the final deadline, set this range to "
    . "that time.";

$DateDescr['finalizePaperSubmission']
= "Authors 'finalize' their submission to indicate that it is complete. "
. "It's usually a good idea to set this deadline to "
. "a day or two after the submission deadline.";

$DateDescr['authorRespondToReviews']
= "This should obviously overlap with the period reviews are visible.";

$DateDescr['PCReviewAnyPaper']
= "If you want the PC members to be able to review <i>any</i> paper that does not"
. " have conflicts indicated, set this date range. "
;

$DateDescr['PCMeetingView'] = "When can PC members see the identity of reviews for"
    . " non-conflicting papers and assign paper grades.";
$DateDescr['AtTheMeeting'] = "Used to hide information about chair opinions.";
$DateDescr['EndOfTheMeeting'] = "The very end of the PC meeting (look at accepted papers)";


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
    echo "  <td class='form_entry'><input type='text' name='${name}_start' id='${name}_start' value='", htmlspecialchars(crp_dateview($name, 0)), "' size='24' onchange='highlightUpdate()' /></td>\n";
    $formclass = isset($DateError["${name}_end"]) ? "form_caption_error" : "form_caption";
    echo "  <td class='$formclass'>To:</td>\n";
    echo "  <td class='form_entry'><input type='text' name='${name}_end' id='${name}_end' value='", htmlspecialchars(crp_dateview($name, 1)), "' size='24' onchange='highlightUpdate()' /></td>\n";
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
    echo "  <td class='form_entry'><input type='text' name='${namex}' id='${namex}' value='", htmlspecialchars(crp_dateview($name, $which)), "' size='24' onchange='highlightUpdate()' /></td>\n";
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
    var ins = document.getElementsByTagName("input");
    for (var i = 0; i < ins.length; i++)
	if (ins[i].name == "update")
	    ins[i].className = "button_alert";
}
function clear1Date(name) {
    document.getElementById(name).value = "N/A";
    highlightUpdate();
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

    // extract dates from form entries
    foreach (array('startPaperSubmission', 'updatePaperSubmission',
		   'finalizePaperSubmission', 'authorViewReviews',
		   'authorRespondToReviews', 'authorViewDecision',
		   'reviewerSubmitReview', 'reviewerSubmitReviewDeadline',
		   'notifyChairAboutReviews', 'reviewerViewDecision',
		   'PCSubmitReview', 'PCSubmitReviewDeadline',
		   'PCReviewAnyPaper', 'PCGradePapers',
		   'PCMeetingView', 'AtTheMeeting', 'EndOfTheMeeting'
		   ) as $s) {
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

    // set dates
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br/>\n", $Error));
    else {
	// specific date validation
	$dval = array("updatePaperSubmission", "startPaperSubmission",
		      "finalizePaperSubmission", "updatePaperSubmission",
		      "updatePaperSubmission", "finalizePaperSubmission",
		      "startPaperSubmission", "updatePaperSubmission");
	for ($i = 0; $i < count($dval); $i += 2) {
	    $dest = $dval[$i]; $src = $dval[$i+1];
	    if ($Dates[$dest][1] < 0 && $Dates[$src][1] > 0) {
		$Dates[$dest][1] = $Dates[$src][1];
		$Messages[] = $DateName[$dest][1] . " set to " . $DateName[$src][1] . ".";
	    }
	}

	// print messages now, in case errors come later
	if (count($Messages) > 0)
	    $Conf->infoMsg(join("<br/>\n", $Messages));

	// insert into database
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
 } else if (isset($_REQUEST["revert"])) {
    $_REQUEST = array();
 }

$Conf->updateImportantDates();
?>

<p><a href='ShowCalendar.php' target='_blank'>Show calendar</a> &mdash;
<a href='http://www.php.net/manual/en/function.strtotime.php' target='_blank'>How to specify a date</a></p>

<form class='date' method='post' action='SetDates.php'>

<table>
<tr>
  <td class='form_caption'><input class='button' type='submit' value='Revert All' name='revert' /></td>
  <td class='form_caption'><input class='button_default' type='submit' value='Update All' name='update' /></td>
  <td>Select Update All to save any changes to dates.  It will turn red to help you remember.</td>
</tr>
</table>

<h2>Dates Affecting Authors</h2>

<table class='imptdates'>
<?php crp_show1date('startPaperSubmission', 0); ?>
<?php crp_show1date('startPaperSubmission', 1); ?>
<?php crp_show1date('updatePaperSubmission', 1); ?>
<?php crp_show1date('finalizePaperSubmission', 1); ?>
<?php crp_showdate('authorViewReviews'); ?>
<?php crp_showdate('authorRespondToReviews'); ?>
<?php crp_showdate('authorViewDecision'); ?>
</table>

<table>
<tr>
  <td class='form_caption'><input class='button' type='submit' value='Revert All' name='revert' /></td>
  <td><input class='button_default' type='submit' value='Update All' name='update' /></td>
</tr>
</table>

    
<h2>Dates Affecting Peer Reviewers</h2>

<table class='imptdates'>
<?php crp_showdate('reviewerSubmitReview'); ?>
<?php crp_show1date('reviewerSubmitReviewDeadline', 1); ?>
<?php crp_showdate('notifyChairAboutReviews'); ?>
<?php crp_showdate('reviewerViewDecision'); ?>
</table>

<table>
<tr>
  <td class='form_caption'><input class='button' type='submit' value='Revert All' name='revert' /></td>
  <td><input class='button_default' type='submit' value='Update All' name='update' /></td>
</tr>
</table>


<h2>Dates Affecting the Program Committee</h2>

<table class='imptdates'>
<?php crp_showdate('PCSubmitReview'); ?>
<?php crp_show1date('PCSubmitReviewDeadline', 1); ?>
<?php crp_showdate('PCReviewAnyPaper'); ?>
<?php crp_showdate('PCGradePapers'); ?>
<?php crp_showdate('PCMeetingView'); ?>
<?php crp_showdate('AtTheMeeting'); ?>
<?php crp_showdate('EndOfTheMeeting'); ?>
</table>

<table>
<tr>
  <td class='form_caption'><input class='button' type='submit' value='Revert All' name='revert' /></td>
  <td><input class='button_default' type='submit' value='Update All' name='update' /></td>
</tr>
</table>

</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>

