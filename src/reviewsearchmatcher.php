<?php
// reviewsearchmatcher.php -- HotCRP helper class for searching for reviews
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewSearchMatcher extends ContactCountMatcher {
    // `status` bits
    const COMPLETE = 1;
    const INCOMPLETE = 2;
    const INPROGRESS = 4;
    const NOTSTARTED = 8;
    const NOTACCEPTED = 16;
    const PENDINGAPPROVAL = 32;
    const MYREQUEST = 128;
    const APPROVED = 256;
    const SUBMITTED = 512;

    // `sensitivity` bits
    const HAS_COUNT = 1;
    const HAS_USERS = 2;
    const HAS_RTYPE = 4;
    const HAS_ROUND = 8;
    const HAS_STATUS = 16;
    const HAS_TOKENS = 32;
    const HAS_REQUESTER = 64;
    const HAS_RATINGS = 128;
    const HAS_WORDCOUNT = 256;
    const HAS_FIELD = 512;

    /** @var int */
    private $sensitivity = 0;
    /** @var int */
    private $review_type = 0;
    /** @var int */
    private $status = 0;
    /** @var ?list<int> */
    public $round_list;
    /** @var ?list<int> */
    private $tokens;
    /** @var ?CountMatcher */
    private $wordcountexpr;
    /** @var ?int */
    private $requester;
    /** @var ?int */
    private $rate_bits;
    /** @var bool */
    private $rate_fail;
    /** @var int */
    public $rfop = 0;
    /** @var ?ReviewFieldSearch<ReviewField> */
    private $rfsrch;

    static private $status_map = [
        // preferred names come first
        "complete" => self::COMPLETE,
        "incomplete" => self::INCOMPLETE,
        "in-progress" => self::INPROGRESS,
        "not-accepted" => self::NOTACCEPTED,
        "not-started" => self::NOTSTARTED,
        "pending-approval" => self::PENDINGAPPROVAL,
        "approved" => self::APPROVED,
        "submitted" => self::SUBMITTED,
        "myreq" => self::MYREQUEST,

        "approvable" => self::PENDINGAPPROVAL,
        "completed" => self::COMPLETE,
        "done" => self::COMPLETE,
        "draft" => self::INPROGRESS,
        "inprogress" => self::INPROGRESS,
        "my-req" => self::MYREQUEST,
        "my-request" => self::MYREQUEST,
        "myrequest" => self::MYREQUEST,
        "not-done" => self::INCOMPLETE,
        "notaccepted" => self::NOTACCEPTED,
        "notdone" => self::INCOMPLETE,
        "notstarted" => self::NOTSTARTED,
        "outstanding" => self::NOTACCEPTED,
        "partial" => self::INPROGRESS,
        "pending" => self::PENDINGAPPROVAL,
        "pendingapproval" => self::PENDINGAPPROVAL,

        "pending-my-approval" => self::PENDINGAPPROVAL | self::MYREQUEST,
        "pendingmy-approval" => self::PENDINGAPPROVAL | self::MYREQUEST,
        "pending-myapproval" => self::PENDINGAPPROVAL | self::MYREQUEST,
        "pendingmyapproval" => self::PENDINGAPPROVAL | self::MYREQUEST
    ];

    function __construct() {
        parent::__construct(">0", null);
    }
    /** @return int */
    function sensitivity() {
        return $this->sensitivity;
    }
    /** @return bool */
    function has_count() {
        return ($this->sensitivity & self::HAS_COUNT) !== 0;
    }
    /** @return bool */
    function has_wordcount() {
        return ($this->sensitivity & self::HAS_WORDCOUNT) !== 0;
    }
    /** @return bool */
    function only_pc() {
        return $this->review_type >= REVIEW_PC;
    }
    /** @return int */
    function review_type() {
        return $this->review_type;
    }
    /** @return array */
    function unparse_json(Conf $conf) {
        $j = [];
        if (($this->sensitivity & self::HAS_COUNT) !== 0) {
            $j["count"] = $this->comparison();
        }
        if (($this->sensitivity & self::HAS_USERS) !== 0) {
            $j["users"] = $this->contact_set();
        }
        if (($this->sensitivity & self::HAS_TOKENS) !== 0) {
            $j["tokens"] = $this->tokens;
        }
        if (($this->sensitivity & self::HAS_REQUESTER) !== 0) {
            $j["requester"] = $this->requester;
        }
        if (($this->sensitivity & self::HAS_RTYPE) !== 0) {
            $j["review_type"] = ReviewInfo::unparse_type($this->review_type);
        }
        if (($this->sensitivity & self::HAS_ROUND) !== 0) {
            $j["round"] = array_map(function ($r) use ($conf) {
                return $conf->round_name($r);
            }, $this->round_list);
        }
        if (($this->sensitivity & self::HAS_STATUS) !== 0) {
            $s = $this->status;
            foreach (self::$status_map as $name => $bits) {
                if (($s & $bits) === $bits) {
                    $j["status"][] = $name;
                    $s &= ~$bits;
                }
            }
        }
        if (($this->sensitivity & self::HAS_RATINGS) !== 0) {
            $j["ratings"] = "yes";
        }
        if (($this->sensitivity & self::HAS_WORDCOUNT) !== 0) {
            $j["wordcount"] = $this->wordcountexpr->comparison();
        }
        if (($this->sensitivity & self::HAS_FIELD) !== 0) {
            $j["field"] = $this->rfsrch->rf->name;
        }
        return $j;
    }

    /** @param string $word
     * @return bool */
    function apply_review_type($word) {
        if ($this->review_type !== 0
            || !($rt = ReviewInfo::parse_type($word, false))) {
            return false;
        }
        $this->review_type = $rt;
        $this->sensitivity |= self::HAS_RTYPE;
        return true;
    }
    /** @param string $word
     * @return bool */
    function apply_completeness($word) {
        if (($c = self::$status_map[$word] ?? null) === null) {
            return false;
        }
        $this->status |= $c;
        $this->sensitivity |= self::HAS_STATUS;
        return true;
    }
    /** @return ?list<int> */
    static function parse_round($word, Conf $conf, $allow_generic = false) {
        if (($neg = str_starts_with($word, "-"))) {
            $word = substr($word, 1);
        }
        if (strcasecmp($word, "unnamed") === 0
            || ($allow_generic && strcasecmp($word, "none") === 0)) {
            $r = [0];
        } else if ($allow_generic && strcasecmp($word, "any") === 0) {
            $r = array_keys($conf->defined_rounds());
        } else if (strpos($word, "*") === false) {
            if (($round = $conf->round_number($word)) !== null) {
                $r = [$round];
            } else {
                return null;
            }
        } else {
            $re = '/\A' . str_replace('\*', '.*', preg_quote($word)) . '\z/is';
            $r = array_keys(preg_grep($re, $conf->defined_rounds()));
        }
        if ($neg) {
            $r = array_values(array_diff(array_keys($conf->defined_rounds()), $r));
        }
        return $r;
    }
    /** @param string $word
     * @return bool */
    function apply_round($word, Conf $conf) {
        if ($this->round_list !== null
            || ($round = $conf->round_number($word)) === null) {
            return false;
        }
        $this->round_list = [$round];
        $this->sensitivity |= self::HAS_ROUND;
        return true;
    }
    /** @param list<int> $rounds
     * @return bool */
    function apply_round_list($rounds) {
        if ($this->round_list !== null) {
            return false;
        }
        $this->round_list = $rounds;
        $this->sensitivity |= self::HAS_ROUND;
        return true;
    }
    /** @param int $relation
     * @param int $value
     * @return bool */
    function apply_relation_value($relation, $value) {
        if (($this->sensitivity & self::HAS_COUNT) !== 0) {
            return false;
        }
        $this->set_relation_value($relation, $value);
        $this->sensitivity |= self::HAS_COUNT;
        return true;
    }
    /** @param string $word
     * @return bool */
    function apply_countexpr($word, $default_op = "=") {
        if (($this->sensitivity & self::HAS_COUNT) !== 0) {
            return false;
        }
        if ($word === "all") {
            $this->rfop |= self::RELALL;
            return $this->apply_relation_value(CountMatcher::RELGE, 1);
        } else if ($word === "span") {
            $this->rfop |= self::RELALL | self::RELSPAN;
            return $this->apply_relation_value(CountMatcher::RELGE, 1);
        } else {
            if (ctype_digit($word)) {
                $word = $default_op . $word;
            }
            $a = CountMatcher::unpack_search_comparison($word);
            return $a[0] === "" && $this->apply_relation_value($a[1], $a[2]);
        }
    }
    /** @param int $cid */
    function apply_requester($cid) {
        $this->requester = $cid;
        $this->sensitivity |= $cid !== null ? self::HAS_REQUESTER : 0;
    }
    /** @param int $relation
     * @param int|float $value
     * @return bool */
    function apply_wordcount($relation, $value) {
        if ($this->wordcountexpr !== null) {
            return false;
        }
        $this->wordcountexpr = CountMatcher::make($relation, $value);
        $this->sensitivity |= self::HAS_WORDCOUNT;
        return true;
    }
    /** @param ?list<int> $tokens */
    function apply_tokens($tokens) {
        assert($this->tokens === null);
        $this->tokens = $tokens;
        $this->sensitivity |= $this->tokens !== null ? self::HAS_TOKENS : 0;
    }
    /** @param int $rate_bits */
    function apply_rate_bits($rate_bits) {
        assert($this->rate_bits === null);
        $this->rate_bits = $rate_bits;
        $this->sensitivity |= self::HAS_RATINGS;
    }
    /** @param ReviewFieldSearch $rfsrch */
    function apply_field(ReviewFieldSearch $rfsrch) {
        assert(!$this->rfsrch);
        $this->rfsrch = $rfsrch;
        $this->sensitivity |= self::HAS_FIELD;
    }

    /** @param string $word
     * @param Conf $conf
     * @return bool */
    function apply_review_word($word, $conf) {
        return $this->apply_review_type($word)
            || $this->apply_completeness($word)
            || $this->apply_round($word, $conf);
    }

    function finish() {
        if (!$this->status
            && ($this->rfsrch || $this->wordcountexpr)) {
            $this->status = self::COMPLETE;
            $this->sensitivity |= self::HAS_STATUS;
        }
        if ($this->status & self::PENDINGAPPROVAL) {
            $this->apply_review_type("ext");
        }
        if ($this->has_contacts()) {
            $this->sensitivity |= self::HAS_USERS;
        }
    }

    /** @return ?string */
    function useful_sqlexpr(Contact $user, $table_name) {
        if ($this->test(0)) {
            return null;
        }
        $where = [];
        $swhere = [];
        if (($this->status & self::SUBMITTED) !== 0) {
            $swhere[] = "reviewSubmitted is not null";
        } else if (($this->status & self::COMPLETE) !== 0) {
            $swhere[] = "reviewSubmitted is not null or timeApprovalRequested<0";
        }
        if (($this->status & self::PENDINGAPPROVAL) !== 0) {
            $swhere[] = "(reviewSubmitted is null and timeApprovalRequested>0)";
        }
        if (($this->status & self::NOTACCEPTED) !== 0) {
            $swhere[] = "reviewModified<1";
        } else if (($this->status & self::NOTSTARTED) !== 0) {
            $swhere[] = "reviewModified<2";
        }
        if (!empty($swhere)) {
            $where[] = "(" . join(" or ", $swhere) . ")";
        }
        if ($this->review_type === 0) {
            $where[] = "reviewType>0";
        } else {
            $where[] = "reviewType={$this->review_type}";
        }
        if ($this->has_contacts()) {
            $cm = $this->contact_match_sql("contactId");
            if ($this->tokens) {
                $cm = "({$cm} or reviewToken in (" . join(",", $this->tokens) . "))";
            }
            $where[] = $cm;
        }
        if ($this->rfsrch && ($qx = $this->rfsrch->sqlexpr())) {
            $where[] = $qx;
        }
        if ($this->rate_bits > 0) {
            $where[] = "exists (select * from ReviewRating where paperId={$table_name}.paperId and reviewId={$table_name}.reviewId)";
        }
        if ($this->requester !== null) {
            $where[] = "requestedBy=" . $this->requester;
        }
        if ($this->status & self::MYREQUEST) {
            $where[] = "requestedBy=" . $user->contactId;
        }
        if (empty($where)) {
            return null;
        } else {
            return join(" and ", $where);
        }
    }
    function prepare_reviews(PaperInfo $prow) {
        if ($this->wordcountexpr) {
            $prow->ensure_review_word_counts();
        }
        if ($this->rate_bits !== null) {
            $prow->ensure_full_reviews();
        }
        if ($this->rfsrch) {
            $prow->ensure_review_field_order($this->rfsrch->rf->order);
            $this->rfsrch->prepare();
        }
        $this->rate_fail = false;
    }
    function test_review(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        if ($this->review_type
            && $this->review_type !== $rrow->reviewType) {
            return false;
        }
        if ($this->status !== 0) {
            if ((($this->status & self::COMPLETE) !== 0
                 && $rrow->reviewStatus < ReviewInfo::RS_ADOPTED)
                || (($this->status & self::SUBMITTED) !== 0
                    && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED)
                || (($this->status & self::INCOMPLETE) !== 0
                    && !$rrow->reviewNeedsSubmit)
                || (($this->status & self::INPROGRESS) !== 0
                    && $rrow->reviewStatus !== ReviewInfo::RS_DRAFTED
                    && $rrow->reviewStatus !== ReviewInfo::RS_DELIVERED)
                || (($this->status & self::NOTACCEPTED) !== 0
                    && $rrow->reviewStatus >= ReviewInfo::RS_ACCEPTED)
                || (($this->status & self::NOTSTARTED) !== 0
                    && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED)
                || (($this->status & self::PENDINGAPPROVAL) !== 0
                    && $rrow->reviewStatus !== ReviewInfo::RS_DELIVERED)
                || (($this->status & self::APPROVED) !== 0
                    && $rrow->reviewStatus !== ReviewInfo::RS_ADOPTED)
                || (($this->status & self::MYREQUEST) !== 0
                    && $rrow->requestedBy != $user->contactId)) {
                return false;
            }
        }
        if ($this->round_list !== null
            && !in_array($rrow->reviewRound, $this->round_list)) {
            // XXX can_view_review_round?
            return false;
        }
        if ($this->rfsrch || $this->wordcountexpr || $this->rate_bits !== null
            ? !$user->can_view_review($prow, $rrow)
            : !$user->can_view_review_assignment($prow, $rrow)) {
            return false;
        }
        if ($this->has_contacts()) {
            if ((!$this->test_contact($rrow->contactId)
                 && (!$this->tokens || !in_array($rrow->reviewToken, $this->tokens)))
                || !$user->can_view_review_identity($prow, $rrow)) {
                return false;
            }
        } else if ($rrow->reviewType > 0
                   && $rrow->reviewStatus < ReviewInfo::RS_ADOPTED
                   && $rrow->reviewNeedsSubmit <= 0
                   && ($this->sensitivity & (self::HAS_STATUS | self::HAS_RTYPE)) === 0) {
            // don't count delegated reviews unless contacts, status, or type given
            return false;
        }
        if ($this->wordcountexpr
            && !$this->wordcountexpr->test($rrow->reviewWordCount)) {
            return false;
        }
        if ($this->requester !== null
            && ($rrow->requestedBy !== $this->requester
                || !$user->can_view_review_requester($prow, $rrow))) {
            return false;
        }
        if ($this->rate_bits !== null) {
            if ($user->can_view_review_ratings($prow, $rrow, $user->privChair)) {
                $ratings = $rrow->ratings();
            } else {
                $ratings = [];
            }
            $ok = $this->rate_bits === 0;
            foreach ($ratings as $r) {
                if ($ok ? $r !== 0 : ($r & $this->rate_bits) !== 0) {
                    $ok = !$ok;
                    break;
                }
            }
            if (!$ok) {
                $this->rate_fail = true;
                return false;
            }
        }
        if ($this->rfsrch
            && ($this->rfsrch->rf->view_score <= $user->view_score_bound($prow, $rrow)
                || $this->rfsrch->finished < 0
                || !$this->rfsrch->test_review($user, $prow, $rrow))) {
            return false;
        }
        return true;
    }
    /** @param int $n
     * @return bool */
    function test_finish($n) {
        return $this->test($n)
            && (!$this->rfsrch
                || $this->rfsrch->finished === 0)
            && ($this->rate_bits !== 0
                || !$this->rate_fail
                || ($this->sensitivity & self::HAS_COUNT) !== 0);
    }
}
