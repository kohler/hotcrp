<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

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
    /** @var Contact */
    public $user;
    /** @var bool */
    public $is_auth_user;

    /** @var bool */
    public $notify = false;
    /** @var bool */
    public $no_deprivilege_self = false;
    /** @var bool */
    public $update_profile_if_empty = false;
    /** @var bool */
    public $update_pc_if_empty = false;
    /** @var bool */
    public $no_create = false;
    /** @var bool */
    public $no_modify = false;
    /** @var ?array<string,true> */
    public $unknown_topics = null;

    /** @var Qrequest */
    public $qreq;
    /** @var CsvRow */
    public $csvreq;
    /** @var object */
    public $jval;

    /** @var ?bool */
    private $_req_security;
    /** @var bool */
    public $created;
    /** @var bool */
    public $notified;
    /** @var associative-array<string,true|string> */
    public $diffs = [];

    /** @var ?ComponentSet */
    private $_cs;
    /** @var bool */
    private $_inputs_printed = false;

    /** @var array<string,int>
     * @readonly */
    static public $watch_keywords = [
        "register" => Contact::WATCH_PAPER_REGISTER_ALL,
        "submit" => Contact::WATCH_PAPER_NEWSUBMIT_ALL,
        "latewithdraw" => Contact::WATCH_LATE_WITHDRAWAL_ALL,
        "review" => Contact::WATCH_REVIEW,
        "anyreview" => Contact::WATCH_REVIEW_ALL,
        "adminreview" => Contact::WATCH_REVIEW_MANAGED,
        "finalupdate" => Contact::WATCH_FINAL_UPDATE_ALL,

        "allregister" => Contact::WATCH_PAPER_REGISTER_ALL,
        "allnewsubmit" => Contact::WATCH_PAPER_NEWSUBMIT_ALL,
        "reviews" => Contact::WATCH_REVIEW,
        "allreviews" => Contact::WATCH_REVIEW_ALL,
        "adminreviews" => Contact::WATCH_REVIEW_MANAGED,
        "managedreviews" => Contact::WATCH_REVIEW_MANAGED,
        "allfinal" => Contact::WATCH_FINAL_UPDATE_ALL
    ];

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
        if ($viewer->is_root_user()) {
            $this->set_user(Contact::make($this->conf));
        } else {
            $this->set_user($viewer);
        }
        parent::__construct();
        $this->set_want_ftext(true, 5);
    }

    function clear() {
        $this->clear_messages();
        $this->unknown_topics = null;
    }

    function set_user(Contact $user) {
        if ($user !== $this->user) {
            $this->user = $user;
            $auth_user = $this->viewer->base_user();
            $this->is_auth_user = $auth_user->has_email()
                && strcasecmp($auth_user->email, $user->email) === 0;
            if ($this->_cs) {
                $this->_cs->reset_context();
                $this->initialize_cs();
            }
        }
    }

    /** @return bool */
    function is_new_user() {
        return !$this->user->email;
    }

    /** Test if the edited user is the authenticated user.
     * @return bool */
    function is_auth_user() {
        return $this->is_auth_user;
    }

    /** Test if the edited user is the authenticated user and same as the viewer.
     * @return bool */
    function is_auth_self() {
        return $this->is_auth_user && !$this->viewer->is_actas_user();
    }

    /** @return ?Contact */
    function cdb_user() {
        return $this->user->cdb_user();
    }

    /** @return ?Contact */
    function actor() {
        return $this->viewer->is_root_user() ? null : $this->viewer;
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
        if ($this->_cs === null) {
            $this->_cs = new ComponentSet($this->viewer, ["etc/profilegroups.json"], $this->conf->opt("profileGroups"));
            $this->_cs->set_title_class("form-h")
                ->set_section_class("form-section")
                ->set_separator('<hr class="form-sep">')
                ->add_print_callback([$this, "_print_callback"]);
            $this->_cs->add_xt_checker([$this, "xt_allower"]);
            $this->initialize_cs();
        }
        return $this->_cs;
    }

    private function initialize_cs() {
        $this->_cs->set_callable("UserStatus", $this)->set_context_args($this);
    }

    /** @return bool */
    function allow_some_security() {
        return !$this->user->is_empty()
            && ($this->is_auth_self() || $this->viewer->can_edit_any_password());
    }

    /** @return ?bool */
    function xt_allower($e, $xt, $xtp) {
        if ($e === "profile_security") {
            return $this->allow_some_security();
        } else if ($e === "auth_self") {
            return $this->is_auth_self();
        } else {
            return null;
        }
    }

    /** @return 0|1|2 */
    function update_if_empty(Contact $user) {
        // CDB user profiles belong to their owners
        if ($user->is_cdb_user()
            && (strcasecmp($user->email, $this->viewer->email) !== 0
                || $this->viewer->is_actas_user())) {
            return 1;
        } else if (($this->jval->user_override ?? null) !== null) {
            return $this->jval->user_override ? 0 : 1;
        } else {
            return $this->update_profile_if_empty ? 1 : 0;
        }
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
        return plural_word(count($pids), "submission") . " " . $l;
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
        if ($roles) {
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
        } else {
            return null;
        }
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
            foreach (self::$watch_keywords as $kw => $bit) {
                if ($dw === 0) {
                    break;
                } else if (($dw & $bit) !== 0) {
                    $cj->follow->$kw = true;
                    $dw &= ~$bit;
                }
            }
        }

        if (($tags = $user->viewable_tags($us->viewer))) {
            $tagger = new Tagger($us->viewer);
            $cj->tags = explode(" ", $tagger->unparse($tags));
        }

        if ($user->contactId && ($tm = $user->topic_interest_map())) {
            $cj->topics = (object) $tm;
        }

        $user_data = clone $user->data();
        foreach (Contact::$props as $prop => $shape) {
            unset($user_data->$prop);
        }
        if (!empty(get_object_vars($user_data))) {
            $cj->data = $user_data;
        }
    }

    function user_json() {
        if ($this->user) {
            $this->jval = (object) [];
            if ($this->user->contactId > 0) {
                $this->jval->id = $this->user->contactId;
            }
            $cs = $this->cs();
            foreach ($cs->members("", "unparse_json_function") as $gj) {
                $cs->call_function($gj, $gj->unparse_json_function, $gj);
            }
            return $this->jval;
        } else {
            return null;
        }
    }


    private function make_keyed_object($x, $field, $lc = false) {
        if (is_string($x)) {
            $x = preg_split('/[\s,;]+/', $x);
        }
        $res = [];
        if (is_object($x) || is_associative_array($x)) {
            foreach ((array) $x as $k => $v) {
                $res[$lc ? strtolower($k) : $k] = $v;
            }
        } else if (is_array($x)) {
            foreach ($x as $v) {
                if (!is_string($v)) {
                    $this->error_at($field, "<0>Format error [$field]");
                } else if ($v !== "") {
                    $res[$lc ? strtolower($v) : $v] = true;
                }
            }
        } else {
            $this->error_at($field, "<0>Format error [$field]");
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

    /** @return list<string> */
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
        foreach ($t0 as $t) {
            if ($t !== "") {
                if (($tx = $tagger->check($t, Tagger::NOPRIVATE))) {
                    $t1[] = $tx;
                } else {
                    $this->error_at($key, $tagger->error_ftext(true));
                }
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
                $this->error_at($k, "<0>Format error [$k]");
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
                   && (!$old_user || $old_user->email !== $cj->email)) {
            $this->error_at("email", "<0>Invalid email address ‘{$cj->email}’");
        }

        // ID
        if (!isset($cj->id)
            && $old_user
            && $old_user->contactId) {
            $cj->id = $old_user->contactId;
        }
        if (isset($cj->id)
            && $cj->id !== "new"
            && $old_user
            && ($cj->email ?? false)
            && strtolower($old_user->email) !== strtolower($cj->email)
            && $this->conf->fresh_user_by_email($cj->email)) {
            $this->error_at("email", "<0>Email address ‘{$cj->email}’ already in use");
            $this->msg_at("email", "<5>You may want to <a href=\"" . $this->conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.", MessageSet::INFORM);
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
            $cj->follow = $this->make_keyed_object($cj->follow, "follow", true);
            $cj->bad_follow = [];
            foreach ((array) $cj->follow as $k => $v) {
                if ($v
                    && $k !== "none"
                    && $k !== "partial"
                    && !isset(self::$watch_keywords[$k])) {
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
            $cj->remove_tags = $this->make_tags_array($cj->remove_tags, "add_tags");
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

    /** @param int $old_roles
     * @return int */
    private function parse_roles($j, $old_roles) {
        if (is_object($j) || is_associative_array($j)) {
            $reset_roles = true;
            $ij = [];
            foreach ((array) $j as $k => $v) {
                if ($v === true) {
                    $ij[] = $k;
                } else if ($v !== false && $v !== null) {
                    $this->error_at("roles", "<0>Format error [roles]");
                    return $old_roles;
                }
            }
        } else if (is_string($j)) {
            $reset_roles = null;
            $ij = preg_split('/[\s,;]+/', $j);
        } else if (is_array($j)) {
            $reset_roles = null;
            $ij = $j;
        } else {
            if ($j !== null) {
                $this->error_at("roles", "<0>Format error [roles]");
            }
            return $old_roles;
        }

        $add_roles = $remove_roles = 0;
        foreach ($ij as $v) {
            if (!is_string($v)) {
                $this->error_at("roles", "<0>Format error [roles]");
                return $old_roles;
            } else if ($v !== "") {
                $action = null;
                if (preg_match('/\A(\+|-|–|—|−)\s*(.*)\z/', $v, $m)) {
                    $action = $m[1] === "+";
                    $v = $m[2];
                }
                if ($v === "") {
                    $this->error_at("roles", "<0>Format error [roles]");
                    return $old_roles;
                } else if (is_bool($action) && strcasecmp($v, "none") === 0) {
                    $this->error_at("roles", "<0>Format error near “none” [roles]");
                    return $old_roles;
                } else if (is_bool($reset_roles) && is_bool($action) === $reset_roles) {
                    $this->warning_at("roles", "<0>Expected ‘" . ($reset_roles ? "" : "+") . "{$v}’ in roles");
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
                    $this->warning_at("roles", "<0>Unknown role ‘{$v}’");
                }
                if ($action !== false) {
                    $add_roles |= $role;
                } else {
                    $remove_roles |= $role;
                    $add_roles &= ~$role;
                }
            }
        }

        $roles = ($reset_roles ? 0 : ($old_roles & ~$remove_roles)) | $add_roles;
        if (($roles & Contact::ROLE_CHAIR) !== 0) {
            $roles |= Contact::ROLE_PC;
        }
        return $roles;
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
        if ($user->is_pc_member()) {
            if ($user->collaborators() === "") {
                $us->warning_at("collaborators", "<0>Please enter your recent collaborators and other affiliations");
                $us->msg_at("collaborators", "<0>This information can help detect conflicts of interest. Enter “None” if you have none.", MessageSet::INFORM);
            }
            if ($us->conf->has_topics()
                && !$user->topic_interest_map()
                && !$us->conf->opt("allowNoTopicInterests")) {
                $us->warning_at("topics", "<0>Please enter your topic interests");
                $us->msg_at("topics", "<0>We use topic interests to improve the paper assignment process.", MessageSet::INFORM);
            }
        }
    }


    /** @param object $cj
     * @param ?Contact $old_user
     * @return ?Contact */
    function save_user($cj, $old_user = null) {
        assert(is_object($cj));
        assert(!$old_user || (!$this->no_create && !$this->no_modify));
        $this->diffs = [];
        $this->created = $this->notified = false;

        // normalize name, including email
        self::normalize_name($cj);

        // check id and email
        if (isset($cj->id)
            && $cj->id !== "new"
            && (!is_int($cj->id) || $cj->id <= 0)) {
            $this->error_at("id", "<0>Format error [id]");
            return null;
        }
        if (isset($cj->email)
            && !is_string($cj->email)) {
            $this->error_at("email", "<0>Format error [email]");
            return null;
        }

        // obtain old users in this conference and contactdb
        // - load by id if only id is set
        if (!$old_user && isset($cj->id) && is_int($cj->id)) {
            $old_user = $this->conf->fresh_user_by_id($cj->id);
        }

        // - obtain email
        if ($old_user && $old_user->has_email()) {
            $old_email = $old_user->email;
        } else if (is_string($cj->email ?? null) && $cj->email !== "") {
            $old_email = $cj->email;
        } else {
            $old_email = null;
        }

        // - load old_cdb_user
        if ($old_user && $old_user->contactDbId > 0) {
            $old_cdb_user = $old_user;
        } else if ($old_email) {
            $old_cdb_user = $this->conf->fresh_cdb_user_by_email($old_email);
        } else {
            $old_cdb_user = null;
        }

        // - load old_user; reset if old_user was in contactdb
        if (!$old_user || !$old_user->has_account_here()) {
            if ($old_email) {
                $old_user = $this->conf->fresh_user_by_email($old_email);
            } else {
                $old_user = null;
            }
        }

        // - check no_create and no_modify
        if ($this->no_create && !$old_user) {
            if (isset($cj->id) && $cj->id !== "new") {
                $this->error_at("id", "<0>Refusing to create user with ID {$cj->id}");
            } else {
                $this->error_at("email", "<0>Refusing to create user with email {$cj->email}");
            }
            return null;
        } else if (($this->no_modify || ($cj->id ?? null) === "new") && $old_user) {
            if (isset($cj->id) && $cj->id !== "new") {
                $this->error_at("id", "<0>Refusing to modify existing user with ID {$cj->id}");
            } else {
                $this->error_at("email", "<0>Refusing to modify existing user with email {$cj->email}");
            }
            return null;
        }

        $user = $old_user ?? $old_cdb_user;

        // normalize and check for errors
        if (!isset($cj->id)) {
            $cj->id = $old_user ? $old_user->contactId : "new";
        }
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->error_at("id", "<0>Saving user with different ID");
            return null;
        }
        $this->normalize($cj, $user);
        $roles = $old_roles = $old_user ? $old_user->roles : 0;
        if (isset($cj->roles)) {
            $roles = $this->parse_roles($cj->roles, $roles);
            if ($old_user) {
                $roles = $this->check_role_change($roles, $old_user);
            }
        }
        if ($this->has_error()) {
            return null;
        }
        // At this point, we will save a user.

        // ensure/create user
        $this->check_invariants($cj);
        $actor = $this->viewer->is_root_user() ? null : $this->viewer;
        if (!$old_user) {
            $create_cj = array_merge((array) $cj, ["disablement" => Contact::CF_PLACEHOLDER]);
            $user = Contact::make_keyed($this->conf, $create_cj)->store(0, $actor);
            $cj->email = $user->email; // adopt contactdb’s email capitalization
        }
        if (!$user) {
            return null;
        }
        $old_disablement = $user->disabled_flags();

        // initialize
        if (isset($cj->email) && strcasecmp($cj->email, $user->email) !== 0) {
            error_log(debug_string_backtrace());
        }
        assert(!isset($cj->email) || strcasecmp($cj->email, $user->email) === 0);
        $this->created = !$old_user;
        $this->set_user($user);
        $user->invalidate_cdb_user();
        $cdb_user = $user->ensure_cdb_user();

        // Early properties
        $this->jval = $cj;
        $this->save_members("", "save_early_function", null);
        if (($user->prop_changed() || $this->created)
            && !$user->save_prop()) {
            return null;
        }

        // Roles
        if ($this->update_pc_if_empty
            && ($old_roles & Contact::ROLE_PCLIKE) !== 0) {
            $roles = ($roles & ~Contact::ROLE_PCLIKE) | ($old_roles & Contact::ROLE_PCLIKE);
        }
        if ($roles !== $old_roles
            && ($roles = $user->save_roles($roles, $actor, true)) !== $old_roles) {
            $this->diffs["roles"] = self::unparse_roles_diff($old_roles, $roles);
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
        if ($roles !== $old_roles
            || ($user->disabled_flags() !== 0) !== ($old_disablement !== 0)) {
            $user->update_cdb();
        }

        // Main properties
        $this->save_members("", "save_function", "save_members");

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

        return $user;
    }


    /** @param string $name
     * @param string $function
     * @param string $members
     * @return null|false */
    private function save_members($name, $function, $members) {
        $cs = $this->cs();
        foreach ($cs->members($name) as $gj) {
            if (isset($gj->$function)
                && $cs->call_function($gj, $gj->$function, $gj) === false) {
                return false;
            }
            if ($members !== null
                && ($gj->$members ?? false)
                && $this->save_members($gj->name, $function, $members) === false) {
                return false;
            }
        }
        return null;
    }

    static function save_main(UserStatus $us) {
        $user = $us->user;
        $cj = $us->jval;

        // Profile properties
        $us->set_profile_prop($user, $us->update_if_empty($user));
        if (($cdbu = $user->cdb_user())) {
            $us->set_profile_prop($cdbu, $us->update_if_empty($cdbu));
        }

        // Disabled
        $cflags = $user->cflags;
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
        $user->set_prop("disabled", $cflags & Contact::CFM_DISABLEMENT);
        $user->set_prop("cflags", $cflags);
        if ($user->prop_changed("disabled") && isset($cj->disabled)) {
            $us->diffs[$cj->disabled ? "disabled" : "enabled"] = true;
        }

        // Follow
        if (isset($cj->follow)
            && (!$us->update_pc_if_empty || $user->defaultWatch === Contact::WATCH_REVIEW)) {
            $w = 0;
            $wmask = ($cj->follow->partial ?? false ? 0 : 0xFFFFFFFF);
            foreach (self::$watch_keywords as $k => $bit) {
                if (isset($cj->follow->$k)) {
                    $wmask |= $bit;
                    $w |= $cj->follow->$k ? $bit : 0;
                }
            }
            $w |= $user->defaultWatch & ~$wmask;
            $user->set_prop("defaultWatch", $w);
            if ($user->prop_changed("defaultWatch")) {
                $us->diffs["follow"] = true;
            }
        }

        // Tags
        if ((isset($cj->tags) || isset($cj->add_tags) || isset($cj->remove_tags))
            && $us->viewer->privChair
            && (!$us->update_pc_if_empty || $user->contactTags === null)) {
            if (isset($cj->tags)) {
                $user->set_prop("contactTags", null);
            }
            foreach ($cj->tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, $value ?? 0);
                }
            }
            foreach ($cj->add_tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, $value ?? $user->tag_value($tag) ?? 0);
                }
            }
            foreach ($cj->remove_tags ?? [] as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $user->change_tag_prop($tag, false);
                }
            }
            if ($user->prop_changed("contactTags")) {
                $us->diffs["tags"] = true;
            }
        }

        // Data
        if (isset($cj->data) && is_object($cj->data)) {
            foreach (get_object_vars($cj->data) as $key => $value) {
                if ($user->set_data($key, $value)) {
                    $us->diffs["data"] = true;
                }
            }
        }
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
    }

    static function save_topics(UserStatus $us) {
        $topics = $us->jval->topics ?? null;
        '@phan-var-force array<int,int> $topics';
        if ($topics === null || !$us->conf->has_topics()) {
            return;
        }
        $ti = $us->created ? [] : $us->user->topic_interest_map();
        if ($us->update_pc_if_empty && !empty($ti)) {
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
        if (!$us->conf->external_login()) {
            if (isset($qreq->uemail) || $us->user->is_empty()) {
                $cj->email = trim((string) $qreq->uemail);
            } else {
                $cj->email = $us->user->email;
            }
        } else {
            if ($us->user->is_empty()) {
                $cj->email = trim((string) $qreq->newUsername);
            } else {
                $cj->email = $us->user->email;
            }
        }

        // whether to update global profile
        if (friendly_boolean($qreq->update_global ?? !$qreq->has_update_global) === false) {
            $cj->update_global = false;
        }

        // normal fields
        foreach (["firstName", "lastName", "preferredEmail", "affiliation",
                  "collaborators", "addressLine1", "addressLine2",
                  "city", "state", "zipCode", "country", "phone"] as $k) {
            if (($v = $qreq[$k]) !== null)
                $cj->$k = $v;
        }

        // PC components
        if (isset($qreq->pctype) && $us->viewer->privChair) {
            $cj->roles = [];
            $pctype = $qreq->pctype;
            if ($pctype === "chair") {
                $cj->roles[] = "chair";
                $cj->roles[] = "pc";
            } else if ($pctype === "pc") {
                $cj->roles[] = "pc";
            }
            if ($qreq->ass) {
                $cj->roles[] = "sysadmin";
            }
            $roles_pc = !empty($cj->roles);
            if (!$roles_pc) {
                $cj->roles[] = "none";
            }
        } else {
            $roles_pc = ($us->user->roles & Contact::ROLE_PCLIKE) !== 0;
        }

        $follow = [];
        if ($qreq->has_follow_register
            && ($us->viewer->privChair || $us->user->is_track_manager())) {
            $follow["register"] = !!$qreq->follow_register;
        }
        if ($qreq->has_follow_submit
            && ($us->viewer->privChair || $us->user->is_track_manager())) {
            $follow["submit"] = !!$qreq->follow_submit;
        }
        if ($qreq->has_follow_latewithdraw
            && ($us->viewer->privChair || $us->user->is_track_manager())) {
            $follow["latewithdraw"] = !!$qreq->follow_latewithdraw;
        }
        if ($qreq->has_follow_review) {
            $follow["review"] = !!$qreq->follow_review;
        }
        if ($qreq->has_follow_anyreview
            && ($us->viewer->privChair || $us->user->isPC)) {
            $follow["anyreview"] = !!$qreq->follow_anyreview;
        }
        if ($qreq->has_follow_adminreview
            && ($us->viewer->privChair || $us->user->is_manager())) {
            $follow["adminreview"] = !!$qreq->follow_adminreview;
        }
        if ($qreq->has_follow_finalupdate
            && ($us->viewer->privChair || $us->user->is_manager())) {
            $follow["finalupdate"] = !!$qreq->follow_finalupdate;
        }
        if (!empty($follow) && $roles_pc) {
            $follow["partial"] = true;
            $cj->follow = (object) $follow;
        }

        if (isset($qreq->tags) && $roles_pc && $us->viewer->privChair) {
            $cj->tags = explode(" ", simplify_whitespace($qreq->tags));
        }

        if (isset($qreq->has_ti) && $roles_pc && $us->viewer->isPC) {
            $topics = [];
            foreach ($us->conf->topic_set() as $id => $t) {
                if (isset($qreq["ti$id"]) && is_numeric($qreq["ti$id"])) {
                    $topics[$id] = (int) $qreq["ti$id"];
                }
            }
            $cj->topics = (object) $topics;
        }
    }

    /** @param string $k
     * @return ?string */
    function field_label($k) {
        if ($k === "firstName") {
            return "First name";
        } else if ($k === "lastName") {
            return "Last name";
        } else if ($k === "email" || $k === "uemail") {
            return "Email";
        } else if ($k === "affiliation") {
            return "Affiliation";
        } else if ($k === "collaborators") {
            return "Collaborators";
        } else if ($k === "topics") {
            return "Topics";
        } else {
            return null;
        }
    }


    /** @return bool
     * @deprecated */
    function has_req_security() {
        return $this->cs()->callable("Security_UserInfo")->allow_security_changes();
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
            for ($i = 1; !$csv->has_column($ks[0]) && isset($ks[$i]); ++$i) {
                $csv->add_synonym($ks[0], $ks[$i]);
            }
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
            assert(is_array($cj->roles) && !is_associative_array($cj->roles));
            if (in_array("chair", $cj->roles)) {
                return "chair";
            } else if (in_array("pc", $cj->roles)) {
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
        $actas = "";
        if ($user !== $us->viewer
            && $user->email !== ""
            && $us->viewer->privChair) {
            $actas = '&nbsp;' . actas_link($user);
        }

        if (!$us->conf->external_login()) {
            $email_class = "want-focus fullw";
            if ($user->can_lookup_user()) {
                $email_class .= " uii js-email-populate";
            }
            $us->print_field("uemail", "Email" . $actas,
                Ht::entry("uemail", $qreq->email ?? $user->email, ["class" => $email_class, "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => $user->email, "type" => "email"]));
        } else if (!$user->is_empty()) {
            $us->print_field("", "Username" . $actas,
                htmlspecialchars($user->email));
            $us->print_field("preferredEmail", "Email",
                Ht::entry("preferredEmail", $qreq->preferredEmail ?? $user->preferredEmail, ["class" => "want-focus fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => $user->preferredEmail, "type" => "email"]));
        } else {
            $us->print_field("uemail", "Username",
                Ht::entry("newUsername", $qreq->email ?? $user->email, ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => $user->email]));
            $us->print_field("preferredEmail", "Email",
                      Ht::entry("preferredEmail", $qreq->preferredEmail ?? $user->preferredEmail, ["class" => "fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => $user->preferredEmail, "type" => "email"]));
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

    static function print_country(UserStatus $us) {
        $t = Countries::selector("country", $us->qreq->country ?? $us->user->country(), ["id" => "country", "data-default-value" => $us->user->country(), "autocomplete" => $us->autocomplete("country")]) . $us->global_profile_difference("country");
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
        foreach (self::$watch_keywords as $kw => $bit) {
            if ($qreq["has_follow_{$kw}"] || $qreq["follow_{$kw}"]) {
                $reqwatch = ($reqwatch & ~$bit) | ($qreq["follow_{$kw}"] ? $bit : 0);
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
            && in_array($us->qreq->pctype, ["chair", "pc", "none"])) {
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
            '<p class="f-h">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</p></div></td></tr></table>', "\n";
    }

    static function print_collaborators(UserStatus $us) {
        if (!$us->user->isPC
            && !$us->qreq->collaborators
            && !$us->user->collaborators()
            && !$us->viewer->privChair) {
            return;
        }
        $cd = $us->conf->_i("conflictdef");
        $us->cs()->add_section_class("w-text")->print_start_section();
        echo '<h3 class="', $us->control_class("collaborators", "form-h"), '">Collaborators and other affiliations</h3>', "\n",
            "<p>List potential conflicts of interest one per line, using parentheses for affiliations and institutions. We may use this information when assigning reviews.<br>Examples: “Ping Yen Zhang (INRIA)”, “All (University College London)”</p>";
        if ($cd !== "" && preg_match('/<(?:p|div)[ >]/', $cd)) {
            echo $cd;
        } else {
            echo '<p>', $cd, '</p>';
        }
        echo $us->feedback_html_at("collaborators"),
            '<textarea name="collaborators" rows="5" cols="80" class="',
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
        $us->cs()->add_section_class("w-text fx1")->print_start_section("Topic interests");
        echo '<p>Please indicate your interest in reviewing papers on these conference
topics. We use this information to help match papers to reviewers.</p>',
            Ht::hidden("has_ti", 1),
            $us->feedback_html_at("ti"),
            '  <table class="table-striped"><thead>
    <tr><td></td><th class="ti_interest">Low</th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest">High</th></tr>
    <tr><td></td><th class="topic-2"></th><th class="topic-1"></th><th class="topic0"></th><th class="topic1"></th><th class="topic2"></th></tr></thead><tbody>', "\n";

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $tmap = $us->user->topic_interest_map();
        $ts = $us->conf->topic_set();
        foreach ($ts->group_list() as $tg) {
            foreach ($tg->members() as $i => $tid) {
                $tic = "ti_topic";
                if ($i === 0) {
                    $n = $ts->unparse_name_html($tid);
                } else {
                    $n = $ts->unparse_subtopic_name_html($tid);
                    $tic .= " ti_subtopic";
                }
                echo "      <tr><td class=\"{$tic}\">{$n}</td>";
                $ival = $tmap[$tid] ?? 0;
                $reqval = isset($us->qreq["ti$tid"]) ? (int) $us->qreq["ti$tid"] : $ival;
                for ($j = -2; $j <= 2; ++$j) {
                    $ichecked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                    $reqchecked = $reqval >= $ibound[$j+2] && $reqval < $ibound[$j+3];
                    echo '<td class="ti_interest">', Ht::radio("ti$tid", $j, $reqchecked, ["class" => "uic js-range-click", "data-range-type" => "topicinterest$j", "data-default-checked" => $ichecked]), "</td>";
                }
                echo "</tr>\n";
            }
        }
        echo "    </tbody></table>\n";
    }

    static function print_tags(UserStatus $us) {
        $user = $us->user;
        $tagger = new Tagger($us->viewer);
        $itags = $tagger->unparse($user->viewable_tags($us->viewer));
        if (!$us->viewer->privChair
            && (!$us->user->isPC || $itags === "")) {
            return;
        }
        $us->cs()->add_section_class("w-text fx2")->print_start_section("Tags");
        if ($us->viewer->privChair) {
            echo '<div class="', $us->control_class("tags", "f-i"), '">',
                $us->feedback_html_at("tags"),
                Ht::entry("tags", $us->qreq->tags ?? $itags, ["data-default-value" => $itags, "class" => "fullw"]),
                "</div>
  <p class=\"f-h\">Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href=\"", $us->conf->hoturl("settings", "group=tags"), "\">tag colors</a> to highlight subgroups in review lists.</p>\n";
        } else {
            echo $itags, "<p class=\"f-h\">Tags represent PC subgroups and are set by administrators.</p>\n";
        }
    }

    private static function print_delete_action(UserStatus $us) {
        if ($us->user->security_locked_here()) {
            return;
        }
        $tracks = self::user_paper_info($us->conf, $us->user->contactId);
        $args = ["class" => "ui btn-danger"];
        if (!empty($tracks->soleAuthor)) {
            $args["class"] .= " js-cannot-delete-user";
            $args["data-sole-author"] = self::render_paper_link($us->conf, $tracks->soleAuthor);
        } else {
            $args["class"] .= " js-delete-user";
            $x = $y = [];
            if (!empty($tracks->author)) {
                $x[] = "is contact for " . self::render_paper_link($us->conf, $tracks->author);
                $y[] = "delete " . plural_word($tracks->author, "this authorship association");
            }
            if (!empty($tracks->review)) {
                $x[] = "reviewed " . self::render_paper_link($us->conf, $tracks->review);
                $y[] = "<strong>permanently delete</strong> " . plural_word($tracks->review, "this review");
            }
            if (!empty($tracks->comment)) {
                $x[] = "commented on " . self::render_paper_link($us->conf, $tracks->comment);
                $y[] = "<strong>permanently delete</strong> " . plural_word($tracks->comment, "this comment");
            }
            if (!empty($x)) {
                $args["data-delete-info"] = "<p>This user " . commajoin($x) . ". Deleting the user will also " . commajoin($y) . ".</p>";
            }
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

        if (!$us->is_auth_user()) {
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
        if ($this->is_auth_self() && $this->cs()->root === "main") {
            if ($this->cdb_user()) {
                echo '<label class="checki mt-7"><span class="checkc">',
                    Ht::hidden("has_update_global", 1),
                    Ht::checkbox("update_global", 1, !$this->qreq->has_updateglobal || $this->qreq->updateglobal, ["class" => "ignore-diff"]),
                    '</span>Update global profile</label>';
                $klass = "mt-3";
            }
            array_push($buttons, "", Ht::submit("merge", "Merge with another account"));
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
            Ht::checkbox("bulkoverride", 1, isset($us->qreq->bulkoverride), ["class" => "ignore-diff"]),
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
        if ($us->conf->has_topics()) {
            echo '<dl class="ctelt dd"><dt><code>topic: &lt;TOPIC NAME&gt;</code></dt>',
                '<dd>Topic interest: blank, “<code>low</code>”, “<code>medium-low</code>”, “<code>medium-high</code>”, or “<code>high</code>”, or numeric (-2 to 2)</dd></dl>';
        }
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
            assert(!isset($gj->allow_request_if));
            if ($cs->call_function($gj, $gj->request_function, $gj) === false) {
                break;
            }
        }
    }
}
