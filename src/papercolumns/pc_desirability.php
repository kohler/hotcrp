<?php
// pc_desirability.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $b->desirability() <=> $a->desirability();
    }
    function content(PaperList $pl, PaperInfo $row) {
        return str_replace("-", "−" /* U+2122 */, (string) $row->desirability());
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $row->desirability();
    }
}
