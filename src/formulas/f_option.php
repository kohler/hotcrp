<?php
// formulas/f_option.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Option_Fexpr extends Fexpr {
    private $option;
    function __construct(PaperOption $option) {
        $this->option = $this->format_ = $option;
        if ($this->option->type === "checkbox") {
            $this->format_ = self::FBOOL;
        }
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $ovar = "\$opt" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($ovar)) {
            $state->queryOptions["options"] = true;
            $state->gstmt[] = "if (\$contact->can_view_option(\$prow, $id)) {";
            $state->gstmt[] = "  $ovar = \$prow->option($id);";
            if ($this->option->type === "checkbox") {
                $state->gstmt[] = "  $ovar = !!($ovar && {$ovar}->value);";
            } else {
                $state->gstmt[] = "  $ovar = $ovar ? {$ovar}->value : null;";
            }
            $state->gstmt[] = "} else\n    $ovar = null;";
        }
        return $ovar;
    }
}
