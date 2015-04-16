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
    $Graphs["formulas"] = "Formulas";
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
function formulas_qrow($i, $q, $s) {
    if ($q === "" || $q === "all")
        $q = "(All)";
    $t = '<tr><td class="lentry">' . Ht::entry("q$i", $q, array("size" => 40, "id" => "q$i", "hottemptext" => "(All)"));
    $t .= " &nbsp;" . Ht::select("s$i", array("plain" => "plain", "redtag" => "red", "greentag" => "green"), $s !== "" ? $s : "plain", array("id" => "s$i"));
    $t .= '</td><td class="nw"><a href="#" class="qx row_up" onclick="return author_change.delta(this,-1)" tabindex="-1">&#x25b2;</a><a href="#" class="qx row_down" onclick="return author_change.delta(this,1)" tabindex="-1">&#x25bc;</a><a href="#" class="qx row_kill" onclick="return author_change.delta(this,Infinity)" tabindex="-1">x</a></td></tr>';
    return $t;
}

if ($Graph == "formulas") {
    $fx = $fy = null;

    // derive a sample graph
    if (!isset($_REQUEST["fx"]) || !isset($_REQUEST["fy"])) {
        $all_review_fields = ReviewForm::field_list_all_rounds();
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

    if (@$_REQUEST["fx"] && @$_REQUEST["fy"]) {
        $fx = new Formula($_REQUEST["fx"], true);
        $fy = new Formula($_REQUEST["fy"], true);

        $errs = array();
        if ($fx->error_html())
            $errs[] = "X axis formula: " . $fx->error_html();
        if ($fy->error_html())
            $errs[] = "Y axis formula: " . $fy->error_html();
        if (count($errs)) {
            $Conf->errorMsg(join("<br>", $errs));
            $fx = $fy = null;
        }
    }

    $queries = $styles = array();
    $have_all = false;
    for ($i = 0; isset($_REQUEST["q$i"]); ++$i) {
        $q = trim($_REQUEST["q$i"]);
        if (strcasecmp($q, "none")) {
            $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
            $styles[] = trim((string) @$_REQUEST["s$i"]);
        }
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

    if ($fx && $fy) {
        $fxf = $fx->compile_function($Me);
        $fyf = $fy->compile_function($Me);
        $needs_review = $fx->needs_review() || $fy->needs_review();

        $psearch = new PaperSearch($Me, array("q" => join(" THEN ", $queries)));
        $psearch->paperList();

        echo "<h2>", htmlspecialchars($fy->expression), " vs. ", htmlspecialchars($fx->expression), "</h2>\n";
        echo_graph();

        // load data
        $queryOptions = array("paperId" => $psearch->paperList());
        $fx->add_query_options($queryOptions, $Me);
        $fy->add_query_options($queryOptions, $Me);
        $result = Dbl::qe_raw($Conf->paperQuery($Me, $queryOptions));
        $data = array();
        while (($prow = PaperInfo::fetch($result, $Me)))
            if ($Me->can_view_paper($prow)) {
                if ($needs_review)
                    $revs = $prow->viewable_submitted_reviewers($Me, null);
                else
                    $revs = array(null);
                $d = array(0, 0, $prow->paperId);
                if (($style = @$psearch->thenmap[$prow->paperId]) !== null
                    && @$styles[$style])
                    $d[] = $styles[$style];
                foreach ($revs as $rcid) {
                    $d[0] = $fxf($prow, $rcid, $Me);
                    $d[1] = $fyf($prow, $rcid, $Me);
                    $data[] = $d;
                }
            }
        Dbl::free($result);
        $Conf->echoScript('jQuery(function () { hotcrp_graphs.scatter("#hotgraph",' . json_encode($data) . ',{xlabel:' . json_encode($fx->expression) . ',ylabel:' . json_encode($fy->expression) . '}); })');
    } else
        echo "<h2>Formulas</h2>\n";

    echo Ht::form_div(hoturl("graph", "g=formulas"), array("method" => "GET"));
    echo '<table>',
        '<tr><td class="lcaption"><label for="fx">X axis</label></td>',
        '<td class="lentry">', Ht::entry("fx", (string) @$_REQUEST["fx"] !== "" ? $_REQUEST["fx"] : "", array("id" => "fx", "size" => 32)), '</td></tr>',
        '<tr><td class="lcaption"><label for="fy">Y axis</label></td>',
        '<td class="lentry" style="padding-bottom:0.8em">', Ht::entry("fy", (string) @$_REQUEST["fy"] !== "" ? $_REQUEST["fy"] : "", array("id" => "fy", "size" => 32)), '</td></tr>',
        '<tr><td class="lcaption"><label for="q">Show</label></td>',
        '<td class="lentry"><table><tbody id="qcontainer">';
    for ($i = 0; $i < count($styles); ++$i)
        echo formulas_qrow($i, $queries[$i], $styles[$i]);
    echo "</tbody></table>\n";
    echo '<tr><td></td><td class="lentry">',
        Ht::js_button("Add point type", "hotcrp_graphs.formulas_add_qrow()"),
        '</td></tr>';
    echo '</table>';
    echo '<div class="g"></div>';
    echo Ht::submit(null, "Graph");
    echo '</div></form>';
    $Conf->echoScript("hotcrp_graphs.formulas_qrow=" . json_encode(formulas_qrow('$', "", "plain")));
}


echo "<hr class=\"c\" />\n";
$Conf->footer();
