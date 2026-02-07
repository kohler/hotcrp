<?php
// messageset.php -- HotCRP sets of messages by fields
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    /** @var bool */
    public $nested_context = false;
    /** @var null|int|string */
    public $landmark;
    /** @var ?string */
    public $fmessage;
    /** @var ?list<mixed> */
    public $args;

    /** @param int $status
     * @param ?string $field
     * @param string $m
     * @param mixed ...$args
     * @suppress PhanTypeMismatchProperty */
    function __construct($status, $field = null, $m = "", ...$args) {
        $this->status = $status;
        if (($field ?? "") !== "") {
            $this->field = $field;
        }
        if (!empty($args)) {
            $this->fmessage = $m ?? "";
            $this->args = $args;
        } else {
            $this->message = $m ?? "";
        }
    }

    /** @param object $x
     * @return MessageItem */
    static function from_json($x) {
        $mi = new MessageItem($x->status ?? 0, $x->field ?? null, $x->message ?? "");
        if (isset($x->pos1) && is_int($x->pos1)) {
            $mi->pos1 = $x->pos1;
        }
        if (isset($x->pos2) && is_int($x->pos2)) {
            $mi->pos2 = $x->pos2;
        }
        if (isset($x->landmark) && (is_int($x->landmark) || is_string($x->landmark))) {
            $mi->landmark = $x->landmark;
        }
        return $mi;
    }

    /** @return bool */
    function need_fmt() {
        return $this->fmessage !== null && $this->message === null;
    }

    /** @param Fmt $fmt
     * @param string|FmtArg ...$args
     * @return $this */
    function fmt($fmt, ...$args) {
        if ($this->fmessage !== null && $this->message === null) {
            $this->message = $fmt->_($this->fmessage, ...$this->args, ...$args);
        }
        return $this;
    }

    /** @param int $format
     * @return string */
    function message_as($format) {
        return Ftext::as($format, $this->message);
    }

    /** @param array{field?:?string,message?:string,status?:int,problem_status?:int,pos_offset?:int,top_pos_offset?:int,top_context?:?string} $updates
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
        } else if ($mi->pos1 !== null
                   && !$mi->nested_context
                   && array_key_exists("top_pos_offset", $updates)) {
            $mi->pos1 += $updates["top_pos_offset"];
        }
        if (array_key_exists("pos2", $updates)) {
            $mi->pos2 = $updates["pos2"];
        } else if ($mi->pos2 !== null
                   && array_key_exists("pos_offset", $updates)) {
            $mi->pos2 += $updates["pos_offset"];
        } else if ($mi->pos2 !== null
                   && !$mi->nested_context
                   && array_key_exists("top_pos_offset", $updates)) {
            $mi->pos2 += $updates["top_pos_offset"];
        }
        if (array_key_exists("context", $updates)) {
            $mi->context = $updates["context"];
        } else if (array_key_exists("top_context", $updates)
                   && !$mi->nested_context) {
            $mi->context = $updates["top_context"];
        }
        if (array_key_exists("nested_context", $updates)) {
            $mi->nested_context = $updates["nested_context"];
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
        if ($this->message === "" || ($text ?? "") === "") {
            return $this;
        }
        $mi = clone $this;
        $mi->message = Ftext::concat($text, $mi->message);
        return $mi;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = ["status" => $this->status];
        if ($this->field !== null) {
            $x["field"] = $this->field;
        }
        if ($this->message !== "") {
            $x["message"] = $this->message;
        }
        if ($this->pos1 !== null && $this->context !== null) {
            $x["context"] = Ht::make_mark_substring($this->context, $this->pos1, $this->pos2);
        } else if ($this->pos1 !== null) {
            $x["pos1"] = $this->pos1;
            $x["pos2"] = $this->pos2;
        }
        if ($this->landmark !== null) {
            $x["landmark"] = $this->landmark;
        }
        return (object) $x;
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function estop($msg, ...$args) {
        return new MessageItem(MessageSet::ESTOP, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function estop_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::ESTOP, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function error($msg, ...$args) {
        return new MessageItem(2, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function error_at($field, $msg = "", ...$args) {
        return new MessageItem(2, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function warning($msg, ...$args) {
        return new MessageItem(1, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function warning_at($field, $msg = "", ...$args) {
        return new MessageItem(1, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function success($msg, ...$args) {
        return new MessageItem(MessageSet::SUCCESS, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function success_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::SUCCESS, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function plain($msg, ...$args) {
        return new MessageItem(MessageSet::PLAIN, null, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function fplain($msg, ...$args) {
        if (empty($args)) {
            $args[] = FmtArg::blank();
        }
        return new MessageItem(MessageSet::PLAIN, null, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function marked_note($msg, ...$args) {
        return new MessageItem(MessageSet::MARKED_NOTE, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function marked_note_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::MARKED_NOTE, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function warning_note($msg, ...$args) {
        return new MessageItem(MessageSet::WARNING_NOTE, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function warning_note_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::WARNING_NOTE, $field, $msg, ...$args);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function urgent_note($msg, ...$args) {
        return new MessageItem(MessageSet::URGENT_NOTE, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function urgent_note_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::URGENT_NOTE, $field, $msg);
    }

    /** @param ?string $msg
     * @return MessageItem */
    static function inform($msg, ...$args) {
        return new MessageItem(MessageSet::INFORM, null, $msg, ...$args);
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    static function inform_at($field, $msg = "", ...$args) {
        return new MessageItem(MessageSet::INFORM, $field, $msg, ...$args);
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
    private $_ms_flags = 8 /* WANT_FTEXT */;

    const IGNORE_MSGS = 1;
    const IGNORE_DUPS = 2;
    const IGNORE_DUPS_FIELD = 6;
    const IGNORE_DUPS_FIELD_FLAG = 4;
    const WANT_FTEXT = 8;

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
    const MIN_STATUS = -5;
    const MAX_STATUS = 3;

    function clear_messages() {
        $this->errf = $this->msgs = [];
        $this->problem_status = 0;
    }

    /** @param int $message_count */
    function clear_messages_since($message_count) {
        assert($message_count >= 0 && $message_count <= count($this->msgs));
        if ($message_count < count($this->msgs)) {
            array_splice($this->msgs, $message_count);
            $this->errf = [];
            $this->problem_status = 0;
            foreach ($this->msgs as $mi) {
                $this->_account_item($mi);
            }
        }
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
     * @return $this */
    function set_want_ftext($x) {
        $this->change_ms_flags(self::WANT_FTEXT, $x ? self::WANT_FTEXT : 0);
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
                && $m->message === $mi->message
                && $m->fmessage === $mi->fmessage
                && $m->args === $mi->args)
                return $i;
        }
        return false;
    }

    /** @param MessageItem $mi */
    private function _account_item($mi) {
        if ($mi->field !== null) {
            $this->errf[$mi->field] = self::combine_status($this->errf[$mi->field] ?? 0, $mi->status);
        }
        $this->problem_status = max($this->problem_status, $mi->status);
    }

    /** @param int $pos
     * @param MessageItem $mi
     * @return MessageItem */
    function splice_item($pos, $mi) {
        if (($this->_ms_flags & self::IGNORE_MSGS) !== 0) {
            return $mi;
        }
        $mtext = $mi->message ?? $mi->fmessage;
        if ($mtext !== ""
            && ($this->_ms_flags & self::WANT_FTEXT) !== 0
            && !Ftext::is_ftext($mtext)) {
            error_log("not ftext: {$mtext} " . debug_string_backtrace());
            if (isset($mi->message)) {
                $mi->message = "<0>{$mtext}";
            } else {
                $mi->fmessage = "<0>{$mtext}";
            }
        }
        if (($this->_ms_flags & self::IGNORE_DUPS) === 0
            || $this->message_index($mi) === false) {
            if ($pos < 0 || $pos >= count($this->msgs)) {
                $this->msgs[] = $mi;
            } else if ($pos === 0) {
                array_unshift($this->msgs, $mi);
            } else {
                array_splice($this->msgs, $pos, 0, [$mi]);
            }
        }
        $this->_account_item($mi);
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

    /** @param MessageItem $mi
     * @return MessageItem */
    function prepend_item($mi) {
        return $this->splice_item(0, $mi);
    }

    /** @param iterable<MessageItem> $message_list */
    function append_list($message_list) {
        if (($this->_ms_flags & self::IGNORE_MSGS) !== 0) {
            return;
        }
        foreach ($message_list as $mi) {
            $this->append_item($mi);
        }
    }

    /** @param MessageSet $ms
     * @return $this */
    function append_set($ms) {
        if (($this->_ms_flags & self::IGNORE_MSGS) !== 0) {
            return $this;
        }
        foreach ($ms->msgs as $mi) {
            $this->append_item($mi);
        }
        return $this;
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function estop_at($field, $msg = null, ...$args) {
        return $this->append_item(new MessageItem(self::ESTOP, $field, $msg, ...$args));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function error_at($field, $msg = null, ...$args) {
        return $this->append_item(new MessageItem(self::ERROR, $field, $msg, ...$args));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function warning_at($field, $msg = null, ...$args) {
        return $this->append_item(new MessageItem(self::WARNING, $field, $msg, ...$args));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @param null|0|1|2|3 $default_status
     * @return MessageItem */
    function problem_at($field, $msg = null, $default_status = 1) {
        $status = $this->pstatus_at[$field] ?? $default_status ?? 1;
        return $this->append_item(new MessageItem($status, $field, $msg));
    }

    /** @param ?string $field
     * @param ?string $msg
     * @return MessageItem */
    function inform_at($field, $msg, ...$args) {
        return $this->append_item(new MessageItem(self::INFORM, $field, $msg, ...$args));
    }

    /** @param ?string $msg
     * @return MessageItem */
    function success($msg, ...$args) {
        return $this->append_item(new MessageItem(self::SUCCESS, null, $msg, ...$args));
    }

    /** @param MessageItem $mi
     * @param -5|-4|-3|-2|-1|0|1|2|3 $status */
    function change_item_status($mi, $status) {
        if ($mi->status <= 0 || $status > $mi->status) {
            $mi->status = $status;
            if (!($this->_ms_flags & self::IGNORE_MSGS)) {
                if ($mi->field !== null) {
                    $this->errf[$mi->field] = self::combine_status($this->errf[$mi->field] ?? 0, $mi->status);
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
    /** @return bool */
    function has_urgent_note() {
        foreach ($this->msgs as $mi) {
            if ($mi->status === self::URGENT_NOTE)
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
        if ($this->problem_status < self::WARNING) {
            return 0;
        }
        return max($this->errf[$field] ?? 0, 0);
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
    /** @param string $field
     * @return int */
    function status_at($field) {
        return $this->errf[$field] ?? 0;
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
        }
        return $rest;
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
        }
        return $st1;
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

    /** @param string $field
     * @return list<MessageItem> */
    function message_list_with_default_field($field) {
        $ml = [];
        foreach ($this->msgs as $mi) {
            if ($mi->field === null) {
                $mi = $mi->with_field($field);
            }
            $ml[] = $mi;
        }
        return $ml;
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

    /** @param MessageItem|iterable<MessageItem>|MessageSet ...$mls
     * @return list<MessageItem> */
    static function make_list(...$mls) {
        $mlx = [];
        foreach ($mls as $ml) {
            if ($ml instanceof MessageItem) {
                $mlx[] = $ml;
            } else if ($ml instanceof MessageSet) {
                array_push($mlx, ...$ml->message_list());
            } else if (!empty($ml)) {
                array_push($mlx, ...$ml);
            }
        }
        return $mlx;
    }

    /** @param Fmt|Conf $fmt
     * @param MessageItem|iterable<MessageItem>|MessageSet ...$mls
     * @return list<MessageItem> */
    static function make_fmt_list($fmt, ...$mls) {
        $mlx = self::make_list(...$mls);
        $xfmt = null;
        foreach ($mlx as $mi) {
            if ($mi->need_fmt()) {
                $xfmt = $xfmt ?? $fmt->fmt();
                $mi->fmt($xfmt);
            }
        }
        return $mlx;
    }

    /** @param Fmt|Conf $fmt
     * @return $this */
    function apply_fmt($fmt) {
        $xfmt = null;
        foreach ($this->msgs as $mi) {
            if ($mi->need_fmt()) {
                $xfmt = $xfmt ?? $fmt->fmt();
                $mi->fmt($xfmt);
            }
        }
        return $this;
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
     * @param array $change
     * @return list<MessageItem> */
    static function list_with($message_list, $change) {
        $ml = [];
        foreach ($message_list as $mi) {
            $ml[] = $mi->with($change);
        }
        return $ml;
    }


    /** @param iterable<MessageItem> $message_list
     * @return list<string> */
    static function feedback_html_items($message_list) {
        $ts = [];
        $t = $tcontext = "";
        $last_mi = $last_landmark = null;
        foreach ($message_list as $mi) {
            if ($mi->message === null) {
                if ($mi->fmessage !== null) {
                    error_log("unformatted message {$mi->fmessage} " . debug_string_backtrace());
                }
                continue;
            }

            if ($mi->message === ""
                && ($mi->pos1 === null || $mi->context === null)) {
                continue;
            }

            // render message
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

            // close previous message
            // (special case: avoid duplicate messages if adding context)
            if ($last_mi
                && $last_mi->status === $mi->status
                && $last_mi->message === $mi->message
                && ($last_mi->landmark ?? "") === ""
                && ($mi->landmark ?? "") === ""
                && $mi->pos1 !== null
                && $mi->context !== null) {
                $s = "";
            } else if ($mi->status !== self::INFORM
                       && ($t !== "" || $tcontext !== "")) {
                $ts[] = $t . $tcontext;
                $t = $tcontext = "";
            } else if ($mi->context !== null
                       && $mi->pos1 !== null
                       && $tcontext !== "") {
                $t .= $tcontext;
                $tcontext = "";
            }

            // render landmark
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

            // add message
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

            // add context
            if ($mi->context !== null
                && $mi->pos1 !== null) {
                $mark = Ht::mark_substring($mi->context, $mi->pos1, $mi->pos2, $mi->status);
                $lmx = $s === "" ? $lm : "";
                $tcontext = "<div class=\"msg-context\">{$lmx}{$mark}</div>";
            }

            // cleanup
            $last_mi = $mi;
            if ($mi->status !== self::INFORM) {
                $last_landmark = $mi->landmark;
            }
        }
        if ($t !== "" || $tcontext !== "") {
            $ts[] = $t . $tcontext;
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
     * @param ?array<string,mixed> $js
     * @return string */
    function feedback_html_at($field, $js = null) {
        return self::feedback_html($this->message_list_at($field), $js);
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
        $tcontext = "";
        foreach ($message_list as $mi) {
            if ($mi->message === null) {
                if ($mi->fmessage !== null) {
                    error_log("unformatted message {$mi->fmessage} " . debug_string_backtrace());
                }
                continue;
            }
            if ($mi->message === "") {
                continue;
            }
            if ($tcontext !== ""
                && ($mi->status !== MessageSet::INFORM
                    || ($mi->context !== null && $mi->pos1 !== null))) {
                $t[] = $tcontext;
                $tcontext = "";
            }
            $mt = $mi->message_as(0);
            if ($include_fields && $mi->field !== null) {
                $mt = "{$mi->field}: {$mt}";
            }
            if ($mi->landmark !== null && $mi->landmark !== "") {
                $mt = "{$mi->landmark}: {$mt}";
            }
            if (!empty($t) && $mi->status === self::INFORM) {
                $mt = "    " . str_replace("\n", "\n    ", $mt);
            }
            $t[] = rtrim($mt) . "\n";
            if ($mi->context !== null && $mi->pos1 !== null) {
                $tcontext = Ht::mark_substring_text($mi->context, $mi->pos1, $mi->pos2, "    ");
            }
        }
        if ($tcontext !== "") {
            $t[] = $tcontext;
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
