<?php
require_once(dirname(__DIR__) . "/src/init.php");

$arg = Getopt::rest($argv, "hn:qeV", array("help", "name:", "quiet", "extensions", "verbose"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3test.php [-q] [--extensions] [FILE...]\n");
    exit(0);
}
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$extensions = isset($arg["e"]) || isset($arg["extensions"]);
$verbose = isset($arg["V"]) || isset($arg["verbose"]);

if (!$Conf->setting_data("s3_bucket")) {
    fwrite(STDERR, "* S3 is not configured for this conference\n");
    exit(1);
}
if (count($arg["_"]) == 0) {
    $arg["_"] = array("-");
}

$s3doc = $Conf->s3_docstore();
$ok = 0;

foreach ($arg["_"] as $fn) {
    if ($fn === "-") {
        $content = @stream_get_contents(STDIN);
    } else {
        $content = @file_get_contents($fn);
    }
    if ($content === false) {
        $error = error_get_last();
        $fn = ($fn === "-" ? "<stdin>" : $fn);
        if (!$quiet) {
            echo "$fn: " . $error["message"] . "\n";
        }
        $ok = 2;
    } else {
        $doc = new DocumentInfo(["content" => $content], $Conf);
        if ($extensions && preg_match('/(\.\w+)\z/', $fn, $m)
            && ($mtx = Mimetype::lookup($m[1]))) {
            $doc->mimetype = $mtx->mimetype;
        } else {
            $doc->mimetype = Mimetype::content_type($content);
        }
        $s3fn = $doc->s3_key();
        if (!$s3doc->head($s3fn)) {
            if (!$quiet) {
                echo "$fn: $s3fn not found\n";
            }
            $ok = 1;
        } else if ($verbose) {
            echo "$fn: $s3fn OK\n";
        }
    }
}

exit($ok);
