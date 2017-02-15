<?php
// contact.php -- HotCRP helper class representing system users
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Contact_Update {
    public $qv = [];
    public $cdb_uqv = [];
    public $different_email;
    public function __construct($inserting, $different_email) {
        if ($inserting)
            $this->qv["firstName"] = $this->qv["lastName"] = "";
        $this->different_email = $different_email;
    }
}

class Contact {
    static public $rights_version = 1;
    static public $trueuser_privChair = null;
    static public $allow_nonexistent_properties = false;

    public $contactId = 0;
    public $contactDbId = 0;
    private $cid;               // for forward compatibility
    public $conf;

    public $firstName = "";
    public $lastName = "";
    public $unaccentedName = "";
    public $nameAmbiguous = null;
    public $email = "";
    public $preferredEmail = "";
    public $sorter = "";
    public $sort_position = null;

    public $affiliation = "";
    public $country = null;
    public $collaborators;
    public $voicePhoneNumber;

    private $password = "";
    private $passwordTime = 0;
    private $passwordUseTime = 0;
    private $contactdb_user_ = false;

    public $disabled = false;
    public $activity_at = false;
    private $lastLogin = null;
    public $creationTime = 0;
    private $updateTime = 0;
    private $data = null;
    private $topic_interest_map_ = null;
    private $name_for_map_ = [];
    private $contact_sorter_map_ = [];
    public $defaultWatch = WATCHTYPE_COMMENT;

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    const ROLE_AUTHOR = 16;
    const ROLE_REVIEWER = 32;
    private $is_author_;
    private $has_review_;
    private $has_outstanding_review_ = null;
    private $is_requester_;
    private $is_lead_;
    private $is_explicit_manager_;
    public $is_site_contact = false;
    private $rights_version_ = 0;
    public $roles = 0;
    var $isPC = false;
    var $privChair = false;
    public $contactTags = null;
    public $tracker_kiosk_state = false;
    const CAP_AUTHORVIEW = 1;
    private $capabilities = null;
    private $review_tokens_ = null;
    private $activated_ = false;

    // Per-paper DB information, usually null
    public $myReviewType = null;
    public $myReviewSubmitted = null;
    public $myReviewNeedsSubmit = null;
    public $conflictType = null;
    public $watch = null;

    static private $status_info_cache = array();
    static private $contactdb_dblink = false;
    static private $active_forceShow = false;


    public function __construct($trueuser = null, Conf $conf = null) {
        global $Conf;
        $this->conf = $conf ? : $Conf;
        if ($trueuser)
            $this->merge($trueuser);
        else if ($this->contactId || $this->contactDbId)
            $this->db_load();
        else if ($this->conf->opt("disableNonPC"))
            $this->disabled = true;
    }

    public static function fetch($result, Conf $conf = null) {
        global $Conf;
        $conf = $conf ? : $Conf;
        $user = $result ? $result->fetch_object("Contact", [null, $conf]) : null;
        if ($user && !is_int($user->contactId)) {
            $user->conf = $conf;
            $user->db_load();
        }
        return $user;
    }

    private function merge($user) {
        if (is_array($user))
            $user = (object) $user;
        if (!isset($user->dsn) || $user->dsn == $this->conf->dsn) {
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
        $this->firstName = get_s($name, "firstName");
        $this->lastName = get_s($name, "lastName");
        if (isset($user->unaccentedName))
            $this->unaccentedName = $user->unaccentedName;
        else if (isset($name->unaccentedName))
            $this->unaccentedName = $name->unaccentedName;
        else
            $this->unaccentedName = Text::unaccented_name($name);
        foreach (array("email", "preferredEmail", "affiliation",
                       "voicePhoneNumber", "country") as $k)
            if (isset($user->$k))
                $this->$k = simplify_whitespace($user->$k);
        if (isset($user->collaborators)) {
            $this->collaborators = "";
            foreach (preg_split('/[\r\n]+/', $user->collaborators) as $c)
                if (($c = simplify_whitespace($c)) !== "")
                    $this->collaborators .= "$c\n";
        }
        self::set_sorter($this, $this->conf);
        if (isset($user->password))
            $this->password = (string) $user->password;
        if (isset($user->disabled))
            $this->disabled = !!$user->disabled;
        foreach (["defaultWatch", "passwordTime", "passwordUseTime",
                  "updateTime", "creationTime"] as $k)
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
            // this works even if $user->data is a JSON string
            // (array_to_object_recursive($str) === $str)
            $this->data = array_to_object_recursive($user->data);
        if (isset($user->roles) || isset($user->isPC) || isset($user->isAssistant)
            || isset($user->isChair)) {
            $roles = (int) get($user, "roles");
            if (get($user, "isPC"))
                $roles |= self::ROLE_PC;
            if (get($user, "isAssistant"))
                $roles |= self::ROLE_ADMIN;
            if (get($user, "isChair"))
                $roles |= self::ROLE_CHAIR;
            $this->assign_roles($roles);
        }
        if (!$this->isPC && $this->conf->opt("disableNonPC"))
            $this->disabled = true;
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
        self::set_sorter($this, $this->conf);
        $this->password = (string) $this->password;
        if (isset($this->disabled))
            $this->disabled = !!$this->disabled;
        foreach (["defaultWatch", "passwordTime", "passwordUseTime",
                  "updateTime", "creationTime"] as $k)
            $this->$k = (int) $this->$k;
        if (!$this->activity_at && isset($this->lastLogin))
            $this->activity_at = (int) $this->lastLogin;
        if ($this->data)
            // this works even if $user->data is a JSON string
            // (array_to_object_recursive($str) === $str)
            $this->data = array_to_object_recursive($this->data);
        if (isset($this->roles))
            $this->assign_roles((int) $this->roles);
        if (!$this->isPC && $this->conf->opt("disableNonPC"))
            $this->disabled = true;
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name === "cid")
            return $this->contactId;
        else
            return null;
    }

    public function __set($name, $value) {
        if ($name === "cid")
            $this->contactId = $this->cid = $value;
        else {
            if (!self::$allow_nonexistent_properties)
                error_log(caller_landmark(1) . ": writing nonexistent property $name");
            $this->$name = $value;
        }
    }

    static public function set_sorter($c, Conf $conf) {
        if (!$conf->sort_by_last && isset($c->unaccentedName)) {
            $c->sorter = trim("$c->unaccentedName $c->email");
            return;
        }
        if ($conf->sort_by_last) {
            if (($m = Text::analyze_von($c->lastName)))
                $c->sorter = trim("$m[1] $c->firstName $m[0] $c->email");
            else
                $c->sorter = trim("$c->lastName $c->firstName $c->email");
        } else
            $c->sorter = trim("$c->firstName $c->lastName $c->email");
        if (preg_match('/[\x80-\xFF]/', $c->sorter))
            $c->sorter = UnicodeHelper::deaccent($c->sorter);
    }

    static public function compare($a, $b) {
        return strnatcasecmp($a->sorter, $b->sorter);
    }

    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
    }


    // initialization

    public function activate() {
        global $Now;
        $this->activated_ = true;
        $trueuser = get($_SESSION, "trueuser");
        $truecontact = null;

        // Handle actas requests
        $actas = req("actas");
        if ($actas && $trueuser) {
            if (is_numeric($actas)) {
                $acct = $this->conf->user_by_id($actas);
                $actasemail = $acct ? $acct->email : null;
            } else if ($actas === "admin")
                $actasemail = $trueuser->email;
            else
                $actasemail = $actas;
            unset($_GET["actas"], $_POST["actas"], $_REQUEST["actas"]);
            if ($actasemail
                && strcasecmp($actasemail, $this->email) != 0
                && (strcasecmp($actasemail, $trueuser->email) == 0
                    || $this->privChair
                    || (($truecontact = $this->conf->user_by_email($trueuser->email))
                        && $truecontact->privChair))
                && ($actascontact = $this->conf->user_by_email($actasemail))) {
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                if ($this->privChair || ($truecontact && $truecontact->privChair))
                    self::$trueuser_privChair = $actascontact;
                return $actascontact->activate();
            }
        }

        // Handle invalidate-caches requests
        if (req("invalidatecaches") && $this->privChair) {
            unset($_GET["invalidatecaches"], $_POST["invalidatecaches"], $_REQUEST["invalidatecaches"]);
            $this->conf->invalidate_caches();
        }

        // If validatorContact is set, use it
        if ($this->contactId <= 0 && req("validator")
            && ($vc = $this->conf->opt("validatorContact"))) {
            unset($_GET["validator"], $_POST["validator"], $_REQUEST["validator"]);
            if (($newc = $this->conf->user_by_email($vc))) {
                $this->activated_ = false;
                return $newc->activate();
            }
        }

        // Add capabilities from session and request
        if (!$this->conf->opt("disableCapabilities")) {
            if (($caps = $this->conf->session("capabilities"))) {
                $this->capabilities = $caps;
                ++self::$rights_version;
            }
            if (isset($_REQUEST["cap"]) || isset($_REQUEST["testcap"]))
                $this->activate_capabilities();
        }

        // Add review tokens from session
        if (($rtokens = $this->conf->session("rev_tokens"))) {
            $this->review_tokens_ = $rtokens;
            ++self::$rights_version;
        }

        // Maybe auto-create a user
        if ($trueuser && $this->update_trueuser(false)
            && !$this->has_database_account()
            && $this->conf->session("trueuser_author_check", 0) + 600 < $Now) {
            $this->conf->save_session("trueuser_author_check", $Now);
            $aupapers = self::email_authored_papers($this->conf, $trueuser->email, $trueuser);
            if (count($aupapers))
                return $this->activate_database_account();
        }

        // Maybe set up the shared contacts database
        if ($this->conf->opt("contactdb_dsn") && $this->has_database_account()
            && $this->conf->session("contactdb_roles", 0) != $this->all_roles()) {
            if ($this->contactdb_update())
                $this->conf->save_session("contactdb_roles", $this->all_roles());
        }

        // Check forceShow
        self::$active_forceShow = $this->privChair && req("forceShow");

        return $this;
    }

    public function set_forceShow($on) {
        global $Me;
        if ($this->contactId == $Me->contactId) {
            self::$active_forceShow = $this->privChair && $on;
            if (self::$active_forceShow)
                $_GET["forceShow"] = $_POST["forceShow"] = $_REQUEST["forceShow"] = 1;
            else
                unset($_GET["forceShow"], $_POST["forceShow"], $_REQUEST["forceShow"]);
        }
    }

    public function activate_database_account() {
        assert($this->has_email());
        if (!$this->has_database_account()) {
            $reg = clone $_SESSION["trueuser"];
            if (strcasecmp($reg->email, $this->email) != 0)
                $reg = (object) array();
            $reg->email = $this->email;
            if (($c = Contact::create($this->conf, $reg))) {
                $this->load_by_id($c->contactId);
                $this->activate();
            }
        }
        return $this;
    }

    static public function contactdb() {
        if (self::$contactdb_dblink === false) {
            self::$contactdb_dblink = null;
            if (($dsn = opt("contactdb_dsn")))
                list(self::$contactdb_dblink, $dbname) = Dbl::connect_dsn($dsn);
        }
        return self::$contactdb_dblink;
    }

    static public function contactdb_find_by_email($email) {
        $acct = null;
        if (($cdb = self::contactdb())) {
            $result = Dbl::ql($cdb, "select * from ContactInfo where email=?", $email);
            $acct = self::fetch($result);
            Dbl::free($result);
        }
        return $acct;
    }

    static public function contactdb_find_by_id($cid) {
        $acct = null;
        if (($cdb = self::contactdb())) {
            $result = Dbl::ql($cdb, "select * from ContactInfo where contactDbId=?", $cid);
            $acct = self::fetch($result);
            Dbl::free($result);
        }
        return $acct;
    }

    public function contactdb_user($refresh = false) {
        if ($this->contactDbId && !$this->contactId)
            return $this;
        else if ($refresh || $this->contactdb_user_ === false) {
            $cdbu = null;
            if ($this->has_email() && ($cdb = self::contactdb()))
                $cdbu = self::contactdb_find_by_email($this->email);
            $this->contactDbId = $cdbu ? $cdbu->contactDbId : 0;
            $this->contactdb_user_ = $cdbu;
        }
        return $this->contactdb_user_;
    }

    public function contactdb_update() {
        global $Now;
        if (!($cdb = self::contactdb()) || !$this->has_database_account())
            return false;
        $update_password = null;
        $update_passwordTime = 0;
        if (!$this->disabled
            && $this->password
            && ($this->password[0] !== " " || $this->password[1] === "\$")
            && $this->passwordTime) {
            $update_password = $this->password;
            $update_passwordTime = $this->passwordTime;
        }

        $idquery = Dbl::format_query($cdb, "select ContactInfo.contactDbId, Conferences.confid, roles, password
            from ContactInfo
            left join Conferences on (Conferences.`dbname`=?)
            left join Roles on (Roles.contactDbId=ContactInfo.contactDbId and Roles.confid=Conferences.confid)
            where email=?", $this->conf->opt("dbName"), $this->email);
        $row = Dbl::fetch_first_row(Dbl::ql_raw($cdb, $idquery));
        if (!$row) {
            Dbl::ql($cdb, "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=?, country=?, collaborators=?, password=?, passwordTime=? on duplicate key update firstName=firstName", $this->firstName, $this->lastName, $this->email, $this->affiliation, $this->country, $this->collaborators, $update_password, $update_passwordTime);
            $row = Dbl::fetch_first_row(Dbl::ql_raw($cdb, $idquery));
            $this->contactdb_user_ = false;
        }

        if ($row && $row[3] === null && $update_password)
            Dbl::ql($cdb, "update ContactInfo set password=?, passwordTime=? where contactDbId=? and password is null", $update_password, $update_passwordTime, $row[0]);

        if ($row && $row[1] && (int) $row[2] != $this->all_roles()) {
            $result = Dbl::ql($cdb, "insert into Roles set contactDbId=?, confid=?, roles=?, updated_at=? on duplicate key update roles=values(roles), updated_at=values(updated_at)", $row[0], $row[1], $this->all_roles(), $Now);
            return !!$result;
        } else
            return false;
    }

    public function is_actas_user() {
        return $this->activated_
            && ($trueuser = get($_SESSION, "trueuser"))
            && strcasecmp($trueuser->email, $this->email) != 0;
    }

    public function update_trueuser($always) {
        if (($trueuser = get($_SESSION, "trueuser"))
            && strcasecmp($trueuser->email, $this->email) == 0) {
            foreach (array("firstName", "lastName", "affiliation", "country") as $k)
                if ($this->$k && ($always || !get($trueuser, $k)))
                    $trueuser->$k = $this->$k;
            return true;
        } else
            return false;
    }

    private function activate_capabilities() {
        // Add capabilities from arguments
        if (($cap_req = req("cap"))) {
            foreach (preg_split(',\s+,', $cap_req) as $cap)
                $this->apply_capability_text($cap);
            unset($_REQUEST["cap"], $_GET["cap"], $_POST["cap"]);
        }

        // Support capability testing
        if ($this->conf->opt("testCapabilities")
            && ($cap_req = req("testcap"))
            && preg_match_all('/([-+]?)([1-9]\d*)([A-Za-z]+)/',
                              $cap_req, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $c = ($mm[3] == "a" ? self::CAP_AUTHORVIEW : 0);
                $this->change_capability((int) $mm[2], $c, $mm[1] !== "-");
            }
            unset($_REQUEST["testcap"], $_GET["testcap"], $_POST["testcap"]);
        }
    }

    function is_empty() {
        return $this->contactId <= 0 && !$this->capabilities && !$this->email;
    }

    function name_text() {
        if ($this->firstName === "" || $this->lastName === "")
            return $this->firstName . $this->lastName;
        else
            return $this->firstName . " " . $this->lastName;
    }

    function completion_items() {
        $items = [];

        $x = strtolower(substr($this->email, 0, strpos($this->email, "@")));
        if ($x !== "")
            $items[$x] = 2;

        $sp = strpos($this->firstName, " ") ? : strlen($this->firstName);
        $x = strtolower(UnicodeHelper::deaccent(substr($this->firstName, 0, $sp)));
        if ($x !== "" && ctype_alnum($x))
            $items[$x] = 1;

        $sp = strrpos($this->lastName, " ");
        $x = strtolower(UnicodeHelper::deaccent(substr($this->lastName, $sp ? $sp + 1 : 0)));
        if ($x !== "" && ctype_alnum($x))
            $items[$x] = 1;

        return $items;
    }

    private function name_for($pfx, $x) {
        $cid = is_object($x) ? $x->contactId : $x;
        $key = $pfx . $cid;
        if (isset($this->name_for_map_[$key]))
            return $this->name_for_map_[$key];

        $pcm = $this->conf->pc_members();
        if (isset($pcm[$cid]))
            $x = $pcm[$cid];
        else if (!is_object($x) || !isset($x->email)
                 || !isset($x->firstName) || !isset($x->lastName)) {
            $x = $this->conf->user_by_id($cid);
            $this->contact_sorter_map_[$cid] = $x->sorter;
        }

        if ($pfx !== "t")
            $n = Text::name_html($x);
        else
            $n = Text::name_text($x);

        if ($pfx === "r" && ($colors = $this->reviewer_color_classes_for($x)))
            $n = '<span class="' . $colors . '">' . $n . '</span>';

        return ($this->name_for_map_[$key] = $n);
    }

    function name_html_for($x) {
        return $this->name_for("", $x);
    }

    function name_text_for($x) {
        return $this->name_for("t", $x);
    }

    function reviewer_html_for($x) {
        return $this->name_for($this->isPC ? "r" : "", $x);
    }

    function reviewer_text_for($x) {
        return $this->name_for("t", $x);
    }

    function reviewer_color_classes_for($x) {
        if ($this->isPC && isset($x->contactTags) && $x->contactTags) {
            if (($colors = $x->viewable_color_classes($this))) {
                if (TagInfo::classes_have_colors($colors))
                    $colors = "tagcolorspan " . $colors;
                return $colors;
            }
        }
        return "";
    }

    function ksort_cid_array(&$a) {
        $pcm = $this->conf->pc_members();
        uksort($a, function ($a, $b) use ($pcm) {
            if (isset($pcm[$a]) && isset($pcm[$b]))
                return $pcm[$a]->sort_position - $pcm[$b]->sort_position;
            if (isset($pcm[$a]))
                $as = $pcm[$a]->sorter;
            else if (isset($this->contact_sorter_map_[$a]))
                $as = $this->contact_sorter_map_[$a];
            else {
                $x = $this->conf->user_by_id($a);
                $as = $this->contact_sorter_map_[$a] = $x->sorter;
            }
            if (isset($pcm[$b]))
                $bs = $pcm[$b]->sorter;
            else if (isset($this->contact_sorter_map_[$b]))
                $bs = $this->contact_sorter_map_[$b];
            else {
                $x = $this->conf->user_by_id($b);
                $bs = $this->contact_sorter_map_[$b] = $x->sorter;
            }
            return strcasecmp($as, $bs);
        });
    }

    function has_email() {
        return !!$this->email;
    }

    static function is_anonymous_email($email) {
        // see also PaperSearch, Mailer
        return substr($email, 0, 9) === "anonymous"
            && (strlen($email) === 9 || ctype_digit(substr($email, 9)));
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
        global $Me;
        return self::$active_forceShow && $this->contactId == $Me->contactId;
    }

    function is_pc_member() {
        return $this->roles & self::ROLE_PC;
    }

    function is_pclike() {
        return $this->roles & self::ROLE_PCLIKE;
    }

    function has_tag($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0)
            return true;
        if ($this->contactTags)
            return stripos($this->contactTags, " $t#") !== false;
        if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conf::/") . ": Contact $this->email contactTags missing");
            $this->contactTags = null;
        }
        return false;
    }

    function tag_value($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0)
            return 0.0;
        if ($this->contactTags
            && ($p = stripos($this->contactTags, " $t#")) !== false)
            return (float) substr($this->contactTags, $p + strlen($t) + 2);
        return false;
    }

    static function roles_all_contact_tags($roles, $tags) {
        $t = "";
        if ($roles & self::ROLE_PC)
            $t = " pc#0";
        if ($tags)
            return $t . $tags;
        else
            return $t ? $t . " " : "";
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    function viewable_tags(Contact $user) {
        if ($user->isPC) {
            $tags = $this->all_contact_tags();
            return Tagger::strip_nonviewable($tags, $user);
        } else
            return "";
    }

    function viewable_color_classes(Contact $user) {
        return $this->conf->tags()->color_classes($this->viewable_tags($user));
    }

    function capability($pid) {
        $caps = $this->capabilities ? : array();
        return get($caps, $pid) ? : 0;
    }

    function change_capability($pid, $c, $on = null) {
        if (!$this->capabilities)
            $this->capabilities = array();
        $oldval = get($this->capabilities, $pid) ? : 0;
        if ($on === null)
            $newval = ($c != null ? $c : 0);
        else
            $newval = ($oldval | ($on ? $c : 0)) & ~($on ? 0 : $c);
        if ($newval !== $oldval) {
            ++self::$rights_version;
            if ($newval !== 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
        }
        if (!count($this->capabilities))
            $this->capabilities = null;
        if ($this->activated_ && $newval !== $oldval)
            $this->conf->save_session("capabilities", $this->capabilities);
        return $newval != $oldval;
    }

    function apply_capability_text($text) {
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = $this->conf->ql("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $this->conf->capability_text($row, $m[3]);
            $text = substr($text, strlen($m[1]));
            if ($rowcap === $text
                || $rowcap === str_replace("/", "_", $text))
                return $this->change_capability((int) $m[2], self::CAP_AUTHORVIEW, $m[1] !== "-");
        }
        return null;
    }

    private function make_data() {
        if (is_string($this->data))
            $this->data = json_decode($this->data);
        if (!$this->data)
            $this->data = (object) array();
    }

    function data($key = null) {
        $this->make_data();
        if ($key)
            return get($this->data, $key);
        else
            return $this->data;
    }

    private function encode_data() {
        if ($this->data && ($t = json_encode($this->data)) !== "{}")
            return $t;
        else
            return null;
    }

    function save_data($key, $value) {
        $this->merge_and_save_data((object) array($key => array_to_object_recursive($value)));
    }

    function merge_data($data) {
        $this->make_data();
        object_replace_recursive($this->data, array_to_object_recursive($data));
    }

    function merge_and_save_data($data) {
        $this->activate_database_account();
        $this->make_data();
        $old = $this->encode_data();
        object_replace_recursive($this->data, array_to_object_recursive($data));
        $new = $this->encode_data();
        if ($old !== $new)
            $this->conf->qe("update ContactInfo set data=? where contactId=?", $new, $this->contactId);
    }

    private function data_str() {
        $d = null;
        if (is_string($this->data))
            $d = $this->data;
        else if (is_object($this->data))
            $d = json_encode($this->data);
        return $d === "{}" ? null : $d;
    }

    function escape() {
        if (req("ajax")) {
            if ($this->is_empty())
                $this->conf->ajaxExit(["ok" => false, "error" => "You have been logged out.", "loggedout" => true]);
            else
                $this->conf->ajaxExit(["ok" => false, "error" => "You don’t have permission to access that page."]);
        }

        if ($this->is_empty()) {
            // Preserve post values across session expiration.
            $x = array();
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            if (req("anchor"))
                $x["anchor"] = req("anchor");
            $url = selfHref($x, array("raw" => true, "site_relative" => true));
            $_SESSION["login_bounce"] = [$this->conf->dsn, $url, Navigation::page(), $_POST];
            if (check_post())
                error_go(false, "You’ve been logged out due to inactivity, so your changes have not been saved. After logging in, you may submit them again.");
            else
                error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }


    static private $save_fields = array("firstName" => 6, "lastName" => 6, "email" => 1, "affiliation" => 6, "country" => 6, "preferredEmail" => 1, "voicePhoneNumber" => 1, "unaccentedName" => 0, "collaborators" => 4);

    private function _save_assign_field($k, $v, Contact_Update $cu) {
        $fieldtype = get_i(self::$save_fields, $k);
        if ($fieldtype & 2)
            $v = simplify_whitespace($v);
        else if ($fieldtype & 1)
            $v = trim($v);
        // check CDB version first (in case $this === $cdbu)
        $cdbu = $this->contactDbId ? $this : $this->contactdb_user_;
        if (($fieldtype & 4)
            && (!$cdbu || $cu->different_email || $cdbu->$k !== $v))
            $cu->cdb_uqv[$k] = $v;
        // change local version
        if ($this->$k !== $v || !$this->contactId)
            $cu->qv[$k] = $this->$k = $v;
    }

    static public function parse_roles_json($j) {
        $roles = 0;
        if (isset($j->pc) && $j->pc)
            $roles |= self::ROLE_PC;
        if (isset($j->chair) && $j->chair)
            $roles |= self::ROLE_CHAIR | self::ROLE_PC;
        if (isset($j->sysadmin) && $j->sysadmin)
            $roles |= self::ROLE_ADMIN;
        return $roles;
    }

    function save_json($cj, $actor, $send) {
        global $Me, $Now;
        $inserting = !$this->contactId;
        $old_roles = $this->roles;
        $old_email = $this->email;
        $different_email = strtolower($cj->email) !== strtolower((string) $old_email);
        $cu = new Contact_Update($inserting, $different_email);

        $aupapers = null;
        if ($different_email)
            $aupapers = self::email_authored_papers($this->conf, $cj->email, $cj);

        // check whether this user is changing themselves
        $changing_other = false;
        if (self::contactdb() && $Me
            && (strcasecmp($this->email, $Me->email) != 0 || $Me->is_actas_user()))
            $changing_other = true;

        // Main fields
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "collaborators", "preferredEmail", "country") as $k)
            if (isset($cj->$k))
                $this->_save_assign_field($k, $cj->$k, $cu);
        if (isset($cj->phone))
            $this->_save_assign_field("voicePhoneNumber", $cj->phone, $cu);
        $this->_save_assign_field("unaccentedName", Text::unaccented_name($this->firstName, $this->lastName), $cu);
        self::set_sorter($this, $this->conf);

        // Disabled
        $disabled = $this->disabled ? 1 : 0;
        if (isset($cj->disabled))
            $disabled = $cj->disabled ? 1 : 0;
        if (($this->disabled ? 1 : 0) !== $disabled || !$this->contactId)
            $cu->qv["disabled"] = $this->disabled = $disabled;

        // Data
        $old_datastr = $this->data_str();
        $data = (object) array();
        foreach (array("address", "city", "state", "zip") as $k)
            if (isset($cj->$k) && ($x = $cj->$k)) {
                while (is_array($x) && $x[count($x) - 1] === "")
                    array_pop($x);
                $data->$k = $x ? : null;
            }
        $this->merge_data($data);
        $datastr = $this->data_str();
        if ($datastr !== $old_datastr)
            $cu->qv["data"] = $datastr;

        // Changes to the above fields also change the updateTime.
        if (count($cu->qv))
            $cu->qv["updateTime"] = $this->updateTime = $Now;

        // Follow
        if (isset($cj->follow)) {
            $w = 0;
            if (get($cj->follow, "reviews"))
                $w |= (WATCHTYPE_COMMENT << WATCHSHIFT_ON);
            if (get($cj->follow, "allreviews"))
                $w |= (WATCHTYPE_COMMENT << WATCHSHIFT_ALLON);
            if (get($cj->follow, "allfinal"))
                $w |= (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALLON);
            $this->_save_assign_field("defaultWatch", $w, $cu);
        }

        // Tags
        if (isset($cj->tags)) {
            $tags = array();
            foreach ($cj->tags as $t) {
                list($tag, $value) = TagInfo::unpack($t);
                if (strcasecmp($tag, "pc") != 0)
                    $tags[$tag] = $tag . "#" . ($value ? : 0);
            }
            ksort($tags);
            $t = count($tags) ? " " . join(" ", $tags) . " " : "";
            $this->_save_assign_field("contactTags", $t, $cu);
        }

        // If inserting, set initial password and creation time
        if ($inserting) {
            $cu->qv["creationTime"] = $this->creationTime = $Now;
            $this->_create_password(self::contactdb_find_by_email($this->email), $cu);
        }

        // Initial save
        if (count($cu->qv)) { // always true if $inserting
            $q = ($inserting ? "insert into" : "update")
                . " ContactInfo set "
                . join("=?, ", array_keys($cu->qv)) . "=?"
                . ($inserting ? "" : " where contactId=$this->contactId");;
            if (!($result = $this->conf->qe_apply($q, array_values($cu->qv))))
                return $result;
            if ($inserting)
                $this->contactId = $this->cid = (int) $result->insert_id;
            Dbl::free($result);
        }

        // Topics
        if (isset($cj->topics)) {
            $tf = array();
            foreach ($cj->topics as $k => $v)
                if ($v || empty($tf))
                    $tf[] = "($this->contactId,$k,$v)";
            $this->conf->qe_raw("delete from TopicInterest where contactId=$this->contactId");
            if (!empty($tf))
                $this->conf->qe_raw("insert into TopicInterest (contactId,topicId,interest) values " . join(",", $tf));
            unset($this->topicInterest, $this->topic_interest_map_);
        }

        // Roles
        $roles = 0;
        if (isset($cj->roles)) {
            $roles = self::parse_roles_json($cj->roles);
            if ($roles !== $old_roles)
                $this->save_roles($roles, $actor);
        }

        // Update authorship
        if ($aupapers)
            $this->save_authored_papers($aupapers);

        // Update contact database
        $cdbu = $this->contactDbId ? $this : $this->contactdb_user_;
        if ($different_email)
            $cdbu = null;
        if (($cdb = self::contactdb()) && (!$cdbu || count($cu->cdb_uqv))) {
            $qv = [];
            if (!$cdbu) {
                $q = "insert into ContactInfo set firstName=?, lastName=?, email=?, affiliation=?, country=?, collaborators=?";
                $qv = array($this->firstName, $this->lastName, $this->email, $this->affiliation, $this->country, $this->collaborators);
                if ($this->password !== ""
                    && ($this->password[0] !== " " || $this->password[1] === "\$")) {
                    $q .= ", password=?";
                    $qv[] = $this->password;
                }
                $q .= " on duplicate key update ";
            } else
                $q = "update ContactInfo set ";
            if (count($cu->cdb_uqv) && $changing_other)
                $q .= join(", ", array_map(function ($k) { return "$k=if(coalesce($k,'')='',?,$k)"; }, array_keys($cu->cdb_uqv)));
            else if (count($cu->cdb_uqv))
                $q .= join("=?, ", array_keys($cu->cdb_uqv)) . "=?";
            else
                $q .= "firstName=firstName";
            if (count($cu->cdb_uqv))
                $q .= ", updateTime=$Now";
            $qv = array_merge($qv, array_values($cu->cdb_uqv));
            if ($cdbu)
                $q .= " where contactDbId=" . $cdbu->contactDbId;
            $result = Dbl::ql_apply($cdb, $q, $qv);
            Dbl::free($result);
            $this->contactdb_user_ = false;
        }

        // Password
        if (isset($cj->new_password))
            $this->change_password(get($cj, "old_password"), $cj->new_password, 0);

        // Beware PC cache
        if (($roles | $old_roles) & Contact::ROLE_PCLIKE)
            $this->conf->invalidate_caches(["pc" => 1]);

        // Mark creation and activity
        if ($inserting) {
            if ($send && !$this->disabled)
                $this->sendAccountInfo("create", false);
            $type = $this->disabled ? "disabled " : "";
            if ($Me && $Me->has_email() && $Me->email !== $this->email)
                $this->conf->log("Created {$type}account ($Me->email)", $this);
            else
                $this->conf->log("Created {$type}account", $this);
        }

        $actor = $actor ? : $Me;
        if ($actor && $this->contactId == $actor->contactId)
            $this->mark_activity();

        return true;
    }

    public function change_email($email) {
        $aupapers = self::email_authored_papers($this->conf, $email, $this);
        $this->conf->ql("update ContactInfo set email=? where contactId=?", $email, $this->contactId);
        $this->save_authored_papers($aupapers);
        if ($this->roles & Contact::ROLE_PCLIKE)
            $this->conf->invalidate_caches(["pc" => 1]);
        $this->email = $email;
    }

    static function email_authored_papers(Conf $conf, $email, $reg) {
        $aupapers = array();
        $result = $conf->q("select paperId, authorInformation from Paper where authorInformation like " . Dbl::utf8ci("'%\t" . sqlq_for_like($email) . "\t%'"));
        while (($row = PaperInfo::fetch($result, null, $conf)))
            foreach ($row->author_list() as $au)
                if (strcasecmp($au->email, $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($reg && $au->firstName && !get($reg, "firstName"))
                        $reg->firstName = $au->firstName;
                    if ($reg && $au->lastName && !get($reg, "lastName"))
                        $reg->lastName = $au->lastName;
                    if ($reg && $au->affiliation && !get($reg, "affiliation"))
                        $reg->affiliation = $au->affiliation;
                }
        return $aupapers;
    }

    private function save_authored_papers($aupapers) {
        if (count($aupapers) && $this->contactId) {
            $q = array();
            foreach ($aupapers as $pid)
                $q[] = "($pid, $this->contactId, " . CONFLICT_AUTHOR . ")";
            $this->conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values " . join(", ", $q) . " on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")");
        }
    }

    function save_roles($new_roles, $actor) {
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN) && ($old_roles & self::ROLE_ADMIN)
            && !(($result = $this->conf->qe("select contactId from ContactInfo where roles!=0 and (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1"))
                 && edb_nrows($result) > 0))
            $new_roles |= self::ROLE_ADMIN;
        // log role change
        $actor_email = ($actor ? " by $actor->email" : "");
        foreach (array(self::ROLE_PC => "pc",
                       self::ROLE_ADMIN => "sysadmin",
                       self::ROLE_CHAIR => "chair") as $role => $type)
            if (($new_roles & $role) && !($old_roles & $role))
                $this->conf->log("Added as $type$actor_email", $this);
            else if (!($new_roles & $role) && ($old_roles & $role))
                $this->conf->log("Removed as $type$actor_email", $this);
        // save the roles bits
        if ($old_roles != $new_roles) {
            $this->conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function load_by_id($cid) {
        $result = $this->conf->q("select * from ContactInfo where contactId=?", $cid);
        if (($row = $result ? $result->fetch_object() : null))
            $this->merge($row);
        Dbl::free($result);
        return !!$row;
    }

    static function safe_registration($reg) {
        $safereg = (object) array();
        foreach (array("email", "firstName", "lastName", "name",
                       "preferredEmail", "affiliation", "collaborators",
                       "voicePhoneNumber", "unaccentedName") as $k)
            if (isset($reg[$k]))
                $safereg->$k = $reg[$k];
        return $safereg;
    }

    private function _create_password($cdbu, Contact_Update $cu) {
        global $Now;
        if ($cdbu && ($cdbu = $cdbu->contactdb_user())
            && $cdbu->allow_contactdb_password()) {
            $cu->qv["password"] = $this->password = "";
            $cu->qv["passwordTime"] = $this->passwordTime = $cdbu->passwordTime;
        } else if (!$this->conf->external_login()) {
            $cu->qv["password"] = $this->password = self::random_password();
            $cu->qv["passwordTime"] = $this->passwordTime = $Now;
        } else
            $cu->qv["password"] = $this->password = "";
    }

    static function create(Conf $conf, $reg, $send = false) {
        global $Me, $Now;
        if (is_array($reg))
            $reg = (object) $reg;
        assert(is_string($reg->email));
        $email = trim($reg->email);
        assert($email !== "");

        // look up account first
        if (($acct = $conf->user_by_email($email)))
            return $acct;

        // validate email, check contactdb
        if (!get($reg, "no_validate_email") && !validate_email($email))
            return null;
        $cdbu = Contact::contactdb_find_by_email($email);
        if (get($reg, "only_if_contactdb") && !$cdbu)
            return null;

        $cj = (object) array();
        foreach (array("firstName", "lastName", "email", "affiliation",
                       "collaborators", "preferredEmail") as $k)
            if (($v = $cdbu && $cdbu->$k ? $cdbu->$k : get($reg, $k)))
                $cj->$k = $v;
        if (($v = $cdbu && $cdbu->voicePhoneNumber ? $cdbu->voicePhoneNumber : get($reg, "voicePhoneNumber")))
            $cj->phone = $v;
        if (($cdbu && $cdbu->disabled) || get($reg, "disabled"))
            $cj->disabled = true;

        $acct = new Contact;
        if ($acct->save_json($cj, null, $send)) {
            if ($Me && $Me->privChair) {
                $type = $acct->disabled ? "disabled " : "";
                $conf->infoMsg("Created {$type}account for <a href=\"" . hoturl("profile", "u=" . urlencode($acct->email)) . "\">" . Text::user_html_nolink($acct) . "</a>.");
            }
            return $acct;
        } else {
            $conf->log("Account $email creation failure", $Me);
            return null;
        }
    }


    // PASSWORDS
    //
    // password "": disabled user; example: anonymous users for review tokens
    // password "*": invalid password, used to require the contactdb
    // password starting with " ": legacy hashed password using hash_hmac
    //     format: " HASHMETHOD KEYID SALT[16B]HMAC"
    // password starting with " $": password hashed by password_hash
    //
    // contactdb_user password falsy: contactdb password unusable
    // contactdb_user password truthy: follows rules above (but no "*")
    //
    // PASSWORD PRINCIPLES
    //
    // - prefer contactdb password
    // - require contactdb password if it is newer
    //
    // PASSWORD CHECKING RULES
    //
    // if (contactdb password exists)
    //     check contactdb password;
    // if (contactdb password matches && contactdb password needs upgrade)
    //     upgrade contactdb password;
    // if (contactdb password matches && local password was from contactdb)
    //     set local password to contactdb password;
    // if (local password was not from contactdb || no contactdb)
    //     check local password;
    // if (local password matches && local password needs upgrade)
    //     upgrade local password;
    //
    // PASSWORD CHANGING RULES
    //
    // change(expected, new):
    // if (contactdb password allowed
    //     && (!expected || expected matches contactdb)) {
    //     change contactdb password and update time;
    //     set local password to "*";
    // } else
    //     change local password and update time;

    public static function valid_password($input) {
        return $input !== "" && $input !== "0" && $input !== "*"
            && trim($input) === $input;
    }

    public static function random_password($length = 14) {
        return hotcrp_random_password($length);
    }

    public static function password_storage_cleartext() {
        return opt("safePasswords") < 1;
    }

    public function allow_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password;
    }

    private function prefer_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password
            && (!$this->has_database_account() || $this->password === "");
    }

    public function plaintext_password() {
        // Return the currently active plaintext password. This might not
        // equal $this->password because of the cdb.
        if ($this->password === "") {
            if ($this->contactId
                && ($cdbu = $this->contactdb_user()))
                return $cdbu->plaintext_password();
            else
                return false;
        } else if ($this->password[0] === " ")
            return false;
        else
            return $this->password;
    }


    // obsolete
    private function password_hmac_key($keyid) {
        if ($keyid === null)
            $keyid = $this->conf->opt("passwordHmacKeyid", 0);
        $key = $this->conf->opt("passwordHmacKey.$keyid");
        if (!$key && $keyid == 0)
            $key = $this->conf->opt("passwordHmacKey");
        if (!$key) /* backwards compatibility */
            $key = $this->conf->setting_data("passwordHmacKey.$keyid");
        if (!$key) {
            error_log("missing passwordHmacKey.$keyid, using default");
            $key = "NdHHynw6JwtfSZyG3NYPTSpgPFG8UN8NeXp4tduTk2JhnSVy";
        }
        return $key;
    }

    private function check_hashed_password($input, $pwhash, $email) {
        if ($input == "" || $input === "*" || $pwhash === null || $pwhash === "")
            return false;
        else if ($pwhash[0] !== " ")
            return $pwhash === $input;
        else if ($pwhash[1] === "\$") {
            if (function_exists("password_verify"))
                return password_verify($input, substr($pwhash, 2));
        } else {
            if (($method_pos = strpos($pwhash, " ", 1)) !== false
                && ($keyid_pos = strpos($pwhash, " ", $method_pos + 1)) !== false
                && strlen($pwhash) > $keyid_pos + 17
                && function_exists("hash_hmac")) {
                $method = substr($pwhash, 1, $method_pos - 1);
                $keyid = substr($pwhash, $method_pos + 1, $keyid_pos - $method_pos - 1);
                $salt = substr($pwhash, $keyid_pos + 1, 16);
                return hash_hmac($method, $salt . $input, $this->password_hmac_key($keyid), true)
                    == substr($pwhash, $keyid_pos + 17);
            }
        }
        error_log("cannot check hashed password for user $email");
        return false;
    }

    private function password_hash_method() {
        $m = $this->conf->opt("passwordHashMethod");
        if (function_exists("password_verify") && !is_string($m))
            return is_int($m) ? $m : PASSWORD_DEFAULT;
        if (!function_exists("hash_hmac"))
            return false;
        if (is_string($m))
            return $m;
        return PHP_INT_SIZE == 8 ? "sha512" : "sha256";
    }

    private function preferred_password_keyid($iscdb) {
        if ($iscdb)
            return $this->conf->opt("contactdb_passwordHmacKeyid", 0);
        else
            return $this->conf->opt("passwordHmacKeyid", 0);
    }

    private function check_password_encryption($hash, $iscdb) {
        $safe = $this->conf->opt($iscdb ? "contactdb_safePasswords" : "safePasswords");
        if ($safe < 1
            || ($method = $this->password_hash_method()) === false
            || ($hash !== "" && $safe == 1 && $hash[0] !== " "))
            return false;
        else if ($hash === "" || $hash[0] !== " ")
            return true;
        else if (is_int($method))
            return $hash[1] !== "\$"
                || password_needs_rehash(substr($hash, 2), $method);
        else {
            $prefix = " " . $method . " " . $this->preferred_password_keyid($iscdb) . " ";
            return !str_starts_with($hash, $prefix);
        }
    }

    private function hash_password($input, $iscdb) {
        $method = $this->password_hash_method();
        if ($method === false)
            return $input;
        else if (is_int($method))
            return " \$" . password_hash($input, $method);
        else {
            $keyid = $this->preferred_password_keyid($iscdb);
            $key = $this->password_hmac_key($keyid);
            $salt = random_bytes(16);
            return " " . $method . " " . $keyid . " " . $salt
                . hash_hmac($method, $salt . $input, $key, true);
        }
    }

    public function check_password($input) {
        global $Now;
        assert(!$this->conf->external_login());
        if (($this->contactId && $this->disabled)
            || !self::valid_password($input))
            return false;
        // update passwordUseTime once a month
        $update_use_time = $Now - 31 * 86400;

        $cdbu = $this->contactdb_user();
        $cdbok = false;
        if ($cdbu && ($hash = $cdbu->password)
            && $cdbu->allow_contactdb_password()
            && ($cdbok = $this->check_hashed_password($input, $hash, $this->email))) {
            if ($this->check_password_encryption($hash, true)) {
                $hash = $this->hash_password($input, true);
                Dbl::ql(self::contactdb(), "update ContactInfo set password=? where contactDbId=?", $hash, $cdbu->contactDbId);
                $cdbu->password = $hash;
            }
            if ($cdbu->passwordUseTime <= $update_use_time) {
                Dbl::ql(self::contactdb(), "update ContactInfo set passwordUseTime=? where contactDbId=?", $Now, $cdbu->contactDbId);
                $cdbu->passwordUseTime = $Now;
            }
        }

        $localok = false;
        if ($this->contactId && ($hash = $this->password)
            && ($localok = $this->check_hashed_password($input, $hash, $this->email))) {
            if ($this->check_password_encryption($hash, false)) {
                $hash = $this->hash_password($input, false);
                $this->conf->ql("update ContactInfo set password=? where contactId=?", $hash, $this->contactId);
                $this->password = $hash;
            }
            if ($this->passwordUseTime <= $update_use_time) {
                $this->conf->ql("update ContactInfo set passwordUseTime=? where contactId=?", $Now, $this->contactId);
                $this->passwordUseTime = $Now;
            }
        }

        return $cdbok || $localok;
    }

    const CHANGE_PASSWORD_PLAINTEXT = 1;
    const CHANGE_PASSWORD_NO_CDB = 2;

    public function change_password($old, $new, $flags) {
        global $Now;
        assert(!$this->conf->external_login());
        if ($new === null)
            $new = self::random_password();
        assert(self::valid_password($new));

        $cdbu = null;
        if (!($flags & self::CHANGE_PASSWORD_NO_CDB))
            $cdbu = $this->contactdb_user();
        if ($cdbu
            && (!$old || $cdbu->password)
            && (!$old || $this->check_hashed_password($old, $cdbu->password, $this->email))) {
            $hash = $new;
            if ($hash && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", true))
                $hash = $this->hash_password($hash, true);
            $cdbu->password = $hash;
            if (!$old || $old !== $new)
                $cdbu->passwordTime = $Now;
            Dbl::ql(self::contactdb(), "update ContactInfo set password=?, passwordTime=? where contactDbId=?", $cdbu->password, $cdbu->passwordTime, $cdbu->contactDbId);
            if ($this->contactId && $this->password) {
                $this->password = "";
                $this->passwordTime = $cdbu->passwordTime;
                $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
            }
        } else if ($this->contactId
                   && (!$old || $this->check_hashed_password($old, $this->password, $this->email))) {
            $hash = $new;
            if ($hash && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", false))
                $hash = $this->hash_password($hash, false);
            $this->password = $hash;
            if (!$old || $old !== $new)
                $this->passwordTime = $Now;
            $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
        }
    }


    function sendAccountInfo($sendtype, $sensitive) {
        assert(!$this->disabled);
        $rest = array();
        if ($sendtype == "create" && $this->prefer_contactdb_password())
            $template = "@activateaccount";
        else if ($sendtype == "create")
            $template = "@createaccount";
        else if ($this->plaintext_password()
                 && ($this->conf->opt("safePasswords") <= 1 || $sendtype != "forgot"))
            $template = "@accountinfo";
        else {
            if ($this->contactDbId && $this->prefer_contactdb_password())
                $capmgr = $this->conf->capability_manager("U");
            else
                $capmgr = $this->conf->capability_manager();
            $rest["capability"] = $capmgr->create(CAPTYPE_RESETPASSWORD, array("user" => $this, "timeExpires" => time() + 259200));
            $this->conf->log("Created password reset " . substr($rest["capability"], 0, 8) . "...", $this);
            $template = "@resetpassword";
        }

        $mailer = new HotCRPMailer($this, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        if ($prep->sendable || !$sensitive
            || $this->conf->opt("debugShowSensitiveEmail")) {
            Mailer::send_preparation($prep);
            return $template;
        } else {
            Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    public function mark_login() {
        global $Now;
        // at least one login every 90 days is marked as activity
        if (!$this->activity_at || $this->activity_at <= $Now - 7776000
            || (($cdbu = $this->contactdb_user())
                && (!$cdbu->activity_at || $cdbu->activity_at <= $Now - 7776000)))
            $this->mark_activity();
    }

    public function mark_activity() {
        global $Now;
        if (!$this->activity_at || $this->activity_at < $Now) {
            $this->activity_at = $Now;
            if ($this->contactId && !$this->is_anonymous_user())
                $this->conf->ql("update ContactInfo set lastLogin=$Now where contactId=$this->contactId");
            if ($this->contactDbId)
                Dbl::ql(self::contactdb(), "update ContactInfo set activity_at=$Now where contactDbId=$this->contactDbId");
        }
    }

    function log_activity($text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log($text, $this, $paperId);
    }

    function log_activity_for($user, $text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log($text . " by $this->email", $user, $paperId);
    }


    // HotCRP roles

    static public function update_rights() {
        ++self::$rights_version;
    }

    private function load_author_reviewer_status() {
        // Load from database
        $result = null;
        if ($this->contactId > 0) {
            $qr = $this->review_tokens_ ? " or reviewToken?a" : "";
            $result = $this->conf->qe("select (select max(conflictType) from PaperConflict where contactId=?), (select paperId from PaperReview where contactId=?$qr limit 1)", $this->contactId, $this->contactId, $this->review_tokens_);
        }
        $row = edb_row($result);
        $this->is_author_ = $row && $row[0] >= CONFLICT_AUTHOR;
        $this->has_review_ = $row && $row[1] > 0;
        Dbl::free($result);

        // Update contact information from capabilities
        if ($this->capabilities)
            foreach ($this->capabilities as $pid => $cap)
                if ($cap & self::CAP_AUTHORVIEW)
                    $this->is_author_ = true;
    }

    private function check_rights_version() {
        if ($this->rights_version_ !== self::$rights_version) {
            $this->is_author_ = $this->has_review_ = $this->has_outstanding_review_ =
                $this->is_requester_ = $this->is_lead_ = $this->is_explicit_manager_ = null;
            $this->rights_version_ = self::$rights_version;
        }
    }

    function is_author() {
        $this->check_rights_version();
        if (!isset($this->is_author_))
            $this->load_author_reviewer_status();
        return $this->is_author_;
    }

    function has_review() {
        $this->check_rights_version();
        if (!isset($this->has_review_))
            $this->load_author_reviewer_status();
        return $this->has_review_;
    }

    function is_reviewer() {
        return $this->isPC || $this->has_review();
    }

    function all_roles() {
        $r = $this->roles;
        if ($this->is_author())
            $r |= self::ROLE_AUTHOR;
        if ($this->is_reviewer())
            $r |= self::ROLE_REVIEWER;
        return $r;
    }

    function has_outstanding_review() {
        $this->check_rights_version();
        if ($this->has_outstanding_review_ === null) {
            // Load from database
            $result = null;
            if ($this->contactId > 0) {
                $qr = $this->review_tokens_ ? " or r.reviewToken?a" : "";
                $result = $this->conf->qe("select r.reviewId from PaperReview r
                    join Paper p on (p.paperId=r.paperId and p.timeSubmitted>0)
                    where (r.contactId=?$qr)
                    and r.reviewNeedsSubmit!=0 limit 1",
                    $this->contactId, $this->review_tokens_);
            }
            $row = edb_row($result);
            Dbl::free($result);
            $this->has_outstanding_review_ = !!$row;
        }
        return $this->has_outstanding_review_;
    }

    function is_requester() {
        $this->check_rights_version();
        if (!isset($this->is_requester_)) {
            $result = null;
            if ($this->contactId > 0)
                $result = $this->conf->qe("select requestedBy from PaperReview where requestedBy=? and contactId!=? limit 1", $this->contactId, $this->contactId);
            $row = edb_row($result);
            $this->is_requester_ = $row && $row[0] > 0;
        }
        return $this->is_requester_;
    }

    function is_discussion_lead() {
        $this->check_rights_version();
        if (!isset($this->is_lead_)) {
            $result = null;
            if ($this->contactId > 0)
                $result = $this->conf->qe("select paperId from Paper where leadContactId=? limit 1", $this->contactId);
            $this->is_lead_ = edb_nrows($result) > 0;
        }
        return $this->is_lead_;
    }

    function is_explicit_manager() {
        $this->check_rights_version();
        if (!isset($this->is_explicit_manager_)) {
            $this->is_explicit_manager_ = false;
            if ($this->contactId > 0
                && $this->isPC
                && ($this->conf->check_any_admin_tracks($this)
                    || ($this->conf->has_any_manager()
                        && $this->conf->fetch_value("select paperId from Paper where managerContactId=? limit 1", $this->contactId) > 0)))
                $this->is_explicit_manager_ = true;
        }
        return $this->is_explicit_manager_;
    }

    function is_manager() {
        return $this->privChair || $this->is_explicit_manager();
    }

    function is_track_manager() {
        return $this->privChair || $this->conf->check_any_admin_tracks($this);
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
            self::update_rights();
        if ($this->activated_ && $new_ntokens != $old_ntokens)
            $this->conf->save_session("rev_tokens", $this->review_tokens_);
        return $new_ntokens != $old_ntokens;
    }


    // topic interests

    function topic_interest_map() {
        global $Me;
        if ($this->topic_interest_map_ !== null)
            return $this->topic_interest_map_;
        if ($this->contactId <= 0)
            return array();
        if (property_exists($this, "topicInterest")) {
            $this->topic_interest_map_ = [];
            foreach (explode(",", $this->topicInterest) as $tandi)
                if (($pos = strpos($tandi, " "))
                    && ($i = (int) substr($tandi, $pos + 1))) {
                    $t = (int) substr($tandi, 0, $pos);
                    $this->topic_interest_map_[$t] = $i;
                }
        } else if (($this->roles & self::ROLE_PCLIKE)
                   && $this !== $Me
                   && ($pcm = $this->conf->pc_members())
                   && $this === get($pcm, $this->contactId)) {
            $result = $this->conf->qe("select contactId, topicId, interest from TopicInterest where interest!=0 order by contactId");
            foreach ($pcm as $pc)
                $pc->topic_interest_map_ = array();
            $pc = null;
            while (($row = edb_row($result))) {
                if (!$pc || $pc->contactId != $row[0])
                    $pc = get($pcm, $row[0]);
                if ($pc)
                    $pc->topic_interest_map_[(int) $row[1]] = (int) $row[2];
            }
            Dbl::free($result);
        } else {
            $result = $this->conf->qe("select topicId, interest from TopicInterest where contactId={$this->contactId} and interest!=0");
            $this->topic_interest_map_ = Dbl::fetch_iimap($result);
        }
        return $this->topic_interest_map_;
    }


    // permissions policies

    private function rights(PaperInfo $prow, $forceShow = null) {
        global $Me;
        $ci = $prow->contact_info($this);

        // check first whether administration is allowed
        if (!isset($ci->allow_administer)) {
            $ci->allow_administer = false;
            if (($this->contactId > 0
                 && (!$prow->managerContactId
                     || $prow->managerContactId == $this->contactId
                     || !$ci->conflict_type)
                 && ($this->privChair
                     || $prow->managerContactId == $this->contactId
                     || ($this->isPC
                         && $this->is_track_manager()
                         && $this->conf->check_admin_tracks($prow, $this))))
                || $this->is_site_contact)
                $ci->allow_administer = true;
        }

        // correct $forceShow
        if ($forceShow === null && $Me && $this->contactId == $Me->contactId)
            $forceShow = self::$active_forceShow;
        else if (!$ci->allow_administer || $forceShow === null)
            $forceShow = false;
        else if ($forceShow === "any")
            $forceShow = !!get($ci, "forced_rights");
        if ($forceShow) {
            if (!get($ci, "forced_rights"))
                $ci->forced_rights = clone $ci;
            $ci = $ci->forced_rights;
        }

        // set other rights
        if (get($ci, "rights_force") !== $forceShow) {
            $ci->rights_force = $forceShow;

            // check current administration status
            $ci->can_administer = $ci->allow_administer
                && (!$ci->conflict_type || $forceShow);

            // check PC tracking
            $tracks = $this->conf->has_tracks();
            $isPC = $this->isPC
                && (!$tracks
                    || $ci->review_type >= REVIEW_PC
                    || !$this->conf->check_track_view_sensitivity()
                    || $this->conf->check_tracks($prow, $this, Track::VIEW));

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
                    || $this->conf->check_tracks($prow, $this, Track::UNASSREV);
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
            $bs = $this->conf->submission_blindness();
            $ci->nonblind = $bs == Conf::BLIND_NEVER
                || ($bs == Conf::BLIND_OPTIONAL
                    && !(isset($prow->paperBlind) ? $prow->paperBlind : $prow->blind))
                || ($bs == Conf::BLIND_UNTILREVIEW
                    && $ci->review_type > 0
                    && $ci->review_submitted > 0)
                || ($prow->outcome > 0
                    && ($isPC || $ci->allow_review)
                    && $this->conf->timeReviewerViewAcceptedAuthors());
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
        if ($this->conf->opt("chairHidePasswords"))
            return get($_SESSION, "trueuser") && $acct && $acct->email
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
            return $this->isPC;
    }

    public function can_view_tracker() {
        return $this->privChair
            || ($this->isPC && $this->conf->check_default_track($this, Track::VIEWTRACKER))
            || $this->tracker_kiosk_state;
    }

    public function view_conflict_type(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->view_conflict_type;
        } else
            return 0;
    }

    public function act_author_view(PaperInfo $prow) {
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
        return $this->email
            && ($this->conf->timeStartPaper()
                || $this->override_deadlines(null, $override));
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
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ((($prow->outcome >= 0 || !$this->can_view_decision($prow, $override))
                 && $this->conf->timeUpdatePaper($prow))
                || $this->override_deadlines($rights, $override));
    }

    function perm_update_paper(PaperInfo $prow, $override = null) {
        if ($this->can_update_paper($prow, $override))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->initial_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->outcome < 0 && $this->can_view_decision($prow, $override))
            $whyNot["rejected"] = 1;
        if ($prow->timeSubmitted > 0 && $this->conf->setting("sub_freeze") > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$this->conf->timeUpdatePaper($prow) && !$this->override_deadlines($rights, $override))
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_finalize_paper(PaperInfo $prow) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($this->conf->timeFinalizePaper($prow) || $this->override_deadlines($rights));
    }

    function perm_finalize_paper(PaperInfo $prow) {
        if ($this->can_finalize_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->initial_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->timeSubmitted > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$this->conf->timeFinalizePaper($prow) && !$this->override_deadlines($rights))
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
        $whyNot = $prow->initial_whynot();
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
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn > 0
            && ($this->conf->timeUpdatePaper($prow) || $this->override_deadlines($rights));
    }

    function perm_revive_paper(PaperInfo $prow) {
        if ($this->can_revive_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->initial_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn <= 0)
            $whyNot["notWithdrawn"] = 1;
        if (!$this->conf->timeUpdatePaper($prow) && !$this->override_deadlines($rights))
            $whyNot["deadline"] = "sub_update";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_submit_final_paper(PaperInfo $prow, $override = null) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && $prow->outcome > 0
            && $this->conf->collectFinalPapers()
            && $this->can_view_decision($prow, $override)
            && ($this->conf->time_submit_final_version() || $this->override_deadlines($rights, $override));
    }

    function perm_submit_final_paper(PaperInfo $prow, $override = null) {
        if ($this->can_submit_final_paper($prow, $override))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->initial_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = 1;
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        // NB logic order here is important elsewhere
        if ($prow->outcome <= 0 || !$this->can_view_decision($prow, $override))
            $whyNot["rejected"] = 1;
        else if (!$this->conf->collectFinalPapers())
            $whyNot["deadline"] = "final_open";
        else if (!$this->conf->time_submit_final_version() && !$this->override_deadlines($rights, $override))
            $whyNot["deadline"] = "final_done";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_view_paper(PaperInfo $prow, $pdf = false) {
        if ($this->privChair)
            return true;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author_view
            || ($rights->review_type
                // assigned reviewer can view PDF of withdrawn, but submitted, paper
                && (!$pdf || $prow->timeSubmitted != 0))
            || ($rights->allow_pc_broad
                && $this->conf->timePCViewPaper($prow, $pdf)
                && (!$pdf || $this->conf->check_tracks($prow, $this, Track::VIEWPDF)));
    }

    function perm_view_paper(PaperInfo $prow, $pdf = false) {
        if ($this->can_view_paper($prow, $pdf))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->initial_whynot();
        if (!$rights->allow_author_view
            && !$rights->review_type
            && !$rights->allow_pc_broad)
            $whyNot["permission"] = 1;
        else {
            $explained = 0;
            if ($prow->timeWithdrawn > 0)
                $whyNot["withdrawn"] = $explained = 1;
            else if ($prow->timeSubmitted <= 0)
                $whyNot["notSubmitted"] = $explained = 1;
            if ($rights->allow_pc_broad
                && !$this->conf->timePCViewPaper($prow, $pdf))
                $whyNot["deadline"] = $explained = "sub_sub";
            if (!$explained)
                $whyNot["pdfPermission"] = 1;
        }
        return $whyNot;
    }

    function can_view_pdf(PaperInfo $prow) {
        return $this->can_view_paper($prow, true);
    }

    function perm_view_pdf(PaperInfo $prow) {
        return $this->perm_view_paper($prow, true);
    }

    function can_view_some_pdf() {
        return $this->privChair
            || $this->is_author()
            || $this->has_review()
            || ($this->isPC && $this->conf->timePCViewSomePaper(true));
    }

    function can_view_document_history(PaperInfo $prow) {
        if ($this->privChair)
            return true;
        $rights = $this->rights($prow, "any");
        return $rights->act_author || $rights->can_administer;
    }

    function can_view_manager(PaperInfo $prow = null, $forceShow = null) {
        if ($this->privChair)
            return true;
        if (!$prow)
            return $this->isPC && !$this->conf->opt("hideManager");
        $rights = $this->rights($prow);
        return $prow->managerContactId == $this->contactId
            || ($rights->potential_reviewer && !$this->conf->opt("hideManager"));
    }

    function can_view_lead(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer
                || $prow->leadContactId == $this->contactId
                || (($rights->allow_pc || $rights->allow_review)
                    && $this->can_view_review_identity($prow, null, $forceShow));
        } else
            return $this->isPC;
    }

    function can_view_shepherd(PaperInfo $prow, $forceShow = null) {
        // XXX Allow shepherd view when outcome == 0 && can_view_decision.
        // This is a mediocre choice, but people like to reuse the shepherd field
        // for other purposes, and I might hear complaints.
        return $this->act_pc($prow, $forceShow)
            || ($this->can_view_decision($prow, $forceShow)
                && $this->can_view_review($prow, null, $forceShow));
    }

    /* NB caller must check can_view_paper() */
    function can_view_authors(PaperInfo $prow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return ($rights->nonblind
                && $prow->timeSubmitted != 0
                && ($rights->allow_pc_broad
                    || $rights->review_type))
            || ($rights->nonblind
                && $prow->timeWithdrawn <= 0
                && $rights->allow_pc_broad
                && $this->conf->can_pc_see_all_submissions())
            || ($rights->allow_administer
                ? $rights->nonblind || $rights->rights_force /* chair can't see blind authors unless forceShow */
                : $rights->act_author_view);
    }

    function can_view_some_authors() {
        return $this->is_manager()
            || $this->is_author()
            || ($this->is_reviewer()
                && ($this->conf->submission_blindness() != Conf::BLIND_ALWAYS
                    || $this->conf->timeReviewerViewAcceptedAuthors()));
    }

    function can_view_conflicts(PaperInfo $prow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        if ($rights->allow_administer || $rights->act_author_view)
            return true;
        if (!$rights->allow_pc_broad && !$rights->potential_reviewer)
            return false;
        $pccv = $this->conf->setting("sub_pcconfvis");
        return $pccv == 2
            || (!$pccv && $this->can_view_authors($prow, $forceShow))
            || (!$pccv && $this->conf->setting("tracker")
                && MeetingTracker::is_paper_tracked($prow)
                && $this->can_view_tracker());
    }

    function can_view_paper_option(PaperInfo $prow, $opt, $forceShow = null) {
        if (!is_object($opt) && !($opt = $this->conf->paper_opts->find($opt)))
            return false;
        if (!$this->can_view_paper($prow, $opt->has_document()))
            return false;
        $rights = $this->rights($prow, $forceShow);
        $oview = $opt->visibility;
        if ($opt->final && ($prow->outcome <= 0 || !$this->can_view_decision($prow, $forceShow)))
            return false;
        return $rights->act_author_view
            || (($rights->allow_administer
                 || $rights->review_type
                 || $rights->allow_pc_broad)
                && (($oview == "admin" && $rights->allow_administer)
                    || !$oview
                    || $oview == "rev"
                    || ($oview == "nonblind"
                        && $this->can_view_authors($prow, $forceShow))));
    }

    function user_option_list() {
        if ($this->conf->has_any_accepts() && $this->can_view_some_decision())
            return $this->conf->paper_opts->option_list();
        else
            return $this->conf->paper_opts->nonfinal_option_list();
    }

    function perm_view_paper_option(PaperInfo $prow, $opt, $forceShow = null) {
        if ($this->can_view_paper_option($prow, $opt, $forceShow))
            return null;
        if (!is_object($opt) && !($opt = $this->conf->paper_opts->find($opt)))
            return $prow->initial_whynot();
        if (($whyNot = $this->perm_view_paper($prow, $opt->has_document())))
            return $whyNot;
        $whyNot = $prow->initial_whynot();
        $rights = $this->rights($prow, $forceShow);
        $oview = $opt->visibility;
        if (!$rights->act_author_view
            && (($oview == "admin" && !$rights->allow_administer)
                || ((!$oview || $oview == "rev") && !$rights->allow_administer && !$rights->review_type && !$rights->allow_pc_broad)
                || ($oview == "nonblind" && !$this->can_view_authors($prow, $forceShow))))
            $whyNot["optionPermission"] = $opt;
        else if ($opt->final && ($prow->outcome <= 0 || !$this->can_view_decision($prow, $forceShow)))
            $whyNot["optionNotAccepted"] = $opt;
        else
            $whyNot["optionPermission"] = $opt;
        return $whyNot;
    }

    function can_view_some_paper_option(PaperOption $opt) {
        if (($opt->has_document() && !$this->can_view_some_pdf())
            || ($opt->final && !$this->can_view_some_decision()))
            return false;
        $oview = $opt->visibility;
        return $this->is_author()
            || ($oview == "admin" && $this->is_manager())
            || ((!$oview || $oview == "rev") && $this->is_reviewer())
            || ($oview == "nonblind" && $this->can_view_some_authors());
    }

    function is_my_review($rrow) {
        if (!$rrow)
            return false;
        if (!isset($rrow->reviewContactId) && !isset($rrow->contactId))
            error_log(json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        if (isset($rrow->reviewContactId))
            $rrow_cid = $rrow->reviewContactId;
        else
            $rrow_cid = $rrow->contactId;
        return $rrow_cid == $this->contactId
            || ($this->review_tokens_
                && array_search($rrow->reviewToken, $this->review_tokens_) !== false)
            || ($rrow->requestedBy == $this->contactId
                && $rrow->reviewType == REVIEW_EXTERNAL
                && $this->conf->setting("pcrev_editdelegate"));
    }

    public function can_view_review_assignment(PaperInfo $prow, $rrow, $forceShow) {
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_administer
            || $rights->allow_pc
            || $rights->review_type
            || $this->can_view_review($prow, $rrow, $forceShow);
    }

    public static function can_some_author_view_submitted_review(PaperInfo $prow) {
        if ($prow->conf->au_seerev == Conf::AUSEEREV_TAGS)
            return $prow->has_any_tag($prow->conf->tag_au_seerev);
        else
            return $prow->conf->au_seerev != 0;
    }

    private function can_view_submitted_review_as_author(PaperInfo $prow) {
        return $this->conf->au_seerev == Conf::AUSEEREV_YES
            || ($this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
                && (!$this->has_review()
                    || !$this->has_outstanding_review()))
            || ($this->conf->au_seerev == Conf::AUSEEREV_TAGS
                && $prow->has_any_tag($this->conf->tag_au_seerev));
    }

    public function can_view_some_review() {
        return $this->is_reviewer()
            || ($this->is_author() && $this->conf->au_seerev != 0);
    }

    public function can_view_review(PaperInfo $prow, $rrow, $forceShow, $viewscore = null) {
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else if ($viewscore === null)
            $viewscore = VIEWSCORE_AUTHOR;
        assert(!$rrow || $prow->paperId == $rrow->paperId);
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer
            || ($rrow && $this->is_my_review($rrow)
                && $viewscore >= VIEWSCORE_REVIEWERONLY))
            return true;
        $rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = $this->conf->setting("pc_seeallrev");
        $pc_trackok = $rights->allow_pc && $this->conf->check_tracks($prow, $this, Track::VIEWREV);
        // See also PaperInfo::can_view_review_identity_of.
        return ($rights->act_author_view
                && $rrowSubmitted
                && $this->can_view_submitted_review_as_author($prow)
                && ($viewscore >= VIEWSCORE_AUTHOR
                    || ($viewscore >= VIEWSCORE_AUTHORDEC
                        && $prow->outcome
                        && $this->can_view_decision($prow, $forceShow))))
            || ($rights->allow_pc
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && $pc_seeallrev > 0 // see also timePCViewAllReviews()
                && ($pc_seeallrev != Conf::PCSEEREV_UNLESSANYINCOMPLETE
                    || !$this->has_outstanding_review())
                && ($pc_seeallrev != Conf::PCSEEREV_UNLESSINCOMPLETE
                    || !$rights->review_type)
                && $pc_trackok)
            || ($rights->review_type
                && !$rights->view_conflict_type
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && ($prow->review_not_incomplete($this)
                    && ($this->conf->setting("extrev_view") >= 1 || $pc_trackok)));
    }

    function perm_view_review(PaperInfo $prow, $rrow, $forceShow, $viewscore = null) {
        if ($this->can_view_review($prow, $rrow, $forceShow, $viewscore))
            return null;
        $rrowSubmitted = (!$rrow || $rrow->reviewSubmitted > 0);
        $pc_seeallrev = $this->conf->setting("pc_seeallrev");
        $rights = $this->rights($prow, $forceShow);
        $whyNot = $prow->initial_whynot();
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else if (!$rights->act_author_view
                 && !$rights->allow_pc
                 && !$rights->review_type)
            $whyNot["permission"] = 1;
        else if ($rights->act_author_view
                 && $this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
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
                 && $pc_seeallrev == Conf::PCSEEREV_UNLESSANYINCOMPLETE
                 && $this->has_outstanding_review())
            $whyNot["reviewsOutstanding"] = 1;
        else if (!$this->conf->time_review_open())
            $whyNot["deadline"] = "rev_open";
        else
            $whyNot["reviewNotComplete"] = 1;
        if ($rights->allow_administer)
            $whyNot["forceShow"] = 1;
        return $whyNot;
    }

    function can_view_review_identity(PaperInfo $prow, $rrow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        // See also PaperInfo::can_view_review_identity_of.
        // See also ReviewerFexpr.
        return $rights->can_administer
            || ($rrow && ($this->is_my_review($rrow)
                          || ($rights->allow_pc
                              && get($rrow, "requestedBy") == $this->contactId)))
            || ($rights->allow_pc
                && (!($pc_seeblindrev = $this->conf->setting("pc_seeblindrev"))
                    || ($pc_seeblindrev == 2
                        && $this->can_view_review($prow, $rrow, $forceShow)))
                && $this->conf->check_tracks($prow, $this, Track::VIEWREVID))
            || ($rights->allow_review
                && $prow->review_not_incomplete($this)
                && ($rights->allow_pc
                    || $this->conf->setting("extrev_view") >= 2))
            || !$this->conf->is_review_blind($rrow);
    }

    function can_view_some_review_identity($forceShow = null) {
        $prow = new PaperInfo([
            "conflictType" => 0, "managerContactId" => 0,
            "myReviewType" => ($this->is_reviewer() ? 1 : 0),
            "myReviewSubmitted" => 1,
            "myReviewNeedsSubmit" => 0,
            "paperId" => 1, "timeSubmitted" => 1,
            "paperBlind" => false, "outcome" => 1
        ], $this);
        return $this->can_view_review_identity($prow, null, $forceShow);
    }

    function can_view_aggregated_review_identity() {
        /*return $this->privChair
            || ($this->isPC
                && (!$this->conf->setting("pc_seeblindrev") || !$this->conf->is_review_blind(null)));*/
        return $this->isPC;
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
            || ($rrow && get($rrow, "reviewAuthorSeen")
                && $rrow->reviewAuthorSeen <= $rrow->reviewModified);
    }

    function can_request_review(PaperInfo $prow, $check_time) {
        $rights = $this->rights($prow);
        return ($rights->review_type >= REVIEW_PC
                 || $rights->allow_administer)
            && (!$check_time
                || $this->conf->time_review(null, false, true)
                || $this->override_deadlines($rights));
    }

    function perm_request_review(PaperInfo $prow, $check_time) {
        if ($this->can_request_review($prow, $check_time))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->initial_whynot();
        if ($rights->review_type < REVIEW_PC && !$rights->allow_administer)
            $whyNot["permission"] = 1;
        else {
            $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer)
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function can_review_any() {
        return $this->isPC
            && $this->conf->setting("pcrev_any") > 0
            && $this->conf->time_review(null, true, true)
            && $this->conf->check_any_tracks($this, Track::UNASSREV);
    }

    function timeReview(PaperInfo $prow, $rrow) {
        $rights = $this->rights($prow);
        if ($rights->review_type > 0
            || $prow->reviewId
            || ($rrow
                && $this->is_my_review($rrow))
            || ($rrow
                && $rrow->contactId != $this->contactId
                && $rights->allow_administer))
            return $this->conf->time_review($rrow, $rights->allow_pc, true);
        else if ($rights->allow_review
                 && $this->conf->setting("pcrev_any") > 0)
            return $this->conf->time_review(null, true, true);
        else
            return false;
    }

    function can_become_reviewer_ignore_conflict(PaperInfo $prow = null) {
        if (!$prow)
            return $this->isPC
                && ($this->conf->check_all_tracks($this, Track::ASSREV)
                    || $this->conf->check_all_tracks($this, Track::UNASSREV));
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV)
                || $this->conf->check_tracks($prow, $this, Track::UNASSREV));
    }

    function can_accept_review_assignment_ignore_conflict(PaperInfo $prow = null) {
        if (!$prow)
            return $this->isPC && $this->conf->check_all_tracks($this, Track::ASSREV);
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV));
    }

    function can_accept_review_assignment(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->allow_pc
            && ($rights->review_type > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV));
    }

    private function review_rights(PaperInfo $prow, $rrow) {
        $rights = $this->rights($prow);
        $rrow_cid = 0;
        if ($rrow) {
            $my_review = $rights->can_administer || $this->is_my_review($rrow);
            if (isset($rrow->reviewContactId))
                $rrow_cid = $rrow->reviewContactId;
            else
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
        assert(!$rrow || $rrow->paperId == $prow->paperId);
        $rights = $this->rights($prow);
        if ($submit && !$this->can_clickthrough("review"))
            return false;
        return ($this->rights_my_review($rights, $rrow)
                && $this->conf->time_review($rrow, $rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review(null, true, true))
            || ($rights->can_administer
                && ($prow->timeSubmitted > 0 || $rights->rights_force)
                && (!$submit || $this->override_deadlines($rights)));
    }

    function can_create_review_from(PaperInfo $prow, Contact $user) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            && ($prow->timeSubmitted > 0 || $rights->rights_force)
            && (!$user->isPC || $user->can_accept_review_assignment($prow))
            && ($this->conf->time_review(null, true, true) || $this->override_deadlines($rights));
    }

    function perm_review(PaperInfo $prow, $rrow, $submit = false) {
        if ($this->can_review($prow, $rrow, $submit))
            return null;
        $rights = $this->rights($prow);
        $rrow_cid = 0;
        if ($rrow && isset($rrow->reviewContactId))
            $rrow_cid = $rrow->reviewContactId;
        else if ($rrow)
            $rrow_cid = $rrow->contactId;
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        $whyNot = $prow->initial_whynot();
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
                     && (!$rrow || $rrow_cid == $this->contactId))
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
        if (!$this->privChair && $this->conf->opt("clickthrough_$ctype")) {
            $csha1 = sha1($this->conf->message_html("clickthrough_$ctype"));
            $data = $this->data("clickthrough");
            return $data && get($data, $csha1);
        } else
            return true;
    }

    function can_view_review_ratings(PaperInfo $prow = null, $rrow = null) {
        $rs = $this->conf->setting("rev_ratings");
        if ($rs != REV_RATINGS_PC && $rs != REV_RATINGS_PC_EXTERNAL)
            return false;
        if (!$prow)
            return $this->is_reviewer();
        $rights = $this->rights($prow);
        return $this->can_view_review($prow, $rrow, null)
            && ($rights->allow_pc || $rights->allow_review);
    }

    function can_rate_review(PaperInfo $prow, $rrow) {
        return $this->can_view_review_ratings($prow, $rrow)
            && !$this->is_my_review($rrow);
    }


    function can_comment(PaperInfo $prow, $crow, $submit = false) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->can_respond($prow, $crow, $submit);
        $rights = $this->rights($prow);
        return $rights->allow_review
            && ($prow->timeSubmitted > 0
                || $rights->review_type > 0
                || ($rights->allow_administer && $rights->rights_force))
            && ($this->conf->setting("cmt_always") > 0
                || $this->conf->time_review(null, $rights->allow_pc, true)
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
        $whyNot = $prow->initial_whynot();
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
        $rights = $this->rights($prow);
        return $prow->timeSubmitted > 0
            && ($rights->can_administer
                || $rights->act_author)
            && (!$crow
                || ($crow->commentType & COMMENTTYPE_RESPONSE))
            && (($rights->allow_administer
                 && (!$submit || $this->override_deadlines($rights)))
                || $this->conf->time_author_respond($crow ? (int) $crow->commentRound : null));
    }

    function perm_respond(PaperInfo $prow, $crow, $submit = false) {
        if ($this->can_respond($prow, $crow, $submit))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->initial_whynot();
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
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_AUTHOR;
        $rights = $this->rights($prow, $forceShow);
        return ($crow && $crow->contactId == $this->contactId) // wrote this comment
            || ($crow && $crow->contactId == $rights->review_token_cid)
            || $rights->can_administer
            || ($rights->act_author_view
                && $ctype >= COMMENTTYPE_AUTHOR
                && (($ctype & COMMENTTYPE_RESPONSE)    // author's response
                    || (!($ctype & COMMENTTYPE_DRAFT)  // author-visible cmt
                        && $this->can_view_submitted_review_as_author($prow))))
            || (!$rights->view_conflict_type
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->can_view_review($prow, null, $forceShow)
                && (($rights->allow_pc && !$this->conf->setting("pc_seeblindrev"))
                    || $prow->review_not_incomplete($this))
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER));
    }

    function can_view_new_comment_ignore_conflict(PaperInfo $prow) {
        // Goal: Return true if this user is part of the comment mention
        // completion for a new comment on $prow.
        // Problem: If authors are hidden, should we mention this user or not?
        $rights = $this->rights($prow, null);
        return $rights->can_administer
            || $rights->allow_pc;
        return $rights->can_administer
            || (!$rights->view_conflict_type
                && $this->can_view_review($prow, null, null)
                && (($rights->allow_pc && !$this->conf->setting("pc_seeblindrev"))
                    || $prow->review_not_incomplete($this))
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER));
    }

    function canViewCommentReviewWheres() {
        if ($this->privChair
            || ($this->isPC && $this->conf->setting("pc_seeallrev") > 0))
            return array();
        else
            return array("(" . $this->actAuthorSql("PaperConflict")
                         . " or MyPaperReview.reviewId is not null)");
    }

    function can_view_comment_identity(PaperInfo $prow, $crow, $forceShow) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->can_view_authors($prow, $forceShow);
        $rights = $this->rights($prow, $forceShow);
        return $rights->can_administer
            || ($crow && $crow->contactId == $this->contactId)
            || $rights->allow_pc
            || ($rights->allow_review
                && $this->conf->setting("extrev_view") >= 2)
            || !$this->conf->is_review_blind(!$crow || ($crow->commentType & COMMENTTYPE_BLIND) != 0);
    }

    function can_view_comment_time(PaperInfo $prow, $crow) {
        return $this->can_view_comment_identity($prow, $crow, true);
    }

    function can_view_comment_tags(PaperInfo $prow, $crow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_pc || $rights->review_type > 0;
    }

    function can_view_some_draft_response() {
        return $this->is_manager() || $this->is_author();
    }


    function can_view_decision(PaperInfo $prow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return $rights->can_administer
            || ($rights->act_author_view
                && $prow->can_author_view_decision())
            || ($rights->allow_pc_broad
                && $this->conf->timePCViewDecision($rights->view_conflict_type > 0))
            || ($rights->review_type > 0
                && $rights->review_submitted
                && $this->conf->timeReviewerViewDecision());
    }

    function can_view_some_decision() {
        return $this->is_manager()
            || ($this->is_author() && $this->conf->can_some_author_view_decision())
            || ($this->isPC && $this->conf->timePCViewDecision(false))
            || ($this->is_reviewer() && $this->conf->timeReviewerViewDecision());
    }

    function can_set_decision(PaperInfo $prow, $forceShow = null) {
        return $this->can_administer($prow, $forceShow);
    }

    function can_set_some_decision($forceShow = null) {
        return $this->can_administer(null, $forceShow);
    }

    function can_view_formula(Formula $formula) {
        return $formula->view_score($this) > $this->permissive_view_score_bound();
    }

    function can_view_formula_as_author(Formula $formula) {
        return $formula->view_score($this) > $this->author_permissive_view_score_bound();
    }

    // A review field is visible only if its view_score > view_score_bound.
    function view_score_bound(PaperInfo $prow, $rrow, $forceShow = null) {
        // Returns the maximum view_score for an invisible review
        // field. Values are:
        //   VIEWSCORE_ADMINONLY     admin can view
        //   VIEWSCORE_REVIEWERONLY  ... and review author can view
        //   VIEWSCORE_PC            ... and any PC/reviewer can view
        //   VIEWSCORE_AUTHORDEC     ... and authors can view when decisions visible
        //   VIEWSCORE_AUTHOR        ... and authors can view
        // So returning -3 means all scores are visible.
        // Deadlines are not considered.
        $rights = $this->rights($prow, $forceShow);
        if ($rights->can_administer)
            return VIEWSCORE_ADMINONLY - 1;
        else if ($rrow ? $this->is_my_review($rrow) : $rights->allow_review)
            return VIEWSCORE_REVIEWERONLY - 1;
        else if (!$this->can_view_review($prow, $rrow, $forceShow))
            return VIEWSCORE_MAX + 1;
        else if ($rights->act_author_view
                 && $prow->outcome
                 && $this->can_view_decision($prow, $forceShow))
            return VIEWSCORE_AUTHORDEC - 1;
        else if ($rights->act_author_view)
            return VIEWSCORE_AUTHOR - 1;
        else
            return VIEWSCORE_PC - 1;
    }

    function permissive_view_score_bound() {
        if ($this->is_manager())
            return VIEWSCORE_ADMINONLY - 1;
        else if ($this->is_reviewer())
            return VIEWSCORE_REVIEWERONLY - 1;
        else if ($this->is_author() && $this->conf->timeAuthorViewReviews()) {
            if ($this->conf->can_some_author_view_decision())
                return VIEWSCORE_AUTHORDEC - 1;
            else
                return VIEWSCORE_AUTHOR - 1;
        } else
            return VIEWSCORE_MAX + 1;
    }

    function author_permissive_view_score_bound() {
        if ($this->conf->can_some_author_view_decision())
            return VIEWSCORE_AUTHORDEC - 1;
        else
            return VIEWSCORE_AUTHOR - 1;
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
        // see also PaperApi::alltags,
        // Contact::list_submitted_papers_with_viewable_tags
        if (!$prow)
            return $this->isPC;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_pc
            || ($rights->allow_pc_broad && $this->conf->tag_seeall)
            || (($this->privChair || $rights->allow_administer)
                && $this->conf->tags()->has_sitewide);
    }

    function can_view_most_tags(PaperInfo $prow = null, $forceShow = null) {
        if (!$prow)
            return $this->isPC;
        $rights = $this->rights($prow, $forceShow);
        return $rights->allow_pc
            || ($rights->allow_pc_broad && $this->conf->tag_seeall);
    }

    function can_view_tag(PaperInfo $prow, $tag, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        $tag = TagInfo::base($tag);
        $twiddle = strpos($tag, "~");
        return ($rights->allow_pc
                || ($rights->allow_pc_broad && $this->conf->tag_seeall)
                || ($this->privChair && $this->conf->tags()->is_sitewide($tag)))
            && ($rights->allow_administer
                || $twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0
                    && (substr($tag, 0, $twiddle) == $this->contactId
                        || $this->conf->tags()->is_votish(substr($tag, $twiddle + 1)))));
    }

    function can_view_peruser_tags(PaperInfo $prow, $tag, $forceShow = null) {
        return $this->can_view_tag($prow, ($this->contactId + 1) . "~$tag", $forceShow);
    }

    function can_view_any_peruser_tags($tag) {
        return $this->privChair
            || ($this->isPC && $this->conf->tags()->is_votish($tag));
    }

    function list_submitted_papers_with_viewable_tags() {
        $pids = array();
        if (!$this->isPC)
            return $pids;
        else if (!$this->privChair && $this->conf->check_track_view_sensitivity()) {
            $q = "select p.paperId, pt.paperTags, r.reviewType from Paper p
                left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag where tag ?a group by paperId) as pt on (pt.paperId=p.paperId)
                left join PaperReview r on (r.paperId=p.paperId and r.contactId=$this->contactId)";
            if ($this->conf->tag_seeall)
                $q .= "\nwhere p.timeSubmitted>0";
            else
                $q .= "\nleft join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=$this->contactId)
                where p.timeSubmitted>0 and (pc.conflictType is null or p.managerContactId=$this->contactId)";
            $result = $this->conf->qe($q, $this->conf->track_tags());
            while ($result && ($prow = PaperInfo::fetch($result, $this)))
                if ((int) $prow->reviewType >= REVIEW_PC
                    || $this->conf->check_tracks($prow, $this, Track::VIEW))
                    $pids[] = (int) $prow->paperId;
            Dbl::free($result);
            return $pids;
        } else if (!$this->privChair && !$this->conf->tag_seeall) {
            $q = "select p.paperId from Paper p
                left join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=$this->contactId)
                where p.timeSubmitted>0 and ";
            if ($this->conf->has_any_manager())
                $q .= "(pc.conflictType is null or p.managerContactId=$this->contactId)";
            else
                $q .= "pc.conflictType is null";
        } else if ($this->privChair && $this->conf->has_any_manager() && !$this->conf->tag_seeall)
            $q = "select p.paperId from Paper p
                left join PaperConflict pc on (pc.paperId=p.paperId and pc.contactId=$this->contactId)
                where p.timeSubmitted>0 and (pc.conflictType is null or p.managerContactId=$this->contactId or p.managerContactId=0)";
        else
            $q = "select p.paperId from Paper p where p.timeSubmitted>0";
        $result = $this->conf->qe($q);
        while (($row = edb_row($result)))
            $pids[] = (int) $row[0];
        Dbl::free($result);
        return $pids;
    }

    function can_change_tag(PaperInfo $prow, $tag, $previndex, $index, $forceShow = null) {
        if ($forceShow === ALWAYS_OVERRIDE)
            return true;
        $rights = $this->rights($prow, $forceShow);
        if (!($rights->allow_pc
              && ($rights->can_administer || $this->conf->timePCViewPaper($prow, false)))) {
            if ($this->privChair
                && $this->conf->tags()->has_sitewide
                && (!$tag || $this->conf->tags()->is_sitewide(TagInfo::base($tag))))
                return true;
            return false;
        }
        if (!$tag)
            return true;
        $tag = TagInfo::base($tag);
        $twiddle = strpos($tag, "~");
        if ($twiddle === 0 && $tag[1] === "~")
            return $rights->can_administer;
        if ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId
            && !$rights->can_administer)
            return false;
        if ($twiddle !== false) {
            $t = $this->conf->tags()->check(substr($tag, $twiddle + 1));
            return !($t && $t->vote && $index < 0);
        } else {
            $t = $this->conf->tags()->check($tag);
            if (!$t)
                return true;
            else if ($t->vote
                     || $t->approval
                     || ($t->track && !$this->privChair))
                return false;
            else
                return $rights->can_administer
                    || ($this->privChair && $t->sitewide)
                    || (!$t->chair && !$t->rank);
        }
    }

    function perm_change_tag(PaperInfo $prow, $tag, $previndex, $index, $forceShow = null) {
        if ($this->can_change_tag($prow, $tag, $previndex, $index, $forceShow))
            return null;
        $rights = $this->rights($prow, $forceShow);
        $whyNot = $prow->initial_whynot();
        $whyNot["tag"] = $tag;
        if (!$this->isPC)
            $whyNot["permission"] = true;
        else if ($rights->conflict_type > 0) {
            $whyNot["conflict"] = true;
            if ($rights->allow_administer)
                $whyNot["forceShow"] = true;
        } else if (!$this->conf->timePCViewPaper($prow, false)) {
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
                $t = $this->conf->tags()->check($tag);
                if ($t && $t->vote)
                    $whyNot["voteTag"] = true;
                else
                    $whyNot["chairTag"] = true;
            }
        }
        return $whyNot;
    }

    function can_change_some_tag(PaperInfo $prow = null, $forceShow = null) {
        if (!$prow)
            return $this->isPC;
        else
            return $this->can_change_tag($prow, null, null, null, $forceShow);
    }

    function perm_change_some_tag(PaperInfo $prow, $forceShow = null) {
        return $this->perm_change_tag($prow, null, null, null, $forceShow);
    }

    function can_change_tag_anno($tag, $forceShow = null) {
        $twiddle = strpos($tag, "~");
        return $this->privChair
            || ($this->isPC
                && !$this->conf->tags()->is_chair($tag)
                && ($twiddle === false
                    || ($twiddle === 0 && $tag[1] !== "~")
                    || ($twiddle > 0 && substr($tag, 0, $twiddle) == $this->contactId)));
    }

    function can_view_reviewer_tags(PaperInfo $prow = null) {
        return $this->act_pc($prow);
    }


    // deadlines

    function my_deadlines($prows = null) {
        // Return cleaned deadline-relevant settings that this user can see.
        global $Now;
        $dl = (object) ["now" => $Now, "email" => $this->email ? : null];
        if ($this->privChair)
            $dl->is_admin = true;
        if ($this->is_author())
            $dl->is_author = true;
        $dl->sub = (object) [];
        $graces = [];

        // submissions
        $sub_reg = $this->conf->setting("sub_reg");
        $sub_update = $this->conf->setting("sub_update");
        $sub_sub = $this->conf->setting("sub_sub");
        $dl->sub->open = +$this->conf->setting("sub_open") > 0;
        $dl->sub->sub = +$sub_sub;
        if ($dl->sub->open)
            $graces[] = [$dl->sub, "sub_grace"];
        if ($sub_reg && $sub_reg < $sub_update)
            $dl->sub->reg = $sub_reg;
        if ($sub_update && $sub_update != $sub_sub)
            $dl->sub->update = $sub_update;

        $sb = $this->conf->submission_blindness();
        if ($sb === Conf::BLIND_ALWAYS)
            $dl->sub->blind = true;
        else if ($sb === Conf::BLIND_OPTIONAL)
            $dl->sub->blind = "optional";
        else if ($sb === Conf::BLIND_UNTILREVIEW)
            $dl->sub->blind = "until-review";

        // responses
        if (+$this->conf->setting("resp_active") > 0) {
            $dlresps = [];
            foreach ($this->conf->resp_round_list() as $i => $rname) {
                $isuf = $i ? "_$i" : "";
                $dlresps[$rname] = $dlresp = (object) [
                    "open" => +$this->conf->setting("resp_open$isuf"),
                    "done" => +$this->conf->setting("resp_done$isuf")
                ];
                if ($dlresp->open)
                    $graces[] = [$dlresp, "resp_grace$isuf"];
            }
            if (!empty($dlresps))
                $dl->resps = $dlresps;
        }

        // final copy deadlines
        if (+$this->conf->setting("final_open") > 0) {
            $dl->final = (object) array("open" => true);
            $final_soft = +$this->conf->setting("final_soft");
            if ($final_soft > $Now)
                $dl->final->done = $final_soft;
            else {
                $dl->final->done = +$this->conf->setting("final_done");
                $dl->final->ishard = true;
            }
            $graces[] = [$dl->final, "final_grace"];
        }

        // reviewer deadlines
        $revtypes = array();
        if ($this->is_reviewer()
            && ($rev_open = +$this->conf->setting("rev_open")) > 0
            && $rev_open <= $Now)
            $dl->rev = (object) ["open" => true];
        if (get($dl, "rev")) {
            $dl->revs = [];
            $k = $this->isPC ? "pcrev" : "extrev";
            foreach ($this->conf->defined_round_list() as $i => $round_name) {
                $isuf = $i ? "_$i" : "";
                $s = +$this->conf->setting("{$k}_soft$isuf");
                $h = +$this->conf->setting("{$k}_hard$isuf");
                $dl->revs[$round_name] = $dlround = (object) ["open" => true];
                if ($h && ($h < $Now || $s < $Now)) {
                    $dlround->done = $h;
                    $dlround->ishard = true;
                } else if ($s)
                    $dlround->done = $s;
            }
            // blindness
            $rb = $this->conf->review_blindness();
            if ($rb === Conf::BLIND_ALWAYS)
                $dl->rev->blind = true;
            else if ($rb === Conf::BLIND_OPTIONAL)
                $dl->rev->blind = "optional";
        }

        // grace periods: give a minute's notice of an impending grace
        // period
        foreach ($graces as $g) {
            if (($grace = $this->conf->setting($g[1])))
                foreach (array("reg", "update", "sub", "done") as $k)
                    if (get($g[0], $k) && $g[0]->$k + 60 < $Now
                        && $g[0]->$k + $grace >= $Now) {
                        $kgrace = "{$k}_ingrace";
                        $g[0]->$kgrace = true;
                    }
        }

        // add meeting tracker
        if (($this->isPC || $this->tracker_kiosk_state)
            && $this->can_view_tracker()) {
            $tracker = MeetingTracker::lookup($this->conf);
            if ($tracker->trackerid
                && ($tinfo = MeetingTracker::info_for($this))) {
                $dl->tracker = $tinfo;
                $dl->tracker_status = MeetingTracker::tracker_status($tracker);
                if ($this->conf->opt("trackerHidden"))
                    $dl->tracker_hidden = true;
                $dl->now = microtime(true);
            }
            if ($tracker->position_at)
                $dl->tracker_status_at = $tracker->position_at;
            if (($tcs = $this->conf->opt("trackerCometSite")))
                $dl->tracker_site = $tcs;
        }

        // permissions
        if ($prows) {
            if (is_object($prows))
                $prows = array($prows);
            $dl->perm = array();
            foreach ($prows as $prow) {
                if (!$this->can_view_paper($prow))
                    continue;
                $perm = $dl->perm[$prow->paperId] = (object) array();
                $rights = $this->rights($prow);
                $admin = $rights->allow_administer;
                if ($admin)
                    $perm->allow_administer = true;
                if ($rights->act_author)
                    $perm->act_author = true;
                if ($rights->act_author_view)
                    $perm->act_author_view = true;
                if ($this->can_review($prow, null, false))
                    $perm->can_review = true;
                if ($this->can_comment($prow, null, true))
                    $perm->can_comment = true;
                else if ($admin && $this->can_comment($prow, null, false))
                    $perm->can_comment = "override";
                if (get($dl, "resps"))
                    foreach ($this->conf->resp_round_list() as $i => $rname) {
                        $crow = (object) array("commentType" => COMMENTTYPE_RESPONSE, "commentRound" => $i);
                        $v = false;
                        if ($this->can_respond($prow, $crow, true))
                            $v = true;
                        else if ($admin && $this->can_respond($prow, $crow, false))
                            $v = "override";
                        if ($v && !isset($perm->can_respond))
                            $perm->can_responds = [];
                        if ($v)
                            $perm->can_responds[$rname] = $v;
                    }
                if (self::can_some_author_view_submitted_review($prow))
                    $perm->some_author_can_view_review = true;
            }
        }

        return $dl;
    }

    function has_reportable_deadline() {
        global $Now;
        $dl = $this->my_deadlines();
        if (get($dl->sub, "reg") || get($dl->sub, "update") || get($dl->sub, "sub"))
            return true;
        if (get($dl, "resps"))
            foreach ($dl->resps as $dlr) {
                if (get($dlr, "open") && $dlr->open < $Now && get($dlr, "done"))
                    return true;
            }
        if (get($dl, "rev") && get($dl->rev, "open") && $dl->rev->open < $Now)
            foreach ($dl->revs as $dlr) {
                if (get($dlr, "done"))
                    return true;
            }
        return false;
    }


    // papers

    function paper_result($options) {
        return $this->conf->paper_result($this, $options);
    }

    function paper_status_info($row, $forceShow = null) {
        if ($row->timeWithdrawn > 0)
            return array("pstat_with", "Withdrawn");
        else if ($row->outcome && $this->can_view_decision($row, $forceShow)) {
            $data = get(self::$status_info_cache, $row->outcome);
            if (!$data) {
                $decclass = ($row->outcome > 0 ? "pstat_decyes" : "pstat_decno");

                $decs = $this->conf->decision_map();
                $decname = get($decs, $row->outcome);
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


    private function unassigned_review_token() {
        while (1) {
            $token = mt_rand(1, 2000000000);
            $result = $this->conf->qe("select reviewId from PaperReview where reviewToken=$token");
            if (edb_nrows($result) == 0)
                return ", reviewToken=$token";
        }
    }

    function assign_review($pid, $reviewer_cid, $type, $extra = array()) {
        global $Now;
        $result = $this->conf->qe("select reviewId, reviewType, reviewRound, reviewModified, reviewToken, requestedBy, reviewSubmitted from PaperReview where paperId=? and contactId=?", $pid, $reviewer_cid);
        $rrow = edb_orow($result);
        Dbl::free($result);
        $reviewId = $rrow ? $rrow->reviewId : 0;

        // can't delete a review that's in progress
        if ($type <= 0 && $rrow && $rrow->reviewType && $rrow->reviewModified > 1) {
            if ($rrow->reviewType >= REVIEW_SECONDARY)
                $type = REVIEW_PC;
            else
                return $reviewId;
        }
        // PC members always get PC reviews
        if ($type == REVIEW_EXTERNAL && get($this->conf->pc_members(), $reviewer_cid))
            $type = REVIEW_PC;

        // change database
        if ($type > 0 && ($round = get($extra, "round_number")) === null)
            $round = $this->conf->assignment_round($type == REVIEW_EXTERNAL);
        if ($type > 0 && (!$rrow || !$rrow->reviewType)) {
            $qa = "";
            if (get($extra, "mark_notify"))
                $qa .= ", timeRequestNotified=$Now";
            if (get($extra, "token"))
                $qa .= $this->unassigned_review_token();
            $new_requester_cid = $this->contactId;
            if (($new_requester = get($extra, "requester_contact")))
                $new_requester_cid = $new_requester->contactId;
            $q = "insert into PaperReview set paperId=$pid, contactId=$reviewer_cid, reviewType=$type, reviewRound=$round, timeRequested=$Now$qa, requestedBy=$new_requester_cid";
        } else if ($type > 0 && ($rrow->reviewType != $type || $rrow->reviewRound != $round)) {
            $q = "update PaperReview set reviewType=$type, reviewRound=$round";
            if (!$rrow->reviewSubmitted)
                $q .= ", reviewNeedsSubmit=1";
            $q .= " where reviewId=$reviewId";
        } else if ($type <= 0 && $rrow && $rrow->reviewType)
            $q = "delete from PaperReview where reviewId=$reviewId";
        else
            return $reviewId;

        if (!($result = $this->conf->qe_raw($q)))
            return false;

        if ($q[0] == "d") {
            $msg = "Removed " . ReviewForm::$revtype_names[$rrow->reviewType] . " review";
            $reviewId = 0;
        } else if ($q[0] == "u")
            $msg = "Changed " . ReviewForm::$revtype_names[$rrow->reviewType] . " review to " . ReviewForm::$revtype_names[$type];
        else {
            $msg = "Added " . ReviewForm::$revtype_names[$type] . " review";
            $reviewId = $result->insert_id;
        }
        $this->conf->log($msg . " by " . $this->email, $reviewer_cid, $pid);

        // on new review, update PaperReviewRefused, ReviewRequest, delegation
        if ($q[0] == "i") {
            $this->conf->ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
            if (($req_email = get($extra, "requested_email")))
                $this->conf->qe("delete from ReviewRequest where paperId=$pid and email=?", $req_email);
            if ($type < REVIEW_SECONDARY)
                $this->update_review_delegation($pid, $new_requester_cid, 1);
        } else if ($q[0] == "d") {
            if ($rrow->reviewType < REVIEW_SECONDARY && $rrow->requestedBy > 0)
                $this->update_review_delegation($pid, $rrow->requestedBy, -1);
        } else {
            if ($type == REVIEW_SECONDARY && $rrow->reviewType != REVIEW_SECONDARY
                && !$rrow->reviewSubmitted)
                $this->update_review_delegation($pid, $reviewer_cid, 0);
        }

        // Mark rev_tokens setting for future update by update_rev_tokens_setting
        if ($rrow && get($rrow, "reviewToken") && $type <= 0)
            $this->conf->settings["rev_tokens"] = -1;
        // Set pcrev_assigntime
        if ($q[0] == "i" && $type >= REVIEW_PC && $this->conf->setting("pcrev_assigntime", 0) < $Now)
            $this->conf->save_setting("pcrev_assigntime", $Now);
        Contact::update_rights();
        return $reviewId;
    }

    function update_review_delegation($pid, $cid, $direction) {
        if ($direction > 0) {
            $this->conf->qe("update PaperReview set reviewNeedsSubmit=-1 where paperId=? and reviewType=" . REVIEW_SECONDARY . " and contactId=? and reviewSubmitted is null and reviewNeedsSubmit=1", $pid, $cid);
        } else {
            $row = Dbl::fetch_first_row($this->conf->qe("select sum(contactId=$cid and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null), sum(reviewType<" . REVIEW_SECONDARY . " and requestedBy=$cid and reviewSubmitted is not null), sum(reviewType<" . REVIEW_SECONDARY . " and requestedBy=$cid) from PaperReview where paperId=$pid"));
            if ($row && $row[0]) {
                $rns = $row[1] ? 0 : ($row[2] ? -1 : 1);
                if ($direction == 0 || $rns != 0)
                    $this->conf->qe("update PaperReview set reviewNeedsSubmit=? where paperId=? and contactId=? and reviewSubmitted is null", $rns, $pid, $cid);
            }
        }
    }

    function unsubmit_review_row($rrow) {
        $needsSubmit = 1;
        if ($rrow->reviewType == REVIEW_SECONDARY) {
            $row = Dbl::fetch_first_row($this->conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where paperId=? and requestedBy=? and reviewType<" . REVIEW_SECONDARY, $rrow->paperId, $rrow->contactId));
            if ($row && $row[0])
                $needsSubmit = 0;
            else if ($row && $row[1])
                $needsSubmit = -1;
        }
        return $this->conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=? where reviewId=?", $needsSubmit, $rrow->reviewId);
    }

    function assign_paper_pc($pids, $type, $reviewer, $extra = array()) {
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
            $result = $this->conf->qe("update Paper set {$type}ContactId=? where paperId" . sql_in_numeric_set($px) . " and {$type}ContactId=?", $revcid, $extra["old_cid"]);
        else
            $result = $this->conf->qe("update Paper set {$type}ContactId=? where paperId" . sql_in_numeric_set($px), $revcid);

        // log, update settings
        if ($result && $result->affected_rows) {
            $this->log_activity_for($revcid, "Set $type", $px);
            if (($type == "lead" || $type == "shepherd") && !$revcid != !$this->conf->setting("paperlead"))
                $this->conf->update_paperlead_setting();
            if ($type == "manager" && !$revcid != !$this->conf->setting("papermanager"))
                $this->conf->update_papermanager_setting();
            return true;
        } else
            return false;
    }
}
