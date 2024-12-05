<?php
// jsonparser.php -- HotCRP JSON parser with position tracking support
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

const JSON_ERROR_EMPTY_KEY = 100;
const JSON_ERROR_TRAILING_COMMA = 101;
if (!defined("JSON_OBJECT_AS_ARRAY")) {
    define("JSON_OBJECT_AS_ARRAY", 1);
}
if (!defined("JSON_THROW_ON_ERROR")) {
    define("JSON_THROW_ON_ERROR", 1<<22);
}
if (!defined("JSON_ERROR_UTF16")) {
    define("JSON_ERROR_UTF16", 10);
}

class JsonParser {
    /** @var ?string */
    public $input;
    /** @var int */
    public $error_type = 0;
    /** @var int */
    public $error_pos = 0;
    /** @var ?string */
    public $filename;

    /** @var int */
    private $pos = 0;
    /** @var bool */
    private $assoc = false;
    /** @var int */
    private $maxdepth = 512;
    /** @var int */
    private $flags = 0;

    /** @var string
     * @readonly */
    static private $escapestr = "\x08...\x0C.......\x0A...\x0D.\x09";
    /** @var list<int>
     * @readonly */
    static private $json5_additional_whitespace = [
        0x0B, 0x0C, 0xA0, 0x2028, 0x2029, 0xFEFF,
        // Zs category as of 2023
        0x1680, 0x2000, 0x2001, 0x2002, 0x2003,
        0x2004, 0x2005, 0x2006, 0x2007, 0x2008,
        0x2009, 0x200A, 0x202F, 0x205F, 0x3000
    ];

    /** @readonly */
    static public $error_messages = [
        JSON_ERROR_NONE => null,
        JSON_ERROR_DEPTH => "Maximum stack depth exceeded",
        JSON_ERROR_STATE_MISMATCH => "Underflow or the modes mismatch",
        JSON_ERROR_CTRL_CHAR => "Unexpected control character found",
        JSON_ERROR_SYNTAX => "Syntax error, malformed JSON",
        JSON_ERROR_UTF8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
        JSON_ERROR_EMPTY_KEY => "Empty keys are not supported",
        JSON_ERROR_UTF16 => "Single unpaired UTF-16 surrogate in unicode escape",
        JSON_ERROR_TRAILING_COMMA => "Trailing commas are not supported"
    ];

    const JSON_EXTENDED_WHITESPACE = 1 << 19;
    const JSON5 = 1 << 18;

    const CTX_TOP = 0;
    const CTX_OBJECT_KEY = 1;
    const CTX_OBJECT_VALUE = 2;
    const CTX_ARRAY_ELEMENT = 3;


    /** @param ?string $input
     * @param ?bool $assoc
     * @param int $maxdepth
     * @param int $flags */
    function __construct($input = null, $assoc = null, $maxdepth = 512, $flags = 0) {
        $this->input = $input;
        $this->assoc = $assoc;
        $this->maxdepth = $maxdepth;
        $this->flags = $flags;
    }

    /** @param ?string $input
     * @return $this
     * @deprecated */
    function input($input) {
        return $this->set_input($input);
    }

    /** @param ?bool $assoc
     * @param int $maxdepth
     * @param int $flags
     * @return $this
     * @deprecated */
    function params($assoc = null, $maxdepth = 512, $flags = 0) {
        return $this->set_params($assoc, $maxdepth, $flags);
    }

    /** @param ?bool $assoc
     * @return $this
     * @deprecated */
    function assoc($assoc = null) {
        return $this->set_assoc($assoc);
    }

    /** @param int $maxdepth
     * @return $this
     * @deprecated */
    function maxdepth($maxdepth = 512) {
        return $this->set_maxdepth($maxdepth);
    }

    /** @param int $flags
     * @return $this
     * @deprecated */
    function flags($flags = 0) {
        return $this->set_flags($flags);
    }

    /** @param ?string $filename
     * @return $this
     * @deprecated */
    function filename($filename) {
        return $this->set_filename($filename);
    }

    /** @param ?string $input
     * @return $this */
    function set_input($input) {
        $this->input = $input;
        $this->error_type = 0;
        $this->error_pos = 0;
        return $this;
    }

    /** @param ?bool $assoc
     * @param int $maxdepth
     * @param int $flags
     * @return $this */
    function set_params($assoc = null, $maxdepth = 512, $flags = 0) {
        $this->assoc = $assoc;
        $this->maxdepth = $maxdepth;
        $this->flags = $flags;
        return $this;
    }

    /** @param ?bool $assoc
     * @return $this */
    function set_assoc($assoc = null) {
        $this->assoc = $assoc;
        return $this;
    }

    /** @param int $maxdepth
     * @return $this */
    function set_maxdepth($maxdepth = 512) {
        $this->maxdepth = $maxdepth;
        return $this;
    }

    /** @param int $flags
     * @return $this */
    function set_flags($flags = 0) {
        $this->flags = $flags;
        return $this;
    }

    /** @param ?string $filename
     * @return $this */
    function set_filename($filename) {
        $this->filename = $filename;
        return $this;
    }

    /** @return bool */
    function has_filename() {
        return ($this->filename ?? "") !== "";
    }


    /** @param int $pos
     * @param int $etype
     * @return null */
    private function set_error($pos, $etype) {
        if (($this->flags & JSON_THROW_ON_ERROR) !== 0) {
            throw new JsonException(self::$error_messages[$etype], $etype);
        }
        $this->error_type = $etype;
        $this->error_pos = $pos;
        return null;
    }


    /** @param string $s
     * @param int $pos
     * @param int $flags
     * @return array{string,int} */
    static private function extract_potential_string($s, $pos, $flags) {
        if (($flags & self::JSON5) !== 0) {
            preg_match('/\G(?:[^\\\\' . $s[$pos] . '\n\r]|\\\\[^\r]|\\\\\r\n?+)*+/', $s, $m, 0, $pos + 1);
        } else {
            preg_match('/\G(?:[^\\\\"\n\r]|\\\\[^\n\r])*+/', $s, $m, 0, $pos + 1);
        }
        $pos2 = $pos + 1 + strlen($m[0]);
        if ($pos2 !== strlen($s) && $s[$pos2] === $s[$pos]) {
            ++$pos2;
        }
        return [$m[0], $pos2];
    }

    /** @param string $s
     * @param int $pos
     * @param int $flags
     * @return array{int,int|string} */
    static private function decode_escape($s, $pos, $flags) {
        if ($pos + 1 === strlen($s)) {
            return [$pos + 1, JSON_ERROR_SYNTAX];
        }
        $ch = ord($s[$pos + 1]);
        if ($ch === 117    // `u`
            && $pos + 6 <= strlen($s)
            && ctype_xdigit(substr($s, $pos + 2, 4))) {
            $v = intval(substr($s, $pos + 2, 4), 16);
            $pos += 6;
            if ($v >= 0xD800
                && $v < 0xDC00
                && $pos + 6 <= strlen($s)
                && $s[$pos] === "\\"
                && $s[$pos + 1] === "u"
                && ctype_xdigit(substr($s, $pos + 2, 4))
                && ($v1 = intval(substr($s, $pos + 2, 4), 16)) >= 0xDC00
                && $v1 < 0xE000) {
                $v = (($v - 0xD800) << 10) | ($v1 - 0xDC00);
                $pos += 6;
            }
            if ($v < 0xD800 || $v >= 0xE000) {
                $o = UnicodeHelper::utf8_chr($v);
            } else {
                $o = JSON_ERROR_UTF16;
            }
        } else {
            $pos += 2;
            if ($ch >= 98                     // `b`
                && $ch <= 116                 // `t`
                && self::$escapestr[$ch - 98] !== ".") {
                $o = self::$escapestr[$ch - 98];
            } else if ($ch === 34             // `"`
                       || $ch === 92          // `\`
                       || $ch === 47) {       // `/`
                $o = chr($ch);
            } else if (($flags & self::JSON5) !== 0) {
                if ($ch === 118) {            // `v`
                    $o = "\x0B";
                } else if ($ch === 48) {
                    if ($pos === strlen($s)
                        || ($ch1 = ord($s[$pos])) < 49
                        || $ch1 > 57) {
                        $o = "\x00";
                    } else {
                        $o = JSON_ERROR_SYNTAX;
                    }
                } else if ($ch === 10         // `\n`
                           || $ch === 13      // `\r`
                           || $ch === 0x2028
                           || $ch === 0x2029) {
                    if ($ch === 13
                        && $pos !== strlen($s)
                        && $s[$pos + 1] === "\n") {
                        ++$pos;
                    }
                    $o = "";
                } else if ($ch < 49 || $ch > 57) {
                    $o = chr($ch);
                } else {
                    $o = JSON_ERROR_SYNTAX;
                }
            } else {
                $o = JSON_ERROR_SYNTAX;
            }
        }
        return [$pos, $o];
    }

    /** @param string $s
     * @param int $flags
     * @param ?callable(int,int):void $errorf
     * @param int $pos1
     * @return string */
    static private function decode_potential_string($s, $flags = 0, $errorf = null, $pos1 = 0) {
        if ($errorf
            && ($flags & self::JSON5) === 0
            && preg_match('/[\000-\037]/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $errorf($pos1 + $m[0][1], JSON_ERROR_SYNTAX);
        }
        $x = "";
        $bs = 0;
        while (true) {
            $bs1 = strpos($s, "\\", $bs);
            if ($bs1 === false) {
                return $bs === 0 ? $s : $x . substr($s, $bs);
            }
            list($bs2, $esc) = self::decode_escape($s, $bs1, $flags);
            $x .= substr($s, $bs, $bs1 - $bs);
            if (is_string($esc)) {
                $x .= $esc;
            } else if ($errorf) {
                $errorf($pos1 + $bs1, $esc);
            }
            $bs = $bs2;
        }
    }

    /** @param int $pos
     * @return int */
    private function skip_space($pos) {
        $s = $this->input;
        $len = strlen($s);
        while ($pos !== $len) {
            $ch = ord($s[$pos]);
            if ($ch === 32       // ` `
                || $ch === 10    // `\n`
                || $ch === 13    // `\r`
                || $ch === 9) {  // `\t`
                ++$pos;
                continue;
            }
            if (($this->flags & self::JSON5) !== 0
                && $ch === 47   // `/`
                && $pos + 1 < $len) {
                if ($s[$pos + 1] === "/") {
                    $pos += 2;
                    while ($pos !== $len) {
                        $ch = ord($s[$pos]);
                        ++$pos;
                        if ($ch === 0x0A || $ch === 0x0D) {
                            break;
                        } else if ($ch === 0xE2 && $pos + 1 < $len) {
                            if ($s[$pos] === "\x80"
                                && ($s[$pos + 1] === "\xA8" || $s[$pos + 1] === "\xA9")) {
                                $pos += 2;
                                break;
                            }
                        }
                    }
                    continue;
                } else if ($s[$pos + 1] === "*") {
                    $p = strpos($s, "*/", $pos + 2);
                    if ($p !== false) {
                        $pos = $p + 2;
                        continue;
                    }
                }
            }
            if (($this->flags & (self::JSON5 | self::JSON_EXTENDED_WHITESPACE)) !== 0
                && ($ch < 0x20 || $ch >= 0xC2)) {
                $ch = UnicodeHelper::utf8_ord($s, $pos);
                if (in_array($ch, self::$json5_additional_whitespace)) {
                    $pos += UnicodeHelper::utf8_chrlen($ch);
                    continue;
                }
            }
            break;
        }
        return $pos;
    }

    /** @param int $depth
     * @param 0|1|2|3 $context
     * @return mixed */
    private function decode_part($depth, $context) {
        $s = $this->input;
        $len = strlen($s);
        $pos = $this->skip_space($this->pos);
        if ($pos === $len) {
            return $this->set_error($pos, JSON_ERROR_SYNTAX);
        }
        $ch = ord($s[$pos]);
        if ($context === self::CTX_OBJECT_KEY
            && ($this->flags & self::JSON5) !== 0
            && ($ch === 36 || ($ch >= 65 && $ch <= 90) || $ch === 95 || ($ch >= 97 && $ch <= 122))) {
            // XXX only support the ASCII subset of JSON5 identifier syntax
            preg_match('/\G[$_A-Za-z0-9]*+/', $s, $m, 0, $pos);
            $this->pos = $pos + strlen($m[0]);
            return $m[0];
        } else if ($ch === 110      // `n`
                   && $pos + 4 <= $len
                   && $s[$pos + 1] === "u"
                   && $s[$pos + 2] === "l"
                   && $s[$pos + 3] === "l") {
            $this->pos = $pos + 4;
            return null;
        } else if ($ch === 102      // `f`
                   && $pos + 5 <= $len
                   && $s[$pos + 1] === "a"
                   && $s[$pos + 2] === "l"
                   && $s[$pos + 3] === "s"
                   && $s[$pos + 4] === "e") {
            $this->pos = $pos + 5;
            return false;
        } else if ($ch === 116      // `t`
                   && $pos + 4 <= $len
                   && $s[$pos + 1] === "r"
                   && $s[$pos + 2] === "u"
                   && $s[$pos + 3] === "e") {
            $this->pos = $pos + 4;
            return true;
        } else if ($ch === 34      // `"`
                   || ($ch === 39 && ($this->flags & self::JSON5) !== 0)) {    // `'`
            list($str, $this->pos) = self::extract_potential_string($s, $pos, $this->flags);
            $s = self::decode_potential_string($str, $this->flags, [$this, "set_error"], $pos + 1);
            if ($this->pos !== $pos + strlen($str) + 2) {   // no close quote
                $this->set_error($this->pos, JSON_ERROR_SYNTAX);
            }
            return $this->error_type === 0 ? $s : null;
        } else if ($ch === 123) {    // `{`
            if ($depth > $this->maxdepth) {
                return $this->set_error($pos, JSON_ERROR_DEPTH);
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                $this->pos = $this->skip_space($this->pos);
                if ($this->pos !== $len) {
                    if ($s[$this->pos] === "}") {
                        ++$this->pos;
                        break;
                    } else if (!empty($arr)) {
                        if ($s[$this->pos] !== ",") {
                            return $this->set_error($this->pos, JSON_ERROR_SYNTAX);
                        }
                        ++$this->pos;
                    }
                }

                $keypos = $this->pos;
                $key = $this->decode_part($depth + 1, self::CTX_OBJECT_KEY);
                if ($this->error_type !== 0) {
                    if ($this->error_type === JSON_ERROR_TRAILING_COMMA
                        && ($this->flags & self::JSON5) !== 0) {
                        $this->pos = $this->error_pos;
                        $this->error_type = 0;
                        continue;
                    }
                    return null;
                } else if (!is_string($key)) {
                    return $this->set_error($keypos, JSON_ERROR_SYNTAX);
                } else if (!$this->assoc && $key === "") {
                    return $this->set_error($keypos, JSON_ERROR_EMPTY_KEY);
                } else if (!$this->assoc && $key[0] === "\0") {
                    return $this->set_error($keypos, JSON_ERROR_INVALID_PROPERTY_NAME);
                }

                $this->pos = $this->skip_space($this->pos);
                if ($this->pos === $len || $s[$this->pos] !== ":") {
                    return $this->set_error($this->pos, JSON_ERROR_SYNTAX);
                }
                ++$this->pos;

                $value = $this->decode_part($depth + 1, self::CTX_OBJECT_VALUE);
                if ($this->error_type !== 0) {
                    return null;
                }
                $arr[$key] = $value;
            }
            return $this->assoc ? $arr : (object) $arr;
        } else if ($ch === 91) {    // `[`
            if ($depth > $this->maxdepth) {
                return self::set_error($pos, JSON_ERROR_DEPTH);
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                $this->pos = $this->skip_space($this->pos);
                if ($this->pos !== $len) {
                    if ($s[$this->pos] === "]") {
                        ++$this->pos;
                        break;
                    } else if (!empty($arr)) {
                        if ($s[$this->pos] !== ",") {
                            return $this->set_error($this->pos, JSON_ERROR_SYNTAX);
                        }
                        ++$this->pos;
                    }
                }

                $value = $this->decode_part($depth + 1, self::CTX_ARRAY_ELEMENT);
                if ($this->error_type !== 0) {
                    if ($this->error_type === JSON_ERROR_TRAILING_COMMA
                        && ($this->flags & self::JSON5) !== 0) {
                        $this->pos = $this->error_pos;
                        $this->error_type = 0;
                        continue;
                    }
                    return null;
                }
                $arr[] = $value;
            }
            return $arr;
        } else if (($this->flags & self::JSON5) !== 0
                   && ($ch === 43 || $ch === 45 || ($ch >= 48 && $ch <= 57))    // `[-+0-9]`
                   && preg_match('/\G[-+]?(?:0[Xx][0-9a-fA-F]++(?!\.)|0|[1-9]\d*+|(?=\.))((?:\.\d*+)?(?:[Ee][-+]?\d++)?)/', $s, $m, 0, $pos)) {
            $this->pos = $pos + strlen($m[0]);
            return $m[1] === "" ? intval($m[0], 0) : floatval($m[0]);
        } else if (($this->flags & self::JSON5) !== 0
                   && ($ch === 43 || $ch === 45 || $ch === 73 || $ch === 78)    // `[-+IN]`
                   && preg_match('/\G[-+]?(Infinity|NaN)/', $s, $m, 0, $pos)) {
            $this->pos = $pos + strlen($m[0]);
            if ($m[1] === "Infinity") {
                return $m[0][0] === "-" ? -INF : INF;
            } else {
                return $m[0][0] === "-" ? -NAN : NAN;
            }
        } else if (($ch === 45 || ($ch >= 48 && $ch <= 57))    // `[-0-9]`
                   && preg_match('/\G-?(?:0|[1-9]\d*+)((?:\.\d++)?(?:[Ee][-+]?\d++)?)/', $s, $m, 0, $pos)) {
            $this->pos = $pos + strlen($m[0]);
            return $m[1] === "" ? intval($m[0]) : floatval($m[0]);
        } else if ($ch === 93) {     // `]`
            if ($context === self::CTX_ARRAY_ELEMENT) {
                return $this->set_error($pos, JSON_ERROR_TRAILING_COMMA);
            } else {
                return $this->set_error($pos, JSON_ERROR_STATE_MISMATCH);
            }
        } else if ($ch === 125) {    // `}`
            if ($context === self::CTX_OBJECT_KEY) {
                return $this->set_error($pos, JSON_ERROR_TRAILING_COMMA);
            } else {
                return $this->set_error($pos, JSON_ERROR_STATE_MISMATCH);
            }
        } else if ($ch < 32) {
            return $this->set_error($pos, JSON_ERROR_CTRL_CHAR);
        } else {
            return $this->set_error($pos, JSON_ERROR_SYNTAX);
        }
    }

    /** @return mixed */
    function decode() {
        $assoc = $this->assoc;
        if ($assoc === null) {
            $this->assoc = ($this->flags & JSON_OBJECT_AS_ARRAY) !== 0;
        }
        $this->error_type = 0;
        $this->error_pos = 0;
        $this->pos = 0;

        $result = $this->decode_part(0, self::CTX_TOP);

        $this->assoc = $assoc;
        $this->pos = $this->skip_space($this->pos);
        if ($this->error_type === 0 && $this->pos !== strlen($this->input)) {
            $this->set_error($this->pos, JSON_ERROR_STATE_MISMATCH);
        }
        if ($this->error_type === 0) {
            return $result;
        } else {
            return null;
        }
    }


    /** @param string $ch
     * @return bool */
    private function ctype_json_value_start($ch) {
        $ord = ord($ch);
        return $ord === 34    // `"` - string
            || $ord === 45 || ($ord >= 48 && $ord < 58) // `[-0-9]` - number */
            || $ord === 91    // `[` - array
            || $ord === 102   // `f` - false
            || $ord === 110   // `n` - null
            || $ord === 116   // `t` - true
            || $ord === 123   // `{` - object
            || (($this->flags & self::JSON5) !== 0
                && ($ord === 43          // `+` - number
                    || $ord === 73       // `I` - number (Infinity)
                    || $ord === 78));    // `N` - number (NaN)
    }


    /** @param int $pos
     * @return int */
    private function skip($pos) {
        $s = $this->input;
        $len = strlen($s);
        $depth = 0;
        while (true) {
            $pos = $this->skip_space($pos);
            if ($pos === $len) {
                return $pos;
            }
            $ch = ord($s[$pos]);
            if ($ch === 34) {    // `"`
                $pos = (self::extract_potential_string($s, $pos, $this->flags))[1];
            } else if ($ch === 123 || $ch === 91) {    // `{`, `[`
                ++$depth;
                ++$pos;
            } else if ($ch === 125 || $ch === 93) {    // `}`, `]`
                if ($depth !== 0) {
                    --$depth;
                }
                ++$pos;
            } else if (preg_match('/\G[-+.$0-9A-Z_a-z]++/', $s, $m, 0, $pos)) {
                $pos += strlen($m[0]);
            } else {
                ++$pos;
            }
            if ($depth === 0) {
                return $pos;
            }
        }
    }


    /** @param int $pos
     * @return \Generator<JsonParserPosition> */
    function member_positions($pos) {
        $s = $this->input;
        $len = strlen($s);
        $pos = $this->skip_space($pos);
        if ($pos === $len) {
            return;
        }
        $ch = ord($s[$pos]);
        if ($ch === 123) {    // `{`
            ++$pos;
            while (true) {
                $pos = $this->skip_space($pos);
                if ($pos === $len || $s[$pos] === "}") {
                    break;
                } else if ($s[$pos] === ",") {
                    ++$pos;
                    continue;
                } else if ($s[$pos] !== "\"") {
                    $pos = $this->skip($pos);
                    continue;
                }
                $kpos1 = $pos;
                list($key, $kpos2) = self::extract_potential_string($s, $pos, $this->flags);
                $pos = $kpos2;
                while ($pos !== $len && (ctype_space($s[$pos]) || $s[$pos] === ":")) {
                    ++$pos;
                }
                if ($pos !== $len && $this->ctype_json_value_start($s[$pos])) {
                    $vpos1 = $pos;
                    $pos = $this->skip($pos);
                    yield new JsonParserPosition(self::decode_potential_string($key, $this->flags), $kpos1, $kpos2, $vpos1, $pos);
                } else {
                    $pos = $this->skip($pos);
                }
            }
        } else if ($ch === 91) {    // `[`
            ++$pos;
            $key = 0;
            while (true) {
                $pos = $this->skip_space($pos);
                if ($pos === $len || $s[$pos] === "]") {
                    break;
                } else if ($s[$pos] === ",") {
                    ++$pos;
                    continue;
                }
                if ($this->ctype_json_value_start($s[$pos])) {
                    $vpos1 = $pos;
                    $pos = $this->skip($pos);
                    yield new JsonParserPosition($key, null, null, $vpos1, $pos);
                    ++$key;
                } else {
                    $pos = $this->skip($pos);
                }
            }
        } else if ($ch === 34) {    // `"`
            $pos2 = (self::extract_potential_string($s, $pos, $this->flags))[1];
            yield new JsonParserPosition(null, null, null, $pos, $pos2);
        } else if (preg_match('/\G[-+.$0-9A-Z_a-z]++/', $s, $m, 0, $pos)) {
            yield new JsonParserPosition(null, null, null, $pos, $pos + strlen($m[0]));
        }
    }


    /** @param int $pos
     * @return ?int */
    function position_line($pos) {
        if ($this->input !== null && $pos <= strlen($this->input)) {
            return 1 + preg_match_all('/\r\n?|\n/s', substr($this->input, 0, $pos));
        } else {
            return null;
        }
    }

    /** @param int $pos
     * @param bool $include_column
     * @return ?string */
    function position_landmark($pos, $include_column = true) {
        if ($this->input === null || $pos > strlen($this->input)) {
            return null;
        }
        $prefix = substr($this->input, 0, $pos);
        $line = 1 + preg_match_all('/\r\n?|\n/s', $prefix);
        $cr = strrpos($prefix, "\r");
        $nl = strrpos($prefix, "\n");
        $last_line = substr($prefix, max($cr === false ? 0 : $cr + 1, $nl === false ? 0 : $nl + 1));
        $column = 1 + preg_match_all('/./u', $last_line);
        if ($this->filename !== null) {
            return "{$this->filename}:{$line}" . ($include_column ? ":{$column}" : "");
        } else {
            return "line {$line}" . ($include_column ? ", column {$column}" : "");
        }
    }

    /** @param ?string $path
     * @param string|int $component
     * @return string */
    static function path_push($path, $component) {
        if ($path === null || $path === "") {
            $path = "\$";
        }
        if ($component === "") {
            return "{$path}[\"\"]";
        } else if (is_int($component)
                   || $component === "0"
                   || (ctype_digit($component) && !str_starts_with($component, "0"))) {
            return "{$path}[{$component}]";
        } else if (ctype_alnum($component) || preg_match('/\A\w+\z/', $component)) {
            return "{$path}.{$component}";
        }
        $component = preg_replace_callback('/["\/\000-\037\\\\]/', function ($m) {
            $ch = ord($m[0]);
            if ($ch === 8 || $ch === 9 || $ch === 10 || $ch === 13) {
                $s = "btnxxr";
                return "\\" . $s[$ch - 8];
            } else if ($ch < 32) {
                return sprintf("\\u%04X", $ch);
            } else {
                return "\\{$m[0]}";
            }
        }, $component);
        return "{$path}[\"{$component}\"]";
    }

    /** @param ?string $path
     * @return list<int|string> */
    static function path_split($path) {
        $ppos = 0;
        $plen = strlen($path ?? "");
        $a = [];
        while ($ppos !== $plen) {
            $ch = $path[$ppos];
            if (ctype_space($ch)) {
                for (++$ppos; $ppos !== $plen && ctype_space($path[$ppos]); ++$ppos) {
                }
            } else if (ctype_alnum($ch) || $ch === "_") {
                preg_match('/\G[a-zA-Z0-9_$]+/', $path, $m, 0, $ppos);
                $a[] = ctype_digit($m[0]) ? intval($m[0]) : $m[0];
                $ppos += strlen($m[0]);
            } else if ($ch === "\"") {
                $ppos1 = $ppos;
                list($component, $ppos) = self::extract_potential_string($path, $ppos, 0);
                $a[] = self::decode_potential_string($component);
            } else if ($ch === "." || $ch === "[" || $ch === "]"
                       || ($ch === "\$" && empty($a))) {
                ++$ppos;
            } else {
                throw new ErrorException("bad JSON path `{$path}`");
            }
        }
        return $a;
    }

    /** @param string $path
     * @return ?JsonParserPosition */
    function path_position($path) {
        $ipos = 0;
        $jpp = null;
        foreach (self::path_split($path) as $key) {
            $jpp = null;
            foreach ($this->member_positions($ipos) as $memp) {
                if ($memp->key !== null && (string) $memp->key === (string) $key) {
                    $jpp = $memp;
                    break;
                }
            }
            if (!$jpp) {
                return null;
            }
            $ipos = $jpp->vpos1;
        }
        if ($jpp !== null || $ipos !== 0) {
            return $jpp;
        }
        $ilen = strlen($this->input);
        while ($ipos !== $ilen && ctype_space($this->input[$ipos])) {
            ++$ipos;
        }
        $vpos2 = $this->skip($ipos);
        return new JsonParserPosition(null, null, null, $ipos, $vpos2);
    }

    /** @param string $path
     * @param bool $include_column
     * @return ?string */
    function path_landmark($path, $include_column = true) {
        $jpp = $this->path_position($path);
        return $jpp ? $this->position_landmark($jpp->vpos1, $include_column) : null;
    }


    /** @return bool */
    function ok() {
        return $this->error_type === 0;
    }

    /** @return int */
    function last_error() {
        return $this->error_type;
    }

    /** @return ?string */
    function last_error_msg() {
        if ($this->error_type === 0) {
            return null;
        }
        $msg = self::$error_messages[$this->error_type] ?? "Unknown error #{$this->error_type}";
        $msg .= " at character {$this->error_pos}";
        if (($lm = $this->position_landmark($this->error_pos)) !== null) {
            $msg .= ", {$lm}";
        }
        return $msg;
    }
}

class JsonParserPosition implements JsonSerializable {
    /** @var null|int|string */
    public $key;
    /** @var ?int */
    public $kpos1;
    /** @var ?int */
    public $kpos2;
    /** @var int */
    public $vpos1;
    /** @var int */
    public $vpos2;

    /** @param null|int|string $key
     * @param ?int $kpos1
     * @param ?int $kpos2
     * @param int $vpos1
     * @param int $vpos2 */
    function __construct($key, $kpos1, $kpos2, $vpos1, $vpos2) {
        $this->key = $key;
        $this->kpos1 = $kpos1;
        $this->kpos2 = $kpos2;
        $this->vpos1 = $vpos1;
        $this->vpos2 = $vpos2;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [$this->key, $this->kpos1, $this->kpos2, $this->vpos1, $this->vpos2];
    }
}
