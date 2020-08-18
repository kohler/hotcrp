<?php
// search/st_optionpresent.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class OptionPresent_SearchTerm extends SearchTerm {
    /** @var PaperOption */
    private $option;
    private $is_multi;

    /** @param PaperOption $o */
    function __construct($o, $is_multi = false) {
        parent::__construct("optionpresent");
        $this->option = $o;
        $this->is_multi = $is_multi;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        if (!$this->is_multi && !$sqi->negated && !$this->option->include_empty) {
            return "exists (select * from PaperOption where paperId=Paper.paperId and optionId={$this->option->id})";
        } else {
            return "true";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $srch->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $this->option->value_present($ov);
    }
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)) {
            return $this->option->present_script_expression();
        } else {
            return false;
        }
    }
}
