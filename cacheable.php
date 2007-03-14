<?php
// cacheable.php -- HotCRP cacheability helper
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

header("Cache-Control: public, max-age=31557600");
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

$last_modified = gmdate("D, d M Y H:i:s", filemtime($file)) . " GMT";
$etag = '"' . md5($last_modified) . '"';
header("Last-Modified: $last_modified");
header("ETag: $etag");

// check for a conditional request
$if_modified_since = isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) ? $_SERVER["HTTP_IF_MODIFIED_SINCE"] : false;
$if_none_match = isset($_SERVER["HTTP_IF_NONE_MATCH"]) ? $_SERVER["HTTP_IF_NONE_MATCH"] : false;
if (($if_modified_since || $if_none_match)
    && (!$if_modified_since || $if_modified_since == $last_modified)
    && (!$if_none_match || $if_none_match == $etag))
    header("HTTP/1.0 304 Not Modified");
else {
    header("Content-Length: " . filesize($file));
    readfile($file);
}
