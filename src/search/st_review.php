<?php
// search/st_review.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    /** @var ?ReviewField */
    private $rfield;
    private $rfield_score1;
    private $rfield_score2;
    private $rfield_scoret;
    private $rfield_scorex;
    private $rfield_text;

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
    function can_test_review() {
        return ($this->sensitivity & self::HAS_COUNT) === 0;
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
            $j["field"] = $this->rfield->name;
        }
        return $j;
    }

    /** @param string $word
     * @return bool */
    function apply_review_type($word) {
        if ($this->review_type === 0
            && ($rt = ReviewInfo::parse_type($word, false))) {
            $this->review_type = $rt;
            $this->sensitivity |= self::HAS_RTYPE;
            return true;
        } else {
            return false;
        }
    }
    /** @param string $word
     * @return bool */
    function apply_completeness($word) {
        if (($c = self::$status_map[$word] ?? null) !== null) {
            $this->status |= $c;
            $this->sensitivity |= self::HAS_STATUS;
            return true;
        } else {
            return false;
        }
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
            $r = array_keys($conf->defined_round_list());
        } else if (strpos($word, "*") === false) {
            if (($round = $conf->round_number($word, false)) !== null) {
                $r = [$round];
            } else {
                return null;
            }
        } else {
            $re = '/\A' . str_replace('\*', '.*', preg_quote($word)) . '\z/is';
            $r = array_keys(preg_grep($re, $conf->defined_round_list()));
        }
        if ($neg) {
            $r = array_values(array_diff(array_keys($conf->defined_round_list()), $r));
        }
        return $r;
    }
    /** @param string $word
     * @return bool */
    function apply_round($word, Conf $conf) {
        if ($this->round_list === null
            && ($round = $conf->round_number($word, false)) !== null) {
            $this->round_list = [$round];
            $this->sensitivity |= self::HAS_ROUND;
            return true;
        } else {
            return false;
        }
    }
    /** @param list<int> $rounds
     * @return bool */
    function apply_round_list($rounds) {
        if ($this->round_list === null) {
            $this->round_list = $rounds;
            $this->sensitivity |= self::HAS_ROUND;
            return true;
        } else {
            return false;
        }
    }
    /** @param int $relation
     * @param int $value
     * @return bool */
    function apply_relation_value($relation, $value) {
        if (($this->sensitivity & self::HAS_COUNT) === 0) {
            $this->set_relation_value($relation, $value);
            $this->sensitivity |= self::HAS_COUNT;
            return true;
        } else {
            return false;
        }
    }
    /** @param string $word
     * @return bool */
    function apply_countexpr($word, $default_op = "=") {
        if (ctype_digit($word)) {
            $word = $default_op . $word;
        }
        $a = CountMatcher::unpack_search_comparison($word);
        return $a[0] === "" && $this->apply_relation_value($a[1], $a[2]);
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
        if ($this->wordcountexpr === null) {
            $this->wordcountexpr = CountMatcher::make($relation, $value);
            $this->sensitivity |= self::HAS_WORDCOUNT;
            return true;
        } else {
            return false;
        }
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
    function apply_text_field(ReviewField $field, $value) {
        assert(!$this->rfield && !$field->has_options);
        $this->rfield = $field;
        $this->rfield_text = $value;
        $this->sensitivity |= self::HAS_FIELD;
    }
    function apply_score_field(ReviewField $field, $value1, $value2, $valuet) {
        assert(!$this->rfield && $field->has_options);
        $this->rfield = $field;
        $this->rfield_score1 = $value1;
        $this->rfield_score2 = $value2;
        $this->rfield_scoret = $valuet;
        $this->sensitivity |= self::HAS_FIELD;
    }
    function finish() {
        if (!$this->status
            && ($this->rfield || $this->wordcountexpr)) {
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
        if (($this->status & self::SUBMITTED) !== 0) {
            $where[] = "reviewSubmitted is not null";
        } else if (($this->status & self::COMPLETE) !== 0) {
            $where[] = "reviewSubmitted is not null or timeApprovalRequested<0";
        }
        if (($this->status & self::PENDINGAPPROVAL) !== 0) {
            $where[] = "(reviewSubmitted is null and timeApprovalRequested>0)";
        }
        if (($this->status & self::NOTACCEPTED) !== 0) {
            $where[] = "reviewModified<1";
        } else if (($this->status & self::NOTSTARTED) !== 0) {
            $where[] = "reviewModified<2";
        }
        if (!empty($where)) {
            $where = ["(" . join(" or ", $where) . ")"];
        }
        if ($this->review_type === 0) {
            $where[] = "reviewType>0";
        } else {
            $where[] = "reviewType={$this->review_type}";
        }
        if ($this->has_contacts()) {
            $cm = $this->contact_match_sql("contactId");
            if ($this->tokens) {
                $cm = "($cm or reviewToken in (" . join(",", $this->tokens) . "))";
            }
            $where[] = $cm;
        }
        if ($this->rfield) {
            if ($this->rfield->has_options) {
                if ($this->rfield->main_storage) {
                    if ($this->rfield_scoret >= 8) {
                        $ce = ">=";
                    } else {
                        $ce = CountMatcher::$oparray[$this->rfield_scoret];
                    }
                    $where[] = $this->rfield->main_storage . $ce . $this->rfield_score1;
                } else {
                    if ($this->rfield_score1 != 0
                        || !($this->rfield_scoret & 2)) {
                        $where[] = "sfields is not null";
                    }
                }
            } else {
                if ($this->rfield->main_storage) {
                    $where[] = $this->rfield->main_storage . "!=''";
                } else {
                    if ($this->rfield_text)
                        $where[] = "tfields is not null";
                }
            }
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
        if ($this->rfield) {
            $prow->ensure_review_field_order($this->rfield->order);
        }
        $this->rfield_scorex = $this->rfield_scoret === 16 ? 0 : 3;
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
        if ($this->rfield || $this->wordcountexpr || $this->rate_bits !== null
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
        if ($this->rfield) {
            if ($this->rfield->view_score <= $user->view_score_bound($prow, $rrow)) {
                return false;
            }
            $val = $rrow->fields[$this->rfield->order];
            if ($this->rfield->has_options) {
                if ($this->rfield_scoret >= 8) {
                    if (!$val || $this->rfield_scorex < 0) {
                        return false;
                    } else if ($val < $this->rfield_score1 || $val > $this->rfield_score2) {
                        $this->rfield_scorex = -1;
                        return false;
                    } else {
                        if ($val == $this->rfield_score1) {
                            $this->rfield_scorex |= 1;
                        }
                        if ($val == $this->rfield_score2) {
                            $this->rfield_scorex |= 2;
                        }
                        return true;
                    }
                } else if ($val) {
                    return CountMatcher::compare($val, $this->rfield_scoret, $this->rfield_score1);
                } else {
                    return $this->rfield_score1 == 0 && ($this->rfield_scoret & 2);
                }
            } else {
                if ((string) $val === "") {
                    return false;
                } else if ($this->rfield_text !== true) {
                    if (!$rrow->field_match_pregexes($this->rfield_text, $this->rfield->order))
                        return false;
                }
            }
        }
        return true;
    }
    /** @param int $n
     * @return bool */
    function test_finish($n) {
        return $this->test($n)
            && $this->rfield_scorex === 3
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

    static function keyword_factory($keyword, Contact $user, $kwfj, $m) {
        $c = str_replace("-", "", $m[1]);
        $t = str_replace("-", "", $m[2]);
        return (object) [
            "name" => $keyword,
            "parse_function" => "Review_SearchTerm::parse",
            "retype" => $t,
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
            $pos1 = SearchSplitter::span_balanced_parens($s, $pos, ":");
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
                $rsm->apply_relation_value($c === "any" ? 4 : 2, 0);
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
        if ($sword->kwdef->retype) {
            $rsm->apply_review_type($sword->kwdef->retype);
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

    static function review_field_factory($keyword, Contact $user, $kwfj, $m) {
        $f = $user->conf->find_all_fields($keyword);
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

        while (preg_match('/\A([^<>].*?|[<>].+?)(:|[=!<>]=?|≠|≤|≥)(.*)\z/s', $word, $m)) {
            if ($rsm->apply_review_type($m[1])
                || $rsm->apply_completeness($m[1])
                || $rsm->apply_round($m[1], $srch->conf)
                || $rsm->apply_countexpr($m[1], ">=")) {
                // OK
            } else {
                $rsm->set_contacts($srch->matching_uids($m[1], $sword->quoted, false));
            }
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
        }

        if ($f instanceof Score_ReviewField) {
            return self::parse_score_field($rsm, $word, $sword, $f, $srch);
        } else {
            if ($word === "any" && !$sword->quoted) {
                $val = true;
            } else if ($word === "none" && !$sword->quoted) {
                $val = true;
                $rsm->set_comparison("=0");
            } else {
                $val = Text::star_text_pregexes($word, $sword->quoted);
            }
            $rsm->apply_text_field($f, $val);
            return new Review_SearchTerm($srch->user, $rsm);
        }
    }
    /** @return False_SearchTerm */
    private static function impossible_score_match(ReviewField $f, SearchWord $sword, PaperSearch $srch) {
        $r = $f->full_score_range();
        $mi = $srch->lwarning($sword, "<0>{$f->name} scores range from {$r[0]} to {$r[1]}");
        $ft = new False_SearchTerm;
        $ft->score_warning = $mi;
        return $ft;
    }
    /** @return int|false */
    private static function parse_score(Score_ReviewField $f, $str) {
        if (strcasecmp($str, "none") === 0) {
            return 0;
        } else if ($f->option_letter != (ctype_digit($str) === false)) { // `!=` matters
            return false;
        } else if ($f->option_letter) {
            $val = $f->option_letter - ord(strtoupper($str));
            return $val > 0 && $val <= count($f->options) ? $val : false;
        } else {
            $val = intval($str);
            return $val >= 0 && $val <= count($f->options) ? $val : false;
        }
    }
    /** @return SearchTerm */
    private static function parse_score_field(ReviewSearchMatcher $rsm, $word, SearchWord $sword, Score_ReviewField $f, PaperSearch $srch) {
        if ($word === "any") {
            $rsm->apply_score_field($f, 0, 0, 4);
        } else if ($word === "none" && $rsm->can_test_review()) {
            $rsm->apply_relation_value(2, 0);
            $rsm->apply_score_field($f, 0, 0, 4);
        } else if (preg_match('/\A([=!<>]=?|≠|≤|≥|)\s*([A-Z]|\d+|none)\z/si', $word, $m)) {
            $relation = CountMatcher::$opmap[$m[1]];
            if ($f->option_letter && !$srch->conf->opt("smartScoreCompare")) {
                $relation = CountMatcher::flip_relation($relation);
            }
            $score = self::parse_score($f, $m[2]);
            if ($score === false
                || ($score === 0 && $relation === 1)
                || ($score === count($f->options) && $relation === 4)) {
                return self::impossible_score_match($f, $sword, $srch);
            }
            $rsm->apply_score_field($f, $score, 0, $relation);
        } else if (preg_match('/\A(\d+|[A-Z])\s*(|-|–|—|\.\.\.?|…)\s*(\d+|[A-Z])\s*\z/si', $word, $m)) {
            $score1 = self::parse_score($f, $m[1]);
            $score2 = self::parse_score($f, $m[3]);
            if ($score1 === false || $score2 === false) {
                return self::impossible_score_match($f, $sword, $srch);
            }
            if ($score1 > $score2) {
                list($score1, $score2) = [$score2, $score1];
            }
            $precise = $m[2] !== ".." && $m[2] !== "..." && $m[2] !== "…";
            $rsm->apply_score_field($f, $score1, $score2, $precise ? 16 : 8);
        } else {             // XXX
            return new False_SearchTerm;
        }
        return new Review_SearchTerm($srch->user, $rsm);
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
        } else {
            $wheres = $this->rsm->useful_sqlexpr($this->user, "r") ?? "true";
            if ($cexpr === ">0") {
                return "exists (select * from PaperReview r where paperId=Paper.paperId and $wheres)";
            } else {
                return "(select count(*) from PaperReview r where paperId=Paper.paperId and $wheres)" . $cexpr;
            }
        }
    }
    function test(PaperInfo $prow, $rrow) {
        $this->rsm->prepare_reviews($prow);
        $n = 0;
        if ($this->rsm->can_test_review() && $rrow) {
            $n += $this->rsm->test_review($this->user, $prow, $rrow);
        } else {
            $n = 0;
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
        return $this->rsm->can_test_review() ? self::ABOUT_SELF : self::ABOUT_MANY;
    }
}
