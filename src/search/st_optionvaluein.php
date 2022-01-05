<?php
// search/st_optionvaluein.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class OptionValueIn_SearchTerm extends Option_SearchTerm {
    private $values;
    /** @param list<int> $values */
    function __construct(Contact $user, PaperOption $o, $values) {
        parent::__construct($user, $o, "optionvaluein");
        $this->values = $values;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function test(PaperInfo $row, $rrow) {
        return $this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $ov->value !== null
            && in_array($ov->value, $this->values, true);
    }
    function script_expression(PaperInfo $row) {
        if ($this->user->can_view_option($row, $this->option)) {
            if (($se = $this->option->value_script_expression())) {
                return ["type" => "in", "child" => [$se], "values" => $this->values];
            } else {
                return null;
            }
        } else {
            return false;
        }
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
