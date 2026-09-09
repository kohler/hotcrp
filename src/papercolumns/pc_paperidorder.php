<?php
// pc_paperidorder.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperIDOrder_PaperColumn extends PaperColumn {
    /** @var PaperIDSet */
    private $pidset;
    /** @var int */
    static private $type_uid = 1;
    function __construct(Conf $conf, PaperIDSet $pidset) {
        parent::__construct($conf, (object) ["name" => "__numericorder" . (++self::$type_uid), "sort" => true]);
        $this->pidset = $pidset;
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $this->pidset->compare($a->paperId, $b->paperId);
    }
}
