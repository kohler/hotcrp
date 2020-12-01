<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class MessageItem implements JsonSerializable {
    /** @var ?string */
    public $field;
    /** @var string */
    public $message;
    /** @var int */
    public $status;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;

    /** @param ?string $field
     * @param string $message
     * @param int $status */
    function __construct($field, $message, $status) {
        $this->field = $field;
        $this->message = $message;
        $this->status = $status;
    }

    function jsonSerialize() {
        $x = [];
        if ($this->field !== null) {
            $x["field"] = $this->field;
        }
        $x["message"] = $this->message;
        $x["status"] = $this->status;
        return (object) $x;
    }
}

class MessageSet {
    /** @var ?Contact */
    public $user;
    /** @var bool */
    public $ignore_msgs = false;
    /** @var bool */
    public $ignore_duplicates = false;
    /** @var ?array<string,true> */
    private $allow_error;
    /** @var ?array<string,true> */
    private $werror;
    /** @var ?array<string,string> */
    private $canonfield;
    /** @var array<string,int> */
    private $errf;
    /** @var list<MessageItem> */
    private $msgs;
    /** @var int */
    private $problem_status;

    const SUCCESS = -3;
    const URGENT_NOTE = -2;
    const NOTE = -1;
    const INFO = 0;
    const WARNING = 1;
    const ERROR = 2;
    const ESTOP = 3;

    function __construct() {
        $this->clear_messages();
    }
    function clear_messages() {
        $this->errf = $this->msgs = [];
        $this->problem_status = 0;
    }
    function clear() {
        $this->clear_messages();
    }

    /** @param string $src
     * @param string $dst */
    function translate_field($src, $dst) {
        $this->canonfield[$src] = $this->canonical_field($dst);
    }
    /** @param string $field
     * @return string */
    function canonical_field($field) {
        assert(!!$field);
        return $field ? $this->canonfield[$field] ?? $field : $field;
    }
    /** @param string $field
     * @return bool */
    function allow_error_at($field) {
        return $this->allow_error && isset($this->allow_error[$this->canonical_field($field)]);
    }
    /** @param string $field
     * @param bool $v */
    function set_allow_error_at($field, $v) {
        $field = $this->canonical_field($field);
        if ($v) {
            $this->allow_error[$field] = true;
        } else {
            unset($this->allow_error[$field]);
        }
    }
    /** @param string $field */
    function werror_at($field, $set = null) {
        $field = $this->canonical_field($field);
        if ($set === null) {
            return $this->werror && isset($this->werror[$field]);
        } else if ($set) {
            $this->werror[$field] = true;
        } else if ($this->werror) {
            unset($this->werror[$field]);
        }
    }
    /** @param bool $im
     * @return bool */
    function set_ignore_messages($im) {
        $oim = $this->ignore_msgs;
        $this->ignore_msgs = $im;
        return $oim;
    }

    /** @param ?string $field
     * @param string $msg
     * @param -3|-2|-1|0|1|2|3 $status
     * @return int|false */
    function message_index($field, $msg, $status) {
        if ($field === null || ($this->errf[$field] ?? -5) >= $status) {
            foreach ($this->msgs as $i => $m) {
                if ($m->field === $field
                    && $m->message === $msg
                    && $m->status === $status)
                    return $i;
            }
        }
        return false;
    }

    /** @param ?string $field
     * @param false|null|string|list<string> $msg
     * @param -2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        $mi = null;
        if (!$this->ignore_msgs) {
            if ($field !== null && $field !== false && $field !== "") {
                $field = $this->canonfield[$field] ?? $field;
                if ($status === self::WARNING && ($this->werror[$field] ?? false)) {
                    $status = self::ERROR;
                } else if ($status === self::ERROR && ($this->allow_error[$field] ?? false)) {
                    $status = self::WARNING;
                }
                $old_status = $this->errf[$field] ?? -5;
                $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
            } else {
                $field = null;
                $old_status = $this->problem_status;
            }
            if (is_string($msg)) {
                $msg = [$msg];
            } else if ($msg === null || $msg === false) {
                $msg = [];
            }
            foreach ($msg as $mt) {
                if ($mt !== ""
                    && (!$this->ignore_duplicates
                        || $old_status < $status
                        || $this->message_index($field, $mt, $status) === false)) {
                    $this->msgs[] = $mi = new MessageItem($field, $mt, $status);
                }
            }
            $this->problem_status = max($this->problem_status, $status);
        }
        return $mi ?? new MessageItem(null, "", $status);
    }
    /** @param ?string $field
     * @param false|null|string|list<string> $msg
     * @param 0|1|2|3 $status
     * @return MessageItem
     * @deprecated */
    function msg($field, $msg, $status) {
        return $this->msg_at($field, $msg, $status);
    }
    /** @param ?string $field
     * @param false|null|string $msg
     * @return MessageItem */
    function estop_at($field, $msg) {
        return $this->msg_at($field, $msg, self::ESTOP);
    }
    /** @param ?string $field
     * @param false|null|string $msg
     * @return MessageItem */
    function error_at($field, $msg) {
        return $this->msg_at($field, $msg, self::ERROR);
    }
    /** @param ?string $field
     * @param false|null|string $msg
     * @return MessageItem */
    function warning_at($field, $msg) {
        return $this->msg_at($field, $msg, self::WARNING);
    }
    /** @param ?string $field
     * @param false|null|string $msg
     * @return MessageItem */
    function info_at($field, $msg) {
        return $this->msg_at($field, $msg, self::INFO);
    }

    /** @return bool */
    function has_messages() {
        return !empty($this->msgs);
    }
    /** @return int */
    function message_count() {
        return count($this->msgs ?? []);
    }
    /** @return int */
    function problem_status() {
        return $this->problem_status;
    }
    /** @return bool */
    function has_problem() {
        return $this->problem_status >= self::WARNING;
    }
    /** @return bool */
    function has_error() {
        return $this->problem_status >= self::ERROR;
    }
    /** @return bool */
    function has_warning() {
        if ($this->problem_status >= self::WARNING) {
            foreach ($this->msgs as $mx) {
                if ($mx->status === self::WARNING)
                    return true;
            }
        }
        return false;
    }
    /** @param int $msgcount
     * @return bool */
    function has_error_since($msgcount) {
        for (; isset($this->msgs[$msgcount]); ++$msgcount) {
            if ($this->msgs[$msgcount]->status >= self::ERROR)
                return true;
        }
        return false;
    }

    /** @param string $field
     * @return int */
    function problem_status_at($field) {
        if ($this->problem_status >= self::WARNING) {
            $field = $this->canonfield[$field] ?? $field;
            return $this->errf[$field] ?? 0;
        } else {
            return 0;
        }
    }
    /** @param string $field
     * @return bool */
    function has_messages_at($field) {
        if (!empty($this->errf)) {
            $field = $this->canonfield[$field] ?? $field;
            if (isset($this->errf[$field])) {
                foreach ($this->msgs as $mx) {
                    if ($mx->field === $field)
                        return true;
                }
            }
        }
        return false;
    }
    /** @param string $field
     * @return bool */
    function has_problem_at($field) {
        return $this->problem_status_at($field) >= self::WARNING;
    }
    /** @param string $field
     * @return bool */
    function has_error_at($field) {
        return $this->problem_status_at($field) >= self::ERROR;
    }

    /** @param list<string> $fields
     * @return int */
    function max_problem_status_at($fields) {
        $ps = 0;
        if ($this->problem_status > $ps) {
            foreach ($fields as $f) {
                $f = $this->canonfield[$f] ?? $f;
                $ps = max($ps, $this->errf[$f] ?? 0);
            }
        }
        return $ps;
    }

    /** @param int $status
     * @param string $rest
     * @return string */
    static function status_class($status, $rest = "", $prefix = "has-") {
        if ($status >= self::ERROR) {
            $sclass = "error";
        } else if ($status === self::WARNING) {
            $sclass = "warning";
        } else if ($status === self::SUCCESS) {
            $sclass = "success";
        } else if ($status === self::NOTE) {
            $sclass = "note";
        } else if ($status === self::URGENT_NOTE) {
            $sclass = "urgent-note";
        } else {
            $sclass = "";
        }
        if ($sclass !== "") {
            return $rest . ($rest === "" ? $prefix : " " . $prefix) . $sclass;
        } else {
            return $rest;
        }
    }
    /** @param ?string|false $field
     * @param string $rest
     * @param string $prefix
     * @return string */
    function control_class($field, $rest = "", $prefix = "has-") {
        return self::status_class($field ? $this->errf[$field] ?? 0 : 0, $rest, $prefix);
    }

    /** @param iterable<MessageItem> $ms
     * @return list<string> */
    static private function list_texts($ms) {
        $t = [];
        foreach ($ms as $mx) {
            $t[] = $mx->message;
        }
        return $t;
    }
    /** @return array<string,int> */
    function message_field_map() {
        return $this->errf;
    }
    /** @return list<string> */
    function message_fields() {
        return array_keys($this->errf);
    }
    /** @return list<string> */
    function error_fields() {
        if ($this->problem_status >= self::ERROR) {
            return array_keys(array_filter($this->errf, function ($v) { return $v >= self::ERROR; }));
        } else {
            return [];
        }
    }
    /** @return list<string> */
    function warning_fields() {
        return array_keys(array_filter($this->errf, function ($v) { return $v == self::WARNING; }));
    }
    /** @return list<string> */
    function problem_fields() {
        return array_keys(array_filter($this->errf, function ($v) { return $v >= self::WARNING; }));
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->msgs;
    }
    /** @return list<string> */
    function message_texts() {
        return self::list_texts($this->msgs);
    }
    /** @return iterable<MessageItem> */
    function error_list() {
        if ($this->problem_status >= self::ERROR) {
            return array_filter($this->msgs, function ($mx) { return $mx->status >= self::ERROR; });
        } else {
            return [];
        }
    }
    /** @return list<string> */
    function error_texts() {
        return self::list_texts($this->error_list());
    }
    /** @return iterable<MessageItem> */
    function warning_list() {
        if ($this->problem_status >= self::WARNING) {
            return array_filter($this->msgs, function ($mx) { return $mx->status == self::WARNING; });
        } else {
            return [];
        }
    }
    /** @return list<string> */
    function warning_texts() {
        return self::list_texts($this->warning_list());
    }
    /** @return iterable<MessageItem> */
    function problem_list() {
        if ($this->problem_status >= self::WARNING) {
            return array_filter($this->msgs, function ($mx) { return $mx->status >= self::WARNING; });
        } else {
            return [];
        }
    }
    /** @return list<string> */
    function problem_texts() {
        return self::list_texts($this->problem_list());
    }
    /** @param string $field
     * @return iterable<MessageItem> */
    function message_list_at($field) {
        $field = $this->canonfield[$field] ?? $field;
        if (isset($this->errf[$field])) {
            return array_filter($this->msgs, function ($mx) use ($field) { return $mx->field === $field; });
        } else {
            return [];
        }
    }
    /** @param string $field
     * @return list<string> */
    function message_texts_at($field) {
        return self::list_texts($this->message_list_at($field));
    }
}
