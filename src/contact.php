<?php
// contact.php -- HotCRP helper class representing system users
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Contact_Update {
    public $qv = [];
    public $cdb_qf = [];
    public $changing_email;
    function __construct($changing_email) {
        $this->changing_email = $changing_email;
    }
}

class Contact {
    static public $rights_version = 1;
    static public $trueuser_privChair = null;
    static public $allow_nonexistent_properties = false;

    public $contactId = 0;
    public $contactDbId = 0;
    public $conf;
    public $confid;

    public $firstName = "";
    public $lastName = "";
    public $unaccentedName = "";
    public $nameAmbiguous;
    public $email = "";
    public $preferredEmail = "";
    public $sorter = "";
    public $sort_position;

    public $affiliation = "";
    public $country;
    public $collaborators;
    public $phone;
    public $birthday;
    public $gender;

    private $password = "";
    private $passwordTime = 0;
    private $passwordUseTime = 0;
    private $_contactdb_user = false;

    public $disabled = false;
    public $activity_at = false;
    private $lastLogin;
    public $creationTime = 0;
    private $updateTime = 0;
    private $data = null;
    private $_topic_interest_map;
    private $_name_for_map = [];
    private $_contact_sorter_map = [];
    const WATCH_REVIEW_EXPLICIT = 1;  // only in PaperWatch
    const WATCH_REVIEW = 2;
    const WATCH_REVIEW_ALL = 4;
    const WATCH_REVIEW_MANAGED = 8;
    const WATCH_FINAL_SUBMIT_ALL = 32;
    public $defaultWatch = self::WATCH_REVIEW;

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    const ROLE_AUTHOR = 16;
    const ROLE_REVIEWER = 32;
    const ROLE_REQUESTER = 64;
    private $_db_roles;
    private $_active_roles;
    private $_has_outstanding_review;
    private $_is_metareviewer;
    private $_is_lead;
    private $_is_explicit_manager;
    private $_dangerous_track_mask;
    private $_can_view_pc;
    public $is_site_contact = false;
    private $_rights_version = 0;
    public $roles = 0;
    public $isPC = false;
    public $privChair = false;
    public $contactTags;
    public $tracker_kiosk_state = false;
    const CAP_AUTHORVIEW = 1;
    private $capabilities;
    private $_review_tokens;
    private $_activated = false;
    const OVERRIDE_CONFLICT = 1;
    const OVERRIDE_TIME = 2;
    const OVERRIDE_TAG_CHECKS = 4;
    const OVERRIDE_EDIT_CONDITIONS = 8;
    private $_overrides = 0;
    public $hidden_papers;
    private $_aucollab_matchers;
    private $_aucollab_general_pregexes;
    private $_authored_papers;

    // Per-paper DB information, usually null
    public $conflictType;
    public $myReviewPermissions;
    public $watch;

    static private $status_info_cache = array();


    function __construct($trueuser = null, Conf $conf = null) {
        global $Conf;
        $this->conf = $conf ? : $Conf;
        if ($trueuser)
            $this->merge($trueuser);
        else if ($this->contactId || $this->contactDbId)
            $this->db_load();
        else if ($this->conf->opt("disableNonPC"))
            $this->disabled = true;
    }

    static function fetch($result, Conf $conf) {
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
                $this->contactId = (int) $user->contactId;
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
        foreach (["email", "preferredEmail", "affiliation", "phone",
                  "country", "birthday", "gender"] as $k)
            if (isset($user->$k))
                $this->$k = simplify_whitespace($user->$k);
        if (isset($user->collaborators))
            $this->collaborators = $user->collaborators;
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
            $this->contactTags = $this->contactId ? false : null;
        if (isset($user->activity_at))
            $this->activity_at = (int) $user->activity_at;
        else if (isset($user->lastLogin))
            $this->activity_at = (int) $user->lastLogin;
        if (isset($user->birthday))
            $this->birthday = (int) $user->birthday;
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
            $this->_has_outstanding_review = $user->has_outstanding_review;
        if (isset($user->is_site_contact))
            $this->is_site_contact = $user->is_site_contact;
    }

    private function db_load() {
        $this->contactId = (int) $this->contactId;
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
        if (isset($this->birthday))
            $this->birthday = (int) $this->birthday;
        if ($this->data)
            // this works even if $user->data is a JSON string
            // (array_to_object_recursive($str) === $str)
            $this->data = array_to_object_recursive($this->data);
        if (isset($this->roles))
            $this->assign_roles((int) $this->roles);
        if (isset($this->__isAuthor__))
            $this->_db_roles = ((int) $this->__isAuthor__ > 0 ? self::ROLE_AUTHOR : 0)
                | ((int) $this->__hasReview__ > 0 ? self::ROLE_REVIEWER : 0);
        if (!$this->isPC && $this->conf->opt("disableNonPC"))
            $this->disabled = true;
    }

    function merge_secondary_properties($x) {
        foreach (["preferredEmail", "phone", "country", "password",
                  "collaborators", "birthday", "gender"] as $k)
            if (isset($x->$k))
                $this->$k = $x->$k;
        foreach (["passwordTime", "passwordUseTime", "creationTime",
                  "updateTime", "defaultWatch"] as $k)
            if (isset($x->$k))
                $this->$k = (int) $x->$k;
        if (isset($x->lastLogin))
            $this->activity_at = $this->lastLogin = (int) $x->lastLogin;
        if ($x->data)
            $this->data = array_to_object_recursive($x->data);
    }

    function __set($name, $value) {
        if (!self::$allow_nonexistent_properties)
            error_log(caller_landmark(1) . ": writing nonexistent property $name");
        $this->$name = $value;
    }

    static function set_sorter($c, Conf $conf) {
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

    static function compare($a, $b) {
        return strnatcasecmp($a->sorter, $b->sorter);
    }

    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) != 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) != 0;
    }


    // initialization

    private function actas_user($x, $trueuser) {
        // translate to email
        if (is_numeric($x)) {
            $acct = $this->conf->user_by_id($x);
            $email = $acct ? $acct->email : null;
        } else if ($x === "admin")
            $email = $trueuser->email;
        else
            $email = $x;
        if (!$email || strcasecmp($email, $this->email) == 0)
            return $this;

        // can always turn back into baseuser
        $baseuser = $this;
        if (strcasecmp($this->email, $trueuser->email) != 0
            && ($u = $this->conf->user_by_email($trueuser->email)))
            $baseuser = $u;
        if (strcasecmp($email, $baseuser->email) == 0)
            return $baseuser;

        // cannot actas unless chair
        if (!$this->privChair && !$baseuser->privChair)
            return $this;

        // new account must exist
        $u = $this->conf->user_by_email($email);
        if (!$u && validate_email($email) && get($this->conf->opt, "debugShowSensitiveEmail"))
            $u = Contact::create($this->conf, null, ["email" => $email]);
        if (!$u)
            return $this;

        // cannot turn into a manager of conflicted papers
        if ($this->conf->setting("papermanager")) {
            $result = $this->conf->qe("select paperId from Paper join PaperConflict using (paperId) where managerContactId!=0 and managerContactId!=? and PaperConflict.contactId=? and conflictType>0", $this->contactId, $this->contactId);
            while (($row = $result->fetch_row()))
                $u->hidden_papers[(int) $row[0]] = false;
            Dbl::free($result);
        }

        // otherwise ok
        return $u;
    }

    function activate($qreq) {
        global $Now;
        $this->_activated = true;
        $trueuser = isset($_SESSION["trueuser"]) ? $_SESSION["trueuser"] : null;
        $truecontact = null;

        // Handle actas requests
        if ($qreq && $qreq->actas && $trueuser) {
            $actas = $qreq->actas;
            unset($qreq->actas, $_GET["actas"], $_POST["actas"]);
            $actascontact = $this->actas_user($actas, $trueuser);
            if ($actascontact !== $this) {
                if ($actascontact->email !== $trueuser->email) {
                    hoturl_defaults(array("actas" => $actascontact->email));
                    $_SESSION["last_actas"] = $actascontact->email;
                }
                if ($this->privChair)
                    self::$trueuser_privChair = $actascontact;
                return $actascontact->activate($qreq);
            }
        }

        // Handle invalidate-caches requests
        if ($qreq && $qreq->invalidatecaches && $this->privChair) {
            unset($qreq->invalidatecaches);
            $this->conf->invalidate_caches();
        }

        // Add capabilities from session and request
        if (!$this->conf->opt("disableCapabilities")) {
            if (($caps = $this->conf->session("capabilities"))) {
                $this->capabilities = $caps;
                ++self::$rights_version;
            }
            if ($qreq && (isset($qreq->cap) || isset($qreq->testcap)))
                $this->activate_capabilities($qreq);
        }

        // Add review tokens from session
        if (($rtokens = $this->conf->session("rev_tokens"))) {
            $this->_review_tokens = $rtokens;
            ++self::$rights_version;
        }

        // Maybe auto-create a user
        if ($trueuser
            && strcasecmp($trueuser->email, $this->email) == 0) {
            $trueuser_aucheck = $this->conf->session("trueuser_author_check", 0);
            if (!$this->has_database_account()
                && $trueuser_aucheck + 600 < $Now) {
                $this->conf->save_session("trueuser_author_check", $Now);
                $aupapers = self::email_authored_papers($this->conf, $this->email, $this);
                if (!empty($aupapers))
                    $this->activate_database_account();
            }
            if ($this->has_database_account()
                && $trueuser_aucheck) {
                foreach ($_SESSION as $k => $v) {
                    if (is_array($v)
                        && isset($v["trueuser_author_check"])
                        && $v["trueuser_author_check"] + 600 < $Now)
                        unset($_SESSION[$k]["trueuser_author_check"]);
                }
            }
        }

        // Maybe set up the shared contacts database
        if ($this->conf->opt("contactdb_dsn")
            && $this->has_database_account()
            && $this->conf->session("contactdb_roles", 0) != $this->contactdb_roles()) {
            if ($this->contactdb_update())
                $this->conf->save_session("contactdb_roles", $this->contactdb_roles());
        }

        // Check forceShow
        $this->_overrides = 0;
        if ($qreq && $qreq->forceShow && $this->privChair)
            $this->_overrides |= self::OVERRIDE_CONFLICT;
        if ($qreq && $qreq->override)
            $this->_overrides |= self::OVERRIDE_TIME;

        return $this;
    }

    function overrides() {
        return $this->_overrides;
    }
    function set_overrides($overrides) {
        $old_overrides = $this->_overrides;
        if (!$this->privChair)
            $overrides &= ~self::OVERRIDE_CONFLICT;
        $this->_overrides = $overrides;
        return $old_overrides;
    }
    function add_overrides($overrides) {
        return $this->set_overrides($this->_overrides | $overrides);
    }
    function remove_overrides($overrides) {
        return $this->set_overrides($this->_overrides & ~$overrides);
    }
    function call_with_overrides($overrides, $method /* , arguments... */) {
        $old_overrides = $this->set_overrides($overrides);
        $result = call_user_func_array([$this, $method], array_slice(func_get_args(), 2));
        $this->_overrides = $old_overrides;
        return $result;
    }

    function activate_database_account() {
        assert($this->has_email());
        if (!$this->has_database_account()
            && ($u = Contact::create($this->conf, null, $this))) {
            $this->merge($u);
            $this->contactDbId = 0;
            $this->_contactdb_user = false;
            $this->activate(null);
        }
    }

    function contactdb_user($refresh = false) {
        if ($this->contactDbId && !$this->contactId)
            return $this;
        else if ($refresh || $this->_contactdb_user === false) {
            $cdbu = null;
            if ($this->has_email())
                $cdbu = $this->conf->contactdb_user_by_email($this->email);
            $this->_contactdb_user = $cdbu;
        }
        return $this->_contactdb_user;
    }

    private function _contactdb_save_roles($cdbur) {
        global $Now;
        Dbl::ql($this->conf->contactdb(), "insert into Roles set contactDbId=?, confid=?, roles=?, activity_at=? on duplicate key update roles=values(roles), activity_at=values(activity_at)", $cdbur->contactDbId, $cdbur->confid, $this->contactdb_roles(), $Now);
    }
    function contactdb_update($update_keys = null, $only_update_empty = false) {
        global $Now;
        if (!($cdb = $this->conf->contactdb())
            || !$this->has_database_account()
            || !validate_email($this->email))
            return false;

        $cdbur = $this->conf->contactdb_user_by_email($this->email);
        $cdbux = $cdbur ? : new Contact(null, $this->conf);
        $upd = [];
        foreach (["firstName", "lastName", "affiliation", "country", "collaborators",
                  "birthday", "gender"] as $k)
            if ($this->$k !== null
                && $this->$k !== ""
                && (!$only_update_empty || $cdbux->$k === null || $cdbux->$k === "")
                && (!$cdbur || in_array($k, $update_keys ? : [])))
                $upd[$k] = $this->$k;
        if (!$cdbur) {
            $upd["email"] = $this->email;
            if ($this->password
                && $this->password !== "*"
                && ($this->password[0] !== " " || $this->password[1] === "\$")) {
                $upd["password"] = $this->password;
                $upd["passwordTime"] = $this->passwordTime;
            }
        }
        if (!empty($upd)) {
            $cdbux->apply_updater($upd, true);
            $this->_contactdb_user = false;
        }
        $cdbur = $cdbur ? : $this->conf->contactdb_user_by_email($this->email);
        if ($cdbur->confid
            && (int) $cdbur->roles !== $this->contactdb_roles())
            $this->_contactdb_save_roles($cdbur);
        return $cdbur ? (int) $cdbur->contactDbId : false;
    }

    function is_actas_user() {
        return $this->_activated
            && isset($_SESSION["trueuser"])
            && strcasecmp($_SESSION["trueuser"]->email, $this->email) !== 0;
    }

    private function activate_capabilities($qreq) {
        // Add capabilities from arguments
        if (($cap_req = $qreq->cap)) {
            foreach (preg_split(',\s+,', $cap_req) as $cap)
                $this->apply_capability_text($cap);
            unset($qreq->cap, $_GET["cap"], $_POST["cap"]);
        }

        // Support capability testing
        if ($this->conf->opt("testCapabilities")
            && ($cap_req = $qreq->testcap)
            && preg_match_all('/([-+]?)([1-9]\d*)([A-Za-z]+)/',
                              $cap_req, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $c = ($mm[3] == "a" ? self::CAP_AUTHORVIEW : 0);
                $this->change_paper_capability((int) $mm[2], $c, $mm[1] !== "-");
            }
            unset($qreq->testcap, $_GET["testcap"], $_POST["testcap"]);
        }
    }

    function is_empty() {
        return $this->contactId <= 0 && !$this->capabilities && !$this->email;
    }

    function owns_email($email) {
        return (string) $email !== "" && strcasecmp($email, $this->email) === 0;
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

    private function calculate_name_for($pfx, $user) {
        if ($pfx === "u")
            return $user;
        if ($pfx === "t")
            return Text::name_text($user);
        $n = Text::name_html($user);
        if ($pfx === "r" && isset($user->contactTags)
            && ($colors = $this->user_color_classes_for($user)))
            $n = '<span class="' . $colors . ' taghh">' . $n . '</span>';
        return $n;
    }

    private function name_for($pfx, $x) {
        $cid = is_object($x) ? $x->contactId : $x;
        $key = $pfx . $cid;
        if (isset($this->_name_for_map[$key]))
            return $this->_name_for_map[$key];

        if (+$cid === $this->contactId)
            $x = $this;
        else if (($pc = $this->conf->pc_member_by_id($cid)))
            $x = $pc;

        if (!(is_object($x) && isset($x->firstName) && isset($x->lastName) && isset($x->email))) {
            if ($pfx === "u") {
                $x = $this->conf->user_by_id($cid);
                $this->_contact_sorter_map[$cid] = $x->sorter;
            } else
                $x = $this->name_for("u", $x);
        }

        return ($this->_name_for_map[$key] = $this->calculate_name_for($pfx, $x));
    }

    function name_html_for($x) {
        return $this->name_for("", $x);
    }

    function name_text_for($x) {
        return $this->name_for("t", $x);
    }

    function name_object_for($x) {
        return $this->name_for("u", $x);
    }

    function reviewer_html_for($x) {
        return $this->name_for($this->isPC ? "r" : "", $x);
    }

    function reviewer_text_for($x) {
        return $this->name_for("t", $x);
    }

    function user_color_classes_for(Contact $x) {
        return $x->viewable_color_classes($this);
    }

    function ksort_cid_array(&$a) {
        $pcm = $this->conf->pc_members();
        uksort($a, function ($a, $b) use ($pcm) {
            if (isset($pcm[$a]) && isset($pcm[$b]))
                return $pcm[$a]->sort_position - $pcm[$b]->sort_position;
            if (isset($pcm[$a]))
                $as = $pcm[$a]->sorter;
            else if (isset($this->_contact_sorter_map[$a]))
                $as = $this->_contact_sorter_map[$a];
            else {
                $x = $this->conf->user_by_id($a);
                $as = $this->_contact_sorter_map[$a] = $x->sorter;
            }
            if (isset($pcm[$b]))
                $bs = $pcm[$b]->sorter;
            else if (isset($this->_contact_sorter_map[$b]))
                $bs = $this->_contact_sorter_map[$b];
            else {
                $x = $this->conf->user_by_id($b);
                $bs = $this->_contact_sorter_map[$b] = $x->sorter;
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
        return ($this->_overrides & self::OVERRIDE_CONFLICT) !== 0;
    }

    function is_pc_member() {
        return $this->roles & self::ROLE_PC;
    }

    function is_pclike() {
        return $this->roles & self::ROLE_PCLIKE;
    }

    function role_html() {
        if ($this->roles & (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN | Contact::ROLE_PC)) {
            if ($this->roles & Contact::ROLE_CHAIR)
                return '<span class="pcrole">chair</span>';
            else if (($this->roles & (Contact::ROLE_ADMIN | Contact::ROLE_PC)) == (Contact::ROLE_ADMIN | Contact::ROLE_PC))
                return '<span class="pcrole">PC, sysadmin</span>';
            else if ($this->roles & Contact::ROLE_ADMIN)
                return '<span class="pcrole">sysadmin</span>';
            else
                return '<span class="pcrole">PC</span>';
        } else
            return '';
    }

    function has_tag($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0)
            return true;
        if ($this->contactTags)
            return stripos($this->contactTags, " $t#") !== false;
        if ($this->contactTags === false) {
            trigger_error(caller_landmark(1, "/^Conf::/") . ": Contact $this->email contactTags missing " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
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

    function viewable_tags(Contact $viewer) {
        if ($viewer->can_view_contact_tags() || $viewer->contactId == $this->contactId) {
            $tags = $this->all_contact_tags();
            return $this->conf->tags()->strip_nonviewable($tags, $viewer, null);
        } else
            return "";
    }

    function viewable_color_classes(Contact $viewer) {
        if ($viewer->isPC && ($tags = $this->viewable_tags($viewer)))
            return $this->conf->tags()->color_classes($tags);
        else
            return "";
    }

    private function update_capabilities() {
        ++self::$rights_version;
        if (empty($this->capabilities))
            $this->capabilities = null;
        if ($this->_activated)
            $this->conf->save_session("capabilities", $this->capabilities);
    }

    function capability($name) {
        if ($this->capabilities !== null && isset($this->capabilities[0]))
            return get($this->capabilities[0], $name);
        else
            return null;
    }

    function set_capability($name, $newval) {
        $oldval = $this->capability($name);
        if ($newval !== $oldval) {
            ++self::$rights_version;
            if ($newval !== null)
                $this->capabilities[0][$name] = $newval;
            else
                unset($this->capabilities[0][$name]);
            if (empty($this->capabilities[0]))
                unset($this->capabilities[0]);
            $this->update_capabilities();
        }
        return $newval !== $oldval;
    }

    function change_paper_capability($pid, $bit, $isset) {
        $oldval = 0;
        if ($this->capabilities !== null)
            $oldval = get($this->capabilities, $pid) ? : 0;
        $newval = ($oldval & ~$bit) | ($isset ? $bit : 0);
        if ($newval !== $oldval) {
            if ($newval !== 0)
                $this->capabilities[$pid] = $newval;
            else
                unset($this->capabilities[$pid]);
            $this->update_capabilities();
        }
        return $newval !== $oldval;
    }

    function apply_capability_text($text) {
        if (preg_match(',\A([-+]?)0([1-9][0-9]*)(a)(\S+)\z,', $text, $m)
            && ($result = $this->conf->ql("select paperId, capVersion from Paper where paperId=$m[2]"))
            && ($row = edb_orow($result))) {
            $rowcap = $this->conf->capability_text($row, $m[3]);
            $text = substr($text, strlen($m[1]));
            if ($rowcap === $text
                || $rowcap === str_replace("/", "_", $text))
                return $this->change_paper_capability((int) $m[2], self::CAP_AUTHORVIEW, $m[1] !== "-");
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

    function escape($qreq = null) {
        global $Qreq, $Now;
        $qreq = $qreq ? : $Qreq;

        if ($qreq->ajax) {
            if ($this->is_empty())
                json_exit(["ok" => false, "error" => "You have been signed out.", "loggedout" => true]);
            else
                json_exit(["ok" => false, "error" => "You don’t have permission to access that page."]);
        }

        if ($this->is_empty()) {
            // Preserve post values across session expiration.
            ensure_session();
            $x = array();
            if (Navigation::path())
                $x["__PATH__"] = preg_replace(",^/+,", "", Navigation::path());
            if ($qreq->anchor)
                $x["anchor"] = $qreq->anchor;
            $url = SelfHref::make($qreq, $x, ["raw" => true, "site_relative" => true]);
            $_SESSION["login_bounce"] = [$this->conf->dsn, $url, Navigation::page(), $_POST, $Now + 120];
            if ($qreq->post_ok())
                error_go(false, "You’ve been signed out, so your changes were not saved. After signing in, you may submit them again.");
            else
                error_go(false, "You must sign in to access that page.");
        } else
            error_go(false, "You don’t have permission to access that page.");
    }


    static private $cdb_fields = [
        "firstName" => true, "lastName" => true, "affiliation" => true,
        "country" => true, "collaborators" => true, "birthday" => true,
        "gender" => true
    ];
    static private $no_clean_fields = [
        "collaborators" => true, "defaultWatch" => true, "contactTags" => true
    ];

    private function _save_assign_field($k, $v, Contact_Update $cu) {
        if (!isset(self::$no_clean_fields[$k])) {
            $v = simplify_whitespace($v);
            if ($k === "birthday" && !$v)
                $v = null;
        }
        // change contactdb
        if (isset(self::$cdb_fields[$k])
            && ($this->$k !== $v || $cu->changing_email))
            $cu->cdb_qf[] = $k;
        // change local version
        if ($this->$k !== $v || !$this->contactId)
            $cu->qv[$k] = $v;
        $this->$k = $v;
    }

    static function parse_roles_json($j) {
        $roles = 0;
        if (isset($j->pc) && $j->pc)
            $roles |= self::ROLE_PC;
        if (isset($j->chair) && $j->chair)
            $roles |= self::ROLE_CHAIR | self::ROLE_PC;
        if (isset($j->sysadmin) && $j->sysadmin)
            $roles |= self::ROLE_ADMIN;
        return $roles;
    }

    const SAVE_NOTIFY = 1;
    const SAVE_ANY_EMAIL = 2;
    const SAVE_IMPORT = 4;
    const SAVE_NO_EXPORT = 8;
    function save_json($cj, $actor, $flags) {
        global $Me, $Now;
        assert(!!$this->contactId);
        $old_roles = $this->roles;
        $old_email = $this->email;
        $old_disabled = $this->disabled ? 1 : 0;
        $changing_email = isset($cj->email) && strtolower($cj->email) !== strtolower((string) $old_email);
        $cu = new Contact_Update($changing_email);

        $aupapers = null;
        if ($changing_email)
            $aupapers = self::email_authored_papers($this->conf, $cj->email, $cj);

        // check whether this user is changing themselves
        $changing_other = false;
        if ($this->conf->contactdb()
            && $Me
            && (strcasecmp($this->email, $Me->email) != 0 || $Me->is_actas_user()))
            $changing_other = true;

        // Main fields
        foreach (["firstName", "lastName", "email", "affiliation", "collaborators",
                  "preferredEmail", "country", "birthday", "gender", "phone"] as $k) {
            if (isset($cj->$k))
                $this->_save_assign_field($k, $cj->$k, $cu);
        }
        if (isset($cj->preferred_email) && !isset($cj->preferredEmail))
            $this->_save_assign_field("preferredEmail", $cj->preferred_email, $cu);
        $this->_save_assign_field("unaccentedName", Text::unaccented_name($this->firstName, $this->lastName), $cu);
        self::set_sorter($this, $this->conf);

        // Disabled
        $disabled = $old_disabled;
        if (isset($cj->disabled))
            $disabled = $cj->disabled ? 1 : 0;
        if ($disabled !== $old_disabled || !$this->contactId)
            $cu->qv["disabled"] = $this->disabled = $disabled;

        // Data
        $old_datastr = $this->data_str();
        $data = get($cj, "data", (object) array());
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
        if (!empty($cu->qv))
            $cu->qv["updateTime"] = $this->updateTime = $Now;

        // Follow
        if (isset($cj->follow)) {
            $w = 0;
            if (get($cj->follow, "reviews"))
                $w |= self::WATCH_REVIEW;
            if (get($cj->follow, "allreviews"))
                $w |= self::WATCH_REVIEW_ALL;
            if (get($cj->follow, "managedreviews"))
                $w |= self::WATCH_REVIEW_MANAGED;
            if (get($cj->follow, "allfinal"))
                $w |= self::WATCH_FINAL_SUBMIT_ALL;
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

        // Initial save
        if (count($cu->qv)) { // always true if $inserting
            $q = "update ContactInfo set "
                . join("=?, ", array_keys($cu->qv)) . "=?"
                . " where contactId=$this->contactId";
            if (!($result = $this->conf->qe_apply($q, array_values($cu->qv))))
                return $result;
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
            $this->_topic_interest_map = null;
        }

        // Roles
        $roles = $old_roles;
        if (isset($cj->roles)) {
            $roles = self::parse_roles_json($cj->roles);
            if ($roles !== $old_roles)
                $this->save_roles($roles, $actor);
        }

        // Update authorship
        if ($aupapers)
            $this->save_authored_papers($aupapers);

        // Contact DB (must precede password)
        $cdb = $this->conf->contactdb();
        if ($changing_email)
            $this->_contactdb_user = false;
        if ($cdb && !($flags & self::SAVE_NO_EXPORT)
            && (!empty($cu->cdb_qf) || $roles !== $old_roles))
            $this->contactdb_update($cu->cdb_qf, $changing_other);

        // Password
        if (isset($cj->new_password))
            $this->change_password($cj->new_password, 0);

        // Beware PC cache
        if (($roles | $old_roles) & Contact::ROLE_PCLIKE)
            $this->conf->invalidate_caches(["pc" => 1]);

        $actor = $actor ? : $Me;
        if ($actor && $this->contactId == $actor->contactId)
            $this->mark_activity();

        return true;
    }

    function change_email($email) {
        assert($this->has_database_account());
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
        while (($row = PaperInfo::fetch($result, null, $conf))) {
            foreach ($row->author_list() as $au) {
                if (strcasecmp($au->email, $email) == 0) {
                    $aupapers[] = $row->paperId;
                    if ($reg
                        && ($au->firstName !== "" || $au->lastName !== "")
                        && !isset($reg->firstName)
                        && !isset($reg->lastName)) {
                        $reg->firstName = $au->firstName;
                        $reg->lastName = $au->lastName;
                    }
                    if ($reg
                        && $au->affiliation !== ""
                        && !isset($reg->affiliation)) {
                        $reg->affiliation = $au->affiliation;
                    }
                }
            }
        }
        return $aupapers;
    }

    private function save_authored_papers($aupapers) {
        if (!empty($aupapers) && $this->contactId) {
            $this->conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values ?v on duplicate key update conflictType=greatest(conflictType, " . CONFLICT_AUTHOR . ")", array_map(function ($pid) {
                return [$pid, $this->contactId, CONFLICT_AUTHOR];
            }, $aupapers));
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
        foreach (array(self::ROLE_PC => "pc",
                       self::ROLE_ADMIN => "sysadmin",
                       self::ROLE_CHAIR => "chair") as $role => $type)
            if (($new_roles & $role) && !($old_roles & $role))
                $this->conf->log_for($actor ? : $this, $this, "Added as $type");
            else if (!($new_roles & $role) && ($old_roles & $role))
                $this->conf->log_for($actor ? : $this, $this, "Removed as $type");
        // save the roles bits
        if ($old_roles != $new_roles) {
            $this->conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
        }
        return $old_roles != $new_roles;
    }

    private function _make_create_updater($reg, $is_cdb) {
        $cj = [];
        if ($this->firstName === "" && $this->lastName === "") {
            if (get_s($reg, "firstName") !== "")
                $cj["firstName"] = (string) $reg->firstName;
            if (get_s($reg, "lastName") !== "")
                $cj["lastName"] = (string) $reg->lastName;
        }
        foreach (["affiliation", "country", "gender", "birthday",
                  "preferredEmail", "phone"] as $k) {
            if ((string) $this->$k === ""
                && isset($reg->$k)
                && $reg->$k !== "")
                $cj[$k] = (string) $reg->$k;
        }
        if ($is_cdb ? !$this->contactDbId : !$this->contactId)
            $cj["email"] = $reg->email;
        return $cj;
    }

    function apply_updater($updater, $is_cdb) {
        global $Now;
        if ($is_cdb) {
            $db = $this->conf->contactdb();
            $idk = "contactDbId";
        } else {
            $db = $this->conf->dblink;
            $idk = "contactId";
            if (isset($updater["firstName"]) || isset($updater["lastName"])) {
                $updater["firstName"] = get($updater, "firstName", $this->firstName);
                $updater["lastName"] = get($updater, "lastName", $this->lastName);
                $updater["unaccentedName"] = Text::unaccented_name($updater["firstName"], $updater["lastName"]);
            }
        }
        if ($this->$idk) {
            $qv = array_values($updater);
            $qv[] = $this->$idk;
            $result = Dbl::qe_apply($db, "update ContactInfo set " . join("=?, ", array_keys($updater)) . "=? where $idk=?", $qv);
        } else {
            assert(isset($updater["email"]));
            if (!isset($updater["password"])) {
                $updater["password"] = validate_email($updater["email"]) ? self::random_password() : "*";
                $updater["passwordTime"] = $Now;
            }
            if (!$is_cdb)
                $updater["creationTime"] = $Now;
            $result = Dbl::qe_apply($db, "insert into ContactInfo set " . join("=?, ", array_keys($updater)) . "=? on duplicate key update firstName=firstName", array_values($updater));
            if ($result)
                $updater[$idk] = (int) $result->insert_id;
        }
        if (($ok = !!$result)) {
            foreach ($updater as $k => $v)
                $this->$k = $v;
        }
        Dbl::free($result);
        return $ok;
    }

    static function create(Conf $conf, $actor, $reg, $flags = 0) {
        global $Me, $Now;

        // clean registration
        if (is_array($reg))
            $reg = (object) $reg;
        assert(is_string($reg->email));
        $reg->email = trim($reg->email);
        assert($reg->email !== "");
        if (!isset($reg->firstName) && isset($reg->first))
            $reg->firstName = $reg->first;
        if (!isset($reg->lastName) && isset($reg->last))
            $reg->lastName = $reg->last;
        if (isset($reg->name) && !isset($reg->firstName) && !isset($reg->lastName))
            list($reg->firstName, $reg->lastName) = Text::split_name($reg->name);
        if (isset($reg->preferred_email) && !isset($reg->preferredEmail))
            $reg->preferredEmail = $reg->preferred_email;

        // look up existing accounts
        $valid_email = validate_email($reg->email);
        $u = $conf->user_by_email($reg->email) ? : new Contact(null, $conf);
        if (($cdb = $conf->contactdb()) && $valid_email)
            $cdbu = $conf->contactdb_user_by_email($reg->email);
        else
            $cdbu = null;
        $create = !$u->contactId;
        $aupapers = [];

        // if local does not exist, create it
        if (!$u->contactId) {
            if (($flags & self::SAVE_IMPORT) && !$cdbu)
                return null;
            if (!$valid_email && !($flags & self::SAVE_ANY_EMAIL))
                return null;
            if ($valid_email)
                // update registration from authorship information
                $aupapers = self::email_authored_papers($conf, $reg->email, $reg);
        }

        // create or update contactdb user
        if ($cdb && $valid_email) {
            $cdbu = $cdbu ? : new Contact(null, $conf);
            if (($upd = $cdbu->_make_create_updater($reg, true)))
                $cdbu->apply_updater($upd, true);
        }

        // create or update local user
        $upd = $u->_make_create_updater($cdbu ? : $reg, false);
        if (!$u->contactId) {
            if (($cdbu && $cdbu->disabled) || get($reg, "disabled"))
                $upd["disabled"] = 1;
            if ($cdbu) {
                $upd["password"] = $cdbu->password;
                $upd["passwordTime"] = $cdbu->passwordTime;
            }
        }
        if ($upd) {
            if (!($u->apply_updater($upd, false)))
                // failed because concurrent create (unlikely)
                $u = $conf->user_by_email($reg->email);
        }

        // update paper authorship
        if ($aupapers) {
            $u->save_authored_papers($aupapers);
            if ($cdbu)
                // can't use `$cdbu` itself b/c no `confid`
                $u->_contactdb_save_roles($u->contactdb_user());
        }

        // notify on creation
        if ($create) {
            if (($flags & self::SAVE_NOTIFY) && !$u->disabled)
                $u->sendAccountInfo("create", false);
            $type = $u->disabled ? "disabled " : "";
            $conf->log_for($actor && $actor->has_email() ? $actor : $u, $u, "Created {$type}account");
            // if ($Me && $Me->privChair)
            //    $conf->infoMsg("Created {$type}account for <a href=\"" . hoturl("profile", "u=" . urlencode($u->email)) . "\">" . Text::user_html_nolink($u) . "</a>.");
        }

        return $u;
    }


    // PASSWORDS
    //
    // password "" or null: reset password (user must recreate password)
    // password "*": invalid password, cannot be reset by user
    // password starting with " ": legacy hashed password using hash_hmac
    //     format: " HASHMETHOD KEYID SALT[16B]HMAC"
    // password starting with " $": password hashed by password_hash
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

    static function valid_password($input) {
        return $input !== "" && $input !== "0" && $input !== "*"
            && trim($input) === $input;
    }

    static function random_password($length = 14) {
        return hotcrp_random_password($length);
    }

    static function password_storage_cleartext() {
        return opt("safePasswords") < 1;
    }

    function allow_contactdb_password() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->password && $cdbu->password !== "*";
    }

    function plaintext_password() {
        // Return the currently active plaintext password. This might not
        // equal $this->password because of the cdb.
        if ($this->password === "" || $this->password === "*") {
            if ($this->contactId
                && ($cdbu = $this->contactdb_user()))
                return $cdbu->plaintext_password();
            else
                return false;
        } else if ($this->password[0] === " " || $this->password === "*")
            return false;
        else
            return $this->password;
    }

    function password_is_reset() {
        if (($cdbu = $this->contactdb_user()))
            return (string) $cdbu->password === ""
                && ((string) $this->password === ""
                    || $this->passwordTime < $cdbu->passwordTime);
        else
            return $this->password === "";
    }

    function password_used() {
        return $this->passwordUseTime > 0;
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

    private function check_hashed_password($input, $pwhash) {
        if ($input == ""
            || $input === "*"
            || (string) $pwhash === ""
            || $pwhash === "*")
            return false;
        else if ($pwhash[0] !== " ")
            return $pwhash === $input;
        else if ($pwhash[1] === "\$")
            return password_verify($input, substr($pwhash, 2));
        else {
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
        return false;
    }

    private function password_hash_method() {
        $m = $this->conf->opt("passwordHashMethod");
        return is_int($m) ? $m : PASSWORD_DEFAULT;
    }

    private function check_password_encryption($hash, $iscdb) {
        $safe = $this->conf->opt($iscdb ? "contactdb_safePasswords" : "safePasswords");
        if ($safe < 1
            || ($method = $this->password_hash_method()) === false
            || ($hash !== "" && $hash[0] !== " " && $safe == 1))
            return false;
        else if ($hash === "" || $hash[0] !== " ")
            return true;
        else
            return $hash[1] !== "\$"
                || password_needs_rehash(substr($hash, 2), $method);
    }

    function hash_password($input) {
        if (($method = $this->password_hash_method()) !== false)
            return " \$" . password_hash($input, $method);
        else
            return $input;
    }

    function check_password($input) {
        global $Now;
        assert(!$this->conf->external_login());
        if (($this->contactId && $this->disabled)
            || !self::valid_password($input))
            return false;

        $cdbu = $this->contactdb_user();
        $cdbok = false;
        if ($cdbu
            && ($hash = $cdbu->password)
            && $cdbu->allow_contactdb_password()
            && ($cdbok = $this->check_hashed_password($input, $hash))) {
            $updater = ["passwordUseTime" => $Now];
            if ($this->check_password_encryption($hash, true)) {
                $updater["password"] = $this->hash_password($input);
                $updater["passwordTime"] = $Now;
            }
            $cdbu->apply_updater($updater, true);
        }

        $localok = false;
        if ($this->contactId
            && ($hash = $this->password)
            && ($localok = $this->check_hashed_password($input, $hash))) {
            if ($cdbu
                && !$cdbok
                && $this->passwordTime
                && $cdbu->passwordTime > $this->passwordTime)
                error_log($this->conf->dbname . ": " . $this->email . ": using old local password (" . post_value(true) . ")");
            $updater = ["passwordUseTime" => $Now];
            if ($this->check_password_encryption($hash, false)) {
                $updater["password"] = $cdbok ? $cdbu->password : $this->hash_password($input);
                $updater["passwordTime"] = $Now;
            }
            $this->apply_updater($updater, false);
        }

        return $cdbok || $localok;
    }

    const CHANGE_PASSWORD_PLAINTEXT = 1;
    const CHANGE_PASSWORD_ENABLE = 2;
    function change_password($new, $flags) {
        global $Now;
        assert(!$this->conf->external_login());

        $cdbu = $this->contactdb_user();
        if (($flags & self::CHANGE_PASSWORD_ENABLE)
            && ($this->password !== "" || ($cdbu && (string) $cdbu->password !== "")))
            return false;

        if ($new === null) {
            $new = self::random_password();
            $flags |= self::CHANGE_PASSWORD_PLAINTEXT;
        }
        assert(self::valid_password($new));

        if ($cdbu) {
            $hash = $new;
            if ($hash
                && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", true))
                $hash = $this->hash_password($hash);
            $cdbu->password = $hash;
            $cdbu->passwordTime = $Now;
            Dbl::ql($this->conf->contactdb(), "update ContactInfo set password=?, passwordTime=? where contactDbId=?", $cdbu->password, $cdbu->passwordTime, $cdbu->contactDbId);
            if ($this->contactId && $this->password) {
                $this->password = "";
                $this->passwordTime = $cdbu->passwordTime;
                $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
            }
        } else if ($this->contactId) {
            $hash = $new;
            if ($hash
                && !($flags & self::CHANGE_PASSWORD_PLAINTEXT)
                && $this->check_password_encryption("", false))
                $hash = $this->hash_password($hash);
            $this->password = $hash;
            $this->passwordTime = $Now;
            $this->conf->ql("update ContactInfo set password=?, passwordTime=? where contactId=?", $this->password, $this->passwordTime, $this->contactId);
        }
        return true;
    }


    function sendAccountInfo($sendtype, $sensitive) {
        assert(!$this->disabled);

        $cdbu = $this->contactdb_user();
        $rest = array();
        if ($sendtype === "create") {
            if ($cdbu && $cdbu->passwordUseTime)
                $template = "@activateaccount";
            else
                $template = "@createaccount";
        } else if ($sendtype === "forgot") {
            if ($this->conf->opt("safePasswords") <= 1 && $this->plaintext_password())
                $template = "@accountinfo";
            else {
                $capmgr = $this->conf->capability_manager($cdbu ? "U" : null);
                $rest["capability"] = $capmgr->create(CAPTYPE_RESETPASSWORD, array("user" => $this, "timeExpires" => time() + 259200));
                $this->conf->log_for($this, null, "Created password reset " . substr($rest["capability"], 0, 8) . "...");
                $template = "@resetpassword";
            }
        } else {
            if ($this->plaintext_password())
                $template = "@accountinfo";
            else
                return false;
        }

        $mailer = new HotCRPMailer($this->conf, $this, null, $rest);
        $prep = $mailer->make_preparation($template, $rest);
        if ($prep->sendable
            || !$sensitive
            || $this->conf->opt("debugShowSensitiveEmail")) {
            $prep->send();
            return $template;
        } else {
            Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    function mark_login() {
        global $Now;
        // at least one login every 30 days is marked as activity
        if ((int) $this->activity_at <= $Now - 2592000
            || (($cdbu = $this->contactdb_user())
                && ((int) $cdbu->activity_at <= $Now - 2592000)))
            $this->mark_activity();
    }

    function mark_activity() {
        global $Now;
        if ((!$this->activity_at || $this->activity_at < $Now)
            && !$this->is_anonymous_user()) {
            $this->activity_at = $Now;
            if ($this->contactId)
                $this->conf->ql("update ContactInfo set lastLogin=$Now where contactId=$this->contactId");
            if (($cdbu = $this->contactdb_user())
                && $cdbu->confid
                && (int) $cdbu->activity_at <= $Now - 604800)
                $this->_contactdb_save_roles($cdbu);
        }
    }

    function log_activity($text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log_for($this, $this, $text, $paperId);
    }

    function log_activity_for($user, $text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user())
            $this->conf->log_for($this, $user, $text, $paperId);
    }


    // HotCRP roles

    static function update_rights() {
        ++self::$rights_version;
    }

    private function load_author_reviewer_status() {
        // Load from database
        $result = null;
        if ($this->contactId > 0) {
            $qs = ["exists (select * from PaperConflict where contactId=? and conflictType>=" . CONFLICT_AUTHOR . ")",
                   "exists (select * from PaperReview where contactId=?)"];
            $qv = [$this->contactId, $this->contactId];
            if ($this->isPC) {
                $qs[] = "exists (select * from PaperReview where requestedBy=? and contactId!=?)";
                array_push($qv, $this->contactId, $this->contactId);
            } else
                $qs[] = "0";
            if ($this->_review_tokens) {
                $qs[] = "exists (select * from PaperReview where reviewToken?a)";
                $qv[] = $this->_review_tokens;
            } else
                $qs[] = "0";
            $result = $this->conf->qe_apply("select " . join(", ", $qs), $qv);
        }
        $row = $result ? $result->fetch_row() : null;
        $this->_db_roles = ($row && $row[0] > 0 ? self::ROLE_AUTHOR : 0)
            | ($row && $row[1] > 0 ? self::ROLE_REVIEWER : 0)
            | ($row && $row[2] > 0 ? self::ROLE_REQUESTER : 0);
        $this->_active_roles = $this->_db_roles
            | ($row && $row[3] > 0 ? self::ROLE_REVIEWER : 0);
        Dbl::free($result);

        // Update contact information from capabilities
        if ($this->capabilities) {
            foreach ($this->capabilities as $pid => $cap)
                if ($pid && ($cap & self::CAP_AUTHORVIEW))
                    $this->_active_roles |= self::ROLE_AUTHOR;
        }
    }

    private function check_rights_version() {
        if ($this->_rights_version !== self::$rights_version) {
            $this->_db_roles = $this->_active_roles =
                $this->_has_outstanding_review = $this->_is_lead =
                $this->_is_explicit_manager = $this->_is_metareviewer =
                $this->_can_view_pc = $this->_dangerous_track_mask =
                $this->_authored_papers = null;
            $this->_rights_version = self::$rights_version;
        }
    }

    function is_author() {
        $this->check_rights_version();
        if (!isset($this->_active_roles))
            $this->load_author_reviewer_status();
        return ($this->_active_roles & self::ROLE_AUTHOR) !== 0;
    }

    function authored_papers() {
        $this->check_rights_version();
        if ($this->_authored_papers === null)
            $this->_authored_papers = $this->is_author() ? $this->conf->paper_set($this, ["author" => true, "tags" => true])->all() : [];
        return $this->_authored_papers;
    }

    function has_review() {
        $this->check_rights_version();
        if (!isset($this->_active_roles))
            $this->load_author_reviewer_status();
        return ($this->_active_roles & self::ROLE_REVIEWER) !== 0;
    }

    function is_reviewer() {
        return $this->isPC || $this->has_review();
    }

    function is_metareviewer() {
        if (!isset($this->_is_metareviewer)) {
            if ($this->isPC && $this->conf->setting("metareviews"))
                $this->_is_metareviewer = !!$this->conf->fetch_ivalue("select exists (select * from PaperReview where contactId={$this->contactId} and reviewType=" . REVIEW_META . ")");
            else
                $this->_is_metareviewer = false;
        }
        return $this->_is_metareviewer;
    }

    function contactdb_roles() {
        $this->is_author(); // load _db_roles
        return $this->roles | ($this->_db_roles & (self::ROLE_AUTHOR | self::ROLE_REVIEWER));
    }

    function has_outstanding_review() {
        $this->check_rights_version();
        if ($this->_has_outstanding_review === null) {
            $this->_has_outstanding_review = $this->has_review()
                && $this->conf->fetch_ivalue("select exists (select * from PaperReview join Paper using (paperId) where Paper.timeSubmitted>0 and " . $this->act_reviewer_sql("PaperReview") . " and reviewNeedsSubmit!=0)");
        }
        return $this->_has_outstanding_review;
    }

    function is_requester() {
        $this->check_rights_version();
        if (!isset($this->_active_roles))
            $this->load_author_reviewer_status();
        return ($this->_active_roles & self::ROLE_REQUESTER) !== 0;
    }

    function is_discussion_lead() {
        $this->check_rights_version();
        if (!isset($this->_is_lead)) {
            $result = null;
            if ($this->contactId > 0)
                $result = $this->conf->qe("select exists (select * from Paper where leadContactId=?)", $this->contactId);
            $this->_is_lead = edb_nrows($result) > 0;
            Dbl::free($result);
        }
        return $this->_is_lead;
    }

    function is_explicit_manager() {
        $this->check_rights_version();
        if (!isset($this->_is_explicit_manager)) {
            $this->_is_explicit_manager = false;
            if ($this->contactId > 0
                && $this->isPC
                && ($this->conf->check_any_admin_tracks($this)
                    || ($this->conf->has_any_manager()
                        && $this->conf->fetch_value("select exists (select * from Paper where managerContactId=?)", $this->contactId) > 0)))
                $this->_is_explicit_manager = true;
        }
        return $this->_is_explicit_manager;
    }

    function is_manager() {
        return $this->privChair || $this->is_explicit_manager();
    }

    function is_track_manager() {
        return $this->privChair || $this->conf->check_any_admin_tracks($this);
    }


    // review tokens

    function review_tokens() {
        return $this->_review_tokens ? : [];
    }

    function active_review_token_for(PaperInfo $prow, ReviewInfo $rrow = null) {
        if ($this->_review_tokens) {
            if ($rrow) {
                if ($rrow->reviewToken && in_array($rrow->reviewToken, $this->_review_tokens))
                    return (int) $rrow->reviewToken;
            } else {
                foreach ($prow->reviews_by_id() as $rrow)
                    if ($rrow->reviewToken && in_array($rrow->reviewToken, $this->_review_tokens))
                        return (int) $rrow->reviewToken;
            }
        }
        return false;
    }

    function change_review_token($token, $on) {
        assert($token !== false || $on === false);
        if (!$this->_review_tokens)
            $this->_review_tokens = array();
        $old_ntokens = count($this->_review_tokens);
        if (!$on && $token === false)
            $this->_review_tokens = array();
        else {
            $pos = array_search($token, $this->_review_tokens);
            if (!$on && $pos !== false)
                array_splice($this->_review_tokens, $pos, 1);
            else if ($on && $pos === false && $token != 0)
                $this->_review_tokens[] = $token;
        }
        $new_ntokens = count($this->_review_tokens);
        if ($new_ntokens == 0)
            $this->_review_tokens = null;
        if ($new_ntokens != $old_ntokens)
            self::update_rights();
        if ($this->_activated && $new_ntokens != $old_ntokens)
            $this->conf->save_session("rev_tokens", $this->_review_tokens);
        return $new_ntokens != $old_ntokens;
    }


    // topic interests

    function topic_interest_map() {
        global $Me;
        if ($this->_topic_interest_map !== null)
            return $this->_topic_interest_map;
        if ($this->contactId <= 0 || !$this->conf->has_topics())
            return array();
        if (($this->roles & self::ROLE_PCLIKE)
            && $this !== $Me
            && ($pcm = $this->conf->pc_members())
            && $this === get($pcm, $this->contactId))
            self::load_topic_interests($pcm);
        else {
            $result = $this->conf->qe("select topicId, interest from TopicInterest where contactId={$this->contactId} and interest!=0");
            $this->_topic_interest_map = Dbl::fetch_iimap($result);
        }
        return $this->_topic_interest_map;
    }

    static function load_topic_interests($contacts) {
        if (empty($contacts))
            return;
        $cbyid = [];
        foreach ($contacts as $c) {
            $c->_topic_interest_map = [];
            $cbyid[$c->contactId] = $c;
        }
        $result = $c->conf->qe("select contactId, topicId, interest from TopicInterest where interest!=0 order by contactId");
        $c = null;
        while (($row = edb_row($result))) {
            if (!$c || $c->contactId != $row[0])
                $c = get($cbyid, $row[0]);
            if ($c)
                $c->_topic_interest_map[(int) $row[1]] = (int) $row[2];
        }
        Dbl::free($result);
    }


    // permissions policies

    private function rights(PaperInfo $prow, $forceShow = null) {
        $ci = $prow->contact_info($this);

        // check first whether administration is allowed
        if (!isset($ci->allow_administer)) {
            $ci->allow_administer = false;
            if (($this->contactId > 0
                 && (!$prow->managerContactId
                     || $prow->managerContactId == $this->contactId
                     || !$ci->conflictType)
                 && ($this->privChair
                     || $prow->managerContactId == $this->contactId
                     || ($this->isPC
                         && $this->is_track_manager()
                         && $this->conf->check_admin_tracks($prow, $this))))
                || $this->is_site_contact) {
                $ci->allow_administer = true;
            }
        }

        // correct $forceShow
        if (!$ci->allow_administer)
            $forceShow = false;
        else if ($forceShow === null)
            $forceShow = ($this->_overrides & self::OVERRIDE_CONFLICT) !== 0;
        else if ($forceShow === "any")
            $forceShow = !!$ci->forced_rights_link;
        if ($forceShow)
            $ci = $ci->get_forced_rights();

        // set other rights
        if ($ci->rights_forced !== $forceShow) {
            $ci->rights_forced = $forceShow;

            // check current administration status
            $ci->can_administer = $ci->allow_administer
                && (!$ci->conflictType || $forceShow);

            // check PC tracking
            // (see also can_accept_review_assignment*)
            $tracks = $this->conf->has_tracks();
            $am_lead = $this->contactId > 0 && isset($prow->leadContactId)
                && $prow->leadContactId == $this->contactId;
            $isPC = $this->isPC
                && (!$tracks
                    || $ci->reviewType >= REVIEW_PC
                    || $am_lead
                    || !$this->conf->check_track_view_sensitivity()
                    || $this->conf->check_tracks($prow, $this, Track::VIEW));

            // check whether PC privileges apply
            $ci->allow_pc_broad = $ci->allow_administer || $isPC;
            $ci->allow_pc = $ci->can_administer
                || ($isPC && !$ci->conflictType);

            // check whether this is a potential reviewer
            // (existing external reviewer or PC)
            if ($ci->reviewType > 0 || $am_lead || $ci->allow_administer)
                $ci->potential_reviewer = true;
            else if ($ci->allow_pc)
                $ci->potential_reviewer = !$tracks
                    || $this->conf->check_tracks($prow, $this, Track::UNASSREV);
            else
                $ci->potential_reviewer = false;
            $ci->allow_review = $ci->potential_reviewer
                && ($ci->can_administer || !$ci->conflictType);

            // check author allowance
            $ci->act_author = $ci->conflictType >= CONFLICT_AUTHOR;
            $ci->allow_author = $ci->act_author || $ci->allow_administer;

            // check author view allowance (includes capabilities)
            // If an author-view capability is set, then use it -- unless
            // this user is a PC member or reviewer, which takes priority.
            $ci->view_conflict_type = $ci->conflictType;
            if (isset($this->capabilities)
                && isset($this->capabilities[$prow->paperId])
                && ($this->capabilities[$prow->paperId] & self::CAP_AUTHORVIEW)
                && !$isPC
                && !$ci->review_status)
                $ci->view_conflict_type = CONFLICT_AUTHOR;
            $ci->act_author_view = $ci->view_conflict_type >= CONFLICT_AUTHOR;
            $ci->allow_author_view = $ci->act_author_view || $ci->allow_administer;

            // check blindness
            $bs = $this->conf->submission_blindness();
            $ci->nonblind = $bs == Conf::BLIND_NEVER
                || ($bs == Conf::BLIND_OPTIONAL
                    && !$prow->blind)
                || ($bs == Conf::BLIND_UNTILREVIEW
                    && $ci->review_status > 0)
                || ($prow->outcome > 0
                    && ($isPC || $ci->allow_review)
                    && $this->conf->time_reviewer_view_accepted_authors());

            // check dangerous track mask
            if ($ci->allow_administer && $this->_dangerous_track_mask === null)
                $this->_dangerous_track_mask = $this->conf->dangerous_track_mask($this);
        }

        return $ci;
    }

    function __rights(PaperInfo $prow, $forceShow = null) {
        // public access point; to be avoided
        return $this->rights($prow, $forceShow);
    }

    function override_deadlines($rights) {
        if (!($this->_overrides & self::OVERRIDE_TIME))
            return false;
        if ($rights && $rights instanceof PaperInfo)
            $rights = $this->rights($rights);
        return $rights ? $rights->allow_administer : $this->privChair;
    }

    function allow_administer(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer;
        } else
            return $this->privChair;
    }

    function can_meaningfully_override(PaperInfo $prow) {
        if ($this->is_manager()) {
            $rights = $this->rights($prow, "any");
            return $rights->allow_administer
                && ($rights->conflictType > 0 || $this->_dangerous_track_mask);
        } else
            return false;
    }

    function can_change_password($acct) {
        if ($this->privChair
            && !$this->conf->opt("chairHidePasswords"))
            return true;
        else
            return $acct
                && $this->contactId > 0
                && $this->contactId == $acct->contactId
                && isset($_SESSION)
                && isset($_SESSION["trueuser"])
                && strcasecmp($_SESSION["trueuser"]->email, $acct->email) == 0;
    }

    function can_administer(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->can_administer;
        } else
            return $this->privChair;
    }

    private function _can_administer_for_track(PaperInfo $prow, $rights, $ttype) {
        return $rights->can_administer
            && (!($this->_dangerous_track_mask & (1 << $ttype))
                || $this->conf->check_tracks($prow, $this, $ttype)
                || ($this->_overrides & self::OVERRIDE_CONFLICT) !== 0);
    }

    function can_administer_for_track(PaperInfo $prow = null, $ttype) {
        if ($prow)
            return $this->_can_administer_for_track($prow, $this->rights($prow), $ttype);
        else
            return $this->privChair;
    }

    function act_pc(PaperInfo $prow = null, $forceShow = null) {
        if ($prow) {
            $rights = $this->rights($prow, $forceShow);
            return $rights->allow_pc;
        } else
            return $this->isPC;
    }

    function can_view_pc() {
        $this->check_rights_version();
        if ($this->_can_view_pc === null) {
            if ($this->is_manager())
                $this->_can_view_pc = 2;
            else if ($this->isPC)
                $this->_can_view_pc = $this->conf->opt("secretPC") ? 0 : 2;
            else
                $this->_can_view_pc = $this->conf->opt("privatePC") ? 0 : 1;
        }
        return $this->_can_view_pc > 0;
    }
    function can_view_contact_tags() {
        return $this->privChair
            || ($this->can_view_pc() && $this->_can_view_pc > 1);
    }

    function can_view_tracker() {
        return $this->privChair
            || ($this->isPC && $this->conf->check_default_track($this, Track::VIEWTRACKER))
            || $this->tracker_kiosk_state;
    }

    function view_conflict_type(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->view_conflict_type;
        } else
            return 0;
    }

    function act_author_view(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->act_author_view;
    }

    function act_author_view_sql($table, $only_if_complex = false) {
        $m = [];
        if (isset($this->capabilities) && !$this->isPC) {
            foreach ($this->capabilities as $pid => $cap)
                if ($pid && ($cap & Contact::CAP_AUTHORVIEW))
                    $m[] = "Paper.paperId=$pid";
        }
        if (empty($m) && $this->contactId && $only_if_complex)
            return false;
        if ($this->contactId)
            $m[] = "$table.conflictType>=" . CONFLICT_AUTHOR;
        if (count($m) > 1)
            return "(" . join(" or ", $m) . ")";
        else
            return empty($m) ? "false" : $m[0];
    }

    function act_reviewer_sql($table) {
        $sql = $this->contactId ? "$table.contactId={$this->contactId}" : "false";
        if (($rev_tokens = $this->review_tokens()))
            $sql = "($sql or $table.reviewToken in (" . join(",", $rev_tokens) . "))";
        return $sql;
    }

    function can_start_paper() {
        return $this->email
            && ($this->conf->timeStartPaper()
                || $this->override_deadlines(null));
    }

    function perm_start_paper() {
        if ($this->can_start_paper())
            return null;
        return array("deadline" => "sub_reg", "override" => $this->privChair);
    }

    function can_edit_paper(PaperInfo $prow) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_administer || $prow->has_author($this);
    }

    function can_update_paper(PaperInfo $prow) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && (($prow->outcome >= 0 && $this->conf->timeUpdatePaper($prow))
                || $this->override_deadlines($rights));
    }

    function perm_update_paper(PaperInfo $prow) {
        if ($this->can_update_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = "edit_paper";
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->outcome < 0 && $this->can_view_decision($prow))
            $whyNot["rejected"] = 1;
        if ($prow->timeSubmitted > 0 && $this->conf->setting("sub_freeze") > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$this->conf->timeUpdatePaper($prow) && !$this->override_deadlines($rights))
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
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = "edit_paper";
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if ($prow->timeSubmitted > 0)
            $whyNot["updateSubmitted"] = 1;
        if (!$this->conf->timeFinalizePaper($prow) && !$this->override_deadlines($rights))
            $whyNot["deadline"] = "sub_sub";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function can_withdraw_paper(PaperInfo $prow) {
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && ($prow->outcome == 0 || $this->override_deadlines($rights));
    }

    function perm_withdraw_paper(PaperInfo $prow) {
        if ($this->can_withdraw_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->make_whynot();
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = "edit_paper";
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        else if ($prow->outcome != 0 && !$this->override_deadlines($rights))
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
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = "edit_paper";
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

    function can_submit_final_paper(PaperInfo $prow) {
        // see also EditFinal_SearchTerm
        $rights = $this->rights($prow, "any");
        return $rights->allow_author
            && $prow->timeWithdrawn <= 0
            && $prow->outcome > 0
            && $this->conf->collectFinalPapers()
            && $this->can_view_decision($prow)
            && ($this->conf->time_submit_final_version()
                || $this->override_deadlines($rights));
    }

    function perm_submit_final_paper(PaperInfo $prow) {
        if ($this->can_submit_final_paper($prow))
            return null;
        $rights = $this->rights($prow, "any");
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view)
            $whyNot["signin"] = "edit_paper";
        else if (!$rights->allow_author)
            $whyNot["author"] = 1;
        if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        // NB logic order here is important elsewhere
        // Don’t report “rejected” error to admins
        if ($prow->outcome <= 0
            || (!$rights->allow_administer
                && !$this->can_view_decision($prow)))
            $whyNot["rejected"] = 1;
        else if (!$this->conf->collectFinalPapers())
            $whyNot["deadline"] = "final_open";
        else if (!$this->conf->time_submit_final_version()
                 && !$this->override_deadlines($rights))
            $whyNot["deadline"] = "final_done";
        if ($rights->allow_administer)
            $whyNot["override"] = 1;
        return $whyNot;
    }

    function has_hidden_papers() {
        return $this->hidden_papers !== null;
    }

    function can_view_paper(PaperInfo $prow, $pdf = false) {
        // hidden_papers is set when a chair with a conflicted, managed
        // paper “becomes” a user
        if ($this->hidden_papers !== null
            && isset($this->hidden_papers[$prow->paperId])) {
            $this->hidden_papers[$prow->paperId] = true;
            return false;
        }
        if ($this->privChair)
            return true;
        $rights = $this->rights($prow, "any");
        return $rights->allow_author_view
            || ($rights->review_status != 0
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
        $whyNot = $prow->make_whynot();
        $base_count = count($whyNot);
        if (!$rights->allow_author_view
            && !$rights->review_status
            && !$rights->allow_pc_broad)
            $whyNot["permission"] = "view_paper";
        else {
            if ($prow->timeWithdrawn > 0)
                $whyNot["withdrawn"] = 1;
            else if ($prow->timeSubmitted <= 0)
                $whyNot["notSubmitted"] = 1;
            if ($rights->allow_pc_broad
                && !$this->conf->timePCViewPaper($prow, false))
                $whyNot["deadline"] = "sub_sub";
            if ($pdf
                && count($whyNot) == $base_count
                && $this->can_view_paper($prow))
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
            || ($this->isPC && $this->conf->has_any_pc_visible_pdf());
    }

    function can_view_document_history(PaperInfo $prow) {
        if ($this->privChair)
            return true;
        $rights = $this->rights($prow, "any");
        return $rights->act_author || $rights->can_administer;
    }

    function can_view_manager(PaperInfo $prow = null) {
        if ($this->privChair)
            return true;
        if (!$prow)
            return (!$this->conf->opt("hideManager") && $this->is_reviewer())
                || ($this->isPC && $this->is_explicit_manager());
        $rights = $this->rights($prow, "any");
        return $prow->managerContactId == $this->contactId
            || ($rights->potential_reviewer && !$this->conf->opt("hideManager"));
    }

    function can_view_lead(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->can_administer
                || ($this->contactId > 0
                    && isset($prow->leadContactId)
                    && $prow->leadContactId == $this->contactId)
                || (($rights->allow_pc || $rights->allow_review)
                    && $this->can_view_review_identity($prow, null));
        } else
            return $this->isPC;
    }

    function can_view_shepherd(PaperInfo $prow = null) {
        // XXX Allow shepherd view when outcome == 0 && can_view_decision.
        // This is a mediocre choice, but people like to reuse the shepherd field
        // for other purposes, and I might hear complaints.
        if ($prow) {
            return $this->act_pc($prow)
                || (!$this->conf->setting("shepherd_hide")
                    && $this->can_view_decision($prow)
                    && $this->can_view_review($prow, null));
        } else {
            return $this->isPC
                || (!$this->conf->setting("shepherd_hide")
                    && $this->can_view_some_decision_as_author());
        }
    }

    /* NB caller must check can_view_paper() */
    function can_view_authors(PaperInfo $prow, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        return ($rights->nonblind
                && $prow->timeSubmitted != 0
                && ($rights->allow_pc_broad
                    || $rights->review_status != 0))
            || ($rights->nonblind
                && $prow->timeWithdrawn <= 0
                && $rights->allow_pc_broad
                && $this->conf->can_pc_see_all_submissions())
            || ($rights->allow_administer
                ? $rights->nonblind || $rights->rights_forced /* chair can't see blind authors unless forceShow */
                : $rights->act_author_view);
    }

    function allow_view_authors(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->allow_administer
            || $rights->act_author_view
            || ($rights->nonblind
                && $prow->timeSubmitted != 0
                && ($rights->allow_pc_broad
                    || $rights->review_status != 0))
            || ($rights->nonblind
                && $prow->timeWithdrawn <= 0
                && $rights->allow_pc_broad
                && $this->conf->can_pc_see_all_submissions());
    }

    function can_view_some_authors() {
        return $this->is_manager()
            || $this->is_author()
            || ($this->is_reviewer()
                && ($this->conf->submission_blindness() != Conf::BLIND_ALWAYS
                    || $this->conf->time_reviewer_view_accepted_authors()));
    }

    function can_view_conflicts(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->allow_administer || $rights->act_author_view)
            return true;
        if (!$rights->allow_pc_broad && !$rights->potential_reviewer)
            return false;
        $pccv = $this->conf->setting("sub_pcconfvis");
        return $pccv == 2
            || (!$pccv && $this->can_view_authors($prow))
            || (!$pccv && $this->conf->setting("tracker")
                && MeetingTracker::is_paper_tracked($prow)
                && $this->can_view_tracker());
    }

    function can_view_paper_option(PaperInfo $prow, $opt) {
        if (!is_object($opt)
            && !($opt = $this->conf->paper_opts->get($opt)))
            return false;
        if (!$this->can_view_paper($prow, $opt->has_document()))
            return false;
        if ($opt->final
            && ($prow->outcome <= 0
                || !$this->can_view_decision($prow))
            && ($opt->id === DTYPE_FINAL
                ? $prow->finalPaperStorageId <= 1
                : !$prow->option($opt->id)))
            return false;
        if ($opt->edit_condition()
            && !($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS)
            && !$opt->test_edit_condition($prow))
            return false;
        $rights = $this->rights($prow);
        $oview = $opt->visibility;
        if ($rights->allow_administer)
            return $oview !== "nonblind" || $this->can_view_authors($prow);
        else
            return $rights->act_author_view
                || (($rights->review_status != 0
                     || $rights->allow_pc_broad)
                    && (!$oview
                        || $oview == "rev"
                        || ($oview == "nonblind"
                            && $this->can_view_authors($prow))));
    }

    function user_option_list() {
        if ($this->conf->has_any_accepted() && $this->can_view_some_decision())
            return $this->conf->paper_opts->option_list();
        else
            return $this->conf->paper_opts->nonfinal_option_list();
    }

    function perm_view_paper_option(PaperInfo $prow, $opt) {
        if ($this->can_view_paper_option($prow, $opt))
            return null;
        if (!is_object($opt) && !($opt = $this->conf->paper_opts->get($opt)))
            return $prow->make_whynot();
        if (($whyNot = $this->perm_view_paper($prow, $opt->has_document())))
            return $whyNot;
        $whyNot = $prow->make_whynot();
        $rights = $this->rights($prow);
        $oview = $opt->visibility;
        if ($rights->allow_administer
            ? $oview === "nonblind"
              && !$this->can_view_authors($prow)
            : !$rights->act_author_view
              && ($oview === "admin"
                  || ((!$oview || $oview == "rev")
                      && !$rights->review_status
                      && !$rights->allow_pc_broad)
                  || ($oview == "nonblind"
                      && !$this->can_view_authors($prow))))
            $whyNot["optionPermission"] = $opt;
        else if ($opt->final && ($prow->outcome <= 0 || !$this->can_view_decision($prow)))
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

    function is_my_review(ReviewInfo $rrow = null) {
        return $rrow
            && ($rrow->contactId == $this->contactId
                || ($this->_review_tokens
                    && $rrow->reviewToken
                    && in_array($rrow->reviewToken, $this->_review_tokens)));
    }

    function is_owned_review(ReviewInfo $rrow = null) {
        return $rrow
            && ($rrow->contactId == $this->contactId
                || ($this->_review_tokens && $rrow->reviewToken && in_array($rrow->reviewToken, $this->_review_tokens))
                || ($rrow->requestedBy == $this->contactId
                    && $rrow->reviewType == REVIEW_EXTERNAL
                    && $this->conf->setting("pcrev_editdelegate")));
    }

    function can_view_review_assignment(PaperInfo $prow, $rrow) {
        $rights = $this->rights($prow);
        return $rights->allow_administer
            || $rights->allow_pc
            || $rights->review_status != 0
            || $this->can_view_review($prow, $rrow);
    }

    static function can_some_author_respond(PaperInfo $prow) {
        return $prow->conf->any_response_open;
    }

    static function can_some_author_view_submitted_review(PaperInfo $prow) {
        if (self::can_some_author_respond($prow))
            return true;
        else if ($prow->conf->au_seerev == Conf::AUSEEREV_TAGS)
            return $prow->has_any_tag($prow->conf->tag_au_seerev);
        else
            return $prow->conf->au_seerev != 0;
    }

    private function can_view_submitted_review_as_author(PaperInfo $prow) {
        return self::can_some_author_respond($prow)
            || $this->conf->au_seerev == Conf::AUSEEREV_YES
            || ($this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
                && (!$this->has_review()
                    || !$this->has_outstanding_review()))
            || ($this->conf->au_seerev == Conf::AUSEEREV_TAGS
                && $prow->has_any_tag($this->conf->tag_au_seerev));
    }

    function can_view_some_review() {
        return $this->is_reviewer()
            || ($this->is_author()
                && ($this->conf->au_seerev != 0
                    || $this->conf->any_response_open));
    }

    private function seerev_setting(PaperInfo $prow, $rrow, $rights) {
        $round = $rrow ? $rrow->reviewRound : "max";
        if ($rights->allow_pc) {
            $rs = $this->conf->round_setting("pc_seeallrev", $round);
            if (!$this->conf->has_tracks())
                return $rs;
            if ($this->conf->check_required_tracks($prow, $this, Track::VIEWREVOVERRIDE))
                return Conf::PCSEEREV_YES;
            if ($this->conf->check_tracks($prow, $this, Track::VIEWREV)) {
                if (!$this->conf->check_tracks($prow, $this, Track::VIEWALLREV))
                    $rs = 0;
                return $rs;
            }
        } else {
            if ($this->conf->round_setting("extrev_view", $round))
                return 0;
        }
        return -1;
    }

    private function seerevid_setting(PaperInfo $prow, $rrow, $rights) {
        $round = $rrow ? $rrow->reviewRound : "max";
        if ($rights->allow_pc) {
            if ($this->conf->check_required_tracks($prow, $this, Track::VIEWREVOVERRIDE))
                return Conf::PCSEEREV_YES;
            if ($this->conf->check_tracks($prow, $this, Track::VIEWREVID)) {
                $s = $this->conf->round_setting("pc_seeblindrev", $round);
                if ($s >= 0)
                    return $s ? 0 : Conf::PCSEEREV_YES;
            }
        } else {
            if ($this->conf->round_setting("extrev_view", $round) == 2)
                return 0;
        }
        return -1;
    }

    function can_view_review(PaperInfo $prow, $rrow, $forceShow = null, $viewscore = null) {
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else if ($viewscore === null)
            $viewscore = VIEWSCORE_AUTHOR;
        if ($rrow && !($rrow instanceof ReviewInfo))
            error_log("not ReviewInfo " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        assert(!$rrow || $prow->paperId == $rrow->paperId);
        $rights = $this->rights($prow, $forceShow);
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREV)
            || $rights->reviewType == REVIEW_META
            || ($rrow
                && $this->is_owned_review($rrow)
                && $viewscore >= VIEWSCORE_REVIEWERONLY))
            return true;
        $rrowSubmitted = !$rrow || $rrow->reviewSubmitted > 0;
        $seerev = $this->seerev_setting($prow, $rrow, $rights);
        // See also PaperInfo::can_view_review_identity_of.
        return ($rights->act_author_view
                && $rrowSubmitted
                && (!$rrow || $rrow->reviewOrdinal > 0)
                && $this->can_view_submitted_review_as_author($prow)
                && ($viewscore >= VIEWSCORE_AUTHOR
                    || ($viewscore >= VIEWSCORE_AUTHORDEC
                        && $prow->outcome
                        && $this->can_view_decision($prow, $forceShow))))
            || ($rights->allow_pc
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && $seerev > 0
                && ($seerev != Conf::PCSEEREV_UNLESSANYINCOMPLETE
                    || !$this->has_outstanding_review())
                && ($seerev != Conf::PCSEEREV_UNLESSINCOMPLETE
                    || !$rights->review_status))
            || ($rights->review_status != 0
                && !$rights->view_conflict_type
                && $rrowSubmitted
                && $viewscore >= VIEWSCORE_PC
                && $prow->review_not_incomplete($this)
                && $seerev >= 0);
    }

    function perm_view_review(PaperInfo $prow, $rrow, $forceShow = null, $viewscore = null) {
        if ($this->can_view_review($prow, $rrow, $forceShow, $viewscore))
            return null;
        $rrowSubmitted = !$rrow || $rrow->reviewSubmitted > 0;
        $rights = $this->rights($prow, $forceShow);
        $whyNot = $prow->make_whynot();
        if ((!$rights->act_author_view
             && !$rights->allow_pc
             && !$rights->review_status)
            || ($rights->allow_pc
                && !$this->conf->check_tracks($prow, $this, Track::VIEWREV)))
            $whyNot["permission"] = "view_review";
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else if ($rights->act_author_view
                 && $this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
                 && $this->has_outstanding_review()
                 && $this->has_review())
            $whyNot["reviewsOutstanding"] = 1;
        else if ($rights->act_author_view
                 && !$rrowSubmitted)
            $whyNot["permission"] = "view_review";
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
                 && $this->seerev_setting($prow, $rrow, $rights) == Conf::PCSEEREV_UNLESSANYINCOMPLETE
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

    function can_view_review_identity(PaperInfo $prow, ReviewInfo $rrow = null, $forceShow = null) {
        $rights = $this->rights($prow, $forceShow);
        // See also PaperInfo::can_view_review_identity_of.
        // See also ReviewerFexpr.
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || $rights->reviewType == REVIEW_META
            || ($rrow && $rrow->requestedBy == $this->contactId && $rights->allow_pc)
            || ($rrow && $this->is_owned_review($rrow)))
            return true;
        $seerevid_setting = $this->seerevid_setting($prow, $rrow, $rights);
        return ($rights->allow_pc
                && $seerevid_setting == Conf::PCSEEREV_YES)
            || ($rights->allow_review
                && $prow->review_not_incomplete($this)
                && $seerevid_setting >= 0)
            || !$this->conf->is_review_blind($rrow);
    }

    function can_view_some_review_identity() {
        $tags = "";
        if (($t = $this->conf->permissive_track_tag_for($this, Track::VIEWREVOVERRIDE))
            || ($t = $this->conf->permissive_track_tag_for($this, Track::VIEWREVID)))
            $tags = " $t#0 ";
        if ($this->isPC)
            $rtype = $this->is_metareviewer() ? REVIEW_META : REVIEW_PC;
        else
            $rtype = $this->is_reviewer() ? REVIEW_EXTERNAL : 0;
        $prow = new PaperInfo([
            "conflictType" => 0, "managerContactId" => 0,
            "myReviewPermissions" => "$rtype 1 0",
            "paperId" => 1, "timeSubmitted" => 1,
            "blind" => false, "outcome" => 1,
            "paperTags" => $tags
        ], $this);
        $overrides = $this->add_overrides(self::OVERRIDE_CONFLICT);
        $answer = $this->can_view_review_identity($prow, null);
        $this->set_overrides($overrides);
        return $answer;
    }

    function can_view_review_round(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            || $rights->allow_pc
            || $rights->allow_review;
    }

    function can_view_review_time(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        return !$rights->act_author_view
            || ($rrow && $rrow->reviewAuthorSeen
                && $rrow->reviewAuthorSeen <= $rrow->reviewAuthorModified);
    }

    function can_view_review_requester(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        return $this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($rrow && $rrow->requestedBy == $this->contactId && $rights->allow_pc)
            || ($rrow && $this->is_owned_review($rrow))
            || ($rights->allow_pc && $this->can_view_review_identity($prow, $rrow));
    }

    function can_request_review(PaperInfo $prow, $check_time) {
        $rights = $this->rights($prow);
        return ($rights->reviewType >= REVIEW_PC
                || ($this->contactId > 0
                    && isset($prow->leadContactId)
                    && $prow->leadContactId == $this->contactId)
                || $rights->allow_administer)
            && (!$check_time
                || $this->conf->time_review(null, false, true)
                || $this->override_deadlines($rights));
    }

    function perm_request_review(PaperInfo $prow, $check_time) {
        if ($this->can_request_review($prow, $check_time))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ($rights->reviewType < REVIEW_PC
            && ($this->contactId <= 0
                || !isset($prow->leadContactId)
                || $prow->leadContactId != $this->contactId)
            && !$rights->allow_administer)
            $whyNot["permission"] = "request_review";
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

    function timeReview(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        if ($rights->reviewType > 0
            || ($rrow
                && $this->is_owned_review($rrow))
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
            && ($rights->reviewType > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV)
                || $this->conf->check_tracks($prow, $this, Track::UNASSREV));
    }

    function can_accept_review_assignment_ignore_conflict(PaperInfo $prow = null) {
        if (!$prow)
            return $this->isPC && $this->conf->check_all_tracks($this, Track::ASSREV);
        $rights = $this->rights($prow);
        return ($rights->allow_administer
                || $this->isPC)
            && ($rights->reviewType > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV));
    }

    function can_accept_review_assignment(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return ($rights->allow_pc
                || ($this->isPC && !$rights->conflictType))
            && ($rights->reviewType > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV));
    }

    private function rights_owned_review($rights, $rrow) {
        if ($rrow)
            return $rights->can_administer || $this->is_owned_review($rrow);
        else
            return $rights->reviewType > 0;
    }

    function can_review(PaperInfo $prow, ReviewInfo $rrow = null, $submit = false) {
        assert(!$rrow || $rrow->paperId == $prow->paperId);
        $rights = $this->rights($prow);
        if ($submit && !$this->can_clickthrough("review"))
            return false;
        return ($this->rights_owned_review($rights, $rrow)
                && $this->conf->time_review($rrow, $rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review(null, true, true))
            || ($rights->can_administer
                && (($prow->timeSubmitted > 0 && !$submit)
                    || $this->override_deadlines($rights)));
    }

    function perm_review(PaperInfo $prow, $rrow, $submit = false) {
        if ($this->can_review($prow, $rrow, $submit))
            return null;
        $rights = $this->rights($prow);
        $rrow_cid = $rrow ? $rrow->contactId : 0;
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        $whyNot = $prow->make_whynot();
        if ($rrow && $rrow_cid != $this->contactId
            && !$rights->allow_administer)
            $whyNot["differentReviewer"] = 1;
        else if (!$rights->allow_pc && !$this->rights_owned_review($rights, $rrow))
            $whyNot["permission"] = "review";
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            if ($rights->conflictType && !$rights->can_administer)
                $whyNot["conflict"] = 1;
            else if ($rights->allow_review
                     && !$this->rights_owned_review($rights, $rrow)
                     && (!$rrow || $rrow_cid == $this->contactId))
                $whyNot["reviewNotAssigned"] = 1;
            else if ($this->can_review($prow, $rrow, false)
                     && !$this->can_clickthrough("review"))
                $whyNot["clickthrough"] = 1;
            else
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer
                && ($rights->conflictType || $prow->timeSubmitted <= 0))
                $whyNot["forceShow"] = 1;
            if ($rights->allow_administer && isset($whyNot["deadline"]))
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function perm_submit_review(PaperInfo $prow, $rrow) {
        return $this->perm_review($prow, $rrow, true);
    }

    function can_create_review_from(PaperInfo $prow, Contact $user) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            && ($prow->timeSubmitted > 0 || $this->override_deadlines($rights))
            && (!$user->isPC || $user->can_accept_review_assignment($prow))
            && ($this->conf->time_review(null, true, true) || $this->override_deadlines($rights));
    }

    function perm_create_review_from(PaperInfo $prow, Contact $user) {
        if ($this->can_create_review_from($prow, $user))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer)
            $whyNot["administer"] = 1;
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            if ($user->isPC && !$user->can_accept_review_assignment($prow))
                $whyNot["unacceptableReviewer"] = 1;
            if (!$this->conf->time_review(null, true, true))
                $whyNot["deadline"] = ($user->isPC ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer
                && ($rights->conflictType || $prow->timeSubmitted <= 0))
                $whyNot["forceShow"] = 1;
            if ($rights->allow_administer && isset($whyNot["deadline"]))
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function can_clickthrough($ctype) {
        if (!$this->privChair && $this->conf->opt("clickthrough_$ctype")) {
            $csha1 = sha1($this->conf->message_html("clickthrough_$ctype"));
            $data = $this->data("clickthrough");
            return $data && get($data, $csha1);
        } else
            return true;
    }

    function can_view_review_ratings(PaperInfo $prow, ReviewInfo $rrow = null, $override_self = false) {
        $rs = $this->conf->setting("rev_ratings");
        $rights = $this->rights($prow);
        if (!$this->can_view_review($prow, $rrow)
            || (!$rights->allow_pc && !$rights->allow_review)
            || ($rs != REV_RATINGS_PC && $rs != REV_RATINGS_PC_EXTERNAL))
            return false;
        if (!$rrow
            || $override_self
            || $rrow->contactId != $this->contactId
            || $this->can_administer($prow)
            || $this->conf->setting("pc_seeallrev")
            || (isset($rrow->allRatings) && strpos($rrow->allRatings, ",") !== false))
            return true;
        // Do not show rating counts if rater identity is unambiguous.
        // See also PaperSearch::_clauseTermSetRating.
        $nsubraters = 0;
        foreach ($prow->reviews_by_id() as $rrow)
            if ($rrow->reviewNeedsSubmit == 0
                && $rrow->contactId != $this->contactId
                && ($rs == REV_RATINGS_PC_EXTERNAL
                    || ($rs == REV_RATINGS_PC && $rrow->reviewType > REVIEW_EXTERNAL)))
                ++$nsubraters;
        return $nsubraters >= 2;
    }

    function can_view_some_review_ratings() {
        $rs = $this->conf->setting("rev_ratings");
        return $this->is_reviewer() && ($rs == REV_RATINGS_PC || $rs == REV_RATINGS_PC_EXTERNAL);
    }

    function can_rate_review(PaperInfo $prow, $rrow) {
        return $this->can_view_review_ratings($prow, $rrow, true)
            && !$this->is_my_review($rrow);
    }


    function is_my_comment(PaperInfo $prow, $crow) {
        if ($crow->contactId == $this->contactId)
            return true;
        if ($this->_review_tokens) {
            foreach ($prow->reviews_of_user($crow->contactId) as $rrow)
                if ($rrow->reviewToken && in_array($rrow->reviewToken, $this->_review_tokens))
                    return true;
        }
        return false;
    }

    function can_comment(PaperInfo $prow, $crow, $submit = false) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE))
            return $this->can_respond($prow, $crow, $submit);
        $rights = $this->rights($prow);
        $author = $rights->act_author
            && $this->conf->setting("cmt_author") > 0
            && $this->can_view_submitted_review_as_author($prow);
        return ($author
                || ($rights->allow_review
                    && ($prow->timeSubmitted > 0
                        || $rights->review_status != 0
                        || ($rights->allow_administer && $rights->rights_forced))
                    && ($this->conf->setting("cmt_always") > 0
                        || $this->conf->time_review(null, $rights->allow_pc, true)
                        || ($rights->allow_administer
                            && (!$submit || $this->override_deadlines($rights))))))
            && (!$crow
                || !$crow->contactId
                || $rights->allow_administer
                || $this->is_my_comment($prow, $crow)
                || ($author
                    && ($crow->commentType & COMMENTTYPE_BYAUTHOR)));
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
        $whyNot = $prow->make_whynot();
        if ($crow && $crow->contactId != $this->contactId
            && !$rights->allow_administer)
            $whyNot["differentReviewer"] = 1;
        else if (!$rights->allow_pc
                 && !$rights->allow_review
                 && (!$rights->act_author
                     || $this->conf->setting("cmt_author", 0) <= 0))
            $whyNot["permission"] = "comment";
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            if ($rights->conflictType > 0)
                $whyNot["conflict"] = 1;
            else
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            if ($rights->allow_administer && $rights->conflictType)
                $whyNot["forceShow"] = 1;
            if ($rights->allow_administer && isset($whyNot['deadline']))
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function perm_submit_comment(PaperInfo $prow, $crow) {
        return $this->perm_comment($prow, $crow, true);
    }

    function can_respond(PaperInfo $prow, CommentInfo $crow, $submit = false) {
        if ($prow->timeSubmitted <= 0
            || !($crow->commentType & COMMENTTYPE_RESPONSE)
            || !($rrd = get($prow->conf->resp_rounds(), $crow->commentRound)))
            return false;
        $rights = $this->rights($prow);
        return ($rights->can_administer
                || $rights->act_author)
            && (($rights->allow_administer
                 && (!$submit || $this->override_deadlines($rights)))
                || $rrd->time_allowed(true))
            && (!$rrd->search
                || $rrd->search->test($prow));
    }

    function perm_respond(PaperInfo $prow, CommentInfo $crow, $submit = false) {
        if ($this->can_respond($prow, $crow, $submit))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer
            && !$rights->act_author)
            $whyNot["permission"] = "respond";
        else if ($prow->timeWithdrawn > 0)
            $whyNot["withdrawn"] = 1;
        else if ($prow->timeSubmitted <= 0)
            $whyNot["notSubmitted"] = 1;
        else {
            $whyNot["deadline"] = "resp_done";
            if ($crow->commentRound)
                $whyNot["deadline"] .= "_" . $crow->commentRound;
            if ($rights->allow_administer && $rights->conflictType)
                $whyNot["forceShow"] = 1;
            if ($rights->allow_administer)
                $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    function preferred_resp_round_number(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->act_author)
            foreach ($prow->conf->resp_rounds() as $rrd)
                if ($rrd->time_allowed())
                    return $rrd->number;
        return false;
    }

    function can_view_comment(PaperInfo $prow, $crow, $forceShow = null) {
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_AUTHOR;
        $rights = $this->rights($prow, $forceShow);
        return ($crow && $this->is_my_comment($prow, $crow))
            || $rights->can_administer
            || ($rights->act_author_view
                && ($ctype & (COMMENTTYPE_BYAUTHOR | COMMENTTYPE_RESPONSE)))
            || ($rights->act_author_view
                && $ctype >= COMMENTTYPE_AUTHOR
                && !($ctype & COMMENTTYPE_DRAFT)
                && $this->can_view_submitted_review_as_author($prow))
            || (!$rights->view_conflict_type
                && !($ctype & COMMENTTYPE_DRAFT)
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER)
                && $this->can_view_review($prow, null, $forceShow)
                && ($this->conf->setting("cmt_revid")
                    || $ctype >= COMMENTTYPE_AUTHOR
                    || $this->can_view_review_identity($prow, null, $forceShow)));
    }

    function can_view_new_comment_ignore_conflict(PaperInfo $prow) {
        // Goal: Return true if this user is part of the comment mention
        // completion for a new comment on $prow.
        // Problem: If authors are hidden, should we mention this user or not?
        $rights = $this->rights($prow, null);
        return $rights->can_administer
            || $rights->allow_pc;
    }

    function canViewCommentReviewWheres() {
        if ($this->privChair
            || ($this->isPC && $this->conf->setting("pc_seeallrev") > 0))
            return array();
        else
            return array("(" . $this->act_author_view_sql("PaperConflict")
                         . " or MyPaperReview.reviewId is not null)");
    }

    function can_view_comment_identity(PaperInfo $prow, $crow, $forceShow = null) {
        if ($crow && ($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_BYAUTHOR)))
            return $this->can_view_authors($prow, $forceShow);
        $rights = $this->rights($prow, $forceShow);
        return $this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($crow && $crow->contactId == $this->contactId)
            || (($rights->allow_pc
                 || ($rights->allow_review
                     && $this->conf->setting("extrev_view") >= 2))
                && ($this->can_view_review_identity($prow, null)
                    || ($crow && $prow->can_view_review_identity_of($crow->commentId, $this))))
            || !$this->conf->is_review_blind(!$crow || ($crow->commentType & COMMENTTYPE_BLIND) != 0);
    }

    function can_view_comment_time(PaperInfo $prow, $crow) {
        return $this->can_view_comment_identity($prow, $crow, true);
    }

    function can_view_comment_tags(PaperInfo $prow, $crow) {
        $rights = $this->rights($prow);
        return $rights->allow_pc || $rights->review_status != 0;
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
            || ($rights->review_status > 0
                && $this->conf->time_reviewer_view_decision());
    }

    function can_view_some_decision() {
        return $this->is_manager()
            || ($this->is_author() && $this->can_view_some_decision_as_author())
            || ($this->isPC && $this->conf->timePCViewDecision(false))
            || ($this->is_reviewer() && $this->conf->time_reviewer_view_decision());
    }

    function can_view_some_decision_as_author() {
        return $this->conf->can_some_author_view_decision();
    }

    static function can_some_author_view_decision(PaperInfo $prow) {
        return $prow->outcome
            && $prow->conf->can_some_author_view_decision();
    }

    function can_set_decision(PaperInfo $prow) {
        return $this->can_administer($prow);
    }

    function can_set_some_decision() {
        return $this->can_administer(null);
    }

    function can_view_formula(Formula $formula, $as_author = false) {
        $bound = $this->permissive_view_score_bound($as_author);
        return $formula->view_score($this) > $bound;
    }

    function can_edit_formula(Formula $formula) {
        return $this->privChair || ($this->isPC && $formula->createdBy > 0);
    }

    // A review field is visible only if its view_score > view_score_bound.
    function view_score_bound(PaperInfo $prow, ReviewInfo $rrow = null) {
        // Returns the maximum view_score for an invisible review
        // field. Values are:
        //   VIEWSCORE_ADMINONLY     admin can view
        //   VIEWSCORE_REVIEWERONLY  ... and review author can view
        //   VIEWSCORE_PC            ... and any PC/reviewer can view
        //   VIEWSCORE_AUTHORDEC     ... and authors can view when decisions visible
        //   VIEWSCORE_AUTHOR        ... and authors can view
        // So returning -3 means all scores are visible.
        // Deadlines are not considered.
        $rights = $this->rights($prow);
        if ($rights->can_administer)
            return VIEWSCORE_ADMINONLY - 1;
        else if ($rrow ? $this->is_owned_review($rrow) : $rights->allow_review)
            return VIEWSCORE_REVIEWERONLY - 1;
        else if (!$this->can_view_review($prow, $rrow))
            return VIEWSCORE_MAX + 1;
        else if ($rights->act_author_view
                 && $prow->outcome
                 && $this->can_view_decision($prow))
            return VIEWSCORE_AUTHORDEC - 1;
        else if ($rights->act_author_view)
            return VIEWSCORE_AUTHOR - 1;
        else
            return VIEWSCORE_PC - 1;
    }

    function permissive_view_score_bound($as_author = false) {
        if (!$as_author && $this->is_manager()) {
            return VIEWSCORE_ADMINONLY - 1;
        } else if (!$as_author && $this->is_reviewer()) {
            return VIEWSCORE_REVIEWERONLY - 1;
        } else if (($as_author || $this->is_author())
                   && ($this->conf->any_response_open
                       || $this->conf->au_seerev != 0)) {
            if ($this->can_view_some_decision_as_author()) {
                return VIEWSCORE_AUTHORDEC - 1;
            } else {
                return VIEWSCORE_AUTHOR - 1;
            }
        } else {
            return VIEWSCORE_MAX + 1;
        }
    }

    function can_view_tags(PaperInfo $prow = null) {
        // see also AllTags_API::alltags
        if (!$prow)
            return $this->isPC;
        $rights = $this->rights($prow);
        return $rights->allow_pc
            || ($rights->allow_pc_broad && $this->conf->tag_seeall)
            || (($this->privChair || $rights->allow_administer)
                && $this->conf->tags()->has_sitewide);
    }

    function can_view_most_tags(PaperInfo $prow = null) {
        if (!$prow)
            return $this->isPC;
        $rights = $this->rights($prow);
        return $rights->allow_pc
            || ($rights->allow_pc_broad && $this->conf->tag_seeall);
    }

    function can_view_hidden_tags(PaperInfo $prow = null) {
        if (!$prow)
            return $this->privChair;
        $rights = $this->rights($prow);
        return $rights->can_administer
            || $this->conf->check_required_tracks($prow, $this, Track::HIDDENTAG);
    }

    function can_view_tag(PaperInfo $prow, $tag) {
        if ($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            return true;
        $rights = $this->rights($prow);
        $tag = TagInfo::base($tag);
        $twiddle = strpos($tag, "~");
        $dt = $this->conf->tags();
        return ($rights->allow_pc
                || ($rights->allow_pc_broad && $this->conf->tag_seeall)
                || ($this->privChair && $dt->is_sitewide($tag)))
            && ($rights->allow_administer
                || $twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0
                    && (substr($tag, 0, $twiddle) == $this->contactId
                        || $dt->is_votish(substr($tag, $twiddle + 1)))))
            && ($twiddle !== false
                || !$dt->has_hidden
                || !$dt->is_hidden($tag)
                || $this->can_view_hidden_tags($prow));
    }

    function can_view_peruser_tags(PaperInfo $prow, $tag) {
        return $this->can_view_tag($prow, ($this->contactId + 1) . "~$tag");
    }

    function can_view_any_peruser_tags($tag) {
        return $this->is_manager()
            || ($this->isPC && $this->conf->tags()->is_votish($tag));
    }

    function can_change_tag(PaperInfo $prow, $tag, $previndex, $index) {
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->is_site_contact)
            return true;
        $rights = $this->rights($prow);
        $tagmap = $this->conf->tags();
        if (!($rights->allow_pc
              && ($rights->can_administer || $this->conf->timePCViewPaper($prow, false)))) {
            if ($this->privChair && $tagmap->has_sitewide) {
                if (!$tag)
                    return true;
                else {
                    $dt = $tagmap->check($tag);
                    return $dt && $dt->sitewide && !$dt->autosearch;
                }
            } else
                return false;
        }
        if (!$tag)
            return true;
        $tag = TagInfo::base($tag);
        $twiddle = strpos($tag, "~");
        if ($twiddle === 0 && $tag[1] === "~") {
            if (!$rights->can_administer)
                return false;
            else if (!$tagmap->has_autosearch)
                return true;
            else {
                $dt = $tagmap->check($tag);
                return !$dt || !$dt->autosearch;
            }
        }
        if ($twiddle > 0
            && substr($tag, 0, $twiddle) != $this->contactId
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
                     || ($t->track && !$this->privChair)
                     || ($t->hidden && !$this->can_view_hidden_tags($prow))
                     || $t->autosearch)
                return false;
            else
                return $rights->can_administer
                    || ($this->privChair && $t->sitewide)
                    || (!$t->readonly && !$t->rank);
        }
    }

    function perm_change_tag(PaperInfo $prow, $tag, $previndex, $index) {
        if ($this->can_change_tag($prow, $tag, $previndex, $index))
            return null;
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        $whyNot["tag"] = $tag;
        if (!$this->isPC)
            $whyNot["permission"] = "change_tag";
        else if ($rights->conflictType > 0) {
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
            if ($twiddle === 0 && $tag[1] === "~")
                $whyNot["chairTag"] = true;
            else if ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId)
                $whyNot["otherTwiddleTag"] = true;
            else if ($twiddle !== false)
                $whyNot["voteTagNegative"] = true;
            else {
                $t = $this->conf->tags()->check($tag);
                if ($t && $t->vote)
                    $whyNot["voteTag"] = true;
                else if ($t && $t->autosearch)
                    $whyNot["autosearchTag"] = true;
                else
                    $whyNot["chairTag"] = true;
            }
        }
        return $whyNot;
    }

    function can_change_some_tag(PaperInfo $prow = null) {
        if (!$prow)
            return $this->isPC;
        else
            return $this->can_change_tag($prow, null, null, null);
    }

    function perm_change_some_tag(PaperInfo $prow) {
        return $this->perm_change_tag($prow, null, null, null);
    }

    function can_change_tag_anno($tag) {
        if ($this->privChair)
            return true;
        $twiddle = strpos($tag, "~");
        $t = $this->conf->tags()->check($tag);
        return $this->isPC
            && (!$t || (!$t->readonly && !$t->hidden))
            && ($twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0 && substr($tag, 0, $twiddle) == $this->contactId));
    }

    function can_view_reviewer_tags(PaperInfo $prow = null) {
        return $this->act_pc($prow);
    }


    function aucollab_matchers() {
        if ($this->_aucollab_matchers === null) {
            $this->_aucollab_matchers = [new AuthorMatcher($this)];
            if ((string) $this->collaborators !== "")
                foreach (explode("\n", $this->collaborators) as $co) {
                    if (($m = AuthorMatcher::make_collaborator_line($co)))
                        $this->_aucollab_matchers[] = $m;
                }
        }
        return $this->_aucollab_matchers;
    }

    function aucollab_general_pregexes() {
        if ($this->_aucollab_general_pregexes === null) {
            $l = [];
            foreach ($this->aucollab_matchers() as $matcher)
                if (($r = $matcher->general_pregexes()))
                    $l[] = $r;
            $this->_aucollab_general_pregexes = Text::merge_pregexes($l);
        }
        return $this->_aucollab_general_pregexes;
    }

    function full_matcher() {
        $this->aucollab_matchers();
        return $this->_aucollab_matchers[0];
    }

    function au_general_pregexes() {
        return $this->full_matcher()->general_pregexes();
    }


    // following / email notifications

    function following_reviews(PaperInfo $prow, $watch) {
        if ($watch & self::WATCH_REVIEW_EXPLICIT)
            return ($watch & self::WATCH_REVIEW) != 0;
        else
            return ($this->defaultWatch & self::WATCH_REVIEW_ALL)
                || (($this->defaultWatch & self::WATCH_REVIEW_MANAGED)
                    && $this->allow_administer($prow))
                || (($this->defaultWatch & self::WATCH_REVIEW)
                    && ($prow->has_author($this)
                        || $prow->has_reviewer($this)
                        || $prow->has_commenter($this)));
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
        if ($sub_reg && (!$sub_update || $sub_reg < $sub_update))
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
        if ($this->conf->setting("resp_active") > 0
            && ($this->isPC || $this->is_author())) {
            $dlresps = [];
            foreach ($this->conf->resp_rounds() as $rrd)
                if ($rrd->open
                    && ($this->isPC || $rrd->open < $Now)
                    && ($this->isPC || !$rrd->search || $rrd->search->filter($this->authored_papers()))) {
                    $dlresp = (object) ["open" => $rrd->open, "done" => +$rrd->done];
                    $dlresps[$rrd->name] = $dlresp;
                    $graces[] = [$dlresp, $rrd->grace];
                }
            if (!empty($dlresps))
                $dl->resps = $dlresps;
        }

        // final copy deadlines
        if ($this->conf->setting("final_open") > 0) {
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
        $rev_open = +$this->conf->setting("rev_open");
        $rev_open = $rev_open > 0 && $rev_open <= $Now;
        if ($this->is_reviewer() && $rev_open)
            $dl->rev = (object) ["open" => true];
        else if ($this->privChair)
            $dl->rev = (object) [];
        if (get($dl, "rev")) {
            $dl->revs = [];
            $k = $this->isPC ? "pcrev" : "extrev";
            foreach ($this->conf->defined_round_list() as $i => $round_name) {
                $isuf = $i ? "_$i" : "";
                $s = +$this->conf->setting("{$k}_soft$isuf");
                $h = +$this->conf->setting("{$k}_hard$isuf");
                $dl->revs[$round_name] = $dlround = (object) [];
                if ($rev_open)
                    $dlround->open = true;
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
                if (get($dl, "resps")) {
                    foreach ($this->conf->resp_rounds() as $rrd) {
                        $crow = CommentInfo::make_response_template($rrd->number, $prow);
                        $v = false;
                        if ($this->can_respond($prow, $crow, true))
                            $v = true;
                        else if ($admin && $this->can_respond($prow, $crow, false))
                            $v = "override";
                        if ($v && !isset($perm->can_respond))
                            $perm->can_responds = [];
                        if ($v)
                            $perm->can_responds[$rrd->name] = $v;
                    }
                }
                if (self::can_some_author_view_submitted_review($prow))
                    $perm->some_author_can_view_review = true;
                if (self::can_some_author_view_decision($prow))
                    $perm->some_author_can_view_decision = true;
                if ($this->isPC
                    && !$this->conf->can_some_external_reviewer_view_comment())
                    $perm->default_comment_visibility = "pc";
                if ($this->_review_tokens) {
                    $tokens = [];
                    foreach ($prow->reviews_by_id() as $rrow) {
                        if ($rrow->reviewToken && in_array($rrow->reviewToken, $this->_review_tokens))
                            $tokens[$rrow->reviewToken] = true;
                    }
                    if (!empty($tokens))
                        $perm->review_tokens = array_map("encode_token", array_keys($tokens));
                }
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


    function setsession_api($v) {
        $ok = true;
        preg_match_all('/(?:\A|\s)(foldpaper[abpt]|foldpscollab|foldhomeactivity|(?:pl|pf|ul)display|scoresort)(|\.[^=]*)(=\S*|)(?=\s|\z)/', $v, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            if ($m[2]) {
                $on = intval(substr($m[3], 1) ? : "0") == 0;
                if ($m[1] === "pldisplay" || $m[1] === "pfdisplay")
                    PaperList::change_display($this, substr($m[1], 0, 2), substr($m[2], 1), $on);
                else if (preg_match('/\A\.[-a-zA-Z0-9_:]+\z/', $m[2]))
                    displayOptionsSet($m[1], substr($m[2], 1), $on);
                else
                    $ok = false;
            } else
                $this->conf->save_session($m[1], $m[3] ? intval(substr($m[3], 1)) : null);
        }
        return $ok;
    }


    // papers

    function paper_set($pids, $options = null) {
        if (is_int($pids)) {
            $options["paperId"] = $pids;
        } else if (is_array($pids)
                   && !is_associative_array($pids)
                   && (!empty($pids) || $options !== null)) {
            $options["paperId"] = $pids;
        } else if (is_object($pids) && $pids instanceof SearchSelection) {
            $options["paperId"] = $pids->selection();
        } else {
            $options = $pids;
        }
        return $this->conf->paper_set($this, $options);
    }

    function hide_reviewer_identity_pids() {
        $pids = [];
        if (!$this->privChair || $this->conf->has_any_manager()) {
            $overrides = $this->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($this->paper_set([]) as $prow) {
                if (!$this->can_view_paper($prow)
                    || !$this->can_view_review_assignment($prow, null)
                    || !$this->can_view_review_identity($prow, null))
                    $pids[] = $prow->paperId;
            }
            $this->set_overrides($overrides);
        }
        return $pids;
    }

    function paper_status_info(PaperInfo $row, $forceShow = null) {
        if ($row->timeWithdrawn > 0) {
            return array("pstat_with", "Withdrawn");
        } else if ($row->outcome && $this->can_view_decision($row, $forceShow)) {
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
        } else if ($row->timeSubmitted <= 0 && $row->paperStorageId == 1) {
            return array("pstat_noup", "No submission");
        } else if ($row->timeSubmitted > 0) {
            return array("pstat_sub", "Submitted");
        } else {
            return array("pstat_prog", "Not ready");
        }
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
        $type = max((int) $type, 0);
        $oldtype = $rrow ? (int) $rrow->reviewType : 0;

        // can't delete a review that's in progress
        if ($type <= 0 && $oldtype && $rrow->reviewModified > 1) {
            if ($oldtype >= REVIEW_SECONDARY)
                $type = REVIEW_PC;
            else
                return $reviewId;
        }
        // PC members always get PC reviews
        if ($type == REVIEW_EXTERNAL && $this->conf->pc_member_by_id($reviewer_cid))
            $type = REVIEW_PC;

        // change database
        if ($type && ($round = get($extra, "round_number")) === null)
            $round = $this->conf->assignment_round($type == REVIEW_EXTERNAL);
        if ($type && !$oldtype) {
            $qa = "";
            if (get($extra, "mark_notify"))
                $qa .= ", timeRequestNotified=$Now";
            if (get($extra, "token"))
                $qa .= $this->unassigned_review_token();
            $new_requester_cid = $this->contactId;
            if (($new_requester = get($extra, "requester_contact")))
                $new_requester_cid = $new_requester->contactId;
            $q = "insert into PaperReview set paperId=$pid, contactId=$reviewer_cid, reviewType=$type, reviewRound=$round, timeRequested=$Now$qa, requestedBy=$new_requester_cid";
        } else if ($type && ($oldtype != $type || $rrow->reviewRound != $round)) {
            $q = "update PaperReview set reviewType=$type, reviewRound=$round";
            if (!$rrow->reviewSubmitted)
                $q .= ", reviewNeedsSubmit=1";
            $q .= " where reviewId=$reviewId";
        } else if (!$type && $oldtype)
            $q = "delete from PaperReview where reviewId=$reviewId";
        else
            return $reviewId;

        if (!($result = $this->conf->qe_raw($q)))
            return false;

        if ($type && !$oldtype) {
            $reviewId = $result->insert_id;
            $msg = "Review $reviewId added (" . ReviewForm::$revtype_names[$type] . ")";
        } else if (!$type) {
            $msg = "Removed " . ReviewForm::$revtype_names[$oldtype] . " review";
            $reviewId = 0;
        } else
            $msg = "Review $reviewId changed (" . ReviewForm::$revtype_names[$oldtype] . " to " . ReviewForm::$revtype_names[$type] . ")";
        $this->conf->log_for($this, $reviewer_cid, $msg, $pid);

        // on new review, update PaperReviewRefused, ReviewRequest, delegation
        if ($type && !$oldtype) {
            $this->conf->ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
            if (($req_email = get($extra, "requested_email")))
                $this->conf->qe("delete from ReviewRequest where paperId=$pid and email=?", $req_email);
            if ($type < REVIEW_SECONDARY)
                $this->update_review_delegation($pid, $new_requester_cid, 1);
            if ($type >= REVIEW_PC
                && $this->conf->setting("pcrev_assigntime", 0) < $Now)
                $this->conf->save_setting("pcrev_assigntime", $Now);
        } else if (!$type) {
            if ($oldtype < REVIEW_SECONDARY && $rrow->requestedBy > 0)
                $this->update_review_delegation($pid, $rrow->requestedBy, -1);
            // Mark rev_tokens setting for future update by update_rev_tokens_setting
            if (get($rrow, "reviewToken"))
                $this->conf->settings["rev_tokens"] = -1;
        } else {
            if ($type == REVIEW_SECONDARY && $oldtype != REVIEW_SECONDARY
                && !$rrow->reviewSubmitted)
                $this->update_review_delegation($pid, $reviewer_cid, 0);
        }
        if ($type == REVIEW_META || $oldtype == REVIEW_META)
            $this->conf->update_metareviews_setting($type == REVIEW_META ? 1 : -1);

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
        return $this->conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=? where paperId=? and reviewId=?", $needsSubmit, $rrow->paperId, $rrow->reviewId);
    }
}
