<?php
// account.php -- HotCRP account management page
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
require_once("Code/countries.inc");
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$newProfile = false;
$Error = array();


if (!$Me->privChair)
    $Acct = $Me;		// always this contact
else if (isset($_REQUEST["new"])) {
    $Acct = new Contact();
    $Acct->invalidate();
    $newProfile = true;
} else if (isset($_REQUEST["contact"])) {
    $Acct = new Contact();
    if (($id = rcvtint($_REQUEST["contact"])) > 0)
	$Acct->lookupById($id);
    else
	$Acct->lookupByEmail($_REQUEST["contact"]);
    if (!$Acct->valid()) {
	$Conf->errorMsg("Invalid contact.");
	$Acct = $Me;
    }
} else
    $Acct = $Me;

$Acct->lookupAddress();


if (isset($_REQUEST["register"])) {
    $needFields = array('uemail', 'firstName', 'lastName', 'affiliation');
    if (!$newProfile)
	$needFields[] = "upassword";
    foreach ($needFields as $field)
	if (!isset($_REQUEST[$field])) {
	    $Conf->errorMsg("Required form fields missing.");
	    $Error[$field] = true;
	    $OK = 0;
	}
}

if (isset($_REQUEST['register']) && $OK) {
    $_REQUEST["uemail"] = trim($_REQUEST["uemail"]);
    if (!$newProfile && defval($_REQUEST, "upassword", "") == "") {
	$UpdateError = "Blank passwords are not allowed.";
	$Error['password'] = true;
    } else if (!$newProfile && $_REQUEST["upassword"] != defval($_REQUEST, "upassword2", "")) {
	$UpdateError = "The two passwords you entered did not match.";
	$Error['password'] = true;
    } else if (!$newProfile && trim($_REQUEST["upassword"]) != $_REQUEST["upassword"]) {
	$UpdateError = "Passwords cannot begin or end with spaces.";
	$Error['password'] = true;
    } else if ($_REQUEST["uemail"] != $Acct->email
	       && $Conf->getContactId($_REQUEST["uemail"])) {
	$UpdateError = "An account is already registered with email address &ldquo;" . htmlspecialchars($_REQUEST["uemail"]) . "&rdquo;.";
	if (!$newProfile)
	    $UpdateError .= "You may want to <a href='mergeaccounts$ConfSiteSuffix'>merge these accounts</a>.";
	$Error["uemail"] = true;
    } else if ($_REQUEST["uemail"] != $Acct->email
	       && !validateEmail($_REQUEST["uemail"])) {
	$UpdateError = "&ldquo;" . htmlspecialchars($_REQUEST["uemail"]) . "&rdquo; is not a valid email address.";
	$Error["uemail"] = true;
    } else {
	if ($newProfile) {
	    $result = $Acct->initialize($_REQUEST["uemail"]);
	    if ($OK) {
		$Acct->sendAccountInfo($Conf, true, false);
		$Conf->log("Account created by $Me->email", $Acct);
	    }
	}

	$updatepc = false;

	if ($Me->privChair) {
	    // initialize roles too
	    if (isset($_REQUEST["pctype"])) {
		if ($_REQUEST["pctype"] == "chair")
		    $_REQUEST["pc"] = $_REQUEST["chair"] = 1;
		else if ($_REQUEST["pctype"] == "pc") {
		    unset($_REQUEST["chair"]);
		    $_REQUEST["pc"] = 1;
		} else {
		    unset($_REQUEST["chair"]);
		    unset($_REQUEST["pc"]);
		}
	    } else if (isset($_REQUEST["chair"]))
		$_REQUEST["pc"] = 1;
	    $checkass = !isset($_REQUEST["ass"]) && $Me->contactId == $Acct->contactId && ($Acct->roles & Contact::ROLE_ADMIN) != 0;

	    $while = "while initializing roles";
	    foreach (array("pc" => "PCMember", "ass" => "ChairAssistant", "chair" => "Chair") as $k => $table) {
		$role = ($k == "pc" ? Contact::ROLE_PC : ($k == "ass" ? Contact::ROLE_ADMIN : Contact::ROLE_CHAIR));
		if (($Acct->roles & $role) != 0 && !isset($_REQUEST[$k])) {
		    $Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
		    $Conf->log("Removed as $table by $Me->email", $Acct);
		    $Acct->roles &= ~$role;
		    $updatepc = true;
		} else if (($Acct->roles & $role) == 0 && isset($_REQUEST[$k])) {
		    $Conf->qe("insert into $table (contactId) values ($Acct->contactId)", $while);
		    $Conf->log("Added as $table by $Me->email", $Acct);
		    $Acct->roles |= $role;
		    $updatepc = true;
		}
	    }

	    // ensure there's at least one system administrator
	    if ($checkass) {
		$result = $Conf->qe("select contactId from ChairAssistant", $while);
		if (edb_nrows($result) == 0) {
		    $Conf->qe("insert into ChairAssistant (contactId) values ($Acct->contactId)", $while);
		    $Conf->warnMsg("Refusing to drop the only system administrator.");
		    $_REQUEST["ass"] = 1;
		    $Acct->roles |= Contact::ROLE_ADMIN;
		}
	    }
	}

	// ensure changes in PC member data are reflected immediately
	if (($Acct->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR))
	    && !$updatepc
	    && ($Acct->firstName != $_REQUEST["firstName"]
		|| $Acct->lastName != $_REQUEST["lastName"]
		|| $Acct->email != $_REQUEST["uemail"]
		|| $Acct->affiliation != $_REQUEST["affiliation"]))
	    $updatepc = true;

	$Acct->firstName = $_REQUEST["firstName"];
	$Acct->lastName = $_REQUEST["lastName"];
	$Acct->email = $_REQUEST["uemail"];
	$Acct->affiliation = $_REQUEST["affiliation"];
	if (!$newProfile && !isset($Opt["ldapLogin"]))
	    $Acct->password = $_REQUEST["upassword"];
	foreach (array("voicePhoneNumber", "faxPhoneNumber", "collaborators",
		       "addressLine1", "addressLine2", "city", "state",
		       "zipCode", "country") as $v)
	    if (isset($_REQUEST[$v]))
		$Acct->$v = $_REQUEST[$v];
	$Acct->defaultWatch = 0;
	if (isset($_REQUEST["watchcomment"]))
	    $Acct->defaultWatch |= WATCH_COMMENT;

	if ($OK)
	    $Acct->updateDB();

	if ($updatepc) {
	    $t = time();
	    $Conf->qe("insert into Settings (name, value) values ('pc', $t) on duplicate key update value=$t");
	    unset($_SESSION["pcmembers"]);
	    unset($_SESSION["pcmembersa"]);
	}

	// if PC member, update collaborators and areas of expertise
	if (($Acct->isPC || $newProfile) && $OK) {
	    // remove all current interests
	    $Conf->qe("delete from TopicInterest where contactId=$Acct->contactId", "while updating topic interests");

	    foreach ($_REQUEST as $key => $value)
		if ($OK && strlen($key) > 2 && $key[0] == 't' && $key[1] == 'i'
		    && ($id = (int) substr($key, 2)) > 0
		    && is_numeric($value)
		    && ($value = (int) $value) >= 0 && $value < 3)
		    $Conf->qe("insert into TopicInterest (contactId, topicId, interest) values ($Acct->contactId, $id, $value)", "while updating topic interests");
	}

	if ($OK) {
	    // Refresh the results
	    $Acct->lookupByEmail($_REQUEST["uemail"]);
	    $Acct->valid();
	    if ($newProfile) {
		$Conf->confirmMsg("Successfully created <a href=\"account$ConfSiteSuffix?contact=" . urlencode($Acct->email) . "\">an account for " . htmlspecialchars($Acct->email) . "</a>.  A password has been emailed to that address.  You may now <a href='account$ConfSiteSuffix?contact=new'>create another account</a>, or edit the " . htmlspecialchars($Acct->email) . " account below.");
		$newProfile = false;
	    } else {
		$Conf->log("Account updated" . ($Me->contactId == $Acct->contactId ? "" : " by $Me->email"), $Acct);
		$Conf->confirmMsg("Account profile successfully updated.");
	    }
	    if (isset($_REQUEST["redirect"]))
		$Me->go("index$ConfSiteSuffix");
	    foreach (array("firstName", "lastName", "affiliation") as $k)
		$_REQUEST[$k] = $Acct->$k;
	}
    }
}

function databaseTracks($who) {
    global $Conf;
    $tracks = (object) array("soleAuthor" => array(),
			     "author" => array(),
			     "review" => array(),
			     "comment" => array());

    // find authored papers
    $result = $Conf->qe("select Paper.paperId, count(pc.contactId)
	from Paper
	join PaperConflict c on (c.paperId=Paper.paperId and c.contactId=$who and c.conflictType>=" . CONFLICT_AUTHOR . ")
	join PaperConflict pc on (pc.paperId=Paper.paperId and pc.conflictType>=" . CONFLICT_AUTHOR . ")
	group by Paper.paperId order by Paper.paperId");
    while (($row = edb_row($result))) {
	if ($row[1] == 1)
	    $tracks->soleAuthor[] = $row[0];
	$tracks->author[] = $row[0];
    }

    // find reviews
    $result = $Conf->qe("select paperId from PaperReview
	where PaperReview.contactId=$who
	group by paperId order by paperId");
    while (($row = edb_row($result)))
	$tracks->review[] = $row[0];

    // find comments
    $result = $Conf->qe("select paperId from PaperComment
	where PaperComment.contactId=$who
	group by paperId order by paperId");
    while (($row = edb_row($result)))
	$tracks->comment[] = $row[0];

    return $tracks;
}

function textArrayPapers($pids) {
    global $ConfSiteSuffix;
    $ls = "&amp;list=" . join("+", $pids);
    return textArrayJoin(preg_replace('/(\d+)/', "<a href='paper$ConfSiteSuffix?p=\$1$ls'>\$1</a>", $pids));
}

if (isset($_REQUEST["delete"]) && $OK) {
    if (!$Me->privChair)
	$Conf->errorMsg("Only administrators can delete users.");
    else if ($Acct->contactId == $Me->contactId)
	$Conf->errorMsg("You aren't allowed to delete yourself.");
    else {
	$tracks = databaseTracks($Acct->contactId);
	if (count($tracks->soleAuthor))
	    $Conf->errorMsg("This user can't be deleted since they are sole contact author for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".  You will be able to delete the user after deleting those papers or adding additional contact authors.");
	else {
	    $while = "while deleting user";
	    foreach (array("ContactInfo", "Chair", "ChairAssistant",
			   "ContactAddress", "PCMember", "PaperComment",
			   "PaperConflict", "PaperReview",
			   "PaperReviewPreference", "PaperReviewRefused",
			   "PaperWatch", "ReviewRating", "TopicInterest")
		     as $table)
		$Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
	    // tags are special because of voting tags, so go through
	    // Code/tags.inc
	    require_once("Code/tags.inc");
	    $prefix = $Acct->contactId . "~";
	    $result = $Conf->qe("select paperId, tag from PaperTag where tag like '$prefix%'", $while);
	    $pids = $tags = array();
	    while (($row = edb_row($result))) {
		$pids[$row[0]] = 1;
		$tags[substr($row[1], strlen($prefix) - 1)] = 1;
	    }
	    if (count($pids) > 0)
		setTags(array_keys($pids), join(" ", array_keys($tags)), "d", $Acct->contactId);
	    // recalculate Paper.numComments if necessary
	    // (XXX lock tables?)
	    foreach ($tracks->comment as $pid)
		$Conf->qe("update Paper set numComments=(select count(commentId) from PaperComment where paperId=$pid), numAuthorComments=(select count(commentId) from PaperComment where paperId=$pid and forAuthors>0) where paperId=$pid", $while);
	    // done
	    $Conf->confirmMsg("Permanently deleted user " . htmlspecialchars($Acct->email) . ".");
	    $Conf->log("Permanently deleted user " . htmlspecialchars($Acct->email) . " ($Acct->contactId)", $Me);
	    $Me->go("contacts$ConfSiteSuffix?t=all");
	}
    }
}

function crpformvalue($val, $field = null) {
    global $Acct;
    if (isset($_REQUEST[$val]))
	return htmlspecialchars($_REQUEST[$val]);
    else
	return htmlspecialchars($field ? $Acct->$field : $Acct->$val);
}

function fcclass($what) {
    global $Error;
    return (isset($Error[$what]) ? "f-c error" : "f-c");
}

function feclass($what) {
    global $Error;
    return (isset($Error[$what]) ? "f-e error" : "f-e");
}

function capclass($what) {
    global $Error;
    return (isset($Error[$what]) ? "caption error" : "caption");
}

if (!$newProfile) {
    $_REQUEST["pc"] = ($Acct->roles & Contact::ROLE_PC) != 0;
    $_REQUEST["ass"] = ($Acct->roles & Contact::ROLE_ADMIN) != 0;
    $_REQUEST["chair"] = ($Acct->roles & Contact::ROLE_CHAIR) != 0;
}


if ($newProfile)
    $Conf->header("Create Account", "account", actionBar());
else
    $Conf->header($Me->contactId == $Acct->contactId ? "Your Profile" : "Account Profile", "account", actionBar());


if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1
	 && ($Acct->roles & Contact::ROLE_PC)) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 3;
    $msg = ($Acct->lastName ? "" : "Please take a moment to update your contact information.  ");
    $msg .= "We need a list of your recent collaborators to detect paper conflicts.  If you have no collaborators, enter \"None\".";
    $result = $Conf->q("select * from TopicArea");
    if (edb_nrows($result) > 0)
	$msg .= "  Additionally, we use your topic interests to assign you papers you might like.";
    $Conf->infoMsg($msg);
} else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 2;
    $Conf->infoMsg("Please take a moment to update your contact information.");
 }


echo "<form id='accountform' method='post' action='account$ConfSiteSuffix' accept-charset='UTF-8'><div class='aahc'>\n";
if ($newProfile)
    echo "<input type='hidden' name='new' value='1' />\n";
else if ($Me->contactId != $Acct->contactId)
    echo "<input type='hidden' name='contact' value='$Acct->contactId' />\n";
if (isset($_REQUEST["redirect"]))
    echo "<input type='hidden' name='redirect' value=\"", htmlspecialchars($_REQUEST["redirect"]), "\" />\n";


echo "<table id='foldpass' class='form foldc'>
<tr>
  <td class='caption initial'>Contact information</td>
  <td class='entry'><div class='f-contain'>

<div class='f-i'>
  <div class='", fcclass('uemail'), "'>Email</div>
  <div class='", feclass('uemail'), "'><input class='textlite' type='text' name='uemail' size='52' value=\"", crpformvalue('uemail', 'email'), "\" onchange='hiliter(this)' /></div>
</div>\n\n";

echo "<div class='f-i'><div class='f-ix'>
  <div class='", fcclass('firstName'), "'>First&nbsp;name</div>
  <div class='", feclass('firstName'), "'><input class='textlite' type='text' name='firstName' size='24' value=\"", crpformvalue('firstName'), "\" onchange='hiliter(this)' /></div>
</div><div class='f-ix'>
  <div class='", fcclass('lastName'), "'>Last&nbsp;name</div>
  <div class='", feclass('lastName'), "'><input class='textlite' type='text' name='lastName' size='24' value=\"", crpformvalue('lastName'), "\" onchange='hiliter(this)' /></div>
</div><div class='clear'></div></div>\n\n";

if (!$newProfile) {
    echo "<div class='f-i'><div class='f-ix'>
  <div class='", fcclass('password'), "'>Password";
    echo "</div>
  <div class='", feclass('password'), "'><input class='textlite fn' type='password' name='upassword' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='hiliter(this);shiftPassword(1)' />";
    if ($Me->privChair)
	echo "<input class='textlite fx' type='text' name='upasswordt' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='hiliter(this);shiftPassword(0)' />";
    echo "</div>
</div><div class='fn f-ix'>
  <div class='", fcclass('password'), "'>Repeat password</div>
  <div class='", feclass('password'), "'><input class='textlite' type='password' name='upassword2' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='hiliter(this)' /></div>
</div>
  <div class='f-h'>The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don't use a login password or any other high-security password.";
    if ($Me->privChair)
	echo "  <span class='sep'></span><span class='f-cx'><a class='fn' href='javascript:void fold(\"pass\")'>Show password</a><a class='fx' href='javascript:void fold(\"pass\")'>Hide password</a></span>";
    echo "</div>\n  <div class='clear'></div></div>\n\n";
}


echo "<div class='f-i'>
  <div class='", fcclass('affiliation'), "'>Affiliation</div>
  <div class='", feclass('affiliation'), "'><input class='textlite' type='text' name='affiliation' size='52' value=\"", crpformvalue('affiliation'), "\" onchange='hiliter(this)' /></div>
</div>\n\n";


if ($Conf->setting("acct_addr")) {
    echo "<div class='g'></div>\n";
    if ($Conf->setting("allowPaperOption") >= 5) {
	echo "<div class='f-i'>
  <div class='f-c'>Address line 1</div>
  <div class='f-e'><input class='textlite' type='text' name='addressLine1' size='52' value=\"", crpformvalue('addressLine1'), "\" onchange='hiliter(this)' /></div>
</div>\n\n";
	echo "<div class='f-i'>
  <div class='f-c'>Address line 2</div>
  <div class='f-e'><input class='textlite' type='text' name='addressLine2' size='52' value=\"", crpformvalue('addressLine2'), "\" onchange='hiliter(this)' /></div>
</div>\n\n";
	echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>City</div>
  <div class='f-e'><input class='textlite' type='text' name='city' size='32' value=\"", crpformvalue('city'), "\" onchange='hiliter(this)' /></div>
</div>";
	echo "<div class='f-ix'>
  <div class='f-c'>State/Province/Region</div>
  <div class='f-e'><input class='textlite' type='text' name='state' size='24' value=\"", crpformvalue('state'), "\" onchange='hiliter(this)' /></div>
</div>";
	echo "<div class='f-ix'>
  <div class='f-c'>ZIP/Postal code</div>
  <div class='f-e'><input class='textlite' type='text' name='zipCode' size='12' value=\"", crpformvalue('zipCode'), "\" onchange='hiliter(this)' /></div>
</div><div class='clear'></div></div>\n\n";
	echo "<div class='f-i'>
  <div class='f-c'>Country</div>
  <div class='f-e'>";
	countrySelector("country", (isset($_REQUEST["country"]) ? $_REQUEST["country"] : $Acct->country));
	echo "</div>\n</div>\n";
    }
    echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Phone <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='voicePhoneNumber' size='24' value=\"", crpformvalue('voicePhoneNumber'), "\" onchange='hiliter(this)' /></div>
</div><div class='f-ix'>
  <div class='f-c'>Fax <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='faxPhoneNumber' size='24' value=\"", crpformvalue('faxPhoneNumber'), "\" onchange='hiliter(this)' /></div>
</div><div class='clear'></div></div>\n";
}

echo "</div></td>\n</tr>\n\n";

if ($Conf->setting("allowPaperOption") >= 6) {
    echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div></td></tr>\n\n",
	"<tr><td class='caption'>Email notification</td><td class='entry'>",
	"<input type='checkbox' name='watchcomment' value='", WATCH_COMMENT, "'";
    if ($Acct->defaultWatch & WATCH_COMMENT)
	echo " checked='checked'";
    echo " onchange='hiliter(this)' />&nbsp;Send mail when new comments are available for authored or reviewed papers</td></tr>\n\n";
}


if ($Acct->isPC || $newProfile)
    echo "<tr><td class='caption'></td><td class='entry'><div class='g'></div><strong>Program committee information</strong></td></tr>\n";


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo "<tr>
  <td class='caption'>Roles</td>
  <td class='entry'><table><tr><td class='nowrap'>\n";

    $pct = defval($_REQUEST, "pctype");
    if ($pct != "chair" && $pct != "pc" && $pct != "no") {
	if (defval($_REQUEST, "chair"))
	    $pct = "chair";
	else
	    $pct = defval($_REQUEST, "pc") ? "pc" : "no";
    }
    foreach (array("chair" => "PC chair", "pc" => "PC member",
		   "no" => "Not on the PC") as $k => $v) {
	echo "<input type='radio' name='pctype' value='$k'",
	    ($k == $pct ? " checked='checked'" : ""),
	    " onchange='hiliter(this)' />&nbsp;$v<br />\n";
    }

    echo "</td><td><span class='sep'></span></td><td class='nowrap'>";
    echo "<input type='checkbox' name='ass' value='1' ",
	(defval($_REQUEST, "ass") ? "checked='checked' " : ""),
	"onchange='hiliter(this)' />&nbsp;</td><td>System administrator<br />",
	"<div class='hint'>System administrators have full control over all site operations.  Administrators need not be members of the PC.  There's always at least one system administrator.</div></td></tr></table>\n";
    echo "  </td>\n</tr>\n\n";
}


if ($Acct->isPC || $newProfile) {
    echo "<tr>
  <td class='caption'>Collaborators and other affiliations</td>
  <td class='entry'><div class='hint'>Please list potential conflicts of interest.  ", $Conf->conflictDefinitionText(), "  List one conflict per line.
    We use this information when assigning reviews.
    For example: &ldquo;<tt>Ping Yen Zhang (INRIA)</tt>&rdquo;
    or, for a whole institution, &ldquo;<tt>INRIA</tt>&rdquo;.</div>
    <textarea class='textlite' name='collaborators' rows='5' cols='50' onchange='hiliter(this)'>", htmlspecialchars($Acct->collaborators), "</textarea></td>
</tr>\n\n";

    $result = $Conf->q("select TopicArea.topicId, TopicArea.topicName, TopicInterest.interest from TopicArea left join TopicInterest on TopicInterest.contactId=$Acct->contactId and TopicInterest.topicId=TopicArea.topicId order by TopicArea.topicName");
    if (edb_nrows($result) > 0) {
	echo "<tr id='topicinterest'>
  <td class='caption'>Topic interests</td>
  <td class='entry' id='topicinterest'><div class='hint'>
    Please indicate your interest in reviewing papers on these conference
    topics. We use this information to help match papers to reviewers.</div>
    <table class='topicinterest'>
       <tr><td></td><th>Low</th><th>Med.</th><th>High</th></tr>\n";
	for ($i = 0; $i < edb_nrows($result); $i++) {
	    $row = edb_row($result);
	    echo "      <tr><td class='ti_topic'>", htmlspecialchars($row[1]), "</td>";
	    $interest = isset($row[2]) ? $row[2] : 1;
	    for ($j = 0; $j < 3; $j++) {
		echo "<td class='ti_interest'>";
		echo "<input type='radio' name='ti$row[0]' value='$j' ";
		if ($interest == $j)
		    echo "checked='checked' ";
		echo "onchange='hiliter(this)' /></td>";
	    }
	    echo "</td></tr>\n";
	}
	echo "    </table></td>
</tr>";
    }
}


echo "<tr class='last'><td class='caption'></td>
  <td class='entry'><div class='aa'><table class='pt_buttons'>\n";
$buttons = array("<input class='bb' type='submit' value='"
		 . ($newProfile ? "Create account" : "Save changes")
		 . "' name='register' />");
if ($Me->privChair && !$newProfile && $Me->contactId != $Acct->contactId) {
    $tracks = databaseTracks($Acct->contactId);
    $buttons[] = array("<button type='button' class='b' onclick=\"popup(this, 'd', 0)\">Delete user</button>", "(admin only)");
    if (count($tracks->soleAuthor)) {
	$Conf->footerStuff .= "<div id='popup_d' class='popupc'><p><strong>This user cannot be deleted</strong> because they are the sole contact author for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".  Delete these papers from the database or add alternate contact authors and you will be able to delete this user.\n<div class='popup_actions'><button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Close</button></div></div>";
    } else {
	$Conf->footerStuff .= "<div id='popup_d' class='popupc'><p>Be careful: This will permanently delete all information about this user from the database and <strong>cannot be undone</strong>.</p>";
	if (count($tracks->author) + count($tracks->review) + count($tracks->comment)) {
	    $x = $y = array();
	    if (count($tracks->author)) {
		$x[] = "contact author for " . pluralx($tracks->author, "paper") . " " . textArrayPapers($tracks->author);
		$y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
	    }
	    if (count($tracks->review)) {
		$x[] = "reviewer for " . pluralx($tracks->review, "paper") . " " . textArrayPapers($tracks->review);
		$y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
	    }
	    if (count($tracks->comment)) {
		$x[] = "commenter for " . pluralx($tracks->comment, "paper") . " " . textArrayPapers($tracks->comment);
		$y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
	    }
	    $Conf->footerStuff .= "<p>This user is " . textArrayJoin($x)
		. ".  Deleting the user will also " . textArrayJoin($y) . ".</p>";
	}
	$Conf->footerStuff .= "<form method='post' action=\"account$ConfSiteSuffix?contact=" . $Acct->contactId . "&amp;post=1\" enctype='multipart/form-data' accept-charset='UTF-8'><div class='popup_actions'><input class='b' type='submit' name='delete' value='Delete user' /> &nbsp;<button type='button' class='b' onclick=\"popup(null, 'd', 1)\">Cancel</button></div></form></div>";
    }
}
echo "    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[0] : $b);
    echo "      <td class='ptb_button'>", $x, "</td>\n";
}
echo "    </tr>\n    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[1] : "");
    echo "      <td class='ptb_explain'>", $x, "</td>\n";
}
echo "    </tr>\n    </table></div></td>\n</tr>
</table></div></form>\n";


$Conf->footer();
