<?php
// graph.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papersearch.php");

$Graph = $Qreq->g;
if (!$Graph
    && preg_match(',\A/(\w+)(/|\z),', Navigation::path(), $m))
    $Graph = $Qreq->g = $m[1];
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
if (!count($Graphs))
    $Me->escape();
reset($Graphs);

$GraphSynonym = array("reviewerlameness" => "procrastination");
if ($Graph && isset($GraphSynonym[$Graph]))
    $Graph = $GraphSynonym[$Graph];
if (!$Graph || !isset($Graphs[$Graph]))
    SelfHref::redirect($Qreq, ["g" => key($Graphs)]);

// Header and body
$Conf->header("Graphs", "graphbody");
echo Ht::unstash();
echo $Conf->make_script_file("scripts/d3-hotcrp.min.js", true);
echo $Conf->make_script_file("scripts/graph.js");

function echo_graph() {
    echo '<div id="hotgraph" style="position:relative;max-width:960px;margin-bottom:4em"></div>', "\n";
}

// Procrastination report
if ($Graph == "procrastination") {
    echo "<h2>Procrastination</h2>\n";
    echo_graph();
    $rt = new ReviewTimes($Me);
    echo Ht::unstash_script('jQuery(function () { hotcrp_graphs.procrastination("#hotgraph",' . json_encode_browser($rt->json()) . '); })');
}


// Formula experiment
function formulas_qrow($i, $q, $s, $errf) {
    if ($q === "all")
        $q = "";
    $klass = ($errf ? "has-error " : "") . "papersearch";
    $t = '<tr><td class="lentry">' . Ht::entry("q$i", $q, array("size" => 40, "placeholder" => "(All)", "class" => $klass, "id" => "q$i"));
    $t .= " <span style=\"padding-left:1em\">Style:</span> &nbsp;" . Ht::select("s$i", array("by-tag" => "by tag", "plain" => "plain", "redtag" => "red", "orangetag" => "orange", "yellowtag" => "yellow", "greentag" => "green", "bluetag" => "blue", "purpletag" => "purple", "graytag" => "gray"), $s !== "" ? $s : "by-tag");
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
        $all_review_fields = $Conf->all_review_fields();
        $field1 = get($all_review_fields, "overAllMerit");
        $field2 = null;
        foreach ($all_review_fields as $f)
            if ($f->has_options && !$field1)
                $field1 = $f;
            else if ($f->has_options && !$field2 && $field1 != $f)
                $field2 = $f;
        unset($Qreq->x, $Qreq->y);
        if ($field1)
            $Qreq->y = "avg(" . $field1->search_keyword() . ")";
        if ($field1 && $field2)
            $Qreq->x = "avg(" . $field2->search_keyword() . ")";
        else
            $Qreq->x = "pid";
    }

    $fg = null;
    if ($Qreq->x && $Qreq->y) {
        $fg = new FormulaGraph($Me, $Qreq->x, $Qreq->y);
        if ($Qreq->xorder)
            $fg->set_xorder($Qreq->xorder);
        if (!empty($fg->error_html))
            Conf::msg_error(join("<br/>", $fg->error_html));
    }

    $queries = $styles = array();
    for ($i = 1; isset($Qreq["q$i"]); ++$i) {
        $q = trim($Qreq["q$i"]);
        $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
        $styles[] = trim((string) $Qreq["s$i"]);
    }
    if (count($queries) == 0) {
        $queries[0] = "";
        $styles[0] = trim((string) $Qreq["s0"]);
    }
    while (count($queries) > 1 && $queries[count($queries) - 1] == $queries[count($queries) - 2]) {
        array_pop($queries);
        array_pop($styles);
    }
    if (count($queries) == 1 && $queries[0] == "all")
        $queries[0] = "";
    if ($fg) {
        $fgerr_begin = count($fg->error_html);
        for ($i = 0; $i < count($queries); ++$i)
            $fg->add_query($queries[$i], $styles[$i], "q$i");
        if (count($fg->error_html) > $fgerr_begin)
            $Conf->warnMsg(join("<br/>", array_slice($fg->error_html, $fgerr_begin)));

        $xhtml = htmlspecialchars($fg->fx_expression());
        if ($fg->fx_type == FormulaGraph::X_TAG)
            $xhtml = "tag";

        if ($fg->fx_type == FormulaGraph::X_QUERY)
            /* no header */;
        else if ($fg->type == FormulaGraph::CDF)
            echo "<h2>$xhtml CDF</h2>\n";
        else if (($fg->type & FormulaGraph::BARCHART)
                 && $fg->fy->expression === "sum(1)")
            echo "<h2>$xhtml</h2>\n";
        else if ($fg->type & FormulaGraph::BARCHART)
            echo "<h2>", htmlspecialchars($fg->fy->expression), " by $xhtml</h2>\n";
        else
            echo "<h2>", htmlspecialchars($fg->fy->expression), " vs. $xhtml</h2>\n";
        echo_graph();

        $gtype = "scatter";
        if ($fg->type & FormulaGraph::BARCHART)
            $gtype = "barchart";
        else if ($fg->type == FormulaGraph::BOXPLOT)
            $gtype = "boxplot";
        else if ($fg->type == FormulaGraph::CDF)
            $gtype = "cdf";
        echo Ht::unstash_script('hotgraph_info=' . json_encode_browser(["selector" => "#hotgraph"] + $fg->graph_json()) . ';'
            . "\$(function () { hotcrp_graphs.{$gtype}(hotgraph_info) });"), "\n";
    } else
        echo "<h2>Formulas</h2>\n";

    echo Ht::form(hoturl("graph", "g=formula"), array("method" => "get"));
    echo '<table>';
    // X axis
    echo '<tr><td class="lcaption"><label for="x_entry">X axis</label></td>',
        '<td class="lentry">', Ht::entry("x", (string) $Qreq->x, array("id" => "x_entry", "size" => 32, "class" => $fg && get($fg->errf, "fx") ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “search”</span>',
        '</td></tr>';
    // Y axis
    echo '<tr><td class="lcaption"><label for="y_entry">Y axis</label></td>',
        '<td class="lentry" style="padding-bottom:0.8em">', Ht::entry("y", (string) $Qreq->y, array("id" => "y_entry", "size" => 32, "class" => $fg && get($fg->errf, "fy") ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “cdf”, “count”, “fraction”, “box <em>formula</em>”, “bar <em>formula</em>”</span>',
        '</td></tr>';
    // Series
    echo '<tr><td class="lcaption"><label for="q1">Search</label></td>',
        '<td class="lentry">',
        '<table class="js-row-order"><tbody id="qcontainer" data-row-template="',
        htmlspecialchars(formulas_qrow('$', "", "by-tag", false)), '">';
    for ($i = 0; $i < count($styles); ++$i)
        echo formulas_qrow($i + 1, $queries[$i], $styles[$i], $fg && get($fg->errf, "q$i"));
    echo "</tbody><tbody><tr><td class=\"lentry\">",
        Ht::link("Add search", "#", ["class" => "ui btn row-order-ui addrow"]),
        "</td></tr></tbody></table></td></tr>\n";
    echo '</table>';
    echo '<div class="g"></div>';
    echo Ht::submit(null, "Graph");
    echo '</form>';
}


echo '<div style="margin:2em 0"><strong>More graphs:</strong>&nbsp; ';
$ghtml = array();
foreach ($Graphs as $g => $gname)
    $ghtml[] = '<a' . ($g == $Graph ? ' class="q"' : '') . ' href="' . hoturl("graph", "g=$g") . '">' . htmlspecialchars($gname) . '</a>';
echo join(' <span class="barsep">·</span> ', $ghtml), '</div>';

echo "<hr class=\"c\" />\n";
$Conf->footer();
