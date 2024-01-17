<?php
// autoassigners/aa_review.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Review_Autoassigner extends Autoassigner {
    /** @var int */
    private $rtype;
    /** @var int */
    private $count;
    /** @var 1|2|3 */
    private $kind;
    /** @var 1|2|3 */
    private $load;

    const KIND_ENSURE = 1;
    const KIND_ADD = 2;
    const KIND_PER_USER = 3;

    const LOAD_ALL = 1;
    const LOAD_RTYPE = 2;
    const LOAD_ROUND = 3;

    /** @param ?list<int> $pcids
     * @param list<int> $papersel
     * @param array<string,mixed> $subreq
     * @param object $gj */
    function __construct(Contact $user, $pcids, $papersel, $subreq, $gj) {
        parent::__construct($user, $pcids, $papersel);
        $t = $gj->rtype ?? $subreq["rtype"] ?? "primary";
        if (($rtype = ReviewInfo::parse_type($t, true))) {
            $this->rtype = $rtype;
        } else {
            $this->error_at("rtype", "<0>Review type not found");
            $this->rtype = REVIEW_PRIMARY;
        }
        $this->set_assignment_action(ReviewInfo::unparse_assigner_action($this->rtype));

        if (($round = $subreq["round"] ?? null) !== null && $round !== "") {
            $round = trim($round);
            if (($err = Conf::round_name_error($round))) {
                $this->error_at("round", "<0>{$err}");
            } else {
                $this->set_assignment_column("round", $round);
            }
        }
        $this->set_computed_assignment_column("preference");
        $this->set_computed_assignment_column("topic_score");

        $t = $subreq["load"] ?? "all";
        if ($t === "all") {
            $this->load = self::LOAD_ALL;
        } else if ($t === "rtype") {
            $this->load = self::LOAD_RTYPE;
        } else if ($t === "round") {
            $this->load = self::LOAD_ROUND;
        }

        $this->extract_balance_method($subreq);
        $this->extract_max_load($subreq);
        $this->extract_gadget_costs($subreq);

        $n = stoi($subreq["count"] ?? $gj->count ?? 1) ?? -1;
        if ($n <= 0) {
            $this->error_at("count", "<0>Positive number expected");
            $n = 1;
        }
        $this->count = $n;

        if ($gj->name === "review_per_user" || $gj->name === "review_per_pc") {
            $this->kind = self::KIND_PER_USER;
        } else if ($gj->name === "review_ensure") {
            $this->kind = self::KIND_ENSURE;
        } else {
            $this->kind = self::KIND_ADD;
        }
    }

    function incompletely_assigned_paper_ids() {
        if ($this->kind === self::KIND_PER_USER) {
            return [];
        }
        return parent::incompletely_assigned_paper_ids();
    }

    private function set_load() {
        $q = "select contactId, count(reviewId) from PaperReview where contactId?a";
        if ($this->load === self::LOAD_RTYPE) {
            $q .= " and reviewType={$this->rtype}";
        } else {
            $q .= " and (reviewType!=" . REVIEW_PC . " or requestedBy!=contactId)";
        }
        if ($this->load === self::LOAD_ROUND) {
            $rname = $this->assignment_column("round")
                ?? $this->conf->assignment_round_option($this->rtype === REVIEW_EXTERNAL);
            $round = $this->conf->round_number($rname);
            if ($round !== null) {
                $q .= " and reviewRound={$round}";
            } else {
                $q .= " and false";
            }
        }
        $result = $this->conf->qe($q . " group by contactId", $this->user_ids());
        while (($row = $result->fetch_row())) {
            $this->add_aauser_load((int) $row[0], (int) $row[1]);
        }
        Dbl::free($result);
    }

    private function set_ensure() {
        $result = $this->conf->qe("select paperId, count(reviewId) from PaperReview where reviewType={$this->rtype} group by paperId");
        while (($row = $result->fetch_row())) {
            if (($ap = $this->aapaper((int) $row[0]))) {
                $ap->ndesired = max($ap->ndesired - (int) $row[1], 0);
            }
        }
        Dbl::free($result);
    }

    function run() {
        $this->load_review_preferences($this->rtype);
        $this->set_load();

        $count = $this->count;
        if ($this->kind === self::KIND_PER_USER) {
            foreach ($this->aausers() as $ac) {
                $ac->ndesired = $count;
            }
            $count = ceil((count($this->aausers()) * ($count + 2)) / max(count($this->aapapers()), 1));
        }
        foreach ($this->aapapers() as $ap) {
            $ap->ndesired = $count;
        }
        if ($this->kind === self::KIND_ENSURE) {
            $this->set_ensure();
        }
        $this->reset_desired_assignment_count();
        gc_collect_cycles();

        $this->assign_method();
        $this->finish_assignment(); // recover memory
    }
}
