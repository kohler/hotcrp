<?php
// base.php -- HotCRP base helper functions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanRedefineFunction */

// type helpers

/** @param mixed $x
 * @return bool */
function is_number($x) {
    return is_int($x) || is_float($x);
}

/** @param mixed $x
 * @return bool */
function is_associative_array($x) {
    // this method is surprisingly fast
    return is_array($x) && array_values($x) !== $x;
}

/** @param mixed $x
 * @return bool */
function is_list($x) {
    return is_array($x) && array_values($x) === $x;
}

/** @param mixed $x
 * @return bool */
function is_int_list($x) {
    if (is_array($x) && array_values($x) === $x) {
        foreach ($x as $i) {
            if (!is_int($i))
                return false;
        }
        return true;
    } else {
        return false;
    }
}

/** @param mixed $x
 * @return bool */
function is_string_list($x) {
    if (is_array($x) && array_values($x) === $x) {
        foreach ($x as $i) {
            if (!is_string($i))
                return false;
        }
        return true;
    } else {
        return false;
    }
}


// string helpers

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

/** @param string $haystack
 * @param string $needle
 * @return bool */
function stri_ends_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, -$nl, $nl, true) === 0);
}

/** @param string $pattern
 * @param string $subject
 * @return int|false */
function preg_matchpos($pattern, $subject) {
    if (preg_match($pattern, $subject, $m, PREG_OFFSET_CAPTURE)) {
        return $m[0][1];
    } else {
        return false;
    }
}

/** @param string $text
 * @return string */
function cleannl($text) {
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    if (strpos($text, "\r") !== false) {
        $text = str_replace("\r\n", "\n", $text);
        $text = strtr($text, "\r", "\n");
    }
    if ($text !== "" && $text[strlen($text) - 1] !== "\n") {
        $text .= "\n";
    }
    return $text;
}

function space_join(/* $str_or_array, ... */) {
    $t = "";
    foreach (func_get_args() as $arg) {
        if (is_array($arg)) {
            foreach ($arg as $x) {
                if ($x !== "" && $x !== false && $x !== null)
                    $t .= ($t === "" ? "" : " ") . $x;
            }
        } else if ($arg !== "" && $arg !== false && $arg !== null) {
            $t .= ($t === "" ? "" : " ") . $arg;
        }
    }
    return $t;
}

/** @param string $str
 * @return bool */
function is_usascii($str) {
    return !preg_match('/[\x80-\xFF]/', $str);
}

/** @param string $str
 * @return bool */
function is_valid_utf8($str) {
    return !!preg_match('//u', $str);
}

if (function_exists("iconv")) {
    function windows_1252_to_utf8(string $str) {
        return iconv("Windows-1252", "UTF-8//IGNORE", $str);
    }
    function mac_os_roman_to_utf8(string $str) {
        return iconv("Mac", "UTF-8//IGNORE", $str);
    }
} else if (function_exists("mb_convert_encoding")) {
    function windows_1252_to_utf8(string $str) {
        return mb_convert_encoding($str, "UTF-8", "Windows-1252");
    }
}
if (!function_exists("windows_1252_to_utf8")) {
    function windows_1252_to_utf8(string $str) {
        return UnicodeHelper::windows_1252_to_utf8($str);
    }
}
if (!function_exists("mac_os_roman_to_utf8")) {
    function mac_os_roman_to_utf8(string $str) {
        return UnicodeHelper::mac_os_roman_to_utf8($str);
    }
}

/** @param string $str */
function convert_to_utf8($str) {
    if (substr($str, 0, 3) === "\xEF\xBB\xBF") {
        $str = substr($str, 3);
    }
    if (is_valid_utf8($str)) {
        return $str;
    } else {
        $pfx = substr($str, 0, 5000);
        if (substr_count($pfx, "\r") > 1.5 * substr_count($pfx, "\n")) {
            return mac_os_roman_to_utf8($str);
        } else {
            return windows_1252_to_utf8($str);
        }
    }
}

/** @param string $str */
function simplify_whitespace($str) {
    // Replace invisible Unicode space-type characters with true spaces,
    // including control characters and DEL.
    return trim(preg_replace('/(?:[\x00-\x20\x7F]|\xC2[\x80-\xA0]|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/', " ", $str));
}

/** @param string $prefix
 * @param string $text
 * @param int|string $indent
 * @param ?int $width
 * @param bool $flowed
 * @return string */
function prefix_word_wrap($prefix, $text, $indent = 18, $width = 75, $flowed = false) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_repeat(" ", $indent);
    } else {
        $indentlen = strlen($indent);
    }
    $width = $width ?? 75;

    $out = "";
    if ($prefix !== false) {
        while ($text !== "" && ctype_space($text[0])) {
            $out .= $text[0];
            $text = substr($text, 1);
        }
    } else if (($line = UnicodeHelper::utf8_line_break($text, $width, $flowed)) !== false) {
        $out .= $line . "\n";
    }

    while (($line = UnicodeHelper::utf8_line_break($text, $width - $indentlen, $flowed)) !== false) {
        $out .= $indent . preg_replace('/\A\pZ+/u', '', $line) . "\n";
    }

    if ($prefix === false) {
        /* skip */;
    } else if (strlen($prefix) <= $indentlen) {
        $prefix = str_pad($prefix, $indentlen, " ", STR_PAD_LEFT);
        $out = $prefix . substr($out, $indentlen);
    } else {
        $out = $prefix . "\n" . $out;
    }

    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }
    return $out;
}

/** @param string $text */
function count_words($text) {
    return preg_match_all('/[^-\s.,;:<>!?*_~`#|]\S*/', $text);
}

function friendly_boolean($x) {
    if (is_bool($x)) {
        return $x;
    } else if (is_string($x) || is_int($x)) {
        return filter_var($x, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    } else {
        return null;
    }
}


// email and MIME helpers

/** @param string $email */
function validate_email($email) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (!preg_match(':\A[-!#$%&\'*+./0-9=?A-Z^_`a-z{|}~]+@(.+)\z:', $email, $m)) {
        return false;
    } else if ($m[1][0] === "_") {
        return preg_match(':\A_\.[0-9A-Za-z]+\z:', $m[1]);
    } else {
        return preg_match(':\A([-0-9A-Za-z]+\.)+[0-9A-Za-z]+\z:', $m[1]);
    }
}

/** @param string $word */
function mime_quote_string($word) {
    return '"' . preg_replace('_(?=[\x00-\x1F\\"])_', '\\', $word) . '"';
}

/** @param string $word */
function mime_token_quote($word) {
    if (preg_match('_\A[^][\x00-\x20\x80-\xFF()<>@,;:\\"/?=]+\z_', $word)) {
        return $word;
    } else {
        return mime_quote_string($word);
    }
}

/** @param string $words */
function rfc2822_words_quote($words) {
    if (preg_match(':\A[-A-Za-z0-9!#$%&\'*+/=?^_`{|}~ \t]*\z:', $words)) {
        return $words;
    } else {
        return mime_quote_string($words);
    }
}


// encoders and decoders

/** @param string $text
 * @return string */
function html_id_encode($text) {
    $x = preg_split('/([^-a-zA-Z0-9])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2) {
        $x[$i] = "_" . dechex(ord($x[$i]));
    }
    return join("", $x);
}

/** @param string $text
 * @return string */
function html_id_decode($text) {
    $x = preg_split('/(_[0-9A-Fa-f][0-9A-Fa-f])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2) {
        $x[$i] = chr(hexdec(substr($x[$i], 1)));
    }
    return join("", $x);
}

/** @param string $text
 * @return string */
function base64url_encode($text) {
    return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
}

/** @param string $text
 * @return string */
function base64url_decode($text) {
    return base64_decode(strtr($text, '-_', '+/'));
}


// JSON encoding helpers

if (defined("JSON_UNESCAPED_LINE_TERMINATORS")) {
    // JSON_UNESCAPED_UNICODE is only safe to send to the browser if
    // JSON_UNESCAPED_LINE_TERMINATORS is defined.
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags | JSON_UNESCAPED_UNICODE);
    }
} else {
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags);
    }
}
function json_encode_db($x, $flags = 0) {
    return json_encode($x, $flags | JSON_UNESCAPED_UNICODE);
}


// array and object helpers

/** @param object|array|null $var
 * @param string|int $idx
 * @return mixed */
function get($var, $idx, $default = null) {
    if (is_array($var)) {
        return array_key_exists($idx, $var) ? $var[$idx] : $default;
    } else if (is_object($var)) {
        return property_exists($var, $idx) ? $var->$idx : $default;
    } else {
        assert($var === null);
        return $default;
    }
}

/** @param object|array|null $var
 * @param string|int $idx
 * @return string */
function get_s($var, $idx, $default = null) {
    return (string) get($var, $idx, $default);
}

/** @param object|array|null $var
 * @param string|int $idx
 * @return int */
function get_i($var, $idx, $default = null) {
    return (int) get($var, $idx, $default);
}

/** @param object|array|null $var
 * @param string|int $idx
 * @return float */
function get_f($var, $idx, $default = null) {
    return (float) get($var, $idx, $default);
}

/** @param mixed $a */
function array_to_object_recursive($a) {
    if (is_array($a) && is_associative_array($a)) {
        $o = (object) array();
        foreach ($a as $k => $v) {
            if ($k !== "")
                $o->$k = array_to_object_recursive($v);
        }
        return $o;
    } else {
        return $a;
    }
}

function object_replace($a, $b) {
    foreach (is_object($b) ? get_object_vars($b) : $b as $k => $v) {
        if ($v === null) {
            unset($a->$k);
        } else {
            $a->$k = $v;
        }
    }
}

function object_replace_recursive($a, $b) {
    foreach (is_object($b) ? get_object_vars($b) : $b as $k => $v) {
        if ($v === null) {
            unset($a->$k);
        } else if (!property_exists($a, $k)
                   || !is_object($a->$k)
                   || !is_object($v)) {
            $a->$k = $v;
        } else {
            object_replace_recursive($a->$k, $v);
        }
    }
}

function json_object_replace($j, $updates, $nullable = false) {
    if ($j === null) {
        $j = (object) [];
    } else if (is_array($j)) {
        $j = (object) $j;
    }
    object_replace($j, $updates);
    if ($nullable) {
        $x = get_object_vars($j);
        if (empty($x)) {
            $j = null;
        }
    }
    return $j;
}


// debug helpers

function caller_landmark($position = 1, $skipfunction_re = null) {
    if (is_string($position)) {
        list($position, $skipfunction_re) = array(1, $position);
    }
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $fname = null;
    for (++$position; isset($trace[$position]); ++$position) {
        $fname = $trace[$position]["class"] ?? "";
        $fname .= ($fname ? "::" : "") . $trace[$position]["function"];
        if ((!$skipfunction_re || !preg_match($skipfunction_re, $fname))
            && ($fname !== "call_user_func" || ($trace[$position - 1]["file"] ?? false))) {
            break;
        }
    }
    $t = "";
    if ($position > 0 && ($pi = $trace[$position - 1]) && isset($pi["file"])) {
        $t = $pi["file"] . ":" . $pi["line"];
    }
    if ($fname) {
        $t .= ($t ? ":" : "") . $fname;
    }
    return $t ? : "<unknown>";
}

function assert_callback() {
    trigger_error("Assertion backtrace: " . json_encode(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 2)), E_USER_WARNING);
}
//assert_options(ASSERT_CALLBACK, "assert_callback");

function debug_string_backtrace() {
    $s = preg_replace_callback('/^\#(\d+)/m', function ($m) {
        return "#" . ($m[1] - 1);
    }, (new Exception)->getTraceAsString());
    if (SiteLoader::$root) {
        $s = str_replace(SiteLoader::$root, "[" . (Conf::$main ? Conf::$main->dbname : "HotCRP") . "]", $s);
    }
    return substr($s, strpos($s, "\n") + 1);
}


// zlib helper

if (!function_exists("zlib_get_coding_type")) {
    /** @phan-suppress-next-line PhanRedefineFunctionInternal */
    function zlib_get_coding_type() {
        return false;
    }
}


// pcntl helpers

if (function_exists("pcntl_wifexited") && pcntl_wifexited(0) !== null) {
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return pcntl_wifexited($status) && pcntl_wexitstatus($status) == $exitstatus;
    }
} else {
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return ($status & 0xff7f) == ($exitstatus << 8);
    }
}


// setcookie helper

if (PHP_VERSION_ID >= 70300) {
    function hotcrp_setcookie($name, $value = "", $options = []) {
        return setcookie($name, $value, $options);
    }
} else {
    function hotcrp_setcookie($name, $value = "", $options = []) {
        return setcookie($name, $value, $options["expires"] ?? 0,
                         $options["path"] ?? "", $options["domain"] ?? "",
                         $options["secure"] ?? false, $options["httponly"] ?? false);
    }
}
