<?php
// pc_formulagraph.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
            || !$pl->user->can_view_formula($this->formula))
            return false;
        $this->format_field = $this->formula->result_format();
        $this->formula_function = $this->formula->compile_sortable_function();
        $this->indexes_function = null;
        if ($this->formula->indexed())
            $this->indexes_function = Formula::compile_indexes_function($pl->user, $this->formula->index_type());
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
        foreach ($indexes as $i) {
            if (($v = $formulaf($row, $i, $pl->user)) !== null)
                $vs[$i] = $v;
        }
        return $vs;
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        return $is_text ? $x : htmlspecialchars($x);
    }

    static function expand($name, Contact $user, $xfj, $m) {
        $formula = new Formula($m[1], Formula::ALLOW_INDEXED);
        if (!$formula->check($user)) {
            PaperColumn::column_error($user, "Formula error: " . $formula->error_html());
            return null;
        } else if (!($formula->result_format() instanceof ReviewField)) {
            PaperColumn::column_error($user, "Graphed formulas must return review fields.");
            return null;
        } else {
            $cj = (array) $xfj;
            $cj["name"] = "graph(" . $m[1] . ")";
            $cj["formula"] = $formula;
            return [(object) $cj];
        }
    }
}
