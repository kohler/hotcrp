<?php
// pc_pagecount.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PageCount_PaperColumn extends PaperColumn {
    /** @var CheckFormat */
    private $cf;
    /** @var ?DocumentInfo */
    private $doc;
    /** @var array<int,int> */
    private $sortmap;
    /** @var ScoreInfo */
    private $statistics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->cf = new CheckFormat($conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
        $this->statistics = new ScoreInfo;
    }
    /** @return 0|-1 */
    private function dtype(Contact $user, PaperInfo $row) {
        if ($row->finalPaperStorageId > 0
            && $row->outcome > 0
            && $user->can_view_decision($row)) {
            return DTYPE_FINAL;
        }
        return DTYPE_SUBMISSION;
    }
    /** @return ?int */
    private function page_count(Contact $user, PaperInfo $row) {
        if ($user->can_view_pdf($row)) {
            $this->doc = $row->document($this->dtype($user, $row));
        } else {
            $this->doc = null;
        }
        return $this->doc ? $this->doc->npages($this->cf) : null;
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            $this->sortmap[$row->paperXid] = $this->page_count($pl->user, $row);
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ac = $this->sortmap[$a->paperXid];
        $bc = $this->sortmap[$b->paperXid];
        if ($ac === null || $bc === null) {
            return ($ac === null ? 0 : 1) <=> ($bc === null ? 0 : 1);
        }
        return $ac <=> $bc;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_pdf($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($pn = $this->page_count($pl->user, $row)) !== null) {
            $this->statistics->add_overriding($pn, $pl->overriding);
            return (string) $pn;
        } else if ($this->doc && $this->cf->need_recheck()) {
            $dt = $this->dtype($pl->user, $row);
            return '<span class="need-format-check is-npages"'
                . ($dt ? ' data-dt="' . $dt . '"' : '')
                . ($dt ? ' data-dtype="' . $dt . '"' : '') /* XXX backward compat */
                . '></span>';
        }
        return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->user, $row);
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $this->page_count($pl->user, $row);
    }
    function has_statistics() {
        return true;
    }
    function statistics() {
        return $this->statistics;
    }
}
