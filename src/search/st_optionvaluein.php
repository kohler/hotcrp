<?php
// search/st_optionvaluein.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class OptionValueIn_SearchTerm extends Option_SearchTerm {
    /** @var list<int> */
    private $values;
    /** @param list<int> $values */
    function __construct(Contact $user, PaperOption $o, $values) {
        parent::__construct($user, $o, "optionvaluein");
        $this->values = $values;
    }
    function debug_json() {
        return [$this->type, $this->option->search_keyword(), $this->values];
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $st = parent::sqlexpr($sqi);
        if ($st !== "true") {
            $values = join(",", $this->values);
            if ($this->option->id > 0) {
                return "exists (select * from PaperOption where paperId=Paper.paperId and optionId={$this->option->id} and value in ({$values}))";
            } else if ($this->option->id === PaperOption::TOPICSID) {
                return "exists (select * from PaperTopic where paperId=Paper.paperId and topicId in ({$values}))";
            }
        }
        return "true";
    }
    function is_sqlexpr_precise() {
        return $this->option->always_visible()
            && $this->option->is_value_present_trivial();
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->user->can_view_option($row, $this->option)
            && ($ov = $row->option($this->option))) {
            $vl = $ov->value_list();
            foreach ($this->values as $v) {
                if (in_array($v, $vl))
                    return true;
            }
        }
        return false;
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
