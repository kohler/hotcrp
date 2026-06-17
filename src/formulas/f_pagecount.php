<?php
// formulas/f_pagecount.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class PageCount_Fexpr extends Fexpr {
    /** @var int */
    private $chkindex;
    function __construct(FormulaCall $ff) {
        parent::__construct("pagecount");
        foreach ($ff->formula->info as $idx => $x) {
            if ($x instanceof CheckFormat)
                $this->chkindex = $idx;
        }
        if ($this->chkindex === null) {
            $cf = new CheckFormat($ff->formula->conf, CheckFormat::RUN_IF_NECESSARY_TIMEOUT);
            $this->chkindex = $ff->formula->register_info($cf);
        }
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function compile(FormulaCompiler $state) {
        $doc = $state->_add_primary_document();
        return "({$doc} ? {$doc}->npages(\$formula->info[{$this->chkindex}]) : null)";
    }
}
