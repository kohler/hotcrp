<?php
// reviewdiffinfo.php -- HotCRP class representing review diffs
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewDiffInfo {
    /** @var ReviewInfo
     * @readonly */
    public $rrow;
    /** @var array<string,mixed> */
    public $_old_prop = [];
    /** @var list<ReviewField> */
    private $_fields = [];
    /** @var ?int */
    private $_view_score;
    /** @var int */
    private $_x_view_score = VIEWSCORE_EMPTY;
    /** @var bool */
    public $notify = false;
    /** @var bool */
    public $notify_author = false;
    /** @var bool */
    public $notify_requester = false;
    /** @var ?dmp\diff_match_patch */
    private $_dmp;

    const VALIDATE_PATCH = false;

    function __construct(ReviewInfo $rrow) {
        $this->rrow = $rrow;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->_old_prop) && empty($this->_fields);
    }

    /** @return bool */
    function is_viewable() {
        return !empty($this->_fields) || $this->_x_view_score !== VIEWSCORE_EMPTY;
    }

    /** @return int */
    function view_score() {
        if ($this->_view_score === null) {
            $this->_view_score = $this->_x_view_score;
            foreach ($this->_fields as $f) {
                $vs = $f->view_score;
                if ($vs > $this->_view_score) {
                    if (!$f->test_exists($this->rrow)) {
                        $vs = min($vs, VIEWSCORE_REVIEWERONLY);
                    }
                    $this->_view_score = max($this->_view_score, $vs);
                }
            }
        }
        return $this->_view_score;
    }

    /** @param ReviewField $f */
    function mark_field($f) {
        $this->_fields[] = $f;
        $this->_view_score = null;
    }

    /** @param int $view_score */
    function mark_view_score($view_score) {
        $this->_x_view_score = max($this->_x_view_score, $view_score);
    }

    /** @return list<ReviewField> */
    function fields() {
        return $this->_fields;
    }

    /** @param string $s1
     * @param string $s2
     * @return string */
    private function dmp_hcdelta($s1, $s2, $line_histogram = false) {
        $this->_dmp = $this->_dmp ?? new dmp\diff_match_patch;
        $this->_dmp->Line_Histogram = $line_histogram;
        try {
            $diffs = $this->_dmp->diff($s1, $s2);
            $hcdelta = $this->_dmp->hcdelta_encode($diffs, true);

            if (self::VALIDATE_PATCH) {
                // validate that applyHCDelta can create $s2
                if ($this->_dmp->hcdelta_apply($s1, $hcdelta) !== $s2) {
                    throw new dmp\diff_exception("incorrect hcdelta_apply");
                }
            }

            return $hcdelta;
        } catch (dmp\diff_exception $ex) {
            error_log("problem encoding delta: " . $ex->getMessage());
            file_put_contents("/tmp/hotcrp-baddiff.txt",
                "###### " . $ex->getMessage()
                . "\n====== " . strlen($s1) . "\n" . $s1
                . "\n====== " . strlen($s2) . "\n" . $s2 . "\n\n",
                FILE_APPEND);
            return null;
        }
    }

    /** @param 0|1 $dir
     * @return array */
    function make_patch($dir) {
        $sfields = json_decode($this->_old_prop["sfields"] ?? "{}", true) ?? [];
        $tfields = json_decode($this->_old_prop["tfields"] ?? "{}", true) ?? [];
        $patch = [];
        foreach ($this->_fields as $i => $f) {
            $sn = $f->short_id;
            if ($f->main_storage) {
                $oldv = (int) $this->_old_prop[$f->main_storage];
                $oldv = $oldv > 0 ? $oldv : ($oldv < 0 ? 0 : null);
            } else {
                $oldv = ($f->is_sfield ? $sfields : $tfields)[$f->json_storage] ?? null;
            }
            $v = [$oldv, $this->rrow->finfoval($f)];
            if (is_string($v[0])
                && is_string($v[1])
                && $f instanceof Text_ReviewField) {
                $hcdelta = $this->dmp_hcdelta($v[1 - $dir], $v[$dir], true);
                if ($hcdelta !== null
                    && strlen($hcdelta) < strlen($v[$dir]) - 32) {
                    $patch["{$sn}:p"] = $hcdelta;
                    continue;
                }
            }
            $patch[$sn] = $v[$dir];
        }
        return $patch;
    }

    function apply_prop_changes_to(ReviewInfo $rrow) {
        assert($rrow->reviewId === $this->rrow->reviewId);
        foreach ($this->_old_prop as $name => $value) {
            if ($name !== "sfields" && $name !== "tfields") {
                $rrow->set_prop($name, $this->rrow->{$name});
            }
        }
        foreach ($this->_fields as $i => $f) {
            $rrow->set_fval_prop($f, $this->rrow->finfoval($f), true);
        }
    }

    /** @param string $prop
     * @return mixed */
    private function base_prop($prop) {
        if (array_key_exists($prop, $this->_old_prop)) {
            return $this->_old_prop[$prop];
        }
        return $this->rrow->$prop;
    }

    /** @param ?callable(?string,string|int|null...):void $stager */
    function save_history($stager = null) {
        assert($this->rrow->reviewId > 0);
        $patch = $this->make_patch(0);
        $rrow = $this->rrow;
        $xstager = $stager ?? [$rrow->conf, "qe"];
        $result = $xstager("insert into PaperReviewHistory set
            paperId=?, reviewId=?, reviewTime=?, reviewNextTime=?,
            contactId=?, reviewRound=?, reviewOrdinal=?, reviewType=?, reviewBlind=?,
            reviewModified=?, reviewSubmitted=?, timeDisplayed=?, timeApprovalRequested=?,
            reviewAuthorSeen=?, reviewAuthorModified=?,
            reviewNotified=?, reviewAuthorNotified=?,
            reviewEditVersion=?, rflags=?,
            revdelta=?",
            $rrow->paperId, $rrow->reviewId,
              $this->base_prop("reviewTime"), $rrow->reviewTime,
            $this->base_prop("contactId"), $this->base_prop("reviewRound"),
              $this->base_prop("reviewOrdinal"), $this->base_prop("reviewType"),
              $this->base_prop("reviewBlind"),
            $this->base_prop("reviewModified") ?? 0,
              $this->base_prop("reviewSubmitted") ?? 0,
              $this->base_prop("timeDisplayed") ?? 0,
              $this->base_prop("timeApprovalRequested") ?? 0,
            $this->base_prop("reviewAuthorSeen") ?? 0,
              $this->base_prop("reviewAuthorModified") ?? 0,
            $this->base_prop("reviewNotified") ?? 0,
              $this->base_prop("reviewAuthorNotified") ?? 0,
            $this->base_prop("reviewEditVersion") ?? 0,
              $this->base_prop("rflags") ?? 0,
            empty($patch) ? null : json_encode_db($patch));
        $result && $result->close();
    }

    /** @param array $patch
     * @return string */
    static function unparse_patch($patch) {
        $upatch = [];
        $bdata = "";
        foreach ($patch as $n => $v) {
            if (str_ends_with($n, ":x") || str_ends_with($n, ":p")) {
                $upatch[$n] = [strlen($bdata), strlen($v)];
                $bdata .= $v;
            } else {
                $upatch[$n] = $v;
            }
        }
        $str = json_encode_db($upatch);
        if ($bdata !== "") {
            $str = strlen($str) . $str . $bdata;
        }
        return $str;
    }

    /** @param string $str
     * @return ?array */
    static function parse_patch($str) {
        $bdata = "";
        if ($str !== "" && ctype_digit($str[0])) {
            $pos = strpos($str, "{");
            $lenx = substr($str, 0, $pos);
            if (!ctype_digit($lenx)
                || ($len = intval($lenx, 10)) <= $pos
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
                && (str_ends_with($n, ":x") || str_ends_with($n, ":p"))
                && count($v) === 2
                && $v[0] < strlen($bdata)
                && $v[0] + $v[1] <= strlen($bdata)) {
                $v = substr($bdata, $v[0], $v[1]);
            }
        }
        return $data;
    }

    /** @param array $patch
     * @return bool */
    static function apply_patch(ReviewInfo $rrow, $patch) {
        $ok = true;
        $has_xpatch = null;
        $dmp = null;
        foreach ($patch as $n => $v) {
            $nl = strlen($n);
            if ($nl > 2
                && $n[$nl - 2] === ":"
                && ($n[$nl - 1] === "p" || $n[$nl - 1] === "x")
                && is_string($v)
                && ($fi = ReviewFieldInfo::find($rrow->conf, substr($n, 0, -2)))
                && !$fi->is_sfield) {
                $oldv = $rrow->finfoval($fi) ?? "";
                if ($n[$nl - 1] === "p") {
                    $dmp = $dmp ?? new dmp\diff_match_patch;
                    $rrow->_set_finfoval($fi, $dmp->hcdelta_apply($oldv, $v));
                    continue;
                }
                if (($has_xpatch = $has_xpatch ?? function_exists("xdiff_string_bpatch"))) {
                    $rrow->_set_finfoval($fi, xdiff_string_bpatch($oldv, $v));
                    continue;
                }
            } else if (($fi = ReviewFieldInfo::find($rrow->conf, $n))) {
                $rrow->_set_finfoval($fi, $v);
                continue;
            }
            $ok = false;
        }
        $rrow->_seal_fstorage();
        $rrow->_assign_fields();
        return $ok;
    }
}
