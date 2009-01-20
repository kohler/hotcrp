<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/paperlist.inc");
require_once("Code/search.inc");

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
    unset($_SESSION["info"]);
    unset($_SESSION["rev_tokens"]);
    unset($_SESSION["rev_token_fail"]);
    foreach ($allowedSessionVars as $v)
	unset($_SESSION[$v]);
    if (isset($_REQUEST["signout"]))
	unset($_SESSION["afterLogin"]);
}

function doFirstUser($msg) {
    global $Conf, $Opt, $Me;
    $msg .= "As the first user, you have been automatically signed in and assigned system administrator privilege.";
    if (!isset($Opt["ldapLogin"]))
	$msg .= "  Your password is &ldquo;<tt>" . htmlspecialchars($Me->password) . "</tt>&rdquo;.  All later users will have to sign in normally.";
    $while = "while granting system administrator privilege";
    $Conf->qe("insert into ChairAssistant (contactId) values (" . $Me->contactId . ")", $while);
    if ($Conf->sversion >= 6)
	$Conf->qe("update ContactInfo set roles=" . (Contact::ROLE_ADMIN) . " where contactId=" . $Me->contactId, $while);
    $Conf->qe("delete from Settings where name='setupPhase'", "while leaving setup phase");
    $Conf->log("Granted system administrator privilege to first user", $Me);
    $Conf->confirmMsg($msg);
    if (!function_exists("imagecreate"))
	$Conf->warnMsg("Your PHP installation appears to lack GD support, which is required for drawing score graphs.  You may want to fix this problem and restart Apache.");
    return true;
}

function doCreateAccount() {
    global $Conf, $Opt, $Me, $email_class;

    if ($Me->valid() && $Me->visits > 0) {
	$email_class = " error";
	return $Conf->errorMsg("An account already exists for " . htmlspecialchars($_REQUEST["email"]) . ".  To retrieve your password, select &ldquo;I forgot my password, email it to me.&rdquo;");
    } else if (!validateEmail($_REQUEST["email"])) {
	$email_class = " error";
	return $Conf->errorMsg("&ldquo;" . htmlspecialchars($_REQUEST["email"]) . "&rdquo; is not a valid email address.");
    } else if (!$Me->valid()) {
	$result = $Me->initialize($_REQUEST["email"]);
	if (!$result)
	    return $Conf->errorMsg($Conf->dbErrorText(true, "while adding your account"));
    }

    $Me->sendAccountInfo($Conf, true, true);
    $Conf->log("Account created", $Me);
    $msg = "Successfully created an account for " . htmlspecialchars($_REQUEST["email"]) . ".  ";

    // handle setup phase
    if (defval($Conf->settings, "setupPhase", false))
	return doFirstUser($msg);

    if ($Conf->allowEmailTo($Me->email))
	$msg .= "A password has been emailed to you.  Return here when you receive it to complete the registration process.  If you don't receive the email, check your spam folders and verify that you entered the correct address.";
    else {
	if ($Opt['sendEmail'])
	    $msg .= "The email address you provided seems invalid.";
	else
	    $msg .= "The conference system is not set up to mail passwords at this time.";
	$msg .= "Although an account was created for you, you need the site administrator's help to retrieve your password.  The site administrator is " . htmlspecialchars($Opt["contactName"] . " <" . $Opt["contactEmail"] . ">") . ".";
    }
    if (isset($_REQUEST["password"]) && $_REQUEST["password"] != "")
	$msg .= "Note that the password you supplied on the login screen was ignored.";
    $Conf->confirmMsg($msg);
    return null;
}

function doLDAPLogin() {
    // check for bogus configurations
    if (!function_exists("ldap_connect") || !function_exists("ldap_bind"))
	return $Conf->errorMsg("Internal error: <code>\$Opt[\"ldapLogin\"]</code> is set, but this PHP installation doesn't support LDAP.  Logins will fail until this error is fixed.");

    // the body is elsewhere because we need LDAP constants, which might[?]
    // cause errors absent LDAP support
    require_once("Code/ldaplogin.inc");
    return ldapLoginAction();
}

function doLogin() {
    global $Conf, $Opt, $ConfSiteSuffix, $Me, $email_class, $password_class;

    // In all cases, we need to look up the account information
    // to determine if the user is registered
    if (!isset($_REQUEST["email"])
        || ($_REQUEST["email"] = trim($_REQUEST["email"])) == "") {
	$email_class = " error";
	if (isset($Opt["ldapLogin"]))
	    return $Conf->errorMsg("Enter your LDAP username.");
	else
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
	$Conf->go("?" . $url);
    } else if (!isset($_COOKIE["CRPTestCookie"]))
	return $Conf->errorMsg("You appear to have disabled cookies in your browser, but this site needs to set cookies to function.  Google has <a href='http://www.google.com/cookies.html'>an informative article on how to enable them</a>.");

    // do LDAP login before validation, since we might create an account
    if (isset($Opt["ldapLogin"])) {
	$_REQUEST["action"] = "login";
	if (!doLDAPLogin())
	    return false;
    }

    $Me->lookupByEmail($_REQUEST["email"]);
    if ($_REQUEST["action"] == "new") {
	if (!($reg = doCreateAccount()))
	    return $reg;
	$_REQUEST["password"] = $Me->password;
    }

    if (!$Me->valid()) {
	if (isset($Opt["ldapLogin"])) {
	    $result = $Me->initialize($_REQUEST["email"], true);
	    if (!$result)
		return $Conf->errorMsg($Conf->dbErrorText(true, "while adding your account"));
	    if (defval($Conf->settings, "setupPhase", false))
		return doFirstUser($msg);
	} else {
	    $email_class = " error";
	    return $Conf->errorMsg("No account for " . htmlspecialchars($_REQUEST["email"]) . " exists.  Did you enter the correct email address?");
	}
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
	return $Conf->errorMsg("Enter your password.  If you've forgotten it, enter your email address and use the &ldquo;I forgot my password, email it to me&rdquo; option.");
    }

    if ($Me->password != $_REQUEST["password"] && !isset($Opt["ldapLogin"])) {
	$password_class = " error";
	return $Conf->errorMsg("That password doesn't match.  If you've forgotten your password, enter your email address and use the &ldquo;I forgot my password, email it to me&rdquo; option.");
    }

    $Conf->qe("update ContactInfo set visits=visits+1, lastLogin=" . time() . " where contactId=" . $Me->contactId, "while recording login statistics");

    if (isset($_REQUEST["go"]))
	$where = $_REQUEST["go"];
    else if (isset($_SESSION["afterLogin"]))
	$where = $_SESSION["afterLogin"];
    else
	$where = "index$ConfSiteSuffix";

    setcookie("CRPTestCookie", false);
    unset($_SESSION["afterLogin"]);
    //if ($where == "index$ConfSiteSuffix")
    //    return true;
    $Me->go($where);
    exit;
}

if (isset($_REQUEST["email"]) && isset($_REQUEST["action"]) && isset($_REQUEST["signin"])) {
    if (doLogin() !== true) {
	// if we get here, login failed
	$Me->invalidate();
    }
}

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
			 && !$Me->collaborators))) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 1;
    $Me->go("account$ConfSiteSuffix?redirect=1");
}

if ($Me->privChair && $Opt["globalSessionLifetime"] < $Opt["sessionLifetime"])
    $Conf->warnMsg("The systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars($Opt["globalSessionLifetime"]) . " seconds, is less than HotCRP's preferred session expiration time, which is " . $Opt["sessionLifetime"] . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users will likely be booted off the system earlier than you expect.");

// review tokens
if (isset($_REQUEST["token"]) && $Me->valid() && $Conf->sversion >= 13) {
    $oldtokens = isset($_SESSION["rev_tokens"]);
    unset($_SESSION["rev_tokens"]);
    $tokeninfo = array();
    foreach (preg_split('/\s+/', $_REQUEST["token"]) as $x)
	if ($x == "")
	    /* no complaints */;
	else if (!($token = decodeToken($x)))
	    $Conf->errorMsg("Invalid review token &ldquo;" . htmlspecialchars($token) . ".&rdquo;  Check your typing and try again.");
	else if (defval($_SESSION, "rev_token_fail", 0) >= 5)
	    $Conf->errorMsg("Too many failed attempts to use a review token.  <a href='index$ConfSiteSuffix?signout=1'>Sign out</a> and in to try again.");
	else {
	    $result = $Conf->qe("select paperId from PaperReview where reviewToken=$token", "while searching for review token");
	    if (($row = edb_row($result))) {
		$tokeninfo[] = "Review token &ldquo;" . htmlspecialchars($x) . "&rdquo; lets you review <a href='paper$ConfSiteSuffix?p=$row[0]'>paper #" . $row[0] . "</a>.";
		if (!isset($_SESSION["rev_tokens"]) || array_search($token, $_SESSION["rev_tokens"]) === false)
		    $_SESSION["rev_tokens"][] = $token;
		$Me->isReviewer = true;
	    } else {
		$Conf->errorMsg("Review token &ldquo;" . htmlspecialchars($x) . "&rdquo; hasn't been assigned.");
		$_SESSION["rev_token_fail"] = defval($_SESSION, "rev_token_fail", 0) + 1;
	    }
	}
    if (count($tokeninfo))
	$Conf->infoMsg(join("<br />\n", $tokeninfo));
    else if ($oldtokens)
	$Conf->infoMsg("Review tokens cleared.");
}
if (isset($_REQUEST["cleartokens"]) && $Me->valid())
    unset($_SESSION["rev_tokens"]);


$Conf->header($Me->valid() ? "Home" : "Sign in", "home", actionBar(null, false, ""));
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";


// if chair, check PHP setup
if ($Me->privChair) {
    if (get_magic_quotes_gpc())
	$Conf->errorMsg("The PHP <code>magic_quotes_gpc</code> feature is on.  This is a bad idea; disable it in your <code>php.ini</code> configuration file.");
}


// Sidebar
echo "<div class='homeside'>";

// Conference management
if ($Me->privChair) {
    echo "<div id='homemgmt' class='homeinside'>
  <h4>Administration</h4>
  <ul>
    <li><a href='settings$ConfSiteSuffix'>Settings</a></li>
    <li><a href='contacts$ConfSiteSuffix?t=all'>Users</a></li>
    <li><a href='autoassign$ConfSiteSuffix'>Assign reviews</a></li>
    <li><a href='mail$ConfSiteSuffix'>Send mail</a></li>
    <li><a href='log$ConfSiteSuffix'>Action log</a></li>
  </ul>
</div>\n";
}

// Conference info sidebar
echo "<div class='homeinside'><div id='homeinfo'>
  <h4>Conference information</h4>
  <ul>\n";
// Any deadlines set?
$sep = "";
if ($Conf->setting('sub_reg') || $Conf->setting('sub_update') || $Conf->setting('sub_sub')
    || ($Me->isAuthor && $Conf->setting('resp_open') > 0 && $Conf->setting('resp_done'))
    || ($Me->isPC && $Conf->setting('rev_open') && $Conf->setting('pcrev_hard'))
    || ($Me->amReviewer() && $Conf->setting('rev_open') && $Conf->setting('extrev_hard'))) {
    echo "    <li><a href='deadlines$ConfSiteSuffix'>Deadlines</a></li>\n";
}
echo "    <li><a href='contacts$ConfSiteSuffix?t=pc'>Program committee</a></li>\n";
if (isset($Opt['conferenceSite']) && $Opt['conferenceSite'] != $Opt['paperSite'])
    echo "    <li><a href='", $Opt['conferenceSite'], "'>Conference site</a></li>\n";
if ($Conf->timeAuthorViewDecision()) {
    $result = $Conf->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 group by outcome", "while loading acceptance statistics");
    $n = $nyes = 0;
    while (($row = edb_row($result))) {
	$n += $row[1];
	if ($row[0] > 0)
	    $nyes += $row[1];
    }
    echo "    <li>", plural($nyes, "paper"), " were accepted out of ", $n, " submitted.</li>\n";
}
echo "  </ul>\n</div>\n";


// Profile
if ($Me->valid()) {
    echo "<div id='homeacct'>\n  ";
    if (($nh = contactNameHtml($Me)))
	echo "Welcome, ", $nh, ".";
    else
	echo "Welcome.";
    echo "
  <ul>
    <li><h4><a href='account$ConfSiteSuffix'>Your Profile</a></h4></li>
    <li><a href='mergeaccounts$ConfSiteSuffix'>Merge accounts</a></li>
    <li><a href='index$ConfSiteSuffix?signout=1'>Sign out</a></li>
  </ul>
</div>\n";
}

echo "</div></div>\n\n";
// End sidebar


// Home message
if (($v = $Conf->settingText("homemsg")))
    $Conf->infoMsg($v);


// Sign in
if (!$Me->valid()) {
    $confname = $Opt["longName"];
    if ($Opt["shortName"] && $Opt["shortName"] != $Opt["longName"])
	$confname .= " (" . $Opt["shortName"] . ")";
    echo "<div class='homegrp'>
Welcome to the ", htmlspecialchars($confname), " submissions site.
Sign in to submit or review papers.";
    if (isset($Opt["conferenceSite"]))
	echo " For general information about ", htmlspecialchars($Opt["shortName"]), ", see the <a href=\"", htmlspecialchars($Opt["conferenceSite"]), "\">conference site</a>.";
    $passwordFocus = ($email_class == "" && $password_class != "");
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeacct'>
<form method='post' action='index$ConfSiteSuffix' accept-charset='UTF-8'><div class='f-contain'>
<input type='hidden' name='cookie' value='1' />
<div class='f-ii'>
  <div class='f-c", $email_class, "'>",
	(isset($Opt["ldapLogin"]) ? "Username" : "Email"),
	"</div>
  <div class='f-e", $email_class, "'><input",
	($passwordFocus ? "" : " id='login_d'"),
	" type='text' class='textlite' name='email' size='36' tabindex='1' ";
    if (isset($_REQUEST["email"]))
	echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" ";
    echo " /></div>
</div>
<div class='f-i'>
  <div class='f-c", $password_class, "'>Password</div>
  <div class='f-e'><input",
	($passwordFocus ? " id='login_d'" : ""),
	" type='password' class='textlite' name='password' size='36' tabindex='1' value='' /></div>
</div>\n";
    if (isset($Opt["ldapLogin"]))
	echo "<input type='hidden' name='action' value='login' />\n";
    else {
	echo "<div class='f-i'>
  <input type='radio' name='action' value='login' checked='checked' tabindex='2' />&nbsp;<b>Sign me in</b><br />
  <input type='radio' name='action' value='forgot' tabindex='2' />&nbsp;I forgot my password, email it to me<br />
  <input type='radio' name='action' value='new' tabindex='2' />&nbsp;I'm a new user and want to create an account using this email address
</div>\n";
    }
    echo "<div class='f-i'>
  <input class='b' type='submit' value='Sign in' name='signin' tabindex='1' />
</div>
</div></form>
<hr class='home' /></div>\n";
    $Conf->footerStuff .= "<script type='text/javascript'>crpfocus(\"login\", null, 2);</script>";
}


// Submissions
$papersub = $Conf->setting("papersub");
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->amReviewer() && $papersub));
if ($homelist) {
    echo "<div class='homegrp' id='homelist'>\n";

    // Lists
    echo "<table><tr><td><h4>Search: &nbsp;&nbsp;</h4></td>\n";

    $tOpt = PaperSearch::searchTypes($Me);
    $q = defval($_REQUEST, "q", "(All)");
    echo "  <td><form method='get' action='search$ConfSiteSuffix' accept-charset='UTF-8'><div class='inform'>
    <input class='textlite' type='text' size='32' name='q' value=\"",
	htmlspecialchars($q),
	"\" onfocus=\"tempText(this, '(All)', 1)\" onblur=\"tempText(this, '(All)', 0)\" title='Enter paper numbers or search terms' />
    &nbsp;in&nbsp; ",
	PaperSearch::searchTypeSelector($tOpt, key($tOpt), 0), "
    &nbsp; <input class='b' type='submit' value='Search' />
  </div></form><br />
  <span style='font-size: x-small'><a href='help$ConfSiteSuffix?t=search'>Search help</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='help$ConfSiteSuffix?t=keywords'>Search keywords</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='search$ConfSiteSuffix?opt=1'>Advanced search</a></span>
  </td></tr></table>
</div>
<hr class='home' />\n";
}


// Review token printing
function reviewTokenGroup() {
    global $reviewTokenGroupPrinted, $ConfSiteSuffix;
    if ($reviewTokenGroupPrinted)
	return;

    echo "<div class='homegrp' id='homerev'>\n";

    $tokens = array();
    foreach (defval($_SESSION, "rev_tokens", array()) as $tt)
	$tokens[] = encodeToken($tt);
    echo "  <h4>Review tokens: &nbsp;</h4> ",
	"<form action='index$ConfSiteSuffix' method='post' enctype='multipart/form-data' accept-charset='UTF-8'><div class='inform'>",
	"<input class='textlite' type='text' name='token' size='15' value=\"",
	htmlspecialchars(join(" ", $tokens)), "\" />",
	" &nbsp;<input class='b' type='submit' value='Go' />",
	"<div class='hint'>Enter review tokens here to gain access to the corresponding reviews.</div>",
	"</div></form>\n";

    echo "<hr class='home' /></div>\n";
    $reviewTokenGroupPrinted = true;
}


// Review assignment
if ($Me->amReviewer() && ($Me->privChair || $papersub)) {
    echo "<div class='homegrp' id='homerev'>\n";

    // Overview
    echo "  <h4>Reviews: &nbsp;</h4> ";
    $result = $Conf->qe("select PaperReview.contactId, count(reviewSubmitted), count(if(reviewNeedsSubmit=0,reviewSubmitted,1)), group_concat(overAllMerit), PCMember.contactId as pc from PaperReview join Paper using (paperId) left join PCMember on (PaperReview.contactId=PCMember.contactId) where Paper.timeSubmitted>0 group by PaperReview.contactId", "while fetching review status");
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
	    echo "You ", ($myrow[1] == 1 ? "have" : "have not"), " submitted your <a href='search$ConfSiteSuffix?q=&amp;t=r'>review</a>";
	else
	    echo "You have submitted ", $myrow[1], " of <a href='search$ConfSiteSuffix?q=&amp;t=r'>", plural($myrow[2], "review"), "</a>";
	if (in_array("overAllMerit", $rf->fieldOrder) && $myrow[1])
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", unparseScoreAverage($myrow[3]->avg, $rf->reviewFields["overAllMerit"]);
	echo ".<br />\n";
    }
    if (($Me->isPC || $Me->privChair) && $npc) {
	echo sprintf("  The average PC member has submitted %.1f reviews", $sumpcSubmit / $npc);
	if (in_array("overAllMerit", $rf->fieldOrder) && $npcScore)
	    echo " with an average ", htmlspecialchars($rf->shortName["overAllMerit"]), " score of ", unparseScoreAverage($sumpcScore / $npcScore, $rf->reviewFields["overAllMerit"]);
	echo ".";
	if ($Me->isPC || $Me->privChair)
	    echo "&nbsp; <small>(<a href='contacts$ConfSiteSuffix?t=pc&amp;score%5B%5D=0'>Details</a>)</small>";
	echo "<br />\n";
    }
    if ($myrow && $myrow[1] < $myrow[2]) {
	$rtyp = ($Me->isPC ? "pcrev_" : "extrev_");
	if ($Conf->timeReviewPaper($Me->isPC, true, false)) {
	    $d = $Conf->printableTimeSetting("${rtyp}soft");
	    if ($d == "N/A")
		$d = $Conf->printableTimeSetting("${rtyp}hard");
	    if ($d != "N/A")
		echo "  <span class='deadline'>Please submit your ", ($myrow[2] == 1 ? "review" : "reviews"), " by $d.</span><br />\n";
	} else if ($Conf->timeReviewPaper($Me->isPC, true, true))
	    echo "  <span class='deadline'><strong class='overdue'>Reviews are overdue.</strong>  They were requested by " . $Conf->printableTimeSetting("${rtyp}soft") . ".</span><br />\n";
	else if (!$Conf->timeReviewPaper($Me->isPC, true, true, true))
	    echo "  <span class='deadline'>The <a href='deadlines$ConfSiteSuffix'>deadline</a> for submitting " . ($Me->isPC ? "PC" : "external") . " reviews has passed.</span><br />\n";
	else
	    echo "  <span class='deadline'>The site is not open for reviewing.</span><br />\n";
    } else if ($Me->isPC && $Conf->timeReviewPaper(true, false, true)) {
	$d = $Conf->printableTimeSetting("pcrev_soft");
	if ($d != "N/A")
	    echo "  <span class='deadline'>The review deadline is $d.</span><br />\n";
    }
    if ($Me->isPC && $Conf->timeReviewPaper(true, false, true))
	echo "  <span class='hint'>As a PC member, you may review <a href='search$ConfSiteSuffix?q=&amp;t=s'>any submitted paper</a>.</span><br />\n";
    else if ($Me->privChair)
	echo "  <span class='hint'>As an administrator, you may review <a href='search$ConfSiteSuffix?q=&amp;t=s'>any submitted paper</a>.</span><br />\n";

    if (($myrow || $Me->privChair) && $npc)
	echo "</div>\n<div id='foldre' class='homegrp foldo'>";

    // Actions
    $sep = "";
    if ($myrow) {
	echo $sep, foldbutton("re", "review list"), "&nbsp;<a href=\"search$ConfSiteSuffix?q=&amp;t=r\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
	$sep = $xsep;
    }
    if ($Me->isPC && $Conf->setting("paperlead") > 0
	&& $Me->amDiscussionLead(0)) {
	echo $sep, "<a href=\"search$ConfSiteSuffix?q=&amp;t=lead\" class='nowrap'>Your discussion leads</a>";
	$sep = $xsep;
    }
    if ($Me->isPC && $Conf->timePCReviewPreferences()) {
	echo $sep, "<a href='reviewprefs$ConfSiteSuffix'>Preferences</a>";
	$sep = $xsep;
    }
    if ($Conf->settingsAfter("rev_open") || $Me->privChair) {
	echo $sep, "<a href='offline$ConfSiteSuffix'>Offline reviewing</a>";
	$sep = $xsep;
    }
    if ($Me->isRequester) {
	echo $sep, "<a href='mail$ConfSiteSuffix?monreq=1'>Monitor external reviews</a>";
	$sep = $xsep;
    }

    if ($myrow && $Conf->sversion >= 12
	&& $Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
	$badratings = PaperSearch::unusableRatings($Me->privChair, $Me->contactId);
	$qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
	/*$result = $Conf->qe("select rating, count(distinct PaperReview.reviewId) from PaperReview join ReviewRating on (PaperReview.contactId=$Me->contactId and PaperReview.reviewId=ReviewRating.reviewId$qx) group by rating order by rating desc", "while checking ratings");
	if (edb_nrows($result)) {
	    $a = array();
	    while (($row = edb_row($result)))
		if (isset($ratingTypes[$row[0]]))
		    $a[] = "<a href=\"search$ConfSiteSuffix?q=rate:%22" . urlencode($ratingTypes[$row[0]]) . "%22\" title='List rated reviews'>$row[1] of your reviews</a> as " . htmlspecialchars($ratingTypes[$row[0]]);
	    if (count($a) > 0) {
		echo "<div class='hint g'>\nOther reviewers ",
		    "<a href='help$ConfSiteSuffix?t=revrate' title='What is this?'>rated</a> ",
		    textArrayJoin($a);
		if (count($a) > 1)
		    echo " (these sets might overlap)";
		echo ".</div>\n";
	    }
	}*/
	$result = $Conf->qe("select rating, count(PaperReview.reviewId) from PaperReview join ReviewRating on (PaperReview.contactId=$Me->contactId and PaperReview.reviewId=ReviewRating.reviewId$qx) group by rating order by rating desc", "while checking ratings");
	if (edb_nrows($result)) {
	    $a = array();
	    while (($row = edb_row($result)))
		if (isset($ratingTypes[$row[0]]))
		    $a[] = "<a href=\"search$ConfSiteSuffix?q=rate:%22" . urlencode($ratingTypes[$row[0]]) . "%22\" title='List rated reviews'>$row[1] &ldquo;" . htmlspecialchars($ratingTypes[$row[0]]) . "&rdquo; " . pluralx($row[1], "rating") . "</a>";
	    if (count($a) > 0) {
		echo "<div class='hint g'>\nYour reviews have received ",
		    textArrayJoin($a);
		if (count($a) > 1)
		    echo " (these sets might overlap)";
		echo ".<a class='help' href='help$ConfSiteSuffix?t=revrate' title='About ratings'>?</a></div>\n";
	    }
	}
    }

    if ($Me->isReviewer) {
	$plist = new PaperList(false, true, new PaperSearch($Me, array("t" => "r")));
	$ptext = $plist->text("reviewerHome", $Me);
	if ($plist->count > 0)
	    echo "<div class='fx'><div class='g'></div>", $ptext, "</div>";
    }

    if ($Conf->setting("rev_tokens")) {
	echo "</div>\n";
	reviewTokenGroup();
    } else
	echo "<hr class='home' /></div>\n";
}


// Authored papers
if ($Me->isAuthor || $Conf->timeStartPaper() > 0 || $Me->privChair
    || !$Me->amReviewer()) {
    echo "<div class='homegrp' id='homeau'>";

    // Overview
    if ($Me->isAuthor)
	echo "<h4>Your Submissions: &nbsp;</h4> ";
    else
	echo "<h4>Submissions: &nbsp;</h4> ";

    $startable = $Conf->timeStartPaper();
    if ($startable && !$Me->valid())
	echo "<span class='deadline'>", $Conf->printableDeadlineSetting('sub_reg'), "</span><br />\n<small>You must sign in to register papers.</small>";
    else if ($startable || $Me->privChair) {
	echo "<strong><a href='paper$ConfSiteSuffix?p=new'>Start new paper</a></strong> <span class='deadline'>(", $Conf->printableDeadlineSetting('sub_reg'), ")</span>";
	if ($Me->privChair)
	    echo "<br />\n<span class='hint'>As an administrator, you can start papers regardless of deadlines and on other people's behalf.</span>";
    }

    $plist = null;
    if ($Me->isAuthor) {
	$plist = new PaperList(false, true, new PaperSearch($Me, array("t" => "a")));
	$plist->showHeader = 0;
	$ptext = $plist->text("authorHome", $Me);
	if ($plist->count > 0)
	    echo "<div class='g'></div>\n", $ptext;
    }

    $deadlines = array();
    if ($plist && $plist->needFinalize > 0) {
	if (!$Conf->timeFinalizePaper())
	    $deadlines[] = "The <a href='deadlines$ConfSiteSuffix'>deadline</a> for submitting papers has passed.";
	else if (!$Conf->timeUpdatePaper()) {
	    $deadlines[] = "The <a href='deadlines$ConfSiteSuffix'>deadline</a> for updating papers has passed, but you can still submit.";
	    $time = $Conf->printableTimeSetting('sub_sub');
	    if ($time != 'N/A')
		$deadlines[] = "You have until $time to submit papers.";
	} else if (($time = $Conf->printableTimeSetting('sub_update')) != 'N/A')
	    $deadlines[] = "You have until $time to submit papers.";
    }
    if (!$startable && !count($deadlines)) {
	if ($Conf->settingsAfter('sub_open'))
	    $deadlines[] = "The <a href='deadlines$ConfSiteSuffix'>deadline</a> for registering new papers has passed.";
	else
	    $deadlines[] = "The site is not open for submissions at the moment.";
    }
    if (count($deadlines) > 0) {
	if ($plist && $plist->count > 0)
	    echo "<div class='g'></div>";
	else if ($startable || $Me->privChair)
	    echo "<br />";
	echo "<span class='deadline'>",
	    join("</span><br />\n<span class='deadline'>", $deadlines),
	    "</span>";
    }

    echo "<hr class='home' /></div>\n";
}


// Review tokens
if ($Me->valid() && $Conf->setting("rev_tokens"))
    reviewTokenGroup();


echo "<div class='clear'></div>\n";
$Conf->footer();
