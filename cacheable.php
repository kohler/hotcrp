<?php
// cacheable.php -- HotCRP cacheability helper
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

session_cache_limiter("");
header("Cache-Control: max-age=315576000, public");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");

// *** NB This file does not include all of the HotCRP infrastructure! ***
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

function fail($reason, $file) {
    global $zlib_output_compression;
    header("HTTP/1.0 $reason");
    header("Content-Type: text/plain; charset=utf-8");
    $result = "$file\r\n";
    if (!$zlib_output_compression)
        header("Content-Length: " . strlen($result));
    echo $result;
    exit;
}

$file = isset($_GET["file"]) ? $_GET["file"] : null;
if (!$file) {
    fail("400 Bad Request", "File missing");
}

$prefix = "";
if (preg_match(',\A(?:images|scripts|stylesheets)(?:/[^./][^/]+)+\z,', $file)
    && preg_match(',.*(\.[a-z0-9]*)\z,', $file, $m)) {
    $s = $m[1];
    if ($s === ".js") {
        header("Content-Type: text/javascript; charset=utf-8");
        if (isset($_GET["strictjs"]) && $_GET["strictjs"])
            $prefix = "\"use strict\";\n";
    } else if ($s === ".map")
        header("Content-Type: application/json; charset=utf-8");
    else if ($s === ".css")
        header("Content-Type: text/css; charset=utf-8");
    else if ($s === ".gif")
        header("Content-Type: image/gif");
    else if ($s === ".jpg")
        header("Content-Type: image/jpeg");
    else if ($s === ".png")
        header("Content-Type: image/png");
    else if ($s === ".svg")
        header("Content-Type: image/svg+xml");
    else if ($s === ".mp3")
        header("Content-Type: audio/mpeg");
    else if ($s === ".woff")
        header("Content-Type: application/font-woff");
    else if ($s === ".woff2")
        header("Content-Type: application/font-woff2");
    else if ($s === ".ttf")
        header("Content-Type: application/x-font-ttf");
    else if ($s === ".otf")
        header("Content-Type: font/opentype");
    else if ($s === ".eot")
        header("Content-Type: application/vnd.ms-fontobject");
    else
        fail("403 Forbidden", "File cannot be served");
    header("Access-Control-Allow-Origin: *");
} else
    fail("403 Forbidden", "File cannot be served");

$mtime = @filemtime($file);
if ($mtime === false)
    fail("404 Not Found", "File not found");
$last_modified = gmdate("D, d M Y H:i:s", $mtime) . " GMT";
$etag = '"' . md5("$file $last_modified") . '"';
header("Last-Modified: $last_modified");
header("ETag: $etag");

// check for a conditional request
$if_modified_since = isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) ? $_SERVER["HTTP_IF_MODIFIED_SINCE"] : 0;
$if_none_match = isset($_SERVER["HTTP_IF_NONE_MATCH"]) ? $_SERVER["HTTP_IF_NONE_MATCH"] : 0;
if (($if_modified_since || $if_none_match)
    && (!$if_modified_since || $if_modified_since === $last_modified)
    && (!$if_none_match || $if_none_match === $etag))
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
