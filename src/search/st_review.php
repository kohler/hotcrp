<?php
// search/st_review.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewSearchMatcher extends ContactCountMatcher {
    const COMPLETE = 1;
    const INCOMPLETE = 2;
    const INPROGRESS = 4;
    const APPROVABLE = 8;

    private $review_type = 0;
    public $completeness = 0;
    private $fieldsql = null;
    public $view_score = null;
    public $round = null;
    public $tokens = null;
    public $wordcountexpr = null;
    private $rfield = null;
    private $rfield_score;
    private $rfield_text;
    private $requester;
    private $ratings;

    function __construct($countexpr = null, $contacts = null) {
        parent::__construct($countexpr, $contacts);
    }
    function only_pc() {
        return $this->review_type >= REVIEW_PC;
    }
    function review_type() {
        return $this->review_type;
    }
    function apply_review_type($word, $allow_pc = false) {
        if ($word === "meta")
            $this->review_type = REVIEW_META;
        else if ($word === "pri" || $word === "primary")
            $this->review_type = REVIEW_PRIMARY;
        else if ($word === "sec" || $word === "secondary")
            $this->review_type = REVIEW_SECONDARY;
        else if ($word === "optional")
            $this->review_type = REVIEW_PC;
        else if ($allow_pc && ($word === "pc" || $word === "pcre" || $word === "pcrev"))
            $this->review_type = REVIEW_PC;
        else if ($word === "ext" || $word === "external")
            $this->review_type = REVIEW_EXTERNAL;
        else
            return false;
        return true;
    }
    function apply_completeness($word) {
        if ($word === "complete" || $word === "done")
            $this->completeness |= self::COMPLETE;
        else if ($word === "incomplete")
            $this->completeness |= self::INCOMPLETE;
        else if ($word === "approvable")
            $this->completeness |= self::APPROVABLE;
        else if ($word === "draft" || $word === "inprogress" || $word === "in-progress" || $word === "partial")
            $this->completeness |= self::INPROGRESS;
        else
            return false;
        return true;
    }
    function apply_round($word, Conf $conf) {
        $round = $conf->round_number($word, false);
        if ($round !== false) {
            $this->round[] = $round;
            return true;
        } else
            return false;
    }
    function apply_requester($cid) {
        $this->requester = $cid;
    }
    function adjust_rounds($rounds) {
        if ($this->round === null)
            $this->round = $rounds;
    }
    function adjust_ratings(ReviewRating_SearchAdjustment $rrsa) {
        if ($this->ratings === null)
            $this->ratings = $rrsa;
    }
    function make_field_term(ReviewField $field, $value) {
        assert(!$this->rfield && !$this->fieldsql);
        if (!$this->completeness)
            $this->completeness = self::COMPLETE;
        if ($field->has_options) {
            if ($field->main_storage)
                $this->fieldsql = $field->main_storage . $value;
            else {
                $this->rfield = $field;
                $this->rfield_score = new CountMatcher($value);
                if (!$this->rfield_score->test(0))
                    $this->fieldsql = "sfields is not null";
            }
        } else {
            if ($field->main_storage && is_bool($value)) {
                if ($value)
                    $this->fieldsql = $field->main_storage . "!=''";
                else
                    $this->fieldsql = "coalesce(" . $field->main_storage . ",'')=''";
            } else {
                $this->rfield = $field;
                $this->rfield_text = $value;
                if ($value)
                    $this->fieldsql = "tfields is not null";
            }
        }
        return new Review_SearchTerm($this);
    }
    function useful_sqlexpr($table_name) {
        $where = [];
        if ($this->completeness & ReviewSearchMatcher::APPROVABLE)
            $where[] = "(reviewSubmitted is null and timeApprovalRequested>0)";
        if ($this->has_contacts()) {
            $cm = $this->contact_match_sql("contactId");
            if ($this->tokens)
                $cm = "($cm or reviewToken in (" . join(",", $this->tokens) . "))";
            $where[] = $cm;
        }
        if ($this->fieldsql)
            $where[] = $this->fieldsql;
        if ($this->ratings && $this->ratings->must_exist())
            $where[] = "exists (select * from ReviewRating where paperId={$table_name}.paperId and reviewId={$table_name}.reviewId)";
        if ($this->requester)
            $where[] = "requestedBy=" . $this->requester;
        if (empty($where))
            return false;
        else
            return join(" and ", $where);
    }
    function prepare_reviews(PaperInfo $prow) {
        if ($this->wordcountexpr)
            $prow->ensure_review_word_counts();
        if (($this->rfield && !$this->rfield->has_options)
            || $this->ratings)
            $prow->ensure_full_reviews();
        else if ($this->rfield)
            $prow->ensure_review_score($this->rfield);
    }
    function test_review(Contact $user, PaperInfo $prow, ReviewInfo $rrow, PaperSearch $srch) {
        if ($this->review_type
            && $this->review_type !== $rrow->reviewType)
            return false;
        if ($this->completeness) {
            if ((($this->completeness & self::COMPLETE)
                 && !$rrow->reviewSubmitted)
                || (($this->completeness & self::INCOMPLETE)
                    && !$rrow->reviewNeedsSubmit)
                || (($this->completeness & self::INPROGRESS)
                    && ($rrow->reviewSubmitted || !$rrow->reviewModified))
                || (($this->completeness & self::APPROVABLE)
                    && ($rrow->reviewSubmitted
                        || $rrow->timeApprovalRequested <= 0
                        || ($rrow->requestedBy != $user->contactId
                            && !$user->allow_administer($prow)))))
                return false;
        }
        if ($this->round !== null
            && !in_array($rrow->reviewRound, $this->round))
            // XXX can_view_review_round?
            return false;
        if ($this->fieldsql || $this->rfield || $this->wordcountexpr || $this->ratings
            ? !$user->can_view_review($prow, $rrow)
            : !$user->can_view_review_assignment($prow, $rrow))
            return false;
        if ($this->has_contacts()) {
            if (!$this->test_contact($rrow->contactId)
                && (!$this->tokens || !in_array($rrow->reviewToken, $this->tokens)))
                return false;
            if (!$user->can_view_review_identity($prow, $rrow))
                return false;
        } else if ($rrow->reviewSubmitted <= 0 && $rrow->reviewNeedsSubmit <= 0)
            // don't count delegated reviews unless contacts given
            return false;
        if ($this->wordcountexpr
            && !$this->wordcountexpr->test($rrow->reviewWordCount))
            return false;
        if ($this->requester !== null
            && ($rrow->requestedBy != $this->requester
                || !$user->can_view_review_requester($prow, $rrow)))
            return false;
        if ($this->ratings !== null
            && !$this->ratings->test($user, $prow, $rrow))
            return false;
        if ($this->view_score !== null
            && $this->view_score <= $user->view_score_bound($prow, $rrow))
            return false;
        if ($this->rfield) {
            $fid = $this->rfield->id;
            if ($this->rfield->has_options) {
                if (!$this->rfield_score->test((int) get($rrow, $fid, 0)))
                    return false;
            } else {
                if ($this->rfield_text === false) {
                    if (isset($rrow->$fid) && $rrow->$fid !== "")
                        return false;
                } else if (!isset($rrow->$fid) || $rrow->$fid === "") {
                    return false;
                } else if ($this->rfield_text !== true) {
                    if (!$rrow->field_match_pregexes($this->rfield_text, $fid))
                        return false;
                }
            }
        }
        return true;
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
    }
    static function keyword_factory($keyword, Conf $conf, $kwfj, $m) {
        $c = str_replace("-", "", $m[1]);
        return (object) [
            "name" => $keyword, "parse_callback" => "Review_SearchTerm::parse",
            "retype" => str_replace("-", "", $m[2]),
            "recompleteness" => get(self::$recompleteness_map, $c, $c),
            "has" => ">0"
        ];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if ($sword->kwdef->retype)
            $rsm->apply_review_type($sword->kwdef->retype);
        if ($sword->kwdef->recompleteness)
            $rsm->apply_completeness($sword->kwdef->recompleteness);

        $qword = $sword->qword;
        $quoted = false;
        $contacts = null;
        $wordcount = null;
        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/s';
        while ($qword !== "") {
            if (preg_match('/\A(.+?)' . $tailre, $qword, $m)
                && ($rsm->apply_review_type($m[1])
                    || $rsm->apply_completeness($m[1])
                    || $rsm->apply_round($m[1], $srch->conf))) {
                $qword = $m[2];
            } else if (preg_match('/\A((?:[=!<>]=?|≠|≤|≥|)\d+|any|none|yes|no)' . $tailre, $qword, $m)) {
                $count = PaperSearch::unpack_comparison($m[1], false);
                $rsm->set_countexpr($count[1]);
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

        $rsm->wordcountexpr = $wordcount;
        if ($wordcount && $rsm->completeness === 0)
            $rsm->apply_completeness("complete");
        if ($contacts) {
            $rsm->set_contacts($srch->matching_reviewers($contacts, $quoted, $rsm->only_pc()));
            if (strcasecmp($contacts, "me") == 0)
                $rsm->tokens = $srch->reviewer_user()->review_tokens();
        }
        return new Review_SearchTerm($rsm);
    }

    static function review_field_factory($keyword, Conf $conf, $kwfj, $m) {
        $f = $conf->find_all_fields($keyword);
        if (count($f) == 1 && $f[0] instanceof ReviewField)
            return (object) [
                "name" => $keyword, "parse_callback" => "Review_SearchTerm::parse_review_field",
                "review_field" => $f[0], "has" => "any"
            ];
        else
            return null;
    }
    static function parse_review_field($word, SearchWord $sword, PaperSearch $srch) {
        $f = $sword->kwdef->review_field;
        $rsm = new ReviewSearchMatcher(">0");
        $rsm->view_score = $f->view_score;

        $contactword = "";
        while (preg_match('/\A(.+?)([:=<>!]|≠|≤|≥)(.*)\z/s', $word, $m)
               && !ctype_digit($m[1])) {
            if ($rsm->apply_review_type($m[1])
                || $rsm->apply_completeness($m[1])
                || $rsm->apply_round($m[1], $srch->conf))
                /* OK */;
            else
                $rsm->set_contacts($srch->matching_reviewers($m[1], $sword->quoted, false));
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            $contactword .= $m[1] . ":";
        }

        if ($f->has_options)
            return self::parse_score_field($rsm, $word, $f, $srch);
        else {
            if ($word === "any" && !$sword->quoted) {
                return $rsm->make_field_term($f, true);
            } else if ($word === "none" && !$sword->quoted) {
                return $rsm->make_field_term($f, false);
            } else {
                return $rsm->make_field_term($f, Text::star_text_pregexes($word, $sword->quoted));
            }
        }
    }
    private static function impossible_score_match(ReviewField $f) {
        $t = new False_SearchTerm;
        $r = $f->full_score_range();
        $t->set_float("contradiction_warning", "$f->name_html scores range from $r[0] to $r[1].");
        $t->set_float("used_revadj", true);
        return $t;
    }
    private static function parse_score_field(ReviewSearchMatcher $rsm, $word, ReviewField $f, PaperSearch $srch, $noswitch = false) {
        if ($word === "any")
            $valexpr = ">0";
        else if ($word === "none")
            $valexpr = "=0";
        else if (preg_match('/\A(\d*?)\s*([=!<>]=?|≠|≤|≥)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
            if ($m[1] === "")
                $m[1] = "1";
            $m[2] = CountMatcher::canonical_comparator($m[2]);
            if ($f->option_letter != (ctype_digit($m[3]) === false))
                return self::impossible_score_match($f);
            $score = $m[3];
            if ($f->option_letter) {
                if (!$srch->conf->opt("smartScoreCompare") || $noswitch) {
                    // switch meaning of inequality
                    if ($m[2][0] === "<")
                        $m[2] = ">" . substr($m[2], 1);
                    else if ($m[2][0] === ">")
                        $m[2] = "<" . substr($m[2], 1);
                }
                if (ctype_alpha($score))
                    $score = $f->option_letter - ord(strtoupper($score));
            }
            $min = $f->allow_empty ? 0 : 1;
            if (($score < $min && ($m[2][0] === "<" || $m[2] === "="))
                || ($score == $min && $m[2] === "<")
                || ($score == count($f->options) && $m[2] === ">")
                || ($score > count($f->options) && ($m[2][0] === ">" || $m[2] === "=")))
                return self::impossible_score_match($f);
            $rsm->set_countexpr((int) $m[1] ? ">=" . $m[1] : "=0");
            $valexpr = $m[2] . $score;
        } else if ($f->option_letter
                   ? preg_match('/\A\s*([A-Za-z])\s*(|-|–|—|\.\.\.?)\s*([A-Za-z])\s*\z/s', $word, $m)
                   : preg_match('/\A\s*(\d+)\s*(-|–|—|\.\.\.?)\s*(\d+)\s*\z/s', $word, $m)) {
            $qo = array();
            if ($m[2] === "-" || $m[2] === "") {
                $qo[] = self::parse_score_field(clone $rsm, $m[1], $f, $srch);
                $qo[] = self::parse_score_field(clone $rsm, $m[3], $f, $srch);
            } else
                $qo[] = self::parse_score_field(clone $rsm, ">=$m[1]", $f, $srch, true);
            $t = self::parse_score_field(clone $rsm, "<$m[1]", $f, $srch, true);
            if (!($t instanceof False_SearchTerm))
                $qo[] = SearchTerm::make_not($t);
            $t = self::parse_score_field(clone $rsm, ">$m[3]", $f, $srch, true);
            if (!($t instanceof False_SearchTerm))
                $qo[] = SearchTerm::make_not($t);
            return SearchTerm::make_op("and", $qo);
        } else              // XXX
            return new False_SearchTerm;
        return $rsm->make_field_term($f, $valexpr);
    }


    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($revadj)
            $revadj->promote_matcher($this->rsm);
        return $this;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_review_signature_columns();
        if ($this->rsm->wordcountexpr)
            $sqi->add_review_word_count_columns();

        if (($wheres = $this->rsm->useful_sqlexpr("r"))) {
            $thistab = "Reviews_" . count($sqi->tables);
            $sqi->add_table($thistab, ["left join", "(select r.paperId, count(r.reviewId) count from PaperReview r where $wheres group by paperId)"]);
        } else
            $thistab = "R_sigs";

        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        return "coalesce($thistab.count,0)" . $this->rsm->conservative_nonnegative_countexpr();
    }
    function exec(PaperInfo $prow, PaperSearch $srch) {
        $n = 0;
        $this->rsm->prepare_reviews($prow);
        foreach ($prow->reviews_by_id() as $rrow)
            $n += $this->rsm->test_review($srch->user, $prow, $rrow, $srch);
        return $this->rsm->test($n);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($top) {
            $v = $this->rsm->contact_set();
            $srch->mark_context_user(count($v) == 1 ? $v[0] : null);
        }
    }
}
