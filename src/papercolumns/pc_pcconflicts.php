<?php
// pc_pcconflicts.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PCConflicts_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->privChair)
            return false;
        if ($visible)
            $pl->qopts["allConflictType"] = 1;
        return true;
    }
    function header(PaperList $pl, $is_text) {
        return "PC conflicts";
    }
    function content(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->user->reviewer_html_for($pc);
        ksort($y);
        return join(", ", $y);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $type)
            if (($pc = get($pcm, $id)))
                $y[$pc->sort_position] = $pl->user->name_text_for($pc);
        ksort($y);
        return join("; ", $y);
    }
}
