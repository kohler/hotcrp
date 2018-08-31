<?php
// sessionlist.php -- HotCRP helper class for lists carried across pageloads
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class SessionList {
    public $listid;
    public $ids;
    public $description;
    public $url;
    public $urlbase;
    public $highlight;
    public $digest;
    public $curid;
    public $previd;
    public $nextid;
    public $id_position = false;

    function __construct($listid = null, $ids = null, $description = null, $urlbase = null) {
        $this->listid = $listid;
        $this->ids = $ids;
        $this->description = $description;
        $this->urlbase = $urlbase;
    }
    function list_type() {
        $pos = strpos($this->listid, "/");
        return $pos > 0 ? substr($this->listid, 0, $pos) : false;
    }

    static function decode_ids($s) {
        if (($a = json_decode($s)) !== null)
            return is_array($a) ? $a : [$a];
        $a = [];
        $l = strlen($s);
        $next = null;
        $sign = 1;
        for ($i = 0; $i < $l; ) {
            $ch = $s[$i];
            if (ctype_digit($ch)) {
                $n1 = 0;
                while (ctype_digit($ch)) {
                    $n1 = 10 * $n1 + ord($ch) - 48;
                    ++$i;
                    $ch = $i < $l ? $s[$i] : "s";
                }
                $n2 = $n1;
                if ($ch === "-" && $i + 1 < $l && ctype_digit($s[$i + 1])) {
                    ++$i;
                    $ch = $s[$i];
                    $n2 = 0;
                    while (ctype_digit($ch)) {
                        $n2 = 10 * $n2 + ord($ch) - 48;
                        ++$i;
                        $ch = $i < $l ? $s[$i] : "s";
                    }
                }
                while ($n1 <= $n2) {
                    $a[] = $n1;
                    ++$n1;
                }
                $next = $n1;
                $sign = 1;
                continue;
            }

            while ($ch === "z") {
                $sign = -$sign;
                ++$i;
                $ch = $i < $l ? $s[$i] : "s";
            }

            $include = true;
            $n = $skip = 0;
            if ($ch >= "a" && $ch <= "h")
                $n = ord($ch) - 96;
            else if ($ch >= "i" && $ch <= "p") {
                $n = ord($ch) - 104;
                $include = false;
            } else if ($ch === "q" || $ch === "r") {
                while ($i + 1 < $l && ctype_digit($s[$i + 1])) {
                    $n = 10 * $n + ord($s[$i + 1]) - 48;
                    ++$i;
                }
                $include = $ch === "q";
            } else if ($ch >= "A" && $ch <= "H") {
                $n = ord($ch) - 64;
                $skip = 1;
            } else if ($ch >= "I" && $ch <= "P") {
                $n = ord($ch) - 72;
                $skip = 2;
            }

            while ($n > 0 && $include) {
                $a[] = $next;
                $next += $sign;
                --$n;
            }
            $next += $sign * ($n + $skip);
            ++$i;
        }
        return $a;
    }
    static function encode_ids($ids) {
        if (empty($ids))
            return "";
        // a-h: range of 1-8 sequential present papers
        // i-p: range of 1-8 sequential missing papers
        // q<N>: range of <N> sequential present papers
        // r<N>: range of <N> sequential missing papers
        // <N>[-<N>]: include <N>
        // z: next range is backwards
        // A-H: like a-h + i
        // I-P: like a-h + j
        $a = [$ids[0]];
        $n = count($ids);
        $next = $ids[0] + 1;
        $sign = 1;
        for ($i = 1; $i < $n; ) {
            $delta = $ids[$i] - $next;
            if ($delta !== 0 && ($sign > 0) !== ($delta > 0)) {
                $sign = -$sign;
                $a[] = "z";
            }
            $delta = abs($delta);
            if (($delta === 1 || $delta === 2)
                && ($ch = $a[count($a) - 1]) >= "a"
                && $ch <= "h")
                $a[count($a) - 1] = chr(ord($ch) - 40 + 8 * $delta);
            else if ($delta > 0 && $delta <= 8)
                $a[] = chr(104 + $delta);
            else if ($delta >= 9)
                $a[] = "r" . $delta;

            $d = 1;
            $step = 1;
            if ($i + 1 < $n && $ids[$i + 1] < $ids[$i])
                $step = -1;
            if (($sign > 0) !== ($step > 0)) {
                $sign = -$sign;
                $a[] = "z";
            }
            while ($i + $d < $n && $ids[$i + $d] === $ids[$i] + $sign * $d)
                ++$d;
            if ($d === 1 && $delta >= 9) {
                while (($w = $a[count($a) - 1]) === "z" || $w[0] === "r")
                    array_pop($a);
                if (is_int($w) || ctype_digit($w[strlen($w) - 1]))
                    $a[] = "s";
                $a[] = $ids[$i];
                $sign = 1;
            } else if ($d >= 1 && $d <= 8)
                $a[] = chr(96 + $d);
            else
                $a[] = "q" . $d;

            $i += $d;
            $next = $ids[$i - 1] + $sign;
        }
        return join("", $a);
    }
    static function decode_info_string($info) {
        if (($j = json_decode($info))
            && (isset($j->ids) || isset($j->digest))) {
            $list = new SessionList;
            foreach ($j as $key => $value)
                $list->$key = $value;
            if (is_string($list->ids))
                $list->ids = self::decode_ids($list->ids);
            return $list;
        } else
            return null;
    }
    function full_site_relative_url() {
        $args = Conf::$hoturl_defaults ? : [];
        if ($this->url)
            $url = $this->url;
        else if ($this->urlbase) {
            $url = $this->urlbase;
            if (preg_match(',\Ap/[^/]*/([^/]*)(?:|/([^/]*))\z,', $this->listid, $m)) {
                if ($m[1] !== "" || str_starts_with($url, "search"))
                    $url .= (strpos($url, "?") ? "&" : "?") . "q=" . $m[1];
                if (isset($m[2]) && $m[2] !== "")
                    foreach (explode("&", $m[2]) as $kv) {
                        $eq = strpos($kv, "=");
                        $args[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
                    }
            }
        } else
            return null;
        foreach ($args as $k => $v) {
            if (!preg_match('{\A[&?]' . preg_quote($k) . '=}', $url))
                $url .= (strpos($url, "?") ? "&" : "?") . $k . "=" . $v;
        }
        return $url;
    }
    function info_string() {
        $j = [];
        if ($this->ids !== null)
            $j["ids"] = self::encode_ids($this->ids);
        foreach (get_object_vars($this) as $k => $v)
            if ($v != null
                && !in_array($k, ["ids", "id_position", "curid", "previd", "nextid"]))
                $j[$k] = $v;
        return json_encode_browser($j);
    }
    function set_cookie() {
        global $Conf, $Now;
        $Conf->set_cookie("hotlist-info", $this->info_string(), $Now + 20);
    }
    function set_current_id($id) {
        if ($this->curid !== $id)
            $this->curid = $this->previd = $this->nextid = null;
        $this->id_position = $this->ids ? array_search($id, $this->ids) : false;
        return $this->id_position === false ? null : $this;
    }
    function neighbor_id($delta) {
        if ($this->id_position !== false) {
            $pos = $this->id_position + $delta;
            if ($pos >= 0 && isset($this->ids[$pos]))
                return $this->ids[$pos];
        } else if ($delta === -1 && $this->previd !== null) {
            return $this->previd;
        } else if ($delta === 1 && $this->nextid !== null) {
            return $this->nextid;
        }
        return false;
    }
}
