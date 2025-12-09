<?php
// formulas/f_pagecount.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class PageCount_Fexpr extends Fexpr {
    /** @var int */
    private $chkindex;
    /** @var list<CheckFormat> */
    static public $checkers = [];
    function __construct(FormulaCall $ff) {
        parent::__construct("pagecount");
        $this->chkindex = 0;
        while ($this->chkindex < count(self::$checkers)
               && self::$checkers[$this->chkindex]->conf !== $ff->user->conf) {
            ++$this->chkindex;
        }
        if ($this->chkindex === count(self::$checkers)) {
            self::$checkers[] = new CheckFormat($ff->user->conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
        }
    }
    function compile(FormulaCompiler $state) {
        $doc = $state->_add_primary_document();
        return "({$doc} ? {$doc}->npages(PageCount_Fexpr::\$checkers[{$this->chkindex}]) : null)";
    }
}
