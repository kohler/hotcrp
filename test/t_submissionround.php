<?php
// t_submissionround.php -- HotCRP tests for named submission rounds/classes
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb(true)]
class SubmissionRound_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_estrin;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;

    /** @var int */
    private $reg_deadline;
    /** @var int */
    private $sub_deadline;
    /** @var int */
    private $pid_estrin;
    /** @var int */
    private $pid_other;
    /** @var int */
    private $pid_main;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        // open submissions, main deadline in the future
        $conf->save_setting("sub_open", 1);
        $conf->save_setting("sub_reg", Conf::$now + 8000);
        $conf->save_setting("sub_sub", Conf::$now + 10000);
        $conf->refresh_settings();
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_estrin = $conf->checked_user_by_email("estrin@usc.edu"); // pc
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu"); // pc
        // named round deadlines distinct from the main round
        $this->reg_deadline = Conf::$now + 80000;
        $this->sub_deadline = Conf::$now + 100000;
    }

    function test_no_named_rounds_initially() {
        xassert(!$this->conf->has_named_submission_rounds());
        // the unnamed round is always present
        $srs = $this->conf->submission_round_list();
        xassert_eqq(count($srs), 1);
        xassert($srs[0]->unnamed);
        // the unnamed round is returned for empty/"unnamed" tags
        xassert($this->conf->submission_round_by_tag("")->unnamed);
        xassert($this->conf->submission_round_by_tag("unnamed")->unnamed);
        xassert_eqq($this->conf->submission_round_by_tag("R2"), null);
    }

    function test_add_round_via_settings() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_submission" => 1,
            "submission/1/id" => "new",
            "submission/1/tag" => "R2",
            "submission/1/label" => "Round Two",
            "submission/1/registration" => "@" . $this->reg_deadline,
            "submission/1/done" => "@" . $this->sub_deadline
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        xassert($this->conf->has_named_submission_rounds());
        $srs = $this->conf->submission_round_list();
        // named round precedes the unnamed round
        xassert_eqq(count($srs), 2);

        $sr = $this->conf->submission_round_by_tag("R2");
        xassert(!!$sr);
        xassert(!$sr->unnamed);
        xassert_eqq($sr->tag, "R2");
        xassert_eqq($sr->label, "Round Two");
        xassert_eqq($sr->prefix, "Round Two ");
        // the named round has its own deadlines, distinct from the main round
        xassert_eqq($sr->register, $this->reg_deadline);
        xassert_eqq($sr->submit, $this->sub_deadline);
        xassert_neqq($sr->submit, $this->conf->setting("sub_sub"));
        // the named round inherits `open` from the main round
        xassert_eqq($sr->open, $this->conf->setting("sub_open"));
        xassert($sr->time_open());

        // tag lookups are case-insensitive
        xassert_eqq($this->conf->submission_round_by_tag("r2"), $sr);
    }

    function test_round_tag_is_a_submission_class() {
        // the round tag is registered as a submission-class tag that PC
        // members can see even on conflicted papers
        $dt = $this->conf->tags()->find("R2");
        xassert(!!$dt);
        xassert($dt->is(TagInfo::TF_SCLASS));
        xassert($dt->is(TagInfo::TF_PC_PUBLIC));
    }

    /** @param Contact $user
     * @param string $title
     * @param string $email
     * @param ?string $sclass
     * @return int */
    private function make_submitted_paper($user, $title, $email, $sclass) {
        $j = [
            "id" => "new",
            "title" => $title,
            "abstract" => "Abstract of {$title}.\n",
            "authors" => [["name" => "Author of {$title}", "email" => $email]],
            "submission" => ["content" => "%PDF-2.0\n{$title}", "type" => "application/pdf"],
            "status" => ["submitted" => true]
        ];
        if ($sclass !== null) {
            $j["submission_class"] = $sclass;
        }
        $ps = new PaperStatus($user);
        xassert($ps->save_paper_json(json_decode(json_encode($j))));
        xassert_paper_status($ps);
        xassert($ps->paperId > 0);
        return $ps->paperId;
    }

    function test_create_papers_in_round() {
        // a PC member submits their own paper into round R2
        $this->pid_estrin = $this->make_submitted_paper($this->u_estrin,
            "Estrin R2 paper", "estrin@usc.edu", "R2");
        // a non-PC author submits into round R2
        $this->pid_other = $this->make_submitted_paper($this->u_chair,
            "Other R2 paper", "outsider@_.com", "R2");
        // a paper in the default (unnamed) round
        $this->pid_main = $this->make_submitted_paper($this->u_chair,
            "Main round paper", "someoneelse@_.com", null);

        // papers land in the right round and carry the round tag
        $p_estrin = $this->conf->checked_paper_by_id($this->pid_estrin);
        xassert_eqq($p_estrin->submission_round()->tag, "R2");
        xassert($p_estrin->has_tag("R2"));
        xassert($p_estrin->timeSubmitted > 0);

        $p_main = $this->conf->checked_paper_by_id($this->pid_main);
        xassert($p_main->submission_round()->unnamed);
        xassert(!$p_main->has_tag("R2"));

        // estrin is a conflicted author on their own R2 paper
        xassert_eqq($p_estrin->conflict_type($this->u_estrin) & CONFLICT_AUTHOR, CONFLICT_AUTHOR);
    }

    function test_pc_search_round_tag_includes_own() {
        $r2 = [$this->pid_estrin, $this->pid_other];
        sort($r2);

        // a PC member who is a conflicted author still gets their own paper
        // when searching for the submission-round tag
        xassert_search($this->u_estrin, "#R2", $r2);
        // an uninvolved PC member sees the same set
        xassert_search($this->u_mgbaker, "#R2", $r2);
        // the chair sees the same set
        xassert_search($this->u_chair, "#R2", $r2);

        // the `sclass:` search keyword agrees with the tag search
        xassert_search($this->u_estrin, "sclass:R2", $r2);
        // the main-round paper is not in R2
        xassert_search($this->u_chair, "sclass:any", [$this->pid_estrin, $this->pid_other]);

        // negated / main-round searches exclude the R2 papers
        xassert_search($this->u_mgbaker, "#R2 {$this->pid_main}", []);

        // Contrast: a *regular* tag is invisible to a conflicted PC author,
        // so the round tag's inclusion of estrin's own paper is meaningful.
        xassert(!$this->conf->pc_can_view_conflicted_tags());
        $aset = new AssignmentSet($this->u_chair);
        $aset->parse("paper,action,tag\n{$this->pid_estrin},tag,priv\n");
        xassert($aset->execute());
        // the chair sees the regular tag on estrin's paper...
        xassert_search($this->u_chair, "#priv", [$this->pid_estrin]);
        // ...but estrin, conflicted on their own paper, does not...
        xassert_search($this->u_estrin, "#priv", []);
        // ...even though the submission-round tag remains visible to them.
        xassert_search($this->u_estrin, "#R2", $r2);
    }

    function test_pc_cannot_change_sclass_tag() {
        // a submission-class tag determines a paper's submission round,
        // so a PC member must not be able to add or remove it
        $p_main = $this->conf->checked_paper_by_id($this->pid_main);
        $p_other = $this->conf->checked_paper_by_id($this->pid_other);
        xassert(!$this->u_mgbaker->can_edit_tag($p_main, "R2", null, 0));
        xassert(!$this->u_mgbaker->can_edit_tag($p_other, "R2", 0, null));
        xassert(!$this->u_mgbaker->can_edit_tag_somewhere("R2"));

        // ...not by assignment, either
        xassert_assign_fail($this->u_mgbaker, "paper,action,tag\n{$this->pid_main},tag,R2\n");
        xassert_assign_fail($this->u_mgbaker, "paper,action,tag\n{$this->pid_other},tag,-R2\n");

        // the papers' submission rounds are unchanged
        $p_main = $this->conf->checked_paper_by_id($this->pid_main);
        xassert(!$p_main->has_tag("R2"));
        xassert($p_main->submission_round()->unnamed);
        $p_other = $this->conf->checked_paper_by_id($this->pid_other);
        xassert($p_other->has_tag("R2"));
        xassert_eqq($p_other->submission_round()->tag, "R2");

        // the chair can change the submission class
        xassert($this->u_chair->can_edit_tag($p_main, "R2", null, 0));
        xassert_assign($this->u_chair, "paper,action,tag\n{$this->pid_main},tag,R2\n");
        $p_main = $this->conf->checked_paper_by_id($this->pid_main);
        xassert_eqq($p_main->submission_round()->tag, "R2");
        xassert_assign($this->u_chair, "paper,action,tag\n{$this->pid_main},tag,-R2\n");
        $p_main = $this->conf->checked_paper_by_id($this->pid_main);
        xassert($p_main->submission_round()->unnamed);
    }

    function test_round_deadline_behavior() {
        $sr = $this->conf->submission_round_by_tag("R2");
        // both deadlines are in the future: registration and submission open
        xassert($sr->time_register(false));
        xassert($sr->time_submit(false));
        xassert($sr->time_edit(false, false));

        // move the round's submission deadline into the past via settings
        $sv = SettingValues::make_request($this->u_chair, [
            "has_submission" => 1,
            "submission/1/id" => "R2",
            "submission/1/tag" => "R2",
            "submission/1/registration" => "@" . (Conf::$now - 10000),
            "submission/1/done" => "@" . (Conf::$now - 5000)
        ]);
        xassert($sv->execute());
        xassert_eqq($sv->full_feedback_text(), "");

        $sr = $this->conf->submission_round_by_tag("R2");
        xassert_eqq($sr->submit, Conf::$now - 5000);
        xassert(!$sr->time_register(false));
        xassert(!$sr->time_submit(false));
        // the main round is unaffected by the named round's deadline
        xassert($this->conf->unnamed_submission_round()->time_submit(false));

        // registration deadline must precede the submission deadline
        $sv = SettingValues::make_request($this->u_chair, [
            "has_submission" => 1,
            "submission/1/id" => "R2",
            "submission/1/tag" => "R2",
            "submission/1/registration" => "@" . (Conf::$now + 20000),
            "submission/1/done" => "@" . (Conf::$now + 10000)
        ]);
        xassert(!$sv->execute());
        xassert_str_contains($sv->full_feedback_text(), "before");
    }

    function test_delete_round() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_submission" => 1,
            "submission/1/id" => "R2",
            "submission/1/delete" => "1"
        ]);
        xassert($sv->execute());
        xassert(!$this->conf->has_named_submission_rounds());
        xassert_eqq($this->conf->submission_round_by_tag("R2"), null);
        // papers formerly in R2 fall back to the unnamed round
        $p_estrin = $this->conf->checked_paper_by_id($this->pid_estrin);
        xassert($p_estrin->submission_round()->unnamed);
    }

    /** @param int $srf
     * @return list<string> */
    private function home_deadlines(Contact $user, $srf) {
        $sr = $this->conf->unnamed_submission_round();
        $home = new Home_Page($user);
        $f = Closure::bind(function ($sr, $srf) {
            $deadlines = [];
            $this->submission_round_deadlines($deadlines, $sr, $srf);
            return $deadlines;
        }, $home, Home_Page::class);
        return $f($sr, $srf);
    }

    /** @param ?int $soft
     * @param ?int $done */
    private function set_final_deadlines($soft, $done) {
        $this->conf->save_setting("final_open", Conf::$now - 1000);
        $this->conf->save_setting("final_soft", $soft);
        $this->conf->save_setting("final_done", $done);
        $this->conf->refresh_settings();
    }

    function test_final_deadline_display() {
        $conf = $this->conf;
        $soft = Conf::$now + 864000;
        $hard = Conf::$now + 1728000;

        // before the soft deadline, the soft deadline is shown
        $this->set_final_deadlines($soft, $hard);
        $sr = $conf->unnamed_submission_round();
        xassert_eqq($sr->final_deadline_for_display(), $soft);
        $dl = $this->home_deadlines($this->u_estrin, 4);
        xassert_eqq(count($dl), 1);
        xassert_str_contains($dl[0], "by " . $conf->unparse_time_with_local_span($soft));
        $sj = $this->u_estrin->status_json();
        xassert_eqq($sj->final->done, $soft);
        xassert(!isset($sj->final->ishard));

        // after the soft deadline, final versions are overdue
        $soft = Conf::$now - 86400;
        $this->set_final_deadlines($soft, $hard);
        $sr = $conf->unnamed_submission_round();
        xassert_eqq($sr->final_deadline_for_display(), $soft);
        $dl = $this->home_deadlines($this->u_estrin, 4);
        xassert_str_contains($dl[0], "overdue");
        xassert_str_contains($dl[0], "requested by " . $conf->unparse_time_with_local_span($soft));
        xassert_str_contains($dl[0], "required by " . $conf->unparse_time_with_local_span($hard));
        $sj = $this->u_estrin->status_json();
        xassert_eqq($sj->final->done, $hard);
        xassert_eqq($sj->final->ishard ?? null, true);

        // a passed soft deadline alone is overdue
        $this->set_final_deadlines($soft, null);
        $dl = $this->home_deadlines($this->u_estrin, 4);
        xassert_str_contains($dl[0], "overdue");
        xassert_str_contains($dl[0], "requested by " . $conf->unparse_time_with_local_span($soft));

        // with soft = hard, the hard deadline is shown
        $this->set_final_deadlines($hard, $hard);
        $sr = $conf->unnamed_submission_round();
        xassert_eqq($sr->final_deadline_for_display(), $hard);
        $sj = $this->u_estrin->status_json();
        xassert_eqq($sj->final->done, $hard);
        xassert_eqq($sj->final->ishard ?? null, true);

        $conf->save_setting("final_open", null);
        $this->set_final_deadlines(null, null);
        $conf->save_refresh_setting("final_open", null);
    }

    function test_submission_deadlines_without_deadline() {
        $conf = $this->conf;
        $sub_reg = $conf->setting("sub_reg");
        $sub_sub = $conf->setting("sub_sub");
        $conf->save_setting("sub_reg", null);
        $conf->save_refresh_setting("sub_sub", null);
        foreach ([1, 2, 3] as $srf) {
            foreach ($this->home_deadlines($this->u_estrin, $srf) as $dl) {
                xassert_not_str_contains($dl, "N/A");
                xassert_not_str_contains($dl, "You have until");
            }
        }
        $conf->save_setting("sub_reg", $sub_reg);
        $conf->save_refresh_setting("sub_sub", $sub_sub);
    }

    function test_external_reviewer_status_review_deadlines() {
        $conf = $this->conf;
        $conf->save_setting("rev_open", 1);
        $conf->save_setting("pcrev_soft", Conf::$now + 5000);
        $conf->save_setting("pcrev_hard", Conf::$now + 6000);
        $conf->save_setting("extrev_soft", null);
        $conf->refresh_settings();
        xassert($conf->time_review_open());
        xassert_assign($this->u_chair, "paper,action,user\n1,review,sclin@leland.stanford.edu\n");
        $u_ext = $conf->fresh_user_by_email("sclin@leland.stanford.edu");
        xassert(!$u_ext->isPC && $u_ext->is_reviewer() && !$u_ext->is_disabled());
        $sj = $u_ext->status_json();
        xassert_eqq($sj->revs["unnamed"]->done ?? null, Conf::$now + 5000);

        xassert_assign($this->u_chair, "paper,action,user\n1,clearreview,sclin@leland.stanford.edu\n");
        $conf->save_setting("pcrev_soft", null);
        $conf->save_setting("pcrev_hard", null);
        $conf->save_setting("rev_open", null);
        $conf->refresh_settings();
    }

    function test_closed_site_draft_message() {
        $conf = $this->conf;
        $ps = new PaperStatus($this->u_estrin);
        xassert($ps->save_paper_json((object) [
            "id" => "new", "title" => "Estrin draft", "abstract" => "Draft abstract.\n",
            "authors" => [["name" => "Deborah Estrin", "email" => "estrin@usc.edu"]],
            "status" => ["submitted" => false]
        ]));
        xassert_paper_status($ps);
        $pid = $ps->paperId;
        $sub_open = $conf->setting("sub_open");
        $sub_reg = $conf->setting("sub_reg");
        $sub_sub = $conf->setting("sub_sub");
        $conf->save_setting("sub_open", null);
        $conf->save_setting("sub_reg", null);
        $conf->save_refresh_setting("sub_sub", null);

        $prow = $this->u_estrin->checked_paper_by_id($pid);
        xassert($prow->timeSubmitted <= 0 && $prow->timeWithdrawn <= 0);
        xassert(!$this->u_estrin->can_edit_paper($prow));
        $qreq = TestQreq::get_page("paper/{$pid}", ["m" => "edit"])->set_user($this->u_estrin);
        $pt = new PaperTable($this->u_estrin, $qreq, $prow);
        $pt->set_edit_status(new PaperStatus($this->u_estrin), false);
        $f = Closure::bind(function () {
            ob_start();
            $this->_print_edit_messages(false);
            return ob_get_clean();
        }, $pt, PaperTable::class);
        $html = $f();
        xassert_str_contains($html, "not open for updates");
        xassert_not_str_contains($html, "submission deadline");

        $conf->save_setting("sub_open", $sub_open);
        $conf->save_setting("sub_reg", $sub_reg);
        $conf->save_refresh_setting("sub_sub", $sub_sub);
    }
}
