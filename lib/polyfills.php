<?php
// polyfills.php -- HotCRP GMP shim functions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (!function_exists("array_find")) {
    /** @param array $array
     * @param callable(mixed,mixed):bool $callback
     * @return mixed */
    function array_find($array, $callback) {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return null;
    }
}

if (!function_exists("array_is_list")) {
    /** @param array $array
     * @return bool */
    function array_is_list($array) {
        return array_values($array) === $array;
    }
}

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

if (!function_exists("json_validate")) {
    /** @suppress PhanRedefineFunctionInternal */
    function json_validate($s, $depth = 512, $flags = 0) {
        json_decode($s, null, $depth, $flags);
        return json_last_error() === JSON_ERROR_NONE;
    }
}


if (!function_exists("normalizer_normalize")) {
    function normalizer_normalize($text) {
        return $text; /* XXX */
    }
}

if (!function_exists("openssl_cipher_key_length")) {
    /** @param string $cipher
     * @return int|false */
    function openssl_cipher_key_length($cipher) {
        if (!in_array($cipher, openssl_get_cipher_methods())) {
            // XXX should warn
            return false;
        } else if (preg_match('/\A(?:aes-|aria-|camellia-)(128|192|256)(?:-cbc|-cbc-hmac.*|-ccm|-cfb.*|-ctr|-ecb|-gcm|-ocb|-ofb|-wrap|-wrap-pad)\z/', $cipher, $m)) {
            return (int) $m[1] / 8;
        }
        return 0; /* XXX */
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

if (!function_exists("zlib_get_coding_type")) {
    /** @return bool
     * @phan-suppress-next-line PhanRedefineFunctionInternal */
    function zlib_get_coding_type() {
        return false;
    }
}
