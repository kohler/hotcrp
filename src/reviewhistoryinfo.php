<?php
// reviewhistoryinfo.php -- HotCRP class representing reviews
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewHistoryInfo implements JsonSerializable {
    // fields always present
    /** @var int */
    public $paperId;
    /** @var int */
    public $reviewId;
    /** @var int */
    public $reviewTime;

    /** @var int */
    public $reviewNextTime;
    /** @var int */
    public $contactId;
    /** @var int */
    public $reviewRound;
    /** @var int */
    public $reviewOrdinal;
    /** @var int */
    public $reviewType;
    /** @var int */
    public $reviewBlind;
    /** @var int */
    public $reviewModified;
    /** @var ?int */
    public $reviewSubmitted;
    /** @var int */
    public $timeDisplayed;
    /** @var int */
    public $timeApprovalRequested;
    /** @var ?int */
    public $reviewAuthorSeen;
    /** @var ?int */
    public $reviewAuthorModified;
    /** @var ?int */
    public $reviewNotified;
    /** @var ?int */
    public $reviewAuthorNotified;
    /** @var ?int */
    public $reviewEditVersion;  // NB also used to check if `data` was loaded
    /** @var ?string */
    public $revdelta;

    /** @param Dbl_Result $result
     * @return ?ReviewHistoryInfo */
    static function fetch($result) {
        $rhrow = $result ? $result->fetch_object("ReviewHistoryInfo") : null;
        '@phan-var ?ReviewHistoryInfo $rhrow';
        if ($rhrow) {
            $rhrow->incorporate();
        }
        return $rhrow;
    }

    private function incorporate() {
        $this->paperId = (int) $this->paperId;
        $this->reviewId = (int) $this->reviewId;
        $this->reviewTime = (int) $this->reviewTime;
        $this->reviewNextTime = (int) $this->reviewNextTime;
        $this->contactId = (int) $this->contactId;
        $this->reviewRound = (int) $this->reviewRound;
        $this->reviewOrdinal = (int) $this->reviewOrdinal;
        $this->reviewType = (int) $this->reviewType;
        $this->reviewBlind = (int) $this->reviewBlind;
        $this->reviewModified = (int) $this->reviewModified;
        $this->reviewSubmitted = (int) $this->reviewSubmitted;
        $this->timeDisplayed = (int) $this->timeDisplayed;
        $this->timeApprovalRequested = (int) $this->timeApprovalRequested;
        $this->reviewAuthorSeen = (int) $this->reviewAuthorSeen;
        $this->reviewAuthorModified = (int) $this->reviewAuthorModified;
        $this->reviewNotified = (int) $this->reviewNotified;
        $this->reviewAuthorNotified = (int) $this->reviewAuthorNotified;
        $this->reviewEditVersion = (int) $this->reviewEditVersion;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return (array) $this;
    }
}
