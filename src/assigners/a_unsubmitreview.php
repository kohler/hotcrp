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
    /** @param 'pid'|'cid' $key
     * @param ?int $pid
     * @param ?int $cid
     * @return list<int> */
    static private function make_filter(AssignmentState $state, $key, $pid, $cid) {
        $cf = [];
        foreach ($state->query(new Review_Assignable($pid, $cid)) as $m) {
            if (($m->_rflags & ReviewInfo::RFM_NONDRAFT) !== 0)
                $cf[] = $m->$key;
        }
        return $cf;
    }
    function paper_filter($contact, $req, AssignmentState $state) {
        $pids = self::make_filter($state, "pid", null, $contact->contactId);
        return array_fill_keys($pids, true);
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $cids = self::make_filter($state, "cid", $prow->paperId, null);
        return $state->users_by_id($cids);
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
            && strcasecmp($rarg0, "any") !== 0
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
        $matcher = new Review_Assignable($prow->paperId, $contact->contactId, $oldtype, $oldround);
        foreach ($state->query($matcher) as $r) {
            if (($r->_rflags & ReviewInfo::RFM_NONDRAFT) !== 0) {
                $r = clone $r;
                $r->_rflags &= ~ReviewInfo::RFM_NONDRAFT;
                $state->add($r);
            }
        }
        return true;
    }
}
