<?php
// a_unsubmitreview.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class UnsubmitReview_AssignmentParser extends AssignmentParser {
    function __construct() {
        parent::__construct("unsubmitreview");
    }
    function load_state(AssignmentState $state) {
        Review_AssignmentParser::load_review_state($state);
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        return $state->user->can_administer($prow);
    }
    function user_universe($req, AssignmentState $state) {
        return "reviewers";
    }
    function paper_filter($contact, $req, AssignmentState $state) {
        return $state->make_filter("pid", (new Review_Assignable(null, $contact->contactId))->set_rnondraft(1));
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $cf = $state->make_filter("cid", (new Review_Assignable($prow->paperId, null))->set_rnondraft(1));
        return $state->users_by_id(array_keys($cf));
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        return $contact->contactId != 0;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // parse round and reviewtype arguments
        $rarg0 = trim((string) $req["round"]);
        $oldround = null;
        if ($rarg0 !== ""
            && strcasecmp($rarg0, "any") != 0
            && ($oldround = $state->conf->round_number($rarg0)) === null) {
            return new AssignmentError("<0>Review round ‘{$rarg0}’ not found");
        }
        $targ0 = trim((string) $req["reviewtype"]);
        $oldtype = null;
        if ($targ0 !== ""
            && ($oldtype = ReviewInfo::parse_type($targ0, true)) === false) {
            return new AssignmentError("<0>Invalid review type ‘{$targ0}’");
        }

        // remove existing review
        $matches = $state->remove((new Review_Assignable($prow->paperId, $contact->contactId, $oldtype, $oldround))->set_rnondraft(1));
        foreach ($matches as $r) {
            $r->_rsubmitted = $r->_rnondraft = 0;
            $state->add($r);
        }
        return true;
    }
}
