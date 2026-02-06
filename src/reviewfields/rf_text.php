<?php
// reviewfields/rf_text.php -- HotCRP search helper for text review fields
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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

    function test_value($rrow, $fv) {
        if ($fv !== null
            && $fv !== ""
            && $this->preg->match($rrow->fields[$this->rf->order])) {
            return true;
        }
        if (($this->op & CountMatcher::RELALL) !== 0 && $fv !== null) {
            $this->finished = -1;
        }
        return false;
    }
}
