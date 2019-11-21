<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:t:f:", ["help", "name:", "type:", "field:", "show:", "header"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/search.php [-n CONFID] [-t COLLECTION] [-f FIELD]+ [QUERY...]
Output a CSV file containing the FIELDs for the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -f, --show FIELD       Include FIELD in output.
  --header               Always include header.
  QUERY...               A search term.\n");
    exit(0);
}
if (isset($arg["type"]) && !isset($arg["t"])) {
    $arg["t"] = $arg["type"];
}

require_once("$ConfSitePATH/src/init.php");

$user = $Conf->site_contact();
$t = get($arg, "t", "s");
$searchtypes = PaperSearch::search_types($user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/search.php: No search collection ‘{$t}’\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
$paperlist = new PaperList($search, ["report" => "empty"]);
$paperlist->set_view("pid", true);
$fields = array_merge(mkarray(get($arg, "f", [])),
                      mkarray(get($arg, "field", [])),
                      mkarray(get($arg, "show", [])));
foreach ($fields as $f) {
    $paperlist->set_view($f, true);
}
list($header, $body) = $paperlist->text_csv("empty");
foreach ($search->warnings as $w) {
    fwrite(STDERR, "$w\n");
}
if (!empty($body)) {
    $csv = new CsvGenerator;
    if (isset($arg["header"]) || count($header) > 1) {
        $csv->add(array_keys($header));
    }
    $csv->add($body);
    fwrite(STDOUT, $csv->unparse());
}
