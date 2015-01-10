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

function fail() {
    global $zlib_output_compression;
    header("Content-Type: text/plain; charset=utf-8");
    if (!$zlib_output_compression)
        header("Content-Length: 10");
    echo "Go away.\r\n";
    exit;
}

$file = @$_REQUEST["file"];
if (!$file)
    fail();

$mtime = @filemtime($file);
$prefix = "";
if (preg_match(',\A(?:images|scripts|stylesheets)(?:/[^./][^/]+)+\z,', $file)
    && preg_match(',.*([.][a-z]*)\z,', $file, $m)) {
    $s = $m[1];
    if ($s == ".js") {
        header("Content-Type: text/javascript; charset=utf-8");
        if (@$_REQUEST["strictjs"])
            $prefix = "\"use strict\";\n";
    } else if ($s == ".map")
        header("Content-Type: application/json; charset=utf-8");
    else if ($s == ".css")
        header("Content-Type: text/css; charset=utf-8");
    else if ($s == ".gif")
        header("Content-Type: image/gif");
    else if ($s == ".jpg")
        header("Content-Type: image/jpeg");
    else if ($s == ".png")
        header("Content-Type: image/png");
    else
        fail();
    header("Access-Control-Allow-Origin: *");
} else
    fail();

$last_modified = gmdate("D, d M Y H:i:s", $mtime) . " GMT";
$etag = '"' . md5($last_modified) . '"';
header("Last-Modified: $last_modified");
header("ETag: $etag");

// check for a conditional request
$if_modified_since = @$_SERVER["HTTP_IF_MODIFIED_SINCE"];
$if_none_match = @$_SERVER["HTTP_IF_NONE_MATCH"];
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
