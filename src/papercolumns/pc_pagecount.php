<?php
// pc_pagecount.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class PageCount_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_some_pdf();
    }
    function page_count(Contact $user, PaperInfo $row) {
        if (!$user->can_view_pdf($row))
            return null;
        $dtype = DTYPE_SUBMISSION;
        if ($row->finalPaperStorageId > 0
            && $row->outcome > 0
            && $user->can_view_decision($row))
            $dtype = DTYPE_FINAL;
        $doc = $row->document($dtype);
        return $doc ? $doc->npages() : null;
    }
    function analyze_sort(PaperList $pl, &$rows, ListSorter $sorter) {
        foreach ($rows as $row)
            $row->_page_count_sort_info = $this->page_count($pl->user, $row);
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $ac = $a->_page_count_sort_info;
        $bc = $b->_page_count_sort_info;
        if ($ac === null || $bc === null)
            return $ac === $bc ? 0 : ($ac === null ? -1 : 1);
        else
            return $ac == $bc ? 0 : ($ac < $bc ? -1 : 1);
    }
    function header(PaperList $pl, $is_text) {
        return "Page count";
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_pdf($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->user, $row);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return (string) $this->page_count($pl->user, $row);
    }
}
