<?php
// graph.php -- HotCRP review preference graph drawing page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");

$Graph = @$_REQUEST["g"];
if (!$Graph
    && preg_match(',\A/(\w+)(/|\z),', Navigation::path(), $m))
    $Graph = $_REQUEST["g"] = $m[1];

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
    echo Ht::unstash_script('jQuery(function () { hotcrp_graphs.procrastination("#hotgraph",' . json_encode($rt->json()) . '); })');
}


// Formula experiment
function formulas_qrow($i, $q, $s, $errf) {
    if ($q === "all")
        $q = "";
    $klass = ($errf ? "setting_error " : "") . "hotcrp_searchbox";
    $t = '<tr><td class="lentry">' . Ht::entry("q$i", $q, array("size" => 40, "placeholder" => "(All)", "class" => $klass));
    $t .= " <span style=\"padding-left:1em\">Style:</span> &nbsp;" . Ht::select("s$i", array("plain" => "plain", "by-tag" => "by tag", "redtag" => "red", "orangetag" => "orange", "yellowtag" => "yellow", "greentag" => "green", "bluetag" => "blue", "purpletag" => "purple", "graytag" => "gray"), $s !== "" ? $s : "by-tag");
    $t .= ' <span class="nb btnbox aumovebox" style="margin-left:1em"><a href="#" class="qx btn" onclick="return author_change(this,-1)" tabindex="-1">&#x25b2;</a><a href="#" class="qx btn" onclick="return author_change(this,1)" tabindex="-1">&#x25bc;</a><a href="#" class="qx btn" onclick="return author_change(this,Infinity)" tabindex="-1">✖</a></span></td></tr>';
    return $t;
}

if ($Graph == "formula") {
    // derive a sample graph
    if (!isset($_REQUEST["fx"]) || !isset($_REQUEST["fy"])) {
        $all_review_fields = $Conf->all_review_fields();
        $field1 = get($all_review_fields, "overAllMerit");
        $field2 = null;
        foreach ($all_review_fields as $f)
            if ($f->has_options && !$field1)
                $field1 = $f;
            else if ($f->has_options && !$field2 && $field1 != $f)
                $field2 = $f;
        unset($_REQUEST["fx"], $_REQUEST["fy"]);
        if ($field1)
            $_REQUEST["fy"] = "avg(" . $field1->abbreviation() . ")";
        if ($field1 && $field2)
            $_REQUEST["fx"] = "avg(" . $field2->abbreviation() . ")";
        else
            $_REQUEST["fx"] = "pid";
    }

    $fg = null;
    if (@$_REQUEST["fx"] && @$_REQUEST["fy"]) {
        $fg = new FormulaGraph($Me, $_REQUEST["fx"], $_REQUEST["fy"]);
        if (count($fg->error_html))
            Conf::msg_error(join("<br/>", $fg->error_html));
    }

    $queries = $styles = array();
    for ($i = 1; isset($_REQUEST["q$i"]); ++$i) {
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

        $xhtml = htmlspecialchars($fg->fx->expression);
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

        echo Ht::unstash();
        echo '<script>hotgraph_info={data:', json_encode($fg->data()), ",\n",
            '  selector:"#hotgraph",', $fg->axis_info_settings("x"),
            ',', $fg->axis_info_settings("y"), "};\n";
        $gtype = "scatter";
        if ($fg->type & FormulaGraph::BARCHART)
            $gtype = "barchart";
        else if ($fg->type == FormulaGraph::BOXPLOT)
            $gtype = "boxplot";
        else if ($fg->type == FormulaGraph::CDF)
            $gtype = "cdf";
        echo '$(function () { hotcrp_graphs.', $gtype, "(hotgraph_info) });\n</script>";
    } else
        echo "<h2>Formulas</h2>\n";

    echo Ht::form_div(hoturl("graph", "g=formula"), array("method" => "get"));
    echo '<table>';
    // X axis
    echo '<tr><td class="lcaption"><label for="fx">X axis</label></td>',
        '<td class="lentry">', Ht::entry("fx", (string) @$_REQUEST["fx"] !== "" ? $_REQUEST["fx"] : "", array("id" => "fx", "size" => 32, "class" => $fg && @$fg->errf["fx"] ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “search”</span>',
        '</td></tr>';
    // Y axis
    echo '<tr><td class="lcaption"><label for="fy">Y axis</label></td>',
        '<td class="lentry" style="padding-bottom:0.8em">', Ht::entry("fy", (string) @$_REQUEST["fy"] !== "" ? $_REQUEST["fy"] : "", array("id" => "fy", "size" => 32, "class" => $fg && @$fg->errf["fy"] ? "setting_error" : "")),
        '<span class="hint" style="padding-left:2em"><a href="', hoturl("help", "t=formulas"), '">Formula</a> or “cdf”, “count”, “fraction”, “box <em>formula</em>”, “bar <em>formula</em>”</span>',
        '</td></tr>';
    // Series
    echo '<tr><td class="lcaption"><label for="q">Search</label></td>',
        '<td class="lentry"><table><tbody id="qcontainer" data-row-template="',
        htmlspecialchars(formulas_qrow('$', "", "by-tag", false)), '">';
    for ($i = 0; $i < count($styles); ++$i)
        echo formulas_qrow($i + 1, $queries[$i], $styles[$i], $fg && @$fg->errf["q$i"]);
    echo "</tbody></table>\n";
    echo '<tr><td></td><td class="lentry">',
        Ht::js_button("Add search", "hotcrp_graphs.formulas_add_qrow()"),
        '</td></tr>';
    echo '</table>';
    echo '<div class="g"></div>';
    echo Ht::submit(null, "Graph");
    echo '</div></form>';
}


echo '<div style="margin:2em 0"><strong>More graphs:</strong>&nbsp; ';
$ghtml = array();
foreach ($Graphs as $g => $gname)
    $ghtml[] = '<a' . ($g == $Graph ? ' class="q"' : '') . ' href="' . hoturl("graph", "g=$g") . '">' . htmlspecialchars($gname) . '</a>';
echo join(' <span class="barsep">·</span> ', $ghtml), '</div>';

echo "<hr class=\"c\" />\n";
$Conf->footer();
