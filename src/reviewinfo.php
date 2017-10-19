<?php
// reviewinfo.php -- HotCRP class representing reviews
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ReviewInfo {
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

    private function merge(Conf $conf) {
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

    function assign_name($c) {
        $this->firstName = $c->firstName;
        $this->lastName = $c->lastName;
        $this->email = $c->email;
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
            if (strlen($k) === 3 && $k[0] === "s" && (int) $v !== 0
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
            if (strlen($k) === 3 && $k[0] === "t" && $v !== null && $v !== "")
                $data[$k] = $v;
        if ($data === null)
            return null;
        $json = json_encode_db($data);
        if ($json === null)
            error_log(($Conf ? "{$Conf->dbname}: " : "") . "review #{$this->paperId}/{$this->reviewId}: text fields cannot be converted to JSON");
        return $json;
    }

    static function compare($a, $b) {
        if ($a->paperId != $b->paperId)
            return (int) $a->paperId < (int) $b->paperId ? -1 : 1;
        if ($a->reviewOrdinal && $b->reviewOrdinal
            && $a->reviewOrdinal != $b->reviewOrdinal)
            return (int) $a->reviewOrdinal < (int) $b->reviewOrdinal ? -1 : 1;
        $asub = (int) $a->reviewSubmitted;
        $bsub = (int) $b->reviewSubmitted;
        if (($asub > 0) != ($bsub > 0))
            return $asub > 0 ? -1 : 1;
        if ($asub != $bsub)
            return $asub < $bsub ? -1 : 1;
        if (isset($a->sorter) && isset($b->sorter)
            && ($x = strcmp($a->sorter, $b->sorter)) != 0)
            return $x;
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
        if ($pos !== false
            && ($rating = intval(substr($this->allRatings, $pos + strlen($str) - 1))))
            return $rating;
        return null;
    }
}
