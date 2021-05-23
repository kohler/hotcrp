<?php
// reviewinfo.php -- HotCRP class representing reviews
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

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
    public $reviewToken;
    /** @var int */
    public $reviewType;
    /** @var int */
    public $reviewRound;
    /** @var int */
    public $requestedBy;
    /** @var int */
    public $reviewBlind;
    /** @var int */
    public $reviewModified;
    /** @var ?int */
    public $reviewSubmitted;
    /** @var ?int */
    public $reviewAuthorSeen;
    /** @var int */
    public $reviewOrdinal;
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
    /** @var ?int */
    public $reviewFormat;
    /** @var ?string */
    public $tfields;
    /** @var ?string */
    public $sfields;
    /** @var ?string */
    private $data;
    private $_data;

    // scores
    /** @var ?int */
    public $overAllMerit;
    /** @var ?int */
    public $reviewerQualification;
    /** @var ?int */
    public $novelty;
    /** @var ?int */
    public $technicalMerit;
    /** @var ?int */
    public $interestToCommunity;
    /** @var ?int */
    public $longevity;
    /** @var ?int */
    public $grammar;
    /** @var ?int */
    public $likelyPresentation;
    /** @var ?int */
    public $suitableForShort;
    /** @var ?int */
    public $potential;
    /** @var ?int */
    public $fixability;
    /** @var ?string */
    public $t01;
    /** @var ?string */
    public $t02;
    /** @var ?string */
    public $t03;
    /** @var ?string */
    public $t04;
    /** @var ?string */
    public $t05;
    /** @var ?string */
    public $t06;
    /** @var ?string */
    public $t07;
    /** @var ?string */
    public $t08;
    /** @var ?string */
    public $t09;
    /** @var ?string */
    public $t10;

    // sometimes joined
    /** @var ?string */
    public $firstName;
    /** @var ?string */
    public $lastName;
    /** @var ?string */
    public $affiliation;
    /** @var ?string */
    public $email;
    /** @var ?bool */
    public $nameAmbiguous;
    /** @var ?int */
    public $roles;
    /** @var ?string */
    public $contactTags;
    /** @var ?int */
    public $lastLogin;
    /** @var ?string */
    public $ratingSignature;

    // other
    /** @var ?list<MessageItem> */
    public $message_list;

    const VIEWSCORE_RECOMPUTE = -100;

    const RS_EMPTY = 0;
    const RS_ACCEPTED = 1;
    const RS_DRAFTED = 2;
    const RS_DELIVERED = 3;
    const RS_ADOPTED = 4;
    const RS_COMPLETED = 5;

    /** @var array<non-empty-string,non-empty-string> */
    static public $text_field_map = [
        "paperSummary" => "t01", "commentsToAuthor" => "t02",
        "commentsToPC" => "t03", "commentsToAddress" => "t04",
        "weaknessOfPaper" => "t05", "strengthOfPaper" => "t06",
        "textField7" => "t07", "textField8" => "t08"
    ];
    /** @var list<?non-empty-string> */
    static private $new_text_fields = [
        null, "paperSummary", "commentsToAuthor", "commentsToPC",
        "commentsToAddress", "weaknessOfPaper", "strengthOfPaper",
        "textField7", "textField8"
    ];
    /** @var array<non-empty-string,non-empty-string> */
    static private $score_field_map = [
        "overAllMerit" => "s01", "reviewerQualification" => "s02",
        "novelty" => "s03", "technicalMerit" => "s04",
        "interestToCommunity" => "s05", "longevity" => "s06", "grammar" => "s07",
        "likelyPresentation" => "s08", "suitableForShort" => "s09",
        "potential" => "s10", "fixability" => "s11"
    ];
    // see also Signature properties in PaperInfo
    /** @var list<?non-empty-string> */
    static private $new_score_fields = [
        null, "overAllMerit", "reviewerQualification", "novelty",
        "technicalMerit", "interestToCommunity", "longevity", "grammar",
        "likelyPresentation", "suitableForShort", "potential", "fixability"
    ];
    /** @var array<string,ReviewFieldInfo> */
    static private $field_info_map = [];
    const MIN_SFIELD = 12;

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
    static private $type_revmap = [
        REVIEW_EXTERNAL => "review", REVIEW_PC => "pcreview",
        REVIEW_SECONDARY => "secondary", REVIEW_PRIMARY => "primary",
        REVIEW_META => "metareview"
    ];

    /** @param string $str
     * @return null|int|false */
    static function parse_type($str) {
        $str = strtolower($str);
        if ($str === "review" || $str === "" || $str === "all" || $str === "any") {
            return null;
        }
        if (str_ends_with($str, "review")) {
            $str = substr($str, 0, $str[strlen($str) - 7] === "-" ? -7 : -6);
        }
        return self::$type_map[$str] ?? false;
    }

    /** @param int $type
     * @return string */
    static function unparse_assigner_action($type) {
        return self::$type_revmap[$type] ?? "clearreview";
    }


    /** @return ReviewInfo */
    static function make_blank(PaperInfo $prow = null, Contact $user) {
        $rrow = new ReviewInfo;
        $rrow->conf = $user->conf;
        $rrow->prow = $prow;
        $rrow->paperId = $prow ? $prow->paperId : 0;
        $rrow->reviewId = 0;
        $rrow->contactId = $user->contactId;
        $rrow->reviewToken = 0;
        $rrow->reviewType = $user->isPC ? REVIEW_PC : REVIEW_EXTERNAL;
        $rrow->reviewRound = $user->conf->assignment_round(!$user->isPC);
        $rrow->requestedBy = 0;
        $rrow->reviewBlind = $user->conf->review_blindness() !== Conf::BLIND_NEVER ? 1 : 0;
        $rrow->reviewModified = 0;
        $rrow->reviewOrdinal = 0;
        $rrow->timeDisplayed = 0;
        $rrow->timeApprovalRequested = 0;
        $rrow->reviewNeedsSubmit = 0;
        $rrow->reviewViewScore = -3;
        $rrow->reviewStatus = self::RS_EMPTY;
        return $rrow;
    }

    private function merge(PaperInfo $prow = null, Conf $conf = null) {
        $this->conf = $conf ?? $prow->conf;
        $this->prow = $prow;
        $this->paperId = (int) $this->paperId;
        assert($prow === null || $this->paperId === $prow->paperId);
        $this->reviewId = (int) $this->reviewId;
        $this->contactId = (int) $this->contactId;
        $this->reviewToken = (int) $this->reviewToken;
        $this->reviewType = (int) $this->reviewType;
        $this->reviewRound = (int) $this->reviewRound;
        $this->requestedBy = (int) $this->requestedBy;
        $this->reviewBlind = (int) $this->reviewBlind;
        $this->reviewModified = (int) $this->reviewModified;
        if ($this->reviewSubmitted !== null) {
            $this->reviewSubmitted = (int) $this->reviewSubmitted;
        }
        if ($this->reviewAuthorSeen !== null) {
            $this->reviewAuthorSeen = (int) $this->reviewAuthorSeen;
        }
        $this->reviewOrdinal = (int) $this->reviewOrdinal;
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
        if ($this->reviewFormat !== null) {
            $this->reviewFormat = (int) $this->reviewFormat;
        }

        if ($this->overAllMerit !== null) {
            $this->overAllMerit = (int) $this->overAllMerit;
        }
        if ($this->reviewerQualification !== null) {
            $this->reviewerQualification = (int) $this->reviewerQualification;
        }
        if ($this->novelty !== null) {
            $this->novelty = (int) $this->novelty;
        }
        if ($this->technicalMerit !== null) {
            $this->technicalMerit = (int) $this->technicalMerit;
        }
        if ($this->interestToCommunity !== null) {
            $this->interestToCommunity = (int) $this->interestToCommunity;
        }
        if ($this->longevity !== null) {
            $this->longevity = (int) $this->longevity;
        }
        if ($this->grammar !== null) {
            $this->grammar = (int) $this->grammar;
        }
        if ($this->likelyPresentation !== null) {
            $this->likelyPresentation = (int) $this->likelyPresentation;
        }
        if ($this->suitableForShort !== null) {
            $this->suitableForShort = (int) $this->suitableForShort;
        }
        if ($this->potential !== null) {
            $this->potential = (int) $this->potential;
        }
        if ($this->fixability !== null) {
            $this->fixability = (int) $this->fixability;
        }
        if (isset($this->tfields) && ($x = json_decode($this->tfields, true))) {
            foreach ($x as $k => $v) {
                $this->$k = $v;
            }
        }
        if (isset($this->sfields) && ($x = json_decode($this->sfields, true))) {
            foreach ($x as $k => $v) {
                $this->$k = $v;
            }
        }

        if ($this->roles !== null) {
            $this->roles = (int) $this->roles;
        }
    }

    function upgrade_sversion() {
        if ($this->conf->sversion < 175) {
            foreach (self::$text_field_map as $kmain => $kjson) {
                if (property_exists($this, $kmain) && !isset($this->$kjson)) {
                    $this->$kjson = $this->$kmain;
                    unset($this->$kmain);
                }
            }
        }
    }

    /** @param PaperInfo|PaperInfoSet|null $prowx
     * @return ?ReviewInfo */
    static function fetch($result, $prowx = null, Conf $conf = null) {
        $rrow = $result ? $result->fetch_object("ReviewInfo") : null;
        '@phan-var ?ReviewInfo $rrow';
        if ($rrow) {
            $prow = $prowx instanceof PaperInfoSet ? $prowx->get((int) $rrow->paperId) : $prowx;
            $rrow->merge($prow, $conf);
        }
        return $rrow;
    }

    static function review_signature_sql(Conf $conf, $scores = null) {
        $t = "r.reviewId, ' ', r.contactId, ' ', r.reviewToken, ' ', r.reviewType, ' ', r.reviewRound, ' ', r.requestedBy, ' ', r.reviewBlind, ' ', r.reviewModified, ' ', coalesce(r.reviewSubmitted,0), ' ', coalesce(r.reviewAuthorSeen,0), ' ', r.reviewOrdinal, ' ', r.timeDisplayed, ' ', r.timeApprovalRequested, ' ', r.reviewNeedsSubmit, ' ', r.reviewViewScore";
        foreach ($scores ?? [] as $fid) {
            if (($f = $conf->review_field($fid)) && $f->main_storage)
                $t .= ", ' " . $f->short_id . "=', " . $f->id;
        }
        return "group_concat($t order by r.reviewId)";
    }

    /** @return ReviewInfo */
    static function make_signature(PaperInfo $prow, $signature) {
        $rrow = new ReviewInfo;
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
        for ($i = 15; isset($vals[$i]); ++$i) {
            $eq = strpos($vals[$i], "=");
            $f = self::field_info(substr($vals[$i], 0, $eq));
            $fid = $f->id;
            $rrow->$fid = (int) substr($vals[$i], $eq + 1);
            $prow->_mark_has_score($fid);
        }
        $rrow->merge($prow, $prow->conf);
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
        return $this->conf->review_deadline_name($this->reviewRound, $this->reviewType >= REVIEW_PC, $hard);
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
    function type_icon() {
        if ($this->subject_to_approval()) {
            $title = "Subreview";
        } else {
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
            return "started";
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
            assert($this->prow);
            $this->reviewViewScore = $this->prow->conf->review_form()->nonempty_view_score($this);
        }
        return $this->reviewViewScore;
    }


    /** @return bool */
    function has_nonempty_field(ReviewField $f) {
        return $f->test_exists($this)
            && ($x = $this->{$f->id} ?? null) !== null
            && $x !== ""
            && (!$f->has_options || (int) $x !== 0);
    }

    /** @return array<string,ReviewField> */
    function viewable_fields(Contact $user) {
        $bound = $user->view_score_bound($this->prow, $this);
        $fs = [];
        foreach ($this->conf->all_review_fields() as $fid => $f) {
            if ($f->view_score > $bound
                && $f->test_exists($this))
                $fs[$fid] = $f;
        }
        return $fs;
    }


    /** @param Contact $c
     * @param list<Contact> &$assigned */
    function assign_name($c, &$assigned) {
        $this->firstName = $c->firstName;
        $this->lastName = $c->lastName;
        $this->affiliation = $c->affiliation;
        $this->email = $c->email;
        $this->roles = $c->roles;
        $this->contactTags = $c->contactTags;
        $this->nameAmbiguous = false;
        foreach ($assigned as $pc) {
            if ($pc->firstName === $c->firstName && $pc->lastName === $c->lastName) {
                $pc->nameAmbiguous = $c->nameAmbiguous = true;
            }
        }
        $assigned[] = $c;
    }

    /** @param string $id
     * @return ?ReviewFieldInfo */
    static function field_info($id) {
        $m = self::$field_info_map[$id] ?? null;
        if (!$m && !array_key_exists($id, self::$field_info_map)) {
            if (strlen($id) === 3 && ctype_digit(substr($id, 1))) {
                $n = intval(substr($id, 1), 10);
                $json_storage = $id;
                if ($id[0] === "s" && isset(self::$new_score_fields[$n])) {
                    $fid = self::$new_score_fields[$n];
                    $m = new ReviewFieldInfo($fid, $id, true, $fid, null);
                } else if ($id[0] === "s" || $id[0] === "t") {
                    $m = new ReviewFieldInfo($id, $id, $id[0] === "s", null, $id);
                }
            } else if (($short_id = self::$text_field_map[$id] ?? null)) {
                $m = new ReviewFieldInfo($short_id, $short_id, false, null, $short_id);
            } else if (($short_id = self::$score_field_map[$id] ?? null)) {
                $m = new ReviewFieldInfo($id, $short_id, true, $id, null);
            }
            self::$field_info_map[$id] = $m;
        }
        return $m;
    }

    /** @return bool */
    function field_match_pregexes($reg, $field) {
        $data = $this->$field;
        $field_deaccent = $field . "_deaccent";
        if (!isset($this->$field_deaccent)) {
            if (is_usascii($data)) {
                $this->$field_deaccent = false;
            } else {
                $this->$field_deaccent = UnicodeHelper::deaccent($data);
            }
        }
        return Text::match_pregexes($reg, $data, $this->$field_deaccent);
    }

    function unparse_sfields() {
        $data = [];
        foreach (get_object_vars($this) as $k => $v) {
            if (strlen($k) === 3
                && $k[0] === "s"
                && (int) $v !== 0
                && ($n = cvtint(substr($k, 1))) >= self::MIN_SFIELD)
                $data[$k] = (int) $v;
        }
        return empty($data) ? null : json_encode_db($data);
    }

    function unparse_tfields() {
        $data = [];
        foreach (get_object_vars($this) as $k => $v) {
            if (strlen($k) === 3
                && $k[0] === "t"
                && $v !== null
                && $v !== "")
                $data[$k] = $v;
        }
        if (empty($data)) {
            return null;
        } else {
            $json = json_encode_db($data);
            if ($json === null) {
                error_log("{$this->conf->dbname}: review #{$this->paperId}/{$this->reviewId}: text fields cannot be converted to JSON");
            }
            return $json;
        }
    }


    function ensure_ratings() {
        if ($this->ratingSignature === null) {
            if ($this->conf->setting("rev_ratings") === REV_RATINGS_NONE) {
                $this->ratingSignature = "";
            } else if ($this->prow) {
                $this->prow->ensure_review_ratings($this);
            } else {
                $result = $this->conf->qe("select " . $this->conf->query_ratings() . " from PaperReview where paperId=? and reviewId=?", $this->paperId, $this->reviewId);
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

    /** @param int|Contact $user
     * @return ?int
     * @deprecated */
    function rating_of_user($user) {
        return $this->rating_by_rater($user);
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

    function acceptor() {
        if ($this->_data === null) {
            $this->_load_data();
        }
        if (!isset($this->_data->acceptor)) {
            $text = base48_encode(random_bytes(10));
            $this->_data->acceptor = (object) ["text" => $text, "at" => Conf::$now];
            $this->_save_data();
        }
        return $this->_data->acceptor;
    }

    function acceptor_is($text) {
        if ($this->_data === null) {
            $this->_load_data();
        }
        return isset($this->_data->acceptor)
            && $this->_data->acceptor->text === $text;
    }

    function delete_acceptor() {
        if ($this->_data === null) {
            $this->_load_data();
        }
        if (isset($this->_data->acceptor) && $this->_data->acceptor->at) {
            $this->_data->acceptor->at = 0;
            $this->_save_data();
        }
    }

    function jsonSerialize() {
        $j = ["confid" => $this->conf->dbname];
        foreach (get_object_vars($this) as $k => $v) {
            if ($k !== "conf" && $k !== "prow" && $k !== "_data") {
                $j[$k] = $v;
            }
        }
        return $j;
    }
}
