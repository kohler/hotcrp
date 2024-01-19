<?php
// helpers.php -- HotCRP non-class helper functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// string helpers

/** @param null|int|string $value
 * @return int
 * @deprecated */
function cvtint($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        $ival = intval($v);
        if ($ival == floatval($v)) {
            return $ival;
        }
    }
    return $default;
}

/** @param null|int|string $s
 * @return ?int */
function stoi($s) {
    if ($s === null || is_int($s)) {
        return $s;
    }
    $v = trim((string) $s);
    if (!is_numeric($v)) {
        return null;
    }
    $iv = intval($v);
    if ($iv != floatval($v)) {
        return null;
    }
    return $iv;
}

/** @param null|int|float|string $s
 * @return null|int|float */
function stonum($s) {
    if ($s === null || is_int($s) || is_float($s)) {
        return $s;
    }
    $v = trim((string) $s);
    if (!is_numeric($v)) {
        return null;
    }
    $iv = intval($v);
    $fv = floatval($v);
    return $iv == $fv ? $iv : $fv;
}

/** @param null|int|float|string $value
 * @return int|float */
function cvtnum($value, $default = -1) {
    $v = trim((string) $value);
    if (is_numeric($v)) {
        return floatval($v);
    }
    return $default;
}


// web helpers

/** @param int|float $n
 * @return string */
function unparse_number_pm_html($n) {
    if ($n < 0) {
        return "−" . (-$n); // U+2212 MINUS
    } else if ($n > 0) {
        return "+" . $n;
    } else {
        return "0";
    }
}

/** @param int|float $n
 * @return string */
function unparse_number_pm_text($n) {
    if ($n < 0) {
        return "-" . (-$n);
    } else if ($n > 0) {
        return "+" . $n;
    } else {
        return "0";
    }
}

/** @param string $url
 * @param string $component
 * @return string */
function hoturl_add_raw($url, $component) {
    if (($pos = strpos($url, "#")) !== false) {
        $component .= substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    return $url . (strpos($url, "?") === false ? "?" : "&") . $component;
}


class JsonResult implements JsonSerializable, ArrayAccess {
    /** @var ?int */
    public $status;
    /** @var array<string,mixed> */
    public $content;
    /** @var bool */
    public $pretty_print;
    /** @var bool */
    public $minimal = false;

    /** @param int|array<string,mixed>|\stdClass|\JsonSerializable $a1
     * @param ?array<string,mixed> $a2 */
    function __construct($a1, $a2 = null) {
        if (is_int($a1)) {
            $this->status = $a1;
            $a2 = $a2 ?? ($this->status <= 299);
        } else {
            $a2 = $a1;
        }
        if ($a2 === true || $a2 === false) {
            $this->content = ["ok" => $a2];
        } else if ($a2 === null) {
            $this->content = [];
        } else if (is_object($a2)) {
            if ($a2 instanceof JsonSerializable) {
                $this->content = (array) $a2->jsonSerialize();
            } else {
                assert(!($a2 instanceof JsonResult));
                $this->content = (array) $a2;
            }
        } else if (is_string($a2)) {
            error_log("bad JsonResult with string " . debug_string_backtrace());
            assert($this->status && $this->status > 299);
            $this->content = ["ok" => false, "error" => $a2];
        } else {
            assert(is_associative_array($a2));
            $this->content = $a2;
        }
    }

    /** @param int $status
     * @param array<string,mixed> $content
     * @return JsonResult */
    static function make_minimal($status, $content) {
        $jr = new JsonResult(null);
        $jr->status = $status;
        $jr->content = $content;
        $jr->minimal = true;
        return $jr;
    }

    /** @param int $status
     * @param string $ftext
     * @return JsonResult */
    static function make_error($status, $ftext) {
        if (!Ftext::is_ftext($ftext)) {
            error_log("bad ftext `{$ftext}` " . debug_string_backtrace());
        }
        return new JsonResult($status, [
            "ok" => false, "message_list" => [MessageItem::error($ftext)]
        ]);
    }

    /** @param ?string $param
     * @param ?string $ftext
     * @return JsonResult */
    static function make_parameter_error($param, $ftext = null) {
        $mi = new MessageItem($param, $ftext ?? "<0>Parameter error", 2);
        return new JsonResult(400, ["ok" => false, "message_list" => [$mi]]);
    }

    /** @param ?string $param
     * @param ?string $ftext
     * @return JsonResult */
    static function make_missing_error($param, $ftext = null) {
        $mi = new MessageItem($param, $ftext ?? "<0>Parameter missing", 2);
        return new JsonResult(400, ["ok" => false, "message_list" => [$mi]]);
    }

    /** @param ?string $field
     * @param ?string $ftext
     * @return JsonResult */
    static function make_permission_error($field = null, $ftext = null) {
        $mi = new MessageItem($field, $ftext ?? "<0>Permission error", 2);
        return new JsonResult(403, ["ok" => false, "message_list" => [$mi]]);
    }

    /** @param ?string $field
     * @param ?string $ftext
     * @return JsonResult */
    static function make_not_found_error($field = null, $ftext = null) {
        $mi = new MessageItem($field, $ftext ?? "<0>Not found", 2);
        return new JsonResult(404, ["ok" => false, "message_list" => [$mi]]);
    }


    /** @param bool $pp
     * @return $this */
    function pretty_print($pp) {
        $this->pretty_print = $pp;
        return $this;
    }


    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return bool */
    function offsetExists($offset) {
        return isset($this->content[$offset]);
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return mixed */
    function &offsetGet($offset) {
        return $this->content[$offset];
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @param mixed $value
     * @return void */
    function offsetSet($offset, $value) {
        $this->content[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    /** @param string $offset
     * @return void */
    function offsetUnset($offset) {
        unset($this->content[$offset]);
    }


    /** @param ?bool $validated */
    function emit($validated = null) {
        if ($this->status && !$this->minimal) {
            if (!isset($this->content["ok"])) {
                $this->content["ok"] = $this->status <= 299;
            }
            if (!isset($this->content["status"])) {
                $this->content["status"] = $this->status;
            }
        } else if (isset($this->content["status"])) {
            $this->status = $this->content["status"];
        }
        if ($validated
            ?? (Qrequest::$main_request && Qrequest::$main_request->valid_token())) {
            // Don’t set status on unvalidated requests, since that can leak
            // information (e.g. via <link prefetch onerror>).
            if ($this->status) {
                http_response_code($this->status);
            }
            header("Access-Control-Allow-Origin: *");
        }
        header("Content-Type: application/json; charset=utf-8");
        if (Qrequest::$main_request && isset(Qrequest::$main_request->pprint)) {
            $pprint = friendly_boolean(Qrequest::$main_request->pprint);
        } else if ($this->pretty_print !== null) {
            $pprint = $this->pretty_print;
        } else {
            $pprint = Contact::$main_user && Contact::$main_user->is_bearer_authorized();
        }
        echo json_encode_browser($this->content, $pprint ? JSON_PRETTY_PRINT : 0), "\n";
    }

    /** @return never
     * @throws JsonCompletion */
    function complete() {
        throw new JsonCompletion($this);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->content;
    }
}

class Redirection extends Exception {
    /** @var string */
    public $url;
    /** @param string $url */
    function __construct($url) {
        parent::__construct("Redirect to $url");
        $this->url = $url;
    }
}

class PageCompletion extends Exception {
    function __construct() {
        parent::__construct("Page complete");
    }
}

class JsonCompletion extends Exception {
    /** @var JsonResult */
    public $result;
    /** @var bool */
    static public $allow_short_circuit = false;
    /** @param JsonResult $j */
    function __construct($j) {
        $this->result = $j;
    }
}

/** @param associative-array<string,mixed>|stdClass|JsonResult $json
 * @return never
 * @throws JsonCompletion
 * @suppress PhanTypeMissingReturn */
function json_exit($json) {
    $jr = $json instanceof JsonResult ? $json : new JsonResult($json);
    $jr->complete();
}

function foldupbutton($foldnum = 0, $content = "", $js = null) {
    $js = $js ?? [];
    if ($foldnum) {
        $js["data-fold-target"] = $foldnum;
    }
    $js["class"] = "ui q js-foldup";
    return Ht::link(expander(null, $foldnum) . $content, "#", $js);
}

/** @param ?bool $open
 * @param ?int $foldnum
 * @param ?string $open_tooltip
 * @return string */
function expander($open, $foldnum = null, $open_tooltip = null) {
    $f = $foldnum !== null;
    $foldnum = ($foldnum !== 0 ? $foldnum : "");
    $t = '<span class="expander">';
    if ($open !== true) {
        $t .= '<span class="in0' . ($f ? " fx{$foldnum}" : "") . '">' . Icons::ui_triangle(2) . '</span>';
    }
    if ($open !== false) {
        $t .= '<span class="in1' . ($f ? " fn{$foldnum}" : "");
        if ($open_tooltip) {
            $t .= ' need-tooltip" aria-label="' . htmlspecialchars($open_tooltip) . '" data-tooltip-anchor="e';
        }
        $t .= '">' . Icons::ui_triangle(1) . '</span>';
    }
    return $t . '</span>';
}


/** @param Contact|Author|ReviewInfo|CommentInfo $userlike
 * @return string */
function actas_link($userlike) {
    return '<a href="' . Conf::$main->selfurl(Qrequest::$main_request, ["actas" => $userlike->email])
        . '" tabindex="-1">' . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . Text::nameo($userlike, NAME_P)])
        . '</a>';
}


/** @return bool */
function clean_tempdirs() {
    $dir = sys_get_temp_dir() ? : "/";
    while (substr($dir, -1) === "/") {
        $dir = substr($dir, 0, -1);
    }
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false) {
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("{$dir}/{$fname}")
            && ($mtime = @filemtime("{$dir}/{$fname}")) !== false
            && $mtime < $now - 1800)
            rm_rf_tempdir("{$dir}/{$fname}");
    }
    closedir($dirh);
    return true;
}


// text helpers
/** @param array $what
 * @param string $prefix
 * @param string $joinword
 * @return string */
function prefix_commajoin($what, $prefix, $joinword = "and") {
    return commajoin(array_map(function ($x) use ($prefix) {
        return $prefix . $x;
    }, $what), $joinword);
}

/** @param iterable $range
 * @return list<string> */
function unparse_numrange_list($range) {
    $a = [];
    $format = $first = $last = null;
    $intval = $plen = 0;
    foreach ($range as $current) {
        if ($format !== null) {
            if (sprintf($format, $intval + 1) === (string) $current) {
                ++$intval;
                $last = $current;
                continue;
            } else if ($first === $last) {
                $a[] = $first;
            } else {
                /** @phan-suppress-next-line PhanTypeMismatchArgumentInternalReal */
                $a[] = $first . "–" . substr($last, $plen);
            }
        }
        if ($current !== "" && (is_int($current) || ctype_digit($current))) {
            $format = "%0" . strlen((string) $current) . "d";
            $plen = 0;
            $first = $last = $current;
            $intval = intval($current);
        } else if (preg_match('/\A(\D*)(\d+)\z/', $current, $m)) {
            $format = str_replace("%", "%%", $m[1]) . "%0" . strlen($m[2]) . "d";
            $plen = strlen($m[1]);
            $first = $last = $current;
            $intval = intval($m[2]);
        } else {
            $format = null;
            $a[] = $current;
        }
    }
    if ($format !== null && $first === $last) {
        $a[] = $first;
    } else if ($format !== null) {
        $a[] = $first . "–" . substr($last, $plen);
    }
    return $a;
}

/** @param iterable $range
 * @return string */
function numrangejoin($range) {
    return commajoin(unparse_numrange_list($range));
}

/** @param int|float|array $n
 * @param string $singular
 * @param ?string $plural
 * @return string */
function plural_word($n, $singular, $plural = null) {
    $z = is_array($n) ? count($n) : $n;
    if ($z == 1) {
        return $singular;
    } else if (($plural ?? "") !== "") {
        return $plural;
    } else {
        return pluralize($singular);
    }
}

/** @param string $s
 * @return string
 * @suppress PhanParamSuspiciousOrder */
function pluralize($s) {
    if ($s[0] === "t"
        && (str_starts_with($s, "this ") || str_starts_with($s, "that "))) {
        return ($s[2] === "i" ? "these " : "those ") . pluralize(substr($s, 5));
    }
    $len = strlen($s);
    $last = $s[$len - 1];
    if ($last === "s") {
        if ($s === "this") {
            return "these";
        } else if ($s === "has") {
            return "have";
        } else if ($s === "is") {
            return "are";
        } else {
            return "{$s}es";
        }
    } else if ($last === "h"
               && $len > 1
               && ($s[$len - 2] === "s" || $s[$len - 2] === "c")) {
        return "{$s}es";
    } else if ($last === "y"
               && $len > 1
               && strpos("bcdfgjklmnpqrstvxz", $s[$len - 2]) !== false) {
        return substr($s, 0, $len - 1) . "ies";
    } else if ($last === "t"
               && $s === "that") {
        return "those";
    } else if ($last === ")"
               && preg_match('/\A(.*?)(\s*\([^)]*\))\z/', $s, $m)) {
        return pluralize($m[1]) . $m[2];
    } else {
        return "{$s}s";
    }
}

/** @param int|float|array $n
 * @param string $singular
 * @param ?string $plural
 * @return string */
function plural($n, $singular, $plural = null) {
    $z = is_array($n) ? count($n) : $n;
    return "{$z} " . plural_word($z, $singular, $plural);
}

/** @param int $n
 * @return string */
function ordinal($n) {
    $x = abs($n);
    $x > 100 && ($x = $x % 100);
    $x > 20 && ($x = $x % 10);
    return $n . (["th", "st", "nd", "rd"])[$x > 3 ? 0 : $x];
}

/** @param int|float $n
 * @return string */
function unparse_byte_size($n) {
    if ($n > 999949999) {
        return (round($n / 10000000) / 100) . "GB";
    } else if ($n > 999499) {
        return (round($n / 100000) / 10) . "MB";
    } else if ($n > 9949) {
        return round($n / 1000) . "kB";
    } else if ($n > 0) {
        return (max(round($n / 100), 1) / 10) . "kB";
    } else {
        return "0B";
    }
}

/** @param int|float $n
 * @return string */
function unparse_byte_size_binary($n) {
    if ($n > 1073689395) {
        return (round($n / 10737418.24) / 100) . "GiB";
    } else if ($n > 1048063) {
        return (round($n / 104857.6) / 10) . "MiB";
    } else if ($n > 10188) {
        return round($n / 1024) . "KiB";
    } else if ($n > 0) {
        return (max(round($n / 102.4), 1) / 10) . "KiB";
    } else {
        return "0B";
    }
}

/** @param string $t
 * @return int */
function parse_latin_ordinal($t) {
    $t = strtoupper($t);
    if (!ctype_alpha($t)) {
        return -1;
    }
    $l = strlen($t) - 1;
    $ord = 0;
    $base = 1;
    while (true) {
        $ord += (ord($t[$l]) - 64) * $base;
        if ($l === 0) {
            break;
        }
        --$l;
        $base *= 26;
    }
    return $ord;
}

/** @param int $n
 * @return string */
function unparse_latin_ordinal($n) {
    assert($n >= 1);
    if ($n <= 26) {
        return chr($n + 64);
    } else {
        $t = "";
        while (true) {
            $t = chr((($n - 1) % 26) + 65) . $t;
            if ($n <= 26) {
                return $t;
            }
            $n = intval(($n - 1) / 26);
        }
    }
}

/** @param ?int $expertise
 * @return string */
function unparse_expertise($expertise) {
    if ($expertise === null) {
        return "";
    } else {
        return $expertise > 0 ? "X" : ($expertise == 0 ? "Y" : "Z");
    }
}

/** @param array{int,?int} $preference
 * @return string
 * @deprecated */
function unparse_preference($preference) {
    assert(is_array($preference)); // XXX remove
    $pv = $preference[0];
    $ev = $preference[1];
    if ($pv === null || $pv === false) {
        $pv = "0";
    }
    return $pv . unparse_expertise($ev);
}

/** @param int $revtype
 * @param bool $unfinished
 * @param ?string $classes
 * @return string */
function review_type_icon($revtype, $unfinished = false, $classes = null) {
    // see also script.js:review_form
    assert(!!$revtype);
    return '<span class="rto rt' . $revtype
        . ($revtype > 0 && $unfinished ? " rtinc" : "")
        . ($classes ? " " . $classes : "")
        . '" title="' . ReviewForm::$revtype_names_full[$revtype]
        . '"><span class="rti">' . ReviewForm::$revtype_icon_text[$revtype] . '</span></span>';
}

/** @return string */
function review_lead_icon() {
    return '<span class="rto rtlead" title="Lead"><span class="rti">L</span></span>';
}

/** @return string */
function review_shepherd_icon() {
    return '<span class="rto rtshep" title="Shepherd"><span class="rti">S</span></span>';
}


// Aims to return a random password string with at least
// `$length * 5` bits of entropy.
/** @param int $length
 * @return string */
function hotcrp_random_password($length = 14) {
    // XXX it is possible to correctly account for loss of entropy due
    // to use of consonant pairs; I have only estimated
    $bytes = random_bytes($length + 12);
    $blen = strlen($bytes) * 8;
    $bneed = $length * 5;
    $pw = "";
    for ($b = 0; $bneed > 0 && $b + 8 <= $blen; ) {
        $bidx = $b >> 3;
        $codeword = (ord($bytes[$bidx]) << ($b & 7)) & 255;
        if (($b & 7) > 0) {
            $codeword |= ord($bytes[$bidx + 1]) >> (8 - ($b & 7));
        }
        if ($codeword < 0x60) {
            $t = "aeiouy";
            $pw .= $t[($codeword >> 4) & 0x7];
            $bneed -= 4; // log2(3/8 * 1/6)
            $b += 4;
        } else if ($codeword < 0xC0) {
            $t = "bcdghjklmnprstvw";
            $pw .= $t[($codeword >> 1) & 0xF];
            $bneed -= 5.415; // log2(3/8 * 1/16)
            $b += 7;
        } else if ($codeword < 0xE0) {
            $t = "trcrbrfrthdrchphwrstspswprslclz";
            $pw .= substr($t, $codeword & 0x1E, 2);
            $bneed -= 6.415; // log2(1/8 * 1/16 * [fudge] ~1.5)
            $b += 7;
        } else {
            $t = "23456789";
            $pw .= $t[($codeword >> 2) & 0x7];
            $bneed -= 6; // log2(1/8 * 1/8)
            $b += 6;
        }
    }
    return $pw;
}


/** @param int|string $x
 * @param string $format
 * @return string */
function encode_token($x, $format = "") {
    $s = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $t = "";
    if (is_int($x)) {
        $format = "V";
    }
    if ($format) {
        $x = pack($format, $x);
    }
    $i = 0;
    $have = 0;
    $n = 0;
    while ($have > 0 || $i < strlen($x)) {
        if ($have < 5 && $i < strlen($x)) {
            $n += ord($x[$i]) << $have;
            $have += 8;
            ++$i;
        }
        $t .= $s[$n & 31];
        $n >>= 5;
        $have -= 5;
    }
    if ($format === "V") {
        return preg_replace('/(\AA|[^A])A*\z/', '$1', $t);
    } else {
        return $t;
    }
}

/** @param string $x
 * @param string $format */
function decode_token($x, $format = "") {
    $map = "//HIJKLMNO///////01234567/89:;</=>?@ABCDEFG";
    $t = "";
    $n = $have = 0;
    $x = trim(strtoupper($x));
    for ($i = 0; $i < strlen($x); ++$i) {
        $o = ord($x[$i]);
        if ($o >= 48 && $o <= 90 && ($out = ord($map[$o - 48])) >= 48) {
            $o = $out - 48;
        } else if ($o === 46 /*.*/ || $o === 34 /*"*/) {
            continue;
        } else {
            return false;
        }
        $n += $o << $have;
        $have += 5;
        while ($have >= 8 || ($n && $i === strlen($x) - 1)) {
            $t .= chr($n & 255);
            $n >>= 8;
            $have -= 8;
        }
    }
    if ($format == "V") {
        $x = unpack("Vx", $t . "\x00\x00\x00\x00\x00\x00\x00");
        return $x["x"];
    } else if ($format) {
        return unpack($format, $t);
    } else {
        return $t;
    }
}

/** @param string $bytes
 * @return string */
function base48_encode($bytes) {
    $convtab = "ABCDEFGHJKLMNPQRSTUVWXYabcdefghijkmnopqrstuvwxyz";
    $bi = 0;
    $blen = strlen($bytes);
    $have = $w = 0;
    $t = "";
    while ($bi !== $blen || $have > 0) {
        while ($have < 11 && $bi !== $blen) {
            $w |= ord($bytes[$bi]) << $have;
            $have += 8;
            ++$bi;
        }
        $x = $w & 0x7FF;
        $t .= $convtab[$x % 48];
        if ($have > 5) {
            $t .= $convtab[(int) ($x / 48)];
        }
        $w >>= 11;
        $have -= 11;
    }
    return $t;
}

/** @param string $text
 * @return string|false */
function base48_decode($text) {
    $revconvtab = "01234567 89:;< =>?@ABCDEF       GHIJKLMNOPQ RSTUVWXYZ[\\]^_";
    $ti = 0;
    $tlen = strlen($text);
    $have = $w = 0;
    $b = "";
    while ($ti !== $tlen) {
        $chunk = $idx = 0;
        while ($ti !== $tlen && $idx !== 2) {
            $ch = ord($text[$ti]);
            if ($ch < 65 || $ch > 122 || ($n = ord($revconvtab[$ch - 65]) - 48) < 0) {
                return false;
            }
            ++$ti;
            $chunk += $idx ? $n * 48 : $n;
            ++$idx;
        }
        if ($idx !== 0) {
            $w |= $chunk << $have;
            $have += $idx === 1 ? 5 : 11;
        }
        while ($have >= 8) {
            $b .= chr($w & 255);
            $w >>= 8;
            $have -= 8;
        }
    }
    return $b;
}
