<?php
// cacheable.php -- HotCRP cacheability helper
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

header("Cache-Control: public, max-age=315576000");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
header("Pragma: "); // don't know where the pragma is coming from; oh well

$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

$file = isset($_REQUEST["file"]) ? $_REQUEST["file"] : "";
if (strpos($file, "/") !== false)
    $file = "";
if ($file)
    $mtime = @filemtime($file);

if ($file == "script.js" || $file == "supersleight-min.js"
    || $file == "supersleight.js")
    header("Content-type: text/javascript; charset: UTF-8");
else if (substr($file, -4) === ".css" && $mtime !== false)
    header("Content-type: text/css; charset: UTF-8");
else {
    header("Content-type: text/plain");
    if (!$zlib_output_compression)
	header("Content-Length: 10");
    echo "Go away.\r\n";
    exit;
}

$last_modified = gmdate("D, d M Y H:i:s", $mtime) . " GMT";
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
else if (function_exists("ob_gzhandler") && !$zlib_output_compression) {
    ob_start('ob_gzhandler');
    readfile($file);
    ob_end_flush();
} else {
    if (!$zlib_output_compression)
	header("Content-Length: " . filesize($file));
    readfile($file);
}
