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
    }

    /** Install a refusal by $u, declining a request from $requester.
     * @param string $reason */
    private function add_refusal(Contact $u, Contact $requester, $reason) {
        $this->conf->qe("insert into PaperReviewRefused set paperId=?, contactId=?, email=?, requestedBy=?, refusedBy=?, reason=?, timeRefused=?",
            $this->pid, $u->contactId, $u->email, $requester->contactId,
            $u->contactId, $reason, Conf::$now);
    }

    private function clear_refusals() {
        $this->conf->qe("delete from PaperReviewRefused where paperId=?", $this->pid);
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
        $this->conf->invalidate_caches("users");
        xassert($this->conf->checked_user_by_email($u_secondary->email)->should_use_primary("extrev"));
        try {
            $f($u_secondary, $u_primary);
        } finally {
            $this->conf->qe("update ContactInfo set primaryContactId=0 where contactId=?",
                $u_secondary->contactId);
            $this->conf->invalidate_caches("users");
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
                $t = $this->masked_probe($u_secondary->email, $this->u_prober);
                $this->conf->qe("delete from PaperConflict where paperId=? and contactId=?",
                    $this->pid, $u_primary->contactId);
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
            $t = $this->masked_probe($u_secondary->email, $this->u_chair);
            $this->conf->qe("delete from PaperConflict where paperId=? and contactId=?",
                $this->pid, $u_primary->contactId);
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
        // a PC member conflicted with #20 cannot see its review assignments,
        // so must not be able to probe review ids
        xassert_assign($this->u_chair, "paper,action,email\n{$this->pid},conflict,marina@poema.ru\n");
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $u_outsider = $this->conf->checked_user_by_email("marina@poema.ru");
        $rrow = $prow->review_by_id($this->hidden_rid);
        xassert(!$u_outsider->can_view_review_assignment($prow, $rrow));
        foreach (["=acceptreview", "=declinereview", "=claimreview"] as $fn) {
            $hit = $this->observable($fn, $u_outsider,
                ["r" => (string) $this->hidden_rid], $prow);
            $miss = $this->observable($fn, $u_outsider,
                ["r" => "99999"], $prow);
            xassert_eqq($hit, $miss);
        }
        xassert_assign($this->u_chair, "paper,action,email\n{$this->pid},clearconflict,marina@poema.ru\n");
    }

    // When a review is actually created for a brand-new reviewer, the reviewer's
    // account must be a real, non-placeholder user — ReviewInfo activates the
    // placeholder on review creation (reviewinfo.php ~1079). These guard that
    // invariant, so a future change making account *creation* produce
    // placeholders (harmless for a dry_run) still yields real users once a
    // review materializes.

    function test_requestreview_needs_account() {
        // a review-accept link grants access to that review, but not the
        // reviewer’s right to request further reviews
        $conf = $this->conf;
        $email = "linkholder-probe@example.edu";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $anon = Contact::make($conf);
        $anon->set_capability("@ra{$this->pid}", $this->u_hidden->contactId);
        $prow = $conf->checked_paper_by_id($this->pid, $anon);
        xassert($this->u_hidden->can_request_review($prow, null, true));
        xassert(!$anon->can_request_review($prow, null, true));
        $jr = call_api_result("=requestreview", $anon,
            ["email" => $email, "given_name" => "Link", "family_name" => "Holder"], $prow);
        xassert_eqq($jr->status, 403);
        $t = json_encode($jr->content["message_list"] ?? [], JSON_UNESCAPED_UNICODE);
        xassert_str_contains($t, "aren’t allowed to request reviews");
        xassert_not_str_contains($t, "deadline");
        xassert(!$conf->fresh_user_by_email($email));
        if (($u = $conf->fresh_user_by_email($email))) {
            $conf->qe("delete from PaperReview where contactId=?", $u->contactId);
            $conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
        }

        // other users without request rights get the same reason
        $whynot = $this->u_author->perm_request_review($prow, null, true);
        xassert_str_contains(json_encode($whynot->message_list(), JSON_UNESCAPED_UNICODE),
                             "aren’t allowed to request reviews");
    }

    function test_only_managers_choose_review_rounds() {
        // a non-manager may name only the default round, or (when requesting)
        // the round of their own review; other rounds’ deadlines may differ
        $conf = $this->conf;
        $keys = ["tag_rounds", "rev_roundtag", "pcrev_soft_1", "pcrev_hard_1"];
        $old = [];
        foreach ($keys as $k) {
            $old[$k] = [$conf->setting($k), $conf->setting_data($k)];
        }
        $wrong_round = function ($whynot) {
            return $whynot && ($whynot["wrongReviewRound"] ?? false);
        };
        try {
            // R1, the assignment round, is closed; R2 and round 0 are open
            $conf->save_setting("tag_rounds", 1, "R1 R2");
            $conf->save_setting("rev_roundtag", 1, "R1");
            $conf->save_setting("pcrev_soft_1", Conf::$now - 200);
            $conf->save_refresh_setting("pcrev_hard_1", Conf::$now - 100);
            xassert_eqq($conf->assignment_round(false), 1);
            xassert_eqq($conf->round_number("R2"), 2);
            $prow = $conf->checked_paper_by_id($this->pid);

            // creating a review: only the default round
            $u = $this->u_control;
            xassert(!$prow->has_reviewer($u) && !$prow->has_conflict($u));
            foreach ([null, 1, 0, 2] as $r) {
                xassert(!$u->can_create_review($prow, $u, $r), "create round " . json_encode($r));
            }
            xassert($wrong_round($u->perm_create_review($prow, $u, 0)));
            xassert($wrong_round($u->perm_create_review($prow, $u, 2)));
            xassert($this->u_chair->can_create_review($prow, $u, 0));
            xassert($this->u_chair->can_create_review($prow, $u, 2));

            // requesting a review: the default round, or the requester’s own
            $u = $this->u_prober;
            xassert_eqq($prow->review_by_user($u)->reviewRound, 0);
            xassert(!$u->can_request_review($prow, null, true));
            xassert(!$u->can_request_review($prow, 2, true));
            xassert($wrong_round($u->perm_request_review($prow, 2, true)));
            xassert($u->can_request_review($prow, 0, true));
            xassert($this->u_chair->can_request_review($prow, 2, true));

            // a review-accept link lends its review’s round, not round 0
            $conf->qe("update PaperReview set reviewRound=1 where reviewId=?", $this->hidden_rid);
            $holder = $conf->fresh_user_by_email($this->u_control->email);
            $holder->set_capability("@ra{$this->pid}", $this->u_hidden->contactId);
            $hprow = $conf->checked_paper_by_id($this->pid, $holder);
            xassert(!$holder->can_request_review($hprow, 0, true));
            xassert($wrong_round($holder->perm_request_review($hprow, 0, true)));

            // with the assignment round open, the default round works
            $conf->save_refresh_setting("pcrev_hard_1", Conf::$now + 100);
            $prow = $conf->checked_paper_by_id($this->pid);
            xassert($this->u_control->can_create_review($prow, $this->u_control, null));
            xassert($this->u_control->can_create_review($prow, $this->u_control, 1));
            xassert($this->u_prober->can_request_review($prow, null, true));
            xassert($this->u_prober->can_request_review($prow, 1, true));
        } finally {
            $conf->qe("update PaperReview set reviewRound=0 where reviewId=?", $this->hidden_rid);
            foreach ($old as $k => $vd) {
                $conf->save_setting($k, $vd[0], $vd[1]);
            }
            $conf->refresh_settings();
        }
    }

    function test_approve_proposal_round_by_number() {
        // the assign page’s approval form names the proposal’s round by number
        $conf = $this->conf;
        $keys = ["tag_rounds", "rev_roundtag"];
        $old = [];
        foreach ($keys as $k) {
            $old[$k] = [$conf->setting($k), $conf->setting_data($k)];
        }
        $email = "proposal-round@_.com";
        try {
            $conf->save_setting("tag_rounds", 1, "R1 R2");
            $conf->save_refresh_setting("rev_roundtag", 1, "R1");
            xassert_eqq($conf->round_number("R2"), 2);
            $prow = $conf->checked_paper_by_id($this->pid);
            $args = ["email" => $email, "given_name" => "Proposal", "family_name" => "Round", "affiliation" => "Fart", "approvereview" => 1];

            // nonexistent rounds are rejected, by name or number
            foreach (["7", "R7"] as $r) {
                $jr = call_api_result("=requestreview", $this->u_chair, $args + ["round" => $r], $prow);
                xassert_eqq($jr->status, 400);
            }

            foreach (["2", "R2", "0", "unnamed"] as $r) {
                $this->clear_requests();
                $conf->qe("insert into ReviewRequest set paperId=?, email=?, firstName=?, lastName=?, affiliation=?, requestedBy=?, timeRequested=?, reason=?, reviewRound=?",
                    $this->pid, $email, "Proposal", "Round", "Fart",
                    $this->u_prober->contactId, Conf::$now, "", $conf->round_number($r));
                $jr = call_api_result("=requestreview", $this->u_chair, $args + ["round" => $r], $prow);
                xassert_eqq($jr->status ?? 200, 200);
                $u = $conf->checked_user_by_email($email);
                $rrow = $conf->checked_paper_by_id($this->pid)->review_by_user($u);
                xassert($rrow !== null);
                xassert_eqq($rrow->reviewRound, ctype_digit($r) ? (int) $r : $conf->round_number($r));
            }
        } finally {
            $this->clear_requests();
            foreach ($old as $k => $vd) {
                $conf->save_setting($k, $vd[0], $vd[1]);
            }
            $conf->refresh_settings();
        }
    }

    function test_review_link_refused_to_conflicted_user() {
        // a review-accept link lends its reviewer’s role only to holders
        // without a conflict on the submission
        $conf = $this->conf;
        $rrow = $conf->checked_paper_by_id($this->pid)->review_by_id($this->hidden_rid);
        $tok = ReviewAccept_Capability::make($rrow, true);
        $sibling = (new TokenInfo($conf, TokenInfo::REVIEWACCEPT))
            ->set_review($rrow)
            ->set_user_id($rrow->contactId)
            ->set_expires_in(86400)
            ->set_token_pattern("hcra{$rrow->reviewId}[16]")
            ->insert();
        try {
            // a conflicted holder gets nothing from the link, and is told why
            $author = $conf->fresh_user_by_email($this->u_author->email);
            $old_test_mode = Navigation::$test_mode;
            Navigation::$test_mode = 2;
            $conf->saved_messages_begin();
            $author->apply_capability_text($tok->salt);
            $msgs = json_encode($conf->claim_saved_messages(), JSON_UNESCAPED_UNICODE);
            Navigation::$test_mode = $old_test_mode;
            xassert_eqq($author->reviewer_capability($this->pid), null);
            $prow = $conf->checked_paper_by_id($this->pid, $author);
            xassert($prow->has_conflict($author));
            xassert(!$author->can_view_review($prow, $prow->review_by_id($this->hidden_rid)));
            xassert(!$author->can_request_review($prow, null, true));
            xassert_str_contains($msgs, "conflict");
            xassert_not_str_contains($msgs, $this->u_hidden->email);
            // ...and doesn't use up the link or its siblings
            xassert_eqq(TokenInfo::find($tok->salt, $conf)->timeUsed, 0);
            xassert(TokenInfo::find($sibling->salt, $conf)->is_active());

            // a holder without a conflict, signed in or not, acts as the reviewer
            foreach ([$conf->fresh_user_by_email($this->u_control->email), Contact::make($conf)] as $u) {
                $u->apply_capability_text($tok->salt);
                xassert_eqq($u->reviewer_capability($this->pid), $this->u_hidden->contactId);
                $prow = $conf->checked_paper_by_id($this->pid, $u);
                xassert($u->can_view_review($prow, $prow->review_by_id($this->hidden_rid)));
            }
        } finally {
            $conf->qe("delete from Capability where salt>=? and salt<?",
                "hcra{$rrow->reviewId}@", "hcra{$rrow->reviewId}~");
        }
    }

    function test_requestreview_creates_nonplaceholder_reviewer() {
        $conf = $this->conf;
        $email = "newrev-req-probe@example.edu";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $prow = $conf->checked_paper_by_id($this->pid);
        // chair + extrev_chairreq=0 + unconflicted new email => direct assignment
        call_api_result("=requestreview", $this->u_chair,
            ["email" => $email, "given_name" => "New", "family_name" => "Reviewer"], $prow);
        $u = $conf->fresh_user_by_email($email);
        xassert(!!$u);
        $conf->checked_paper_by_id($this->pid)->load_reviews(true);
        xassert(!!$conf->checked_paper_by_id($this->pid)->review_by_user($u)); // a review was created
        xassert(!$u->is_placeholder());
        if ($u) {
            $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u->contactId);
            $conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
        }
    }

    function test_assign_review_creates_nonplaceholder_reviewer() {
        $conf = $this->conf;
        $email = "newrev-assign-probe@example.edu";
        $conf->qe("delete from ContactInfo where email=?", $email);
        $reviewer = Contact::make_keyed($conf, ["email" => $email, "name" => "Assign Ee"])
            ->store(0, $this->u_chair);
        $rid = $this->u_chair->assign_review($this->pid, $reviewer, REVIEW_EXTERNAL);
        xassert($rid > 0);
        $u = $conf->fresh_user_by_email($email);
        xassert(!!$u);
        xassert(!$u->is_placeholder());
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u->contactId);
        $conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
    }

    function test_retractreview_cannot_remove_promoted_primary() {
        // HC-171: a non-admin requester must not be able to retract a review the
        // chair has promoted into a primary/secondary/meta assignment.
        $conf = $this->conf;
        $prow = $conf->checked_paper_by_id($this->pid);
        $u_victim = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u_victim->contactId);
        $prow->load_reviews(true);

        // estrin (a PC reviewer on #20) requests lixia (PC): creates a PC review
        // whose requestedBy is estrin
        $qreq = (new Qrequest("POST", ["email" => $u_victim->email]))->approve_token();
        $jr = RequestReview_API::requestreview($this->u_prober, $qreq, $prow);
        xassert($jr->content["ok"] ?? false);
        $prow->load_reviews(true);
        $rrow = $prow->fresh_review_by_user($u_victim);
        xassert(!!$rrow);
        xassert_eqq($rrow->reviewType, REVIEW_PC);
        xassert_eqq($rrow->requestedBy, $this->u_prober->contactId);

        // the chair promotes lixia to PRIMARY; ownership resets to the chair
        $this->u_chair->assign_review($this->pid, $u_victim, REVIEW_PRIMARY);
        $prow->load_reviews(true);
        $rrow = $prow->fresh_review_by_user($u_victim);
        xassert_eqq($rrow->reviewType, REVIEW_PRIMARY);
        xassert_eqq($rrow->requestedBy, $this->u_chair->contactId);

        // the original requester can no longer retract the chair's assignment
        $qreq = (new Qrequest("POST", ["email" => $u_victim->email]))->approve_token();
        $jr = RequestReview_API::retractreview($this->u_prober, $qreq, $prow);
        xassert(!($jr->content["ok"] ?? false));
        $prow->load_reviews(true);
        $rrow = $prow->fresh_review_by_user($u_victim);
        xassert(!!$rrow);
        xassert_eqq($rrow->reviewType, REVIEW_PRIMARY);

        // control: an unpromoted review the requester made can still be retracted
        $u_v2 = $conf->checked_user_by_email("van@ee.lbl.gov");
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u_v2->contactId);
        $prow->load_reviews(true);
        $qreq = (new Qrequest("POST", ["email" => $u_v2->email]))->approve_token();
        RequestReview_API::requestreview($this->u_prober, $qreq, $prow);
        $prow->load_reviews(true);
        xassert(!!$prow->fresh_review_by_user($u_v2));
        $qreq = (new Qrequest("POST", ["email" => $u_v2->email]))->approve_token();
        $jr = RequestReview_API::retractreview($this->u_prober, $qreq, $prow);
        xassert($jr->content["ok"] ?? false);
        $prow->load_reviews(true);
        xassert(!$prow->fresh_review_by_user($u_v2));

        // cleanup
        $conf->qe("delete from PaperReview where paperId=? and contactId=?", $this->pid, $u_victim->contactId);
        $prow->load_reviews(true);
    }
}
