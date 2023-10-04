<?php
// search/st_badge.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Badge_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string */
    private $word;

    /** @param string $word */
    function __construct(Contact $user, $word) {
        parent::__construct("badge");
        $this->user = $user;
        $this->word = $word;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return 'exists (select * from PaperTag where paperId=Paper.paperId)';
    }
    function test(PaperInfo $row, $xinfo) {
        $tags = $row->viewable_tags($this->user);
        foreach ($row->conf->tags()->badges($tags) as $tb) {
            if ($this->word === "any" || $this->word === $tb[1])
                return true;
        }
        return false;
    }
    function debug_json() {
        return ["type" => $this->type, "style" => $this->word];
    }
    function about() {
        return self::ABOUT_PAPER;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        if ($word === "any" || $word === "none") {
            return (new Badge_SearchTerm($srch->user, "any"))->negate_if($word === "none");
        } else if (($ks = $srch->conf->tags()->known_badge($word))) {
            return new Badge_SearchTerm($srch->user, $ks->style);
        } else {
            $srch->lwarning($sword, "<0>Unknown badge color ‘{$word}’");
            return new False_SearchTerm;
        }
    }
}
