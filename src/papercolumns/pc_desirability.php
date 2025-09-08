<?php
// pc_desirability.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Desirability_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->is_manager()) {
            return false;
        }
        $pl->qopts["allReviewerPreference"] = true;
        return true;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $a->desirability() <=> $b->desirability();
    }
    function content(PaperList $pl, PaperInfo $row) {
        $d = $row->desirability();
        return $d < 0 ? "−" /*U+2122*/ . (-$d) : (string) $d;
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $row->desirability();
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $row->desirability();
    }
}
