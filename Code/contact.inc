<?php
// contact.inc -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Which conference are we dealing with?
    var $confDsn;

    // Information from the SQL definition
    public $contactId;
    private $cid;               // for forward compatibility
    var $visits;
    var $firstName;
    var $lastName;
    var $email;
    var $preferredEmail;
    var $sorter;
    var $affiliation;
    var $collaborators;
    var $voicePhoneNumber;
    var $password;
    public $password_type;
    public $password_plaintext;
    public $disabled;
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


    public function __construct() {
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name == "cid")
            return $this->contactId;
        else
            return null;
    }

    public function __set($name, $value) {
        if ($name == "cid")
            $this->contactId = $value;
        else
            $this->$name = $value;
    }

    static public function make($o) {
	// If you change this function, search for its callers to ensure
	// they provide all necessary information.
	$c = new Contact();
	$c->invalidate();
	$c->contactId = (int) $o->contactId;
	$c->firstName = defval($o, "firstName", "");
	$c->lastName = defval($o, "lastName", "");
	$c->email = defval($o, "email", "");
	$c->preferredEmail = defval($o, "preferredEmail", "");
        $c->sorter = trim("$c->firstName $c->lastName $c->email");
	$c->password = defval($o, "password", "");
        $c->password_type = (substr($c->password, 0, 1) == " " ? 1 : 0);
        if ($c->password_type == 0)
            $c->password_plaintext = $c->password;
        $c->disabled = !!defval($o, "disabled", false);
	$c->isAuthor = defval($o, "isAuthor", false) != false;
	$c->isReviewer = defval($o, "isReviewer", false) != false;
	$c->reviewsOutstanding = defval($o, "reviewsOutstanding", false) != false;
	$c->roles = defval($o, "roles", 0);
	if (defval($o, "isPC", false))
	    $c->roles |= self::ROLE_PC;
	if (defval($o, "isAssistant", false))
	    $c->roles |= self::ROLE_ADMIN;
	if (defval($o, "isChair", false))
	    $c->roles |= self::ROLE_CHAIR;
	$c->isPC = ($c->roles & (self::ROLE_PC | self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
	$c->privChair = ($c->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
        $c->contactTags = defval($o, "contactTags", null);
	return $c;
    }


    //
    // Initialization functions
    //

    function valid($update = false) {
	global $Conf, $Opt;
	if (!$this->contactId)		// null contactId causes problems
	    $this->contactId = 0;
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
		    if (isset($_REQUEST["invalidatecaches"])
			&& $_REQUEST["invalidatecaches"])
			$Conf->invalidateCaches();
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
		       "affiliation", "voicePhoneNumber",
		       "password", "disabled", "collaborators") as $k)
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

    function changeCapability($pid, $c, $on) {
	global $CapabilitiesOK;
	$newcap = defval($this, "capabilities", array());
	$v = (defval($newcap, $pid, 0) | ($on ? $c : 0)) & ~($on ? 0 : $c);
	if ($v)
	    $newcap[$pid] = $v;
	else
	    unset($newcap[$pid]);
	if (count($newcap))
	    $this->capabilities = $newcap;
	else
	    unset($this->capabilities);
	if ($on)
	    $CapabilitiesOK = true;
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
		       "voicePhoneNumber", "note",
		       "addressLine1", "addressLine2", "city", "state",
		       "zipCode", "country")
		 as $k)
	    if ($this->$k)
		$this->$k = trim($this->$k);
        $this->sorter = trim("$this->firstName $this->lastName $this->email");
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
	    foreach ($rf->fmap as $field => $f)
		if (isset($_REQUEST[$field]))
		    $x[$field] = $_REQUEST[$field];
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
	if ($this->preferredEmail != "")
	    $qa .= ", preferredEmail='" . sqlq($this->preferredEmail) . "'";
	if ($Conf->sversion >= 35) {
	    if ($this->contactTags)
		$qa .= ", contactTags='" . sqlq($this->contactTags) . "'";
	    else
		$qa .= ", contactTags=null";
	}
        if ($Conf->sversion >= 47)
            $qa .= ", disabled=" . ($this->disabled ? 1 : 0);
	$query = sprintf("update ContactInfo set firstName='%s', lastName='%s',
		email='%s', affiliation='%s', voicePhoneNumber='%s',
		password='%s', collaborators='%s'$qa
		where contactId='%s'",
			 sqlq($this->firstName), sqlq($this->lastName),
			 sqlq($this->email), sqlq($this->affiliation),
			 sqlq($this->voicePhoneNumber),
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

    function email_authored_papers($email, $update) {
        global $Conf;
        $aupapers = array();
        $result = $Conf->q("select paperId, authorInformation from Paper where authorInformation like '%	" . sqlq_for_like($email) . "	%'");
	while (($row = edb_orow($result))) {
	    cleanAuthor($row);
	    foreach ($row->authorTable as $au)
		if (strcasecmp($au[2], $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($update && $this->firstName == "" && $au[0])
                        $this->firstName = $au[0];
                    if ($update && $this->lastName == "" && $au[1])
                        $this->lastName = $au[1];
                    if ($update && $this->affiliation == "" && $au[3])
                        $this->affiliation = $au[3];
                    break;
                }
        }
        return $aupapers;
    }

    function save_authored_papers($aupapers) {
        global $Conf;
        if (count($aupapers)) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            $Conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    function initialize($email, $useRequest = false, $useRequestPassword = false) {
	global $Conf, $Opt;
	$this->email = trim($email);
	if (!$useRequest || !$useRequestPassword || !isset($_REQUEST["password"])
	    || ($password = trim($_REQUEST["password"])) == "")
	    $password = self::random_password();
        $this->password_type = defval($Opt, "safePasswords") ? 1 : 0;
        $this->change_password($password);

	// update paper authorship based on authorInformation fields
	// (ignore errors)
	$bestEmail = $this->email;
	if ($useRequest && isset($_REQUEST["preferredEmail"]))
	    $bestEmail = $_REQUEST["preferredEmail"];
	$addAuthor = $this->email_authored_papers($bestEmail, true);

	$qa = "email, password, creationTime";
	$qb = "'" . sqlq($this->email) . "', '" . sqlq($this->password) . "', " . time();
	foreach (array("firstName", "lastName", "affiliation", "collaborators",
		       "voicePhoneNumber", "preferredEmail")
		 as $k) {
	    if ($useRequest && isset($_REQUEST[$k])
		&& defval($this, $k, "") == "")
		$this->$k = trim($_REQUEST[$k]);
	    if (isset($this->$k) && $this->$k) {
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

        $this->contactId = $contactId;
	if (count($addAuthor))
            $this->save_authored_papers($addAuthor);
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
            $this->sorter = trim("$this->firstName $this->lastName $this->email");
	    $this->affiliation = $fromdb->affiliation;
	    $this->voicePhoneNumber = $fromdb->voicePhoneNumber;
	    $this->password = $fromdb->password;
            $this->password_type = (substr($this->password, 0, 1) == " " ? 1 : 0);
            if ($this->password_type == 0)
                $this->password_plaintext = $this->password;
            $this->disabled = !!defval($fromdb, "disabled", 0);
	    $this->note = $fromdb->note;
	    $this->collaborators = $fromdb->collaborators;
	    $this->defaultWatch = defval($fromdb, "defaultWatch", 0);
	    $this->contactTags = defval($fromdb, "contactTags", null);

	    $this->confDsn = $Opt["dsn"];

	    $this->trim();
	    $this->validated = false;
	    return true;
	} else {
	    // Not found - zero out the context
	    $this->invalidate();
	    return false;
	}
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

    static public function override_deadlines() {
        return isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    static public function override_conflict($forceShow = null) {
        if ($forceShow === null)
            return isset($_REQUEST["forceShow"]) && $_REQUEST["forceShow"] > 0;
        else
            return $forceShow;
    }

    public function allowAdminister($prow) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return $this->privChair
            || ($prow && $prow->managerContactId && $prow->managerContactId == $this->contactId);
    }

    public function canAdminister($prow, $forceShow = null) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return ($this->privChair
                || ($prow && $prow->managerContactId
                    && $prow->managerContactId == $this->contactId))
            && (!$prow || $prow->conflictType <= 0
                || self::override_conflict($forceShow));
    }

    public function actPC($prow, $forceShow = null) {
        if ($prow && $prow->conflictType > 0 && $prow->managerContactId
            && $prow->managerContactId != $this->contactId)
            return false;
        return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || (($this->privChair || $prow->managerContactId == $this->contactId)
                    && self::override_conflict($forceShow)));
    }

    public function actConflictType($prow) {
	global $Conf;
	// If an author-view capability is set, then use it -- unless this
	// user is a PC member or reviewer, which takes priority.
	if (isset($this->capabilities)
	    && isset($this->capabilities[$prow->paperId])
	    && ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW)
	    && !$this->isPC
	    && $prow->myReviewType <= 0)
	    return CONFLICT_AUTHOR;
	return $prow->conflictType;
    }

    public function actAuthorView($prow, $download = false) {
	return $this->actConflictType($prow) >= CONFLICT_AUTHOR;
    }

    public function actAuthorSql($table, $only_if_complex = false) {
	$m = array("$table.conflictType>=" . CONFLICT_AUTHOR);
	if (isset($this->capabilities) && !$this->isPC)
	    foreach ($this->capabilities as $pid => $cap)
		if ($cap & Contact::CAP_AUTHORVIEW)
		    $m[] = "Paper.paperId=$pid";
	if (count($m) > 1)
	    return "(" . join(" or ", $m) . ")";
	else
	    return $only_if_complex ? false : $m[0];
    }

    function canStartPaper(&$whyNot = null) {
	global $Conf;
	$whyNot = array();
	if ($Conf->timeStartPaper()
            || ($this->privChair && self::override_deadlines()))
	    return true;
	$whyNot["deadline"] = "sub_reg";
	if ($this->privChair)
	    $whyNot["override"] = 1;
	return false;
    }

    function canEditPaper($prow, &$whyNot = null) {
	return $prow->conflictType >= CONFLICT_AUTHOR
            || $this->allowAdminister($prow);
    }

    function canUpdatePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeUpdatePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0 && $Conf->setting('sub_freeze') > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeUpdatePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "sub_update";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canFinalizePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
	    && ($Conf->timeFinalizePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->timeSubmitted > 0)
	    $whyNot["updateSubmitted"] = 1;
	if (!$Conf->timeFinalizePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "finalizePaperSubmission";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canWithdrawPaper($prow, &$whyNot = null, $override = false) {
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
        $override = $admin && ($override || self::override_deadlines());
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn <= 0
            && ($override || $prow->outcome == 0))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
        else if ($prow->outcome != 0 && !$override)
            $whyNot["decided"] = 1;
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canRevivePaper($prow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $prow->timeWithdrawn > 0
	    && ($Conf->timeUpdatePaper($prow)
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn <= 0)
	    $whyNot["notWithdrawn"] = 1;
	if (!$Conf->timeUpdatePaper($prow)
            && !($admin && self::override_deadlines()))
	    $whyNot["deadline"] = "sub_update";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canSubmitFinalPaper($prow, &$whyNot = null, $override = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	$override = $admin && ($override || self::override_deadlines());
	// policy
	if (($prow->conflictType >= CONFLICT_AUTHOR || $admin)
	    && $Conf->collectFinalPapers()
	    && $prow->timeWithdrawn <= 0 && $prow->outcome > 0
	    && ($Conf->timeSubmitFinalPaper() || $override))
	    return true;
	// collect failure reasons
	if ($prow->conflictType < CONFLICT_AUTHOR && !$admin)
	    $whyNot["author"] = 1;
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	// NB logic order here is important elsewhere
	if (!$Conf->collectFinalPapers()
	    || (!$Conf->timeAuthorViewDecision() && !$override))
	    $whyNot["deadline"] = "final_open";
	else if ($prow->outcome <= 0)
	    $whyNot["notAccepted"] = 1;
	else if (!$Conf->timeSubmitFinalPaper() && !$override)
	    $whyNot["deadline"] = "final_done";
	if ($admin)
	    $whyNot["override"] = 1;
	return false;
    }

    function canViewPaper($prow, &$whyNot = null, $download = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($conflictType >= CONFLICT_AUTHOR
	    || $admin
	    || ($prow->myReviewType > 0 && $Conf->timeReviewerViewSubmittedPaper())
	    || ($this->isPC && $Conf->timePCViewPaper($prow, $download)))
	    return true;
	// collect failure reasons
	if (!$this->isPC && $conflictType < CONFLICT_AUTHOR
	    && $prow->myReviewType <= 0) {
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

    function canViewPaperManager($prow) {
        global $Opt;
        return $this->privChair
            || ($prow && $prow->managerContactId == $this->contactId)
            || (($this->isPC || ($prow && $prow->myReviewType > 0))
                && defval($Opt, "hideManager", false));
    }

    function allowViewAuthors($prow, &$whyNot = null) {
        return $this->canViewAuthors($prow, true, $whyNot);
    }

    function canViewAuthors($prow, $forceShow = null, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	// policy
	$conflictType = $this->actConflictType($prow);
        $bs = $Conf->setting("sub_blind");
        $nonblind = ((($this->isPC || $prow->myReviewType > 0)
                      && $Conf->timeReviewerViewAcceptedAuthors()
                      && $prow->outcome > 0)
                     || $bs == BLIND_NEVER
                     || ($bs == BLIND_OPTIONAL
                         && !(isset($prow->paperBlind) ? $prow->paperBlind : $prow->blind))
                     || ($bs == BLIND_UNTILREVIEW
                         && defval($prow, "myReviewSubmitted")));
        if (($nonblind && $prow->timeSubmitted > 0
             && ($this->isPC
                 || ($prow->myReviewType > 0
                     && $Conf->timeReviewerViewSubmittedPaper())))
            || ($nonblind && $prow->timeWithdrawn <= 0 && $this->isPC
                && $Conf->setting("pc_seeall") > 0)
            || ($this->privChair || $this->allowAdminister($prow)
                ? self::override_conflict($forceShow)
                : $conflictType >= CONFLICT_AUTHOR))
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

    function canViewPaperOption($prow, $opt, $forceShow = null,
                                &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	if (!is_object($opt) && !($opt = paperOptions($opt))) {
	    $whyNot["invalidId"] = "paper";
	    return false;
	}
        $admin = $this->privChair || $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if (!$this->canViewPaper($prow, $whyNot))
	    return false;	// $whyNot already set
        if ($conflictType >= CONFLICT_AUTHOR
            || (($admin || $this->isPC || ($prow && $prow->myReviewType > 0))
                && (($opt->pcView == 0 && $admin)
                    || $opt->pcView == 1
                    || ($opt->pcView == 2
                        && $this->canViewAuthors($prow, $forceShow)))))
            return true;
	$whyNot["permission"] = 1;
	return false;
    }

    function ownReview($rrow) {
	global $Conf;
	if (!$rrow || !$rrow->reviewId)
	    return false;
	$rrow_contactId = 0;
	if (isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if (isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	return $rrow_contactId == $this->contactId
	    || (isset($_SESSION["rev_tokens"]) && array_search($rrow->reviewToken, $_SESSION["rev_tokens"]) !== false)
	    || ($rrow->requestedBy == $this->contactId && $rrow->reviewType == REVIEW_EXTERNAL && $Conf->setting("pcrev_editdelegate"));
    }

    public function canCountReview($prow, $rrow, $forceShow) {
        if ($rrow && $rrow->reviewNeedsSubmit <= 0
            && $rrow->reviewSubmitted <= 0)
            return false;
        else if ($this->isPC && (!$prow || $this->actConflictType($prow) == 0))
            return true;
        else
            return $this->canViewReview($prow, $rrow, $forceShow);
    }

    function canViewReview($prow, $rrow, $forceShow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $forceShow = self::override_conflict($forceShow);
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else
            $viewscore = VIEWSCORE_AUTHOR;
	$rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
	$pc_seeallrev = ($this->isPC ? $Conf->setting("pc_seeallrev") : 0);
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($admin && $forceShow)
	    return true;
	if (($prow->timeSubmitted > 0
	     || defval($prow, "myReviewType") > 0
	     || $admin)
	    && (($conflictType >= CONFLICT_AUTHOR
		 && $Conf->timeAuthorViewReviews($this->reviewsOutstanding && $this->isReviewer)
		 && $rrowSubmitted
                 && $viewscore >= VIEWSCORE_AUTHOR)
		|| ($admin
                    && $conflictType == 0)
		|| ($this->isPC
		    && $conflictType == 0 && $rrowSubmitted
		    && $pc_seeallrev > 0	// see also timePCViewAllReviews()
		    && ($pc_seeallrev != 4 || !$this->reviewsOutstanding)
		    && ($pc_seeallrev != 3 || !defval($prow, "myReviewType"))
                    && $viewscore >= VIEWSCORE_PC)
		|| (defval($prow, "myReviewType") > 0
		    && $conflictType == 0
                    && $rrowSubmitted
		    && (defval($prow, "myReviewSubmitted") > 0
			|| defval($prow, "myReviewNeedsSubmit", 1) == 0)
		    && ($this->isPC || $Conf->settings["extrev_view"] >= 1)
                    && $viewscore >= VIEWSCORE_PC)
		|| ($rrow
                    && $rrow->paperId == $prow->paperId
		    && $this->ownReview($rrow)
                    && $viewscore >= VIEWSCORE_REVIEWERONLY)))
	    return true;
	// collect failure reasons
	if ($prow->timeWithdrawn > 0)
	    $whyNot["withdrawn"] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot["notSubmitted"] = 1;
	else if ($conflictType < CONFLICT_AUTHOR && !$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR && $Conf->timeAuthorViewReviews()
		 && $this->reviewsOutstanding && $this->isReviewer)
	    $whyNot['reviewsOutstanding'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR && !$rrowSubmitted)
	    $whyNot['permission'] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR)
	    $whyNot['deadline'] = 'au_seerev';
	else if ($conflictType > 0)
	    $whyNot['conflict'] = 1;
	else if ($prow->myReviewType > 0 && !$this->isPC && $prow->myReviewSubmitted > 0)
	    $whyNot['externalReviewer'] = 1;
	else if (!$rrowSubmitted)
	    $whyNot['reviewNotSubmitted'] = 1;
	else if ($this->isPC && $pc_seeallrev == 4 && $this->reviewsOutstanding)
	    $whyNot["reviewsOutstanding"] = 1;
	else if (!$Conf->timeReviewOpen())
	    $whyNot['deadline'] = "rev_open";
	else {
	    $whyNot['reviewNotComplete'] = 1;
	    if (!$Conf->timeReviewPaper($this->isPC, true, true))
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	}
	if ($admin)
	    $whyNot['forceShow'] = 1;
	return false;
    }

    function canRequestReview($prow, $time, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->myReviewType >= REVIEW_SECONDARY || $admin)
	    && ($Conf->timeReviewPaper(false, true, true) || !$time
                || ($admin && self::override_deadlines())))
	    return true;
	// collect failure reasons
	if ($prow->myReviewType < REVIEW_SECONDARY)
	    $whyNot['permission'] = 1;
	else {
	    $whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($admin)
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
        $forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	$manager = $this->canAdminister($prow);
	$rrow_contactId = 0;
	if ($rrow) {
	    $myReview = $manager || $this->ownReview($rrow);
	    if (isset($rrow->reviewContactId))
		$rrow_contactId = $rrow->reviewContactId;
	    else if (isset($rrow->contactId))
		$rrow_contactId = $rrow->contactId;
	} else
	    $myReview = $prow->myReviewType > 0;
	// policy
	if (($prow->timeSubmitted > 0 || $myReview
	     || ($manager && $forceShow))
	    && (($this->isPC && $prow->conflictType == 0 && !$rrow)
		|| $myReview || ($manager && $forceShow))
	    && ($Conf->timeReviewPaper($this->isPC, $myReview, true)
		|| ($manager && (!$submit || self::override_deadlines()))))
	    return true;
	// collect failure reasons
	// The "reviewNotAssigned" and "deadline" failure reasons are special.
	// If either is set, the system will still allow review form download.
	if ($rrow && $rrow_contactId != $this->contactId && !$admin)
	    $whyNot['differentReviewer'] = 1;
	else if (!$this->isPC && $prow->myReviewType <= 0)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    if ($prow->conflictType > 0 && !($manager && $forceShow))
		$whyNot['conflict'] = 1;
	    else if ($this->isPC && $prow->myReviewType <= 0
		     && !$Conf->timeReviewPaper(true, false, true)
		     && (!$rrow || $rrow_contactId == $this->contactId))
		$whyNot['reviewNotAssigned'] = 1;
	    else
		$whyNot['deadline'] = ($this->isPC ? "pcrev_hard" : "extrev_hard");
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
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
        // load comment type
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
	// check whether this is a response
	if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
	    return $this->canRespond($prow, $crow, $whyNot, $submit);
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	// policy
	if (($prow->timeSubmitted > 0 || $prow->myReviewType > 0)
	    && (($admin && ($forceShow || $prow->conflictType == 0))
		|| ($this->isPC && $prow->conflictType == 0)
		|| $prow->myReviewType > 0)
	    && ($Conf->timeReviewPaper($this->isPC, true, true)
		|| $Conf->setting('cmt_always') > 0
		|| ($admin && (!$submit || self::override_deadlines())))
	    && (!$crow
		|| $crow->contactId == $this->contactId
		|| $admin))
	    return true;
	// collect failure reasons
	if ($crow && $crow->contactId != $this->contactId && !$admin)
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
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
		$whyNot['override'] = 1;
	}
	return false;
    }

    function canSubmitComment($prow, $crow, &$whyNot = null) {
	return $this->canComment($prow, $crow, $whyNot, true);
    }

    function canViewComment($prow, $crow, $forceShow, &$whyNot = null) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
        $forceShow = self::override_conflict($forceShow);
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_AUTHOR;
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	if ($crow && isset($crow->threadContacts)
	    && isset($crow->threadContacts[$this->contactId]))
	    $thread_contactId = $this->contactId;
        $admin = $this->allowAdminister($prow);
	$conflictType = $this->actConflictType($prow);
	// policy
	if ($crow_contactId == $this->contactId		// wrote this comment
	    || ($conflictType >= CONFLICT_AUTHOR	// author
                && $ctype >= COMMENTTYPE_AUTHOR
                && (($ctype & COMMENTTYPE_RESPONSE)	// author's response
                    || ($Conf->timeAuthorViewReviews()  // author-visible cmt
                        && !($ctype & COMMENTTYPE_DRAFT))))
            || ($admin					// chair privilege
                && ($conflictType == 0 || $forceShow))
            || ($conflictType == 0
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->canViewReview($prow, null, $forceShow)
                && (($this->isPC && !$Conf->setting("pc_seeblindrev"))
                    || (defval($prow, "myReviewType") > 0
			&& (defval($prow, "myReviewSubmitted") > 0
			    || defval($prow, "myReviewNeedsSubmit", 1) == 0)))
                && $ctype >= ($this->isPC ? COMMENTTYPE_PCONLY : COMMENTTYPE_REVIEWER)))
	    return true;
	// collect failure reasons
	if (($conflictType < CONFLICT_AUTHOR
	     && !$this->isPC && $prow->myReviewType <= 0)
	    || (!$admin && ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_ADMINONLY))
	    $whyNot["permission"] = 1;
	else if ($conflictType >= CONFLICT_AUTHOR)
	    $whyNot["deadline"] = 'au_seerev';
	else if ($conflictType > 0)
	    $whyNot["conflict"] = 1;
	else if ($prow->myReviewType > 0 && !$this->isPC && defval($prow, "myReviewSubmitted") > 0)
	    $whyNot["externalReviewer"] = 1;
	else if ($ctype & COMMENTTYPE_DRAFT)
	    $whyNot["responseNotReady"] = 1;
	else
	    $whyNot["reviewNotComplete"] = 1;
	if ($admin)
	    $whyNot["forceShow"] = 1;
	return false;
    }

    function canRespond($prow, $crow, &$whyNot = null, $submit = false) {
	global $Conf;
	// fetch paper
	if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
	    return false;
	$forceShow = self::override_conflict();
        $admin = $this->allowAdminister($prow);
	// policy
	if ($prow->timeSubmitted > 0
	    && (($admin && ($forceShow || $prow->conflictType == 0))
		|| $prow->conflictType >= CONFLICT_AUTHOR)
	    && ($Conf->timeAuthorRespond()
		|| ($admin && (!$submit || self::override_deadlines())))
	    && (!$crow || ($crow->commentType & COMMENTTYPE_RESPONSE)))
	    return true;
	// collect failure reasons
	if (!$admin && $prow->conflictType < CONFLICT_AUTHOR)
	    $whyNot['permission'] = 1;
	else if ($prow->timeWithdrawn > 0)
	    $whyNot['withdrawn'] = 1;
	else if ($prow->timeSubmitted <= 0)
	    $whyNot['notSubmitted'] = 1;
	else {
	    $whyNot['deadline'] = "resp_done";
	    if ($admin && $prow->conflictType > 0)
		$whyNot['chairMode'] = 1;
	    if ($admin && isset($whyNot['deadline']))
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
	return $prow->conflictType >= CONFLICT_AUTHOR
            || $this->allowAdminister($prow);
    }

    function timeReview($prow, $rrow) {
	global $Conf;
	$myReview = $prow->myReviewType || $prow->reviewId
            || ($rrow && $this->ownReview($rrow))
	    || ($rrow && $rrow->contactId != $this->contactId
                && $this->allowAdminister($prow));
	return $Conf->timeReviewPaper($this->isPC, $myReview, true);
    }

    function canViewReviewerIdentity($prow, $rrow, $forceShow = null) {
	global $Conf;
        $forceShow = self::override_conflict($forceShow);
	$rrow_contactId = 0;
	if ($rrow && isset($rrow->reviewContactId))
	    $rrow_contactId = $rrow->reviewContactId;
	else if ($rrow && isset($rrow->contactId))
	    $rrow_contactId = $rrow->contactId;
	// If $prow === true, be as permissive as possible: return true
	// iff there could exist a paper for which canViewReviewerIdentity
	// is true.
	if ($prow === true)
	    $prow = (object) array("conflictType" => 0, "managerContactId" => 0,
			"myReviewType" => ($this->amReviewer() ? 1 : 0),
			"myReviewSubmitted" => 1,
			"paperId" => 1, "timeSubmitted" => 1);
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
	if (($admin && $forceShow)
	    || ($rrow && $rrow_contactId == $this->contactId)
	    || ($rrow && $this->ownReview($rrow))
	    || ($admin && $prow && $conflictType == 0)
	    || ($this->isPC && $prow && $conflictType == 0
		&& (!($pc_seeblindrev = $Conf->setting("pc_seeblindrev"))
		    || ($pc_seeblindrev == 2
			&& $this->canViewReview($prow, $rrow, $forceShow))))
	    || ($prow && $prow->myReviewType > 0
		&& (defval($prow, "myReviewSubmitted") > 0
		    || defval($prow, "myReviewNeedsSubmit", 1) == 0)
		&& ($this->isPC || $Conf->settings["extrev_view"] >= 2))
	    || !reviewBlind($rrow))
	    return true;
	return false;
    }

    function canViewDiscussionLead($prow, $forceShow) {
        if ($prow === null || $prow === true)
            return $this->canViewReviewerIdentity(true, null, $forceShow);
        $forceShow = self::override_conflict($forceShow);
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
        return ($admin && ($conflictType == 0 || $forceShow))
            || $prow->leadContactId == $this->contactId
            || (($this->isPC || $prow->myReviewType > 0)
                && $conflictType == 0
                && $this->canViewReviewerIdentity($prow, null, $forceShow));
    }

    function canViewCommentIdentity($prow, $crow, $forceShow) {
	global $Conf;
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
	if ($crow->commentType & COMMENTTYPE_RESPONSE)
	    return $this->canViewAuthors($prow, $forceShow);
        $forceShow = self::override_conflict($forceShow);
	$crow_contactId = 0;
	if ($crow && isset($crow->commentContactId))
	    $crow_contactId = $crow->commentContactId;
	else if ($crow)
	    $crow_contactId = $crow->contactId;
	$conflictType = $this->actConflictType($prow);
        $admin = $this->allowAdminister($prow);
	if (($admin && $forceShow)
	    || $crow_contactId == $this->contactId
	    || ($this->isPC && $conflictType == 0)
	    || ($prow->myReviewType > 0 && $conflictType == 0
		&& $Conf->settings["extrev_view"] >= 2)
            || ($br = $Conf->blindReview()) == BLIND_NEVER
            || ($br == BLIND_OPTIONAL && $crow
                && !($crow->commentType & COMMENTTYPE_BLIND)))
	    return true;
	return false;
    }

    function canViewDecision($prow, $forceShow = null) {
	global $Conf;
	$conflictType = $prow ? $this->actConflictType($prow) : 0;
        if ($this->canAdminister($prow, $forceShow)
            || ($prow && $conflictType >= CONFLICT_AUTHOR
                && $Conf->timeAuthorViewDecision())
            || ($this->isPC
                && $Conf->timePCViewDecision($prow && $conflictType > 0))
	    || ($prow && defval($prow, "myReviewType", 0) > 0
		&& defval($prow, "myReviewSubmitted", 0) > 0
		&& $Conf->timeReviewerViewDecision()))
	    return true;
	return false;
    }

    function viewReviewFieldsScore($prow, $rrow) {
	// Returns the maximum authorView score for an invisible review
	// field.  Values for authorView are:
	//   VIEWSCORE_ADMINONLY     -2   admin can view
	//   VIEWSCORE_REVIEWERONLY  -1   admin and review author can view
	//   VIEWSCORE_PC             0   admin and PC/any reviewer can view
	//   VIEWSCORE_AUTHOR         1   admin and PC/any reviewer and author can view
	// So returning -3 means all scores are visible.
	// Deadlines are not considered.
	// (!$prow && !$rrow) ==> return best case scores that can be seen.
	// (!$prow &&  $rrow) ==> return worst case scores that can be seen.
        // ** See also canViewReview.

	// chair can see everything
	if ($this->canAdminister($prow))
	    return VIEWSCORE_ADMINONLY - 1;

	// author can see author information
	if (($prow && $this->actConflictType($prow) >= CONFLICT_AUTHOR)
	    || (!$prow && !$this->amReviewer()))
	    return VIEWSCORE_AUTHOR - 1;

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
	    return VIEWSCORE_REVIEWERONLY - 1;

	// otherwise, can see information visible for all reviewers
	return VIEWSCORE_PC - 1;
    }

    function canViewTags($prow, $forceShow = null) {
        // see also PaperActions::all_tags
	global $Conf;
	return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || $this->canAdminister($prow, $forceShow)
                || $Conf->setting("tag_seeall") > 0);
    }

    function canSetTags($prow, $forceShow = null) {
	return $this->isPC
            && (!$prow || $prow->conflictType <= 0
                || $this->canAdminister($prow, $forceShow));
    }

    function canSetOutcome($prow) {
        return $this->canAdminister($prow);
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
	    return "<span class='pstat pstat_noup'>No submission</span>";
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
	//    else if ($this->canViewReview($row, null, null))
	//	return "<span class='pstat pstat_rev'>Reviews&nbsp;available</span>";
	//    else
	//	return "<span class='pstat pstat_rev'>Under&nbsp;review</span>";
	} else {
	    if ($row->timeSubmitted > 0) {
		if ($long < 0)
		    return "";
		$x = "<span class='pstat pstat_sub'>Submitted</span>";
	    } else
		$x = "<span class='pstat pstat_prog'>Not ready</span>";
	    return $x;
	}
    }


    public static function password_hmac_key($keyid, $create) {
        global $Conf, $Opt;
        if ($keyid === null)
            $keyid = defval($Opt, "passwordHmacKeyid", 0);
        if ($keyid == 0 && isset($Opt["passwordHmacKey"]))
            $key = $Opt["passwordHmacKey"];
        else if (isset($Opt["passwordHmacKey.$keyid"]))
            $key = $Opt["passwordHmacKey.$keyid"];
        else {
            $key = $Conf->settingText("passwordHmacKey.$keyid", "");
            if ($key == "" && $create) {
                $key = hotcrp_random_bytes(24);
                $Conf->save_setting("passwordHmacKey.$keyid", time(), $key);
            }
        }
        if ($create)
            return array($keyid, $key);
        else
            return $key;
    }

    public function check_password($password) {
        global $Conf, $Opt;
        assert(!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]));
        if ($password == "")
            return false;
        if ($this->password_type == 0)
            return $password == $this->password;
        if ($this->password_type == 1
            && ($hash_method_pos = strpos($this->password, " ", 1)) !== false
            && ($keyid_pos = strpos($this->password, " ", $hash_method_pos + 1)) !== false
            && strlen($this->password) > $keyid_pos + 17
            && function_exists("hash_hmac")) {
            $hash_method = substr($this->password, 1, $hash_method_pos - 1);
            $keyid = substr($this->password, $hash_method_pos + 1, $keyid_pos - $hash_method_pos - 1);
            $salt = substr($this->password, $keyid_pos + 1, 16);
            return hash_hmac($hash_method, $salt . $password,
                             self::password_hmac_key($keyid, false), true)
                == substr($this->password, $keyid_pos + 17);
        } else if ($this->password_type == 1)
            error_log("cannot check hashed password for user " . $this->email);
        return false;
    }

    static public function password_hash_method() {
        global $Opt;
        if (isset($Opt["passwordHashMethod"]) && $Opt["passwordHashMethod"])
            return $Opt["passwordHashMethod"];
        else
            return PHP_INT_SIZE == 8 ? "sha512" : "sha256";
    }

    public function password_needs_upgrade() {
        global $Opt;
        if (isset($Opt["safePasswords"]) && is_int($Opt["safePasswords"])
            && $Opt["safePasswords"] > 1) {
            $expected_prefix = " " . self::password_hash_method()
                . " " . defval($Opt, "passwordHmacKeyid", 0) . " ";
            return $this->password_type == 0
                || ($this->password_type == 1 && !str_starts_with($this->password, $expected_prefix));
        } else
            return false;
    }

    public function change_password($new_password) {
        global $Conf, $Opt;
        $this->password_plaintext = $new_password;
        if ($this->password_needs_upgrade())
            $this->password_type = 1;
        if ($this->password_type == 1 && function_exists("hash_hmac")) {
            list($keyid, $key) = self::password_hmac_key(null, true);
            $hash_method = self::password_hash_method();
            $salt = hotcrp_random_bytes(16);
            $this->password = " " . $hash_method . " " . $keyid . " " . $salt
                . hash_hmac($hash_method, $salt . $new_password, $key, true);
        } else {
            $this->password = $new_password;
            $this->password_type = 0;
        }
    }

    static function random_password($length = 16) {
	global $Opt;
	if (isset($Opt["ldapLogin"]))
	    return "<stored in LDAP>";
	else if (isset($Opt["httpAuthLogin"]))
	    return "<using HTTP authentication>";

        // see also regexp in randompassword.php
	$l = explode(" ", "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =");
	$n = count($l);

	$bytes = hotcrp_random_bytes($length + 10, true);
	if (!$bytes) {
	    $bytes = "";
	    while (strlen($bytes) < $length)
		$bytes .= sha1($Opt["conferenceKey"] . pack("V", mt_rand()));
	}

	$pw = "";
        $nvow = 0;
	for ($i = 0;
             $i < strlen($bytes) &&
                 strlen($pw) < $length + max(0, ($nvow - 3) / 3);
             ++$i) {
            $x = ord($bytes[$i]) % $n;
            if ($x < 30)
                ++$nvow;
	    $pw .= $l[$x];
        }
	return $pw;
    }

    function sendAccountInfo($create, $sensitive) {
	global $Conf;
        $rest = array();
        if ($create)
            $template = "@createaccount";
        else if ($this->password_type == 0)
            $template = "@accountinfo";
        else {
            $rest["capability"] = $Conf->create_capability(CAPTYPE_RESETPASSWORD, array("contactId" => $this->contactId, "timeExpires" => time() + 259200));
            $Conf->log("Created password reset request", $this);
            $template = "@resetpassword";
        }

	$prep = Mailer::prepareToSend($template, null, $this, null, $rest);
	if ($prep["allowEmail"] || !$sensitive) {
            Mailer::sendPrepared($prep);
	    return $template;
	} else {
	    $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
	    return false;
	}
    }


    function assignPaper($paperId, $row, $reviewer, $type, $when) {
	global $Conf, $reviewTypeName;
	$reviewerContactId = (is_object($reviewer) ? $reviewer->contactId : $reviewer);
	if ($type == 0 && $row && $row->reviewModified
	    && ($row->reviewType == REVIEW_EXTERNAL || $row->reviewType == REVIEW_PC))
	    return;
	$qtag = "";
	if ($type > 0 && (!$row || !$row->reviewType)) {
	    $qa = $qb = "";
	    if (($type == REVIEW_PRIMARY || $type == REVIEW_SECONDARY)
		&& ($t = $Conf->settingText("rev_roundtag"))) {
		if (!($k = array_search($t, $Conf->settings["rounds"]))) {
		    $rounds = $Conf->settingText("tag_rounds", "");
		    $rounds = ($rounds ? "$rounds$t " : " $t ");
		    $Conf->qe("insert into Settings (name, value, data) values ('tag_rounds', 1, '" . sqlq($rounds) . "') on duplicate key update data='" . sqlq($rounds) . "'");
		    $Conf->settings["tag_rounds"] = 1;
		    $Conf->settingTexts["tag_rounds"] = $rounds;
		    $Conf->settings["rounds"][] = $t;
		    $k = array_search($t, $Conf->settings["rounds"]);
		}
		$qa .= ", reviewRound";
		$qb .= ", $k";
	    }
	    if ($Conf->sversion >= 46) {
		$qa .= ", timeRequested";
		$qb .= ", " . $when;
	    }
	    $q = "insert into PaperReview (paperId, contactId, reviewType, requestedBy$qa) values ($paperId, $reviewerContactId, $type, $this->contactId$qb)";
	} else if ($type > 0 && $row->reviewType != $type)
	    $q = "update PaperReview set reviewType=$type where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType && !$row->reviewModified)
	    $q = "delete from PaperReview where reviewId=$row->reviewId";
	else if ($type <= 0 && $row && $row->reviewType)
	    $q = "update PaperReview set reviewType=" . REVIEW_PC . " where reviewId=$row->reviewId";
	else
	    return;

	if ($Conf->qe($q, "while assigning review")) {
	    if ($qtag)
		$Conf->q($qtag);
	    if ($row && defval($row, "reviewToken", 0) != 0 && $type <= 0)
		$Conf->settings["rev_tokens"] = -1;
	    $Conf->log("Added $reviewTypeName[$type] reviewer " . (is_object($reviewer) ? $reviewer->email : $reviewer), $this, $paperId);
	    if ($q[0] == "i")
		$Conf->qx("delete from PaperReviewRefused where paperId=$paperId and contactId=$reviewerContactId");
	    if ($q[0] == "i" && $type >= REVIEW_PC && $Conf->setting("pcrev_assigntime", 0) < $when)
		$Conf->save_setting("pcrev_assigntime", $when);
	}
    }

}
