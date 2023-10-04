<?php
// search/st_color.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Color_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string */
    private $word;

    /** @param string $word */
    function __construct(Contact $user, $word) {
        parent::__construct("style");
        $this->user = $user;
        $this->word = $word;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return 'exists (select * from PaperTag where paperId=Paper.paperId)';
    }
    function test(PaperInfo $row, $xinfo) {
        $tags = $row->viewable_tags($this->user);
        $styles = $row->conf->tags()->styles($tags, 0, true);
        return !empty($styles)
            && ($this->word === "any" || in_array($this->word, $styles));
    }
    function debug_json() {
        return ["type" => $this->type, "style" => $this->word];
    }
    function about() {
        return self::ABOUT_PAPER;
    }

    static function parse_style($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        if ($word === "any" || $word === "none") {
            return (new Color_SearchTerm($srch->user, "any"))->negate_if($word === "none");
        } else if ($word === "color") {
            return new Color_SearchTerm($srch->user, "tagbg");
        } else if (($ks = $srch->conf->tags()->known_style($word))) {
            return new Color_SearchTerm($srch->user, "tag-" . $ks->style);
        } else {
            $srch->lwarning($sword, "<0>Unknown style ‘{$word}’");
            return new False_SearchTerm;
        }
    }
    static function parse_color($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        if ($word === "any" || $word === "color" || $word === "none") {
            return (new Color_SearchTerm($srch->user, "tagbg"))->negate_if($word === "none");
        } else if (($ks = $srch->conf->tags()->known_style($word))) {
            return new Color_SearchTerm($srch->user, $ks->style);
        } else {
            $srch->lwarning($sword, "<0>Unknown color ‘{$word}’");
            return new False_SearchTerm;
        }
    }
}
