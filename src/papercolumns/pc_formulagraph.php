<?php
// pc_formulagraph.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class FormulaGraph_PaperColumn extends ScoreGraph_PaperColumn {
    /** @var Formula */
    public $formula;
    /** @var callable */
    private $indexes_function;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->formula = $cj->formula;
    }
    function prepare(PaperList $pl, $visible) {
        if (!$this->formula->ok()
            || $this->formula->result_format() !== Fexpr::FREVIEWFIELD
            || !$this->formula->viewable()) {
            return false;
        }
        $this->format_field = $this->formula->result_format_detail();
        $this->formula->prepare_sortable();
        $this->indexes_function = null;
        if ($this->formula->indexed()) {
            $this->indexes_function = Formula::compile_indexes_function($pl->user, $this->formula->index_type());
        }
        $this->formula->add_query_options($pl->qopts);
        parent::prepare($pl, $visible);
        return true;
    }
    function score_info(PaperList $pl, PaperInfo $row) {
        $indexesf = $this->indexes_function;
        $indexes = $indexesf ? call_user_func($indexesf, $row, $pl->user) : [null];
        $sci = new ScoreInfo;
        foreach ($indexes as $i) {
            if (($v = $this->formula->eval_sortable($row, $i)) !== null
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
        $x = $this->formula->expression;
        return $is_text ? $x : htmlspecialchars($x);
    }

    static function expand($name, XtParams $xtp, $xfj, $m) {
        $formula = Formula::make_indexed($xtp->user, $m[2]);
        if (!$formula->ok()) {
            PaperColumn::column_error($xtp, MessageSet::list_with($formula->message_list(), [
                "top_context" => $m[0], "top_pos_offset" => strlen($m[1])
            ]));
            return null;
        } else if ($formula->result_format() !== Fexpr::FREVIEWFIELD) {
            PaperColumn::column_error($xtp, "<0>Formula of type " . $formula->result_format_description() . " can’t be used in graphs, review field value expected");
            return null;
        }
        $cj = (array) $xfj;
        $cj["name"] = "graph(" . $m[2] . ")";
        $cj["formula"] = $formula;
        return [(object) $cj];
    }

    static function examples(Contact $user, $xfj) {
        return [new SearchExample("graph({formula})", "<0>Graph results of formula for each reviewer")];
    }
}
