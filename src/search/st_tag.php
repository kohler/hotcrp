<?php
// search/st_tag.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Tag_SearchTerm extends SearchTerm {
    /** @var TagSearchMatcher */
    public $tsm;
    /** @var bool */
    private $allow_default_sort = false;

    function __construct(TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->tsm = $tsm;
    }

    function set_allow_default_sort() {
        $this->allow_default_sort = true;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $negated = $sword->kwdef->negated;
        $revsort = $sword->kwdef->sorting && $sword->kwdef->revsort;
        if (str_starts_with($word, "-")) {
            if ($sword->kwdef->sorting) {
                $revsort = !$revsort;
                $word = substr($word, 1);
            } else if (!$negated) {
                $negated = true;
                $word = substr($word, 1);
            }
        }
        if (str_starts_with($word, "#")) {
            $word = substr($word, 1);
        }

        // check value matchers
        $tsm = new TagSearchMatcher($srch->user);
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-|–|—)(-?(?:\.\d+|\d+\.?\d*))\z/s', $word, $m)) {
            $tagword = $m[1];
            $tsm->add_value_matcher(new CountMatcher(">=$m[2]"));
            $tsm->add_value_matcher(new CountMatcher("<=$m[3]"));
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/s', $word, $m)
                   && $m[1] !== "any"
                   && $m[1] !== "none"
                   && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $tsm->add_value_matcher(new CountMatcher(($m[3] ? : "=") . $m[4]));
        } else {
            $tagword = $word;
        }

        // match tag body
        $tsm->add_check_tag($tagword, !$sword->kwdef->sorting);

        // expand automatic tags if requested
        $allterms = [];
        if ($srch->expand_automatic > 0
            && ($dt = $srch->conf->tags())->has_automatic) {
            $nomatch = [];
            foreach ($dt->filter("automatic") as $t) {
                if (!$tsm->test_ignore_value(" {$t->tag}#")) {
                    continue;
                }
                if ($t->automatic_formula_expression() !== "0") {
                    error_log("{$srch->conf->dbname}: refusing to expand_automatic #{$t->tag} for {$sword->qword} (unimplemented)");
                    continue;
                }
                if ($srch->expand_automatic >= 10) {
                    $srch->warning_at("circular_automatic");
                    continue;
                }
                $nomatch[] = " " . preg_quote($t->tag) . "#";
                if ($tsm->test_value(0.0)) {
                    $asrch = new PaperSearch($srch->conf->root_user(), ["q" => $t->automatic_search(), "t" => "all"]);
                    $asrch->set_expand_automatic($srch->expand_automatic + 1);
                    $allterms[] = $asrch->full_term();
                    if ($asrch->has_problem_at("circular_automatic")) {
                        $srch->warning_at("circular_automatic");
                        if ($srch->expand_automatic === 1) {
                            $srch->lwarning($sword, "<0>Circular reference in automatic tag #{$t->tag}");
                        }
                    }
                }
            }
            if (!empty($nomatch)) {
                $tsm->set_tag_exclusion_regex(join("|", $nomatch));
            }
        }

        // add value term
        if (!$tsm->is_empty_after_exclusion()) {
            $allterms[] = $term = new Tag_SearchTerm($tsm);
            if (!$negated && ($tagpat = $tsm->tag_patterns())) {
                $term->set_float("tags", $tagpat);
                if ($sword->kwdef->sorting) {
                    $revanno = $revsort ? "-" : "";
                    $term->add_view_anno("sort:{$revanno}#{$tagpat[0]}", $sword);
                }
            }
            if (!$negated && $sword->kwdef->is_hash) {
                $term->set_allow_default_sort();
            }
        }

        foreach ($tsm->error_texts() as $e) {
            $srch->lwarning($sword, "<5>{$e}");
        }
        return SearchTerm::combine("or", ...$allterms)->negate_if($negated);
    }
    const SQLEXPR_PREFIX = 'exists (select * from PaperTag where paperId=Paper.paperId';
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->tsm->test_empty()) {
            return "true";
        } else {
            $sql = $this->tsm->sqlexpr("PaperTag");
            return self::SQLEXPR_PREFIX . ($sql ? " and $sql" : "") . ')';
        }
    }
    function is_sqlexpr_precise() {
        return $this->tsm->is_sqlexpr_precise() && $this->tsm->user->is_root_user();
    }
    /** @param non-empty-list<string> $ff
     * @return string */
    static function combine_sqlexpr($ff) {
        if (count($ff) === 1) {
            return $ff[0];
        } else {
            $x = [];
            foreach ($ff as $f) {
                if ($f === "true" || !str_starts_with($f, self::SQLEXPR_PREFIX)) {
                    return "true";
                } else if ($f === self::SQLEXPR_PREFIX . ")") {
                    return $f;
                } else {
                    $x[] = substr($f, strlen(self::SQLEXPR_PREFIX) + 5, -1);
                }
            }
            return self::SQLEXPR_PREFIX . " and (" . join(" or ", $x) . "))";
        }
    }
    function test(PaperInfo $row, $xinfo) {
        return $this->tsm->test($row->searchable_tags($this->tsm->user));
    }
    /** @param PaperList $pl
     * @param string $tag
     * @param ?TagInfo $dt
     * @return PaperColumn */
    private function _make_default_sort_column($pl, $tag, $dt) {
        $xjs = Tag_PaperColumn::expand("#{$tag}", $pl->user, (object) [], ["#{$tag}", "#", $tag]);
        assert(count($xjs) === 1 && $xjs[0]->function === "+Tag_PaperColumn");
        return PaperColumn::make($pl->conf, $xjs[0], $dt && $dt->votish ? ["reverse"] : []);
    }
    function default_sort_column($top, $pl) {
        if (!$top
            || !$this->allow_default_sort
            || !($tag = $this->tsm->single_tag())) {
            return null;
        }
        if (($dt = $pl->conf->tags()->check(Tagger::base($tag)))
            && $dt->order_anno) {
            return $this->_make_default_sort_column($pl, $tag, $dt);
        }
        foreach ($pl->rowset() as $prow) {
            if ($prow->tag_value($tag) != 0) {
                return $this->_make_default_sort_column($pl, $tag, $dt);
            }
        }
        return null;
    }
    function debug_json() {
        return ["type" => $this->type, "tag_regex" => $this->tsm->regex()];
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
