<?php
// tokeninfo.php -- HotCRP token management
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    /** @var int
     * @readonly */
    public $contactId;
    /** @var int
     * @readonly */
    public $paperId;
    /** @var int */
    public $reviewId;
    /** @var ?int
     * @readonly */
    public $timeCreated;
    /** @var int
     * @readonly */
    public $timeUsed;
    /** @var int
     * @readonly */
    public $useCount;
    /** @var int
     * @readonly */
    public $timeInvalid;
    /** @var int
     * @readonly */
    public $timeExpires;
    /** @var string
     * @readonly */
    public $salt;
    /** @var ?string
     * @readonly */
    protected $inputData;
    /** @var ?string
     * @readonly */
    protected $inputDataOverflow;
    /** @var ?string
     * @readonly */
    protected $data;
    /** @var ?string
     * @readonly */
    protected $dataOverflow;
    /** @var ?string */
    public $outputData;
    /** @var ?int */
    public $outputTimestamp;
    /** @var ?string */
    public $outputMimetype;
    /** @var ?string
     * @readonly */
    public $lookupKey;

    /** @var ?string */
    public $email;
    /** @var ?Contact|false */
    private $_user = false;
    /** @var ?string */
    private $_token_pattern;
    /** @var ?callable(TokenInfo):bool */
    private $_token_approver;
    /** @var ?object */
    private $_jinputData;
    /** @var ?object */
    private $_jdata;
    /** @var ?object */
    private $_joutputData;
    /** @var int */
    private $_changes;

    const SALT_PREFIX = "hc";

    const RESETPASSWORD = 1;
    const CHANGEEMAIL = 2;
    const UPLOAD = 3;
    const AUTHORVIEW = 4;
    const REVIEWACCEPT = 5;
    const OAUTHSIGNIN = 6;
    const BEARER = 7;
    const JOB = 8;
    const OAUTHCODE = 9;
    const MANAGEEMAIL = 10;
    const ALERT = 11;
    const OAUTHREFRESH = 12;
    const OAUTHCLIENT = 13;

    const CHF_UID = 1;
    const CHF_TIMES = 2;
    const CHF_DATA = 4;
    const CHF_OUTPUT = 8;

    /** @param ?int $capabilityType */
    function __construct(Conf $conf, $capabilityType = null) {
        $this->conf = $conf;
        $this->capabilityType = $this->capabilityType ?? $capabilityType;
    }

    /** @return bool */
    final function stored() {
        return $this->timeCreated !== null;
    }

    /** return \mysqli */
    private function dblink() {
        return $this->is_cdb ? $this->conf->contactdb() : $this->conf->dblink;
    }

    /** @param bool $is_cdb
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_contactdb($is_cdb) {
        assert($this->is_cdb === null);
        $this->is_cdb = $is_cdb;
        return $this;
    }

    /** @param int $uid
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_user_id($uid) {
        assert(!$this->contactId && $uid >= 0);
        $this->contactId = $uid;
        $this->_changes |= self::CHF_UID;
        return $this;
    }

    /** @param ?bool $is_cdb
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_user_from(Contact $user, $is_cdb) {
        if ($this->is_cdb === null) {
            $this->is_cdb = $is_cdb ?? $user->is_cdb_user();
        }
        if (!$this->is_cdb) {
            $user->ensure_account_here();
        }
        $uid = $this->is_cdb ? $user->contactDbId : $user->contactId;
        assert(!$this->contactId && $uid > 0);
        $this->contactId = $uid;
        $this->email = $user->email;
        $this->_user = $user;
        $this->_changes |= self::CHF_UID;
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_paper(?PaperInfo $prow) {
        assert(!$this->stored());
        $this->paperId = $prow ? $prow->paperId : 0;
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_review(ReviewInfo $rrow) {
        assert(!$this->stored());
        $this->paperId = $rrow->paperId;
        $this->reviewId = $rrow->reviewId;
        return $this;
    }

    /** @param string $pattern
     * @return $this */
    final function set_token_pattern($pattern) {
        assert(!$this->stored());
        $this->_token_pattern = $pattern;
        return $this;
    }

    /** @param callable(TokenInfo):bool $approver
     * @return $this */
    final function set_token_approver($approver) {
        $this->_token_approver = $approver;
        return $this;
    }

    /** @param string $salt
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_salt($salt) {
        assert(!$this->stored());
        $this->salt = $salt;
        return $this;
    }

    /** @param ?string $key
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_lookup_key($key) {
        $this->lookupKey = $key;
        return $this;
    }

    /** @param int $t
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_invalid_at($t) {
        if ($t !== $this->timeInvalid) {
            $this->timeInvalid = $t;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    final function set_invalid_in($seconds) {
        return $this->set_invalid_at(Conf::$now + $seconds);
    }

    /** @param int $seconds
     * @return $this
     * @deprecated */
    final function set_invalid_after($seconds) {
        return $this->set_invalid_in($seconds);
    }

    /** @return $this */
    final function set_invalid() {
        if ($this->timeInvalid <= 0 || $this->timeInvalid >= Conf::$now) {
            $this->set_invalid_at(Conf::$now - 1);
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    final function extend_validity($seconds) {
        if ($this->timeInvalid > 0 && $this->timeInvalid < Conf::$now + $seconds) {
            $this->set_invalid_at(Conf::$now + $seconds);
        }
        return $this;
    }

    /** @param int $t
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_expires_at($t) {
        if ($t !== $this->timeExpires) {
            $this->timeExpires = $t;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param int $seconds
     * @return $this */
    final function set_expires_in($seconds) {
        return $this->set_expires_at(Conf::$now + $seconds);
    }

    /** @param int $seconds
     * @return $this
     * @deprecated */
    final function set_expires_after($seconds) {
        return $this->set_expires_in($seconds);
    }

    /** @param int $seconds
     * @return $this */
    final function extend_expiry($seconds) {
        if ($this->timeExpires > 0 && $this->timeExpires < Conf::$now + $seconds) {
            $this->set_expires_at(Conf::$now + $seconds);
        }
        return $this;
    }

    /** @param null|string|associative-array|object $data
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function set_input($data, $value = null) {
        json_encode_object_change($this->inputData, $this->_jinputData, $data, $value, func_num_args());
        return $this;
    }

    /** @param bool $is_cdb
     * @suppress PhanAccessReadOnlyProperty */
    function incorporate(Conf $conf, $is_cdb) {
        $this->conf = $conf;
        $this->is_cdb = $is_cdb;
        $this->capabilityType = (int) $this->capabilityType;
        $this->contactId = (int) $this->contactId;
        $this->paperId = (int) $this->paperId;
        $this->reviewId = (int) $this->reviewId;
        $this->timeCreated = (int) $this->timeCreated;
        $this->timeUsed = (int) $this->timeUsed;
        $this->useCount = (int) $this->useCount;
        $this->timeInvalid = (int) $this->timeInvalid;
        $this->timeExpires = (int) $this->timeExpires;
        $this->inputData = $this->inputDataOverflow ?? $this->inputData;
        $this->inputDataOverflow = null;
        $this->data = $this->dataOverflow ?? $this->data;
        $this->dataOverflow = null;
    }

    /** @template T
     * @param mysqli_result|Dbl_Result $result
     * @param bool $is_cdb
     * @param class-string<T> $class
     * @return T|null */
    static function fetch($result, Conf $conf, $is_cdb, $class = "TokenInfo") {
        $cap = $result->fetch_object($class, [$conf]);
        '@phan-var ?T $cap';
        if ($cap) {
            $cap->incorporate($conf, $is_cdb);
        }
        return $cap;
    }

    /** @param ?string $token
     * @param bool $is_cdb
     * @return ?TokenInfo */
    static function find_from($token, Conf $conf, $is_cdb) {
        $db = $is_cdb ? $conf->contactdb() : $conf->dblink;
        if ($token === null || strlen($token) < 5 || !$db) {
            return null;
        }
        $extra = $is_cdb ? ", (select email from ContactInfo where contactDbId=Capability.contactId) email" : "";
        $result = Dbl::qe($db, "select *{$extra} from Capability where salt=?", $token);
        $cap = self::fetch($result, $conf, $is_cdb);
        $result->close();
        return $cap;
    }

    /** @param ?string $token
     * @return ?TokenInfo */
    static function find($token, Conf $conf) {
        return self::find_from($token, $conf, false);
    }

    /** @param ?string $token
     * @return ?TokenInfo
     * @deprecated */
    static function find_cdb($token, Conf $conf) {
        return self::find_from($token, $conf, true);
    }

    /** @param string $token
     * @param ?int $capabilityType
     * @param bool $is_cdb
     * @return ?TokenInfo
     * @deprecated */
    static function find_active($token, $capabilityType, Conf $conf, $is_cdb = false) {
        $tok = self::find_from($token, $conf, $is_cdb);
        return $tok && $tok->is_active($capabilityType) ? $tok : null;
    }

    /** @param list<int> $types
     * @return Dbl_Result */
    static function expired_result(Conf $conf, $types) {
        // do not load `inputData` or `outputData`
        return $conf->ql("select capabilityType, contactId, paperId, reviewId, timeCreated, timeUsed, useCount, timeInvalid, timeExpires, salt, `data`, dataOverflow from Capability where timeExpires>0 and timeExpires<? and capabilityType?a",
            Conf::$now, $types);
    }

    /** @param string $lookup_key
     * @return Dbl_Result */
    static function active_lookup_key_result(Conf $conf, $lookup_key) {
        return $conf->ql("select * from Capability where (timeExpires<=0 or timeExpires>=?) and lookupKey?e",
            Conf::$now, $lookup_key);
    }


    /** @param ?int $capabilityType
     * @return bool */
    final function is_active($capabilityType = null) {
        return ($capabilityType === null || $this->capabilityType === $capabilityType)
            && ($this->timeExpires === 0 || $this->timeExpires > Conf::$now)
            && ($this->timeInvalid === 0 || $this->timeInvalid > Conf::$now);
    }

    /** @return ?Contact */
    final function user() {
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
    final function local_user() {
        if (!$this->is_cdb) {
            return $this->user();
        } else if ($this->email !== null) {
            return $this->conf->user_by_email($this->email)
                ?? Contact::make_email($this->conf, $this->email);
        }
        return null;
    }

    /** @return string */
    final function instantiate_token() {
        while (true) {
            $s = preg_replace_callback('/\[(\d+)\]/', function ($m) {
                return base48_encode(random_bytes(intval($m[1])));
            }, $this->_token_pattern);
            if (!str_starts_with($this->_token_pattern, "[")
                || !str_starts_with($s, self::SALT_PREFIX)) {
                return $s;
            }
        }
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function insert() {
        assert($this->timeCreated === null);
        assert($this->capabilityType > 0);
        $this->contactId = $this->contactId ?? 0;
        $this->paperId = $this->paperId ?? 0;
        $this->reviewId = $this->reviewId ?? 0;
        $this->timeUsed = $this->timeUsed ?? 0;
        $this->useCount = $this->useCount ?? 0;
        $this->timeInvalid = $this->timeInvalid ?? 0;
        $this->timeExpires = $this->timeExpires ?? 0;
        $need_salt = !$this->salt;
        assert(!$need_salt || $this->_token_pattern);
        $changes = $this->_changes;
        $this->_changes = 0;

        $qf = "";
        $qv = [
            null /* salt */, null /* timeCreated */,
            $this->capabilityType, $this->contactId, $this->paperId,
            $this->timeUsed, $this->useCount,
            $this->timeInvalid, $this->timeExpires
        ];
        if ($this->data === null || strlen($this->data) <= 16383) {
            $qv[] = $this->data;
            $qv[] = null;
        } else {
            $qv[] = null;
            $qv[] = $this->data;
        }
        if ($this->reviewId !== 0) {
            $qf .= ", reviewId";
            $qv[] = $this->reviewId;
        }
        if ($this->inputData !== null && strlen($this->inputData) > 16383) {
            $qf .= ", inputDataOverflow";
            $qv[] = $this->inputData;
        } else if ($this->inputData !== null) {
            $qf .= ", inputData";
            $qv[] = $this->inputData;
        }
        if ($this->lookupKey !== null) {
            $qf .= ", lookupKey";
            $qv[] = $this->lookupKey;
        }

        for ($tries = 0; $tries < ($need_salt ? 5 : 1); ++$tries) {
            if ($need_salt) {
                $this->salt = $this->instantiate_token();
            }
            $qv[0] = $this->salt;
            $qv[1] = Conf::$now;
            $result = Dbl::qe($this->dblink(), "insert into Capability (salt, timeCreated, capabilityType, contactId, paperId, timeUsed, useCount, timeInvalid, timeExpires, data, dataOverflow{$qf}) values ?v", [$qv]);
            if ($result->affected_rows <= 0) {
                continue;
            }
            if ($this->_token_approver
                && !call_user_func($this->_token_approver, $this)) {
                Dbl::qe($this->dblink(), "delete from Capability where salt=?", $this->salt);
                continue;
            }
            $this->timeCreated = $qv[1];
            $this->update();  // does nothing unless _token_approver modifies self
            return $this;
        }

        if ($need_salt) {
            $this->salt = null;
        }
        $this->_changes = $changes;
        return $this;
    }

    /** @param ?string $key
     * @return mixed */
    final function data($key = null) {
        $this->_jdata = $this->_jdata ?? json_decode_object($this->data);
        return $key ? $this->_jdata->$key ?? null : $this->_jdata;
    }

    /** @return ?string */
    final function encoded_data() {
        return $this->data;
    }

    /** @param string $key
     * @return bool */
    final function has_data($key) {
        return $this->data($key) !== null;
    }

    final function load_data() {
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->data = Dbl::fetch_value($this->dblink(), "select coalesce(dataOverflow,`data`) from Capability where salt=?", $this->salt);
    }

    /** @param ?string $key
     * @return mixed */
    final function input($key = null) {
        $this->_jinputData = $this->_jinputData ?? json_decode_object($this->inputData);
        return $key ? $this->_jinputData->$key ?? null : $this->_jinputData;
    }


    /** @param ?int $within_sec
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function update_use($within_sec = null) {
        if ($within_sec === null) {
            Conf::set_current_time();
        }
        if ($within_sec === null || $this->timeUsed + $within_sec <= Conf::$now) {
            $this->timeUsed = Conf::$now;
            ++$this->useCount;
            $this->_changes |= self::CHF_TIMES;
        }
        return $this;
    }

    /** @param ?string $data
     * @return $this */
    final function change_data($data, $value = null) {
        if (json_encode_object_change($this->data, $this->_jdata, $data, $value, func_num_args())) {
            $this->_changes |= self::CHF_DATA;
        }
        return $this;
    }

    /** @param null|string|associative-array|object $data
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    final function assign_data($data) {
        if ($data !== null && !is_string($data)) {
            $data = json_encode_db($data);
        }
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->data = $data;
        $this->_jdata = null;
        $this->_changes &= ~self::CHF_DATA;
        return $this;
    }

    /** @param string $data
     * @param string $mimetype
     * @return $this */
    final function set_output($data, $mimetype) {
        assert($this->outputData === null);
        $this->outputData = $data;
        $this->outputMimetype = $mimetype;
        $this->outputTimestamp = Conf::$now;
        $this->_changes |= self::CHF_OUTPUT;
        return $this;
    }

    /** @return bool */
    final function need_update() {
        return ($this->_changes ?? 0) !== 0;
    }

    /** @return bool */
    final function update() {
        assert($this->capabilityType > 0 && !!$this->salt);
        if (($this->_changes ?? 0) === 0) {
            return false;
        }
        $qf = $qv = [];
        if (($this->_changes & self::CHF_UID) !== 0) {
            $qf[] = "contactId=?";
            $qv[] = $this->contactId;
        }
        if (($this->_changes & self::CHF_TIMES) !== 0) {
            array_push($qf, "timeUsed=?", "useCount=?", "timeInvalid=?", "timeExpires=?");
            array_push($qv, $this->timeUsed, $this->useCount, $this->timeInvalid, $this->timeExpires);
        }
        if (($this->_changes & self::CHF_DATA) !== 0) {
            array_push($qf, "`data`=?", "dataOverflow=?");
            if ($this->data === null || strlen($this->data) <= 16383) {
                array_push($qv, $this->data, null);
            } else {
                array_push($qv, null, $this->data);
            }
        }
        if (($this->_changes & self::CHF_OUTPUT) !== 0) {
            array_push($qf, "outputData=?", "outputMimetype=?", "outputTimestamp=?");
            array_push($qv, $this->outputData, $this->outputMimetype, $this->outputTimestamp);
        }
        $qv[] = $this->salt;
        $result = Dbl::qe_apply($this->dblink(), "update Capability set " . join(", ", $qf) . " where salt=?", $qv);
        if (Dbl::is_error($result)) {
            return false;
        }
        $this->_changes = 0;
        return true;
    }

    final function delete() {
        Dbl::qe($this->dblink(), "delete from Capability where salt=?", $this->salt);
    }
}
