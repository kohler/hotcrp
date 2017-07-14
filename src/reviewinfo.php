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

    private function merge() {
        foreach (["paperId", "reviewId", "contactId", "reviewType",
                  "reviewRound", "requestedBy", "reviewBlind",
                  "reviewOrdinal", "reviewNeedsSubmit"] as $k) {
            assert($this->$k !== null, "null $k");
            $this->$k = (int) $this->$k;
        }
        foreach (["reviewModified", "reviewSubmitted", "reviewAuthorSeen"] as $k)
            if (isset($this->$k))
                $this->$k = (int) $this->$k;
    }
    static function fetch($result, Conf $conf = null) {
        $rrow = $result ? $result->fetch_object("ReviewInfo") : null;
        if ($rrow && !is_int($rrow->paperId))
            $rrow->merge();
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
        $rrow->merge();
        return $rrow;
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
}
