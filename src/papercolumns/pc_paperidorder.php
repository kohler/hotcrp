<?php
// pc_paperidorder.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class PaperIDOrder_PaperColumn extends PaperColumn {
    /** @var PaperID_SearchTerm */
    private $order;
    function __construct(Conf $conf, PaperID_SearchTerm $order) {
        parent::__construct($conf, (object) ["name" => "numericorder", "sort" => true]);
        $this->order = $order;
    }
    function compare(PaperInfo $a, PaperInfo $b, ListSorter $sorter) {
        $ap = $this->order->position($a->paperId);
        $bp = $this->order->position($b->paperId);
        if ($ap !== false && $bp !== false) {
            return $ap < $bp ? -1 : ($ap > $bp ? 1 : 0);
        } else if ($ap !== false || $bp !== false) {
            return $ap === false ? 1 : -1;
        } else {
            return $a->paperId - $b->paperId;
        }
    }
}
