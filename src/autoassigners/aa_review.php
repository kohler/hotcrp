<?php
// autoassigners/aa_review.php -- HotCRP helper classes for autoassignment
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Review_Autoassigner extends Autoassigner {
    /** @var int */
    private $rtype;
    /** @var int */
    private $count;
    /** @var 1|2|3 */
    private $kind;
    /** @var bool */
    private $adjust = false;
    /** @var 1|2|3 */
    private $load;
    /** @var ?int */
    private $round;

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

        if ($gj->name === "review_per_user" || $gj->name === "review_per_pc") {
            $this->kind = self::KIND_PER_USER;
        } else if ($gj->name === "review_ensure") {
            $this->kind = self::KIND_ENSURE;
        } else if ($gj->name === "review_adjust") {
            $this->kind = self::KIND_ENSURE;
            $this->adjust = true;
        } else {
            $this->kind = self::KIND_ADD;
        }

        $t = $gj->rtype ?? $subreq["rtype"] ?? "primary";
        if (($rtype = ReviewInfo::parse_type($t, true))) {
            $this->rtype = $rtype;
        } else {
            $this->error_at("rtype", "<0>Unknown review type ‘{$t}’");
            $this->rtype = REVIEW_PRIMARY;
        }
        $this->set_assignment_action(ReviewInfo::unparse_assigner_action($this->rtype));

        $roundname = trim($subreq["round"] ?? "");
        if ($roundname === "") {
            $roundname = $this->conf->assignment_round_option($this->rtype);
        }
        $this->round = $this->conf->round_number($roundname);
        if ($this->round === null) {
            $e = Conf::round_name_error($roundname);
            $this->error_at("round", $e ? "<0>{$e}" : "<0>Review round ‘{$roundname}’ does not exist");
        } else {
            $this->set_assignment_column("round", $roundname);
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
            $q .= $this->round === null ? " and false" : " and reviewRound={$this->round}";
        }
        $result = $this->conf->qe($q . " group by contactId", $this->user_ids());
        while (($row = $result->fetch_row())) {
            $this->add_aauser_load((int) $row[0], (int) $row[1]);
            if ($this->balance === self::BALANCE_ALL) {
                $this->add_aauser_balance((int) $row[0], (int) $row[1]);
            }
        }
        Dbl::free($result);
    }

    /** @param PaperInfo $prow */
    protected function load_paper_reviews($prow) {
        foreach ($prow->reviews_as_list() as $rrow) {
            if ($this->kind === self::KIND_ENSURE
                && $rrow->reviewType === $this->rtype) {
                if ($this->adjust
                    && $rrow->reviewRound === $this->round
                    && $rrow->reviewStatus < ReviewInfo::RS_DRAFTED) {
                    $eass = self::ENEWASSIGN;
                } else {
                    $eass = self::EOLDASSIGN;
                }
            } else {
                $eass = self::EOTHERASSIGN;
            }
            $this->load_assignment($rrow->contactId, $prow->paperId, $eass);
            if ($eass === self::ENEWASSIGN
                && $this->balance === self::BALANCE_NEW) {
                $this->add_aauser_balance($rrow->contactId, 1);
            }
        }
    }

    function run() {
        $this->load_review_preferences($this->rtype);
        $this->set_load();

        if ($this->kind === self::KIND_PER_USER) {
            foreach ($this->aausers() as $ac) {
                $ac->ndesired = $this->count;
            }
            $count = ceil((count($this->aausers()) * ($this->count + 2))
                          / max(count($this->aapapers()), 1));
            foreach ($this->aapapers() as $ap) {
                $ap->ndesired = $count;
                $ap->balance = true;
            }
        } else if ($this->kind === self::KIND_ENSURE) {
            foreach ($this->aapapers() as $ap) {
                $ap->ndesired = max($this->count - $ap->assignment_count(self::EOLDASSIGN), 0);
            }
        } else {
            foreach ($this->aapapers() as $ap) {
                $ap->ndesired = $this->count;
            }
        }
        $this->reset_desired_assignment_count();
        gc_collect_cycles();

        $this->assign_method();
        $this->finish_assignment(); // recover memory
    }
}
