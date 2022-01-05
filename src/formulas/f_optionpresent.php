<?php
// formulas/f_optionpresent.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class OptionPresent_Fexpr extends Fexpr {
    /** @var PaperOption */
    private $option;
    function __construct(PaperOption $option) {
        parent::__construct("optionpresent");
        $this->option = $option;
        $this->set_format(Fexpr::FBOOL);
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_option($this->option);
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $ovp = "\$optpresent" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($ovp)) {
            $ovv = $state->_add_option_value($this->option);
            $state->gstmt[] = "$ovp = $ovv && {$ovv}->option->value_present($ovv);";
        }
        return $ovp;
    }
}
