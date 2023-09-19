<?php
// json.php -- HotCRP JSON function replacements (if PHP JSON not available)
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Json {
    /** @var ?JsonParser */
    static private $json_parser;

    /** @readonly */
    static public $string_map = [
        "\\" => "\\\\", "\"" => "\\\"", "/" => "\\/",
        "\000" => "\\u0000", "\001" => "\\u0001", "\002" => "\\u0002",
        "\003" => "\\u0003", "\004" => "\\u0004", "\005" => "\\u0005",
        "\006" => "\\u0006", "\007" => "\\u0007", "\010" => "\\b",
        "\011" => "\\t", "\012" => "\\n", "\013" => "\\u000B",
        "\014" => "\\f", "\015" => "\\r", "\016" => "\\u000E",
        "\017" => "\\u000F", "\020" => "\\u0010", "\021" => "\\u0011",
        "\022" => "\\u0012", "\023" => "\\u0013", "\024" => "\\u0014",
        "\025" => "\\u0015", "\026" => "\\u0016", "\027" => "\\u0017",
        "\030" => "\\u0018", "\031" => "\\u0019", "\032" => "\\u001A",
        "\033" => "\\u001B", "\034" => "\\u001C", "\035" => "\\u001D",
        "\036" => "\\u001E", "\037" => "\\u001F",
        "\xE2\x80\xA8" => "\\u2028", "\xE2\x80\xA9" => "\\u2029"
    ];

    /** @param string $json
     * @param ?bool $assoc
     * @param int $depth
     * @param int $flags
     * @return ?mixed */
    static function decode($json, $assoc = null, $depth = 512, $flags = 0) {
        self::$json_parser = self::$json_parser ?? new JsonParser;
        $x = self::$json_parser->input($json)->params($assoc, $depth, $flags)->decode();
        if (self::$json_parser->error_type === 0) {
            // ensure storage is reclaimed
            self::$json_parser->input(null);
        }
        return $x;
    }


    /** @param string|list<string> $x
     * @return string */
    static function encode_escape($x) {
        if (is_array($x)) {
            $x = $x[0];
        }
        return self::$string_map[$x];
    }

    // XXX not a full emulation of json_encode(); hopefully that won't matter
    // in the fullness of time
    /** @param mixed $x
     * @param int $options
     * @return ?string */
    static function encode($x, $options = 0) {
        if ($x instanceof JsonSerializable) {
            $x = $x->jsonSerialize();
        }
        if ($x === null) {
            return "null";
        } else if ($x === false) {
            return "false";
        } else if ($x === true) {
            return "true";
        } else if (is_int($x) || is_float($x)) {
            return (string) $x;
        } else if (is_string($x)) {
            if ($options & JSON_UNESCAPED_SLASHES) {
                $pat = "{[\\\"\\x00-\\x1F]|\xE2\x80[\xA8\xA9]}";
            } else {
                $pat = "{[\\\"/\\x00-\\x1F]|\xE2\x80[\xA8\xA9]}";
            }
            return "\"" . preg_replace_callback($pat, "Json::encode_escape", $x) . "\"";
        } else if (is_object($x) || is_array($x)) {
            $as_object = null;
            $as_array = [];
            $nextkey = 0;
            foreach ($x as $k => $v) {
                if ((!is_int($k) && !is_string($k))
                    || ($v = self::encode($v, $options)) === null) {
                    continue;
                }
                if ($as_array !== null && $k !== $nextkey) {
                    $as_object = [];
                    foreach ($as_array as $kk => $vv) {
                        $as_object[] = "\"$kk\":$vv";
                    }
                    $as_array = null;
                }
                if ($as_array === null) {
                    $as_object[] = self::encode((string) $k) . ":" . $v;
                } else {
                    $as_array[] = $v;
                    ++$nextkey;
                }
            }
            if ($as_array === null) {
                return "{" . join(",", $as_object) . "}";
            } else if (count($as_array) == 0) {
                return (is_object($x) || ($options & JSON_FORCE_OBJECT)) ? "{}" : "[]";
            } else {
                return "[" . join(",", $as_array) . "]";
            }
        } else {
            return null;
        }
    }


    /** @param string $s
     * @param ?bool $assoc
     * @param int $depth
     * @param int $flags
     * @return mixed */
    static function try_decode($s, $assoc = null, $depth = 512, $flags = 0) {
        if (self::$json_parser) {
            self::$json_parser->error_type = 0;
        }
        return json_decode($s, $assoc, $depth, $flags) ?? self::decode($s, $assoc, $depth, $flags);
    }

    /** @return int */
    static function last_error() {
        return self::$json_parser ? self::$json_parser->last_error() : 0;
    }

    /** @return ?string */
    static function last_error_msg() {
        return self::$json_parser ? self::$json_parser->last_error_msg() : null;
    }
}
