<?php
require_once("src/init.php");

$arg = getopt_rest($argv, "hm:p:n:d:f:", array("help", "mimetype:", "paper:", "dtype:", "filename:", "name:"));
if (!isset($arg["d"]))
    $arg["d"] = @$arg["dtype"] ? $arg["dtype"] : "0";
if (!isset($arg["p"]))
    $arg["p"] = @$arg["paper"] ? $arg["paper"] : "0";
if (!isset($arg["f"]))
    $arg["f"] = @$arg["filename"] ? $arg["filename"] : null;
if (!isset($arg["m"]))
    $arg["m"] = @$arg["mimetype"] ? $arg["mimetype"] : null;
if (isset($arg["h"]) || isset($arg["help"]) || !is_numeric($arg["d"]) || !is_numeric($arg["p"])
    || count($arg["_"]) > 1
    || (count($arg["_"]) && $arg["_"][0] !== "-" && $arg["_"][0][0] === "-")) {
    fwrite(STDOUT, "Usage: php batch/s3upload.php [-p PID] [-m MIMETYPE] [-d DTYPE] FILE\n");
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

$docclass = new HotCRPDocument((int) $arg["d"]);
$docclass->set_no_database_storage();
$docinfo = (object) array("paperId" => (int) $arg["p"]);
$doc = (object) array("content" => $content, "documentType" => (int) $arg["d"]);
if (@$arg["f"])
    $doc->filename = $arg["f"];
if (@$arg["m"])
    $doc->mimetype = $arg["m"];
else if (($m = Mimetype::sniff($doc->content)))
    $doc->mimetype = $m;
else
    $doc->mimetype = "application/octet-stream";
DocumentHelper::store($docclass, $doc, $docinfo);
