<?php 
require_once('Code/header.inc');
require_once('Code/Calendar.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();

// not actually dates; just look at the startTime:
//	PCReviewAnyPaper
//	reviewerViewReviews
//	notifyChairAboutReviews

$DateStartMap = array("updatePaperSubmission" => "startPaperSubmission",
		      "finalizePaperSubmission" => "startPaperSubmission",
		      "reviewerSubmitReviewDeadline" => "reviewerSubmitReview",
		      "PCSubmitReviewDeadline" => "reviewerSubmitReview",
		      "PCSubmitReview" => "reviewerSubmitReview");
$DateNoEndMap = array("PCReviewPreferences" => 1);

$DateName['startPaperSubmission'][0] = "Submission period begins";
$DateName['startPaperSubmission'][1] = "Deadline for creating new submissions";
$DateName['updatePaperSubmission'][1] = "Deadline for updating submissions";
$DateName['finalizePaperSubmission'][1] = "Submission period ends";

$DateName['PCReviewPreferences'][0] = "Start collecting PC preferences";
$DateName['reviewerSubmitReview'][0] = "Review period begins";
$DateName['reviewerSubmitReview'][1] = "Soft deadline for external reviews";
$DateName['PCSubmitReview'][1] = "Soft deadline for PC reviews";
$DateName['reviewerSubmitReviewDeadline'][1] = "Hard deadline for external reviews";
$DateName['PCSubmitReviewDeadline'][1] = "Hard deadline for PC reviews";
$DateName['authorViewReviews'] = "Reviews visible to authors";
$DateName['authorRespondToReviews'] = "Authors may respond";
$DateName['authorViewDecision'] = "Outcomes visible to authors";
$DateName['authorUpdateFinal'] = "Authors upload final copies";

$DateName['reviewerViewDecision'] = "Outcomes and responses visible to reviewers";

$DateName['PCGradePapers'] = "Paper grading period";
$DateName['PCMeetingView'] = "PC meeting view";
$DateName['AtTheMeeting'] = "PC meeting";
$DateName['EndOfTheMeeting'] = "End of PC meeting";

#$DateDescr['updatePaperSubmission']
#= "If you want to allow authors to start a paper and then "
#. "update it before the final deadline, set this range to "
#. "that time.";

#$DateDescr['finalizePaperSubmission']
#= "Papers must be officially submitted by this date or they will not be considered.  "
#. "It's usually a good idea to set this deadline to "
#. "a day or two after the update deadline.";

$DateDescr['authorRespondToReviews']
= "This should obviously overlap with the period reviews are visible.";

$DateDescr['authorViewDecision']
= "Note that PC authors can view outcomes as soon as they are entered into the system.";

$DateDescr['PCMeetingView'] = "When can PC members see the identity of reviews for"
    . " non-conflicting papers and assign paper grades.";
$DateDescr['AtTheMeeting'] = "Used to hide information about chair opinions.";
$DateDescr['EndOfTheMeeting'] = "The very end of the PC meeting (look at accepted papers)";


// header and script
$Conf->header_head("Important Dates");
echo "<script type='text/javascript'>
function clear1Date(name) {
    document.getElementById(name).value = 'N/A';
    highlightUpdate();
}
function clearDates(name) {
    clear1Date(name + '_start');
    clear1Date(name + '_end');
}
</script>\n";
$Conf->header("Important Dates");


// Now catch any modified dates

function crp_strtotime($tname, $which) {
    global $Error, $DateName, $DateStartMap, $DateNoEndMap, $DateError;
    
    $req_tname = $tname;
    if ($which == 0 && isset($DateStartMap[$tname]))
	$req_tname = $DateStartMap[$tname];
    if ($which == 1 && isset($DateNoEndMap[$tname]))
	return -1;
    $varname = $req_tname . ($which ? "_end" : "_start");

    if (!isset($_REQUEST[$varname]))
	$err = "missing from form";
    else {
	$t = trim($_REQUEST[$varname]);
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

if (isset($_REQUEST['update']) && $Me->amAssistant()) {
    $Error = array();
    $Dates = array();
    $Messages = array();

    // extract dates from form entries
    foreach (array('startPaperSubmission', 'updatePaperSubmission',
		   'finalizePaperSubmission', 'authorViewReviews',
		   'authorRespondToReviews', 'authorViewDecision',
		   'authorUpdateFinal',
		   'reviewerSubmitReview', 'reviewerSubmitReviewDeadline',
		   'reviewerViewDecision', 'PCReviewPreferences',
		   'PCSubmitReview', 'PCSubmitReviewDeadline',
		   'PCGradePapers', 'PCMeetingView',
		   'AtTheMeeting', 'EndOfTheMeeting'
		   ) as $s) {
	$Dates[$s][0] = crp_strtotime($s, 0);
	$Dates[$s][1] = crp_strtotime($s, 1);
	if ($Dates[$s][1] > 0 && $Dates[$s][0] < 0) {
	    $today = getdate();
	    $Dates[$s][0] = mktime(0, 0, 0, 1, 1, $today["year"]);
	    if (!isset($DateStartMap[$s]))
		$Messages[] = "\"" . (is_array($DateName[$s]) ? $DateName[$s][0] : $DateName[$s] . " begin") . "\" missing; set to the beginning of this year.";
	} else if ($Dates[$s][1] > 0 && $Dates[$s][1] < $Dates[$s][0]) {
	    $Error[] = (is_array($DateName[$s]) ? $DateName[$s][1] : $DateName[$s]) . " period ends before it begins.";
	    $DateError["${s}_end"] = 1;
	}
    }

    // set nonexistent dates based on existent dates
    $dval = array("updatePaperSubmission", "startPaperSubmission",
		  "finalizePaperSubmission", "updatePaperSubmission",
		  "PCSubmitReviewDeadline", "PCSubmitReview",
		  "reviewerSubmitReviewDeadline", "reviewerSubmitReview");
    for ($i = 0; $i < count($dval); $i += 2) {
	$dest = $dval[$i]; $src = $dval[$i+1];
	if ($Dates[$dest][1] > 0 && $Dates[$src][1] > 0 && $Dates[$dest][1] < $Dates[$src][1]) {
	    $dname = is_array($DateName[$src]) ? $DateName[$src][1] : $DateName[$src];
	    $Error[] = $DateName[$dest][1] . " must be on or after $dname.";
	    $DateError["${dest}_end"] = $DateError["${src}_end"] = 1;
	}
    }
    $dval = array("updatePaperSubmission", "finalizePaperSubmission",
		  "startPaperSubmission", "updatePaperSubmission");
    for ($i = 0; $i < count($dval); $i += 2) {
	$dest = $dval[$i]; $src = $dval[$i+1];
	if ($Dates[$dest][1] <= 0 && $Dates[$src][1] > 0) {
	    $Dates[$dest][1] = $Dates[$src][1];
	    if (!is_array($DateName[$dest]) || $DateName[$dest][1])
		$Messages[] = $DateName[$dest][1] . " set to " . $DateName[$src][1] . ".";
	}
    }

    // special cases
    $Dates["PCReviewAnyPaper"] = array((isset($_REQUEST["PCReviewAnyPaper"]) ? 1 : 0), 0);
    if (($x = cvtint($_REQUEST["reviewerViewReviews"])) < 0 || $x > 2)
	$x = 0;
    $Dates["reviewerViewReviews"] = array($x, 0);
    $Dates["notifyChairAboutReviews"] = array((isset($_REQUEST["notifyChairAboutReviews"]) ? 1 : 0), 0);
    if (($x = cvtint($_REQUEST["blindSubmission"])) < 0 || $x > 2)
	$x = 2;
    $Dates["blindSubmission"] = array($x, 0);
    if (($x = cvtint($_REQUEST["blindReview"])) < 0 || $x > 2)
	$x = 2;
    $Dates["blindReview"] = array($x, 0);
    $Dates["reviewFormUpdate"] = array(time(), 0);
    
    // print messages now, in case errors come later
    if (count($Messages) > 0)
	$Conf->infoMsg(join("<br/>\n", $Messages));

    // set dates, if no errors
    if (count($Error) > 0)
	$Conf->errorMsg(join("<br/>\n", $Error));
    else {
	$result = $Conf->qe("delete from ImportantDates");
	if (!MDB2::isError($result)) {
	    $q = "insert into ImportantDates (name, start, end) values ";
	    foreach ($Dates as $n => $v) {
		$sx = ($v[0] > 0 ? "from_unixtime($v[0])" : "'0'");
		$ex = ($v[1] > 0 ? "from_unixtime($v[1])" : "'0'");
		$q .= "('$n', $sx, $ex), ";
		unset($_REQUEST["${n}_start"]);
		unset($_REQUEST["${n}_end"]);
		unset($_REQUEST[$n]);
	    }
	    $Conf->qe(substr($q, 0, strlen($q) - 2));
	}
    }

    // check tags
    if (isset($_REQUEST["chairtags"])) {
	$chairtags = preg_split('/\s+/', $_REQUEST["chairtags"]);
	$ok = true;
	foreach ($chairtags as $ct)
	    $ok = ($ok && checkTag($ct));
	if ($ok)
	    $Conf->qe("delete from ChairTag");
	if ($ok && count($chairtags) > 0) {
	    $q = "insert into ChairTag (tag) values ";
	    foreach ($chairtags as $ct)
		$q .= "('" . sqlq($ct) . "'), ";
	    $Conf->qe(substr($q, 0, strlen($q) - 2));
	}
    }

    $Conf->updateImportantDates();
    
} else if (isset($_REQUEST["revert"])) {
    $_REQUEST = array();
}


function crp_dateview($name, $end) {
    global $Conf;
    $var = ($end ? $Conf->endTime : $Conf->startTime);
    $tname = $name . ($end ? "_end" : "_start");
    if (isset($_REQUEST[$tname]))
	return $_REQUEST[$tname];
    else if (isset($var[$name]) && $var[$name] > 0)
	return $Conf->parseableTime($var[$name]);
    else
	return "N/A";
}

function crp_showdate($name) {
    global $Conf, $DateDescr, $DateName, $DateError;
    $label = preg_replace('/ /', '&nbsp;', $DateName[$name]);

    echo "<tr>\n";
    echo "  <td class='datename'>$label</td>\n";
    $formclass = isset($DateError["${name}_start"]) ? "rcaption error" : "rcaption";
    echo "  <td class='$formclass'>From:</td>\n";
    echo "  <td class='entry'><input class='textlite' type='text' name='${name}_start' id='${name}_start' value='", htmlspecialchars(crp_dateview($name, 0)), "' size='24' onchange='highlightUpdate()' tabindex='1' /></td>\n";
    $formclass = isset($DateError["${name}_end"]) ? "rcaption error" : "rcaption";
    echo "  <td class='$formclass'>To:</td>\n";
    echo "  <td class='entry'><input class='textlite' type='text' name='${name}_end' id='${name}_end' value='", htmlspecialchars(crp_dateview($name, 1)), "' size='24' onchange='highlightUpdate()' tabindex='1' /></td>\n";
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
    echo "  <td class='entry'><input class='textlite' type='text' name='${namex}' id='${namex}' value='", htmlspecialchars(crp_dateview($name, $which)), "' size='24' onchange='highlightUpdate()' tabindex='1' /></td>\n";
    //echo "  <td class='rcaption'></td>\n";
    //echo "  <td class='entry'></td>\n";
    //echo "  <td><button class='button' type='button' onclick='javascript: clear1Date(\"", $namex, "\")'>Clear</button></td>\n";
    //echo "  <td width='100%'></td>\n";
    echo "</tr>\n";

    if (isset($DateDescr[$name])) {
	echo "<tr>\n";
	echo "  <td colspan='7' class='datedesc'>", $DateDescr[$name], "</td>\n";
	echo "</tr>\n";
    }
}


?>

<p>The following times determine when various conference
submission and review functions can be accessed.
<em>The enforcement of these times is automatically controlled by
the conference review software. They are firm deadlines.</em>
Each time is specified in the timezone of the server
for this conference, which is shown at the top
of each page in the conference review system.</p>

<ul>
<?php 

if ($Conf->validDeadline("startPaperSubmission")) {
    echo "<li>";
    echo "You can start new paper submissions ";
    echo $Conf->printableTimeRange('startPaperSubmission');
    echo ".</li>";
}

if ($Conf->validDeadline('updatePaperSubmission')) {
    echo "<li>";
    echo "You can update those submissions, including uploading new copies of your paper, ";
    echo $Conf->printableTimeRange('updatePaperSubmission');
    echo ".  You must officially submit your submissions by this time or they will not be considered for the conference.</li>";
}

if ($Conf->validDeadline('authorViewReviews')) {
    echo "<li>";
    echo "Authors can view the reviews of their papers ";
    echo $Conf->printableTimeRange('authorViewReviews');
    echo ".</li>";
}

if ($Conf->validDeadline('authorRespondToReviews')) {
    echo "<li>";
    echo "Authors can respond to the reviews of their papers ";
    echo $Conf->printableTimeRange('authorRespondToReviews');
    echo ".</li>";
}

if ($Conf->validDeadline('reviewerSubmitReview')) {
    echo "<li>";
    echo "Reviewers can submit their reviews ";
    echo $Conf->printableTimeRange('reviewerSubmitReview');
    echo ".</li>";
}

if ($Conf->validDeadline('PCSubmitReview')) {
    echo "<li>";
    echo "Program committee members can complete reviews ";
    echo $Conf->printableTimeRange('PCSubmitReview');
    echo ".</li>";
}

echo "</ul>\n\n";


if ($Me->amAssistant()) {
    $result = $Conf->qe("select group_concat(tag separator ' ') from ChairTag");
    if (!MDB2::isError($result)) {
	$row = $result->fetchRow();
	$chairtags = $row[0];
    } else
	$chairtags = '';
    
    echo "<hr />";

    echo "<h2>Set deadlines and conference options</h2>\n";
    
    echo "<p><a href='ShowCalendar.php' target='_blank'>Show calendar</a> &mdash;
<a href='http://www.php.net/manual/en/function.strtotime.php' target='_blank'>How to specify a date</a></p>

<form class='date' method='post' action='deadlines.php'>
<input type='hidden' name='chairMode' value='0' />

<table>
<tr>
  <td class='rcaption'><input class='button_default' type='submit' value='Save Changes' name='update' tabindex='1' /></td>
  <td class='rcaption'><input class='button' type='submit' value='Revert All' name='revert' tabindex='1' /></td>
</tr>
</table>

<h3>The submission period</h3>

<table>
<tr><td style='vertical-align: top'><table class='imptdates'>\n";
    crp_show1date('startPaperSubmission', 0);
    crp_show1date('startPaperSubmission', 1);
    crp_show1date('updatePaperSubmission', 1);
    crp_show1date('finalizePaperSubmission', 1);
    echo "</table></td>
<td style='vertical-align: top'><table class='imptdates'>
  <tr><td class='datename'>";

    $x = cvtint($_REQUEST["blindSubmission"]);
    if ($x < 0)
	$x = cvtint($Conf->startTime["blindSubmission"]);
    echo "<input type='radio' name='blindSubmission' value='2'", ($x < 0 || $x >= 2 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Blind submission<br />";
    echo "<input type='radio' name='blindSubmission' value='1'", ($x == 1 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Optionally blind submission<br />";
    echo "<input type='radio' name='blindSubmission' value='0'", ($x == 0 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Nonblind submission\n";

    echo "  </td></tr>
</table></td></tr>
</table>

<table class='imptdates'>\n";
    crp_showdate('authorViewReviews');
    crp_showdate('authorRespondToReviews');
    crp_showdate('authorViewDecision');
    crp_showdate('authorUpdateFinal');
    echo "</table>

<table>
<tr>
  <td class='rcaption'><input class='button_default' type='submit' value='Save Changes' name='update' tabindex='1' /></td>
  <td class='rcaption'><input class='button' type='submit' value='Revert All' name='revert' tabindex='1' /></td>
</tr>
</table>


<h3>The review period</h3>

<table>
<tr><td style='vertical-align: top'><table class='imptdates'>\n";
    
    crp_show1date('PCReviewPreferences', 0);
    crp_show1date('reviewerSubmitReview', 0);
    crp_show1date('reviewerSubmitReview', 1);
    crp_show1date('PCSubmitReview', 1);
    crp_show1date('reviewerSubmitReviewDeadline', 1);
    crp_show1date('PCSubmitReviewDeadline', 1);
    
    echo "</table></td><td style='vertical-align: top'><table class='imptdates'>
<tr>
  <td class='datename'>";

    $x = cvtint($_REQUEST["blindReview"]);
    if ($x < 0)
	$x = cvtint($Conf->startTime["blindReview"]);
    echo "<input type='radio' name='blindReview' value='2'", ($x < 0 || $x >= 2 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Blind review<br />";
    echo "<input type='radio' name='blindReview' value='1'", ($x == 1 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Optionally blind review<br />";
    echo "<input type='radio' name='blindReview' value='0'", ($x == 0 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Nonblind review\n";

    echo "<div class='maintabsep'></div>\n";

    echo "<input type='checkbox' name='PCReviewAnyPaper' value='1' ";
    if (isset($_REQUEST["PCReviewAnyPaper"])
	|| cvtint($Conf->startTime["PCReviewAnyPaper"]) > 0)
	echo "checked='checked' ";
    echo "onchange='highlightUpdate()' tabindex='1' />&nbsp;PC members can review any submitted paper during the review period<br />\n";

    echo "<input type='checkbox' name='notifyChairAboutReviews' value='1' ";
    if (isset($_REQUEST["notifyChairAboutReviews"])
	|| cvtint($Conf->startTime["notifyChairAboutReviews"]) > 0)
	echo "checked='checked' ";
    echo "onchange='highlightUpdate()' tabindex='1' />&nbsp;PC chairs are notified via email about new reviews<br />\n";
    
    echo "<div class='maintabsep'></div>\nExternal reviewers can view other reviews for their papers:<br />";
    $x = cvtint($_REQUEST["reviewerViewReviews"]);
    if ($x < 0)
	$x = cvtint($Conf->startTime["reviewerViewReviews"]);
    echo "<input type='radio' name='reviewerViewReviews' value='0'", ($x <= 0 || $x > 2 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;Never </span><br />";
    echo "<input type='radio' name='reviewerViewReviews' value='1'", ($x == 1 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;After they submit their reviews, but they cannot see reviewer identities<br />";
    echo "<input type='radio' name='reviewerViewReviews' value='2'", ($x == 2 ? " checked='checked'" : "") , " onchange='highlightUpdate()' />&nbsp;After they submit their reviews, including reviewer identities";
    echo "<div class='maintabsep'></div>\nSpecial tags (only chairs can add/remove):<br />
<input type='text' class='textlite' name='chairtags' value=\"", htmlspecialchars($chairtags), "\" size='40' />\n";
    echo "</td>\n</tr>\n";

    echo "</table></td></tr>
</table>

    
<table class='imptdates'>\n";
    crp_showdate('reviewerViewDecision');
    echo "</table>

<table>
<tr>
  <td class='rcaption'><input class='button_default' type='submit' value='Save Changes' name='update' tabindex='1' /></td>
  <td class='rcaption'><input class='button' type='submit' value='Revert All' name='revert' tabindex='1' /></td>
</tr>
</table>


<h3>Dates Affecting the Program Committee</h3>

<table class='imptdates'>\n";
    crp_showdate('PCGradePapers');
    crp_showdate('PCMeetingView');
    crp_showdate('EndOfTheMeeting');
    echo "</table>

<table>
<tr>
  <td class='rcaption'><input class='button_default' type='submit' value='Save Changes' name='update' tabindex='1' /></td>
  <td class='rcaption'><input class='button' type='submit' value='Revert All' name='revert' tabindex='1' /></td>
</tr>
</table>

</form>

</div>\n";
}

$Conf->footer();
