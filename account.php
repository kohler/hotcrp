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

$Conf->saveMessages = 1;

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
    $_REQUEST["email"] = ltrim(rtrim($_REQUEST["email"]));
    if ($_REQUEST["password"] == "" && !$newProfile)
	$UpdateError = "Blank passwords are not allowed.";
    else if ($_REQUEST["password"] != $_REQUEST["password2"] && !$newProfile)
	$UpdateError = "The two passwords you entered did not match.";
    else if (ltrim(rtrim($_REQUEST["password"])) != $_REQUEST["password"] && !$newProfile)
	$UpdateError = "Passwords cannot begin or end with spaces.";
    else if ($_REQUEST["email"] != $Me->email
	     && $Conf->emailRegistered($_REQUEST["email"]))
	$UpdateError = "Can't change your email address to " . htmlspecialchars($_REQUEST["email"]) . ", since an account is already registered with that email address.  You may want to <a href='MergeAccounts.php'>merge these accounts</a>.";
    else {
	$Conf->saveMessages = 1;
	if ($newProfile) {
	    $result = $Me->initialize($_REQUEST["email"], $Conf);
	    if ($OK) {
		$Me->sendAccountInfo($Conf);
		$Conf->log("Created account", $Me);
	    }
	    
	    // initialize roles too
	    for ($i = ROLE_PC; $i <= ROLE_CHAIR; $i++)
		if (isset($_REQUEST["role$i"]))
		    $Conf->qe("insert into Roles set contactId=$Me->contactId, role=$i", "while initializing roles");
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

	$Conf->saveMessages = 0;
	
	if ($OK) {
	    // Refresh the results
	    $Me->lookupByEmail($_REQUEST["email"], $Conf);
	    if ($newProfile) {
		$Conf->log("New account", $RealMe);
		$_SESSION["confirmMsg"] = "Successfully created an account for " . htmlspecialchars($Me->email) . ".  A password has been emailed to that address.";
	    } else {
		$Conf->log("Updated account", $RealMe);
		$_SESSION["confirmMsg"] = "Account profile successfully updated.";
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

$Conf->header($newProfile ? "Create Account" : "Update Profile");
?>

<?php
if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1 && $Me->isPC) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 3;
    $msg = ($Me->lastName ? "" : "Please take a moment to update your contact information.  ");
    $msg .= "We need a list of your recent collaborators to detect paper conflicts.  We also use your interest level in the conference's topics to assign you papers you might like.";
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
  <td class='form_caption'>Email:</td>
  <td class='form_entry' colspan='3'><input type='text' name='email' size='50' value="<?php crpformvalue('email') ?>" /></td>
</tr>

<tr>
  <td class='form_caption'>First&nbsp;name:</td>
  <td class='form_entry'><input type='text' name='firstName' size='20' value="<?php crpformvalue('firstName') ?>" /></td>
  <td class='form_caption'>Last&nbsp;name:</td>
  <td class='form_entry'><input type='text' name='lastName' size='20' value="<?php crpformvalue('lastName') ?>" /></td>
</tr>

<?php if (!$newProfile) { ?>
<tr>
  <td class='form_caption'>Password:</td>
  <td class='form_entry'><input type='password' name='password' size='20' value="<?php crpformvalue('password') ?>" /></td>
  <td class='form_caption'>Repeat&nbsp;password:</td>
  <td class='form_entry'><input type='password' name='password2' size='20' value="<?php crpformvalue('password') ?>" /></td>
  <td class='form_hint'>Please note that the password is stored in our database in cleartext, and will be mailed to you if you have forgotten it.  Thus, you should not use a login password or any other password that is important to you.</td>
</tr>
<?php } ?>

<tr>
  <td class='form_caption'>Affiliation:</td>
  <td class='form_entry' colspan='3'><input type='text' name='affiliation' size='50' value="<?php crpformvalue('affiliation') ?>" /></td>
</tr>

<tr>
  <td class='form_caption'>Phone:</td>
  <td class='form_entry'><input type='text' name='voicePhoneNumber' size='20' value="<?php crpformvalue('voicePhoneNumber') ?>" /></td>
  <td class='form_caption'>Fax:</td>
  <td class='form_entry'><input type='text' name='faxPhoneNumber' size='20' value="<?php crpformvalue('faxPhoneNumber') ?>" /></td>
</tr>

<?php if ($newProfile) { ?>

<tr>
  <td class='form_caption'>Roles:</td>
  <td colspan='3' class='form_entry'>
<?php
    foreach (array(ROLE_PC => "PC&nbsp;member", ROLE_ASSISTANT => "Chair's&nbsp;assistant", ROLE_CHAIR => "PC&nbsp;chair") as $key => $value) {
	echo "    <input type='checkbox' name='role$key' value='1' ";
	if (isset($_REQUEST["role$key"])) echo "checked='checked' ";
	echo "/>&nbsp;", $value, "&nbsp;&nbsp;\n";
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
  <td class='form_caption'>Collaborators and&nbsp;other&nbsp;affiliations:</td>
  <td class='form_entry' colspan='3'><textarea class='textlite' name='collaborators' rows='5'><?php echo htmlspecialchars($Me->collaborators) ?></textarea></td>
  <td class='form_hint'>List advisors, students, and other recent 
    coauthors and collaborators one per line.  We use this information to
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
  <td class='form_caption'>Topic&nbsp;interests:</td>
  <td class='form_entry' colspan='3' id='topicinterest'><table class='topicinterest'>
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
  <td class='form_hint'>Please indicate how much you would want to review
	these conference topics.  We use this information to help match papers
	to reviewers.</td>
</tr>";
    }
} ?>

<tr>
  <td></td>
  <td><input class='button_default' type='submit' value='<?php
    if ($newProfile)
	echo "Create Account";
    else
	echo "Update Profile";
?>' name='register' /></td>
</tr>

</table>
</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
