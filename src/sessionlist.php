<?php
// sessionlist.php -- HotCRP helper class for lists carried across pageloads
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SessionList {
    /** @var string */
    public $listid;
    /** @var list<int> */
    public $ids;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $url;
    /** @var ?string */
    public $urlbase;
    public $highlight;
    public $digest;
    public $curid;
    public $previd;
    public $nextid;
    public $id_position = false;

    /** @param string $listid
     * @param list<int> $ids
     * @param ?string $description
     * @param ?string $urlbase */
    function __construct($listid, $ids, $description = null, $urlbase = null) {
        $this->listid = $listid;
        $this->ids = $ids;
        $this->description = $description;
        $this->urlbase = $urlbase;
    }

    /** @return string */
    function list_type() {
        $pos = strpos($this->listid, "/");
        return $pos > 0 ? substr($this->listid, 0, $pos) : $this->listid;
    }

    /** @param string $s
     * @return list<int>|false */
    static function decode_ids($s) {
        if (str_starts_with($s, "[")
            && ($a = json_decode($s)) !== null) {
            return is_int_list($a) ? $a : false;
        }

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
            if ($ch >= "a" && $ch <= "h") {
                $n = ord($ch) - 96;
            } else if ($ch >= "i" && $ch <= "p") {
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
            } else if (strspn($ch, "s[],0123456789'") !== 1) {
                error_log("bad SessionList decode_ids contains $ch"); // XXX delete this
                return false;
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

    /** @param list<string> $a
     * @return bool */
    static private function encoding_ends_numerically($a) {
        $w = $a[count($a) - 1];
        return is_int($w) || ctype_digit($w[strlen($w) - 1]);
    }

    /** @param list<string> $a
     * @return bool */
    static private function encoding_ends_with_r($a) {
        $w = $a[count($a) - 1];
        return !is_int($w) && $w[0] === "r";
    }

    /** @param list<int> $ids
     * @return string */
    static function encode_ids($ids) {
        if (empty($ids)) {
            return "";
        }
        // a-h: range of 1-8 sequential present papers
        // i-p: range of 1-8 sequential missing papers
        // q<N>: range of <N> sequential present papers
        // r<N>: range of <N> sequential missing papers
        // <N>[-<N>]: include <N>, set direction forwards
        // z: next range is backwards
        // A-H: like a-h + i
        // I-P: like a-h + j
        // [s\[\],']: ignored
        $n = count($ids);
        $a = [(string) $ids[0]];
        '@phan-var list<string> $a';
        $sign = 1;
        $next = $ids[0] + 1;
        for ($i = 1; $i < $n; ) {
            $delta = ($ids[$i] - $next) * $sign;
            if (($delta === 1 || $delta === 2)
                && ($ch = $a[count($a) - 1]) >= "a"
                && $ch <= "h") {
                $a[count($a) - 1] = chr(ord($ch) - 40 + 8 * $delta);
            } else if ($delta > 0 && $delta <= 8) {
                $a[] = chr(104 + $delta);
            } else if ($delta < 0 && $delta >= -8) {
                $sign = -$sign;
                $a[] = "z" . chr(104 - $delta);
            } else if ($delta >= 9) {
                $a[] = "r" . $delta;
            } else if ($delta !== 0) {
                if (self::encoding_ends_numerically($a)) {
                    $a[] = "s";
                }
                $a[] = (string) $ids[$i];
                $sign = 1;
                $next = $ids[$i] + 1;
                ++$i;
                continue;
            }

            $d = 1;
            $step = 1;
            if (($i + 1 < $n && $ids[$i + 1] === $ids[$i] - 1)
                || ($sign < 0 && ($i + 1 === $n || $ids[$i + 1] !== $ids[$i] + 1))) {
                $step = -1;
            }
            if (($sign > 0) !== ($step > 0)) {
                $sign = -$sign;
                $a[] = "z";
            }
            while ($i + $d < $n && $ids[$i + $d] === $ids[$i] + $sign * $d) {
                ++$d;
            }
            if ($d === 1 && self::encoding_ends_with_r($a)) {
                array_pop($a);
                if (self::encoding_ends_numerically($a)) {
                    $a[] = "s";
                }
                $a[] = (string) $ids[$i];
                $sign = 1;
            } else if ($d >= 1 && $d <= 8) {
                $a[] = chr(96 + $d);
            } else {
                $a[] = "q" . $d;
            }

            $i += $d;
            $next = $ids[$i - 1] + $sign;
        }
        return join("", $a);
    }

    /** @param string $info
     * @param string $type
     * @return ?SessionList */
    static function decode_info_string($user, $info, $type) {
        if (($j = json_decode($info))
            && is_object($j)
            && (!isset($j->listid) || is_string($j->listid))) {
            $listid = $j->listid ?? null;
            if ($listid !== $type && !str_starts_with($listid, "{$type}/")) {
                return null;
            }
            '@phan-var-force ?string $listid';

            $ids = $j->ids ?? null;
            if (is_string($ids)) {
                if (($ids = self::decode_ids($ids)) === false)
                    return null;
            } else if ($ids !== null && !is_int_list($ids)) {
                return null;
            }
            '@phan-var-force ?list<int> $ids';

            $digest = is_string($j->digest ?? null) ? $j->digest : null;
            '@phan-var-force ?string $digest';

            if ($ids !== null || $digest !== null) {
                $list = new SessionList($listid ?? $type, $ids);
                foreach (get_object_vars($j) as $k => $v) {
                    if ($k !== "listid" && $k !== "ids")
                        $list->$k = $v;
                }
                return $list;
            } else {
                return null;
            }
        }

        if ($type === "p"
            && str_starts_with($info, "p/")
            && ($args = PaperSearch::unparse_listid($info))) {
            $search = new PaperSearch($user, $args);
            return $search->session_list_object();
        }

        return null;
    }

    /** @return ?string */
    function full_site_relative_url() {
        $args = Conf::$hoturl_defaults ? : [];
        if ($this->url) {
            $url = $this->url;
        } else if ($this->urlbase) {
            $url = $this->urlbase;
            if (preg_match('/\Ap\/[^\/]*\/([^\/]*)(?:|\/([^\/]*))\z/', $this->listid, $m)) {
                if ($m[1] !== "" || str_starts_with($url, "search")) {
                    $url .= (strpos($url, "?") ? "&" : "?") . "q=" . $m[1];
                }
                if (isset($m[2]) && $m[2] !== "") {
                    foreach (explode("&", $m[2]) as $kv) {
                        $eq = strpos($kv, "=");
                        $args[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
                    }
                }
            }
        } else {
            return null;
        }
        foreach ($args as $k => $v) {
            if (!preg_match('{[&?]' . preg_quote($k) . '=}', $url))
                $url .= (strpos($url, "?") ? "&" : "?") . $k . "=" . $v;
        }
        return $url;
    }

    /** @return string */
    function info_string() {
        $j = [];
        if ($this->ids !== null) {
            $j["ids"] = self::encode_ids($this->ids);
        }
        foreach (get_object_vars($this) as $k => $v) {
            if ($v != null
                && !in_array($k, ["ids", "id_position", "curid", "previd", "nextid"], true))
                $j[$k] = $v;
        }
        return json_encode_browser($j);
    }

    /** @param 'p'|'u' $type
     * @return ?SessionList */
    static function load_cookie(Contact $user, $type) {
        $found = null;
        foreach ($_COOKIE as $k => $v) {
            if (($k === "hotlist-info" && $found === null)
                || (str_starts_with($k, "hotlist-info-")
                    && ($found === null || strnatcmp($k, $found) > 0)))
                $found = $k;
        }
        if ($found
            && ($list = SessionList::decode_info_string($user, $_COOKIE[$found], $type))
            && $list->list_type() === $type) {
            return $list;
        } else {
            return null;
        }
    }

    function set_cookie(Contact $user) {
        $t = round(microtime(true) * 1000);
        $user->conf->set_cookie("hotlist-info-" . $t, $this->info_string(), Conf::$now + 20);
    }

    /** @param int $id */
    function set_current_id($id) {
        if ($this->curid !== $id) {
            $this->curid = $this->previd = $this->nextid = null;
        }
        $this->id_position = $this->ids ? array_search($id, $this->ids) : false;
        return $this->id_position !== false;
    }

    /** @param int $delta */
    function neighbor_id($delta) {
        if ($this->id_position !== false) {
            $pos = $this->id_position + $delta;
            if ($pos >= 0 && isset($this->ids[$pos])) {
                return $this->ids[$pos];
            }
        } else if ($delta === -1 && $this->previd !== null) {
            return $this->previd;
        } else if ($delta === 1 && $this->nextid !== null) {
            return $this->nextid;
        }
        return false;
    }
}
