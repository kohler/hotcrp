<?php
// t_followapi.php -- HotCRP tests for the follow API and review followers
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class FollowAPI_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $pid = 28;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mjh;     // author of paper 28
    /** @var Contact
     * @readonly */
    public $u_lixia;   // reviewer of paper 28
    /** @var Contact
     * @readonly */
    public $u_varghese; // reviewer of paper 28
    /** @var Contact
     * @readonly */
    public $u_estrin;  // PC member, not a reviewer of paper 28
    /** @var Contact
     * @readonly */
    public $u_mgbaker; // ghost reviewer of paper 28

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $conf->save_setting("rev_open", 1);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mjh = $conf->checked_user_by_email("mjh@isi.edu");
        $this->u_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $this->u_varghese = $conf->checked_user_by_email("varghese@ccrc.wustl.edu");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // start from a clean slate: no comments and no explicit watches
        $conf->qe("delete from PaperComment where paperId=?", $this->pid);
        $conf->qe("delete from PaperWatch where paperId=?", $this->pid);

        // assign two live reviewers (only one of them starts a review; both
        // follow the submission thread by virtue of being assigned reviewers)
        // plus one ghost reviewer (no review rights, so no default follow).
        $as = (new AssignmentSet($this->u_chair))->set_override_conflicts(true);
        $as->parse("paper,action,email,ghost\n"
            . "{$this->pid},primary,{$this->u_lixia->email},no\n"
            . "{$this->pid},primary,{$this->u_varghese->email},no\n"
            . "{$this->pid},primary,{$this->u_mgbaker->email},yes");
        xassert($as->execute());
        $conf->invalidate_caches(["pc" => true]);

        $prow = $conf->checked_paper_by_id($this->pid);
        $tf = new ReviewValues($this->u_lixia);
        xassert($tf->parse_json(["ovemer" => 2, "revexp" => 1, "papsum" => "Summary", "comaut" => "Comments"]));
        xassert($tf->check_and_save($prow));
    }

    /** @param int $topic
     * @return list<string> */
    private function follower_emails($topic) {
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $emails = array_map(function ($u) { return $u->email; }, $prow->review_followers($topic));
        sort($emails);
        return $emails;
    }

    function test_authors_and_reviewers_follow() {
        // authors and active reviewers follow the submission thread by default
        $emails = $this->follower_emails(CommentInfo::CT_TOPIC_PAPER);
        xassert(in_array($this->u_mjh->email, $emails, true));
        xassert(in_array($this->u_lixia->email, $emails, true));
        xassert(in_array($this->u_varghese->email, $emails, true));
        // a non-reviewer PC member does not follow by default
        xassert(!in_array($this->u_estrin->email, $emails, true));
        // a ghost reviewer has no review rights, so does not follow by default
        $prow = $this->conf->checked_paper_by_id($this->pid);
        xassert($prow->review_by_user($this->u_mgbaker)->is_ghost());
        xassert(!in_array($this->u_mgbaker->email, $emails, true));
    }

    function test_explicit_follow() {
        // a reviewer asks not to follow; a non-reviewer PC member asks to follow
        $prow = $this->conf->checked_paper_by_id($this->pid);
        $jr = call_api("=follow", $this->u_lixia, ["u" => $this->u_lixia->email, "following" => "no"], $prow);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->following, false);

        $jr = call_api("=follow", $this->u_estrin, ["u" => $this->u_estrin->email, "following" => "yes"], $prow);
        xassert_eqq($jr->ok, true);
        xassert_eqq($jr->following, true);

        // the no-following reviewer drops out; the explicitly-following PC
        // member appears
        $emails = $this->follower_emails(CommentInfo::CT_TOPIC_PAPER);
        xassert(!in_array($this->u_lixia->email, $emails, true));
        xassert(in_array($this->u_estrin->email, $emails, true));
        // authors and the other reviewer still follow
        xassert(in_array($this->u_mjh->email, $emails, true));
        xassert(in_array($this->u_varghese->email, $emails, true));
    }

    function test_clearfollow_any_does_not_leak_followers() {
        // Confidentiality: an author who cannot manage the paper must not be
        // able to learn, from `clearfollow`/`any` error messages, which other
        // users explicitly follow the paper.
        $this->conf->qe("delete from PaperWatch where paperId=?", $this->pid);
        $prow = $this->conf->checked_paper_by_id($this->pid);

        // sanity: mjh is an author who cannot manage the paper
        xassert($prow->has_author($this->u_mjh));
        xassert(!$this->u_mjh->can_manage($prow));

        // a non-reviewer PC member explicitly follows the paper
        $jr = call_api("=follow", $this->u_estrin, ["u" => $this->u_estrin->email, "following" => "yes"], $prow);
        xassert_eqq($jr->ok, true);

        // the author clears follows for "any" user
        $jr = call_api("=assign", $this->u_mjh,
            TestQreq::post(["assignments" => "action,pid,user\nclearfollow,{$this->pid},any"]), $prow);

        // the response must not disclose the follower's identity
        $text = "";
        foreach ($jr->message_list ?? [] as $mi) {
            $text .= $mi->message . "\n";
        }
        xassert_not_str_contains($text, $this->u_estrin->email);
        xassert_not_str_contains($text, "Estrin");
    }

    function finalize() {
        // remove the reviews and watches added to paper 28 so later testers
        // see it in its original review-free state
        $this->conf->qe("delete from PaperReview where paperId=?", $this->pid);
        $this->conf->qe("delete from PaperWatch where paperId=?", $this->pid);
        $this->conf->invalidate_caches(["paper" => true, "pc" => true]);
    }
}
