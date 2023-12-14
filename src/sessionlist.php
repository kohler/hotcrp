<?php
// sessionlist.php -- HotCRP helper class for lists carried across pageloads
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
    /** @var ?string */
    public $digest;
    /** @var ?int */
    public $curid;
    /** @var ?int */
    private $previd;
    /** @var ?int */
    private $nextid;
    /** @var null|false|int */
    private $id_index;

    /** @param string $listid
     * @param list<int> $ids
     * @param ?string $description */
    function __construct($listid, $ids, $description = null) {
        $this->listid = $listid;
        $this->ids = $ids;
        $this->description = $description;
    }

    /** @param string $url
     * @return $this */
    function set_url($url) {
        $this->url = $url;
        $this->urlbase = null;
        return $this;
    }

    /** @param string $urlbase
     * @return $this */
    function set_urlbase($urlbase) {
        $this->urlbase = $urlbase;
        return $this;
    }

    /** @return string */
    function list_type() {
        $pos = strpos($this->listid, "/");
        return $pos > 0 ? substr($this->listid, 0, $pos) : $this->listid;
    }

    /** @param string $s
     * @return ?list<int> */
    static function decode_ids($s) {
        if (str_starts_with($s, "[")
            && ($a = json_decode($s)) !== null) {
            return is_int_list($a) ? $a : null;
        }

        $a = [];
        $l = strlen($s);
        $next = null;
        $sign = 1;
        $include_after = false;
        for ($i = 0; $i !== $l; ) {
            $ch = ord($s[$i]);
            if ($ch >= 48 && $ch <= 57) {
                $n1 = 0;
                while ($ch >= 48 && $ch <= 57) {
                    $n1 = 10 * $n1 + $ch - 48;
                    ++$i;
                    $ch = $i !== $l ? ord($s[$i]) : 0;
                }
                $n2 = $n1;
                if ($ch === 45 && $i + 1 < $l && ctype_digit($s[$i + 1])) {
                    ++$i;
                    $ch = ord($s[$i]);
                    $n2 = 0;
                    while ($ch >= 48 && $ch <= 57) {
                        $n2 = 10 * $n2 + $ch - 48;
                        ++$i;
                        $ch = $i !== $l ? ord($s[$i]) : 0;
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

            ++$i;
            $add0 = $skip = 0;
            if ($ch >= 97 && $ch <= 104) { // a-h
                $add0 = $ch - 96;
            } else if ($ch >= 105 && $ch <= 112) { // i-p
                $skip = $ch - 104;
            } else if ($ch >= 117 && $ch <= 120) { // u-x
                $next += ($ch - 116) * 8 * $sign;
                continue;
            } else if ($ch === 113 || $ch === 114 || $ch === 116) { // qrt
                $j = 0;
                while ($i !== $l && ctype_digit($s[$i])) {
                    $j = 10 * $j + ord($s[$i]) - 48;
                    ++$i;
                }
                if ($ch === 113) { // q
                    $add0 = $j;
                } else if ($ch === 114) { // r
                    $skip = $j;
                } else {
                    $skip = -$j;
                }
            } else if ($ch >= 65 && $ch <= 72) { // A-H
                $add0 = $ch - 64;
                $skip = 1;
            } else if ($ch >= 73 && $ch <= 80) { // I-P
                $add0 = $ch - 72;
                $skip = 2;
            } else if ($ch === 122) { // z
                $sign = -$sign;
            } else if ($ch === 90) { // Z
                $sign = -$sign;
                $skip = 2;
            } else if ($ch === 81 && $i === 1) { // Q
                $include_after = true;
                continue;
            } else if (($ch >= 9 && $ch <= 13) || $ch === 32
                       || $ch === 35 || $ch === 39 || $ch === 44
                       || $ch === 91 || $ch === 93 || $ch === 115) {
                // \s # ' , [ ] s : ignore
            } else {
                return null;
            }

            while ($add0 !== 0) {
                $a[] = $next;
                $next += $sign;
                --$add0;
            }
            $next += $skip * $sign;
            if ($skip !== 0 && $include_after) {
                $a[] = $next;
                $next += $sign;
            }
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
        // Q at start: i-p, r, t, Z, A-P are followed by implicit `a` (1 present paper)
        // a-h: range of 1-8 sequential present papers
        // i-p: range of 1-8 sequential missing papers
        // u, v, w, x: 8, 16, 24, 32 sequential missing papers
        // q<N>: range of <N> sequential present papers
        // r<N>: range of <N> sequential missing papers
        // t<N>: range of -<N> sequential missing papers
        // <N>[-<N>]: include <N>, set direction forwards
        // z: switch direction
        // Z: like z + j
        // A-H: like a-h + i
        // I-P: like a-h + j
        // [#s\[\],']: ignored
        $n = count($ids);
        $a = ["Q", (string) $ids[0]];
        '@phan-var list<string> $a';
        $sign = 1;
        $next = $ids[0] + 1;
        for ($i = 1; $i !== $n; ) {
            // maybe switch direction
            if ($ids[$i] < $next
                && ($sign === -1 || ($i + 1 !== $n && $ids[$i + 1] < $ids[$i]))) {
                $want_sign = -1;
            } else {
                $want_sign = 1;
            }
            if (($sign > 0) !== ($want_sign > 0)) {
                $sign = -$sign;
                $a[] = "z";
            }

            $skip = ($ids[$i] - $next) * $sign;
            $include = 1;
            while ($i + 1 !== $n && $ids[$i + 1] === $ids[$i] + $sign) {
                ++$i;
                ++$include;
            }
            $last = $a[count($a) - 1];
            if ($skip < 0) {
                if ($sign === 1 && $skip <= -100) {
                    $a[] = "s" . ($next + $skip);
                } else {
                    $a[] = "t" . -$skip;
                }
                --$include;
            } else if ($skip === 2 && $last === "z") {
                $a[count($a) - 1] = "Z";
                --$include;
            } else if ($skip === 1 && $last >= "a" && $last <= "h") {
                $a[count($a) - 1] = chr(ord($last) - 32);
                --$include;
            } else if ($skip === 2 && $last >= "a" && $last <= "h") {
                $a[count($a) - 1] = chr(ord($last) - 32 + 8);
                --$include;
            } else if ($skip >= 1 && $skip <= 8) {
                $a[] = chr(104 + $skip);
                --$include;
            } else if ($skip >= 9 && $skip <= 40) {
                $a[] = chr(116 + (($skip - 1) >> 3)) . chr(105 + (($skip - 1) & 7));
                --$include;
            } else if ($skip >= 41) {
                $a[] = "r" . $skip;
                --$include;
            }
            if ($include !== 0) {
                if ($include <= 8) {
                    $a[] = chr(96 + $include);
                } else if ($include <= 16) {
                    $a[] = "h" . chr(88 + $include);
                } else {
                    $a[] = "q" . $include;
                }
            }

            $next = $ids[$i] + $sign;
            ++$i;
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
            $listid = $j->listid ?? $type;
            if ($listid !== $type && !str_starts_with($listid, "{$type}/")) {
                return null;
            }

            $ids = $j->ids ?? null;
            if (is_string($ids)) {
                if (($ids = self::decode_ids($ids)) === null)
                    return null;
            } else if ($ids !== null && !is_int_list($ids)) {
                return null;
            }
            '@phan-var-force ?list<int> $ids';

            $digest = is_string($j->digest ?? null) ? $j->digest : null;
            '@phan-var-force ?string $digest';

            if ($ids !== null || $digest !== null) {
                $list = new SessionList($listid, $ids);
                if (isset($j->description) && is_string($j->description)) {
                    $list->description = $j->description;
                }
                if (isset($j->url) && is_string($j->url)) {
                    $list->url = $j->url;
                } else if (isset($j->urlbase) && is_string($j->urlbase)) {
                    $list->urlbase = $j->urlbase;
                }
                if (isset($j->highlight)) {
                    $list->highlight = $j->highlight;
                }
                $list->digest = $digest;
                if (isset($j->curid) && is_int($j->curid)) {
                    $list->curid = $j->curid;
                }
                if (isset($j->previd) && is_int($j->previd)) {
                    $list->previd = $j->previd;
                }
                if (isset($j->nextid) && is_int($j->nextid)) {
                    $list->nextid = $j->nextid;
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

    /** @param Contact $user
     * @return ?string */
    function full_site_relative_url($user) {
        $args = $user->hoturl_defaults();
        if ($this->url !== null) {
            $url = $this->url;
        } else if ($this->urlbase !== null) {
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
            if (!preg_match('/[&?]' . preg_quote($k) . '=/', $url)) {
                $sep = strpos($url, "?") === false ? "?" : "&";
                $url = "{$url}{$sep}{$k}={$v}";
            }
        }
        return $url;
    }

    /** @return string */
    function info_string() {
        $j = [];
        if ($this->listid !== null) {
            $j["listid"] = $this->listid;
        }
        if ($this->description !== null) {
            $j["description"] = $this->description;
        }
        if ($this->url !== null) {
            $j["url"] = $this->url;
        } else if ($this->urlbase !== null) {
            $j["urlbase"] = $this->urlbase;
        }
        if ($this->highlight !== null) {
            $j["highlight"] = $this->highlight;
        }
        if ($this->digest !== null) {
            $j["digest"] = $this->digest;
        }
        // The JS digest mechanism must remove all potentially-long components
        // of the sessionlist object, currently `ids` and `sorted_ids`.
        if ($this->ids !== null) {
            $j["ids"] = self::encode_ids($this->ids);
            if (strlen($j["ids"]) > 160) {
                $x = $this->ids;
                sort($x);
                $j["sorted_ids"] = self::encode_ids($x);
            }
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

    function set_cookie(Qrequest $qreq) {
        $t = round(microtime(true) * 1000);
        $qreq->set_cookie("hotlist-info-" . $t, $this->info_string(), Conf::$now + 20);
    }

    /** @param int $id
     * @return bool */
    function set_current_id($id) {
        if ($this->curid !== $id) {
            $this->curid = $this->previd = $this->nextid = null;
        }
        $this->id_index = $this->ids ? array_search($id, $this->ids) : false;
        return $this->id_index !== false;
    }

    /** @param int $delta
     * @return int|false */
    function neighbor_id($delta) {
        if ($delta === -1 && $this->previd !== null) {
            return $this->previd;
        } else if ($delta === 1 && $this->nextid !== null) {
            return $this->nextid;
        } else if (isset($this->curid) && $this->set_current_id($this->curid)) {
            $pos = $this->id_index + $delta;
            if ($pos >= 0 && isset($this->ids[$pos])) {
                return $this->ids[$pos];
            }
        }
        return false;
    }
}
