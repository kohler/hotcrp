<?php 
require_once('Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$newProfile = false;
$Error = array();


if (!$Me->amAssistant())
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

	if ($Me->amAssistant() && $Me->contactId != $Acct->contactId) {
	    // initialize roles too
	    if (isset($_REQUEST["ass"]) || isset($_REQUEST["chair"]))
		$_REQUEST["pc"] = 1;
	    $while = "while initializing roles";
	    $changed = false;
	    foreach (array("pc" => "PCMember", "ass" => "ChairAssistant", "chair" => "Chair") as $k => $table) {
		$now = ($k == "pc" ? $Acct->isPC : ($k == "ass" ? $Acct->isAssistant : $Acct->isChair));
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

function capclass($what) {
    global $Error;
    return (isset($Error[$what]) ? "caption error" : "caption");
}

$_REQUEST["pc"] = $Acct->isPC;
$_REQUEST["ass"] = $Acct->isAssistant;
$_REQUEST["chair"] = $Acct->isChair;


$Conf->header($newProfile ? "Create Account" : "Account Settings");


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


echo "<form method='post' action='account.php'>\n";
if ($newProfile)
    echo "<input type='hidden' name='new' value='1' />\n";
else if ($Me->contactId != $Acct->contactId)
    echo "<input type='hidden' name='contact' value='$Acct->contactId' />\n";


echo "<table class='form'>
<tr>
  <td class='", capclass('email'), "'>Email</td>
  <td class='entry' colspan='3'><input class='textlite' type='text' name='uemail' size='50' value=\"", crpformvalue('uemail', 'email'), "\" /></td>
</tr>\n\n";

echo "<tr>
  <td class='", capclass('firstName'), "'>First&nbsp;name</td>
  <td class='entry'><input class='textlite' type='text' name='firstName' size='20' value=\"", crpformvalue('firstName'), "\" /></td>
  <td class='", capclass('lastName'), "'>Last&nbsp;name</td>
  <td class='entry'><input class='textlite' type='text' name='lastName' size='20' value=\"", crpformvalue('lastName'), "\" /></td>
</tr>\n\n";

if (!$newProfile) {
    echo "<tr>
  <td class='", capclass('password'), "'>Password</td>
  <td class='entry'><input class='textlite' type='password' name='upassword' size='20' value=\"", crpformvalue('upassword', 'password'), "\" /></td>
  <td class='", capclass('password'), "'>Repeat password</td>
  <td class='entry'><input class='textlite' type='password' name='upassword2' size='20' value=\"", crpformvalue('upassword', 'password'), "\" /></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='hint' colspan='4'>The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don't use a login password or any other high-security password.</td>
</tr>\n\n";
}


echo "<tr>
  <td class='", capclass('affiliation'), "'>Affiliation</td>
  <td class='entry' colspan='3'><input class='textlite' type='text' name='affiliation' size='50' value=\"", crpformvalue('affiliation'), "\" /></td>
</tr>\n\n";


echo "<tr>
  <td class='caption'>Phone</td>
  <td class='entry'><input class='textlite' type='text' name='voicePhoneNumber' size='20' value=\"", crpformvalue('voicePhoneNumber'), "\" /></td>
  <td class='caption'>Fax</td>
  <td class='entry'><input class='textlite' type='text' name='faxPhoneNumber' size='20' value=\"", crpformvalue('faxPhoneNumber'), "\" /></td>
</tr>\n\n";


if ($Acct->isPC || $newProfile)
    echo "<tr><td class='caption'></td><td colspan='4' class='entry'><div class='smgap'></div><strong>Program committee-specific information</strong></td></tr>\n";


if ($newProfile || $Acct->contactId != $Me->contactId) {
    echo "<tr>
  <td class='caption'>Roles</td>
  <td colspan='3' class='entry'>\n";
    foreach (array("pc" => "PC&nbsp;member", "ass" => "Chair's&nbsp;assistant", "chair" => "PC&nbsp;chair") as $key => $value) {
	echo "    <input type='checkbox' name='$key' id='$key' value='1' ";
	if (defval($_REQUEST["$key"]))
	    echo "checked='checked' ";
	echo "onclick='doRole(this)' />&nbsp;", $value, "&nbsp;&nbsp;\n";
    }
    echo "  </td>\n</tr>\n\n";
}


if ($Acct->isPC || $newProfile) {
    echo "<tr>
  <td class='caption'>Collaborators and other affiliations</td>
  <td class='entry textarea' colspan='3'><textarea class='textlite' name='collaborators' rows='5'>", htmlspecialchars($Acct->collaborators), "</textarea></td>
  <td class='hint'>List your recent (~2 years) coauthors, collaborators,
    and affiliations, and any advisor or student relationships, one per line.
    We use this information to
    avoid conflicts of interest when assigning reviews.  Example:
    <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)
(IIT Madras)</pre></td>
</tr>\n\n";

    $result = $Conf->q("select TopicArea.topicId, TopicArea.topicName, TopicInterest.interest from TopicArea left join TopicInterest on TopicInterest.contactId=$Acct->contactId and TopicInterest.topicId=TopicArea.topicId order by TopicArea.topicName");
    if (edb_nrows($result) > 0) {
	echo "<tr id='topicinterest'>
  <td class='caption'>Topic interests</td>
  <td class='entry' colspan='3' id='topicinterest'><table class='topicinterest'>
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
  <td class='hint'>Please indicate how much you would want to review
	these conference topics.  We use this information to help match papers
	to reviewers.</td>
</tr>";
    }
}


echo "<tr><td class='caption'></td>
  <td class='entry'><input class='button_default' type='submit' value='",
    ($newProfile ? "Create Account" : "Save Changes"),
    "' name='register' /></td>
</tr>
</table></form>";


// if (!$newAccount) {
//     echo "<form method='post' action='account.php'>\n";
//     if ($Acct->contactId != $Me->contactId)
// 	echo "<input type='hidden' name='contact' value='$Acct->contactId' />\n";
//     echo "<table>
// <tr><td class='caption'></td><td class='entry'><div class='smgap'></div>
//   <h2>Merge accounts</h2></td></tr>\n";
//     echo "</table></form>";
// }


echo "\n";
$Conf->footer();
