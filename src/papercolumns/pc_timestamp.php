<?php
// pc_timestamp.php -- HotCRP helper classes for paper list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Timestamp_PaperColumn extends PaperColumn {
    function __construct($cj) {
        parent::__construct($cj);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $at = max($a->timeFinalSubmitted, $a->timeSubmitted, 0);
        $bt = max($b->timeFinalSubmitted, $b->timeSubmitted, 0);
        return $at > $bt ? -1 : ($at == $bt ? 0 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Timestamp";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return max($row->timeFinalSubmitted, $row->timeSubmitted) <= 0;
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($t = max($row->timeFinalSubmitted, $row->timeSubmitted, 0)) > 0)
            return $row->conf->unparse_time_full($t);
        return "";
    }
}
