<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class MessageSet {
    public $ignore_msgs = false;
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
        $this->clear();
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
            && $field && $this->werror && isset($this->werror[$field]))
            $status = self::ERROR;
        if ($field)
            $this->errf[$field] = max(get($this->errf, $field, 0), $status);
        if ($msg)
            $this->msgs[] = [$field, $msg, $status];
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

    function has_error() {
        return $this->has_error > 0;
    }
    function nerrors() {
        return $this->has_error;
    }
    function has_warning() {
        return $this->has_warning > 0;
    }
    function nwarnings() {
        return $this->has_warning;
    }
    function has_problem() {
        return $this->has_warning > 0 || $this->has_error > 0;
    }
    function has_messages() {
        return !empty($this->msgs);
    }
    function problem_status() {
        if ($this->has_error > 0)
            return self::ERROR;
        else
            return $this->has_warning > 0 ? self::WARNING : self::INFO;
    }
    function has_error_at($field) {
        $this->canonfield && ($field = $this->canonical_field($field));
        return get($this->errf, $field, 0) > 1;
    }
    function has_problem_at($field) {
        $this->canonfield && ($field = $this->canonical_field($field));
        return get($this->errf, $field, 0) > 0;
    }
    function problem_status_at($field) {
        $this->canonfield && ($field = $this->canonical_field($field));
        return get($this->errf, $field, 0);
    }
    function control_class($field, $rest = "") {
        $x = $field ? get($this->errf, $field, 0) : 0;
        if ($x >= self::ERROR)
            return $rest === "" ? "has-error" : $rest . " has-error";
        else if ($x === self::WARNING)
            return $rest === "" ? "has-warning" : $rest . " has-warning";
        else
            return $rest;
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
        $this->canonfield && ($field = $this->canonical_field($field));
        $ms = array_filter($this->msgs, function ($mx) use ($field) { return $mx[0] === $field; });
        if ($include_fields)
            return $ms;
        else
            return array_map(function ($mx) { return $mx[1]; }, $ms);
    }
}
