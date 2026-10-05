<?php
// t_assignmentset.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class AssignmentSet_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mgbaker; // pc
    /** @var Contact
     * @readonly */
    public $u_puneet; // author of #1

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_puneet = $conf->checked_user_by_email("puneet@catarina.usc.edu");
    }

    /** @return bool */
    private function too_complex(AssignmentSet $aset) {
        foreach ($aset->message_list() as $mi) {
            if (str_contains($mi->message, "too long or complex"))
                return true;
        }
        return false;
    }

    function test_invisible_paper_answers_like_missing_paper() {
        // assignment errors don't reveal whether an invisible paper exists
        $u = $this->u_puneet;
        $p2 = $this->conf->checked_paper_by_id(2);
        xassert(!$u->can_view_paper($p2));
        xassert(!$this->conf->paper_by_id(9999));
        $answer = function ($pid, $row) use ($u) {
            $aset = new AssignmentSet($u);
            $aset->parse("paper,action,user,tag,decision\n{$pid},{$row}\n");
            $msgs = [];
            foreach ($aset->message_list() as $mi) {
                $msgs[] = str_replace("#{$pid}", "#N", $mi->message);
            }
            return [$aset->has_error(), $msgs];
        };
        foreach (["tag,,fart", "decision,,,,accept", "follow,{$u->email}",
                  "lead,{$this->u_mgbaker->email}", "pref,{$u->email}",
                  "conflict,{$this->u_mgbaker->email}", "review,{$this->u_mgbaker->email}"] as $row) {
            xassert_eqq($answer(2, $row), $answer(9999, $row), $row);
        }
    }

    function test_any_user_skips_others_for_nonmanagers() {
        // `follow,any` acts on whom the user may change, and doesn't reveal
        // whether others follow
        $u = $this->u_mgbaker;
        $p3 = $this->conf->checked_paper_by_id(3);
        xassert($u->can_view_paper($p3) && !$u->can_manage($p3));
        $others = [$this->u_chair->contactId, $this->u_puneet->contactId];
        $this->conf->qe("delete from PaperWatch where paperId=3 and contactId?a", $others);
        // (a search paper field, like `#3`, reports differently from an ID)
        $answer = function ($paper) use ($u) {
            $aset = new AssignmentSet($u);
            $aset->parse("paper,action,user,following\n{$paper},follow,any,clear\n");
            return [$aset->has_error(), $aset->full_feedback_text()];
        };
        $alone = [$answer("3"), $answer("#3")];
        xassert(!$alone[0][0]);
        xassert(!$alone[1][0]);
        $this->conf->qe("insert into PaperWatch (paperId,contactId,watch) values (3,?,3),(3,?,3)", ...$others);
        xassert_eqq([$answer("3"), $answer("#3")], $alone);
        $aset = new AssignmentSet($u);
        xassert($aset->parse("paper,action,user,following\n3,follow,any,clear\n") && $aset->execute());
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperWatch where paperId=3 and contactId?a and watch!=0", $others), 2);
        $this->conf->qe("delete from PaperWatch where paperId=3 and contactId?a", $others);
    }

    function test_budget_applies_to_nonmanagers() {
        xassert_eqq((new AssignmentSet($this->u_chair))->max_cost(), null);
        xassert_eqq((new AssignmentSet($this->conf->root_user()))->max_cost(), null);
        xassert(!$this->u_mgbaker->is_manager());
        xassert_eqq((new AssignmentSet($this->u_mgbaker))->max_cost(), AssignmentSet::MAX_COST_PC);
        xassert_eqq((new AssignmentSet($this->u_puneet))->max_cost(), AssignmentSet::MAX_COST_PC);
    }

    function test_preference_rows_are_cheap() {
        // a PC member's preferences for every paper stay far under budget
        $pids = $this->u_mgbaker->paper_set(["where" => "timeSubmitted>0"])->paper_ids();
        xassert_gt(count($pids), 10);
        $rows = ["paper,action,email,preference"];
        foreach ($pids as $pid) {
            $rows[] = "{$pid},pref,mgbaker@cs.stanford.edu," . ($pid % 4 - 1);
        }
        $aset = (new AssignmentSet($this->u_mgbaker))->set_override_conflicts(true);
        $aset->parse(join("\n", $rows));
        xassert(!$this->too_complex($aset));
        xassert_le($aset->cost(), 6 * count($pids));
    }

    function test_distinct_search_cells_exhaust_budget() {
        // every distinct non-numeric paper cell runs a search
        $n = intdiv(AssignmentSet::MAX_COST_PC, AssignmentState::COST_SEARCH_MIN) + 10;
        $rows = ["paper,action,tag"];
        for ($i = 0; $i < $n; ++$i) {
            $rows[] = "\"1 OR ti:budgetprobe{$i}\",tag,budgetprobe";
        }
        $aset = new AssignmentSet($this->u_mgbaker);
        $aset->parse(join("\n", $rows));
        xassert($this->too_complex($aset));
        // searches are charged by time, so the overshoot is one search
        xassert_lt($aset->cost(), 2 * AssignmentSet::MAX_COST_PC);
        xassert(!$aset->execute());
        xassert_search($this->u_chair, "#budgetprobe", "");

        // the assign API reports the same failure
        $j = call_api("=assign", $this->u_mgbaker, TestQreq::post(["assignments" => join("\n", $rows)]));
        xassert(!$j->ok);
        xassert($this->too_complex($aset));
        xassert_search($this->u_chair, "#budgetprobe", "");
    }

    function test_wildcard_tag_removal_budget() {
        // each wildcard piece scans the paper's tag items
        $rows = ["paper,action,tag"];
        $pieces = [];
        for ($i = 0; $i < 200; ++$i) {
            $pieces[] = "budgetpiece{$i}";
        }
        $rows[] = "2,tag,\"" . join(" ", $pieces) . "\"";
        $wild = join(" ", array_fill(0, 20, "budgetq*"));
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = "2,cleartag,\"{$wild}\"";
        }
        $csv = join("\n", $rows);

        $aset = new AssignmentSet($this->u_mgbaker);
        $aset->parse($csv);
        xassert($this->too_complex($aset));
        xassert(!$aset->execute());
        xassert_search($this->u_chair, "#budgetpiece0", "");

        // the same request succeeds without a budget
        $aset = (new AssignmentSet($this->u_mgbaker))->set_max_cost(null);
        $aset->parse($csv);
        xassert(!$this->too_complex($aset));
        xassert_gt($aset->cost(), AssignmentSet::MAX_COST_PC);
    }

    function test_any_user_expansion_budget() {
        // each `any` row expands to every contact added earlier
        xassert($this->conf->checked_paper_by_id(1)->has_author($this->u_puneet));
        $rows = ["paper,action,email"];
        for ($i = 0; $i < 300; ++$i) {
            $rows[] = "1,contact,budget{$i}@budget.example";
        }
        for ($i = 0; $i < 200; ++$i) {
            $rows[] = "1,clearcontact,any";
        }
        $aset = new AssignmentSet($this->u_puneet);
        $aset->parse(join("\n", $rows));
        xassert($this->too_complex($aset));
        xassert(!$aset->execute());
        xassert(!$this->conf->fresh_user_by_email("budget0@budget.example"));
    }

    function test_pc_name_resolution_budget() {
        // a user named by partial name is searched for among the PC
        $rows = ["paper,action,user,preference"];
        for ($i = 0; $i < 100; ++$i) {
            $rows[] = "1,pref,Mary,1";
        }
        $aset = (new AssignmentSet($this->u_mgbaker))->set_max_cost(500);
        $aset->parse(join("\n", $rows));
        xassert($this->too_complex($aset));
        xassert(!$aset->execute());
    }

    function test_disallowed_user_error_names_matched_user() {
        // the error names the user who matched, not the CSV's text, and
        // does not call a non-PC user a PC member
        $email = "nofollow@_.com";
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $us = new UserStatus($this->conf->root_user());
        $u = $us->save_user((object) ["email" => $email, "name" => "Nora Follow"]);
        xassert(!!$u, $us->full_feedback_text());
        xassert(!$u->can_view_paper($this->conf->checked_paper_by_id(1)));

        $aset = new AssignmentSet($this->u_chair);
        $aset->parse("paper,action,email,name\n1,follow,{$email},Someone Else\n");
        $t = $aset->full_feedback_text();
        xassert_str_contains($t, "Nora Follow <{$email}> cannot be assigned to #1");
        xassert_not_str_contains($t, "Someone Else");
        xassert_not_str_contains($t, "PC member");

        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users");
    }
}
