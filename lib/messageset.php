<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @param object $x
     * @return MessageItem */
    static function from_json($x) {
        // XXX context, pos1, pos2?
        return new MessageItem($x->field ?? null, $x->message ?? "", $x->status ?? 0);
    }

    /** @param int $format
     * @return string */
    function message_as($format) {
        return Ftext::as($format, $this->message);
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
        if (array_key_exists("pos1", $updates)) {
            $mi->pos1 = $updates["pos1"];
        } else if ($mi->pos1 !== null
                   && array_key_exists("pos_offset", $updates)) {
            $mi->pos1 += $updates["pos_offset"];
        }
        if (array_key_exists("pos2", $updates)) {
            $mi->pos2 = $updates["pos2"];
        } else if ($mi->pos2 !== null
                   && array_key_exists("pos_offset", $updates)) {
            $mi->pos2 += $updates["pos_offset"];
        }
        if (array_key_exists("context", $updates)) {
            $mi->context = $updates["context"];
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
        } else if ($this->pos1 !== null) {
            $x["pos1"] = $this->pos1;
            $x["pos2"] = $this->pos2;
        }
        return (object) $x;
    }

    /** @param ?string $msg
     * @return array{ok:false,message_list:list<MessageItem>}
     * @deprecated */
    static function make_error_json($msg) {
        return ["ok" => false, "message_list" => [new MessageItem(null, $msg ?? "", 2)]];
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function error($msg) {
        return new MessageItem(null, $msg, 2);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function error_at($field, $msg) {
        return new MessageItem($field, $msg, 2);
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
    static function plain($msg) {
        return new MessageItem(null, $msg, MessageSet::PLAIN);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function marked_note($msg) {
        return new MessageItem(null, $msg, MessageSet::MARKED_NOTE);
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
    const IGNORE_DUPS_FIELD = 6;
    const IGNORE_DUPS_FIELD_FLAG = 4;
    const WANT_FTEXT = 8;
    const DEFAULT_FTEXT_TEXT = 16;
    const DEFAULT_FTEXT_HTML = 32;

    // These numbers are stored in databases (e.g., PaperStorage.infoJson.cfmsg)
    // and should be changed only with great care.
    const INFORM = -5;
    const MARKED_NOTE = -4;
    const SUCCESS = -3;
    const WARNING_NOTE = -2;
    const URGENT_NOTE = -1;
    const PLAIN = 0;
    const WARNING = 1;
    const ERROR = 2;
    const ESTOP = 3;

    /** @deprecated */
    const INFO = 0;
    /** @deprecated */
    const NOTE = -1;

    /** @param 0|1|2|3|6|7 $flags */
    function __construct($flags = 0) {
        $this->_ms_flags = $flags;
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
    /** @param bool|0|2|6 $x
     * @return $this */
    function set_ignore_duplicates($x) {
        $f = is_bool($x) ? ($x ? self::IGNORE_DUPS : 0) : $x;
        $this->change_ms_flags(self::IGNORE_DUPS | self::IGNORE_DUPS_FIELD_FLAG, $f);
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
    function message_index($mi) {
        if ($this->problem_status < $mi->status) {
            return false;
        }
        $ignore_field = ($this->_ms_flags & self::IGNORE_DUPS_FIELD_FLAG) !== 0;
        if ($mi->field !== null
            && !$ignore_field
            && ($this->errf[$mi->field] ?? -5) < $mi->status) {
            return false;
        }
        foreach ($this->msgs as $i => $m) {
            if ($m->status === $mi->status
                && ($ignore_field || $m->field === $mi->field)
                && $m->message === $mi->message)
                return $i;
        }
        return false;
    }

    /** @param int $pos
     * @param MessageItem $mi
     * @return MessageItem */
    function splice_item($pos, $mi) {
        if (($this->_ms_flags & self::IGNORE_MSGS) !== 0) {
            return $mi;
        }
        if ($mi->message !== ""
            && ($this->_ms_flags & self::WANT_FTEXT) !== 0
            && !Ftext::is_ftext($mi->message)) {
            error_log("not ftext: " . debug_string_backtrace());
            if (($this->_ms_flags & self::DEFAULT_FTEXT_TEXT) !== 0) {
                $mi->message = "<0>{$mi->message}";
            } else if (($this->_ms_flags & self::DEFAULT_FTEXT_HTML) !== 0) {
                $mi->message = "<5>{$mi->message}";
            }
        }
        if (($mi->message !== ""
             || ($mi->context !== null && $mi->pos1 !== null))
            && (($this->_ms_flags & self::IGNORE_DUPS) === 0
                || $this->message_index($mi) === false)) {
            if ($pos < 0 || $pos >= count($this->msgs)) {
                $this->msgs[] = $mi;
            } else if ($pos === 0) {
                array_unshift($this->msgs, $mi);
            } else {
                array_splice($this->msgs, $pos, 0, [$mi]);
            }
        }
        if ($mi->field !== null) {
            $this->errf[$mi->field] = max($this->errf[$mi->field] ?? 0, $mi->status);
        }
        $this->problem_status = max($this->problem_status, $mi->status);
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

    /** @param MessageSet $ms
     * @return $this */
    function append_set($ms) {
        if (!($this->_ms_flags & self::IGNORE_MSGS)) {
            foreach ($ms->msgs as $mi) {
                $this->append_item($mi);
            }
            foreach ($ms->errf as $field => $status) {
                $this->errf[$field] = max($this->errf[$field] ?? 0, $status);
            }
        }
        return $this;
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
    /** @return bool */
    function has_success() {
        foreach ($this->msgs as $mi) {
            if ($mi->status === self::SUCCESS)
                return true;
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
            return $rest;
        }
        if ($rest !== "") {
            return "{$rest} {$prefix}{$sclass}";
        } else {
            return "{$prefix}{$sclass}";
        }
    }
    /** @param ?string|false $field
     * @param string $rest
     * @param string $prefix
     * @return string */
    function control_class($field, $rest = "", $prefix = "has-") {
        if ($field && ($st = $this->errf[$field] ?? 0) !== 0) {
            return self::status_class($st, $rest, $prefix);
        } else {
            return $rest;
        }
    }
    /** @param ?int $st1
     * @param int $st2
     * @return int */
    static function combine_status($st1, $st2) {
        if ($st1 === null
            || $st1 === $st2
            || ($st1 === 0 && $st2 !== self::INFORM)
            || ($st1 < $st2 && ($st2 !== 0 || $st1 === self::INFORM))) {
            return $st2;
        } else {
            return $st1;
        }
    }
    /** @param string $field_prefix
     * @param string $rest
     * @param string $prefix
     * @return string */
    function prefix_control_class($field_prefix, $rest = "", $prefix = "has-") {
        $gst = null;
        foreach ($this->errf as $field => $st) {
            if (str_starts_with($field, $field_prefix))
                $gst = self::combine_status($gst, $st);
        }
        return $gst ? self::status_class($gst, $rest, $prefix) : $rest;
    }
    /** @param string $field_suffix
     * @param string $rest
     * @param string $prefix
     * @return string */
    function suffix_control_class($field_suffix, $rest = "", $prefix = "has-") {
        $gst = null;
        foreach ($this->errf as $field => $st) {
            if (str_ends_with($field, $field_suffix))
                $gst = self::combine_status($gst, $st);
        }
        return $gst ? self::status_class($gst, $rest, $prefix) : $rest;
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
    /** @param string $pfx
     * @return \Generator<MessageItem> */
    function message_list_at_prefix($pfx) {
        foreach ($this->msgs as $mi) {
            if ($mi->field !== null && str_starts_with($mi->field, $pfx)) {
                yield $mi;
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
        $status = null;
        foreach ($message_list as $mi) {
            if ($mi->status === self::INFORM || $mi->status === self::PLAIN) {
                continue;
            }
            if ($status === null
                || ($status <= 0 && $mi->status === self::SUCCESS)
                || ($mi->status > 0 && $mi->status > $status)) {
                $status = $mi->status;
            }
        }
        return $status ?? 0;
    }


    /** @param iterable<MessageItem> $message_list
     * @return list<string> */
    static function feedback_html_items($message_list) {
        $ts = [];
        $t = "";
        $last_landmark = null;
        foreach ($message_list as $mi) {
            if ($mi->message === ""
                && ($mi->pos1 === null || $mi->context === null)) {
                continue;
            }
            $s = $mi->message_as(5);
            $pstart = $pstartclass = "";
            if (str_starts_with($s, "<p")) {
                if ($s[2] === ">") {
                    $pstart = "<p>";
                    $s = substr($s, 3);
                } else if (preg_match('/\A<p class="(.*?)">/', $s, $m)) {
                    $pstart = $m[0];
                    $pstartclass = "{$m[1]} ";
                    $s = substr($s, strlen($m[0]));
                }
            }
            if ($mi->landmark !== null
                && $mi->landmark !== ""
                && ($mi->status !== self::INFORM || $mi->landmark !== $last_landmark)) {
                $lmx = $mi->landmark;
                if (str_starts_with($lmx, "<5>")
                    && ($clmx = CleanHTML::basic_clean(substr($lmx, 3))) !== false) {
                    $lmx = $clmx;
                } else {
                    $lmx = htmlspecialchars($lmx);
                }
                if (str_ends_with($lmx, " ")) {
                    $lmx = rtrim($lmx);
                } else {
                    $lmx .= ":";
                }
                $lm = "<span class=\"lineno\">{$lmx}</span> ";
            } else {
                $lm = "";
            }
            if ($mi->status !== self::INFORM && $t !== "") {
                $ts[] = $t;
                $t = "";
            }
            if ($s === "") {
                // Do not report message
            } else if ($mi->status !== self::INFORM) {
                if ($pstart !== "") {
                    $pstart = "<p class=\"" . self::status_class($mi->status, "{$pstartclass}is-diagnostic", "is-") . "\">";
                    $k = "has-diagnostic";
                } else {
                    $k = self::status_class($mi->status, "is-diagnostic", "is-");
                }
                $t .= "<div class=\"{$k}\">{$pstart}{$lm}{$s}</div>";
            } else {
                $t .= "<div class=\"msg-inform\">{$pstart}{$lm}{$s}</div>";
            }
            if ($mi->pos1 !== null && $mi->context !== null) {
                $mark = Ht::mark_substring($mi->context, $mi->pos1, $mi->pos2, $mi->status);
                $lmx = $s === "" ? $lm : "";
                $t .= "<div class=\"msg-context\">{$lmx}{$mark}</div>";
            }
            if ($mi->status !== self::INFORM) {
                $last_landmark = $mi->landmark;
            }
        }
        if ($t !== "") {
            $ts[] = $t;
        }
        return $ts;
    }

    /** @param iterable<MessageItem> $message_list
     * @param ?array<string,mixed> $js
     * @return string */
    static function feedback_html($message_list, $js = null) {
        $items = self::feedback_html_items($message_list);
        if (empty($items)) {
            return "";
        }
        if (empty($js)) {
            $k = " class=\"feedback-list\"";
        } else {
            $js["class"] = Ht::add_tokens("feedback-list", $js["class"] ?? null);
            $k = Ht::extra($js);
        }
        return "<ul{$k}><li>" . join("</li><li>", $items) . "</li></ul>";
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
     * @param bool $include_fields
     * @return string */
    static function feedback_text($message_list, $include_fields = false) {
        $t = [];
        foreach ($message_list as $mi) {
            if ($mi->message !== "") {
                if (!empty($t) && $mi->status === self::INFORM) {
                    $t[] = "    ";
                }
                if ($mi->landmark !== null && $mi->landmark !== "") {
                    $t[] = "{$mi->landmark}: ";
                }
                if ($include_fields && $mi->field !== null) {
                    $t[] = "{$mi->field}: ";
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

    /** @param bool $include_fields
     * @return string */
    function full_feedback_text($include_fields = false) {
        return self::feedback_text($this->message_list(), $include_fields);
    }
}
