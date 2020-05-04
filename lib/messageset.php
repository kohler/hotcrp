<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class MessageSet {
    public $user;
    public $ignore_msgs = false;
    public $ignore_duplicates = false;
    private $allow_error;
    private $werror;
    private $canonfield;
    private $errf;
    private $msgs;
    private $problem_status;

    const INFO = 0;
    const WARNING = 1;
    const ERROR = 2;

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

    function translate_field($src, $dst) {
        $this->canonfield[$src] = $this->canonical_field($dst);
    }
    function canonical_field($field) {
        return $field ? $this->canonfield[$field] ?? $field : $field;
    }
    function allow_error_at($field, $set = null) {
        $field = $this->canonical_field($field);
        if ($set === null) {
            return $this->allow_error && isset($this->allow_error[$field]);
        } else if ($set) {
            $this->allow_error[$field] = true;
        } else if ($this->allow_error) {
            unset($this->allow_error[$field]);
        }
    }
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

    function msg_at($field, $msg, $status) {
        if ($this->ignore_msgs) {
            return;
        }
        if ($field) {
            $field = $this->canonfield[$field] ?? $field;
            if ($status === self::WARNING && ($this->werror[$field] ?? false)) {
                $status = self::ERROR;
            } else if ($status === self::ERROR && ($this->allow_error[$field] ?? false)) {
                $status = self::WARNING;
            }
            $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
        }
        if ($msg === null || $msg === false || $msg === []) {
            $msg = "";
        }
        if ($this->ignore_duplicates
            && $msg !== ""
            && is_string($msg)
            && (!$field || isset($this->errf[$field]))
            && in_array([$field, $msg, $status], $this->msgs)) {
            return;
        }
        if ($msg !== "") {
            foreach (is_array($msg) ? $msg : [$msg] as $m) {
                $this->msgs[] = [$field, $m, $status];
            }
        }
        $this->problem_status = max($this->problem_status, $status);
    }
    function msg($field, $msg, $status) {
        $this->msg_at($field, $msg, $status);
    }
    function error_at($field, $msg) {
        $this->msg_at($field, $msg, self::ERROR);
    }
    function warning_at($field, $msg) {
        $this->msg_at($field, $msg, self::WARNING);
    }
    function info_at($field, $msg) {
        $this->msg_at($field, $msg, self::INFO);
    }

    function has_messages() {
        return !empty($this->msgs);
    }
    function message_count() {
        return count($this->msgs ?? []);
    }
    function problem_status() {
        return $this->problem_status;
    }
    function has_problem() {
        return $this->problem_status >= self::WARNING;
    }
    function has_error() {
        return $this->problem_status >= self::ERROR;
    }
    function has_warning() {
        if ($this->problem_status >= self::WARNING) {
            foreach ($this->msgs as $mx) {
                if ($mx[2] === self::WARNING)
                    return true;
            }
        }
        return false;
    }
    function has_error_since($msgcount) {
        for (; isset($this->msgs[$msgcount]); ++$msgcount) {
            if ($this->msgs[$msgcount][2] >= self::ERROR)
                return true;
        }
        return false;
    }

    function problem_status_at($field) {
        if ($this->problem_status >= self::WARNING) {
            $field = $this->canonfield[$field] ?? $field;
            return $this->errf[$field] ?? 0;
        } else {
            return 0;
        }
    }
    function has_messages_at($field) {
        if (!empty($this->errf)) {
            $field = $this->canonfield[$field] ?? $field;
            if (isset($this->errf[$field])) {
                foreach ($this->msgs as $mx) {
                    if ($mx[0] === $field)
                        return true;
                }
            }
        }
        return false;
    }
    function has_problem_at($field) {
        return $this->problem_status_at($field) >= self::WARNING;
    }
    function has_error_at($field) {
        return $this->problem_status_at($field) >= self::ERROR;
    }

    static function status_class($status, $rest = "", $prefix = "has-") {
        if ($status >= self::WARNING) {
            if ((string) $rest !== "") {
                $rest .= " ";
            }
            $rest .= $prefix . ($status >= self::ERROR ? "error" : "warning");
        }
        return $rest;
    }
    function control_class($field, $rest = "", $prefix = "has-") {
        return self::status_class($field ? $this->errf[$field] ?? 0 : 0, $rest, $prefix);
    }

    static private function filter_msgs($ms, $include_fields) {
        if ($include_fields || empty($ms)) {
            return $ms ? : [];
        } else {
            return array_map(function ($mx) { return $mx[1]; }, $ms);
        }
    }
    function message_field_map() {
        return $this->errf;
    }
    function message_fields() {
        return array_keys($this->errf);
    }
    function error_fields() {
        if ($this->problem_status >= self::ERROR) {
            return array_keys(array_filter($this->errf, function ($v) { return $v >= self::ERROR; }));
        } else {
            return [];
        }
    }
    function warning_fields() {
        return array_keys(array_filter($this->errf, function ($v) { return $v == self::WARNING; }));
    }
    function problem_fields() {
        return array_keys(array_filter($this->errf, function ($v) { return $v >= self::WARNING; }));
    }
    function messages($include_fields = false) {
        return self::filter_msgs($this->msgs, $include_fields);
    }
    function errors($include_fields = false) {
        if ($this->problem_status >= self::ERROR) {
            $ms = array_filter($this->msgs, function ($mx) { return $mx[2] >= self::ERROR; });
            return self::filter_msgs($ms, $include_fields);
        } else {
            return [];
        }
    }
    function warnings($include_fields = false) {
        if ($this->problem_status >= self::WARNING) {
            $ms = array_filter($this->msgs, function ($mx) { return $mx[2] == self::WARNING; });
            return self::filter_msgs($ms, $include_fields);
        } else {
            return [];
        }
    }
    function problems($include_fields = false) {
        if ($this->problem_status >= self::WARNING) {
            $ms = array_filter($this->msgs, function ($mx) { return $mx[2] >= self::WARNING; });
            return self::filter_msgs($ms, $include_fields);
        } else {
            return [];
        }
    }
    function messages_at($field, $include_fields = false) {
        if (isset($this->errf[$field])) {
            $field = $this->canonfield[$field] ?? $field;
            $ms = array_filter($this->msgs, function ($mx) use ($field) { return $mx[0] === $field; });
            return self::filter_msgs($ms, $include_fields);
        } else {
            return [];
        }
    }
}
