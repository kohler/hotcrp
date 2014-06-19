<?php
// navigation.php -- HotCRP navigation helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Navigation {

    private static $protocol;           // "http://" or "https://"
    private static $server;             // "PROTOCOL://SITE[:PORT]"
    private static $sitedir;            // "/PATH", does not include $page, ends in /
    private static $page;               // Name of page
    private static $path;
    private static $query;
    private static $sitedir_relative;
    private static $php_suffix;

    public static function analyze() {
        if (PHP_SAPI == "cli")
            return;

        if (@$_SERVER["HTTPS"] && $_SERVER["HTTPS"] != "off")
            list($x, $xport) = array("https://", 443);
        else
            list($x, $xport) = array("http://", 80);
        self::$protocol = $x;
        if (!@$_SERVER["HTTP_HOST"])
            $x .= "localhost";
        else
            $x .= $_SERVER["HTTP_HOST"];
        if (($port = @$_SERVER["SERVER_PORT"])
            && $port != $xport
            && strpos($x, ":", 6) === false)
            $x .= ":" . $port;
        self::$server = $x;

        $script_name = $_SERVER["SCRIPT_FILENAME"];
        $is_index = strlen($script_name) > 9
            && substr($script_name, strlen($script_name) - 9) === "index.php";

        self::$path = "";
        if (@$_SERVER["PATH_INFO"])
            self::$path = $_SERVER["PATH_INFO"];

        preg_match(',\A([^\?\#]*)(.*)\z,', $_SERVER["REQUEST_URI"], $m);
        self::$query = $m[2];

        // beware: PATH_INFO is URL-decoded, REQUEST_URI is not.
        // be careful; make sure $site and $page are not URL-decoded.
        $sitepage = urldecode($m[1]);
        $sitepage = substr($sitepage, 0, strlen($sitepage) - strlen(self::$path));
        if (substr($m[1], 0, strlen($sitepage)) !== $sitepage) {
            $sitepage = $m[1];
            for ($nslashes = substr_count(self::$path, "/"); $nslashes > 0; --$nslashes)
                $sitepage = substr($sitepage, 0, strrpos($sitepage, "/"));
        }

        if ($is_index) {
            if (preg_match(',\A(.*/)index(?:[.]php)?\z,i', $sitepage, $m))
                $sitepage = $m[1];
            $site = $sitepage;
            if (preg_match(',\A/([^/]+?)(?:[.]php)?(|/.*)\z,', self::$path, $m)) {
                $site .= "/";
                self::$page = $m[1];
                self::$path = $m[2];
            } else {
                $site .= self::$path;
                self::$page = "index";
                self::$path = "";
            }
        } else {
            preg_match(',\A(.*/)([^/]*?)(?:[.]php)?/?\z,i', $sitepage, $m);
            $site = $m[1];
            self::$page = $m[2];
        }
        self::$sitedir = $site;
        if (self::$sitedir === ""
            || self::$sitedir[strlen(self::$sitedir) - 1] !== "/")
            self::$sitedir .= "/";

        self::$sitedir_relative = str_repeat("../", substr_count(self::$path, "/"));
        if (self::$sitedir_relative === ""
            && self::$sitedir !== $site)
            self::$sitedir_relative = self::$sitedir;

        self::$php_suffix = ".php";
        if (substr(@$_SERVER["SERVER_SOFTWARE"], 0, 5) === "nginx"
            || (function_exists("apache_get_modules")
                && array_search("mod_rewrite", apache_get_modules()) !== false))
            self::$php_suffix = "";
    }

    public static function site_absolute($downcase_host = false) {
        $x = $downcase_host ? strtolower(self::$server) : self::$server;
        return $x . self::$sitedir;
    }

    public static function site_path() {
        return self::$sitedir;
    }

    public static function site_relative() {
        return self::$sitedir_relative;
    }

    public static function page() {
        return self::$page;
    }

    public static function path() {
        return self::$path;
    }

    public static function php_suffix() {
        return self::$php_suffix;
    }

    public static function make_absolute($url) {
        if ($url === false)
            return self::$server . self::$sitedir;
        preg_match(',\A((?:https?://[^/]+)?)(/*)((?:[.][.]/)*)(.*)\z,i', $url, $m);
        if ($m[1] !== "")
            return $url;
        else if (strlen($m[2]) > 1)
            return self::$protocol . substr($url, 2);
        else if ($m[2] === "/")
            return self::$server . $url;
        else {
            $site = substr($_SERVER["REQUEST_URI"], 0, strlen($_SERVER["REQUEST_URI"]) - strlen(self::$query));
            $site = preg_replace(',/[^/]+\z,', "/", $site);
            for (; $m[3]; $m[3] = substr($m[3], 3))
                $site = preg_replace(',/[^/]+/\z,', "/", $site);
            return self::$server . $site . $m[3] . $m[4];
        }
    }

    public static function redirect_to($url) {
        $url = self::make_absolute($url);
        // Might have an HTML-encoded URL; decode at least &amp;.
        $url = str_replace("&amp;", "&", $url);

        if (preg_match('|\A[a-z]+://|', $url))
            header("Location: $url");

        echo "<!DOCTYPE html><html lang=\"en\"><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
<meta http-equiv=\"Content-Script-Type\" content=\"text/javascript\" />
<title>Redirection</title>
<script>location=\"$url\";</script></head>
<body>
<p>You should be redirected <a href='", htmlspecialchars($url), "'>to here</a>.</p>
</body></html>\n";
        exit();
    }

    public static function redirect_http_to_https($allow_http_if_localhost = false) {
        if ((!@$_SERVER["HTTPS"] || $_SERVER["HTTPS"] == "off")
            && self::$protocol == "http://"
            && (!$allow_http_if_localhost
                || ($_SERVER["REMOTE_ADDR"] !== "127.0.0.1"
                    && $_SERVER["REMOTE_ADDR"] !== "::1"))) {
            $x = "https://";
            if (!@$_SERVER["HTTP_HOST"])
                $x .= "localhost";
            else
                $x .= $_SERVER["HTTP_HOST"];
            $x .= self::$sitedir . self::$page . self::$php_suffix . self::$path . self::$query;
            self::redirect_to($x);
        }
    }

}
