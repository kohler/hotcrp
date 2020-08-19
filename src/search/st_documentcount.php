<?php
// search/st_documentcount.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentCount_SearchTerm extends Option_SearchTerm {
    /** @var int */
    private $compar;
    /** @var int */
    private $value;
    /** @param string $compar
     * @param int $value */
    function __construct(PaperOption $o, $compar, $value) {
        parent::__construct("documentcount", $o);
        $this->compar = CountMatcher::comparator_value($compar);
        $this->value = $value;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), CountMatcher::unparse_comparator_value($this->compar), $this->value];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        return CountMatcher::compare(0, $this->compar, $this->value) ? "true" : parent::sqlexpr($sqi);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))) {
            $n = count($this->option->value_dids($ov));
        } else {
            $n = 0;
        }
        return CountMatcher::compare($n, $this->compar, $this->value);
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)) {
            return ["type" => "compar", "child" => [$this->option->present_script_expression(), $this->value], "compar" => CountMatcher::unparse_comparator_value($this->compar)];
        } else {
            return false;
        }
    }
}
