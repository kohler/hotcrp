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
    public $timeUsed;
    /** @var int */
    public $timeInvalid;
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
    const AUTHORVIEW = 4;
    const REVIEWACCEPT = 5;
    const OAUTHSIGNIN = 6;

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

    /** @param bool $is_cdb
     * @return $this */
    function set_contactdb($is_cdb) {
        assert(!$this->_user && !$this->contactId);
        $this->is_cdb = $is_cdb;
        return $this;
    }

    /** @param string $pattern
     * @return $this */
    function set_token_pattern($pattern) {
        $this->_token_pattern = $pattern;
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
    function set_invalid_after($seconds) {
        $this->timeInvalid = Conf::$now + $seconds;
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    function set_expires_after($seconds) {
        $this->timeExpires = Conf::$now + $seconds;
        return $this;
    }

    /** @param mysqli_result|Dbl_Result $result
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function fetch($result, Conf $conf, $is_cdb = false) {
        if (($cap = $result->fetch_object("TokenInfo", [$conf]))) {
            $cap->conf = $conf;
            $cap->is_cdb = $is_cdb;
            $cap->capabilityType = (int) $cap->capabilityType;
            $cap->contactId = (int) $cap->contactId;
            $cap->paperId = (int) $cap->paperId;
            $cap->otherId = (int) $cap->otherId;
            $cap->timeCreated = (int) $cap->timeCreated;
            $cap->timeUsed = (int) $cap->timeUsed;
            $cap->timeInvalid = (int) $cap->timeInvalid;
            $cap->timeExpires = (int) $cap->timeExpires;
        }
        return $cap;
    }

    /** @param string $token
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function find($token, Conf $conf, $is_cdb = false) {
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

    /** @return bool */
    function is_active() {
        return ($this->timeExpires === 0 || $this->timeExpires > Conf::$now)
            && ($this->timeInvalid === 0 || $this->timeInvalid > Conf::$now);
    }

    /** @return ?Contact */
    function user() {
        if ($this->_user === false) {
            if ($this->contactId <= 0) {
                $this->_user = null;
            } else if ($this->is_cdb) {
                $this->_user = $this->conf->cdb_user_by_id($this->contactId);
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

    /** @return ?string */
    function create() {
        assert($this->capabilityType > 0);
        $this->contactId = $this->contactId ?? 0;
        $this->paperId = $this->paperId ?? 0;
        $this->otherId = $this->otherId ?? 0;
        $this->timeCreated = $this->timeCreated ?? Conf::$now;
        $this->timeUsed = $this->timeUsed ?? 0;
        $this->timeInvalid = $this->timeInvalid ?? 0;
        $this->timeExpires = $this->timeExpires ?? 0;
        $need_salt = !$this->salt;
        assert(!$need_salt || $this->_token_pattern);
        for ($tries = 0; $tries < ($need_salt ? 4 : 1); ++$tries) {
            $salt = $need_salt ? $this->instantiate_token() : $this->salt;
            $result = Dbl::qe($this->dblink(), "insert into Capability set
                    capabilityType=?, contactId=?, paperId=?, otherId=?,
                    timeCreated=?, timeUsed=?, timeInvalid=?, timeExpires=?,
                    salt=?, data=?",
                $this->capabilityType, $this->contactId, $this->paperId, $this->otherId,
                $this->timeCreated, $this->timeUsed, $this->timeInvalid, $this->timeExpires,
                $salt, $this->data);
            if ($result->affected_rows > 0) {
                $this->salt = $salt;
                return $salt;
            }
        }
        return null;
    }

    /** @return bool */
    function update() {
        assert($this->capabilityType > 0 && !!$this->salt);
        $result = Dbl::qe($this->dblink(), "update Capability set
                timeUsed=?, timeInvalid=?, timeExpires=?, data=?
                where salt=?",
            $this->timeUsed, $this->timeInvalid, $this->timeExpires, $this->data,
            $this->salt);
        return !Dbl::is_error($result);
    }

    function delete() {
        Dbl::qe($this->dblink(), "delete from Capability where salt=?", $this->salt);
    }
}
