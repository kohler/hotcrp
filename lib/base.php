<?php
// base.php -- HotCRP base helper functions
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
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

function stri_ends_with($haystack, $needle) {
    $p = strlen($haystack) - strlen($needle);
    return $p >= 0 && strcasecmp(substr($haystack, $p), $needle) == 0;
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

function prefix_word_wrap($prefix, $text, $indent = 18, $totWidth = 75,
                          $prefix_right_justify = true) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_pad("", $indent);
    } else
        $indentlen = strlen($indent);

    $out = "";
    while ($text != "" && ctype_space($text[0])) {
        $out .= $text[0];
        $text = substr($text, 1);
    }

    $out .= preg_replace("/^(?!\\Z)/m", $indent, wordwrap($text, $totWidth - $indentlen));
    if (strlen($prefix) <= $indentlen) {
        $prefix = str_pad($prefix, $indentlen, " ",
                          ($prefix_right_justify ? STR_PAD_LEFT : STR_PAD_RIGHT));
        return $prefix . substr($out, $indentlen);
    } else
        return $prefix . "\n" . $out;
}


// email and MIME helpers

function validate_email($email) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (!preg_match(':\A[-!#$%&\'*+./0-9=?A-Z^_`a-z{|}~]+@(.+)\z:', $email, $m))
        return false;
    if ($m[1][0] == "_")
        return preg_match(':\A_\.[0-9A-Za-z]+\z:', $m[1]);
    else
        return preg_match(':\A([-0-9A-Za-z]+\.)+[0-9A-Za-z]+\z:', $m[1]);
}

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

function base64url_encode($text) {
    return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($text, '-_', '+/'));
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

function is_associative_array($a) {
    // this method is suprisingly fast
    return is_array($a) && array_values($a) !== $a;
}

function array_to_object_recursive($a) {
    if (is_associative_array($a)) {
        $o = (object) array();
        foreach ($a as $k => $v)
            if ($k !== "")
                $o->$k = array_to_object_recursive($v);
        return $o;
    } else
        return $a;
}

function object_replace_recursive($a, $b) {
    foreach (get_object_vars($b) as $k => $v)
        if ($v === null)
            unset($a->$k);
        else if (!property_exists($a, $k)
                 || !is_object($a->$k)
                 || !is_object($v))
            $a->$k = $v;
        else
            object_replace_recursive($a->$k, $v);
}


// debug helpers

function caller_landmark($position = 1, $skipfunction_re = null) {
    if (is_string($position))
        list($position, $skipfunction_re) = array(1, $position);
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $fname = null;
    for (++$position; isset($trace[$position]); ++$position) {
        $fname = (string) @$trace[$position]["class"];
        $fname .= ($fname ? "::" : "") . $trace[$position]["function"];
        if ((!$skipfunction_re || !preg_match($skipfunction_re, $fname))
            && ($fname !== "call_user_func" || @$trace[$position - 1]["file"]))
            break;
    }
    $t = "";
    if ($position > 0 && ($pi = @$trace[$position - 1]) && @$pi["file"])
        $t = $pi["file"] . ":" . $pi["line"];
    if ($fname)
        $t .= ($t ? ":" : "") . $fname;
    return $t ? : "<unknown>";
}


// pcntl helpers

if (!function_exists("pcntl_wifexited")) {
    function pcntl_wifexited($status) {
        return ($status & 0x7f) == 0;
    }
    function pcntl_wexitstatus($status) {
        return ($status & 0xff00) >> 8;
    }
}
