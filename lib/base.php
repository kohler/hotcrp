<?php
// base.php -- HotCRP base helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// string helpers

function str_starts_with($haystack, $needle) {
    $nl = strlen($needle);
    return $nl <= strlen($haystack) && substr($haystack, 0, $nl) === $needle;
}

function str_ends_with($haystack, $needle) {
    $p = strlen($haystack) - strlen($needle);
    return $p >= 0 && substr($haystack, $p) === $needle;
}

function cleannl($text) {
    if (strpos($text, "\r") !== false) {
	$text = str_replace("\r\n", "\n", $text);
	$text = strtr($text, "\r", "\n");
    }
    if (strlen($text) && $text[strlen($text) - 1] != "\n")
	$text .= "\n";
    return $text;
}

function simplify_whitespace($x) {
    return trim(preg_replace('/\s+/', " ", $x));
}


// MIME helpers

function mime_quote_string($word) {
    return '"' . preg_replace('_([\x00-\x1F\\"])_', '\$1', $word) . '"';
}

function mime_token_quote($word) {
    if (preg_match('_\A[^][\x00-\x20\x80-\xFF()<>@,;:\\"/?=]+\z_', $word))
        return $word;
    else
        return mime_quote_string($word);
}


// encoders and decoders

function html_id_encode($text) {
    $x = preg_split('_([^-a-zA-Z0-9])_', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2)
        $x[$i] = "_" . dechex(ord($x[$i]));
    return join("", $x);
}

function html_id_decode($text) {
    $x = preg_split(',(_[0-9A-Fa-f][0-9A-Fa-f]),', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($x); $i += 2)
        $x[$i] = chr(hexdec(substr($x[$i], 1)));
    return join("", $x);
}


// JSON encoding helpers

if (!function_exists("json_encode") || !function_exists("json_decode"))
    require_once("$ConfSitePATH/lib/json.php");
if (!function_exists("json_last_error_msg")) {
    function json_last_error_msg() {
        return false;
    }
}
if (!defined("JSON_PRETTY_PRINT"))
    define("JSON_PRETTY_PRINT", 0);
if (!defined("JSON_UNESCAPED_UNICODE"))
    define("JSON_UNESCAPED_UNICODE", 0);


// array and object helpers

function defval($var, $idx, $defval = null) {
    if (is_array($var))
	return (isset($var[$idx]) ? $var[$idx] : $defval);
    else
	return (isset($var->$idx) ? $var->$idx : $defval);
}
