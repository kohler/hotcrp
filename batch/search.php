<?php
require_once(preg_replace('/\/batch\/[^\/]+/', '/src/siteloader.php', __FILE__));

$arg = Getopt::rest($argv, "hn:t:f:N", ["help", "name:", "type:", "field:", "show:", "header", "no-header", "sitename"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/search.php [-n CONFID] [-t COLLECTION] [-f FIELD]+ [QUERY...]
Output a CSV file containing the FIELDs for the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -f, --show FIELD       Include FIELD in output.
  -N, --sitename         Include site name and class in CSV.
  --header               Always include CSV header.
  --no-header            Omit CSV header.
  QUERY...               A search term.\n");
    exit(0);
}
if (isset($arg["type"]) && !isset($arg["t"])) {
    $arg["t"] = $arg["type"];
}

require_once(SiteLoader::find("src/init.php"));

$user = $Conf->root_user();
$t = get($arg, "t", "s");
$searchtypes = PaperSearch::search_types($user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/search.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
$paperlist = new PaperList("empty", $search);
$paperlist->set_view("pid", true);
$fields = array_merge(mkarray(get($arg, "f", [])),
                      mkarray(get($arg, "field", [])),
                      mkarray(get($arg, "show", [])));
foreach ($fields as $f) {
    $paperlist->set_view($f, true);
}
list($header, $body) = $paperlist->text_csv();
foreach ($search->problem_texts() as $w) {
    fwrite(STDERR, "$w\n");
}
if (!empty($body)) {
    $csv = new CsvGenerator;
    $sitename = isset($arg["N"]) || isset($arg["sitename"]);
    $siteid = $Conf->opt("confid");
    $siteclass = $Conf->opt("siteclass");
    if ((isset($arg["header"]) || count($header) > 1 || $sitename)
        && !isset($arg["no-header"])) {
        $header = array_keys($header);
        if ($sitename) {
            array_unshift($header, "sitename", "siteclass");
        }
        $csv->add_row($header);
    }
    foreach ($body as $row) {
        if ($sitename) {
            array_unshift($row, $siteid, $siteclass);
        }
        $csv->add_row($row);
    }
    fwrite(STDOUT, $csv->unparse());
}
