<?php
// searchvisitor.php -- HotCRP paper search terms
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

abstract class SearchVisitor {
    function visit_children(SearchTerm $st) {
        return true;
    }
    function visit_highlights(Then_SearchTerm $st) {
        return false;
    }
    abstract function __invoke(SearchTerm $st, ...$args);
}
