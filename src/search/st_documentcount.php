<?php
// search/st_documentcount.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentCount_SearchTerm extends Option_SearchTerm {
    /** @var int */
    private $compar;
    /** @var int */
    private $value;
    /** @param string $compar
     * @param int $value */
    function __construct(Contact $user, PaperOption $o, $compar, $value) {
        parent::__construct($user, $o, "documentcount");
        $this->compar = CountMatcher::parse_relation($compar);
        $this->value = $value;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), CountMatcher::unparse_relation($this->compar), $this->value];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_options_columns();
        return CountMatcher::compare(0, $this->compar, $this->value) ? "true" : parent::sqlexpr($sqi);
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))) {
            $n = count($this->option->value_dids($ov));
        } else {
            $n = 0;
        }
        return CountMatcher::compare($n, $this->compar, $this->value);
    }
    function script_expression(PaperInfo $row, $about) {
        if ($about === self::ABOUT_PAPER) {
            return parent::script_expression($row, $about);
        } else if ($this->user->can_view_option($row, $this->option)) {
            return [
                "type" => "compar",
                "child" => [$this->option->present_script_expression(), $this->value],
                "compar" => CountMatcher::unparse_relation($this->compar)
            ];
        } else {
            return CountMatcher::compare(0, $this->compar, $this->value);
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
