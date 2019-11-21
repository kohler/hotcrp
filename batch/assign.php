<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:d", ["help", "name:", "dry-run"]);
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/assign.php [-n CONFID] [-d|--dry-run] [FILE]
Perform a CSV bulk assignment.

Options include:
  -d, --dry-run          Output CSV describing assignment.\n");
    exit(0);
}

require_once("$ConfSitePATH/src/init.php");

if (empty($arg["_"])) {
    $filename = "<stdin>";
    $text = stream_get_contents(STDIN);
} else {
    $filename = $arg["_"][0];
    $text = @file_get_contents($filename);
    if ($text === false) {
        $m = preg_replace('{.*: }', "", error_get_last()["message"]);
        fwrite(STDERR, "$filename: $m\n");
        exit(1);
    }
}

$text = convert_to_utf8($text);
$user = $Conf->site_contact();
$assignset = new AssignmentSet($user, true);
$assignset->parse($text, $filename);
if ($assignset->has_error()) {
    foreach ($assignset->errors_text(true) as $e) {
        fwrite(STDERR, "$e\n");
    }
} else if ($assignset->is_empty()) {
    fwrite(STDERR, "$filename: Assignment makes no changes.\n");
} else if (isset($arg["d"]) || isset($arg["dry-run"])) {
    $acsv = $assignset->unparse_csv();
    fwrite(STDOUT, $acsv->unparse());
} else {
    $assignset->execute();
    $pids = $assignset->assigned_pids();
    $pidt = join(", #", $assignset->assigned_pids(true));
    fwrite(STDERR, "$filename: Assigned "
        . join(", ", $assignset->assigned_types())
        . " to " . pluralx($pids, "paper") . " #" . $pidt . ".\n");
}
