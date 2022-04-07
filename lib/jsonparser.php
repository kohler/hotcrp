<?php
// jsonparser.php -- HotCRP JSON parser with position tracking support
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

const JSON_ERROR_EMPTY_KEY = 100;
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

    private $input_pos;

    /** @readonly */
    static private $escapestr = "\x08...\x0C.......\x0A...\x0D.\x09";

    /** @readonly */
    static public $error_messages = [
        JSON_ERROR_NONE => null,
        JSON_ERROR_DEPTH => "Maximum stack depth exceeded",
        JSON_ERROR_STATE_MISMATCH => "Underflow or the modes mismatch",
        JSON_ERROR_CTRL_CHAR => "Unexpected control character found",
        JSON_ERROR_SYNTAX => "Syntax error, malformed JSON",
        JSON_ERROR_UTF8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
        JSON_ERROR_EMPTY_KEY => "Empty keys are not supported",
        JSON_ERROR_UTF16 => "Single unpaired UTF-16 surrogate in unicode escape"
    ];


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
     * @return $this */
    function input($input) {
        $this->input = $input;
        $this->error_type = 0;
        $this->error_pos = 0;
        $this->input_pos = null;
        return $this;
    }

    /** @param ?bool $assoc
     * @param int $maxdepth
     * @param int $flags
     * @return $this */
    function params($assoc = null, $maxdepth = 512, $flags = 0) {
        $this->assoc = $assoc;
        $this->maxdepth = $maxdepth;
        $this->flags = $flags;
        return $this;
    }

    /** @param ?bool $assoc
     * @return $this */
    function assoc($assoc = null) {
        $this->assoc = $assoc;
        return $this;
    }

    /** @param int $maxdepth
     * @return $this */
    function maxdepth($maxdepth = 512) {
        $this->maxdepth = $maxdepth;
        return $this;
    }

    /** @param int $flags
     * @return $this */
    function flags($flags = 0) {
        $this->flags = $flags;
        return $this;
    }

    /** @param ?string $filename
     * @return $this */
    function filename($filename) {
        $this->filename = $filename;
        return $this;
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
     * @return string */
    static private function potential_string($s, $pos) {
        $len = strlen($s);
        $bs = strpos($s, "\\", $pos);
        $dq = strpos($s, "\"", $pos);
        $dq = $dq === false ? $len : $dq;
        while ($bs !== false && $bs < $dq) {
            if ($bs + 1 === $dq) {
                $dq = $bs + 2 >= $len ? false : strpos($s, "\"", $bs + 2);
                $dq = $dq === false ? $len : $dq;
            }
            $bs = $bs + 2 >= $len ? false : strpos($s, "\\", $bs + 2);
        }
        preg_match('/\A[^\000-\037]*/', substr($s, $pos, $dq - $pos), $m);
        return $m[0] ?? "";
    }

    /** @param string $x
     * @param int &$pos
     * @param bool $error
     * @return string */
    private function decode_escape($x, &$pos, $error) {
        if ($pos + 1 === strlen($x)) {
            if ($error) {
                $this->set_error($this->pos + $pos, JSON_ERROR_SYNTAX);
            }
            ++$pos;
            return "";
        }
        $ch = $x[$pos + 1];
        if ($ch === "u"
            && $pos + 6 <= strlen($x)
            && ctype_xdigit(substr($x, $pos + 2, 4))) {
            $v = intval(substr($x, $pos + 2, 4), 16);
            $pos += 6;
            if ($v >= 0xD800
                && $v < 0xDC00
                && $pos + 6 <= strlen($x)
                && $x[$pos] === "\\"
                && $x[$pos + 1] === "u"
                && ctype_xdigit(substr($x, $pos + 2, 4))
                && ($v1 = intval(substr($x, $pos + 2, 4), 16)) >= 0xDC00
                && $v1 < 0xE000) {
                $v = (($v - 0xD800) << 10) | ($v1 - 0xDC00);
                $pos += 6;
            } else if ($v >= 0xD800 && $v < 0xE000) {
                if ($error) {
                    $this->set_error($this->pos + $pos - 6, JSON_ERROR_UTF16);
                }
                $v = 0xFFFD;
            }
            if ($v < 0x80) {
                return chr($v);
            } else if ($v < 0x800) {
                return chr(0xC0 + ($v >> 6)) . chr(0x80 + ($v & 0x3F));
            } else if ($v < 0x10000) {
                return chr(0xE0 + ($v >> 12)) . chr(0x80 + (($v >> 6) & 0x3F))
                    . chr(0x80 + ($v & 0x3F));
            } else {
                return chr(0xF0 + ($v >> 18)) . chr(0x80 + (($v >> 12) & 0x3F))
                    . chr(0x80 + (($v >> 6) & 0x3F)) . chr(0x80 + ($v & 0x3F));
            }
        } else {
            $pos += 2;
            $n = ord($ch) - 98;
            if ($n >= 0 && $n <= 18 && self::$escapestr[$n] !== ".") {
                return self::$escapestr[$n];
            } else if ($ch === "\"" || $ch === "\\" || $ch === "/") {
                return $ch;
            } else {
                if ($error) {
                    $this->set_error($this->pos + $pos - 2, JSON_ERROR_SYNTAX);
                }
                return "";
            }
        }
    }

    /** @param string $x
     * @param bool $error
     * @return string */
    private function decode_string($x, $error) {
        $s = "";
        $bs = 0;
        while (true) {
            $bs1 = strpos($x, "\\", $bs);
            if ($bs1 === false) {
                return $bs === 0 ? $x : $s . substr($x, $bs);
            }
            $s .= substr($x, $bs, $bs1 - $bs) . $this->decode_escape($x, $bs1, $error);
            $bs = $bs1;
        }
    }

    /** @param int $depth
     * @return mixed */
    private function decode_part($depth) {
        $s = $this->input;
        $pos = $this->pos;
        $len = strlen($s);
        while ($pos !== $len && ctype_space($s[$pos])) {
            ++$pos;
        }
        if ($pos === $len) {
            return $this->set_error($pos, JSON_ERROR_SYNTAX);
        }
        $ch = $s[$pos];
        if ($ch === "n"
            && $pos + 4 <= $len
            && $s[$pos + 1] === "u"
            && $s[$pos + 2] === "l"
            && $s[$pos + 3] === "l") {
            $this->pos = $pos + 4;
            return null;
        } else if ($ch === "f"
                   && $pos + 5 <= $len
                   && $s[$pos + 1] === "a"
                   && $s[$pos + 2] === "l"
                   && $s[$pos + 3] === "s"
                   && $s[$pos + 4] === "e") {
            $this->pos = $pos + 5;
            return false;
        } else if ($ch === "t"
                   && $pos + 4 <= $len
                   && $s[$pos + 1] === "r"
                   && $s[$pos + 2] === "u"
                   && $s[$pos + 3] === "e") {
            $this->pos = $pos + 4;
            return true;
        } else if ($ch === "\"") {
            $this->pos = $pos + 1;
            $x = self::potential_string($this->input, $this->pos);
            $p = $this->decode_string($x, true);
            if ($this->error_type !== 0) {
                return null;
            }
            $this->pos += strlen($x);
            if ($this->pos === $len || $s[$this->pos] !== "\"") {
                return $this->set_error($this->pos, JSON_ERROR_SYNTAX);
            }
            ++$this->pos;
            return $p;
        } else if ($ch === "{") {
            if ($depth > $this->maxdepth) {
                return self::set_error($pos, JSON_ERROR_DEPTH);
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
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
                $key = $this->decode_part($depth + 1);
                if ($this->error_type !== 0) {
                    return null;
                } else if (!is_string($key)) {
                    return $this->set_error($keypos, JSON_ERROR_SYNTAX);
                } else if (!$this->assoc && $key === "") {
                    return $this->set_error($keypos, JSON_ERROR_EMPTY_KEY);
                } else if (!$this->assoc && $key[0] === "\0") {
                    return $this->set_error($keypos, JSON_ERROR_INVALID_PROPERTY_NAME);
                }

                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
                if ($this->pos === $len || $s[$this->pos] !== ":") {
                    return $this->set_error($this->pos, JSON_ERROR_SYNTAX);
                }
                ++$this->pos;

                $value = $this->decode_part($depth + 1);
                if ($this->error_type !== 0) {
                    return null;
                }
                $arr[$key] = $value;
            }
            return $this->assoc ? $arr : (object) $arr;
        } else if ($ch === "[") {
            if ($depth > $this->maxdepth) {
                return self::set_error($pos, JSON_ERROR_DEPTH);
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
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

                $value = $this->decode_part($depth + 1);
                if ($this->error_type !== 0) {
                    return null;
                }
                $arr[] = $value;
            }
            return $arr;
        } else if (($ch === "-" || ctype_digit($ch))
                   && preg_match('/\G(-?(?:0|[1-9]\d*))((?:\.\d+)?(?:[Ee][-+]?\d+)?)/', $s, $m, 0, $pos)) {
            $this->pos = $pos + strlen($m[0]);
            return $m[2] === "" ? intval($m[1]) : floatval($m[0]);
        } else if ($ch === "]" || $ch === "}") {
            return $this->set_error($pos, JSON_ERROR_STATE_MISMATCH);
        } else if (ord($ch) < 32) {
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

        $result = $this->decode_part(0);

        $this->assoc = $assoc;
        $s = $this->input;
        while ($this->pos !== strlen($s) && ctype_space($s[$this->pos])) {
            ++$this->pos;
        }
        if ($this->error_type === 0 && $this->pos !== strlen($s)) {
            $this->set_error($this->pos, JSON_ERROR_STATE_MISMATCH);
        }
        if ($this->error_type === 0) {
            return $result;
        } else {
            return null;
        }
    }


    /** @param int $pos
     * @return int */
    private function skip($pos) {
        $s = $this->input;
        $len = strlen($s);
        $depth = 0;
        while (true) {
            while ($pos !== $len && ctype_space($s[$pos])) {
                ++$pos;
            }
            if ($pos === $len) {
                return $pos;
            }
            $ch = $s[$pos];
            if ($ch === "\"") {
                $pos = min($len, $pos + 2 + strlen(self::potential_string($this->input, $pos + 1)));
            } else if ($ch === "{" || $ch === "[") {
                ++$depth;
                ++$pos;
            } else if (ctype_alnum($ch) || $ch === "-") {
                while ($pos !== $len
                       && (ctype_alnum($ch) || $ch === "-" || $ch === "+" || $ch === ".")) {
                    ++$pos;
                    if ($pos !== $len) {
                        $ch = $s[$pos];
                    }
                }
            } else if ($depth === 0) {
                return $pos;
            } else if ($ch === "}" || $ch === "]") {
                --$depth;
                ++$pos;
            } else if ($ch !== ":" && $ch !== ",") {
                ++$pos;
            }
            if ($depth === 0) {
                return $pos;
            }
        }
    }

    /** @param int $pos
     * @return int */
    private function skip_before_end($pos) {
        $pos1 = $this->skip($pos);
        if ($pos1 > $pos
            && $this->input[$pos1 - 1] === ($this->input[$pos] === "{" ? "}" : "]")) {
            return $pos1 - 1;
        } else {
            return $pos1;
        }
    }

    /** @param int $depth
     * @return mixed */
    private function decode_positions_part($depth) {
        $s = $this->input;
        $pos = $this->pos;
        $len = strlen($s);
        while ($pos !== $len && ctype_space($s[$pos])) {
            ++$pos;
        }
        if ($pos === $len) {
            $this->pos = $pos;
            return $pos;
        }
        $ch = $s[$pos];
        if ($ch === "n"
            && $pos + 4 <= $len
            && $s[$pos + 1] === "u"
            && $s[$pos + 2] === "l"
            && $s[$pos + 3] === "l") {
            $this->pos = $pos + 4;
            return $pos;
        } else if ($ch === "f"
                   && $pos + 5 <= $len
                   && $s[$pos + 1] === "a"
                   && $s[$pos + 2] === "l"
                   && $s[$pos + 3] === "s"
                   && $s[$pos + 4] === "e") {
            $this->pos = $pos + 5;
            return $pos;
        } else if ($ch === "t"
                   && $pos + 4 <= $len
                   && $s[$pos + 1] === "r"
                   && $s[$pos + 2] === "u"
                   && $s[$pos + 3] === "e") {
            $this->pos = $pos + 4;
            return $pos;
        } else if ($ch === "\"") {
            $epos = $pos + 1 + strlen(self::potential_string($this->input, $pos + 1));
            if ($epos === $len || $s[$epos] !== "\"") {
                $this->pos = $len;
            } else {
                $this->pos = $epos + 1;
            }
            return $pos;
        } else if ($ch === "{") {
            if ($depth > $this->maxdepth) {
                $this->pos = $this->skip($pos);
                return $pos;
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
                if ($this->pos !== $len) {
                    if ($s[$this->pos] === "}") {
                        ++$this->pos;
                        $arr["__LANDMARK__"] = $arr["__LANDMARK__"] ?? $pos;
                        return $this->assoc ? $arr : (object) $arr;
                    } else if (!empty($arr)) {
                        if ($s[$this->pos] !== ",") {
                            $this->pos = $this->skip_before_end($pos);
                            continue;
                        }
                        ++$this->pos;
                    }
                }

                $key = $this->decode_part($depth + 1);
                if (!is_string($key)
                    || (!$this->assoc && ($key === "" || $key[0] === "\0"))) {
                    $key = false;
                }

                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
                if ($this->pos === $len || $s[$this->pos] !== ":") {
                    $this->pos = $this->skip_before_end($pos);
                    continue;
                }
                ++$this->pos;

                $vpos = $this->decode_positions_part($depth + 1);
                if ($key !== false) {
                    $arr[$key] = $vpos;
                }
            }
        } else if ($ch === "[") {
            if ($depth > $this->maxdepth) {
                $this->pos = $this->skip($pos);
                return $pos;
            }
            $arr = [];
            $this->pos = $pos + 1;
            while (true) {
                while ($this->pos !== $len && ctype_space($s[$this->pos])) {
                    ++$this->pos;
                }
                if ($this->pos !== $len) {
                    if ($s[$this->pos] === "]") {
                        ++$this->pos;
                        return empty($arr) ? $pos : $arr;
                    } else if (!empty($arr)) {
                        if ($s[$this->pos] !== ",") {
                            $this->pos = $this->skip_before_end($pos);
                            continue;
                        }
                        ++$this->pos;
                    }
                }

                $arr[] = $this->decode_positions_part($depth + 1);
            }
        } else if (($ch === "-" || ctype_digit($ch))
                   && preg_match('/\G(-?(?:0|[1-9]\d*))((?:\.\d+)?(?:[Ee][-+]?\d+)?)/', $s, $m, 0, $pos)) {
            $this->pos = $pos + strlen($m[0]);
            return $pos;
        } else {
            $this->pos = $pos + 1;
            return $pos;
        }
    }

    /** @return mixed */
    function decode_positions() {
        $this->pos = 0;
        $assoc = $this->assoc;
        if ($assoc === null) {
            $this->assoc = ($this->flags & JSON_OBJECT_AS_ARRAY) !== 0;
        }

        $result = $this->decode_positions_part(0);

        $this->assoc = $assoc;
        return $result;
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
     * @return ?string */
    function position_landmark($pos) {
        if ($this->input !== null && $pos <= strlen($this->input)) {
            $prefix = substr($this->input, 0, $pos);
            $line = 1 + preg_match_all('/\r\n?|\n/s', $prefix);
            $cr = strrpos($prefix, "\r");
            $nl = strrpos($prefix, "\n");
            $last_line = substr($prefix, max($cr === false ? 0 : $cr + 1, $nl === false ? 0 : $nl + 1));
            $column = 1 + preg_match_all('/./u', $last_line);
            if ($this->filename !== null) {
                return "{$this->filename}:{$line}:{$column}";
            } else {
                return "line {$line}, column {$column}";
            }
        } else {
            return null;
        }
    }

    /** @param string $path
     * @return int */
    function path_position($path) {
        if ($this->input_pos === null) {
            $assoc = $this->assoc;
            $this->assoc = true;
            $this->input_pos = $this->decode_positions();
            $this->assoc = $assoc;
        }
        $pos = 0;
        $len = strlen($path);
        $cur = $this->input_pos;
        while (!is_int($cur)) {
            while ($pos !== $len && ctype_space($path[$pos])) {
                ++$pos;
            }
            if ($pos === $len) {
                break;
            } else if (ctype_alnum($path[$pos]) || $path[$pos] === "_" || $path[$pos] === "\$") {
                preg_match('/\G[a-zA-Z_0-9$]*/', $path, $m, 0, $pos);
                $key = $m[0];
                $pos += strlen($m[0]);
            } else if ($path[$pos] === "\"") {
                $x = self::potential_string($path, $pos + 1);
                $key = $this->decode_string($x, false);
                $pos = min($len, $pos + 2 + strlen($x));
            } else if ($path[$pos] === "." || $path[$pos] === "[" || $path[$pos] === "]") {
                ++$pos;
                continue;
            } else {
                break;
            }
            if (!array_key_exists($key, $cur)) {
                break;
            }
            $cur = $cur[$key];
        }
        return is_int($cur) ? $cur : ($cur["__LANDMARK__"] ?? $cur[0]);
    }

    /** @param string $path
     * @return string */
    function path_landmark($path) {
        return $this->position_landmark($this->path_position($path));
    }


    /** @return int */
    function last_error() {
        return $this->error_type;
    }

    /** @return ?string */
    function last_error_msg() {
        if ($this->error_type === 0) {
            return null;
        } else {
            $msg = self::$error_messages[$this->error_type] ?? "Unknown error #{$this->error_type}";
            $msg .= " at character {$this->error_pos}";
            if (($lm = $this->position_landmark($this->error_pos)) !== null) {
                $msg .= ", {$lm}";
            }
            return $msg;
        }
    }
}
