<?php
// search/st_optionpresent.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class OptionPresent_SearchTerm extends Option_SearchTerm {
    private $is_multi;

    function __construct(PaperOption $o, $is_multi = false) {
        parent::__construct("optionpresent", $o);
        $this->is_multi = $is_multi;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        return $this->is_multi ? "true" : parent::sqlexpr($sqi);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $srch->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $this->option->value_present($ov);
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)) {
            return $this->option->present_script_expression();
        } else {
            return false;
        }
    }
}
