<?php
// search/st_review.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
            "rematch" => [$t, self::$recompleteness_map[$c] ?? $c],
            "reblank" => $c === "" && $t === "",
            "has" => ">0",
            "needs_relation" => true
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
            if ($rsm->apply_review_word($c, $srch->conf)) {
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
        foreach ($sword->kwdef->rematch ?? [] as $m) {
            if ($m)
                $rsm->apply_review_word($m, $srch->conf);
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
                "has" => "any",
                "needs_relation" => true
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
                || $rsm->apply_review_word($part, $srch->conf)) {
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

    function paper_requirements(&$options) {
        $options["reviewSignatures"] = true;
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
    function about() {
        return $this->rsm->has_count() ? self::ABOUT_REVIEW_SET : self::ABOUT_REVIEW;
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
            } else if ($st instanceof And_SearchTerm) {
                $mx = ~0;
                foreach ($args as $m) {
                    $mx &= $m ?? ~0;
                }
                return $mx;
            } else if ($st instanceof Or_SearchTerm
                       || $st instanceof Then_SearchTerm) {
                return Review_SearchTerm::round_mask_combine($args, false);
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
