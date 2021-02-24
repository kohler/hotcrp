<?php
// a_error.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Error_AssignmentParser extends UserlessAssignmentParser {
    private $iswarning;
    function __construct(Conf $conf, $aj) {
        parent::__construct("error");
        $this->iswarning = $aj->name === "warning";
    }
    function paper_universe($req, AssignmentState $state) {
        return "none";
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $m = $req["message"] ?? ($this->iswarning ? "Warning" : "Error");
        $state->msg_near($state->landmark, htmlspecialchars($m), $this->iswarning ? 1 : 2);
    }
}
