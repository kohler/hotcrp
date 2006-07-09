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
    var $isReviewer;
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
	    $result = $conf->q("select max(author) as author,
			count(reviewType) as numReviews,
			PCMember.contactId as pcMember,
			ChairAssistant.contactId as assistant,
			Chair.contactId as chair
		from ContactInfo
		left join ReviewRequest using (contactId)
		left join PaperConflict using (contactId)
		left join PCMember using (contactId)
		left join ChairAssistant using (contactId)
		left join Chair using (contactId)
		where ContactInfo.contactId=$this->contactId
		group by ContactInfo.contactId");
	    if (DB::isError($result) || $result->numRows() == 0)
		$this->invalidate();
	    else {
		$this->isAuthor = $this->isReviewer = $this->isPC = $this->isChair = $this->isAssistant = false;
		while ($row = $result->fetchRow()) {
		    if ($row[0] > 0)
			$this->isAuthor = true;
		    if ($row[1] > 0)
			$this->isReviewer = true;
		    if ($row[2] > 0)
			$this->isPC = true;
		    if ($row[3] > 0)
			$this->isAssistant = $this->isPC = true;
		    if ($row[4] > 0)
			$this->isChair = true;
		}
		if ($conf->setupPhase)
		    $this->isChair = true;
		if (!$this->isChair)
		    $this->chairMode = false;
		else if (isset($_REQUEST['chairMode']))
		    $this->chairMode = (cvtint($_REQUEST['chairMode']) > 0 ? 1 : 0);
		$this->validated = true;
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
	$this->isReviewer = 0;
	$this->isPC = 0;
	$this->isChair = 0;
	$this->isAssistant = 0;
	$this->chairMode = 0;
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
	return $this->isPC || $this->isReviewer;
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
      return $this->lookupByEmail($this->email, $conf);
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
	    $query = "select paperId from PaperConflict where paperId=$paperId and contactId=$this->contactId and author=1";
	    $result = $conf->qe($query);
	    return !DB::isError($result) && $result->numRows() > 0;
	} else
	    return $prow->author > 0;
    }

    function checkConflict($paperId, $conf) {
	return $conf->checkConflict($paperId, $this->contactId);
    }

    function canDownload($paperId, $conf, $prow = null) {
	if ($this->amAssistant())
	    return true;
	if ($prow === null)
	    $prow = $conf->getPaperRow($paperId, $this->contactId);
	if ($prow->author > 0 || $prow->reviewType > 0)
	    return true;
	return $this->isPC && $prow->acknowledged > 0 && $prow->withdrawn <= 0;
    }

    function canReview($paperId, $conf, $prow = null, &$error = null) {
	global $ConfSiteBase;
	if ($prow === null)
	    $prow = $conf->getPaperRow($paperId, $this->contactId);
	if ($prow->reviewType > 0)
	    return true;
	else if (!$this->isPC || (!$this->amAssistant() && !$conf->canPCReviewAnyPaper())) {
	    $error = "You are not a reviewer for paper #$paperId.";
	    return false;
	} else if ($prow->author > 0) {
	    $error = "You are an author of paper #$paperId and cannot review it.";
	    return false;
	} else if ($prow->conflict > 0) {
	    $error = "You have a conflict with paper #$paperId and cannot review it.";
	    return false;
	} else if ($prow->withdrawn > 0 || $prow->acknowledged <= 0) {
	    $error = "Paper #$paperId was " . ($prow->withdrawn > 0 ? "withdrawn" : "never submitted") . ", so you cannot review it.";
	    if ($this->amAssistant())
		$error .= "  As PC Chair you can <a href='${ConfSiteBase}Author/ManagePaper.php?paperId=$paperId'>revive and submit</a> the paper, if you want; then it will be available for review.";
	    return false;
	} else
	    return true;
    }

    function timeReview($prow, $conf) {
	return $conf->timeReviewPaper($_SESSION['Me']->isPC, $prow->reviewType || $prow->reviewId, true);
    }
    
    function canViewAuthors($prow, $conf) {
	if ($prow->author > 0 || (!$conf->blindSubmission && $prow->acknowledged > 0 && $prow->withdrawn <= 0))
	    return true;
	if (paperAccepted($prow) && $conf->validPeriod('EndOfTheMeeting'))
	    return true;
	if ($this->amAssistant() && $this->chairMode)
	    return true;
	return false;
    }

    function canViewReviews($prow, $conf) {
	if ($prow->author > 0 && $conf->timeAuthorViewReviews())
	    return true;
	if ($prow->author <= 0 && $prow->conflict <= 0 && $this->isPC)
	    return true;
	if ($this->amAssistant() && $this->chairMode)
	    return true;
	if (isset($prow->reviewType) && $prow->reviewType > 0)
	    return true;
	return false;
    }
    function canViewReviewerIdentity($rrow, $prow, $conf) {
	if ($this->amAssistant() && $this->chairMode)
	    return true;
	if ($rrow->contactId == $this->contactId)
	    return true;
	if ($prow->author <= 0 && $prow->conflict <= 0 && $this->isPC)
	    return true;
	return false;
    }
    function canViewDecision($prow, $conf) {
	if ($prow->author > 0 && $conf->timeAuthorViewDecision())
	    return true;
	if ($this->isPC)
	    return true;
	return false;
    }
    
    function canViewAllReviewFields($prow, $conf) {
	if ($this->amAssistant() && $this->chairMode)
	    return true;
	if ($prow->author <= 0 && $prow->conflict <= 0 && $this->isPC)
	    return true;
	return false;
    }

    function canSetOutcome($prow) {
	if (!$this->amAssistant())
	    return false;
	if (($prow->author > 0 || $prow->conflict > 0) && !$this->chairMode)
	    return false;
	return true;
    }
    
    function paperStatus($paperId, $row, $long = 0, $conf = null) {
	global $ConfSiteBase, $Conf;
	$conf = ($conf ? $conf : $Conf);

	if ($row->withdrawn > 0) {
	    $x = "<span class='pstat pstat_with'>Withdrawn</span>";
	    return $x . ($long > 0 ? " at " . $conf->printTime($row->withdrawn) : '');
	} else if ($row->acknowledged <= 0 && $row->paperStorageId == 1) {
	    return "<span class='pstat pstat_noup'>Not yet uploaded</span>";
	} else if (isset($row->outcome) && $row->outcome != 0 && $this->canViewDecision($row, $conf)) {
	    $rf = reviewForm();
	    $x =& $rf->options['outcome'][$row->outcome];
	    if (isset($x))
		return "<span class=\"pstat pstat_" . htmlspecialchars($x) . "\">" . htmlspecialchars($x) . "</span>";
	    else
		return "<span class='pstat'>Unknown outcome \"" . htmlspecialchars($row->outcome) . "\"</span>";
	} else if (isset($row->reviewCount) && $row->reviewCount > 0) {
	    if ($long < 0 && $row->author <= 0)
		return "";
	    else if ($this->canViewReviews($row, $conf))
		return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	    else
		return "<span class='pstat pstat_rev'>Under&nbsp;review</span>";
	} else {
	    if ($row->acknowledged > 0) {
		if ($long < 0)
		    return "";
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
		if ($long > 0)
		    $x .= " at " . $conf->printTime($row->acknowledged);
	    } else {
		$x = "<span class='pstat pstat_prog'>In progress</span>";
		if ($long > 0)
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

      $query="SELECT paperId, reviewSubmitted FROM PaperReview WHERE contactId='$this->contactId'";
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
