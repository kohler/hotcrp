<?php
// autoassigners/aa_paperpc.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperPC_Autoassigner extends Autoassigner {
    /** @var ?ReviewField */
    private $rf;
    /** @var -1|1 */
    private $rfdir;
    /** @var bool */
    private $allow_incomplete = false;

    /** @param ?list<int> $pcids
     * @param list<int> $papersel
     * @param array<string,mixed> $subreq
     * @param object $gj */
    function __construct(Contact $user, $pcids, $papersel, $subreq, $gj) {
        parent::__construct($user, $pcids, $papersel);
        $t = $gj->name ?? null;
        if ($t !== "lead" && $t !== "shepherd") {
            $this->error_at("type", "<0>Expected ‘lead’ or ‘shepherd’");
            $t = "lead";
        }
        $this->set_assignment_action($t);
        $this->extract_balance_method($subreq);
        $this->extract_max_load($subreq);
        $this->extract_gadget_costs($subreq);

        $this->allow_incomplete = $subreq["allow_incomplete"] ?? false;

        $s = $subreq["score"] ?? "random";
        if ($s === "" || $s === "random") {
            // nothing
        } else if ($s === "allow_incomplete") {
            $this->allow_incomplete = true;
        } else {
            if ($s[0] !== "-" && $s[0] !== "+") {
                $s = "+" . $s;
            }
            $this->rf = $user->conf->find_review_field(substr($s, 1));
            if (!$this->rf) {
                $this->error_at("score", "<0>Review field not found");
            } else if (!$this->rf->is_sfield) {
                $this->error_at("score", "<0>Textual review fields not supported");
            } else {
                $this->rfdir = $s[0] === "+" ? 1 : -1;
            }
        }
    }

    private function set_load() {
        $q = "select {$this->ass_action}ContactId, count(paperId) from Paper where paperId ?A group by {$this->ass_action}ContactId";
        $result = $this->conf->qe($q, $this->paper_ids());
        while (($row = $result->fetch_row())) {
            $this->add_aauser_load((int) $row[0], (int) $row[1]);
        }
        Dbl::free($result);
    }

    private function load_preferences() {
        $time = microtime(true);
        $this->make_elements();

        $set = $this->conf->paper_set(["paperId" => $this->paper_ids(), "allConflictType" => true, "reviewSignatures" => true, "scores" => $this->rf ? [$this->rf] : []]);

        $scorearr = [];
        foreach ($set as $prow) {
            if ($this->rf) {
                $prow->ensure_review_field_order($this->rf->order);
            }
            foreach ($this->aausers() as $cid => $ac) {
                if (!$prow->has_conflict($cid)
                    && ($rrow = $prow->review_by_user($cid))
                    && ($this->allow_incomplete
                        || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)) {
                    $s = $this->rf ? $rrow->fval($this->rf) ?? 0 : 1;
                    if ($s <= 0) {
                        $s = -1;
                    } else if ($this->rfdir === -1) {
                        $s = max(1000 - $s, 1);
                    }
                } else {
                    $s = -1;
                }
                $scorearr[$prow->paperId][$cid] = $s;
            }
        }

        foreach ($scorearr as $pid => $carr) {
            $extreme = max($carr);
            foreach ($carr as $cid => $s) {
                $ae = $this->ae($cid, $pid);
                if ($s < 0) {
                    $ae->eass = self::ENOASSIGN;
                } else {
                    $ae->cpref = max(0, $s - $extreme + 2);
                }
            }
        }

        $this->compute_pref_index();
        $this->profile["preferences"] = microtime(true) - $time;
    }

    function run() {
        $this->set_load();
        $result = $this->conf->qe("select paperId from Paper where {$this->ass_action}ContactId=0");
        while (($row = $result->fetch_row())) {
            $this->set_aapaper_ndesired((int) $row[0], 1);
        }
        Dbl::free($result);
        $this->load_preferences();
        $this->assign_method();
        $this->finish_assignment(); // recover memory
    }
}
