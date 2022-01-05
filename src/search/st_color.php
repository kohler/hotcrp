<?php
// search/st_color.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Color_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        // XXX does not correctly handle tag patterns
        $word = strtolower($word);
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC) {
            $dt = $srch->conf->tags();
            $tags = array_unique(array_merge(array_keys($dt->filter("colors")), $dt->known_styles()));
            $known_style = $dt->known_style($word) . "tag";
            foreach ($tags as $t) {
                if ($word === "color") {
                    if (!$dt->is_style($t, TagMap::STYLE_BG)) {
                        continue;
                    }
                } else if ($word !== "any" && $word !== "none") {
                    if (array_search($known_style, $dt->styles($t, 0, false)) === false) {
                        continue;
                    }
                }
                $tm->add_tag($t);
            }
        }
        return (new Tag_SearchTerm($srch->user, $tm))->negate_if($word === "none");
    }
    static function parse_badge($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC && $srch->conf->tags()->has_badges) {
            if ($word === "any" || $word === "none") {
                $f = function ($t) { return !empty($t->badges); };
            } else if (preg_match('{\A(black|' . join("|", $srch->conf->tags()->canonical_badges()) . ')\z}s', $word)
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
                $tm->add_tag($tag);
            }
        }
        return (new Tag_SearchTerm($srch->user, $tm))->negate_if($word === "none");
    }
    static function parse_emoji($word, SearchWord $sword, PaperSearch $srch) {
        $tm = new TagSearchMatcher($srch->user);
        $tm->set_include_twiddles(true);
        if ($srch->user->isPC && $srch->conf->tags()->has_emoji) {
            $xword = $word;
            if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0) {
                $xword = ":*:";
                $f = function ($t) { return !empty($t->emoji); };
            } else if (preg_match('{\A' . TAG_REGEX_NOTWIDDLE . '\z}s', $word)) {
                if (!str_starts_with($xword, ":")) {
                    $xword = ":$xword";
                }
                if (!str_ends_with($xword, ":")) {
                    $xword = "$xword:";
                }
                $code = ($srch->conf->emoji_code_map())[$xword] ?? false;
                $codes = [];
                if ($code !== false) {
                    $codes[] = $code;
                } else if (strpos($xword, "*") !== false) {
                    $re = "{\\A" . str_replace("\\*", ".*", preg_quote($xword)) . "\\z}s";
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
        return (new Tag_SearchTerm($srch->user, $tm))->negate_if(strcasecmp($word, "none") == 0);
    }
}
