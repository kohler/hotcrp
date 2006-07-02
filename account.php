<?php 
require_once('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"]->goIfInvalid("../");
$Me = $_SESSION["Me"];

if (isset($_REQUEST["register"])) {
    $_REQUEST["email"] = ltrim(rtrim($_REQUEST["email"]));
    if ($_REQUEST["password"] == "") {
	$UpdateError = "Blank passwords are not allowed.";
    } else if ($_REQUEST["password"] != $_REQUEST["password2"]) {
	$UpdateError = "The two passwords you entered did not match.";
    } else if (ltrim(rtrim($_REQUEST["password"])) != $_REQUEST["password"]) {
	$UpdateError = "Passwords cannot begin or end with spaces.";
    } else if ($_REQUEST["email"] != $Me->email
	       && $Conf->emailRegistered($_REQUEST["email"])) {
	$UpdateError = "Can't change your email address to " . htmlspecialchars($_REQUEST["email"]) . ", since an account is already registered with that email address.  You may want to <a href='MergeAccounts.php'>merge these accounts</a>.";
    } else {
	$Me->firstName = $_REQUEST["firstName"];
	$Me->lastName = $_REQUEST["lastName"];
	$Me->email = $_REQUEST["email"];
	$Me->affiliation = $_REQUEST["affiliation"];
	$Me->voicePhoneNumber = $_REQUEST["voicePhoneNumber"];
	$Me->faxPhoneNumber = $_REQUEST["faxPhoneNumber"];
	$Me->password = $_REQUEST["password"];
	$success = true;

	$result = $Me->updateDB($Conf);
	if (DB::isError($result)) {
	    $success = false;
	    $UpdateError = $this->dbErrorText($result);
	}

	// if PC member, update collaborators and areas of expertise
	if ($Me->isPC) {
	    $query="UPDATE ContactInfo SET collaborators='" . $_REQUEST["collaborators"] . "' WHERE contactId='" . $_SESSION["Me"]->contactId . "'";
      
	    $result = $Conf->qe($query);

      
	    if (DB::isError($result)) {
		$success = false;
		$Conf->errorMsg("There was some problem updating your collaborators. "
				. "The error message was " .
				$result->getMessage() . " Please try again. ");
	    }


	    // query for this guy's interests
	    $query="SELECT TopicInterest.topicId, TopicInterest.interest FROM TopicInterest WHERE TopicInterest.contactId = " . $_SESSION["Me"]->contactId;
	    $result = $Conf->q($query);
	    if ( DB::isError($result)) {
		$Conf->errorMsg("Error in query for interests: " . $result->getMessage());
	    } else {
		// load interests into array
		$interests = array();
		while ( $row = $result->fetchRow()) {
		    $interests[$row[0]] = $row[1];
		}

		$topics = $_REQUEST["topics"];
		if (IsSet($topics)) {
		    foreach( $topics as $id => $interest) {
			if(IsSet($interests[$id])) {
			    $query="UPDATE TopicInterest SET "
				. " interest='$interest' WHERE "
				. " topicId='$id' AND "
				. " contactId='" . $_SESSION["Me"]->contactId . "' ";
			} else {
			    $query="INSERT into TopicInterest SET "
				. " topicId='$id', "
				. " contactId='" . $_SESSION["Me"]->contactId . "', "
				. " interest='$interest'";
			}
			$result = $Conf->qe($query);
			if (DB::isError($result)) {
			    $success = false;
			    $Conf->errorMsg("unable to associate one of your areas of interest "
					    . "with your paper due to a database error. "
					    . "The message was " . $result->getMessage() 
					    . " the query was " . $query);
			}
		    }
		}
	    }
	}
	
	if ($success) {
	    // Refresh the results
	    $Me->lookupByEmail($_REQUEST["email"], $Conf);
	    $Conf->log("Updated account", $_SESSION["Me"]);
	    $_SESSION["confirmMsg"] = "Account profile successfully updated.";
	    $Me->go("../");
	}
    }
 }

$_SESSION["Me"]->updateContactRoleInfo($Conf);

function crpformvalue($val) {
    global $Me;
    if (isset($_REQUEST[$val]))
	echo htmlspecialchars($_REQUEST[$val]);
    else
	echo htmlspecialchars($Me->$val);
}

$Conf->header("Update Profile");
?>
<div name='body'>

<?php
if (isset($UpdateError))
    $Conf->errorMsg($UpdateError);
else if ($_SESSION["AskedYouToUpdateContactInfo"] == 1) {
    $_SESSION["AskedYouToUpdateContactInfo"] = 2;
    $Conf->infoMsg("Please take a moment to update your contact information.");
 }
?>

<form class='updateProfile' method='post' action='UpdateContactInfo.php'>
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

<tr>
  <td class='form_caption'>Password<a href='#passwdfootnote'>*</a>:</td>
  <td class='form_entry'><input type='password' name='password' size='20' value="<?php crpformvalue('password') ?>" /></td>
  <td class='form_caption'>Repeat&nbsp;password:</td>
  <td class='form_entry'><input type='password' name='password2' size='20' value="<?php crpformvalue('password') ?>" /></td>
</tr>

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

<tr>
  <td></td>
  <td colspan='3'><a name='passwordfootnote'>*Please</a> note that the password is stored in our database in cleartext, and will be mailed to you if you have forgotten it.  Thus, you should not use a login password or any other password that is important to you.</td>
</tr>
  
<tr><td></td><td><input class='button_default' type='submit' value='Update Profile' name='register' /></td></tr>
</table>
</form>

<?php if ( $_SESSION["Me"]->isPC ) {
  // pc members need to indicate their collaborators, and indicate the
  // topics they are familiar with
  $query = "SELECT collaborators "
  . " FROM ContactInfo WHERE "
  . " contactId='" . $_SESSION["Me"]->contactId . "' ";
  
  $result = $Conf->qe($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("query for your collaborators failed: "
                    . $result->getMessage());
  } else {
    $row = $result->fetchRow();
    $collaborators = $row[0];
  }
  ?>
  <tr>
  <td valign="top" width="35%">Collaborators and Other Affiliations:<br>
  List all persons in alphabetical order (including their current
					  affiliations) who are currently, or who have been 
  collaborators or co-authors in the past.  This includes your
  advisor, students, and collaborators.  Please list one person
  per line.  This is used to avoid conflicts of interest when
  papers are assigned.</td>
  <td valign="top" width="65%">
  <textarea rows=20 name="collaborators" cols=75><?php echo $collaborators?></textarea></td>
  </tr>


  <?php
  $query="SELECT TopicArea.topicAreaId, TopicArea.topicName FROM TopicArea";
  $result = $Conf->q($query);

  if ( DB::isError($result)) {
    $Conf->errorMsg("Error in query for topics: " . $result->getMessage());
  } else if ($result->numRows() > 0) {
    
    // query for this guy's interests
    $query="SELECT TopicInterest.topicId, TopicInterest.interest FROM TopicInterest WHERE TopicInterest.contactId = " . $_SESSION["Me"]->contactId;
    $result1 = $Conf->q($query);
    if ( DB::isError($result1)) {
      $Conf->errorMsg("Error in query for interests: " . $result1->getMessage());
    } else {
      ?>
      <tr>
      <td valign="top" width="16%" height="19">Areas of Expertise:<br> Please
      indicate your confidence in your ability to review each of the topics
      listed to the right.  This will be used to help match papers to you
      for review.
      </td>
      <td valign="top" width="84%" height="19">
      <?php 
      $names=array();
      $names[0] = "None";
      $names[1] = "Low";
      $names[2] = "High";

      $interests=array();

      // load interests into array
      while ( $row = $result1->fetchRow()) {
	$interests[$row[0]] = $row[1];
      }

      while ($row = $result->fetchRow()) {
	$id = $row[0];
	$topic = $row[1];
	print "<B>$topic</B><br>&nbsp;&nbsp;";

	for($i=0; $i < 3; $i++) {
	  if ($interests[$id] == $i) {
	    $checked = "CHECKED";
	  } else {
	    $checked ="";
	  }
	  print "<INPUT type=radio name=topics[$id] value=$i $checked>&nbsp;$names[$i] ";
	}
	print "<br>";
      }
    }
    print "</td></tr>";
  }
}
?>

</table>

</form>

</div>
<?php $Conf->footer() ?>
</body>
</html>
