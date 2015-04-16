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
if ($Me->isPC)
    $Graphs["procrastination"] = "Procrastination";
if ($Me->privChair)
    $Graphs["derp"] = "derp";
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
echo '<h2>', $Graphs[$Graph], "</h2>\n";
echo '<div id="hotgraph" style="position:relative;max-width:960px"></div>', "\n";

// Procrastination report
if ($Graph == "procrastination") {
    $rt = new ReviewTimes;
    $Conf->echoScript('jQuery(function () { hotcrp_graphs.procrastination("#hotgraph",' . json_encode($rt->json()) . '); })');
}

// Formula experiment
if ($Graph == "derp") {
    $fx = new Formula("avg(ovemer)", true);
    $fy = new Formula("avg(revexp)", true);
    $fxf = $fx->compile_function($Me);
    $fyf = $fy->compile_function($Me);
    $needs_review = $fx->needs_review() || $fy->needs_review();
    $psearch = new PaperSearch($Me, array("q" => "dec:yes THEN any"));
    $psearch->paperList();

    // load data
    $queryOptions = array("finalized" => true);
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
            if (@$psearch->thenmap[$prow->paperId] == 0)
                $d[] = "redtag";
            foreach ($revs as $rcid) {
                $d[0] = $fxf($prow, $rcid, $Me);
                $d[1] = $fyf($prow, $rcid, $Me);
                $data[] = $d;
            }
        }
    Dbl::free($result);
    $Conf->echoScript('jQuery(function () { hotcrp_graphs.scatter("#hotgraph",' . json_encode($data) . ',{xlabel:' . json_encode($fx->expression) . ',ylabel:' . json_encode($fy->expression) . '}); })');
}

echo "<hr class=\"c\" />\n";
$Conf->footer();
