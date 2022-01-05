<?php
// assignmentcountset.php -- HotCRP helper classes for assignments
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AssignmentCount {
    /** @var int */
    public $ass = 0;
    /** @var int */
    public $rev = 0;
    /** @var int */
    public $meta = 0;
    /** @var int */
    public $pri = 0;
    /** @var int */
    public $sec = 0;
    /** @var int */
    public $lead = 0;
    /** @var int */
    public $shepherd = 0;

    function add(AssignmentCount $ct) {
        $this->ass += $ct->ass;
        $this->rev += $ct->rev;
        $this->meta += $ct->meta;
        $this->pri += $ct->pri;
        $this->sec += $ct->sec;
        $this->lead += $ct->lead;
        $this->shepherd += $ct->shepherd;
    }

    /** @param int $count
     * @param string $item
     * @param bool $plural
     * @param string $prefix
     * @param Contact $pc
     * @return string */
    static function unparse_count($count, $item, $plural, $prefix, $pc) {
        $t = $plural ? plural($count, $item) : $count . "&nbsp;" . $item;
        if ($count === 0) {
            return $t;
        } else {
            $url = $pc->conf->hoturl("search", "q=" . urlencode("$prefix:{$pc->email}"));
            return "<a class=\"q\" href=\"{$url}\">{$t}</a>";
        }
    }
    /** @param Contact $pc
     * @return string */
    function unparse_review_counts($pc) {
        $t = self::unparse_count($this->rev, "review", true, "re", $pc);
        $x = [];
        if ($this->meta !== 0) {
            $x[] = self::unparse_count($this->meta, "meta", false, "meta", $pc);
        }
        if ($this->pri !== $this->rev && (!$this->meta || $this->meta !== $this->rev)) {
            $x[] = self::unparse_count($this->pri, "primary", false, "pri", $pc);
        }
        if ($this->sec !== 0 && $this->sec !== $this->rev && $this->pri + $this->sec !== $this->rev) {
            $x[] = self::unparse_count($this->sec, "secondary", false, "sec", $pc);
        }
        if (!empty($x)) {
            $t .= " (" . join(", ", $x) . ")";
        }
        return $t;
    }
}

class AssignmentCountSet {
    /** @var Contact */
    public $user;
    /** @var array<int,AssignmentCount> */
    public $bypc = [];
    /** @var int */
    public $has = 0;
    const HAS_REVIEW = 1;
    const HAS_LEAD = 2;
    const HAS_SHEPHERD = 4;

    function __construct(Contact $user) {
        $this->user = $user;
    }

    /** @return AssignmentCount */
    function get($offset) {
        return $this->bypc[$offset] ?? new AssignmentCount;
    }
    /** @param int $offset
     * @return AssignmentCount */
    function ensure($offset) {
        if (!isset($this->bypc[$offset])) {
            $this->bypc[$offset] = new AssignmentCount;
        }
        return $this->bypc[$offset];
    }
    function add(AssignmentCountSet $acs) {
        foreach ($acs->bypc as $id => $ac) {
            $this->ensure($id)->add($ac);
        }
    }

    /** @param int $has
     * @return AssignmentCountSet */
    static function load(Contact $user, $has) {
        $acs = new AssignmentCountSet($user);
        $acs->has = $has;
        if ($user->privChair && !$user->conf->has_any_manager()) {
            $acs->easy_load();
        } else if ($has !== 0) {
            $acs->hard_load();
        }
        return $acs;
    }
    private function easy_load() {
        if ($this->has & self::HAS_REVIEW) {
            $result = $this->user->conf->qe("select r.contactId, group_concat(r.reviewType separator '')
                    from PaperReview r
                    join Paper p on (p.paperId=r.paperId)
                    where r.reviewType>=" . REVIEW_PC . " and (r.reviewSubmitted>0 or r.timeApprovalRequested!=0 or p.timeSubmitted>0)
                    group by r.contactId");
            while (($row = $result->fetch_row())) {
                $ct = $this->ensure((int) $row[0]);
                $ct->rev = strlen($row[1]);
                $ct->meta = substr_count($row[1], (string) REVIEW_META);
                $ct->pri = substr_count($row[1], (string) REVIEW_PRIMARY);
                $ct->sec = substr_count($row[1], (string) REVIEW_SECONDARY);
            }
            Dbl::free($result);
        }
        foreach (["lead", "shepherd"] as $k) {
            if ($this->has & ($k === "lead" ? self::HAS_LEAD : self::HAS_SHEPHERD)) {
                $result = $this->user->conf->qe("select {$k}ContactId, count(paperId)
                        from Paper where timeSubmitted>0
                        group by {$k}ContactId");
                while (($row = $result->fetch_row())) {
                    $ct = $this->ensure((int) $row[0]);
                    $ct->$k = (int) $row[1];
                }
                Dbl::free($result);
            }
        }
    }
    private function hard_load() {
        $opt = ["finalized" => true, "minimal" => true];
        if ($this->has & self::HAS_REVIEW) {
            $opt["reviewSignatures"] = true;
        }
        if ($this->has & self::HAS_LEAD) {
            $opt["leadContactId"] = true;
        }
        if ($this->has & self::HAS_SHEPHERD) {
            $opt["shepherdContactId"] = true;
        }
        $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $result = $this->user->conf->paper_result($opt, $this->user);
        while (($prow = PaperInfo::fetch($result, $this->user, $this->user->conf))) {
            if ($this->user->can_view_paper($prow)) {
                if (($this->has & self::HAS_REVIEW)
                    && $this->user->can_view_review_assignment($prow, null)
                    && $this->user->can_view_review_identity($prow, null)) {
                    foreach ($prow->all_reviews() as $rrow) {
                        if ($rrow->reviewType >= REVIEW_PC
                            && ($rrow->reviewStatus >= ReviewInfo::RS_ADOPTED || $prow->timeSubmitted > 0)
                            && $this->user->can_view_review_assignment($prow, $rrow)
                            && $this->user->can_view_review_identity($prow, $rrow)) {
                            $ct = $this->ensure($rrow->contactId);
                            $ct->rev += 1;
                            if ($rrow->reviewType === REVIEW_PRIMARY) {
                                $ct->pri += 1;
                            } else if ($rrow->reviewType === REVIEW_SECONDARY) {
                                $ct->sec += 1;
                            } else if ($rrow->reviewType === REVIEW_META) {
                                $ct->meta += 1;
                            }
                        }
                    }
                }
                if (($this->has & self::HAS_LEAD)
                    && $prow->leadContactId > 0
                    && $prow->timeSubmitted > 0) {
                    $this->ensure($prow->leadContactId)->lead += 1;
                }
                if (($this->has & self::HAS_SHEPHERD)
                    && $prow->shepherdContactId > 0
                    && $prow->timeSubmitted > 0) {
                    $this->ensure($prow->shepherdContactId)->shepherd += 1;
                }
            }
        }
        Dbl::free($result);
    }

    /** @param Contact $pc
     * @param string $prefix
     * @return string */
    function unparse_counts_for($pc, $prefix = "") {
        $data = [];
        $ct = $this->get($pc->contactId);
        if ($this->has & self::HAS_REVIEW) {
            $data[] = $ct->unparse_review_counts($pc);
        }
        if ($this->has & self::HAS_LEAD) {
            $data[] = AssignmentCount::unparse_count($ct->lead, "lead", true, "lead", $pc);
        }
        if ($this->has & self::HAS_SHEPHERD) {
            $data[] = AssignmentCount::unparse_count($ct->shepherd, "shepherd", true, "shepherd", $pc);
        }
        return '<span class="pcrevsum">' . $prefix . join(", ", $data) . "</span>";
    }
}
