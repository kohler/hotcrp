<?php
// formulas/f_reviewstatus.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class ReviewStatus_Fexpr extends Fexpr {
    /** @var int */
    public $status = 0;
    function __construct($status = 0) {
        parent::__construct("reviewstatus");
        $this->set_format(Fexpr::FBOOL);
        $this->status = $status;
    }
    function about() {
        return SearchTerm::ABOUT_REVIEWS;
    }
    function inferred_index() {
        return Fexpr::IDX_REVIEW;
    }
    function compile(FormulaCompiler $state) {
        $rrow = $state->current_rrow();
        if ($this->status === 0) {
            return "!!{$rrow}";
        }
        return "({$rrow} && ReviewSearchMatcher::test_status({$this->status}, {$rrow}, \$user))";
    }
}
