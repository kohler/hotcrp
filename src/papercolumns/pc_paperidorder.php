<?php
// pc_paperidorder.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperIDOrder_PaperColumn extends PaperColumn {
    /** @var PaperID_SearchTerm */
    private $order_term;
    /** @var int */
    static private $type_uid = 1;
    function __construct(Conf $conf, PaperID_SearchTerm $order_term) {
        parent::__construct($conf, (object) ["name" => "__numericorder" . (++self::$type_uid), "sort" => true]);
        $this->order_term = $order_term;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ap = $this->order_term->index_of($a->paperId);
        $bp = $this->order_term->index_of($b->paperId);
        if ($ap !== false && $bp !== false) {
            return $ap <=> $bp;
        } else if ($ap !== false || $bp !== false) {
            return $ap === false ? 1 : -1;
        } else {
            return $a->paperId <=> $b->paperId;
        }
    }
}
