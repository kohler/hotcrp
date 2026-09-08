<?php
// formulas/f_pdfsize.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class PdfSize_Fexpr extends Fexpr {
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function compile(FormulaCompiler $state) {
        $doc = $state->prow_primary_document();
        return "({$doc} ? {$doc}->size() : null)";
    }
}
