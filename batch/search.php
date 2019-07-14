<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:t:f:", ["help", "name:", "type:", "field:"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/search.php [-n CONFID] [-t TYPE] [-f FIELD]+ [QUERY...]\n");
    exit(0);
}
if (isset($arg["type"]) && !isset($arg["t"]))
    $arg["t"] = $arg["type"];

$user = $Conf->site_contact();
$t = get($arg, "t", "s");
$searchtypes = PaperSearch::search_types($user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/search.php: No such search collection ‘{$t}’\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
$paperlist = new PaperList($search, ["report" => "empty"]);
$paperlist->set_view("pid", true);
if (isset($arg["f"])) {
    foreach (is_array($arg["f"]) ? $arg["f"] : [$arg["f"]] as $f)
        $paperlist->set_view($f, true);
}
if (isset($arg["field"])) {
    foreach (is_array($arg["field"]) ? $arg["field"] : [$arg["field"]] as $f)
        $paperlist->set_view($f, true);
}
list($header, $body) = $paperlist->text_csv("empty");
foreach ($search->warnings as $w) {
    fwrite(STDERR, "$w\n");
}
if (!empty($body)) {
    $csv = new CsvGenerator;
    if (count($header) > 1) {
        $csv->add(array_keys($header));
    }
    $csv->add($body);
    fwrite(STDOUT, $csv->unparse());
}
