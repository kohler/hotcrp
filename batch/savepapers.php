<?php
$ConfSiteBase = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSiteBase/src/init.php");
require_once("$ConfSiteBase/lib/getopt.php");

$arg = getopt_rest($argv, "hn:q", array("help", "name:", "quiet", "disable",
                                        "disable-users"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/savepapers.php [-n CONFID] [--disable-users] FILE\n");
    exit(0);
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
$quiet = isset($arg["q"]) || isset($arg["quiet"]);
$disable_users = isset($arg["disable"]) || isset($arg["disable-users"]);

if ($file === "-")
    $content = stream_get_contents(STDIN);
else
    $content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "$file: Read error\n");
    exit(1);
}

if (($jp = json_decode($content)) === false) {
    fwrite(STDERR, "$file: bad JSON (" . json_last_error_msg() . ")\n");
    exit(1);
}

if (is_object($jp))
    $jp = array($jp);
$index = 0;
foreach ($jp as $j) {
    ++$index;
    $prefix = @$j->id ? "#$j->id: " : "new paper #$index: ";
    if (!$quiet)
        fwrite(STDERR, $prefix);
    $ps = new PaperStatus(array("no_email" => true,
                                "disable_users" => $disable_users,
                                "allow_error" => array("topics", "options")));
    $res = $ps->save($j);
    if (!$quiet)
        fwrite(STDERR, $res ? "saved\n" : "failed\n");
    foreach ($ps->error_html() as $msg)
        fwrite(STDERR, $prefix . htmlspecialchars_decode($msg) . "\n");
    if (!$res)
        exit(1);
}
