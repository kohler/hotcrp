<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class UserStatus extends MessageSet {
    public $conf;
    public $user;
    public $viewer;
    public $self;
    public $no_notify = null;
    private $no_deprivilege_self = false;
    private $no_update_profile = false;
    public $unknown_topics = null;
    public $diffs;
    private $_gxt;
    private $_req_security;
    private $_req_need_security;
    private $_req_passwords;

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
        foreach (["no_notify", "no_deprivilege_self", "no_update_profile"] as $k) {
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
    function global_user() { // XXX
        return $this->global_self();
    }
    function actor() {
        return $this->viewer->is_site_contact ? null : $this->viewer;
    }

    function gxt() {
        if ($this->_gxt === null) {
            $this->_gxt = new GroupedExtensions($this->viewer, ["etc/profilegroups.json"], $this->conf->opt("profileGroups"));
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
    static function xt_allow_security($xt, Contact $user, Conf $conf) {
        $us = $conf->xt_context->arg(0);
        assert($us instanceof UserStatus);
        return $us->allow_security();
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
        if ($this->self)
            return $what;
        else if ($what === "email" || $what === "username" || $what === "current-password")
            return "nope";
        else
            return "off";
    }

    static function unparse_roles_json($roles) {
        if ($roles) {
            $rj = (object) array();
            if ($roles & Contact::ROLE_CHAIR) {
                $rj->chair = $rj->pc = true;
            }
            if ($roles & Contact::ROLE_PC) {
                $rj->pc = true;
            }
            if ($roles & Contact::ROLE_ADMIN) {
                $rj->sysadmin = true;
            }
            return $rj;
        } else {
            return null;
        }
    }

    static function unparse_json_main(UserStatus $us, $cj, $args) {
        // keys that might come from user or contactdb
        $user = $us->user;
        $cdb_user = $user->contactdb_user();
        foreach (["email", "firstName", "lastName", "affiliation",
                  "collaborators", "country"] as $k) {
            if ($user->$k !== null && $user->$k !== "") {
                $cj->$k = $user->$k;
            } else if ($cdb_user && $cdb_user->$k !== null && $cdb_user->$k !== "") {
                $cj->$k = $cdb_user->$k;
            }
        }

        // keys that come from user
        foreach (["preferredEmail" => "preferred_email",
                  "phone" => "phone"] as $uk => $jk) {
            if ($user->$uk !== null && $user->$uk !== "") {
                $cj->$jk = $user->$uk;
            }
        }

        if ($user->disabled) {
            $cj->disabled = true;
        }

        foreach (["address", "city", "state", "zip", "country"] as $k) {
            if (($x = $user->data($k))) {
                $cj->$k = $x;
            }
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

    static function normalize_name($cj) {
        $cj_user = isset($cj->user) ? Text::split_name($cj->user, true) : null;
        $cj_name = Text::analyze_name($cj);
        foreach (array("firstName", "lastName", "email") as $i => $k) {
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
        if (!get($cj, "email") && $old_user) {
            $cj->email = $old_user->email;
        } else if (!get($cj, "email")) {
            $this->error_at("email", "Email is required.");
        } else if (!$this->has_problem_at("email")
                   && !validate_email($cj->email)
                   && (!$old_user || $old_user->email !== $cj->email)) {
            $this->error_at("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");
        }

        // ID
        if (get($cj, "id") === "new") {
            if (get($cj, "email") && $this->conf->user_id_by_email($cj->email)) {
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use.");
                $this->error_at("email_inuse", false);
            }
        } else {
            if (!get($cj, "id") && $old_user && $old_user->contactId) {
                $cj->id = $old_user->contactId;
            }
            if (get($cj, "id") && !is_int($cj->id)) {
                $this->error_at("id", "Format error [id]");
            }
            if ($old_user && get($cj, "email")
                && strtolower($old_user->email) !== strtolower($cj->email)
                && $this->conf->user_id_by_email($cj->email)) {
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");
            }
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
        if (get($cj, "preferred_email")
            && !$this->has_problem_at("preferred_email")
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email)) {
            $this->error_at("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");
        }

        // Address
        $address = null;
        if (is_array(get($cj, "address"))) {
            $address = $cj->address;
        } else {
            if (is_string(get($cj, "address"))) {
                $address[] = $cj->address;
            } else if (get($cj, "address")) {
                $this->error_at("address", "Format error [address]");
            }
            if (is_string(get($cj, "address2"))) {
                $address[] = $cj->address2;
            } else if (is_string(get($cj, "addressLine2"))) {
                $address[] = $cj->addressLine2;
            } else if (get($cj, "address2") || get($cj, "addressLine2")) {
                $this->error_at("address2", "Format error [address2]");
            }
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
            while (is_string($address[count($address) - 1])
                   && $address[count($address) - 1] === "") {
                array_pop($address);
            }
            $cj->address = $address;
        }

        // Collaborators
        if (is_array(get($cj, "collaborators"))) {
            foreach ($cj->collaborators as $c) {
                if (!is_string($c)) {
                    $this->error_at("collaborators", "Format error [collaborators]");
                }
            }
            if (!$this->has_problem_at("collaborators")) {
                $cj->collaborators = join("\n", $cj->collaborators);
            }
        }
        if (get($cj, "collaborators")
            && !$this->has_problem_at("collaborators")
            && !is_string($cj->collaborators)) {
            $this->error_at("collaborators", "Format error [collaborators]");
        }
        if (get($cj, "collaborators")
            && !$this->has_problem_at("collaborators")) {
            $old_collab = rtrim(cleannl($cj->collaborators));
            $collab = AuthorMatcher::fix_collaborators($old_collab);
            if ($collab !== $old_collab) {
                $this->warning_at("collaborators", "Collaborators changed to follow our required format. You may want to look them over.");
            }
            $cj->collaborators = $collab;
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
                if ($v && !in_array($k, ["reviews", "allreviews", "managedreviews", "adminreviews", "allfinal", "none", "partial"])) {
                    $cj->bad_follow[] = $k;
                }
            }
        }

        // Roles
        if (isset($cj->roles) && $cj->roles !== "") {
            $cj->roles = $this->make_keyed_object($cj->roles, "roles", true);
            $cj->bad_roles = array();
            foreach ((array) $cj->roles as $k => $v) {
                if ($v && !in_array($k, ["pc", "chair", "sysadmin", "no", "none"])) {
                    $cj->bad_roles[] = $k;
                }
            }
            if ($old_user
                && (($this->no_deprivilege_self
                     && $this->viewer
                     && $this->viewer->conf === $this->conf
                     && $this->viewer->contactId == $old_user->contactId)
                    || $old_user->data("locked"))
                && self::parse_roles_json($cj->roles) < $old_user->roles) {
                unset($cj->roles);
                if ($old_user->data("locked")) {
                    $this->warning_at("roles", "Ignoring request to drop privileges for locked account.");
                } else {
                    $this->warning_at("roles", "Ignoring request to drop your privileges.");
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
                    list($tag, $index) = TagInfo::unpack($t);
                    $old_tags[strtolower($tag)] = [$tag, $index];
                }
            }
            // process removals, then additions
            foreach ($this->make_tags_array(get($cj, "remove_tags"), "remove_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
                if ($index !== false) {
                    $ti = get($old_tags, strtolower($tag));
                    if (!$ti || $ti[1] != $index)
                        continue;
                }
                unset($old_tags[strtolower($tag)]);
            }
            foreach ($this->make_tags_array(get($cj, "add_tags"), "add_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
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
                    $this->error_at("topics", "Topic interest format error");
                    continue;
                }
                $topics[$k] = $v;
            }
            $cj->topics = (object) $topics;
        }
    }

    function check_invariants($cj) {
        if (isset($cj->bad_follow) && !empty($cj->bad_follow)) {
            $this->warning_at("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        }
        if (isset($cj->bad_roles) && !empty($cj->bad_roles)) {
            $this->warning_at("roles", "Unknown roles ignored (" . htmlspecialchars(commajoin($cj->bad_roles)) . ").");
        }
        if (isset($cj->bad_topics) && !empty($cj->bad_topics)) {
            $this->warning_at("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
        }
    }

    static private function parse_roles_json($j) {
        $roles = 0;
        if (isset($j->pc) && $j->pc) {
            $roles |= Contact::ROLE_PC;
        }
        if (isset($j->chair) && $j->chair) {
            $roles |= Contact::ROLE_CHAIR | Contact::ROLE_PC;
        }
        if (isset($j->sysadmin) && $j->sysadmin) {
            $roles |= Contact::ROLE_ADMIN;
        }
        return $roles;
    }

    static function check_pc_tag($base) {
        return !preg_match('{\A(?:any|all|none|pc|chair|admin)\z}i', $base);
    }

    private function maybe_assign($user, $cj, $cu, $fields, $userval = true) {
        $field = is_array($fields) ? $fields[0] : $fields;
        if ($userval === true) {
            $userval = $user->$field;
        }
        $cjk = is_array($fields) ? $fields[1] : $fields;
        if (isset($cj->$cjk)
            && (!$this->no_update_profile || (string) $userval === "")
            && $user->save_assign_field($field, $cj->$cjk, $cu)) {
            $this->diffs[is_array($fields) ? $fields[2] : $fields] = true;
        }
    }


    function save($cj, $old_user = null) {
        global $Now;
        assert(is_object($cj));
        $nerrors = $this->nerrors();

        // normalize name, including email
        self::normalize_name($cj);

        // obtain old users in this conference and contactdb
        // - load by id if only id is set
        if (!$old_user && is_int(get($cj, "id")) && $cj->id) {
            $old_user = $this->conf->user_by_id($cj->id);
        }

        // - obtain email
        if ($old_user && $old_user->has_email()) {
            $old_email = $old_user->email;
        } else if (is_string(get($cj, "email")) && $cj->email !== "") {
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

        $user = $old_user ? : $old_cdb_user;

        // normalize and check for errors
        if (!get($cj, "id")) {
            $cj->id = $old_user ? $old_user->contactId : "new";
        }
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->error_at("id", "Saving user with different ID");
            return false;
        }
        $this->normalize($cj, $user);
        if ($this->nerrors() > $nerrors) {
            return false;
        }
        // At this point, we will save a user.

        $roles = $old_user ? $old_user->roles : 0;
        if (isset($cj->roles)) {
            $roles = self::parse_roles_json($cj->roles);
        }

        // create user
        $this->check_invariants($cj);
        if (($no_notify = $this->no_notify) === null) {
            $no_notify = !!$old_cdb_user;
        }
        $actor = $this->viewer->is_site_contact ? null : $this->viewer;
        if ($old_user) {
            $old_roles = $user->roles;
            $old_disabled = $user->disabled ? 1 : 0;
        } else {
            $user = Contact::create($this->conf, $actor, $cj, Contact::SAVE_ROLES, $roles);
            $cj->email = $user->email; // adopt contactdb’s spelling of email
            $old_roles = 0;
            $old_disabled = 1;
        }
        if (!$user) {
            return false;
        }

        // prepare contact update
        assert(!isset($cj->email) || strcasecmp($cj->email, $user->email) === 0);
        $cu = new Contact_Update(false);

        // check whether this user is changing themselves
        $changing_other = false;
        if ($user->conf->contactdb()
            && (strcasecmp($user->email, $this->viewer->email) !== 0
                || $this->viewer->is_actas_user()
                || $this->viewer->is_site_contact)) { // XXX want way in script to modify all
            $changing_other = true;
        }
        $this->diffs = [];

        // Main fields
        if (!$this->no_update_profile
            || ($user->firstName === "" && $user->lastName === "")) {
            if (isset($cj->firstName)
                && $user->save_assign_field("firstName", $cj->firstName, $cu)) {
                $this->diffs["name"] = true;
            }
            if (isset($cj->lastName)
                && $user->save_assign_field("lastName", $cj->lastName, $cu)) {
                $this->diffs["name"] = true;
            }
            $user->save_assign_field("unaccentedName", Text::unaccented_name($user->firstName, $user->lastName), $cu);
        }

        if (isset($cj->email)
            && (!$this->no_update_profile || !$old_user)
            && $user->save_assign_field("email", $cj->email, $cu)) {
            $this->diffs["email"] = true;
        }

        $this->maybe_assign($user, $cj, $cu, "affiliation");
        $this->maybe_assign($user, $cj, $cu, "collaborators", $user->collaborators());
        $this->maybe_assign($user, $cj, $cu, "country", $user->country());
        $this->maybe_assign($user, $cj, $cu, "phone");
        $this->maybe_assign($user, $cj, $cu, ["gender", "gender", "demographics"]);
        $this->maybe_assign($user, $cj, $cu, ["birthday", "birthday", "demographics"]);
        $this->maybe_assign($user, $cj, $cu, ["preferredEmail", "preferred_email", "preferred_email"]);

        // Disabled
        $disabled = $old_disabled;
        if (isset($cj->disabled)) {
            $disabled = $cj->disabled ? 1 : 0;
        }
        if ($disabled !== $old_disabled || !$user->contactId) {
            $cu->qv["disabled"] = $user->disabled = $disabled;
            $this->diffs["disabled"] = true;
        }

        // Data
        $old_datastr = $user->data_str();
        $data = get($cj, "data", (object) array());
        foreach (["address", "city", "state", "zip"] as $k) {
            if (isset($cj->$k)
                && (!$this->no_update_profile || (string) get($data, $k) === "")
                && ($x = $cj->$k)) {
                while (is_array($x) && $x[count($x) - 1] === "") {
                    array_pop($x);
                }
                $data->$k = $x ? : null;
            }
        }
        $user->merge_data($data);
        $datastr = $user->data_str();
        if ($datastr !== $old_datastr) {
            $cu->qv["data"] = $datastr;
            $this->diffs["address"] = true;
        }

        // Changes to the above fields also change the updateTime
        // (changes to the below fields do not).
        if (!empty($cu->qv)) {
            $user->save_assign_field("updateTime", $Now, $cu);
        }

        // Follow
        if (isset($cj->follow)
            && (!$this->no_update_profile || $user->defaultWatch == Contact::WATCH_REVIEW)) {
            $w = 0;
            $wmask = ($cj->follow->partial ?? false ? 0 : 0xFFFFFFFF);
            foreach (["reviews" => Contact::WATCH_REVIEW,
                      "allreviews" => Contact::WATCH_REVIEW_ALL,
                      "adminreviews" => Contact::WATCH_REVIEW_MANAGED,
                      "managedreviews" => Contact::WATCH_REVIEW_MANAGED,
                      "allfinal" => Contact::WATCH_FINAL_SUBMIT_ALL]
                     as $k => $bit) {
                if (isset($cj->follow->$k)) {
                    $wmask |= $bit;
                    $w |= $cj->follow->$k ? $bit : 0;
                }
            }
            $w |= $user->defaultWatch & ~$wmask;
            if ($user->save_assign_field("defaultWatch", $w, $cu)) {
                $this->diffs["follow"] = true;
            }
        }

        // Tags
        if (isset($cj->tags) && $this->viewer->privChair) {
            $tags = array();
            foreach ($cj->tags as $t) {
                list($tag, $value) = TagInfo::unpack($t);
                if (self::check_pc_tag($tag)) {
                    $tags[$tag] = $tag . "#" . ($value ? : 0);
                }
            }
            ksort($tags);
            $t = empty($tags) ? null : " " . join(" ", $tags);
            if ($user->save_assign_field("contactTags", $t, $cu)) {
                $this->diffs["tags"] = true;
            }
        }

        // Initial save
        if (!empty($cu->qv)) { // always true if $inserting
            $q = "update ContactInfo set "
                . join("=?, ", array_keys($cu->qv)) . "=?"
                . " where contactId={$user->contactId}";
            if (!($result = $user->conf->qe_apply($q, array_values($cu->qv)))) {
                return false;
            }
            Dbl::free($result);
        }

        // Topics
        if (isset($cj->topics) && $user->conf->has_topics()) {
            $ti = $old_user ? $user->topic_interest_map() : [];
            $tv = [];
            $diff = false;
            foreach ($cj->topics as $k => $v) {
                if ($v) {
                    $tv[] = [$user->contactId, $k, $v];
                }
                if ($v !== get($ti, $k, 0)) {
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
            }
            if ($diff) {
                $this->diffs["topics"] = true;
            }
        }

        // Roles
        if ($roles !== $old_roles) {
            $user->save_roles($roles, $actor);
            $this->diffs["roles"] = true;
        }

        // Contact DB (must precede password)
        $cdb = $user->conf->contactdb();
        if ($cdb
            && (!empty($cu->cdb_qf) || $roles !== $old_roles)) {
            $user->contactdb_update($cu->cdb_qf, $changing_other);
        }

        // Password
        if (isset($cj->new_password)) {
            $user->change_password($cj->new_password);
            $this->diffs["password"] = true;
        }

        // Extensions
        $this->set_user($user);
        $gx = $this->gxt();
        $gx->set_context(["args" => [$this, $user, $cj]]);
        foreach ($gx->members("", "save_callback") as $gj) {
            if ($gx->allowed($gj->allow_save_if ?? null, $gj)) {
                $gx->call_callback($gj->save_callback, $gj);
            }
        }

        // Clean up
        $user->save_cleanup($cu, $this);
        if ($this->viewer->contactId == $user->contactId) {
            $user->mark_activity();
        }
        if (!empty($this->diffs)) {
            $user->conf->log_for($this->viewer, $user, "Account edited: " . join(", ", array_keys($this->diffs)));
        }

        // Send creation mail
        if (($roles & Contact::ROLE_PC)
            && !$user->is_disabled()
            && (!($old_roles & Contact::ROLE_PC)
                || $old_disabled)
            && !$user->activity_at
            && !$this->no_notify) {
            $user->send_mail("@newaccount.pc");
        }

        return $user;
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
            $v = $qreq[$k];
            if ($v !== null && ($cj->id !== "new" || trim($v) !== ""))
                $cj->$k = $v;
        }

        // PC components
        if (isset($qreq->pctype) && $us->viewer->privChair) {
            $cj->roles = (object) array();
            $pctype = $qreq->pctype;
            if ($pctype === "chair") {
                $cj->roles->chair = $cj->roles->pc = true;
            }
            if ($pctype === "pc") {
                $cj->roles->pc = true;
            }
            if ($qreq->ass) {
                $cj->roles->sysadmin = true;
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
            for ($i = 1; $i < count($ks) && !$csv->has_column($ks[0]); ++$i) {
                $csv->add_synonym($ks[0], $ks[$i]);
            }
        }
    }

    function parse_csv_group($g, $cj, $line) {
        foreach ($this->gxt()->members(strtolower($g)) as $gj) {
            if (isset($gj->parse_csv_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->parse_csv_callback, $this, $cj, $line, $gj);
            }
        }
    }


    function render_field($field, $caption, $entry, $class = "f-i w-text") {
        echo '<div class="', $this->control_class($field, $class), '">',
            ($field ? Ht::label($caption, $field) : '<div class="f-c">' . $caption . '</div>'),
            $entry, "</div>";
    }

    static function pcrole_text($cj) {
        if (isset($cj->roles)) {
            if (isset($cj->roles->chair) && $cj->roles->chair) {
                return "chair";
            } else if (isset($cj->roles->pc) && $cj->roles->pc) {
                return "pc";
            }
        }
        return "no";
    }

    function global_profile_difference($cj, $key) {
        if (($cdb_user = $this->global_self())
            && (string) get($cj, $key) !== (string) $cdb_user->$key) {
            if ((string) $cdb_user->$key !== "") {
                return '<div class="f-h">Global profile gives “' . htmlspecialchars($cdb_user->$key) . '”</div>';
            } else {
                return '<div class="f-h">Empty in global profile</div>';
            }
        } else {
            return "";
        }
    }

    static function render_main(UserStatus $us, $cj, $reqj, $uf) {
        $actas = "";
        if ($us->user !== $us->viewer
            && $us->user->email
            && $us->viewer->privChair) {
            $actas = '&nbsp;' . actas_link($us->user);
        }

        if (!$us->conf->external_login()) {
            $email_class = "want-focus fullw";
            if ($us->user->can_lookup_user()) {
                $email_class .= " uii js-email-populate";
            }
            $us->render_field("uemail", "Email" . $actas,
                Ht::entry("uemail", get_s($reqj, "email"), ["class" => $email_class, "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => get_s($cj, "email"), "type" => "email"]));
        } else if (!$us->user->is_empty()) {
            $us->render_field(false, "Username" . $actas,
                htmlspecialchars(get_s($cj, "email")));
            $us->render_field("preferredEmail", "Email",
                Ht::entry("preferredEmail", get_s($reqj, "preferred_email"), ["class" => "want-focus fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => get_s($cj, "preferred_email"), "type" => "email"]));
        } else {
            $us->render_field("uemail", "Username",
                Ht::entry("newUsername", get_s($reqj, "email"), ["class" => "want-focus fullw", "size" => 52, "id" => "uemail", "autocomplete" => $us->autocomplete("username"), "data-default-value" => get_s($cj, "email")]));
            $us->render_field("preferredEmail", "Email",
                      Ht::entry("preferredEmail", get_s($reqj, "preferred_email"), ["class" => "fullw", "size" => 52, "id" => "preferredEmail", "autocomplete" => $us->autocomplete("email"), "data-default-value" => get_s($cj, "preferred_email"), "type" => "email"]));
        }

        echo '<div class="f-2col w-text">';
        $t = Ht::entry("firstName", get_s($reqj, "firstName"), ["size" => 24, "autocomplete" => $us->autocomplete("given-name"), "class" => "fullw", "id" => "firstName", "data-default-value" => get_s($cj, "firstName")]) . $us->global_profile_difference($cj, "firstName");
        $us->render_field("firstName", "First name (given name)", $t, "f-i");

        $t = Ht::entry("lastName", get_s($reqj, "lastName"), ["size" => 24, "autocomplete" => $us->autocomplete("family-name"), "class" => "fullw", "id" => "lastName", "data-default-value" => get_s($cj, "lastName")]) . $us->global_profile_difference($cj, "lastName");
        $us->render_field("lastName", "Last name (family name)", $t, "f-i");
        echo '</div>';

        $t = Ht::entry("affiliation", get_s($reqj, "affiliation"), ["size" => 52, "autocomplete" => $us->autocomplete("organization"), "class" => "fullw", "id" => "affiliation", "data-default-value" => get_s($cj, "affiliation")]) . $us->global_profile_difference($cj, "affiliation");
        $us->render_field("affiliation", "Affiliation", $t);
    }

    static function render_current_password(UserStatus $us, $cj, $reqj, $uf) {
        echo '<p class="w-text">You must enter your current password to make changes to ',
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

    static function render_new_password(UserStatus $us, $cj, $reqj, $uf) {
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

    static function render_demographics(UserStatus $us, $cj, $reqj, $uf) {
        $t = Countries::selector("country", get_s($reqj, "country"), ["id" => "country", "data-default-value" => get_s($cj, "country"), "autocomplete" => $us->autocomplete("country")]) . $us->global_profile_difference($cj, "country");
        $us->render_field("country", "Country", $t);
    }

    static function render_follow(UserStatus $us, $cj, $reqj, $uf) {
        echo '<h3 class="form-h">Email notification</h3>';
        $follow = isset($reqj->follow) ? $reqj->follow : (object) [];
        $cfollow = isset($cj->follow) ? $cj->follow : (object) [];
        echo Ht::hidden("has_watchreview", 1);
        if ($us->user->is_empty() ? $us->viewer->privChair : $us->user->isPC) {
            echo Ht::hidden("has_watchallreviews", 1);
            echo "<table class=\"w-text\"><tr><td>Send mail for:</td><td><span class=\"sep\"></span></td>",
                "<td><label class=\"checki\"><span class=\"checkc\">",
                Ht::checkbox("watchreview", 1, !!get($follow, "reviews"), ["data-default-checked" => !!get($cfollow, "reviews")]),
                "</span>", $us->conf->_("Reviews and comments on authored or reviewed submissions"), "</label>\n";
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                echo "<label class=\"checki\"><span class=\"checkc\">",
                    Ht::checkbox("watchadminreviews", 1, !!get($follow, "adminreviews"), ["data-default-checked" => !!get($cfollow, "adminreviews")]),
                    "</span>", $us->conf->_("Reviews and comments on submissions you administer"),
                    Ht::hidden("has_watchadminreviews", 1), "</label>\n";
            }
            echo "<label class=\"checki\"><span class=\"checkc\">",
                Ht::checkbox("watchallreviews", 1, !!get($follow, "allreviews"), ["data-default-checked" => !!get($cfollow, "allreviews")]),
                "</span>", $us->conf->_("Reviews and comments on <i>all</i> submissions"), "</label>\n";
            if (!$us->user->is_empty() && $us->user->is_manager()) {
                echo "<label class=\"checki\"><span class=\"checkc\">",
                    Ht::checkbox("watchallfinal", 1, !!get($follow, "allfinal"), ["data-default-checked" => !!get($cfollow, "allfinal")]),
                    "</span>", $us->conf->_("Updates to final versions for submissions you administer"),
                    Ht::hidden("has_watchallfinal", 1), "</label>\n";
            }
            echo "</td></tr></table>";
        } else {
            echo Ht::checkbox("watchreview", 1, !!get($follow, "reviews"), ["data-default-checked" => !!get($cfollow, "reviews")]), "&nbsp;",
                Ht::label($us->conf->_("Send mail for new comments on authored or reviewed papers"));
        }
        echo "</div>\n";
    }

    static function render_roles(UserStatus $us, $cj, $reqj, $uf) {
        if (!$us->viewer->privChair) {
            return;
        }
        echo '<h3 class="form-h">Roles</h3>', "\n",
          "<table class=\"w-text\"><tr><td class=\"nw\">\n";
        $pcrole = self::pcrole_text($reqj);
        $cpcrole = self::pcrole_text($cj);
        foreach (["chair" => "PC chair", "pc" => "PC member",
                  "no" => "Not on the PC"] as $k => $v) {
            echo '<label class="checki"><span class="checkc">',
                Ht::radio("pctype", $k, $pcrole === $k, ["class" => "js-role keep-focus", "data-default-checked" => $cpcrole === $k]),
                '</span>', $v, "</label>\n";
        }
        Ht::stash_script('$(".js-role").on("change", profile_ui);$(function(){$(".js-role").first().trigger("change")})');

        echo "</td><td><span class=\"sep\"></span></td><td>";
        $is_ass = isset($reqj->roles) && get($reqj->roles, "sysadmin");
        $cis_ass = isset($cj->roles) && get($cj->roles, "sysadmin");
        echo '<div class="checki"><label><span class="checkc">',
            Ht::checkbox("ass", 1, $is_ass, ["data-default-checked" => $cis_ass, "class" => "js-role keep-focus"]),
            '</span>Sysadmin</label>',
            '<p class="f-h">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</p></div></td></tr></table>', "\n";
    }

    static function render_collaborators(UserStatus $us, $cj, $reqj, $uf) {
        if (!$us->user->isPC && !$us->viewer->privChair) {
            return;
        }
        echo '<div class="form-g w-text fx2"><h3 class="', $us->control_class("collaborators", "form-h"), '">Collaborators and other affiliations</h3>', "\n",
            "<div>Please list potential conflicts of interest. We use this information when assigning reviews. ",
            $us->conf->_i("conflictdef"),
            " <p>Give one conflict per line, using parentheses for affiliations and institutions.<br>
        Examples: “Ping Yen Zhang (INRIA)”, “All (University College London)”</p></div>
        <textarea name=\"collaborators\" rows=\"5\" cols=\"80\" class=\"",
            $us->control_class("collaborators", "need-autogrow"), "\">",
            htmlspecialchars(get_s($cj, "collaborators")), "</textarea></div>\n";
    }

    static function render_topics(UserStatus $us, $cj, $reqj, $uf) {
        echo '<div id="topicinterest" class="form-g w-text fx1">',
            '<h3 class="form-h">Topic interests</h3>', "\n",
            '<p>Please indicate your interest in reviewing papers on these conference
topics. We use this information to help match papers to reviewers.</p>',
            Ht::hidden("has_ti", 1),
            '  <table class="table-striped"><thead>
    <tr><td></td><th class="ti_interest">Low</th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest"></th><th class="ti_interest">High</th></tr>
    <tr><td></td><th class="topic-2"></th><th class="topic-1"></th><th class="topic0"></th><th class="topic1"></th><th class="topic2"></th></tr></thead><tbody>', "\n";

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $reqj_topics = (array) get($reqj, "topics", []);
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
                $ival = (float) get($reqj_topics, $tid);
                for ($j = -2; $j <= 2; ++$j) {
                    $checked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                    echo '<td class="ti_interest">', Ht::radio("ti$tid", $j, $checked, ["class" => "uic js-range-click", "data-range-type" => "topicinterest$j"]), "</td>";
                }
                echo "</tr>\n";
            }
        }
        echo "    </tbody></table></div>\n";
    }

    static function render_tags(UserStatus $us, $cj, $reqj, $uf) {
        if ((!$us->user->isPC || empty($reqj->tags))
            && !$us->viewer->privChair) {
            return;
        }
        $tags = isset($reqj->tags) && is_array($reqj->tags) ? $reqj->tags : [];
        echo "<div class=\"form-g w-text fx2\"><h3 class=\"form-h\">Tags</h3>\n";
        if ($us->viewer->privChair) {
            echo '<div class="', $us->control_class("contactTags", "f-i"), '">',
                Ht::entry("contactTags", join(" ", $tags), ["size" => 60]),
                "</div>
  <p class=\"f-h\">Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href=\"", hoturl("settings", "group=tags"), "\">tag colors</a> to highlight subgroups in review lists.</p>\n";
        } else {
            echo join(" ", $tags), "<div class=\"hint\">Tags represent PC subgroups and are set by administrators.</div>\n";
        }
        echo "</div>\n";
    }

    static function render_actions(UserStatus $us, $cj, $reqj, $uf) {
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
