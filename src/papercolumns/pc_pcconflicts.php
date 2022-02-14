<?php
// pc_pcconflicts.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PCConflicts_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_conflicts()) {
            return false;
        }
        if ($visible) {
            $pl->qopts["allConflictType"] = 1;
        }
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_conflicts($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $cflt) {
            if (($pc = $pcm[$id] ?? null) && $cflt->is_conflicted())
                $y[$pc->pc_index] = $pl->user->reviewer_html_for($pc);
        }
        ksort($y);
        return join(", ", $y);
    }
    function text(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflicts() as $id => $cflt) {
            if (($pc = $pcm[$id] ?? null) && $cflt->is_conflicted())
                $y[$pc->pc_index] = $pl->user->reviewer_text_for($pc);
        }
        ksort($y);
        return join("; ", $y);
    }
}
