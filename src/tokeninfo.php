<?php
// tokeninfo.php -- HotCRP token management
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class TokenInfo {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?bool
     * @readonly */
    public $is_cdb;
    /** @var int
     * @readonly */
    public $capabilityType;
    /** @var int */
    public $contactId;
    /** @var int */
    public $paperId;
    /** @var int */
    public $reviewId;
    /** @var int */
    public $timeCreated;
    /** @var int
     * @readonly */
    public $timeUsed;
    /** @var int
     * @readonly */
    public $timeInvalid;
    /** @var int
     * @readonly */
    public $timeExpires;
    /** @var string
     * @readonly */
    public $salt;
    /** @var ?string */
    public $inputData;
    /** @var ?string
     * @readonly */
    public $data;
    /** @var ?string */
    public $outputData;

    /** @var ?string */
    public $email;
    /** @var ?Contact|false */
    private $_user = false;
    /** @var ?string */
    private $_token_pattern;
    /** @var ?object */
    private $_jinputData;
    /** @var ?object */
    private $_jdata;
    /** @var ?object */
    private $_joutputData;
    /** @var int */
    private $_changes;

    const RESETPASSWORD = 1;
    const CHANGEEMAIL = 2;
    const UPLOAD = 3;
    const AUTHORVIEW = 4;
    const REVIEWACCEPT = 5;
    const OAUTHSIGNIN = 6;
    const BEARER = 7;
    const JOB = 8;
    const OAUTHCODE = 9;

    const CHF_TIMES = 1;
    const CHF_DATA = 2;
    const CHF_OUTPUT_DATA = 4;

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
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_contactdb($is_cdb) {
        assert($this->_user === false && !$this->contactId);
        $this->is_cdb = $is_cdb;
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_user(Contact $user) {
        assert(($user->contactId > 0 && !$this->is_cdb) || $user->contactDbId > 0);
        $this->is_cdb = $user->contactId <= 0;
        $this->contactId = $this->is_cdb ? $user->contactDbId : $user->contactId;
        $this->_user = $user;
        return $this;
    }

    /** @param string $pattern
     * @return $this */
    function set_token_pattern($pattern) {
        $this->_token_pattern = $pattern;
        return $this;
    }

    /** @param string $salt
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_salt($salt) {
        $this->salt = $salt;
        return $this;
    }

    /** @param int $t
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_invalid_at($t) {
        if ($t !== $this->timeInvalid) {
            $this->timeInvalid = $t;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    function set_invalid_after($seconds) {
        return $this->set_invalid_at(Conf::$now + $seconds);
    }

    /** @return $this */
    function set_invalid() {
        if ($this->timeInvalid <= 0 || $this->timeInvalid >= Conf::$now) {
            $this->set_invalid_at(Conf::$now - 1);
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    function extend_validity($seconds) {
        if ($this->timeInvalid > 0 && $this->timeInvalid < Conf::$now + $seconds) {
            $this->set_invalid_at(Conf::$now + $seconds);
        }
        return $this;
    }

    /** @param int $t
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_expires_at($t) {
        if ($t !== $this->timeExpires) {
            $this->timeExpires = $t;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    function set_expires_after($seconds) {
        return $this->set_expires_at(Conf::$now + $seconds);
    }

    /** @param int $seconds
     * @return $this */
    function extend_expiry($seconds) {
        if ($this->timeExpires > 0 && $this->timeExpires < Conf::$now + $seconds) {
            $this->set_expires_at(Conf::$now + $seconds);
        }
        return $this;
    }

    /** @param null|string|associative-array|object $data
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_input($data, $value = null) {
        json_encode_object_change($this->inputData, $this->_jinputData, $data, $value, func_num_args());
        return $this;
    }

    /** @param null|string|associative-array|object $data
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function assign_data($data) {
        if ($data !== null && !is_string($data)) {
            $data = json_encode_db($data);
        }
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->data = $data;
        $this->_jdata = null;
        $this->_changes &= ~self::CHF_DATA;
        return $this;
    }

    /** @param mysqli_result|Dbl_Result $result
     * @param bool $is_cdb
     * @return ?TokenInfo
     * @suppress PhanAccessReadOnlyProperty */
    static function fetch($result, Conf $conf, $is_cdb = false) {
        if (($cap = $result->fetch_object("TokenInfo", [$conf]))) {
            $cap->conf = $conf;
            $cap->is_cdb = $is_cdb;
            $cap->capabilityType = (int) $cap->capabilityType;
            $cap->contactId = (int) $cap->contactId;
            $cap->paperId = (int) $cap->paperId;
            $cap->reviewId = (int) $cap->reviewId;
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
        $dblink = $is_cdb ? $conf->contactdb() : $conf->dblink;
        if (strlen($token) < 5 || !$dblink) {
            return null;
        }
        $email = $is_cdb ? ", (select email from ContactInfo where contactDbId=Capability.contactId) email" : "";
        $result = Dbl::qe($dblink, "select *{$email} from Capability where salt=?", $token);
        $cap = self::fetch($result, $conf, $is_cdb);
        Dbl::free($result);
        return $cap;
    }

    /** @param string $token
     * @param ?int $capabilityType
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function find_active($token, $capabilityType, Conf $conf, $is_cdb = false) {
        $tok = self::find($token, $conf, $is_cdb);
        return $tok && $tok->is_active($capabilityType) ? $tok : null;
    }

    /** @param list<int> $types
     * @return Dbl_Result */
    static function expired_tokens_result(Conf $conf, $types) {
        // do not load `inputData` or `outputData`
        return $conf->ql("select capabilityType, contactId, paperId, reviewId, timeCreated, timeUsed, timeInvalid, timeExpires, salt, `data` from Capability where timeExpires>0 and timeExpires<? and capabilityType?a",
            Conf::$now, $types);
    }


    /** @param ?int $capabilityType
     * @return bool */
    function is_active($capabilityType = null) {
        return ($capabilityType === null || $this->capabilityType === $capabilityType)
            && ($this->timeExpires === 0 || $this->timeExpires > Conf::$now)
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

    /** @return ?Contact */
    function local_user() {
        if (!$this->is_cdb) {
            return $this->user();
        } else if ($this->email !== null) {
            return $this->conf->user_by_email($this->email)
                ?? Contact::make_email($this->conf, $this->email);
        } else {
            return null;
        }
    }

    /** @return string */
    function instantiate_token() {
        return preg_replace_callback('/\[(\d+)\]/', function ($m) {
            return base48_encode(random_bytes(intval($m[1])));
        }, $this->_token_pattern);
    }

    /** @return ?string
     * @suppress PhanAccessReadOnlyProperty */
    function create() {
        assert($this->capabilityType > 0);
        $this->contactId = $this->contactId ?? 0;
        $this->paperId = $this->paperId ?? 0;
        $this->reviewId = $this->reviewId ?? 0;
        $this->timeCreated = $this->timeCreated ?? Conf::$now;
        $this->timeUsed = $this->timeUsed ?? 0;
        $this->timeInvalid = $this->timeInvalid ?? 0;
        $this->timeExpires = $this->timeExpires ?? 0;
        $need_salt = !$this->salt;
        assert(!$need_salt || $this->_token_pattern);

        $qf = "";
        $qv = [
            null, $this->capabilityType, $this->contactId, $this->paperId,
            $this->timeCreated, $this->timeUsed, $this->timeInvalid,
            $this->timeExpires, $this->data
        ];
        if ($this->reviewId !== 0) {
            $qf .= ", reviewId";
            $qv[] = $this->reviewId;
        }
        if ($this->inputData !== null) {
            $qf .= ", inputData";
            $qv[] = $this->inputData;
        }

        for ($tries = 0; $tries < ($need_salt ? 4 : 1); ++$tries) {
            $salt = $need_salt ? $this->instantiate_token() : $this->salt;
            $qv[0] = $salt;
            $result = Dbl::qe($this->dblink(), "insert into Capability (salt, capabilityType, contactId, paperId, timeCreated, timeUsed, timeInvalid, timeExpires, data{$qf}) values ?v", [$qv]);
            if ($result->affected_rows > 0) {
                $this->salt = $salt;
                $this->_changes = 0;
                return $salt;
            }
        }
        return null;
    }

    /** @param ?string $key
     * @return mixed */
    function data($key = null) {
        $this->_jdata = $this->_jdata ?? json_decode_object($this->data);
        return $key ? $this->_jdata->$key ?? null : $this->_jdata;
    }

    function load_data() {
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->data = Dbl::fetch_value($this->dblink(), "select `data` from Capability where salt=?", $this->salt);
    }

    /** @param ?string $key
     * @return mixed */
    function input($key = null) {
        $this->_jinputData = $this->_jinputData ?? json_decode_object($this->inputData);
        return $key ? $this->_jinputData->$key ?? null : $this->_jinputData;
    }


    /** @param ?int $within_sec
     * @return $this */
    function update_use($within_sec = null) {
        if ($within_sec === null || $this->timeUsed + $within_sec <= Conf::$now) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->timeUsed = Conf::$now;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param ?string $data
     * @return $this */
    function change_data($data, $value = null) {
        if (json_encode_object_change($this->data, $this->_jdata, $data, $value, func_num_args())) {
            $this->_changes |= self::CHF_DATA;
        }
        return $this;
    }

    /** @param ?string $data
     * @return $this */
    function change_output($data, $value = null) {
        if (json_encode_object_change($this->outputData, $this->_joutputData, $data, $value, func_num_args())) {
            $this->_changes |= self::CHF_OUTPUT_DATA;
        }
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function unload_output() {
        $this->outputData = $this->_joutputData = null;
        $this->_changes &= ~self::CHF_OUTPUT_DATA;
        return $this;
    }

    /** @return bool */
    function update() {
        assert($this->capabilityType > 0 && !!$this->salt);
        if (($this->_changes ?? 0) === 0) {
            return false;
        }
        $qf = $qv = [];
        if (($this->_changes & self::CHF_TIMES) !== 0) {
            array_push($qf, "timeUsed=?", "timeInvalid=?", "timeExpires=?");
            array_push($qv, $this->timeUsed, $this->timeInvalid, $this->timeExpires);
        }
        if (($this->_changes & self::CHF_DATA) !== 0) {
            $qf[] = "`data`=?";
            $qv[] = $this->data;
        }
        if (($this->_changes & self::CHF_OUTPUT_DATA) !== 0) {
            $qf[] = "outputData=?";
            $qv[] = $this->outputData;
        }
        $qv[] = $this->salt;
        $result = Dbl::qe_apply($this->dblink(), "update Capability set " . join(", ", $qf) . " where salt=?", $qv);
        if (Dbl::is_error($result)) {
            return false;
        }
        $this->_changes = 0;
        return true;
    }

    function delete() {
        Dbl::qe($this->dblink(), "delete from Capability where salt=?", $this->salt);
    }
}
