<?php
// search/st_optionvalue.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class OptionValue_SearchTerm extends Option_SearchTerm {
    /** @var int */
    private $compar;
    /** @var int */
    private $value;
    /** @param int $relation
     * @param int|float $value */
    function __construct(Contact $user, PaperOption $o, $relation, $value) {
        parent::__construct($user, $o, "optionvalue");
        $this->compar = $relation;
        $this->value = $value;
    }
    function debug_json() {
        return [
            "type" => $this->type,
            "option" => $this->option->search_keyword(),
            "compar" => CountMatcher::unparse_relation($this->compar),
            "value" => $this->value
        ];
    }
    function test(PaperInfo $row, $xinfo) {
        return $this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))
            && $ov->value !== null
            && CountMatcher::compare($ov->value, $this->compar, $this->value);
    }
    function script_expression(PaperInfo $row, $about) {
        if (($about & self::ABOUT_PAPER) === 0) {
            return parent::script_expression($row, $about);
        } else if (!$this->user->can_view_option($row, $this->option, Contact::OVERRIDE_EDIT_CONDITIONS)) {
            return false;
        } else if (!($se = $this->option->value_script_expression())) {
            return null;
        }
        return ["type" => "compar", "child" => [$se, $this->value], "compar" => CountMatcher::unparse_relation($this->compar)];
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
