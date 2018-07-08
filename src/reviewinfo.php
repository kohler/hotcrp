<?php
// reviewinfo.php -- HotCRP class representing reviews
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewInfo {
    public $conf;
    public $paperId;
    public $reviewId;
    public $contactId;
    public $reviewToken;
    public $reviewType;
    public $reviewRound;
    public $requestedBy;
    //public $timeRequested;
    //public $timeRequestNotified;
    public $reviewBlind;
    public $reviewModified;
    //public $reviewAuthorModified;
    public $reviewSubmitted;
    //public $reviewNotified;
    //public $reviewAuthorNotified;
    public $reviewAuthorSeen;
    public $reviewOrdinal;
    //public $timeDisplayed;
    public $timeApprovalRequested;
    //public $reviewEditVersion;
    public $reviewNeedsSubmit;
    // ... scores ...
    //public $reviewWordCount;
    //public $reviewFormat;

    static public $text_field_map = [
        "paperSummary" => "t01", "commentsToAuthor" => "t02",
        "commentsToPC" => "t03", "commentsToAddress" => "t04",
        "weaknessOfPaper" => "t05", "strengthOfPaper" => "t06",
        "textField7" => "t07", "textField8" => "t08"
    ];
    static private $new_text_fields = [
        null, "paperSummary", "commentsToAuthor", "commentsToPC",
        "commentsToAddress", "weaknessOfPaper", "strengthOfPaper",
        "textField7", "textField8"
    ];
    static private $score_field_map = [
        "overAllMerit" => "s01", "reviewerQualification" => "s02",
        "novelty" => "s03", "technicalMerit" => "s04",
        "interestToCommunity" => "s05", "longevity" => "s06", "grammar" => "s07",
        "likelyPresentation" => "s08", "suitableForShort" => "s09",
        "potential" => "s10", "fixability" => "s11"
    ];
    static private $new_score_fields = [
        null, "overAllMerit", "reviewerQualification", "novelty",
        "technicalMerit", "interestToCommunity", "longevity", "grammar",
        "likelyPresentation", "suitableForShort", "potential", "fixability"
    ];
    const MIN_SFIELD = 12;

    const RATING_GOODMASK = 1;
    const RATING_BADMASK = 126;
    // See also script.js:unparse_ratings
    static public $rating_options = [
        1 => "good review", 2 => "needs work",
        4 => "too short", 8 => "too vague", 16 => "too narrow",
        32 => "not constructive", 64 => "not correct"
    ];
    static public $rating_bits = [
        1 => "good", 2 => "bad", 4 => "short", 8 => "vague",
        16 => "narrow", 32 => "not-constructive", 64 => "wrong"
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

    static function parse_type($str) {
        $str = strtolower($str);
        if ($str === "review" || $str === "" || $str === "all" || $str === "any")
            return null;
        if (str_ends_with($str, "review"))
            $str = substr($str, 0, -6);
        return get(self::$type_map, $str, false);
    }
    static function unparse_assigner_action($type) {
        return get(self::$type_revmap, $type, "clearreview");
    }

    private function merge(Conf $conf) {
        $this->conf = $conf;
        foreach (["paperId", "reviewId", "contactId", "reviewType",
                  "reviewRound", "requestedBy", "reviewBlind",
                  "reviewOrdinal", "reviewNeedsSubmit"] as $k) {
            assert($this->$k !== null, "null $k");
            $this->$k = (int) $this->$k;
        }
        foreach (["reviewModified", "reviewSubmitted", "reviewAuthorSeen"] as $k)
            if (isset($this->$k))
                $this->$k = (int) $this->$k;
        if (isset($this->tfields) && ($x = json_decode($this->tfields, true))) {
            foreach ($x as $k => $v)
                $this->$k = $v;
        }
        if (isset($this->sfields) && ($x = json_decode($this->sfields, true))) {
            foreach ($x as $k => $v)
                $this->$k = $v;
        }
        if ($conf->sversion < 176) {
            foreach (self::$text_field_map as $kmain => $kjson)
                if (isset($this->$kmain) && !isset($this->$kjson))
                    $this->$kjson = $this->$kmain;
        }
    }
    static function fetch($result, Conf $conf) {
        $rrow = $result ? $result->fetch_object("ReviewInfo") : null;
        if ($rrow)
            $rrow->merge($conf);
        return $rrow;
    }
    static function review_signature_sql() {
        return "group_concat(r.reviewId, ' ', r.contactId, ' ', r.reviewToken, ' ', r.reviewType, ' ', "
            . "r.reviewRound, ' ', r.requestedBy, ' ', r.reviewBlind, ' ', r.reviewModified, ' ', "
            . "coalesce(r.reviewSubmitted,0), ' ', coalesce(r.reviewAuthorSeen,0), ' ', "
            . "r.reviewOrdinal, ' ', r.timeApprovalRequested, ' ', r.reviewNeedsSubmit order by r.reviewId)";
    }
    static function make_signature(PaperInfo $prow, $signature) {
        $rrow = new ReviewInfo;
        $rrow->paperId = $prow->paperId;
        list($rrow->reviewId, $rrow->contactId, $rrow->reviewToken, $rrow->reviewType,
             $rrow->reviewRound, $rrow->requestedBy, $rrow->reviewBlind, $rrow->reviewModified,
             $rrow->reviewSubmitted, $rrow->reviewAuthorSeen,
             $rrow->reviewOrdinal, $rrow->timeApprovalRequested, $rrow->reviewNeedsSubmit)
            = explode(" ", $signature);
        $rrow->merge($prow->conf);
        return $rrow;
    }

    function round_name() {
        return $this->reviewRound ? $this->conf->round_name($this->reviewRound) : "";
    }

    function assign_name($c) {
        $this->firstName = $c->firstName;
        $this->lastName = $c->lastName;
        $this->email = $c->email;
        $this->sorter = $c->sorter;
    }

    static function field_info($id, Conf $conf) {
        $sversion = $conf->sversion;
        if (strlen($id) === 3 && ctype_digit(substr($id, 1))) {
            $n = intval(substr($id, 1), 10);
            $json_storage = $sversion >= 174 ? $id : null;
            if ($id[0] === "t") {
                if (isset(self::$new_text_fields[$n]) && $sversion < 175)
                    return new ReviewFieldInfo($id, $id, false, self::$new_text_fields[$n], $json_storage);
                else if ($json_storage)
                    return new ReviewFieldInfo($id, $id, false, null, $json_storage);
                else
                    return false;
            } else if ($id[0] === "s") {
                if (isset(self::$new_score_fields[$n])) {
                    $fid = self::$new_score_fields[$n];
                    return new ReviewFieldInfo($fid, $id, true, $fid, null);
                } else if ($json_storage)
                    return new ReviewFieldInfo($id, $id, true, null, $json_storage);
                else
                    return false;
            } else
                return false;
        } else if (isset(self::$text_field_map[$id])) {
            $short_id = self::$text_field_map[$id];
            $main_storage = $sversion < 175 ? $id : null;
            $json_storage = $sversion >= 174 ? $short_id : null;
            return new ReviewFieldInfo($short_id, $short_id, false, $main_storage, $json_storage);
        } else if (isset(self::$score_field_map[$id])) {
            $short_id = self::$score_field_map[$id];
            return new ReviewFieldInfo($id, $short_id, true, $id, null);
        } else
            return false;
    }

    function field_match_pregexes($reg, $field) {
        $data = $this->$field;
        $field_deaccent = $field . "_deaccent";
        if (!isset($this->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $data))
                $this->$field_deaccent = UnicodeHelper::deaccent($data);
            else
                $this->$field_deaccent = false;
        }
        return Text::match_pregexes($reg, $data, $this->$field_deaccent);
    }

    function unparse_sfields() {
        $data = null;
        foreach (get_object_vars($this) as $k => $v)
            if (strlen($k) === 3
                && $k[0] === "s"
                && (int) $v !== 0
                && ($n = cvtint(substr($k, 1))) >= self::MIN_SFIELD)
                $data[$k] = (int) $v;
        if ($data === null)
            return null;
        return json_encode_db($data);
    }
    function unparse_tfields() {
        global $Conf;
        $data = null;
        foreach (get_object_vars($this) as $k => $v)
            if (strlen($k) === 3
                && $k[0] === "t"
                && $v !== null
                && $v !== "")
                $data[$k] = $v;
        if ($data === null)
            return null;
        $json = json_encode_db($data);
        if ($json === null)
            error_log(($Conf ? "{$Conf->dbname}: " : "") . "review #{$this->paperId}/{$this->reviewId}: text fields cannot be converted to JSON");
        return $json;
    }

    static function compare($a, $b) {
        // 1. different papers
        if ($a->paperId != $b->paperId)
            return (int) $a->paperId < (int) $b->paperId ? -1 : 1;
        // 2. different ordinals (both have ordinals)
        if ($a->reviewOrdinal
            && $b->reviewOrdinal
            && $a->reviewOrdinal != $b->reviewOrdinal)
            return (int) $a->reviewOrdinal < (int) $b->reviewOrdinal ? -1 : 1;
        // 3. some submitted reviews have no ordinal (ordinal is reserved for
        //    user-visible reviews)
        $asub = (int) $a->reviewSubmitted;
        $bsub = (int) $b->reviewSubmitted;
        if (($asub > 0) != ($bsub > 0))
            return $asub > 0 ? -1 : 1;
        if ($asub !== $bsub)
            return $asub < $bsub ? -1 : 1;
        // 4. submission class
        $asclass = self::submission_class($a);
        $bsclass = self::submission_class($b);
        if ($asclass !== $bsclass)
            return $asclass < $bsclass ? 1 : -1;
        // 5. reviewer
        if (isset($a->sorter)
            && isset($b->sorter)
            && ($x = strcmp($a->sorter, $b->sorter)) != 0)
            return $x;
        // 6. review id
        if ($a->reviewId != $b->reviewId)
            return (int) $a->reviewId < (int) $b->reviewId ? -1 : 1;
        return 0;
    }

    static function compare_id($a, $b) {
        if ($a->paperId != $b->paperId)
            return (int) $a->paperId < (int) $b->paperId ? -1 : 1;
        if ($a->reviewId != $b->reviewId)
            return (int) $a->reviewId < (int) $b->reviewId ? -1 : 1;
        return 0;
    }

    static function submission_class($rr) {
        if ($rr->reviewSubmitted > 0)
            return 5;
        else if ($rr->reviewType == REVIEW_SECONDARY && $rr->reviewNeedsSubmit <= 0)
            return 4;
        else if ($rr->reviewModified > 1 && $rr->timeApprovalRequested > 0)
            return 3;
        else if ($rr->reviewModified > 1)
            return 2;
        else if ($rr->reviewModified > 0)
            return 1;
        else
            return 0;
    }

    function ratings() {
        $ratings = [];
        if ((string) $this->allRatings !== "") {
            foreach (explode(",", $this->allRatings) as $rx) {
                list($cid, $rating) = explode(" ", $rx);
                $ratings[(int) $cid] = intval($rating);
            }
        }
        return $ratings;
    }

    function rating_of_user($user) {
        $cid = is_object($user) ? $user->contactId : $user;
        $str = ",$cid ";
        $pos = strpos("," . $this->allRatings, $str);
        if ($pos !== false)
            return intval(substr($this->allRatings, $pos + strlen($str) - 1));
        return null;
    }

    static function unparse_rating($rating) {
        if (isset(self::$rating_bits[$rating]))
            return self::$rating_bits[$rating];
        else if (!$rating)
            return "none";
        else {
            $a = [];
            foreach (self::$rating_bits as $k => $v)
                if ($rating & $k)
                    $a[] = $v;
            return join(" ", $a);
        }
    }

    static function parse_rating($s) {
        if (ctype_digit($s)) {
            $n = intval($s);
            if ($n >= 0 && $n < 127)
                return $n ? : null;
        }
        $n = 0;
        foreach (preg_split('/\s+/', $s) as $word) {
            if (($k = array_search($word, ReviewInfo::$rating_bits)) !== false)
                $n |= $k;
            else if ($word !== "" && $word !== "none")
                return false;
        }
        return $n;
    }
}
