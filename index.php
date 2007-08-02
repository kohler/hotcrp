<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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

if ($Me->privChair && $Opt["globalSessionLifetime"] < $Opt["sessionLifetime"])
    $Conf->warnMsg("The systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars($Opt["globalSessionLifetime"]) . " seconds, is less than HotCRP's preferred session expiration time, which is " . $Opt["sessionLifetime"] . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users will likely be booted off the system earlier than you expect.");


$Conf->header("Home", "home", actionBar(null, false, ""));
$thesep = " &nbsp;|&nbsp; ";


// if chair, check PHP setup
if ($Me->privChair) {
    if (get_magic_quotes_gpc())
	$Conf->errorMsg("The PHP <code>magic_quotes_gpc</code> feature is on.  This is a bad idea; disable it in your <code>php.ini</code> configuration file.");
}


// Home message
if (($v = $Conf->settingText("homemsg")))
    $Conf->infoMsg($v);


echo "<table class='homegrp'>";


// Submissions
$papersub = $Conf->setting("papersub");
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->amReviewer() && $papersub));
if ($homelist) {
    echo "<tr><td id='homelist'><table><tr><td>";

    // Lists
    echo "<strong class='grpt'>List papers: &nbsp;</strong> ";
    $sep = "";
    if ($Me->isReviewer) {
	echo $sep, "<a href='search.php?q=&amp;t=r' class='nowrap'>Your review assignment</a>";
	$sep = $thesep;
    }
    if ($Me->isPC && $Conf->timePCViewAllReviews()
	&& $Me->amDiscussionLead(0, $Conf)) {
	echo $sep, "<a href=\"search.php?q=lead:", urlencode($Me->email), "&amp;t=s\" class='nowrap'>Your discussion lead</a>";
	$sep = $thesep;
    }
    if ($Me->isPC && $papersub) {
	echo $sep, "<a href='search.php?q=&amp;t=s' class='nowrap'>Submitted</a>";
	$sep = $thesep;
    }
    if (($Me->isPC && $Conf->timeAuthorViewDecision() && $papersub)
	|| ($Me->privChair && $Conf->setting("paperacc") > 0)) {
	echo $sep, "<a href='search.php?q=&amp;t=acc' class='nowrap'>Accepted</a>";
	$sep = $thesep;
    }
    if ($Me->privChair) {
	echo $sep, "<a href='search.php?q=&amp;t=all' class='nowrap'>All</a>";
	$sep = $thesep;
    }

    echo "</td></tr><tr><td id='homesearch'>";
    echo "<form method='get' action='search.php'><input class='textlite' type='text' size='32' name='q' value='' /> &nbsp;<input class='button_small' type='submit' value='Search' /></form>\n";
    echo "<span class='sep'></span><small><a href='search.php?opt=1'>Advanced search</a></small>";
    echo "</td></tr></table><hr class='home' /></td></tr>\n";
}


// Conference management
if ($Me->privChair) {
    echo "<tr><td id='homemgmt'>";
    
    // Lists
    echo "<strong class='grpt'>Conference management: &nbsp;</strong> ";
    echo "<a href='settings.php'>Settings</a>";
    echo $thesep, "<a href='contacts.php?t=all'>Accounts</a>";
    echo $thesep, "<a href='autoassign.php'>Review assignments</a>";
    echo $thesep, "<a href='mail.php'>Mail users</a>";
    echo $thesep, "<a href='log.php'>Action log</a>";

    echo "<hr class='home' /></td></tr>\n";
}


// Review assignment
if ($Me->amReviewer() && ($Me->privChair || $papersub)) {
    echo "<tr><td id='homerev'>";
    
    // Overview
    echo "<strong class='grpt'>Reviewing: &nbsp;</strong> ";
    $result = $Conf->qe("select PaperReview.contactId, count(reviewSubmitted), count(reviewNeedsSubmit), group_concat(overAllMerit), PCMember.contactId as pc from PaperReview join Paper using (paperId) left join PCMember on (PaperReview.contactId=PCMember.contactId) where Paper.timeSubmitted>0 group by PaperReview.contactId", "while fetching review status");
    $rf = reviewForm();
    $maxOverAllMerit = $rf->maxNumericScore("overAllMerit");
    $npc = $npcScore = $sumpcScore = $sumpcSubmit = 0;
    $myrow = null;
    while (($row = edb_row($result))) {
	$row[3] = scoreCounts($row[3], $maxOverAllMerit);
	if ($row[0] == $Me->contactId)
	    $myrow = $row;
	if ($row[4]) {
	    $npc++;
	    $sumpcSubmit += $row[1];
	}
	if ($row[4] && $row[1]) {
	    $npcScore++;
	    $sumpcScore += $row[3]->avg;
	}
    }
    if ($myrow) {
	echo "You have submitted ", $myrow[1], " of <a href='search.php?q=&amp;t=r'>", $myrow[2], " reviews</a>";
	if (in_array("overAllMerit", $rf->fieldOrder) && $myrow[1])
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", sprintf("%.2f", $myrow[3]->avg);
	echo ".<br />";
    }
    if (($myrow || $Me->privChair) && $npc) {
	echo sprintf("The average PC member has submitted %.1f reviews", $sumpcSubmit / $npc);
	if (in_array("overAllMerit", $rf->fieldOrder) && $npcScore)
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", sprintf("%.2f", $sumpcScore / $npcScore);
	echo ".";
	if ($Me->isPC || $Me->privChair)
	    echo "&nbsp; <small>(<a href='contacts.php?t=pc&amp;score%5B%5D=0'>Details</a>)</small><br />";
    }
    if ($myrow && $myrow[1] < $myrow[2]) {
	$rtyp = ($Me->isPC ? "pcrev_" : "extrev_");
	if (!$Conf->timeReviewPaper($Me->isPC, true, true))
	    echo "<span class='deadline'>The <a href='deadlines.php'>deadline</a> for submitting " . ($Me->isPC ? "PC" : "external") . " reviews has passed.</span>";
	else if (!$Conf->timeReviewPaper($Me->isPC, true, false))
	    echo "<span class='deadline'><strong class='overdue'>Reviews are overdue.</strong>  They were requested by " . $Conf->printableTimeSetting("${rtyp}soft") . ".</span>";
	else {
	    $d = $Conf->printableTimeSetting("${rtyp}soft");
	    if ($d != "N/A")
		echo "<span class='deadline'>Please submit your reviews by $d.</span>";
	}
    } else if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = $Conf->printableTimeSetting("${rtyp}soft");
	if ($d != "N/A")
	    echo "<span class='deadline'>The review deadline is $d.</span>";
    }

    if (($myrow || $Me->privChair) && $npc)
	echo "</td></tr>\n<tr><td id='foldre' class='foldc'>";

    // Actions
    $sep = "";
    if ($myrow) {
	echo $sep, "<a href=\"javascript:fold('re', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('re', 1)\" class='foldbutton folder'>&minus;</a>&nbsp;<a href=\"search.php?q=&amp;t=r\"><strong>My Reviews</strong></a>";
	$sep = $thesep;
    }
    if ($Conf->settingsAfter("rev_open") || $Me->privChair) {
	echo $sep, "<a href='offline.php'>Offline reviewing</a>";
	$sep = $thesep;
    }
    if ($Me->isPC && $Conf->timePCReviewPreferences()) {
	echo $sep, "<a href='PC/reviewprefs.php'>Preferences</a>";
	$sep = $thesep;
    }
    if ($Me->privChair || ($Me->isPC && $Conf->timeReviewPaper(true, false, true))) {
	echo $sep, "<a href='search.php?q=&amp;t=s'>Review any paper</a>";
	$sep = $thesep;
    }
    if ($Me->isRequester) {
	echo $sep, "<a href='PC/CheckReviewStatus.php'>Monitor external reviews</a>";
	$sep = $thesep;
    }
    
    if ($Me->isReviewer) {
	$plist = new PaperList(false, "listre", new PaperSearch($Me, array("t" => "r")));
	$ptext = $plist->text("reviewerHome", $Me);
	if ($plist->count > 0)
	    echo "<div class='smgap extension'>", $ptext, "</div>";
    }

    echo "<hr class='home' /></td></tr>\n";
}


// Authored papers
if ($Me->isAuthor || $Conf->timeStartPaper() > 0 || $Me->privChair
    || !$Me->amReviewer()) {
    echo "<tr><td id='homeau'>";

    // Overview
    echo "<strong class='grpt'>My Papers: &nbsp;</strong> ";

    $startable = $Conf->timeStartPaper();
    if ($startable || $Me->privChair) {
	echo "<strong><a href='paper.php?paperId=new'>Start new paper</a></strong> <span class='deadline'>(" . $Conf->printableDeadlineSetting('sub_reg') . ")</span>";
	if ($Me->privChair)
	    echo "<br />\n<small>As an administrator, you can start papers regardless of deadlines and on other people's behalf.</small>";
    }

    $plist = null;
    if ($Me->isAuthor) {
	$plist = new PaperList(false, "listau", new PaperSearch($Me, array("t" => "a")));
	$plist->showHeader = 0;
	$ptext = $plist->text("authorHome", $Me);
	if ($plist->count > 0)
	    echo "<div class='smgap'></div>\n", $ptext;
    }

    $deadlines = array();
    if ($plist && $plist->needFinalize > 0) {
	if (!$Conf->timeFinalizePaper())
	    $deadlines[] = "The <a href='deadlines.php'>deadline</a> for submitting papers has passed.";
	else if (!$Conf->timeUpdatePaper()) {
	    $deadlines[] = "The <a href='deadlines.php'>deadline</a> for updating papers has passed, but you can still submit.";
	    $time = $Conf->printableTimeSetting('sub_sub');
	    if ($time != 'N/A')
		$deadlines[] = "You have until $time to submit papers.";
	} else if (($time = $Conf->printableTimeSetting('sub_update')) != 'N/A')
	    $deadlines[] = "You have until $time to submit papers.";
    }
    if (!$startable && !$Conf->timeAuthorViewReviews() && !count($deadlines))
	$deadlines[] = "The <a href='deadlines.php'>deadline</a> for registering new papers has passed.";
    if (count($deadlines) > 0) {
	if ($plist && $plist->count > 0)
	    echo "<div class='smgap'></div>";
	echo "<span class='deadline'>",
	    join("</span><br />\n<span class='deadline'>", $deadlines),
	    "</span>";
    }

    echo "<hr class='home' /></td></tr>\n";
}


// Profile
echo "<tr><td id='homeacct'>";
echo "<strong class='grpt'>My Account: &nbsp;</strong> ";
echo "<a href='account.php'>Profile</a>";
echo $thesep, "<a href='mergeaccounts.php'>Merge accounts</a>";
echo $thesep, "Welcome, ", contactNameHtml($Me), ".  (If this isn't you, please <a href='${ConfSiteBase}logout.php'>sign out</a>.)";
// echo "You will be signed out automatically if you are idle for more than ", round(ini_get("session.gc_maxlifetime")/3600), " hours.";
echo "<hr class='home' /></td></tr>\n";


// Conference info
echo "<tr><td id='homeinfo'>";
echo "<strong class='grpt'>Conference information: &nbsp;</strong> ";
// Any deadlines set?
$sep = "";
if ($Conf->setting('sub_reg') || $Conf->setting('sub_update') || $Conf->setting('sub_sub')
    || ($Me->isAuthor && $Conf->setting('resp_open') > 0 && $Conf->setting('resp_done'))
    || ($Me->isPC && $Conf->setting('rev_open') && $Conf->setting('pcrev_hard'))
    || ($Me->amReviewer() && $Conf->setting('rev_open') && $Conf->setting('extrev_hard'))) {
    echo $sep, "<a href='deadlines.php'>Deadlines</a>";
    $sep = $thesep;
}
echo $sep, "<a href='contacts.php?t=pc'>Program committee members</a>";
if (isset($Opt['conferenceSite']) && $Opt['conferenceSite'] != $Opt['paperSite'])
    echo $thesep, "<a href='", $Opt['conferenceSite'], "'>Main conference site</a>";
if ($Conf->timeAuthorViewDecision()) {
    $result = $Conf->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 group by outcome", "while loading acceptance statistics");
    $n = $nyes = 0;
    while (($row = edb_row($result))) {
	$n += $row[1];
	if ($row[0] > 0)
	    $nyes += $row[1];
    }
    echo "<br />", plural($nyes, "paper"), " were accepted out of ", $n, " submitted.";
}
    
echo "<hr class='home' /></td></tr>\n";


echo "</table>\n";
unset($_SESSION["list"]);
$Conf->footer();
