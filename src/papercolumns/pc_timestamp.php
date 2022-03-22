<?php
// pc_timestamp.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Timestamp_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $at = max($a->timeSubmitted, 0);
        $bt = max($b->timeSubmitted, 0);
        return $bt <=> $at;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return $row->timeSubmitted <= 0;
    }
    function content(PaperList $pl, PaperInfo $row) {
        if ($row->timeSubmitted > 0) {
            return $row->conf->unparse_time_log($row->timeSubmitted);
        }
        return "";
    }
}
