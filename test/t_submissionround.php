<?php
// t_submissionround.php -- HotCRP tests for named submission rounds/classes
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb("fresh")]
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
        $conf->save_setting("sub_update", Conf::$now + 10000);
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

    function test_round_deadline_behavior() {
        $sr = $this->conf->submission_round_by_tag("R2");
        // both deadlines are in the future: registration and submission open
        xassert($sr->time_register(false));
        xassert($sr->time_submit(false));
        xassert($sr->time_update(false));

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
}
