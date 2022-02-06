<?php
// pages/p_cacheable.php -- HotCRP cacheability helper
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Cacheable_Page {
    static function cacheable_headers() {
        header("Cache-Control: max-age=315576000, public");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 315576000) . " GMT");
    }

    /** @param string $status
     * @param string $text
     * @param bool $cacheable */
    static function fail($status, $text, $cacheable) {
        header("HTTP/1.0 $status");
        header("Content-Type: text/plain; charset=utf-8");
        if (!function_exists("zlib_get_coding_type")
            || zlib_get_coding_type() === false) {
            header("Content-Length: " . (strlen($text) + 2));
        }
        if ($cacheable) {
            self::cacheable_headers();
        }
        echo $text, "\r\n";
    }

    /** @param NavigationState $nav */
    static function go_nav($nav) {
        session_cache_limiter("");

        // read file
        $file = $_GET["file"] ?? null;
        if ($file === null && $nav->path !== "" && $nav->path[0] === "/") {
            $file = substr($nav->path, 1);
        }
        if ($file === null || $file === "") {
            self::fail("400 Bad Request", "File missing", true);
            return;
        }

        // analyze file
        $prefix = "";
        if (preg_match('/\A(?:images|scripts|stylesheets)(?:\/[^.\/][^\/]+)+\z/', $file)
            && ($dot = strrpos($file, ".")) !== false
            && ctype_alnum(($ext = substr($file, $dot + 1)))) {
            if ($ext === "js") {
                header("Content-Type: text/javascript; charset=utf-8");
                if (isset($_GET["strictjs"]) && $_GET["strictjs"]) {
                    $prefix = "\"use strict\";\n";
                }
            } else if ($ext === "map" || $ext === "json") {
                header("Content-Type: application/json; charset=utf-8");
            } else if ($ext === "css") {
                header("Content-Type: text/css; charset=utf-8");
            } else if ($ext === "gif") {
                header("Content-Type: image/gif");
            } else if ($ext === "jpg") {
                header("Content-Type: image/jpeg");
            } else if ($ext === "png") {
                header("Content-Type: image/png");
            } else if ($ext === "svg") {
                header("Content-Type: image/svg+xml");
            } else if ($ext === "mp3") {
                header("Content-Type: audio/mpeg");
            } else if ($ext === "woff") {
                header("Content-Type: application/font-woff");
            } else if ($ext === "woff2") {
                header("Content-Type: application/font-woff2");
            } else if ($ext === "ttf") {
                header("Content-Type: application/x-font-ttf");
            } else if ($ext === "otf") {
                header("Content-Type: font/opentype");
            } else if ($ext === "eot") {
                header("Content-Type: application/vnd.ms-fontobject");
            } else {
                self::fail("403 Forbidden", "File cannot be served", true);
                return;
            }
            header("Access-Control-Allow-Origin: *");
        } else {
            self::fail("403 Forbidden", "File cannot be served", true);
            return;
        }

        $mtime = @filemtime($file);
        if ($mtime === false) {
            self::fail("404 Not Found", "File not found", false);
            return;
        }

        $last_modified = gmdate("D, d M Y H:i:s", $mtime) . " GMT";
        $etag = '"' . md5("$file $last_modified") . '"';
        header("Last-Modified: $last_modified");
        header("ETag: $etag");
        self::cacheable_headers();

        if (function_exists("zlib_get_coding_type")) {
            $zlib_type = zlib_get_coding_type();
        } else {
            $zlib_type = false;
        }

        // check for a conditional request
        $if_modified_since = $_SERVER["HTTP_IF_MODIFIED_SINCE"] ?? null;
        $if_none_match = $_SERVER["HTTP_IF_NONE_MATCH"] ?? null;
        if (($if_modified_since || $if_none_match)
            && (!$if_modified_since || $if_modified_since === $last_modified)
            && (!$if_none_match || $if_none_match === $etag)) {
            header("HTTP/1.0 304 Not Modified");
        } else if (function_exists("ob_gzhandler") && $zlib_type === false) {
            ob_start("ob_gzhandler");
            echo $prefix;
            readfile($file);
            ob_end_flush();
        } else {
            if ($zlib_type === false) {
                header("Content-Length: " . (filesize($file) + strlen($prefix)));
            }
            echo $prefix;
            readfile($file);
        }
    }
}
