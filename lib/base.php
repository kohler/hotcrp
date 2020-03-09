<?php
// base.php -- HotCRP base helper functions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

// type helpers

function is_number($x) {
    return is_int($x) || is_float($x);
}


// string helpers

function str_starts_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, 0, $nl) === 0);
}

function str_ends_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, -$nl) === 0);
}

function stri_ends_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, -$nl, $nl, true) === 0);
}

function preg_matchpos($pattern, $subject) {
    if (preg_match($pattern, $subject, $m, PREG_OFFSET_CAPTURE))
        return $m[0][1];
    else
        return false;
}

function cleannl($text) {
    if (substr($text, 0, 3) === "\xEF\xBB\xBF")
        $text = substr($text, 3);
    if (strpos($text, "\r") !== false) {
        $text = str_replace("\r\n", "\n", $text);
        $text = strtr($text, "\r", "\n");
    }
    if ($text !== "" && $text[strlen($text) - 1] !== "\n")
        $text .= "\n";
    return $text;
}

function space_join(/* $str_or_array, ... */) {
    $t = "";
    foreach (func_get_args() as $arg)
        if (is_array($arg)) {
            foreach ($arg as $x)
                if ($x !== "" && $x !== false && $x !== null)
                    $t .= ($t === "" ? "" : " ") . $x;
        } else if ($arg !== "" && $arg !== false && $arg !== null)
            $t .= ($t === "" ? "" : " ") . $arg;
    return $t;
}

function is_usascii($str) {
    return !preg_match('/[\x80-\xFF]/', $str);
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
    if (substr($str, 0, 3) === "\xEF\xBB\xBF")
        $str = substr($str, 3);
    if (is_valid_utf8($str))
        return $str;
    $pfx = substr($str, 0, 5000);
    if (substr_count($pfx, "\r") > 1.5 * substr_count($pfx, "\n"))
        return mac_os_roman_to_utf8($str);
    else
        return windows_1252_to_utf8($str);
}

function simplify_whitespace($x) {
    // Replace invisible Unicode space-type characters with true spaces,
    // including control characters and DEL.
    return trim(preg_replace('/(?:[\x00-\x20\x7F]|\xC2[\x80-\xA0]|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/', " ", $x));
}

function prefix_word_wrap($prefix, $text, $indent = 18, $totWidth = 75) {
    if (is_int($indent)) {
        $indentlen = $indent;
        $indent = str_pad("", $indent);
    } else
        $indentlen = strlen($indent);

    $out = "";
    if ($prefix !== false) {
        while ($text !== "" && ctype_space($text[0])) {
            $out .= $text[0];
            $text = substr($text, 1);
        }
    } else if (($line = UnicodeHelper::utf8_line_break($text, $totWidth)) !== false)
        $out .= $line . "\n";

    while (($line = UnicodeHelper::utf8_line_break($text, $totWidth - $indentlen)) !== false)
        $out .= $indent . preg_replace('/^\pZ+/u', '', $line) . "\n";

    if ($prefix === false)
        /* skip */;
    else if (strlen($prefix) <= $indentlen) {
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

interface Abbreviator {
    public function abbreviations_for($name, $data);
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

function get($var, $idx, $default = null) {
    if (is_array($var)) {
        return array_key_exists($idx, $var) ? $var[$idx] : $default;
    } else if (is_object($var)) {
        return property_exists($var, $idx) ? $var->$idx : $default;
    } else if ($var === null) {
        return $default;
    } else {
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

function get_f($var, $idx, $default = null) {
    return (float) get($var, $idx, $default);
}

function uploaded_file_error($finfo) {
    $e = $finfo["error"];
    $name = get($finfo, "name") ? "<span class=\"lineno\">" . htmlspecialchars($finfo["name"]) . ":</span> " : "";
    if ($e == UPLOAD_ERR_INI_SIZE || $e == UPLOAD_ERR_FORM_SIZE)
        return $name . "Uploaded file too big. The maximum upload size is " . ini_get("upload_max_filesize") . "B.";
    else if ($e == UPLOAD_ERR_PARTIAL)
        return $name . "Upload process interrupted.";
    else if ($e != UPLOAD_ERR_NO_FILE)
        return $name . "Unknown upload error.";
    else
        return false;
}

function make_qreq() {
    $qreq = new Qrequest($_SERVER["REQUEST_METHOD"]);
    $qreq->set_page_path(Navigation::page(), Navigation::path());
    foreach ($_GET as $k => $v) {
        $qreq->set_req($k, $v);
    }
    foreach ($_POST as $k => $v) {
        $qreq->set_req($k, $v);
    }
    if (empty($_POST)) {
        $qreq->set_post_empty();
    }

    // $_FILES requires special processing since we want error messages.
    $errors = [];
    foreach ($_FILES as $nx => $fix) {
        if (is_array($fix["error"])) {
            $fis = [];
            foreach (array_keys($fix["error"]) as $i) {
                $fis[$i ? "$nx.$i" : $nx] = ["name" => $fix["name"][$i], "type" => $fix["type"][$i], "size" => $fix["size"][$i], "tmp_name" => $fix["tmp_name"][$i], "error" => $fix["error"][$i]];
            }
        } else {
            $fis = [$nx => $fix];
        }
        foreach ($fis as $n => $fi) {
            if ($fi["error"] == UPLOAD_ERR_OK) {
                if (is_uploaded_file($fi["tmp_name"]))
                    $qreq->set_file($n, $fi);
            } else if (($err = uploaded_file_error($fi))) {
                $errors[] = $err;
            }
        }
    }
    if (!empty($errors) && Conf::$g) {
        Conf::msg_error("<div class=\"parseerr\"><p>" . join("</p>\n<p>", $errors) . "</p></div>");
    }

    return $qreq;
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

function object_replace($a, $b) {
    foreach (is_object($b) ? get_object_vars($b) : $b as $k => $v)
        if ($v === null)
            unset($a->$k);
        else
            $a->$k = $v;
}

function object_replace_recursive($a, $b) {
    foreach (is_object($b) ? get_object_vars($b) : $b as $k => $v)
        if ($v === null)
            unset($a->$k);
        else if (!property_exists($a, $k)
                 || !is_object($a->$k)
                 || !is_object($v))
            $a->$k = $v;
        else
            object_replace_recursive($a->$k, $v);
}

function json_object_replace($j, $updates, $nullable = false) {
    if ($j === null)
        $j = (object) [];
    else if (is_array($j))
        $j = (object) $j;
    object_replace($j, $updates);
    if ($nullable) {
        $x = get_object_vars($j);
        if (empty($x))
            $j = null;
    }
    return $j;
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

function assert_callback() {
    trigger_error("Assertion backtrace: " . json_encode(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 2)), E_USER_WARNING);
}
//assert_options(ASSERT_CALLBACK, "assert_callback");


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
        return setcookie($name, $value, get($options, "expires", 0),
                         get($options, "path", ""), get($options, "domain", ""),
                         get($options, "secure", false), get($options, "httponly", false));
    }
}
