<?php
// reviewrefusalinfo.php -- HotCRP PaperReviewRefused objects
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ReviewRefusalInfo {
    /** @var int */
    public $paperId;
    /** @var string */
    public $email;
    /** @var ?string */
    public $firstName;
    /** @var ?string */
    public $lastName;
    /** @var ?string */
    public $affiliation;
    /** @var int */
    public $contactId;
    /** @var int */
    public $requestedBy;
    /** @var ?int */
    public $timeRequested;
    /** @var ?int */
    public $refusedBy;
    /** @var ?int */
    public $timeRefused;
    /** @var int */
    public $refusedReviewType;
    /** @var ?int */
    public $reviewRound;
    /** @var ?string */
    public $data;
    /** @var ?string */
    public $reason;

    /** @var null */
    public $reviewToken;
    /** @var int */
    public $reviewType = REVIEW_REFUSAL;

    private function merge() {
        $this->paperId = (int) $this->paperId;
        $this->contactId = (int) $this->contactId;
        $this->requestedBy = (int) $this->requestedBy;
        if ($this->timeRequested !== null) {
            $this->timeRequested = (int) $this->timeRequested;
        }
        if ($this->refusedBy !== null) {
            $this->refusedBy = (int) $this->refusedBy;
        }
        if ($this->timeRefused !== null) {
            $this->timeRefused = (int) $this->timeRefused;
        }
        $this->refusedReviewType = (int) $this->refusedReviewType;
        if ($this->reviewRound !== null) {
            $this->reviewRound = (int) $this->reviewRound;
        }
        $this->reviewType = REVIEW_REFUSAL;
    }

    /** @return ?ReviewRefusalInfo */
    static function fetch($result) {
        if (($row = $result->fetch_object("ReviewRefusalInfo"))) {
            $row->merge();
        }
        return $row;
    }
}
