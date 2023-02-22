<?php
// reviewfields/rf_discrete.php -- HotCRP search for discrete fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

/** @inherits ReviewFieldSearch<Discrete_ReviewField> */
class Discrete_ReviewFieldSearch extends ReviewFieldSearch {
    /** @var int */
    public $op;
    /** @var list<int> */
    public $scores;

    /** @param Discrete_ReviewField $rf
     * @param int $op
     * @param list<int> $scores */
    function __construct($rf, $op, $scores) {
        parent::__construct($rf);
        $this->op = $op;
        $this->scores = $scores;
        sort($this->scores);
    }

    function sqlexpr() {
        if ($this->scores[0] <= 0) {
            return null;
        } else if ($this->rf->main_storage) {
            $ce = count($this->scores) === 1 ? "=" : ">=";
            return $this->rf->main_storage . $ce . $this->scores[0];
        } else {
            return "sfields is not null";
        }
    }

    function prepare() {
        $this->finished = $this->op & CountMatcher::RELSPAN ? 3 : 0;
    }

    function test_review($user, $prow, $rrow) {
        $fv = $rrow->fval($this->rf);
        if (!in_array($fv ?? 0, $this->scores)) {
            if (($this->op & CountMatcher::RELALL) !== 0 && $fv !== null) {
                $this->finished = -1;
            }
            return false;
        }
        if ($this->finished !== 0) {
            if (($fv ?? 0) === $this->scores[0]) {
                $this->finished &= ~1;
            }
            if (($fv ?? 0) === $this->scores[count($this->scores) - 1]) {
                $this->finished &= ~2;
            }
        }
        return true;
    }


    // * (=|==|!|!=|<|<=|>|>=|≤|≥|≠) S
    // * (=|==|!|!=|≠) none
    // * comma-separated list of `none`, S, S-S, S…S, S..S, S...S, S–S, S—S,
    //   (if single-char) S+

    /** @param string $word
     * @param bool $has_count
     * @return array{int,list<int>} */
    static function parse_score_matcher($word, DiscreteValues_ReviewField $f, $has_count) {
        // common case: exact match of single score
        if (($sym = $f->find_symbol($word)) !== null) {
            return [CountMatcher::RELEQ, [$sym]];
        }
        // match with relation
        if (preg_match('/\A([=!<>]=?+|≠|≤|≥)\s*(.*)\z/', $word, $m)) {
            $op = CountMatcher::parse_relation($m[1]);
            if (($sym = $f->find_symbol($m[2])) === null
                || ($sym === 0 && $op !== CountMatcher::RELEQ && $op !== CountMatcher::RELNE)) {
                return null;
            }
            return self::expand_score_matcher($f, $op, $sym);
        }
        // comma-separated, possibly ranges
        $rel = CountMatcher::RELEQ;
        $r = [];
        while ($word !== "") {
            if (!preg_match('/\A([^-,.]*?)(\.\.\.?+|…|-|–|—|(?=,)|\z)([^,]*+)[,\s]*+(.*)\z/s', $word, $m)) {
                return null;
            }
            $sym = $f->find_symbol(trim($m[1]));
            if ($m[2] !== "") { // S1-S2, S1...S2
                $sym2 = $f->find_symbol(trim($m[3]));
                if (($sym ?? 0) === 0
                    || ($sym2 ?? 0) === 0
                    || ($f->flip ? $sym < $sym2 : $sym > $sym2)) {
                    return null;
                }
                if (!$has_count) {
                    $rel |= ReviewSearchMatcher::RELALL;
                    if (empty($r)
                        && $m[4] === ""
                        && ($m[2] === "-" || $m[2] === "–" || $m[2] === "—")
                        && $f->conf->opt("allowObsoleteScoreSearch")
                        && $f instanceof Score_ReviewField) {
                        $rel |= ReviewSearchMatcher::RELSPAN;
                    }
                }
                $x = range($f->flip ? $sym2 : $sym, $f->flip ? $sym : $sym2, 1);
                array_push($r, ...$x);
            } else if ($m[3] !== "") {
                return null;
            } else if ($sym !== null) { // S
                $r[] = $sym;
            } else if (!$f->is_single_character()) {
                return null;
            } else {
                preg_match_all('/\X/u', $m[1], $mm);
                $pnr = count($r);
                foreach ($mm[0] as $symtxt) {
                    if (($sym = $f->find_symbol($symtxt)) > 0)
                        $r[] = $sym;
                }
                if (empty($mm[0]) || count($r) !== $pnr + count($mm[0])) {
                    return null;
                }
                if (!$has_count) {
                    $rel |= ReviewSearchMatcher::RELALL;
                }
            }
            $word = $m[4];
        }

        return empty($r) ? null : [$rel, array_values(array_unique($r))];
    }

    /** @param int $op
     * @param int $fv
     * @return ?array{int,list<int>} */
    static function expand_score_matcher(DiscreteValues_ReviewField $f, $op, $fv) {
        if ($f->flip_relation()) {
            $op = CountMatcher::flip_relation($op);
        }
        if (($op === CountMatcher::RELLT && $fv <= 1)
            || ($op === CountMatcher::RELGT && $fv >= $f->nvalues())) {
            return null;
        }
        if ($op === CountMatcher::RELLT) {
            $fva = range(1, $fv - 1);
        } else if ($op === CountMatcher::RELLE) {
            $fva = range(1, $fv);
        } else if ($op === CountMatcher::RELGE) {
            $fva = range($fv, $f->nvalues());
        } else if ($op === CountMatcher::RELGT) {
            $fva = range($fv + 1, $f->nvalues());
        } else if ($op === CountMatcher::RELNE) {
            $x = range($fv === 0 || !$f->required ? 0 : 1, $f->nvalues());
            $fva = array_values(array_diff($x, [$fv]));
        } else {
            $fva = [$fv];
        }
        return [$op, $fva];
    }

    /** @return ?ReviewFieldSearch */
    static function parse_score(SearchWord $sword, Score_ReviewField $f,
                                ReviewSearchMatcher $rsm, PaperSearch $srch) {
        $word = SearchWord::unquote($sword->cword);
        list($op, $fva) = self::parse_score_matcher($word, $f, $rsm->has_count()) ?? [0, []];
        if ($op === 0) {
            return null;
        }
        return new Discrete_ReviewFieldSearch($f, $op | $rsm->rfop, $fva);
    }

    private static function impossible_score_match(Score_ReviewField $f, SearchWord $sword, PaperSearch $srch) {
        $r = $f->full_score_range();
        $srch->lwarning($sword, "<0>{$f->name} scores range from {$r[0]} to {$r[1]}");
    }
}
