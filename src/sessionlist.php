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

    static function decode_ids($ids) {
        if (strpos($ids, "-") === false && ($a = json_decode($ids)) !== null)
            return is_array($a) ? $a : [$a];
        $a = [];
        preg_match_all('/[-\d]+/', $ids, $m);
        foreach ($m[0] as $p)
            if (($pos = strpos($p, "-"))) {
                $j = (int) substr($p, $pos + 1);
                for ($i = (int) substr($p, 0, $pos); $i <= $j; ++$i)
                    $a[] = $i;
            } else
                $a[] = (int) $p;
        return $a;
    }
    static function encode_ids($ids) {
        $a = array();
        $p0 = $p1 = -100;
        foreach ($ids as $p) {
            if ($p1 + 1 != $p) {
                if ($p0 > 0)
                    $a[] = ($p0 == $p1 ? $p0 : "$p0-$p1");
                $p0 = $p;
            }
            $p1 = $p;
        }
        if ($p0 > 0)
            $a[] = ($p0 == $p1 ? $p0 : "$p0-$p1");
        return join("'", $a);
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
