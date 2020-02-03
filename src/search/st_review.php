<?php
// search/st_review.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ReviewSearchMatcher extends ContactCountMatcher {
    const COMPLETE = 1;
    const INCOMPLETE = 2;
    const INPROGRESS = 4;
    const NOTSTARTED = 8;
    const PENDINGAPPROVAL = 16;
    const PROPOSED = 32;
    const MYREQUEST = 64;
    const APPROVED = 128;

    private $review_type = 0;
    private $completeness = 0;
    public $view_score;
    public $round;
    public $review_testable = true;
    private $tokens;
    private $wordcountexpr;
    private $rfield;
    private $rfield_score1;
    private $rfield_score2;
    private $rfield_scoret;
    private $rfield_scorex;
    private $rfield_text;
    private $requester;
    private $ratings;
    private $frozen = false;

    static private $completeness_map = [
        "approvable" => self::PENDINGAPPROVAL,
        "approved" => self::APPROVED,
        "complete" => self::COMPLETE,
        "completed" => self::COMPLETE,
        "done" => self::COMPLETE,
        "draft" => self::INPROGRESS,
        "in-progress" => self::INPROGRESS,
        "incomplete" => self::INCOMPLETE,
        "inprogress" => self::INPROGRESS,
        "my-req" => self::MYREQUEST,
        "my-request" => self::MYREQUEST,
        "myreq" => self::MYREQUEST,
        "myrequest" => self::MYREQUEST,
        "not-done" => self::INCOMPLETE,
        "not-started" => self::NOTSTARTED,
        "notdone" => self::INCOMPLETE,
        "notstarted" => self::NOTSTARTED,
        "partial" => self::INPROGRESS,
        "pending" => self::PENDINGAPPROVAL,
        "pending-approval" => self::PENDINGAPPROVAL,
        "pendingapproval" => self::PENDINGAPPROVAL,
        "proposal" => self::PROPOSED,
        "proposed" => self::PROPOSED,
    ];

    function __construct($countexpr = null, $contacts = null) {
        parent::__construct($countexpr, $contacts);
    }
    function only_pc() {
        return $this->review_type >= REVIEW_PC;
    }
    function review_type() {
        return $this->review_type;
    }
    function has_wordcount() {
        return !!$this->wordcountexpr;
    }

    function apply_review_type($word) {
        if (str_ends_with($word, "review")) {
            $word = substr($word, 0, strlen($word) - 6);
            if (str_ends_with($word, "-"))
                $word = substr($word, 0, strlen($word) - 1);
        }
        if ($word === "meta") {
            $rt = REVIEW_META;
        } else if ($word === "pri" || $word === "primary") {
            $rt = REVIEW_PRIMARY;
        } else if ($word === "sec" || $word === "secondary") {
            $rt = REVIEW_SECONDARY;
        } else if ($word === "optional") {
            $rt = REVIEW_PC;
        } else if ($word === "ext" || $word === "external") {
            $rt = REVIEW_EXTERNAL;
        } else {
            return false;
        }
        if ($this->review_type === 0 || $this->review_type === $rt) {
            $this->review_type = $rt;
        } else {
            $this->review_type = -100;
        }
        return true;
    }
    function apply_completeness($word) {
        if (!isset(self::$completeness_map[$word])) {
            return false;
        }
        $this->completeness |= self::$completeness_map[$word];
        return true;
    }
    function apply_round($word, Conf $conf) {
        if (($round = $conf->round_number($word, false)) === false) {
            return false;
        }
        $this->round[] = $round;
        return true;
    }
    function apply_countexpr($word, $default_op = "=") {
        if (!preg_match('/\A(?:(?:[=!<>]=?|≠|≤|≥|)\d+|any|none|yes|no)\z/', $word)) {
            return false;
        }
        if (ctype_digit($word)) {
            $word = $default_op . $word;
        }
        $count = PaperSearch::unpack_comparison($word, false);
        $this->set_countexpr($count[1]);
        $this->review_testable = false;
        return true;
    }
    function adjust_round_list($rounds) {
        if ($this->round === null) {
            $this->round = $rounds;
        }
    }
    function apply_requester($cid) {
        $this->requester = $cid;
    }
    function apply_wordcount($wordcount) {
        assert($this->wordcountexpr === null);
        if ($wordcount) {
            $this->wordcountexpr = $wordcount;
        }
    }
    function apply_tokens($tokens) {
        assert($this->tokens === null);
        $this->tokens = $tokens;
    }
    function adjust_ratings(ReviewRating_SearchAdjustment $rrsa) {
        if ($this->ratings === null) {
            $this->ratings = $rrsa;
        }
    }
    function apply_text_field(ReviewField $field, $value) {
        assert(!$this->rfield && !$field->has_options);
        $this->rfield = $field;
        $this->rfield_text = $value;
    }
    function apply_score_field(ReviewField $field, $value1, $value2, $valuet) {
        assert(!$this->rfield && $field->has_options);
        $this->rfield = $field;
        $this->rfield_score1 = $value1;
        $this->rfield_score2 = $value2;
        $this->rfield_scoret = $valuet;
    }
    function finish() {
        if (!$this->completeness
            && ($this->rfield || $this->wordcountexpr)) {
            $this->completeness = self::COMPLETE;
        }
        if ($this->completeness & self::PENDINGAPPROVAL) {
            $this->apply_review_type("ext");
        }
        if ($this->completeness & self::PROPOSED) {
            if (($this->completeness & ~(self::PROPOSED | self::MYREQUEST))
                || ($this->review_type !== 0 && $this->review_type !== REVIEW_EXTERNAL)
                || $this->rfield
                || $this->wordcountexpr
                || $this->ratings
                || $this->view_score !== null) {
                $this->review_type = -100;
            } else {
                $this->review_type = REVIEW_REQUEST;
                $this->completeness &= self::MYREQUEST;
            }
        }
    }

    function useful_sqlexpr(Contact $user, $table_name) {
        if ($this->test(0))
            return false;
        $where = [];
        if ($this->completeness & self::COMPLETE) {
            $where[] = "reviewSubmitted is not null";
        }
        if ($this->completeness & self::PENDINGAPPROVAL) {
            $where[] = "(reviewSubmitted is null and timeApprovalRequested>0)";
        }
        if ($this->completeness & self::NOTSTARTED) {
            $where[] = "reviewModified<2";
        }
        if (!empty($where)) {
            $where = ["(" . join(" or ", $where) . ")"];
        }
        if ($this->has_contacts()) {
            $cm = $this->contact_match_sql("contactId");
            if ($this->tokens)
                $cm = "($cm or reviewToken in (" . join(",", $this->tokens) . "))";
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
        if ($this->ratings && $this->ratings->must_exist()) {
            $where[] = "exists (select * from ReviewRating where paperId={$table_name}.paperId and reviewId={$table_name}.reviewId)";
        }
        if ($this->requester) {
            $where[] = "requestedBy=" . $this->requester;
        }
        if ($this->completeness & self::MYREQUEST) {
            $where[] = "requestedBy=" . $user->contactId;
        }
        if (empty($where)) {
            return false;
        } else {
            return join(" and ", $where);
        }
    }
    function prepare_reviews(PaperInfo $prow) {
        if ($this->wordcountexpr) {
            $prow->ensure_review_word_counts();
        }
        if (($this->rfield && !$this->rfield->has_options)
            || $this->ratings) {
            $prow->ensure_full_reviews();
        } else if ($this->rfield) {
            $prow->ensure_review_score($this->rfield);
        }
        $this->rfield_scorex = $this->rfield_scoret === 16 ? 0 : 3;
    }
    function test_review(Contact $user, PaperInfo $prow, $rrow, PaperSearch $srch) {
        if ($this->review_type
            && $this->review_type !== $rrow->reviewType) {
            return false;
        }
        if ($this->completeness) {
            if ((($this->completeness & self::COMPLETE)
                 && !$rrow->reviewSubmitted)
                || (($this->completeness & self::INCOMPLETE)
                    && !$rrow->reviewNeedsSubmit)
                || (($this->completeness & self::INPROGRESS)
                    && ($rrow->reviewSubmitted || $rrow->reviewModified < 2))
                || (($this->completeness & self::NOTSTARTED)
                    && $rrow->reviewModified > 1)
                || (($this->completeness & self::PENDINGAPPROVAL)
                    && ($rrow->reviewSubmitted
                        || $rrow->timeApprovalRequested <= 0
                        || ($rrow->requestedBy != $user->contactId
                            && !$user->allow_administer($prow))))
                || (($this->completeness & self::APPROVED)
                    && $rrow->timeApprovalRequested >= 0)
                || (($this->completeness & self::MYREQUEST)
                    && $rrow->requestedBy != $user->contactId)) {
                return false;
            }
        }
        if ($this->round !== null
            && !in_array($rrow->reviewRound, $this->round)) {
            // XXX can_view_review_round?
            return false;
        }
        if ($this->rfield || $this->wordcountexpr || $this->ratings
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
                   && $rrow->reviewSubmitted <= 0
                   && $rrow->reviewNeedsSubmit <= 0
                   && !$this->completeness) {
            // don't count delegated reviews unless contacts or completeness given
            return false;
        }
        if ($this->wordcountexpr
            && !$this->wordcountexpr->test($rrow->reviewWordCount)) {
            return false;
        }
        if ($this->requester !== null
            && ($rrow->requestedBy != $this->requester
                || !$user->can_view_review_requester($prow, $rrow))) {
            return false;
        }
        if ($this->ratings !== null
            && !$this->ratings->test($user, $prow, $rrow)) {
            return false;
        }
        if ($this->view_score !== null
            && $this->view_score <= $user->view_score_bound($prow, $rrow)) {
            return false;
        }
        if ($this->rfield) {
            $fid = $this->rfield->id;
            $val = isset($rrow->$fid) ? $rrow->$fid : null;
            if ($this->rfield->has_options) {
                if ($this->rfield_scoret >= 8) {
                    if (!$val || $this->rfield_scorex < 0) {
                        return false;
                    } else if ($val < $this->rfield_score1 || $val > $this->rfield_score2) {
                        $this->rfield_scorex = -1;
                        return false;
                    } else {
                        if ($val == $this->rfield_score1)
                            $this->rfield_scorex |= 1;
                        if ($val == $this->rfield_score2)
                            $this->rfield_scorex |= 2;
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
                    if (!$rrow->field_match_pregexes($this->rfield_text, $fid))
                        return false;
                }
            }
        }
        return true;
    }
    function test_finish($n) {
        return $this->test($n) && $this->rfield_scorex === 3;
    }
}

class Review_SearchTerm extends SearchTerm {
    private $rsm;
    private static $recompleteness_map = [
        "c" => "complete", "i" => "incomplete", "p" => "partial"
    ];

    function __construct(ReviewSearchMatcher $rsm) {
        parent::__construct("re");
        $this->rsm = $rsm;
        $this->rsm->finish();
    }
    static function keyword_factory($keyword, $user, $kwfj, $m) {
        $c = str_replace("-", "", $m[1]);
        $t = str_replace("-", "", $m[2]);
        return (object) [
            "name" => $keyword,
            "parse_callback" => "Review_SearchTerm::parse",
            "retype" => $t,
            "recompleteness" => get(self::$recompleteness_map, $c, $c),
            "has" => ">0"
        ];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if ($sword->kwdef->retype) {
            $rsm->apply_review_type($sword->kwdef->retype);
        }
        if ($sword->kwdef->recompleteness) {
            $rsm->apply_completeness($sword->kwdef->recompleteness);
        }

        $qword = $sword->qword;
        $quoted = false;
        $contacts = null;
        $wordcount = null;
        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/s';
        while ($qword !== "") {
            if (preg_match('/\A:?((?:[=!<>]=?|≠|≤|≥|)\d+)' . $tailre, $qword, $m)
                && $rsm->apply_countexpr($m[1])) {
                $qword = $m[2];
            } else if (preg_match('/\A(.+?)' . $tailre, $qword, $m)
                       && ($rsm->apply_review_type($m[1])
                           || $rsm->apply_completeness($m[1])
                           || $rsm->apply_round($m[1], $srch->conf)
                           || $rsm->apply_countexpr($m[1]))) {
                $qword = $m[2];
            } else if (preg_match('/\A(?:au)?words((?:[=!<>]=?|≠|≤|≥)\d+)(?:\z|:)(.*)\z/', $qword, $m)) {
                $wordcount = new CountMatcher($m[1]);
                $qword = $m[2];
            } else if (preg_match('/\A(..*?|"[^"]+(?:"|\z))' . $tailre, $qword, $m)) {
                if (($quoted = $m[1][0] === "\""))
                    $m[1] = str_replace(array('"', '*'), array('', '\*'), $m[1]);
                $contacts = $m[1];
                $qword = $m[2];
            } else {
                $rsm->set_countexpr("<0");
                break;
            }
        }

        if (($qr = PaperSearch::check_tautology($rsm->countexpr()))) {
            $qr->set_float("used_revadj", true);
            return $qr;
        }

        $rsm->apply_wordcount($wordcount);
        if ($contacts) {
            $rsm->set_contacts($srch->matching_uids($contacts, $quoted, $rsm->only_pc()));
            if (strcasecmp($contacts, "me") == 0)
                $rsm->apply_tokens($srch->user->review_tokens());
        }
        return new Review_SearchTerm($rsm);
    }

    static function review_field_factory($keyword, $user, $kwfj, $m) {
        $f = $user->conf->find_all_fields($keyword);
        if (count($f) == 1 && $f[0] instanceof ReviewField) {
            return (object) [
                "name" => $keyword,
                "parse_callback" => "Review_SearchTerm::parse_review_field",
                "review_field" => $f[0],
                "has" => "any"
            ];
        } else {
            return null;
        }
    }
    static function parse_review_field($word, SearchWord $sword, PaperSearch $srch) {
        $f = $sword->kwdef->review_field;
        $rsm = new ReviewSearchMatcher(">0");
        $rsm->view_score = $f->view_score;

        $contactword = "";
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
            $contactword .= $m[1] . ":";
        }

        if ($f->has_options) {
            return self::parse_score_field($rsm, $word, $f, $srch);
        } else {
            if ($word === "any" && !$sword->quoted) {
                $val = true;
            } else if ($word === "none" && !$sword->quoted) {
                $val = true;
                $rsm->set_countexpr("=0");
            } else {
                $val = Text::star_text_pregexes($word, $sword->quoted);
            }
            $rsm->apply_text_field($f, $val);
            return new Review_SearchTerm($rsm);
        }
    }
    private static function impossible_score_match(ReviewField $f) {
        $t = new False_SearchTerm;
        $r = $f->full_score_range();
        $t->set_float("contradiction_warning", "$f->name_html scores range from $r[0] to $r[1].");
        $t->set_float("used_revadj", true);
        return $t;
    }
    private static function parse_score($f, $str) {
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
    private static function parse_score_field(ReviewSearchMatcher $rsm, $word, ReviewField $f, PaperSearch $srch) {
        if ($word === "any") {
            $rsm->apply_score_field($f, 0, 0, 4);
        } else if ($word === "none" && $rsm->review_testable) {
            $rsm->apply_countexpr("=0");
            $rsm->apply_score_field($f, 0, 0, 4);
        } else if (preg_match('/\A([=!<>]=?|≠|≤|≥|)\s*([A-Z]|\d+|none)\z/si', $word, $m)) {
            if ($f->option_letter && !$srch->conf->opt("smartScoreCompare"))
                $m[1] = CountMatcher::flip_countexpr_string($m[1]);
            $score = self::parse_score($f, $m[2]);
            if ($score === false)
                return self::impossible_score_match($f);
            $rsm->apply_score_field($f, $score, 0, CountMatcher::$opmap[$m[1]]);
        } else if (preg_match('/\A(\d+|[A-Z]|none)\s*(|-|–|—|\.\.\.?|…)\s*(\d+|[A-Z]|none)\s*\z/si', $word, $m)) {
            $score1 = self::parse_score($f, $m[1]);
            $score2 = self::parse_score($f, $m[3]);
            if ($score1 === false || $score2 === false)
                return self::impossible_score_match($f);
            if ($score1 > $score2)
                list($score1, $score2) = [$score2, $score1];
            $precise = $m[2] !== ".." && $m[2] !== "..." && $m[2] !== "…";
            $rsm->apply_score_field($f, $score1, $score2, $precise ? 16 : 8);
        } else {             // XXX
            return new False_SearchTerm;
        }
        return new Review_SearchTerm($rsm);
    }


    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($revadj) {
            $revadj->promote_matcher($this->rsm);
        }
        return $this;
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
        $cexpr = $this->rsm->conservative_nonnegative_countexpr();
        if ($cexpr === ">=0" || $sqi->negated) {
            return "true";
        } else if ($this->rsm->review_type() == REVIEW_REQUEST) {
            return "exists (select * from ReviewRequest where paperId=Paper.paperId)";
        } else {
            $wheres = $this->rsm->useful_sqlexpr($sqi->user, "r") ? : "true";
            if ($cexpr === ">0") {
                return "exists (select * from PaperReview r where paperId=Paper.paperId and $wheres)";
            } else {
                return "(select count(*) from PaperReview r where paperId=Paper.paperId and $wheres)" . $cexpr;
            }
        }
    }
    function exec(PaperInfo $prow, PaperSearch $srch) {
        $this->rsm->prepare_reviews($prow);
        if ($this->rsm->review_testable && $srch->test_review) {
            return $this->rsm->test_review($srch->user, $prow, $srch->test_review, $srch);
        } else {
            if ($this->rsm->review_type() === REVIEW_REQUEST) {
                $rs = $prow->review_requests();
            } else {
                $rs = $prow->reviews_by_id();
            }
            $n = 0;
            foreach ($rs as $rrow) {
                $n += $this->rsm->test_review($srch->user, $prow, $rrow, $srch);
            }
            return $this->rsm->test_finish($n);
        }
    }
}
