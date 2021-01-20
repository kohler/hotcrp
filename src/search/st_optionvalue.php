<?php
// search/st_optionvalue.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class OptionValue_SearchTerm extends Option_SearchTerm {
    /** @var int */
    private $compar;
    /** @var int */
    private $value;
    /** @param int $compar
     * @param int|float $value */
    function __construct(Contact $user, PaperOption $o, $compar, $value) {
        parent::__construct($user, $o, "optionvalue");
        $this->compar = $compar;
        $this->value = $value;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword()];
    }
    function test(PaperInfo $row, $rrow) {
        return $this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $ov->value !== null
            && CountMatcher::compare($ov->value, $this->compar, $this->value);
    }
    function script_expression(PaperInfo $row) {
        if ($this->user->can_view_option($row, $this->option)) {
            if (($se = $this->option->value_script_expression())) {
                return ["type" => "compar", "child" => [$se, $this->value], "compar" => CountMatcher::unparse_comparator_value($this->compar)];
            } else {
                return null;
            }
        } else {
            return false;
        }
    }
}
