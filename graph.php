<?php
// graph.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

$Graph = $Qreq->g;
if (!$Graph
    && preg_match('/\A\/\w+\/*/', $Qreq->path())) {
    $Graph = $Qreq->g = $Qreq->path_component(0);
}
if (!isset($Qreq->x) && !isset($Qreq->y) && isset($Qreq->fx) && isset($Qreq->fy)) {
    $Qreq->x = $Qreq->fx;
    $Qreq->y = $Qreq->fy;
}

// collect allowed graphs
$Graphs = array();
if ($Me->isPC) {
    $Graphs["procrastination"] = "Procrastination";
    $Graphs["formula"] = "Formula";
}
if (!count($Graphs)) {
    $Me->escape();
}
reset($Graphs);

$GraphSynonym = array("reviewerlameness" => "procrastination");
if ($Graph && isset($GraphSynonym[$Graph])) {
    $Graph = $GraphSynonym[$Graph];
}
if (!$Graph || !isset($Graphs[$Graph])) {
    $Conf->redirect_self($Qreq, ["g" => key($Graphs)]);
}

// Header and body
$Conf->header("Graphs", "graphbody");
echo Ht::unstash();
echo $Conf->make_script_file("scripts/d3-hotcrp.min.js", true);
echo $Conf->make_script_file("scripts/graph.js");

function echo_graph($searchable, $fg, $h2) {
    echo '<div class="has-hotgraph" style="max-width:960px;margin-bottom:4em">';
    if ($searchable) {
        echo Ht::entry("q", "", ["placeholder" => "Find on graph", "class" => "uii js-hotgraph-highlight papersearch float-right need-autogrow need-suggest", "spellcheck" => false]);
    }
    if ($h2) {
        echo "<h2>", $h2, "</h2>\n";
    }
    echo "<div class=\"hotgraph c\" id=\"hotgraph\"";
    if ($fg && !($fg->type & FormulaGraph::CDF)) {
        echo " data-graph-fx=\"", htmlspecialchars($fg->fx->expression),
            "\" data-graph-fy=\"", htmlspecialchars($fg->fy->expression), "\"";
    }
    echo "></div></div>\n";
}

// Procrastination report
if ($Graph == "procrastination") {
    echo_graph(false, null, "Procrastination");
    $rt = new ReviewTimes($Me);
    echo Ht::unstash_script('$(function () { hotcrp.graph("#hotgraph",' . json_encode_browser($rt->json()) . ') })');
}


// Formula experiment
function formulas_qrow($i, $q, $s, $status) {
    if ($q === "all") {
        $q = "";
    }
    $klass = MessageSet::status_class($status, "need-suggest papersearch want-focus");
    $t = '<tr><td class="lentry">' . Ht::entry("q$i", $q, ["size" => 40, "placeholder" => "(All)", "class" => $klass, "id" => "q$i", "spellcheck" => false, "autocomplete" => "off", "aria-label" => "Search"]);
    $t .= " <span style=\"padding-left:1em\">Style:</span> &nbsp;" . Ht::select("s$i", array("default" => "default", "plain" => "plain", "redtag" => "red", "orangetag" => "orange", "yellowtag" => "yellow", "greentag" => "green", "bluetag" => "blue", "purpletag" => "purple", "graytag" => "gray"), $s !== "" ? $s : "by-tag");
    $t .= ' <span class="nb btnbox aumovebox" style="margin-left:1em"><a href="#" class="ui btn qx row-order-ui moveup" tabindex="-1">'
        . Icons::ui_triangle(0)
        . '</a><a href="#" class="ui btn qx row-order-ui movedown" tabindex="-1">'
        . Icons::ui_triangle(2)
        . '</a><a href="#" class="ui btn qx row-order-ui delete" tabindex="-1">✖</a></span></td></tr>';
    return $t;
}

if ($Graph == "formula") {
    // derive a sample graph
    if (!isset($Qreq->x) || !isset($Qreq->y)) {
        $fields = $Conf->review_form()->example_fields($Me);
        unset($Qreq->x, $Qreq->y);
        if (count($fields) > 0) {
            $Qreq->y = "avg(" . $fields[0]->search_keyword() . ")";
        }
        if (count($fields) > 1) {
            $Qreq->x = "avg(" . $fields[1]->search_keyword() . ")";
        } else {
            $Qreq->x = "pid";
        }
    }

    if ($Qreq->x && ($Qreq->gtype || $Qreq->y)) {
        $fg = $fgm = new FormulaGraph($Me, $Qreq->gtype, $Qreq->x, $Qreq->y);
        if ($Qreq->xorder) {
            $fg->set_xorder($Qreq->xorder);
        }
    } else {
        $fg = null;
        $fgm = new MessageSet;
    }

    list($queries, $styles) = FormulaGraph::parse_queries($Qreq);
    if ($fg) {
        for ($i = 0; $i < count($queries); ++$i) {
            $fg->add_query($queries[$i], $styles[$i], "q$i");
        }

        if ($fg->has_messages()) {
            echo Ht::msg($fg->message_texts(), $fg->problem_status());
        }

        $xhtml = htmlspecialchars($fg->fx_expression());
        if ($fg->fx_format() === Fexpr::FTAG) {
            $xhtml = "tag";
        }

        if ($fg->fx_format() === Fexpr::FSEARCH) {
            $h2 = "";
        } else if ($fg->type === FormulaGraph::RAWCDF) {
            $h2 = "Cumulative count of $xhtml";
        } else if ($fg->type & FormulaGraph::CDF) {
            $h2 = "$xhtml CDF";
        } else if (($fg->type & FormulaGraph::BARCHART)
                   && $fg->fy->expression === "sum(1)") {
            $h2 = $xhtml;
        } else if ($fg->type & FormulaGraph::BARCHART) {
            $h2 = htmlspecialchars($fg->fy->expression) . " by $xhtml";
        } else {
            $h2 = htmlspecialchars($fg->fy->expression) . " vs. $xhtml";
        }
        $highlightable = ($fg->type & (FormulaGraph::SCATTER | FormulaGraph::BOXPLOT))
            && $fg->fx_combinable();
        echo_graph($highlightable, $fg, $h2);

        $gtype = "scatter";
        if ($fg->type & FormulaGraph::BARCHART) {
            $gtype = "barchart";
        } else if ($fg->type & FormulaGraph::CDF) {
            $gtype = "cdf";
        } else if ($fg->type === FormulaGraph::BOXPLOT) {
            $gtype = "boxplot";
        }
        echo Ht::unstash_script("\$(function () { hotcrp.graph(\"#hotgraph\", " . json_encode_browser($fg->graph_json()) . ") });"), "\n";
    } else {
        echo "<h2>Formulas</h2>\n";
    }

    echo Ht::form(hoturl("graph", "g=formula"), ["method" => "get"]);
    /*echo '<div>',
        Ht::button(Icons::ui_graph_scatter(), ["class" => "btn-t"]),
        Ht::button(Icons::ui_graph_bars(), ["class" => "btn-t"]),
        Ht::button(Icons::ui_graph_box(), ["class" => "btn-t"]),
        Ht::button(Icons::ui_graph_cdf(), ["class" => "btn-t"]),
        '</div>';*/

    // X axis
    echo '<div class="', $fgm->control_class("fx", "entryi"), '">',
        '<label for="x_entry">X axis</label>',
        '<div class="entry">',
        Ht::entry("x", (string) $Qreq->x, ["id" => "x_entry", "size" => 32]),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “search”</span>',
        '</div></div>';
    // Y axis
    echo '<div class="', $fgm->control_class("fy", "entryi"), '">',
        '<label for="y_entry">Y axis</label>',
        '<div class="entry">',
        Ht::entry("y", (string) $Qreq->y, ["id" => "y_entry", "size" => 32]),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “cdf”, “count”, “fraction”, “box <em>formula</em>”, “bar <em>formula</em>”</span>',
        '</div></div>';
    // Series
    echo '<div class="', $fgm->control_class("q1", "entryi"), '">',
        '<label for="q1">Data set</label>',
        '<div class="entry">',
        '<table class="js-row-order"><tbody id="qcontainer" data-row-template="',
        htmlspecialchars(formulas_qrow('$', "", "by-tag", 0)), '">';
    for ($i = 0; $i < count($styles); ++$i) {
        echo formulas_qrow($i + 1, $queries[$i], $styles[$i], $fgm->problem_status_at("q$i"));
    }
    echo "</tbody><tbody><tr><td>",
        Ht::button("Add data set", ["class" => "ui row-order-ui addrow"]),
        "</td></tr></tbody></table></div></div>\n";
    echo '<div class="g"></div>';
    echo Ht::submit("Graph");
    echo '</form>';
}


echo '<div style="margin:2em 0"><strong>More graphs:</strong>&nbsp; ';
$ghtml = array();
foreach ($Graphs as $g => $gname) {
    $ghtml[] = '<a' . ($g == $Graph ? ' class="q"' : '') . ' href="' . hoturl("graph", "g=$g") . '">' . htmlspecialchars($gname) . '</a>';
}
echo join(' <span class="barsep">·</span> ', $ghtml), '</div>';

$Conf->footer();
