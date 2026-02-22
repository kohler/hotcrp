<?php
// pc_pcconflicts.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PCConflicts_PaperColumn extends PaperColumn {
    /** @var PCConflicts_PaperOption */
    private $opt;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->opt = $conf->option_by_id(PaperOption::PCCONFID);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_conflicts()) {
            return false;
        }
        $pl->qopts["allConflictType"] = 1;
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_conflicts($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $y = [];
        $pcm = $row->conf->pc_members();
        foreach ($row->conflict_types() as $uid => $ctype) {
            if (!($pc = $pcm[$uid] ?? null)
                || !Conflict::is_conflicted($ctype)) {
                continue;
            }
            $y[$pc->pc_index] = $pl->user->reviewer_html_for($pc);
        }
        ksort($y);
        return join(", ", $y);
    }
    function text_ctx(RenderContext $ctx, PaperInfo $row) {
        return $this->opt->text($ctx, $row->option($this->opt));
    }
    function json_ctx(RenderContext $ctx, PaperInfo $row) {
        return $this->opt->json($ctx, $row->option($this->opt));
    }
}
