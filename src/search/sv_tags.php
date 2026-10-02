<?php
// sv_tags.php -- HotCRP search term visitor
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Tags_SearchVisitor extends SearchVisitor {
    /** @var array<string,string> */
    private $tags = [];
    /** @var bool */
    private $fixed = false;

    function visit_children(SearchTerm $st) {
        return !($st instanceof Not_SearchTerm);
    }

    function visit_highlights(Then_SearchTerm $st) {
        return true;
    }

    function __invoke(SearchTerm $st, ...$args) {
        foreach ($st->get_float("tags") ?? [] as $tag) {
            $ltag = strtolower($tag);
            if (!isset($this->tags[$ltag])) {
                $this->tags[$ltag] = $tag;
            }
        }
    }

    /** @return list<string> */
    function list() {
        return array_values($this->tags);
    }
}
