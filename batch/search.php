<?php
// search.php -- HotCRP batch search script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = (new Getopt)->long("n:,name:", "t:,type:", "f[],field[],show[]", "N,sitename", "header", "no-header", "help,h")->parse($argv);
if (isset($arg["help"])) {
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

require_once(SiteLoader::find("src/init.php"));

$user = $Conf->root_user();
$t = $arg["t"] ?? "s";
if (!in_array($t, PaperSearch::viewable_limits($user, $t))) {
    fwrite(STDERR, "batch/search.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
$paperlist = new PaperList("empty", $search);
$paperlist->set_view("pid", true);
foreach ($arg["f"] ?? [] as $f) {
    $paperlist->set_view($f, true);
}
list($header, $body) = $paperlist->text_csv();
if ($search->has_problem()) {
    fwrite(STDERR, $search->full_feedback_text());
}
if (!empty($body)) {
    $csv = new CsvGenerator;
    $sitename = isset($arg["N"]);
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
