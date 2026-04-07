<?php
// pages/p_cacheable.php -- HotCRP cacheability helper
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Cacheable_Page {
    static function cacheable_headers() {
        Navigation::header("Cache-Control: max-age=315576000, public");
        Navigation::header("Expires: " . Navigation::http_date(time() + 315576000));
    }

    static function skip_content_length_header() {
        // see also Downloader::skip_content_length_header
        return function_exists("zlib_get_coding_type") && zlib_get_coding_type() !== false;
    }

    /** @param int $status
     * @param string $text
     * @param bool $cacheable */
    static private function fail($status, $text, $cacheable) {
        Navigation::http_response_code($status);
        Navigation::header("Content-Type: text/plain; charset=utf-8");
        if (!self::skip_content_length_header()) {
            Navigation::header("Content-Length: " . (strlen($text) + 2));
        }
        if ($cacheable) {
            self::cacheable_headers();
        }
        echo $text, "\r\n";
    }

    /** @param NavigationState $nav */
    static function go_nav($nav) {
        session_cache_limiter("");
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            Navigation::header("Access-Control-Allow-Origin: *");
        }

        // read file
        $file = $_GET["file"] ?? null;
        if ($file === null && $nav->path !== "" && $nav->path[0] === "/") {
            $file = substr($nav->path, 1);
        }
        if ($file === null || $file === "") {
            self::fail(400 /* Bad Request */, "File missing", true);
            return;
        }

        // analyze file
        $prefix = "";
        if (preg_match('/\A(?:images|scripts|stylesheets)(?:\/[^.\/][^\/]+)+\z/', $file)
            && ($dot = strrpos($file, ".")) !== false
            && ctype_alnum(($ext = substr($file, $dot + 1)))) {
            if ($ext === "js") {
                Navigation::header("Content-Type: text/javascript; charset=utf-8");
                if (isset($_GET["strictjs"]) && $_GET["strictjs"]) {
                    $prefix = "\"use strict\";\n";
                }
            } else if ($ext === "map" || $ext === "json") {
                Navigation::header("Content-Type: application/json; charset=utf-8");
            } else if ($ext === "css") {
                Navigation::header("Content-Type: text/css; charset=utf-8");
            } else if ($ext === "gif") {
                Navigation::header("Content-Type: image/gif");
            } else if ($ext === "jpg") {
                Navigation::header("Content-Type: image/jpeg");
            } else if ($ext === "png") {
                Navigation::header("Content-Type: image/png");
            } else if ($ext === "svg") {
                Navigation::header("Content-Type: image/svg+xml");
            } else if ($ext === "mp3") {
                Navigation::header("Content-Type: audio/mpeg");
            } else if ($ext === "woff") {
                Navigation::header("Content-Type: application/font-woff");
            } else if ($ext === "woff2") {
                Navigation::header("Content-Type: application/font-woff2");
            } else if ($ext === "ttf") {
                Navigation::header("Content-Type: application/x-font-ttf");
            } else if ($ext === "otf") {
                Navigation::header("Content-Type: font/opentype");
            } else if ($ext === "eot") {
                Navigation::header("Content-Type: application/vnd.ms-fontobject");
            } else {
                self::fail(403 /* Forbidden */, "File cannot be served", true);
                return;
            }
            Navigation::header("Access-Control-Allow-Origin: *");
        } else {
            self::fail(403 /* Forbidden */, "File cannot be served", true);
            return;
        }

        $mtime = @filemtime($file);
        if ($mtime === false) {
            self::fail(404 /* Not Found */, "File not found", false);
            return;
        }

        $last_modified = Navigation::http_date($mtime);
        $etag = '"' . md5("{$file} {$last_modified}") . '"';
        Navigation::header("Last-Modified: {$last_modified}");
        Navigation::header("ETag: {$etag}");
        $skip_length = self::skip_content_length_header();

        // check for a conditional request
        $if_modified_since = $_SERVER["HTTP_IF_MODIFIED_SINCE"] ?? null;
        $if_none_match = $_SERVER["HTTP_IF_NONE_MATCH"] ?? null;
        if (($if_modified_since || $if_none_match)
            && (!$if_modified_since || $if_modified_since === $last_modified)
            && (!$if_none_match || $if_none_match === $etag)) {
            Navigation::http_response_code(304 /* Not Modified */);
        } else if (function_exists("ob_gzhandler") && !$skip_length) {
            ob_start("ob_gzhandler");
            echo $prefix;
            readfile($file);
            ob_end_flush();
        } else {
            if (!$skip_length) {
                Navigation::header("Content-Length: " . (filesize($file) + strlen($prefix)));
            }
            echo $prefix;
            readfile($file);
        }
    }
}
