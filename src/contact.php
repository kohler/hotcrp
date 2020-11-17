<?php
// contact.php -- HotCRP helper class representing system users
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

/** @property ?string $__isAuthor__
 * @property ?string $__hasReview__ */
class Contact {
    /** @var int */
    static public $rights_version = 1;
    /** @var ?Contact */
    static public $guser;
    /** @var bool */
    static public $no_guser = false;
    /** @var ?Contact */
    static public $true_user;
    /** @var bool */
    static public $allow_nonexistent_properties = false;
    /** @var int */
    static public $next_xid = -2;

    /** @var Conf */
    public $conf;

    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $contactDbId = 0;
    /** @var int */
    public $contactXid = 0;
    /** @var int */
    public $cdb_confid = 0;

    /** @var string */
    public $firstName = "";
    /** @var string */
    public $lastName = "";
    /** @var string */
    public $unaccentedName = "";
    /** @var string */
    public $affiliation = "";
    /** @var string */
    public $email = "";
    /** @var int */
    public $roles = 0;
    /** @var ?string */
    public $contactTags;
    /** @var bool|'deleted' */
    public $disabled = false;
    /** @var bool */
    public $_slice = false;

    /** @var ?bool */
    public $nameAmbiguous;
    /** @var string */
    private $_sorter;
    /** @var ?int */
    private $_sortspec;
    /** @var ?int */
    public $sort_position;

    /** @var ?string */
    public $collaborators;
    public $preferredEmail = "";
    /** @var ?string */
    public $country;
    /** @var ?string */
    public $orcid;
    /** @var ?string */
    public $phone;

    public $demoSharing;
    public $demoBirthday;
    public $demoGender;
    public $demoEthnicity;
    public $demoAccess;

    /** @var string */
    private $password = "";
    /** @var int */
    private $passwordTime = 0;
    /** @var int */
    private $passwordUseTime = 0;
    /** @var false|null|Contact */
    private $_contactdb_user = false;

    /** @var ?bool */
    private $_disabled;
    public $activity_at = false;
    private $lastLogin = 0;
    public $creationTime = 0;
    private $updateTime = 0;
    private $data;
    /** @var ?object */
    private $_jdata;
    const WATCH_REVIEW_EXPLICIT = 1;  // only in PaperWatch
    const WATCH_REVIEW = 2;
    const WATCH_REVIEW_ALL = 4;
    const WATCH_REVIEW_MANAGED = 8;
    const WATCH_FINAL_SUBMIT_ALL = 32;
    public $defaultWatch = self::WATCH_REVIEW;

    private $_topic_interest_map;
    private $_name_for_map = [];

    // Roles
    const ROLE_PC = 1;
    const ROLE_ADMIN = 2;
    const ROLE_CHAIR = 4;
    const ROLE_PCLIKE = 15;
    const ROLE_AUTHOR = 16;
    const ROLE_REVIEWER = 32;
    const ROLE_REQUESTER = 64;
    /** @var bool */
    public $isPC = false;
    /** @var bool */
    public $privChair = false;
    /** @var bool */
    public $is_site_contact = false;
    /** @var ?int */
    private $_db_roles;
    /** @var ?int */
    private $_active_roles;
    /** @var ?bool */
    private $_has_outstanding_review;
    /** @var ?bool */
    private $_is_metareviewer;
    /** @var ?bool */
    private $_is_lead;
    /** @var ?bool */
    private $_is_explicit_manager;
    /** @var ?int */
    private $_dangerous_track_mask;
    /** @var ?int */
    private $_has_approvable;
    /** @var ?int */
    private $_can_view_pc;
    /** @var int */
    private $_rights_version = 0;
    /** @var int */
    public $tracker_kiosk_state = 0;
    /** @var ?array<string,mixed> */
    private $_capabilities;
    /** @var ?list<int> */
    private $_review_tokens;
    const OVERRIDE_CONFLICT = 1;
    const OVERRIDE_TIME = 2;
    const OVERRIDE_CHECK_TIME = 4;
    const OVERRIDE_TAG_CHECKS = 8;
    const OVERRIDE_EDIT_CONDITIONS = 16;
    /** @var int */
    private $_overrides = 0;
    /** @var ?array<int,bool> */
    public $hidden_papers;
    /** @var ?array<string,true> */
    private $_author_perm_tags;

    /** @var bool */
    private $_activated = false;

    /** @var ?non-empty-list<AuthorMatcher> */
    private $_aucollab_matchers;
    /** @var ?TextPregexes|false */
    private $_aucollab_general_pregexes;
    /** @var ?list<PaperInfo> */
    private $_authored_papers;

    /** @var ?array */
    private $_mod_undo;

    // Per-paper DB information, usually null
    public $conflictType;
    public $myReviewPermissions;
    public $watch;

    const PROP_LOCAL = 0x01;
    const PROP_CDB = 0x02;
    const PROP_SLICE = 0x04;
    const PROP_DATA = 0x08;
    const PROP_NULL = 0x10;
    const PROP_STRING = 0x20;
    const PROP_INT = 0x40;
    const PROP_BOOL = 0x80;
    const PROP_STRINGLIST = 0x100;
    const PROP_NAME = 0x1000;
    const PROP_PASSWORD = 0x2000;
    const PROP_UPDATE = 0x4000;
    const PROP_IMPORT = 0x8000;
    static public $props = [
        "firstName" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SLICE | self::PROP_NAME | self::PROP_UPDATE | self::PROP_IMPORT,
        "lastName" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SLICE | self::PROP_NAME | self::PROP_UPDATE | self::PROP_IMPORT,
        "email" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SLICE,
        "preferredEmail" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING,
        "affiliation" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SLICE | self::PROP_UPDATE | self::PROP_IMPORT,
        "phone" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE,
        "country" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE | self::PROP_IMPORT,
        "password" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_PASSWORD,
        "passwordTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_PASSWORD,
        "passwordUseTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_PASSWORD,
        "collaborators" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE | self::PROP_IMPORT,
        "creationTime" => self::PROP_LOCAL | self::PROP_INT,
        "updateTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT,
        "lastLogin" => self::PROP_LOCAL | self::PROP_INT,
        "defaultWatch" => self::PROP_LOCAL | self::PROP_INT,
        "roles" => self::PROP_LOCAL | self::PROP_INT | self::PROP_SLICE,
        "disabled" => self::PROP_LOCAL | self::PROP_BOOL | self::PROP_SLICE,
        "contactTags" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING | self::PROP_SLICE,
        "address" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRINGLIST | self::PROP_UPDATE,
        "city" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE,
        "state" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE,
        "zip" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE
    ];


    /** @param ?array<string,mixed> $user */
    function __construct($user = null, Conf $conf = null) {
        $this->conf = $conf ?? Conf::$main;
        if ($user) {
            $this->merge($user);
        } else if ($this->contactId || $this->contactDbId) {
            $this->db_load();
        } else {
            $this->contactXid = self::$next_xid--;
        }
    }

    /** @return ?Contact */
    static function fetch($result, Conf $conf) {
        $user = $result ? $result->fetch_object("Contact", [null, $conf]) : null;
        '@phan-var ?Contact $user';
        if ($user && !$user->conf) {
            $user->conf = $conf;
            $user->db_load();
        }
        return $user;
    }

    /** @param array<string,mixed> $user */
    private function merge($user) {
        if (is_object($user)) {
            $user = (array) $user;
        }
        if ((!isset($user["dsn"]) || $user["dsn"] == $this->conf->dsn)
            && isset($user["contactId"])) {
            $this->contactId = (int) $user["contactId"];
        }
        if (isset($user["contactDbId"])) {
            $this->contactDbId = (int) $user["contactDbId"];
        }
        if (isset($user["cdb_confid"])) {
            $this->cdb_confid = $user["cdb_confid"];
        }
        $this->contactXid = $this->contactId ? : self::$next_xid--;

        // handle slice properties
        if (isset($user["firstName"]) && isset($user["lastName"])) {
            $this->firstName = (string) $user["firstName"];
            $this->lastName = (string) $user["lastName"];
            $this->unaccentedName = isset($user["unaccentedName"])
                ? $user["unaccentedName"]
                : Text::name($this->firstName, $this->lastName, "", NAME_U);
        } else {
            $nameau = Author::make_keyed($user);
            $this->firstName = $nameau->firstName;
            $this->lastName = $nameau->lastName;
            $this->unaccentedName = $nameau->name(NAME_U);
        }

        $this->affiliation = simplify_whitespace((string) ($user["affiliation"] ?? ""));
        $this->email = simplify_whitespace((string) ($user["email"] ?? ""));

        if (isset($user["is_site_contact"])) {
            $this->is_site_contact = $user["is_site_contact"];
        }
        $roles = (int) ($user["roles"] ?? 0);
        if ($user["isPC"] ?? false) {
            $roles |= self::ROLE_PC;
        }
        if ($user["isAssistant"] ?? false) {
            $roles |= self::ROLE_ADMIN;
        }
        if ($user["isChair"] ?? false) {
            $roles |= self::ROLE_CHAIR;
        }
        if ($roles !== 0) {
            $this->assign_roles($roles);
        }

        if (array_key_exists("contactTags", $user)) {
            $this->contactTags = $user["contactTags"];
        } else {
            $this->contactTags = $this->contactId ? false : null;
        }

        if (isset($user["disabled"])) {
            $this->disabled = !!$user["disabled"];
        }

        // handle other properties
        foreach (self::$props as $prop => $shape) {
            if (array_key_exists($prop, $user)
                && ($shape & (self::PROP_SLICE | self::PROP_DATA)) === 0) {
                $this->$prop = $user[$prop];
                if ($this->$prop === null
                    ? ($shape & self::PROP_NULL) === 0
                    : (($shape & self::PROP_STRING) !== 0 && !is_string($this->$prop))
                      || (($shape & self::PROP_INT) !== 0 && !is_int($this->$prop))
                      || (($shape & self::PROP_BOOL) !== 0 && !is_bool($this->$prop))) {
                    error_log("{$this->conf->dbname}: user {$this->email}: bad property $prop " . debug_string_backtrace());
                }
            }
        }
        if (isset($user["activity_at"])) {
            $this->activity_at = (int) $user["activity_at"];
        } else {
            $this->activity_at = $this->lastLogin;
        }
        if (array_key_exists("data", $user)) {
            $this->data = $user["data"];
        }
        $this->_jdata = null;
        $this->_disabled = null;
        $this->_contactdb_user = false;
    }

    private function db_load() {
        $this->contactId = (int) $this->contactId;
        $this->contactDbId = (int) $this->contactDbId;
        $this->cdb_confid = (int) $this->cdb_confid;
        assert($this->contactId > 0 || ($this->contactId === 0 && $this->contactDbId > 0));
        $this->contactXid = $this->contactId ? : self::$next_xid--;

        // handle slice properties
        if ($this->unaccentedName === "") {
            $this->unaccentedName = Text::name($this->firstName, $this->lastName, "", NAME_U);
        }
        if (isset($this->roles)) {
            $this->assign_roles((int) $this->roles);
        }
        if (isset($this->disabled)) {
            $this->disabled = !!$this->disabled;
        }
        $this->_slice = isset($this->_slice) && $this->_slice;

        // handle special role markers
        if (isset($this->__isAuthor__)) {
            $this->_db_roles = ((int) $this->__isAuthor__ > 0 ? self::ROLE_AUTHOR : 0)
                | ((int) $this->__hasReview__ > 0 ? self::ROLE_REVIEWER : 0);
        }

        // handle other properties
        if (!$this->_slice) {
            foreach (self::$props as $prop => $shape) {
                if (($shape & (self::PROP_SLICE | self::PROP_DATA | self::PROP_STRING)) === 0
                    && isset($this->$prop)) {
                    if (($shape & self::PROP_INT) !== 0) {
                        $this->$prop = (int) $this->$prop;
                    } else {
                        assert(($shape & self::PROP_BOOL) !== 0);
                        $this->$prop = (bool) $this->$prop;
                    }
                }
            }
            if (!$this->activity_at && $this->lastLogin) {
                $this->activity_at = $this->lastLogin;
            }
        }

        $this->_jdata = null;
        $this->_disabled = null;
        $this->_contactdb_user = false;
    }

    static function set_guser(Contact $user = null) {
        global $Me;
        Contact::$guser = $Me = $user;
    }


    function unslice_using($x) {
        foreach (self::$props as $prop => $shape) {
            if (($shape & (self::PROP_LOCAL | self::PROP_SLICE | self::PROP_DATA)) === self::PROP_LOCAL) {
                $value = $x->$prop;
                if ($value === null || ($shape & self::PROP_STRING) !== 0) {
                    $this->$prop = $value;
                } else if (($shape & self::PROP_INT) !== 0) {
                    $this->$prop = (int) $value;
                } else {
                    assert(($shape & self::PROP_BOOL) !== 0);
                    $this->$prop = (bool) $value;
                }
            }
            $this->activity_at = $this->lastLogin;
            $this->data = $x->data;
            $this->_jdata = null;
            $this->_slice = false;
        }
    }

    function unslice() {
        if ($this->_slice) {
            assert($this->contactId > 0);
            $need = $this->conf->sliced_users($this);
            $result = $this->conf->qe("select * from ContactInfo where contactId?a", array_keys($need));
            while (($m = $result->fetch_object())) {
                $need[$m->contactId]->unslice_using($m);
            }
            Dbl::free($result);
            $this->_slice = false;
        }
    }

    function __set($name, $value) {
        if (!self::$allow_nonexistent_properties) {
            error_log(caller_landmark(1) . ": writing nonexistent property $name");
        }
        $this->$name = $value;
    }


    /** @return string */
    function collaborators() {
        $this->_slice && $this->unslice();
        return $this->collaborators ?? "";
    }

    /** @return Generator<AuthorMatcher> */
    static function make_collaborator_generator($s) {
        $pos = 0;
        while (($eol = strpos($s, "\n", $pos)) !== false) {
            if ($eol !== $pos
                && ($m = AuthorMatcher::make_collaborator_line(substr($s, $pos, $eol - $pos))) !== null) {
                yield $m;
            }
            $pos = $eol + 1;
        }
        if (strlen($s) !== $pos
            && ($m = AuthorMatcher::make_collaborator_line(substr($s, $pos))) !== null) {
            yield $m;
        }
    }

    /** @return Generator<AuthorMatcher> */
    function collaborator_generator() {
        return self::make_collaborator_generator($this->collaborators());
    }

    /** @return string */
    function country() {
        $this->_slice && $this->unslice();
        return $this->country ?? "";
    }

    /** @return string */
    function orcid() {
        $this->_slice && $this->unslice();
        return $this->orcid ?? "";
    }


    // A sort specification is an integer divided into units of 3 bits.
    // A unit of 1 === first, 2 === last, 3 === email, 4 === affiliation.
    // Least significant bits === most important sort.

    /** @param ?list<string> $args
     * @return int */
    static function parse_sortspec(Conf $conf, $args) {
        $r = $seen = $shift = 0;
        while (!empty($args)) {
            $w = array_shift($args);
            if ($w === "name") {
                array_unshift($args, $conf->sort_by_last ? "first" : "last");
                $w = $conf->sort_by_last ? "last" : "first";
            }
            if ($w === "first" || $w === "firstName") {
                $bit = 1;
            } else if ($w === "last" || $w === "lastName") {
                $bit = 2;
            } else if ($w === "email") {
                $bit = 3;
            } else if ($w === "affiliation") {
                $bit = 4;
            } else {
                $bit = 0;
            }
            if ($bit !== 0 && ($seen & (1 << $bit)) === 0) {
                $seen |= 1 << $bit;
                $r |= $bit << $shift;
                $shift += 3;
            }
        }
        if ($r === 0) { // default
            $r = $conf->sort_by_last ? 0312 : 0321;
        } else if (($seen & 016) === 002) { // first -> first last email
            $r |= 032 << $shift;
        } else if (($seen & 016) === 004) { // last -> first last email
            $r |= 031 << $shift;
        } else if (($seen & 010) === 0) { // always add email
            $r |= 03 << $shift;
        }
        return $r;
    }

    /** @param int $sortspec
     * @return string */
    static function unparse_sortspec($sortspec) {
        if ($sortspec === 0321 || $sortspec === 0312) {
            return $sortspec === 0321 ? "first" : "last";
        } else {
            $r = [];
            while ($sortspec !== 0 && ($sortspec !== 03 || empty($r))) {
                $bit = $sortspec & 7;
                $sortspec >>= 3;
                if ($bit >= 1 && $bit <= 4) {
                    $r[] = (["first", "last", "email", "affiliation"])[$bit - 1];
                }
            }
            return join(" ", $r);
        }
    }

    /** @param Contact|Author $c
     * @param int $sortspec
     * @return string */
    static function make_sorter($c, $sortspec) {
        if (!($c instanceof Contact) && !($c instanceof Author)) {
            error_log(debug_string_backtrace());
        }
        $r = [];
        $first = $c->firstName;
        $von = "";
        while ($sortspec !== 0) {
            if (($sortspec & 077) === 021 && isset($c->unaccentedName)) {
                $r[] = $c->unaccentedName;
                $sortspec >>= 6;
                $first = "";
            } else {
                $bit = $sortspec & 7;
                $sortspec >>= 3;
                if ($bit === 1) {
                    $s = $first . $von;
                    $first = $von = "";
                } else if ($bit === 2) {
                    $s = $c->lastName;
                    if ($first !== "" && ($m = Text::analyze_von($s))) {
                        $s = $m[1];
                        $von = " " . $m[0];
                    }
                } else if ($bit === 3) {
                    $s = $c->email;
                } else if ($bit === 4) {
                    $s = $c->affiliation;
                } else {
                    $s = "";
                }
                if ($s !== "") {
                    $r[] = $s;
                }
            }
        }
        if ($von !== "") {
            $r[] = $von;
        }
        return join(",", $r);
    }

    /** @param Contact|Author $c
     * @param int $sortspec
     * @return string */
    static function get_sorter($c, $sortspec) {
        if ($c instanceof Contact) {
            if ($c->_sortspec !== $sortspec) {
                $c->_sorter = self::make_sorter($c, $sortspec);
                $c->_sortspec = $sortspec;
            }
            return $c->_sorter;
        } else {
            return self::make_sorter($c, $sortspec);
        }
    }

    /** @param int $roles */
    private function assign_roles($roles) {
        $this->roles = $roles;
        $this->isPC = ($roles & self::ROLE_PCLIKE) !== 0;
        $this->privChair = ($roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) !== 0;
    }


    // initialization

    /** @return list<string> */
    static function session_users() {
        if (isset($_SESSION["us"])) {
            return $_SESSION["us"];
        } else if (isset($_SESSION["u"])) {
            return [$_SESSION["u"]];
        } else {
            return [];
        }
    }

    /** @return int */
    static function session_user_index($email) {
        foreach (self::session_users() as $i => $u) {
            if (strcasecmp($u, $email) === 0) {
                return $i;
            }
        }
        return -1;
    }

    /** @return Contact */
    private function actas_user($x) {
        assert(!self::$true_user || self::$true_user === $this);

        // translate to email
        if (is_numeric($x)) {
            $acct = $this->conf->user_by_id($x);
            $email = $acct ? $acct->email : null;
        } else if ($x === "admin") {
            $email = $this->email;
        } else {
            $email = $x;
        }
        if (!$email
            || strcasecmp($email, $this->email) === 0
            || !$this->privChair) {
            return $this;
        }

        // new account must exist
        $u = $this->conf->user_by_email($email);
        if (!$u
            && validate_email($email)
            && $this->conf->opt("debugShowSensitiveEmail")) {
            $u = Contact::create($this->conf, null, ["email" => $email]);
        }
        if (!$u) {
            return $this;
        }

        // cannot turn into a manager of conflicted papers
        if ($this->conf->setting("papermanager")) {
            $result = $this->conf->qe("select paperId from Paper join PaperConflict using (paperId) where managerContactId!=0 and managerContactId!=? and PaperConflict.contactId=? and conflictType>" . CONFLICT_MAXUNCONFLICTED, $this->contactXid, $this->contactXid);
            while (($row = $result->fetch_row())) {
                $u->hidden_papers[(int) $row[0]] = false;
            }
            Dbl::free($result);
        }

        // otherwise ok
        return $u;
    }

    /** @return Contact */
    function activate($qreq, $signin = false) {
        $this->_activated = true;

        // Handle actas requests
        if ($qreq && $qreq->actas && $signin && $this->email) {
            $actas = $qreq->actas;
            unset($qreq->actas, $_GET["actas"], $_POST["actas"]);
            $actascontact = $this->actas_user($actas);
            if ($actascontact !== $this) {
                Conf::$hoturl_defaults["actas"] = urlencode($actascontact->email);
                $_SESSION["last_actas"] = $actascontact->email;
                self::$true_user = $this;
                return $actascontact->activate($qreq, true);
            }
        }

        // Handle invalidate-caches requests
        if ($qreq && $qreq->invalidatecaches && $this->privChair) {
            unset($qreq->invalidatecaches);
            $this->conf->invalidate_caches();
        }

        // Add capabilities from session and request
        if ($qreq && isset($qreq->cap)) {
            $this->apply_capability_text($qreq->cap);
            unset($qreq->cap, $_GET["cap"], $_POST["cap"]);
        }

        // Add review tokens from session
        if (($rtokens = $this->session("rev_tokens"))) {
            foreach ($rtokens as $t) {
                $this->_review_tokens[] = (int) $t;
            }
            ++self::$rights_version;
        }

        // Maybe auto-create a user
        if (!self::$true_user && $this->email) {
            $trueuser_aucheck = $this->session("trueuser_author_check", 0);
            if (!$this->has_account_here()
                && $trueuser_aucheck + 600 < Conf::$now) {
                $this->save_session("trueuser_author_check", Conf::$now);
                $aupapers = self::email_authored_papers($this->conf, $this->email, $this);
                if (!empty($aupapers)) {
                    $this->activate_database_account();
                }
            }
            if ($this->has_account_here()
                && $trueuser_aucheck) {
                foreach ($_SESSION as $k => $v) {
                    if (is_array($v)
                        && isset($v["trueuser_author_check"])
                        && $v["trueuser_author_check"] + 600 < Conf::$now)
                        unset($_SESSION[$k]["trueuser_author_check"]);
                }
            }
        }

        // Maybe set up the shared contacts database
        if ($this->conf->opt("contactdb_dsn")
            && $this->has_account_here()
            && $this->session("contactdb_roles", 0) != $this->contactdb_roles()) {
            if ($this->contactdb_update())
                $this->save_session("contactdb_roles", $this->contactdb_roles());
        }

        // Check forceShow
        $this->_overrides = 0;
        if ($qreq && $qreq->forceShow && $this->is_manager()) {
            $this->_overrides |= self::OVERRIDE_CONFLICT;
        }
        if ($qreq && $qreq->override) {
            $this->_overrides |= self::OVERRIDE_TIME;
        }

        return $this;
    }

    /** @return int */
    function overrides() {
        return $this->_overrides;
    }

    /** @param int $overrides
     * @return int */
    function set_overrides($overrides) {
        $old_overrides = $this->_overrides;
        if (($overrides & self::OVERRIDE_CONFLICT) && !$this->is_manager()) {
            $overrides &= ~self::OVERRIDE_CONFLICT;
        }
        $this->_overrides = $overrides;
        return $old_overrides;
    }

    /** @param int $overrides
     * @return int */
    function add_overrides($overrides) {
        return $this->set_overrides($this->_overrides | $overrides);
    }

    /** @param int $overrides
     * @return int */
    function remove_overrides($overrides) {
        return $this->set_overrides($this->_overrides & ~$overrides);
    }

    /** @param int $overrides
     * @param string $method */
    function call_with_overrides($overrides, $method /* , arguments... */) {
        $old_overrides = $this->set_overrides($overrides);
        $result = call_user_func_array([$this, $method], array_slice(func_get_args(), 2));
        $this->_overrides = $old_overrides;
        return $result;
    }

    function activate_database_account() {
        assert($this->has_email());
        if (!$this->has_account_here()
            && ($u = Contact::create($this->conf, null, $this))) {
            $this->merge(get_object_vars($u));
            $this->contactDbId = 0;
            $this->_contactdb_user = false;
            $this->activate(null);
        }
    }

    /** @return ?Contact */
    function contactdb_user($refresh = false) {
        if ($this->contactDbId && $this->contactId <= 0) {
            return $this;
        } else {
            $u = $this->_contactdb_user;
            if ($u === false || $refresh) {
                $u = $this->_contactdb_user = $this->conf->contactdb_user_by_email($this->email);
                if ($u && $this->contactId > 0) {
                    $u->contactXid = $this->contactId;
                }
            }
            return $u;
        }
    }

    /** @return ?Contact */
    function ensure_contactdb_user($refresh = false) {
        assert($this->has_email());
        if ($this->contactDbId && $this->contactId <= 0) {
            return $this;
        } else {
            $u = $this->_contactdb_user;
            if ($u === false || $refresh) {
                $u = $this->_contactdb_user = $this->conf->contactdb_user_by_email($this->email);
            }
            if ($u === null
                && $this->conf->contactdb()
                && $this->has_email()
                && !self::is_anonymous_email($this->email)) {
                $u = $this->_contactdb_user = new Contact(["email" => $this->email, "cdb_confid" => -1]);
            }
            if ($u && $this->contactId > 0) {
                $u->contactXid = $this->contactId;
            }
            return $u;
        }
    }

    /** @param iterable<Contact> $users */
    static function ensure_contactdb_users(Conf $conf, $users) {
        if (($cdb = $conf->contactdb())) {
            $emails = [];
            foreach ($users as $user) {
                if ($user->has_email()
                    && !self::is_anonymous_email($user->email)
                    && $user->_contactdb_user === false) {
                    $emails[strtolower($user->email)] = null;
                }
            }
            if (!empty($emails)) {
                $result = $conf->contactdb_user_result("ContactInfo.email?a", array_keys($emails));
                while (($cdbu = Contact::fetch($result, $conf))) {
                    $emails[strtolower($cdbu->email)] = $cdbu;
                }
                Dbl::free($result);
                foreach ($users as $user) {
                    $lemail = strtolower($user->email);
                    if (array_key_exists($lemail, $emails)) {
                        $user->_contactdb_user = $emails[$lemail];
                    }
                }
            }
        }
    }

    /** @param Contact $cdbu */
    private function _contactdb_save_roles($cdbu) {
        if ($cdbu->cdb_confid > 0) {
            if (($roles = $this->contactdb_roles())) {
                Dbl::ql($this->conf->contactdb(), "insert into Roles set contactDbId=?, confid=?, roles=?, activity_at=? on duplicate key update roles=values(roles), activity_at=values(activity_at)", $cdbu->contactDbId, $cdbu->cdb_confid, $roles, Conf::$now);
            } else {
                Dbl::ql($this->conf->contactdb(), "delete from Roles where contactDbId=? and confid=? and roles=0", $cdbu->contactDbId, $cdbu->cdb_confid);
            }
        }
    }

    /** @return int|false */
    function contactdb_update() {
        if (!($cdb = $this->conf->contactdb())
            || !$this->has_account_here()
            || !validate_email($this->email)) {
            return false;
        }

        $cdbur = $this->conf->contactdb_user_by_email($this->email);
        $cdbux = $cdbur ?? new Contact(["email" => $this->email, "cdb_confid" => -1], $this->conf);
        foreach (self::$props as $prop => $shape) {
            if (($shape & self::PROP_CDB) !== 0
                && ($shape & self::PROP_PASSWORD) === 0
                && ($value = $this->prop1($prop, $shape)) !== null
                && $value !== ""
                && $prop !== "updateTime") {
                $cdbux->set_prop($prop, $value, true);
            }
        }
        if (!$cdbur && str_starts_with($this->password, " ")) {
            $cdbux->set_prop("password", $this->password);
            $cdbux->set_prop("passwordTime", $this->passwordTime);
            $cdbux->set_prop("passwordUseTime", $this->passwordUseTime);
        }
        if (!empty($cdbux->_mod_undo)) {
            assert($cdbux->cdb_confid !== 0);
            $cdbux->save_prop();
            $this->_contactdb_user = false;
        }
        $cdbur = $cdbur ?? $this->conf->contactdb_user_by_email($this->email);
        if ($cdbur && $cdbur->roles !== $this->contactdb_roles()) {
            $this->_contactdb_save_roles($cdbur);
        }
        return $cdbur ? $cdbur->contactDbId : false;
    }


    /** @param string $name */
    function session($name, $defval = null) {
        return $this->conf->session($name, $defval);
    }

    /** @param string $name */
    function save_session($name, $value) {
        $this->conf->save_session($name, $value);
    }


    /** @return bool */
    function is_activated() {
        return $this->_activated;
    }

    /** @return bool */
    function is_actas_user() {
        return $this->_activated && self::$true_user;
    }

    /** @return bool */
    function is_empty() {
        return $this->contactId <= 0 && !$this->email && !$this->_capabilities;
    }

    /** @return bool */
    function owns_email($email) {
        return (string) $email !== "" && strcasecmp($email, $this->email) === 0;
    }

    /** @return bool */
    function is_disabled() {
        if ($this->_disabled === null) {
            $this->_disabled = $this->disabled
                || (!$this->isPC && $this->conf->opt("disableNonPC"));
        }
        return $this->_disabled;
    }

    /** @return bool */
    function contactdb_disabled() {
        $cdbu = $this->contactdb_user();
        return $cdbu && $cdbu->disabled;
    }

    /** @param int $flags
     * @return string */
    function name($flags = 0) {
        if (($flags & NAME_S) !== 0 && $this->conf->sort_by_last) {
            $flags |= NAME_L;
        }
        if (($flags & NAME_P) !== 0 && $this->nameAmbiguous) {
            $flags |= NAME_E;
        }
        $name = Text::name($this->firstName, $this->lastName, $this->email, $flags);
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation($name, $this->affiliation, $flags);
        }
        return $name;
    }

    /** @param int $flags
     * @return string */
    function name_h($flags = 0) {
        $name = htmlspecialchars($this->name($flags & ~NAME_A));
        if (($flags & NAME_A) !== 0 && $this->affiliation !== "") {
            $name = Text::add_affiliation_h($name, $this->affiliation, $flags);
        }
        return $name;
    }

    /** @return object */
    function unparse_nae_json() {
        return Author::unparse_nae_json_for($this);
    }

    /** @return array<string,1|2> */
    function completion_items() {
        $items = [];

        $x = strtolower(substr($this->email, 0, strpos($this->email, "@")));
        if ($x !== "") {
            $items[$x] = 2;
        }

        $sp = strpos($this->firstName, " ") ? : strlen($this->firstName);
        $x = strtolower(UnicodeHelper::deaccent(substr($this->firstName, 0, $sp)));
        if ($x !== "" && ctype_alnum($x)) {
            $items[$x] = 1;
        }

        $sp = strrpos($this->lastName, " ");
        $x = strtolower(UnicodeHelper::deaccent(substr($this->lastName, $sp ? $sp + 1 : 0)));
        if ($x !== "" && ctype_alnum($x)) {
            $items[$x] = 1;
        }

        return $items;
    }

    private function calculate_name_for($pfx, $user) {
        if ($pfx === "u") {
            return $user;
        } else if ($pfx === "t") {
            return Text::nameo($user, NAME_P);
        }
        $n = htmlspecialchars(Text::nameo($user, NAME_P));
        if ($pfx === "r"
            && isset($user->contactTags)
            && ($this->can_view_user_tags() || $user->contactId === $this->contactXid)) {
            $dt = $this->conf->tags();
            if (($viewable = $dt->censor(TagMap::CENSOR_VIEW, $user->contactTags, $this, null))) {
                if (($colors = $dt->color_classes($viewable))) {
                    $n = '<span class="' . $colors . ' taghh">' . $n . '</span>';
                }
                if ($dt->has_decoration) {
                    $tagger = new Tagger($this);
                    $n .= $tagger->unparse_decoration_html($viewable, Tagger::DECOR_USER);
                }
            }
        }
        return $n;
    }

    /** @param Contact|ReviewInfo|int $x
     * @return mixed */
    private function name_for($pfx, $x) {
        $cid = is_object($x) ? (int) $x->contactId : (int) $x;

        $key = $pfx . $cid;
        if (isset($this->_name_for_map[$key])) {
            return $this->_name_for_map[$key];
        }

        if ($cid === $this->contactId) {
            $x = $this;
        }

        if (!is_object($x) || !isset($x->firstName)) {
            if ($pfx === "u") {
                $x = $this->conf->cached_user_by_id($cid);
            } else {
                $x = $this->name_for("u", $cid);
            }
        }

        if (!$x) {
            return $pfx === "u" ? null : "";
        }

        if ($x
            && $pfx === "r"
            && $this->can_view_user_tags()
            && !isset($x->contactTags)
            && ($pc = $this->conf->pc_member_by_id($cid))) {
            $x = $pc;
        }

        $res = $this->calculate_name_for($pfx, $x);
        $this->_name_for_map[$key] = $res;
        return $res;
    }

    /** @param Contact|ReviewInfo|int $x
     * @return string */
    function name_html_for($x) {
        return $this->name_for("", $x);
    }

    /** @param Contact|ReviewInfo|int $x
     * @return string */
    function name_text_for($x) {
        return $this->name_for("t", $x);
    }

    /** @param Contact|ReviewInfo|int $x
     * @return Contact|Author */
    function name_object_for($x) {
        return $this->name_for("u", $x);
    }

    /** @param Contact|ReviewInfo|int $x
     * @return string */
    function reviewer_html_for($x) {
        return $this->name_for($this->isPC ? "r" : "", $x);
    }

    /** @param Contact|ReviewInfo|int $x
     * @return string */
    function reviewer_text_for($x) {
        return $this->name_for("t", $x);
    }

    function ksort_cid_array(&$a) {
        $pcm = $this->conf->pc_members();
        uksort($a, function ($a, $b) use ($pcm) {
            if (isset($pcm[$a]) && isset($pcm[$b])) {
                return $pcm[$a]->sort_position - $pcm[$b]->sort_position;
            }
            $au = $pcm[$a] ?? $this->conf->cached_user_by_id($a);
            $bu = $pcm[$b] ?? $this->conf->cached_user_by_id($b);
            if ($au && $bu) {
                return call_user_func($this->conf->user_comparator(), $au, $bu);
            } else if ($au || $bu) {
                return $au ? -1 : 1;
            } else {
                return $a - $b;
            }
        });
    }

    /** @return bool */
    function has_email() {
        return !!$this->email;
    }

    /** @return bool */
    static function is_anonymous_email($email) {
        // see also PaperSearch, Mailer
        return substr_compare($email, "anonymous", 0, 9, true) === 0
            && (strlen($email) === 9 || ctype_digit(substr($email, 9)));
    }

    /** @return bool */
    function is_anonymous_user() {
        return $this->email && self::is_anonymous_email($this->email);
    }

    /** @return bool */
    function is_signed_in() {
        return $this->email && $this->_activated;
    }

    /** @return bool */
    function has_account_here() {
        return $this->contactId > 0;
    }

    /** @return bool */
    function is_root_user() {
        return $this->is_site_contact;
    }

    /** @return bool */
    function is_admin() {
        return $this->privChair;
    }

    /** @return bool */
    function is_admin_force() {
        return ($this->_overrides & self::OVERRIDE_CONFLICT) !== 0;
    }

    /** @return bool */
    function is_pc_member() {
        return ($this->roles & self::ROLE_PC) !== 0;
    }

    /** @return bool */
    function is_pclike() {
        return ($this->roles & self::ROLE_PCLIKE) !== 0;
    }

    /** @return int */
    function viewable_pc_roles(Contact $viewer) {
        if (($this->roles & Contact::ROLE_PCLIKE)
            && $viewer->can_view_pc()) {
            $roles = $this->roles & Contact::ROLE_PCLIKE;
            if (!$viewer->isPC) {
                $roles &= ~Contact::ROLE_ADMIN;
            }
            return $roles;
        } else {
            return 0;
        }
    }

    /** @param int $roles
     * @return string */
    static function role_html_for($roles) {
        if ($roles & (Contact::ROLE_CHAIR | Contact::ROLE_ADMIN | Contact::ROLE_PC)) {
            if ($roles & Contact::ROLE_CHAIR) {
                return '<span class="pcrole">chair</span>';
            } else if (($roles & (Contact::ROLE_ADMIN | Contact::ROLE_PC)) === (Contact::ROLE_ADMIN | Contact::ROLE_PC)) {
                return '<span class="pcrole">PC, sysadmin</span>';
            } else if ($roles & Contact::ROLE_ADMIN) {
                return '<span class="pcrole">sysadmin</span>';
            } else {
                return '<span class="pcrole">PC</span>';
            }
        } else {
            return "";
        }
    }

    /** @param string $t
     * @return bool */
    function has_tag($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0) {
            return true;
        }
        if ($this->contactTags) {
            return stripos($this->contactTags, " $t#") !== false;
        }
        if ($this->contactTags === false) {
            trigger_error("Contact $this->email contactTags missing\n" . debug_string_backtrace());
            $this->contactTags = null;
        }
        return false;
    }

    /** @param string $perm
     * @return bool */
    function has_permission($perm) {
        return !$perm || $this->has_tag(substr($perm, 1)) === ($perm[0] === "+");
    }

    /** @param string $t
     * @return ?float */
    function tag_value($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0) {
            return 0.0;
        } else if ($this->contactTags
                   && ($p = stripos($this->contactTags, " $t#")) !== false) {
            return (float) substr($this->contactTags, $p + strlen($t) + 2);
        } else {
            return null;
        }
    }

    /** @param int $roles
     * @param string $tags
     * @return string */
    static function roles_all_contact_tags($roles, $tags) {
        if ($roles & self::ROLE_PC) {
            return " pc#0" . $tags;
        } else {
            return $tags;
        }
    }

    function all_contact_tags() {
        return self::roles_all_contact_tags($this->roles, $this->contactTags);
    }

    function viewable_tags(Contact $viewer) {
        // see also Contact::calculate_name_for
        if ($viewer->can_view_user_tags() || $viewer->contactXid === $this->contactXid) {
            $tags = $this->all_contact_tags();
            return $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $tags, $viewer, null);
        } else {
            return "";
        }
    }

    function viewable_color_classes(Contact $viewer) {
        if (($tags = $this->viewable_tags($viewer))) {
            return $this->conf->tags()->color_classes($tags);
        } else {
            return "";
        }
    }


    /** @return object */
    private function make_data() {
        $this->_slice && $this->unslice();
        if ($this->_jdata === null) {
            if (is_string($this->data)) {
                $x = json_decode($this->data);
            } else if (is_array($this->data)) {
                $x = array_to_object_recursive($this->data);
            } else {
                $x = null;
            }
            $this->_jdata = is_object($x) ? $x : (object) [];
        }
        return $this->_jdata;
    }

    /** @param ?string $key */
    function data($key = null) {
        $d = $this->make_data();
        if ($key) {
            return $d->$key ?? null;
        } else {
            return $d;
        }
    }

    /** @param ?string $key */
    function set_data($key, $value) {
        $d = $this->make_data();
        if (($d->$key ?? null) !== $value) {
            if (!array_key_exists("data", $this->_mod_undo ?? [])) {
                $this->_mod_undo["data"] = $this->data;
            }
            if ($value !== null) {
                $d->$key = $value;
            } else {
                unset($d->$key);
            }
        }
    }

    /** @return ?string */
    private function encode_data() {
        $t = json_encode_db($this->make_data());
        return $t !== "{}" ? $t : null;
    }

    /** @param string $key
     * @param mixed $value */
    function save_data($key, $value) {
        $this->merge_and_save_data((object) [$key => array_to_object_recursive($value)]);
    }

    /** @param object|array $data */
    function merge_data($data) {
        object_replace_recursive($this->make_data(), array_to_object_recursive($data));
    }

    /** @param object|array $data */
    function merge_and_save_data($data) {
        $cdb = $this->contactDbId && $this->contactId <= 0;
        $key = $cdb ? "contactDbId" : "contactId";
        $cid = $cdb ? $this->contactDbId : $this->contactId;
        $change = array_to_object_recursive($data);
        assert($cid > 0);
        Dbl::compare_and_swap(
            $cdb ? $this->conf->contactdb() : $this->conf->dblink,
            "select `data` from ContactInfo where $key=?", [$cid],
            function ($old) use ($change) {
                $this->data = $old;
                $this->_jdata = null;
                object_replace_recursive($this->make_data(), $change);
                return $this->encode_data();
            },
            "update ContactInfo set data=?{desired} where $key=? and data?{expected}e", [$cid]
        );
    }

    /** @return ?string */
    function data_str() {
        $this->_slice && $this->unslice();
        if ($this->_jdata === null
            && ($this->data === null || is_string($this->data))) {
            return $this->data === "{}" ? null : $this->data;
        } else {
            return $this->encode_data();
        }
    }


    /** @return bool */
    function has_capability() {
        return $this->_capabilities !== null;
    }

    /** @param string $name
     * @return mixed */
    function capability($name) {
        return $this->_capabilities ? $this->_capabilities[$name] ?? null : null;
    }

    /** @param int $pid
     * @return bool */
    function has_capability_for($pid) {
        return $this->_capabilities !== null
            && (isset($this->_capabilities["@av{$pid}"])
                || isset($this->_capabilities["@ra{$pid}"]));
    }

    /** @return bool */
    function has_author_view_capability() {
        return $this->_capabilities !== null && $this->author_view_capability_paper_ids();
    }

    /** @return list<int> */
    function author_view_capability_paper_ids() {
        $pids = [];
        foreach ($this->_capabilities ?? [] as $k => $v) {
            if (str_starts_with($k, "@av") && ctype_digit(substr($k, 3))) {
                $pids[] = (int) substr($k, 3);
            }
        }
        return $pids;
    }

    /** @param int $pid
     * @return ?Contact */
    function reviewer_capability_user($pid) {
        if ($this->_capabilities !== null
            && ($rcid = $this->_capabilities["@ra{$pid}"] ?? null)) {
            return $this->conf->cached_user_by_id($rcid);
        } else {
            return null;
        }
    }

    /** @param string $name
     * @param mixed $value
     * @return bool */
    function set_capability($name, $value) {
        $oldval = $this->capability($name);
        if (($value ? : null) !== $oldval) {
            if ($value) {
                $this->_capabilities[$name] = $value;
            } else {
                unset($this->_capabilities[$name]);
                if (empty($this->_capabilities)) {
                    $this->_capabilities = null;
                }
            }
            $this->update_my_rights();
            return true;
        } else {
            return false;
        }
    }

    /** @param string $text */
    function apply_capability_text($text) {
        // Add capabilities from arguments
        foreach (preg_split('/\s+/', $text) as $s) {
            if ($s !== "" && ($uf = $this->conf->capability_handler($s))) {
                call_user_func($uf->callback, $this, $uf, $s);
            }
        }
    }


    function escape($qreq = null) {
        global $Qreq;
        $qreq = $qreq ? : $Qreq;

        if ($qreq->ajax) {
            if ($this->is_empty()) {
                json_exit(["ok" => false, "error" => "You have been signed out.", "loggedout" => true]);
            } else if (!$this->is_signed_in()) {
                json_exit(["ok" => false, "error" => "You must sign in to access that function.", "loggedout" => true]);
            } else {
                json_exit(["ok" => false, "error" => "You don’t have permission to access that page."]);
            }
        }

        if (!$this->is_signed_in()) {
            // Preserve post values across session expiration.
            ensure_session();
            $x = [];
            if (($path = Navigation::path())) {
                $x["__PATH__"] = preg_replace('/^\/+/', "", $path);
            }
            if ($qreq->anchor) {
                $x["anchor"] = $qreq->anchor;
            }
            $url = $this->conf->selfurl($qreq, $x, Conf::HOTURL_RAW | Conf::HOTURL_SITE_RELATIVE);
            $_SESSION["login_bounce"] = [$this->conf->dsn, $url, Navigation::page(), $_POST, Conf::$now + 120];
            if ($qreq->post_ok()) {
                $this->conf->errorMsg("You must sign in to access that page. Your changes were not saved; after signing in, you may submit them again.");
            } else {
                $this->conf->errorMsg("You must sign in to access that page.");
            }
        } else {
            $this->conf->errorMsg("You don’t have permission to access that page.");
        }
        $this->conf->redirect();
    }


    const SAVE_ANY_EMAIL = 1;
    const SAVE_IMPORT = 2;
    const SAVE_ROLES = 4;

    function change_email($email) {
        assert($this->has_account_here());
        $old_email = $this->email;
        $aupapers = self::email_authored_papers($this->conf, $email, $this);
        $this->conf->ql("update ContactInfo set email=? where contactId=?", $email, $this->contactId);
        $this->save_authored_papers($aupapers);

        if (!$this->password
            && ($cdbu = $this->contactdb_user())
            && $cdbu->password) {
            $this->password = $cdbu->password;
        }
        $this->email = $email;
        $this->contactdb_update();

        if ($this->roles & Contact::ROLE_PCLIKE) {
            $this->conf->invalidate_caches(["pc" => 1]);
        }
        $this->conf->log_for($this, $this, "Account edited: email ($old_email to $email)");
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
            $this->conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values ?v on duplicate key update conflictType=(conflictType|" . CONFLICT_AUTHOR . ")", array_map(function ($pid) {
                return [$pid, $this->contactId, CONFLICT_AUTHOR];
            }, $aupapers));
        }
    }


    /** @param string $prop
     * @param int $shape */
    private function prop1($prop, $shape) {
        if ($this->_slice && ($shape & self::PROP_SLICE) === 0) {
            $this->unslice();
        }
        if (($shape & self::PROP_DATA) !== 0) {
            return $this->data($prop);
        } else {
            return $this->$prop;
        }
    }

    /** @param string $prop */
    function prop($prop) {
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop $prop");
        }
        return $this->prop1($prop, $shape);
    }

    /** @param string $prop */
    function gprop($prop) {
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop $prop");
        }
        $value = $this->prop1($prop, $shape);
        if ($value === null || ($value === "" && ($shape & self::PROP_NULL) !== 0)) {
            if (($shape & self::PROP_CDB) !== 0
                && ($cdbu = $this->contactdb_user())
                && $cdbu !== $this
                && (($shape & self::PROP_NAME) === 0
                    || ($this->firstName === "" && $this->lastName === ""))) {
                $value = $cdbu->prop1($prop, $shape);
            }
        }
        return $value;
    }

    /** @param string $prop
     * @param mixed $value
     * @return bool */
    function set_prop($prop, $value, $ifempty = false) {
        // validate argument
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop $prop");
        }
        if (($value !== null || ($shape & self::PROP_NULL) === 0)
            && ((($shape & self::PROP_INT) !== 0 && !is_int($value))
                || (($shape & self::PROP_BOOL) !== 0 && !is_bool($value))
                || (($shape & self::PROP_STRING) !== 0 && !is_string($value))
                || (($shape & self::PROP_STRINGLIST) !== 0 && !is_string_list($value)))) {
            throw new Exception("bad prop type $prop");
        }
        // check if property applies here
        if (($shape & ($this->cdb_confid !== 0 ? self::PROP_CDB : self::PROP_LOCAL)) === 0) {
            return false;
        }
        // check ifempty update
        $old = $this->prop1($prop, $shape);
        if ($ifempty) {
            if ($old !== null && $old !== "") {
                return false;
            } else if (($shape & self::PROP_NAME) !== 0) {
                $prop2 = $prop === "firstName" ? "lastName" : "firstName";
                $old2 = $this->_mod_undo[$prop2] ?? $this->$prop2;
                if ($old2 !== null && $old2 !== "") {
                    return false;
                }
            }
        }
        // check for no change
        if ($value === "" && ($shape & self::PROP_NULL) !== 0) {
            $value = null;
        }
        if ($old === $value
            && ($value !== null
                || ($this->cdb_confid !== 0 ? $this->contactDbId : $this->contactId))) {
            return false;
        }
        // save
        if (($shape & self::PROP_DATA) !== 0) {
            $this->set_data($prop, $value);
        } else {
            if (!array_key_exists($prop, $this->_mod_undo ?? [])) {
                $this->_mod_undo[$prop] = $old;
            }
            $this->$prop = $value;
        }
        if (($shape & self::PROP_UPDATE) !== 0) {
            if (!array_key_exists("updateTime", $this->_mod_undo)) {
                $this->_mod_undo["updateTime"] = $this->updateTime;
            }
            $this->updateTime = Conf::$now;
        }
        return true;
    }

    /** @return bool */
    function prop_changed() {
        return !empty($this->_mod_undo);
    }

    /** @return bool */
    function save_prop() {
        if (empty($this->_mod_undo)) {
            return true;
        } else if ($this->cdb_confid !== 0) {
            $db = $this->conf->contactdb();
            $idk = "contactDbId";
            $flag = self::PROP_CDB;
        } else {
            $db = $this->conf->dblink;
            $idk = "contactId";
            $flag = self::PROP_LOCAL;
        }
        if (!$this->$idk) {
            if (!array_key_exists("password", $this->_mod_undo)) {
                $this->password = validate_email($this->email) ? " unset" : " nologin";
                $this->passwordTime = Conf::$now;
            }
        }
        $qf = $qv = [];
        foreach (self::$props as $prop => $shape) {
            if (array_key_exists($prop, $this->_mod_undo)
                || (!$this->$idk
                    && ($shape & $flag) !== 0
                    && ($shape & self::PROP_NULL) === 0)) {
                $qf[] = "{$prop}=?";
                $value = $this->prop1($prop, $shape);
                if ($value === false || $value === true) {
                    $qv[] = (int) $value;
                } else {
                    $qv[] = $value;
                }
            }
        }
        if (array_key_exists("data", $this->_mod_undo)) {
            $qf[] = "`data`=?";
            $qv[] = $this->data = $this->encode_data();
        }
        if ((array_key_exists("firstName", $this->_mod_undo)
             || array_key_exists("lastName", $this->_mod_undo))
            && $this->cdb_confid === 0) {
            $qf[] = "unaccentedName=?";
            $qv[] = Text::name($this->firstName, $this->lastName, "", NAME_U);
        }
        if ($this->$idk) {
            $qv[] = $this->$idk;
            $result = Dbl::qe_apply($db, "update ContactInfo set " . join(", ", $qf) . " where {$idk}=?", $qv);
        } else {
            assert($this->email !== "");
            $result = Dbl::qe_apply($db, "insert into ContactInfo set " . join(", ", $qf) . " on duplicate key update firstName=firstName", $qv);
            if ($result->affected_rows) {
                $this->$idk = (int) $result->insert_id;
                if ($this->cdb_confid === 0) {
                    $this->contactXid = (int) $result->insert_id;
                }
            }
        }
        $ok = !Dbl::is_error($result);
        Dbl::free($result);
        if ($ok) {
            // invalidate caches
            $this->_disabled = null;
            $this->_mod_undo = null;
        } else {
            error_log("{$this->conf->dbname}: save {$this->email} fails " . debug_string_backtrace());
        }
        return $ok;
    }

    function abort_prop() {
        foreach ($this->_mod_undo as $prop => $value) {
            $this->$prop = $value;
        }
        $this->_mod_undo = $this->_disabled = $this->_jdata = null;
    }


    /** @param int $new_roles
     * @param ?Contact $actor
     * @return bool */
    function save_roles($new_roles, $actor) {
        $old_roles = $this->roles;
        // ensure there's at least one system administrator
        if (!($new_roles & self::ROLE_ADMIN)
            && ($old_roles & self::ROLE_ADMIN)
            && !$this->conf->fetch_ivalue("select contactId from ContactInfo where roles!=0 and (roles&" . self::ROLE_ADMIN . ")!=0 and contactId!=" . $this->contactId . " limit 1")) {
            $new_roles |= self::ROLE_ADMIN;
        }
        // log role change
        foreach ([self::ROLE_PC => "pc", self::ROLE_ADMIN => "sysadmin", self::ROLE_CHAIR => "chair"]
                 as $role => $type) {
            if (($new_roles & $role) && !($old_roles & $role)) {
                $this->conf->log_for($actor ? : $this, $this, "Added as $type");
            } else if (!($new_roles & $role) && ($old_roles & $role)) {
                $this->conf->log_for($actor ? : $this, $this, "Removed as $type");
            }
        }
        // save the roles bits
        if ($old_roles !== $new_roles) {
            $this->conf->qe("update ContactInfo set roles=$new_roles where contactId=$this->contactId");
            $this->assign_roles($new_roles);
            $this->conf->invalidate_caches(["pc" => true]);
        }
        return $old_roles !== $new_roles;
    }

    function import_prop($reg) {
        if ($reg instanceof Contact) {
            foreach (self::$props as $prop => $shape) {
                if (($shape & self::PROP_IMPORT) !== 0) {
                    $value = $reg->prop1($prop, $shape);
                    if ($value === null && ($shape & self::PROP_NULL) === 0) {
                        $value = "";
                    }
                    $this->set_prop($prop, $value, true);
                }
            }
        } else {
            $this->set_prop("firstName", $reg->firstName ?? "", true);
            $this->set_prop("lastName", $reg->lastName ?? "", true);
            $this->set_prop("affiliation", $reg->affiliation ?? "", true);
            $this->set_prop("phone", $reg->phone ?? "", true);
            $this->set_prop("country", $reg->country ?? "", true);
        }
    }

    static function create(Conf $conf, $actor, $reg, $flags = 0, $roles = 0) {
        // clean registration
        if (is_array($reg)) {
            $reg = (object) $reg;
        }
        assert(is_string($reg->email));
        $reg->email = trim($reg->email);
        assert($reg->email !== "");
        if (!isset($reg->firstName) && isset($reg->first)) {
            $reg->firstName = $reg->first;
        }
        if (!isset($reg->lastName) && isset($reg->last)) {
            $reg->lastName = $reg->last;
        }
        if (isset($reg->name) && !isset($reg->firstName) && !isset($reg->lastName)) {
            list($reg->firstName, $reg->lastName) = Text::split_name($reg->name);
        }

        // look up existing accounts
        $valid_email = validate_email($reg->email);
        $u = $conf->user_by_email($reg->email) ?? new Contact(["email" => $reg->email], $conf);
        if (($cdb = $conf->contactdb()) && $valid_email) {
            $cdbu = $conf->contactdb_user_by_email($reg->email);
        } else {
            $cdbu = null;
        }
        $create = !$u->contactId;
        $aupapers = [];

        // if local does not exist, create it
        if (!$u->contactId) {
            if ((($flags & self::SAVE_IMPORT) !== 0 && !$cdbu)
                || (($flags & self::SAVE_ANY_EMAIL) === 0 && !$valid_email)) {
                return null;
            } else if ($valid_email) {
                // update registration from authorship information
                $aupapers = self::email_authored_papers($conf, $reg->email, $reg);
            }
        }

        // create or update contactdb user
        if ($cdb && $valid_email) {
            $cdbu = $cdbu ?? new Contact(["email" => $reg->email, "cdb_confid" => -1], $conf);
            $cdbu->import_prop($reg);
            if ($cdbu->save_prop()) {
                $u->_contactdb_user = false;
            }
        }

        // create or update local user
        $u->import_prop($cdbu ?? $reg);
        if (!$u->contactId) {
            if (($cdbu && $cdbu->disabled)
                || ($reg->disabled ?? false)) {
                $u->set_prop("disabled", true);
            }
            if ($cdbu) {
                $u->set_prop("password", "");
                $u->set_prop("passwordTime", $cdbu->passwordTime);
                $u->set_prop("passwordUseTime", 0);
            }
        }
        if (!$u->save_prop()) {
            // maybe failed because concurrent create (unlikely)
            $u = $conf->user_by_email($reg->email);
        }

        // update roles
        if ($flags & self::SAVE_ROLES) {
            $u->save_roles($roles, $actor);
        }
        if ($aupapers) {
            $u->save_authored_papers($aupapers);
            if ($cdbu) {
                // can't use `$cdbu` itself b/c `cdb_confid` might be missing
                $u->_contactdb_save_roles($u->contactdb_user());
            }
        }

        // notify on creation
        if ($create) {
            $type = $u->is_disabled() ? ", disabled" : "";
            $conf->log_for($actor && $actor->has_email() ? $actor : $u, $u, "Account created" . $type);
        }

        return $u;
    }


    // PASSWORDS
    //
    // Password values
    // * "": Unset password. In contactdb, means local password allowed.
    // * " unset": Affirmatively unset password. In contactdb, overrides older
    //   local passwords.
    // * " reset": Affirmatively reset password. User must reset password to
    //   log in.
    // * " nologin": Disallows login and cannot be reset.
    // * " $[password_hash]": Hashed password.
    // * "[not space]....": Legacy plaintext password, reset to hashed password
    //   on successful login.
    // * " [hashmethod] [keyid] [salt][hash_hmac]": Legacy hashed password
    //   using hash_hmac. `salt` is 16 bytes. Reset to hashed password on
    //   successful login.
    //
    // Password checking guiding principles
    // * Contactdb password generally takes preference. On successful signin
    //   using contactdb password, local password is reset to "".

    /** @param string $input
     * @return bool */
    static function valid_password($input) {
        return strlen($input) > 5 && trim($input) === $input;
    }

    /** @return bool */
    function password_unset() {
        $cdbu = $this->contactdb_user();
        return (!$cdbu
                || (string) $cdbu->password === ""
                || str_starts_with($cdbu->password, " unset"))
            && ((string) $this->password === ""
                || str_starts_with($this->password, " unset")
                || ($cdbu && (string) $cdbu->password !== "" && $cdbu->passwordTime >= $this->passwordTime));
    }

    /** @return bool */
    function can_reset_password() {
        $cdbu = $this->contactdb_user();
        return !$this->conf->external_login()
            && !str_starts_with((string) $this->password, " nologin")
            && (!$cdbu || !str_starts_with((string) $cdbu->password, " nologin"));
    }


    // obsolete
    private function password_hmac_key($keyid) {
        if ($keyid === null) {
            $keyid = $this->conf->opt("passwordHmacKeyid", 0);
        }
        $key = $this->conf->opt("passwordHmacKey.$keyid");
        if (!$key && $keyid == 0) {
            $key = $this->conf->opt("passwordHmacKey");
        }
        if (!$key) { /* backwards compatibility */
            $key = $this->conf->setting_data("passwordHmacKey.$keyid");
        }
        if (!$key) {
            error_log("missing passwordHmacKey.$keyid, using default");
            $key = "NdHHynw6JwtfSZyG3NYPTSpgPFG8UN8NeXp4tduTk2JhnSVy";
        }
        return $key;
    }

    /** @param string $input
     * @param string $pwhash
     * @return bool */
    private function check_hashed_password($input, $pwhash) {
        if ($input == ""
            || $input === "*"
            || (string) $pwhash === ""
            || $pwhash === "*") {
            return false;
        } else if ($pwhash[0] !== " ") {
            return $pwhash === $input;
        } else if ($pwhash[1] === "\$") {
            return password_verify($input, substr($pwhash, 2));
        } else if (($method_pos = strpos($pwhash, " ", 1)) !== false
                   && ($keyid_pos = strpos($pwhash, " ", $method_pos + 1)) !== false
                   && strlen($pwhash) > $keyid_pos + 17
                   && function_exists("hash_hmac")) {
            $method = substr($pwhash, 1, $method_pos - 1);
            $keyid = substr($pwhash, $method_pos + 1, $keyid_pos - $method_pos - 1);
            $salt = substr($pwhash, $keyid_pos + 1, 16);
            return hash_hmac($method, $salt . $input, $this->password_hmac_key($keyid), true)
                === substr($pwhash, $keyid_pos + 17);
        } else {
            return false;
        }
    }

    /** @return int|string */
    private function password_hash_method() {
        $m = $this->conf->opt("passwordHashMethod");
        return is_int($m) ? $m : PASSWORD_DEFAULT;
    }

    /** @param string $hash
     * @return bool */
    private function password_needs_rehash($hash) {
        return $hash === ""
            || $hash[0] !== " "
            || $hash[1] !== "\$"
            || password_needs_rehash(substr($hash, 2), $this->password_hash_method());
    }

    /** @param string $input
     * @return string */
    private function hash_password($input) {
        return " \$" . password_hash($input, $this->password_hash_method());
    }

    /** @param string $input
     * @return array{ok:bool} */
    function check_password_info($input, $options = []) {
        assert(!$this->conf->external_login());
        $cdbu = $this->contactdb_user();

        // check passwords
        $local_ok = $this->contactId > 0
            && $this->password
            && $this->check_hashed_password($input, $this->password);
        $cdb_ok = $cdbu
            && $cdbu->password
            && $this->check_hashed_password($input, $cdbu->password);
        $cdb_older = !$cdbu || $cdbu->passwordTime < $this->passwordTime;

        // invalid passwords cannot be used to log in
        if (trim($input) === "") {
            return ["ok" => false, "nopw" => true];
        } else if ($input === "0" || $input === "*") {
            return ["ok" => false, "invalid" => true];
        }

        // users with reset passwords cannot log in
        if (($cdbu
             && str_starts_with($cdbu->password, " reset"))
            || ($cdb_older
                && !$cdb_ok
                && str_starts_with($this->password, " reset"))) {
            return ["ok" => false, "reset" => true];
        }

        // users with unset passwords cannot log in
        // This logic should correspond closely with Contact::password_unset().
        if (($cdbu
             && (!$cdb_older || !$local_ok)
             && str_starts_with($cdbu->password, " unset"))
            || ((!$cdbu || (string) $cdbu->password === "")
                && str_starts_with($this->password, " unset"))
            || ((!$cdbu || (string) $cdbu->password === "")
                && (string) $this->password === "")) {
            return ["ok" => false, "email" => true, "unset" => true];
        }

        // deny if no match
        if (!$cdb_ok && !$local_ok) {
            $x = [
                "ok" => false, "invalid" => true,
                "can_reset" => $this->can_reset_password()
            ];
            // report information about passwords
            if ($this->password) {
                if ($this->password[0] === " "
                    && $this->password[1] !== "$") {
                    $x["local_password"] = $this->password;
                }
                if ($this->passwordTime > 0) {
                    $x["local_password_age"] = ceil((Conf::$now - $this->passwordTime) / 8640) / 10;
                }
            }
            if ($cdbu && $cdbu->password) {
                if ($cdbu->password[0] === " "
                    && $cdbu->password[1] !== "$") {
                    $x["cdbu_password"] = $cdbu->password;
                }
                if ($cdbu->passwordTime > 0) {
                    $x["cdb_password_age"] = ceil((Conf::$now - $cdbu->passwordTime) / 8640) / 10;
                }
            }
            return $x;
        }

        // disabled users cannot log in
        // (NB all `anonymous` users should be disabled)
        if (($this->contactId && $this->is_disabled())
            || ($cdbu && $cdbu->is_disabled())) {
            return ["ok" => false, "email" => true, "disabled" => true];
        }

        // otherwise, the login attempt succeeds

        // create cdb user
        if (!$cdbu && $this->conf->contactdb()) {
            $this->contactdb_update();
            $cdbu = $this->contactdb_user();
        }

        // update cdb password
        if ($cdb_ok
            || ($cdbu && (string) $cdbu->password === "")) {
            if (!$cdb_ok || $this->password_needs_rehash($cdbu->password)) {
                $cdbu->set_prop("password", $this->hash_password($input));
            }
            if (!$cdb_ok || !$cdbu->passwordTime) {
                $cdbu->set_prop("passwordTime", Conf::$now);
            }
            $cdbu->set_prop("passwordUseTime", Conf::$now);
            $cdbu->save_prop();

            // clear local password
            if ($this->contactId > 0 && (string) $this->password !== "") {
                $this->set_prop("password", "");
                $this->set_prop("passwordTime", Conf::$now);
                $this->set_prop("passwordUseTime", Conf::$now);
                $this->save_prop();
                $local_ok = false;
            }
        }

        // update local password
        if ($local_ok) {
            if ($this->password_needs_rehash($this->password)) {
                $this->set_prop("password", $this->hash_password($input));
            }
            if (!$this->passwordTime) {
                $this->set_prop("passwordTime", Conf::$now);
            }
            $this->set_prop("passwordUseTime", Conf::$now);
            $this->save_prop();

            // complain about local password use
            if ($cdbu) {
                $t0 = $this->passwordTime ? ceil((Conf::$now - $this->passwordTime) / 8640) / 10 : -1;
                $t1 = $cdbu->passwordTime ? ceil((Conf::$now - $cdbu->passwordTime) / 8640) / 10 : -1;
                error_log("{$this->conf->dbname}: user {$this->email}: signing in with local password, which is " . ($this->passwordTime < $cdbu->passwordTime ? "older" : "newer") . " than cdb [{$t0}d/{$t1}d]");
            }
        }

        return ["ok" => true];
    }

    /** @param string $input
     * @return bool */
    function check_password($input) {
        $x = $this->check_password_info($input);
        return $x["ok"];
    }

    function change_password($new) {
        assert(!$this->conf->external_login());
        assert($new !== null);

        if ($new && $new[0] !== " ") {
            $hash = $this->hash_password($new);
            $use_time = Conf::$now;
        } else {
            $hash = $new;
            $use_time = 0;
        }

        $cdbu = $this->contactdb_user();
        $saveu = $cdbu ?? ($this->contactId ? $this : null);
        if ($saveu) {
            $saveu->set_prop("password", $hash);
            $saveu->set_prop("passwordTime", Conf::$now);
            $saveu->set_prop("passwordUseTime", $use_time);
            $saveu->save_prop();
        }
        if ($saveu === $cdbu && $this->contactId && (string) $this->password !== "") {
            $this->set_prop("password", "");
            $this->set_prop("passwordTime", Conf::$now);
            $this->set_prop("passwordUseTime", $use_time);
            $this->save_prop();
        }
        return true;
    }


    function send_mail($template, $rest = []) {
        $mailer = new HotCRPMailer($this->conf, $this, $rest);
        $prep = $mailer->prepare($template, $rest);
        if ($prep->can_send()) {
            $prep->send();
            return $prep;
        } else {
            Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($this->email) . " at this time.");
            return false;
        }
    }


    function mark_login() {
        // at least one login every 30 days is marked as activity
        if ((int) $this->activity_at <= Conf::$now - 2592000
            || (($cdbu = $this->contactdb_user())
                && ((int) $cdbu->activity_at <= Conf::$now - 2592000))) {
            $this->mark_activity();
        }
    }

    function mark_activity() {
        if ((!$this->activity_at || $this->activity_at < Conf::$now)
            && !$this->is_anonymous_user()) {
            $this->activity_at = Conf::$now;
            if ($this->contactId) {
                $this->conf->ql("update ContactInfo set lastLogin=".Conf::$now." where contactId=$this->contactId");
            }
            if (($cdbu = $this->contactdb_user())
                && (int) $cdbu->activity_at <= Conf::$now - 604800) {
                $this->_contactdb_save_roles($cdbu);
            }
        }
    }

    function log_activity($text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log_for($this, $this, $text, $paperId);
        }
    }

    function log_activity_for($user, $text, $paperId = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log_for($this, $user, $text, $paperId);
        }
    }


    // HotCRP roles

    static function update_rights() {
        ++self::$rights_version;
    }

    function update_my_rights() {
        if ($this->contactId > 0) {
            self::update_rights();
        } else {
            $this->contactXid = self::$next_xid--;
            $this->_rights_version = self::$rights_version - 1;
        }
    }

    private function load_author_reviewer_status() {
        // Load from database
        if ($this->contactId > 0) {
            $qs = ["exists (select * from PaperConflict where contactId=? and conflictType>=" . CONFLICT_AUTHOR . ")",
                   "exists (select * from PaperReview where contactId=?)"];
            $qv = [$this->contactId, $this->contactId];
            if ($this->isPC) {
                $qs[] = "exists (select * from PaperReview where requestedBy=? and reviewType<=" . REVIEW_PC . " and contactId!=?)";
                array_push($qv, $this->contactId, $this->contactId);
            } else {
                $qs[] = "0";
            }
            if ($this->_review_tokens) {
                $qs[] = "exists (select * from PaperReview where reviewToken?a)";
                $qv[] = $this->_review_tokens;
            } else {
                $qs[] = "0";
            }
            $result = $this->conf->qe_apply("select " . join(", ", $qs), $qv);
            $row = $result->fetch_row();
            $this->_db_roles = ($row && $row[0] > 0 ? self::ROLE_AUTHOR : 0)
                | ($row && $row[1] > 0 ? self::ROLE_REVIEWER : 0)
                | ($row && $row[2] > 0 ? self::ROLE_REQUESTER : 0);
            $this->_active_roles = $this->_db_roles
                | ($row && $row[3] > 0 ? self::ROLE_REVIEWER : 0);
            Dbl::free($result);
        } else {
            $this->_db_roles = $this->_active_roles = 0;
        }

        // Update contact information from capabilities
        if ($this->_capabilities) {
            foreach ($this->_capabilities as $k => $v) {
                if (str_starts_with($k, "@av") && $v) {
                    $this->_active_roles |= self::ROLE_AUTHOR;
                } else if (str_starts_with($k, "@ra") && $v) {
                    $this->_active_roles |= self::ROLE_REVIEWER;
                }
            }
        }
    }

    private function check_rights_version() {
        if ($this->_rights_version !== self::$rights_version) {
            $this->_db_roles = $this->_active_roles =
                $this->_has_outstanding_review = $this->_is_lead =
                $this->_is_explicit_manager = $this->_is_metareviewer =
                $this->_can_view_pc = $this->_dangerous_track_mask =
                $this->_has_approvable = $this->_authored_papers =
                $this->_author_perm_tags = null;
            $this->_rights_version = self::$rights_version;
        }
    }

    /** @param string $tag
     * @return bool */
    function some_author_perm_tag_allows($tag) {
        $this->check_rights_version();
        if ($this->_author_perm_tags === null) {
            $this->_author_perm_tags = [];
            $qe = $qv = [];
            if (($pids = $this->author_view_capability_paper_ids())) {
                $qe[] = "paperId?a";
                $qv[] = $pids;
            }
            if ($this->contactId > 0) {
                $qe[] = "exists(select * from PaperConflict where paperId=PaperTag.paperId and contactId=? and conflictType>=" . CONFLICT_AUTHOR . ")";
                $qv[] = $this->contactId;
            }
            if (!empty($qe)) {
                $result = $this->conf->qe_apply("select distinct tag from PaperTag where tag like 'perm:%' and tagIndex>=0 and (" . join(" or ", $qe) . ")", $qv);
                while (($row = $result->fetch_row())) {
                    $this->_author_perm_tags[strtolower(substr($row[0], 5))] = true;
                }
                Dbl::free($result);
            }
        }
        return isset($this->_author_perm_tags[$tag]);
    }

    /** @return bool */
    function is_author() {
        $this->check_rights_version();
        if (!isset($this->_active_roles)) {
            $this->load_author_reviewer_status();
        }
        return ($this->_active_roles & self::ROLE_AUTHOR) !== 0;
    }

    /** @return list<PaperInfo> */
    function authored_papers() {
        $this->check_rights_version();
        if ($this->_authored_papers === null) {
            $this->_authored_papers = $this->is_author() ? $this->paper_set(["author" => true, "tags" => true])->as_list() : [];
        }
        return $this->_authored_papers;
    }

    /** @return bool */
    function has_review() {
        $this->check_rights_version();
        if (!isset($this->_active_roles)) {
            $this->load_author_reviewer_status();
        }
        return ($this->_active_roles & self::ROLE_REVIEWER) !== 0;
    }

    /** @return bool */
    function is_reviewer() {
        return $this->isPC || $this->has_review();
    }

    /** @return bool */
    function is_metareviewer() {
        if (!isset($this->_is_metareviewer)) {
            $this->_is_metareviewer = $this->isPC
                && $this->conf->setting("metareviews")
                && !!$this->conf->fetch_ivalue("select exists (select * from PaperReview where contactId={$this->contactId} and reviewType=" . REVIEW_META . ")");
        }
        return $this->_is_metareviewer;
    }

    /** @return int */
    function contactdb_roles() {
        if ($this->is_disabled()) {
            return 0;
        } else {
            $this->is_author(); // load _db_roles
            return $this->roles
                | ($this->_db_roles & (self::ROLE_AUTHOR | self::ROLE_REVIEWER));
        }
    }

    /** @return bool */
    function has_outstanding_review() {
        $this->check_rights_version();
        if ($this->_has_outstanding_review === null) {
            $this->_has_outstanding_review = $this->has_review()
                && $this->conf->fetch_ivalue("select exists (select * from PaperReview join Paper using (paperId) where Paper.timeSubmitted>0 and " . $this->act_reviewer_sql("PaperReview") . " and reviewNeedsSubmit!=0)");
        }
        return $this->_has_outstanding_review;
    }

    /** @return bool */
    function is_requester() {
        $this->check_rights_version();
        if (!isset($this->_active_roles)) {
            $this->load_author_reviewer_status();
        }
        return ($this->_active_roles & self::ROLE_REQUESTER) !== 0;
    }

    /** @return bool */
    function is_discussion_lead() {
        $this->check_rights_version();
        if (!isset($this->_is_lead)) {
            $this->_is_lead = $this->contactXid > 0
                && $this->isPC
                && $this->conf->has_any_lead_or_shepherd()
                && $this->conf->fetch_ivalue("select exists (select * from Paper where leadContactId=?)", $this->contactXid);
        }
        return $this->_is_lead;
    }

    /** @return bool */
    function is_explicit_manager() {
        $this->check_rights_version();
        if (!isset($this->_is_explicit_manager)) {
            $this->_is_explicit_manager = $this->contactXid > 0
                && $this->isPC
                && ($this->conf->check_any_admin_tracks($this)
                    || ($this->conf->has_any_manager()
                        && $this->conf->fetch_ivalue("select exists (select * from Paper where managerContactId=?)", $this->contactXid) > 0));
        }
        return $this->_is_explicit_manager;
    }

    /** @return bool */
    function is_manager() {
        return $this->privChair || $this->is_explicit_manager();
    }

    /** @return bool */
    function is_track_manager() {
        return $this->privChair || $this->conf->check_any_admin_tracks($this);
    }

    /** @return bool */
    function has_review_pending_approval($my_request_only = false) {
        $this->check_rights_version();
        if ($this->_has_approvable === null) {
            $this->_has_approvable = 0;
            if ($this->conf->ext_subreviews > 1) {
                if ($this->is_manager()) {
                    $search = new PaperSearch($this, "re:pending-approval OR (has:proposal admin:me) HIGHLIGHT:pink re:pending-my-approval HIGHLIGHT:green re:pending-approval HIGHLIGHT:yellow (has:proposal admin:me)");
                    if (($hmap = $search->paper_highlights())) {
                        $colors = array_unique(call_user_func_array("array_merge", array_values($hmap)));
                        foreach (["green", "pink", "yellow"] as $i => $k) {
                            if (in_array($k, $colors))
                                $this->_has_approvable |= 1 << $i;
                        }
                    }
                } else if ($this->is_requester()
                           && $this->conf->fetch_ivalue("select exists (select * from PaperReview where reviewType=" . REVIEW_EXTERNAL . " and reviewSubmitted is null and timeApprovalRequested>0 and requestedBy={$this->contactId})")) {
                    $this->_has_approvable = 2;
                }
            } else if ($this->is_manager()) {
                $search = new PaperSearch($this, "has:proposal admin:me");
                if ($search->paper_ids()) {
                    $this->_has_approvable = 4;
                }
            }
        }
        $flag = $my_request_only ? 2 : 3;
        return ($this->_has_approvable & $flag) !== 0;
    }

    /** @return bool */
    function has_proposal_pending() {
        $this->has_review_pending_approval();
        return ($this->_has_approvable & 4) !== 0;
    }


    // review tokens

    /** @return list<int> */
    function review_tokens() {
        return $this->_review_tokens ?? [];
    }

    /** @return int|false */
    function active_review_token_for(PaperInfo $prow, ReviewInfo $rrow = null) {
        if ($this->_review_tokens !== null) {
            foreach ($rrow ? [$rrow] : $prow->reviews_by_id() as $rr) {
                if ($rrow->reviewToken !== 0
                    && in_array($rrow->reviewToken, $this->_review_tokens, true))
                    return $rrow->reviewToken;
            }
        }
        return false;
    }

    /** @param false|int $token
     * @param bool $on */
    function change_review_token($token, $on) {
        assert(($token === false && $on === false) || is_int($token));
        $this->_review_tokens = $this->_review_tokens ?? [];
        $old_ntokens = count($this->_review_tokens);
        if (!$on && $token === false) {
            $this->_review_tokens = [];
        } else {
            $pos = array_search($token, $this->_review_tokens);
            if (!$on && $pos !== false) {
                array_splice($this->_review_tokens, $pos, 1);
            } else if ($on && $pos === false && $token != 0) {
                $this->_review_tokens[] = $token;
            }
        }
        $new_ntokens = count($this->_review_tokens);
        if ($new_ntokens === 0) {
            $this->_review_tokens = null;
        }
        if ($new_ntokens !== $old_ntokens) {
            $this->update_my_rights();
            if ($this->_activated) {
                $this->save_session("rev_tokens", $this->_review_tokens);
            }
        }
        return $new_ntokens !== $old_ntokens;
    }


    // topic interests

    /** @return array<int,int> */
    function topic_interest_map() {
        global $Me;
        if ($this->_topic_interest_map === null) {
            if ($this->contactId <= 0 || !$this->conf->has_topics()) {
                $this->_topic_interest_map = [];
            } else if (($this->roles & self::ROLE_PCLIKE)
                       && $this !== $Me
                       && ($pcm = $this->conf->pc_members())
                       && $this === ($pcm[$this->contactId] ?? null)) {
                self::load_topic_interests($pcm);
            } else {
                $result = $this->conf->qe("select topicId, interest from TopicInterest where contactId={$this->contactId} and interest!=0");
                $this->_topic_interest_map = Dbl::fetch_iimap($result);
                $this->_sort_topic_interests();
            }
        }
        return $this->_topic_interest_map;
    }

    function invalidate_topic_interests() {
        $this->_topic_interest_map = null;
    }

    /** @param Contact[] $contacts */
    static function load_topic_interests($contacts) {
        $cbyid = [];
        foreach ($contacts as $c) {
            $c->_topic_interest_map = [];
            $cbyid[$c->contactId] = $c;
        }
        if (!empty($cbyid)) {
            $result = current($cbyid)->conf->qe("select contactId, topicId, interest from TopicInterest where interest!=0 order by contactId");
            $c = null;
            while (($row = $result->fetch_row())) {
                if (!$c || $c->contactId != $row[0]) {
                    $c = $cbyid[(int) $row[0]] ?? null;
                }
                if ($c) {
                    $c->_topic_interest_map[(int) $row[1]] = (int) $row[2];
                }
            }
            Dbl::free($result);
        }
        foreach ($contacts as $c) {
            $c->_sort_topic_interests();
        }
    }

    private function _sort_topic_interests() {
        $this->conf->topic_set()->ksort($this->_topic_interest_map);
    }


    // permissions policies

    /** @return int */
    private function dangerous_track_mask() {
        if ($this->_dangerous_track_mask === null) {
            $this->_dangerous_track_mask = $this->conf->dangerous_track_mask($this);
        }
        return $this->_dangerous_track_mask;
    }

    /** @return PaperContactInfo */
    private function rights(PaperInfo $prow) {
        $ci = $prow->contact_info($this);

        // check first whether administration is allowed
        if (!isset($ci->allow_administer)) {
            $ci->allow_administer = false;
            if ($prow->managerContactId === $this->contactXid
                || ($this->privChair
                    && (!$prow->managerContactId || $ci->conflictType <= CONFLICT_MAXUNCONFLICTED)
                    && (!($this->dangerous_track_mask() & Track::BITS_VIEWADMIN)
                        || ($this->conf->check_tracks($prow, $this, Track::VIEW)
                            && $this->conf->check_tracks($prow, $this, Track::ADMIN))))
                || ($this->isPC
                    && $this->is_track_manager()
                    && (!$prow->managerContactId || $ci->conflictType <= CONFLICT_MAXUNCONFLICTED)
                    && $this->conf->check_admin_tracks($prow, $this))
                || $this->is_site_contact) {
                $ci->allow_administer = true;
            }
        }

        // correct $forceShow
        $forceShow = $ci->allow_administer
            && ($this->_overrides & self::OVERRIDE_CONFLICT) !== 0;
        if ($forceShow) {
            $ci = $ci->get_forced_rights();
        }

        // set other rights
        if ($ci->rights_forced !== $forceShow) {
            $ci->rights_forced = $forceShow;

            // check current administration status
            $ci->can_administer = $ci->allow_administer
                && ($ci->conflictType <= CONFLICT_MAXUNCONFLICTED || $forceShow);

            // check PC tracking
            // (see also can_accept_review_assignment*)
            $tracks = $this->conf->has_tracks();
            $am_lead = $this->isPC
                && $prow->leadContactId === $this->contactXid;
            $isPC = $this->isPC
                && (!$tracks
                    || $ci->reviewType >= REVIEW_PC
                    || $am_lead
                    || !$this->conf->check_track_view_sensitivity()
                    || $this->conf->check_tracks($prow, $this, Track::VIEW));

            // check whether PC privileges apply
            $ci->allow_pc_broad = $ci->allow_administer || $isPC;
            $ci->allow_pc = $ci->can_administer
                || ($isPC && $ci->conflictType <= CONFLICT_MAXUNCONFLICTED);

            // check review accept capability
            if ($ci->reviewType == 0
                && $this->_capabilities !== null
                && ($ru = $this->reviewer_capability_user($prow->paperId))
                && ($rci = $prow->contact_info($ru))) {
                if ($rci->review_status == 0) {
                    $rci->review_status = PaperContactInfo::RS_DECLINED;
                }
                $ci->reviewType = $rci->reviewType;
                $ci->review_status = $rci->review_status;
            }

            // check whether this is a potential reviewer
            // (existing external reviewer or PC)
            if ($ci->reviewType > 0 || $am_lead) {
                $ci->potential_reviewer = true;
            } else if ($ci->allow_administer || $ci->allow_pc) {
                $ci->potential_reviewer = !$tracks
                    || !$this->conf->check_track_review_sensitivity()
                    || ($ci->allow_administer
                        && !($this->dangerous_track_mask() & Track::BITS_REVIEW))
                    || ($this->conf->check_tracks($prow, $this, Track::ASSREV)
                        && $this->conf->check_tracks($prow, $this, Track::UNASSREV));
            } else {
                $ci->potential_reviewer = false;
            }
            $ci->allow_review = $ci->potential_reviewer
                && ($ci->can_administer || $ci->conflictType <= CONFLICT_MAXUNCONFLICTED);

            // check author allowance
            $ci->act_author = $ci->conflictType >= CONFLICT_AUTHOR;
            $ci->allow_author = $ci->act_author || $ci->allow_administer;

            // check author view allowance (includes capabilities)
            // If an author-view capability is set, then use it -- unless
            // this user is a PC member or reviewer, which takes priority.
            $ci->view_conflict_type = $ci->conflictType;
            if ($ci->view_conflict_type <= CONFLICT_MAXUNCONFLICTED) {
                $ci->view_conflict_type = 0;
            }
            if ($this->_capabilities !== null
                && ($this->_capabilities["@av{$prow->paperId}"] ?? null)
                && !$isPC
                && $ci->review_status == 0) {
                $ci->view_conflict_type = CONFLICT_AUTHOR;
            }
            $ci->act_author_view = $ci->view_conflict_type >= CONFLICT_AUTHOR;
            $ci->allow_author_view = $ci->act_author_view || $ci->allow_administer;

            // check decision visibility
            $ci->can_view_decision = $ci->can_administer
                || ($ci->act_author_view
                    && $prow->can_author_view_decision())
                || ($ci->allow_pc_broad
                    && $this->conf->time_pc_view_decision($ci->view_conflict_type > 0))
                || ($ci->review_status > PaperContactInfo::RS_UNSUBMITTED
                    && $this->conf->time_reviewer_view_decision()
                    && ($ci->allow_pc_broad
                        || $this->conf->setting("extrev_view") > 0));

            // check view-authors state
            if ($ci->act_author_view && !$ci->allow_administer) {
                $ci->view_authors_state = 2;
            } else if ($ci->allow_pc_broad || $ci->review_status > 0) {
                $bs = $this->conf->submission_blindness();
                $nb = $bs == Conf::BLIND_NEVER
                    || ($bs == Conf::BLIND_OPTIONAL
                        && !$prow->blind)
                    || ($bs == Conf::BLIND_UNTILREVIEW
                        && $ci->review_status > PaperContactInfo::RS_PROXIED)
                    || ($prow->outcome > 0
                        && ($isPC || $ci->allow_review)
                        && $ci->can_view_decision
                        && $this->conf->time_reviewer_view_accepted_authors());
                if ($ci->allow_administer) {
                    $ci->view_authors_state = $nb ? 2 : 1;
                } else if ($nb
                           && ($prow->timeSubmitted != 0
                               || ($ci->allow_pc_broad
                                   && $prow->timeWithdrawn <= 0
                                   && $this->conf->can_pc_see_active_submissions()))) {
                    $ci->view_authors_state = 2;
                } else {
                    $ci->view_authors_state = 0;
                }
            } else {
                $ci->view_authors_state = 0;
            }

            // check for permission tags
            if ($prow->paperTags !== null
                && $this->conf->has_perm_tags()
                && stripos($prow->paperTags, " perm:") !== false) {
                $ci->perm_tags = $prow->paperTags;
            }
        }

        return $ci;
    }

    /** @return PaperContactInfo */
    function __rights(PaperInfo $prow) {
        // public access point; to be avoided
        return $this->rights($prow);
    }

    /** @param ?PaperContactInfo $rights
     * @return bool */
    function override_deadlines($rights) {
        if (($this->_overrides & (self::OVERRIDE_CHECK_TIME | self::OVERRIDE_TIME))
            === self::OVERRIDE_CHECK_TIME) {
            return false;
        } else if ($rights) {
            return $rights->can_administer;
        } else {
            return $this->privChair;
        }
    }

    /** @return bool */
    function allow_administer(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer;
        } else {
            return $this->privChair;
        }
    }

    /** @return bool */
    function has_overridable_conflict(PaperInfo $prow) {
        if ($this->is_manager()) {
            $rights = $this->rights($prow);
            return $rights->allow_administer && $rights->conflictType > CONFLICT_MAXUNCONFLICTED;
        } else {
            return false;
        }
    }

    /** @param Contact $acct
     * @return bool */
    function can_change_password($acct) {
        return ($this->privChair && !$this->conf->opt("chairHidePasswords"))
            || ($acct
                && $this->contactId > 0
                && $this->contactId == $acct->contactId
                && $this->_activated
                && !self::$true_user);
    }

    /** @return bool */
    function can_administer(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->can_administer;
        } else {
            // XXX deprecated
            error_log(debug_string_backtrace());
            return $this->privChair;
        }
    }

    /** @param PaperContactInfo $rights
     * @return bool */
    private function _can_administer_for_track(PaperInfo $prow, $rights, $ttype) {
        return $rights->can_administer
            && (!($this->dangerous_track_mask() & (1 << $ttype))
                || $this->conf->check_tracks($prow, $this, $ttype));
    }

    /** @return bool */
    function can_administer_for_track(PaperInfo $prow = null, $ttype) {
        if ($prow) {
            return $this->_can_administer_for_track($prow, $this->rights($prow), $ttype);
        } else {
            return $this->privChair;
        }
    }

    /** @return bool */
    function is_primary_administrator(PaperInfo $prow) {
        // - Assigned administrator is primary
        // - Otherwise, track administrators are primary
        // - Otherwise, chairs are primary
        $rights = $this->rights($prow);
        if ($rights->primary_administrator === null) {
            $rights->primary_administrator = $rights->allow_administer
                && ($prow->managerContactId
                    ? $prow->managerContactId === $this->contactXid
                    : !$this->privChair
                      || !$this->conf->check_paper_track_sensitivity($prow, Track::ADMIN));
        }
        return $rights->primary_administrator;
    }

    /** @return bool */
    function act_pc(PaperInfo $prow = null) {
        if ($prow) {
            return $this->rights($prow)->allow_pc;
        } else {
            return $this->isPC;
        }
    }

    /** @return bool */
    function can_view_pc() {
        $this->check_rights_version();
        if ($this->_can_view_pc === null) {
            if ($this->is_manager() || $this->tracker_kiosk_state > 0) {
                $this->_can_view_pc = 2;
            } else if ($this->conf->opt("secretPC")) {
                $this->_can_view_pc = 0;
            } else if ($this->isPC) {
                $this->_can_view_pc = 2;
            } else {
                $this->_can_view_pc = $this->conf->opt("privatePC") ? 0 : 1;
            }
        }
        return $this->_can_view_pc > 0;
    }

    /** @return bool */
    function can_lookup_user() {
        if ($this->privChair) {
            return true;
        } else {
            $x = $this->conf->opt("allowLookupUser");
            return $x || ($x === null && $this->can_view_pc());
        }
    }

    /** @return bool */
    function can_view_user_tags() {
        return $this->privChair
            || ($this->can_view_pc() && $this->_can_view_pc > 1);
    }

    /** @param string $tag
     * @return bool */
    function can_view_user_tag($tag) {
        return $this->can_view_user_tags()
            && $this->conf->tags()->censor(TagMap::CENSOR_VIEW, " {$tag}#0", $this, null) !== "";
    }

    /** @return bool */
    function can_view_tracker($tracker_json = null) {
        return $this->privChair
            || ($this->isPC
                && $this->conf->check_default_track($this, Track::VIEWTRACKER)
                && (!$tracker_json
                    || !isset($tracker_json->visibility)
                    || ($this->has_tag(substr($tracker_json->visibility, 1))
                        === ($tracker_json->visibility[0] === "+"))))
            || $this->tracker_kiosk_state > 0;
    }

    /** @return bool */
    function include_tracker_conflict($tracker_json = null) {
        return $this->isPC
            && (!($perm = $this->conf->track_permission("_", Track::VIEWTRACKER))
                || $perm === "+none"
                || $this->has_permission($perm))
            && (!$tracker_json
                || !isset($tracker_json->visibility)
                || ($this->has_tag(substr($tracker_json->visibility, 1))
                    === ($tracker_json->visibility[0] === "+")));
    }

    /** @return int */
    function view_conflict_type(PaperInfo $prow = null) {
        if ($prow) {
            return $this->rights($prow)->view_conflict_type;
        } else {
            return 0;
        }
    }

    /** @return bool */
    function act_author(PaperInfo $prow) {
        return $this->rights($prow)->act_author;
    }

    /** @return bool */
    function act_author_view(PaperInfo $prow) {
        return $this->rights($prow)->act_author_view;
    }

    function act_author_view_sql($table, $only_if_complex = false) {
        // see also _author_perm_tags
        $m = [];
        if ($this->_capabilities !== null && !$this->isPC) {
            foreach ($this->author_view_capability_paper_ids() as $pid) {
                $m[] = "Paper.paperId=" . $pid;
            }
        }
        if (empty($m) && $this->contactId && $only_if_complex) {
            return false;
        } else {
            if ($this->contactId) {
                $m[] = "$table.conflictType>=" . CONFLICT_AUTHOR;
            }
            if (count($m) > 1) {
                return "(" . join(" or ", $m) . ")";
            } else {
                return empty($m) ? "false" : $m[0];
            }
        }
    }

    function act_reviewer_sql($table) {
        $m = [];
        if ($this->contactId > 0) {
            $m[] = "{$table}.contactId={$this->contactId}";
        }
        if (($rev_tokens = $this->review_tokens())) {
            $m[] = "{$table}.reviewToken in (" . join(",", $rev_tokens) . ")";
        }
        if ($this->_capabilities !== null) {
            foreach ($this->_capabilities as $k => $v) {
                if (str_starts_with($k, "@ra")
                    && $v
                    && ctype_digit(substr($k, 3)))
                    $m[] = "({$table}.paperId=" . substr($k, 3) . " and {$table}.contactId=" . $v . ")";
            }
        }
        if (count($m) > 1) {
            return "(" . join(" or ", $m) . ")";
        } else {
            return empty($m) ? "false" : $m[0];
        }
    }

    /** @return bool */
    function can_start_paper() {
        return $this->email
            && ($this->conf->timeStartPaper()
                || $this->override_deadlines(null));
    }

    /** @return ?PermissionProblem */
    function perm_start_paper() {
        if ($this->can_start_paper()) {
            return null;
        } else {
            return new PermissionProblem($this->conf, ["deadline" => "sub_reg", "override" => $this->privChair]);
        }
    }

    /** @return bool */
    function allow_edit_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->allow_administer || $prow->has_author($this);
    }

    /** @return bool */
    function can_edit_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if (!$rights->allow_author || $prow->timeWithdrawn > 0) {
            return false;
        } else if (($v = $rights->perm_tag_value("author-write"))) {
            return $v > 0;
        } else {
            return ($prow->outcome >= 0 && $this->conf->time_edit_paper($prow))
                || $this->override_deadlines($rights);
        }
    }

    /** @return ?PermissionProblem */
    function perm_edit_paper(PaperInfo $prow) {
        if ($this->can_edit_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view) {
            $whyNot["signin"] = "edit_paper";
        } else if (!$rights->allow_author) {
            $whyNot["author"] = true;
        }
        if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        }
        if ($prow->outcome < 0
            && $rights->can_view_decision) {
            $whyNot["rejected"] = true;
        }
        if ($prow->timeSubmitted > 0
            && $this->conf->setting("sub_freeze") > 0) {
            $whyNot["updateSubmitted"] = true;
        }
        if (!$this->conf->time_edit_paper($prow)
            && !$this->override_deadlines($rights)) {
            $whyNot["deadline"] = "sub_update";
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = true;
        }
        return $whyNot;
    }

    /** @return bool
     * @deprecated */
    function can_update_paper(PaperInfo $prow) {
        return $this->can_edit_paper($prow);
    }

    /** @return ?PermissionProblem
     * @deprecated */
    function perm_update_paper(PaperInfo $prow) {
        return $this->perm_edit_paper($prow);
    }

    /** @return bool */
    function can_finalize_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if (!$rights->allow_author || $prow->timeWithdrawn > 0) {
            return false;
        } else if (($v = $rights->perm_tag_value("author-write"))) {
            return $v > 0;
        } else {
            return $this->conf->timeFinalizePaper($prow)
                || $this->override_deadlines($rights);
        }
    }

    /** @return ?PermissionProblem */
    function perm_finalize_paper(PaperInfo $prow) {
        if ($this->can_finalize_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view) {
            $whyNot["signin"] = "edit_paper";
        } else if (!$rights->allow_author) {
            $whyNot["author"] = true;
        }
        if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        }
        if ($prow->timeSubmitted > 0) {
            $whyNot["updateSubmitted"] = true;
        }
        if (!$this->conf->timeFinalizePaper($prow)
            && !$this->override_deadlines($rights)) {
            $whyNot["deadline"] = "sub_sub";
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = true;
        }
        return $whyNot;
    }

    /** @return bool */
    function can_withdraw_paper(PaperInfo $prow, $display_only = false) {
        $rights = $this->rights($prow);
        $sub_withdraw = $this->conf->setting("sub_withdraw", 0);
        $override = $this->override_deadlines($rights);
        return $rights->allow_author
            && ($sub_withdraw !== -1
                || $prow->timeSubmitted == 0
                || $override)
            && ($sub_withdraw !== 0
                || !$prow->has_author_seen_any_review()
                || $override)
            && ($prow->outcome == 0
                || ($display_only && !$prow->can_author_view_decision())
                || $override);
    }

    /** @return ?PermissionProblem */
    function perm_withdraw_paper(PaperInfo $prow, $display_only = false) {
        if ($this->can_withdraw_paper($prow, $display_only)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        }
        if (!$rights->allow_author && $rights->allow_author_view) {
            $whyNot["signin"] = "edit_paper";
        } else if (!$rights->allow_author) {
            $whyNot["permission"] = "withdraw";
            $whyNot["author"] = true;
        } else if (!$this->override_deadlines($rights)) {
            $whyNot["permission"] = "withdraw";
            $sub_withdraw = $this->conf->setting("sub_withdraw", 0);
            if ($sub_withdraw === 0 && $prow->has_author_seen_any_review()) {
                $whyNot["reviewsSeen"] = true;
            } else if ($prow->outcome != 0) {
                $whyNot["decided"] = true;
            }
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = 1;
        }
        return $whyNot;
    }

    /** @return bool */
    function can_revive_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if (!$rights->allow_author || $prow->timeWithdrawn <= 0) {
            return false;
        } else if (($v = $rights->perm_tag_value("author-write"))) {
            return $v > 0;
        } else {
            return $this->conf->timeFinalizePaper($prow)
                || $this->override_deadlines($rights);
        }
    }

    /** @return ?PermissionProblem */
    function perm_revive_paper(PaperInfo $prow) {
        if ($this->can_revive_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view) {
            $whyNot["signin"] = "edit_paper";
        } else if (!$rights->allow_author) {
            $whyNot["author"] = true;
        }
        if ($prow->timeWithdrawn <= 0) {
            $whyNot["notWithdrawn"] = true;
        }
        if (!$this->conf->time_edit_paper($prow)
            && !$this->override_deadlines($rights)) {
            $whyNot["deadline"] = "sub_update";
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = true;
        }
        return $whyNot;
    }

    /** @return bool */
    function allow_edit_final_paper(PaperInfo $prow) {
        // see also PaperInfo::can_author_edit_final_paper
        if ($prow->timeWithdrawn > 0
            || $prow->outcome <= 0
            || !$this->conf->allow_final_versions()) {
            return false;
        }
        $rights = $this->rights($prow);
        if (!$rights->allow_author || !$rights->can_view_decision) {
            return false;
        } else if (($v = $rights->perm_tag_value("author-write"))) {
            return $v > 0;
        } else {
            return $rights->allow_administer
                || $this->conf->time_edit_final_paper();
        }
    }

    /** @return bool */
    function can_edit_final_paper(PaperInfo $prow) {
        if ($prow->timeWithdrawn > 0
            || $prow->outcome <= 0
            || !$this->conf->allow_final_versions()) {
            return false;
        }
        $rights = $this->rights($prow);
        if (!$rights->allow_author || !$rights->can_view_decision) {
            return false;
        } else if (($v = $rights->perm_tag_value("author-write"))) {
            return $v > 0;
        } else {
            return $this->conf->time_edit_final_paper()
                || $this->override_deadlines($rights);
        }
    }

    /** @return ?PermissionProblem */
    function perm_edit_final_paper(PaperInfo $prow) {
        if ($this->can_edit_final_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author && $rights->allow_author_view) {
            $whyNot["signin"] = "edit_paper";
        } else if (!$rights->allow_author) {
            $whyNot["author"] = true;
        }
        if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        }
        // NB logic order here is important elsewhere
        // Don’t report “rejected” error to admins
        if ($prow->outcome <= 0
            || (!$rights->allow_administer
                && !$rights->can_view_decision)) {
            $whyNot["rejected"] = true;
        } else if (!$this->conf->allow_final_versions()) {
            $whyNot["deadline"] = "final_open";
        } else if (!$this->conf->time_edit_final_paper()
                   && !$this->override_deadlines($rights)) {
            $whyNot["deadline"] = "final_done";
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = true;
        }
        return $whyNot;
    }

    /** @return bool
     * @deprecated */
    function can_submit_final_paper(PaperInfo $prow) {
        return $this->can_edit_final_paper($prow);
    }

    /** @return ?PermissionProblem
     * @deprecated */
    function perm_submit_final_paper(PaperInfo $prow) {
        return $this->perm_edit_final_paper($prow);
    }

    /** @return bool */
    function has_hidden_papers() {
        return $this->hidden_papers !== null
            || ($this->dangerous_track_mask() & Track::BITS_VIEW);
    }

    /** @return bool */
    function can_view_missing_papers() {
        return $this->privChair
            || ($this->isPC && $this->conf->check_all_tracks($this, Track::VIEW));
    }

    /** @return PermissionProblem */
    function no_paper_whynot($pid) {
        $whynot = new PermissionProblem($this->conf, ["paperId" => $pid]);
        if (!ctype_digit((string) $pid)) {
            $whynot["invalidId"] = "paper";
        } else if ($this->can_view_missing_papers()) {
            $whynot["noPaper"] = true;
        } else {
            $whynot["permission"] = "view_paper";
            if ($this->is_empty()) {
                $whynot["signin"] = "view_paper";
            }
        }
        return $whynot;
    }

    /** @return bool */
    function can_view_paper(PaperInfo $prow, $pdf = false) {
        // hidden_papers is set when a chair with a conflicted, managed
        // paper “becomes” a user
        if ($this->hidden_papers !== null
            && isset($this->hidden_papers[$prow->paperId])) {
            $this->hidden_papers[$prow->paperId] = true;
            return false;
        } else if ($this->privChair
                   && !($this->dangerous_track_mask() & Track::BITS_VIEW)) {
            return true;
        }
        $rights = $this->rights($prow);
        return $rights->allow_author_view
            || ($pdf
                // assigned reviewer can view PDF of withdrawn, but submitted, paper
                ? $rights->review_status > PaperContactInfo::RS_DECLINED
                  && $prow->timeSubmitted != 0
                : $rights->review_status > 0)
            || ($rights->allow_pc_broad
                && $this->conf->timePCViewPaper($prow, $pdf)
                && (!$pdf || $this->conf->check_tracks($prow, $this, Track::VIEWPDF)));
    }

    /** @return ?PermissionProblem */
    function perm_view_paper(PaperInfo $prow = null, $pdf = false, $pid = null) {
        if (!$prow) {
            return $this->no_paper_whynot($pid);
        } else if ($this->can_view_paper($prow, $pdf)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        $base_count = count($whyNot);
        if (!$rights->allow_author_view
            && $rights->review_status == 0
            && !$rights->allow_pc_broad) {
            $whyNot["permission"] = "view_paper";
            if ($this->is_empty()) {
                $whyNot["signin"] = "view_paper";
            }
        } else {
            if ($prow->timeWithdrawn > 0) {
                $whyNot["withdrawn"] = true;
            } else if ($prow->timeSubmitted <= 0) {
                $whyNot["notSubmitted"] = true;
            }
            if ($pdf
                && count($whyNot) === $base_count
                && $this->can_view_paper($prow)) {
                $whyNot["permission"] = "view_doc";
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_view_pdf(PaperInfo $prow) {
        return $this->can_view_paper($prow, true);
    }

    /** @return ?PermissionProblem */
    function perm_view_pdf(PaperInfo $prow) {
        return $this->perm_view_paper($prow, true);
    }

    /** @return bool */
    function can_view_some_pdf() {
        return $this->privChair
            || $this->is_author()
            || $this->has_review()
            || ($this->isPC && $this->conf->has_any_pc_visible_pdf());
    }

    /** @return bool */
    function can_view_document_history(PaperInfo $prow) {
        if ($this->privChair) {
            return true;
        }
        $rights = $this->rights($prow);
        return $rights->act_author || $rights->can_administer;
    }

    /** @return bool */
    function can_view_manager(PaperInfo $prow = null) {
        if ($this->privChair) {
            return true;
        } else if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer
                || ($rights->potential_reviewer && !$this->conf->opt("hideManager"));
        } else {
            return (!$this->conf->opt("hideManager") && $this->is_reviewer())
                || ($this->isPC && $this->is_explicit_manager());
        }
    }

    /** @return bool */
    function can_view_lead(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->can_administer
                || $prow->leadContactId === $this->contactXid
                || (($rights->allow_pc || $rights->allow_review)
                    && $this->can_view_review_identity($prow, null));
        } else {
            return $this->isPC;
        }
    }

    /** @return bool */
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
    /** @return 0|1|2 */
    function view_authors_state(PaperInfo $prow) {
        return $this->rights($prow)->view_authors_state;
    }

    /** @return bool */
    function can_view_authors(PaperInfo $prow) {
        $vas = $this->view_authors_state($prow);
        return $vas === 2 || ($vas === 1 && $this->is_admin_force());
    }

    /** @return bool */
    function allow_view_authors(PaperInfo $prow) {
        return $this->view_authors_state($prow) !== 0;
    }

    /** @return bool */
    function can_view_some_authors() {
        return $this->is_manager()
            || $this->is_author()
            || ($this->is_reviewer()
                && ($this->conf->submission_blindness() != Conf::BLIND_ALWAYS
                    || $this->conf->time_reviewer_view_accepted_authors()));
    }

    /** @return bool */
    function can_view_conflicts(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->allow_administer || $rights->act_author_view) {
            return true;
        } else if (!$rights->allow_pc_broad && !$rights->potential_reviewer) {
            return false;
        }
        $pccv = $this->conf->setting("sub_pcconfvis");
        return $pccv == 2
            || (!$pccv
                && ($this->can_view_authors($prow)
                    || ($this->conf->setting("tracker")
                        && MeetingTracker::can_view_tracker_at($this, $prow))));
    }

    /** @return bool */
    function can_view_some_conflicts() {
        return $this->is_manager()
            || $this->is_author()
            || ($this->is_reviewer()
                && (($pccv = $this->conf->setting("sub_pcconfvis")) == 2
                    || (!$pccv
                        && ($this->can_view_some_authors() || $this->conf->setting("tracker")))));
    }

    /** @param PaperOption $opt
     * @return 0|1|2 */
    function view_option_state(PaperInfo $prow, $opt) {
        if (!$this->can_view_paper($prow, $opt->has_document())
            || ($opt->final
                && ($prow->outcome <= 0
                    || $prow->timeSubmitted <= 0
                    || !$this->can_view_decision($prow)))
            || ($opt->exists_condition()
                && !($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS)
                && !$opt->test_exists($prow))) {
            return 0;
        }
        $rights = $this->rights($prow);
        $oview = $opt->visibility;
        if ($rights->allow_administer) {
            if ($oview === "nonblind") {
                return $rights->view_authors_state;
            } else {
                return 2;
            }
        } else if ($rights->act_author_view) {
            return 2;
        } else if ($rights->allow_pc_broad || $rights->review_status > 0) {
            if ($oview === "nonblind") {
                return $rights->view_authors_state;
            } else if ($oview === "conflict") {
                return $this->can_view_conflicts($prow) ? 2 : 0;
            } else {
                return !$oview || $oview === "rev" ? 2 : 0;
            }
        } else {
            return 0;
        }
    }

    /** @param PaperOption $opt
     * @return bool */
    function can_view_option(PaperInfo $prow, $opt) {
        $vos = $this->view_option_state($prow, $opt);
        return $vos === 2 || ($vos === 1 && $this->is_admin_force());
    }

    /** @param PaperOption $opt
     * @return bool*/
    function allow_view_option(PaperInfo $prow, $opt) {
        return $this->view_option_state($prow, $opt) !== 0;
    }

    /** @param PaperOption $opt
     * @return 0|1|2 */
    function edit_option_state(PaperInfo $prow, $opt) {
        if ($opt->form_position() === false
            || !$opt->test_editable($prow)
            || ($opt->id > 0 && !$this->allow_view_option($prow, $opt))
            || ($opt->final && !$this->allow_edit_final_paper($prow))
            || ($opt->id === 0 && $this->allow_edit_final_paper($prow))) {
            return 0;
        } else if (!$opt->test_exists($prow)) {
            return $opt->exists_script_expression($prow) ? 1 : 0;
        } else {
            return 2;
        }
    }

    /** @param PaperOption $opt
     * @return bool */
    function can_edit_option(PaperInfo $prow, $opt) {
        $eos = $this->edit_option_state($prow, $opt);
        return $eos === 2
            || ($eos === 1 && ($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS));
    }

    /** @return array<int,PaperOption> */
    function user_option_list() {
        if ($this->conf->has_any_accepted() && $this->can_view_some_decision()) {
            return $this->conf->options()->normal();
        } else {
            return $this->conf->options()->nonfinal();
        }
    }

    /** @param PaperOption $opt
     * @return ?PermissionProblem */
    function perm_view_option(PaperInfo $prow, $opt) {
        if ($this->can_view_option($prow, $opt)) {
            return null;
        } else if (($whyNot = $this->perm_view_paper($prow, $opt->has_document()))) {
            return $whyNot;
        }
        $whyNot = $prow->make_whynot();
        $rights = $this->rights($prow);
        $oview = $opt->visibility;
        if ($rights->allow_administer
            ? $oview === "nonblind"
              && !$this->can_view_authors($prow)
            : !$rights->act_author_view
              && ($oview === "admin"
                  || ((!$oview || $oview == "rev")
                      && $rights->review_status == 0
                      && !$rights->allow_pc_broad)
                  || ($oview == "nonblind"
                      && !$this->can_view_authors($prow)))) {
            $whyNot["permission"] = "view_option";
            $whyNot["option"] = $opt;
        } else if ($opt->final
                   && ($prow->outcome <= 0
                       || $prow->timeSubmitted <= 0
                       || !$rights->can_view_decision)) {
            $whyNot["optionNotAccepted"] = true;
            $whyNot["option"] = $opt;
        } else {
            $whyNot["permission"] = "view_option";
            $whyNot["option"] = $opt;
        }
        return $whyNot;
    }

    /** @return bool */
    function can_view_some_option(PaperOption $opt) {
        if (($opt->has_document() && !$this->can_view_some_pdf())
            || ($opt->final && !$this->can_view_some_decision())) {
            return false;
        }
        $oview = $opt->visibility;
        return $this->is_author()
            || ($oview == "admin" && $this->is_manager())
            || ((!$oview || $oview == "rev") && $this->is_reviewer())
            || ($oview == "nonblind" && $this->can_view_some_authors());
    }

    /** @return bool */
    function is_my_review(ReviewInfo $rrow = null) {
        return $rrow
            && ($rrow->contactId === $this->contactXid
                || ($this->_review_tokens
                    && $rrow->reviewToken !== 0
                    && in_array($rrow->reviewToken, $this->_review_tokens, true))
                || ($this->_capabilities !== null
                    && ($this->_capabilities["@ra{$rrow->paperId}"] ?? null) == $rrow->contactId));
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function is_owned_review($rbase = null) {
        return $rbase
            && $rbase->contactId > 0
            && ($rbase->contactId === $this->contactXid
                || ($this->_review_tokens
                    && $rbase->reviewToken !== 0
                    && in_array($rbase->reviewToken, $this->_review_tokens, true))
                || ($rbase->requestedBy === $this->contactId
                    && $rbase->reviewType === REVIEW_EXTERNAL
                    && $this->conf->ext_subreviews)
                || ($this->_capabilities !== null
                    && ($this->_capabilities["@ra{$rbase->paperId}"] ?? null) == $rbase->contactId));
    }

    /** @param ?ReviewInfo $rrow
     * @return bool */
    function can_view_review_assignment(PaperInfo $prow, $rrow) {
        if (!$rrow || $rrow->reviewType > 0) {
            $rights = $this->rights($prow);
            return $rights->allow_administer
                || $rights->allow_pc
                || $rights->review_status > 0
                || $this->can_view_review($prow, $rrow);
        } else {
            return $this->can_view_review_identity($prow, $rrow);
        }
    }

    /** @return list<ResponseRound> */
    function relevant_resp_rounds() {
        $rrds = [];
        foreach ($this->conf->resp_rounds() as $rrd) {
            if ($rrd->relevant($this))
                $rrds[] = $rrd;
        }
        return $rrds;
    }

    /** @return bool */
    private function can_view_submitted_review_as_author(PaperInfo $prow) {
        if ($this->conf->has_perm_tags()
            && ($v = $prow->tag_value("perm:author-read-review"))) {
            return $v > 0;
        } else {
            return $prow->can_author_respond()
                || $this->conf->au_seerev == Conf::AUSEEREV_YES
                || ($this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
                    && (!$this->has_review()
                        || !$this->has_outstanding_review()))
                || ($this->conf->au_seerev == Conf::AUSEEREV_TAGS
                    && $prow->has_any_tag($this->conf->tag_au_seerev));
        }
    }

    /** @return bool */
    function can_view_some_review() {
        return $this->is_reviewer()
            || ($this->is_author()
                && ($this->conf->au_seerev !== 0
                    || $this->conf->any_response_open === 2
                    || ($this->conf->any_response_open === 1
                        && !empty($this->relevant_resp_rounds()))
                    || ($this->conf->has_perm_tags()
                        && $this->some_author_perm_tag_allows("author-read-review"))));
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @param PaperContactInfo $rights
     * @return int */
    private function seerev_setting(PaperInfo $prow, $rbase, $rights) {
        $round = $rbase ? $rbase->reviewRound : "max";
        if ($rights->allow_pc) {
            $rs = $this->conf->round_setting("pc_seeallrev", $round);
            if (!$this->conf->has_tracks()) {
                return $rs;
            }
            if ($this->conf->check_tracks($prow, $this, Track::VIEWREV)) {
                if (!$this->conf->check_tracks($prow, $this, Track::VIEWALLREV)) {
                    $rs = 0;
                }
                return $rs;
            }
        } else if ($this->conf->round_setting("extrev_view", $round)) {
            return 0;
        }
        return -1;
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @param PaperContactInfo $rights
     * @return int */
    private function seerevid_setting(PaperInfo $prow, $rbase, $rights) {
        $round = $rbase ? $rbase->reviewRound : "max";
        if ($rights->allow_pc) {
            if ($this->conf->check_tracks($prow, $this, Track::VIEWREVID)) {
                $s = $this->conf->round_setting("pc_seeblindrev", $round);
                if ($s >= 0) {
                    return $s ? 0 : Conf::PCSEEREV_YES;
                }
            }
        } else if ($this->conf->round_setting("extrev_view", $round) == 2) {
            return 0;
        }
        return -1;
    }

    /** @param ?ReviewInfo $rrow
     * @param ?int $viewscore
     * @return bool */
    function can_view_review(PaperInfo $prow, $rrow, $viewscore = null) {
        if (is_int($rrow)) {
            $viewscore = $rrow;
            $rrow = null;
        } else if ($viewscore === null) {
            $viewscore = VIEWSCORE_AUTHOR;
        }
        assert(!$rrow || $prow->paperId == $rrow->paperId);
        $rights = $this->rights($prow);
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREV)
            || $rights->reviewType == REVIEW_META
            || ($rrow
                && $this->is_owned_review($rrow)
                && $viewscore >= VIEWSCORE_REVIEWERONLY)) {
            return true;
        } else if ($rrow && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
            return false;
        }
        $seerev = $this->seerev_setting($prow, $rrow, $rights);
        if ($rrow) {
            $viewscore = min($viewscore, $rrow->reviewViewScore);
        }
        // See also PaperInfo::can_view_review_identity_of.
        return ($rights->act_author_view
                && ($viewscore >= VIEWSCORE_AUTHOR
                    || ($viewscore >= VIEWSCORE_AUTHORDEC
                        && $prow->outcome
                        && $rights->can_view_decision))
                && $this->can_view_submitted_review_as_author($prow))
            || ($rights->allow_pc
                && $viewscore >= VIEWSCORE_PC
                && $seerev > 0
                && ($seerev !== Conf::PCSEEREV_UNLESSANYINCOMPLETE
                    || !$this->has_outstanding_review())
                && ($seerev !== Conf::PCSEEREV_UNLESSINCOMPLETE
                    || $rights->review_status == 0))
            || ($rights->review_status > 0
                && !$rights->view_conflict_type
                && $viewscore >= VIEWSCORE_PC
                && $prow->review_not_incomplete($this)
                && $seerev >= 0);
    }

    /** @param ?ReviewInfo $rrow
     * @param ?int $viewscore
     * @return ?PermissionProblem */
    function perm_view_review(PaperInfo $prow, $rrow, $viewscore = null) {
        if ($this->can_view_review($prow, $rrow, $viewscore)) {
            return null;
        }
        $rrowSubmitted = !$rrow || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED;
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ((!$rights->act_author_view
             && !$rights->allow_pc
             && $rights->review_status == 0)
            || ($rights->allow_pc
                && !$this->conf->check_tracks($prow, $this, Track::VIEWREV))) {
            $whyNot["permission"] = "view_review";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else if ($rights->act_author_view
                   && $this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE
                   && $this->has_outstanding_review()
                   && $this->has_review()) {
            $whyNot["reviewsOutstanding"] = true;
        } else if ($rights->act_author_view
                   && !$rrowSubmitted) {
            $whyNot["permission"] = "view_review";
        } else if ($rights->act_author_view) {
            $whyNot["deadline"] = "au_seerev";
        } else if ($rights->view_conflict_type) {
            $whyNot["conflict"] = true;
        } else if ($rights->reviewType === REVIEW_EXTERNAL
                   && $this->seerev_setting($prow, $rrow, $rights) < 0) {
            $whyNot["externalReviewer"] = true;
        } else if (!$rrowSubmitted) {
            $whyNot["reviewNotSubmitted"] = true;
        } else if ($rights->allow_pc
                   && $this->seerev_setting($prow, $rrow, $rights) == Conf::PCSEEREV_UNLESSANYINCOMPLETE
                   && $this->has_outstanding_review()) {
            $whyNot["reviewsOutstanding"] = true;
        } else if (!$this->conf->time_review_open()) {
            $whyNot["deadline"] = "rev_open";
        } else {
            $whyNot["reviewNotComplete"] = true;
        }
        if ($rights->allow_administer) {
            $whyNot["forceShow"] = true;
        }
        return $whyNot;
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function can_view_review_identity(PaperInfo $prow, $rbase = null) {
        $rights = $this->rights($prow);
        // See also PaperInfo::can_view_review_identity_of.
        // See also ReviewerFexpr.
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || $rights->reviewType == REVIEW_META
            || ($rbase && $rbase->requestedBy == $this->contactId && $rights->allow_pc)
            || ($rbase && $this->is_owned_review($rbase))) {
            return true;
        }
        $seerevid_setting = $this->seerevid_setting($prow, $rbase, $rights);
        return ($rights->allow_pc
                && $seerevid_setting == Conf::PCSEEREV_YES)
            || ($rights->allow_review
                && $prow->review_not_incomplete($this)
                && $seerevid_setting >= 0)
            || !$this->conf->is_review_blind($rbase);
    }

    /** @return bool */
    function can_view_some_review_identity() {
        $tags = "";
        if (($t = $this->conf->permissive_track_tag_for($this, Track::VIEWREVID))) {
            $tags = " $t#0 ";
        }
        if ($this->isPC) {
            $rtype = $this->is_metareviewer() ? REVIEW_META : REVIEW_PC;
        } else {
            $rtype = $this->is_reviewer() ? REVIEW_EXTERNAL : 0;
        }
        $prow = new PaperInfo([
            "conflictType" => 0, "managerContactId" => 0,
            "myReviewPermissions" => "$rtype 1 0",
            "paperId" => 1, "timeSubmitted" => 1,
            "blind" => "0", "outcome" => 1,
            "paperTags" => $tags
        ], $this);
        $overrides = $this->add_overrides(self::OVERRIDE_CONFLICT);
        $answer = $this->can_view_review_identity($prow, null);
        $this->set_overrides($overrides);
        return $answer;
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function can_view_review_round(PaperInfo $prow, $rbase = null) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            || $rights->allow_pc
            || $rights->allow_review;
    }

    /** @return bool */
    function can_view_review_time(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        return !$rights->act_author_view
            || ($rrow
                && $rrow->reviewAuthorSeen
                && $rrow->reviewAuthorSeen <= $rrow->reviewAuthorModified);
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function can_view_review_requester(PaperInfo $prow, $rbase = null) {
        $rights = $this->rights($prow);
        return $this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($rbase && $rbase->requestedBy == $this->contactId && $rights->allow_pc)
            || ($rbase && $this->is_owned_review($rbase))
            || ($rights->allow_pc && $this->can_view_review_identity($prow, $rbase));
    }

    /** @return bool */
    function can_request_review(PaperInfo $prow, $round, $check_time) {
        $rights = $this->rights($prow);
        return ($rights->allow_administer
                || (($rights->reviewType >= REVIEW_PC
                     || ($this->isPC
                         && $prow->leadContactId === $this->contactXid))
                    && $this->conf->setting("extrev_chairreq", 0) >= 0))
            && (!$check_time
                || $this->conf->time_review($round, false, true)
                || $this->override_deadlines($rights));
    }

    /** @return ?PermissionProblem */
    function perm_request_review(PaperInfo $prow, $round, $check_time) {
        if ($this->can_request_review($prow, $round, $check_time)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer
            && (($rights->reviewType < REVIEW_PC
                 && (!$this->isPC
                     || $prow->leadContactId !== $this->contactXid))
                || $this->conf->setting("extrev_chairreq", 0) < 0)) {
            $whyNot["permission"] = "request_review";
        } else {
            $whyNot["deadline"] = "extrev_chairreq";
            $whyNot["reviewRound"] = $round;
            if ($rights->allow_administer) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_review_any() {
        return $this->isPC
            && $this->conf->setting("pcrev_any") > 0
            && $this->conf->time_review(null, true, true)
            && $this->conf->check_any_tracks($this, Track::ASSREV)
            && $this->conf->check_any_tracks($this, Track::UNASSREV);
    }

    function timeReview(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        if ($rights->reviewType > 0
            || ($rrow
                && $this->is_owned_review($rrow))
            || ($rrow
                && $rrow->contactId != $this->contactId
                && $rights->allow_administer)) {
            return $this->conf->time_review($rrow, $rights->allow_pc, true);
        } else if ($rights->allow_review
                   && $this->conf->setting("pcrev_any") > 0) {
            return $this->conf->time_review(null, true, true);
        } else {
            return false;
        }
    }

    /** @return bool */
    function can_become_reviewer(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_review
                || ($rights->allow_pc
                    && $this->conf->check_tracks($prow, $this, Track::ASSREV));
        } else {
            return $this->isPC
                && $this->conf->check_all_tracks($this, Track::ASSREV);
        }
    }

    /** @return bool */
    function can_become_reviewer_ignore_conflict(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->potential_reviewer
                || ($rights->allow_pc_broad
                    && $this->conf->check_tracks($prow, $this, Track::ASSREV));
        } else {
            return $this->isPC
                && $this->conf->check_all_tracks($this, Track::ASSREV);
        }
    }

    /** @return bool */
    function allow_view_preference(PaperInfo $prow = null, $aggregate = false) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $aggregate
                ? $rights->allow_pc && $this->can_view_pc()
                : $rights->allow_administer;
        } else {
            return $this->is_manager();
        }
    }

    /** @return bool */
    function can_view_preference(PaperInfo $prow = null, $aggregate = false) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $aggregate
                ? $rights->allow_pc && $this->can_view_pc()
                : $rights->can_administer;
        } else {
            return $this->is_manager();
        }
    }

    /** @return bool */
    function can_enter_preference(PaperInfo $prow, $submit = false) {
        return $this->isPC
            && ($submit
                ? $this->can_become_reviewer_ignore_conflict($prow)
                : $this->can_become_reviewer($prow))
            && ($this->can_view_paper($prow)
                || ($prow->timeWithdrawn > 0
                    && ($prow->timeSubmitted < 0
                        || $this->conf->can_pc_see_active_submissions())));
    }

    /** @return bool */
    function can_accept_review_assignment_ignore_conflict(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return ($rights->allow_administer
                    || $this->isPC)
                && ($rights->reviewType > 0
                    || $rights->allow_administer
                    || $this->conf->check_tracks($prow, $this, Track::ASSREV));
        } else {
            return $this->isPC
                && $this->conf->check_all_tracks($this, Track::ASSREV);
        }
    }

    /** @return bool */
    function can_accept_review_assignment(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return ($rights->allow_pc
                || ($this->isPC && $rights->conflictType <= CONFLICT_MAXUNCONFLICTED))
            && ($rights->reviewType > 0
                || $rights->allow_administer
                || $this->conf->check_tracks($prow, $this, Track::ASSREV));
    }

    /** @param PaperContactInfo $rights
     * @param ?ReviewInfo $rrow
     * @return bool */
    private function rights_owned_review($rights, $rrow) {
        if ($rrow) {
            return $rights->can_administer || $this->is_owned_review($rrow);
        } else {
            return $rights->reviewType > 0;
        }
    }

    /** @return bool */
    function can_review(PaperInfo $prow, ReviewInfo $rrow = null, $submit = false) {
        assert(!$rrow || $rrow->paperId == $prow->paperId);
        $rights = $this->rights($prow);
        if ($submit && !$this->can_clickthrough("review", $prow)) {
            return false;
        }
        return ($this->rights_owned_review($rights, $rrow)
                && $this->conf->time_review($rrow, $rights->allow_pc, true))
            || (!$rrow
                && $prow->timeSubmitted > 0
                && $rights->allow_review
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review(null, true, true))
            || ($rights->can_administer
                && $rights->potential_reviewer /* true unless track perm */
                && (($prow->timeSubmitted > 0 && !$submit)
                    || $this->override_deadlines($rights)));
    }

    /** @param ?ReviewInfo $rrow
     * @return ?PermissionProblem */
    function perm_review(PaperInfo $prow, $rrow, $submit = false) {
        if ($this->can_review($prow, $rrow, $submit)) {
            return null;
        }
        $rights = $this->rights($prow);
        $rrow_cid = $rrow ? $rrow->contactId : 0;
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        $whyNot = $prow->make_whynot();
        if ($rrow && $rrow_cid != $this->contactId
            && !$rights->allow_administer) {
            $whyNot["differentReviewer"] = true;
        } else if (!$rights->allow_pc && !$this->rights_owned_review($rights, $rrow)) {
            $whyNot["permission"] = "review";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else {
            if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED && !$rights->can_administer) {
                $whyNot["conflict"] = true;
            } else if ($rights->allow_review
                       && !$this->rights_owned_review($rights, $rrow)
                       && (!$rrow || $rrow_cid == $this->contactId)) {
                $whyNot["reviewNotAssigned"] = true;
            } else if ($this->can_review($prow, $rrow, false)
                       && !$this->can_clickthrough("review", $prow)) {
                $whyNot["clickthrough"] = true;
            } else {
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            }
            if ($rights->allow_administer
                && ($rights->conflictType > CONFLICT_MAXUNCONFLICTED || $prow->timeSubmitted <= 0)) {
                $whyNot["forceShow"] = true;
            }
            if ($rights->allow_administer && isset($whyNot["deadline"])) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @param ?ReviewInfo $rrow
     * @return ?PermissionProblem */
    function perm_submit_review(PaperInfo $prow, $rrow) {
        return $this->perm_review($prow, $rrow, true);
    }

    /** @return bool */
    function can_approve_review(PaperInfo $prow, ReviewInfo $rrow) {
        $rights = $this->rights($prow);
        return ($prow->timeSubmitted > 0 || $this->override_deadlines($rights))
            && $rrow->subject_to_approval()
            && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED
            && ($rights->can_administer
                || ($this->isPC && $rrow->requestedBy === $this->contactXid))
            && ($this->conf->time_review(null, true, true) || $this->override_deadlines($rights));
    }

    /** @return bool */
    function can_create_review_from(PaperInfo $prow, Contact $user) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            && ($prow->timeSubmitted > 0 || $this->override_deadlines($rights))
            && (!$user->isPC || $user->can_accept_review_assignment($prow))
            && ($this->conf->time_review(null, true, true) || $this->override_deadlines($rights));
    }

    /** @return ?PermissionProblem */
    function perm_create_review_from(PaperInfo $prow, Contact $user) {
        if ($this->can_create_review_from($prow, $user)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer) {
            $whyNot["administer"] = true;
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else {
            if ($user->isPC && !$user->can_accept_review_assignment($prow)) {
                $whyNot["unacceptableReviewer"] = true;
            }
            if (!$this->conf->time_review(null, true, true)) {
                $whyNot["deadline"] = ($user->isPC ? "pcrev_hard" : "extrev_hard");
            }
            if ($rights->allow_administer
                && ($rights->conflictType > CONFLICT_MAXUNCONFLICTED || $prow->timeSubmitted <= 0)) {
                $whyNot["forceShow"] = true;
            }
            if ($rights->allow_administer && isset($whyNot["deadline"])) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_clickthrough($ctype, PaperInfo $prow = null) {
        if ($this->privChair || !$this->conf->opt("clickthrough_$ctype"))  {
            return true;
        }
        $csha1 = sha1($this->conf->_i("clickthrough_$ctype"));
        $data = $this->data("clickthrough");
        return ($data && ($data->$csha1 ?? null))
            || ($prow
                && $ctype === "review"
                && $this->_capabilities !== null
                && ($user = $this->reviewer_capability_user($prow->paperId))
                && $user->can_clickthrough($ctype, $prow));
    }

    /** @return bool */
    function can_view_review_ratings(PaperInfo $prow, ReviewInfo $rrow = null, $override_self = false) {
        $rs = $this->conf->setting("rev_ratings");
        $rights = $this->rights($prow);
        if (!$this->can_view_review($prow, $rrow)
            || (!$rights->allow_pc && !$rights->allow_review)
            || ($rs != REV_RATINGS_PC && $rs != REV_RATINGS_PC_EXTERNAL)) {
            return false;
        }
        if (!$rrow
            || $override_self
            || $rrow->contactId != $this->contactId
            || $this->can_administer($prow)
            || $this->conf->setting("pc_seeallrev")
            || $rrow->has_multiple_ratings()) {
            return true;
        }
        // Do not show rating counts if rater identity is unambiguous.
        // See also PaperSearch::unusable_ratings.
        $nsubraters = 0;
        foreach ($prow->reviews_by_id() as $rrow) {
            if ($rrow->reviewNeedsSubmit == 0
                && $rrow->contactId != $this->contactId
                && ($rs == REV_RATINGS_PC_EXTERNAL
                    || ($rs == REV_RATINGS_PC && $rrow->reviewType > REVIEW_EXTERNAL)))
                ++$nsubraters;
        }
        return $nsubraters >= 2;
    }

    /** @return bool */
    function can_view_some_review_ratings() {
        $rs = $this->conf->setting("rev_ratings");
        return $this->is_reviewer() && ($rs == REV_RATINGS_PC || $rs == REV_RATINGS_PC_EXTERNAL);
    }

    /** @param ?ReviewInfo $rrow
     * @return bool */
    function can_rate_review(PaperInfo $prow, $rrow) {
        return $this->can_view_review_ratings($prow, $rrow, true)
            && !$this->is_my_review($rrow);
    }


    /** @param ?CommentInfo $crow
     * @return bool */
    function is_my_comment(PaperInfo $prow, $crow) {
        if ($crow->contactId === $this->contactXid
            || (!$this->contactId
                && $this->capability("@ra{$prow->paperId}") == $crow->contactId)) {
            return true;
        }
        if ($this->_review_tokens) {
            foreach ($prow->reviews_of_user($crow->contactId) as $rrow) {
                if ($rrow->reviewToken !== 0
                    && in_array($rrow->reviewToken, $this->_review_tokens, true))
                    return true;
            }
        }
        return false;
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_comment(PaperInfo $prow, $crow, $submit = false) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE)) {
            return $this->can_respond($prow, $crow, $submit);
        }
        $rights = $this->rights($prow);
        $author = $rights->act_author
            && $this->conf->setting("cmt_author") > 0
            && $this->can_view_submitted_review_as_author($prow);
        return ($author
                || ($rights->allow_review
                    && ($prow->timeSubmitted > 0
                        || $rights->review_status > 0
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

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_finalize_comment(PaperInfo $prow, $crow) {
        return $crow
            && ($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT)) === (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT)
            && ($rrd = ($prow->conf->resp_rounds())[$crow->commentRound] ?? null)
            && $rrd->open > 0
            && $rrd->open < Conf::$now
            && $prow->conf->setting("resp_active") > 0;
    }

    /** @param ?CommentInfo $crow
     * @return ?PermissionProblem */
    function perm_comment(PaperInfo $prow, $crow, $submit = false) {
        if ($crow && ($crow->commentType & COMMENTTYPE_RESPONSE)) {
            return $this->perm_respond($prow, $crow, $submit);
        } else if ($this->can_comment($prow, $crow, $submit)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ($crow
            && $crow->contactId !== $this->contactXid
            && !$rights->allow_administer) {
            $whyNot["differentReviewer"] = true;
        } else if (!$rights->allow_pc
                   && !$rights->allow_review
                   && (!$rights->act_author
                       || $this->conf->setting("cmt_author", 0) <= 0)) {
            $whyNot["permission"] = "comment";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else {
            if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
                $whyNot["conflict"] = true;
            } else {
                $whyNot["deadline"] = ($rights->allow_pc ? "pcrev_hard" : "extrev_hard");
            }
            if ($rights->allow_administer && $rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
                $whyNot["forceShow"] = true;
            }
            if ($rights->allow_administer && isset($whyNot['deadline'])) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_respond(PaperInfo $prow, CommentInfo $crow, $submit = false) {
        if ($prow->timeSubmitted <= 0
            || !($crow->commentType & COMMENTTYPE_RESPONSE)
            || !($rrd = ($prow->conf->resp_rounds())[$crow->commentRound] ?? null)) {
            return false;
        }
        $rights = $this->rights($prow);
        return ($rights->can_administer
                || $rights->act_author)
            && (($rights->allow_administer
                 && (!$submit || $this->override_deadlines($rights)))
                || $rrd->time_allowed(true)
                || ($submit === 2 && $this->can_finalize_comment($prow, $crow)))
            && (!$rrd->search
                || $rrd->search->test($prow));
    }

    /** @return ?PermissionProblem */
    function perm_respond(PaperInfo $prow, CommentInfo $crow, $submit = false) {
        if ($this->can_respond($prow, $crow, $submit)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer
            && !$rights->act_author) {
            $whyNot["permission"] = "respond";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else {
            $whyNot["deadline"] = "resp_done";
            if ($crow->commentRound) {
                $whyNot["deadline"] .= "_" . $crow->commentRound;
            }
            if ($rights->allow_administer && $rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
                $whyNot["forceShow"] = true;
            }
            if ($rights->allow_administer) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return int|false */
    function preferred_resp_round_number(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->act_author) {
            foreach ($prow->conf->resp_rounds() as $rrd) {
                if ($rrd->time_allowed(true))
                    return $rrd->number;
            }
        }
        return false;
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment(PaperInfo $prow, $crow, $textless = false) {
        $ctype = $crow ? $crow->commentType : COMMENTTYPE_AUTHOR;
        $rights = $this->rights($prow);
        return ($crow && $this->is_my_comment($prow, $crow))
            || ($rights->can_administer
                && ($ctype >= COMMENTTYPE_AUTHOR
                    || $rights->potential_reviewer))
            || ($rights->act_author_view
                && (($ctype & (COMMENTTYPE_BYAUTHOR | COMMENTTYPE_RESPONSE))
                    || ($ctype >= COMMENTTYPE_AUTHOR
                        && !($ctype & COMMENTTYPE_DRAFT)
                        && $this->can_view_submitted_review_as_author($prow))))
            || (!$rights->view_conflict_type
                && (!($ctype & COMMENTTYPE_DRAFT)
                    || ($textless && ($ctype & COMMENTTYPE_RESPONSE)))
                && ($rights->allow_pc
                    ? $ctype >= COMMENTTYPE_PCONLY
                    : $ctype >= COMMENTTYPE_REVIEWER)
                && $this->can_view_review($prow, null)
                && ($ctype >= COMMENTTYPE_AUTHOR
                    || $this->conf->setting("cmt_revid")
                    || $this->can_view_review_identity($prow, null)));
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment_text(PaperInfo $prow, $crow) {
        // assume can_view_comment is true
        if (!$crow
            || ($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT)) !== (COMMENTTYPE_RESPONSE | COMMENTTYPE_DRAFT)) {
            return true;
        }
        $rights = $this->rights($prow);
        return $rights->can_administer || $rights->act_author_view;
    }

    /** @return bool */
    function can_view_new_comment_ignore_conflict(PaperInfo $prow) {
        // Goal: Return true if this user is part of the comment mention
        // completion for a new comment on $prow.
        // Problem: If authors are hidden, should we mention this user or not?
        $rights = $this->rights($prow);
        return $rights->can_administer
            || $rights->allow_pc;
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment_identity(PaperInfo $prow, $crow) {
        if ($crow && ($crow->commentType & (COMMENTTYPE_RESPONSE | COMMENTTYPE_BYAUTHOR))) {
            return $this->can_view_authors($prow);
        }
        $rights = $this->rights($prow);
        return $this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($crow && $crow->contactId === $this->contactXid)
            || (($rights->allow_pc
                 || ($rights->allow_review
                     && $this->conf->setting("extrev_view") >= 2))
                && ($this->can_view_review_identity($prow, null)
                    || ($crow && $prow->can_view_review_identity_of($crow->commentId, $this))))
            || !$this->conf->is_review_blind(!$crow || ($crow->commentType & COMMENTTYPE_BLIND) != 0);
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment_time(PaperInfo $prow, $crow) {
        return $this->can_view_comment_identity($prow, $crow);
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment_tags(PaperInfo $prow, $crow) {
        $rights = $this->rights($prow);
        return $rights->allow_pc || $rights->review_status > 0;
    }

    /** @return bool */
    function can_view_some_draft_response() {
        return $this->is_manager() || $this->is_author();
    }


    /** @return bool */
    function can_view_decision(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->can_view_decision;
    }

    /** @return bool */
    function can_view_some_decision() {
        return $this->is_manager()
            || ($this->is_author() && $this->can_view_some_decision_as_author())
            || ($this->isPC && $this->conf->time_pc_view_decision(false))
            || ($this->is_reviewer() && $this->conf->time_reviewer_view_decision());
    }

    /** @return bool */
    function can_view_some_decision_as_author() {
        return $this->conf->can_some_author_view_decision();
    }

    /** @return bool */
    function can_set_decision(PaperInfo $prow) {
        return $this->can_administer($prow);
    }

    /** @return bool */
    function can_set_some_decision() {
        return $this->is_manager();
    }

    /** @return bool */
    function can_view_formula(Formula $formula) {
        return $formula->view_score($this) > $this->permissive_view_score_bound();
    }

    /** @return bool */
    function can_edit_formula(Formula $formula) {
        return $this->privChair || ($this->isPC && $formula->createdBy > 0);
    }

    // A review field is visible only if its view_score > view_score_bound.
    /** @return int */
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
        if ($rights->can_administer) {
            return VIEWSCORE_ADMINONLY - 1;
        } else if ($rrow ? $this->is_owned_review($rrow) : $rights->allow_review) {
            return VIEWSCORE_REVIEWERONLY - 1;
        } else if (!$this->can_view_review($prow, $rrow)) {
            return VIEWSCORE_EMPTYBOUND;
        } else if ($rights->act_author_view
                   && $prow->outcome
                   && $rights->can_view_decision) {
            return VIEWSCORE_AUTHORDEC - 1;
        } else if ($rights->act_author_view) {
            return VIEWSCORE_AUTHOR - 1;
        } else {
            return VIEWSCORE_PC - 1;
        }
    }

    /** @return int */
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
            return VIEWSCORE_EMPTYBOUND;
        }
    }


    /** @return bool */
    function can_view_tags(PaperInfo $prow = null) {
        // see also AllTags_API::alltags, PaperInfo::{searchable,viewable}_tags
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_pc
                || ($rights->allow_pc_broad && $this->conf->tag_seeall)
                || ($this->privChair && $this->conf->tags()->has_sitewide);
        } else {
            return $this->isPC;
        }
    }

    /** @return bool */
    function can_view_most_tags(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_pc
                || ($rights->allow_pc_broad && $this->conf->tag_seeall);
        } else {
            return $this->isPC;
        }
    }

    /** @return bool */
    function can_view_hidden_tags(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->can_administer
                || $this->conf->check_required_tracks($prow, $this, Track::HIDDENTAG);
        } else {
            return $this->privChair;
        }
    }

    /** @param string $tag
     * @return bool */
    function can_view_tag(PaperInfo $prow = null, $tag) {
        // basic checks
        if (!$this->isPC) {
            return false;
        } else if ($this->_overrides & self::OVERRIDE_TAG_CHECKS) {
            return true;
        }

        // conflict checks
        $tag = Tagger::base($tag);
        $dt = $this->conf->tags();
        if ($prow) {
            $rights = $this->rights($prow);
            if (!($rights->allow_pc
                  || ($rights->allow_pc_broad && $this->conf->tag_seeall)
                  || ($this->privChair && $dt->is_sitewide($tag)))) {
                return false;
            }
            $allow_administer = $rights->allow_administer;
        } else {
            $allow_administer = $this->privChair;
        }

        // twiddle and hidden-tag checks
        $twiddle = strpos($tag, "~");
        return ($allow_administer
                || $twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0
                    && (substr($tag, 0, $twiddle) == $this->contactId
                        || $dt->is_public_peruser(substr($tag, $twiddle + 1)))))
            && ($twiddle !== false
                || !$dt->has_hidden
                || !$dt->is_hidden($tag)
                || $this->can_view_hidden_tags($prow));
    }

    /** @param string $tag
     * @return bool */
    function can_view_peruser_tag(PaperInfo $prow = null, $tag) {
        if ($prow) {
            return $this->can_view_tag($prow, ($this->contactId + 1) . "~$tag");
        } else {
            return $this->is_manager()
                || ($this->isPC && $this->conf->tags()->is_public_peruser($tag));
        }
    }

    /** @return bool */
    function can_view_some_peruser_tag() {
        return $this->is_manager()
            || ($this->isPC && $this->conf->tags()->has_public_peruser);
    }

    /** @param string $tag
     * @return bool */
    function can_change_tag(PaperInfo $prow, $tag, $previndex, $index) {
        assert(!!$tag);
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->is_site_contact) {
            return true;
        }
        $rights = $this->rights($prow);
        $tagmap = $this->conf->tags();
        if (!($rights->allow_pc
              && ($rights->can_administer || $this->conf->timePCViewPaper($prow, false)))) {
            if ($this->privChair && $tagmap->has_sitewide) {
                $dt = $tagmap->check($tag);
                return $dt && $dt->sitewide && !$dt->automatic;
            } else {
                return false;
            }
        }
        $tag = Tagger::base($tag);
        $twiddle = strpos($tag, "~");
        if ($twiddle === 0 && $tag[1] === "~") {
            if (!$rights->can_administer) {
                return false;
            } else if (!$tagmap->has_automatic) {
                return true;
            } else {
                $dt = $tagmap->check($tag);
                return !$dt || !$dt->automatic;
            }
        }
        if ($twiddle > 0
            && substr($tag, 0, $twiddle) != $this->contactId
            && !$rights->can_administer) {
            return false;
        }
        if ($twiddle !== false) {
            $t = $this->conf->tags()->check(substr($tag, $twiddle + 1));
            return !($t && $t->allotment && $index < 0);
        } else {
            $t = $this->conf->tags()->check($tag);
            if (!$t) {
                return true;
            } else if ($t->automatic
                       || ($t->track && !$this->privChair)
                       || ($t->hidden && !$this->can_view_hidden_tags($prow))) {
                return false;
            } else {
                return $rights->can_administer
                    || ($this->privChair && $t->sitewide)
                    || (!$t->readonly && !$t->rank);
            }
        }
    }

    /** @param string $tag
     * @return ?PermissionProblem */
    function perm_change_tag(PaperInfo $prow, $tag, $previndex, $index) {
        if ($this->can_change_tag($prow, $tag, $previndex, $index)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        $whyNot["tag"] = $tag;
        if (!$this->isPC) {
            $whyNot["permission"] = "change_tag";
        } else if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
            $whyNot["conflict"] = true;
            if ($rights->allow_administer) {
                $whyNot["forceShow"] = true;
            }
        } else if (!$this->conf->timePCViewPaper($prow, false)) {
            if ($prow->timeWithdrawn > 0) {
                $whyNot["withdrawn"] = true;
            } else {
                $whyNot["notSubmitted"] = true;
            }
        } else {
            $tag = Tagger::base($tag);
            $twiddle = strpos($tag, "~");
            if ($twiddle === 0 && $tag[1] === "~") {
                $whyNot["chairTag"] = true;
            } else if ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId) {
                $whyNot["otherTwiddleTag"] = true;
            } else if ($twiddle !== false) {
                $whyNot["voteTagNegative"] = true;
            } else {
                $t = $this->conf->tags()->check($tag);
                if ($t && $t->votish) {
                    $whyNot["voteTag"] = true;
                } else if ($t && $t->automatic) {
                    $whyNot["autosearchTag"] = true;
                } else {
                    $whyNot["chairTag"] = true;
                }
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_change_some_tag(PaperInfo $prow = null) {
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->is_site_contact) {
            return true;
        } else if ($prow) {
            $rights = $this->rights($prow);
            return ($rights->allow_pc
                    && ($rights->can_administer || $this->conf->timePCViewPaper($prow, false)))
                || ($this->privChair && $this->conf->tags()->has_sitewide);
        } else {
            return $this->isPC;
        }
    }

    /** @return ?PermissionProblem */
    function perm_change_some_tag(PaperInfo $prow) {
        if ($this->can_change_some_tag($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$this->isPC) {
            $whyNot["permission"] = "change_tag";
        } else if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
            $whyNot["conflict"] = true;
        } else if ($prow->timeWithdrawn > 0)  {
            $whyNot["withdrawn"] = true;
        } else {
            $whyNot["notSubmitted"] = true;
        }
        if ($rights->allow_administer) {
            $whyNot["forceShow"] = true;
        }
        return $whyNot;
    }

    /** @return bool */
    function can_change_most_tags(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_pc
                   && ($rights->can_administer || $this->conf->timePCViewPaper($prow, false));
        } else {
            return $this->isPC;
        }
    }

    /** @return bool */
    function can_change_tag_anno($tag) {
        if ($this->privChair) {
            return true;
        }
        $twiddle = strpos($tag, "~");
        $t = $this->conf->tags()->check($tag);
        return $this->isPC
            && (!$t || (!$t->readonly && !$t->hidden))
            && ($twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0 && substr($tag, 0, $twiddle) == $this->contactId));
    }


    /** @return non-empty-list<AuthorMatcher> */
    function aucollab_matchers() {
        if ($this->_aucollab_matchers === null) {
            $this->_aucollab_matchers = [new AuthorMatcher($this)];
            foreach ($this->collaborator_generator() as $m) {
                $this->_aucollab_matchers[] = $m;
            }
        }
        return $this->_aucollab_matchers;
    }

    /** @return TextPregexes|false */
    function aucollab_general_pregexes() {
        if ($this->_aucollab_general_pregexes === null) {
            $l = [];
            foreach ($this->aucollab_matchers() as $matcher) {
                if (($r = $matcher->general_pregexes()))
                    $l[] = $r;
            }
            $this->_aucollab_general_pregexes = Text::merge_pregexes($l);
        }
        return $this->_aucollab_general_pregexes;
    }

    /** @return AuthorMatcher */
    function full_matcher() {
        return ($this->aucollab_matchers())[0];
    }

    /** @return ?TextPregexes */
    function au_general_pregexes() {
        return $this->full_matcher()->general_pregexes();
    }


    // following / email notifications

    /** @param int $watch
     * @return bool */
    function following_reviews(PaperInfo $prow, $watch) {
        if ($watch & self::WATCH_REVIEW_EXPLICIT) {
            return ($watch & self::WATCH_REVIEW) !== 0;
        } else {
            return ($this->defaultWatch & self::WATCH_REVIEW_ALL)
                || (($this->defaultWatch & self::WATCH_REVIEW_MANAGED)
                    && $this->is_primary_administrator($prow))
                || (($this->defaultWatch & self::WATCH_REVIEW)
                    && ($prow->has_author($this)
                        || $prow->has_reviewer($this)
                        || $prow->has_commenter($this)));
        }
    }


    // deadlines

    /** @param ?list<PaperInfo> $prows */
    function my_deadlines($prows = null) {
        // Return cleaned deadline-relevant settings that this user can see.
        $dl = (object) ["now" => Conf::$now, "email" => $this->email ? : null];
        if ($this->privChair) {
            $dl->is_admin = true;
        } else if ($this->is_track_manager()) {
            $dl->is_track_admin = true;
        }
        if ($this->is_author()) {
            $dl->is_author = true;
        }
        $dl->sub = (object) [];
        $graces = [];

        // submissions
        $sub_reg = $this->conf->setting("sub_reg");
        $sub_update = $this->conf->setting("sub_update");
        $sub_sub = $this->conf->setting("sub_sub");
        $dl->sub->open = +$this->conf->setting("sub_open") > 0;
        $dl->sub->sub = +$sub_sub;
        $sub_graces = [];
        if ($sub_reg
            && (!$sub_update || $sub_reg < $sub_update)) {
            $dl->sub->reg = $sub_reg;
            $sub_graces[] = "reg";
        }
        if ($sub_update
            && $sub_update != $sub_sub) {
            $dl->sub->update = $sub_update;
            $sub_graces[] = "update";
        }
        if ($dl->sub->open
            && ($g = $this->conf->setting("sub_grace"))) {
            $sub_graces[] = "sub";
            array_push($graces, $dl->sub, $g, $sub_graces);
        }

        $sb = $this->conf->submission_blindness();
        if ($sb === Conf::BLIND_ALWAYS) {
            $dl->sub->blind = true;
        } else if ($sb === Conf::BLIND_OPTIONAL) {
            $dl->sub->blind = "optional";
        } else if ($sb === Conf::BLIND_UNTILREVIEW) {
            $dl->sub->blind = "until-review";
        }

        // responses
        if ($this->conf->setting("resp_active") > 0
            && ($this->isPC || $this->is_author())) {
            $dlresps = [];
            foreach ($this->relevant_resp_rounds() as $rrd) {
                $dlresp = (object) ["open" => $rrd->open, "done" => +$rrd->done];
                $dlresps[$rrd->name] = $dlresp;
                if ($rrd->grace) {
                    array_push($graces, $dlresp, $rrd->grace, ["done"]);
                }
            }
            if (!empty($dlresps)) {
                $dl->resps = $dlresps;
            }
        }

        // final copy deadlines
        if ($this->conf->setting("final_open") > 0) {
            $dl->final = (object) array("open" => true);
            $final_soft = +$this->conf->setting("final_soft");
            if ($final_soft > Conf::$now) {
                $dl->final->done = $final_soft;
            } else {
                $dl->final->done = +$this->conf->setting("final_done");
                $dl->final->ishard = true;
            }
            if (($g = $this->conf->setting("final_grace"))) {
                array_push($graces, $dl->final, $g, ["done"]);
            }
        }

        // reviewer deadlines
        $revtypes = array();
        $rev_open = +$this->conf->setting("rev_open");
        $rev_open = $rev_open > 0 && $rev_open <= Conf::$now;
        if ($this->is_reviewer() && $rev_open) {
            $dl->rev = (object) ["open" => true];
        } else if ($this->privChair) {
            $dl->rev = (object) [];
        }
        if (isset($dl->rev)) {
            $dl->revs = [];
            $k = $this->isPC ? "pcrev" : "extrev";
            foreach ($this->conf->defined_round_list() as $i => $round_name) {
                $isuf = $i ? "_$i" : "";
                $s = +$this->conf->setting("{$k}_soft$isuf");
                $h = +$this->conf->setting("{$k}_hard$isuf");
                $dl->revs[$round_name] = $dlround = (object) [];
                if ($rev_open) {
                    $dlround->open = true;
                }
                if ($h && ($h < Conf::$now || $s < Conf::$now)) {
                    $dlround->done = $h;
                    $dlround->ishard = true;
                } else if ($s) {
                    $dlround->done = $s;
                }
            }
            // blindness
            $rb = $this->conf->review_blindness();
            if ($rb === Conf::BLIND_ALWAYS) {
                $dl->rev->blind = true;
            } else if ($rb === Conf::BLIND_OPTIONAL) {
                $dl->rev->blind = "optional";
            }
            if ($this->conf->can_some_author_view_review()) {
                $dl->rev->some_author_can_view = true;
            }
        }

        // grace periods: give a minute's notice of an impending grace
        // period
        for ($i = 0; $i !== count($graces); $i += 3) {
            $dlx = $graces[$i];
            foreach ($graces[$i + 2] as $k) {
                if ($dlx->$k
                    && $dlx->$k - 30 < Conf::$now
                    && $dlx->$k + $graces[$i + 1] >= Conf::$now) {
                    $kgrace = "{$k}_ingrace";
                    $dlx->$kgrace = true;
                }
            }
        }

        // add meeting tracker
        if (($this->isPC || $this->tracker_kiosk_state > 0)
            && $this->can_view_tracker()) {
            MeetingTracker::my_deadlines($dl, $this);
        }

        // permissions
        if ($prows) {
            $dl->perm = [];
            foreach ($prows as $prow) {
                if (!$this->can_view_paper($prow)) {
                    continue;
                }
                $perm = $dl->perm[$prow->paperId] = (object) [];
                $rights = $this->rights($prow);
                $admin = $rights->allow_administer;
                if ($admin) {
                    $perm->allow_administer = true;
                }
                if ($rights->act_author) {
                    $perm->act_author = true;
                }
                if ($rights->act_author_view) {
                    $perm->act_author_view = true;
                }
                if ($this->can_review($prow, null, false)) {
                    $perm->can_review = true;
                }
                if ($this->can_comment($prow, null, true)) {
                    $perm->can_comment = true;
                } else if ($admin && $this->can_comment($prow, null, false)) {
                    $perm->can_comment = "override";
                }
                if (isset($dl->resps)) {
                    foreach ($this->conf->resp_rounds() as $rrd) {
                        $crow = CommentInfo::make_response_template($rrd->number, $prow);
                        $v = false;
                        if ($this->can_respond($prow, $crow, true)) {
                            $v = true;
                        } else if ($admin && $this->can_respond($prow, $crow, false)) {
                            $v = "override";
                        }
                        if ($v && !isset($perm->can_responds)) {
                            $perm->can_responds = [];
                        }
                        if ($v) {
                            $perm->can_responds[$rrd->name] = $v;
                        }
                    }
                }
                if ($prow->can_author_view_submitted_review()) {
                    $perm->some_author_can_view_review = true;
                }
                if ($prow->can_author_view_decision()) {
                    $perm->some_author_can_view_decision = true;
                }
                if ($this->isPC
                    && !$this->conf->can_some_external_reviewer_view_comment()) {
                    $perm->default_comment_visibility = "pc";
                }
                if ($this->_review_tokens) {
                    $tokens = [];
                    foreach ($prow->reviews_by_id() as $rrow) {
                        if ($rrow->reviewToken !== 0
                            && in_array($rrow->reviewToken, $this->_review_tokens, true))
                            $tokens[$rrow->reviewToken] = true;
                    }
                    if (!empty($tokens)) {
                        $perm->review_tokens = array_map("encode_token", array_keys($tokens));
                    }
                }
            }
        }

        return $dl;
    }

    function has_reportable_deadline() {
        $dl = $this->my_deadlines();
        if (isset($dl->sub->reg) || isset($dl->sub->update) || isset($dl->sub->sub)) {
            return true;
        }
        if (isset($dl->resps)) {
            foreach ($dl->resps as $dlr) {
                if (isset($dlr->open) && $dlr->open < Conf::$now && ($dlr->done ?? null))
                    return true;
            }
        }
        if (isset($dl->rev) && isset($dl->rev->open) && $dl->rev->open < Conf::$now) {
            foreach ($dl->revs as $dlr) {
                if ($dlr->done ?? null)
                    return true;
            }
        }
        return false;
    }


    // papers

    /** @param array{paperId?:list<int>|PaperID_SearchTerm} $options
     * @return PaperInfoSet|Iterable<PaperInfo> */
    function paper_set($options) {
        return $this->conf->paper_set($options, $this);
    }

    /** @param int $pid
     * @return ?PaperInfo */
    function paper_by_id($pid, $options = []) {
        return $this->conf->paper_by_id($pid, $this, $options);
    }

    /** @param int $pid
     * @return PaperInfo */
    function checked_paper_by_id($pid, $options = []) {
        return $this->conf->checked_paper_by_id($pid, $this, $options);
    }

    /** @return array{string,string} */
    function paper_status_info(PaperInfo $row) {
        if ($row->timeWithdrawn > 0) {
            return ["pstat_with", "Withdrawn"];
        } else if ($row->outcome && $this->can_view_decision($row)) {
            return $this->conf->decision_status_info($row->outcome);
        } else if ($row->timeSubmitted > 0) {
            return ["pstat_sub", "Submitted"];
        } else if ($row->paperStorageId <= 1
                   && (int) $this->conf->opt("noPapers") !== 1) {
            return ["pstat_draft", "No submission"];
        } else {
            return ["pstat_draft", "Draft"];
        }
    }


    private function unassigned_review_token() {
        while (true) {
            $token = mt_rand(1, 2000000000);
            if (!$this->conf->fetch_ivalue("select reviewId from PaperReview where reviewToken=$token")) {
                return ", reviewToken=$token";
            }
        }
    }

    /** @param int $type
     * @param int $round */
    private function assign_review_explanation($type, $round) {
        $t = ReviewForm::$revtype_names_lc[$type] . " review";
        if ($round && ($rname = $this->conf->round_name($round))) {
            $t .= " (round $rname)";
        }
        return $t;
    }

    /** @param int $pid
     * @param int $reviewer_cid
     * @param int $type */
    function assign_review($pid, $reviewer_cid, $type, $extra = []) {
        $result = $this->conf->qe("select * from PaperReview where paperId=? and contactId=?", $pid, $reviewer_cid);
        $rrow = ReviewInfo::fetch($result, null, $this->conf);
        Dbl::free($result);
        $reviewId = $rrow ? $rrow->reviewId : 0;
        $type = max((int) $type, 0);
        $oldtype = $rrow ? $rrow->reviewType : 0;
        $round = $extra["round_number"] ?? null;
        $new_requester_cid = $this->contactId;

        // can't delete a review that's in progress
        if ($type <= 0 && $oldtype && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            if ($oldtype >= REVIEW_SECONDARY) {
                $type = REVIEW_PC;
            } else {
                return $reviewId;
            }
        }
        // PC members always get PC reviews
        if ($type == REVIEW_EXTERNAL
            && $this->conf->pc_member_by_id($reviewer_cid)) {
            $type = REVIEW_PC;
        }

        // change database
        if ($type && $round === null) {
            $round = $this->conf->assignment_round($type == REVIEW_EXTERNAL);
        }
        if ($type && !$oldtype) {
            $qa = "";
            if ($extra["mark_notify"] ?? null) {
                $qa .= ", timeRequestNotified=" . Conf::$now;
            }
            if ($extra["token"] ?? null) {
                $qa .= $this->unassigned_review_token();
            }
            if (($new_requester = $extra["requester_contact"] ?? null)) {
                $new_requester_cid = $new_requester->contactId;
            }
            $q = "insert into PaperReview set paperId=$pid, contactId=$reviewer_cid, reviewType=$type, reviewRound=$round, timeRequested=".Conf::$now."$qa, requestedBy=$new_requester_cid";
        } else if ($type && ($oldtype != $type || $rrow->reviewRound != $round)) {
            $q = "update PaperReview set reviewType=$type, reviewRound=$round";
            if ($rrow->reviewStatus < ReviewInfo::RS_ADOPTED) {
                $q .= ", reviewNeedsSubmit=1";
            }
            $q .= " where reviewId=$reviewId";
        } else if (!$type && $oldtype) {
            $q = "delete from PaperReview where reviewId=$reviewId";
        } else {
            return $reviewId;
        }

        $result = $this->conf->qe_raw($q);
        if (Dbl::is_error($result)) {
            return false;
        }

        if ($type && !$oldtype) {
            $reviewId = $result->insert_id;
            $msg = "Assigned " . $this->assign_review_explanation($type, $round);
        } else if (!$type) {
            $msg = "Removed " . $this->assign_review_explanation($oldtype, $rrow->reviewRound);
            $reviewId = 0;
        } else {
            $msg = "Changed " . $this->assign_review_explanation($oldtype, $rrow->reviewRound) . " to " . $this->assign_review_explanation($type, $round);
        }
        $this->conf->log_for($this, $reviewer_cid, $msg, $pid);

        // on new review, update PaperReviewRefused, ReviewRequest, delegation
        if ($type && !$oldtype) {
            $this->conf->ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
            if (($req_email = $extra["requested_email"] ?? null)) {
                $this->conf->qe("delete from ReviewRequest where paperId=$pid and email=?", $req_email);
            }
            if ($type < REVIEW_SECONDARY) {
                $this->update_review_delegation($pid, $new_requester_cid, 1);
            }
            if ($type >= REVIEW_PC
                && $this->conf->setting("pcrev_assigntime", 0) < Conf::$now) {
                $this->conf->save_setting("pcrev_assigntime", Conf::$now);
            }
        } else if (!$type) {
            if ($oldtype < REVIEW_SECONDARY && $rrow->requestedBy > 0) {
                $this->update_review_delegation($pid, $rrow->requestedBy, -1);
            }
            // Mark rev_tokens setting for future update by update_rev_tokens_setting
            if ($rrow->reviewToken !== 0) {
                $this->conf->settings["rev_tokens"] = -1;
            }
        } else {
            if ($type == REVIEW_SECONDARY
                && $oldtype != REVIEW_SECONDARY
                && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
                $this->update_review_delegation($pid, $reviewer_cid, 0);
            }
        }
        if ($type == REVIEW_META || $oldtype == REVIEW_META) {
            $this->conf->update_metareviews_setting($type == REVIEW_META ? 1 : -1);
        }

        self::update_rights();
        if (!($extra["no_autosearch"] ?? false)) {
            $this->conf->update_automatic_tags($pid, "review");
        }
        return $reviewId;
    }

    /** @param int $pid
     * @param int $cid
     * @param 1|0|-1 $direction */
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

    /** @param ReviewInfo|stdClass $rrow
     * @return Dbl_Result */
    function unsubmit_review_row($rrow, $extra = null) {
        $needsSubmit = 1;
        if ($rrow->reviewType == REVIEW_SECONDARY) {
            $row = Dbl::fetch_first_row($this->conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where paperId=? and requestedBy=? and reviewType<" . REVIEW_SECONDARY, $rrow->paperId, $rrow->contactId));
            if ($row && $row[0]) {
                $needsSubmit = 0;
            } else if ($row && $row[1]) {
                $needsSubmit = -1;
            }
        }
        $result = $this->conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=?, timeApprovalRequested=0 where paperId=? and reviewId=?", $needsSubmit, $rrow->paperId, $rrow->reviewId);
        if ($result->affected_rows && $rrow->reviewType < REVIEW_SECONDARY) {
            $this->update_review_delegation($rrow->paperId, $rrow->requestedBy, -1);
        }
        if (!$extra || !($extra["no_autosearch"] ?? false)) {
            $this->conf->update_automatic_tags($rrow->paperId, "review");
        }
        return $result;
    }
}
