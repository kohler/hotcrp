<?php
// pc_pagecount.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class PageCount_PaperColumn extends PaperColumn {
    /** @var CheckFormat */
    private $cf;
    /** @var ?DocumentInfo */
    private $doc;
    /** @var array<int,int> */
    private $sortmap;
    /** @var ScoreInfo */
    private $statistics;
    /** @var ?string */
    private $type;
    /** @var PaperOption */
    private $opt;
    /** @var ?PaperOption */
    private $opt_final;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->cf = new CheckFormat($conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
        $this->statistics = new ScoreInfo;
    }
    function view_option_schema() {
        return ["type=all|body|blank|cover|appendix|bib,refs,references|figure^", "dt=paper,submission|final|$^"];
    }
    function prepare(PaperList $pl, $visible) {
        $type = $this->view_option("type") ?? "all";
        if ($type !== "all") {
            $this->type = $type;
        }
        $dt = $this->view_option("dt");
        if ($dt !== null) {
            $this->opt = $pl->conf->options()->find($dt);
            if (!$this->opt || !$this->opt->is_document()) {
                $pl->column_error_at($this->name, "<0>Document ‘{$dt}’ not found");
                return false;
            }
        } else {
            $this->opt = $pl->conf->option_by_id(DTYPE_SUBMISSION);
            $this->opt_final = $pl->conf->option_by_id(DTYPE_FINAL);
        }
        return true;
    }
    function sort_name() {
        return $this->sort_name_with_options("type", "dt");
    }
    /** @return ?PaperOption */
    private function dopt(Contact $user, PaperInfo $row) {
        if ($this->opt_final
            && $row->finalPaperStorageId > 0
            && $user->can_view_option($row, $this->opt_final)) {
            return $this->opt_final;
        } else if ($user->can_view_option($row, $this->opt)) {
            return $this->opt;
        }
        return null;
    }
    /** @return ?int */
    private function page_count(Contact $user, PaperInfo $row) {
        $dopt = $this->dopt($user, $row);
        $this->doc = $dopt ? $row->document($dopt->id) : null;
        return $this->doc ? $this->doc->npages_of_type($this->type, $this->cf) : null;
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
        } else if (!$this->doc || !$this->cf->need_recheck()) {
            return "";
        }
        $dtx = "";
        if ($this->doc->documentType) {
            $dtx .= " data-dt=\"{$this->doc->documentType}\"";
        }
        if ($this->type) {
            $dtx .= " data-npages-detail=\"{$this->type}\"";
        }
        return "<span class=\"need-format-check is-npages\"{$dtx}></span>";
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
