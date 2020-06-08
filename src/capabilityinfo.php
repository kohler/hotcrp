<?php
// capabilityinfo.php -- HotCRP capability management
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CapabilityInfo {
    const RESETPASSWORD = 1;
    const CHANGEEMAIL = 2;
    const UPLOAD = 3;

    /** @var Conf */
    public $conf;
    /** @var bool */
    public $is_cdb;
    /** @var int */
    public $capabilityType;
    /** @var int */
    public $contactId;
    /** @var int */
    public $paperId;
    /** @var int */
    public $timeExpires;
    /** @var string */
    public $salt;
    /** @var string */
    public $data;
    /** @var ?Contact|false */
    private $_user = false;

    /** @param bool $is_cdb
     * @param ?int $capabilityType */
    function __construct(Conf $conf, $is_cdb, $capabilityType = null) {
        $this->conf = $conf;
        $this->is_cdb = $is_cdb;
        if ($capabilityType !== null) {
            $this->capabilityType = $capabilityType;
        }
    }

    /** return \mysqli */
    private function dblink() {
        return $this->is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
    }

    /** @param string $text
     * @param ?int $type
     * @param bool $allow_inactive
     * @return ?CapabilityInfo */
    static function find(Conf $conf, $text, $type, $allow_inactive = false) {
        if (strlen($text) < 5) {
            return null;
        } else if ($text[0] === "1" || $text[0] === "2") {
            $prefix = $text[0];
        } else if (str_starts_with($text, "U1")) {
            $prefix = "U1";
        } else {
            $prefix = "";
        }

        $iscdb = $prefix === "U1" || $prefix === "2";
        $dblink = $iscdb ? $conf->contactdb() : $conf->dblink;
        if (!$dblink) {
            return null;
        }

        $result = Dbl::qe($dblink, "select * from Capability where salt=?", $text);
        if ($result->num_rows === 0 && $prefix !== "") { // XXX backward compat
            $decoded = base64_decode(str_replace(["-a", "-b"], ["+", "/"], substr($text, strlen($prefix))));
            if ($decoded !== false && strlen($decoded) >= 5) {
                $result = Dbl::qe($dblink, "select * from Capability where salt=?", $decoded);
            }
        }

        $cap = self::fetch($result, $conf, $iscdb);
        Dbl::free($result);

        if ($cap
            && ($type === null || $cap->capabilityType === $type)
            && ($allow_inactive || $cap->is_active())) {
            return $cap;
        } else {
            return null;
        }
    }

    /** @param mysqli_result|Dbl_Result $result
     * @param bool $is_cdb
     * @return ?CapabilityInfo */
    static function fetch($result, Conf $conf, $is_cdb) {
        if (($cap = $result->fetch_object("CapabilityInfo", [$conf, $is_cdb]))) {
            $cap->conf = $conf;
            $cap->is_cdb = $is_cdb;
            $cap->capabilityType = (int) $cap->capabilityType;
            $cap->contactId = (int) $cap->contactId;
            $cap->paperId = (int) $cap->paperId;
            $cap->timeExpires = (int) $cap->timeExpires;
            $cap->_user = false;
        }
        return $cap;
    }

    /** @return bool */
    function is_active() {
        return $this->timeExpires === 0 || $this->timeExpires > time();
    }

    /** @return ?Contact */
    function user() {
        if ($this->_user === false) {
            if ($this->contactId <= 0) {
                $this->_user = null;
            } else if ($this->is_cdb) {
                $this->_user = $this->conf->contactdb_user_by_id($this->contactId);
            } else {
                $this->_user = $this->conf->user_by_id($this->contactId);
            }
        }
        return $this->_user;
    }

    /** @return $this */
    function set_user(Contact $user) {
        $user = $this->is_cdb ? $user->contactdb_user() : $user;
        assert($user && ($this->is_cdb ? $user->contactDbId > 0 : $user->contactId > 0));
        $this->contactId = $this->is_cdb ? $user->contactDbId : $user->contactId;
        $this->_user = $user;
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    function set_expires_after($seconds) {
        $this->timeExpires = time() + $seconds;
        return $this;
    }

    /** @return string|false */
    function create() {
        assert($this->capabilityType > 0);
        foreach (["contactId", "paperId", "timeExpires"] as $k) {
            $this->$k = $this->$k ?? 0;
        }
        $need_salt = !$this->salt;
        for ($tries = 0; $tries < ($need_salt ? 4 : 1); ++$tries) {
            if ($need_salt) {
                $salt = ($this->is_cdb ? "2" : "1") . base48_encode(random_bytes(16));
            } else {
                $salt = $this->salt;
            }
            $result = Dbl::qe($this->dblink(), "insert into Capability set capabilityType=?, contactId=?, paperId=?, timeExpires=?, salt=?, data=?", $this->capabilityType, $this->contactId, $this->paperId, $this->timeExpires, $salt, $this->data);
            if ($result->affected_rows > 0) {
                $this->salt = $salt;
                return $salt;
            }
        }
        return false;
    }

    /** @return bool */
    function update() {
        assert($this->capabilityType > 0 && !!$this->salt);
        $result = Dbl::qe($this->dblink(), "update Capability set timeExpires=?, data=? where salt=?", $this->timeExpires, $this->data, $this->salt);
        return !Dbl::is_error($result);
    }

    function delete() {
        Dbl::qe($this->dblink(), "delete from Capability where salt=?", $this->salt);
    }


    /** @param string $text
     * @param bool $add */
    static function set_default_cap_param($text, $add) {
        Conf::$hoturl_defaults = Conf::$hoturl_defaults ?? [];
        $cap = urldecode(Conf::$hoturl_defaults["cap"] ?? "");
        $a = array_diff(explode(" ", $cap), [$text, ""]);
        if ($add) {
            $a[] = $text;
        }
        if (empty($a)) {
            unset(Conf::$hoturl_defaults["cap"]);
        } else {
            Conf::$hoturl_defaults["cap"] = urlencode(join(" ", $a));
        }
    }
}
