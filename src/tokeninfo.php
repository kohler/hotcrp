<?php
// tokeninfo.php -- HotCRP token management
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TokenInfo {
    /** @var Conf */
    public $conf;
    /** @var ?bool */
    public $is_cdb;
    /** @var int */
    public $capabilityType;
    /** @var int */
    public $contactId;
    /** @var int */
    public $paperId;
    /** @var int */
    public $otherId;
    /** @var int */
    public $timeCreated;
    /** @var int */
    public $timeExpires;
    /** @var string */
    public $salt;
    /** @var string */
    public $data;
    /** @var ?Contact|false */
    private $_user = false;
    /** @var ?string */
    private $_token_pattern;

    const RESETPASSWORD = 1;
    const CHANGEEMAIL = 2;
    const UPLOAD = 3;

    /** @param ?int $capabilityType */
    function __construct(Conf $conf, $capabilityType = null) {
        $this->conf = $conf;
        if ($capabilityType !== null) {
            $this->capabilityType = $capabilityType;
        }
    }

    /** return \mysqli */
    private function dblink() {
        return $this->is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
    }

    /** @return $this */
    function set_contactdb() {
        assert(!$this->_user && !$this->contactId);
        $this->is_cdb = true;
        return $this;
    }

    /** @return $this */
    function set_user(Contact $user) {
        assert(($user->contactId > 0 && !$this->is_cdb) || $user->contactDbId > 0);
        $this->is_cdb = $user->contactId <= 0;
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

    /** @param string $pattern
     * @return $this */
    function set_token_pattern($pattern) {
        $this->_token_pattern = $pattern;
        return $this;
    }

    /** @param mysqli_result|Dbl_Result $result
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function fetch($result, Conf $conf, $is_cdb) {
        if (($cap = $result->fetch_object("TokenInfo", [$conf]))) {
            $cap->conf = $conf;
            $cap->is_cdb = $is_cdb;
            $cap->capabilityType = (int) $cap->capabilityType;
            $cap->contactId = (int) $cap->contactId;
            $cap->paperId = (int) $cap->paperId;
            $cap->otherId = (int) $cap->otherId;
            $cap->timeCreated = (int) $cap->timeCreated;
            $cap->timeExpires = (int) $cap->timeExpires;
        }
        return $cap;
    }

    /** @param string $token
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function find_any(Conf $conf, $token, $is_cdb = false) {
        if (strlen($token) >= 5
            && ($dblink = $is_cdb ? $conf->contactdb() : $conf->dblink)) {
            $result = Dbl::qe($dblink, "select * from Capability where salt=?", $token);
            $cap = self::fetch($result, $conf, $is_cdb);
            Dbl::free($result);
            return $cap;
        } else {
            return null;
        }
    }

    /** @param string $token
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function find_active(Conf $conf, $token, $is_cdb = false) {
        if (($cap = self::find_any($conf, $token, $is_cdb))
            && $cap->is_active()) {
            return $cap;
        } else {
            return null;
        }
    }

    /** @param string $text
     * @param ?int $type
     * @param bool $allow_inactive
     * @return ?TokenInfo
     * @deprecated */
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

    /** @return string */
    function instantiate_token() {
        return preg_replace_callback('/\[(\d+)\]/', function ($m) {
            return base48_encode(random_bytes(intval($m[1])));
        }, $this->_token_pattern);
    }

    /** @return string|false */
    function create() {
        assert($this->capabilityType > 0);
        $this->contactId = $this->contactId ?? 0;
        $this->paperId = $this->paperId ?? 0;
        $this->otherId = $this->otherId ?? 0;
        $this->timeCreated = $this->timeCreated ?? time();
        $this->timeExpires = $this->timeExpires ?? 0;
        $need_salt = !$this->salt;
        assert(!$need_salt || $this->_token_pattern);
        for ($tries = 0; $tries < ($need_salt ? 4 : 1); ++$tries) {
            $salt = $need_salt ? $this->instantiate_token() : $this->salt;
            $result = Dbl::qe($this->dblink(), "insert into Capability
                    set capabilityType=?, contactId=?, paperId=?, otherId=?,
                    timeCreated=?, timeExpires=?, salt=?, data=?",
                $this->capabilityType, $this->contactId, $this->paperId, $this->otherId,
                $this->timeCreated, $this->timeExpires, $salt, $this->data);
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
}
