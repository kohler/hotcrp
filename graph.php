<?php
// graph.php -- HotCRP review preference graph drawing page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");

$Graph = @$_REQUEST["g"];
if (!$Graph
    && preg_match(',\A/(\w+)(/|\z),', Navigation::path(), $m))
    $Graph = $m[1];
$Subgraph = @$_REQUEST["subg"];
if (!$Subgraph
    && preg_match(',\A/\w+/(.+?)/?\z,', Navigation::path()))
    $Subgraph = $m[2];

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
    redirectSelf(array("g" => key($Graphs)));

// Header and body
$Conf->header("Graphs", "graphbody", actionBar());
$Conf->echoScript("");
echo $Conf->make_script_file("scripts/d3.min.js", true);
echo $Conf->make_script_file("scripts/graph.js");

function echo_graph() {
    echo '<div id="hotgraph" style="position:relative;max-width:960px;margin-bottom:4em"></div>', "\n";
}

// Procrastination report
if ($Graph == "procrastination") {
    echo "<h2>Procrastination</h2>\n";
    echo_graph();
    $rt = new ReviewTimes;
    $Conf->echoScript('jQuery(function () { hotcrp_graphs.procrastination("#hotgraph",' . json_encode($rt->json()) . '); })');
}


// Formula experiment
function formulas_qrow($i, $q, $s, $errf) {
    if ($q === "" || $q === "all")
        $q = "(All)";
    $t = '<tr><td class="lentry">' . Ht::entry("q$i", $q, array("size" => 40, "id" => "q$i", "hottemptext" => "(All)", "class" => $errf ? "setting_error" : ""));
    $t .= " <span style=\"padding-left:1em\">Style:</span> &nbsp;" . Ht::select("s$i", array("default" => "default", "plain" => "plain", null, "redtag" => "red", "orangetag" => "orange", "yellowtag" => "yellow", "greentag" => "green", "bluetag" => "blue", "purpletag" => "purple", "graytag" => "gray"), $s !== "" ? $s : "default", array("id" => "s$i"));
    $t .= '</td><td class="nw"><a href="#" class="qx row_up" onclick="return author_change.delta(this,-1)" tabindex="-1">&#x25b2;</a><a href="#" class="qx row_down" onclick="return author_change.delta(this,1)" tabindex="-1">&#x25bc;</a><a href="#" class="qx row_kill" onclick="return author_change.delta(this,Infinity)" tabindex="-1">x</a></td></tr>';
    return $t;
}

if ($Graph == "formula") {
    // derive a sample graph
    if (!isset($_REQUEST["fx"]) || !isset($_REQUEST["fy"])) {
        $all_review_fields = ReviewForm::all_fields();
        $field1 = @$all_review_fields["overAllMerit"];
        $field2 = null;
        foreach ($all_review_fields as $f)
            if ($f->has_options && !$field1)
                $field1 = $f;
            else if ($f->has_options && !$field2 && $field1 != $f)
                $field2 = $f;
        unset($_REQUEST["fx"], $_REQUEST["fy"]);
        if ($field1)
            $_REQUEST["fy"] = "avg(" . $field1->analyze()->abbreviation . ")";
        if ($field1 && $field2)
            $_REQUEST["fx"] = "avg(" . $field2->analyze()->abbreviation . ")";
        else
            $_REQUEST["fx"] = "pid";
    }

    $fg = null;
    if (@$_REQUEST["fx"] && @$_REQUEST["fy"]) {
        $fg = new FormulaGraph($_REQUEST["fx"], $_REQUEST["fy"]);
        if (count($fg->error_html))
            $Conf->errorMsg(join("<br/>", $fg->error_html));
    }

    $queries = $styles = array();
    for ($i = 0; isset($_REQUEST["q$i"]); ++$i) {
        $q = trim($_REQUEST["q$i"]);
        $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
        $styles[] = trim((string) @$_REQUEST["s$i"]);
    }
    if (count($queries) == 0) {
        $queries[0] = "";
        $styles[0] = trim((string) @$_REQUEST["s0"]);
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

        if ($fg->fx_query)
            /* no header */;
        else if ($fg->type == FormulaGraph::CDF)
            echo "<h2>", htmlspecialchars($fg->fx->expression), " CDF</h2>\n";
        else if ($fg->type == FormulaGraph::BARCHART)
            echo "<h2>", htmlspecialchars($fg->fx->expression), "</h2>\n";
        else
            echo "<h2>", htmlspecialchars($fg->fy->expression), " vs. ", htmlspecialchars($fg->fx->expression), "</h2>\n";
        echo_graph();

        $data = $fg->data();
        if ($fg->type == FormulaGraph::CDF)
            $Conf->echoScript('jQuery(function () { hotcrp_graphs.cdf({selector:"#hotgraph",series:' . json_encode($data) . ',' . $fg->axis_info_settings("x") . ',ylabel:"CDF"}); })');
        else if ($fg->type)
            $Conf->echoScript('jQuery(function () { hotcrp_graphs.barchart({selector:"#hotgraph",data:' . json_encode($data) . ',' . $fg->axis_info_settings("x") . ',' . $fg->axis_info_settings("y") . '}); })');
        else
            $Conf->echoScript('jQuery(function () { hotcrp_graphs.scatter({selector:"#hotgraph",data:' . json_encode($data) . ',' . $fg->axis_info_settings("x") . ',' . $fg->axis_info_settings("y") . '}); })');
    } else
        echo "<h2>Formulas</h2>\n";

    echo Ht::form_div(hoturl("graph", "g=formula"), array("method" => "GET"));
    echo '<table>';
    // X axis
    echo '<tr><td class="lcaption"><label for="fx">X axis</label></td>',
        '<td class="lentry">', Ht::entry("fx", (string) @$_REQUEST["fx"] !== "" ? $_REQUEST["fx"] : "", array("id" => "fx", "size" => 32, "class" => $fg && @$fg->errf["fx"] ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “query”</span>',
        '</td></tr>';
    // Y axis
    echo '<tr><td class="lcaption"><label for="fy">Y axis</label></td>',
        '<td class="lentry" style="padding-bottom:0.8em">', Ht::entry("fy", (string) @$_REQUEST["fy"] !== "" ? $_REQUEST["fy"] : "", array("id" => "fy", "size" => 32, "class" => $fg && @$fg->errf["fy"] ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “cdf”, “count”, “fraction”</span>',
        '</td></tr>';
    // Series
    echo '<tr><td class="lcaption"><label for="q">Query</label></td>',
        '<td class="lentry"><table><tbody id="qcontainer">';
    for ($i = 0; $i < count($styles); ++$i)
        echo formulas_qrow($i, $queries[$i], $styles[$i], $fg && @$fg->errf["q$i"]);
    echo "</tbody></table>\n";
    echo '<tr><td></td><td class="lentry">',
        Ht::js_button("Add query", "hotcrp_graphs.formulas_add_qrow()"),
        '</td></tr>';
    echo '</table>';
    echo '<div class="g"></div>';
    echo Ht::submit(null, "Graph");
    echo '</div></form>';
    $Conf->echoScript("hotcrp_graphs.formulas_qrow=" . json_encode(formulas_qrow('$', "", "default", false)));
}


echo '<div style="margin:2em 0"><strong>More graphs:</strong>&nbsp; ';
$ghtml = array();
foreach ($Graphs as $g => $gname)
    $ghtml[] = '<a' . ($g == $Graph ? ' class="q"' : '') . ' href="' . hoturl("graph", "g=$g") . '">' . htmlspecialchars($gname) . '</a>';
echo join(' <span class="barsep">·</span> ', $ghtml), '</div>';

echo "<hr class=\"c\" />\n";
$Conf->footer();
