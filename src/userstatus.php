<?php
// userstatus.php -- HotCRP helpers for reading/storing users as JSON
// HotCRP is Copyright (c) 2008-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class UserStatus {

    private $errf;
    private $errmsg;
    public $nerrors;
    private $no_email = false;
    private $allow_error = array();

    static private $field_synonym_map = array("preferredEmail" => "preferred_email",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "addressLine2" => "address",
                       "zipCode" => "zip", "postal_code" => "zip",
                       "contactTags" => "tags", "uemail" => "email");

    function __construct($options = array()) {
        foreach (array("no_email", "allow_error") as $k)
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
        global $Conf;
        if (!$user)
            return null;

        $cj = (object) array();
        if ($user->contactId)
            $cj->id = $user->contactId;
        foreach (array("email" => "email", "preferredEmail" => "preferred_email",
                       "firstName" => "firstName", "lastName" => "lastName",
                       "affiliation" => "affiliation", "collaborators" => "collaborators",
                       "voicePhoneNumber" => "phone") as $uk => $jk)
            if ($user->$uk !== null && $user->$uk !== "")
                $cj->$jk = $user->$uk;
        if ($user->disabled)
            $cj->disabled = true;

        $user->load_address();
        $address = array();
        if (@$user->addressLine1 !== null && @$user->addressLine1 !== "")
            $address[] = $user->addressLine1;
        if (@$user->addressLine2 !== null && @$user->addressLine2 !== "")
            $address[] = $user->addressLine2;
        if (count($address))
            $cj->address = $address;
        foreach (array("city" => "city", "state" => "state", "zipCode" => "zip",
                       "country" => "country") as $uk => $jk)
            if (@$user->$uk !== null && $user->$uk !== "")
                $cj->$jk = $user->$uk;

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
            if ($user->defaultWatch & (WATCH_COMMENT | WATCH_ALLCOMMENTS))
                $cj->follow->reviews = true;
            if ($user->defaultWatch & WATCH_ALLCOMMENTS)
                $cj->follow->allreviews = true;
            if ($user->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALL))
                $cj->follow->allfinal = true;
        }

        if (($tags = $user->all_contact_tags()))
            $cj->tags = explode(" ", trim($tags));

        if (($user->roles & Contact::ROLE_PC)
            && $user->contactId
            && ($tm = $Conf->topic_map())
            && count($tm)) {
            $result = $Conf->qe("select topicId, " . $Conf->query_topic_interest() . " from TopicInterest where contactId=$user->contactId");
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

    function normalize($cj, $old_user) {
        // Errors prevent saving
        global $Conf, $Now;

        // Regularize names
        $name = Text::analyze_name($cj);
        foreach (array("firstName", "lastName", "email") as $k)
            if (isset($name->$k))
                $cj->$k = $name->$k;

        // Canonicalize keys
        foreach (array("preferredEmail" => "preferred_email",
                       "voicePhoneNumber" => "phone",
                       "addressLine1" => "address",
                       "zipCode" => "zip", "postal_code" => "zip") as $x => $y)
            if (isset($cj->$x) && !isset($cj->$y))
                $cj->$y = $cj->$x;

        // Stringiness
        foreach (array("firstName", "lastName", "email", "preferred_email",
                       "affiliation", "phone", "password", "city", "state",
                       "zip", "country") as $k)
            if (isset($cj->$k) && !is_string($cj->$k)) {
                $this->set_error($k, "Format error [$k]");
                unset($cj->$k);
            }

        // Email
        if (!@$cj->email)
            $this->set_error("email", "Email is required.");
        else if (!@$this->errf["email"]
                 && !validateEmail($cj->email)
                 && (!$old_user || $old_user->email !== $cj->email))
            $this->set_error("email", "Invalid email address “" . htmlspecialchars($cj->email) . "”.");

        // ID
        if (@$cj->id === "new") {
            if (@$cj->email && Contact::id_by_email($cj->email))
                $this->set_error("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use for another account.");
        } else {
            if (!@$cj->id && $old_user && $old_user->contactId)
                $cj->id = $old_user->contactId;
            if (@$cj->id && !is_int($cj->id))
                $this->errf("id", "Format error [id]");
            if ($old_user && @$cj->email
                && strtolower($old_user->email) !== strtolower($cj->email)
                && Contact::id_by_email($cj->email))
                $this->set_error("email", "Email address “" . htmlspecialchars($cj->email) . "” is already in use for another account. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");
        }

        // Preferred email
        if (@$cj->preferred_email
            && !@$this->errf["preferred_email"]
            && !validateEmail($cj->preferred_email)
            && (!$old_user || $old_user->preferredEmail !== $cj->preferred_email))
            $this->set_error("preferred_email", "Invalid email address “" . htmlspecialchars($cj->preferred_email) . "”");

        // Address
        $address = array();
        if (is_array(@$cj->address))
            $address = $cj->address;
        else {
            if (is_string(@$cj->address))
                $address[] = $cj->address;
            else if (@$cj->address)
                $this->set_error("address", "Format error [address]");
            if (is_string(@$cj->address2))
                $address[] = $cj->address2;
            else if (is_string(@$cj->addressLine2))
                $address[] = $cj->addressLine2;
            else if (@$cj->address2 || @$cj->addressLine2)
                $this->set_error("address2", "Format error [address2]");
        }
        foreach ($address as $a)
            if (!is_string($a))
                $this->set_error("address", "Format error [address]");
        if (count($address))
            $cj->address = $address;

        // Collaborators
        if (is_array(@$cj->collaborators))
            foreach ($cj->collaborators as $c)
                if (!is_string($c))
                    $this->set_error("collaborators", "Format error [collaborators]");
        if (is_array(@$cj->collaborators) && !@$this->errf["collaborators"])
            $cj->collaborators = join("\n", $cj->collaborators);
        if (@$cj->collaborators && !is_string($cj->collaborators)
            && !@$this->errf["collaborators"])
            $this->set_error("collaborators", "Format error [collaborators]");

        // Follow
        if (@$cj->follow !== null) {
            $cj->follow = $this->make_keyed_object($cj->follow, "follow");
            $cj->bad_follow = array();
            foreach ((array) $cj->follow as $k => $v)
                if ($v && $k !== "reviews" && $k !== "allreviews" && $k !== "allfinal")
                    $cj->bad_follow[] = $k;
        }

        // Roles
        if (@$cj->roles !== null) {
            $cj->roles = $this->make_keyed_object($cj->roles, "roles");
            $cj->bad_roles = array();
            foreach ((array) $cj->roles as $k => $v)
                if ($v && $k !== "pc" && $k !== "chair" && $k !== "sysadmin"
                    && $k !== "no")
                    $cj->bad_roles[] = $k;
        }

        // Tags
        if (@$cj->tags !== null) {
            $cj->tags = $this->make_keyed_object($cj->tags, "tags");
            $cj->bad_tags = array();
            $tagger = new Tagger;
            foreach ((array) $cj->tags as $k => $v)
                if ($v && !$tagger->check($k, Tagger::NOPRIVATE | Tagger::NOVALUE | Tagger::NOCHAIR))
                    $this->set_error("tags", $tagger->error_html);
        }

        // Topics
        if (@$cj->topics !== null) {
            $topics = $this->make_keyed_object($cj->topics, "topics");
            $topic_map = $Conf->topic_map();
            $cj->topics = (object) array();
            $cj->bad_topics = array();
            foreach ((array) $topics as $k => $v) {
                if (@$topic_map[$k])
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
        if (@count($cj->bad_follow))
            $this->set_warning("follow", "Unknown follow types ignored (" . htmlspecialchars(commajoin($cj->bad_follow)) . ").");
        if (@count($cj->bad_topics))
            $this->set_warning("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($cj->bad_topics)) . ").");
    }


    function save($cj, $old_user = null, $actor = null) {
        global $Conf, $Now;

        if (is_int(@$cj->id) && $cj->id && !$old_user)
            $old_user = Contact::find_by_id($cj->id);
        else if (is_string(@$cj->email) && $cj->email && !$old_user)
            $old_user = Contact::find_by_email($cj->email);
        if (!@$cj->id)
            $cj->id = $old_user ? $old_user->contactId : "new";
        if ($cj->id !== "new" && $old_user && $cj->id != $old_user->contactId) {
            $this->set_error("id", "Saving user with different ID");
            return false;
        }

        $this->normalize($cj, $old_user);
        if ($this->nerrors)
            return false;
        $this->check_invariants($cj);

        $user = $old_user ? : new Contact;
        $old_roles = $user->roles;
        $old_email = $user->email;

        $aupapers = null;
        if (strtolower($cj->email) !== @strtolower($old_email))
            $aupapers = Contact::email_authored_papers($cj->email, $cj);

        foreach (array("firstName", "lastName", "email", "affiliation",
                       "collaborators", "password", "city", "state",
                       "country") as $k)
            if (isset($cj->$k))
                $user->$k = $cj->$k;
        if (isset($cj->phone))
            $user->voicePhoneNumber = $cj->phone;
        if (!isset($cj->password) && isset($cj->password_plaintext))
            $user->change_password($cj->password_plaintext);
        if (!$user->password && !Contact::external_login())
            $user->password = $user->password_plaintext =
                Contact::random_password();
        if (isset($cj->zip))
            $user->zipCode = $cj->zip;
        if (isset($cj->address)) {
            $user->addressLine1 = defval($cj->address, 0, "");
            $user->addressLine2 = defval($cj->address, 1, "");
        }
        if (isset($cj->follow)) {
            $user->defaultWatch = 0;
            if (@$cj->follow->reviews)
                $user->defaultWatch |= WATCH_COMMENT;
            if (@$cj->follow->allreviews)
                $user->defaultWatch |= WATCH_ALLCOMMENTS;
            if (@$cj->follow->allfinal)
                $user->defaultWatch |= (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALL);
        }
        if (isset($cj->tags)) {
            $tags = array();
            foreach ($cj->tags as $t => $v)
                if ($v && strtolower($t) !== "pc")
                    $tags[$t] = true;
            if (count($tags)) {
                ksort($tags);
                $user->contactTags = " " . join(" ", array_keys($tags)) . " ";
            } else
                $user->contactTags = "";
        }
        if (!$user->save())
            return false;

        // Topics
        if (isset($cj->topics)) {
            $qf = array();
            foreach ($cj->topics as $k => $v)
                $qf[] = "($user->contactId,$k,$v)";
            $Conf->qe("delete from TopicInterest where contactId=$user->contactId", "while updating topic interests");
            if (count($qf))
                $Conf->qe("insert into TopicInterest (contactId,topicId,interest) values " . join(",", $qf), "while updating topic interests");
        }

        // Roles
        $roles = 0;
        if (isset($cj->roles)) {
            if (@$cj->roles->pc)
                $roles |= Contact::ROLE_PC;
            if (@$cj->roles->chair)
                $roles |= Contact::ROLE_CHAIR | Contact::ROLE_PC;
            if (@$cj->roles->sysadmin)
                $roles |= Contact::ROLE_ADMIN;
            if ($roles !== $old_roles)
                $user->save_roles($roles, $actor);
        }

        // Update authorship
        if ($aupapers)
            $user->save_authored_papers($aupapers);

        // Beware PC cache
        if (($roles | $old_roles) & Contact::ROLE_PCLIKE)
            $Conf->invalidateCaches(array("pc" => 1));

        if (!$old_user || !$old_user->is_known_user())
            $user->mark_create(!$this->no_email);
        return $user;
    }

    function error_messages() {
        return $this->errmsg;
    }

    function has_error($field) {
        if (@($x = self::$field_synonym_map[$field]))
            $field = $x;
        return isset($this->errf[$field]);
    }

}
