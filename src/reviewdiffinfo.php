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
    /** @var ?dmp\diff_match_patch */
    private $_dmp;

    const VALIDATE_PATCH = true;

    function __construct(ReviewInfo $rrow) {
        $this->rrow = $rrow;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->_fields) && $this->_x_view_score === VIEWSCORE_EMPTY;
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
            $hcdelta = $this->_dmp->diff_toHCDelta($diffs, true);

            if (self::VALIDATE_PATCH) {
                // validate that applyHCDelta can create $s2
                if ($this->_dmp->diff_applyHCDelta($s1, $hcdelta) !== $s2) {
                    throw new dmp\diff_exception("incorrect diff_applyHCDelta");
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
        $use_xdiff = $this->rrow->conf->opt("diffMethod") === "xdiff";
        $sfields = json_decode($this->_old_prop["sfields"] ?? "{}", true) ?? [];
        $tfields = json_decode($this->_old_prop["tfields"] ?? "{}", true) ?? [];
        $patch = [];
        foreach ($this->_fields as $i => $f) {
            $sn = $f->short_id;
            if ($f->main_storage) {
                $oldv = $this->_old_prop[$f->main_storage];
                $oldv = $oldv > 0 ? $oldv : ($oldv < 0 ? 0 : null);
            } else {
                $oldv = ($f->is_sfield ? $sfields : $tfields)[$f->json_storage] ?? null;
            }
            $v = [$oldv, $this->rrow->finfoval($f)];
            if (!($f instanceof Text_ReviewField)) {
                $v[$dir] = (int) $v[$dir];
            } else if (is_string($v[0]) && is_string($v[1])) {
                if ($use_xdiff) {
                    $bdiff = xdiff_string_bdiff($v[1 - $dir], $v[$dir]);
                    if (strlen($bdiff) < strlen($v[$dir]) - 32) {
                        $patch["{$sn}:x"] = $bdiff;
                        continue;
                    }
                } else {
                    $hcdelta = $this->dmp_hcdelta($v[1 - $dir], $v[$dir], false);
                    $hcdelta = $this->dmp_hcdelta($v[1 - $dir], $v[$dir], true);
                    if ($hcdelta !== null
                        && strlen($hcdelta) < strlen($v[$dir]) - 32) {
                        $patch["{$sn}:p"] = $hcdelta;
                        continue;
                    }
                }
            }
            $patch[$sn] = $v[$dir];
        }
        return $patch;
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
            reviewEditVersion=?,
            revdelta=?",
            $rrow->paperId, $rrow->reviewId,
              $rrow->base_prop("reviewTime"), $rrow->reviewTime,
            $rrow->base_prop("contactId"), $rrow->base_prop("reviewRound"),
              $rrow->base_prop("reviewOrdinal"), $rrow->base_prop("reviewType"),
              $rrow->base_prop("reviewBlind"),
            $rrow->base_prop("reviewModified") ?? 0,
              $rrow->base_prop("reviewSubmitted") ?? 0,
              $rrow->base_prop("timeDisplayed") ?? 0,
              $rrow->base_prop("timeApprovalRequested") ?? 0,
            $rrow->base_prop("reviewAuthorSeen") ?? 0,
              $rrow->base_prop("reviewAuthorModified") ?? 0,
            $rrow->base_prop("reviewNotified") ?? 0,
              $rrow->base_prop("reviewAuthorNotified") ?? 0,
            $rrow->base_prop("reviewEditVersion") ?? 0,
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
        $str = json_encode($upatch);
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
                    $rrow->_set_finfoval($fi, $dmp->diff_applyHCDelta($oldv, $v));
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
