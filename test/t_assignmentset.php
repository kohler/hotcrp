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
}
