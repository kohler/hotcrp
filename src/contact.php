<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact {

    // Information from the SQL definition
    public $contactId = 0;
    public $contactDbId = 0;
    private $cid;               // for forward compatibility
    public $firstName = "";
    public $lastName = "";
    public $unaccentedName = "";
    var $email = "";
    var $preferredEmail = "";
    var $sorter = "";
    var $affiliation = "";
    var $collaborators;
    var $voicePhoneNumber;
    var $password = "";
    public $password_type = 0;
    public $password_plaintext = "";
    public $passwordTime = 0;
    public $disabled = false;
    public $activity_at = false;
    private $data_ = null;
    private $topic_interests_ = null;
    var $defaultWatch = WATCH_COMMENT;

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
    private $is_explicit_manager_;
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
        else if ($this->contactId || $this->contactDbId)
            $this->db_load();
    }

    static public function make($o) {
        return new Contact($o);
    }

    private function merge($user) {
        global $Conf;
        if (!isset($user->dsn) || $user->dsn == $Conf->dsn) {
            if (isset($user->contactId))
                $this->contactId = $this->cid = (int) $user->contactId;
            //else if (isset($user->cid))
            //    $this->contactId = $this->cid = (int) $user->cid;
        }
        if (isset($user->contactDbId))
            $this->contactDbId = (int) $user->contactDbId;
        if (isset($user->firstName) && isset($user->lastName))
            $name = $user;
        else
            $name = Text::analyze_name($user);
        $this->firstName = (string) @$name->firstName;
        $this->lastName = (string) @$name->lastName;
        if (isset($user->unaccentedName))
            $this->unaccentedName = $user->unaccentedName;
        else if (isset($name->unaccentedName))
            $this->unaccentedName = $name->unaccentedName;
        else
            $this->unaccentedName = Text::unaccented_name($name);
        foreach (array("email", "preferredEmail", "affiliation", "voicePhoneNumber") as $k)
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
        if (isset($user->data) && $user->data)
            $this->data_ = array_to_object_recursive($user->data);
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

    private function db_load() {
        $this->contactId = $this->cid = (int) $this->contactId;
        $this->contactDbId = (int) $this->contactDbId;
        if ($this->unaccentedName === "")
            $this->unaccentedName = Text::unaccented_name($this->firstName, $this->lastName);
        self::set_sorter($this);
        if ($this->password)
            $this->set_encoded_password($this->password);
        if (isset($this->disabled))
            $this->disabled = !!$this->disabled;
        foreach (array("defaultWatch", "passwordTime") as $k)
            $this->$k = (int) $this->$k;
        if (!$this->activity_at && isset($this->lastLogin))
            $this->activity_at = (int) $this->lastLogin;
        if (isset($this->data) && $this->data)
            $this->data_ = array_to_object_recursive($this->data);
        if (isset($this->roles))
            $this->assign_roles((int) $this->roles);
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
            $this->contactId = $this->cid = $value;
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
        } else if (isset($c->unaccentedName)) {
            $c->sorter = trim("$c->unaccentedName $c->email");
            return;
        } else
            $c->sorter = trim("$c->firstName $c->lastName $c->email");
        if (preg_match('/[\x80-\xFF]/', $c->sorter))
            $c->sorter = UnicodeHelper::deaccent($c->sorter);
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
        global $Opt;
        if (!@$Opt["contactEmail"] || $Opt["contactEmail"] == "you@example.com") {
            $result = Dbl::ql("select firstName, lastName, email from ContactInfo where (roles&" . (self::ROLE_CHAIR | self::ROLE_ADMIN) . ")!=0 order by (roles&" . self::ROLE_CHAIR . ") desc limit 1");
            if ($result && ($row = $result->fetch_object())) {
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


    // initialization

    function activate() {
        global $Conf, $Opt, $Now;
        $this->activated_ = true;
        $trueuser = @$_SESSION["trueuser"];
        $truecontact = null;

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
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                if ($this->privChair || ($truecontact && $truecontact->privChair))
                    $actascontact->trueuser_privChair = true;
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
            if (($newc = self::find_by_email($Opt["validatorContact"]))) {
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
        assert($this->has_email());
        if (!$this->has_database_account()) {
            $reg = $_SESSION["trueuser"];
            if (strcasecmp($reg->email, $this->email) != 0)
                $reg = true;
            if (($c = self::find_by_email($this->email, $reg, false))) {
                $this->load_by_id($c->contactId);
                $this->activate();
            }
        }
        return $this;
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
            && ($result = Dbl::ql($cdb, "select *, password contactdb_encoded_password from ContactInfo where email=?", $email))
            && ($acct = $result->fetch_object("Contact")))
            return $acct;
        return null;
    }

    static public function contactdb_find_by_id($cid) {
        if (($cdb = self::contactdb())
            && ($result = Dbl::ql($cdb, "select *, password contactdb_encoded_password from ContactInfo where contactDbId=?", $cid))
            && ($acct = $result->fetch_object("Contact")))
            return $acct;
        return null;
    }

    public function contactdb_allow_password() {
        global $Opt;
        return !$this->disable_shared_password && !@$Opt["contactdb_noPasswords"];
    }

    public function contactdb_update() {
        global $Opt, $Now;
        if (!($dblink = self::contactdb()) || !$this->has_database_account())
            return false;
        $idquery = Dbl::format_query($dblink, "select ContactInfo.contactDbId, Conferences.confid, roles
            from ContactInfo
            left join Conferences on (Conferences.`dbname`=?)
            left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=Conferences.confid)
            where email=?", $Opt["dbName"], $this->email);
        $row = Dbl::fetch_first_row(Dbl::ql_raw($dblink, $idquery));
        if (!$row) {
            Dbl::ql($dblink, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=firstName", $this->firstName, $this->lastName, $this->email, $this->affiliation);
            $row = Dbl::fetch_first_row(Dbl::ql_raw($dblink, $idquery));
        }

        if ($row && $row[1] && (int) $row[2] != $this->all_roles()) {
            $result = Dbl::ql($dblink, "insert into Roles set contactDbId=?, confid=?, roles=?, updated_at=? on duplicate key update roles=values(roles), updated_at=values(updated_at)", $row[0], $row[1], $this->all_roles(), $Now);
            return !!$result;
        } else
            return false;
    }

    public function is_actas_user() {
        return $this->activated_
            && ($trueuser = @$_SESSION["trueuser"])
            && strcasecmp($trueuser->email, $this->email) != 0;
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
                $c = ($mm[3] == "a" ? self::CAP_AUTHORVIEW : 0);
                $this->change_capability((int) $mm[2], $c, $mm[1] !== "-");
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

    function capability($pid) {
        $caps = $this->capabilities ? : array();
        return @$caps[$pid] ? : 0;
    }

    function change_capability($pid, $c, $on = null) {
        global $Conf;
        if (!$this->capabilities)
            $this->capabilities = array();
        $oldval = @$this->capabilities[$pid] ? : 0;
        if ($on === null)
            $newval = ($c != null ? $c : 0);
        else
            $newval = ($oldval | ($on ? $c : 0)) & ~($on ? 0 : $c);
        if ($newval !== $oldval) {
            ++$this->rights_version_;
            if ($newval !== 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
        }
        if (!count($this->capabilities))
            $this->capabilities = null;
        if ($this->activated_ && $newval !== $oldval)
            $Conf->save_session("capabilities", $this->capabilities);
        return $newval != $oldval;
    }

    function apply_capability_text($text) {
        global $Conf;
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = Dbl::ql("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $Conf->capability_text($row, $m[3]);
            $text = substr($text, strlen($m[1]));
            if ($rowcap === $text
                || $rowcap === str_replace("/", "_", $text))
                return $this->change_capability((int) $m[2], self::CAP_AUTHORVIEW, $m[1] !== "-");
        }
        return null;
    }

    private function make_data() {
        if (is_string($this->data_))
            $this->data_ = json_decode($this->data_);
        if (!$this->data_)
            $this->data_ = (object) array();
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

    function save_data($key, $value) {
        $this->merge_and_save_data((object) array($key => array_to_object_recursive($value)));
    }

    function merge_data($data) {
        $this->make_data();
        object_replace_recursive($this->data_, array_to_object_recursive($data));
    }

    function merge_and_save_data($data) {
        $this->activate_database_account();
        $this->make_data();
        $old = $this->encode_data();
        object_replace_recursive($this->data_, array_to_object_recursive($data));
        $new = $this->encode_data();
        if ($old !== $new)
            Dbl::qe("update ContactInfo set data=? where contactId=$this->contactId", $new);
    }

    private function trim() {
        $this->contactId = (int) trim($this->contactId);
        $this->cid = $this->contactId;
        $this->firstName = simplify_whitespace($this->firstName);
        $this->lastName = simplify_whitespace($this->lastName);
        $this->unaccentedName = Text::unaccented_name($this->firstName, $this->lastName);
        foreach (array("email", "preferredEmail", "affiliation", "voicePhoneNumber") as $k)
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
        $qf = $qv = array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "voicePhoneNumber", "password", "collaborators",
                       "roles", "defaultWatch", "passwordTime") as $k) {
            $qf[] = "$k=?";
            $qv[] = $this->$k;
        }
        if ($Conf->sversion >= 90) {
            $qf[] = "unaccentedName=?";
            $qv[] = $this->unaccentedName;
        }
        $qf[] = "preferredEmail=?";
        $qv[] = $this->preferredEmail != "" ? $this->preferredEmail : null;
        $qf[] = "contactTags=?";
        $qv[] = $this->contactTags ? : null;
        $qf[] = "disabled=" . ($this->disabled ? 1 : 0);
        if ($Conf->sversion >= 71) {
            $qf[] = "data=?";
            if (!$this->data_ || is_string($this->data_))
                $qv[] = $this->data_ ? : null;
            else if (is_object($this->data_))
                $qv[] = json_encode($this->data_);
            else
                $qv[] = null;
        }
        $q = ($inserting ? "insert into" : "update")
            . " ContactInfo set " . join(", ", $qf);
        if ($inserting) {
            $this->creationTime = $Now;
            $q .= ", creationTime=$Now";
        } else
            $q .= " where contactId=" . $this->contactId;
        $result = Dbl::qe_apply($Conf->dblink, $q, $qv);
        if (!$result)
            return $result;
        if ($inserting)
            $this->contactId = $this->cid = $result->insert_id;

        // add to contact database
        if (@$Opt["contactdb_dsn"] && ($cdb = self::contactdb())) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=? on duplicate key update firstName=values(firstName), lastName=values(lastName), affiliation=values(affiliation)",
                    $this->firstName, $this->lastName, $this->email, $this->affiliation);
            if ($this->password_plaintext
                && ($cdb_user = self::contactdb_find_by_email($this->email))
                && !$cdb_user->password
                && $cdb_user->contactdb_allow_password())
                $cdb_user->change_password($this->password_plaintext, true);
        }

        return $result;
    }

    static function email_authored_papers($email, $reg) {
        $aupapers = array();
        $result = Dbl::q("select paperId, authorInformation from Paper where authorInformation like '%\t" . sqlq_for_like($email) . "\t%'");
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
        if (count($aupapers) && $this->contactId) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            Dbl::ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    function save_roles($new_roles, $actor) {
        global $Conf;
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN) && ($old_roles & self::ROLE_ADMIN)
            && !(($result = Dbl::qe("select contactId from ContactInfo where (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1"))
                 && edb_nrows($result) > 0))
            $new_roles |= self::ROLE_ADMIN;
        // log role change
        $actor_email = ($actor ? " by $actor->email" : "");
        foreach (array(self::ROLE_PC => "pc",
                       self::ROLE_ADMIN => "sysadmin",
                       self::ROLE_CHAIR => "chair") as $role => $type)
            if (($new_roles & $role) && !($old_roles & $role))
                $Conf->log("Added as $type$actor_email", $this);
            else if (!($new_roles & $role) && ($old_roles & $role))
                $Conf->log("Removed as $type$actor_email", $this);
        // save the roles bits
        if ($old_roles != $new_roles) {
            Dbl::qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function load_by_id($cid) {
        $result = Dbl::q("select ContactInfo.* from ContactInfo where contactId=?", $cid);
        if (($row = $result ? $result->fetch_object() : null))
            $this->merge($row);
        Dbl::free($result);
        return !!$row;
    }

    static function find_by_id($cid) {
        $result = Dbl::qe("select ContactInfo.* from ContactInfo where contactId=?", $cid);
        $c = $result ? $result->fetch_object("Contact") : null;
        Dbl::free($result);
        return $c;
    }

    static function safe_registration($reg) {
        $safereg = array();
        foreach (array("firstName", "lastName", "name", "preferredEmail",
                       "affiliation", "collaborators", "voicePhoneNumber",
                       "unaccentedName") as $k)
            if (isset($reg[$k]))
                $safereg[$k] = $reg[$k];
        return $safereg;
    }

    static private function safe_registration_with_contactdb($email, $reg) {
        global $Opt;
        $reg = (object) ($reg === true ? array() : $reg);
        $sreg = (object) array("email" => $email);

        $name = Text::analyze_name($reg);
        foreach (array("firstName", "lastName", "unaccentedName") as $k)
            $sreg->$k = $name->$k;

        foreach (array("affiliation", "collaborators", "voicePhoneNumber",
                       "preferredEmail", "password", "country") as $k)
            if (is_string(@$reg->$k) && $reg->$k)
                $sreg->$k = $reg->$k;

        if (@$Opt["contactdb_dsn"]
            && ($cdb_user = self::contactdb_find_by_email($email))) {
            $sreg->contactDbId = $cdb_user->contactDbId;
            foreach (array("firstName", "lastName", "unaccentedName",
                           "affiliation", "collaborators", "voicePhoneNumber",
                           "preferredEmail", "country") as $k)
                if (!@$sreg->$k && $cdb_user->$k)
                    $sreg->$k = $cdb_user->$k;
            if (!@$sreg->password && $cdb_user->password
                && $cdb_user->contactdb_allow_password())
                $sreg->encoded_password = $cdb_user->password;
        }

        if (@$reg->disabled)
            $sreg->disabled = 1;

        return $sreg;
    }

    private function register_by_email($sreg) {
        // For more complicated registrations, use UserStatus
        global $Conf, $Opt, $Now;

        if (@$sreg->password)
            $this->change_password($sreg->password, false);
        else if (@$sreg->encoded_password)
            $this->set_encoded_password($sreg->encoded_password);
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

        $best_email = @$sreg->preferredEmail ? : $sreg->email;
        $authored_papers = Contact::email_authored_papers($best_email, $sreg);

        // Insert
        $qf = array("email=?, password=?, creationTime=$Now");
        $qv = array($sreg->email, $this->password);
        $reg_keys = array("firstName", "lastName", "affiliation", "collaborators",
                          "voicePhoneNumber", "preferredEmail", "disabled");
        if ($Conf->sversion >= 90)
            $reg_keys[] = "unaccentedName";
        foreach ($reg_keys as $k)
            if (isset($sreg->$k)) {
                $qf[] = "$k=?";
                $qv[] = $sreg->$k;
            }
        $result = Dbl::ql_apply("insert into ContactInfo set " . join(", ", $qf), $qv);
        if (!$result)
            return false;
        $cid = (int) $result->insert_id;
        if (!$cid)
            return false;
        Dbl::free($result);

        // Having added, load it
        if (!$this->load_by_id($cid))
            return false;

        // Success! Save newly authored papers
        if (count($authored_papers))
            $this->save_authored_papers($authored_papers);
        // Maybe add to contact db
        if (@$Opt["contactdb_dsn"] && @$sreg->contactDbId) {
            $this->contactdb_update();
            $this->contactDbId = $sreg->contactDbId;
            $this->contactdb_encoded_password = @$sreg->encoded_password;
        }

        return true;
    }

    static function find_by_email($email, $reg = false, $send = false) {
        global $Conf, $Me;

        // Lookup by email
        $email = trim((string) $email);
        if ($email != "") {
            $result = Dbl::qe("select ContactInfo.* from ContactInfo where email=?", $email);
            $acct = $result ? $result->fetch_object("Contact") : null;
            Dbl::free($result);
            if ($acct)
                return $acct;
        }

        // Not found: register
        if (!$reg || !validate_email($email))
            return null;

        $sreg = self::safe_registration_with_contactdb($email, $reg);
        if (is_object($reg) && @$reg->only_if_contactdb
            && !@$sreg->contactDbId)
            return null;

        $acct = new Contact;
        $ok = $acct->register_by_email($sreg);

        // Log
        if ($ok)
            $acct->mark_create($send, true);
        else
            $Conf->log("Account $email creation failure", $Me);

        return $ok ? $acct : null;
    }

    function mark_create($send_email, $message_chair) {
        global $Conf, $Me;
        $account = $this->disabled ? "disabled account" : "account";
        if ($Me && $Me->privChair && $message_chair)
            $Conf->infoMsg("Created $account for <a href=\"" . hoturl("profile", "u=" . urlencode($this->email)) . "\">" . Text::user_html_nolink($this) . "</a>.");
        if ($send_email && !$this->disabled)
            $this->sendAccountInfo("create", false);
        if ($Me && $Me->has_email() && $Me->email !== $this->email)
            $Conf->log("Created $account ($Me->email)", $this);
        else
            $Conf->log("Created $account", $this);
    }

    static function id_by_email($email) {
        $result = Dbl::qe("select contactId from ContactInfo where email=?", trim($email));
        $row = edb_row($result);
        return $row ? $row[0] : false;
    }

    static function email_by_id($id) {
        $result = Dbl::qe("select email from ContactInfo where contactId=" . (int) $id);
        $row = edb_row($result);
        return $row ? $row[0] : false;
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
        return $password && trim($password) === $password
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

    public function change_password($new_password, $save, $plaintext = false) {
        global $Conf, $Opt, $Now;
        // set password fields
        $this->password_type = 0;
        if (!$new_password)
            $new_password = self::random_password();
        else if (!$plaintext && $this->check_password_encryption(true))
            $this->password_type = 1;
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
        if ($sendtype == "create" && $this->password
            && @$this->contactdb_encoded_password === $this->password)
            $template = "@activateaccount";
        else if ($sendtype == "create")
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


    // HotCRP roles

    function update_cached_roles() {
        foreach (array("is_author_", "has_review_", "has_outstanding_review_",
                       "is_requester_", "is_lead_", "is_explicit_manager_") as $k)
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
            $result = Dbl::qe("select max(conf.conflictType),
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
                $result = Dbl::qe("select paperId from Paper where leadContactId=$this->contactId limit 1");
            $this->is_lead_ = edb_nrows($result) > 0;
        }
        return $this->is_lead_;
    }

    function is_explicit_manager() {
        if (!isset($this->is_explicit_manager_)) {
            $result = null;
            if ($this->contactId > 0 && $this->isPC)
                $result = Dbl::qe("select paperId from Paper where managerContactId=$this->contactId limit 1");
            $this->is_explicit_manager_ = edb_nrows($result) > 0;
            Dbl::free($result);
        }
        return $this->is_explicit_manager_;
    }

    function is_manager() {
        return $this->privChair || $this->is_explicit_manager();
    }


    // review tokens

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


    // topic interests

    function topic_interest_map() {
        if ($this->topic_interest_map_ !== null)
            return $this->topic_interest_map_;
        else if ($this->contactId <= 0)
            return array();
        $result = Dbl::qe("select topicId, interest from TopicInterest where contactId={$this->contactId} and interest!=0");
        return ($this->topic_interest_map_ = edb_map($result));
    }


    // permissions policies

    private function rights(PaperInfo $prow, $forceShow = null) {
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

            // check author allowance
            $ci->act_author = $ci->conflict_type >= CONFLICT_AUTHOR;
            $ci->allow_author = $ci->act_author || $ci->allow_administer;

            // check author view allowance (includes capabilities)
            // If an author-view capability is set, then use it -- unless
            // this user is a PC member or reviewer, which takes priority.
            $ci->view_conflict_type = $ci->conflict_type;
            if (isset($this->capabilities)
                && isset($this->capabilities[$prow->paperId])
                && ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW)
                && !$isPC
                && $ci->review_type <= 0)
                $ci->view_conflict_type = CONFLICT_AUTHOR;
            $ci->act_author_view = $ci->view_conflict_type >= CONFLICT_AUTHOR;
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
                    && ($isPC || $ci->allow_review)
                    && $Conf->timeReviewerViewAcceptedAuthors());
        }
        return $ci;
    }

    public function override_deadlines($rights, $override = null) {
        if ($rights && $rights instanceof PaperInfo)
            $rights = $this->rights($rights);
        if ($rights ? !$rights->allow_administer : !$this->privChair)
            return false;
        else if ($override !== null)
            return !!$override;
        else
            return isset($_REQUEST["override"]) && $_REQUEST["override"] > 0;
    }

    public function allow_administer(PaperInfo $prow = null) {
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

    public function can_administer(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer;
        } else
            return $this->privChair;
    }

    public function act_pc(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc;
        } else
            return $this->privChair || $this->isPC;
    }

    public function view_conflict_type(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->view_conflict_type;
    }

    public function actAuthorView(PaperInfo $prow) {
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

    function can_start_paper($override = null) {
        global $Conf;
        return $Conf->timeStartPaper() || $this->override_deadlines(null, $override);
    }

    function perm_start_paper($override = null) {
        if ($this->can_start_paper($override))
            return null;
        return array("deadline" => "sub_reg", "override" => $this->privChair);
    }

    function can_edit_paper(PaperInfo $prow) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_administer || $prow->has_author($this);
    }

    function can_update_paper(PaperInfo $prow, $override = null) {
        global $Conf;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ((($prow->outcome >= 0 || !$Conf->timeAuthorViewDecision())
                 && $Conf->timeUpdatePaper($prow))
                || $this->override_deadlines($rights, $override));
    }

    function perm_update_paper(PaperInfo $prow, $override = null) {
        global $Conf;
        if ($this->can_update_paper($prow, $override))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = array("fail" => 1);
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($Conf->timeAuthorViewDecision() && $prow->outcome < 0)
            $whyNot["rejected"] = 1;
        if ($prow->timeSubmitted > 0 && $Conf->setting("sub_freeze") > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$Conf->timeUpdatePaper($prow) && !$this->override_deadlines($rights, $override))
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_finalize_paper(PaperInfo $prow) {
        global $Conf;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($Conf->timeFinalizePaper($prow) || $this->override_deadlines($rights));
    }

    function perm_finalize_paper(PaperInfo $prow) {
        global $Conf;
        if ($this->can_finalize_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = array("fail" => 1);
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->timeSubmitted > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$Conf->timeFinalizePaper($prow) && !$this->override_deadlines($rights))
            $whyNot["deadline"] = "finalizePaperSubmission";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_withdraw_paper(PaperInfo $prow, $override = null) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($prow->outcome == 0 || $this->override_deadlines($rights, $override));
    }

    function perm_withdraw_paper(PaperInfo $prow, $override = null) {
        if ($this->can_withdraw_paper($prow, $override))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = array("fail" => 1);
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        else if ($prow->outcome != 0 && !$this->override_deadlines($rights, $override))
            $whyNot["decided"] = 1;
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_revive_paper(PaperInfo $prow) {
        global $Conf;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn > 0
            && ($Conf->timeUpdatePaper($prow) || $this->override_deadlines($rights));
    }

    function perm_revive_paper(PaperInfo $prow) {
        global $Conf;
        if ($this->can_revive_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = array("fail" => 1);
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn <= 0)
            $whyNot["notWithdrawn"] = 1;
        if (!$Conf->timeUpdatePaper($prow) && !$this->override_deadlines($rights))
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_submit_final_paper(PaperInfo $prow, $override = null) {
        global $Conf;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $Conf->collectFinalPapers()
            && $prow->timeWithdrawn <= 0
            && $prow->outcome > 0
            && $Conf->timeAuthorViewDecision()
            && ($Conf->timeSubmitFinalPaper() || $this->override_deadlines($rights, $override));
    }

    function perm_submit_final_paper(PaperInfo $prow, $override = null) {
        global $Conf;
        if ($this->can_submit_final_paper($prow, $override))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = array("fail" => 1);
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
        else if (!$Conf->collectFinalPapers())
            $whyNot["deadline"] = "final_open";
        else if (!$Conf->timeSubmitFinalPaper() && !$this->override_deadlines($rights, $override))
            $whyNot["deadline"] = "final_done";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_view_paper(PaperInfo $prow, $pdf = false) {
        global $Conf;
        $rights = $this->rights($prow, "any");
        return $this->privChair
            || $rights->allow_author_view
            || ($rights->review_type
                && $Conf->timeReviewerViewSubmittedPaper())
            || ($rights->allow_pc_broad
                && $Conf->timePCViewPaper($prow, $pdf)
                && (!$pdf || $Conf->check_tracks($prow, $this, "viewpdf")));
    }

    function perm_view_paper(PaperInfo $prow, $pdf = false) {
        global $Conf;
        if ($this->can_view_paper($prow, $pdf))
            return null;
        $rights = $this->rights($prow, "any");
        if (!$rights->allow_author_view
            && !$rights->review_type
            && !$rights->allow_pc_broad)
            return array("permission" => 1);
        $whyNot = array("fail" => 1);
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
        return $whyNot;
    }

    function can_view_pdf(PaperInfo $prow) {
        return $this->can_view_paper($prow, true);
    }

    function perm_view_pdf(PaperInfo $prow) {
        return $this->perm_view_paper($prow, true);
    }

    function can_view_paper_manager(PaperInfo $prow = null) {
        global $Opt;
        if ($this->privChair)
            return true;
        if (!$prow)
            return $this->isPC && !@$Opt["hideManager"];
        $rights = $this->rights($prow);
        return $prow->managerContactId == $this->contactId
            || ($rights->potential_reviewer && !@$Opt["hideManager"]);
    }

    function can_view_lead(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer
                || $prow->leadContactId == $this->contactId
                || (($rights->allow_pc || $rights->allow_review)
                    && $this->can_view_review_identity($prow, null, $forceShow));
        } else
            return $this->privChair || $this->isPC;
    }

    function can_view_shepherd(PaperInfo $prow, $forceShow = null) {
        return $this->act_pc($prow, $forceShow)
            || $this->can_view_decision($prow, $forceShow);
    }

    /* NB caller must check can_view_paper() */
    function can_view_authors(PaperInfo $prow, $forceShow = null) {
        global $Conf;
        $rights = $this->rights($prow, $forceShow);
        return ($rights->nonblind
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
                : $rights->act_author_view);
    }

    function can_view_pc_conflicts(PaperInfo $prow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_administer
            || $rights->act_author_view
            || (($rights->allow_pc_broad || $rights->potential_reviewer)
                && $this->can_view_authors($prow, $forceShow));
    }

    function can_view_paper_option(PaperInfo $prow, $opt, $forceShow = null) {
        global $Conf;
        if (!is_object($opt) && !($opt = PaperOption::find($opt)))
            return false;
        $rights = $this->rights($prow, $forceShow);
        if (!$this->can_view_paper($prow))
            return false;
        $oview = @$opt->visibility;
        return $rights->act_author_view
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
                    || $Conf->check_tracks($prow, $this, "viewpdf")));
    }

    function is_my_review($rrow) {
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

    public function can_count_review(PaperInfo $prow, $rrow, $forceShow) {
        if ($rrow && $rrow->reviewNeedsSubmit <= 0
            && $rrow->reviewSubmitted <= 0)
            return false;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_administer
            || $rights->allow_pc
            || $rights->review_type
            || $this->can_view_review($prow, $rrow, $forceShow);
    }

    public static function can_some_author_view_submitted_review(PaperInfo $prow) {
        global $Conf;
        if ($Conf->au_seerev == Conference::AUSEEREV_TAGS)
            return $prow->has_any_tag($Conf->tag_au_seerev);
        else
            return $Conf->au_seerev != 0;
    }

    public function can_author_view_submitted_review(PaperInfo $prow) {
        global $Conf;
        return $Conf->au_seerev == Conference::AUSEEREV_YES
            || ($Conf->au_seerev == Conference::AUSEEREV_UNLESSINCOMPLETE
                && (!$this->has_review()
                    || !$this->has_outstanding_review()))
            || ($Conf->au_seerev == Conference::AUSEEREV_TAGS
                && $prow->has_any_tag($Conf->tag_au_seerev));
    }

    public function can_view_review(PaperInfo $prow, $rrow, $forceShow) {
        global $Conf;
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else
            $viewscore = VIEWSCORE_AUTHOR;
        assert(!$rrow || $prow->paperId == $rrow->paperId);
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer
            || ($rrow && $this->is_my_review($rrow)
                && $viewscore >= VIEWSCORE_REVIEWERONLY))
            return true;
        if (!($prow->timeSubmitted > 0 || $rights->review_type || $rights->allow_administer))
            return false;
        $rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = $Conf->setting("pc_seeallrev");
        $pc_trackok = $rights->allow_pc && $Conf->check_tracks($prow, $this, "viewrev");
        // See also PaperInfo::can_view_review_identity_of.
        return ($rights->act_author_view
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_AUTHOR
                && $this->can_author_view_submitted_review($prow))
            || ($rights->allow_pc
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && $pc_seeallrev > 0 // see also timePCViewAllReviews()
                && ($pc_seeallrev != Conference::PCSEEREV_UNLESSANYINCOMPLETE
                    || !$this->has_outstanding_review())
                && ($pc_seeallrev != Conference::PCSEEREV_UNLESSINCOMPLETE
                    || !$rights->review_type)
                && $pc_trackok)
            || ($rights->review_type
                && !$rights->view_conflict_type
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && (($prow->review_not_incomplete($this)
                     && ($Conf->settings["extrev_view"] >= 1 || $pc_trackok))
                    || $prow->leadContactId == $this->contactId));
    }

    function perm_view_review(PaperInfo $prow, $rrow, $forceShow) {
        global $Conf;
        if ($this->can_view_review($prow, $rrow, $forceShow))
            return null;
        $rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = $Conf->setting("pc_seeallrev");
        $rights = $this->rights($prow, $forceShow);
        $whyNot = array("fail" => 1);
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else if (!$rights->act_author_view
                 && !$rights->allow_pc
                 && !$rights->review_type)
            $whyNot["permission"] = 1;
        else if ($rights->act_author_view
                 && $Conf->au_seerev == Conference::AUSEEREV_UNLESSINCOMPLETE
                 && $this->has_outstanding_review()
                 && $this->has_review())
            $whyNot["reviewsOutstanding"] = 1;
        else if ($rights->act_author_view
                 && !$rrowSubmitted)
            $whyNot["permission"] = 1;
        else if ($rights->act_author_view)
            $whyNot["deadline"] = "au_seerev";
        else if ($rights->view_conflict_type)
            $whyNot["conflict"] = 1;
        else if (!$rights->allow_pc
                 && $prow->review_submitted($this))
            $whyNot["externalReviewer"] = 1;
        else if (!$rrowSubmitted)
            $whyNot["reviewNotSubmitted"] = 1;
        else if ($rights->allow_pc
                 && $pc_seeallrev == Conference::PCSEEREV_UNLESSANYINCOMPLETE
                 && $this->has_outstanding_review())
            $whyNot["reviewsOutstanding"] = 1;
        else if (!$Conf->time_review_open())
            $whyNot["deadline"] = "rev_open";
        else
            $whyNot["reviewNotComplete"] = 1;
        if ($rights->allow_administer)
            $whyNot["forceShow"] = 1;
        return $whyNot;
    }

    function can_view_review_identity($prow, $rrow, $forceShow = null) {
        global $Conf;
        $rights = $this->rights($prow, $forceShow);
        // See also PaperInfo::can_view_review_identity_of.
        return $rights->can_administer
            || ($rrow && ($this->is_my_review($rrow)
                          || ($rights->allow_pc
                              && @$rrow->requestedBy == $this->contactId)))
            || ($rights->allow_pc
                && (!($pc_seeblindrev = $Conf->setting("pc_seeblindrev"))
                    || ($pc_seeblindrev == 2
                        && $this->can_view_review($prow, $rrow, $forceShow))))
            || ($rights->allow_review
                && $prow->review_not_incomplete($this)
                && ($rights->allow_pc
                    || $Conf->settings["extrev_view"] >= 2))
            || !$Conf->is_review_blind($rrow);
    }

    function can_view_some_review_identity($forceShow = null) {
        $prow = new PaperInfo
            (array("conflictType" => 0, "managerContactId" => 0,
                   "myReviewType" => ($this->is_reviewer() ? 1 : 0),
                   "myReviewSubmitted" => 1,
                   "myReviewNeedsSubmit" => 0,
                   "paperId" => 1, "timeSubmitted" => 1,
                   "paperBlind" => false, "outcome" => 1), $this);
        return $this->can_view_review_identity($prow, null, $forceShow);
    }

    function can_view_aggregated_review_identity() {
        global $Conf;
        return $this->privChair
            || ($this->isPC
                && (!$Conf->setting("pc_seeblindrev") || !$Conf->is_review_blind(null)));
    }

    function can_view_review_round(PaperInfo $prow, $rrow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return $rights->can_administer
            || $rights->allow_pc
            || $rights->allow_review;
    }

    function can_view_review_time(PaperInfo $prow, $rrow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return !$rights->act_author_view
            || ($rrow && @$rrow->reviewAuthorSeen
                && $rrow->reviewAuthorSeen <= $rrow->reviewModified);
    }

    function can_request_review(PaperInfo $prow, $check_time) {
        global $Conf;
        $rights = $this->rights($prow);
        return ($rights->review_type >= REVIEW_PC
                 || $rights->allow_administer)
            && (!$check_time
                || $Conf->time_review(null, false, true)
                || $this->override_deadlines($rights));
    }

    function perm_request_review(PaperInfo $prow, $check_time) {
        global $Conf;
        if ($this->can_request_review($prow, $check_time))
            return null;
        $rights = $this->rights($prow);
        $whyNot = array("fail" => 1);
        if ($rights->review_type < REVIEW_PC)
            $whyNot["permission"] = 1;
        else {
            $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer)
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function can_review_any() {
        global $Conf;
        return $this->isPC
            && $Conf->setting("pcrev_any") > 0
            && $Conf->time_review(null, true, true)
            && $Conf->check_any_tracks($this, "unassrev");
    }

    function timeReview(PaperInfo $prow, $rrow) {
        global $Conf;
        $rights = $this->rights($prow);
        if ($rights->review_type > 0
            || $prow->reviewId
            || ($rrow
                && $this->is_my_review($rrow))
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

    function can_become_reviewer_ignore_conflict(PaperInfo $prow) {
        global $Conf;
        if (!$prow)
            return $this->isPC
                && ($Conf->check_all_tracks($this, "assrev")
                    || $Conf->check_all_tracks($this, "unassrev"));
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $Conf->check_tracks($prow, $this, "assrev")
                || $Conf->check_tracks($prow, $this, "unassrev"));
    }

    function can_accept_review_assignment_ignore_conflict(PaperInfo $prow) {
        global $Conf;
        if (!$prow)
            return $this->isPC && $Conf->check_all_tracks($this, "assrev");
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $Conf->check_tracks($prow, $this, "assrev"));
    }

    function can_accept_review_assignment(PaperInfo $prow) {
        global $Conf;
        $rights = $this->rights($prow);
        return $rights->allow_pc
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $Conf->check_tracks($prow, $this, "assrev"));
    }

    private function review_rights(PaperInfo $prow, $rrow) {
        $rights = $this->rights($prow);
        $rrow_cid = 0;
        if ($rrow) {
            $my_review = $rights->can_administer || $this->is_my_review($rrow);
            if (isset($rrow->reviewContactId))
                $rrow_cid = $rrow->reviewContactId;
            else if (isset($rrow->contactId))
                $rrow_cid = $rrow->contactId;
        } else
            $my_review = $rights->review_type > 0;
        return array($rights, $my_review, $rrow_cid);
    }

    private function rights_my_review($rights, $rrow) {
        if ($rrow)
            return $rights->can_administer || $this->is_my_review($rrow);
        else
            return $rights->review_type > 0;
    }

    function can_review(PaperInfo $prow, $rrow, $submit = false) {
        global $Conf;
        assert(!$rrow || $rrow->paperId == $prow->paperId);
        $rights = $this->rights($prow);
        if ($submit && !$this->can_clickthrough("review"))
            return false;
        return ($this->rights_my_review($rights, $rrow)
                && $Conf->time_review($rrow, $rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $Conf->setting("pcrev_any") > 0
                && $Conf->time_review(null, true, true))
            || ($rights->can_administer
                && ($prow->timeSubmitted > 0 || $rights->rights_force)
                && (!$submit || $this->override_deadlines($rights)));
    }

    function perm_review(PaperInfo $prow, $rrow, $submit = false) {
        if ($this->can_review($prow, $rrow, $submit))
            return null;
        $rights = $this->rights($prow);
        $rrow_cid = 0;
        if ($rrow && isset($rrow->reviewContactId))
            $rrow_cid = $rrow->reviewContactId;
        else if ($rrow && isset($rrow->contactId))
            $rrow_cid = $rrow->contactId;
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        $whyNot = array("fail" => 1);
        if ($rrow && $rrow_cid != $this->contactId
            && !$rights->allow_administer)
            $whyNot["differentReviewer"] = 1;
        else if (!$rights->allow_pc && !$this->rights_my_review($rights, $rrow))
            $whyNot["permission"] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            if ($rights->conflict_type && !$rights->can_administer)
                $whyNot["conflict"] = 1;
            else if ($rights->allow_review && !$this->rights_my_review($rights, $rrow)
                     && (!$rrow || $rrow_contactId == $this->contactId))
                $whyNot["reviewNotAssigned"] = 1;
            else if ($this->can_review($prow, $rrow, false)
                     && !$this->can_clickthrough("review"))
                $whyNot["clickthrough"] = 1;
            else
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer
                && ($rights->conflict_type || $prow->timeSubmitted <= 0))
                $whyNot["chairMode"] = 1;
            if ($rights->allow_administer && isset($whyNot["deadline"]))
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function perm_submit_review(PaperInfo $prow, $rrow) {
        return $this->perm_review($prow, $rrow, true);
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

    function can_view_review_ratings(PaperInfo $prow, $rrow) {
        global $Conf;
        $rs = $Conf->setting("rev_ratings");
        if ($rs != REV_RATINGS_PC && $rs != REV_RATINGS_PC_EXTERNAL)
            return false;
        $rights = $this->rights($prow);
        return $this->can_view_review($prow, $rrow, null)
            && ($rights->allow_pc || $rights->allow_review);
    }

    function can_rate_review(PaperInfo $prow, $rrow) {
        return $this->can_view_review_ratings($prow, $rrow)
            && !$this->is_my_review($rrow);
    }


    function can_comment(PaperInfo $prow, $crow, $submit = false) {
        global $Conf;
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->can_respond($prow, $crow, $submit);
        $rights = $this->rights($prow);
        return $rights->allow_review
            && ($prow->timeSubmitted > 0
                || $rights->review_type > 0
                || ($rights->allow_administer && $rights->rights_force))
            && ($Conf->setting("cmt_always") > 0
                || $Conf->time_review(null, $rights->allow_pc, true)
                || ($rights->allow_administer
                    && (!$submit || $this->override_deadlines($rights))))
            && (!$crow
                || $crow->contactId == $this->contactId
                || ($crow->contactId == $rights->review_token_cid
                    && $rights->review_token_cid)
                || $rights->allow_administer);
    }

    function can_submit_comment(PaperInfo $prow, $crow) {
        return $this->can_comment($prow, $crow, true);
    }

    function perm_comment(PaperInfo $prow, $crow, $submit = false) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->perm_respond($prow, $crow, $submit);
        if ($this->can_comment($prow, $crow, $submit))
            return null;
        $rights = $this->rights($prow);
        $whyNot = array("fail" => 1);
        if ($crow && $crow->contactId != $this->contactId
            && !$rights->allow_administer)
            $whyNot["differentReviewer"] = 1;
        else if (!$rights->allow_pc && !$rights->allow_review)
            $whyNot["permission"] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            if ($rights->conflict_type > 0)
                $whyNot["conflict"] = 1;
            else
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer && $rights->conflict_type)
                $whyNot["chairMode"] = 1;
            if ($rights->allow_administer && isset($whyNot['deadline']))
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function perm_submit_comment(PaperInfo $prow, $crow) {
        return $this->perm_comment($prow, $crow, true);
    }

    function can_respond(PaperInfo $prow, $crow, $submit = false) {
        global $Conf;
        $rights = $this->rights($prow);
        return $prow->timeSubmitted > 0
            && ($rights->can_administer
                || $rights->act_author)
            && (!$crow
                || ($crow->commentType & COMMENTTYPE_RESPONSE))
            && (($rights->allow_administer
                 && (!$submit || $this->override_deadlines($rights)))
                || $Conf->time_author_respond($crow ? (int) $crow->commentRound : null));
    }

    function perm_respond(PaperInfo $prow, $crow, $submit = false) {
        if ($this->can_respond($prow, $crow, $submit))
            return null;
        $rights = $this->rights($prow);
        $whyNot = array("fail" => 1);
        if (!$rights->allow_administer
            && !$rights->act_author)
            $whyNot["permission"] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            $whyNot["deadline"] = "resp_done";
            if ($crow && (int) $crow->commentRound)
                $whyNot["deadline"] .= "_" . $crow->commentRound;
            if ($rights->allow_administer && $rights->conflict_type)
                $whyNot["chairMode"] = 1;
            if ($rights->allow_administer)
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function can_view_comment(PaperInfo $prow, $crow, $forceShow) {
        global $Conf;
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
        return $crow_contactId == $this->contactId        // wrote this comment
            || ($crow_contactId == $rights->review_token_cid
                && $rights->review_token_cid)
            || $rights->can_administer
            || ($rights->act_author_view
                && $ctype >= COMMENTTYPE_AUTHOR
                && (($ctype & COMMENTTYPE_RESPONSE)    // author's response
                    || (!($ctype & COMMENTTYPE_DRAFT)  // author-visible cmt
                        && $this->can_author_view_submitted_review($prow))))
            || (!$rights->view_conflict_type
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->can_view_review($prow, null, $forceShow)
                && (($rights->allow_pc
                     && (!$Conf->setting("pc_seeblindrev")
                         || $prow->leadContactId == $this->contactId))
                    || $prow->review_not_incomplete($this))
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER));
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

    function can_view_comment_identity(PaperInfo $prow, $crow, $forceShow) {
        global $Conf;
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->can_view_authors($prow, $forceShow);
        $crow_contactId = 0;
        if ($crow && isset($crow->commentContactId))
            $crow_contactId = $crow->commentContactId;
        else if ($crow)
            $crow_contactId = $crow->contactId;
        $rights = $this->rights($prow, $forceShow);
        return $rights->can_administer
            || $crow_contactId == $this->contactId
            || $rights->allow_pc
            || ($rights->allow_review
                && $Conf->settings["extrev_view"] >= 2)
            || !$Conf->is_review_blind(!$crow || ($crow->commentType & COMMENTTYPE_BLIND) != 0);
    }


    function can_view_decision(PaperInfo $prow, $forceShow = null) {
        global $Conf;
        $rights = $this->rights($prow, $forceShow);
        return $rights->can_administer
            || ($rights->act_author_view
                && $Conf->timeAuthorViewDecision())
            || ($rights->allow_pc_broad
                && $Conf->timePCViewDecision($rights->view_conflict_type > 0))
            || ($rights->review_type > 0
                && $rights->review_submitted
                && $Conf->timeReviewerViewDecision());
    }

    function can_set_decision(PaperInfo $prow) {
        return $this->can_administer($prow);
    }

    // A review field is visible only if viewScore > view_score_bound.
    function view_score_bound(PaperInfo $prow, $rrow, $forceShow = null) {
        // Returns the maximum authorView score for an invisible review
        // field.  Values for authorView are:
        //   VIEWSCORE_ADMINONLY     -2   admin can view
        //   VIEWSCORE_REVIEWERONLY  -1   admin and review author can view
        //   VIEWSCORE_PC             0   admin and PC/any reviewer can view
        //   VIEWSCORE_AUTHOR         1   admin and PC/any reviewer and author can view
        // So returning -3 means all scores are visible.
        // Deadlines are not considered.
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer)
            return VIEWSCORE_ADMINONLY - 1;
        else if ($rrow && $this->is_my_review($rrow))
            return VIEWSCORE_REVIEWERONLY - 1;
        else if (!$this->can_view_review($prow, $rrow, $forceShow))
            return VIEWSCORE_MAX + 1;
        else if ($rights->act_author_view)
            return VIEWSCORE_AUTHOR - 1;
        else
            return VIEWSCORE_PC - 1;
    }

    function permissive_view_score_bound() {
        global $Conf;
        if ($this->is_manager())
            return VIEWSCORE_ADMINONLY - 1;
        else if ($this->is_reviewer())
            return VIEWSCORE_REVIEWERONLY - 1;
        else if ($this->is_author() && $Conf->timeAuthorViewReviews())
            return VIEWSCORE_AUTHOR - 1;
        else
            return VIEWSCORE_MAX + 1;
    }

    function aggregated_view_score_bound() {
        // XXX Every time this function is used it represents a problem.
        // For instance, privChair users can view admin-only scores for
        // papers that have explicit administrators.
        // Should use permissive_view_score_bound() and then restrict
        // what is actually visible based on per-review view_score_bound.
        if ($this->privChair)
            return VIEWSCORE_ADMINONLY - 1;
        else if ($this->isPC)
            return VIEWSCORE_PC - 1;
        else
            return VIEWSCORE_MAX + 1;
    }

    function can_view_tags(PaperInfo $prow = null, $forceShow = null) {
        // see also PaperActions::all_tags
        global $Conf;
        if (!$prow)
            return $this->isPC;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_pc
            || ($rights->allow_pc_broad && $Conf->setting("tag_seeall") > 0);
    }

    function can_change_tag(PaperInfo $prow, $tag, $previndex, $index, $forceShow = null) {
        global $Conf;
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer || $forceShow === ALWAYS_OVERRIDE)
            return true;
        else if (!$rights->allow_pc
                 || !$Conf->timePCViewPaper($prow, false))
            return false;
        else if (!$tag)
            return true;
        $tag = TagInfo::base($tag);
        $twiddle = strpos($tag, "~");
        if (($twiddle === 0 && $tag[1] === "~")
            || ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId))
            return false;
        else if ($twiddle !== false) {
            $t = TagInfo::defined_tag(substr($tag, $twiddle + 1));
            return !($t && $t->vote && $index < 0);
        } else {
            $t = TagInfo::defined_tag($tag);
            return !($t && ($t->chair || $t->vote || $t->rank));
        }
    }

    function perm_change_tag(PaperInfo $prow, $tag, $previndex, $index, $forceShow = null) {
        global $Conf;
        if ($this->can_change_tag($prow, $tag, $previndex, $index, $forceShow))
            return null;
        $rights = $this->rights($prow, $forceShow);
        $whyNot = array("fail" => 1, "tag" => $tag, "paperId" => $prow->paperId);
        if (!$this->isPC)
            $whyNot["permission"] = true;
        else if ($rights->conflict_type > 0) {
            $whyNot["conflict"] = true;
            if ($rights->allow_administer)
                $whyNot["forceShow"] = true;
        } else if (!$Conf->timePCViewPaper($prow, false)) {
            if ($prow->timeWithdrawn > 0)
                $whyNot["withdrawn"] = true;
            else
                $whyNot["notSubmitted"] = true;
        } else {
            $tag = TagInfo::base($tag);
            $twiddle = strpos($tag, "~");
            if (($twiddle === 0 && $tag[1] === "~")
                || ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId))
                $whyNot["otherTwiddleTag"] = true;
            else if ($twiddle !== false)
                $whyNot["voteTagNegative"] = true;
            else {
                $t = TagInfo::defined_tag($tag);
                if ($t && $t->vote)
                    $whyNot["voteTag"] = true;
                else
                    $whyNot["chairTag"] = true;
            }
        }
        return $whyNot;
    }

    function can_change_some_tag(PaperInfo $prow, $forceShow = null) {
        return $this->can_change_tag($prow, null, null, null, $forceShow);
    }

    function perm_change_some_tag(PaperInfo $prow, $forceShow = null) {
        return $this->perm_change_tag($prow, null, null, null, $forceShow);
    }

    function can_view_reviewer_tags(PaperInfo $prow) {
        return $this->act_pc($prow);
    }


    // deadlines

    function my_deadlines($prows = null) {
        // Return cleaned deadline-relevant settings that this user can see.
        global $Conf, $Opt, $Now;
        $set = $Conf->settings;
        $dl = (object) array("now" => $Now,
                             "sub" => (object) array(),
                             "rev" => (object) array());
        if ($this->privChair)
            $dl->is_admin = true;

        // submissions
        $dl->sub->open = @+$set["sub_open"] > 0;
        $dl->sub->sub = @+$set["sub_sub"];
        $dl->sub->grace = "sub_grace";
        if (@$set["sub_reg"] && $set["sub_reg"] != @$set["sub_update"])
            $dl->sub->reg = $set["sub_reg"];
        if (@$set["sub_update"] && $set["sub_update"] != @$set["sub_sub"])
            $dl->sub->update = $set["sub_update"];

        $sb = $Conf->submission_blindness();
        if ($sb === Conference::BLIND_ALWAYS)
            $dl->sub->blind = true;
        else if ($sb === Conference::BLIND_OPTIONAL)
            $dl->sub->blind = "optional";
        else if ($sb === Conference::BLIND_UNTILREVIEW)
            $dl->sub->blind = "until-review";

        // responses
        if (@$set["resp_active"] > 0) {
            $dl->resp = (object) array("rounds" => array(), "roundsuf" => array());
            foreach ($Conf->resp_round_list() as $i => $rname) {
                $osuf = $rname != "1" ? ".$rname" : "";
                $dl->resp->rounds[] = $rname;
                $dl->resp->roundsuf[] = $osuf;
                $k = "resp" . $osuf;
                $dlresp = $dl->$k = @$dl->$k ? : (object) array();
                $isuf = $i ? "_$i" : "";
                $dlresp->open = @+$set["resp_open$isuf"];
                $dlresp->done = @+$set["resp_done$isuf"];
                $dlresp->grace = "resp_grace$isuf";
            }
        }

        // final copy deadlines
        if (@+$set["final_open"] > 0) {
            $dl->final = (object) array("open" => true);
            if (@+$set["final_soft"] > $Now)
                $dl->final->done = $set["final_soft"];
            else {
                $dl->final->done = @+$set["final_done"];
                $dl->final->ishard = true;
            }
            $dl->final->grace = "final_grace";
        }

        // reviewer deadlines
        $revtypes = array();
        if ($this->is_reviewer() && @$set["rev_open"] > 0) {
            if (($rev_open = @+$set["rev_open"]))
                $dl->rev->open = $rev_open;
            $dl->rev->rounds = array();
            $dl->rev->roundsuf = array();
            $grace = $rev_open ? @$set["rev_grace"] : 0;
            $cur_round = $Conf->current_round_name();
            foreach ($Conf->round_list() as $i => $round_name) {
                if ($round_name === ";" && ($i || !$Conf->round0_defined()))
                    continue;
                $isuf = $i ? "_$i" : "";
                $jsuf = $i ? ".$round_name" : "";
                $dl->rev->rounds[] = $i ? $round_name : 0;
                $dl->rev->roundsuf[] = $jsuf;
                foreach (array("pcrev", "extrev") as $rt) {
                    if ($rt == "pcrev" && !$this->isPC)
                        continue;
                    list($s, $h) = array(@+$set["{$rt}_soft$isuf"], @+$set["{$rt}_hard$isuf"]);
                    $k = $rt . $jsuf;
                    $dlround = $dl->$k = (object) array("open" => $rev_open);
                    if ($h && ($h < $Now || $s < $Now)) {
                        $dlround->done = $h;
                        $dlround->ishard = true;
                    } else if ($s)
                        $dlround->done = $s;
                    $dlround->grace = "rev_grace";
                }
                if ($cur_round == $round_name)
                    $cur_round = false;
            }
            if ($this->privChair && $cur_round)
                $dl->rev->rounds[] = $cur_round;
            // blindness
            $rb = $Conf->review_blindness();
            if ($rb === Conference::BLIND_ALWAYS)
                $dl->rev->blind = true;
            else if ($rb === Conference::BLIND_OPTIONAL)
                $dl->rev->blind = "optional";
            // can authors see reviews?
            if ($Conf->au_seerev)
                $dl->au_allowseerev = true;
        }

        // grace periods: give a minute's notice of an impending grace
        // period
        foreach (get_object_vars($dl) as $dlsub) {
            if (@$dlsub->open && @$dlsub->grace && ($grace = @$set[$dlsub->grace]))
                foreach (array("reg", "update", "sub", "done") as $k)
                    if (@$dlsub->$k && $dlsub->$k + 60 < $Now
                        && $dlsub->$k + $grace >= $Now) {
                        $kgrace = "{$k}_ingrace";
                        $dlsub->$kgrace = true;
                    }
            unset($dlsub->grace);
        }

        // add meeting tracker
        $tracker = null;
        if (($this->isPC || @$this->is_tracker_kiosk)
            && $Conf->setting("tracker")
            && ($tracker = MeetingTracker::status($this))) {
            $dl->tracker = $tracker;
            $dl->tracker_status = MeetingTracker::tracker_status($tracker);
            $dl->now = microtime(true);
        }
        if (($this->isPC || @$this->is_tracker_kiosk)
            && @$Opt["trackerCometSite"])
            $dl->tracker_site = $Opt["trackerCometSite"]
                . "?conference=" . urlencode(Navigation::site_absolute(true));

        // permissions
        if ($prows) {
            if (is_object($prows))
                $prows = array($prows);
            $dl->perm = array();
            foreach ($prows as $prow) {
                if (!$this->can_view_paper($prow))
                    continue;
                $perm = $dl->perm[$prow->paperId] = (object) array();
                $admin = $this->allow_administer($prow);
                if ($admin)
                    $perm->allow_administer = true;
                if ($this->can_review($prow, null, false))
                    $perm->can_review = true;
                if ($this->can_comment($prow, null, true))
                    $perm->can_comment = true;
                else if ($admin && $this->can_comment($prow, null, false))
                    $perm->can_comment = "override";
                if (@$dl->resp)
                    foreach ($Conf->resp_round_list() as $i => $rname) {
                        $crow = (object) array("commentType" => COMMENTTYPE_RESPONSE, "commentRound" => $i);
                        $k = "can_respond" . ($rname == "1" ? "" : ".$rname");
                        if ($this->can_respond($prow, $crow, true))
                            $perm->$k = true;
                        else if ($admin && $this->can_respond($prow, $crow, false))
                            $perm->$k = "override";
                    }
            }
        }

        return $dl;
    }

    function has_reportable_deadline() {
        global $Now;
        $dl = $this->my_deadlines();
        if (@$dl->sub->reg || @$dl->sub->update || @$dl->sub->sub)
            return true;
        if (@$dl->resp)
            foreach ($dl->resp->roundsuf as $rsuf) {
                $dlk = "resp$rsuf";
                $dlr = $dl->$dlk;
                if (@$dlr->open && $dlr->open < $Now && @$dlr->done)
                    return true;
            }
        if (@$dl->rev && @$dl->rev->open && $dl->rev->open < $Now)
            foreach ($dl->rev->roundsuf as $rsuf) {
                $dlk = "pcrev$rsuf";
                if (@$dl->$dlk && @$dl->$dlk->done)
                    return true;
                $dlk = "extrev$rsuf";
                if (@$dl->$dlk && @$dl->$dlk->done)
                    return true;
            }
        return false;
    }


    function paper_status_info($row, $forceShow = null) {
        global $Conf;
        if ($row->timeWithdrawn > 0)
            return array("pstat_with", "Withdrawn");
        else if (@$row->outcome && $this->can_view_decision($row, $forceShow)) {
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


    private static function unassigned_review_token() {
        while (1) {
            $token = mt_rand(1, 2000000000);
            $result = Dbl::qe("select reviewId from PaperReview where reviewToken=$token");
            if (edb_nrows($result) == 0)
                return ", reviewToken=$token";
        }
    }

    function assign_review($pid, $reviewer_cid, $type, $extra = array()) {
        global $Conf, $Now, $reviewTypeName;
        $result = Dbl::qe("select reviewId, reviewType, reviewModified, reviewToken from PaperReview where paperId=? and contactId=?", $pid, $reviewer_cid);
        $rrow = edb_orow($result);
        Dbl::free($result);
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

        if (!($result = Dbl::qe_raw($q)))
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
            Dbl::ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
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
            if (($type == "lead" || $type == "shepherd") && !$revcid != !$Conf->setting("paperlead"))
                $Conf->update_paperlead_setting();
            if ($type == "manager" && !$revcid != !$Conf->setting("papermanager"))
                $Conf->update_papermanager_setting();
            return true;
        } else
            return false;
    }

}
