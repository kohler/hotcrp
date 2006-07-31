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

echo "<p>You're logged in as ", htmlspecialchars($Me->fullnameAndEmail()), ".
If this is not you, please <a href='", $ConfSiteBase, "logout.php'>log out</a>.
You will be automatically logged out if you are idle for more than ",
    round(ini_get("session.gc_maxlifetime")/3600), " hours.</p>\n\n";

$Conf->updateImportantDates();

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

$homeSep = "<span class='homesep'></span>";
?>


<?php if ($Me->isChair) { ?>
<div class='home_tasks' id='home_tasks_chair'>
  <div class='taskname'><h2>Program Chair Tasks</h2></div>
  <div class='taskdetail'>
    <table>
    <tr>
      <th>Papers:</th>
	<td><?php echo goPaperForm(), " ", $homeSep ?>
	<a href='All/ListPapers.php?list=submitted'>List&nbsp;submitted</a> <?php echo $homeSep ?>
	<a href='All/ListPapers.php?list=all'>List&nbsp;all</a> <?php echo $homeSep ?>
	<a href='paper.php?paperId=new'>Enter&nbsp;new</a></td>
    </tr>

    <tr>
      <th>Program&nbsp;committee:</th>
      <td><a href='Chair/ReviewPC.php'>Add/remove&nbsp;members</a> <?php echo $homeSep ?>
        <a href='Chair/ListPC.php'>See&nbsp;contact&nbsp;information[X]</a></td>
    </tr>

    <tr>
      <th>Accounts:</th>
      <td><a href='All/UpdateContactInfo.php?new=1'>Create&nbsp;account</a> <?php echo $homeSep ?>
	<a href='Chair/BecomeSomeoneElse.php'>Log&nbsp;in&nbsp;as&nbsp;someone&nbsp;else[X]</a></td>
    </tr>

    <tr>
      <th>Conference&nbsp;setup:</th>
      <td><a href='Chair/SetDates.php'>Dates</a> <?php echo $homeSep ?>
	<a href='Chair/SetTopics.php'>Topics</a> <?php echo $homeSep ?>
	<a href='Chair/SetReviewForm.php'>Review&nbsp;form</a></td>
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

if ($Me->isPC) { ?>
<div class='home_tasks' id='home_tasks_pc'>
  <div class='taskname'><h2>Program Committee Tasks</h2></div>
  <div class='taskdetail'>
    <table>
    <tr>
      <th>Papers:</th>
      <td><?php echo goPaperForm(), " ", $homeSep ?>
	<a href='All/ListPapers.php?list=submitted'>List&nbsp;submitted</a></td>
    </tr>

<?php
    $plist = new PaperList();
    $plist->showHeader = 0;
    $ptext = $plist->text("pcreviewerHome", $Me);
    if ($plist->count > 0 || $Conf->timeReviewPaper(true, false, true)) {
	$header = "  <th>Reviews:</th>";
	if ($plist->count > 0) {
	    echo "<tr>\n$header\n  <td class='plholder'>$ptext</td>\n</tr>\n";
	    $header = "  <td></td>";
	}
	if ($Conf->timeReviewPaper(true, true, true)) {
	    echo "<tr>\n$header\n  <td>";
	    if ($Conf->timeReviewPaper(true, false, true))
		echo "<a href='All/ListPapers.php?list=submitted'>Review&nbsp;other&nbsp;papers</a> $homeSep ";
	    echo "<a href='OfflineReview.php'>Offline&nbsp;reviewing</a></td>\n</tr>\n";
	}

	reviewerDeadlines(true, $plist);
    }

    $ptext = $plist->text("reviewRequestsHome", $Me);
    if ($plist->count > 0) {
	echo "<tr>\n  <th>Review&nbsp;requests:</th>\n  <td class='plholder'>$ptext</td>\n</tr>\n";
	reviewerDeadlines(false, $plist);
    }
?>

    </table>
  </div>
  <div class='clear'></div>
</div>
<?php } ?>


<?php if ($Me->amReviewer() && !$Me->isPC) { ?>
<div class='home_tasks' id='home_tasks_pc'>
  <div class='taskname'><h2>Reviewer Tasks</h2></div>
  <div class='taskdetail'>
    <table>

<?php
    $plist = new PaperList();
    $plist->showHeader = 0;
    $ptext = $plist->text("reviewerHome", $Me);
    if ($plist->count > 0) {
	$header = "  <th>Reviews:</th>";
	if ($plist->count > 0) {
	    echo "<tr>\n$header\n  <td class='plholder'>$ptext</td>\n</tr>\n";
	    $header = "  <td></td>";
	}
	if ($Conf->timeReviewPaper(false, true, true))
	    echo "<tr>\n$header\n  <td><a href='OfflineReview.php'>Offline&nbsp;reviewing</a></td>\n</tr>\n";

	reviewerDeadlines(false, $plist);
    }
?>

    </table>
  </div>
  <div class='clear'></div>
</div>
<?php } ?>


<?php if ($Me->isAuthor || $Conf->timeStartPaper() > 0) { ?>
<div class='home_tasks' id='home_tasks_author'>
  <div class='taskname'><h2>Tasks for Authors</h2></div>
  <div class='taskdetail'>
    <table>

<?php
$startable = $Conf->timeStartPaper();
if ($startable)
    echo "    <tr><th><a href='paper.php?paperId=new'>Start new paper</a></th> <td colspan='2'><span class='deadline'>(", $Conf->printDeadline('startPaperSubmission'), ")</span></td></tr>\n";

if ($Me->isAuthor) {
    $plist = new PaperList();
    $plist->showHeader = 0;
    $ptext = $plist->text("authorHome", $Me);
    if ($plist->count > 0)
	echo "<tr>\n  <th>Existing papers:</th>\n  <td class='plholder'>$ptext</td>\n</tr>\n";
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
    printDeadlines($deadlines, 3);
}
?>

    </table>
  </div>
  <div class='clear'></div>
</div>
<?php } ?>


<div class='home_tasks' id='home_tasks_all'>
  <div class='taskname'><h2>Tasks for Everyone</h2></div>
  <div class='taskdetail'>
    <a href='All/UpdateContactInfo.php'>Edit&nbsp;profile</a> <?php echo $homeSep ?>
    <a href='All/MergeAccounts.php'>Merge&nbsp;accounts</a> <?php echo $homeSep ?>
    <a href='deadlines.php'>Important&nbsp;dates</a> <?php echo $homeSep ?>
    <a href='logout.php'>Log&nbsp;out</a>
  </div>
  <div class='clear'></div>
</div>


<?php if ($Me->isPC || $Me->amAssistant()) { ?>

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
