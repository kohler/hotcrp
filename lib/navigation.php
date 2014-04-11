<?php
// navigation.php -- HotCRP navigation helper functions
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Navigation {

    private static $protocol;
    private static $server;
    private static $site;
    private static $sitedir;
    private static $page;
    private static $path;
    private static $query;
    private static $sitedir_relative;
    private static $php_suffix;

    public static function analyze() {
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

        $sitepage = substr($m[1], 0, strlen($m[1]) - strlen(self::$path));
        if ($is_index) {
            if (preg_match(',\A(.*/)index(?:[.]php)?\z,i', $sitepage, $m))
                $sitepage = $m[1];
            self::$site = $sitepage;
            if (preg_match(',\A/([^/]+)(.*)\z,', self::$path, $m)) {
                self::$site .= "/";
                self::$page = $m[1];
                self::$path = $m[2];
            } else {
                self::$site .= self::$path;
                self::$page = "index";
                self::$path = "";
            }
        } else {
            preg_match(',\A(.*/)([^/]*?)(?:[.]php)?/?\z,i', $sitepage, $m);
            self::$site = $m[1];
            self::$page = $m[2];
        }
        self::$sitedir = self::$site;
        if (self::$sitedir === ""
            || self::$sitedir[strlen(self::$sitedir) - 1] !== "/")
            self::$sitedir .= "/";

        self::$sitedir_relative = str_repeat("../", substr_count(self::$path, "/"));
        if (self::$sitedir_relative === ""
            && self::$sitedir !== self::$site)
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

}
