<?php
// json.php -- HotCRP JSON decoding with error location
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Json {
    /** @var ?JsonParser */
    static private $default_parser;
    /** @var ?JsonParser */
    static private $last_parser;

    /** @return JsonParser */
    static private function default_parser() {
        self::$default_parser = self::$default_parser ?? new JsonParser;
        return self::$default_parser;
    }

    /** Decode `$json`, preferring the native json_decode; if it fails,
     * locate the error with `$jp`. See JsonParser::decode().
     * @param string $json
     * @param ?JsonParser $jp
     * @return mixed */
    static function decode($json, $jp = null) {
        $jp = $jp ?? self::default_parser();
        self::$last_parser = $jp;
        $x = $jp->set_input($json)->decode();
        if ($jp === self::$default_parser && $jp->error_type === 0) {
            $jp->set_input(null); // ensure storage is reclaimed
        }
        return $x;
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
