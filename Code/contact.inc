<?php
// contact.inc -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Which conference are we dealing with?
    var $confDsn;

    // Information from the SQL definition
    var $contactId;
    var $visits;
    var $firstName;
    var $lastName;
    var $email;
    var $preferredEmail;
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
    var $contactTags;
    var $reviewsOutstanding;
    var $chairContact;
    const CAP_AUTHORVIEW = 1;
    var $capabilities;
    var $validated;


    //
    // Initialization functions
    //

    function valid($update = false) {
	global $Conf, $Opt;
	if (!$this->validated && $this->contactId > 0) {
	    $qr = "";
	    if (isset($_SESSION["rev_tokens"]))
		$qr = " or PaperReview.reviewToken in (" . join(", ", $_SESSION["rev_tokens"]) . ")";
	    $result = $Conf->qe("select max(conflictType),
		PaperReview.contactId as reviewer,
		max(PaperReview.reviewNeedsSubmit) as reviewNeedsSubmit,
		count(ExtPaperReview.requestedBy) as requester,
		ContactInfo.roles
		from ContactInfo
		left join PaperReview on (PaperReview.contactId=ContactInfo.contactId$qr)
		left join PaperConflict on (PaperConflict.contactId=ContactInfo.contactId)
		left join PaperReview as ExtPaperReview on (ExtPaperReview.requestedBy=ContactInfo.contactId)
		where ContactInfo.contactId=$this->contactId
		group by ContactInfo.contactId");
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
		    $this->roles |= $row[4] & (self::ROLE_PC | self::ROLE_ADMIN | self::ROLE_CHAIR);
		}
		$this->isPC = ($this->roles & (self::ROLE_PC | self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
		$this->privChair = ($this->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
		if (!$this->privChair
		    && $this->chairContact == $this->contactId)
		    $this->chairContact = null;
		// change view ID or chair mode
		if ($this->privChair || $this->chairContact) {
		    $viewContact = rcvtint($_REQUEST["viewContact"]);
		    if ($viewContact <= 0 && isset($_REQUEST["viewContact"]))
			$viewContact = $Conf->getContactId($_REQUEST["viewContact"]);
		    if ($viewContact > 0) {
			if ($this->privChair)
			    $this->chairContact = $this->contactId;
			$this->contactId = $viewContact;
			unset($_REQUEST["viewContact"]);
			unset($_SESSION["l"]);
			return $this->valid(true);
		    }
		    $chairMode = rcvtint($_REQUEST["chairMode"]);
		    if (!$this->privChair && $chairMode >= 0) {
			$this->contactId = $this->chairContact;
			$this->chairContact = $this->email;
			unset($_REQUEST["chairMode"]);
			return $this->valid(true);
		    }
		}
		if ($update)
		    $this->lookupById($this->contactId);
		$this->validated = true;
	    }
	} else if (isset($Opt["validatorContact"]) && $Opt["validatorContact"]
		   && !$this->contactId && isset($_REQUEST["validator"])) {
	    $this->lookupByEmail($Opt["validatorContact"]);
	    return $this->valid($update);
	}
	if (isset($this->capabilities))
	    foreach ($this->capabilities as $pid => $cap)
		if ($cap & self::CAP_AUTHORVIEW)
		    $this->isAuthor = $this->validated = true;
	return $this->validated && $this->confDsn == $Opt["dsn"];
    }

    function validContact() {
	return $this->valid() && $this->contactId > 0;
    }

    static function _addressKeys() {
	return array("addressLine1", "addressLine2", "city", "state",
		     "zipCode", "country");
    }

    function invalidate() {
	$this->confDsn = 0;

	$this->contactId = 0;
	$this->visits = 0;
	foreach (array("firstName", "lastName", "email", "preferredEmail",
		       "affiliation", "voicePhoneNumber", "faxPhoneNumber",
		       "password", "collaborators") as $k)
	    $this->$k = "";
	foreach (self::_addressKeys() as $k)
	    $this->$k = null;
	$this->contactTags = null;
	$this->note = 0;
	$this->defaultWatch = WATCH_COMMENT;

	$this->roles = 0;
	$this->isAuthor = $this->isReviewer = $this->isPC = $this->privChair = false;
	$this->reviewsOutstanding = false;
	$this->chairContact = null;

	$this->validated = false;
    }

    function amReviewer() {
	return $this->isPC || $this->isReviewer;
    }

    function trim() {
	$this->contactId = (int) trim($this->contactId);
	$this->visits = trim($this->visits);
	$this->firstName = simplifyWhitespace($this->firstName);
	$this->lastName = simplifyWhitespace($this->lastName);
	foreach (array("email", "preferredEmail", "affiliation",
		       "voicePhoneNumber", "faxPhoneNumber", "password", "note",
		       "addressLine1", "addressLine2", "city", "state",
		       "zipCode", "country")
		 as $k)
	    if ($this->$k)
		$this->$k = trim($this->$k);
    }

    function go($url) {
	global $ConfSiteBase, $ConfSiteSuffix;
	if ($url === false)
	    $url = "${ConfSiteBase}index$ConfSiteSuffix";
	go($url);
    }

    function goAlert($url, $message = "") {
	global $Conf;
	$Conf->errorMsg($message);
	go($url);
    }

    function goIfInvalid() {
	global $Conf;
	if (!$this->valid()) {
	    if (defval($_REQUEST, "ajax"))
		$Conf->ajaxExit(array("ok" => 0, "loggedout" => 1));
	    $x = array("afterLogin" => 1, "blind" => 1);
	    $rf = reviewForm();
	    // Preserve review form values.
	    foreach ($rf->reviewFields as $k => $v)
		if (isset($_REQUEST[$k]))
		    $x[$k] = $_REQUEST[$k];
	    // Preserve comments and other long-to-type things.
	    foreach (array("comment", "visibility", "override", "plimit",
			   "subject", "emailBody", "cc", "recipients",
			   "replyto") as $k)
		if (isset($_REQUEST[$k]))
		    $x[$k] = $_REQUEST[$k];
	    // NB: selfHref automagically preserves common parameters like
	    // "p", "q", etc.
	    $_SESSION["afterLogin"] = selfHref($x, null, false);
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

    function updateDB($where = "") {
	global $Conf;
	$this->trim();
	$qa = ", roles='$this->roles', defaultWatch='$this->defaultWatch'";
	if ($Conf->sversion >= 25 && $this->preferredEmail != "")
	    $qa .= ", preferredEmail='" . sqlq($this->preferredEmail) . "'";
	if ($Conf->sversion >= 35) {
	    if ($this->contactTags)
		$qa .= ", contactTags='" . sqlq($this->contactTags) . "'";
	    else
		$qa .= ", contactTags=null";
	}
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
	$result = $Conf->qe($query, $where);
	if (!$result)
	    return $result;
	$Conf->qx("delete from ContactAddress where contactId=$this->contactId");
	if ($this->addressLine1 || $this->addressLine2 || $this->city
	    || $this->state || $this->zipCode || $this->country) {
	    $query = "insert into ContactAddress (contactId, addressLine1, addressLine2, city, state, zipCode, country) values ($this->contactId, '" . sqlq($this->addressLine1) . "', '" . sqlq($this->addressLine2) . "', '" . sqlq($this->city) . "', '" . sqlq($this->state) . "', '" . sqlq($this->zipCode) . "', '" . sqlq($this->country) . "')";
	    $result = $Conf->qe($query, $where);
	}
	return $result;
    }

    function initialize($email, $useRequest = false) {
	global $Conf;
	$this->email = trim($email);
	$this->password = self::generatePassword(10);

	// update paper authorship based on authorInformation fields
	// (ignore errors)
	$bestEmail = $this->email;
	if ($useRequest && isset($_REQUEST["preferredEmail"]))
	    $bestEmail = $_REQUEST["preferredEmail"];
	$addAuthor = array();
	$result = $Conf->q("select paperId, authorInformation from Paper where authorInformation like '%	" . sqlq_for_like($bestEmail) . "	%'");
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au)
		if (strcasecmp($au[2], $bestEmail) == 0) {
		    $addAuthor[] = "($row->paperId, *, " . CONFLICT_AUTHOR . ")";
		    if ($this->firstName == "")
			$this->firstName = $au[0];
		    if ($this->lastName == "")
			$this->lastName = $au[1];
		    break;
		}
	}

	$qa = "email, password, creationTime";
	$qb = "'" . sqlq($this->email) . "', '" . sqlq($this->password) . "', " . time();
	foreach (array("firstName", "lastName", "affiliation", "collaborators",
		       "voicePhoneNumber", "faxPhoneNumber", "preferredEmail")
		 as $k) {
	    if ($useRequest && isset($_REQUEST[$k])
		&& defval($this, $k, "") == "")
		$this->$k = trim($_REQUEST[$k]);
	    if (isset($this->$k) && $this->$k
		&& ($k != "preferredEmail"
		    || $Conf->sversion >= 25)) {
		$qa .= ", $k";
		$qb .= ", '" . sqlq($this->$k) . "'";
	    }
	}
	$result = $Conf->q("insert into ContactInfo ($qa) values ($qb)");
	if (!$result)
	    return $result;
	$contactId = (int) $Conf->lastInsertId("while creating contact");
	if (!$contactId)
	    return false;

	if (count($addAuthor))
	    $Conf->q("insert into PaperConflict (paperId, contactId, conflictType) values " . str_replace("*", $contactId, join(", ", $addAuthor)) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");

	return $this->lookupByEmail($this->email);
    }

    function updateFromQuery($query) {
	global $Conf, $Opt;
	$result = $Conf->q($query);
	if (edb_nrows($result) >= 1) {
	    $fromdb = edb_orow($result);

	    $this->contactId = (int) $fromdb->contactId;
	    $this->visits = $fromdb->visits;
	    $this->firstName = $fromdb->firstName;
	    $this->lastName = $fromdb->lastName;
	    $this->email = $fromdb->email;
	    $this->preferredEmail = defval($fromdb, "preferredEmail", null);
	    $this->affiliation = $fromdb->affiliation;
	    $this->voicePhoneNumber = $fromdb->voicePhoneNumber;
	    $this->faxPhoneNumber = $fromdb->faxPhoneNumber;
	    $this->password = $fromdb->password;
	    $this->note = $fromdb->note;
	    $this->collaborators = $fromdb->collaborators;
	    $this->defaultWatch = defval($fromdb, "defaultWatch", 0);
	    $this->contactTags = defval($fromdb, "contactTags", null);

	    $this->confDsn = $Opt["dsn"];

	    $this->trim();
	    $this->validated = false;
	} else {
	    // Not found - zero out the context
	    $this->invalidate();
	}
	return $result;
    }

    function lookupByEmail($email) {
	return $this->updateFromQuery("select ContactInfo.* from ContactInfo
		where email='" . sqlqtrim($email) . "'");
    }

    function lookupById($contactId) {
	return $this->updateFromQuery("select ContactInfo.* from ContactInfo
		where ContactInfo.contactId=$contactId");
    }

    function lookupAddress() {
	global $Conf;
	$result = $Conf->qx("select * from ContactAddress where contactId=$this->contactId");
	foreach (self::_addressKeys() as $k)
	    $this->$k = null;
	if (($row = edb_orow($result)))
	    foreach (self::_addressKeys() as $k)
		$this->$k = $row->$k;
    }

    static function makeMinicontact($o) {
	// If you change this function, search for its callers to ensure
	// they provide all necessary information.
	$c = new Contact();
	$c->invalidate();
	$c->contactId = (int) $o->contactId;
	$c->firstName = defval($o, "firstName", "");
	$c->lastName = defval($o, "lastName", "");
	$c->email = defval($o, "email", "");
	$c->preferredEmail = defval($o, "preferredEmail", "");
	$c->password = defval($o, "password", "");
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

    function _fetchPaperRow($prow, &$whyNot) {
	global $Conf;
	if (!is_object($prow))
	    return $Conf->paperRow($prow, $this->contactId, $whyNot);
	else {
	    $whyNot = array("paperId" => $prow->paperId);
	    return $prow;
	}
    }

    function privChairOverride() {
	return $this->privChair && isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    function canStartPaper(&$whyNot = null) {
	global $Conf;
	$whyNot = array();
	if ($this->privChairOverride() || $Conf->timeStartPaper())
	    return true;
	$whyNot["deadline"] = "sub_reg";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canEditPaper($prow, &$whyNot = null) {
	return ($prow->conflictType >= CONFLICT_AUTHOR
		|| $this->privChair);
    }

    function canUpdatePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeUpdatePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0 && $Conf->setting('sub_freeze') > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeUpdatePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "sub_update";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canFinalizePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeFinalizePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeFinalizePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "finalizePaperSubmission";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canWithdrawPaper($prow, &$whyNot = null) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
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

    function canRevivePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $prow->timeWithdrawn > 0
	    && ($Conf->timeUpdatePaper($prow) || $this->privChairOverride()))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn <= 0)
	    $whyNot["notWithdrawn"] = 1;
	if (!$Conf->timeUpdatePaper($prow) && !$this->privChairOverride())
	    $whyNot["deadline"] = "sub_update";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canSubmitFinalPaper($prow, &$whyNot = null, $override = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$privChairOverride = ($override ? $this->privChair : $this->privChairOverride());
	if (($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair)
	    && $Conf->collectFinalPapers()
	    && $prow->timeWithdrawn <= 0 && $prow->outcome > 0
	    && ($Conf->timeSubmitFinalPaper() || $privChairOverride))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$this->privChair)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	// NB logic order here is important elsewhere
	if (!$Conf->collectFinalPapers()
	    || (!$Conf->timeAuthorViewDecision() && !$privChairOverride))
	    $whyNot["deadline"] = "final_open";
	else if ($prow->outcome <= 0)
	    $whyNot["notAccepted"] = 1;
	else if (!$Conf->timeSubmitFinalPaper() && !$privChairOverride)
	    $whyNot["deadline"] = "final_done";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function actAuthorView($prow) {
	return $prow->conflictType >= CONFLICT_AUTHOR
	    || (isset($this->capabilities)
		&& isset($this->capabilities[$prow->paperId])
		&& ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW) != 0);
    }

    function actAuthorSql($table, $only_if_complex = false) {
	$m = array("$table.conflictType>=" . CONFLICT_AUTHOR);
	if (isset($this->capabilities))
	    foreach ($this->capabilities as $pid => $cap)
		if ($cap & Contact::CAP_AUTHORVIEW)
		    $m[] = "Paper.paperId=$pid";
	if (count($m) > 1)
	    return "(" . join(" or ", $m) . ")";
	else
	    return $only_if_complex ? false : $m[0];
    }

    function canViewPaper($prow, &$whyNot = null, $download = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$auview = $this->actAuthorView($prow);
	if ($auview || $this->privChair
	    || ($prow->myReviewType > 0 && $Conf->timeReviewerViewSubmittedPaper())
	    || ($this->isPC && $Conf->timePCViewPaper($prow, $download)))
	    return true;
	// collect failure reasons
	if (!$this->isPC && !$auview && $prow->myReviewType <= 0) {
	    $whyNot["permission"] = 1;
	    return false;
	}
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	if ($this->isPC && !$Conf->timePCViewPaper($prow, $download))
	    $whyNot["deadline"] = "sub_sub";
	else if ($prow->myReviewType > 0 && !$Conf->timeReviewerViewSubmittedPaper())
	    $whyNot["deadline"] = "sub_sub";
	if ((!$this->isPC && $prow->myReviewType <= 0) || count($whyNot) == 1)
	    $whyNot["permission"] = 1;
	return false;
    }

    function canDownloadPaper($prow, &$whyNot = null) {
	return $this->canViewPaper($prow, $whyNot, true);
    }

    function canViewAuthors($prow, $blindWins, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$pblind = $Conf->paperBlind($prow, $this);
	if (($this->actAuthorView($prow)
	     && (!$this->isPC || !$blindWins || !$pblind))
	    || ($prow->myReviewType > 0 && !$pblind
		&& $Conf->timeReviewerViewSubmittedPaper())
	    || ($prow->timeSubmitted > 0 && !$pblind && $this->isPC)
	    || ($this->isPC && !$pblind && $prow->timeWithdrawn <= 0
		&& $Conf->setting("pc_seeall") > 0))
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

    function canViewPaperOption($prow, $opt, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	if (!is_object($opt) && !($opt = paperOptions($opt))) {
	    $whyNot["invalidId"] = "paper";
	    return false;
	}
	// policy
	if ($this->actAuthorView($prow) || $this->privChair)
	    return true;
	if (!$this->canViewPaper($prow, $whyNot))
	    return false;	// $whyNot already set
	if (($prow->myReviewType > 0 || $this->isPC)
	    && ($opt->pcView == 1
		|| ($opt->pcView == 2 && $this->canViewAuthors($prow, true))))
	    return true;
	$whyNot["permission"] = 1;
	return false;
    }

    function ownReview($rrow) {
	global $Conf;
	return $rrow->contactId == $this->contactId
	    || (isset($_SESSION["rev_tokens"]) && array_search($rrow->reviewToken, $_SESSION["rev_tokens"]) !== false)
	    || ($rrow->requestedBy == $this->contactId && $rrow->reviewType == REVIEW_EXTERNAL && $Conf->setting("pcrev_editdelegate"));
    }

    function canViewReview($prow, $rrow, &$whyNot = null, $ignoreForceShow = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
	$pc_seeallrev = ($this->isPC ? $Conf->setting("pc_seeallrev") : 0);
	$auview = $this->actAuthorView($prow);
	if ($this->privChair && $forceShow)
	    return true;
	if (($prow->timeSubmitted > 0
	     || defval($prow, "myReviewType") > 0)
	    && (($auview
		 && $Conf->timeAuthorViewReviews($this->reviewsOutstanding && $this->isReviewer)
		 && $rrowSubmitted)
		|| ($this->privChair && $prow->conflictType == 0)
		|| ($this->isPC
		    && $prow->conflictType == 0 && $rrowSubmitted
		    && $pc_seeallrev > 0
		    && ($pc_seeallrev != 4 || !$this->reviewsOutstanding)
		    && ($pc_seeallrev != 3 || !defval($prow, "myReviewType")))
		|| (defval($prow, "myReviewType") > 0
		    && $prow->conflictType == 0 && $rrowSubmitted
		    && (defval($prow, "myReviewSubmitted") > 0
			|| defval($prow, "myReviewNeedsSubmit", 1) == 0)
		    && ($this->isPC || $Conf->settings["extrev_view"] >= 1))
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
	else if (!$auview && !$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($auview && $Conf->timeAuthorViewReviews()
		 && $this->reviewsOutstanding && $this->isReviewer)
	    $whyNot['reviewsOutstanding'] = 1;
	else if ($auview && !$rrowSubmitted)
	    $whyNot['permission'] = 1;
	else if ($auview)
	    $whyNot['deadline'] = 'au_seerev';
	else if ($prow->conflictType > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->myReviewType > 0 && !$this->isPC && $prow->myReviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else if (!$rrowSubmitted)
	    $whyNot['reviewNotSubmitted'] = 1;
	else if (!$Conf->timeReviewOpen())
	    $whyNot['deadline'] = "rev_open";
	else {
	    $whyNot['reviewNotComplete'] = 1;
	    if (!$Conf->timeReviewPaper($this->isPC, true, true))
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	}
	if ($this->privChair)
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRequestReview($prow, $time, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	if (($prow->myReviewType >= REVIEW_SECONDARY || $this->privChair)
	    && ($Conf->timeReviewPaper(false, true, true) || $this->privChairOverride() || !$time))
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

    function canReview($prow, $rrow, &$whyNot = null, $submit = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
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
	    && ($Conf->timeReviewPaper($this->isPC, $myReview, true)
		|| $this->privChairOverride()
		|| ($manager && !$submit)))
	    return true;
	// collect failure reasons
	// The "reviewNotAssigned" and "deadline" failure reasons are special.
	// If either is set, the system will still allow review form download.
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
		     && !$Conf->timeReviewPaper(true, false, true)
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

    function canSubmitReview($prow, $rrow, &$whyNot = null) {
	return $this->canReview($prow, $rrow, $whyNot, true);
    }

    function canRateReview($prow, $rrow) {
	global $Conf;
	$rs = $Conf->setting("rev_ratings");
	if ($rs == REV_RATINGS_PC)
	    return $this->actPC($prow);
	else if ($rs == REV_RATINGS_PC_EXTERNAL)
	    return $this->actPC($prow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0);
	else
	    return false;
    }

    function canSetRank($prow, $forceShow = null) {
	global $Conf;
	return $Conf->setting("tag_rank")
	    && ($this->actPC($prow, $forceShow)
		|| ($prow->conflictType <= 0 && $prow->myReviewType > 0));
    }


    function canComment($prow, $crow, &$whyNot = null, $submit = false) {
	global $Conf;
	// check whether this is a response
	if ($crow && $crow->forAuthors > 1)
	    return $this->canRespond($prow, $crow, $whyNot, $submit);
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if (($prow->timeSubmitted > 0 || $prow->myReviewType > 0)
	    && (($this->privChair && ($forceShow || $prow->conflictType == 0))
		|| ($this->isPC && $prow->conflictType == 0)
		|| $prow->myReviewType > 0)
	    && ($Conf->timeReviewPaper($this->isPC, true, true)
		|| $Conf->setting('cmt_always') > 0
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

    function canSubmitComment($prow, $crow, &$whyNot = null) {
	return $this->canComment($prow, $crow, $whyNot, true);
    }

    function canViewComment($prow, $crow, &$whyNot = null, $ignoreForceShow = false) {
	global $Conf;
	// Policy outline:
	// forAuthors 2, forReviewers 1     response, ready for reviewers
	// forAuthors 2, forReviewers 0     response, not ready for reviewers
	// forAuthors 1/0                   comment for authors/not for authors
	// forAuthors 1/0, forReviewers 2   comment for PC chairs and admins
	// forAuthors 1/0, forReviewers 1   comment for PC, reviewers
	// forAuthors 1/0, forReviewers 0   comment for PC
	// forAuthors 1/0, forReviewers -1  comment for PC, reviewers
	// (now all comments are postrev, before forReviewers>=0 meant anytime)

	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
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
	$forReviewers = (!$crow || $crow->forReviewers == -1 ? 1 : $crow->forReviewers);
	$auview = $this->actAuthorView($prow);
	if ($crow_contactId == $this->contactId		// wrote this comment
	    || ($auview && $crow && $crow->forAuthors == 2) // author's response
	    || ($auview					// author viewable
		&& $Conf->timeAuthorViewReviews()
		&& (!$crow || $crow->forAuthors != 0))
	    || ($this->privChair			// chair privilege
		&& ($prow->conflictType == 0 || $forceShow))
	    || ($prow->conflictType == 0		// reviewer postrev
		&& $this->canViewReview($prow, null, $xWhyNot, $ignoreForceShow)
		&& (!$Conf->setting("pc_seeblindrev")
		    || (defval($prow, "myReviewType") > 0
			&& (defval($prow, "myReviewSubmitted") > 0
			    || defval($prow, "myReviewNeedsSubmit", 1) == 0)))
		&& ($forReviewers == 1 || ($forReviewers == 0 && $this->isPC))))
	    return true;
	// collect failure reasons
	if ((!$auview && !$this->isPC && $prow->myReviewType <= 0)
	    || (!$this->privChair && $forReviewers == 2))
	    $whyNot['permission'] = 1;
	else if ($auview)
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

    function canRespond($prow, $crow, &$whyNot = null, $submit = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = isset($_REQUEST['forceShow']) && $_REQUEST['forceShow'];
	// policy
	if ($prow->timeSubmitted > 0
	    && (($this->privChair && ($forceShow || $prow->conflictType == 0))
		|| $prow->conflictType >= CONFLICT_AUTHOR)
	    && ($Conf->timeAuthorRespond()
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

    function canViewCommentReviewWheres() {
	global $Conf;
	if ($this->privChair
	    || ($this->isPC && $Conf->setting("pc_seeallrev") > 0))
	    return array();
	else
	    return array("(" . $this->actAuthorSql("PaperConflict")
			 . " or MyPaperReview.reviewId is not null)");
    }


    function amPaperAuthor($paperId, $prow = null) {
	global $Conf;
	if ($prow === null) {
	    // Query for a specific match of the author and paper
	    $query = "select paperId from PaperConflict where paperId=$paperId and contactId=$this->contactId and conflictType>=" . CONFLICT_AUTHOR;
	    $result = $Conf->qe($query);
	    return edb_nrows($result) > 0;
	} else
	    return $prow->conflictType >= CONFLICT_AUTHOR;
    }

    function amDiscussionLead($paperId, $prow = null) {
	global $Conf;
	if ($prow === null && $paperId <= 0) {
	    if ($this->isDiscussionLead === null) {
		$result = $Conf->qe("select count(paperId) from Paper where leadContactId=$this->contactId");
		$row = edb_row($result);
		$this->isDiscussionLead = $row && $row[0] > 0;
	    }
	    return $this->isDiscussionLead;
	} else if ($prow === null) {
	    $result = $Conf->qe("select paperId from Paper where paperId=$paperId and leadContactId=$this->contactId");
	    return edb_nrows($result) > 0;
	} else
	    return $prow->leadContactId == $this->contactId;
    }

    function canEditContactAuthors($prow) {
	return ($prow->conflictType >= CONFLICT_AUTHOR || $this->privChair);
    }

    function timeReview($prow, $rrow) {
	global $Conf;
	$myReview = $prow->myReviewType || $prow->reviewId
	    || ($rrow && isset($prow->myReviewId)
		&& $prow->myReviewId == $rrow->reviewId)
	    || ($this->privChair && $rrow && $rrow->contactId != $this->contactId);
	return $Conf->timeReviewPaper($this->isPC, $myReview, true);
    }

    function canViewReviewerIdentity($prow, $rrow, $ignoreForceShow = false) {
	global $Conf;
	$forceShow = isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] && !$ignoreForceShow;
	$rrow_contactId = 0;
	if ($rrow && isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if ($rrow && isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	// If $prow === true, be as permissive as possible: return true
	// iff there could exist a paper for which canViewReviewerIdentity
	// is true.
	if ($prow === true)
	    $prow = (object) array("conflictType" => 0,
			"myReviewType" => ($this->amReviewer() ? 1 : 0),
			"myReviewSubmitted" => 1,
			"paperId" => 1, "timeSubmitted" => 1);
	if (($this->privChair && $forceShow)
	    || ($rrow && $rrow_contactId == $this->contactId)
	    || ($rrow && $this->ownReview($rrow))
	    || ($this->privChair && $prow && $prow->conflictType == 0)
	    || ($this->isPC && $prow && $prow->conflictType == 0
		&& (!($pc_seeblindrev = $Conf->setting("pc_seeblindrev"))
		    || ($pc_seeblindrev == 2
			&& $this->canViewReview($prow, $rrow, $whyNot, $ignoreForceShow))))
	    || ($prow && $prow->myReviewType > 0
		&& (defval($prow, "myReviewSubmitted") > 0
		    || defval($prow, "myReviewNeedsSubmit", 1) == 0)
		&& ($this->isPC || $Conf->settings["extrev_view"] >= 2))
	    || !reviewBlind($rrow))
	    return true;
	return false;
    }

    function canViewCommentIdentity($prow, $crow, $ignoreForceShow = false) {
	global $Conf;
	if ($crow && $crow->forAuthors > 1)
	    return $this->canViewAuthors($prow, false);
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
		&& $Conf->settings["extrev_view"] >= 2)
	    || !commentBlind($crow))
	    return true;
	return false;
    }

    function canViewDecision($prow, $forceShow = false) {
	global $Conf;
	if (($prow && $prow->conflictType >= CONFLICT_AUTHOR && $Conf->timeAuthorViewDecision())
	    || ($this->isPC && $Conf->timePCViewDecision($prow && $prow->conflictType > 0))
	    || ($prow && defval($prow, "myReviewType", 0) > 0
		&& defval($prow, "myReviewSubmitted", 0) > 0
		&& $Conf->timeReviewerViewDecision())
	    || ($this->privChair && isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"])
	    || ($this->privChair && (!$prow || $prow->conflictType == 0))
	    || ($this->privChair && $forceShow))
	    return true;
	return false;
    }

    function viewReviewFieldsScore($prow, $rrow) {
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
	if (($prow && $this->actAuthorView($prow))
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

    function canViewTags($prow, $forceShow = true) {
	global $Conf;
	return ($this->isPC
		&& (!$prow || $prow->conflictType <= 0
		    || ($this->privChair && $forceShow)
		    || $Conf->setting("tag_seeall") > 0));
    }

    function canSetTags($prow, $forceShow = true) {
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


    function deadlines() {
	// Return cleaned deadline-relevant settings that this user can see.
	global $Conf;
	$dlx = $Conf->deadlines();
	$now = $dlx["now"];
	$dl = array("now" => $now);
	foreach (array("sub_open", "resp_open", "rev_open", "final_open") as $x)
	    $dl[$x] = $dlx[$x] > 0;

	if ($dlx["sub_reg"] && $dlx["sub_reg"] != $dlx["sub_update"])
	    $dl["sub_reg"] = $dlx["sub_reg"];
	if ($dlx["sub_update"] && $dlx["sub_update"] != $dlx["sub_sub"])
	    $dl["sub_update"] = $dlx["sub_update"];
	$dl["sub_sub"] = $dlx["sub_sub"];

	$dl["resp_done"] = $dlx["resp_done"];

	$dl["rev_open"] = $dl["rev_open"] && $this->amReviewer();
	if ($this->isPC) {
	    if ($dlx["pcrev_soft"] > $now)
		$dl["pcrev_done"] = $dlx["pcrev_soft"];
	    else if ($dlx["pcrev_hard"]) {
		$dl["pcrev_done"] = $dlx["pcrev_hard"];
		$dl["pcrev_ishard"] = true;
	    }
	}
	if ($this->amReviewer()) {
	    if ($dlx["extrev_soft"] > $now)
		$dl["extrev_done"] = $dlx["extrev_soft"];
	    else if ($dlx["extrev_hard"]) {
		$dl["extrev_done"] = $dlx["extrev_hard"];
		$dl["extrev_ishard"] = true;
	    }
	}

	if ($dl["final_open"]) {
	    if ($dlx["final_soft"] > $now)
		$dl["final_done"] = $dlx["final_soft"];
	    else {
		$dl["final_done"] = $dlx["final_done"];
		$dl["final_ishard"] = true;
	    }
	}

	// mark grace periods
	foreach (array("sub" => array("sub_reg", "sub_update", "sub_sub"),
		       "resp" => array("resp_done"),
		       "rev" => array("pcrev_done", "extrev_done"),
		       "final" => array("final_done")) as $type => $dlnames) {
	    if ($dl["${type}_open"] && ($grace = $dlx["${type}_grace"])) {
		foreach ($dlnames as $dlname)
		    // Give a minute's notice that there will be a grace
		    // period to make the UI a little better.
		    if (defval($dl, $dlname) && $dl[$dlname] + 60 < $now
			&& $dl[$dlname] + $grace >= $now)
			$dl["${dlname}_ingrace"] = true;
	    }
	}

	return $dl;
    }


    function paperStatus($paperId, $row, $long) {
	global $Conf, $paperStatusCache;
	if ($row->timeWithdrawn > 0)
	    return "<span class='pstat pstat_with'>Withdrawn</span>";
	else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1)
	    return "<span class='pstat pstat_noup'>Not uploaded</span>";
	else if (isset($row->outcome) && $row->outcome != 0
		 && $this->canViewDecision($row, abs($long) > 1)) {
	    if (!isset($paperStatusCache) || !$paperStatusCache)
		$paperStatusCache = array();
	    if (!isset($paperStatusCache[$row->outcome])) {
		$data = "<span class=\"pstat "
		    . ($row->outcome > 0 ? "pstat_decyes" : "pstat_decno");

		$rf = reviewForm();
		$decname =& $rf->options["outcome"][$row->outcome];
		if (isset($decname)) {
		    $trdecname = preg_replace('/[^-.\w]/', '', $decname);
		    if ($trdecname != "")
			$data .= " pstat_" . strtolower($trdecname);
		    $data .= "\">" . htmlspecialchars($decname) . "</span>";
		} else
		    $data .= "\">Unknown decision #" . htmlspecialchars($row->outcome) . "</span>";

		$paperStatusCache[$row->outcome] = $data;
	    }
	    return $paperStatusCache[$row->outcome];
	//} else if (isset($row->reviewCount) && $row->reviewCount > 0) {
	//    if ($long < 0 && $row->conflictType < CONFLICT_AUTHOR)
	//	return "";
	//    else if ($this->canViewReview($row, null))
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


    static function generatePassword($length) {
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
		&& ($t = $conf->settingText("rev_roundtag"))) {
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
