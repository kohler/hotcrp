<?php
// polyfills.php -- HotCRP GMP shim functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (!function_exists("str_starts_with")) {
    /** @param string $haystack
     * @param string $needle
     * @return bool */
    function str_starts_with($haystack, $needle) {
        $nl = strlen($needle);
        $hl = strlen($haystack);
        return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, 0, $nl) === 0);
    }

    /** @param string $haystack
     * @param string $needle
     * @return bool */
    function str_ends_with($haystack, $needle) {
        $nl = strlen($needle);
        $hl = strlen($haystack);
        return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, -$nl) === 0);
    }
}

foreach (["JSON_ERROR_NONE" => 0, "JSON_ERROR_DEPTH" => 1,
          "JSON_ERROR_STATE_MISMATCH" => 2, "JSON_ERROR_CTRL_CHAR" => 3,
          "JSON_ERROR_SYNTAX" => 4, "JSON_ERROR_UTF8" => 5,
          "JSON_FORCE_OBJECT" => 16, "JSON_PRETTY_PRINT" => 128,
          "JSON_UNESCAPED_SLASHES" => 64,
          "JSON_UNESCAPED_UNICODE" => 256] as $k => $v) {
    if (!defined($k))
        define($k, $v);
}
if (!interface_exists("JsonSerializable")) {
    interface JsonSerializable {
        public function jsonSerialize();
    }
}
if (!function_exists("json_encode") || !function_exists("json_decode")) {
    define("JSON_HOTCRP", 1);
}
if (!function_exists("json_encode")) {
    function json_encode($x, $options = 0) {
        return Json::encode($x, $options);
    }
}
if (!function_exists("json_decode")) {
    function json_decode($x, $assoc = false, $depth = 512, $options = 0) {
        return Json::decode($x, $assoc, $depth, $options);
    }
    function json_last_error() {
        return Json::last_error();
    }
    function json_last_error_msg() {
        return Json::last_error_msg();
    }
}
if (!function_exists("json_last_error")) {
    function json_last_error() {
        return false;
    }
}
if (!function_exists("json_last_error_msg")) {
    function json_last_error_msg() {
        return false;
    }
}

if (!function_exists("normalizer_normalize")) {
    function normalizer_normalize($text) {
        return $text; /* XXX */
    }
}

if (!function_exists("gmp_init")) {
    function gmp_init($v) {
        return GMPShim::init($v);
    }
    function gmp_clrbit(&$a, $index) {
        return GMPShim::clrbit($a, $index);
    }
    function gmp_setbit(&$a, $index, $bit_on = true) {
        return GMPShim::setbit($a, $index, $bit_on);
    }
    function gmp_testbit($a, $index) {
        return GMPShim::testbit($a, $index);
    }
    function gmp_scan1($a, $start) {
        return GMPShim::scan1($a, $start);
    }
}
