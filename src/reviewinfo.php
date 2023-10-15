<?php
// reviewinfo.php -- HotCRP class representing reviews
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewInfo implements JsonSerializable {
    /** @var Conf */
    public $conf;
    /** @var ?PaperInfo */
    public $prow;

    // fields always present
    /** @var int */
    public $paperId;
    /** @var int */
    public $reviewId;
    /** @var int */
    public $contactId;
    /** @var int */
    public $requestedBy;
    /** @var int */
    public $reviewToken;
    /** @var int */
    public $reviewRound;
    /** @var int */
    public $reviewOrdinal;
    /** @var int */
    public $reviewType;
    /** @var int */
    public $reviewBlind;
    /** @var int */
    public $reviewTime;
    /** @var int */
    public $reviewModified;
    /** @var ?int */
    public $reviewSubmitted;
    /** @var ?int */
    public $reviewAuthorSeen;
    /** @var int */
    public $timeDisplayed;
    /** @var int */
    public $timeApprovalRequested;
    /** @var int */
    public $reviewNeedsSubmit;
    /** @var int */
    private $reviewViewScore;
    /** @var int */
    public $reviewStatus;

    // sometimes loaded
    /** @var ?int */
    public $timeRequested;
    /** @var ?int */
    public $timeRequestNotified;
    /** @var ?int */
    public $reviewAuthorModified;
    /** @var ?int */
    public $reviewNotified;
    /** @var ?int */
    public $reviewAuthorNotified;
    /** @var ?int */
    public $reviewEditVersion;  // NB also used to check if `data` was loaded
    /** @var ?int */
    public $reviewWordCount;
    /** @var ?string */
    private $tfields;
    /** @var ?string */
    private $sfields;
    /** @var ?string */
    private $data;
    /** @var ?object */
    private $_data;

    /** @var list<null|int|string> */
    public $fields;
    /** @var ?list<null|false|string> */
    private $_deaccent_fields;

    // scores
    // These scores are loaded from the database, but exposed only in `fields`
    private $s01;
    private $s02;
    private $s03;
    private $s04;
    private $s05;
    private $s06;
    private $s07;
    private $s08;
    private $s09;
    private $s10;
    private $s11;

    // sometimes joined
    /** @var ?bool */
    public $nameAmbiguous;
    /** @var ?string */
    public $ratingSignature;

    // other
    /** @var ?list<MessageItem> */
    public $message_list;
    /** @var ?array */
    private $_sfields;
    /** @var ?array */
    private $_tfields;
    /** @var ?list<ReviewHistoryInfo|ReviewInfo> */
    private $_history;
    /** @var ?Contact */
    private $_reviewer;
    /** @var ?ReviewDiffInfo */
    private $_diff;

    const VIEWSCORE_RECOMPUTE = -100;

    const RS_EMPTY = 0;
    const RS_ACCEPTED = 1;
    const RS_DRAFTED = 2;
    const RS_DELIVERED = 3;
    const RS_ADOPTED = 4;
    const RS_COMPLETED = 5;

    const RATING_GOODMASK = 1;
    const RATING_BADMASK = 126;
    const RATING_ANYMASK = 127;
    // See also script.js:unparse_ratings
    /** @var array<int,string>
     * @readonly */
    static public $rating_options = [
        1 => "good review", 2 => "needs work",
        4 => "too short", 8 => "too vague", 16 => "too narrow",
        32 => "disrespectful", 64 => "not correct"
    ];
    /** @var array<int,string>
     * @readonly */
    static public $rating_bits = [
        1 => "good", 2 => "needswork", 4 => "short", 8 => "vague",
        16 => "narrow", 32 => "disrespectful", 64 => "wrong"
    ];
    /** @var list<string>
     * @readonly */
    static public $rating_match_strings = [
        "good", "good-review", "goodreview",
        "needs-work", "needswork", "needs", "work",
        "too-short", "tooshort", "short",
        "too-vague", "toovague", "vague",
        "too-narrow", "toonarrow", "narrow",
        "disrespectful", "respect", "bias", "biased",
        "not-correct", "incorrect", "notcorrect", "wrong"
    ];
    /** @var list<int>
     * @readonly */
    static public $rating_match_bits = [
        1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 8, 8, 8, 16, 16, 16, 32, 32, 32, 32, 64, 64, 64, 64
    ];

    static private $type_map = [
        "meta" => REVIEW_META,
        "primary" => REVIEW_PRIMARY, "pri" => REVIEW_PRIMARY,
        "secondary" => REVIEW_SECONDARY, "sec" => REVIEW_SECONDARY,
        "optional" => REVIEW_PC, "opt" => REVIEW_PC, "pc" => REVIEW_PC,
        "external" => REVIEW_EXTERNAL, "ext" => REVIEW_EXTERNAL
    ];
    // see also assign.php, script.js
    static private $type_revmap = ["none", "external", "pc", "secondary", "primary", "meta"];

    /** @param string $str
     * @param bool $required
     * @return null|int|false */
    static function parse_type($str, $required) {
        $str = strtolower($str);
        if ($str === "review" || $str === "" || $str === "all" || $str === "any") {
            return null;
        }
        if (!$required && $str === "pc") {
            return false;
        }
        if (($l = strlen($str)) > 6 && str_ends_with($str, "review")) {
            $str = substr($str, 0, $l - ($str[$l - 6] === "-" ? 7 : 6));
        }
        return self::$type_map[$str] ?? false;
    }

    /** @param int $type
     * @return string */
    static function unparse_type($type) {
        return self::$type_revmap[$type];
    }

    /** @param int $type
     * @return string */
    static function unparse_assigner_action($type) {
        if ($type <= 0) {
            return "clearreview";
        } else {
            return self::$type_revmap[$type] . "review";
        }
    }


    /** @return ReviewInfo */
    static function make_blank(PaperInfo $prow = null, Contact $user) {
        $rrow = new ReviewInfo;
        $rrow->conf = $user->conf;
        $rrow->prow = $prow;
        $rrow->paperId = $prow ? $prow->paperId : 0;
        $rrow->reviewId = 0;
        $rrow->contactId = $user->contactId;
        $rrow->requestedBy = 0;
        $rrow->reviewToken = 0;
        $rrow->reviewRound = $rrow->conf->assignment_round(!$user->isPC);
        $rrow->reviewOrdinal = 0;
        $rrow->reviewType = $user->isPC ? REVIEW_PC : REVIEW_EXTERNAL;
        $rrow->reviewBlind = $rrow->conf->review_blindness() !== Conf::BLIND_NEVER ? 1 : 0;
        $rrow->reviewTime = 0;
        $rrow->reviewModified = 0;
        $rrow->timeDisplayed = 0;
        $rrow->timeApprovalRequested = 0;
        $rrow->reviewNeedsSubmit = 0;
        $rrow->reviewViewScore = -3;
        $rrow->reviewStatus = self::RS_EMPTY;
        $rrow->fields = $rrow->conf->review_form()->order_array(null);
        return $rrow;
    }

    private function _incorporate(PaperInfo $prow = null, Conf $conf = null) {
        $this->conf = $conf ?? $prow->conf;
        $this->prow = $prow;
        $this->paperId = (int) $this->paperId;
        assert($prow === null || $this->paperId === $prow->paperId);
        $this->reviewId = (int) $this->reviewId;
        $this->contactId = (int) $this->contactId;
        $this->requestedBy = (int) $this->requestedBy;
        $this->reviewToken = (int) $this->reviewToken;
        $this->reviewRound = (int) $this->reviewRound;
        $this->reviewOrdinal = (int) $this->reviewOrdinal;
        $this->reviewType = (int) $this->reviewType;
        $this->reviewBlind = (int) $this->reviewBlind;
        $this->reviewTime = (int) $this->reviewTime;
        $this->reviewModified = (int) $this->reviewModified;
        if ($this->reviewSubmitted !== null) {
            $this->reviewSubmitted = (int) $this->reviewSubmitted;
        }
        if ($this->reviewAuthorSeen !== null) {
            $this->reviewAuthorSeen = (int) $this->reviewAuthorSeen;
        }
        $this->timeDisplayed = (int) $this->timeDisplayed;
        $this->timeApprovalRequested = (int) $this->timeApprovalRequested;
        $this->reviewNeedsSubmit = (int) $this->reviewNeedsSubmit;
        $this->reviewViewScore = (int) $this->reviewViewScore;
        $this->reviewStatus = $this->compute_review_status();

        if ($this->timeRequested !== null) {
            $this->timeRequested = (int) $this->timeRequested;
        }
        if ($this->timeRequestNotified !== null) {
            $this->timeRequestNotified = (int) $this->timeRequestNotified;
        }
        if ($this->reviewAuthorModified !== null) {
            $this->reviewAuthorModified = (int) $this->reviewAuthorModified;
        }
        if ($this->reviewNotified !== null) {
            $this->reviewNotified = (int) $this->reviewNotified;
        }
        if ($this->reviewAuthorNotified !== null) {
            $this->reviewAuthorNotified = (int) $this->reviewAuthorNotified;
        }
        if ($this->reviewEditVersion !== null) {
            $this->reviewEditVersion = (int) $this->reviewEditVersion;
        }
        if ($this->reviewWordCount !== null) {
            $this->reviewWordCount = (int) $this->reviewWordCount;
        }

        $this->_assign_fields();
    }

    function _assign_fields() {
        assert($this->_sfields === null && $this->_tfields === null);
        $rform = $this->conf->review_form();
        $this->fields = $rform->order_array(null);
        $sfields = isset($this->sfields) ? json_decode($this->sfields, true) : null;
        $tfields = isset($this->tfields) ? json_decode($this->tfields, true) : null;
        foreach ($rform->all_fields() as $f) {
            if ($f->main_storage) {
                $fv = intval($this->{$f->main_storage} ?? "0");
                $fv = $fv > 0 ? $fv : ($fv < 0 ? 0 : null);
            } else if ($f->is_sfield) {
                $fv = $sfields[$f->json_storage] ?? null;
            } else {
                $fv = $tfields[$f->json_storage] ?? null;
            }
            $this->fields[$f->order] = $fv;
        }
    }

    /** @param PaperInfo|PaperInfoSet|null $prowx
     * @return ?ReviewInfo */
    static function fetch($result, $prowx = null, Conf $conf = null) {
        $rrow = $result ? $result->fetch_object("ReviewInfo") : null;
        '@phan-var ?ReviewInfo $rrow';
        if ($rrow) {
            $prow = $prowx instanceof PaperInfoSet ? $prowx->get((int) $rrow->paperId) : $prowx;
            $rrow->_incorporate($prow, $conf);
        }
        return $rrow;
    }

    /** @param Conf $conf @unused-param
     * @param ?list<ReviewField> $scores
     * @return string */
    static function review_signature_sql(Conf $conf, $scores = null) {
        $t = "r.reviewId, ' ', r.contactId, ' ', r.reviewToken, ' ', r.reviewType, ' ', r.reviewRound, ' ', r.requestedBy, ' ', r.reviewBlind, ' ', r.reviewModified, ' ', coalesce(r.reviewSubmitted,0), ' ', coalesce(r.reviewAuthorSeen,0), ' ', r.reviewOrdinal, ' ', r.timeDisplayed, ' ', r.timeApprovalRequested, ' ', r.reviewNeedsSubmit, ' ', r.reviewViewScore";
        foreach ($scores ?? [] as $f) {
            if ($f->order && $f->main_storage)
                $t .= ", ' {$f->order}=', {$f->main_storage}";
        }
        return "group_concat($t order by r.reviewId)";
    }

    /** @param string $signature
     * @return ReviewInfo */
    static function make_signature(PaperInfo $prow, $signature) {
        $rrow = new ReviewInfo;
        $rrow->conf = $prow->conf;
        $rrow->prow = $prow;
        $rrow->paperId = $prow->paperId;
        $vals = explode(" ", $signature);
        $rrow->reviewId = (int) $vals[0];
        $rrow->contactId = (int) $vals[1];
        $rrow->reviewToken = (int) $vals[2];
        $rrow->reviewType = (int) $vals[3];
        $rrow->reviewRound = (int) $vals[4];
        $rrow->requestedBy = (int) $vals[5];
        $rrow->reviewBlind = (int) $vals[6];
        $rrow->reviewModified = (int) $vals[7];
        $rrow->reviewSubmitted = (int) $vals[8];
        $rrow->reviewAuthorSeen = (int) $vals[9];
        $rrow->reviewOrdinal = (int) $vals[10];
        $rrow->timeDisplayed = (int) $vals[11];
        $rrow->timeApprovalRequested = (int) $vals[12];
        $rrow->reviewNeedsSubmit = (int) $vals[13];
        $rrow->reviewViewScore = (int) $vals[14];
        $rrow->reviewStatus = $rrow->compute_review_status();
        if (isset($vals[15])) {
            $rrow->fields = $prow->conf->review_form()->order_array(null);
            for ($i = 15; isset($vals[$i]); ++$i) {
                $eq = strpos($vals[$i], "=");
                $order = intval(substr($vals[$i], 0, $eq));
                $fv = intval(substr($vals[$i], $eq + 1));
                $fv = $fv > 0 ? $fv : ($fv < 0 ? 0 : null);
                $rrow->fields[$order] = $fv;
                $prow->_mark_has_review_field_order($order);
            }
        }
        return $rrow;
    }

    function set_prow(PaperInfo $prow) {
        assert(!$this->prow && $this->paperId === $prow->paperId && $this->conf === $prow->conf);
        $this->prow = $prow;
    }


    /** @return int */
    function compute_review_status() {
        if ($this->reviewSubmitted) {
            return self::RS_COMPLETED;
        } else if ($this->reviewType === REVIEW_EXTERNAL
                   && $this->timeApprovalRequested !== 0) {
            if ($this->timeApprovalRequested > 0) {
                return self::RS_DELIVERED;
            } else {
                return self::RS_ADOPTED;
            }
        } else if ($this->reviewModified === 0) {
            return self::RS_EMPTY;
        } else if ($this->reviewModified === 1) {
            return self::RS_ACCEPTED;
        } else {
            return self::RS_DRAFTED;
        }
    }

    /** @return bool */
    function is_subreview() {
        return $this->reviewType === REVIEW_EXTERNAL
            && $this->reviewStatus < ReviewInfo::RS_COMPLETED;
    }

    /** @param bool $hard
     * @return string */
    function deadline_name($hard = false) {
        return $this->conf->review_deadline_name($this->reviewRound, $this->reviewType, $hard);
    }

    /** @param bool $hard
     * @return ?int */
    function deadline($hard = false) {
        return $this->conf->setting($this->deadline_name($hard));
    }

    /** @return ?int */
    function mtime(Contact $viewer) {
        if (!$this->prow || !$viewer->can_view_review_time($this->prow, $this)) {
            return null;
        } else if ($viewer->view_score_bound($this->prow, $this) >= VIEWSCORE_AUTHORDEC - 1) {
            if (isset($this->reviewAuthorModified)) {
                return (int) $this->reviewAuthorModified;
            } else {
                $ran = (int) ($this->reviewAuthorNotified ?? 0);
                $rm = $this->reviewModified;
                if (!$ran || $rm - $ran <= ReviewForm::NOTIFICATION_DELAY) {
                    return $rm;
                } else {
                    return $ran;
                }
            }
        } else if ($this->reviewModified > 1) {
            return $this->reviewModified;
        } else {
            return (int) $this->timeRequested;
        }
    }

    /** @return bool */
    function subject_to_approval() {
        return $this->reviewType === REVIEW_EXTERNAL
            && $this->reviewStatus < ReviewInfo::RS_COMPLETED
            && $this->requestedBy !== 0
            && $this->conf->ext_subreviews > 1;
    }

    /** @return string */
    function round_name() {
        return $this->reviewRound ? $this->conf->round_name($this->reviewRound) : "";
    }

    /** @return string */
    function icon_h() {
        $title = $this->status_title(true);
        if ($title === "Review") {
            $title = ReviewForm::$revtype_names_full[$this->reviewType];
        }
        $t = '<span class="rto rt' . $this->reviewType;
        if ($this->reviewStatus < ReviewInfo::RS_COMPLETED) {
            if ($this->timeApprovalRequested < 0) {
                $t .= " rtsubrev";
            } else {
                $t .= " rtinc";
            }
            if ($title !== "Subreview" || $this->timeApprovalRequested >= 0) {
                $title .= " (" . $this->status_description() . ")";
            }
        }
        return $t . '" title="' . $title . '"><span class="rti">'
            . ReviewForm::$revtype_icon_text[$this->reviewType]
            . '</span></span>';
    }

    /** @param Conf $conf
     * @param int $round
     * @return string */
    static function make_round_h($conf, $round) {
        if ($round > 0 && ($n = $conf->round_name($round)) !== "") {
            $n = htmlspecialchars($n);
            return "<span class=\"revround\" title=\"Review round\">{$n}</span>";
        } else {
            return "";
        }
    }

    /** @return string */
    function round_h() {
        return self::make_round_h($this->conf, $this->reviewRound);
    }

    /** @return string */
    function status_title($ucfirst = false) {
        if ($this->reviewStatus <= ReviewInfo::RS_ACCEPTED
            && $this->reviewType < REVIEW_PC) {
            return $ucfirst ? "Request" : "request";
        } else if ($this->subject_to_approval()) {
            return $ucfirst ? "Subreview" : "subreview";
        } else {
            return $ucfirst ? "Review" : "review";
        }
    }

    /** @return string */
    function status_description() {
        if ($this->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            return "complete";
        } else if ($this->reviewStatus === ReviewInfo::RS_ADOPTED) {
            return "approved";
        } else if ($this->reviewStatus === ReviewInfo::RS_DELIVERED) {
            return "pending approval";
        } else if ($this->reviewStatus === ReviewInfo::RS_DRAFTED) {
            return "draft";
        } else if ($this->reviewType == REVIEW_SECONDARY
                   && $this->reviewNeedsSubmit <= 0
                   && $this->conf->ext_subreviews < 3) {
            return "delegated";
        } else if ($this->reviewStatus === ReviewInfo::RS_ACCEPTED) {
            return "accepted";
        } else if ($this->reviewType < REVIEW_PC) {
            return "outstanding";
        } else {
            return "not started";
        }
    }

    /** @return string */
    function unparse_ordinal_id() {
        if ($this->reviewOrdinal) {
            return $this->paperId . unparse_latin_ordinal($this->reviewOrdinal);
        } else if ($this->reviewId) {
            return "{$this->paperId}r{$this->reviewId}";
        } else if ($this->paperId) {
            return "{$this->paperId}rnew";
        } else {
            return "new";
        }
    }

    /** @return bool */
    function need_view_score() {
        return $this->reviewViewScore === self::VIEWSCORE_RECOMPUTE;
    }

    /** @return int */
    function view_score() {
        if ($this->reviewViewScore === self::VIEWSCORE_RECOMPUTE) {
            assert(!!$this->prow);
            $this->reviewViewScore = $this->prow->conf->review_form()->nonempty_view_score($this);
        }
        return $this->reviewViewScore;
    }


    /** @param ReviewField $f
     * @return null|int|string */
    function fval($f) {
        $fval = $this->fields[$f->order];
        if ($fval !== null && $f->test_exists($this)) {
            return $fval;
        } else {
            return null;
        }
    }

    /** @param string $fid
     * @return null|int|string */
    function fidval($fid) {
        $f = $this->conf->review_field($fid);
        if ($f && $f->order && $f->test_exists($this)) {
            return $this->fields[$f->order];
        } else {
            return null;
        }
    }

    /** @param bool $sfield
     * @return &array */
    private function &_fstorage($sfield) {
        $k = $sfield ? "_sfields" : "_tfields";
        if ($this->$k === null) {
            $xk = $sfield ? "sfields" : "tfields";
            $this->$k = json_decode($this->$xk ?? "{}", true) ?? [];
        }
        return $this->$k;
    }

    function _seal_fstorage() {
        if ($this->_sfields !== null) {
            $this->sfields = empty($this->_sfields) ? null : json_encode_db($this->_sfields);
        }
        if ($this->_tfields !== null) {
            $this->tfields = empty($this->_tfields) ? null : json_encode_db($this->_tfields);
        }
        $this->_sfields = $this->_tfields = null;
    }

    /** @param ReviewField|ReviewFieldInfo $finfo
     * @return null|int|string */
    function finfoval($finfo) {
        if ($finfo->main_storage) {
            $v = intval($this->{$finfo->main_storage} ?? "0");
            return $v > 0 ? $v : ($v < 0 ? 0 : null);
        } else {
            $a = &$this->_fstorage($finfo->is_sfield);
            return $a[$finfo->json_storage] ?? null;
        }
    }

    /** @param ReviewField|ReviewFieldInfo $finfo
     * @param null|int|string $v */
    function _set_finfoval($finfo, $v) {
        if ($finfo->main_storage) {
            $xv = is_int($v) ? ($v > 0 ? $v : -1) : 0;
            $this->{$finfo->main_storage} = (string) $xv;
        }
        if ($finfo->json_storage) {
            $a = &$this->_fstorage($finfo->is_sfield);
            if ($v === null) {
                unset($a[$finfo->json_storage]);
            } else {
                $a[$finfo->json_storage] = $v;
            }
        }
    }

    /** @param ReviewField $f
     * @param null|int|string $v
     * @param bool $diff */
    function set_fval_prop($f, $v, $diff) {
        $this->_diff = $this->_diff ?? new ReviewDiffInfo($this);
        if ($f->main_storage) {
            if (!array_key_exists($f->main_storage, $this->_diff->_old_prop)) {
                $this->_diff->_old_prop[$f->main_storage] = $this->{$f->main_storage};
            }
            $xv = is_int($v) ? ($v > 0 ? $v : -1) : 0;
            $this->{$f->main_storage} = (string) $xv;
        }
        if ($f->json_storage) {
            $k = $f->is_sfield ? "sfields" : "tfields";
            if (!array_key_exists($k, $this->_diff->_old_prop)) {
                $this->_diff->_old_prop[$k] = $this->$k;
            }
            $a = &$this->_fstorage($f->is_sfield);
            if ($v === null) {
                unset($a[$f->json_storage]);
            } else {
                $a[$f->json_storage] = $v;
            }
        }
        if ($f->order) {
            $this->fields[$f->order] = $v;
        }
        if ($diff) {
            $this->_diff->mark_field($f);
        }
    }

    /** @param string $prop
     * @param null|int|string $v */
    function set_prop($prop, $v) {
        $this->_diff = $this->_diff ?? new ReviewDiffInfo($this);
        if (!array_key_exists($prop, $this->_diff->_old_prop)) {
            $this->_diff->_old_prop[$prop] = $this->$prop;
        }
        $this->$prop = $v;
    }

    /** @param int $view_score */
    function mark_prop_view_score($view_score) {
        $this->_diff = $this->_diff ?? new ReviewDiffInfo($this);
        $this->_diff->mark_view_score($view_score);
    }

    /** @return ReviewDiffInfo */
    function prop_diff() {
        $this->_diff = $this->_diff ?? new ReviewDiffInfo($this);
        return $this->_diff;
    }

    /** @param string $prop
     * @return mixed */
    function prop($prop) {
        return $this->$prop;
    }

    /** @param string $prop
     * @return mixed */
    function base_prop($prop) {
        if ($this->_diff !== null
            && array_key_exists($prop, $this->_diff->_old_prop)) {
            return $this->_diff->_old_prop[$prop];
        }
        return $this->$prop;
    }

    /** @param ?string $prop
     * @return bool */
    function prop_changed($prop = null) {
        return $this->_diff
            && !$this->_diff->is_empty()
            && (!$prop || array_key_exists($prop, $this->_diff->_old_prop));
    }

    const SAVE_PROP_STAGED = 2;
    const SAVE_PROP_OK = 1;
    const SAVE_PROP_EMPTY = 0;
    const SAVE_PROP_CONFLICT = -1;
    const SAVE_PROP_ERROR = -2;

    /** @param ?callable(?string,string|int|null...):void $stager
     * @return -2|-1|0|1|2 */
    function save_prop($stager = null) {
        // do not save if no changes
        if ($this->reviewId > 0 && !$this->prop_changed()) {
            return self::SAVE_PROP_EMPTY;
        }

        // update reviewTime, set required fields
        $this->_diff = $this->_diff ?? new ReviewDiffInfo($this);
        assert(!isset($this->_diff->_old_prop["reviewTime"]));
        $this->_seal_fstorage();
        if ($this->reviewId <= 0) {
            foreach (["paperId", "contactId", "reviewType", "requestedBy", "reviewRound"] as $k) {
                assert(!array_key_exists($k, $this->_diff->_old_prop));
                $this->_diff->_old_prop[$k] = $this->$k;
            }
            $this->set_prop("reviewTime", mt_rand(2000, 1000000));
        } else {
            $this->set_prop("reviewTime", $this->reviewTime + mt_rand(1, 10000));
        }

        // construct query
        $qf = $qv = [];
        foreach ($this->_diff->_old_prop as $prop => $v) {
            $qf[] = "{$prop}=?";
            $qv[] = $this->$prop;
        }
        //error_log("PaperReview {$this->paperId}/{$this->reviewId} " . json_encode($this->_diff->_old_prop));
        $xstager = $stager ?? [$this->conf, "qe"];
        if ($this->reviewId <= 0) {
            $result = $xstager("insert into PaperReview set " . join(", ", $qf), ...$qv);
        } else {
            array_push($qv, $this->paperId, $this->reviewId, $this->base_prop("reviewTime"));
            $result = $xstager("update PaperReview set " . join(", ", $qf)
                . " where paperId=? and reviewId=? and reviewTime=?", ...$qv);
        }
        if ($stager) {
            $this->reviewStatus = $this->compute_review_status();
            $r = self::SAVE_PROP_STAGED;
        } else if ($result->is_error()) {
            $r = self::SAVE_PROP_ERROR;
        } else if ($result->affected_rows === 0) {
            $r = self::SAVE_PROP_CONFLICT;
        } else {
            if ($result->insert_id) {
                $this->reviewId = $result->insert_id;
            }
            $this->reviewStatus = $this->compute_review_status();
            $r = self::SAVE_PROP_OK;
        }
        $result && $result->close();
        return $r;
    }

    function abort_prop() {
        if ($this->_diff) {
            foreach ($this->_diff->_old_prop as $prop => $v) {
                $this->$prop = $v;
            }
            $this->_sfields = $this->_tfields = null;
            $this->_assign_fields();
        }
    }


    /** @return array<string,ReviewField> */
    function viewable_fields(Contact $user, $include_nonexistent = false) {
        $bound = $user->view_score_bound($this->prow, $this);
        $fs = [];
        foreach ($this->conf->all_review_fields() as $fid => $f) {
            if ($f->view_score > $bound
                && ($f->test_exists($this)
                    || ($include_nonexistent
                        && $this->fields[$f->order] !== null)))
                $fs[$fid] = $f;
        }
        return $fs;
    }


    /** @return Contact */
    function reviewer() {
        if ($this->_reviewer === null) {
            $this->prow && $this->prow->ensure_reviewer_names();
            $this->_reviewer = $this->conf->user_by_id($this->contactId, USER_SLICE)
                ?? Contact::make_deleted($this->conf, $this->contactId);
        }
        return $this->_reviewer;
    }

    /** @param list<ReviewInfo> $rrows */
    static function check_ambiguous_names($rrows) {
        // XXXX fuck... this exposes information about whether 2 people with
        // the same name reviewed a paper, even if only one of their identities
        // should be visible
        foreach ($rrows as $i => $rrow) {
            $rrow->nameAmbiguous = false;
            $u1 = $rrow->reviewer();
            for ($j = 0; $j !== $i; ++$j) {
                $u2 = $rrows[$j]->_reviewer;
                if ($u1->firstName === $u2->firstName
                    && $u1->lastName === $u2->lastName
                    && ($u1->firstName !== "" || $u1->lastName !== "")) {
                    $rrow->nameAmbiguous = $rrows[$j]->nameAmbiguous = true;
                    break;
                }
            }
        }
    }

    /** @param ?TextPregexes $reg
     * @param int $order
     * @return bool */
    function field_match_pregexes($reg, $order) {
        $data = $this->fields[$order];
        if (!isset($this->_deaccent_fields[$order])) {
            if (!isset($this->_deaccent_fields)) {
                $this->_deaccent_fields = $this->conf->review_form()->order_array(null);
            }
            if (is_usascii($data)) {
                $this->_deaccent_fields[$order] = false;
            } else {
                $this->_deaccent_fields[$order] = UnicodeHelper::deaccent($data);
            }
        }
        return Text::match_pregexes($reg, $data, $this->_deaccent_fields[$order]);
    }


    function ensure_ratings() {
        if ($this->ratingSignature === null) {
            if ($this->conf->setting("rev_ratings") === REV_RATINGS_NONE) {
                $this->ratingSignature = "";
            } else if ($this->prow) {
                $this->prow->ensure_review_ratings($this);
            } else {
                $result = $this->conf->qe("select " . $this->conf->rating_signature_query() . " from PaperReview where paperId=? and reviewId=?", $this->paperId, $this->reviewId);
                $row = $result->fetch_row();
                Dbl::free($result);
                $this->ratingSignature = $row ? $row[0] : "";
            }
        }
    }

    /** @return bool */
    function has_ratings() {
        $this->ensure_ratings();
        return $this->ratingSignature !== "";
    }

    /** @return bool */
    function has_multiple_ratings() {
        $this->ensure_ratings();
        return strpos($this->ratingSignature, ",") !== false;
    }

    /** @return array<int,int> */
    function ratings() {
        $this->ensure_ratings();
        $ratings = [];
        if ($this->ratingSignature !== "") {
            foreach (explode(",", $this->ratingSignature) as $rx) {
                list($cid, $rating) = explode(" ", $rx);
                $ratings[(int) $cid] = (int) $rating;
            }
        }
        return $ratings;
    }

    /** @param int|Contact $user
     * @return ?int */
    function rating_by_rater($user) {
        $this->ensure_ratings();
        $cid = is_object($user) ? $user->contactId : $user;
        $str = ",$cid ";
        $pos = strpos("," . $this->ratingSignature, $str);
        if ($pos !== false) {
            return intval(substr($this->ratingSignature, $pos + strlen($str) - 1));
        } else {
            return null;
        }
    }

    /** @param int $rating
     * @return string */
    static function unparse_rating($rating) {
        if (isset(self::$rating_bits[$rating])) {
            return self::$rating_bits[$rating];
        } else if (!$rating) {
            return "none";
        } else {
            $a = [];
            foreach (self::$rating_bits as $k => $v) {
                if ($rating & $k)
                    $a[] = $v;
            }
            return join(" ", $a);
        }
    }

    /** @param string $s
     * @return ?int */
    static function parse_rating($s) {
        if (ctype_digit($s)) {
            $n = intval($s);
            return $n >= 0 && $n <= 127 ? $n : null;
        } else {
            $n = 0;
            foreach (preg_split('/\s+/', $s) as $word) {
                if (($k = array_search($word, ReviewInfo::$rating_bits)) !== false) {
                    $n |= $k;
                } else if ($word !== "" && $word !== "none") {
                    return null;
                }
            }
            return $n;
        }
    }

    /** @param string $s
     * @return ?int */
    static function parse_rating_search($s) {
        assert(count(self::$rating_match_bits) === count(self::$rating_match_strings));
        $n = 0;
        foreach (preg_split('/\s+/', SearchWord::unquote(strtolower($s))) as $w) {
            if ($w === "none") {
                return 0;
            } else if ($w === "any") {
                $n |= ReviewInfo::RATING_ANYMASK;
            } else if ($w === "good" || $w === "+") {
                $n |= ReviewInfo::RATING_GOODMASK;
            } else if ($w === "bad" || $w === "-" || $w === "\xE2\x88\x92" /* MINUS */) {
                $n |= ReviewInfo::RATING_BADMASK;
            } else if ($w !== "") {
                $re = '/\A' . str_replace('\*', '.*', preg_quote(str_replace("_", "-", $w))) . '\z/';
                $matches = preg_grep($re, self::$rating_match_strings);
                if (empty($matches) && strpos($w, "*") === false) {
                    return null;
                }
                foreach ($matches as $i => $m) {
                    $n |= self::$rating_match_bits[$i];
                }
            }
        }
        return $n;
    }


    private function _load_data() {
        if ($this->data === null && $this->reviewEditVersion === null) {
            $this->data = $this->conf->fetch_value("select `data` from PaperReview where paperId=? and reviewId=?", $this->paperId, $this->reviewId);
        }
        $this->_data = json_decode($this->data ?? "{}");
    }

    private function _save_data() {
        $this->data = json_encode_db($this->_data);
        $this->conf->qe("update PaperReview set `data`=? where paperId=? and reviewId=?", $this->data === "{}" ? null : $this->data, $this->paperId, $this->reviewId);
    }

    /** @return ?string */
    function data_string() {
        if ($this->_data === null) {
            $this->_load_data();
        }
        $s = json_encode_db($this->_data);
        return $s === "{}" ? null : $s;
    }


    /** @param ReviewHistoryInfo $rhrow
     * @return ?ReviewInfo */
    function apply_history($rhrow) {
        assert($this->paperId === $rhrow->paperId);
        assert($this->reviewId === $rhrow->reviewId);
        assert($this->reviewTime === $rhrow->reviewNextTime);

        $rrow = clone $this;
        $rrow->_data = null;
        $rrow->message_list = null;
        $rrow->_history = null;
        $rrow->reviewViewScore = self::VIEWSCORE_RECOMPUTE;

        $rrow->reviewTime = $rhrow->reviewTime;
        $rrow->contactId = $rhrow->contactId;
        $rrow->reviewRound = $rhrow->reviewRound;
        $rrow->reviewOrdinal = $rhrow->reviewOrdinal;
        $rrow->reviewType = $rhrow->reviewType;
        $rrow->reviewBlind = $rhrow->reviewBlind;
        $rrow->reviewModified = $rhrow->reviewModified;
        $rrow->reviewSubmitted = $rhrow->reviewSubmitted;
        $rrow->timeDisplayed = $rhrow->timeDisplayed;
        $rrow->timeApprovalRequested = $rhrow->timeApprovalRequested;
        $rrow->reviewAuthorSeen = $rhrow->reviewAuthorSeen;
        $rrow->reviewAuthorModified = $rhrow->reviewAuthorModified;
        $rrow->reviewNotified = $rhrow->reviewNotified;
        $rrow->reviewAuthorNotified = $rhrow->reviewAuthorNotified;
        $rrow->reviewEditVersion = $rhrow->reviewEditVersion;

        if ($rhrow->revdelta !== null) {
            $patch = json_decode($rhrow->revdelta, true);
            if (!is_array($patch)
                || !ReviewDiffInfo::apply_patch($rrow, $patch)) {
                return null;
            }
        }

        $rrow->reviewStatus = $rrow->compute_review_status();
        return $rrow;
    }

    /** @return list<ReviewHistoryInfo> */
    function load_history() {
        $this->_history = [];
        $result = $this->conf->qe("select * from PaperReviewHistory where paperId=? and reviewId=? order by reviewTime asc", $this->paperId, $this->reviewId);
        while (($rhrow = ReviewHistoryInfo::fetch($result))) {
            $this->_history[] = $rhrow;
        }
        Dbl::free($result);
        return $this->_history;
    }

    /** @param int $time
     * @return ?ReviewInfo */
    function version_at($time) {
        if ($time >= $this->reviewModified) {
            return $this;
        }
        $history = $this->_history ?? $this->load_history();
        $rrow = $this;
        for ($i = count($history) - 1;
             $i >= 0 && $time < $rrow->reviewModified;
             --$i) {
            $rhrow = $history[$i];
            if ($rhrow instanceof ReviewInfo) {
                $rrow = $rhrow;
            } else if ($rhrow->reviewNextTime !== $rrow->reviewTime) {
                error_log("#{$this->paperId}/{$this->reviewId}: break in review history chain @{$rhrow->reviewTime} {$rhrow->reviewNextTime} {$rrow->reviewTime}");
                return null;
            } else {
                $rrow = $this->_history[$i] = $rrow->apply_history($rhrow);
            }
        }
        return $rrow;
    }


    /** @param ReviewInfo $a
     * @param ReviewInfo $b
     * @return -1|0|1 */
    static function display_compare($a, $b) {
        // NB: all submitted reviews have timeDisplayed
        if (($a->timeDisplayed > 0) !== ($b->timeDisplayed > 0)) {
            return $a->timeDisplayed > 0 ? -1 : 1;
        }
        if ($a->timeDisplayed !== $b->timeDisplayed) {
            return $a->timeDisplayed <=> $b->timeDisplayed;
        }
        if ($a->reviewOrdinal !== $b->reviewOrdinal) {
            return $a->reviewOrdinal <=> $b->reviewOrdinal;
        }
        return $a->reviewId <=> $b->reviewId;
    }


    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = ["confid" => $this->conf->dbname];
        foreach (get_object_vars($this) as $k => $v) {
            if ($k[0] !== "_"
                && $v !== null
                && !in_array($k, ["conf", "prow", "sfields", "tfields", "fields"])
                && strlen($k) > 3) {
                $j[$k] = $v;
            }
        }
        if ($this->fields !== null) {
            foreach ($this->conf->all_review_fields() as $f) {
                if ($this->fields[$f->order] !== null)
                    $j[$f->uid()] = $this->fields[$f->order];
            }
        }
        return $j;
    }
}
