<?php
// json.php -- HotCRP JSON decoding with error location
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Json {
    const ERROR_TRAILING_COMMA = 101;
    const ERROR_TOO_LONG = 102;
    const ERROR_TOO_COMPLEX = 103;

    /** @var ?JsonParser */
    static private $default_parser;
    /** @var ?JsonParser */
    static private $last_parser;

    /** @return JsonParser */
    static private function default_parser() {
        self::$default_parser = self::$default_parser ?? new JsonParser;
        return self::$default_parser;
    }

    /** Decode `$s`, preferring the native json_decode; if it fails,
     * locate the error with `$jp`. See JsonParser::decode().
     * @param string $s
     * @param ?JsonParser $jp
     * @return mixed */
    static function decode($s, $jp = null) {
        $jp = $jp ?? self::default_parser();
        self::$last_parser = $jp;
        $x = $jp->set_input($s)->decode();
        if ($jp === self::$default_parser && $jp->error_type === 0) {
            $jp->set_input(null); // ensure storage is reclaimed
        }
        return $x;
    }

    /** Like Json::decode, but refuse hash-collision-shaped input.
     * @param ?string $s
     * @param ?int $max_length
     * @param ?JsonParser $jp
     * @return mixed */
    static function decode_user($s, $max_length = null, $jp = null) {
        $jp = $jp ?? self::default_parser();
        self::$last_parser = $jp;
        $len = $s === null ? 0 : strlen($s);
        if ($len === 0 || ($max_length !== null && $len > $max_length)) {
            $jp->set_error($len, $len === 0 ? JSON_ERROR_SYNTAX : self::ERROR_TOO_LONG);
            return null;
        }
        // A hash collision input packs ~1 object member per 7 bytes. Allowing
        // ~32*sqrt(len) members keeps json_decode's worst case linear in
        // `len` and never trips on normal documents. A backup case attempts
        // to allow strings containing lots of colons.
        if ($len > 8192) {
            $max_colon = 32 * (int) sqrt($len);
            if (substr_count($s, ":") > $max_colon
                && preg_match_all('/(?:"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+(?:"|\z))(*SKIP)(*FAIL)|:/', $s) > $max_colon) {
                $jp->set_error($len, self::ERROR_TOO_COMPLEX);
                return null;
            }
        }
        return self::decode($s, $jp);
    }

    /** @return int */
    static function last_error() {
        return self::$last_parser ? self::$last_parser->last_error() : 0;
    }

    /** @return ?string */
    static function last_error_msg() {
        return self::$last_parser ? self::$last_parser->last_error_msg() : null;
    }
}
