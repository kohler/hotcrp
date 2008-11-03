<?php
// contact.inc -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

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
    var $defaultWatch;

    // Address information (loaded separately)
    var $addressLine1;
    var $addressLine2;
    var $city;
    var $state;
    var $zipCode;
    var $country;

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    var $isAuthor;
    var $isReviewer;
    var $isRequester;
    var $isDiscussionLead;
    var $roles;
    var $isPC;
    var $privChair;
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
	    $qr = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qr = " or PaperReview.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
	    $qa = "select max(conflictType),
		PaperReview.contactId as reviewer,
		max(PaperReview.reviewNeedsSubmit) as reviewNeedsSubmit,
		count(ExtPaperReview.requestedBy) as requester";
	    $qb = "left join PaperReview on (PaperReview.contactId=ContactInfo.contactId$qr)
		left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId)
		left join PaperReview as ExtPaperReview on (ExtPaperReview.requestedBy=ContactInfo.contactId)";
	    if ($conf->setting("allowPaperOption") >= 6)
		$qa .= ",
		ContactInfo.roles, 0, 0, 0";
	    else {
		$qa .= ",
		0, PCMember.contactId as isPC,
		ChairAssistant.contactId as isAssistant,
		Chair.contactId as isChair";
		$qb .= "
		left join PCMember on (PCMember.contactId=ContactInfo.contactId)
		left join ChairAssistant on (ChairAssistant.contactId=ContactInfo.contactId)
		left join Chair on (Chair.contactId=ContactInfo.contactId)";
	    }
	    $result = $conf->qe("$qa from ContactInfo $qb where ContactInfo.contactId=$this->contactId group by ContactInfo.contactId");
	    if (edb_nrows($result) == 0)
		$this->invalidate();
	    else {
		$this->roles = 0;
		$this->isAuthor = $this->isReviewer = $this->isRequester = $this->isPC = $this->privChair = $this->reviewsOutstanding = false;
		$this->isDiscussionLead = null;
		while (($row = edb_row($result))) {
		    if ($row[0] >= CONFLICT_AUTHOR)
			$this->isAuthor = true;
		    if ($row[1] > 0)
			$this->isReviewer = true;
		    if ($row[2] > 0)
			$this->reviewsOutstanding = true;
		    if ($row[3] > 0)
			$this->isRequester = true;
		    if (($row[4] & self::ROLE_PC) || $row[5] > 0)
			$this->roles |= self::ROLE_PC;
		    if (($row[4] & self::ROLE_ADMIN) || $row[6] > 0)
			$this->roles |= self::ROLE_ADMIN;
		    if (($row[4] & self::ROLE_CHAIR) || $row[7] > 0)
			$this->roles |= self::ROLE_CHAIR;
		}
		$this->isPC = ($this->roles & (self::ROLE_PC | self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
		$this->privChair = ($this->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
		if ($this->privChair)
		    $this->chairContactId = $this->contactId;
		// change view ID or chair mode
		if ($this->privChair || $this->chairContactId > 0) {
		    $viewContact = rcvtint($_REQUEST["viewContact"]);
		    if ($viewContact <= 0 && isset($_REQUEST["viewContact"]))
			$viewContact = $Conf->getContactId($_REQUEST["viewContact"]);
		    $chairMode = rcvtint($_REQUEST["chairMode"]);
		    if ($viewContact > 0) {
			$this->contactId = $viewContact;
			unset($_REQUEST["viewContact"]);
			unset($_SESSION["l"]);
			return $this->valid($conf, true);
		    }
		    if ($chairMode >= 0 && !$this->privChair) {
			$this->contactId = $this->chairContactId;
			return $this->valid($conf, true);
		    }
		}
		if ($update)
		    $this->lookupById($this->contactId, $conf);
		$this->validated = true;
	    }
	} else if (isset($Opt['validatorContact']) && $Opt['validatorContact'] && !$this->contactId && isset($_REQUEST['validator'])) {
	    $this->lookupByEmail($Opt['validatorContact'], $conf);
	    return $this->valid($conf, $update);
	}
	return $this->contactId > 0 && $conf->shortName == $this->shortConfName;
    }

    function _addressKeys() {
	return array("addressLine1", "addressLine2", "city", "state",
		     "zipCode", "country");
    }

    function invalidate() {
	$this->shortConfName = 0;

	$this->contactId = 0;
	$this->visits = 0;
	foreach (array("firstName", "lastName", "email", "affiliation",
		       "voicePhoneNumber", "faxPhoneNumber", "password",
		       "collaborators") as $k)
	    $this->$k = '';
	foreach (self::_addressKeys() as $k)
	    $this->$k = null;
	$this->note = 0;
	$this->defaultWatch = 0;

	$this->roles = 0;
	$this->isAuthor = $this->isReviewer = $this->isPC = $this->privChair = false;
	$this->reviewsOustanding = false;
	$this->chairContactId = 0;
    }

    function amReviewer() {
	return $this->isPC || $this->isReviewer;
    }

    function trim() {
	$this->contactId = trim($this->contactId);
	$this->visits = trim($this->visits);
	$this->firstName = simplifyWhitespace($this->firstName);
	$this->lastName = simplifyWhitespace($this->lastName);
	foreach (array("email", "affiliation", "voicePhoneNumber",
		       "faxPhoneNumber", "password", "note", "addressLine1",
		       "addressLine2", "city", "state", "zipCode", "country")
		 as $k)
	    if ($this->$k)
		$this->$k = trim($this->$k);
    }

    function go($url) {
	go($url);
    }

    function alert($string = "") {
	alert($string);
    }

    function goAlert($url, $message = "") {
	global $Conf, $ConfSiteSuffix;
	$Conf->errorMsg($message);
	if ($url === false) {
	    $url = "index" . $ConfSiteSuffix;
	    if (preg_match("|/doc(\.php)?/|", $_SERVER["PHP_SELF"]))
		$url = "../" . $url;
	}
	go($url);
    }

    function goIfInvalid() {
	if (!$this->valid()) {
	    $x = array("afterLogin" => 1, "blind" => 1);
	    $rf = reviewForm();
	    foreach ($rf->reviewFields as $k => $v)
		if (isset($_REQUEST[$k]))
		    $x[$k] = $_REQUEST[$k];
	    $_SESSION["afterLogin"] = selfHref($x);
	    $this->goAlert(false, "You have invalid credentials and need to sign in.");
	}
    }

    function goIfNotPC() {
	if (!$this->valid() || !($this->privChair || $this->isPC))
	    $this->goAlert(false, "That page is only accessible to program committee members.");
    }

    function goIfNotPrivChair() {
	if (!$this->valid() || !$this->privChair)
	    $this->goAlert(false, "That page is only accessible to conference administrators.");
    }

    function updateDB($conf, $where = "") {
	$this->trim();
	$qa = "";
	if ($conf->setting("allowPaperOption") >= 6)
	    $qa = ", roles='$this->roles', defaultWatch='$this->defaultWatch'";
	$query = sprintf("update ContactInfo set firstName='%s', lastName='%s',
		email='%s', affiliation='%s', voicePhoneNumber='%s',
		faxPhoneNumber='%s', password='%s', collaborators='%s'$qa
		where contactId='%s'",
			 sqlq($this->firstName), sqlq($this->lastName),
			 sqlq($this->email), sqlq($this->affiliation),
			 sqlq($this->voicePhoneNumber),
			 sqlq($this->faxPhoneNumber),
			 sqlq($this->password),
			 sqlq($this->collaborators),
			 $this->contactId);
	$result = $conf->qe($query, $where);
	if (!$result || $conf->setting("allowPaperOption") < 5)
	    return $result;
	$conf->qx("delete from ContactAddress where contactId=$this->contactId");
	if ($this->addressLine1 || $this->addressLine2 || $this->city
	    || $this->state || $this->zipCode || $this->country) {
	    $query = "insert into ContactAddress (contactId, addressLine1, addressLine2, city, state, zipCode, country) values ($this->contactId, '" . sqlq($this->addressLine1) . "', '" . sqlq($this->addressLine2) . "', '" . sqlq($this->city) . "', '" . sqlq($this->state) . "', '" . sqlq($this->zipCode) . "', '" . sqlq($this->country) . "')";
	    $result = $conf->qe($query, $where);
	}
	return $result;
    }

    function initialize($email, $conf) {
	$this->email = trim($email);
	$this->password = $this->generatePassword(10);
	$qa = "email, password";
	$qb = "'" . sqlq($this->email) . "', '" . sqlq($this->password) . "'";
	if ($conf->setting("allowPaperOption") >= 4) {
	    $qa .= ", creationTime";
	    $qb .= ", " . time();
	}
	$result = $conf->q("insert into ContactInfo ($qa) values ($qb)");
	if (!$result)
	    return $result;
	$contactId = $conf->lastInsertId("while creating contact");
	if (!$contactId)
	    return false;

	// update paper authorship based on authorInformation fields
	// (ignore errors)
	$addAuthor = array();
	$result = $conf->q("select paperId, authorInformation from Paper where authorInformation like '%	" . sqlq_for_like($this->email) . "	%'");
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au)
		if (strcasecmp($au[2], $this->email) == 0) {
		    $addAuthor[] = "($row->paperId, $contactId, " . CONFLICT_AUTHOR . ")";
		    break;
		}
	}
	if (count($addAuthor))
	    $conf->q("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $addAuthor) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");

	return $this->lookupByEmail($this->email, $conf);
    }

    function updateFromQuery($query, $conf) {
	$result = $conf->q($query);
	if (edb_nrows($result) >= 1) {
	    $fromdb = edb_orow($result);

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
	    $this->defaultWatch = defval($fromdb, "defaultWatch", 0);

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
	return $this->updateFromQuery("select ContactInfo.* from ContactInfo
		where email='" . sqlqtrim($email) . "'", $conf);
    }

    function lookupById($contactId, $conf) {
	return $this->updateFromQuery("select ContactInfo.* from ContactInfo
		where ContactInfo.contactId=$contactId", $conf);
    }

    function lookupAddress($conf) {
	if ($conf->setting("allowPaperOption") >= 5) {
	    $result = $conf->qx("select * from ContactAddress where contactId=$this->contactId");
	    foreach (self::_addressKeys() as $k)
		$this->$k = null;
	    if (($row = edb_orow($result)))
		foreach (self::_addressKeys() as $k)
		    $this->$k = $row->$k;
	}
    }

    function makeMinicontact($o) {
	// If you change this function, search for its callers to ensure
	// they provide all necessary information.
	$c = new Contact();
	$c->invalidate();
	$c->contactId = $o->contactId;
	$c->firstName = defval($o, "firstName", '');
	$c->lastName = defval($o, "lastName", '');
	$c->email = defval($o, "email", '');
	$c->password = defval($o, "password", '');
	$c->isAuthor = defval($o, "isAuthor", false) != 0;
	$c->isReviewer = defval($o, "isReviewer", false) != 0;
	$c->roles = defval($o, "roles", 0);
	if (defval($o, "isPC", false))
	    $c->roles |= self::ROLE_PC;
	if (defval($o, "isAssistant", false))
	    $c->roles |= self::ROLE_ADMIN;
	if (defval($o, "isChair", false))
	    $c->roles |= self::ROLE_CHAIR;
	$c->isPC = ($c->roles & (self::ROLE_PC | self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
	$c->privChair = ($c->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
	return $c;
    }


    // viewing permissions

    function _fetchPaperRow($prow, $conf, &$whyNot) {
	if (!is_object($prow))
	    return $conf->paperRow($prow, $this->contactId, $whyNot);
	else {
	    $whyNot = array('paperId' => $prow->paperId);
	    return $prow;
	}
    }

    function privChairOverride() {
	return $this->privChair && isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    function canStartPaper($conf, &$whyNot = null) {
	$whyNot = array();
	if ($this->privChairOverride() || $conf->timeStartPaper())
	    return true;
	$whyNot["deadline"] = "sub_reg";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canEditPaper($prow, $conf, &$whyNot = null) {
	return ($prow->conflictType >= CONFLICT_AUTHOR
		|| $this->privChair);
    }

    function canUpdatePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0
	    && ($conf->timeUpdatePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0 && $conf->setting('sub_freeze') > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$conf->timeUpdatePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "sub_update";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canFinalizePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0
	    && ($conf->timeFinalizePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$conf->timeFinalizePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "finalizePaperSubmission";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canWithdrawPaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0)
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	return false;
    }

    function canRevivePaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn > 0
	    && ($conf->timeUpdatePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn <= 0)
	    $whyNot["notWithdrawn"] = 1;
	if (!$conf->timeUpdatePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "sub_update";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canSubmitFinalPaper($prow, $conf, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0 && $prow->outcome > 0
	    && ($conf->timeSubmitFinalPaper() || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->outcome <= 0)
	    $whyNot["notAccepted"] = 1;
	if (!$conf->timeSubmitFinalPaper() && !$this->privChairOverride())
	    $whyNot["deadline"] = "final_done";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canViewPaper($prow, $conf, &$whyNot = null, $download = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if ($prow->conflictType >= CONFLICT_AUTHOR
	    || $this->privChair
	    || ($prow->myReviewType > 0 && $conf->timeReviewerViewSubmittedPaper())
	    || ($this->isPC && $conf->timePCViewPaper($prow, $download)))
	    return true;
	// collect failure reasons
	if (!$this->isPC && $prow->conflictType < CONFLICT_AUTHOR && $prow->myReviewType <= 0) {
	    $whyNot["permission"] = 1;
	    return false;
	}
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	if ($this->isPC && !$conf->timePCViewPaper($prow, $download))
	    $whyNot["deadline"] = "sub_sub";
	else if ($prow->myReviewType > 0 && !$conf->timeReviewerViewSubmittedPaper())
	    $whyNot["deadline"] = "sub_sub";
	if ((!$this->isPC && $prow->myReviewType <= 0) || count($whyNot) == 1)
	    $whyNot["permission"] = 1;
	return false;
    }

    function canDownloadPaper($prow, $conf, &$whyNot = null) {
	return $this->canViewPaper($prow, $conf, $whyNot, true);
    }

    function canViewAuthors($prow, $conf, $blindWins, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	$pblind = paperBlind($prow);
	if (($prow->conflictType >= CONFLICT_AUTHOR
	     && (!$this->isPC || !$blindWins || !$pblind))
	    || ($prow->myReviewType > 0 && !$pblind
		&& $conf->timeReviewerViewSubmittedPaper())
	    || ($prow->timeSubmitted > 0 && !$pblind && $this->isPC)
	    || ($prow->outcome > 0
		&& ($this->isPC || $prow->myReviewType > 0)
		&& $conf->timeReviewerViewAcceptedAuthors())
	    || ($this->isPC && !$pblind && $prow->timeWithdrawn <= 0
		&& $conf->setting("pc_seeall") > 0))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($this->isPC || $prow->myReviewType > 0)
	    $whyNot["blindSubmission"] = 1;
	else
	    $whyNot["permission"] = 1;
	return false;
    }

    function ownReview($rrow) {
	return $rrow->contactId == $this->contactId
	    || (isset($_SESSION["rev_tokens"]) && array_search($rrow->reviewToken, $_SESSION["rev_tokens"]) !== false);
    }

    function canViewReview($prow, $rrow, $conf, &$whyNot = null, $ignoreForceShow = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
	if ($this->privChair && $forceShow)
	    return true;
	if (($prow->timeSubmitted > 0
	     || defval($prow, "myReviewType") > 0)
	    && (($prow->conflictType >= CONFLICT_AUTHOR
		 && $conf->timeAuthorViewReviews($this->reviewsOutstanding && $this->isReviewer)
		 && $rrowSubmitted)
		|| ($this->privChair && $prow->conflictType == 0)
		|| ($this->isPC
		    && $prow->conflictType == 0 && $rrowSubmitted
		    && $conf->timePCViewAllReviews())
		|| (defval($prow, "myReviewType") > 0
		    && $prow->conflictType == 0 && $rrowSubmitted
		    && (defval($prow, "myReviewSubmitted") > 0
			|| defval($prow, "myReviewNeedsSubmit", 1) == 0)
		    && ($this->isPC || $conf->settings["extrev_view"] >= 1))
		|| ($rrow && $rrow->paperId == $prow->paperId
		    && $this->ownReview($rrow))
		|| ($rrow && isset($prow->myReviewId)
		    && $prow->myReviewId == $rrow->reviewId)))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($prow->conflictType < CONFLICT_AUTHOR && !$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->conflictType >= CONFLICT_AUTHOR && $conf->timeAuthorViewReviews()
		 && $this->reviewsOutstanding && $this->isReviewer)
	    $whyNot['reviewsOutstanding'] = 1;
	else if ($prow->conflictType >= CONFLICT_AUTHOR && !$rrowSubmitted)
	    $whyNot['permission'] = 1;
	else if ($prow->conflictType >= CONFLICT_AUTHOR)
	    $whyNot['deadline'] = 'au_seerev';
	else if ($prow->conflictType > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->myReviewType > 0 && !$this->isPC && $prow->myReviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else if (!$rrowSubmitted)
	    $whyNot['reviewNotSubmitted'] = 1;
	else if (!$conf->timeReviewOpen())
	    $whyNot['deadline'] = "rev_open";
	else {
	    $whyNot['reviewNotComplete'] = 1;
	    if (!$conf->timeReviewPaper($this->isPC, true, true))
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	}
	if ($this->privChair)
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRequestReview($prow, $conf, $time, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	if (($prow->myReviewType >= REVIEW_SECONDARY || $this->privChair)
	    && ($conf->timeReviewPaper(false, true, true) || $this->privChairOverride() || !$time))
	    return true;
	// collect failure reasons
	if ($prow->myReviewType < REVIEW_SECONDARY)
	    $whyNot['permission'] = 1;
	else {
	    $whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($this->privChair)
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canReview($prow, $rrow, $conf, &$whyNot = null, $submit = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	assert(!$rrow || $rrow->paperId == $prow->paperId);
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"];
	$manager = $this->actChair($prow);
	if ($rrow)
	    $myReview = $manager || $this->ownReview($rrow);
	else
	    $myReview = $prow->myReviewType > 0;
	// policy
	if (($prow->timeSubmitted > 0 || $myReview
	     || ($manager && $forceShow))
	    && (($this->isPC && $prow->conflictType == 0 && !$rrow)
		|| $myReview || ($manager && $forceShow))
	    && ($conf->timeReviewPaper($this->isPC, $myReview, true)
		|| $this->privChairOverride()
		|| ($manager && !$submit)))
	    return true;
	// collect failure reasons
	if ($rrow && $rrow->contactId != $this->contactId && !$this->privChair)
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflictType > 0)
		$whyNot['conflict'] = 1;
	    else if ($this->isPC && $prow->myReviewType <= 0
		     && !$conf->timeReviewPaper(true, false, true)
		     && (!$rrow || $rrow->contactId == $this->contactId))
		$whyNot['reviewNotAssigned'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($this->privChair && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($this->privChair && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitReview($prow, $rrow, $conf, &$whyNot = null) {
	return $this->canReview($prow, $rrow, $conf, $whyNot, true);
    }

    function canRateReview($prow, $rrow, $conf) {
	if ($conf->setting("allowPaperOption") < 12)
	    return false;
	$rs = $conf->setting("rev_ratings");
	if ($rs == REV_RATINGS_PC)
	    return $this->actPC($prow);
	else if ($rs == REV_RATINGS_PC_EXTERNAL)
	    return $this->actPC($prow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0);
	else
	    return false;
    }

    function canSetRank($prow, $conf, $forceShow = null) {
	return $conf->setting("tag_rank")
	    && ($this->actPC($prow, $forceShow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0));
    }


    function canComment($prow, $crow, $conf, &$whyNot = null, $submit = false) {
	// check whether this is a response
	if ($crow && $crow->forAuthors > 1)
	    return $this->canRespond($prow, $crow, $conf, $whyNot, $submit);
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if (($prow->timeSubmitted > 0 || $prow->myReviewType > 0)
	    && (($this->privChair && ($forceShow || $prow->conflictType == 0))
		|| ($this->isPC && $prow->conflictType == 0)
		|| $prow->myReviewType > 0)
	    && ($conf->timeReviewPaper($this->isPC, true, true)
		|| $conf->setting('cmt_always') > 0
		|| $this->privChairOverride()
		|| ($this->privChair && !$submit))
	    && (!$crow
		|| $crow->contactId == $this->contactId
		|| $this->privChair))
	    return true;
	// collect failure reasons
	if ($crow && $crow->contactId != $this->contactId && !$this->privChair)
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflictType > 0)
		$whyNot['conflict'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($this->privChair && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($this->privChair && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitComment($prow, $crow, $conf, &$whyNot = null) {
	return $this->canComment($prow, $crow, $conf, $whyNot, true);
    }

    function canViewComment($prow, $crow, $conf, &$whyNot = null, $ignoreForceShow = false) {
	// Policy outline:
	// forAuthors 2, forReviewers 1     response, ready for reviewers
	// forAuthors 2, forReviewers 0     response, not ready for reviewers
	// forAuthors 1/0                   comment for authors/not for authors
	// forAuthors 1/0, forReviewers 1   comment for PC, reviewers
	// forAuthors 1/0, forReviewers 0   comment for PC
	// forAuthors 1/0, forReviewers -1  comment for PC, reviewers
	// (now all comments are postrev, before forReviewers>=0 meant anytime)

	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	// policy
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	if ($crow && isset($crow->threadContacts)
	    && isset($crow->threadContacts[$this->contactId]))
	    $thread_contactId = $this->contactId;
	if ($crow_contactId == $this->contactId		// wrote this comment
	    || ($prow->conflictType >= CONFLICT_AUTHOR	// author's response
		&& $crow && $crow->forAuthors == 2)
	    || ($prow->conflictType >= CONFLICT_AUTHOR	// author viewable
		&& $conf->timeAuthorViewReviews()
		&& (!$crow || $crow->forAuthors != 0))
	    || ($this->privChair			// chair privilege
		&& ($prow->conflictType == 0 || $forceShow))
	    || ($prow->conflictType == 0		// reviewer postrev
		&& $this->canViewReview($prow, null, $conf, $xWhyNot, $ignoreForceShow)
		&& (!$crow || $this->isPC || $crow->forReviewers != 0)))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->conflictType >= CONFLICT_AUTHOR)
	    $whyNot['deadline'] = 'au_seerev';
	else if ($prow->conflictType > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->myReviewType > 0 && !$this->isPC && defval($prow, "myReviewSubmitted") > 0)
	    $whyNot['externalReviewer'] = 1;
	else if ($crow && $crow->forAuthors > 1 && $crow->forReviewers == 0)
	    $whyNot['responseNotReady'] = 1;
	else
	    $whyNot['reviewNotComplete'] = 1;
	if ($this->privChair)
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRespond($prow, $crow, $conf, &$whyNot = null, $submit = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $conf, $whyNot)))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if ($prow->timeSubmitted > 0
	    && (($this->privChair && ($forceShow || $prow->conflictType == 0))
		|| $prow->conflictType >= CONFLICT_AUTHOR)
	    && ($conf->timeAuthorRespond()
		|| $this->privChairOverride()
		|| ($this->privChair && !$submit))
	    && (!$crow || $crow->forAuthors > 1))
	    return true;
	// collect failure reasons
	if (!$this->privChair && $prow->conflictType < CONFLICT_AUTHOR)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    $whyNot['deadline'] = "resp_done";
	    if ($this->privChair && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($this->privChair && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }


    function amPaperAuthor($paperId, $conf, $prow = null) {
	if ($prow === null) {
	    // Query for a specific match of the author and paper
	    $query = "select paperId from PaperConflict where paperId=$paperId and contactId=$this->contactId and conflictType>=" . CONFLICT_AUTHOR;
	    $result = $conf->qe($query);
	    return edb_nrows($result) > 0;
	} else
	    return $prow->conflictType >= CONFLICT_AUTHOR;
    }

    function amDiscussionLead($paperId, $conf, $prow = null) {
	if ($prow === null && $paperId <= 0) {
	    if ($this->isDiscussionLead === null) {
		$result = $conf->qe("select count(paperId) from Paper where leadContactId=$this->contactId");
		$row = edb_row($result);
		$this->isDiscussionLead = $row && $row[0] > 0;
	    }
	    return $this->isDiscussionLead;
	} else if ($prow === null) {
	    $result = $conf->qe("select paperId from Paper where paperId=$paperId and leadContactId=$this->contactId");
	    return edb_nrows($result) > 0;
	} else
	    return $prow->leadContactId == $this->contactId;
    }

    function canEditContactAuthors($prow) {
	return ($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair);
    }

    function timeReview($prow, $rrow, $conf) {
	$myReview = $prow->myReviewType || $prow->reviewId
	    || ($rrow && isset($prow->myReviewId)
		&& $prow->myReviewId == $rrow->reviewId)
	    || ($this->privChair && $rrow && $rrow->contactId != $this->contactId);
	return $conf->timeReviewPaper($this->isPC, $myReview, true);
    }

    function canViewReviewerIdentity($prow, $rrow, $conf, $ignoreForceShow = false) {
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$rrow_contactId = 0;
	if ($rrow && isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if ($rrow && isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	if (($this->privChair && $forceShow)
	    || ($rrow && $rrow_contactId == $this->contactId)
	    || ($rrow && $this->ownReview($rrow))
	    || ($this->privChair && $prow && $prow->conflictType == 0)
	    || ($this->isPC && $prow && $prow->conflictType == 0
		&& $conf->setting("pc_seeallrev") != 2)
	    || ($prow && $prow->myReviewType > 0
		&& (defval($prow, "myReviewSubmitted") > 0
		    || defval($prow, "myReviewNeedsSubmit", 1) == 0)
		&& ($this->isPC || $conf->settings["extrev_view"] >= 2))
	    || !reviewBlind($rrow))
	    return true;
	return false;
    }

    function canViewCommentIdentity($prow, $crow, $conf, $ignoreForceShow = false) {
	if ($crow && $crow->forAuthors > 1)
	    return $this->canViewAuthors($prow, $conf, false);
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	if (($this->privChair && $forceShow)
	    || $crow_contactId == $this->contactId
	    || ($this->isPC && $prow->conflictType == 0)
	    || ($prow->myReviewType > 0 && $prow->conflictType == 0
		&& $conf->settings["extrev_view"] >= 2)
	    || !commentBlind($crow))
	    return true;
	return false;
    }

    function canViewDecision($prow, $conf, $forceShow = false) {
	if (($prow && $prow->conflictType >= CONFLICT_AUTHOR && $conf->timeAuthorViewDecision())
	    // || ($this->isPC
	    //     && (!$prow || $prow->conflictType == 0)
	    //     && $conf->timePCViewDecision())
	    || ($this->isPC && $conf->timePCViewDecision())
	    || ($prow && defval($prow, "myReviewType", 0) > 0
		&& defval($prow, "myReviewSubmitted", 0) > 0
		&& $conf->timeReviewerViewDecision())
	    || ($this->privChair && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])
	    || ($this->privChair && (!$prow || $prow->conflictType == 0))
	    || ($this->privChair && $forceShow))
	    return true;
	return false;
    }

    function viewReviewFieldsScore($prow, $rrow, $conf) {
	// Returns the maximum authorView score for an invisible review
	// field.  Values for authorView are:
	//   -2   admin can view
	//   -1   admin and review author can view
	//    0   admin and PC/any reviewer can view
	//    1   admin and PC/any reviewer and author can view
	// So returning -3 means all scores are visible.
	// Deadlines are not considered.
	// (!$prow && !$rrow) ==> return best case scores that can be seen.
	// (!$prow &&  $rrow) ==> return worst case scores that can be seen.

	// chair can see everything
	if ($this->privChair
	    && (!$prow
		|| $prow->conflictType == 0
		|| (isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])))
	    return -3;

	// author can see author information
	if (($prow && $prow->conflictType > 0)
	    || (!$prow && !$this->amReviewer()))
	    return 0;

	// authors and external reviewers of not this paper can't see anything
	if (!$this->amReviewer()
	    || (!$this->isPC && $prow && $prow->myReviewType <= 0))
	    return 10000;

	// see who this reviewer is
	if (!$rrow)
	    $rrowContactId = $this->contactId;
	else if (isset($rrow->reviewContactId))
	    $rrowContactId = $rrow->reviewContactId;
	else if (isset($rrow->contactId))
	    $rrowContactId = $rrow->contactId;
	else
	    $rrowContactId = -1;

	// reviewer can see any information they entered
	if ($rrowContactId == $this->contactId)
	    return -2;

	// otherwise, can see information visible for all reviewers
	return -1;
    }

    function canViewTags($prow, $Conf, $forceShow = true) {
	return ($this->isPC
		&& (!$prow || $prow->conflictType <= 0
		    || ($this->privChair && $forceShow)
		    || $Conf->setting("tag_seeall") > 0));
    }

    function canSetTags($prow, $Conf, $forceShow = true) {
	return ($this->isPC
		&& (!$prow || $prow->conflictType <= 0
		    || ($this->privChair && $forceShow)));
    }

    function actChair($prow, $forceShow = false) {
	return ($this->privChair
		&& (!$prow || $prow->conflictType <= 0
		    || $forceShow || defval($_REQUEST, "forceShow")));
    }

    function actPC($prow, $forceShow = null) {
	return ($this->isPC
		&& (!$prow
		    || $prow->conflictType <= 0
		    || ($this->privChair && ($forceShow === null ? defval($_REQUEST, "forceShow") : $forceShow))));
    }

    function canSetOutcome($prow) {
	if (!$this->privChair)
	    return false;
	if ($prow && $prow->conflictType > 0
	    && !(isset($_REQUEST['forceShow']) && $_REQUEST['forceShow']))
	    return false;
	return true;
    }

    function paperStatus($paperId, $row, $long) {
	global $Conf;
	if ($row->timeWithdrawn > 0)
	    return "<span class='pstat pstat_with'>Withdrawn</span>";
	else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1)
	    return "<span class='pstat pstat_noup'>In progress</span>";
	else if (isset($row->outcome) && $row->outcome != 0
		 && $this->canViewDecision($row, $Conf, abs($long) > 1)) {
	    $rf = reviewForm();
	    $x =& $rf->options['outcome'][$row->outcome];
	    if (isset($x))
		return "<span class=\"pstat pstat_" . htmlspecialchars($x) . "\">" . htmlspecialchars($x) . "</span>";
	    else
		return "<span class='pstat'>Unknown decision \"" . htmlspecialchars($row->outcome) . "\"</span>";
	//} else if (isset($row->reviewCount) && $row->reviewCount > 0) {
	//    if ($long < 0 && $row->conflictType < CONFLICT_AUTHOR)
	//	return "";
	//    else if ($this->canViewReview($row, null, $Conf))
	//	return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	//    else
	//	return "<span class='pstat pstat_rev'>Under&nbsp;review</span>";
	} else {
	    if ($row->timeSubmitted > 0) {
		if ($long < 0)
		    return "";
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
	    } else
		$x = "<span class='pstat pstat_prog'>In progress</span>";
	    return $x;
	}
    }


    function generatePassword($length) {
	global $Opt;
	if (isset($Opt["ldapLogin"]))
	    return "<stored in LDAP>";

	$l = array("a", "e", "i", "o", "u", "y", "a", "e", "i", "o", "u", "y",
		   "a", "e", "i", "o", "u", "y", "a", "e", "i", "o", "u", "y",
		   "a", "e", "i", "o", "u", "y", "a", "e", "i", "o", "u", "y",
		   "b", "c", "d", "g", "h", "j", "k", "l", "m", "n", "p",
		   "r", "s", "t", "u", "v", "w", "tr",
		   "cr", "br", "fr", "th", "dr", "ch", "ph", "wr", "st",
		   "sp", "sw", "pr", "sl", "cl",
		   "2", "3", "4", "5", "6", "7", "8", "9",
		   "-", "@", "_", "+", "=");
	$n = count($l);
	$password = "";
	while (strlen($password) < $length)
	    $password .= $l[mt_rand(0, $n - 1)];
	return $password;
    }

    function sendAccountInfo($conf, $create, $sensitive) {
	require_once("mailtemplate.inc");
	global $mailTemplates;
	$mailer = new Mailer(null, $this);
	$preparation = $mailer->prepareToSend($create ? "@createaccount" : "@accountinfo", null, $this);

	if ($preparation["allowEmail"] || !$sensitive) {
	    $mailer->sendPrepared($preparation);
	    return true;
	} else {
	    $conf->errorMsg("Mail cannot be sent to your email address &lt;" . htmlspecialchars($this->email) . "&gt; at this time.");
	    return false;
	}
    }


    function assignPaper($paperId, $row, $reviewer, $type, $conf) {
	global $reviewTypeName;
	$reviewerContactId = (is_object($reviewer) ? $reviewer->contactId : $reviewer);
	if ($type == 0 && $row && ($row->reviewType == REVIEW_EXTERNAL || $row->reviewType == REVIEW_PC))
	    return;
	$qtag = "";
	if ($type > 0 && (!$row || !$row->reviewType)) {
	    $roundqa = $roundqb = "";
	    if (($type == REVIEW_PRIMARY || $type == REVIEW_SECONDARY)
		&& ($t = $conf->settingText("rev_roundtag"))
		&& $conf->settings["allowPaperOption"] >= 11) {
		if (!($k = array_search($t, $conf->settings["rounds"]))) {
		    $rounds = $conf->settingText("tag_rounds", "");
		    $rounds = ($rounds ? "$rounds$t " : " $t ");
		    $conf->qe("insert into Settings (name, value, data) values ('tag_rounds', 1, '" . sqlq($rounds) . "') on duplicate key update data='" . sqlq($rounds) . "'");
		    $conf->settings["tag_rounds"] = 1;
		    $conf->settingTexts["tag_rounds"] = $rounds;
		    $conf->settings["rounds"][] = $t;
		    $k = array_search($t, $conf->settings["rounds"]);
		}
		$roundqa = ", reviewRound";
		$roundqb = ", $k";
	    }
	    $q = "insert into PaperReview (paperId, contactId, reviewType, requestedBy, requestedOn$roundqa) values ($paperId, $reviewerContactId, $type, $this->contactId, current_timestamp$roundqb)";
	} else if ($type > 0 && $row->reviewType != $type)
	    $q = "update PaperReview set reviewType=$type where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType && !$row->reviewModified)
	    $q = "delete from PaperReview where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType)
	    $q = "update PaperReview set reviewType=" . REVIEW_PC . " where reviewId=$row->reviewId";
	else
	    return;

	if ($conf->qe($q, "while assigning review")) {
	    if ($qtag)
		$conf->q($qtag);
	    if ($row && defval($row, "reviewToken", 0) != 0 && $type <= 0)
		$conf->settings["rev_tokens"] = -1;
	    $conf->log("Added $reviewTypeName[$type] reviewer " . (is_object($reviewer) ? $reviewer->email : $reviewer), $this, $paperId);
	}
    }

}
