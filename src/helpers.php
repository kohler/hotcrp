<?php
// helpers.php -- HotCRP non-class helper functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

// string helpers

/** @param null|int|string $value
 * @return int */
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

/** @param string $page
 * @param null|string|array $param
 * @return string
 * @deprecated */
function hoturl($page, $param = null) {
    return Conf::$main->hoturl($page, $param);
}

/** @deprecated */
function hoturl_post($page, $param = null) {
    return Conf::$main->hoturl($page, $param, Conf::HOTURL_POST);
}


class JsonResult implements JsonSerializable, ArrayAccess {
    /** @var ?int */
    public $status;
    /** @var array<string,mixed> */
    public $content;

    /** @param int|array<string,mixed>|\stdClass|\JsonSerializable $a1
     * @param ?array<string,mixed> $a2 */
    function __construct($a1, $a2 = null) {
        if (is_int($a1)) {
            $this->status = $a1;
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

    /** @return JsonResult */
    static function make($jr, $arg2 = null) {
        if ($jr instanceof JsonResult) {
            return $jr;
        } else if (is_int($jr)) {
            return new JsonResult($jr, $arg2);
        } else {
            return new JsonResult($jr);
        }
    }

    /** @param int|string $a1
     * @param ?string $a2
     * @return JsonResult */
    static function make_error($a1, $a2 = null) {
        if (!is_int($a1)) {
            $a2 = $a1;
            $a1 = 400;
        }
        if (!Ftext::is_ftext($a2)) {
            error_log("bad ftext `{$a2}` " . debug_string_backtrace());
        }
        return new JsonResult($a1, ["ok" => false, "message_list" => [MessageItem::error($a2)]]);
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


    function export_messages(Conf $conf) {
        $ml = [];
        foreach ($this->content["message_list"] ?? [] as $mi) {
            if ($mi instanceof MessageItem) {
                $ml[] = $mi;
            } else {
                error_log("message_list is not MessageItem: " . debug_string_backtrace());
            }
        }
        if (empty($ml) && isset($this->content["error"])) {
            $ml[] = new MessageItem(null, "<0>" . $this->content["error"], 2);
        }
        if (empty($ml) && !($this->content["ok"] ?? ($this->status <= 299))) {
            $ml[] = new MessageItem(null, "<0>Internal error", 2);
        }
        $conf->feedback_msg($ml);
        foreach ($ml as $mi) {
            if ($mi->field)
                Ht::message_set()->append_item($mi);
        }
    }

    /** @param bool $validated */
    function emit($validated) {
        if ($this->status) {
            if (!isset($this->content["ok"])) {
                $this->content["ok"] = $this->status <= 299;
            }
            if (!isset($this->content["status"])) {
                $this->content["status"] = $this->status;
            }
        } else if (isset($this->content["status"])) {
            $this->status = $this->content["status"];
        }
        if ($validated) {
            // Don’t set status on unvalidated requests, since that can leak
            // information (e.g. via <link prefetch onerror>).
            if ($this->status) {
                http_response_code($this->status);
            }
            header("Access-Control-Allow-Origin: *");
        }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode_browser($this->content);
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
    /** @var int */
    static public $capturing = 0;
    /** @var bool */
    static public $allow_short_circuit = false;
    /** @param JsonResult $j */
    function __construct($j) {
        $this->result = $j;
    }
}

function json_exit($json, $arg2 = null) {
    $json = JsonResult::make($json, $arg2);
    if (JsonCompletion::$capturing > 0) {
        throw new JsonCompletion($json);
    } else {
        $json->emit(Qrequest::$main_request && Qrequest::$main_request->valid_token());
        exit;
    }
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
        $t .= '<span class="in0' . ($f ? " fx$foldnum" : "") . '">' . Icons::ui_triangle(2) . '</span>';
    }
    if ($open !== false) {
        $t .= '<span class="in1' . ($f ? " fn$foldnum" : "");
        if ($open_tooltip) {
            $t .= ' need-tooltip" data-tooltip="' . htmlspecialchars($open_tooltip) . '" data-tooltip-anchor="e';
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


function _one_quicklink($id, $baseUrl, $urlrest, $listtype, $isprev) {
    if ($listtype == "u") {
        $result = Dbl::ql("select email from ContactInfo where contactId=?", $id);
        $row = $result->fetch_row();
        Dbl::free($result);
        $paperText = htmlspecialchars($row ? $row[0] : $id);
        $urlrest["u"] = urlencode((string) $id);
    } else {
        $paperText = "#$id";
        $urlrest["p"] = $id;
    }
    return "<a id=\"quicklink-" . ($isprev ? "prev" : "next")
        . "\" class=\"ulh pnum\" href=\"" . Conf::$main->hoturl($baseUrl, $urlrest) . "\">"
        . ($isprev ? Icons::ui_linkarrow(3) : "")
        . $paperText
        . ($isprev ? "" : Icons::ui_linkarrow(1))
        . "</a>";
}

function goPaperForm($baseUrl = null, $args = []) {
    global $Me;
    if ($Me->is_empty()) {
        return "";
    }
    $x = Ht::form(Conf::$main->hoturl($baseUrl ? : "paper"), ["method" => "get", "class" => "gopaper"]);
    if ($baseUrl == "profile") {
        $x .= Ht::entry("u", "", ["id" => "quicklink-search", "size" => 15, "placeholder" => "User search", "aria-label" => "User search", "class" => "usersearch need-autogrow", "spellcheck" => false]);
    } else {
        $x .= Ht::entry("q", "", ["id" => "quicklink-search", "size" => 10, "placeholder" => "(All)", "aria-label" => "Search", "class" => "papersearch need-suggest need-autogrow", "spellcheck" => false]);
    }
    foreach ($args as $k => $v) {
        $x .= Ht::hidden($k, $v);
    }
    $x .= "&nbsp; " . Ht::submit("Search") . "</form>";
    return $x;
}

function clean_tempdirs() {
    $dir = sys_get_temp_dir() ? : "/";
    while (substr($dir, -1) === "/") {
        $dir = substr($dir, 0, -1);
    }
    $dirh = opendir($dir);
    $now = time();
    while (($fname = readdir($dirh)) !== false) {
        if (preg_match('/\Ahotcrptmp\d+\z/', $fname)
            && is_dir("$dir/$fname")
            && ($mtime = @filemtime("$dir/$fname")) !== false
            && $mtime < $now - 1800)
            rm_rf_tempdir("$dir/$fname");
    }
    closedir($dirh);
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
 * @return string */
function numrangejoin($range) {
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
    return commajoin($a);
}

/** @param int|float|array $n
 * @param string $what
 * @return string */
function pluralx($n, $what) {
    $z = is_array($n) ? count($n) : $n;
    return $z == 1 ? $what : pluralize($what);
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
 * @param string $what
 * @return string */
function plural($n, $what) {
    $z = is_array($n) ? count($n) : $n;
    return "$z " . pluralx($z, $what);
}

/** @param int $n
 * @return string */
function ordinal($n) {
    $x = $n;
    if ($x > 100) {
        $x = $x % 100;
    }
    if ($x > 20) {
        $x = $x % 10;
    }
    return $n . ($x < 1 || $x > 3 ? "th" : ($x == 1 ? "st" : ($x == 2 ? "nd" : "rd")));
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

/** @param ?string $mode
 * @param ?Qrequest $qreq
 * @return string */
function actionBar($mode = null, $qreq = null) {
    global $Me;
    if ($Me->is_disabled()) {
        return "";
    }

    $xmode = [];
    $listtype = "p";

    $goBase = "paper";
    if ($mode === "assign") {
        $goBase = "assign";
    } else if ($mode === "re") {
        $goBase = "review";
    } else if ($mode === "account") {
        $listtype = "u";
        if ($Me->privChair) {
            $goBase = "profile";
            $xmode["search"] = 1;
        }
    } else if ($mode === "edit") {
        $xmode["m"] = "edit";
    } else if ($qreq && ($qreq->m || $qreq->mode)) {
        $xmode["m"] = $qreq->m ? : $qreq->mode;
    }

    // quicklinks
    $x = "";
    if (($list = Conf::$main->active_list())) {
        $x .= '<td class="vbar quicklinks"';
        if ($xmode || $goBase !== "paper") {
            $x .= ' data-link-params="' . htmlspecialchars(json_encode_browser(["page" => $goBase] + $xmode)) . '"';
        }
        $x .= '>';
        if (($prev = $list->neighbor_id(-1)) !== false) {
            $x .= _one_quicklink($prev, $goBase, $xmode, $listtype, true) . " ";
        }
        if ($list->description) {
            $d = htmlspecialchars($list->description);
            $url = $list->full_site_relative_url();
            if ($url) {
                $x .= '<a id="quicklink-list" class="ulh" href="' . htmlspecialchars(Navigation::siteurl() . $url) . "\">{$d}</a>";
            } else {
                $x .= "<span id=\"quicklink-list\">{$d}</span>";
            }
        }
        if (($next = $list->neighbor_id(1)) !== false) {
            $x .= " " . _one_quicklink($next, $goBase, $xmode, $listtype, false);
        }
        $x .= '</td>';

        if ($Me->is_track_manager() && $listtype === "p") {
            $x .= '<td id="tracker-connect" class="vbar"><a id="tracker-connect-btn" class="ui js-tracker tbtn need-tooltip" href="" aria-label="Start meeting tracker">&#9759;</a><td>';
        }
    }

    // paper search form
    if ($Me->isPC || $Me->is_reviewer() || $Me->is_author()) {
        $x .= '<td class="vbar gopaper">' . goPaperForm($goBase, $xmode) . '</td>';
    }

    return '<table class="vbar"><tr>' . $x . '</tr></table>';
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
 * @return string */
function unparse_preference($preference) {
    assert(is_array($preference)); // XXX remove
    $pv = $preference[0];
    $ev = $preference[1];
    if ($pv === null || $pv === false) {
        $pv = "0";
    }
    return $pv . unparse_expertise($ev);
}

/** @param array{int,?int} $preference
 * @return string */
function unparse_preference_span($preference, $always = false) {
    assert(is_array($preference)); // XXX remove
    $pv = (int) $preference[0];
    $ev = $preference[1];
    $tv = (int) ($preference[2] ?? null);
    if ($pv > 0 || (!$pv && $tv > 0)) {
        $type = 1;
    } else if ($pv < 0 || $tv < 0) {
        $type = -1;
    } else {
        $type = 0;
    }
    $t = "";
    if ($pv || $ev !== null || $always) {
        $t .= "P" . unparse_number_pm_html($pv) . unparse_expertise($ev);
    }
    if ($tv && !$pv) {
        $t .= ($t ? " " : "") . "T" . unparse_number_pm_html($tv);
    }
    if ($t !== "") {
        $t = " <span class=\"asspref$type\">$t</span>";
    }
    return $t;
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
    $convtab = "abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXY";
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
    $revconvtab = "IJKLMNOP QRSTU VWXYZ[\\]^_       0123456789: ;<=>?@ABCDEFGH";
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
