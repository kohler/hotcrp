<?php
// logentrygenerator.php -- HotCRP action log entries and generator
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class LogEntry {
    /** @var int */
    public $logId;
    /** @var int */
    public $timestamp;
    /** @var int */
    public $contactId;
    /** @var ?int */
    public $destContactId;
    /** @var ?int */
    public $trueContactId;
    /** @var ?string */
    public $ipaddr;
    /** @var string */
    public $action;
    /** @var ?int */
    public $paperId;
    public $data;

    /** @var ?string */
    private $cleanedAction;
    /** @var ?list<int> */
    private $paperIdArray;
    /** @var ?list<int> */
    public $destContactIdArray;
    /** @var int */
    public $ordinal;

    function incorporate() {
        $this->logId = (int) $this->logId;
        $this->timestamp = (int) $this->timestamp;
        $this->contactId = (int) $this->contactId;
        $this->destContactId = (int) $this->destContactId;
        $this->trueContactId = (int) $this->trueContactId;
        if ($this->paperId !== null) {
            $this->paperId = (int) $this->paperId;
        }
    }

    /** @return list<int> */
    function paper_ids() {
        if ($this->paperIdArray !== null) {
            return $this->paperIdArray;
        }
        $this->paperIdArray = [];
        if ($this->paperId) {
            $this->paperIdArray[] = $this->paperId;
        }
        if (preg_match('/\(papers ([\d, ]++)\)?\z/', $this->action, $m)) {
            $this->cleanedAction = rtrim(substr($this->action, 0, -strlen($m[0])));
            foreach (preg_split('/[\s,]+/', $m[1]) as $p) {
                if ($p !== "")
                    $this->paperIdArray[] = (int) $p;
            }
            $this->paperIdArray = array_values(array_unique($this->paperIdArray));
        } else {
            $this->cleanedAction = $this->action;
        }
        return $this->paperIdArray;
    }

    /** @return string */
    function cleaned_action() {
        if ($this->cleanedAction === null) {
            $this->paper_ids();
        }
        return $this->cleanedAction;
    }

    function prepare_merge() {
        $this->destContactIdArray = [$this->destContactId ? : $this->contactId];
        $this->destContactId = null;
        $this->paper_ids();
        $this->paperId = null;
    }

    function merge(LogEntry $x) {
        assert($this->action === $x->action && $this->logId > $x->logId);
        $this->destContactIdArray[] = $x->destContactId ? : $x->contactId;
        if ($x->paperId) {
            $this->paperIdArray[] = $x->paperId;
        }
        $this->logId = $x->logId;
    }

    /** @return string */
    function unparse_via() {
        $tcid = $this->trueContactId ?? 0;
        if ($tcid === 0) {
            return "";
        } else if ($tcid > 0) {
            return "admin";
        } else if ($tcid === -1) {
            return "link";
        } else if ($tcid === -2) {
            return "API token";
        } else if ($tcid === -3) {
            return "command line";
        }
        return "unknown";
    }
}
