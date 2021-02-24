<?php
// formulas/f_topic.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2021 Eddie Kohler; see LICENSE.

class Topic_Fexpr extends Fexpr {
    private $match;
    function __construct(FormulaCall $ff, Formula $formula) {
        parent::__construct("topic");
        if ($ff->modifier === false || $ff->modifier === true) {
            $this->match = true;
        } else if ($ff->modifier === [false]) {
            $this->match = false;
            $this->_format = self::FBOOL;
        } else {
            $this->match = $ff->modifier;
            if (count($this->match) === 1)
                $this->_format = self::FBOOL;
        }
    }
    static function parse_modifier(FormulaCall $ff, $arg, $rest, Formula $formula) {
        if ($ff->modifier === false && !str_starts_with($arg, ".")) {
            if (str_starts_with($arg, ":")) {
                $arg = substr($arg, 1);
            }
            $w = new SearchWord($arg, $arg);
            if (strcasecmp($w->word, "any") === 0 && !$w->quoted) {
                $ff->modifier = true;
            } else if (strcasecmp($w->word, "none") === 0 && !$w->quoted) {
                $ff->modifier = [false];
            } else {
                $ff->modifier = $formula->conf->topic_abbrev_matcher()->find_all($w->word);
                // XXX warn if no match
            }
            return true;
        } else {
            return false;
        }
    }
    function compile(FormulaCompiler $state) {
        $state->queryOptions["topics"] = true;
        $prow = $state->_prow();
        if ($this->match === true) {
            return "count({$prow}->topic_list())";
        } else if ($this->match === false) {
            return "empty({$prow}->topic_list())";
        } else if ($this->_format === self::FBOOL) {
            return "in_array({$this->match[0]},{$prow}->topic_list())";
        } else {
            return "count(array_intersect({$prow}->topic_list()," . json_encode($this->match) . '))';
        }
    }
}
