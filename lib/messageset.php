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

    /** @param int $format
     * @return string */
    function message_as($format) {
        return Ftext::unparse_as($this->message, $format);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = [];
        if ($this->field !== null) {
            $x["field"] = $this->field;
        }
        if ($this->message !== "") {
            $x["message"] = $this->message;
        }
        $x["status"] = $this->status;
        return (object) $x;
    }

    /** @param ?string $message
     * @return array{ok:false,message_list:list<MessageItem>} */
    static function make_error_json($message) {
        return ["ok" => false, "message_list" => [new MessageItem(null, $message ?? "", 2)]];
    }
}

class MessageSet {
    /** @var bool */
    public $ignore_msgs = false;
    /** @var bool */
    public $ignore_duplicates = false;
    /** @var array<string,int> */
    private $errf;
    /** @var list<MessageItem> */
    private $msgs;
    /** @var int */
    private $problem_status;
    /** @var ?array<string,int> */
    private $pstatus_at;
    /** @var bool */
    private $want_ftext = false;

    const WARNING_NOTE = -4;
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

    /** @param bool $im
     * @return bool */
    function swap_ignore_messages($im) {
        $oim = $this->ignore_msgs;
        $this->ignore_msgs = $im;
        return $oim;
    }
    /** @param bool $v
     * @return $this */
    function set_ignore_duplicates($v) {
        $this->ignore_duplicates = $v;
        return $this;
    }
    /** @param string $field
     * @param -4|-3|-2|-1|0|1|2|3 $status */
    function set_status_for_problem_at($field, $status) {
        $this->pstatus_at[$field] = $status;
    }
    /** @return void */
    function clear_status_for_problem_at() {
        $this->pstatus_at = [];
    }
    /** @param bool $wft */
    function set_want_ftext($wft) {
        $this->want_ftext = $wft;
    }

    /** @param MessageItem $mi
     * @return int|false */
    private function message_index($mi) {
        if ($mi->field === null
            ? $this->problem_status >= $mi->status
            : ($this->errf[$mi->field] ?? -5) >= $mi->status) {
            foreach ($this->msgs as $i => $m) {
                if ($m->field === $mi->field
                    && $m->message === $mi->message
                    && $m->status === $mi->status)
                    return $i;
            }
        }
        return false;
    }

    /** @param MessageItem $mi */
    function add($mi) {
        if (!$this->ignore_msgs) {
            if ($mi->field !== null) {
                $old_status = $this->errf[$mi->field] ?? -5;
                $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
            } else {
                $old_status = $this->problem_status;
            }
            $this->problem_status = max($this->problem_status, $mi->status);
            if ($mi->message !== ""
                && (!$this->ignore_duplicates
                    || $old_status < $mi->status
                    || $this->message_index($mi) === false)) {
                $this->msgs[] = $mi;
                if ($this->want_ftext && !Ftext::is_ftext($mi->message)) {
                    error_log("not ftext: " . debug_string_backtrace());
                }
            }
        }
    }

    /** @param MessageSet $ms */
    function add_set($ms) {
        if (!$this->ignore_msgs) {
            foreach ($ms->msgs as $mi) {
                $this->add($mi);
            }
            foreach ($ms->errf as $field => $status) {
                $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
            }
        }
    }

    /** @param ?string $field
     * @param false|null|string $msg
     * @param -4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        if ($field === false || $field === "") {
            $field = null;
        }
        if ($msg === null || $msg === false) {
            $msg = "";
        }
        $mi = new MessageItem($field, $msg, $status);
        $this->add($mi);
        return $mi;
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
     * @param null|0|1|2|3 $default_status
     * @return MessageItem */
    function problem_at($field, $msg, $default_status = 1) {
        $status = $this->pstatus_at[$field] ?? $default_status ?? 1;
        return $this->msg_at($field, $msg, $status);
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
            return $this->errf[$field] ?? 0;
        } else {
            return 0;
        }
    }
    /** @param string $field
     * @return bool */
    function has_messages_at($field) {
        if (!empty($this->errf)) {
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
        } else if ($status === self::WARNING_NOTE) {
            $sclass = "warning-note";
        } else {
            $sclass = "";
        }
        if ($sclass !== "" && $rest !== "") {
            return "$rest $prefix$sclass";
        } else if ($sclass !== "") {
            return "$prefix$sclass";
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
    /** @param int $min_status
     * @return list<string> */
    private function min_status_fields($min_status) {
        $fs = [];
        if ($this->problem_status >= $min_status) {
            foreach ($this->errf as $f => $v) {
                if ($v >= $min_status) {
                    $fs[] = $f;
                }
            }
        }
        return $fs;
    }
    /** @param int $min_status
     * @return \Generator<MessageItem> */
    private function min_status_list($min_status) {
        if ($this->problem_status >= $min_status) {
            foreach ($this->msgs as $mx) {
                if ($mx->status >= $min_status) {
                    yield $mx;
                }
            }
        }
    }
    /** @return list<string> */
    function error_fields() {
        return $this->min_status_fields(self::ERROR);
    }
    /** @return list<string> */
    function problem_fields() {
        return $this->min_status_fields(self::WARNING);
    }
    /** @return list<MessageItem> */
    function message_list() {
        return $this->msgs;
    }
    /** @return list<string> */
    function message_texts() {
        return self::list_texts($this->msgs);
    }
    /** @return \Generator<MessageItem> */
    function error_list() {
        return $this->min_status_list(self::ERROR);
    }
    /** @return list<string> */
    function error_texts() {
        return self::list_texts($this->error_list());
    }
    /** @return \Generator<MessageItem> */
    function problem_list() {
        return $this->min_status_list(self::WARNING);
    }
    /** @return list<string> */
    function problem_texts() {
        return self::list_texts($this->problem_list());
    }
    /** @param string $field
     * @return \Generator<MessageItem> */
    function message_list_at($field) {
        if (isset($this->errf[$field])) {
            foreach ($this->msgs as $mx) {
                if ($mx->field === $field) {
                    yield $mx;
                }
            }
        }
    }
    /** @param string $field
     * @return list<string> */
    function message_texts_at($field) {
        return self::list_texts($this->message_list_at($field));
    }

    /** @param string $message
     * @param int $status
     * @return string */
    static function feedback_p_html($message, $status) {
        $k = self::status_class($status, "feedback", "is-");
        return "<p class=\"{$k}\">{$message}</p>";
    }
    /** @param string $field
     * @return string */
    function feedback_html_at($field) {
        $t = "";
        foreach ($this->message_list_at($field) as $mx) {
            $t .= self::feedback_p_html($mx->message, $mx->status);
        }
        return $t;
    }
    /** @param string $message
     * @param int $status
     * @return string
     * @deprecated */
    static function render_feedback_p($message, $status) {
        return self::feedback_p_html($message, $status);
    }
    /** @param string $field
     * @return string
     * @deprecated */
    function render_feedback_at($field) {
        return self::feedback_html_at($field);
    }
}
