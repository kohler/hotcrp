<?php
require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:yq", ["help", "name:", "yes", "quiet"]);
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) == 0) {
    fwrite(STDOUT, "Usage: php batch/deletepapers.php [-n CONFID] [OPTIONS] PAPER...

Options include:
  -y, --yes              Assume yes.
  -q, --quiet            Quiet.\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

$yes = isset($arg["y"]) || isset($arg["yes"]);
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$user = $Conf->root_user();
$search = new PaperSearch($user, ["t" => "all", "q" => join(" ", $arg["_"])]);
$pids = $search->paper_ids();
if (($pids = $search->paper_ids())) {
    $ndeleted = false;
    foreach ($user->paper_set(["paperId" => $pids]) as $prow) {
        $pid = "#{$prow->paperId}";
        if ($prow->title !== "") {
            $pid .= " (" . UnicodeHelper::utf8_abbreviate($prow->title, 40) . ")";
        }
        if (!$yes) {
            $str = "";
            while (!preg_match('/\A[ynq]/i', $str)) {
                fwrite(STDERR, "Delete $pid? (y/n/q) ");
                $str = fgets(STDIN);
            }
            $str = strtolower($str);
            if (str_starts_with($str, "q")) {
                exit(1);
            } else if (str_starts_with($str, "n")) {
                continue;
            }
        }
        if (!$quiet) {
            fwrite(STDERR, "Deleting $pid\n");
        }
        if (!$prow->delete_from_database($user)) {
            exit(2);
        }
        $ndeleted = true;
    }
    exit($ndeleted ? 0 : 1);
} else if ($search->has_problem()) {
    fwrite(STDERR, $search->full_feedback_text());
    exit(1);
} else {
    fwrite(STDERR, "No matching papers.\n");
    exit(1);
}
