<?php
require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:d", ["help", "name:", "dry-run"]);
if (isset($arg["h"]) || isset($arg["help"]) || count($arg["_"]) > 1) {
    fwrite(STDOUT, "Usage: php batch/assign.php [-n CONFID] [-d|--dry-run] [FILE]
Perform a CSV bulk assignment.

Options include:
  -d, --dry-run          Output CSV describing assignment.\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

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
$assignset = new AssignmentSet($Conf->root_user(), true);
$assignset->parse($text, $filename);
if ($assignset->has_error()) {
    fwrite(STDERR, $assignset->full_feedback_text());
} else if ($assignset->is_empty()) {
    fwrite(STDERR, "$filename: Assignment makes no changes.\n");
} else if (isset($arg["d"]) || isset($arg["dry-run"])) {
    fwrite(STDOUT, $assignset->make_acsv()->unparse());
} else {
    $assignset->execute();
    $pids = $assignset->assigned_pids();
    $pidt = $assignset->numjoin_assigned_pids(", #");
    fwrite(STDERR, "$filename: Assigned "
        . join(", ", $assignset->assigned_types())
        . " to " . pluralx($pids, "paper") . " #" . $pidt . ".\n");
}
