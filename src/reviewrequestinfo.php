<?php
// reviewrequestinfo.php -- HotCRP ReviewRequest objects
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewRequestInfo {
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

    /** @var ?Contact */
    private $_reviewer;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    private function incorporate() {
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
    static function fetch($result, Conf $conf) {
        if (($row = $result->fetch_object("ReviewRequestInfo", [$conf]))) {
            $row->incorporate();
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
            $this->_reviewer = $this->conf->user_by_email($this->email, USER_SLICE)
                ?? Contact::make_keyed($this->conf, [
                       "contactId" => $this->contactId ?? 0,
                       "email" => $this->email,
                       "firstName" => $this->firstName,
                       "lastName" => $this->lastName,
                       "affiliation" => $this->affiliation
                   ]);
        }
        return $this->_reviewer;
    }
}
