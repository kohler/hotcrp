<?php
// cacheable.php -- HotCRP cacheable helper
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

header("Cache-Control: public, max-age=31557600");
header("Expires: " . date("r", time() + 31557600));
header("Pragma: "); // don't know where the pragma is coming from; oh well

$file = isset($_REQUEST["file"]) ? $_REQUEST["file"] : "";

if ($file == "script.js")
    header("Content-type: text/javascript; charset: UTF-8");
else if ($file == "style.css")
    header("Content-type: text/css; charset: UTF-8");
else {
    header("Content-type: text/plain");
    header("Content-Length: 10");
    echo "Go away.\r\n";
    exit;
}

header("Content-Length: " . filesize($file));
readfile($file);
