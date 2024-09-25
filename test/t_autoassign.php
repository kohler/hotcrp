<?php
// t_autoassign.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Autoassign_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var list<int> */
    public $pcc = [];
    /** @var array<int,array<int,true>> */
    public $cflts = [];
    /** @var list<int> */
    public $cur_pids;
    /** @var list<int> */
    public $cur_pcc;
    /** @var list<array{int,int,int}> */
    public $cur_pref;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        foreach ($this->conf->pc_members() as $pc) {
            if (!$pc->privChair)
                $this->pcc[] = $pc->contactId;
        }
        $this->cflts = array_fill(1, 10, []);
        foreach ($this->conf->paper_set(["paperId" => range(1, 10)]) as $prow) {
            foreach ($prow->conflict_list() as $cflt) {
                if (in_array($cflt->contactId, $this->pcc))
                    $this->cflts[$prow->paperId][$cflt->contactId] = true;
            }
        }
        assert(count($this->pcc) === 17);
    }

    function setup() {
        $this->conf->qe("delete from PaperReview");
        $this->conf->qe("delete from PaperReviewHistory");
        $this->conf->qe("delete from PaperReviewRefused");
        $this->conf->qe("delete from PaperReviewPreference");
    }

    function setup_10x10_pref3() {
        $this->setup();
        $this->cur_pids = range(1, 10);
        $this->cur_pcc = array_slice($this->pcc, 0, 10);
        do {
            $qv = [];
            $pcx = array_merge($this->cur_pcc, $this->cur_pcc, $this->cur_pcc);
            $npcx = count($pcx) - 1;
            foreach ($this->cur_pids as $pid) {
                $x = [];
                for ($i = 0; $i !== 3; ++$i) {
                    $nt = 0;
                    do {
                        $pci = mt_rand(0, $npcx);
                        $uid = $pcx[$pci];
                        if (++$nt === 100) {
                            goto retry;
                        }
                    } while (in_array($uid, $x) || isset($this->cflts[$pid][$uid]));
                    $x[] = $uid;
                    $qv[] = [$pid, $uid, 10];
                    $pcx[$pci] = $pcx[$npcx];
                    --$npcx;
                }
            }
            retry: ;
        } while (count($qv) < 3 * count($this->cur_pids));
        $this->conf->qe("insert into PaperReviewPreference (paperId,contactId,preference) values ?v", $qv);
        $this->cur_pref = $qv;
    }

    /** @return Autoassigner */
    function autoassigner($aaname, $pcc, $pids, $param) {
        $gj = $this->conf->autoassigner($aaname);
        if (str_starts_with($gj->function, "+")) {
            $class = substr($gj->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $aa = new $class($this->user, $pcc, $pids, $param, $gj);
        } else {
            $aa = call_user_func($gj->function, $this->user, $pcc, $pids, $param, $gj);
        }
        '@phan-var-force Autoassigner $aa';
        return $aa;
    }

    function xassert_run_autoassigner(Autoassigner $aa) {
        $aa->run();
        if ($aa->has_message()) {
            Xassert::print_landmark();
            fwrite(STDERR, preg_replace('/^/m', "  ", $aa->full_feedback_text()));
        }
        xassert($aa->has_assignment());
        xassert_assign($this->user, join("", $aa->assignments()), true);
    }

    /** @return Autoassigner */
    function xassert_autoassigner($aaname, $pcc, $pids, $param) {
        $aa = $this->autoassigner($aaname, $pcc, $pids, $param);
        $this->xassert_run_autoassigner($aa);
        return $aa;
    }

    /** @param PaperInfoSet|list<int> $prows
     * @return array<int,list<int>> */
    function reviews_by_user($prows) {
        $prows = is_array($prows) ? $this->conf->paper_set(["paperId" => $prows]) : $prows;
        $rbu = array_fill_keys($this->pcc, []);
        foreach ($prows as $prow) {
            foreach ($prow->reviews_as_list() as $rinfo) {
                $rbu[$rinfo->contactId][] = $prow->paperId;
            }
        }
        return $rbu;
    }

    function test_add_reviews_initial() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review", $this->cur_pcc, $this->cur_pids, ["count" => 3]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 3);
        }
        foreach ($this->cur_pref as $ass) {
            xassert_neqq($prows->get($ass[0])->review_by_user($ass[1]), null);
        }
    }

    function test_add_reviews_balance_all() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $this->xassert_autoassigner("review", $this->pcc, $this->cur_pids, ["count" => 3, "balance" => "all"]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 6);
        }

        $rbu = $this->reviews_by_user($prows);
        foreach ($this->pcc as $idx => $uid) {
            xassert_le(count($rbu[$uid]), 4);
        }
    }

    function test_add_reviews_balance_new() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $this->xassert_autoassigner("review", $this->pcc, $this->cur_pids, ["count" => 3, "balance" => "new"]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 6);
        }

        $rbu = $this->reviews_by_user($prows);
        foreach ($this->pcc as $idx => $uid) {
            xassert_le(count($rbu[$uid]), $idx < 10 ? 5 : 2);
        }
    }

    function test_ensure_reviews_initial() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review_ensure", $this->cur_pcc, $this->cur_pids, ["count" => 3]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 3);
        }
        foreach ($this->cur_pref as $ass) {
            xassert_neqq($prows->get($ass[0])->review_by_user($ass[1]), null);
        }
    }

    function test_ensure_reviews_balance_all() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review_ensure", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $pids = range(1, 15);
        $this->xassert_autoassigner("review_ensure", $this->pcc, $pids, ["count" => 5, "balance" => "all"]);

        $prows = $this->conf->paper_set(["paperId" => $pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 5);
        }

        $rbu = $this->reviews_by_user($prows);
        foreach ($this->pcc as $idx => $uid) {
            xassert_le(count($rbu[$uid]), 5);
        }
    }

    function test_ensure_reviews_balance_new() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review_ensure", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $pids = range(1, 15);
        $this->xassert_autoassigner("review_ensure", $this->pcc, $pids, ["count" => 5, "balance" => "new"]);

        $prows = $this->conf->paper_set(["paperId" => $pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 5);
        }

        $rbu = $this->reviews_by_user($prows);
        foreach ($this->pcc as $idx => $uid) {
            xassert_le(count($rbu[$uid]), $idx < 10 ? 6 : 3);
        }
    }

    function test_ensure_reviews_per_user() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review_per_pc", $this->pcc, $this->cur_pids, ["count" => 3]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_le(count($prow->reviews_as_list()), 6);
        }

        $rbu = $this->reviews_by_user($prows);
        foreach ($this->pcc as $idx => $uid) {
            xassert_eq(count($rbu[$uid]), 3);
        }
    }

    function test_avoid_coassignment_baseline()  {
        foreach ($this->conf->paper_set(["paperId" => [1, 2, 3]]) as $prow) {
            Xassert::$context = "pid {$prow->paperId}";
            xassert_eqq($prow->conflict_type($this->pcc[0]), 0);
            xassert_eqq($prow->conflict_type($this->pcc[2]), 0);
            xassert_eqq($prow->conflict_type($this->pcc[3]), 0);
            Xassert::$context = null;
        }

        $this->setup();
        $qv = [[1, $this->pcc[0], 10], [2, $this->pcc[0], 20],
               [1, $this->pcc[2], 20], [2, $this->pcc[2], 10]];
        $this->conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ?v", $qv);
        $this->cur_pcc = [$this->pcc[0], $this->pcc[2], $this->pcc[3]];
        $aa = $this->xassert_autoassigner("review_ensure", $this->cur_pcc, [1, 2, 3], ["count" => 3]);
        $rbu = $this->reviews_by_user([1, 2, 3]);
        xassert(in_array(1, $rbu[$this->pcc[0]]));
        xassert(in_array(2, $rbu[$this->pcc[0]]));
        xassert(in_array(1, $rbu[$this->pcc[2]]));
        xassert(in_array(2, $rbu[$this->pcc[2]]));
        $pids = $aa->incompletely_assigned_paper_ids();
        xassert_eqq($pids, []);
    }

    function test_avoid_coassignment_active()  {
        $this->setup();
        $qv = [[1, $this->pcc[0], 10], [2, $this->pcc[0], 20],
               [1, $this->pcc[2], 20], [2, $this->pcc[2], 10]];
        $this->conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ?v", $qv);
        $this->cur_pcc = [$this->pcc[0], $this->pcc[2], $this->pcc[3]];
        $aa = $this->autoassigner("review_ensure", $this->cur_pcc, [1, 2, 3], ["count" => 3]);
        $aa->avoid_coassignment($this->pcc[0], $this->pcc[2]);
        $this->xassert_run_autoassigner($aa);
        $prows = $this->conf->paper_set(["paperId" => [1, 2, 3]]);
        $rbu = $this->reviews_by_user($prows);
        xassert(!in_array(1, $rbu[$this->pcc[0]]));
        xassert(in_array(2, $rbu[$this->pcc[0]]));
        xassert(in_array(1, $rbu[$this->pcc[2]]));
        xassert(!in_array(2, $rbu[$this->pcc[2]]));
        xassert(in_array(3, $rbu[$this->pcc[0]]) === !in_array(3, $rbu[$this->pcc[2]]));
        $pids = $aa->incompletely_assigned_paper_ids();
        xassert_eqq($pids, [1, 2, 3]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 2);
        }
    }

    function test_avoid_coassignment_active_expertise()  {
        $this->setup();
        $qv = [[1, $this->pcc[0], 0, 0], [2, $this->pcc[0], 0, 1],
               [1, $this->pcc[2], 0, 1], [2, $this->pcc[2], 0, 0]];
        $this->conf->qe("insert into PaperReviewPreference (paperId, contactId, preference, expertise) values ?v", $qv);
        $this->cur_pcc = [$this->pcc[0], $this->pcc[2], $this->pcc[3]];
        $aa = $this->autoassigner("review_ensure", $this->cur_pcc, [1, 2, 3], ["count" => 3, "expertise" => true]);
        $aa->avoid_coassignment($this->pcc[0], $this->pcc[2]);
        $this->xassert_run_autoassigner($aa);
        $prows = $this->conf->paper_set(["paperId" => [1, 2, 3]]);
        $rbu = $this->reviews_by_user($prows);
        xassert(!in_array(1, $rbu[$this->pcc[0]]));
        xassert(in_array(2, $rbu[$this->pcc[0]]));
        xassert(in_array(1, $rbu[$this->pcc[2]]));
        xassert(!in_array(2, $rbu[$this->pcc[2]]));
        xassert(in_array(3, $rbu[$this->pcc[0]]) === !in_array(3, $rbu[$this->pcc[2]]));
        $pids = $aa->incompletely_assigned_paper_ids();
        xassert_eqq($pids, [1, 2, 3]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 2);
        }
    }

    function test_adjust_reviews_normal() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $rbu1 = $this->reviews_by_user($this->cur_pids);

        $aa = $this->xassert_autoassigner("review_adjust", $this->pcc, $this->cur_pids, ["count" => 5, "balance" => "new"]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 5);
        }

        // preserves all previous assignments
        $rbu2 = $this->reviews_by_user($prows);
        foreach ($this->pcc as $uid) {
            xassert_eqq(array_diff($rbu1[$uid] ?? [], $rbu2[$uid]), []);
            xassert_le(count($rbu2[$uid]), 4);
        }
    }

    function test_adjust_reviews_repel() {
        $this->setup_10x10_pref3();
        $this->xassert_autoassigner("review", $this->cur_pcc, $this->cur_pids, ["count" => 3]);
        $rbu1 = $this->reviews_by_user($this->cur_pids);

        $aa = $this->xassert_autoassigner("review_adjust", $this->pcc, $this->cur_pids, ["count" => 5, "balance" => "new", "remove_assignment_cost" => -300]);

        $prows = $this->conf->paper_set(["paperId" => $this->cur_pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 5);
        }

        // preserves all previous assignments
        $rbu2 = $this->reviews_by_user($prows);
        foreach ($this->pcc as $uid) {
            if (isset($rbu1[$uid])) {
                xassert_eqq(array_diff($rbu1[$uid], $rbu2[$uid]), $rbu1[$uid]);
            }
            xassert_le(count($rbu2[$uid]), 4);
        }
    }

    function test_adjust_reviews_reassign() {
        $this->setup_10x10_pref3();
        $pids = range(1, 15);
        $this->xassert_autoassigner("review", $this->pcc, $pids, ["count" => 3]);

        $prows = $this->conf->paper_set(["paperId" => $pids]);
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 3);
        }
        $rbu1 = $this->reviews_by_user($prows);
        foreach ($rbu1 as $px) {
            xassert_in_eqq(count($px), [2, 3]);
        }
        $repcc = [$this->pcc[10], $this->pcc[11], $this->pcc[12]];

        $qv = [];
        foreach ($repcc as $cid) {
            foreach ($rbu1[$cid] as $pid) {
                $qv[] = [$pid, $cid, -10];
            }
        }
        $this->conf->qe("insert into PaperReviewPreference (paperId, contactId, preference) values ?v", $qv);
        $this->conf->qe("delete from PaperReview where contactId?a", $repcc);

        $this->xassert_autoassigner("review_adjust", $this->pcc, $pids, ["count" => 3]);

        $prows = $this->conf->paper_set(["paperId" => $pids]);
        $x = [];
        foreach ($prows as $prow) {
            xassert_eqq(count($prow->reviews_as_list()), 3);
            foreach ($prow->reviews_as_list() as $rr) {
                $x[$prow->paperId][] = $rr->contactId;
            }
        }
        $rbu2 = $this->reviews_by_user($prows);
        foreach ($rbu2 as $px) {
            xassert_in_eqq(count($px), [2, 3]);
        }
    }
}
