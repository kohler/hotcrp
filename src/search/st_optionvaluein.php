<?php
// search/st_optionvaluein.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class OptionValueIn_SearchTerm extends Option_SearchTerm {
    private $values;
    /** @param list<int> $values */
    function __construct(PaperOption $o, $values) {
        parent::__construct("optionvaluein", $o);
        $this->values = $values;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $srch->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $ov->value !== null
            && in_array($ov->value, $this->values, true);
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        if ($srch->user->can_view_option($row, $this->option)) {
            if (($se = $this->option->value_script_expression())) {
                return ["type" => "in", "child" => [$se], "values" => $this->values];
            } else {
                return null;
            }
        } else {
            return false;
        }
    }
}
