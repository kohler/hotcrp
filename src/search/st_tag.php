<?php
// search/st_tag.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Tag_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var TagSearchMatcher */
    private $tsm;
    /** @var bool */
    private $allow_default_sort = false;

    function __construct(Contact $user, TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->user = $user;
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
        $value = new TagSearchMatcher($srch->user);
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-|–|—)(-?(?:\.\d+|\d+\.?\d*))\z/s', $word, $m)) {
            $tagword = $m[1];
            $value->add_value_matcher(new CountMatcher(">=$m[2]"));
            $value->add_value_matcher(new CountMatcher("<=$m[3]"));
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/s', $word, $m)
                   && $m[1] !== "any"
                   && $m[1] !== "none"
                   && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $value->add_value_matcher(new CountMatcher(($m[3] ? : "=") . $m[4]));
        } else {
            $tagword = $word;
        }

        // match tag body
        $value->add_check_tag($tagword, !$sword->kwdef->sorting);

        // expand automatic tags if requested
        $allterms = [];
        if ($srch->expand_automatic
            && ($dt = $srch->conf->tags())->has_automatic) {
            $nomatch = [];
            foreach ($dt->filter("automatic") as $t) {
                if ($value->test_ignore_value(" {$t->tag}#")
                    && $t->automatic_formula_expression() === "0") {
                    $nomatch[] = " " . preg_quote($t->tag) . "#";
                    if ($value->test_value(0.0)) {
                        $asrch = new PaperSearch($srch->conf->root_user(), ["q" => $t->automatic_search(), "t" => "all"]);
                        $allterms[] = $asrch->term();
                    }
                }
            }
            if (!empty($nomatch)) {
                $value->set_tag_exclusion_regex(join("|", $nomatch));
            }
        }

        // add value term
        if (!$value->is_empty_after_exclusion()) {
            $allterms[] = $term = new Tag_SearchTerm($srch->user, $value);
            if (!$negated && ($tagpat = $value->tag_patterns())) {
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

        // return
        foreach ($value->error_texts() as $e) {
            $srch->lwarning($sword, "<5>$e");
        }
        return SearchTerm::combine("or", $allterms)->negate_if($negated);
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
        return $this->tsm->is_sqlexpr_precise() && $this->user->is_site_contact;
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
        return $this->tsm->test($row->searchable_tags($this->user));
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
