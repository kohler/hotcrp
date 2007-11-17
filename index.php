<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
require_once('Code/paperlist.inc');
require_once('Code/search.inc');

$Me = $_SESSION["Me"];
$email_class = '';
$password_class = '';

// signin links
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = defval($_REQUEST, "action", "login");
    $_REQUEST["signin"] = defval($_REQUEST, "signin", "go");
}

if (isset($_REQUEST["signin"]) || isset($_REQUEST["signout"])) {
    if ($Me->valid() && isset($_REQUEST["signout"]))
	$Conf->confirmMsg("You have been signed out.  Thanks for using the system.");
    $Me->invalidate();
    unset($_SESSION["AskedYouToUpdateContactInfo"]);
    unset($_SESSION["l"]);
    unset($_SESSION["foldplau"]);
    unset($_SESSION["foldplanonau"]);
    unset($_SESSION["foldplabstract"]);
    unset($_SESSION["foldpltags"]);
}

function doCreateAccount() {
    global $Conf, $Opt, $Me, $email_class;

    if ($Me->valid()) {
	$email_class = " error";
	return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ".  To retrieve your password, select \"I forgot my password, email it to me\".");
    }

    $result = $Me->initialize($_REQUEST["email"], $Conf);
    if (!$result)
	return $Conf->errorMsg($Conf->dbErrorText(true, "while adding your account"));

    $Me->sendAccountInfo($Conf, true, false);
    $Conf->log("Account created", $Me);
    $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".  ";

    // handle setup phase
    if (defval($Conf->settings, "setupPhase", false)) {
	$msg .= "  As the first user, you have been automatically signed in and assigned PC chair privilege.  Your password is \"<tt>" . htmlspecialchars($Me->password) . "</tt>\".  All later users will have to sign in normally.";
	$while = "while granting PC chair privilege";
	$Conf->qe("insert into Chair (contactId) values (" . $Me->contactId . ")", $while);
	$Conf->qe("insert into PCMember (contactId) values (" . $Me->contactId . ")", $while);
	if ($Conf->setting("allowPaperOption") >= 6)
	    $Conf->qe("update ContactInfo set roles=" . (Contact::ROLE_PC | Contact::ROLE_CHAIR) . " where contactId=" . $Me->contactId, $while);
	$Conf->qe("delete from Settings where name='setupPhase'", "while leaving setup phase");
	$Conf->log("Granted PC chair privilege to first user", $Me);
	$Conf->confirmMsg($msg);
	return true;
    }

    if ($Conf->allowEmailTo($Me->email))
	$msg .= "  A password has been emailed to this address.  When you receive that email, return here to complete the registration process.";
    else {
	if ($Opt['sendEmail'])
	    $msg .= "  The email address you provided seems invalid (it doesn't contain an @).";
	else
	    $msg .= "  The conference system is not set up to mail passwords at this time.";
	$msg .= "  Although an account was created for you, you need the site administrator's help to retrieve your password.  The site administrator is " . htmlspecialchars("$Conf->contactName <$Conf->contactEmail>") . ".";
    }
    if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	$msg .= "  Note that the password you supplied on the login screen was ignored.";
    $Conf->confirmMsg($msg);
    return null;
}

function doLogin() {
    global $Conf, $Me, $email_class, $password_class;
    
    // In all cases, we need to look up the account information
    // to determine if the user is registered
    if (!isset($_REQUEST["email"]) || $_REQUEST["email"] == "") {
	$email_class = " error";
	return $Conf->errorMsg("Enter your email address.");
    }

    // Check for the cookie
    if (!isset($_COOKIE["CRPTestCookie"]) && !isset($_REQUEST["cookie"])) {
	// set a cookie to test that their browser supports cookies
	setcookie("CRPTestCookie", true);
	$url = "cookie=1";
	foreach (array("email", "password", "action", "go", "afterLogin", "signin") as $a)
	    if (isset($_REQUEST[$a]))
		$url .= "&$a=" . urlencode($_REQUEST[$a]);
	$Conf->go("index.php?" . $url);
    } else if (!isset($_COOKIE["CRPTestCookie"]))
	return $Conf->errorMsg("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

    $Me->lookupByEmail($_REQUEST["email"], $Conf);
    if ($_REQUEST["action"] == "new") {
	if (!($reg = doCreateAccount()))
	    return $reg;
	$_REQUEST["password"] = $Me->password;
    }

    if (!$Me->valid()) {
	$email_class = " error";
	return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?");
    }

    if ($_REQUEST["action"] == "forgot") {
	$worked = $Me->sendAccountInfo($Conf, false, true);
	$Conf->log("Sent password", $Me);
	if ($worked)
	    $Conf->confirmMsg("Your password has been emailed to " . $_REQUEST["email"] . ".  When you receive that email, return here to sign in.");
	return null;
    }

    if (!isset($_REQUEST["password"]) || $_REQUEST["password"] == "") {
	$password_class = " error";
	return $Conf->errorMsg("You tried to sign in without providing a password.  Please enter your password and try again.  If you've forgotten your password, enter your email address and use the \"I forgot my password, email it to me\" option.");
    }

    if ($Me->password != $_REQUEST["password"]) {
	$password_class = " error";
	return $Conf->errorMsg("That password doesn't match.  If you've forgotten your password, enter your email address and use the \"I forgot my password, email it to me\" option.");
    }

    $Conf->qe("update ContactInfo set visits=visits+1, lastLogin=" . time() . " where contactId=" . $Me->contactId, "while recording login statistics");
    
    if (isset($_REQUEST["go"]))
	$where = $_REQUEST["go"];
    else if (isset($_SESSION["afterLogin"]))
	$where = $_SESSION["afterLogin"];
    else
	$where = "index.php";

    setcookie("CRPTestCookie", false);
    unset($_SESSION["afterLogin"]);
    //if ($where == "index.php")
    //    return true;
    $Me->go($where);
    exit;
}

if (isset($_REQUEST["email"]) && isset($_REQUEST["action"]) && isset($_REQUEST["signin"])) {
    if (doLogin() !== true) {
	// if we get here, login failed
	$Me->invalidate();
    }
} else
    unset($_SESSION["afterLogin"]);

// set a cookie to test that their browser supports cookies
if (!$Me->valid())
    setcookie("CRPTestCookie", true);

// perhaps redirect through account
if ($Me->valid() && !isset($_SESSION["AskedYouToUpdateContactInfo"]))
    $_SESSION["AskedYouToUpdateContactInfo"] = 0;
if ($Me->valid() && (($_SESSION["AskedYouToUpdateContactInfo"] < 2
		      && !($Me->lastName && $Me->affiliation))
		     || ($_SESSION["AskedYouToUpdateContactInfo"] < 3 
			 && ($Me->roles & Contact::ROLE_PC)
			 && !($Me->collaborators || $Me->anyTopicInterest)))) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $Me->go("account.php?redirect=1");
}

if ($Me->privChair && $Opt["globalSessionLifetime"] < $Opt["sessionLifetime"])
    $Conf->warnMsg("The systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars($Opt["globalSessionLifetime"]) . " seconds, is less than HotCRP's preferred session expiration time, which is " . $Opt["sessionLifetime"] . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users will likely be booted off the system earlier than you expect.");


$Conf->header("Home", "home", actionBar(null, false, ""));
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";


// if chair, check PHP setup
if ($Me->privChair) {
    if (get_magic_quotes_gpc())
	$Conf->errorMsg("The PHP <code>magic_quotes_gpc</code> feature is on.  This is a bad idea; disable it in your <code>php.ini</code> configuration file.");
}


// Home message
if (($v = $Conf->settingText("homemsg")))
    $Conf->infoMsg($v);


echo "<table class='homegrp'>";


// Sign in
if (!$Me->valid()) {
    echo "<tr><td id='homeacct'>
<form method='post' action='index.php'><div class='f-contain'>
<input type='hidden' name='cookie' value='1' />
<div class='f-ii'>
  <div class='f-c", $email_class, "'>Email</div>
  <div class='f-e", $email_class, "'><input id='login_d' type='text' class='textlite' name='email' size='42' tabindex='1' ";
    if (isset($_REQUEST["email"]))
	echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" ";
    echo " /></div>
</div>
<div class='f-i'>
  <div class='f-c", $password_class, "'>Password</div>
  <div class='f-e'><input type='password' class='textlite' name='password' size='42' tabindex='1' value='' /></div>
</div>
<div class='f-i'>
  <input type='radio' name='action' value='login' checked='checked' tabindex='2' />&nbsp;<b>Sign me in</b><br />
  <input type='radio' name='action' value='forgot' tabindex='2' />&nbsp;I forgot my password, email it to me<br />
  <input type='radio' name='action' value='new' tabindex='2' />&nbsp;I'm a new user and want to create an account using this email address
</div>
<div class='f-i'>
  <input class='button' type='submit' value='Sign in' name='signin' tabindex='1' />
</div>
</div></form>
<hr class='home' /></td></tr>\n";
}


// Submissions
$papersub = $Conf->setting("papersub");
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->amReviewer() && $papersub));
if ($homelist) {
    echo "<tr><td id='homelist'><table><tr><td>";

    // Lists
    echo "<strong class='grpt'>List papers: &nbsp;</strong> ";
    $sep = "";
    if ($Me->isReviewer) {
	echo $sep, "<a href='search.php?q=&amp;t=r' class='nowrap'>My reviews</a>";
	$sep = $xsep;
    }
    if ($Me->isPC && $Conf->timePCViewAllReviews()
	&& $Me->amDiscussionLead(0, $Conf)) {
	echo $sep, "<a href=\"search.php?q=lead:", urlencode($Me->email), "&amp;t=s\" class='nowrap'>My discussion leads</a>";
	$sep = $xsep;
    }
    if ($Me->isPC && $papersub) {
	echo $sep, "<a href='search.php?q=&amp;t=s' class='nowrap'>Submitted</a>";
	$sep = $xsep;
    }
    if (($Me->isPC && $Conf->timeAuthorViewDecision() && $papersub)
	|| ($Me->privChair && $Conf->setting("paperacc") > 0)) {
	echo $sep, "<a href='search.php?q=&amp;t=acc' class='nowrap'>Accepted</a>";
	$sep = $xsep;
    }
    if ($Me->privChair) {
	echo $sep, "<a href='search.php?q=&amp;t=all' class='nowrap'>All</a>";
	$sep = $xsep;
    }

    echo "</td></tr><tr><td id='homesearch'>";
    echo "<form method='get' action='search.php'><div class='inform'>",
	"<input class='textlite' type='text' size='32' name='q' value='' /> &nbsp;<input class='button_small' type='submit' value='Search' />",
	"</div></form>\n";
    echo "<span class='sep'></span><small><a href='search.php?opt=1'>Advanced search</a></small>";
    echo "</td></tr></table><hr class='home' /></td></tr>\n";
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
	if ($myrow[2] == 1 && $myrow[1] <= 1)
	   echo "You ", ($myrow[1] == 1 ? "have" : "have not"), " submitted your <a href='search.php?q=&amp;t=r'>1 review</a>";
	else
	   echo "You have submitted ", $myrow[1], " of <a href='search.php?q=&amp;t=r'>", $myrow[2], " ", ($myrow[2] == 1 ? "review" : "reviews"), "</a>";
	if (in_array("overAllMerit", $rf->fieldOrder) && $myrow[1])
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", sprintf("%.2f", $myrow[3]->avg);
	echo ".<br />";
    }
    if (($Me->isPC || $Me->privChair) && $npc) {
	echo sprintf("The average PC member has submitted %.1f reviews", $sumpcSubmit / $npc);
	if (in_array("overAllMerit", $rf->fieldOrder) && $npcScore)
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", sprintf("%.2f", $sumpcScore / $npcScore);
	echo ".";
	if ($Me->isPC || $Me->privChair)
	    echo "&nbsp; <small>(<a href='contacts.php?t=pc&amp;score%5B%5D=0'>Details</a>)</small>";
	echo "<br />";
    }
    if ($myrow && $myrow[1] < $myrow[2]) {
	$rtyp = ($Me->isPC ? "pcrev_" : "extrev_");
	if (!$Conf->timeReviewPaper($Me->isPC, true, true))
	    echo "<span class='deadline'>The <a href='deadlines.php'>deadline</a> for submitting " . ($Me->isPC ? "PC" : "external") . " reviews has passed.</span>";
	else if (!$Conf->timeReviewPaper($Me->isPC, true, false))
	    echo "<span class='deadline'><strong class='overdue'>Reviews are overdue.</strong>  They were requested by " . $Conf->printableTimeSetting("${rtyp}soft") . ".</span>";
	else {
	    $d = $Conf->printableTimeSetting("${rtyp}soft");
	    if ($d == "N/A")
		$d = $Conf->printableTimeSetting("${rtyp}hard");
	    if ($d != "N/A")
		echo "<span class='deadline'>Please submit your ", ($myrow[2] == 1 ? "review" : "reviews"), " by $d.</span>";
	}
    } else if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = $Conf->printableTimeSetting("pcrev_soft");
	if ($d != "N/A")
	    echo "<span class='deadline'>The review deadline is $d.</span>";
    }

    if (($myrow || $Me->privChair) && $npc)
	echo "</td></tr>\n<tr><td id='foldre' class='foldc'>";

    // Actions
    $sep = "";
    if ($myrow) {
	echo $sep, "<a href=\"javascript:fold('re', 0)\" class='foldbutton unfolder'>+</a><a href=\"javascript:fold('re', 1)\" class='foldbutton folder'>&ndash;</a>&nbsp;<a href=\"search.php?q=&amp;t=r\"><strong>My Reviews</strong></a>";
	$sep = $xsep;
    }
    if ($Me->isPC && $Conf->timePCReviewPreferences()) {
	echo $sep, "<a href='PC/reviewprefs.php'>Preferences</a>";
	$sep = $xsep;
    }
    if ($Conf->settingsAfter("rev_open") || $Me->privChair) {
	echo $sep, "<a href='offline.php'>Offline reviewing</a>";
	$sep = $xsep;
    }
    if ($Me->privChair || ($Me->isPC && $Conf->timeReviewPaper(true, false, true))) {
	echo $sep, "<a href='search.php?q=&amp;t=s'>Review any paper</a>";
	$sep = $xsep;
    }
    if ($Me->isRequester) {
	echo $sep, "<a href='PC/CheckReviewStatus.php'>Monitor external reviews</a>";
	$sep = $xsep;
    }
    
    if ($Me->isReviewer) {
	$plist = new PaperList(false, true, new PaperSearch($Me, array("t" => "r")));
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
    if ($Me->isAuthor)
	echo "<strong class='grpt'>My Papers: &nbsp;</strong> ";
    else
	echo "<strong class='grpt'>Submissions: &nbsp;</strong> ";

    $startable = $Conf->timeStartPaper();
    if ($startable && !$Me->valid())
	echo "<span class='deadline'>", $Conf->printableDeadlineSetting('sub_reg'), "</span><br />\n<small>You must sign in to register papers.</small>";
    else if ($startable || $Me->privChair) {
	echo "<strong><a href='paper.php?paperId=new'>Start new paper</a></strong> <span class='deadline'>(", $Conf->printableDeadlineSetting('sub_reg'), ")</span>";
	if ($Me->privChair)
	    echo "<br />\n<small>As an administrator, you can start papers regardless of deadlines and on other people's behalf.</small>";
    }

    $plist = null;
    if ($Me->isAuthor) {
	$plist = new PaperList(false, true, new PaperSearch($Me, array("t" => "a")));
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
    if (!$startable && !count($deadlines)) {
	if ($Conf->settingsAfter('sub_open'))
	    $deadlines[] = "The <a href='deadlines.php'>deadline</a> for registering new papers has passed.";
	else
	    $deadlines[] = "The site has not yet opened for submission, please try again later.";
    }
    if (count($deadlines) > 0) {
	if ($plist && $plist->count > 0)
	    echo "<div class='smgap'></div>";
	else if ($startable || $Me->privChair)
	    echo "<br />";
	echo "<span class='deadline'>",
	    join("</span><br />\n<span class='deadline'>", $deadlines),
	    "</span>";
    }

    echo "<hr class='home' /></td></tr>\n";
}


// Profile
if ($Me->valid()) {
    echo "<tr><td id='homeacct'>";
    echo "<a href='account.php'><strong class='grpt'>My Profile</strong></a>",
	$xsep, "<a href='mergeaccounts.php'>Merge accounts</a>";
    if (($nh = contactNameHtml($Me)))
	echo $xsep, "Welcome, ", $nh, ".";
    else
	echo $xsep, "Welcome.";
    echo $xsep, "<a href='index.php?signout=1'>Sign out</a>";
    // echo "(If this isn't you, please <a href='${ConfSiteBase}index.php?signout=1'>sign out</a>.)";
    // echo "You will be signed out automatically if you are idle for more than ", round(ini_get("session.gc_maxlifetime")/3600), " hours.";
    echo "<hr class='home' /></td></tr>\n";
}


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
    $sep = $xsep;
}
echo $sep, "<a href='contacts.php?t=pc'>Program committee members</a>";
if (isset($Opt['conferenceSite']) && $Opt['conferenceSite'] != $Opt['paperSite'])
    echo $xsep, "<a href='", $Opt['conferenceSite'], "'>Main conference site</a>";
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


// Conference management
if ($Me->privChair) {
    echo "<tr><td id='homemgmt'>";
    
    // Lists
    echo "<strong class='grpt'>Conference management: &nbsp;</strong> ";
    echo "<a href='settings.php'>Settings</a>";
    echo $xsep, "<a href='contacts.php?t=all'>Accounts</a>";
    echo $xsep, "<a href='autoassign.php'>Review assignments</a>";
    echo $xsep, "<a href='mail.php'>Mail users</a>";
    echo $xsep, "<a href='log.php'>Action log</a>";

    echo "<hr class='home' /></td></tr>\n";
}


echo "</table>\n";
$Conf->footer();
