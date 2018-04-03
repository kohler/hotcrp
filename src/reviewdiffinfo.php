<?php
// reviewdiffinfo.php -- HotCRP class representing review diffs
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewDiffInfo {
    public $conf;
    public $prow;
    public $rrow;
    private $fields = [];
    private $newv = [];
    public $view_score = VIEWSCORE_FALSE;
    public $notify = false;
    public $notify_author = false;
    static private $use_xdiff = null;
    static private $has_xpatch = null;

    function __construct(PaperInfo $prow, ReviewInfo $rrow = null) {
        $this->conf = $prow->conf;
        $this->prow = $prow;
        $this->rrow = $rrow;
    }
    function add_field($f, $newv) {
        $this->fields[] = $f;
        $this->newv[] = $newv;
        $this->add_view_score($f->view_score);
    }
    function add_view_score($view_score) {
        if ($view_score > $this->view_score) {
            if ($view_score === VIEWSCORE_AUTHORDEC
                && $this->prow->outcome != 0
                && $this->prow->can_author_view_decision()) {
                $view_score = VIEWSCORE_AUTHOR;
            }
            $this->view_score = $view_score;
        }
    }
    function nonempty() {
        return $this->view_score > VIEWSCORE_FALSE;
    }
    function fields() {
        return $this->fields;
    }

    static private function check_xdiff() {
        if (self::$use_xdiff === null) {
            self::$use_xdiff = function_exists("xdiff_string_bdiff");
        }
        if (self::$has_xpatch === null) {
            self::$has_xpatch = function_exists("xdiff_string_bpatch");
        }
    }
    function make_patch($dir = 0) {
        if (!$this->rrow) {
            return null;
        }
        self::check_xdiff();
        $patch = [];
        foreach ($this->fields as $i => $f) {
            $sn = $f->short_id;
            $v = [$this->rrow->{$f->id}, $this->newv[$i]];
            if ($f->has_options) {
                $v[$dir] = (int) $v[$dir];
            } else if (self::$use_xdiff) {
                $bdiff = xdiff_string_bdiff($v[1 - $dir], $v[$dir]);
                if (strlen($bdiff) < strlen($v[$dir]) - 32) {
                    $patch[$sn . ":x"] = $bdiff;
                    continue;
                }
            }
            $patch[$sn] = $v[$dir];
        }
        return $patch;
    }
    static function unparse_patch($patch) {
        $upatch = [];
        $bdata = "";
        foreach ($patch as $n => $v) {
            if (str_ends_with($n, ":x")) {
                $upatch[$n] = [strlen($bdata), strlen($v)];
                $bdata .= $v;
            } else {
                $upatch[$n] = $v;
            }
        }
        $str = json_encode($upatch);
        if ($bdata !== "") {
            $str = strlen($str) . $str . $bdata;
        }
        return $str;
    }
    static function parse_patch($str) {
        $bdata = "";
        if ($str !== "" && ctype_digit($str[0])) {
            $pos = strpos($str, "{");
            $len = substr($str, 0, $pos);
            if (!ctype_digit($len)
                || ($len = intval($len, 10)) <= $pos
                || $len > strlen($str)) {
                return null;
            }
            $bdata = substr($str, $pos + $len);
            $str = substr($str, $pos, $len);
        }
        $data = json_decode($str);
        if (!is_object($data)) {
            return null;
        }
        $data = (array) $data;
        foreach ($data as $n => &$v) {
            if (is_array($v)
                && str_ends_with($n, ":x")
                && count($v) === 2
                && $v[0] < strlen($bdata)
                && $v[0] + $v[1] <= strlen($bdata)) {
                $v = substr($bdata, $v[0], $v[1]);
            }
        }
        return $data;
    }
    static function apply_patch(ReviewInfo $rrow, $patch) {
        self::check_xdiff();
        $ok = true;
        foreach ($patch as $n => $v) {
            if (str_ends_with($n, ":x")
                && is_string($v)
                && self::$has_xpatch
                && ($fi = ReviewInfo::field_info(substr($n, 0, -2), $rrow->conf))
                && !$fi->has_options) {
                $rrow->{$fi->id} = xdiff_string_bpatch($rrow->{$fi->id}, $v);
            } else if (($fi = ReviewInfo::field_info($n, $rrow->conf))) {
                $rrow->{$fi->id} = (string) $v;
            } else {
                $ok = false;
            }
        }
        return $ok;
    }
}
