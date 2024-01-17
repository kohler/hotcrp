<?php
// base.php -- HotCRP base helper functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.
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

/** @return list */
function make_array(...$x) {
    // This works around a syntax error in PHP 7
    return $x;
}

/** @param list<mixed> &$a */
function array_sort_unique(&$a) {
    if (($n = count($a)) > 1) {
        sort($a);
        for ($i = 0, $j = 1; $j !== $n; ++$j) {
            if ($a[$i] !== $a[$j]) {
                ++$i;
                if ($i !== $j) {
                    $a[$i] = $a[$j];
                }
            }
        }
        if ($i !== $n - 1) {
            array_splice($a, $i + 1);
        }
    }
}


// string helpers

/** @param string $haystack
 * @param string $needle
 * @return bool */
function stri_starts_with($haystack, $needle) {
    $nl = strlen($needle);
    $hl = strlen($haystack);
    return $nl === 0 || ($hl >= $nl && substr_compare($haystack, $needle, 0, $nl, true) === 0);
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
        $text = str_replace("\r", "\n", $text);
    }
    if ($text !== "" && $text[strlen($text) - 1] !== "\n") {
        $text .= "\n";
    }
    return $text;
}

/** @param array $what
 * @param string $joinword
 * @return string */
function commajoin($what, $joinword = "and") {
    $what = array_values($what);
    $c = count($what);
    if ($c === 0) {
        return "";
    } else if ($c === 1) {
        return $what[0];
    } else if ($c === 2) {
        return "{$what[0]} {$joinword} {$what[1]}";
    } else {
        $last = array_pop($what);
        foreach ($what as &$w) {
            if (str_ends_with($w, "</span>")) {
                $w = substr($w, 0, -7) . ",</span>";
            } else {
                $w .= ",";
            }
        }
        return join(" ", $what) . " {$joinword} {$last}";
    }
}

/** @param string $str
 * @return bool */
function is_usascii($str) {
    return !preg_match('/[\x80-\xFF]/', $str);
    // 2023: this is faster than iconv, iconv_strlen, or mb_check_encoding
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

/** @param string $str
 * @return string */
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

/** @param string $str
 * @return string */
function simplify_whitespace($str) {
    // Replace invisible Unicode space-type characters with true spaces,
    // including control characters and DEL.
    return trim(preg_replace('/(?:[\x00-\x20\x7F]|\xC2[\x80-\xA0]|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/', " ", $str));
}

/** @param string $text
 * @param bool $all
 * @return int */
function tab_width($text, $all) {
    $len = 0;
    for ($i = 0; $i < strlen($text); ++$i) {
        if ($text[$i] === ' ') {
            ++$len;
        } else if ($text[$i] === '\t') {
            $len += 8 - ($len % 8);
        } else if (!$all) {
            break;
        } else {
            ++$len;
        }
    }
    return $len;
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

    $out = $prefix;
    $wx = max($width - strlen($prefix), 0);
    $first = true;
    $itext = $text;

    while (($line = UnicodeHelper::utf8_line_break($text, $wx, $flowed)) !== false) {
        if ($first
            && $wx < $width - $indentlen
            && strlen($line) > $wx
            && strlen($line) < $width - $indentlen
            && $out !== ""
            && !ctype_space($out)
            && (!$flowed || strlen(rtrim($line)) > $wx)) {
            // `$prefix` too long for even one word: add a line break and restart
            $out = ($flowed ? $out : rtrim($out)) . "\n";
            $text = $itext;
            $wx = $width - $indentlen;
        } else if ($first) {
            // finish first line
            $out .= $line . "\n";
            $wx = $width - $indentlen;
        } else {
            $out .= $indent . preg_replace('/\A\pZ+/u', '', $line) . "\n";
        }
        $first = false;
    }

    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }
    return $out;
}

/** @param string $text
 * @return int */
function count_words($text) {
    $refl = is_usascii($text) ? 's' : 'us';
    return preg_match_all('/[^-\s.,;:<>!?*_~`#|]\S*+/' . $refl, $text);
    // 2023 benchmark on 223MB: Without /u = 1.76, with /u = 2.52, with is_usascii = 2.40.
}

/** @param string $text
 * @param int $wlimit
 * @return array{string,string} */
function count_words_split($text, $wlimit) {
    // A compiled regex in PCRE takes space proportional to the maximum repeat
    // count, so the previous version that more straightforwardly used a repeat
    // count equal to $wlimit failed in practice. This version is faster than
    // preg_match_all + join.
    $refl = is_usascii($text) ? 's' : 'us';
    $offset = 0;
    $len = strlen($text);
    while ($offset < $len && $wlimit > 0) {
        $n = min($wlimit, 50);
        if (!preg_match('/\G(?:[-\s.,;:<>!?*_~`#|]*+[^-\s.,;:<>!?*_~`#|]\S*+){' . $n . '}\s*+/' . $refl, $text, $m, 0, $offset)) {
            return [$text, ""];
        }
        $offset += strlen($m[0]);
        $wlimit -= $n;
    }
    if ($wlimit > 0
        || preg_match('/\G[-\s.,;:<>!?*_~`#|]*+\z/' . $refl, $text, $m, 0, $offset)) {
        return [$text, ""];
    } else {
        return [substr($text, 0, $offset), substr($text, $offset)];
    }
}

/** @param string $s
 * @param int $flags
 * @return string */
function glob_to_regex($s, $flags = 0) {
    $t = "";
    while (preg_match('/\A(.*?)([-.\\\\+*?\\[^\\]$(){}=!<>|:#\\/])([\s\S]*)\z/', $s, $m)) {
        $t .= $m[1];
        if ($m[2] === "\\") {
            if ($m[3] === "") {
                $t .= "\\\\";
            } else {
                $t .= "\\" . $m[3][0];
                $m[3] = (string) substr($m[3], 1);
            }
        } else if ($m[2] === "*") {
            $t .= ".*";
        } else if ($m[2] === "?") {
            $t .= ".";
        } else if ($m[2] === "["
                   && ($pos = strpos($m[3], "]")) !== false
                   && $pos > 0) {
            $x = substr($m[3], 0, $pos);
            $m[3] = (string) substr($m[3], $pos + 1);
            if ($x[0] === "!") {
                $t .= "[^" . (string) substr($x, 1) . "]";
            } else {
                $t .= "[{$x}]";
            }
        } else if ($m[2] === "{"
                   && ($pos = strpos($m[3], "}")) !== false
                   && ($flags & GLOB_BRACE) !== 0) {
            $x = substr($m[3], 0, $pos);
            $m[3] = (string) substr($m[3], $pos + 1);
            $sep = "(?:";
            while ($x !== "") {
                $comma = strpos($x, ",");
                $pos = $comma === false ? strlen($x) : $comma;
                $t .= $sep . substr($x, 0, $pos);
                $sep = "|";
                $x = $comma === false ? "" : (string) substr($x, $comma + 1);
            }
            if ($sep !== "(?:") {
                $t .= ")";
            }
        } else {
            $t .= "\\" . $m[2];
        }
        $s = $m[3];
    }
    return $t . $s;
}

/** @param mixed $x
 * @return ?bool */
function friendly_boolean($x) {
    if (is_bool($x)) {
        return $x;
    } else if (is_string($x) || is_int($x)) {
        // 0, false, off, no: false; 1, true, on, yes: true
        return filter_var($x, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    } else {
        return null;
    }
}

/** @param ?string $varname
 * @param null|string|int $value
 * @return int */
function ini_get_bytes($varname, $value = null) {
    $value = $value ?? trim(ini_get($varname));
    if (is_string($value)) {
        $len = strlen($value);
        $last = $len > 0 ? strtolower($value[$len - 1]) : ".";
        /** @phan-suppress-next-line PhanParamSuspiciousOrder */
        $value = floatval($value) * (1 << (+strpos(".kmg", $last) * 10));
    }
    return (int) ceil($value);
}


// email and MIME helpers

/** @param string $email
 * @return bool */
function validate_email($email) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    return preg_match('/\A[-!#$%&\'*+.\/0-9=?A-Z^_`a-z{|}~]+@(?:_\.|(?:[-0-9A-Za-z]+\.)+)[0-9A-Za-z]+\z/', $email);
}

/** @param string $s
 * @param int $pos
 * @return ?string */
function validate_email_at($s, $pos) {
    // Allow @_.com email addresses.  Simpler than RFC822 validation.
    if (preg_match('/\G[-!#$%&\'*+.\/0-9=?A-Z^_`a-z{|}~]+@(?:_\.|(?:[-0-9A-Za-z]+\.)+)[0-9A-Za-z]+(?=\z|[-,.;:()\[\]{}\s]|–|—)/', $s, $m, 0, $pos)) {
        return $m[0];
    } else {
        return null;
    }
}

/** @param string $word
 * @return string */
function mime_quote_string($word) {
    return '"' . preg_replace('/(?=[\x00-\x1F\\"])/', '\\', $word) . '"';
}

/** @param string $word
 * @return string */
function mime_token_quote($word) {
    if (preg_match('/\A[^][\x00-\x20\x80-\xFF()<>@,;:\\"\/?=]+\z/', $word)) {
        return $word;
    } else {
        return mime_quote_string($word);
    }
}

/** @param string $words
 * @return string */
function rfc2822_words_quote($words) {
    // NB: Do not allow `'` in an unquoted <phrase>; Proofpoint can add quotes
    // to names containing `'`, which invalidates a DKIM signature.
    if (preg_match('/\A[-A-Za-z0-9!#$%&*+\/=?^_`{|}~ \t]*\z/', $words)) {
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
        $x[$i] = sprintf("_%02X", ord($x[$i]));
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
    return rtrim(str_replace(["+", "/"], ["-", "_"], base64_encode($text)), "=");
}

/** @param string $text
 * @return string */
function base64url_decode($text) {
    return base64_decode(str_replace(["-", "_"], ["+", "/"], $text));
}

/** @param string $text
 * @return bool */
function is_base64url_string($text) {
    return preg_match('/\A[-_A-Za-z0-9]*\z/', $text);
}


// JSON encoding helpers

if (defined("JSON_UNESCAPED_LINE_TERMINATORS")) {
    // JSON_UNESCAPED_UNICODE is only safe to send to the browser if
    // JSON_UNESCAPED_LINE_TERMINATORS is defined.
    /** @return string */
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags | JSON_UNESCAPED_UNICODE);
    }
} else {
    /** @return string */
    function json_encode_browser($x, $flags = 0) {
        return json_encode($x, $flags);
    }
}

/** @return string */
function json_encode_db($x, $flags = 0) {
    return json_encode($x, $flags | JSON_UNESCAPED_UNICODE);
}

/** @param ?string $x
 * @return ?object */
function json_decode_object($x) {
    if ($x === null || $x === "" || !is_object(($j = json_decode($x)))) {
        return null;
    }
    return $j;
}

/** @param ?string &$s
 * @param ?object &$x
 * @param mixed $k
 * @param mixed $v
 * @param 1|2 $n
 * @return bool */
function json_encode_object_change(&$s, &$x, $k, $v, $n) {
    if ($n === 1) {
        if ($k === null || is_string($k)) {
            $news = $k;
        } else {
            $news = json_encode_db($k);
        }
        if ($s === $news) {
            return false;
        }
        $s = $news;
        $x = null;
        return true;
    }
    assert(is_string($k));
    if ($x === null) {
        $x = json_decode_object($s);
    }
    if (($x->$k ?? null) === $v) {
        return false;
    }
    if ($v !== null) {
        $x = $x ?? (object) [];
        $x->$k = $v;
    } else {
        unset($x->$k);
    }
    $s = json_encode_db($x);
    return true;
}


// array and object helpers

/** @param string $needle
 * @param list<string> $haystack
 * @return int */
function str_list_lower_bound($needle, $haystack) {
    $l = 0;
    $r = count($haystack);
    while ($l < $r) {
        $m = $l + (($r - $l) >> 1);
        $cmp = strcmp($needle, $haystack[$m]);
        if ($cmp <= 0) {
            $r = $m;
        } else {
            $l = $m + 1;
        }
    }
    return $l;
}

/** @param mixed $a */
function array_to_object_recursive($a) {
    if (is_array($a) && is_associative_array($a)) {
        $o = (object) [];
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

const OBJECT_REPLACE_NO_RECURSE = "norecurse\000";

/** @param object $a
 * @param array|object $b
 * @return void */
function object_replace_recursive($a, $b) {
    $ba = is_object($b) ? get_object_vars($b) : $b;
    if ($ba[OBJECT_REPLACE_NO_RECURSE] ?? null) {
        foreach (array_keys(get_object_vars($a)) as $ak) {
            unset($a->$ak);
        }
    }
    foreach ($ba as $k => $v) {
        if (is_object($v) || is_associative_array($v)) {
            if (!is_object($a->$k ?? null)) {
                $a->$k = (object) [];
            }
            object_replace_recursive($a->$k, $v);
        } else if ($v !== null && $k !== OBJECT_REPLACE_NO_RECURSE) {
            $a->$k = $v;
        } else {
            unset($a->$k);
        }
    }
}

/** @param ?string $a
 * @param array|object $b
 * @return ?string */
function json_object_replace_recursive($a, $b) {
    $ax = $a ? json_decode($a) : (object) [];
    $ao = is_object($ax) ? $ax : (object) [];
    object_replace_recursive($ao, $b);
    $s = json_encode_db($ao);
    return $s !== "{}" ? $s : null;
}


// debug helpers

function caller_landmark($position = 1, $skipfunction_re = null) {
    if (is_string($position)) {
        $skipfunction_re = $position;
        $position = 1;
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

/** @param ?Throwable $ex
 * @return string */
function debug_string_backtrace($ex = null, $limit = 32) {
    $tr = $ex ? $ex->getTrace() : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 2);
    $s = [];
    $delta = $ex ? 0 : 1;
    for ($i = $delta; $i !== count($tr); ++$i) {
        $frame = $i - $delta;
        if ($frame === $limit) {
            $s[] = "#{$frame} [...]\n";
            break;
        }
        $fi = $tr[$i]["file"] ?? "<internal>";
        if (SiteLoader::$root && str_starts_with($fi, SiteLoader::$root)) {
            $fi = "[" . (Conf::$main ? Conf::$main->dbname : "HotCRP") . "]" . substr($fi, strlen(SiteLoader::$root));
        }
        $ln = isset($tr[$i]["line"]) ? ":" . $tr[$i]["line"] : "";
        if (($fn = $tr[$i]["function"] ?? "") !== "") {
            $cl = $tr[$i]["class"] ?? "";
            $ty = $tr[$i]["type"] ?? "";
            $fn = ": {$cl}{$ty}{$fn}()";
        }
        $s[] = "#{$frame} {$fi}{$ln}{$fn}\n";
    }
    return join("", $s);
}


// zlib helper

if (!function_exists("zlib_get_coding_type")) {
    /** @return bool
     * @phan-suppress-next-line PhanRedefineFunctionInternal */
    function zlib_get_coding_type() {
        return false;
    }
}


// pcntl helpers

if (function_exists("pcntl_wifexited") && pcntl_wifexited(0) !== null) {
    /** @param int $status
     * @param int $exitstatus
     * @return bool */
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return pcntl_wifexited($status) && pcntl_wexitstatus($status) == $exitstatus;
    }
} else {
    /** @param int $status
     * @param int $exitstatus
     * @return bool */
    function pcntl_wifexitedwith($status, $exitstatus = 0) {
        return ($status & 0xff7f) == ($exitstatus << 8);
    }
}


// tempdir helper

/** @param string $tempdir */
function rm_rf_tempdir($tempdir) {
    assert(str_starts_with($tempdir, "/"));
    exec("/bin/rm -rf " . escapeshellarg($tempdir));
}

/** @param int $mode
 * @return string|false */
function tempdir($mode = 0700) {
    $dir = sys_get_temp_dir() ? : "/";
    while (substr($dir, -1) === "/") {
        $dir = substr($dir, 0, -1);
    }
    for ($i = 0; $i !== 100; $i++) {
        $path = sprintf("%s/hotcrptmp%07d", $dir, mt_rand(0, 9999999));
        if (mkdir($path, $mode)) {
            register_shutdown_function("rm_rf_tempdir", $path);
            return $path;
        }
    }
    return false;
}


/** @return Exception */
function error_get_last_as_exception($prefix) {
    $msg = preg_replace('/.*: /', "", error_get_last()["message"]);
    return new ErrorException($prefix . $msg);
}

/** @return string */
function file_get_contents_throw($filename) {
    $s = @file_get_contents($filename);
    if ($s === false) {
        throw error_get_last_as_exception("{$filename}: ");
    }
    return $s;
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


/** @return string */
function uuid_v4() {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . "-" . substr($h, 8, 4) . "-" . substr($h, 12, 4)
        . "-" . substr($h, 16, 4) . "-" . substr($h, 20);
}
