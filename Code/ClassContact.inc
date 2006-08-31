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
    var $reviewsOutstanding;
    var $chairContactId;
    var $validated;


    //
    // Initialization functions
    //

    function valid($conf = null, $update = false) {
	global $Conf, $Opt;
	if ($conf == null)
	    $conf = $Conf;
	if (!$this->validated && $this->contactId > 0) {
	    $result = $conf->q("select max(author) as author,
			PaperReview.contactId as reviewer,
			PCMember.contactId as pcMember,
			ChairAssistant.contactId as assistant,
			Chair.contactId as chair,
			min(PaperReview.reviewSubmitted) as minSubmitted
		from ContactInfo
		left join PaperReview using (contactId)
		left join PaperConflict using (contactId)
		left join PCMember using (contactId)
		left join ChairAssistant using (contactId)
		left join Chair using (contactId)
		where ContactInfo.contactId=$this->contactId
		group by ContactInfo.contactId");
	    if (DB::isError($result) || $result->numRows() == 0)
		$this->invalidate();
	    else {
		$this->isAuthor = $this->isReviewer = $this->isPC = $this->isChair = $this->isAssistant = $this->reviewsOutstanding = false;
		while (($row = $result->fetchRow())) {
		    if ($row[0] > 0)
			$this->isAuthor = true;
		    if ($row[1] > 0)
			$this->isReviewer = true;
		    if ($row[2] > 0)
			$this->isPC = true;
		    if ($row[3] > 0)
			$this->isAssistant = true;
		    if ($row[4] > 0)
			$this->isChair = true;
		    if ($row[5] <= 0)
			$this->reviewsOutstanding = true;
		}
		if ($Opt['setupPhase'])
		    $this->isChair = true;
		if ($this->isChair)
		    $this->chairContactId = $this->contactId;
		// change view ID or chair mode
		if ($this->isChair || $this->chairContactId > 0) {
		    $viewContact = cvtint($_REQUEST['viewContact']);
		    if ($viewContact <= 0 && isset($_REQUEST['viewContact']))
			$viewContact = $Conf->getContactId($_REQUEST['viewContact']);
		    $chairMode = cvtint($_REQUEST['chairMode']);
		    if ($viewContact > 0) {
			$this->contactId = $viewContact;
			unset($_REQUEST['viewContact']);
			return $this->valid($conf, true);
		    }
		    if ($chairMode >= 0 && !$this->isChair) {
			$this->contactId = $this->chairContactId;
			return $this->valid($conf, true);
		    }
		}
		$this->validated = true;
		if ($update)
		    $this->lookupById($this->contactId, $conf);
	    }
	} else if (isset($Opt['validatorContact']) && $Opt['validatorContact'] && !$this->contactId && isset($_REQUEST['validator'])) {
	    $this->lookupByEmail($Opt['validatorContact'], $conf);
	    return $this->valid($conf, $update);
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
	
	$this->isAuthor = false;
	$this->isReviewer = false;
	$this->isPC = false;
	$this->isChair = false;
	$this->isAssistant = false;
	$this->reviewsOustanding = false;
	$this->chairContactId = 0;
    }
  
    function countRequestedReviews() {
	global $Conf;
	$query = "select count(*) FROM PaperReview WHERE "
	    . " contactId=" . $this->contactId;
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
	$query = "select count(*) from PaperReview where "
	    . " contactId=" . $this->contactId . " and reviewType=" . REVIEW_PRIMARY;
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
	$query = "select count(*) FROM PaperReview where "
	    . " contactId=" . $this->contactId . " and reviewType=" . REVIEW_SECONDARY;
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
      $this->contactId = trim($this->contactId);
      $this->visits = trim($this->visits);
      $this->firstName = trim($this->firstName);
      $this->lastName = trim($this->lastName);
      $this->email = trim($this->email);
      $this->affiliation = trim($this->affiliation);
      $this->voicePhoneNumber = trim($this->voicePhoneNumber);
      $this->faxPhoneNumber = trim($this->faxPhoneNumber);
      $this->password = trim($this->password);
      $this->note = trim($this->note);
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

    function goIfInvalid($url = null, $string = "") {
	global $ConfSiteBase;
	if (!$this->valid()) {
	    if ($url === null)
		$url = selfHref();
	    $_SESSION["afterLogin"] = $url;
	    $this->goAlert("${ConfSiteBase}login.php", $string ? $string : "You have invalid credentials and need to log in.");
	}
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
		     "This page is only accessible by Program Chair Assistants"
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
	$this->email = trim($email);
	$this->password = $this->generatePassword(10);
	$result = $conf->q(sprintf("insert into ContactInfo (email, password) values ('%s', '%s')", sqlqtrim($this->email), sqlqtrim($this->password)));
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
	    $this->validated = false;
	} else {
	    // Not found - zero out the context
	    $this->invalidate();
	}
	return $result;
    }

    function lookupByEmail($email, $conf) {
	return $this->updateFromQuery(sprintf(
		"select ContactInfo.*, TopicInterest.topicId from ContactInfo
		left join TopicInterest using (contactId)
		where email='%s' limit 0,1", sqlqtrim($email)), $conf);
    }

    function lookupById($contactId, $conf) {
	return $this->updateFromQuery("select ContactInfo.*, TopicInterest.topicId from ContactInfo
		left join TopicInterest using (contactId)
		where ContactInfo.contactId=$contactId limit 0,1", $conf);
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


    // listing permissions

    function canListAllPapers() {
	return $this->amAssistant();
    }
	
    function canListSubmittedPapers() {
	return $this->isPC;
    }

    function canListReviewerPapers() {
	return false;
    }

    function canListAuthoredPapers() {
	return true;
    }


    // viewing permissions

    function _fetchPaperRow($prow, $conf, &$whyNot, $type) {
	if (!is_object($prow))
	    return $conf->paperRow($prow, $this->contactId, $whyNot);
	else {
	    $whyNot = array('paperId' => $prow->paperId);
	    return $prow;
	}
    }

    function amAssistantOverride() {
	return $this->amAssistant() && isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }
    
    function canStartPaper($conf, &$whyNot = null) {
	$whyNot = array();
	if ($this->amAssistantOverride() || $conf->timeStartPaper())
	    return true;
	$whyNot["deadline"] = "startPaperSubmission";
	if ($this->amAssistant())
	    $whyNot["override"] = 1;
	return false;
    }
    
    function canUpdatePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "update")))
	    return false;
	// policy
	if (($prow->author > 0 || $this->amAssistant())
	    && $prow->timeWithdrawn <= 0
	    && ($prow->timeSubmitted <= 0 || $this->amAssistantOverride())
	    && ($conf->timeUpdatePaper() || $this->amAssistantOverride()))
	    return true;
	// collect failure reasons
	if ($prow->author <= 0 && !$this->amAssistant())
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$conf->timeUpdatePaper() && !$this->amAssistantOverride())
	    $whyNot["deadline"] = "updatePaperSubmission";
	if ($this->amAssistant())
	    $whyNot["override"] = 1;
	return false;
    }
    
    function canFinalizePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "submit")))
	    return false;
	// policy
	if (($prow->author > 0 || $this->amAssistant())
	    && $prow->timeWithdrawn <= 0 && $prow->timeSubmitted <= 0
	    && ($conf->timeFinalizePaper() || $this->amAssistantOverride()))
	    return true;
	// collect failure reasons
	if ($prow->author <= 0 && !$this->amAssistant())
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$conf->timeFinalizePaper() && !$this->amAssistantOverride())
	    $whyNot["deadline"] = "finalizePaperSubmission";
	if ($this->amAssistant())
	    $whyNot["override"] = 1;
	return false;
    }
    
    function canWithdrawPaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "withdraw")))
	    return false;
	// policy
	if (($prow->author > 0 || $this->amAssistant())
	    && $prow->timeWithdrawn <= 0)
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->author <= 0 && !$this->amAssistant())
	    $whyNot["author"] = 1;
	return false;
    }
    
    function canRevivePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "revive")))
	    return false;
	// policy
	if (($prow->author > 0 || $this->amAssistant())
	    && $prow->timeWithdrawn > 0
	    && ($conf->timeUpdatePaper() || $this->amAssistantOverride()))
	    return true;
	// collect failure reasons
	if ($prow->author <= 0 && !$this->amAssistant())
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn <= 0)
	    $whyNot["notWithdrawn"] = 1;
	if (!$conf->timeUpdatePaper() && !$this->amAssistantOverride())
	    $whyNot["deadline"] = "updatePaperSubmission";
	if ($this->amAssistant())
	    $whyNot["override"] = 1;
	return false;
    }
    
    function canViewPaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "view")))
	    return false;
	// policy
	if ($prow->author > 0
	    || $this->amAssistant()
	    || ($prow->timeSubmitted > 0
		&& (($this->isPC && $conf->timePCViewSubmission())
		    || ($prow->reviewType > 0 && $conf->timeReviewerViewSubmission()))))
	    return true;
	// collect failure reasons
	if (!$this->isPC && $prow->author <= 0 && $prow->reviewType <= 0) {
	    $whyNot["permission"] = 1;
	    return false;
	}
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	if ($this->isPC && !$conf->timePCViewSubmission())
	    $whyNot["deadline"] = "PCViewSubmission";
	else if ($prow->reviewType > 0 && !$conf->timeReviewerViewSubmission())
	    $whyNot["deadline"] = "ReviewerViewSubmission";
	if ((!$this->isPC && $prow->reviewType <= 0) || count($whyNot) == 1)
	    $whyNot["permission"] = 1;
	return false;
    }
    
    function canViewAuthors($prow, $conf, $blindWins, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "auview")))
	    return false;
	// policy
	if (($prow->author > 0
	     && (!$this->isPC || !$blindWins || !$prow->blind))
	    || ($prow->timeSubmitted > 0
		&& ($this->isPC || $prow->reviewType > 0)
		&& !$prow->blind)
	    || (paperAccepted($prow)
		&& ($this->isPC || $prow->reviewType > 0)
		&& $conf->timeViewAcceptedAuthors()))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($this->isPC || $prow->reviewType > 0)
	    $whyNot["blindSubmission"] = 1;
	else
	    $whyNot["permission"] = 1;
	return false;
    }

    function canViewReview($prow, $rrow, $conf, &$whyNot = null, $type = "revview") {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, $type)))
	    return false;
	// policy
	$rrsub = (!$rrow || $rrow->reviewSubmitted > 0);
	$forceShow = ($this->amAssistant() && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"]);
	if (($prow->author > 0 && $conf->timeAuthorViewReviews() && $rrsub
	     && (!$this->reviewsOutstanding || !$this->isReviewer))
	    || ($this->amAssistant() && ($prow->conflict <= 0 || $forceShow))
	    || ($this->isPC && $prow->conflict <= 0 && $rrsub
		&& ($conf->timePCViewAllReviews()
		    || defval($prow->reviewSubmitted) > 0))
	    || ($prow->reviewType > 0 && $prow->conflict <= 0 && $rrsub
		&& defval($prow->reviewSubmitted) > 0
		&& ($this->isPC || $conf->startTime["reviewerViewReviews"] >= 1))
	    || ($rrow && $rrow->paperId == $prow->paperId
		&& $rrow->contactId == $this->contactId))
	    return true;
	// collect failure reasons
	if ($prow->author <= 0 && !$this->isPC && $prow->reviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->author > 0 && $conf->timeAuthorViewReviews()
		 && $this->reviewsOutstanding && $this->isReviewer)
	    $whyNot['reviewsOutstanding'] = 1;
	else if ($prow->author > 0)
	    $whyNot['deadline'] = 'authorViewReviews';
	else if ($prow->conflict > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->reviewType > 0 && !$this->isPC && $prow->reviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else
	    $whyNot['reviewNotComplete'] = 1;
	if ($this->amAssistant())
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRequestReview($prow, $conf, $time, &$whyNot = null) {
	global $ConfSiteBase;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "rev")))
	    return false;
	// policy
	if (($prow->reviewType >= REVIEW_SECONDARY || $this->amAssistant())
	    && ($conf->timeReviewPaper(false, true, true) || $this->amAssistantOverride() || !$time))
	    return true;
	// collect failure reasons
	if ($prow->reviewType < REVIEW_SECONDARY)
	    $whyNot['permission'] = 1;
	else {
	    $whyNot['deadline'] = ($this->isPC ? "PCSubmitReviewDeadline" : "reviewerSubmitReviewDeadline");
	    if ($this->amAssistant())
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canReview($prow, $rrow, $conf, &$whyNot = null, $submit = false) {
	global $ConfSiteBase;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "rev")))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if ($prow->timeSubmitted > 0
	    && (($this->amAssistant() && ($forceShow || $prow->conflict <= 0))
		|| ($this->isPC && $prow->conflict <= 0)
		|| $prow->reviewType > 0)
	    && ($conf->timeReviewPaper($this->isPC, $prow->reviewType > 0, true)
		|| $this->amAssistantOverride()
		|| ($this->amAssistant() && !$submit))
	    && (!$rrow || $rrow->contactId == $this->contactId || $this->amAssistant()))
	    return true;
	// collect failure reasons
	if ($rrow && $rrow->contactId != $this->contactId && !$this->amAssistant())
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->reviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflict > 0)
		$whyNot['conflict'] = 1;
	    else if ($this->isPC && $prow->reviewType <= 0 && !$conf->timeReviewPaper(true, false, true))
		$whyNot['reviewNotAssigned'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "PCSubmitReviewDeadline" : "reviewerSubmitReviewDeadline");
	    if ($this->amAssistant() && $prow->conflict > 0)
		$whyNot['chairMode'] = 1;
	    if ($this->amAssistant() && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitReview($prow, $rrow, $conf, &$whyNot = null) {
	return $this->canReview($prow, $rrow, $conf, $whyNot, true);
    }


    function canComment($prow, $crow, $conf, &$whyNot = null, $submit = false) {
	global $ConfSiteBase;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "rev")))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if ($prow->timeSubmitted > 0
	    && (($this->amAssistant() && ($forceShow || $prow->conflict <= 0))
		|| ($this->isPC && $prow->conflict <= 0)
		|| $prow->reviewType > 0)
	    && ($conf->timeReviewPaper($this->isPC, true, true)
		|| $this->amAssistantOverride()
		|| ($this->amAssistant() && !$submit))
	    && (!$crow || $crow->contactId == $this->contactId || $this->amAssistant()))
	    return true;
	// collect failure reasons
	if ($crow && $crow->contactId != $this->contactId && !$this->amAssistant())
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->reviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflict > 0)
		$whyNot['conflict'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "PCSubmitReviewDeadline" : "reviewerSubmitReviewDeadline");
	    if ($this->amAssistant() && $prow->conflict > 0)
		$whyNot['chairMode'] = 1;
	    if ($this->amAssistant() && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }
    
    function canSubmitComment($prow, $crow, $conf, &$whyNot = null) {
	return $this->canComment($prow, $crow, $conf, $whyNot, true);
    }

    function canViewComment($prow, $crow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot, "comment")))
	    return false;
	// policy
	$forceShow = ($this->amAssistant() && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"]);
	if (($prow->author > 0 && $conf->timeAuthorViewReviews()
	     && (!$crow || $crow->forAuthors))
	    || ($prow->conflict > 0 && $forceShow)
	    || ($this->amAssistant() && $prow->conflict <= 0)
	    || ($this->isPC && $prow->conflict <= 0
		&& ($conf->timePCViewAllReviews()
		    || !$crow || $crow->forPC))
	    || ($prow->reviewType > 0 && $prow->conflict <= 0
		&& isset($prow->reviewSubmitted) && $prow->reviewSubmitted > 0
		&& (!$crow || $crow->forReviewers)))
	    return true;
	return false;
	// collect failure reasons
	if ($prow->author <= 0 && !$this->isPC && $prow->reviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->author > 0)
	    $whyNot['deadline'] = 'authorViewReviews';
	else if ($prow->conflict > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->reviewType > 0 && !$this->isPC && $prow->reviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else
	    $whyNot['reviewNotComplete'] = 1;
	if ($this->amAssistant())
	    $whyNot['forceShow'] = 1;
	return false;
    }

    
    function amPaperAuthor($paperId, $conf, $prow = null) {
	if ($prow === null) {
	    // Query for a specific match of the author and paper
	    $query = "select paperId from PaperConflict where paperId=$paperId and contactId=$this->contactId and author>0";
	    $result = $conf->qe($query);
	    return !DB::isError($result) && $result->numRows() > 0;
	} else
	    return $prow->author > 0;
    }

    function checkConflict($paperId, $conf) {
	return $conf->checkConflict($paperId, $this->contactId);
    }

    function canManagePaper($prow) {
	return ($prow->author > 0 || $this->amAssistant());
    }
    
    function timeReview($prow, $conf) {
	return $conf->timeReviewPaper($_SESSION['Me']->isPC, $prow->reviewType || $prow->reviewId, true);
    }
    
    function canViewReviewerIdentity($prow, $rrow, $conf) {
	if (($this->amAssistant() && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])
	    || ($rrow && $rrow->contactId == $this->contactId)
	    || ($this->isPC && $prow->author <= 0 && $prow->conflict <= 0)
	    || ($prow->reviewType > 0
		&& isset($prow->reviewSubmitted) && $prow->reviewSubmitted > 0
		&& $conf->startTime["reviewerViewReviews"] >= 2))
	    return true;
	$blind = $conf->blindReview();
	if ($blind > 1
	    || ($blind == 1 && $rrow && $rrow->blind == 0))
	    return true;
	return false;
    }
    
    function canViewCommentIdentity($prow, $crow, $conf) {
	if (($this->amAssistant() && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])
	    || ($crow && $crow->contactId == $this->contactId)
	    || ($this->isPC && $prow->author <= 0 && $prow->conflict <= 0)
	    || ($prow->reviewType > 0
		&& isset($prow->reviewSubmitted) && $prow->reviewSubmitted > 0
		&& $conf->startTime["reviewerViewReviews"] >= 2))
	    return true;
	$blind = $conf->blindReview();
	if ($blind > 1
	    || ($blind == 1 && $rrow && $rrow->blind == 0))
	    return true;
	return false;
    }
    
    function canViewDecision($prow, $conf) {
	if (($prow->author > 0 && $conf->timeAuthorViewDecision())
	    || $this->isPC
	    || ($prow->reviewType > 0
		&& isset($prow->reviewSubmitted) && $prow->reviewSubmitted > 0))
	    return true;
	return false;
    }
    
    function canViewAllReviewFields($prow, $conf) {
	if (($this->amAssistant() && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])
	    || ($prow->author <= 0 && $prow->conflict <= 0 && $this->isPC)
	    || $prow->reviewType > 0)
	    return true;
	return false;
    }

    function canSetOutcome($prow) {
	if (!$this->amAssistant())
	    return false;
	if (($prow->author > 0 || $prow->conflict > 0)
	    && !(isset($_REQUEST['forceShow']) && $_REQUEST['forceShow']))
	    return false;
	return true;
    }
    
    function paperStatus($paperId, $row, $long = 0, $conf = null) {
	global $ConfSiteBase, $Conf;
	$conf = ($conf ? $conf : $Conf);
	if (!$this->amAssistant() && $row->author <= 0)
	    $long = 0;

	if ($row->timeWithdrawn > 0) {
	    $x = "<span class='pstat pstat_with'>Withdrawn</span>";
	    return $x . ($long > 0 ? " at " . $conf->printableTime($row->timeWithdrawn) : '');
	} else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1) {
	    return "<span class='pstat pstat_noup'>Not yet uploaded</span>";
	} else if (isset($row->outcome) && $row->outcome != 0 && $this->canViewDecision($row, $conf)) {
	    $rf = reviewForm();
	    $x =& $rf->options['outcome'][$row->outcome];
	    if (isset($x))
		return "<span class=\"pstat pstat_" . htmlspecialchars($x) . "\">" . htmlspecialchars($x) . "</span>";
	    else
		return "<span class='pstat'>Unknown outcome \"" . htmlspecialchars($row->outcome) . "\"</span>";
	//} else if (isset($row->reviewCount) && $row->reviewCount > 0) {
	//    if ($long < 0 && $row->author <= 0)
	//	return "";
	//    else if ($this->canViewReview($row, null, $conf))
	//	return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	//    else
	//	return "<span class='pstat pstat_rev'>Under&nbsp;review</span>";
	} else {
	    if ($row->timeSubmitted > 0) {
		if ($long < 0)
		    return "";
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
		if ($long > 0)
		    $x .= " at " . $conf->printableTime($row->timeSubmitted);
	    } else {
		$x = "<span class='pstat pstat_prog'>In progress</span>";
		if ($long > 0)
		    $x .= "; updated at " . $conf->printableTime($row->timestamp);
	    }
	    return $x;
	}
    }

  
    function generatePassword($length) {  
	srand((double)microtime()*1000000);  
	$vowels = array("a", "e", "i", "o", "u");  
	$cons = array("b", "c", "d", "g", "h", "j", "k", "l", "m", "n", "p",
		      "r", "s", "t", "u", "v", "w", "tr",  
		      "cr", "br", "fr", "th", "dr", "ch", "ph", "wr", "st",
		      "sp", "sw", "pr", "sl", "cl");  
      
	$num_vowels = count($vowels);  
	$num_cons = count($cons);  

	$password = "";
	for ($i = 0; $i < $length; $i++)
	    $password .= $cons[rand(0, $num_cons - 1)] . $vowels[rand(0, $num_vowels - 1)];  
	return substr($password, 0, $length);  
    }

    function sendAccountInfo($conf, $create) {
	$m = "Greetings,\n\n";
	if ($create)
	    $m .= wordwrap("An account has been created for you at the $conf->shortName conference submission site, and an initial password has been assigned.\n\n");
	else
	    $m .= wordwrap("Here is your account information for the $conf->shortName conference submission site.\n\n");
	$m .= "        Site: $conf->paperSite
       Email: $this->email
    Password: $this->password

Click the link below to log in.  If the link isn't clickable, you may copy
and paste it into your web browser's location field.

    $conf->paperSite/login.php?email=" . urlencode($this->email) . "&password=" . urlencode($this->password) . "\n\n";
	if ($create)
	    $m .= wordwrap("If you already have an account at the $conf->shortName site, you may merge this new account into that one.  Log in to the conference site and select \"Merge accounts\" on the \"Settings\" tab.\n\n");
	$m .= wordwrap("Contact the site administrator, $conf->contactName ($conf->contactEmail), with any questions or concerns.

- $conf->shortName Conference Submissions\n");

	$s = "[$conf->shortName] Account information";
	
	if ($conf->allowEmailTo($this->email))
	    $results = mail($this->email, $s, $m, "From: $conf->emailFrom");
	else
	    $conf->infoMsg("<pre>" . htmlspecialchars("$s\n\n$m") . "</pre>");
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


    function assignPaper($paperId, $row, $reviewer, $type, $conf) {
	global $reviewTypeName;
	if ($type == 0 && $row && ($row->reviewType == REVIEW_REQUESTED || $row->reviewType == REVIEW_PC))
	    return;
	if ($type > 0 && (!$row || !$row->reviewType))
	    $q = "insert into PaperReview (paperId, contactId, reviewType, requestedBy, requestedOn) values ($paperId, $reviewer, $type, $this->contactId, current_timestamp)";
	else if ($type > 0 && $row->reviewType != $type)
	    $q = "update PaperReview set reviewType=$type where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType && !$row->reviewModified)
	    $q = "delete from PaperReview where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType)
	    $q = "update PaperReview set reviewType=" . REVIEW_PC . " where reviewId=$row->reviewId";
	else
	    return;
	
	$result = $conf->qe($q, "while assigning review");
	if (!DB::isError($result))
	    $conf->log("Added $reviewTypeName[$type] reviewer $reviewer for paper $paperId", $this);
    }

}
