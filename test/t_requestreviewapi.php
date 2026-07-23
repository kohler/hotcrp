<?php
// t_requestreviewapi.php -- HotCRP tests for review-request API information leaks
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

// Tests for information leaks in the review-request endpoints: POST
// /requestreview, /acceptreview, /declinereview, and /claimreview.
//
// One bit is deliberately NOT policed here. A request naming someone already
// involved with the submission must fail, and the requester necessarily learns
// that it failed — the same bit reaches them anyway when an administrator
// denies the proposal (@denyreviewrequest). Success-vs-failure is inherent to
// PC-requested reviews, so no test demands that a denial look like a success.
//
// What is policed is everything finer than that bit:
//
// * Resolution. A caller who cannot see reviewer identities, proposals, or
//   conflicts must not learn WHICH of those blocked the request: all four
//   causes must produce one identical response. Likewise /acceptreview and
//   friends must not let a caller distinguish a real review id from a bogus one.
// * Detail. A refusal's author and stated reason stay hidden — except from an
//   administrator, or from the caller whose own request the refusal answered.
// * Linkage. A *denied* request must not reveal that the typed address resolves
//   to some other account; that linkage is cross-conference cdb data and the
//   caller committed nothing to obtain it. A request that is actually attempted
//   may name the account it reached, because the caller did commit: the
//   reviewer is mailed, the row is stored, the act is logged, and the identity
//   would surface anyway once the review arrives.
//
// Several tests run the other way, pinning what must stay visible, so that a
// later tightening cannot blind administrators or leave a requester facing an
// unexplained address substitution.

class RequestReviewAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_prober;
    /** @var Contact
     * @readonly */
    public $u_hidden;
    /** @var Contact
     * @readonly */
    public $u_control;
    /** @var Contact
     * @readonly */
    public $u_author;
    /** @var int */
    public $pid = 20;
    /** @var int */
    public $hidden_rid;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        // estrin gets a PC review on #20, which is what lets him request
        // external reviews there (Contact::can_request_review)
        $this->u_prober = $conf->checked_user_by_email("estrin@usc.edu");
        // mgbaker is the reviewer whose presence must stay hidden
        $this->u_hidden = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        // varghese is an equivalent account that is *not* a reviewer: the control
        $this->u_control = $conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        // breslau authors #20 and is not on the PC
        $this->u_author = $conf->checked_user_by_email("breslau@parc.xerox.com");

        $conf->save_setting("rev_open", 1);
        $conf->save_setting("pcrev_soft", Conf::$now + 10000);
        $conf->save_setting("pcrev_hard", Conf::$now + 10000);
        $conf->save_setting("extrev_soft", Conf::$now + 10000);
        $conf->save_setting("extrev_hard", Conf::$now + 10000);
        $conf->save_setting("extrev_chairreq", 0);
        $conf->refresh_settings();

        $this->u_chair->assign_review($this->pid, $this->u_prober, REVIEW_PC);
        $this->hidden_rid = $this->u_chair->assign_review($this->pid, $this->u_hidden, REVIEW_PC);
        $conf->paper_by_id($this->pid, null, ["forceShow" => true]);
    }

    /** Collapse an API response to what a caller can actually observe.
     * @param string $fn
     * @param array<string,mixed> $args
     * @return string */
    private function observable($fn, Contact $user, $args, ?PaperInfo $prow = null) {
        $jr = call_api_result($fn, $user, $args, $prow);
        if (!($jr instanceof JsonResult)) {
            return "non-json";
        }
        $t = [];
        foreach ($jr->content["message_list"] ?? [] as $mi) {
            if (is_object($mi) && ($mi->message ?? "") !== "") {
                $t[] = $mi->message;
            }
        }
        sort($t);
        return ($jr->status ?? 200) . " " . join(" ~ ", $t);
    }

    /** A successful request writes a ReviewRequest row, or (when the requester
     * needs no approval) a whole external review. Undo both, so each probe
     * starts from the same state and only the hidden datum varies. */
    private function clear_requests() {
        $this->conf->qe("delete from ReviewRequest where paperId=?", $this->pid);
        $this->conf->qe("delete from PaperReview where paperId=? and contactId not in (?, ?)",
            $this->pid, $this->u_prober->contactId, $this->u_hidden->contactId);
        $this->conf->invalidate_caches([]);
    }

    /** Probe $fn twice, differing only in hidden state, and return the two
     * observables.
     * @param string $fn
     * @return array{string,string} */
    private function probe_pair($fn, Contact $user, $hit_args, $miss_args, PaperInfo $prow) {
        $this->clear_requests();
        $hit = $this->observable($fn, $user, $hit_args, $prow);
        $this->clear_requests();
        $miss = $this->observable($fn, $user, $miss_args, $prow);
        $this->clear_requests();
        // The control probe must actually succeed. If leftover state makes it
        // fail too, the two observables can match for the wrong reason and the
        // comparison below would pass vacuously.
        xassert_str_contains($miss, "200 ");
        return [$hit, $miss];
    }

    /** Request a review of $email and return the observable with $email masked,
     * so responses about different people can be compared directly.
     * @param string $email
     * @return string */
    private function masked_probe($email, Contact $user) {
        // clear only *after* probing: callers install the condition under test
        // (a pending proposal, say) immediately before the call
        $t = $this->observable("=requestreview", $user, ["email" => $email],
            $this->conf->checked_paper_by_id($this->pid));
        $this->clear_requests();
        return str_ireplace($email, "<EMAIL>", $t);
    }

    /** Install a pending proposal for $u by the chair. */
    private function add_request(Contact $u) {
        $this->conf->qe("insert into ReviewRequest set paperId=?, email=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, timeRequested=?, reason=?, reviewRound=?",
            $this->pid, $u->email, $u->firstName, $u->lastName, $u->affiliation,
            $this->u_chair->contactId, Conf::$now, "", null);
        $this->conf->invalidate_caches([]);
    }

    /** Install a refusal by $u, declining a request from $requester.
     * @param string $reason */
    private function add_refusal(Contact $u, Contact $requester, $reason) {
        $this->conf->qe("insert into PaperReviewRefused set paperId=?, contactId=?, email=?, requestedBy=?, refusedBy=?, reason=?, timeRefused=?",
            $this->pid, $u->contactId, $u->email, $requester->contactId,
            $u->contactId, $reason, Conf::$now);
        $this->conf->invalidate_caches([]);
    }

    private function clear_refusals() {
        $this->conf->qe("delete from PaperReviewRefused where paperId=?", $this->pid);
        $this->conf->invalidate_caches([]);
    }

    /** Hide reviewer identities from the PC, then run $f.
     * @param callable():void $f */
    private function with_blind_reviewers($f) {
        $old = $this->conf->setting("viewrevid");
        $this->conf->save_setting("viewrevid", Conf::VIEWREV_NEVER);
        $this->conf->refresh_settings();
        try {
            $f();
        } finally {
            $this->conf->save_setting("viewrevid", $old);
            $this->conf->refresh_settings();
        }
    }

    // ---- POST /requestreview -------------------------------------------

    /** The four blocking conditions must be indistinguishable from each other.
     * A prober learns "this address cannot be asked" — never which of reviewer,
     * proposal, refusal, or conflict is responsible. */
    function test_requestreview_blocking_causes_indistinguishable() {
        $this->with_blind_reviewers(function () {
            $prow = $this->conf->checked_paper_by_id($this->pid);
            $rrow = $prow->checked_review_by_user($this->u_hidden);
            xassert($this->u_prober->can_request_review($prow, null, true));
            xassert(!$this->u_prober->can_view_review_identity($prow, $rrow));
            xassert(!$this->u_prober->can_view_conflicts($prow));
            xassert(!$this->u_prober->allow_manage_reviews($prow));

            $u_pending = $this->conf->checked_user_by_email("lixia@cs.ucla.edu");
            $u_refuser = $this->conf->checked_user_by_email("jj@cse.ucsc.edu");
            $u_conflicted = $this->conf->checked_user_by_email("shenker@parc.xerox.com");

            // (a) already reviewing
            $obs = ["reviewer" => $this->masked_probe($this->u_hidden->email, $this->u_prober)];
            // (b) already proposed by someone else
            $this->add_request($u_pending);
            $obs["proposal"] = $this->masked_probe($u_pending->email, $this->u_prober);
            $this->clear_requests();
            // (c) previously declined
            $this->add_refusal($u_refuser, $this->u_chair, "I am hopelessly conflicted with the third author");
            $obs["refusal"] = $this->masked_probe($u_refuser->email, $this->u_prober);
            $this->clear_refusals();
            // (d) conflicted
            $obs["conflict"] = $this->masked_probe($u_conflicted->email, $this->u_prober);

            foreach (["proposal", "refusal", "conflict"] as $k) {
                xassert_eqq($obs[$k], $obs["reviewer"]);
            }
            // ...and the endpoint still works for an unencumbered address, so
            // the four are not merely all failing for some unrelated reason
            xassert_str_contains($this->masked_probe($this->u_control->email, $this->u_prober), "200 ");
        });
    }

    /** A refusal's author and stated reason are never disclosed to a prober who
     * cannot see review identities. */
    function test_requestreview_hides_refusal_details() {
        $this->with_blind_reviewers(function () {
            $u_refuser = $this->conf->checked_user_by_email("jj@cse.ucsc.edu");
            $this->add_refusal($u_refuser, $this->u_chair, "I am hopelessly conflicted with the third author");
            $t = $this->masked_probe($u_refuser->email, $this->u_prober);
            $this->clear_refusals();
            xassert_not_str_contains($t, "hopelessly conflicted");
            xassert_not_str_contains($t, "Garcia-Luna");
            xassert_not_str_contains($t, "declined");
        });
    }

    /** Even a caller who may see reviewer identities gets the decline notice
     * without the reason, unless the refusal answered their own request. */
    function test_requestreview_refusal_reason_needs_requester() {
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $u_refuser = $this->conf->checked_user_by_email("jj@cse.ucsc.edu");
        xassert($this->u_prober->can_view_review_identity($prow, null));

        // the chair asked; the prober did not
        $this->add_refusal($u_refuser, $this->u_chair, "I am hopelessly conflicted with the third author");
        $t = $this->masked_probe($u_refuser->email, $this->u_prober);
        $this->clear_refusals();
        xassert_str_contains($t, "declined");
        xassert_not_str_contains($t, "hopelessly conflicted");

        // the prober asked, so the answer to their own request is theirs to read
        $this->add_refusal($u_refuser, $this->u_prober, "I am hopelessly conflicted with the third author");
        $t = $this->masked_probe($u_refuser->email, $this->u_prober);
        $this->clear_refusals();
        xassert_str_contains($t, "hopelessly conflicted");
    }

    /** Link van's account to cheshire's as its primary, run $f, then unlink.
     * @param callable(Contact,Contact):void $f */
    private function with_primary_link($f) {
        $u_secondary = $this->conf->checked_user_by_email("van@ee.lbl.gov");
        $u_primary = $this->conf->checked_user_by_email("cheshire@cs.stanford.edu");
        $this->conf->qe("update ContactInfo set primaryContactId=? where contactId=?",
            $u_primary->contactId, $u_secondary->contactId);
        $this->conf->invalidate_caches(["users" => true]);
        xassert($this->conf->checked_user_by_email($u_secondary->email)->should_use_primary("extrev"));
        try {
            $f($u_secondary, $u_primary);
        } finally {
            $this->conf->qe("update ContactInfo set primaryContactId=0 where contactId=?",
                $u_secondary->contactId);
            $this->conf->invalidate_caches(["users" => true]);
        }
    }

    /** A *denied* request must not reveal the primary account behind a typed
     * secondary address. The prober committed nothing, so the linkage — which
     * is cross-conference cdb data — is not theirs to learn. */
    function test_requestreview_denial_hides_primary_account() {
        $this->with_blind_reviewers(function () {
            $this->with_primary_link(function ($u_secondary, $u_primary) {
                // block the request: the primary is conflicted with #20
                $this->conf->qe("insert into PaperConflict set paperId=?, contactId=?, conflictType=?",
                    $this->pid, $u_primary->contactId, CONFLICT_AUTHOR);
                $this->conf->invalidate_caches([]);
                $t = $this->masked_probe($u_secondary->email, $this->u_prober);
                $this->conf->qe("delete from PaperConflict where paperId=? and contactId=?",
                    $this->pid, $u_primary->contactId);
                $this->conf->invalidate_caches([]);
                xassert_str_contains($t, "cannot be asked");
                xassert_not_str_contains($t, $u_primary->email);
                xassert_not_str_contains($t, "Cheshire");
            });
        });
    }

    /** An administrator may always see the redirection, including on a denial:
     * they can view the linkage anyway, and without the note the denial names
     * an address the requester never typed. */
    function test_requestreview_admin_sees_primary_note_on_denial() {
        $this->with_primary_link(function ($u_secondary, $u_primary) {
            $this->conf->qe("insert into PaperConflict set paperId=?, contactId=?, conflictType=?",
                $this->pid, $u_primary->contactId, CONFLICT_AUTHOR);
            $this->conf->invalidate_caches([]);
            $t = $this->masked_probe($u_secondary->email, $this->u_chair);
            $this->conf->qe("delete from PaperConflict where paperId=? and contactId=?",
                $this->pid, $u_primary->contactId);
            $this->conf->invalidate_caches([]);
            xassert_str_contains($t, "primary account");
        });
    }

    /** A request that actually goes through *may* name the account it went to,
     * and deliberately does. The requester committed: the reviewer is mailed
     * (@requestreview), the row is stored, the act is logged, and the identity
     * would surface anyway when the review arrives. Telling them promptly is
     * more accurate than letting them believe they invited someone else. */
    function test_requestreview_success_may_name_primary() {
        $this->with_primary_link(function ($u_secondary, $u_primary) {
            $t = $this->masked_probe($u_secondary->email, $this->u_prober);
            xassert_str_contains($t, "200 ");
            xassert_str_contains($t, $u_primary->email);
        });
    }

    function test_requestreview_chair_still_informed() {
        // the fix must not blind administrators: a chair legitimately sees why
        // a request cannot go through
        $this->with_blind_reviewers(function () {
            $prow = $this->conf->checked_paper_by_id($this->pid);
            list($hit, $miss) = $this->probe_pair("=requestreview", $this->u_chair,
                ["email" => $this->u_hidden->email],
                ["email" => $this->u_control->email], $prow);
            xassert_neqq($hit, $miss);
            xassert_str_contains($hit, "already reviewing");
        });
    }

    // ---- POST /acceptreview, /declinereview, /claimreview ---------------

    // These take a review id `r`. The question is whether a caller who may not
    // view the paper's reviews can tell an existing review id from a bogus one.

    function test_acceptdecline_hide_review_existence_from_author() {
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $rrow = $prow->checked_review_by_user($this->u_hidden);
        xassert(!$this->u_author->can_view_review_assignment($prow, $rrow));
        foreach (["=acceptreview", "=declinereview", "=claimreview"] as $fn) {
            $hit = $this->observable($fn, $this->u_author,
                ["r" => (string) $this->hidden_rid], $prow);
            $miss = $this->observable($fn, $this->u_author,
                ["r" => "99999"], $prow);
            xassert_eqq($hit, $miss);
        }
    }

    function test_acceptdecline_hide_review_existence_from_outsider() {
        // a PC member with no assignment on #20 and no ability to view its
        // reviews should likewise not be able to probe review ids
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $u_outsider = $this->conf->checked_user_by_email("marina@poema.ru");
        foreach (["=acceptreview", "=declinereview", "=claimreview"] as $fn) {
            $hit = $this->observable($fn, $u_outsider,
                ["r" => (string) $this->hidden_rid], $prow);
            $miss = $this->observable($fn, $u_outsider,
                ["r" => "99999"], $prow);
            xassert_eqq($hit, $miss);
        }
    }

    // When a review is actually created for a brand-new reviewer, the reviewer's
    // account must be a real, non-placeholder user — ReviewInfo activates the
    // placeholder on review creation (reviewinfo.php ~1079). These guard that
    // invariant, so a future change making account *creation* produce
    // placeholders (harmless for a dry_run) still yields real users once a
    // review materializes.

    function test_requestreview_creates_nonplaceholder_reviewer() {
        $conf = $this->conf;
        $email = "newrev-req-probe@example.edu";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches(["users" => true]);
        $prow = $conf->checked_paper_by_id($this->pid);
        // chair + extrev_chairreq=0 + unconflicted new email => direct assignment
        call_api_result("=requestreview", $this->u_chair,
            ["email" => $email, "given_name" => "New", "family_name" => "Reviewer"], $prow);
        $conf->invalidate_caches(["users" => true]);
        $u = $conf->fresh_user_by_email($email);
        xassert(!!$u);
        $conf->checked_paper_by_id($this->pid)->load_reviews(true);
        xassert(!!$conf->checked_paper_by_id($this->pid)->review_by_user($u)); // a review was created
        xassert(!$u->is_placeholder());
        if ($u) {
            $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u->contactId);
            $conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
            $conf->invalidate_caches(["users" => true]);
        }
    }

    function test_assign_review_creates_nonplaceholder_reviewer() {
        $conf = $this->conf;
        $email = "newrev-assign-probe@example.edu";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $conf->invalidate_caches(["users" => true]);
        $reviewer = Contact::make_keyed($conf, ["email" => $email, "name" => "Assign Ee"])
            ->store(0, $this->u_chair);
        $rid = $this->u_chair->assign_review($this->pid, $reviewer, REVIEW_EXTERNAL);
        xassert($rid > 0);
        $conf->invalidate_caches(["users" => true]);
        $u = $conf->fresh_user_by_email($email);
        xassert(!!$u);
        xassert(!$u->is_placeholder());
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u->contactId);
        $conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
        $conf->invalidate_caches(["users" => true]);
    }
}
