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

    /** Like Json::decode, but bound length and refuse hash-collision-shaped
     * input, as befits untrusted input.
     *
     * A caller that must accept large, legitimately complex documents (e.g. a
     * chair or an administrative batch tool) builds its own parser and calls
     * `Json::decode`, raising `JsonParser::set_complexity_scale` or setting it
     * to 0 to lift the complexity cap.
     * @param ?string $s
     * @param ?int $max_length
     * @return mixed */
    static function decode_user($s, $max_length = null) {
        return self::decode($s, (new JsonParser)->set_user($max_length));
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
