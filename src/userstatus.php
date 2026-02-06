<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class UserStatus_UserLinks {
    /** @var list<int> */
    public $soleAuthor = [];
    /** @var list<int> */
    public $author = [];
    /** @var list<int> */
    public $review = [];
    /** @var list<int> */
    public $comment = [];
}

class UserStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Contact
     * @readonly */
    public $user;
    const AUTHF_USER = 1;
    const AUTHF_SELF = 2;
    const AUTHF_CDB = 4;
    const AUTHF_ACTAS = 8;
    /** @var int */
    private $_authf;

    /** @var bool
     * @readonly */
    public $notify = false;
    /** @var bool */
    public $no_deprivilege_self = false;
    /** @var 0|1|2 */
    private $if_empty = 0; /* = IF_EMPTY_NONE */
    /** @var 0|1|2 */
    private $save_mode = 0; /* = SAVE_ALL */
    /** @var bool */
    private $follow_primary = false;

    /** @var Qrequest
     * @readonly */
    public $qreq;
    /** @var CsvRow */
    public $csvreq;
    /** @var object */
    public $jval;

    /** @var ?bool */
    private $_req_security;
    /** @var ?array<string,true> */
    public $unknown_topics;
    /** @var bool */
    public $created;
    /** @var bool */
    public $notified;
    /** @var ?string */
    public $linked_secondary;
    /** @var associative-array<string,true|string> */
    public $diffs = [];

    /** @var ?ComponentSet */
    private $_cs;
    /** @var ?ComponentSet */
    private $_xcs;
    /** @var bool */
    private $_inputs_printed = false;
    /** @var ?AuthenticationChecker */
    private $_authchecker;

    static private $web_to_message_map = [
        "preferredEmail" => "preferred_email",
        "uemail" => "email"
    ];

    /** @var array<string,-2|1|0|1|2>
     * @readonly */
    static public $topic_interest_name_map = [
        "low" => -2, "lo" => -2,
        "medium-low" => -1, "medium_low" => -1, "mediumlow" => -1, "mlow" => -1,
        "medium-lo" => -1, "medium_lo" => -1, "mediumlo" => -1, "mlo" => -1,
        "medium" => 0, "none" => 0, "med" => 0,
        "medium-high" => 1, "medium_high" => 1, "mediumhigh" => 1, "mhigh" => 1,
        "medium-hi" => 1, "medium_hi" => 1, "mediumhi" => 1, "mhi" => 1,
        "high" => 2, "hi" => 2
    ];

    /** @var array<int,string>
     * @readonly */
    static public $role_map = [
        Contact::ROLE_PC => "pc",
        Contact::ROLE_CHAIR => "chair",
        Contact::ROLE_ADMIN => "sysadmin"
    ];

    function __construct(Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
    }

    const IF_EMPTY_NONE = 0;
    const IF_EMPTY_PROFILE = 1;
    const IF_EMPTY_MOST = 2;
    /** @param 0|1|2 $if_empty
     * @return $this */
    function set_if_empty($if_empty) {
        $this->if_empty = $if_empty;
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    const SAVE_ALL = 0;
    const SAVE_EXISTING = 1;
    const SAVE_NEW = 2;
    /** @param 0|1|2 $mode
     * @return $this */
    function set_save_mode($mode) {
        $this->save_mode = $mode;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_follow_primary($x) {
        $this->follow_primary = $x;
        return $this;
    }

    function clear() {
        $this->clear_messages();
        $this->unknown_topics = null;
    }

    /** @return $this */
    function set_user(?Contact $user) {
        if ($user === $this->user) {
            return $this;
        }

        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->user = $user;

        // set authentication flags
        $this->_authf = 0;
        if ($user && $user->has_email()) {
            $auth_viewer = $this->viewer->base_user();
            if (strcasecmp($auth_viewer->email, $user->email) === 0) {
                $this->_authf |= self::AUTHF_USER;
            }
            if ($this->viewer->is_actas_user()) {
                if (strcasecmp($this->viewer->email, $user->email) === 0) {
                    $this->_authf |= self::AUTHF_ACTAS;
                }
            } else if (($this->_authf & self::AUTHF_USER) !== 0) {
                $this->_authf |= self::AUTHF_SELF | self::AUTHF_CDB;
            }
            if (($this->_authf & self::AUTHF_CDB) === 0
                && $auth_viewer->privChair
                && $this->conf->opt("contactdbAdminUpdate")) {
                $this->_authf |= self::AUTHF_CDB;
            }
        }

        $this->_cs = null;
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_qreq(Qrequest $qreq) {
        $this->qreq = $qreq;
        $this->_authchecker = null;
        return $this;
    }

    /** @return ?Contact */
    function cdb_user() {
        return $this->user->cdb_user();
    }

    /** @return bool */
    function is_new_user() {
        return !$this->user->email;
    }


    /* These functions distinguish three kinds of user:
       1. The *edited user* — the user being edited ($this->user)
       2. The *apparent viewer* ($this->viewer; might be actas)
       3. The *authenticated viewer* ($this->viewer->base_user(), if that viewer
          has an email) */

    /** Test if edited user is the authenticated viewer.
     * @return bool */
    function is_editing_authenticated() {
        return ($this->_authf & self::AUTHF_USER) !== 0;
    }

    /** Test if edited user = apparent viewer = authenticated viewer.
     * @return bool */
    function is_auth_self() {
        return ($this->_authf & self::AUTHF_SELF) !== 0;
    }

    /** Test if edited user = apparent viewer != authenticated viewer.
     * @return bool */
    function is_actas_self() {
        return ($this->_authf & self::AUTHF_ACTAS) !== 0;
    }

    /** @return bool */
    function can_update_cdb() {
        return ($this->_authf & self::AUTHF_CDB) !== 0;
    }

    /** @param object $gj
     * @return ?bool */
    function _print_callback($gj) {
        $inputs = $gj->inputs ?? null;
        if ($inputs || (isset($gj->print_function) && $inputs === null)) {
            $this->_inputs_printed = true;
        }
        return null;
    }

    /** @return ComponentSet */
    function cs() {
        if ($this->_cs !== null) {
            return $this->_cs;
        }
        if ($this->_xcs === null) {
            $this->_xcs = new ComponentSet($this->viewer,
                ["etc/profilegroups.json"],
                $this->conf->opt("profileGroups"));
            $this->_xcs->set_title_class("form-h")
                ->set_section_class("form-section")
                ->set_separator('<hr class="form-sep">')
                ->add_print_callback([$this, "_print_callback"]);
            $this->_xcs->add_xt_checker([$this, "xt_allower"]);
        } else {
            $this->_xcs->reset_context();
        }
        $this->_cs = $this->_xcs;
        $this->_cs->set_callable("UserStatus", $this)
            ->set_context_args($this);
        return $this->_cs;
    }

    /** @return AuthenticationChecker */
    function authentication_checker() {
        if (!$this->_authchecker) {
            $this->_authchecker = $this->viewer->authentication_checker($this->qreq, "profile_security");
        }
        return $this->_authchecker;
    }

    /** @return bool */
    function has_recent_authentication() {
        return $this->authentication_checker()->test();
    }

    /** @return ?bool */
    function xt_allower($e, $xt, $xtp) {
        if ($e === "auth_self") {
            return $this->is_auth_self();
        }
        return null;
    }

    /** @return 0|1|2
     * @deprecated */
    function update_if_empty(Contact $user) {
        assert($user === $this->user || $user === $this->user->cdb_user());
        return $this->if_empty_code($user->is_cdb_user());
    }

    /** @param bool $cdb
     * @return 0|1|2 */
    function if_empty_code($cdb = false) {
        // 0: allow all changes
        // 1: allow null -> non-null changes only
        // CDB user profiles belong to their owners
        if ($cdb && !$this->can_update_cdb()) {
            return 1;
        } else if (($this->jval->user_override ?? null) !== null) {
            return $this->jval->user_override ? 0 : 1;
        }
        return min($this->if_empty, 1);
    }

    /** @param int $cid
     * @return UserStatus_UserLinks */
    static function user_paper_info(Conf $conf, $cid) {
        $pinfo = new UserStatus_UserLinks;

        // find authored papers
        $result = $conf->qe("select Paper.paperId, count(pc.contactId)
            from Paper
            join PaperConflict c on (c.paperId=Paper.paperId and c.contactId=? and c.conflictType>=" . CONFLICT_AUTHOR . ")
            join PaperConflict pc on (pc.paperId=Paper.paperId and pc.conflictType>=" . CONFLICT_AUTHOR . ")
            group by Paper.paperId order by Paper.paperId", $cid);
        while (($row = $result->fetch_row())) {
            if ($row[1] == 1) {
                $pinfo->soleAuthor[] = (int) $row[0];
            }
            $pinfo->author[] = (int) $row[0];
        }
        Dbl::free($result);

        // find reviews
        $result = $conf->qe("select paperId from PaperReview
            where PaperReview.contactId=?
            group by paperId order by paperId", $cid);
        while (($row = $result->fetch_row())) {
            $pinfo->review[] = (int) $row[0];
        }
        Dbl::free($result);

        // find comments
        $result = $conf->qe("select paperId from PaperComment
            where PaperComment.contactId=?
            group by paperId order by paperId", $cid);
        while (($row = $result->fetch_row())) {
            $pinfo->comment[] = (int) $row[0];
        }

        return $pinfo;
    }

    /** @param list<int> $pids
     * @return string */
    static function render_paper_link(Conf $conf, $pids) {
        if (count($pids) === 1) {
            $l = Ht::link("#{$pids[0]}", $conf->hoturl("paper", ["p" => $pids[0]]));
        } else {
            $l = Ht::link(commajoin(array_map(function ($p) { return "#$p"; }, $pids)),
                          $conf->hoturl("search", ["q" => join(" ", $pids)]));
        }
        return $conf->snouns[count($pids) !== 1 ? 0 : 1] . " " . $l;
    }

    /** @param string $what
     * @return string */
    function autocomplete($what) {
        if ($this->is_auth_self()) {
            return $what;
        } else if ($what === "email" || $what === "username") {
            return "nope";
        } else if ($what === "current-password") {
            return "new-password";
        } else {
            return "off";
        }
    }

    /** @param int $roles
     * @return ?list<string> */
    static function unparse_roles_json($roles) {
        if (!$roles) {
            return null;
        }
        $rj = [];
        if ($roles & Contact::ROLE_CHAIR) {
            $rj[] = "chair";
        }
        if ($roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR)) {
            $rj[] = "pc";
        }
        if ($roles & Contact::ROLE_ADMIN) {
            $rj[] = "sysadmin";
        }
        return $rj;
    }

    /** @param int $old_roles
     * @param int $new_roles
     * @return string */
    static function unparse_roles_diff($old_roles, $new_roles) {
        $t = [];
        foreach (self::$role_map as $bit => $name) {
            if ((($old_roles ^ $new_roles) & $bit) !== 0) {
                $t[] = (($old_roles & $bit) !== 0 ? "-" : "+") . $name;
            }
        }
        return join(" ", $t);
    }

    static function unparse_json_main(UserStatus $us) {
        $user = $us->user;
        $cj = $us->jval;

        // keys that might come from user or contactdb
        foreach (["email", "firstName", "lastName", "affiliation",
                  "collaborators", "country", "phone", "address",
                  "city", "state", "zip", "country"] as $prop) {
            $value = $user->gprop($prop);
            if ($value !== null && $value !== "") {
                $cj->$prop = $value;
            }
        }

        if ($user->is_disabled()) {
            $cj->disabled = true;
        }

        if ($user->roles) {
            $cj->roles = self::unparse_roles_json($user->roles);
        }

        if ($user->defaultWatch) {
            $cj->follow = (object) [];
            $dw = $user->defaultWatch;
            if (($dw & Contact::WATCH_REVIEW_ALL) !== 0) {
                $dw |= Contact::WATCH_REVIEW;
            }
            for ($b = 1; $b <= $dw; $b <<= 1) {
                if (($dw & $b) !== 0
                    && ($n = self::unparse_follow_bit($b)))
                    $cj->follow->$n = true;
            }
        }

        if (($tags = $user->viewable_tags($us->viewer))) {
            $tagger = new Tagger($us->viewer);
            $cj->tags = explode(" ", $tagger->unparse($tags));
        }

        if ($user->contactId && ($tm = $user->topic_interest_map())) {
            $cj->topics = (object) $tm;
        }
    }

    function user_json() {
        if (!$this->user) {
            return null;
        }
        $this->jval = (object) [];
        $cs = $this->cs();
        foreach ($cs->members("", "unparse_json_function") as $gj) {
            $cs->call_function($gj, $gj->unparse_json_function, $gj);
        }
        return $this->jval;
    }


    /** @var array<int,non-empty-list<string>>
     * @readonly */
    static private $follow_keywords = [
        Contact::WATCH_REVIEW => ["review", "reviews"],
        Contact::WATCH_REVIEW_ALL => ["anyreview", "allreviews"],
        Contact::WATCH_REVIEW_MANAGED => ["adminreview", "adminreviews", "managedreviews"],
        Contact::WATCH_PAPER_NEWSUBMIT_ALL => ["submit", "allnewsubmit"],
        Contact::WATCH_PAPER_REGISTER_ALL => ["register", "allregister"],
        Contact::WATCH_LATE_WITHDRAWAL_ALL => ["latewithdraw"],
        Contact::WATCH_FINAL_UPDATE_ALL => ["finalupdate", "allfinal"]
    ];

    /** @param int $f
     * @return ?string */
    static function unparse_follow_bit($f) {
        return self::$follow_keywords[$f][0] ?? null;
    }

    /** @param string $s
     * @return ?int */
    static function parse_follow_bit($s) {
        foreach (self::$follow_keywords as $b => $ns) {
            if (in_array($s, $ns, true))
                return $b;
        }
        return null;
    }


    private function make_keyed_object($x, $field, $lc = false) {
        if (is_string($x)) {
            $x = preg_split('/[\s,;]+/', $x);
        }
        $res = [];
        if (is_object($x) || (is_array($x) && !array_is_list($x))) {
            foreach ((array) $x as $k => $v) {
                $res[$lc ? strtolower($k) : $k] = $v;
            }
        } else if (is_array($x)) {
            foreach ($x as $v) {
                if (!is_string($v)) {
                    $this->error_at($field, "<0>Format error [{$field}]");
                } else if ($v !== "") {
                    $res[$lc ? strtolower($v) : $v] = true;
                }
            }
        } else {
            $this->error_at($field, "<0>Format error [{$field}]");
        }
        return (object) $res;
    }

    /** @param object $cj */
    static function normalize_name($cj) {
        if (isset($cj->user) && is_string($cj->user)) {
            $cj_user = Text::split_name($cj->user, true);
        } else {
            $cj_user = null;
        }
        $cj_name = Author::make_keyed($cj);
        foreach (["firstName", "lastName", "email"] as $i => $k) {
            if ($cj_name->$k !== "" && $cj_name->$k !== false) {
                $cj->$k = $cj_name->$k;
            } else if ($cj_user && $cj_user[$i]) {
                $cj->$k = $cj_user[$i];
            }
        }
    }

    /** @param 'tags'|'add_tags'|'remove_tags'|'change_tags' $key
     * @return list<string> */
    private function make_tags_array($x, $key) {
        $t0 = [];
        if (is_string($x)) {
            $t0 = preg_split('/[\s,;]+/', $x);
        } else if (is_array($x)) {
            $t0 = $x;
        } else if ($x !== null) {
            $this->error_at($key, "<0>Format error [{$key}]");
        }
        $tagger = new Tagger($this->viewer);
        $t1 = [];
        $change = $key === "change_tags";
        foreach ($t0 as $t) {
            if ($t === "") {
                continue;
            }
            $pfx = "";
            $flags = Tagger::NOPRIVATE;
            if ($change && strlen($t) > 1 && $t[0] === "-") {
                $pfx = "-";
                $t = substr($t, 1);
                $flags |= Tagger::NOVALUE;
            } else if ($change && strlen($t) > 1 && $t[0] === "+") {
                $t = substr($t, 1);
            }
            if (($tx = $tagger->check($t, $flags))) {
                $t1[] = $pfx . $tx;
            } else {
                $this->error_at($key, $tagger->error_ftext(true));
            }
        }
        return $t1;
    }

    /** @param ?Contact $old_user */
    private function normalize($cj, $old_user) {
        // Errors prevent saving

        // Canonicalize keys
        foreach (["preferredEmail" => "preferred_email",
                  "institution" => "affiliation",
                  "voicePhoneNumber" => "phone",
                  "addressLine1" => "address",
                  "zipCode" => "zip",
                  "postal_code" => "zip"] as $x => $y) {
            if (isset($cj->$x) && !isset($cj->$y)) {
                $cj->$y = $cj->$x;
            }
        }

        // Stringiness
        foreach (["firstName", "lastName", "email", "preferred_email",
                  "affiliation", "phone", "new_password",
                  "city", "state", "zip", "country"] as $k) {
            if (isset($cj->$k) && !is_string($cj->$k)) {
                $this->error_at($k, "<0>Format error [{$k}]");
                unset($cj->$k);
            }
        }

        // Email
        if (!isset($cj->email)) {
            if ($old_user) {
                $cj->email = $old_user->email;
            } else {
                $this->error_at("email", "<0>Email required");
            }
        } else if (!$this->has_problem_at("email")
                   && !validate_email($cj->email)
                   && (!$old_user || strcasecmp($old_user->email, $cj->email) !== 0)) {
            $this->error_at("email", "<0>Invalid email address");
        }

        // Contactdb information
        if ($old_user && !$old_user->contactId) {
            if (!isset($cj->firstName) && !isset($cj->lastName)) {
                $cj->firstName = $old_user->firstName;
                $cj->lastName = $old_user->lastName;
            }
            if (!isset($cj->affiliation)) {
                $cj->affiliation = $old_user->affiliation;
            }
            if (!isset($cj->collaborators)) {
                $cj->collaborators = $old_user->collaborators();
            }
        }

        // Preferred email
        if (($cj->preferred_email ?? false)
            && !$this->has_problem_at("preferred_email")
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email)) {
            $this->error_at("preferred_email", "<0>Invalid email address ‘{$cj->preferred_email}’");
        }

        // Address
        $address = null;
        if (is_array($cj->address ?? null)) {
            $address = $cj->address;
        } else if (is_string($cj->address ?? null)) {
            $address = [$cj->address];
            if (is_string($cj->address2 ?? null)) {
                $address[] = $cj->address2;
            } else if (is_string($cj->addressLine2 ?? null)) {
                $address[] = $cj->addressLine2;
            } else if (($cj->address2 ?? null) || ($cj->addressLine2 ?? null)) {
                $this->error_at("address2", "<0>Format error [address2]");
            }
        } else if ($cj->address ?? null) {
            $this->error_at("address", "<0>Format error [address]");
        }
        if ($address !== null) {
            foreach ($address as &$a) {
                if (!is_string($a)) {
                    $this->error_at("address", "<0>Format error [address]");
                } else {
                    $a = simplify_whitespace($a);
                }
            }
            unset($a);
            while (!empty($address)
                   && $address[count($address) - 1] === "") {
                array_pop($address);
            }
            $cj->address = $address;
        }

        // Collaborators
        $collaborators = $cj->collaborators ?? null;
        if (is_array($collaborators)) {
            foreach ($collaborators as $c) {
                if (!is_string($c)) {
                    $this->error_at("collaborators", "<0>Format error [collaborators]");
                }
            }
            $collaborators = $this->has_problem_at("collaborators") ? null : join("\n", $collaborators);
        }
        if (is_string($collaborators)) {
            $old_collab = rtrim(cleannl($collaborators));
            $new_collab = AuthorMatcher::fix_collaborators($old_collab) ?? "";
            if ($old_collab !== $new_collab) {
                $this->warning_at("collaborators", "<0>Collaborators changed to follow our required format; you may want to look them over");
            }
            $collaborators = $new_collab;
        } else if ($collaborators !== null) {
            $this->error_at("collaborators", "<0>Format error [collaborators]");
        }
        if (isset($cj->collaborators)) {
            $cj->collaborators = $collaborators;
        }

        // Disabled
        if (isset($cj->disabled)) {
            if (($x = friendly_boolean($cj->disabled)) !== null) {
                $cj->disabled = $x;
            } else {
                $this->error_at("disabled", "<0>Format error [disabled]");
            }
        }

        // Follow
        if (isset($cj->follow) && $cj->follow !== "") {
            $fs = $this->make_keyed_object($cj->follow, "follow", true);
            $cj->follow = (object) [];
            $cj->bad_follow = [];
            foreach ((array) $fs as $k => $v) {
                if ($k === "none" || $k === "partial") {
                    $cj->follow->$k = $v;
                } else if (($b = self::parse_follow_bit($k))) {
                    $n = self::unparse_follow_bit($b);
                    if ($n === $k || !isset($cj->follow->$n)) {
                        $cj->follow->$n = $v;
                    }
                } else if ($v) {
                    $cj->bad_follow[] = $k;
                }
            }
        }

        // Tags
        if (isset($cj->tags)) {
            $cj->tags = $this->make_tags_array($cj->tags, "tags");
        }
        if (isset($cj->add_tags)) {
            $cj->add_tags = $this->make_tags_array($cj->add_tags, "add_tags");
        }
        if (isset($cj->remove_tags)) {
            $cj->remove_tags = $this->make_tags_array($cj->remove_tags, "remove_tags");
        }
        if (isset($cj->change_tags)) {
            $cj->change_tags = $this->make_tags_array($cj->change_tags, "change_tags");
        }

        // Topics
        if (isset($cj->topics)) {
            $tk = "topics";
        } else if (isset($cj->change_topics)) {
            $tk = "change_topics";
        } else if (isset($cj->default_topics)) {
            $tk = "default_topics";
        } else {
            $tk = null;
        }
        if ($tk !== null
            && ($in_topics = $this->make_keyed_object($cj->$tk, $tk)) !== null) {
            $this->normalize_topics($cj, $old_user, $tk, $in_topics);
        }
    }

    /** @param ?Contact $old_user
     * @param string $tk
     * @param object $in_topics */
    private function normalize_topics($cj, $old_user, $tk, $in_topics) {
        unset($cj->topics);
        $cj->bad_topics = [];
        if ($tk === "topics") {
            $topics = [];
        } else {
            $topics = $old_user ? $old_user->topic_interest_map() : [];
            if ($tk === "default_topics" && !empty($topics)) {
                $tk = "ignore";
            }
        }
        foreach ((array) $in_topics as $k => $v) {
            if ((is_int($k) || ctype_digit($k))
                && $this->conf->topic_set()->name((int) $k)) {
                $k = (int) $k;
            } else if (($tid = $this->conf->topic_set()->find1($k, TopicSet::MFLAG_TOPIC)) > 0) {
                $k = $tid;
            } else {
                $cj->bad_topics[] = $k;
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 2 : 0;
            } else if (is_string($v) && isset(self::$topic_interest_name_map[$v])) {
                $v = self::$topic_interest_name_map[$v];
            } else if (is_numeric($v)) {
                $v = (int) $v;
            } else {
                $this->error_at("topics", "<0>Format error [topic interest]");
                continue;
            }
            $topics[$k] = $v;
        }
        if ($tk !== "ignore") {
            $cj->topics = (object) $topics;
        }
    }

    /** @param ?MessageSet $ms
     * @return array{int,int} */
    static private function parse_roles($j, $ms = null) {
        $reset_roles = null;
        $ignore_empty = false;
        if (is_object($j) || (is_array($j) && !array_is_list($j))) {
            $reset_roles = true;
            $ij = [];
            foreach ((array) $j as $k => $v) {
                if ($v === true) {
                    $ij[] = $k;
                } else if ($v !== false && $v !== null) {
                    $ms && $ms->error_at("roles", "<0>Format error in roles");
                    return [0, 0];
                }
            }
        } else if (is_string($j)) {
            $ignore_empty = true;
            $ij = preg_split('/[\s,;]+/', $j);
        } else if (is_array($j)) {
            $ij = $j;
        } else {
            if ($j !== null) {
                $ms && $ms->error_at("roles", "<0>Format error in roles");
            }
            return [0, 0];
        }

        $add_roles = $remove_roles = 0;
        foreach ($ij as $v) {
            if (!is_string($v)) {
                $ms && $ms->error_at("roles", "<0>Format error in roles");
                return [0, 0];
            } else if ($v === "" && $ignore_empty) {
                continue;
            }
            $action = null;
            if (preg_match('/\A(?:\+|-|–|—|−)/s', $v, $m)) {
                $action = $m[0] === "+";
                $v = substr($v, strlen($m[0]));
            }
            if ($v === "") {
                $ms && $ms->error_at("roles", "<0>Format error in roles");
                return [0, 0];
            } else if (is_bool($action) && strcasecmp($v, "none") === 0) {
                $ms && $ms->error_at("roles", "<0>Format error near “none”");
                return [0, 0];
            } else if (is_bool($reset_roles) && is_bool($action) === $reset_roles) {
                $ms && $ms->warning_at("roles", "<0>Expected ‘" . ($reset_roles ? "" : "+") . "{$v}’ in roles");
            } else if ($reset_roles === null) {
                $reset_roles = $action === null;
            }
            $role = 0;
            if (strcasecmp($v, "pc") === 0) {
                $role = Contact::ROLE_PC;
            } else if (strcasecmp($v, "chair") === 0) {
                $role = Contact::ROLE_CHAIR;
            } else if (strcasecmp($v, "sysadmin") === 0
                       || strcasecmp($v, "admin") === 0) {
                $role = Contact::ROLE_ADMIN;
            } else if (strcasecmp($v, "none") !== 0) {
                $ms && $ms->warning_at("roles", "<0>Unknown role ‘{$v}’");
            }
            if ($action !== false) {
                $add_roles |= $role;
            } else {
                $remove_roles |= $role;
                $add_roles &= ~$role;
            }
        }

        if ($reset_roles) {
            $remove_roles = ~0;
        }
        if (($add_roles & Contact::ROLE_CHAIR) !== 0) {
            $add_roles |= Contact::ROLE_PC;
        }
        return [$add_roles, $remove_roles];
    }

    /** @param int $roles
     * @param Contact $old_user */
    private function check_role_change($roles, $old_user) {
        if ($roles === $old_user->roles) {
            return $roles;
        } else if ($old_user->security_locked_here()) {
            $this->warning_at("roles", "<0>Ignoring request to change roles for locked account");
            return $old_user->roles;
        }
        if ($this->no_deprivilege_self
            && $this->viewer
            && $this->viewer->conf === $this->conf
            && $this->viewer->contactId == $old_user->contactId
            && ($old_user->roles & (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) !== 0
            && ($roles & (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) === 0) {
            $what = $old_user->roles & Contact::ROLE_CHAIR ? "chair" : "system administration";
            $this->warning_at("roles", "<0>You can’t drop your own {$what} privileges. Ask another administrator to do it for you");
            $roles |= $old_user->roles & Contact::ROLE_PCLIKE;
        }
        return $roles;
    }

    private function check_invariants($cj) {
        if (isset($cj->bad_follow) && !empty($cj->bad_follow)) {
            $this->warning_at("follow", "<0>Unknown follow types ignored (" . commajoin($cj->bad_follow) . ")");
        }
        if (isset($cj->bad_topics) && !empty($cj->bad_topics)) {
            $this->warning_at("topics", $this->conf->_("<0>Unknown topics ignored ({:list})", $cj->bad_topics));
        }
    }

    /** @return bool */
    static function check_pc_tag($base) {
        return !preg_match('/\A(?:any|all|none|enabled|disabled|pc|chair|admin|sysadmin)\z/i', $base);
    }


    static function crosscheck_main(UserStatus $us) {
        if ($us->cs()->root() !== "main") {
            return;
        }
        $user = $us->user;
        $cdbu = $us->cdb_user();
        if ($user->firstName === ""
            && $user->lastName === ""
            && ($user->contactId > 0 || !$cdbu || ($cdbu->firstName === "" && $cdbu->lastName === ""))) {
            $us->warning_at("firstName", "<0>Please enter your name");
            $us->warning_at("lastName", "<0>Please enter your name");
        }
        if ($user->affiliation === ""
            && ($user->contactId > 0 || !$cdbu || $cdbu->affiliation === "")) {
            $us->warning_at("affiliation", "<0>Please enter your affiliation (use “None” or “Unaffiliated” if you have none)");
        }
        if ($user->collaborators() === "") {
            $us->warning_at("collaborators", "<0>Please enter your recent collaborators and other affiliations");
            $us->inform_at("collaborators", "<0>This information can help detect conflicts of interest. Enter “None” if you have none.");
        }
        if ($user->is_pc_member()
            && $us->conf->has_topics()
            && !$user->topic_interest_map()
            && !$us->conf->opt("allowNoTopicInterests")) {
            $us->warning_at("topics", "<0>Please enter your topic interests");
            $us->inform_at("topics", "<0>We use topic interests to improve the paper assignment process.");
        }
    }

    static function crosscheck_alerts(UserStatus $us) {
        if ($us->cs()->root() !== "main"
            || (!$us->is_auth_self() && !$us->is_actas_self())
            || !$us->user->data("alerts")) {
            return;
        }
        $ca = new ContactAlerts($us->user);
        foreach ($ca->list() as $alert) {
            if (isset($alert->scope)
                && !($alert->dismissed ?? false)
                && preg_match('/(?:\A| )profile\#(\S+)(?: |\z)/', $alert->scope, $m)
                && ($p = strpos($alert->scope, "profile#"))
                && (!($alert->sensitive ?? false)
                    || $us->is_auth_self()
                    || $us->conf->opt("debugShowSensitiveEmail"))) {
                foreach ($ca->message_list($alert) as $mi) {
                    $us->append_item($mi->with_field($m[1]));
                }
            }
        }
    }


    /** @param ?object $cj
     * @return $this */
    function start_update($cj = null) {
        $this->jval = $cj ?? (object) [];
        $this->diffs = [];
        $this->created = $this->notified = false;
        $this->linked_secondary = null;
        $this->set_user(null);
        return $this;
    }

    /** @param object $cj
     * @param ?Contact $old_user
     * @return ?Contact */
    function save_user($cj, $old_user = null) {
        $this->start_update($cj);
        $this->set_user($old_user);
        return $this->execute_update() ? $this->user : null;
    }

    /** @return bool */
    function execute_update() {
        assert(is_object($this->jval));
        assert(!$this->user || $this->save_mode === self::SAVE_ALL);
        $cj = $this->jval;

        // normalize name, including email
        self::normalize_name($cj);

        // obtain old users in this conference and contactdb
        // - obtain email
        $xuser = $this->user && $this->user->has_email() ? $this->user : null;
        if ($xuser) {
            $email = $xuser->email;
        } else if (!isset($cj->email) || $cj->email === "") {
            $this->error_at("email", "<0>Entry required");
            return false;
        } else if (!is_string($cj->email)
                   || !is_valid_utf8($cj->email)) {
            $this->error_at("email", "<0>Format error");
            return false;
        } else if (!validate_email($cj->email)) {
            $this->error_at("email", "<0>Invalid email address");
            return false;
        } else {
            $email = $cj->email;
        }

        // - look up local user
        list($add_roles, $remove_roles) = self::parse_roles($cj->roles ?? null, $this);
        if ($xuser && !$xuser->is_cdb_user()) {
            $old_user = $xuser;
        } else {
            $old_user = $this->conf->fresh_user_by_email($email);
            if ($old_user
                && !$xuser
                && $this->follow_primary
                && ($add_roles & Contact::ROLE_PCLIKE) !== 0
                && $old_user->should_use_primary("pc")) {
                $this->linked_secondary = $old_user->email;
                $old_user = $this->conf->fresh_user_by_id($old_user->primaryContactId)
                    ?? /* should never happen */ $old_user;
                $email = $old_user->email;
            }
        }

        // - look up CDB user
        if ($xuser && $xuser->is_cdb_user()) {
            $old_cdb_user = $xuser;
        } else {
            $old_cdb_user = $this->conf->fresh_cdb_user_by_email($email);
            if ($old_cdb_user
                && !$old_user
                && $this->follow_primary
                && $old_cdb_user->should_use_primary("import")) {
                $this->linked_secondary = $old_cdb_user->email;
                $old_cdb_user = $this->conf->fresh_cdb_user_by_id($old_cdb_user->primaryContactId)
                    ?? /* should never happen */ $old_cdb_user;
                $email = $old_cdb_user->email;
                $old_user = $this->conf->fresh_user_by_email($email);
            }
        }

        // - adopt existing user email case
        if ($old_user || $old_cdb_user) {
            $email = ($old_user ?? $old_cdb_user)->email;
        }

        // - check save mode
        if ($this->save_mode === self::SAVE_EXISTING && !$old_user) {
            $this->error_at("email", "<0>Account {$cj->email} not found");
            return false;
        }
        if ($this->save_mode === self::SAVE_NEW && $old_user) {
            $this->error_at("email", "<0>Email address {$email} is already in use");
            return false;
        }
        if ($old_cdb_user
            && $old_cdb_user->is_deleted()) {
            $this->error_at("email", "<0>Account {$cj->email} has been deleted and cannot be recreated");
            return false;
        } else if ($old_user
                   && $old_user->is_deleted()
                   && !($cj->user_override ?? false)) {
            $this->error_at("email", "<0>Account {$cj->email} has been deleted");
            if ($this->viewer->privChair) {
                $this->inform_at("email", "<5>You can recreate the account using <a href=\"{bulkupdate}\">Bulk update</a>. Set the ‘user_override’ column to ‘yes’.", new FmtArg("bulkupdate", $this->conf->hoturl_raw("profile", ["u" => "bulk"]), 0));
            }
            return false;
        }
        $user = $old_user ?? $old_cdb_user;

        // normalize properties
        $this->normalize($cj, $user);

        // parse roles
        $roles = $old_roles = $old_user ? $old_user->roles : 0;
        if (isset($cj->roles)) {
            $roles = ($old_roles & ~$remove_roles) | $add_roles;
            if ($this->if_empty >= UserStatus::IF_EMPTY_MOST) {
                $roles |= $old_roles & Contact::ROLE_PCLIKE;
            }
            if (($roles & Contact::ROLE_CHAIR) !== 0) {
                $roles |= Contact::ROLE_PC;
            }
            if ($old_user) {
                $roles = $this->check_role_change($roles, $old_user);
            }
        }

        // errors before this point prevent save
        if ($this->has_error()) {
            return false;
        }

        // ensure/create user
        $this->check_invariants($cj);
        $actor = $this->viewer->is_root_user() ? null : $this->viewer;
        if (!$old_user) {
            $create_cj = array_merge((array) $cj, [
                "email" => $email, "disablement" => Contact::CF_PLACEHOLDER
            ]);
            $user = Contact::make_keyed($this->conf, $create_cj)->store(0, $actor);
        }
        if (!$user) {
            return false;
        }
        $old_disablement = $user->disabled_flags();

        // initialize
        $this->created = !$old_user;
        $this->set_user($user);
        $user->invalidate_cdb_user();
        $cdb_user = $user->ensure_cdb_user();
        $cs = $this->cs();
        $this->jval = $cj;

        // apply roles
        if ($roles !== $old_roles) {
            $user->set_prop("roles", $roles);
            $this->diffs["roles"] = self::unparse_roles_diff($old_roles, $roles);
        }

        // apply other early properties
        foreach ($cs->members("") as $gj) {
            if (isset($gj->save_early_function)
                && $cs->call_function($gj, $gj->save_early_function, $gj) === false) {
                break;
            }
        }
        if (($user->prop_changed() || $this->created)
            && !$user->save_prop()) {
            return false;
        }

        // Contact DB (must precede password)
        if ($cdb_user && $cdb_user->prop_changed()) {
            if ($this->jval->update_global ?? true) {
                $cdb_user->save_prop();
                $user->invalidate_cdb_user();
                $user->cdb_user();
            } else {
                $cdb_user->abort_prop();
            }
        }

        // Main properties
        $this->save_members("");

        // Clean up
        $old_activity_at = $user->activity_at;
        if ($this->viewer->contactId === $user->contactId
            || !$user->activity_at /* being modified by an admin counts as activity */) {
            $user->mark_activity();
        }
        if (!empty($this->diffs)) {
            $t = [];
            foreach ($this->diffs as $k => $v) {
                $t[] = is_string($v) && $v !== "" ? "{$k} [{$v}]" : $k;
            }
            $user->conf->log_for($this->viewer, $user, "Account edited: " . join(", ", $t));
        } else if ($this->created) {
            $this->diffs["create"] = true;
        }

        // Notify of new accounts or new PC-ness
        if ($this->notify && $user->disabled_flags() === 0) {
            $eff_old_roles = $old_disablement !== 0 ? 0 : $old_roles;
            if (!$old_activity_at
                || (($eff_old_roles & Contact::ROLE_PCLIKE) === 0
                    && ($roles & Contact::ROLE_PCLIKE) !== 0)) {
                if (($roles & Contact::ROLE_PC) !== 0) {
                    $prep = $user->prepare_mail("@newaccount.pc");
                } else if (($roles & Contact::ROLE_ADMIN) !== 0) {
                    $prep = $user->prepare_mail("@newaccount.admin");
                } else {
                    $prep = $user->prepare_mail("@newaccount.other");
                }
                $this->notified = $prep->send();
            }
        }

        return true;
    }


    /** @param string $name
     * @return null|false */
    function save_members($name) {
        $cs = $this->cs();
        foreach ($cs->members($name) as $gj) {
            if (isset($gj->save_function)
                && $cs->call_function($gj, $gj->save_function, $gj) === false) {
                return false;
            }
        }
        return null;
    }


    /** @param 0|1|2 $ifempty */
    private function set_profile_prop(Contact $user, $ifempty) {
        foreach (["firstName" => "name",
                  "lastName" => "name",
                  "affiliation" => "affiliation",
                  "collaborators" => "collaborators",
                  "country" => "country",
                  "phone" => "phone",
                  "address" => "address",
                  "city" => "address",
                  "state" => "address",
                  "zip" => "address"] as $prop => $diff) {
            if (($v = $this->jval->$prop ?? null) !== null) {
                $user->set_prop($prop, $v, $ifempty);
                if ($user->prop_changed($prop)) {
                    $this->diffs[$diff] = true;
                }
            }
        }
        if ($user->conf->allow_preferred_email()
            && ($v = $this->jval->preferred_email ?? null) !== null) {
            $user->set_prop("preferredEmail", $v, $ifempty);
            if ($user->prop_changed("preferredEmail")) {
                $this->diffs["preferred_email"] = true;
            }
        }
    }

    function save_main() {
        $user = $this->user;
        $cj = $this->jval;

        // Profile properties
        $this->set_profile_prop($user, $this->if_empty_code(false));
        if (($cdbu = $user->cdb_user())) {
            $this->set_profile_prop($cdbu, $this->if_empty_code(true));
        }

        // Disabled
        $old_cflags = $cflags = $user->cflags;
        $cflags &= ~Contact::CF_DELETED;
        if (isset($cj->disabled) && !$user->security_locked_here()) {
            if ($cj->disabled) {
                $cflags |= Contact::CF_UDISABLED;
            } else {
                $cflags &= ~Contact::CF_UDISABLED;
            }
        }
        if (($cflags & Contact::CFM_DISABLEMENT) === Contact::CF_PLACEHOLDER) {
            $cflags &= ~Contact::CF_PLACEHOLDER;
        }
        $user->set_prop("cflags", $cflags);
        if (isset($cj->disabled) && $old_cflags !== $cflags) {
            $old_disabled = ($old_cflags & Contact::CFM_DISABLEMENT & Contact::CFM_DB) !== 0;
            $new_disabled = ($cflags & Contact::CFM_DISABLEMENT & Contact::CFM_DB) !== 0;
            if ($old_disabled !== $new_disabled) {
                $this->diffs[$new_disabled ? "disabled" : "enabled"] = true;
            }
        }

        // Follow
        if (isset($cj->follow)
            && ($this->if_empty < UserStatus::IF_EMPTY_MOST
                || $user->defaultWatch === Contact::WATCH_REVIEW)) {
            $this->save_follow($cj->follow);
        }

        // Tags
        if ((isset($cj->tags)
             || !empty($cj->add_tags)
             || !empty($cj->remove_tags)
             || !empty($cj->change_tags))
            && $this->viewer->privChair
            && ($this->if_empty < UserStatus::IF_EMPTY_MOST
                || $user->contactTags === null)) {
            if (isset($cj->tags)) {
                $user->set_prop("contactTags", null);
            }
            foreach ($cj->tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, $value ?? 0);
                }
            }
            foreach ($cj->remove_tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, false);
                }
            }
            foreach ($cj->add_tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, $value ?? $user->tag_value($tag) ?? 0);
                }
            }
            foreach ($cj->change_tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (str_starts_with($tag, "-")) {
                    $tag = substr($tag, 1);
                    $value = false;
                } else {
                    $value = $value ?? $user->tag_value($tag) ?? 0;
                }
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, $value);
                }
            }
            if ($user->prop_changed("contactTags")) {
                $this->diffs["tags"] = true;
            }
        }
    }

    private function save_follow($follow) {
        if (($follow->partial ?? false) && !($follow->none ?? false)) {
            $w = $this->user->defaultWatch;
        } else {
            $w = 0;
        }
        foreach (self::$follow_keywords as $bit => $ns) {
            if (($v = $follow->{$ns[0]} ?? null) !== null) {
                $w = ($w & ~$bit) | ($v ? $bit : 0);
            }
        }
        $this->user->set_prop("defaultWatch", $w);
        if ($this->user->prop_changed("defaultWatch")) {
            $this->diffs["follow"] = true;
        }
    }

    static function save_topics(UserStatus $us) {
        $topics = $us->jval->topics ?? null;
        '@phan-var-force array<int,int> $topics';
        if ($topics === null || !$us->conf->has_topics()) {
            return;
        }
        $ti = $us->created ? [] : $us->user->topic_interest_map();
        if ($us->if_empty >= UserStatus::IF_EMPTY_MOST && !empty($ti)) {
            return;
        }
        $tv = [];
        $diff = false;
        foreach ($topics as $k => $v) {
            if ($v) {
                $tv[] = [$us->user->contactId, $k, $v];
            }
            if ($v !== ($ti[$k] ?? 0)) {
                $diff = true;
            }
        }
        if ($diff || empty($tv)) {
            if (empty($tv)) {
                foreach ($topics as $k => $v) {
                    $tv[] = [$us->user->contactId, $k, 0];
                    break;
                }
            }
            $us->conf->qe("delete from TopicInterest where contactId=?", $us->user->contactId);
            $us->conf->qe("insert into TopicInterest (contactId,topicId,interest) values ?v", $tv);
            $us->user->invalidate_topic_interests();
        }
        if ($diff) {
            $us->diffs["topics"] = true;
        }
    }


    static function parse_qreq_main(UserStatus $us) {
        $qreq = $us->qreq;
        $cj = $us->jval;

        // email
        $email_key = $us->conf->external_login() ? "newUsername" : "uemail";
        if (isset($qreq->$email_key)) {
            $cj->email = trim((string) $qreq->$email_key);
        }

        // whether to update global profile
        $ug = $qreq->update_global ?? !isset($qreq->has_update_global);
        if (!friendly_boolean($ug)) {
            $cj->update_global = false;
        }

        // normal fields
        foreach (["firstName", "lastName", "preferredEmail", "affiliation",
                  "collaborators", "addressLine1", "addressLine2",
                  "city", "state", "zipCode", "country", "phone"] as $k) {
            if (($v = $qreq[$k]) !== null)
                $cj->$k = $v;
        }

        // follow settings
        $follow = [];
        foreach (self::$follow_keywords as $bit => $names) {
            $t = $qreq["follow_{$names[0]}"] ?? (isset($qreq["has_follow_{$names[0]}"]) ? "0" : null);
            if (($v = friendly_boolean($t)) !== null) {
                $follow[$names[0]] = $v;
            }
        }
        if (!empty($follow)) {
            $follow["partial"] = true;
            $cj->follow = (object) $follow;
        }

        // PC components
        if ($us->viewer->privChair) {
            $us->parse_qreq_chair();
        }

        if (isset($qreq->has_ti)
            && $us->viewer->isPC
            && (!isset($cj->roles) || $cj->roles !== ["none"])) {
            $topics = [];
            foreach ($us->conf->topic_set() as $id => $t) {
                if (($v = $qreq["ti{$id}"]) !== null && is_numeric($v)) {
                    $topics[$id] = (int) $v;
                }
            }
            $cj->topics = (object) $topics;
        }
    }

    private function parse_qreq_chair() {
        $cj = $this->jval;

        if (isset($this->qreq->pctype)) {
            $cj->roles = [];
            $pctype = $this->qreq->pctype;
            if ($pctype === "chair") {
                $cj->roles[] = "chair";
                $cj->roles[] = "pc";
            } else if ($pctype === "pc") {
                $cj->roles[] = "pc";
            }
            if ($this->qreq->ass) {
                $cj->roles[] = "sysadmin";
            }
            if (empty($cj->roles)) {
                $cj->roles[] = "none";
            }
        }

        if (isset($this->qreq->tags)) {
            $cj->tags = explode(" ", simplify_whitespace($this->qreq->tags));
        }
    }

    /** @param string $k
     * @return ?string */
    function field_label($k) {
        $gj = $this->cs()->get("__field/{$k}");
        return $gj->label ?? null;
    }


    static private $csv_keys = [
        ["email"],
        ["user"],
        ["firstName", "firstname", "first_name", "first", "givenname", "given_name", "given"],
        ["lastName", "lastname", "last_name", "last", "surname", "familyname", "family_name", "family"],
        ["name"],
        ["preferred_email", "preferredemail"],
        ["affiliation"],
        ["collaborators"],
        ["address1", "addressline1", "address_1", "address_line_1"],
        ["address2", "addressline2", "address_2", "address_line_2"],
        ["city"],
        ["state", "province", "region"],
        ["zip", "zipcode", "zip_code", "postalcode", "postal_code"],
        ["country"],
        ["roles", "role"],
        ["follow"],
        ["tags", "tag"],
        ["add_tags", "add_tag"],
        ["remove_tags", "remove_tag"],
        ["change_tags", "change_tag"],
        ["disabled"]
    ];

    static function parse_csv_main(UserStatus $us) {
        $line = $us->csvreq;
        $cj = $us->jval;

        // set keys
        foreach (self::$csv_keys as $ks) {
            if (($v = trim((string) $line[$ks[0]])) !== "") {
                $cj->{$ks[0]} = $v;
            }
        }

        // clean up
        if (isset($line["address"])
            && trim($line["address"]) !== "") {
            $cj->address = explode("\n", cleannl($line["address"]));
            while (!empty($cj->address)
                   && $cj->address[count($cj->address) - 1] === "") {
                array_pop($cj->address);
            }
        }

        // user override
        $override = trim($line["user_override"] ?? "");
        if ($override !== "") {
            $cj->user_override = friendly_boolean($override); /* OK if null */
        }

        // topics
        if ($us->conf->has_topics()) {
            $topics = [];
            foreach ($line as $k => $v) {
                if (preg_match('/^topic[:\s]\s*(.*?)\s*$/i', $k, $m)) {
                    if (($tid = $us->conf->topic_set()->find1($m[1], TopicSet::MFLAG_TOPIC)) > 0) {
                        $v = trim($v);
                        $topics[$tid] = $v === "" ? 0 : $v;
                    } else {
                        $us->unknown_topics[$m[1]] = true;
                    }
                }
            }
            if (!empty($topics)) {
                $override = trim($line["topic_override"] ?? "");
                if ($override !== "" && friendly_boolean($override) === false) {
                    $cj->default_topics = (object) $topics;
                } else {
                    $cj->change_topics = (object) $topics;
                }
            }
        }
    }

    function add_csv_synonyms(CsvParser $csv) {
        foreach (self::$csv_keys as $ks) {
            $csv->add_synonym(...$ks);
        }
    }

    /** @param string $name */
    function parse_csv_group($name) {
        foreach ($this->cs()->members($name, "parse_csv_function") as $gj) {
            $this->cs()->call_function($gj, $gj->parse_csv_function, $gj);
        }
    }


    /** @return bool */
    function inputs_printed() {
        return $this->_inputs_printed;
    }

    function mark_inputs_printed() {
        $this->_inputs_printed = true;
    }

    /** @param string $field
     * @param string $caption
     * @param string $entry
     * @param string $class */
    function print_field($field, $caption, $entry, $class = "f-i w-text") {
        $msfield = self::$web_to_message_map[$field] ?? $field;
        echo '<div class="', $this->control_class($msfield, $class), '">',
            ($field ? Ht::label($caption, $field) : "<div class=\"f-c\">{$caption}</div>"),
            $this->feedback_html_at($field),
            $entry, "</div>";
        $this->mark_inputs_printed();
    }

    static function pc_role_text($cj) {
        if (isset($cj->roles)) {
            assert(is_array($cj->roles) && array_is_list($cj->roles));
            if (in_array("chair", $cj->roles, true)) {
                return "chair";
            } else if (in_array("pc", $cj->roles, true)) {
                return "pc";
            }
        }
        return "none";
    }

    /** @param string $key
     * @return string */
    function global_profile_difference($key) {
        if (($this->is_auth_self() || $this->viewer->privChair)
            && ($cdbu = $this->cdb_user())) {
            $cdbprop = $cdbu->prop($key) ?? "";
            if (($this->user->prop($key) ?? "") !== $cdbprop) {
                return '<p class="feedback is-warning-note mt-1 mb-0">' . $this->conf->_5("<0>“{}” in global profile", $cdbprop, new FmtArg("field", $key, 0)) . '</p>';
            }
        }
        return "";
    }

    static function print_main(UserStatus $us) {
        $user = $us->user;
        $qreq = $us->qreq;

        if ($us->conf->external_login()) {
            $us->print_main_external_username();
        } else {
            $us->print_main_email();
        }

        echo '<div class="f-mcol w-text">';
        $t = Ht::entry("firstName", $qreq->firstName ?? $user->firstName, ["size" => 24, "autocomplete" => $us->autocomplete("given-name"), "class" => "fullw", "id" => "firstName", "data-default-value" => $user->firstName]) . $us->global_profile_difference("firstName");
        $us->print_field("firstName", "First name (given name)", $t, "f-i");

        $t = Ht::entry("lastName", $qreq->lastName ?? $user->lastName, ["size" => 24, "autocomplete" => $us->autocomplete("family-name"), "class" => "fullw", "id" => "lastName", "data-default-value" => $user->lastName]) . $us->global_profile_difference("lastName");
        $us->print_field("lastName", "Last name (family name)", $t, "f-i");
        echo '</div>';

        $t = Ht::entry("affiliation", $qreq->affiliation ?? $user->affiliation, ["size" => 52, "autocomplete" => $us->autocomplete("organization"), "class" => "fullw", "id" => "affiliation", "data-default-value" => $user->affiliation]) . $us->global_profile_difference("affiliation");
        $us->print_field("affiliation", "Affiliation", $t);
    }

    function actas_link() {
        if ($this->user !== $this->viewer
            && $this->user->email !== ""
            && $this->viewer->privChair) {
            return "&nbsp;" . actas_link($this->user);
        }
        return "";
    }

    function print_main_email() {
        if ($this->user->is_empty()) {
            $class = "want-focus fullw";
            if ($this->viewer->can_lookup_user()) {
                $class .= " uii js-email-populate";
            }
            $this->print_field("uemail", "Email" . $this->actas_link(),
                Ht::entry("uemail", $this->qreq->uemail ?? $this->qreq->email ?? "", ["class" => $class, "size" => 52, "id" => "uemail", "autocomplete" => $this->autocomplete("username"), "data-default-value" => "", "type" => "email"]));
            return;
        }
        if (Contact::session_index_by_email($this->qreq->qsession(), $this->user->email) >= 0) {
            $link = "<p class=\"nearby\">" . Ht::link("Manage email →", $this->conf->hoturl("manageemail", ["u" => $this->user->email]), ["class" => "btn btn-success btn-sm"]) . "</p>";
        } else if ($this->viewer->privChair && $this->user->is_reviewer()) {
            $link = "<p class=\"nearby\">" . Ht::link("Transfer reviews →", $this->conf->hoturl("manageemail", ["t" => "transferreview", "u" => $this->user->email]), ["class" => "btn btn-primary btn-sm"]) . "</p>";
        } else {
            $link = "";
        }
        $this->print_field("", "Email" . $this->actas_link(),
            "<p><strong class=\"sb\">" . htmlspecialchars($this->user->email) . "</strong></p>{$link}");
    }

    function print_main_external_username() {
        if ($this->user->is_empty()) {
            $this->print_field("uemail", "Username",
                Ht::entry("newUsername", $this->qreq->uemail ?? $this->user->email, ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "autocomplete" => $this->autocomplete("username"), "data-default-value" => $this->user->email]));
            $peclass = "fullw";
        } else {
            $this->print_field("", "Username" . $this->actas_link(),
                "<strong class=\"sb\">" . htmlspecialchars($this->user->email) . "</strong>");
            $peclass = "want-focus fullw";
        }
        $this->print_field("preferredEmail", "Email",
            Ht::entry("preferredEmail", $this->qreq->preferredEmail ?? $this->user->preferredEmail, ["class" => $peclass, "size" => 52, "id" => "preferredEmail", "autocomplete" => $this->autocomplete("email"), "data-default-value" => $this->user->preferredEmail, "type" => "email"]));
    }

    static function print_country(UserStatus $us) {
        $user_country = Countries::fix($us->user->country_code());
        $t = Countries::selector("country", $us->qreq->country ?? $user_country, ["id" => "country", "data-default-value" => $user_country, "autocomplete" => $us->autocomplete("country")]) . $us->global_profile_difference("country");
        $us->print_field("country", "Country/region", $t);
    }

    /** @param int $reqwatch
     * @param int $iwatch
     * @param int $wbit
     * @param string $wname
     * @param string $wlabel */
    private static function print_follow_checkbox(UserStatus $us, $reqwatch, $iwatch, $wbit, $wname, $wlabel) {
        echo '<label class="checki"><span class="checkc">',
            Ht::hidden("has_follow_{$wname}", 1),
            Ht::checkbox("follow_{$wname}", 1, ($reqwatch & $wbit) !== 0, ["data-default-checked" => ($iwatch & $wbit) !== 0]),
            '</span>', $us->conf->_($wlabel), "</label>\n";
    }

    static function print_follow(UserStatus $us) {
        $qreq = $us->qreq;
        $reqwatch = $iwatch = $us->user->defaultWatch;
        foreach (self::$follow_keywords as $bit => $ns) {
            $t = $qreq["follow_{$ns[0]}"] ?? (isset($req["has_follow_{$ns[0]}"]) ? "0" : null);
            if (($v = friendly_boolean($t)) !== null) {
                $reqwatch = ($reqwatch & ~$bit) | ($v ? $bit : 0);
            }
        }
        if ($us->user->is_empty() ? $us->viewer->privChair : $us->user->isPC) {
            echo "<table class=\"w-text\"><tr><td>Send mail for:</td><td><span class=\"sep\"></span></td><td>";
            if (!$us->user->is_empty() && $us->user->is_track_manager()) {
                self::print_follow_checkbox($us, $reqwatch, $iwatch,
                    Contact::WATCH_PAPER_REGISTER_ALL, "register", "Newly registered submissions, including draft submissions");
                self::print_follow_checkbox($us, $reqwatch, $iwatch,
                    Contact::WATCH_PAPER_NEWSUBMIT_ALL, "submit", "Newly ready submissions");
            }
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                self::print_follow_checkbox($us, $reqwatch, $iwatch,
                    Contact::WATCH_LATE_WITHDRAWAL_ALL, "latewithdraw", "Submissions withdrawn after the deadline");
            }
            self::print_follow_checkbox($us, $reqwatch, $iwatch,
                Contact::WATCH_REVIEW, "review", "Reviews and comments on authored or reviewed submissions");
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                self::print_follow_checkbox($us, $reqwatch, $iwatch,
                    Contact::WATCH_REVIEW_MANAGED, "adminreview", "Reviews and comments on submissions you administer");
            }
            self::print_follow_checkbox($us, $reqwatch, $iwatch,
                Contact::WATCH_REVIEW_ALL, "anyreview", "Reviews and comments on <em>all</em> submissions");
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                self::print_follow_checkbox($us, $reqwatch, $iwatch,
                    Contact::WATCH_FINAL_UPDATE_ALL, "finalupdate", "Updates to final versions for submissions you administer");
            }
            echo "</td></tr></table>";
        } else {
            self::print_follow_checkbox($us, $reqwatch, $iwatch,
                Contact::WATCH_REVIEW, "review", "Send mail for new reviews and comments on authored or reviewed submissions");
        }
        echo "</div>\n";
    }

    static function print_roles(UserStatus $us) {
        if (!$us->viewer->privChair) {
            return;
        }

        $us->cs()->set_section_tag("fieldset");
        $us->print_start_section("Roles", "roles");

        if ($us->user->security_locked_here()) {
            $roles = [];
            if (($us->user->roles & Contact::ROLE_CHAIR) !== 0) {
                $roles[] = "chair";
            } else if (($us->user->roles & Contact::ROLE_PC) !== 0) {
                $roles[] = "PC";
            }
            if (($us->user->roles & Contact::ROLE_ADMIN) !== 0) {
                $roles[] = "sysadmin";
            }
            if (empty($roles)) {
                $roles[] = "none";
            }
            if (ctype_lower($roles[0][0])) {
                $roles[0] = ucfirst($roles[0]);
            }
            echo '<p class="w-text mb-1">', join(", ", $roles), '</p>',
                self::feedback_html([MessageItem::warning("<0>This account’s security settings are locked, so its roles cannot be changed.")]);
            return;
        }

        echo "<table class=\"w-text\"><tr><td class=\"nw\">\n";
        if (($us->user->roles & Contact::ROLE_CHAIR) !== 0) {
            $pcrole = $cpcrole = "chair";
        } else if (($us->user->roles & Contact::ROLE_PC) !== 0) {
            $pcrole = $cpcrole = "pc";
        } else {
            $pcrole = $cpcrole = "none";
        }
        if (isset($us->qreq->pctype)
            && in_array($us->qreq->pctype, ["chair", "pc", "none"], true)) {
            $pcrole = $us->qreq->pctype;
        }
        $diffclass = $us->user->email ? "" : " ignore-diff";
        foreach (["chair" => "PC chair", "pc" => "PC member",
                  "none" => "Not on the PC"] as $k => $v) {
            echo '<label class="checki"><span class="checkc">',
                Ht::radio("pctype", $k, $pcrole === $k, ["class" => "uich js-profile-role" . $diffclass, "data-default-checked" => $cpcrole === $k]),
                '</span>', $v, "</label>\n";
        }
        Ht::stash_script('$(function(){$(".js-profile-role").first().trigger("change")})');

        echo "</td><td><span class=\"sep\"></span></td><td>";
        $is_ass = $cis_ass = ($us->user->roles & Contact::ROLE_ADMIN) !== 0;
        if (isset($us->qreq->pctype)) {
            $is_ass = isset($us->qreq->ass);
        }
        echo '<div class="checki"><label><span class="checkc">',
            Ht::checkbox("ass", 1, $is_ass, ["data-default-checked" => $cis_ass, "class" => "uich js-profile-role" . $diffclass]),
            '</span>Sysadmin</label>',
            '<p class="f-d">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</p></div></td></tr></table>', "\n";
    }

    static function print_collaborators(UserStatus $us) {
        $cd = $us->conf->_i("conflictdef");
        $us->cs()->add_section_class("w-text")->print_start_section();
        echo '<h3 class="', $us->control_class("collaborators", "form-h field-title"), '"><label for="collaborators">Collaborators and other affiliations</label></h3>', "\n",
            "<p>List potential conflicts of interest one per line, using parentheses for affiliations and institutions. We may use this information when assigning reviews.<br>Examples: “Ping Yen Zhang (INRIA)”, “All (University College London)”</p>";
        if ($cd !== "" && preg_match('/<(?:p|div)[ >]/', $cd)) {
            echo $cd;
        } else {
            echo '<p>', $cd, '</p>';
        }
        echo $us->feedback_html_at("collaborators"),
            '<textarea id="collaborators" name="collaborators" rows="5" cols="80" class="',
            $us->control_class("collaborators", "need-autogrow w-text"),
            "\" data-default-value=\"", htmlspecialchars($us->user->collaborators()), "\">",
            htmlspecialchars($us->qreq->collaborators ?? $us->user->collaborators()),
            "</textarea>",
            $us->global_profile_difference("collaborators");
    }

    static function print_topics(UserStatus $us) {
        if (!$us->user->isPC
            && !$us->viewer->privChair) {
            return;
        }
        $us->cs()->add_section_class("w-text fx1")
            ->set_section_tag("fieldset")
            ->print_start_section("Topic interests");

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $labels = ["Very low interest", "Low interest", "Standard interest", "High interest", "Very high interest"];

        echo '<p>Please indicate your interest in reviewing papers on these conference
topics. We use this information to help match papers to reviewers.</p>',
            Ht::hidden("has_ti", 1),
            $us->feedback_html_at("ti"),
            '  <table class="profile-topic-interests"><thead><tr>',
            '<th aria-label="Topic"></th>',
            '<th class="ti_interest" aria-label="', $labels[0], '">Low<br><span class="topic-2"></span></th>',
            '<th class="ti_interest" aria-label="', $labels[1], '"><span class="topic-1"></span></th>',
            '<th class="ti_interest" aria-label="', $labels[2], '"><span class="topic0"></span></th>',
            '<th class="ti_interest" aria-label="', $labels[3], '"><span class="topic1"></span></th>',
            '<th class="ti_interest" aria-label="', $labels[4], '">High<br><span class="topic2"></span></th>',
            "</tr></thead>\n";

        $tmap = $us->user->topic_interest_map();
        $ts = $us->conf->topic_set();
        $k = 0;
        foreach ($ts->group_list() as $tg) {
            echo '<tbody>';
            foreach ($tg->members() as $i => $tid) {
                $tic = "ti_topic";
                $thscope = "row";
                if ($tg->trivial()) {
                    $n = $ts->unparse_name_html($tid);
                } else if ($i === 0 && $tg->has_group_topic()) {
                    $n = $ts->unparse_name_html($tid);
                    $thscope = "rowgroup";
                } else {
                    if ($i === 0) {
                        echo '<tr class="k', $k, '">',
                            '<th class="ti_topic" scope="rowgroup" colspan="6">',
                            $tg->unparse_name_html(),
                            "</th></tr>\n";
                        $k = 1 - $k;
                    }
                    $n = $ts->unparse_subtopic_name_html($tid);
                    $tic .= " ti_subtopic";
                }
                echo "<tr class=\"k{$k}\">",
                    "<th class=\"{$tic}\" scope=\"{$thscope}\">{$n}</th>";
                $k = 1 - $k;
                $ival = $tmap[$tid] ?? 0;
                $reqval = isset($us->qreq["ti{$tid}"]) ? (int) $us->qreq["ti{$tid}"] : $ival;
                for ($j = -2; $j <= 2; ++$j) {
                    $ichecked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                    $reqchecked = $reqval >= $ibound[$j+2] && $reqval < $ibound[$j+3];
                    echo '<td class="ti_interest">',
                        Ht::radio("ti{$tid}", $j, $reqchecked, [
                            "class" => "uic js-range-click",
                            "data-range-type" => "topicinterest{$j}",
                            "data-default-checked" => $ichecked,
                            "aria-label" => $labels[$j+2]
                        ]),
                        "</td>";
                }
                echo "</tr>\n";
            }
            echo "</tbody>\n";
        }
        echo "</table>\n";
    }

    static function print_tags(UserStatus $us) {
        $user = $us->user;
        $tagger = new Tagger($us->viewer);
        $itags = $tagger->unparse($us->user->viewable_tags($us->viewer));
        if (!$us->viewer->privChair) {
            if ($us->user->isPC && $itags !== "") {
                $us->print_start_section("Tags");
                echo $itags, "<p class=\"f-d\">Tags represent PC subgroups and are set by administrators.</p>\n";
            }
            return;
        }
        $us->cs()->add_section_class("w-text fx2")
            ->print_start_section("<5>" . Ht::label("Tags", "tags"));
        echo '<div class="', $us->control_class("tags", "f-i"), '">',
            $us->feedback_html_at("tags"),
            Ht::entry("tags", $us->qreq->tags ?? $itags, ["data-default-value" => $itags, "class" => "fullw", "id" => "tags"]),
            "<p class=\"f-d\">Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href=\"", $us->conf->hoturl("settings", "group=tags"), "\">tag colors</a> to highlight subgroups in review lists.</p></div>\n";
    }

    private static function print_delete_action(UserStatus $us) {
        if ($us->user->security_locked_here()) {
            return;
        }
        $tracks = self::user_paper_info($us->conf, $us->user->contactId);
        $args = ["class" => "ui btn-danger js-delete-user"];
        if (!empty($tracks->soleAuthor)) {
            $args["class"] .= " js-cannot-delete-user";
            $args["data-sole-contact"] = join(" ", $tracks->soleAuthor);
        }
        if (!empty($tracks->author)) {
            $args["data-contact"] = join(" ", $tracks->author);
        }
        if (!empty($tracks->review)) {
            $args["data-reviewer"] = join(" ", $tracks->review);
        }
        if (!empty($tracks->comment)) {
            $args["data-commenter"] = join(" ", $tracks->comment);
        }
        echo Ht::button("Delete account", $args), '<p class="pt-1"></p>';
    }

    static function print_administration(UserStatus $us) {
        if (!$us->viewer->privChair || $us->is_new_user()) {
            return;
        }

        $us->cs()->add_section_class("form-outline-section")->print_start_section("User administration");
        echo '<div class="grid-btn-explanation"><div class="d-flex mf mf-absolute">';

        echo Ht::button("Send account information", ["class" => "ui js-send-user-accountinfo flex-grow-1", "disabled" => $us->user->is_disabled()]), '</div><p></p>';

        if (!$us->is_editing_authenticated()) {
            $disablement = $us->user->disabled_flags() & ~Contact::CF_PLACEHOLDER;
            if ($us->user->contactdb_disabled()) {
                $klass = "flex-grow-1 disabled";
                $p = "<p class=\"pt-1 mb-0 feedback is-warning\">This account is disabled on all sites.</p>";
                $disabled = true;
            } else if (($disablement & Contact::CF_ROLEDISABLED) !== 0) {
                $klass = "flex-grow-1 disabled";
                $p = "<p class=\"pt-1 mb-0 feedback is-warning\">Conference settings prevent this account from being enabled.</p>";
                $disabled = true;
            } else if ($us->user->security_locked_here()) {
                $klass = "flex-grow-1 disabled";
                $p = "<p class=\"pt-1 mb-0 feedback is-warning\">This account’s security settings are locked, so it cannot be " . ($disablement ? "enabled" : "disabled") . ".</p>";
                $disabled = true;
            } else {
                $klass = "ui js-disable-user flex-grow-1 " . ($disablement ? "btn-success" : "btn-danger");
                $p = "<p class=\"pt-1 mb-0\">Disabled accounts cannot sign in or view the site.</p>";
                $disabled = false;
            }
            echo '<div class="d-flex mf mf-absolute">',
                Ht::button($disablement ? "Enable account" : "Disable account", [
                    "class" => $klass, "disabled" => $disabled
                ]), '</div>', $p;
            self::print_delete_action($us);
        }

        echo '</div>';
    }

    function print_actions() {
        $this->cs()->print_end_section();
        $klass = "mt-7";
        $buttons = [
            Ht::submit("save", $this->is_new_user() ? "Create account" : "Save changes", ["class" => "btn-primary"]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true])
        ];
        if ($this->can_update_cdb()
            && $this->cdb_user()
            && $this->cs()->root === "main") {
            echo '<label class="checki mt-7"><span class="checkc">',
                Ht::hidden("has_update_global", 1),
                Ht::checkbox("update_global", 1, !$this->qreq->has_updateglobal || $this->qreq->updateglobal, ["class" => "ignore-diff"]),
                '</span>Update global profile</label>';
            $klass = "mt-3";
        }
        echo Ht::actions($buttons, ["class" => "aab aabig {$klass}"]);
    }



    static function print_bulk_entry(UserStatus $us) {
        echo Ht::textarea("bulkentry", $us->qreq->bulkentry, [
            "rows" => 1, "cols" => 80,
            "placeholder" => "Enter CSV user data with header",
            "class" => "want-focus need-autogrow",
            "spellcheck" => "false"
        ]);
        echo '<div class="g"><strong class="pr-1">OR</strong> ',
            '<input type="file" name="bulk" size="30"></div>';
    }

    static function print_bulk_actions(UserStatus $us) {
        echo '<label class="checki mt-5"><span class="checkc">',
            Ht::checkbox("bulkoverride", 1, !!friendly_boolean($us->qreq->bulkoverride), ["class" => "ignore-diff"]),
            '</span>Override existing names, affiliations, and collaborators</label>',
            '<div class="aab aabig mt-3">',
            '<div class="aabut">', Ht::submit("savebulk", "Save accounts", ["class" => "btn-primary"]), '</div>',
            '</div>';
    }

    static function print_bulk_help(UserStatus $us) {
        echo '<section class="mt-7"><h3>Instructions</h3>',
            "<p>Enter or upload CSV data with header, such as:</p>\n",
            '<pre class="sample">
name,email,affiliation,roles
John Adams,john@earbox.org,UC Berkeley,pc
"Adams, John Quincy",quincy@whitehouse.gov
</pre>',
            "\n<p>Or just enter an email address per line.</p>";

        $rows = [];
        foreach ($us->cs()->members("__bulk/help/f") as $gj) {
            $t = '<tr><td class="pad">';
            if (isset($gj->field_html)) {
                $t .= $gj->field_html;
            } else if (isset($gj->field)) {
                $t .= '<code>' . htmlspecialchars($gj->field) . '</code>';
            } else {
                $t .= '<code>' . htmlspecialchars(substr($gj->name, 14)) . '</code>';
            }
            $t .= '</td><td class="pad">' . $gj->description_html . '</td></tr>';
            $rows[] = $t;
        }

        if (!empty($rows)) {
            echo '<p>Supported CSV fields include:</p>',
                '<table class="table-striped mb-p"><thead>',
                '<tr><th class="pll">Field</th><th class="pll">Description</th></tr></thead>',
                '<tbody>', join('', $rows), '</tbody></table>';
        }

        echo '</section>';
    }

    static function print_bulk_help_topics(UserStatus $us) {
        if (!$us->conf->has_topics()) {
            return;
        }
        echo '<dl class="bsp ctelt mb-2"><dt><code>topic: &lt;TOPIC NAME&gt;</code></dt>',
            '<dd>Topic interest: blank, “<code>low</code>”, “<code>medium-low</code>”, “<code>medium-high</code>”, or “<code>high</code>”, or numeric (-2 to 2)</dd></dl>';
    }



    /** @param string $name */
    function print_members($name) {
        $this->cs()->print_members($name);
    }

    /** @param string $title
     * @param ?string $hashid */
    function print_start_section($title, $hashid = null) {
        $this->cs()->print_start_section($title, $hashid);
    }

    /** @param string $name */
    function request_group($name) {
        $cs = $this->cs();
        foreach ($cs->members($name, "request_function") as $gj) {
            if (($gj->request_recent_authentication ?? false)
                && !$this->has_recent_authentication()) {
                continue;
            }
            if ($cs->call_function($gj, $gj->request_function, $gj) === false) {
                break;
            }
        }
    }
}
