<?php
// pc_formulagraph.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FormulaGraph_PaperColumn extends ScoreGraph_PaperColumn {
    /** @var Formula */
    public $formula;
    /** @var callable */
    private $indexes_function;
    /** @var callable */
    private $formula_function;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->formula = $cj->formula;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->check($pl->user)
            || $this->formula->result_format() !== Fexpr::FREVIEWFIELD
            || !$pl->user->can_view_formula($this->formula)) {
            return false;
        }
        $this->format_field = $this->formula->result_format_detail();
        $this->formula_function = $this->formula->compile_sortable_function();
        $this->indexes_function = null;
        if ($this->formula->indexed()) {
            $this->indexes_function = Formula::compile_indexes_function($pl->user, $this->formula->index_type());
        }
        if ($visible) {
            $this->formula->add_query_options($pl->qopts);
        }
        parent::prepare($pl, $visible);
        return true;
    }
    function score_info(PaperList $pl, PaperInfo $row) {
        $indexesf = $this->indexes_function;
        $indexes = $indexesf ? $indexesf($row, $pl->user) : [null];
        $formulaf = $this->formula_function;
        $sci = new ScoreInfo(null, true);
        foreach ($indexes as $i) {
            if (($v = $formulaf($row, $i, $pl->user)) !== null
                && $v > 0) {
                $sci->add($v);
                if ($i === $this->cid
                    && $row->can_view_review_identity_of($i, $pl->user)) {
                    $sci->set_my_score($v);
                }
            }
        }
        return $sci;
    }
    function header(PaperList $pl, $is_text) {
        $x = $this->formula->column_header();
        return $is_text ? $x : htmlspecialchars($x);
    }

    static function expand($name, Contact $user, $xfj, $m) {
        $formula = new Formula($m[1], Formula::ALLOW_INDEXED);
        if (!$formula->check($user)) {
            foreach ($formula->message_list() as $mi) {
                PaperColumn::column_error($user, $mi);
            }
            return null;
        } else if ($formula->result_format() !== Fexpr::FREVIEWFIELD) {
            PaperColumn::column_error($user, "<0>Formula of type " . $formula->result_format_description() . " can’t be used in graphs, review field value expected");
            return null;
        } else {
            $cj = (array) $xfj;
            $cj["name"] = "graph(" . $m[1] . ")";
            $cj["formula"] = $formula;
            return [(object) $cj];
        }
    }

    static function completions(Contact $user, $xfj) {
        return ["graph(<formula>)"];
    }
}
