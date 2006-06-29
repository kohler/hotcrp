<?php 
//
// Configuration for a Contact (i.e. someone using the reviewing system)
//

function alert($string = "") {
  print "<script language=javascript>\n";
  print "alert(\"" . addslashes($string).  "\");";
  print "</script>";
}

function go($url) {
//      header("Location: $url");
// exit();
  echo "<html>";
  echo "<script language=javascript>\n";
  echo "location=\"$url\";";
  echo "</script>";
  echo "<body> <p> You should not be here. ";
  echo "You need to <a href=\"$url\"> login </a> </p> </body>";
  echo "</html>";
  exit();
}

class Contact {
  //
  // Which conference are we dealing with?
  //
  var $shortConfName;

  //
  // Information from the SQL definition
  //
  var $contactId;
  var $visits;
  var $firstName;
  var $lastName;
  var $email;
  var $affiliation;
  var $voicePhoneNumber;
  var $faxPhoneNumber;
  var $password;
  var $uniquid; // is this used?
  var $note;

  //
  // The following fields are taken from tables other
  // than Contact
  //
  var $papersFinalized;
  var $papersSubmitted;

  //
  // Roles
  //
  var $isAuthor;
  var $isPC;
  var $isChair;
  var $isAssistant;

  function amAssistant() {
    return $this -> isAssistant || $this -> isChair;
  }

  function countRequestedReviews() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM ReviewRequest WHERE "
      . " asked=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result -> numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function countRequestedPrimary() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM PrimaryReviewer WHERE "
      . " reviewer=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result -> numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function countRequestedSecondary() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM SecondaryReviewer WHERE "
      . " reviewer=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result -> numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function amReviewer() {
    global $Conf;

    $reviewer = false;

    if ($Conf->validTimeFor('reviewerSubmitReviewDeadline', 0)
	|| $Conf->validTimeFor('reviewerViewDecision', 0) ) {
      //
      // See if I have any review requests
      //
      $reviewer = $this->countRequestedReviews() > 0;
    }

    if ($_SESSION[Me] -> isPc && $Conf->validTimeFor('PCSubmitReview')) {
      $pcreviews = $this->countPrimaryReviews()
	+ $this->countSecondaryReviews();
      $reviewer |= $pcreviews > 0;
    }
    return $reviewer;
  }

  //
  // Initialization functions
  //

  function valid() {
    global $Conf;
    return $this -> contactId > 0 && ($Conf->shortName == $this->shortConfName);
    return $this -> contactId > 0;
  }

  function invalidate()
    {
      $this->confShortName= 0;

      $this -> contactId = 0;
      $this -> visits = 0;
      $this -> firstName = 0;
      $this -> lastName = 0;
      $this -> email = 0;
      $this -> affiliation = 0;
      $this -> voicePhoneNumber = 0;
      $this -> faxPhoneNumber = 0;
      $this -> password = 0;
      $this -> uniquid = 0;
      $this -> note = 0;
      $this -> papersFinalized = 0;
      $this -> papersSubmitted = 0;
      $this -> isAuthor = 0;
      $this -> isPC = 0;
      $this -> isChair = 0;
      $this -> isAssistant = 0;
    }

  function dump()
    {
      echo "<table border=\"1\" width=\"83%\" bgcolor=$bgOne>";
      echo "<tr> <td>";

      echo "<p>";
      echo "contactId = " . $this -> contactId . "<br>";
      echo "visits = " . $this -> visits . "<br>";
      echo "firstName = " . $this -> firstName . "<br>";
      echo "lastName = " . $this -> lastName . "<br>";
      echo "email = " . $this -> email . "<br>";
      echo "affiliation = " . $this -> affiliation . "<br>";
      echo "voicePhoneNumber = " . $this -> voicePhoneNumber . "<br>";
      echo "faxPhoneNumber = " . $this -> faxPhoneNumber . "<br>";
      echo "password = " . $this -> password . "<br>";
      echo "uniquid = " . $this -> uniquid . "<br>";
      echo "note = " . $this -> note . "<br>";
      echo "papersFinalized = " . $this -> papersFinalized . "<br>";
      echo "papersSubmitted = " . $this -> papersSubmitted . "<br>";
      echo "isAuthor = " . $this -> isAuthor . "<br>";
      echo "isPC = " . $this -> isPC . "<br>";
      echo "isChair = " . $this -> isChair . "<br>";
      echo "isAssistant = " . $this -> isAssistant . "<br>";
      echo "</p> </td> </tr> </table>";
    }

  function trim() {
    $this->contactId = ltrim(rtrim( $this->contactId));
    $this->visits = ltrim(rtrim( $this->visits));
    $this->firstName = ltrim(rtrim( $this->firstName));
    $this->lastName = ltrim(rtrim( $this->lastName));
    $this->email = ltrim(rtrim( $this->email));
    $this->affiliation = ltrim(rtrim( $this->affiliation));
    $this->voicePhoneNumber = ltrim(rtrim( $this->voicePhoneNumber));
    $this->faxPhoneNumber = ltrim(rtrim( $this->faxPhoneNumber));
    $this->password = ltrim(rtrim( $this->password));
    $this->uniquid = ltrim(rtrim( $this->uniquid));
    $this->note = ltrim(rtrim( $this->note));
  }

  function go($url) {
    go($url);
  }

  function alert($string = "") {
    alert($string);
  }

  function goAlert($url, $string = "") {
    print "<html>\n";
    print "<head>\n";
    print "<script language=javascript>\n";
    print "alert(\"" . addslashes($string).  "\");";
    print "location=\"$url\";";
    print "</script>";
    print "</head>";
    print "<body>\n";
    print "<H1> You should not be here. </h1> ";
    print "<h2> You need to <a href=\"$url\"> login </a> </h2>\n";
    print "</head>";
    print "</html>";
    exit();
  }

  function goIfInvalid($url,$string="") {
    if ( !$this -> valid() ) {
      $this->goAlert($url,
		     "You have invalid credentials and need to login"
		     );
    }
  }

  function goIfNotAuthor($url) {
    if ( ! $this -> valid() || !$this -> isAuthor ) {
      $this->goAlert($url,
		     "This page is only accessible by paper authors"
		     );
    }
  }

  function goIfNotPC($url) {
    if ( $this -> valid() && ($this -> isChair || $this -> isPC)) {
      return;
    } else {
      $this->goAlert($url,
		     "This page is only accessible by Program Committee members"
		     );
    }
  }

  function goIfNotChair($url) {
    if (!$this -> valid() || ! $this -> isChair ) {
      $this->goAlert($url,
		     "This page is only accessible by Program Chairs"
		     );
    }
  }

  function goIfNotAssistant($url) {
    if ( $this -> valid() && ($this -> isChair || $this -> isAssistant)) {
      return;
    } else {
      $this->goAlert($url,
		     "This page is only accessible by Program Chair Assitants"
		     );
    }
  }

  function updateDB($conf)
    {
      $this -> trim();
      $query = "UPDATE ContactInfo SET "
	. " firstName='$this->firstName', lastname='$this->lastName', "
	. "email='$this->email', "
	. "affiliation='$this->affiliation', "
	. "voicePhoneNumber='$this->voicePhoneNumber', "
	. "faxPhoneNumber='$this->faxPhoneNumber', "
	. "password='$this->password' "
	. " WHERE contactId='$this->contactId'";

      return $conf->qe($query);
    }

  function addToDB($conf)
    {
      $this -> trim();
      $query = "INSERT into ContactInfo SET "
	. " firstName='$this->firstName', lastname='$this->lastName', "
	. "email='$this->email', "
	. "affiliation='$this->affiliation', "
	. "voicePhoneNumber='$this->voicePhoneNumber', "
	. "faxPhoneNumber='$this->faxPhoneNumber', "
	. "password='$this->password', "
	. "uniquid='$this->uniquid' ";

      return $conf->qe($query);
    }

  function initialize($firstName, $lastName, $email, $affiliation,
		      $phone, $fax)
    {
      $this -> firstName = $firstName;
      $this -> lastName = $lastName;
      $this -> email = $email;
      $this -> affiliation = $affiliation;
      $this -> voicePhoneNumber = $phone;
      $this -> faxPhoneNumber = $fax;
      $this -> uniquid= md5(uniqid(rand()));
      $this -> password= $this->generatePassword(10);

      $this -> trim();
    }

  function updateFromQuery($query, $conf) {
    $email=ltrim(rtrim($email));
    $result = $conf->qe($query);
    if ( !DB::isError($result) && $result->numRows() >= 1 ) {
      $fromdb = $result->fetchRow(DB_FETCHMODE_OBJECT);
      $this->contactId = $fromdb -> contactId;
      $this->visits = $fromdb -> visits;
      $this->firstName = $fromdb -> firstName;
      $this->lastName = $fromdb -> lastName;
      $this->email = $fromdb -> email;
      $this->affiliation = $fromdb -> affiliation;
      $this->voicePhoneNumber = $fromdb -> voicePhoneNumber;
      $this->faxPhoneNumber = $fromdb -> faxPhoneNumber;
      $this->password = $fromdb -> password;
      $this->uniquid = $fromdb -> uniquid;
      $this->note = $fromdb -> note;

      $this->shortConfName = $conf->shortName;

      $this -> trim();
    } else {
      //
      // Not found - zero out the context
      //
      $this -> invalidate();
    }
  }

  function lookupByEmail($email, $conf) {
    $email=ltrim(rtrim($email));
    $this->updateFromQuery("SELECT * FROM ContactInfo WHERE email='$email'", $conf);
  }

  function lookupById($contactId, $conf) {
    $this->updateFromQuery("SELECT * FROM ContactInfo WHERE contactId='$contactId'", $conf);
  }


  function fullname() {
    return $this -> firstName . " " . $this -> lastName;
  }

  function fullnameAndEmail() {
    return $this -> firstName . " " . $this -> lastName . " ( " . $this->email . " )";
  }

  function bumpVisits($conf) {
    $this -> visits += 1;
    $time=mktime();
    $message = date ("l dS of F Y h:i:s A", $time) . "\n";
    $query = "UPDATE ContactInfo SET visits='$this->visits', "
      . " note='$message' WHERE contactId='$this->contactId'";
    $result = $conf->qe($query);
  }

  //
  function updateContactRoleInfo($conf)
    {
      $this -> papersSubmitted
      = $conf -> countSubmittedById($this -> contactId);
      $this -> papersFinalized
      = $conf -> countFinalizedById($this -> contactId);
      $this -> isAuthor = ($this -> papersSubmitted > 0);
      $this -> isChair = $conf -> contactIsChair( $this -> contactId);
      $this -> isAssistant = $conf -> contactIsAssistant( $this -> contactId);
      $this -> isPC = $conf -> contactIsPC( $this -> contactId)
	|| $this-> isAssistant;

	if ($conf -> setupPhase) {
	    $this->isChair = 1;
	}
    }

  function amPaperAuthor($paperId, $conf)
    {
      $contactId = $this -> contactId;
      //
      // Query for a specific match of the author and paper
      //
      $query = "SELECT paperId FROM Paper WHERE Paper.contactId=$contactId";
      $result = $conf->qe($query);
      
      if ( DB::isError($result) )  {
	return 0;
      }
      while ($row = $result->fetchRow()) {
	$submittedPaperId = $row[0];
	if ($submittedPaperId == $paperId) {
	  return 1;
	}
      }
      return 0;
    }

  function checkConflict($paperId, $conf)
    {
      return $conf->checkConflict($paperId, $this->contactId);
    }

  function iCanReview($paperId, $conf)
    {
      if ( $conf -> reviewablePaper($paperId)
	   && (
	       $conf -> reviewRequestFor($paperId, $this -> contactId)
	       ||
	       $conf -> primaryReviewerFor($paperId, $this -> contactId)
	       ||
	       $conf -> secondaryReviewerFor($paperId, $this -> contactId)
	       ||
	       ($this->isPC && $conf->validTimeFor('PCReviewAnyPaper', 0))
	       )
	   )
	{
	  return 1;
	} else {
	  return 0;
	}
    }

  //
  //
  function generatePassword($length) {  
    srand((double)microtime()*1000000);  
    $vowels = array("a", "e", "i", "o", "u");  
    $cons = array("b", "c", "d", "g", "h", "j", "k", "l", "m", "n", "p", "r",
		  "s", "t", "u", "v", "w", "tr",  
		  "cr", "br", "fr", "th", "dr", "ch", "ph", "wr", "st", "sp",
		  "sw", "pr", "sl", "cl");  
      
    $num_vowels = count($vowels);  
    $num_cons = count($cons);  
      
    for($i = 0; $i < $length; $i++){  
      $password .= $cons[rand(0, $num_cons - 1)] . $vowels[rand(0, $num_vowels - 1)];  
    }
    return substr($password, 0, $length);  
  }

  function sendAccountInfo($conf)
    {
      $message  = "Someone requested that we send you your account registration information\n";
      $message .= "for the $conf->shortName conference submission site, located at\n";
      $message .= "$conf->paperSite\n";
      $message .= "\n\n";
      $message .= "Your registration indicates your email address is $this->email\n";
      $message .= "and your password is $this->password\n";
      //
      // This was removed for MICRO36, but I think it's very useful. If nothing else,
      // this should be an option
      //
      if ( 1 ) {
	$message .= "\n\n";
	$message .= "Depending on your email client, you may be able to click on this link\n";
	$cleanEmail=htmlspecialchars($this->email);
	$cleanPasswd=htmlspecialchars($this->password);
	$message .= "to login:\n";
	$message .= "$conf->paperSite/All/authAccount.php?loginEmail=$cleanEmail&password=$cleanPasswd\n";
      }

      $message .= "\n\n";
      $message .= "If you did not request your password, you may want to contact\n";
      $message .= "$conf->contactName ($conf->contactEmail) about this matter.\n\n";

      $results = mail($this->email,
		      "Account information for $conf->shortName",
		      $message,
		      "From: $conf->emailFrom");
    }

  //
  // Print all the papers and review links.
  //
  function printReviewables($papersList, $papersTitles, $submit, $conf)
    {
      $paperStatus=array();

      $query="SELECT paperId, finalized FROM PaperReview WHERE reviewer='$this->contactId'";
      $result = $conf->qe($query);
      if (!DB::isError($result)) {
	while ($row = $result->fetchRow()) {
	  if ($row[1]) {
	    $paperStatus[$row[0]] = "Finalized";
	  } else {
	    $paperStatus[$row[0]] = "Started";
	  }
	}
      } else {
	$conf->errorMsg("Error in review query: " . $result->getMessage());
      }
      //
      // Now, print out the papers w/hyper links
      //
      print "<table width=\"100%\" border=1>\n";
      print "<tr> <th width=10%> Paper #</th> <th width=10%> Status </th> <th> Title </th> </tr>\n";
      for($i = 0; $i < sizeof($papersList); $i++) {
	$id = $papersList[$i];
	$title = $papersTitles[$i];
	if (IsSet($paperStatus[$id])) {
	  $status = $paperStatus[$id];
	} else {
	  $status = "Not Started";
	}
	print " <tr> <td> $id </td> <td> $status </td> <td> ";

	$conf->linkWithPaperId($title, $submit, $id);
	
	print "</td> </tr>";
      }
      print "</table>";
    }
}

?>
