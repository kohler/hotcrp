<?php
// pc_desirability.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Desirability_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->is_manager())
            return false;
        if ($visible)
            $pl->qopts["allReviewerPreference"] = true;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $ad = $a->desirability();
        $bd = $b->desirability();
        return $bd < $ad ? -1 : ($bd > $ad ? 1 : 0);
    }
    function header(PaperList $pl, $is_text) {
        return "Desirability";
    }
    function content(PaperList $pl, PaperInfo $row) {
        return str_replace("-", "−" /* U+2122 */, (string) $row->desirability());
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $row->desirability();
    }
}
