<?php
// search/st_emoji.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Emoji_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ?list<string> */
    private $codes;

    /** @param ?list<string> $codes */
    function __construct(Contact $user, $codes = null) {
        parent::__construct("emoji");
        $this->user = $user;
        $this->codes = $codes;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return 'exists (select * from PaperTag where paperId=Paper.paperId)';
    }
    function test(PaperInfo $row, $xinfo) {
        $tags = $row->viewable_tags($this->user);
        foreach ($row->conf->tags()->emoji($tags) as $code => $ts) {
            if ($this->codes === null || in_array($code, $this->codes))
                return true;
        }
        return false;
    }
    function debug_json() {
        return ["type" => $this->type, "match" => $this->codes];
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        if ($word === "any" || $word === "none") {
            return (new Emoji_SearchTerm($srch->user))->negate_if($word === "none");
        }
        $exact = str_starts_with($word, ":") && str_ends_with($word, ":");
        if (str_starts_with($word, ":")) {
            $word = substr($word, 1);
        }
        if (str_ends_with($word, ":")) {
            $word = substr($word, 0, -1);
        }
        $star = strpos($word, "*") !== false;
        $regex = '/\\A' . str_replace("\\*", ".*", preg_quote($word, "/")) . '\\z/i';
        $ecmap = $srch->conf->emoji_code_map();
        $wantcode = $ecmap[$word] ?? $word;
        $codes = [];
        foreach ($ecmap as $key => $code) {
            if (($wantcode !== null && strpos($code, $wantcode) !== false)
                || ($exact ? $key === $word : strpos($key, $word) !== false)
                || ($star && preg_match($regex, $key)))
                $codes[] = $code;
        }
        if (!empty($codes)) {
            return new Emoji_SearchTerm($srch->user, $codes);
        } else {
            $srch->lwarning($sword, "<0>No emoji match ‘{$sword->word}’");
            return new False_SearchTerm;
        }
    }
}
