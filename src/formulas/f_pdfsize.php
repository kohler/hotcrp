<?php
// formulas/f_pdfsize.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class PdfSize_Fexpr extends Fexpr {
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function compile(FormulaCompiler $state) {
        $doc = $state->prow_primary_document();
        // unknown sizes (including placeholders) are null
        $sz = $state->define_gvar('$primary_document_size', "{$doc} ? {$doc}->size() : -1");
        return "({$sz} >= 0 ? {$sz} : null)";
    }
}
