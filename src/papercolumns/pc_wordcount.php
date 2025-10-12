<?php
// pc_wordcount.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class WordCount_PaperColumn extends PaperColumn {
    /** @var CheckFormat */
    private $cf;
    /** @var array<int,int> */
    private $sortmap;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->cf = new CheckFormat($conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
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
    function word_count(Contact $user, PaperInfo $row) {
        if ($user->can_view_pdf($row)
            && ($doc = $row->document($this->dtype($user, $row)))) {
            return $doc->nwords($this->cf);
        }
        $this->cf->clear();
        return null;
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->sortmap = [];
        foreach ($pl->rowset() as $row) {
            $this->sortmap[$row->paperXid] = $this->word_count($pl->user, $row);
        }
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ac = $this->sortmap[$a->paperXid];
        $bc = $this->sortmap[$b->paperXid];
        if ($ac === null || $bc === null) {
            return ($ac === null ? 1 : 0) <=> ($bc === null ? 1 : 0);
        }
        return $ac <=> $bc;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_pdf($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        if (($pn = $this->word_count($pl->user, $row)) !== null) {
            return (string) $pn;
        } else if ($this->cf->need_recheck()) {
            $dt = $this->dtype($pl->user, $row);
            return '<span class="need-format-check is-nwords"'
                . ($dt ? ' data-dt="' . $dt . '"' : '')
                . ($dt ? ' data-dtype="' . $dt . '"' : '') /* XXX backward compat */
                . '></span>';
        }
        return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->word_count($pl->user, $row);
    }
    function json(PaperList $pl, PaperInfo $row) {
        return $this->word_count($pl->user, $row);
    }
}
