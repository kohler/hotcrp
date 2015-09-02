<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:qe", array("help", "name:", "quiet", "extensions"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3test.php [-q] [--extensions] [FILE...]\n");
    exit(0);
}
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$extensions = isset($arg["e"]) || isset($arg["extensions"]);

if (!$Conf->setting_data("s3_bucket"))
    die("* S3 is not configured for this conference\n");
if (count($arg["_"]) == 0)
    $arg["_"] = array("-");

$s3doc = HotCRPDocument::s3_document();
$ok = 0;

foreach ($arg["_"] as $fn) {
    if ($fn === "-")
        $content = @stream_get_contents(STDIN);
    else
        $content = @file_get_contents($fn);
    if ($content === false) {
        $error = error_get_last();
        $fn = ($fn === "-" ? "<stdin>" : $fn);
        if (!$quiet)
            echo "$fn: " . $error["message"] . "\n";
        $ok = 2;
    } else {
        $doc = (object) array("sha1" => sha1($content, true));
        if (!($extensions && preg_match('/\.(\w+)\z/', $fn, $m)
              && ($doc->mimetype = Mimetype::lookup_extension($m[1]))))
            $doc->mimetype = Mimetype::sniff($content);
        $s3fn = HotCRPDocument::s3_filename($doc);
        if (!$s3doc->check($s3fn)) {
            if (!$quiet)
                echo "$fn: $s3fn not found\n";
            $ok = 1;
        }
    }
}

exit($ok);
