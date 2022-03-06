<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var ?string */
    public $context;
    /** @var ?string */
    public $landmark;

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

    /** @param array{field?:?string,message?:string,status?:int,problem_status?:int} $updates
     * @return MessageItem */
    function with($updates) {
        $mi = clone $this;
        if (array_key_exists("field", $updates)) {
            $mi->field = $updates["field"] === "" ? null : $updates["field"];
        }
        if (array_key_exists("status", $updates)) {
            $mi->status = $updates["status"];
        } else if (array_key_exists("problem_status", $updates)
                   && ($this->status === MessageSet::WARNING || $this->status === MessageSet::ERROR)) {
            $mi->status = $updates["problem_status"];
        }
        if (array_key_exists("message", $updates)) {
            $mi->message = $updates["message"];
        }
        if (array_key_exists("landmark", $updates)) {
            $mi->landmark = $updates["landmark"];
        }
        return $mi;
    }

    /** @param ?string $field
     * @return MessageItem */
    function with_field($field) {
        return $this->field === $field ? $this : $this->with(["field" => $field]);
    }

    /** @param ?string $landmark
     * @return MessageItem */
    function with_landmark($landmark) {
        return $this->landmark === $landmark ? $this : $this->with(["landmark" => $landmark]);
    }

    /** @param string $text
     * @return MessageItem */
    function with_prefix($text) {
        if ($this->message !== "" && $text !== "") {
            $mi = clone $this;
            $mi->message = Ftext::concat($text, $mi->message);
            return $mi;
        } else {
            return $this;
        }
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
        if ($this->pos1 !== null && $this->context !== null) {
            $x["context"] = Ht::make_mark_substring($this->context, $this->pos1, $this->pos2);
        }
        return (object) $x;
    }

    /** @param ?string $msg
     * @return array{ok:false,message_list:list<MessageItem>} */
    static function make_error_json($msg) {
        return ["ok" => false, "message_list" => [new MessageItem(null, $msg ?? "", 2)]];
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function error($msg) {
        return new MessageItem(null, $msg, 2);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function warning($msg) {
        return new MessageItem(null, $msg, 1);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function success($msg) {
        return new MessageItem(null, $msg, MessageSet::SUCCESS);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function inform($msg) {
        return new MessageItem(null, $msg, MessageSet::INFORM);
    }
}

class MessageSet {
    /** @var list<MessageItem> */
    private $msgs = [];
    /** @var array<string,int> */
    private $errf = [];
    /** @var int */
    private $problem_status = 0;
    /** @var ?array<string,int> */
    private $pstatus_at;
    /** @var int */
    private $_ms_flags = 0;

    const IGNORE_MSGS = 1;
    const IGNORE_DUPS = 2;
    const WANT_FTEXT = 4;
    const DEFAULT_FTEXT_TEXT = 8;
    const DEFAULT_FTEXT_HTML = 16;

    const INFORM = -5;
    const WARNING_NOTE = -4;
    const SUCCESS = -3;
    const URGENT_NOTE = -2;
    const MARKED_NOTE = -1;
    const PLAIN = 0;
    const WARNING = 1;
    const ERROR = 2;
    const ESTOP = 3;

    /** @deprecated */
    const INFO = 0;
    /** @deprecated */
    const NOTE = -1;

    function __construct() {
    }

    function clear_messages() {
        $this->errf = $this->msgs = [];
        $this->problem_status = 0;
    }

    function clear() {
        $this->clear_messages();
    }

    /** @param int $clearf
     * @param int $wantf */
    private function change_ms_flags($clearf, $wantf) {
        $this->_ms_flags = ($this->_ms_flags & ~$clearf) | $wantf;
    }
    /** @param bool $x
     * @return bool */
    function swap_ignore_messages($x) {
        $oim = ($this->_ms_flags & self::IGNORE_MSGS) !== 0;
        $this->change_ms_flags(self::IGNORE_MSGS, $x ? self::IGNORE_MSGS : 0);
        return $oim;
    }
    /** @param bool $x
     * @return $this */
    function set_ignore_duplicates($x) {
        $this->change_ms_flags(self::IGNORE_DUPS, $x ? self::IGNORE_DUPS : 0);
        return $this;
    }
    /** @param bool $x
     * @param ?int $default_format
     * @return $this */
    function set_want_ftext($x, $default_format = null) {
        $this->change_ms_flags(self::WANT_FTEXT, $x ? self::WANT_FTEXT : 0);
        if ($x && $default_format !== null) {
            assert($default_format === 0 || $default_format === 5);
            $this->change_ms_flags(self::DEFAULT_FTEXT_TEXT | self::DEFAULT_FTEXT_HTML,
                                   $default_format === 0 ? self::DEFAULT_FTEXT_TEXT : self::DEFAULT_FTEXT_HTML);
        }
        return $this;
    }
    /** @param string $field
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status */
    function set_status_for_problem_at($field, $status) {
        $this->pstatus_at[$field] = $status;
    }
    /** @return void */
    function clear_status_for_problem_at() {
        $this->pstatus_at = [];
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

    /** @param int $pos
     * @param MessageItem $mi
     * @return MessageItem */
    function splice_item($pos, $mi) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            if ($mi->field !== null) {
                $old_status = $this->errf[$mi->field] ?? -5;
                $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
            } else {
                $old_status = $this->problem_status;
            }
            $this->problem_status = max($this->problem_status, $mi->status);
            if ($mi->message !== ""
                && (!($this->_ms_flags & self::IGNORE_DUPS)
                    || $old_status < $mi->status
                    || $this->message_index($mi) === false)) {
                if ($pos < 0 || $pos >= count($this->msgs)) {
                    $this->msgs[] = $mi;
                } else if ($pos === 0) {
                    array_unshift($this->msgs, $mi);
                } else {
                    array_splice($this->msgs, $pos, 0, [$mi]);
                }
                if (($this->_ms_flags & self::WANT_FTEXT)
                    && $mi->message !== ""
                    && !Ftext::is_ftext($mi->message)) {
                    error_log("not ftext: " . debug_string_backtrace());
                    if ($this->_ms_flags & self::DEFAULT_FTEXT_TEXT) {
                        $mi->message = "<0>{$mi->message}";
                    } else if ($this->_ms_flags & self::DEFAULT_FTEXT_HTML) {
                        $mi->message = "<5>{$mi->message}";
                    }
                }
            }
        }
        return $mi;
    }

    /** @param MessageItem $mi
     * @return MessageItem */
    function append_item($mi) {
        return $this->splice_item(-1, $mi);
    }

    /** @param ?string $field
     * @param MessageItem $mi
     * @return MessageItem */
    function append_item_at($field, $mi) {
        return $this->splice_item(-1, $mi->with_field($field));
    }

    /** @param iterable<MessageItem> $message_list */
    function append_list($message_list) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            foreach ($message_list as $mi) {
                $this->append_item($mi);
            }
        }
    }

    /** @param MessageSet $ms */
    function append_set($ms) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            foreach ($ms->msgs as $mi) {
                $this->append_item($mi);
            }
            foreach ($ms->errf as $field => $status) {
                $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
            }
        }
    }

    /** @param ?string $field
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function msg_at($field, $msg, $status) {
        assert($field !== false && $msg !== false);
        if ($field === "") {
            $field = null;
        }
        return $this->append_item(new MessageItem($field, $msg ?? "", $status));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function estop_at($field, $msg = null) {
        return $this->msg_at($field, $msg, self::ESTOP);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function error_at($field, $msg = null) {
        return $this->msg_at($field, $msg, self::ERROR);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function warning_at($field, $msg = null) {
        return $this->msg_at($field, $msg, self::WARNING);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @param null|0|1|2|3 $default_status
     * @return MessageItem */
    function problem_at($field, $msg = null, $default_status = 1) {
        $status = $this->pstatus_at[$field] ?? $default_status ?? 1;
        return $this->msg_at($field, $msg, $status);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function inform_at($field, $msg) {
        return $this->msg_at($field, $msg, self::INFORM);
    }

    /** @param ?string $msg
     * @return MessageItem */
    function success($msg) {
        return $this->msg_at(null, $msg, self::SUCCESS);
    }

    /** @param int $pos
     * @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function splice_msg($pos, $msg, $status) {
        return $this->splice_item($pos, new MessageItem(null, $msg, $status));
    }

    /** @param ?string $msg
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status
     * @return MessageItem */
    function prepend_msg($msg, $status) {
        return $this->splice_item(0, new MessageItem(null, $msg, $status));
    }

    /** @param MessageItem $mi
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status */
    function change_item_status($mi, $status) {
        if ($mi->status <= 0 || $status > $mi->status) {
            $mi->status = $status;
            if (!($this->_ms_flags & self::IGNORE_MSGS)) {
                if ($mi->field !== null) {
                    $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
                }
                $this->problem_status = max($this->problem_status, $mi->status);
            }
        }
    }


    /** @return bool */
    function has_message() {
        return !empty($this->msgs);
    }
    /** @return ?MessageItem */
    function back_message() {
        return empty($this->msgs) ? null : $this->msgs[count($this->msgs) - 1];
    }
    /** @return int */
    function message_count() {
        return count($this->msgs);
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
     * @return bool */
    function has_message_at($field) {
        return isset($this->errf[$field]);
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
    function has_problem_at($field) {
        return $this->problem_status >= self::WARNING
            && ($this->errf[$field] ?? 0) >= self::WARNING;
    }
    /** @param string $field
     * @return bool */
    function has_error_at($field) {
        return $this->problem_status >= self::ERROR
            && ($this->errf[$field] ?? 0) >= self::ERROR;
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
        } else if ($status === self::MARKED_NOTE) {
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
            foreach ($this->msgs as $mi) {
                if ($mi->status >= $min_status) {
                    yield $mi;
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
    /** @return \Generator<MessageItem> */
    function error_list() {
        return $this->min_status_list(self::ERROR);
    }
    /** @return \Generator<MessageItem> */
    function problem_list() {
        return $this->min_status_list(self::WARNING);
    }
    /** @param string $field
     * @return \Generator<MessageItem> */
    function message_list_at($field) {
        if (isset($this->errf[$field])) {
            foreach ($this->msgs as $mi) {
                if ($mi->field === $field) {
                    yield $mi;
                }
            }
        }
    }


    /** @param iterable<MessageItem> $message_list
     * @param callable(MessageItem):(MessageItem|list<MessageItem>) $function
     * @return Generator<MessageItem> */
    static function map($message_list, $function) {
        foreach ($message_list as $mi) {
            $mix = $function($mi) ?? $mi;
            if (is_array($mix)) {
                foreach ($mix as $nmi) {
                    yield $nmi;
                }
            } else {
                yield $mix;
            }
        }
    }

    /** @param iterable<MessageItem> $message_list
     * @return int */
    static function list_status($message_list) {
        $status = 0;
        foreach ($message_list as $mi) {
            if ($mi->status === self::SUCCESS && $status === 0) {
                $status = self::SUCCESS;
            } else if ($mi->status > 0 && $mi->status > $status) {
                $status = $mi->status;
            }
        }
        return $status;
    }


    /** @param iterable<MessageItem> $message_list
     * @return list<string> */
    static function feedback_html_items($message_list) {
        $ts = [];
        $t = "";
        foreach ($message_list as $mi) {
            if ($mi->message !== "") {
                $s = $mi->message_as(5);
                if ($mi->landmark !== null && $mi->landmark !== "") {
                    $lm = htmlspecialchars($mi->landmark);
                    $s = "<span class=\"lineno\">{$lm}:</span> {$s}";
                }
                if ($mi->status !== self::INFORM) {
                    if ($t !== "") {
                        $ts[] = $t;
                    }
                    $k = self::status_class($mi->status, "is-diagnostic", "is-");
                    $t = "<div class=\"{$k}\">{$s}</div>";
                } else {
                    $t .= "<div class=\"msg-inform\">{$s}</div>";
                }
                if ($mi->pos1 !== null && $mi->context !== null) {
                    $mark = Ht::mark_substring($mi->context, $mi->pos1, $mi->pos2, $mi->status);
                    $t .= "<div class=\"msg-context\">{$mark}</div>";
                }
            }
        }
        if ($t !== "") {
            $ts[] = $t;
        }
        return $ts;
    }

    /** @param iterable<MessageItem> $message_list
     * @return string */
    static function feedback_html($message_list) {
        $t = join("</li><li>", self::feedback_html_items($message_list));
        return $t !== "" ? "<ul class=\"feedback-list\"><li>{$t}</li></ul>" : "";
    }

    /** @param string $field
     * @return string */
    function feedback_html_at($field) {
        return self::feedback_html($this->message_list_at($field));
    }

    /** @return string */
    function full_feedback_html() {
        return self::feedback_html($this->message_list());
    }

    /** @param iterable<MessageItem> $message_list
     * @return string */
    static function feedback_text($message_list) {
        $t = [];
        foreach ($message_list as $mi) {
            if ($mi->message !== "") {
                if (!empty($t) && $mi->status === self::INFORM) {
                    $t[] = "    ";
                }
                if ($mi->landmark !== null && $mi->landmark !== "") {
                    $t[] = "{$mi->landmark}: ";
                }
                $t[] = $mi->message_as(0);
                $t[] = "\n";
                if ($mi->pos1 !== null && $mi->context !== null) {
                    $t[] = Ht::mark_substring_text($mi->context, $mi->pos1, $mi->pos2, "    ");
                }
            }
        }
        return empty($t) ? "" : join("", $t);
    }

    /** @param string $field
     * @return string */
    function feedback_text_at($field) {
        return self::feedback_text($this->message_list_at($field));
    }

    /** @return string */
    function full_feedback_text() {
        return self::feedback_text($this->message_list());
    }
}
