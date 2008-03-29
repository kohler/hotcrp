<?php 
// account.php -- HotCRP account management page
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
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
    if (($id = cvtint($_REQUEST["contact"])) > 0)
	$Acct->lookupById($id, $Conf);
    else
	$Acct->lookupByEmail($_REQUEST["contact"], $Conf);
    if (!$Acct->valid($Conf)) {
	$Conf->errorMsg("Invalid contact.");
	$Acct = $Me;
    }
} else
    $Acct = $Me;

$Acct->lookupAddress($Conf);


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
	$UpdateError = "An account is already registered with email address \"" . htmlspecialchars($_REQUEST["uemail"]) . "\".";
	if (!$newProfile)
	    $UpdateError .= "You may want to <a href='mergeaccounts$ConfSiteSuffix'>merge these accounts</a>.";
	$Error['email'] = true;
    } else {
	if ($newProfile) {
	    $result = $Acct->initialize($_REQUEST["uemail"], $Conf);
	    if ($OK) {
		$Acct->sendAccountInfo($Conf, true, false);
		$Conf->log("Account created by $Me->email", $Acct);
	    }
	}

	if ($Me->privChair && $Me->contactId != $Acct->contactId) {
	    // initialize roles too
	    if (isset($_REQUEST["chair"]))
		$_REQUEST["pc"] = 1;
	    $while = "while initializing roles";
	    $changed = false;
	    foreach (array("pc" => "PCMember", "ass" => "ChairAssistant", "chair" => "Chair") as $k => $table) {
		$role = ($k == "pc" ? Contact::ROLE_PC : ($k == "ass" ? Contact::ROLE_ASSISTANT : Contact::ROLE_CHAIR));
		if (($Acct->roles & $role) != 0 && !isset($_REQUEST[$k])) {
		    $Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
		    $Conf->log("Removed as $table by $Me->email", $Acct);
		    $Acct->roles &= ~$role;
		    $changed = true;
		} else if (($Acct->roles & $role) == 0 && isset($_REQUEST[$k])) {
		    $Conf->qe("insert into $table (contactId) values ($Acct->contactId)", $while);
		    $Conf->log("Added as $table by $Me->email", $Acct);
		    $Acct->roles |= $role;
		    $changed = true;
		}
	    }
	    if ($changed) {
		$t = time();
		$Conf->qe("insert into Settings (name, value) values ('pc', $t) on duplicate key update value=$t");
		unset($_SESSION["pcmembers"]);
		unset($_SESSION["pcmembersa"]);
	    }
	}
	
	$Acct->firstName = $_REQUEST["firstName"];
	$Acct->lastName = $_REQUEST["lastName"];
	$Acct->email = $_REQUEST["uemail"];
	$Acct->affiliation = $_REQUEST["affiliation"];
	if (!$newProfile)
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
	    $Acct->updateDB($Conf);

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
	    $Acct->lookupByEmail($_REQUEST["uemail"], $Conf);
	    $Acct->valid($Conf);
	    if ($newProfile)
		$Conf->confirmMsg("Successfully created an account for " . htmlspecialchars($Acct->email) . ".  A password has been emailed to that address.  You may now create another account if you'd like.");
	    else {
		$Conf->log("Account updated" . ($Me->contactId == $Acct->contactId ? "" : " by $Me->email"), $Acct);
		$Conf->confirmMsg("Account profile successfully updated.");
	    }
	    if (isset($_REQUEST["redirect"]))
		$Me->go("index$ConfSiteSuffix");
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
    $_REQUEST["ass"] = ($Acct->roles & Contact::ROLE_ASSISTANT) != 0;
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


echo "<form id='accountform' method='post' action='account$ConfSiteSuffix' accept-encoding='UTF-8'><div>\n";
if ($newProfile)
    echo "<input type='hidden' name='new' value='1' />\n";
else if ($Me->contactId != $Acct->contactId)
    echo "<input type='hidden' name='contact' value='$Acct->contactId' />\n";
if (isset($_REQUEST["redirect"]))
    echo "<input type='hidden' name='redirect' value=\"", htmlspecialchars($_REQUEST["redirect"]), "\" />\n";


echo "<table id='foldpass' class='form foldc'>
<tr>
  <td class='caption'>Contact information</td>
  <td class='entry'><div class='f-contain'>

<div class='f-i'>
  <div class='", fcclass('email'), "'>Email</div>
  <div class='", feclass('email'), "'><input class='textlite' type='text' name='uemail' size='52' value=\"", crpformvalue('uemail', 'email'), "\" /></div>
</div>\n\n";

echo "<div class='f-i'><div class='f-ix'>
  <div class='", fcclass('firstName'), "'>First&nbsp;name</div>
  <div class='", feclass('firstName'), "'><input class='textlite' type='text' name='firstName' size='24' value=\"", crpformvalue('firstName'), "\" /></div>
</div><div class='f-ix'>
  <div class='", fcclass('lastName'), "'>Last&nbsp;name</div>
  <div class='", feclass('lastName'), "'><input class='textlite' type='text' name='lastName' size='24' value=\"", crpformvalue('lastName'), "\" /></div>
</div><div class='clear'></div></div>\n\n";

if (!$newProfile) {
    echo "<div class='f-i'><div class='f-ix'>
  <div class='", fcclass('password'), "'>Password";
    echo "</div>
  <div class='", feclass('password'), "'><input class='textlite ellipsis' type='password' name='upassword' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='shiftPassword(1)' />";
    if ($Me->privChair)
	echo "<input class='textlite extension' type='text' name='upasswordt' size='24' value=\"", crpformvalue('upassword', 'password'), "\" onchange='shiftPassword(0)' />";
    echo "</div>
</div><div class='ellipsis f-ix'>
  <div class='", fcclass('password'), "'>Repeat password</div>
  <div class='", feclass('password'), "'><input class='textlite' type='password' name='upassword2' size='24' value=\"", crpformvalue('upassword', 'password'), "\" /></div>
</div>
  <div class='f-h'>The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don't use a login password or any other high-security password.";
    if ($Me->privChair)
	echo "  <span class='sep'></span><span class='f-cx'><a class='unfolder' href='javascript:void fold(\"pass\")'>Show password</a><a class='folder' href='javascript:void fold(\"pass\")'>Hide password</a></span>";
    echo "</div>\n  <div class='clear'></div></div>\n\n";
}


echo "<div class='f-i'>
  <div class='", fcclass('affiliation'), "'>Affiliation</div>
  <div class='", feclass('affiliation'), "'><input class='textlite' type='text' name='affiliation' size='52' value=\"", crpformvalue('affiliation'), "\" /></div>
</div>\n\n";


if ($Conf->setting("acct_addr")) {
    echo "<div class='smgap'></div>\n";
    if ($Conf->setting("allowPaperOption") >= 5) {
	echo "<div class='f-i'>
  <div class='f-c'>Address line 1</div>
  <div class='f-e'><input class='textlite' type='text' name='addressLine1' size='52' value=\"", crpformvalue('addressLine1'), "\" /></div>
</div>\n\n";
	echo "<div class='f-i'>
  <div class='f-c'>Address line 2</div>
  <div class='f-e'><input class='textlite' type='text' name='addressLine2' size='52' value=\"", crpformvalue('addressLine2'), "\" /></div>
</div>\n\n";
	echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>City</div>
  <div class='f-e'><input class='textlite' type='text' name='city' size='32' value=\"", crpformvalue('city'), "\" /></div>
</div>";
	echo "<div class='f-ix'>
  <div class='f-c'>State/Province/Region</div>
  <div class='f-e'><input class='textlite' type='text' name='state' size='24' value=\"", crpformvalue('state'), "\" /></div>
</div>";
	echo "<div class='f-ix'>
  <div class='f-c'>ZIP/Postal code</div>
  <div class='f-e'><input class='textlite' type='text' name='zipCode' size='12' value=\"", crpformvalue('zipCode'), "\" /></div>
</div><div class='clear'></div></div>\n\n";
	echo "<div class='f-i'>
  <div class='f-c'>Country</div>
  <div class='f-e'>";
	countrySelector("country", (isset($_REQUEST["country"]) ? $_REQUEST["country"] : $Acct->country));
	echo "</div>\n</div>\n";
    }
    echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Phone <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='voicePhoneNumber' size='24' value=\"", crpformvalue('voicePhoneNumber'), "\" /></div>
</div><div class='f-ix'>
  <div class='f-c'>Fax <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='faxPhoneNumber' size='24' value=\"", crpformvalue('faxPhoneNumber'), "\" /></div>
</div><div class='clear'></div></div>\n";
}

echo "</div></td>\n</tr>\n\n";

if ($Conf->setting("allowPaperOption") >= 6) {
    echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div></td></tr>\n\n",
	"<tr><td class='caption'>Email notification</td><td class='entry'>",
	"<input type='checkbox' name='watchcomment' value='", WATCH_COMMENT, "'";
    if ($Acct->defaultWatch & WATCH_COMMENT)
	echo " checked='checked'";
    echo " />&nbsp;Mail me when new comments are available for papers I wrote or reviewed</td></tr>\n\n";
}


if ($Acct->isPC || $newProfile)
    echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div><strong>Program committee information</strong></td></tr>\n";


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo "<tr>
  <td class='caption'>Roles</td>
  <td class='entry'>\n";
    foreach (array("pc" => "PC&nbsp;member", "chair" => "PC&nbsp;chair", "ass" => "System&nbsp;administrator") as $key => $value) {
	echo "    <input type='checkbox' name='$key' id='$key' value='1' ";
	if (defval($_REQUEST, $key))
	    echo "checked='checked' ";
	if ($Acct->contactId == $Me->contactId)
	    echo "disabled='disabled' ";
	echo "onclick='doRole(this)' />&nbsp;", $value, "&nbsp;&nbsp;\n";
    }
    echo "<div class='hint'>PC chairs and system administrators have full privilege over all operations of the site.  Administrators need not be members of the PC.</div>\n";
    echo "  </td>\n</tr>\n\n";
}


if ($Acct->isPC || $newProfile) {
    echo "<tr>
  <td class='caption'>Collaborators and other affiliations</td>
  <td class='entry'><div class='hint'>Please list potential conflicts of interest.  ", $Conf->conflictDefinitionText(), "  List one conflict per line.
    We use this information when assigning reviews.
    For example: &ldquo;<tt>Ping Yen Zhang (INRIA)</tt>&rdquo;
    or, for a whole institution, &ldquo;<tt>INRIA</tt>&rdquo;.</div>
    <textarea class='textlite' name='collaborators' rows='5' cols='50'>", htmlspecialchars($Acct->collaborators), "</textarea></td>
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
		echo "/></td>";
	    }
	    echo "</td></tr>\n";
	}
	echo "    </table></td>
</tr>";
    }
}


echo "<tr><td class='caption'></td>
  <td class='entry'><div class='smgap'></div>
    <input class='button' type='submit' value='",
    ($newProfile ? "Create account" : "Save changes"),
    "' name='register' />
    <div class='xsmgap'></div></td>
</tr>
<tr class='last'><td class='caption'></td></tr>
</table></div></form>\n";


$Conf->footer();
