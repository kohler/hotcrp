<?php
// formulas/f_topic.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class Topic_Fexpr extends Fexpr {
    /** @var true|array<int> */
    private $match;
    /** @var int */
    private $topic_idx = -1;
    function __construct(FormulaCall $ff) {
        parent::__construct($ff);
        if ($ff->modifier === null || $ff->modifier === [-1]) {
            $this->match = true;
            $this->set_format(Fexpr::FNUMERIC);
        } else {
            $this->match = $ff->modifier;
            if (count($this->match) <= 1 || $this->match[0] === 0) {
                $this->set_format(Fexpr::FBOOL);
            }
        }
        $opt = $ff->conf->option_by_id(PaperOption::TOPICSID);
        if (!$opt->always_visible()) {
            $this->topic_idx = $ff->formula->register_info($opt);
        }
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if ($ff->modifier !== null || str_starts_with($arg, ".")) {
            return false;
        }
        if (str_starts_with($arg, ":")) {
            $arg = substr($arg, 1);
        }
        $ff->modifier = $ff->conf->topic_set()->find_all(SearchWord::unquote($arg));
        // XXX warn if no match
        return true;
    }
    function about() {
        return SearchTerm::ABOUT_SUB;
    }
    function paper_options(&$oids) {
        $oids[PaperOption::TOPICSID] = true;
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["topics"] = true;
        $texpr = $state->_prow() . "->topic_list()";
        if ($this->topic_idx >= 0) {
            $texpr = "(\$user->can_view_option(\$prow, \$formula->info[{$this->topic_idx}]) ? {$texpr} : [])";
        }
        if ($this->match === true) {
            return "count({$texpr})";
        } else if ($this->match === []) {
            return "false";
        }
        $none = $this->match[0] === 0;
        $ts = $none ? array_slice($this->match, 1) : $this->match;
        if ($ts === []) {
            return "empty({$texpr})";
        } else if (count($ts) === 1) {
            return ($none ? "!" : "") . "in_array({$ts[0]}, {$texpr}, true)";
        }
        return ($none ? "empty" : "count") . "(array_intersect({$texpr}," . json_encode($ts) . "))";
    }
}
