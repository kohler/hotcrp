<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class UserStatus {
    private $errf;
    private $errmsg;
    public $nerrors;
    public $send_email = null;
    private $no_deprivilege_self = false;
    private $allow_error = array();

    static private $field_synonym_map = array("preferredEmail" => "preferred_email",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "addressLine2" => "address",
                       "zipCode" => "zip", "postal_code" => "zip",
                       "contactTags" => "tags", "uemail" => "email");

    function __construct($options = array()) {
        foreach (array("send_email", "allow_error", "no_deprivilege_self") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        $this->clear();
    }

    function clear() {
        $this->errf = array();
        $this->errmsg = array();
        $this->nerrors = 0;
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
                  "collaborators"] as $k)
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

        if ($user->roles) {
            $cj->roles = (object) array();
            if ($user->roles & Contact::ROLE_CHAIR)
                $cj->roles->chair = $cj->roles->pc = true;
            else if ($user->roles & Contact::ROLE_PC)
                $cj->roles->pc = true;
            if ($user->roles & Contact::ROLE_ADMIN)
                $cj->roles->sysadmin = true;
        }

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

        if (($user->roles & Contact::ROLE_PC)
            && $user->contactId
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

    function set_error($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
        if (!$field
            || !$this->allow_error
            || array_search($field, $this->allow_error) === false)
            ++$this->nerrors;
        return false;
    }

    private function set_warning($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
    }

    private function make_keyed_object($x, $field) {
        if (is_string($x))
            $x = preg_split("/[\s,]+/", $x);
        $res = (object) array();
        if (is_array($x)) {
            foreach ($x as $v)
                if (!is_string($v))
                    $this->set_error($field, "Format error [$field]");
                else if ($v !== "")
                    $res->$v = true;
        } else if (is_object($x))
            $res = $x;
        else
            $this->set_error($field, "Format error [$field]");
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
            $t0 = preg_split("/[\s,]+/", $x);
        else if (is_array($x))
            $t0 = $x;
        else if ($x !== null)
            $this->set_error($key, "Format error [$key]");
        $tagger = new Tagger;
        $t1 = array();
        foreach ($t0 as $t)
            if ($t !== "" && ($t = $tagger->check($t, Tagger::NOPRIVATE)))
                $t1[] = $t;
            else if ($t !== "")
                $this->set_error($key, $tagger->error_html);
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
                $this->set_error($k, "Format error [$k]");
                unset($cj->$k);
            }

        // Email
        if (!get($cj, "email") && $old_user)
            $cj->email = $old_user->email;
        else if (!get($cj, "email"))
            $this->set_error("email", "Email is required.");
        else if (!isset($this->errf["email"])
                 && !validate_email($cj->email)
                 && (!$old_user || $old_user->email !== $cj->email))
            $this->set_error("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");

        // ID
        if (get($cj, "id") === "new") {
            if (get($cj, "email") && $Conf->user_id_by_email($cj->email)) {
                $this->set_error("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use.");
                $this->errf["email_inuse"] = true;
            }
        } else {
            if (!get($cj, "id") && $old_user && $old_user->contactId)
                $cj->id = $old_user->contactId;
            if (get($cj, "id") && !is_int($cj->id))
                $this->set_error("id", "Format error [id]");
            if ($old_user && get($cj, "email")
                && strtolower($old_user->email) !== strtolower($cj->email)
                && $Conf->user_id_by_email($cj->email))
                $this->set_error("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");
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
            $this->set_warning("password", "Ignoring request to change locked user’s password.");
        }

        // Preferred email
        if (get($cj, "preferred_email")
            && !isset($this->errf["preferred_email"])
            && !validate_email($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email))
            $this->set_error("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");

        // Address
        $address = array();
        if (is_array(get($cj, "address")))
            $address = $cj->address;
        else {
            if (is_string(get($cj, "address")))
                $address[] = $cj->address;
            else if (get($cj, "address"))
                $this->set_error("address", "Format error [address]");
            if (is_string(get($cj, "address2")))
                $address[] = $cj->address2;
            else if (is_string(get($cj, "addressLine2")))
                $address[] = $cj->addressLine2;
            else if (get($cj, "address2") || get($cj, "addressLine2"))
                $this->set_error("address2", "Format error [address2]");
        }
        foreach ($address as $a)
            if (!is_string($a))
                $this->set_error("address", "Format error [address]");
        if (count($address))
            $cj->address = $address;

        // Collaborators
        if (is_array(get($cj, "collaborators")))
            foreach ($cj->collaborators as $c)
                if (!is_string($c))
                    $this->set_error("collaborators", "Format error [collaborators]");
        if (is_array(get($cj, "collaborators")) && !isset($this->errf["collaborators"]))
            $cj->collaborators = join("\n", $cj->collaborators);
        if (get($cj, "collaborators") && !is_string($cj->collaborators)
            && !isset($this->errf["collaborators"]))
            $this->set_error("collaborators", "Format error [collaborators]");

        // Disabled
        if (isset($cj->disabled)) {
            if (($x = friendly_boolean($cj->disabled)) !== null)
                $cj->disabled = $x;
            else
                $this->set_error("disabled", "Format error [disabled]");
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
                    $this->set_warning("roles", "Ignoring request to drop privileges for locked account.");
                else
                    $this->set_warning("roles", "Ignoring request to drop your privileges.");
            }
        }

        // Tags
        if (isset($cj->tags))
            $cj->tags = $this->make_tags_array($cj->tags, "tags");
        if (isset($cj->add_tags) || isset($cj->remove_tags)) {
            // collect old tags as map by base
            if (!isset($cj->tags) && $old_user)
                $cj->tags = preg_split("/[\s,]+/", $old_user->contactTags);
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
                if ($v === "mlow" || $v === "medium-low")
                    $v = -1;
                else if ($v === true || $v === "mhigh" || $v === "medium-high")
                    $v = 2;
                else if ($v === "low")
                    $v = -2;
                else if ($v === "high")
                    $v = 4;
                else if ($v === "medium" || $v === "none" || $v === false)
                    $v = 0;
                else if (is_numeric($v))
                    $v = (int) $v;
                else {
                    $this->set_error("topics", "Topic interest format error");
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
            $this->set_warning("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        if (isset($cj->bad_topics) && count($cj->bad_topics))
            $this->set_warning("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
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
            $this->set_error("id", "Saving user with different ID");
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
        if ($this->nerrors)
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

    function error_messages() {
        return $this->errmsg;
    }

    function has_error($field) {
        if (($x = get(self::$field_synonym_map, $field)))
            $field = $x;
        return isset($this->errf[$field]);
    }
}
