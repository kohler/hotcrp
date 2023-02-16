<?php
// reviewfields/rf_text.php -- HotCRP search helper for text review fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

/** @inherits ReviewFieldSearch<Text_ReviewField> */
class Text_ReviewFieldSearch extends ReviewFieldSearch {
    /** @var int */
    public $op;
    /** @var TextPregexes */
    public $preg;

    /** @param Text_ReviewField $rf
     * @param int $op
     * @param TextPregexes $preg */
    function __construct($rf, $op, $preg) {
        parent::__construct($rf);
        $this->op = $op;
        $this->preg = $preg;
    }

    function sqlexpr() {
        return "tfields is not null";
    }

    function test_review($user, $prow, $rrow) {
        $fv = $rrow->fval($this->rf);
        $match = $fv !== null
            && $fv !== ""
            && $rrow->field_match_pregexes($this->preg, $this->rf->order);
        if (!$match) {
            if (($this->op & CountMatcher::RELALL) !== 0 && $fv !== null) {
                $this->finished = -1;
            }
            return false;
        }
        return true;
    }
}
