<?php
// search/st_optionpresent.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class OptionPresent_SearchTerm extends Option_SearchTerm {
    function __construct(Contact $user, PaperOption $o) {
        parent::__construct($user, $o, "optionpresent");
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function is_sqlexpr_precise() {
        return $this->option->always_visible()
            && $this->option->is_value_present_trivial();
    }
    function test(PaperInfo $row, $xinfo) {
        return $this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $this->option->value_present($ov);
    }
    function script_expression(PaperInfo $row, $about) {
        if ($about !== self::ABOUT_PAPER) {
            return parent::script_expression($row, $about);
        } else if ($this->user->can_view_option($row, $this->option)) {
            return $this->option->present_script_expression();
        } else {
            return false;
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
