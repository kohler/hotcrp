<?php
// reviewrefusalinfo.php -- HotCRP PaperReviewRefused objects
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewRefusalInfo {
    /** @var Conf
     * @readonly */
    public $conf;

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
    /** @var ?int */
    public $refusedReviewId;
    /** @var int */
    public $refusedReviewType;
    /** @var ?int */
    public $reviewRound;
    /** @var int */
    public $requestedBy;
    /** @var ?int */
    public $timeRequested;
    /** @var ?int */
    public $refusedBy;
    /** @var ?int */
    public $timeRefused;
    /** @var ?string */
    public $data;
    /** @var ?string */
    public $reason;

    /** @var null */
    public $reviewToken;
    /** @var int */
    public $reviewType = REVIEW_REFUSAL;

    /** @var ?Contact */
    private $_reviewer;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function fetch_incorporate() {
        $this->paperId = (int) $this->paperId;
        $this->contactId = (int) $this->contactId;
        if ($this->refusedReviewId !== null) {
            $this->refusedReviewId = (int) $this->refusedReviewId;
        }
        $this->refusedReviewType = (int) $this->refusedReviewType;
        if ($this->reviewRound !== null) {
            $this->reviewRound = (int) $this->reviewRound;
        }
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
        $this->reviewType = REVIEW_REFUSAL;
    }

    /** @return ?ReviewRefusalInfo */
    static function fetch($result, Conf $conf) {
        if (($row = $result->fetch_object("ReviewRefusalInfo", [$conf]))) {
            $row->fetch_incorporate();
        }
        return $row;
    }

    /** @return bool */
    function is_tentative() {
        return false;
    }

    /** @return Contact */
    function reviewer() {
        if ($this->_reviewer === null) {
            $this->_reviewer = $this->conf->user_by_id($this->contactId, USER_SLICE)
                ?? Contact::make_keyed($this->conf, [
                       "contactId" => $this->contactId,
                       "email" => $this->email,
                       "firstName" => $this->firstName,
                       "lastName" => $this->lastName,
                       "affiliation" => $this->affiliation
                   ]);
        }
        return $this->_reviewer;
    }
}
