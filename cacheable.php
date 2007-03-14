<?php
// cacheable.php -- HotCRP cacheable helper
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

header("Cache-Control: public, must-revalidate");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31557600) . " GMT");
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

header("Last-Modified: " . gmdate("D, d M Y H:i:s", filemtime($file)) . " GMT");
header("Content-Length: " . filesize($file));
readfile($file);
