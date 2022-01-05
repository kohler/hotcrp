<?php
// searchsplitter.php -- HotCRP helper class for splitting search strings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchSplitter {
    /** @var string */
    private $str;
    /** @var bool */
    private $utf8q;
    /** @var int */
    public $pos = 0;
    /** @var int */
    private $len;
    /** @var int */
    public $last_pos = 0;

    /** @param string $str */
    function __construct($str) {
        $this->str = $str;
        $this->len = strlen($str);
        $this->utf8q = strpos($str, chr(0xE2)) !== false;
        $this->set_span_and_pos(0);
    }
    /** @return bool */
    function is_empty() {
        return $this->pos >= $this->len;
    }
    /** @return string */
    function rest() {
        return substr($this->str, $this->pos);
    }
    /** @return string */
    function shift_keyword() {
        if ($this->utf8q
            ? preg_match('/\G(?:[-_.a-zA-Z0-9]+|["“”][^"“”]+["“”]):/su', $this->str, $m, 0, $this->pos)
            : preg_match('/\G(?:[-_.a-zA-Z0-9]+|"[^"]+"):/s', $this->str, $m, 0, $this->pos)) {
            $this->set_span_and_pos(strlen($m[0]));
            return $this->utf8q ? preg_replace('/[“”]/u', '"', $m[0]) : $m[0];
        } else {
            return "";
        }
    }
    /** @param string $exceptions
     * @return string */
    function shift($exceptions = null) {
        if ($exceptions === null) {
            $exceptions = '\(\)\[\]';
        } else if ($exceptions !== "()" && $exceptions !== "") {
            $exceptions = preg_quote($exceptions);
        }
        if ($this->utf8q
            ? preg_match("/\\G(?:[\"“”][^\"“”]*(?:[\"“”]|\\z)|[^\"“”\\s{$exceptions}]*)*/su", $this->str, $m, 0, $this->pos)
            : preg_match("/\\G(?:\"[^\"]*(?:\"|\\z)|[^\"\\s{$exceptions}]*)*/s", $this->str, $m, 0, $this->pos)) {
            $this->set_span_and_pos(strlen($m[0]));
            return $this->utf8q ? preg_replace('/[“”]/u', '"', $m[0]) : $m[0];
        } else {
            $this->last_pos = $this->pos = $this->len;
            return "";
        }
    }
    /** @param string $str */
    function shift_past($str) {
        assert(substr_compare($this->str, $str, $this->pos, strlen($str)) === 0);
        $this->set_span_and_pos(strlen($str));
    }
    /** @return bool */
    function skip_whitespace() {
        $this->set_span_and_pos(0);
        return $this->pos < $this->len;
    }
    /** @param string $chars
     * @return bool */
    function skip_span($chars) {
        while ($this->pos < $this->len && strpos($chars, $this->str[$this->pos]) !== false) {
            ++$this->pos;
        }
        return $this->pos < $this->len;
    }
    /** @param ?string $endchars
     * @return string */
    function shift_balanced_parens($endchars = null) {
        $pos0 = $this->pos;
        $pos1 = self::span_balanced_parens($this->str, $pos0, $endchars);
        $this->set_span_and_pos($pos1 - $pos0);
        return substr($this->str, $pos0, $pos1 - $pos0);
    }
    /** @param string $re
     * @param list<string> &$m @phan-output-reference */
    function match($re, &$m = null) {
        return preg_match($re, $this->str, $m, 0, $this->pos);
    }
    /** @param string $substr */
    function starts_with($substr) {
        return substr_compare($this->str, $substr, $this->pos, strlen($substr)) === 0;
    }
    /** @param int $len */
    private function set_span_and_pos($len) {
        $this->last_pos = $this->pos = $this->pos + $len;
        if ($this->utf8q) {
            if (preg_match('/\G\s+/u', $this->str, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
            }
        } else {
            while ($this->pos < $this->len && ctype_space($this->str[$this->pos])) {
                ++$this->pos;
            }
        }
    }
    /** @param string $str
     * @param int $pos
     * @param ?string $endchars
     * @return int */
    static function span_balanced_parens($str, $pos = 0, $endchars = null) {
        $pstack = "";
        $plast = "";
        $quote = 0;
        $len = strlen($str);
        while ($pos < $len) {
            $ch = $str[$pos];
            // stop when done
            if ($plast === ""
                && !$quote
                && ($endchars === null ? ctype_space($ch) : strpos($endchars, $ch) !== false)) {
                break;
            }
            // translate “” -> "
            if ($ch === "\xE2"
                && $pos + 2 < $len
                && $str[$pos + 1] === "\x80"
                && (ord($str[$pos + 2]) & 0xFE) === 0x9C) {
                $ch = "\"";
                $pos += 2;
            }
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str)) {
                    ++$pos;
                } else if ($ch === "\"") {
                    $quote = 0;
                }
            } else if ($ch === "(") {
                $pstack .= $plast;
                $plast = ")";
            } else if ($ch === "[") {
                $pstack .= $plast;
                $plast = "]";
            } else if ($ch === "{") {
                $pstack .= $plast;
                $plast = "}";
            } else if ($ch === ")" || $ch === "]" || $ch === "}") {
                do {
                    $pcleared = $plast;
                    $plast = (string) substr($pstack, -1);
                    $pstack = (string) substr($pstack, 0, -1);
                } while ($ch !== $pcleared && $pcleared !== "");
                if ($pcleared === "") {
                    break;
                }
            } else if ($ch === "\"") {
                $quote = 1;
            }
            ++$pos;
        }
        return $pos;
    }
    /** @param string $s
     * @return list<string> */
    static function split_balanced_parens($s) {
        $splitter = new SearchSplitter($s);
        $w = [];
        while ($splitter->skip_whitespace()) {
            $w[] = $splitter->shift_balanced_parens();
        }
        return $w;
    }
}
