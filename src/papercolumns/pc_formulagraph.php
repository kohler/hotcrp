<?php
// pc_formulagraph.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class FormulaGraph_PaperColumn extends ScoreGraph_PaperColumn {
    public $formula;
    private $indexes_function;
    private $formula_function;
    private $results;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->formula = $cj->formula;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->check($pl->user)
            || !($this->formula->result_format() instanceof ReviewField)
            || !$pl->user->can_view_formula($this->formula, $pl->search->limit_author()))
            return false;
        $this->format_field = $this->formula->result_format();
        $this->formula_function = $this->formula->compile_sortable_function();
        $this->indexes_function = null;
        if ($this->formula->is_indexed())
            $this->indexes_function = Formula::compile_indexes_function($pl->user, $this->formula->datatypes());
        if ($visible)
            $this->formula->add_query_options($pl->qopts);
        parent::prepare($pl, $visible);
        return true;
    }
    function score_values(PaperList $pl, PaperInfo $row) {
        $indexesf = $this->indexes_function;
        $indexes = $indexesf ? $indexesf($row, $pl->user) : [null];
        $formulaf = $this->formula_function;
        $vs = [];
        foreach ($indexes as $i)
            if (($v = $formulaf($row, $i, $pl->user)) !== null)
                $vs[$i] = $v;
        return $vs;
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        if ($is_text)
            return $x;
        else if ($this->formula->headingTitle && $this->formula->headingTitle != $x)
            return "<span class=\"need-tooltip\" data-tooltip=\"" . htmlspecialchars($this->formula->headingTitle) . "\">" . htmlspecialchars($x) . "</span>";
        else
            return htmlspecialchars($x);
    }
}

class FormulaGraph_PaperColumnFactory {
    static private $nregistered = 0;
    static function expand($name, Conf $conf, $xfj, $m) {
        $formula = new Formula($m[1], true);
        if (!$formula->check($conf->xt_user)) {
            $conf->xt_factory_error($formula->error_html());
            return null;
        } else if (!($formula->result_format() instanceof ReviewField)) {
            $conf->xt_factory_error("Graphed formulas must return review fields.");
            return null;
        } else {
            ++self::$nregistered;
            $cj = (array) $xfj;
            $cj["name"] = "graphx" . self::$nregistered;
            $cj["formula"] = $formula;
            return [(object) $cj];
        }
    }
}
