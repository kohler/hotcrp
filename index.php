<?php
require_once('Code/confHeader.inc');
require_once('Code/ClassPaperList.inc');
$Conf->connect();

$testCookieStatus = 0;
if (isset($_COOKIE["CRPTestCookie"]) && $_COOKIE["CRPTestCookie"] == "ChocChip")
    $testCookieStatus = 1;
if (!isset($_GET["cc"]) && !$testCookieStatus) {
    setcookie("CRPTestCookie", "ChocChip");
    header("Location: http://" . $_SERVER["HTTP_HOST"] . $_SERVER["PHP_SELF"] . "?cc=1");
    exit;
}
if (!$testCookieStatus) {
    $here = dirname($_SERVER["SCRIPT_NAME"]);
    header("Location: http://" . $_SERVER["HTTP_HOST"] . "$here/YouMustAllowCookies.php");
}

if (!isset($_SESSION["Me"]) || !$_SESSION["Me"]->valid())
    go("login.php");
$Me = $_SESSION["Me"];

if (($_SESSION["AskedYouToUpdateContactInfo"] < 2
     && !($Me->lastName && $Me->affiliation))
    || ($_SESSION["AskedYouToUpdateContactInfo"] < 3 && $Me->isPC
	&& !($Me->collaborators || $Me->anyTopicInterest))) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $Me->go("All/UpdateContactInfo.php");
}


//
// Check for updated menu
//
if (isset($_REQUEST["setRole"]))
    $_SESSION["WhichTaskView"] = $_REQUEST["setRole"];

$Conf->header("Welcome");
echo actionBar(null, false, "");



$tabName = array();
$tabText = array();
$tabBody = array();
$tabSep = "<div class='maintabsep'></div>\n";


if ($Me->isPC || $Me->amReviewer()) {
    $tabName[] = "re";
    $tabText[] = "Reviews";
    if (!isset($defaultTabName))
	$defaultTabName = 're';
    $body = "";
    $sep = "";

    $plist = new PaperList("1", null, "list_tabre");
    $plist->showHeader = 0;
    $ptext = $plist->text("reviewerHome", $Me);
    if ($plist->count > 0) {
	$body .= $sep . $ptext;
	$sep = $tabSep;
    }

    if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)){
	$body .= $sep . "PC members may review <a href='list.php?list=submitted'>any submitted paper</a>, whether or not a review has been assigned.";
	$sep = $tabSep;
    }

    $deadlines = array();
    $rtyp = ($Me->isPC ? "PC" : "reviewer");
    unset($d);
    if ($plist->needSubmitReview == 0) {
	/* nada */
	//if ($plist->count > 0)
	//   $deadlines[] = "Thank you for submitting your requested reviews!";
    } else if (!$Conf->timeReviewPaper($Me->isPC, true, true))
	$deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting " . ($Me->isPC ? "PC" : "external") . " reviews has passed.";
    else if (!$Conf->timeReviewPaper($Me->isPC, true, false))
	$deadlines[] = "Reviews were requested by " . $Conf->printableEndTime("${rtyp}SubmitReview") . ".";
    else {
	$d = $Conf->printableEndTime("${rtyp}SubmitReview");
	if ($d != "N/A")
	    $deadlines[] = "Please submit your reviews by $d.";
    }
    if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = (isset($d) ? "N/A" : $Conf->printableEndTime("PCSubmitReview"));
	if ($d != "N/A")
	    $deadlines[] = "Please submit your reviews by $d.";
    }
    if (count($deadlines) > 0)
	$body .= $sep . join("<br/>", $deadlines);

    $body .= "<hr />

<table class='bullets'><tr><td>

<ul>
  <li><a href='uploadreview.php'>Download and upload review forms</a></li>
</ul>

</td><td>\n\n";
    if ($Me->isPC) {
	$body .= "<ul>
  <li><a href='list.php?list=submitted'>List submitted papers</a></li>\n";
	if ($Me->amAssistant())
	    $body .= "  <li><a href='list.php?list=all'>List all papers</a></li>\n";
	$body .= "</ul>\n\n";
    }
    $body .= "</td></tr></table>\n";
    
    $tabBody[] = $body;
}


if ($Me->isAuthor || $Conf->timeStartPaper() > 0 || $Me->amAssistant()) {
    $tabName[] = "su";
    $tabText[] = "Submissions";
    if (!isset($defaultTabName))
	$defaultTabName = 'su';
    $body = "";
    $sep = "";

    $startable = $Conf->timeStartPaper();
    if ($startable || $Me->amAssistant()) {
	$body .= "$sep<div><strong><a href='paper.php?paperId=new'>Start new paper</a></strong> <span class='deadline'>(" . $Conf->printDeadline('startPaperSubmission') . ")</span>";
	if ($Me->amAssistant())
	    $body .= "<br/>\n<small>As PC Chair, you can start papers regardless of deadlines and on other people's behalf.</small>";
	$body .= "</div>\n";
	$sep = $tabSep;
    }

    if ($Me->isAuthor) {
	$plist = new PaperList("1", null, "list_tabsu");
	$plist->showHeader = 0;
	$ptext = $plist->text("authorHome", $Me);
	$deadlines = array();
	if ($plist->count > 0) {
	    $body .= $sep . $ptext;
	    $sep = $tabSep;
	}
	if ($plist->needFinalize > 0) {
	    $time = $Conf->printableEndTime('updatePaperSubmission');
	    if (!$Conf->timeFinalizePaper())
		$deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting papers in progress has passed.";
	    else if (!$Conf->timeUpdatePaper()) {
		$deadlines[] = "The <a href='deadlines.php'>deadline</a> for updating papers in progress has passed, but you can still submit.";
		$time = $Conf->printableEndTime('finalizePaperSubmission');
		if ($time != 'N/A')
		    $deadlines[] = "You have until $time to submit any papers in progress.";
	    } else if (($time = $Conf->printableEndTime('updatePaperSubmission')) != 'N/A')
		$deadlines[] = "You have until $time to submit any papers in progress.";
	}
	if (!$startable && !$Conf->timeAuthorViewReviews())
	    $deadlines[] = "The <a href='deadlines.php'>deadline</a> for starting new papers has passed.";
	if (count($deadlines) > 0)
	    $body .= $sep . join("<br/>", $deadlines);
    }

    $tabBody[] = $body;
}


$tabName[] = "se";
$tabText[] = "Settings";
if (!isset($defaultTabName))
    $defaultTabName = 'se';
$body = "<table class='bullets'><tr>";

$body .= "<td><h4>Your account</h4>
<ul>
  <li><a href='All/UpdateContactInfo.php'>Account settings</a>: your name, email, affiliation</li>
  <li><a href='All/MergeAccounts.php'>Merge accounts</a></li>
</ul>";
if ($Me->amAssistant())
    $body .= "\n<h4>Other accounts</h4>
<ul>
  <li><a href='All/UpdateContactInfo.php?new=1'>Create new account</a></li>
  <li><a href='Chair/BecomeSomeoneElse.php'>Act on someone else's behalf</a></li>
</ul>";
$body .= "</td>\n";

$body .= "<td><h4>Conference information</h4>
<ul>
  <li><a href='deadlines.php'>Important dates";
if ($Me->amAssistant())
    $body .= " and conference options";
$body .= "</a></li>
  <li><a href='pc.php'>Program committee</a>";
if ($Me->amAssistant())
    $body .= ": view and/or modify";
$body .= "</li>\n";
if ($Me->amAssistant())
    $body .= "  <li><a href='Chair/SetTopics.php'>Set conference topics</a></li>
  <li><a href='Chair/SetReviewForm.php'>Set review form</a></li>\n";
$body .= "</ul></td>\n";

$body .= "</tr></table>";
$tabBody[] = $body;


if (isset($_SESSION["mainTab"]) && in_array($_SESSION["mainTab"], $tabName))
    $defaultTabName = $_SESSION["mainTab"];
$_SESSION["whichList"] = "mainTab";

						      
// now we know the default tab name, print the introduction
echo "<p>You're logged in as ", htmlspecialchars($Me->fullnameAndEmail()), ".
If this is not you, please <a href='", $ConfSiteBase, "logout.php'>log out</a>.
You will be automatically logged out if you are idle for more than ",
    round(ini_get("session.gc_maxlifetime")/3600), " hours.";
echo "<img id='tabsv' alt='' src='", $ConfSiteBase, "sessionvar.php?var=mainTab&amp;val=", $defaultTabName, "' /></p>\n\n";



echo "<div class='maintab'><table class='top'><tr>\n  <td><table><tr>\n";
$tns = "[";
foreach ($tabName as $tn)
    $tns .= "'$tn',";
$tns = substr($tns, 0, -1) . "]";
for ($i = 0; $i < count($tabBody); $i++) {
    echo "    <td class='sep'></td>\n";
    echo "    <td class='", ($tabName[$i] == $defaultTabName ? "tab_default" : "tab"), "' id='tab$tabName[$i]' nowrap='nowrap'><a href=\"javascript:tabfold($tns,'", $tabName[$i], "',0,'tabsv')\">", $tabText[$i], "</a></td>\n";
}
echo "  </tr></table></td>\n  <td style='width:100%'><table style='width:100%'><tr><td class='spanner'></td></tr></table></td>\n</tr></table>\n";
for ($i = 0; $i < count($tabBody); $i++) {
    echo "<div class='", ($tabName[$i] == $defaultTabName ? " unfolded" : " folded"), "' id='fold", $tabName[$i], "'><div class='bot extension'>", $tabBody[$i], "</div></div>\n";
}
echo "</div>\n";



$homeSep = "<span class='sep'></span>";
?>


<?php if ($Me->isChair) { ?>
<div class='home_tasks' id='home_tasks_chair'>
  <div class='taskname'><h2>Program Chair Tasks</h2></div>
  <div class='taskdetail'>
    <table>

    <tr>
      <th>Program&nbsp;committee:</th>
      <td><a href='Chair/ListPC.php'>See&nbsp;contact&nbsp;information[X]</a></td>
    </tr>

    </table>
  </div>
  <div class='clear'></div>
</div>
<?php } ?>


<?php
function printDeadlines(&$da, $colspan) {
    if (isset($da) && count($da))
	echo "<tr>\n  <td colspan='$colspan'>", join("<br/>\n", $da), "</td>\n</tr>\n";
}

function reviewerDeadlines($isPC, $plist) {
    global $Conf;
    $rtyp = ($isPC ? "PC" : "reviewer");
    if ($plist->needSubmitReview == 0)
	/* do nothing */;
    else if (!$Conf->timeReviewPaper($isPC, true, true))
	$deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting " . ($isPC ? "PC" : "external") . " reviews has passed.";
    else if (!$Conf->timeReviewPaper($isPC, true, false))
	$deadlines[] = "Reviews were requested by " . $Conf->printableEndTime("${rtyp}SubmitReview") . ".";
    else {
	$d = $Conf->printableEndTime("${rtyp}SubmitReview");
	if ($d != "N/A")
	    if (!$isPC && $_SESSION["Me"]->isPC)
		$deadlines[] = "External reviewers should submit reviews by $d.";
	    else
		$deadlines[] = "Please submit your reviews by $d.";
    }
    if ($isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = (isset($d) ? "N/A" : $Conf->printableEndTime("PCSubmitReview"));
	$deadlines[] = "PC members may review any submitted paper" . ($d == "N/A" ? "." : " until $d.");
    }
    printDeadlines($deadlines, 6);
}




function taskbutton($name,$label) {
    global $Conf;
    if ($_SESSION["WhichTaskView"] == $name )
	$color = $Conf->taskHeaderColor;
    else
	$color = $Conf->contrastColorTwo;
    print "<td bgcolor=$color width=20% align=center> ";
    echo "<form action='index.php' method='get'>\n";
    print "<input type=submit value='$label'>";
    print "<input type=hidden name='setRole' value='$name'>";
    print "</form>";
    print "</td>";
}


if ($Me->isPC || $Me->amAssistant()) { ?>

<table width=100%>
<tr>
<? if ($Me->isPC) { taskbutton("PC", "PC Members"); }?>
<? if ($Me->isChair) {taskbutton("Chair", "PC Chair");}?>
<? if ($Me->amAssistant()) {taskbutton("Assistant", "PC Chair Assistant");}?>
</tr>
</table>

<?
    if ($_SESSION["WhichTaskView"] == "Reviewer") {
	include("Tasks-Reviewer.inc");
    } else if ($_SESSION["WhichTaskView"] == "PC") {
	include("Tasks-PC.inc");
    } else if ($_SESSION["WhichTaskView"] == "Chair") {
	include("Tasks-Chair.inc");
    } else if ($_SESSION["WhichTaskView"] == "Assistant") {
	include("Tasks-Assistant.inc");
    }
}

if (0) {
  print "<p> ";
  print $Me->dump();
  print "</p>";
}
?>

<?php $Conf->footer() ?>
