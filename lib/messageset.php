<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class MessageSet {
    public $user;
    public $ignore_msgs = false;
    public $ignore_duplicates = false;
    private $allow_error;
    private $werror;
    private $errf;
    private $msgs;
    private $canonfield;
    public $has_warning;
    public $has_error;

    const INFO = 0;
    const WARNING = 1;
    const ERROR = 2;

    function __construct() {
        $this->clear_messages();
    }
    function clear_messages() {
        $this->errf = $this->msgs = [];
        $this->has_warning = $this->has_error = 0;
    }
    function clear() {
        $this->clear_messages();
    }

    function translate_field($src, $dst) {
        $this->canonfield[$src] = $this->canonical_field($dst);
    }
    function canonical_field($field) {
        if ($field && $this->canonfield && isset($this->canonfield[$field]))
            $field = $this->canonfield[$field];
        return $field;
    }
    function allow_error_at($field, $set = null) {
        $field = $this->canonical_field($field);
        if ($set === null)
            return $this->allow_error && isset($this->allow_error[$field]);
        else if ($set)
            $this->allow_error[$field] = true;
        else if ($this->allow_error)
            unset($this->allow_error[$field]);
    }
    function werror_at($field, $set = null) {
        $field = $this->canonical_field($field);
        if ($set === null)
            return $this->werror && isset($this->werror[$field]);
        else if ($set)
            $this->werror[$field] = true;
        else if ($this->werror)
            unset($this->werror[$field]);
    }

    function msg($field, $msg, $status) {
        if ($this->ignore_msgs)
            return;
        $this->canonfield && ($field = $this->canonical_field($field));
        if ($status == self::WARNING
            && $field
            && $this->werror
            && isset($this->werror[$field]))
            $status = self::ERROR;
        if ($field)
            $this->errf[$field] = max(get($this->errf, $field, 0), $status);
        if ($msg === null || $msg === false || $msg === [])
            $msg = "";
        if ($this->ignore_duplicates
            && $msg !== ""
            && is_string($msg)
            && (!$field || isset($this->errf[$field]))
            && in_array([$field, $msg, $status], $this->msgs))
            return;
        if ($msg !== "") {
            foreach (is_array($msg) ? $msg : [$msg] as $m)
                $this->msgs[] = [$field, $m, $status];
        }
        if ($status == self::WARNING)
            ++$this->has_warning;
        if ($status == self::ERROR
            && !($field && $this->allow_error && isset($this->allow_error[$field])))
            ++$this->has_error;
    }
    function error_at($field, $msg) {
        $this->msg($field, $msg, self::ERROR);
    }
    function warning_at($field, $msg) {
        $this->msg($field, $msg, self::WARNING);
    }
    function info_at($field, $msg) {
        $this->msg($field, $msg, self::INFO);
    }

    function problem_status() {
        if ($this->has_error > 0)
            return self::ERROR;
        else
            return $this->has_warning > 0 ? self::WARNING : self::INFO;
    }
    function has_messages() {
        return !empty($this->msgs);
    }
    function has_problem() {
        return $this->has_warning > 0 || $this->has_error > 0;
    }
    function has_error() {
        return $this->has_error > 0;
    }
    function has_warning() {
        return $this->has_warning > 0;
    }

    function nerrors() {
        return $this->has_error;
    }
    function nwarnings() {
        return $this->has_warning;
    }

    function problem_status_at($field) {
        if ($this->has_warning > 0 || $this->has_error > 0) {
            $this->canonfield && ($field = $this->canonical_field($field));
            return get($this->errf, $field, 0);
        } else
            return 0;
    }
    function has_messages_at($field) {
        if (!empty($this->errf)) {
            $this->canonfield && ($field = $this->canonical_field($field));
            if (isset($this->errf[$field])) {
                foreach ($this->msgs as $mx)
                    if ($mx[0] === $field)
                        return true;
            }
        }
        return false;
    }
    function has_problem_at($field) {
        return $this->problem_status_at($field) > 0;
    }
    function has_error_at($field) {
        return $this->problem_status_at($field) > 1;
    }

    static function status_class($status, $rest = "", $prefix = "has-") {
        if ($status >= self::WARNING) {
            if ((string) $rest !== "")
                $rest .= " ";
            $rest .= $prefix . ($status >= self::ERROR ? "error" : "warning");
        }
        return $rest;
    }
    function control_class($field, $rest = "", $prefix = "has-") {
        return self::status_class($field ? get($this->errf, $field, 0) : 0, $rest, $prefix);
    }

    static private function filter_msgs($ms, $include_fields) {
        if ($include_fields || empty($ms))
            return $ms ? : [];
        else
            return array_map(function ($mx) { return $mx[1]; }, $ms);
    }
    function message_field_map() {
        return $this->errf;
    }
    function message_fields() {
        return array_keys($this->errf);
    }
    function error_fields() {
        if (!$this->has_error)
            return [];
        return array_keys(array_filter($this->errf, function ($v) { return $v >= self::ERROR; }));
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
        if (!$this->has_error)
            return [];
        $ms = array_filter($this->msgs, function ($mx) { return $mx[2] >= self::ERROR; });
        return self::filter_msgs($ms, $include_fields);
    }
    function warnings($include_fields = false) {
        if (!$this->has_warning)
            return [];
        $ms = array_filter($this->msgs, function ($mx) { return $mx[2] == self::WARNING; });
        return self::filter_msgs($ms, $include_fields);
    }
    function problems($include_fields = false) {
        if (!$this->has_error && !$this->has_warning)
            return [];
        $ms = array_filter($this->msgs, function ($mx) { return $mx[2] >= self::WARNING; });
        return self::filter_msgs($ms, $include_fields);
    }
    function messages_at($field, $include_fields = false) {
        if (!isset($this->errf[$field]))
            return [];
        $this->canonfield && ($field = $this->canonical_field($field));
        $ms = array_filter($this->msgs, function ($mx) use ($field) { return $mx[0] === $field; });
        if ($include_fields)
            return $ms;
        else
            return array_map(function ($mx) { return $mx[1]; }, $ms);
    }
}
