<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class UserStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var Contact */
    public $user;
    /** @var ?bool */
    public $self;
    private $no_notify = null;
    private $no_deprivilege_self = false;
    private $no_update_profile = false;
    private $no_create = false;
    private $no_modify = false;
    public $unknown_topics = null;
    /** @var bool */
    private $created;
    /** @var ?array<string,true> */
    public $diffs;
    /** @var ?GroupedExtensions */
    private $_gxt;
    private $_req_security;
    private $_req_need_security;
    private $_req_passwords;

    public static $watch_keywords = [
        "reviews" => Contact::WATCH_REVIEW,
        "allreviews" => Contact::WATCH_REVIEW_ALL,
        "adminreviews" => Contact::WATCH_REVIEW_MANAGED,
        "managedreviews" => Contact::WATCH_REVIEW_MANAGED,
        "allfinal" => Contact::WATCH_FINAL_SUBMIT_ALL
    ];

    static private $field_synonym_map = [
        "preferredEmail" => "preferred_email",
        "addressLine1" => "address", "addressLine2" => "address",
        "zipCode" => "zip", "postal_code" => "zip",
        "contactTags" => "tags", "uemail" => "email"
    ];

    static public $topic_interest_name_map = [
        "low" => -2, "lo" => -2,
        "medium-low" => -1, "medium_low" => -1, "mediumlow" => -1, "mlow" => -1,
        "medium-lo" => -1, "medium_lo" => -1, "mediumlo" => -1, "mlo" => -1,
        "medium" => 0, "none" => 0, "med" => 0,
        "medium-high" => 1, "medium_high" => 1, "mediumhigh" => 1, "mhigh" => 1,
        "medium-hi" => 1, "medium_hi" => 1, "mediumhi" => 1, "mhi" => 1,
        "high" => 2, "hi" => 2
    ];

    function __construct(Contact $viewer, $options = []) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        parent::__construct();
        foreach (["no_notify", "no_deprivilege_self", "no_update_profile",
                  "no_create", "no_modify"] as $k) {
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        }
        foreach (self::$field_synonym_map as $src => $dst) {
            $this->translate_field($src, $dst);
        }
    }
    function clear() {
        $this->clear_messages();
        $this->unknown_topics = null;
    }
    function set_user(Contact $user) {
        $old_user = $this->user;
        $this->user = $user;
        $this->self = $this->user === $this->viewer
            && !$this->viewer->is_actas_user();
        if ($this->_gxt && $this->user !== $old_user) {
            $this->_gxt->reset_context();
            $this->initialize_gxt();
        }
    }
    function is_new_user() {
        return $this->user && !$this->user->email;
    }
    function is_viewer_user() {
        return $this->user && $this->user->contactId == $this->viewer->contactId;
    }
    function global_self() {
        return $this->self ? $this->user->contactdb_user() : null;
    }
    /** @deprecated */
    function global_user() { // XXX
        return $this->global_self();
    }
    function actor() {
        return $this->viewer->is_root_user() ? null : $this->viewer;
    }

    function gxt() {
        if ($this->_gxt === null) {
            $this->_gxt = new GroupedExtensions($this->viewer, ["etc/profilegroups.json"], $this->conf->opt("profileGroups"));
            $this->_gxt->add_xt_checker([$this, "xt_allower"]);
            $this->initialize_gxt();
        }
        return $this->_gxt;
    }
    private function initialize_gxt() {
        $this->_gxt->set_context(["hclass" => "form-h"]);
        $this->_gxt->set_callable("UserStatus", $this);
    }

    function allow_security() {
        return !$this->conf->external_login()
            && (!$this->user
                || $this->self
                || (!$this->user->is_empty()
                    && $this->viewer->privChair
                    && !$this->conf->contactdb()
                    && !$this->conf->opt("chairHidePasswords")));
    }
    function xt_allower($e, $xt, Contact $user, Conf $conf) {
        if ($e === "profile_security") {
            return $this->allow_security();
        } else if ($e === "self") {
            return !$this->user || $this->self;
        } else if ($e === "global_self") {
            return !$this->user || ($this->self && $this->global_self());
        } else {
            return null;
        }
    }

    /** @return bool */
    function only_update_empty(Contact $user) {
        return $this->no_update_profile
            || ($user->cdb_confid !== 0
                && (strcasecmp($user->email, $this->viewer->email) !== 0
                    || $this->viewer->is_actas_user()
                    || $this->viewer->is_root_user()));
                       // XXX want way in script to modify all
    }

    static function user_paper_info(Conf $conf, $cid) {
        $pinfo = (object) ["soleAuthor" => [], "author" => [], "review" => [], "comment" => []];

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

    static function render_paper_link(Conf $conf, $pids) {
        if (count($pids) === 1) {
            return Ht::link("#{$pids[0]}", $conf->hoturl("paper", ["p" => $pids[0]]));
        } else {
            return Ht::link(commajoin(array_map(function ($p) { return "#$p"; }, $pids)),
                            $conf->hoturl("search", ["q" => join(" ", $pids)]));
        }
    }

    function autocomplete($what) {
        if ($this->self) {
            return $what;
        } else if ($what === "email" || $what === "username" || $what === "current-password") {
            return "nope";
        } else {
            return "off";
        }
    }

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

    static function unparse_json_main(UserStatus $us, $cj, $args) {
        $user = $us->user;

        // keys that might come from user or contactdb
        foreach (["email", "firstName", "lastName", "affiliation",
                  "collaborators", "country", "phone", "address",
                  "city", "state", "zip", "country"] as $prop) {
            $value = $user->gprop($prop);
            if ($value !== null && $value !== "") {
                $cj->$prop = $value;
            }
        }

        if ($user->disabled) {
            $cj->disabled = true;
        }

        if ($user->roles) {
            $cj->roles = self::unparse_roles_json($user->roles);
        }

        if ($user->defaultWatch) {
            $cj->follow = (object) array();
            if ($user->defaultWatch & Contact::WATCH_REVIEW) {
                $cj->follow->reviews = true;
            }
            if ($user->defaultWatch & Contact::WATCH_REVIEW_ALL) {
                $cj->follow->reviews = $cj->follow->allreviews = true;
            }
            if ($user->defaultWatch & Contact::WATCH_REVIEW_MANAGED) {
                $cj->follow->adminreviews = true;
            }
            if ($user->defaultWatch & Contact::WATCH_FINAL_SUBMIT_ALL) {
                $cj->follow->allfinal = true;
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

    function user_json($args = []) {
        if ($this->user) {
            $cj = (object) [];
            if ($this->user->contactId > 0) {
                $cj->id = $this->user->contactId;
            }
            $gx = $this->gxt();
            $gx->set_context(["args" => [$this, $cj, $args]]);
            foreach ($gx->members("", "unparse_json_callback") as $gj) {
                $gx->call_callback($gj->unparse_json_callback, $gj);
            }
            return $cj;
        } else {
            return null;
        }
    }


    private function make_keyed_object($x, $field, $lc = false) {
        if (is_string($x)) {
            $x = preg_split('/[\s,]+/', $x);
        }
        $res = [];
        if (is_object($x) || is_associative_array($x)) {
            foreach ((array) $x as $k => $v) {
                $res[$lc ? strtolower($k) : $k] = $v;
            }
        } else if (is_array($x)) {
            foreach ($x as $v) {
                if (!is_string($v)) {
                    $this->error_at($field, "Format error [$field]");
                } else if ($v !== "") {
                    $res[$lc ? strtolower($v) : $v] = true;
                }
            }
        } else {
            $this->error_at($field, "Format error [$field]");
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

    private function make_tags_array($x, $key) {
        $t0 = array();
        if (is_string($x)) {
            $t0 = preg_split('/[\s,]+/', $x);
        } else if (is_array($x)) {
            $t0 = $x;
        } else if ($x !== null) {
            $this->error_at($key, "Format error [$key]");
        }
        $tagger = new Tagger($this->viewer);
        $t1 = array();
        foreach ($t0 as $t) {
            if ($t !== "") {
                if (($tx = $tagger->check($t, Tagger::NOPRIVATE))) {
                    $t1[] = $tx;
                } else {
                    $this->error_at($key, $tagger->error_html);
                }
            }
        }
        return $t1;
    }

    /** @param ?Contact $old_user */
    private function normalize($cj, $old_user) {
        // Errors prevent saving

        // Canonicalize keys
        foreach (array("preferredEmail" => "preferred_email",
                       "institution" => "affiliation",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "zipCode" => "zip", "postal_code" => "zip") as $x => $y) {
            if (isset($cj->$x) && !isset($cj->$y)) {
                $cj->$y = $cj->$x;
            }
        }

        // Stringiness
        foreach (array("firstName", "lastName", "email", "preferred_email",
                       "affiliation", "phone", "new_password",
                       "city", "state", "zip", "country") as $k) {
            if (isset($cj->$k) && !is_string($cj->$k)) {
                $this->error_at($k, "Format error [$k]");
                unset($cj->$k);
            }
        }

        // Email
        if (!isset($cj->email)) {
            if ($old_user) {
                $cj->email = $old_user->email;
            } else {
                $this->error_at("email", "Email is required.");
            }
        } else if (!$this->has_problem_at("email")
                   && !validate_email($cj->email)
                   && (!$old_user || $old_user->email !== $cj->email)) {
            $this->error_at("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");
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
            && $this->conf->user_id_by_email($cj->email)) {
            $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use. You may want to <a href=\"" . $this->conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.");
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

        // Password changes
        if (isset($cj->new_password)
            && $old_user
            && $old_user->data("locked")) {
            unset($cj->new_password);
            $this->warning_at("password", "Ignoring request to change locked user’s password.");
        }

        // Preferred email
        if (($cj->preferred_email ?? false)
            && !$this->has_problem_at("preferred_email")
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email)) {
            $this->error_at("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");
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
                $this->error_at("address2", "Format error [address2]");
            }
        } else if ($cj->address ?? null) {
            $this->error_at("address", "Format error [address]");
        }
        if ($address !== null) {
            foreach ($address as &$a) {
                if (!is_string($a)) {
                    $this->error_at("address", "Format error [address]");
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
                    $this->error_at("collaborators", "Format error [collaborators]");
                }
            }
            $collaborators = $this->has_problem_at("collaborators") ? null : join("\n", $collaborators);
        }
        if (is_string($collaborators)) {
            $old_collab = rtrim(cleannl($collaborators));
            $new_collab = AuthorMatcher::fix_collaborators($old_collab) ?? "";
            if ($old_collab !== $new_collab) {
                $this->warning_at("collaborators", "Collaborators changed to follow our required format. You may want to look them over.");
            }
            $collaborators = $new_collab;
        } else if ($collaborators !== null) {
            $this->error_at("collaborators", "Format error [collaborators]");
        }
        if (isset($cj->collaborators)) {
            $cj->collaborators = $collaborators;
        }

        // Disabled
        if (isset($cj->disabled)) {
            if (($x = friendly_boolean($cj->disabled)) !== null) {
                $cj->disabled = $x;
            } else {
                $this->error_at("disabled", "Format error [disabled]");
            }
        }

        // Follow
        if (isset($cj->follow) && $cj->follow !== "") {
            $cj->follow = $this->make_keyed_object($cj->follow, "follow", true);
            $cj->bad_follow = array();
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
        if (isset($cj->add_tags) || isset($cj->remove_tags)) {
            // collect old tags as map by base
            if (!isset($cj->tags) && $old_user) {
                $cj->tags = preg_split('/[\s,]+/', $old_user->contactTags);
            } else if (!isset($cj->tags)) {
                $cj->tags = array();
            }
            $old_tags = array();
            foreach ($cj->tags as $t) {
                if ($t !== "") {
                    list($tag, $index) = Tagger::unpack($t);
                    $old_tags[strtolower($tag)] = [$tag, $index];
                }
            }
            // process removals, then additions
            foreach ($this->make_tags_array($cj->remove_tags ?? null, "remove_tags") as $t) {
                list($tag, $index) = Tagger::unpack($t);
                if ($index !== false) {
                    $ti = $old_tags[strtolower($tag)] ?? null;
                    if (!$ti || $ti[1] != $index)
                        continue;
                }
                unset($old_tags[strtolower($tag)]);
            }
            foreach ($this->make_tags_array($cj->add_tags ?? null, "add_tags") as $t) {
                list($tag, $index) = Tagger::unpack($t);
                $old_tags[strtolower($tag)] = [$tag, $index];
            }
            // collect results
            $cj->tags = array_map(function ($ti) { return $ti[0] . "#" . (float) $ti[1]; }, $old_tags);
        }

        // Topics
        $in_topics = null;
        if (isset($cj->topics)) {
            $in_topics = $this->make_keyed_object($cj->topics, "topics");
        } else if (isset($cj->change_topics)) {
            $in_topics = $this->make_keyed_object($cj->change_topics, "change_topics");
        }
        if ($in_topics !== null) {
            $topics = !isset($cj->topics) && $old_user ? $old_user->topic_interest_map() : [];
            $cj->bad_topics = array();
            foreach ((array) $in_topics as $k => $v) {
                if ($this->conf->topic_set()->get($k)) {
                    $k = (int) $k;
                } else if (($tid = $this->conf->topic_abbrev_matcher()->find1($k))) {
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
                    $this->error_at("topics", "Format error [topic interest]");
                    continue;
                }
                $topics[$k] = $v;
            }
            $cj->topics = (object) $topics;
        }
    }

    /** @param int $old_roles */
    private function parse_roles($j, $old_roles) {
        if (is_object($j) || is_associative_array($j)) {
            $reset_roles = true;
            $ij = [];
            foreach ((array) $j as $k => $v) {
                if ($v === true) {
                    $ij[] = $k;
                } else if ($v !== false && $v !== null) {
                    $this->error_at("roles", "Format error [roles]");
                    return $old_roles;
                }
            }
        } else if (is_string($j)) {
            $reset_roles = null;
            $ij = preg_split('/[\s,]+/', $j);
        } else if (is_array($j)) {
            $reset_roles = null;
            $ij = $j;
        } else {
            if ($j !== null) {
                $this->error_at("roles", "Format error [roles]");
            }
            return $old_roles;
        }

        $add_roles = $remove_roles = 0;
        foreach ($ij as $v) {
            if (!is_string($v)) {
                $this->error_at("roles", "Format error [roles]");
                return $old_roles;
            } else if ($v !== "") {
                $action = null;
                if (preg_match('/\A(\+|-|–|—|−)\s*(.*)\z/', $v, $m)) {
                    $action = $m[1] === "+";
                    $v = $m[2];
                }
                if ($v === "") {
                    $this->error_at("roles", "Format error [roles]");
                    return $old_roles;
                } else if (is_bool($action) && strcasecmp($v, "none") === 0) {
                    $this->error_at("roles", "Format error near “none” [roles]");
                    return $old_roles;
                } else if (is_bool($reset_roles) && is_bool($action) === $reset_roles) {
                    $this->warning_at("roles", "Expected “" . ($reset_roles ? "" : "+") . htmlspecialchars($v) . "” in roles");
                } else if ($reset_roles === null) {
                    $reset_roles = $action === null;
                }
                $role = 0;
                if (strcasecmp($v, "pc") === 0) {
                    $role = Contact::ROLE_PC;
                } else if (strcasecmp($v, "chair") === 0) {
                    $role = Contact::ROLE_CHAIR;
                } else if (strcasecmp($v, "sysadmin") === 0) {
                    $role = Contact::ROLE_ADMIN;
                } else if (strcasecmp($v, "none") !== 0) {
                    $this->warning_at("roles", "Unknown role “" . htmlspecialchars($v) . "”");
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
        if ($roles & Contact::ROLE_CHAIR) {
            $roles |= Contact::ROLE_PC;
        }
        return $roles;
    }

    /** @param int $roles
     * @param Contact $old_user */
    private function check_role_change($roles, $old_user) {
        if ((($this->no_deprivilege_self
              && $this->viewer
              && $this->viewer->conf === $this->conf
              && $this->viewer->contactId == $old_user->contactId)
             || $old_user->data("locked"))
            && $roles < $old_user->roles) {
            if ($old_user->data("locked")) {
                $this->warning_at("roles", "Ignoring request to drop privileges for locked account.");
            } else {
                $this->warning_at("roles", "Ignoring request to drop your privileges.");
            }
            $roles = $old_user->roles;
        }
        return $roles;
    }

    function check_invariants($cj) {
        if (isset($cj->bad_follow) && !empty($cj->bad_follow)) {
            $this->warning_at("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        }
        if (isset($cj->bad_topics) && !empty($cj->bad_topics)) {
            $this->warning_at("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
        }
    }

    static function check_pc_tag($base) {
        return !preg_match('/\A(?:any|all|none|pc|chair|admin|sysadmin)\z/i', $base);
    }


    static function crosscheck_main(UserStatus $us, Contact $user) {
        $cdbu = $user->contactdb_user();
        if ($us->gxt()->root() !== "main") {
            return;
        }
        if ($user->firstName === ""
            && $user->lastName === ""
            && ($user->contactId > 0 || !$cdbu || ($cdbu->firstName === "" && $cdbu->lastName === ""))) {
            $us->warning_at("firstName", "Please enter your name.");
            $us->warning_at("lastName", false);
        }
        if ($user->affiliation === ""
            && ($user->contactId > 0 || !$cdbu || $cdbu->affiliation === "")) {
            $us->warning_at("affiliation", "Please enter your affiliation (use “None” or “Unaffiliated” if you have none).");
        }
        if ($user->is_pc_member()) {
            if ($user->collaborators() === "") {
                $us->warning_at("collaborators", "Please enter your recent collaborators and other affiliations. This information can help detect conflicts of interest. Enter “None” if you have none.");
            }
            if ($user->conf->has_topics() && !$user->topic_interest_map()) {
                $us->warning_at("topics", "Please enter your topic interests. We use topic interests to improve the paper assignment process.");
            }
        }
    }


    /** @param object $cj
     * @param ?Contact $old_user */
    function save($cj, $old_user = null) {
        assert(is_object($cj));
        assert(!$old_user || (!$this->no_create && !$this->no_modify));
        $msgcount = $this->message_count();

        // normalize name, including email
        self::normalize_name($cj);

        // check id and email
        if (isset($cj->id)
            && $cj->id !== "new"
            && (!is_int($cj->id) || $cj->id <= 0)) {
            $this->error_at("id", "Format error [id]");
            return false;
        }
        if (isset($cj->email)
            && !is_string($cj->email)) {
            $this->error_at("email", "Format error [email]");
            return false;
        }

        // obtain old users in this conference and contactdb
        // - load by id if only id is set
        if (!$old_user && isset($cj->id) && is_int($cj->id)) {
            $old_user = $this->conf->user_by_id($cj->id);
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
            $old_cdb_user = $this->conf->contactdb_user_by_email($old_email);
        } else {
            $old_cdb_user = null;
        }

        // - load old_user; reset if old_user was in contactdb
        if (!$old_user || !$old_user->has_account_here()) {
            if ($old_email) {
                $old_user = $this->conf->user_by_email($old_email);
            } else {
                $old_user = null;
            }
        }

        // - check no_create and no_modify
        if ($this->no_create && !$old_user) {
            if (isset($cj->id) && $cj->id !== "new") {
                $this->error_at("id", "Refusing to create user with ID {$cj->id}.");
            } else {
                $this->error_at("email", "Refusing to create user with email " . htmlspecialchars($cj->email) . ".");
            }
            return false;
        } else if (($this->no_modify || ($cj->id ?? null) === "new") && $old_user) {
            if (isset($cj->id) && $cj->id !== "new") {
                $this->error_at("id", "Refusing to modify existing user with ID {$cj->id}.");
            } else {
                $this->error_at("email", "Refusing to modify existing user with email " . htmlspecialchars($cj->email) . ".");
            }
            return false;
        }

        $user = $old_user ?? $old_cdb_user;

        // normalize and check for errors
        if (!isset($cj->id)) {
            $cj->id = $old_user ? $old_user->contactId : "new";
        }
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->error_at("id", "Saving user with different ID");
            return false;
        }
        $this->normalize($cj, $user);
        $roles = $old_roles = $old_user ? $old_user->roles : 0;
        if (isset($cj->roles)) {
            $roles = $this->parse_roles($cj->roles, $roles);
            if ($old_user) {
                $roles = $this->check_role_change($roles, $old_user);
            }
        }
        if ($this->has_error_since($msgcount)) {
            return false;
        }
        // At this point, we will save a user.

        // create user
        $this->check_invariants($cj);
        if (($no_notify = $this->no_notify) === null) {
            $no_notify = !!$old_cdb_user;
        }
        $actor = $this->viewer->is_root_user() ? null : $this->viewer;
        if ($old_user) {
            $old_disabled = $user->disabled;
        } else {
            $user = Contact::create($this->conf, $actor, $cj, Contact::SAVE_ROLES, $roles);
            $cj->email = $user->email; // adopt contactdb’s spelling of email
            $old_disabled = true;
        }
        if (!$user) {
            return false;
        }
        $this->created = !$old_user;

        // prepare contact update
        assert(!isset($cj->email) || strcasecmp($cj->email, $user->email) === 0);
        $this->diffs = [];
        $this->set_user($user);
        $cdb_user = $user->ensure_contactdb_user(true);

        // Early properties
        $gx = $this->gxt();
        $gx->set_context(["args" => [$this, $user, $cj]]);
        foreach ($gx->members("", "save_early_callback") as $gj) {
            $gx->call_callback($gj->save_early_callback, $gj);
        }
        if (($user->prop_changed() || $this->created) && !$user->save_prop()) {
            return false;
        }

        // Roles
        if ($roles !== $old_roles) {
            $user->save_roles($roles, $actor);
            $this->diffs["roles"] = true;
        }

        // Contact DB (must precede password)
        if ($cdb_user && $cdb_user->prop_changed()) {
            $cdb_user->save_prop();
            $user->contactdb_user(true);
        }
        if ($roles !== $old_roles) {
            $user->contactdb_update();
        }

        // Main properties
        foreach ($gx->members("", "save_callback") as $gj) {
            $gx->call_callback($gj->save_callback, $gj);
        }

        // Clean up
        if ($this->viewer->contactId == $user->contactId) {
            $user->mark_activity();
        }
        if (!empty($this->diffs)) {
            $user->conf->log_for($this->viewer, $user, "Account edited: " . join(", ", array_keys($this->diffs)));
        }

        // Send creation mail
        if (!$user->activity_at && !$this->no_notify && !$user->is_disabled()) {
            $eff_old_roles = $old_disabled ? 0 : $old_roles;
            if (($roles & Contact::ROLE_PC)
                && !($eff_old_roles & Contact::ROLE_PC)) {
                $user->send_mail("@newaccount.pc");
            } else if (($roles & Contact::ROLE_ADMIN)
                       && !($eff_old_roles & Contact::ROLE_ADMIN)) {
                $user->send_mail("@newaccount.admin");
            }
        }

        return $user;
    }


    static function save_main(UserStatus $us, Contact $user, $cj) {
        // Profile properties
        $us->set_profile_prop($user, $cj, $us->only_update_empty($user));
        if (($cdbu = $user->contactdb_user())) {
            $us->set_profile_prop($cdbu, $cj, $us->only_update_empty($cdbu));
        }

        // Disabled
        if (isset($cj->disabled)) {
            $user->set_prop("disabled", $cj->disabled);
        }

        // Follow
        if (isset($cj->follow)
            && (!$us->no_update_profile || $user->defaultWatch == Contact::WATCH_REVIEW)) {
            $w = 0;
            $wmask = ($cj->follow->partial ?? false ? 0 : 0xFFFFFFFF);
            foreach (self::$watch_keywords as $k => $bit) {
                if (isset($cj->follow->$k)) {
                    $wmask |= $bit;
                    $w |= $cj->follow->$k ? $bit : 0;
                }
            }
            $w |= $user->defaultWatch & ~$wmask;
            if ($user->set_prop("defaultWatch", $w)) {
                $us->diffs["follow"] = true;
            }
        }

        // Tags
        if (isset($cj->tags) && $us->viewer->privChair) {
            $tags = [];
            foreach ($cj->tags as $t) {
                list($tag, $value) = Tagger::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $tags[$tag] = $tag . "#" . ($value ? : 0);
                }
            }
            ksort($tags);
            $t = empty($tags) ? null : " " . join(" ", $tags);
            if ($user->set_prop("contactTags", $t)) {
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

    private function set_profile_prop(Contact $user, $cj, $only_empty) {
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
            if (($v = $cj->$prop ?? null) !== null
                && $user->set_prop($prop, $v, $only_empty)) {
                $this->diffs[$diff] = true;
            }
        }
    }

    static function save_topics(UserStatus $us, Contact $user, $cj) {
        if (isset($cj->topics) && $user->conf->has_topics()) {
            $ti = $us->created ? [] : $user->topic_interest_map();
            $tv = [];
            $diff = false;
            foreach ($cj->topics as $k => $v) {
                if ($v) {
                    $tv[] = [$user->contactId, $k, $v];
                }
                if ($v !== ($ti[$k] ?? 0)) {
                    $diff = true;
                }
            }
            if ($diff || empty($tv)) {
                if (empty($tv)) {
                    foreach ($cj->topics as $k => $v) {
                        $tv[] = [$user->contactId, $k, 0];
                        break;
                    }
                }
                $user->conf->qe("delete from TopicInterest where contactId=?", $user->contactId);
                $user->conf->qe("insert into TopicInterest (contactId,topicId,interest) values ?v", $tv);
                $user->invalidate_topic_interests();
            }
            if ($diff) {
                $us->diffs["topics"] = true;
            }
        }
    }


    static function request_main(UserStatus $us, $cj, Qrequest $qreq, $uf) {
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
            if (empty($cj->roles)) {
                $cj->roles[] = "none";
            }
        }

        $follow = [];
        if ($qreq->has_watchreview) {
            $follow["reviews"] = !!$qreq->watchreview;
        }
        if ($qreq->has_watchallreviews
            && ($us->viewer->privChair || $us->user->isPC)) {
            $follow["allreviews"] = !!$qreq->watchallreviews;
        }
        if ($qreq->has_watchadminreviews
            && ($us->viewer->privChair || $us->user->isPC)) {
            $follow["adminreviews"] = !!$qreq->watchadminreviews;
        }
        if ($qreq->has_watchallfinal
            && ($us->viewer->privChair || $us->user->is_manager())) {
            $follow["allfinal"] = !!$qreq->watchallfinal;
        }
        if (!empty($follow)) {
            $follow["partial"] = true;
            $cj->follow = (object) $follow;
        }

        if (isset($qreq->contactTags) && $us->viewer->privChair) {
            $cj->tags = explode(" ", simplify_whitespace($qreq->contactTags));
        }

        if (isset($qreq->has_ti) && $us->viewer->isPC) {
            $topics = array();
            foreach ($us->conf->topic_set() as $id => $t) {
                if (isset($qreq["ti$id"]) && is_numeric($qreq["ti$id"])) {
                    $topics[$id] = (int) $qreq["ti$id"];
                }
            }
            $cj->topics = (object) $topics;
        }
    }


    static function request_security(UserStatus $us, $cj, Qrequest $qreq, $uf) {
        $us->_req_security = $us->_req_need_security = false;
        if ($us->allow_security()
            && isset($qreq->oldpassword)) {
            $info = $us->viewer->check_password_info(trim((string) $qreq->oldpassword));
            $us->_req_security = $info["ok"];
            $us->request_group("security");
            if (!$us->_req_security && $us->_req_need_security) {
                $us->error_at("oldpassword", "Incorrect current password. Changes to other security settings were ignored.");
            }
        }
    }

    function has_req_security() {
        $this->_req_need_security = true;
        return $this->_req_security;
    }

    static function request_new_password(UserStatus $us, $cj, Qrequest $qreq, $uf) {
        $pw = trim((string) $qreq->upassword);
        $pw2 = trim((string) $qreq->upassword2);
        $us->_req_passwords = [(string) $qreq->upassword, (string) $qreq->upassword2];
        if ($pw === "" && $pw2 === "") {
            // do nothing
        } else if ($us->has_req_security()
                   && $us->viewer->can_change_password($us->user)) {
            if ($pw !== $pw2) {
                $us->error_at("password", "Those passwords do not match.");
            } else if (strlen($pw) <= 5) {
                $us->error_at("password", "Password too short.");
            } else if (!Contact::valid_password($pw)) {
                $us->error_at("password", "Invalid new password.");
            } else {
                $cj->new_password = $pw;
            }
        }
    }

    static function save_security(UserStatus $us, Contact $user, $cj) {
        if (isset($cj->new_password)) {
            $user->change_password($cj->new_password);
            $us->diffs["password"] = true;
        }
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
        ["roles"],
        ["follow"],
        ["tags"],
        ["add_tags"],
        ["remove_tags"],
        ["disabled"]
    ];

    static function parse_csv_main(UserStatus $us, $cj, $line, $uf) {
        // set keys
        foreach (self::$csv_keys as $ks) {
            if (($v = trim((string) $line[$ks[0]])) !== "") {
                $cj->{$ks[0]} = $v;
            }
        }

        // clean up
        if (isset($line["address"])
            && ($v = trim($line["address"])) !== "") {
            $cj->address = explode("\n", cleannl($line["address"]));
            while (!empty($cj->address)
                   && $cj->address[count($cj->address) - 1] === "") {
                array_pop($cj->address);
            }
        }

        // topics
        if ($us->conf->has_topics()) {
            $topics = [];
            foreach ($line as $k => $v) {
                if (preg_match('/^topic[:\s]\s*(.*?)\s*$/i', $k, $m)) {
                    if (($tid = $us->conf->topic_abbrev_matcher()->find1($m[1]))) {
                        $v = trim($v);
                        $topics[$tid] = $v === "" ? 0 : $v;
                    } else {
                        $us->unknown_topics[$m[1]] = true;
                    }
                }
            }
            if (!empty($topics)) {
                $cj->change_topics = (object) $topics;
            }
        }
    }

    function add_csv_synonyms($csv) {
        foreach (self::$csv_keys as $ks) {
            for ($i = 1; !$csv->has_column($ks[0]) && isset($ks[$i]); ++$i) {
                $csv->add_synonym($ks[0], $ks[$i]);
            }
        }
    }

    function parse_csv_group($g, $cj, $line) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            if (($cb = $gj->parse_csv_callback ?? null)) {
                Conf::xt_resolve_require($gj);
                $cb($this, $cj, $line, $gj);
            }
        }
    }


    function render_field($field, $caption, $entry, $class = "f-i w-text") {
        echo '<div class="', $this->control_class($field, $class), '">',
            ($field ? Ht::label($caption, $field) : '<div class="f-c">' . $caption . '</div>'),
            $entry, "</div>";
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

    function global_profile_difference($key) {
        if (($cdbu = $this->global_self())) {
            $cdbprop = $cdbu->prop($key) ?? "";
            if (($this->user->prop($key) ?? "") !== $cdbprop) {
                if ($cdbprop !== "") {
                    return '<div class="f-h">Global profile has “' . htmlspecialchars($cdbprop) . '”</div>';
                } else {
                    return '<div class="f-h">Empty in global profile</div>';
                }
            }
        }
        return "";
    }

    static function render_main(UserStatus $us, Qrequest $qreq) {
        $user = $us->user;
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
            $us->render_field("uemail", "Email" . $actas,
                Ht::entry("uemail", $qreq->email ?? $user->email, ["class" => $email_class, "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => $user->email, "type" => "email"]));
        } else if (!$user->is_empty()) {
            $us->render_field(false, "Username" . $actas,
                htmlspecialchars($user->email));
            $us->render_field("preferredEmail", "Email",
                Ht::entry("preferredEmail", $qreq->preferredEmail ?? $user->preferredEmail, ["class" => "want-focus fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => $user->preferredEmail, "type" => "email"]));
        } else {
            $us->render_field("uemail", "Username",
                Ht::entry("newUsername", $qreq->email ?? $user->email, ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => $user->email]));
            $us->render_field("preferredEmail", "Email",
                      Ht::entry("preferredEmail", $qreq->preferredEmail ?? $user->preferredEmail, ["class" => "fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => $user->preferredEmail, "type" => "email"]));
        }

        echo '<div class="f-2col w-text">';
        $t = Ht::entry("firstName", $qreq->firstName ?? $user->firstName, ["size" => 24, "autocomplete" => $us->autocomplete("given-name"), "class" => "fullw", "id" => "firstName", "data-default-value" => $user->firstName]) . $us->global_profile_difference("firstName");
        $us->render_field("firstName", "First name (given name)", $t, "f-i");

        $t = Ht::entry("lastName", $qreq->lastName ?? $user->lastName, ["size" => 24, "autocomplete" => $us->autocomplete("family-name"), "class" => "fullw", "id" => "lastName", "data-default-value" => $user->lastName]) . $us->global_profile_difference("lastName");
        $us->render_field("lastName", "Last name (family name)", $t, "f-i");
        echo '</div>';

        $t = Ht::entry("affiliation", $qreq->affiliation ?? $user->affiliation, ["size" => 52, "autocomplete" => $us->autocomplete("organization"), "class" => "fullw", "id" => "affiliation", "data-default-value" => $user->affiliation]) . $us->global_profile_difference("affiliation");
        $us->render_field("affiliation", "Affiliation", $t);
    }

    static function render_current_password(UserStatus $us, Qrequest $qreq) {
        echo '<p class="w-text">Re-enter your current password to make changes to ',
            $us->self ? "" : "other users’ ",
            'security settings.</p>',
            '<div class="', $us->control_class("oldpassword", "f-i w-text"), '">',
            '<label for="oldpassword">',
            $us->self ? "Current password" : "Current password for " . htmlspecialchars($us->viewer->email),
            '</label>',
            Ht::entry("viewer_email", $us->viewer->email, ["autocomplete" => "username", "class" => "hidden ignore-diff", "readonly" => true]),
            Ht::password("oldpassword", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff", "id" => "oldpassword"]),
            '</div>';
    }

    static function render_new_password(UserStatus $us, Qrequest $qreq) {
        if (!$us->viewer->can_change_password($us->user)) {
            return;
        }
        echo '<h3 class="form-h">Change password</h3>';
        $pws = $us->_req_passwords ? : ["", ""];
        echo '<div class="', $us->control_class("password", "f-i w-text"), '">',
            '<label for="upassword">New password</label>',
            Ht::password("upassword", $pws[0], ["size" => 52, "autocomplete" => $us->autocomplete("new-password")]),
            '</div>',
            '<div class="', $us->control_class("password", "f-i w-text"), '">',
            '<label for="upassword2">Repeat new password</label>',
            Ht::password("upassword2", $pws[1], ["size" => 52, "autocomplete" => $us->autocomplete("new-password")]),
            '</div>';
    }

    static function render_country(UserStatus $us, Qrequest $qreq) {
        $t = Countries::selector("country", $qreq->country ?? $us->user->country(), ["id" => "country", "data-default-value" => $us->user->country(), "autocomplete" => $us->autocomplete("country")]) . $us->global_profile_difference("country");
        $us->render_field("country", "Country", $t);
    }

    static function render_follow(UserStatus $us, Qrequest $qreq) {
        echo '<h3 class="form-h">Email notification</h3>';
        $reqwatch = $iwatch = $us->user->defaultWatch;
        foreach (self::$watch_keywords as $kw => $bit) {
            if ($qreq["has_watch$kw"] || $qreq["watch$kw"]) {
                $reqwatch = ($reqwatch & ~$bit) | ($qreq["watch$kw"] ? $bit : 0);
            }
        }
        echo Ht::hidden("has_watchreview", 1);
        if ($us->user->is_empty() ? $us->viewer->privChair : $us->user->isPC) {
            echo Ht::hidden("has_watchallreviews", 1);
            echo "<table class=\"w-text\"><tr><td>Send mail for:</td><td><span class=\"sep\"></span></td>",
                "<td><label class=\"checki\"><span class=\"checkc\">",
                Ht::checkbox("watchreview", 1, ($reqwatch & Contact::WATCH_REVIEW) !== 0, ["data-default-checked" => ($iwatch & Contact::WATCH_REVIEW) !== 0]),
                "</span>", $us->conf->_("Reviews and comments on authored or reviewed submissions"), "</label>\n";
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                echo "<label class=\"checki\"><span class=\"checkc\">",
                    Ht::checkbox("watchadminreviews", 1, ($reqwatch & Contact::WATCH_REVIEW_MANAGED) !== 0, ["data-default-checked" => ($iwatch & Contact::WATCH_REVIEW_MANAGED) !== 0]),
                    "</span>", $us->conf->_("Reviews and comments on submissions you administer"),
                    Ht::hidden("has_watchadminreviews", 1), "</label>\n";
            }
            echo "<label class=\"checki\"><span class=\"checkc\">",
                Ht::checkbox("watchallreviews", 1, ($reqwatch & Contact::WATCH_REVIEW_ALL) !== 0, ["data-default-checked" => ($iwatch & Contact::WATCH_REVIEW_ALL) !== 0]),
                "</span>", $us->conf->_("Reviews and comments on <i>all</i> submissions"), "</label>\n";
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                echo "<label class=\"checki\"><span class=\"checkc\">",
                    Ht::checkbox("watchallfinal", 1, ($reqwatch & Contact::WATCH_FINAL_SUBMIT_ALL) !== 0, ["data-default-checked" => ($iwatch & Contact::WATCH_FINAL_SUBMIT_ALL) !== 0]),
                    "</span>", $us->conf->_("Updates to final versions for submissions you administer"),
                    Ht::hidden("has_watchallfinal", 1), "</label>\n";
            }
            echo "</td></tr></table>";
        } else {
            echo Ht::checkbox("watchreview", 1, ($reqwatch & Contact::WATCH_REVIEW) !== 0, ["data-default-checked" => ($iwatch & Contact::WATCH_REVIEW) !== 0]), "&nbsp;",
                Ht::label($us->conf->_("Send mail for new comments on authored or reviewed papers"));
        }
        echo "</div>\n";
    }

    static function render_roles(UserStatus $us, Qrequest $qreq) {
        if (!$us->viewer->privChair) {
            return;
        }
        echo '<h3 class="form-h">Roles</h3>', "\n",
            "<table class=\"w-text\"><tr><td class=\"nw\">\n";
        if (($us->user->roles & Contact::ROLE_CHAIR) !== 0) {
            $pcrole = $cpcrole = "chair";
        } else if (($us->user->roles & Contact::ROLE_PC) !== 0) {
            $pcrole = $cpcrole = "pc";
        } else {
            $pcrole = $cpcrole = "none";
        }
        if (isset($qreq->pctype) && in_array($qreq->pctype, ["chair", "pc", "none"])) {
            $pcrole = $qreq->pctype;
        }
        foreach (["chair" => "PC chair", "pc" => "PC member",
                  "none" => "Not on the PC"] as $k => $v) {
            echo '<label class="checki"><span class="checkc">',
                Ht::radio("pctype", $k, $pcrole === $k, ["class" => "js-role", "data-default-checked" => $cpcrole === $k]),
                '</span>', $v, "</label>\n";
        }
        Ht::stash_script('$(".js-role").on("change", hotcrp.profile_ui);$(function(){$(".js-role").first().trigger("change")})');

        echo "</td><td><span class=\"sep\"></span></td><td>";
        $is_ass = $cis_ass = ($us->user->roles & Contact::ROLE_ADMIN) !== 0;
        if (isset($qreq->pctype)) {
            $is_ass = isset($qreq->ass);
        }
        echo '<div class="checki"><label><span class="checkc">',
            Ht::checkbox("ass", 1, $is_ass, ["data-default-checked" => $cis_ass, "class" => "js-role"]),
            '</span>Sysadmin</label>',
            '<p class="f-h">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</p></div></td></tr></table>', "\n";
    }

    static function render_collaborators(UserStatus $us, Qrequest $qreq) {
        if (!$us->user->isPC && !$us->viewer->privChair) {
            return;
        }
        echo '<div class="form-g w-text fx2"><h3 class="', $us->control_class("collaborators", "form-h"), '">Collaborators and other affiliations</h3>', "\n",
            "<div>Please list potential conflicts of interest. We use this information when assigning reviews. ",
            $us->conf->_i("conflictdef"),
            " <p>Give one conflict per line, using parentheses for affiliations and institutions.<br>
        Examples: “Ping Yen Zhang (INRIA)”, “All (University College London)”</p></div>
        <textarea name=\"collaborators\" rows=\"5\" cols=\"80\" class=\"",
            $us->control_class("collaborators", "need-autogrow"),
            "\" data-default-value=\"", htmlspecialchars($us->user->collaborators()), "\">",
            htmlspecialchars($qreq->collaborators ?? $us->user->collaborators()),
            "</textarea></div>\n";
    }

    static function render_topics(UserStatus $us, Qrequest $qreq) {
        echo '<div id="topicinterest" class="form-g w-text fx1">',
            '<h3 class="form-h">Topic interests</h3>', "\n",
            '<p>Please indicate your interest in reviewing papers on these conference
topics. We use this information to help match papers to reviewers.</p>',
            Ht::hidden("has_ti", 1),
            '  <table class="table-striped"><thead>
    <tr><td></td><th class="ti_interest">Low</th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest">High</th></tr>
    <tr><td></td><th class="topic-2"></th><th class="topic-1"></th><th class="topic0"></th><th class="topic1"></th><th class="topic2"></th></tr></thead><tbody>', "\n";

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $tmap = $us->user->topic_interest_map();
        $ts = $us->conf->topic_set();
        foreach ($ts->group_list() as $tg) {
            for ($i = 1; $i !== count($tg); ++$i) {
                $tid = $tg[$i];
                $tic = "ti_topic";
                if ($i === 1) {
                    $n = $ts->unparse_name_html($tid);
                } else {
                    $n = htmlspecialchars($ts->subtopic_name($tid));
                    $tic .= " ti_subtopic";
                }
                echo "      <tr><td class=\"{$tic}\">{$n}</td>";
                $ival = $tmap[$tid] ?? 0;
                $reqval = isset($qreq["ti$tid"]) ? (int) $qreq["ti$tid"] : $ival;
                for ($j = -2; $j <= 2; ++$j) {
                    $ichecked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                    $reqchecked = $reqval >= $ibound[$j+2] && $reqval < $ibound[$j+3];
                    echo '<td class="ti_interest">', Ht::radio("ti$tid", $j, $reqchecked, ["class" => "uic js-range-click", "data-range-type" => "topicinterest$j", "data-default-checked" => $ichecked]), "</td>";
                }
                echo "</tr>\n";
            }
        }
        echo "    </tbody></table></div>\n";
    }

    static function render_tags(UserStatus $us, Qrequest $qreq) {
        $user = $us->user;
        $tagger = new Tagger($us->viewer);
        $itags = $tagger->unparse($user->viewable_tags($us->viewer));
        if (!$us->viewer->privChair && $itags === "") {
            return;
        }
        echo "<div class=\"form-g w-text fx2\"><h3 class=\"form-h\">Tags</h3>\n";
        if ($us->viewer->privChair) {
            echo '<div class="', $us->control_class("contactTags", "f-i"), '">',
                Ht::entry("contactTags", $qreq->contactTags ?? $itags, ["size" => 60, "data-default-value" => $itags]),
                "</div>
  <p class=\"f-h\">Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href=\"", $us->conf->hoturl("settings", "group=tags"), "\">tag colors</a> to highlight subgroups in review lists.</p>\n";
        } else {
            echo $itags, "<p class=\"f-h\">Tags represent PC subgroups and are set by administrators.</p>\n";
        }
        echo "</div>\n";
    }

    static function render_actions(UserStatus $us, Qrequest $qreq) {
        $buttons = [Ht::submit("save", $us->is_new_user() ? "Create account" : "Save changes", ["class" => "btn-primary"]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true])];

        if ($us->viewer->privChair
            && !$us->is_new_user()
            && !$us->is_viewer_user()
            && $us->gxt()->root === "main") {
            $tracks = self::user_paper_info($us->conf, $us->user->contactId);
            $args = ["class" => "ui"];
            if (!empty($tracks->soleAuthor)) {
                $args["class"] .= " js-cannot-delete-user";
                $args["data-sole-author"] = pluralx($tracks->soleAuthor, "submission") . " " . self::render_paper_link($us->conf, $tracks->soleAuthor);
            } else {
                $args["class"] .= " js-delete-user";
                $x = $y = array();
                if (!empty($tracks->author)) {
                    $x[] = "contact for " . pluralx($tracks->author, "submission") . " " . self::render_paper_link($us->conf, $tracks->author);
                    $y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
                }
                if (!empty($tracks->review)) {
                    $x[] = "reviewer for " . pluralx($tracks->review, "submission") . " " . self::render_paper_link($us->conf, $tracks->review);
                    $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
                }
                if (!empty($tracks->comment)) {
                    $x[] = "commenter for " . pluralx($tracks->comment, "submission") . " " . self::render_paper_link($us->conf, $tracks->comment);
                    $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
                }
                if (!empty($x)) {
                    $args["data-delete-info"] = "<p>This user is " . commajoin($x) . ". Deleting the user will also " . commajoin($y) . ".</p>";
                }
            }
            $buttons[] = "";
            $buttons[] = [Ht::button("Delete user", $args), "(admin only)"];
        }

        if ($us->self
            && $us->gxt()->root === "main") {
            array_push($buttons, "", Ht::submit("merge", "Merge with another account"));
        }

        echo Ht::actions($buttons, ["class" => "aab aabig mt-7"]);
    }



    static function render_bulk_entry(UserStatus $us, Qrequest $qreq) {
        echo Ht::textarea("bulkentry", $qreq->bulkentry, [
            "rows" => 1, "cols" => 80,
            "placeholder" => "Enter users one per line",
            "class" => "want-focus need-autogrow"
        ]);
        echo '<div class="g"><strong>OR</strong>  ',
            '<input type="file" name="bulk" size="30"></div>';
    }

    static function render_bulk_actions(UserStatus $us, Qrequest $qreq) {
        echo '<div class="aab aabig">',
            '<div class="aabut">', Ht::submit("savebulk", "Save accounts", ["class" => "btn-primary"]), '</div>',
            '</div>';
    }

    static function render_bulk_help(UserStatus $us) {
        echo '<section class="mt-7"><h3>Instructions</h3>',
            "<p>Enter or upload CSV data with header, such as:</p>\n",
            '<pre class="entryexample">
name,email,affiliation,roles
John Adams,john@earbox.org,UC Berkeley,pc
"Adams, John Quincy",quincy@whitehouse.gov
</pre>',
            "\n<p>Or just enter an email address per line.</p>";

        $rows = [];
        foreach ($us->gxt()->members("__bulk/help/f") as $gj) {
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
                '<table class="p table-striped"><thead>',
                '<tr><th class="pll">Field</th><th class="pll">Description</th></tr></thead>',
                '<tbody>', join('', $rows), '</tbody></table>';
        }

        echo '</section>';
    }

    static function render_bulk_help_topics(UserStatus $us) {
        if ($us->conf->has_topics()) {
            echo '<dl class="ctelt dd"><dt><code>topic: &lt;TOPIC NAME&gt;</code></dt>',
                '<dd>Topic interest: blank, “<code>low</code>”, “<code>medium-low</code>”, “<code>medium-high</code>”, or “<code>high</code>”, or numeric (-2 to 2)</dd></dl>';
        }
    }



    function set_context($options) {
        $this->gxt()->set_context($options);
    }

    function render_group($g) {
        $gx = $this->gxt();
        $gx->start_render();
        $ok = null;
        foreach ($gx->members(strtolower($g)) as $gj) {
            if (array_search("pc", Conf::xt_allow_list($gj)) === false) {
                $ok = $gx->render($gj);
            } else if ($this->user->isPC || $this->viewer->privChair) {
                echo '<div class="fx1">';
                $ok = $gx->render($gj);
                echo '</div>';
            }
            if ($ok === false) {
                break;
            }
        }
        $gx->end_render();
    }

    function request_group($name) {
        $gx = $this->gxt();
        foreach ($gx->members($name, "request_callback") as $gj) {
            if ($gx->allowed($gj->allow_request_if ?? null, $gj)) {
                $gx->call_callback($gj->request_callback, $gj);
            }
        }
    }
}
