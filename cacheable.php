<?php
// cacheable.php -- HotCRP cacheability helper
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

session_cache_limiter("");
header("Cache-Control: public, max-age=315576000");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");

// *** NB This file does not include all of the HotCRP infrastructure! ***
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}
global $Opt;
$Opt = array();
if ((@include "conf/options.php") === false
    && (@include "conf/options.inc") === false
    && (@include "Code/options.inc") === false)
    error_log("cannot load conf/options.php");

function css_ok($file) {
    global $Opt;
    return $file == "style.css"
        || (@$Opt["stylesheets"]
            && array_search($file, $Opt["stylesheets"]) !== false);
}

$file = isset($_REQUEST["file"]) ? $_REQUEST["file"] : "";
if (strpos($file, "/") !== false)
    $file = "";
if ($file)
    $mtime = @filemtime($file);

$prefix = "";
if ($file == "script.js") {
    header("Content-Type: text/javascript; charset=utf-8");
    if (@$Opt["strictJavascript"])
        $prefix = "\"use strict\";\n";
} else if ($file == "jquery-1.10.2.min.js" || $file == "jquery-1.10.2.js")
    header("Content-Type: text/javascript; charset=utf-8");
else if ($file == "jquery-1.10.2.min.map")
    header("Content-Type: application/json; charset=utf-8");
else if (substr($file, -4) === ".css" && css_ok($file))
    header("Content-Type: text/css; charset=utf-8");
else if ($file == "supersleight-min.js" || $file == "supersleight.js")
    header("Content-Type: text/javascript; charset=utf-8");
else {
    header("Content-Type: text/plain; charset=utf-8");
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
    ob_start("ob_gzhandler");
    echo $prefix;
    readfile($file);
    ob_end_flush();
} else {
    if (!$zlib_output_compression)
	header("Content-Length: " . (filesize($file) + strlen($prefix)));
    echo $prefix;
    readfile($file);
}
