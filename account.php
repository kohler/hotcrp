<?php 
include('../Code/confHeader.inc');
$Conf -> connect();
$_SESSION[Me] -> goIfInvalid("../");
?>

<html>
<?php  $Conf->header("Modify Account Information") ?>
<body>

<?php 
if (IsSet($_REQUEST[register]) ) {
  if ( $_REQUEST[firstEmail] != $_REQUEST[secondEmail] ) {
    $Conf->errorMsg(
		    "Your first email address ($_REQUEST[firstEmail]) does not match your "
		    . "second email ($_REQUEST[secondEmail]). Please correct "
		    . "this problem");
  } else if ( $_REQUEST[passwd] == "") {
    $Conf->errorMsg(
		 "You used a blank password. This must contain a value");
  } else {
    //
    // Update Me
    //
    $_SESSION[Me] -> firstName = $_REQUEST[firstName];
    $_SESSION[Me] -> lastName = $_REQUEST[lastName];
    $_SESSION[Me] -> email = $_REQUEST[firstEmail];
    $_SESSION[Me] -> affiliation = $_REQUEST[affiliation];
    $_SESSION[Me] -> voicePhoneNumber = $_REQUEST[phone];
    $_SESSION[Me] -> faxPhoneNumber = $_REQUEST[fax];
    $_SESSION[Me] -> password = $_REQUEST[passwd];

    $success = true;

    $result = $_SESSION[Me] -> updateDB($Conf);

    if (DB::isError($result)) {
      $success = false;
      $Conf->errorMsg("There was some problem updating your information. "
		      . "The error message was " .
		      $result -> getMessage() . " Please try again. ");
    } 

    // if we are a pc member, update our collaborators and areas of expertise
    if ( $_SESSION[Me]->isPC ) {
      $query="UPDATE ContactInfo SET collaborators='$_REQUEST[collaborators]' WHERE contactId='" . $_SESSION[Me]->contactId . "'";
      
      $result = $Conf->qe($query);

      
      if (DB::isError($result)) {
	$success = false;
	$Conf->errorMsg("There was some problem updating your collaborators. "
			. "The error message was " .
			$result -> getMessage() . " Please try again. ");
      }


      // query for this guy's interests
      $query="SELECT TopicInterest.topicId, TopicInterest.interest FROM TopicInterest WHERE TopicInterest.contactId = " . $_SESSION[Me]->contactId;
      $result = $Conf->q($query);
      if ( DB::isError($result)) {
	$Conf->errorMsg("Error in query for interests: " . $result->getMessage());
      }
      else {
	// load interests into array
	$interests = array();
	while ( $row = $result->fetchRow()) {
	  $interests[$row[0]] = $row[1];
	}

	$topics = $_REQUEST[topics];
	if (IsSet($topics)) {
	  foreach( $topics as $id => $interest) {
	    if(IsSet($interests[$id])) {
	      $query="UPDATE TopicInterest SET "
		. " interest='$interest' WHERE "
		. " topicId='$id' AND "
		. " contactId='" . $_SESSION[Me]->contactId . "' ";
	    }
	    else {
	      $query="INSERT into TopicInterest SET "
		. " topicId='$id', "
		. " contactId='" . $_SESSION[Me]->contactId . "', "
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
      //
      // Refresh the results
      //
      $_SESSION[Me] -> lookupByEmail($_REQUEST[firstEmail],$Conf);
      $Conf->confirmMsg("You account was successfully updated. "
			. "You may make other modifications if you wish, "
			. "or you may <a href=\"../index.php\"> return to conference page </a>."
		   );


      $Conf->log("Updated account", $_SESSION[Me]);
    }
  }
}
$_SESSION[Me] -> updateContactRoleInfo($Conf);

if ($_SESSION[Me] -> firstName == "" || $_SESSION[Me] -> lastName == "" ) {
  $Conf->infoMsg("<center> <h2>"
		 . " Please take a moment to update your "
		 . " contact information<br> (last and first name) "
		 . " </h2> </center>");
}

?>

<table width="75%" cellpadding=2 align=center>
<tr> <td>
<h2>
You can modify your contact information below.
Once you're done, hit the button at the bottom on the page.
</td> </tr>
</table>

</h2>
        <form method="POST" action="<?php  echo $_SERVER[PHP_SELF] ?>">
          <div align="center">
            <table border="1" width="75%" bgcolor="<?php echo $Conf->bgTwo?>">
              <tr>
                <td width="35%">First Name</td>
                <td width="65%"><input type="text" name="firstName" size="44"
			value="<?php echo $_SESSION[Me]->firstName?>" ></td>
              </tr>
              <tr>
                <td width="35%">Last Name</td>
                <td width="65%"><input type="text" name="lastName" size="44"
			value="<?php echo $_SESSION[Me]->lastName?>" ></td>
              </tr>
              <tr>
                <td width="35%">Email</td>
                <td width="65%"><input type="text" name="firstEmail" size="44"
			value="<?php echo $_SESSION[Me]->email?>" ></td>
              </tr>
              <tr>
                <td width="35%">Email Again</td>
                <td width="65%"><input type="text" name="secondEmail" size="44"
			value="<?php echo $_SESSION[Me]->email?>" ></td>
              </tr>
              <tr>
                <td width="35%">Password</td>
                <td width="65%"><input type="password" name="passwd" size="44"
			value="<?php echo $_SESSION[Me]->password?>" ></td>
              </tr>
              <tr>
                <td width="35%">Affiliation</td>
                <td width="65%"><input type="text" name="affiliation" size="44"
			value="<?php echo $_SESSION[Me]->affiliation?>" ></td>
              </tr>
              <tr>
                <td width="35%">Voice Phone</td>
                <td width="65%"><input type="text" name="phone" size="44"
			value="<?php echo $_SESSION[Me]->voicePhoneNumber?>" ></td>
              </tr>
              <tr>
                <td width="35%">Fax</td>
                <td width="65%"><input type="text" name="fax" size="44"
			value="<?php echo $_SESSION[Me]->faxPhoneNumber?>" ></td>
              </tr>
<?php if ( $_SESSION[Me]->isPC ) {
  // pc members need to indicate their collaborators, and indicate the
  // topics they are familiar with
  $query = "SELECT collaborators "
  . " FROM ContactInfo WHERE "
  . " contactId='" . $_SESSION[Me]->contactId . "' ";
  
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
    $query="SELECT TopicInterest.topicId, TopicInterest.interest FROM TopicInterest WHERE TopicInterest.contactId = " . $_SESSION[Me]->contactId;
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

<table width="75%" cellpadding=2 bgcolor=<?php echo $Conf->infoColor?> align=center>
<tr> <td>
<h3>
Please note that the password entered is stored
in our database in cleartext, and will be mailed to you
if you have forgotten your password. Thus, you should not
use your login password or other passwords that are important
to you.
</h3>
</td> </tr>
<tr> <td align=center>
<p><input type="submit" value="Update My Contact Information"
name="register"></p>
</tr> <td>
</table>

</form>

<?php  $Conf->footer() ?>
</body>
</html>
