<?php
// paperuservalue.php -- HotCRP per-submission, per-user values
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

/** One user’s value of a `PaperUserOption` for a submission, such as a
 * committee member’s opinion of it, stored in the `PaperUserValue` table. */
final class PaperUserValue {
    /** @var int */
    public $paperId;
    /** @var int */
    public $puoId;
    /** @var int */
    public $contactId;
    /** @var int */
    public $value;
    /** @var int */
    public $timestamp;

    /** @param int $paperId
     * @param int $puoId
     * @param int $contactId
     * @param int $value
     * @param int $timestamp */
    function __construct($paperId, $puoId, $contactId, $value, $timestamp) {
        $this->paperId = $paperId;
        $this->puoId = $puoId;
        $this->contactId = $contactId;
        $this->value = $value;
        $this->timestamp = $timestamp;
    }
}
