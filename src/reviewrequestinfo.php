<?php
// reviewrequestinfo.php -- HotCRP ReviewRequest objects
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ReviewRequestInfo {
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
    /** @var ?string */
    public $reason;
    /** @var int */
    public $requestedBy;
    /** @var string */
    public $timeRequested;
    /** @var ?int */
    public $reviewRound;

    /** @var ?int */
    public $contactId;
    /** @var null */
    public $reviewToken;
    /** @var int */
    public $reviewType = REVIEW_REQUEST;

    private function merge() {
        $this->paperId = (int) $this->paperId;
        $this->requestedBy = (int) $this->requestedBy;
        if ($this->reviewRound !== null) {
            $this->reviewRound = (int) $this->reviewRound;
        }
        if ($this->contactId !== null) {
            $this->contactId = (int) $this->contactId;
        }
        $this->reviewType = REVIEW_REQUEST;
    }

    /** @return ?ReviewRequestInfo */
    static function fetch($result) {
        if (($row = $result->fetch_object("ReviewRequestInfo"))) {
            $row->merge();
        }
        return $row;
    }
}
