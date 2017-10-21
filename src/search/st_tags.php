<?php
// search/st_tags.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class TagSearchMatcher {
    public $tags = [];
    public $index1 = null;
    public $index2 = null;
    private $_re;

    function include_twiddles(Contact $user) {
        $ntags = [];
        foreach ($this->tags as $t) {
            array_push($ntags, $t, "{$user->contactId}~$t");
            if ($user->privChair)
                $ntags[] = "~~$t";
        }
        $this->tags = $ntags;
        return $this;
    }
    function make_term() {
        if (empty($this->tags))
            return new False_SearchTerm;
        else
            return new Tag_SearchTerm($this);
    }
    function tagmatch_sql($table, Contact $user) {
        $x = [];
        foreach ($this->tags as $tm) {
            if (($starpos = strpos($tm, "*")) !== false || $tm === "any")
                return false;
            else
                $x[] = "$table.tag='" . sqlq($tm) . "'";
        }
        $q = "(" . join(" or ", $x) . ")";
        if ($this->index1)
            $q .= " and $table.tagIndex" . $this->index1->countexpr();
        if ($this->index2)
            $q .= " and $table.tagIndex" . $this->index2->countexpr();
        return $q;
    }
    function evaluate(Contact $user, $taglist) {
        if (!$this->_re) {
            $res = [];
            foreach ($this->tags as $tm)
                if (($starpos = strpos($tm, "*")) !== false)
                    $res[] = '(?!.*~)' . str_replace('\\*', '.*', preg_quote($tm));
                else if ($tm === "any" && $user->privChair)
                    $res[] = "(?:{$user->contactId}~.*|~~.*|(?!.*~).*)";
                else if ($tm === "any")
                    $res[] = "(?:{$user->contactId}~.*|(?!.*~).*)";
                else
                    $res[] = preg_quote($tm);
            $this->_re = '{\A(?:' . join("|", $res) . ')\z}i';
        }
        foreach (TagInfo::split_unpack($taglist) as $ti) {
            if (preg_match($this->_re, $ti[0])
                && (!$this->index1 || $this->index1->test($ti[1]))
                && (!$this->index2 || $this->index2->test($ti[1])))
                return true;
        }
        return false;
    }
}

class Tag_SearchTerm extends SearchTerm {
    private $tsm;

    function __construct(TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->tsm = $tsm;
    }
    static function expand($tagword, $allow_star, PaperSearch $srch) {
        // see also TagAssigner
        $ret = array("");
        $twiddle = strpos($tagword, "~");
        if ($srch->user->privChair
            && $twiddle > 0
            && !ctype_digit(substr($tagword, 0, $twiddle))) {
            $c = substr($tagword, 0, $twiddle);
            $ret = ContactSearch::make_pc($c, $srch->user)->ids;
            if (empty($ret))
                $srch->warn("“#" . htmlspecialchars($tagword) . "” doesn’t match a PC email.");
            else if (!$allow_star && count($ret) > 1) {
                $srch->warn("“#" . htmlspecialchars($tagword) . "” matches more than one PC member.");
                $ret = [];
            }
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0 && ($tagword === "~" || $tagword[1] !== "~"))
            $ret[0] = $srch->cid;

        $tagger = new Tagger($srch->user);
        $flags = Tagger::NOVALUE;
        if ($allow_star)
            $flags |= Tagger::ALLOWRESERVED | Tagger::ALLOWSTAR;
        if (!$tagger->check("#" . $tagword, $flags)) {
            $srch->warn($tagger->error_html);
            $ret = [];
        }
        foreach ($ret as &$x)
            $x .= $tagword;
        return $ret;
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
        if (str_starts_with($word, "#"))
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$srch->user->isPC) {
            $ranktag = "~" . $srch->conf->setting_data("tag_rank", "");
            if (!$srch->conf->setting("tag_rank")
                || substr($word, 0, strlen($ranktag)) !== $ranktag
                || (strlen($word) > strlen($ranktag)
                    && $word[strlen($ranktag)] !== "#"))
                return;
        }

        $value = new TagSearchMatcher;
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-|–|—)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)) {
            $tagword = $m[1];
            $value->index1 = new CountMatcher(">=$m[2]");
            $value->index2 = new CountMatcher("<=$m[3]");
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)
            && $m[1] !== "any" && $m[1] !== "none"
            && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $value->index1 = new CountMatcher(($m[3] ? : "=") . $m[4]);
        } else
            $tagword = $word;

        $value->tags = self::expand($tagword, !$sword->kwdef->sorting, $srch);
        if (count($value->tags) === 1 && $value->tags[0] === "none") {
            $value->tags[0] = "any";
            $negated = !$negated;
        }

        $term = $value->make_term()->negate_if($negated);
        if (!$negated && $sword->kwdef->sorting && !empty($value->tags))
            $term->set_float("sort", [($revsort ? "-#" : "#") . $value->tags[0]]);
        return $term;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Tag_" . count($sqi->tables);
        $tm_sql = $this->tsm->tagmatch_sql($thistab, $sqi->user);
        if ($tm_sql === false) {
            $thistab = "AnyTag";
            $tdef = ["left join", "PaperTag"];
        } else
            $tdef = ["left join", "PaperTag", $tm_sql];
        $sqi->add_table($thistab, $tdef);
        return "$thistab.tag is not null";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->tsm->evaluate($srch->user, $row->searchable_tags($srch->user));
    }
}

class Color_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC
            && preg_match(',\A(any|none|color|' . TagInfo::BASIC_COLORS_PLUS . ')\z,', $word)) {
            if ($word === "any" || $word === "none")
                $f = function ($t, $colors) { return !empty($colors); };
            else if ($word === "color")
                $f = function ($t, $colors) {
                    return !empty($colors)
                        && preg_match('/ (?:' . TagInfo::BASIC_COLORS_NOSTYLES . ') /',
                                      " " . join(" ", $colors) . " ");
                };
            else {
                $word = TagInfo::canonical_color($word);
                $f = function ($t, $colors) use ($word) {
                    return !empty($colors) && array_search($word, $colors) !== false;
                };
            }
            $tm->tags = $srch->conf->tags()->tags_with_color($f);
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_badge($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC && $srch->conf->tags()->has_badges) {
            if ($word === "any" || $word === "none")
                $f = function ($t) { return !empty($t->badges); };
            else if (preg_match(',\A(black|' . TagInfo::BASIC_BADGES . ')\z,', $word)
                     && !$sword->quoted) {
                $word = $word === "black" ? "normal" : $word;
                $f = function ($t) use ($word) {
                    return !empty($t->badges) && in_array($word, $t->badges);
                };
            } else if (($tx = $srch->conf->tags()->check_base($word))
                     && $tx->badges)
                $f = function ($t) use ($tx) { return $t === $tx; };
            else
                $f = function ($t) { return false; };
            $tm->tags = array_keys($srch->conf->tags()->filter_by($f));
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_emoji($word, SearchWord $sword, PaperSearch $srch) {
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC && $srch->conf->tags()->has_emoji) {
            $xword = $word;
            if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0) {
                $xword = ":*:";
                $f = function ($t) { return !empty($t->emoji); };
            } else if (preg_match('{\A' . TAG_REGEX_NOTWIDDLE . '\z}', $word)) {
                if (!str_starts_with($xword, ":"))
                    $xword = ":$xword";
                if (!str_ends_with($xword, ":"))
                    $xword = "$xword:";
                $code = get($srch->conf->emoji_code_map(), $xword, false);
                $codes = [];
                if ($code !== false)
                    $codes[] = $code;
                else if (strpos($xword, "*") !== false) {
                    $re = "{\\A" . str_replace("\\*", ".*", preg_quote($xword)) . "\\z}";
                    foreach ($srch->conf->emoji_code_map() as $key => $code)
                        if (preg_match($re, $key))
                            $codes[] = $code;
                }
                $f = function ($t) use ($codes) {
                    return !empty($t->emoji) && array_intersect($codes, $t->emoji);
                };
            } else {
                foreach ($srch->conf->emoji_code_map() as $key => $code)
                    if ($code === $xword)
                        $tm->tags[] = ":$key:";
                $f = function ($t) use ($xword) {
                    return !empty($t->emoji) && in_array($xword, $t->emoji);
                };
            }
            $tm->tags[] = $xword;
            $tm->tags = array_merge($tm->tags, array_keys($srch->conf->tags()->filter_by($f)));
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if(strcasecmp($word, "none") == 0);
    }
}
