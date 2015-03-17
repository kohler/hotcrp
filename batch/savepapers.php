<?php
require_once("src/init.php");
require_once("lib/getopt.php");

$arg = getopt_rest($argv, "hn:", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/savepapers.php [-n CONFID] FILE\n");
    exit(0);
}

$file = count($arg["_"]) ? $arg["_"][0] : "-";
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
foreach ($jp as $j) {
    $ps = new PaperStatus(array("no_email" => true,
                                "allow_error" => array("topics", "options")));
    $res = $ps->save($j);
    foreach ($ps->error_html() as $msg)
        fwrite(STDERR, ($j->id ? "#$j->id: " : "paper: ") . htmlspecialchars_decode($msg) . "\n");
    if (!$res)
        exit(1);
}
