<?php
// search/st_tag.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Tag_SearchTerm extends SearchTerm {
    private $tsm;
    private $tag1;
    private $tag1nz;

    function __construct(TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->tsm = $tsm;
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
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-|–|—)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)) {
            $tagword = $m[1];
            $value->add_value_matcher(new CountMatcher(">=$m[2]"));
            $value->add_value_matcher(new CountMatcher("<=$m[3]"));
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)
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

        // report errors, combine
        $term = (new Tag_SearchTerm($value))->negate_if($negated);
        if (!$negated && ($tagpat = $value->tag_patterns())) {
            $term->set_float("tags", $tagpat);
            if ($sword->kwdef->sorting) {
                $term->set_float("sort", [($revsort ? "-#" : "#") . $tagpat[0]]);
            }
        }
        if (!$negated && $sword->kwdef->is_hash && $value->single_tag()) {
            $term->tag1 = $value->single_tag();
            $term->tag1nz = false;
        }
        foreach ($value->errors() as $e) {
            $srch->warn($e);
        }
        return $term;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->tsm->test_empty()) {
            return "true";
        } else {
            $sql = $this->tsm->sqlexpr("PaperTag");
            return 'exists (select * from PaperTag where paperId=Paper.paperId' . ($sql ? " and $sql" : "") . ')';
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $ok = $this->tsm->test($row->searchable_tags($srch->user));
        if ($ok && $this->tag1 && !$this->tag1nz) {
            $this->tag1nz = $row->tag_value($this->tag1) != 0;
        }
        return $ok;
    }
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        $child = [];
        $tags = $row->searchable_tags($srch->user);
        // autosearch tags are special, splice in their search defs
        foreach ($srch->conf->tags()->filter("autosearch") as $dt) {
            if ($this->tsm->test(" {$dt->tag}#0")) {
                $newsrch = new PaperSearch($srch->user, $dt->autosearch);
                $newec = $newsrch->term()->compile_condition($row, $newsrch);
                if ($newec === null) {
                    return null;
                } else if ($newec === true) {
                    return true;
                } else if ($newec !== false) {
                    $child[] = $newec;
                }
                $tags = str_replace(" {$dt->tag}#0", "", $tags);
            }
        }
        // now complete
        if ($this->tsm->test($tags)) {
            return true;
        } else if (empty($child)) {
            return false;
        } else if (count($child) === 1) {
            return $child[0];
        } else {
            return (object) ["type" => "or", "child" => $child];
        }
    }
    function default_sorter($top, $thenmap, PaperSearch $srch) {
        if ($top && $this->tag1) {
            $dt = $srch->conf->tags()->check(TagInfo::base($this->tag1));
            if (($dt && $dt->order_anno) || $this->tag1nz) {
                $s = new ListSorter("#{$this->tag1}");
                $s->reverse = $dt && $dt->votish;
                $s->thenmap = $thenmap;
                return $s;
            }
        }
        return false;
    }
}

class Color_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC) {
            $dt = $srch->conf->tags();
            $tags = array_unique(array_merge(array_keys($dt->filter("colors")), $dt->known_styles()));
            $known_style = $dt->known_style($word) . "tag";
            foreach ($tags as $t) {
                if ($word === "any" || $word === "none") {
                } else if ($word === "color") {
                    if (!$dt->is_style($t, TagMap::STYLE_BG))
                        continue;
                } else {
                    if (array_search($known_style, $dt->styles($t, 0, false)) === false)
                        continue;
                }
                $tm->add_tag($t);
            }
        }
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_badge($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC && $srch->conf->tags()->has_badges) {
            if ($word === "any" || $word === "none") {
                $f = function ($t) { return !empty($t->badges); };
            } else if (preg_match(',\A(black|' . join("|", $srch->conf->tags()->canonical_badges()) . ')\z,', $word)
                       && !$sword->quoted) {
                $word = $word === "black" ? "normal" : $word;
                $f = function ($t) use ($word) {
                    return !empty($t->badges) && in_array($word, $t->badges);
                };
            } else if (($tx = $srch->conf->tags()->check_base($word))
                       && $tx->badges) {
                $f = function ($t) use ($tx) { return $t === $tx; };
            } else {
                $f = function ($t) { return false; };
            }
            foreach ($srch->conf->tags()->filter_by($f) as $tag => $tinfo) {
                $dt->add_tag($tag);
            }
        }
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_emoji($word, SearchWord $sword, PaperSearch $srch) {
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC && $srch->conf->tags()->has_emoji) {
            $xword = $word;
            if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0) {
                $xword = ":*:";
                $f = function ($t) { return !empty($t->emoji); };
            } else if (preg_match('{\A' . TAG_REGEX_NOTWIDDLE . '\z}', $word)) {
                if (!str_starts_with($xword, ":")) {
                    $xword = ":$xword";
                }
                if (!str_ends_with($xword, ":")) {
                    $xword = "$xword:";
                }
                $code = get($srch->conf->emoji_code_map(), $xword, false);
                $codes = [];
                if ($code !== false) {
                    $codes[] = $code;
                } else if (strpos($xword, "*") !== false) {
                    $re = "{\\A" . str_replace("\\*", ".*", preg_quote($xword)) . "\\z}";
                    foreach ($srch->conf->emoji_code_map() as $key => $code) {
                        if (preg_match($re, $key))
                            $codes[] = $code;
                    }
                }
                $f = function ($t) use ($codes) {
                    return !empty($t->emoji) && array_intersect($codes, $t->emoji);
                };
            } else {
                foreach ($srch->conf->emoji_code_map() as $key => $code) {
                    if ($code === $xword)
                        $tm->add_tag(":$key:");
                }
                $f = function ($t) use ($xword) {
                    return !empty($t->emoji) && in_array($xword, $t->emoji);
                };
            }
            $tm->add_tag($xword);
            foreach ($srch->conf->tags()->filter_by($f) as $tag => $tinfo) {
                $tm->add_tag($tag);
            }
        }
        return $tm->make_term()->negate_if(strcasecmp($word, "none") == 0);
    }
}
