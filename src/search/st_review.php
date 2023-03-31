<?php
// search/st_review.php -- HotCRP helper class for searching for papers
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
            if (($round = $conf->round_number($word, false)) !== null) {
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
            || ($round = $conf->round_number($word, false)) === null) {
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

class Review_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ReviewSearchMatcher */
    private $rsm;
    private static $recompleteness_map = [
        "c" => "complete", "i" => "incomplete", "p" => "partial"
    ];

    function __construct(Contact $user, ReviewSearchMatcher $rsm) {
        parent::__construct("re");
        $this->user = $user;
        $this->rsm = $rsm;
        $this->rsm->finish();
    }

    /** @return ReviewSearchMatcher */
    function review_matcher() {
        return $this->rsm;
    }

    static function keyword_factory($keyword, XtParams $xtp, $kwfj, $m) {
        $c = str_replace("-", "", $m[1]);
        $t = str_replace("-", "", $m[2]);
        return (object) [
            "name" => $keyword,
            "parse_function" => "Review_SearchTerm::parse",
            "rtype" => $t,
            "recompleteness" => self::$recompleteness_map[$c] ?? $c,
            "reblank" => $c === "" && $t === "",
            "has" => ">0"
        ];
    }

    /** @param string $s
     * @return list<string> */
    static function split($s) {
        $cs = [];
        $pos = 0;
        while ($pos < strlen($s)) {
            $pos1 = SearchSplitter::span_balanced_parens($s, $pos, ":", true);
            $x = trim(substr($s, $pos, $pos1 - $pos));
            if ($x !== ""
                && ctype_digit($x[strlen($x) - 1])
                && ($a = CountMatcher::unpack_comparison($x))) {
                if ($a[0] !== "") {
                    $cs[] = $a[0];
                }
                $x = CountMatcher::unparse_relation($a[1]) . $a[2];
            }
            $cs[] = $x;
            $pos = $pos1 + 1;
        }
        return $cs;
    }

    /** @param list<string> &$components
     * @param int &$pos
     * @return ?array{int,float} */
    static function comparator_after(&$components, &$pos) {
        if ($pos + 1 < count($components)
            && ($a = CountMatcher::parse_comparison($components[$pos + 1]))) {
            ++$pos;
            return $a;
        } else if ($pos + 1 < count($components) - 1
                   && ($a = CountMatcher::parse_comparison($components[count($components) - 1]))) {
            array_pop($components);
            return $a;
        } else {
            return null;
        }
    }

    /** @param list<string> $components
     * @param int $i
     * @return ?SearchTerm */
    static private function parse_components(ReviewSearchMatcher $rsm, $components, $i, PaperSearch $srch) {
        $contacts = null;
        for (; $i < count($components); ++$i) {
            $c = $components[$i];
            if ($rsm->apply_review_type($c)
                || $rsm->apply_completeness($c)
                || $rsm->apply_round($c, $srch->conf)) {
                // ok
            } else if (($c === "auwords" || $c === "words")
                       && ($a = self::comparator_after($components, $i))) {
                if (!$rsm->apply_wordcount($a[0], $a[1])) {
                    return null;
                }
            } else if ($i === count($components) - 1
                       && ($a = CountMatcher::parse_comparison($c))) {
                $rsm->apply_relation_value($a[0], (int) $a[1]);
            } else if ($i === count($components) - 1
                       && ($c === "any" || $c === "none")) {
                $rsm->apply_relation_value($c === "any" ? CountMatcher::RELGT : CountMatcher::RELEQ, 0);
            } else if ($contacts === null) {
                $contacts = $c;
            } else {
                return null;
            }
        }
        if (($qr = SearchTerm::make_constant($rsm->tautology()))) {
            return $qr;
        }
        if ($contacts !== null && $contacts !== "") {
            $rsm->set_contacts($srch->matching_uids($contacts, null, $rsm->only_pc()));
            if (strcasecmp($contacts, "me") == 0) {
                $rsm->apply_tokens($srch->user->review_tokens());
            }
        }
        return new Review_SearchTerm($srch->user, $rsm);
    }

    /** @return SearchTerm */
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if ($sword->kwdef->reblank
            && str_starts_with($sword->qword, "proposal")
            && (strlen($sword->qword) === 8 || $sword->qword[8] === ":")) {
            $sword->qword = strlen($sword->qword) === 8 ? "any" : ltrim(substr($sword->qword, 9));
            return Proposal_SearchTerm::parse(SearchWord::unquote($sword->qword), $sword, $srch);
        }
        $rsm = new ReviewSearchMatcher;
        if ($sword->kwdef->rtype) {
            $rsm->apply_review_type($sword->kwdef->rtype);
        }
        if ($sword->kwdef->recompleteness) {
            $rsm->apply_completeness($sword->kwdef->recompleteness);
        }
        if (($qr = self::parse_components($rsm, self::split($sword->qword), 0, $srch))) {
            return $qr;
        } else {
            $srch->lwarning($sword, "<0>Invalid reviewer search");
            return new False_SearchTerm;
        }
    }

    /** @return SearchTerm */
    static function parse_round($word, SearchWord $sword, PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher;
        $components = self::split($sword->qword);
        if (empty($components)
            || ($round_list = ReviewSearchMatcher::parse_round($components[0], $srch->conf)) === null) {
            $srch->lwarning($sword, "<0>Review round not found");
            return new False_SearchTerm;
        }
        $rsm->apply_round_list($round_list);
        if (($qr = self::parse_components($rsm, $components, 1, $srch))) {
            return $qr;
        } else {
            $srch->lwarning($sword, "<0>Invalid review round search");
            return new False_SearchTerm;
        }
    }

    /** @return SearchTerm */
    static function parse_rate($word, SearchWord $sword, PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher;
        $components = self::split($sword->qword);
        if (!empty($components)) {
            $rate_bits = ReviewInfo::parse_rating_search($components[0]);
            $rsm->apply_rate_bits($rate_bits ?? ReviewInfo::RATING_ANYMASK);
            if (($qr = self::parse_components($rsm, $components, 1, $srch))) {
                return $qr;
            }
        }
        $srch->lwarning($sword, "<0>Invalid rating search");
        return new False_SearchTerm;
    }

    static function review_field_factory($keyword, XtParams $xtp, $kwfj, $m) {
        $f = $xtp->conf->find_all_fields($keyword);
        if (count($f) == 1 && $f[0] instanceof ReviewField) {
            return (object) [
                "name" => $keyword,
                "parse_function" => "Review_SearchTerm::parse_review_field",
                "review_field" => $f[0],
                "has" => "any"
            ];
        } else {
            return null;
        }
    }

    /** @return SearchTerm */
    static function parse_review_field($word, SearchWord $sword, PaperSearch $srch) {
        $f = $sword->kwdef->review_field;
        $rsm = new ReviewSearchMatcher;

        // split into parts
        $parts = preg_split('/((?::(?:[=!<>]=?+|≠|≤|≥)?+|[=!<>]=?+|≠|≤|≥)(?:[^:=!<>\"\xe2]|\xe2(?!\x89[\xa0\xa4\xa5])[\x80-\xBF][\x80-\xBF]|\"[^\"]*+\"?)++)/', $sword->qword, 0, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) === 1 && trim($parts[0]) === "") {
            $srch->lwarning($sword, "<0>Missing expression (did you mean ‘{$sword->kwdef->name}:any’?)");
            return new False_SearchTerm;
        }

        $i = $parts[0] === "" ? 1 : 0;
        while ($i < count($parts) - 2) {
            $part = $i === 0 ? $parts[$i] : $parts[$i] . $parts[$i + 1];
            $i = $i === 0 ? 1 : $i + 2;
            if (str_starts_with($part, ":")) {
                $part = substr($part, 1);
            }
            if ($rsm->apply_countexpr($part, ">=")
                || $rsm->apply_review_type($part)
                || $rsm->apply_completeness($part)
                || $rsm->apply_round($part, $srch->conf)) {
                // OK
            } else {
                list($part, $quoted) = SearchWord::maybe_unquote($part);
                $rsm->set_contacts($srch->matching_uids($part, $quoted, false));
            }
        }

        $word = $i === 0 ? $parts[$i] : $parts[$i] . $parts[$i + 1];
        if (str_starts_with($word, ":")) {
            $word = substr($word, 1);
        }
        $sword->cword = $word;
        if (($rfsrch = ReviewFieldSearch::parse($sword, $f, $rsm, $srch))) {
            $rsm->apply_field($rfsrch);
            return new Review_SearchTerm($srch->user, $rsm);
        } else {
            return new False_SearchTerm;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_review_signature_columns();
        if ($this->rsm->has_wordcount()) {
            $sqi->add_review_word_count_columns();
        }

        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        // ">=0" is a useless constraint in SQL-land.
        $cexpr = $this->rsm->conservative_nonnegative_comparison();
        if ($cexpr === ">=0") {
            return "true";
        }
        $wheres = $this->rsm->useful_sqlexpr($this->user, "r") ?? "true";
        if ($cexpr === ">0") {
            return "exists (select * from PaperReview r where paperId=Paper.paperId and {$wheres})";
        } else {
            return "(select count(*) from PaperReview r where paperId=Paper.paperId and {$wheres}){$cexpr}";
        }
    }
    function test(PaperInfo $prow, $xinfo) {
        $this->rsm->prepare_reviews($prow);
        $n = 0;
        if ($xinfo
            && !$this->rsm->has_count()
            && $xinfo instanceof ReviewInfo) {
            $n += $this->rsm->test_review($this->user, $prow, $xinfo);
        } else {
            foreach ($prow->all_reviews() as $rrow) {
                $n += $this->rsm->test_review($this->user, $prow, $rrow);
            }
        }
        return $this->rsm->test_finish($n);
    }
    function debug_json() {
        return ["type" => $this->type] + $this->rsm->unparse_json($this->user->conf);
    }
    function about_reviews() {
        return $this->rsm->has_count() ? self::ABOUT_MANY : self::ABOUT_SELF;
    }


    /** @param SearchTerm $term
     * @return array{int,bool} */
    static function term_round_mask($term) {
        $other = false;
        $mask = $term->visit(function ($st, ...$args) use (&$other) {
            if ($st instanceof True_SearchTerm) {
                return ~0;
            } else if ($st instanceof False_SearchTerm) {
                $other = true;
                return 0;
            } else if ($st instanceof Or_SearchTerm) {
                return Review_SearchTerm::round_mask_combine($args, false);
            } else if ($st instanceof And_SearchTerm) {
                $mx = ~0;
                foreach ($args as $m) {
                    $mx &= $m ?? ~0;
                }
                return $mx;
            } else if ($st instanceof Review_SearchTerm) {
                $rsm = $st->review_matcher();
                if ($rsm->sensitivity() !== ReviewSearchMatcher::HAS_ROUND) {
                    $other = true;
                }
                if (!$rsm->has_count()
                    && ($rsm->sensitivity() & ReviewSearchMatcher::HAS_ROUND) !== 0
                    && $rsm->test(1)) {
                    return Review_SearchTerm::round_mask_combine($rsm->round_list, true);
                }
            }
            $other = true;
            return null;
        });
        if ($mask === ~0 || $mask === null) {
            $mask = 0;
        }
        return [$mask, $other];
    }

    /** @param list<?int> $rlist
     * @param bool $isrnum
     * @return ?int */
    static private function round_mask_combine($rlist, $isrnum) {
        $rm = 0;
        foreach ($rlist as $round) {
            if ($round === null || ($isrnum && $round >= PHP_INT_SIZE * 8 - 1)) {
                return null;
            }
            $rm |= $isrnum ? 1 << $round : $round;
        }
        return $rm;
    }
}
