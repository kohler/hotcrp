<?php
// formulas/f_option.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Option_Fexpr extends Fexpr {
    private $option;
    function __construct(PaperOption $option) {
        parent::__construct("option");
        $this->option = $this->_format = $option;
        if ($this->option->type === "checkbox") {
            $this->_format = self::FBOOL;
        }
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $ovar = "\$opt" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($ovar)) {
            $state->queryOptions["options"] = true;
            if ($this->option->type === "checkbox") {
                $state->gstmt[] = "{$ovar}_ov = \$prow->force_option($id);";
            } else {
                $state->gstmt[] = "{$ovar}_ov = \$prow->option($id);";
            }
            $state->gstmt[] = "if ({$ovar}_ov !== null && \$contact->can_view_option(\$prow, {$ovar}_ov->option)) {";
            if ($this->option->type === "checkbox") {
                $state->gstmt[] = "  $ovar = !!{$ovar}_ov->value;";
            } else {
                $state->gstmt[] = "  $ovar = {$ovar}_ov->value;";
            }
            $state->gstmt[] = "} else {";
            $state->gstmt[] = "  $ovar = null;";
            $state->gstmt[] = "}";
        }
        return $ovar;
    }
}
