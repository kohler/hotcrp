<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$Me = $_SESSION["Me"];
$RealMe = $Me;

$newProfile = (isset($_REQUEST["new"]) && $Me->amAssistant());
if ($newProfile) {
    $Me = new Contact;
    $Me->invalidate();
 }

if (isset($_REQUEST["register"])) {
    $needFields = array('email', 'firstName', 'lastName', 'affiliation');
    if (!$newProfile)
	$needFields[] = "password";
    foreach ($needFields as $field)
	if (!isset($_REQUEST[$field])) {
	    $Conf->errorMsg("Required form fields missing.");
	    $OK = 0;
	}
 }

if (isset($_REQUEST['register']) && $OK) {
    $_REQUEST["email"] = trim($_REQUEST["email"]);
    if ($_REQUEST["password"] == "" && !$newProfile)
	$UpdateError = "Blank passwords are not allowed.";
    else if ($_REQUEST["password"] != $_REQUEST["password2"] && !$newProfile)
	$UpdateError = "The two passwords you entered did not match.";
    else if (trim($_REQUEST["password"]) != $_REQUEST["password"] && !$newProfile)
	$UpdateError = "Passwords cannot begin or end with spaces.";
    else if ($_REQUEST["email"] != $Me->email
	     && $Conf->getContactId($_REQUEST["email"]))
	$UpdateError = "Can't change your email address to " . htmlspecialchars($_REQUEST["email"]) . ", since an account is already registered with that email address.  You may want to <a href='MergeAccounts.php'>merge these accounts</a>.";
    else {
	if ($newProfile) {
	    $result = $Me->initialize($_REQUEST["email"], $Conf);
	    if ($OK) {
		$Me->sendAccountInfo($Conf, true);
		$Conf->log("Created account", $Me);
	    }
	    
	    // initialize roles too
	    if (isset($_REQUEST["ass"]) || isset($_REQUEST["chair"]))
		$_REQUEST["pc"] = 1;
	    if (isset($_REQUEST["pc"]))
		$Conf->qe("insert into PCMember set contactId=$Me->contactId", "while initializing roles");
	    if (isset($_REQUEST["ass"]))
		$Conf->qe("insert into ChairAssistant set contactId=$Me->contactId", "while initializing roles");
	    if (isset($_REQUEST["chair"]))
		$Conf->qe("insert into Chair set contactId=$Me->contactId", "while initializing roles");
	}
	
	$Me->firstName = $_REQUEST["firstName"];
	$Me->lastName = $_REQUEST["lastName"];
	$Me->email = $_REQUEST["email"];
	$Me->affiliation = $_REQUEST["affiliation"];
	if (isset($_REQUEST["voicePhoneNumber"]))
	    $Me->voicePhoneNumber = $_REQUEST["voicePhoneNumber"];
	if (isset($_REQUEST["faxPhoneNumber"]))
	    $Me->faxPhoneNumber = $_REQUEST["faxPhoneNumber"];
	if (!$newProfile)
	    $Me->password = $_REQUEST["password"];
	if (isset($_REQUEST['collaborators']))
	    $Me->collaborators = $_REQUEST['collaborators'];

	if ($OK)
	    $result = $Me->updateDB($Conf);

	// if PC member, update collaborators and areas of expertise
	if (($Me->isPC || $newProfile) && $OK) {
	    // remove all current interests
	    $Conf->qe("delete from TopicInterest where contactId=$Me->contactId", "while updating topic interests");

	    foreach ($_REQUEST as $key => $value)
		if ($OK && $key[0] == 't' && $key[1] == 'i'
		    && ($id = (int) substr($key, 2)) > 0
		    && is_numeric($value)
		    && ($value = (int) $value) >= 0 && $value < 3)
		    $Conf->qe("insert into TopicInterest set contactId=$Me->contactId, topicId=$id, interest=$value", "while updating topic interests");
	}

	if ($OK) {
	    // Refresh the results
	    $Me->lookupByEmail($_REQUEST["email"], $Conf);
	    if ($newProfile) {
		$Conf->log("New account", $RealMe);
		$Conf->confirmMsg("Successfully created an account for " . htmlspecialchars($Me->email) . ".  A password has been emailed to that address.");
	    } else {
		$Conf->log("Updated account", $RealMe);
		$Conf->confirmMsg("Account profile successfully updated.");
	    }
	    $RealMe->go("../");
	}
    }
 }


function crpformvalue($val) {
    global $Me;
    if (isset($_REQUEST[$val]))
	echo htmlspecialchars($_REQUEST[$val]);
    else
	echo htmlspecialchars($Me->$val);
}

$title = ($newProfile ? "Create Account" : "Edit Profile");
$Conf->header_head($title);
?>
<script type="text/javascript"><!--
function doRole(what) {
    var pc = document.getElementById("pc");
    var ass = document.getElementById("ass");
    var chair = document.getElementById("chair");
    if (pc == what && !pc.checked)
	ass.checked = chair.checked = false;
    if (pc != what && (ass.checked || chair.checked))
	pc.checked = true;
}
// -->
</script>

<?php
$Conf->header($title);

if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1 && $Me->isPC) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 3;
    $msg = ($Me->lastName ? "" : "Please take a moment to update your contact information.  ");
    $msg .= "We need a list of your recent collaborators to detect paper conflicts.  If you have no collaborators, enter \"None\".";
    $result = $Conf->q("select * from TopicArea");
    if (!DB::isError($result) && $result->numRows() > 0)
	$msg .= "  Additionally, we use your topic interests to assign you papers you might like.";
    $Conf->infoMsg($msg);
} else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 2;
    $Conf->infoMsg("Please take a moment to update your contact information.");
 }
?>

<form class='updateProfile' method='post' action='UpdateContactInfo.php'>
<?php if ($newProfile) echo "<input type='hidden' name='new' value='1' />\n"; ?>
<table class='form'>
<tr>
  <td class='caption'>Email</td>
  <td class='entry' colspan='3'><input class='textlite' type='text' name='email' size='50' value="<?php crpformvalue('email') ?>" /></td>
</tr>

<tr>
  <td class='caption'>First&nbsp;name</td>
  <td class='entry'><input class='textlite' type='text' name='firstName' size='20' value="<?php crpformvalue('firstName') ?>" /></td>
  <td class='caption'>Last&nbsp;name</td>
  <td class='entry'><input class='textlite' type='text' name='lastName' size='20' value="<?php crpformvalue('lastName') ?>" /></td>
</tr>

<?php if (!$newProfile) { ?>
<tr>
  <td class='caption'>Password</td>
  <td class='entry'><input class='textlite' type='password' name='password' size='20' value="<?php crpformvalue('password') ?>" /></td>
  <td class='caption'>Repeat password</td>
  <td class='entry'><input class='textlite' type='password' name='password2' size='20' value="<?php crpformvalue('password') ?>" /></td>
</tr>

<tr>
  <td class='caption'></td>
  <td class='hint' colspan='4'>Please note that the password is stored in our database in cleartext, and will be mailed to you if you have forgotten it.  Thus, you should not use a login password or any other password that is important to you.</td>
</tr>
<?php } ?>

<tr>
  <td class='caption'>Affiliation</td>
  <td class='entry' colspan='3'><input class='textlite' type='text' name='affiliation' size='50' value="<?php crpformvalue('affiliation') ?>" /></td>
</tr>

<tr>
  <td class='caption'>Phone</td>
  <td class='entry'><input class='textlite' type='text' name='voicePhoneNumber' size='20' value="<?php crpformvalue('voicePhoneNumber') ?>" /></td>
  <td class='caption'>Fax</td>
  <td class='entry'><input class='textlite' type='text' name='faxPhoneNumber' size='20' value="<?php crpformvalue('faxPhoneNumber') ?>" /></td>
</tr>

<?php if ($newProfile) { ?>

<tr>
  <td class='caption'>Roles</td>
  <td colspan='3' class='entry'>
<?php
    foreach (array("pc" => "PC&nbsp;member", "ass" => "Chair's&nbsp;assistant", "chair" => "PC&nbsp;chair") as $key => $value) {
	echo "    <input type='checkbox' name='$key' id='$key' value='1' ";
	if (isset($_REQUEST["$key"]))
	    echo "checked='checked' ";
	echo "onclick='doRole(this)' />&nbsp;", $value, "&nbsp;&nbsp;\n";
    }
?>
  </td>
</tr>

<tr>
  <td></td>
  <td colspan='4'><hr/><strong>PC/Reviewer Information</td>
</tr>

<?php } ?>

<?php if ($Me->isPC || $newProfile) { ?>
<tr>
  <td class='caption'>Collaborators and other affiliations</td>
  <td class='entry' colspan='3'><textarea class='textlite' name='collaborators' rows='5'><?php echo htmlspecialchars($Me->collaborators) ?></textarea></td>
  <td class='hint'>List your recent (~2 years) coauthors, collaborators,
    and affiliations, and any advisor or student relationships, one per line.
    We use this information to
    avoid conflicts of interest when assigning reviews.  Example:
    <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)
(IIT Madras)</pre></td>
</tr>

<?php
    $result = $Conf->q("select TopicArea.topicId, TopicArea.topicName, TopicInterest.interest from TopicArea left join TopicInterest on TopicInterest.contactId=$Me->contactId and TopicInterest.topicId=TopicArea.topicId order by TopicArea.topicName");
    if (!DB::isError($result) && $result->numRows() > 0) {
	echo "<tr id='topicinterest'>
  <td class='caption'>Topic interests</td>
  <td class='entry' colspan='3' id='topicinterest'><table class='topicinterest'>
       <tr><td></td><th>Low</th><th>Med.</th><th>High</th></tr>\n";
	for ($i = 0; $i < $result->numRows(); $i++) {
	    $row = $result->fetchRow();
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
} ?>

<tr>
  <td class='caption'></td>
  <td class='entry'><input class='button_default' type='submit' value='<?php
    if ($newProfile)
	echo "Create Account";
    else
	echo "Save Profile";
?>' name='register' /></td>
</tr>

</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
