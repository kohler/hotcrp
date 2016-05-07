<?php
// base.php -- HotCRP base helper functions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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
    if (strlen($text) && $text[strlen($text) - 1] !== "\n")
        $text .= "\n";
    return $text;
}

function is_valid_utf8($str) {
    return !!preg_match('//u', $str);
}

if (function_exists("iconv")) {
    function windows_1252_to_utf8($str) {
        return iconv("Windows-1252", "UTF-8//IGNORE", $str);
    }
    function mac_os_roman_to_utf8($str) {
        return iconv("Mac", "UTF-8//IGNORE", $str);
    }
} else if (function_exists("mb_convert_encoding")) {
    function windows_1252_to_utf8($str) {
        return mb_convert_encoding($str, "UTF-8", "Windows-1252");
    }
}
if (!function_exists("windows_1252_to_utf8")) {
    function windows_1252_to_utf8($str) {
        return UnicodeHelper::windows_1252_to_utf8($str);
    }
}
if (!function_exists("mac_os_roman_to_utf8")) {
    function mac_os_roman_to_utf8($str) {
        return UnicodeHelper::mac_os_roman_to_utf8($str);
    }
}

function convert_to_utf8($str) {
    if (is_valid_utf8($str))
        return $str;
    $pfx = substr($str, 0, 5000);
    if (substr_count($pfx, "\r") > 1.5 * substr_count($pfx, "\n"))
        return mac_os_roman_to_utf8($str);
    else
        return windows_1252_to_utf8($str);
}

function simplify_whitespace($x) {
    return trim(preg_replace('/(?:\s|\xC2\xA0)+/', " ", $x));
}

function prefix_word_wrap($prefix, $text, $indent = 18, $totWidth = 75) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_pad("", $indent);
    } else
        $indentlen = strlen($indent);

    $out = "";
    while ($text !== "" && ctype_space($text[0])) {
        $out .= $text[0];
        $text = substr($text, 1);
    }

    while (($line = UnicodeHelper::utf8_line_break($text, $totWidth - $indentlen)) !== false)
        $out .= $indent . preg_replace('/^\pZ+/u', '', $line) . "\n";
    if (strlen($prefix) <= $indentlen) {
        $prefix = str_pad($prefix, $indentlen, " ", STR_PAD_LEFT);
        $out = $prefix . substr($out, $indentlen);
    } else
        $out = $prefix . "\n" . $out;
    if (!str_ends_with($out, "\n"))
        $out .= "\n";
    return $out;
}

function center_word_wrap($text, $totWidth = 75, $multi_center = false) {
    if (strlen($text) <= $totWidth && !preg_match('/[\200-\377]/', $text))
        return str_pad($text, (int) (($totWidth + strlen($text)) / 2), " ", STR_PAD_LEFT) . "\n";
    $out = "";
    while (($line = UnicodeHelper::utf8_line_break($text, $totWidth)) !== false) {
        $linelen = UnicodeHelper::utf8_glyphlen($line);
        $out .= str_pad($line, (int) (($totWidth + $linelen) / 2), " ", STR_PAD_LEFT) . "\n";
    }
    return $out;
}

function count_words($text) {
    return preg_match_all('/[^-\s.,;:<>!?*_~`#|]\S*/', $text);
}

function friendly_boolean($x) {
    if (is_bool($x))
        return $x;
    else if (is_string($x) || is_int($x))
        return filter_var($x, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    else
        return null;
}


// email and MIME helpers

function validate_email($email) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (!preg_match(':\A[-!#$%&\'*+./0-9=?A-Z^_`a-z{|}~]+@(.+)\z:', $email, $m))
        return false;
    if ($m[1][0] === "_")
        return preg_match(':\A_\.[0-9A-Za-z]+\z:', $m[1]);
    else
        return preg_match(':\A([-0-9A-Za-z]+\.)+[0-9A-Za-z]+\z:', $m[1]);
}

function mime_quote_string($word) {
    return '"' . preg_replace('_(?=[\x00-\x1F\\"])_', '\\', $word) . '"';
}

function mime_token_quote($word) {
    if (preg_match('_\A[^][\x00-\x20\x80-\xFF()<>@,;:\\"/?=]+\z_', $word))
        return $word;
    else
        return mime_quote_string($word);
}

function rfc2822_words_quote($words) {
    if (preg_match(':\A[-A-Za-z0-9!#$%&\'*+/=?^_`{|}~ \t]*\z:', $words))
        return $words;
    else
        return mime_quote_string($words);
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

function get($var, $idx, $default = null) {
    if (is_array($var))
        return array_key_exists($idx, $var) ? $var[$idx] : $default;
    else if (is_object($var))
        return property_exists($var, $idx) ? $var->$idx : $default;
    else if ($var === null)
        return $default;
    else {
        error_log("inappropriate get: " . var_export($var, true) . ": " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $default;
    }
}

function get_s($var, $idx, $default = null) {
    return (string) get($var, $idx, $default);
}

function get_i($var, $idx, $default = null) {
    return (int) get($var, $idx, $default);
}

function opt($idx, $default = null) {
    global $Opt;
    return get($Opt, $idx, $default);
}

function req($idx, $default = null) {
    if (isset($_POST[$idx]))
        return $_POST[$idx];
    else if (isset($_GET[$idx]))
        return $_GET[$idx];
    else
        return $default;
}

function req_s($idx, $default = null) {
    return (string) req($idx, $default);
}

function set_req($idx, $value) {
    $_GET[$idx] = $_POST[$idx] = $_REQUEST[$idx] = $value;
}

function make_qreq() {
    $qreq = new Qobject;
    foreach ($_GET as $k => $v)
        $qreq[$k] = $v;
    foreach ($_POST as $k => $v)
        $qreq[$k] = $v;

    // $_FILES requires special processing since we want error messages.
    $qreq->_FILES = new Qobject;
    $errors = [];
    foreach ($_FILES as $f => $finfo) {
        if (($e = $finfo["error"]) == UPLOAD_ERR_OK) {
            if (is_uploaded_file($finfo["tmp_name"]))
                $qreq->_FILES[$f] = $finfo;
        } else {
            $name = get($finfo, "name") ? "<span class=\"lineno\">" . htmlspecialchars($finfo["name"]) . ":</span> " : "";
            if ($e == UPLOAD_ERR_INI_SIZE || $e == UPLOAD_ERR_FORM_SIZE)
                $errors[] = $name . "Uploaded file too big. The maximum upload size is " . ini_get("upload_max_filesize") . "B.";
            else if ($e == UPLOAD_ERR_PARTIAL)
                $errors[] = $name . "Upload process interrupted.";
            else if ($e != UPLOAD_ERR_NO_FILE)
                $errors[] = $name . "Unknown upload error.";
        }
    }
    if (count($errors) && Conf::$g)
        Conf::msg_error("<div class=\"parseerr\"><p>" . join("</p>\n<p>", $errors) . "</p></div>");
    return $qreq;
}

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
        $fname = get_s($trace[$position], "class");
        $fname .= ($fname ? "::" : "") . $trace[$position]["function"];
        if ((!$skipfunction_re || !preg_match($skipfunction_re, $fname))
            && ($fname !== "call_user_func" || get($trace[$position - 1], "file")))
            break;
    }
    $t = "";
    if ($position > 0 && ($pi = $trace[$position - 1]) && isset($pi["file"]))
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
