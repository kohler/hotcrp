<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:m:p:d:f:", array("help", "name:", "mimetype:", "paper:", "dtype:", "filename:", "no-file-storage"));
if (!isset($arg["d"]))
    $arg["d"] = get($arg, "dtype") ? : "0";
if (!isset($arg["p"]))
    $arg["p"] = get($arg, "paper") ? : "0";
if (!isset($arg["f"]))
    $arg["f"] = get($arg, "filename");
if (!isset($arg["m"]))
    $arg["m"] = get($arg, "mimetype");
if (isset($arg["h"]) || isset($arg["help"])
    || !is_numeric($arg["d"])
    || !is_numeric($arg["p"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    $status = isset($arg["h"]) || isset($arg["help"]) ? 0 : 1;
    fwrite($status ? STDERR : STDOUT,
           "Usage: php batch/adddoc.php [-n CONFID] [-p PID] [-m MIMETYPE] [-d DTYPE] [--no-file-storage] FILE\n");
    exit($status);
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

$docclass = $Conf->docclass((int) $arg["d"]);
$docclass->set_no_database_storage();
if (isset($arg["no-file-storage"]))
    $docclass->set_no_file_storage();
$docarg = ["paperId" => (int) $arg["p"], "documentType" => (int) $arg["d"],
           "content" => $content];
if (get($arg, "f"))
    $docarg["filename"] = $arg["f"];
$docarg["mimetype"] = Mimetype::content_type($content, get($arg, "m"));
$doc = new DocumentInfo($docarg);
$docclass->store($doc);
