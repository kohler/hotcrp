<?php
// contact.php -- HotCRP helper class representing system users
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Contact implements JsonSerializable {
    /** @var int */
    static public $rights_version = 1;
    /** @var ?Contact
     * @readonly */
    static public $main_user;
    /** @var bool */
    static public $no_main_user = false;
    /** @var int */
    static public $next_xid = -2;
    /** @var ?list<string> */
    static public $session_users;

    /** @var Conf */
    public $conf;

    /** @var int */
    public $contactId = 0;
    /** @var int */
    public $contactDbId = 0;
    /** @var int */
    public $contactXid = 0;
    /** @var int */
    public $cdb_confid = 0; // nonzero iff this is a CDB user

    /** @var string */
    public $email = "";
    /** @var string */
    public $firstName = "";
    /** @var string */
    public $lastName = "";
    /** @var ?string */
    public $unaccentedName;
    /** @var ?bool */
    public $name_usascii;
    /** @var string */
    public $affiliation = "";
    /** @var int */
    public $roles = 0;
    /** @var int */
    public $role_mask = self::ROLE_DBMASK;
    /** @var int
     * @deprecated */
    private $disabled = 0;
    /** @var ?int */
    public $primaryContactId;
    /** @var ?string */
    public $contactTags;
    /** @var int */
    public $cflags = 0;

    /** @var int */
    public $_slice = 0;
    /** @var ?ContactSet */
    public $_row_set;

    const SLICEBIT_COLLABORATORS = 0x1;
    const SLICEBIT_PASSWORD = 0x2;
    const SLICEBIT_COUNTRY = 0x4;
    const SLICEBIT_ORCID = 0x8;
    const SLICEBIT_REST = 0x10;
    const SLICE_MINIMAL = 0x1F;

    /** @var ?bool */
    public $nameAmbiguous;
    /** @var string */
    private $_sorter;
    /** @var ?int */
    private $_sortspec;
    /** @var ?int */
    public $pc_index;

    /** @var ?string */
    private $collaborators;
    public $preferredEmail = "";
    /** @var ?string */
    private $country;
    /** @var ?string */
    private $orcid;
    /** @var ?string */
    private $phone;
    /** @var ?int */
    private $cdbRoles;

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
    private $_cdb_user = false;

    /** @var ?int */
    public $activity_at;
    /** @var int */
    private $lastLogin = 0;
    /** @var int */
    private $updateTime = 0;
    private $data;
    /** @var ?object */
    private $_jdata;
    const WATCH_REVIEW_EXPLICIT = 0x01; // only in PaperWatch, not defaultWatch
    const WATCH_REVIEW = 0x02;
    const WATCH_REVIEW_ALL = 0x04;
    const WATCH_REVIEW_MANAGED = 0x08;
    const WATCH_REVIEW_ANY = 0x0E; // REVIEW + REVIEW_ALL + REVIEW_MANAGED
    const WATCH_PAPER_NEWSUBMIT_ALL = 0x10;
    const WATCH_FINAL_UPDATE_ALL = 0x20;
    const WATCH_PAPER_REGISTER_ALL = 0x40;
    const WATCH_LATE_WITHDRAWAL_ALL = 0x80;
    /** @var int */
    public $defaultWatch = self::WATCH_REVIEW;

    /** @var ?array<int,int> */
    private $_topic_interest_map;
    /** @var array<string,string> */
    private $_name_for_map = [];

    // Roles
    const ROLE_PC = 0x0001; // value matters
    const ROLE_ADMIN = 0x0002;
    const ROLE_CHAIR = 0x0004;
    const ROLE_PCLIKE = 0x000F;
    const ROLE_AUTHOR = 0x0010;
    const ROLE_REVIEWER = 0x0020;
    const ROLE_REQUESTER = 0x0040;
    const ROLE_OUTSTANDING_REVIEW = 0x1000;
    const ROLE_METAREVIEWER = 0x2000;
    const ROLE_LEAD = 0x4000;
    const ROLE_EXPLICIT_MANAGER = 0x8000;
    const ROLE_APPROVABLE = 0x10000;
    const ROLE_VIEW_SOME_REVIEW_ID = 0x20000;

    const ROLE_DBMASK = 0x000F;
    const ROLE_CDBMASK = 0x003F; // DBMASK | AUTHOR | REVIEWER

    /** @var bool */
    public $isPC = false;
    /** @var bool */
    public $privChair = false;
    /** @var bool */
    private $_root_user = false;
    /** @var ?int */
    private $_session_roles;
    /** @var ?associative-array<int,int> */
    private $_conflict_types;
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
    const OVERRIDE_TAG_CHECKS = 2;
    const OVERRIDE_EDIT_CONDITIONS = 4;
    const OVERRIDE_AU_SEEREV = 8;
    /** @var int */
    private $_overrides = 0;
    /** @var ?array<int,bool> */
    public $hidden_papers;

    // $_activated values: 0: no, 1: yes; 2: is actas; 4: is token
    // higher bits: user index
    /** @var int */
    private $_activated = 0;
    // $_admin_base_user: base authenticated user in case of actas
    /** @var ?Contact */
    private $_admin_base_user;
    // defaults for hoturl
    /** @var ?array<string,string> */
    private $_hoturl_defaults;

    /** @var ?non-empty-list<AuthorMatcher> */
    private $_aucollab_matchers;
    /** @var ?TextPregexes */
    private $_aucollab_general_pregexes;
    /** @var ?list<PaperInfo> */
    private $_authored_papers;

    /** @var ?array */
    private $_mod_undo;

    // Per-paper DB information, usually null
    public $conflictType;
    public $myReviewPermissions;
    public $paperId;

    /** @deprecated */
    const DISABLEMENT_USER = 1;
    /** @deprecated */
    const DISABLEMENT_PLACEHOLDER = 2;
    /** @deprecated */
    const DISABLEMENT_ROLE = 4;
    /** @deprecated */
    const DISABLEMENT_DELETED = 8;

    const CF_UDISABLED = 0x1;
    const CF_PLACEHOLDER = 0x2;
    const CF_ROLEDISABLED = 0x4;
    const CF_DELETED = 0x8;
    const CF_GDISABLED = 0x10;
    const CF_UNCONFIRMED = 0x20;
    const CF_SECURITYLOCK = 0x40;

    const CFM_DISABLEMENT = 0x1F;
    const CFM_DB = ~0xC;

    /** @deprecated */
    const CFLAG_UDISABLED = 0x1;
    /** @deprecated */
    const CFLAG_PLACEHOLDER = 0x2;
    /** @deprecated */
    const CFLAG_ROLEDISABLED = 0x4;
    /** @deprecated */
    const CFLAG_DELETED = 0x8;
    /** @deprecated */
    const CFLAG_GDISABLED = 0x10;
    /** @deprecated */
    const CFLAG_UNCONFIRMED = 0x20;

    /** @deprecated */
    const CFMASK_DISABLEMENT = 0x1F;
    /** @deprecated */
    const CFMASK_DB = ~0xC;

    const PROP_LOCAL = 0x01;
    const PROP_CDB = 0x02;
    const PROP_SLICE = 0x04;
    const PROP_DATA = 0x08;
    const PROP_NULL = 0x10;
    const PROP_STRING = 0x20;
    const PROP_INT = 0x40;
    const PROP_BOOL = 0x80;
    const PROP_STRINGLIST = 0x100;
    const PROP_SIMPLIFY = 0x200;
    const PROP_NAME = 0x1000;
    const PROP_NOUPDATECDB = 0x2000;
    const PROP_UPDATE = 0x4000;
    const PROP_IMPORT = 0x8000;
    const PROP_SPECIAL = 0x10000;
    static public $props = [
        "email" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_SLICE,
        "firstName" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_SLICE | self::PROP_NAME | self::PROP_UPDATE | self::PROP_IMPORT,
        "lastName" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_SLICE | self::PROP_NAME | self::PROP_UPDATE | self::PROP_IMPORT,
        "preferredEmail" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY,
        "affiliation" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_SLICE | self::PROP_UPDATE | self::PROP_IMPORT,
        "phone" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE | self::PROP_IMPORT,
        "country" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE | self::PROP_IMPORT,
        "orcid" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE | self::PROP_IMPORT,
        "password" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_STRING | self::PROP_NOUPDATECDB,
        "passwordTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_NOUPDATECDB,
        "passwordUseTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_NOUPDATECDB,
        "collaborators" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_NULL | self::PROP_STRING | self::PROP_UPDATE | self::PROP_IMPORT,
        "updateTime" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_NOUPDATECDB,
        "lastLogin" => self::PROP_LOCAL | self::PROP_INT,
        "defaultWatch" => self::PROP_LOCAL | self::PROP_INT,
        "primaryContactId" => self::PROP_LOCAL | self::PROP_INT | self::PROP_SLICE,
        "roles" => self::PROP_LOCAL | self::PROP_INT | self::PROP_SLICE | self::PROP_SPECIAL,
        "cdbRoles" => self::PROP_LOCAL | self::PROP_INT,
        "disabled" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_SLICE | self::PROP_NOUPDATECDB,
        "contactTags" => self::PROP_LOCAL | self::PROP_NULL | self::PROP_STRING | self::PROP_SLICE,
        "cflags" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_INT | self::PROP_SLICE | self::PROP_NOUPDATECDB | self::PROP_SPECIAL,
        "address" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRINGLIST | self::PROP_SIMPLIFY | self::PROP_UPDATE,
        "city" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE,
        "state" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE,
        "zip" => self::PROP_LOCAL | self::PROP_CDB | self::PROP_DATA | self::PROP_NULL | self::PROP_STRING | self::PROP_SIMPLIFY | self::PROP_UPDATE
    ];


    /** @param Conf $conf */
    private function __construct($conf) {
        $this->conf = $conf;
    }

    /** @return Contact */
    static function make(Conf $conf) {
        $u = new Contact($conf);
        $u->contactXid = self::$next_xid--;
        $u->cflags = self::CF_UNCONFIRMED;
        $u->set_roles_properties();
        return $u;
    }

    /** @param ?string $email
     * @return Contact */
    static function make_email(Conf $conf, $email) {
        $u = new Contact($conf);
        $u->contactXid = self::$next_xid--;
        $u->email = $email ?? "";
        $u->cflags = self::CF_UNCONFIRMED;
        $u->set_roles_properties();
        return $u;
    }

    /** @return Contact */
    static function make_placeholder(Conf $conf) {
        $u = new Contact($conf);
        $u->contactXid = self::$next_xid--;
        $u->cflags = self::CF_PLACEHOLDER | self::CF_UNCONFIRMED;
        /** @phan-suppress-next-line PhanDeprecatedProperty */
        $u->disabled = self::CF_PLACEHOLDER;
        $u->set_roles_properties();
        return $u;
    }

    /** @param int $contactId
     * @return Contact */
    static function make_deleted(Conf $conf, $contactId) {
        $u = new Contact($conf);
        $u->contactId = $contactId;
        $u->contactXid = $contactId > 0 ? $contactId : self::$next_xid--;
        $u->email = "<deleted>";
        $u->cflags = self::CF_DELETED;
        $u->set_roles_properties();
        return $u;
    }

    /** @param ?string $email
     * @return Contact */
    static function make_cdb_email(Conf $conf, $email) {
        $u = new Contact($conf);
        $u->contactXid = self::$next_xid--;
        $u->email = $email ?? "";
        $u->cdb_confid = $conf->cdb_confid();
        $u->cflags = self::CF_UNCONFIRMED;
        $u->set_roles_properties();
        return $u;
    }

    /** @param array{contactId?:int,email?:string,firstName?:string,first?:string,lastName?:string,last?:string,name?:string,affiliation?:string,disabled?:void,disablement?:int} $args
     * @return Contact */
    static function make_keyed(Conf $conf, $args) {
        // email, firstName, lastName, affiliation, disablement, contactId, first, last:
        // the importable properties
        $u = new Contact($conf);
        $u->contactId = $args["contactId"] ?? 0;
        $u->contactXid = $u->contactId > 0 ? $u->contactId : self::$next_xid--;
        $u->email = trim($args["email"] ?? "");
        $u->firstName = $args["firstName"] ?? $args["first"] ?? "";
        $u->lastName = $args["lastName"] ?? $args["last"] ?? "";
        if (isset($args["name"]) && $u->firstName === "" && $u->lastName === "") {
            list($u->firstName, $u->lastName, $unused) = Text::split_name($args["name"]);
        }
        $u->affiliation = simplify_whitespace($args["affiliation"] ?? "");
        $u->orcid = trim($args["orcid"] ?? "");
        $u->cflags = ($args["disablement"] ?? 0) | self::CF_UNCONFIRMED;
        /** @phan-suppress-next-line PhanDeprecatedProperty */
        $u->disabled = $u->cflags & self::CFM_DISABLEMENT & self::CFM_DB;
        $u->set_roles_properties();
        return $u;
    }

    /** @return Contact */
    static function make_root_user(Conf $conf) {
        $u = new Contact($conf);
        $u->contactXid = self::$next_xid--;
        $u->email = "rootuser";
        $u->roles = self::ROLE_PC | self::ROLE_CHAIR;
        $u->_root_user = true;
        $u->_dangerous_track_mask = 0;
        $u->set_roles_properties();
        $u->_cdb_user = null;
        return $u;
    }

    /** @param mysqli_result|Dbl_Result $result
     * @return ?Contact */
    static function fetch($result, Conf $conf) {
        if (($u = $result->fetch_object("Contact", [$conf]))) {
            $u->conf = $conf;
            $u->fetch_incorporate();
            $u->set_roles_properties();
            $u->contactXid = $u->contactId ? : self::$next_xid--;
        }
        return $u;
    }

    /** @suppress PhanDeprecatedProperty */
    private function fetch_incorporate() {
        $this->contactId = (int) $this->contactId;
        $this->contactDbId = (int) $this->contactDbId;
        $this->cdb_confid = (int) $this->cdb_confid;
        if ($this->contactId > 0) {
            assert($this->cdb_confid === 0);
        } else {
            assert($this->contactId === 0 && $this->contactDbId > 0);
            if ($this->cdb_confid === 0) {
                $this->cdb_confid = -1;
            }
        }

        // handle slice properties
        $this->role_mask = (int) ($this->role_mask ?? self::ROLE_DBMASK);
        $this->roles = (int) $this->roles;
        $this->cflags = (int) $this->cflags;
        /** @phan-suppress-next-line PhanDeprecatedProperty */
        $this->disabled = (int) $this->disabled;
        if (isset($this->primaryContactId)) {
            $this->primaryContactId = (int) $this->primaryContactId;
        }
        $this->_slice = (int) $this->_slice;

        // handle unsliced properties
        if ($this->_slice === 0) {
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
        }

        if ($this->activity_at === null && $this->lastLogin !== 0) {
            $this->activity_at = (int) $this->lastLogin;
        }
        $this->_cdb_user = false;
    }

    private function set_roles_properties() {
        $this->isPC = ($this->roles & self::ROLE_PCLIKE) !== 0;
        $this->privChair = ($this->roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) !== 0;
        if ($this->isPC || !$this->conf->disable_non_pc) {
            $this->cflags &= ~self::CF_ROLEDISABLED;
        } else {
            $this->cflags |= self::CF_ROLEDISABLED;
        }
    }

    /** @suppress PhanAccessReadOnlyProperty */
    static function set_main_user(Contact $user = null) {
        global $Me;
        Contact::$main_user = $Me = $user;
    }


    /** @param object $x
     * @param bool $all */
    function unslice_using($x, $all = false) {
        assert($all || $this->cdb_confid === 0);
        $shapemask = self::PROP_LOCAL | self::PROP_DATA | ($all ? 0 : self::PROP_SLICE);
        foreach (self::$props as $prop => $shape) {
            if (($shape & $shapemask) === self::PROP_LOCAL) {
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
        }
        if ($all) {
            $this->contactId = $this->contactXid = $x->contactId;
            $this->cdb_confid = $this->contactDbId = 0;
        }
        $this->activity_at = $this->lastLogin;
        $this->data = $x->data;
        $this->_jdata = null;
        $this->_slice = 0;
    }

    function unslice() {
        if ($this->_slice !== 0) {
            assert($this->contactId > 0);
            $this->conf->unslice_user($this);
        }
    }


    /** @return string */
    function collaborators() {
        if (($this->_slice & self::SLICEBIT_COLLABORATORS) === 0) {
            return $this->collaborators ?? "";
        }
        assert($this->cdb_confid === 0);
        $set = $this->_row_set ?? ContactSet::make_singleton($this);
        foreach ($set as $u) {
            $u->collaborators = null;
            $u->_slice &= ~self::SLICEBIT_COLLABORATORS;
        }
        $result = $this->conf->qe("select contactId, collaborators from ContactInfo where contactId?a", $set->user_ids());
        while (($row = $result->fetch_row())) {
            $set->get(intval($row[0]))->collaborators = $row[1];
        }
        $result->close();
        return $this->collaborators ?? "";
    }

    /** @param ?string $x */
    function set_collaborators($x) {
        $this->_slice &= ~self::SLICEBIT_COLLABORATORS;
        $this->collaborators = $x;
    }

    /** @return Generator<AuthorMatcher> */
    function collaborator_generator() {
        return AuthorMatcher::make_collaborator_generator($this->collaborators());
    }

    /** @return string */
    function country() {
        if (($this->_slice & self::SLICEBIT_COUNTRY) !== 0) {
            $this->unslice();
        }
        return $this->country ?? "";
    }

    /** @return ?string */
    function phone() {
        if ($this->_slice !== 0) {
            $this->unslice();
        }
        return $this->phone;
    }

    /** @return string */
    function orcid() {
        if (($this->_slice & self::SLICEBIT_ORCID) !== 0) {
            $this->unslice();
        }
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
        } else if (($seen & 016) === 004) { // last -> last first email
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
                if ($bit !== 3 && !ctype_alpha($s)) {
                    $s = trim(preg_replace('/(?:[()\[\]{}".\s]|“|”)+/', " ", $s));
                }
                $r[] = $s;
            }
        }
        if ($von !== "") {
            $r[] = $von;
        }
        return join(" \x00 ", $r);
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


    // initialization

    /** @param Qrequest $qreq
     * @return list<string> */
    static function session_users($qreq) {
        if (isset(self::$session_users)) {
            return self::$session_users;
        } else if (($us = $qreq->gsession("us")) !== null) {
            return $us;
        } else if (($u = $qreq->gsession("u")) !== null) {
            return [$u];
        } else {
            return [];
        }
    }

    /** @param Qrequest $qreq
     * @param string $email
     * @return int */
    static function session_index_by_email($qreq, $email) {
        foreach (self::session_users($qreq) as $i => $u) {
            if (strcasecmp($u, $email) === 0) {
                return $i;
            }
        }
        return -1;
    }

    /** @return Contact */
    private function actas_user($x) {
        // translate to email
        if (ctype_digit($x)) {
            $acct = $this->conf->user_by_id(intval($x));
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
        if (!$u && validate_email($email)) {
            $u = $this->conf->cdb_user_by_email($email);
            if (!$u && $this->conf->opt("debugShowSensitiveEmail")) {
                $u = Contact::make_email($this->conf, $email)->store();
            }
        }
        if (!$u) {
            return $this;
        }

        // cannot turn into a manager of conflicted papers
        if ($this->conf->setting("papermanager")) {
            $result = $this->conf->qe("select paperId from Paper join PaperConflict using (paperId) where managerContactId!=0 and managerContactId!=? and PaperConflict.contactId=? and conflictType>" . CONFLICT_MAXUNCONFLICTED, $this->contactXid, $this->contactXid);
            // XXX should also consider setting papers that this user cannot administer
            // XXX because of tracks
            while (($row = $result->fetch_row())) {
                $u->hidden_papers[(int) $row[0]] = false;
            }
            Dbl::free($result);
        }

        // otherwise ok
        return $u;
    }

    function set_bearer_authorized() {
        $this->_activated |= 4;
    }

    /** @param Qrequest $qreq
     * @return Contact */
    function activate($qreq, $signin, $userindex = 0) {
        $this->_activated |= 1 | ($userindex << 8);

        // Handle actas requests
        if ($qreq->actas && $signin && $this->email) {
            $actas = $qreq->actas;
            unset($qreq->actas, $_GET["actas"], $_POST["actas"]);
            $actasuser = $this->actas_user($actas);
            if ($actasuser !== $this) {
                $qreq->set_gsession("last_actas", $actasuser->email);
                $actasuser->_activated |= 2;
                $actasuser->_admin_base_user = $this;
                $actasuser->_hoturl_defaults["actas"] = urlencode($actasuser->email);
                return $actasuser->activate($qreq, true, $userindex);
            }
        }

        // add capabilities from request
        if (isset($qreq->cap)) {
            $this->apply_capability_text($qreq->cap);
            unset($qreq->cap, $_GET["cap"], $_POST["cap"]);
        }

        // add review tokens from session
        if (($rtokens = $qreq->csession("rev_tokens"))) {
            foreach ($rtokens as $t) {
                $this->_review_tokens[] = (int) $t;
            }
            ++self::$rights_version;
        }

        // print, resave, and/or gc named saved messages
        if (($cmsgs = $qreq->csession("msgs"))) {
            foreach ($cmsgs as $mx) {
                if (is_array($mx) && is_string($mx[0] ?? null) && is_int($mx[1] ?? null)) {
                    Conf::msg_on($this->conf, $mx[0], $mx[1]);
                }
            }
            $qreq->unset_csession("msgs");
        }
        if (($smsgs = $qreq->gsession("smsg"))) {
            $nsmsgs = [];
            foreach ($smsgs as $ml) {
                if ($ml[0] === $qreq->_smsg) {
                    for ($i = 2; $i !== count($ml); ++$i) {
                        Conf::msg_on($this->conf, $ml[$i][0], $ml[$i][1]);
                    }
                } else if ($ml[1] >= Conf::$now - 30) {
                    $nsmsgs[] = $ml;
                }
            }
            if (empty($nsmsgs)) {
                $qreq->unset_gsession("smsg");
            } else if (count($nsmsgs) !== count($smsgs)) {
                $qreq->set_gsession("smsg", $nsmsgs);
            }
        }

        // maybe auto-create a user
        if (($this->_activated & 2) === 0 && $this->email) {
            if ($this->activate_placeholder_prop(($this->_activated & 7) === 1)) {
                $this->save_prop();
                if (($cdbu = $this->cdb_user())
                    && $cdbu->activate_placeholder_prop(($this->_activated & 7) === 1)) {
                    $cdbu->save_prop();
                }
            }
            $trueuser_aucheck = $qreq->csession("trueuser_author_check") ?? 0;
            if (!$this->has_account_here()
                && $trueuser_aucheck + 600 < Conf::$now) {
                $qreq->set_csession("trueuser_author_check", Conf::$now);
                $aupapers = self::email_authored_papers($this->conf, $this->email, $this);
                if (!empty($aupapers)) {
                    $this->ensure_account_here();
                }
            }
            if ($this->has_account_here()
                && $trueuser_aucheck
                && $this->conf->session_key !== null) {
                foreach ($_SESSION as $k => $v) {
                    if (is_array($v)
                        && isset($v["trueuser_author_check"])
                        && $v["trueuser_author_check"] + 600 < Conf::$now)
                        unset($_SESSION[$k]["trueuser_author_check"]);
                }
            }
        }

        // maybe set up the shared contacts database
        if ($this->conf->opt("contactdbDsn")
            && $this->has_account_here()
            && $this->cdbRoles !== $this->cdb_roles()) {
            $this->update_cdb();
        }

        // check forceShow
        $this->_overrides = 0;
        if ($qreq->forceShow && $this->is_manager()) {
            $this->_overrides |= self::OVERRIDE_CONFLICT;
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
    function call_with_overrides($overrides, $method, ...$args) {
        $old_overrides = $this->set_overrides($overrides);
        $result = call_user_func_array([$this, $method], $args);
        $this->_overrides = $old_overrides;
        return $result;
    }

    /** @return bool */
    function is_cdb_user() {
        return $this->cdb_confid !== 0;
    }

    /** @return $this */
    function ensure_account_here() {
        assert($this->has_email());
        if (!$this->has_account_here() && !$this->is_root_user()) {
            $this->store();
        }
        return $this;
    }

    function invalidate_cdb_user() {
        $u = $this->_cdb_user ? : Contact::make_cdb_email($this->conf, $this->email);
        $this->conf->invalidate_user($u);
        $this->_cdb_user = false;
    }

    /** @return ?Contact */
    function cdb_user() {
        if ($this->cdb_confid !== 0) {
            assert($this->contactDbId !== 0 && $this->contactId <= 0);
            return $this;
        } else if ($this->_cdb_user !== false) {
            return $this->_cdb_user;
        }
        if ($this->conf->prefetch_cdb_user_by_email($this->email)) {
            foreach ($this->_row_set ?? ContactSet::make_singleton($this) as $u) {
                if ($u->email)
                    $this->conf->prefetch_cdb_user_by_email($u->email);
            }
        }
        $this->_cdb_user = $this->conf->cdb_user_by_email($this->email);
        if ($this->_cdb_user && $this->contactId > 0) {
            $this->_cdb_user->contactXid = $this->contactId;
        }
        return $this->_cdb_user;
    }

    /** @return ?Contact */
    function ensure_cdb_user() {
        assert($this->has_email());
        if ($this->cdb_confid !== 0) {
            assert($this->contactDbId !== 0 && $this->contactId <= 0);
            return $this;
        }
        $u = $this->cdb_user();
        if (!$u
            && $this->conf->contactdb()
            && $this->has_email()
            && !self::is_anonymous_email($this->email)) {
            $u = $this->_cdb_user = Contact::make_cdb_email($this->conf, $this->email);
            if ($this->contactId > 0) {
                $u->contactXid = $this->contactId;
            }
        }
        return $u;
    }

    function update_cdb_roles() {
        $roles = $this->cdb_roles();
        if (($cdbu = $this->cdb_user())
            && ($roles !== $cdbu->roles
                || ($roles !== 0 && (int) $cdbu->activity_at <= Conf::$now - 604800))) {
            assert($cdbu->cdb_confid < 0 || $cdbu->cdb_confid === $this->conf->opt["contactdbConfid"]);
            if ($roles !== 0) {
                Dbl::ql($this->conf->contactdb(), "insert into Roles set contactDbId=?, confid=?, roles=?, activity_at=? on duplicate key update roles=?, activity_at=?", $cdbu->contactDbId, $this->conf->cdb_confid(), $roles, Conf::$now, $roles, Conf::$now);
            } else {
                Dbl::ql($this->conf->contactdb(), "delete from Roles where contactDbId=? and confid=?", $cdbu->contactDbId, $this->conf->cdb_confid());
            }
            $cdbu->roles = $roles;
        }
        if ($this->contactId > 0
            && $roles !== $this->cdbRoles) {
            Dbl::ql($this->conf->dblink, "update ContactInfo set cdbRoles=? where contactId=?", $roles, $this->contactId);
            $this->cdbRoles = $roles;
        }
    }

    /** @return int|false */
    function update_cdb() {
        if (!$this->conf->contactdb()
            || !$this->has_account_here()
            || !validate_email($this->email)) {
            return false;
        }

        $cdbur = $this->conf->fresh_cdb_user_by_email($this->email);
        $cdbux = $cdbur ?? Contact::make_cdb_email($this->conf, $this->email);
        foreach (self::$props as $prop => $shape) {
            if (($shape & self::PROP_CDB) !== 0
                && ($shape & self::PROP_NOUPDATECDB) === 0
                && ($value = $this->prop1($prop, $shape)) !== null
                && $value !== "") {
                $cdbux->set_prop($prop, $value, 1);
            }
        }
        if (!$cdbur && str_starts_with($this->password, " ")) {
            $cdbux->set_prop("password", $this->password);
            $cdbux->set_prop("passwordTime", $this->passwordTime);
            $cdbux->set_prop("passwordUseTime", $this->passwordUseTime);
        }
        if (($this->cflags & self::CFM_DISABLEMENT) === 0) {
            $cdbux->set_prop("cflags", $cdbux->cflags & ~Contact::CF_PLACEHOLDER);
            $cdbux->set_prop("disabled", $cdbux->cflags & Contact::CFM_DISABLEMENT & Contact::CFM_DB);
        } else if (!$cdbur) {
            $cdbux->set_prop("cflags", $cdbux->cflags | Contact::CF_PLACEHOLDER);
            $cdbux->set_prop("disabled", $cdbux->cflags & Contact::CFM_DISABLEMENT & Contact::CFM_DB);
        }
        if (($this->cflags & self::CF_UNCONFIRMED) === 0) {
            $cdbux->set_prop("cflags", $cdbux->cflags & ~Contact::CF_UNCONFIRMED);
        }
        if (!empty($cdbux->_mod_undo)) {
            assert($cdbux->cdb_confid !== 0);
            $cdbux->save_prop();
            $cdbur = $cdbux;
        }
        if (($this->_cdb_user = $cdbur)) {
            $this->update_cdb_roles();
            return $cdbur->contactDbId;
        } else {
            return false;
        }
    }


    /** @return bool */
    function is_actas_user() {
        return ($this->_activated & 3) === 3;
    }

    /** @return Contact */
    function base_user() {
        return $this->_admin_base_user ?? $this;
    }

    /** @return bool */
    function is_bearer_authorized() {
        return ($this->_activated & 4) === 4;
    }

    /** @return bool */
    function is_empty() {
        return $this->contactId <= 0 && !$this->email && !$this->_capabilities;
    }

    /** @param string $email
     * @return bool */
    function owns_email($email) {
        return (string) $email !== "" && strcasecmp($email, $this->email) === 0;
    }

    /** @return bool */
    function is_disabled() {
        return ($this->cflags & self::CFM_DISABLEMENT & ~self::CF_PLACEHOLDER) !== 0;
    }

    /** @return bool */
    function is_dormant() {
        return ($this->cflags & self::CFM_DISABLEMENT) !== 0;
    }

    /** @return bool */
    function is_explicitly_disabled() {
        return ($this->cflags & self::CF_UDISABLED) !== 0;
    }

    /** @return bool */
    function is_placeholder() {
        return ($this->cflags & self::CF_PLACEHOLDER) !== 0;
    }

    /** @return bool */
    function contactdb_disabled() {
        $cdbu = $this->cdb_user();
        return $cdbu && ($cdbu->cflags & self::CFM_DISABLEMENT & ~self::CF_PLACEHOLDER) !== 0;
    }

    /** @return int */
    function disabled_flags() {
        return $this->cflags & self::CFM_DISABLEMENT;
    }

    /** @return bool */
    function is_unconfirmed() {
        return ($this->cflags & self::CF_UNCONFIRMED) !== 0;
    }

    /** @param bool $self_requested
     * @return bool */
    function can_receive_mail($self_requested = false) {
        $disabled = self::CFM_DISABLEMENT;
        if ($self_requested) {
            $disabled &= ~self::CF_PLACEHOLDER;
        } else if (($this->cflags & self::CF_UNCONFIRMED) !== 0
                   && $this->conf->opt("sendEmailUnconfirmed") === false) {
            $disabled |= self::CF_UNCONFIRMED;
        }
        $e = $this->preferredEmail ? : $this->email;
        return ($this->cflags & $disabled) === 0 && self::is_real_email($e);
    }

    /** @param string $email
     * @return bool */
    static function is_real_email($email) {
        $len = strlen($email);
        return ($at = strpos($email, "@")) !== false
            && $at + 1 < $len
            && $email[$at + 1] !== "_"
            && ($at + 12 > $len
                || (ord($email[$len - 11]) | 0x20) !== 0x65 /* 'e' */
                || !preg_match('/\G[@.]example\.(?:com|net|org|edu)\z/i', $email, $m, 0, $len - 12));
    }

    /** @return int */
    function session_index() {
        return $this->_activated > 0 ? $this->_activated >> 8 : -1;
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

    /** @return string */
    function searchable_name() {
        if ($this->firstName !== "" && $this->lastName !== "") {
            $name = "{$this->firstName} {$this->lastName}";
        } else {
            $name = $this->firstName . $this->lastName;
        }
        if ($name !== "" && $this->affiliation !== "") {
            $name = "{$name} ({$this->affiliation})";
        } else if ($this->affiliation !== "") {
            $name = "({$this->affiliation})";
        }
        return $name;
    }

    /** @return string */
    function db_searchable_name() {
        return substr(strtolower(UnicodeHelper::deaccent($this->searchable_name())), 0, 2048);
    }

    /** @return array{email?:string,first?:string,last?:string,affiliation?:string} */
    function unparse_nea_json() {
        return Author::unparse_nea_json_for($this);
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

    /** @param ''|'t'|'r'|'ra'|'rn' $pfx
     * @param Author|Contact $user */
    private function calculate_name_for($pfx, $user) {
        $flags = NAME_P;
        if (($user->nameAmbiguous ?? false) || $pfx === "ra") {
            $flags |= NAME_E;
        }
        $n = Text::nameo($user, $flags);
        if ($pfx !== "t") {
            $n = htmlspecialchars($n);
        }
        if (($pfx === "r" || $pfx === "ra" || $pfx === "rn")
            && ($this->isPC || $this->tracker_kiosk_state > 0)) {
            $dt = $this->conf->tags();
            $ctflags = ($dt->has_role_decoration ? self::CTFLAG_ROLES : 0)
                | ($pfx !== "rn" ? self::CTFLAG_DISABLED : 0);
            $act = self::all_contact_tags_for($user, $ctflags);
            if ($act !== ""
                && $this->can_view_user_tags()
                && ($viewable = $dt->censor(TagMap::CENSOR_VIEW, $act, $this, null))) {
                if (($colors = $dt->color_classes($viewable))) {
                    $n = "<span class=\"{$colors} taghh\">{$n}</span>";
                }
                if ($dt->has(TagInfo::TFM_DECORATION)) {
                    $n .= (new Tagger($this))->unparse_decoration_html($viewable, Tagger::DECOR_USER);
                }
            }
        }
        return $n;
    }

    /** @param ''|'t'|'r'|'ra'|'rn' $pfx
     * @param ReviewInfo|Contact|int $x
     * @return mixed */
    function name_for($pfx, $x) {
        $uid = is_int($x) ? $x : $x->contactId;
        $key = $pfx . $uid;
        if (isset($this->_name_for_map[$key])) {
            return $this->_name_for_map[$key];
        }

        if ($uid === $this->contactId) {
            $u = $this;
        } else if (is_int($x)) {
            $u = $this->conf->user_by_id($uid, USER_SLICE);
        } else if ($x instanceof ReviewInfo) {
            $u = $x->reviewer();
            if ($x->nameAmbiguous && $pfx === "r" && !$u->nameAmbiguous) {
                return $this->name_for("ra", $u);
            }
        } else {
            $u = $x;
        }

        $n = $u ? $this->calculate_name_for($pfx, $u) : "";
        $this->_name_for_map[$key] = $n;
        return $n;
    }

    /** @param int|Contact|ReviewInfo $x
     * @return string */
    function name_html_for($x) {
        return $this->name_for("", $x);
    }

    /** @param int|Contact|ReviewInfo $x
     * @return string */
    function name_text_for($x) {
        return $this->name_for("t", $x);
    }

    /** @param int|Contact|ReviewInfo $x
     * @return string */
    function reviewer_html_for($x) {
        return $this->name_for($this->isPC ? "r" : "", $x);
    }

    /** @param int|Contact|ReviewInfo $x
     * @return string */
    function reviewer_text_for($x) {
        return $this->name_for("t", $x);
    }

    /** @param array<int,mixed> &$array */
    function ksort_cid_array(&$array) {
        $this->conf->prefetch_users_by_id(array_keys($array));
        uksort($array, function ($a, $b) {
            $au = $this->conf->user_by_id($a, USER_SLICE);
            $bu = $this->conf->user_by_id($b, USER_SLICE);
            if ($au && $au->pc_index !== null && $bu && $bu->pc_index !== null) {
                return $au->pc_index <=> $bu->pc_index;
            } else if ($au && $bu) {
                return call_user_func($this->conf->user_comparator(), $au, $bu);
            } else if ($au || $bu) {
                return $au ? -1 : 1;
            } else {
                return $a <=> $b;
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
        return $this->email && $this->_activated !== 0;
    }

    /** @return bool */
    function has_account_here() {
        return $this->contactId > 0;
    }

    /** @return bool */
    function is_root_user() {
        return $this->_root_user;
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
            return stripos($this->contactTags, " {$t}#") !== false;
        }
        if ($this->contactTags === false) {
            trigger_error("Contact {$this->email} contactTags missing\n" . debug_string_backtrace());
            $this->contactTags = null;
        }
        return false;
    }

    /** @param string $t
     * @return ?float */
    function tag_value($t) {
        if (($this->roles & self::ROLE_PC) && strcasecmp($t, "pc") == 0) {
            return 0.0;
        } else if ($this->contactTags
                   && ($p = stripos($this->contactTags, " {$t}#")) !== false) {
            return (float) substr($this->contactTags, $p + strlen($t) + 2);
        } else {
            return null;
        }
    }

    /** @param string $perm
     * @return ?bool */
    function perm_tag_allows($perm) {
        if ($this->contactTags
            && ($pos = stripos($this->contactTags, " perm:{$perm}#")) !== false) {
            return $this->contactTags[$pos + strlen($perm) + 7] !== "-";
        } else {
            return null;
        }
    }

    const CTFLAG_ROLES = 1;
    const CTFLAG_DISABLED = 2;

    /** @param Contact|Author $x
     * @param 0|1|2|3 $ctags
     * @return string */
    static function all_contact_tags_for($x, $ctags) {
        $tags = $x->contactTags ?? "";
        if (($ctags & self::CTFLAG_ROLES) !== 0
            && ($x->roles & self::ROLE_PC) !== 0) {
            $tags = " pc#0{$tags}";
        }
        if (($ctags & self::CTFLAG_DISABLED) !== 0
            && $x->disabled_flags() !== 0) {
            $tags = "{$tags} dim#0";
        }
        return $tags;
    }

    /** @return string */
    function all_contact_tags() {
        return self::all_contact_tags_for($this, self::CTFLAG_ROLES);
    }

    /** @return string */
    function viewable_tags(Contact $viewer) {
        // see also Contact::calculate_name_for
        if ($viewer->can_view_user_tags() || $viewer->contactXid === $this->contactXid) {
            $tags = $this->all_contact_tags();
            return $this->conf->tags()->censor(TagMap::CENSOR_VIEW, $tags, $viewer, null);
        } else {
            return "";
        }
    }

    /** @return string */
    function viewable_color_classes(Contact $viewer) {
        if (($tags = $this->viewable_tags($viewer))) {
            return $this->conf->tags()->color_classes($tags);
        } else {
            return "";
        }
    }

    /** @param string $perm
     * @return bool */
    function has_permission($perm) {
        return !$perm || $this->has_tag(substr($perm, 1)) === ($perm[0] === "+");
    }


    /** @return object */
    private function make_data() {
        $this->_slice !== 0 && $this->unslice();
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

    /** @param string $key */
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
        $cdb = $this->cdb_confid !== 0;
        $key = $cdb ? "contactDbId" : "contactId";
        $cid = $cdb ? $this->contactDbId : $this->contactId;
        $change = array_to_object_recursive($data);
        assert($cid > 0);
        Dbl::compare_exchange(
            $cdb ? $this->conf->contactdb() : $this->conf->dblink,
            "select `data` from ContactInfo where {$key}=?", [$cid],
            function ($old) use ($change) {
                $this->data = $old;
                $this->_jdata = null;
                object_replace_recursive($this->make_data(), $change);
                return $this->encode_data();
            },
            "update ContactInfo set data=?{desired} where {$key}=? and data?{expected}e", [$cid]
        );
    }

    /** @return ?string */
    function data_str() {
        $this->_slice !== 0 && $this->unslice();
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
        return $this->_capabilities[$name] ?? null;
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
            return $this->conf->user_by_id($rcid, USER_SLICE);
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
        foreach (explode(" ", $text) as $s) {
            if ($s !== "" && ($uf = $this->conf->token_handler($s))) {
                call_user_func($uf->apply_function, $this, $uf, $s);
            }
        }
    }

    function clear_capabilities() {
        $this->_capabilities = null;
        unset($this->_hoturl_defaults["cap"]);
        $this->update_my_rights();
    }

    /** @param string $text
     * @param bool $add */
    function set_default_cap_param($text, $add) {
        $cap = urldecode($this->_hoturl_defaults["cap"] ?? "");
        $a = array_diff(explode(" ", $cap), [$text, ""]);
        if ($add) {
            $a[] = $text;
        }
        if (empty($a)) {
            unset($this->_hoturl_defaults["cap"]);
        } else {
            $this->_hoturl_defaults["cap"] = urlencode(join(" ", $a));
        }
    }

    /** @return array<string,string> -- Note that return values are urlencoded */
    function hoturl_defaults() {
        return $this->_hoturl_defaults ?? [];
    }


    /** @param ?Qrequest $qreq */
    function escape($qreq = null) {
        $qreq = $qreq ?? Qrequest::$main_request;

        if ($qreq->ajax) {
            if ($this->is_empty()) {
                $m = "<0>You have been signed out";
            } else if (!$this->is_signed_in()) {
                $m = "<0>You must sign in to access this function";
            } else {
                $m = "<0>You don’t have permission to access this function";
            }
            $jr = JsonResult::make_error(403, $m);
            if (!$this->is_signed_in()) {
                $jr->content["loggedout"] = true;
            }
            json_exit($jr);
        }

        if ($this->is_signed_in()) {
            Multiconference::fail($qreq, 403, ["link" => true], "<0>Page inaccessible");
        }

        $x = [];
        if (($path = $qreq->path())) {
            $x["__PATH__"] = preg_replace('/^\/+/', "", $path);
        }
        $url = $this->conf->selfurl($qreq, $x, Conf::HOTURL_RAW | Conf::HOTURL_SITEREL);

        if (!$qreq->valid_post()) {
            Multiconference::fail($qreq, 403, $this->conf->_i("signin_required", new FmtArg("action", $qreq->page()), new FmtArg("url", $this->conf->hoturl_raw("signin", ["redirect" => $url]), 0)));
        }

        // Preserve post values across session expiration.
        $qreq->open_session();
        $qreq->set_gsession("login_bounce", [$this->conf->session_key, $url, $qreq->page(), $_POST, Conf::$now + 120]);
        $this->conf->feedback_msg([
            MessageItem::error($this->conf->_i("signin_required", new FmtArg("action", $qreq->page()))),
            MessageItem::inform("<0>Your changes were not saved. After signing in, you may try to submit them again")
        ]);
        $this->conf->redirect();
    }


    const SAVE_ANY_EMAIL = 1;
    const SAVE_SELF_REGISTER = 2;

    function change_email($email) {
        assert($this->has_account_here());
        assert($this->_slice === 0);
        $old_email = $this->email;
        $aupapers = self::email_authored_papers($this->conf, $email, $this);
        $this->conf->ql("update ContactInfo set email=? where contactId=?", $email, $this->contactId);
        $this->save_authored_papers($aupapers);

        if (!$this->password
            && ($cdbu = $this->cdb_user())
            && $cdbu->password) {
            $this->password = $cdbu->password;
            $this->passwordTime = $cdbu->passwordTime;
            $this->passwordUseTime = $cdbu->passwordUseTime;
        }
        $this->email = $email;
        $this->update_cdb();

        if ($this->roles & Contact::ROLE_PCLIKE) {
            $this->conf->invalidate_caches(["pc" => true]);
        }
        $this->conf->log_for($this, $this, "Account edited: email ({$old_email} to {$email})");
    }

    /** @param string $email
     * @param object $reg
     * @return list<int> */
    static function email_authored_papers(Conf $conf, $email, $reg) {
        $aupapers = [];
        $result = $conf->q("select paperId, authorInformation from Paper where authorInformation like " . Dbl::utf8ci("'%\t?ls\t%'"), $email);
        while (($row = $result->fetch_row())) {
            foreach (PaperInfo::parse_author_list($row[1]) as $au) {
                if (strcasecmp($au->email, $email) !== 0) {
                    continue;
                }
                $aupapers[] = (int) $row[0];
                if ($reg
                    && ($au->firstName !== "" || $au->lastName !== "")
                    && ($reg->firstName ?? "") === ""
                    && ($reg->lastName ?? "") === "") {
                    $reg->firstName = $au->firstName;
                    $reg->lastName = $au->lastName;
                }
                if ($reg
                    && $au->affiliation !== ""
                    && ($reg->affiliation ?? "") === "") {
                    $reg->affiliation = $au->affiliation;
                }
            }
        }
        Dbl::free($result);
        return $aupapers;
    }

    /** @param list<int> $aupapers */
    private function save_authored_papers($aupapers) {
        if (empty($aupapers) || $this->contactId <= 0) {
            return;
        }
        $ps = [];
        foreach ($aupapers as $pid) {
            $ps[] = [$pid, $this->contactId, CONFLICT_AUTHOR];
        }
        $this->conf->ql("insert into PaperConflict (paperId, contactId, conflictType) values ?v on duplicate key update conflictType=(conflictType|" . CONFLICT_AUTHOR . ")", $ps);
    }


    /** @param string $prop
     * @param int $shape */
    private function prop1($prop, $shape) {
        if ($this->_slice !== 0 && ($shape & self::PROP_SLICE) === 0) {
            $this->unslice();
        }
        return ($shape & self::PROP_DATA) === 0 ? $this->$prop : $this->data($prop);
    }

    /** @param string $prop
     * @param mixed $value
     * @return mixed */
    private function clean_prop_value($prop, $value) {
        if ($prop === "roles") {
            return $value & ($this->cdb_confid === 0 ? self::ROLE_DBMASK : self::ROLE_CDBMASK);
        } else if ($prop === "cflags") {
            return $value & self::CFM_DB;
        } else {
            assert(false);
            return $value;
        }
    }

    /** @param string $prop */
    function prop($prop) {
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop {$prop}");
        }
        return $this->prop1($prop, $shape);
    }

    /** @param string $prop */
    function gprop($prop) {
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop {$prop}");
        }
        $value = $this->prop1($prop, $shape);
        if ($value === null || ($value === "" && ($shape & self::PROP_NULL) !== 0)) {
            if (($shape & self::PROP_CDB) !== 0
                && ($cdbu = $this->cdb_user())
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
     * @param 0|1|2 $ifempty
     * @return void
     *
     * `$ifempty` is used to control whether updates are ignored. This
     * important when merging users from different sources.
     * An update is ignored when:
     * - `$ifempty !== 0`, AND
     * - the current value of the property is not empty, AND
     * - EITHER `$ifempty === 2`, OR the new value is empty, OR the current
     *   user is not a placeholder. */
    function set_prop($prop, $value, $ifempty = 0) {
        // validate argument
        $shape = self::$props[$prop] ?? 0;
        if ($shape === 0) {
            throw new Exception("bad prop {$prop}");
        }
        if (($value !== null || ($shape & self::PROP_NULL) === 0)
            && ((($shape & self::PROP_INT) !== 0 && !is_int($value))
                || (($shape & self::PROP_BOOL) !== 0 && !is_bool($value))
                || (($shape & self::PROP_STRING) !== 0 && !is_string($value))
                || (($shape & self::PROP_STRINGLIST) !== 0 && !is_string_list($value)))) {
            throw new Exception("bad prop type {$prop} " . var_export($value, true));
        }
        // check if property applies here
        if (($shape & ($this->cdb_confid !== 0 ? self::PROP_CDB : self::PROP_LOCAL)) === 0) {
            return;
        }
        // check `$ifempty`
        $old = $this->prop1($prop, $shape);
        if ($ifempty !== 0
            && ($ifempty === 2
                || ($this->cflags & self::CF_PLACEHOLDER) === 0
                || $value === null
                || $value === "")
            && $old !== null
            && $old !== "") {
            return;
        }
        // simplify
        if (($shape & self::PROP_SIMPLIFY) !== 0 && is_string($value)) {
            $value = simplify_whitespace($value);
        }
        // check for no change
        if ($value === "" && ($shape & self::PROP_NULL) !== 0) {
            $value = null;
        }
        // save
        if (($shape & self::PROP_DATA) !== 0) {
            $this->set_data($prop, $value);
        } else {
            $this->_mod_undo = $this->_mod_undo ?? [];
            $has_old = array_key_exists($prop, $this->_mod_undo);
            $oldv = $has_old ? $this->_mod_undo[$prop] : $old;
            $newv = $value;
            if (($shape & self::PROP_SPECIAL) !== 0) {
                $oldv = $this->clean_prop_value($prop, $oldv);
                $newv = $this->clean_prop_value($prop, $newv);
            }
            if ($oldv !== $newv
                || ($newv === null
                    && ($this->cdb_confid !== 0 ? $this->contactDbId <= 0 : $this->contactId <= 0))) {
                if (!$has_old) {
                    $this->_mod_undo[$prop] = $old;
                }
            } else {
                if ($has_old) {
                    unset($this->_mod_undo[$prop]);
                }
                $shape &= ~self::PROP_UPDATE;
            }
            $this->$prop = $value;
        }
        if (($shape & self::PROP_UPDATE) !== 0) {
            if (!array_key_exists("updateTime", $this->_mod_undo)) {
                $this->_mod_undo["updateTime"] = $this->updateTime;
            }
            $this->updateTime = Conf::$now;
        }
        if ($this->_aucollab_matchers
            && in_array($prop, ["firstName", "lastName", "email", "affiliation"])) {
            $this->_aucollab_matchers = $this->_aucollab_general_pregexes = null;
        }
        if ($prop === "cflags") {
            $this->set_roles_properties();
        }
    }

    /** @param string $tag
     * @param false|int|float $value
     * @return void */
    function change_tag_prop($tag, $value) {
        assert(strcasecmp($tag, "pc") !== 0 && strcasecmp($tag, "chair") !== 0);
        $shape = self::$props["contactTags"];
        $svalue = $this->prop1("contactTags", $shape) ?? "";
        if (($pos = stripos($svalue, " {$tag}#")) !== false) {
            $epos = $pos + strlen($tag) + 2;
            $space = strpos($svalue, " ", $epos);
            $space = $space === false ? strlen($svalue) : $space;
            if ($value !== false) {
                $svalue = substr($svalue, 0, $epos) . ((float) $value) . substr($svalue, $space);
            } else {
                $svalue = substr($svalue, 0, $pos) . substr($svalue, $space);
            }
        } else if ($value !== false) {
            $lvalue = $svalue === "" ? [] : explode(" ", trim($svalue));
            $lvalue[] = "{$tag}#" . ((float) $value);
            sort($lvalue);
            $svalue = " " . join(" ", $lvalue);
        }
        $this->set_prop("contactTags", $svalue === "" ? null : $svalue);
    }

    /** @param ?string $prop
     * @return bool */
    function prop_changed($prop = null) {
        return $prop ? array_key_exists($prop, $this->_mod_undo ?? []) : !empty($this->_mod_undo);
    }

    /** @param bool $confirm
     * @return bool */
    function activate_placeholder_prop($confirm) {
        $changed = false;
        if (($this->cflags & self::CF_PLACEHOLDER) !== 0) {
            $this->set_prop("cflags", $this->cflags & ~self::CF_PLACEHOLDER);
            $this->set_prop("disabled", $this->cflags & self::CFM_DISABLEMENT & self::CFM_DB);
            $changed = true;
        }
        if (($this->cflags & self::CF_UNCONFIRMED) !== 0 && $confirm) {
            $this->set_prop("cflags", $this->cflags & ~self::CF_UNCONFIRMED);
            $changed = true;
        }
        return $changed;
    }

    /** @return bool */
    function save_prop() {
        if ($this->cdb_confid !== 0) {
            $db = $this->conf->contactdb();
            $idk = "contactDbId";
            $flag = self::PROP_CDB;
        } else {
            $db = $this->conf->dblink;
            $idk = "contactId";
            $flag = self::PROP_LOCAL;
        }
        if ($this->$idk <= 0) {
            $this->_mod_undo = $this->_mod_undo ?? [];
            if (!array_key_exists("password", $this->_mod_undo)) {
                $this->password = validate_email($this->email) ? " unset" : " nologin";
                $this->passwordTime = Conf::$now;
            }
            if ($flag === self::PROP_LOCAL
                && !array_key_exists("cdbRoles", $this->_mod_undo)) {
                $this->cdbRoles = 0;
            }
        } else if (empty($this->_mod_undo)) {
            return true;
        }
        $qf = $qv = [];
        foreach (self::$props as $prop => $shape) {
            if (($shape & $flag) !== 0
                && (array_key_exists($prop, $this->_mod_undo)
                    || ($this->$idk <= 0 && ($shape & self::PROP_NULL) === 0))) {
                $qf[] = "{$prop}=?";
                $value = $this->prop1($prop, $shape);
                if (($shape & self::PROP_SPECIAL) !== 0) {
                    $value = $this->clean_prop_value($prop, $value);
                }
                if ($value === false || $value === true) {
                    $qv[] = (int) $value;
                } else if ($value === null && ($shape & self::PROP_NULL) === 0) {
                    $qv[] = 0;
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
             || array_key_exists("lastName", $this->_mod_undo)
             || array_key_exists("affiliation", $this->_mod_undo))
            && $this->cdb_confid === 0) {
            $qf[] = "unaccentedName=?";
            $qv[] = $this->db_searchable_name();
        }
        if ($this->$idk > 0) {
            $qv[] = $this->$idk;
            $result = Dbl::qe_apply($db, "update ContactInfo set " . join(", ", $qf) . " where {$idk}=?", $qv);
        } else {
            assert($this->email !== "");
            $result = Dbl::qe_apply($db, "insert into ContactInfo set " . join(", ", $qf) . " on duplicate key update cflags=cflags", $qv);
            if ($result->affected_rows > 0) {
                $this->$idk = (int) $result->insert_id;
                if ($this->cdb_confid === 0) {
                    $this->contactXid = (int) $result->insert_id;
                }
            }
        }
        if ($result->is_error()) {
            error_log("{$this->conf->dbname}: save {$this->email} fails {$result->errno} " . debug_string_backtrace());
            return false;
        } else if ($this->$idk <= 0) {
            return false;
        } else {
            // invalidate caches
            $this->_mod_undo = null;
            $this->conf->invalidate_user($this, true);
            return true;
        }
    }

    function abort_prop() {
        foreach ($this->_mod_undo as $prop => $value) {
            $this->$prop = $value;
        }
        $this->_mod_undo = $this->_jdata = null;
        $this->_aucollab_matchers = $this->_aucollab_general_pregexes = null;
        $this->set_roles_properties();
    }


    /** @param int $new_roles
     * @param ?Contact $actor
     * @param bool $skip_log
     * @return int */
    function save_roles($new_roles, $actor, $skip_log = false) {
        assert(($new_roles & self::ROLE_DBMASK) === $new_roles);
        $old_roles = $this->roles & self::ROLE_DBMASK;
        if (($old_roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) !== 0
            && ($new_roles & (self::ROLE_ADMIN | self::ROLE_CHAIR)) === 0) {
            // ensure there's at least one chair or system administrator
            // (MySQL 5.5 requires this syntax, not a subselect)
            $result = $this->conf->qe("update ContactInfo c, (select contactId from ContactInfo where roles>? and (roles&?)!=0 and contactId!=? limit 1) d
                set c.roles=? where c.contactId=? and d.contactId is not null",
                self::ROLE_PC, self::ROLE_ADMIN | self::ROLE_CHAIR, $this->contactId,
                $new_roles, $this->contactId);
        } else {
            $result = $this->conf->qe("update ContactInfo set roles=? where contactId=?",
                $new_roles, $this->contactId);
        }
        // save the roles bits
        if ($result->affected_rows > 0) {
            if (!$skip_log) {
                $this->conf->log_for($actor ?? $this, $this, "Account edited: roles [" . UserStatus::unparse_roles_diff($old_roles, $new_roles) . "]");
            }
            $this->roles = $new_roles;
            $this->_session_roles = (($this->_session_roles ?? 0) & ~self::ROLE_DBMASK) | $new_roles;
            $this->set_roles_properties();
            $this->conf->invalidate_caches(["pc" => true]);
            $this->conf->invalidate_user($this, true);
            $this->update_cdb_roles();
            return $new_roles;
        } else {
            return $old_roles;
        }
    }

    static function importable_props() {
        foreach (self::$props as $prop => $shape) {
            if (($shape & self::PROP_IMPORT) !== 0)
                yield $prop => $shape;
        }
    }

    /** @param Contact $src
     * @param 0|1|2 $ifempty */
    function import_prop($src, $ifempty) {
        foreach (self::importable_props() as $prop => $shape) {
            if (($value = $src->prop1($prop, $shape)) !== null
                && $value !== "") {
                $this->set_prop($prop, $value, $ifempty);
            }
        }

        // disablement import is special
        // source is disabled local user, creating cdb user: cdb is placeholder
        $sdflags = $src->cflags & self::CFM_DISABLEMENT;
        if ($sdflags !== 0
            && $this->cdb_confid !== 0
            && $this->contactDbId === 0) {
            $this->set_prop("cflags", $this->cflags | self::CF_PLACEHOLDER);
            $this->set_prop("disabled", $this->cflags & self::CFM_DISABLEMENT & self::CFM_DB);
        }
        // source is non-disabled local user: this is not placeholder
        if ($src->cdb_confid === 0
            && $sdflags === 0
            && ($this->cflags & self::CF_PLACEHOLDER) !== 0) {
            $this->set_prop("cflags", $this->cflags & ~self::CF_PLACEHOLDER);
            $this->set_prop("disabled", $this->cflags & self::CFM_DISABLEMENT & self::CFM_DB);
        }
        // source is globally disabled: this local user is disabled
        if (($sdflags & self::CF_GDISABLED) !== 0
            && $src->cdb_confid !== 0
            && $this->cdb_confid === 0) {
            $this->set_prop("cflags", $this->cflags | self::CF_GDISABLED);
            $this->set_prop("disabled", $this->cflags & self::CFM_DISABLEMENT & self::CFM_DB);
        }

        // unconfirmed import is special
        // source is confirmed: this is confirmed
        if (($src->cflags & self::CF_UNCONFIRMED) === 0) {
            $this->set_prop("cflags", $this->cflags & ~self::CF_UNCONFIRMED);
        }
    }

    /** @param int $flags
     * @param ?Contact $actor
     * @return ?Contact */
    function store($flags = 0, $actor = null) {
        // clean registration
        assert(is_string($this->email));
        assert($this->email === trim($this->email));
        assert(empty($this->_mod_undo));
        assert($this->contactId <= 0);

        // maybe refuse to create
        $localu = null;
        $valid_email = validate_email($this->email);
        if ((!$valid_email
             && ($flags & self::SAVE_ANY_EMAIL) === 0)
            || (($flags & self::SAVE_SELF_REGISTER) !== 0
                && !$this->conf->allow_user_self_register())) {
            $localu = $this->conf->fresh_user_by_email($this->email);
            if (!$localu) {
                return null;
            }
        }

        // `$this` will become a non-cdb user, so invalidate it
        if ($this->cdb_confid !== 0) {
            $this->conf->invalidate_user($this);
            $this->cdb_confid = $this->contactDbId = 0;
            $this->_cdb_user = false;
        }

        // look up cdb account
        $cdbu = null;
        if ($valid_email
            && self::is_real_email($this->email)
            && ($cdb = $this->conf->contactdb())) {
            $cdbu = $this->cdb_user() ?? Contact::make_cdb_email($this->conf, $this->email);
        }

        // import properties from cdb
        if (!$localu) {
            $this->_mod_undo = ["disabled" => 0, "cflags" => 0];
            foreach (self::importable_props() as $prop => $shape) {
                $this->_mod_undo[$prop] = ($shape & self::PROP_NULL) !== 0 ? null : "";
            }
            if ($cdbu) {
                $this->password = "";
                if ($cdbu->contactDbId > 0) {
                    $this->import_prop($cdbu, $cdbu->is_placeholder() ? 2 : 0);
                    $this->passwordTime = $cdbu->passwordTime;
                } else {
                    $this->passwordTime = 0;
                }
                $this->passwordUseTime = 0;
                $this->_mod_undo["password"] = null;
                $this->_mod_undo["passwordTime"] = null;
                $this->_mod_undo["passwordUseTime"] = null;
            }
            $this->_cdb_user = $cdbu;

            if ($this->save_prop()) {
                // log creation (except for placeholder accounts)
                if (($this->cflags & self::CF_PLACEHOLDER) === 0) {
                    $msg = "Account created";
                    if (($this->cflags & self::CFM_DISABLEMENT & ~self::CF_ROLEDISABLED) !== 0) {
                        $msg .= ", disabled";
                    }
                    $this->conf->log_for($actor && $actor->has_email() ? $actor : $this, $this, $msg);
                }
            } else {
                // maybe user already existed
                $localu = $this->conf->fresh_user_by_email($this->email);
                if (!$localu) {
                    return null;
                }
            }
        }

        // if `$localu` is set, update local user
        if ($localu) {
            $localu->import_prop($this, $localu->is_placeholder() ? 0 : 1);
            $localu->save_prop(); // may do nothing
            $this->unslice_using($localu, true);
            $this->set_roles_properties();
        }

        // update cdb user if necessary
        if ($cdbu) {
            $cdbu->import_prop($this, $cdbu->is_placeholder() ? 0 : 1);
            $cdbu->save_prop();
        }

        return $this;
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
        assert(($this->_slice & self::SLICEBIT_PASSWORD) === 0);
        $cdbu = $this->cdb_user();
        return (!$cdbu
                || (string) $cdbu->password === ""
                || str_starts_with($cdbu->password, " unset"))
            && ((string) $this->password === ""
                || str_starts_with($this->password, " unset")
                || ($cdbu && (string) $cdbu->password !== "" && $cdbu->passwordTime >= $this->passwordTime));
    }

    /** @return bool */
    function can_reset_password() {
        assert(($this->_slice & self::SLICEBIT_PASSWORD) === 0);
        $cdbu = $this->cdb_user();
        return !$this->conf->external_login()
            && !str_starts_with((string) $this->password, " nologin")
            && (!$cdbu || !str_starts_with((string) $cdbu->password, " nologin"));
    }


    // obsolete
    private function password_hmac_key($keyid) {
        if ($keyid === null) {
            $keyid = $this->conf->opt("passwordHmacKeyid") ?? 0;
        }
        $key = $this->conf->opt("passwordHmacKey.$keyid");
        if (!$key && $keyid == 0) {
            $key = $this->conf->opt("passwordHmacKey");
        }
        if (!$key) { /* backwards compatibility */
            $key = $this->conf->setting_data("passwordHmacKey.$keyid");
        }
        if (!$key) {
            error_log("missing passwordHmacKey.{$keyid}, using default");
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
        return $this->conf->opt("passwordHashMethod") ?? PASSWORD_DEFAULT;
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
     * @return array{ok:true,user:$this}|array{ok:false} */
    function check_password_info($input) {
        assert(!$this->conf->external_login());
        assert($this->_slice === 0);
        $cdbu = $this->cdb_user();

        // check passwords
        $local_ok = $this->contactId > 0
            && $this->password
            && $this->check_hashed_password($input, $this->password);
        $cdb_password = $cdbu ? (string) $cdbu->password : "";
        $cdb_ok = $cdb_password
            && $this->check_hashed_password($input, $cdbu->password);
        $cdb_older = !$cdbu || $cdbu->passwordTime < $this->passwordTime;

        // invalid passwords cannot be used to log in
        if (trim($input) === "") {
            return ["ok" => false, "nopw" => true];
        } else if ($input === "0" || $input === "*") {
            return ["ok" => false, "invalid" => true];
        }

        // users with reset passwords cannot log in
        if (str_starts_with($cdb_password, " reset")
            || ($cdb_older
                && !$cdb_ok
                && str_starts_with($this->password, " reset"))) {
            return ["ok" => false, "reset" => true];
        }

        // users with unset passwords cannot log in
        // This logic should match Contact::password_unset().
        if (((!$cdb_older || !$local_ok)
             && str_starts_with($cdb_password, " unset"))
            || ($cdb_password === ""
                && str_starts_with($this->password, " unset"))
            || ($cdb_password === ""
                && (string) $this->password === "")) {
            if (($this->contactId > 0 && !$this->is_dormant())
                || ($cdbu && !$cdbu->is_dormant())) {
                return ["ok" => false, "email" => true, "unset" => true, "can_reset" => $this->can_reset_password()];
            } else {
                return ["ok" => false, "email" => true, "noaccount" => true];
            }
        }

        // deny if no match
        if (!$cdb_ok && !$local_ok) {
            $x = [
                "ok" => false, "invalid" => true, "usec" => [[0, false]],
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
            if ($cdb_password !== "") {
                if ($cdb_password[0] === " "
                    && $cdb_password[1] !== "$") {
                    $x["cdbu_password"] = $cdb_password;
                }
                if ($cdbu->passwordTime > 0) {
                    $x["cdb_password_age"] = ceil((Conf::$now - $cdbu->passwordTime) / 8640) / 10;
                }
            }
            return $x;
        }

        // disabled users cannot log in
        // (NB all `anonymous` users should be disabled)
        if (($this->contactId > 0 && $this->is_disabled())
            || ($cdbu && $cdbu->is_disabled())) {
            return ["ok" => false, "email" => true, "disabled" => true];
        }

        // otherwise, the login attempt succeeds

        // create cdb user
        if (!$cdbu && $this->conf->contactdb()) {
            $this->update_cdb();
            $cdbu = $this->cdb_user();
        }

        // update cdb password
        if ($cdb_ok
            || ($cdbu && $cdb_password === "")) {
            if (!$cdb_ok || $this->password_needs_rehash($cdb_password)) {
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

        return ["ok" => true, "user" => $this, "usec" => [[0, true]]];
    }

    /** @param string $input
     * @return bool */
    function check_password($input) {
        $x = $this->check_password_info($input);
        return $x["ok"];
    }

    /** @param string $new */
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

        // prefer to store passwords in cdb
        $cdbu = $this->cdb_user();
        $saveu = $cdbu ?? ($this->contactId ? $this : null);
        if ($saveu) {
            $saveu->set_prop("password", $hash);
            $saveu->set_prop("passwordTime", Conf::$now);
            $saveu->set_prop("passwordUseTime", $use_time);
            $saveu->activate_placeholder_prop(false);
            $saveu->save_prop();
        }
        if ($saveu !== $this && $this->contactId) {
            if ((string) $this->password !== "") {
                $this->set_prop("password", "");
                $this->set_prop("passwordTime", Conf::$now);
                $this->set_prop("passwordUseTime", $use_time);
            }
            $this->activate_placeholder_prop(false);
            $this->save_prop();
        }
    }


    /** @return HotCRPMailPreparation */
    function prepare_mail($template, $rest = []) {
        return (new HotCRPMailer($this->conf, $this, $rest))->prepare($template, $rest);
    }


    function mark_login() {
        // at least one login every 30 days is marked as activity
        if ((int) $this->activity_at <= Conf::$now - 2592000
            || (($cdbu = $this->cdb_user())
                && ((int) $cdbu->activity_at <= Conf::$now - 2592000))) {
            $this->mark_activity();
        }
    }

    function mark_activity() {
        if ((!$this->activity_at || $this->activity_at < Conf::$now)
            && !$this->is_anonymous_user()) {
            $this->activity_at = Conf::$now;
            if ($this->contactId) {
                $this->conf->ql("update ContactInfo set lastLogin=" . Conf::$now . " where contactId={$this->contactId}");
            }
            $this->update_cdb_roles();
        }
    }

    /** @param string $text
     * @param null|int|PaperInfo|list<int|PaperInfo> $pids */
    function log_activity($text, $pids = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log_for($this, $this, $text, $pids);
        }
    }

    /** @param null|int|Contact $dest_user
     * @param string $text
     * @param null|int|PaperInfo|list<int|PaperInfo> $pids */
    function log_activity_for($dest_user, $text, $pids = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log_for($this, $dest_user, $text, $pids);
        }
    }

    /** @param string $text
     * @param null|int|PaperInfo|list<int|PaperInfo> $pids */
    function log_activity_dedup($text, $pids = null) {
        $this->mark_activity();
        if (!$this->is_anonymous_user()) {
            $this->conf->log_for($this, $this, $text, $pids, true);
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

    /** @param int $wantmask */
    private function check_author_reviewer_status($wantmask) {
        if ($this->_rights_version === self::$rights_version
            && ($this->role_mask & $wantmask) === $wantmask) {
            return;
        }
        $this->check_rights_version();
        $rmask = self::ROLE_AUTHOR | self::ROLE_REVIEWER | self::ROLE_REQUESTER;
        $this->roles &= ~$rmask;
        $this->_session_roles &= ~$rmask;
        $this->role_mask |= $rmask;
        // Load from database
        $this->_conflict_types = [];
        if ($this->contactId > 0) {
            $qs = [
                "(select group_concat(paperId, ' ', conflictType) from PaperConflict where contactId=?)",
                "exists (select * from PaperReview where contactId=? and reviewType>0)"
            ];
            $qv = [$this->contactId, $this->contactId];
            if ($this->isPC) {
                $qs[] = "exists (select * from PaperReview where requestedBy=? and reviewType>0 and reviewType<=" . REVIEW_PC . " and contactId!=?)";
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
            if (($row = $result->fetch_row())) {
                if ($row[0] !== null) {
                    foreach (explode(",", $row[0]) as $pc) {
                        $sp = strpos($pc, " ");
                        $ct = (int) substr($pc, $sp + 1);
                        $this->_conflict_types[(int) substr($pc, 0, $sp)] = $ct;
                        if ($ct >= CONFLICT_AUTHOR) {
                            $this->roles |= self::ROLE_AUTHOR;
                        }
                    }
                }
                $this->roles |= ($row[1] > 0 ? self::ROLE_REVIEWER : 0)
                    | ($row[2] > 0 ? self::ROLE_REQUESTER : 0);
                $this->_session_roles |= ($this->roles & $rmask)
                    | ($row[3] > 0 ? self::ROLE_REVIEWER : 0);
            }
            Dbl::free($result);
        }

        // Update contact information from capabilities
        if ($this->_capabilities) {
            foreach ($this->_capabilities as $k => $v) {
                if (str_starts_with($k, "@av") && $v) {
                    $this->_session_roles |= self::ROLE_AUTHOR;
                } else if (str_starts_with($k, "@ra") && $v) {
                    $this->_session_roles |= self::ROLE_REVIEWER;
                }
            }
        }
    }

    function check_rights_version() {
        if ($this->_rights_version !== self::$rights_version) {
            $this->role_mask = self::ROLE_DBMASK;
            $this->roles = $this->roles & self::ROLE_DBMASK;
            $this->_session_roles = $this->roles;
            $this->_conflict_types = $this->_can_view_pc = $this->_dangerous_track_mask =
                $this->_has_approvable = $this->_authored_papers = null;
            $this->_rights_version = self::$rights_version;
        }
    }

    /** @return bool */
    function is_author() {
        $this->check_author_reviewer_status(self::ROLE_AUTHOR);
        return ($this->_session_roles & self::ROLE_AUTHOR) !== 0;
    }

    /** @return list<PaperInfo> */
    function authored_papers() {
        $this->check_rights_version();
        if ($this->_authored_papers === null) {
            $this->_authored_papers = $this->is_author() ? $this->paper_set(["author" => true, "tags" => true])->as_list() : [];
        }
        return $this->_authored_papers;
    }

    /** @return associative-array<int,int> */
    function conflict_types() {
        $this->check_author_reviewer_status($this->_conflict_types === null ? -1 : 0);
        return $this->_conflict_types;
    }

    /** @return bool */
    function has_review() {
        $this->check_author_reviewer_status(self::ROLE_REVIEWER);
        return ($this->_session_roles & self::ROLE_REVIEWER) !== 0;
    }

    /** @return bool */
    function is_reviewer() {
        return $this->isPC || $this->has_review();
    }

    /** @return bool */
    function is_metareviewer() {
        if (($this->role_mask & self::ROLE_METAREVIEWER) === 0) {
            $this->role_mask |= self::ROLE_METAREVIEWER;
            if ($this->isPC
                && $this->conf->setting("metareviews")
                && !!$this->conf->fetch_ivalue("select exists (select * from PaperReview where contactId={$this->contactId} and reviewType=" . REVIEW_META . ")")) {
                $this->roles |= self::ROLE_METAREVIEWER;
            }
        }
        return ($this->roles & self::ROLE_METAREVIEWER) !== 0;
    }

    /** @return int */
    function cdb_roles() {
        if ($this->is_disabled()) {
            return 0;
        } else {
            $this->check_author_reviewer_status(self::ROLE_CDBMASK);
            return $this->roles & self::ROLE_CDBMASK;
        }
    }

    /** @return bool */
    function has_outstanding_review() {
        $this->check_rights_version();
        if (($this->role_mask & self::ROLE_OUTSTANDING_REVIEW) === 0) {
            $this->role_mask |= self::ROLE_OUTSTANDING_REVIEW;
            if ($this->has_review()
                && $this->conf->fetch_ivalue("select exists (select * from PaperReview join Paper using (paperId) where Paper.timeSubmitted>0 and " . $this->act_reviewer_sql("PaperReview") . " and reviewNeedsSubmit!=0)")) {
                $this->roles |= self::ROLE_OUTSTANDING_REVIEW;
            }
        }
        return ($this->roles & self::ROLE_OUTSTANDING_REVIEW) !== 0;
    }

    /** @return bool */
    function is_requester() {
        $this->check_author_reviewer_status(self::ROLE_REQUESTER);
        return ($this->_session_roles & self::ROLE_REQUESTER) !== 0;
    }

    /** @return bool */
    function is_discussion_lead() {
        $this->check_rights_version();
        if (($this->role_mask & self::ROLE_LEAD) === 0) {
            $this->role_mask |= self::ROLE_LEAD;
            if ($this->contactXid > 0
                && $this->isPC
                && $this->conf->has_any_lead_or_shepherd()
                && $this->conf->fetch_ivalue("select exists (select * from Paper where leadContactId=?)", $this->contactXid)) {
                $this->roles |= self::ROLE_LEAD;
            }
        }
        return ($this->roles & self::ROLE_LEAD) !== 0;
    }

    /** @return bool */
    function is_explicit_manager() {
        $this->check_rights_version();
        if (($this->role_mask & self::ROLE_EXPLICIT_MANAGER) === 0) {
            $this->role_mask |= self::ROLE_EXPLICIT_MANAGER;
            if ($this->contactXid > 0
                && $this->isPC
                && ($this->conf->check_any_admin_tracks($this)
                    || ($this->conf->has_any_manager()
                        && $this->conf->fetch_ivalue("select exists (select * from Paper where managerContactId=?)", $this->contactXid) > 0))) {
                $this->roles |= self::ROLE_EXPLICIT_MANAGER;
            }
        }
        return ($this->roles & self::ROLE_EXPLICIT_MANAGER) !== 0;
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
                    if (($hmap = $search->highlights_by_paper_id())) {
                        $colors = call_user_func_array("array_merge", array_values($hmap));
                        if (in_array("pink", $colors)) {
                            $this->_has_approvable |= 3;
                        } else if (in_array("green", $colors)) {
                            $this->_has_approvable |= 1;
                        }
                        if (in_array("yellow", $colors)) {
                            $this->_has_approvable |= 4;
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
            foreach ($rrow ? [$rrow] : $prow->all_reviews() as $rr) {
                if ($rr->reviewToken !== 0
                    && in_array($rr->reviewToken, $this->_review_tokens, true))
                    return $rr->reviewToken;
            }
        }
        return false;
    }

    /** @param false|int $token
     * @param bool $on
     * @param ?Qrequest $qreq */
    function change_review_token($token, $on, $qreq = null) {
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
            if ($qreq) {
                assert($qreq->user() === $this);
                if ($this->_review_tokens !== null) {
                    $qreq->set_csession("rev_tokens", $this->_review_tokens);
                } else {
                    $qreq->unset_csession("rev_tokens");
                }
            }
        }
        return $new_ntokens !== $old_ntokens;
    }


    // topic interests

    /** @return array<int,int> */
    function topic_interest_map() {
        if ($this->_topic_interest_map === null) {
            if ($this->contactId <= 0 || !$this->conf->has_topics()) {
                $this->_topic_interest_map = [];
            } else if (($this->roles & self::ROLE_PCLIKE)
                       && $this !== Contact::$main_user
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
    function dangerous_track_mask() {
        if ($this->_dangerous_track_mask === null) {
            $this->_dangerous_track_mask = $this->conf->dangerous_track_mask($this);
            if ($this->hidden_papers) {
                $this->_dangerous_track_mask |= Track::BITS_VIEW;
            }
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
                    && (($this->dangerous_track_mask() & Track::BITS_VIEWADMIN) === 0
                        || ($this->conf->check_tracks($prow, $this, Track::VIEW)
                            && $this->conf->check_tracks($prow, $this, Track::ADMIN))))
                || ($this->isPC
                    && $this->is_track_manager()
                    && (!$prow->managerContactId || $ci->conflictType <= CONFLICT_MAXUNCONFLICTED)
                    && $this->conf->check_admin_tracks($prow, $this))
                || $this->_root_user) {
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
                        && ($this->dangerous_track_mask() & Track::BITS_REVIEW) === 0)
                    || ($this->conf->check_tracks($prow, $this, Track::ASSREV)
                        && $this->conf->check_tracks($prow, $this, Track::UNASSREV));
            } else {
                $ci->potential_reviewer = false;
            }
            $ci->allow_review = $ci->potential_reviewer
                && ($ci->can_administer || $ci->conflictType <= CONFLICT_MAXUNCONFLICTED);

            // check author allowance
            $ci->allow_author_edit = $ci->conflictType >= CONFLICT_AUTHOR
                || $ci->allow_administer;

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
            $ci->act_author_view = $ci->view_conflict_type >= CONFLICT_AUTHOR
                && !$forceShow;
            $ci->allow_author_view = $ci->act_author_view || $ci->allow_administer;

            // check decision visibility
            $sdr = $ci->allow_pc_broad
                || ($ci->review_status > PaperContactInfo::RS_UNSUBMITTED
                    && ($this->conf->setting("viewrev_ext") ?? 0) >= 0);
            $ci->can_view_decision = $ci->can_administer
                || (($sdr || $ci->act_author_view)
                    && $prow->can_author_view_decision())
                || ($sdr
                    && ($sd = $this->conf->setting("seedec")) > 0
                    && ($sd !== Conf::SEEDEC_NCREV || $ci->view_conflict_type <= 0));

            // check view-authors state
            if ($ci->act_author_view && !$ci->allow_administer) {
                $ci->view_authors_state = 2;
            } else if ($ci->allow_pc_broad || $ci->review_status > 0) {
                $bs = $prow->blindness_state($ci->review_status > PaperContactInfo::RS_PROXIED);
                if ($bs === 0) {
                    $bs = $ci->can_view_decision && ($isPC || $ci->allow_review) ? -1 : 1;
                }
                if ($ci->allow_administer) {
                    $ci->view_authors_state = $bs < 0 ? 2 : 1;
                } else if ($bs < 0
                           && ($prow->timeSubmitted != 0
                               || ($ci->allow_pc_broad
                                   && $prow->timeWithdrawn <= 0
                                   && $prow->submission_round()->incomplete_viewable))) {
                    $ci->view_authors_state = 2;
                } else {
                    $ci->view_authors_state = 0;
                }
            } else {
                $ci->view_authors_state = 0;
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
     * @return bool
     * @deprecated */
    function override_deadlines($rights) {
        return $rights ? $rights->can_administer : $this->privChair;
    }

    /** @return bool */
    function allow_administer_all() {
        return $this->_root_user
            || ($this->privChair
                && !$this->conf->has_any_explicit_manager()
                && ($this->dangerous_track_mask() & Track::BITS_VIEWADMIN) === 0);
    }

    /** @return bool */
    function allow_administer(PaperInfo $prow = null) {
        if ($prow) {
            return $this->rights($prow)->allow_administer;
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

    /** @return bool */
    function can_administer(PaperInfo $prow) {
        return $this->rights($prow)->can_administer;
    }

    /** @param PaperContactInfo $rights
     * @return bool */
    private function _can_administer_for_track(PaperInfo $prow, $rights, $ttype) {
        return $rights->can_administer
            && (($this->dangerous_track_mask() & (1 << $ttype)) === 0
                || $this->conf->check_tracks($prow, $this, $ttype));
    }

    /** @param PaperContactInfo $rights
     * @return bool */
    private function _allow_administer_for_track(PaperInfo $prow, $rights, $ttype) {
        return $rights->allow_administer
            && (($this->dangerous_track_mask() & (1 << $ttype)) === 0
                || $this->conf->check_tracks($prow, $this, $ttype));
    }

    /** @return bool */
    function can_administer_for_track(PaperInfo $prow, $ttype) {
        return $this->_can_administer_for_track($prow, $this->rights($prow), $ttype);
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
    function security_locked() {
        $cdbu = $this->cdb_user();
        return ($this->cflags & self::CF_SECURITYLOCK) !== 0
            || ($cdbu && ($cdbu->cflags & self::CF_SECURITYLOCK) !== 0);
    }

    /** @return bool */
    function security_locked_here() {
        return ($this->cflags & self::CF_SECURITYLOCK) !== 0;
    }

    /** @return bool */
    function can_edit_any_password() {
        return $this->privChair
            && !$this->conf->external_login()
            && !$this->conf->contactdb()
            && !$this->conf->opt("chairHidePasswords");
    }

    /** @return bool */
    function can_edit_password(Contact $acct) {
        return !$acct->security_locked()
            && ((($this->_activated & 7) === 1
                 && $this->contactId > 0
                 && $this->contactId === $acct->contactId)
                || $this->can_edit_any_password());
    }

    /** @return bool */
    function can_view_tracker($tracker_json = null) {
        return $this->privChair
            || ($this->isPC
                && $this->conf->check_default_track($this, Track::VIEWTRACKER)
                && (!$tracker_json
                    || ($tracker_json->visibility ?? "") === ""
                    || ($this->has_tag(substr($tracker_json->visibility, 1))
                        === ($tracker_json->visibility[0] === "+"))))
            || $this->tracker_kiosk_state > 0;
    }

    /** @return bool */
    function include_tracker_conflict($tracker_json = null) {
        return $this->isPC
            && (!($perm = $this->conf->track_permission("", Track::VIEWTRACKER))
                || $perm === "+none"
                || $this->has_permission($perm))
            && (!$tracker_json
                || ($tracker_json->visibility ?? "") === ""
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
    function act_author_view(PaperInfo $prow) {
        return $this->rights($prow)->act_author_view;
    }

    /** @param ?string $table
     * @param bool $only_if_complex
     * @return ?string */
    function act_author_view_sql($table, $only_if_complex = false) {
        $m = [];
        if ($this->_capabilities !== null && !$this->isPC) {
            foreach ($this->author_view_capability_paper_ids() as $pid) {
                $m[] = "Paper.paperId=" . $pid;
            }
        }
        if (empty($m) && $this->contactId && $only_if_complex) {
            return null;
        } else {
            if ($this->contactId) {
                assert($table !== null);
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
            $m[] = "({$table}.contactId={$this->contactId} and {$table}.reviewType>0)";
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
        if (empty($m)) {
            return "false";
        } else if (count($m) === 1) {
            return $m[0];
        } else {
            return "(" . join(" or ", $m) . ")";
        }
    }

    /** @param bool $allow_no_email
     * @return ?PermissionProblem */
    function perm_start_paper(PaperInfo $prow, $allow_no_email = false) {
        if (($sl = $this->conf->site_lock("paper:start")) > 0
            && ($sl > 1 || !$this->can_administer($prow))) {
            return new PermissionProblem($this->conf, ["site_lock" => "paper:start"]);
        }
        if ($this->can_administer($prow)) {
            return null;
        }
        $sr = $prow->submission_round();
        if (!$sr->time_register(true)) {
            return new PermissionProblem($this->conf, ["deadline" => "sub_reg", "override" => $this->privChair]);
        } else if (!$this->email && !$allow_no_email) {
            return new PermissionProblem($this->conf, ["signin" => "paper:start"]);
        } else {
            return null;
        }
    }

    /** @return bool */
    function allow_edit_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->allow_administer || $prow->has_author($this);
    }

    /** @return 0|1|2 */
    function edit_paper_state(PaperInfo $prow) {
        if ($prow->timeWithdrawn > 0 /* non-overridable */) {
            return 0;
        }
        $rights = $this->rights($prow);
        if ($prow->is_new()
            && ($sl = $this->conf->site_lock("paper:start")) > 0
            && ($sl > 1 || !$rights->can_administer)) {
            return 0;
        }
        if ($rights->can_administer) {
            if ($prow->phase() === PaperInfo::PHASE_FINAL) {
                return 2;
            } else {
                return 1;
            }
        } else if ($rights->allow_author_edit) {
            return $prow->author_edit_state();
        } else {
            return 0;
        }
    }

    /** @return bool */
    function can_edit_paper(PaperInfo $prow) {
        return $this->edit_paper_state($prow) !== 0;
    }

    /** @return PermissionProblem */
    private function perm_edit_paper_failure(PaperInfo $prow, PaperContactInfo $rights, $kind = "") {
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_author_edit) {
            if ($rights->allow_author_view) {
                $whyNot["signin"] = "paper:edit";
            } else {
                $whyNot["author"] = true;
            }
        }
        if ($prow->timeWithdrawn > 0
            && strpos($kind, "w") === false) {
            $whyNot["withdrawn"] = true;
        }
        if ($prow->timeSubmitted > 0
            && strpos($kind, "f") !== false
            && $prow->submission_round()->freeze) {
            $whyNot["frozen"] = true;
        }
        if ($rights->allow_administer) {
            $whyNot["override"] = true;
        }
        return $whyNot;
    }

    /** @return ?PermissionProblem */
    function perm_edit_paper(PaperInfo $prow) {
        if ($this->can_edit_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $this->perm_edit_paper_failure($prow, $rights, "f");
        if ($prow->outcome_sign < 0
            && $rights->can_view_decision) {
            $whyNot["frozen"] = true;
        } else if (!$rights->can_administer) {
            if ($prow->phase() === PaperInfo::PHASE_FINAL
                && $rights->can_view_decision
                && !$this->conf->time_edit_final_paper()) {
                $whyNot["deadline"] = "final_done";
            } else if (!$prow->submission_round()->time_update(true)) {
                $whyNot["deadline"] = "sub_update";
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_finalize_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if (!$rights->allow_author_edit || $prow->timeWithdrawn > 0) {
            return false;
        }
        $sr = $prow->submission_round();
        return (($prow->timeSubmitted <= 0 || !$sr->freeze)
                && $sr->time_submit(true))
            || $rights->can_administer;
    }

    /** @return ?PermissionProblem */
    function perm_finalize_paper(PaperInfo $prow) {
        if ($this->can_finalize_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $this->perm_edit_paper_failure($prow, $rights, "f");
        $sr = $prow->submission_round();
        if (!$sr->time_submit(true)
            && !$rights->can_administer) {
            $whyNot["deadline"] = "sub_sub";
        }
        return $whyNot;
    }

    /** @return bool */
    function can_withdraw_paper(PaperInfo $prow, $display_only = false) {
        $rights = $this->rights($prow);
        if ($rights->can_administer
            || $prow->timeWithdrawn > 0) {
            return true;
        }
        $sub_withdraw = $this->conf->setting("sub_withdraw") ?? 0;
        return $rights->allow_author_edit
            && ($sub_withdraw !== -1
                || $prow->timeSubmitted == 0)
            && ($sub_withdraw !== 0
                || !$prow->has_author_seen_any_review())
            && ($prow->outcome_sign === 0
                || ($display_only && !$prow->can_author_view_decision()));
    }

    /** @return ?PermissionProblem */
    function perm_withdraw_paper(PaperInfo $prow, $display_only = false) {
        if ($this->can_withdraw_paper($prow, $display_only)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $this->perm_edit_paper_failure($prow, $rights);
        if ($rights->allow_author_edit && !$rights->can_administer) {
            $whyNot["permission"] = "paper:withdraw";
            $sub_withdraw = $this->conf->setting("sub_withdraw") ?? 0;
            if ($sub_withdraw === 0 && $prow->has_author_seen_any_review()) {
                $whyNot["reviewsSeen"] = true;
            } else if ($prow->outcome_sign !== 0) {
                $whyNot["decided"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_revive_paper(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->allow_author_edit
            && $prow->timeWithdrawn > 0
            && ($rights->can_administer
                || $prow->submission_round()->time_submit(true));
    }

    /** @return ?PermissionProblem */
    function perm_revive_paper(PaperInfo $prow) {
        if ($this->can_revive_paper($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $this->perm_edit_paper_failure($prow, $rights, "w");
        if ($prow->timeWithdrawn <= 0) {
            $whyNot["notWithdrawn"] = true;
        }
        if (!$rights->can_administer
            && !$prow->submission_round()->time_submit(true)) {
            $whyNot["deadline"] = "sub_sub";
        }
        return $whyNot;
    }

    /** @return bool */
    function can_view_all() {
        // see also Limit_SeachTerm
        return $this->privChair
            && ($this->dangerous_track_mask() & Track::BITS_VIEW) === 0;
    }

    /** @return bool */
    function can_view_missing_papers() {
        return $this->privChair
            || ($this->isPC && ($this->dangerous_track_mask() & Track::BITS_VIEW) === 0);
    }

    /** @return bool */
    function can_view_some_incomplete() {
        return $this->privChair
            || ($this->isPC && $this->conf->can_pc_view_some_incomplete());
    }

    /** @return bool */
    function can_view_all_incomplete() {
        return $this->privChair
            || ($this->isPC && $this->conf->can_pc_view_all_incomplete());
    }

    /** @return PermissionProblem */
    function no_paper_whynot($pid) {
        $whynot = new PermissionProblem($this->conf, ["paperId" => $pid]);
        if (!ctype_digit((string) $pid)) {
            $whynot["invalidId"] = "paper";
        } else if ($this->can_view_missing_papers()) {
            $whynot["noPaper"] = true;
        } else {
            $whynot["permission"] = "paper:view";
            if ($this->is_empty()) {
                $whynot["signin"] = "paper";
            }
        }
        return $whynot;
    }

    /** @return bool */
    function can_view_paper(PaperInfo $prow, $pdf = false) {
        // root user can view everything
        if ($this->_root_user) {
            return true;
        }
        // hidden_papers is set when a chair with a conflicted, managed paper
        // “becomes” a user
        if ($this->hidden_papers !== null
            && isset($this->hidden_papers[$prow->paperId])) {
            $this->hidden_papers[$prow->paperId] = true;
            return false;
        }
        // chairs can view everything unless there are dangerous view tracks
        if ($this->privChair) {
            $f = Track::BITS_VIEW | ($pdf ? 1 << Track::VIEWPDF : 0);
            if (($this->dangerous_track_mask() & $f) === 0) {
                return true;
            }
        }
        // otherwise check rights
        $rights = $this->rights($prow);
        return $rights->allow_author_view
            || ($pdf
                // assigned reviewer can view PDF of withdrawn, but submitted, paper
                ? $rights->review_status > PaperContactInfo::RS_DECLINED
                  && $prow->timeSubmitted != 0
                : $rights->review_status > 0)
            || ($rights->allow_pc_broad
                && $this->conf->time_pc_view($prow, $pdf)
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
            $whyNot["permission"] = "paper:view";
            if ($this->is_empty()) {
                $whyNot["signin"] = "paper";
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
                $whyNot["permission"] = "document:view";
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
    function can_pc_view_paper_track(PaperInfo $prow) {
        assert($this->isPC);
        $rights = $this->rights($prow);
        return $rights->allow_pc_broad
            && $this->conf->time_pc_view($prow, false)
            && (!$this->conf->check_track_view_sensitivity()
                || $this->conf->check_tracks($prow, $this, Track::VIEW));
    }

    /** @return bool */
    function can_view_document_history(PaperInfo $prow) {
        if ($this->privChair) {
            return true;
        }
        $rights = $this->rights($prow);
        return $rights->conflictType >= CONFLICT_AUTHOR || $rights->can_administer;
    }

    /** @return bool */
    function needs_some_bulk_download_warning() {
        return !$this->privChair
            && $this->isPC
            && $this->conf->opt("pcWarnBulkDownload");
    }

    /** @return bool */
    function needs_bulk_download_warning(PaperInfo $prow) {
        if ($this->needs_some_bulk_download_warning()) {
            $rights = $this->rights($prow);
            return !$rights->allow_administer
                && $rights->allow_pc_broad
                && $rights->review_status === 0
                && !$rights->allow_author_view
                && ($prow->outcome_sign <= 0 || !$rights->can_view_decision)
                && $this->conf->time_pc_view($prow, true)
                && $this->conf->check_tracks($prow, $this, Track::VIEWPDF);
        } else {
            return false;
        }
    }

    /** @return bool */
    function can_view_manager(PaperInfo $prow = null) {
        if ($this->privChair) {
            return true;
        } else if (!$this->can_view_pc()) {
            return false;
        } else if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_administer
                || ($rights->potential_reviewer
                    && !$this->conf->opt("hideManager"));
        } else {
            return ($this->is_reviewer()
                    && !$this->conf->opt("hideManager"))
                || ($this->isPC
                    && $this->is_explicit_manager());
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
                    && $this->can_view_some_decision());
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
                && $this->conf->time_some_reviewer_view_authors($this->isPC));
    }

    /** @return bool */
    function can_view_conflicts(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->allow_administer || $rights->act_author_view) {
            return true;
        } else if (!$rights->allow_pc_broad && !$rights->potential_reviewer) {
            return false;
        } else {
            $pccv = $this->conf->setting("sub_pcconfvis");
            return $pccv === 2
                || (!$pccv
                    && ($this->can_view_authors($prow)
                        || ($this->conf->has_active_tracker()
                            && MeetingTracker::can_view_tracker_at($this, $prow))));
        }
    }

    /** @return bool */
    function can_view_some_conflicts() {
        return $this->is_manager()
            || $this->is_author()
            || ($this->is_reviewer()
                && (($pccv = $this->conf->setting("sub_pcconfvis")) === 2
                    || (!$pccv
                        && ($this->can_view_some_authors()
                            || ($this->conf->has_active_tracker()
                                && MeetingTracker::can_view_some_tracker($this))))));
    }

    /** @param PaperOption $opt
     * @return 0|1|2 */
    function view_option_state(PaperInfo $prow, $opt) {
        if (!$this->can_view_paper($prow, $opt->has_document())
            || !$opt->test_exists($prow, ($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS) !== 0)
            || ($opt->is_final() && !$this->can_view_decision($prow))) {
            return 0;
        }
        $rights = $this->rights($prow);
        $oview = $opt->visibility();
        if ($rights->allow_administer) {
            if ($oview === PaperOption::VIS_AUTHOR) {
                return $rights->view_authors_state;
            } else {
                return 2;
            }
        } else if ($oview === PaperOption::VIS_SUB || $rights->act_author_view) {
            return 2;
        } else if ($oview === PaperOption::VIS_AUTHOR) {
            return $rights->view_authors_state;
        } else if ($oview === PaperOption::VIS_CONFLICT) {
            return $this->can_view_conflicts($prow) ? 2 : 0;
        } else if ($oview === PaperOption::VIS_REVIEW) {
            return $rights->review_status >= PaperContactInfo::RS_PROXIED
                || $this->can_view_review($prow, null) ? 2 : 0;
        } else {
            return 0;
        }
    }

    /** @param PaperOption $opt
     * @return bool */
    function can_view_option(PaperInfo $prow, $opt) {
        $vos = $this->view_option_state($prow, $opt);
        return $vos === 2
            || ($vos === 1 && $this->is_admin_force());
    }

    /** @param PaperOption $opt
     * @return bool*/
    function allow_view_option(PaperInfo $prow, $opt) {
        return $this->view_option_state($prow, $opt) !== 0;
    }

    /** @param PaperOption $opt
     * @return 0|1|2 */
    function edit_option_state(PaperInfo $prow, $opt) {
        if (!$opt->on_form()
            || !$opt->test_exists($prow, ($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS) !== 0)
            || (!$opt->test_editable($prow) && !$this->can_administer($prow))
            || ($opt->id > 0 && !$this->allow_view_option($prow, $opt))
            || ($opt->is_final() && $this->edit_paper_state($prow) !== 2)
            || ($opt->id === 0 && $this->edit_paper_state($prow) === 2)) {
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
            || ($eos === 1 && ($this->_overrides & self::OVERRIDE_EDIT_CONDITIONS) !== 0);
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
        $oview = $opt->visibility();
        if ($rights->allow_administer
            ? $oview === PaperOption::VIS_AUTHOR
              && !$this->can_view_authors($prow)
            : !$rights->act_author_view
              && ($oview === PaperOption::VIS_ADMIN
                  || ($oview === PaperOption::VIS_AUTHOR
                      && !$this->can_view_authors($prow))
                  || ($oview === PaperOption::VIS_REVIEW
                      && $rights->review_status < PaperContactInfo::RS_PROXIED
                      && !$this->can_view_review($prow, null)))) {
            $whyNot["permission"] = "field:view";
            $whyNot["option"] = $opt;
        } else if (!$opt->test_exists($prow)) {
            $whyNot["optionNonexistent"] = true;
            $whyNot["option"] = $opt;
        } else {
            $whyNot["permission"] = "field:view";
            $whyNot["option"] = $opt;
        }
        return $whyNot;
    }

    /** @return bool */
    function can_view_some_option(PaperOption $opt) {
        if (($opt->is_final() && !$this->can_view_some_decision())
            || !$opt->test_can_exist()) {
            return false;
        }
        $oview = $opt->visibility();
        return $oview === PaperOption::VIS_SUB
            || $this->privChair
            || $this->is_author()
            || ($oview === PaperOption::VIS_ADMIN && $this->is_manager())
            || ($oview === PaperOption::VIS_AUTHOR && $this->can_view_some_authors())
            || ($oview === PaperOption::VIS_CONFLICT && $this->can_view_some_conflicts())
            || ($oview === PaperOption::VIS_REVIEW && $this->is_reviewer());
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
            // this branch is ReviewRequestInfo or ReviewRefusalInfo
            return $this->can_view_review_identity($prow, $rrow);
        }
    }

    /** @return list<ResponseRound> */
    function relevant_response_rounds() {
        $rrds = [];
        foreach ($this->conf->response_rounds() as $rrd) {
            if ($rrd->relevant($this))
                $rrds[] = $rrd;
        }
        return $rrds;
    }

    /** @return bool */
    private function can_view_submitted_review_as_author(PaperInfo $prow) {
        return $prow->can_author_view_submitted_review()
            || ($this->_overrides & self::OVERRIDE_AU_SEEREV) !== 0;
    }

    /** @return bool */
    function can_view_some_review() {
        return $this->is_reviewer()
            || ($this->is_author()
                && (($this->_overrides & self::OVERRIDE_AU_SEEREV) !== 0
                    || $this->conf->_au_seerev
                    || $this->conf->any_response_open === 2
                    || ($this->conf->any_response_open === 1
                        && !empty($this->relevant_response_rounds()))));
    }

    /** @return bool */
    function can_view_some_review_field(ReviewField $f) {
        return $f->view_score > $this->permissive_view_score_bound();
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @param PaperContactInfo $rights
     * @return -1|0|1|3|4 */
    private function seerev_setting(PaperInfo $prow, $rbase, $rights) {
        if ($rights->view_conflict_type
            || (!$rights->allow_pc && !$rights->reviewType)
            || !$this->conf->check_reviewer_tracks($prow, $this, Track::VIEWREV)) {
            return -1;
        }
        $round = $rbase ? $rbase->reviewRound : "max";
        $s = $this->conf->round_setting($rights->allow_pc ? "viewrev" : "viewrev_ext", $round) ?? 0;
        if ($s > 0
            && !$rights->reviewType
            && !$this->conf->check_reviewer_tracks($prow, $this, Track::VIEWALLREV)) {
            $s = 0;
        }
        return $s;
    }

    const CAN_VIEW_REVIEW_NO_ADMINISTER = 1;

    /** @param ?ReviewInfo $rrow
     * @param ?int $viewscore
     * @param int $flags
     * @return bool */
    function can_view_review(PaperInfo $prow, $rrow, $viewscore = null,
                             $flags = 0) {
        assert(!$rrow || $prow->paperId == $rrow->paperId);
        $viewscore = $viewscore ?? VIEWSCORE_AUTHOR;
        $rights = $this->rights($prow);
        // can always view if can administer, is metareviewer, own review
        if (($this->_can_administer_for_track($prow, $rights, Track::VIEWREV)
             && ($flags & self::CAN_VIEW_REVIEW_NO_ADMINISTER) === 0)
            || $rights->reviewType == REVIEW_META
            || ($rrow
                && $this->is_owned_review($rrow)
                && $viewscore >= VIEWSCORE_REVIEWERONLY)) {
            return true;
        }
        // otherwise, cannot view draft reviews
        if ($rrow && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
            return false;
        }
        // otherwise, check author rights
        // See also PaperInfo::can_view_review_identity_of.
        if ($rrow) {
            $viewscore = min($viewscore, $rrow->view_score());
        }
        if ($rights->act_author_view) {
            return ($viewscore >= VIEWSCORE_AUTHOR
                    || ($viewscore >= VIEWSCORE_AUTHORDEC
                        && $prow->outcome_sign !== 0
                        && $rights->can_view_decision))
                && $this->can_view_submitted_review_as_author($prow);
        }
        // otherwise, check reviewer rights
        if ($viewscore < ($rights->allow_pc ? VIEWSCORE_PC : VIEWSCORE_REVIEWER)) {
            return false;
        }
        $seerev = $this->seerev_setting($prow, $rrow, $rights);
        if ($seerev < 0) {
            return false;
        }
        return $rights->review_status > PaperContactInfo::RS_UNSUBMITTED
            || ($seerev === Conf::VIEWREV_UNLESSANYINCOMPLETE
                && !$this->has_outstanding_review())
            || ($seerev === Conf::VIEWREV_UNLESSINCOMPLETE
                && $rights->review_status === 0)
            || $seerev === Conf::VIEWREV_ALWAYS;
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
        if ($rights->allow_pc
            ? !$this->conf->check_tracks($prow, $this, Track::VIEWREV)
            : !$rights->act_author_view && $rights->review_status == 0) {
            $whyNot["permission"] = "review:view";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else if ($rights->act_author_view
                   && !$rrowSubmitted) {
            $whyNot["permission"] = "review:view";
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
                   && $this->seerev_setting($prow, $rrow, $rights) == Conf::VIEWREV_UNLESSANYINCOMPLETE
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

    function can_view_submitted_review_without_administer(PaperInfo $prow) {
        return $this->can_view_review($prow, null, null, self::CAN_VIEW_REVIEW_NO_ADMINISTER);
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @param PaperContactInfo $rights
     * @return -1|0|1 */
    private function seerevid_setting(PaperInfo $prow, $rbase, $rights) {
        if ((!$rights->allow_pc && !$rights->reviewType)
            || !$this->conf->check_reviewer_tracks($prow, $this, Track::VIEWREVID)) {
            return -1;
        }
        $round = $rbase ? $rbase->reviewRound : "max";
        return $this->conf->round_setting($rights->allow_pc ? "viewrevid" : "viewrevid_ext", $round) ?? 0;
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function can_view_review_identity(PaperInfo $prow, $rbase = null) {
        $rights = $this->rights($prow);
        // See also PaperInfo::can_view_review_identity_of.
        // See also ReviewerFexpr.
        // See also can_view_comment_identity.
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($rights->reviewType === REVIEW_META
                && $this->conf->check_tracks($prow, $this, Track::VIEWREVID))
            || ($rbase
                && $rbase->requestedBy == $this->contactId
                && $rights->allow_pc)
            || ($rbase
                && $this->is_owned_review($rbase))
            || ($rights->act_author_view
                && !$this->conf->is_review_blind(!$rbase || $rbase->reviewType < 0 || (bool) $rbase->reviewBlind))) {
            return true;
        }
        $seerevid_setting = $this->seerevid_setting($prow, $rbase, $rights);
        return $seerevid_setting === Conf::VIEWREV_ALWAYS
            || ($seerevid_setting >= 0
                && $rights->review_status > PaperContactInfo::RS_UNSUBMITTED)
            || ($seerevid_setting === Conf::VIEWREV_IFASSIGNED
                && $rights->reviewType > 0
                && !$rights->self_assigned);
    }

    /** @return bool */
    function can_view_some_review_identity() {
        if (($this->role_mask & self::ROLE_VIEW_SOME_REVIEW_ID) === 0) {
            $this->role_mask |= self::ROLE_VIEW_SOME_REVIEW_ID;
            $tags = "";
            if (($t = $this->conf->permissive_track_tag_for($this, Track::VIEWREVID))) {
                $tags = " {$t}#0 ";
            }
            if ($this->isPC) {
                $rtype = $this->is_metareviewer() ? REVIEW_META : REVIEW_PC;
            } else {
                $rtype = $this->is_reviewer() ? REVIEW_EXTERNAL : 0;
            }
            $prow = PaperInfo::make_permissive_reviewer($this, $rtype, $tags);
            $overrides = $this->add_overrides(self::OVERRIDE_CONFLICT);
            if ($this->can_view_review_identity($prow, null)) {
                $this->roles |= self::ROLE_VIEW_SOME_REVIEW_ID;
            }
            $this->set_overrides($overrides);
        }
        return ($this->roles & self::ROLE_VIEW_SOME_REVIEW_ID) !== 0;
    }

    /** @param null|ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rbase
     * @return bool */
    function can_view_review_meta(PaperInfo $prow, $rbase = null) {
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
                    && ($this->conf->setting("extrev_chairreq") ?? 0) >= 0))
            && (!$check_time
                || $this->conf->time_review($round, false, true)
                || $rights->can_administer);
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
                || ($this->conf->setting("extrev_chairreq") ?? 0) < 0)) {
            $whyNot["permission"] = "review:request";
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

    /** @return bool */
    function time_review(PaperInfo $prow, ReviewInfo $rrow = null) {
        $rights = $this->rights($prow);
        if ($rrow) {
            return ($rights->allow_administer || $this->is_owned_review($rrow))
                && $this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true);
        } else if ($rights->reviewType > 0) {
            return $this->conf->time_review($rights->reviewRound, $rights->reviewType, true);
        } else {
            return $rights->allow_review
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review(null, true, true);
        }
    }

    /** @return bool */
    function can_accept_some_review_assignment() {
        return $this->isPC
            && $this->conf->check_any_tracks($this, Track::ASSREV);
    }

    /** @return bool */
    function can_accept_review_assignment_ignore_conflict(PaperInfo $prow) {
        if ($this->isPC
            && $this->conf->check_tracks($prow, $this, Track::ASSREV)) {
            return true;
        } else {
            $rights = $this->rights($prow);
            return $rights->allow_administer || $rights->reviewType > 0;
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
    function can_edit_preference_for(Contact $u, PaperInfo $prow, $careful = false) {
        // Can enter a preference iff you can be assigned a PC review
        if ($u->contactId === $this->contactId) {
            return $u->isPC
                && ($careful
                    ? $u->can_accept_review_assignment($prow)
                    : $u->can_accept_review_assignment_ignore_conflict($prow))
                && ($u->can_view_paper($prow)
                    || (!$careful
                        && $prow->timeWithdrawn > 0
                        && ($prow->timeSubmitted < 0
                            || $prow->submission_round()->incomplete_viewable)));
        } else {
            return $u->isPC
                && $this->can_administer($prow)
                && $u->can_accept_review_assignment_ignore_conflict($prow);
        }
    }

    /** @return ?PermissionProblem */
    function perm_edit_preference_for(Contact $u, PaperInfo $prow) {
        if ($this->can_edit_preference_for($u, $prow)) {
            return null;
        }
        $whynot = $prow->make_whynot();
        if (!$u->isPC) {
            $whynot["nonPC"] = true;
        } else if ($u->contactId !== $this->contactId
                   && !$this->can_administer($prow)) {
            $whynot["administer"] = true;
        } else if ($u->contactId === $this->contactId
                   && !$u->can_view_paper($prow)) {
            $whynot["permission"] = "paper:view";
        } else {
            $whynot["unacceptableReviewer"] = true;
        }
        return $whynot;
    }

    /** @return bool */
    function can_edit_some_review(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->can_administer
            || ($rights->reviewType > 0
                && $this->conf->time_review($rights->reviewRound, $rights->reviewType, true))
            || ($rights->reviewType === 0
                && $rights->allow_review
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review(null, true, true));
    }

    /** @return ?PermissionProblem */
    function perm_edit_some_review(PaperInfo $prow) {
        if ($this->can_edit_some_review($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        // The "reviewNotAssigned" and "deadline" failure reasons are special.
        // If either is set, the system will still allow review form download.
        $whyNot = $prow->make_whynot();
        if ($rights->allow_administer && !$rights->can_administer) {
            $whyNot["conflict"] = true;
            $whyNot["forceShow"] = true;
        } else if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
            $whyNot["conflict"] = true;
        } else if ($rights->reviewType === 0 && !$rights->allow_pc) {
            $whyNot["permission"] = "review:edit";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else if ($rights->allow_review && $rights->reviewType === 0) {
            $whyNot["reviewNotAssigned"] = true;
        } else {
            $whyNot["deadline"] = $rights->allow_pc ? "pcrev_hard" : "extrev_hard";
        }
        return $whyNot;
    }

    /** @param ?int $round
     * @return bool */
    function can_create_review(PaperInfo $prow, Contact $reviewer = null, $round = null) {
        $reviewer = $reviewer ?? $this;
        $rights = $this->rights($prow);
        if ($rights->can_administer) {
            return !$reviewer->isPC
                || $reviewer->can_accept_review_assignment_ignore_conflict($prow);
        } else {
            return $rights->reviewType === 0
                && $rights->allow_review
                && $reviewer->contactId === $this->contactId
                && $this->conf->setting("pcrev_any") > 0
                && $this->conf->time_review($round, $rights->allow_pc, true);
        }
    }

    /** @param ?int $round
     * @return ?PermissionProblem */
    function perm_create_review(PaperInfo $prow, Contact $reviewer = null, $round = null) {
        $reviewer = $reviewer ?? $this;
        if ($this->can_create_review($prow, $reviewer, $round)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ($rights->can_administer) {
            if ($reviewer->isPC
                && !$reviewer->can_accept_review_assignment_ignore_conflict($prow)) {
                $whyNot["unacceptableReviewer"] = true;
            }
        } else if ($rights->allow_administer) {
            $whyNot["conflict"] = true;
            $whyNot["forceShow"] = true;
        } else {
            if ($reviewer->contactId !== $this->contactId) {
                $whyNot["differentReviewer"] = true;
            } else if ($rights->reviewType > 0) {
                $whyNot["alreadyReviewed"] = true;
            } else if (!$rights->potential_reviewer) {
                $whyNot["permission"] = "review:edit";
            } else if (!$rights->allow_review) {
                $whyNot["permission"] = "review:edit";
                $whyNot["conflict"] = true;
            } else if ($this->conf->setting("pcrev_any") <= 0) {
                $whyNot["reviewNotAssigned"] = true;
            }
        }
        if (count($whyNot) === 0) {
            if ($prow->timeWithdrawn > 0) {
                $whyNot["withdrawn"] = true;
            } else if ($prow->timeSubmitted <= 0) {
                $whyNot["notSubmitted"] = true;
            } else if (!$this->conf->time_review($round, $reviewer->isPC, true)) {
                $whyNot["deadline"] = $reviewer->isPC ? "pcrev_hard" : "extrev_hard";
            }
            if ($rights->can_administer
                && ($prow->timeSubmitted <= 0 || isset($whyNot["deadline"]))) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_edit_review(PaperInfo $prow, ReviewInfo $rrow, $submit = false) {
        $rights = $this->rights($prow);
        return (!$submit
                || $this->can_clickthrough("review", $prow))
            && ($rights->can_administer
                || $this->is_owned_review($rrow))
            && ($this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true)
                || $rights->can_administer);
    }

    /** @param bool $submit
     * @return ?PermissionProblem */
    function perm_edit_review(PaperInfo $prow, ReviewInfo $rrow, $submit = false) {
        if ($this->can_edit_review($prow, $rrow, $submit)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$this->can_clickthrough("review", $prow)
            && $this->can_edit_review($prow, $rrow, false)) {
            $whyNot["clickthrough"] = true;
        } else if (!$rights->can_administer
                   && !$this->is_owned_review($rrow)) {
            $whyNot["differentReviewer"] = true;
        } else if (!$this->conf->time_review($rrow->reviewRound, $rrow->reviewType, true)) {
            $whyNot["deadline"] = $rrow->reviewType >= REVIEW_PC ? "pcrev_hard" : "extrev_hard";
            if ($rights->allow_administer) {
                $whyNot["override"] = true;
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_approve_review(PaperInfo $prow, ReviewInfo $rrow) {
        $rights = $this->rights($prow);
        return ($prow->timeSubmitted > 0 || $rights->can_administer)
            && $rrow->subject_to_approval()
            && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED
            && ($rights->can_administer
                || ($this->isPC && $rrow->requestedBy === $this->contactXid))
            && ($this->conf->time_review(null, true, true) || $rights->can_administer);
    }

    /** @return bool */
    function can_clickthrough($ctype, PaperInfo $prow = null) {
        if ($this->privChair || !$this->conf->opt("clickthrough_{$ctype}"))  {
            return true;
        }
        $csha1 = sha1($this->conf->_i("clickthrough_{$ctype}"));
        $data = $this->data("clickthrough");
        return ($data && ($data->$csha1 ?? null))
            || ($prow
                && $ctype === "review"
                && $this->_capabilities !== null
                && ($user = $this->reviewer_capability_user($prow->paperId))
                && $user->contactId !== $this->contactId
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
            || $this->conf->setting("viewrev") === Conf::VIEWREV_ALWAYS
            || $rrow->has_multiple_ratings()) {
            return true;
        }
        // Do not show rating counts if rater identity is unambiguous.
        // See also PaperSearch::unusable_ratings.
        $nsubraters = 0;
        foreach ($prow->all_reviews() as $rrow) {
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
            foreach ($prow->reviews_by_user($crow->contactId) as $rrow) {
                if ($rrow->reviewToken !== 0
                    && in_array($rrow->reviewToken, $this->_review_tokens, true))
                    return true;
            }
        }
        return false;
    }

    /** @return int */
    function add_comment_state(PaperInfo $prow) {
        $rights = $this->rights($prow);
        $time = $this->conf->setting("cmt_always") > 0
            || $this->conf->time_review_open();
        $ctype = 0;
        if ($rights->allow_review
            && ($prow->timeSubmitted > 0
                || $rights->review_status > 0
                || ($rights->allow_administer && $rights->rights_forced))
            && ($time || $rights->allow_administer)) {
            $ctype |= CommentInfo::CT_TOPIC_PAPER | CommentInfo::CT_TOPIC_REVIEW;
        }
        if ($rights->conflictType >= CONFLICT_AUTHOR
            && $this->conf->setting("cmt_author") > 0
            && $time) {
            if ($this->can_view_submitted_review_as_author($prow)) {
                $ctype |= CommentInfo::CT_TOPIC_PAPER | CommentInfo::CT_TOPIC_REVIEW;
            } else if ($this->can_view_author_comment_topic_paper($prow)) {
                $ctype |= CommentInfo::CT_TOPIC_PAPER;
            }
        }
        if ($ctype !== 0) {
            if ($time) {
                $ctype |= CommentInfo::CT_SUBMIT;
            }
            if ($prow->has_author($this)) {
                $ctype |= CommentInfo::CT_BYAUTHOR;
            } else if ($prow->shepherdContactId > 0) {
                if ($this->contactId === $prow->shepherdContactId
                    || ($this->contactId === 0
                        && ($reviewer = $this->reviewer_capability_user($prow->paperId))
                        && $reviewer->contactId === $prow->shepherdContactId)) {
                    $ctype |= CommentInfo::CT_BYSHEPHERD;
                }
            }
            if ($rights->can_administer) {
                $ctype |= CommentInfo::CT_BYADMINISTRATOR;
            }
        }
        return $ctype;
    }

    /** @param ?int $newctype
     * @return bool */
    function can_edit_comment(PaperInfo $prow, CommentInfo $crow, $newctype = null) {
        if (($crow->commentType & CommentInfo::CT_RESPONSE) !== 0) {
            return $this->can_edit_response($prow, $crow, $newctype);
        }
        $rights = $this->rights($prow);
        $author = $rights->conflictType >= CONFLICT_AUTHOR
            && $this->conf->setting("cmt_author") > 0;
        $time = ($this->conf->setting("cmt_always") > 0
                 || $this->conf->time_review_open())
            && ($crow->commentType & CommentInfo::CT_FROZEN) === 0;
        if ($crow->contactId !== 0
            && !$rights->allow_administer
            && !$this->is_my_comment($prow, $crow)
            && (!$author || ($crow->commentType & CommentInfo::CT_BYAUTHOR) === 0)) {
            // cannot edit someone else's comment
            return false;
        } else if ($rights->allow_review) {
            return ($prow->timeSubmitted > 0
                    || $rights->review_status > 0
                    || ($rights->allow_administer && $rights->rights_forced))
                && ($time
                    || ($rights->allow_administer
                        && ($newctype === null || $rights->can_administer)));
        } else if ($author && $time) {
            if ((($newctype ?? $crow->commentType) & CommentInfo::CT_TOPIC_PAPER) !== 0) {
                return $crow->commentId !== 0
                    || $this->can_view_author_comment_topic_paper($prow);
            } else {
                return $this->can_view_submitted_review_as_author($prow);
            }
        } else {
            return false;
        }
    }

    /** @param ?int $newctype
     * @return ?PermissionProblem */
    function perm_edit_comment(PaperInfo $prow, CommentInfo $crow, $newctype = null) {
        if (($crow->commentType & CommentInfo::CT_RESPONSE) !== 0) {
            return $this->perm_edit_response($prow, $crow, $newctype);
        } else if ($this->can_edit_comment($prow, $crow, $newctype)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if ($crow->contactId !== $this->contactXid
            && !$rights->allow_administer) {
            $whyNot["differentReviewer"] = true;
            $whyNot["commentId"] = $crow->commentId;
        } else if (!$rights->allow_pc
                   && !$rights->allow_review
                   && ($rights->conflictType < CONFLICT_AUTHOR
                       || ($this->conf->setting("cmt_author") ?? 0) <= 0)) {
            $whyNot["permission"] = "comment:edit";
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

    /** @param ?int $newctype
     * @return bool */
    function can_edit_response(PaperInfo $prow, CommentInfo $crow, $newctype = null) {
        if ($prow->timeSubmitted <= 0
            || ($crow->commentType & CommentInfo::CT_RESPONSE) === 0
            || !($rrd = $prow->conf->response_round_by_id($crow->commentRound))) {
            return false;
        }
        $rights = $this->rights($prow);
        return ($rights->can_administer
                || $rights->conflictType >= CONFLICT_AUTHOR)
            && (($rights->allow_administer
                 && ($newctype === null || $rights->can_administer))
                || ($rrd->time_allowed(true)
                    && ($crow->commentType & CommentInfo::CT_FROZEN) === 0))
            && $rrd->test_condition($prow);
    }

    /** @param ?int $newctype
     * @return ?PermissionProblem */
    function perm_edit_response(PaperInfo $prow, CommentInfo $crow, $newctype = null) {
        if ($this->can_edit_response($prow, $crow, $newctype)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$rights->allow_administer
            && $rights->conflictType < CONFLICT_AUTHOR) {
            $whyNot["permission"] = "response:edit";
        } else if ($prow->timeWithdrawn > 0) {
            $whyNot["withdrawn"] = true;
        } else if ($prow->timeSubmitted <= 0) {
            $whyNot["notSubmitted"] = true;
        } else {
            $rrd = $prow->conf->response_round_by_id($crow->commentRound);
            if (!($crow->commentType & CommentInfo::CT_RESPONSE)
                || !$rrd
                || !$rrd->test_condition($prow)) {
                $whyNot["responseNonexistent"] = true;
            } else {
                $whyNot["deadline"] = "response";
                $whyNot["commentRound"] = $crow->commentRound;
                if ($rights->allow_administer
                    && $rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
                    $whyNot["forceShow"] = true;
                }
                if ($rights->allow_administer) {
                    $whyNot["override"] = true;
                }
            }
        }
        return $whyNot;
    }

    /** @return ?ResponseRound */
    function preferred_response_round(PaperInfo $prow) {
        $rights = $this->rights($prow);
        if ($rights->conflictType >= CONFLICT_AUTHOR) {
            foreach ($prow->conf->response_rounds() as $rrd) {
                if ($rrd->time_allowed(true))
                    return $rrd;
            }
        }
        return null;
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment(PaperInfo $prow, $crow, $textless = false) {
        $ctype = $crow ? $crow->commentType : CommentInfo::CTVIS_AUTHOR;
        $rights = $this->rights($prow);
        return ($crow && $this->is_my_comment($prow, $crow))
            || ($rights->can_administer
                && ($ctype >= CommentInfo::CTVIS_AUTHOR
                    || $rights->potential_reviewer))
            || ($rights->act_author_view
                && (($ctype & CommentInfo::CT_BYAUTHOR_MASK) !== 0
                    || ($ctype >= CommentInfo::CTVIS_AUTHOR
                        && ($ctype & CommentInfo::CT_DRAFT) === 0
                        && (($ctype & CommentInfo::CT_TOPIC_PAPER) !== 0
                            || $this->can_view_submitted_review_as_author($prow)))))
            || (!$rights->view_conflict_type
                && (($ctype & CommentInfo::CT_DRAFT) === 0
                    || ($textless && ($ctype & CommentInfo::CT_RESPONSE)))
                && ($rights->allow_pc
                    ? $ctype >= CommentInfo::CTVIS_PCONLY
                    : $ctype >= CommentInfo::CTVIS_REVIEWER)
                && (($ctype & CommentInfo::CT_TOPIC_PAPER) !== 0
                    || $this->can_view_review($prow, null))
                && ($ctype >= CommentInfo::CTVIS_AUTHOR
                    || $this->conf->setting("cmt_revid")
                    || $this->can_view_comment_identity($prow, $crow)));
    }

    /** @param ?CommentInfo $crow
     * @return bool */
    function can_view_comment_contents(PaperInfo $prow, $crow) {
        // assume can_view_comment is true
        if (!$crow
            || ($crow->commentType & (CommentInfo::CT_RESPONSE | CommentInfo::CT_DRAFT)) !== (CommentInfo::CT_RESPONSE | CommentInfo::CT_DRAFT)) {
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
        $ct = $crow ? $crow->commentType : CommentInfo::CT_BLIND;
        if (($ct & CommentInfo::CT_BYAUTHOR_MASK) !== 0) {
            return $this->can_view_authors($prow);
        }
        if (($crow
             && $crow->contactId === $this->contactId)
            || (($ct & CommentInfo::CT_BYSHEPHERD) !== 0
                && $this->can_view_shepherd($prow))) {
            return true;
        }
        $rights = $this->rights($prow);
        if ($this->_can_administer_for_track($prow, $rights, Track::VIEWREVID)
            || ($rights->reviewType === REVIEW_META
                && $this->conf->check_tracks($prow, $this, Track::VIEWREVID))
            || ($rights->act_author_view
                && !$this->conf->is_review_blind(($ct & CommentInfo::CT_BLIND) !== 0))) {
            return true;
        }
        $seerevid = $this->seerevid_setting($prow, null, $rights);
        if ($seerevid !== 0) {
            return $seerevid > 0;
        } else {
            return $rights->review_status > PaperContactInfo::RS_UNSUBMITTED
                || ($crow && $prow->can_view_review_identity_of($crow->contactId, $this));
        }
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
    function can_view_author_comment_topic_paper(PaperInfo $prow) {
        return $prow->has_viewable_comment_type($this,
            CommentInfo::CT_BYAUTHOR_MASK | CommentInfo::CT_TOPIC_PAPER | CommentInfo::CTVIS_MASK,
            CommentInfo::CT_TOPIC_PAPER | CommentInfo::CTVIS_AUTHOR);
    }


    /** @return bool */
    function can_view_decision(PaperInfo $prow) {
        $rights = $this->rights($prow);
        return $rights->can_view_decision;
    }

    /** @return bool */
    function can_view_all_decision() {
        return $this->allow_administer_all()
            || ($this->isPC && $this->conf->setting("seedec") === Conf::SEEDEC_REV);
    }

    /** @return bool */
    function can_view_some_decision() {
        if ($this->is_manager()) {
            return true;
        } else if ($this->conf->time_some_author_view_decision()) {
            return $this->isPC
                || $this->is_author()
                || ($this->is_reviewer()
                    && ($this->conf->setting("viewrev_ext") ?? 0) >= 0);
        } else if ($this->is_reviewer()) {
            return $this->conf->setting("seedec") > 0
                && ($this->conf->setting("viewrev_ext") ?? 0) >= 0;
        } else {
            return false;
        }
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
        return $formula->viewable_by($this);
    }

    /** @return bool */
    function can_edit_formula(Formula $formula = null) {
        // XXX one PC member can edit another's formulas?
        return $this->privChair
            || ($this->isPC && (!$formula || $formula->createdBy > 0));
    }

    // A review field is visible only if its view_score > view_score_bound.
    /** @return int */
    function view_score_bound(PaperInfo $prow, ReviewInfo $rrow = null) {
        // Returns the maximum view_score for an invisible review
        // field. Values are:
        //   VIEWSCORE_ADMINONLY     admin can view
        //   VIEWSCORE_REVIEWERONLY  ... and review author can view
        //   VIEWSCORE_PC            ... and any PC can view
        //   VIEWSCORE_REVIEWER      ... and external reviewers can view
        //   VIEWSCORE_AUTHORDEC     ... and authors can view when decisions visible
        //   VIEWSCORE_AUTHOR        ... and authors can view
        // So returning -3 means all scores are visible.
        // Deadlines are not considered.
        assert(!!$rrow);
        $rights = $this->rights($prow);
        if ($rights->can_administer) {
            return VIEWSCORE_ADMINONLY - 1;
        } else if ($rrow ? $this->is_owned_review($rrow) : $rights->allow_review) {
            return VIEWSCORE_REVIEWERONLY - 1;
        } else if (!$this->can_view_review($prow, $rrow)) {
            return VIEWSCORE_EMPTYBOUND;
        } else if ($rights->act_author_view
                   && $prow->outcome !== 0
                   && $rights->can_view_decision) {
            return VIEWSCORE_AUTHORDEC - 1;
        } else if ($rights->act_author_view) {
            return VIEWSCORE_AUTHOR - 1;
        } else if ($rights->allow_pc) {
            return VIEWSCORE_PC - 1;
        } else {
            return VIEWSCORE_REVIEWER - 1;
        }
    }

    /** @return int */
    function permissive_view_score_bound($as_author = false) {
        if (!$as_author && $this->is_manager()) {
            return VIEWSCORE_ADMINONLY - 1;
        } else if (!$as_author && $this->is_reviewer()) {
            return VIEWSCORE_REVIEWERONLY - 1;
        } else if (($as_author || $this->is_author())
                   && ($this->conf->_au_seerev
                       || $this->conf->any_response_open
                       || ($this->_overrides & self::OVERRIDE_AU_SEEREV) !== 0)) {
            if ($this->can_view_some_decision()) {
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
                || ($this->privChair && $this->conf->tags()->has(TagInfo::TF_SITEWIDE));
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
    function can_view_some_tag($tag) {
        // chair can always view
        if ($this->privChair) {
            return true;
        }

        // non-PC can never view
        if (!$this->isPC) {
            return false;
        }

        // chair tag: only chairs can view
        $tw = strpos($tag, "~");
        if ($tw === 0 && $tag[1] === "~") {
            return false;
        }

        // manager can always view
        if ($this->is_manager()) {
            return true;
        }

        // private: can always view own
        if ($tw === 0 || ($tw > 0 && str_starts_with($tag, "{$this->contactId}~"))) {
            return true;
        }

        // private other: can view others only if public per-user
        $tagmap = $this->conf->tags();
        if ($tw > 0) {
            return $tagmap->has(TagInfo::TF_PUBLIC_PERUSER)
                && $tagmap->is_public_peruser(substr($tag, $tw + 1));
        }

        // otherwise, public
        return !$tagmap->is_hidden($tag)
            || $this->conf->check_any_required_tracks($this, Track::HIDDENTAG);
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
        $tag = Tagger::tv_tag($tag);
        $tagmap = $this->conf->tags();
        if ($prow) {
            $rights = $this->rights($prow);
            if (!$rights->allow_pc
                && (!$this->privChair
                    || !$tagmap->is_sitewide($tag))
                && (!$rights->allow_pc_broad
                    || (!$this->conf->tag_seeall
                        && !$tagmap->is_conflict_free($tag)))) {
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
                        || $tagmap->is_public_peruser(substr($tag, $twiddle + 1)))))
            && ($twiddle !== false
                || !$tagmap->is_hidden($tag)
                || $this->can_view_hidden_tags($prow));
    }

    /** @param string $tag
     * @return bool */
    function can_view_peruser_tag(PaperInfo $prow = null, $tag) {
        if ($prow) {
            return $this->can_view_tag($prow, ($this->contactId + 1) . "~{$tag}");
        } else {
            return $this->is_manager()
                || ($this->isPC && $this->conf->tags()->is_public_peruser($tag));
        }
    }

    /** @return bool */
    function can_view_some_peruser_tag() {
        return $this->is_manager()
            || ($this->isPC && $this->conf->tags()->has(TagInfo::TF_PUBLIC_PERUSER));
    }

    /** @param string $tag
     * @return bool */
    function can_edit_tag(PaperInfo $prow, $tag, $previndex, $index) {
        assert(!!$tag);
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->_root_user) {
            return true;
        }
        $rights = $this->rights($prow);
        $tagmap = $this->conf->tags();
        if (!$rights->allow_pc_broad
            || (!$rights->allow_pc && !$tagmap->has(TagInfo::TF_CONFLICT_FREE))
            || (!$rights->can_administer && !$this->conf->time_pc_view($prow, false))) {
            if ($this->privChair && $tagmap->has(TagInfo::TF_SITEWIDE)) {
                $tag = Tagger::tv_tag($tag);
                $tw = strpos($tag, "~");
                return ($tw === false || ($tw === 0 && $tag[1] === "~"))
                    && ($t = $tagmap->find($tag))
                    && $t->is(TagInfo::TF_SITEWIDE)
                    && !$t->is(TagInfo::TF_AUTOMATIC);
            } else {
                return false;
            }
        }
        $tag = Tagger::tv_tag($tag);
        $tw = strpos($tag, "~");
        if ($tw === false || ($tw === 0 && $tag[1] === "~")) {
            $t = $tagmap->find($tag);
            return ($rights->allow_pc
                    || ($t && $t->is(TagInfo::TF_CONFLICT_FREE)))
                && ($tw === false || $this->privChair)
                && (!$t || !$t->is(TagInfo::TF_AUTOMATIC))
                && (!$t || !$t->is(TagInfo::TF_CHAIR) || $this->privChair)
                && (!$t || !$t->is(TagInfo::TF_HIDDEN) || $this->can_view_hidden_tags($prow))
                && (!$t
                    || !$t->is(TagInfo::TF_READONLY | TagInfo::TF_RANK)
                    || $rights->can_administer
                    || ($this->privChair && $t->is(TagInfo::TF_SITEWIDE)));
        } else {
            $t = $tagmap->find(substr($tag, $tw + 1));
            return ($rights->allow_pc
                    || ($t && $t->is(TagInfo::TF_CONFLICT_FREE)))
                && ($tw === 0
                    || $rights->can_administer
                    || ($tw === strlen((string) $this->contactId)
                        && str_starts_with($tag, (string) $this->contactId)))
                && (!($index < 0)
                    || !$t
                    || !$t->allotment);
        }
    }

    /** @param string $tag
     * @return ?PermissionProblem */
    function perm_edit_tag(PaperInfo $prow, $tag, $previndex, $index) {
        if ($this->can_edit_tag($prow, $tag, $previndex, $index)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        $whyNot["tag"] = $tag;
        if (!$this->isPC) {
            $whyNot["permission"] = "tag:edit";
        } else if ($rights->conflictType > CONFLICT_MAXUNCONFLICTED) {
            $whyNot["conflict"] = true;
            if ($rights->allow_administer) {
                $whyNot["forceShow"] = true;
            }
        } else if (!$this->conf->time_pc_view($prow, false)) {
            if ($prow->timeWithdrawn > 0) {
                $whyNot["withdrawn"] = true;
            } else {
                $whyNot["notSubmitted"] = true;
            }
        } else {
            $tag = Tagger::tv_tag($tag);
            $twiddle = strpos($tag, "~");
            if ($twiddle === 0 && $tag[1] === "~") {
                $whyNot["chairTag"] = true;
            } else if ($twiddle > 0 && substr($tag, 0, $twiddle) != $this->contactId) {
                $whyNot["otherTwiddleTag"] = true;
            } else if ($twiddle !== false) {
                $whyNot["voteTagNegative"] = true;
            } else {
                $t = $this->conf->tags()->find($tag);
                if ($t && $t->is(TagInfo::TFM_VOTES)) {
                    $whyNot["voteTag"] = true;
                } else if ($t && $t->is(TagInfo::TF_AUTOMATIC)) {
                    $whyNot["autosearchTag"] = true;
                } else {
                    $whyNot["chairTag"] = true;
                }
            }
        }
        return $whyNot;
    }

    /** @return bool */
    function can_edit_some_tag(PaperInfo $prow = null) {
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->_root_user) {
            return true;
        } else if ($prow) {
            $rights = $this->rights($prow);
            return ($rights->allow_pc
                    && ($rights->can_administer || $this->conf->time_pc_view($prow, false)))
                || ($this->privChair && $this->conf->tags()->has(TagInfo::TF_SITEWIDE));
        } else {
            return $this->isPC;
        }
    }

    /** @return ?PermissionProblem */
    function perm_edit_some_tag(PaperInfo $prow) {
        if ($this->can_edit_some_tag($prow)) {
            return null;
        }
        $rights = $this->rights($prow);
        $whyNot = $prow->make_whynot();
        if (!$this->isPC) {
            $whyNot["permission"] = "tag:edit";
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
    function can_edit_most_tags(PaperInfo $prow = null) {
        if ($prow) {
            $rights = $this->rights($prow);
            return $rights->allow_pc
                   && ($rights->can_administer || $this->conf->time_pc_view($prow, false));
        } else {
            return $this->isPC;
        }
    }

    /** @param string $tag
     * @return bool */
    function can_edit_tag_somewhere($tag) {
        assert(!!$tag);
        if (($this->_overrides & self::OVERRIDE_TAG_CHECKS)
            || $this->_root_user) {
            return true;
        } else if (!$this->isPC) {
            return false;
        }
        $tagmap = $this->conf->tags();
        $tag = Tagger::tv_tag($tag);
        $twiddle = strpos($tag, "~");
        if ($twiddle !== false) {
            if ($twiddle > 0) {
                return substr($tag, 0, $twiddle) == $this->contactId
                    || $this->is_manager();
            } else {
                return $tag[1] !== "~"
                    || ($this->is_manager() && !$tagmap->is_automatic($tag));
            }
        } else {
            $t = $tagmap->find($tag);
            return !$t
                || (!$t->is(TagInfo::TF_AUTOMATIC)
                    && (!$t->is(TagInfo::TF_CHAIR) || $this->privChair)
                    && (!$t->is(TagInfo::TF_READONLY) || $this->is_manager()));
        }
    }

    /** @param string $tag
     * @return bool */
    function can_edit_tag_anno($tag) {
        if ($this->privChair) {
            return true;
        }
        $twiddle = strpos($tag, "~");
        $t = $this->conf->tags()->find($tag);
        // XXXXXXX
        return $this->isPC
            && (!$t || !$t->is(TagInfo::TF_CHAIR | TagInfo::TF_READONLY | TagInfo::TF_HIDDEN))
            && ($twiddle === false
                || ($twiddle === 0 && $tag[1] !== "~")
                || ($twiddle > 0 && substr($tag, 0, $twiddle) == $this->contactId));
    }


    /** @return non-empty-list<AuthorMatcher> */
    function aucollab_matchers() {
        if ($this->_aucollab_matchers === null) {
            $this->_aucollab_matchers = [new AuthorMatcher($this, Author::STATUS_AUTHOR)];
            foreach ($this->collaborator_generator() as $m) {
                $this->_aucollab_matchers[] = $m;
            }
        }
        return $this->_aucollab_matchers;
    }

    /** @return TextPregexes */
    function aucollab_general_pregexes() {
        if ($this->_aucollab_general_pregexes === null) {
            $this->_aucollab_general_pregexes = TextPregexes::make_empty();
            foreach ($this->aucollab_matchers() as $matcher) {
                $this->_aucollab_general_pregexes->add_matches($matcher->general_pregexes());
            }
        }
        return $this->_aucollab_general_pregexes;
    }

    /** @return AuthorMatcher */
    function full_matcher() {
        return ($this->aucollab_matchers())[0];
    }


    // following / email notifications

    /** @param int $topic
     * @return int */
    function review_watch(PaperInfo $prow, $topic) {
        // use explicit watch if set
        $w = $prow->watch($this);
        if (($w & self::WATCH_REVIEW_EXPLICIT) !== 0) {
            return $w;
        }
        // otherwise, adjust default watch
        $w = $this->defaultWatch & self::WATCH_REVIEW_ANY;
        // if not administrator, remove MANAGED bit
        if (($w & self::WATCH_REVIEW_MANAGED) !== 0
            && !$this->is_primary_administrator($prow)) {
            $w &= ~self::WATCH_REVIEW_MANAGED;
        }
        // if not author, reviewer, or commenter, remove REVIEW bit
        if (($w & self::WATCH_REVIEW) !== 0
            && !$prow->has_author($this)
            && !$prow->has_reviewer($this)
            && !$prow->has_commenter($this)) {
            $w &= ~self::WATCH_REVIEW;
        }
        // if administrator AND reviewer, but viewing submitted reviews would
        // require administrator privilege, remove REVIEW bit
        if (($w & self::WATCH_REVIEW) !== 0
            && ($topic & CommentInfo::CT_TOPIC_PAPER) === 0
            && $this->can_administer($prow)
            && $prow->has_reviewer($this)
            && !$this->can_view_submitted_review_without_administer($prow)) {
            $w &= ~self::WATCH_REVIEW;
        }
        return $w;
    }

    /** @param int $topic
     * @return bool */
    function following_reviews(PaperInfo $prow, $topic) {
        return ($this->review_watch($prow, $topic) & self::WATCH_REVIEW_ANY) !== 0;
    }

    /** @return bool */
    function following_submission(PaperInfo $prow) {
        $fl = ($prow->is_new() ? self::WATCH_PAPER_REGISTER_ALL : 0)
            | ($prow->timeSubmitted > 0 ? self::WATCH_PAPER_NEWSUBMIT_ALL : 0);
        return $this->allow_administer($prow)
            && ($this->defaultWatch & $fl) !== 0;
    }

    /** @return bool */
    function following_late_withdrawal(PaperInfo $prow) {
        return $this->allow_administer($prow)
            && ($this->defaultWatch & self::WATCH_LATE_WITHDRAWAL_ALL) !== 0;
    }

    /** @return bool */
    function following_final_update(PaperInfo $prow) {
        return $this->allow_administer($prow)
            && ($this->defaultWatch & self::WATCH_FINAL_UPDATE_ALL) !== 0;
    }


    // deadlines

    /** @param ?list<PaperInfo> $prows */
    function my_deadlines($prows = null) {
        // Return cleaned deadline-relevant settings that this user can see.
        $dl = (object) ["now" => Conf::$unow, "email" => $this->email ? : null];
        if (($disabled = $this->is_disabled())) {
            $dl->disabled = true;
        }
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
        $sr = $prows ? $prows[0]->submission_round() : $this->conf->unnamed_submission_round();
        $dl->sub->open = $sr->open > 0 && $sr->open <= Conf::$now;
        $dl->sub->sub = $sr->submit;
        $sub_graces = [];
        if ($sr->register > 0
            && ($sr->update <= 0 || $sr->register < $sr->update)) {
            $dl->sub->reg = $sr->register;
            $sub_graces[] = "reg";
        }
        if ($sr->update > 0
            && $sr->update != $sr->submit) {
            $dl->sub->update = $sr->update;
            $sub_graces[] = "update";
        }
        if ($sr->open > 0
            && $sr->open <= Conf::$now
            && $sr->grace > 0) {
            $sub_graces[] = "sub";
            array_push($graces, $dl->sub, $sr->grace, $sub_graces);
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
            && !$disabled
            && ($this->isPC || $this->is_author())) {
            $dlresps = [];
            foreach ($this->relevant_response_rounds() as $rrd) {
                $dlresp = (object) ["open" => $rrd->open, "done" => $rrd->done];
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
        if ($this->conf->setting("final_open") > 0
            && !$disabled) {
            $dl->final = (object) ["open" => true];
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
        $rev_open = $this->conf->time_review_open();
        if ($disabled) {
            // do not show reviewer deadlines
        } else if ($this->is_reviewer() && $rev_open) {
            $dl->rev = (object) ["open" => true];
        } else if ($this->privChair) {
            $dl->rev = (object) [];
        }
        if (isset($dl->rev)) {
            $dl->revs = [];
            $k = $this->isPC ? "pcrev" : "extrev";
            foreach ($this->conf->defined_rounds() as $i => $round_name) {
                $isuf = $i ? "_$i" : "";
                $s = +$this->conf->setting("{$k}_soft{$isuf}");
                $h = +$this->conf->setting("{$k}_hard{$isuf}");
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
            if ($rb === Conf::BLIND_OPTIONAL) {
                $dl->rev->blind = "optional";
            } else if ($rb !== Conf::BLIND_NEVER) {
                $dl->rev->blind = true;
            }
            if ($this->conf->time_some_author_view_review()) {
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
            && !$disabled
            && $this->can_view_tracker()) {
            MeetingTracker::my_deadlines($dl, $this);
        }

        // permissions
        if ($prows
            && !$disabled) {
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
                if ($rights->conflictType >= CONFLICT_AUTHOR) {
                    $perm->is_author = true;
                }
                if ($rights->act_author_view) {
                    $perm->act_author_view = true;
                }
                if ($this->can_edit_some_review($prow)) {
                    $perm->can_review = true;
                }
                if (($caddf = $this->add_comment_state($prow)) !== 0) {
                    if (($caddf & CommentInfo::CT_SUBMIT) !== 0) {
                        $perm->can_comment = true;
                    } else {
                        $perm->can_comment = "override";
                    }
                }
                if (isset($dl->resps)) {
                    foreach ($this->conf->response_rounds() as $rrd) {
                        $crow = CommentInfo::make_response_template($rrd, $prow);
                        $v = false;
                        if ($this->can_edit_response($prow, $crow, CommentInfo::CT_SUBMIT)) {
                            $v = true;
                        } else if ($admin && $this->can_edit_response($prow, $crow)) {
                            $v = "override";
                        }
                        if ($v) {
                            $perm->can_responds = $perm->can_responds ?? [];
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
                    && !$this->conf->time_some_external_reviewer_view_comment()) {
                    $perm->default_comment_visibility = "pc";
                }
                $found = false;
                foreach ($prow->all_reviews() as $rrow) {
                    if ($rrow->reviewStatus >= ReviewInfo::RS_DELIVERED
                        && $this->can_view_review($prow, $rrow)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $perm->default_comment_topic = "paper";
                }
                if ($this->_review_tokens) {
                    $tokens = [];
                    foreach ($prow->all_reviews() as $rrow) {
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

    /** @return bool */
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


    /** @return string */
    private function unassigned_review_token() {
        while (true) {
            $token = mt_rand(1, 2000000000);
            if (!$this->conf->fetch_ivalue("select reviewId from PaperReview where reviewToken={$token}")) {
                return ", reviewToken={$token}";
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
     * @param int $type
     * @return int|false */
    function assign_review($pid, $reviewer_cid, $type, $extra = []) {
        $result = $this->conf->qe("select * from PaperReview where paperId=? and contactId=?", $pid, $reviewer_cid);
        $rrow = ReviewInfo::fetch($result, null, $this->conf);
        Dbl::free($result);
        $reviewId = $rrow ? $rrow->reviewId : 0;
        $oldtype = $rrow ? $rrow->reviewType : 0;
        $type = max((int) $type, 0);
        assert($type >= 0 && $oldtype >= 0);
        $round = $extra["round_number"] ?? null;
        $new_requester_cid = $this->contactId;
        $time = Conf::$now;

        // can't delete a review that's in progress
        if ($type === 0
            && $oldtype > 0
            && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            $type = $oldtype >= REVIEW_SECONDARY ? REVIEW_PC : $oldtype;
        }

        // PC members always get PC reviews
        if ($type === REVIEW_EXTERNAL
            && $this->conf->pc_member_by_id($reviewer_cid)) {
            $type = REVIEW_PC;
        }

        // change database
        if ($type === $oldtype
            && ($type === 0 || $round === null || $round === $rrow->reviewRound)) {
            return $reviewId;
        } else if ($oldtype === 0) {
            $round = $round ?? $this->conf->assignment_round($type === REVIEW_EXTERNAL);
            assert($round !== null); // `null` should not happen
            $round = $round ?? 0;
            if (($new_requester = $extra["requester_contact"] ?? null)) {
                $new_requester_cid = $new_requester->contactId;
            }
            $q = "insert into PaperReview set paperId={$pid}, contactId={$reviewer_cid}, reviewType={$type}, reviewRound={$round}, timeRequested={$time}, requestedBy={$new_requester_cid}";
            if ($extra["mark_notify"] ?? null) {
                $q .= ", timeRequestNotified={$time}";
            }
            if ($extra["token"] ?? null) {
                $q .= $this->unassigned_review_token();
            }
        } else if ($type === 0) {
            $q = "delete from PaperReview where paperId={$pid} and reviewId={$reviewId}";
        } else {
            $q = "update PaperReview set reviewType={$type}";
            if ($round !== null) {
                $q .= ", reviewRound={$round}";
            }
            if ($type !== REVIEW_SECONDARY && $oldtype === REVIEW_SECONDARY) {
                $rns = $rrow->reviewStatus < ReviewInfo::RS_ADOPTED ? 1 : 0;
                $q .= ", reviewNeedsSubmit={$rns}";
            }
            $q .= " where paperId={$pid} and reviewId={$reviewId}";
        }

        $result = $this->conf->qe_raw($q);
        if (Dbl::is_error($result)) {
            return false;
        }

        if ($type > 0 && $oldtype === 0) {
            $reviewId = $result->insert_id;
            $msg = "Assigned " . $this->assign_review_explanation($type, $round);
        } else if ($type === 0) {
            $msg = "Removed " . $this->assign_review_explanation($oldtype, $rrow->reviewRound);
            $reviewId = 0;
        } else {
            $msg = "Changed " . $this->assign_review_explanation($oldtype, $rrow->reviewRound) . " to " . $this->assign_review_explanation($type, $round);
        }
        $this->conf->log_for($this, $reviewer_cid, $msg, $pid);

        // on new review, update PaperReviewRefused, ReviewRequest, delegation
        if ($type > 0 && $oldtype === 0) {
            $this->conf->ql("delete from PaperReviewRefused where paperId=$pid and contactId=$reviewer_cid");
            if (($req_email = $extra["requested_email"] ?? null)) {
                $this->conf->qe("delete from ReviewRequest where paperId=$pid and email=?", $req_email);
            }
            if ($type < REVIEW_SECONDARY) {
                $this->update_review_delegation($pid, $new_requester_cid, 1);
            }
            if ($type >= REVIEW_PC
                && ($this->conf->setting("pcrev_assigntime") ?? 0) < Conf::$now) {
                $this->conf->save_setting("pcrev_assigntime", Conf::$now);
            }
        } else if ($type === 0) {
            if ($oldtype < REVIEW_SECONDARY && $rrow->requestedBy > 0) {
                $this->update_review_delegation($pid, $rrow->requestedBy, -1);
            }
            // Mark rev_tokens setting for future update by update_rev_tokens_setting
            if ($rrow->reviewToken !== 0) {
                $this->conf->settings["rev_tokens"] = -1;
            }
        } else if ($type === REVIEW_SECONDARY
                   && $oldtype !== REVIEW_SECONDARY
                   && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
            $this->update_review_delegation($pid, $reviewer_cid, 0);
        }
        if ($type === REVIEW_META || $oldtype === REVIEW_META) {
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
            $row = Dbl::fetch_first_row($this->conf->qe("select sum(contactId={$cid} and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null), sum(reviewType>0 and reviewType<" . REVIEW_SECONDARY . " and requestedBy={$cid} and reviewSubmitted is not null), sum(reviewType>0 and reviewType<" . REVIEW_SECONDARY . " and requestedBy={$cid}) from PaperReview where paperId={$pid}"));
            if ($row && $row[0]) {
                $rns = $row[1] ? 0 : ($row[2] ? -1 : 1);
                if ($direction == 0 || $rns != 0)
                    $this->conf->qe("update PaperReview set reviewNeedsSubmit=? where paperId=? and contactId=? and reviewSubmitted is null", $rns, $pid, $cid);
            }
        }
    }

    /** @param ReviewInfo $rrow
     * @return bool */
    function unsubmit_review_row($rrow, $extra = null) {
        $needsSubmit = 1;
        if ($rrow->reviewType == REVIEW_SECONDARY) {
            $row = Dbl::fetch_first_row($this->conf->qe("select count(reviewSubmitted), count(reviewId) from PaperReview where paperId=? and requestedBy=? and reviewType>0 and reviewType<" . REVIEW_SECONDARY, $rrow->paperId, $rrow->contactId));
            if ($row && $row[0]) {
                $needsSubmit = 0;
            } else if ($row && $row[1]) {
                $needsSubmit = -1;
            }
        }
        $result = $this->conf->qe("update PaperReview set reviewSubmitted=null, reviewNeedsSubmit=?, timeApprovalRequested=0 where paperId=? and reviewId=?", $needsSubmit, $rrow->paperId, $rrow->reviewId);
        if ($result->affected_rows) {
            if ($rrow->reviewType < REVIEW_SECONDARY) {
                $this->update_review_delegation($rrow->paperId, $rrow->requestedBy, -1);
            }
            $this->conf->log_for($this, $rrow->contactId, "Unsubmitted " . $this->assign_review_explanation($rrow->reviewType, $rrow->reviewRound), $rrow->paperId);
        }
        if (!$extra || !($extra["no_autosearch"] ?? false)) {
            $this->conf->update_automatic_tags($rrow->paperId, "review");
        }
        return $result->affected_rows > 0;
    }


    /** @return array */
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $a = ["email" => $this->email];
        if ($this->cdb_confid !== 0) {
            $a["contactDbId"] = $this->contactDbId;
            $a["cdb_confid"] = $this->cdb_confid;
        } else {
            $a["contactId"] = $this->contactId;
        }
        $a["roles"] = $this->roles;
        return $a;
    }
}
