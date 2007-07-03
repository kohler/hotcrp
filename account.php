<?php 
// account.php -- HotCRP account management page
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once('Code/header.inc');
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
    if (!$newProfile && defval($_REQUEST["upassword"], "") == "") {
	$UpdateError = "Blank passwords are not allowed.";
	$Error['password'] = true;
    } else if (!$newProfile && $_REQUEST["upassword"] != defval($_REQUEST["upassword2"], "")) {
	$UpdateError = "The two passwords you entered did not match.";
	$Error['password'] = true;
    } else if (!$newProfile && trim($_REQUEST["upassword"]) != $_REQUEST["upassword"]) {
	$UpdateError = "Passwords cannot begin or end with spaces.";
	$Error['password'] = true;
    } else if ($_REQUEST["uemail"] != $Acct->email
	     && $Conf->getContactId($_REQUEST["uemail"])) {
	$UpdateError = "An account is already registered with email address \"" . htmlspecialchars($_REQUEST["uemail"]) . "\".";
	if (!$newProfile)
	    $UpdateError .= "You may want to <a href='mergeaccounts.php'>merge these accounts</a>.";
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
		if ($k == "pc")
		    $now = ($Acct->roles & Contact::ROLE_PC) != 0;
		else if ($k == "ass")
		    $now = ($Acct->roles & Contact::ROLE_ASSISTANT) != 0;
		else
		    $now = ($Acct->roles & Contact::ROLE_CHAIR) != 0;
		if ($now && !isset($_REQUEST[$k])) {
		    $Conf->qe("delete from $table where contactId=$Acct->contactId", $while);
		    $Conf->log("Removed as $table by $Me->email", $Acct);
		    $changed = true;
		} else if (!$now && isset($_REQUEST[$k])) {
		    $Conf->qe("insert into $table (contactId) values ($Acct->contactId)", $while);
		    $Conf->log("Added as $table by $Me->email", $Acct);
		    $changed = true;
		}
	    }
	    if ($changed) {
		$t = time();
		$Conf->qe("insert into Settings (name, value) values ('pc', $t) on duplicate key update value=$t");
	    }
	}
	
	$Acct->firstName = $_REQUEST["firstName"];
	$Acct->lastName = $_REQUEST["lastName"];
	$Acct->email = $_REQUEST["uemail"];
	$Acct->affiliation = $_REQUEST["affiliation"];
	if (isset($_REQUEST["voicePhoneNumber"]))
	    $Acct->voicePhoneNumber = $_REQUEST["voicePhoneNumber"];
	if (isset($_REQUEST["faxPhoneNumber"]))
	    $Acct->faxPhoneNumber = $_REQUEST["faxPhoneNumber"];
	if (!$newProfile)
	    $Acct->password = $_REQUEST["upassword"];
	if (isset($_REQUEST['collaborators']))
	    $Acct->collaborators = $_REQUEST['collaborators'];

	if ($OK)
	    $Acct->updateDB($Conf);

	// if PC member, update collaborators and areas of expertise
	if (($Acct->isPC || $newProfile) && $OK) {
	    // remove all current interests
	    $Conf->qe("delete from TopicInterest where contactId=$Acct->contactId", "while updating topic interests");

	    foreach ($_REQUEST as $key => $value)
		if ($OK && $key[0] == 't' && $key[1] == 'i'
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
	    if ($Me->contactId == $Acct->contactId)
		$Me->go("index.php");
	}
    }
 }


function crpformvalue($val, $field = null) {
    global $Acct;
    if (isset($_REQUEST[$val]))
	echo htmlspecialchars($_REQUEST[$val]);
    else
	echo htmlspecialchars($field ? $Acct->$field : $Acct->$val);
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


$Conf->header($newProfile ? "Create Account" : "Profile", "account", actionBar());


if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1 && $Acct->isPC) {
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


echo "<form name='account' method='post' action='account.php'>\n";
if ($newProfile)
    echo "<input type='hidden' name='new' value='1' />\n";
else if ($Me->contactId != $Acct->contactId)
    echo "<input type='hidden' name='contact' value='$Acct->contactId' />\n";


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


echo "<div class='f-i'><div class='f-ix'>
  <div class='f-c'>Phone <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='voicePhoneNumber' size='24' value=\"", crpformvalue('voicePhoneNumber'), "\" /></div>
</div><div class='f-ix'>
  <div class='f-c'>Fax <span class='f-cx'>(optional)</span></div>
  <div class='f-e'><input class='textlite' type='text' name='faxPhoneNumber' size='24' value=\"", crpformvalue('faxPhoneNumber'), "\" /></div>
</div><div class='clear'></div></div>\n</td>\n</tr>\n\n";


if ($Acct->isPC || $newProfile)
    echo "<tr><td class='caption'></td><td class='entry'><div class='smgap'></div><strong>Program committee information</strong></td></tr>\n";


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo "<tr>
  <td class='caption'>Roles</td>
  <td class='entry'>\n";
    foreach (array("pc" => "PC&nbsp;member", "chair" => "PC&nbsp;chair", "ass" => "System&nbsp;administrator") as $key => $value) {
	echo "    <input type='checkbox' name='$key' id='$key' value='1' ";
	if (defval($_REQUEST["$key"]))
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
  <td class='entry'><div class='hint'>List any
    advisor/student relationships, and your recent (~2 years) coauthors,
    collaborators, and affiliations, one per line.
    We use this information to avoid conflicts of interest when assigning
    reviews.  For example: &ldquo;<tt>Ping Yen Zhang (INRIA)</tt>&rdquo;</div>
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
</table></form>\n";


$Conf->footer();
