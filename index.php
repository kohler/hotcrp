<?php
require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');

if (!isset($_SESSION["Me"]) || !$_SESSION["Me"]->valid())
    go("login.php");
$Me = $_SESSION["Me"];

if (($_SESSION["AskedYouToUpdateContactInfo"] < 2
     && !($Me->lastName && $Me->affiliation))
    || ($_SESSION["AskedYouToUpdateContactInfo"] < 3 && $Me->isPC
	&& !($Me->collaborators || $Me->anyTopicInterest))) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $Me->go("account.php");
}


$Conf->header("Home", "", actionBar(null, false, ""));


// if chair, check PHP setup
if ($Me->amAssistant()) {
    if (get_magic_quotes_gpc())
	$Conf->errorMsg("The PHP <code>magic_quotes_gpc</code> feature is on.  This is a bad idea; disable it in your <code>php.ini</code> configuration file.");
}


echo "<table class='half'><tr><td class='l'>";


// General information
echo "<div class='bgrp'><div class='bgrp_head'>General</div><div class='bgrp_body'>
Welcome, ", htmlspecialchars($Me->fullnameOrEmail()), ".  (If this isn't you, please <a href='${ConfSiteBase}logout.php'>sign out</a>.)  You will be automatically signed out if you are idle for more than ", round(ini_get("session.gc_maxlifetime")/3600), " hours.\n";

echo "<table class='half'><tr><td class='l'><ul class='compact'>
<li><a href='account.php'>Your account settings</a></li>
<li><a href='mergeaccounts.php'>Merge accounts</a></li>
</ul></td><td class='r'><ul class='compact'>\n";

// Any deadlines set?
if ($Conf->setting('sub_reg') || $Conf->setting('sub_update') || $Conf->setting('sub_sub')
    || ($Me->isAuthor && $Conf->setting('resp_open') > 0 && $Conf->setting('resp_done'))
    || ($Me->isPC && $Conf->setting('rev_open') && $Conf->setting('pcrev_hard'))
    || ($Me->amReviewer() && $Conf->setting('rev_open') && $Conf->setting('extrev_hard')))
    echo "<li><a href='deadlines.php'>Deadlines</a></li>\n";

echo "<li><a href='pc.php'>Program committee membership</a></li>\n";

echo "</ul></td></tr></table>";

if ($Me->amAssistant())
    echo "\n<div class='smgap'></div>
<table class='half'><tr><td class='l'><ul class='compact'>
<li><a href='settings.php'><b>Conference settings</b></a></li>
</ul></td></tr></table>
<div class='smgap'></div>
<table class='half'><tr><td class='l'><ul class='compact'>
<li><a href='account.php?new=1'>Create new account</a></li>
<li><a href='Chair/BecomeSomeoneElse.php'>Sign in as someone else</a></li>
</ul></td><td class='r'><ul class='compact'>
<li><a href='Chair/SendMail.php'>Send users mail</a></li>
<li><a href='Chair/ViewActionLog.php'>View action log</a></li>
</ul></td></tr></table>";

echo "</div></div>\n";



// Submissions
$papersub = $Conf->setting("papersub");
if ($Me->amAssistant() || ($Me->isPC && $papersub)) {
    echo "<div class='bgrp'><div class='bgrp_head'>Submissions</div><div class='bgrp_body'>\n";
    echo "<form method='get' action='search.php'><input class='textlite' type='text' size='32' name='q' value='' /> <input class='button_small' type='submit' name='go' value='Search' /></form>\n";
    echo "<span class='sep'></span><a href='search.php?opt=1'>Advanced search</a>";
    echo "<table class='half'><tr><td class='l'><ul class='compact'>\n";
    echo "<li><a href='search.php?q=&amp;t=s'>List submitted papers</a></li>\n";
    if ($Me->canViewDecision(null, $Conf))
	echo "<li><a href='search.php?q=decision:yes&amp;t=s'>List accepted papers</a></li>\n";
    echo "</ul></td><td class='r'><ul class='compact'>";
    if ($Me->amAssistant())
	echo "<li><a href='search.php?q=&amp;t=all'>List <i>all</i> papers</a></li>\n";
    echo "</ul></td></tr></table></div></div>\n";
}


echo "</td><td class='r'>";


// Authored papers
if ($Me->isAuthor || $Conf->timeStartPaper() > 0 || $Me->amAssistant()) {
    echo "<div class='bgrp'><div class='bgrp_head'>Authored papers</div><div class='bgrp_body'>\n";
    $sep = "";

    $startable = $Conf->timeStartPaper();
    if ($startable || $Me->amAssistant()) {
	echo $sep, "<div><strong><a href='paper.php?paperId=new'>Start new paper</a></strong> <span class='deadline'>(" . $Conf->printableDeadlineSetting('sub_reg') . ")</span>";
	if ($Me->amAssistant())
	    echo "<br/>\n<small>As PC Chair, you can start papers regardless of deadlines and on other people's behalf.</small>";
	echo "</div>\n";
	$sep = "<div class='smgap'></div>";
    }

    if ($Me->isAuthor) {
	$plist = new PaperList(false, "aulist", new PaperSearch($Me, array("t" => "a")));
	$plist->showHeader = 0;
	$ptext = $plist->text("authorHome", $Me, "Authored papers");
	$deadlines = array();
	if ($plist->count > 0) {
	    echo $sep, $ptext;
	    $sep = "<div class='smgap'></div>";
	}
	if ($plist->needFinalize > 0) {
	    if (!$Conf->timeFinalizePaper())
		$deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting papers in progress has passed.";
	    else if (!$Conf->timeUpdatePaper()) {
		$deadlines[] = "The <a href='deadlines.php'>deadline</a> for updating papers in progress has passed, but you can still submit.";
		$time = $Conf->printableTimeSetting('sub_sub');
		if ($time != 'N/A')
		    $deadlines[] = "You have until $time to submit any papers in progress.";
	    } else if (($time = $Conf->printableTimeSetting('sub_update')) != 'N/A')
		$deadlines[] = "You have until $time to submit any papers in progress.";
	}
	if (!$startable && !$Conf->timeAuthorViewReviews())
	    $deadlines[] = "The <a href='deadlines.php'>deadline</a> for starting new papers has passed.";
	if (count($deadlines) > 0)
	    echo $sep, join("<br/>", $deadlines);
    }

    echo "</div></div>\n";
}


// Review assignment
if ($Me->amReviewer() && ($Me->amAssistant() || $papersub)) {
    echo "<div class='bgrp foldc' id='foldre'><div class='bgrp_head'>";
    if ($Me->isReviewer)
	echo "<a href=\"javascript:fold('re', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('re', 1)\" class='foldbutton folder'>&minus;</a>&nbsp;";
    echo "Review assignments</div><div class='bgrp_body'>\n";
    $sep = "";

    echo "<table class='half'><tr><td class='l'><ul class='compact'>\n";
    if ($Me->isReviewer)
	echo "<li><a href='search.php?q=&amp;t=r'>List assigned papers</a></li>\n";
    if ($Me->isPC && $Conf->timePCReviewPreferences())
	echo "<li><a href='PC/reviewprefs.php'>Mark review preferences</a></li>\n";
    echo "</ul></td><td class='r'><ul class='compact'>\n";
    if ($Me->amReviewer())
	echo "<li><a href='offline.php'>Offline reviewing</a></li>\n";
    if ($Me->amAssistant())
	echo "<li><a href='Chair/AssignPapers.php'>PC review assignments and conflicts</a></li>\n";
    echo "</ul></td></tr></table>\n<div class='smgap'></div>\n";
    
    unset($plist);
    if ($Me->isReviewer) {
	$plist = new PaperList(false, "relist", new PaperSearch($Me, array("t" => "r")));
	$ptext = $plist->text("reviewerHome", $Me, "Review assignment");
    }
    
    $deadlines = array();
    $rtyp = ($Me->isPC ? "pcrev_" : "extrev_");
    unset($d);
    if ($Me->isPC && $Conf->timeReviewPaper(true, false, true))
	$deadlines[] = "PC members may review <a href='search.php?q=&amp;t=s'>any submitted paper</a>, whether or not a review has been assigned.";
    if ((isset($plist) && $plist->needSubmitReview == 0) || !$Me->isReviewer)
	/* do nothing */;
    else if (!$Conf->timeReviewPaper($Me->isPC, true, true))
	$deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting " . ($Me->isPC ? "PC" : "external") . " reviews has passed.";
    else if (!$Conf->timeReviewPaper($Me->isPC, true, false))
	$deadlines[] = "Reviews were requested by " . $Conf->printableTimeSetting("${rtyp}soft") . ".";
    else {
	$d = $Conf->printableTimeSetting("${rtyp}soft");
	if ($d != "N/A")
	    $deadlines[] = "Please submit your reviews by $d.";
    }
    if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = (isset($d) ? "N/A" : $Conf->printableTimeSetting("pcrev_soft"));
	if ($d != "N/A")
	    $deadlines[] = "Please submit your reviews by $d.";
    }
    if (count($deadlines) > 0) {
	echo $sep, join("<br />", $deadlines);
	$sep = "<div class='smgap'></div>";
    }

    if (isset($plist) && $plist->count > 0) {
	echo "<div class='smgap extension'>", $ptext, "</div>";
	$sep = "<div class='smgap'></div>";
    }

    echo "</div></div>\n";
}


// PC tasks (old CRP)
if ($Me->isPC) {
    echo "<div class='bgrp foldc' id='foldpc'><div class='bgrp_head'><a href=\"javascript:fold('pc', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('pc', 1)\" class='foldbutton folder'>&minus;</a>&nbsp;PC member tasks (old CRP)</div><div class='bgrp_body extension'>\n";

    $Conf->infoMsg(" You need to write up your own review for any "
		  . " assigned Primary paper, and ask one or more other people "
		  . " for reviews for your assigned Secondary papers");

    $Conf->reviewerSummary($Me->contactId);

    echo "<ul>
<li>Reviewer assignments  (asking others to review papers)
  <ul>
  <li><a href='PC/CheckReviewStatus.php'>Check on reviewer progress</a> (and possibly nag reviewers)</li>
  <li><a href='PC/SpotMyProblems.php'>See which missing reviews are most important</a> based on how many reviews have been submitted by everyone</li>
  </ul></li>

<li>The End Game - Activities Prior to the PC Meeting
  <ul>\n";
    if ($Conf->timePCViewGrades())
	echo "  <li><a href='PC/GradePapers.php'>Grade Papers</a>
-- arrive at a consensus and determine discussion order of papers at PC meeting</li>\n";
    if ($Conf->timePCViewAllReviews()) {
	echo "  <li><a href='PC/SeeAllGrades.php'>See overall merit and grades for all papers</a> -- you can get to reviews from here as well</li>\n";
	echo "  <li><a href='PC/CheckOnPCProgress.php'>Spy On Your Neighbours</a> -- See progress of entire PC</li>\n";
	echo "  <li><a href='Chair/SpotProblems.php'>Spot problems across all papers</a></li>\n";
	echo "  <li><a href='Chair/AverageReviewerScore.php'>See average reviewer ratings</a> -- this compares the overall merit ratings of different reviewers</li>\n";
    }
    if ($Conf->timePCViewGrades())
	echo "  <li><a href='Chair/AveragePaperScore.php'>See Average Paper Scores</a></li>\n";
    echo "</ul></li>\n";
    echo "</ul></div></div>\n";
}    


// Chair/assistant tasks (old CRP)
if ($Me->amAssistant()) {
    echo "<div class='bgrp foldc' id='foldch'><div class='bgrp_head'><a href=\"javascript:fold('ch', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('ch', 1)\" class='foldbutton folder'>&minus;</a>&nbsp;PC chair tasks (old CRP)</div><div class='bgrp_body extension'>\n";

    echo "<ul>
<li>Manage all papers
  <ul>
  <li><a href='Chair/GradeAllPapers.php'>Assign Paper Grades</a></li>
  </ul></li>

<li>Assign papers
  <ul> 
  <li><a href='Chair/FindPCConflicts.php'>Look for conflicts</a> -- by searching for PC names</li>
  <li><a href='Chair/AskForReview.php'>Ask someone to review a paper (any paper)</a></li>
  </ul></li>

<li>Check on reviewing progress
  <ul>
  <li><a href='Chair/ListReviews.php'>See all the people</a> that PC members have requested to review papers.</li>
  <li><a href='Chair/CheckOnPCProgress.php'>See PC progress</a> on reviewing; you can also see the review requests made	by this specific PC member.</li>
  <li><a href='Chair/SpotProblems.php'>Spot Reviewing Problems</a></li>
  <li><a href='Chair/AverageReviewerScore.php'>See average reviewer score</a></li>
  <li><a href='Chair/AveragePaperScore.php'>See Average Paper Scores</a></li>
  </ul></li>

<li>Contact authors &amp; prepare facesheets
  <ul>
  <li><a href='Chair/ListReviewers.php'>List all reviewers (email and name)</a></li>
  </ul></li>\n";

    if (isset($Opt['dbDumpDir']))
	echo "<li><a href='Chair/DumpDatabase.php'>Make a backup of the database</a></li>\n";

    echo "<li>Help prepare information about paper
  <ul>
  <li><a href='Assistant/PrintAllAbstracts.php'>Show all abstracts for printing</a></li>
  <li><a href='Assistant/PrintAllReviews.php'>Show all reviews for printing</a></li>
  <li><a href='Assistant/PrintSomeReviews.php'>Show <b>some</b> reviews for printing</a> -- you can use this to eliminate papers unlikely to be accepted</li>
  <li><a href='Assistant/ModifyUserNames.php'>Modify user names</a> in account database prior to preparing face sheets</li>
  <li><a href='Assistant/PrepareFacesheets.php'>Prepare information for face sheets</a></li>
  </ul></li>\n";
    
    echo "</ul></div></div>\n";
}    


echo "</td></tr></table>\n";


$Conf->footer();

