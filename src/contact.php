<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Information from the SQL definition
    public $contactId = 0;
    private $cid;               // for forward compatibility
    var $firstName = "";
    var $lastName = "";
    var $email = "";
    var $preferredEmail = "";
    var $sorter = "";
    var $affiliation;
    var $collaborators;
    var $voicePhoneNumber;
    var $password = "";
    public $password_type = 0;
    public $password_plaintext = "";
    public $disabled = false;
    public $activity_at = false;
    private $data_ = null;
    var $defaultWatch = WATCH_COMMENT;

    // Address information (loaded separately)
    public $addressLine1 = false;
    public $addressLine2;
    public $city;
    public $state;
    public $zipCode;
    public $country;

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    private $is_author_;
    private $has_review_;
    private $has_outstanding_review_;
    private $is_requester_;
    private $is_lead_;
    private $rights_version_ = 1;
    var $roles = 0;
    var $isPC = false;
    var $privChair = false;
    var $contactTags = null;
    const CAP_AUTHORVIEW = 1;
    private $capabilities = null;
    private $review_tokens_ = null;
    private $activated_ = false;

    static private $status_info_cache = array();


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

    static public function set_sorter($c) {
        global $Opt;
        if (@$Opt["sortByLastName"]) {
            if (($m = Text::analyze_von($c->lastName)))
                $c->sorter = trim("$m[1] $c->firstName $m[0] $c->email");
            else
                $c->sorter = trim("$c->lastName $c->firstName $c->email");
        } else
            $c->sorter = trim("$c->firstName $c->lastName $c->email");
    }

    static public function compare($a, $b) {
        return strcasecmp($a->sorter, $b->sorter);
    }

    static public function make($o) {
        // If you change this function, search for its callers to ensure
        // they provide all necessary information.
        $c = new Contact;
        $c->contactId = (int) $o->contactId;
        $c->firstName = defval($o, "firstName", "");
        $c->lastName = defval($o, "lastName", "");
        $c->email = defval($o, "email", "");
        $c->preferredEmail = defval($o, "preferredEmail", "");
        self::set_sorter($c);
        $c->password = defval($o, "password", "");
        $c->password_type = (substr($c->password, 0, 1) == " " ? 1 : 0);
        if ($c->password_type == 0)
            $c->password_plaintext = $c->password;
        $c->disabled = !!defval($o, "disabled", false);
        if (isset($o->has_review))
            $c->has_review_ = $o->has_review;
        if (isset($o->has_outstanding_review))
            $c->has_outstanding_review_ = $o->has_outstanding_review;
        $roles = defval($o, "roles", 0);
        if (@$o->isPC)
            $roles |= self::ROLE_PC;
        if (@$o->isAssistant)
            $roles |= self::ROLE_ADMIN;
        if (@$o->isChair)
            $roles |= self::ROLE_CHAIR;
        $c->assign_roles($roles);
        $c->contactTags = defval($o, "contactTags", null);
        $c->data_ = defval($o, "data", null);
        return $c;
    }

    static public function site_contact() {
        global $Conf, $Opt;
        if (!@$Opt["contactEmail"] || $Opt["contactEmail"] == "you@example.com") {
            $result = $Conf->qx("select firstName, lastName, email from ContactInfo join Chair using (contactId) limit 1");
            $row = edb_orow($result);
            if (!$row) {
                $result = $Conf->qx("select firstName, lastName, email from ContactInfo join ChairAssistant using (contactId) limit 1");
                $row = edb_orow($result);
            }
            if ($row) {
                $Opt["defaultSiteContact"] = true;
                $Opt["contactName"] = Text::name_text($row);
                $Opt["contactEmail"] = $row->email;
            }
        }
        return (object) array("fullName" => $Opt["contactName"],
                              "email" => $Opt["contactEmail"],
                              "privChair" => 1, "privSuperChair" => 1);
    }

    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN|self::ROLE_CHAIR)) != 0;
    }

    static function external_login() {
        global $Opt;
        return @$Opt["ldapLogin"] || @$Opt["httpAuthLogin"];
    }


    //
    // Initialization functions
    //

    function activate() {
        global $Conf, $Opt;
        $this->activated_ = true;

        // Set $_SESSION["adminuser"] based on administrator status
        if ($this->contactId > 0 && !$this->privChair
            && @$_SESSION["adminuser"] == $this->contactId)
            unset($_SESSION["adminuser"]);
        else if ($this->privChair && !@$_SESSION["adminuser"])
            $_SESSION["adminuser"] = $this->contactId;

        // Handle adminuser actas requests
        if (@$_SESSION["adminuser"] && isset($_REQUEST["actas"])) {
            $cid = cvtint($_REQUEST["actas"]);
            if ($cid <= 0 && $_REQUEST["actas"] == "admin"
                && @$_SESSION["adminuser"])
                $cid = (int) $_SESSION["adminuser"];
            else if ($cid <= 0)
                $cid = Contact::id_by_email($_REQUEST["actas"]);
            unset($_REQUEST["actas"]);
            if ($cid > 0) {
                if (($newc = Contact::find_by_id($cid))) {
                    $Conf->save_session("l", null);
                    if ($newc->contactId != $_SESSION["adminuser"])
                        $_SESSION["actasuser"] = $newc->email;
                    return $newc->activate();
                }
            }
        }

        // Handle invalidate-caches requests
        if (@$_REQUEST["invalidatecaches"] && @$_SESSION["adminuser"]) {
            unset($_REQUEST["invalidatecaches"]);
            $Conf->invalidateCaches();
        }

        // If validatorContact is set, use it
        if ($this->contactId <= 0 && @$Opt["validatorContact"]
            && @$_REQUEST["validator"]) {
            unset($_REQUEST["validator"]);
            if (($newc = Contact::find_by_email($Opt["validatorContact"]))) {
                $this->activated_ = false;
                return $newc->activate();
            }
        }

        // Add capabilities from session and request
        if (!@$Opt["disableCapabilities"]) {
            if (($caps = $Conf->session("capabilities"))) {
                $this->capabilities = $caps;
                ++$this->rights_version_;
            }
            if (isset($_REQUEST["cap"]) || isset($_REQUEST["testcap"]))
                $this->activate_capabilities();
        }

        // Add review tokens from session
        if (($rtokens = $Conf->session("rev_tokens"))) {
            $this->review_tokens_ = $rtokens;
            ++$this->rights_version_;
        }

        // Set user session
        if ($this->contactId)
            $_SESSION["user"] = "$this->contactId " . $Opt["dsn"] . " $this->email";
        else
            unset($_SESSION["user"]);
        return $this;
    }

    private function activate_capabilities() {
        global $Conf, $Opt;

        // Add capabilities from arguments
        if (@$_REQUEST["cap"]) {
            foreach (preg_split(',\s+,', $_REQUEST["cap"]) as $cap)
                $this->apply_capability_text($cap);
            unset($_REQUEST["cap"]);
        }

        // Support capability testing
        if (@$Opt["testCapabilities"] && @$_REQUEST["testcap"]
            && preg_match_all('/([-+]?)([1-9]\d*)([A-Za-z]+)/',
                              $_REQUEST["testcap"], $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $c = ($mm[3] == "a" ? Contact::CAP_AUTHORVIEW : 0);
                $this->change_capability($mm[2], $c, $mm[1] != "-");
            }
            unset($_REQUEST["testcap"]);
        }
    }

    function is_empty() {
        return $this->contactId <= 0 && !$this->capabilities;
    }

    function is_known_user() {
        return $this->contactId > 0;
    }

    function is_admin() {
        return $this->privChair;
    }

    function is_admin_force() {
        return $this->privChair
            && ($fs = @$_REQUEST["forceShow"])
            && $fs != "0";
    }

    function has_tag($t) {
        return $this->contactTags && strpos($this->contactTags, " $t ") !== false;
    }

    function update_cached_roles() {
        foreach (array("is_author_", "has_review_", "has_outstanding_review_",
                       "is_requester_", "is_lead_") as $k)
            unset($this->$k);
        ++$this->rights_version_;
    }

    private function load_author_reviewer_status() {
        global $Me, $Conf;

        // Load from database
        if ($this->contactId > 0) {
            $qr = "";
            if ($this->review_tokens_)
                $qr = " or r.reviewToken in (" . join(",", $this->review_tokens_) . ")";
            $result = $Conf->qe("select max(conf.conflictType),
                r.contactId as reviewer,
                max(r.reviewNeedsSubmit) as reviewNeedsSubmit
                from ContactInfo c
                left join PaperConflict conf on (conf.contactId=c.contactId)
                left join PaperReview r on (r.contactId=c.contactId$qr)
                where c.contactId=$this->contactId group by c.contactId");
            $row = edb_row($result);
        } else
            $row = null;
        $this->is_author_ = $row && $row[0] >= CONFLICT_AUTHOR;
        $this->has_review_ = $row && $row[1] > 0;
        $this->has_outstanding_review_ = $row && $row[2] > 0;

        // Update contact information from capabilities
        if ($this->capabilities)
            foreach ($this->capabilities as $pid => $cap)
                if ($cap & self::CAP_AUTHORVIEW)
                    $this->is_author_ = true;
    }

    function is_author() {
        if (!isset($this->is_author_))
            $this->load_author_reviewer_status();
        return $this->is_author_;
    }

    function is_reviewer() {
        if (!$this->isPC && !isset($this->has_review_))
            $this->load_author_reviewer_status();
        return $this->isPC || $this->has_review_;
    }

    function has_review() {
        if (!isset($this->has_review_))
            $this->load_author_reviewer_status();
        return $this->has_review_;
    }

    function has_outstanding_review() {
        if (!isset($this->has_outstanding_review_))
            $this->load_author_reviewer_status();
        return $this->has_outstanding_review_;
    }

    function is_requester() {
        global $Conf;
        if (!isset($this->is_requester_)) {
            $result = $Conf->qe("select epr.requestedBy from PaperReview epr
                where epr.requestedBy=$this->contactId limit 1");
            $row = edb_row($result);
            $this->is_requester_ = $row && $row[0] > 1;
        }
        return $this->is_requester_;
    }

    function is_discussion_lead() {
        global $Conf;
        if (!isset($this->is_lead_)) {
            $result = $Conf->qe("select paperId from Paper where leadContactId=$this->contactId limit 1");
            $this->is_lead_ = edb_nrows($result) > 0;
        }
        return $this->is_lead_;
    }

    static function roles_all_contact_tags($roles, $tags) {
        $t = "";
        if ($roles & self::ROLE_PC)
            $t = " pc";
        if ($tags)
            return $t . $tags;
        else
            return $t ? $t . " " : "";
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    static function _addressKeys() {
        return array("addressLine1", "addressLine2", "city", "state",
                     "zipCode", "country");
    }

    function change_capability($pid, $c, $on) {
        global $Conf;
        if (!$this->capabilities)
            $this->capabilities = array();
        $oldval = @$cap[$pid] ? $cap[$pid] : 0;
        $newval = ($oldval | ($on ? $c : 0)) & ~($on ? 0 : $c);
        if ($newval != $oldval) {
            ++$this->rights_version_;
            if ($newval != 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
        }
        if (!count($this->capabilities))
            $this->capabilities = null;
        if ($this->activated_ && $newval != $oldval)
            $Conf->save_session("capabilities", $this->capabilities);
        return $newval != $oldval;
    }

    function apply_capability_text($text) {
        global $Conf;
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = $Conf->qx("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $Conf->capability_text($row, $m[3]);
            $text = substr($text, strlen($m[1]));
            if ($rowcap === $text
                || $rowcap === str_replace("/", "_", $text))
                return $this->change_capability((int) $m[2], self::CAP_AUTHORVIEW, $m[1] !== "-");
        }
        return null;
    }

    function review_tokens() {
        return $this->review_tokens_ ? $this->review_tokens_ : array();
    }

    function change_review_token($token, $on) {
        global $Conf;
        assert($token !== false || $on === false);
        if (!$this->review_tokens_)
            $this->review_tokens_ = array();
        $old_ntokens = count($this->review_tokens_);
        if (!$on && $token === false)
            $this->review_tokens_ = array();
        else {
            $pos = array_search($token, $this->review_tokens_);
            if (!$on && $pos !== false)
                array_splice($this->review_tokens_, $pos, 1);
            else if ($on && $pos === false && $token != 0)
                $this->review_tokens_[] = $token;
        }
        $new_ntokens = count($this->review_tokens_);
        if ($new_ntokens == 0)
            $this->review_tokens_ = null;
        if ($new_ntokens != $old_ntokens)
            $this->update_cached_roles();
        if ($this->activated_ && $new_ntokens != $old_ntokens)
            $Conf->save_session("rev_tokens", $this->review_tokens_);
        return $new_ntokens != $old_ntokens;
    }

    function data($key = null) {
        if ($this->data_ && is_string($this->data_))
            $this->data_ = json_decode($this->data_, true);
        if (!$key)
            return $this->data_;
        else if (!$this->data_)
            return null;
        else
            return @$this->data_[$key];
    }

    function save_data($key, $value) {
        global $Conf;
        if ($this->data_ && is_string($this->data_))
            $this->data_ = json_decode($this->data_, true);
        $old = $this->data_ ? json_encode($this->data_) : "NULL";
        if ($value !== null) {
            if (!$this->data_)
                $this->data_ = array();
            $this->data_[$key] = $value;
        } else if ($this->data_) {
            unset($this->data_[$key]);
            if (!count($this->data_))
                $this->data_ = null;
        }
        $new = $this->data_ ? json_encode($this->data_) : "NULL";
        if ($old !== $new)
            $Conf->qe("update ContactInfo set data=" . ($this->data_ ? "'" . sqlq($new) . "'" : $new) . " where contactId=" . $this->contactId);
    }

    function trim() {
        $this->contactId = (int) trim($this->contactId);
        $this->firstName = simplify_whitespace($this->firstName);
        $this->lastName = simplify_whitespace($this->lastName);
        foreach (array("email", "preferredEmail", "affiliation",
                       "voicePhoneNumber", "addressLine1", "addressLine2", "city", "state",
                       "zipCode", "country")
                 as $k)
            if ($this->$k)
                $this->$k = trim($this->$k);
        self::set_sorter($this);
    }

    function escape() {
        global $Conf;
        if (@$_REQUEST["ajax"]) {
            if ($this->is_empty())
                $Conf->ajaxExit(array("ok" => 0, "loggedout" => 1));
            else
                $Conf->ajaxExit(array("ok" => 0, "error" => "You don’t have permission to access that page."));
        }

        if ($this->is_empty()) {
            // Preserve review form values and comments across session expiration.
            $x = array("afterLogin" => 1, "blind" => 1);
            $rf = reviewForm();
            foreach ($rf->fmap as $field => $f)
                if (isset($_REQUEST[$field]))
                    $x[$field] = $_REQUEST[$field];
            foreach (array("comment", "visibility", "override", "plimit",
                           "subject", "emailBody", "cc", "recipients",
                           "replyto") as $k)
                if (isset($_REQUEST[$k]))
                    $x[$k] = $_REQUEST[$k];
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            // NB: selfHref automagically preserves common parameters like
            // "p", "q", etc.
            $_SESSION["afterLogin"] = selfHref($x, array("raw" => true,
                                                         "site_relative" => true));
            error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }

    function save() {
        global $Conf, $Now;
        $this->trim();
        $inserting = !$this->contactId;
        $qf = array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "voicePhoneNumber", "password", "collaborators",
                       "roles", "defaultWatch") as $k)
            $qf[] = "$k='" . sqlq($this->$k) . "'";
        if ($this->preferredEmail != "")
            $qf[] = "preferredEmail='" . sqlq($this->preferredEmail) . "'";
        else
            $qf[] = "preferredEmail=null";
        if ($Conf->sversion >= 35) {
            if ($this->contactTags)
                $qf[] = "contactTags='" . sqlq($this->contactTags) . "'";
            else
                $qf[] = "contactTags=null";
        }
        if ($Conf->sversion >= 47)
            $qf[] = "disabled=" . ($this->disabled ? 1 : 0);
        if ($Conf->sversion >= 71) {
            if (!$this->data_)
                $qf[] = "data=NULL";
            else if (is_string($this->data_))
                $qf[] = "data='" . sqlq($this->data_) . "'";
            else if (is_object($this->data_))
                $qf[] = "data='" . sqlq(json_encode($this->data_)) . "'";
        }
        $q = ($inserting ? "insert into" : "update")
            . " ContactInfo set " . join(", ", $qf);
        if ($inserting) {
            $this->creationTime = $Now;
            $q .= ", creationTime=$Now";
        } else
            $q .= " where contactId=" . $this->contactId;
        $result = $Conf->qe($q);
        if (!$result)
            return $result;
        if ($inserting)
            $this->contactId = $Conf->lastInsertId();
        $Conf->qx("delete from ContactAddress where contactId=$this->contactId");
        if ($this->addressLine1 || $this->addressLine2 || $this->city
            || $this->state || $this->zipCode || $this->country) {
            $query = "insert into ContactAddress (contactId, addressLine1, addressLine2, city, state, zipCode, country) values ($this->contactId, '" . sqlq($this->addressLine1) . "', '" . sqlq($this->addressLine2) . "', '" . sqlq($this->city) . "', '" . sqlq($this->state) . "', '" . sqlq($this->zipCode) . "', '" . sqlq($this->country) . "')";
            $result = $Conf->qe($query);
        }
        return $result;
    }

    static function email_authored_papers($email, $reg) {
        global $Conf;
        $aupapers = array();
        $result = $Conf->q("select paperId, authorInformation from Paper where authorInformation like '%\t" . sqlq_for_like($email) . "\t%'");
        while (($row = edb_orow($result))) {
            cleanAuthor($row);
            foreach ($row->authorTable as $au)
                if (strcasecmp($au[2], $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($reg && !@$reg->firstName && $au[0])
                        $reg->firstName = $au[0];
                    if ($reg && !@$reg->lastName && $au[1])
                        $reg->lastName = $au[1];
                    if ($reg && !@$reg->affiliation && $au[3])
                        $reg->affiliation = $au[3];
                    break;
                }
        }
        return $aupapers;
    }

    function save_authored_papers($aupapers) {
        global $Conf;
        if (count($aupapers) && $this->contactId) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            $Conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    function save_roles($new_roles, $actor) {
        global $Conf;
        $old_roles = $this->roles;
        // change the roles tables and log
        $tables = Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR;
        $actor_email = ($actor ? " by $actor->email" : "");
        $diff = 0;
        foreach (array(Contact::ROLE_PC => "PCMember",
                       Contact::ROLE_ADMIN => "ChairAssistant",
                       Contact::ROLE_CHAIR => "Chair") as $role => $tablename)
            if (($new_roles & $role) && !($old_roles & $role)) {
                if ($tables & $role)
                    $Conf->qe("insert into $tablename (contactId) values ($this->contactId)");
                $Conf->log("Added as $tablename$actor_email", $this);
                $diff |= $role;
            } else if (!($new_roles & $role) && ($old_roles & $role)) {
                if ($tables & $role)
                    $Conf->qe("delete from $tablename where contactId=$this->contactId");
                $Conf->log("Removed as $tablename$actor_email", $this);
                $diff |= $role;
            }
        // ensure there's at least one system administrator
        if ($diff & Contact::ROLE_ADMIN) {
            $result = $Conf->qe("select contactId from ChairAssistant");
            if (edb_nrows($result) == 0) {
                $Conf->qe("insert into ChairAssistant (contactId) values ($this->contactId)");
                $new_roles |= Contact::ROLE_ADMIN;
            }
        }
        // save the roles bits
        if ($diff) {
            $Conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $diff != 0;
    }

    private function load_by_query($where) {
        global $Conf, $Opt;

        $result = $Conf->q("select ContactInfo.* from ContactInfo where $where");
        if (!($row = edb_orow($result)))
            return false;

        $this->contactId = (int) $row->contactId;
        $this->firstName = $row->firstName;
        $this->lastName = $row->lastName;
        $this->email = $row->email;
        $this->preferredEmail = defval($row, "preferredEmail", null);
        self::set_sorter($this);
        $this->affiliation = $row->affiliation;
        $this->voicePhoneNumber = $row->voicePhoneNumber;
        $this->password = $row->password;
        $this->password_type = (substr($this->password, 0, 1) == " " ? 1 : 0);
        if ($this->password_type == 0)
            $this->password_plaintext = $this->password;
        $this->disabled = !!defval($row, "disabled", 0);
        $this->collaborators = $row->collaborators;
        $this->defaultWatch = defval($row, "defaultWatch", 0);
        $this->contactTags = defval($row, "contactTags", null);
        $this->activity_at = (int) defval($row, "lastLogin", 0);
        $this->data_ = defval($row, "data", null);
        $this->assign_roles($row->roles);

        $this->trim();
        return true;
    }

    static function find_by_id($cid) {
        $acct = new Contact;
        if (!$acct->load_by_query("ContactInfo.contactId=" . (int) $cid))
            return null;
        return $acct;
    }

    static function safe_registration($reg) {
        $safereg = array();
        foreach (array("firstName", "lastName", "name", "preferredEmail",
                       "affiliation", "collaborators", "voicePhoneNumber")
                 as $k)
            if (isset($reg[$k]))
                $safereg[$k] = $reg[$k];
        return $safereg;
    }

    private function register_by_email($email, $reg) {
        // For more complicated registrations, use UserStatus
        global $Conf, $Opt, $Now;
        $reg = (object) ($reg === true ? array() : $reg);

        // Set up registration
        $name = Text::analyze_name($reg);
        list($reg->firstName, $reg->lastName) = array($name->firstName, $name->lastName);

        $this->password_type = 0;
        if (isset($reg->password)
            && ($password = trim($reg->password)) != "")
            $this->change_password($password);
        else {
            // Always store initial, randomly-generated user passwords in
            // plaintext. The first time a user logs in, we will encrypt
            // their password.
            //
            // Why? (1) There is no real security problem to storing random
            // values. (2) We get a better UI by storing the textual password.
            // Specifically, if someone tries to "create an account", then
            // they don't get the email, then they try to create the account
            // again, the password will be visible in both emails.
            $this->password = $password = self::random_password();
        }

        $best_email = @$reg->preferredEmail ? $reg->preferredEmail : $email;
        $authored_papers = Contact::email_authored_papers($best_email, $reg);

        // Set up query
        $qa = "email, password, creationTime";
        $qb = "'" . sqlq($email) . "','" . sqlq($this->password) . "',$Now";
        foreach (array("firstName", "lastName", "affiliation",
                       "collaborators", "voicePhoneNumber", "preferredEmail")
                 as $k)
            if (isset($reg->$k)) {
                $qa .= ",$k";
                $qb .= ",'" . sqlq($reg->$k) . "'";
            }
        $result = $Conf->ql("insert into ContactInfo ($qa) values ($qb)");
        if (!$result)
            return false;
        $cid = (int) $Conf->lastInsertId("while creating contact");
        if (!$cid)
            return false;

        // Having added, load it
        if (!$this->load_by_query("ContactInfo.contactId=$cid"))
            return false;

        // Success! Save newly authored papers
        if (count($authored_papers))
            $this->save_authored_papers($authored_papers);

        $this->password_plaintext = $password;
        return true;
    }

    static function find_by_email($email, $reg = false, $send = false) {
        global $Conf, $Me, $Now;
        $acct = new Contact;

        // Lookup by email
        $email = trim($email ? $email : "");
        if ($email != ""
            && $acct->load_by_query("ContactInfo.email='" . sqlq($email) . "'"))
            return $acct;

        // Not found: register
        if (!$reg || !validateEmail($email))
            return null;
        $ok = $acct->register_by_email($email, $reg);

        // Log
        if ($ok)
            $acct->mark_create($send, true);
        else
            $Conf->log("Account $email creation failure", $Me);

        return $ok ? $acct : null;
    }

    function mark_create($send_email, $message_chair) {
        global $Conf, $Me;
        if ($Me && $Me->privChair && $message_chair)
            $Conf->infoMsg("Created account for <a href=\"" . hoturl("profile", "u=" . urlencode($this->email)) . "\">" . Text::user_html_nolink($this) . "</a>.");
        if ($send_email)
            $this->sendAccountInfo("create", false);
        $Conf->log($Me && $Me->is_known_user() ? "Created account ($Me->email)" : "Created account", $this);
    }

    function load_address() {
        global $Conf;
        if ($this->addressLine1 === false && $this->contactId) {
            $result = $Conf->qx("select * from ContactAddress where contactId=$this->contactId");
            $row = edb_orow($result);
            foreach (self::_addressKeys() as $k)
                $this->$k = @($row->$k);
        }
    }

    static function id_by_email($email) {
        global $Conf;
        $result = $Conf->qe("select contactId from ContactInfo where email='" . sqlq(trim($email)) . "'");
        $row = edb_row($result);
        return $row ? $row[0] : false;
    }


    // viewing permissions

    function _fetchPaperRow($prow, &$whyNot) {
        global $Conf;
        if (!is_object($prow))
            return $Conf->paperRow($prow, $this, $whyNot);
        else {
            $whyNot = array("paperId" => $prow->paperId);
            if ($prow instanceof PaperInfo)
                return $prow;
            else {
                trigger_error("Contact::_fetchPaperRow called on a non-PaperInfo object");
                return new PaperInfo($prow, $this);
            }
        }
    }

    private function rights($prow, $forceShow = null) {
        global $Conf;
        $ci = $prow->contact_info($this);

        // check first whether administration is allowed
        if (@$ci->rights_version != $this->rights_version_) {
            $ci->allow_administer = false;
            if ($this->contactId > 0
                && !($prow->managerContactId
                     && $prow->managerContactId != $this->contactId
                     && $ci->conflict_type)
                && ($this->privChair
                    || $prow->managerContactId == $this->contactId))
                $ci->allow_administer = true;
        }

        // correct $forceShow
        if ($ci->allow_administer) {
            if ($forceShow === "any")
                $forceShow = @$ci->rights_force;
            if ($forceShow === null)
                $forceShow = ($fs = @$_REQUEST["forceShow"]) && $fs != "0";
        } else
            $forceShow = false;

        // set other rights
        if (@$ci->rights_version != $this->rights_version_
            || @$ci->rights_force !== $forceShow) {
            $ci->rights_version = $this->rights_version_;
            $ci->rights_force = $forceShow;

            // check current administration status
            $ci->can_administer = $ci->allow_administer
                && (!$ci->conflict_type || $forceShow);

            // check PC tracking
            $tracks = $Conf->has_tracks();
            $isPC = $this->isPC
                && (!$tracks || $Conf->check_tracks($prow, $this, "view"));

            // check whether PC privileges apply
            $ci->allow_pc_broad = $ci->allow_administer || $isPC;
            $ci->allow_pc = $ci->can_administer
                || ($isPC && !$ci->conflict_type);

            // check whether this is a potential reviewer
            // (existing external reviewer or PC)
            if ($ci->review_type > 0 || $ci->allow_administer)
                $ci->potential_reviewer = true;
            else if ($ci->allow_pc)
                $ci->potential_reviewer = !$tracks
                    || $Conf->check_tracks($prow, $this, "unassrev");
            else
                $ci->potential_reviewer = false;
            $ci->allow_review = $ci->potential_reviewer
                && ($ci->can_administer || !$ci->conflict_type);

            // check capabilities
            // If an author-view capability is set, then use it -- unless
            // this user is a PC member or reviewer, which takes priority.
            $ci->view_conflict_type = $ci->conflict_type;
            if (isset($this->capabilities)
                && isset($this->capabilities[$prow->paperId])
                && ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW)
                && !$isPC
                && $ci->review_type <= 0)
                $ci->view_conflict_type = CONFLICT_AUTHOR;
            $ci->act_author = $ci->conflict_type >= CONFLICT_AUTHOR;
            $ci->act_author_view = $ci->view_conflict_type >= CONFLICT_AUTHOR;

            // check author behavior rights
            $ci->allow_author = $ci->act_author || $ci->allow_administer;
            $ci->allow_author_view = $ci->act_author_view || $ci->allow_administer;

            // check blindness
            $bs = $Conf->setting("sub_blind");
            $ci->nonblind = $bs == Conference::BLIND_NEVER
                || ($bs == Conference::BLIND_OPTIONAL
                    && !(isset($prow->paperBlind) ? $prow->paperBlind : $prow->blind))
                || ($bs == Conference::BLIND_UNTILREVIEW
                    && $ci->review_type > 0
                    && $ci->review_submitted > 0)
                || ($prow->outcome > 0
                    && $ci->allow_review
                    && $Conf->timeReviewerViewAcceptedAuthors());
        }
        return $ci;
    }

    static public function override_deadlines($override = null) {
        if ($override !== null)
            return !!$override;
        else
            return isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    public function allowAdminister($prow) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer;
        } else
            return $this->privChair;
    }

    public function canAdminister($prow, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer;
        } else
            return $this->privChair;
    }

    public function actPC($prow, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc;
        } else
            return $this->privChair || $this->isPC;
    }

    public function view_conflict_type($prow) {
        $rights = $this->rights($prow);
        return $rights->view_conflict_type;
    }

    public function actAuthorView($prow, $download = false) {
        $rights = $this->rights($prow);
        return $rights->act_author_view;
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

    function canStartPaper(&$whyNot = null, $override = null) {
        global $Conf;
        $whyNot = array();
        if ($Conf->timeStartPaper()
            || ($this->privChair && self::override_deadlines($override)))
            return true;
        $whyNot["deadline"] = "sub_reg";
        if ($this->privChair)
            $whyNot["override"] = 1;
        return false;
    }

    function canEditPaper($prow, &$whyNot = null) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_administer || $prow->has_author($this);
    }

    function canUpdatePaper($prow, &$whyNot = null, $override = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        $override = $rights->allow_administer && self::override_deadlines($override);
        // policy
        if ($rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($Conf->timeUpdatePaper($prow) || $override))
            return true;
        // collect failure reasons
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->timeSubmitted > 0 && $Conf->setting('sub_freeze') > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$Conf->timeUpdatePaper($prow) && !$override)
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canFinalizePaper($prow, &$whyNot = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        // policy
        if ($rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($Conf->timeFinalizePaper($prow)
                || ($rights->allow_administer && self::override_deadlines())))
            return true;
        // collect failure reasons
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->timeSubmitted > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$Conf->timeFinalizePaper($prow)
            && !($rights->allow_administer && self::override_deadlines()))
            $whyNot["deadline"] = "finalizePaperSubmission";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canWithdrawPaper($prow, &$whyNot = null, $override = null) {
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        $override = $rights->allow_administer && self::override_deadlines($override);
        // policy
        if ($rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($override || $prow->outcome == 0))
            return true;
        // collect failure reasons
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        else if ($prow->outcome != 0 && !$override)
            $whyNot["decided"] = 1;
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canRevivePaper($prow, &$whyNot = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        // policy
        if ($rights->allow_author
            && $prow->timeWithdrawn > 0
            && ($Conf->timeUpdatePaper($prow)
                || ($rights->allow_administer && self::override_deadlines())))
            return true;
        // collect failure reasons
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn <= 0)
            $whyNot["notWithdrawn"] = 1;
        if (!$Conf->timeUpdatePaper($prow)
            && !($rights->allow_administer && self::override_deadlines()))
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canSubmitFinalPaper($prow, &$whyNot = null, $override = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        $override = $rights->allow_administer && self::override_deadlines($override);
        // policy
        if ($rights->allow_author
            && $Conf->collectFinalPapers()
            && $prow->timeWithdrawn <= 0
            && $prow->outcome > 0
            && $Conf->timeAuthorViewDecision()
            && ($Conf->timeSubmitFinalPaper() || $override))
            return true;
        // collect failure reasons
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        // NB logic order here is important elsewhere
        if (!$Conf->timeAuthorViewDecision()
            || $prow->outcome <= 0)
            $whyNot["notAccepted"] = 1;
        else  if (!$Conf->collectFinalPapers())
            $whyNot["deadline"] = "final_open";
        else if (!$Conf->timeSubmitFinalPaper() && !$override)
            $whyNot["deadline"] = "final_done";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canViewPaper($prow, &$whyNot = null, $download = false) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        // policy
        if ($rights->allow_author_view
            || ($rights->review_type
                && $Conf->timeReviewerViewSubmittedPaper())
            || ($rights->allow_pc_broad
                && $Conf->timePCViewPaper($prow, $download)))
            return true;
        // collect failure reasons
        if (!$rights->allow_author_view
            && !$rights->review_type
            && !$rights->allow_pc_broad) {
            $whyNot["permission"] = 1;
            return false;
        }
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        if ($rights->allow_pc_broad
            && !$Conf->timePCViewPaper($prow, $download))
            $whyNot["deadline"] = "sub_sub";
        else if ($rights->review_type
                 && !$Conf->timeReviewerViewSubmittedPaper())
            $whyNot["deadline"] = "sub_sub";
        if ((!$rights->allow_pc_broad
             && !$rights->review_type)
            || count($whyNot) == 1)
            $whyNot["permission"] = 1;
        return false;
    }

    function canDownloadPaper($prow, &$whyNot = null) {
        return $this->canViewPaper($prow, $whyNot, true);
    }

    function can_view_paper_manager($prow) {
        global $Opt;
        if ($this->privChair)
            return true;
        if (!$prow)
            return $this->isPC && !@$Opt["hideManager"];
        $rights = $this->rights($prow);
        return $prow->managerContactId == $this->contactId
            || ($rights->potential_reviewer && !@$Opt["hideManager"]);
    }

    function can_view_lead($prow, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer
                || $prow->leadContactId == $this->contactId
                || (($rights->allow_pc || $rights->allow_review)
                    && $this->canViewReviewerIdentity($prow, null, $forceShow));
        } else
            return $this->privChair || $this->isPC;
    }

    function can_view_shepherd($prow, $forceShow = null) {
        return $this->actPC($prow, $forceShow)
            || $this->canViewDecision($prow, $forceShow);
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
        $rights = $this->rights($prow, $forceShow);
        if (($rights->nonblind
             && $prow->timeSubmitted > 0
             && ($rights->allow_pc_broad
                 || ($rights->review_type
                     && $Conf->timeReviewerViewSubmittedPaper())))
            || ($rights->nonblind
                && $prow->timeWithdrawn <= 0
                && $rights->allow_pc_broad
                && $Conf->setting("pc_seeall") > 0)
            || ($rights->allow_administer
                ? $rights->nonblind || $rights->rights_force /* chair can't see blind authors unless forceShow */
                : $rights->act_author_view))
            return true;
        // collect failure reasons
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else if ($rights->allow_pc_broad || $rights->review_type)
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
        if (!is_object($opt) && !($opt = PaperOption::find($opt))) {
            $whyNot["invalidId"] = "paper";
            return false;
        }
        $rights = $this->rights($prow, $forceShow);
        // policy
        if (!$this->canViewPaper($prow, $whyNot))
            return false;       // $whyNot already set
        $oview = @$opt->view_type;
        if ($rights->act_author_view
            || (($rights->allow_administer
                 || $rights->allow_pc_broad
                 || $rights->review_type)
                && (($oview == "admin" && $rights->allow_administer)
                    || !$oview
                    || $oview == "pc"
                    || ($oview == "nonblind"
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
            || ($this->review_tokens_ && array_search($rrow->reviewToken, $this->review_tokens_) !== false)
            || ($rrow->requestedBy == $this->contactId && $rrow->reviewType == REVIEW_EXTERNAL && $Conf->setting("pcrev_editdelegate"));
    }

    public function canCountReview($prow, $rrow, $forceShow) {
        if ($rrow && $rrow->reviewNeedsSubmit <= 0
            && $rrow->reviewSubmitted <= 0)
            return false;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_administer
            || $rights->allow_pc
            || $this->canViewReview($prow, $rrow, $forceShow);
    }

    function canViewReview($prow, $rrow, $forceShow, &$whyNot = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else
            $viewscore = VIEWSCORE_AUTHOR;
        $rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = $Conf->setting("pc_seeallrev");
        $rights = $this->rights($prow, $forceShow);
        // policy
        if ($rights->can_administer
            || (($prow->timeSubmitted > 0
                 || $rights->review_type
                 || $rights->allow_administer)
                && (($rights->act_author_view
                     && $Conf->timeAuthorViewReviews($this->has_outstanding_review() && $this->has_review())
                     && $rrowSubmitted
                     && $viewscore >= VIEWSCORE_AUTHOR)
                    || ($rights->allow_pc
                        && $rrowSubmitted
                        && $pc_seeallrev > 0 // see also timePCViewAllReviews()
                        && ($pc_seeallrev != Conference::PCSEEREV_UNLESSANYINCOMPLETE
                            || !$this->has_outstanding_review())
                        && ($pc_seeallrev != Conference::PCSEEREV_UNLESSINCOMPLETE
                            || !$rights->review_type)
                        && $Conf->check_tracks($prow, $this, "viewrev")
                        && $viewscore >= VIEWSCORE_PC)
                    || ($rights->review_type
                        && !$rights->view_conflict_type
                        && $rrowSubmitted
                        && $prow->review_not_incomplete($this)
                        && ($rights->allow_pc
                            || $Conf->settings["extrev_view"] >= 1)
                        && $viewscore >= VIEWSCORE_PC)
                    || ($rrow
                        && $rrow->paperId == $prow->paperId
                        && $this->ownReview($rrow)
                        && $viewscore >= VIEWSCORE_REVIEWERONLY))))
            return true;
        // collect failure reasons
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else if (!$rights->act_author_view
                 && !$rights->allow_pc
                 && !$rights->review_type)
            $whyNot['permission'] = 1;
        else if ($rights->act_author_view
                 && $Conf->timeAuthorViewReviews()
                 && $this->has_outstanding_review()
                 && $this->has_review())
            $whyNot['reviewsOutstanding'] = 1;
        else if ($rights->act_author_view
                 && !$rrowSubmitted)
            $whyNot['permission'] = 1;
        else if ($rights->act_author_view)
            $whyNot['deadline'] = 'au_seerev';
        else if ($rights->view_conflict_type)
            $whyNot['conflict'] = 1;
        else if (!$rights->allow_pc
                 && $prow->review_submitted($this))
            $whyNot['externalReviewer'] = 1;
        else if (!$rrowSubmitted)
            $whyNot['reviewNotSubmitted'] = 1;
        else if ($rights->allow_pc
                 && $pc_seeallrev == Conference::PCSEEREV_UNLESSANYINCOMPLETE
                 && $this->has_outstanding_review())
            $whyNot["reviewsOutstanding"] = 1;
        else if (!$Conf->timeReviewOpen())
            $whyNot['deadline'] = "rev_open";
        else {
            $whyNot['reviewNotComplete'] = 1;
            if (!$Conf->time_review($rights->allow_pc, true))
                $whyNot['deadline'] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
        }
        if ($rights->allow_administer)
            $whyNot['forceShow'] = 1;
        return false;
    }

    function canRequestReview($prow, $time, &$whyNot = null) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        // policy
        $rights = $this->rights($prow);
        if (($rights->review_type >= REVIEW_SECONDARY
             || $rights->allow_administer)
            && ($Conf->time_review(false, true)
                || !$time
                || ($rights->allow_administer
                    && self::override_deadlines())))
            return true;
        // collect failure reasons
        if ($rights->review_type < REVIEW_SECONDARY)
            $whyNot['permission'] = 1;
        else {
            $whyNot['deadline'] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer)
                $whyNot['override'] = 1;
        }
        return false;
    }

    function can_review_any() {
        global $Conf;
        return $this->isPC
            && $Conf->setting("pcrev_any") > 0
            && $Conf->time_review(true, true)
            && $Conf->check_any_tracks($this, "unassrev");
    }

    function timeReview($prow, $rrow) {
        global $Conf;
        $rights = $this->rights($prow);
        if ($rights->review_type > 0
            || $prow->reviewId
            || ($rrow
                && $this->ownReview($rrow))
            || ($rrow
                && $rrow->contactId != $this->contactId
                && $rights->allow_administer))
            return $Conf->time_review($rights->allow_pc, true);
        else if ($rights->allow_review
                 && $Conf->setting("pcrev_any") > 0)
            return $Conf->time_review(true, true);
        else
            return false;
    }

    function allow_review_assignment_ignore_conflict($prow, &$whyNot = null) {
        global $Conf;
        if (!$prow)
            return $this->isPC && $Conf->check_all_tracks($this, "assrev");
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $Conf->check_tracks($prow, $this, "assrev"));
    }

    function allow_review_assignment($prow, &$whyNot = null) {
        global $Conf;
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow);
        return $rights->allow_pc
            && ($rights->allow_review
                || $Conf->check_tracks($prow, $this, "assrev"));
    }

    function canReview($prow, $rrow, &$whyNot = null, $submit = false) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        assert(!$rrow || $rrow->paperId == $prow->paperId);
        $rights = $this->rights($prow);
        $rrow_contactId = 0;
        if ($rrow) {
            $myReview = $rights->can_administer || $this->ownReview($rrow);
            if (isset($rrow->reviewContactId))
                $rrow_contactId = $rrow->reviewContactId;
            else if (isset($rrow->contactId))
                $rrow_contactId = $rrow->contactId;
        } else
            $myReview = $rights->review_type > 0;
        // policy
        if (($myReview
             && $Conf->time_review($rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $Conf->setting("pcrev_any") > 0
                && $Conf->time_review(true, true))
            || ($rights->can_administer
                && ($prow->timeSubmitted > 0 || $rights->rights_force)
                && (!$submit || self::override_deadlines())))
            return true;
        // collect failure reasons
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        if ($rrow
            && $rrow_contactId != $this->contactId
            && !$rights->allow_administer)
            $whyNot['differentReviewer'] = 1;
        else if (!$rights->allow_pc && !$myReview)
            $whyNot['permission'] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot['withdrawn'] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot['notSubmitted'] = 1;
        else {
            if ($rights->conflict_type && !$rights->can_administer)
                $whyNot['conflict'] = 1;
            else if ($rights->allow_review && !$myReview
                     && (!$rrow || $rrow_contactId == $this->contactId))
                $whyNot['reviewNotAssigned'] = 1;
            else
                $whyNot['deadline'] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer
                && ($rights->conflict_type || $prow->timeSubmitted <= 0))
                $whyNot['chairMode'] = 1;
            if ($rights->allow_administer && isset($whyNot['deadline']))
                $whyNot['override'] = 1;
        }
        return false;
    }

    function canSubmitReview($prow, $rrow, &$whyNot = null) {
        return $this->canReview($prow, $rrow, $whyNot, true);
    }

    function can_view_review_ratings($prow, $rrow) {
        global $Conf;
        $rs = $Conf->setting("rev_ratings");
        if ($rs != REV_RATINGS_PC && $rs != REV_RATINGS_PC_EXTERNAL)
            return false;
        $rights = $this->rights($prow);
        return $this->canViewReview($prow, $rrow, null)
            && ($rights->allow_pc || $rights->allow_review);
    }

    function can_rate_review($prow, $rrow) {
        return $this->can_view_review_ratings($prow, $rrow)
            && !$this->ownReview($rrow);
    }

    function canSetRank($prow, $forceShow = null) {
        global $Conf;
        if (!$Conf->setting("tag_rank"))
            return false;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_review;
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
        $rights = $this->rights($prow);
        // policy
        if ($rights->allow_review
            && ($prow->timeSubmitted > 0
                || $rights->review_type > 0
                || ($rights->allow_administer && $rights->rights_force))
            && ($Conf->time_review($rights->allow_pc, true)
                || $Conf->setting("cmt_always") > 0
                || ($rights->allow_administer
                    && (!$submit || self::override_deadlines())))
            && (!$crow
                || $crow->contactId == $this->contactId
                || $rights->allow_administer))
            return true;
        // collect failure reasons
        if ($crow && $crow->contactId != $this->contactId
            && !$rights->allow_administer)
            $whyNot['differentReviewer'] = 1;
        else if (!$rights->allow_pc && !$rights->allow_review)
            $whyNot['permission'] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot['withdrawn'] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot['notSubmitted'] = 1;
        else {
            if ($rights->conflict_type > 0)
                $whyNot['conflict'] = 1;
            else
                $whyNot['deadline'] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer && $rights->conflict_type)
                $whyNot['chairMode'] = 1;
            if ($rights->allow_administer && isset($whyNot['deadline']))
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
        $rights = $this->rights($prow, $forceShow);
        // policy
        if ($crow_contactId == $this->contactId        // wrote this comment
            || $rights->can_administer
            || ($rights->act_author_view
                && $ctype >= COMMENTTYPE_AUTHOR
                && (($ctype & COMMENTTYPE_RESPONSE)    // author's response
                    || ($Conf->timeAuthorViewReviews() // author-visible cmt
                        && !($ctype & COMMENTTYPE_DRAFT))))
            || (!$rights->view_conflict_type
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->canViewReview($prow, null, $forceShow)
                && (($rights->allow_pc
                     && !$Conf->setting("pc_seeblindrev"))
                    || $prow->review_not_incomplete($this))
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER)))
            return true;
        // collect failure reasons
        if ((!$rights->act_author_view && !$rights->allow_review)
            || (!$rights->allow_administer
                && ($ctype & COMMENTTYPE_VISIBILITY) == COMMENTTYPE_ADMINONLY))
            $whyNot["permission"] = 1;
        else if ($rights->act_author_view)
            $whyNot["deadline"] = 'au_seerev';
        else if ($rights->view_conflict_type)
            $whyNot["conflict"] = 1;
        else if (!$rights->allow_pc
                 && !$rights->review_submitted)
            $whyNot["externalReviewer"] = 1;
        else if ($ctype & COMMENTTYPE_DRAFT)
            $whyNot["responseNotReady"] = 1;
        else
            $whyNot["reviewNotComplete"] = 1;
        if ($rights->allow_administer)
            $whyNot["forceShow"] = 1;
        return false;
    }

    function canRespond($prow, $crow, &$whyNot = null, $submit = false) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow);
        // policy
        if ($prow->timeSubmitted > 0
            && ($rights->can_administer
                || $rights->act_author)
            && ($Conf->timeAuthorRespond()
                || ($rights->allow_administer
                    && (!$submit || self::override_deadlines())))
            && (!$crow
                || ($crow->commentType & COMMENTTYPE_RESPONSE)))
            return true;
        // collect failure reasons
        if (!$rights->allow_administer
            && !$rights->act_author)
            $whyNot['permission'] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot['withdrawn'] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot['notSubmitted'] = 1;
        else {
            $whyNot['deadline'] = "resp_done";
            if ($rights->allow_administer && $rights->conflict_type)
                $whyNot['chairMode'] = 1;
            if ($rights->allow_administer && isset($whyNot['deadline']))
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


    function canEditContactAuthors($prow) {
        $rights = $this->rights($prow);
        return $rights->allow_administer || $prow->has_author($this);
    }

    function canViewReviewerIdentity($prow, $rrow, $forceShow = null) {
        global $Conf;
        $rrow_contactId = 0;
        if ($rrow && isset($rrow->reviewContactId))
            $rrow_contactId = $rrow->reviewContactId;
        else if ($rrow && isset($rrow->contactId))
            $rrow_contactId = $rrow->contactId;
        // If $prow === true or null, be permissive: return true
        // iff there could exist a paper for which canViewReviewerIdentity
        // is true.
        if (!$prow || $prow === true)
            $prow = new PaperInfo
                (array("conflictType" => 0, "managerContactId" => 0,
                       "myReviewType" => ($this->is_reviewer() ? 1 : 0),
                       "myReviewSubmitted" => 1,
                       "myReviewNeedsSubmit" => 0,
                       "paperId" => 1, "timeSubmitted" => 1,
                       "paperBlind" => false, "outcome" => 1), $this);
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer
            || ($rrow && $rrow_contactId == $this->contactId)
            || ($rrow && $this->ownReview($rrow))
            || ($rights->allow_pc
                && (!($pc_seeblindrev = $Conf->setting("pc_seeblindrev"))
                    || ($pc_seeblindrev == 2
                        && $this->canViewReview($prow, $rrow, $forceShow))))
            || ($rights->allow_review
                && $prow->review_not_incomplete($this)
                && ($rights->allow_pc
                    || $Conf->settings["extrev_view"] >= 2))
            || !$Conf->is_review_blind($rrow))
            return true;
        return false;
    }

    function canViewCommentIdentity($prow, $crow, $forceShow) {
        global $Conf;
        if ($crow && !isset($crow->commentType))
            setCommentType($crow);
        if ($crow->commentType & COMMENTTYPE_RESPONSE)
            return $this->canViewAuthors($prow, $forceShow);
        $crow_contactId = 0;
        if ($crow && isset($crow->commentContactId))
            $crow_contactId = $crow->commentContactId;
        else if ($crow)
            $crow_contactId = $crow->contactId;
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer
            || $crow_contactId == $this->contactId
            || $rights->allow_pc
            || ($rights->allow_review
                && $Conf->settings["extrev_view"] >= 2)
            || !$Conf->is_review_blind(!$crow || ($crow->commentType & COMMENTTYPE_BLIND) != 0))
            return true;
        return false;
    }

    function canViewDecision($prow, $forceShow = null) {
        global $Conf;
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer
            || ($rights->act_author_view
                && $Conf->timeAuthorViewDecision())
            || ($rights->allow_pc_broad
                && $Conf->timePCViewDecision($rights->view_conflict_type > 0))
            || ($rights->review_type > 0
                && $rights->review_submitted
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
        $rights = $prow ? $this->rights($prow) : null;

        // chair can see everything
        if ($rights ? $rights->can_administer : $this->privChair)
            return VIEWSCORE_ADMINONLY - 1;

        // author can see author information
        if ($rights ? $rights->act_author_view : !$this->is_reviewer())
            return VIEWSCORE_AUTHOR - 1;

        // if you can't review this paper you can't see anything
        if ($rights && !$rights->allow_review)
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
        if (!$prow)
            return $this->isPC;
        else {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc
                || $Conf->setting("tag_seeall") > 0;
        }
    }

    function canSetTags($prow, $forceShow = null) {
        if (!$prow)
            return $this->isPC;
        else {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc;
        }
    }

    function canSetOutcome($prow) {
        return $this->canAdminister($prow);
    }


    function deadlines() {
        // Return cleaned deadline-relevant settings that this user can see.
        global $Conf, $Opt;
        $dlx = $Conf->deadlines();
        $now = $dlx["now"];
        $dl = array("now" => $now);
        if ($this->privChair)
            $dl["is_admin"] = true;
        foreach (array("sub_open", "resp_open", "rev_open", "final_open") as $x)
            $dl[$x] = $dlx[$x] > 0;

        if ($dlx["sub_reg"] && $dlx["sub_reg"] != $dlx["sub_update"])
            $dl["sub_reg"] = $dlx["sub_reg"];
        if ($dlx["sub_update"] && $dlx["sub_update"] != $dlx["sub_sub"])
            $dl["sub_update"] = $dlx["sub_update"];
        $dl["sub_sub"] = $dlx["sub_sub"];

        $dl["resp_done"] = $dlx["resp_done"];

        $dl["rev_open"] = $dl["rev_open"] && $this->is_reviewer();
        if ($this->isPC) {
            if ($dlx["pcrev_soft"] > $now)
                $dl["pcrev_done"] = $dlx["pcrev_soft"];
            else if ($dlx["pcrev_hard"]) {
                $dl["pcrev_done"] = $dlx["pcrev_hard"];
                $dl["pcrev_ishard"] = true;
            }
        }
        if ($this->is_reviewer()) {
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

        // add meeting tracker
        $tracker = null;
        if ($this->isPC && $Conf->setting("tracker")
            && ($tracker = MeetingTracker::status($this)))
            $dl["tracker"] = $tracker;
        if ($this->isPC && @$Opt["trackerCometSite"] && $tracker)
            $dl["tracker_poll"] = $Opt["trackerCometSite"]
                . "?conference=" . urlencode(Navigation::site_absolute(true))
                . "&poll=" . urlencode(MeetingTracker::tracker_status($tracker));

        return $dl;
    }


    function paper_status_info($row, $forceShow = null) {
        global $Conf;
        if ($row->timeWithdrawn > 0)
            return array("pstat_with", "Withdrawn");
        else if (@$row->outcome && $this->canViewDecision($row, $forceShow)) {
            if (!($data = @self::$status_info_cache[$row->outcome])) {
                $decclass = ($row->outcome > 0 ? "pstat_decyes" : "pstat_decno");

                $outcomes = $Conf->outcome_map();
                $decname = @$outcomes[$row->outcome];
                if ($decname) {
                    $trdecname = preg_replace('/[^-.\w]/', '', $decname);
                    if ($trdecname != "")
                        $decclass .= " pstat_" . strtolower($trdecname);
                } else
                    $decname = "Unknown decision #" . $row->outcome;

                $data = self::$status_info_cache[$row->outcome] = array($decclass, $decname);
            }
            return $data;
        } else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1)
            return array("pstat_noup", "No submission");
        else if ($row->timeSubmitted > 0)
            return array("pstat_sub", "Submitted");
        else
            return array("pstat_prog", "Not ready");
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
            $key = $Conf->setting_data("passwordHmacKey.$keyid", "");
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

    static public function password_cleartext() {
        global $Opt;
        return $Opt["safePasswords"] < 1;
    }

    public function check_password_encryption($is_change) {
        global $Opt;
        if ($Opt["safePasswords"] < 1
            || ($Opt["safePasswords"] == 1 && !$is_change))
            return false;
        if ($this->password_type == 0)
            return true;
        $expected_prefix = " " . self::password_hash_method()
            . " " . defval($Opt, "passwordHmacKeyid", 0) . " ";
        return $this->password_type == 1
            && !str_starts_with($this->password, $expected_prefix);
    }

    public function change_password($new_password) {
        global $Conf, $Opt;
        $this->password_plaintext = $new_password;
        if ($this->check_password_encryption(true))
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

    static function random_password($length = 14) {
        global $Opt;
        if (isset($Opt["ldapLogin"]))
            return "<stored in LDAP>";
        else if (isset($Opt["httpAuthLogin"]))
            return "<using HTTP authentication>";

        // see also regexp in randompassword.php
        $l = explode(" ", "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w tr cr br fr th dr ch ph wr st sp sw pr sl cl 2 3 4 5 6 7 8 9 - @ _ + =");
        $n = count($l);

        $bytes = hotcrp_random_bytes($length + 10, true);
        if ($bytes === false) {
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

    function sendAccountInfo($sendtype, $sensitive) {
        global $Conf, $Opt;
        $rest = array();
        if ($sendtype == "create")
            $template = "@createaccount";
        else if ($this->password_type == 0
                 && (!@$Opt["safePasswords"]
                     || (is_int($Opt["safePasswords"]) && $Opt["safePasswords"] <= 1)
                     || $sendtype != "forgot"))
            $template = "@accountinfo";
        else {
            $rest["capability"] = $Conf->create_capability(CAPTYPE_RESETPASSWORD, array("contactId" => $this->contactId, "timeExpires" => time() + 259200));
            $Conf->log("Created password reset request", $this);
            $template = "@resetpassword";
        }

        $prep = Mailer::prepareToSend($template, null, $this, null, $rest);
        if ($prep["allowEmail"] || !$sensitive
            || @$Opt["debugShowSensitiveEmail"]) {
            Mailer::sendPrepared($prep);
            return $template;
        } else {
            $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    function assign_paper($pid, $rrow, $reviewer_cid, $type, $when) {
        global $Conf, $reviewTypeName;
        if ($type <= 0 && $rrow && $rrow->reviewType && $rrow->reviewModified) {
            if ($rrow->reviewType >= REVIEW_SECONDARY)
                $type = REVIEW_PC;
            else
                return;
        }
        $qtag = "";
        if ($type > 0 && (!$rrow || !$rrow->reviewType)) {
            $qa = $qb = "";
            if (($type == REVIEW_PRIMARY || $type == REVIEW_SECONDARY)
                && ($t = $Conf->setting_data("rev_roundtag"))) {
                $qa .= ", reviewRound";
                $qb .= ", " . $Conf->round_number($t, true);
            }
            if ($Conf->sversion >= 46) {
                $qa .= ", timeRequested";
                $qb .= ", " . $when;
            }
            $q = "insert into PaperReview (paperId, contactId, reviewType, requestedBy$qa) values ($pid, $reviewer_cid, $type, $this->contactId$qb)";
        } else if ($type > 0 && $rrow->reviewType != $type)
            $q = "update PaperReview set reviewType=$type where reviewId=$rrow->reviewId";
        else if ($type <= 0 && $rrow && $rrow->reviewType)
            $q = "delete from PaperReview where reviewId=$rrow->reviewId";
        else
            return;

        if ($Conf->qe($q, "while assigning review")) {
            if ($qtag)
                $Conf->q($qtag);
            if ($rrow && defval($rrow, "reviewToken", 0) != 0 && $type <= 0)
                $Conf->settings["rev_tokens"] = -1;
            if ($q[0] == "d")
                $msg = "Removed " . $reviewTypeName[$rrow->reviewType] . " review";
            else if ($q[0] == "u")
                $msg = "Changed " . $reviewTypeName[$rrow->reviewType] . " review to " . $reviewTypeName[$type];
            else
                $msg = "Added " . $reviewTypeName[$type] . " review";
            $Conf->log($msg . " by " . $this->email, $reviewer_cid, $pid);
            if ($q[0] == "i")
                $Conf->qx("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
            if ($q[0] == "i" && $type >= REVIEW_PC && $Conf->setting("pcrev_assigntime", 0) < $when)
                $Conf->save_setting("pcrev_assigntime", $when);
        }
    }

    function mark_activity() {
        global $Conf, $Now;
        if ($this->contactId > 0
            && (!$this->activity_at || $this->activity_at < $Now)) {
            $this->activity_at = $Now;
            $Conf->qx("update ContactInfo set lastLogin=$Now where contactId=" . $this->contactId);
        }
    }

    function log_activity($text, $paperId = null) {
        global $Conf;
        $this->mark_activity();
        $Conf->log($text, $this, $paperId);
    }

}
