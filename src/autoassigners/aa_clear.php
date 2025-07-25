<?php
// autoassigners/aa_clear.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Clear_Autoassigner extends Autoassigner {
    /** @var int|'conflict'|'lead'|'shepherd' */
    private $type;
    /** @var ?string */
    private $xtype;

    /** @param object $gj */
    function __construct(Contact $user, $gj) {
        parent::__construct($user);
        $this->xtype = $gj->type ?? null;
    }

    function option_schema() {
        return ["type$"];
    }

    function configure() {
        $t = $this->xtype ?? $this->option("type");
        if (in_array($t, ["conflict", "lead", "shepherd"], true)) {
            $this->type = $t;
        } else if (is_string($t) && ($x = ReviewInfo::parse_type($t, true))) {
            $this->type = $x;
        } else {
            $this->error_at("type", "<0>Expected review type, ‘conflict’, ‘lead’, or ‘shepherd’");
        }
    }

    function run() {
        if (is_int($this->type)) {
            $q = "select paperId, contactId from PaperReview where reviewType=" . $this->type;
            $action = "noreview";
        } else if ($this->type === "conflict") {
            $q = "select paperId, contactId from PaperConflict where conflictType>" . CONFLICT_MAXUNCONFLICTED . " and conflictType<" . CONFLICT_AUTHOR;
            $action = "noconflict";
        } else {
            $q = "select paperId, {$this->type}ContactId from Paper where {$this->type}ContactId!=0";
            $action = "no" . $this->type;
        }
        $this->set_assignment_action($action);
        $result = $this->conf->qe_raw($q);
        while (($row = $result->fetch_row())) {
            $this->assign1((int) $row[1], (int) $row[0]);
        }
        Dbl::free($result);
    }
}
