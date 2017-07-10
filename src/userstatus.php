<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class UserStatus extends MessageSet {
    private $errf;
    public $send_email = null;
    private $no_deprivilege_self = false;

    static private $field_synonym_map = array("preferredEmail" => "preferred_email",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "addressLine2" => "address",
                       "zipCode" => "zip", "postal_code" => "zip",
                       "contactTags" => "tags", "uemail" => "email");

    static public $topic_interest_name_map = [
        "low" => -2,
        "mlow" => -1, "mediumlow" => -1, "medium-low" => -1, "medium_low" => -1,
        "medium" => 0, "none" => 0,
        "mhigh" => 2, "mediumhigh" => 2, "medium-high" => 2, "medium_high" => 2,
        "high" => 4
    ];

    function __construct($options = array()) {
        parent::__construct();
        foreach (array("send_email", "no_deprivilege_self") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        foreach (self::$field_synonym_map as $src => $dst)
            $this->translate_field($src, $dst);
    }

    static function unparse_roles_json($roles) {
        if ($roles) {
            $rj = (object) array();
            if ($roles & Contact::ROLE_CHAIR)
                $rj->chair = $rj->pc = true;
            if ($roles & Contact::ROLE_PC)
                $rj->pc = true;
            if ($roles & Contact::ROLE_ADMIN)
                $rj->sysadmin = true;
            return $rj;
        } else
            return null;
    }

    function user_to_json($user) {
        global $Conf, $Me;
        if (!$user)
            return null;

        $cj = (object) array();
        if ($user->contactId)
            $cj->id = $user->contactId;

        // keys that might come from user or contactdb
        $cdb_user = $user->contactdb_user();
        foreach (["email", "firstName", "lastName", "affiliation",
                  "collaborators", "country"] as $k)
            if ($user->$k !== null && $user->$k !== "")
                $cj->$k = $user->$k;
            else if ($cdb_user && $cdb_user->$k !== null && $cdb_user->$k !== "")
                $cj->$k = $cdb_user->$k;

        // keys that come from user
        foreach (["preferredEmail" => "preferred_email",
                  "voicePhoneNumber" => "phone"] as $uk => $jk)
            if ($user->$uk !== null && $user->$uk !== "")
                $cj->$jk = $user->$uk;

        if ($user->disabled)
            $cj->disabled = true;

        foreach (array("address", "city", "state", "zip", "country") as $k)
            if (($x = $user->data($k)))
                $cj->$k = $x;

        if ($user->roles)
            $cj->roles = self::unparse_roles_json($user->roles);

        if ($user->defaultWatch) {
            $cj->follow = (object) array();
            if ($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ON))
                $cj->follow->reviews = true;
            if ($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ALLON))
                $cj->follow->reviews = $cj->follow->allreviews = true;
            if ($user->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALLON))
                $cj->follow->allfinal = true;
        }

        if (($tags = $user->viewable_tags($Me))) {
            $tagger = new Tagger;
            $cj->tags = explode(" ", $tagger->unparse($tags));
        }

        if ($user->contactId
            && ($tm = $Conf->topic_map())) {
            $result = $Conf->qe_raw("select topicId, interest from TopicInterest where contactId=$user->contactId");
            $topics = (object) array();
            while (($row = edb_row($result))) {
                $k = $row[0];
                $topics->$k = (int) $row[1];
            }
            if (count(get_object_vars($topics)))
                $cj->topics = $topics;
        }

        return $cj;
    }

    private function make_keyed_object($x, $field) {
        if (is_string($x))
            $x = preg_split('/[\s,]+/', $x);
        $res = (object) array();
        if (is_array($x)) {
            foreach ($x as $v)
                if (!is_string($v))
                    $this->error_at($field, "Format error [$field]");
                else if ($v !== "")
                    $res->$v = true;
        } else if (is_object($x))
            $res = $x;
        else
            $this->error_at($field, "Format error [$field]");
        return $res;
    }

    static function normalize_name($cj) {
        $cj_user = isset($cj->user) ? Text::split_name($cj->user, true) : null;
        $cj_name = Text::analyze_name($cj);
        foreach (array("firstName", "lastName", "email") as $i => $k)
            if ($cj_name->$k !== "" && $cj_name->$k !== false)
                $cj->$k = $cj_name->$k;
            else if ($cj_user && $cj_user[$i])
                $cj->$k = $cj_user[$i];
    }

    private function make_tags_array($x, $key) {
        $t0 = array();
        if (is_string($x))
            $t0 = preg_split('/[\s,]+/', $x);
        else if (is_array($x))
            $t0 = $x;
        else if ($x !== null)
            $this->error_at($key, "Format error [$key]");
        $tagger = new Tagger;
        $t1 = array();
        foreach ($t0 as $t)
            if ($t !== "" && ($t = $tagger->check($t, Tagger::NOPRIVATE)))
                $t1[] = $t;
            else if ($t !== "")
                $this->error_at($key, $tagger->error_html);
        return $t1;
    }

    private function normalize($cj, $old_user) {
        // Errors prevent saving
        global $Conf, $Me, $Now;

        // Canonicalize keys
        foreach (array("preferredEmail" => "preferred_email",
                       "institution" => "affiliation",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "zipCode" => "zip", "postal_code" => "zip") as $x => $y)
            if (isset($cj->$x) && !isset($cj->$y))
                $cj->$y = $cj->$x;

        // Stringiness
        foreach (array("firstName", "lastName", "email", "preferred_email",
                       "affiliation", "phone", "old_password", "new_password",
                       "city", "state", "zip", "country") as $k)
            if (isset($cj->$k) && !is_string($cj->$k)) {
                $this->error_at($k, "Format error [$k]");
                unset($cj->$k);
            }

        // Email
        if (!get($cj, "email") && $old_user)
            $cj->email = $old_user->email;
        else if (!get($cj, "email"))
            $this->error_at("email", "Email is required.");
        else if (!$this->has_problem_at("email")
                 && !validate_email($cj->email)
                 && (!$old_user || $old_user->email !== $cj->email))
            $this->error_at("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");

        // ID
        if (get($cj, "id") === "new") {
            if (get($cj, "email") && $Conf->user_id_by_email($cj->email)) {
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use.");
                $this->error_at("email_inuse", false);
            }
        } else {
            if (!get($cj, "id") && $old_user && $old_user->contactId)
                $cj->id = $old_user->contactId;
            if (get($cj, "id") && !is_int($cj->id))
                $this->error_at("id", "Format error [id]");
            if ($old_user && get($cj, "email")
                && strtolower($old_user->email) !== strtolower($cj->email)
                && $Conf->user_id_by_email($cj->email))
                $this->error_at("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");
        }

        // Contactdb information
        if ($old_user && !$old_user->contactId) {
            if (!isset($cj->firstName) && !isset($cj->lastName)) {
                $cj->firstName = $old_user->firstName;
                $cj->lastName = $old_user->lastName;
            }
            if (!isset($cj->affiliation))
                $cj->affiliation = $old_user->affiliation;
            if (!isset($cj->collaborators))
                $cj->collaborators = $old_user->collaborators;
        }

        // Password changes
        if (isset($cj->new_password) && $old_user && $old_user->data("locked")) {
            unset($cj->new_password);
            $this->warning_at("password", "Ignoring request to change locked user’s password.");
        }

        // Preferred email
        if (get($cj, "preferred_email")
            && !$this->has_problem_at("preferred_email")
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email))
            $this->error_at("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");

        // Address
        $address = array();
        if (is_array(get($cj, "address")))
            $address = $cj->address;
        else {
            if (is_string(get($cj, "address")))
                $address[] = $cj->address;
            else if (get($cj, "address"))
                $this->error_at("address", "Format error [address]");
            if (is_string(get($cj, "address2")))
                $address[] = $cj->address2;
            else if (is_string(get($cj, "addressLine2")))
                $address[] = $cj->addressLine2;
            else if (get($cj, "address2") || get($cj, "addressLine2"))
                $this->error_at("address2", "Format error [address2]");
        }
        foreach ($address as $a)
            if (!is_string($a))
                $this->error_at("address", "Format error [address]");
        if (count($address))
            $cj->address = $address;

        // Collaborators
        if (is_array(get($cj, "collaborators"))) {
            foreach ($cj->collaborators as $c)
                if (!is_string($c))
                    $this->error_at("collaborators", "Format error [collaborators]");
            if (!$this->has_problem_at("collaborators"))
                $cj->collaborators = join("\n", $cj->collaborators);
        }
        if (get($cj, "collaborators") && !is_string($cj->collaborators)
            && !$this->has_problem_at("collaborators"))
            $this->error_at("collaborators", "Format error [collaborators]");

        // Disabled
        if (isset($cj->disabled)) {
            if (($x = friendly_boolean($cj->disabled)) !== null)
                $cj->disabled = $x;
            else
                $this->error_at("disabled", "Format error [disabled]");
        }

        // Follow
        if (isset($cj->follow)) {
            $cj->follow = $this->make_keyed_object($cj->follow, "follow");
            $cj->bad_follow = array();
            foreach ((array) $cj->follow as $k => $v)
                if ($v && $k !== "reviews" && $k !== "allreviews" && $k !== "allfinal")
                    $cj->bad_follow[] = $k;
        }

        // Roles
        if (isset($cj->roles)) {
            $cj->roles = $this->make_keyed_object($cj->roles, "roles");
            $cj->bad_roles = array();
            foreach ((array) $cj->roles as $k => $v)
                if ($v && $k !== "pc" && $k !== "chair" && $k !== "sysadmin"
                    && $k !== "no")
                    $cj->bad_roles[] = $k;
            if ($old_user
                && (($this->no_deprivilege_self && $Me && $Me->contactId == $old_user->contactId)
                    || $old_user->data("locked"))
                && Contact::parse_roles_json($cj->roles) < $old_user->roles) {
                unset($cj->roles);
                if ($old_user->data("locked"))
                    $this->warning_at("roles", "Ignoring request to drop privileges for locked account.");
                else
                    $this->warning_at("roles", "Ignoring request to drop your privileges.");
            }
        }

        // Tags
        if (isset($cj->tags))
            $cj->tags = $this->make_tags_array($cj->tags, "tags");
        if (isset($cj->add_tags) || isset($cj->remove_tags)) {
            // collect old tags as map by base
            if (!isset($cj->tags) && $old_user)
                $cj->tags = preg_split('/[\s,]+/', $old_user->contactTags);
            else if (!isset($cj->tags))
                $cj->tags = array();
            $old_tags = array();
            foreach ($cj->tags as $t)
                if ($t !== "") {
                    list($tag, $index) = TagInfo::unpack($t);
                    $old_tags[$tag] = $index;
                }
            // process removals, then additions
            foreach ($this->make_tags_array(get($cj, "remove_tags"), "remove_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
                if ($index === false || get($old_tags, $tag) == $index)
                    unset($old_tags[$tag]);
            }
            foreach ($this->make_tags_array(get($cj, "add_tags"), "add_tags") as $t) {
                list($tag, $index) = TagInfo::unpack($t);
                $old_tags[$tag] = $index;
            }
            // collect results
            $cj->tags = array();
            foreach ($old_tags as $tag => $index)
                $cj->tags[] = $tag . "#" . (float) $index;
        }

        // Topics
        if (isset($cj->topics)) {
            $topics = $this->make_keyed_object($cj->topics, "topics");
            $topic_map = $Conf->topic_map();
            $cj->topics = (object) array();
            $cj->bad_topics = array();
            foreach ((array) $topics as $k => $v) {
                if (get($topic_map, $k))
                    /* OK */;
                else if (($x = array_search($k, $topic_map, true)) !== false)
                    $k = $x;
                else {
                    $cj->bad_topics[] = $k;
                    continue;
                }
                if (is_bool($v))
                    $v = $v ? 2 : 0;
                else if (is_string($v) && isset(self::$topic_interest_name_map[$v]))
                    $v = self::$topic_interest_name_map[$v];
                else if (is_numeric($v))
                    $v = (int) $v;
                else {
                    $this->error_at("topics", "Topic interest format error");
                    continue;
                }
                $k = (string) $k;
                $cj->topics->$k = $v;
            }
        }
    }

    function check_invariants($cj) {
        global $Now;
        if (isset($cj->bad_follow) && count($cj->bad_follow))
            $this->warning_at("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        if (isset($cj->bad_topics) && count($cj->bad_topics))
            $this->warning_at("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
    }


    function save($cj, $old_user = null, $actor = null) {
        global $Conf, $Me, $Now;
        assert(is_object($cj));
        self::normalize_name($cj);

        if (!$old_user && is_int(get($cj, "id")) && $cj->id)
            $old_user = $Conf->user_by_id($cj->id);
        else if (!$old_user && is_string(get($cj, "email")) && $cj->email)
            $old_user = $Conf->user_by_email($cj->email);
        if (!get($cj, "id"))
            $cj->id = $old_user ? $old_user->contactId : "new";
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->error_at("id", "Saving user with different ID");
            return false;
        }

        $no_old_db_account = !$old_user || !$old_user->has_database_account();
        $old_cdb_user = null;
        if ($old_user && $old_user->has_email())
            $old_cdb_user = Contact::contactdb_find_by_email($old_user->email);
        else if (is_string(get($cj, "email")) && $cj->email)
            $old_cdb_user = Contact::contactdb_find_by_email($cj->email);
        $user = $old_user ? : $old_cdb_user;

        $this->normalize($cj, $user);
        if ($this->has_error)
            return false;
        $this->check_invariants($cj);

        $user = $user ? : new Contact;
        if (($send = $this->send_email) === null)
            $send = !$old_cdb_user;
        if ($user->save_json($cj, $actor, $send))
            return $user;
        else
            return false;
    }
}
