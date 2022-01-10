<?php
// paperjson.php -- HotCRP paper export script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:t:N1", ["help", "name:", "type:", "sitename", "single"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/paperjson.php [-n CONFID] [-t COLLECTION] [QUERY...]
Output a JSON file containing the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -N, --sitename         Include site name and class in JSON.
  -1, --single           Output first matching paper rather than an array.
  QUERY...               A search term.\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

$user = $Conf->root_user();
$t = $arg["t"] ?? "s";
if (!in_array($t, PaperSearch::viewable_limits($user, $t))) {
    fwrite(STDERR, "batch/paperjson.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$q = join(" ", $arg["_"]);
$search = new PaperSearch($user, ["q" => $q, "t" => $t]);
if ($search->has_problem()) {
    fwrite(STDERR, $search->full_feedback_text());
}

$pj_first = [];
if (isset($arg["N"]) || isset($arg["sitename"])) {
    if ($Conf->opt("confid")) {
        $pj_first["sitename"] = $Conf->opt("confid");
    }
    if ($Conf->opt("siteclass")) {
        $pj_first["siteclass"] = $Conf->opt("siteclass");
    }
}

$apj = [];
$pset = $Conf->paper_set(["paperId" => $search->paper_ids(), "topics" => true, "options" => true]);
$ps = new PaperStatus($Conf, $user, ["hide_docids" => true]);
foreach ($pset as $prow) {
    $pj1 = $ps->paper_json($prow);
    if (!empty($pj_first)) {
        $pj1 = (object) ($pj_first + (array) $pj1);
    }
    $apj[] = $pj1;
}

if (isset($arg["1"]) || isset($arg["single"])) {
    if (!empty($apj)) {
        echo json_encode($apj[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    }
} else {
    echo json_encode($apj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
}
