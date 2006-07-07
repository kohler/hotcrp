<?php 
//
// Configuration for a Contact (i.e. someone using the reviewing system)
//

class Contact {
    // Which conference are we dealing with?
    var $shortConfName;

    // Information from the SQL definition
    var $contactId;
    var $visits;
    var $firstName;
    var $lastName;
    var $email;
    var $affiliation;
    var $collaborators;
    var $voicePhoneNumber;
    var $faxPhoneNumber;
    var $password;
    var $note;
    var $anyTopicInterest;

    // Roles
    var $isAuthor;
    var $isPC;
    var $isChair;
    var $isAssistant;
    var $chairMode;
    var $validated;


    //
    // Initialization functions
    //

    function valid($conf = null) {
	global $Conf;
	if ($conf == null)
	    $conf = $Conf;
	if (!$this->validated && $this->contactId > 0) {
	    $result = $conf->q("select role from Roles, ContactInfo where Roles.contactId = $this->contactId and ContactInfo.contactId = $this->contactId");
	    if (DB::isError($result) || $result->numRows() == 0)
		$this->invalidate();
	    else {
		$this->isAuthor = $this->isPC = $this->isChair = $this->isAssistant = 0;
		while ($row = $result->fetchRow()) {
		    if ($row[0] == ROLE_AUTHOR)
			$this->isAuthor = 1;
		    if ($row[0] == ROLE_PC)
			$this->isPC = 1;
		    if ($row[0] == ROLE_CHAIR)
			$this->isChair = 1;
		    if ($row[0] == ROLE_ASSISTANT)
			$this->isAssistant = $this->isPC = 1;
		}
		if ($conf->setupPhase)
		    $this->isChair = 1;
		if (!$this->isChair)
		    $this->chairMode = 0;
		else if (isset($_REQUEST['chairMode']))
		    $this->chairMode = (cvtint($_REQUEST['chairMode']) > 0 ? 1 : 0);
		$this->validated = 1;
	    }
	}
	return $this->contactId > 0 && $conf->shortName == $this->shortConfName;
    }

    function invalidate() {
	$this->confShortName = 0;

	$this->contactId = 0;
	$this->visits = 0;
	$this->firstName = '';
	$this->lastName = '';
	$this->email = '';
	$this->affiliation = '';
	$this->voicePhoneNumber = '';
	$this->faxPhoneNumber = '';
	$this->password = '';
	$this->note = 0;
	$this->collaborators = '';
	$this->anyTopicInterest = 0;
	
	$this->isAuthor = 0;
	$this->isPC = 0;
	$this->isChair = 0;
	$this->isAssistant = 0;
    }
  
  function countRequestedReviews() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM ReviewRequest WHERE "
      . " asked=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result->numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function countRequestedPrimary() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM PrimaryReviewer WHERE "
      . " contactId=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result->numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function countRequestedSecondary() {
    global $Conf;
    $query = "SELECT COUNT(*) FROM SecondaryReviewer WHERE "
      . " contactId=' " . $this->contactId . "'";
    $result = $Conf->q($query);
    if ( !DB::isError($result) && $result->numRows() == 1 ) {
      $row = $result->fetchRow();
      return $row[0];
    } else {
      return 0;
    }
  }

  function amAssistant() {
      return $this->isAssistant || $this->isChair;
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

    if ($_SESSION["Me"]->isPC && $Conf->validTimeFor('PCSubmitReview', 0)) {
	$pcreviews = $this->countPrimaryReviews()
	    + $this->countSecondaryReviews();
	$reviewer |= $pcreviews > 0;
    }
    return $reviewer;
  }

  function dump() {
      echo "<table border=\"1\" width=\"83%\" bgcolor=$bgOne>";
      echo "<tr> <td>";

      echo "<p>";
      echo "contactId = " . $this->contactId . "<br>";
      echo "visits = " . $this->visits . "<br>";
      echo "firstName = " . $this->firstName . "<br>";
      echo "lastName = " . $this->lastName . "<br>";
      echo "email = " . $this->email . "<br>";
      echo "affiliation = " . $this->affiliation . "<br>";
      echo "voicePhoneNumber = " . $this->voicePhoneNumber . "<br>";
      echo "faxPhoneNumber = " . $this->faxPhoneNumber . "<br>";
      echo "password = " . $this->password . "<br>";
      echo "note = " . $this->note . "<br>";
      echo "isAuthor = " . $this->isAuthor . "<br>";
      echo "isPC = " . $this->isPC . "<br>";
      echo "isChair = " . $this->isChair . "<br>";
      echo "isAssistant = " . $this->isAssistant . "<br>";
      echo "</p> </td> </tr> </table>";
  }

  function trim() {
      $this->contactId = ltrim(rtrim($this->contactId));
      $this->visits = ltrim(rtrim($this->visits));
      $this->firstName = ltrim(rtrim($this->firstName));
      $this->lastName = ltrim(rtrim($this->lastName));
      $this->email = ltrim(rtrim($this->email));
      $this->affiliation = ltrim(rtrim($this->affiliation));
      $this->voicePhoneNumber = ltrim(rtrim($this->voicePhoneNumber));
      $this->faxPhoneNumber = ltrim(rtrim($this->faxPhoneNumber));
      $this->password = ltrim(rtrim($this->password));
      $this->note = ltrim(rtrim($this->note));
  }

  function go($url) {
      go($url);
  }

  function alert($string = "") {
      alert($string);
  }

  function goAlert($url, $message = "") {
      global $Conf;
      $Conf->errorMsg($message);
      go($url);
  }

  function goIfInvalid($url, $string = "") {
      if (!$this->valid())
	  $this->goAlert($url, $string ? $string : "You have invalid credentials and need to log in.");
  }

  function goIfNotAuthor($url) {
      if (!$this->valid() || !$this->isAuthor)
	  $this->goAlert($url, "That page is only accessible to paper authors.");
  }

  function goIfNotPC($url) {
    if ( $this->valid() && ($this->isChair || $this->isPC)) {
      return;
    } else {
      $this->goAlert($url,
		     "This page is only accessible by Program Committee members"
		     );
    }
  }

  function goIfNotChair($url) {
    if (!$this->valid() || ! $this->isChair ) {
      $this->goAlert($url,
		     "This page is only accessible by Program Chairs"
		     );
    }
  }

  function goIfNotAssistant($url) {
    if ( $this->valid() && ($this->isChair || $this->isAssistant)) {
      return;
    } else {
      $this->goAlert($url,
		     "This page is only accessible by Program Chair Assitants"
		     );
    }
  }

  function updateDB($conf, $where = "") {
      $this->trim();
      $query = sprintf("update ContactInfo set firstName='%s', lastName='%s',
		email='%s', affiliation='%s', voicePhoneNumber='%s',
		faxPhoneNumber='%s', password='%s', collaborators='%s'
		where contactId='%s'",
		       sqlqtrim($this->firstName), sqlqtrim($this->lastName),
		       sqlqtrim($this->email), sqlqtrim($this->affiliation),
		       sqlqtrim($this->voicePhoneNumber),
		       sqlqtrim($this->faxPhoneNumber),
		       sqlqtrim($this->password),
		       sqlqtrim($this->collaborators),
		       $this->contactId);
      return $conf->qe($query, $where);
  }

  function initialize($email, $conf) {
      $this->email = ltrim(rtrim($email));
      $this->password = $this->generatePassword(10);
      $result = $conf->q(sprintf("insert into ContactInfo set email='%s', password='%s'", sqlqtrim($this->email), sqlqtrim($this->password)));
      if (DB::isError($result))
	  return $result;
      $result = $this->lookupByEmail($this->email, $conf);
      if (DB::isError($result))
	  return $result;
      return $conf->q("insert into Roles set contactId=$this->contactId, role=" . ROLE_EXISTS);
  }

  function updateFromQuery($query, $conf) {
      $result = $conf->q($query);
      if (!DB::isError($result) && $result->numRows() >= 1) {
	  $fromdb = $result->fetchRow(DB_FETCHMODE_OBJECT);
	  
	  $this->contactId = $fromdb->contactId;
	  $this->visits = $fromdb->visits;
	  $this->firstName = $fromdb->firstName;
	  $this->lastName = $fromdb->lastName;
	  $this->email = $fromdb->email;
	  $this->affiliation = $fromdb->affiliation;
	  $this->voicePhoneNumber = $fromdb->voicePhoneNumber;
	  $this->faxPhoneNumber = $fromdb->faxPhoneNumber;
	  $this->password = $fromdb->password;
	  $this->note = $fromdb->note;
	  $this->collaborators = $fromdb->collaborators;
	  $this->anyTopicInterest = isset($fromdb->topicId);

	  $this->shortConfName = $conf->shortName;

	  $this->trim();
      } else {
	  // Not found - zero out the context
	  $this->invalidate();
      }
      return $result;
  }

  function lookupByEmail($email, $conf) {
      return $this->updateFromQuery(sprintf(
		"select ContactInfo.*, TopicInterest.topicId from ContactInfo
		left join TopicInterest on ContactInfo.contactId=TopicInterest.contactId
		where email='%s' limit 0,1", sqlqtrim($email)), $conf);
  }

  function lookupById($contactId, $conf) {
      return $this->updateFromQuery(
		"select ContactInfo.*, TopicInterest.topicId from ContactInfo
		left join TopicInterest on ContactInfo.contactId=TopicInterest.contactId
		where contactId=$contactId limit 0,1", $conf);
  }

  function fullname() {
      if ($this->firstName && $this->lastName)
	  return $this->firstName . " " . $this->lastName;
      else if ($this->firstName || $this->lastName)
	  return $this->firstName . $this->lastName;
      else
	  return "";
  }

  function fullnameAndEmail() {
      $n = $this->fullname();
      return ($n ? $n . " (" . $this->email . ")" : $this->email);
  }
  
  function bumpVisits($conf) {
      $this->visits += 1;
      $time=mktime();
      $message = date ("l dS of F Y h:i:s A", $time) . "\n";
      $query = "UPDATE ContactInfo SET visits='$this->visits', "
	  . " note='$message' WHERE contactId='$this->contactId'";
      $result = $conf->qe($query);
  }

    function amPaperAuthor($paperId, $conf, $prow = null) {
	if ($prow === null) {
	    // Query for a specific match of the author and paper
	    $query = "select paperId from Roles where paperId=$paperId and contactId=$this->contactId";
	    $result = $conf->qe($query);
	    return !DB::isError($result) && $result->numRows() > 0;
	} else
	    return $prow->myMinRole == ROLE_AUTHOR;
    }

    function checkConflict($paperId, $conf) {
	return $conf->checkConflict($paperId, $this->contactId);
    }

    function canDownload($paperId, $conf, $prow = null) {
	if ($this->amAssistant())
	    return true;
	if ($prow === null)
	    $prow = $conf->getPaperRow($paperId, $this->contactId);
	if ($prow->myMinRole == ROLE_AUTHOR || $prow->myReviewType > 0)
	    return true;
	return $this->isPC && $prow->acknowledged > 0 && $prow->withdrawn <= 0;
    }

    function canReview($paperId, $conf, $prow = null) {
	if (($this->isPC && $conf->validTimeFor('PCReviewAnyPaper', 0))
	    || $this->amAssistant())
	    return true;
	if ($prow === null)
	    $prow = $conf->getPaperRow($paperId, $this->contactId);
	return $prow->myReviewType > 0;
    }

    function paperStatus($paperId, $row, $knownAuthor, $long = 0, $conf = null) {
	global $ConfSiteBase, $Conf;
	if ($conf == null)
	    $conf = $Conf;

	if ($knownAuthor) {
	    $canViewReviews = $conf->canAuthorViewReviews();
	    $canViewDecision = $conf->canAuthorViewDecision();
	} else if ($this->amAssistant()
		   || ($this->isPC && (!isset($row->conflictCount) || !$row->conflictCount)))
	    $canViewReviews = $canViewDecision = 1;
	else
	    $canViewReviews = $canViewDecision = 0;

	if ($row->withdrawn > 0) {
	    $x = "<span class='pstat pstat_with'>Withdrawn</span>";
	    return $x . ($long ? " at " . $conf->printTime($row->withdrawn) : '');
	} else if ($row->acknowledged <= 0 && $row->paperStorageId == 1)
	    return "<span class='pstat pstat_noup'>Not yet uploaded</span>";
	else if (isset($row->outcome) && $row->outcome != 'unspecified' && $canViewDecision) {
	    if ($row->outcome == "accepted")
		return "<span class='pstat pstat_acc'>Accepted</span>";
	    else if ($row->outcome == "acceptedShort")
		return "<span class='pstat pstat_accsh'>Accepted as short paper</span>";
	    else if ($row->outcome == "rejected")
		return "<span class='pstat pstat_rej'>Rejected</span>";
	    else
		return "<span class='pstat'>Unknown outcome \"" . htmlspecialchars($row->outcome) . "\"</span>";
	} else if (isset($row->reviewCount) && $row->reviewCount > 0 && $canViewReviews) {
	    return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	} else {
	    if ($row->acknowledged > 0) {
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
		if ($long)
		    $x .= " at " . $conf->printTime($row->acknowledged);
	    } else {
		$x = "<span class='pstat pstat_prog'>In progress</span>";
		if ($long)
		    $x .= "; updated at " . $conf->printTime($row->timestamp);
	    }
	    return $x;
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

      $password = "";
      for ($i = 0; $i < $length; $i++) {
	  $password .= $cons[rand(0, $num_cons - 1)] . $vowels[rand(0, $num_vowels - 1)];  
      }
      return substr($password, 0, $length);  
  }

  function sendAccountInfo($conf) {
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

      if ($conf->allowEmail)
	  $results = mail($this->email,
			  "[$conf->confShortName] Account Information",
			  $message,
			  "From: $conf->emailFrom");
  }

  //
  // Print all the papers and review links.
  //
  function printReviewables($papersList, $papersTitles, $submit, $conf)
    {
      $paperStatus=array();

      $query="SELECT paperId, finalized FROM PaperReview WHERE contactId='$this->contactId'";
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
