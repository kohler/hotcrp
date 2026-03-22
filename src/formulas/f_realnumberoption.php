<?php
// formulas/f_realnumberoption.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class RealNumberOption_Fexpr extends Fexpr {
    /** @var PaperOption */
    private $option;
    function __construct(PaperOption $option) {
        parent::__construct("realnumberoption");
        $this->option = $option;
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function paper_options(&$oids) {
        $oids[$this->option->id] = true;
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $oval = "\$optvalue" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($oval)) {
            $ovv = $state->_add_option_value($this->option);
            $fv = "floatval({$ovv}->data())";
            if ($this->option->precision) {
                $fv = "round({$fv}, {$this->option->precision})";
            }
            $state->gstmt[] = "{$oval} = {$ovv} && {$ovv}->value !== null ? {$fv} : null;";
        }
        return $oval;
    }
    function collect_range_anno(&$ranges) {
        $this->record_range_anno($ranges, $this->option->title());
    }
}
