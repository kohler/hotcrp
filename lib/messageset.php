<?php
// messageset.php -- HotCRP sets of messages by fields
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MessageSet {
    public $ignore_msgs = false;
    public $allow_error = [];
    public $werror = [];
    private $errf;
    private $msgs;
    public $has_warning;
    public $has_error;

    const INFO = 0;
    const WARNING = 1;
    const ERROR = 2;

    function __construct() {
        $this->clear();
    }
    function clear() {
        $this->errf = $this->msgs = [];
        $this->has_warning = $this->has_error = false;
    }

    function msg($field, $msg, $status) {
        if ($this->ignore_msgs)
            return;
        if ($field && $status == self::WARNING && $this->werror && isset($this->werror, $field))
            $status = self::ERROR;
        if ($field)
            $this->errf[$field] = max(get($this->errf, $field, 0), $status);
        if ($msg)
            $this->msgs[] = [$field, $msg, $status];
        if ($status == self::WARNING)
            $this->has_warning = true;
        if ($status == self::ERROR && (!$field || !get($this->allow_error, $field)))
            $this->has_error = true;
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

    function has_error() {
        return $this->has_error;
    }
    function has_warning() {
        return $this->has_warning;
    }
    function has_problem() {
        return $this->has_warning || $this->has_error;
    }
    function has_error_at($field) {
        return get($this->errf, $field, 0) > 1;
    }
    function has_problem_at($field) {
        return get($this->errf, $field, 0) > 0;
    }

    static private function filter_msgs($ms, $include_fields) {
        if ($include_fields || empty($ms))
            return $ms;
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
    function messages_at($field, $include_fields = false) {
        if (empty($this->msgs) || !isset($this->errf[$field]))
            return [];
        $ms = array_filter($this->msgs, function ($mx) use ($field) { return $mx[0] === $field; });
        if ($include_fields)
            return $ms;
        else
            return array_map(function ($mx) { return $mx[1]; }, $ms);
    }
}
