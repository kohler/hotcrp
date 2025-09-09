<?php
// searchexample.php -- HotCRP helper class for search examples
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class SearchExample {
    /** @var string
     * @readonly */
    private $text;
    /** @var ?string
     * @readonly */
    private $description;
    /** @var 0|1
     * @readonly */
    private $importance = 1;
    /** @var list<string|FmtArg> */
    private $args;
    /** @var ?list<MessageItem> */
    private $ml;

    const PRIMARY = 1;
    const SECONDARY = 0;

    /** @param string $text
     * @param string $description
     * @param string|FmtArg ...$args */
    function __construct($text, $description = "", ...$args) {
        $this->text = $text;
        $this->description = $description;
        $this->args = $args;
        if ($description !== "" && !Ftext::is_ftext($description)) {
            error_log(debug_string_backtrace());
        }
    }

    /** @param string $description
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_description($description) {
        $this->description = $description;
        return $this;
    }

    /** @param 0|1 $importance
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_importance($importance) {
        $this->importance = $importance;
        return $this;
    }

    /** @param MessageItem $mi
     * @return $this */
    function append_item($mi) {
        $this->ml[] = $mi;
        return $this;
    }

    /** @param FmtArg $fa
     * @return $this */
    function add_arg($fa) {
        $this->args[] = $fa;
        return $this;
    }

    /** @return string */
    function text() {
        return $this->text;
    }

    /** @return string */
    function fmt_text() {
        if (strpos($this->text, "{") === false) {
            return $this->text;
        }
        return Fmt::simple($this->text, ...$this->args());
    }

    /** @return string */
    function text_h_s9t() {
        $t = "<span class=\"s9t\">" . htmlspecialchars($this->text) . "</span>";
        $pos = 0;
        while (($lb = strpos($t, "{", $pos)) !== false
               && ($rb = strpos($t, "}", $lb + 1)) !== false) {
            $co = strpos($t, ":", $lb + 1);
            if ($co === false || $co > $rb) {
                $co = $rb;
            }
            $sfxlen = strlen($t) - $rb - 1;
            $t = substr($t, 0, $lb) . "<span class=\"s9ta\">"
                . substr($t, $lb + 1, $co - $lb - 1) . "</span>"
                . substr($t, $rb + 1);
            $pos = strlen($t) - $sfxlen;
        }
        return $t;
    }

    /** @return string */
    function description() {
        return $this->description;
    }

    /** @param ?Fmt|Conf $fmt
     * @return string */
    function fmt_description($fmt = null) {
        $fmt = $fmt ?? new Fmt;
        return $fmt->_($this->description, ...$this->args());
    }

    /** @return list<string|FmtArg> */
    function args() {
        return $this->args ?? [];
    }

    /** @param ?Fmt|Conf $fmt
     * @return list<MessageItem> */
    function fmt_message_list($fmt = null) {
        $fmt = $fmt ?? new Fmt;
        foreach ($this->ml ?? [] as $mi) {
            $mi->fmt($fmt, ...$this->args());
        }
        return $this->ml ?? [];
    }

    /** @return 0|1 */
    function importance() {
        return $this->importance;
    }

    /** @param list<SearchExample> &$exs
     * @param SearchExample $match
     * @return list<SearchExample> */
    static function remove_category(&$exs, $match) {
        $ret = [];
        for ($i = 0; $i !== count($exs); ) {
            if ($match->description && $exs[$i]->description === $match->description) {
                $ret[] = $exs[$i];
                array_splice($exs, $i, 1);
            } else {
                ++$i;
            }
        }
        return $ret;
    }
}
