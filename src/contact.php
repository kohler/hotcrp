<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Information from the SQL definition
    public $contactId = 0;
    public $contactDbId = 0;
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
    public $passwordTime = 0;
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
    const ROLE_AUTHOR = 16;
    const ROLE_REVIEWER = 32;
    private $is_author_;
    private $has_review_;
    private $has_outstanding_review_;
    private $is_requester_;
    private $is_lead_;
    private $is_manager_;
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
    static private $contactdb_dblink = false;


    public function __construct($trueuser = null) {
        if ($trueuser)
            $this->merge($trueuser);
    }

    static public function make($o) {
        return new Contact($o);
    }

    private function merge($user) {
        global $Conf;
        if (isset($user->contactId)
            && (!isset($user->dsn) || $user->dsn === $Conf->dsn))
            $this->contactId = (int) $user->contactId;
        if (isset($user->contactDbId))
            $this->contactDbId = (int) $user->contactDbId;
        foreach (array("firstName", "lastName", "email", "preferredEmail", "affiliation",
                       "voicePhoneNumber", "addressLine1", "addressLine2",
                       "city", "state", "zipCode", "country") as $k)
            if (isset($user->$k))
                $this->$k = simplify_whitespace($user->$k);
        if (isset($user->collaborators)) {
            $this->collaborators = "";
            foreach (preg_split('/[\r\n]+/', $user->collaborators) as $c)
                if (($c = simplify_whitespace($c)) !== "")
                    $this->collaborators .= "$c\n";
        }
        self::set_sorter($this);
        if (isset($user->password))
            $this->set_encoded_password($user->password);
        if (isset($user->disabled))
            $this->disabled = !!$user->disabled;
        foreach (array("defaultWatch", "passwordTime") as $k)
            if (isset($user->$k))
                $this->$k = (int) $user->$k;
        if (property_exists($user, "contactTags"))
            $this->contactTags = $user->contactTags;
        else
            $this->contactTags = false;
        if (isset($user->activity_at))
            $this->activity_at = (int) $user->activity_at;
        else if (isset($user->lastLogin))
            $this->activity_at = (int) $user->lastLogin;
        if (isset($user->data))
            $this->data_ = $user->data ? : (object) $user->data;
        if (isset($user->roles) || isset($user->isPC) || isset($user->isAssistant)
            || isset($user->isChair)) {
            $roles = (int) @$user->roles;
            if (@$user->isPC)
                $roles |= self::ROLE_PC;
            if (@$user->isAssistant)
                $roles |= self::ROLE_ADMIN;
            if (@$user->isChair)
                $roles |= self::ROLE_CHAIR;
            $this->assign_roles($roles);
        }
        if (isset($user->has_review))
            $this->has_review_ = $user->has_review;
        if (isset($user->has_outstanding_review))
            $this->has_outstanding_review_ = $user->has_outstanding_review;
        if (isset($user->is_site_contact))
            $this->is_site_contact = $user->is_site_contact;
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

    public function set_encoded_password($password) {
        if ($password === null || $password === false)
            $password = "";
        $this->password = $password;
        $this->password_type = substr($this->password, 0, 1) == " " ? 1 : 0;
        if ($this->password_type == 0)
            $this->password_plaintext = $password;
    }

    static public function site_contact() {
        global $Conf, $Opt;
        if (!@$Opt["contactEmail"] || $Opt["contactEmail"] == "you@example.com") {
            $result = $Conf->ql("select firstName, lastName, email from ContactInfo join Chair using (contactId) limit 1");
            $row = edb_orow($result);
            if (!$row) {
                $result = $Conf->ql("select firstName, lastName, email from ContactInfo join ChairAssistant using (contactId) limit 1");
                $row = edb_orow($result);
            }
            if ($row) {
                $Opt["defaultSiteContact"] = true;
                $Opt["contactName"] = Text::name_text($row);
                $Opt["contactEmail"] = $row->email;
            }
        }
        return new Contact((object) array("fullName" => $Opt["contactName"],
                                          "email" => $Opt["contactEmail"],
                                          "isChair" => true,
                                          "isPC" => true,
                                          "is_site_contact" => true,
                                          "contactTags" => null));
    }

    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
    }

    static function external_login() {
        global $Opt;
        return @$Opt["ldapLogin"] || @$Opt["httpAuthLogin"];
    }


    //
    // Initialization functions
    //

    function activate() {
        global $Conf, $Opt, $Now;
        $this->activated_ = true;
        $trueuser = @$_SESSION["trueuser"];

        // Handle actas requests
        if (isset($_REQUEST["actas"]) && $trueuser) {
            if (is_numeric($_REQUEST["actas"]))
                $actasemail = self::email_by_id($_REQUEST["actas"]);
            else if ($_REQUEST["actas"] === "admin")
                $actasemail = $trueuser->email;
            else
                $actasemail = $_REQUEST["actas"];
            unset($_REQUEST["actas"]);
            if ($actasemail
                && strcasecmp($actasemail, $this->email) != 0
                && (strcasecmp($actasemail, $trueuser->email) == 0
                    || $this->privChair
                    || (($truecontact = self::find_by_email($trueuser->email))
                        && $truecontact->privChair))
                && ($actascontact = self::find_by_email($actasemail))) {
                $Conf->save_session("l", null);
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                return $actascontact->activate();
            }
        }

        // Handle invalidate-caches requests
        if (@$_REQUEST["invalidatecaches"] && $this->privChair) {
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

        // Maybe auto-create a user
        if ($trueuser && $this->update_trueuser(false)
            && !$this->has_database_account()
            && $Conf->session("trueuser_author_check", 0) + 600 < $Now) {
            $Conf->save_session("trueuser_author_check", $Now);
            $aupapers = self::email_authored_papers($trueuser->email, $trueuser);
            if (count($aupapers))
                return $this->activate_database_account();
        }

        // Maybe set up the shared contacts database
        if (@$Opt["contactdb_dsn"] && $this->has_database_account()
            && $Conf->session("contactdb_roles", 0) != $this->all_roles()) {
            if ($this->contactdb_update())
                $Conf->save_session("contactdb_roles", $this->all_roles());
        }

        return $this;
    }

    public function activate_database_account() {
        assert(!$this->has_database_account() && $this->has_email());
        $contact = self::find_by_email($this->email, $_SESSION["trueuser"], false);
        return $contact ? $contact->activate() : $this;
    }

    static public function contactdb() {
        global $Opt;
        if (self::$contactdb_dblink === false) {
            self::$contactdb_dblink = null;
            if (@$Opt["contactdb_dsn"])
                list(self::$contactdb_dblink, $dbname) = Dbl::connect_dsn($Opt["contactdb_dsn"]);
        }
        return self::$contactdb_dblink;
    }

    static public function contactdb_find_by_email($email) {
        if (($cdb = self::contactdb())
            && ($result = Dbl::ql($cdb, "select * from ContactInfo where email=?", $email))
            && ($row = $result->fetch_object()))
            return new Contact($row);
        else
            return null;
    }

    static public function contactdb_find_by_id($cid) {
        if (($cdb = self::contactdb())
            && ($result = Dbl::ql($cdb, "select * from ContactInfo where contactDbId=?", $cid))
            && ($row = $result->fetch_object()))
            return new Contact($row);
        else
            return null;
    }

    public function contactdb_update() {
        global $Opt, $Now;
        if (!($dblink = self::contactdb()) || !$this->has_database_account())
            return false;
        $idquery = Dbl::format_query($dblink, "select ContactInfo.contactDbId, Conferences.confid, roles
            from ContactInfo join Conferences
            left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=Conferences.confid)
            where email=? and `dbname`=?", $this->email, $Opt["dbName"]);
        $result = $dblink->query($idquery);
        $row = edb_row($result);
        if (!$row) {
            $result = Dbl::ql($dblink, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=firstName", $this->firstName, $this->lastName, $this->email, $this->affiliation);
            $result = $dblink->query($idquery);
            $row = edb_row($result);
        }

        if ($row && (int) $row[2] != $this->all_roles()) {
            $result = Dbl::ql($dblink, "insert into Roles set contactDbId=?, confid=?, roles=?, updated_at=? on duplicate key update roles=values(roles), updated_at=values(updated_at)", $row[0], $row[1], $this->all_roles(), $Now);
            return !!$result;
        } else
            return false;
    }

    public function update_trueuser($always) {
        if (($trueuser = @$_SESSION["trueuser"])
            && strcasecmp($trueuser->email, $this->email) == 0) {
            foreach (array("firstName", "lastName", "affiliation") as $k)
                if ($this->$k && ($always || !@$trueuser->$k))
                    $trueuser->$k = $this->$k;
            return true;
        } else
            return false;
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
        return $this->contactId <= 0 && !$this->capabilities && !$this->email;
    }

    function has_email() {
        return !!$this->email;
    }

    static function is_anonymous_email($email) {
        // see also PaperSearch, Mailer
        return preg_match('/\Aanonymous\d*\z/', $email);
    }

    function is_anonymous_user() {
        return $this->email && self::is_anonymous_email($this->email);
    }

    function has_database_account() {
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

    function is_pc_member() {
        return $this->roles & self::ROLE_PC;
    }

    function is_pclike() {
        return $this->roles & self::ROLE_PCLIKE;
    }

    function has_tag($t) {
        if ($this->contactTags)
            return strpos($this->contactTags, " $t ") !== false;
        if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conference::/") . ": Contact $this->email contactTags missing");
            $this->contactTags = null;
        }
        return false;
    }

    function update_cached_roles() {
        foreach (array("is_author_", "has_review_", "has_outstanding_review_",
                       "is_requester_", "is_lead_", "is_manager_") as $k)
            unset($this->$k);
        ++$this->rights_version_;
    }

    private function load_author_reviewer_status() {
        global $Conf;

        // Load from database
        $result = null;
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
        }
        $row = edb_row($result);
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

    function all_roles() {
        $r = $this->roles;
        if ($this->is_author())
            $r |= self::ROLE_AUTHOR;
        if ($this->is_reviewer())
            $r |= self::ROLE_REVIEWER;
        return $r;
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
            $result = null;
            if ($this->contactId > 0)
                $result = Dbl::qe("select requestedBy from PaperReview where requestedBy=? and contactId!=? limit 1", $this->contactId, $this->contactId);
            $row = edb_row($result);
            $this->is_requester_ = $row && $row[0] > 1;
        }
        return $this->is_requester_;
    }

    function is_discussion_lead() {
        global $Conf;
        if (!isset($this->is_lead_)) {
            $result = null;
            if ($this->contactId > 0)
                $result = $Conf->qe("select paperId from Paper where leadContactId=$this->contactId limit 1");
            $this->is_lead_ = edb_nrows($result) > 0;
        }
        return $this->is_lead_;
    }

    function is_manager() {
        global $Conf;
        if (!isset($this->is_manager_)) {
            $result = null;
            if ($this->contactId > 0)
                $result = $Conf->qe("select paperId from Paper where managerContactId=$this->contactId limit 1");
            $this->is_manager_ = edb_nrows($result) > 0;
        }
        return $this->is_manager_;
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
            && ($result = $Conf->ql("select paperId, capVersion from Paper where paperId=$m[2]"))
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

    function review_token_cid($prow, $rrow = null) {
        if (!$this->review_tokens_)
            return null;
        if (!$rrow) {
            $ci = $prow->contact_info($this);
            return $ci->review_token_cid;
        } else if ($rrow->reviewToken
                   && array_search($rrow->reviewToken, $this->review_tokens_) !== false)
            return (int) $rrow->contactId;
        else
            return null;
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

    private function make_data() {
        if ($this->data_ && is_string($this->data_))
            $this->data_ = json_decode($this->data_);
    }

    function data($key = null) {
        $this->make_data();
        if ($key)
            return @$this->data_->$key;
        else
            return $this->data_;
    }

    private function encode_data() {
        if ($this->data_ && ($t = json_encode($this->data_)) !== "{}")
            return $t;
        else
            return null;
    }

    static private function merge_data_object(&$data, $value, $merge) {
        if ($value === null)
            unset($data);
        else if ($merge && (is_array($value) || is_object($value))) {
            if (!$data)
                $data = (object) array();
            foreach ($value as $k => $v)
                self::merge_data_object($data->$k, $v, $merge);
        } else
            $data = $value;
    }

    function save_data($key, $value) {
        global $Conf;
        $this->make_data();
        $old = $this->encode_data();
        self::merge_data_object($this->data_->$key, $value, false);
        $new = $this->encode_data();
        if ($old !== $new)
            $Conf->qe("update ContactInfo set data=" . ($this->data_ ? "'" . sqlq($new) . "'" : $new) . " where contactId=" . $this->contactId);
    }

    function merge_and_save_data($data) {
        global $Conf;
        $this->make_data();
        $old = $this->encode_data();
        self::merge_data_object($this->data_, (object) $data, true);
        $new = $this->encode_data();
        if ($old !== $new)
            $Conf->qe("update ContactInfo set data=" . ($this->data_ ? "'" . sqlq($new) . "'" : $new) . " where contactId=" . $this->contactId);
    }

    private function trim() {
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
            // Preserve post values across session expiration.
            $x = array();
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            if (@$_REQUEST["anchor"])
                $x["anchor"] = $_REQUEST["anchor"];
            $url = selfHref($x, array("raw" => true, "site_relative" => true));
            $_SESSION["login_bounce"] = array($Conf->dsn, $url, Navigation::page(), $_POST);
            if (check_post())
                error_go(false, "You’ve been logged out due to inactivity, so your changes have not been saved. After logging in, you may submit them again.");
            else
                error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }

    function save() {
        global $Conf, $Now, $Opt;
        $this->trim();
        $inserting = !$this->contactId;
        $qf = array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "voicePhoneNumber", "password", "collaborators",
                       "roles", "defaultWatch", "passwordTime") as $k)
            $qf[] = "$k='" . sqlq($this->$k) . "'";
        if ($this->preferredEmail != "")
            $qf[] = "preferredEmail='" . sqlq($this->preferredEmail) . "'";
        else
            $qf[] = "preferredEmail=null";
        if ($this->contactTags)
            $qf[] = "contactTags='" . sqlq($this->contactTags) . "'";
        else
            $qf[] = "contactTags=null";
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
        $result = Dbl::real_qe($Conf->dblink, $q);
        if (!$result)
            return $result;
        if ($inserting)
            $this->contactId = $result->insert_id;
        $Conf->ql("delete from ContactAddress where contactId=$this->contactId");
        if ($this->addressLine1 || $this->addressLine2 || $this->city
            || $this->state || $this->zipCode || $this->country) {
            $query = "insert into ContactAddress (contactId, addressLine1, addressLine2, city, state, zipCode, country) values ($this->contactId, '" . sqlq($this->addressLine1) . "', '" . sqlq($this->addressLine2) . "', '" . sqlq($this->city) . "', '" . sqlq($this->state) . "', '" . sqlq($this->zipCode) . "', '" . sqlq($this->country) . "')";
            $result = $Conf->qe($query);
        }

        // add to contact database
        if (@$Opt["contactdb_dsn"] && ($cdb = self::contactdb())) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=values(firstName), lastName=values(lastName), affiliation=values(affiliation)",
                   $this->firstName, $this->lastName, $this->email, $this->affiliation);
            if ($this->password_plaintext
                && ($cdb_user = self::contactdb_find_by_email($this->email))
                && !$cdb_user->password
                && !$cdb_user->disable_shared_password
                && !@$Opt["contactdb_noPasswords"])
                $cdb_user->change_password($this->password_plaintext, true);
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
        global $Conf;
        $result = $Conf->q("select ContactInfo.* from ContactInfo where $where");
        if (($row = edb_orow($result))) {
            $this->merge($row);
            return true;
        } else
            return false;
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
        $reg_keys = array("firstName", "lastName", "affiliation", "collaborators",
                          "voicePhoneNumber", "preferredEmail");

        // Set up registration
        $name = Text::analyze_name($reg);
        $reg->firstName = $name->firstName;
        $reg->lastName = $name->lastName;

        // Combine with information from contact database
        $cdb_user = null;
        if (@$Opt["contactdb_dsn"])
            $cdb_user = self::contactdb_find_by_email($email);
        if ($cdb_user)
            foreach ($reg_keys as $k)
                if (@$cdb_user->$k && !@$reg->$k)
                    $reg->$k = $cdb_user->$k;

        if (($password = @trim($reg->password)) !== "")
            $this->change_password($password, false);
        else if ($cdb_user && $cdb_user->password
                 && !$cdb_user->disable_shared_password)
            $this->set_encoded_password($cdb_user->password);
        else
            // Always store initial, randomly-generated user passwords in
            // plaintext. The first time a user logs in, we will encrypt
            // their password.
            //
            // Why? (1) There is no real security problem to storing random
            // values. (2) We get a better UI by storing the textual password.
            // Specifically, if someone tries to "create an account", then
            // they don't get the email, then they try to create the account
            // again, the password will be visible in both emails.
            $this->set_encoded_password(self::random_password());

        $best_email = @$reg->preferredEmail ? $reg->preferredEmail : $email;
        $authored_papers = Contact::email_authored_papers($best_email, $reg);

        // Set up query
        $qa = "email, password, creationTime";
        $qb = "'" . sqlq($email) . "','" . sqlq($this->password) . "',$Now";
        foreach ($reg_keys as $k)
            if (isset($reg->$k)) {
                $qa .= ",$k";
                $qb .= ",'" . sqlq($reg->$k) . "'";
            }
        $result = Dbl::real_ql("insert into ContactInfo ($qa) values ($qb)");
        if (!$result)
            return false;
        $cid = (int) $result->insert_id;
        if (!$cid)
            return false;

        // Having added, load it
        if (!$this->load_by_query("ContactInfo.contactId=$cid"))
            return false;

        // Success! Save newly authored papers
        if (count($authored_papers))
            $this->save_authored_papers($authored_papers);
        // Maybe add to contact db
        if (@$Opt["contactdb_dsn"] && !$cdb_user)
            $this->contactdb_update();

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
        if (!$reg || !validate_email($email))
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
        if ($Me && $Me->has_email() && $Me->email !== $this->email)
            $Conf->log("Created account ($Me->email)", $this);
        else
            $Conf->log("Created account", $this);
    }

    function load_address() {
        global $Conf;
        if ($this->addressLine1 === false && $this->contactId) {
            $result = $Conf->ql("select * from ContactAddress where contactId=$this->contactId");
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

    static function email_by_id($id) {
        global $Conf;
        $result = $Conf->qe("select email from ContactInfo where contactId=" . (int) $id);
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

    public function allow_administer($prow) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer;
        } else
            return $this->privChair;
    }

    public function can_change_password($acct) {
        global $Opt;
        if (@$Opt["chairHidePasswords"])
            return @$_SESSION["trueuser"] && $acct && $acct->email
                && $_SESSION["trueuser"]->email == $acct->email;
        else
            return $this->privChair
                || ($acct && $this->contactId > 0 && $this->contactId == $acct->contactId);
    }

    public function can_administer($prow, $forceShow = null) {
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

    public function actAuthorView($prow) {
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
            && ($prow->outcome >= 0 || !$Conf->timeAuthorViewDecision())
            && ($Conf->timeUpdatePaper($prow) || $override))
            return true;
        // collect failure reasons
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($Conf->timeAuthorViewDecision() && $prow->outcome < 0)
            $whyNot["rejected"] = 1;
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
            $whyNot["rejected"] = 1;
        else  if (!$Conf->collectFinalPapers())
            $whyNot["deadline"] = "final_open";
        else if (!$Conf->timeSubmitFinalPaper() && !$override)
            $whyNot["deadline"] = "final_done";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return false;
    }

    function canViewPaper($prow, &$whyNot = null, $pdf = false) {
        global $Conf;
        // fetch paper
        if (!($prow = $this->_fetchPaperRow($prow, $whyNot)))
            return false;
        $rights = $this->rights($prow, "any");
        // policy
        if ($this->privChair
            || $rights->allow_author_view
            || ($rights->review_type
                && $Conf->timeReviewerViewSubmittedPaper())
            || ($rights->allow_pc_broad
                && $Conf->timePCViewPaper($prow, $pdf)
                && (!$pdf || $Conf->check_tracks($prow, $this, "viewpdf"))))
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
            && !$Conf->timePCViewPaper($prow, $pdf))
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

    function can_view_pdf($prow, &$whyNot = null) {
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

    function allow_view_authors($prow, &$whyNot = null) {
        return $this->can_view_authors($prow, true, $whyNot);
    }

    function can_view_authors($prow, $forceShow = null, &$whyNot = null) {
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
                && $Conf->can_pc_see_all_submissions())
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

    function can_view_paper_option($prow, $opt, $forceShow = null,
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
        $oview = @$opt->visibility;
        if ($rights->act_author_view
            || (($rights->allow_administer
                 || $rights->review_type
                 || $rights->allow_pc_broad)
                && (($oview == "admin" && $rights->allow_administer)
                    || !$oview
                    || $oview == "rev"
                    || ($oview == "nonblind"
                        && $this->can_view_authors($prow, $forceShow)))
                && ($rights->allow_administer
                    || $rights->review_type
                    || !$opt->has_document()
                    || $Conf->check_tracks($prow, $this, "viewpdf"))))
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
            || ($this->review_tokens_
                && array_search($rrow->reviewToken, $this->review_tokens_) !== false)
            || ($rrow->requestedBy == $this->contactId
                && $rrow->reviewType == REVIEW_EXTERNAL
                && $Conf->setting("pcrev_editdelegate"));
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
        else if (!$Conf->time_review_open())
            $whyNot['deadline'] = "rev_open";
        else
            $whyNot["reviewNotComplete"] = 1;
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
        if (($rights->review_type >= REVIEW_PC
             || $rights->allow_administer)
            && (!$time
                || $Conf->time_review(null, false, true)
                || ($rights->allow_administer
                    && self::override_deadlines())))
            return true;
        // collect failure reasons
        if ($rights->review_type < REVIEW_PC)
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
            && $Conf->time_review(null, true, true)
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
            return $Conf->time_review($rrow, $rights->allow_pc, true);
        else if ($rights->allow_review
                 && $Conf->setting("pcrev_any") > 0)
            return $Conf->time_review(null, true, true);
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
             && $Conf->time_review($rrow, $rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $Conf->setting("pcrev_any") > 0
                && $Conf->time_review(null, true, true))
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

    function can_submit_review($prow, $rrow, &$whyNot = null) {
        global $Conf;
        if ($this->canReview($prow, $rrow, $whyNot, true)) {
            if ($this->can_clickthrough("review"))
                return true;
            $whyNot["clickthrough"] = 1;
        }
        return false;
    }

    function can_clickthrough($ctype) {
        global $Conf, $Opt;
        if (!$this->privChair && @$Opt["clickthrough_$ctype"]) {
            $csha1 = sha1($Conf->message_html("clickthrough_$ctype"));
            $data = $this->data("clickthrough");
            return $data && @$data->$csha1;
        } else
            return true;
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
            && ($Conf->setting("cmt_always") > 0
                || $Conf->time_review(null, $rights->allow_pc, true)
                || ($rights->allow_administer
                    && (!$submit || self::override_deadlines())))
            && (!$crow
                || $crow->contactId == $this->contactId
                || ($crow->contactId == $rights->review_token_cid
                    && $rights->review_token_cid)
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
            || ($crow_contactId == $rights->review_token_cid
                && $rights->review_token_cid)
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
            || ($rrow && ($rrow_contactId == $this->contactId
                          || $this->ownReview($rrow)
                          || ($rights->allow_pc
                              && @$rrow->requestedBy == $this->contactId)))
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
            return $this->can_view_authors($prow, $forceShow);
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
        if ($prow && $rrow && $this->ownReview($rrow))
            return VIEWSCORE_REVIEWERONLY - 1;

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

        // in general, can see information visible for all reviewers
        // but !$rrow => return best case: all information they entered
        if ($rrow)
            return VIEWSCORE_PC - 1;
        else
            return VIEWSCORE_REVIEWERONLY - 1;
    }

    function canViewTags($prow, $forceShow = null) {
        // see also PaperActions::all_tags
        global $Conf;
        if (!$prow)
            return $this->isPC;
        else {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc
                || ($rights->allow_pc_broad && $Conf->setting("tag_seeall") > 0);
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
        return $this->can_administer($prow);
    }


    function my_rounds() {
        global $Conf;
        $rounds = array();
        $where = array();
        if ($this->contactId)
            $where[] = "contactId=" . $this->contactId;
        if (($tokens = $this->review_tokens()))
            $where[] = "reviewToken in (" . join(",", $tokens) . ")";
        if (count($where)) {
            $result = $Conf->qe("select distinct reviewRound from PaperReview where " . join(" or ", $where));
            while (($row = edb_row($result)))
                $rounds[] = +$row[0];
        }
        sort($rounds);
        return $rounds;
    }

    function my_deadlines() {
        // Return cleaned deadline-relevant settings that this user can see.
        global $Conf, $Opt;
        $dlx = $Conf->deadlines();
        $now = $dlx["now"];
        $dl = array("now" => $now);
        if ($this->privChair)
            $dl["is_admin"] = true;
        foreach (array("sub_open", "resp_open") as $x)
            $dl[$x] = $dlx[$x] > 0;

        if ($dlx["sub_reg"] && $dlx["sub_reg"] != $dlx["sub_update"])
            $dl["sub_reg"] = $dlx["sub_reg"];
        if ($dlx["sub_update"] && $dlx["sub_update"] != $dlx["sub_sub"])
            $dl["sub_update"] = $dlx["sub_update"];
        $dl["sub_sub"] = $dlx["sub_sub"];
        $sb = $Conf->submission_blindness();
        if ($sb === Conference::BLIND_ALWAYS)
            $dl["sub_blind"] = true;
        else if ($sb === Conference::BLIND_OPTIONAL)
            $dl["sub_blind"] = "optional";
        else if ($sb === Conference::BLIND_UNTILREVIEW)
            $dl["sub_blind"] = "until-review";

        $dl["resp_done"] = $dlx["resp_done"];

        // final copy deadlines
        if ($dlx["final_open"] > 0) {
            $dl["final_open"] = true;
            if ($dlx["final_soft"] > $now)
                $dl["final_done"] = $dlx["final_soft"];
            else {
                $dl["final_done"] = $dlx["final_done"];
                $dl["final_ishard"] = true;
            }
        }

        // reviewer deadlines
        $revtypes = array();
        $rev_allowed = false;
        if ($this->is_reviewer()) {
            $dl["rev_open"] = $dlx["rev_open"] > 0;
            $rounds = $this->my_rounds();
            $dl["rev_rounds"] = array();
            $grace = $dl["rev_open"] ? @$dlx["rev_grace"] : 0;
            foreach ($this->my_rounds() as $i) {
                $round_name = $Conf->round_name($i, true);
                $dl["rev_rounds"][] = $i ? $round_name : "";
                $isuffix = $i ? "_$i" : "";
                $osuffix = $i ? "_$round_name" : "";
                foreach (array("pcrev", "extrev") as $rt) {
                    if ($rt == "pcrev" && !$this->isPC)
                        continue;
                    list($s, $h) = array($dlx["{$rt}_soft$isuffix"], $dlx["{$rt}_hard$isuffix"]);
                    if ($h && ($h < $now || $s < $now)) {
                        $dl["{$rt}_done$osuffix"] = $h;
                        $dl["{$rt}_ishard$osuffix"] = true;
                    } else if ($s)
                        $dl["{$rt}_done$osuffix"] = $s;
                    $revtypes[] = "{$rt}_done$osuffix";
                    if (!$dlx["{$rt}_hard$isuffix"]
                        || $dlx["{$rt}_hard$isuffix"] + $grace >= $now)
                        $rev_allowed = true;
                }
            }
            // blindness
            $rb = $Conf->review_blindness();
            if ($rb === Conference::BLIND_ALWAYS)
                $dl["rev_blind"] = true;
            else if ($rb === Conference::BLIND_OPTIONAL)
                $dl["rev_blind"] = "optional";
            // can authors see reviews?
            if ($Conf->timeAuthorViewReviews())
                $dl["au_allowseerev"] = true;
            $rev_allowed = $rev_allowed || $this->can_review_any();
        }

        // grace periods
        foreach (array("sub" => array("sub_reg", "sub_update", "sub_sub"),
                       "resp" => array("resp_done"),
                       "rev" => $revtypes,
                       "final" => array("final_done")) as $type => $dlnames) {
            if (@$dl["${type}_open"] && ($grace = $dlx["${type}_grace"])) {
                foreach ($dlnames as $dlname)
                    // Give a minute's notice that there will be a grace
                    // period to make the UI a little better.
                    if (@$dl[$dlname] && $dl[$dlname] + 60 < $now
                        && $dl[$dlname] + $grace >= $now)
                        $dl["${dlname}_ingrace"] = true;
            }
        }

        // activeness
        $rt = $this->isPC ? "pcrev_done" : "extrev_done";
        if ($rev_allowed)
            $dl["rev_allowed"] = true;
        if (@$dl["rev_allowed"] || ($this->is_reviewer() && $Conf->setting("cmt_always") > 0))
            $dl["cmt_allowed"] = true;
        if (@$dl["resp_open"] && (!@$dl["resp_done"] || $dl["resp_done"] >= $now || @$dl["resp_done_ingrace"]))
            $dl["resp_allowed"] = true;

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

    function has_reportable_deadline() {
        $dl = $this->my_deadlines();
        if (@$dl["sub_reg"] || @$dl["sub_update"] || @$dl["sub_sub"]
            || ($dl["resp_open"] && @$dl["resp_done"]))
            return true;
        if (@$dl["rev_rounds"] && @$dl["rev_open"])
            foreach ($dl["rev_rounds"] as $rname) {
                $suffix = $rname === "" ? "" : "_$rname";
                if (@$dl["pcrev_done$suffix"] || @$dl["extrev_done$suffix"])
                    return true;
            }
        return false;
    }


    function paper_status_info($row, $forceShow = null) {
        global $Conf;
        if ($row->timeWithdrawn > 0)
            return array("pstat_with", "Withdrawn");
        else if (@$row->outcome && $this->canViewDecision($row, $forceShow)) {
            $data = @self::$status_info_cache[$row->outcome];
            if (!$data) {
                $decclass = ($row->outcome > 0 ? "pstat_decyes" : "pstat_decno");

                $decs = $Conf->decision_map();
                $decname = @$decs[$row->outcome];
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


    public static function password_hmac_key($keyid) {
        global $Conf, $Opt;
        if ($keyid === null)
            $keyid = defval($Opt, "passwordHmacKeyid", 0);
        $key = @$Opt["passwordHmacKey.$keyid"];
        if (!$key && $keyid == 0)
            $key = @$Opt["passwordHmacKey"];
        if (!$key) /* backwards compatibility */
            $key = $Conf->setting_data("passwordHmacKey.$keyid");
        if (!$key) {
            error_log("missing passwordHmacKey.$keyid, using default");
            $key = "NdHHynw6JwtfSZyG3NYPTSpgPFG8UN8NeXp4tduTk2JhnSVy";
        }
        return $key;
    }

    public static function valid_password($password) {
        return $password != "" && trim($password) === $password
            && $password !== "*";
    }

    public function check_password($password) {
        global $Conf, $Opt;
        assert(!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]));
        if ($password == "" || $password === "*")
            return false;
        if ($this->password_type == 0)
            return $password === $this->password;
        if ($this->password_type == 1
            && ($hash_method_pos = strpos($this->password, " ", 1)) !== false
            && ($keyid_pos = strpos($this->password, " ", $hash_method_pos + 1)) !== false
            && strlen($this->password) > $keyid_pos + 17
            && function_exists("hash_hmac")) {
            $hash_method = substr($this->password, 1, $hash_method_pos - 1);
            $keyid = substr($this->password, $hash_method_pos + 1, $keyid_pos - $hash_method_pos - 1);
            $salt = substr($this->password, $keyid_pos + 1, 16);
            return hash_hmac($hash_method, $salt . $password,
                             self::password_hmac_key($keyid), true)
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

    private function preferred_password_keyid() {
        global $Opt;
        if ($this->contactDbId)
            return defval($Opt, "contactdb_passwordHmacKeyid", 0);
        else
            return defval($Opt, "passwordHmacKeyid", 0);
    }

    public function check_password_encryption($is_change) {
        global $Opt;
        if ($Opt["safePasswords"] < 1
            || ($Opt["safePasswords"] == 1 && !$is_change)
            || !function_exists("hash_hmac"))
            return false;
        if ($this->password_type == 0)
            return true;
        $expected_prefix = " " . self::password_hash_method() . " "
            . $this->preferred_password_keyid() . " ";
        return $this->password_type == 1
            && !str_starts_with($this->password, $expected_prefix . " ");
    }

    public function change_password($new_password, $save) {
        global $Conf, $Opt, $Now;
        // set password fields
        $this->password_type = 0;
        if ($new_password && $this->check_password_encryption(true))
            $this->password_type = 1;
        if (!$new_password)
            $new_password = self::random_password();
        $this->password_plaintext = $new_password;
        if ($this->password_type == 1) {
            $keyid = $this->preferred_password_keyid();
            $key = self::password_hmac_key($keyid);
            $hash_method = self::password_hash_method();
            $salt = hotcrp_random_bytes(16);
            $this->password = " " . $hash_method . " " . $keyid . " " . $salt
                . hash_hmac($hash_method, $salt . $new_password, $key, true);
        } else
            $this->password = $new_password;
        $this->passwordTime = $Now;
        // save possibly-encrypted password
        if ($save && $this->contactId)
            Dbl::ql($Conf->dblink, "update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
        if ($save && $this->contactDbId)
            Dbl::ql(self::contactdb(), "update ContactInfo set password=?, passwordTime=? where contactDbId=?", $this->password, $this->passwordTime, $this->contactDbId);
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
                     || $sendtype != "forgot")
                 && $this->password !== "*")
            $template = "@accountinfo";
        else {
            $capmgr = $Conf->capability_manager($this);
            $rest["capability"] = $capmgr->create(CAPTYPE_RESETPASSWORD, array("user" => $this, "timeExpires" => time() + 259200));
            $Conf->log("Created password reset request", $this);
            $template = "@resetpassword";
        }

        $mailer = new HotCRPMailer($this, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        if ($prep->sendable || !$sensitive
            || @$Opt["debugShowSensitiveEmail"]) {
            Mailer::send_preparation($prep);
            return $template;
        } else {
            $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    private static function unassigned_review_token() {
        global $Conf;
        while (1) {
            $token = mt_rand(1, 2000000000);
            $result = $Conf->qe("select reviewId from PaperReview where reviewToken=$token");
            if (edb_nrows($result) == 0)
                return ", reviewToken=$token";
        }
    }

    function assign_review($pid, $rrow, $reviewer_cid, $type, $extra = array()) {
        global $Conf, $Now, $reviewTypeName;
        $reviewId = $rrow ? $rrow->reviewId : 0;

        // can't delete a review that's in progress
        if ($type <= 0 && $rrow && $rrow->reviewType && $rrow->reviewModified) {
            if ($rrow->reviewType >= REVIEW_SECONDARY)
                $type = REVIEW_PC;
            else
                return $reviewId;
        }

        // change database
        if ($type > 0 && (!$rrow || !$rrow->reviewType)) {
            $qa = "";
            if (($t = $Conf->setting_data("rev_roundtag")))
                $qa .= ", reviewRound=" . $Conf->round_number($t, true);
            if (@$extra["mark_notify"])
                $qa .= ", timeRequestNotified=$Now";
            if (@$extra["token"])
                $qa .= self::unassigned_review_token();
            $q = "insert into PaperReview set paperId=$pid, contactId=$reviewer_cid, reviewType=$type, requestedBy=$this->contactId, timeRequested=$Now$qa";
        } else if ($type > 0 && $rrow->reviewType != $type)
            $q = "update PaperReview set reviewType=$type where reviewId=$rrow->reviewId";
        else if ($type <= 0 && $rrow && $rrow->reviewType)
            $q = "delete from PaperReview where reviewId=$rrow->reviewId";
        else
            return $rrow ? $rrow->reviewId : 0;

        if (!($result = Dbl::real_qe($q)))
            return false;

        if ($q[0] == "d") {
            $msg = "Removed " . $reviewTypeName[$rrow->reviewType] . " review";
            $reviewId = 0;
        } else if ($q[0] == "u")
            $msg = "Changed " . $reviewTypeName[$rrow->reviewType] . " review to " . $reviewTypeName[$type];
        else {
            $msg = "Added " . $reviewTypeName[$type] . " review";
            $reviewId = $result->insert_id;
        }
        $Conf->log($msg . " by " . $this->email, $reviewer_cid, $pid);

        if ($q[0] == "i")
            $Conf->ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
        // Mark rev_tokens setting for future update by
        // updateRevTokensSetting
        if ($rrow && @$rrow->reviewToken && $type <= 0)
            $Conf->settings["rev_tokens"] = -1;
        // Set pcrev_assigntime
        if ($q[0] == "i" && $type >= REVIEW_PC && $Conf->setting("pcrev_assigntime", 0) < $Now)
            $Conf->save_setting("pcrev_assigntime", $Now);
        return $reviewId;
    }

    function assign_paper_pc($pids, $type, $reviewer, $extra = array()) {
        global $Conf;

        // check arguments
        assert($type == "lead" || $type == "shepherd" || $type == "manager");
        if ($reviewer)
            $revcid = is_object($reviewer) ? $reviewer->contactId : $reviewer;
        else
            $revcid = 0;
        assert(is_int($revcid));
        if (!is_array($pids))
            $pids = array($pids);
        $px = array();
        foreach ($pids as $p) {
            assert((is_object($p) && is_numeric($p->paperId)) || is_numeric($p));
            $px[] = (int) (is_object($p) ? $p->paperId : $p);
        }

        // make assignments
        if (isset($extra["old_cid"]))
            $result = Dbl::qe("update Paper set {$type}ContactId=? where paperId" . sql_in_numeric_set($px) . " and {$type}ContactId=?", $revcid, $extra["old_cid"]);
        else
            $result = Dbl::qe("update Paper set {$type}ContactId=? where paperId" . sql_in_numeric_set($px), $revcid);

        // log, update settings
        if ($result && $result->affected_rows) {
            $this->log_activity_for($revcid, "Set $type", $px);
            if ($type == "lead" && !$revcid != !$Conf->setting("paperlead"))
                $Conf->update_paperlead_setting();
            if ($type == "manager" && !$revcid != !$Conf->setting("papermanager"))
                $Conf->update_papermanager_setting();
            return true;
        } else
            return false;
    }


    function mark_activity() {
        global $Conf, $Now;
        if (!$this->activity_at || $this->activity_at < $Now) {
            $this->activity_at = $Now;
            if ($this->contactId && !$this->is_anonymous_user())
                Dbl::ql("update ContactInfo set lastLogin=$Now where contactId=$this->contactId");
            if ($this->contactDbId)
                Dbl::ql(self::contactdb(), "update ContactInfo set activity_at=$Now where contactDbId=$this->contactDbId");
        }
    }

    function log_activity($text, $paperId = null) {
        global $Conf;
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $Conf->log($text, $this, $paperId);
    }

    function log_activity_for($user, $text, $paperId = null) {
        global $Conf;
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $Conf->log($text . " by $this->email", $user, $paperId);
    }

}
