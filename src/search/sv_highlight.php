<?php
// sv_highlight.php -- HotCRP search term visitor
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Highlight_SearchVisitor extends SearchVisitor {
    /** @var array<string,TextPregexes> */
    private $hlm = [];

    function visit_children(SearchTerm $st) {
        return !($st instanceof Not_SearchTerm);
    }

    function __invoke(SearchTerm $st, ...$args) {
        foreach ($st->float_map() as $k => $v) {
            if (!str_starts_with($k, "fhl:")) {
                continue;
            }
            $field = substr($k, 4);
            $hl = &$this->hlm[$field];
            if ($hl) {
                $hl = clone $hl;
                $hl->merge_any($v);
            } else {
                $hl = $v;
            }
        }
    }

    /** @param string $field
     * @return bool */
    function has($field) {
        return isset($this->hlm[$field]);
    }

    /** @param string $field
     * @return ?TextPregexes */
    function get($field) {
        return $this->hlm[$field] ?? null;
    }

    /** @return array<string,TextPregexes> */
    function map() {
        return $this->hlm;
    }
}
