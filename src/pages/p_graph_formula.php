<?php
// pages/p_graph_formula.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Graph_Formula_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var list<FormulaGraphDataset> */
    public $datasets;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    /** @param int|string $i
     * @param FormulaGraphDataset $ds
     * @param MessageSet $ms */
    private function echo_formulas_qrow($i, $ds, $ms) {
        $field = "q{$ds->field_suffix}";
        $klass = $ms->control_class($field, "need-suggest papersearch want-focus");
        $ph = $i === 1 ? "(All)" : "Search";
        echo '<div class="draggable d-flex mb-2">',
            '<div class="flex-grow-0 pr-1"><button type="button" class="draghandle ui uikd js-dropmenu-button ui-drag row-order-draghandle need-tooltip need-dropmenu" draggable="true" title="Click or drag to reorder" aria-haspopup="menu" aria-expanded="false"></button></div>',
            '<div class="flex-grow-1 lentry">',
            $ms->feedback_html_at($field),
            Ht::entry("q{$i}", $ds->q, ["size" => 40, "placeholder" => $ph, "class" => $klass, "id" => "q{$i}", "spellcheck" => false, "autocomplete" => "off", "aria-label" => "Search"]),
            " <span class=\"pl-3\">Style:</span> &nbsp;",
            Ht::select("s{$i}", ["default" => "default", "plain" => "plain", "tag-red" => "red", "tag-orange" => "orange", "tag-yellow" => "yellow", "tag-green" => "green", "tag-blue" => "blue", "tag-purple" => "purple", "tag-gray" => "gray"], $ds->style !== "" ? $ds->style : "by-tag"),
            '</div></div>';
    }

    /** @param FormulaGraph $fg
     * @param list<FormulaGraphDataset> $datasets */
    private function print_graph($fg, $datasets) {
        foreach ($datasets as $ds) {
            $fg->add_dataset($ds);
        }

        if ($fg->has_message()) {
            echo Ht::msg(MessageSet::feedback_html($fg->decorated_message_list()), $fg->problem_status());
        }

        $xhtml = $fg->annotated_fx_expression_h();
        if ($fg->fx_format() === Fexpr::FTAG) {
            $xhtml = "tag";
        }

        if ($fg->fx_format() === Fexpr::FSEARCH) {
            $h2 = "";
        } else if ($fg->type === FormulaGraph::OGIVE) {
            $h2 = "Cumulative frequency of {$xhtml}";
        } else if ($fg->type & FormulaGraph::CDF) {
            $h2 = "{$xhtml} CDF";
        } else if (($fg->type & FormulaGraph::BARCHART)
                   && $fg->fy->expression === "sum(1)") {
            $h2 = $xhtml;
        } else if ($fg->type & FormulaGraph::BARCHART) {
            $h2 = $fg->fy->annotated_expression_h() . " by {$xhtml}";
        } else {
            $h2 = $fg->fy->annotated_expression_h() . " vs. {$xhtml}";
        }
        $highlightable = ($fg->type & (FormulaGraph::SCATTER | FormulaGraph::DOT | FormulaGraph::BOXPLOT)) !== 0;

        $attr = [];
        if (!($fg->type & FormulaGraph::CDF)) {
            $attr["data-graph-fx"] = $fg->fx->expression;
            $attr["data-graph-fy"] = $fg->fy->expression;
        }
        Graph_Page::print_graph($highlightable, $h2, $attr);

        echo Ht::unstash(), Ht::script_open(),
            '$(function () { hotcrp.graph("#hotgraph", ',
            json_encode_browser($fg->graph_json()),
            ") });</script>\n";
    }

    /** @param MessageSet $fgm
     * @param list<FormulaGraphDataset> $datasets */
    private function print_ui($fgm, $datasets) {
        echo Ht::form($this->conf->hoturl("graph", "group=formula"), ["method" => "get"]);
        /*echo '<div>',
            Ht::button(Icons::ui_graph_scatter(), ["class" => "btn-t"]),
            Ht::button(Icons::ui_graph_bars(), ["class" => "btn-t"]),
            Ht::button(Icons::ui_graph_box(), ["class" => "btn-t"]),
            Ht::button(Icons::ui_graph_cdf(), ["class" => "btn-t"]),
            '</div>';*/

        // X axis
        echo '<div class="f-mcol">',
            '<div class="', $fgm->control_class("fx", "f-i maxw-480"), '">',
            '<label for="x_entry">X axis</label>',
            $fgm->feedback_html_at("fx"),
            Ht::entry("x", (string) $this->qreq->x, ["id" => "x_entry", "size" => 32, "class" => "w-99", "spellcheck" => false]),
            '<div class="f-d">', $this->conf->hotlink("Formula", "help", ["t" => "formulas"]), ' or “search”</div>',
            '</div>';
        // Y axis
        echo '<div class="', $fgm->control_class("fy", "f-i maxw-480"), '">',
            '<label for="y_entry">Y axis</label>',
            $fgm->feedback_html_at("fy"),
            Ht::entry("y", (string) $this->qreq->y, ["id" => "y_entry", "size" => 32, "class" => "w-99", "spellcheck" => false]),
            '<div class="f-d">', $this->conf->hotlink("Formula", "help", ["t" => "formulas"]), ' or “cdf”, “count”, “fraction”, “box <em>formula</em>”, “bar <em>formula</em>”</div>',
            '</div>',
            '</div>';
        // Series
        echo '<div class="', $fgm->control_class("q1", "f-i"), '">',
            '<label for="q1">Data sets</label>',
            '<div id="graph-datasets" class="js-row-order" data-min-rows="1" data-row-template="formula-dataset-template">';
        foreach ($datasets as $i => $ds) {
            $this->echo_formulas_qrow($i + 1, $ds, $fgm);
        }
        echo '</div><template id="formula-dataset-template" class="hidden">';
        $this->echo_formulas_qrow('$', new FormulaGraphDataset("ALL", "by-tag", '$'), $fgm);
        echo '</template>',
            Ht::button("Add data set", ["class" => "ui row-order-append", "data-rowset" => "graph-datasets"]),
            '</div>',
            Ht::submit("Graph", ["class" => 'btn-primary']),
            '</form>';
    }

    static function go(Contact $user, Qrequest $qreq, $gx, $gj) {
        (new Graph_Formula_Page($user, $qreq))->print($gj);
    }

    function print($gj) {
        // parse arguments
        $qreq = $this->qreq;
        if (!isset($qreq->x) || !isset($qreq->y)) {
            // derive a sample graph
            $fields = $this->conf->review_form()->example_fields($this->user);
            unset($qreq->x, $qreq->y);
            if (count($fields) > 0) {
                $qreq->y = "avg(" . $fields[0]->search_keyword() . ")";
            }
            if (count($fields) > 1) {
                $qreq->x = "avg(" . $fields[1]->search_keyword() . ")";
            } else {
                $qreq->x = "pid";
            }
        }
        $datasets = FormulaGraph::parse_datasets($qreq);

        // create graph
        if ($qreq->x && ($qreq->gtype || $qreq->y)) {
            $fg = new FormulaGraph($this->user, $qreq->gtype, $qreq->x, $qreq->y);
            if ($qreq->xorder) {
                $fg->set_xorder($qreq->xorder);
            }
            $this->print_graph($fg, $datasets);
            $this->print_ui($fg, $datasets);
        } else {
            $this->print_ui(new MessageSet, $datasets);
        }
    }
}
